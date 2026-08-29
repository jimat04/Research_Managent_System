<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('student');

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Get student's research projects
$proj_stmt = $conn->prepare("SELECT * FROM research_projects WHERE created_by = ? ORDER BY created_at DESC");
$proj_stmt->bind_param('i', $user_id);
$proj_stmt->execute();
$projects = $proj_stmt->get_result();

// Get notifications
$notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notif_stmt->bind_param('i', $user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result();

// Get unread notifications count
$unread_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->bind_param('i', $user_id);
$unread_stmt->execute();
$unread_count = (int) ($unread_stmt->get_result()->fetch_assoc()['count'] ?? 0);

// Get chapter statistics
$stat_submitted_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chapters WHERE project_id IN (SELECT project_id FROM research_projects WHERE created_by = ?) AND status IN ('submitted', 'under_review', 'approved')");
$stat_submitted_stmt->bind_param('i', $user_id);
$stat_submitted_stmt->execute();
$stat_submitted = (int) ($stat_submitted_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$stat_review_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chapters WHERE project_id IN (SELECT project_id FROM research_projects WHERE created_by = ?) AND status = 'under_review'");
$stat_review_stmt->bind_param('i', $user_id);
$stat_review_stmt->execute();
$stat_review = (int) ($stat_review_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$stat_approved_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chapters WHERE project_id IN (SELECT project_id FROM research_projects WHERE created_by = ?) AND status = 'approved'");
$stat_approved_stmt->bind_param('i', $user_id);
$stat_approved_stmt->execute();
$stat_approved = (int) ($stat_approved_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$stat_revision_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chapters WHERE project_id IN (SELECT project_id FROM research_projects WHERE created_by = ?) AND status = 'revision_required'");
$stat_revision_stmt->bind_param('i', $user_id);
$stat_revision_stmt->execute();
$stat_revision = (int) ($stat_revision_stmt->get_result()->fetch_assoc()['count'] ?? 0);

// Get active project with chapter details
$active_project = null;
$chapter_progress = [];
if ($projects->num_rows > 0) {
    $projects->data_seek(0);
    $active_project = $projects->fetch_assoc();

    // Get chapter progress for active project
    $chapter_stmt = $conn->prepare("SELECT chapter_number, status FROM chapters WHERE project_id = ? ORDER BY chapter_number ASC");
    $chapter_stmt->bind_param('i', $active_project['project_id']);
    $chapter_stmt->execute();
    $chapters_result = $chapter_stmt->get_result();

    while ($ch = $chapters_result->fetch_assoc()) {
        $chapter_progress[(int)$ch['chapter_number']] = $ch['status'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard — RMS</title>
  <script src="https://unpkg.com/lucide@latest" defer></script>
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

    /* CHAPTER PROGRESS */
    .chapter-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .chapter-item {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 16px;
      background: var(--bg-surface);
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    .chapter-number {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: var(--charcoal);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 18px;
      flex-shrink: 0;
    }

    .chapter-number.completed {
      background: var(--status-approved);
    }

    .chapter-number.review {
      background: var(--status-proposal);
    }

    .chapter-number.revision {
      background: var(--status-revision);
    }

    .chapter-info {
      flex: 1;
    }

    .chapter-title {
      font-weight: 600;
      font-size: 15px;
      margin-bottom: 4px;
    }

    .chapter-desc {
      font-size: 13px;
      color: var(--text-secondary);
    }

    .chapter-status {
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
    }

    .chapter-status.draft {
      background: #F1F5F9;
      color: var(--status-draft);
    }

    .chapter-status.review {
      background: #DBEAFE;
      color: var(--status-proposal);
    }

    .chapter-status.approved {
      background: #DCFCE7;
      color: var(--status-approved);
    }

    .chapter-status.revision {
      background: #FEF3C7;
      color: var(--status-revision);
    }

    /* TIMELINE */
    .timeline {
      position: relative;
      padding-left: 32px;
    }

    .timeline::before {
      content: '';
      position: absolute;
      left: 8px;
      top: 8px;
      bottom: 8px;
      width: 2px;
      background: var(--border);
    }

    .timeline-item {
      position: relative;
      padding-bottom: 24px;
    }

    .timeline-item:last-child {
      padding-bottom: 0;
    }

    .timeline-dot {
      position: absolute;
      left: -27px;
      top: 4px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: var(--bg-card);
      border: 3px solid var(--border);
    }

    .timeline-dot.active {
      border-color: var(--gold);
      background: var(--gold);
    }

    .timeline-dot.completed {
      border-color: var(--status-approved);
      background: var(--status-approved);
    }

    .timeline-content h4 {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 4px;
    }

    .timeline-content p {
      font-size: 13px;
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

    .badge-status.status-submitted,
    .badge-status.status-under-review {
      background: #DBEAFE;
      color: var(--status-proposal);
    }

    .badge-status.status-approved {
      background: #DCFCE7;
      color: var(--status-approved);
    }

    .badge-status.status-revision-required {
      background: #FEF3C7;
      color: var(--status-revision);
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
      <div class="sidebar-role">Student Portal</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Main</div>
      <div class="nav-item active" onclick="location.href='student-dashboard.php'">
        <span class="icon"><i data-lucide="layout-dashboard"></i></span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item" onclick="location.href='my-research.php'">
        <span class="icon"><i data-lucide="folder-kanban"></i></span>
        <span>My Research</span>
      </div>
      <div class="nav-item" onclick="location.href='submit-research.php'">
        <span class="icon"><i data-lucide="file-up"></i></span>
        <span>Submit Research</span>
      </div>
      <div class="nav-item" onclick="location.href='my-documents.php'">
        <span class="icon"><i data-lucide="files"></i></span>
        <span>My Documents</span>
      </div>
      <div class="nav-item" onclick="location.href='progress-tracking.php'">
        <span class="icon"><i data-lucide="chart-no-axes-combined"></i></span>
        <span>Progress Tracking</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span class="icon"><i data-lucide="messages-square"></i></span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/notifications.php'">
        <span class="icon"><i data-lucide="bell"></i></span>
        <span>Notifications</span>
        <?php if ($unread_count > 0): ?>
          <span class="badge"><?php echo $unread_count; ?></span>
        <?php endif; ?>
      </div>

      <div class="nav-group-title">Resources</div>
      <div class="nav-item" onclick="location.href='../shared/research-archive.php'">
        <span class="icon"><i data-lucide="archive"></i></span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/calendar.php'">
        <span class="icon"><i data-lucide="calendar-days"></i></span>
        <span>Calendar</span>
      </div>

      <div class="nav-group-title">Account</div>
      <div class="nav-item" onclick="location.href='../shared/profile.php'">
        <span class="icon"><i data-lucide="circle-user-round"></i></span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #EF4444;">
        <span class="icon"><i data-lucide="log-out"></i></span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">Student</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="page-header">
      <h1>Welcome back, <?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
      <p>Track your research progress and stay connected with your adviser.</p>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_submitted; ?></div>
            <div class="stat-label">Total Submissions</div>
          </div>
          <div class="stat-icon">📋</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_review; ?></div>
            <div class="stat-label">Under Review</div>
          </div>
          <div class="stat-icon">🔍</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_approved; ?></div>
            <div class="stat-label">Approved Chapters</div>
          </div>
          <div class="stat-icon">✅</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_revision; ?></div>
            <div class="stat-label">Needs Revision</div>
          </div>
          <div class="stat-icon">⚠️</div>
        </div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- CHAPTER PROGRESS -->
      <div class="bento-card span-8">
        <div class="card-header">
          <div class="card-title">Chapter Progress</div>
          <div class="card-subtitle">Five-chapter research structure</div>
        </div>

        <div class="chapter-list">
          <?php
          $chapter_titles = [
            1 => ['title' => 'Chapter 1', 'desc' => 'The Problem and Its Background'],
            2 => ['title' => 'Chapter 2', 'desc' => 'Review of Related Literature'],
            3 => ['title' => 'Chapter 3', 'desc' => 'Methodology'],
            4 => ['title' => 'Chapter 4', 'desc' => 'Results and Discussion'],
            5 => ['title' => 'Chapter 5', 'desc' => 'Summary, Conclusions, and Recommendations']
          ];

          foreach ($chapter_titles as $num => $info):
            $status = $chapter_progress[$num] ?? 'draft';
            $status_label = ucwords(str_replace('_', ' ', $status));

            $number_class = '';
            $status_class = 'draft';

            if ($status === 'approved') {
              $number_class = 'completed';
              $status_class = 'approved';
            } elseif ($status === 'under_review') {
              $number_class = 'review';
              $status_class = 'review';
            } elseif ($status === 'revision_required') {
              $number_class = 'revision';
              $status_class = 'revision';
            }
          ?>
            <div class="chapter-item">
              <div class="chapter-number <?php echo $number_class; ?>"><?php echo $num; ?></div>
              <div class="chapter-info">
                <div class="chapter-title"><?php echo htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="chapter-desc"><?php echo htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
              <div class="chapter-status <?php echo $status_class; ?>"><?php echo $status_label; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- WORKFLOW TIMELINE -->
      <div class="bento-card span-4">
        <div class="card-header">
          <div class="card-title">Research Workflow</div>
          <div class="card-subtitle">Current stage</div>
        </div>

        <div class="timeline">
          <div class="timeline-item">
            <div class="timeline-dot completed"></div>
            <div class="timeline-content">
              <h4>Proposal</h4>
              <p>Completed</p>
            </div>
          </div>

          <div class="timeline-item">
            <div class="timeline-dot active"></div>
            <div class="timeline-content">
              <h4>CREC Evaluation</h4>
              <p>In Progress</p>
            </div>
          </div>

          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-content">
              <h4>EREC Forum</h4>
              <p>Pending</p>
            </div>
          </div>

          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-content">
              <h4>Approval</h4>
              <p>Pending</p>
            </div>
          </div>

          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-content">
              <h4>Implementation</h4>
              <p>Upcoming</p>
            </div>
          </div>
        </div>
      </div>

      <!-- RECENT ACTIVITY -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div class="card-title">Recent Activity</div>
          <a href="../shared/notifications.php" class="card-action">View all →</a>
        </div>

        <ul class="activity-list">
          <?php
          if ($notifications->num_rows > 0):
            while ($notif = $notifications->fetch_assoc()):
              $colors = ['success' => '#16A34A', 'error' => '#EF4444', 'warning' => '#EA580C', 'info' => '#2563EB'];
              $color = $colors[$notif['type']] ?? '#2563EB';
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: <?php echo $color; ?>;"></div>
              <div class="activity-content">
                <p><?php echo htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="activity-time"><?php echo date('M d, Y', strtotime($notif['created_at'])); ?></div>
              </div>
            </li>
          <?php
            endwhile;
          else:
          ?>
            <li class="activity-item">
              <div class="activity-content">
                <p style="color: var(--text-muted);">No recent activity</p>
              </div>
            </li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- UPCOMING DEADLINES -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div class="card-title">Upcoming Deadlines</div>
          <a href="../shared/calendar.php" class="card-action">View calendar →</a>
        </div>

        <ul class="activity-list">
          <li class="activity-item">
            <div class="activity-dot" style="background: #EA580C;"></div>
            <div class="activity-content">
              <p><strong>Chapter 3 Submission</strong></p>
              <div class="activity-time">Due in 5 days • Sep 3, 2026</div>
            </div>
          </li>
          <li class="activity-item">
            <div class="activity-dot" style="background: #2563EB;"></div>
            <div class="activity-content">
              <p><strong>CREC Presentation</strong></p>
              <div class="activity-time">Sep 15, 2026</div>
            </div>
          </li>
          <li class="activity-item">
            <div class="activity-dot" style="background: #7C3AED;"></div>
            <div class="activity-content">
              <p><strong>Adviser Meeting</strong></p>
              <div class="activity-time">Sep 20, 2026</div>
            </div>
          </li>
        </ul>
      </div>

      <!-- MY RESEARCH TABLE -->
      <div class="bento-card span-12">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            <div class="card-title">My Research Projects</div>
            <div class="card-subtitle">All submitted research</div>
          </div>
          <a href="submit-research.php" class="btn btn-primary">+ New Research</a>
        </div>

        <?php if ($projects->num_rows > 0): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Submission Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $projects->data_seek(0);
                while ($proj = $projects->fetch_assoc()):
                  $status_class = 'status-' . str_replace('_', '-', strtolower($proj['status']));
                ?>
                  <tr>
                    <td style="font-weight: 500;"><?php echo htmlspecialchars($proj['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo date('M d, Y', strtotime($proj['created_at'])); ?></td>
                    <td>
                      <span class="badge-status <?php echo htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $proj['status'])), ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </td>
                    <td>
                      <a class="btn btn-primary btn-sm" href="view-research.php?id=<?php echo (int)$proj['project_id']; ?>">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-state-icon">📁</div>
            <p>No research submissions yet.</p>
            <br>
            <a href="submit-research.php" class="btn btn-primary">Submit Your First Research</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.lucide) {
      lucide.createIcons();
    } else {
      // Wait for Lucide to load
      setTimeout(function() {
        if (window.lucide) {
          lucide.createIcons();
        }
      }, 100);
    }
  });
</script>
</body>
</html>
