<?php
include '../includes/config.php';
include '../includes/auth.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Faculty Dashboard — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>

<div class="dashboard">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">
        Research<br>Management
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item active" onclick="location.href='faculty-dashboard.php'">
        <span class="icon">📊</span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-submissions.php'">
        <span class="icon">📥</span>
        <span>Submissions</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-review.php'">
        <span class="icon">🔍</span>
        <span>Review Queue</span>
        <span class="badge"><?php echo htmlspecialchars((string) $stat_pending, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-students.php'">
        <span class="icon">👨‍🎓</span>
        <span>My Students</span>
      </div>

      <div class="nav-group-title">COMMUNICATION</div>
      <div class="nav-item" onclick="location.href='messages.php'">
        <span class="icon">💬</span>
        <span>Messages</span>
        <span class="badge">3</span>
      </div>
      <div class="nav-item" onclick="location.href='notifications.php'">
        <span class="icon">🔔</span>
        <span>Notifications</span>
      </div>

      <div class="nav-group-title">RESOURCES</div>
      <div class="nav-item" onclick="location.href='research-archive.php'">
        <span class="icon">🗂️</span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='faculty-reports.php'">
        <span class="icon">📊</span>
        <span>Reports</span>
      </div>

      <div class="nav-group-title">ACCOUNT</div>
      <div class="nav-item" onclick="location.href='profile.php'">
        <span class="icon">👤</span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../logout.php'" style="color: #ef4444;">
        <span class="icon">🚪</span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar" style="background: linear-gradient(135deg, var(--secondary), var(--accent));"><?php echo htmlspecialchars(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">👨‍🏫 Faculty</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <h2>Faculty Dashboard</h2>
        <p>Good day, Prof. <?php echo htmlspecialchars($user['last_name'], ENT_QUOTES, 'UTF-8'); ?>! You have <?php echo htmlspecialchars((string) $stat_pending, ENT_QUOTES, 'UTF-8'); ?> submissions awaiting review.</p>
      </div>

      <div class="topbar-right">
        <div class="search-box">
          <span style="color: #94a3b8;">🔍</span>
          <input type="text" placeholder="Search submissions...">
        </div>

        <div class="topbar-icons">
          <div class="icon-btn has-notif">🔔</div>
        </div>

        <div class="user-profile-btn" onclick="alert('Profile menu')">
          <div class="profile-avatar" style="background: linear-gradient(135deg, var(--secondary), var(--accent));"><?php echo htmlspecialchars(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="profile-role" style="color: var(--secondary);">Faculty</div>
          </div>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">
      <!-- STAT CARDS -->
      <div class="stats-grid">
        <div class="stat-card blue">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo htmlspecialchars((string) $stat_pending, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="stat-label">Pending Reviews</div>
            </div>
            <div class="stat-icon">📥</div>
          </div>
        </div>

        <div class="stat-card green">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo htmlspecialchars((string) $stat_approved, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="stat-label">Approved</div>
            </div>
            <div class="stat-icon">✅</div>
          </div>
        </div>

        <div class="stat-card orange">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo htmlspecialchars((string) $stat_revision, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="stat-label">Revision Required</div>
            </div>
            <div class="stat-icon">🔄</div>
          </div>
        </div>

        <div class="stat-card purple">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo htmlspecialchars((string) $stat_students, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-icon">👨‍🎓</div>
          </div>
        </div>
      </div>

      <!-- REVIEW QUEUE -->
      <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
          <div>
            <div class="card-title">Review Queue</div>
            <div class="card-subtitle">Submissions awaiting your review</div>
          </div>
          <button class="btn btn-primary btn-sm" onclick="location.href='faculty-review.php'">View All Submissions ↗</button>
        </div>
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
              if ($assigned->num_rows > 0):
                $assigned->data_seek(0);
                while ($proj = $assigned->fetch_assoc()):
              ?>
                <tr>
                  <td style="font-weight: 500;"><?php echo htmlspecialchars($proj['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($proj['student_first_name'] . ' ' . $proj['student_last_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars(date('M d, Y', strtotime($proj['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <span class="badge-status status-review">
                      <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $proj['status'])), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  </td>
                  <td>
                    <a class="btn btn-primary btn-sm" href="faculty-review-detail.php?id=<?php echo (int)$proj['project_id']; ?>">Review</a>
                  </td>
                </tr>
              <?php
                endwhile;
              else:
              ?>
                <tr>
                  <td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);">
                    No submissions assigned yet
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- BOTTOM GRID -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- RECENT ACTIVITIES -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Recent Activities</div>
          </div>
          <div class="card-body">
            <ul class="activity-list">
              <?php if ($activities->num_rows > 0): ?>
                <?php while ($activity = $activities->fetch_assoc()): ?>
                  <?php
                  $activity_action = strtolower($activity['action']);
                  $activity_color = 'var(--primary)';
                  if (strpos($activity_action, 'approved') !== false) {
                      $activity_color = 'var(--success)';
                  } elseif (strpos($activity_action, 'revision') !== false || strpos($activity_action, 'rejected') !== false) {
                      $activity_color = 'var(--warning)';
                  } elseif (strpos($activity_action, 'logged in') !== false || strpos($activity_action, 'submitted') !== false || strpos($activity_action, 'created') !== false) {
                      $activity_color = 'var(--info)';
                  }
                  ?>
                  <li class="activity-item">
                    <div class="activity-dot" style="background: <?php echo htmlspecialchars($activity_color, ENT_QUOTES, 'UTF-8'); ?>;"></div>
                    <div class="activity-content">
                      <p><?php echo htmlspecialchars($activity['action'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <div class="time"><?php echo htmlspecialchars(date('M d, Y • h:i A', strtotime($activity['created_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                  </li>
                <?php endwhile; ?>
              <?php else: ?>
                <li class="activity-item">
                  <div class="activity-content">
                    <p style="color: var(--text-muted);">No recent activity yet.</p>
                  </div>
                </li>
              <?php endif; ?>
            </ul>
          </div>
        </div>

        <!-- SUBMISSIONS OVERVIEW -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Submissions Overview</div>
            <div class="card-subtitle">This Year</div>
          </div>
          <div class="card-body">
            <div style="display: flex; justify-content: center; margin-bottom: 20px;">
              <div class="donut-chart" style="background: conic-gradient(var(--secondary) 0deg 180deg, var(--accent) 180deg 252deg, var(--danger) 252deg 288deg, var(--success) 288deg 360deg);">
                <div class="donut-center">
                  <div class="value"><?php echo htmlspecialchars((string) ($stat_approved + $stat_pending + $stat_revision), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="label">Total</div>
                </div>
              </div>
            </div>
            <div class="chart-legend">
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--secondary);"></div>
                <span class="legend-label">Approved</span>
                <span class="legend-pct"><?php echo htmlspecialchars((string) $stat_approved, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--accent);"></div>
                <span class="legend-label">Pending Review</span>
                <span class="legend-pct"><?php echo htmlspecialchars((string) $stat_pending, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--danger);"></div>
                <span class="legend-label">Revision Needed</span>
                <span class="legend-pct"><?php echo htmlspecialchars((string) $stat_revision, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>