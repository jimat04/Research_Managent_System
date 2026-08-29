<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

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
    background: #F1F5F9;
    color: #475569;
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-archive',
    'Research Archive',
    'Completed and archived research projects.'
);
?>

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
                <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
                  No archived research projects found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php
renderAdminShellClose();
