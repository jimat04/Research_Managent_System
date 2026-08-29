<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$user_filter = $_GET['user'] ?? '';
$module_filter = $_GET['module'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($user_filter)) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
    $types .= 'i';
}

if (!empty($module_filter)) {
    $where_clauses[] = "al.module = ?";
    $params[] = $module_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_clauses[] = "al.action LIKE ?";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get activity logs
$stmt = $conn->prepare("
    SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) as user_name, u.email, u.role
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    {$where_sql}
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result();

// Get total count
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);
$count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_log al {$where_sql}");
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_logs = $count_stmt->get_result()->fetch_assoc()['count'];
$total_pages = ceil($total_logs / $per_page);

// Get unique modules for filter
$modules = $conn->query("SELECT DISTINCT module FROM activity_log WHERE module IS NOT NULL ORDER BY module");

// Get total log count
$total_log_count = (int) ($conn->query("SELECT COUNT(*) as count FROM activity_log")->fetch_assoc()['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Logs — Admin — RMS</title>
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

    /* STATS */
    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 32px;
      display: inline-block;
    }

    .stat-number {
      font-size: 32px;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 8px;
    }

    .stat-label {
      font-size: 14px;
      color: var(--text-secondary);
      font-weight: 500;
    }

    /* CARD */
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 32px;
      margin-bottom: 24px;
    }

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }

    .card-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--charcoal);
    }

    /* FILTER BAR */
    .filter-bar {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .search-input {
      flex: 1;
      min-width: 250px;
      padding: 10px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      font-size: 14px;
      background: var(--bg-surface);
    }

    .filter-select {
      padding: 10px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      font-size: 14px;
      background: var(--bg-card);
      cursor: pointer;
    }

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

    .btn-secondary {
      background: var(--bg-surface);
      color: var(--text-primary);
      border: 1px solid var(--border);
    }

    .btn-secondary:hover {
      background: #E5E7EB;
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 13px;
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
      font-size: 12px;
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

    /* BADGE */
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
    }

    .badge-student { background: #DBEAFE; color: #2563EB; }
    .badge-faculty { background: #F3E8FF; color: #7C3AED; }
    .badge-staff { background: #FEF3C7; color: #D97706; }
    .badge-admin { background: #FEE2E2; color: #DC2626; }

    /* PAGINATION */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 12px;
      margin-top: 24px;
      padding: 24px;
      border-top: 1px solid var(--border);
    }

    /* RESPONSIVE */
    @media (max-width: 768px) {
      .sidebar {
        display: none;
      }

      .main-content {
        margin-left: 0;
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
      <div class="sidebar-role">Administrator</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Overview</div>
      <div class="nav-item" onclick="location.href='admin-dashboard.php'">
        <span>📊</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-group-title">Management</div>
      <div class="nav-item" onclick="location.href='admin-users.php'">
        <span>👥</span>
        <span>User Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-research.php'">
        <span>📁</span>
        <span>Research Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-archive.php'">
        <span>🗂️</span>
        <span>Archive</span>
      </div>

      <div class="nav-group-title">Analytics</div>
      <div class="nav-item" onclick="location.href='admin-reports.php'">
        <span>📈</span>
        <span>Reports & Analytics</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span>💬</span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-contact.php'">
        <span>📨</span>
        <span>Contact Messages</span>
      </div>

      <div class="nav-group-title">System</div>
      <div class="nav-item active" onclick="location.href='admin-logs.php'">
        <span>⚙️</span>
        <span>System Logs</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-backup.php'">
        <span>💾</span>
        <span>Backup</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/notifications.php'">
        <span>🔔</span>
        <span>Notifications</span>
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
          <div class="user-role">Administrator</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="page-header">
      <h1>System Logs</h1>
      <p>Activity audit trail across the platform.</p>
    </div>

    <!-- STAT -->
    <div class="stat-card">
      <div class="stat-number"><?php echo number_format($total_log_count); ?></div>
      <div class="stat-label">Total Activity Logs</div>
    </div>

    <!-- LOGS CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Activity Log</div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-logs.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search actions..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

        <select name="module" class="filter-select" onchange="this.form.submit()">
          <option value="">All Modules</option>
          <?php while ($mod = $modules->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($mod['module'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $module_filter === $mod['module'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $mod['module'])), ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endwhile; ?>
        </select>

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($module_filter) || !empty($user_filter)): ?>
          <a href="admin-logs.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Role</th>
              <th>Module</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($logs->num_rows > 0): ?>
              <?php while ($log = $logs->fetch_assoc()): ?>
                <tr>
                  <td style="white-space: nowrap; font-size: 13px;">
                    <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                  </td>
                  <td style="font-weight: 500;">
                    <?php echo htmlspecialchars($log['user_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td>
                    <?php
                    $role_badges = [
                      'student' => 'badge-student',
                      'faculty' => 'badge-faculty',
                      'research_staff' => 'badge-staff',
                      'admin' => 'badge-admin'
                    ];
                    $role_labels = [
                      'student' => 'Student',
                      'faculty' => 'Faculty',
                      'research_staff' => 'Staff',
                      'admin' => 'Admin'
                    ];
                    $badge_class = $role_badges[$log['role']] ?? 'badge-student';
                    $role_label = $role_labels[$log['role']] ?? ucfirst($log['role']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $role_label; ?></span>
                  </td>
                  <td style="font-size: 13px; color: var(--text-secondary);">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['module'] ?? 'N/A')), ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 32px; color: var(--text-muted);">
                  No activity logs found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $module_filter ? '&module=' . urlencode($module_filter) : ''; ?>" class="btn btn-sm btn-secondary">← Previous</a>
          <?php endif; ?>

          <span style="padding: 8px 16px; color: var(--text-secondary);">
            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
          </span>

          <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $module_filter ? '&module=' . urlencode($module_filter) : ''; ?>" class="btn btn-sm btn-secondary">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
