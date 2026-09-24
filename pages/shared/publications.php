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

function publicationsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function publicationsRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function publicationsStatusBadge(string $status): array
{
    return match ($status) {
        'submitted' => ['label' => 'Submitted', 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'published' => ['label' => 'Published', 'tone' => 'success'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'publications'");
$publicationsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) {
    $tableCheck->free();
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$ownScope = in_array($role, ['faculty', 'student'], true);
$canReview = in_array($role, ['research_staff', 'admin'], true);
$flash = $_SESSION['publications_flash'] ?? null;
unset($_SESSION['publications_flash']);
$statusOptions = ['submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'published' => 'Published'];
$typeOptions = ['journal' => 'Journal', 'conference' => 'Conference', 'book_chapter' => 'Book chapter', 'other' => 'Other'];
$query = trim((string) ($_GET['q'] ?? ''));
$query = strlen($query) > 150 ? substr($query, 0, 150) : $query;
$statusInput = trim((string) ($_GET['status'] ?? ''));
$status = preg_match('/^(submitted|under_review|accepted|published)$/D', $statusInput) ? $statusInput : '';
$typeInput = trim((string) ($_GET['publication_type'] ?? ''));
$publicationType = preg_match('/^(journal|conference|book_chapter|other)$/D', $typeInput) ? $typeInput : '';
$filtersActive = $query !== '' || $status !== '' || $publicationType !== '';

$stats = ['published' => 0, 'under_review' => 0, 'total' => 0];
$publications = [];
$recentPublished = [];
if ($publicationsAvailable) {
    $statsSql = "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'published'), 0) AS published, COALESCE(SUM(status = 'under_review'), 0) AS under_review FROM publications";
    if ($ownScope) {
        $statsSql .= ' WHERE researcher_id = ?';
    }
    $stmt = $conn->prepare($statsSql);
    $statsRow = publicationsRun($stmt, $ownScope ? 'i' : '', $ownScope ? [$userId] : [])->fetch_assoc() ?: [];
    foreach (array_keys($stats) as $key) {
        $stats[$key] = (int) ($statsRow[$key] ?? 0);
    }
    $stmt->close();

    $sql = 'SELECT publication_id, research_title, researcher_id, researcher_name, researcher_category, publication_type, journal_publisher, publication_date, status FROM publications WHERE 1 = 1';
    $params = [];
    $types = '';
    if ($ownScope) {
        $sql .= ' AND researcher_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = '%' . $escaped . '%';
        $sql .= " AND (research_title LIKE ? ESCAPE '\\\\' OR researcher_name LIKE ? ESCAPE '\\\\' OR journal_publisher LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($publicationType !== '') {
        $sql .= ' AND publication_type = ?';
        $params[] = $publicationType;
        $types .= 's';
    }
    $sql .= ' ORDER BY publication_date DESC, publication_id DESC LIMIT 250';
    $stmt = $conn->prepare($sql);
    $result = publicationsRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) {
        $publications[] = $row;
    }
    $stmt->close();

    if ($ownScope) {
        $stmt = $conn->prepare("SELECT publication_id, research_title, researcher_name, journal_publisher, publication_date FROM publications WHERE status = 'published' ORDER BY publication_date DESC, publication_id DESC LIMIT 5");
        $result = publicationsRun($stmt);
        while ($row = $result->fetch_assoc()) {
            $recentPublished[] = $row;
        }
        $stmt->close();
    }
}

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
$subtitle = $ownScope ? 'Your publication record and recent institutional outputs' : 'Institutional publication monitoring';
if ($shell === 'admin') renderAdminShell($user, 'publications.php', 'Publications', $subtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'publications.php', 'Publications', $subtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'publications.php', 'Publications', $subtitle);
else renderStudentShell($user, 'publications.php', 'Publications', $subtitle);
?>

<style>
.module-hub{max-width:1320px;margin:0 auto;color:#172033}.module-intro{display:flex;justify-content:space-between;gap:24px;align-items:end;margin:0 0 24px;padding:28px 30px;border-left:5px solid #315b9f;border-radius:4px 18px 18px 4px;background:#eef3fb}.module-kicker{margin:0 0 7px;color:#315b9f;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-intro h1{margin:0;font-size:clamp(25px,3vw,38px);line-height:1.08;letter-spacing:-.035em}.module-intro p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.intro-actions{display:flex;gap:9px;flex-wrap:wrap}.repository-link,.hub-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;box-sizing:border-box;border:1px solid #315b9f;border-radius:9px;padding:0 15px;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap;transition:transform .18s ease,background .18s ease}.repository-link{color:#315b9f}.hub-button{background:#315b9f;color:#fff;font:inherit;cursor:pointer}.repository-link:hover{background:#fff;color:#24477e;transform:translateY(-1px)}.hub-button:hover{background:#24477e;color:#fff;transform:translateY(-1px)}.hub-button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}.stat-card{padding:20px 22px;border:1px solid #dfe5ee;border-radius:14px;background:#fff}.stat-card strong{display:block;font-size:30px;line-height:1;font-variant-numeric:tabular-nums}.stat-card span{display:block;margin-top:8px;color:#64748b;font-size:12px;font-weight:700}.hub-panel{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.filter-form{display:grid;grid-template-columns:minmax(220px,1fr) 170px 180px auto;gap:12px;align-items:end;padding:20px}.field label{display:block;margin-bottom:6px;color:#526176;font-size:11px;font-weight:800}.field input,.field select{width:100%;min-height:43px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:13px}.field input:focus,.field select:focus,.hub-button:focus-visible,.repository-link:focus-visible,.row-link:focus-visible{border-color:#315b9f;outline:3px solid rgba(49,91,159,.12);outline-offset:2px}.filter-actions{display:flex;gap:8px}.table-wrap{overflow-x:auto}.hub-table{width:100%;min-width:1080px;border-collapse:collapse}.hub-table th{padding:12px 16px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;letter-spacing:.06em;text-transform:uppercase}.hub-table td{padding:15px 16px;border-top:1px solid #edf1f5;font-size:13px;vertical-align:middle}.title-cell{max-width:320px;font-weight:750;line-height:1.4}.title-cell a,.recent-item a{color:#172033;text-decoration:none}.title-cell a:hover,.recent-item a:hover{color:#315b9f}.muted{color:#64748b}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-neutral{background:#e2e8f0;color:#475569}.row-actions{display:flex;gap:9px;white-space:nowrap}.row-link{color:#315b9f;font-size:11px;font-weight:800;text-decoration:none}.recent-list{display:grid;grid-template-columns:repeat(5,minmax(0,1fr))}.recent-item{padding:18px 20px;min-width:0}.recent-item+.recent-item{border-left:1px solid #edf1f5}.recent-item strong{display:block;font-size:13px;line-height:1.45}.recent-item span{display:block;margin-top:6px;color:#64748b;font-size:11px;line-height:1.45}.flash{margin-bottom:18px;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.empty-state{padding:48px 24px;text-align:center}.empty-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border-radius:13px;background:#e8eef9;color:#315b9f;font-size:21px}.empty-state h2{margin:0;font-size:17px}.empty-state p{max-width:480px;margin:7px auto 0;color:#64748b;font-size:13px;line-height:1.55}@media(max-width:980px){.filter-form{grid-template-columns:1fr 1fr}.recent-list{grid-template-columns:repeat(2,1fr)}.recent-item+.recent-item{border-left:0;border-top:1px solid #edf1f5}}@media(max-width:640px){.module-intro{display:block;padding:24px 21px}.intro-actions{margin-top:18px}.stat-grid,.filter-form,.recent-list{grid-template-columns:1fr}.filter-actions,.hub-button,.repository-link{width:100%}}
</style>

<div class="module-hub">
  <header class="module-intro"><div><p class="module-kicker">Research dissemination</p><h1>Publication record</h1><p><?= publicationsEscape($ownScope ? 'Track your submitted work while keeping sight of recently published institutional research.' : 'Monitor faculty and student publication outputs from submission through publication.') ?></p></div><div class="intro-actions"><a class="hub-button" href="<?= publicationsEscape(SITE_URL . 'pages/shared/publication-form.php') ?>">+ Add Publication</a><a class="repository-link" href="<?= publicationsEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">Open Repository</a></div></header>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= publicationsEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <section class="stat-grid" aria-label="Publication statistics"><article class="stat-card"><strong><?= $stats['published'] ?></strong><span>Published</span></article><article class="stat-card"><strong><?= $stats['under_review'] ?></strong><span>Under review</span></article><article class="stat-card"><strong><?= $stats['total'] ?></strong><span>Total</span></article></section>

  <?php if ($ownScope): ?>
  <section class="hub-panel" aria-labelledby="recent-publications-heading"><div class="panel-head"><div><h2 id="recent-publications-heading">Recent publications (all)</h2><p>The five latest published outputs across the institution.</p></div></div>
    <?php if (!$publicationsAvailable || !$recentPublished): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#128214;</div><h2>No published outputs yet</h2><p><?= $publicationsAvailable ? 'Published institutional work will appear here.' : 'The publications module is not installed on this database.' ?></p></div>
    <?php else: ?><div class="recent-list"><?php foreach ($recentPublished as $item): ?><article class="recent-item"><strong><a href="<?= publicationsEscape(SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $item['publication_id']) ?>"><?= publicationsEscape($item['research_title']) ?></a></strong><span><?= publicationsEscape($item['researcher_name'] ?: 'Unnamed researcher') ?></span><span><?= publicationsEscape($item['journal_publisher'] ?: 'Publisher not recorded') ?><?= $item['publication_date'] ? ' · ' . publicationsEscape(date('M Y', strtotime((string) $item['publication_date']))) : '' ?></span></article><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="hub-panel" aria-labelledby="publication-directory-heading"><div class="panel-head"><div><h2 id="publication-directory-heading"><?= $ownScope ? 'My publications' : 'Publication directory' ?></h2><p><?= count($publications) ?> <?= count($publications) === 1 ? 'record' : 'records' ?> in view</p></div></div>
    <form class="filter-form" method="get" action="<?= publicationsEscape(SITE_URL . 'pages/shared/publications.php') ?>"><div class="field"><label for="publication-q">Title, researcher, or publisher</label><input id="publication-q" name="q" type="search" maxlength="150" value="<?= publicationsEscape($query) ?>" placeholder="Search publications"></div><div class="field"><label for="publication-status">Status</label><select id="publication-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= publicationsEscape($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= publicationsEscape($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="publication-type">Publication type</label><select id="publication-type" name="publication_type"><option value="">All types</option><?php foreach ($typeOptions as $value => $label): ?><option value="<?= publicationsEscape($value) ?>" <?= $publicationType === $value ? 'selected' : '' ?>><?= publicationsEscape($label) ?></option><?php endforeach; ?></select></div><div class="filter-actions"><button class="hub-button" type="submit">Apply</button><?php if ($filtersActive): ?><a class="hub-button secondary" href="<?= publicationsEscape(SITE_URL . 'pages/shared/publications.php') ?>">Clear</a><?php endif; ?></div></form>
    <?php if (!$publicationsAvailable || !$publications): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#8635;</div><h2><?= $publicationsAvailable ? 'No publications found' : 'Publications are unavailable' ?></h2><p><?= $publicationsAvailable ? ($ownScope ? 'No submissions match your current search and filters.' : 'Adjust the search or filters to see more publication records.') : 'Apply database migration 012 to enable this module.' ?></p></div>
    <?php else: ?><div class="table-wrap"><table class="hub-table"><thead><tr><th>Title</th><th>Researcher</th><th>Type</th><th>Journal / publisher</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($publications as $publication): $badge = publicationsStatusBadge((string) $publication['status']); $detailUrl = SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $publication['publication_id']; $canEdit = $canReview || ($ownScope && (int) $publication['researcher_id'] === $userId && $publication['status'] === 'submitted'); ?><tr><td class="title-cell"><a href="<?= publicationsEscape($detailUrl) ?>"><?= publicationsEscape($publication['research_title']) ?></a></td><td><?= publicationsEscape($publication['researcher_name'] ?: '—') ?><div class="muted"><?= publicationsEscape(ucfirst((string) $publication['researcher_category'])) ?></div></td><td><?= publicationsEscape($typeOptions[$publication['publication_type']] ?? ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?></td><td class="muted"><?= publicationsEscape($publication['journal_publisher'] ?: '—') ?></td><td><?= publicationsEscape($publication['publication_date'] ? date('M j, Y', strtotime((string) $publication['publication_date'])) : '—') ?></td><td><span class="badge tone-<?= publicationsEscape($badge['tone']) ?>"><?= publicationsEscape($badge['label']) ?></span></td><td><div class="row-actions"><a class="row-link" href="<?= publicationsEscape($detailUrl) ?>">View</a><?php if ($canEdit): ?><a class="row-link" href="<?= publicationsEscape(SITE_URL . 'pages/shared/publication-form.php?id=' . (int) $publication['publication_id']) ?>">Edit</a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
