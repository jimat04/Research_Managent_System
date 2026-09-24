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

function copyrightsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function copyrightsRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function copyrightsStatusBadge(string $status): array
{
    return match ($status) {
        'pending' => ['label' => 'Pending', 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'registered' => ['label' => 'Registered', 'tone' => 'success'],
        'rejected' => ['label' => 'Rejected', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'copyright_applications'");
$copyrightsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) {
    $tableCheck->free();
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$ownScope = in_array($role, ['faculty', 'student'], true);
$statusOptions = ['pending' => 'Pending', 'under_review' => 'Under review', 'registered' => 'Registered', 'rejected' => 'Rejected'];
$typeOptions = ['software' => 'Software', 'research' => 'Research', 'instructional_material' => 'Instructional material', 'module' => 'Module', 'other' => 'Other'];
$query = trim((string) ($_GET['q'] ?? ''));
$query = strlen($query) > 150 ? substr($query, 0, 150) : $query;
$statusInput = trim((string) ($_GET['status'] ?? ''));
$status = preg_match('/^(pending|under_review|registered|rejected)$/D', $statusInput) ? $statusInput : '';
$typeInput = trim((string) ($_GET['output_type'] ?? ''));
$outputType = preg_match('/^(software|research|instructional_material|module|other)$/D', $typeInput) ? $typeInput : '';
$filtersActive = $query !== '' || $status !== '' || $outputType !== '';

$stats = ['total' => 0, 'pending' => 0, 'registered' => 0];
$applications = [];
if ($copyrightsAvailable) {
    $statsSql = "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'pending'), 0) AS pending, COALESCE(SUM(status = 'registered'), 0) AS registered FROM copyright_applications";
    if ($ownScope) {
        $statsSql .= ' WHERE applicant_id = ?';
    }
    $stmt = $conn->prepare($statsSql);
    $statsRow = copyrightsRun($stmt, $ownScope ? 'i' : '', $ownScope ? [$userId] : [])->fetch_assoc() ?: [];
    foreach (array_keys($stats) as $key) {
        $stats[$key] = (int) ($statsRow[$key] ?? 0);
    }
    $stmt->close();

    $sql = 'SELECT copyright_id, copyright_ref_no, output_title, applicant_name, applicant_category, output_type, status FROM copyright_applications WHERE 1 = 1';
    $params = [];
    $types = '';
    if ($ownScope) {
        $sql .= ' AND applicant_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = '%' . $escaped . '%';
        $sql .= " AND (output_title LIKE ? ESCAPE '\\\\' OR applicant_name LIKE ? ESCAPE '\\\\' OR copyright_ref_no LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($outputType !== '') {
        $sql .= ' AND output_type = ?';
        $params[] = $outputType;
        $types .= 's';
    }
    $sql .= ' ORDER BY created_at DESC, copyright_id DESC LIMIT 250';
    $stmt = $conn->prepare($sql);
    $result = copyrightsRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    $stmt->close();
}

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
$subtitle = $ownScope ? 'Your copyright application record' : 'Institutional copyright application monitoring';
if ($shell === 'admin') renderAdminShell($user, 'copyrights.php', 'Copyrights', $subtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'copyrights.php', 'Copyrights', $subtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'copyrights.php', 'Copyrights', $subtitle);
else renderStudentShell($user, 'copyrights.php', 'Copyrights', $subtitle);
?>

<style>
.module-hub{max-width:1320px;margin:0 auto;color:#172033}.module-intro{margin:0 0 24px;padding:28px 30px;border-left:5px solid #9a5b13;border-radius:4px 18px 18px 4px;background:#fbf4e8}.module-kicker{margin:0 0 7px;color:#9a5b13;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-intro h1{margin:0;font-size:clamp(25px,3vw,38px);line-height:1.08;letter-spacing:-.035em}.module-intro p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}.stat-card{padding:20px 22px;border:1px solid #e9e1d5;border-radius:14px;background:#fff}.stat-card strong{display:block;font-size:30px;line-height:1;font-variant-numeric:tabular-nums}.stat-card span{display:block;margin-top:8px;color:#64748b;font-size:12px;font-weight:700}.hub-panel{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.filter-form{display:grid;grid-template-columns:minmax(220px,1fr) 170px 210px auto;gap:12px;align-items:end;padding:20px}.field label{display:block;margin-bottom:6px;color:#526176;font-size:11px;font-weight:800}.field input,.field select{width:100%;min-height:43px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:13px}.field input:focus,.field select:focus{border-color:#9a5b13;outline:3px solid rgba(154,91,19,.12)}.filter-actions{display:flex;gap:8px}.hub-button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;box-sizing:border-box;border:1px solid #9a5b13;border-radius:9px;padding:9px 15px;background:#9a5b13;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.hub-button:hover{background:#79450b;color:#fff;transform:translateY(-1px)}.hub-button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.table-wrap{overflow-x:auto}.hub-table{width:100%;min-width:980px;border-collapse:collapse}.hub-table th{padding:12px 16px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;letter-spacing:.06em;text-transform:uppercase}.hub-table td{padding:15px 16px;border-top:1px solid #edf1f5;font-size:13px;vertical-align:middle}.title-cell{max-width:330px;font-weight:750;line-height:1.4}.muted{color:#64748b}.ref{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:#526176;font-size:12px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.empty-state{padding:48px 24px;text-align:center}.empty-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border-radius:13px;background:#f8ecd8;color:#9a5b13;font-size:21px}.empty-state h2{margin:0;font-size:17px}.empty-state p{max-width:480px;margin:7px auto 0;color:#64748b;font-size:13px;line-height:1.55}@media(max-width:980px){.filter-form{grid-template-columns:1fr 1fr}}@media(max-width:640px){.module-intro{padding:24px 21px}.stat-grid,.filter-form{grid-template-columns:1fr}.filter-actions,.hub-button{width:100%}}
</style>

<div class="module-hub">
  <header class="module-intro"><p class="module-kicker">Intellectual property</p><h1>Copyright application register</h1><p><?= copyrightsEscape($ownScope ? 'Review the progress and registration details of your submitted creative and research outputs.' : 'Monitor copyright applications for software, research, instructional materials, and modules.') ?></p></header>
  <section class="stat-grid" aria-label="Copyright statistics"><article class="stat-card"><strong><?= $stats['total'] ?></strong><span>Total applications</span></article><article class="stat-card"><strong><?= $stats['pending'] ?></strong><span>Pending</span></article><article class="stat-card"><strong><?= $stats['registered'] ?></strong><span>Registered</span></article></section>
  <section class="hub-panel" aria-labelledby="copyright-directory-heading"><div class="panel-head"><div><h2 id="copyright-directory-heading"><?= $ownScope ? 'My applications' : 'Application directory' ?></h2><p><?= count($applications) ?> <?= count($applications) === 1 ? 'record' : 'records' ?> in view</p></div></div>
    <form class="filter-form" method="get" action="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyrights.php') ?>"><div class="field"><label for="copyright-q">Title, applicant, or reference no.</label><input id="copyright-q" name="q" type="search" maxlength="150" value="<?= copyrightsEscape($query) ?>" placeholder="Search applications"></div><div class="field"><label for="copyright-status">Status</label><select id="copyright-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= copyrightsEscape($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= copyrightsEscape($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="copyright-type">Output type</label><select id="copyright-type" name="output_type"><option value="">All types</option><?php foreach ($typeOptions as $value => $label): ?><option value="<?= copyrightsEscape($value) ?>" <?= $outputType === $value ? 'selected' : '' ?>><?= copyrightsEscape($label) ?></option><?php endforeach; ?></select></div><div class="filter-actions"><button class="hub-button" type="submit">Apply</button><?php if ($filtersActive): ?><a class="hub-button secondary" href="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyrights.php') ?>">Clear</a><?php endif; ?></div></form>
    <?php if (!$copyrightsAvailable || !$applications): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#169;</div><h2><?= $copyrightsAvailable ? 'No applications found' : 'Copyrights are unavailable' ?></h2><p><?= $copyrightsAvailable ? ($ownScope ? 'No applications match your current search and filters.' : 'Adjust the search or filters to see more copyright records.') : 'Apply database migration 012 to enable this module.' ?></p></div>
    <?php else: ?><div class="table-wrap"><table class="hub-table"><thead><tr><th>Reference no.</th><th>Output title</th><th>Applicant</th><th>Category</th><th>Output type</th><th>Status</th></tr></thead><tbody><?php foreach ($applications as $application): $badge = copyrightsStatusBadge((string) $application['status']); ?><tr><td class="ref"><?= copyrightsEscape($application['copyright_ref_no'] ?: 'Pending') ?></td><td class="title-cell"><?= copyrightsEscape($application['output_title']) ?></td><td><?= copyrightsEscape($application['applicant_name'] ?: '—') ?></td><td><?= copyrightsEscape(ucfirst((string) $application['applicant_category'])) ?></td><td><?= copyrightsEscape($typeOptions[$application['output_type']] ?? ucwords(str_replace('_', ' ', (string) $application['output_type']))) ?></td><td><span class="badge tone-<?= copyrightsEscape($badge['tone']) ?>"><?= copyrightsEscape($badge['label']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
