<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();

// Get statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0);
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$total_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

$total_students = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'] ?? 0);
$total_faculty = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'faculty'")->fetch_assoc()['count'] ?? 0);
$total_admins = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'] ?? 0);

$research_pending = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('draft', 'submitted')")->fetch_assoc()['count'] ?? 0);
$research_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('approved', 'completed')")->fetch_assoc()['count'] ?? 0);
$research_rejected = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'rejected'")->fetch_assoc()['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>

<div class="dashboard">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--accent), #FF9800); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">
        Research<br>Management<br><small style="font-size: 0.65rem; color: #8B8FAD;">Admin</small>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">OVERVIEW</div>
      <div class="nav-item active" onclick="location.href='admin-dashboard.php'">
        <span class="icon">📊</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-group-title">MANAGEMENT</div>
      <div class="nav-item" onclick="location.href='admin-users.php'">
        <span class="icon">👥</span>
        <span>User Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-research.php'">
        <span class="icon">📁</span>
        <span>Research Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-archive.php'">
        <span class="icon">🗂️</span>
        <span>Archive Management</span>
      </div>

      <div class="nav-group-title">ANALYTICS</div>
      <div class="nav-item" onclick="location.href='admin-reports.php'">
        <span class="icon">📈</span>
        <span>Reports & Analytics</span>
      </div>

      <div class="nav-group-title">SYSTEM</div>
      <div class="nav-item" onclick="location.href='admin-logs.php'">
        <span class="icon">⚙️</span>
        <span>System Logs</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-backup.php'">
        <span class="icon">💾</span>
        <span>Backup</span>
      </div>
      <div class="nav-item" onclick="location.href='notifications.php'">
        <span class="icon">🔔</span>
        <span>Notifications</span>
        <span class="badge info">3</span>
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
        <div class="user-avatar" style="background: linear-gradient(135deg, var(--accent), #FF9800);"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">⚙️ Administrator</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <h2>Admin Dashboard</h2>
        <p>System overview and management controls</p>
      </div>

      <div class="topbar-right">
        <div class="search-box">
          <span style="color: #94a3b8;">🔍</span>
          <input type="text" placeholder="Search anything...">
        </div>

        <div class="topbar-icons">
          <div class="icon-btn has-notif">🔔</div>
        </div>

        <div class="user-profile-btn" onclick="alert('Profile menu')">
          <div class="profile-avatar" style="background: linear-gradient(135deg, var(--accent), #FF9800);"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="profile-role" style="color: var(--accent);">Administrator</div>
          </div>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">
      <!-- KPI CARDS -->
      <div class="stats-grid">
        <div class="stat-card purple">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $total_users; ?></div>
              <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-icon">👥</div>
          </div>
        </div>

        <div class="stat-card blue">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $total_research; ?></div>
              <div class="stat-label">Total Research</div>
            </div>
            <div class="stat-icon">📁</div>
          </div>
        </div>

        <div class="stat-card green">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo $total_archived; ?></div>
              <div class="stat-label">Archived Research</div>
            </div>
            <div class="stat-icon">🗂️</div>
          </div>
        </div>

        <div class="stat-card orange">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number" style="font-size: 1.4rem;">🟢</div>
              <div class="stat-label">System Online</div>
            </div>
            <div class="stat-icon">✓</div>
          </div>
        </div>
      </div>

      <!-- MAIN GRID -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <!-- USER DISTRIBUTION -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">User Role Distribution</div>
          </div>
          <div class="card-body">
            <div style="display: flex; justify-content: center; margin-bottom: 20px;">
              <div class="donut-chart" style="background: conic-gradient(var(--primary) 0deg 252deg, var(--secondary) 252deg 324deg, var(--accent) 324deg 360deg);">
                <div class="donut-center">
                  <div class="value"><?php echo $total_users; ?></div>
                  <div class="label">Users</div>
                </div>
              </div>
            </div>
            <div class="chart-legend">
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--primary);"></div>
                <span class="legend-label">Students</span>
                <span class="legend-pct"><?php echo $total_students; ?> (<?php echo $total_users > 0 ? round(($total_students / $total_users) * 100) : 0; ?>%)</span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--secondary);"></div>
                <span class="legend-label">Faculty</span>
                <span class="legend-pct"><?php echo $total_faculty; ?> (<?php echo $total_users > 0 ? round(($total_faculty / $total_users) * 100) : 0; ?>%)</span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--accent);"></div>
                <span class="legend-label">Admins</span>
                <span class="legend-pct"><?php echo $total_admins; ?> (<?php echo $total_users > 0 ? round(($total_admins / $total_users) * 100) : 0; ?>%)</span>
              </div>
            </div>
          </div>
        </div>

        <!-- RESEARCH STATUS -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Research by Status</div>
            <div class="card-subtitle">Total across all users</div>
          </div>
          <div class="card-body">
            <div style="display: flex; justify-content: center; margin-bottom: 20px;">
              <div class="donut-chart" style="background: conic-gradient(var(--primary) 0deg 115deg, var(--success) 115deg 219deg, var(--warning) 219deg 283deg, var(--danger) 283deg 360deg);">
                <div class="donut-center">
                  <div class="value"><?php echo $total_research; ?></div>
                  <div class="label">Total</div>
                </div>
              </div>
            </div>
            <div class="chart-legend">
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--primary);"></div>
                <span class="legend-label">In Progress / Submitted</span>
                <span class="legend-pct"><?php echo $research_pending; ?></span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--success);"></div>
                <span class="legend-label">Completed / Approved</span>
                <span class="legend-pct"><?php echo $research_approved; ?></span>
              </div>
              <div class="legend-item">
                <div class="legend-dot" style="background: var(--warning);"></div>
                <span class="legend-label">Other Statuses</span>
                <span class="legend-pct"><?php echo max(0, $total_research - $research_approved - $research_pending); ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- BOTTOM SECTION -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- SYSTEM ACTIVITY -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">System Activity (This Month)</div>
            <span class="card-action" onclick="location.href='admin-logs.php'">View Logs ↗</span>
          </div>
          <div class="card-body">
            <div style="margin-bottom: 16px;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.85rem; color: #64748b;">New Submissions</span>
                <span style="font-size: 0.85rem; font-weight: 600;">+34</span>
              </div>
              <div style="height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                <div style="width: 68%; height: 100%; background: var(--primary); border-radius: 4px;"></div>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.85rem; color: #64748b;">Research Approved</span>
                <span style="font-size: 0.85rem; font-weight: 600;">+24</span>
              </div>
              <div style="height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                <div style="width: 48%; height: 100%; background: var(--success); border-radius: 4px;"></div>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.85rem; color: #64748b;">New Users Registered</span>
                <span style="font-size: 0.85rem; font-weight: 600;">+12</span>
              </div>
              <div style="height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                <div style="width: 24%; height: 100%; background: var(--secondary); border-radius: 4px;"></div>
              </div>
            </div>
            <div>
              <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.85rem; color: #64748b;">Archived Studies</span>
                <span style="font-size: 0.85rem; font-weight: 600;">+8</span>
              </div>
              <div style="height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                <div style="width: 16%; height: 100%; background: var(--accent); border-radius: 4px;"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- RECENT LOGINS -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Recent Logins</div>
            <span class="card-action" onclick="location.href='admin-logs.php'">View All ↗</span>
          </div>
          <div class="card-body">
            <ul class="activity-list">
              <li class="activity-item" style="padding: 12px 0;">
                <div class="activity-dot" style="background: var(--success); margin-top: 2px;"></div>
                <div class="activity-content">
                  <p style="font-weight: 500; color: var(--text-dark);">Juan Dela Cruz <span style="color: #94a3b8; font-size: 0.8rem;">• Student</span></p>
                  <div class="time">2 mins ago</div>
                </div>
                <span class="badge-status status-approved" style="font-size: 0.7rem;">Active</span>
              </li>
              <li class="activity-item" style="padding: 12px 0;">
                <div class="activity-dot" style="background: var(--success); margin-top: 2px;"></div>
                <div class="activity-content">
                  <p style="font-weight: 500; color: var(--text-dark);">Prof. Maria Santos <span style="color: #94a3b8; font-size: 0.8rem;">• Faculty</span></p>
                  <div class="time">15 mins ago</div>
                </div>
                <span class="badge-status status-approved" style="font-size: 0.7rem;">Active</span>
              </li>
              <li class="activity-item" style="padding: 12px 0;">
                <div class="activity-dot" style="background: var(--success); margin-top: 2px;"></div>
                <div class="activity-content">
                  <p style="font-weight: 500; color: var(--text-dark);">Admin User <span style="color: #94a3b8; font-size: 0.8rem;">• Administrator</span></p>
                  <div class="time">1 hour ago</div>
                </div>
                <span class="badge-status status-approved" style="font-size: 0.7rem;">Active</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>