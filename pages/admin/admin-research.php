<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get research statistics
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$research_draft = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'draft'")->fetch_assoc()['count'] ?? 0);
$research_proposal = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'proposal'")->fetch_assoc()['count'] ?? 0);
$research_crec = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('under_crec_review')")->fetch_assoc()['count'] ?? 0);
$research_erec = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('under_erec_review')")->fetch_assoc()['count'] ?? 0);
// 'approved' = EREC-endorsed, awaiting President/Administration final approval gate
$research_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'approved'")->fetch_assoc()['count'] ?? 0);
$research_ongoing = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('ongoing', 'in_progress')")->fetch_assoc()['count'] ?? 0);
$research_completed = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'completed'")->fetch_assoc()['count'] ?? 0);
$research_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

// ── runtime schema detection (audit-trail columns + soft-delete) ─────────
// research_projects may or may not have approved_by / approved_at columns
// (base schema doesn't have them; a future migration might). Detect so the
// final-approval gate works on both old and new schemas.
$rp_has_approved_by = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'approved_by'")->num_rows ?? 0);
$rp_has_approved_at = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'approved_at'")->num_rows ?? 0);
$rp_has_deleted_at        = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'")->num_rows ?? 0);
$rp_deleted_filter        = $rp_has_deleted_at ? ' AND deleted_at IS NULL'    : '';
$rp_deleted_filter_alias  = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// ── POST handlers (President/Administration final approval gate) ─────────
$admin_flash = ['ok' => '', 'err' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $admin_flash['err'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $action     = (string) $_POST['action'];
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;

        if ($project_id <= 0) {
            $admin_flash['err'] = 'Invalid project reference.';
        } else {
            $info_stmt = $conn->prepare("SELECT project_id, title, status, created_by FROM research_projects WHERE project_id = ?" . $rp_deleted_filter . " LIMIT 1");
            if (!$info_stmt) {
                $admin_flash['err'] = 'Unable to look up the project.';
            } else {
                $info_stmt->bind_param('i', $project_id);
                $info_stmt->execute();
                $project = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();

                if (!$project) {
                    $admin_flash['err'] = 'Project not found.';
                } else {
                    $title     = (string) ($project['title'] ?? 'your research');
                    $short     = mb_substr($title, 0, 60) . (mb_strlen($title) > 60 ? '…' : '');
                    $student_id = (int) ($project['created_by'] ?? 0);

                    // Recipients (owner + members + advisers, deduped).
                    $recipient_ids = [];
                    if ($student_id > 0) $recipient_ids[] = $student_id;
                    $ms = $conn->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
                    if ($ms) {
                        $ms->bind_param('i', $project_id);
                        $ms->execute();
                        $r = $ms->get_result();
                        while ($row = $r->fetch_assoc()) {
                            if ((int) $row['user_id'] > 0) $recipient_ids[] = (int) $row['user_id'];
                        }
                        $ms->close();
                    }
                    $as = $conn->prepare("SELECT adviser_id FROM project_advisers WHERE project_id = ?");
                    if ($as) {
                        $as->bind_param('i', $project_id);
                        $as->execute();
                        $r = $as->get_result();
                        while ($row = $r->fetch_assoc()) {
                            if ((int) $row['adviser_id'] > 0) $recipient_ids[] = (int) $row['adviser_id'];
                        }
                        $as->close();
                    }
                    $recipient_ids = array_values(array_unique($recipient_ids));

                    if ($action === 'final_approve') {
                        if (($project['status'] ?? '') !== 'approved') {
                            $admin_flash['err'] = 'Only projects with status "approved" (EREC-endorsed) can be granted final approval.';
                        } else {
                            // Build the UPDATE — include approved_by/approved_at only if the columns exist
                            $set_parts = ["status = 'ongoing'", "updated_at = NOW()"];
                            if ($rp_has_approved_by) $set_parts[] = "approved_by = ?";
                            if ($rp_has_approved_at) $set_parts[] = "approved_at = NOW()";
                            $where_parts = ["project_id = ?", "status = 'approved'"];
                            if ($rp_has_deleted_at) $where_parts[] = "deleted_at IS NULL";
                            $sql = "UPDATE research_projects SET " . implode(', ', $set_parts) . " WHERE " . implode(' AND ', $where_parts);
                            $upd = $conn->prepare($sql);
                            if ($rp_has_approved_by && $rp_has_approved_at) {
                                $upd->bind_param('ii', $user_id, $project_id);
                            } elseif ($rp_has_approved_by) {
                                $upd->bind_param('ii', $user_id, $project_id);
                            } else {
                                $upd->bind_param('i', $project_id);
                            }
                            $upd->execute();
                            $affected = $upd->affected_rows;
                            $upd->close();

                            if ($affected > 0) {
                                // Notify all stakeholders
                                foreach ($recipient_ids as $rid) {
                                    createNotification(
                                        (int) $rid,
                                        'Final approval granted — implementation begins',
                                        'The President/Administration has granted final approval for "' . $short . '". Implementation may now begin.',
                                        'success',
                                        SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
                                    );
                                }
                                logActivity(
                                    'Final approval granted for project #' . $project_id . ' ("' . $title . '") — moved to ongoing',
                                    'admin_final_approval'
                                );
                                $admin_flash['ok'] = 'Final approval granted. The project is now in implementation (ongoing).';
                            } else {
                                $admin_flash['err'] = 'Project is no longer awaiting final approval (it may have already been processed).';
                            }
                        }
                    } elseif ($action === 'final_return') {
                        $reason = trim((string) ($_POST['revision_reason'] ?? ''));
                        if (mb_strlen($reason) < 20) {
                            $admin_flash['err'] = 'Revision reason is required (minimum 20 characters).';
                        } elseif (($project['status'] ?? '') !== 'approved') {
                            $admin_flash['err'] = 'Only projects with status "approved" (EREC-endorsed) can be returned for revision.';
                        } else {
                            $upd = $conn->prepare("UPDATE research_projects SET status = 'for_revision', updated_at = NOW() WHERE project_id = ? AND status = 'approved'" . $rp_deleted_filter);
                            $upd->bind_param('i', $project_id);
                            $upd->execute();
                            $affected = $upd->affected_rows;
                            $upd->close();

                            if ($affected > 0) {
                                // Record the decision detail. Always log to activity_log;
                                // also persist into the comments table (mirrors the
                                // faculty-review-detail pattern of writing project-level
                                // notes with chapter_id = NULL). admin user_id stored in
                                // faculty_id column — comments.faculty_id is just an actor
                                // id in this usage and the type enum accepts 'general'.
                                $cmt = $conn->prepare("INSERT INTO comments (chapter_id, faculty_id, comment, type) VALUES (NULL, ?, ?, 'general')");
                                if ($cmt) {
                                    $decision_text = 'President/Administration returned for final-approval revision: ' . $reason;
                                    $cmt->bind_param('is', $user_id, $decision_text);
                                    $cmt->execute();
                                    $cmt->close();
                                }
                                foreach ($recipient_ids as $rid) {
                                    createNotification(
                                        (int) $rid,
                                        'Returned for final-approval revision',
                                        'The President/Administration returned "' . $short . '" for revision at the final-approval stage. Reason: ' . $reason,
                                        'warning',
                                        SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
                                    );
                                }
                                logActivity(
                                    'Returned project #' . $project_id . ' ("' . $title . '") for final-approval revision: ' . $reason,
                                    'admin_final_approval'
                                );
                                $admin_flash['ok'] = 'Project returned for revision. The student has been notified.';
                            } else {
                                $admin_flash['err'] = 'Project is no longer awaiting final approval (it may have already been processed).';
                            }
                        }
                    } else {
                        $admin_flash['err'] = 'Unknown action.';
                    }
                }
            }
        }
    }
}

