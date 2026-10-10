<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get report statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL")->fetch_assoc()['count'] ?? 0);
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE deleted_at IS NULL")->fetch_assoc()['count'] ?? 0);
$completed_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE deleted_at IS NULL AND status = 'completed'")->fetch_assoc()['count'] ?? 0);
$archived_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE deleted_at IS NULL AND status = 'archived'")->fetch_assoc()['count'] ?? 0);

// Get monthly research submissions (last 6 months)
$monthly_submissions = [];
$monthly_count_stmt = $conn->prepare('SELECT COUNT(*) AS count FROM research_projects WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?');
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-{$i} months"));
  $month_end = date('Y-m-01', strtotime($month_start . ' +1 month'));
    $month_label = date('M Y', strtotime("-{$i} months"));

  $monthly_count_stmt->bind_param('ss', $month_start, $month_end);
  $monthly_count_stmt->execute();
  $count = (int) ($monthly_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $monthly_submissions[] = [
        'month' => $month_label,
        'count' => $count
    ];
}
$monthly_count_stmt->close();

// Get research by department
$dept_query = $conn->query("
    SELECT u.department, COUNT(*) as count
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE rp.deleted_at IS NULL AND u.department IS NOT NULL AND u.department != ''
    GROUP BY u.department
    ORDER BY count DESC
    LIMIT 10
");
$research_by_dept = [];
while ($row = $dept_query->fetch_assoc()) {
    $research_by_dept[] = $row;
}

// Get research by status
$status_query = $conn->query("
    SELECT status, COUNT(*) as count
    FROM research_projects
    WHERE deleted_at IS NULL
    GROUP BY status
    ORDER BY count DESC
");
$research_by_status = [];
while ($row = $status_query->fetch_assoc()) {
    $research_by_status[] = $row;
}

$active_research = 0;
foreach ($research_by_status as $status_row) {
  if (!in_array($status_row['status'], ['completed', 'archived', 'rejected'], true)) {
    $active_research += (int) $status_row['count'];
  }
}
$completion_total = $completed_research + $archived_research;
$completion_rate = $total_research > 0 ? round(($completion_total / $total_research) * 100, 1) : 0;
$current_month_count = $monthly_submissions ? (int) $monthly_submissions[count($monthly_submissions) - 1]['count'] : 0;
$max_month_count = $monthly_submissions ? max(array_column($monthly_submissions, 'count')) : 0;
$max_status_count = $research_by_status ? max(array_column($research_by_status, 'count')) : 1;
$max_dept_count = $research_by_dept ? max(array_column($research_by_dept, 'count')) : 1;

$status_labels = [
    'draft' => 'Draft',
    'proposal' => 'Proposal',
  'submitted' => 'Submitted',
  'under_review' => 'Under Review',
    'under_crec_review' => 'Committee Review (legacy)',
    'under_erec_review' => 'Ethics Review (legacy)',
  'for_revision' => 'For Revision',
  'revision_required' => 'Revision Required',
  'rejected' => 'Rejected',
  'title_approved' => 'Title Approved',
    'approved' => 'Approved',
    'ongoing' => 'Ongoing',
  'progress_report' => 'Progress Report',
  'terminal_review' => 'Terminal Review',
    'completed' => 'Completed',
    'archived' => 'Archived',
];

$moduleTables = [];
foreach (['research_activities', 'activity_participants', 'publications', 'copyright_applications'] as $tableName) {
    $tableCheck = $conn->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $tableCheck->bind_param('s', $tableName);
    $tableCheck->execute();
    $moduleTables[$tableName] = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $tableCheck->close();
}

$activityReport = ['seminars' => 0, 'presentations' => 0, 'workshops' => 0, 'scheduled' => 0, 'completed' => 0, 'participants' => 0, 'attended' => 0];
if ($moduleTables['research_activities']) {
    $activityStmt = $conn->prepare("SELECT SUM(activity_type = 'seminar' AND status <> 'cancelled') AS seminars, SUM(activity_type = 'presentation' AND status <> 'cancelled') AS presentations, SUM(activity_type IN ('workshop', 'forum') AND status <> 'cancelled') AS workshops, SUM(status = 'scheduled') AS scheduled, SUM(status = 'completed') AS completed FROM research_activities");
    $activityStmt->execute();
    $activityRow = $activityStmt->get_result()->fetch_assoc() ?: [];
    foreach (['seminars', 'presentations', 'workshops', 'scheduled', 'completed'] as $key) {
        $activityReport[$key] = (int) ($activityRow[$key] ?? 0);
    }
    $activityStmt->close();
}
if ($moduleTables['research_activities'] && $moduleTables['activity_participants']) {
    $attendanceStmt = $conn->prepare('SELECT COUNT(*) AS participants, COALESCE(SUM(attended = 1), 0) AS attended FROM activity_participants');
    $attendanceStmt->execute();
    $attendanceRow = $attendanceStmt->get_result()->fetch_assoc() ?: [];
    $activityReport['participants'] = (int) ($attendanceRow['participants'] ?? 0);
    $activityReport['attended'] = (int) ($attendanceRow['attended'] ?? 0);
    $attendanceStmt->close();
}

$publicationStages = ['submitted' => 0, 'under_review' => 0, 'accepted' => 0, 'published' => 0];
$publicationTypes = [];
if ($moduleTables['publications']) {
    $publicationStmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM publications GROUP BY status');
    $publicationStmt->execute();
    $result = $publicationStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['status'], $publicationStages)) $publicationStages[$row['status']] = (int) $row['total'];
    }
    $publicationStmt->close();
    $publicationStmt = $conn->prepare('SELECT publication_type, COUNT(*) AS total FROM publications GROUP BY publication_type ORDER BY total DESC, publication_type ASC');
    $publicationStmt->execute();
    $result = $publicationStmt->get_result();
    while ($row = $result->fetch_assoc()) $publicationTypes[] = $row;
    $publicationStmt->close();
}

