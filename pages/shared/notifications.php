<?php
/**
 * Shared Notifications page.
 *
 * Works for all roles (student, faculty, research_staff, admin).
 * Routes to the matching role shell so the sidebar/topbar match the
 * rest of the logged-in user's experience.
 *
 * Features:
 *   - List current user's notifications, newest first
 *   - Unread notifications visually distinct (dot indicator + bold title)
 *   - Mark a single notification as read
 *   - Mark all as read
 *   - Delete a single notification (only the user's own)
 *   - Type-coded badges (info / success / warning / error)
 *   - Relative timestamps ("2 hours ago") with a small helper
 *   - Empty state when the inbox is empty
 *   - LIMIT 50 most recent to keep page bounded
 *
 * All POST handling uses CSRF, prepared statements, and logActivity().
 * Notifications are always filtered by user_id so a user can only act
 * on their own rows.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireLogin();

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user_id = (int) $user['user_id'];
$role    = (string) ($user['role'] ?? 'student');

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Local HTML-escape helper — mirrors the role shells' se()/rms_escape().
 */
function notif_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Build a usable notification URL from either a relative path or full URL. */
function notif_link_url($link) {
  $link = trim((string) $link);
  if ($link === '') {
    return '';
  }

  if (preg_match('#^(https?:)?//#i', $link)) {
    return $link;
  }

  return SITE_URL . ltrim($link, '/');
}

/**
 * Render a timestamp as a relative phrase like "just now", "5 min ago",
 * "2 hours ago", "3 days ago", or a fallback absolute date for older rows.
 * Uses server-side computation against the current time so output is stable
 * across page reloads within the same request.
 */
function notif_relative_time($created_at) {
    $ts   = strtotime((string) $created_at);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 0)             $diff = 0;

    if ($diff < 45)            return 'just now';
    if ($diff < 90)            return '1 min ago';
    if ($diff < 3300)          return intval($diff / 60) . ' min ago';
    if ($diff < 5400)          return '1 hour ago';
    if ($diff < 86400)         return intval($diff / 3600) . ' hours ago';
    if ($diff < 172800)        return '1 day ago';
    if ($diff < 604800)        return intval($diff / 86400) . ' days ago';
    if ($diff < 2592000)       return intval($diff / 604800) . ' weeks ago';
    if ($diff < 31536000)      return intval($diff / 2592000) . ' months ago';
    return intval($diff / 31536000) . ' years ago';
}

/**
 * Map a notification type to a pill color set. Uses the existing
 * design-system status tokens (Draft/Pending, Submitted, Approved, Error).
 */
function notif_type_badge($type) {
    $safe_type = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
    return '<span class="notif-type type-' . notif_se($safe_type) . '">' .
           notif_se(ucfirst($safe_type)) . '</span>';
}