// ── Awaiting Final Approval list (status = 'approved') ──────────────────
$afa_sql = "
    SELECT rp.project_id, rp.title, rp.abstract, rp.status, rp.created_at, rp.updated_at,
           CONCAT(u.first_name, ' ', u.last_name) AS student_name,
           u.email AS student_email,
           (SELECT COUNT(*) FROM project_advisers pa WHERE pa.project_id = rp.project_id) AS adviser_count,
           (SELECT COUNT(*) FROM project_members   pm WHERE pm.project_id = rp.project_id) AS member_count
      FROM research_projects rp
      LEFT JOIN users u ON u.user_id = rp.created_by
     WHERE rp.status = 'approved'" . $rp_deleted_filter_alias . "
     ORDER BY rp.updated_at ASC
     LIMIT 50
";
$afa_result = $conn->query($afa_sql);
$awaiting_final = $afa_result ? $afa_result->fetch_all(MYSQLI_ASSOC) : [];
$awaiting_final_count = count($awaiting_final);

// Get all research projects with filters
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($status_filter)) {
    if ($status_filter === 'ongoing') {
        $where_clauses[] = "rp.status IN ('ongoing', 'in_progress')";
    } else {
        $where_clauses[] = "rp.status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
}

if (!empty($search)) {
    $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "
    SELECT rp.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           CONCAT(f.first_name, ' ', f.last_name) as adviser_name
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN project_advisers pa ON rp.project_id = pa.project_id
    LEFT JOIN users f ON pa.adviser_id = f.user_id
    {$where_sql}
    ORDER BY rp.created_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects = $stmt->get_result();
$visible_projects = $projects->num_rows;
$committee_review_total = $research_crec + $research_erec;
$finished_total = $research_completed + $research_archived;

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

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }

  .stat-number {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }

  .stat-label {
    font-size: 14px;
    color: var(--text-secondary, #64748B);
    font-weight: 500;
  }

  /* CARD */
  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
  }

  .card-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--charcoal, #111827);
  }

  /* FILTER BAR */
  .filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 24px;
  }

  .search-input {
    flex: 1;
    min-width: 250px;
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-surface, #F8FAFC);
  }

  .filter-select {
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-card, #FFFFFF);
    cursor: pointer;
  }

  /* BUTTON */
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
  }

  .btn-secondary {
    background: var(--bg-surface, #F8FAFC);
    color: var(--text-primary, #111827);
    border: 1px solid var(--border, #E5E7EB);
  }

  .btn-secondary:hover {
    background: #E5E7EB;
  }

  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
  }

  /* TABLE */
  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border, #E5E7EB);
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead {
    background: var(--bg-surface, #F8FAFC);
  }

  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748B);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid var(--border, #E5E7EB);
  }

  tr:hover {
    background: var(--bg-surface, #F8FAFC);
  }

  /* BADGE */
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .badge-draft { background: #F1F5F9; color: #64748B; }
  .badge-proposal { background: #DBEAFE; color: #2563EB; }
  .badge-crec { background: #DBEAFE; color: #3B82F6; }
  .badge-erec { background: #F3E8FF; color: #7C3AED; }
  .badge-approved { background: #DCFCE7; color: #16A34A; }
  .badge-ongoing { background: #D1FAE5; color: #059669; }
  .badge-completed { background: #D1FAE5; color: #059669; }
  .badge-archived { background: #F1F5F9; color: #475569; }

  /* Flash banner */
  .admin-flash {
    padding: 14px 18px; border-radius: 12px; margin-bottom: 24px;
    font-size: 14px; font-weight: 500;
  }
  .admin-flash-ok  { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
  .admin-flash-err { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }

  .btn-primary {
    background: var(--accent-primary, #F57C00);
    color: #ffffff;
  }
  .btn-primary:hover { background: var(--accent-hover, #EA580C); }

  .btn-danger {
    background: #DC2626;
    color: #ffffff;
  }
  .btn-danger:hover { background: #B91C1C; }

  .btn-warning {
    background: #EA580C;
    color: #ffffff;
  }
  .btn-warning:hover { background: #C2410C; }

  /* Modal (President/Administration final approval) */
  .afa-modal {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 9999;
    align-items: center; justify-content: center; padding: 16px;
  }
  .afa-modal.open { display: flex; }
  .afa-modal-content {
    background: #ffffff; border-radius: 16px; width: 100%; max-width: 560px;
    overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;
  }
  .afa-modal-header {
    padding: 20px 24px; border-bottom: 1px solid #E5E7EB;
    display: flex; justify-content: space-between; align-items: center;
  }
  .afa-modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #111827; }
  .afa-modal-close {
    background: none; border: none; font-size: 1.6rem; cursor: pointer;
    color: #64748B; width: 32px; height: 32px; display: flex; align-items: center;
    justify-content: center; border-radius: 8px;
  }
  .afa-modal-close:hover { background: #F1F5F9; }
  .afa-modal-body { padding: 20px 24px; overflow-y: auto; }
  .afa-modal-footer {
    padding: 16px 24px; border-top: 1px solid #E5E7EB;
    display: flex; justify-content: flex-end; gap: 8px;
  }
  .afa-form-group { margin-bottom: 16px; }
  .afa-form-label {
    display: block; font-size: 13px; font-weight: 600;
    color: #111827; margin-bottom: 6px;
  }
  .afa-form-control {
    width: 100%; padding: 10px 14px; border: 1px solid #E5E7EB;
    border-radius: 10px; font-size: 14px; font-family: inherit;
    color: #111827; background: #ffffff; box-sizing: border-box;
  }
  .afa-form-control:focus {
    outline: none; border-color: #F57C00;
    box-shadow: 0 0 0 3px rgba(245,124,0,0.15);
  }
  .afa-form-control.invalid { border-color: #EF4444; }
  .afa-form-help {
    display: block; font-size: 12px; color: #64748B; margin-top: 4px;
  }
  .afa-empty {
    text-align: center; padding: 40px 24px; color: #94A3B8;
  }
  .afa-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.6; }
  .afa-empty p { margin: 0; font-size: 14px; }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<style>
  .research-workspace { --ink:#182033; --gold:#d3a348; --line:#dfe5ed; --muted:#667085; max-width:1480px; margin:0 auto; color:var(--ink); }
  .research-workspace .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
  .research-hero { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr); gap:48px; min-height:340px; padding:52px 56px 60px; overflow:hidden; border-radius:24px 24px 8px 8px; background:radial-gradient(circle at 84% 12%,rgba(211,163,72,.2),transparent 28%),linear-gradient(135deg,#172033 0%,#1d2940 58%,#263149 100%); color:#fff; box-shadow:0 26px 60px rgba(24,32,51,.17); }
  .research-hero::after { content:''; position:absolute; inset:0; z-index:-1; opacity:.16; background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); pointer-events:none; }
  .research-kicker,.section-eyebrow,.metric-index { font:700 11px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.14em; text-transform:uppercase; }
  .research-kicker { margin-bottom:18px; color:#e8bd67; }
  .research-hero h2 { max-width:800px; margin:0; font-size:clamp(38px,4.6vw,66px); line-height:.98; letter-spacing:-.055em; text-wrap:balance; }
  .research-hero-copy>p { max-width:640px; margin:24px 0 0; color:#bdc7d7; font-size:15px; line-height:1.75; }
  .hero-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:28px; }
  .hero-note { display:inline-flex; align-items:center; gap:8px; margin-top:18px; color:#9eabbf; font-size:12px; }
  .hero-note::before { content:''; width:7px; height:7px; border-radius:50%; background:#70bd91; box-shadow:0 0 0 5px rgba(112,189,145,.12); }
  .lifecycle-snapshot { align-self:end; display:grid; gap:2px; }
  .snapshot-row { display:grid; grid-template-columns:42px 1fr auto; align-items:center; gap:12px; padding:14px 16px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.065); }
  .snapshot-row:first-child { border-radius:14px 14px 5px 5px; }
  .snapshot-row:last-child { border-radius:5px 5px 14px 14px; }
  .snapshot-code { color:#e8bd67; font:700 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .snapshot-label { color:#d5dce8; font-size:13px; }
  .snapshot-value { font-size:24px; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.03em; }

  .research-metrics { position:relative; z-index:2; display:grid; grid-template-columns:1.2fr repeat(4,1fr); gap:2px; margin:-22px 22px 0; }
  .research-metric { position:relative; min-height:142px; padding:24px 24px 21px; overflow:hidden; border:1px solid #e3e8ef; background:#fff; }
  .research-metric:first-child { border-radius:16px 5px 5px 16px; }
  .research-metric:last-child { border-radius:5px 16px 16px 5px; }
  .research-metric::after { content:''; position:absolute; right:-20px; bottom:-32px; width:76px; height:76px; border:17px solid var(--metric-accent,#64748b); border-radius:50%; opacity:.08; }
  .metric-total { --metric-accent:#172033; background:#fcfaf5; }.metric-proposal{--metric-accent:#315b8c}.metric-review{--metric-accent:#705487}.metric-implementation{--metric-accent:#347451}.metric-finished{--metric-accent:#8b6528}
  .metric-index { margin-bottom:24px; color:var(--metric-accent); }
  .metric-value { font-size:34px; font-weight:720; line-height:1; letter-spacing:-.04em; font-variant-numeric:tabular-nums; }
  .metric-label { margin-top:8px; color:var(--muted); font-size:13px; font-weight:600; }

  .decision-card,.research-directory { margin-top:38px; overflow:hidden; border:1px solid var(--line); border-radius:18px; background:#fff; box-shadow:0 14px 38px rgba(31,42,63,.07); }
  .research-directory { margin-top:24px; }
  .decision-header,.directory-header { display:flex; justify-content:space-between; align-items:end; gap:24px; padding:30px 32px 24px; }
  .decision-header { align-items:center; border-bottom:1px solid #e8ecf1; background:linear-gradient(90deg,#fbf7ed,#fff 72%); }
  .section-eyebrow { margin-bottom:9px; color:#9a7228; }
  .section-title { margin:0; color:var(--ink); font-size:25px; line-height:1.1; letter-spacing:-.025em; }
  .section-copy { max-width:720px; margin:9px 0 0; color:var(--muted); font-size:13px; line-height:1.6; }
  .queue-count { display:grid; place-items:center; min-width:78px; min-height:68px; padding:10px; border:1px solid #e5d5b3; border-radius:12px; background:#fff; color:#8a6424; text-align:center; }
  .queue-count strong { display:block; font-size:24px; line-height:1; font-variant-numeric:tabular-nums; }
  .queue-count span { display:block; margin-top:5px; font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }

  .research-workspace .table-wrap { overflow-x:auto; border:0; border-radius:0; }
  .research-table { width:100%; min-width:1040px; border-collapse:collapse; }
  .decision-table { min-width:1120px; }
  .research-table thead { background:#fafbfc; }
  .research-table th { padding:12px 16px; color:#7a8494; font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.11em; text-align:left; text-transform:uppercase; }
  .research-table th:first-child,.research-table td:first-child { padding-left:32px; }
  .research-table th:last-child,.research-table td:last-child { padding-right:32px; }
  .research-table td { padding:18px 16px; border-top:1px solid #edf0f4; color:#475467; font-size:13px; vertical-align:middle; }
  .research-table tbody tr { transition:background .2s ease; }
  .research-table tbody tr:hover { background:#fbfaf7; }
  .project-title { display:block; max-width:430px; color:#20293b; font-size:14px; font-weight:680; line-height:1.4; text-decoration:none; text-wrap:pretty; }
  .project-title:hover { color:#8a6424; }
  .project-ref { margin-top:5px; color:#8a94a3; font:600 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .person-name { color:#20293b; font-weight:650; }
  .person-meta,.stakeholder-meta { margin-top:4px; color:#8490a1; font-size:11px; }
  .date-cell { white-space:nowrap; color:#687386; font-variant-numeric:tabular-nums; }
  .row-actions { display:flex; flex-wrap:wrap; gap:7px; min-width:280px; }
  .research-workspace .badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border:1px solid transparent; border-radius:6px; font-size:11px; font-weight:680; white-space:nowrap; }
  .research-workspace .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; opacity:.65; }
  .research-workspace .badge-draft{border-color:#e3e7eb;background:#f3f5f7;color:#6b7280}.research-workspace .badge-proposal{border-color:#d8e5f0;background:#edf4fa;color:#315b8c}.research-workspace .badge-crec{border-color:#d8e5f0;background:#edf4fa;color:#315b8c}.research-workspace .badge-erec{border-color:#e7dcec;background:#f5f0f7;color:#705487}.research-workspace .badge-approved{border-color:#eadaba;background:#faf4e8;color:#8b6528}.research-workspace .badge-ongoing,.research-workspace .badge-completed{border-color:#d4ebdc;background:#edf8f1;color:#347451}.research-workspace .badge-archived{border-color:#dfe3e8;background:#f4f5f6;color:#525e6d}

  .research-workspace .filter-bar { display:grid; grid-template-columns:minmax(280px,1fr) 210px auto auto; gap:10px; align-items:center; margin:0; padding:14px 32px; border-top:1px solid #edf0f4; border-bottom:1px solid #e5e9ef; background:#f5f7fa; }
  .search-field { position:relative; }
  .search-field::before { content:'\2315'; position:absolute; left:15px; top:50%; transform:translateY(-52%) rotate(-20deg); color:#7b8798; font-size:19px; pointer-events:none; }
  .research-workspace .search-input,.research-workspace .filter-select { width:100%; min-width:0; min-height:44px; box-sizing:border-box; border:1px solid #d9e0e8; border-radius:9px; background:#fff; color:#000; font-family:inherit; font-size:14px; font-weight:500; }
  .research-workspace .search-input { padding:10px 14px 10px 43px; }.research-workspace .filter-select{padding:10px 13px}
  .research-workspace .search-input:focus,.research-workspace .filter-select:focus { outline:none; border-color:#bc8e37; box-shadow:0 0 0 3px rgba(211,163,72,.16); }
  .research-workspace .btn,.afa-modal .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:9px 16px; border:1px solid transparent; border-radius:8px; font-family:inherit; font-size:13px; font-weight:650; line-height:1.2; text-decoration:none; cursor:pointer; transition:transform .2s ease,border-color .2s ease,background .2s ease,color .2s ease,box-shadow .2s ease; }
  .research-workspace .btn:hover,.afa-modal .btn:hover { transform:translateY(-1px); }.research-workspace .btn:active,.afa-modal .btn:active{transform:translateY(0) scale(.98)}
  .research-workspace .btn:focus-visible,.afa-modal .btn:focus-visible { outline:3px solid rgba(211,163,72,.28); outline-offset:2px; }
  .research-workspace .btn-primary,.afa-modal .btn-primary { border-color:#d3a348; background:#d3a348; color:#182033; box-shadow:0 8px 20px rgba(211,163,72,.16); }
  .research-workspace .btn-primary:hover,.afa-modal .btn-primary:hover { border-color:#dfb45f; background:#dfb45f; }.research-workspace .btn-secondary,.afa-modal .btn-secondary{border-color:#dce2e9;background:#fff;color:#344054}.research-workspace .btn-secondary:hover,.afa-modal .btn-secondary:hover{border-color:#b9c2ce;background:#f7f8fa}
  .research-workspace .btn-sm { min-height:34px; padding:7px 11px; font-size:12px; }.research-workspace .btn-warning,.afa-modal .btn-warning{border-color:#ead1c4;background:#fff5f0;color:#9f4c2d}.research-workspace .btn-warning:hover,.afa-modal .btn-warning:hover{border-color:#d89c83;background:#fae9e1}
  .hero-action{min-height:45px!important;padding-inline:18px!important}.hero-action-primary{background:#d3a348!important;color:#182033!important}.hero-action-secondary{border-color:rgba(255,255,255,.18)!important;background:rgba(255,255,255,.055)!important;color:#fff!important}.hero-action-secondary:hover{border-color:#e8bd67!important;background:rgba(255,255,255,.1)!important}
  .empty-state { padding:54px 24px; text-align:center; }.empty-state-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#8b6528;font:700 16px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.empty-state strong{display:block;color:#344054;font-size:16px}.empty-state p{max-width:520px;margin:7px auto 0;color:#8490a1;font-size:13px;line-height:1.55}
  .admin-flash { display:flex; align-items:center; gap:10px; margin:0 0 22px; padding:14px 16px; border-radius:10px; font-size:14px; }.research-metrics+.admin-flash{margin-top:38px}.admin-flash-ok{border-color:#bcdcc9;background:#edf8f1;color:#2f6d4c}.admin-flash-err{border-color:#e8c5bc;background:#fff3f0;color:#9b3f2d}.flash-mark{display:grid;place-items:center;width:25px;height:25px;border-radius:7px;background:rgba(255,255,255,.65);font-weight:800}

  .afa-modal { z-index:1000; padding:24px; background:rgba(12,18,29,.68); backdrop-filter:blur(7px); }
  .afa-modal.open { animation:modal-fade .18s ease both; }
  .afa-modal-content { max-width:600px; max-height:calc(100dvh - 48px); border:1px solid rgba(255,255,255,.6); border-radius:18px; box-shadow:0 30px 80px rgba(9,15,27,.3); animation:modal-rise .25s cubic-bezier(.2,.8,.2,1) both; }
  .afa-modal-header { position:relative; padding:26px 30px 23px; overflow:hidden; border:0; background:#182033; color:#fff; }.afa-modal-header::after{content:'';position:absolute;right:-42px;top:-72px;width:170px;height:170px;border:30px solid rgba(211,163,72,.17);border-radius:50%}.afa-modal-header h3{position:relative;z-index:1;color:#fff;font-size:23px;letter-spacing:-.025em}.afa-modal-close{position:relative;z-index:2;color:#fff}.afa-modal-close:hover{background:rgba(255,255,255,.1)}
  .afa-modal-body { padding:26px 30px; }.afa-modal-footer{padding:20px 30px 26px}.afa-modal-footer .btn-secondary{border:2px solid #182033;background:#fff;color:#000;font-weight:700}.afa-modal-footer .btn-secondary:hover{background:#182033;color:#fff}.afa-form-label,.afa-form-help{color:#000!important}.afa-form-help{font-weight:600}.afa-form-control{border-color:#d9e0e8;color:#000!important}.afa-form-control:focus{border-color:#bc8e37;box-shadow:0 0 0 3px rgba(211,163,72,.16)}
  @keyframes modal-fade{from{opacity:0}to{opacity:1}}@keyframes modal-rise{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}

  @media(max-width:1120px){.research-hero{grid-template-columns:1fr;gap:32px}.lifecycle-snapshot{grid-template-columns:repeat(3,1fr)}.snapshot-row{grid-template-columns:34px 1fr}.snapshot-value{grid-column:2}.research-metrics{grid-template-columns:repeat(5,minmax(150px,1fr));overflow-x:auto}}
  @media(max-width:768px){.research-hero{min-height:0;padding:30px 24px 50px;border-radius:18px 18px 7px 7px}.research-hero h2{font-size:38px}.lifecycle-snapshot{grid-template-columns:1fr}.snapshot-row{grid-template-columns:36px 1fr auto}.snapshot-value{grid-column:auto}.research-metrics{margin:-18px 12px 0}.research-metric{min-width:150px}.decision-header,.directory-header{align-items:stretch;padding:25px 20px 20px;flex-direction:column}.queue-count{width:100%;grid-template-columns:auto auto;justify-content:center;gap:8px;min-height:48px}.queue-count span{margin:0}.research-workspace .filter-bar{grid-template-columns:1fr;padding:14px 20px 18px}.research-workspace .filter-bar .btn{width:100%}.research-table{min-width:0}.research-table thead{display:none}.research-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.research-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.research-table tbody td{display:grid;grid-template-columns:92px minmax(0,1fr);width:100%;padding:12px 14px;border-top:1px solid #edf0f4;text-align:left;overflow-wrap:anywhere}.research-table tbody td:first-child{padding:16px 14px;border-top:0}.research-table tbody td:last-child{padding:14px}.research-table tbody td::before{content:attr(data-label);margin:2px 12px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.research-table tbody td:first-child::before{display:none}.row-actions{min-width:0}.row-actions .btn,.row-actions form{flex:1 1 auto}.row-actions form .btn{width:100%}.empty-row{display:block!important}.empty-row::before{display:none}.afa-modal{padding:12px}.afa-modal-content{max-height:calc(100dvh - 24px)}.afa-modal-header,.afa-modal-body,.afa-modal-footer{padding-left:22px;padding-right:22px}}
  @media(prefers-reduced-motion:reduce){.research-workspace .btn,.research-table tbody tr{transition:none}.afa-modal.open,.afa-modal-content{animation:none}}
</style>
<?php

renderAdminShell(
    $user,
    'admin-research',
    'Research Management',
    'Institute-wide research lifecycle and final administrative decisions.'
);
?>

<div class="research-workspace">

    <section class="research-hero" aria-labelledby="research-command-title">
      <div class="research-hero-copy">
        <div class="research-kicker">Research lifecycle &middot; Administrative command</div>
        <h2 id="research-command-title">Every project, every gate, one clear line of sight.</h2>
        <p>Track institute research from proposal intake through committee review, final administrative approval, implementation, and completion.</p>
        <div class="hero-actions">
          <a class="btn hero-action hero-action-primary" href="#decision-queue">Open decision queue <span aria-hidden="true">&#8594;</span></a>
          <a class="btn hero-action hero-action-secondary" href="#research-directory">Browse all projects</a>
        </div>
        <div class="hero-note">Final approval is available only after EREC endorsement.</div>
      </div>

      <div class="lifecycle-snapshot" aria-label="Research lifecycle snapshot">
        <div class="snapshot-row"><span class="snapshot-code">01</span><span class="snapshot-label">Proposal intake</span><strong class="snapshot-value"><?php echo $research_proposal; ?></strong></div>
        <div class="snapshot-row"><span class="snapshot-code">02</span><span class="snapshot-label">Committee review</span><strong class="snapshot-value"><?php echo $committee_review_total; ?></strong></div>
        <div class="snapshot-row"><span class="snapshot-code">03</span><span class="snapshot-label">Awaiting decision</span><strong class="snapshot-value"><?php echo $research_approved; ?></strong></div>
      </div>
    </section>

    <section class="research-metrics" aria-label="Research totals">
      <article class="research-metric metric-total"><div class="metric-index">Portfolio</div><div class="metric-value"><?php echo $total_research; ?></div><div class="metric-label">Total projects</div></article>
      <article class="research-metric metric-proposal"><div class="metric-index">Intake</div><div class="metric-value"><?php echo $research_proposal; ?></div><div class="metric-label">Proposals</div></article>
      <article class="research-metric metric-review"><div class="metric-index">Committees</div><div class="metric-value"><?php echo $committee_review_total; ?></div><div class="metric-label">Under review</div></article>
      <article class="research-metric metric-implementation"><div class="metric-index">Delivery</div><div class="metric-value"><?php echo $research_ongoing; ?></div><div class="metric-label">In implementation</div></article>
      <article class="research-metric metric-finished"><div class="metric-index">Record</div><div class="metric-value"><?php echo $finished_total; ?></div><div class="metric-label">Completed or archived</div></article>
    </section>

    <?php if ($admin_flash['ok'] !== ''): ?>
      <div class="admin-flash admin-flash-ok"><span class="flash-mark" aria-hidden="true">&#10003;</span><?php echo htmlspecialchars($admin_flash['ok'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($admin_flash['err'] !== ''): ?>
      <div class="admin-flash admin-flash-err"><span class="flash-mark" aria-hidden="true">&#215;</span><?php echo htmlspecialchars($admin_flash['err'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- AWAITING FINAL APPROVAL -->
    <section class="decision-card" id="decision-queue" aria-labelledby="decision-title">
      <div class="decision-header">
        <div>
          <div class="section-eyebrow">President / Administration gate</div>
          <h3 class="section-title" id="decision-title">Awaiting final approval</h3>
          <p class="section-copy">EREC-endorsed projects awaiting the institute's final administrative decision before implementation may begin.</p>
        </div>
        <div class="queue-count">
          <strong><?php echo (int) $awaiting_final_count; ?></strong>
          <span>project<?php echo $awaiting_final_count !== 1 ? 's' : ''; ?></span>
        </div>
      </div>

      <?php if (empty($awaiting_final)): ?>
        <div class="empty-state">
          <div class="empty-state-mark" aria-hidden="true">OK</div>
          <strong>The decision queue is clear</strong>
          <p>Projects appear here after EREC endorsement and remain until the President or Administration grants final approval or returns them for revision.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="research-table decision-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Student</th>
                <th>Stakeholders</th>
                <th>Endorsed</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($awaiting_final as $row):
                $p_id   = (int) $row['project_id'];
                $pname  = (string) ($row['student_name'] ?? '—');
                $adviser_count = (int) ($row['adviser_count'] ?? 0);
                $member_count  = (int) ($row['member_count']  ?? 0);
                $endorsed_at   = !empty($row['updated_at'])
                    ? date('M d, Y · h:i A', strtotime((string) $row['updated_at']))
                    : '—';
              ?>
                <tr>
                  <td data-label="Project">
                    <a class="project-title" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>">
                      <?php echo htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <div class="project-ref">PROJECT #<?php echo $p_id; ?> · EREC ENDORSED</div>
                  </td>
                  <td data-label="Proponent">
                    <div class="person-name"><?php echo htmlspecialchars($pname, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="person-meta">Lead proponent</div>
                  </td>
                  <td data-label="Stakeholders">
                    <div class="stakeholder-meta">
                    <?php echo $adviser_count; ?> adviser<?php echo $adviser_count !== 1 ? 's' : ''; ?>
                    · <?php echo $member_count; ?> member<?php echo $member_count !== 1 ? 's' : ''; ?>
                    </div>
                  </td>
                  <td data-label="Endorsed" class="date-cell"><?php echo htmlspecialchars($endorsed_at, ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="Actions">
                    <div class="row-actions">
                      <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="final_approve">
                        <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                        <button type="submit" class="btn btn-primary btn-sm"
                                title="Grant President/Administration final approval — moves project to Implementation (ongoing)">
                          Grant final approval
                        </button>
                      </form>
                      <button type="button" class="btn btn-warning btn-sm"
                              onclick='openAfaReturnModal(<?php echo $p_id; ?>, <?php echo json_encode((string) $row['title'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                        Return for revision
                      </button>
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>" class="btn btn-secondary btn-sm">View</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Return-for-revision modal (final approval) -->
    <div id="afaReturnModal" class="afa-modal" role="dialog" aria-modal="true" aria-labelledby="afaReturnTitle" aria-hidden="true">
      <div class="afa-modal-content" tabindex="-1">
        <form method="POST" id="afaReturnForm">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="final_return">
          <input type="hidden" name="project_id" id="afa_return_project_id" value="">

          <div class="afa-modal-header">
            <h3 id="afaReturnTitle">Return for final-approval revision</h3>
            <button type="button" class="afa-modal-close" onclick="closeAfaReturnModal()" aria-label="Close">&times;</button>
          </div>
          <div class="afa-modal-body">
            <p class="section-copy" style="margin-top: 0; margin-bottom: 16px;">
              Returning: <strong id="afa_return_title"></strong>
            </p>
            <div class="afa-form-group">
              <label class="afa-form-label" for="afa_revision_reason">Reason <span style="color: #EF4444;">*</span></label>
              <textarea id="afa_revision_reason" name="revision_reason" class="afa-form-control" rows="5" minlength="20" required
                        placeholder="Explain what the proponent needs to address (minimum 20 characters)"></textarea>
              <span class="afa-form-help">This will be recorded in the project's audit trail and sent to the student, members, and advisers as a notification.</span>
            </div>
          </div>
          <div class="afa-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAfaReturnModal()">Cancel</button>
            <button type="submit" class="btn btn-warning">Return to proponent</button>
          </div>
        </form>
      </div>
    </div>

    <!-- RESEARCH TABLE CARD -->
    <section class="research-directory" id="research-directory" aria-labelledby="research-directory-title">
      <div class="directory-header">
        <div>
          <div class="section-eyebrow">Institute portfolio</div>
          <h3 class="section-title" id="research-directory-title">All research projects</h3>
          <p class="section-copy"><?php echo $visible_projects; ?> project<?php echo $visible_projects === 1 ? '' : 's'; ?> shown<?php echo (!empty($search) || !empty($status_filter)) ? ' for the current filters' : ''; ?>.</p>
        </div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-research.php" class="filter-bar">
        <div class="search-field">
          <label for="researchSearch" class="sr-only">Search projects</label>
          <input id="researchSearch" type="search" name="search" class="search-input" placeholder="Search title or proponent" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label for="researchStatus" class="sr-only">Filter by project status</label>
        <select id="researchStatus" name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="proposal" <?php echo $status_filter === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
          <option value="under_crec_review" <?php echo $status_filter === 'under_crec_review' ? 'selected' : ''; ?>>CREC Review</option>
          <option value="under_erec_review" <?php echo $status_filter === 'under_erec_review' ? 'selected' : ''; ?>>EREC Review</option>
          <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
          <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
          <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
        <?php if (!empty($search) || !empty($status_filter)): ?>
          <a href="admin-research.php" class="btn btn-secondary">Clear filters</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table class="research-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Student</th>
              <th>Adviser</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($projects->num_rows > 0): ?>
              <?php while ($project = $projects->fetch_assoc()): ?>
                <tr>
                  <td data-label="Project">
                    <a class="project-title" href="../shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>"><?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <div class="project-ref">PROJECT #<?php echo (int) $project['project_id']; ?></div>
                  </td>
                  <td data-label="Proponent"><span class="person-name"><?php echo htmlspecialchars($project['student_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="Adviser"><?php echo htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="Status">
                    <?php
                    $status_badges = [
                      'draft' => 'badge-draft',
                      'proposal' => 'badge-proposal',
                      'under_crec_review' => 'badge-crec',
                      'under_erec_review' => 'badge-erec',
                      'approved' => 'badge-approved',
                      'ongoing' => 'badge-ongoing',
                      'in_progress' => 'badge-ongoing',
                      'completed' => 'badge-completed',
                      'archived' => 'badge-archived'
                    ];
                    $status_labels = [
                      'draft' => 'Draft',
                      'proposal' => 'Proposal',
                      'under_crec_review' => 'CREC Review',
                      'under_erec_review' => 'EREC Review',
                      'approved' => 'Approved',
                      'ongoing' => 'Ongoing',
                      'in_progress' => 'In Progress',
                      'completed' => 'Completed',
                      'archived' => 'Archived'
                    ];
                    $badge_class = $status_badges[$project['status']] ?? 'badge-draft';
                    $status_label = $status_labels[$project['status']] ?? ucfirst($project['status']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
                  </td>
                  <td data-label="Created" class="date-cell"><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                  <td data-label="Actions">
                    <a href="../shared/research-detail.php?id=<?php echo $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="empty-row">
                  <div class="empty-state">
                    <div class="empty-state-mark" aria-hidden="true">00</div>
                    <strong>No matching research projects</strong>
                    <p>Adjust the title, proponent, or lifecycle-status filter.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
</div>

<script>
  // ── Awaiting Final Approval — Return-for-revision modal ───────────────
  (function () {
    const modal  = document.getElementById('afaReturnModal');
    const form   = document.getElementById('afaReturnForm');
    const pidEl  = document.getElementById('afa_return_project_id');
    const titleEl = document.getElementById('afa_return_title');
    const reasonEl = document.getElementById('afa_revision_reason');
    let lastFocusedElement = null;

    window.openAfaReturnModal = function (projectId, title) {
      lastFocusedElement = document.activeElement;
      pidEl.value = projectId;
      titleEl.textContent = title;
      reasonEl.value = '';
      reasonEl.classList.remove('invalid');
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => reasonEl.focus(), 50);
    };
    window.closeAfaReturnModal = function () {
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocusedElement) lastFocusedElement.focus();
    };

    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.classList.contains('open')) {
        closeAfaReturnModal();
      }
    });
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeAfaReturnModal();
    });
    // Client-side length guard
    form.addEventListener('submit', (e) => {
      const v = reasonEl.value.trim();
      if (v.length < 20) {
        e.preventDefault();
        reasonEl.classList.add('invalid');
        reasonEl.focus();
      }
    });
    reasonEl.addEventListener('input', () => reasonEl.classList.remove('invalid'));
  })();
</script>

<?php
renderAdminShellClose();
?>
