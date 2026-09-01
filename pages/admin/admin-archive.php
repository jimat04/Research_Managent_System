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

  @media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-archive',
    'Research Archive',
    'Archive management, plus the publication & colloquium tracking writers for the Research Manual 2015.'
);
?>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo arch_se($colloquium_ready); ?></div>
        <div class="stat-label">Awaiting Colloquium</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo arch_se($published_count); ?></div>
        <div class="stat-label">Published</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo arch_se($total_archived); ?></div>
        <div class="stat-label">Archived</div>
      </div>
      <div class="stat-card">
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
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">🗂️ Publication &amp; Colloquium</div>
          <p class="card-sub">
            Manage the final Research Manual 2015 milestones (colloquium, journal submission, and archive)
            for completed projects. Saving creates the publication record if it doesn't exist yet.
          </p>
        </div>
        <div style="font-size: 13px; color: var(--text-secondary, #64748B); font-weight: 500;">
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
        <div class="table-wrap">
          <table>
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
                <tr>
                  <td>
                    <div style="font-weight: 600; color: #111827;">
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>"
                         style="color: #111827; text-decoration: none;">
                        <?php echo arch_se($row['title']); ?>
                      </a>
                    </div>
                    <div style="font-size: 12px; color: #64748B; margin-top: 2px;">
                      🎒 <?php echo arch_se($pname); ?>
                      · <span class="badge <?php echo $proj_status === 'archived' ? 'badge-archived' : 'badge-completed'; ?>">
                          <?php echo arch_se(ucfirst($proj_status)); ?>
                        </span>
                    </div>
                  </td>
                  <td style="min-width: 280px;">
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
                  <td style="min-width: 260px;">
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
                  <td style="min-width: 200px;">
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
    <div class="card">
      <div class="card-header">
        <div class="card-title">Archived Projects</div>
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
