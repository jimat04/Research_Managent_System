<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');
$user = getCurrentUser();

function adminResearchersEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminResearchersTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function adminResearchersRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

$hasActivities = adminResearchersTableExists($conn, 'activity_participants')
    && adminResearchersTableExists($conn, 'research_activities');
$hasPublications = adminResearchersTableExists($conn, 'publications');
$hasCopyrights = adminResearchersTableExists($conn, 'copyright_applications');

$researcherId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$roleInput = trim((string) ($_GET['role'] ?? ''));
$roleFilter = in_array($roleInput, ['faculty', 'student'], true) ? $roleInput : '';
$statusInput = trim((string) ($_GET['status'] ?? ''));
$statusFilter = in_array($statusInput, ['active', 'pending', 'suspended'], true) ? $statusInput : '';
$search = trim((string) ($_GET['q'] ?? ''));
$search = strlen($search) > 150 ? substr($search, 0, 150) : $search;

$profile = null;
$activities = [];
$publications = [];
$copyrights = [];
$researchers = [];
$capped = false;

if ($researcherId) {
    $stmt = $conn->prepare("SELECT user_id, role, first_name, last_name, email, student_id, department, program, year_level, specialization, academic_rank, status, created_at FROM users WHERE user_id = ? AND role IN ('faculty', 'student') AND deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $researcherId);
    $profile = adminResearchersRun($stmt)->fetch_assoc() ?: null;
    $stmt->close();

    if ($profile && $hasActivities) {
        $stmt = $conn->prepare('SELECT ra.activity_id, ra.activity_type, ra.title, ra.activity_date, ra.status, ap.role AS participant_role, ap.attended FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE ap.user_id = ? ORDER BY ra.activity_date DESC, ra.activity_id DESC LIMIT 100');
        $result = adminResearchersRun($stmt, 'i', [$researcherId]);
        while ($row = $result->fetch_assoc()) $activities[] = $row;
        $stmt->close();
    }
    if ($profile && $hasPublications) {
        $stmt = $conn->prepare('SELECT publication_id, research_title, publication_type, status, publication_date FROM publications WHERE researcher_id = ? ORDER BY created_at DESC, publication_id DESC LIMIT 100');
        $result = adminResearchersRun($stmt, 'i', [$researcherId]);
        while ($row = $result->fetch_assoc()) $publications[] = $row;
        $stmt->close();
    }
    if ($profile && $hasCopyrights) {
        $stmt = $conn->prepare('SELECT copyright_id, output_title, output_type, status, copyright_ref_no, registered_at FROM copyright_applications WHERE applicant_id = ? ORDER BY created_at DESC, copyright_id DESC LIMIT 100');
        $result = adminResearchersRun($stmt, 'i', [$researcherId]);
        while ($row = $result->fetch_assoc()) $copyrights[] = $row;
        $stmt->close();
    }
} else {
    $selectCounts = [
        $hasActivities
            ? '(SELECT COUNT(DISTINCT ap.activity_id) FROM activity_participants ap WHERE ap.user_id = u.user_id) AS activity_count'
            : '0 AS activity_count',
        $hasPublications
            ? '(SELECT COUNT(*) FROM publications p WHERE p.researcher_id = u.user_id) AS publication_count'
            : '0 AS publication_count',
        $hasCopyrights
            ? '(SELECT COUNT(*) FROM copyright_applications c WHERE c.applicant_id = u.user_id) AS copyright_count'
            : '0 AS copyright_count',
    ];
    $sql = 'SELECT u.user_id, u.role, u.first_name, u.last_name, u.email, u.department, u.program, u.specialization, u.academic_rank, u.status, '
        . implode(', ', $selectCounts)
        . " FROM users u WHERE u.role IN ('faculty', 'student') AND u.deleted_at IS NULL";
    $params = [];
    $types = '';
    if ($roleFilter !== '') {
        $sql .= ' AND u.role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }
    if ($statusFilter !== '') {
        $sql .= ' AND u.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($search !== '') {
        $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.program LIKE ? OR u.specialization LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= 'ssssss';
    }
    $sql .= " ORDER BY FIELD(u.status, 'active', 'pending', 'suspended'), u.last_name ASC, u.first_name ASC LIMIT 251";
    $stmt = $conn->prepare($sql);
    $result = adminResearchersRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) $researchers[] = $row;
    $stmt->close();
    $capped = count($researchers) > 250;
    if ($capped) $researchers = array_slice($researchers, 0, 250);
}

renderAdminShell(
    $user,
    'admin-researchers.php',
    $profile ? 'Researcher Profile' : 'Researcher Profiles',
    $profile ? 'Research specialization and participation history.' : 'Browse faculty and student researcher profiles and activity.'
);
?>
<style>
.researcher-page{max-width:1320px;margin:0 auto;color:#111827}.researcher-intro{margin-bottom:22px;padding:26px 28px;border:1px solid rgba(91,30,188,.18);border-left:4px solid #5b1ebc;border-radius:16px;background:linear-gradient(135deg,rgba(91,30,188,.08),#fff)}.researcher-intro h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.researcher-intro p{margin:8px 0 0;color:#64748b;line-height:1.55}.researcher-panel{overflow:hidden;margin-bottom:22px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.04)}.researcher-panel h2{margin:0;font-size:18px}.panel-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;border-bottom:1px solid #edf2f7}.panel-heading-left{display:flex;flex-direction:column;gap:4px}.researcher-muted{color:#64748b}.researcher-summary{display:flex;flex-wrap:wrap;gap:8px}.researcher-summary-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;color:#374151;font-size:11px;font-weight:700}.profile-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0}.profile-field{padding:17px 20px;border-bottom:1px solid #edf2f7}.profile-field span{display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700}.profile-field strong{font-size:13px;overflow-wrap:anywhere}.researcher-toolbar{display:grid;grid-template-columns:minmax(200px,1.8fr) minmax(160px,.9fr) minmax(160px,.9fr) auto;gap:12px;align-items:end;padding:18px 20px;border-bottom:1px solid #edf2f7}.researcher-toolbar label{display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700}.researcher-toolbar input,.researcher-toolbar select{width:100%;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:white;color:#111827;font:inherit}.researcher-toolbar input:focus,.researcher-toolbar select:focus,.researcher-button:focus-visible,.researcher-tab:focus-visible{outline:3px solid rgba(91,30,188,.16);outline-offset:2px;border-color:#5b1ebc}.researcher-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:8px 14px;border:1px solid #5b1ebc;border-radius:9px;background:#5b1ebc;color:#fff;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}.researcher-button.secondary{border-color:#d1d5db;background:#fff;color:#374151}.researcher-tabs{display:flex;flex-wrap:wrap;gap:10px;padding:16px 20px 0}.researcher-tab{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;color:#475569;text-decoration:none;font-weight:700;font-size:12px}.researcher-tab.active{background:linear-gradient(135deg,#5b1ebc,#7c3aed);border-color:transparent;color:#fff;box-shadow:0 8px 20px rgba(91,30,188,.18)}.researcher-list-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;padding:18px 20px 20px}.researcher-card{display:flex;flex-direction:column;gap:14px;padding:18px;border:1px solid #e5e7eb;border-radius:16px;background:linear-gradient(180deg,#fff,#f8fafc);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}.researcher-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.06);border-color:rgba(91,30,188,.15)}.researcher-card-head{display:flex;align-items:center;gap:12px}.researcher-avatar{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#5b1ebc,#8b5cf6);color:#fff;font-weight:800}.researcher-meta{flex:1;min-width:0}.researcher-meta h3{margin:0;font-size:20px;line-height:1.2;letter-spacing:-.02em}.researcher-meta h3 a{color:#111827;text-decoration:none}.researcher-email{margin-top:4px;color:#64748b;font-size:12px;word-break:break-word}.researcher-role-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.researcher-role-chip.role-faculty{background:rgba(29,78,216,.08);color:#1d4ed8}.researcher-role-chip.role-student{background:rgba(91,30,188,.08);color:#5b1ebc}.researcher-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding-top:4px;border-top:1px solid #edf2f7}.researcher-details div{display:flex;flex-direction:column;gap:4px}.researcher-details span{color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.researcher-details strong{font-size:13px;line-height:1.4;overflow-wrap:anywhere}.researcher-stat-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.researcher-stat{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:10px;background:#fff;border:1px solid #edf2f7}.researcher-stat span{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700}.researcher-stat strong{font-size:20px;line-height:1}.researcher-card-actions{display:flex;justify-content:flex-end}.researcher-table-wrap{overflow-x:auto}.researcher-table{width:100%;border-collapse:collapse;min-width:940px}.researcher-table th{padding:12px 15px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em}.researcher-table td{padding:13px 15px;border-top:1px solid #edf2f7;font-size:12px;vertical-align:middle}.researcher-link{color:#111827;font-weight:700;text-decoration:none}.researcher-link:hover{color:#5b1ebc}.researcher-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#fff4e8;color:#9a3f00;font-size:10px;font-weight:700}.researcher-empty{padding:42px 20px;text-align:center;color:#64748b}.researcher-empty strong{display:block;margin-bottom:5px;color:#111827}.activity-status{color:#475569}.back-link{color:#9a3f00;font-size:12px;font-weight:700;text-decoration:none}@media(max-width:980px){.researcher-toolbar{grid-template-columns:1fr 1fr}.researcher-list-grid{grid-template-columns:1fr}}@media(max-width:760px){.panel-heading{align-items:flex-start}.researcher-toolbar{grid-template-columns:1fr}.researcher-list-grid{grid-template-columns:1fr}.researcher-details{grid-template-columns:1fr}.researcher-stat-row{grid-template-columns:1fr 1fr 1fr}.researcher-card-actions .researcher-button{width:100%}}@media(max-width:560px){.researcher-summary{width:100%}.researcher-card-head{align-items:flex-start}.researcher-role-chip{margin-top:4px}.researcher-stat-row{grid-template-columns:1fr}.profile-grid{grid-template-columns:1fr}}
</style>

<main class="researcher-page">
  <header class="researcher-intro"><h1><?= adminResearchersEscape($profile ? trim($profile['first_name'] . ' ' . $profile['last_name']) : 'Researcher Profiles') ?></h1><p><?= $profile ? 'Research profile and associated participation records.' : 'Faculty and student researchers, their specializations, and their recorded research participation.' ?></p></header>

  <?php if ($researcherId && !$profile): ?>
    <section class="researcher-panel researcher-empty"><strong>Researcher not found</strong>The researcher may have been removed or the profile link is invalid.<p><a class="researcher-button" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">Return to researchers</a></p></section>
  <?php elseif ($profile): ?>
    <p><a class="back-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">&larr; All researchers</a></p>
    <section class="researcher-panel" aria-labelledby="profile-heading">
      <div class="panel-heading"><h2 id="profile-heading">Researcher information</h2><span class="researcher-badge"><?= adminResearchersEscape(ucfirst((string) $profile['role'])) ?></span></div>
      <div class="profile-grid">
        <div class="profile-field"><span>Email</span><strong><?= adminResearchersEscape($profile['email']) ?></strong></div>
        <div class="profile-field"><span>Research specialization</span><strong><?= adminResearchersEscape($profile['specialization'] ?: 'Not recorded') ?></strong></div>
        <div class="profile-field"><span>Academic rank</span><strong><?= adminResearchersEscape($profile['academic_rank'] ?: 'Not recorded') ?></strong></div>
        <div class="profile-field"><span>Department</span><strong><?= adminResearchersEscape($profile['department'] ?: 'Not recorded') ?></strong></div>
        <div class="profile-field"><span>Program</span><strong><?= adminResearchersEscape($profile['program'] ?: 'Not recorded') ?></strong></div>
        <div class="profile-field"><span>Account status</span><strong><?= adminResearchersEscape(ucfirst((string) $profile['status'])) ?></strong></div>
        <?php if ($profile['role'] === 'student'): ?><div class="profile-field"><span>Year level</span><strong><?= adminResearchersEscape($profile['year_level'] ?: 'Not recorded') ?></strong></div><?php endif; ?>
      </div>
    </section>

    <section class="researcher-panel" aria-labelledby="participation-heading">
      <div class="panel-heading"><div><h2 id="participation-heading">Research participation history</h2><span class="researcher-muted">Activities linked to this user account.</span></div></div>
      <?php if (!$hasActivities): ?><div class="researcher-empty"><strong>Activity history unavailable</strong>Install migration 012 to enable activity records.</div>
      <?php elseif (!$activities): ?><div class="researcher-empty"><strong>No linked participation records</strong>Participation appears here when this researcher is linked to an activity.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Date</th><th>Activity</th><th>Role</th><th>Status</th><th>Attendance</th></tr></thead><tbody><?php foreach ($activities as $activity): ?><tr><td><?= adminResearchersEscape(date('M j, Y', strtotime((string) $activity['activity_date']))) ?></td><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id']) ?>"><?= adminResearchersEscape($activity['title']) ?></a><div class="researcher-muted"><?= adminResearchersEscape(ucfirst((string) $activity['activity_type'])) ?></div></td><td><?= adminResearchersEscape(ucfirst((string) $activity['participant_role'])) ?></td><td class="activity-status"><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $activity['status']))) ?></td><td><?= (int) $activity['attended'] === 1 ? 'Attended' : 'Not recorded' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="researcher-panel" aria-labelledby="publications-heading">
      <div class="panel-heading"><h2 id="publications-heading">Publications</h2></div>
      <?php if (!$hasPublications): ?><div class="researcher-empty"><strong>Publication records unavailable</strong>Install migration 012 to enable publication records.</div>
      <?php elseif (!$publications): ?><div class="researcher-empty"><strong>No publication records</strong>Publications associated with this researcher will appear here.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Publication date</th></tr></thead><tbody><?php foreach ($publications as $publication): ?><tr><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $publication['publication_id']) ?>"><?= adminResearchersEscape($publication['research_title']) ?></a></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $publication['status']))) ?></td><td><?= adminResearchersEscape($publication['publication_date'] ? date('M j, Y', strtotime((string) $publication['publication_date'])) : 'Not recorded') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="researcher-panel" aria-labelledby="outputs-heading">
      <div class="panel-heading"><h2 id="outputs-heading">Copyright and intellectual outputs</h2></div>
      <?php if (!$hasCopyrights): ?><div class="researcher-empty"><strong>Output records unavailable</strong>Install migration 012 to enable copyright records.</div>
      <?php elseif (!$copyrights): ?><div class="researcher-empty"><strong>No copyright applications</strong>Applications associated with this researcher will appear here.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Output</th><th>Type</th><th>Status</th><th>Reference</th><th>Registered</th></tr></thead><tbody><?php foreach ($copyrights as $copyright): ?><tr><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $copyright['copyright_id']) ?>"><?= adminResearchersEscape($copyright['output_title']) ?></a></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $copyright['output_type']))) ?></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $copyright['status']))) ?></td><td><?= adminResearchersEscape($copyright['copyright_ref_no'] ?: '—') ?></td><td><?= adminResearchersEscape($copyright['registered_at'] ? date('M j, Y', strtotime((string) $copyright['registered_at'])) : '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  <?php else: ?>
    <section class="researcher-panel" aria-labelledby="researcher-directory-heading">
      <div class="panel-heading">
        <div class="panel-heading-left">
          <h2 id="researcher-directory-heading">Researchers</h2>
          <span class="researcher-muted"><?= count($researchers) ?> <?= count($researchers) === 1 ? 'profile' : 'profiles' ?> shown</span>
        </div>
        <div class="researcher-summary">
          <?php $facultyCount = count(array_filter($researchers, fn($r) => ($r['role'] ?? '') === 'faculty')); ?>
          <?php $studentCount = count(array_filter($researchers, fn($r) => ($r['role'] ?? '') === 'student')); ?>
          <span class="researcher-summary-pill">Faculty: <?= $facultyCount ?></span>
          <span class="researcher-summary-pill">Students: <?= $studentCount ?></span>
        </div>
      </div>

      <form class="researcher-toolbar" method="get" action="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">
        <div>
          <label for="researcher-q">Name, email, department, program, or specialization</label>
          <input id="researcher-q" name="q" type="search" maxlength="150" value="<?= adminResearchersEscape($search) ?>" placeholder="Search researcher profiles">
        </div>
        <div>
          <label for="researcher-role">Role</label>
          <select id="researcher-role" name="role">
            <option value="">Faculty and students</option>
            <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Students</option>
          </select>
        </div>
        <div>
          <label for="researcher-status">Account status</label>
          <select id="researcher-status" name="status">
            <option value="">All statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
          </select>
        </div>
        <div>
          <button class="researcher-button" type="submit">Apply</button>
          <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
            <a class="researcher-button secondary" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <nav class="researcher-tabs" aria-label="Researcher role filters">
        <?php $allTabHref = SITE_URL . 'pages/admin/admin-researchers.php' . ($search !== '' || $statusFilter !== '' ? '?q=' . rawurlencode($search) . '&status=' . rawurlencode($statusFilter) : ''); ?>
        <a class="researcher-tab <?= $roleFilter === '' ? 'active' : '' ?>" href="<?= adminResearchersEscape($allTabHref) ?>">All</a>
        <a class="researcher-tab <?= $roleFilter === 'faculty' ? 'active' : '' ?>" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?role=faculty' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '')) ?>">Faculty</a>
        <a class="researcher-tab <?= $roleFilter === 'student' ? 'active' : '' ?>" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?role=student' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '')) ?>">Students</a>
      </nav>
      <?php if (!$researchers): ?><div class="researcher-empty"><strong>No researcher profiles found</strong>Try changing the search or filters.</div>
      <?php else: ?>
        <div class="researcher-list-grid">
          <?php foreach ($researchers as $researcher):
              $researcherName = trim((string) ($researcher['first_name'] ?? '') . ' ' . (string) ($researcher['last_name'] ?? ''));
              $initials = strtoupper(trim(substr((string) ($researcher['first_name'] ?? ''), 0, 1) . substr((string) ($researcher['last_name'] ?? ''), 0, 1)) ?: 'R');
              $roleKey = strtolower((string) ($researcher['role'] ?? 'researcher'));
          ?>
            <article class="researcher-card" aria-label="<?= adminResearchersEscape($researcherName) ?> profile">
              <div class="researcher-card-head">
                <div class="researcher-avatar"><?= adminResearchersEscape($initials) ?></div>
                <div class="researcher-meta">
                  <h3><a href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . (int) $researcher['user_id']) ?>"><?= adminResearchersEscape($researcherName ?: 'Unnamed researcher') ?></a></h3>
                  <div class="researcher-email"><?= adminResearchersEscape((string) ($researcher['email'] ?? '')) ?></div>
                </div>
                <span class="researcher-role-chip role-<?= adminResearchersEscape($roleKey) ?>"><?= adminResearchersEscape(ucfirst((string) ($researcher['role'] ?? 'researcher'))) ?></span>
              </div>

              <div class="researcher-details">
                <div>
                  <span>Department</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['department'] ?: '—')) ?></strong>
                </div>
                <div>
                  <span>Program</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['program'] ?: '—')) ?></strong>
                </div>
                <div>
                  <span>Specialization</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['specialization'] ?: '—')) ?></strong>
                </div>
                <div>
                  <span>Rank</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['academic_rank'] ?: '—')) ?></strong>
                </div>
              </div>

              <div class="researcher-stat-row">
                <div class="researcher-stat"><span>Activities</span><strong><?= (int) ($researcher['activity_count'] ?? 0) ?></strong></div>
                <div class="researcher-stat"><span>Publications</span><strong><?= (int) ($researcher['publication_count'] ?? 0) ?></strong></div>
                <div class="researcher-stat"><span>Outputs</span><strong><?= (int) ($researcher['copyright_count'] ?? 0) ?></strong></div>
              </div>

              <div class="researcher-card-actions">
                <a class="researcher-button secondary" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . (int) $researcher['user_id']) ?>">View profile</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($capped): ?><div class="researcher-empty">Showing the first 250 matching profiles. Refine the search to narrow the directory.</div><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php renderAdminShellClose(); ?>
