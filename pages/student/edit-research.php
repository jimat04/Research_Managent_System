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

/**
 * Team-management helpers
 *
 * Cap the team at 5 total members (1 lead + up to 4 co-researchers).
 * Reuse the same validation rules as submit-research so the picker behaves
 * identically in both places.
 */
define('CRC_MAX_TOTAL_MEMBERS', 5);
if (!defined('CRC_MAX_CO_RESEARCHERS')) define('CRC_MAX_CO_RESEARCHERS', CRC_MAX_TOTAL_MEMBERS - 1);

function crc_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function crc_normalize_ids($raw) {
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $v) {
        $id = (int) $v;
        if ($id > 0 && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out;
}

function crc_validate_candidates($conn, $candidate_ids, $requester_id) {
    $candidates = crc_normalize_ids($candidate_ids);
    if (empty($candidates)) return [];
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
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

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
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

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
    $action = isset($_POST['action']) ? $_POST['action'] : 'save';

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

            // If resubmitting after revision, move status back to 'submitted'
            $new_status = null;
            if ($action === 'resubmit' && in_array($project['status'], ['for_revision', 'revision_required', 'draft'], true)) {
                $new_status = 'submitted';
                $status_upd = $conn->prepare("UPDATE research_projects SET status = 'submitted' WHERE project_id = ?");
                $status_upd->bind_param('i', $project_id);
                $status_upd->execute();
                $status_upd->close();
                if (function_exists('createNotification')) {
                    $notification_title = 'Project resubmitted';
                    $student_message = 'Your project "' . $title . '" has been resubmitted for review.';
                    $student_link = 'pages/student/research-detail.php?id=' . (int) $project_id;
                    $actor_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $review_message = 'Project "' . $title . '" has been resubmitted for review by ' . $actor_name . '.';

                    createNotification(
                        (int) $project['created_by'],
                        $notification_title,
                        $student_message,
                        'success',
                        $student_link
                    );

                    // Keep co-researchers informed with the same confirmation
                    // shown to the project owner.
                    $member_notify_stmt = $conn->prepare(
                        'SELECT user_id FROM project_members WHERE project_id = ? AND user_id <> ?'
                    );
                    if (!$member_notify_stmt) {
                        throw new Exception('Unable to prepare co-researcher notifications.');
                    }
                    $member_notify_stmt->bind_param('ii', $project_id, $user_id);
                    if (!$member_notify_stmt->execute()) {
                        throw new Exception('Unable to load co-researchers for notification.');
                    }
                    $member_notify_result = $member_notify_stmt->get_result();
                    while ($member = $member_notify_result->fetch_assoc()) {
                        createNotification(
                            (int) $member['user_id'],
                            $notification_title,
                            $student_message,
                            'success',
                            $student_link
                        );
                    }
                    $member_notify_stmt->close();

                    $adviser_notify_stmt = $conn->prepare(
                        'SELECT adviser_id FROM project_advisers WHERE project_id = ? AND adviser_id IS NOT NULL'
                    );
                    if (!$adviser_notify_stmt) {
                        throw new Exception('Unable to prepare adviser notifications.');
                    }
                    $adviser_notify_stmt->bind_param('i', $project_id);
                    if (!$adviser_notify_stmt->execute()) {
                        throw new Exception('Unable to load advisers for notification.');
                    }
                    $adviser_notify_result = $adviser_notify_stmt->get_result();
                    while ($adviser = $adviser_notify_result->fetch_assoc()) {
                        createNotification(
                            (int) $adviser['adviser_id'],
                            $notification_title,
                            $review_message,
                            'info',
                            'pages/faculty/faculty-review-detail.php?id=' . (int) $project_id
                        );
                    }
                    $adviser_notify_stmt->close();

                    $staff_notify_stmt = $conn->prepare(
                        "SELECT user_id FROM users WHERE role = 'research_staff' AND status = 'active'"
                    );
                    if (!$staff_notify_stmt) {
                        throw new Exception('Unable to prepare research staff notifications.');
                    }
                    if (!$staff_notify_stmt->execute()) {
                        throw new Exception('Unable to load research staff for notification.');
                    }
                    $staff_notify_result = $staff_notify_stmt->get_result();
                    while ($staff = $staff_notify_result->fetch_assoc()) {
                        createNotification(
                            (int) $staff['user_id'],
                            $notification_title,
                            $review_message,
                            'info',
                            'pages/staff/staff-submissions.php'
                        );
                    }
                    $staff_notify_stmt->close();
                }
                logActivity('Research project resubmitted for review after revision', 'research');
            } else {
                logActivity('Updated research project', 'research');
            }

            $conn->commit();
            $_SESSION['module_success'] = $new_status === 'submitted'
                ? 'Project resubmitted for review successfully.'
                : 'Project updated successfully.';
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

// =========================================================================
// Team management
// =========================================================================
//
// Two routes:
//   (a) POST action=add_member — INSERT a new co-researcher (member role).
//       Triggers a createNotification() to the added student.
//   (b) POST action=remove_member — DELETE a non-lead member that is not
//       the project creator (defence in depth; the project lead is also the
//       creator, so removing the creator == removing the lead — both are
//       refused).
//
// Both routes re-verify project ownership server-side. They run inside the
// same CSRF check as the rest of the page and surface a flash message via
// $_SESSION['module_success'] / $_SESSION['module_error'].

$crc_team_success = isset($_SESSION['module_success']) ? (string) $_SESSION['module_success'] : '';
$crc_team_error   = isset($_SESSION['module_error'])   ? (string) $_SESSION['module_error']   : '';
unset($_SESSION['module_success'], $_SESSION['module_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isCsrfTokenValid($_POST['csrf_token'] ?? null)
    && in_array($_POST['action'] ?? '', ['add_member', 'remove_member'], true)
) {
    $crc_project_id = (int) ($_POST['project_id'] ?? 0);
    // Re-verify ownership
    $crc_owner_check = $conn->prepare("SELECT created_by, title FROM research_projects WHERE project_id = ?" . $rp_deleted_filter . " LIMIT 1");
    if (!$crc_owner_check) {
        $_SESSION['module_error'] = 'Database error: unable to verify project.';
        header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
        exit;
    }
    $crc_owner_check->bind_param('i', $crc_project_id);
    $crc_owner_check->execute();
    $crc_owner = $crc_owner_check->get_result()->fetch_assoc();
    $crc_owner_check->close();
    if (!$crc_owner || (int) $crc_owner['created_by'] !== $user_id) {
        $_SESSION['module_error'] = 'You are not allowed to manage this team.';
        header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
        exit;
    }

    if ($_POST['action'] === 'add_member') {
        $crc_new_id = (int) ($_POST['member_id'] ?? 0);
        if ($crc_new_id <= 0 || $crc_new_id === $user_id) {
            $_SESSION['module_error'] = 'Invalid member selected.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }

        // Cap: total team size <= 5
        $crc_count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM project_members WHERE project_id = ?");
        $crc_count_stmt->bind_param('i', $crc_project_id);
        $crc_count_stmt->execute();
        $crc_total = (int) ($crc_count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $crc_count_stmt->close();
        if ($crc_total >= CRC_MAX_TOTAL_MEMBERS) {
            $_SESSION['module_error'] = 'Team is already at the maximum of ' . CRC_MAX_TOTAL_MEMBERS . ' members.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }

        // Validate candidate is an active student
        $crc_valid = crc_validate_candidates($conn, [$crc_new_id], $user_id);
        if (empty($crc_valid)) {
            $_SESSION['module_error'] = 'That student is not eligible to join the team.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        $crc_valid_row = $crc_valid[0];

        // Already a member?
        $crc_dup_stmt = $conn->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $crc_dup_stmt->bind_param('ii', $crc_project_id, $crc_new_id);
        $crc_dup_stmt->execute();
        $crc_dup_exists = $crc_dup_stmt->get_result()->num_rows > 0;
        $crc_dup_stmt->close();
        if ($crc_dup_exists) {
            $_SESSION['module_error'] = 'That student is already on the team.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }

        $crc_ins = $conn->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')");
        if (!$crc_ins) {
            $_SESSION['module_error'] = 'Database error: ' . $conn->error;
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        $crc_ins->bind_param('ii', $crc_project_id, $crc_new_id);
        if (!$crc_ins->execute()) {
            $crc_ins->close();
            $_SESSION['module_error'] = 'Could not add team member.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        $crc_ins->close();

        logActivity('Added co-researcher to research project', 'research');

        $crc_full_name  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $crc_link       = SITE_URL . 'pages/student/research-detail.php?id=' . (int) $crc_project_id;
        createNotification(
            $crc_new_id,
            'Added as co-researcher',
            $crc_full_name . ' added you as a co-researcher on "' . (string) $crc_owner['title'] . '".',
            'info',
            $crc_link
        );

        $_SESSION['module_success'] = trim($crc_valid_row['first_name'] . ' ' . $crc_valid_row['last_name']) . ' has been added to the team.';
        header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
        exit;
    }

    if ($_POST['action'] === 'remove_member') {
        $crc_rm_id = (int) ($_POST['member_id'] ?? 0);
        if ($crc_rm_id <= 0) {
            $_SESSION['module_error'] = 'Invalid member selected.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        // Refuse to remove the lead row or the project creator
        if ($crc_rm_id === $user_id) {
            $_SESSION['module_error'] = 'You cannot remove yourself from the team. The lead researcher is fixed.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        $crc_row_stmt = $conn->prepare("SELECT id, role, user_id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $crc_row_stmt->bind_param('ii', $crc_project_id, $crc_rm_id);
        $crc_row_stmt->execute();
        $crc_row = $crc_row_stmt->get_result()->fetch_assoc();
        $crc_row_stmt->close();
        if (!$crc_row) {
            $_SESSION['module_error'] = 'That member is not on this team.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        if ($crc_row['role'] === 'lead') {
            $_SESSION['module_error'] = 'The lead researcher cannot be removed.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        if ((int) $crc_row['user_id'] === (int) $crc_owner['created_by']) {
            $_SESSION['module_error'] = 'The project creator cannot be removed.';
            header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
            exit;
        }
        $crc_del = $conn->prepare("DELETE FROM project_members WHERE id = ?");
        $crc_del->bind_param('i', $crc_row['id']);
        $crc_del->execute();
        $crc_del->close();

        logActivity('Removed co-researcher from research project', 'research');
        $_SESSION['module_success'] = 'Team member has been removed.';
        header('Location: ' . SITE_URL . 'pages/student/edit-research.php?id=' . (int) $project_id);
        exit;
    }
}

// GET-driven picker: ?add=N or ?remove=N for the search-result "+ Add" /
// "× Remove" links. We only mutate the session here; the real INSERT runs
// through the POST add_member action above.
$crc_search_query   = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$crc_picker_results = [];
$crc_picker_picks   = [];   // currently-selected co-researcher rows
$crc_picker_ids     = [];   // just the ids

// Load current team (for display in the Manage team card)
$crc_team_stmt = $conn->prepare("
    SELECT pm.user_id, pm.role, u.first_name, u.last_name, u.email, u.student_id
    FROM project_members pm
    JOIN users u ON u.user_id = pm.user_id
    WHERE pm.project_id = ?
    ORDER BY (pm.role = 'lead') DESC, u.last_name ASC, u.first_name ASC
");
$crc_team_members = [];
if ($crc_team_stmt) {
    $crc_team_stmt->bind_param('i', $project_id);
    $crc_team_stmt->execute();
    $crc_res = $crc_team_stmt->get_result();
    while ($crc_res && $r = $crc_res->fetch_assoc()) {
        $crc_team_members[] = $r;
    }
    $crc_team_stmt->close();
}
foreach ($crc_team_members as $crc_t) {
    if ($crc_t['role'] === 'member') {
        $crc_picker_ids[] = (int) $crc_t['user_id'];
    }
}
$crc_team_size = count($crc_team_members);
$crc_team_full = $crc_team_size >= CRC_MAX_TOTAL_MEMBERS;

// Search results
if ($crc_search_query !== '' && !$crc_team_full) {
    $crc_picker_results = crc_search_students($conn, $crc_search_query, $user_id, 8);
    if (!empty($crc_picker_ids)) {
        $crc_picker_results = array_values(array_filter($crc_picker_results, function ($r) use ($crc_picker_ids) {
            return !in_array((int) $r['user_id'], $crc_picker_ids, true);
        }));
    }
    // Also exclude the project creator (already on the team as lead)
    $crc_picker_results = array_values(array_filter($crc_picker_results, function ($r) use ($user_id) {
        return (int) $r['user_id'] !== $user_id;
    }));
}

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

  /* Manage team list */
  .crc-team-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .crc-team-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
  }
  .crc-team-info { flex: 1; min-width: 0; }
  .crc-team-name {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .crc-team-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    background: rgba(91, 30, 188, 0.12);
    color: #5B1EBC;
    border-radius: 9999px;
  }
  .crc-team-role {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .crc-team-role--lead   { background: #FEF3C7; color: #92400E; }
  .crc-team-role--member { background: #E0E7FF; color: #3730A3; }
  .crc-team-meta {
    font-size: 12px;
    color: #64748B;
    margin-top: 4px;
    word-break: break-word;
  }
  .crc-team-locked {
    font-size: 12px;
    color: #94A3B8;
    font-style: italic;
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

<?php if ($crc_team_error !== ''): ?>
  <div class="alert alert-error" style="margin-bottom: 16px;">
    ❌ <?php echo crc_se($crc_team_error); ?>
  </div>
<?php endif; ?>

<?php if ($crc_team_success !== ''): ?>
  <div class="alert alert-success" style="margin-bottom: 16px;">
    ✅ <?php echo crc_se($crc_team_success); ?>
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
      'projectId'          => $project_id,
      'type'               => 'proposal',
      'label'              => 'Upload New Proposal',
      'description'        => 'Drag & drop a new version or click to browse',
      'allowedFormatsText' => 'PDF, DOC, DOCX • Max 10 MB',
      'required'           => false,
    ]);
    ?>
    <small class="form-hint" style="margin-top: 4px;">Leave empty to keep the existing file.</small>
  </div>

  <!-- MANAGE TEAM -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Manage Team</div>
      <p class="card-sub">
        <?php echo (int) $crc_team_size; ?> of <?php echo CRC_MAX_TOTAL_MEMBERS; ?> members
        · You (<?php echo crc_se(trim($user['first_name'] . ' ' . $user['last_name'])); ?>) are the lead researcher
      </p>
    </div>

    <?php if (empty($crc_team_members)): ?>
      <p style="margin: 0; color: #64748B;">No team members yet.</p>
    <?php else: ?>
      <div class="crc-team-list">
        <?php foreach ($crc_team_members as $crc_t):
          $crc_is_lead  = ($crc_t['role'] === 'lead');
          $crc_is_self  = ((int) $crc_t['user_id'] === $user_id);
          $crc_full_nm  = trim($crc_t['first_name'] . ' ' . $crc_t['last_name']);
        ?>
          <div class="crc-team-row">
            <div class="crc-team-info">
              <div class="crc-team-name">
                🎒 <?php echo crc_se($crc_full_nm); ?>
                <?php if ($crc_is_self): ?>
                  <span class="crc-team-tag">you</span>
                <?php endif; ?>
                <span class="crc-team-role crc-team-role--<?php echo crc_se($crc_t['role']); ?>">
                  <?php echo crc_se(ucfirst((string) $crc_t['role'])); ?>
                </span>
              </div>
              <div class="crc-team-meta">
                <?php if (!empty($crc_t['student_id'])): ?>
                  <?php echo crc_se($crc_t['student_id']); ?>
                <?php endif; ?>
                <?php if (!empty($crc_t['email'])): ?>
                  <?php if (!empty($crc_t['student_id'])): ?> · <?php endif; ?>
                  <?php echo crc_se($crc_t['email']); ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$crc_is_lead && !$crc_is_self): ?>
              <button type="submit"
                      form="remove-member-form-<?php echo (int) $crc_t['user_id']; ?>"
                      class="btn btn-secondary"
                      style="font-size: 13px; padding: 6px 14px; color: #DC2626;">Remove</button>
            <?php else: ?>
              <span class="crc-team-locked" title="The lead researcher is fixed">fixed</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$crc_team_full): ?>
      <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #E5E7EB;">
        <label for="crc_search_input" class="form-label">Add Co-researcher</label>
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input
            type="text"
            id="crc_search_input"
            name="search"
            form="member-search-form"
            class="form-control"
            placeholder="Search by name, student ID, or email…"
            value="<?php echo crc_se($crc_search_query); ?>"
            style="flex: 1;"
            maxlength="100"
          />
          <button type="submit" form="member-search-form" class="btn btn-secondary">🔍 Search</button>
        </div>

        <?php if ($crc_search_query !== ''): ?>
          <?php if (empty($crc_picker_results)): ?>
            <div class="crc-empty">No matching students found.</div>
          <?php else: ?>
            <small style="display: block; color: #64748B; margin-bottom: 6px;">
              Results for "<?php echo crc_se($crc_search_query); ?>"
            </small>
            <div class="crc-result-list">
              <?php foreach ($crc_picker_results as $crc_row): ?>
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
                        <?php if (!empty($crc_row['student_id'])): ?> · <?php endif; ?>
                        <?php echo crc_se($crc_row['email']); ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <button type="submit"
                          form="add-member-form-<?php echo (int) $crc_row['user_id']; ?>"
                          class="btn btn-primary"
                          style="font-size: 13px; padding: 6px 14px;">+ Add</button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <small style="color: #64748B; display: block;">
            💡 Search by name, student ID, or email to add up to <?php echo CRC_MAX_CO_RESEARCHERS; ?> co-researchers.
          </small>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p style="margin: 16px 0 0 0; color: #64748B; font-size: 13px;">
        💡 Team is at the maximum of <?php echo CRC_MAX_TOTAL_MEMBERS; ?> members. Remove a member to add someone new.
      </p>
    <?php endif; ?>
  </div>

  <!-- SUBMIT BAR -->
  <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
    <a href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project_id; ?>" class="btn btn-secondary">Cancel</a>
    <button type="submit" name="action" value="save" class="btn btn-secondary">💾 Save Changes</button>
    <?php if (in_array($project['status'], ['for_revision', 'revision_required', 'draft'], true)): ?>
      <button type="submit" name="action" value="resubmit" class="btn btn-primary">📤 Resubmit for Review</button>
    <?php endif; ?>
  </div>
</form>

<!-- These action forms stay outside the multipart edit form. The controls in
     the team card target them with the HTML5 form attribute. -->
<?php foreach ($crc_team_members as $crc_t):
  $crc_is_lead = ($crc_t['role'] === 'lead');
  $crc_is_self = ((int) $crc_t['user_id'] === $user_id);
  if ($crc_is_lead || $crc_is_self) continue;
  $crc_full_nm = trim($crc_t['first_name'] . ' ' . $crc_t['last_name']);
?>
  <form id="remove-member-form-<?php echo (int) $crc_t['user_id']; ?>" method="POST"
        onsubmit="return confirm('Remove <?php echo crc_se(addslashes($crc_full_nm)); ?> from the team?');">
    <?php echo csrfField(); ?>
    <input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
    <input type="hidden" name="action" value="remove_member">
    <input type="hidden" name="member_id" value="<?php echo (int) $crc_t['user_id']; ?>">
  </form>
<?php endforeach; ?>

<?php if (!$crc_team_full): ?>
  <form id="member-search-form" method="GET" action="<?php echo SITE_URL; ?>pages/student/edit-research.php">
    <input type="hidden" name="id" value="<?php echo (int) $project_id; ?>">
  </form>

  <?php foreach ($crc_picker_results as $crc_row): ?>
    <form id="add-member-form-<?php echo (int) $crc_row['user_id']; ?>" method="POST">
      <?php echo csrfField(); ?>
      <input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
      <input type="hidden" name="action" value="add_member">
      <input type="hidden" name="member_id" value="<?php echo (int) $crc_row['user_id']; ?>">
    </form>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>

<?php renderStudentShellClose(); ?>
