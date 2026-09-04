<?php
/**
 * Faculty — My Submissions
 *
 * Lists every research project where the logged-in faculty is:
 *   - an adviser (via project_advisers.adviser_id), OR
 *   - an assigned CREC/EREC reviewer (via project_reviews.reviewer_id)
 *
 * Per row: title, student owner(s), status badge, chapter progress
 * (X/5 chapters approved), last activity date, and a context-aware link
 * to the right detail page:
 *   - adviser         -> faculty-review-detail.php?id={project_id}
 *   - CREC/EREC review-> faculty-score-review.php?id={review_id}
 *
 * Filters (GET):
 *   ?status={all|one of the current research_projects workflow statuses}
 *   ?q={text search on title}
 *
 * Summary stat cards: total assigned, pending review, in progress, completed.
 *
 * Page is read-only; no POSTs. All queries are prepared statements.
 * Only soft-deleted rows are filtered out when the column is present.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function fsub_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------
// Defensive column detection — research_projects.deleted_at may or may
// not exist depending on whether the migration has been applied.
// ------------------------------------------------------------------
$rp_has_deleted_at = false;
$col_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($col_stmt) {
    $col_stmt->execute();
    $rp_has_deleted_at = $col_stmt->get_result()->num_rows > 0;
    $col_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// Is the project_reviews table available? (created by migration 006)
$has_project_reviews = false;
$tbl_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
if ($tbl_stmt) {
    $tbl_stmt->execute();
    $tbl_stmt->bind_result($tbl);
    while ($tbl_stmt->fetch()) { $has_project_reviews = ($tbl === 'project_reviews'); }
    $tbl_stmt->close();
}

// ------------------------------------------------------------------
// Read filters from GET
// ------------------------------------------------------------------
$status_filter = (string) ($_GET['status'] ?? 'all');
$status_options = [
    'draft'               => 'Draft',
    'proposal'            => 'Proposal',
    'submitted'           => 'Submitted',
    'under_review'        => 'Under Review',
    'under_crec_review'   => 'CREC Review',
    'under_erec_review'   => 'EREC Review',
    'for_revision'        => 'For Revision',
    'revision_required'   => 'Revision Required',
    'rejected'            => 'Rejected',
    'approved'            => 'Approved',
    'ongoing'             => 'Ongoing',
    'progress_report'     => 'Progress Report',
    'terminal_review'     => 'Terminal Review',
    'completed'           => 'Completed',
    'archived'            => 'Archived',
];
$allowed_statuses = array_keys($status_options);
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

$q_raw     = (string) ($_GET['q'] ?? '');
$q_trimmed = trim($q_raw);
$q_like    = '%' . $q_trimmed . '%';

// ------------------------------------------------------------------
// Build the base query. We pull the same row from two sources
// (adviser / reviewer) and UNION them. project_id is the dedupe key;
// if both apply we keep the adviser row (its `role_kind` is 'adviser'
// and a faculty is more often reviewing as adviser).
// ------------------------------------------------------------------
$rows   = [];
$errors = [];

$sql =
    "SELECT * FROM (
        SELECT
            rp.project_id,
            rp.title,
            rp.status,
            rp.created_by,
            rp.created_at AS rp_created_at,
            rp.updated_at AS rp_updated_at,
            'adviser' AS role_kind,
            NULL AS review_id,
            NULL AS review_level,
            NULL AS review_recommendation,
            NULL AS reviewed_at,
            NULL AS review_assigned_at
          FROM research_projects rp
          JOIN project_advisers pa ON pa.project_id = rp.project_id
         WHERE pa.adviser_id = ?" . $rp_deleted_filter . "

        UNION ALL

        SELECT
            rp.project_id,
            rp.title,
            rp.status,
            rp.created_by,
            rp.created_at AS rp_created_at,
            rp.updated_at AS rp_updated_at,
            'reviewer' AS role_kind,
            pr.review_id,
            pr.review_level,
            pr.recommendation AS review_recommendation,
            pr.reviewed_at,
            pr.created_at AS review_assigned_at
          FROM project_reviews pr
          JOIN research_projects rp ON rp.project_id = pr.project_id
         WHERE pr.reviewer_id = ?" . $rp_deleted_filter . "
    ) AS combined ";

$params = [];
$types  = '';

// Status filter (applied on the outer wrapper, not per branch)
if ($status_filter !== 'all') {
    $sql .= "WHERE combined.status = ? ";
    $params[] = $status_filter;
    $types   .= 's';
}

// Text search on title
if ($q_trimmed !== '') {
    $sql .= ($status_filter === 'all' ? "WHERE " : "AND ") . "combined.title LIKE ? ";
    $params[] = $q_like;
    $types   .= 's';
}

$sql .= "ORDER BY combined.rp_updated_at DESC, combined.project_id DESC LIMIT 200";

$list_stmt = $conn->prepare($sql);
if (!$list_stmt) {
    $errors[] = 'Could not prepare the submissions query.';
} else {
    // UNION query always has two `?` placeholders for adviser + reviewer user_id,
    // plus an optional status `?` and LIKE `?` for the outer filters.
    $types_full = 'ii' . $types;
    $bind_args = [$types_full];
    // The two user_id placeholders need to be passed by reference.
    $uid_a = $user_id;
    $uid_b = $user_id;
    $bind_args[] = &$uid_a;
    $bind_args[] = &$uid_b;
    foreach ($params as $i => $v) { $bind_args[] = &$params[$i]; }
    call_user_func_array([$list_stmt, 'bind_param'], $bind_args);
    $list_stmt->execute();
    $result = $list_stmt->get_result();
    $rows_by_project = [];
    while ($r = $result->fetch_assoc()) {
        $pid = (int) $r['project_id'];
        if (!isset($rows_by_project[$pid])) {
            $rows_by_project[$pid] = $r;
            continue;
        }

        $current = $rows_by_project[$pid];
        $candidate_is_adviser = ($r['role_kind'] ?? '') === 'adviser';
        $current_is_adviser = ($current['role_kind'] ?? '') === 'adviser';

        // One project may reach this UNION through both relationships. Keep a
        // single display row, preferring the adviser context as documented
        // above. For duplicate reviewer assignments, prefer work that is not
        // completed yet, then the newest review id.
        if ($candidate_is_adviser && !$current_is_adviser) {
            $rows_by_project[$pid] = $r;
        } elseif (!$candidate_is_adviser && !$current_is_adviser) {
            $candidate_pending = empty($r['reviewed_at']);
            $current_pending = empty($current['reviewed_at']);
            if (($candidate_pending && !$current_pending)
                || ($candidate_pending === $current_pending
                    && (int) ($r['review_id'] ?? 0) > (int) ($current['review_id'] ?? 0))) {
                $rows_by_project[$pid] = $r;
            }
        }
    }
    $rows = array_values($rows_by_project);
    $list_stmt->close();
}

// ------------------------------------------------------------------
// Aggregate per project: student owner(s) name(s) and chapter progress.
// We run targeted lookups keyed by project_id so a single query per
// project is fine for the typical 0-50 row count this page handles.
// ------------------------------------------------------------------
$student_names = [];     // [project_id => 'Name A, Name B']
$chapter_total = [];     // [project_id => N]
$chapter_done  = [];     // [project_id => approved count]
$last_activity = [];     // [project_id => timestamp string]

if (!empty($rows)) {
    $project_ids = array_values(array_unique(array_map(static function ($r) {
        return (int) $r['project_id'];
    }, $rows)));
    $id_list = implode(',', array_map('intval', $project_ids));

    if ($id_list !== '') {
        // project_members + users — owner + co-researchers
        $mem_sql =
            "SELECT pm.project_id, pm.role AS pm_role,
                    u.user_id, u.first_name, u.last_name
               FROM project_members pm
               JOIN users u ON u.user_id = pm.user_id
              WHERE pm.project_id IN ($id_list)
                AND u.deleted_at IS NULL
           ORDER BY pm.project_id, (pm.role = 'lead') DESC, u.last_name, u.first_name";
        $mem_res = $conn->query($mem_sql);
        if ($mem_res) {
            while ($m = $mem_res->fetch_assoc()) {
                $pid = (int) $m['project_id'];
                $name = trim($m['first_name'] . ' ' . $m['last_name']);
                if (!isset($student_names[$pid])) { $student_names[$pid] = []; }
                $student_names[$pid][] = $name;
            }
        }

        // Fall back to the project's created_by if no project_members rows
        $owner_sql =
            "SELECT rp.project_id, u.first_name, u.last_name
               FROM research_projects rp
               JOIN users u ON u.user_id = rp.created_by
              WHERE rp.project_id IN ($id_list)
                AND u.deleted_at IS NULL";
        $owner_res = $conn->query($owner_sql);
        if ($owner_res) {
            while ($o = $owner_res->fetch_assoc()) {
                $pid = (int) $o['project_id'];
                if (empty($student_names[$pid])) {
                    $student_names[$pid] = [trim($o['first_name'] . ' ' . $o['last_name'])];
                }
            }
        }

        // Chapter counts: total + approved
        $ch_sql =
            "SELECT project_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count
               FROM chapters
              WHERE project_id IN ($id_list)
           GROUP BY project_id";
        $ch_res = $conn->query($ch_sql);
        if ($ch_res) {
            while ($c = $ch_res->fetch_assoc()) {
                $pid = (int) $c['project_id'];
                $chapter_total[$pid] = (int) $c['total'];
                $chapter_done[$pid]  = (int) $c['approved_count'];
            }
        }

        // Last activity = max(updated_at, latest chapter submitted_at, latest review)
        $act_parts = [];
        foreach ($project_ids as $pid) {
            $act_parts[$pid] = null;
        }

        $upd_sql = "SELECT project_id, updated_at FROM research_projects WHERE project_id IN ($id_list)";
        if ($up_res = $conn->query($upd_sql)) {
            while ($u = $up_res->fetch_assoc()) {
                $pid = (int) $u['project_id'];
                $ts  = (string) $u['updated_at'];
                if ($act_parts[$pid] === null || $ts > $act_parts[$pid]) { $act_parts[$pid] = $ts; }
            }
        }
        $chap_sql = "SELECT project_id, MAX(submitted_at) AS last_sub FROM chapters WHERE project_id IN ($id_list) GROUP BY project_id";
        if ($ch2_res = $conn->query($chap_sql)) {
            while ($c2 = $ch2_res->fetch_assoc()) {
                $pid = (int) $c2['project_id'];
                $ts  = (string) ($c2['last_sub'] ?? '');
                if ($ts !== '' && ($act_parts[$pid] === null || $ts > $act_parts[$pid])) { $act_parts[$pid] = $ts; }
            }
        }
        $last_activity = $act_parts;
    }
}

// ------------------------------------------------------------------
// Summary stat cards. Counts across BOTH roles (adviser + reviewer).
// ------------------------------------------------------------------
$stat_total    = count($rows);
$stat_pending  = 0; // submitted/review/revision states needing attention
$stat_progress = 0; // approved/implementation/reporting states
$stat_done     = 0; // completed / archived

foreach ($rows as $r) {
    $s = (string) $r['status'];
    if (in_array($s, ['completed', 'archived'], true)) {
        $stat_done++;
    } elseif (in_array($s, ['approved', 'ongoing', 'progress_report', 'terminal_review'], true)) {
        $stat_progress++;
    } elseif (in_array($s, ['submitted', 'under_review', 'under_crec_review', 'under_erec_review', 'for_revision', 'revision_required'], true)) {
        $stat_pending++;
    }
}

// ------------------------------------------------------------------
// Status badge helper — same color tokens as the design system.
// ------------------------------------------------------------------
function fsub_status_badge($status) {
    $map = [
        'draft'               => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
        'proposal'            => ['#0369A1', 'rgba(3,105,161,0.10)',   'rgba(3,105,161,0.25)',   'Proposal'],
        'submitted'           => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Submitted'],
        'under_review'        => ['#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)', 'Under Review'],
        'under_crec_review'   => ['#4F46E5', 'rgba(79,70,229,0.10)',  'rgba(79,70,229,0.25)',  'CREC Review'],
        'under_erec_review'   => ['#9333EA', 'rgba(147,51,234,0.10)', 'rgba(147,51,234,0.25)', 'EREC Review'],
        'for_revision'        => ['#D97706', 'rgba(217,119,6,0.10)',  'rgba(217,119,6,0.25)',  'For Revision'],
        'revision_required'   => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)',  'Revision Required'],
        'rejected'            => ['#DC2626', 'rgba(220,38,38,0.10)',  'rgba(220,38,38,0.25)',  'Rejected'],
        'approved'            => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Approved'],
        'ongoing'             => ['#0F766E', 'rgba(15,118,110,0.10)', 'rgba(15,118,110,0.25)', 'Ongoing'],
        'progress_report'     => ['#0891B2', 'rgba(8,145,178,0.10)',  'rgba(8,145,178,0.25)',  'Progress Report'],
        'terminal_review'     => ['#A21CAF', 'rgba(162,28,175,0.10)', 'rgba(162,28,175,0.25)', 'Terminal Review'],
        'completed'           => ['#15803D', 'rgba(21,128,61,0.10)',  'rgba(21,128,61,0.25)',  'Completed'],
        'archived'            => ['#475569', 'rgba(71,85,105,0.10)',  'rgba(71,85,105,0.25)',  'Archived'],
    ];
    $row = $map[$status] ?? ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', ucwords(str_replace('_', ' ', (string) $status))];
    [$fg, $bg, $bd, $label] = $row;
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $fg . ';'
         . 'border:1px solid ' . $bd . ';">'
         . fsub_se($label) . '</span>';
}

function fsub_role_badge($role_kind, $review_level = null) {
    if ($role_kind === 'adviser') {
        return '<span style="display:inline-block;font-size:12px;font-weight:500;'
             . 'padding:3px 10px;border-radius:9999px;'
             . 'background:rgba(29,78,216,0.10);color:#1d4ed8;'
             . 'border:1px solid rgba(29,78,216,0.25);">'
             . '🎓 Adviser</span>';
    }
    $level = strtoupper((string) ($review_level ?? 'CREC'));
    $label = '📋 ' . fsub_se($level) . ' Reviewer';
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:rgba(91,30,188,0.10);color:#5B1EBC;'
         . 'border:1px solid rgba(91,30,188,0.25);">'
         . $label . '</span>';
}

function fsub_format_date($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return fsub_se((string) $ts); }
    return date('M d, Y', $t);
}

// ------------------------------------------------------------------
// Render the shell. Title varies with filter state for nicer UX.
// ------------------------------------------------------------------
$subtitle = $stat_total === 0
    ? 'No research is assigned to you yet.'
    : $stat_total . ' project' . ($stat_total === 1 ? '' : 's') . ' assigned · '
      . $stat_pending . ' need attention.';

renderFacultyShell($user, 'faculty-submissions.php', 'My Submissions', $subtitle);
?>

<style>
  .fsub-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .fsub-stat {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px 22px;
    transition: box-shadow 0.2s, transform 0.2s;
  }
  .fsub-stat:hover {
    box-shadow: 0 4px 14px rgba(29,78,216,0.10);
    transform: translateY(-1px);
  }
  .fsub-stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
  }
  .fsub-stat-lbl {
    font-size: 13px;
    color: #64748B;
    font-weight: 500;
    margin-top: 6px;
  }
  .fsub-stat-icon {
    float: right;
    font-size: 22px;
    opacity: 0.55;
  }

  .fsub-filters {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
  }
  .fsub-field { display: flex; flex-direction: column; gap: 4px; }
  .fsub-field label {
    font-size: 12px;
    font-weight: 500;
    color: #64748B;
  }
  .fsub-field select, .fsub-field input {
    font-family: inherit;
    font-size: 14px;
    padding: 8px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    min-width: 180px;
  }
  .fsub-field select:focus, .fsub-field input:focus {
    outline: 2px solid rgba(29,78,216,0.30);
    outline-offset: 1px;
    border-color: #1d4ed8;
  }
  .fsub-actions { display: flex; gap: 8px; align-items: flex-end; }
  .fsub-actions .btn-secondary { text-decoration: none; }

  .fsub-table-wrap {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    overflow: hidden;
  }
  .fsub-table { width: 100%; border-collapse: collapse; }
  .fsub-table thead th {
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 14px 18px;
    background: #F8FAFC;
    border-bottom: 1px solid #E5E7EB;
    position: sticky;
    top: 0;
  }
  .fsub-table tbody td {
    padding: 16px 18px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
    color: #111827;
    vertical-align: top;
  }
  .fsub-table tbody tr:first-child td { border-top: none; }
  .fsub-table tbody tr:hover { background: #F8FAFC; }

  .fsub-title {
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
    line-height: 1.3;
  }
  .fsub-students { color: #64748B; font-size: 13px; }
  .fsub-progress {
    font-size: 13px;
    color: #111827;
    font-weight: 500;
  }
  .fsub-progress-bar {
    margin-top: 6px;
    height: 6px;
    background: #F1F5F9;
    border-radius: 999px;
    overflow: hidden;
    width: 140px;
  }
  .fsub-progress-fill {
    height: 100%;
    background: #16A34A;
    border-radius: 999px;
    transition: width 0.2s;
  }

  .fsub-role-cell { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
  .fsub-rec-pill {
    display: inline-block;
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 9999px;
    background: rgba(100,116,139,0.10);
    color: #475569;
  }

  .fsub-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .fsub-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .fsub-empty-title { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 6px; }
  .fsub-empty-sub { font-size: 14px; color: #64748B; }

  .fsub-error {
    background: #FEF2F2;
    color: #991B1B;
    border: 1px solid #FECACA;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
  }

  @media (max-width: 720px) {
    .fsub-table thead { display: none; }
    .fsub-table tbody td { display: block; padding: 10px 18px; }
    .fsub-table tbody tr { display: block; padding: 8px 0; border-top: 1px solid #E5E7EB; }
    .fsub-table tbody td::before {
      content: attr(data-label);
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: #94A3B8;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 4px;
    }
  }
</style>

<?php if (!empty($errors)): ?>
  <div class="fsub-error"><?php echo fsub_se(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="fsub-stats">
  <div class="fsub-stat">
    <span class="fsub-stat-icon">📚</span>
    <div class="fsub-stat-num"><?php echo (int) $stat_total; ?></div>
    <div class="fsub-stat-lbl">Total assigned</div>
  </div>
  <div class="fsub-stat">
    <span class="fsub-stat-icon">📥</span>
    <div class="fsub-stat-num"><?php echo (int) $stat_pending; ?></div>
    <div class="fsub-stat-lbl">Pending review</div>
  </div>
  <div class="fsub-stat">
    <span class="fsub-stat-icon">🔄</span>
    <div class="fsub-stat-num"><?php echo (int) $stat_progress; ?></div>
    <div class="fsub-stat-lbl">In progress</div>
  </div>
  <div class="fsub-stat">
    <span class="fsub-stat-icon">✅</span>
    <div class="fsub-stat-num"><?php echo (int) $stat_done; ?></div>
    <div class="fsub-stat-lbl">Completed / Archived</div>
  </div>
</div>

<form class="fsub-filters" method="get" action="">
  <div class="fsub-field">
    <label for="fsub-status">Status</label>
    <select id="fsub-status" name="status">
      <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All statuses</option>
      <?php foreach ($status_options as $status_value => $status_label): ?>
        <option value="<?php echo fsub_se($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
          <?php echo fsub_se($status_label); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fsub-field" style="flex:1 1 240px;min-width:240px;">
    <label for="fsub-q">Search title</label>
    <input id="fsub-q" type="text" name="q" maxlength="120"
           placeholder="e.g. machine learning, bamboo, e-learning…"
           value="<?php echo fsub_se($q_raw); ?>">
  </div>
  <div class="fsub-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a class="btn btn-secondary" href="faculty-submissions.php">Reset</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card">
    <div class="fsub-empty">
      <div class="fsub-empty-icon">📭</div>
      <div class="fsub-empty-title">No submissions found</div>
      <div class="fsub-empty-sub">
        <?php if ($status_filter !== 'all' || $q_trimmed !== ''): ?>
          Nothing matches your current filters. Try clearing them.
        <?php else: ?>
          When a project lists you as adviser, or Research Staff assigns you as a CREC/EREC reviewer, it will appear here.
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="fsub-table-wrap">
    <table class="fsub-table">
      <thead>
        <tr>
          <th>Research</th>
          <th>Student</th>
          <th>Role</th>
          <th>Status</th>
          <th>Chapter progress</th>
          <th>Last activity</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $pid         = (int) $r['project_id'];
          $students    = !empty($student_names[$pid]) ? implode(', ', $student_names[$pid]) : '—';
          $ch_total    = (int) ($chapter_total[$pid] ?? 0);
          $ch_done     = (int) ($chapter_done[$pid] ?? 0);
          $progress_pct = $ch_total > 0 ? (int) round(($ch_done / max(1, $ch_total)) * 100) : 0;
          $last        = $last_activity[$pid] ?? ($r['rp_updated_at'] ?? null);
          $role_kind   = (string) $r['role_kind'];
          $review_id   = $r['review_id'] !== null ? (int) $r['review_id'] : 0;
          $review_lvl  = $r['review_level'] ?? null;
          $reviewed_at = $r['reviewed_at'] ?? null;
          $rec         = $r['review_recommendation'] ?? null;

          if ($role_kind === 'adviser') {
              $detail_url = SITE_URL . 'pages/faculty/faculty-review-detail.php?id=' . $pid;
              $detail_lbl = 'Review chapters';
          } else {
              $detail_url = SITE_URL . 'pages/faculty/faculty-score-review.php?id=' . $review_id;
              $detail_lbl = $reviewed_at ? 'Edit review' : 'Score review';
          }
      ?>
        <tr>
          <td data-label="Research">
            <div class="fsub-title"><?php echo fsub_se($r['title']); ?></div>
            <div class="fsub-students">Project #<?php echo $pid; ?></div>
          </td>
          <td data-label="Student" class="fsub-students"><?php echo fsub_se($students); ?></td>
          <td data-label="Role">
            <div class="fsub-role-cell">
              <?php echo fsub_role_badge($role_kind, $review_lvl); ?>
              <?php if ($role_kind === 'reviewer' && $rec): ?>
                <span class="fsub-rec-pill"><?php echo fsub_se(ucfirst((string) $rec)); ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Status"><?php echo fsub_status_badge((string) $r['status']); ?></td>
          <td data-label="Chapter progress">
            <div class="fsub-progress"><?php echo $ch_done; ?>/<?php echo max(5, $ch_total); ?> approved</div>
            <div class="fsub-progress-bar" aria-hidden="true">
              <div class="fsub-progress-fill" style="width: <?php echo $progress_pct; ?>%;"></div>
            </div>
          </td>
          <td data-label="Last activity"><?php echo fsub_se(fsub_format_date($last)); ?></td>
          <td data-label="Action">
            <a class="btn btn-primary btn-sm" href="<?php echo fsub_se($detail_url); ?>">
              <?php echo fsub_se($detail_lbl); ?> &rarr;
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (count($rows) >= 200): ?>
    <div style="text-align:center;margin-top:14px;font-size:13px;color:#94A3B8;">
      Showing the 200 most recent. Refine your filters to narrow the list.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php renderFacultyShellClose(); ?>