// ---------------------------------------------------------------
// POST handling — runs before any HTML output so we can redirect.
// Three actions: mark_read, mark_all_read, delete.
// ---------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ---- Mark a single notification as read ---------------------------
    if ($action === 'mark_read') {
        $notification_id = (int) ($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            $stmt = $conn->prepare(
                'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $notification_id, $user_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    logActivity('Marked notification as read', 'notifications');
                    $_SESSION['module_success'] = 'Notification marked as read.';
                }
                $stmt->close();
            }
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // ---- Mark all of the user's notifications as read -----------------
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            if ($stmt->execute()) {
                logActivity('Marked all notifications as read', 'notifications');
                $_SESSION['module_success'] = 'All notifications marked as read.';
            }
            $stmt->close();
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // ---- Delete a single notification ---------------------------------
    if ($action === 'delete') {
        $notification_id = (int) ($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            $stmt = $conn->prepare(
                'DELETE FROM notifications WHERE notification_id = ? AND user_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $notification_id, $user_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    logActivity('Deleted notification', 'notifications');
                    $_SESSION['module_success'] = 'Notification deleted.';
                }
                $stmt->close();
            }
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ---------------------------------------------------------------
// Load the user's notifications (LIMIT 50, newest first).
// ---------------------------------------------------------------
$NOTIF_LIMIT = 50;

$rows = [];
$list_stmt = $conn->prepare(
    'SELECT notification_id, title, message, type, link, is_read, created_at
       FROM notifications
      WHERE user_id = ?
   ORDER BY created_at DESC
      LIMIT ' . $NOTIF_LIMIT
);
if ($list_stmt) {
    $list_stmt->bind_param('i', $user_id);
    $list_stmt->execute();
    $result = $list_stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $list_stmt->close();
}

$total_count       = count($rows);
$unread_count      = 0;
$linked_count      = 0;
$today_count       = 0;
$today_key         = date('Y-m-d');
foreach ($rows as $r) {
    if ((int) $r['is_read'] === 0) {
        $unread_count++;
    }
    if (trim((string) ($r['link'] ?? '')) !== '') {
        $linked_count++;
    }
    if (substr((string) ($r['created_at'] ?? ''), 0, 10) === $today_key) {
        $today_count++;
    }
}
$read_count = max(0, $total_count - $unread_count);

// ---------------------------------------------------------------
// Flash message helper (mirrors profile.php).
// ---------------------------------------------------------------
function notif_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $class = $type === 'error' ? 'is-error' : 'is-success';
        echo '<div class="notif-flash ' . $class . '"><span aria-hidden="true">' .
             ($type === 'error' ? '&times;' : '&#10003;') . '</span>' . notif_se($message) . '</div>';
    }
}

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors profile.php and module-page.php.
// ---------------------------------------------------------------
$page_title    = 'Notifications';
$page_subtitle = $unread_count > 0
    ? "You have {$unread_count} unread notification" . ($unread_count === 1 ? '' : 's') . '.'
    : 'Your research activity is up to date.';
$notifTheme = match ($role) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'highlight' => '#FED7AA', 'rgb' => '245,124,0'],
    'research_staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'highlight' => '#99F6E4', 'rgb' => '13,148,136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'highlight' => '#BFDBFE', 'rgb' => '29,78,216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'highlight' => '#DDD6FE', 'rgb' => '91,30,188'],
};

if ($role === 'admin') {
    renderAdminShell($user, 'notifications.php', $page_title, $page_subtitle);
} elseif ($role === 'research_staff') {
    renderStaffShell($user, 'notifications.php', $page_title, $page_subtitle);
} elseif ($role === 'faculty') {
    renderFacultyShell($user, 'notifications.php', $page_title, $page_subtitle);
} else {
    renderStudentShell($user, 'notifications.php', $page_title, $page_subtitle);
}

notif_flash('success');
notif_flash('error');
?>

