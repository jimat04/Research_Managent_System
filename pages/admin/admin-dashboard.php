<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0);
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$total_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

$total_students = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'] ?? 0);
$total_faculty = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'faculty'")->fetch_assoc()['count'] ?? 0);
$total_staff = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'research_staff'")->fetch_assoc()['count'] ?? 0);

$research_draft = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'draft'")->fetch_assoc()['count'] ?? 0);
$research_review = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review')")->fetch_assoc()['count'] ?? 0);
$research_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('approved', 'ongoing')")->fetch_assoc()['count'] ?? 0);
$research_completed = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('completed', 'archived')")->fetch_assoc()['count'] ?? 0);

// Get recent activity
$activity_stmt = $conn->prepare("
    SELECT al.action, al.module, al.created_at, u.first_name, u.last_name
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 8
");
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: #ffffff;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 24px;
    transition: transform 0.3s, box-shadow 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  }

  .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .stat-number {
    font-size: 36px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }

  .stat-label {
    font-size: 14px;
    color: var(--text-light, #64748B);
    font-weight: 500;
  }

  .stat-icon {
    font-size: 32px;
    opacity: 0.3;
  }

  /* BENTO GRID */
  .bento-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 24px;
    margin-bottom: 48px;
  }

  .bento-card {
    background: #ffffff;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
  }

  .bento-card.span-8 { grid-column: span 8; }
  .bento-card.span-4 { grid-column: span 4; }
  .bento-card.span-6 { grid-column: span 6; }
  .bento-card.span-12 { grid-column: span 12; }

  .card-header {
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .card-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--charcoal, #111827);
  }

  .card-subtitle {
    font-size: 14px;
    color: var(--text-light, #64748B);
  }

  .card-action {
    color: var(--gold, #C8A44D);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }

  /* DONUT CHART */
  .chart-container {
    display: flex;
    gap: 32px;
    align-items: center;
    flex-wrap: wrap;
  }

  .donut-chart {
    width: 160px;
    height: 160px;
    border-radius: 50%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .donut-center {
    width: 100px;
    height: 100px;
    background: #ffffff;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  }

  .donut-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
  }

  .donut-label {
    font-size: 12px;
    color: var(--text-light, #64748B);
    margin-top: 4px;
  }

  .chart-legend {
    flex: 1;
    min-width: 200px;
  }

  .legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 14px;
  }

  .legend-left {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .legend-label {
    color: var(--text-dark, #111827);
  }

  .legend-value {
    font-weight: 600;
    color: var(--text-light, #64748B);
  }

  /* ACTIVITY LIST */
  .activity-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .activity-item {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border, #E5E7EB);
  }

  .activity-item:last-child {
    border-bottom: none;
  }

  .activity-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 6px;
    flex-shrink: 0;
  }

  .activity-content {
    flex: 1;
  }

  .activity-content p {
    font-size: 14px;
    margin: 0 0 4px;
  }

  .activity-time {
    font-size: 12px;
    color: var(--text-muted, #94A3B8);
  }

  /* QUICK ACTIONS */
  .quick-actions {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }

  .action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--bg-light, #F8F9FE);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 12px;
    cursor: pointer;
    transition: background-color 0.2s, border-color 0.2s, transform 0.2s;
    text-decoration: none;
    color: inherit;
  }

  .action-btn:hover {
    background: #ffffff;
    border-color: var(--gold, #C8A44D);
    transform: translateY(-1px);
  }

  .action-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--gold, #C8A44D);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }

  .action-text {
    flex: 1;
  }

  .action-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
  }

  .action-desc {
    font-size: 12px;
    color: var(--text-light, #64748B);
  }

  /* COMMAND CENTER */
  .command-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1.18fr) minmax(340px, .82fr);
    gap: 46px;
    align-items: center;
    min-height: 310px;
    padding: 44px 48px 68px;
    border-radius: 24px;
    background:
      radial-gradient(circle at 88% 8%, rgba(201, 146, 46, .23), transparent 31%),
      radial-gradient(circle at 4% 112%, rgba(23, 107, 103, .24), transparent 35%),
      #101827;
    color: #fff;
    box-shadow: 0 24px 55px rgba(30, 41, 59, .16);
  }
  .command-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    opacity: .18;
    background-image: repeating-linear-gradient(115deg, transparent 0 28px, rgba(255,255,255,.08) 29px 30px);
    mask-image: linear-gradient(to right, transparent, #000 58%);
  }
  .dashboard-kicker,
  .stat-overline {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
  }
  .dashboard-kicker { margin-bottom: 13px; color: #e8bd67; }
  .command-hero h1 {
    max-width: 680px;
    margin: 0;
    font-size: clamp(34px, 4vw, 54px);
    line-height: 1.01;
    letter-spacing: -.048em;
    text-wrap: balance;
  }
  .command-hero-copy > p {
    max-width: 640px;
    margin: 19px 0 0;
    color: #b9c3d3;
    font-size: 15px;
    line-height: 1.7;
  }
  .hero-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 26px; }
  .hero-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    min-height: 42px;
    padding: 0 16px;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 9px;
    color: #fff;
    font-size: 13px;
    font-weight: 650;
    text-decoration: none;
    transition: transform .2s ease, background .2s ease, border-color .2s ease;
  }
  .hero-action-primary { border-color: #d3a348; background: #d3a348; color: #182033; }
  .hero-action-secondary { background: rgba(255,255,255,.055); }
  .hero-action:hover { transform: translateY(-2px); border-color: #f0c870; }
  .hero-action:active { transform: translateY(0); }
  .hero-action:focus-visible { outline: 3px solid rgba(232, 189, 103, .35); outline-offset: 3px; }

  .operating-picture {
    padding: 21px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 17px;
    background: rgba(255,255,255,.06);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.07);
    backdrop-filter: blur(9px);
  }
  .operating-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
  .operating-head > div:first-child { display: grid; gap: 3px; }
  .operating-head span { color: #9da9ba; font-size: 10px; letter-spacing: .1em; text-transform: uppercase; }
  .operating-head strong { color: #dce3ed; font-size: 12px; font-weight: 600; }
  .system-pulse { display: flex; align-items: center; gap: 7px; color: #b9d8d2; font-size: 11px; white-space: nowrap; }
  .system-pulse i { width: 7px; height: 7px; border-radius: 50%; background: #56ad9f; box-shadow: 0 0 0 5px rgba(86,173,159,.12); }
  .operating-total { display: flex; align-items: baseline; gap: 11px; margin: 26px 0 22px; }
  .operating-total strong { font: 700 46px/1 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: -.06em; }
  .operating-total span { max-width: 130px; color: #aab5c5; font-size: 12px; line-height: 1.35; }
  .stage-meter { display: grid; gap: 13px; }
  .stage-meter-row > div:first-child { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
  .stage-meter-row span { color: #b6c0cf; font-size: 11px; }
  .stage-meter-row strong { font: 700 11px/1 ui-monospace, SFMono-Regular, Consolas, monospace; }
  .stage-meter-track { overflow: hidden; height: 4px; border-radius: 999px; background: rgba(255,255,255,.1); }
  .stage-meter-track span { display: block; height: 100%; border-radius: inherit; }

  .stats-grid {
    position: relative;
    z-index: 2;
    grid-template-columns: 1.2fr .9fr .9fr 1.1fr;
    gap: 12px;
    margin: -34px 22px 36px;
  }
  .stat-card {
    --stat-accent: #60708b;
    position: relative;
    overflow: hidden;
    min-height: 146px;
    padding: 22px;
    border: 0;
    border-radius: 15px;
    box-shadow: 0 14px 32px rgba(30, 41, 59, .1);
  }
  .stat-card::after {
    content: '';
    position: absolute;
    right: -33px;
    bottom: -48px;
    width: 106px;
    height: 106px;
    border: 18px solid var(--stat-accent);
    border-radius: 50%;
    opacity: .08;
  }
  .stat-card-users { --stat-accent: #c9922e; }
  .stat-card-research { --stat-accent: #176b67; }
  .stat-card-archive { --stat-accent: #60708b; }
  .stat-card-records { --stat-accent: #172033; }
  .stat-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(30, 41, 59, .14); }
  .stat-overline { margin-bottom: 20px; color: var(--stat-accent); }
  .stat-number { color: #111827; font-size: 37px; font-variant-numeric: tabular-nums; letter-spacing: -.045em; }
  .stat-label { color: #667085; font-size: 12px; }
  .stat-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 26px;
    border-radius: 7px;
    background: color-mix(in srgb, var(--stat-accent) 11%, white);
    color: var(--stat-accent);
    font: 700 10px/1 ui-monospace, SFMono-Regular, Consolas, monospace;
    letter-spacing: .08em;
    opacity: 1;
  }

  .bento-grid { gap: 16px; margin-bottom: 20px; }
  .bento-card {
    padding: 27px;
    border-color: #dfe5ed;
    border-radius: 18px;
    box-shadow: 0 11px 30px rgba(30, 41, 59, .065);
  }
  .bento-card.span-5 { grid-column: span 5; }
  .bento-card.span-7 { grid-column: span 7; }
  .card-title { font-size: 20px; letter-spacing: -.025em; }
  .card-subtitle { font-size: 12px; }
  .card-action { color: #946917; font-size: 12px; }
  .card-action:hover { color: #68480d; text-decoration: underline; text-underline-offset: 4px; }
  .card-action:focus-visible { outline: 3px solid rgba(201,146,46,.18); outline-offset: 3px; border-radius: 4px; }
  .chart-container { align-items: stretch; }
  .donut-chart { width: 146px; height: 146px; box-shadow: inset 0 0 0 1px rgba(15,23,42,.05); }
  .donut-center { width: 94px; height: 94px; box-shadow: 0 5px 16px rgba(30,41,59,.08); }
  .donut-value { font-variant-numeric: tabular-nums; letter-spacing: -.04em; }
  .chart-legend { display: grid; align-content: center; gap: 2px; }
  .legend-item { margin: 0; padding: 9px 0; border-bottom: 1px solid #edf0f4; }
  .legend-item:last-child { border-bottom: 0; }
  .legend-value { font: 700 12px/1 ui-monospace, SFMono-Regular, Consolas, monospace; }
  .legend-dot { border-radius: 3px; }

  .quick-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
  .action-btn {
    min-height: 78px;
    padding: 13px;
    border-color: #e2e7ee;
    border-radius: 11px;
    background: #f8fafc;
  }
  .action-btn:hover { background: #fffaf0; border-color: #c99a3e; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(104,72,13,.08); }
  .action-btn:active { transform: translateY(0); }
  .action-btn:focus-visible { outline: 3px solid rgba(201,146,46,.18); outline-offset: 2px; }
  .action-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #172033;
    color: #e6bd6d;
    font: 700 10px/1 ui-monospace, SFMono-Regular, Consolas, monospace;
  }
  .action-title { font-size: 13px; }
  .action-desc { margin-top: 2px; color: #7a8698; font-size: 11px; }
  .activity-list { max-height: 360px; overflow: auto; padding-right: 5px; }
  .activity-item { position: relative; gap: 14px; padding: 13px 0; }
  .activity-dot { width: 7px; height: 7px; box-shadow: 0 0 0 4px rgba(100,116,139,.09); }
  .activity-content p { line-height: 1.45; }
  .activity-time { font: 500 10px/1.4 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .02em; }

  /* RESPONSIVE */
  @media (max-width: 1200px) {
    .bento-card.span-8,
    .bento-card.span-4,
    .bento-card.span-5,
    .bento-card.span-7 {
      grid-column: span 12;
    }
  }

  @media (max-width: 1000px) {
    .command-hero { grid-template-columns: 1fr; gap: 30px; }
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }

  @media (max-width: 768px) {
    .command-hero { min-height: 0; padding: 30px 24px 56px; border-radius: 18px; }
    .command-hero h1 { font-size: 35px; }
    .command-hero-copy > p { font-size: 14px; }
    .operating-picture { padding: 18px; }
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin: -28px 8px 30px; }
    .stat-card { min-height: 132px; padding: 17px; }
    .stat-overline { margin-bottom: 16px; font-size: 9px; }
    .stat-number { font-size: 31px; }
    .stat-icon { display: none; }
    .bento-card { padding: 24px; }
    .quick-actions { grid-template-columns: 1fr; }
    .chart-container { align-items: center; }
  }

  @media (max-width: 420px) {
    .hero-actions { display: grid; }
    .hero-action { width: 100%; }
    .operating-head { display: grid; }
    .stats-grid { margin-left: 4px; margin-right: 4px; }
    .stat-card { min-height: 123px; padding: 15px; }
    .stat-label { max-width: 120px; line-height: 1.35; }
    .chart-container { justify-content: center; }
    .chart-legend { width: 100%; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-dashboard',
    'Research Command Center',
    'Institute-wide research operations, people, and system activity.'
);
?>

    <section class="command-hero">
      <div class="command-hero-copy">
        <div class="dashboard-kicker">Institutional research operations</div>
        <h1>A clear view of every project moving through the system.</h1>
        <p>Monitor the active review pipeline, guide completed work into the archive, and keep institutional records healthy.</p>
        <div class="hero-actions">
          <a href="admin-research.php" class="hero-action hero-action-primary">Review research <span aria-hidden="true">→</span></a>
          <a href="admin-reports.php" class="hero-action hero-action-secondary">Open analytics</a>
        </div>
      </div>
      <div class="operating-picture">
        <div class="operating-head">
          <div>
            <span>Operating picture</span>
            <strong><?php echo date('M d, Y'); ?></strong>
          </div>
          <div class="system-pulse"><i></i> Live database</div>
        </div>
        <div class="operating-total">
          <strong><?php echo $total_research; ?></strong>
          <span>research project<?php echo $total_research === 1 ? '' : 's'; ?> tracked</span>
        </div>
        <div class="stage-meter">
          <?php
          $stage_total = max(1, $total_research);
          $stage_rows = [
              ['Review queue', $research_review, '#d2a343'],
              ['Approved / ongoing', $research_approved, '#4f9188'],
              ['Completed / archived', $research_completed, '#9fb2c8'],
          ];
          foreach ($stage_rows as [$stage_label, $stage_count, $stage_color]):
              $stage_width = min(100, max(0, ($stage_count / $stage_total) * 100));
          ?>
            <div class="stage-meter-row">
              <div><span><?php echo htmlspecialchars($stage_label, ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo (int) $stage_count; ?></strong></div>
              <div class="stage-meter-track"><span style="width: <?php echo number_format($stage_width, 2, '.', ''); ?>%; background: <?php echo $stage_color; ?>;"></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card stat-card-users">
        <div class="stat-header">
          <div>
            <div class="stat-overline">People</div>
            <div class="stat-number"><?php echo $total_users; ?></div>
            <div class="stat-label">Total Users</div>
          </div>
          <div class="stat-icon">USR</div>
        </div>
      </div>

      <div class="stat-card stat-card-research">
        <div class="stat-header">
          <div>
            <div class="stat-overline">Portfolio</div>
            <div class="stat-number"><?php echo $total_research; ?></div>
            <div class="stat-label">Total Research</div>
          </div>
          <div class="stat-icon">RSR</div>
        </div>
      </div>

      <div class="stat-card stat-card-archive">
        <div class="stat-header">
          <div>
            <div class="stat-overline">Permanent record</div>
            <div class="stat-number"><?php echo $total_archived; ?></div>
            <div class="stat-label">Archived Projects</div>
          </div>
          <div class="stat-icon">ARC</div>
        </div>
      </div>

      <div class="stat-card stat-card-records">
        <div class="stat-header">
          <div>
            <div class="stat-overline">System footprint</div>
            <div class="stat-number"><?php echo number_format($total_users + $total_research); ?></div>
            <div class="stat-label">Records Under Management</div>
          </div>
          <div class="stat-icon">SYS</div>
        </div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- USER DISTRIBUTION -->
      <div class="bento-card span-5 distribution-card">
        <div class="card-header">
          <div>
            <div class="card-title">User Distribution</div>
            <div class="card-subtitle">By role</div>
          </div>
          <a href="admin-users.php" class="card-action">Manage users →</a>
        </div>

        <div class="chart-container">
          <?php
          $user_deg1 = $total_users > 0 ? ($total_students / $total_users) * 360 : 0;
          $user_deg2 = $user_deg1 + ($total_users > 0 ? ($total_faculty / $total_users) * 360 : 0);
          $user_deg3 = $user_deg2 + ($total_users > 0 ? ($total_staff / $total_users) * 360 : 0);
          $total_admins = max(0, $total_users - $total_students - $total_faculty - $total_staff);
          ?>
          <div class="donut-chart" style="background: conic-gradient(#C9922E 0deg <?php echo $user_deg1; ?>deg, #176B67 <?php echo $user_deg1; ?>deg <?php echo $user_deg2; ?>deg, #60708B <?php echo $user_deg2; ?>deg <?php echo $user_deg3; ?>deg, #172033 <?php echo $user_deg3; ?>deg 360deg);">
            <div class="donut-center">
              <div class="donut-value"><?php echo $total_users; ?></div>
              <div class="donut-label">Total</div>
            </div>
          </div>

          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #C9922E;"></div>
                <span class="legend-label">Students</span>
              </div>
              <span class="legend-value"><?php echo $total_students; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #176B67;"></div>
                <span class="legend-label">Faculty</span>
              </div>
              <span class="legend-value"><?php echo $total_faculty; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #60708B;"></div>
                <span class="legend-label">Staff</span>
              </div>
              <span class="legend-value"><?php echo $total_staff; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #172033;"></div>
                <span class="legend-label">Administrators</span>
              </div>
              <span class="legend-value"><?php echo $total_admins; ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- RESEARCH STATUS -->
      <div class="bento-card span-7 research-status-card">
        <div class="card-header">
          <div>
            <div class="card-title">Research Status</div>
            <div class="card-subtitle">Distribution by stage</div>
          </div>
          <a href="admin-research.php" class="card-action">View all →</a>
        </div>

        <div class="chart-container">
          <?php
          $res_deg1 = $total_research > 0 ? ($research_draft / $total_research) * 360 : 0;
          $res_deg2 = $res_deg1 + ($total_research > 0 ? ($research_review / $total_research) * 360 : 0);
          $res_deg3 = $res_deg2 + ($total_research > 0 ? ($research_approved / $total_research) * 360 : 0);
          $res_deg4 = $res_deg3 + ($total_research > 0 ? ($research_completed / $total_research) * 360 : 0);
          $research_other = max(0, $total_research - $research_draft - $research_review - $research_approved - $research_completed);
          ?>
          <div class="donut-chart" style="background: conic-gradient(#CBD5E1 0deg <?php echo $res_deg1; ?>deg, #C9922E <?php echo $res_deg1; ?>deg <?php echo $res_deg2; ?>deg, #4F9188 <?php echo $res_deg2; ?>deg <?php echo $res_deg3; ?>deg, #172033 <?php echo $res_deg3; ?>deg <?php echo $res_deg4; ?>deg, #94A3B8 <?php echo $res_deg4; ?>deg 360deg);">
            <div class="donut-center">
              <div class="donut-value"><?php echo $total_research; ?></div>
              <div class="donut-label">Total</div>
            </div>
          </div>

          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #CBD5E1;"></div>
                <span class="legend-label">Draft</span>
              </div>
              <span class="legend-value"><?php echo $research_draft; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #C9922E;"></div>
                <span class="legend-label">Under Review</span>
              </div>
              <span class="legend-value"><?php echo $research_review; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #4F9188;"></div>
                <span class="legend-label">Approved</span>
              </div>
              <span class="legend-value"><?php echo $research_approved; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #172033;"></div>
                <span class="legend-label">Completed</span>
              </div>
              <span class="legend-value"><?php echo $research_completed; ?></span>
            </div>
            <?php if ($research_other > 0): ?>
              <div class="legend-item">
                <div class="legend-left">
                  <div class="legend-dot" style="background: #94A3B8;"></div>
                  <span class="legend-label">Other states</span>
                </div>
                <span class="legend-value"><?php echo $research_other; ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="bento-card span-7 quick-actions-card">
        <div class="card-header">
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-subtitle">Common administrative tasks</div>
          </div>
        </div>

        <div class="quick-actions">
          <a href="admin-users.php" class="action-btn">
            <div class="action-icon">01</div>
            <div class="action-text">
              <div class="action-title">Add User</div>
              <div class="action-desc">Create new account</div>
            </div>
          </a>

          <a href="admin-research.php" class="action-btn">
            <div class="action-icon">02</div>
            <div class="action-text">
              <div class="action-title">Manage Research</div>
              <div class="action-desc">Review projects</div>
            </div>
          </a>

          <a href="admin-reports.php" class="action-btn">
            <div class="action-icon">03</div>
            <div class="action-text">
              <div class="action-title">Generate Report</div>
              <div class="action-desc">Analytics & insights</div>
            </div>
          </a>

          <a href="admin-backup.php" class="action-btn">
            <div class="action-icon">04</div>
            <div class="action-text">
              <div class="action-title">System Backup</div>
              <div class="action-desc">Export database</div>
            </div>
          </a>

          <a href="admin-archive.php" class="action-btn">
            <div class="action-icon">05</div>
            <div class="action-text">
              <div class="action-title">Archive Management</div>
              <div class="action-desc">View completed research</div>
            </div>
          </a>

          <a href="admin-logs.php" class="action-btn">
            <div class="action-icon">06</div>
            <div class="action-text">
              <div class="action-title">Activity Logs</div>
              <div class="action-desc">System audit trail</div>
            </div>
          </a>
        </div>
      </div>

      <!-- SYSTEM ACTIVITY -->
      <div class="bento-card span-5 activity-card">
        <div class="card-header">
          <div>
            <div class="card-title">System Activity</div>
            <div class="card-subtitle">Recent actions across the platform</div>
          </div>
          <a href="admin-logs.php" class="card-action">View logs →</a>
        </div>

        <ul class="activity-list">
          <?php
          if ($activities->num_rows > 0):
            while ($activity = $activities->fetch_assoc()):
              $user_name = trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? ''));
              $action = $activity['action'] ?? 'Unknown action';
              $created_at = $activity['created_at'] ?? '';

              $activity_lower = strtolower($action);
              $dot_color = '#2563EB';
              if (strpos($activity_lower, 'approved') !== false) {
                $dot_color = '#16A34A';
              } elseif (strpos($activity_lower, 'submitted') !== false || strpos($activity_lower, 'created') !== false) {
                $dot_color = '#7C3AED';
              } elseif (strpos($activity_lower, 'revision') !== false || strpos($activity_lower, 'rejected') !== false) {
                $dot_color = '#EA580C';
              } elseif (strpos($activity_lower, 'login') !== false) {
                $dot_color = '#C8A44D';
              }
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: <?php echo $dot_color; ?>;"></div>
              <div class="activity-content">
                <p><?php echo $user_name ? '<strong>' . htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') . '</strong>: ' : ''; ?><?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="activity-time"><?php echo $created_at ? date('M d, Y • h:i A', strtotime($created_at)) : ''; ?></div>
              </div>
            </li>
          <?php
            endwhile;
          else:
          ?>
            <li class="activity-item">
              <div class="activity-content">
                <p style="color: var(--text-muted, #94A3B8);">No recent activity</p>
              </div>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

<?php
renderAdminShellClose();
