<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get research statistics
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$research_draft = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'draft'")->fetch_assoc()['count'] ?? 0);
$research_proposal = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'proposal'")->fetch_assoc()['count'] ?? 0);
$research_crec = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('under_crec_review')")->fetch_assoc()['count'] ?? 0);
$research_erec = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('under_erec_review')")->fetch_assoc()['count'] ?? 0);
$research_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'approved'")->fetch_assoc()['count'] ?? 0);
$research_ongoing = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'in_progress'")->fetch_assoc()['count'] ?? 0);
$research_completed = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'completed'")->fetch_assoc()['count'] ?? 0);
$research_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

// Get all research projects with filters
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($status_filter)) {
    $where_clauses[] = "rp.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "
    SELECT rp.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           CONCAT(f.first_name, ' ', f.last_name) as adviser_name
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN project_advisers pa ON rp.project_id = pa.project_id
    LEFT JOIN users f ON pa.adviser_id = f.user_id
    {$where_sql}
    ORDER BY rp.created_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects = $stmt->get_result();

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 16px;
    padding: 20px;
    transition: all 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
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

  .badge-draft { background: #F1F5F9; color: #64748B; }
  .badge-proposal { background: #DBEAFE; color: #2563EB; }
  .badge-crec { background: #DBEAFE; color: #3B82F6; }
  .badge-erec { background: #F3E8FF; color: #7C3AED; }
  .badge-approved { background: #DCFCE7; color: #16A34A; }
  .badge-ongoing { background: #D1FAE5; color: #059669; }
  .badge-completed { background: #D1FAE5; color: #059669; }
  .badge-archived { background: #F1F5F9; color: #475569; }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-research',
    'Research Management',
    'Oversee all research projects across the university.'
);
?>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_research; ?></div>
        <div class="stat-label">Total Projects</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_proposal; ?></div>
        <div class="stat-label">Proposals</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_crec + $research_erec; ?></div>
        <div class="stat-label">Under Review</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_approved; ?></div>
        <div class="stat-label">Approved</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_ongoing; ?></div>
        <div class="stat-label">In Progress</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_completed; ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>

    <!-- RESEARCH TABLE CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Research Projects</div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-research.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by title or student name..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="proposal" <?php echo $status_filter === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
          <option value="under_crec_review" <?php echo $status_filter === 'under_crec_review' ? 'selected' : ''; ?>>CREC Review</option>
          <option value="under_erec_review" <?php echo $status_filter === 'under_erec_review' ? 'selected' : ''; ?>>EREC Review</option>
          <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
          <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
          <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($status_filter)): ?>
          <a href="admin-research.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Student</th>
              <th>Adviser</th>
              <th>Status</th>
              <th>Created</th>
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
                  <td><?php echo htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php
                    $status_badges = [
                      'draft' => 'badge-draft',
                      'proposal' => 'badge-proposal',
                      'under_crec_review' => 'badge-crec',
                      'under_erec_review' => 'badge-erec',
                      'approved' => 'badge-approved',
                      'in_progress' => 'badge-ongoing',
                      'completed' => 'badge-completed',
                      'archived' => 'badge-archived'
                    ];
                    $status_labels = [
                      'draft' => 'Draft',
                      'proposal' => 'Proposal',
                      'under_crec_review' => 'CREC Review',
                      'under_erec_review' => 'EREC Review',
                      'approved' => 'Approved',
                      'in_progress' => 'In Progress',
                      'completed' => 'Completed',
                      'archived' => 'Archived'
                    ];
                    $badge_class = $status_badges[$project['status']] ?? 'badge-draft';
                    $status_label = $status_labels[$project['status']] ?? ucfirst($project['status']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                  <td>
                    <a href="../shared/research-detail.php?id=<?php echo $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
                  No research projects found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php
renderAdminShellClose();
