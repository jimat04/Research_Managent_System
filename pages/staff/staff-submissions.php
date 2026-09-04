<?php
/**
 * Staff — Submissions Inbox
 *
 * Research Staff verifies newly-submitted student proposals:
 *   - Forward to CREC    → status: submitted → under_crec_review
 *   - Return for revision → status: submitted → for_revision (reason required)
 *
 * Both actions notify the student via createNotification() and write to activity_log.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole('research_staff');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];
$adviser_locked_statuses = ['draft', 'completed', 'archived', 'rejected'];

// ── helpers ────────────────────────────────────────────────────────────────
function se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Status badge helper (mirrors staff-dashboard.php)
function submissions_statusBadge(string $status): array {
    $map = [
        'draft'             => ['status-draft',    'Draft'],
        'submitted'         => ['status-review',   'Pending Verification'],
        'under_review'      => ['status-review',   'Under Review'],
        'under_crec_review' => ['status-review',   'CREC Review'],
        'under_erec_review' => ['status-review',   'EREC Review'],
        'for_revision'      => ['status-pending',  'For Revision'],
        'revision_required' => ['status-pending',  'Revision Required'],
        'approved'          => ['status-approved', 'Approved'],
        'ongoing'           => ['status-approved', 'Ongoing'],
        'completed'         => ['status-approved', 'Completed'],
        'archived'          => ['status-approved', 'Archived'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

// ── runtime schema detection ────────────────────────────────────────────
// research_projects.deleted_at is added by the migration but not in the base dump.
// Mirror the same pattern edit-research.php uses so this page is safe on both schemas.
$rp_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$rp_has_deleted_at = false;
if ($rp_deleted_column_stmt) {
    $rp_deleted_column_stmt->execute();
    $rp_has_deleted_at = $rp_deleted_column_stmt->get_result()->num_rows > 0;
    $rp_deleted_column_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND deleted_at IS NULL' : '';

// project_advisers has a minimal base schema, while some installations add
// optional role/assigned_at columns. Detect them before building INSERTs.
$project_advisers_exists = false;
$pa_has_role = false;
$pa_has_assigned_at = false;
$pa_table_check = $conn->query("SHOW TABLES LIKE 'project_advisers'");
if ($pa_table_check) {
    $project_advisers_exists = $pa_table_check->num_rows > 0;
    $pa_table_check->close();
}
if ($project_advisers_exists) {
    $pa_role_check = $conn->query("SHOW COLUMNS FROM project_advisers LIKE 'role'");
    $pa_has_role = $pa_role_check && $pa_role_check->num_rows > 0;
    if ($pa_role_check) $pa_role_check->close();

    $pa_assigned_check = $conn->query("SHOW COLUMNS FROM project_advisers LIKE 'assigned_at'");
    $pa_has_assigned_at = $pa_assigned_check && $pa_assigned_check->num_rows > 0;
    if ($pa_assigned_check) $pa_assigned_check->close();
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        // Mint a fresh token so the user can retry on the next request without a hard refresh
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['module_error'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $action     = (string) $_POST['action'];
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;

        if ($project_id <= 0) {
            $_SESSION['module_error'] = 'Invalid submission reference (received project_id='
                . htmlspecialchars((string)($_POST['project_id'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8')
                . '). Please refresh the page (Ctrl+Shift+R) and try again.';
        } else {
            // Look up project + student to drive the action safely
            $info_stmt = $conn->prepare("
                SELECT rp.project_id, rp.title, rp.status, rp.created_by
                FROM research_projects rp
                WHERE rp.project_id = ?"
                . $rp_deleted_filter . "
                LIMIT 1
            ");
            $info_stmt->bind_param('i', $project_id);
            $info_stmt->execute();
            $project = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            if (!$project) {
                $_SESSION['module_error'] = 'Submission not found.';
            } else {
                $student_id = (int) ($project['created_by'] ?? 0);
                $title      = (string) ($project['title'] ?? 'your research');
                $short      = mb_substr($title, 0, 60) . (mb_strlen($title) > 60 ? '…' : '');

                if ($action === 'assign_adviser') {
                    $adviser_id = isset($_POST['adviser_id']) ? (int) $_POST['adviser_id'] : 0;
                    if (!$project_advisers_exists) {
                        $_SESSION['module_error'] = 'Adviser assignments are unavailable because the project_advisers table is missing.';
                    } elseif (in_array((string) ($project['status'] ?? ''), $adviser_locked_statuses, true)) {
                        $_SESSION['module_error'] = 'Advisers can only be assigned to active projects.';
                    } elseif ($adviser_id <= 0) {
                        $_SESSION['module_error'] = 'Select an active faculty member to assign.';
                    } else {
                        $faculty_stmt = $conn->prepare(
                            "SELECT user_id, first_name, last_name
                               FROM users
                              WHERE user_id = ? AND role = 'faculty' AND status = 'active'
                              LIMIT 1"
                        );
                        $faculty_stmt->bind_param('i', $adviser_id);
                        $faculty_stmt->execute();
                        $faculty = $faculty_stmt->get_result()->fetch_assoc();
                        $faculty_stmt->close();

                        if (!$faculty) {
                            $_SESSION['module_error'] = 'The selected faculty member is unavailable.';
                        } else {
                            $duplicate_stmt = $conn->prepare(
                                'SELECT 1 FROM project_advisers WHERE project_id = ? AND adviser_id = ? LIMIT 1'
                            );
                            $duplicate_stmt->bind_param('ii', $project_id, $adviser_id);
                            $duplicate_stmt->execute();
                            $already_assigned = $duplicate_stmt->get_result()->num_rows > 0;
                            $duplicate_stmt->close();

                            if ($already_assigned) {
                                $_SESSION['module_error'] = 'That faculty member is already assigned to this project.';
                            } else {
                                if ($pa_has_role && $pa_has_assigned_at) {
                                    $insert_stmt = $conn->prepare(
                                        "INSERT INTO project_advisers (project_id, adviser_id, role, assigned_at)
                                         VALUES (?, ?, 'adviser', NOW())"
                                    );
                                } elseif ($pa_has_role) {
                                    $insert_stmt = $conn->prepare(
                                        "INSERT INTO project_advisers (project_id, adviser_id, role)
                                         VALUES (?, ?, 'adviser')"
                                    );
                                } elseif ($pa_has_assigned_at) {
                                    $insert_stmt = $conn->prepare(
                                        'INSERT INTO project_advisers (project_id, adviser_id, assigned_at) VALUES (?, ?, NOW())'
                                    );
                                } else {
                                    $insert_stmt = $conn->prepare(
                                        'INSERT INTO project_advisers (project_id, adviser_id) VALUES (?, ?)'
                                    );
                                }

                                if (!$insert_stmt) {
                                    $_SESSION['module_error'] = 'Could not prepare the adviser assignment.';
                                } else {
                                    $insert_stmt->bind_param('ii', $project_id, $adviser_id);
                                    if ($insert_stmt->execute()) {
                                        $adviser_name = trim($faculty['first_name'] . ' ' . $faculty['last_name']);
                                        createNotification(
                                            $adviser_id,
                                            'New adviser assignment',
                                            'You have been assigned as adviser for "' . $short . '".',
                                            'info',
                                            SITE_URL . 'pages/faculty/faculty-submissions.php'
                                        );
                                        createNotification(
                                            $student_id,
                                            'Adviser assigned',
                                            $adviser_name . ' has been assigned as adviser for "' . $short . '".',
                                            'info',
                                            SITE_URL . 'pages/student/my-research.php'
                                        );
                                        logActivity(
                                            'Assigned faculty #' . $adviser_id . ' (' . $adviser_name . ') as adviser for project #' . $project_id . ' ("' . $title . '")',
                                            'adviser_assignment'
                                        );
                                        $_SESSION['module_success'] = $adviser_name . ' assigned as adviser.';
                                    } else {
                                        $_SESSION['module_error'] = 'Could not assign the adviser. Please try again.';
                                    }
                                    $insert_stmt->close();
                                }
                            }
                        }
                    }

                } elseif ($action === 'remove_adviser') {
                    $adviser_id = isset($_POST['adviser_id']) ? (int) $_POST['adviser_id'] : 0;
                    if (!$project_advisers_exists || $adviser_id <= 0) {
                        $_SESSION['module_error'] = 'Invalid adviser assignment.';
                    } elseif (in_array((string) ($project['status'] ?? ''), $adviser_locked_statuses, true)) {
                        $_SESSION['module_error'] = 'Advisers can only be removed from active projects.';
                    } else {
                        $removed = false;
                        $assigned_adviser = null;

                        try {
                            $conn->begin_transaction();

                            $assigned_at_sql = $pa_has_assigned_at
                                ? 'COALESCE(pa.assigned_at, NOW())'
                                : 'NOW()';
                            $role_sql = $pa_has_role ? 'pa.role' : 'NULL';
                            $assigned_stmt = $conn->prepare(
                                "SELECT pa.adviser_id, {$assigned_at_sql} AS assigned_at,
                                        {$role_sql} AS adviser_role, u.first_name, u.last_name
                                   FROM project_advisers pa
                                   LEFT JOIN users u ON u.user_id = pa.adviser_id
                                  WHERE pa.project_id = ? AND pa.adviser_id = ?
                                  LIMIT 1
                                  FOR UPDATE"
                            );
                            if (!$assigned_stmt) {
                                throw new RuntimeException('Could not prepare the adviser lookup.');
                            }
                            $assigned_stmt->bind_param('ii', $project_id, $adviser_id);
                            $assigned_stmt->execute();
                            $assigned_adviser = $assigned_stmt->get_result()->fetch_assoc();
                            $assigned_stmt->close();

                            if (!$assigned_adviser) {
                                throw new RuntimeException('That adviser is no longer assigned to this project.');
                            }

                            $adviser_role = $assigned_adviser['adviser_role'] ?? null;
                            $assigned_at = (string) $assigned_adviser['assigned_at'];
                            $history_stmt = $conn->prepare(
                                'INSERT INTO project_advisers_history
                                    (project_id, adviser_id, role, assigned_at, removed_at, removed_by)
                                 VALUES (?, ?, ?, ?, NOW(), ?)'
                            );
                            if (!$history_stmt) {
                                throw new RuntimeException('Adviser history is unavailable. Please apply migration 009.');
                            }
                            $history_stmt->bind_param('iissi', $project_id, $adviser_id, $adviser_role, $assigned_at, $user_id);
                            if (!$history_stmt->execute()) {
                                $history_stmt->close();
                                throw new RuntimeException('Could not preserve the adviser assignment history.');
                            }
                            $history_stmt->close();

                            $delete_stmt = $conn->prepare(
                                'DELETE FROM project_advisers WHERE project_id = ? AND adviser_id = ?'
                            );
                            if (!$delete_stmt) {
                                throw new RuntimeException('Could not prepare the adviser removal.');
                            }
                            $delete_stmt->bind_param('ii', $project_id, $adviser_id);
                            $delete_stmt->execute();
                            $removed = $delete_stmt->affected_rows > 0;
                            $delete_stmt->close();

                            if (!$removed) {
                                throw new RuntimeException('That adviser is no longer assigned to this project.');
                            }

                            $conn->commit();
                        } catch (Throwable $exception) {
                            $conn->rollback();
                            error_log('Adviser removal failed for project #' . $project_id . ': ' . $exception->getMessage());
                            $_SESSION['module_error'] = 'Could not remove the adviser while preserving assignment history. Please verify migration 009 is applied and try again.';
                        }

                        if ($removed && $assigned_adviser) {
                                $adviser_name = trim(($assigned_adviser['first_name'] ?? '') . ' ' . ($assigned_adviser['last_name'] ?? ''));
                                if ($adviser_name === '') {
                                    $adviser_name = 'The previous adviser';
                                }
                                createNotification(
                                    $adviser_id,
                                    'Adviser assignment removed',
                                    'You are no longer assigned as adviser for "' . $short . '".',
                                    'warning',
                                    SITE_URL . 'pages/faculty/faculty-submissions.php'
                                );

                                $student_recipients = [];
                                if ($student_id > 0 && $student_id !== $adviser_id) {
                                    $student_recipients[$student_id] = true;
                                }
                                $member_stmt = $conn->prepare(
                                    'SELECT user_id FROM project_members WHERE project_id = ? AND user_id <> ?'
                                );
                                if ($member_stmt) {
                                    $member_stmt->bind_param('ii', $project_id, $adviser_id);
                                    $member_stmt->execute();
                                    $member_result = $member_stmt->get_result();
                                    while ($member = $member_result->fetch_assoc()) {
                                        $member_id = (int) ($member['user_id'] ?? 0);
                                        if ($member_id > 0) {
                                            $student_recipients[$member_id] = true;
                                        }
                                    }
                                    $member_stmt->close();
                                }

                                $student_message = $adviser_name . ' is no longer the adviser for "' . $title . '". A new adviser will be assigned.';
                                foreach (array_keys($student_recipients) as $recipient_id) {
                                    createNotification(
                                        (int) $recipient_id,
                                        'Adviser assignment changed',
                                        $student_message,
                                        'info',
                                        SITE_URL . 'pages/student/research-detail.php?id=' . $project_id
                                    );
                                }
                                logActivity(
                                    'Removed faculty #' . $adviser_id . ($adviser_name !== '' ? ' (' . $adviser_name . ')' : '')
                                    . ' as adviser for project #' . $project_id . ' ("' . $title . '")',
                                    'adviser_assignment'
                                );
                                $_SESSION['module_success'] = 'Adviser assignment removed.';
                        }
                    }

                } elseif ($action === 'forward_to_crec') {
                    // Only forward projects currently sitting at 'submitted'
                    $upd = $conn->prepare("
                        UPDATE research_projects
                           SET status = 'under_crec_review', updated_at = NOW()
                         WHERE project_id = ?
                           AND status = 'submitted'"
                           . $rp_deleted_filter
                    );
                    $upd->bind_param('i', $project_id);
                    $upd->execute();
                    $affected = $conn->affected_rows;
                    $upd->close();

                    if ($affected > 0) {
                        createNotification(
                            $student_id,
                            'Proposal forwarded to CREC',
                            'Your proposal "' . $short . '" has been forwarded to the College Research Evaluation Committee (CREC) for review.',
                            'info',
                            SITE_URL . 'pages/student/my-research.php'
                        );
                        logActivity(
                            'Forwarded submission #' . $project_id . ' ("' . $title . '") to CREC',
                            'submissions_inbox'
                        );
                        $_SESSION['module_success'] = 'Submission forwarded to CREC.';
                    } else {
                        $_SESSION['module_error'] = 'Submission is no longer in the verification queue (it may have already been processed).';
                    }

                } elseif ($action === 'return_for_revision') {
                    $reason = trim((string) ($_POST['revision_reason'] ?? ''));
                    if (mb_strlen($reason) < 10) {
                        $_SESSION['module_error'] = 'Revision reason is required (minimum 10 characters).';
                    } else {
                         $upd = $conn->prepare("
                            UPDATE research_projects
                               SET status = 'for_revision', updated_at = NOW()
                             WHERE project_id = ?
                               AND status = 'submitted'"
                               . $rp_deleted_filter
                        );
                        $upd->bind_param('i', $project_id);
                        $upd->execute();
                        $affected = $conn->affected_rows;
                        $upd->close();

                        if ($affected > 0) {
                            createNotification(
                                $student_id,
                                'Proposal returned for revision',
                                'Your proposal "' . $short . '" was returned for revision. Reason: ' . $reason,
                                'warning',
                                SITE_URL . 'pages/student/my-research.php'
                            );
                            logActivity(
                                'Returned submission #' . $project_id . ' ("' . $title . '") for revision: ' . $reason,
                                'submissions_inbox'
                            );
                            $_SESSION['module_success'] = 'Submission returned to the student for revision.';
                        } else {
                            $_SESSION['module_error'] = 'Submission is no longer in the verification queue (it may have already been processed).';
                        }
                    }

                } else {
                    $_SESSION['module_error'] = 'Unknown action.';
                }
            }
        }
    }

    // PRG: redirect back to the inbox (preserves any active filter via the referrer)
    $redirect = SITE_URL . 'pages/staff/staff-submissions.php';
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs !== '') {
        $redirect .= '?' . $qs;
    }
    header('Location: ' . $redirect);
    exit;
}

// ── filter parameters ────────────────────────────────────────────────────
$search   = trim((string) ($_GET['q']        ?? ''));
$category = (int)         ($_GET['category'] ?? 0);
$range    = (string)     ($_GET['range']     ?? 'all');
$status_view = (string)  ($_GET['status_view'] ?? 'submitted');

$valid_ranges = ['all', 'week', 'month'];
if (!in_array($range, $valid_ranges, true)) {
    $range = 'all';
}

$valid_status_views = ['submitted', 'active'];
if (!in_array($status_view, $valid_status_views, true)) {
    $status_view = 'submitted';
}

// ── pagination ───────────────────────────────────────────────────────────
$per_page = 20;
$page     = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// ── build WHERE clause dynamically ──────────────────────────────────────
$where  = [$status_view === 'active'
    ? "rp.status NOT IN ('draft', 'completed', 'archived', 'rejected')"
    : "rp.status = 'submitted'"];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = '(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
    $types   .= 'ssss';
}

if ($category > 0) {
    $where[]  = 'rp.category_id = ?';
    $params[] = $category;
    $types   .= 'i';
}

if ($range === 'week') {
    $where[] = 'rp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($range === 'month') {
    $where[] = 'rp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

$where_sql = implode(' AND ', $where);

// ── count (for stat card + pagination) ──────────────────────────────────
$count_sql  = "SELECT COUNT(*) AS c
                 FROM research_projects rp
                 LEFT JOIN users u ON u.user_id = rp.created_by
                WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($params !== []) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_pending = (int) ($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int) ceil($total_pending / $per_page));
if ($page > $total_pages) {
    $page   = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// ── main list query ──────────────────────────────────────────────────────
$list_sql = "
    SELECT
        rp.project_id,
        rp.title,
        rp.abstract,
        rp.created_at,
        rp.status,
        u.first_name,
        u.last_name,
        u.student_id,
        u.email,
        rc.category_name,
        up.file_name  AS proposal_file_name,
        up.file_path  AS proposal_file_path,
        up.original_name AS proposal_original_name
    FROM research_projects rp
    LEFT JOIN users u  ON u.user_id = rp.created_by
    LEFT JOIN research_categories rc ON rc.category_id = rp.category_id
    LEFT JOIN (
        SELECT u1.project_id, u1.file_name, u1.file_path, u1.original_name
          FROM uploads u1
          JOIN (
              SELECT project_id, MIN(upload_id) AS first_upload_id
                FROM uploads
               WHERE type = 'proposal'
               GROUP BY project_id
          ) first ON first.first_upload_id = u1.upload_id
    ) up ON up.project_id = rp.project_id
    WHERE $where_sql
    ORDER BY rp.created_at DESC
    LIMIT ? OFFSET ?
";

$list_stmt = $conn->prepare($list_sql);
$bind_types  = $types . 'ii';
$bind_params = $params;
$bind_params[] = $per_page;
$bind_params[] = $offset;
$list_stmt->bind_param($bind_types, ...$bind_params);
$list_stmt->execute();
$list_result = $list_stmt->get_result();
$submissions = [];
while ($row = $list_result->fetch_assoc()) {
    $submissions[] = $row;
}
$list_stmt->close();

// ── categories (for the filter dropdown) ─────────────────────────────────
// Current adviser assignments for the projects on this page.
$advisers_by_project = [];
if ($project_advisers_exists && !empty($submissions)) {
    $project_ids = array_map(fn($row) => (int) $row['project_id'], $submissions);
    $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
    $adviser_stmt = $conn->prepare("
        SELECT pa.project_id, pa.adviser_id, u.first_name, u.last_name
          FROM project_advisers pa
          LEFT JOIN users u ON u.user_id = pa.adviser_id
         WHERE pa.project_id IN ($placeholders)
           AND pa.adviser_id IS NOT NULL
         ORDER BY u.last_name, u.first_name
    ");
    if ($adviser_stmt) {
        $adviser_stmt->bind_param(str_repeat('i', count($project_ids)), ...$project_ids);
        $adviser_stmt->execute();
        $adviser_result = $adviser_stmt->get_result();
        while ($adviser = $adviser_result->fetch_assoc()) {
            $advisers_by_project[(int) $adviser['project_id']][] = $adviser;
        }
        $adviser_stmt->close();
    }
}

// All active faculty can serve as advisers; reviewer eligibility is separate.
$active_faculty = [];
$faculty_result = $conn->query("
    SELECT user_id, first_name, last_name, email
      FROM users
     WHERE role = 'faculty' AND status = 'active'
     ORDER BY last_name, first_name
");
if ($faculty_result) {
    $active_faculty = $faculty_result->fetch_all(MYSQLI_ASSOC);
    $faculty_result->close();
}

$cat_result = $conn->query("
    SELECT category_id, category_name
      FROM research_categories
     WHERE status = 1
     ORDER BY category_name ASC
");
$categories = $cat_result ? $cat_result->fetch_all(MYSQLI_ASSOC) : [];

// Build a query string for pagination links that preserves filters
function submissions_filter_qs(array $overrides = []): string {
    global $status_view;

    $base = [
        'q'        => $_GET['q']        ?? '',
        'category' => $_GET['category'] ?? '',
        'range'    => $_GET['range']    ?? 'all',
        'status_view' => $status_view,
        'page'     => '',
    ];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
}

// Render shell
renderStaffShell($user, 'staff-submissions', 'Submissions Inbox', 'Verify new proposal submissions and forward to CREC or return for revision.');
?>

<style>
  .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }

  /* Stat card */
  .stat-card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .stat-card .icon { font-size: 36px; }
  .stat-card .num  { text-align: center; font-size: 32px; font-weight: 700; line-height: 1; color: #111827; }
  .stat-card .lbl  { font-size: 13px; color: #64748B; margin-top: 4px; font-weight: 500; }

  /* Filter bar */
  .filter-bar {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 24px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
  }
  .filter-bar .field { display: flex; flex-direction: column; min-width: 180px; flex: 1; }
  .filter-bar label  { font-size: 13px; font-weight: 500; color: #111827; margin-bottom: 6px; }
  .filter-bar input,
  .filter-bar select {
    height: 40px;
    padding: 0 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #FFFFFF;
    color: #111827;
    font-family: inherit;
  }
  .filter-bar input:focus,
  .filter-bar select:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13,148,136,0.15);
  }
  .filter-bar .actions { display: flex; gap: 8px; }
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
    font-family: inherit;
  }
  .btn-primary   { background: #0d9488; color: white; }
  .btn-primary:hover { background: #059669; }
  .btn-secondary { background: #FFFFFF; color: #111827; border: 1px solid #E5E7EB; }
  .btn-secondary:hover { background: #F1F5F9; }
  .btn-danger    { background: #EA580C; color: white; }
  .btn-danger:hover { background: #C2410C; }
  .btn-sm        { padding: 6px 12px; font-size: 12px; }

  /* Table */
  .card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    overflow: hidden;
  }
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  thead { background: #F8FAFC; }
  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #E5E7EB;
  }
  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
    vertical-align: top;
  }
  tr:hover { background: #F8FAFC; }
  .badge-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
  }
  .badge-status.status-draft     { background: #F1F5F9; color: #64748B; }
  .badge-status.status-review    { background: #DBEAFE; color: #2563EB; }
  .badge-status.status-pending   { background: #FEF3C7; color: #EA580C; }
  .badge-status.status-approved  { background: #DCFCE7; color: #16A34A; }

  .proposal-link {
    color: #0d9488;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
  }
  .proposal-link:hover { text-decoration: underline; }
  .proposal-missing { color: #EA580C; font-size: 13px; font-weight: 500; }

  .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .adviser-panel { min-width: 230px; }
  .adviser-panel summary { cursor: pointer; color: #0F766E; font-size: 13px; font-weight: 600; }
  .adviser-panel[open] summary { margin-bottom: 10px; }
  .adviser-list { display: grid; gap: 8px; margin-bottom: 10px; }
  .adviser-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; }
  .adviser-assign { display: flex; align-items: center; gap: 6px; }
  .adviser-assign select { min-width: 150px; padding: 7px 9px; border: 1px solid #E5E7EB; border-radius: 8px; background: #FFFFFF; }
  .warning-badge { display: inline-block; padding: 4px 9px; border-radius: 9999px; background: #FEF3C7; color: #B45309; font-size: 12px; font-weight: 600; }

  /* Empty state */
  .empty-state {
    text-align: center;
    padding: 64px 24px;
    color: #94A3B8;
  }
  .empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.6; }
  .empty-state p    { font-size: 14px; }

  /* Pagination */
  .pagination {
    padding: 16px 20px;
    border-top: 1px solid #E5E7EB;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 13px;
    color: #64748B;
  }
  .pagination .pages { display: flex; gap: 6px; }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .modal-content {
    background: #FFFFFF;
    border-radius: 16px;
    width: 100%;
    max-width: 560px;
    overflow: hidden;
  }
  .modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #E5E7EB;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #111827; }
  .modal-close {
    background: none;
    border: none;
    font-size: 1.6rem;
    cursor: pointer;
    color: #64748B;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
  }
  .modal-close:hover { background: #F1F5F9; }
  .modal-body   { padding: 20px 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid #E5E7EB; display: flex; justify-content: flex-end; gap: 8px; }

  .form-group { margin-bottom: 16px; }
  .form-label { display: block; font-size: 13px; font-weight: 600; color: #111827; margin-bottom: 6px; }
  .form-help  { display: block; font-size: 12px; color: #64748B; margin-top: 4px; }
  .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    color: #111827;
    background: #FFFFFF;
    box-sizing: border-box;
  }
  .form-control:focus {
    outline: none;
    border-color: #EA580C;
    box-shadow: 0 0 0 3px rgba(234,88,12,0.15);
  }
  .form-control.invalid { border-color: #EF4444; }

  @media (max-width: 768px) {
    .stat-card { flex-direction: column; align-items: flex-start; }
    td, th { padding: 12px; font-size: 13px; }
  }
</style>

<?php if (isset($_SESSION['module_success'])): ?>
  <div class="alert alert-success">✓ <?php echo se($_SESSION['module_success']); unset($_SESSION['module_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['module_error'])): ?>
  <div class="alert alert-error">✕ <?php echo se($_SESSION['module_error']); unset($_SESSION['module_error']); ?></div>
<?php endif; ?>

<!-- Stat summary -->
<div class="stat-card">
  <div class="icon">📥</div>
  <div>
    <div class="num"><?php echo se($total_pending); ?></div>
    <div class="lbl"><?php echo se($status_view === 'active' ? 'Active projects' : 'Pending verification' . ($total_pending !== 1 ? 's' : '')); ?></div>
  </div>
</div>

<!-- Filter bar -->
<form method="GET" class="filter-bar" action="">
  <div class="field">
    <label for="f_status_view">Project view</label>
    <select id="f_status_view" name="status_view">
      <option value="submitted" <?php echo $status_view === 'submitted' ? 'selected' : ''; ?>>Intake queue</option>
      <option value="active" <?php echo $status_view === 'active' ? 'selected' : ''; ?>>All active projects</option>
    </select>
  </div>

  <div class="field">
    <label for="f_q">Search</label>
    <input id="f_q" type="text" name="q" value="<?php echo se($search); ?>" placeholder="Title, student name, or student ID…">
  </div>

  <div class="field">
    <label for="f_cat">Category</label>
    <select id="f_cat" name="category">
      <option value="0">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?php echo (int) $cat['category_id']; ?>" <?php echo $category === (int) $cat['category_id'] ? 'selected' : ''; ?>>
          <?php echo se($cat['category_name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="f_range">Date range</label>
    <select id="f_range" name="range">
      <option value="all"   <?php echo $range === 'all'   ? 'selected' : ''; ?>>All time</option>
      <option value="week"  <?php echo $range === 'week'  ? 'selected' : ''; ?>>This week</option>
      <option value="month" <?php echo $range === 'month' ? 'selected' : ''; ?>>This month</option>
    </select>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="<?php echo SITE_URL; ?>pages/staff/staff-submissions.php" class="btn btn-secondary">Reset</a>
  </div>
</form>

<!-- Submissions table -->
<div class="card">
  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-state-icon">📭</div>
      <p><?php echo se($status_view === 'active' ? 'No active projects match your filters.' : 'No pending submissions match your filters.'); ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 60px;">#</th>
            <th>Research Title</th>
            <th>Student</th>
            <th>Category</th>
            <th>Submitted</th>
            <th>Proposal</th>
            <th>Adviser</th>
            <th>Status</th>
            <th style="min-width: 220px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($submissions as $i => $row):
            $idx           = $offset + $i + 1;
            [$b_class, $b_label] = submissions_statusBadge($row['status'] ?? 'submitted');

            $student_name  = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
            $student_id_v  = $row['student_id'] ?? '';
            $cat_name      = $row['category_name'] ?? '—';
            $sub_date      = !empty($row['created_at'])
                ? date('M d, Y • h:i A', strtotime($row['created_at']))
                : '—';

            $proposal_url  = !empty($row['proposal_file_path'])
                ? SITE_URL . $row['proposal_file_path']
                : '';
            $proposal_name = $row['proposal_original_name'] ?? 'proposal file';
            $project_advisers = $advisers_by_project[(int) $row['project_id']] ?? [];
            $assigned_adviser_ids = array_map(fn($adviser) => (int) $adviser['adviser_id'], $project_advisers);
            $faculty_to_assign = array_filter($active_faculty, function ($faculty) use ($assigned_adviser_ids) {
                return !in_array((int) $faculty['user_id'], $assigned_adviser_ids, true);
            });
          ?>
            <tr>
              <td style="color: #64748B; font-weight: 500;"><?php echo se($idx); ?></td>
              <td style="max-width: 320px;">
                <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $row['project_id']; ?>"
                   style="color: #111827; font-weight: 600; text-decoration: none;">
                  <?php echo se($row['title']); ?>
                </a>
              </td>
              <td>
                <div style="font-weight: 500; color: #111827;"><?php echo se($student_name); ?></div>
                <?php if ($student_id_v !== ''): ?>
                  <div style="font-size: 12px; color: #64748B;">🎒 <?php echo se($student_id_v); ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size: 13px; color: #64748B;"><?php echo se($cat_name); ?></td>
              <td style="font-size: 13px; color: #64748B; white-space: nowrap;"><?php echo se($sub_date); ?></td>
              <td>
                <?php if ($proposal_url !== ''): ?>
                  <a class="proposal-link" href="<?php echo se($proposal_url); ?>" target="_blank" rel="noopener">
                    📎 <?php echo se(mb_substr($proposal_name, 0, 28) . (mb_strlen($proposal_name) > 28 ? '…' : '')); ?>
                  </a>
                <?php else: ?>
                  <span class="proposal-missing">⚠️ Not attached</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$project_advisers_exists): ?>
                  <span class="warning-badge">Assignment unavailable</span>
                <?php else: ?>
                  <details class="adviser-panel">
                    <summary>
                      <?php if (empty($project_advisers)): ?>
                        <span class="warning-badge">No adviser assigned</span>
                      <?php else: ?>
                        <?php
                          $adviser_names = array_map(function ($adviser) {
                              $name = trim(($adviser['first_name'] ?? '') . ' ' . ($adviser['last_name'] ?? ''));
                              return $name !== '' ? $name : 'Faculty #' . (int) $adviser['adviser_id'];
                          }, $project_advisers);
                          echo se(implode(', ', $adviser_names));
                        ?>
                      <?php endif; ?>
                    </summary>

                    <?php if (!empty($project_advisers)): ?>
                      <div class="adviser-list">
                        <?php foreach ($project_advisers as $adviser):
                          $adviser_name = trim(($adviser['first_name'] ?? '') . ' ' . ($adviser['last_name'] ?? ''));
                          if ($adviser_name === '') $adviser_name = 'Faculty #' . (int) $adviser['adviser_id'];
                        ?>
                          <div class="adviser-item">
                            <span><?php echo se($adviser_name); ?></span>
                            <form method="POST" onsubmit="return confirm('Remove this adviser assignment?');">
                              <?php echo csrfField(); ?>
                              <input type="hidden" name="action" value="remove_adviser">
                              <input type="hidden" name="project_id" value="<?php echo (int) $row['project_id']; ?>">
                              <input type="hidden" name="adviser_id" value="<?php echo (int) $adviser['adviser_id']; ?>">
                              <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                            </form>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <form method="POST" class="adviser-assign">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="assign_adviser">
                      <input type="hidden" name="project_id" value="<?php echo (int) $row['project_id']; ?>">
                      <select name="adviser_id" aria-label="Select adviser" required <?php echo empty($faculty_to_assign) ? 'disabled' : ''; ?>>
                        <option value="">Select faculty</option>
                        <?php foreach ($faculty_to_assign as $faculty):
                          $faculty_name = trim(($faculty['first_name'] ?? '') . ' ' . ($faculty['last_name'] ?? ''));
                        ?>
                          <option value="<?php echo (int) $faculty['user_id']; ?>">
                            <?php echo se($faculty_name . (!empty($faculty['email']) ? ' — ' . $faculty['email'] : '')); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-primary btn-sm" <?php echo empty($faculty_to_assign) ? 'disabled' : ''; ?>>Assign</button>
                    </form>
                  </details>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-status <?php echo se($b_class); ?>"><?php echo se($b_label); ?></span>
              </td>
              <td>
                <div class="row-actions">
                  <form method="POST" style="display: inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="forward_to_crec">
                    <input type="hidden" name="project_id" value="<?php echo (int) $row['project_id']; ?>">
                    <button type="submit" class="btn btn-primary btn-sm" title="Forward to CREC">
                      ✓ Forward
                    </button>
                  </form>
                  <button type="button" class="btn btn-danger btn-sm"
                          onclick="openReturnModal(<?php echo (int) $row['project_id']; ?>, '<?php echo se(addslashes($row['title'])); ?>')">
                    ↩ Return
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <div>
          Showing <?php echo se($offset + 1); ?>–<?php echo se(min($offset + $per_page, $total_pending)); ?>
          of <?php echo se($total_pending); ?>
        </div>
        <div class="pages">
          <?php if ($page > 1): ?>
            <a class="btn btn-secondary btn-sm" href="?<?php echo se(submissions_filter_qs(['page' => $page - 1])); ?>">← Previous</a>
          <?php endif; ?>
          <span style="padding: 6px 12px; color: #64748B;">Page <?php echo se($page); ?> of <?php echo se($total_pages); ?></span>
          <?php if ($page < $total_pages): ?>
            <a class="btn btn-secondary btn-sm" href="?<?php echo se(submissions_filter_qs(['page' => $page + 1])); ?>">Next →</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Return-for-revision modal -->
<div id="returnModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
  <div class="modal-content">
    <form method="POST" id="returnForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="return_for_revision">
      <input type="hidden" name="project_id" id="return_project_id" value="">

      <div class="modal-header">
        <h3 id="returnModalTitle">↩ Return for Revision</h3>
        <button type="button" class="modal-close" onclick="closeReturnModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Returning: <strong id="return_title" style="color: #111827;"></strong>
        </p>
        <div class="form-group">
          <label class="form-label" for="revision_reason">Reason <span style="color: #EF4444;">*</span></label>
          <textarea id="revision_reason" name="revision_reason" class="form-control" rows="4" minlength="10" required
                    placeholder="Tell the student what needs to be fixed (min. 10 characters)…"></textarea>
          <span class="form-help">This message will be sent to the student as a notification.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">Return to Student</button>
      </div>
    </form>
  </div>
</div>

<script>
  const returnModal = document.getElementById('returnModal');
  const returnForm  = document.getElementById('returnForm');
  const returnTitle = document.getElementById('return_title');
  const returnPid   = document.getElementById('return_project_id');
  const reasonField = document.getElementById('revision_reason');

  function openReturnModal(projectId, title) {
    returnPid.value     = projectId;
    returnTitle.textContent = title;
    reasonField.value   = '';
    reasonField.classList.remove('invalid');
    returnModal.style.display = 'flex';
    setTimeout(() => reasonField.focus(), 50);
  }

  function closeReturnModal() {
    returnModal.style.display = 'none';
  }

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && returnModal.style.display === 'flex') {
      closeReturnModal();
    }
  });

  // Close on backdrop click
  returnModal.addEventListener('click', (e) => {
    if (e.target === returnModal) {
      closeReturnModal();
    }
  });

  // Client-side guard: minimum 10 characters before submit
  returnForm.addEventListener('submit', (e) => {
    const v = reasonField.value.trim();
    if (v.length < 10) {
      e.preventDefault();
      reasonField.classList.add('invalid');
      reasonField.focus();
    }
  });
  reasonField.addEventListener('input', () => reasonField.classList.remove('invalid'));
</script>

<?php renderStaffShellClose(); ?>
