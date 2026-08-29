<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

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

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS */
  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
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
    color: var(--text-secondary, #64748B);
    font-weight: 500;
  }

  /* CARD */
  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
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
    color: var(--charcoal, #111827);
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
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-surface, #F8FAFC);
  }

  .filter-select {
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-card, #FFFFFF);
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
    background: var(--bg-surface, #F8FAFC);
    color: var(--text-primary, #111827);
    border: 1px solid var(--border, #E5E7EB);
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
    border: 1px solid var(--border, #E5E7EB);
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead {
    background: var(--bg-surface, #F8FAFC);
  }

  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748B);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid var(--border, #E5E7EB);
  }

  tr:hover {
    background: var(--bg-surface, #F8FAFC);
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
    border-top: 1px solid var(--border, #E5E7EB);
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-logs',
    'System Logs',
    'Activity audit trail across the platform.'
);
?>

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
                  <td style="font-size: 13px; color: var(--text-secondary, #64748B);">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['module'] ?? 'N/A')), ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
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

          <span style="padding: 8px 16px; color: var(--text-secondary, #64748B);">
            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
          </span>

          <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $module_filter ? '&module=' . urlencode($module_filter) : ''; ?>" class="btn btn-sm btn-secondary">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

<?php
renderAdminShellClose();
