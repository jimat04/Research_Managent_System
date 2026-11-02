<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/academic-years.php';

requireRole('student');

$user = getCurrentUser();
$user_id = (int) $user['user_id'];
$student_research_program_id = ($user['role'] ?? '') === 'student'
    ? rms_student_research_program_id($conn, $user_id)
    : 0;
$student_program_eligible = ($user['role'] ?? '') !== 'student' || $student_research_program_id > 0;

if (rms_student_has_approved_title($conn, $user_id)) {
    renderStudentShell($user, 'submit-research', 'Research Title Approved', 'Continue with your current research project.');
    ?>
    <main class="approved-title-page">
      <section class="approved-title-card" aria-labelledby="approved-title-heading">
        <span class="approved-title-mark" aria-hidden="true">✓</span>
        <p class="approved-title-eyebrow">Research workflow · Title review complete</p>
        <h2 id="approved-title-heading">Your research title is approved</h2>
        <p class="approved-title-copy">Continue your current project from My Research. Your next steps and chapter progress are available there.</p>
        <a class="approved-title-action" href="<?php echo htmlspecialchars(SITE_URL . 'pages/researcher/my-research.php', ENT_QUOTES, 'UTF-8'); ?>">Open My Research <span aria-hidden="true">→</span></a>
      </section>
    </main>
    <?php
    renderStudentShellClose();
    exit;
}

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
              AND EXISTS (
                  SELECT 1
                    FROM programs p
                   WHERE p.status = 1
                     AND p.program_code IN ('BSIT', 'BSCS')
                     AND (
                         UPPER(TRIM(p.program_code)) = UPPER(TRIM(users.program))
                         OR UPPER(TRIM(p.program_name)) = UPPER(TRIM(users.program))
                     )
              )
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
              AND EXISTS (
                  SELECT 1
                    FROM programs p
                   WHERE p.status = 1
                     AND p.program_code IN ('BSIT', 'BSCS')
                     AND (
                         UPPER(TRIM(p.program_code)) = UPPER(TRIM(users.program))
                         OR UPPER(TRIM(p.program_name)) = UPPER(TRIM(users.program))
                     )
              )
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
$submission_nonce = $_SESSION['research_title_submission_nonce'] ?? '';
if (!is_string($submission_nonce) || $submission_nonce === '') {
    $submission_nonce = bin2hex(random_bytes(32));
    $_SESSION['research_title_submission_nonce'] = $submission_nonce;
}

// Form field defaults
$title_options = ['', '', ''];
$title = '';
$category_id = '';
$category_name = '';
$ay_id = '';
$academic_years = rms_academic_year_options($conn);
$academic_year = rms_current_academic_year();
$research_area_ids = [];
$title_classifications = array_fill(0, 3, ['category_id' => 0, 'area_id' => 0]);
$abstract = '';
$status = 'draft';

// Co-researcher search state (for the picker UI on the form)
$crc_search_query    = isset($_POST['crc_search']) ? trim((string) $_POST['crc_search']) : '';
$crc_search_results  = [];
$crc_selected        = [];   // array of user rows currently picked
$crc_selected_ids    = [];   // just the ids, for quick lookup
$crc_raw_ids         = [];
$crc_is_picker_post  = false;

// Picker add/remove/search are POST-driven (crc_do=search|add|remove) so the
// in-progress project fields survive via the normal
// POST re-render. We track picks in session so they persist across picker
// actions and across the eventual real submit.
if (!isset($_SESSION['crc_picks']) || !is_array($_SESSION['crc_picks'])) {
    $_SESSION['crc_picks'] = [];
}
$crc_picks = &$_SESSION['crc_picks'];

