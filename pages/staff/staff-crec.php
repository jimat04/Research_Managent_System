<?php
/**
 * Staff — CREC Review
 *
 * Research Staff manages the College Research Evaluation Committee (CREC) workflow
 * for proposals that have been forwarded from the Submissions Inbox.
 *
 *   - Assign / unassign faculty reviewers (users.role='faculty' AND is_reviewer=1)
 *   - Endorse to EREC    → status: under_crec_review → under_erec_review
 *                          (requires >= 2 completed reviews AND avg score >= 50/80)
 *   - Return for revision → status: under_crec_review → for_revision (reason required)
 *   - Reject              → status: under_crec_review → rejected (reason required)
 *
 * Reviewer scores (OVPREIS Form No. 3) live in `project_reviews` and are entered
 * by the assigned faculty via the Faculty Review UI; this page only manages
 * assignments and disposition.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole('research_staff');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── helpers ────────────────────────────────────────────────────────────────
function se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Status badge helper (mirrors staff-submissions)
function crec_statusBadge(string $status): array {
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
        'rejected'          => ['status-pending',  'Rejected'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

// ── runtime schema detection ──────────────────────────────────────────────
// research_projects.deleted_at is added by the migration but not in the base dump.
// users.is_reviewer was added in migration 002. project_reviews is added by
// migration 006. Detect each so the page degrades gracefully on older schemas.
$rp_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$rp_has_deleted_at = false;
if ($rp_deleted_column_stmt) {
    $rp_deleted_column_stmt->execute();
    $rp_has_deleted_at = $rp_deleted_column_stmt->get_result()->num_rows > 0;
    $rp_deleted_column_stmt->close();
}
$rp_deleted_filter       = $rp_has_deleted_at ? ' AND deleted_at IS NULL'         : '';
$rp_deleted_filter_aliased = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL'     : '';

$user_reviewer_column_stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_reviewer'");
$users_has_is_reviewer = false;
if ($user_reviewer_column_stmt) {
    $user_reviewer_column_stmt->execute();
    $users_has_is_reviewer = $user_reviewer_column_stmt->get_result()->num_rows > 0;
    $user_reviewer_column_stmt->close();
}

// Does project_reviews exist? If not, try to create it (safe no-op if it does).
$table_check_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
$project_reviews_exists = false;
if ($table_check_stmt) {
    $table_check_stmt->execute();
    $table_check_stmt->bind_result($tbl);
    while ($table_check_stmt->fetch()) {
        $project_reviews_exists = ($tbl === 'project_reviews');
    }
    $table_check_stmt->close();
}
if (!$project_reviews_exists) {
    $create_sql = "
        CREATE TABLE IF NOT EXISTS project_reviews (
          review_id            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          project_id           INT(10) UNSIGNED NOT NULL,
          reviewer_id          INT(10) UNSIGNED NOT NULL,
          review_level         ENUM('crec','erec') NOT NULL DEFAULT 'crec',
          methodology_score    TINYINT UNSIGNED DEFAULT NULL,
          contribution_score   TINYINT UNSIGNED DEFAULT NULL,
          applicability_score  TINYINT UNSIGNED DEFAULT NULL,
          agenda_score         TINYINT UNSIGNED DEFAULT NULL,
          comments             TEXT NULL,
          recommendation       ENUM('approve','revise','reject') DEFAULT NULL,
          reviewed_at          DATETIME NULL,
          created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (review_id),
          UNIQUE KEY uk_project_reviewer_level (project_id, reviewer_id, review_level),
          KEY idx_project (project_id),
          KEY idx_reviewer (reviewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    @$conn->query($create_sql);
    // Re-check
    $recheck_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
    if ($recheck_stmt) {
        $recheck_stmt->execute();
        $recheck_stmt->bind_result($tbl);
        while ($recheck_stmt->fetch()) {
            $project_reviews_exists = ($tbl === 'project_reviews');
        }
        $recheck_stmt->close();
    }
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
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
                . $rp_deleted_filter_aliased . "
                LIMIT 1
            ");
            $info_stmt->bind_param('i', $project_id);
            $info_stmt->execute();
            $project = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            if (!$project) {
                $_SESSION['module_error'] = 'Project not found.';
            } else {
                $student_id = (int) ($project['created_by'] ?? 0);
                $title      = (string) ($project['title'] ?? 'your research');
                $short      = mb_substr($title, 0, 60) . (mb_strlen($title) > 60 ? '…' : '');

                if ($action === 'assign_reviewer') {
                    if (!$project_reviews_exists) {
                        $_SESSION['module_error'] = 'Reviewer system is not yet available. Please run database migration 006_create_project_reviews.sql.';
                    } else {
                        $reviewer_id = (int) ($_POST['reviewer_id'] ?? 0);
                        if ($reviewer_id <= 0) {
                            $_SESSION['module_error'] = 'Please pick a faculty reviewer to assign.';
                        } else {
                            // Validate reviewer (must be faculty + active + is_reviewer=1)
                            $reviewer_filter = $users_has_is_reviewer
                                ? " AND is_reviewer = 1"
                                : "";
                            $r_stmt = $conn->prepare("
                                SELECT user_id, first_name, last_name
                                  FROM users
                                 WHERE user_id = ?
                                   AND role = 'faculty'
                                   AND status = 'active'"
                                   . $reviewer_filter . "
                                 LIMIT 1
                            ");
                            $r_stmt->bind_param('i', $reviewer_id);
                            $r_stmt->execute();
                            $reviewer = $r_stmt->get_result()->fetch_assoc();
                            $r_stmt->close();

                            if (!$reviewer) {
                                $_SESSION['module_error'] = 'Selected user is not an active faculty reviewer.';
                            } else {
                                // Prevent duplicate assignment
                                $ins = $conn->prepare("
                                    INSERT INTO project_reviews
                                        (project_id, reviewer_id, review_level, created_at, updated_at)
                                    VALUES (?, ?, 'crec', NOW(), NOW())
                                    ON DUPLICATE KEY UPDATE updated_at = NOW()
                                ");
                                $ins->bind_param('ii', $project_id, $reviewer_id);
                                $ins->execute();
                                $affected = $ins->affected_rows;
                                $ins->close();

                                // affected_rows: 1 = inserted, 2 = updated on duplicate key
                                if ($affected > 0) {
                                    $rev_name = trim(($reviewer['first_name'] ?? '') . ' ' . ($reviewer['last_name'] ?? '')) ?: ('Reviewer #' . $reviewer_id);
                                    // Notify the reviewer
                                    createNotification(
                                        $reviewer_id,
                                        'New CREC review assignment',
                                        'You have been assigned to review "' . $short . '" for the College Research Ethics Committee.',
                                        'info',
                                        SITE_URL . 'pages/faculty/faculty-review.php'
                                    );
                                    logActivity(
                                        'Assigned reviewer ' . $rev_name . ' to CREC review of project #' . $project_id . ' ("' . $title . '")',
                                        'crec_review'
                                    );
                                    $_SESSION['module_success'] = 'Reviewer assigned successfully.';
                                } else {
                                    $_SESSION['module_error'] = 'Reviewer could not be assigned.';
                                }
                            }
                        }
                    }

                } elseif ($action === 'remove_reviewer') {
                    if (!$project_reviews_exists) {
                        $_SESSION['module_error'] = 'Reviewer system is not yet available.';
                    } else {
                        $review_id = (int) ($_POST['review_id'] ?? 0);
                        if ($review_id <= 0) {
                            $_SESSION['module_error'] = 'Invalid reviewer assignment reference.';
                        } else {
                            $del = $conn->prepare("
                                DELETE FROM project_reviews
                                 WHERE review_id = ?
                                   AND project_id = ?
                                   AND reviewed_at IS NULL
                            ");
                            $del->bind_param('ii', $review_id, $project_id);
                            $del->execute();
                            $affected = $conn->affected_rows;
                            $del->close();

                            if ($affected > 0) {
                                logActivity(
                                    'Removed reviewer assignment #' . $review_id . ' from CREC review of project #' . $project_id,
                                    'crec_review'
                                );
                                $_SESSION['module_success'] = 'Reviewer removed from assignment.';
                            } else {
                                $_SESSION['module_error'] = 'Only unreviewed assignments can be removed (this reviewer has already submitted a review).';
                            }
                        }
                    }

                } elseif ($action === 'crec_endorse') {
                    if (!$project_reviews_exists) {
                        $_SESSION['module_error'] = 'Reviewer system is not yet available.';
                    } else {
                        // Aggregate current reviews
                        $agg_stmt = $conn->prepare("
                            SELECT
                              COUNT(DISTINCT reviewer_id) AS assigned_count,
                              COUNT(DISTINCT CASE WHEN reviewed_at IS NOT NULL THEN reviewer_id END) AS completed_count,
                              AVG(
                                COALESCE(methodology_score,0) +
                                COALESCE(contribution_score,0) +
                                COALESCE(applicability_score,0) +
                                COALESCE(agenda_score,0)
                              ) AS avg_score
                            FROM project_reviews
                            WHERE project_id = ? AND review_level = 'crec'
                        ");
                        $agg_stmt->bind_param('i', $project_id);
                        $agg_stmt->execute();
                        $agg = $agg_stmt->get_result()->fetch_assoc();
                        $agg_stmt->close();

                        $completed = (int) ($agg['completed_count'] ?? 0);
                        $avg       = (float) ($agg['avg_score'] ?? 0);

                        if ($completed < 2) {
                            $_SESSION['module_error'] = 'Cannot endorse: at least 2 completed reviews are required (currently ' . $completed . ').';
                        } elseif ($avg < 50.0) {
                            $_SESSION['module_error'] = 'Cannot endorse: average score is ' . number_format($avg, 1) . '/80, below the 50-point threshold.';
                        } else {
                            $upd = $conn->prepare("
                                UPDATE research_projects
                                   SET status = 'under_erec_review', updated_at = NOW()
                                 WHERE project_id = ?
                                   AND status = 'under_crec_review'"
                                   . $rp_deleted_filter
                            );
                            $upd->bind_param('i', $project_id);
                            $upd->execute();
                            $affected = $conn->affected_rows;
                            $upd->close();

                            if ($affected > 0) {
                                createNotification(
                                    $student_id,
                                    'Proposal endorsed to EREC',
                                    'Your proposal "' . $short . '" has been endorsed by CREC (avg score ' . number_format($avg, 1) . '/80) and forwarded to the Ethics Review Committee (EREC).',
                                    'success',
                                    SITE_URL . 'pages/student/my-research.php'
                                );
                                // Notify all admins
                                $admins = $conn->query("SELECT user_id FROM users WHERE role='admin' AND status='active'");
                                if ($admins) {
                                    while ($a = $admins->fetch_assoc()) {
                                        createNotification(
                                            (int) $a['user_id'],
                                            'Project endorsed to EREC',
                                            'Project #' . $project_id . ' ("' . $short . '") was endorsed by CREC with avg ' . number_format($avg, 1) . '/80.',
                                            'info',
                                            SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
                                        );
                                    }
                                }
                                logActivity(
                                    'Endorsed project #' . $project_id . ' ("' . $title . '") from CREC to EREC (avg ' . number_format($avg, 1) . '/80)',
                                    'crec_review'
                                );
                                $_SESSION['module_success'] = 'Project endorsed to EREC.';
                            } else {
                                $_SESSION['module_error'] = 'Project is no longer in CREC review (it may have already been processed).';
                            }
                        }
                    }

                } elseif ($action === 'crec_return') {
                    $reason = trim((string) ($_POST['revision_reason'] ?? ''));
                    if (mb_strlen($reason) < 20) {
                        $_SESSION['module_error'] = 'Revision reason is required (minimum 20 characters).';
                    } else {
                        $upd = $conn->prepare("
                            UPDATE research_projects
                               SET status = 'for_revision', updated_at = NOW()
                             WHERE project_id = ?
                               AND status = 'under_crec_review'"
                               . $rp_deleted_filter
                        );
                        $upd->bind_param('i', $project_id);
                        $upd->execute();
                        $affected = $conn->affected_rows;
                        $upd->close();

                        if ($affected > 0) {
                            createNotification(
                                $student_id,
                                'CREC returned proposal for revision',
                                'Your proposal "' . $short . '" was returned by CREC for revision. Reason: ' . $reason,
                                'warning',
                                SITE_URL . 'pages/student/my-research.php'
                            );
                            logActivity(
                                'CREC returned project #' . $project_id . ' ("' . $title . '") for revision: ' . $reason,
                                'crec_review'
                            );
                            $_SESSION['module_success'] = 'Project returned to the student for revision.';
                        } else {
                            $_SESSION['module_error'] = 'Project is no longer in CREC review (it may have already been processed).';
                        }
                    }

                } elseif ($action === 'crec_reject') {
                    $reason = trim((string) ($_POST['revision_reason'] ?? ''));
                    if (mb_strlen($reason) < 10) {
                        $_SESSION['module_error'] = 'Rejection reason is required (minimum 10 characters).';
                    } else {
                        $upd = $conn->prepare("
                            UPDATE research_projects
                               SET status = 'rejected', updated_at = NOW()
                             WHERE project_id = ?
                               AND status = 'under_crec_review'"
                               . $rp_deleted_filter
                        );
                        $upd->bind_param('i', $project_id);
                        $upd->execute();
                        $affected = $conn->affected_rows;
                        $upd->close();

                        if ($affected > 0) {
                            createNotification(
                                $student_id,
                                'Proposal rejected by CREC',
                                'Your proposal "' . $short . '" was rejected by CREC. Reason: ' . $reason,
                                'error',
                                SITE_URL . 'pages/student/my-research.php'
                            );
                            logActivity(
                                'CREC rejected project #' . $project_id . ' ("' . $title . '"): ' . $reason,
                                'crec_review'
                            );
                            $_SESSION['module_success'] = 'Project rejected.';
                        } else {
                            $_SESSION['module_error'] = 'Project is no longer in CREC review (it may have already been processed).';
                        }
                    }

                } else {
                    $_SESSION['module_error'] = 'Unknown action.';
                }
            }
        }
    }

    // PRG: redirect back to this page
    $redirect = SITE_URL . 'pages/staff/staff-crec.php';
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs !== '') {
        $redirect .= '?' . $qs;
    }
    header('Location: ' . $redirect);
    exit;
}

// ── count (for stat card) ───────────────────────────────────────────────
$count_result = $conn->query("
    SELECT COUNT(*) AS c
      FROM research_projects
     WHERE status = 'under_crec_review'"
     . $rp_deleted_filter
);
$total_crec = (int) ($count_result ? ($count_result->fetch_assoc()['c'] ?? 0) : 0);
if ($count_result) {
    $count_result->close();
}

// ── main list query ──────────────────────────────────────────────────────
// If project_reviews exists, aggregate reviewer stats. Otherwise fall back to
// zeroed columns so the page still renders.
if ($project_reviews_exists) {
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
            up.original_name AS proposal_original_name,
            COALESCE(stats.assigned_count, 0)  AS assigned_count,
            COALESCE(stats.completed_count, 0) AS completed_count,
            stats.avg_score                   AS avg_score
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
        LEFT JOIN (
            SELECT
                project_id,
                COUNT(DISTINCT reviewer_id) AS assigned_count,
                COUNT(DISTINCT CASE WHEN reviewed_at IS NOT NULL THEN reviewer_id END) AS completed_count,
                AVG(
                    COALESCE(methodology_score,0) +
                    COALESCE(contribution_score,0) +
                    COALESCE(applicability_score,0) +
                    COALESCE(agenda_score,0)
                ) AS avg_score
            FROM project_reviews
            WHERE review_level = 'crec'
            GROUP BY project_id
        ) stats ON stats.project_id = rp.project_id
        WHERE rp.status = 'under_crec_review'"
        . $rp_deleted_filter_aliased . "
        ORDER BY rp.created_at ASC
    ";
} else {
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
            up.original_name AS proposal_original_name,
            0 AS assigned_count,
            0 AS completed_count,
            NULL AS avg_score
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
        WHERE rp.status = 'under_crec_review'"
        . $rp_deleted_filter_aliased . "
        ORDER BY rp.created_at ASC
    ";
}

$list_result = $conn->query($list_sql);
$projects = $list_result ? $list_result->fetch_all(MYSQLI_ASSOC) : [];

// ── load reviewers per project (for the modal lists) ────────────────────
$reviewers_by_project = [];
if ($project_reviews_exists && !empty($projects)) {
    $project_ids = array_map(fn($p) => (int) $p['project_id'], $projects);
    $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
    $rv_stmt = $conn->prepare("
        SELECT pr.review_id, pr.project_id, pr.reviewer_id, pr.review_level,
               pr.methodology_score, pr.contribution_score, pr.applicability_score,
               pr.agenda_score, pr.recommendation, pr.reviewed_at,
               u.first_name, u.last_name, u.email, u.academic_rank
          FROM project_reviews pr
          JOIN users u ON u.user_id = pr.reviewer_id
         WHERE pr.project_id IN ($placeholders)
           AND pr.review_level = 'crec'
         ORDER BY u.last_name, u.first_name
    ");
    if ($rv_stmt) {
        $rv_stmt->bind_param(str_repeat('i', count($project_ids)), ...$project_ids);
        $rv_stmt->execute();
        $rv_res = $rv_stmt->get_result();
        while ($r = $rv_res->fetch_assoc()) {
            $reviewers_by_project[(int) $r['project_id']][] = $r;
        }
        $rv_stmt->close();
    }
}

// ── load available faculty reviewers (for the assign dropdown) ───────────
$available_reviewers = [];
if ($users_has_is_reviewer) {
    $avail_result = $conn->query("
        SELECT user_id, first_name, last_name, email, academic_rank, specialization
          FROM users
         WHERE role = 'faculty'
           AND status = 'active'
           AND is_reviewer = 1
         ORDER BY last_name, first_name
    ");
    if ($avail_result) {
        $available_reviewers = $avail_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Render shell
renderStaffShell($user, 'staff-crec.php', 'CREC Review', 'College Research Evaluation Committee — assign reviewers and endorse proposals.');
?>

<style>
  .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
  .alert-warn    { background: #FEF3C7; color: #92400e; border: 1px solid #FDE68A; }

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

  .review-progress {
    font-size: 13px;
    color: #111827;
    font-weight: 500;
  }
  .review-progress .avg {
    display: block;
    font-size: 12px;
    color: #64748B;
    margin-top: 2px;
  }
  .review-progress.ready {
    color: #16A34A;
  }

  .row-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  /* Buttons */
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
  .btn:disabled,
  .btn.disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }
  .btn-primary   { background: #0d9488; color: white; }
  .btn-primary:hover:not(:disabled) { background: #059669; }
  .btn-secondary { background: #FFFFFF; color: #111827; border: 1px solid #E5E7EB; }
  .btn-secondary:hover:not(:disabled) { background: #F1F5F9; }
  .btn-danger    { background: #EA580C; color: white; }
  .btn-danger:hover:not(:disabled) { background: #C2410C; }
  .btn-reject    { background: #DC2626; color: white; }
  .btn-reject:hover:not(:disabled) { background: #B91C1C; }
  .btn-sm        { padding: 6px 12px; font-size: 12px; }

  /* Empty state */
  .empty-state {
    text-align: center;
    padding: 64px 24px;
    color: #94A3B8;
  }
  .empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.6; }
  .empty-state p    { font-size: 14px; }

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
  }
  .form-control:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13,148,136,0.15);
  }
  .form-control.invalid { border-color: #EF4444; }

  /* Reviewer list inside modal */
  .reviewer-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
  }
  .reviewer-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    background: #F8FAFC;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
  }
  .reviewer-row.reviewed {
    background: #DCFCE7;
    border-color: #BBF7D0;
  }
  .reviewer-row .meta { font-size: 12px; color: #64748B; margin-top: 2px; }
  .reviewer-row .remove-btn {
    background: #FEE2E2;
    color: #B91C1C;
    border: none;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .reviewer-row .remove-btn:hover { background: #FECACA; }
  .reviewer-row .reviewed-tag {
    font-size: 11px;
    font-weight: 700;
    color: #15803d;
    background: #DCFCE7;
    padding: 3px 8px;
    border-radius: 9999px;
  }

  .score-pill {
    display: inline-block;
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 12px;
    font-weight: 600;
    color: #111827;
    margin-left: 6px;
  }

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

<?php if (!$project_reviews_exists): ?>
  <div class="alert alert-warn">
    ⚠️ The <code>project_reviews</code> table is missing. Attempted to create it automatically — please verify or run <code>database/migrations/006_create_project_reviews.sql</code> manually.
  </div>
<?php endif; ?>

<!-- Stat summary -->
<div class="stat-card">
  <div class="icon">🏛️</div>
  <div>
    <div class="num"><?php echo se($total_crec); ?></div>
    <div class="lbl">Proposal<?php echo $total_crec !== 1 ? 's' : ''; ?> awaiting CREC review</div>
  </div>
</div>

<!-- CREC projects table -->
<div class="card">
  <?php if (empty($projects)): ?>
    <div class="empty-state">
      <div class="empty-state-icon">🏛️</div>
      <p>No proposals currently in CREC review.</p>
      <p style="font-size: 13px; margin-top: 8px; color: #94A3B8;">
        When staff forwards a submission from the Inbox, it will appear here.
      </p>
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
            <th>Forwarded</th>
            <th>Proposal</th>
            <th>Review Progress</th>
            <th>Status</th>
            <th style="min-width: 280px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $i => $row):
            $idx          = $i + 1;
            [$b_class, $b_label] = crec_statusBadge($row['status'] ?? 'under_crec_review');

            $student_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
            $student_id_v = $row['student_id'] ?? '';
            $cat_name     = $row['category_name'] ?? '—';
            $fwd_date     = !empty($row['created_at'])
                ? date('M d, Y • h:i A', strtotime($row['created_at']))
                : '—';

            $proposal_url  = !empty($row['proposal_file_path'])
                ? SITE_URL . $row['proposal_file_path']
                : '';
            $proposal_name = $row['proposal_original_name'] ?? 'proposal file';

            $assigned  = (int) ($row['assigned_count'] ?? 0);
            $completed = (int) ($row['completed_count'] ?? 0);
            $avg       = $row['avg_score'] !== null ? (float) $row['avg_score'] : null;

            $can_endorse = ($completed >= 2) && ($avg !== null) && ($avg >= 50.0);
            $endorse_title = '';
            if ($completed < 2) {
                $endorse_title = 'Needs at least 2 completed reviews (currently ' . $completed . ')';
            } elseif ($avg === null || $avg < 50.0) {
                $endorse_title = 'Average score is below the 50/80 threshold';
            } else {
                $endorse_title = 'Forward this proposal to the Ethics Review Committee (EREC)';
            }

            $assigned_reviewers = $reviewers_by_project[(int) $row['project_id']] ?? [];
            $already_assigned_ids = array_map(fn($r) => (int) $r['reviewer_id'], $assigned_reviewers);
            $available_to_add = array_filter($available_reviewers, function ($r) use ($already_assigned_ids) {
                return !in_array((int) $r['user_id'], $already_assigned_ids, true);
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
              <td style="font-size: 13px; color: #64748B; white-space: nowrap;"><?php echo se($fwd_date); ?></td>
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
                <?php if ($project_reviews_exists): ?>
                  <div class="review-progress <?php echo $can_endorse ? 'ready' : ''; ?>">
                    <?php echo se($completed . '/' . $assigned); ?> reviewer<?php echo $assigned !== 1 ? 's' : ''; ?>
                    <?php if ($avg !== null): ?>
                      <span class="avg">avg <?php echo se(number_format($avg, 1)); ?>/80</span>
                    <?php else: ?>
                      <span class="avg">no scores yet</span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span style="color: #94A3B8; font-size: 13px;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-status <?php echo se($b_class); ?>"><?php echo se($b_label); ?></span>
              </td>
              <td>
                <div class="row-actions">
                  <button type="button" class="btn btn-secondary btn-sm"
                          onclick="openReviewersModal(<?php echo (int) $row['project_id']; ?>, '<?php echo se(addslashes($row['title'])); ?>')">
                    👥 Reviewers
                  </button>
                  <?php if ($can_endorse): ?>
                    <form method="POST" style="display: inline;">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="crec_endorse">
                      <input type="hidden" name="project_id" value="<?php echo (int) $row['project_id']; ?>">
                      <button type="submit" class="btn btn-primary btn-sm" title="<?php echo se($endorse_title); ?>">
                        ✓ Endorse to EREC
                      </button>
                    </form>
                  <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm disabled"
                            title="<?php echo se($endorse_title); ?>" disabled>
                      ✓ Endorse to EREC
                    </button>
                  <?php endif; ?>
                  <button type="button" class="btn btn-danger btn-sm"
                          onclick="openReturnModal(<?php echo (int) $row['project_id']; ?>, '<?php echo se(addslashes($row['title'])); ?>')">
                    ↩ Return
                  </button>
                  <button type="button" class="btn btn-reject btn-sm"
                          onclick="openRejectModal(<?php echo (int) $row['project_id']; ?>, '<?php echo se(addslashes($row['title'])); ?>')">
                    ✕ Reject
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Manage Reviewers modal -->
<div id="reviewersModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="reviewersModalTitle">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="reviewersModalTitle">👥 Manage Reviewers</h3>
      <button type="button" class="modal-close" onclick="closeReviewersModal()" aria-label="Close">×</button>
    </div>
    <div class="modal-body">
      <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
        Project: <strong id="rv_title" style="color: #111827;"></strong>
      </p>

      <div class="form-group">
        <label class="form-label">Current Reviewers</label>
        <div id="rv_list" class="reviewer-list">
          <!-- populated by JS -->
        </div>
      </div>

      <form method="POST" id="assignForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="assign_reviewer">
        <input type="hidden" name="project_id" id="rv_project_id" value="">
        <div class="form-group">
          <label class="form-label" for="reviewer_id">Assign another faculty reviewer</label>
          <select id="reviewer_id" name="reviewer_id" class="form-control" required>
            <option value="">— Choose reviewer —</option>
            <!-- populated by JS -->
          </select>
          <span class="form-help">Only faculty with the <em>is_reviewer</em> flag enabled appear here.</span>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 8px;">
          <button type="button" class="btn btn-secondary" onclick="closeReviewersModal()">Done</button>
          <button type="submit" class="btn btn-primary" id="rv_assign_btn">Assign Reviewer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Return-for-revision modal -->
<div id="returnModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
  <div class="modal-content">
    <form method="POST" id="returnForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="crec_return">
      <input type="hidden" name="project_id" id="return_project_id" value="">

      <div class="modal-header">
        <h3 id="returnModalTitle">↩ Return for Revision (CREC)</h3>
        <button type="button" class="modal-close" onclick="closeReturnModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Returning: <strong id="return_title" style="color: #111827;"></strong>
        </p>
        <div class="form-group">
          <label class="form-label" for="revision_reason">Reason <span style="color: #EF4444;">*</span></label>
          <textarea id="revision_reason" name="revision_reason" class="form-control" rows="5" minlength="20" required
                    placeholder="Explain what the student needs to fix (min. 20 characters)…"></textarea>
          <span class="form-help">This message will be sent to the student as a notification. CREC returns require more detail than initial verification returns.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">Return to Student</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject modal -->
<div id="rejectModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
  <div class="modal-content">
    <form method="POST" id="rejectForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="crec_reject">
      <input type="hidden" name="project_id" id="reject_project_id" value="">

      <div class="modal-header">
        <h3 id="rejectModalTitle">✕ Reject Proposal</h3>
        <button type="button" class="modal-close" onclick="closeRejectModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Rejecting: <strong id="reject_title" style="color: #111827;"></strong>
        </p>
        <div class="form-group">
          <label class="form-label" for="reject_reason">Reason <span style="color: #EF4444;">*</span></label>
          <textarea id="reject_reason" name="revision_reason" class="form-control" rows="4" minlength="10" required
                    placeholder="Explain why this proposal is being rejected (min. 10 characters)…"></textarea>
          <span class="form-help">This message will be sent to the student as a notification.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-reject">Reject Proposal</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Server-rendered data passed to JS
  const reviewersByProject = <?php
    $js_data = [];
    foreach ($reviewers_by_project as $pid => $rs) {
        $js_data[$pid] = array_map(function ($r) {
            return [
                'review_id'     => (int) $r['review_id'],
                'reviewer_id'   => (int) $r['reviewer_id'],
                'name'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'email'         => $r['email'] ?? '',
                'rank'          => $r['academic_rank'] ?? '',
                'reviewed'      => !empty($r['reviewed_at']),
                'reviewed_at'   => $r['reviewed_at'] ?? null,
                'm_score'       => $r['methodology_score'] !== null ? (int) $r['methodology_score'] : null,
                'c_score'       => $r['contribution_score'] !== null ? (int) $r['contribution_score'] : null,
                'a_score'       => $r['applicability_score'] !== null ? (int) $r['applicability_score'] : null,
                'g_score'       => $r['agenda_score'] !== null ? (int) $r['agenda_score'] : null,
                'recommendation'=> $r['recommendation'] ?? null,
            ];
        }, $rs);
    }
    echo json_encode($js_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ?>;

  const availableReviewers = <?php
    $js_avail = array_map(function ($r) {
        return [
            'user_id'  => (int) $r['user_id'],
            'name'     => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'email'    => $r['email'] ?? '',
            'rank'     => $r['academic_rank'] ?? '',
            'specialization' => $r['specialization'] ?? '',
        ];
    }, $available_reviewers);
    echo json_encode($js_avail, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ?>;

  // ── Reviewers modal ───────────────────────────────────────────────
  const rvModal      = document.getElementById('reviewersModal');
  const rvTitle      = document.getElementById('rv_title');
  const rvProjectId  = document.getElementById('rv_project_id');
  const rvList       = document.getElementById('rv_list');
  const rvSelect     = document.getElementById('reviewer_id');
  const assignForm   = document.getElementById('assignForm');

  function openReviewersModal(projectId, title) {
    rvTitle.textContent = title;
    rvProjectId.value   = projectId;
    renderReviewerList(projectId);
    renderAssignOptions(projectId);
    rvModal.style.display = 'flex';
  }

  function closeReviewersModal() {
    rvModal.style.display = 'none';
    assignForm.reset();
  }

  function renderReviewerList(projectId) {
    const reviewers = reviewersByProject[projectId] || [];
    rvList.innerHTML = '';

    if (reviewers.length === 0) {
      const empty = document.createElement('div');
      empty.style.cssText = 'font-size: 13px; color: #94A3B8; padding: 12px; text-align: center;';
      empty.textContent = 'No reviewers assigned yet.';
      rvList.appendChild(empty);
      return;
    }

    reviewers.forEach((r) => {
      const row = document.createElement('div');
      row.className = 'reviewer-row' + (r.reviewed ? ' reviewed' : '');

      const left = document.createElement('div');
      left.style.flex = '1';
      const nameLine = document.createElement('div');
      nameLine.style.fontWeight = '600';
      nameLine.style.color = '#111827';
      nameLine.textContent = r.name || ('Reviewer #' + r.reviewer_id);
      left.appendChild(nameLine);

      const meta = document.createElement('div');
      meta.className = 'meta';
      const parts = [];
      if (r.rank) parts.push(r.rank);
      if (r.email) parts.push(r.email);
      meta.textContent = parts.join(' · ');
      left.appendChild(meta);

      if (r.reviewed) {
        const score = (r.m_score || 0) + (r.c_score || 0) + (r.a_score || 0) + (r.g_score || 0);
        const total = (r.m_score !== null ? r.m_score : '?') + '/20 · '
                    + (r.c_score !== null ? r.c_score : '?') + '/20 · '
                    + (r.a_score !== null ? r.a_score : '?') + '/30 · '
                    + (r.g_score !== null ? r.g_score : '?') + '/10';
        const scoreLine = document.createElement('div');
        scoreLine.className = 'meta';
        scoreLine.innerHTML = '✅ Reviewed · <span class="score-pill">' + score + '/80</span> '
          + '<span style="color: #94A3B8;">(' + total + ')</span>';
        if (r.recommendation) {
          const rec = document.createElement('span');
          rec.className = 'score-pill';
          rec.textContent = r.recommendation;
          scoreLine.appendChild(rec);
        }
        left.appendChild(scoreLine);
      }

      row.appendChild(left);

      if (!r.reviewed) {
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-btn';
        removeBtn.title = 'Remove this reviewer';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', () => removeReviewer(r.review_id, projectId));
        row.appendChild(removeBtn);
      } else {
        const tag = document.createElement('span');
        tag.className = 'reviewed-tag';
        tag.textContent = '✓ Reviewed';
        row.appendChild(tag);
      }

      rvList.appendChild(row);
    });
  }

  function renderAssignOptions(projectId) {
    rvSelect.innerHTML = '<option value="">— Choose reviewer —</option>';
    const assignedIds = (reviewersByProject[projectId] || []).map(r => r.reviewer_id);
    const available = availableReviewers.filter(r => !assignedIds.includes(r.user_id));
    if (available.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.disabled = true;
      opt.textContent = 'All eligible reviewers already assigned';
      rvSelect.appendChild(opt);
      document.getElementById('rv_assign_btn').disabled = true;
    } else {
      available.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.user_id;
        const detail = [r.rank, r.specialization].filter(Boolean).join(' · ');
        opt.textContent = r.name + (detail ? ' (' + detail + ')' : '');
        rvSelect.appendChild(opt);
      });
      document.getElementById('rv_assign_btn').disabled = false;
    }
  }

  function removeReviewer(reviewId, projectId) {
    if (!confirm('Remove this reviewer from the assignment? They will no longer be able to submit a review.')) {
      return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    form.innerHTML =
      '<input type="hidden" name="csrf_token" value="<?php echo se(csrfToken()); ?>">' +
      '<input type="hidden" name="action" value="remove_reviewer">' +
      '<input type="hidden" name="project_id" value="' + projectId + '">' +
      '<input type="hidden" name="review_id" value="' + reviewId + '">';
    document.body.appendChild(form);
    form.submit();
  }

  // ── Return modal ──────────────────────────────────────────────────
  const returnModal = document.getElementById('returnModal');
  const returnForm  = document.getElementById('returnForm');
  const returnTitle = document.getElementById('return_title');
  const returnPid   = document.getElementById('return_project_id');
  const reasonField = document.getElementById('revision_reason');

  function openReturnModal(projectId, title) {
    returnPid.value        = projectId;
    returnTitle.textContent = title;
    reasonField.value      = '';
    reasonField.classList.remove('invalid');
    returnModal.style.display = 'flex';
    setTimeout(() => reasonField.focus(), 50);
  }
  function closeReturnModal() {
    returnModal.style.display = 'none';
  }

  // ── Reject modal ─────────────────────────────────────────────────
  const rejectModal = document.getElementById('rejectModal');
  const rejectForm  = document.getElementById('rejectForm');
  const rejectTitle = document.getElementById('reject_title');
  const rejectPid   = document.getElementById('reject_project_id');
  const rejectField = document.getElementById('reject_reason');

  function openRejectModal(projectId, title) {
    rejectPid.value          = projectId;
    rejectTitle.textContent  = title;
    rejectField.value        = '';
    rejectField.classList.remove('invalid');
    rejectModal.style.display = 'flex';
    setTimeout(() => rejectField.focus(), 50);
  }
  function closeRejectModal() {
    rejectModal.style.display = 'none';
  }

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (rvModal.style.display === 'flex') closeReviewersModal();
      if (returnModal.style.display === 'flex') closeReturnModal();
      if (rejectModal.style.display === 'flex') closeRejectModal();
    }
  });

  // Close on backdrop click
  [rvModal, returnModal, rejectModal].forEach((m) => {
    m.addEventListener('click', (e) => {
      if (e.target === m) {
        if (m === rvModal) closeReviewersModal();
        else if (m === returnModal) closeReturnModal();
        else if (m === rejectModal) closeRejectModal();
      }
    });
  });

  // Client-side guards
  returnForm.addEventListener('submit', (e) => {
    const v = reasonField.value.trim();
    if (v.length < 20) {
      e.preventDefault();
      reasonField.classList.add('invalid');
      reasonField.focus();
    }
  });
  reasonField.addEventListener('input', () => reasonField.classList.remove('invalid'));

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
