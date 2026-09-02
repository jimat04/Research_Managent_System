<?php
/**
 * Staff — Defense Schedule
 *
 * Research Staff & Admin schedule and manage proposal / pre-oral / final
 * defenses for active research projects. Writes to the `defense_schedule`
 * table (defense_id, project_id, schedule_date DATETIME, venue, type
 * enum proposal/pre_oral/final, status enum scheduled/done/cancelled/rescheduled,
 * remarks, created_by).
 *
 * The student progress-tracking page reads from this same table — this page
 * is the only writer.
 *
 * Actions:
 *   - schedule   → INSERT (project must exist, date in the future, no
 *                  duplicate scheduled defense of the same type for the same
 *                  project)
 *   - mark_done  → status = 'done'
 *   - cancel     → status = 'cancelled', reason appended to remarks
 *   - reschedule → schedule_date updated, status = 'rescheduled'; old date
 *                  and reason appended to remarks (same row — see header note)
 *
 * On any action: logActivity() and createNotification() to the project's
 * student owner, all project_members, and all project_advisers (deduplicated).
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole(['research_staff', 'admin']);

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];
$role    = (string) ($user['role'] ?? 'research_staff');

// ── helpers ────────────────────────────────────────────────────────────────
function sdef_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sdef_type_label(string $type): string {
    $map = [
        'proposal' => 'Proposal Defense',
        'pre_oral' => 'Pre-Oral Defense',
        'final'    => 'Final Defense',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function sdef_statusBadge(string $status): array {
    $map = [
        'scheduled'    => ['status-review',   'Scheduled'],
        'rescheduled'  => ['status-pending',  'Rescheduled'],
        'done'         => ['status-approved', 'Done'],
        'cancelled'    => ['status-draft',    'Cancelled'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

function sdef_format_dt(?string $dt): string {
    if (empty($dt) || $dt === '0000-00-00 00:00:00') return '—';
    $ts = strtotime((string) $dt);
    return $ts ? date('M d, Y · g:i A', $ts) : '—';
}

function sdef_relative_time(?string $dt): string {
    if (empty($dt)) return '';
    $ts = strtotime((string) $dt);
    if (!$ts) return '';
    $diff = $ts - time();
    if ($diff < 0) {
        $adiff = abs($diff);
        if ($adiff < 3600)   return (int)($adiff / 60)   . ' min ago';
        if ($adiff < 86400)  return (int)($adiff / 3600) . ' hr ago';
        if ($adiff < 604800) return (int)($adiff / 86400) . ' day'  . ((int)($adiff / 86400) === 1 ? '' : 's') . ' ago';
        return date('M d, Y', $ts);
    }
    if ($diff < 60)        return 'in a moment';
    if ($diff < 3600)      return 'in ' . (int)($diff / 60)  . ' min';
    if ($diff < 86400)     return 'in ' . (int)($diff / 3600) . ' hr';
    if ($diff < 604800)    return 'in ' . (int)($diff / 86400) . ' day' . ((int)($diff / 86400) === 1 ? '' : 's');
    return date('M d, Y', $ts);
}

// ── runtime schema detection ──────────────────────────────────────────────
// research_projects.deleted_at is added by a migration; defend gracefully.
$sdef_rp_has_deleted_at = false;
$rp_check = $conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($rp_check) {
    $sdef_rp_has_deleted_at = ($rp_check->num_rows > 0);
    $rp_check->free();
}
$sdef_rp_deleted_filter = $sdef_rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// defense_schedule columns — protect against partial migrations.
$sdef_has_remarks    = false;
$sdef_has_created_by = false;
$tbl_check = $conn->query("SHOW TABLES LIKE 'defense_schedule'");
$sdef_has_table = $tbl_check ? ($tbl_check->num_rows > 0) : false;
if ($tbl_check) { $tbl_check->free(); }

if ($sdef_has_table) {
    $c1 = $conn->query("SHOW COLUMNS FROM defense_schedule LIKE 'remarks'");
    if ($c1)    { $sdef_has_remarks    = ($c1->num_rows > 0); $c1->free(); }
    $c2 = $conn->query("SHOW COLUMNS FROM defense_schedule LIKE 'created_by'");
    if ($c2)    { $sdef_has_created_by = ($c2->num_rows > 0); $c2->free(); }
}

// ── notification helper ───────────────────────────────────────────────────
// Collect every user who should be told about a defense event: the project
// owner, every project_members row, every project_advisers row. Deduplicated
// by user_id. Staff/admins who happen to be members are still notified — they
// are stakeholders too.
function sdef_collect_recipients(mysqli $conn, int $project_id): array {
    $ids = [];

    // Project owner
    $ps = $conn->prepare("SELECT created_by FROM research_projects WHERE project_id = ? LIMIT 1");
    if ($ps) {
        $ps->bind_param('i', $project_id);
        $ps->execute();
        $row = $ps->get_result()->fetch_assoc();
        $ps->close();
        if ($row && (int) $row['created_by'] > 0) {
            $ids[] = (int) $row['created_by'];
        }
    }

    // Members
    $ms = $conn->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
    if ($ms) {
        $ms->bind_param('i', $project_id);
        $ms->execute();
        $r = $ms->get_result();
        while ($row = $r->fetch_assoc()) {
            if ((int) $row['user_id'] > 0) $ids[] = (int) $row['user_id'];
        }
        $ms->close();
    }

    // Advisers
    $as = $conn->prepare("SELECT adviser_id FROM project_advisers WHERE project_id = ?");
    if ($as) {
        $as->bind_param('i', $project_id);
        $as->execute();
        $r = $as->get_result();
        while ($row = $r->fetch_assoc()) {
            if ((int) $row['adviser_id'] > 0) $ids[] = (int) $row['adviser_id'];
        }
        $as->close();
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

function sdef_load_project_title(mysqli $conn, int $project_id): string {
    $ps = $conn->prepare("SELECT title FROM research_projects WHERE project_id = ? LIMIT 1");
    if (!$ps) return '';
    $ps->bind_param('i', $project_id);
    $ps->execute();
    $row = $ps->get_result()->fetch_assoc();
    $ps->close();
    return (string) ($row['title'] ?? '');
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $redirect = SITE_URL . 'pages/staff/staff-defense.php';

    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your session has expired. Please refresh the page and try again.';
        header('Location: ' . $redirect);
        exit;
    }

    if (!$sdef_has_table) {
        $_SESSION['module_error'] = 'The defense_schedule table is not available in this database.';
        header('Location: ' . $redirect);
        exit;
    }

    $action = (string) $_POST['action'];

    if ($action === 'schedule') {
        $project_id    = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $type          = (string) ($_POST['type'] ?? '');
        $schedule_date = trim((string) ($_POST['schedule_date'] ?? ''));
        $venue         = trim((string) ($_POST['venue'] ?? ''));
        $remarks       = trim((string) ($_POST['remarks'] ?? ''));

        // Validate project
        if ($project_id <= 0) {
            $_SESSION['module_error'] = 'Please select a project.';
            header('Location: ' . $redirect);
            exit;
        }
        $valid_types = ['proposal', 'pre_oral', 'final'];
        if (!in_array($type, $valid_types, true)) {
            $_SESSION['module_error'] = 'Invalid defense type.';
            header('Location: ' . $redirect);
            exit;
        }
        // datetime-local gives "YYYY-MM-DDTHH:MM" — normalize to "Y-m-d H:i:s"
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $schedule_date);
        if (!$dt) {
            $_SESSION['module_error'] = 'Please provide a valid schedule date and time.';
            header('Location: ' . $redirect);
            exit;
        }
        $now = new DateTime('now');
        if ($dt <= $now) {
            $_SESSION['module_error'] = 'Schedule date must be in the future.';
            header('Location: ' . $redirect);
            exit;
        }
        $scheduled_norm = $dt->format('Y-m-d H:i:s');

        // Verify project exists (and is not soft-deleted if column is present)
        $ps = $conn->prepare("
            SELECT rp.project_id, rp.title, rp.status
              FROM research_projects rp
             WHERE rp.project_id = ?" . $sdef_rp_deleted_filter . "
             LIMIT 1
        ");
        if (!$ps) {
            $_SESSION['module_error'] = 'Failed to verify project.';
            header('Location: ' . $redirect);
            exit;
        }
        $ps->bind_param('i', $project_id);
        $ps->execute();
        $proj = $ps->get_result()->fetch_assoc();
        $ps->close();
        if (!$proj) {
            $_SESSION['module_error'] = 'Selected project does not exist.';
            header('Location: ' . $redirect);
            exit;
        }

        // Duplicate check: no existing scheduled/rescheduled defense of the same type
        $dup = $conn->prepare("
            SELECT defense_id, status, schedule_date
              FROM defense_schedule
             WHERE project_id = ?
               AND type = ?
               AND status IN ('scheduled','rescheduled')
             LIMIT 1
        ");
        $dup->bind_param('is', $project_id, $type);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($existing) {
            $when = sdef_format_dt($existing['schedule_date'] ?? null);
            $_SESSION['module_error'] = 'This project already has a ' . sdef_type_label($type) . " scheduled ({$when}, status: {$existing['status']}). Cancel or mark it done before scheduling another.";
            header('Location: ' . $redirect);
            exit;
        }

        $venue_v   = $venue   !== '' ? $venue   : null;
        $remarks_v = $remarks !== '' ? $remarks : null;

        $cols = 'project_id, schedule_date, venue, type, status';
        $vals = '?, ?, ?, ?, ?';
        $bind_types = 'issss';
        $bind_params = [$project_id, $scheduled_norm, $venue_v, $type, 'scheduled'];
        if ($sdef_has_remarks)    { $cols .= ', remarks';    $vals .= ', ?'; $bind_types .= 's'; $bind_params[] = $remarks_v; }
        if ($sdef_has_created_by) { $cols .= ', created_by'; $vals .= ', ?'; $bind_types .= 'i'; $bind_params[] = $user_id; }

        $ins = $conn->prepare("INSERT INTO defense_schedule ($cols) VALUES ($vals)");
        if (!$ins) {
            $_SESSION['module_error'] = 'Failed to prepare insert statement.';
            header('Location: ' . $redirect);
            exit;
        }
        $ref_params = [];
        foreach ($bind_params as $k => $v) { $ref_params[$k] = &$bind_params[$k]; }
        call_user_func_array([$ins, 'bind_param'], array_merge([$bind_types], $ref_params));
        $ins->execute();
        $affected = $conn->affected_rows;
        $new_id   = $ins->insert_id;
        $ins->close();

        if ($affected > 0) {
            $short_title = mb_strlen((string) $proj['title']) > 60
                ? mb_substr((string) $proj['title'], 0, 60) . '…'
                : (string) $proj['title'];
            $when_label = $dt->format('M d, Y · g:i A');
            $venue_part = $venue !== '' ? " at {$venue}" : '';
            $notif_msg  = 'A ' . sdef_type_label($type) . " for \"{$short_title}\" is scheduled on {$when_label}{$venue_part}.";
            $link       = SITE_URL . 'pages/student/progress-tracking.php?project_id=' . $project_id;

            $recipients = sdef_collect_recipients($conn, $project_id);
            foreach ($recipients as $rid) {
                if ($rid === $user_id) continue; // don't notify the staff member themselves
                createNotification(
                    $rid,
                    'Defense Scheduled — ' . sdef_type_label($type),
                    $notif_msg,
                    'info',
                    $link
                );
            }

            logActivity(
                'Scheduled ' . sdef_type_label($type) . " for project #{$project_id} (\"{$proj['title']}\") on {$when_label}{$venue_part}",
                'defense_schedule'
            );
            $_SESSION['module_success'] = sdef_type_label($type) . ' scheduled successfully.';
        } else {
            $_SESSION['module_error'] = 'Failed to schedule defense (no rows affected).';
        }

        header('Location: ' . $redirect);
        exit;
    }

    if (in_array($action, ['mark_done', 'cancel', 'reschedule'], true)) {
        $defense_id = isset($_POST['defense_id']) ? (int) $_POST['defense_id'] : 0;
        $reason     = trim((string) ($_POST['reason'] ?? ''));

        if ($defense_id <= 0) {
            $_SESSION['module_error'] = 'Invalid defense reference.';
            header('Location: ' . $redirect);
            exit;
        }

        // Load current row + project title
        $ls = $conn->prepare("
            SELECT ds.defense_id, ds.project_id, ds.schedule_date, ds.venue, ds.type,
                   ds.status, ds.remarks, rp.title AS project_title
              FROM defense_schedule ds
              JOIN research_projects rp ON rp.project_id = ds.project_id" . $sdef_rp_deleted_filter . "
             WHERE ds.defense_id = ?
             LIMIT 1
        ");
        if (!$ls) {
            $_SESSION['module_error'] = 'Failed to load defense row.';
            header('Location: ' . $redirect);
            exit;
        }
        $ls->bind_param('i', $defense_id);
        $ls->execute();
        $row = $ls->get_result()->fetch_assoc();
        $ls->close();
        if (!$row) {
            $_SESSION['module_error'] = 'Defense not found.';
            header('Location: ' . $redirect);
            exit;
        }

        $current_status = strtolower((string) ($row['status'] ?? ''));
        if (in_array($current_status, ['done', 'cancelled'], true)) {
            $_SESSION['module_error'] = 'This defense is already ' . $current_status . ' and cannot be modified.';
            header('Location: ' . $redirect);
            exit;
        }

        $new_status  = '';
        $log_action  = '';
        $notif_title = '';
        $notif_msg   = '';
        $notif_type  = 'info';
        $set_clauses = ['status = ?'];
        $bind_params = [];
        $bind_types  = 's';

        $project_id   = (int) $row['project_id'];
        $project_title = (string) $row['project_title'];
        $short_title   = mb_strlen($project_title) > 60 ? mb_substr($project_title, 0, 60) . '…' : $project_title;
        $old_dt_label  = sdef_format_dt($row['schedule_date'] ?? null);
        $old_remarks   = trim((string) ($row['remarks'] ?? ''));
        $history_line  = '';
        $new_date_norm = null;
        $venue_part    = !empty($row['venue']) ? ' at ' . (string) $row['venue'] : '';
        $link          = SITE_URL . 'pages/student/progress-tracking.php?project_id=' . $project_id;

        if ($action === 'mark_done') {
            $new_status  = 'done';
            $log_action  = 'Marked done';
            $notif_title = 'Defense Done — ' . sdef_type_label((string) $row['type']);
            $notif_msg   = 'Your ' . sdef_type_label((string) $row['type']) . " for \"{$short_title}\" has been marked as done ({$old_dt_label}{$venue_part}).";
            $notif_type  = 'success';
        } elseif ($action === 'cancel') {
            if (mb_strlen($reason) < 5) {
                $_SESSION['module_error'] = 'Cancellation reason is required (minimum 5 characters).';
                header('Location: ' . $redirect);
                exit;
            }
            $new_status = 'cancelled';
            $log_action = 'Cancelled';
            $notif_title = 'Defense Cancelled — ' . sdef_type_label((string) $row['type']);
            $notif_msg   = 'Your ' . sdef_type_label((string) $row['type']) . " for \"{$short_title}\" scheduled on {$old_dt_label}{$venue_part} has been cancelled. Reason: {$reason}";
            $notif_type  = 'error';
            $history_line = '[' . date('Y-m-d H:i') . '] Cancelled: ' . $reason;
        } elseif ($action === 'reschedule') {
            $new_date_raw = trim((string) ($_POST['new_date'] ?? ''));
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $new_date_raw);
            if (!$dt) {
                $_SESSION['module_error'] = 'Please provide a valid new schedule date and time.';
                header('Location: ' . $redirect);
                exit;
            }
            $now = new DateTime('now');
            if ($dt <= $now) {
                $_SESSION['module_error'] = 'New schedule date must be in the future.';
                header('Location: ' . $redirect);
                exit;
            }
            $new_date_norm = $dt->format('Y-m-d H:i:s');
            $new_status    = 'rescheduled';
            $log_action    = 'Rescheduled';
            $when_label    = $dt->format('M d, Y · g:i A');
            $notif_title   = 'Defense Rescheduled — ' . sdef_type_label((string) $row['type']);
            $notif_msg     = 'Your ' . sdef_type_label((string) $row['type']) . " for \"{$short_title}\" has been moved from {$old_dt_label} to {$when_label}{$venue_part}.";
            $notif_type    = 'info';
            $set_clauses[] = 'schedule_date = ?';
            $bind_types   .= 's';
            $bind_params[]  = $new_date_norm;
            $history_line  = '[' . date('Y-m-d H:i') . '] Rescheduled from ' . $old_dt_label . ' to ' . $when_label
                . ($reason !== '' ? ' — ' . $reason : '');
        }

        $bind_params = array_merge([$new_status], $bind_params);
        $bind_params[] = $defense_id;
        $bind_types  .= 'i';

        // Append history to remarks if column exists
        if ($sdef_has_remarks) {
            $merged = $old_remarks;
            if ($history_line !== '') {
                $merged = $merged === '' ? $history_line : ($merged . "\n" . $history_line);
            }
            $set_clauses[] = 'remarks = ?';
            $bind_types   .= 's';
            $bind_params[]  = $merged;
        }

        $set_sql = implode(', ', $set_clauses);
        $upd = $conn->prepare("UPDATE defense_schedule SET $set_sql WHERE defense_id = ?");
        if (!$upd) {
            $_SESSION['module_error'] = 'Failed to prepare update statement.';
            header('Location: ' . $redirect);
            exit;
        }
        $ref_params = [];
        foreach ($bind_params as $k => $v) { $ref_params[$k] = &$bind_params[$k]; }
        call_user_func_array([$upd, 'bind_param'], array_merge([$bind_types], $ref_params));
        $upd->execute();
        $affected = $conn->affected_rows;
        $upd->close();

        if ($affected > 0) {
            $recipients = sdef_collect_recipients($conn, $project_id);
            foreach ($recipients as $rid) {
                if ($rid === $user_id) continue;
                createNotification($rid, $notif_title, $notif_msg, $notif_type, $link);
            }
            logActivity(
                $log_action . ' ' . sdef_type_label((string) $row['type']) . " for project #{$project_id} (\"{$project_title}\") — was {$old_dt_label}{$venue_part}",
                'defense_schedule'
            );
            $_SESSION['module_success'] = sdef_type_label((string) $row['type']) . ' ' . $log_action . ' successfully.';
        } else {
            $_SESSION['module_error'] = 'No changes were made — the defense may have been updated already.';
        }

        header('Location: ' . $redirect);
        exit;
    }

    $_SESSION['module_error'] = 'Unknown action.';
    header('Location: ' . $redirect);
    exit;
}

// ── GET filter ────────────────────────────────────────────────────────────
$filter = (string) ($_GET['filter'] ?? 'upcoming');
if (!in_array($filter, ['upcoming', 'past', 'all'], true)) {
    $filter = 'upcoming';
}

// ── stat cards ────────────────────────────────────────────────────────────
$stat_upcoming_week = 0;
$stat_total_sched   = 0;
$stat_done_term     = 0;
$stat_total_overall = 0;

if ($sdef_has_table) {
    // Upcoming this week: scheduled/rescheduled AND schedule_date BETWEEN now and now+7d
    $r = $conn->query("
        SELECT COUNT(*) AS c FROM defense_schedule
         WHERE status IN ('scheduled','rescheduled')
           AND schedule_date >= NOW()
           AND schedule_date < DATE_ADD(NOW(), INTERVAL 7 DAY)
    ");
    if ($r) { $stat_upcoming_week = (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }

    // Total currently scheduled (active)
    $r = $conn->query("
        SELECT COUNT(*) AS c FROM defense_schedule
         WHERE status IN ('scheduled','rescheduled')
           AND schedule_date >= NOW()
    ");
    if ($r) { $stat_total_sched = (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }

    // Term = current academic year. Since academic_years has no start_date column
    // (only `label` like "2024-2025" and an `is_active` flag), we pick the active
    // row and treat Jan 1 of its label's first year as the term start. If the
    // table is missing or no active row exists, fall back to Jan 1 of the
    // current calendar year.
    //
    // NOTE: the only columns on academic_years are ay_id, label, semester,
    // is_active, created_at. Do NOT reference start_date — it does not exist.
    $term_start = date('Y-01-01 00:00:00');
    $ay_check = $conn->query("SHOW TABLES LIKE 'academic_years'");
    if ($ay_check) {
        $has_ay = ($ay_check->num_rows > 0);
        $ay_check->free();
        if ($has_ay) {
            // Only the columns that actually exist — label, is_active.
            $ayq = $conn->query("SELECT label, is_active FROM academic_years WHERE is_active = 1 ORDER BY ay_id DESC LIMIT 1");
            if ($ayq) {
                $ayr = $ayq->fetch_assoc();
                $ayq->close();
                if (!empty($ayr['label']) && preg_match('/(\d{4})/', (string) $ayr['label'], $m)) {
                    $term_start = $m[1] . '-01-01 00:00:00';
                }
            }
        }
    }
    $term_start_safe = $conn->real_escape_string($term_start);
    $r = $conn->query("
        SELECT COUNT(*) AS c FROM defense_schedule
         WHERE status = 'done'
           AND schedule_date >= '$term_start_safe'
    ");
    if ($r) { $stat_done_term = (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }

    // All-time total (for the "All" tab caption)
    $r = $conn->query("SELECT COUNT(*) AS c FROM defense_schedule");
    if ($r) { $stat_total_overall = (int) ($r->fetch_assoc()['c'] ?? 0); $r->close(); }
}

// ── main list query ──────────────────────────────────────────────────────
$rows = [];
if ($sdef_has_table) {
    $where_parts = [];
    if ($filter === 'upcoming') {
        $where_parts[] = "ds.status IN ('scheduled','rescheduled') AND ds.schedule_date >= NOW()";
    } elseif ($filter === 'past') {
        $where_parts[] = "(ds.status IN ('done','cancelled','rescheduled') OR ds.schedule_date < NOW())";
    }
    $where = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

    $sql = "
        SELECT ds.defense_id, ds.project_id, ds.schedule_date, ds.venue, ds.type,
               ds.status, ds.remarks, ds.created_at,
               rp.title AS project_title, rp.created_by AS owner_id,
               CONCAT(own.first_name, ' ', own.last_name) AS owner_name,
               own.student_id AS owner_school_id
          FROM defense_schedule ds
          JOIN research_projects rp ON rp.project_id = ds.project_id" . $sdef_rp_deleted_filter . "
          LEFT JOIN users own ON own.user_id = rp.created_by
          $where
         ORDER BY ds.schedule_date ASC, ds.defense_id ASC
         LIMIT 200
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->close();
    }
}

// ── project dropdown (active projects, search-friendly) ──────────────────
$active_projects = [];
if ($sdef_has_table) {
    $sql = "
        SELECT rp.project_id, rp.title, rp.status,
               CONCAT(own.first_name, ' ', own.last_name) AS owner_name
          FROM research_projects rp
          LEFT JOIN users own ON own.user_id = rp.created_by
         WHERE 1=1" . $sdef_rp_deleted_filter . "
         ORDER BY rp.updated_at DESC
         LIMIT 500
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $active_projects[] = $r;
        }
        $res->close();
    }
}

$page_title = 'Defense Schedule';
$page_subtitle = 'Schedule proposal, pre-oral, and final defenses for active research projects.';

if ($role === 'admin') {
    renderAdminShell($user, 'staff-defense.php', $page_title, $page_subtitle);
} else {
    renderStaffShell($user, 'staff-defense.php', $page_title, $page_subtitle);
}
?>

<style>
  .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
  .alert-warn    { background: #FEF3C7; color: #92400e; border: 1px solid #FDE68A; }

  .stat-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 32px;
  }
  .stat-card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .stat-card .icon { font-size: 36px; }
  .stat-card .num  { text-align: center; font-size: 32px; font-weight: 700; line-height: 1; color: #111827; }
  .stat-card .lbl  { font-size: 13px; color: #64748B; margin-top: 4px; font-weight: 500; }
  .stat-card.upcoming .icon { color: #2563EB; }
  .stat-card.scheduled .icon { color: #7C3AED; }
  .stat-card.done .icon { color: #16A34A; }

  .filter-bar {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    background: #F1F5F9;
    padding: 4px;
    border-radius: 12px;
    width: fit-content;
  }
  .filter-tab {
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #64748B;
    text-decoration: none;
    transition: all 0.2s;
  }
  .filter-tab:hover { color: #111827; }
  .filter-tab.active {
    background: #FFFFFF;
    color: #111827;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
  }
  .filter-tab .count {
    display: inline-block;
    margin-left: 6px;
    background: #E5E7EB;
    color: #111827;
    font-size: 11px;
    font-weight: 700;
    padding: 1px 8px;
    border-radius: 9999px;
  }
  .filter-tab.active .count { background: #0d9488; color: white; }

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
  .badge-status.status-draft    { background: #F1F5F9; color: #64748B; }
  .badge-status.status-review   { background: #DBEAFE; color: #2563EB; }
  .badge-status.status-pending  { background: #FEF3C7; color: #EA580C; }
  .badge-status.status-approved { background: #DCFCE7; color: #16A34A; }

  .badge-type {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: rgba(13,148,136,0.10);
    color: #0d9488;
    border: 1px solid rgba(13,148,136,0.20);
  }
  .badge-type.pre_oral { background: rgba(124,58,237,0.10); color: #7C3AED; border-color: rgba(124,58,237,0.20); }
  .badge-type.final    { background: rgba(245,124,0,0.10);  color: #F57C00; border-color: rgba(245,124,0,0.20); }

  .row-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-family: inherit;
  }
  .btn:disabled,
  .btn.disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }
  .btn-primary   { background: #0d9488; color: white; }
  .btn-primary:hover:not(:disabled) { background: #059669; }
  .btn-secondary { background: #FFFFFF; color: #111827; border: 1px solid #E5E7EB; }
  .btn-secondary:hover:not(:disabled) { background: #F1F5F9; }
  .btn-success   { background: #16A34A; color: white; }
  .btn-success:hover:not(:disabled)   { background: #15803D; }
  .btn-danger    { background: #EF4444; color: white; }
  .btn-danger:hover:not(:disabled)    { background: #B91C1C; }
  .btn-warn      { background: #F59E0B; color: white; }
  .btn-warn:hover:not(:disabled)      { background: #B45309; }
  .btn-sm        { padding: 6px 12px; font-size: 12px; }

  .meta {
    font-size: 12px;
    color: #94A3B8;
    margin-top: 4px;
  }
  .remarks {
    font-size: 12px;
    color: #64748B;
    font-style: italic;
    margin-top: 6px;
    max-width: 360px;
    word-break: break-word;
    white-space: pre-wrap;
  }

  .empty-state {
    text-align: center;
    padding: 64px 24px;
    color: #94A3B8;
  }
  .empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.6; }
  .empty-state p    { font-size: 14px; }

  /* Modal (reused style) */
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
    max-width: 640px;
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
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
  .modal-body   { padding: 20px 24px; overflow-y: auto; }
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
    resize: vertical;
  }
  .form-control:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13,148,136,0.15);
  }
  .form-control.invalid { border-color: #EF4444; }

  @media (max-width: 768px) {
    .stat-row { grid-template-columns: 1fr; }
    td, th { padding: 12px; font-size: 13px; }
  }
</style>

<?php if (isset($_SESSION['module_success'])): ?>
  <div class="alert alert-success">✓ <?php echo sdef_se($_SESSION['module_success']); unset($_SESSION['module_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['module_error'])): ?>
  <div class="alert alert-error">✕ <?php echo sdef_se($_SESSION['module_error']); unset($_SESSION['module_error']); ?></div>
<?php endif; ?>

<?php if (!$sdef_has_table): ?>
  <div class="alert alert-warn">
    ⚠️ The <code>defense_schedule</code> table is not available in this database. Run the latest migration to enable defense scheduling.
  </div>
<?php endif; ?>

<!-- Stat row -->
<div class="stat-row">
  <div class="stat-card upcoming">
    <div class="icon">⏰</div>
    <div>
      <div class="num"><?php echo sdef_se($stat_upcoming_week); ?></div>
      <div class="lbl">Upcoming This Week</div>
    </div>
  </div>
  <div class="stat-card scheduled">
    <div class="icon">🗓️</div>
    <div>
      <div class="num"><?php echo sdef_se($stat_total_sched); ?></div>
      <div class="lbl">Total Scheduled</div>
    </div>
  </div>
  <div class="stat-card done">
    <div class="icon">✅</div>
    <div>
      <div class="num"><?php echo sdef_se($stat_done_term); ?></div>
      <div class="lbl">Completed This Term</div>
    </div>
  </div>
</div>

<!-- Toolbar -->
<div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px;">
  <div class="filter-bar">
    <a class="filter-tab <?php echo $filter === 'upcoming' ? 'active' : ''; ?>"
       href="<?php echo SITE_URL; ?>pages/staff/staff-defense.php?filter=upcoming">
      Upcoming
      <?php if ($stat_total_sched > 0): ?>
        <span class="count"><?php echo sdef_se($stat_total_sched); ?></span>
      <?php endif; ?>
    </a>
    <a class="filter-tab <?php echo $filter === 'past' ? 'active' : ''; ?>"
       href="<?php echo SITE_URL; ?>pages/staff/staff-defense.php?filter=past">
      Past
    </a>
    <a class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>"
       href="<?php echo SITE_URL; ?>pages/staff/staff-defense.php?filter=all">
      All
      <?php if ($stat_total_overall > 0): ?>
        <span class="count"><?php echo sdef_se($stat_total_overall); ?></span>
      <?php endif; ?>
    </a>
  </div>

  <button type="button" class="btn btn-primary" onclick="openScheduleModal()">
    + Schedule Defense
  </button>
</div>

<!-- List -->
<div class="card">
  <?php if (!$sdef_has_table): ?>
    <div class="empty-state">
      <div class="empty-state-icon">🗓️</div>
      <p>Defense scheduling is not available.</p>
    </div>
  <?php elseif (empty($rows)): ?>
    <div class="empty-state">
      <div class="empty-state-icon">📭</div>
      <?php if ($filter === 'upcoming'): ?>
        <p>No upcoming defenses.</p>
        <p style="font-size: 13px; margin-top: 8px; color: #94A3B8;">
          Click <strong>+ Schedule Defense</strong> to add a proposal, pre-oral, or final defense.
        </p>
      <?php elseif ($filter === 'past'): ?>
        <p>No past or completed defenses yet.</p>
      <?php else: ?>
        <p>No defenses scheduled yet.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 150px;">Type</th>
            <th>Research Title</th>
            <th>Owner</th>
            <th style="width: 200px;">Schedule</th>
            <th style="width: 120px;">Status</th>
            <th style="min-width: 280px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $defense_id  = (int) ($row['defense_id'] ?? 0);
            $proj_id     = (int) ($row['project_id'] ?? 0);
            $type        = (string) ($row['type'] ?? 'final');
            $status      = strtolower((string) ($row['status'] ?? 'scheduled'));
            $project_title = (string) ($row['project_title'] ?? 'Untitled project');
            $owner_name  = trim((string) ($row['owner_name'] ?? ''));
            $owner_sid   = (string) ($row['owner_school_id'] ?? '');
            $venue       = trim((string) ($row['venue'] ?? ''));
            $remarks     = trim((string) ($row['remarks'] ?? ''));
            $sched_dt    = $row['schedule_date'] ?? null;

            [$b_class, $b_label] = sdef_statusBadge($status);
            $is_active  = in_array($status, ['scheduled', 'rescheduled'], true);
            $is_terminal = in_array($status, ['done', 'cancelled'], true);
            $type_class = $type;
          ?>
            <tr>
              <td>
                <span class="badge-type <?php echo sdef_se($type_class); ?>"><?php echo sdef_se(sdef_type_label($type)); ?></span>
              </td>
              <td style="max-width: 320px;">
                <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $proj_id; ?>"
                   style="color: #111827; font-weight: 600; text-decoration: none;">
                  <?php echo sdef_se($project_title); ?>
                </a>
                <?php if ($venue !== ''): ?>
                  <div class="meta">📍 <?php echo sdef_se($venue); ?></div>
                <?php endif; ?>
                <?php if ($remarks !== ''): ?>
                  <div class="remarks"><?php echo sdef_se(mb_substr($remarks, 0, 220) . (mb_strlen($remarks) > 220 ? '…' : '')); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight: 500; color: #111827;"><?php echo sdef_se($owner_name !== '' ? $owner_name : '—'); ?></div>
                <?php if ($owner_sid !== ''): ?>
                  <div style="font-size: 12px; color: #64748B;">🎒 <?php echo sdef_se($owner_sid); ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size: 13px; color: #111827; white-space: nowrap;">
                <?php echo sdef_se(sdef_format_dt($sched_dt)); ?>
                <?php if ($is_active): ?>
                  <div class="meta"><?php echo sdef_se(sdef_relative_time($sched_dt)); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-status <?php echo sdef_se($b_class); ?>"><?php echo sdef_se($b_label); ?></span>
              </td>
              <td>
                <?php if ($is_active): ?>
                  <div class="row-actions">
                    <form method="POST" style="display: inline;">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="mark_done">
                      <input type="hidden" name="defense_id" value="<?php echo (int) $defense_id; ?>">
                      <button type="submit" class="btn btn-success btn-sm" title="Mark this defense as completed">✓ Done</button>
                    </form>
                    <button type="button" class="btn btn-warn btn-sm"
                            onclick="openRescheduleModal(<?php echo (int) $defense_id; ?>, '<?php echo sdef_se(addslashes(sdef_type_label($type))); ?>', '<?php echo sdef_se(addslashes(sdef_format_dt($sched_dt))); ?>')">
                      ↻ Reschedule
                    </button>
                    <button type="button" class="btn btn-danger btn-sm"
                            onclick="openCancelModal(<?php echo (int) $defense_id; ?>, '<?php echo sdef_se(addslashes(sdef_type_label($type))); ?>')">
                      ✕ Cancel
                    </button>
                  </div>
                <?php else: ?>
                  <span style="font-size: 12px; color: #94A3B8;">No further action</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Schedule modal -->
<div id="scheduleModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="scheduleModalTitle">
  <div class="modal-content">
    <form method="POST" id="scheduleForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="schedule">

      <div class="modal-header">
        <h3 id="scheduleModalTitle">🗓️ Schedule Defense</h3>
        <button type="button" class="modal-close" onclick="closeScheduleModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="project_id">Research Project <span style="color: #EF4444;">*</span></label>
          <select id="project_id" name="project_id" class="form-control" required>
            <option value="">— Select a project —</option>
            <?php foreach ($active_projects as $p):
              $pid = (int) $p['project_id'];
              $ptitle = (string) ($p['title'] ?? 'Untitled');
              $pstatus = (string) ($p['status'] ?? '');
              $powner = trim((string) ($p['owner_name'] ?? ''));
              $label = $ptitle;
              if ($powner !== '') $label .= ' — ' . $powner;
              if ($pstatus !== '') $label .= ' (' . ucwords(str_replace('_', ' ', $pstatus)) . ')';
            ?>
              <option value="<?php echo (int) $pid; ?>"><?php echo sdef_se($label); ?></option>
            <?php endforeach; ?>
          </select>
          <span class="form-help">Search the dropdown by project title or owner name.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="type">Defense Type <span style="color: #EF4444;">*</span></label>
          <select id="type" name="type" class="form-control" required>
            <option value="proposal">Proposal Defense</option>
            <option value="pre_oral">Pre-Oral Defense</option>
            <option value="final">Final Defense</option>
          </select>
          <span class="form-help">Only one active (scheduled/rescheduled) defense of each type is allowed per project.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="schedule_date">Date &amp; Time <span style="color: #EF4444;">*</span></label>
          <input type="datetime-local" id="schedule_date" name="schedule_date" class="form-control" required>
          <span class="form-help">Must be in the future.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="venue">Venue</label>
          <input type="text" id="venue" name="venue" class="form-control" maxlength="200"
                 placeholder="e.g. Audio-Visual Room, 3rd floor, Main Building">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="remarks">Remarks</label>
          <textarea id="remarks" name="remarks" class="form-control" rows="3"
                    placeholder="Optional notes (panel, instructions, special accommodations)…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeScheduleModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- Cancel modal -->
<div id="cancelModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle">
  <div class="modal-content">
    <form method="POST" id="cancelForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="defense_id" id="cancel_defense_id" value="">

      <div class="modal-header">
        <h3 id="cancelModalTitle">✕ Cancel Defense</h3>
        <button type="button" class="modal-close" onclick="closeCancelModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Cancelling: <strong id="cancel_label" style="color: #111827;"></strong>
        </p>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cancel_reason">Reason <span style="color: #EF4444;">*</span></label>
          <textarea id="cancel_reason" name="reason" class="form-control" rows="3" minlength="5" required
                    placeholder="Why is this defense being cancelled? (min. 5 characters)"></textarea>
          <span class="form-help">The reason will be saved to the remarks and sent as a notification to the student and adviser.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Close</button>
        <button type="submit" class="btn btn-danger">Cancel Defense</button>
      </div>
    </form>
  </div>
</div>

<!-- Reschedule modal -->
<div id="rescheduleModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rescheduleModalTitle">
  <div class="modal-content">
    <form method="POST" id="rescheduleForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="reschedule">
      <input type="hidden" name="defense_id" id="resched_defense_id" value="">

      <div class="modal-header">
        <h3 id="rescheduleModalTitle">↻ Reschedule Defense</h3>
        <button type="button" class="modal-close" onclick="closeRescheduleModal()" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p style="margin: 0 0 16px; font-size: 14px; color: #64748B;">
          Rescheduling: <strong id="resched_label" style="color: #111827;"></strong>
          <br><span id="resched_old" style="color: #94A3B8; font-size: 12px;"></span>
        </p>
        <div class="form-group">
          <label class="form-label" for="new_date">New Date &amp; Time <span style="color: #EF4444;">*</span></label>
          <input type="datetime-local" id="new_date" name="new_date" class="form-control" required>
          <span class="form-help">Must be in the future. The previous date and any reason will be appended to remarks.</span>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="resched_reason">Reason (optional)</label>
          <textarea id="resched_reason" name="reason" class="form-control" rows="2"
                    placeholder="Why is this being rescheduled? (optional)"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeRescheduleModal()">Close</button>
        <button type="submit" class="btn btn-warn">Reschedule</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Schedule modal
  const scheduleModal   = document.getElementById('scheduleModal');
  const scheduleForm    = document.getElementById('scheduleForm');
  const scheduleProject = document.getElementById('project_id');
  const scheduleType    = document.getElementById('type');
  const scheduleDate    = document.getElementById('schedule_date');
  const scheduleVenue   = document.getElementById('venue');
  const scheduleRemarks = document.getElementById('remarks');

  function openScheduleModal() {
    scheduleForm.reset();
    scheduleModal.style.display = 'flex';
    setTimeout(() => scheduleProject.focus(), 50);
  }
  function closeScheduleModal() { scheduleModal.style.display = 'none'; }

  // Cancel modal
  const cancelModal  = document.getElementById('cancelModal');
  const cancelForm   = document.getElementById('cancelForm');
  const cancelLabel  = document.getElementById('cancel_label');
  const cancelRow    = document.getElementById('cancel_defense_id');
  const cancelReason = document.getElementById('cancel_reason');

  function openCancelModal(defenseId, label) {
    cancelRow.value         = defenseId;
    cancelLabel.textContent = label;
    cancelReason.value      = '';
    cancelReason.classList.remove('invalid');
    cancelModal.style.display = 'flex';
    setTimeout(() => cancelReason.focus(), 50);
  }
  function closeCancelModal() { cancelModal.style.display = 'none'; }

  // Reschedule modal
  const reschedModal    = document.getElementById('rescheduleModal');
  const reschedForm     = document.getElementById('rescheduleForm');
  const reschedLabel    = document.getElementById('resched_label');
  const reschedOld      = document.getElementById('resched_old');
  const reschedRow      = document.getElementById('resched_defense_id');
  const reschedDate     = document.getElementById('new_date');
  const reschedReason   = document.getElementById('resched_reason');

  function openRescheduleModal(defenseId, label, oldDt) {
    reschedRow.value         = defenseId;
    reschedLabel.textContent = label;
    reschedOld.textContent   = 'Previous: ' + oldDt;
    reschedDate.value        = '';
    reschedReason.value      = '';
    reschedModal.style.display = 'flex';
    setTimeout(() => reschedDate.focus(), 50);
  }
  function closeRescheduleModal() { reschedModal.style.display = 'none'; }

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (scheduleModal.style.display === 'flex')  closeScheduleModal();
      if (cancelModal.style.display === 'flex')    closeCancelModal();
      if (reschedModal.style.display === 'flex')  closeRescheduleModal();
    }
  });

  // Close on backdrop click
  [scheduleModal, cancelModal, reschedModal].forEach((m) => {
    m.addEventListener('click', (e) => {
      if (e.target === m) m.style.display = 'none';
    });
  });

  // Client-side guards
  scheduleForm.addEventListener('submit', (e) => {
    if (!scheduleProject.value) {
      e.preventDefault();
      scheduleProject.classList.add('invalid');
      scheduleProject.focus();
      return;
    }
    if (!scheduleDate.value) {
      e.preventDefault();
      scheduleDate.classList.add('invalid');
      scheduleDate.focus();
      return;
    }
    const picked = new Date(scheduleDate.value);
    if (isNaN(picked.getTime()) || picked <= new Date()) {
      e.preventDefault();
      scheduleDate.classList.add('invalid');
      scheduleDate.focus();
    }
  });
  [scheduleProject, scheduleDate].forEach((el) => {
    el.addEventListener('input',  () => el.classList.remove('invalid'));
    el.addEventListener('change', () => el.classList.remove('invalid'));
  });

  cancelForm.addEventListener('submit', (e) => {
    const v = cancelReason.value.trim();
    if (v.length < 5) {
      e.preventDefault();
      cancelReason.classList.add('invalid');
      cancelReason.focus();
    }
  });
  cancelReason.addEventListener('input', () => cancelReason.classList.remove('invalid'));

  reschedForm.addEventListener('submit', (e) => {
    if (!reschedDate.value) {
      e.preventDefault();
      reschedDate.classList.add('invalid');
      reschedDate.focus();
      return;
    }
    const picked = new Date(reschedDate.value);
    if (isNaN(picked.getTime()) || picked <= new Date()) {
      e.preventDefault();
      reschedDate.classList.add('invalid');
      reschedDate.focus();
    }
  });
  reschedDate.addEventListener('input',  () => reschedDate.classList.remove('invalid'));
  reschedDate.addEventListener('change', () => reschedDate.classList.remove('invalid'));
</script>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
