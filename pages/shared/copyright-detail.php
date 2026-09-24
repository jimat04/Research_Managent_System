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

function copyrightDetailEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function copyrightDetailStatusBadge(string $status): array
{
    return match ($status) {
        'pending' => ['label' => 'Pending', 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'registered' => ['label' => 'Registered', 'tone' => 'success'],
        'rejected' => ['label' => 'Rejected', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

function copyrightDetailSafeFileUrl(?string $relativePath): ?string
{
    $normalized = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
    if (!preg_match('#^uploads/copyrights/[A-Za-z0-9._-]+\.pdf$#Di', $normalized)) return null;

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
$typeOptions = ['software' => 'Software', 'research' => 'Research', 'instructional_material' => 'Instructional material', 'module' => 'Module', 'other' => 'Other'];

$tableCheck = $conn->query("SHOW TABLES LIKE 'copyright_applications'");
$copyrightsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) $tableCheck->free();

$copyrightId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$application = null;
$loadApplication = static function (mysqli $connection, int $id): ?array {
    $stmt = $connection->prepare('SELECT copyright_id, applicant_id, applicant_name, applicant_category, output_title, output_type, co_authors, date_completed, file_path, file_name, copyright_ref_no, status, created_at, updated_at FROM copyright_applications WHERE copyright_id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};
if ($copyrightsAvailable && $copyrightId) $application = $loadApplication($conn, $copyrightId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'Your request expired. Please try again.'];
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php' . ($copyrightId ? '?id=' . $copyrightId : ''));
        exit;
    }
    if (!$copyrightsAvailable) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'The copyrights module is not installed.'];
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php');
        exit;
    }

    $postedId = filter_var($_POST['copyright_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $action = trim((string) ($_POST['action'] ?? ''));
    $transitions = [
        'start_review' => ['from' => 'pending', 'to' => 'under_review', 'message' => 'Copyright application moved to review.'],
        'return_pending' => ['from' => 'under_review', 'to' => 'pending', 'message' => 'Copyright application returned to pending.'],
        'register' => ['from' => 'under_review', 'to' => 'registered', 'message' => 'Copyright application registered.'],
        'reject' => ['from' => 'under_review', 'to' => 'rejected', 'message' => 'Copyright application rejected.'],
    ];
    if (!$postedId || !isset($transitions[$action])) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'Invalid copyright application action.'];
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php' . ($postedId ? '?id=' . $postedId : ''));
        exit;
    }

    $referenceNo = trim((string) ($_POST['copyright_ref_no'] ?? ''));
    $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
    if ($action === 'register' && (strlen($referenceNo) < 3 || strlen($referenceNo) > 120)) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'Copyright reference number is required and must be 3 to 120 characters.'];
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php?id=' . $postedId);
        exit;
    }
    if ($action === 'reject' && strlen($rejectionReason) > 1000) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'Rejection reason must not exceed 1,000 characters.'];
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php?id=' . $postedId);
        exit;
    }

    $current = $loadApplication($conn, $postedId);
    $transition = $transitions[$action];
    if (!$current) {
        $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'Copyright application not found.'];
    } else {
        if ($action === 'register') {
            $stmt = $conn->prepare('UPDATE copyright_applications SET status = ?, copyright_ref_no = ? WHERE copyright_id = ? AND status = ?');
            $stmt->bind_param('ssis', $transition['to'], $referenceNo, $postedId, $transition['from']);
        } else {
            $stmt = $conn->prepare('UPDATE copyright_applications SET status = ? WHERE copyright_id = ? AND status = ?');
            $stmt->bind_param('sis', $transition['to'], $postedId, $transition['from']);
        }
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            logActivity("Changed copyright application #{$postedId} status from {$transition['from']} to {$transition['to']}", 'copyrights');
            $applicantId = (int) ($current['applicant_id'] ?? 0);
            if ($transition['to'] === 'registered' && $applicantId > 0) {
                createNotification(
                    $applicantId,
                    'Copyright registered',
                    'Your copyright application "' . (string) $current['output_title'] . '" was registered under ' . $referenceNo . '.',
                    'success',
                    SITE_URL . 'pages/shared/copyright-detail.php?id=' . $postedId
                );
            } elseif ($transition['to'] === 'rejected' && $applicantId > 0) {
                $notificationMessage = 'Your copyright application "' . (string) $current['output_title'] . '" was rejected.';
                if ($rejectionReason !== '') $notificationMessage .= ' Reason: ' . $rejectionReason;
                createNotification(
                    $applicantId,
                    'Copyright application rejected',
                    $notificationMessage,
                    'warning',
                    SITE_URL . 'pages/shared/copyright-detail.php?id=' . $postedId
                );
            }
            $_SESSION['copyrights_flash'] = ['type' => 'success', 'message' => $transition['message']];
        } else {
            $_SESSION['copyrights_flash'] = ['type' => 'error', 'message' => 'That transition is no longer available. Refresh and try again.'];
        }
    }
    header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php?id=' . $postedId);
    exit;
}

