<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function publicationDetailEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function publicationDetailStatusBadge(string $status): array
{
    return match ($status) {
        'submitted' => ['label' => 'Submitted', 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'published' => ['label' => 'Published', 'tone' => 'success'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

function publicationDetailDoiUrl(?string $identifier): ?string
{
    $value = trim((string) $identifier);
    if ($value === '') return null;
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    $doi = preg_replace('#^(?:doi:\s*|https?://(?:dx\.)?doi\.org/)#i', '', $value);
    if (!is_string($doi) || !preg_match('#^10\.\d{4,9}/\S+$#D', $doi)) return null;
    return 'https://doi.org/' . str_replace('%2F', '/', rawurlencode($doi));
}

function publicationDetailSafeFileUrl(?string $relativePath): ?string
{
    $normalized = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
    if (!preg_match('#^uploads/publications/[A-Za-z0-9._-]+\.pdf$#Di', $normalized)) return null;

    $uploadRoot = realpath(__DIR__ . '/../../uploads');
    $resolved = realpath(__DIR__ . '/../../' . $normalized);
    if (!$uploadRoot || !$resolved || !is_file($resolved)) return null;
    $prefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($resolved, $prefix)) return null;

    return SITE_URL . implode('/', array_map('rawurlencode', explode('/', $normalized)));
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$canReview = in_array($role, ['research_staff', 'admin'], true);
$ownerRole = in_array($role, ['faculty', 'student'], true);
$typeOptions = ['journal' => 'Journal', 'conference' => 'Conference', 'book_chapter' => 'Book chapter', 'other' => 'Other'];

$tableCheck = $conn->query("SHOW TABLES LIKE 'publications'");
$publicationsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) $tableCheck->free();

$publicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$publication = null;
$loadPublication = static function (mysqli $connection, int $id): ?array {
    $stmt = $connection->prepare('SELECT publication_id, research_title, researcher_id, researcher_name, researcher_category, publication_type, journal_publisher, publication_date, doi_identifier, file_path, file_name, status, created_at, updated_at FROM publications WHERE publication_id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};
if ($publicationsAvailable && $publicationId) $publication = $loadPublication($conn, $publicationId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['publications_flash'] = ['type' => 'error', 'message' => 'Your request expired. Please try again.'];
        header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php' . ($publicationId ? '?id=' . $publicationId : ''));
        exit;
    }
    if (!$publicationsAvailable) {
        $_SESSION['publications_flash'] = ['type' => 'error', 'message' => 'The publications module is not installed.'];
        header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php');
        exit;
    }

    $postedId = filter_var($_POST['publication_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $action = trim((string) ($_POST['action'] ?? ''));
    $transitions = [
        'start_review' => ['from' => 'submitted', 'to' => 'under_review', 'message' => 'Publication moved to review.'],
        'return_submitted' => ['from' => 'under_review', 'to' => 'submitted', 'message' => 'Publication returned to submitted.'],
        'accept' => ['from' => 'under_review', 'to' => 'accepted', 'message' => 'Publication accepted.'],
        'publish_review' => ['from' => 'under_review', 'to' => 'published', 'message' => 'Publication marked published.'],
        'publish_accepted' => ['from' => 'accepted', 'to' => 'published', 'message' => 'Publication marked published.'],
    ];
    if (!$postedId || !isset($transitions[$action])) {
        $_SESSION['publications_flash'] = ['type' => 'error', 'message' => 'Invalid publication action.'];
        header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php' . ($postedId ? '?id=' . $postedId : ''));
        exit;
    }

    $current = $loadPublication($conn, $postedId);
    $transition = $transitions[$action];
    if (!$current) {
        $_SESSION['publications_flash'] = ['type' => 'error', 'message' => 'Publication not found.'];
    } else {
        $stmt = $conn->prepare('UPDATE publications SET status = ? WHERE publication_id = ? AND status = ?');
        $stmt->bind_param('sis', $transition['to'], $postedId, $transition['from']);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            logActivity("Changed publication #{$postedId} status from {$transition['from']} to {$transition['to']}", 'publications');
            if ($transition['to'] === 'published' && (int) ($current['researcher_id'] ?? 0) > 0) {
                createNotification(
                    (int) $current['researcher_id'],
                    'Publication published',
                    'Your publication "' . (string) $current['research_title'] . '" was marked published.',
                    'success',
                    SITE_URL . 'pages/shared/publication-detail.php?id=' . $postedId
                );
            }
            $_SESSION['publications_flash'] = ['type' => 'success', 'message' => $transition['message']];
        } else {
            $_SESSION['publications_flash'] = ['type' => 'error', 'message' => 'That transition is no longer available. Refresh and try again.'];
        }
    }
    header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php?id=' . $postedId);
    exit;
}

$flash = $_SESSION['publications_flash'] ?? null;
unset($_SESSION['publications_flash']);
if ($publicationsAvailable && $publicationId) $publication = $loadPublication($conn, $publicationId);

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
$pageSubtitle = $publication ? 'Publication record and review status' : 'Publication record';
if ($shell === 'admin') renderAdminShell($user, 'publications.php', 'Publication Details', $pageSubtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'publications.php', 'Publication Details', $pageSubtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'publications.php', 'Publication Details', $pageSubtitle);
else renderStudentShell($user, 'publications.php', 'Publication Details', $pageSubtitle);

$badge = $publication ? publicationDetailStatusBadge((string) $publication['status']) : publicationDetailStatusBadge('');
$doiUrl = $publication ? publicationDetailDoiUrl($publication['doi_identifier']) : null;
$fileUrl = $publication ? publicationDetailSafeFileUrl($publication['file_path']) : null;
$canEdit = $publication && ($canReview || ($ownerRole && (int) $publication['researcher_id'] === $userId && $publication['status'] === 'submitted'));
?>

<style>
.publication-detail{max-width:1120px;margin:0 auto;color:#172033}.detail-topline{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:18px}.back-link,.text-link{color:#315b9f;font-size:12px;font-weight:800;text-decoration:none}.back-link:focus-visible,.text-link:focus-visible,.action-button:focus-visible{outline:3px solid rgba(49,91,159,.15);outline-offset:3px}.flash{margin-bottom:18px;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.detail-card{overflow:hidden;border:1px solid #dfe5ee;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(42,67,108,.07)}.detail-hero{padding:30px;border-left:5px solid #315b9f;background:#eef3fb}.hero-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}.detail-hero h1{max-width:850px;margin:0;font-size:clamp(25px,3vw,38px);line-height:1.13;letter-spacing:-.035em}.detail-hero p{margin:11px 0 0;color:#526176;font-size:14px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800}.type-badge{background:#dbeafe;color:#1e40af}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-neutral{background:#e2e8f0;color:#475569}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0}.detail-field{min-height:92px;padding:20px 24px;border-top:1px solid #edf1f5}.detail-field:nth-child(odd){border-right:1px solid #edf1f5}.detail-field.full{grid-column:1/-1;border-right:0}.detail-field span{display:block;margin-bottom:7px;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.detail-field strong,.detail-field p{margin:0;color:#172033;font-size:13px;line-height:1.55}.file-note{margin-top:7px!important;color:#64748b!important;font-size:11px!important}.action-panel{margin-top:20px;padding:20px;border:1px solid #dfe5ee;border-radius:15px;background:#fff}.action-panel h2{margin:0;font-size:16px}.action-panel>p{margin:6px 0 16px;color:#64748b;font-size:12px}.actions{display:flex;gap:9px;flex-wrap:wrap}.actions form{margin:0}.action-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;box-sizing:border-box;border:1px solid #315b9f;border-radius:9px;padding:8px 14px;background:#315b9f;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer}.action-button:hover{background:#24477e;color:#fff}.action-button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.action-button.success{border-color:#15803d;background:#15803d}.empty-state{padding:70px 24px;text-align:center;border:1px solid #dfe5ee;border-radius:18px;background:#fff}.empty-mark{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 15px;border-radius:14px;background:#e8eef9;color:#315b9f;font-size:22px}.empty-state h1{margin:0;font-size:22px}.empty-state p{max-width:520px;margin:8px auto 20px;color:#64748b;font-size:13px;line-height:1.55}@media(max-width:650px){.detail-topline{align-items:flex-start}.detail-grid{grid-template-columns:1fr}.detail-field,.detail-field:nth-child(odd){border-right:0}.detail-hero{padding:24px 20px}.actions,.actions form,.action-button{width:100%}}
</style>

<div class="publication-detail">
  <div class="detail-topline"><a class="back-link" href="<?= publicationDetailEscape(SITE_URL . 'pages/shared/publications.php') ?>">&larr; Back to Publications</a><?php if ($canEdit): ?><a class="action-button secondary" href="<?= publicationDetailEscape(SITE_URL . 'pages/shared/publication-form.php?id=' . (int) $publication['publication_id']) ?>">Edit publication</a><?php endif; ?></div>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= publicationDetailEscape($flash['message'] ?? '') ?></div><?php endif; ?>

  <?php if (!$publication): ?>
    <section class="empty-state"><div class="empty-mark" aria-hidden="true">?</div><h1>Publication not found</h1><p><?= publicationDetailEscape($publicationsAvailable ? 'The publication may have been removed, or the link is incomplete.' : 'The publications module is not installed. Apply database migration 012 to enable it.') ?></p><a class="action-button" href="<?= publicationDetailEscape(SITE_URL . 'pages/shared/publications.php') ?>">Return to Publications</a></section>
  <?php else: ?>
    <article class="detail-card">
      <header class="detail-hero"><div class="hero-meta"><span class="badge type-badge"><?= publicationDetailEscape($typeOptions[$publication['publication_type']] ?? ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?></span><span class="badge tone-<?= publicationDetailEscape($badge['tone']) ?>"><?= publicationDetailEscape($badge['label']) ?></span></div><h1><?= publicationDetailEscape($publication['research_title']) ?></h1><p><?= publicationDetailEscape($publication['researcher_name'] ?: 'Unnamed researcher') ?> &middot; <?= publicationDetailEscape(ucfirst((string) $publication['researcher_category'])) ?></p></header>
      <div class="detail-grid">
        <div class="detail-field"><span>Journal / publisher</span><strong><?= publicationDetailEscape($publication['journal_publisher'] ?: 'Not recorded') ?></strong></div>
        <div class="detail-field"><span>Publication date</span><strong><?= publicationDetailEscape($publication['publication_date'] ? date('F j, Y', strtotime((string) $publication['publication_date'])) : 'Not recorded') ?></strong></div>
        <div class="detail-field"><span>DOI / identifier</span><?php if ($publication['doi_identifier']): ?><p><?= publicationDetailEscape($publication['doi_identifier']) ?></p><?php if ($doiUrl): ?><a class="text-link" href="<?= publicationDetailEscape($doiUrl) ?>" target="_blank" rel="noopener noreferrer">Open DOI / identifier</a><?php endif; ?><?php else: ?><strong>Not recorded</strong><?php endif; ?></div>
        <div class="detail-field"><span>Research file</span><?php if ($fileUrl): ?><a class="text-link" href="<?= publicationDetailEscape($fileUrl) ?>" download="<?= publicationDetailEscape($publication['file_name'] ?: 'publication.pdf') ?>">Download <?= publicationDetailEscape($publication['file_name'] ?: 'publication.pdf') ?></a><p class="file-note">The stored file was verified inside the publication upload directory.</p><?php elseif ($publication['file_path']): ?><strong>File unavailable</strong><p class="file-note">The stored file could not be safely resolved.</p><?php else: ?><strong>No file attached</strong><?php endif; ?></div>
        <div class="detail-field"><span>Submitted</span><strong><?= publicationDetailEscape(date('F j, Y, g:i a', strtotime((string) $publication['created_at']))) ?></strong></div>
        <div class="detail-field"><span>Last updated</span><strong><?= publicationDetailEscape(date('F j, Y, g:i a', strtotime((string) $publication['updated_at']))) ?></strong></div>
      </div>
    </article>

    <?php if ($canReview): ?>
      <section class="action-panel" aria-labelledby="review-actions-heading"><h2 id="review-actions-heading">Review actions</h2><p>Only valid next steps for the current status are available. Every transition is checked again when submitted.</p><div class="actions">
        <?php if ($publication['status'] === 'submitted'): ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="publication_id" value="<?= (int) $publication['publication_id'] ?>"><input type="hidden" name="action" value="start_review"><button class="action-button" type="submit">Start review</button></form>
        <?php elseif ($publication['status'] === 'under_review'): ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="publication_id" value="<?= (int) $publication['publication_id'] ?>"><input type="hidden" name="action" value="return_submitted"><button class="action-button secondary" type="submit">Return to submitted</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="publication_id" value="<?= (int) $publication['publication_id'] ?>"><input type="hidden" name="action" value="accept"><button class="action-button" type="submit">Accept</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="publication_id" value="<?= (int) $publication['publication_id'] ?>"><input type="hidden" name="action" value="publish_review"><button class="action-button success" type="submit">Mark published</button></form>
        <?php elseif ($publication['status'] === 'accepted'): ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="publication_id" value="<?= (int) $publication['publication_id'] ?>"><input type="hidden" name="action" value="publish_accepted"><button class="action-button success" type="submit">Mark published</button></form>
        <?php else: ?><span class="badge tone-success">Review complete</span><?php endif; ?>
      </div></section>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
