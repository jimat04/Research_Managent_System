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
$research_ongoing = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'in_progress'")->fetch_assoc()['count'] ?? 0);
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
    $where_clauses[] = "rp.status = ?";
    $params[] = $status_filter;
    $types .= 's';
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
<?php

renderAdminShell(
    $user,
    'admin-research',
    'Research Management',
    'Oversee all research projects across the university.'
);
?>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_research; ?></div>
        <div class="stat-label">Total Projects</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_proposal; ?></div>
        <div class="stat-label">Proposals</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_crec + $research_erec; ?></div>
        <div class="stat-label">Under Review</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_approved; ?></div>
        <div class="stat-label">Awaiting Final Approval</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_ongoing; ?></div>
        <div class="stat-label">In Progress</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $research_completed; ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>

    <?php if ($admin_flash['ok'] !== ''): ?>
      <div class="admin-flash admin-flash-ok">✓ <?php echo htmlspecialchars($admin_flash['ok'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($admin_flash['err'] !== ''): ?>
      <div class="admin-flash admin-flash-err">✕ <?php echo htmlspecialchars($admin_flash['err'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- AWAITING FINAL APPROVAL -->
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">🛡️ Awaiting Final Approval</div>
          <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-secondary, #64748B);">
            Projects endorsed by EREC and awaiting the President/Administration final approval gate
            (EARIST Research Manual 2015, step 5 — President Approval).
          </p>
        </div>
        <div style="font-size: 13px; color: var(--text-secondary, #64748B); font-weight: 500;">
          <?php echo (int) $awaiting_final_count; ?> project<?php echo $awaiting_final_count !== 1 ? 's' : ''; ?>
        </div>
      </div>

      <?php if (empty($awaiting_final)): ?>
        <div class="afa-empty">
          <div class="afa-empty-icon">🛡️</div>
          <p>No projects are currently awaiting final approval.</p>
          <p style="margin-top: 6px; font-size: 13px;">When a faculty member endorses a project through EREC, it will appear here for the President/Administration gate.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Title</th>
                <th>Student</th>
                <th>Stakeholders</th>
                <th>Endorsed</th>
                <th style="min-width: 280px;">Actions</th>
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
                $js_title = addslashes((string) $row['title']);
              ?>
                <tr>
                  <td style="font-weight: 500;">
                    <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $p_id; ?>"
                       style="color: #111827; text-decoration: none;">
                      <?php echo htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  </td>
                  <td>
                    <div style="font-weight: 500; color: #111827;"><?php echo htmlspecialchars($pname, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div style="font-size: 12px; color: #64748B;">🎒 Owner</div>
                  </td>
                  <td style="font-size: 13px; color: #64748B;">
                    <?php echo $adviser_count; ?> adviser<?php echo $adviser_count !== 1 ? 's' : ''; ?>
                    · <?php echo $member_count; ?> member<?php echo $member_count !== 1 ? 's' : ''; ?>
                  </td>
                  <td style="font-size: 13px; color: #64748B; white-space: nowrap;"><?php echo htmlspecialchars($endorsed_at, ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <div class="filter-bar" style="margin: 0;">
                      <form method="POST" style="display: inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="final_approve">
                        <input type="hidden" name="project_id" value="<?php echo $p_id; ?>">
                        <button type="submit" class="btn btn-primary btn-sm"
                                title="Grant President/Administration final approval — moves project to Implementation (ongoing)">
                          ✓ Grant Final Approval
                        </button>
                      </form>
                      <button type="button" class="btn btn-warning btn-sm"
                              onclick="openAfaReturnModal(<?php echo $p_id; ?>, '<?php echo htmlspecialchars($js_title, ENT_QUOTES, 'UTF-8'); ?>')">
                        ↩ Return for Revision
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
    </div>

    <!-- Return-for-revision modal (final approval) -->
    <div id="afaReturnModal" class="afa-modal" role="dialog" aria-modal="true" aria-labelledby="afaReturnTitle">
      <div class="afa-modal-content">
        <form method="POST" id="afaReturnForm">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="final_return">
          <input type="hidden" name="project_id" id="afa_return_project_id" value="">

          <div class="afa-modal-header">
            <h3 id="afaReturnTitle">↩ Return for Final-Approval Revision</h3>
            <button type="button" class="afa-modal-close" onclick="closeAfaReturnModal()" aria-label="Close">×</button>
          </div>
          <div class="afa-modal-body">
            <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
              Returning: <strong id="afa_return_title" style="color: #111827;"></strong>
            </p>
            <div class="afa-form-group">
              <label class="afa-form-label" for="afa_revision_reason">Reason <span style="color: #EF4444;">*</span></label>
              <textarea id="afa_revision_reason" name="revision_reason" class="afa-form-control" rows="5" minlength="20" required
                        placeholder="Explain what the student needs to address (min. 20 characters)…"></textarea>
              <span class="afa-form-help">This will be recorded in the project's audit trail and sent to the student, members, and advisers as a notification.</span>
            </div>
          </div>
          <div class="afa-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAfaReturnModal()">Cancel</button>
            <button type="submit" class="btn btn-warning">Return to Student</button>
          </div>
        </form>
      </div>
    </div>

    <!-- RESEARCH TABLE CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Research Projects</div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-research.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by title or student name..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="proposal" <?php echo $status_filter === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
          <option value="under_crec_review" <?php echo $status_filter === 'under_crec_review' ? 'selected' : ''; ?>>CREC Review</option>
          <option value="under_erec_review" <?php echo $status_filter === 'under_erec_review' ? 'selected' : ''; ?>>EREC Review</option>
          <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
          <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
          <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($status_filter)): ?>
          <a href="admin-research.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
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
                  <td style="font-weight: 500;">
                    <?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td><?php echo htmlspecialchars($project['student_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php
                    $status_badges = [
                      'draft' => 'badge-draft',
                      'proposal' => 'badge-proposal',
                      'under_crec_review' => 'badge-crec',
                      'under_erec_review' => 'badge-erec',
                      'approved' => 'badge-approved',
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
                      'in_progress' => 'In Progress',
                      'completed' => 'Completed',
                      'archived' => 'Archived'
                    ];
                    $badge_class = $status_badges[$project['status']] ?? 'badge-draft';
                    $status_label = $status_labels[$project['status']] ?? ucfirst($project['status']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                  <td>
                    <a href="../shared/research-detail.php?id=<?php echo $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
                  No research projects found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php
renderAdminShellClose();
?>
<script>
  // ── Awaiting Final Approval — Return-for-revision modal ───────────────
  (function () {
    const modal  = document.getElementById('afaReturnModal');
    const form   = document.getElementById('afaReturnForm');
    const pidEl  = document.getElementById('afa_return_project_id');
    const titleEl = document.getElementById('afa_return_title');
    const reasonEl = document.getElementById('afa_revision_reason');

    window.openAfaReturnModal = function (projectId, title) {
      pidEl.value = projectId;
      titleEl.textContent = title;
      reasonEl.value = '';
      reasonEl.classList.remove('invalid');
      modal.classList.add('open');
      setTimeout(() => reasonEl.focus(), 50);
    };
    window.closeAfaReturnModal = function () {
      modal.classList.remove('open');
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
