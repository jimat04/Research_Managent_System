<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user = getCurrentUser();
$user_id = $user['user_id'];

// Get student's research projects (created by OR where they are members)
$query = "
    SELECT
        rp.project_id,
        rp.title,
        rp.category_id,
        rp.ay_id,
        rp.status,
        rp.created_by,
        rp.created_at,
        rp.updated_at,
        rc.category_name,
        aa.label as ay_label,
        aa.semester,
        u.first_name,
        u.last_name,
        (SELECT COUNT(*) FROM chapters WHERE project_id = rp.project_id AND status = 'approved') as approved_chapters
    FROM research_projects rp
    LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
    LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE (rp.created_by = ? OR rp.project_id IN (
        SELECT project_id FROM project_members WHERE user_id = ?
    ))
    ORDER BY rp.updated_at DESC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query error: " . $conn->error);
}
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$projects_result = $stmt->get_result();
$projects = [];
while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}
$stmt->close();

// Calculate statistics
$total_projects = count($projects);
$under_review = 0;
$for_revision = 0;
$completed = 0;

foreach ($projects as $proj) {
    if ($proj['status'] === 'proposal' || $proj['status'] === 'in_progress') {
        $under_review++;
    } else if ($proj['status'] === 'draft') {
        $for_revision++;
    } else if ($proj['status'] === 'completed') {
        $completed++;
    }
}

// Status badge mapping
$status_map = [
    'draft' => 'badge',
    'proposal' => 'badge badge-info',
    'in_progress' => 'badge badge-primary',
    'for_defense' => 'badge badge-warning',
    'completed' => 'badge badge-success',
    'archived' => 'badge'
];

$status_display = [
    'draft' => 'Draft',
    'proposal' => 'Proposal',
    'in_progress' => 'In Progress',
    'for_defense' => 'For Defense',
    'completed' => 'Completed',
    'archived' => 'Archived'
];

renderStudentShell($user, 'my-research', 'My Research', 'Track your research projects through review, implementation, and completion.');
?>

