<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['faculty', 'student'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

function personalDashboardEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personalDashboardTableExists(mysqli $connection, string $table): bool
{
    $allowedTables = [
        'research_activities',
        'publications',
        'copyright_applications',
        'notifications',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $connection->prepare("SHOW TABLES LIKE '{$table}'");
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function personalDashboardStatusBadge(string $status): array
{
    return match ($status) {
        'scheduled', 'pending', 'submitted' => ['label' => ucfirst($status), 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'completed', 'published', 'registered' => ['label' => ucfirst($status), 'tone' => 'success'],
        'cancelled', 'rejected', 'error' => ['label' => ucfirst($status), 'tone' => 'danger'],
        'warning' => ['label' => 'Notice', 'tone' => 'warning'],
        'success' => ['label' => 'Update', 'tone' => 'success'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'tone' => 'neutral'],
    };
}

$userId = (int) ($user['user_id'] ?? 0);
$tables = [
    'activities' => personalDashboardTableExists($conn, 'research_activities'),
    'publications' => personalDashboardTableExists($conn, 'publications'),
    'copyrights' => personalDashboardTableExists($conn, 'copyright_applications'),
    'notifications' => personalDashboardTableExists($conn, 'notifications'),
];

$publicationCounts = ['submitted' => 0, 'under_review' => 0, 'accepted' => 0, 'published' => 0];
$recentPublications = [];
if ($tables['publications']) {
    $stmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM publications WHERE researcher_id = ? GROUP BY status');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $publicationCounts)) $publicationCounts[$status] = (int) $row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT publication_id, research_title, publication_type, publication_date, status, updated_at FROM publications WHERE researcher_id = ? ORDER BY updated_at DESC, publication_id DESC LIMIT 5');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentPublications[] = $row;
    $stmt->close();
}

$copyrightCounts = ['pending' => 0, 'under_review' => 0, 'registered' => 0, 'rejected' => 0];
$recentCopyrights = [];
if ($tables['copyrights']) {
    $stmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM copyright_applications WHERE applicant_id = ? GROUP BY status');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $copyrightCounts)) $copyrightCounts[$status] = (int) $row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT copyright_id, output_title, output_type, copyright_ref_no, status, updated_at FROM copyright_applications WHERE applicant_id = ? ORDER BY updated_at DESC, copyright_id DESC LIMIT 5');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentCopyrights[] = $row;
    $stmt->close();
}

$upcomingActivities = [];
if ($tables['activities']) {
    $scheduled = 'scheduled';
    $stmt = $conn->prepare('SELECT activity_id, activity_type, title, activity_date, activity_time, venue FROM research_activities WHERE status = ? AND activity_date >= CURDATE() ORDER BY activity_date ASC, activity_time ASC, activity_id ASC LIMIT 5');
    $stmt->bind_param('s', $scheduled);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $upcomingActivities[] = $row;
    $stmt->close();
}

$recentNotifications = [];
if ($tables['notifications']) {
    $stmt = $conn->prepare('SELECT notification_id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 5');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentNotifications[] = $row;
    $stmt->close();
}

$publicationTotal = array_sum($publicationCounts);
$copyrightTotal = array_sum($copyrightCounts);
$firstName = trim((string) ($user['first_name'] ?? '')) ?: ($role === 'faculty' ? 'Faculty' : 'Student');
$lastName = trim((string) ($user['last_name'] ?? ''));
$greeting = $role === 'faculty' ? 'Good day, Prof. ' . ($lastName ?: $firstName) : 'Welcome back, ' . $firstName;
$roleLabel = $role === 'faculty' ? 'Faculty Researcher' : 'Student Researcher';
$currentPage = $role === 'faculty' ? 'faculty-dashboard.php' : 'student-dashboard.php';
$pageSubtitle = 'Your research workspace at a glance';
if ($role === 'faculty') renderFacultyShell($user, $currentPage, 'Dashboard', $pageSubtitle);
else renderStudentShell($user, $currentPage, 'Dashboard', $pageSubtitle);
?>

<style>
.module-hub{--accent:#5b1ebc;--accent-dark:#481796;--accent-soft:#f1eafb;--ink:#172033;--copy:#64748b;--line:#e2e8f0;max-width:1380px;margin:0 auto;color:var(--ink)}.module-hub.role-faculty{--accent:#0f766e;--accent-dark:#115e59;--accent-soft:#e7f5f3}.module-greeting{display:flex;justify-content:space-between;gap:26px;align-items:end;margin:0 0 22px;padding:30px;border-left:5px solid var(--accent);border-radius:4px 18px 18px 4px;background:var(--accent-soft)}.module-kicker{margin:0 0 7px;color:var(--accent);font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-greeting h1{margin:0;font-size:clamp(27px,3vw,40px);line-height:1.08;letter-spacing:-.04em;text-wrap:balance}.module-greeting p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.greeting-meta{text-align:right}.greeting-meta strong{display:block;font-size:13px}.greeting-meta span{display:block;margin-top:5px;color:var(--copy);font-size:11px}.quick-links{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:22px}.quick-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #cbd5e1;border-radius:9px;padding:0 15px;background:#fff;color:#334155;font-size:12px;font-weight:800;text-decoration:none;transition:transform .18s ease,border-color .18s ease,color .18s ease,background .18s ease}.quick-link.primary{border-color:var(--accent);background:var(--accent);color:#fff}.quick-link:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px)}.quick-link.primary:hover{background:var(--accent-dark);color:#fff}.quick-link:active{transform:scale(.98)}.dashboard-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:18px}.hub-panel{min-width:0;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px;letter-spacing:-.015em}.panel-head p{margin:5px 0 0;color:var(--copy);font-size:12px;line-height:1.45}.panel-link{color:var(--accent);font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap}.count-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-bottom:1px solid #edf1f5;background:#fbfcfe}.count-item{padding:13px 12px;text-align:center}.count-item+.count-item{border-left:1px solid #edf1f5}.count-item strong{display:block;font-size:20px;font-variant-numeric:tabular-nums}.count-item span{display:block;margin-top:5px;color:var(--copy);font-size:9px;font-weight:800;line-height:1.25;text-transform:uppercase}.record-list{margin:0;padding:0;list-style:none}.record-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:15px 20px;border-bottom:1px solid #edf1f5}.record-item:last-child{border-bottom:0}.record-title{display:block;overflow:hidden;color:var(--ink);font-size:13px;font-weight:750;line-height:1.4;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.record-title:hover{color:var(--accent)}.record-meta{display:block;margin-top:5px;color:var(--copy);font-size:10px;line-height:1.4}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.activity-list,.notification-list{margin:0;padding:0;list-style:none}.activity-item,.notification-item{display:grid;gap:13px;padding:15px 20px;border-bottom:1px solid #edf1f5}.activity-item{grid-template-columns:56px minmax(0,1fr)}.notification-item{grid-template-columns:10px minmax(0,1fr)}.activity-item:last-child,.notification-item:last-child{border-bottom:0}.activity-date{display:grid;place-items:center;align-content:center;min-height:52px;border-radius:10px;background:var(--accent-soft);color:var(--accent);text-align:center}.activity-date strong{font-size:18px;line-height:1}.activity-date span{margin-top:3px;font-size:9px;font-weight:800;text-transform:uppercase}.activity-title,.notification-title{margin:0;color:var(--ink);font-size:13px;font-weight:750;line-height:1.4}.activity-title a{color:inherit;text-decoration:none}.activity-title a:hover{color:var(--accent)}.activity-meta,.notification-message,.notification-date{margin:4px 0 0;color:var(--copy);font-size:11px;line-height:1.45}.notification-dot{width:8px;height:8px;margin-top:5px;border-radius:50%;background:#94a3b8}.notification-dot.info{background:#2563eb}.notification-dot.success{background:#15803d}.notification-dot.warning{background:#d97706}.notification-dot.error{background:#b42318}.empty-state{padding:42px 22px;text-align:center}.empty-state strong{display:block;font-size:15px}.empty-state p{max-width:430px;margin:7px auto 0;color:var(--copy);font-size:12px;line-height:1.5}.quick-link:focus-visible,.panel-link:focus-visible,.record-title:focus-visible,.activity-title a:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 20%,transparent);outline-offset:3px}@media(max-width:900px){.dashboard-grid{grid-template-columns:1fr}}@media(max-width:640px){.module-greeting{display:block;padding:24px 21px}.greeting-meta{margin-top:18px;text-align:left}.quick-links{display:grid}.quick-link{width:100%;box-sizing:border-box}.count-strip{grid-template-columns:repeat(2,1fr)}.count-item:nth-child(3){border-left:0;border-top:1px solid #edf1f5}.count-item:nth-child(4){border-top:1px solid #edf1f5}.record-item{align-items:start}.record-title{white-space:normal}}
</style>
<style>
.module-hub {
  --accent: #5b1ebc;
  --accent-dark: #481796;
  --accent-soft: #f1ebff;
  --ink: #172033;
  --copy: #617087;
  --line: #e1e7ef;
  max-width: 1440px;
  margin: 0 auto;
  color: var(--ink);
}
.module-hub.role-faculty {
  --accent: #1d4ed8;
  --accent-dark: #1e40af;
  --accent-soft: #eaf0ff;
}
.module-greeting {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 28px;
  overflow: hidden;
  margin: 0 0 16px;
  padding: clamp(24px, 3vw, 36px);
  border: 1px solid color-mix(in srgb, var(--accent) 20%, white);
  border-left: 4px solid var(--accent);
  border-radius: 14px;
  background: radial-gradient(circle at 88% 10%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 34%), linear-gradient(115deg, color-mix(in srgb, var(--accent) 14%, white) 0%, #f8faff 76%);
}
.module-greeting::after {
  position: absolute;
  right: 17%;
  bottom: -82px;
  width: 220px;
  height: 220px;
  border: 1px solid color-mix(in srgb, var(--accent) 20%, transparent);
  border-radius: 50%;
  content: "";
  pointer-events: none;
}
.module-kicker {
  margin: 0 0 8px;
  color: var(--accent);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .11em;
  text-transform: uppercase;
}
.module-greeting h1 {
  margin: 0;
  font-size: clamp(25px, 2.6vw, 36px);
  font-weight: 750;
  line-height: 1.12;
  letter-spacing: -.035em;
  text-wrap: balance;
}
.module-greeting > div:first-child > p:last-child {
  max-width: 620px;
  margin: 10px 0 0;
  color: #526176;
  font-size: 13px;
  line-height: 1.6;
}
.greeting-meta {
  position: relative;
  z-index: 1;
  min-width: 176px;
  padding: 15px 17px;
  border: 1px solid rgba(255, 255, 255, .8);
  border-radius: 11px;
  background: rgba(255, 255, 255, .76);
  text-align: left;
}
.greeting-meta strong {
  display: block;
  color: var(--ink);
  font-size: 12px;
}
.greeting-meta span {
  display: block;
  margin-top: 4px;
  color: var(--copy);
  font-size: 11px;
}
.quick-links {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.quick-link {
  display: inline-flex;
  min-height: 46px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--accent);
  border-radius: 9px;
  padding: 0 14px;
  background: var(--accent);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
  text-decoration: none;
  transition: transform .18s ease, border-color .18s ease, color .18s ease, background .18s ease, box-shadow .18s ease;
  box-shadow: 0 5px 14px color-mix(in srgb, var(--accent) 18%, transparent);
}
.quick-link:hover {
  transform: translateY(-1px);
  border-color: var(--accent-dark);
  background: var(--accent-dark);
  color: #fff;
}
.quick-link:active { transform: scale(.985); }
.module-shortcuts {
  display: flex;
  min-height: 34px;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin: 0 0 18px;
}
.module-shortcuts-label {
  margin-right: 3px;
  color: #718096;
  font-size: 9px;
  font-weight: 750;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.module-shortcuts a {
  display: inline-flex;
  min-height: 30px;
  align-items: center;
  padding: 4px 10px;
  border: 1px solid #e0e6ef;
  border-radius: 7px;
  background: rgba(255, 255, 255, .72);
  color: #4b5a70;
  font-size: 10px;
  font-weight: 650;
  text-decoration: none;
  transition: color .16s ease, border-color .16s ease, background .16s ease;
}
.module-shortcuts a:hover {
  border-color: color-mix(in srgb, var(--accent) 28%, white);
  background: #fff;
  color: var(--accent);
}
.module-shortcuts a:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 24%, transparent);
  outline-offset: 2px;
}
.dashboard-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr);
  gap: 16px;
  margin-bottom: 16px;
}
.dashboard-grid--records { grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr); }
.dashboard-grid--updates { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.hub-panel {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 5px 18px rgba(35, 54, 80, .035);
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 15px 18px;
  border-bottom: 1px solid #e9edf3;
}
.panel-head h2 { margin: 0; font-size: 15px; font-weight: 750; letter-spacing: -.015em; }
.panel-head p { margin: 4px 0 0; color: var(--copy); font-size: 11px; line-height: 1.45; }
.panel-link { color: var(--accent); font-size: 11px; font-weight: 750; text-decoration: none; white-space: nowrap; }
.panel-link:hover { color: var(--accent-dark); text-decoration: underline; text-underline-offset: 3px; }
.count-strip { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-bottom: 1px solid #edf1f5; background: #fafbfd; }
.count-item { padding: 12px 9px; text-align: center; }
.count-item + .count-item { border-left: 1px solid #e9edf3; }
.count-item strong { display: block; color: #152239; font-size: 19px; font-variant-numeric: tabular-nums; }
.count-item span { display: block; margin-top: 4px; color: var(--copy); font-size: 9px; font-weight: 750; line-height: 1.25; text-transform: uppercase; }
.record-list, .activity-list, .notification-list { margin: 0; padding: 0; list-style: none; }
.record-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 14px 19px;
  border-bottom: 1px solid #edf1f5;
}
.record-item:last-child, .activity-item:last-child, .notification-item:last-child { border-bottom: 0; }
.record-title { display: block; overflow: hidden; color: var(--ink); font-size: 12px; font-weight: 700; line-height: 1.4; text-decoration: none; text-overflow: ellipsis; white-space: nowrap; }
.record-title:hover, .activity-title a:hover { color: var(--accent); }
.record-meta { display: block; margin-top: 4px; color: var(--copy); font-size: 10px; line-height: 1.45; }
.badge { display: inline-flex; border-radius: 7px; padding: 5px 8px; font-size: 9px; font-weight: 750; white-space: nowrap; }
.tone-warning { background: #fff4dc; color: #89530b; }
.tone-review { background: #eee9ff; color: #5428a5; }
.tone-info { background: #e4f4fa; color: #155e75; }
.tone-success { background: #e5f5ec; color: #23633d; }
.tone-danger { background: #fce9e7; color: #8f332e; }
.tone-neutral { background: #edf1f5; color: #475569; }
.activity-item, .notification-item { display: grid; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #edf1f5; }
.activity-item { grid-template-columns: 50px minmax(0, 1fr); }
.notification-item { grid-template-columns: 9px minmax(0, 1fr); }
.activity-date { display: grid; min-height: 50px; align-content: center; place-items: center; border-radius: 9px; background: var(--accent-soft); color: var(--accent); text-align: center; }
.activity-date strong { font-size: 17px; line-height: 1; }
.activity-date span { margin-top: 3px; font-size: 9px; font-weight: 750; text-transform: uppercase; }
.activity-title, .notification-title { margin: 0; color: var(--ink); font-size: 12px; font-weight: 700; line-height: 1.4; }
.activity-title a { color: inherit; text-decoration: none; }
.activity-meta, .notification-message, .notification-date { margin: 4px 0 0; color: var(--copy); font-size: 10px; line-height: 1.5; }
.notification-meta { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 10px; margin-top: 5px; }
.notification-date { margin: 0; }
.notification-details { margin-left: auto; font-size: 10px; }
.notification-details summary { color: var(--accent); cursor: pointer; font-weight: 750; list-style: none; }
.notification-details summary::-webkit-details-marker { display: none; }
.notification-details summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.notification-details[open] { flex-basis: 100%; margin-left: 0; }
.notification-dot { width: 8px; height: 8px; margin-top: 4px; border-radius: 50%; background: #94a3b8; }
.notification-dot.info { background: #2563eb; }
.notification-dot.success { background: #15803d; }
.notification-dot.warning { background: #b7791f; }
.notification-dot.error { background: #b42318; }
.empty-state { padding: 34px 20px; text-align: center; }
.empty-state strong { display: block; font-size: 14px; }
.empty-state p { max-width: 430px; margin: 7px auto 0; color: var(--copy); font-size: 11px; line-height: 1.55; }
.quick-link:focus-visible, .panel-link:focus-visible, .record-title:focus-visible, .activity-title a:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 24%, transparent);
  outline-offset: 3px;
}
@media (max-width: 1000px) {
  .dashboard-grid, .dashboard-grid--records, .dashboard-grid--updates { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 680px) {
  .module-greeting { grid-template-columns: 1fr; gap: 18px; padding: 23px 20px; }
  .module-greeting::after { right: -90px; }
  .greeting-meta { min-width: 0; }
  .quick-links { grid-template-columns: 1fr; gap: 8px; }
  .module-shortcuts { gap: 6px; }
  .module-shortcuts-label { flex-basis: 100%; }
  .dashboard-grid, .dashboard-grid--records, .dashboard-grid--updates { grid-template-columns: 1fr; }
  .count-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .count-item:nth-child(3) { border-top: 1px solid #e9edf3; border-left: 0; }
  .count-item:nth-child(4) { border-top: 1px solid #e9edf3; }
  .record-item { align-items: start; }
  .record-title { white-space: normal; }
}
@media (prefers-reduced-motion: reduce) {
  .quick-link { transition: none; }
}
</style>

<div class="module-hub role-<?= $role === 'faculty' ? 'faculty' : 'student' ?>">
  <header class="module-greeting"><div><p class="module-kicker">Your research workspace</p><h1><?= personalDashboardEscape($greeting) ?></h1><p>Manage title and chapter submissions, review feedback, publications, and campus research activities.</p></div><div class="greeting-meta"><strong><?= personalDashboardEscape($roleLabel) ?></strong><span><?= personalDashboardEscape(date('l, F j, Y')) ?></span></div></header>

  <nav class="quick-links" aria-label="Research workflow shortcuts">
    <a class="quick-link primary" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/my-research.php') ?>">My Research</a>
    <a class="quick-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/submit-research.php') ?>">Submit Research Title</a>
    <a class="quick-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/submit-chapter.php') ?>">Submit a Chapter</a>
  </nav>
  <div class="dashboard-grid">
    <section class="hub-panel" aria-labelledby="my-publications-heading"><div class="panel-head"><div><h2 id="my-publications-heading">My Publications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/publications.php') ?>">Open module</a></div>
      <div class="count-strip"><?php foreach ($publicationCounts as $status => $count): $badge = personalDashboardStatusBadge($status); ?><div class="count-item"><strong><?= (int) $count ?></strong><span><?= personalDashboardEscape($badge['label']) ?></span></div><?php endforeach; ?></div>
      <?php if (!$tables['publications'] || !$recentPublications): ?><div class="empty-state"><strong><?= $tables['publications'] ? 'No publications yet' : 'Publications unavailable' ?></strong><p><?= $tables['publications'] ? 'Recent publication submissions will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="record-list"><?php foreach ($recentPublications as $publication): $badge = personalDashboardStatusBadge((string) $publication['status']); ?><li class="record-item"><div><a class="record-title" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $publication['publication_id']) ?>"><?= personalDashboardEscape($publication['research_title']) ?></a><span class="record-meta"><?= personalDashboardEscape(ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?><?= $publication['publication_date'] ? ' &middot; ' . personalDashboardEscape(date('M j, Y', strtotime((string) $publication['publication_date']))) : '' ?></span></div><span class="badge tone-<?= personalDashboardEscape($badge['tone']) ?>"><?= personalDashboardEscape($badge['label']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="my-copyrights-heading"><div class="panel-head"><div><h2 id="my-copyrights-heading">My Copyright Applications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/copyrights.php') ?>">Open module</a></div>
      <div class="count-strip"><?php foreach ($copyrightCounts as $status => $count): $badge = personalDashboardStatusBadge($status); ?><div class="count-item"><strong><?= (int) $count ?></strong><span><?= personalDashboardEscape($badge['label']) ?></span></div><?php endforeach; ?></div>
      <?php if (!$tables['copyrights'] || !$recentCopyrights): ?><div class="empty-state"><strong><?= $tables['copyrights'] ? 'No copyright applications yet' : 'Copyrights unavailable' ?></strong><p><?= $tables['copyrights'] ? 'Recent copyright applications will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="record-list"><?php foreach ($recentCopyrights as $application): $badge = personalDashboardStatusBadge((string) $application['status']); ?><li class="record-item"><div><a class="record-title" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $application['copyright_id']) ?>"><?= personalDashboardEscape($application['output_title']) ?></a><span class="record-meta"><?= personalDashboardEscape(ucwords(str_replace('_', ' ', (string) $application['output_type']))) ?><?= $application['copyright_ref_no'] ? ' &middot; ' . personalDashboardEscape($application['copyright_ref_no']) : '' ?></span></div><span class="badge tone-<?= personalDashboardEscape($badge['tone']) ?>"><?= personalDashboardEscape($badge['label']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>

  <div class="dashboard-grid">
    <section class="hub-panel" aria-labelledby="upcoming-activities-heading"><div class="panel-head"><div><h2 id="upcoming-activities-heading">Upcoming Activities</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/activities.php') ?>">View all</a></div>
      <?php if (!$tables['activities'] || !$upcomingActivities): ?><div class="empty-state"><strong><?= $tables['activities'] ? 'No upcoming activities' : 'Activities unavailable' ?></strong><p><?= $tables['activities'] ? 'Scheduled seminars and research events will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="activity-list"><?php foreach ($upcomingActivities as $activity): $activityDate = new DateTimeImmutable((string) $activity['activity_date']); ?><li class="activity-item"><div class="activity-date" aria-hidden="true"><strong><?= personalDashboardEscape($activityDate->format('d')) ?></strong><span><?= personalDashboardEscape($activityDate->format('M')) ?></span></div><div><p class="activity-title"><a href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id']) ?>"><?= personalDashboardEscape($activity['title']) ?></a></p><p class="activity-meta"><?= personalDashboardEscape(ucfirst((string) $activity['activity_type'])) ?><?= $activity['activity_time'] ? ' &middot; ' . personalDashboardEscape(date('g:i a', strtotime((string) $activity['activity_time']))) : '' ?><?= $activity['venue'] ? ' &middot; ' . personalDashboardEscape($activity['venue']) : '' ?></p></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="recent-notifications-heading"><div class="panel-head"><div><h2 id="recent-notifications-heading">Recent Notifications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/notifications.php') ?>">View all</a></div>
      <?php if (!$tables['notifications'] || !$recentNotifications): ?><div class="empty-state"><strong>No notifications yet</strong><p>Submission and review updates will appear here.</p></div>
      <?php else: ?><ul class="notification-list"><?php foreach ($recentNotifications as $notification): $notificationType = in_array($notification['type'], ['info', 'success', 'warning', 'error'], true) ? $notification['type'] : 'info'; ?><li class="notification-item"><span class="notification-dot <?= personalDashboardEscape($notificationType) ?>" aria-hidden="true"></span><div><p class="notification-title"><?= personalDashboardEscape($notification['title']) ?></p><div class="notification-meta"><p class="notification-date"><?= personalDashboardEscape(date('M j, Y g:i a', strtotime((string) $notification['created_at']))) ?><?= (int) $notification['is_read'] === 0 ? ' &middot; Unread' : '' ?></p><details class="notification-details"><summary>Details</summary><p class="notification-message"><?= personalDashboardEscape($notification['message']) ?></p></details></div></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
</div>

<?php
if ($role === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
