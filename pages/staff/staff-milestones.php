<?php
/**
 * Staff — Milestone Verification Queue
 *
 * Research Staff & Admin verify milestone documents that students submit
 * via pages/student/submit-milestone.php:
 *
 *   - research_documents (document_type: mou, nda, progress_report, terminal_report, etc.)
 *       status enum: pending / submitted / approved / rejected / waived
 *   - research_reports  (report_type:    midway_progress, terminal)
 *       status enum: draft / submitted / under_review / revision_required / approved / rejected
 *
 * Actions:
 *   - approve_doc   → research_documents.status = 'approved'
 *   - reject_doc    → research_documents.status = 'rejected' (reason required, stored in remarks)
 *   - waive_doc     → research_documents.status = 'waived'
 *   - approve_rep   → research_reports.status  = 'approved'
 *   - reject_rep    → research_reports.status  = 'rejected' (reason required)
 *   - mark_review   → research_reports.status  = 'under_review'  (optional ack without final decision)
 *
 * On any action: update status + reviewed_by + reviewed_at (if column exists),
 * logActivity(), and notify the student who submitted the milestone.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole(['research_staff', 'admin']);

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── helpers ────────────────────────────────────────────────────────────────
function smil_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Status badge helper for the documents table (status enum: pending/submitted/approved/rejected/waived)
function smil_doc_statusBadge(string $status): array {
    $map = [
        'pending'   => ['status-pending',  'Pending'],
        'submitted' => ['status-review',   'Submitted'],
        'approved'  => ['status-approved', 'Approved'],
        'rejected'  => ['status-pending',  'Rejected'],
        'waived'    => ['status-draft',    'Waived'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

// Status badge helper for the reports table
function smil_rep_statusBadge(string $status): array {
    $map = [
        'draft'             => ['status-draft',    'Draft'],
        'submitted'         => ['status-review',   'Pending Review'],
        'under_review'      => ['status-review',   'Under Review'],
        'revision_required' => ['status-pending',  'Revision Required'],
        'approved'          => ['status-approved', 'Approved'],
        'rejected'          => ['status-pending',  'Rejected'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

// Human-friendly label for the document_type / report_type
function smil_milestone_label(string $kind, string $type): string {
    if ($kind === 'doc') {
        $map = [
            'mou'                  => 'MOU',
            'nda'                  => 'NDA',
            'progress_report'      => 'Midway Progress Report',
            'terminal_report'      => 'Terminal Report',
            'final_bound_report'   => 'Final Bound Report',
            'publication_record'   => 'Publication Record',
            'defense_material'     => 'Defense Material',
            'revision_checklist'   => 'Revision Checklist',
            'proposal'             => 'Proposal Document',
            'other'                => 'Document',
        ];
    } else {
        $map = [
            'midway_progress' => 'Midway Progress Report',
            'terminal'        => 'Terminal Report',
        ];
    }
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function smil_relative_time(?string $datetime): string {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    $diff = time() - $ts;
    if ($diff < 0)         return date('M d, Y', $ts);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff / 60) . ' min ago';
    if ($diff < 86400)     return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800)    return floor($diff / 86400) . ' day' . (floor($diff / 86400) !== 1 ? 's' : '') . ' ago';
    return date('M d, Y', $ts);
}

// ── runtime schema detection ──────────────────────────────────────────────
$rp_has_deleted_at = false;
$rp_check = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($rp_check) {
    $rp_check->execute();
    $rp_has_deleted_at = $rp_check->get_result()->num_rows > 0;
    $rp_check->close();
}
$rp_deleted_filter_aliased = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// Are the milestone tables present? Use direct query() calls (not prepared)
// and fully consume each result set before the next query, to avoid the
// mysqli "Commands out of sync" error.
$smil_has_documents = false;
$smil_has_reports   = false;
$t1 = $conn->query("SHOW TABLES LIKE 'research_documents'");
if ($t1) {
    $smil_has_documents = ($t1->num_rows > 0);
    $t1->free();
}
$t2 = $conn->query("SHOW TABLES LIKE 'research_reports'");
if ($t2) {
    $smil_has_reports = ($t2->num_rows > 0);
    $t2->free();
}

// Does research_reports.document_id exist (added by migration)? It is in the
// migration schema, but defend against older installs.
$smil_reports_has_document_id = false;
if ($smil_has_reports) {
    $c1 = $conn->query("SHOW COLUMNS FROM research_reports LIKE 'document_id'");
    if ($c1) {
        $smil_reports_has_document_id = ($c1->num_rows > 0);
        $c1->free();
    }
}

// Are reviewed_by / reviewed_at present on each table?
function smil_column_exists(mysqli $conn, string $table, string $column): bool {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    if (!$r) return false;
    $has = ($r->num_rows > 0);
    $r->free();
    return $has;
}
$smil_doc_has_reviewed_by = $smil_has_documents ? smil_column_exists($conn, 'research_documents', 'reviewed_by') : false;
$smil_doc_has_reviewed_at = $smil_has_documents ? smil_column_exists($conn, 'research_documents', 'reviewed_at') : false;
$smil_rep_has_reviewed_by = $smil_has_reports   ? smil_column_exists($conn, 'research_reports',   'reviewed_by') : false;
$smil_rep_has_reviewed_at = $smil_has_reports   ? smil_column_exists($conn, 'research_reports',   'reviewed_at') : false;

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $action = (string) $_POST['action'];
        $kind   = (string) ($_POST['kind'] ?? ''); // 'doc' or 'rep'

        $redirect = SITE_URL . 'pages/staff/staff-milestones.php';
        $qs       = $_SERVER['QUERY_STRING'] ?? '';
        if ($qs !== '') {
            $redirect .= '?' . $qs;
        }

        if (!in_array($kind, ['doc', 'rep'], true)) {
            $_SESSION['module_error'] = 'Invalid milestone kind.';
            header('Location: ' . $redirect);
            exit;
        }

        // Validate the row + load student info for notification
        $row_id = isset($_POST['row_id']) ? (int) $_POST['row_id'] : 0;
        if ($row_id <= 0) {
            $_SESSION['module_error'] = 'Invalid milestone reference.';
            header('Location: ' . $redirect);
            exit;
        }

        if ($kind === 'doc') {
            if (!$smil_has_documents) {
                $_SESSION['module_error'] = 'research_documents table is not available.';
                header('Location: ' . $redirect);
                exit;
            }
            $info_stmt = $conn->prepare("
                SELECT rd.document_id, rd.project_id, rd.document_type, rd.status,
                       rd.remarks, rd.submitted_by,
                       rp.title AS project_title
                  FROM research_documents rd
                  JOIN research_projects  rp ON rp.project_id = rd.project_id"
                  . ($rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '') . "
                 WHERE rd.document_id = ?
                 LIMIT 1
            ");
        } else {
            if (!$smil_has_reports) {
                $_SESSION['module_error'] = 'research_reports table is not available.';
                header('Location: ' . $redirect);
                exit;
            }
            $info_stmt = $conn->prepare("
                SELECT rr.report_id AS document_id, rr.project_id,
                       rr.report_type AS document_type, rr.status,
                       rr.summary AS remarks, rr.submitted_at AS submitted_by_dummy,
                       rp.title AS project_title,
                       (SELECT rd.submitted_by FROM research_documents rd
                         WHERE rd.document_id = rr.document_id LIMIT 1) AS submitted_by
                  FROM research_reports rr
                  JOIN research_projects rp ON rp.project_id = rr.project_id"
                  . ($rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '') . "
                 WHERE rr.report_id = ?
                 LIMIT 1
            ");
        }

        $info_stmt->bind_param('i', $row_id);
        $info_stmt->execute();
        $row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        if (!$row) {
            $_SESSION['module_error'] = 'Milestone not found.';
            header('Location: ' . $redirect);
            exit;
        }

        $student_id  = (int) ($row['submitted_by'] ?? 0);
        $proj_title  = (string) ($row['project_title'] ?? 'your research');
        $short_title = mb_strlen($proj_title) > 60 ? mb_substr($proj_title, 0, 60) . '…' : $proj_title;
        $doc_type    = (string) ($row['document_type'] ?? '');
        $kind_label  = smil_milestone_label($kind === 'doc' ? 'doc' : 'rep', $doc_type);
        $current     = strtolower((string) ($row['status'] ?? ''));

        // Only rows currently in 'submitted' can be acted on (unless the status
        // is already terminal — in that case the action would be a no-op).
        if (!in_array($current, ['submitted'], true) && !in_array($action, ['noop'], true)) {
            $_SESSION['module_error'] = 'This milestone has already been processed (status: ' . $current . ').';
            header('Location: ' . $redirect);
            exit;
        }

        $reason      = trim((string) ($_POST['reason'] ?? ''));
        $link_student = SITE_URL . 'pages/student/progress-tracking.php?project_id=' . (int) $row['project_id'];

        $new_status = '';
        $log_msg    = '';
        $notif_type = 'info';
        $notif_msg  = '';

        if ($action === 'approve_doc' && $kind === 'doc') {
            $new_status = 'approved';
            $log_msg    = 'Approved ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '")';
            $notif_type = 'success';
            $notif_msg  = 'Your ' . $kind_label . ' for "' . $short_title . '" has been approved.';
        } elseif ($action === 'reject_doc' && $kind === 'doc') {
            if (mb_strlen($reason) < 10) {
                $_SESSION['module_error'] = 'Rejection reason is required (minimum 10 characters).';
                header('Location: ' . $redirect);
                exit;
            }
            $new_status = 'rejected';
            $log_msg    = 'Rejected ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '"): ' . $reason;
            $notif_type = 'error';
            $notif_msg  = 'Your ' . $kind_label . ' for "' . $short_title . '" was rejected. Reason: ' . $reason;
        } elseif ($action === 'waive_doc' && $kind === 'doc') {
            $new_status = 'waived';
            $log_msg    = 'Waived ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '")';
            $notif_type = 'info';
            $notif_msg  = 'Your ' . $kind_label . ' requirement for "' . $short_title . '" has been waived (no submission required).';
        } elseif ($action === 'approve_rep' && $kind === 'rep') {
            $new_status = 'approved';
            $log_msg    = 'Approved ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '")';
            $notif_type = 'success';
            $notif_msg  = 'Your ' . $kind_label . ' for "' . $short_title . '" has been approved.';
        } elseif ($action === 'reject_rep' && $kind === 'rep') {
            if (mb_strlen($reason) < 10) {
                $_SESSION['module_error'] = 'Rejection reason is required (minimum 10 characters).';
                header('Location: ' . $redirect);
                exit;
            }
            $new_status = 'rejected';
            $log_msg    = 'Rejected ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '"): ' . $reason;
            $notif_type = 'error';
            $notif_msg  = 'Your ' . $kind_label . ' for "' . $short_title . '" was rejected. Reason: ' . $reason;
        } elseif ($action === 'mark_review' && $kind === 'rep') {
            $new_status = 'under_review';
            $log_msg    = 'Acknowledged ' . $kind_label . ' for project #' . (int) $row['project_id'] . ' ("' . $proj_title . '") — under review';
            $notif_type = 'info';
            $notif_msg  = 'Your ' . $kind_label . ' for "' . $short_title . '" is now under review.';
        } else {
            $_SESSION['module_error'] = 'Unknown action.';
            header('Location: ' . $redirect);
            exit;
        }

        // Build the UPDATE for documents
        $extra_sets = [];
        $extra_sets[] = "status = ?";
        if ($kind === 'doc' && $action === 'reject_doc') {
            $extra_sets[] = "remarks = ?";
        }
        if ($kind === 'doc' && $smil_doc_has_reviewed_by) {
            $extra_sets[] = "reviewed_by = ?";
        }
        if ($kind === 'doc' && $smil_doc_has_reviewed_at) {
            $extra_sets[] = "reviewed_at = NOW()";
        }
        $set_clause = implode(', ', $extra_sets);

        if ($kind === 'doc') {
            $types  = 's' . ($action === 'reject_doc' ? 's' : '') . 'ii';
            $params = [$new_status];
            if ($action === 'reject_doc') {
                $params[] = $reason;
            }
            $params[] = $user_id;
            $params[] = $row_id;

            $sql = "UPDATE research_documents SET $set_clause"
                 . ($smil_doc_has_reviewed_by ? '' : '')
                 . " WHERE document_id = ?";
            $upd = $conn->prepare($sql);
            if (!$upd) {
                $_SESSION['module_error'] = 'Failed to prepare update statement.';
                header('Location: ' . $redirect);
                exit;
            }
            // bind_param requires references
            $bind_params = [];
            foreach ($params as $k => $v) { $bind_params[$k] = &$params[$k]; }
            call_user_func_array([$upd, 'bind_param'], array_merge([$types], $bind_params));
        } else {
            $rep_extra = [];
            $rep_extra[] = "status = ?";
            if ($smil_rep_has_reviewed_by) {
                $rep_extra[] = "reviewed_by = ?";
            }
            if ($smil_rep_has_reviewed_at) {
                $rep_extra[] = "reviewed_at = NOW()";
            }
            $rep_set = implode(', ', $rep_extra);

            $rep_params = [$new_status];
            if ($smil_rep_has_reviewed_by) {
                $rep_params[] = $user_id;
            }
            $rep_params[] = $row_id;

            $rep_types = 's' . ($smil_rep_has_reviewed_by ? 'i' : '') . 'i';
            $sql = "UPDATE research_reports SET $rep_set WHERE report_id = ?";
            $upd = $conn->prepare($sql);
            if (!$upd) {
                $_SESSION['module_error'] = 'Failed to prepare update statement.';
                header('Location: ' . $redirect);
                exit;
            }
            $bind_params = [];
            foreach ($rep_params as $k => $v) { $bind_params[$k] = &$rep_params[$k]; }
            call_user_func_array([$upd, 'bind_param'], array_merge([$rep_types], $bind_params));
        }

        $upd->execute();
        $affected = $conn->affected_rows;
        $upd->close();

        if ($affected > 0) {
            // Notify the student
            if ($student_id > 0) {
                createNotification(
                    $student_id,
                    $kind_label . ' — ' . ucwords(str_replace('_', ' ', $new_status)),
                    $notif_msg,
                    $notif_type,
                    $link_student
                );
            }
            logActivity($log_msg, 'milestone_verification');
            $_SESSION['module_success'] = $kind_label . ' marked as ' . str_replace('_', ' ', $new_status) . '.';
        } else {
            $_SESSION['module_error'] = 'No changes were made — the milestone may have been processed already.';
        }
    }

    $redirect = SITE_URL . 'pages/staff/staff-milestones.php';
    $qs       = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs !== '') {
        $redirect .= '?' . $qs;
    }
    header('Location: ' . $redirect);
    exit;
}

// ── GET filter ────────────────────────────────────────────────────────────
$filter = (string) ($_GET['filter'] ?? 'pending');
if (!in_array($filter, ['pending', 'processed'], true)) {
    $filter = 'pending';
}

// ── stat cards ────────────────────────────────────────────────────────────
$stat_pending   = 0;
$stat_approved  = 0;
$stat_rejected  = 0;

if ($smil_has_documents) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM research_documents WHERE status = 'submitted'");
    if ($r) { $stat_pending += (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }
}
if ($smil_has_reports) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM research_reports WHERE status = 'submitted'");
    if ($r) { $stat_pending += (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }
}

// Approved this month (documents + reports)
$month_start = date('Y-m-01 00:00:00');
$approved_sql_parts = [];
if ($smil_has_documents) {
    if ($smil_doc_has_reviewed_at) {
        $approved_sql_parts[] = "SELECT 'doc' AS kind FROM research_documents WHERE status = 'approved' AND reviewed_at >= '$month_start'";
    } else {
        $approved_sql_parts[] = "SELECT 'doc' AS kind FROM research_documents WHERE status = 'approved'";
    }
}
if ($smil_has_reports) {
    if ($smil_rep_has_reviewed_at) {
        $approved_sql_parts[] = "SELECT 'rep' AS kind FROM research_reports WHERE status = 'approved' AND reviewed_at >= '$month_start'";
    } else {
        $approved_sql_parts[] = "SELECT 'rep' AS kind FROM research_reports WHERE status = 'approved'";
    }
}
if (!empty($approved_sql_parts)) {
    $r = $conn->query(implode(' UNION ALL ', $approved_sql_parts));
    if ($r) { $stat_approved = (int) $r->num_rows; $r->close(); }
}

$rejected_sql_parts = [];
if ($smil_has_documents) {
    if ($smil_doc_has_reviewed_at) {
        $rejected_sql_parts[] = "SELECT 'doc' AS kind FROM research_documents WHERE status = 'rejected' AND reviewed_at >= '$month_start'";
    } else {
        $rejected_sql_parts[] = "SELECT 'doc' AS kind FROM research_documents WHERE status = 'rejected'";
    }
}
if ($smil_has_reports) {
    if ($smil_rep_has_reviewed_at) {
        $rejected_sql_parts[] = "SELECT 'rep' AS kind FROM research_reports WHERE status = 'rejected' AND reviewed_at >= '$month_start'";
    } else {
        $rejected_sql_parts[] = "SELECT 'rep' AS kind FROM research_reports WHERE status = 'rejected'";
    }
}
if (!empty($rejected_sql_parts)) {
    $r = $conn->query(implode(' UNION ALL ', $rejected_sql_parts));
    if ($r) { $stat_rejected = (int) $r->num_rows; $r->close(); }
}

// ── main query (union of documents + reports) ─────────────────────────────
// Build a unified row shape: (kind, row_id, project_id, project_title, type,
// status, remarks, submitted_by, submitted_at, file_path, file_name, original_name,
// student_first, student_last, student_id_no, reviewed_at)
$rows = [];

if ($filter === 'pending') {
    // PENDING = status = 'submitted'
    $union_parts = [];

    if ($smil_has_documents) {
        $union_parts[] = "
            SELECT 'doc' AS kind,
                   rd.document_id AS row_id,
                   rd.project_id,
                   rp.title AS project_title,
                   rd.document_type AS mtype,
                   rd.status,
                   rd.remarks,
                   rd.submitted_by,
                   rd.submitted_at,
                   u.file_path,
                   u.file_name,
                   u.original_name,
                   u.mime_type,
                   usr.first_name AS student_first,
                   usr.last_name  AS student_last,
                   usr.student_id AS student_id_no,
                   usr.email      AS student_email,
                   " . ($smil_doc_has_reviewed_at ? "rd.reviewed_at" : "NULL") . " AS reviewed_at
              FROM research_documents rd
              JOIN research_projects  rp  ON rp.project_id = rd.project_id" . $rp_deleted_filter_aliased . "
              LEFT JOIN uploads        u  ON u.upload_id  = rd.upload_id
              LEFT JOIN users          usr ON usr.user_id = rd.submitted_by
             WHERE rd.status = 'submitted'
        ";
    }

    if ($smil_has_reports) {
        $union_parts[] = "
            SELECT 'rep' AS kind,
                   rr.report_id AS row_id,
                   rr.project_id,
                   rp.title AS project_title,
                   rr.report_type AS mtype,
                   rr.status,
                   rr.summary AS remarks,
                   (SELECT rd2.submitted_by
                      FROM research_documents rd2
                     WHERE rd2.document_id = rr.document_id
                     LIMIT 1) AS submitted_by,
                   rr.submitted_at,
                   u.file_path,
                   u.file_name,
                   u.original_name,
                   u.mime_type,
                   usr.first_name AS student_first,
                   usr.last_name  AS student_last,
                   usr.student_id AS student_id_no,
                   usr.email      AS student_email,
                   " . ($smil_rep_has_reviewed_at ? "rr.reviewed_at" : "NULL") . " AS reviewed_at
              FROM research_reports rr
              JOIN research_projects  rp  ON rp.project_id = rr.project_id" . $rp_deleted_filter_aliased . "
              LEFT JOIN research_documents rd_link ON rd_link.document_id = rr.document_id
              LEFT JOIN uploads        u  ON u.upload_id  = rd_link.upload_id
              LEFT JOIN users          usr ON usr.user_id = rd_link.submitted_by
             WHERE rr.status = 'submitted'
        ";
    }

    if (!empty($union_parts)) {
        $sql = implode(' UNION ALL ', $union_parts) . " ORDER BY submitted_at DESC, row_id DESC";
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $res->close();
        }
    }
} else {
    // RECENTLY PROCESSED = approved / rejected / waived in the last 30 days
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
    $union_parts = [];

    if ($smil_has_documents) {
        $union_parts[] = "
            SELECT 'doc' AS kind,
                   rd.document_id AS row_id,
                   rd.project_id,
                   rp.title AS project_title,
                   rd.document_type AS mtype,
                   rd.status,
                   rd.remarks,
                   rd.submitted_by,
                   rd.submitted_at,
                   u.file_path,
                   u.file_name,
                   u.original_name,
                   u.mime_type,
                   usr.first_name AS student_first,
                   usr.last_name  AS student_last,
                   usr.student_id AS student_id_no,
                   usr.email      AS student_email,
                   " . ($smil_doc_has_reviewed_at ? "rd.reviewed_at" : "NULL") . " AS reviewed_at
              FROM research_documents rd
              JOIN research_projects  rp  ON rp.project_id = rd.project_id" . $rp_deleted_filter_aliased . "
              LEFT JOIN uploads        u  ON u.upload_id  = rd.upload_id
              LEFT JOIN users          usr ON usr.user_id = rd.submitted_by
             WHERE rd.status IN ('approved','rejected','waived')
               " . ($smil_doc_has_reviewed_at ? "AND rd.reviewed_at >= '$thirty_days_ago'" : "") . "
        ";
    }

    if ($smil_has_reports) {
        $union_parts[] = "
            SELECT 'rep' AS kind,
                   rr.report_id AS row_id,
                   rr.project_id,
                   rp.title AS project_title,
                   rr.report_type AS mtype,
                   rr.status,
                   rr.summary AS remarks,
                   (SELECT rd2.submitted_by
                      FROM research_documents rd2
                     WHERE rd2.document_id = rr.document_id
                     LIMIT 1) AS submitted_by,
                   rr.submitted_at,
                   u.file_path,
                   u.file_name,
                   u.original_name,
                   u.mime_type,
                   usr.first_name AS student_first,
                   usr.last_name  AS student_last,
                   usr.student_id AS student_id_no,
                   usr.email      AS student_email,
                   " . ($smil_rep_has_reviewed_at ? "rr.reviewed_at" : "NULL") . " AS reviewed_at
              FROM research_reports rr
              JOIN research_projects  rp  ON rp.project_id = rr.project_id" . $rp_deleted_filter_aliased . "
              LEFT JOIN research_documents rd_link ON rd_link.document_id = rr.document_id
              LEFT JOIN uploads        u  ON u.upload_id  = rd_link.upload_id
              LEFT JOIN users          usr ON usr.user_id = rd_link.submitted_by
             WHERE rr.status IN ('approved','rejected')
               " . ($smil_rep_has_reviewed_at ? "AND rr.reviewed_at >= '$thirty_days_ago'" : "") . "
        ";
    }

    if (!empty($union_parts)) {
        $sql = implode(' UNION ALL ', $union_parts) . " ORDER BY reviewed_at DESC, row_id DESC LIMIT 100";
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $res->close();
        }
    }
}

renderStaffShell(
    $user,
    'staff-milestones.php',
    'Milestone Verification',
    'Review and approve milestone documents (MOU, NDA, Progress, Terminal) submitted by students.'
);
?>

<style>
  .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
  .alert-warn    { background: #FEF3C7; color: #92400e; border: 1px solid #FDE68A; }

  .stat-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 32px;
  }
  .stat-card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .stat-card .icon { font-size: 36px; }
  .stat-card .num  { text-align: center; font-size: 32px; font-weight: 700; line-height: 1; color: #111827; }
  .stat-card .lbl  { font-size: 13px; color: #64748B; margin-top: 4px; font-weight: 500; }
  .stat-card.approved .icon { color: #16A34A; }
  .stat-card.rejected .icon { color: #EF4444; }

  /* Filter tabs */
  .filter-bar {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    background: #F1F5F9;
    padding: 4px;
    border-radius: 12px;
    width: fit-content;
  }
  .filter-tab {
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #64748B;
    text-decoration: none;
    transition: all 0.2s;
  }
  .filter-tab:hover { color: #111827; }
  .filter-tab.active {
    background: #FFFFFF;
    color: #111827;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
  }
  .filter-tab .count {
    display: inline-block;
    margin-left: 6px;
    background: #E5E7EB;
    color: #111827;
    font-size: 11px;
    font-weight: 700;
    padding: 1px 8px;
    border-radius: 9999px;
  }
  .filter-tab.active .count { background: #0d9488; color: white; }

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

  .badge-kind {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: rgba(13,148,136,0.10);
    color: #0d9488;
    border: 1px solid rgba(13,148,136,0.20);
  }
  .badge-kind.rep {
    background: rgba(91,30,188,0.10);
    color: #5B1EBC;
    border-color: rgba(91,30,188,0.20);
  }

  .file-link {
    color: #0d9488;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
  }
  .file-link:hover { text-decoration: underline; }
  .file-missing { color: #94A3B8; font-size: 13px; font-style: italic; }

  .row-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-family: inherit;
  }
  .btn:disabled,
  .btn.disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }
  .btn-primary   { background: #0d9488; color: white; }
  .btn-primary:hover:not(:disabled) { background: #059669; }
  .btn-secondary { background: #FFFFFF; color: #111827; border: 1px solid #E5E7EB; }
  .btn-secondary:hover:not(:disabled) { background: #F1F5F9; }
  .btn-danger    { background: #EF4444; color: white; }
  .btn-danger:hover:not(:disabled) { background: #B91C1C; }
  .btn-warn      { background: #F59E0B; color: white; }
  .btn-warn:hover:not(:disabled) { background: #B45309; }
  .btn-sm        { padding: 6px 12px; font-size: 12px; }

  .remarks {
    font-size: 12px;
    color: #64748B;
    font-style: italic;
    margin-top: 4px;
    max-width: 320px;
    word-break: break-word;
  }

  .empty-state {
    text-align: center;
    padding: 64px 24px;
    color: #94A3B8;
  }
  .empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.6; }
  .empty-state p    { font-size: 14px; }

  .processed-meta {
    font-size: 11px;
    color: #94A3B8;
    margin-top: 4px;
  }

  /* Modal (reused style) */
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
    max-width: 640px;
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
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
  .modal-body   { padding: 20px 24px; overflow-y: auto; }
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
    resize: vertical;
  }
  .form-control:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13,148,136,0.15);
  }
  .form-control.invalid { border-color: #EF4444; }

  @media (max-width: 768px) {
    .stat-row { grid-template-columns: 1fr; }
    td, th { padding: 12px; font-size: 13px; }
  }
</style>

<?php if (isset($_SESSION['module_success'])): ?>
  <div class="alert alert-success">✓ <?php echo smil_se($_SESSION['module_success']); unset($_SESSION['module_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['module_error'])): ?>
  <div class="alert alert-error">✕ <?php echo smil_se($_SESSION['module_error']); unset($_SESSION['module_error']); ?></div>
<?php endif; ?>

<?php if (!$smil_has_documents && !$smil_has_reports): ?>
  <div class="alert alert-warn">
    ⚠️ Neither <code>research_documents</code> nor <code>research_reports</code> exist in this database. Please run the latest migration (<code>database/migrations/rms_db_migration.sql</code>).
  </div>
<?php endif; ?>

<!-- Stat row -->
<div class="stat-row">
  <div class="stat-card">
    <div class="icon">📋</div>
    <div>
      <div class="num"><?php echo smil_se($stat_pending); ?></div>
      <div class="lbl">Pending Verification</div>
    </div>
  </div>
  <div class="stat-card approved">
    <div class="icon">✅</div>
    <div>
      <div class="num"><?php echo smil_se($stat_approved); ?></div>
      <div class="lbl">Approved This Month</div>
    </div>
  </div>
  <div class="stat-card rejected">
    <div class="icon">✕</div>
    <div>
      <div class="num"><?php echo smil_se($stat_rejected); ?></div>
      <div class="lbl">Rejected This Month</div>
    </div>
  </div>
</div>

<!-- Filter tabs -->
<div class="filter-bar">
  <a class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>"
     href="<?php echo SITE_URL; ?>pages/staff/staff-milestones.php?filter=pending">
    Pending
    <?php if ($stat_pending > 0): ?>
      <span class="count"><?php echo smil_se($stat_pending); ?></span>
    <?php endif; ?>
  </a>
  <a class="filter-tab <?php echo $filter === 'processed' ? 'active' : ''; ?>"
     href="<?php echo SITE_URL; ?>pages/staff/staff-milestones.php?filter=processed">
    Recently Processed
    <span class="count">30d</span>
  </a>
</div>

<!-- Queue -->
<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-state-icon">📭</div>
      <?php if ($filter === 'pending'): ?>
        <p>No milestones are pending verification.</p>
        <p style="font-size: 13px; margin-top: 8px; color: #94A3B8;">
          When a student submits an MOU, NDA, midway progress, or terminal report, it will appear here.
        </p>
      <?php else: ?>
        <p>No recently processed milestones in the last 30 days.</p>
        <p style="font-size: 13px; margin-top: 8px; color: #94A3B8;">
          Approve, reject, or waive a milestone and it will show up under this tab.
        </p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 200px;">Milestone</th>
            <th>Research Title</th>
            <th>Student</th>
            <th>File</th>
            <th>Submitted</th>
            <th>Status</th>
            <th style="min-width: 260px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $kind       = (string) ($row['kind'] ?? 'doc');
            $mtype      = (string) ($row['mtype'] ?? '');
            $status     = strtolower((string) ($row['status'] ?? 'submitted'));
            $label      = smil_milestone_label($kind === 'doc' ? 'doc' : 'rep', $mtype);
            $row_id     = (int) ($row['row_id'] ?? 0);
            $proj_id    = (int) ($row['project_id'] ?? 0);

            $student_name = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? '')) ?: '—';
            $student_id_v = (string) ($row['student_id_no'] ?? '');
            $student_mail = (string) ($row['student_email'] ?? '');

            $remarks    = trim((string) ($row['remarks'] ?? ''));

            $file_url   = !empty($row['file_path']) ? SITE_URL . $row['file_path'] : '';
            $file_name  = (string) ($row['original_name'] ?? ($row['file_name'] ?? 'file'));

            $submitted_at = $row['submitted_at'] ?? null;
            $reviewed_at  = $row['reviewed_at']  ?? null;

            if ($kind === 'doc') {
                [$b_class, $b_label] = smil_doc_statusBadge($status);
            } else {
                [$b_class, $b_label] = smil_rep_statusBadge($status);
            }

            $is_pending = ($status === 'submitted');
          ?>
            <tr>
              <td>
                <span class="badge-kind <?php echo $kind === 'rep' ? 'rep' : ''; ?>">
                  <?php echo $kind === 'doc' ? '📄 Document' : '📊 Report'; ?>
                </span>
                <div style="margin-top: 6px; font-weight: 600; color: #111827; font-size: 14px;">
                  <?php echo smil_se($label); ?>
                </div>
              </td>
              <td style="max-width: 280px;">
                <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $proj_id; ?>"
                   style="color: #111827; font-weight: 600; text-decoration: none;">
                  <?php echo smil_se($row['project_title'] ?? 'Untitled project'); ?>
                </a>
                <?php if ($remarks !== ''): ?>
                  <div class="remarks">"<?php echo smil_se(mb_substr($remarks, 0, 140) . (mb_strlen($remarks) > 140 ? '…' : '')); ?>"</div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight: 500; color: #111827;"><?php echo smil_se($student_name); ?></div>
                <?php if ($student_id_v !== ''): ?>
                  <div style="font-size: 12px; color: #64748B;">🎒 <?php echo smil_se($student_id_v); ?></div>
                <?php elseif ($student_mail !== ''): ?>
                  <div style="font-size: 12px; color: #64748B;"><?php echo smil_se($student_mail); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($file_url !== ''): ?>
                  <a class="file-link" href="<?php echo smil_se($file_url); ?>" target="_blank" rel="noopener">
                    📎 <?php echo smil_se(mb_substr($file_name, 0, 28) . (mb_strlen($file_name) > 28 ? '…' : '')); ?>
                  </a>
                <?php else: ?>
                  <span class="file-missing">no file</span>
                <?php endif; ?>
              </td>
              <td style="font-size: 13px; color: #64748B; white-space: nowrap;">
                <?php echo smil_se(smil_relative_time($submitted_at)); ?>
                <?php if (!empty($submitted_at)): ?>
                  <div style="font-size: 11px; color: #94A3B8;"><?php echo smil_se(date('M d, Y', strtotime((string) $submitted_at))); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-status <?php echo smil_se($b_class); ?>"><?php echo smil_se($b_label); ?></span>
                <?php if (!empty($reviewed_at)): ?>
                  <div class="processed-meta">reviewed <?php echo smil_se(smil_relative_time($reviewed_at)); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($is_pending): ?>
                  <div class="row-actions">
                    <form method="POST" style="display: inline;">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="<?php echo $kind === 'doc' ? 'approve_doc' : 'approve_rep'; ?>">
                      <input type="hidden" name="kind" value="<?php echo smil_se($kind); ?>">
                      <input type="hidden" name="row_id" value="<?php echo (int) $row_id; ?>">
                      <button type="submit" class="btn btn-primary btn-sm" title="Approve this milestone">✓ Approve</button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm"
                            onclick="openRejectModal(<?php echo (int) $row_id; ?>, '<?php echo smil_se(addslashes($label)); ?>', '<?php echo smil_se($kind); ?>')">
                      ✕ Reject
                    </button>
                    <?php if ($kind === 'doc'): ?>
                      <button type="button" class="btn btn-warn btn-sm"
                              onclick="openWaiveModal(<?php echo (int) $row_id; ?>, '<?php echo smil_se(addslashes($label)); ?>', '<?php echo smil_se($kind); ?>')">
                        ⤴ Waive
                      </button>
                    <?php else: ?>
                      <form method="POST" style="display: inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="mark_review">
                        <input type="hidden" name="kind" value="rep">
                        <input type="hidden" name="row_id" value="<?php echo (int) $row_id; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" title="Mark as under review (no decision yet)">👀 Under Review</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span style="font-size: 12px; color: #94A3B8;">No further action</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Reject modal (used for both docs and reports) -->
<div id="rejectModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
  <div class="modal-content">
    <form method="POST" id="rejectForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" id="reject_action" value="reject_doc">
      <input type="hidden" name="kind"    id="reject_kind"   value="doc">
      <input type="hidden" name="row_id"  id="reject_row_id" value="">

      <div class="modal-header">
        <h3 id="rejectModalTitle">✕ Reject Milestone</h3>
        <button type="button" class="modal-close" onclick="closeRejectModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Rejecting: <strong id="reject_label" style="color: #111827;"></strong>
        </p>
        <div class="form-group">
          <label class="form-label" for="reject_reason">Reason <span style="color: #EF4444;">*</span></label>
          <textarea id="reject_reason" name="reason" class="form-control" rows="4" minlength="10" required
                    placeholder="Explain what needs to be fixed or why this is being rejected (min. 10 characters)…"></textarea>
          <span class="form-help">This message will be sent to the student as a notification.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">Reject</button>
      </div>
    </form>
  </div>
</div>

<!-- Waive modal (documents only) -->
<div id="waiveModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="waiveModalTitle">
  <div class="modal-content">
    <form method="POST" id="waiveForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="waive_doc">
      <input type="hidden" name="kind"   value="doc">
      <input type="hidden" name="row_id" id="waive_row_id" value="">

      <div class="modal-header">
        <h3 id="waiveModalTitle">⤴ Waive Milestone</h3>
        <button type="button" class="modal-close" onclick="closeWaiveModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Waiving: <strong id="waive_label" style="color: #111827;"></strong>
        </p>
        <p style="margin: 0; font-size: 13px; color: #64748B;">
          Waiving marks this milestone as not required for the project. The student will be notified.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeWaiveModal()">Cancel</button>
        <button type="submit" class="btn btn-warn">Waive Requirement</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Reject modal
  const rejectModal = document.getElementById('rejectModal');
  const rejectForm  = document.getElementById('rejectForm');
  const rejectLabel = document.getElementById('reject_label');
  const rejectKind  = document.getElementById('reject_kind');
  const rejectRow   = document.getElementById('reject_row_id');
  const rejectAct   = document.getElementById('reject_action');
  const rejectField = document.getElementById('reject_reason');

  function openRejectModal(rowId, label, kind) {
    rejectRow.value         = rowId;
    rejectLabel.textContent = label;
    rejectKind.value        = kind;
    rejectAct.value         = (kind === 'doc') ? 'reject_doc' : 'reject_rep';
    rejectField.value       = '';
    rejectField.classList.remove('invalid');
    rejectModal.style.display = 'flex';
    setTimeout(() => rejectField.focus(), 50);
  }
  function closeRejectModal() {
    rejectModal.style.display = 'none';
  }

  // Waive modal
  const waiveModal = document.getElementById('waiveModal');
  const waiveLabel = document.getElementById('waive_label');
  const waiveRow   = document.getElementById('waive_row_id');

  function openWaiveModal(rowId, label, kind) {
    waiveRow.value         = rowId;
    waiveLabel.textContent = label;
    waiveModal.style.display = 'flex';
  }
  function closeWaiveModal() {
    waiveModal.style.display = 'none';
  }

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (rejectModal.style.display === 'flex') closeRejectModal();
      if (waiveModal.style.display === 'flex')  closeWaiveModal();
    }
  });

  // Close on backdrop click
  [rejectModal, waiveModal].forEach((m) => {
    m.addEventListener('click', (e) => {
      if (e.target === m) {
        if (m === rejectModal) closeRejectModal();
        else if (m === waiveModal) closeWaiveModal();
      }
    });
  });

  // Client-side guard: minimum 10 chars on reject reason
  rejectForm.addEventListener('submit', (e) => {
    const v = rejectField.value.trim();
    if (v.length < 10) {
      e.preventDefault();
      rejectField.classList.add('invalid');
      rejectField.focus();
    }
  });
  rejectField.addEventListener('input', () => rejectField.classList.remove('invalid'));
</script>

<?php renderStaffShellClose(); ?>
