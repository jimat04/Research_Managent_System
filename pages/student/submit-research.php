<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

requireRole('student');

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

/**
 * Co-researcher helpers
 *
 * Cap the team at 5 total members (1 lead + up to 4 co-researchers).
 * Server-side re-verify: user must exist, be active, be a student, not
 * already a member, and not the requester themselves.
 */
if (!defined('CRC_MAX_TOTAL_MEMBERS'))   define('CRC_MAX_TOTAL_MEMBERS', 5);
if (!defined('CRC_MAX_CO_RESEARCHERS'))  define('CRC_MAX_CO_RESEARCHERS', CRC_MAX_TOTAL_MEMBERS - 1);

function crc_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalise a raw POST/GET co_researchers[] payload into a unique list of
 * positive ints, dropping 0 / non-numeric / duplicates.
 */
function crc_normalize_ids($raw) {
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $v) {
        $id = (int) $v;
        if ($id > 0 && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * Validate a list of candidate user IDs as eligible co-researchers.
 * Returns an array of valid user rows ['user_id','first_name','last_name',
 * 'email','student_id'], dropping:
 *   - the requester themselves
 *   - non-students
 *   - inactive users
 *   - duplicates
 */
function crc_validate_candidates($conn, $candidate_ids, $requester_id) {
    $candidates = crc_normalize_ids($candidate_ids);
    if (empty($candidates)) return [];
    // Drop self
    $candidates = array_values(array_filter($candidates, function ($id) use ($requester_id) {
        return (int) $id !== (int) $requester_id;
    }));
    if (empty($candidates)) return [];

    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $types = str_repeat('i', count($candidates));
    $sql = "SELECT user_id, first_name, last_name, email, student_id
            FROM users
            WHERE user_id IN ($placeholders)
              AND role = 'student'
              AND status = 'active'
            ORDER BY last_name ASC, first_name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$candidates);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

/**
 * Search active students (excluding self) by name / student_id / email.
 * Returns up to $limit rows.
 */
function crc_search_students($conn, $query, $requester_id, $limit = 8) {
    $query = trim((string) $query);
    if ($query === '') return [];
    $like = '%' . $query . '%';
    $sql = "SELECT user_id, first_name, last_name, email, student_id
            FROM users
            WHERE role = 'student'
              AND status = 'active'
              AND user_id <> ?
              AND (first_name LIKE ?
                   OR last_name LIKE ?
                   OR CONCAT(first_name, ' ', last_name) LIKE ?
                   OR email LIKE ?
                   OR student_id LIKE ?)
            ORDER BY last_name ASC, first_name ASC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $requester_id = (int) $requester_id;
    $limit = (int) $limit;
    $stmt->bind_param('isssssi', $requester_id, $like, $like, $like, $like, $like, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

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

// Co-researcher search state (for the picker UI on the form)
$crc_search_query    = isset($_GET['search']) ? trim((string) $_GET['search']) : (isset($_POST['crc_search']) ? trim((string) $_POST['crc_search']) : '');
$crc_search_results  = [];
$crc_selected        = [];   // array of user rows currently picked
$crc_selected_ids    = [];   // just the ids, for quick lookup

// Picker add/remove — GET-driven (?add=N / ?remove=N) so the form keeps its
// draft state. We track picks in session so they survive across GETs
// (e.g. user types search, hits search, then clicks + Add).
if (!isset($_SESSION['crc_picks']) || !is_array($_SESSION['crc_picks'])) {
    $_SESSION['crc_picks'] = [];
}
$crc_picks = &$_SESSION['crc_picks'];

if (isset($_GET['add']) && !$success) {
    $crc_add_id = (int) $_GET['add'];
    if ($crc_add_id > 0 && !in_array($crc_add_id, $crc_picks, true) && $crc_add_id !== $user_id) {
        // Verify eligible before adding to session
        $crc_check = crc_validate_candidates($conn, [$crc_add_id], $user_id);
        if (!empty($crc_check)) {
            if (count($crc_picks) < CRC_MAX_CO_RESEARCHERS) {
                $crc_picks[] = $crc_add_id;
            }
        }
    }
    $crc_redirect = SITE_URL . 'pages/student/submit-research.php';
    $crc_qs = [];
    if ($crc_search_query !== '') $crc_qs['search'] = $crc_search_query;
    if (!empty($crc_qs)) $crc_redirect .= '?' . http_build_query($crc_qs);
    header('Location: ' . $crc_redirect);
    exit;
}

if (isset($_GET['remove']) && !$success) {
    $crc_rm_id = (int) $_GET['remove'];
    if ($crc_rm_id > 0) {
        $crc_picks = array_values(array_filter($crc_picks, function ($id) use ($crc_rm_id) {
            return (int) $id !== $crc_rm_id;
        }));
    }
    $crc_redirect = SITE_URL . 'pages/student/submit-research.php';
    $crc_qs = [];
    if ($crc_search_query !== '') $crc_qs['search'] = $crc_search_query;
    if (!empty($crc_qs)) $crc_redirect .= '?' . http_build_query($crc_qs);
    header('Location: ' . $crc_redirect);
    exit;
}

// After successful submission, clear the picker state so the next visit
// starts clean. Must happen before render.
if ($success) {
    $_SESSION['crc_picks'] = [];
    $crc_picks = [];
}

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

    // Raw co-researcher IDs from the form (hidden inputs).
    // Server-side validation re-checks existence, role, and active status.
    $crc_raw_ids = crc_normalize_ids($_POST['co_researchers'] ?? []);

    if (count($crc_raw_ids) > CRC_MAX_CO_RESEARCHERS) {
        $errors[] = 'You can add at most ' . CRC_MAX_CO_RESEARCHERS . ' co-researchers (5 members total including you).';
    }

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

            // Resolve and insert co-researchers (role 'member').
            // Re-validate every candidate against users (existence + active
            // student) and dedupe in case the form was tampered with.
            $crc_valid = crc_validate_candidates($conn, $crc_raw_ids, $user_id);
            if (count($crc_valid) > CRC_MAX_CO_RESEARCHERS) {
                $crc_valid = array_slice($crc_valid, 0, CRC_MAX_CO_RESEARCHERS);
            }
            $crc_member_stmt = $conn->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')");
            if (!$crc_member_stmt) {
                throw new Exception("Query error: " . $conn->error);
            }
            foreach ($crc_valid as $crc_row) {
                $crc_uid = (int) $crc_row['user_id'];
                $crc_member_stmt->bind_param("ii", $new_project_id, $crc_uid);
                if (!$crc_member_stmt->execute()) {
                    // UNIQUE (project_id, user_id) — silently skip dupes that
                    // slipped past crc_normalize_ids (defence in depth).
                    if ($crc_member_stmt->errno !== 1062) {
                        throw new Exception("Co-researcher insert failed: " . $crc_member_stmt->error);
                    }
                }
            }
            $crc_member_stmt->close();
            $crc_added = $crc_valid; // for the notification pass below

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

            // Notify each co-researcher that they were added to the team
            $crc_project_title = $title;
            $crc_project_link  = SITE_URL . 'pages/student/research-detail.php?id=' . (int) $new_project_id;
            $crc_inviter_name  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            foreach ($crc_added as $crc_row) {
                $crc_target = (int) $crc_row['user_id'];
                $crc_name   = trim(($crc_row['first_name'] ?? '') . ' ' . ($crc_row['last_name'] ?? ''));
                createNotification(
                    $crc_target,
                    'Added as co-researcher',
                    $crc_inviter_name . ' added you as a co-researcher on "' . $crc_project_title . '".',
                    'info',
                    $crc_project_link
                );
            }

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

// Co-researcher picker state (only relevant on GET; after a failed POST
// we re-hydrate selection from the raw POST so the user's picks persist).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Hydrate from session on GET (after add/remove, picks are in session)
    $crc_raw_ids = array_values(array_unique(array_map('intval', $crc_picks)));
    $crc_raw_ids = array_values(array_filter($crc_raw_ids, function ($id) {
        return $id > 0;
    }));
}
if (!empty($crc_raw_ids)) {
    $crc_selected = crc_validate_candidates($conn, $crc_raw_ids, $user_id);
}
foreach ($crc_selected as $crc_row) {
    $crc_selected_ids[] = (int) $crc_row['user_id'];
}

// Run the live search (only when there is a query). Filter out anyone
// already picked so they don't show in "available" results.
if ($crc_search_query !== '') {
    $crc_search_results = crc_search_students($conn, $crc_search_query, $user_id, 8);
    if (!empty($crc_selected_ids)) {
        $crc_search_results = array_values(array_filter($crc_search_results, function ($r) use ($crc_selected_ids) {
            return !in_array((int) $r['user_id'], $crc_selected_ids, true);
        }));
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

  /* Co-researcher picker */
  .crc-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 6px 6px 12px;
    background: rgba(91, 30, 188, 0.08);
    border: 1px solid rgba(91, 30, 188, 0.25);
    color: #5B1EBC;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 500;
  }
  .crc-pill-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(91, 30, 188, 0.15);
    color: #5B1EBC;
    text-decoration: none;
    font-size: 16px;
    line-height: 1;
    font-weight: 700;
  }
  .crc-pill-remove:hover {
    background: #DC2626;
    color: #fff;
  }

  .crc-result-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
  }
  .crc-result {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
  }
  .crc-result-info { flex: 1; min-width: 0; }
  .crc-result-name {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
  }
  .crc-result-meta {
    font-size: 12px;
    color: #64748B;
    margin-top: 2px;
    word-break: break-word;
  }
  .crc-empty {
    padding: 16px;
    text-align: center;
    color: #64748B;
    font-size: 13px;
    background: #F8FAFC;
    border: 1px dashed #E5E7EB;
    border-radius: 10px;
    margin-top: 8px;
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
<form method="POST" enctype="multipart/form-data" action="<?php echo SITE_URL; ?>pages/student/submit-research.php">
  <?php echo csrfField(); ?>
  <input type="hidden" name="crc_search" value="<?php echo crc_se($crc_search_query); ?>">
  <?php foreach ($crc_selected_ids as $crc_sid): ?>
    <input type="hidden" name="co_researchers[]" value="<?php echo (int) $crc_sid; ?>">
  <?php endforeach; ?>
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

      <div>
        <label for="crc_search_input" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
          Co-researchers <span style="color: #999;">(Optional)</span>
        </label>

        <form method="GET" action="<?php echo SITE_URL; ?>pages/student/submit-research.php" style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input
            type="text"
            id="crc_search_input"
            name="search"
            class="form-control"
            placeholder="Search by name, student ID, or email…"
            value="<?php echo crc_se($crc_search_query); ?>"
            style="flex: 1;"
            maxlength="100"
          />
          <button type="submit" class="btn btn-secondary">🔍 Search</button>
        </form>

        <?php
          $crc_remaining = CRC_MAX_CO_RESEARCHERS - count($crc_selected_ids);
        ?>

        <?php if (!empty($crc_selected)): ?>
          <div style="margin-bottom: 12px;">
            <small style="display: block; color: #64748B; margin-bottom: 6px;">
              Selected (<?php echo count($crc_selected_ids); ?> / <?php echo CRC_MAX_CO_RESEARCHERS; ?>)
            </small>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
              <?php foreach ($crc_selected as $crc_row): ?>
                <span class="crc-pill">
                  🎒 <?php echo crc_se(trim($crc_row['first_name'] . ' ' . $crc_row['last_name'])); ?>
                  <?php if (!empty($crc_row['student_id'])): ?>
                    <small style="opacity: 0.7; margin-left: 4px;"><?php echo crc_se($crc_row['student_id']); ?></small>
                  <?php endif; ?>
                  <a
                    href="<?php echo SITE_URL; ?>pages/student/submit-research.php?<?php echo crc_se(http_build_query(array_filter(['search' => $crc_search_query, 'remove' => (int) $crc_row['user_id']]))); ?>"
                    class="crc-pill-remove"
                    title="Remove"
                    aria-label="Remove co-researcher"
                  >×</a>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($crc_search_query !== ''): ?>
          <?php if (empty($crc_search_results)): ?>
            <div class="crc-empty">
              No matching students found. Try a different name, student ID, or email.
            </div>
          <?php else: ?>
            <small style="display: block; color: #64748B; margin-bottom: 6px;">
              Results for "<?php echo crc_se($crc_search_query); ?>"
              <?php if ($crc_remaining <= 0): ?>
                <span style="color: #EA580C;">— team is full. Remove someone before adding more.</span>
              <?php endif; ?>
            </small>
            <div class="crc-result-list">
              <?php foreach ($crc_search_results as $crc_row): ?>
                <?php
                  $crc_disabled = $crc_remaining <= 0;
                  $crc_link = SITE_URL . 'pages/student/submit-research.php?' . http_build_query(array_filter([
                    'search' => $crc_search_query,
                    'add'    => (int) $crc_row['user_id'],
                  ]));
                ?>
                <div class="crc-result">
                  <div class="crc-result-info">
                    <div class="crc-result-name">
                      🎒 <?php echo crc_se(trim($crc_row['first_name'] . ' ' . $crc_row['last_name'])); ?>
                    </div>
                    <div class="crc-result-meta">
                      <?php if (!empty($crc_row['student_id'])): ?>
                        <?php echo crc_se($crc_row['student_id']); ?>
                      <?php endif; ?>
                      <?php if (!empty($crc_row['email'])): ?>
                        · <?php echo crc_se($crc_row['email']); ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($crc_disabled): ?>
                    <button type="button" class="btn btn-secondary" disabled style="font-size: 13px; padding: 6px 14px;">+ Add</button>
                  <?php else: ?>
                    <a href="<?php echo crc_se($crc_link); ?>" class="btn btn-primary" style="font-size: 13px; padding: 6px 14px;">+ Add</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <small style="color: #64748B; display: block;">
            💡 You can add up to <?php echo CRC_MAX_CO_RESEARCHERS; ?> co-researchers. Search by their name, student ID, or email to add them to the team.
          </small>
        <?php endif; ?>
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
