<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

requireRole('student');

$user = getCurrentUser();
$user_id = $user['user_id'];

$errors = [];
$success = false;
$success_message = '';
$new_project_id = null;

// Form field defaults
$title = '';
$category_id = '';
$ay_id = '';
$research_area = '';
$abstract = '';
$status = 'draft';

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form values
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : '';
    $ay_id = isset($_POST['ay_id']) ? intval($_POST['ay_id']) : '';
    $research_area = isset($_POST['research_area']) ? trim($_POST['research_area']) : '';
    $abstract = isset($_POST['abstract']) ? trim($_POST['abstract']) : '';
    $status = isset($_POST['status']) && in_array($_POST['status'], ['draft', 'proposal']) ? $_POST['status'] : 'draft';

    // Validation
    if (empty($title)) {
        $errors[] = 'Project Title is required.';
    } elseif (strlen($title) > 255) {
        $errors[] = 'Project Title must not exceed 255 characters.';
    }

    if (empty($category_id)) {
        $errors[] = 'Research Category is required.';
    } else {
        // Verify category exists
        $cat_stmt = $conn->prepare("SELECT category_id FROM research_categories WHERE category_id = ? AND status = 1");
        $cat_stmt->bind_param("i", $category_id);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_result->num_rows === 0) {
            $errors[] = 'Invalid Research Category selected.';
        }
        $cat_stmt->close();
    }

    if (empty($ay_id)) {
        $errors[] = 'Academic Year / Semester is required.';
    } else {
        // Verify AY exists
        $ay_stmt = $conn->prepare("SELECT ay_id FROM academic_years WHERE ay_id = ? AND is_active = 1");
        $ay_stmt->bind_param("i", $ay_id);
        $ay_stmt->execute();
        $ay_result = $ay_stmt->get_result();
        if ($ay_result->num_rows === 0) {
            $errors[] = 'Invalid Academic Year selected.';
        }
        $ay_stmt->close();
    }

    if (empty($abstract)) {
        $errors[] = 'Abstract is required.';
    } elseif (strlen($abstract) > 5000) {
        $errors[] = 'Abstract must not exceed 5000 characters.';
    }

    if (strlen($research_area) > 150) {
        $errors[] = 'Research Area must not exceed 150 characters.';
    }

    // File upload handling using the RMS file uploader component
    $file_uploaded = false;
    $upload_result = null;
    $upload_id = null;

    // If no errors, proceed with insertion
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert research project
            $insert_query = "INSERT INTO research_projects (title, category_id, ay_id, research_area, abstract, status, created_by, created_at, updated_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                throw new Exception("Query error: " . $conn->error);
            }

            $insert_stmt->bind_param("siisisi", $title, $category_id, $ay_id, $research_area, $abstract, $status, $user_id);
            if (!$insert_stmt->execute()) {
                throw new Exception("Insert failed: " . $insert_stmt->error);
            }

            $new_project_id = $conn->insert_id;
            $insert_stmt->close();

            // Insert project member (creator as lead)
            $member_query = "INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'lead')";
            $member_stmt = $conn->prepare($member_query);
            if (!$member_stmt) {
                throw new Exception("Query error: " . $conn->error);
            }

            $member_stmt->bind_param("ii", $new_project_id, $user_id);
            if (!$member_stmt->execute()) {
                throw new Exception("Member insert failed: " . $member_stmt->error);
            }
            $member_stmt->close();

            // Handle file upload if present
            if (isset($_FILES['proposal_file']) && !empty($_FILES['proposal_file']['name'])) {
                $upload_result = handleRmsUpload([
                    'inputName' => 'proposal_file',
                    'folderTarget' => 'proposals',
                    'maxSize' => 10000, // 10MB
                    'accept' => ['.pdf', '.doc', '.docx'],
                    'projectId' => $new_project_id,
                    'type' => 'proposal'
                ], $_FILES, $conn);

                if (!$upload_result['success']) {
                    throw new Exception("File upload failed: " . $upload_result['error']);
                }

                $upload_id = $upload_result['upload_id'];
                $file_uploaded = true;
            }

            // Log activity
            $action_msg = $status === 'draft' ? 'Research project created as draft' : 'Research project submitted for review';
            logActivity($action_msg, 'research');

            // Commit transaction
            $conn->commit();

            // Set success flag
            $success = true;
            $success_message = $status === 'draft' 
                ? 'Research project saved as draft successfully.' 
                : 'Research project submitted for review successfully.';

        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get categories for dropdown