$copyrightStages = ['pending' => 0, 'under_review' => 0, 'registered' => 0, 'rejected' => 0];
$copyrightTypes = [];
$registeredCopyrightsThisYear = 0;
if ($moduleTables['copyright_applications']) {
    $copyrightStmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM copyright_applications GROUP BY status');
    $copyrightStmt->execute();
    $result = $copyrightStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['status'], $copyrightStages)) $copyrightStages[$row['status']] = (int) $row['total'];
    }
    $copyrightStmt->close();
    $copyrightStmt = $conn->prepare('SELECT output_type, COUNT(*) AS total FROM copyright_applications GROUP BY output_type ORDER BY total DESC, output_type ASC');
    $copyrightStmt->execute();
    $result = $copyrightStmt->get_result();
    while ($row = $result->fetch_assoc()) $copyrightTypes[] = $row;
    $copyrightStmt->close();
    $copyrightStmt = $conn->prepare("SELECT COUNT(*) AS total FROM copyright_applications WHERE status = 'registered' AND registered_at >= ?");
    $registrationYearStart = date('Y') . '-01-01 00:00:00';
    $copyrightStmt->bind_param('s', $registrationYearStart);
    $copyrightStmt->execute();
    $registeredCopyrightsThisYear = (int) ($copyrightStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $copyrightStmt->close();
}

