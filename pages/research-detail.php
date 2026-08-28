<?php
include '../includes/config.php';
include '../includes/auth.php';

requireRole('student');

$user = getCurrentUser();
$user_id = $user['user_id'];

// Get project ID from URL
$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Access control & fetch project
$project = null;
$has_access = false;

if ($project_id > 0) {
    $query = "
        SELECT rp.*, rc.category_name, aa.label as ay_label, aa.semester, u.first_name, u.last_name
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
        LEFT JOIN users u ON rp.created_by = u.user_id
        WHERE rp.project_id = ?
    ";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $project = $result->fetch_assoc();

            // Check access: creator OR member
            if ($project['created_by'] == $user_id) {
                $has_access = true;
            } else {
                $member_query = "SELECT id FROM project_members WHERE project_id = ? AND user_id = ?";
                $member_stmt = $conn->prepare($member_query);
                if ($member_stmt) {
                    $member_stmt->bind_param("ii", $project_id, $user_id);
                    $member_stmt->execute();
                    if ($member_stmt->get_result()->num_rows > 0) {
                        $has_access = true;
                    }
                    $member_stmt->close();
                }
            }
        }
        $stmt->close();
    }
}

// Fetch chapters
$chapters = [];
$approved_count = 0;
if ($has_access && $project_id > 0) {
    $chapter_query = "
        SELECT c.*, ua.first_name as approver_first, ua.last_name as approver_last
        FROM chapters c
        LEFT JOIN users ua ON c.approved_by = ua.user_id
        WHERE c.project_id = ?
        ORDER BY c.chapter_number ASC
    ";
    $ch_stmt = $conn->prepare($chapter_query);
    if ($ch_stmt) {
        $ch_stmt->bind_param("i", $project_id);
        $ch_stmt->execute();
        $ch_result = $ch_stmt->get_result();
        while ($row = $ch_result->fetch_assoc()) {
            $chapters[$row['chapter_number']] = $row;
            if ($row['status'] === 'approved') {
                $approved_count++;
            }
        }
        $ch_stmt->close();
    }
}

// Fetch project members
$members = [];
if ($has_access && $project_id > 0) {
    $member_query = "
        SELECT pm.*, u.first_name, u.last_name
        FROM project_members pm
        LEFT JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ?
        ORDER BY pm.role DESC, u.first_name ASC
    ";
    $ms_stmt = $conn->prepare($member_query);
    if ($ms_stmt) {
        $ms_stmt->bind_param("i", $project_id);
        $ms_stmt->execute();
        $ms_result = $ms_stmt->get_result();
        while ($row = $ms_result->fetch_assoc()) {
            $members[] = $row;
        }
        $ms_stmt->close();
    }
}

// Fetch recent uploads (up to 5)
$uploads = [];
if ($has_access && $project_id > 0) {
    $upload_query = "
        SELECT u.*, us.first_name, us.last_name
        FROM uploads u
        LEFT JOIN users us ON u.uploaded_by = us.user_id
        WHERE u.project_id = ?
        ORDER BY u.upload_date DESC
        LIMIT 5
    ";
    $up_stmt = $conn->prepare($upload_query);
    if ($up_stmt) {
        $up_stmt->bind_param("i", $project_id);
        $up_stmt->execute();
        $up_result = $up_stmt->get_result();
        while ($row = $up_result->fetch_assoc()) {
            $uploads[] = $row;
        }
        $up_stmt->close();
    }
}

// Status badge mapping
$status_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'proposal' => ['class' => 'badge badge-info', 'style' => ''],
    'in_progress' => ['class' => 'badge badge-primary', 'style' => ''],
    'for_defense' => ['class' => 'badge badge-warning', 'style' => ''],
    'completed' => ['class' => 'badge badge-success', 'style' => ''],
    'archived' => ['class' => 'badge', 'style' => 'opacity:0.6;']
];

// Chapter status badges
$chapter_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'submitted' => ['class' => 'badge badge-info', 'style' => ''],
    'under_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'revision_required' => ['class' => 'badge badge-warning', 'style' => ''],
    'approved' => ['class' => 'badge badge-success', 'style' => '']
];

