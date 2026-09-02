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

                if ($action === 'forward_to_crec') {
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

$valid_ranges = ['all', 'week', 'month'];
if (!in_array($range, $valid_ranges, true)) {
    $range = 'all';
}

// ── pagination ───────────────────────────────────────────────────────────
$per_page = 20;
$page     = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// ── build WHERE clause dynamically ──────────────────────────────────────
$where  = ["rp.status = 'submitted'"];
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
$cat_result = $conn->query("
    SELECT category_id, category_name
      FROM research_categories
     WHERE status = 1
     ORDER BY category_name ASC
");
$categories = $cat_result ? $cat_result->fetch_all(MYSQLI_ASSOC) : [];

// Build a query string for pagination links that preserves filters
function submissions_filter_qs(array $overrides = []): string {
    $base = [
        'q'        => $_GET['q']        ?? '',
        'category' => $_GET['category'] ?? '',
        'range'    => $_GET['range']    ?? 'all',
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
    <div class="lbl">Pending verification<?php echo $total_pending !== 1 ? 's' : ''; ?></div>
  </div>
</div>

<!-- Filter bar -->
<form method="GET" class="filter-bar" action="">
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
      <p>No pending submissions match your filters.</p>
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