renderAdminShell(
    $user,
    'admin-reports',
    'Reports & Analytics',
    'Operational reporting across the institute research workflow.'
);

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  .reports-workspace{--report-ink:#192235;--report-gold:#d2a248;--report-line:#dfe5ed;--report-muted:#687386;max-width:1480px;margin:0 auto;color:var(--report-ink)}
  .reports-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:46px;min-height:335px;padding:52px 56px 62px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 84% 14%,rgba(210,162,72,.2),transparent 29%),linear-gradient(135deg,#172033,#202d45 64%,#27344b);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.reports-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.16;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.reports-kicker,.hero-stat-code,.report-eyebrow,.metric-index{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.reports-kicker{margin-bottom:18px;color:#e9bf6e}.reports-hero h2{max-width:780px;margin:0;font-size:clamp(40px,4.6vw,66px);line-height:.98;letter-spacing:-.055em;text-wrap:balance}.reports-hero-copy>p{max-width:620px;margin:24px 0 0;color:#bdc8d8;font-size:15px;line-height:1.74;text-wrap:pretty}.reports-note{display:inline-flex;align-items:center;gap:9px;margin-top:24px;color:#aeb9ca;font-size:12px}.reports-note::before{content:'';width:7px;height:7px;border-radius:50%;background:#70bd91;box-shadow:0 0 0 5px rgba(112,189,145,.11)}
  .hero-stats{align-self:end;display:grid;gap:2px}.hero-stat{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.hero-stat:first-child{border-radius:14px 14px 5px 5px}.hero-stat:last-child{border-radius:5px 5px 14px 14px}.hero-stat-code{color:#e9bf6e}.hero-stat-label{color:#d4dce8;font-size:13px}.hero-stat-value{font-size:24px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
  .report-metrics{position:relative;z-index:2;display:grid;grid-template-columns:1.18fr repeat(3,1fr);gap:2px;margin:-22px 22px 0}.report-metric{position:relative;min-height:140px;padding:23px 24px 21px;overflow:hidden;border:1px solid #e2e7ee;background:#fff}.report-metric:first-child{border-radius:16px 5px 5px 16px}.report-metric:last-child{border-radius:5px 16px 16px 5px}.report-metric::after{content:'';position:absolute;right:-20px;bottom:-32px;width:76px;height:76px;border:17px solid var(--metric-accent,#64748b);border-radius:50%;opacity:.075}.metric-users{--metric-accent:#315b8c}.metric-portfolio{--metric-accent:#8b6528;background:#fcfaf5}.metric-completed{--metric-accent:#347451}.metric-archive{--metric-accent:#705487}.metric-index{margin-bottom:23px;color:var(--metric-accent)}.metric-value{font-size:34px;font-weight:720;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums}.metric-label{margin-top:8px;color:var(--report-muted);font-size:13px;font-weight:620}
  .analytics-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:24px;margin-top:38px}.report-panel{grid-column:span 6;overflow:hidden;border:1px solid var(--report-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.065)}.report-panel.panel-wide{grid-column:span 12}.report-panel-header{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:28px 30px 22px;border-bottom:1px solid #e8ecf1}.report-eyebrow{margin-bottom:8px;color:#987027}.report-title{margin:0;color:#1c2639;font-size:24px;line-height:1.1;letter-spacing:-.03em}.report-copy{max-width:590px;margin:8px 0 0;color:var(--report-muted);font-size:13px;line-height:1.55}.report-tag{color:#8a95a5;font:650 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.report-chart{padding:25px 30px 30px}.report-bar{display:grid;grid-template-columns:minmax(110px,1fr) minmax(180px,2.4fr) 64px;align-items:center;gap:15px;padding:9px 0}.report-bar+.report-bar{border-top:1px solid #f0f2f5}.report-bar-label{min-width:0;color:#3c475b;font-size:12px;font-weight:650;overflow-wrap:anywhere}.report-bar-track{height:10px;overflow:hidden;border-radius:3px;background:#edf0f4}.report-bar-fill{width:var(--bar-size,0%);height:100%;border-radius:3px;background:#d2a248;transform-origin:left;animation:bar-grow .6s cubic-bezier(.2,.8,.2,1) both}.panel-status .report-bar-fill{background:#435b7a}.panel-department .report-bar-fill{background:#92703a}.report-bar-value{color:#727d8f;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;text-align:right;white-space:nowrap}.report-empty{padding:55px 24px;text-align:center}.report-empty-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#876225;font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.report-empty strong{display:block;color:#344054;font-size:16px}.report-empty p{margin:7px 0 0;color:#8490a1;font-size:13px}@keyframes bar-grow{from{transform:scaleX(0)}to{transform:scaleX(1)}}
  @media(max-width:1100px){.reports-hero{grid-template-columns:1fr;gap:30px}.hero-stats{grid-template-columns:repeat(3,1fr)}.hero-stat{grid-template-columns:32px 1fr}.hero-stat-value{grid-column:2}.report-panel{grid-column:span 12}}
  @media(max-width:760px){.reports-hero{min-height:0;padding:31px 24px 50px;border-radius:18px 18px 7px 7px}.reports-hero h2{font-size:40px}.hero-stats{grid-template-columns:1fr}.hero-stat{grid-template-columns:34px 1fr auto}.hero-stat-value{grid-column:auto}.report-metrics{grid-template-columns:repeat(4,minmax(150px,1fr));margin:-18px 12px 0;overflow-x:auto}.report-metric{min-width:150px}.analytics-grid{margin-top:28px}.report-panel-header{align-items:flex-start;flex-direction:column;padding:24px 20px 20px}.report-chart{padding:18px 20px 23px}.report-bar{grid-template-columns:minmax(92px,1fr) minmax(90px,1.5fr) 52px;gap:10px}.report-tag{white-space:normal}}
  @media(max-width:480px){.report-bar{grid-template-columns:1fr 54px}.report-bar-track{grid-column:1/-1;grid-row:2}.report-bar-value{grid-column:2;grid-row:1}}
  @media(prefers-reduced-motion:reduce){.report-bar-fill{animation:none}}
</style>
<style>
  .module-reports{margin-top:38px}
  .module-reports-heading{margin:0 0 16px;padding-inline:20px}
  .module-reports-heading h2{margin:0;color:#1c2639;font-size:26px;letter-spacing:-.03em}
  .module-reports-heading p{margin:7px 0 0;color:#687386;font-size:13px;line-height:1.55}
  .module-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
  .module-report-grid[data-report-count="1"]{grid-template-columns:1fr}
  .module-report-grid[data-report-count="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}
  .module-report-grid>.panel-implementation{grid-column:1/-1;width:100%}
  .module-report-card{overflow:hidden;border:1px solid #dfe5ed;border-radius:16px;background:#fff;box-shadow:0 10px 28px rgba(31,42,63,.045)}
  .module-report-card header{padding:19px 20px;border-bottom:1px solid #e8ecf1}
  .module-report-card h3{margin:0;color:#1c2639;font-size:16px}
  .module-report-card header p{max-width:42ch;margin:7px 0 0;color:#5d6b81;font-size:13px;line-height:1.55}
  .module-report-list{display:grid;gap:0;padding:8px 20px}
  .module-report-row{display:flex;justify-content:space-between;gap:15px;padding:11px 0;border-bottom:1px solid #e9edf2;color:#435166;font-size:13px;line-height:1.45}
  .module-report-row:last-child{border-bottom:0}
  .module-report-row strong{color:#192235;font-size:13px;font-variant-numeric:tabular-nums}
  .module-report-card footer{padding:0 20px 18px}
  .module-report-card footer a{color:#9a4d00;font-size:12px;font-weight:700;text-decoration:none}
  .module-report-card footer a:hover{text-decoration:underline;text-underline-offset:3px}
  .module-report-empty{padding:18px 20px;color:#5d6b81;font-size:13px;line-height:1.55}
  .report-controls{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px 20px;margin:26px 0 0;padding:20px 22px;border:1px solid #dfe5ed;border-radius:12px;background:#fff;box-shadow:0 4px 14px rgba(31,42,63,.035)}
  .report-controls-copy{grid-column:1;grid-row:1}
  .report-controls-copy strong{display:block;color:#192235;font-size:16px;line-height:1.3}
  .report-controls-copy span{display:block;max-width:58ch;margin-top:5px;color:#5d6b81;font-size:13px;line-height:1.5}
  .report-controls-actions{display:contents}
  .report-print-heading{display:none}
  .report-selector{grid-column:1/-1;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;padding:0;border:0;background:transparent}
  .report-choice{display:flex;align-items:flex-start;gap:9px;min-height:48px;padding:11px 10px;border:1px solid #e3e7ed;border-radius:8px;background:#f8fafc;color:#405069;font-size:12px;font-weight:600;line-height:1.35;cursor:pointer;transition:background-color .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease}
  .report-choice:has(input:checked){border-color:#f57c00;background:#fff8f0;color:#713900;box-shadow:inset 3px 0 #f57c00}
  .report-choice input{flex:0 0 16px;width:16px;height:16px;margin:1px 0 0;accent-color:#f57c00}
  .report-choice:focus-within,.report-print:focus-visible{outline:2px solid #f57c00;outline-offset:2px}
  .report-print{grid-column:2;grid-row:1;justify-self:end;min-height:44px;padding:10px 16px;border:1px solid #f57c00;border-radius:8px;background:#f57c00;color:#fff;font-family:inherit;font-size:13px;font-weight:700;line-height:1.2;white-space:nowrap;cursor:pointer;transition:background-color .16s ease,border-color .16s ease,transform .16s ease}
  .report-choice:hover{border-color:#f57c00;color:#713900}
  .report-print:hover{border-color:#ea580c;background:#ea580c}
  .report-print:active{transform:translateY(1px)}
  .report-print:disabled{border-color:#aeb7c3;background:#aeb7c3;cursor:not-allowed;transform:none}
  .report-page[hidden]{display:none!important}
  .report-page-heading{display:none}
  @media(max-width:1000px){.module-report-grid{grid-template-columns:1fr 1fr}.report-selector{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:900px){.report-controls{display:flex;align-items:stretch;flex-direction:column}.report-controls-actions{display:flex;width:100%;align-items:stretch;flex-direction:column;gap:12px}.report-selector{width:100%;grid-template-columns:repeat(2,minmax(0,1fr))}.report-print{align-self:flex-start}}
  @media(max-width:680px){.module-reports .module-report-grid{grid-template-columns:1fr}.module-reports-heading h2{font-size:22px}.report-selector{grid-template-columns:1fr}.report-choice{min-height:42px}}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .reports-hero{color:#192235!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .reports-hero h2{color:#192235!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .reports-hero-copy>p{color:#5d6b81!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .hero-stat{border-color:#dfe5ed!important;background:#f7f9fd!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .hero-stat-code{color:#f57c00!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .hero-stat-label{color:#526074!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .hero-stat-value{color:#192235!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .reports-note{color:#526074!important}
  body.admin-body .admin-dashboard .admin-page-content .reports-workspace .reports-hero{border-left-color:#f57c00!important}
  .report-eyebrow{color:#a64b00}
  .report-bar-label{font-size:13px}
  .report-bar-value{font-size:12px}
  .report-bar-track{height:12px;border-radius:6px}
  .report-bar-fill{background:#f57c00}
  .panel-status .report-bar-fill{background:#37618f}
  .panel-department .report-bar-fill{background:#bd5e08}
  @media print{
    body.admin-body,.admin-page-content{background:#fff!important}
    .admin-sidebar,.admin-topbar,.admin-skip-link,.admin-sidebar-scrim,.report-controls,.reports-hero,.report-metrics,.module-reports-heading,.module-report-card footer{display:none!important}
    .admin-main,.admin-dashboard.is-collapsed .admin-main{margin:0!important}
    .admin-page-content{min-height:0!important;padding:0!important}
    .reports-workspace{max-width:none!important}
    .reports-workspace{padding:0!important;border:0!important;box-shadow:none!important}
    .report-page:not([data-report-print-active]){display:none!important}
    .report-page[data-report-print-active]{display:block!important}
    .report-page[data-report-print-active]:not([data-report-print-first]){break-before:page;page-break-before:always}
    .report-page[data-report-print-active]:not([data-report-print-last]){break-after:page;page-break-after:always}
    .report-page-heading{display:block;margin:0 0 12px;color:#192235;font-size:20px;font-weight:700}
    .report-metric,.report-panel,.module-report-card{break-inside:avoid;box-shadow:none!important}
    .analytics-grid{display:block!important;margin:0!important;padding:0!important}
    .report-panel{width:100%;margin:0 0 12px}
    .module-reports{margin:0!important}
    .module-report-grid{display:block!important}
    .module-report-card{width:100%;margin:0!important}
    a{color:inherit!important;text-decoration:none!important}
  }
</style>
<div class="reports-workspace">
  <section class="reports-hero" aria-labelledby="reports-hero-title">
    <div class="reports-hero-copy">
      <div class="reports-kicker">Administration &middot; Research workflow</div>
      <h2 id="reports-hero-title">Track research from proposal to impact.</h2>
      <p>Review live indicators across research development, capacity building, implementation, dissemination, and intellectual property.</p>
      <div class="reports-note">Live figures generated from the current RMS database.</div>
    </div>
    <div class="hero-stats" aria-label="Portfolio indicators">
      <div class="hero-stat"><span class="hero-stat-code">01</span><span class="hero-stat-label">Submitted this month</span><strong class="hero-stat-value"><?php echo (int) $current_month_count; ?></strong></div>
      <div class="hero-stat"><span class="hero-stat-code">02</span><span class="hero-stat-label">Active portfolio</span><strong class="hero-stat-value"><?php echo (int) $active_research; ?></strong></div>
      <div class="hero-stat"><span class="hero-stat-code">03</span><span class="hero-stat-label">Completion coverage</span><strong class="hero-stat-value"><?php echo htmlspecialchars(number_format($completion_rate, 1), ENT_QUOTES, 'UTF-8'); ?>%</strong></div>
    </div>
  </section>

  <section class="report-metrics" aria-label="System totals">
    <article class="report-metric metric-users"><div class="metric-index">People</div><div class="metric-value"><?php echo (int) $total_users; ?></div><div class="metric-label">Registered users</div></article>
    <article class="report-metric metric-portfolio"><div class="metric-index">Portfolio</div><div class="metric-value"><?php echo (int) $total_research; ?></div><div class="metric-label">Research projects</div></article>
    <article class="report-metric metric-completed"><div class="metric-index">Outcomes</div><div class="metric-value"><?php echo (int) $completed_research; ?></div><div class="metric-label">Completed projects</div></article>
    <article class="report-metric metric-archive"><div class="metric-index">Repository</div><div class="metric-value"><?php echo (int) $archived_research; ?></div><div class="metric-label">Archived projects</div></article>
  </section>

  <section class="report-controls" aria-label="Report selection and printing">
    <div class="report-controls-copy"><strong id="selected-report-title" aria-live="polite">1 report selected</strong><span id="selected-report-description">Choose one or more workflow reports. Each selected report prints on a separate page.</span></div>
    <div class="report-controls-actions">
      <div class="report-selector" role="group" aria-label="Select report pages">
        <label class="report-choice"><input type="checkbox" data-report-choice="development" value="development" checked><span>Research Development</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="capacity" value="capacity"><span>Capacity Building</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="implementation" value="implementation"><span>Implementation &amp; Outputs</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="dissemination" value="dissemination"><span>Publication &amp; Dissemination</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="ip" value="ip"><span>IP &amp; Utilization</span></label>
      </div>
      <button class="report-print" type="button" id="print-report">Print selected report (1)</button>
    </div>
  </section>

  <section class="report-page analytics-grid" data-report-page="development" data-report-title="Research Development">
    <h2 class="report-page-heading">Research Development</h2>
    <section class="report-panel panel-trend" aria-labelledby="submission-trend-title">
      <header class="report-panel-header">
        <div><div class="report-eyebrow">Submission pulse</div><h3 class="report-title" id="submission-trend-title">Six-month intake</h3><p class="report-copy">Monthly project creation reveals the current pace of institute research activity.</p></div>
        <span class="report-tag">6 MONTHS</span>
      </header>
      <div class="report-chart">
        <?php foreach ($monthly_submissions as $data):
          $width = $max_month_count > 0 ? ((int) $data['count'] / $max_month_count) * 100 : 0;
        ?>
          <div class="report-bar">
            <span class="report-bar-label"><?php echo htmlspecialchars($data['month'], ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="report-bar-track"><div class="report-bar-fill" style="--bar-size:<?php echo htmlspecialchars(number_format($width, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>%"></div></div>
            <span class="report-bar-value"><?php echo (int) $data['count']; ?> proj.</span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="report-panel panel-status" aria-labelledby="status-distribution-title">
      <header class="report-panel-header">
        <div><div class="report-eyebrow">Lifecycle</div><h3 class="report-title" id="status-distribution-title">Portfolio by status</h3><p class="report-copy">A snapshot of where every project currently sits in the research process.</p></div>
        <span class="report-tag"><?php echo (int) count($research_by_status); ?> STAGES</span>
      </header>
      <?php if (empty($research_by_status)): ?>
        <div class="report-empty"><div class="report-empty-mark">00</div><strong>No status data</strong><p>Project activity will appear here.</p></div>
      <?php else: ?>
        <div class="report-chart">
          <?php foreach ($research_by_status as $data):
            $width = ((int) $data['count'] / $max_status_count) * 100;
            $status_label = $status_labels[$data['status']] ?? ucfirst(str_replace('_', ' ', (string) $data['status']));
          ?>
            <div class="report-bar">
              <span class="report-bar-label"><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></span>
              <div class="report-bar-track"><div class="report-bar-fill" style="--bar-size:<?php echo htmlspecialchars(number_format($width, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>%"></div></div>
              <span class="report-bar-value"><?php echo (int) $data['count']; ?> proj.</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </section>

  <section class="module-reports" id="module-portfolio" aria-labelledby="module-reports-heading">
    <header class="module-reports-heading"><h2 id="module-reports-heading">Operational workflow indicators</h2><p>Current activity and output records from the corresponding RMS modules.</p></header>
    <div class="module-report-grid">
      <article class="module-report-card report-page" data-report-page="capacity" data-report-title="Capacity Building">
        <h2 class="report-page-heading">Capacity Building</h2>
        <header><h3>Research activities</h3><p>Seminar, presentation, and participation totals recorded in the activity module.</p></header>
        <?php if ($moduleTables['research_activities']): ?>
          <div class="module-report-list">
            <div class="module-report-row"><span>Seminars</span><strong><?= $activityReport['seminars'] ?></strong></div>
            <div class="module-report-row"><span>Presentations</span><strong><?= $activityReport['presentations'] ?></strong></div>
            <div class="module-report-row"><span>Workshops and forums</span><strong><?= $activityReport['workshops'] ?></strong></div>
            <div class="module-report-row"><span>Scheduled / completed</span><strong><?= $activityReport['scheduled'] ?> / <?= $activityReport['completed'] ?></strong></div>
            <div class="module-report-row"><span>Recorded attendance</span><strong><?= $activityReport['attended'] ?> / <?= $activityReport['participants'] ?></strong></div>
          </div>
        <?php else: ?><div class="module-report-empty">Research activity data is unavailable. Apply migration 012 to enable the module.</div><?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/shared/activities.php', ENT_QUOTES, 'UTF-8') ?>">Open activity records</a></footer>
      </article>

      <section class="report-page analytics-grid panel-implementation" data-report-page="implementation" data-report-title="Implementation &amp; Outputs">
        <h2 class="report-page-heading">Implementation &amp; Outputs</h2>
        <section class="report-panel panel-wide panel-department" aria-labelledby="department-distribution-title">
          <header class="report-panel-header">
            <div><div class="report-eyebrow">Institute participation</div><h3 class="report-title" id="department-distribution-title">Research by department</h3><p class="report-copy">The ten departments with the largest representation in the current portfolio.</p></div>
            <span class="report-tag">TOP 10</span>
          </header>
          <?php if (empty($research_by_dept)): ?>
            <div class="report-empty"><div class="report-empty-mark">00</div><strong>No department data</strong><p>Department participation will appear when project owners have department records.</p></div>
          <?php else: ?>
            <div class="report-chart">
              <?php foreach ($research_by_dept as $data):
                $width = ((int) $data['count'] / $max_dept_count) * 100;
              ?>
                <div class="report-bar">
                  <span class="report-bar-label"><?php echo htmlspecialchars($data['department'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <div class="report-bar-track"><div class="report-bar-fill" style="--bar-size:<?php echo htmlspecialchars(number_format($width, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>%"></div></div>
                  <span class="report-bar-value"><?php echo (int) $data['count']; ?> proj.</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </section>

      <article class="module-report-card report-page" data-report-page="dissemination" data-report-title="Publication &amp; Dissemination">
        <h2 class="report-page-heading">Publication &amp; Dissemination</h2>
        <header><h3>Publication monitoring</h3><p>Submissions tracked from first review through publication.</p></header>
        <?php if ($moduleTables['publications']): ?>
          <div class="module-report-list">
            <?php foreach (['submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'published' => 'Published'] as $stage => $label): ?>
              <div class="module-report-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= $publicationStages[$stage] ?></strong></div>
            <?php endforeach; ?>
            <?php foreach ($publicationTypes as $typeRow): ?>
              <div class="module-report-row"><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $typeRow['publication_type'])), ENT_QUOTES, 'UTF-8') ?></span><strong><?= (int) $typeRow['total'] ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="module-report-empty">Publication data is unavailable. Apply migration 012 to enable the module.</div><?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/shared/publications.php?status=under_review', ENT_QUOTES, 'UTF-8') ?>">Open publication monitoring</a></footer>
      </article>

      <article class="module-report-card report-page" data-report-page="ip" data-report-title="IP &amp; Utilization">
        <h2 class="report-page-heading">IP &amp; Utilization</h2>
        <header><h3>Copyrights and research outputs</h3><p>Application stages, registered outputs, and registrations completed this year.</p></header>
        <?php if ($moduleTables['copyright_applications']): ?>
          <div class="module-report-list">
            <?php foreach (['pending' => 'Pending', 'under_review' => 'Under review', 'registered' => 'Registered', 'rejected' => 'Rejected'] as $stage => $label): ?>
              <div class="module-report-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= $copyrightStages[$stage] ?></strong></div>
            <?php endforeach; ?>
            <div class="module-report-row"><span>Registered this year</span><strong><?= $registeredCopyrightsThisYear ?></strong></div>
            <?php foreach ($copyrightTypes as $typeRow): ?>
              <div class="module-report-row"><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $typeRow['output_type'])), ENT_QUOTES, 'UTF-8') ?> outputs</span><strong><?= (int) $typeRow['total'] ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="module-report-empty">Copyright data is unavailable. Apply migration 012 to enable the module.</div><?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/shared/copyrights.php?status=under_review', ENT_QUOTES, 'UTF-8') ?>">Open copyright monitoring</a></footer>
      </article>
    </div>
  </section>
</div>

<script>
(() => {
  const workspace = document.querySelector('.reports-workspace');
  const choices = Array.from(workspace.querySelectorAll('[data-report-choice]'));
  const pages = Array.from(workspace.querySelectorAll('[data-report-page]'));
  const moduleReports = workspace.querySelector('#module-portfolio');
  const moduleGrid = workspace.querySelector('.module-report-grid');
  const title = workspace.querySelector('#selected-report-title');
  const description = workspace.querySelector('#selected-report-description');
  const printButton = workspace.querySelector('#print-report');

  const updateSelection = () => {
    const selectedChoices = choices.filter((choice) => choice.checked);
    const selectedReports = new Set(selectedChoices.map((choice) => choice.value));
    const selectedPages = pages.filter((page) => selectedReports.has(page.dataset.reportPage));

    pages.forEach((page) => {
      const isSelected = selectedReports.has(page.dataset.reportPage);
      page.hidden = !isSelected;
      page.toggleAttribute('data-report-print-active', isSelected);
      page.toggleAttribute('data-report-print-first', isSelected && selectedPages[0] === page);
      page.toggleAttribute('data-report-print-last', isSelected && selectedPages[selectedPages.length - 1] === page);
    });
    const selectedModuleCards = selectedPages.filter((page) => page.classList.contains('module-report-card'));
    moduleGrid.dataset.reportCount = String(Math.max(1, selectedModuleCards.length));
    moduleReports.hidden = !selectedPages.some((page) => page.closest('#module-portfolio'));

    const count = selectedChoices.length;
    title.textContent = `${count} ${count === 1 ? 'report' : 'reports'} selected`;
    description.textContent = count > 0
      ? 'Each selected report prints on a separate page.'
      : 'Select at least one report to enable printing.';
    printButton.textContent = `Print selected ${count === 1 ? 'report' : 'reports'} (${count})`;
    printButton.disabled = count === 0;
  };

  choices.forEach((choice) => choice.addEventListener('change', updateSelection));
  printButton.addEventListener('click', () => {
    if (choices.some((choice) => choice.checked)) window.print();
  });
  updateSelection();
})();
</script>

<?php
renderAdminShellClose();
