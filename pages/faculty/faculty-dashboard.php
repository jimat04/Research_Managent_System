<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('faculty');

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Get assigned research projects
$assigned_stmt = $conn->prepare("
    SELECT rp.*, student.first_name AS student_first_name, student.last_name AS student_last_name
    FROM research_projects rp
    JOIN project_advisers pa ON rp.project_id = pa.project_id
    JOIN users student ON student.user_id = rp.created_by
    WHERE pa.adviser_id = ?
    ORDER BY rp.created_at DESC
");
$assigned_stmt->bind_param('i', $user_id);
$assigned_stmt->execute();
$assigned = $assigned_stmt->get_result();

// Get statistics
$pending_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'under_review'
");
$pending_stmt->bind_param('i', $user_id);
$pending_stmt->execute();
$stat_pending = (int) ($pending_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$approved_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'approved'
");
$approved_stmt->bind_param('i', $user_id);
$approved_stmt->execute();
$stat_approved = (int) ($approved_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$revision_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'revision_required'
");
$revision_stmt->bind_param('i', $user_id);
$revision_stmt->execute();
$stat_revision = (int) ($revision_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$students_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT rp.created_by) as count FROM research_projects rp
    JOIN project_advisers pa ON rp.project_id = pa.project_id
    WHERE pa.adviser_id = ?
");
$students_stmt->bind_param('i', $user_id);
$students_stmt->execute();
$stat_students = (int) ($students_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$activity_stmt = $conn->prepare("SELECT action, module, created_at
  FROM activity_log
  WHERE user_id = ?
  ORDER BY created_at DESC
  LIMIT 5");
$activity_stmt->bind_param('i', $user_id);
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// Get recent submissions (last 5)
$recent_stmt = $conn->prepare("
    SELECT c.*, rp.title as project_title, u.first_name, u.last_name
    FROM chapters c
    JOIN research_projects rp ON c.project_id = rp.project_id
    JOIN users u ON rp.created_by = u.user_id
    WHERE rp.project_id IN (
        SELECT rp2.project_id FROM research_projects rp2
        JOIN project_advisers pa ON rp2.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    )
    ORDER BY c.submitted_at DESC
    LIMIT 5
");
$recent_stmt->bind_param('i', $user_id);
$recent_stmt->execute();
$recent_submissions = $recent_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Dashboard — RMS</title>
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

    /* PROGRESS BAR */
    .progress-bar-container {
      margin-bottom: 24px;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
      font-size: 13px;
    }

    .progress-label {
      font-weight: 500;
      color: var(--text-secondary);
    }

    .progress-value {
      font-weight: 600;
      color: var(--charcoal);
    }

    .progress-bar {
      height: 8px;
      background: var(--bg-surface);
      border-radius: 10px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      border-radius: 10px;
      transition: width 0.3s;
    }

    .progress-fill.blue { background: var(--status-proposal); }
    .progress-fill.green { background: var(--status-approved); }
    .progress-fill.orange { background: var(--status-revision); }
    .progress-fill.purple { background: var(--status-erec); }

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
      <div class="sidebar-role">Faculty Portal</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Main</div>
      <div class="nav-item active" onclick="location.href='faculty-dashboard.php'">
        <span>📊</span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-submissions.php'">
        <span>📥</span>
        <span>Submissions</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-review.php'">
        <span>🔍</span>
        <span>Review Queue</span>
        <?php if ($stat_pending > 0): ?>
          <span class="badge"><?php echo $stat_pending; ?></span>
        <?php endif; ?>
      </div>
      <div class="nav-item" onclick="location.href='faculty-students.php'">
        <span>👨‍🎓</span>
        <span>My Students</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span>💬</span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/notifications.php'">
        <span>🔔</span>
        <span>Notifications</span>
      </div>

      <div class="nav-group-title">Resources</div>
      <div class="nav-item" onclick="location.href='../shared/research-archive.php'">
        <span>🗂️</span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-reports.php'">
        <span>📊</span>
        <span>Reports</span>
      </div>

      <div class="nav-group-title">Account</div>
      <div class="nav-item" onclick="location.href='../shared/profile.php'">
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
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">Faculty Adviser</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="page-header">
      <h1>Good day, Prof. <?php echo htmlspecialchars($user['last_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
      <p>You have <?php echo $stat_pending; ?> submission<?php echo $stat_pending !== 1 ? 's' : ''; ?> awaiting your review.</p>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_pending; ?></div>
            <div class="stat-label">Pending Reviews</div>
          </div>
          <div class="stat-icon">📥</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_approved; ?></div>
            <div class="stat-label">Approved</div>
          </div>
          <div class="stat-icon">✅</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_revision; ?></div>
            <div class="stat-label">Revision Required</div>
          </div>
          <div class="stat-icon">🔄</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $stat_students; ?></div>
            <div class="stat-label">Total Advisees</div>
          </div>
          <div class="stat-icon">👨‍🎓</div>
        </div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- REVIEW QUEUE -->
      <div class="bento-card span-12">
        <div class="card-header">
          <div>
            <div class="card-title">Review Queue</div>
            <div class="card-subtitle">Research projects assigned to you</div>
          </div>
          <a href="faculty-review.php" class="btn btn-primary">View All Reviews</a>
        </div>

        <?php if ($assigned->num_rows > 0): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Research Title</th>
                  <th>Student</th>
                  <th>Date Submitted</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $assigned->data_seek(0);
                $count = 0;
                while ($proj = $assigned->fetch_assoc()):
                  if ($count >= 5) break;
                  $count++;
                ?>
                  <tr>
                    <td style="font-weight: 500;"><?php echo htmlspecialchars($proj['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($proj['student_first_name'] . ' ' . $proj['student_last_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo date('M d, Y', strtotime($proj['created_at'])); ?></td>
                    <td>
                      <span class="badge-status status-under-review">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $proj['status'])), ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </td>
                    <td>
                      <a class="btn btn-primary btn-sm" href="faculty-review-detail.php?id=<?php echo (int)$proj['project_id']; ?>">Review</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            <p>No research projects assigned yet.</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- RECENT SUBMISSIONS -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Recent Submissions</div>
            <div class="card-subtitle">Latest chapter submissions</div>
          </div>
          <a href="faculty-submissions.php" class="card-action">View all →</a>
        </div>

        <ul class="activity-list">
          <?php
          if ($recent_submissions->num_rows > 0):
            while ($sub = $recent_submissions->fetch_assoc()):
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: #2563EB;"></div>
              <div class="activity-content">
                <p><strong><?php echo htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                <p style="font-size: 13px; color: var(--text-secondary);">Chapter <?php echo $sub['chapter_number']; ?> — <?php echo htmlspecialchars($sub['project_title'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="activity-time"><?php echo date('M d, Y', strtotime($sub['created_at'])); ?></div>
              </div>
            </li>
          <?php
            endwhile;
          else:
          ?>
            <li class="activity-item">
              <div class="activity-content">
                <p style="color: var(--text-muted);">No recent submissions</p>
              </div>
            </li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- REVIEW ANALYTICS -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Review Analytics</div>
            <div class="card-subtitle">Your review activity</div>
          </div>
        </div>

        <div class="progress-bar-container">
          <div class="progress-header">
            <span class="progress-label">Approved Chapters</span>
            <span class="progress-value"><?php echo $stat_approved; ?></span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill green" style="width: <?php echo min(100, ($stat_approved / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
          </div>
        </div>

        <div class="progress-bar-container">
          <div class="progress-header">
            <span class="progress-label">Pending Review</span>
            <span class="progress-value"><?php echo $stat_pending; ?></span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill blue" style="width: <?php echo min(100, ($stat_pending / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
          </div>
        </div>

        <div class="progress-bar-container">
          <div class="progress-header">
            <span class="progress-label">Revision Required</span>
            <span class="progress-value"><?php echo $stat_revision; ?></span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill orange" style="width: <?php echo min(100, ($stat_revision / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
          </div>
        </div>

        <div class="progress-bar-container" style="margin-bottom: 0;">
          <div class="progress-header">
            <span class="progress-label">Total Advisees</span>
            <span class="progress-value"><?php echo $stat_students; ?></span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill purple" style="width: <?php echo min(100, ($stat_students / 10) * 100); ?>%;"></div>
          </div>
        </div>
      </div>

      <!-- RECENT ACTIVITY -->
      <div class="bento-card span-12">
        <div class="card-header">
          <div>
            <div class="card-title">Your Recent Activity</div>
            <div class="card-subtitle">Latest actions and updates</div>
          </div>
        </div>

        <ul class="activity-list">
          <?php
          if ($activities->num_rows > 0):
            while ($activity = $activities->fetch_assoc()):
              $activity_action = strtolower($activity['action']);
              $activity_color = '#2563EB';
              if (strpos($activity_action, 'approved') !== false) {
                  $activity_color = '#16A34A';
              } elseif (strpos($activity_action, 'revision') !== false || strpos($activity_action, 'rejected') !== false) {
                  $activity_color = '#EA580C';
              }
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: <?php echo $activity_color; ?>;"></div>
              <div class="activity-content">
                <p><?php echo htmlspecialchars($activity['action'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="activity-time"><?php echo date('M d, Y • h:i A', strtotime($activity['created_at'])); ?></div>
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
    </div>
  </div>
</div>

</body>
</html>
