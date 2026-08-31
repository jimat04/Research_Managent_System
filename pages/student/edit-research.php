<?php
/**
 * Student — Edit Research
 *
 * Lets the owning student edit title, category, AY, research area, abstract,
 * and (optionally) attach a new proposal document. Editing is restricted to
 * `draft`, `for_revision`, and `revision_required` statuses. Anything that's
 * already in review cannot be edited here — that's the faculty/staff's lane.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

requireRole('student');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

$project_id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0);

$errors         = [];
$success        = false;
$success_message = '';

// research_projects.deleted_at is added by the migration but not in the base dump.
$rp_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$rp_has_deleted_at = false;
if ($rp_deleted_column_stmt) {
    $rp_deleted_column_stmt->execute();
    $rp_has_deleted_at = $rp_deleted_column_stmt->get_result()->num_rows > 0;
    $rp_deleted_column_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND deleted_at IS NULL' : '';

// Load the project — must be owned by the current user
$project = null;
if ($project_id > 0) {
    $stmt = $conn->prepare("
        SELECT project_id, title, category_id, ay_id, research_area, abstract, status, created_by
        FROM research_projects
        WHERE project_id = ?" . $rp_deleted_filter . "
          AND created_by = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $project_id, $user_id);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if (!$project) {
    $_SESSION['module_error'] = 'Project not found, or you do not have permission to edit it.';
    header('Location: ' . SITE_URL . 'pages/student/my-research.php');
    exit;
}

$is_locked = !in_array($project['status'], ['draft', 'for_revision', 'revision_required'], true);

// Form field defaults — pre-fill from DB on first GET
$title          = (string) $project['title'];
$category_id    = (int) $project['category_id'];
$ay_id          = (int) $project['ay_id'];
$research_area  = (string) ($project['research_area'] ?? '');
$abstract       = (string) ($project['abstract']    ?? '');

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lock the form on submit too — re-verify status server-side
    if ($is_locked) {
        $errors[] = 'This project can no longer be edited because it is already ' . $project['status'] . '.';
    }

    $title          = isset($_POST['title'])          ? trim($_POST['title'])          : '';
    $category_id    = isset($_POST['category_id'])    ? (int) $_POST['category_id']    : 0;
    $ay_id          = isset($_POST['ay_id'])          ? (int) $_POST['ay_id']          : 0;
    $research_area  = isset($_POST['research_area'])  ? trim($_POST['research_area'])  : '';
    $abstract       = isset($_POST['abstract'])       ? trim($_POST['abstract'])       : '';

    if (empty($title)) {
        $errors[] = 'Project Title is required.';
    } elseif (strlen($title) > 255) {
        $errors[] = 'Project Title must not exceed 255 characters.';
    }

    if (empty($category_id)) {
        $errors[] = 'Research Category is required.';
    } else {
        $cat_stmt = $conn->prepare("SELECT category_id FROM research_categories WHERE category_id = ? AND status = 1");
        $cat_stmt->bind_param('i', $category_id);
        $cat_stmt->execute();
        if ($cat_stmt->get_result()->num_rows === 0) {
            $errors[] = 'Invalid Research Category selected.';
        }
        $cat_stmt->close();
    }

    if (empty($ay_id)) {
        $errors[] = 'Academic Year / Semester is required.';
    } else {
        $ay_stmt = $conn->prepare("SELECT ay_id FROM academic_years WHERE ay_id = ? AND is_active = 1");
        $ay_stmt->bind_param('i', $ay_id);
        $ay_stmt->execute();
        if ($ay_stmt->get_result()->num_rows === 0) {
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

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE research_projects
                SET title = ?, category_id = ?, ay_id = ?, research_area = ?, abstract = ?, updated_at = NOW()
                WHERE project_id = ? AND created_by = ?
            ");
            if (!$upd) {
                throw new Exception('Query error: ' . $conn->error);
            }
            $upd->bind_param('siissii', $title, $category_id, $ay_id, $research_area, $abstract, $project_id, $user_id);
            if (!$upd->execute()) {
                throw new Exception('Update failed: ' . $upd->error);
            }
            $upd->close();

            // Optional new proposal file upload
            if (isset($_FILES['proposal_file']) && !empty($_FILES['proposal_file']['name'])) {
                $upload_result = handleRmsUpload([
                    'inputName'    => 'proposal_file',
                    'folderTarget' => 'proposals',
                    'maxSize'      => 10000,
                    'accept'       => ['.pdf', '.doc', '.docx'],
                    'projectId'    => $project_id,
                    'type'         => 'proposal',
                ], $_FILES, $conn);

                if (!$upload_result['success']) {
                    throw new Exception('File upload failed: ' . $upload_result['error']);
                }
            }

            logActivity('Updated research project', 'research');

            $conn->commit();
            $_SESSION['module_success'] = 'Project updated successfully.';
            header('Location: ' . SITE_URL . 'pages/student/research-detail.php?id=' . $project_id);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Dropdowns (same source as submit-research.php)
$categories = [];
$cat_result = $conn->query("SELECT category_id, category_name FROM research_categories WHERE status = 1 ORDER BY category_name ASC");
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) { $categories[] = $row; }
}

$academic_years = [];
$ay_result = $conn->query("SELECT ay_id, label, semester FROM academic_years WHERE is_active = 1 ORDER BY label DESC, semester ASC");
if ($ay_result) {
    while ($row = $ay_result->fetch_assoc()) { $academic_years[] = $row; }
}

$page_title = 'Edit Research';
renderStudentShell($user, 'my-research', $page_title, 'Update your research project details.');
?>

<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/file-uploader.css">

<style>
  .back-link {
    color: #5B1EBC;
    text-decoration: none;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 20px;
  }
  .back-link:hover { text-decoration: underline; }

  .card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 20px;
  }
  .card-header { margin-bottom: 16px; }
  .card-title   { font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 4px 0; }
  .card-sub     { font-size: 13px; color: #64748B; margin: 0; }

  .form-group   { margin-bottom: 20px; }
  .form-label   { display: block; font-size: 14px; font-weight: 600; color: #111827; margin-bottom: 8px; }
  .form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.2s;
    background: #fff;
    color: #111827;
  }
  .form-control:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }
  .form-control:disabled { background: #F1F5F9; color: #64748B; cursor: not-allowed; }
  textarea.form-control { resize: vertical; min-height: 140px; }
  select.form-control  { cursor: pointer; }
  .form-hint { font-size: 12px; color: #64748B; margin-top: 6px; display: block; }

  .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #16A34A; border: 1px solid #86EFAC; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; }
  .alert ul { margin: 8px 0 0 20px; padding: 0; }

  .badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 600;
  }
  .badge-slate  { background: #F1F5F9; color: #475569; }
  .badge-orange { background: #FEF3C7; color: #EA580C; }

  .btn {
    display: inline-flex; align-items: center; gap: 8px;
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
    background: #5B1EBC; color: white;
  }
  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(91, 30, 188, 0.3);
  }
  .btn-secondary {
    background: #F8FAFC; color: #111827; border: 1px solid #E5E7EB;
  }
  .btn-secondary:hover { background: #111827; color: white; }
  .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
</style>

<a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project_id; ?>" class="back-link">← Back to Project</a>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <strong>❌ Please fix the following errors:</strong>
    <ul>
      <?php foreach ($errors as $error): ?>
        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($is_locked): ?>
  <div class="card" style="text-align: center; padding: 40px;">
    <div style="font-size: 48px; margin-bottom: 12px;">🔒</div>
    <h3 style="margin: 0 0 8px 0; color: #111827;">Editing is locked</h3>
    <p style="margin: 0 0 20px 0; color: #64748B;">
      This project is currently in <span class="badge badge-orange"><?php echo htmlspecialchars(str_replace('_', ' ', $project['status']), ENT_QUOTES, 'UTF-8'); ?></span> and can no longer be edited.
      If changes are needed, contact your adviser or the research office.
    </p>
    <a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project_id; ?>" class="btn btn-primary">Back to Project</a>
  </div>
<?php else: ?>

<form method="POST" enctype="multipart/form-data">
  <?php echo csrfField(); ?>
  <input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">

  <!-- BASIC INFORMATION -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Basic Information</div>
      <p class="card-sub">Update the identifying details of your research project</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">Project Title <span style="color: #ef4444;">*</span></label>
      <input type="text" id="title" name="title" class="form-control"
             value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
             maxlength="255" required>
      <small class="form-hint">Max 255 characters</small>
    </div>

    <div class="form-group">
      <label class="form-label" for="category_id">Research Category <span style="color: #ef4444;">*</span></label>
      <select id="category_id" name="category_id" class="form-control" required>
        <option value="">-- Select Category --</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?php echo (int) $cat['category_id']; ?>"
            <?php echo (int) $category_id === (int) $cat['category_id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="ay_id">Academic Year / Semester <span style="color: #ef4444;">*</span></label>
      <select id="ay_id" name="ay_id" class="form-control" required>
        <option value="">-- Select Academic Year --</option>
        <?php foreach ($academic_years as $ay): ?>
          <option value="<?php echo (int) $ay['ay_id']; ?>"
            <?php echo (int) $ay_id === (int) $ay['ay_id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($ay['label'] . ' — ' . $ay['semester'], ENT_QUOTES, 'UTF-8'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="research_area">Research Area <span style="color: #999;">(Optional)</span></label>
      <input type="text" id="research_area" name="research_area" class="form-control"
             value="<?php echo htmlspecialchars($research_area, ENT_QUOTES, 'UTF-8'); ?>"
             maxlength="150" placeholder="e.g., Computer Science, Social Sciences, etc.">
      <small class="form-hint">Max 150 characters</small>
    </div>
  </div>

  <!-- ABSTRACT -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Abstract</div>
      <p class="card-sub">Purpose, methods, and expected outcomes</p>
    </div>
    <div class="form-group">
      <label class="form-label" for="abstract">Research Abstract <span style="color: #ef4444;">*</span></label>
      <textarea id="abstract" name="abstract" class="form-control" rows="8" required
                placeholder="Describe the purpose, methods, and expected outcomes of your research."><?php echo htmlspecialchars($abstract, ENT_QUOTES, 'UTF-8'); ?></textarea>
      <small class="form-hint">Max 5000 characters</small>
    </div>
  </div>

  <!-- PROPOSAL DOCUMENT (optional replacement) -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Proposal Document</div>
      <p class="card-sub">Optionally upload a new version of your proposal</p>
    </div>
    <?php
    echo renderFileUploader([
      'inputName'          => 'proposal_file',
      'accept'             => '.pdf,.doc,.docx',
      'maxSize'            => 10000,
      'folderTarget'       => 'proposals',
      'label'              => 'Upload New Proposal',
      'description'        => 'Drag & drop a new version or click to browse',
      'allowedFormatsText' => 'PDF, DOC, DOCX • Max 10 MB',
      'required'           => false,
    ]);
    ?>
    <small class="form-hint" style="margin-top: 4px;">Leave empty to keep the existing file.</small>
  </div>

  <!-- SUBMIT BAR -->
  <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
    <a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project_id; ?>" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
  </div>
</form>

<?php endif; ?>

<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>

<?php renderStudentShellClose(); ?>
