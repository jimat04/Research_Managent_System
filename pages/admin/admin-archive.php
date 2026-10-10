<?php
/**
 * Admin — Archive Management
 *
 * Completed and archived projects share one register for dissemination outcomes
 * and the completed-to-archived status transition.
 *
 * research_publication_tracking has UNIQUE KEY (project_id), so a first-time update uses
 * INSERT ... ON DUPLICATE KEY UPDATE. The three status enums are validated server-side
 * (whitelist) before any UPDATE — even with prepared statements, a whitelist stops garbage
 * from being written to a non-strict column.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── helpers (escape + enum whitelists) ─────────────────────────────────
function arch_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function arch_enum_values(string $col): array {
    // Server-side enum whitelist. If the column is missing or the SHOW query fails
    // we fall back to the canonical list declared in the schema so the page still works.
    $fallback = [
        'colloquium_status' => ['not_scheduled', 'scheduled', 'presented', 'cancelled'],
        'journal_status'    => ['not_submitted', 'submitted', 'under_review', 'accepted', 'published', 'rejected'],
        'archive_status'    => ['not_archived', 'ready', 'archived'],
    ];
    return $fallback[$col] ?? [];
}

// Runtime detection of research_publication_tracking columns (defensive — older
// schemas might be missing one or more of the optional columns). The base
// schema ships with all of them, so this should be all-true in practice.
function arch_column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . str_replace("'", "''", $column) . "'";
    $res = $conn->query($sql);
    $exists = $res instanceof mysqli_result && $res->num_rows > 0;
    if ($res instanceof mysqli_result) { $res->free(); }
    return $exists;
}
function arch_capture_author_snapshots(mysqli $conn, int $project_id): bool {
    $member_sql = "
        INSERT IGNORE INTO research_author_snapshots
            (project_id, user_id, author_name, program, author_role, display_order)
        SELECT pm.project_id, u.user_id, TRIM(CONCAT(u.first_name, ' ', u.last_name)),
               NULLIF(TRIM(u.program), ''), pm.role,
               CASE WHEN pm.role = 'lead' THEN 0 ELSE 1 END
          FROM project_members pm
          JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
         WHERE pm.project_id = ?
    ";
    $member_stmt = $conn->prepare($member_sql);
    if (!$member_stmt) return false;
    $member_stmt->bind_param('i', $project_id);
    $member_ok = $member_stmt->execute();
    $member_stmt->close();
    if (!$member_ok) return false;

    // Some legacy projects have no project_members lead row, so preserve the
    // student owner as the lead author when they are not already represented.
    $owner_sql = "
        INSERT IGNORE INTO research_author_snapshots
            (project_id, user_id, author_name, program, author_role, display_order)
        SELECT rp.project_id, u.user_id, TRIM(CONCAT(u.first_name, ' ', u.last_name)),
               NULLIF(TRIM(u.program), ''), 'lead', 0
          FROM research_projects rp
          JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
         WHERE rp.project_id = ?
           AND NOT EXISTS (
               SELECT 1 FROM project_members pm
                WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id
           )
    ";
    $owner_stmt = $conn->prepare($owner_sql);
    if (!$owner_stmt) return false;
    $owner_stmt->bind_param('i', $project_id);
    $owner_ok = $owner_stmt->execute();
    $owner_stmt->close();
    return $owner_ok;
}
$pubtbl_exists    = (function () use ($conn) {
    $r = $conn->query("SHOW TABLES LIKE 'research_publication_tracking'");
    $ok = $r instanceof mysqli_result && $r->num_rows > 0;
    if ($r instanceof mysqli_result) { $r->free(); }
    return $ok;
})();
$author_snapshots_exists = (function () use ($conn) {
    $r = $conn->query("SHOW TABLES LIKE 'research_author_snapshots'");
    $ok = $r instanceof mysqli_result && $r->num_rows > 0;
    if ($r instanceof mysqli_result) { $r->free(); }
    return $ok;
})();
$has_col_date     = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'colloquium_date');
$has_col_status   = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'colloquium_status');
$has_journal_st   = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'journal_status');
$has_journal_ref  = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'journal_reference');
$has_archive_st   = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'archive_status');
$has_remarks      = $pubtbl_exists && arch_column_exists($conn, 'research_publication_tracking', 'remarks');

$rp_has_deleted_at = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'")->num_rows ?? 0);
// Build two flavors of the soft-delete filter — bare column for unaliased
// queries, rp. prefix for queries that alias the table. Pick the right one
// per-query so we never inject "rp.deleted_at" into a query that doesn't
// alias the table (or vice versa).
$rp_deleted_filter        = $rp_has_deleted_at ? ' AND deleted_at IS NULL'         : '';
$rp_deleted_filter_alias  = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL'     : '';

// ── completed research record filters ──────────────────────────────────
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$department_filter = isset($_GET['department']) && is_string($_GET['department']) ? trim($_GET['department']) : '';
$record_status = isset($_GET['record_status']) && is_string($_GET['record_status'])
    ? $_GET['record_status']
    : '';
if (!in_array($record_status, ['completed', 'archived'], true)) {
    $record_status = '';
}

// ── POST handlers (dissemination outcomes + archive transition) ─────────
$arch_flash = ['ok' => '', 'err' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $arch_flash['err'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $action     = (string) $_POST['action'];
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;

        if ($project_id <= 0) {
            $arch_flash['err'] = 'Invalid project reference.';
        } elseif (!$pubtbl_exists) {
            $arch_flash['err'] = 'The research_publication_tracking table is not available in this database.';
        } else {
            // Look up the project + its current publication row (if any).
            $ps = $conn->prepare("SELECT project_id, title, status, created_by FROM research_projects WHERE project_id = ?" . $rp_deleted_filter . " LIMIT 1");
            if (!$ps) {
                $arch_flash['err'] = 'Unable to look up the project.';
            } else {
                $ps->bind_param('i', $project_id);
                $ps->execute();
                $project = $ps->get_result()->fetch_assoc();
                $ps->close();

                if (!$project) {
                    $arch_flash['err'] = 'Project not found.';
                } else {
                    $title     = (string) ($project['title'] ?? 'your research');
                    $short     = mb_substr($title, 0, 60) . (mb_strlen($title) > 60 ? '…' : '');
                    $student_id = (int) ($project['created_by'] ?? 0);
                    $proj_status = (string) ($project['status'] ?? '');

                    // Dissemination outcomes are only tracked after project completion.
                    $allow_publish = in_array($proj_status, ['completed', 'archived'], true);

                    if ($action === 'update_colloquium') {
                        if (!$allow_publish) {
                            $arch_flash['err'] = 'Colloquium status can only be set when the project is completed or archived.';
                        } else {
                            $new_status = (string) ($_POST['colloquium_status'] ?? '');
                            if (!in_array($new_status, arch_enum_values('colloquium_status'), true)) {
                                $arch_flash['err'] = 'Invalid colloquium status value.';
                            } else {
                                $col_date = trim((string) ($_POST['colloquium_date'] ?? ''));
                                $col_date = $col_date !== '' ? str_replace('T', ' ', $col_date) : null; // HTML datetime-local → MySQL DATETIME
                                if ($col_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $col_date)) {
                                    $arch_flash['err'] = 'Invalid colloquium date format.';
                                    $col_date = null;
                                }
                            }
                            if ($arch_flash['err'] === '') {
                                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                                if (mb_strlen($remarks) > 2000) $remarks = mb_substr($remarks, 0, 2000);

                                $sql = "
                                    INSERT INTO research_publication_tracking
                                        (project_id, colloquium_status" . ($has_col_date ? ', colloquium_date' : '') . ($has_remarks ? ', remarks' : '') . ", created_at, updated_at)
                                    VALUES (?, ?" . ($has_col_date ? ', ?' : '') . ($has_remarks ? ', ?' : '') . ", NOW(), NOW())
                                    ON DUPLICATE KEY UPDATE
                                        colloquium_status = VALUES(colloquium_status)"
                                        . ($has_col_date ? ", colloquium_date  = VALUES(colloquium_date)" : '')
                                        . ($has_remarks ? ", remarks         = VALUES(remarks)" : '')
                                        . ", updated_at = NOW()
                                ";
                                $upd = $conn->prepare($sql);
                                if (!$upd) {
                                    $arch_flash['err'] = 'Unable to save colloquium status.';
                                } else {
                                    // Types: project_id(i) + status(s) + date(s) + remarks(s)
                                    if ($has_col_date && $has_remarks) {
                                        $upd->bind_param('isss', $project_id, $new_status, $col_date, $remarks);
                                    } elseif ($has_col_date) {
                                        $upd->bind_param('iss', $project_id, $new_status, $col_date);
                                    } elseif ($has_remarks) {
                                        $upd->bind_param('iss', $project_id, $new_status, $remarks);
                                    } else {
                                        $upd->bind_param('is', $project_id, $new_status);
                                    }
                                    $upd->execute();
                                    $upd->close();

                                    // Best-effort notification when a colloquium is scheduled (first time)
                                    if ($new_status === 'scheduled' && $student_id > 0) {
                                        $when = $col_date ? ' on ' . $col_date : '';
                                        createNotification(
                                            $student_id,
                                            'Research colloquium scheduled',
                                            'The research colloquium for "' . $short . '" has been scheduled' . $when . '.',
                                            'info',
                                            SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
                                        );
                                    }
                                    logActivity(
                                        'Updated colloquium status to "' . $new_status . '" for project #' . $project_id . ' ("' . $title . '")',
                                        'publication_tracking'
                                    );
                                    $arch_flash['ok'] = 'Colloquium status updated.';
                                }
                            }
                        }
                    } elseif ($action === 'update_journal') {
                        if (!$allow_publish) {
                            $arch_flash['err'] = 'Journal status can only be set when the project is completed or archived.';
                        } else {
                            $new_status = (string) ($_POST['journal_status'] ?? '');
                            if (!in_array($new_status, arch_enum_values('journal_status'), true)) {
                                $arch_flash['err'] = 'Invalid journal status value.';
                            } else {
                                $reference = trim((string) ($_POST['journal_reference'] ?? ''));
                                if (mb_strlen($reference) > 255) $reference = mb_substr($reference, 0, 255);
                            }
                            if ($arch_flash['err'] === '') {
                                $sql = "
                                    INSERT INTO research_publication_tracking
                                        (project_id, journal_status" . ($has_journal_ref ? ', journal_reference' : '') . ", created_at, updated_at)
                                    VALUES (?, ?" . ($has_journal_ref ? ', ?' : '') . ", NOW(), NOW())
                                    ON DUPLICATE KEY UPDATE
                                        journal_status = VALUES(journal_status)"
                                        . ($has_journal_ref ? ", journal_reference = VALUES(journal_reference)" : '')
                                        . ", updated_at = NOW()
                                ";
                                $upd = $conn->prepare($sql);
                                if (!$upd) {
                                    $arch_flash['err'] = 'Unable to save journal status.';
                                } else {
                                    if ($has_journal_ref) {
                                        $upd->bind_param('iss', $project_id, $new_status, $reference);
                                    } else {
                                        $upd->bind_param('is', $project_id, $new_status);
                                    }
                                    $upd->execute();
                                    $upd->close();

                                    // Best-effort notification on acceptance or publication
                                    if (in_array($new_status, ['accepted', 'published'], true) && $student_id > 0) {
                                        $verb = $new_status === 'published' ? 'published' : 'accepted for publication';
                                        createNotification(
                                            $student_id,
                                            'Research journal status: ' . $verb,
                                            'The journal status for "' . $short . '" has been marked as "' . $new_status . '".' . ($reference !== '' ? ' Reference: ' . $reference : ''),
                                            'success',
                                            SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
                                        );
                                    }
                                    logActivity(
                                        'Updated journal status to "' . $new_status . '" for project #' . $project_id . ' ("' . $title . '")',
                                        'publication_tracking'
                                    );
                                    $arch_flash['ok'] = 'Journal status updated.';
                                }
                            }
                        }
                    } elseif ($action === 'update_archive') {
                        $new_status = (string) ($_POST['archive_status'] ?? '');
                        if ($proj_status !== 'completed') {
                            $arch_flash['err'] = 'Only completed projects can be moved into the archive. Archived project records are preserved.';
                        } elseif (!in_array($new_status, arch_enum_values('archive_status'), true)) {
                            $arch_flash['err'] = 'Invalid archive status value.';
                        } else {
                            if (!$conn->begin_transaction()) {
                                $arch_flash['err'] = 'Unable to begin the archive update.';
                            } else {
                                $archive_saved = false;
                                $archive_stmt = $conn->prepare("
                                    INSERT INTO research_publication_tracking
                                        (project_id, archive_status, created_at, updated_at)
                                    VALUES (?, ?, NOW(), NOW())
                                    ON DUPLICATE KEY UPDATE
                                        archive_status = VALUES(archive_status),
                                        updated_at = NOW()
                                ");
                                if (!$archive_stmt) {
                                    $arch_flash['err'] = 'Unable to prepare the archive status update.';
                                } else {
                                    $archive_stmt->bind_param('is', $project_id, $new_status);
                                    if (!$archive_stmt->execute()) {
                                        $arch_flash['err'] = 'Unable to save the archive status.';
                                    } else {
                                        $archive_saved = true;
                                    }
                                    $archive_stmt->close();
                                }

                                $flipped_project = false;
                                $author_snapshot_saved = true;
                                if ($arch_flash['err'] === '' && $new_status === 'archived') {
                                    $project_stmt = $conn->prepare("
                                        UPDATE research_projects
                                           SET status = 'archived', updated_at = NOW()
                                         WHERE project_id = ? AND status = 'completed'" . $rp_deleted_filter
                                    );
                                    if (!$project_stmt) {
                                        $arch_flash['err'] = 'Unable to prepare the project archive transition.';
                                    } else {
                                        $project_stmt->bind_param('i', $project_id);
                                        if (!$project_stmt->execute()) {
                                            $arch_flash['err'] = 'Unable to move the completed project into the archive.';
                                        } elseif ($project_stmt->affected_rows !== 1) {
                                            $arch_flash['err'] = 'The project status changed before it could be archived. Refresh the page and try again.';
                                        } else {
                                            $flipped_project = true;
                                        }
                                        $project_stmt->close();
                                    }
                                }

                                if ($arch_flash['err'] === '' && $new_status === 'archived') {
                                    $author_snapshot_saved = $author_snapshots_exists
                                        && arch_capture_author_snapshots($conn, $project_id);
                                    if (!$author_snapshot_saved) {
                                        $arch_flash['err'] = 'Unable to preserve student program attribution. Apply migration 011 before archiving this project.';
                                    }
                                }

                                if ($arch_flash['err'] !== '') {
                                    $conn->rollback();
                                } elseif (!$conn->commit()) {
                                    $conn->rollback();
                                    $arch_flash['err'] = 'Unable to commit the archive update.';
                                } elseif ($archive_saved) {
                                    logActivity(
                                        'Updated archive status to "' . $new_status . '" for project #' . $project_id . ' ("' . $title . '")'
                                        . ($flipped_project ? ' — project also moved to archived' : ''),
                                        'publication_tracking'
                                    );
                                    $arch_flash['ok'] = $flipped_project
                                        ? 'Archive status updated, project moved to archived, and student program attribution preserved.'
                                        : 'Archive status updated.';
                                }
                            }
                        }
                    } else {
                        $arch_flash['err'] = 'Unknown action.';
                    }
                }
            }
        }
    }
}

// ── Completion and dissemination counts ────────────────────────────────
$total_archived = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status = 'archived'" . $rp_deleted_filter)->fetch_assoc()['c'] ?? 0);
$total_completed = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status = 'completed'" . $rp_deleted_filter)->fetch_assoc()['c'] ?? 0);

// Track presentation outcomes independently from the project completion/archive status.
$colloquium_unpresented = 0;
$published_count  = 0;
if ($pubtbl_exists) {
    $cq = $conn->query("
        SELECT COUNT(*) AS c FROM research_projects rp
        WHERE rp.status IN ('completed','archived')" . $rp_deleted_filter_alias . "
          AND (NOT EXISTS (SELECT 1 FROM research_publication_tracking rpt WHERE rpt.project_id = rp.project_id)
               OR EXISTS (SELECT 1 FROM research_publication_tracking rpt WHERE rpt.project_id = rp.project_id AND rpt.colloquium_status <> 'presented'))
    ");
    if ($cq) { $colloquium_unpresented = (int) ($cq->fetch_assoc()['c'] ?? 0); $cq->free(); }

    $pc = $conn->query("
        SELECT COUNT(*) AS c
          FROM research_publication_tracking rpt
          JOIN research_projects rp ON rp.project_id = rpt.project_id
         WHERE rp.status IN ('completed', 'archived')
           AND rpt.journal_status = 'published'" . $rp_deleted_filter_alias
    );
    if ($pc) { $published_count = (int) ($pc->fetch_assoc()['c'] ?? 0); $pc->free(); }
}

// ── Completed and archived research register ───────────────────────────
$pub_list = [];
if ($pubtbl_exists) {
    $where_clauses = ["rp.status IN ('completed', 'archived')"];
    $params = [];
    $types = '';
    if ($record_status !== '') {
        $where_clauses[] = 'rp.status = ?';
        $params[] = $record_status;
        $types .= 's';
    }
    if ($search !== '') {
        $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }
    if ($department_filter !== '') {
        $where_clauses[] = 'u.department = ?';
        $params[] = $department_filter;
        $types .= 's';
    }
    $pub_sql = "
        SELECT rp.project_id, rp.title, rp.status AS project_status, rp.updated_at,
               CONCAT(u.first_name, ' ', u.last_name) AS student_name,
               u.department,
               rpt.colloquium_status, rpt.colloquium_date, rpt.journal_status,
               rpt.journal_reference, rpt.archive_status, rpt.remarks, rpt.updated_at AS pub_updated_at
          FROM research_projects rp
          LEFT JOIN users u ON u.user_id = rp.created_by
          LEFT JOIN research_publication_tracking rpt ON rpt.project_id = rp.project_id
         WHERE " . implode(' AND ', $where_clauses) . $rp_deleted_filter_alias . "
         ORDER BY rp.updated_at DESC
         LIMIT 100
    ";
    $pub_stmt = $conn->prepare($pub_sql);
    if (!$pub_stmt) {
        throw new RuntimeException('Unable to prepare the completed research register: ' . $conn->error);
    }
    if ($params) {
        $pub_stmt->bind_param($types, ...$params);
    }
    if (!$pub_stmt->execute()) {
        $error = $pub_stmt->error;
        $pub_stmt->close();
        throw new RuntimeException('Unable to load the completed research register: ' . $error);
    }
    $pub_result = $pub_stmt->get_result();
    while ($r = $pub_result->fetch_assoc()) {
        $pub_list[] = $r;
    }
    $pub_stmt->close();
}

$departments = $conn->query("
    SELECT DISTINCT u.department
      FROM research_projects rp
      LEFT JOIN users u ON u.user_id = rp.created_by
     WHERE rp.status IN ('completed', 'archived')" . $rp_deleted_filter_alias . "
       AND u.department IS NOT NULL AND u.department != ''
     ORDER BY u.department
");

// ── status label maps (for display only — write-side uses whitelists above) ──
$colloquium_labels = [
    'not_scheduled' => 'Not scheduled',
    'scheduled'     => 'Scheduled',
    'presented'     => 'Presented',
    'cancelled'     => 'Cancelled',
];
$journal_labels = [
    'not_submitted' => 'Not submitted',
    'submitted'     => 'Submitted',
    'under_review'  => 'Under review',
    'accepted'      => 'Accepted',
    'published'     => 'Published',
    'rejected'      => 'Rejected',
];
$archive_labels = [
    'not_archived' => 'Not archived',
    'ready'        => 'Ready',
    'archived'     => 'Archived',
];

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 48px;
  }
  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 16px;
    padding: 20px;
    transition: all 0.3s;
  }
  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
  .stat-number { font-size: 32px; font-weight: 700; line-height: 1; margin-bottom: 8px; }
  .stat-label  { font-size: 14px; color: var(--text-secondary, #64748B); font-weight: 500; }

  /* CARD */
  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }
  .card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 16px;
  }
  .card-title { font-size: 20px; font-weight: 700; color: var(--charcoal, #111827); }
  .card-sub   { font-size: 13px; color: var(--text-secondary, #64748B); margin: 4px 0 0 0; }

  /* FILTER BAR */
  .filter-bar {
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 24px;
  }
  .search-input {
    flex: 1; min-width: 250px; padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB); border-radius: 10px;
    font-size: 14px; background: var(--bg-surface, #F8FAFC);
  }
  .filter-select {
    padding: 10px 16px; border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px; font-size: 14px; background: var(--bg-card, #FFFFFF); cursor: pointer;
  }

  /* BUTTON */
  .btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px; border-radius: 10px; font-weight: 600; font-size: 14px;
    border: none; cursor: pointer; transition: all 0.2s; text-decoration: none;
  }
  .btn-secondary {
    background: var(--bg-surface, #F8FAFC);
    color: var(--text-primary, #111827);
    border: 1px solid var(--border, #E5E7EB);
  }
  .btn-secondary:hover { background: #E5E7EB; }
  .btn-sm { padding: 6px 12px; font-size: 13px; }

  /* TABLE */
  .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border, #E5E7EB); }
  table { width: 100%; border-collapse: collapse; }
  thead { background: var(--bg-surface, #F8FAFC); }
  th {
    text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 600;
    color: var(--text-secondary, #64748B); text-transform: uppercase; letter-spacing: 0.5px;
  }
  td { padding: 16px; font-size: 14px; border-top: 1px solid var(--border, #E5E7EB); vertical-align: top; }
  tr:hover { background: var(--bg-surface, #F8FAFC); }

  /* BADGES */
  .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
  .badge-draft     { background: #F1F5F9; color: #475569; }
  .badge-completed { background: #D1FAE5; color: #059669; }
  .badge-archived  { background: #F1F5F9; color: #475569; }
  .badge-slate     { background: #F1F5F9; color: #475569; }
  .badge-blue      { background: #DBEAFE; color: #2563EB; }
  .badge-violet    { background: #EDE9FE; color: #7C3AED; }
  .badge-orange    { background: #FEF3C7; color: #EA580C; }
  .badge-green     { background: #DCFCE7; color: #16A34A; }
  .badge-emerald   { background: #D1FAE5; color: #059669; }
  .badge-red       { background: #FEE2E2; color: #B91C1C; }

  /* Flash banner */
  .admin-flash {
    padding: 14px 18px; border-radius: 12px; margin-bottom: 24px;
    font-size: 14px; font-weight: 500;
  }
  .admin-flash-ok  { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
  .admin-flash-err { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }

  /* Inline status-edit controls */
  .pub-edit {
    display: grid; grid-template-columns: 1fr; gap: 8px;
    padding: 12px; background: var(--bg-surface, #F8FAFC);
    border: 1px solid var(--border, #E5E7EB); border-radius: 12px; margin-top: 8px;
  }
  .pub-edit-row {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
  }
  .pub-edit-row .label {
    font-size: 12px; font-weight: 600; color: var(--text-secondary, #64748B);
    text-transform: uppercase; letter-spacing: 0.5px; min-width: 90px;
  }
  .pub-edit-row select,
  .pub-edit-row input[type="text"],
  .pub-edit-row input[type="datetime-local"] {
    padding: 8px 10px; font-size: 13px; border: 1px solid var(--border, #E5E7EB);
    border-radius: 8px; background: #ffffff; color: #111827; font-family: inherit;
  }
  .pub-edit-row input[type="text"] { min-width: 200px; }
  .pub-edit-row input[type="datetime-local"] { min-width: 200px; }
  .pub-meta { font-size: 12px; color: var(--text-muted, #94A3B8); }
  .pub-empty { font-size: 12px; color: var(--text-muted, #94A3B8); font-style: italic; }
  .pub-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }

  /* COMPLETION AND ARCHIVE WORKSPACE */
  .archive-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(360px, .75fr);
    gap: 40px;
    align-items: end;
    min-height: 260px;
    padding: 42px 46px 62px;
    border-radius: 24px;
    background:
      radial-gradient(circle at 82% 14%, rgba(245, 124, 0, .2), transparent 30%),
      radial-gradient(circle at 8% 110%, rgba(245, 124, 0, .11), transparent 34%),
      #101827;
    color: #fff;
    box-shadow: 0 24px 55px rgba(30, 41, 59, .16);
  }
  .archive-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    opacity: .18;
    background-image: repeating-linear-gradient(115deg, transparent 0 28px, rgba(255,255,255,.08) 29px 30px);
    mask-image: linear-gradient(to right, transparent, #000 55%);
  }
  .archive-kicker,
  .section-kicker,
  .stat-index,
  .project-overline {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
  }
  .archive-kicker { color: #fdba74; margin-bottom: 12px; }
  .archive-hero h1 {
    max-width: 650px;
    margin: 0;
    font-size: clamp(32px, 4vw, 52px);
    line-height: 1.02;
    letter-spacing: -.045em;
    text-wrap: balance;
  }
  .archive-hero-copy p {
    max-width: 610px;
    margin: 18px 0 0;
    color: #b9c3d3;
    font-size: 15px;
    line-height: 1.7;
  }
  .archive-route { display: grid; gap: 2px; }
  .archive-route > div {
    display: grid;
    grid-template-columns: 34px 88px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    padding: 13px 15px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8fafc;
  }
  .archive-route span { color: #c45f00; font: 700 11px/1 ui-monospace, SFMono-Regular, Consolas, monospace; }
  .archive-route strong { color: #182230; font-size: 13px; }
  .archive-route small { color: #64748b; font-size: 12px; }

  .stats-grid {
    position: relative;
    z-index: 2;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin: -32px 22px 34px;
  }
  .stat-card {
    position: relative;
    overflow: hidden;
    min-height: 138px;
    padding: 20px 22px;
    border: 0;
    border-radius: 15px;
    box-shadow: 0 14px 32px rgba(30, 41, 59, .1);
  }
  .stat-card::after {
    content: '';
    position: absolute;
    right: -30px;
    bottom: -46px;
    width: 100px;
    height: 100px;
    border: 18px solid var(--metric-accent, #64748b);
    border-radius: 50%;
    opacity: .08;
  }
  .stat-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(30, 41, 59, .14); }
  .stat-card-colloquium { --metric-accent: #2563eb; }
  .stat-card-published { --metric-accent: #7c3aed; }
  .stat-card-archived { --metric-accent: #059669; }
  .stat-card-completed { --metric-accent: #f57c00; }
  .stat-index { color: var(--metric-accent); margin-bottom: 22px; }
  .stat-number { color: #111827; font-size: 36px; font-variant-numeric: tabular-nums; letter-spacing: -.04em; }
  .stat-label { color: #667085; font-size: 12px; }

  .publication-card { padding: 0; border: 0; background: transparent; box-shadow: none; }
  .publication-card > .card-header {
    margin-bottom: 14px;
    padding: 24px 26px;
    border: 1px solid #e3e8ef;
    border-radius: 18px;
    background: #fff;
  }
  .section-kicker { color: #c45f00; margin-bottom: 5px; }
  .card-title { font-size: 23px; letter-spacing: -.025em; }
  .card-sub { max-width: 700px; line-height: 1.6; }
  .project-count {
    padding: 7px 11px;
    border-radius: 8px;
    background: rgba(245, 124, 0, .09);
    color: #9a4b00;
    font: 700 12px/1 ui-monospace, SFMono-Regular, Consolas, monospace;
  }
  .record-filter-bar {
    padding: 12px;
    border: 1px solid #e6eaf0;
    border-radius: 13px;
    background: #f7f8fa;
  }
  .record-filter-bar .search-input,
  .record-filter-bar .filter-select { min-height: 42px; background: #fff; }
  .record-filter-bar :is(input, select, button, a):focus-visible {
    outline: 3px solid rgba(245, 124, 0, .23);
    outline-offset: 2px;
  }
  .record-note {
    margin: -5px 0 18px;
    padding: 12px 15px;
    border-left: 3px solid #f57c00;
    border-radius: 0 9px 9px 0;
    background: #fff8f1;
    color: #6b4a2d;
    font-size: 13px;
    line-height: 1.55;
  }
  .publication-table-wrap { overflow: visible; border: 0; border-radius: 0; }
  .publication-table,
  .publication-table tbody { display: block; }
  .publication-table thead { display: none; }
  .publication-table tbody { display: grid; gap: 16px; }
  .publication-table .publication-project {
    display: grid;
    grid-template-columns: minmax(230px, .82fr) repeat(3, minmax(220px, 1fr));
    overflow: hidden;
    border: 1px solid #dfe5ed;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(30, 41, 59, .07);
    transition: transform .22s ease, box-shadow .22s ease;
  }
  .publication-table .publication-project:hover { transform: translateY(-2px); box-shadow: 0 17px 36px rgba(30, 41, 59, .11); }
  .publication-table .publication-project > td {
    min-width: 0 !important;
    padding: 22px;
    border: 0;
    border-left: 1px solid #e8ecf2;
    background: #fbfcfe;
  }
  .publication-table .publication-project > td:first-child { border-left: 0; }
  .publication-table .publication-project > td.project-identity {
    display: flex;
    flex-direction: column;
    justify-content: center;
    background: #172033;
    color: #fff;
  }
  .project-overline { color: #fdba74; margin-bottom: 13px; }
  .project-title a {
    color: #fff;
    font-size: 17px;
    font-weight: 650;
    line-height: 1.35;
    letter-spacing: -.015em;
    text-decoration: none;
  }
  .project-title a:hover { color: #fdba74; }
  .project-owner { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; color: #aeb9ca; font-size: 12px; }
  .project-meta { margin-top: 16px; font-size: 12px; }
  .project-meta a { color: #fdba74; text-decoration: none; }
  .project-meta a:hover { color: #fff; text-decoration: underline; }
  .stage-cell::before {
    content: attr(data-stage);
    display: block;
    margin-bottom: 14px;
    color: #697586;
    font: 700 11px/1 ui-monospace, SFMono-Regular, Consolas, monospace;
    letter-spacing: .08em;
    text-transform: uppercase;
  }
  .stage-cell.stage-complete { background: #f5fbf8 !important; }
  .stage-cell.stage-complete::before { color: #087a55; }
  .publication-table .pub-edit {
    gap: 11px;
    margin-top: 15px;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
  }
  .publication-table .pub-edit-row { display: grid; grid-template-columns: 1fr; gap: 5px; align-items: stretch; }
  .publication-table .pub-edit-row .label { min-width: 0; color: #7c8797; font-size: 10px; }
  .publication-table .pub-edit-row select,
  .publication-table .pub-edit-row input[type="text"],
  .publication-table .pub-edit-row input[type="datetime-local"] {
    width: 100%;
    min-width: 0;
    min-height: 39px;
    border-color: #d9e0e9;
    background: #fff;
    transition: border-color .2s ease, box-shadow .2s ease;
  }
  .publication-table .pub-edit-row select:focus,
  .publication-table .pub-edit-row input:focus {
    outline: 0;
    border-color: #f57c00;
    box-shadow: 0 0 0 3px rgba(245, 124, 0, .13);
  }
  .publication-table .pub-meta { margin-top: 7px; color: #748094; line-height: 1.45; }
  .publication-table .pub-actions { margin-top: 2px; }
  .publication-table .btn-secondary {
    width: 100%;
    justify-content: center;
    min-height: 38px;
    border-color: #d8dee7;
    background: #fff;
    color: #263246;
  }
  .publication-table .btn-secondary:hover { border-color: #f57c00; background: #fff8f1; color: #9a4b00; transform: translateY(-1px); }
  .publication-table .btn-secondary:active { transform: translateY(0); }
  .publication-table .btn-secondary:focus-visible { outline: 3px solid rgba(245, 124, 0, .23); outline-offset: 2px; }

  .archive-library-card {
    margin-top: 16px;
    padding: 0 22px 24px;
    border: 1px solid #dfe5ed;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 12px 34px rgba(30, 41, 59, .07);
  }
  .archive-library-card > .card-header { margin: 0 -22px 20px; }
  .archive-library-card .filter-bar { padding: 12px; border-radius: 13px; background: #f5f7fa; }
  .archive-library-card .search-input,
  .archive-library-card .filter-select { min-height: 42px; background: #fff; }
  .archive-library-card .table-wrap { border-radius: 14px; }

  @media (max-width: 1180px) {
    .archive-hero { grid-template-columns: 1fr; gap: 28px; }
    .archive-route { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .archive-route > div { grid-template-columns: 28px 1fr; }
    .archive-route small { grid-column: 2; }
    .publication-table .publication-project { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .publication-table .project-identity { grid-column: 1 / -1; }
  }
  @media (max-width: 768px) {
    .archive-hero { min-height: 0; padding: 30px 24px 52px; border-radius: 18px; }
    .archive-hero h1 { font-size: 34px; }
    .archive-route { grid-template-columns: 1fr; }
    .archive-route > div { grid-template-columns: 30px 82px 1fr; }
    .archive-route small { grid-column: auto; }
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: -25px 12px 30px; }
    .stat-card { min-height: 124px; padding: 17px; }
    .stat-index { margin-bottom: 15px; }
    .publication-card > .card-header { padding: 20px; }
    .publication-table .publication-project { grid-template-columns: 1fr; }
    .publication-table .project-identity { grid-column: auto; }
    .publication-table .publication-project > td { border-left: 0; border-top: 1px solid #e8ecf2; }
    .publication-table .publication-project > td:first-child { border-top: 0; }
    .archive-library-card { padding: 0 18px 20px; }
    .archive-library-card > .card-header { margin: 0 -18px 18px; }
    .record-filter-bar { align-items: stretch; }
    .record-filter-bar .search-input { min-width: 0; }
  }
  @media (max-width: 460px) {
    .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; margin-left: 6px; margin-right: 6px; }
    .stat-card { padding: 15px; }
    .stat-number { font-size: 30px; }
    .archive-route > div { grid-template-columns: 28px 74px minmax(0, 1fr); padding: 11px; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-archive',
    'Archive Management',
    'Close completed research records and manage their dissemination and preservation.'
);
?>

    <section class="archive-hero">
      <div class="archive-hero-copy">
        <div class="archive-kicker">Research lifecycle · Completion and preservation</div>
        <h1>Close the record. Keep the work accessible.</h1>
        <p>Review completed projects, record how their findings are shared, and move finalized work into the institutional archive.</p>
      </div>
      <div class="archive-route" aria-label="Project status flow">
        <div><span>STATUS</span><strong>Completed</strong><small>Implementation is finished</small></div>
        <div><span>NEXT</span><strong>Archived</strong><small>Preserved institutional record</small></div>
      </div>
    </section>

    <!-- COMPLETION AND OUTCOME COUNTS -->
    <div class="stats-grid">
      <div class="stat-card stat-card-colloquium">
        <div class="stat-index">Project status</div>
        <div class="stat-number"><?php echo arch_se($total_completed); ?></div>
        <div class="stat-label">Completed · ready to archive</div>
      </div>
      <div class="stat-card stat-card-archived">
        <div class="stat-index">Project status</div>
        <div class="stat-number"><?php echo arch_se($total_archived); ?></div>
        <div class="stat-label">In the archive</div>
      </div>
      <div class="stat-card stat-card-published">
        <div class="stat-index">Dissemination outcome</div>
        <div class="stat-number"><?php echo $pubtbl_exists ? arch_se($published_count) : '&mdash;'; ?></div>
        <div class="stat-label">Journal publications</div>
      </div>
      <div class="stat-card stat-card-completed">
        <div class="stat-index">Dissemination outcome</div>
        <div class="stat-number"><?php echo $pubtbl_exists ? arch_se($colloquium_unpresented) : '&mdash;'; ?></div>
        <div class="stat-label">Not yet presented at colloquium</div>
      </div>
    </div>

    <?php if ($arch_flash['ok'] !== ''): ?>
      <div class="admin-flash admin-flash-ok">✓ <?php echo arch_se($arch_flash['ok']); ?></div>
    <?php endif; ?>
    <?php if ($arch_flash['err'] !== ''): ?>
      <div class="admin-flash admin-flash-err">✕ <?php echo arch_se($arch_flash['err']); ?></div>
    <?php endif; ?>

    <!-- COMPLETED AND ARCHIVED RESEARCH REGISTER -->
    <div class="card publication-card archive-library-card">
      <div class="card-header">
        <div>
          <div class="section-kicker">Completed → archived</div>
          <div class="card-title">Completed research record</div>
          <p class="card-sub">
            Manage archive status and keep colloquium and journal outcomes with each completed project.
          </p>
        </div>
        <div class="project-count">
          Showing <?php echo (int) count($pub_list); ?> project<?php echo count($pub_list) !== 1 ? 's' : ''; ?>
        </div>
      </div>

      <?php if (!$pubtbl_exists): ?>
        <div class="admin-flash admin-flash-err">
          ✕ The <code>research_publication_tracking</code> table is missing. Run migration
          <code>database/migrations/rms_db_migration.sql</code> to enable dissemination and archive tracking.
        </div>
      <?php else: ?>
        <p class="record-note">
          Colloquium and journal entries document dissemination outcomes; they are not approval gates for archiving.
          Only projects with status <strong>completed</strong> can be moved into the archive.
        </p>
        <form method="GET" action="admin-archive.php" class="filter-bar record-filter-bar">
          <input type="search" name="search" class="search-input" placeholder="Search by title or student name..." value="<?php echo arch_se($search); ?>" aria-label="Search completed research">
          <select name="record_status" class="filter-select" aria-label="Filter by project status">
            <option value="">Completed and archived</option>
            <option value="completed" <?php echo $record_status === 'completed' ? 'selected' : ''; ?>>Completed · ready to archive</option>
            <option value="archived" <?php echo $record_status === 'archived' ? 'selected' : ''; ?>>Archived</option>
          </select>
          <select name="department" class="filter-select" aria-label="Filter by department">
            <option value="">All departments</option>
            <?php while ($dept = $departments->fetch_assoc()): ?>
              <option value="<?php echo arch_se($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                <?php echo arch_se($dept['department']); ?>
              </option>
            <?php endwhile; ?>
          </select>
          <button type="submit" class="btn btn-secondary btn-sm">Filter records</button>
          <?php if ($search !== '' || $department_filter !== '' || $record_status !== ''): ?>
            <a href="admin-archive.php" class="btn btn-secondary btn-sm">Clear filters</a>
          <?php endif; ?>
        </form>
        <?php if (empty($pub_list)): ?>
          <div style="text-align: center; padding: 40px 24px; color: #64748B;">
            <div style="font-size: 40px; margin-bottom: 12px;">🗂️</div>
            <p style="margin: 0; font-size: 15px; font-weight: 600; color: #334155;">
              <?php echo $record_status === 'archived' ? 'No archived projects match your filters.' : ($record_status === 'completed' ? 'No completed projects match your filters.' : 'No completed or archived projects match your filters.'); ?>
            </p>
            <p style="margin: 6px 0 0; font-size: 13px;">Clear or change the filters to see other completed research records.</p>
          </div>
        <?php else: ?>
        <div class="table-wrap publication-table-wrap">
          <table class="publication-table">
            <thead>
              <tr>
                <th style="min-width: 240px;">Title / Student</th>
                <th>Colloquium</th>
                <th>Journal</th>
                <th>Repository</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pub_list as $row):
                $p_id          = (int) $row['project_id'];
                $pname         = (string) ($row['student_name'] ?? '—');
                $proj_status   = (string) ($row['project_status'] ?? '');
                $col_state     = (string) ($row['colloquium_status'] ?? 'not_scheduled');
                $col_date      = (string) ($row['colloquium_date'] ?? '');
                $jour_state    = (string) ($row['journal_status'] ?? 'not_submitted');
                $jour_ref      = (string) ($row['journal_reference'] ?? '');
                $arch_state    = $proj_status === 'archived'
                    ? 'archived'
                    : (string) ($row['archive_status'] ?? 'not_archived');
                $remarks       = (string) ($row['remarks'] ?? '');
                $has_row       = $row['pub_updated_at'] !== null;
                // Status → badge color (read-side display only)
                $col_class  = 'badge-slate';
                if ($col_state === 'scheduled')    $col_class  = 'badge-blue';
                elseif ($col_state === 'presented')    $col_class  = 'badge-emerald';
                elseif ($col_state === 'cancelled')    $col_class  = 'badge-red';

                $jour_class = 'badge-slate';
                if ($jour_state === 'submitted')    $jour_class = 'badge-blue';
                elseif ($jour_state === 'under_review') $jour_class = 'badge-violet';
                elseif ($jour_state === 'accepted')     $jour_class = 'badge-green';
                elseif ($jour_state === 'published')    $jour_class = 'badge-emerald';
                elseif ($jour_state === 'rejected')     $jour_class = 'badge-red';

                $arch_class = 'badge-slate';
                if ($arch_state === 'ready')    $arch_class = 'badge-orange';
                elseif ($arch_state === 'archived') $arch_class = 'badge-emerald';

                // Pre-format datetime-local value (HTML expects "Y-m-d\TH:i")
                $col_date_input = '';
                if ($col_date !== '' && $col_date !== '0000-00-00 00:00:00') {
                    $ts = strtotime($col_date);
                    if ($ts) $col_date_input = date('Y-m-d\TH:i', $ts);
                }
              ?>
                <tr class="publication-project">
                  <td class="project-identity">
                    <div class="project-overline">Project <?php echo arch_se(str_pad((string) $p_id, 2, '0', STR_PAD_LEFT)); ?></div>
                    <div class="project-title">
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>">
                        <?php echo arch_se($row['title']); ?>
                      </a>
                    </div>
                    <div class="project-owner">
                      <?php echo arch_se($pname); ?>
                      <span class="badge <?php echo $proj_status === 'archived' ? 'badge-archived' : 'badge-completed'; ?>">
                          <?php echo arch_se($proj_status === 'archived' ? 'Archived' : 'Completed · ready to archive'); ?>
                      </span>
                    </div>
                    <?php if (!empty($row['department'])): ?>
                      <div class="project-owner"><?php echo arch_se($row['department']); ?></div>
                    <?php endif; ?>
                    <div class="project-meta">
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>">View project record</a>
                    </div>
                  </td>
                  <td class="stage-cell <?php echo $col_state === 'presented' ? 'stage-complete' : ''; ?>" data-stage="01 · Colloquium">
                    <span class="badge <?php echo $col_class; ?>">
                      <?php echo arch_se($colloquium_labels[$col_state] ?? 'Not scheduled'); ?>
                    </span>
                    <?php if ($col_date !== '' && $col_date !== '0000-00-00 00:00:00'): ?>
                      <div class="pub-meta">📅 <?php echo arch_se(date('M d, Y · h:i A', strtotime($col_date))); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="pub-edit">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="update_colloquium">
                      <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                      <div class="pub-edit-row">
                        <span class="label">Status</span>
                        <select name="colloquium_status" required>
                          <?php foreach (arch_enum_values('colloquium_status') as $v): ?>
                            <option value="<?php echo arch_se($v); ?>" <?php echo $v === $col_state ? 'selected' : ''; ?>>
                              <?php echo arch_se($colloquium_labels[$v] ?? $v); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php if ($has_col_date): ?>
                      <div class="pub-edit-row">
                        <span class="label">Date</span>
                        <input type="datetime-local" name="colloquium_date" value="<?php echo arch_se($col_date_input); ?>">
                      </div>
                      <?php endif; ?>
                      <?php if ($has_remarks): ?>
                      <div class="pub-edit-row">
                        <span class="label">Remarks</span>
                        <input type="text" name="remarks" maxlength="2000"
                               value="<?php echo arch_se($remarks); ?>"
                               placeholder="Venue, panel, notes…">
                      </div>
                      <?php endif; ?>
                      <div class="pub-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">💾 Save Colloquium</button>
                      </div>
                    </form>
                  </td>
                  <td class="stage-cell <?php echo $jour_state === 'published' ? 'stage-complete' : ''; ?>" data-stage="02 · Journal">
                    <span class="badge <?php echo $jour_class; ?>">
                      <?php echo arch_se($journal_labels[$jour_state] ?? 'Not submitted'); ?>
                    </span>
                    <?php if ($jour_ref !== ''): ?>
                      <div class="pub-meta">📰 <?php echo arch_se($jour_ref); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="pub-edit">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="update_journal">
                      <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                      <div class="pub-edit-row">
                        <span class="label">Status</span>
                        <select name="journal_status" required>
                          <?php foreach (arch_enum_values('journal_status') as $v): ?>
                            <option value="<?php echo arch_se($v); ?>" <?php echo $v === $jour_state ? 'selected' : ''; ?>>
                              <?php echo arch_se($journal_labels[$v] ?? $v); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php if ($has_journal_ref): ?>
                      <div class="pub-edit-row">
                        <span class="label">Reference</span>
                        <input type="text" name="journal_reference" maxlength="255"
                               value="<?php echo arch_se($jour_ref); ?>"
                               placeholder="Journal name / DOI / manuscript ID">
                      </div>
                      <?php endif; ?>
                      <div class="pub-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">💾 Save Journal</button>
                      </div>
                    </form>
                  </td>
                  <td class="stage-cell <?php echo $arch_state === 'archived' ? 'stage-complete' : ''; ?>" data-stage="03 · Repository">
                    <span class="badge <?php echo $arch_class; ?>">
                      <?php echo arch_se($archive_labels[$arch_state] ?? 'Not archived'); ?>
                    </span>
                    <?php if (!$has_row): ?>
                      <div class="pub-empty">No record yet — first save will create it.</div>
                    <?php endif; ?>

                    <?php if ($proj_status === 'completed'): ?>
                      <form method="POST" class="pub-edit">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="update_archive">
                        <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                        <div class="pub-edit-row">
                          <span class="label">Archive status</span>
                          <select name="archive_status" required>
                            <?php foreach (arch_enum_values('archive_status') as $v): ?>
                              <option value="<?php echo arch_se($v); ?>" <?php echo $v === $arch_state ? 'selected' : ''; ?>>
                                <?php echo arch_se($archive_labels[$v] ?? $v); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="pub-meta">
                          Moving this project to <strong>Archived</strong> updates its research status and preserves the record.
                        </div>
                        <div class="pub-actions">
                          <button type="submit" class="btn btn-secondary btn-sm">
                            <?php echo $arch_state === 'archived' ? 'Move to archive' : 'Save archive status'; ?>
                          </button>
                        </div>
                      </form>
                    <?php else: ?>
                      <p class="pub-meta">This project is already archived. Its archive status cannot be reversed here.</p>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

<?php
renderAdminShellClose();
