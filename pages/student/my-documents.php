<?php
/**
 * Student — My Documents
 *
 * Lists every file the logged-in student has uploaded across all of their
 * research projects. Sources of truth:
 *
 *   - `uploads` (database/schema/rms_db.sql lines 341-353) is the canonical
 *     table for student-uploaded files. Its `type` ENUM
 *     ('proposal','chapter','defense','revision','manuscript','other')
 *     plus `project_id`, `chapter_id`, `uploaded_by`, `file_name`,
 *     `file_path`, `file_size`, `mime_type`, `upload_date` give us every
 *     file a student has ever attached.
 *
 *   - `research_documents` is a separate workflow-tracking table (status
 *     pending/submitted/approved/...) and is not used here. It references
 *     `uploads.upload_id` for the underlying file.
 *
 * Scope: a project the student owns (research_projects.created_by) OR is
 * a member of (project_members.user_id). Same pattern as my-research.php
 * and research-detail.php.
 *
 * Soft deletes: `uploads.deleted_at` is set when submit-chapter.php
 * replaces an existing file. We probe at runtime (same SHOW COLUMNS
 * pattern used in research-detail.php) and filter when present.
 *
 * GET filters:
 *   ?type={all|proposal|chapter|defense|revision|manuscript|other}
 *   ?project_id={int}
 *
 * Read-only. No POSTs. No new CSS files.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function mydoc_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------
// Defensive column detection.
// ------------------------------------------------------------------
$uploads_has_deleted_at = false;
$col_stmt = $conn->prepare("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
if ($col_stmt) {
    $col_stmt->execute();
    $uploads_has_deleted_at = $col_stmt->get_result()->num_rows > 0;
    $col_stmt->close();
}
$uploads_deleted_filter = $uploads_has_deleted_at ? ' AND u.deleted_at IS NULL' : '';

$rp_has_deleted_at = false;
$col2 = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($col2) {
    $col2->execute();
    $rp_has_deleted_at = $col2->get_result()->num_rows > 0;
    $col2->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// chapters.updated_at / deleted_at may be added by migration; chapters base
// schema is fine without them, but probe for parity with research-detail.
$ch_has_deleted_at = false;
$col3 = $conn->prepare("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
if ($col3) {
    $col3->execute();
    $ch_has_deleted_at = $col3->get_result()->num_rows > 0;
    $col3->close();
}
$ch_deleted_filter = $ch_has_deleted_at ? ' AND ch.deleted_at IS NULL' : '';

// ------------------------------------------------------------------
// Validate optional GET filters.
// ------------------------------------------------------------------
$valid_types = ['proposal', 'chapter', 'defense', 'revision', 'manuscript', 'other'];
$type_filter = (string) ($_GET['type'] ?? 'all');
if (!in_array($type_filter, $valid_types, true) && $type_filter !== 'all') {
    $type_filter = 'all';
}

$project_filter = 0;
if (isset($_GET['project_id']) && $_GET['project_id'] !== '') {
    $candidate = (int) $_GET['project_id'];
    if ($candidate > 0) { $project_filter = $candidate; }
}

// ------------------------------------------------------------------
// 1. The student's projects (own or member). This is the project_id
//    whitelist that every uploads query joins back to.
// ------------------------------------------------------------------
$projects = []; // [project_id => row]
$proj_sql =
    "SELECT DISTINCT rp.project_id, rp.title, rp.status, rp.created_by, rp.updated_at
       FROM research_projects rp
      WHERE (rp.created_by = ?
             OR rp.project_id IN (
                 SELECT pm.project_id FROM project_members pm WHERE pm.user_id = ?
             ))"
    . $rp_deleted_filter . "
   ORDER BY rp.updated_at DESC, rp.project_id DESC";
$proj_stmt = $conn->prepare($proj_sql);
if ($proj_stmt) {
    $uid_a = $user_id;
    $uid_b = $user_id;
    $proj_stmt->bind_param('ii', $uid_a, $uid_b);
    $proj_stmt->execute();
    $res = $proj_stmt->get_result();
    while ($r = $res->fetch_assoc()) { $projects[(int) $r['project_id']] = $r; }
    $proj_stmt->close();
}

$project_ids = array_keys($projects);
$errors      = [];

// ------------------------------------------------------------------
// 2. Pull every upload for those projects. We join chapter for status
//    (chapter / revision uploads only). We sort by upload_date DESC so
//    the newest is on top.
// ------------------------------------------------------------------
$upload_rows = []; // each row keyed by upload_id

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));

    $sql =
        "SELECT u.upload_id, u.project_id, u.chapter_id, u.uploaded_by, u.type,
                u.original_name, u.file_name, u.file_path, u.file_size,
                u.mime_type, u.upload_date,
                rp.title AS project_title, rp.status AS project_status,
                ch.chapter_number, ch.chapter_title, ch.status AS chapter_status
           FROM uploads u
           JOIN research_projects rp ON rp.project_id = u.project_id
           LEFT JOIN chapters ch ON ch.chapter_id = u.chapter_id
          WHERE u.project_id IN ($id_list)"
        . $uploads_deleted_filter . $rp_deleted_filter . $ch_deleted_filter;

    $params = [];
    $types  = '';
    if ($type_filter !== 'all') {
        $sql    .= " AND u.type = ? ";
        $params[] = $type_filter;
        $types   .= 's';
    }
    if ($project_filter > 0) {
        $sql    .= " AND u.project_id = ? ";
        $params[] = $project_filter;
        $types   .= 'i';
    }
    $sql .= " ORDER BY u.upload_date DESC, u.upload_id DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $bind_args = [$types];
            foreach ($params as $i => $v) { $bind_args[] = &$params[$i]; }
            call_user_func_array([$stmt, 'bind_param'], $bind_args);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $upload_rows[] = $r; }
        $stmt->close();
    } else {
        $errors[] = 'Could not prepare the uploads query.';
    }
}

// ------------------------------------------------------------------
// 3. Compute stats across the unfiltered set so the cards always
//    reflect the student's real totals (the filter narrows the table,
//    not the headline numbers). One pass over $projects + a single
//    grouped query for the uploads.
// ------------------------------------------------------------------
$stat_total      = 0;
$stat_proposals  = 0;
$stat_chapters   = 0; // chapter + revision (both are chapter-related)
$stat_other      = 0; // defense, manuscript, other

if (!empty($project_ids)) {
    $id_list = implode(',', array_map('intval', $project_ids));
    $count_sql =
        "SELECT u.type AS t, COUNT(*) AS c
           FROM uploads u
           JOIN research_projects rp ON rp.project_id = u.project_id
          WHERE u.project_id IN ($id_list)"
        . $uploads_deleted_filter . $rp_deleted_filter . "
       GROUP BY u.type";
    if ($cres = $conn->query($count_sql)) {
        while ($row = $cres->fetch_assoc()) {
            $t = (string) $row['t'];
            $c = (int) $row['c'];
            $stat_total += $c;
            if ($t === 'proposal') {
                $stat_proposals += $c;
            } elseif ($t === 'chapter' || $t === 'revision') {
                $stat_chapters += $c;
            } else {
                $stat_other += $c;
            }
        }
    }
}

// ------------------------------------------------------------------
// 4. Build download URLs. NEVER echo a raw user-controlled value —
//    always derive the URL from the whitelisted type→folder map and
//    the DB-stored file_name. rawurlencode() the file_name to be safe
//    against spaces / unicode in the original filename.
// ------------------------------------------------------------------
$type_to_folder = [
    'proposal'   => 'proposals',
    'chapter'    => 'chapters',
    'revision'   => 'chapters', // revisions land alongside chapter files
    'defense'    => 'defense',
    'manuscript' => 'manuscripts',
    'other'      => 'other',
];
$type_to_label = [
    'proposal'   => ['📄', 'Proposal',  '#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)'],
    'chapter'    => ['📘', 'Chapter',   '#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)'],
    'revision'   => ['✏️', 'Revision',  '#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)'],
    'defense'    => ['🛡️', 'Defense',   '#0d9488', 'rgba(13,148,136,0.10)', 'rgba(13,148,136,0.25)'],
    'manuscript' => ['📚', 'Manuscript','#5B1EBC', 'rgba(91,30,188,0.10)',  'rgba(91,30,188,0.25)'],
    'other'      => ['📎', 'Other',     '#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)'],
];

$project_to_label = [
    'draft'       => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
    'submitted'   => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Submitted'],
    'under_review' => ['#2563EB', 'rgba(37,99,235,0.10)', 'rgba(37,99,235,0.25)',  'Under Review'],
    'under_crec_review' => ['#3B82F6', 'rgba(59,130,246,0.10)', 'rgba(59,130,246,0.25)', 'CREC Review'],
    'under_erec_review' => ['#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)', 'EREC Review'],
    'for_revision' => ['#EA580C', 'rgba(234,88,12,0.10)', 'rgba(234,88,12,0.25)', 'For Revision'],
    'revision_required' => ['#EA580C', 'rgba(234,88,12,0.10)', 'rgba(234,88,12,0.25)', 'Revision Required'],
    'approved'    => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Approved'],
    'ongoing'     => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Ongoing'],
    'proposal'    => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Proposal'],
    'in_progress' => ['#7C3AED', 'rgba(124,58,237,0.10)', 'rgba(124,58,237,0.25)', 'In Progress'],
    'for_defense' => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)',  'For Defense'],
    'completed'   => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Completed'],
    'archived'    => ['#475569', 'rgba(71,85,105,0.10)',  'rgba(71,85,105,0.25)',  'Archived'],
];

$chapter_to_label = [
    'draft'             => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
    'submitted'         => ['#2563EB', 'rgba(37,99,235,0.10)',   'rgba(37,99,235,0.25)',   'Submitted'],
    'under_review'      => ['#7C3AED', 'rgba(124,58,237,0.10)',  'rgba(124,58,237,0.25)',  'Under Review'],
    'revision_required' => ['#EA580C', 'rgba(234,88,12,0.10)',   'rgba(234,88,12,0.25)',   'Needs Revision'],
    'approved'          => ['#16A34A', 'rgba(22,163,74,0.10)',   'rgba(22,163,74,0.25)',   'Approved'],
];

// Resolve each upload's URL. We use a single helper to keep the logic
// consistent and easy to audit. file_exists() is called only on values
// the page itself composed from DB fields, so it's safe.
function mydoc_resolve_url(array $row, array $type_to_folder): string {
    $type = (string) ($row['type'] ?? '');
    $name = (string) ($row['file_name'] ?? '');
    if ($type === '' || $name === '' || !isset($type_to_folder[$type])) {
        return '';
    }
    $folder = $type_to_folder[$type];
    // We never trust $row['file_path'] directly (it may be in legacy form
    // like "../../uploads/chapters/..."). Build the URL from scratch.
    return SITE_URL . 'uploads/' . $folder . '/' . rawurlencode($name);
}

function mydoc_file_exists_check(array $row, array $type_to_folder): bool {
    $type = (string) ($row['type'] ?? '');
    $name = (string) ($row['file_name'] ?? '');
    if ($type === '' || $name === '' || !isset($type_to_folder[$type])) {
        return false;
    }
    $folder = $type_to_folder[$type];
    $path = __DIR__ . '/../../uploads/' . $folder . '/' . $name;
    return is_file($path);
}

// Group uploads by project_id for the sectioned table.
$grouped = []; // [project_id => [project meta, [rows]]

// In the default "All types" view, include accessible projects that do not
// have an upload yet. This makes "All projects" truthful and explains why a
// submitted project may not currently have a document to download.
if ($type_filter === 'all') {
    foreach ($projects as $pid => $project_row) {
        if ($project_filter > 0 && $project_filter !== (int) $pid) {
            continue;
        }
        $grouped[(int) $pid] = [
            'project_id'     => (int) $pid,
            'project_title'  => (string) ($project_row['title'] ?? ''),
            'project_status' => (string) ($project_row['status'] ?? 'draft'),
            'rows'           => [],
        ];
    }
}

foreach ($upload_rows as $r) {
    $pid = (int) $r['project_id'];
    if (!isset($grouped[$pid])) {
        $grouped[$pid] = [
            'project_id'     => $pid,
            'project_title'  => (string) ($r['project_title'] ?? ''),
            'project_status' => (string) ($r['project_status'] ?? 'draft'),
            'rows'           => [],
        ];
    }
    $grouped[$pid]['rows'][] = $r;
}

// ------------------------------------------------------------------
// 5. Helpers — relative time, format date, byte formatter.
// ------------------------------------------------------------------
function mydoc_relative_time($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return mydoc_se((string) $ts); }
    $diff = time() - $t;
    if ($diff < 0) { $diff = 0; }
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return intval($diff / 60)   . ' min ago';
    if ($diff < 86400)  return intval($diff / 3600) . ' hours ago';
    if ($diff < 604800) return intval($diff / 86400). ' days ago';
    if ($diff < 2592000)return intval($diff / 604800). ' weeks ago';
    return date('M d, Y', $t);
}

function mydoc_format_date($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return mydoc_se((string) $ts); }
    return date('M d, Y g:i A', $t);
}

function mydoc_format_size($bytes) {
    if ($bytes === null || $bytes === '' || (int) $bytes <= 0) { return '—'; }
    $b = (int) $bytes;
    if ($b < 1024)             return $b . ' B';
    if ($b < 1048576)          return number_format($b / 1024, 1) . ' KB';
    if ($b < 1073741824)       return number_format($b / 1048576, 1) . ' MB';
    return number_format($b / 1073741824, 2) . ' GB';
}

function mydoc_type_badge($type, $type_to_label) {
    $row = $type_to_label[$type] ?? $type_to_label['other'];
    [$icon, $label, $fg, $bg, $bd] = $row;
    return '<span style="display:inline-flex;align-items:center;gap:6px;'
         . 'font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $fg . ';'
         . 'border:1px solid ' . $bd . ';">'
         . '<span aria-hidden="true">' . $icon . '</span>'
         . mydoc_se($label) . '</span>';
}

function mydoc_status_badge($status, $map) {
    $row = $map[$status] ?? null;
    if (!$row) {
        $label = ucwords(str_replace('_', ' ', (string) $status));
        return '<span style="display:inline-block;font-size:12px;font-weight:500;'
             . 'padding:3px 10px;border-radius:9999px;'
             . 'background:rgba(100,116,139,0.10);color:#64748B;'
             . 'border:1px solid rgba(100,116,139,0.25);">'
             . mydoc_se($label) . '</span>';
    }
    [$fg, $bg, $bd, $label] = $row;
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $fg . ';'
         . 'border:1px solid ' . $bd . ';">'
         . mydoc_se($label) . '</span>';
}

// ------------------------------------------------------------------
// 6. Render the shell.
// ------------------------------------------------------------------
$subtitle = $stat_total === 0
    ? 'Upload your first proposal or chapter to see it tracked here.'
    : $stat_total . ' file' . ($stat_total === 1 ? '' : 's') . ' across '
      . count($projects) . ' project' . (count($projects) === 1 ? '' : 's') . '.';

renderStudentShell($user, 'my-documents', 'My Documents', $subtitle);
?>

<style>
  .mydoc-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .mydoc-stat {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px 22px;
    transition: box-shadow 0.2s, transform 0.2s;
  }
  .mydoc-stat:hover {
    box-shadow: 0 4px 14px rgba(91,30,188,0.10);
    transform: translateY(-1px);
  }
  .mydoc-stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
  }
  .mydoc-stat-lbl {
    font-size: 13px;
    color: #64748B;
    font-weight: 500;
    margin-top: 6px;
  }
  .mydoc-stat-icon {
    float: right;
    font-size: 22px;
    opacity: 0.55;
  }
  .mydoc-stat.purple .mydoc-stat-num { color: #5B1EBC; }
  .mydoc-stat.blue   .mydoc-stat-num { color: #2563EB; }
  .mydoc-stat.violet .mydoc-stat-num { color: #7C3AED; }
  .mydoc-stat.slate  .mydoc-stat-num { color: #475569; }

  .mydoc-filters {
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
  .mydoc-field { display: flex; flex-direction: column; gap: 4px; }
  .mydoc-field label {
    font-size: 12px;
    font-weight: 500;
    color: #64748B;
  }
  .mydoc-field select {
    font-family: inherit;
    font-size: 14px;
    padding: 8px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    min-width: 200px;
  }
  .mydoc-field select:focus {
    outline: 2px solid rgba(91,30,188,0.30);
    outline-offset: 1px;
    border-color: #5B1EBC;
  }
  .mydoc-actions { display: flex; gap: 8px; align-items: flex-end; }
  .mydoc-actions .btn-secondary { text-decoration: none; }

  .mydoc-group {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    margin-bottom: 18px;
    overflow: hidden;
  }
  .mydoc-group-head {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    padding: 16px 20px;
    background: #F8FAFC;
    border-bottom: 1px solid #E5E7EB;
  }
  .mydoc-group-title {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    line-height: 1.3;
  }
  .mydoc-group-sub {
    font-size: 12px;
    color: #64748B;
    margin-top: 2px;
  }
  .mydoc-group-side {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }

  .mydoc-table {
    width: 100%;
    border-collapse: collapse;
  }
  .mydoc-table thead th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 12px 18px;
    background: #ffffff;
    border-bottom: 1px solid #E5E7EB;
  }
  .mydoc-table tbody td {
    padding: 14px 18px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
    color: #111827;
    vertical-align: top;
  }
  .mydoc-table tbody tr:first-child td { border-top: none; }
  .mydoc-table tbody tr:hover { background: #F8FAFC; }
  .mydoc-file-name {
    font-weight: 600;
    color: #111827;
    margin-bottom: 4px;
    line-height: 1.3;
    word-break: break-all;
  }
  .mydoc-file-meta {
    font-size: 12px;
    color: #94A3B8;
  }
  .mydoc-link {
    color: #5B1EBC;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
  }
  .mydoc-link:hover { text-decoration: underline; }
  .mydoc-link.disabled {
    color: #94A3B8;
    cursor: not-allowed;
    pointer-events: none;
  }
  .mydoc-missing {
    font-size: 12px;
    color: #94A3B8;
    font-style: italic;
  }

  .mydoc-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .mydoc-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .mydoc-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
  }
  .mydoc-empty-sub {
    font-size: 14px;
    color: #64748B;
    max-width: 520px;
    margin: 0 auto 18px auto;
  }

  .mydoc-no-results {
    text-align: center;
    padding: 48px 24px;
    color: #64748B;
  }
  .mydoc-no-results-title {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 4px;
  }

  .mydoc-error {
    background: #FEF2F2;
    color: #991B1B;
    border: 1px solid #FECACA;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
  }

  @media (max-width: 720px) {
    .mydoc-table thead { display: none; }
    .mydoc-table tbody td { display: block; padding: 10px 18px; }
    .mydoc-table tbody tr { display: block; border-top: 1px solid #E5E7EB; }
    .mydoc-table tbody tr:first-child { border-top: none; }
    .mydoc-table tbody td::before {
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
  <div class="mydoc-error"><?php echo mydoc_se(implode(' ', $errors)); ?></div>
<?php endif; ?>

<?php if (empty($projects)): ?>
  <!-- Global empty state: the student has no research projects yet. -->
  <div class="mydoc-group">
    <div class="mydoc-empty">
      <div class="mydoc-empty-icon">📂</div>
      <div class="mydoc-empty-title">No documents yet</div>
      <div class="mydoc-empty-sub">
        Files you upload with your research proposal and chapter submissions will appear here.
        Start by submitting your first research project.
      </div>
      <a class="btn btn-primary" href="<?php echo mydoc_se(SITE_URL . 'pages/student/submit-research.php'); ?>">
        ＋ Submit your first research
      </a>
    </div>
  </div>
<?php else: ?>

<div class="mydoc-stats">
  <div class="mydoc-stat purple">
    <span class="mydoc-stat-icon">📂</span>
    <div class="mydoc-stat-num"><?php echo (int) $stat_total; ?></div>
    <div class="mydoc-stat-lbl">Total documents</div>
  </div>
  <div class="mydoc-stat blue">
    <span class="mydoc-stat-icon">📄</span>
    <div class="mydoc-stat-num"><?php echo (int) $stat_proposals; ?></div>
    <div class="mydoc-stat-lbl">Proposals</div>
  </div>
  <div class="mydoc-stat violet">
    <span class="mydoc-stat-icon">📘</span>
    <div class="mydoc-stat-num"><?php echo (int) $stat_chapters; ?></div>
    <div class="mydoc-stat-lbl">Chapter files</div>
  </div>
  <div class="mydoc-stat slate">
    <span class="mydoc-stat-icon">📎</span>
    <div class="mydoc-stat-num"><?php echo (int) $stat_other; ?></div>
    <div class="mydoc-stat-lbl">Defense / Manuscript / Other</div>
  </div>
</div>

<form class="mydoc-filters" method="get" action="">
  <div class="mydoc-field">
    <label for="mydoc-type">Document type</label>
    <select id="mydoc-type" name="type">
      <option value="all"        <?php echo $type_filter === 'all'        ? 'selected' : ''; ?>>All types</option>
      <option value="proposal"   <?php echo $type_filter === 'proposal'   ? 'selected' : ''; ?>>📄 Proposals</option>
      <option value="chapter"    <?php echo $type_filter === 'chapter'    ? 'selected' : ''; ?>>📘 Chapters</option>
      <option value="revision"   <?php echo $type_filter === 'revision'   ? 'selected' : ''; ?>>✏️ Revisions</option>
      <option value="defense"    <?php echo $type_filter === 'defense'    ? 'selected' : ''; ?>>🛡️ Defense</option>
      <option value="manuscript" <?php echo $type_filter === 'manuscript' ? 'selected' : ''; ?>>📚 Manuscripts</option>
      <option value="other"      <?php echo $type_filter === 'other'      ? 'selected' : ''; ?>>📎 Other</option>
    </select>
  </div>
  <?php if (!empty($projects)): ?>
  <div class="mydoc-field">
    <label for="mydoc-project">Project</label>
    <select id="mydoc-project" name="project_id">
      <option value="0">All projects</option>
      <?php foreach ($projects as $pid => $proj): ?>
        <option value="<?php echo (int) $pid; ?>"
                <?php echo $project_filter === (int) $pid ? 'selected' : ''; ?>>
          <?php echo mydoc_se(mb_strimwidth((string) $proj['title'], 0, 70, '…')); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="mydoc-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a class="btn btn-secondary" href="my-documents.php">Reset</a>
  </div>
</form>

<?php if (empty($grouped)): ?>
  <div class="mydoc-group">
    <div class="mydoc-no-results">
      <div class="mydoc-no-results-title">No documents match your filters</div>
      <div>Try changing the document type or selecting a different project.</div>
    </div>
  </div>
<?php else: ?>

  <?php foreach ($grouped as $g):
      $pid = (int) $g['project_id'];
      $project_url = SITE_URL . 'pages/student/research-detail.php?id=' . $pid;
  ?>
    <div class="mydoc-group">
      <div class="mydoc-group-head">
        <div>
          <div class="mydoc-group-title">
            <a href="<?php echo mydoc_se($project_url); ?>" class="mydoc-link" style="font-size:15px;">
              <?php echo mydoc_se($g['project_title']); ?>
            </a>
          </div>
          <div class="mydoc-group-sub">
            Project #<?php echo (int) $pid; ?> &middot;
            <?php echo count($g['rows']); ?> file<?php echo count($g['rows']) === 1 ? '' : 's'; ?>
          </div>
        </div>
        <div class="mydoc-group-side">
          <?php echo mydoc_status_badge($g['project_status'], $project_to_label); ?>
        </div>
      </div>

      <?php if (empty($g['rows'])): ?>
        <div class="mydoc-no-results" style="margin: 0; border: 0; border-top: 1px solid #E5E7EB; border-radius: 0;">
          <div class="mydoc-no-results-title">No documents uploaded for this project</div>
          <div>This project exists, but no proposal or chapter file was attached.</div>
          <a class="mydoc-link" href="<?php echo mydoc_se($project_url); ?>" style="display:inline-block;margin-top:8px;">View project →</a>
        </div>
      <?php else: ?>
      <table class="mydoc-table">
        <thead>
          <tr>
            <th>File</th>
            <th>Type</th>
            <th>Linked to</th>
            <th>Uploaded</th>
            <th>Size</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($g['rows'] as $r):
            $type        = (string) $r['type'];
            $download    = mydoc_resolve_url($r, $type_to_folder);
            $exists      = mydoc_file_exists_check($r, $type_to_folder);
            $size_bytes  = $r['file_size'];
            $original    = (string) ($r['original_name'] ?? '');
            $ext         = strtoupper(pathinfo($original, PATHINFO_EXTENSION));
            if ($ext === '') { $ext = 'FILE'; }

            // Linked-to column: chapter number for chapter/revision, else
            // the project label. For chapter uploads with a chapter_id
            // we show "Chapter N — <title>".
            $linked = '';
            if (($type === 'chapter' || $type === 'revision') && !empty($r['chapter_id'])) {
                $cnum = $r['chapter_number'] !== null ? (int) $r['chapter_number'] : 0;
                $ctit = (string) ($r['chapter_title'] ?? '');
                $linked = $cnum > 0 ? ('Chapter ' . $cnum) : 'Chapter';
                if ($ctit !== '') { $linked .= ' — ' . mb_strimwidth($ctit, 0, 50, '…'); }
            } else {
                $linked = mydoc_se($type_to_label[$type][1] ?? 'Document');
            }

            // Status: chapter status for chapter/revision, else project status.
            $status_html = '';
            if (($type === 'chapter' || $type === 'revision') && !empty($r['chapter_id'])) {
                $cs = (string) ($r['chapter_status'] ?? '');
                $status_html = mydoc_status_badge($cs, $chapter_to_label);
            } else {
                $status_html = mydoc_status_badge((string) $g['project_status'], $project_to_label);
            }
        ?>
          <tr>
            <td data-label="File">
              <div class="mydoc-file-name">
                <?php if ($download !== '' && $exists): ?>
                  <a class="mydoc-link" href="<?php echo mydoc_se($download); ?>" target="_blank" rel="noopener">
                    <?php echo mydoc_se($original !== '' ? $original : (string) $r['file_name']); ?>
                  </a>
                <?php else: ?>
                  <span><?php echo mydoc_se($original !== '' ? $original : (string) $r['file_name']); ?></span>
                <?php endif; ?>
              </div>
              <div class="mydoc-file-meta">
                <?php echo mydoc_se($ext); ?>
                <?php if ($r['mime_type']): ?>
                  &middot; <?php echo mydoc_se((string) $r['mime_type']); ?>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Type"><?php echo mydoc_type_badge($type, $type_to_label); ?></td>
            <td data-label="Linked to" style="color:#475569;font-size:13px;"><?php echo $linked; ?></td>
            <td data-label="Uploaded" style="color:#475569;font-size:13px;white-space:nowrap;">
              <div><?php echo mydoc_se(mydoc_relative_time($r['upload_date'])); ?></div>
              <div style="font-size:11px;color:#94A3B8;"><?php echo mydoc_se(mydoc_format_date($r['upload_date'])); ?></div>
            </td>
            <td data-label="Size" style="color:#475569;font-size:13px;white-space:nowrap;">
              <?php echo mydoc_se(mydoc_format_size($size_bytes)); ?>
            </td>
            <td data-label="Status"><?php echo $status_html; ?></td>
            <td data-label="Action">
              <?php if ($download === ''): ?>
                <span class="mydoc-missing">Unavailable</span>
              <?php elseif (!$exists): ?>
                <span class="mydoc-missing">File missing on disk</span>
              <?php else: ?>
                <a class="mydoc-link" href="<?php echo mydoc_se($download); ?>" target="_blank" rel="noopener">
                Download
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (count($upload_rows) >= 500): ?>
    <div style="text-align:center;margin-top:14px;font-size:13px;color:#94A3B8;">
      Showing the 500 most recent uploads. Refine the filters to see older files.
    </div>
  <?php endif; ?>

<?php endif; /* end of "rows" */ ?>

<?php endif; /* end of "empty projects" */ ?>

<?php renderStudentShellClose(); ?>
