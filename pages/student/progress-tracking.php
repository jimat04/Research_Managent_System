<?php
/**
 * Student — Progress Tracking
 *
 * Read-only workflow visualization for a single student project:
 *  - Workflow timeline (Research Manual 2015 stages) derived from project status
 *  - Chapter progress (5 chapters) with status, dates, latest adviser feedback
 *  - Research Manual milestones (MOU/NDA, progress reports, terminal report,
 *    colloquium, publication) — graceful fallback if tables are missing
 *  - Overall completion percentage combining chapter approvals + milestones
 *
 * Multi-project students get a project switcher (?project_id=, validated).
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── local helpers (escape + display) ─────────────────────────────────────
function ptrack_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function ptrack_status_label($key) {
    $map = [
        'draft'                => 'Draft',
        'submitted'            => 'Submitted',
        'under_review'         => 'Under Review',
        'under_crec_review'    => 'CREC Review',
        'under_erec_review'    => 'EREC Review',
        'for_revision'         => 'For Revision',
        'revision_required'    => 'Revision Required',
        'approved'             => 'Approved',
        'ongoing'              => 'Ongoing',
        'completed'            => 'Completed',
        'archived'             => 'Archived',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
}
function ptrack_status_class($key) {
    $map = [
        'draft'                => 'slate',
        'submitted'            => 'blue',
        'under_review'         => 'blue',
        'under_crec_review'    => 'blue',
        'under_erec_review'    => 'violet',
        'for_revision'         => 'orange',
        'revision_required'    => 'orange',
        'approved'             => 'green',
        'ongoing'              => 'green',
        'completed'            => 'emerald',
        'archived'             => 'slate',
    ];
    return $map[$key] ?? 'slate';
}
function ptrack_format_date($val) {
    if (empty($val) || $val === '0000-00-00 00:00:00' || $val === '0000-00-00') return '';
    $ts = strtotime((string) $val);
    return $ts ? date('M d, Y', $ts) : '';
}
function ptrack_relative_time($val) {
    if (empty($val) || $val === '0000-00-00 00:00:00' || $val === '0000-00-00') return '';
    $ts = strtotime((string) $val);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = abs($diff);
        if ($diff < 60)        return 'in a moment';
        if ($diff < 3600)      return 'in ' . (int)($diff / 60)  . ' min';
        if ($diff < 86400)     return 'in ' . (int)($diff / 3600) . ' hr';
        if ($diff < 2592000)   return 'in ' . (int)($diff / 86400) . ' day' . ((int)($diff/86400) === 1 ? '' : 's');
        return 'on ' . date('M d, Y', $ts);
    }
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return (int)($diff / 60)   . ' min ago';
    if ($diff < 86400)     return (int)($diff / 3600) . ' hr ago';
    if ($diff < 2592000)   return (int)($diff / 86400) . ' day'  . ((int)($diff/86400) === 1 ? '' : 's') . ' ago';
    if ($diff < 31536000)  return (int)($diff / 2592000) . ' mo ago';
    return (int)($diff / 31536000) . ' yr ago';
}

// ── defensive schema detection ───────────────────────────────────────────
// research_projects.deleted_at may or may not exist (added by migration).
$rp_has_deleted_at = false;
$rp_check = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($rp_check) {
    $rp_check->execute();
    $rp_has_deleted_at = $rp_check->get_result()->num_rows > 0;
    $rp_check->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// chapters.deleted_at and updated_at may or may not exist.
$ch_has_deleted = false;
$ch_check = $conn->prepare("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
if ($ch_check) {
    $ch_check->execute();
    $ch_has_deleted = $ch_check->get_result()->num_rows > 0;
    $ch_check->close();
}
$ch_where_extra = $ch_has_deleted ? ' AND deleted_at IS NULL' : '';

// ── research_manual milestone tables (optional, SHOW TABLES fallback) ───
// MySQL's SHOW statement does not accept parameter placeholders, so we
// validate the name against a strict identifier whitelist and inline it.
// (No user input flows into this — only known schema table names.)
function ptrack_table_exists($conn, $name) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $name)) {
        return false;
    }
    $sql = "SHOW TABLES LIKE '" . str_replace("'", "''", (string) $name) . "'";
    $res = $conn->query($sql);
    $exists = $res instanceof mysqli_result && $res->num_rows > 0;
    if ($res instanceof mysqli_result) { $res->free(); }
    return $exists;
}
$tbl_documents   = ptrack_table_exists($conn, 'research_documents');
$tbl_reports     = ptrack_table_exists($conn, 'research_reports');
$tbl_publication = ptrack_table_exists($conn, 'research_publication_tracking');
$tbl_defense     = ptrack_table_exists($conn, 'defense_schedule');
$tbl_reviews     = ptrack_table_exists($conn, 'project_reviews');

// ── fetch all student's projects (owned OR member) ──────────────────────
$projects_sql = "
    SELECT rp.project_id, rp.title, rp.status, rp.created_at, rp.updated_at,
           rc.category_name, ay.label AS ay_label, ay.semester
    FROM research_projects rp
    LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
    LEFT JOIN academic_years ay ON rp.ay_id = ay.ay_id
    WHERE (rp.created_by = ? OR rp.project_id IN (
            SELECT project_id FROM project_members WHERE user_id = ?
        ))" . $rp_deleted_filter . "
    ORDER BY rp.updated_at DESC
";
$projects = [];
$ps = $conn->prepare($projects_sql);
if ($ps) {
    $ps->bind_param('ii', $user_id, $user_id);
    $ps->execute();
    $r = $ps->get_result();
    while ($row = $r->fetch_assoc()) {
        $projects[] = $row;
    }
    $ps->close();
}

// Pick a project — validate ?project_id= against owned/member, else default to most recent.
$selected_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$selected    = null;
if ($selected_id > 0 && !empty($projects)) {
    foreach ($projects as $p) {
        if ((int) $p['project_id'] === $selected_id) { $selected = $p; break; }
    }
}
if (!$selected && !empty($projects)) {
    $selected = $projects[0]; // most recent
}

// ── selected-project data ───────────────────────────────────────────────
$project      = $selected;
$chapters     = [];   // [chapter_number => row]
$last_comments = [];  // [chapter_id => ['comment'=>..., 'type'=>..., 'created_at'=>..., 'faculty_name'=>...]]
$milestones   = [];   // assembled milestone cards
$completion   = ['pct' => 0, 'done' => 0, 'total' => 0, 'has_data' => false];

if ($project) {
    $project_id = (int) $project['project_id'];

    // Chapters
    $cs = $conn->prepare("
        SELECT chapter_id, chapter_number, chapter_title, status,
               submitted_at, approved_at, approved_by, version
        FROM chapters
        WHERE project_id = ?" . $ch_where_extra . "
        ORDER BY chapter_number ASC
    ");
    if ($cs) {
        $cs->bind_param('i', $project_id);
        $cs->execute();
        $r = $cs->get_result();
        while ($row = $r->fetch_assoc()) {
            $chapters[(int) $row['chapter_number']] = $row;
        }
        $cs->close();
    }

    // Latest comment per chapter (correlated subquery for max created_at).
    $chapter_ids = array_map(fn($r) => (int) $r['chapter_id'], $chapters);
    if (!empty($chapter_ids)) {
        $placeholders = implode(',', array_fill(0, count($chapter_ids), '?'));
        $types = str_repeat('i', count($chapter_ids));
        $cm = $conn->prepare("
            SELECT c.chapter_id, c.comment, c.type, c.created_at,
                   CONCAT(u.first_name, ' ', u.last_name) AS faculty_name
            FROM comments c
            INNER JOIN users u ON c.faculty_id = u.user_id
            INNER JOIN (
                SELECT chapter_id, MAX(created_at) AS mx
                FROM comments
                WHERE chapter_id IN ($placeholders)
                GROUP BY chapter_id
            ) m ON m.chapter_id = c.chapter_id AND m.mx = c.created_at
        ");
        if ($cm) {
            $cm->bind_param($types, ...$chapter_ids);
            $cm->execute();
            $r = $cm->get_result();
            while ($row = $r->fetch_assoc()) {
                $last_comments[(int) $row['chapter_id']] = $row;
            }
            $cm->close();
        }
    }

    // ── Milestones (graceful per-table) ─────────────────────────────────
    // 1) MOU / NDA — research_documents WHERE document_type IN ('mou','nda')
    $milestones[] = ['group' => 'MOU / NDA', 'items' => []];
    if ($tbl_documents) {
        $ms = $conn->prepare("
            SELECT document_id, document_type, status, submitted_at, reviewed_at
            FROM research_documents
            WHERE project_id = ? AND document_type IN ('mou','nda')
            ORDER BY document_type ASC
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $r = $ms->get_result();
            while ($row = $r->fetch_assoc()) {
                $milestones[count($milestones) - 1]['items'][] = $row;
            }
            $ms->close();
        }
    }

    // 2) Progress reports — research_reports WHERE report_type='midway_progress'
    $milestones[] = ['group' => 'Progress Reports', 'items' => []];
    if ($tbl_reports) {
        $ms = $conn->prepare("
            SELECT report_id, report_type, status, due_date, submitted_at, reviewed_at
            FROM research_reports
            WHERE project_id = ? AND report_type = 'midway_progress'
            ORDER BY due_date ASC, report_id ASC
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $r = $ms->get_result();
            while ($row = $r->fetch_assoc()) {
                $milestones[count($milestones) - 1]['items'][] = $row;
            }
            $ms->close();
        }
    }

    // 3) Terminal report — research_reports WHERE report_type='terminal'
    $milestones[] = ['group' => 'Terminal Report', 'items' => []];
    if ($tbl_reports) {
        $ms = $conn->prepare("
            SELECT report_id, report_type, status, due_date, submitted_at, reviewed_at
            FROM research_reports
            WHERE project_id = ? AND report_type = 'terminal'
            ORDER BY report_id ASC
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $r = $ms->get_result();
            $milestones[count($milestones) - 1]['items'] = $r->fetch_all(MYSQLI_ASSOC);
            $ms->close();
        }
    }

    // 4) Colloquium — research_publication_tracking.colloquium_date / status
    $milestones[] = ['group' => 'Research Colloquium', 'items' => []];
    if ($tbl_publication) {
        $ms = $conn->prepare("
            SELECT publication_id, colloquium_date, colloquium_status
            FROM research_publication_tracking
            WHERE project_id = ?
            LIMIT 1
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $row = $ms->get_result()->fetch_assoc();
            if ($row) {
                $milestones[count($milestones) - 1]['items'][] = $row;
            }
            $ms->close();
        }
    }

    // 5) Publication / archive — research_publication_tracking journal + archive
    $milestones[] = ['group' => 'Publication & Archive', 'items' => []];
    $pub_summary = null;
    if ($tbl_publication) {
        $ms = $conn->prepare("
            SELECT publication_id, journal_status, journal_reference,
                   archive_status, remarks, updated_at
            FROM research_publication_tracking
            WHERE project_id = ?
            LIMIT 1
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $pub_summary = $ms->get_result()->fetch_assoc();
            $ms->close();
        }
    }
    if ($pub_summary) {
        $milestones[count($milestones) - 1]['items'][] = $pub_summary;
    }

    // 6) Defense schedule (proposal defense scheduled/done)
    $milestones[] = ['group' => 'Proposal Defense', 'items' => []];
    if ($tbl_defense) {
        $ms = $conn->prepare("
            SELECT defense_id, schedule_date, venue, type, status
            FROM defense_schedule
            WHERE project_id = ?
            ORDER BY schedule_date ASC
        ");
        if ($ms) {
            $ms->bind_param('i', $project_id);
            $ms->execute();
            $r = $ms->get_result();
            while ($row = $r->fetch_assoc()) {
                $milestones[count($milestones) - 1]['items'][] = $row;
            }
            $ms->close();
        }
    }

    // ── Overall completion ─────────────────────────────────────────────
    // Two parts: 5 chapters (each 0/1) + milestone subtasks. Equal weight.
    $chapter_done = 0;
    foreach ($chapters as $ch) {
        if (($ch['status'] ?? '') === 'approved') $chapter_done++;
    }
    $chapter_total = 5;
    $chapter_pct = ($chapter_total > 0) ? ($chapter_done / $chapter_total) : 0;

    $milestone_done = 0;
    $milestone_total = 0;
    // MOU/NDA + Progress + Terminal + Colloquium + Publication = 5 milestone groups
    foreach (array_slice($milestones, 0, 5) as $grp) {
        $milestone_total++;
        $items = $grp['items'];
        if (empty($items)) continue;
        // A milestone group is "done" if at least one item is approved/published/presented/done
        $is_done = false;
        foreach ($items as $it) {
            $s = strtolower((string) ($it['status'] ?? $it['colloquium_status'] ?? $it['journal_status'] ?? ''));
            if (in_array($s, ['approved', 'presented', 'published', 'done', 'archived'], true)) {
                $is_done = true; break;
            }
        }
        if ($is_done) $milestone_done++;
    }
    if ($milestone_total === 0) {
        // No milestone tables present — fall back to chapter-only weighting
        $total_units = $chapter_total;
        $done_units  = $chapter_done;
    } else {
        $total_units = $chapter_total + $milestone_total;
        $done_units  = $chapter_done + $milestone_done;
    }
    $pct = ($total_units > 0) ? (int) round(($done_units / $total_units) * 100) : 0;
    $pct = max(0, min(100, $pct));
    $completion = [
        'pct'      => $pct,
        'done'     => $done_units,
        'total'    => $total_units,
        'has_data' => $total_units > 0,
        'chapter_done'  => $chapter_done,
        'chapter_total' => $chapter_total,
        'milestone_done'  => $milestone_done,
        'milestone_total' => $milestone_total,
    ];
}

// ── Workflow stages (Research Manual 2015) ──────────────────────────────
// Each stage: id, label, description, status_match (array of project statuses
// that count as "completed" for this stage), current_match (the single status
// that makes the project "currently in" this stage).
$workflow_stages = [
    ['id' => 'draft',         'label' => 'Draft Proposal',           'desc' => 'Author prepares the research proposal.',                                  'completed' => ['submitted','under_review','under_crec_review','under_erec_review','for_revision','revision_required','approved','ongoing','completed','archived'], 'current' => ['draft']],
    ['id' => 'submitted',     'label' => 'Proposal Submitted',       'desc' => 'Proposal sent for review.',                                              'completed' => ['under_review','under_crec_review','under_erec_review','for_revision','revision_required','approved','ongoing','completed','archived'], 'current' => ['submitted']],
    ['id' => 'crec',          'label' => 'CREC Review',              'desc' => 'College Research Evaluation Committee evaluates the proposal.',            'completed' => ['under_erec_review','for_revision','revision_required','approved','ongoing','completed','archived'], 'current' => ['under_review','under_crec_review']],
    ['id' => 'erec',          'label' => 'EREC Research Forum',      'desc' => 'EARIST Research Evaluation Committee (EREC) forum.',                      'completed' => ['for_revision','revision_required','approved','ongoing','completed','archived'], 'current' => ['under_erec_review']],
    ['id' => 'revision',      'label' => 'Revisions',                'desc' => 'Author revises based on panel feedback.',                                'completed' => ['approved','ongoing','completed','archived'], 'current' => ['for_revision','revision_required']],
    ['id' => 'approved',      'label' => 'Approved',                 'desc' => 'Proposal approved; ready for implementation.',                           'completed' => ['ongoing','completed','archived'], 'current' => ['approved']],
    ['id' => 'implementation','label' => 'Implementation',           'desc' => 'Data gathering and chapter writing (Ch. 1–5).',                           'completed' => ['completed','archived'], 'current' => ['ongoing']],
    ['id' => 'terminal',      'label' => 'Terminal / Final Report',  'desc' => 'Terminal report, final bound copy, and colloquium.',                     'completed' => ['archived'], 'current' => ['completed']],
    ['id' => 'archived',      'label' => 'Archive',                  'desc' => 'Project archived in the research repository.',                           'completed' => ['archived'], 'current' => ['archived']],
];

// Determine current stage for the project
$current_stage_idx = -1;
if ($project) {
    $proj_status = (string) $project['status'];
    foreach ($workflow_stages as $i => $stg) {
        if (in_array($proj_status, $stg['current'], true)) {
            $current_stage_idx = $i;
            break;
        }
    }
    // If project is "draft" or no match, current_stage_idx stays -1; first stage is "current" if status==draft
    if ($current_stage_idx === -1 && $proj_status === 'draft') {
        $current_stage_idx = 0;
    }
}

// ── Page title ──────────────────────────────────────────────────────────
$page_title    = 'Progress Tracking';
$page_subtitle = 'See exactly where your research stands in the review workflow.';
if ($project) {
    $page_subtitle = 'Workflow timeline, chapter progress, and Research Manual milestones for "' . $project['title'] . '".';
}

renderStudentShell($user, 'progress-tracking', $page_title, $page_subtitle);
?>

<style>
  .ptrack-page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 16px; flex-wrap: wrap; margin-bottom: 24px;
  }
  .ptrack-page-title { margin: 0 0 6px 0; color: #111827; font-size: 28px; font-weight: 600; }
  .ptrack-page-sub   { margin: 0; color: #64748B; font-size: 14px; }

  .ptrack-card {
    background: #ffffff; border: 1px solid #E5E7EB; border-radius: 20px;
    padding: 24px; margin-bottom: 20px;
  }
  .ptrack-card-title {
    font-size: 17px; font-weight: 700; color: #111827; margin: 0 0 4px 0;
  }
  .ptrack-card-sub {
    font-size: 13px; color: #64748B; margin: 0 0 16px 0;
  }
  .ptrack-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }

  .ptrack-switcher {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
  }
  .ptrack-switcher select {
    padding: 10px 14px; border: 1px solid #E5E7EB; border-radius: 10px;
    background: #ffffff; color: #111827; font-size: 14px; min-width: 280px;
  }
  .ptrack-switcher select:focus {
    outline: none; border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }
  .ptrack-switcher .btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px;
    border-radius: 10px; font-weight: 600; font-size: 14px; border: none;
    cursor: pointer; text-decoration: none; transition: all 0.2s;
  }
  .ptrack-switcher .btn-primary { background: #5B1EBC; color: white; }
  .ptrack-switcher .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(91,30,188,0.3); }

  /* Stats */
  .ptrack-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px; margin-bottom: 20px;
  }
  .ptrack-stat {
    background: #ffffff; border: 1px solid #E5E7EB; border-radius: 16px;
    padding: 18px;
  }
  .ptrack-stat .label { font-size: 12px; color: #64748B; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
  .ptrack-stat .value { font-size: 28px; font-weight: 700; color: #111827; line-height: 1; }
  .ptrack-stat .meta  { font-size: 12px; color: #94A3B8; margin-top: 6px; }
  .ptrack-stat.purple .value { color: #5B1EBC; }
  .ptrack-stat.green  .value { color: #16A34A; }
  .ptrack-stat.blue   .value { color: #2563EB; }
  .ptrack-stat.orange .value { color: #EA580C; }

  /* Workflow timeline */
  .ptrack-timeline { position: relative; padding-left: 32px; }
  .ptrack-timeline::before {
    content: ''; position: absolute; left: 14px; top: 8px; bottom: 8px;
    width: 2px; background: #E5E7EB;
  }
  .ptrack-stage { position: relative; padding: 12px 0 12px 8px; }
  .ptrack-stage + .ptrack-stage { border-top: 1px dashed #E5E7EB; }
  .ptrack-dot {
    position: absolute; left: -26px; top: 16px;
    width: 22px; height: 22px; border-radius: 50%;
    background: #ffffff; border: 2px solid #E5E7EB;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; color: #94A3B8;
  }
  .ptrack-stage.done .ptrack-dot { background: #16A34A; border-color: #16A34A; color: white; }
  .ptrack-stage.current .ptrack-dot {
    background: #5B1EBC; border-color: #5B1EBC; color: white;
    box-shadow: 0 0 0 4px rgba(91, 30, 188, 0.15);
  }
  .ptrack-stage-title { font-size: 15px; font-weight: 600; color: #111827; margin-bottom: 2px; }
  .ptrack-stage-desc  { font-size: 13px; color: #64748B; line-height: 1.5; }
  .ptrack-stage-meta  { font-size: 12px; color: #94A3B8; margin-top: 4px; }
  .ptrack-stage.current .ptrack-stage-title { color: #5B1EBC; }
  .ptrack-stage.current .ptrack-stage-title::after {
    content: 'Current'; display: inline-block; margin-left: 10px;
    padding: 2px 10px; border-radius: 9999px;
    background: rgba(91, 30, 188, 0.1); color: #5B1EBC;
    font-size: 11px; font-weight: 600;
  }

  /* Chapter list */
  .ptrack-chapters { display: flex; flex-direction: column; gap: 12px; }
  .ptrack-chapter {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px; background: #F8FAFC;
    border: 1px solid #E5E7EB; border-radius: 12px;
  }
  .ptrack-chapter-num {
    width: 40px; height: 40px; border-radius: 10px;
    background: #111827; color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px; flex-shrink: 0;
  }
  .ptrack-chapter-num.approved { background: #16A34A; }
  .ptrack-chapter-num.review   { background: #2563EB; }
  .ptrack-chapter-num.revision { background: #EA580C; }
  .ptrack-chapter-num.draft    { background: #64748B; }
  .ptrack-chapter-num.submitted{ background: #7C3AED; }
  .ptrack-chapter-body { flex: 1; min-width: 0; }
  .ptrack-chapter-name { font-weight: 600; color: #111827; margin-bottom: 4px; }
  .ptrack-chapter-meta { font-size: 12px; color: #64748B; line-height: 1.6; }
  .ptrack-chapter-feedback {
    margin-top: 10px; padding: 10px 12px;
    background: #ffffff; border-left: 3px solid #7C3AED;
    border-radius: 6px; font-size: 13px; color: #111827; line-height: 1.5;
  }
  .ptrack-chapter-feedback .who { font-size: 11px; color: #7C3AED; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }

  /* Badges */
  .ptrack-badge {
    display: inline-block; padding: 4px 12px; border-radius: 9999px;
    font-size: 12px; font-weight: 600; white-space: nowrap;
  }
  .ptrack-badge-slate   { background: #F1F5F9; color: #475569; }
  .ptrack-badge-blue    { background: #DBEAFE; color: #2563EB; }
  .ptrack-badge-violet  { background: #EDE9FE; color: #7C3AED; }
  .ptrack-badge-orange  { background: #FEF3C7; color: #EA580C; }
  .ptrack-badge-green   { background: #DCFCE7; color: #16A34A; }
  .ptrack-badge-emerald { background: #D1FAE5; color: #059669; }

  /* Milestones */
  .ptrack-milestone { padding: 16px; background: #F8FAFC; border: 1px solid #E5E7EB; border-radius: 12px; margin-bottom: 12px; }
  .ptrack-milestone-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
  .ptrack-milestone-name { font-weight: 600; color: #111827; }
  .ptrack-milestone-sub  { font-size: 12px; color: #94A3B8; margin-top: 2px; }
  .ptrack-milestone-row  { display: flex; align-items: center; gap: 12px; padding: 8px 0; font-size: 13px; color: #111827; flex-wrap: wrap; }
  .ptrack-milestone-row + .ptrack-milestone-row { border-top: 1px dashed #E5E7EB; }
  .ptrack-empty-mini { color: #94A3B8; font-size: 13px; font-style: italic; }

  /* Progress bar */
  .ptrack-progress { width: 100%; height: 12px; background: #F1F5F9; border-radius: 9999px; overflow: hidden; margin-top: 12px; }
  .ptrack-progress-bar { height: 100%; background: linear-gradient(90deg, #5B1EBC, #7C3AED); border-radius: 9999px; transition: width 0.4s ease; }
  .ptrack-progress-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 13px; color: #64748B; }

  /* Empty state */
  .ptrack-empty {
    background: #ffffff; border: 1px solid #E5E7EB; border-radius: 20px;
    padding: 48px 24px; text-align: center;
  }
  .ptrack-empty .ico { font-size: 56px; margin-bottom: 12px; }
  .ptrack-empty h3  { margin: 0 0 6px 0; color: #111827; font-size: 18px; }
  .ptrack-empty p   { margin: 0 0 20px 0; color: #64748B; font-size: 14px; }

  .ptrack-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px;
    border-radius: 10px; font-weight: 600; font-size: 14px; border: none;
    cursor: pointer; text-decoration: none; transition: all 0.2s;
    font-family: 'Inter', sans-serif;
  }
  .ptrack-btn-primary   { background: #5B1EBC; color: white; }
  .ptrack-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(91,30,188,0.3); }
  .ptrack-btn-secondary { background: #F8FAFC; color: #111827; border: 1px solid #E5E7EB; }
  .ptrack-btn-secondary:hover { background: #111827; color: white; }

  /* Inline link to research detail */
  .ptrack-link { color: #5B1EBC; text-decoration: none; font-size: 13px; }
  .ptrack-link:hover { text-decoration: underline; }

  @media (max-width: 768px) {
    .ptrack-page-header { flex-direction: column; }
    .ptrack-switcher select { min-width: 0; width: 100%; }
  }

  .student-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 3%,rgba(91,30,188,.13),transparent 27%),radial-gradient(circle at 7% 47%,rgba(37,99,235,.07),transparent 24%),linear-gradient(180deg,#F4F1FA 0%,#ECE8F5 100%)}
  .student-topbar{background:rgba(255,255,255,.93);backdrop-filter:blur(14px)}
  .ptrack-page{--purple:#5B1EBC;--ink:#140D1E;--muted:#6D6279;--line:#DED5E8;max-width:1160px;margin:0 auto;padding-bottom:44px;color:var(--ink)}
  .ptrack-page-header{position:relative;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr);gap:42px;overflow:hidden;margin-bottom:22px;padding:38px 40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 91% 8%,rgba(220,198,255,.25),transparent 28%),radial-gradient(circle at 8% 118%,rgba(43,110,230,.24),transparent 32%),linear-gradient(135deg,#291050 0%,#4C188F 57%,#6C2CC7 100%);color:#fff;box-shadow:0 22px 52px rgba(54,24,103,.22)}.ptrack-page-header::after{content:'';position:absolute;right:-75px;bottom:-150px;width:300px;height:300px;border:46px solid rgba(255,255,255,.055);border-radius:50%;pointer-events:none}.ptrack-page-header>div,.ptrack-switcher{position:relative;z-index:1}.ptrack-kicker{margin:0 0 11px;color:#DCCBFF;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.ptrack-page-title{max-width:670px;margin:0 0 14px;color:#fff;font-size:clamp(29px,3.2vw,44px);font-weight:750;letter-spacing:-.045em;line-height:1.08;text-wrap:balance}.ptrack-page-sub{max-width:59ch;color:#E8E0F3;font-size:14px;line-height:1.65}.ptrack-switcher{display:grid;align-content:center;gap:8px;padding-left:34px;border-left:1px solid rgba(255,255,255,.24)}.ptrack-switcher label{color:#D9CDE9;font-size:10px;font-weight:750;letter-spacing:.05em;text-transform:uppercase}.ptrack-switcher select{width:100%;min-width:0;min-height:46px;padding:10px 13px;border:1px solid rgba(255,255,255,.23);border-radius:11px;background:rgba(255,255,255,.12);color:#fff;font:650 12px 'Inter',sans-serif;backdrop-filter:blur(7px)}.ptrack-switcher select option{background:#351064;color:#fff}.ptrack-switcher select:focus{border-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.17)}
  .ptrack-card{margin-bottom:20px;padding:26px 27px 29px;border-color:#D9D0E4;border-radius:19px;background:rgba(255,255,255,.96);box-shadow:0 13px 34px rgba(45,24,76,.07)}.ptrack-card-title{color:#24162F;font-size:18px;letter-spacing:-.025em}.ptrack-card-sub{max-width:78ch;color:#756A80;font-size:11px;line-height:1.6}.ptrack-overall{position:relative;overflow:hidden;padding:24px 28px;border:0;background:linear-gradient(105deg,#F8F3FD 0%,#EDE3F9 100%);box-shadow:inset 4px 0 0 #5B1EBC,0 12px 30px rgba(45,24,76,.07)}.ptrack-overall::after{content:'';position:absolute;right:-42px;top:-66px;width:150px;height:150px;border:24px solid rgba(91,30,188,.055);border-radius:50%}.ptrack-overall .ptrack-row,.ptrack-overall .ptrack-progress{position:relative;z-index:1}.ptrack-overall .ptrack-row>div:last-child{color:#5B1EBC!important;font-size:36px!important;font-weight:750!important;letter-spacing:-.045em;font-variant-numeric:tabular-nums}.ptrack-progress{height:9px;margin-top:18px;background:#DED4E8}.ptrack-progress-bar{background:linear-gradient(90deg,#4A168F,#6F2BC2);box-shadow:0 3px 10px rgba(91,30,188,.2)}
  .ptrack-primary-grid{display:grid;grid-template-columns:minmax(310px,.78fr) minmax(0,1.22fr);gap:20px;align-items:start}.ptrack-primary-grid>.ptrack-card{height:100%}.ptrack-workflow-card{border-top:4px solid #5B1EBC}.ptrack-chapters-card{border-top:4px solid #07855F}.ptrack-timeline{padding-left:34px}.ptrack-timeline::before{left:14px;width:2px;background:#E2D8EC}.ptrack-stage{padding:13px 9px 13px 10px;border-radius:10px}.ptrack-stage+.ptrack-stage{border-top:1px dashed #E3DBEA}.ptrack-stage.current{margin:4px 0;padding:15px 13px;background:#F4EDFC}.ptrack-dot{left:-28px;top:15px;width:24px;height:24px;border-color:#D8CDE2;background:#FBF9FD;color:#8C8197;font-size:10px}.ptrack-stage.done .ptrack-dot{border-color:#07855F;background:#07855F}.ptrack-stage.current .ptrack-dot{border-color:#5B1EBC;background:#5B1EBC;box-shadow:0 0 0 5px rgba(91,30,188,.12)}.ptrack-stage-title{color:#2A1E34;font-size:12px;font-weight:750}.ptrack-stage-desc{margin-top:3px;color:#796E83;font-size:10px;line-height:1.5}.ptrack-stage-meta{color:#9A8FA4;font-size:9px}.ptrack-stage.current .ptrack-stage-title::after{padding:3px 7px;border-radius:6px;font-size:8px;letter-spacing:.04em;text-transform:uppercase}
  .ptrack-chapters{gap:9px}.ptrack-chapter{align-items:center;padding:13px 14px;border-color:#E2D9E9;border-radius:13px;background:#FBFAFC;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.ptrack-chapter:hover{z-index:1;border-color:#BDA8CE;box-shadow:0 9px 22px rgba(45,24,76,.08);transform:translateY(-1px)}.ptrack-chapter-num{width:39px;height:42px;border-radius:11px;background:#70647B;font-size:13px}.ptrack-chapter-name{color:#291E33;font-size:12px;font-weight:750}.ptrack-chapter-meta{color:#82778C;font-size:9px}.ptrack-chapter-feedback{margin-top:9px;padding:10px 11px;border-left-color:#7C3AED;border-radius:8px;background:#F5F0FA;color:#3D2B4C;font-size:10px}.ptrack-chapter-feedback .who{font-size:8px}.ptrack-chapter .ptrack-link{flex:0 0 auto}
  .ptrack-milestones-card{border-top:4px solid #C55408}.ptrack-milestone-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ptrack-milestone{height:100%;margin:0;padding:17px;border-color:#E1D8E8;border-radius:14px;background:#FBFAFC}.ptrack-milestone-head{margin-bottom:12px}.ptrack-milestone-name{color:#2B1F35;font-size:12px;font-weight:750}.ptrack-milestone-sub{color:#8A7F93;font-size:9px;line-height:1.45}.ptrack-milestone-row{color:#3C3045;font-size:10px}.ptrack-empty-mini{padding:11px;border-radius:9px;background:#F3EFF6;color:#84788E!important;font-size:9px!important;font-style:normal}.ptrack-badge{padding:4px 8px;border-radius:7px;font-size:9px;font-weight:750}.ptrack-actions-card{padding:19px 21px;border-top:4px solid #2563EB}.ptrack-actions-card .ptrack-card-title{font-size:14px}.ptrack-btn{min-height:42px;padding:10px 16px;border:1px solid transparent;border-radius:10px;font-size:11px;font-weight:700}.ptrack-btn-primary{background:#5B1EBC}.ptrack-btn-primary:hover{background:#491598;box-shadow:0 9px 18px rgba(91,30,188,.2)}.ptrack-btn-secondary{border-color:#D8D0E3;background:#fff;color:#281B35}.ptrack-btn-secondary:hover{border-color:#B9AACA;background:#F5F0FA;color:#4B168F}.ptrack-link{display:inline-flex;align-items:center;color:#5B1EBC;font-size:10px;font-weight:750;text-decoration:none}.ptrack-link:hover{color:#40107F;text-decoration:none}.ptrack-page a:focus-visible,.ptrack-page select:focus-visible,.ptrack-page button:focus-visible{outline:3px solid rgba(91,30,188,.25);outline-offset:3px}.ptrack-empty{padding:60px 28px;border-color:#D9D0E4;background:radial-gradient(circle at 50% 13%,rgba(91,30,188,.1),transparent 38%),#FBF9FD;box-shadow:0 15px 36px rgba(45,24,76,.08)}.ptrack-empty .ico{display:grid;width:72px;height:72px;margin:0 auto 18px;place-items:center;border-radius:21px;background:#EEE4FA;font-size:31px}.ptrack-empty h3{font-size:22px;letter-spacing:-.03em}.ptrack-empty p{max-width:530px;margin-right:auto;margin-left:auto;color:#766A81;line-height:1.65}
  .ptrack-switcher label{color:#EEE6FA!important}.ptrack-switcher select:not(:disabled){appearance:none;-webkit-appearance:none;overflow:hidden;padding:11px 44px 11px 14px!important;border-color:rgba(255,255,255,.45);background-color:#F8F3FD!important;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='m5 7.5 5 5 5-5' stroke='%235B1EBC' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E")!important;background-repeat:no-repeat!important;background-position:right 14px center!important;background-size:16px!important;color:#24162F!important;-webkit-text-fill-color:#24162F!important;text-overflow:ellipsis;white-space:nowrap;box-shadow:0 8px 20px rgba(24,7,52,.1)}.ptrack-switcher select:not(:disabled):hover{border-color:#D7C6E8;background-color:#fff!important}.ptrack-switcher select:not(:disabled):focus{border-color:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.17),0 8px 20px rgba(24,7,52,.12)}.ptrack-switcher select:not(:disabled) option{background:#fff!important;color:#24162F!important;-webkit-text-fill-color:#24162F!important}
  .ptrack-switcher-label{color:#EEE6FA;font-size:10px;font-weight:750;letter-spacing:.05em;text-transform:uppercase}.ptrack-project-menu{min-width:0}.ptrack-project-menu summary{display:grid;grid-template-columns:minmax(0,1fr) 18px;align-items:center;gap:12px;min-height:48px;padding:11px 14px 11px 16px;border:1px solid rgba(255,255,255,.45);border-radius:11px;background:#F8F3FD;color:#24162F;font-size:12px;font-weight:700;line-height:1.35;cursor:pointer;box-shadow:0 8px 20px rgba(24,7,52,.1);list-style:none;transition:background .2s ease,border-color .2s ease,box-shadow .2s ease}.ptrack-project-menu summary::-webkit-details-marker{display:none}.ptrack-project-menu summary>span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ptrack-project-menu summary:hover{border-color:#fff;background:#fff}.ptrack-project-chevron{width:9px;height:9px;justify-self:center;border-right:2px solid #5B1EBC;border-bottom:2px solid #5B1EBC;transform:translateY(-2px) rotate(45deg);transition:transform .2s ease}.ptrack-project-menu[open] .ptrack-project-chevron{transform:translateY(2px) rotate(225deg)}.ptrack-project-options{display:grid;gap:5px;max-height:238px;margin-top:8px;padding:7px;overflow-y:auto;border:1px solid rgba(255,255,255,.35);border-radius:12px;background:#F8F3FD;box-shadow:0 14px 30px rgba(24,7,52,.2)}.ptrack-project-options a{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;padding:11px 12px;border-radius:8px;color:#352641;font-size:11px;font-weight:650;line-height:1.4;text-decoration:none;transition:background .18s ease,color .18s ease}.ptrack-project-options a:hover{background:#EDE2F8;color:#4B168F}.ptrack-project-options a.is-active{background:#5B1EBC;color:#fff}.ptrack-project-check{display:grid;width:20px;height:20px;place-items:center;border-radius:6px;background:rgba(255,255,255,.18);font-size:10px}.ptrack-project-menu summary:focus-visible{outline:3px solid rgba(255,255,255,.3);outline-offset:3px}
  @keyframes ptrackEnter{from{opacity:0;transform:translateY(13px)}to{opacity:1;transform:translateY(0)}}
  @media(prefers-reduced-motion:no-preference){.ptrack-page-header,.ptrack-overall,.ptrack-primary-grid,.ptrack-milestones-card,.ptrack-actions-card,.ptrack-empty{animation:ptrackEnter .48s cubic-bezier(.16,1,.3,1) both}.ptrack-overall{animation-delay:.05s}.ptrack-primary-grid{animation-delay:.1s}.ptrack-milestones-card{animation-delay:.15s}.ptrack-actions-card{animation-delay:.2s}}
  @media(max-width:980px){.ptrack-primary-grid{grid-template-columns:1fr}.ptrack-primary-grid>.ptrack-card{height:auto}.ptrack-milestone-grid{grid-template-columns:1fr}}
  @media(max-width:760px){.ptrack-page-header{grid-template-columns:1fr;padding:30px 24px}.ptrack-switcher{padding:22px 0 0;border-top:1px solid rgba(255,255,255,.24);border-left:0}.ptrack-overall .ptrack-row{align-items:flex-start;flex-direction:column}.ptrack-card{padding:23px 20px 26px}.ptrack-chapter{align-items:flex-start;flex-wrap:wrap}.ptrack-chapter .ptrack-link{margin-left:53px}.ptrack-actions-card .ptrack-row{display:grid;grid-template-columns:1fr 1fr}.ptrack-actions-card .ptrack-btn-primary{grid-column:1/-1}}
  @media(max-width:500px){.ptrack-page-header{padding:27px 21px}.ptrack-actions-card .ptrack-row{grid-template-columns:1fr}.ptrack-actions-card .ptrack-btn-primary{grid-column:auto}.ptrack-btn{width:100%;justify-content:center}.ptrack-chapter .ptrack-link{width:auto;margin-left:53px}.ptrack-milestone-head{align-items:flex-start;flex-direction:column}}
  @media(prefers-reduced-motion:reduce){.ptrack-page *{animation:none!important;transition:none!important}}
</style>

<main class="ptrack-page">

<?php if (empty($projects)): ?>
  <!-- NO PROJECTS YET -->
  <div class="ptrack-empty">
    <div class="ico">📭</div>
    <h3>No research projects yet</h3>
    <p>Submit your first research proposal to start tracking your progress through CREC, EREC, and final review.</p>
    <a class="ptrack-btn ptrack-btn-primary" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">+ Submit Your First Research</a>
  </div>

<?php else: ?>

  <!-- PAGE HEADER + PROJECT SWITCHER -->
  <header class="ptrack-page-header">
    <div>
      <p class="ptrack-kicker">Research journey</p>
      <h2 class="ptrack-page-title"><?php echo ptrack_se((string) ($project['title'] ?? 'Progress tracking')); ?></h2>
      <p class="ptrack-page-sub">Follow the project from proposal review through chapters, milestones, and archive.</p>
    </div>
    <div class="ptrack-switcher">
      <span class="ptrack-switcher-label">Viewing project</span>
      <details class="ptrack-project-menu">
        <summary>
          <span><?php echo ptrack_se((string) ($project['title'] ?? 'Choose a project')); ?></span>
          <span class="ptrack-project-chevron" aria-hidden="true"></span>
        </summary>
        <nav class="ptrack-project-options" aria-label="Choose a research project">
          <?php foreach ($projects as $p): ?>
            <?php $is_active_project = (int) $p['project_id'] === (int) ($project['project_id'] ?? 0); ?>
            <a href="<?php echo SITE_URL; ?>pages/student/progress-tracking.php?project_id=<?php echo (int) $p['project_id']; ?>" class="<?php echo $is_active_project ? 'is-active' : ''; ?>" <?php echo $is_active_project ? 'aria-current="page"' : ''; ?>>
              <span><?php echo ptrack_se($p['title']); ?></span>
              <?php if ($is_active_project): ?><span class="ptrack-project-check" aria-hidden="true">&#10003;</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
      </details>
    </div>
  </header>

  <?php if (!$project): ?>
    <div class="ptrack-card ptrack-overall">
      <div style="text-align: center; color: #94A3B8; padding: 32px 0;">
        <div style="font-size: 48px; margin-bottom: 12px;">🔍</div>
        <p style="margin: 0; color: #111827; font-weight: 600;">Project not found</p>
        <p style="margin: 6px 0 0 0;">The selected project does not exist or you no longer have access.</p>
      </div>
    </div>

  <?php else: ?>

    <!-- OVERALL COMPLETION -->
    <div class="ptrack-card ptrack-overall">
      <div class="ptrack-row" style="justify-content: space-between;">
        <div>
          <h3 class="ptrack-card-title" style="margin-bottom: 4px;">Overall Completion</h3>
          <p class="ptrack-card-sub" style="margin: 0;">
            <?php if ($completion['has_data']): ?>
              <?php echo (int) $completion['done']; ?> of <?php echo (int) $completion['total']; ?> key items complete
              · <?php echo (int) $completion['chapter_done']; ?>/<?php echo (int) $completion['chapter_total']; ?> chapters approved
              <?php if ($completion['milestone_total'] > 0): ?>
                · <?php echo (int) $completion['milestone_done']; ?>/<?php echo (int) $completion['milestone_total']; ?> milestones
              <?php endif; ?>
            <?php else: ?>
              Completion data will appear once you submit your proposal.
            <?php endif; ?>
          </p>
        </div>
        <div style="font-size: 32px; font-weight: 700; color: #5B1EBC;"><?php echo (int) $completion['pct']; ?>%</div>
      </div>
      <div class="ptrack-progress" aria-label="Overall completion">
        <div class="ptrack-progress-bar" style="width: <?php echo (int) $completion['pct']; ?>%;"></div>
      </div>
    </div>

    <!-- WORKFLOW TIMELINE -->
    <div class="ptrack-primary-grid">
    <div class="ptrack-card ptrack-workflow-card">
      <h3 class="ptrack-card-title">Research Workflow</h3>
      <p class="ptrack-card-sub">Each stage reflects the EARIST Research Manual 2015. Current stage is highlighted based on your project's status (<?php echo ptrack_se(ptrack_status_label((string) $project['status'])); ?>).</p>
      <div class="ptrack-timeline">
        <?php foreach ($workflow_stages as $i => $stg):
          $state = 'pending';
          if ($current_stage_idx >= 0 && $i < $current_stage_idx) {
              $state = 'done';
          } elseif ($i === $current_stage_idx) {
              $state = 'current';
          }
          $dot = $state === 'done' ? '✓' : ($state === 'current' ? '●' : (string) ($i + 1));
        ?>
          <div class="ptrack-stage <?php echo $state; ?>">
            <div class="ptrack-dot"><?php echo ptrack_se($dot); ?></div>
            <div class="ptrack-stage-title"><?php echo ptrack_se($stg['label']); ?></div>
            <div class="ptrack-stage-desc"><?php echo ptrack_se($stg['desc']); ?></div>
            <?php if ($i === 0): ?>
              <div class="ptrack-stage-meta">Project created: <?php echo ptrack_se(ptrack_format_date($project['created_at'])); ?></div>
            <?php elseif ($i === 5 && $state === 'done'): /* approved stage; show when it cleared */ ?>
              <div class="ptrack-stage-meta">Project last updated: <?php echo ptrack_se(ptrack_format_date($project['updated_at'])); ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- CHAPTER PROGRESS -->
    <div class="ptrack-card ptrack-chapters-card">
      <h3 class="ptrack-card-title">Chapter Progress</h3>
      <p class="ptrack-card-sub"><?php echo (int) $completion['chapter_done']; ?> of 5 chapters approved · latest adviser feedback shown when available.</p>
      <?php
        $chapter_titles = [
          1 => 'The Problem and Its Background',
          2 => 'Review of Related Literature',
          3 => 'Methodology',
          4 => 'Results and Discussion',
          5 => 'Summary, Conclusions, and Recommendations',
        ];
        $ch_status_class = [
          'approved'          => 'approved',
          'under_review'      => 'review',
          'revision_required' => 'revision',
          'for_revision'      => 'revision',
          'submitted'         => 'submitted',
          'draft'             => 'draft',
        ];
        $ch_status_color = [
          'approved'          => '#16A34A',
          'under_review'      => '#2563EB',
          'revision_required' => '#EA580C',
          'for_revision'      => '#EA580C',
          'submitted'         => '#7C3AED',
          'draft'             => '#64748B',
        ];
      ?>
      <div class="ptrack-chapters">
        <?php foreach ($chapter_titles as $num => $default_title):
          $row = $chapters[$num] ?? null;
          $status = $row['status'] ?? 'draft';
          $cls = $ch_status_class[$status] ?? 'draft';
          $color = $ch_status_color[$status] ?? '#64748B';
          $title = $row['chapter_title'] ?? $default_title;
          $fb = $row ? ($last_comments[(int) $row['chapter_id']] ?? null) : null;
        ?>
          <div class="ptrack-chapter">
            <div class="ptrack-chapter-num <?php echo ptrack_se($cls); ?>"><?php echo (int) $num; ?></div>
            <div class="ptrack-chapter-body">
              <div class="ptrack-chapter-name"><?php echo ptrack_se($title); ?></div>
              <div class="ptrack-chapter-meta">
                <?php if ($row): ?>
                  v<?php echo (int) ($row['version'] ?? 1); ?>
                  <?php if (!empty($row['submitted_at'])): ?>· Submitted <?php echo ptrack_se(ptrack_format_date($row['submitted_at'])); ?><?php endif; ?>
                  <?php if (!empty($row['approved_at'])): ?>· Approved <?php echo ptrack_se(ptrack_format_date($row['approved_at'])); ?><?php endif; ?>
                <?php else: ?>
                  Not yet started
                <?php endif; ?>
                · <span style="color: <?php echo $color; ?>; font-weight: 600;"><?php echo ptrack_se(ptrack_status_label($status)); ?></span>
              </div>
              <?php if ($fb): ?>
                <div class="ptrack-chapter-feedback">
                  <div class="who">Adviser feedback (<?php echo ptrack_se(ucfirst((string) $fb['type'])); ?>) · <?php echo ptrack_se(ptrack_relative_time($fb['created_at'])); ?></div>
                  <?php echo ptrack_se(mb_strimwidth((string) $fb['comment'], 0, 280, '…')); ?>
                </div>
              <?php endif; ?>
            </div>
            <?php if (in_array((string) $project['status'], ['approved','ongoing'], true)): ?>
              <a class="ptrack-link" href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $project['project_id']; ?>">Submit / view →</a>
            <?php else: ?>
              <a class="ptrack-link" href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project['project_id']; ?>">View →</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    </div>

    <!-- RESEARCH MANUAL MILESTONES -->
    <div class="ptrack-card ptrack-milestones-card">
      <h3 class="ptrack-card-title">Research Manual Milestones</h3>
      <p class="ptrack-card-sub">Required documents from the EARIST Research Manual 2015. Some sections depend on workflow-tracking tables — empty groups just mean no entries yet.</p>

      <div class="ptrack-milestone-grid">
      <?php
      // Render the 5 logical milestone groups
      $rendered_groups = 0;
      foreach ($milestones as $group_index => $grp):
        // Skip the duplicate "Publication & Archive" view if it has nothing
        $group_name = $grp['group'];
        $items      = $grp['items'];
      ?>
        <div class="ptrack-milestone">
          <div class="ptrack-milestone-head">
            <div>
              <div class="ptrack-milestone-name"><?php echo ptrack_se($group_name); ?></div>
              <?php
                $subtitle = '';
                if ($group_name === 'MOU / NDA')            $subtitle = 'Memorandum of Understanding / Non-Disclosure Agreement';
                elseif ($group_name === 'Progress Reports') $subtitle = 'Midway progress reports during implementation';
                elseif ($group_name === 'Terminal Report')  $subtitle = 'Final terminal report submitted before defense';
                elseif ($group_name === 'Research Colloquium') $subtitle = 'Final research colloquium presentation';
                elseif ($group_name === 'Publication & Archive') $subtitle = 'Journal submission and archive status';
                elseif ($group_name === 'Proposal Defense') $subtitle = 'Scheduled proposal defenses';
                if ($subtitle): ?>
                  <div class="ptrack-milestone-sub"><?php echo ptrack_se($subtitle); ?></div>
              <?php endif; ?>
            </div>
            <div>
              <?php
                $status = 'Pending';
                $status_class = 'slate';
                if (empty($items)) {
                  $status = 'Pending';
                } else {
                  $any_done = false;
                  $any_in_progress = false;
                  foreach ($items as $it) {
                    $s = strtolower((string) ($it['status'] ?? $it['colloquium_status'] ?? $it['journal_status'] ?? ''));
                    if (in_array($s, ['approved','presented','published','done','archived'], true)) { $any_done = true; break; }
                    if (in_array($s, ['submitted','under_review','scheduled','in_progress','ongoing'], true)) { $any_in_progress = true; }
                  }
                  if ($any_done) { $status = 'Completed'; $status_class = 'emerald'; }
                  elseif ($any_in_progress) { $status = 'In Progress'; $status_class = 'blue'; }
                }
              ?>
              <span class="ptrack-badge ptrack-badge-<?php echo ptrack_se($status_class); ?>"><?php echo ptrack_se($status); ?></span>
            </div>
          </div>

          <?php if (empty($items)): ?>
            <div class="ptrack-empty-mini" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
              <span>No records yet. This milestone will appear here once a document or report is logged.</span>
              <?php if (in_array($group_name, ['MOU / NDA', 'Progress Reports', 'Terminal Report'], true)): ?>
                <a class="ptrack-link" href="<?php echo SITE_URL; ?>pages/student/submit-milestone.php?project_id=<?php echo (int) $project['project_id']; ?>">+ Submit →</a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?php foreach ($items as $it):
              $row_status = (string) ($it['status'] ?? '');
              $row_class  = ptrack_status_class($row_status ?: '');
              $row_label  = $row_status ? ptrack_status_label($row_status) : '';
              $date_str   = '';
              if (!empty($it['submitted_at'])) $date_str = ptrack_format_date($it['submitted_at']);
              elseif (!empty($it['reviewed_at'])) $date_str = ptrack_format_date($it['reviewed_at']);
              elseif (!empty($it['colloquium_date'])) $date_str = ptrack_format_date($it['colloquium_date']);
              elseif (!empty($it['due_date'])) $date_str = ptrack_format_date($it['due_date']);
            ?>
              <div class="ptrack-milestone-row">
                <?php if ($group_name === 'MOU / NDA'): ?>
                  <span style="font-weight: 600; color: #111827;"><?php echo ptrack_se(strtoupper((string) $it['document_type'])); ?></span>
                <?php elseif ($group_name === 'Progress Reports' || $group_name === 'Terminal Report'): ?>
                  <span style="font-weight: 600; color: #111827;"><?php echo ptrack_se(ucwords(str_replace('_', ' ', (string) $it['report_type']))); ?></span>
                  <?php if (!empty($it['due_date'])): ?>
                    <span style="color: #94A3B8;">· Due <?php echo ptrack_se(ptrack_format_date($it['due_date'])); ?></span>
                  <?php endif; ?>
                <?php elseif ($group_name === 'Research Colloquium'): ?>
                  <span style="font-weight: 600; color: #111827;">Colloquium</span>
                  <span style="color: #64748B;">· <?php echo !empty($it['colloquium_date']) ? ptrack_se(ptrack_format_date($it['colloquium_date'])) : 'Date TBA'; ?></span>
                <?php elseif ($group_name === 'Publication & Archive'): ?>
                  <span style="font-weight: 600; color: #111827;">Journal status:</span>
                  <span class="ptrack-badge ptrack-badge-<?php echo ptrack_se(ptrack_status_class((string) ($it['journal_status'] ?? ''))); ?>">
                    <?php echo ptrack_se(ptrack_status_label((string) ($it['journal_status'] ?? 'not_submitted'))); ?>
                  </span>
                  <span style="color: #64748B;">·</span>
                  <span style="font-weight: 600; color: #111827;">Archive:</span>
                  <span class="ptrack-badge ptrack-badge-<?php echo ptrack_se(ptrack_status_class((string) ($it['archive_status'] ?? ''))); ?>">
                    <?php echo ptrack_se(ptrack_status_label((string) ($it['archive_status'] ?? 'not_archived'))); ?>
                  </span>
                <?php elseif ($group_name === 'Proposal Defense'): ?>
                  <span style="font-weight: 600; color: #111827;"><?php echo ptrack_se(ucwords(str_replace('_', ' ', (string) $it['type']))); ?> defense</span>
                  <span style="color: #64748B;">· <?php echo ptrack_se(ptrack_format_date($it['schedule_date'])); ?><?php if (!empty($it['venue'])): ?> · <?php echo ptrack_se($it['venue']); ?><?php endif; ?></span>
                <?php endif; ?>

                <?php if ($group_name !== 'Publication & Archive'): ?>
                  <?php if ($row_label): ?>
                    <span class="ptrack-badge ptrack-badge-<?php echo ptrack_se($row_class); ?>"><?php echo ptrack_se($row_label); ?></span>
                  <?php endif; ?>
                <?php endif; ?>

                <?php if ($date_str && $group_name !== 'Research Colloquium' && $group_name !== 'Progress Reports' && $group_name !== 'Terminal Report'): ?>
                  <span style="color: #94A3B8;">· <?php echo ptrack_se($date_str); ?></span>
                <?php endif; ?>

                <?php
                  // Minimal link to the new submit-milestone page for the four slots it
                  // actually writes to (MOU/NDA, Midway Progress, Terminal). Show on
                  // pending or rejected rows so the student can upload / re-upload.
                  $ptrack_supports_submit = in_array($group_name, ['MOU / NDA', 'Progress Reports', 'Terminal Report'], true);
                  $ptrack_needs_action    = in_array(strtolower($row_status), ['pending', 'rejected'], true);
                  if ($ptrack_supports_submit && $ptrack_needs_action): ?>
                    <a class="ptrack-link" href="<?php echo SITE_URL; ?>pages/student/submit-milestone.php?project_id=<?php echo (int) $project['project_id']; ?>" style="margin-left: auto;">
                      <?php echo strtolower($row_status) === 'rejected' ? '↺ Resubmit →' : '+ Upload →'; ?>
                    </a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php $rendered_groups++; ?>
      <?php endforeach; ?>
      </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="ptrack-card ptrack-actions-card">
      <h3 class="ptrack-card-title" style="margin-bottom: 12px;">Quick Actions</h3>
      <div class="ptrack-row">
        <a class="ptrack-btn ptrack-btn-primary" href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $project['project_id']; ?>">📄 View Project</a>
        <a class="ptrack-btn ptrack-btn-secondary" href="<?php echo SITE_URL; ?>pages/student/submit-milestone.php?project_id=<?php echo (int) $project['project_id']; ?>">📑 Submit Milestone Documents</a>
        <?php if (in_array((string) $project['status'], ['approved','ongoing'], true)): ?>
          <a class="ptrack-btn ptrack-btn-secondary" href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $project['project_id']; ?>">📘 Submit a Chapter</a>
        <?php endif; ?>
        <a class="ptrack-btn ptrack-btn-secondary" href="<?php echo SITE_URL; ?>pages/student/my-research.php">← Back to My Research</a>
      </div>
    </div>

  <?php endif; ?>
<?php endif; ?>

</main>

<?php renderStudentShellClose(); ?>
