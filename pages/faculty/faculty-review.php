<?php
/**
 * Faculty — Review Queue
 *
 * Lists chapters belonging to projects where the logged-in faculty is the
 * adviser (via project_advisers.adviser_id).
 *
 * Tabs (?tab=):
 *   - pending (default) — chapters with status 'submitted' or 'under_review'.
 *   - recent           — last 20 chapters this faculty has acted on, status
 *                        'approved' or 'revision_required'.
 *
 * Inline review action per chapter (POST + CSRF):
 *   - approve — chapters.status = 'approved', approved_at/approved_by set,
 *               feedback row inserted into the `comments` table (type 'approval').
 *   - revise  — chapters.status = 'revision_required', approved_at/approved_by
 *               cleared, feedback row inserted into the `comments` table
 *               (type 'correction', or 'suggestion' if the feedback contains
 *               the word "suggest").
 *
 * Feedback storage: the canonical `comments` table
 *   (chapter_id, faculty_id, comment, type ENUM
 *    'general','suggestion','correction','approval').
 * There is no dedicated chapter_feedback table in database/schema/rms_db.sql,
 * so this is the right place. See database/schema/rms_db.sql lines 174-181.
 *
 * Best-effort notification to the project's created_by (the student owner)
 * via createNotification() — same pattern as pages/shared/messages.php.
 *
 * Page is gated to requireRole('faculty'). Only chapters belonging to
 * projects where this faculty is an adviser are eligible for action;
 * every POST verifies ownership with a SELECT through project_advisers
 * before any UPDATE/INSERT.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function frev_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------
// Defensive column detection — research_projects.deleted_at may or may
// not exist depending on whether a migration has added it. The base
// schema does not have it (see database/schema/rms_db.sql:322-333) but
// some migration scripts do. Same approach as faculty-submissions.php.
// ------------------------------------------------------------------
$rp_has_deleted_at = false;
$col_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($col_stmt) {
    $col_stmt->execute();
    $rp_has_deleted_at = $col_stmt->get_result()->num_rows > 0;
    $col_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// ------------------------------------------------------------------
// POST handler — runs before any HTML so we can redirect on success.
// ------------------------------------------------------------------
$flash_success = '';
$flash_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
        $tab_qs = isset($_GET['tab']) && $_GET['tab'] === 'recent' ? '?tab=recent' : '';
        header('Location: ' . SITE_URL . 'pages/faculty/faculty-review.php' . $tab_qs);
        exit;
    }

    $action     = (string) ($_POST['action'] ?? '');
    $chapter_id = (int)    ($_POST['chapter_id'] ?? 0);

    if ($chapter_id < 1 || !in_array($action, ['approve', 'revise'], true)) {
        $_SESSION['module_error'] = 'Invalid review request.';
        header('Location: ' . SITE_URL . 'pages/faculty/faculty-review.php');
        exit;
    }

    $feedback = trim((string) ($_POST['feedback'] ?? ''));
    if (mb_strlen($feedback) > 2000) {
        $feedback = mb_substr($feedback, 0, 2000);
    }

    // Verify the chapter belongs to a project where this faculty is the
    // adviser. If 0 rows, refuse — protects against hand-crafted POSTs
    // from faculty who are not the project's adviser.
    $ver_sql =
        "SELECT ch.chapter_id, ch.project_id, ch.chapter_number, ch.chapter_title, ch.status,
                rp.title AS project_title, rp.created_by
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE ch.chapter_id = ?
            AND pa.adviser_id = ?" . $rp_deleted_filter . "
          LIMIT 1";
    $ver_stmt = $conn->prepare($ver_sql);
    if (!$ver_stmt) {
        $_SESSION['module_error'] = 'Could not verify chapter ownership.';
        header('Location: ' . SITE_URL . 'pages/faculty/faculty-review.php');
        exit;
    }
    $ver_stmt->bind_param('ii', $chapter_id, $user_id);
    $ver_stmt->execute();
    $chap = $ver_stmt->get_result()->fetch_assoc();
    $ver_stmt->close();

    if (!$chap) {
        $_SESSION['module_error'] = 'Chapter not found, or you are not the adviser for this project.';
        header('Location: ' . SITE_URL . 'pages/faculty/faculty-review.php');
        exit;
    }

    // Apply the action in a transaction.
    $conn->begin_transaction();
    $ok = true;
    $new_status   = '';
    $comment_type = '';
    $notif_title  = '';
    $notif_type   = 'info';

    if ($action === 'approve') {
        $new_status   = 'approved';
        $comment_type = 'approval';
        $notif_title  = 'Chapter approved';
        $notif_type   = 'success';
        $upd = $conn->prepare(
            "UPDATE chapters
                SET status = ?, approved_at = NOW(), approved_by = ?
              WHERE chapter_id = ?"
        );
        if ($upd) {
            $upd->bind_param('sii', $new_status, $user_id, $chapter_id);
            $ok = $ok && $upd->execute();
            $upd->close();
        } else {
            $ok = false;
        }
    } else { // revise
        $new_status   = 'revision_required';
        $comment_type = (stripos($feedback, 'suggest') !== false) ? 'suggestion' : 'correction';
        $notif_title  = 'Chapter needs revision';
        $notif_type   = 'warning';
        $upd = $conn->prepare(
            "UPDATE chapters
                SET status = ?, approved_at = NULL, approved_by = NULL
              WHERE chapter_id = ?"
        );
        if ($upd) {
            $upd->bind_param('si', $new_status, $chapter_id);
            $ok = $ok && $upd->execute();
            $upd->close();
        } else {
            $ok = false;
        }
    }

    // Insert feedback row. Best-effort: skip silently if the comments
    // table is unavailable or the feedback was empty. The status
    // update still lands.
    if ($ok && $feedback !== '') {
        $ins = $conn->prepare(
            "INSERT INTO comments (chapter_id, faculty_id, comment, type) VALUES (?, ?, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param('iiss', $chapter_id, $user_id, $feedback, $comment_type);
            $ins->execute();
            $ins->close();
        }
    }

    // Bump research_projects.updated_at to keep "last activity" fresh.
    if ($ok) {
        $tou = $conn->prepare("UPDATE research_projects SET updated_at = NOW() WHERE project_id = ?");
        if ($tou) {
            $tou->bind_param('i', $chap['project_id']);
            $tou->execute();
            $tou->close();
        }
    }

    if ($ok) {
        $conn->commit();
        logActivity(
            ($action === 'approve' ? 'Approved' : 'Requested revision on')
            . " chapter " . (int) $chap['chapter_number']
            . " (project #" . (int) $chap['project_id'] . ")",
            'faculty-review'
        );

        // Compose the notification body for the student owner.
        $ch_label = 'Chapter ' . (int) $chap['chapter_number']
                  . ($chap['chapter_title'] !== '' ? ' — ' . $chap['chapter_title'] : '');
        $notif_msg = $ch_label . ' was '
                   . ($action === 'approve' ? 'approved' : 'returned for revision')
                   . ' on "' . $chap['project_title'] . '".';
        if ($feedback !== '') {
            $snippet = mb_strlen($feedback) > 140 ? mb_substr($feedback, 0, 140) . '…' : $feedback;
            $notif_msg .= ' Feedback: "' . $snippet . '"';
        }
        $notif_link = SITE_URL . 'pages/student/submit-chapter.php?project_id=' . (int) $chap['project_id']
                    . '&chapter=' . (int) $chap['chapter_number'];

        // Best-effort — createNotification() returns false if the
        // notifications table is missing. Same pattern as messages.php.
        if ((int) $chap['created_by'] > 0 && (int) $chap['created_by'] !== $user_id) {
            createNotification(
                (int) $chap['created_by'],
                $notif_title,
                $notif_msg,
                $notif_type,
                $notif_link
            );
        }

        $_SESSION['module_success'] = $action === 'approve'
            ? 'Chapter approved. The student has been notified.'
            : 'Revision requested. The student has been notified.';
    } else {
        $conn->rollback();
        $_SESSION['module_error'] = 'The review could not be saved. Please try again.';
    }

    $tab_qs = isset($_GET['tab']) && $_GET['tab'] === 'recent' ? '?tab=recent' : '?tab=pending';
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-review.php' . $tab_qs);
    exit;
}

// ------------------------------------------------------------------
// Determine the active tab.
// ------------------------------------------------------------------
$tab = (string) ($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'recent'], true)) { $tab = 'pending'; }

// ------------------------------------------------------------------
// Load chapter rows for the active tab.
// ------------------------------------------------------------------
$rows   = [];
$errors = [];

if ($tab === 'pending') {
    $sql =
        "SELECT ch.chapter_id, ch.project_id, ch.chapter_number, ch.chapter_title,
                ch.status, ch.submitted_at,
                rp.title AS project_title, rp.created_by,
                (SELECT u.file_name FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_name,
                (SELECT u.file_path FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_path,
                (SELECT u.original_name FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_original
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa  ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.status IN ('submitted','under_review')"
            . $rp_deleted_filter . "
       ORDER BY ch.submitted_at IS NULL, ch.submitted_at DESC, ch.chapter_id DESC
          LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $uid = $user_id;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
    } else {
        $errors[] = 'Could not prepare the pending-chapters query.';
    }
} else { // recent
    $sql =
        "SELECT ch.chapter_id, ch.project_id, ch.chapter_number, ch.chapter_title,
                ch.status, ch.submitted_at, ch.approved_at, ch.approved_by,
                rp.title AS project_title, rp.created_by,
                (SELECT u.file_name FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_name,
                (SELECT u.file_path FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_path,
                (SELECT u.original_name FROM uploads u
                  WHERE u.chapter_id = ch.chapter_id
                    AND u.type = 'chapter'
                  ORDER BY u.upload_date DESC LIMIT 1) AS chapter_file_original
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa  ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.approved_by = ?
            AND ch.status IN ('approved','revision_required')"
            . $rp_deleted_filter . "
       ORDER BY ch.approved_at DESC, ch.chapter_id DESC
          LIMIT 20";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $uid = $user_id;
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
    } else {
        $errors[] = 'Could not prepare the recent-reviews query.';
    }
}

// ------------------------------------------------------------------
// Per-row student name from project_members (lead first), falling back
// to the project's created_by.
// ------------------------------------------------------------------
$student_names = []; // [project_id => ['Name A', 'Name B']]

if (!empty($rows)) {
    $project_ids = array_values(array_unique(array_map(static function ($r) {
        return (int) $r['project_id'];
    }, $rows)));
    $id_list = implode(',', array_map('intval', $project_ids));

    if ($id_list !== '') {
        $mem_sql =
            "SELECT pm.project_id, pm.role AS pm_role,
                    u.first_name, u.last_name
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

        // Fall back to created_by for projects with no project_members.
        $owner_sql =
            "SELECT rp.project_id, u.first_name, u.last_name
               FROM research_projects rp
               JOIN users u ON u.user_id = rp.created_by
              WHERE rp.project_id IN ($id_list)
                AND u.deleted_at IS NULL";
        if ($owner_res = $conn->query($owner_sql)) {
            while ($o = $owner_res->fetch_assoc()) {
                $pid = (int) $o['project_id'];
                if (empty($student_names[$pid])) {
                    $nm = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
                    if ($nm !== '') { $student_names[$pid] = [$nm]; }
                }
            }
        }
    }
}

// ------------------------------------------------------------------
// Stat cards — three counts for this faculty this month.
// ------------------------------------------------------------------
$stat_awaiting  = 0;
$stat_approved  = 0;
$stat_revisions = 0;

$month_start = date('Y-m-01 00:00:00');

{
    $sa = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.status IN ('submitted','under_review')"
        . $rp_deleted_filter
    );
    if ($sa) {
        $uid = $user_id;
        $sa->bind_param('i', $uid);
        $sa->execute();
        $stat_awaiting = (int) ($sa->get_result()->fetch_assoc()['c'] ?? 0);
        $sa->close();
    }

    $sb = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.approved_by = ?
            AND ch.status = 'approved'
            AND ch.approved_at >= ?"
        . $rp_deleted_filter
    );
    if ($sb) {
        $uid = $user_id;
        $sb->bind_param('iis', $uid, $uid, $month_start);
        $sb->execute();
        $stat_approved = (int) ($sb->get_result()->fetch_assoc()['c'] ?? 0);
        $sb->close();
    }

    $sc = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id
           JOIN project_advisers pa ON pa.project_id = ch.project_id
          WHERE pa.adviser_id = ?
            AND ch.status = 'revision_required'
            AND ch.submitted_at >= ?"
        . $rp_deleted_filter
    );
    if ($sc) {
        $uid = $user_id;
        $sc->bind_param('is', $uid, $month_start);
        $sc->execute();
        $stat_revisions = (int) ($sc->get_result()->fetch_assoc()['c'] ?? 0);
        $sc->close();
    }
}

// ------------------------------------------------------------------
// Helpers — relative time + status badge + flash banner.
// ------------------------------------------------------------------
function frev_relative_time($ts) {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime((string) $ts);
    if ($t === false) { return frev_se((string) $ts); }
    $diff = time() - $t;
    if ($diff < 0) { $diff = 0; }
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return intval($diff / 60)   . ' min ago';
    if ($diff < 86400)  return intval($diff / 3600) . ' hours ago';
    if ($diff < 604800) return intval($diff / 86400). ' days ago';
    if ($diff < 2592000)return intval($diff / 604800). ' weeks ago';
    return date('M d, Y', $t);
}

function frev_status_badge($status) {
    $map = [
        'draft'             => ['#64748B', 'rgba(100,116,139,0.10)', 'rgba(100,116,139,0.25)', 'Draft'],
        'submitted'         => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)',  'Submitted'],
        'under_review'      => ['#2563EB', 'rgba(37,99,235,0.10)', 'rgba(37,99,235,0.25)', 'Under Review'],
        'revision_required' => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)',  'Needs Revision'],
        'approved'          => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)',  'Approved'],
    ];
    $row = $map[$status] ?? $map['draft'];
    [$fg, $bg, $bd, $label] = $row;
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $fg . ';'
         . 'border:1px solid ' . $bd . ';">'
         . frev_se($label) . '</span>';
}

function frev_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $color = $type === 'error' ? '#ef4444' : '#22c55e';
        echo '<div style="margin-bottom:20px;padding:14px 18px;border-left:4px solid ' . $color .
             ';background:#fff;color:#334155;border-radius:10px;">' .
             frev_se($message) . '</div>';
    }
}

// ------------------------------------------------------------------
// Render the shell.
// ------------------------------------------------------------------
$subtitle = $tab === 'pending'
    ? ($stat_awaiting === 0
        ? 'Your review queue is clear.'
        : $stat_awaiting . ' chapter' . ($stat_awaiting === 1 ? '' : 's') . ' awaiting your review.')
    : 'Your 20 most recent chapter reviews.';

renderFacultyShell($user, 'faculty-review.php', 'Review Queue', $subtitle);
?>

<style>
  .frev-tabs {
    display: flex;
    gap: 6px;
    background: #F1F5F9;
    padding: 4px;
    border-radius: 12px;
    margin-bottom: 20px;
    width: fit-content;
  }
  .frev-tab {
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    background: transparent;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
  }
  .frev-tab:hover { color: #111827; }
  .frev-tab.is-active {
    background: #ffffff;
    color: #1d4ed8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
  }
  .frev-tab .frev-tab-count {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #1d4ed8;
    background: rgba(29,78,216,0.10);
    padding: 1px 7px;
    border-radius: 9999px;
  }

  .frev-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .frev-stat {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px 22px;
    transition: box-shadow 0.2s, transform 0.2s;
  }
  .frev-stat:hover {
    box-shadow: 0 4px 14px rgba(29,78,216,0.10);
    transform: translateY(-1px);
  }
  .frev-stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
  }
  .frev-stat-lbl {
    font-size: 13px;
    color: #64748B;
    font-weight: 500;
    margin-top: 6px;
  }
  .frev-stat-icon {
    float: right;
    font-size: 22px;
    opacity: 0.55;
  }

  .frev-card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .frev-card:hover {
    border-color: rgba(29,78,216,0.25);
    box-shadow: 0 1px 6px rgba(29,78,216,0.08);
  }
  .frev-card-head {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }
  .frev-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    line-height: 1.3;
    margin-bottom: 4px;
  }
  .frev-card-meta {
    font-size: 13px;
    color: #64748B;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: center;
  }
  .frev-card-chapter {
    font-size: 14px;
    color: #1e293b;
    font-weight: 500;
    margin-bottom: 8px;
  }
  .frev-card-chapter strong { color: #111827; }

  .frev-feedback {
    width: 100%;
    font-family: inherit;
    font-size: 14px;
    padding: 10px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    resize: vertical;
    min-height: 60px;
  }
  .frev-feedback:focus {
    outline: 2px solid rgba(29,78,216,0.30);
    outline-offset: 1px;
    border-color: #1d4ed8;
  }

  .frev-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
  }

  .frev-file {
    display: inline-block;
    font-size: 12px;
    font-weight: 500;
    color: #1d4ed8;
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid rgba(29,78,216,0.25);
    background: rgba(29,78,216,0.06);
    border-radius: 9999px;
  }
  .frev-file:hover { background: rgba(29,78,216,0.12); }
  .frev-no-file { font-size: 12px; color: #94A3B8; }

  .frev-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .frev-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .frev-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
  }
  .frev-empty-sub { font-size: 14px; color: #64748B; }

  .frev-error {
    background: #FEF2F2;
    color: #991B1B;
    border: 1px solid #FECACA;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 16px;
  }

  @media (max-width: 640px) {
    .frev-card-head { flex-direction: column; }
  }

  /* Faculty review workspace */
  .frev-brief { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.35fr) minmax(260px,.65fr); gap:42px; overflow:hidden; margin-bottom:20px; padding:36px 40px; border:1px solid rgba(29,78,216,.32); border-radius:20px; background:radial-gradient(circle at 91% 8%,rgba(96,165,250,.4),transparent 31%),linear-gradient(135deg,#172554,#1e3a8a 56%,#1d4ed8); color:#fff; box-shadow:0 22px 48px rgba(30,64,175,.16); }
  .frev-brief::after { content:''; position:absolute; z-index:-1; right:-78px; bottom:-126px; width:280px; height:280px; border:1px solid rgba(255,255,255,.12); border-radius:50%; box-shadow:0 0 0 38px rgba(255,255,255,.03),0 0 0 80px rgba(255,255,255,.02); }
  .frev-brief-copy { align-self:center; }
  .frev-eyebrow { margin:0 0 10px; color:#bfdbfe; font-size:10px; font-weight:750; letter-spacing:.12em; text-transform:uppercase; }
  .frev-brief h2 { max-width:670px; margin:0 0 12px; color:#fff; font-size:clamp(28px,3vw,40px); line-height:1.08; letter-spacing:-.045em; }
  .frev-brief-copy > p:last-of-type { max-width:62ch; margin:0; color:rgba(255,255,255,.76); font-size:14px; line-height:1.7; }
  .frev-brief-link { display:inline-flex; min-height:41px; align-items:center; margin-top:22px; padding:9px 16px; border:1px solid rgba(255,255,255,.26); border-radius:10px; background:rgba(255,255,255,.09); color:#fff; font-size:13px; font-weight:700; text-decoration:none; }
  .frev-brief-link:hover { background:rgba(255,255,255,.16); transform:translateY(-1px); }
  .frev-priority { align-self:stretch; display:grid; align-content:center; padding-left:32px; border-left:1px solid rgba(255,255,255,.2); }
  .frev-priority-label { color:#bfdbfe; font-size:10px; font-weight:750; letter-spacing:.09em; text-transform:uppercase; }
  .frev-priority strong { display:block; margin:10px 0 5px; color:#fff; font-size:42px; line-height:1; font-variant-numeric:tabular-nums; }
  .frev-priority p { margin:0; color:rgba(255,255,255,.72); font-size:12px; line-height:1.55; }
  .frev-stats { grid-template-columns:repeat(3,1fr); gap:0; overflow:hidden; margin-bottom:28px; border:1px solid #E5E7EB; border-radius:16px; background:#fff; }
  .frev-stat { min-width:0; border:0; border-radius:0; padding:19px 22px; box-shadow:none; }
  .frev-stat + .frev-stat { border-left:1px solid #E5E7EB; }
  .frev-stat:first-child { background:#eff6ff; }
  .frev-stat:hover { background:rgba(29,78,216,.055); box-shadow:none; transform:none; }
  .frev-stat-num { font-size:30px; letter-spacing:-.045em; }
  .frev-stat-icon { display:grid; width:34px; height:34px; place-items:center; border-radius:9px; background:rgba(29,78,216,.08); font-size:16px; opacity:1; }
  .frev-tabs { margin-bottom:14px; border:1px solid #E2E8F0; background:#fff; }
  .frev-queue-label { display:flex; align-items:end; justify-content:space-between; gap:20px; margin:30px 0 12px; }
  .frev-queue-label h2 { margin:0 0 4px; font-size:20px; }
  .frev-queue-label p { margin:0; color:#64748B; font-size:13px; }
  .frev-queue-count { color:#1d4ed8; font-size:12px; font-weight:700; font-variant-numeric:tabular-nums; }
  .frev-tab { min-height:38px; display:inline-flex; align-items:center; }
  .frev-tab.is-active { background:#eff6ff; box-shadow:none; }
  .frev-queue-head { display:flex; align-items:end; justify-content:space-between; gap:20px; margin:0 0 14px; }
  .frev-queue-head h2 { margin:0 0 4px; font-size:20px; }
  .frev-queue-head p { margin:0; color:#64748B; font-size:13px; }
  .frev-queue-count { flex:none; color:#1d4ed8; font-size:12px; font-weight:700; font-variant-numeric:tabular-nums; }
  .frev-review-list { display:grid; gap:14px; }
  .frev-card { position:relative; margin:0; padding:23px 24px 24px; border-radius:16px; box-shadow:0 5px 18px rgba(30,64,175,.045); }
  .frev-card--pending { border-left:4px solid #1d4ed8; }
  .frev-card:hover { box-shadow:0 14px 34px rgba(30,64,175,.095); transform:translateY(-1px); }
  .frev-card-head { margin-bottom:16px; }
  .frev-card-copy { min-width:0; flex:1 1 280px; }
  .frev-card-state { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .frev-card-title { font-size:17px; font-weight:700; }
  .frev-card-chapter { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:0 0 14px; padding:12px 14px; border-radius:11px; background:#f8fafc; }
  .frev-file { border-radius:7px; }
  .frev-feedback { min-height:84px; padding:13px 14px; line-height:1.55; }
  .frev-feedback-label { display:block; margin:0 0 7px; color:#475569; font-size:12px; font-weight:650; }
  .frev-actions { margin-top:12px; }
  .frev-actions .btn { min-width:150px; justify-content:center; }
  .frev-limit-note { margin-top:14px; color:#94A3B8; font-size:12px; text-align:center; }
  @media (max-width:900px) { .frev-brief { grid-template-columns:1fr; gap:26px; } .frev-priority { padding:22px 0 0; border-top:1px solid rgba(255,255,255,.2); border-left:0; } .frev-queue-head { align-items:flex-start; flex-direction:column; } }
  @media (max-width:640px) { .frev-brief { padding:28px 22px; } .frev-stats { grid-template-columns:1fr; } .frev-stat + .frev-stat { border-top:1px solid #E5E7EB; border-left:0; } .frev-tabs { display:grid; width:100%; } .frev-tab { justify-content:center; } .frev-card { padding:20px 18px; } .frev-actions { display:grid; } .frev-actions .btn { width:100%; } }
</style>

<?php frev_flash('success'); frev_flash('error'); ?>

<?php if (!empty($errors)): ?>
  <div class="frev-error"><?php echo frev_se(implode(' ', $errors)); ?></div>
<?php endif; ?>

<section class="frev-brief" aria-labelledby="frev-workspace-title">
  <div class="frev-brief-copy">
    <p class="frev-eyebrow">Chapter review workspace</p>
    <h2 id="frev-workspace-title"><?php echo $stat_awaiting > 0 ? 'Give each submission a clear next step.' : 'Your chapter review queue is clear.'; ?></h2>
    <p>Read the submitted chapter, leave focused feedback, and record an approval or revision decision for your advisee.</p>
    <a class="frev-brief-link" href="faculty-students.php">View my advisees</a>
  </div>
  <div class="frev-priority">
    <span class="frev-priority-label">Awaiting review</span>
    <strong><?php echo (int) $stat_awaiting; ?></strong>
    <p>chapter<?php echo $stat_awaiting === 1 ? '' : 's'; ?> currently waiting for your decision</p>
  </div>
</section>

<div class="frev-stats">
  <div class="frev-stat">
    <span class="frev-stat-icon">📥</span>
    <div class="frev-stat-num"><?php echo (int) $stat_awaiting; ?></div>
    <div class="frev-stat-lbl">Awaiting review</div>
  </div>
  <div class="frev-stat">
    <span class="frev-stat-icon">✅</span>
    <div class="frev-stat-num"><?php echo (int) $stat_approved; ?></div>
    <div class="frev-stat-lbl">Approved this month</div>
  </div>
  <div class="frev-stat">
    <span class="frev-stat-icon">✏️</span>
    <div class="frev-stat-num"><?php echo (int) $stat_revisions; ?></div>
    <div class="frev-stat-lbl">Revisions requested (mo.)</div>
  </div>
</div>

<div class="frev-queue-head">
  <div>
    <h2><?php echo $tab === 'pending' ? 'Pending chapter reviews' : 'Recently reviewed chapters'; ?></h2>
    <p><?php echo $tab === 'pending' ? 'Open a submission and record the next decision.' : 'Your latest completed chapter decisions.'; ?></p>
  </div>
  <span class="frev-queue-count"><?php echo count($rows); ?> item<?php echo count($rows) === 1 ? '' : 's'; ?></span>
</div>

<div class="frev-tabs" role="tablist">
  <a class="frev-tab <?php echo $tab === 'pending' ? 'is-active' : ''; ?>"
     href="faculty-review.php?tab=pending">
    📥 Pending
    <?php if ($stat_awaiting > 0): ?>
      <span class="frev-tab-count"><?php echo (int) $stat_awaiting; ?></span>
    <?php endif; ?>
  </a>
  <a class="frev-tab <?php echo $tab === 'recent' ? 'is-active' : ''; ?>"
     href="faculty-review.php?tab=recent">
    🕓 Recently reviewed
  </a>
</div>

<?php if (!$rows): ?>
  <div class="card">
    <div class="frev-empty">
      <div class="frev-empty-icon"><?php echo $tab === 'pending' ? '🎉' : '🕓'; ?></div>
      <div class="frev-empty-title">
        <?php echo $tab === 'pending' ? 'Your queue is clear' : 'No recent reviews'; ?>
      </div>
      <div class="frev-empty-sub">
        <?php if ($tab === 'pending'): ?>
          When a student submits a chapter and you are the adviser, it will appear here for review.
        <?php else: ?>
          Chapters you approve or send back for revision will show up here (most recent 20).
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="frev-review-list" id="review-list">
  <?php foreach ($rows as $r):
      $pid         = (int) $r['project_id'];
      $cid         = (int) $r['chapter_id'];
      $ch_num      = (int) $r['chapter_number'];
      $ch_title    = (string) ($r['chapter_title'] ?? '');
      $status      = (string) $r['status'];
      $submitted   = $r['submitted_at'] ?? null;
      $approved_at = $r['approved_at']   ?? null;
      $proj_title  = (string) $r['project_title'];
      $students    = !empty($student_names[$pid])
                   ? implode(', ', $student_names[$pid])
                   : '—';
      $file_path   = (string) ($r['chapter_file_path'] ?? '');
      $file_orig   = (string) ($r['chapter_file_original'] ?? '');
  ?>
    <article class="frev-card<?php echo $tab === 'pending' ? ' frev-card--pending' : ''; ?>">
      <div class="frev-card-head">
        <div class="frev-card-copy">
          <div class="frev-card-title"><?php echo frev_se($proj_title); ?></div>
          <div class="frev-card-meta">
            <span>🎒 <?php echo frev_se($students); ?></span>
            <span>📁 Project #<?php echo $pid; ?></span>
          </div>
        </div>
        <div class="frev-card-state">
          <?php echo frev_status_badge($status); ?>
          <?php if ($tab === 'pending'): ?>
            <span style="font-size:12px;color:#94A3B8;">Submitted <?php echo frev_se(frev_relative_time($submitted)); ?></span>
          <?php else: ?>
            <span style="font-size:12px;color:#94A3B8;">Acted <?php echo frev_se(frev_relative_time($approved_at)); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="frev-card-chapter">
        <strong>Chapter <?php echo $ch_num; ?></strong>
        <?php if ($ch_title !== ''): ?>
          &mdash; <?php echo frev_se($ch_title); ?>
        <?php endif; ?>

        <?php if ($file_path !== ''): ?>
          <a class="frev-file"
             href="<?php echo frev_se($file_path); ?>" target="_blank" rel="noopener">
            📄 <?php echo frev_se($file_orig !== '' ? $file_orig : 'View file'); ?>
          </a>
        <?php else: ?>
          <span class="frev-no-file" style="margin-left:10px;">No file attached</span>
        <?php endif; ?>
      </div>

      <?php if ($tab === 'pending'): ?>
        <form method="post" action="faculty-review.php?tab=pending">
          <?php echo csrfField(); ?>
          <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">
          <label class="frev-feedback-label" for="frev-feedback-<?php echo $cid; ?>">Feedback for the student <span style="font-weight:500;color:#94A3B8;">(optional)</span></label>
          <textarea class="frev-feedback" id="frev-feedback-<?php echo $cid; ?>" name="feedback" maxlength="2000"
                    placeholder="Optional feedback — visible to the student and stored in the chapter history…"></textarea>
          <div class="frev-actions">
            <button type="submit" name="action" value="approve" class="btn btn-primary">
              ✅ Approve chapter
            </button>
            <button type="submit" name="action" value="revise" class="btn btn-secondary"
                    style="color:#EA580C;border-color:rgba(234,88,12,0.35);background:rgba(234,88,12,0.04);">
              ✏️ Request revision
            </button>
          </div>
        </form>
      <?php else: ?>
        <div class="frev-card-meta" style="margin-top:4px;">
          <?php if ($approved_at): ?>
            <span>📅 <?php echo frev_se(date('M d, Y \a\t h:i A', strtotime((string) $approved_at))); ?></span>
          <?php endif; ?>
          <?php if ($file_path !== ''): ?>
            <a class="frev-file" href="<?php echo frev_se($file_path); ?>" target="_blank" rel="noopener">
              📄 <?php echo frev_se($file_orig !== '' ? $file_orig : 'View file'); ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>

  <?php if ($tab === 'recent' && count($rows) >= 20): ?>
    <div class="frev-limit-note">
      Showing the 20 most recent chapter reviews.
    </div>
  <?php elseif ($tab === 'pending' && count($rows) >= 100): ?>
    <div class="frev-limit-note">
      Showing the 100 most urgent. Older items remain in the queue.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php renderFacultyShellClose(); ?>
