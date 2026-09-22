<?php
/**
 * Faculty — Reports
 *
 * Read-only analytics page for the logged-in faculty. Scope:
 *   - Projects where this faculty is the adviser (project_advisers.adviser_id)
 *   - CREC/EREC reviews assigned to this faculty (project_reviews.reviewer_id)
 *     when the migration 006 table is present (same fallback pattern used
 *     in faculty-submissions.php).
 *
 * Sections:
 *   1. Stat cards: total advised projects, active, completed,
 *      total chapters reviewed (approved + revision_required by this
 *      faculty), pending reviews awaiting the faculty.
 *   2. Project status breakdown — one horizontal bar per status, width
 *      scaled to the count, plus the count and percentage. Pure divs.
 *   3. Review activity — monthly counts of chapter approvals and
 *      revision requests this faculty made over the last 6 months.
 *      Renders as a simple table + a CSS bar list, no JS chart library.
 *   4. Per-project progress table — one row per advised project with
 *      title, lead student, status badge, chapters approved/total,
 *      last activity.
 *
 * Optional GET filters (applied only to the review activity section):
 *   ?from=YYYY-MM-DD
 *   ?to=YYYY-MM-DD
 * Both are validated against YYYY-MM-DD and bound via prepared statements.
 *
 * Defensive column detection mirrors faculty-submissions.php and
 * faculty-review.php — the base schema (database/schema/rms_db.sql) has no
 * deleted_at on research_projects / project_advisers / chapters, but a
 * migration may have added one, so we probe at runtime.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function frep_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------
// Defensive column / table detection.
// ------------------------------------------------------------------
$rp_has_deleted_at = false;
$col_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($col_stmt) {
    $col_stmt->execute();
    $rp_has_deleted_at = $col_stmt->get_result()->num_rows > 0;
    $col_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

$ch_has_deleted_at = false;
$col2 = $conn->prepare("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
if ($col2) {
    $col2->execute();
    $ch_has_deleted_at = $col2->get_result()->num_rows > 0;
    $col2->close();
}
$ch_deleted_filter = $ch_has_deleted_at ? ' AND ch.deleted_at IS NULL' : '';

$pa_has_deleted_at = false;
$col3 = $conn->prepare("SHOW COLUMNS FROM project_advisers LIKE 'deleted_at'");
if ($col3) {
    $col3->execute();
    $pa_has_deleted_at = $col3->get_result()->num_rows > 0;
    $col3->close();
}
$pa_deleted_filter = $pa_has_deleted_at ? ' AND pa.deleted_at IS NULL' : '';

$has_project_reviews = false;
$tbl_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
if ($tbl_stmt) {
    $tbl_stmt->execute();
    $tbl_stmt->bind_result($tbl);
    while ($tbl_stmt->fetch()) { $has_project_reviews = ($tbl === 'project_reviews'); }
    $tbl_stmt->close();
}

// ------------------------------------------------------------------
// Validate optional date filters (applied to review activity only).
//   - Empty / missing  -> filter disabled
//   - Must match YYYY-MM-DD strictly
//   - from > to        -> swap so the range is always valid
// ------------------------------------------------------------------
$from_raw = (string) ($_GET['from'] ?? '');
$to_raw   = (string) ($_GET['to']   ?? '');

$from_dt = null;
$to_dt   = null;
$date_filter_error = '';

if ($from_raw !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $from_raw);
    if (!$d || $d->format('Y-m-d') !== $from_raw) {
        $date_filter_error = 'Invalid "from" date — use YYYY-MM-DD.';
    } else {
        $from_dt = $d->setTime(0, 0, 0);
    }
}
if ($to_raw !== '' && $date_filter_error === '') {
    $d = DateTime::createFromFormat('Y-m-d', $to_raw);
    if (!$d || $d->format('Y-m-d') !== $to_raw) {
        $date_filter_error = 'Invalid "to" date — use YYYY-MM-DD.';
    } else {
        $to_dt = $d->setTime(23, 59, 59);
    }
}
if ($from_dt && $to_dt && $from_dt > $to_dt) {
    [$from_dt, $to_dt] = [$to_dt, $from_dt];
    [$from_raw, $to_raw] = [$to_raw, $from_raw];
}

$has_date_filter = ($from_dt !== null) || ($to_dt !== null);

// ------------------------------------------------------------------
// 1. List of advised projects (the master scope for this page).
// ------------------------------------------------------------------
$advised_projects = []; // [project_id => row]
$errors = [];

$proj_sql =
    "SELECT rp.project_id, rp.title, rp.status, rp.created_by,
            rp.created_at, rp.updated_at
       FROM research_projects rp
       JOIN project_advisers pa ON pa.project_id = rp.project_id
      WHERE pa.adviser_id = ?"
    . $rp_deleted_filter . $pa_deleted_filter . "
   ORDER BY rp.updated_at DESC, rp.project_id DESC";
$proj_stmt = $conn->prepare($proj_sql);
if ($proj_stmt) {
    $uid = $user_id;
    $proj_stmt->bind_param('i', $uid);
    $proj_stmt->execute();
    $res = $proj_stmt->get_result();
    while ($r = $res->fetch_assoc()) { $advised_projects[(int) $r['project_id']] = $r; }
    $proj_stmt->close();
} else {
    $errors[] = 'Could not prepare the advised-projects query.';
}

// ------------------------------------------------------------------
// 2. CREC/EREC reviews for this faculty (informational, separate scope).
//    We don't double-count these into advised project totals — they're
//    shown only in the per-review summary if any exist.
// ------------------------------------------------------------------
$review_rows = [];
if ($has_project_reviews) {
    $rev_sql =
        "SELECT pr.review_id, pr.project_id, pr.review_level, pr.recommendation,
                pr.reviewed_at, pr.created_at, pr.methodology_score,
                pr.contribution_score, pr.applicability_score, pr.agenda_score,
                rp.title AS project_title
           FROM project_reviews pr
           JOIN research_projects rp ON rp.project_id = pr.project_id
          WHERE pr.reviewer_id = ?"
        . $rp_deleted_filter . "
       ORDER BY pr.created_at DESC, pr.review_id DESC
          LIMIT 50";
    $rev_stmt = $conn->prepare($rev_sql);
    if ($rev_stmt) {
        $uid = $user_id;
        $rev_stmt->bind_param('i', $uid);
        $rev_stmt->execute();
        $res2 = $rev_stmt->get_result();
        while ($r = $res2->fetch_assoc()) { $review_rows[] = $r; }
        $rev_stmt->close();
    }
    // $errors intentionally not extended — review list is supplementary.
}

// ------------------------------------------------------------------
// 3. Per-project aggregates (status breakdown source, per-project table
//    source, last activity).
// ------------------------------------------------------------------
$project_ids = array_keys($advised_projects);

$chapter_total  = []; // [project_id => N]
$chapter_approved = []; // [project_id => approved count]
$chapter_last_sub = []; // [project_id => MAX(submitted_at)]
$last_activity  = []; // [project_id => ts]

foreach ($advised_projects as $pid => $r) {
    $last_activity[$pid] = (string) ($r['updated_at'] ?? '');
}

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));

    $ch_sql =
        "SELECT ch.project_id,
                COUNT(*) AS total,
                SUM(CASE WHEN ch.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                MAX(ch.submitted_at) AS last_sub
           FROM chapters ch
          WHERE ch.project_id IN ($id_list)"
        . $ch_deleted_filter . "
       GROUP BY ch.project_id";
    if ($ch_res = $conn->query($ch_sql)) {
        while ($c = $ch_res->fetch_assoc()) {
            $pid = (int) $c['project_id'];
            $chapter_total[$pid]   = (int) $c['total'];
            $chapter_approved[$pid] = (int) ($c['approved_count'] ?? 0);
            $chapter_last_sub[$pid] = (string) ($c['last_sub'] ?? '');
            $ls = $chapter_last_sub[$pid];
            if ($ls !== '' && $ls > $last_activity[$pid]) { $last_activity[$pid] = $ls; }
        }
    }

    // Update last activity with latest comment/approval timestamp.
    $act_sql =
        "SELECT ch.project_id, MAX(GREATEST(
                COALESCE(ch.approved_at, '1970-01-01 00:00:00'),
                COALESCE(ch.submitted_at, '1970-01-01 00:00:00')
             )) AS last_ts
           FROM chapters ch
          WHERE ch.project_id IN ($id_list)"
        . $ch_deleted_filter . "
       GROUP BY ch.project_id";
    if ($act_res = $conn->query($act_sql)) {
        while ($a = $act_res->fetch_assoc()) {
            $pid = (int) $a['project_id'];
            $ts  = (string) ($a['last_ts'] ?? '');
            if ($ts !== '' && $ts > $last_activity[$pid]) { $last_activity[$pid] = $ts; }
        }
    }
}

// ------------------------------------------------------------------
// 4. Stat cards.
//   - stat_total_advised: count of advised projects (regardless of status).
//   - stat_active:        advised projects with active statuses
//                         (proposal, in_progress, for_defense).
//   - stat_completed:     advised projects with status = completed.
//   - stat_chapters_done: chapters this faculty has approved
//                         (ch.approved_by = ?) + revision requests this
//                         faculty has issued (via comments.type IN
//                         ('correction','suggestion') with comments.faculty_id = ?).
//   - stat_pending:       chapters awaiting this faculty (status in
//                         submitted, under_review) on advised projects.
// ------------------------------------------------------------------
$stat_total_advised = count($advised_projects);
$stat_active        = 0;
$stat_completed     = 0;

$active_set = ['proposal', 'in_progress', 'for_defense'];
foreach ($advised_projects as $r) {
    $s = (string) $r['status'];
    if ($s === 'completed') { $stat_completed++; }
    if (in_array($s, $active_set, true)) { $stat_active++; }
}

$stat_chapters_done = 0;
$stat_pending       = 0;

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));

    // Approved by this faculty (canonical — chapters.approved_by is set in
    // faculty-review.php when the faculty clicks Approve).
    $app_sql =
        "SELECT COUNT(*) AS c
           FROM chapters ch
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.approved_by = ?
            AND ch.status = 'approved'"
        . $ch_deleted_filter . $pa_deleted_filter;
    $app_stmt = $conn->prepare($app_sql);
    if ($app_stmt) {
        $uid = $user_id;
        $app_stmt->bind_param('ii', $uid, $uid);
        $app_stmt->execute();
        $approved_count = (int) ($app_stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $app_stmt->close();
    } else {
        $approved_count = 0;
    }

    // Revision requests by this faculty — comments of type 'correction'
    // or 'suggestion' that they wrote, on a chapter belonging to an
    // advised project. The comments table has no deleted_at column in
    // the base schema, so no soft-delete filter is needed.
    $rev_sql =
        "SELECT COUNT(DISTINCT c.chapter_id) AS c
           FROM comments c
           JOIN chapters ch ON ch.chapter_id = c.chapter_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND c.faculty_id = ?
            AND c.type IN ('correction','suggestion')"
        . $ch_deleted_filter . $pa_deleted_filter;
    $rev_stmt = $conn->prepare($rev_sql);
    if ($rev_stmt) {
        $uid = $user_id;
        $rev_stmt->bind_param('ii', $uid, $uid);
        $rev_stmt->execute();
        $revision_count = (int) ($rev_stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $rev_stmt->close();
    } else {
        $revision_count = 0;
    }

    $stat_chapters_done = $approved_count + $revision_count;

    // Pending reviews: submitted/under_review chapters on advised projects.
    $pend_sql =
        "SELECT COUNT(*) AS c
           FROM chapters ch
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.status IN ('submitted','under_review')"
        . $ch_deleted_filter . $pa_deleted_filter;
    $pend_stmt = $conn->prepare($pend_sql);
    if ($pend_stmt) {
        $uid = $user_id;
        $pend_stmt->bind_param('i', $uid);
        $pend_stmt->execute();
        $stat_pending = (int) ($pend_stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $pend_stmt->close();
    }
}

// ------------------------------------------------------------------
// 5. Project status breakdown.
// ------------------------------------------------------------------
$status_counts = [
    'draft'       => 0,
    'proposal'    => 0,
    'in_progress' => 0,
    'for_defense' => 0,
    'completed'   => 0,
    'archived'    => 0,
];
foreach ($advised_projects as $r) {
    $s = (string) $r['status'];
    if (isset($status_counts[$s])) { $status_counts[$s]++; }
}
$status_total = array_sum($status_counts);

$status_order = [
    'draft'       => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
    'proposal'    => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Proposal'],
    'in_progress' => ['#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)', 'In Progress'],
    'for_defense' => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)',  'For Defense'],
    'completed'   => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Completed'],
    'archived'    => ['#475569', 'rgba(71,85,105,0.10)',  'rgba(71,85,105,0.25)',  'Archived'],
];

// ------------------------------------------------------------------
// 6. Review activity — last 6 months (current month + 5 prior).
//    Two series: approved (ch.approved_by = ?) and revisions
//    (comments by this faculty, type IN 'correction','suggestion').
//    Date filter (from/to) narrows the activity window when present.
// ------------------------------------------------------------------
$activity_months = []; // ['YYYY-MM' => ['label' => 'Aug 2026', 'approved' => N, 'revisions' => N]]
$activity_base = new DateTime(date('Y-m-01 00:00:00'));
$activity_base->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $key = $activity_base->format('Y-m');
    $activity_months[$key] = [
        'label'     => $activity_base->format('M Y'),
        'approved'  => 0,
        'revisions' => 0,
    ];
    $activity_base->modify('+1 month');
}

$activity_from = $from_dt;
$activity_to   = $to_dt;
if ($activity_from === null) {
    // No explicit from -> start at the first month in the activity window.
    $first_key = array_key_first($activity_months);
    $activity_from = new DateTime($first_key . '-01 00:00:00');
}
if ($activity_to === null) {
    // No explicit to -> cover everything up to "now + 1 day" so today's
    // records land in the current month.
    $activity_to = new DateTime('now');
    $activity_to->modify('+1 day');
}

$activity_from_str = $activity_from->format('Y-m-d H:i:s');
$activity_to_str   = $activity_to->format('Y-m-d H:i:s');

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));

    // Approved in window.
    $a_sql =
        "SELECT DATE_FORMAT(ch.approved_at, '%Y-%m') AS ym, COUNT(*) AS c
           FROM chapters ch
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.approved_by = ?
            AND ch.status = 'approved'
            AND ch.approved_at IS NOT NULL
            AND ch.approved_at BETWEEN ? AND ?"
        . $ch_deleted_filter . $pa_deleted_filter . "
       GROUP BY ym";
    $a_stmt = $conn->prepare($a_sql);
    if ($a_stmt) {
        $uid = $user_id;
        $a_stmt->bind_param('iiss', $uid, $uid, $activity_from_str, $activity_to_str);
        $a_stmt->execute();
        $r = $a_stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $ym = (string) ($row['ym'] ?? '');
            if (isset($activity_months[$ym])) {
                $activity_months[$ym]['approved'] = (int) $row['c'];
            }
        }
        $a_stmt->close();
    }

    // Revisions in window (comments by this faculty, correction/suggestion).
    $r_sql =
        "SELECT DATE_FORMAT(c.created_at, '%Y-%m') AS ym, COUNT(*) AS c
           FROM comments c
           JOIN chapters ch ON ch.chapter_id = c.chapter_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND c.faculty_id = ?
            AND c.type IN ('correction','suggestion')
            AND c.created_at BETWEEN ? AND ?"
        . $ch_deleted_filter . $pa_deleted_filter . "
       GROUP BY ym";
    $r_stmt = $conn->prepare($r_sql);
    if ($r_stmt) {
        $uid = $user_id;
        $r_stmt->bind_param('iiss', $uid, $uid, $activity_from_str, $activity_to_str);
        $r_stmt->execute();
        $r2 = $r_stmt->get_result();
        while ($row = $r2->fetch_assoc()) {
            $ym = (string) ($row['ym'] ?? '');
            if (isset($activity_months[$ym])) {
                $activity_months[$ym]['revisions'] = (int) $row['c'];
            }
        }
        $r_stmt->close();
    }
}

// Compute max for bar width.
$activity_max = 0;
foreach ($activity_months as $bucket) {
    $sum = $bucket['approved'] + $bucket['revisions'];
    if ($sum > $activity_max) { $activity_max = $sum; }
}
$activity_total_approved  = 0;
$activity_total_revisions = 0;
foreach ($activity_months as $bucket) {
    $activity_total_approved  += $bucket['approved'];
    $activity_total_revisions += $bucket['revisions'];
}

// ------------------------------------------------------------------
// 7. Per-project progress table — student name lookup.
// ------------------------------------------------------------------
$student_names = []; // [project_id => 'Name A, Name B']

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));

    $mem_sql =
        "SELECT pm.project_id, pm.role AS pm_role,
                u.first_name, u.last_name, u.deleted_at
           FROM project_members pm
           JOIN users u ON u.user_id = pm.user_id
          WHERE pm.project_id IN ($id_list)
            AND u.deleted_at IS NULL
       ORDER BY pm.project_id, (pm.role = 'lead') DESC, u.last_name, u.first_name";
    if ($mem_res = $conn->query($mem_sql)) {
        while ($m = $mem_res->fetch_assoc()) {
            $pid  = (int) $m['project_id'];
            $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            if (!isset($student_names[$pid])) { $student_names[$pid] = []; }
            if ($name !== '' && !in_array($name, $student_names[$pid], true)) {
                $student_names[$pid][] = $name;
            }
        }
    }

    // Fallback to created_by.
    $own_sql =
        "SELECT rp.project_id, u.first_name, u.last_name
           FROM research_projects rp
           JOIN users u ON u.user_id = rp.created_by
          WHERE rp.project_id IN ($id_list)
            AND u.deleted_at IS NULL";
    if ($own_res = $conn->query($own_sql)) {
        while ($o = $own_res->fetch_assoc()) {
            $pid = (int) $o['project_id'];
            if (empty($student_names[$pid])) {
                $nm = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
                if ($nm !== '') { $student_names[$pid] = [$nm]; }
            }
        }
    }
}

// ------------------------------------------------------------------
// 8. Helpers (status badge, relative time, format date).
// ------------------------------------------------------------------
function frep_status_badge($status) {
    $map = [
        'draft'       => 'Draft',
        'proposal'    => 'Proposal',
        'in_progress' => 'In Progress',
        'for_defense' => 'For Defense',
        'completed'   => 'Completed',
        'archived'    => 'Archived',
    ];
    $safeStatus = array_key_exists($status, $map) ? $status : 'draft';
    return '<span class="frep-status frep-status--' . frep_se($safeStatus) . '">'
         . frep_se($map[$safeStatus]) . '</span>';
}

function frep_relative_time($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return frep_se((string) $ts); }
    $diff = time() - $t;
    if ($diff < 0) { $diff = 0; }
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return intval($diff / 60)   . ' min ago';
    if ($diff < 86400)  return intval($diff / 3600) . ' hours ago';
    if ($diff < 604800) return intval($diff / 86400). ' days ago';
    if ($diff < 2592000)return intval($diff / 604800). ' weeks ago';
    return date('M d, Y', $t);
}

function frep_format_date($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return frep_se((string) $ts); }
    return date('M d, Y', $t);
}

// ------------------------------------------------------------------
// 9. Render the shell.
// ------------------------------------------------------------------
$subtitle = $stat_total_advised === 0
    ? 'You have no advised projects yet — your analytics will appear once students are assigned.'
    : $stat_total_advised . ' project' . ($stat_total_advised === 1 ? '' : 's') . ' advised &middot; '
      . $stat_pending . ' chapter' . ($stat_pending === 1 ? '' : 's') . ' pending your review.';

renderFacultyShell($user, 'faculty-reports.php', 'Reports', $subtitle);
?>


<style>
  .frep-page {
    --frep-blue: #1d4ed8;
    --frep-indigo: #4338ca;
    --frep-deep: #172554;
    --frep-soft: #eff6ff;
    --frep-line: #e2e8f0;
    --frep-muted: #64748b;
    max-width: 1320px;
    margin: 0 auto;
    color: #0f172a;
  }
  .frep-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 36px;
    align-items: end;
    overflow: hidden;
    margin-bottom: 18px;
    padding: 34px 38px;
    border: 1px solid rgba(29, 78, 216, .35);
    border-radius: 20px;
    background:
      radial-gradient(circle at 88% 7%, rgba(96, 165, 250, .45), transparent 31%),
      linear-gradient(135deg, var(--frep-deep), #1e40af 62%, var(--frep-blue));
    box-shadow: 0 20px 44px rgba(30, 64, 175, .16);
  }
  .frep-hero::after {
    content: "";
    position: absolute;
    right: -75px;
    bottom: -125px;
    width: 300px;
    height: 300px;
    border: 1px solid rgba(255, 255, 255, .15);
    border-radius: 50%;
  }
  .frep-hero-copy, .frep-hero-summary { position: relative; z-index: 1; }
  .frep-kicker {
    display: block;
    margin-bottom: 9px;
    color: #bfdbfe;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .13em;
    text-transform: uppercase;
  }
  .frep-hero h1 {
    max-width: 720px;
    margin: 0;
    color: #fff;
    font-size: clamp(28px, 3vw, 40px);
    line-height: 1.08;
    letter-spacing: -.035em;
  }
  .frep-hero p {
    max-width: 670px;
    margin: 13px 0 0;
    color: rgba(255, 255, 255, .76);
    font-size: 14px;
    line-height: 1.65;
  }
  .frep-hero-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(116px, 1fr));
    min-width: 270px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 15px;
    background: rgba(255, 255, 255, .1);
    backdrop-filter: blur(8px);
  }
  .frep-hero-metric { padding: 19px 18px; }
  .frep-hero-metric + .frep-hero-metric { border-left: 1px solid rgba(255, 255, 255, .17); }
  .frep-hero-metric strong { display: block; color: #fff; font-size: 29px; line-height: 1; }
  .frep-hero-metric span { display: block; margin-top: 7px; color: #bfdbfe; font-size: 11px; font-weight: 700; }

  .frep-page .frep-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 24px;
  }
  .frep-page .frep-stat {
    position: relative;
    min-width: 0;
    padding: 17px 18px 16px;
    border: 1px solid var(--frep-line);
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
  }
  .frep-page .frep-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 18px;
    width: 32px;
    height: 3px;
    border-radius: 0 0 5px 5px;
    background: var(--frep-blue);
  }
  .frep-page .frep-stat:hover { border-color: rgba(29, 78, 216, .24); box-shadow: 0 12px 28px rgba(30, 64, 175, .09); transform: translateY(-1px); }
  .frep-page .frep-stat-icon { display: none; }
  .frep-page .frep-stat-num { color: #0f172a; font-size: 25px; font-weight: 780; letter-spacing: -.035em; }
  .frep-page .frep-stat-lbl { min-height: 34px; margin-top: 7px; color: var(--frep-muted); font-size: 11px; font-weight: 650; line-height: 1.5; }

  .frep-analytics-grid { display: grid; grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); gap: 18px; align-items: stretch; margin-bottom: 24px; }
  .frep-page .frep-section {
    margin-bottom: 24px;
    padding: 25px 27px;
    border: 1px solid var(--frep-line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 12px 32px rgba(15, 23, 42, .05);
  }
  .frep-analytics-grid .frep-section { height: 100%; box-sizing: border-box; margin-bottom: 0; }
  .frep-page .frep-section-head { align-items: flex-end; margin-bottom: 22px; }
  .frep-page .frep-section-title { color: #0f172a; font-size: 17px; font-weight: 760; letter-spacing: -.015em; }
  .frep-page .frep-section-sub { margin-top: 4px; color: var(--frep-muted); font-size: 11px; line-height: 1.5; }

  .frep-page .frep-bar-list { gap: 15px; }
  .frep-page .frep-bar-row { grid-template-columns: 106px minmax(80px, 1fr) 70px; gap: 11px; }
  .frep-page .frep-bar-label { color: #334155; font-size: 11px; font-weight: 720; }
  .frep-page .frep-bar-track { height: 7px; background: #eef2f7; }
  .frep-page .frep-bar-fill { min-width: 0; }
  .frep-page .frep-bar-count { color: #334155; font-size: 11px; font-weight: 720; }

  .frep-page .frep-filters {
    display: grid;
    grid-template-columns: repeat(2, minmax(120px, 1fr)) auto;
    gap: 10px;
    align-items: end;
    margin: -5px 0 22px;
    padding: 13px;
    border: 1px solid #dbe4ef;
    border-radius: 12px;
    background: #f8fafc;
  }
  .frep-page .frep-field { gap: 5px; }
  .frep-page .frep-field label { color: #64748b; font-size: 9px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
  .frep-page .frep-field input { min-width: 0; width: 100%; box-sizing: border-box; min-height: 38px; padding: 8px 10px; border-color: #cbd5e1; border-radius: 9px; font-size: 11px; }
  .frep-page .frep-field input:focus { border-color: var(--frep-blue); outline: 0; box-shadow: 0 0 0 3px rgba(29, 78, 216, .1); }
  .frep-page .frep-actions { gap: 6px; }
  .frep-page .frep-actions .btn { min-height: 38px; padding: 8px 11px; border-radius: 9px; font-size: 10px; font-weight: 800; }
  .frep-page .frep-activity-totals { gap: 13px; margin-bottom: 17px; }
  .frep-page .frep-activity-total { color: #475569; font-size: 10px; font-weight: 700; }
  .frep-page .frep-activity-swatch { width: 7px; height: 7px; }
  .frep-page .frep-activity-row { grid-template-columns: 62px minmax(80px, 1fr) 64px; gap: 10px; margin-bottom: 12px; }
  .frep-page .frep-activity-month { color: #334155; font-size: 10px; font-weight: 720; }
  .frep-page .frep-activity-track { height: 12px; border-radius: 4px; background: #eef2f7; }
  .frep-page .frep-activity-count { color: #334155; font-size: 10px; font-weight: 720; }

  .frep-page .frep-table-wrap { border-color: var(--frep-line); border-radius: 12px; box-shadow: none; }
  .frep-page .frep-table thead th { padding: 12px 14px; border-bottom-color: var(--frep-line); background: #f1f5f9; color: #475569; font-size: 9px; font-weight: 800; letter-spacing: .065em; }
  .frep-page .frep-table tbody td { padding: 15px 14px; border-top-color: var(--frep-line); color: #334155; font-size: 11px; vertical-align: middle; }
  .frep-page .frep-table tbody tr:hover { background: var(--frep-soft); }
  .frep-page .frep-title { margin-bottom: 4px; color: #0f172a; font-size: 12px; font-weight: 750; line-height: 1.45; }
  .frep-page .frep-title-sub { color: #94a3b8; font-size: 9px; font-weight: 700; }
  .frep-page .frep-students { color: #64748b; font-size: 11px; line-height: 1.5; }
  .frep-page .frep-progress { color: #334155; font-size: 10px; font-weight: 720; }
  .frep-page .frep-progress-bar { width: min(130px, 100%); height: 5px; margin-top: 7px; }
  .frep-page .frep-progress-fill { background: linear-gradient(90deg, var(--frep-blue), #60a5fa); }
  .frep-status, .frep-review-level, .frep-recommendation {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .025em;
    white-space: nowrap;
  }
  .frep-status--draft, .frep-status--archived { background: #e2e8f0; color: #475569; }
  .frep-status--proposal { background: #dbeafe; color: #1d4ed8; }
  .frep-status--in_progress { background: #ede9fe; color: #6d28d9; }
  .frep-status--for_defense { background: #ffedd5; color: #c2410c; }
  .frep-status--completed { background: #dcfce7; color: #15803d; }
  .frep-review-level { background: #dbeafe; color: var(--frep-blue); }
  .frep-recommendation { padding-left: 0; background: transparent; }
  .frep-recommendation--approve { color: #15803d; }
  .frep-recommendation--revise { color: #c2410c; }
  .frep-recommendation--reject { color: #dc2626; }
  .frep-recommendation--none { color: #64748b; }

  .frep-page .frep-empty { padding: 46px 22px; }
  .frep-page .frep-empty-icon { display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 15px; border-radius: 15px; background: var(--frep-soft); color: var(--frep-blue); font-size: 0; }
  .frep-page .frep-empty-icon::after { content: "↗"; font-size: 22px; font-weight: 800; }
  .frep-page .frep-empty-title { color: #0f172a; font-size: 17px; font-weight: 760; }
  .frep-page .frep-empty-sub { max-width: 540px; margin: 0 auto; color: var(--frep-muted); font-size: 12px; line-height: 1.65; }
  .frep-page > .card { overflow: hidden; border: 1px solid var(--frep-line); border-radius: 17px; background: #fff; box-shadow: 0 12px 32px rgba(15, 23, 42, .05); }
  .frep-page .frep-error { margin-bottom: 14px; padding: 12px 15px; border-radius: 11px; font-size: 12px; }

  @media (max-width: 1080px) {
    .frep-page .frep-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .frep-analytics-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 760px) {
    .frep-hero { grid-template-columns: 1fr; gap: 25px; padding: 29px 24px; }
    .frep-hero-summary { width: min(100%, 360px); min-width: 0; }
    .frep-page .frep-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .frep-page .frep-filters { grid-template-columns: 1fr; }
    .frep-page .frep-actions, .frep-page .frep-actions .btn { width: 100%; }
    .frep-page .frep-bar-row, .frep-page .frep-activity-row { grid-template-columns: 1fr; gap: 5px; }
    .frep-page .frep-bar-count, .frep-page .frep-activity-count { text-align: left; }
    .frep-page .frep-section { padding: 21px 18px; }
    .frep-page .frep-table tbody tr { padding: 8px 0; }
    .frep-page .frep-table tbody td { padding: 8px 15px; }
  }
  @media (max-width: 430px) {
    .frep-page .frep-stats { grid-template-columns: 1fr; }
    .frep-page .frep-stat-lbl { min-height: 0; }
  }
</style>

<div class="frep-page">
  <section class="frep-hero" aria-labelledby="frep-hero-title">
    <div class="frep-hero-copy">
      <span class="frep-kicker">Faculty analytics</span>
      <h1 id="frep-hero-title">Your research portfolio, at a glance.</h1>
      <p>Track advised projects, chapter decisions, review activity, and student progress from one reporting workspace.</p>
    </div>
    <div class="frep-hero-summary" aria-label="Reporting summary">
      <div class="frep-hero-metric">
        <strong><?php echo (int) $stat_pending; ?></strong>
        <span>Pending reviews</span>
      </div>
      <div class="frep-hero-metric">
        <strong><?php echo (int) $stat_chapters_done; ?></strong>
        <span>Chapter decisions</span>
      </div>
    </div>
  </section>

<?php if (!empty($errors)): ?>
  <div class="frep-error"><?php echo frep_se(implode(' ', $errors)); ?></div>
<?php endif; ?>

<?php if ($date_filter_error !== ''): ?>
  <div class="frep-error"><?php echo frep_se($date_filter_error); ?></div>
<?php endif; ?>

<?php if ($stat_total_advised === 0): ?>
  <div class="card">
    <div class="frep-empty">
      <div class="frep-empty-icon">📊</div>
      <div class="frep-empty-title">No reports to show yet</div>
      <div class="frep-empty-sub">
        When Research Staff assigns you as adviser on a student project, your analytics —
        status breakdown, monthly review activity, and per-project progress — will appear here.
      </div>
    </div>
  </div>
<?php else: ?>

<div class="frep-stats">
  <div class="frep-stat">
    <span class="frep-stat-icon">📚</span>
    <div class="frep-stat-num"><?php echo (int) $stat_total_advised; ?></div>
    <div class="frep-stat-lbl">Total advised projects</div>
  </div>
  <div class="frep-stat">
    <span class="frep-stat-icon">🔄</span>
    <div class="frep-stat-num"><?php echo (int) $stat_active; ?></div>
    <div class="frep-stat-lbl">Active (proposal → defense)</div>
  </div>
  <div class="frep-stat">
    <span class="frep-stat-icon">✅</span>
    <div class="frep-stat-num"><?php echo (int) $stat_completed; ?></div>
    <div class="frep-stat-lbl">Completed</div>
  </div>
  <div class="frep-stat">
    <span class="frep-stat-icon">📝</span>
    <div class="frep-stat-num"><?php echo (int) $stat_chapters_done; ?></div>
    <div class="frep-stat-lbl">Chapters reviewed (cumulative)</div>
  </div>
  <div class="frep-stat">
    <span class="frep-stat-icon">📥</span>
    <div class="frep-stat-num"><?php echo (int) $stat_pending; ?></div>
    <div class="frep-stat-lbl">Pending reviews</div>
  </div>
</div>

<!-- Section: project status breakdown -->
<div class="frep-analytics-grid">
<div class="frep-section">
  <div class="frep-section-head">
    <div class="frep-section-title">Project status breakdown</div>
    <div class="frep-section-sub">
      <?php echo (int) $status_total; ?> project<?php echo $status_total === 1 ? '' : 's'; ?> across 6 statuses
    </div>
  </div>
  <?php if ($status_total === 0): ?>
    <div class="frep-empty" style="padding:24px 0;">
      <div class="frep-empty-sub">No advised projects yet.</div>
    </div>
  <?php else: ?>
    <div class="frep-bar-list">
      <?php foreach ($status_order as $key => [$fg, $bg, $bd, $label]):
          $count = (int) $status_counts[$key];
          $pct   = $status_total > 0 ? ($count / $status_total) * 100 : 0;
          // Show every status row so the breakdown is complete; zero-count
          // rows render with a 0% fill and a label, making the "no
          // projects in this state" reading clear without hiding data.
          $width = max(0, min(100, $pct));
      ?>
        <div class="frep-bar-row">
          <div class="frep-bar-label"><?php echo frep_se($label); ?></div>
          <div class="frep-bar-track" aria-hidden="true">
            <div class="frep-bar-fill" style="width: <?php echo number_format($width, 1); ?>%; background: <?php echo frep_se($fg); ?>;"></div>
          </div>
          <div class="frep-bar-count">
            <?php echo (int) $count; ?>
            <span style="color:#94A3B8;font-weight:400;">(<?php echo number_format($pct, 0); ?>%)</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Section: review activity -->
<div class="frep-section">
  <div class="frep-section-head">
    <div class="frep-section-title">Review activity</div>
    <div class="frep-section-sub">
      Last 6 months
      <?php if ($has_date_filter): ?>
        &middot; filtered
        <?php echo frep_se($from_raw); ?> → <?php echo frep_se($to_raw); ?>
      <?php endif; ?>
    </div>
  </div>

  <form class="frep-filters" method="get" action="">
    <div class="frep-field">
      <label for="frep-from">From</label>
      <input id="frep-from" type="date" name="from" maxlength="10"
             value="<?php echo frep_se($from_raw); ?>">
    </div>
    <div class="frep-field">
      <label for="frep-to">To</label>
      <input id="frep-to" type="date" name="to" maxlength="10"
             value="<?php echo frep_se($to_raw); ?>">
    </div>
    <div class="frep-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <a class="btn btn-secondary" href="faculty-reports.php">Reset</a>
    </div>
  </form>

  <?php
    $activity_zero = ($activity_total_approved === 0 && $activity_total_revisions === 0);
  ?>
  <?php if ($activity_zero): ?>
    <div class="frep-empty" style="padding:24px 0;">
      <div class="frep-empty-sub">
        <?php if ($has_date_filter): ?>
          No review activity in this date range. Try widening the window.
        <?php else: ?>
          No chapter approvals or revision requests in the last 6 months yet.
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="frep-activity-totals">
      <span class="frep-activity-total">
        <span class="frep-activity-swatch" style="background:#16A34A;"></span>
        Approved: <?php echo (int) $activity_total_approved; ?>
      </span>
      <span class="frep-activity-total">
        <span class="frep-activity-swatch" style="background:#EA580C;"></span>
        Revisions requested: <?php echo (int) $activity_total_revisions; ?>
      </span>
    </div>
    <div>
      <?php foreach ($activity_months as $bucket):
          $sum = (int) $bucket['approved'] + (int) $bucket['revisions'];
          $app_pct = $activity_max > 0 ? ((int) $bucket['approved']  / $activity_max) * 100 : 0;
          $rev_pct = $activity_max > 0 ? ((int) $bucket['revisions'] / $activity_max) * 100 : 0;
      ?>
        <div class="frep-activity-row">
          <div class="frep-activity-month"><?php echo frep_se($bucket['label']); ?></div>
          <div class="frep-activity-track" aria-hidden="true">
            <div class="frep-activity-seg-approved"
                 style="width: <?php echo number_format($app_pct, 1); ?>%;"
                 title="Approved: <?php echo (int) $bucket['approved']; ?>"></div>
            <div class="frep-activity-seg-revisions"
                 style="width: <?php echo number_format($rev_pct, 1); ?>%;"
                 title="Revisions: <?php echo (int) $bucket['revisions']; ?>"></div>
          </div>
          <div class="frep-activity-count">
            <?php echo (int) $sum; ?>
            <span style="color:#94A3B8;font-weight:400;">
              (<?php echo (int) $bucket['approved']; ?> / <?php echo (int) $bucket['revisions']; ?>)
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Section: per-project progress table -->
<!-- Close the paired analytics workspace before the project tables. -->
</div>

<div class="frep-section">
  <div class="frep-section-head">
    <div class="frep-section-title">Per-project progress</div>
    <div class="frep-section-sub">
      <?php echo (int) $stat_total_advised; ?> advised project<?php echo $stat_total_advised === 1 ? '' : 's'; ?>
    </div>
  </div>

  <?php if (empty($advised_projects)): ?>
    <div class="frep-empty" style="padding:24px 0;">
      <div class="frep-empty-sub">No advised projects to display.</div>
    </div>
  <?php else: ?>
    <div class="frep-table-wrap">
      <table class="frep-table table-mobile-stack">
        <thead>
          <tr>
            <th>Research</th>
            <th>Student</th>
            <th>Status</th>
            <th>Chapter progress</th>
            <th>Last activity</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($advised_projects as $proj):
            $pid          = (int) $proj['project_id'];
            $ch_total     = (int) ($chapter_total[$pid] ?? 0);
            $ch_done      = (int) ($chapter_approved[$pid] ?? 0);
            $progress_pct = $ch_total > 0 ? (int) round(($ch_done / max(1, $ch_total)) * 100) : 0;
            $students     = !empty($student_names[$pid]) ? implode(', ', $student_names[$pid]) : '—';
            $last_ts      = $last_activity[$pid] ?? null;
            $display_total = max(5, $ch_total); // match the convention used in
                                                // faculty-submissions.php — show
                                                // progress against the canonical
                                                // 5-chapter structure.
        ?>
          <tr>
            <td data-label="Research">
              <div class="frep-title"><?php echo frep_se((string) $proj['title']); ?></div>
              <div class="frep-title-sub">Project #<?php echo $pid; ?></div>
            </td>
            <td data-label="Student" class="frep-students"><?php echo frep_se($students); ?></td>
            <td data-label="Status"><?php echo frep_status_badge((string) $proj['status']); ?></td>
            <td data-label="Chapter progress">
              <div class="frep-progress"><?php echo $ch_done; ?>/<?php echo (int) $display_total; ?> approved</div>
              <div class="frep-progress-bar" aria-hidden="true">
                <div class="frep-progress-fill" style="width: <?php echo (int) $progress_pct; ?>%;"></div>
              </div>
            </td>
            <td data-label="Last activity"><?php echo frep_se(frep_relative_time($last_ts)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($has_project_reviews && !empty($review_rows)): ?>
  <!-- Section: CREC/EREC review summary (supplementary) -->
  <div class="frep-section">
    <div class="frep-section-head">
      <div class="frep-section-title">CREC / EREC review summary</div>
      <div class="frep-section-sub">
        <?php echo count($review_rows); ?> review<?php echo count($review_rows) === 1 ? '' : 's'; ?> assigned
      </div>
    </div>
    <div class="frep-table-wrap">
      <table class="frep-table table-mobile-stack">
        <thead>
          <tr>
            <th>Project</th>
            <th>Level</th>
            <th>Recommendation</th>
            <th>Score (M / C / A / Ag)</th>
            <th>Reviewed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($review_rows as $rev):
            $m = $rev['methodology_score']  !== null ? (int) $rev['methodology_score']   : null;
            $c = $rev['contribution_score'] !== null ? (int) $rev['contribution_score']  : null;
            $a = $rev['applicability_score']!== null ? (int) $rev['applicability_score'] : null;
            $g = $rev['agenda_score']       !== null ? (int) $rev['agenda_score']        : null;
            $score_str = ($m !== null && $c !== null && $a !== null && $g !== null)
                ? ($m . ' / ' . $c . ' / ' . $a . ' / ' . $g)
                : '—';
            $rec = (string) ($rev['recommendation'] ?? '');
            $rec_label = $rec === '' ? '—' : ucfirst($rec);
            $rec_class = in_array($rec, ['approve', 'revise', 'reject'], true) ? $rec : 'none';
        ?>
          <tr>
            <td data-label="Project">
              <div class="frep-title"><?php echo frep_se((string) ($rev['project_title'] ?? '')); ?></div>
              <div class="frep-title-sub">Project #<?php echo (int) $rev['project_id']; ?></div>
            </td>
            <td data-label="Level">
              <span class="frep-review-level">
                <?php echo frep_se(strtoupper((string) ($rev['review_level'] ?? ''))); ?>
              </span>
            </td>
            <td data-label="Recommendation">
              <span class="frep-recommendation frep-recommendation--<?php echo frep_se($rec_class); ?>">
                <?php echo frep_se($rec_label); ?>
              </span>
            </td>
            <td data-label="Score" class="frep-students"><?php echo frep_se($score_str); ?></td>
            <td data-label="Reviewed"><?php echo frep_se(frep_relative_time($rev['reviewed_at'] ?? null)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php endif; /* end of "has advised projects" guard */ ?>

</div>

<?php renderFacultyShellClose(); ?>