// Canonical chapter titles
$chapter_titles = [
    1 => 'The Problem and Its Background',
    2 => 'Review of Related Literature',
    3 => 'Research Methodology',
    4 => 'Presentation, Analysis and Interpretation of Data',
    5 => 'Summary, Conclusions, and Recommendations'
];

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $has_access && $project ? htmlspecialchars($project['title']) . ' — Research Details' : 'Research Project'; ?> — RMS</title>
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
        <h2><?php echo $has_access && $project ? htmlspecialchars($project['title']) : 'Research Project'; ?></h2>
        <p>
          <?php if ($has_access && $project): ?>
            <?php echo htmlspecialchars($project['category_name'] ?? 'Uncategorized'); ?> • <?php echo htmlspecialchars($project['ay_label'] ?? 'N/A'); ?> • <?php echo htmlspecialchars($project['semester'] ?? ''); ?>
          <?php else: ?>
            Project details
          <?php endif; ?>
        </p>
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
      <!-- BREADCRUMB -->
      <div style="margin-bottom: 20px;">
        <a href="my-research.php" style="color: var(--primary); text-decoration: none; font-size: 14px;">← Back to My Research</a>
      </div>

      <?php if (!$has_access || !$project): ?>
        <!-- ACCESS DENIED / NOT FOUND -->
        <div class="card" style="text-align: center; padding: 60px 40px;">
          <div style="font-size: 48px; margin-bottom: 16px;">❌</div>
          <h3 style="margin: 0 0 8px 0; color: var(--text-dark);">Project not found or you don't have access</h3>
          <p style="margin: 0 0 24px 0; color: var(--text-light); font-size: 14px;">
            The research project you're looking for doesn't exist or you don't have permission to view it.
          </p>
          <a href="my-research.php" class="btn btn-primary">Go back to My Research</a>
        </div>

      <?php else: ?>
        <!-- STATUS + ACTIONS CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-body" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
            <div style="flex: 1;">
              <div style="margin-bottom: 12px;">
                <?php $status = isset($project['status']) ? $project['status'] : 'draft'; $badge = isset($status_badges[$status]) ? $status_badges[$status] : $status_badges['draft']; ?>
                <span class="<?php echo htmlspecialchars($badge['class']); ?>" <?php echo !empty($badge['style']) ? 'style="' . htmlspecialchars($badge['style']) . '"' : ''; ?>>
                  <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                </span>
              </div>
              <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                📅 Created: <?php echo date('M d, Y', strtotime($project['created_at'])); ?><br>
                🔄 Last updated: <?php echo date('M d, Y', strtotime($project['updated_at'])); ?><br>
                👤 Lead: <?php echo htmlspecialchars($project['first_name'] . ' ' . $project['last_name']); ?>
              </div>
            </div>

            <div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
              <?php if ($project['status'] === 'draft'): ?>
                <a href="#" class="btn btn-sm btn-accent">Edit Project</a>
                <a href="#" class="btn btn-sm btn-primary">Submit for Review</a>
              <?php elseif ($project['status'] === 'for_defense'): ?>
                <a href="#" class="btn btn-sm btn-secondary">Submit Update</a>
                <a href="#" class="btn btn-sm btn-accent">Edit Project</a>
              <?php else: ?>
                <a href="#" class="btn btn-sm btn-secondary">Upload Chapter</a>
              <?php endif; ?>
              <a href="#" class="btn btn-sm btn-secondary" title="Available after approval" style="cursor: not-allowed; opacity: 0.6;">View in Archive</a>
            </div>
          </div>
        </div>

        <!-- ABSTRACT CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Abstract</div>
          </div>
          <div class="card-body">
            <?php if (!empty($project['abstract'])): ?>
              <div style="white-space: pre-wrap; color: var(--text-dark); line-height: 1.6;">
                <?php echo htmlspecialchars($project['abstract'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php else: ?>
              <div style="color: var(--text-light); font-style: italic;">No abstract provided.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- CHAPTERS CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div>
              <div class="card-title">Chapters</div>
            </div>
            <div style="font-size: 13px; color: var(--text-light);">
              <?php echo $approved_count; ?>/5 approved
              <!-- @rms-ui: chapter progress bar (200px wide, colored fill) -->
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($chapters)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No chapters submitted yet. Start with Chapter 1.
              </div>
              <div style="display: flex; justify-content: center; gap: 8px;">
                <a href="submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=1" class="btn btn-sm btn-primary">Upload Chapter 1</a>
              </div>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;">
                    <div style="flex: 1;">
                      <div style="font-weight: 500; color: var(--text-dark);">Ch. <?php echo $i; ?> — <?php echo htmlspecialchars($chapter_titles[$i]); ?></div>
                      <div style="font-size: 13px; color: var(--text-light); margin-top: 4px;">
                        <?php if (isset($chapters[$i])): ?>
                          <?php $ch_status = isset($chapters[$i]['status']) ? $chapters[$i]['status'] : 'draft'; $ch_badge = isset($chapter_badges[$ch_status]) ? $chapter_badges[$ch_status] : $chapter_badges['draft']; ?>
                          <span class="<?php echo htmlspecialchars($ch_badge['class']); ?>" <?php echo !empty($ch_badge['style']) ? 'style="' . htmlspecialchars($ch_badge['style']) . '"' : ''; ?>>
                            <?php echo ucwords(str_replace('_', ' ', $ch_status)); ?>
                          </span>
                          <?php if (!empty($chapters[$i]['submitted_at'])): ?>
                            • Submitted: <?php echo date('M d, Y', strtotime($chapters[$i]['submitted_at'])); ?>
                          <?php endif; ?>
                          <?php if (!empty($chapters[$i]['approved_at'])): ?>
                            • Approved: <?php echo date('M d, Y', strtotime($chapters[$i]['approved_at'])); ?>
                            <?php if (!empty($chapters[$i]['approver_first'])): ?>
                              by <?php echo htmlspecialchars($chapters[$i]['approver_first'] . ' ' . $chapters[$i]['approver_last']); ?>
                            <?php endif; ?>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge" style="background:#e2e8f0;color:#475569;">Not Started</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div>
                      <?php if (!isset($chapters[$i])): ?>
                        <a href="submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Upload</a>
                      <?php elseif (isset($chapters[$i]['status']) && in_array($chapters[$i]['status'], ['draft', 'revision_required', 'submitted'])): ?>
                        <a href="submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Upload</a>
                      <?php elseif (isset($chapters[$i]['status']) && $chapters[$i]['status'] === 'approved'): ?>
                        <a href="#" class="btn btn-sm btn-accent">View</a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- RESEARCH TEAM CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Research Team</div>
          </div>
          <div class="card-body">
            <?php if (empty($members)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No additional team members yet.
              </div>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($members as $member): ?>
                  <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;">
                    <div style="flex: 1;">
                      <div style="font-weight: 500; color: var(--text-dark);">
                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                      </div>
                    </div>
                    <div>
                      <span class="badge <?php echo $member['role'] === 'lead' ? 'badge-primary' : 'badge-info'; ?>">
                        <?php echo ucfirst(htmlspecialchars($member['role'])); ?>
                      </span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- RECENT UPLOADS CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div>
              <div class="card-title">Recent Documents</div>
            </div>
            <a href="#" style="color: var(--primary); text-decoration: none; font-size: 13px;">View All →</a>
          </div>
          <div class="card-body">
            <?php if (empty($uploads)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No documents uploaded yet.
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>File Name</th>
                      <th>Type</th>
                      <th>Size</th>
                      <th>Uploaded</th>
                      <th>By</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($uploads as $upload): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($upload['original_name']); ?></td>
                        <td>
                          <span class="badge badge-info"><?php echo ucfirst(htmlspecialchars($upload['type'])); ?></span>
                        </td>
                        <td><?php echo formatFileSize($upload['file_size']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($upload['upload_date'])); ?></td>
                        <td><?php echo htmlspecialchars($upload['first_name'] . ' ' . $upload['last_name']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ACTIVITY CARD (PLACEHOLDER) -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Activity</div>
          </div>
          <div class="card-body" style="color: var(--text-light); text-align: center; padding: 24px;">
            <!-- @rms-db: add project_id to activity_log to enable project-level activity tracking -->
            Recent activity for this project will appear here.
          </div>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script>
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
