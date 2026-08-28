<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

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

    // File upload validation
    $file_uploaded = false;
    $upload_error = null;
    $file_name = null;
    $file_path = null;
    $file_size = null;
    $mime_type = null;
    $original_name = null;

    if (!empty($_FILES['proposal']['name'])) {
        $max_size = 10 * 1024 * 1024; // 10 MB
        $allowed_ext = ['pdf', 'doc', 'docx'];

        $file_name_tmp = $_FILES['proposal']['name'];
        $file_size_tmp = $_FILES['proposal']['size'];
        $file_tmp = $_FILES['proposal']['tmp_name'];
        $file_error = $_FILES['proposal']['error'];

        // Check for upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error. Please try again.';
        } elseif ($file_size_tmp > $max_size) {
            $errors[] = 'File size must not exceed 10 MB.';
        } else {
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name_tmp, PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_ext)) {
                $errors[] = 'Invalid file format. Accepted formats: PDF, DOC, DOCX.';
            } else {
                $file_uploaded = true;
                $original_name = $file_name_tmp;
                $file_size = $file_size_tmp;
                $mime_type = $_FILES['proposal']['type'];
                // Generate safe filename
                $file_name = uniqid('prop_') . '.' . $file_ext;
                $file_path = 'proposals/' . $file_name;
            }
        }
    }

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
            if ($file_uploaded) {
                // Create uploads/proposals directory if it doesn't exist
                $proposals_dir = __DIR__ . '/../uploads/proposals';
                if (!is_dir($proposals_dir)) {
                    @mkdir($proposals_dir, 0755, true);
                }

                $full_file_path = $proposals_dir . '/' . $file_name;

                // Move uploaded file
                if (!move_uploaded_file($file_tmp, $full_file_path)) {
                    throw new Exception("Failed to save uploaded file.");
                }

                // Insert upload record
                $upload_query = "INSERT INTO uploads (project_id, chapter_id, uploaded_by, type, original_name, file_name, file_path, file_size, mime_type, upload_date) 
                               VALUES (?, NULL, ?, 'proposal', ?, ?, ?, ?, ?, NOW())";
                $upload_stmt = $conn->prepare($upload_query);
                if (!$upload_stmt) {
                    throw new Exception("Query error: " . $conn->error);
                }

                $upload_stmt->bind_param("iisssis", $new_project_id, $user_id, $original_name, $file_name, $file_path, $file_size, $mime_type);
                if (!$upload_stmt->execute()) {
                    throw new Exception("Upload record insert failed: " . $upload_stmt->error);
                }
                $upload_stmt->close();
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Submit New Research — RMS</title>
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
      <div class="nav-item" onclick="location.href='my-research.php'">
        <span class="icon">📁</span>
        <span>My Research</span>
      </div>
      <div class="nav-item active" onclick="location.href='submit-research.php'">
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
            <div>
              <label for="proposal" style="display: block; margin-bottom: 6px; font-weight: 500; color: var(--text-dark);">
                Upload Proposal <span style="color: #999;">(Optional)</span>
              </label>
              <!-- @rms-ui: enhanced file upload area with drag-and-drop -->
              <input 
                type="file" 
                id="proposal" 
                name="proposal" 
                class="form-control"
                accept=".pdf,.doc,.docx"
              />
              <small style="color: var(--text-light); margin-top: 8px; display: block;">
                📋 <strong>Accepted formats:</strong> PDF, DOC, DOCX | <strong>Max size:</strong> 10 MB
              </small>
              <small style="color: var(--text-light); margin-top: 4px; display: block;">
                You can skip this and upload chapters later during the review process.
              </small>
            </div>
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

<script>
// Sidebar menu item click handlers
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
  });
});

// File input validation preview
document.getElementById('proposal')?.addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    const maxSize = 10 * 1024 * 1024; // 10 MB
    const allowedExtensions = ['pdf', 'doc', 'docx'];
    const fileExtension = file.name.split('.').pop().toLowerCase();

    if (file.size > maxSize) {
      alert('File size exceeds 10 MB limit. Please choose a smaller file.');
      e.target.value = '';
    } else if (!allowedExtensions.includes(fileExtension)) {
      alert('Invalid file format. Accepted formats: PDF, DOC, DOCX.');
      e.target.value = '';
    }
  }
});
</script>
</body>
</html>