$categories = [];
$cat_query = "SELECT category_id, category_name FROM research_categories WHERE status = 1 ORDER BY category_name ASC";
$cat_result = $conn->query($cat_query);
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get active academic years for dropdown
$academic_years = [];
$ay_query = "SELECT ay_id, label, semester FROM academic_years WHERE is_active = 1 ORDER BY label DESC, semester ASC";
$ay_result = $conn->query($ay_query);
if ($ay_result) {
    while ($row = $ay_result->fetch_assoc()) {
        $academic_years[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Submit New Research — RMS</title>
  <link rel="stylesheet" href="../../css/file-uploader.css">
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
      max-width: 900px;
    }

    /* CARD */
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 32px;
      margin-bottom: 24px;
    }

    /* FORM */
    .form-group {
      margin-bottom: 24px;
    }

    .form-label {
      display: block;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 8px;
    }

    .form-control {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid var(--border);
      border-radius: 10px;
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      transition: all 0.2s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(200,164,77,0.1);
    }

    textarea.form-control {
      resize: vertical;
      min-height: 120px;
    }

    select.form-control {
      cursor: pointer;
    }

    .form-text {
      font-size: 13px;
      color: var(--text-secondary);
      margin-top: 6px;
    }

    /* ALERT */
    .alert {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 24px;
      font-size: 14px;
    }

    .alert-success {
      background: #DCFCE7;
      color: var(--status-approved);
      border: 1px solid #86EFAC;
    }

    .alert-danger {
      background: #FEE2E2;
      color: #DC2626;
      border: 1px solid #FCA5A5;
    }

    .alert ul {
      margin: 8px 0 0 20px;
    }

    /* BUTTON */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 14px;
      border: none;
      cursor: pointer;
      transition: all 0.2s;
      text-decoration: none;
      font-family: 'Inter', sans-serif;
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

    /* RESPONSIVE */
    @media (max-width: 768px) {
      .sidebar {
        display: none;
      }

      .main-content {
        margin-left: 0;
        padding: 24px;
      }

      .page-content {
        max-width: 100%;
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
      <div class="nav-item" onclick="location.href='my-research.php'">
        <span class="icon"><i data-lucide="folder-kanban"></i></span>
        <span>My Research</span>
      </div>
      <div class="nav-item active" onclick="location.href='submit-research.php'">
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
        <h2>Submit New Research</h2>
        <p>Fill in your research details and upload your proposal document for CREC review.</p>
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

      <?php if ($success): ?>
        <!-- SUCCESS ALERT -->
        <div class="alert alert-success" style="margin-bottom: 20px;">
          <strong>✅ Success!</strong> <?php echo htmlspecialchars($success_message); ?>
          <br><a href="my-research.php" style="color: inherit; text-decoration: underline;">View your research project →</a>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <!-- ERROR ALERT -->
        <div class="alert alert-error" style="margin-bottom: 20px;">
          <strong>❌ Please fix the following errors:</strong>
          <ul style="margin: 8px 0 0 20px; padding: 0;">
            <?php foreach ($errors as $error): ?>
              <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- FORM -->
      <?php if (!$success): ?>
      <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <!-- SECTION 1: BASIC INFORMATION -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Basic Information</div>
          </div>
          <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
            <!-- Project Title -->
            <div>
              <label for="title" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Project Title <span style="color: #ef4444;">*</span>
              </label>
              <input 
                type="text" 
                id="title" 
                name="title" 
                class="form-control" 
                placeholder="Enter your research project title" 
                maxlength="255"
                value="<?php echo htmlspecialchars($title); ?>"
                required
              />
              <small style="color: var(--text-light); margin-top: 4px; display: block;">Max 255 characters</small>
            </div>

            <!-- Research Category -->
            <div>
              <label for="category_id" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Research Category <span style="color: #ef4444;">*</span>
              </label>
              <select 
                id="category_id" 
                name="category_id" 
                class="form-control"
                required
              >
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_id == $cat['category_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['category_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Academic Year / Semester -->
            <div>
              <label for="ay_id" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Academic Year / Semester <span style="color: #ef4444;">*</span>
              </label>
              <select 
                id="ay_id" 
                name="ay_id" 
                class="form-control"
                required
              >
                <option value="">-- Select Academic Year --</option>
                <?php foreach ($academic_years as $ay): ?>
                  <option value="<?php echo $ay['ay_id']; ?>" <?php echo $ay_id == $ay['ay_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ay['label'] . ' — ' . $ay['semester']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Research Area -->
            <div>
              <label for="research_area" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Research Area <span style="color: #999;">(Optional)</span>
              </label>
              <input 
                type="text" 
                id="research_area" 
                name="research_area" 
                class="form-control" 
                placeholder="e.g., Computer Science, Social Sciences, etc." 
                maxlength="150"
                value="<?php echo htmlspecialchars($research_area); ?>"
              />
              <small style="color: var(--text-light); margin-top: 4px; display: block;">Max 150 characters</small>
            </div>
          </div>
        </div>

        <!-- SECTION 2: RESEARCH TEAM -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Research Team</div>
          </div>
          <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
            <div>
              <label style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">Lead Researcher</label>
              <div style="padding: 10px 12px; background-color: #f0f4f8; border-radius: 6px; border: 1px solid var(--border); color: var(--text-dark);">
                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
              </div>
              <small style="color: var(--text-light); margin-top: 4px; display: block;">You are automatically set as the lead researcher.</small>
            </div>

            <!-- @rms-db: Team member addition feature for v2 -->
            <div style="padding: 12px; background-color: #f0f4f8; border-radius: 6px; border-left: 3px solid var(--primary); color: var(--text-dark); font-size: 14px;">
              💡 <strong>Tip:</strong> You can add team members and co-researchers after submitting this project. See the My Research page for more options.
            </div>
          </div>
        </div>

        <!-- SECTION 3: ABSTRACT -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Abstract</div>
          </div>
          <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
            <div>
              <label for="abstract" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Research Abstract <span style="color: #ef4444;">*</span>
              </label>
              <textarea 
                id="abstract" 
                name="abstract" 
                class="form-control" 
                rows="8"
                placeholder="Enter your research abstract. Describe the purpose, methods, and expected outcomes of your research."
                required
              ><?php echo htmlspecialchars($abstract); ?></textarea>
              <small style="color: var(--text-light); margin-top: 4px; display: block;">Max 5000 characters</small>
            </div>
          </div>
        </div>

        <!-- SECTION 4: PROPOSAL DOCUMENT -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Proposal Document</div>
          </div>
          <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
            <?php
            echo renderFileUploader([
              'inputName' => 'proposal_file',
              'accept' => '.pdf,.doc,.docx',
              'maxSize' => 10000,  // 10 MB
              'folderTarget' => 'proposals',
              'label' => 'Upload Proposal',
              'description' => 'Drag & drop your proposal manuscript or click to browse',
              'allowedFormatsText' => 'PDF, DOC, DOCX • Max 10 MB',
              'required' => false
            ]);
            ?>
            <small style="color: var(--text-light); margin-top: -12px; display: block;">
              💡 You can skip this and upload chapters later during the review process.
            </small>
          </div>
        </div>

        <!-- SUBMIT BUTTONS -->
        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
          <!-- @rms-ui: sticky submit bar styling -->
          <a href="my-research.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" name="status" value="draft" class="btn btn-secondary">Save as Draft</button>
          <button type="submit" name="status" value="proposal" class="btn btn-primary">Submit for Review</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="../../js/file-uploader.js"></script>
<script>
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
