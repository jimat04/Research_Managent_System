<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
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
    $status = isset($_POST['status']) && in_array($_POST['status'], ['draft', 'submitted'], true) ? $_POST['status'] : 'draft';
    if ($status === 'proposal') $status = 'submitted';
    if (!in_array($status, ['draft', 'submitted'], true)) $status = 'draft';

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

            $insert_stmt->bind_param("siisssi", $title, $category_id, $ay_id, $research_area, $abstract, $status, $user_id);
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

renderStudentShell($user, 'submit-research', 'Submit New Research', 'Fill in your research details and upload your proposal document for CREC review.');
?>

<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/file-uploader.css">

<style>
  .card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }

  .form-group {
    margin-bottom: 24px;
  }

  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 8px;
  }

  .form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.2s;
  }

  .form-control:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
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
    color: #64748B;
    margin-top: 6px;
  }

  .alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 14px;
  }

  .alert-success {
    background: #DCFCE7;
    color: #16A34A;
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
</style>

<!-- BREADCRUMB -->
<div style="margin-bottom: 20px;">
  <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" style="color: #5B1EBC; text-decoration: none; font-size: 14px;">← Back to My Research</a>
</div>

<?php if ($success): ?>
  <!-- SUCCESS ALERT -->
  <div class="alert alert-success" style="margin-bottom: 20px;">
    <strong>✅ Success!</strong> <?php echo htmlspecialchars($success_message); ?>
    <br><a href="<?php echo SITE_URL; ?>pages/student/my-research.php" style="color: inherit; text-decoration: underline;">View your research project →</a>
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
        <label for="title" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
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
        <small style="color: #64748B; margin-top: 4px; display: block;">Max 255 characters</small>
      </div>

      <!-- Research Category -->
      <div>
        <label for="category_id" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
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
        <label for="ay_id" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
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
        <label for="research_area" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
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
        <small style="color: #64748B; margin-top: 4px; display: block;">Max 150 characters</small>
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
        <label style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">Lead Researcher</label>
        <div style="padding: 10px 12px; background-color: #f0f4f8; border-radius: 6px; border: 1px solid #E5E7EB; color: #111827;">
          <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
        </div>
        <small style="color: #64748B; margin-top: 4px; display: block;">You are automatically set as the lead researcher.</small>
      </div>

      <div style="padding: 12px; background-color: #f0f4f8; border-radius: 6px; border-left: 3px solid #5B1EBC; color: #111827; font-size: 14px;">
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
        <label for="abstract" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
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
        <small style="color: #64748B; margin-top: 4px; display: block;">Max 5000 characters</small>
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
      <small style="color: #64748B; margin-top: -12px; display: block;">
        💡 You can skip this and upload chapters later during the review process.
      </small>
    </div>
  </div>

  <!-- SUBMIT BUTTONS -->
  <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
    <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" name="status" value="draft" class="btn btn-secondary">Save as Draft</button>
    <button type="submit" name="status" value="submitted" class="btn btn-primary">Submit for Review</button>
  </div>
</form>
<?php endif; ?>

<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>

<?php renderStudentShellClose(); ?>