// A plain GET always starts a fresh form. Do not leak picks from an
// abandoned submission into the next visit.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['crc_picks'] = [];
    $crc_picks = [];
}

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    // Preserve the visible draft even when the token expired; no picker or
    // project action is performed until the user submits a valid token.
    if (is_array($_POST['title_options'] ?? null)) {
        foreach (array_slice(array_values($_POST['title_options']), 0, 3) as $index => $value) {
            if (is_string($value)) {
                $title_options[$index] = trim($value);
            }
        }
    }
    $title = $title_options[0];
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : '';
    $academic_year = is_string($_POST['academic_year'] ?? null) ? trim($_POST['academic_year']) : '';
    $research_area_ids = is_array($_POST['research_area_ids'] ?? null) ? array_values($_POST['research_area_ids']) : [];
    $title_classifications = is_array($_POST['title_classifications'] ?? null) ? array_slice(array_values($_POST['title_classifications']), 0, 3) : $title_classifications;
    $status = isset($_POST['status']) && in_array($_POST['status'], ['draft', 'submitted'], true) ? $_POST['status'] : 'draft';
    $crc_raw_ids = crc_normalize_ids($_POST['co_researchers'] ?? []);
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form values
    if (is_array($_POST['title_options'] ?? null)) {
        foreach (array_slice(array_values($_POST['title_options']), 0, 3) as $index => $value) {
            if (is_string($value)) {
                $title_options[$index] = trim($value);
            }
        }
    }
    $title = $title_options[0];
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : '';
    $academic_year = is_string($_POST['academic_year'] ?? null) ? trim($_POST['academic_year']) : '';
    $research_area_ids = is_array($_POST['research_area_ids'] ?? null) ? array_values($_POST['research_area_ids']) : [];
    $title_classifications = is_array($_POST['title_classifications'] ?? null) ? array_slice(array_values($_POST['title_classifications']), 0, 3) : $title_classifications;
    $status = isset($_POST['status']) && in_array($_POST['status'], ['draft', 'submitted'], true) ? $_POST['status'] : 'draft';
    if ($status === 'proposal') $status = 'submitted';
    if (!in_array($status, ['draft', 'submitted'], true)) $status = 'draft';
    if (isset($_POST['status'])
        && (!is_string($_POST['submission_nonce'] ?? null)
            || !hash_equals($submission_nonce, (string) $_POST['submission_nonce']))) {
        $errors[] = 'This form was already submitted or has expired. Reload the page before trying again.';
    }

    // Raw co-researcher IDs from the form (hidden inputs).
    // Server-side validation re-checks existence, role, and active status.
    $crc_raw_ids = crc_normalize_ids($_POST['co_researchers'] ?? []);

    // Picker controls post through this same form. They only update picker
    // state and re-render; project validation and insertion belong solely to
    // the existing submit buttons named "status".
    $crc_picker_action = '';
    $crc_picker_id = 0;
    if (isset($_POST['crc_do']) && $_POST['crc_do'] === 'search') {
        $crc_picker_action = 'search';
    } elseif (isset($_POST['crc_add'])) {
        $crc_picker_action = 'add';
        $crc_picker_id = (int) $_POST['crc_add'];
    } elseif (isset($_POST['crc_remove'])) {
        $crc_picker_action = 'remove';
        $crc_picker_id = (int) $_POST['crc_remove'];
    }
    $crc_is_picker_post = $crc_picker_action !== '';

    if ($crc_is_picker_post) {
        // Rebuild the session from eligible posted candidates first, then
        // apply the requested action. This retains self-exclusion, active
        // student validation, deduplication, and the team-size cap.
        $crc_valid_current = crc_validate_candidates($conn, $crc_raw_ids, $user_id);
        $crc_picks = array_map(function ($row) {
            return (int) $row['user_id'];
        }, array_slice($crc_valid_current, 0, CRC_MAX_CO_RESEARCHERS));

        if ($crc_picker_action === 'add'
            && $crc_picker_id > 0
            && $crc_picker_id !== $user_id
            && !in_array($crc_picker_id, $crc_picks, true)
            && count($crc_picks) < CRC_MAX_CO_RESEARCHERS) {
            $crc_candidate = crc_validate_candidates($conn, [$crc_picker_id], $user_id);
            if (!empty($crc_candidate)) {
                $crc_picks[] = $crc_picker_id;
            }
        } elseif ($crc_picker_action === 'remove' && $crc_picker_id > 0) {
            $crc_picks = array_values(array_filter($crc_picks, function ($id) use ($crc_picker_id) {
                return (int) $id !== $crc_picker_id;
            }));
        }

        $crc_raw_ids = $crc_picks;
    }

    if (!$crc_is_picker_post && count($crc_raw_ids) > CRC_MAX_CO_RESEARCHERS) {
        $errors[] = 'You can add at most ' . CRC_MAX_CO_RESEARCHERS . ' co-researchers (5 members total including you).';
    }

    // Full validation runs only when a real submit button was pressed.
    if (!$crc_is_picker_post && isset($_POST['status'])) {
        try {
            $title_options = rms_validate_title_options($_POST['title_options'] ?? null);
            $title = $title_options[0];
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$crc_is_picker_post && isset($_POST['status']) && ($user['role'] ?? '') !== 'student' && empty($category_id)) {
        $errors[] = 'Research Category is required.';
    } elseif (!$crc_is_picker_post && isset($_POST['status']) && ($user['role'] ?? '') !== 'student') {
        try {
            $category_row = rms_validate_research_category(
                $conn,
                (int) $category_id,
                0,
                $student_research_program_id,
                ($user['role'] ?? '') === 'student'
            );
            $category_name = (string) $category_row['category_name'];
        } catch (InvalidArgumentException $exception) {
            $errors[] = 'Invalid Research Category selected.';
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$crc_is_picker_post && isset($_POST['status']) && ($user['role'] ?? '') === 'student') {
        try {
            $title_classifications = rms_validate_title_classifications($conn, $_POST['title_classifications'] ?? null, [], $student_research_program_id);
            $category_id = (int) $title_classifications[0]['category_id'];
            $category_name = (string) $title_classifications[0]['category_name'];
            $research_area_ids = [(int) $title_classifications[0]['area_id']];
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$crc_is_picker_post && isset($_POST['status'])) {
        try {
            $academic_year = rms_validate_academic_year($_POST['academic_year'] ?? null, $academic_years);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$crc_is_picker_post && isset($_POST['status'])) {
        if (!$student_program_eligible) {
            $errors[] = 'Only active BSIT and BSCS students may submit student research.';
        }
        $eligible_candidates = crc_validate_candidates($conn, $crc_raw_ids, $user_id);
        if (count($eligible_candidates) !== count($crc_raw_ids)) {
            $errors[] = 'Every student co-researcher must be an active BSIT or BSCS student.';
        }
    }

    if (!$crc_is_picker_post && isset($_POST['status']) && ($user['role'] ?? '') !== 'student') {
        try {
            $research_area_ids = rms_validate_research_area_ids($_POST['research_area_ids'] ?? null);
            if ($category_id > 0) {
                rms_validate_mapped_research_areas(
                    $conn,
                    (int) $category_id,
                    $research_area_ids,
                    [],
                    0,
                    $student_research_program_id,
                    ($user['role'] ?? '') === 'student'
                );
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    $proposal_file_selected = isset($_FILES['proposal_file'])
        && !empty($_FILES['proposal_file']['name'])
        && $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$crc_is_picker_post && isset($_POST['status']) && $status === 'submitted' && !$proposal_file_selected) {
        $errors[] = 'A proposal document is required when submitting for review. You may save without a file as a draft.';
    }

    // File upload handling using the RMS file uploader component
    $file_uploaded = false;
    $upload_result = null;
    $upload_id = null;

    // If no errors, proceed with insertion
    if (!$crc_is_picker_post && isset($_POST['status']) && empty($errors)) {
        // Start transaction
        $conn->begin_transaction();

        try {
            if (($user['role'] ?? '') === 'student') {
                $title_classifications = rms_validate_title_classifications($conn, $_POST['title_classifications'] ?? null, [], $student_research_program_id);
                $category_id = (int) $title_classifications[0]['category_id'];
                $category_name = (string) $title_classifications[0]['category_name'];
                $research_area_ids = [(int) $title_classifications[0]['area_id']];
            } else {
                rms_validate_mapped_research_areas($conn, (int) $category_id, $research_area_ids);
            }
            $ay_id = rms_resolve_academic_year($conn, $academic_year);
            // Insert research project
            $legacy_research_area = null;
            $insert_query = "INSERT INTO research_projects
                (title, title_option_1, title_option_2, title_option_3, category_id, ay_id, research_area, abstract, status, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                throw new Exception("Query error: " . $conn->error);
            }

            $initial_project_status = 'draft';
            $insert_stmt->bind_param(
                'ssssiisssi',
                $title,
                $title_options[0],
                $title_options[1],
                $title_options[2],
                $category_id,
                $ay_id,
                $legacy_research_area,
                $abstract,
                $initial_project_status,
                $user_id
            );
            if (!$insert_stmt->execute()) {
                throw new Exception("Insert failed: " . $insert_stmt->error);
            }

            $new_project_id = $conn->insert_id;
            $insert_stmt->close();
            rms_replace_project_areas($conn, (int) $new_project_id, $research_area_ids);
            if (($user['role'] ?? '') === 'student') {
                rms_save_title_classifications($conn, (int) $new_project_id, $title_classifications);
                if (!rms_claim_student_project_slot($conn, $user_id, (int) $new_project_id)) {
                    throw new DomainException('You are already assigned to another research project. Remove that assignment before creating a new project.');
                }
            }

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
                if (!rms_claim_student_project_slot($conn, $crc_uid, (int) $new_project_id)) {
                    throw new DomainException('A selected student is already assigned to another research project. Remove that assignment before adding them.');
                }
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
                    'accept' => ['.pdf'],
                    'projectId' => $new_project_id,
                    'type' => 'proposal',
                    'workflowType' => 'title'
                ], $_FILES, $conn);

                if (!$upload_result['success']) {
                    throw new Exception("File upload failed: " . $upload_result['error']);
                }

                $upload_id = $upload_result['upload_id'];
                $file_uploaded = true;
            }

            if ($status === 'submitted') {
                $submit_project = $conn->prepare("UPDATE research_projects SET status = 'submitted', updated_at = NOW() WHERE project_id = ? AND created_by = ? AND status = 'draft'");
                if (!$submit_project) {
                    throw new Exception('Unable to prepare title submission.');
                }
                $submit_project->bind_param('ii', $new_project_id, $user_id);
                if (!$submit_project->execute() || $submit_project->affected_rows !== 1) {
                    throw new Exception('The research title could not be submitted.');
                }
                $submit_project->close();
                $area_snapshot = rms_research_area_snapshot($conn, $research_area_ids);
                rms_capture_submission_version(
                    $conn,
                    (int) $new_project_id,
                    null,
                    'title',
                    $user_id,
                    [
                        'title' => $title,
                        'title_options' => $title_options,
                        'category_id' => (int) $category_id,
                        'category_name' => $category_name,
                        'ay_id' => (int) $ay_id,
                        'research_area' => implode(', ', array_column($area_snapshot, 'area_name')),
                        'research_areas' => $area_snapshot,
                        'title_classifications' => ($user['role'] ?? '') === 'student' ? $title_classifications : [],
                        'abstract' => $abstract
                    ],
                    $upload_id ? [(int) $upload_id] : []
                );
            }

            // Log activity
            $action_msg = $status === 'draft' ? 'Research project created as draft' : 'Research project submitted for review';
            logActivity($action_msg, 'research');

            // Commit transaction
            $conn->commit();

            // Notify each co-researcher that they were added to the team
            $crc_project_title = $title;
            $crc_project_link  = SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $new_project_id;
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

            if ($status === 'submitted') {
                $student_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $staff_notify_stmt = $conn->prepare(
                    "SELECT user_id FROM users
                     WHERE role IN ('research_staff', 'admin') AND status = 'active'"
                );
                if (!$staff_notify_stmt) {
                    throw new Exception('Unable to prepare proposal submission notifications.');
                }
                if (!$staff_notify_stmt->execute()) {
                    throw new Exception('Unable to load proposal submission notification recipients.');
                }
                $staff_notify_result = $staff_notify_stmt->get_result();
                while ($recipient = $staff_notify_result->fetch_assoc()) {
                    createNotification(
                        (int) $recipient['user_id'],
                        'New research proposal submitted',
                        $student_name . ' submitted "' . $title . '" for review.',
                        'info',
                        SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_proposals'
                    );
                }
                $staff_notify_stmt->close();
            }

            // Set success flag
            $success = true;
            $success_message = $status === 'draft'
                ? 'Research project saved as draft successfully.'
                : 'Research project submitted for review successfully.';
            $submission_nonce = bin2hex(random_bytes(32));
            $_SESSION['research_title_submission_nonce'] = $submission_nonce;
            $_SESSION['crc_picks'] = [];
            $crc_picks = [];

        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $errors[] = $e instanceof DomainException
                ? $e->getMessage()
                : 'Database error: ' . $e->getMessage();
        }
    }
}

// Students receive categories mapped to their account program; faculty retain
// the original shared catalog.
$categories = rms_research_category_catalog(
    $conn,
    ($user['role'] ?? '') === 'student',
    $student_research_program_id
);
$research_areas = rms_research_area_catalog($conn);

// Co-researcher picker state. Picker POSTs and failed submissions re-hydrate
// from their validated posted/session selection.
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

if (($user['role'] ?? '') === 'faculty') {
    renderFacultyShell($user, 'submit-research', 'Submit New Research', 'Save a title draft or submit it for authorized review.');
} else {
    renderStudentShell($user, 'submit-research', 'Submit New Research', 'Save a title draft or submit it for authorized review.');
}
?>

<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/file-uploader.css">

<style>
  .student-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 4%,rgba(91,30,188,.12),transparent 27%),radial-gradient(circle at 8% 42%,rgba(37,99,235,.07),transparent 25%),linear-gradient(180deg,#F4F1FA 0%,#ECE8F5 100%)}
  .student-topbar{background:rgba(255,255,255,.93);backdrop-filter:blur(14px)}
  .submission-page{--purple:#5B1EBC;--purple-dark:#291050;--ink:#120C1C;--muted:#655C74;--line:#DED5EA;max-width:1160px;margin:0 auto;padding-bottom:44px;color:var(--ink)}
  .submission-back{display:inline-flex;align-items:center;gap:8px;margin:0 0 18px;color:#4B168F;font-size:13px;font-weight:700;text-decoration:none;transition:gap .2s ease,color .2s ease}
  .submission-back:hover{gap:11px;color:#741FD1}.submission-back:focus-visible,.btn:focus-visible,.crc-pill-remove:focus-visible{outline:3px solid rgba(91,30,188,.24);outline-offset:3px}
  .submission-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(280px,.7fr);gap:42px;overflow:hidden;margin-bottom:24px;padding:38px 40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 93% 8%,rgba(220,198,255,.25),transparent 28%),radial-gradient(circle at 10% 120%,rgba(44,111,230,.24),transparent 32%),linear-gradient(135deg,#291050 0%,#4C188F 56%,#6C2CC7 100%);color:#fff;box-shadow:0 22px 52px rgba(54,24,103,.22)}
  .submission-hero::after{content:'';position:absolute;right:-76px;bottom:-150px;width:300px;height:300px;border:46px solid rgba(255,255,255,.055);border-radius:50%;pointer-events:none}
  .hero-copy,.hero-checklist{position:relative;z-index:1}.hero-eyebrow{margin:0 0 11px;color:#DCCBFF;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.submission-hero h1{max-width:620px;margin:0;font-size:clamp(31px,3.4vw,48px);font-weight:750;letter-spacing:-.045em;line-height:1.06;text-wrap:balance}.hero-description{max-width:60ch;margin:17px 0 0;color:#E8E0F3;font-size:15px;line-height:1.7}
  .hero-checklist{align-content:center;padding-left:34px;border-left:1px solid rgba(255,255,255,.24)}.hero-checklist-label{margin:0 0 14px;color:#D9CDE9;font-size:12px;font-weight:700}.hero-checklist-list{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0;padding:0;list-style:none}.hero-checklist-list li{display:flex;align-items:center;gap:9px;color:#F4EFFF;font-size:12px;font-weight:650}.hero-checklist-list span{display:grid;width:25px;height:25px;place-items:center;border:1px solid rgba(255,255,255,.25);border-radius:8px;background:rgba(255,255,255,.1);font-size:11px}
  .alert{display:grid;grid-template-columns:auto 1fr;gap:13px;margin:0 0 20px;padding:17px 19px;border-radius:16px;font-size:13px;line-height:1.55}.alert-icon{display:grid;width:31px;height:31px;place-items:center;border-radius:10px;font-weight:800}.alert strong{display:block;margin-bottom:2px;font-size:14px}.alert ul{margin:7px 0 0;padding-left:18px}.alert-success{border:1px solid #A7DDC8;background:#EDFBF5;color:#087A59}.alert-success .alert-icon{background:#D2F4E6}.alert-success a{color:#087A59;font-weight:700}.alert-error{border:1px solid #F1B5B5;background:#FFF1F1;color:#A82323}.alert-error .alert-icon{background:#FFE0E0}
  .proposal-guidance{display:grid;gap:16px;margin:0 0 20px;padding:22px;border:1px solid #D8C7F0;border-left:5px solid var(--purple);border-radius:16px;background:linear-gradient(135deg,#FBF9FF,#F3EDFC);color:#281B35;font-size:14px;line-height:1.6}.proposal-guidance h3{margin:0;color:#291050;font-size:18px;letter-spacing:-.02em}.proposal-guidance-intro{margin:4px 0 0;color:#655C74}.proposal-requirements{display:grid;gap:10px;margin:0;padding:0;list-style:none}.proposal-requirements li{display:grid;grid-template-columns:30px minmax(0,1fr);align-items:start;gap:11px}.proposal-requirement-number{display:grid;width:28px;height:28px;place-items:center;border:1px solid #D8C7F0;border-radius:9px;background:#EEE6FB;color:#4B168F;font-size:12px;font-weight:800}.proposal-requirement-copy strong{display:block;margin:0;color:#281B35;font-size:14px}.proposal-requirement-copy span{display:block;color:#655C74}  .proposal-template-action{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;padding-top:15px;border-top:1px solid #E4DAF0}.proposal-template-hint{max-width:54ch;margin:0;color:#655C74;font-size:12px;line-height:1.5}.proposal-template-button{flex:0 0 auto;min-height:46px;font-size:13px}
  .success-panel{padding:42px;border:1px solid #B8E5D5;border-radius:20px;background:linear-gradient(135deg,#F7FFFC,#EAF9F3);box-shadow:0 18px 44px rgba(32,105,78,.1);text-align:center}.success-mark{display:grid;width:66px;height:66px;margin:0 auto 19px;place-items:center;border-radius:20px;background:#087A59;color:#fff;font-size:28px;box-shadow:0 12px 24px rgba(8,122,89,.2)}.success-panel h2{margin:0;font-size:27px;letter-spacing:-.035em}.success-panel p{margin:10px 0 24px;color:#4D6E61;font-size:14px}
  .submission-layout{display:grid;grid-template-columns:minmax(0,1fr) 282px;gap:24px;align-items:start}.submission-sections{display:grid;gap:18px}.form-section{overflow:hidden;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.95);box-shadow:0 12px 32px rgba(45,24,76,.07)}.form-section.accent-purple{border-top:4px solid var(--purple)}.form-section.accent-blue{border-top:4px solid #2563EB}.form-section.accent-green{border-top:4px solid #07855F}.form-section.accent-amber{border-top:4px solid #C55408}
  .section-header{display:flex;align-items:flex-start;gap:14px;padding:25px 28px 19px;border-bottom:1px solid #EEE8F4}.section-number{display:grid;flex:0 0 auto;width:34px;height:34px;place-items:center;border-radius:11px;background:#EEE6FB;color:var(--purple);font-size:12px;font-weight:800}.accent-blue .section-number{background:#E8F0FF;color:#2563EB}.accent-green .section-number{background:#E3F7EF;color:#07855F}.accent-amber .section-number{background:#FFF0E4;color:#C55408}.section-header h2{margin:0 0 4px;font-size:19px;letter-spacing:-.025em}.section-header p{margin:0;color:var(--muted);font-size:12px;line-height:1.5}.section-body{padding:25px 28px 28px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.field-full{grid-column:1/-1}.form-label{display:block;margin:0 0 8px;color:#241A31;font-size:12px;font-weight:750}.required{color:#B42318}.optional{color:#8B8198;font-weight:550}.form-control{width:100%;min-height:47px;padding:12px 14px;border:1px solid #D8D0E3;border-radius:11px;background:#FCFBFD;color:#171020;font:500 13px/1.5 'Inter',sans-serif;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease}.form-control::placeholder{color:#9B93A5}.form-control:hover{border-color:#BEB0CE}.form-control:focus{outline:none;border-color:var(--purple);background:#fff;box-shadow:0 0 0 4px rgba(91,30,188,.1)}select.form-control{cursor:pointer}textarea.form-control{min-height:190px;resize:vertical}.field-help{display:block;margin-top:7px;color:#81778D;font-size:11px;line-height:1.45}.character-guide{display:flex;justify-content:space-between;gap:12px}
  .lead-researcher{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #CFE1FF;border-radius:13px;background:#F1F6FF}.lead-avatar{display:grid;width:38px;height:38px;place-items:center;border-radius:12px;background:#2563EB;color:#fff;font-size:12px;font-weight:800}.lead-meta{min-width:0}.lead-name{color:#14213B;font-size:13px;font-weight:750}.lead-role{margin-top:2px;color:#64748B;font-size:11px}.team-divider{height:1px;margin:22px 0;background:#EEE8F4}.crc-search{display:flex;gap:9px}.crc-search .form-control{flex:1;min-width:0}.selection-label{display:flex;justify-content:space-between;margin:17px 0 8px;color:#665A74;font-size:11px}.selection-count{font-weight:750;color:var(--purple)}.crc-pills{display:flex;flex-wrap:wrap;gap:8px}.crc-pill{display:inline-flex;align-items:center;gap:7px;padding:7px 7px 7px 11px;border:1px solid #D7C4F4;border-radius:999px;background:#F4EEFC;color:#4B168F;font-size:12px;font-weight:650}.crc-pill small{color:#78658E}.crc-pill-remove{display:inline-grid;width:23px;height:23px;padding:0;place-items:center;border:0;border-radius:50%;background:#E5D8F8;color:#4B168F;font-size:15px;font-weight:800;cursor:pointer;transition:background .2s ease,color .2s ease}.crc-pill-remove:hover{background:#B42318;color:#fff}.crc-result-list{display:grid;gap:8px;margin-top:8px}.crc-result{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 13px;border:1px solid #E0D8E9;border-radius:12px;background:#FBFAFD}.crc-result-info{flex:1;min-width:0}.crc-result-name{color:#251A31;font-size:13px;font-weight:750}.crc-result-meta{margin-top:2px;color:#776C83;font-size:11px;word-break:break-word}.crc-empty{margin-top:9px;padding:19px;border:1px dashed #D8CBE7;border-radius:12px;background:#FAF7FD;color:#74677F;font-size:12px;text-align:center}.team-hint{display:flex;gap:8px;margin-top:14px;padding:11px 12px;border-radius:11px;background:#FFF8E9;color:#81510D;font-size:11px;line-height:1.5}
  .submission-page .rms-file-uploader{margin:0}.submission-page .rms-uploader-label{display:none}.submission-page .rms-uploader-description{display:none}.submission-page .rms-uploader-dropzone{padding:42px 24px;border-color:#D3BDEB;border-radius:15px;background:#F8F3FD}.submission-page .rms-uploader-dropzone:hover{background:#F1E9FB}.upload-notes{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:13px}.upload-note{display:flex;gap:8px;padding:11px 12px;border-radius:11px;background:#EDF8F4;color:#17654F;font-size:11px;line-height:1.45}.upload-note.warning{background:#FFF4E8;color:#945016}
  .submission-guide{position:sticky;top:24px;overflow:hidden;border:1px solid #D8CBE8;border-radius:20px;background:#FBF9FD;box-shadow:0 14px 34px rgba(45,24,76,.08)}.guide-head{padding:24px;background:#31105E;color:#fff}.guide-kicker{margin:0 0 7px;color:#DCCBFF;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.guide-head h2{margin:0;font-size:19px;letter-spacing:-.025em}.guide-head p{margin:8px 0 0;color:#DCD0EA;font-size:11px;line-height:1.55}.guide-list{margin:0;padding:8px 20px 2px;list-style:none}.guide-item{display:grid;grid-template-columns:29px 1fr;gap:11px;padding:15px 0;border-bottom:1px solid #E9E2F0}.guide-item:last-child{border-bottom:0}.guide-icon{display:grid;width:29px;height:29px;place-items:center;border-radius:9px;font-size:12px;font-weight:800}.guide-item:nth-child(1) .guide-icon{background:#EEE5FB;color:#5B1EBC}.guide-item:nth-child(2) .guide-icon{background:#E5EEFF;color:#2563EB}.guide-item:nth-child(3) .guide-icon{background:#E2F5ED;color:#07855F}.guide-item:nth-child(4) .guide-icon{background:#FFF0E3;color:#C55408}.guide-title{color:#2A2035;font-size:12px;font-weight:750}.guide-copy{margin-top:3px;color:#81768D;font-size:10px;line-height:1.45}.guide-note{margin:8px 20px 20px;padding:13px;border-radius:12px;background:#F0E9F8;color:#59476D;font-size:10px;line-height:1.55}
  .form-actions{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:19px 22px;border:1px solid #D8CBE8;border-radius:17px;background:rgba(255,255,255,.96);box-shadow:0 12px 30px rgba(45,24,76,.08)}.action-group{display:flex;gap:9px}.btn{display:inline-flex;min-height:43px;align-items:center;justify-content:center;gap:7px;padding:10px 17px;border:1px solid transparent;border-radius:11px;font:700 12px/1 'Inter',sans-serif;text-decoration:none;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,background .2s ease,color .2s ease,border-color .2s ease}.btn-primary{border-color:var(--purple);background:var(--purple);color:#fff}.btn-primary:hover{transform:translateY(-1px);background:#491598;box-shadow:0 9px 18px rgba(91,30,188,.22)}.btn-secondary{border-color:#D8D0E3;background:#fff;color:#281B35}.btn-secondary:hover{border-color:#B9AACA;background:#F5F0FA;color:#4B168F}.btn-quiet{border-color:transparent;background:transparent;color:#6A6074}.btn-quiet:hover{background:#F1ECF6;color:#291050}.btn:disabled{cursor:not-allowed;opacity:.48;transform:none;box-shadow:none}.action-note{color:#81768D;font-size:10px;line-height:1.45}
  @keyframes submissionEnter{from{opacity:0;transform:translateY(13px)}to{opacity:1;transform:translateY(0)}}
  @media(prefers-reduced-motion:no-preference){.submission-hero,.alert,.form-section,.submission-guide,.form-actions,.success-panel{animation:submissionEnter .48s cubic-bezier(.16,1,.3,1) both}.form-section:nth-child(2){animation-delay:.05s}.form-section:nth-child(3){animation-delay:.1s}.form-section:nth-child(4){animation-delay:.15s}.submission-guide{animation-delay:.08s}.form-actions{animation-delay:.18s}}
  @media(max-width:980px){.submission-layout{grid-template-columns:1fr}.submission-guide{position:static}.guide-list{display:grid;grid-template-columns:1fr 1fr;gap:0 22px}.guide-note{margin-top:4px}}
  @media(max-width:760px){.submission-hero{grid-template-columns:1fr;padding:30px 24px}.hero-checklist{padding:23px 0 0;border-top:1px solid rgba(255,255,255,.24);border-left:0}.form-grid{grid-template-columns:1fr}.field-full{grid-column:auto}.section-header,.section-body{padding-right:21px;padding-left:21px}.upload-notes{grid-template-columns:1fr}.form-actions{align-items:stretch;flex-direction:column}.action-group{display:grid;grid-template-columns:1fr 1fr}.action-group .btn-primary{grid-column:1/-1;grid-row:1}.action-note{text-align:center}}
  @media(max-width:520px){.submission-hero{padding:27px 21px}.hero-checklist-list,.guide-list{grid-template-columns:1fr}.crc-search{flex-direction:column}.crc-result{align-items:flex-start}.action-group{grid-template-columns:1fr}.action-group .btn-primary{grid-column:auto;grid-row:auto}.btn{width:100%}.success-panel{padding:34px 22px}}
  @media(prefers-reduced-motion:reduce){.submission-page *{scroll-behavior:auto!important;animation:none!important;transition:none!important}}

  /* Map the refreshed system onto the existing server-rendered form. */
  .submission-page>div:first-of-type{margin:0 0 18px!important}.submission-page>div:first-of-type a{display:inline-flex;align-items:center;gap:8px;color:#4B168F!important;font-size:13px!important;font-weight:750;text-decoration:none!important}
  .submission-page form{display:grid;gap:18px}.submission-page form>.card{overflow:hidden;margin:0!important;padding:0;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.96);box-shadow:0 12px 32px rgba(45,24,76,.07)}
  .submission-page form>.card:nth-of-type(1){border-top:4px solid var(--purple)}.submission-page form>.card:nth-of-type(2){border-top:4px solid #2563EB}.submission-page form>.card:nth-of-type(3){border-top:4px solid #07855F}.submission-page form>.card:nth-of-type(4){border-top:4px solid #C55408}
  .submission-page .card-header{margin:0;padding:22px 27px 17px;border-bottom:1px solid #EEE8F4}.submission-page .card-title{display:flex;align-items:center;gap:12px;margin:0;color:#201529;font-size:19px;font-weight:750;letter-spacing:-.025em}
  .submission-page form>.card .card-title::before{display:grid;width:34px;height:34px;place-items:center;border-radius:11px;background:#EEE6FB;color:#5B1EBC;font-size:11px;font-weight:800}.submission-page form>.card:nth-of-type(1) .card-title::before{content:'01'}.submission-page form>.card:nth-of-type(2) .card-title::before{content:'02';background:#E8F0FF;color:#2563EB}.submission-page form>.card:nth-of-type(3) .card-title::before{content:'03';background:#E3F7EF;color:#07855F}.submission-page form>.card:nth-of-type(4) .card-title::before{content:'04';background:#FFF0E4;color:#C55408}
  .submission-page .card-body{padding:25px 27px 27px!important}.submission-page form>.card:first-of-type .card-body{display:grid!important;grid-template-columns:1fr 1fr;gap:20px!important}.submission-page form>.card:first-of-type .card-body>div:first-child,.submission-page form>.card:first-of-type .card-body>div:last-child{grid-column:1/-1}
  .submission-page .card-body label:not(.research-area-option){display:block!important;margin:0 0 8px!important;color:#241A31!important;font-size:12px;font-weight:750!important}.submission-page .card-body small{font-size:11px!important;line-height:1.45}.submission-page .card-body>div>small{color:#81778D!important}
  .submission-page form>.card:nth-of-type(2) .card-body>div:first-child>div{display:flex;align-items:center;min-height:50px;padding:13px 15px!important;border:1px solid #CFE1FF!important;border-radius:12px!important;background:#F1F6FF!important;color:#14213B!important;font-size:13px;font-weight:700}.submission-page form>.card:nth-of-type(2) .card-body>div+div{padding-top:20px;border-top:1px solid #EEE8F4}
  .submission-page .alert-success{display:block;padding:28px;border:1px solid #A7DDC8;border-radius:18px;background:#EDFBF5;color:#087A59;text-align:center}.submission-page .alert-error{display:block;padding:18px 20px;border:1px solid #F1B5B5;border-radius:16px;background:#FFF1F1;color:#A82323}.submission-page .alert-error strong{margin-bottom:4px}.submission-page .alert-success a{display:inline-block;margin-top:8px;color:#087A59!important;font-weight:750}
  .submission-page form>div:last-child:not(.card){margin:4px 0 0!important;padding:18px 20px;border:1px solid #D8CBE8;border-radius:17px;background:rgba(255,255,255,.96);box-shadow:0 12px 30px rgba(45,24,76,.08)}
  @media(max-width:760px){.submission-page form>.card:first-of-type .card-body{grid-template-columns:1fr}.submission-page form>.card:first-of-type .card-body>div:first-child,.submission-page form>.card:first-of-type .card-body>div:last-child{grid-column:auto}.submission-page .card-header,.submission-page .card-body{padding-right:20px!important;padding-left:20px!important}.submission-page form>div:last-child:not(.card){display:grid!important;grid-template-columns:1fr 1fr;gap:9px!important}.submission-page form>div:last-child:not(.card) .btn-primary,.submission-page form>div:last-child:not(.card) a{grid-column:1/-1}}
.faculty-role .submission-page{--purple:#1D4ED8;--purple-dark:#102654;--ink:#111827;--muted:#5D6B81;--line:#DCE4EF}
.faculty-role .submission-hero{background:radial-gradient(circle at 93% 8%,rgba(191,219,254,.24),transparent 28%),radial-gradient(circle at 10% 120%,rgba(29,78,216,.28),transparent 32%),linear-gradient(135deg,#102654 0%,#1D4ED8 58%,#344FC4 100%);box-shadow:0 22px 52px rgba(29,78,216,.2)}
.faculty-role .submission-hero h1{color:#fff}
.faculty-role .hero-eyebrow,.faculty-role .hero-checklist-label{color:#DBEAFE}
.faculty-role .submission-page>div:first-of-type a{color:#1D4ED8!important}
.faculty-role .submission-back:hover,.faculty-role .submission-page>div:first-of-type a:hover{color:#1E40AF!important}
.faculty-role .submission-back:focus-visible,.faculty-role .btn:focus-visible,.faculty-role .crc-pill-remove:focus-visible{outline-color:rgba(29,78,216,.42)}
.faculty-role .form-section.accent-purple{border-top-color:#1D4ED8}
.faculty-role .section-number,.faculty-role .submission-page form>.card:nth-of-type(1) .card-title::before{background:#E8F0FF;color:#1D4ED8}
.faculty-role .form-control:focus{border-color:#1D4ED8;box-shadow:0 0 0 4px rgba(29,78,216,.13)}
.faculty-role .selection-count{color:#1D4ED8}
.faculty-role .crc-pill{border-color:#BFDBFE;background:#EFF6FF;color:#1E40AF}
.faculty-role .crc-pill-remove{background:#DBEAFE;color:#1E40AF}
.faculty-role .submission-page form>div:last-child:not(.card){border-color:#D5E1F2;box-shadow:0 12px 30px rgba(29,78,216,.08)}
.research-area-picker{border:1px solid #D8D0E3;border-radius:11px;background:#FCFBFD}.research-area-picker summary{padding:13px 14px;cursor:pointer;font-size:13px;font-weight:650}.research-area-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;padding:12px;border-top:1px solid #E6E0EB;max-height:300px;overflow:auto}.research-area-option{display:flex;align-items:flex-start;gap:9px;padding:9px;border:1px solid #EAE5F0;border-radius:8px;background:#fff;font-size:12px}.research-area-option[hidden]{display:none}.research-area-option input{margin-top:2px;accent-color:#5B1EBC}.research-area-message{margin:8px 0 0;color:#9A3412;font-size:13px}.research-area-message[hidden]{display:none}
</style>

<main class="submission-page">
  <section class="submission-hero" aria-labelledby="submission-title">
    <div class="hero-copy">
      <p class="hero-eyebrow">Research proposal workspace</p>
      <h1 id="submission-title">Shape your idea into a clear proposal.</h1>
      <p class="hero-description">Enter the essential project details, prepare your proposal document, and submit it for authorized review.</p>
    </div>
    <div class="hero-checklist" aria-label="Proposal sections">
      <p class="hero-checklist-label">Three parts to complete</p>
      <ol class="hero-checklist-list">
        <li><span>01</span> Project details</li>
        <li><span>02</span> Research team</li>
        <li><span>03</span> Proposal document</li>
      </ol>
    </div>
  </section>

<!-- BREADCRUMB -->
<div style="margin-bottom: 20px;">
  <a href="<?php echo SITE_URL; ?>pages/researcher/my-research.php" style="color: #5B1EBC; text-decoration: none; font-size: 14px;">← Back to My Research</a>
</div>

<?php if ($success): ?>
  <!-- SUCCESS ALERT -->
  <div class="alert alert-success" style="margin-bottom: 20px;">
    <strong>✅ Success!</strong> <?php echo htmlspecialchars($success_message); ?>
    <br><a href="<?php echo SITE_URL; ?>pages/researcher/my-research.php" style="color: inherit; text-decoration: underline;">View your research project →</a>
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
<?php if (!$success && !$student_program_eligible && ($user['role'] ?? '') === 'student'): ?>
  <div class="alert alert-error" role="alert" style="margin-bottom: 20px;">
    <strong>Student researcher eligibility</strong>
    Only active students whose program is BSIT or BSCS can create research submissions. Update your program with an administrator if it is missing or incorrect.
  </div>
<?php elseif (!$success): ?>
<form method="POST" enctype="multipart/form-data" action="<?php echo SITE_URL; ?>pages/researcher/submit-research.php" class="form-guard">
  <?php echo csrfField(); ?>
  <input type="hidden" name="submission_nonce" value="<?php echo htmlspecialchars($submission_nonce, ENT_QUOTES, 'UTF-8'); ?>">
  <?php foreach ($crc_selected_ids as $crc_sid): ?>
    <input type="hidden" name="co_researchers[]" value="<?php echo (int) $crc_sid; ?>">
  <?php endforeach; ?>
  <!-- SECTION 1: BASIC INFORMATION -->
  <div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
      <div class="card-title">Basic Information</div>
    </div>
    <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
      <div class="field-full">
        <span class="form-label">Research Title Options <span class="required">*</span></span>
        <p class="form-hint">Enter three distinct options. The authorized reviewer will approve one, or return the proposal for revision.</p>
        <div class="form-grid">
          <?php foreach ($title_options as $index => $option): ?>
            <div>
              <label class="form-label" for="title-option-<?php echo $index + 1; ?>">Title option <?php echo $index + 1; ?></label>
              <input
                type="text"
                id="title-option-<?php echo $index + 1; ?>"
                name="title_options[]"
                class="form-control"
                maxlength="255"
                value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"
                required
              >
              <small class="form-hint">Maximum 255 characters</small>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (($user['role'] ?? '') === 'student'): ?>
      <div class="field-full">
        <span class="form-label">Research Category and Area for Each Title <span class="required">*</span></span>
        <p class="form-hint">Your course determines the available categories. Choose one category and one related research area for each title option.</p>
        <?php for ($title_index = 0; $title_index < 3; $title_index++): ?>
          <div class="title-classification" data-title-classification style="border:1px solid #E5E7EB;border-radius:10px;padding:14px;margin:10px 0;background:#FCFBFD">
            <strong>Title option <?php echo $title_index + 1; ?></strong>
            <label class="form-label" for="title-category-<?php echo $title_index + 1; ?>">Research category</label>
            <select id="title-category-<?php echo $title_index + 1; ?>" name="title_classifications[<?php echo $title_index; ?>][category_id]" class="form-control title-category" required>
              <option value="">-- Select Category --</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int) $cat['category_id']; ?>" <?php echo (int) ($title_classifications[$title_index]['category_id'] ?? 0) === (int) $cat['category_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="form-label" for="title-area-<?php echo $title_index + 1; ?>">Research area</label>
            <select id="title-area-<?php echo $title_index + 1; ?>" name="title_classifications[<?php echo $title_index; ?>][area_id]" class="form-control title-area" required>
              <option value="">-- Select a category first --</option>
              <?php foreach ($research_areas as $area): ?>
                <option value="<?php echo (int) $area['area_id']; ?>" data-category-ids="<?php echo htmlspecialchars(implode(',', $area['category_ids']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo (int) ($title_classifications[$title_index]['area_id'] ?? 0) === (int) $area['area_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($area['selection_label'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endfor; ?>
      </div>
      <?php else: ?>
      <!-- Faculty retains the existing shared category and areas catalog -->
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
      <?php endif; ?>

      <!-- Academic Year -->
      <div>
        <label for="academic_year" style="display: block; margin-bottom: 6px; font-weight: 500; color: #111827;">
          Academic Year <span style="color: #ef4444;">*</span>
        </label>
        <select
          id="academic_year"
          name="academic_year"
          class="form-control"
          aria-describedby="academic-year-hint"
          required
        >
          <option value="">-- Select Academic Year --</option>
          <?php foreach (array_keys($academic_years) as $ay_label): ?>
            <option value="<?php echo htmlspecialchars($ay_label, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $academic_year === $ay_label ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($ay_label, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p id="academic-year-hint" class="form-hint">The current academic year is selected automatically and advances each January.</p>
      </div>

      <?php if (($user['role'] ?? '') !== 'student'): ?><div class="field-full">
        <span class="form-label" id="research-area-label">Research Areas <span class="required">*</span></span>
        <p class="form-hint">Choose one to five BSIT/BSCS areas mapped to the selected category. Selected areas stay visible when you change category; uncheck any mismatches explicitly.</p>
        <details class="research-area-picker" id="research-area-picker" aria-labelledby="research-area-label">
          <summary id="research-area-list">Choose research areas <span id="research-area-count">(<?php echo count($research_area_ids); ?> selected)</span></summary>
          <div class="research-area-options" role="group" aria-label="Research areas">
            <?php foreach ($research_areas as $area): ?>
              <?php
                $selected_area_ids = array_map('intval', $research_area_ids);
                $area_category_ids = implode(',', $area['category_ids']);
              ?>
              <label class="research-area-option" data-eligible="<?php echo $area['proposal_eligible'] ? '1' : '0'; ?>" data-category-ids="<?php echo htmlspecialchars($area_category_ids, ENT_QUOTES, 'UTF-8'); ?>">
                <input
                  type="checkbox"
                  name="research_area_ids[]"
                  value="<?php echo (int) $area['area_id']; ?>"
                  <?php echo in_array((int) $area['area_id'], $selected_area_ids, true) ? 'checked' : ''; ?>
                >
                <span><?php echo htmlspecialchars($area['selection_label'], ENT_QUOTES, 'UTF-8'); ?></span>
              </label>
            <?php endforeach; ?>
            <?php if (!$research_areas): ?>
              <p>No active research areas are available. Ask an administrator to configure the research-area catalog.</p>
            <?php endif; ?>
          </div>
        </details>
        <p id="research-area-message" class="research-area-message" role="status" aria-live="polite" hidden></p>
      </div>
      <?php endif; ?>
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

        <div class="crc-search" style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input
            type="text"
            id="crc_search_input"
            name="crc_search"
            class="form-control"
            placeholder="Search by name, student ID, or email…"
            value="<?php echo crc_se($crc_search_query); ?>"
            style="flex: 1;"
            maxlength="100"
          />
          <button type="submit" name="crc_do" value="search" formnovalidate class="btn btn-secondary">🔍 Search</button>
        </div>

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
                  <button
                    type="submit"
                    name="crc_remove"
                    value="<?php echo (int) $crc_row['user_id']; ?>"
                    formnovalidate
                    class="crc-pill-remove"
                    title="Remove"
                    aria-label="Remove co-researcher"
                  >×</button>
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
                <?php $crc_disabled = $crc_remaining <= 0; ?>
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
                    <button type="submit" name="crc_add" value="<?php echo (int) $crc_row['user_id']; ?>" formnovalidate class="btn btn-primary" style="font-size: 13px; padding: 6px 14px;">+ Add</button>
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

  <!-- SECTION 3: PROPOSAL DOCUMENT -->
  <div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
      <div class="card-title">Proposal Document</div>
    </div>
    <div class="card-body" style="display: flex; flex-direction: column; gap: 16px;">
      <div class="proposal-guidance" role="note" aria-labelledby="proposal-guidance-title">
        <div>
          <h3 id="proposal-guidance-title">What to include in your title proposal</h3>
          <p class="proposal-guidance-intro">For this first review, keep your proposal focused. Include these two parts:</p>
        </div>
        <ol class="proposal-requirements">
          <li>
            <span class="proposal-requirement-number" aria-hidden="true">1</span>
            <span class="proposal-requirement-copy">
              <strong>Your research title</strong>
              <span>A clear working title for the study you want to pursue.</span>
            </span>
          </li>
          <li>
            <span class="proposal-requirement-number" aria-hidden="true">2</span>
            <span class="proposal-requirement-copy">
              <strong>A brief description of your idea</strong>
              <span>Explain the topic or problem you want to address and what you hope to learn or achieve.</span>
            </span>
          </li>
        </ol>
        <div class="proposal-template-action">
          <p class="proposal-template-hint">Use the editable DOCX starter, then save or export your completed proposal as a PDF to upload here (PDF only, max 10 MB).</p>
          <a class="btn btn-primary proposal-template-button" href="<?php echo SITE_URL; ?>public/assets/research-proposal-template.docx" download>
            Download DOCX template
          </a>
        </div>
      </div>
      <?php
      echo renderFileUploader([
        'inputName' => 'proposal_file',
        'accept' => '.pdf',
        'maxSize' => 10000,  // 10 MB
        'folderTarget' => 'proposals',
        'label' => 'Upload Proposal',
        'description' => 'Upload your research title and concise research description',
        'allowedFormatsText' => 'PDF only • Max 10 MB',
        'required' => false
      ]);
      ?>
      <small style="color: #64748B; margin-top: -12px; display: block;">
        💡 Optional when saving a draft; required when submitting for review.
      </small>
      <small style="color: #EA580C; margin-top: -8px; display: block;">
        Please re-select your proposal file after using the co-researcher search, add, or remove controls.
      </small>
    </div>
  </div>

  <!-- SUBMIT BUTTONS -->
  <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
    <a href="<?php echo SITE_URL; ?>pages/researcher/my-research.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" name="status" value="draft" class="btn btn-secondary">Save as Draft</button>
    <button type="submit" name="status" value="submitted" class="btn btn-primary">Submit for Review</button>
  </div>
</form>
<?php endif; ?>

</main>

<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>
<script src="../../js/app-forms.js" defer></script>
<script src="<?php echo SITE_URL; ?>js/research-area-picker.js?v=<?php echo filemtime(__DIR__ . '/../../js/research-area-picker.js'); ?>" defer></script>

<?php
if (($user['role'] ?? '') === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
