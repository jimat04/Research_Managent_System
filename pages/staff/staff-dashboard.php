<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['research_staff', 'admin'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

function monitoringDashboardEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function monitoringDashboardTableExists(mysqli $connection, string $table): bool
{
    $allowedTables = [
        'research_activities',
        'activity_participants',
        'publications',
        'copyright_applications',
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

function monitoringDashboardStatusBadge(string $status): array
{
    return match ($status) {
        'scheduled', 'pending', 'submitted' => ['label' => ucfirst($status), 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'completed', 'published', 'registered' => ['label' => ucfirst($status), 'tone' => 'success'],
        'cancelled', 'rejected' => ['label' => ucfirst($status), 'tone' => 'danger'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'tone' => 'neutral'],
    };
}

$tables = [
    'activities' => monitoringDashboardTableExists($conn, 'research_activities'),
    'participants' => monitoringDashboardTableExists($conn, 'activity_participants'),
    'publications' => monitoringDashboardTableExists($conn, 'publications'),
    'copyrights' => monitoringDashboardTableExists($conn, 'copyright_applications'),
];

$researchers = ['total' => 0, 'faculty' => 0, 'student' => 0];
$activeStatus = 'active';
$facultyRole = 'faculty';
$studentRole = 'student';
$stmt = $conn->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(role = ?), 0) AS faculty, COALESCE(SUM(role = ?), 0) AS student FROM users WHERE status = ? AND role IN (?, ?)');
$stmt->bind_param('sssss', $facultyRole, $studentRole, $activeStatus, $facultyRole, $studentRole);
$stmt->execute();
$researcherRow = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
foreach (array_keys($researchers) as $key) $researchers[$key] = (int) ($researcherRow[$key] ?? 0);

$stats = [
    'seminars' => 0,
    'presentations' => 0,
    'published' => 0,
    'pending_publications' => 0,
    'copyright_applications' => 0,
    'registered_copyrights' => 0,
];
if ($tables['activities']) {
    $cancelled = 'cancelled';
    $seminar = 'seminar';
    $presentation = 'presentation';
    $stmt = $conn->prepare('SELECT COALESCE(SUM(activity_type = ? AND status <> ?), 0) AS seminars, COALESCE(SUM(activity_type = ? AND status <> ?), 0) AS presentations FROM research_activities');
    $stmt->bind_param('ssss', $seminar, $cancelled, $presentation, $cancelled);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['seminars'] = (int) ($row['seminars'] ?? 0);
    $stats['presentations'] = (int) ($row['presentations'] ?? 0);
}
if ($tables['publications']) {
    $published = 'published';
    $submitted = 'submitted';
    $underReview = 'under_review';
    $stmt = $conn->prepare('SELECT COALESCE(SUM(status = ?), 0) AS published, COALESCE(SUM(status IN (?, ?)), 0) AS pending_publications FROM publications');
    $stmt->bind_param('sss', $published, $submitted, $underReview);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['published'] = (int) ($row['published'] ?? 0);
    $stats['pending_publications'] = (int) ($row['pending_publications'] ?? 0);
}
if ($tables['copyrights']) {
    $pending = 'pending';
    $underReview = 'under_review';
    $registered = 'registered';
    $stmt = $conn->prepare('SELECT COALESCE(SUM(status IN (?, ?)), 0) AS copyright_applications, COALESCE(SUM(status = ?), 0) AS registered_copyrights FROM copyright_applications');
    $stmt->bind_param('sss', $pending, $underReview, $registered);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['copyright_applications'] = (int) ($row['copyright_applications'] ?? 0);
    $stats['registered_copyrights'] = (int) ($row['registered_copyrights'] ?? 0);
}

$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$monthlyActivity = [];
for ($index = 0; $index < 6; $index++) {
    $month = $monthStart->modify('+' . $index . ' months');
    $monthlyActivity[$month->format('Y-m')] = ['label' => $month->format('M Y'), 'count' => 0];
}
if ($tables['activities']) {
    $cancelled = 'cancelled';
    $startDate = $monthStart->format('Y-m-d');
    $endDate = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
    $stmt = $conn->prepare("SELECT DATE_FORMAT(activity_date, '%Y-%m') AS month_key, COUNT(*) AS total FROM research_activities WHERE status <> ? AND activity_date >= ? AND activity_date < ? GROUP BY DATE_FORMAT(activity_date, '%Y-%m') ORDER BY month_key");
    $stmt->bind_param('sss', $cancelled, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = (string) $row['month_key'];
        if (isset($monthlyActivity[$key])) $monthlyActivity[$key]['count'] = (int) $row['total'];
    }
    $stmt->close();
}
$monthlyMax = 0;
foreach ($monthlyActivity as $month) $monthlyMax = max($monthlyMax, $month['count']);

$eventParts = [];
if ($tables['activities']) {
    $eventParts[] = "SELECT ra.created_at AS event_at, CONCAT(UCASE(LEFT(ra.activity_type, 1)), SUBSTRING(ra.activity_type, 2), ' created') AS event_label, COALESCE(NULLIF(ra.organizer, ''), NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'Institutional activity') AS actor_name, ra.status, 'activity' AS source_type, ra.activity_id AS source_id FROM research_activities ra LEFT JOIN users u ON u.user_id = ra.created_by";
}
if ($tables['publications']) {
    $eventParts[] = "SELECT p.created_at AS event_at, 'Publication submitted' AS event_label, COALESCE(NULLIF(p.researcher_name, ''), 'Unnamed researcher') AS actor_name, p.status, 'publication' AS source_type, p.publication_id AS source_id FROM publications p";
    $eventParts[] = "SELECT p.updated_at AS event_at, 'Publication published' AS event_label, COALESCE(NULLIF(p.researcher_name, ''), 'Unnamed researcher') AS actor_name, 'published' AS status, 'publication' AS source_type, p.publication_id AS source_id FROM publications p WHERE p.status = 'published'";
}
if ($tables['copyrights']) {
    $eventParts[] = "SELECT c.created_at AS event_at, 'Copyright applied' AS event_label, COALESCE(NULLIF(c.applicant_name, ''), 'Unnamed applicant') AS actor_name, c.status, 'copyright' AS source_type, c.copyright_id AS source_id FROM copyright_applications c";
    $eventParts[] = "SELECT c.updated_at AS event_at, 'Copyright registered' AS event_label, COALESCE(NULLIF(c.applicant_name, ''), 'Unnamed applicant') AS actor_name, 'registered' AS status, 'copyright' AS source_type, c.copyright_id AS source_id FROM copyright_applications c WHERE c.status = 'registered'";
}
$recentEvents = [];
if ($eventParts) {
    $eventSql = 'SELECT event_at, event_label, actor_name, status, source_type, source_id FROM (' . implode(' UNION ALL ', $eventParts) . ') AS module_events ORDER BY event_at DESC LIMIT 6';
    $stmt = $conn->prepare($eventSql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentEvents[] = $row;
    $stmt->close();
}

$eventUrl = static function (array $event): string {
    $path = match ($event['source_type']) {
        'activity' => 'pages/shared/activity-detail.php?id=',
        'publication' => 'pages/shared/publication-detail.php?id=',
        'copyright' => 'pages/shared/copyright-detail.php?id=',
        default => 'pages/shared/activities.php?id=',
    };
    return SITE_URL . $path . (int) $event['source_id'];
};

$firstName = trim((string) ($user['first_name'] ?? '')) ?: ($role === 'admin' ? 'Administrator' : 'Research Staff');
$roleLabel = $role === 'admin' ? 'Administrator' : 'Research Staff';
$currentPage = $role === 'admin' ? 'admin-dashboard.php' : 'staff-dashboard.php';
$pageTitle = 'Welcome back, ' . $firstName;
$pageSubtitle = $role === 'admin' ? 'Institution-wide research monitoring overview' : 'Research activity, publication, and copyright monitoring';
if ($role === 'admin') renderAdminShell($user, $currentPage, $pageTitle, $pageSubtitle);
else renderStaffShell($user, $currentPage, $pageTitle, $pageSubtitle);
?>

<style>
.module-hub{--accent:#315b9f;--ink:#172033;--copy:#64748b;--line:#e2e8f0;max-width:1380px;margin:0 auto;color:var(--ink)}.module-greeting{display:flex;justify-content:space-between;gap:24px;align-items:end;margin:0 0 24px;padding:30px;border-left:5px solid var(--accent);border-radius:4px 18px 18px 4px;background:#eef3fb}.module-kicker{margin:0 0 7px;color:var(--accent);font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-greeting h1{margin:0;font-size:clamp(27px,3vw,40px);line-height:1.08;letter-spacing:-.04em;text-wrap:balance}.module-greeting p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.greeting-meta{text-align:right}.greeting-meta strong{display:block;font-size:13px}.greeting-meta span{display:block;margin-top:5px;color:var(--copy);font-size:11px}.stat-grid{display:grid;grid-template-columns:repeat(7,minmax(145px,1fr));gap:10px;margin-bottom:24px}.stat-card{min-width:0;padding:18px;border:1px solid #dfe5ee;border-radius:14px;background:#fff}.stat-card strong{display:block;font-size:28px;line-height:1;font-variant-numeric:tabular-nums}.stat-card>span{display:block;margin-top:8px;color:#475569;font-size:11px;font-weight:800;line-height:1.35}.stat-card small{display:block;margin-top:8px;color:#7b8798;font-size:10px;line-height:1.45}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(360px,.8fr);gap:18px;margin-bottom:18px}.hub-panel{min-width:0;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px;letter-spacing:-.015em}.panel-head p{margin:5px 0 0;color:var(--copy);font-size:12px;line-height:1.45}.bar-chart{display:grid;gap:15px;padding:22px}.bar-row{display:grid;grid-template-columns:72px minmax(0,1fr) 28px;gap:12px;align-items:center}.bar-label,.bar-value{font-size:11px;font-weight:750}.bar-label{color:#526176}.bar-value{text-align:right;font-variant-numeric:tabular-nums}.bar-track{height:11px;overflow:hidden;border-radius:3px;background:#edf1f6}.bar-fill{height:100%;min-width:0;border-radius:3px;background:#315b9f}.event-wrap{overflow-x:auto}.event-table{width:100%;min-width:720px;border-collapse:collapse}.event-table th{padding:11px 15px;background:#f8fafc;color:var(--copy);font-size:10px;letter-spacing:.06em;text-align:left;text-transform:uppercase}.event-table td{padding:14px 15px;border-top:1px solid #edf1f5;font-size:12px;vertical-align:middle}.event-name{color:var(--ink);font-weight:750;text-decoration:none}.event-name:hover{color:var(--accent)}.event-date{white-space:nowrap;color:#526176;font-variant-numeric:tabular-nums}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.quick-actions{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:20px}.action-link{display:flex;align-items:center;justify-content:center;min-height:46px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font-size:12px;font-weight:800;text-align:center;text-decoration:none;transition:transform .18s ease,border-color .18s ease,color .18s ease,background .18s ease}.action-link.primary{border-color:var(--accent);background:var(--accent);color:#fff}.action-link:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px)}.action-link.primary:hover{background:#24477e;color:#fff}.action-link:active{transform:scale(.98)}.action-link:focus-visible,.event-name:focus-visible{outline:3px solid rgba(49,91,159,.15);outline-offset:3px}.empty-state{padding:38px 22px;text-align:center;color:var(--copy);font-size:13px;line-height:1.55}@media(max-width:1180px){.stat-grid{grid-template-columns:repeat(4,1fr)}.dashboard-grid{grid-template-columns:1fr}.quick-actions{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.module-greeting{display:block;padding:24px 21px}.greeting-meta{margin-top:18px;text-align:left}.stat-grid{grid-template-columns:repeat(2,1fr)}.quick-actions{grid-template-columns:1fr}.bar-row{grid-template-columns:64px minmax(0,1fr) 24px}}
</style>

<div class="module-hub">
  <header class="module-greeting"><div><p class="module-kicker">Monitoring overview</p><h1>Welcome back, <?= monitoringDashboardEscape($firstName) ?></h1><p>Track institutional research activities, publication progress, and copyright processing from one view.</p></div><div class="greeting-meta"><strong><?= monitoringDashboardEscape($roleLabel) ?></strong><span><?= monitoringDashboardEscape(date('l, F j, Y')) ?></span></div></header>

  <section class="stat-grid" aria-label="Research module statistics">
    <article class="stat-card"><strong><?= $researchers['total'] ?></strong><span>Active Researchers</span><small><?= $researchers['faculty'] ?> faculty &middot; <?= $researchers['student'] ?> students</small></article>
    <article class="stat-card"><strong><?= $stats['seminars'] ?></strong><span>Seminars</span><small>Non-cancelled activities</small></article>
    <article class="stat-card"><strong><?= $stats['presentations'] ?></strong><span>Presentations</span><small>Non-cancelled activities</small></article>
    <article class="stat-card"><strong><?= $stats['published'] ?></strong><span>Publications</span><small>Published outputs</small></article>
    <article class="stat-card"><strong><?= $stats['pending_publications'] ?></strong><span>Pending Publications</span><small>Submitted or under review</small></article>
    <article class="stat-card"><strong><?= $stats['copyright_applications'] ?></strong><span>Copyright Applications</span><small>Pending or under review</small></article>
    <article class="stat-card"><strong><?= $stats['registered_copyrights'] ?></strong><span>Registered Copyrights</span><small>Completed registrations</small></article>
  </section>

  <div class="dashboard-grid">
    <section class="hub-panel" aria-labelledby="activity-chart-heading"><div class="panel-head"><div><h2 id="activity-chart-heading">Research Activity by Month</h2><p>Non-cancelled activities during the last six calendar months.</p></div></div>
      <?php if ($monthlyMax === 0): ?><div class="empty-state">No research activities were recorded during this six-month window.</div>
      <?php else: ?><div class="bar-chart"><?php foreach ($monthlyActivity as $month): $width = $monthlyMax > 0 ? ($month['count'] / $monthlyMax) * 100 : 0; ?><div class="bar-row"><span class="bar-label"><?= monitoringDashboardEscape($month['label']) ?></span><div class="bar-track" aria-label="<?= monitoringDashboardEscape($month['label'] . ': ' . $month['count']) ?>"><div class="bar-fill" style="width:<?= monitoringDashboardEscape(number_format($width, 2, '.', '')) ?>%"></div></div><span class="bar-value"><?= (int) $month['count'] ?></span></div><?php endforeach; ?></div><?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="quick-actions-heading"><div class="panel-head"><div><h2 id="quick-actions-heading">Quick Actions</h2><p>Open the module forms and institutional repository.</p></div></div><div class="quick-actions">
      <a class="action-link primary" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/shared/activity-form.php') ?>">+ Add Activity</a>
      <a class="action-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/shared/publication-form.php') ?>">+ Add Publication</a>
      <a class="action-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/shared/copyright-form.php') ?>">+ Copyright Application</a>
      <a class="action-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">Open Repository</a>
      <?php if ($role === 'admin'): ?><a class="action-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/admin/admin-reports.php') ?>">Reports</a><?php endif; ?>
    </div></section>
  </div>

  <section class="hub-panel" aria-labelledby="recent-events-heading"><div class="panel-head"><div><h2 id="recent-events-heading">Recent Research Activities</h2><p>The six newest events across activities, publications, and copyrights.</p></div></div>
    <?php if (!$recentEvents): ?><div class="empty-state">Module activity will appear here after the first activity, publication, or copyright application is recorded.</div>
    <?php else: ?><div class="event-wrap"><table class="event-table"><thead><tr><th>Date</th><th>What happened</th><th>Actor</th><th>Status</th></tr></thead><tbody><?php foreach ($recentEvents as $event): $badge = monitoringDashboardStatusBadge((string) $event['status']); ?><tr><td class="event-date"><?= monitoringDashboardEscape(date('M j, Y g:i a', strtotime((string) $event['event_at']))) ?></td><td><a class="event-name" href="<?= monitoringDashboardEscape($eventUrl($event)) ?>"><?= monitoringDashboardEscape($event['event_label']) ?></a></td><td><?= monitoringDashboardEscape($event['actor_name']) ?></td><td><span class="badge tone-<?= monitoringDashboardEscape($badge['tone']) ?>"><?= monitoringDashboardEscape($badge['label']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</div>

<?php
if ($role === 'admin') renderAdminShellClose();
else renderStaffShellClose();
?>
