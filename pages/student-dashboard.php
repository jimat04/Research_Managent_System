<?php
include '../includes/config.php';
include '../includes/auth.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Dashboard — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>

<div class="dashboard">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">
        Research<br>Management
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item active" onclick="location.href='student-dashboard.php'">
        <span class="icon">📊</span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item" onclick="location.href='my-research.php'">
        <span class="icon">📁</span>
        <span>My Research</span>
      </div>
      <div class="nav-item" onclick="location.href='submit-research.php'">
        <span class="icon">📤</span>
        <span>Submit Research</span>
      </div>
      <div class="nav-item" onclick="location.href='my-documents.php'">
        <span class="icon">📄</span>
        <span>My Documents</span>
      </div>

      <div class="nav-group-title">TRACKING</div>
      <div class="nav-item" onclick="location.href='progress-tracking.php'">
        <span class="icon">📈</span>
        <span>Progress Tracking</span>
      </div>
      <div class="nav-item" onclick="location.href='messages.php'">
        <span class="icon">💬</span>
        <span>Messages</span>
        <span class="badge">3</span>
      </div>
      <div class="nav-item" onclick="location.href='notifications.php'">
        <span class="icon">🔔</span>
        <span>Notifications</span>
        <?php if ($unread_count > 0): ?>
          <span class="badge"><?php echo $unread_count; ?></span>
        <?php endif; ?>
      </div>

      <div class="nav-group-title">RESOURCES</div>
      <div class="nav-item" onclick="location.href='research-archive.php'">
        <span class="icon">🗂️</span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='calendar.php'">
        <span class="icon">📅</span>
        <span>Calendar</span>
      </div>

      <div class="nav-group-title">ACCOUNT</div>
      <div class="nav-item" onclick="location.href='profile.php'">
        <span class="icon">👤</span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='settings.php'">
        <span class="icon">⚙️</span>
        <span>Settings</span>
      </div>
      <div class="nav-item" onclick="location.href='../logout.php'" style="color: #ef4444;">
        <span class="icon">🚪</span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">🎓 Student</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <h2>Dashboard</h2>
        <p>Welcome back, <?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?>! Here's your research overview.</p>
      </div>

      <div class="topbar-right">
        <div class="search-box">
          <span style="color: #94a3b8;">🔍</span>
          <input type="text" placeholder="Search anything...">
        </div>

        <div class="topbar-icons">
          <div class="icon-btn <?php echo $unread_count > 0 ? 'has-notif' : ''; ?>">
            🔔
          </div>
        </div>

        <div class="user-profile-btn" onclick="alert('Profile menu')">
          <div class="profile-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="profile-role">Student</div>
          </div>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">
      <!-- STAT CARDS -->
      <div class="stats-grid">
        <div class="stat-card purple">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $stat_submitted; ?></div>
              <div class="stat-label">My Submissions</div>
            </div>
            <div class="stat-icon">📋</div>
          </div>
        </div>

        <div class="stat-card blue">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $stat_review; ?></div>
              <div class="stat-label">Under Review</div>
            </div>
            <div class="stat-icon">🔍</div>
          </div>
        </div>

        <div class="stat-card green">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $stat_approved; ?></div>
              <div class="stat-label">Approved</div>
            </div>
            <div class="stat-icon">✅</div>
          </div>
        </div>

        <div class="stat-card orange">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $stat_revision; ?></div>
              <div class="stat-label">Revision Required</div>
            </div>
            <div class="stat-icon">⚠️</div>
          </div>
        </div>
      </div>

      <!-- MAIN GRID -->
      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
        <!-- RESEARCH STATUS OVERVIEW -->
        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Research Status Overview</div>
              <div class="card-subtitle">All submissions</div>
            </div>
            <span class="card-action" onclick="alert('View details')">View All ↗</span>
          </div>
          <div class="card-body">
            <div style="display: flex; gap: 32px; align-items: center;">
              <div class="chart-placeholder">
                <div class="donut-chart">
                  <div class="donut-center">
                    <div class="value" style="color: var(--primary);"><?php echo $stat_submitted; ?></div>
                    <div class="label">Total</div>
                  </div>
                </div>
              </div>

              <div class="chart-legend" style="flex: 1;">
                <div class="legend-item">
                  <div class="legend-dot" style="background: var(--primary);"></div>
                  <span class="legend-label">Under Review</span>
                  <span class="legend-pct"><?php echo $stat_review; ?> (<?php echo $stat_submitted > 0 ? round(($stat_review / $stat_submitted) * 100) : 0; ?>%)</span>
                </div>
                <div class="legend-item">
                  <div class="legend-dot" style="background: var(--success);"></div>
                  <span class="legend-label">Approved</span>
                  <span class="legend-pct"><?php echo $stat_approved; ?> (<?php echo $stat_submitted > 0 ? round(($stat_approved / $stat_submitted) * 100) : 0; ?>%)</span>
                </div>
                <div class="legend-item">
                  <div class="legend-dot" style="background: var(--warning);"></div>
                  <span class="legend-label">Revision Required</span>
                  <span class="legend-pct"><?php echo $stat_revision; ?> (<?php echo $stat_submitted > 0 ? round(($stat_revision / $stat_submitted) * 100) : 0; ?>%)</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Recent Activity</div>
            <span class="card-action" onclick="location.href='notifications.php'">View all ↗</span>
          </div>
          <div class="card-body">
            <ul class="activity-list">
              <?php
              $count = 0;
              while ($count < 5 && ($notif = $notifications->fetch_assoc())):
                $count++;
                $colors = ['success' => '#22c55e', 'error' => '#ef4444', 'warning' => '#f59e0b', 'info' => '#0F6CBD'];
                $color = $colors[$notif['type']] ?? '#0F6CBD';
              ?>
                <li class="activity-item">
                  <div class="activity-dot" style="background: <?php echo $color; ?>;"></div>
                  <div class="activity-content">
                    <p><?php echo htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="time"><?php echo date('M d, Y', strtotime($notif['created_at'])); ?></div>
                  </div>
                </li>
              <?php endwhile; ?>
            </ul>
          </div>
        </div>
      </div>

      <!-- RESEARCH TABLE & DEADLINES -->
      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
        <!-- MY RESEARCH TABLE -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">My Research</div>
            <button class="btn btn-primary btn-sm" onclick="location.href='submit-research.php'">+ New Submission</button>
          </div>
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
                if ($projects->num_rows > 0):
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
                      <a class="btn btn-accent btn-sm" href="view-research.php?id=<?php echo (int)$proj['project_id']; ?>">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 20px;">No research submissions yet.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- UPCOMING DEADLINES -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Upcoming Deadlines</div>
            <span class="card-action" onclick="location.href='calendar.php'">View Calendar ↗</span>
          </div>
          <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
              <div style="padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: #f8fafc;">
                <div style="font-weight: 500; font-size: 0.875rem; color: var(--text-dark);">📋 Chapter 1 Submission</div>
                <div style="font-size: 0.75rem; color: var(--info); margin-top: 4px;">📅 May 30, 2024</div>
              </div>
              <div style="padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: #f8fafc;">
                <div style="font-weight: 500; font-size: 0.875rem; color: var(--text-dark);">📄 Final Paper Submission</div>
                <div style="font-size: 0.75rem; color: var(--info); margin-top: 4px;">📅 June 15, 2024</div>
              </div>
              <div style="padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: #f8fafc;">
                <div style="font-weight: 500; font-size: 0.875rem; color: var(--text-dark);">🎤 Oral Defense</div>
                <div style="font-size: 0.75rem; color: var(--info); margin-top: 4px;">📅 June 28, 2024</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
  });
});
</script>
</body>
</html>