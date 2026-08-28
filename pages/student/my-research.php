<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Research — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>

<div class="dashboard">
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- SIDEBAR -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">
        Research<br>Management
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item" onclick="location.href='student-dashboard.php'">
        <span class="icon">📊</span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item active" onclick="location.href='my-research.php'">
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
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
          <div class="user-role">🎓 Student</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- MAIN CONTENT -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="main-content">
    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <h2>My Research</h2>
        <p>Track your research projects through review, implementation, and completion.</p>
      </div>

      <div class="topbar-right">
        <div class="search-box">
          <span style="color: #94a3b8;">🔍</span>
          <input type="text" placeholder="Search anything...">
        </div>

        <div class="topbar-icons">
          <div class="icon-btn">
            🔔
          </div>
        </div>

        <div class="user-profile-btn" onclick="alert('Profile menu')">
          <div class="profile-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name']); ?></div>
            <div class="profile-role">Student</div>
          </div>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">
      <?php if ($total_projects > 0): ?>
        <!-- PAGE HEADER -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
          <div>
            <h2 style="margin: 0 0 8px 0; color: var(--text-dark); font-size: 28px; font-weight: 600;">My Research</h2>
            <p style="margin: 0; color: var(--text-light); font-size: 14px;">Manage and monitor your research projects</p>
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
                      <a href="#" style="color: var(--primary); text-decoration: none;">
                        <?php echo htmlspecialchars($proj['title']); ?>
                      </a>
                    </td>
                    <td><?php echo htmlspecialchars($proj['category_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars(($proj['ay_label'] ?? 'N/A') . ' / ' . ($proj['semester'] ?? 'N/A')); ?></td>
                    <td><?php echo htmlspecialchars($proj['first_name'] . ' ' . $proj['last_name']); ?></td>
                    <td>
                      <small><?php echo $proj['approved_chapters']; ?>/5 approved</small>
                      <!-- @rms-ui: chapter progress bar -->
                    </td>
                    <td>
                      <span class="<?php echo $status_map[$proj['status']] ?? 'badge'; ?>" <?php echo $proj['status'] === 'archived' ? 'style="opacity: 0.6;"' : ''; ?>>
                        <?php echo $status_display[$proj['status']] ?? ucwords(str_replace('_', ' ', $proj['status'])); ?>
                      </span>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($proj['updated_at'])); ?></td>
                    <td style="display: flex; gap: 4px;">
                      <a href="research-detail.php?id=<?php echo $proj['project_id']; ?>" class="btn btn-sm btn-accent">View</a>
                      <button class="btn btn-sm btn-secondary" onclick="alert('Edit project: ' + '<?php echo htmlspecialchars($proj['title']); ?>')">Edit</button>
                      <button class="btn btn-sm btn-secondary" onclick="alert('Submit update for: ' + '<?php echo htmlspecialchars($proj['title']); ?>')">Submit Update</button>
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
            <h3 style="margin: 0 0 8px 0; color: var(--text-dark);">No research projects yet</h3>
            <p style="margin: 0 0 24px 0; color: var(--text-light); font-size: 14px;">
              Start your first research project and track it through CREC/EREC review, implementation, and your terminal report.
            </p>
            <button class="btn btn-primary" onclick="location.href='submit-research.php'">Start a New Research</button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Search and filter functionality
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const projectRows = document.querySelectorAll('.project-row');

function filterTable() {
  const searchTerm = searchInput.value.toLowerCase();
  const statusValue = statusFilter.value;

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

// Sidebar menu item click handlers
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
  });
});
</script>
</body>
</html>