$flash = $_SESSION['copyrights_flash'] ?? null;
unset($_SESSION['copyrights_flash']);
if ($copyrightsAvailable && $copyrightId) $application = $loadApplication($conn, $copyrightId);

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
$pageSubtitle = $application ? 'Copyright application and processing status' : 'Copyright application record';
if ($shell === 'admin') renderAdminShell($user, 'copyrights.php', 'Copyright Details', $pageSubtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'copyrights.php', 'Copyright Details', $pageSubtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'copyrights.php', 'Copyright Details', $pageSubtitle);
else renderStudentShell($user, 'copyrights.php', 'Copyright Details', $pageSubtitle);

$badge = $application ? copyrightDetailStatusBadge((string) $application['status']) : copyrightDetailStatusBadge('');
$fileUrl = $application ? copyrightDetailSafeFileUrl($application['file_path']) : null;
$canEdit = $application && ($canReview || ($ownerRole && (int) $application['applicant_id'] === $userId && $application['status'] === 'pending'));
?>

<style>
.copyright-detail{max-width:1120px;margin:0 auto;color:#172033}.detail-topline{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:18px}.back-link,.text-link{color:#9a5b13;font-size:12px;font-weight:800;text-decoration:none}.back-link:focus-visible,.text-link:focus-visible,.action-button:focus-visible,.review-form input:focus,.review-form textarea:focus{outline:3px solid rgba(154,91,19,.15);outline-offset:3px}.flash{margin-bottom:18px;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.detail-card{overflow:hidden;border:1px solid #e9e1d5;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(88,62,30,.07)}.detail-hero{padding:30px;border-left:5px solid #9a5b13;background:#fbf4e8}.hero-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}.detail-hero h1{max-width:850px;margin:0;font-size:clamp(25px,3vw,38px);line-height:1.13;letter-spacing:-.035em}.detail-hero p{margin:11px 0 0;color:#526176;font-size:14px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800}.type-badge{background:#f8e9cc;color:#7c480e}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0}.detail-field{min-height:92px;padding:20px 24px;border-top:1px solid #f0ebe4}.detail-field:nth-child(odd){border-right:1px solid #f0ebe4}.detail-field.full{grid-column:1/-1;border-right:0}.detail-field span{display:block;margin-bottom:7px;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.detail-field strong,.detail-field p{margin:0;color:#172033;font-size:13px;line-height:1.55}.reference{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}.file-note{margin-top:7px!important;color:#64748b!important;font-size:11px!important}.action-panel{margin-top:20px;padding:20px;border:1px solid #e9e1d5;border-radius:15px;background:#fff}.action-panel h2{margin:0;font-size:16px}.action-panel>p{margin:6px 0 16px;color:#64748b;font-size:12px}.actions{display:flex;gap:9px;flex-wrap:wrap}.actions form{margin:0}.return-action{margin-bottom:12px}.processing-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.review-form{padding:16px;border:1px solid #eee6db;border-radius:12px;background:#fcfaf7}.review-form h3{margin:0 0 5px;font-size:13px}.review-form p{margin:0 0 12px;color:#64748b;font-size:11px;line-height:1.45}.review-form label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.review-form input,.review-form textarea{width:100%;box-sizing:border-box;margin-bottom:10px;border:1px solid #cbd5e1;border-radius:9px;padding:10px 11px;background:#fff;color:#172033;font:inherit;font-size:12px}.review-form textarea{min-height:72px;resize:vertical}.action-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;box-sizing:border-box;border:1px solid #9a5b13;border-radius:9px;padding:8px 14px;background:#9a5b13;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer}.action-button:hover{background:#79450b;color:#fff}.action-button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.action-button.success{border-color:#15803d;background:#15803d}.action-button.danger{border-color:#b42318;background:#b42318}.empty-state{padding:70px 24px;text-align:center;border:1px solid #e9e1d5;border-radius:18px;background:#fff}.empty-mark{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 15px;border-radius:14px;background:#f8ecd8;color:#9a5b13;font-size:22px}.empty-state h1{margin:0;font-size:22px}.empty-state p{max-width:520px;margin:8px auto 20px;color:#64748b;font-size:13px;line-height:1.55}@media(max-width:650px){.detail-topline{align-items:flex-start}.detail-grid,.processing-grid{grid-template-columns:1fr}.detail-field,.detail-field:nth-child(odd){border-right:0}.detail-hero{padding:24px 20px}.actions,.actions form,.action-button{width:100%}}
</style>

<div class="copyright-detail">
  <div class="detail-topline"><a class="back-link" href="<?= copyrightDetailEscape(SITE_URL . 'pages/shared/copyrights.php') ?>">&larr; Back to Copyrights</a><?php if ($canEdit): ?><a class="action-button secondary" href="<?= copyrightDetailEscape(SITE_URL . 'pages/shared/copyright-form.php?id=' . (int) $application['copyright_id']) ?>">Edit application</a><?php endif; ?></div>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= copyrightDetailEscape($flash['message'] ?? '') ?></div><?php endif; ?>

  <?php if (!$application): ?>
    <section class="empty-state"><div class="empty-mark" aria-hidden="true">?</div><h1>Copyright application not found</h1><p><?= copyrightDetailEscape($copyrightsAvailable ? 'The application may have been removed, or the link is incomplete.' : 'The copyrights module is not installed. Apply database migration 012 to enable it.') ?></p><a class="action-button" href="<?= copyrightDetailEscape(SITE_URL . 'pages/shared/copyrights.php') ?>">Return to Copyrights</a></section>
  <?php else: ?>
    <article class="detail-card">
      <header class="detail-hero"><div class="hero-meta"><span class="badge type-badge"><?= copyrightDetailEscape($typeOptions[$application['output_type']] ?? ucwords(str_replace('_', ' ', (string) $application['output_type']))) ?></span><span class="badge tone-<?= copyrightDetailEscape($badge['tone']) ?>"><?= copyrightDetailEscape($badge['label']) ?></span></div><h1><?= copyrightDetailEscape($application['output_title']) ?></h1><p><?= copyrightDetailEscape($application['applicant_name'] ?: 'Unnamed applicant') ?> &middot; <?= copyrightDetailEscape(ucfirst((string) $application['applicant_category'])) ?></p></header>
      <div class="detail-grid">
        <?php if ($application['copyright_ref_no']): ?><div class="detail-field"><span>Copyright reference no.</span><strong class="reference"><?= copyrightDetailEscape($application['copyright_ref_no']) ?></strong></div><?php endif; ?>
        <div class="detail-field"><span>Date completed</span><strong><?= copyrightDetailEscape($application['date_completed'] ? date('F j, Y', strtotime((string) $application['date_completed'])) : 'Not recorded') ?></strong></div>
        <div class="detail-field full"><span>Co-authors</span><p><?= copyrightDetailEscape($application['co_authors'] ?: 'None recorded') ?></p></div>
        <div class="detail-field"><span>Supporting file</span><?php if ($fileUrl): ?><a class="text-link" href="<?= copyrightDetailEscape($fileUrl) ?>" download="<?= copyrightDetailEscape($application['file_name'] ?: 'copyright-application.pdf') ?>">Download <?= copyrightDetailEscape($application['file_name'] ?: 'copyright-application.pdf') ?></a><p class="file-note">The stored file was verified inside the copyright upload directory.</p><?php elseif ($application['file_path']): ?><strong>File unavailable</strong><p class="file-note">The stored file could not be safely resolved.</p><?php else: ?><strong>No file attached</strong><?php endif; ?></div>
        <div class="detail-field"><span>Submitted</span><strong><?= copyrightDetailEscape(date('F j, Y, g:i a', strtotime((string) $application['created_at']))) ?></strong></div>
        <div class="detail-field"><span>Last updated</span><strong><?= copyrightDetailEscape(date('F j, Y, g:i a', strtotime((string) $application['updated_at']))) ?></strong></div>
      </div>
    </article>

    <?php if ($canReview): ?>
      <section class="action-panel" aria-labelledby="review-actions-heading"><h2 id="review-actions-heading">Processing actions</h2><p>Only valid next steps for the current status are available. Every transition is checked again when submitted.</p>
        <?php if ($application['status'] === 'pending'): ?><div class="actions"><form method="post"><?= csrfField() ?><input type="hidden" name="copyright_id" value="<?= (int) $application['copyright_id'] ?>"><input type="hidden" name="action" value="start_review"><button class="action-button" type="submit">Start review</button></form></div>
        <?php elseif ($application['status'] === 'under_review'): ?>
          <div class="actions return-action"><form method="post"><?= csrfField() ?><input type="hidden" name="copyright_id" value="<?= (int) $application['copyright_id'] ?>"><input type="hidden" name="action" value="return_pending"><button class="action-button secondary" type="submit">Return to pending</button></form></div>
          <div class="processing-grid">
            <form class="review-form" method="post"><h3>Register copyright</h3><p>Assign the official reference number when completing registration.</p><?= csrfField() ?><input type="hidden" name="copyright_id" value="<?= (int) $application['copyright_id'] ?>"><input type="hidden" name="action" value="register"><label for="copyright-ref-no">Copyright Ref. No. <span aria-hidden="true">*</span></label><input id="copyright-ref-no" name="copyright_ref_no" type="text" minlength="3" maxlength="120" required><button class="action-button success" type="submit">Register application</button></form>
            <form class="review-form" method="post"><h3>Reject application</h3><p>Add an optional reason; it will be included in the applicant notification.</p><?= csrfField() ?><input type="hidden" name="copyright_id" value="<?= (int) $application['copyright_id'] ?>"><input type="hidden" name="action" value="reject"><label for="rejection-reason">Reason</label><textarea id="rejection-reason" name="rejection_reason" maxlength="1000"></textarea><button class="action-button danger" type="submit">Reject application</button></form>
          </div>
        <?php else: ?><span class="badge tone-<?= $application['status'] === 'registered' ? 'success' : 'danger' ?>">Processing complete</span><?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
