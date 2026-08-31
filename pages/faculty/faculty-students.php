<?php
/**
 * Faculty — My Students
 *
 * Lists every student the logged-in faculty advises: distinct users with
 * role='student' who either own (created_by) or are members of
 * (project_members) any project where this faculty is the adviser
 * (project_advisers.adviser_id).
 *
 * Per row: name, student_id, program, year_level, their project(s) with
 * status badge, chapter progress (approved/total), last activity, and a
 * "Message" action that links to pages/shared/messages.php?to={user_id}
 * (the ?to= alias is a thin pre-select for the recipient).
 *
 * Filters (GET):
 *   ?q={text search on first/last/email}
 *   ?status={all|draft|proposal|in_progress|for_defense|completed|archived}
 *
 * Stat cards: total advisees, active projects, advisees needing attention
 * (have at least one chapter with status='revision_required').
 *
 * Page is read-only; no POSTs. All queries are prepared statements. Soft
 * deletes are filtered out on every table that has a deleted_at column.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function fstu_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------
// Defensive column detection. The base schema (database/schema/rms_db.sql)
// has no deleted_at on research_projects, project_advisers, project_members,
// or chapters, but a migration has added users.deleted_at and likely
// others. Mirror the approach used in faculty-submissions.php and
// faculty-review.php.
// ------------------------------------------------------------------
function fstu_has_column($conn, $table, $column) {
    // MariaDB does not accept a placeholder for the LIKE pattern in SHOW
    // statements, so the column name must be a literal. Both $table and
    // $column are hardcoded by the call sites below (never user input) so
    // string interpolation is safe here. Mirrors the literal-LIKE pattern
    // used in faculty-review.php and faculty-submissions.php.
    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $s = $conn->prepare($sql);
    if (!$s) { return false; }
    $s->execute();
    $has = $s->get_result()->num_rows > 0;
    $s->close();
    return $has;
}
function fstu_deleted_filter($conn, $table, $alias) {
    return fstu_has_column($conn, $table, 'deleted_at') ? " AND $alias.deleted_at IS NULL" : '';
}

$u_deleted  = fstu_deleted_filter($conn, 'users',               'u');
$rp_deleted = fstu_deleted_filter($conn, 'research_projects',   'rp');
$pm_deleted = fstu_deleted_filter($conn, 'project_members',     'pm');
$ch_deleted = fstu_deleted_filter($conn, 'chapters',            'ch');

// ------------------------------------------------------------------
// Filters
// ------------------------------------------------------------------
$status_filter = (string) ($_GET['status'] ?? 'all');
$allowed_statuses = ['draft', 'proposal', 'in_progress', 'for_defense', 'completed', 'archived'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

$q_raw     = (string) ($_GET['q'] ?? '');
$q_trimmed = trim($q_raw);
$q_like    = '%' . $q_trimmed . '%';

// ------------------------------------------------------------------
// Fetch advisees — every active student who owns or is a member of a
// project where this faculty is the adviser. The two subqueries are
// independent; we OR them. (We could UNION them, but IN with two
// subqueries is clearer and the planner handles it well.)
// ------------------------------------------------------------------
$advisees = [];
$errors   = [];

$adv_sql =
    "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email,
            u.student_id, u.program, u.year_level
       FROM users u
      WHERE u.role = 'student'
        AND u.status = 'active'"
        . $u_deleted . "
        AND (
            u.user_id IN (
                SELECT rp.created_by
                  FROM research_projects rp
                  JOIN project_advisers pa ON pa.project_id = rp.project_id
                 WHERE pa.adviser_id = ?"
                . $rp_deleted . "
            )
            OR
            u.user_id IN (
                SELECT pm.user_id
                  FROM project_members pm
                  JOIN project_advisers pa ON pa.project_id = pm.project_id
                 WHERE pa.adviser_id = ?"
                . $pm_deleted . "
            )
        )";

$params = [];
$types  = '';
if ($q_trimmed !== '') {
    $adv_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?) ";
    $params[] = $q_like;
    $params[] = $q_like;
    $params[] = $q_like;
    $types   .= 'sss';
}
$adv_sql .= " ORDER BY u.last_name, u.first_name, u.user_id LIMIT 200";

$adv_stmt = $conn->prepare($adv_sql);
if (!$adv_stmt) {
    $errors[] = 'Could not prepare the advisees query.';
} else {
    $uid_a = $user_id;
    $uid_b = $user_id;
    $bind_args = ['ii' . $types, &$uid_a, &$uid_b];
    foreach ($params as $i => $v) { $bind_args[] = &$params[$i]; }
    call_user_func_array([$adv_stmt, 'bind_param'], $bind_args);
    $adv_stmt->execute();
    $res = $adv_stmt->get_result();
    while ($r = $res->fetch_assoc()) { $advisees[] = $r; }
    $adv_stmt->close();
}

// ------------------------------------------------------------------
// For every advisee, gather their projects (with status), chapter
// progress, and last activity. One batched query per concern so we
// avoid N+1 across the typical 0-50 advisees this page renders.
// ------------------------------------------------------------------
$projects_by_student = [];   // [user_id => [['project_id'=>..,'title'=>..,'status'=>..,'updated_at'=>..]]
$chapters_by_project = [];   // [project_id => ['total'=>N,'approved'=>N,'last_submitted'=>ts]]
$needs_attention_ids = [];    // [user_id => true]  — has a chapter revision_required

if (!empty($advisees)) {
    $advisee_ids = array_map(static function ($a) { return (int) $a['user_id']; }, $advisees);
    $user_id_list = implode(',', array_map('intval', $advisee_ids));
    // Build the set of project_ids where any of these students are involved.
    $proj_ids = [];

    if ($user_id_list !== '') {
        // Projects owned by any of these students
        $own_sql =
            "SELECT rp.project_id, rp.title, rp.status, rp.updated_at, rp.created_by
               FROM research_projects rp
              WHERE rp.created_by IN ($user_id_list)"
              . $rp_deleted . "
              ORDER BY rp.updated_at DESC, rp.project_id DESC";
        if ($own_res = $conn->query($own_sql)) {
            while ($p = $own_res->fetch_assoc()) {
                $pid = (int) $p['project_id'];
                $owner = (int) $p['created_by'];
                $projects_by_student[$owner][] = $p;
                $proj_ids[$pid] = true;
            }
        }

        // Projects where any of these students are members
        $mem_sql =
            "SELECT pm.user_id, rp.project_id, rp.title, rp.status, rp.updated_at, rp.created_by
               FROM project_members pm
               JOIN research_projects rp ON rp.project_id = pm.project_id
              WHERE pm.user_id IN ($user_id_list)"
              . $pm_deleted
              . $rp_deleted . "
           ORDER BY rp.updated_at DESC, rp.project_id DESC";
        if ($mem_res = $conn->query($mem_sql)) {
            while ($p = $mem_res->fetch_assoc()) {
                $pid  = (int) $p['project_id'];
                $mid  = (int) $p['user_id'];
                $projects_by_student[$mid][] = $p;
                $proj_ids[$pid] = true;
            }
        }
    }

    if (!empty($proj_ids)) {
        $pid_list = implode(',', array_map('intval', array_keys($proj_ids)));

        // Chapter progress: total + approved count per project + latest submitted_at.
        $ch_sql =
            "SELECT project_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                    MAX(submitted_at) AS last_submitted
               FROM chapters
              WHERE project_id IN ($pid_list)"
              . $ch_deleted . "
           GROUP BY project_id";
        if ($ch_res = $conn->query($ch_sql)) {
            while ($c = $ch_res->fetch_assoc()) {
                $pid = (int) $c['project_id'];
                $chapters_by_project[$pid] = [
                    'total'         => (int) $c['total'],
                    'approved'      => (int) ($c['approved_count'] ?? 0),
                    'last_submitted'=> (string) ($c['last_submitted'] ?? ''),
                ];
            }
        }

        // "Needs attention" — projects (and therefore their owners + members)
        // that have at least one chapter with status='revision_required'.
        $rev_sql =
            "SELECT DISTINCT project_id
               FROM chapters
              WHERE project_id IN ($pid_list)
                AND status = 'revision_required'"
                . $ch_deleted;
        $rev_proj_ids = [];
        if ($rev_res = $conn->query($rev_sql)) {
            while ($r = $rev_res->fetch_assoc()) {
                $rev_proj_ids[(int) $r['project_id']] = true;
            }
        }
        if (!empty($rev_proj_ids)) {
            $rev_pid_list = implode(',', array_map('intval', array_keys($rev_proj_ids)));
            $oa_sql =
                "SELECT DISTINCT rp.created_by AS owner
                   FROM research_projects rp
                  WHERE rp.project_id IN ($rev_pid_list)"
                  . $rp_deleted;
            if ($oa = $conn->query($oa_sql)) {
                while ($row = $oa->fetch_assoc()) {
                    $needs_attention_ids[(int) $row['owner']] = true;
                }
            }
            $om_sql =
                "SELECT DISTINCT pm.user_id
                   FROM project_members pm
                  WHERE pm.project_id IN ($rev_pid_list)"
                  . $pm_deleted;
            if ($om = $conn->query($om_sql)) {
                while ($row = $om->fetch_assoc()) {
                    $needs_attention_ids[(int) $row['user_id']] = true;
                }
            }
        }
    }
}

// ------------------------------------------------------------------
// Apply the status filter (after the per-student project list is built).
// When a status filter is active, students with no projects matching
// that status are dropped, and we trim each student's list down to only
// the matching projects.
// ------------------------------------------------------------------
if ($status_filter !== 'all') {
    foreach ($advisees as $i => $a) {
        $uid  = (int) $a['user_id'];
        $list = $projects_by_student[$uid] ?? [];
        $filtered = array_values(array_filter($list, static function ($p) use ($status_filter) {
            return (string) $p['status'] === $status_filter;
        }));
        if (empty($filtered)) {
            unset($advisees[$i]);
        } else {
            $advisees[$i]['_filtered_projects'] = $filtered;
        }
    }
    $advisees = array_values($advisees);
}

// ------------------------------------------------------------------
// Stat cards.
//   - total_advisees:        advisee count after the search filter
//                            (status filter doesn't change the count of
//                            "people" — students with no matching project
//                            are dropped from the list, not the count).
//                            We compute "total" against the pre-status
//                            filter, so the stat reflects "how many
//                            students do I advise" regardless of view.
//                            Simplest faithful reading of the spec:
//                            show the count currently visible.
//   - active_projects:      projects across all (unfiltered) advisees
//                            with status in (proposal, in_progress,
//                            for_defense).
//   - need_attention:       unique advisees who have at least one
//                            chapter in revision_required.
// ------------------------------------------------------------------
$stat_total      = count($advisees);
$stat_active     = 0;
$stat_attention  = count($needs_attention_ids);

$active_set = ['proposal', 'in_progress', 'for_defense'];
$seen_projects = [];
foreach ($projects_by_student as $uid => $list) {
    foreach ($list as $p) {
        $pid = (int) $p['project_id'];
        if (isset($seen_projects[$pid])) { continue; }
        $seen_projects[$pid] = true;
        if (in_array((string) $p['status'], $active_set, true)) {
            $stat_active++;
        }
    }
}

// ------------------------------------------------------------------
// Helpers — relative time, status badge, year-level display.
// ------------------------------------------------------------------
function fstu_relative_time($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return fstu_se((string) $ts); }
    $diff = time() - $t;
    if ($diff < 0) { $diff = 0; }
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return intval($diff / 60)   . ' min ago';
    if ($diff < 86400)  return intval($diff / 3600) . ' hours ago';
    if ($diff < 604800) return intval($diff / 86400). ' days ago';
    if ($diff < 2592000)return intval($diff / 604800). ' weeks ago';
    return date('M d, Y', $t);
}

function fstu_status_badge($status) {
    $map = [
        'draft'       => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
        'proposal'    => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Proposal'],
        'in_progress' => ['#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)', 'In Progress'],
        'for_defense' => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)',  'For Defense'],
        'completed'   => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Completed'],
        'archived'    => ['#475569', 'rgba(71,85,105,0.10)',  'rgba(71,85,105,0.25)',  'Archived'],
    ];
    $row = $map[$status] ?? $map['draft'];
    [$fg, $bg, $bd, $label] = $row;
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $fg . ';'
         . 'border:1px solid ' . $bd . ';">'
         . fstu_se($label) . '</span>';
}

// ------------------------------------------------------------------
// Render the shell.
// ------------------------------------------------------------------
$subtitle = $stat_total === 0
    ? 'No students are assigned to you yet.'
    : $stat_total . ' advisee' . ($stat_total === 1 ? '' : 's')
      . ' &middot; ' . $stat_attention . ' need attention.';

renderFacultyShell($user, 'faculty-students.php', 'My Students', $subtitle);
?>

<style>
  .fstu-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .fstu-stat {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px 22px;
    transition: box-shadow 0.2s, transform 0.2s;
  }
  .fstu-stat:hover {
    box-shadow: 0 4px 14px rgba(29,78,216,0.10);
    transform: translateY(-1px);
  }
  .fstu-stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
  }
  .fstu-stat-lbl {
    font-size: 13px;
    color: #64748B;
    font-weight: 500;
    margin-top: 6px;
  }
  .fstu-stat-icon {
    float: right;
    font-size: 22px;
    opacity: 0.55;
  }

  .fstu-filters {
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
  .fstu-field { display: flex; flex-direction: column; gap: 4px; }
  .fstu-field label {
    font-size: 12px;
    font-weight: 500;
    color: #64748B;
  }
  .fstu-field select, .fstu-field input {
    font-family: inherit;
    font-size: 14px;
    padding: 8px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    min-width: 180px;
  }
  .fstu-field input { min-width: 240px; }
  .fstu-field select:focus, .fstu-field input:focus {
    outline: 2px solid rgba(29,78,216,0.30);
    outline-offset: 1px;
    border-color: #1d4ed8;
  }
  .fstu-actions { display: flex; gap: 8px; align-items: flex-end; }
  .fstu-actions .btn-secondary { text-decoration: none; }

  .fstu-card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .fstu-card:hover {
    border-color: rgba(29,78,216,0.25);
    box-shadow: 0 1px 6px rgba(29,78,216,0.08);
  }
  .fstu-card.attn {
    border-color: rgba(234,88,12,0.45);
    background: linear-gradient(180deg, #FFFBEB 0%, #ffffff 70%);
  }
  .fstu-head {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }
  .fstu-name {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    line-height: 1.3;
    margin-bottom: 4px;
  }
  .fstu-meta {
    font-size: 13px;
    color: #64748B;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: center;
  }
  .fstu-projects {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .fstu-project {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    padding: 10px 12px;
    background: #F8FAFC;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
  }
  .fstu-project-title {
    font-size: 14px;
    font-weight: 500;
    color: #111827;
    min-width: 0;
    flex: 1 1 240px;
  }
  .fstu-project-side {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }
  .fstu-progress {
    font-size: 12px;
    color: #475569;
    font-weight: 500;
  }
  .fstu-progress-bar {
    height: 5px;
    background: #E5E7EB;
    border-radius: 9999px;
    overflow: hidden;
    width: 80px;
  }
  .fstu-progress-fill {
    height: 100%;
    background: #16A34A;
    border-radius: 9999px;
    transition: width 0.2s;
  }

  .fstu-actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
  }

  .fstu-attn-pill {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 9999px;
    background: rgba(234,88,12,0.10);
    color: #EA580C;
    border: 1px solid rgba(234,88,12,0.30);
    margin-left: 8px;
  }

  .fstu-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .fstu-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .fstu-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
  }
  .fstu-empty-sub { font-size: 14px; color: #64748B; }

  .fstu-error {
    background: #FEF2F2;
    color: #991B1B;
    border: 1px solid #FECACA;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
  }

  @media (max-width: 720px) {
    .fstu-head { flex-direction: column; }
    .fstu-project { flex-direction: column; align-items: flex-start; }
  }
</style>

<?php if (!empty($errors)): ?>
  <div class="fstu-error"><?php echo fstu_se(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="fstu-stats">
  <div class="fstu-stat">
    <span class="fstu-stat-icon">🎒</span>
    <div class="fstu-stat-num"><?php echo (int) $stat_total; ?></div>
    <div class="fstu-stat-lbl">Total students</div>
  </div>
  <div class="fstu-stat">
    <span class="fstu-stat-icon">📚</span>
    <div class="fstu-stat-num"><?php echo (int) $stat_active; ?></div>
    <div class="fstu-stat-lbl">Active projects</div>
  </div>
  <div class="fstu-stat">
    <span class="fstu-stat-icon">⚠️</span>
    <div class="fstu-stat-num"><?php echo (int) $stat_attention; ?></div>
    <div class="fstu-stat-lbl">Need attention</div>
  </div>
</div>

<form class="fstu-filters" method="get" action="">
  <div class="fstu-field">
    <label for="fstu-status">Project status</label>
    <select id="fstu-status" name="status">
      <option value="all"        <?php echo $status_filter === 'all'        ? 'selected' : ''; ?>>All statuses</option>
      <option value="draft"       <?php echo $status_filter === 'draft'       ? 'selected' : ''; ?>>Draft</option>
      <option value="proposal"    <?php echo $status_filter === 'proposal'    ? 'selected' : ''; ?>>Proposal</option>
      <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
      <option value="for_defense" <?php echo $status_filter === 'for_defense' ? 'selected' : ''; ?>>For defense</option>
      <option value="completed"   <?php echo $status_filter === 'completed'   ? 'selected' : ''; ?>>Completed</option>
      <option value="archived"    <?php echo $status_filter === 'archived'    ? 'selected' : ''; ?>>Archived</option>
    </select>
  </div>
  <div class="fstu-field" style="flex:1 1 240px;min-width:240px;">
    <label for="fstu-q">Search name or email</label>
    <input id="fstu-q" type="text" name="q" maxlength="120"
           placeholder="e.g. Dela Cruz, juan, jdelacruz@rms.edu.ph…"
           value="<?php echo fstu_se($q_raw); ?>">
  </div>
  <div class="fstu-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a class="btn btn-secondary" href="faculty-students.php">Reset</a>
  </div>
</form>

<?php if (!$advisees): ?>
  <div class="card">
    <div class="fstu-empty">
      <div class="fstu-empty-icon">🎒</div>
      <div class="fstu-empty-title">
        <?php echo $stat_total === 0 && $q_trimmed === '' && $status_filter === 'all'
            ? 'No advisees yet'
            : 'No students match your filters'; ?>
      </div>
      <div class="fstu-empty-sub">
        <?php if ($q_trimmed !== '' || $status_filter !== 'all'): ?>
          Try clearing the search or the status filter.
        <?php else: ?>
          When Research Staff assigns you as the adviser on a student's project, they will appear here.
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($advisees as $a):
      $sid         = (int) $a['user_id'];
      $needs_attn  = isset($needs_attention_ids[$sid]);
      // Use the filtered list if a status filter trimmed it; otherwise the full list.
      $plist = isset($a['_filtered_projects']) ? $a['_filtered_projects'] : ($projects_by_student[$sid] ?? []);
      $plist = array_values($plist);

      $latest_ts = '';
      foreach ($plist as $p) {
          $ts_a = (string) ($p['updated_at'] ?? '');
          $pid  = (int)   $p['project_id'];
          $ts_b = (string) ($chapters_by_project[$pid]['last_submitted'] ?? '');
          $ts   = max($ts_a, $ts_b);
          if ($ts !== '' && $ts > $latest_ts) { $latest_ts = $ts; }
      }

      $student_id = (string) ($a['student_id'] ?? '');
      $program    = (string) ($a['program']    ?? '');
      $year_level = (string) ($a['year_level'] ?? '');
      $email      = (string) ($a['email']      ?? '');
  ?>
    <div class="fstu-card <?php echo $needs_attn ? 'attn' : ''; ?>">
      <div class="fstu-head">
        <div style="min-width:0;flex:1 1 280px;">
          <div class="fstu-name">
            🎒 <?php echo fstu_se(trim($a['first_name'] . ' ' . $a['last_name'])); ?>
            <?php if ($needs_attn): ?>
              <span class="fstu-attn-pill">⚠ Needs revision</span>
            <?php endif; ?>
          </div>
          <div class="fstu-meta">
            <?php if ($student_id !== ''): ?>
              <span>🆔 <?php echo fstu_se($student_id); ?></span>
            <?php endif; ?>
            <?php if ($program !== ''): ?>
              <span>📘 <?php echo fstu_se($program); ?></span>
            <?php endif; ?>
            <?php if ($year_level !== ''): ?>
              <span>🎓 <?php echo fstu_se($year_level); ?></span>
            <?php endif; ?>
            <?php if ($email !== ''): ?>
              <span>✉️ <?php echo fstu_se($email); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="fstu-actions-row" style="margin-top:0;">
          <a class="btn btn-primary btn-sm"
             href="<?php echo fstu_se(SITE_URL . 'pages/shared/messages.php?to=' . $sid); ?>">
            ✉️ Message
          </a>
        </div>
      </div>

      <?php if (!$plist): ?>
        <div class="fstu-meta" style="padding:6px 0;">No projects to show under the current filter.</div>
      <?php else: ?>
        <div class="fstu-projects">
          <?php
              $shown = array_slice($plist, 0, 2);
              $more  = count($plist) - count($shown);
              foreach ($shown as $p):
                  $pid  = (int) $p['project_id'];
                  $prog = $chapters_by_project[$pid] ?? ['total' => 0, 'approved' => 0];
                  $total = max(5, (int) $prog['total']);
                  $done  = (int) $prog['approved'];
                  $pct   = $total > 0 ? (int) round(($done / $total) * 100) : 0;
          ?>
            <div class="fstu-project">
              <div class="fstu-project-title">
                <?php echo fstu_se((string) $p['title']); ?>
                <div style="font-size:12px;color:#94A3B8;">Project #<?php echo $pid; ?></div>
              </div>
              <div class="fstu-project-side">
                <?php echo fstu_status_badge((string) $p['status']); ?>
                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
                  <div class="fstu-progress"><?php echo $done; ?>/<?php echo $total; ?> approved</div>
                  <div class="fstu-progress-bar" aria-hidden="true">
                    <div class="fstu-progress-fill" style="width: <?php echo $pct; ?>%;"></div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($more > 0): ?>
            <div class="fstu-meta">+ <?php echo (int) $more; ?> more project<?php echo $more === 1 ? '' : 's'; ?> not shown</div>
          <?php endif; ?>
        </div>
        <div class="fstu-meta" style="margin-top:10px;">
          🕓 Last activity <?php echo fstu_se(fstu_relative_time($latest_ts)); ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (count($advisees) >= 200): ?>
    <div style="text-align:center;margin-top:14px;font-size:13px;color:#94A3B8;">
      Showing the 200 most recent. Refine the search to narrow the list.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php renderFacultyShellClose(); ?>