<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 24px;
    transition: all 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }

  .stat-card.purple .stat-number { color: #7C3AED; }
  .stat-card.blue .stat-number { color: #2563EB; }
  .stat-card.orange .stat-number { color: #EA580C; }
  .stat-card.green .stat-number { color: #16A34A; }

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
    color: #64748B;
    font-weight: 500;
  }

  .stat-icon {
    font-size: 32px;
    opacity: 0.3;
  }

  .card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 24px;
  }

  .card-body {
    padding: 0;
  }

  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
  }

  .data-table {
    width: 100%;
    border-collapse: collapse;
  }

  .data-table thead {
    background: #F8FAFC;
  }

  .data-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .data-table td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
  }

  .data-table tr:hover {
    background: #F8FAFC;
  }

  .badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .badge-info {
    background: #DBEAFE;
    color: #2563EB;
  }

  .badge-primary {
    background: #DBEAFE;
    color: #3B82F6;
  }

  .badge-warning {
    background: #FEF3C7;
    color: #EA580C;
  }

  .badge-success {
    background: #DCFCE7;
    color: #16A34A;
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

  .btn-primary {
    background: #5B1EBC;
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(91, 30, 188, 0.3);
  }

  .btn-secondary {
    background: #F8FAFC;
    color: #111827;
    border: 1px solid #E5E7EB;
  }

  .btn-secondary:hover {
    background: #111827;
    color: white;
  }

  .btn-accent {
    background: #2563EB;
    color: white;
  }

  .btn-sm {
    padding: 6px 14px;
    font-size: 13px;
  }

  .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s;
  }

  .form-control:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php if ($total_projects > 0): ?>
  <!-- PAGE HEADER -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
    <div>
      <h2 style="margin: 0 0 8px 0; color: #111827; font-size: 28px; font-weight: 600;">My Research</h2>
      <p style="margin: 0; color: #64748B; font-size: 14px;">Manage and monitor your research projects</p>
    </div>
    <button class="btn btn-primary" onclick="location.href='submit-research.php'" style="white-space: nowrap;">+ New Research</button>
  </div>

  <!-- STAT CARDS -->
  <div class="stats-grid">
    <div class="stat-card purple">
      <div class="stat-header">
        <div style="flex: 1;">
          <div class="stat-number"><?php echo $total_projects; ?></div>
          <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-icon">📊</div>
      </div>
    </div>

    <div class="stat-card blue">
      <div class="stat-header">
        <div style="flex: 1;">
          <div class="stat-number"><?php echo $under_review; ?></div>
          <div class="stat-label">Under Review</div>
        </div>
        <div class="stat-icon">🔍</div>
      </div>
    </div>

    <div class="stat-card orange">
      <div class="stat-header">
        <div style="flex: 1;">
          <div class="stat-number"><?php echo $for_revision; ?></div>
          <div class="stat-label">For Revision</div>
        </div>
        <div class="stat-icon">⚠️</div>
      </div>
    </div>

    <div class="stat-card green">
      <div class="stat-header">
        <div style="flex: 1;">
          <div class="stat-number"><?php echo $completed; ?></div>
          <div class="stat-label">Completed</div>
        </div>
        <div class="stat-icon">✅</div>
      </div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
      <input
        type="text"
        id="searchInput"
        class="form-control"
        placeholder="Search projects by title..."
        style="flex: 1; min-width: 200px;"
      />
      <select id="statusFilter" class="form-control" style="min-width: 180px;">
        <option value="">All Statuses</option>
        <option value="draft">Draft</option>
        <option value="proposal">Proposal</option>
        <option value="in_progress">In Progress</option>
        <option value="for_defense">For Defense</option>
        <option value="completed">Completed</option>
        <option value="archived">Archived</option>
      </select>
    </div>
  </div>

  <!-- DATA TABLE -->
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Category</th>
            <th>AY / Semester</th>
            <th>Lead</th>
            <th>Chapters</th>
            <th>Status</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="projectsTableBody">
          <?php foreach ($projects as $proj): ?>
            <tr class="project-row" data-title="<?php echo htmlspecialchars(strtolower($proj['title'])); ?>" data-status="<?php echo htmlspecialchars($proj['status']); ?>">
              <td style="font-weight: 500;">
                <a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $proj['project_id']; ?>" style="color: #2563EB; text-decoration: none;">
                  <?php echo htmlspecialchars($proj['title']); ?>
                </a>
              </td>
              <td><?php echo htmlspecialchars($proj['category_name'] ?? 'N/A'); ?></td>
              <td><?php echo htmlspecialchars(($proj['ay_label'] ?? 'N/A') . ' / ' . ($proj['semester'] ?? 'N/A')); ?></td>
              <td><?php echo htmlspecialchars($proj['first_name'] . ' ' . $proj['last_name']); ?></td>
              <td>
                <small><?php echo $proj['approved_chapters']; ?>/5 approved</small>
              </td>
              <td>
                <span class="<?php echo $status_map[$proj['status']] ?? 'badge'; ?>" <?php echo $proj['status'] === 'archived' ? 'style="opacity: 0.6;"' : ''; ?>>
                  <?php echo $status_display[$proj['status']] ?? ucwords(str_replace('_', ' ', $proj['status'])); ?>
                </span>
              </td>
              <td><?php echo date('M d, Y', strtotime($proj['updated_at'])); ?></td>
              <td style="display: flex; gap: 4px;">
                <a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $proj['project_id']; ?>" class="btn btn-sm btn-accent">View</a>
                <?php $proj_status = $proj['status'] ?? ''; $can_edit_row = in_array($proj_status, ['draft', 'for_revision', 'revision_required'], true); ?>
                <?php if ($can_edit_row): ?>
                  <a href="<?php echo SITE_URL; ?>pages/student/edit-research.php?id=<?php echo (int) $proj['project_id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                <?php else: ?>
                  <span class="btn btn-sm btn-secondary" style="opacity: 0.5; cursor: not-allowed;" title="Editing is disabled while the project is in review.">Edit</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <!-- EMPTY STATE -->
  <div style="display: flex; justify-content: center; align-items: center; min-height: 500px;">
    <div class="card" style="text-align: center; max-width: 400px; padding: 40px;">
      <div style="font-size: 64px; margin-bottom: 16px;">📭</div>
      <h3 style="margin: 0 0 8px 0; color: #111827;">No research projects yet</h3>
      <p style="margin: 0 0 24px 0; color: #64748B; font-size: 14px;">
        Start your first research project and track it through CREC/EREC review, implementation, and your terminal report.
      </p>
      <button class="btn btn-primary" onclick="location.href='submit-research.php'">Start a New Research</button>
    </div>
  </div>
<?php endif; ?>

<script>
// Search and filter functionality
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const projectRows = document.querySelectorAll('.project-row');

function filterTable() {
  const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
  const statusValue = statusFilter ? statusFilter.value : '';

  projectRows.forEach(row => {
    const title = row.dataset.title;
    const status = row.dataset.status;

    const matchesSearch = title.includes(searchTerm);
    const matchesStatus = statusValue === '' || status === statusValue;

    if (matchesSearch && matchesStatus) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

if (searchInput) {
  searchInput.addEventListener('input', filterTable);
}

if (statusFilter) {
  statusFilter.addEventListener('change', filterTable);
}
</script>

<?php renderStudentShellClose(); ?>
