<?php
/**
 * Admin — Archive Management
 *
 * Two views on this page:
 *   1. Archived Projects        — projects with research_projects.status='archived' (read-only)
 *   2. Publication & Colloquium  — write UI for research_publication_tracking on completed/archived
 *                                 projects. The student progress-tracking page reads from this
 *                                 table; before this change no code ever wrote to it.
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
$pubtbl_exists    = (function () use ($conn) {
    $r = $conn->query("SHOW TABLES LIKE 'research_publication_tracking'");
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

// ── archive view filters (existing) ───────────────────────────────────
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';

// ── POST handlers (publication + archive transitions) ──────────────────
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

                    // Publication & Colloquium updates are only meaningful on
                    // completed/archived projects. In-progress ones aren't ready.
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
                        if (!in_array($new_status, arch_enum_values('archive_status'), true)) {
                            $arch_flash['err'] = 'Invalid archive status value.';
                        } else {
                            $sql = "
                                INSERT INTO research_publication_tracking
                                    (project_id, archive_status, created_at, updated_at)
                                VALUES (?, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE
                                    archive_status = VALUES(archive_status),
                                    updated_at = NOW()
                            ";
                            $upd = $conn->prepare($sql);
                            if ($upd) {
                                $upd->bind_param('is', $project_id, $new_status);
                                $upd->execute();
                                $upd->close();
                            }

                            // Side-effect: archiving the publication row archives the project too,
                            // but only from a 'completed' state (never anything else — guards
                            // against accidentally archiving a draft).
                            $flipped_project = false;
                            if ($new_status === 'archived' && $proj_status === 'completed') {
                                $ps_upd = $conn->prepare("UPDATE research_projects SET status = 'archived', updated_at = NOW() WHERE project_id = ? AND status = 'completed'" . $rp_deleted_filter);
                                if ($ps_upd) {
                                    $ps_upd->bind_param('i', $project_id);
                                    $ps_upd->execute();
                                    $flipped_project = $ps_upd->affected_rows > 0;
                                    $ps_upd->close();
                                }
                            }

                            logActivity(
                                'Updated archive status to "' . $new_status . '" for project #' . $project_id . ' ("' . $title . '")'
                                . ($flipped_project ? ' — project also moved to archived' : ''),
                                'publication_tracking'
                            );
                            $arch_flash['ok'] = $flipped_project
                                ? 'Archive status updated and project moved to archived.'
                                : 'Archive status updated.';
                        }
                    } else {
                        $arch_flash['err'] = 'Unknown action.';
                    }
                }
            }
        }
    }
}

// ── Stat cards ─────────────────────────────────────────────────────────
$total_archived = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status = 'archived'" . $rp_deleted_filter)->fetch_assoc()['c'] ?? 0);
$total_completed = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status = 'completed'" . $rp_deleted_filter)->fetch_assoc()['c'] ?? 0);

// "Colloquium-ready" = completed/archived projects whose publication row either
// doesn't exist yet OR has colloquium_status != 'presented'. This matches the
// student progress-tracking definition of "scheduled/presented/cancelled".
$colloquium_ready = 0;
$published_count  = 0;
if ($pubtbl_exists) {
    $cq = $conn->query("
        SELECT COUNT(*) AS c FROM research_projects rp
        WHERE rp.status IN ('completed','archived')" . $rp_deleted_filter_alias . "
          AND (NOT EXISTS (SELECT 1 FROM research_publication_tracking rpt WHERE rpt.project_id = rp.project_id)
               OR EXISTS (SELECT 1 FROM research_publication_tracking rpt WHERE rpt.project_id = rp.project_id AND rpt.colloquium_status <> 'presented'))
    ");
    if ($cq) { $colloquium_ready = (int) ($cq->fetch_assoc()['c'] ?? 0); $cq->free(); }

    $pc = $conn->query("SELECT COUNT(*) AS c FROM research_publication_tracking WHERE journal_status = 'published'");
    if ($pc) { $published_count = (int) ($pc->fetch_assoc()['c'] ?? 0); $pc->free(); }
}

// ── Archive view query (existing) ─────────────────────────────────────
$where_clauses = ["rp.status = 'archived'"];
$params = [];
$types = '';
if (!empty($search)) {
    $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}
if (!empty($department_filter)) {
    $where_clauses[] = "u.department = ?";
    $params[] = $department_filter;
    $types .= 's';
}
$where_sql = implode(' AND ', $where_clauses);

$query = "
    SELECT rp.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           u.department,
           CONCAT(f.first_name, ' ', f.last_name) as adviser_name
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN project_advisers pa ON rp.project_id = pa.project_id
    LEFT JOIN users f ON pa.adviser_id = f.user_id
    WHERE {$where_sql}
    ORDER BY rp.updated_at DESC
    LIMIT 100
";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$projects = $stmt->get_result();

$departments = $conn->query("
    SELECT DISTINCT u.department
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE rp.status = 'archived' AND u.department IS NOT NULL AND u.department != ''
    ORDER BY u.department
");

// ── Publication & Colloquium list (completed + archived) ──────────────
$pub_list = [];
if ($pubtbl_exists) {
    $pub_sql = "
        SELECT rp.project_id, rp.title, rp.status AS project_status, rp.updated_at,
               CONCAT(u.first_name, ' ', u.last_name) AS student_name,
               u.department,
               rpt.colloquium_status, rpt.colloquium_date, rpt.journal_status,
               rpt.journal_reference, rpt.archive_status, rpt.remarks, rpt.updated_at AS pub_updated_at
          FROM research_projects rp
          LEFT JOIN users u ON u.user_id = rp.created_by
          LEFT JOIN research_publication_tracking rpt ON rpt.project_id = rp.project_id
         WHERE rp.status IN ('completed','archived')" . $rp_deleted_filter_alias . "
         ORDER BY rp.updated_at DESC
         LIMIT 100
    ";
    $pr = $conn->query($pub_sql);
    if ($pr) {
        while ($r = $pr->fetch_assoc()) { $pub_list[] = $r; }
        $pr->free();
    }
}

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

  /* FINAL-STAGE WORKSPACE */
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
      radial-gradient(circle at 82% 14%, rgba(217, 164, 65, .24), transparent 30%),
      radial-gradient(circle at 8% 110%, rgba(13, 148, 136, .2), transparent 34%),
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
  .archive-kicker { color: #e8bd67; margin-bottom: 12px; }
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
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.055);
    backdrop-filter: blur(8px);
  }
  .archive-route > div:first-child { border-radius: 13px 13px 5px 5px; }
  .archive-route > div:last-child { border-radius: 5px 5px 13px 13px; }
  .archive-route span { color: #e8bd67; font: 700 11px/1 ui-monospace, SFMono-Regular, Consolas, monospace; }
  .archive-route strong { font-size: 13px; }
  .archive-route small { color: #aab5c6; font-size: 12px; }

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
  .stat-card-completed { --metric-accent: #d09a2d; }
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
  .section-kicker { color: #9a6b13; margin-bottom: 5px; }
  .card-title { font-size: 23px; letter-spacing: -.025em; }
  .card-sub { max-width: 700px; line-height: 1.6; }
  .project-count {
    padding: 7px 11px;
    border-radius: 8px;
    background: #fff7e6;
    color: #845b0d;
    font: 700 12px/1 ui-monospace, SFMono-Regular, Consolas, monospace;
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
  .project-overline { color: #d6aa52; margin-bottom: 13px; }
  .project-title a {
    color: #fff;
    font-size: 17px;
    font-weight: 650;
    line-height: 1.35;
    letter-spacing: -.015em;
    text-decoration: none;
  }
  .project-title a:hover { color: #f0ca7b; }
  .project-owner { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; color: #aeb9ca; font-size: 12px; }
  .project-progress { overflow: hidden; height: 4px; margin-top: 24px; border-radius: 999px; background: rgba(255,255,255,.12); }
  .project-progress span { display: block; height: 100%; border-radius: inherit; background: #d6aa52; }
  .project-progress-label { margin-top: 7px; color: #8491a5; font-size: 11px; }
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
    border-color: #ab7c23;
    box-shadow: 0 0 0 3px rgba(171, 124, 35, .13);
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
  .publication-table .btn-secondary:hover { border-color: #a97920; background: #fff8e8; color: #754f09; transform: translateY(-1px); }
  .publication-table .btn-secondary:active { transform: translateY(0); }
  .publication-table .btn-secondary:focus-visible { outline: 3px solid rgba(171, 124, 35, .2); outline-offset: 2px; }

  .archive-library-card { margin-top: 38px; border-color: #dfe5ed; box-shadow: 0 12px 34px rgba(30, 41, 59, .07); }
  .archive-library-card .filter-bar { padding: 12px; border-radius: 13px; background: #f5f7fa; }
  .archive-library-card .search-input,
  .archive-library-card .filter-select { min-height: 42px; background: #fff; }
  .archive-library-card .table-wrap { border-radius: 14px; }

  @media (max-width: 1180px) {
    .archive-hero { grid-template-columns: 1fr; gap: 28px; }
    .archive-route { grid-template-columns: repeat(3, minmax(0, 1fr)); }
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
    .archive-library-card { padding: 22px 18px; }
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
    'Publication & Colloquium',
    'Move completed research from presentation to publication and institutional archive.'
);
?>

    <section class="archive-hero">
      <div class="archive-hero-copy">
        <div class="archive-kicker">Research lifecycle · Final stage</div>
        <h1>Bring finished research into the public record.</h1>
        <p>Coordinate the colloquium, journal outcome, and permanent archive from one focused workspace.</p>
      </div>
      <div class="archive-route" aria-label="Publication workflow">
        <div><span>01</span><strong>Present</strong><small>Research colloquium</small></div>
        <div><span>02</span><strong>Publish</strong><small>Journal tracking</small></div>
        <div><span>03</span><strong>Preserve</strong><small>Institutional archive</small></div>
      </div>
    </section>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card stat-card-colloquium">
        <div class="stat-index">01 · Present</div>
        <div class="stat-number"><?php echo arch_se($colloquium_ready); ?></div>
        <div class="stat-label">Awaiting Colloquium</div>
      </div>
      <div class="stat-card stat-card-published">
        <div class="stat-index">02 · Publish</div>
        <div class="stat-number"><?php echo arch_se($published_count); ?></div>
        <div class="stat-label">Published</div>
      </div>
      <div class="stat-card stat-card-archived">
        <div class="stat-index">03 · Preserve</div>
        <div class="stat-number"><?php echo arch_se($total_archived); ?></div>
        <div class="stat-label">Archived</div>
      </div>
      <div class="stat-card stat-card-completed">
        <div class="stat-index">Ready queue</div>
        <div class="stat-number"><?php echo arch_se($total_completed); ?></div>
        <div class="stat-label">Completed (awaiting)</div>
      </div>
    </div>

    <?php if ($arch_flash['ok'] !== ''): ?>
      <div class="admin-flash admin-flash-ok">✓ <?php echo arch_se($arch_flash['ok']); ?></div>
    <?php endif; ?>
    <?php if ($arch_flash['err'] !== ''): ?>
      <div class="admin-flash admin-flash-err">✕ <?php echo arch_se($arch_flash['err']); ?></div>
    <?php endif; ?>

    <!-- PUBLICATION & COLLOQUIUM -->
    <div class="card publication-card">
      <div class="card-header">
        <div>
          <div class="section-kicker">Active pipeline</div>
          <div class="card-title">Publication &amp; Colloquium</div>
          <p class="card-sub">
            Advance each completed project through presentation, journal publication, and long-term preservation.
          </p>
        </div>
        <div class="project-count">
          <?php echo (int) count($pub_list); ?> project<?php echo count($pub_list) !== 1 ? 's' : ''; ?>
        </div>
      </div>

      <?php if (!$pubtbl_exists): ?>
        <div class="admin-flash admin-flash-err">
          ✕ The <code>research_publication_tracking</code> table is missing. Run migration
          <code>database/migrations/rms_db_migration.sql</code> to enable this section.
        </div>
      <?php elseif (empty($pub_list)): ?>
        <div style="text-align: center; padding: 40px 24px; color: #94A3B8;">
          <div style="font-size: 40px; margin-bottom: 12px; opacity: 0.6;">🗂️</div>
          <p style="margin: 0; font-size: 14px;">No completed or archived projects yet.</p>
          <p style="margin: 6px 0 0 0; font-size: 13px;">Projects appear here when they reach the completed or archived stage.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap publication-table-wrap">
          <table class="publication-table">
            <thead>
              <tr>
                <th style="min-width: 240px;">Title / Student</th>
                <th>Colloquium</th>
                <th>Journal</th>
                <th>Archive</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pub_list as $row):
                $p_id          = (int) $row['project_id'];
                $pname         = (string) ($row['student_name'] ?? '—');
                $proj_status   = (string) ($row['project_status'] ?? '');
                $col_state     = (string) ($row['colloquium_status'] ?? '');
                $col_date      = (string) ($row['colloquium_date'] ?? '');
                $jour_state    = (string) ($row['journal_status'] ?? '');
                $jour_ref      = (string) ($row['journal_reference'] ?? '');
                $arch_state    = (string) ($row['archive_status'] ?? '');
                $remarks       = (string) ($row['remarks'] ?? '');
                $has_row       = $row['pub_updated_at'] !== null;
                $closed_stages = ($col_state === 'presented' ? 1 : 0)
                    + ($jour_state === 'published' ? 1 : 0)
                    + ($arch_state === 'archived' ? 1 : 0);

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
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>"
                         >
                        <?php echo arch_se($row['title']); ?>
                      </a>
                    </div>
                    <div class="project-owner">
                      <?php echo arch_se($pname); ?>
                      <span class="badge <?php echo $proj_status === 'archived' ? 'badge-archived' : 'badge-completed'; ?>">
                          <?php echo arch_se(ucfirst($proj_status)); ?>
                      </span>
                    </div>
                    <div class="project-progress" aria-label="<?php echo $closed_stages; ?> of 3 final milestones complete">
                      <span style="width: <?php echo (int) round(($closed_stages / 3) * 100); ?>%;"></span>
                    </div>
                    <div class="project-progress-label"><?php echo $closed_stages; ?>/3 milestones closed</div>
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
                  <td class="stage-cell <?php echo $arch_state === 'archived' ? 'stage-complete' : ''; ?>" data-stage="03 · Archive">
                    <span class="badge <?php echo $arch_class; ?>">
                      <?php echo arch_se($archive_labels[$arch_state] ?? 'Not archived'); ?>
                    </span>
                    <?php if (!$has_row): ?>
                      <div class="pub-empty">No record yet — first save will create it.</div>
                    <?php endif; ?>

                    <form method="POST" class="pub-edit">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="update_archive">
                      <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                      <div class="pub-edit-row">
                        <span class="label">Status</span>
                        <select name="archive_status" required>
                          <?php foreach (arch_enum_values('archive_status') as $v): ?>
                            <option value="<?php echo arch_se($v); ?>" <?php echo $v === $arch_state ? 'selected' : ''; ?>>
                              <?php echo arch_se($archive_labels[$v] ?? $v); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <?php if ($proj_status === 'completed'): ?>
                        <div class="pub-meta">
                          ⚠️ Setting this to <strong>archived</strong> will also archive the project
                          (research_projects.status → 'archived').
                        </div>
                      <?php elseif ($proj_status === 'archived'): ?>
                        <div class="pub-meta">
                          ✓ Project is already archived.
                        </div>
                      <?php endif; ?>
                      <div class="pub-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">💾 Save Archive</button>
                      </div>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ARCHIVE CARD -->
    <div class="card archive-library-card">
      <div class="card-header">
        <div>
          <div class="section-kicker">Permanent collection</div>
          <div class="card-title">Archived Projects</div>
          <p class="card-sub">Search the institutional record by title, researcher, or department.</p>
        </div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-archive.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by title or student name..." value="<?php echo arch_se($search); ?>">
        <select name="department" class="filter-select" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php while ($dept = $departments->fetch_assoc()): ?>
            <option value="<?php echo arch_se($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
              <?php echo arch_se($dept['department']); ?>
            </option>
          <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($department_filter)): ?>
          <a href="admin-archive.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Student</th>
              <th>Department</th>
              <th>Adviser</th>
              <th>Archived</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($projects->num_rows > 0): ?>
              <?php while ($project = $projects->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight: 500;">
                    <?php echo arch_se($project['title']); ?>
                  </td>
                  <td><?php echo arch_se($project['student_name'] ?? '—'); ?></td>
                  <td><?php echo arch_se($project['department'] ?? '—'); ?></td>
                  <td><?php echo arch_se($project['adviser_name'] ?? 'Unassigned'); ?></td>
                  <td style="white-space: nowrap;">
                    <?php echo arch_se(date('M d, Y', strtotime($project['updated_at']))); ?>
                  </td>
                  <td>
                    <a href="../shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
                  No archived research projects found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php
renderAdminShellClose();