<style>
  .notifications-workspace { --notif-ink:#192235; --notif-gold:<?= notif_se($notifTheme['accent']) ?>; --notif-accent:<?= notif_se($notifTheme['accent']) ?>; --notif-deep:<?= notif_se($notifTheme['deep']) ?>; --notif-tint:<?= notif_se($notifTheme['tint']) ?>; --notif-highlight:<?= notif_se($notifTheme['highlight']) ?>; --notif-rgb:<?= notif_se($notifTheme['rgb']) ?>; --notif-line:#dfe5ed; --notif-muted:#687386; max-width:1480px; margin:0 auto; color:var(--notif-ink); }
  .notif-flash { display:flex; align-items:center; gap:10px; max-width:1480px; margin:0 auto 18px; padding:13px 16px; border:1px solid #d9e6de; border-radius:10px; background:#f3faf6; color:#245d40; font-size:13px; font-weight:600; }.notif-flash.is-error{border-color:#edd8d2;background:#fff6f3;color:#93432f}.notif-flash span{display:grid;place-items:center;width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,.72);font-weight:800}
  .notif-hero { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.2fr) minmax(290px,.8fr); gap:46px; min-height:310px; padding:49px 54px 57px; overflow:hidden; border-radius:24px 24px 8px 8px; background:radial-gradient(circle at 84% 14%,rgba(210,162,72,.2),transparent 29%),linear-gradient(135deg,#172033,#202d45 64%,#27344b); color:#fff; box-shadow:0 24px 58px rgba(24,34,53,.16); }
  .notif-hero::after { content:''; position:absolute; inset:0; z-index:-1; opacity:.16; background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); pointer-events:none; }
  .notif-kicker,.notif-panel-eyebrow,.notif-stat-code { font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.15em; text-transform:uppercase; }.notif-kicker{margin-bottom:17px;color:#e9bf6e}.notif-hero h2{max-width:760px;margin:0;color:#fff;font-size:clamp(39px,4.4vw,64px);line-height:.98;letter-spacing:-.054em;text-wrap:balance}.notif-hero-copy>p{max-width:610px;margin:23px 0 0;color:#bdc8d8;font-size:15px;line-height:1.72;text-wrap:pretty}.notif-hero-note{display:inline-flex;align-items:center;gap:9px;margin-top:24px;color:#aeb9ca;font-size:12px}.notif-hero-note::before{content:'';width:7px;height:7px;border-radius:50%;background:<?php echo $unread_count > 0 ? '#e2ad4c' : '#70bd91'; ?>;box-shadow:0 0 0 5px rgba(255,255,255,.07)}
  .notif-stat-stack{align-self:end;display:grid;gap:2px}.notif-stat{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.notif-stat:first-child{border-radius:14px 14px 5px 5px}.notif-stat:last-child{border-radius:5px 5px 14px 14px}.notif-stat-code{color:#e9bf6e}.notif-stat-label{color:#d4dce8;font-size:13px}.notif-stat-value{font-size:24px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
  .notif-commandbar{position:relative;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:18px;margin:-18px 22px 0;padding:13px 15px;border:1px solid var(--notif-line);border-radius:13px;background:#f8fafc;box-shadow:0 12px 30px rgba(25,34,53,.08)}.notif-summary-counts{color:#667286;font-size:12px}.notif-summary-counts strong{color:#1f293b;font:720 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.notif-command-actions{display:flex;gap:9px;flex-wrap:wrap}
  .notif-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:8px 13px;border:1px solid transparent;border-radius:7px;background:none;color:inherit;font-family:inherit;font-size:12px;font-weight:680;line-height:1.2;text-decoration:none;cursor:pointer;transition:transform .2s ease,background .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease}.notif-btn:hover{transform:translateY(-1px)}.notif-btn:active{transform:translateY(0) scale(.98)}.notif-btn:focus-visible,.notif-link:focus-visible{outline:3px solid rgba(210,162,72,.3);outline-offset:2px}.notif-btn-primary{border-color:var(--notif-gold);background:var(--notif-gold);color:#182033;box-shadow:0 7px 17px rgba(210,162,72,.14)}.notif-btn-primary:hover{border-color:#dfb45f;background:#dfb45f}.notif-btn-secondary{border-color:#d8dfe8;background:#fff;color:#344054}.notif-btn-secondary:hover{border-color:#b3bdca;background:#f7f8fa}.notif-btn-danger{border-color:#ead6d1;background:#fff8f6;color:#984633}.notif-btn-danger:hover{border-color:#d39a8d;background:#faece8}.notif-btn-sm{min-height:34px;padding:7px 10px;font-size:11px}
  .notif-ledger{margin-top:34px;overflow:hidden;border:1px solid var(--notif-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.065)}.notif-ledger-header{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:28px 31px 23px;border-bottom:1px solid #e8ecf1}.notif-panel-eyebrow{margin-bottom:8px;color:#987027}.notif-ledger-title{margin:0;color:#1c2639;font-size:25px;line-height:1.1;letter-spacing:-.03em}.notif-ledger-copy{max-width:620px;margin:8px 0 0;color:var(--notif-muted);font-size:13px;line-height:1.55}.notif-ledger-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}
  .notif-list{display:flex;flex-direction:column}.notif-item{position:relative;display:grid;grid-template-columns:48px minmax(0,1fr) auto;gap:17px;align-items:start;padding:23px 30px;border-bottom:1px solid #edf0f4;background:#fff;transition:background .2s ease}.notif-item:last-child{border-bottom:0}.notif-item:hover{background:#fbfaf7}.notif-item.is-unread{background:#fcfaf4}.notif-item.is-unread:hover{background:#faf6eb}.notif-marker{position:relative;display:grid;place-items:center;width:44px;height:44px;border:1px solid #dfe5ec;border-radius:11px;background:#f3f5f8;color:#627084;font:750 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.notif-item.is-unread .notif-marker{border-color:#e2d1ae;background:#f8f1e3;color:#825e22}.notif-dot{position:absolute;right:-3px;top:-3px;width:9px;height:9px;border:2px solid #fff;border-radius:50%;background:#bb8330}.notif-item.is-read .notif-dot{display:none}.notif-body{min-width:0}.notif-title{margin:0 0 6px;color:#202a3d;font-size:15px;font-weight:690;line-height:1.35;text-wrap:pretty;overflow-wrap:anywhere}.notif-item.is-read .notif-title{color:#3f4b5f;font-weight:590}.notif-message{max-width:820px;margin:0;color:#667286;font-size:13px;line-height:1.58;overflow-wrap:anywhere}.notif-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:11px;color:#919baa;font-size:11px}.notif-time{font-weight:680}.notif-absolute{color:#929cab}.notif-type{display:inline-flex;align-items:center;padding:4px 7px;border:1px solid #d7e3ef;border-radius:5px;background:#f0f5fa;color:#315b8c;font-size:9px;font-weight:750;letter-spacing:.06em;text-transform:uppercase}.notif-type.type-success{border-color:#d4e8da;background:#f0f8f3;color:#347451}.notif-type.type-warning{border-color:#eadaba;background:#faf4e8;color:#865f24}.notif-type.type-error{border-color:#ead6d1;background:#faf0ed;color:#974b39}.notif-link{display:inline-flex;align-items:center;gap:5px;color:#825f25;font-size:11px;font-weight:720;text-decoration:none}.notif-link:hover{color:#513a16;text-decoration:underline}.notif-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap}.notif-action-form{display:inline;margin:0}
  .notif-empty{padding:72px 28px;text-align:center}.notif-empty-icon{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 16px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#876225;font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.notif-empty-title{color:#273247;font-size:18px;font-weight:690}.notif-empty-subtitle{max-width:520px;margin:7px auto 0;color:#7a8596;font-size:13px;line-height:1.58}.notif-truncated-note{padding:15px 24px;border-top:1px solid #edf0f4;background:#fafbfc;color:#8791a1;font-size:11px;text-align:center}
  .notif-modal[hidden]{display:none}.notif-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:22px;background:rgba(15,22,35,.64);backdrop-filter:blur(7px);opacity:0;transition:opacity .22s ease}.notif-modal.is-open{opacity:1}.notif-modal-panel{position:relative;width:min(100%,430px);overflow:hidden;border:1px solid rgba(255,255,255,.76);border-radius:18px;background:#fff;box-shadow:0 30px 80px rgba(15,22,35,.3),0 0 0 1px rgba(25,34,53,.08);transform:translateY(14px) scale(.975);transition:transform .26s cubic-bezier(.16,1,.3,1)}.notif-modal.is-open .notif-modal-panel{transform:translateY(0) scale(1)}.notif-modal-accent{height:5px;background:linear-gradient(90deg,#b65d46,#d58c71)}.notif-modal-content{display:grid;grid-template-columns:54px minmax(0,1fr);gap:17px;padding:28px 28px 21px}.notif-modal-icon{display:grid;width:50px;height:50px;place-items:center;border:1px solid #efd2ca;border-radius:14px;background:#fff3ef;color:#a94d37;font:800 21px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.notif-modal-kicker{margin:1px 0 7px;color:#a14b37;font:750 9px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.13em;text-transform:uppercase}.notif-modal-title{margin:0;color:#1d2739;font-size:22px;font-weight:740;line-height:1.15;letter-spacing:-.035em}.notif-modal-copy{margin:10px 0 0;color:#697487;font-size:12px;line-height:1.6}.notif-modal-record{overflow:hidden;margin:15px 0 0;padding:10px 12px;border-left:3px solid #d48670;border-radius:4px 8px 8px 4px;background:#faf5f3;color:#4f596a;font-size:11px;font-weight:650;line-height:1.45;text-overflow:ellipsis;white-space:nowrap}.notif-modal-actions{display:flex;justify-content:flex-end;gap:9px;padding:15px 28px 22px;border-top:1px solid #e9edf2;background:#fafbfc}.notif-modal-actions .notif-btn{min-height:40px;padding-inline:16px}.notif-modal-confirm{border-color:#aa4d38;background:#aa4d38;color:#fff;box-shadow:0 8px 18px rgba(170,77,56,.18)}.notif-modal-confirm:hover{border-color:#913e2c;background:#913e2c}.notif-modal-confirm:focus-visible,.notif-modal-cancel:focus-visible{outline:3px solid rgba(180,91,67,.25);outline-offset:2px}body.notif-modal-open{overflow:hidden}
  .notifications-workspace .notif-hero{background:radial-gradient(circle at 84% 14%,rgba(255,255,255,.2),transparent 29%),linear-gradient(135deg,var(--notif-deep),var(--notif-accent));box-shadow:0 24px 58px rgba(var(--notif-rgb),.15)}
  .notifications-workspace .notif-kicker,.notifications-workspace .notif-stat-code{color:var(--notif-highlight)}
  .notifications-workspace .notif-btn:focus-visible,.notifications-workspace .notif-link:focus-visible{outline-color:rgba(var(--notif-rgb),.28)}
  .notifications-workspace .notif-btn-primary{border-color:var(--notif-accent);background:var(--notif-accent);color:#fff;box-shadow:0 7px 17px rgba(var(--notif-rgb),.18)}
  .notifications-workspace .notif-btn-primary:hover{filter:brightness(1.08)}
  .notifications-workspace .notif-panel-eyebrow,.notifications-workspace .notif-link{color:var(--notif-accent)}
  .notifications-workspace .notif-link:hover{color:var(--notif-deep)}
  .notifications-workspace .notif-item.is-unread,.notifications-workspace .notif-item.is-unread:hover{background:var(--notif-tint)}
  .notifications-workspace .notif-item.is-unread .notif-marker{border-color:rgba(var(--notif-rgb),.2);background:#fff;color:var(--notif-accent)}
  .notifications-workspace .notif-dot{background:var(--notif-accent)}
  .notifications-workspace .notif-empty-icon{border-color:rgba(var(--notif-rgb),.2);background:var(--notif-tint);color:var(--notif-accent)}
  @media(max-width:1000px){.notif-hero{grid-template-columns:1fr;gap:30px}.notif-stat-stack{grid-template-columns:repeat(3,1fr)}.notif-stat{grid-template-columns:32px 1fr}.notif-stat-value{grid-column:2}.notif-item{grid-template-columns:44px minmax(0,1fr)}.notif-actions{grid-column:2;justify-content:flex-start}}
  @media(max-width:700px){.notif-hero{min-height:0;padding:30px 24px 48px;border-radius:18px 18px 7px 7px}.notif-hero h2{font-size:39px}.notif-stat-stack{grid-template-columns:1fr}.notif-stat{grid-template-columns:34px 1fr auto}.notif-stat-value{grid-column:auto}.notif-commandbar{align-items:stretch;flex-direction:column;margin:-17px 12px 0}.notif-command-actions,.notif-command-actions form,.notif-command-actions .notif-btn{width:100%}.notif-ledger{margin-top:24px}.notif-ledger-header{align-items:flex-start;flex-direction:column;padding:24px 20px 20px}.notif-ledger-count{white-space:normal}.notif-item{grid-template-columns:40px minmax(0,1fr);gap:13px;padding:19px}.notif-marker{width:38px;height:38px}.notif-actions{grid-column:1/-1;justify-content:stretch}.notif-actions form{flex:1}.notif-actions .notif-btn{width:100%}.notif-absolute{display:none}.notif-modal{padding:14px}.notif-modal-content{grid-template-columns:44px minmax(0,1fr);gap:13px;padding:23px 20px 19px}.notif-modal-icon{width:42px;height:42px;border-radius:12px;font-size:18px}.notif-modal-actions{padding:14px 20px 19px}.notif-modal-title{font-size:20px}}
  @media(prefers-reduced-motion:reduce){.notif-btn,.notif-item,.notif-modal,.notif-modal-panel{transition:none}}
</style>

<div class="notifications-workspace">
  <section class="notif-hero" aria-labelledby="notifications-hero-title">
    <div class="notif-hero-copy">
      <div class="notif-kicker">RMS activity ledger &middot; Account updates</div>
      <h2 id="notifications-hero-title"><?php echo $unread_count > 0 ? 'Your attention queue, clearly ordered.' : 'Everything important, accounted for.'; ?></h2>
      <p>Track reviews, status decisions, messages, and project milestones from one chronological record across the institute workflow.</p>
      <div class="notif-hero-note"><?php echo $unread_count > 0
          ? $unread_count . ' update' . ($unread_count === 1 ? '' : 's') . ' still need your attention.'
          : 'No unread updates are waiting for you.'; ?></div>
    </div>
    <div class="notif-stat-stack" aria-label="Notification totals">
      <div class="notif-stat"><span class="notif-stat-code">01</span><span class="notif-stat-label">Unread</span><strong class="notif-stat-value"><?php echo (int) $unread_count; ?></strong></div>
      <div class="notif-stat"><span class="notif-stat-code">02</span><span class="notif-stat-label">Received today</span><strong class="notif-stat-value"><?php echo (int) $today_count; ?></strong></div>
      <div class="notif-stat"><span class="notif-stat-code">03</span><span class="notif-stat-label">Linked actions</span><strong class="notif-stat-value"><?php echo (int) $linked_count; ?></strong></div>
    </div>
  </section>

  <div class="notif-commandbar">
    <div class="notif-summary-counts">
      Showing <strong><?php echo (int) $total_count; ?></strong> recent updates
      &middot; <strong><?php echo (int) $read_count; ?></strong> reviewed
    </div>
    <div class="notif-command-actions">
      <?php if ($unread_count > 0): ?>
        <form method="post" class="notif-action-form">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="mark_all_read">
          <button class="notif-btn notif-btn-primary" type="submit">Mark all as read</button>
        </form>
      <?php else: ?>
        <span class="notif-btn notif-btn-secondary" aria-disabled="true">All caught up</span>
      <?php endif; ?>
    </div>
  </div>

  <section class="notif-ledger" aria-labelledby="notification-ledger-title">
    <header class="notif-ledger-header">
      <div>
        <div class="notif-panel-eyebrow">Chronological record</div>
        <h3 class="notif-ledger-title" id="notification-ledger-title">Recent activity</h3>
        <p class="notif-ledger-copy">Newest first. Open linked records, acknowledge unread updates, or remove items you no longer need.</p>
      </div>
      <span class="notif-ledger-count">LATEST <?php echo (int) min($total_count, $NOTIF_LIMIT); ?></span>
    </header>

    <?php if (!$rows): ?>
      <div class="notif-empty">
        <div class="notif-empty-icon" aria-hidden="true">00</div>
        <div class="notif-empty-title">No notifications yet</div>
        <div class="notif-empty-subtitle">Reviews, messages, project decisions, and other account activity will appear here when they happen.</div>
      </div>
    <?php else: ?>
    <div class="notif-list">
      <?php foreach ($rows as $row):
        $nid       = (int) $row['notification_id'];
        $is_unread = ((int) $row['is_read'] === 0);
        $type      = (string) ($row['type'] ?? 'info');
        $title     = (string) ($row['title'] ?? '');
        $message   = (string) ($row['message'] ?? '');
        $link      = (string) ($row['link'] ?? '');
        $created   = (string) ($row['created_at'] ?? '');
        $rel       = notif_relative_time($created);
        $abs       = $created !== '' ? date('M d, Y \a\t h:i A', strtotime($created)) : '';
        $type_codes = ['info' => 'INF', 'success' => 'OK', 'warning' => 'ACT', 'error' => 'ERR'];
        $type_code = $type_codes[$type] ?? 'INF';
      ?>
      <article class="notif-item <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>">
        <div class="notif-marker" aria-hidden="true"><?php echo notif_se($type_code); ?><span class="notif-dot"></span></div>
        <div class="notif-body">
          <h4 class="notif-title"><?php echo notif_se($title); ?></h4>
          <p class="notif-message"><?php echo nl2br(notif_se($message)); ?></p>
          <div class="notif-meta">
            <?php echo notif_type_badge($type); ?>
            <span class="notif-time" title="<?php echo notif_se($abs); ?>"><?php echo notif_se($rel); ?></span>
            <?php if ($abs !== ''): ?>
              <span class="notif-absolute">&middot; <?php echo notif_se($abs); ?></span>
            <?php endif; ?>
            <?php if ($link !== ''): ?>
              <a class="notif-link" href="<?php echo notif_se(notif_link_url($link)); ?>" target="_blank" rel="noopener">Open record <span aria-hidden="true">&#8599;</span></a>
            <?php endif; ?>
          </div>
        </div>
        <div class="notif-actions">
          <?php if ($is_unread): ?>
            <form method="post" class="notif-action-form">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="notification_id" value="<?php echo $nid; ?>">
              <button class="notif-btn notif-btn-secondary notif-btn-sm" type="submit">Mark read</button>
            </form>
          <?php endif; ?>
          <form method="post" class="notif-action-form notif-delete-form"
                data-notification-title="<?php echo notif_se($title); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notification_id" value="<?php echo $nid; ?>">
            <button class="notif-btn notif-btn-danger notif-btn-sm" type="submit">Delete</button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

      <?php if ($total_count >= $NOTIF_LIMIT): ?>
        <div class="notif-truncated-note">
          Showing the <?php echo (int) $NOTIF_LIMIT; ?> most recent notifications. Older activity is outside this view.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <div class="notif-modal" id="notificationDeleteModal" hidden>
    <section class="notif-modal-panel" role="dialog" aria-modal="true" aria-labelledby="notificationDeleteTitle" aria-describedby="notificationDeleteCopy">
      <div class="notif-modal-accent" aria-hidden="true"></div>
      <div class="notif-modal-content">
        <div class="notif-modal-icon" aria-hidden="true">!</div>
        <div>
          <p class="notif-modal-kicker">Remove notification</p>
          <h2 class="notif-modal-title" id="notificationDeleteTitle">Delete this update?</h2>
          <p class="notif-modal-copy" id="notificationDeleteCopy">This notification will be permanently removed from your activity record. This action cannot be undone.</p>
          <p class="notif-modal-record" id="notificationDeleteRecord"></p>
        </div>
      </div>
      <div class="notif-modal-actions">
        <button class="notif-btn notif-btn-secondary notif-modal-cancel" type="button">Keep notification</button>
        <button class="notif-btn notif-modal-confirm" type="button">Delete notification</button>
      </div>
    </section>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('notificationDeleteModal');
  if (!modal) return;

  const cancelButton = modal.querySelector('.notif-modal-cancel');
  const confirmButton = modal.querySelector('.notif-modal-confirm');
  const record = document.getElementById('notificationDeleteRecord');
  let pendingForm = null;
  let previousFocus = null;

  function openDeleteModal(form) {
    pendingForm = form;
    previousFocus = document.activeElement;
    record.textContent = form.dataset.notificationTitle || 'Selected notification';
    modal.hidden = false;
    document.body.classList.add('notif-modal-open');
    window.requestAnimationFrame(() => {
      modal.classList.add('is-open');
      cancelButton.focus();
    });
  }

  function closeDeleteModal() {
    modal.classList.remove('is-open');
    document.body.classList.remove('notif-modal-open');
    const focusTarget = previousFocus;
    window.setTimeout(() => {
      modal.hidden = true;
      pendingForm = null;
      if (focusTarget instanceof HTMLElement) focusTarget.focus();
    }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220);
  }

  document.querySelectorAll('.notif-delete-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      openDeleteModal(form);
    });
  });

  cancelButton.addEventListener('click', closeDeleteModal);
  confirmButton.addEventListener('click', () => {
    if (!pendingForm) return;
    confirmButton.disabled = true;
    pendingForm.submit();
  });
  modal.addEventListener('mousedown', (event) => {
    if (event.target === modal) closeDeleteModal();
  });
  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeDeleteModal();
      return;
    }
    if (event.key === 'Tab') {
      const focusable = [cancelButton, confirmButton];
      const currentIndex = focusable.indexOf(document.activeElement);
      event.preventDefault();
      focusable[(currentIndex + (event.shiftKey ? -1 : 1) + focusable.length) % focusable.length].focus();
    }
  });
})();
</script>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} elseif ($role === 'research_staff') {
    renderStaffShellClose();
} elseif ($role === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
