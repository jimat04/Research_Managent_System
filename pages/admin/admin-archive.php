<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();

// Get archived research with filters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';

$where_clauses = ["rp.status = 'archived'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($department_filter)) {
    $where_clauses[] = "u.department = ?";
    $params[] = $department_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_clauses);

$query = "
    SELECT rp.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           u.department,
           CONCAT(f.first_name, ' ', f.last_name) as adviser_name
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN project_advisers pa ON rp.project_id = pa.project_id
    LEFT JOIN users f ON pa.adviser_id = f.user_id
    WHERE {$where_sql}
    ORDER BY rp.updated_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects = $stmt->get_result();

// Get statistics
$total_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

// Get departments for filter
$departments = $conn->query("
    SELECT DISTINCT u.department
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE rp.status = 'archived' AND u.department IS NOT NULL AND u.department != ''
    ORDER BY u.department
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Archive — Admin — RMS</title>
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
      background: #F1F5F9;
      color: #475569;
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
      <div class="nav-item active" onclick="location.href='admin-archive.php'">
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
      <div class="nav-item" onclick="location.href='admin-logs.php'">
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
      <h1>Research Archive</h1>
      <p>Completed and archived research projects.</p>
    </div>

    <!-- STAT -->
    <div class="stat-card">
      <div class="stat-number"><?php echo $total_archived; ?></div>
      <div class="stat-label">Archived Research Projects</div>
    </div>

    <!-- ARCHIVE CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Archived Projects</div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-archive.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by title or student name..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="department" class="filter-select" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php while ($dept = $departments->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($dept['department'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($dept['department'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($department_filter)): ?>
          <a href="admin-archive.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Student</th>
              <th>Department</th>
              <th>Adviser</th>
              <th>Archived</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($projects->num_rows > 0): ?>
              <?php while ($project = $projects->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight: 500;">
                    <?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td><?php echo htmlspecialchars($project['student_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($project['department'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="white-space: nowrap;">
                    <?php echo date('M d, Y', strtotime($project['updated_at'])); ?>
                  </td>
                  <td>
                    <a href="../shared/research-detail.php?id=<?php echo $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted);">
                  No archived research projects found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</body>
</html>
