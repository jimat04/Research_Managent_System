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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Research — RMS</title>
  <script src="https://unpkg.com/lucide@latest" defer></script>
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

      --status-draft: #64748B;
      --status-proposal: #2563EB;
      --status-crec: #3B82F6;
      --status-erec: #7C3AED;
      --status-revision: #EA580C;
      --status-approved: #16A34A;
      --status-completed: #059669;
      --status-archived: #475569;
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

    .nav-item .icon {
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .nav-item .icon svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
      stroke-width: 2;
    }

    .nav-item:hover {
      background: rgba(255,255,255,0.08);
      color: white;
    }

    .nav-item.active {
      background: var(--gold);
      color: white;
    }

    .nav-item.active .icon svg {
      stroke: white;
    }

    .nav-item .badge {
      margin-left: auto;
      background: var(--gold);
      color: white;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 12px;
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

    .page-content {
      max-width: 1400px;
    }

    /* STATS GRID */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 24px;
      margin-bottom: 48px;
    }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 24px;
      transition: all 0.3s;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }

    .stat-card.purple .stat-number { color: var(--status-erec); }
    .stat-card.blue .stat-number { color: var(--status-proposal); }
    .stat-card.orange .stat-number { color: var(--status-revision); }
    .stat-card.green .stat-number { color: var(--status-approved); }

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
      color: var(--text-secondary);
      font-weight: 500;
    }

    .stat-icon {
      font-size: 32px;
      opacity: 0.3;
    }

    /* CARD */
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 24px;
      margin-bottom: 24px;
    }

    .card-body {
      padding: 0;
    }

    /* TABLE */
    .table-wrap {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table thead {
      background: var(--bg-surface);
    }

    .data-table th {
      text-align: left;
      padding: 12px 16px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .data-table td {
      padding: 16px;
      font-size: 14px;
      border-top: 1px solid var(--border);
    }

    .data-table tr:hover {
      background: var(--bg-surface);
    }

    /* BADGE */
    .badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
    }

    .badge-info {
      background: #DBEAFE;
      color: var(--status-proposal);
    }

    .badge-primary {
      background: #DBEAFE;
      color: var(--status-crec);
    }

    .badge-warning {
      background: #FEF3C7;
      color: var(--status-revision);
    }

    .badge-success {
      background: #DCFCE7;
      color: var(--status-approved);
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

    .btn-primary {
      background: var(--gold);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(200,164,77,0.3);
    }

    .btn-secondary {
      background: var(--bg-surface);
      color: var(--text-primary);
      border: 1px solid var(--border);
    }

    .btn-secondary:hover {
      background: var(--charcoal);
      color: white;
    }

    .btn-accent {
      background: var(--status-proposal);
      color: white;
    }

    .btn-sm {
      padding: 6px 14px;
      font-size: 13px;
    }

    /* FORM */
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 14px;
      transition: all 0.2s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(200,164,77,0.1);
    }

    /* TOPBAR */
    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
      padding-bottom: 24px;
      border-bottom: 1px solid var(--border);
    }

    .topbar-left h2 {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 4px;
      color: var(--charcoal);
    }

    .topbar-left p {
      font-size: 14px;
      color: var(--text-secondary);
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .search-box {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 10px;
    }

    .search-box input {
      border: none;
      background: none;
      outline: none;
      width: 200px;
      font-size: 14px;
    }

    .icon-btn {
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .icon-btn:hover {
      background: var(--bg-surface);
    }

    .user-profile-btn {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 12px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .user-profile-btn:hover {
      border-color: var(--gold);
    }

    .profile-avatar {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: var(--gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 13px;
      color: white;
    }

    .profile-name {
      font-size: 14px;
      font-weight: 600;
    }

    .profile-role {
      font-size: 12px;
      color: var(--text-secondary);
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

      .stats-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

<div class="dashboard">
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- SIDEBAR -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand">EARIST RMS</div>
      <div class="sidebar-role">Student Portal</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Main</div>
      <div class="nav-item" onclick="location.href='student-dashboard.php'">
        <span class="icon"><i data-lucide="layout-dashboard"></i></span>
        <span>Dashboard</span>
      </div>
      <div class="nav-item active" onclick="location.href='my-research.php'">
        <span class="icon"><i data-lucide="folder-kanban"></i></span>
        <span>My Research</span>
      </div>
      <div class="nav-item" onclick="location.href='submit-research.php'">
        <span class="icon"><i data-lucide="file-up"></i></span>
        <span>Submit Research</span>
      </div>
      <div class="nav-item" onclick="location.href='my-documents.php'">
        <span class="icon"><i data-lucide="files"></i></span>
        <span>My Documents</span>
      </div>
      <div class="nav-item" onclick="location.href='progress-tracking.php'">
        <span class="icon"><i data-lucide="chart-no-axes-combined"></i></span>
        <span>Progress Tracking</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span class="icon"><i data-lucide="messages-square"></i></span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/notifications.php'">
        <span class="icon"><i data-lucide="bell"></i></span>
        <span>Notifications</span>
      </div>

      <div class="nav-group-title">Resources</div>
      <div class="nav-item" onclick="location.href='../shared/research-archive.php'">
        <span class="icon"><i data-lucide="archive"></i></span>
        <span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/calendar.php'">
        <span class="icon"><i data-lucide="calendar-days"></i></span>
        <span>Calendar</span>
      </div>

      <div class="nav-group-title">Account</div>
      <div class="nav-item" onclick="location.href='../shared/profile.php'">
        <span class="icon"><i data-lucide="circle-user-round"></i></span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #EF4444;">
        <span class="icon"><i data-lucide="log-out"></i></span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">Student</div>
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

// Initialize Lucide icons
document.addEventListener('DOMContentLoaded', function() {
  if (window.lucide) {
    lucide.createIcons();
  } else {
    // Wait for Lucide to load
    setTimeout(function() {
      if (window.lucide) {
        lucide.createIcons();
      }
    }, 100);
  }
});
</script>
</body>
</html>
