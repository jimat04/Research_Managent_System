<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('research_staff');
$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Helper function
function se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Stat counts
$stat_pending = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status = 'submitted'"
)->fetch_assoc()['count'] ?? 0);

$stat_crec = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('under_review', 'under_crec_review')"
)->fetch_assoc()['count'] ?? 0);

$stat_revision = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('revision_required', 'for_revision')"
)->fetch_assoc()['count'] ?? 0);

$stat_archive = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects
     WHERE status IN ('completed','archived')
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
)->fetch_assoc()['count'] ?? 0);

// Contact Messages count
$stat_contact = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM contact_messages WHERE status = 'pending'"
)->fetch_assoc()['count'] ?? 0);

// Submissions Inbox
$inbox_stmt = $conn->prepare("
    SELECT
        rp.project_id,
        rp.title,
        rp.created_at,
        rp.status,
        u.first_name,
        u.last_name,
        rc.category_name,
        CASE WHEN pu.project_id IS NOT NULL THEN 1 ELSE 0 END AS has_proposal
    FROM research_projects rp
    LEFT JOIN users           u  ON u.user_id      = rp.created_by
    LEFT JOIN research_categories rc ON rc.category_id = rp.category_id
    LEFT JOIN (
        SELECT DISTINCT project_id FROM uploads WHERE type = 'proposal'
    ) pu ON pu.project_id = rp.project_id
    WHERE rp.status = 'submitted'
    ORDER BY rp.created_at DESC
    LIMIT 10
");
$inbox_stmt->execute();
$inbox = $inbox_stmt->get_result();

// Repository Overview
$repo_row = $conn->query("
    SELECT
        SUM(status = 'draft')                                                                                           AS draft_count,
        SUM(status IN ('submitted','under_review','under_crec_review','under_erec_review'))                             AS review_count,
        SUM(status IN ('for_revision','revision_required'))                                                             AS revision_count,
        SUM(status IN ('approved','ongoing','progress_report','terminal_review'))                                        AS ongoing_count,
        SUM(status IN ('completed','archived'))                                                                         AS completed_count
    FROM research_projects
")->fetch_assoc();

$repo_draft     = (int) ($repo_row['draft_count']     ?? 0);
$repo_review    = (int) ($repo_row['review_count']    ?? 0);
$repo_revision  = (int) ($repo_row['revision_count']  ?? 0);
$repo_ongoing   = (int) ($repo_row['ongoing_count']   ?? 0);
$repo_completed = (int) ($repo_row['completed_count'] ?? 0);
$repo_total     = $repo_draft + $repo_review + $repo_revision + $repo_ongoing + $repo_completed;

// Build donut chart degrees
$deg = function (int $count) use ($repo_total): float {
    return $repo_total > 0 ? round(($count / $repo_total) * 360, 2) : 0;
};
$d1 = $deg($repo_draft);
$d2 = $d1 + $deg($repo_review);
$d3 = $d2 + $deg($repo_revision);
$d4 = $d3 + $deg($repo_ongoing);

// Recent Activity
$activity_stmt = $conn->prepare("
    SELECT al.action, al.module, al.created_at,
           u.first_name, u.last_name
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
");
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// Status badge helper
function statusBadge(string $status): array {
    $map = [
        'draft'               => ['status-draft',    'Draft'],
        'submitted'           => ['status-review',   'Submitted'],
        'under_review'        => ['status-review',   'Under Review'],
        'under_crec_review'   => ['status-review',   'CREC Review'],
        'under_erec_review'   => ['status-review',   'EREC Review'],
        'for_revision'        => ['status-pending',  'For Revision'],
        'revision_required'   => ['status-pending',  'Revision Required'],
        'approved'            => ['status-approved', 'Approved'],
        'ongoing'             => ['status-approved', 'Ongoing'],
        'completed'           => ['status-approved', 'Completed'],
        'archived'            => ['status-approved', 'Archived'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

function activityDotColor(string $action): string {
    $a = strtolower($action);
    if (strpos($a, 'approv') !== false || strpos($a, 'login') !== false)  return '#16A34A';
    if (strpos($a, 'submi')  !== false || strpos($a, 'revis') !== false)  return '#EA580C';
    if (strpos($a, 'creat')  !== false || strpos($a, 'regist') !== false) return '#2563EB';
    return '#64748B';
}

$initials = se(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)));
$full_name = se($user['first_name'] . ' ' . $user['last_name']);
$first_name = se($user['first_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Research Staff Dashboard — RMS</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    * { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --charcoal: #111827;
      --slate: #1F2937;
      --bg-surface: #F8FAFC;
      --bg-card: #FFFFFF;
      --border: #E5E7EB;
      --gold: #C8A44D;
      --text-primary: #111827;
      --text-secondary: #64748B;
      --text-muted: #94A3B8;

      --status-draft: #64748B;
      --status-proposal: #2563EB;
      --status-crec: #3B82F6;
      --status-erec: #7C3AED;
      --status-revision: #EA580C;
      --status-approved: #16A34A;
      --status-completed: #059669;
      --status-archived: #475569;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-surface);
      color: var(--text-primary);
      line-height: 1.6;
    }

    .dashboard {
      display: flex;
      min-height: 100vh;
    }

    /* SIDEBAR */
    .sidebar {
      width: 260px;
      background: var(--charcoal);
      color: white;
      padding: 32px 0;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
    }

    .sidebar-header {
      padding: 0 24px 32px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      margin-bottom: 24px;
    }

    .sidebar-brand {
      font-size: 20px;
      font-weight: 700;
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .sidebar-role {
      font-size: 13px;
      color: var(--text-muted);
      font-weight: 500;
    }

    .sidebar-nav {
      flex: 1;
      padding: 0 16px;
    }

    .nav-group-title {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin: 24px 8px 8px;
      text-transform: uppercase;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      margin: 2px 0;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s;
      font-size: 14px;
      color: rgba(255,255,255,0.7);
    }

    .nav-item:hover {
      background: rgba(255,255,255,0.08);
      color: white;
    }

    .nav-item.active {
      background: var(--gold);
      color: white;
    }

    .nav-item .badge {
      margin-left: auto;
      background: var(--gold);
      color: white;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 12px;
    }

    .sidebar-footer {
      padding: 24px;
      border-top: 1px solid rgba(255,255,255,0.1);
    }

    .user-card {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: var(--gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }

    .user-name {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 2px;
    }

    .user-role {
      font-size: 12px;
      color: var(--text-muted);
    }

    /* MAIN CONTENT */
    .main-content {
      flex: 1;
      margin-left: 260px;
      padding: 48px;
    }

    .page-header {
      margin-bottom: 48px;
    }

    .page-header h1 {
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--charcoal);
    }

    .page-header p {
      font-size: 16px;
      color: var(--text-secondary);
    }

    /* STATS GRID */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 24px;
      margin-bottom: 48px;
    }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 24px;
      transition: all 0.3s;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.08);
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
      color: var(--text-secondary);
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
      background: var(--bg-card);
      border: 1px solid var(--border);
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
      color: var(--charcoal);
    }

    .card-subtitle {
      font-size: 14px;
      color: var(--text-secondary);
    }

    .card-action {
      color: var(--gold);
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
      background: var(--bg-card);
      border-radius: 50%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .donut-value {
      font-size: 28px;
      font-weight: 700;
      line-height: 1;
    }

    .donut-label {
      font-size: 12px;
      color: var(--text-secondary);
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
      color: var(--text-primary);
    }

    .legend-value {
      font-weight: 600;
      color: var(--text-secondary);
    }

    /* ACTIVITY LIST */
    .activity-list {
      list-style: none;
    }

    .activity-item {
      display: flex;
      gap: 12px;
      padding: 16px 0;
      border-bottom: 1px solid var(--border);
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
      margin-bottom: 4px;
    }

    .activity-time {
      font-size: 12px;
      color: var(--text-muted);
    }

    /* TABLE */
    .table-wrap {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    thead {
      background: var(--bg-surface);
    }

    th {
      text-align: left;
      padding: 12px 16px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    td {
      padding: 16px;
      font-size: 14px;
      border-top: 1px solid var(--border);
    }

    tr:hover {
      background: var(--bg-surface);
    }

    .badge-status {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
    }

    .badge-status.status-draft {
      background: #F1F5F9;
      color: var(--status-draft);
    }

    .badge-status.status-review {
      background: #DBEAFE;
      color: var(--status-proposal);
    }

    .badge-status.status-pending {
      background: #FEF3C7;
      color: var(--status-revision);
    }

    .badge-status.status-approved {
      background: #DCFCE7;
      color: var(--status-approved);
    }

    /* BUTTON */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 14px;
      border: none;
      cursor: pointer;
      transition: all 0.2s;
      text-decoration: none;
    }

    .btn-primary {
      background: var(--gold);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(200,164,77,0.3);
    }

    .btn-sm {
      padding: 6px 14px;
      font-size: 13px;
    }

    /* EMPTY STATE */
    .empty-state {
      text-align: center;
      padding: 48px 24px;
      color: var(--text-muted);
    }

    .empty-state-icon {
      font-size: 48px;
      margin-bottom: 16px;
      opacity: 0.5;
    }

    .empty-state p {
      font-size: 14px;
    }

    /* RESPONSIVE */
    @media (max-width: 1200px) {
      .bento-card.span-8,
      .bento-card.span-4 {
        grid-column: span 12;
      }
    }

    @media (max-width: 768px) {
      .sidebar {
        display: none;
      }

      .main-content {
        margin-left: 0;
        padding: 24px;
      }

      .stats-grid {
        grid-template-columns: 1fr;
      }

      .bento-card {
        padding: 24px;
      }
    }
  </style>
</head>
<body>

<div class="dashboard">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand">EARIST RMS</div>
      <div class="sidebar-role">Research Staff</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Overview</div>
      <div class="nav-item active" onclick="location.href='staff-dashboard.php'">
        <span>📊</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-group-title">Processing</div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span>📥</span>
        <span>Submissions Inbox</span>
        <?php if ($stat_pending > 0): ?>
          <span class="badge"><?php echo se($stat_pending); ?></span>
        <?php endif; ?>
      </div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span>🏛️</span>
        <span>CREC Review</span>
        <?php if ($stat_crec > 0): ?>
          <span class="badge"><?php echo se($stat_crec); ?></span>
        <?php endif; ?>
      </div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span>🔄</span>
        <span>Revision Returns</span>
        <?php if ($stat_revision > 0): ?>
          <span class="badge"><?php echo se($stat_revision); ?></span>
        <?php endif; ?>
      </div>

      <div class="nav-group-title">Repository</div>
      <div class="nav-item" onclick="location.href='../shared/research-archive.php'">
        <span>🗂️</span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='#'">
        <span>📄</span>
        <span>Document Verification</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='contact-messages.php'">
        <span>📨</span>
        <span>Contact Messages</span>
        <?php if ($stat_contact > 0): ?>
          <span class="badge"><?php echo se($stat_contact); ?></span>
        <?php endif; ?>
      </div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span>💬</span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='notifications.php'">
        <span>🔔</span>
        <span>Notifications</span>
      </div>

      <div class="nav-group-title">Account</div>
      <div class="nav-item" onclick="location.href='profile.php'">
        <span>👤</span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #EF4444;">
        <span>🚪</span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo $initials; ?></div>
        <div>
          <div class="user-name"><?php echo $full_name; ?></div>
          <div class="user-role">Research Staff</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="page-header">
      <h1>Welcome back, <?php echo $first_name; ?></h1>
      <p>You have <?php echo se($stat_pending); ?> new submission<?php echo $stat_pending !== 1 ? 's' : ''; ?> awaiting verification.</p>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo se($stat_pending); ?></div>
            <div class="stat-label">Pending Review</div>
          </div>
          <div class="stat-icon">📥</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo se($stat_crec); ?></div>
            <div class="stat-label">In CREC Review</div>
          </div>
          <div class="stat-icon">🏛️</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo se($stat_revision); ?></div>
            <div class="stat-label">Revision Returns</div>
          </div>
          <div class="stat-icon">🔄</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo se($stat_archive); ?></div>
            <div class="stat-label">Archived (30 Days)</div>
          </div>
          <div class="stat-icon">🗂️</div>
        </div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- REPOSITORY OVERVIEW -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Repository Overview</div>
            <div class="card-subtitle">All research projects</div>
          </div>
          <a href="../shared/research-archive.php" class="card-action">View archive →</a>
        </div>

        <div class="chart-container">
          <?php
          $donut_css = $repo_total > 0
            ? "conic-gradient(
                #64748B   0deg {$d1}deg,
                #7C3AED   {$d1}deg {$d2}deg,
                #EA580C   {$d2}deg {$d3}deg,
                #2563EB   {$d3}deg {$d4}deg,
                #16A34A   {$d4}deg 360deg
              )"
            : '#E5E7EB';
          ?>
          <div class="donut-chart" style="background: <?php echo $donut_css; ?>;">
            <div class="donut-center">
              <div class="donut-value"><?php echo se($repo_total); ?></div>
              <div class="donut-label">Total</div>
            </div>
          </div>

          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #64748B;"></div>
                <span class="legend-label">Draft</span>
              </div>
              <span class="legend-value"><?php echo se($repo_draft); ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #7C3AED;"></div>
                <span class="legend-label">In Review</span>
              </div>
              <span class="legend-value"><?php echo se($repo_review); ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #EA580C;"></div>
                <span class="legend-label">For Revision</span>
              </div>
              <span class="legend-value"><?php echo se($repo_revision); ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #2563EB;"></div>
                <span class="legend-label">Ongoing</span>
              </div>
              <span class="legend-value"><?php echo se($repo_ongoing); ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #16A34A;"></div>
                <span class="legend-label">Completed</span>
              </div>
              <span class="legend-value"><?php echo se($repo_completed); ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- RECENT ACTIVITY -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Recent Activity</div>
            <div class="card-subtitle">Latest system actions</div>
          </div>
          <a href="notifications.php" class="card-action">View all →</a>
        </div>

        <ul class="activity-list">
          <?php
          $act_count = 0;
          while ($act = $activities->fetch_assoc()):
              if ($act_count >= 5) break;
              $act_count++;
              $dot_color = activityDotColor($act['action'] ?? '');
              $act_user  = trim(($act['first_name'] ?? '') . ' ' . ($act['last_name'] ?? ''));
              $time_str  = !empty($act['created_at']) ? date('M d, Y • h:i A', strtotime($act['created_at'])) : '';
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: <?php echo $dot_color; ?>;"></div>
              <div class="activity-content">
                <p><?php echo $act_user ? '<strong>' . se($act_user) . '</strong>: ' : ''; ?><?php echo se($act['action']); ?></p>
                <?php if ($time_str): ?>
                  <div class="activity-time"><?php echo se($time_str); ?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endwhile; ?>
          <?php if ($act_count === 0): ?>
            <li class="activity-item">
              <div class="activity-content">
                <p style="color: var(--text-muted);">No recent activity</p>
              </div>
            </li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- SUBMISSIONS INBOX -->
      <div class="bento-card span-12" id="inbox">
        <div class="card-header">
          <div>
            <div class="card-title">Submissions Inbox</div>
            <div class="card-subtitle">New submissions awaiting completeness verification</div>
          </div>
        </div>

        <?php if ($inbox->num_rows > 0): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Research Title</th>
                  <th>Proponent</th>
                  <th>Category</th>
                  <th>Submitted</th>
                  <th>Proposal</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                while ($row = $inbox->fetch_assoc()):
                    [$badge_class, $badge_label] = statusBadge($row['status'] ?? 'submitted');
                    $proponent = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
                    $category  = $row['category_name'] ?? 'General';
                    $sub_date  = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—';
                    $has_doc   = (int) ($row['has_proposal'] ?? 0) === 1;
                ?>
                  <tr>
                    <td style="font-weight: 500; max-width: 300px;">
                      <?php echo se($row['title']); ?>
                    </td>
                    <td><?php echo se($proponent); ?></td>
                    <td style="font-size: 13px; color: var(--text-secondary);"><?php echo se($category); ?></td>
                    <td><?php echo se($sub_date); ?></td>
                    <td>
                      <?php if ($has_doc): ?>
                        <span style="color: #16A34A; font-size: 13px; font-weight: 600;">✓ Attached</span>
                      <?php else: ?>
                        <span style="color: #EA580C; font-size: 13px;">⚠️ Missing</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge-status <?php echo se($badge_class); ?>">
                        <?php echo se($badge_label); ?>
                      </span>
                    </td>
                    <td>
                      <a class="btn btn-primary btn-sm"
                         href="research-detail.php?id=<?php echo (int) $row['project_id']; ?>">
                        Review
                      </a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-state-icon">🎉</div>
            <p>No pending submissions in inbox.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</body>
</html>
