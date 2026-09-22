<?php
/**
 * Shared Messages page.
 *
 * Works for all roles (student, faculty, research_staff, admin).
 * Routes to the matching role shell so the sidebar/topbar match the
 * rest of the logged-in user's experience.
 *
 * Features:
 *   - Inbox (default) and Sent tabs, switchable via ?view=sent
 *   - Detail view via ?id=X; only sender or recipient may open it
 *   - Opening an unread received message marks it as read
 *   - Compose form: recipient dropdown (active users, exclude self),
 *     subject, body — full validation, prepared-statement insert
 *   - Reply pre-fills recipient and prefixes subject with "Re: "
 *   - When a message is sent, a row is added to the recipient's
 *     notifications inbox (best-effort: the createNotification() helper
 *     fails silently if the notifications table is unavailable)
 *   - LIMIT 50 per list view
 *   - Empty states for inbox and sent
 *   - Relative timestamps (mirrors notifications.php)
 *   - Role badges next to names
 *
 * All POSTs use CSRF + prepared statements + logActivity() on send.
 * Every message access is gated by `sender_id = ? OR recipient_id = ?`
 * so a user can never read or auto-mark another user's mail.
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

/** Local HTML-escape helper. */
function msg_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Relative-time formatter — mirrors notif_relative_time() in notifications.php. */
function msg_relative_time($created_at) {
    $ts = strtotime((string) $created_at);
    if ($ts === false) return '—';
    $diff = max(0, time() - $ts);
    if ($diff < 45)        return 'just now';
    if ($diff < 90)        return '1 min ago';
    if ($diff < 3300)      return intval($diff / 60) . ' min ago';
    if ($diff < 5400)      return '1 hour ago';
    if ($diff < 86400)     return intval($diff / 3600) . ' hours ago';
    if ($diff < 172800)    return '1 day ago';
    if ($diff < 604800)    return intval($diff / 86400) . ' days ago';
    if ($diff < 2592000)   return intval($diff / 604800) . ' weeks ago';
    if ($diff < 31536000)  return intval($diff / 2592000) . ' months ago';
    return intval($diff / 31536000) . ' years ago';
}

/** Role label + accent color tuple (matches the design-system tokens). */
function msg_role_meta($r) {
    $map = [
        'admin'          => ['Administrator',   '#9a6827'],
        'research_staff' => ['Research Staff',  '#267064'],
        'faculty'        => ['Faculty Adviser', '#315b8c'],
        'student'        => ['Student',         '#72528c'],
    ];
    return $map[$r] ?? [ucfirst(str_replace('_', ' ', (string) $r)), '#64748B'];
}

/** Render a small role badge (re-uses the same pill pattern as notif_type_badge). */
function msg_role_badge($r) {
    [$label] = msg_role_meta($r);
    $role_class = in_array($r, ['admin', 'research_staff', 'faculty', 'student'], true)
        ? str_replace('_', '-', $r)
        : 'other';
    return '<span class="msg-role-badge role-' . msg_se($role_class) . '">' . msg_se($label) . '</span>';
}

/** Flash message helper. */
function msg_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $class = $type === 'error' ? 'is-error' : 'is-success';
        echo '<div class="msg-flash ' . $class . '"><span aria-hidden="true">' .
             ($type === 'error' ? '&times;' : '&#10003;') . '</span>' . msg_se($message) . '</div>';
    }
}

/** Build a snippet of the message body (first 120 chars, single line). */
function msg_snippet($body, $len = 120) {
    $body = trim(preg_replace('/\s+/', ' ', (string) $body));
    if (mb_strlen($body) <= $len) return $body;
    return mb_substr($body, 0, $len) . '…';
}

// ---------------------------------------------------------------
// POST handling — runs before any HTML output so we can redirect.
// Only one action: send_message. (Read/mark-read happen via GET ?id=.)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'send_message') {
        $recipient_id = (int) ($_POST['recipient_id'] ?? 0);
        $subject      = trim((string) ($_POST['subject'] ?? ''));
        $body         = trim((string) ($_POST['message'] ?? ''));
        $errors       = [];

        if ($recipient_id <= 0) {
            $errors[] = 'Please choose a recipient.';
        } elseif ($recipient_id === $user_id) {
            $errors[] = 'You cannot send a message to yourself.';
        } else {
            // Verify recipient exists and is active.
            $r_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
            if ($r_stmt) {
                $r_stmt->bind_param('i', $recipient_id);
                $r_stmt->execute();
                $r_row = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if (!$r_row) {
                    $errors[] = 'The selected recipient is not an active user.';
                }
            } else {
                $errors[] = 'Could not verify the recipient. Please try again.';
            }
        }

        if ($subject === '') {
            $errors[] = 'Subject is required.';
        } elseif (mb_strlen($subject) > 160) {
            $errors[] = 'Subject is too long (max 160 characters).';
        }
        if ($body === '') {
            $errors[] = 'Message body is required.';
        } elseif (mb_strlen($body) > 5000) {
            $errors[] = 'Message body is too long (max 5000 characters).';
        }

        if ($errors) {
            $_SESSION['module_error'] = implode(' ', $errors);
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $ins = $conn->prepare(
            'INSERT INTO messages (sender_id, recipient_id, subject, message) VALUES (?, ?, ?, ?)'
        );
        if (!$ins) {
            $_SESSION['module_error'] = 'The message could not be sent. Please try again.';
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        $ins->bind_param('iiss', $user_id, $recipient_id, $subject, $body);
        if ($ins->execute()) {
            $ins->close();
            logActivity('Sent a message', 'messages');
            // Best-effort notification — createNotification() returns false if the
            // notifications table is missing; we don't want to fail the send.
            $sender_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            createNotification(
                $recipient_id,
                'New message',
                $sender_name !== '' ? "$sender_name sent you a message: \"$subject\"" : "You received a new message: \"$subject\"",
                'info',
                'pages/shared/messages.php'
            );
            $_SESSION['module_success'] = 'Message sent.';
        } else {
            $_SESSION['module_error'] = 'The message could not be sent. Please try again.';
        }
        header('Location: ' . SITE_URL . 'pages/shared/messages.php?view=sent');
        exit;
    }
}

// ---------------------------------------------------------------
// Determine which view to render.
//   ?view=sent       → Sent tab
//   ?id=N            → Detail view (overrides view)
//   default          → Inbox tab
// ---------------------------------------------------------------
$MSG_LIMIT     = 50;
$current_view  = (string) ($_GET['view'] ?? 'inbox');
if (!in_array($current_view, ['inbox', 'sent'], true)) {
    $current_view = 'inbox';
}
$detail_id     = (int) ($_GET['id'] ?? 0);
$is_detail     = ($detail_id > 0);

// ---------------------------------------------------------------
// Load recipient dropdown — active users, exclude self.
// (Re-used in detail and list views when the compose form is shown.)
// ---------------------------------------------------------------
$recipients = [];
$r_stmt = $conn->prepare(
    "SELECT user_id, first_name, last_name, role
       FROM users
      WHERE status = 'active' AND user_id <> ?
   ORDER BY (role IN ('admin','research_staff')) DESC, last_name, first_name"
);
if ($r_stmt) {
    $r_stmt->bind_param('i', $user_id);
    $r_stmt->execute();
    $result = $r_stmt->get_result();
    $recipients = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $r_stmt->close();
}

// ---------------------------------------------------------------
// Pre-fill values for the compose form (sticky on validation failure
// and pre-filled when replying to a message).
// ---------------------------------------------------------------
$compose_recipient_id = (int) ($_GET['reply_to'] ?? 0);
// ?to={user_id} is a friendly alias for ?reply_to={user_id} — used by
// cross-role "Message" links (e.g. faculty-students.php) that just want
// to pre-select a recipient without a message-id context.
if ($compose_recipient_id <= 0) {
    $compose_recipient_id = (int) ($_GET['to'] ?? 0);
}
$compose_subject      = trim((string) ($_GET['subject'] ?? ''));
$compose_body         = '';

// If replying, derive the recipient from the message and pre-fill subject.
if ($compose_recipient_id <= 0 && $is_detail) {
    // (resolved later when we load $detail)
}
$compose_subject_default = '';
if ($compose_subject === '' && $is_detail) {
    // (set below after $detail is loaded)
}

// ---------------------------------------------------------------
// Detail view — load a single message and verify the user is a party.
// ---------------------------------------------------------------
$detail = null;
if ($is_detail) {
    $d_stmt = $conn->prepare(
        'SELECT m.message_id, m.sender_id, m.recipient_id, m.subject, m.message,
                m.is_read, m.created_at,
                s.first_name AS s_first, s.last_name AS s_last, s.role AS s_role, s.status AS s_status,
                r.first_name AS r_first, r.last_name AS r_last, r.role AS r_role, r.status AS r_status
           FROM messages m
           JOIN users s ON s.user_id = m.sender_id
           JOIN users r ON r.user_id = m.recipient_id
          WHERE m.message_id = ?
            AND (m.sender_id = ? OR m.recipient_id = ?)
          LIMIT 1'
    );
    if ($d_stmt) {
        $d_stmt->bind_param('iii', $detail_id, $user_id, $user_id);
        $d_stmt->execute();
        $detail = $d_stmt->get_result()->fetch_assoc() ?: null;
        $d_stmt->close();
    }

    if (!$detail) {
        $_SESSION['module_error'] = 'Message not found, or you do not have permission to view it.';
        header('Location: ' . SITE_URL . 'pages/shared/messages.php');
        exit;
    }

    // If current user is the recipient and the message is unread, mark it read.
    if ((int) $detail['recipient_id'] === $user_id && (int) $detail['is_read'] === 0) {
        $u = $conn->prepare('UPDATE messages SET is_read = 1 WHERE message_id = ? AND recipient_id = ?');
        if ($u) {
            $u->bind_param('ii', $detail_id, $user_id);
            if ($u->execute() && $u->affected_rows > 0) {
                $detail['is_read'] = 1; // reflect change in the rendered view
                logActivity('Read message', 'messages');
            }
            $u->close();
        }
    }

    // Pre-fill reply form values.
    $other_user_id = ((int) $detail['sender_id'] === $user_id)
        ? (int) $detail['recipient_id']
        : (int) $detail['sender_id'];
    $compose_recipient_id = $other_user_id;
    if (stripos((string) $detail['subject'], 'Re:') !== 0) {
        $compose_subject = 'Re: ' . (string) $detail['subject'];
    } else {
        $compose_subject = (string) $detail['subject'];
    }
}

// ---------------------------------------------------------------
// List views — inbox or sent, newest first, LIMIT $MSG_LIMIT.
// (Skipped if we are rendering the detail view.)
// ---------------------------------------------------------------
$rows = [];
$unread_count = 0;
if (!$is_detail) {
    if ($current_view === 'sent') {
        $s_stmt = $conn->prepare(
            "SELECT m.message_id, m.subject, m.message, m.is_read, m.created_at,
                    m.recipient_id,
                    CONCAT(r.first_name, ' ', r.last_name) AS other_name,
                    r.role AS other_role
               FROM messages m
               JOIN users r ON r.user_id = m.recipient_id
              WHERE m.sender_id = ?
           ORDER BY m.created_at DESC
              LIMIT " . $MSG_LIMIT
        );
        if ($s_stmt) {
            $s_stmt->bind_param('i', $user_id);
            $s_stmt->execute();
            $result = $s_stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $s_stmt->close();
        }
    } else {
        $i_stmt = $conn->prepare(
            "SELECT m.message_id, m.subject, m.message, m.is_read, m.created_at,
                    m.sender_id,
                    CONCAT(s.first_name, ' ', s.last_name) AS other_name,
                    s.role AS other_role
               FROM messages m
               JOIN users s ON s.user_id = m.sender_id
              WHERE m.recipient_id = ?
           ORDER BY m.created_at DESC
              LIMIT " . $MSG_LIMIT
        );
        if ($i_stmt) {
            $i_stmt->bind_param('i', $user_id);
            $i_stmt->execute();
            $result = $i_stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $i_stmt->close();
        }
        foreach ($rows as $r) {
            if ((int) $r['is_read'] === 0) $unread_count++;
        }
    }
}

$visible_count   = count($rows);
$recipient_count = count($recipients);

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors profile.php and notifications.php.
// ---------------------------------------------------------------
$page_title    = 'Messages';
$page_subtitle = $is_detail
    ? 'Read and respond to private research correspondence.'
    : ($current_view === 'sent'
        ? 'Review correspondence you have sent.'
        : ($unread_count > 0
            ? "You have {$unread_count} unread message" . ($unread_count === 1 ? '' : 's') . '.'
            : 'Private correspondence across your research workspace.'));

if ($role === 'admin') {
    renderAdminShell($user, 'messages.php', $page_title, $page_subtitle);
} elseif ($role === 'research_staff') {
    renderStaffShell($user, 'messages.php', $page_title, $page_subtitle);
} elseif ($role === 'faculty') {
    renderFacultyShell($user, 'messages.php', $page_title, $page_subtitle);
} else {
    renderStudentShell($user, 'messages.php', $page_title, $page_subtitle);
}

msg_flash('success');
msg_flash('error');

// Tab URLs.
$tab_inbox_url = SITE_URL . 'pages/shared/messages.php';
$tab_sent_url  = SITE_URL . 'pages/shared/messages.php?view=sent';
$compose_url   = SITE_URL . 'pages/shared/messages.php#compose';
$msgTheme = match ($role) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'highlight' => '#FED7AA', 'rgb' => '245,124,0'],
    'research_staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'highlight' => '#99F6E4', 'rgb' => '13,148,136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'highlight' => '#BFDBFE', 'rgb' => '29,78,216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'highlight' => '#DDD6FE', 'rgb' => '91,30,188'],
};
?>

<style>
  html { scroll-behavior: smooth; }
  .messages-workspace { --msg-ink:#192235; --msg-gold:<?= msg_se($msgTheme['accent']) ?>; --msg-accent:<?= msg_se($msgTheme['accent']) ?>; --msg-deep:<?= msg_se($msgTheme['deep']) ?>; --msg-tint:<?= msg_se($msgTheme['tint']) ?>; --msg-highlight:<?= msg_se($msgTheme['highlight']) ?>; --msg-rgb:<?= msg_se($msgTheme['rgb']) ?>; --msg-line:#dfe5ed; --msg-muted:#687386; max-width:1480px; margin:0 auto; color:var(--msg-ink); }
  .msg-flash { display:flex; align-items:center; gap:10px; margin:0 auto 18px; max-width:1480px; padding:13px 16px; border:1px solid #d9e6de; border-radius:10px; background:#f3faf6; color:#245d40; font-size:13px; font-weight:600; }
  .msg-flash.is-error { border-color:#edd8d2; background:#fff6f3; color:#93432f; }
  .msg-flash span { display:grid; place-items:center; width:24px; height:24px; border-radius:6px; background:rgba(255,255,255,.7); font-weight:800; }

  .msg-hero { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.2fr) minmax(290px,.8fr); gap:44px; min-height:300px; padding:48px 52px 56px; overflow:hidden; border-radius:24px 24px 8px 8px; background:radial-gradient(circle at 84% 15%,rgba(255,255,255,.2),transparent 29%),linear-gradient(135deg,var(--msg-deep),var(--msg-accent)); color:#fff; box-shadow:0 24px 58px rgba(var(--msg-rgb),.14); }
  .msg-hero::after { content:''; position:absolute; inset:0; z-index:-1; opacity:.16; background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); pointer-events:none; }
  .msg-kicker,.msg-panel-eyebrow,.msg-stat-code { font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.15em; text-transform:uppercase; }
  .msg-kicker { margin-bottom:17px; color:var(--msg-highlight); }
  .msg-hero h2 { max-width:760px; margin:0; color:#fff; font-size:clamp(38px,4.2vw,62px); line-height:1; letter-spacing:-.052em; text-wrap:balance; }
  .msg-hero-copy>p { max-width:610px; margin:22px 0 0; color:#bdc8d8; font-size:15px; line-height:1.72; text-wrap:pretty; }
  .msg-hero-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:26px; }
  .msg-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:10px 16px; border:1px solid transparent; border-radius:8px; background:none; color:inherit; font-family:inherit; font-size:13px; font-weight:650; line-height:1.2; text-decoration:none; cursor:pointer; transition:transform .2s ease,background .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease; }
  .msg-btn:hover { transform:translateY(-1px); }.msg-btn:active{transform:translateY(0) scale(.98)}.msg-btn:focus-visible,.msg-tab:focus-visible,.msg-item:focus-visible{outline:3px solid rgba(var(--msg-rgb),.28);outline-offset:2px}
  .msg-btn-primary { border-color:var(--msg-accent); background:var(--msg-accent); color:#fff; box-shadow:0 8px 20px rgba(var(--msg-rgb),.18); }.msg-btn-primary:hover{filter:brightness(1.08)}
  .msg-btn-secondary { border-color:#d8dfe8; background:#fff; color:#283246; }.msg-btn-secondary:hover{border-color:#adb8c7;background:#f8f9fb}
  .msg-btn-hero { border-color:rgba(255,255,255,.19); background:rgba(255,255,255,.06); color:#fff; }.msg-btn-hero:hover{border-color:var(--msg-highlight);background:rgba(255,255,255,.1)}
  .msg-stat-stack { align-self:end; display:grid; gap:2px; }
  .msg-stat { display:grid; grid-template-columns:38px 1fr auto; align-items:center; gap:12px; padding:14px 16px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.06); }.msg-stat:first-child{border-radius:14px 14px 5px 5px}.msg-stat:last-child{border-radius:5px 5px 14px 14px}
  .msg-stat-code{color:var(--msg-highlight)}.msg-stat-label{color:#d4dce8;font-size:13px}.msg-stat-value{font-size:24px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums}

  .msg-commandbar { position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between; gap:18px; margin:-18px 22px 0; padding:12px 14px; border:1px solid var(--msg-line); border-radius:13px; background:#f8fafc; box-shadow:0 12px 30px rgba(25,34,53,.08); }
  .msg-tabs { display:inline-flex; gap:3px; padding:3px; border:1px solid #e0e5ec; border-radius:9px; background:#e9edf2; }
  .msg-tab { display:inline-flex; align-items:center; gap:8px; min-height:37px; padding:9px 15px; border-radius:6px; color:#657083; font-size:12px; font-weight:680; text-decoration:none; transition:background .2s ease,color .2s ease,box-shadow .2s ease; }.msg-tab:hover{color:#182033}.msg-tab.is-active{background:#fff;color:#182033;box-shadow:0 1px 4px rgba(24,32,51,.1)}
  .msg-tab-badge { display:inline-grid; place-items:center; min-width:19px; height:19px; padding:0 5px; border-radius:5px; background:var(--msg-accent); color:#fff; font:750 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }

  .messages-layout { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(320px,.68fr); gap:24px; margin-top:34px; align-items:start; }
  .msg-panel { overflow:hidden; border:1px solid var(--msg-line); border-radius:18px; background:#fff; box-shadow:0 14px 38px rgba(31,42,63,.065); }
  .msg-panel-header { display:flex; align-items:end; justify-content:space-between; gap:18px; padding:26px 28px 22px; border-bottom:1px solid #e8ecf1; }.msg-panel-eyebrow{margin-bottom:8px;color:var(--msg-accent)}.msg-panel-title{margin:0;color:#1c2639;font-size:24px;line-height:1.1;letter-spacing:-.03em}.msg-panel-copy{max-width:610px;margin:8px 0 0;color:var(--msg-muted);font-size:13px;line-height:1.55}.msg-panel-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}
  .msg-list { display:flex; flex-direction:column; }
  .msg-item { position:relative; display:grid; grid-template-columns:42px minmax(0,1fr) auto; gap:15px; align-items:start; padding:20px 27px; border-bottom:1px solid #edf0f4; background:#fff; color:inherit; text-decoration:none; transition:background .2s ease,transform .2s ease; }.msg-item:last-child{border-bottom:0}.msg-item:hover{z-index:1;background:#fbfaf7;transform:translateX(3px)}.msg-item.is-unread{background:#fcfaf4}.msg-item.is-unread:hover{background:#faf6eb}
  .msg-avatar { position:relative; display:grid; place-items:center; width:40px; height:40px; border-radius:10px; background:#e9edf2; color:#465266; font-size:12px; font-weight:750; letter-spacing:.03em; }.msg-item.is-unread .msg-avatar{background:var(--msg-tint);color:var(--msg-accent)}.msg-dot{position:absolute;right:-3px;top:-3px;width:9px;height:9px;border:2px solid #fff;border-radius:50%;background:var(--msg-accent)}.msg-item.is-read .msg-dot{display:none}
  .msg-body{min-width:0}.msg-line1{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:4px}.msg-from{color:#202a3c;font-size:14px;font-weight:680}.msg-item.is-read .msg-from{font-weight:570}.msg-subject{margin:3px 0 5px;color:#263147;font-size:14px;font-weight:680;line-height:1.4;overflow-wrap:anywhere}.msg-item.is-read .msg-subject{color:#475267;font-weight:580}.msg-snippet{margin:0;max-width:760px;color:#707b8d;font-size:13px;line-height:1.52;overflow-wrap:anywhere}.msg-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#929cac;font-size:11px}.msg-list .msg-meta{margin-top:8px}.msg-time{font-weight:650}.msg-right{padding-top:2px;color:#929cac;font-size:11px;text-align:right}
  .msg-role-badge { display:inline-flex; align-items:center; padding:4px 7px; border:1px solid #dce2e9; border-radius:5px; background:#f4f6f8; color:#586477; font-size:9px; font-weight:720; letter-spacing:.055em; text-transform:uppercase; }.msg-role-badge.role-admin{border-color:#eadaba;background:#faf4e8;color:#865f24}.msg-role-badge.role-research-staff{border-color:#d2e7e2;background:#eff8f6;color:#27695f}.msg-role-badge.role-faculty{border-color:#d7e3ef;background:#f0f5fa;color:#315b8c}.msg-role-badge.role-student{border-color:#e3d9e9;background:#f7f2f8;color:#72528c}
  .msg-empty{padding:68px 28px;text-align:center}.msg-empty-icon{display:grid;place-items:center;width:50px;height:50px;margin:0 auto 15px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#886326;font:750 13px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.msg-empty-title{color:#2b3548;font-size:17px;font-weight:680}.msg-empty-subtitle{max-width:470px;margin:7px auto 0;color:#7b8697;font-size:13px;line-height:1.55}.msg-truncated-note{padding:14px 24px;border-top:1px solid #edf0f4;background:#fafbfc;color:#8791a1;font-size:11px;text-align:center}

  .msg-inbox-panel{grid-column:1;grid-row:1}.msg-compose-panel { position:sticky; top:92px; grid-column:2; grid-row:1; }.msg-compose-panel .msg-panel-header{padding-bottom:20px}.msg-compose-body{padding:24px 26px 28px}.msg-compose-grid{display:grid;gap:15px}.msg-compose-grid label{display:block;margin:0 0 -8px;color:#000;font-size:12px;font-weight:700}.msg-compose-grid .form-control{width:100%;min-height:44px;padding:10px 13px;border:1px solid #d9e0e8;border-radius:8px;background:#fff;color:#000!important;font-family:inherit;font-size:13px;font-weight:500;line-height:1.45;transition:border-color .2s ease,box-shadow .2s ease}.msg-compose-grid textarea.form-control{min-height:154px;resize:vertical}.msg-compose-grid .form-control:focus{outline:0;border-color:var(--msg-accent);box-shadow:0 0 0 3px rgba(var(--msg-rgb),.12)}.msg-compose-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:19px}.msg-compose-note{margin:13px 0 0;color:#818b9b;font-size:11px;line-height:1.5}

  .msg-detail-shell{max-width:1120px;margin:34px auto 0}.msg-detail-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}.msg-detail-back{display:inline-flex;align-items:center;gap:7px;color:#455166;font-size:12px;font-weight:680;text-decoration:none}.msg-detail-back:hover{color:#8b6425}.msg-detail-card{overflow:hidden;border:1px solid var(--msg-line);border-radius:18px;background:#fff;box-shadow:0 16px 42px rgba(31,42,63,.075)}.msg-detail-heading{padding:30px 34px 26px;border-bottom:1px solid #e8ecf1;background:#fbfcfd}.msg-detail-subject{max-width:840px;margin:0;color:#192235;font-size:clamp(27px,3vw,40px);font-weight:720;line-height:1.12;letter-spacing:-.04em;text-wrap:balance;overflow-wrap:anywhere}.msg-detail-meta{display:flex;align-items:center;gap:14px;margin-top:23px}.msg-detail-avatar{display:grid;place-items:center;width:46px;height:46px;border-radius:11px;background:#202c43;color:#e9bf6e;font-size:13px;font-weight:760;letter-spacing:.04em}.msg-detail-person{display:flex;flex-direction:column;gap:5px}.msg-detail-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#263147;font-size:14px;font-weight:680}.msg-detail-line{color:#748093;font-size:12px}.msg-detail-line strong{color:#4d596d}.msg-detail-body{min-height:190px;padding:35px 34px 43px;color:#273246;font-size:15px;line-height:1.78;white-space:pre-wrap;overflow-wrap:anywhere}.msg-detail-actions{display:flex;gap:9px;flex-wrap:wrap;padding:18px 34px 24px;border-top:1px solid #edf0f4;background:#fafbfc}.msg-reply-panel{margin-top:22px}
  .messages-workspace .msg-item.is-unread{background:var(--msg-tint)}.messages-workspace .msg-item.is-unread:hover{filter:brightness(.99)}
  .messages-workspace .msg-empty-icon{border-color:rgba(var(--msg-rgb),.2);background:var(--msg-tint);color:var(--msg-accent)}
  .messages-workspace .msg-detail-back:hover{color:var(--msg-accent)}
  .messages-workspace .msg-detail-avatar{background:var(--msg-deep);color:var(--msg-highlight)}

  @media(max-width:1050px){.msg-hero{grid-template-columns:1fr;gap:30px}.msg-stat-stack{grid-template-columns:repeat(3,1fr)}.msg-stat{grid-template-columns:32px 1fr}.msg-stat-value{grid-column:2}.messages-layout{grid-template-columns:1fr}.msg-inbox-panel,.msg-compose-panel{grid-column:1;grid-row:auto}.msg-inbox-panel{order:1}.msg-compose-panel{position:static;order:2}}
  @media(max-width:700px){.msg-hero{min-height:0;padding:30px 24px 47px;border-radius:18px 18px 7px 7px}.msg-hero h2{font-size:39px}.msg-stat-stack{grid-template-columns:1fr}.msg-stat{grid-template-columns:34px 1fr auto}.msg-stat-value{grid-column:auto}.msg-commandbar{align-items:stretch;flex-direction:column;margin:-17px 12px 0}.msg-tabs{width:100%}.msg-tab{flex:1;justify-content:center}.msg-commandbar>.msg-btn{width:100%}.messages-layout{margin-top:24px}.msg-panel-header{align-items:flex-start;flex-direction:column;padding:23px 20px 19px}.msg-panel-count{white-space:normal}.msg-item{grid-template-columns:40px minmax(0,1fr);padding:17px 19px}.msg-right{grid-column:2;text-align:left}.msg-compose-body{padding:21px 20px 24px}.msg-detail-shell{margin-top:24px}.msg-detail-heading,.msg-detail-body,.msg-detail-actions{padding-left:22px;padding-right:22px}.msg-detail-subject{font-size:28px}.msg-detail-header .msg-meta{width:100%}}
  @media(prefers-reduced-motion:reduce){.msg-btn,.msg-tab,.msg-item{transition:none}.msg-item:hover{transform:none}}
</style>

<div class="messages-workspace">
  <section class="msg-hero" aria-labelledby="messages-hero-title">
    <div class="msg-hero-copy">
      <div class="msg-kicker">RMS correspondence &middot; Private channel</div>
      <h2 id="messages-hero-title"><?php echo $is_detail ? 'Read the full context.' : 'Keep research decisions moving.'; ?></h2>
      <p><?php echo $is_detail
          ? 'Review the complete message, then respond without losing the thread or the people involved.'
          : 'Coordinate with proponents, advisers, reviewers, and offices through one focused institute inbox.'; ?></p>
      <div class="msg-hero-actions">
        <?php if ($is_detail && $detail): ?>
          <a class="msg-btn msg-btn-hero" href="<?php echo msg_se(((int) $detail['recipient_id'] === $user_id) ? $tab_inbox_url : $tab_sent_url); ?>">Return to messages</a>
          <a class="msg-btn msg-btn-primary" href="#compose">Write a reply <span aria-hidden="true">&#8594;</span></a>
        <?php else: ?>
          <a class="msg-btn msg-btn-primary" href="#compose">Compose a message <span aria-hidden="true">&#8594;</span></a>
          <a class="msg-btn msg-btn-hero" href="<?php echo msg_se($current_view === 'sent' ? $tab_inbox_url : $tab_sent_url); ?>"><?php echo $current_view === 'sent' ? 'Open inbox' : 'Review sent mail'; ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="msg-stat-stack" aria-label="Message activity">
      <div class="msg-stat"><span class="msg-stat-code">01</span><span class="msg-stat-label">Unread now</span><strong class="msg-stat-value"><?php echo (int) $unread_count; ?></strong></div>
      <div class="msg-stat"><span class="msg-stat-code">02</span><span class="msg-stat-label"><?php echo $is_detail ? 'Open message' : 'In this view'; ?></span><strong class="msg-stat-value"><?php echo $is_detail ? '1' : (int) $visible_count; ?></strong></div>
      <div class="msg-stat"><span class="msg-stat-code">03</span><span class="msg-stat-label">Available contacts</span><strong class="msg-stat-value"><?php echo (int) $recipient_count; ?></strong></div>
    </div>
  </section>

  <?php if (!$is_detail): ?>
    <nav class="msg-commandbar" aria-label="Message views">
      <div class="msg-tabs" role="tablist">
        <a class="msg-tab <?php echo $current_view === 'inbox' ? 'is-active' : ''; ?>"
           href="<?php echo msg_se($tab_inbox_url); ?>" role="tab" aria-selected="<?php echo $current_view === 'inbox' ? 'true' : 'false'; ?>">
          Inbox
          <?php if ($unread_count > 0): ?><span class="msg-tab-badge"><?php echo (int) $unread_count; ?></span><?php endif; ?>
        </a>
        <a class="msg-tab <?php echo $current_view === 'sent' ? 'is-active' : ''; ?>"
           href="<?php echo msg_se($tab_sent_url); ?>" role="tab" aria-selected="<?php echo $current_view === 'sent' ? 'true' : 'false'; ?>">Sent</a>
      </div>
      <a class="msg-btn msg-btn-secondary" href="#compose">New message</a>
    </nav>
  <?php endif; ?>

<?php if ($is_detail && $detail): ?>
  <?php
    $is_inbox_message = ((int) $detail['recipient_id'] === $user_id);
    $other_first = $is_inbox_message ? (string) $detail['s_first'] : (string) $detail['r_first'];
    $other_last  = $is_inbox_message ? (string) $detail['s_last']  : (string) $detail['r_last'];
    $other_role  = $is_inbox_message ? (string) $detail['s_role']  : (string) $detail['r_role'];
    $other_name  = trim($other_first . ' ' . $other_last);
    $initials    = mb_strtoupper(mb_substr($other_first, 0, 1) . mb_substr($other_last, 0, 1));
    $created_abs = date('M d, Y \a\t h:i A', strtotime((string) $detail['created_at']));
    $created_rel = msg_relative_time((string) $detail['created_at']);
    $back_url    = $is_inbox_message ? $tab_inbox_url : $tab_sent_url;
  ?>
  <div class="msg-detail-shell">
  <div class="msg-detail-header">
    <a class="msg-detail-back" href="<?php echo msg_se($back_url); ?>">&larr; Back to <?php echo $is_inbox_message ? 'inbox' : 'sent'; ?></a>
    <span class="msg-meta">
      <span title="<?php echo msg_se($created_abs); ?>"><?php echo msg_se($created_rel); ?></span>
      &middot; <?php echo msg_se($created_abs); ?>
    </span>
  </div>

  <article class="msg-detail-card">
    <div class="msg-detail-heading">
      <h1 class="msg-detail-subject"><?php echo msg_se($detail['subject']); ?></h1>

      <div class="msg-detail-meta">
        <div class="msg-detail-avatar"><?php echo msg_se($initials !== '' ? $initials : '?'); ?></div>
        <div class="msg-detail-person">
          <div class="msg-detail-name">
            <?php echo msg_se($other_name); ?>
            <?php echo msg_role_badge($other_role); ?>
          </div>
          <div class="msg-detail-line">
            <?php if ($is_inbox_message): ?>
              <strong>From</strong> <?php echo msg_se($other_name); ?>
            <?php else: ?>
              <strong>To</strong> <?php echo msg_se($other_name); ?>
            <?php endif; ?>
            &middot; <?php echo msg_se($created_abs); ?>
          </div>
        </div>
      </div>
    </div>

    <div class="msg-detail-body"><?php echo msg_se($detail['message']); ?></div>

    <div class="msg-detail-actions">
      <a class="msg-btn msg-btn-primary" href="<?php echo msg_se($compose_url); ?>">Reply</a>
      <a class="msg-btn msg-btn-secondary" href="<?php echo msg_se($back_url); ?>">Back</a>
    </div>
  </article>

  <?php if (in_array($other_role, ['admin', 'research_staff', 'faculty', 'student'], true)): ?>
    <section class="msg-panel msg-reply-panel" id="compose">
      <div class="msg-panel-header">
        <div>
          <div class="msg-panel-eyebrow">Continue the thread</div>
          <h3 class="msg-panel-title">Reply to <?php echo msg_se($other_name); ?></h3>
          <p class="msg-panel-copy">Your response will appear in their RMS inbox and notification feed.</p>
        </div>
      </div>
      <div class="msg-compose-body">
        <form method="post" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php'); ?>">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_message">
          <input type="hidden" name="recipient_id" value="<?php echo (int) $compose_recipient_id; ?>">
          <div class="msg-compose-grid">
            <label for="compose-recipient">To</label>
            <input class="form-control" id="compose-recipient" type="text" disabled
                   value="<?php echo msg_se($other_name . ' — ' . msg_role_meta($other_role)[0]); ?>">

            <label for="compose-subject">Subject</label>
            <input class="form-control" id="compose-subject" type="text" name="subject" maxlength="160" required
                   value="<?php echo msg_se($compose_subject); ?>">

            <label for="compose-message">Message</label>
            <textarea class="form-control" id="compose-message" name="message" rows="6" required
                      placeholder="Type your reply…"><?php echo msg_se($compose_body); ?></textarea>
          </div>
          <div class="msg-compose-actions">
            <button type="submit" class="msg-btn msg-btn-primary">Send reply</button>
            <a class="msg-btn msg-btn-secondary" href="<?php echo msg_se($back_url); ?>">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  </div>

<?php else: ?>

  <div class="messages-layout">

  <!-- Compose form -->
  <section class="msg-panel msg-compose-panel" id="compose">
    <div class="msg-panel-header">
      <div>
        <div class="msg-panel-eyebrow">Direct correspondence</div>
        <h3 class="msg-panel-title">Compose a message</h3>
        <p class="msg-panel-copy">Write privately to any active RMS user.</p>
      </div>
    </div>
    <div class="msg-compose-body">
      <?php if (!$recipients): ?>
        <p class="msg-panel-copy">No other active users are available to receive messages.</p>
      <?php else: ?>
        <form method="post" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php'); ?>">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_message">
          <div class="msg-compose-grid">
            <label for="new-recipient">Recipient</label>
            <select class="form-control" id="new-recipient" name="recipient_id" required>
              <option value="">Select recipient…</option>
              <?php
                // In compose form, the sticky value is only meaningful on a
                // validation-failure redirect; the only POST that triggers this
                // is 'send_message', so we don't try to read it back here.
                $selected_id = (int) $compose_recipient_id;
                foreach ($recipients as $r):
                  $rid  = (int) $r['user_id'];
                  $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                  $rrole= (string) ($r['role'] ?? '');
                  [$role_label] = msg_role_meta($rrole);
              ?>
                <option value="<?php echo $rid; ?>" <?php echo $rid === $selected_id ? 'selected' : ''; ?>>
                  <?php echo msg_se($name . ' — ' . $role_label); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label for="new-subject">Subject</label>
            <input class="form-control" id="new-subject" type="text" name="subject" maxlength="160" required
                   value="<?php echo msg_se($compose_subject); ?>"
                   placeholder="e.g. Question about your proposal">

            <label for="new-message">Message</label>
            <textarea class="form-control" id="new-message" name="message" rows="6" required
                      placeholder="Write your message…"><?php echo msg_se($compose_body); ?></textarea>
          </div>
          <div class="msg-compose-actions">
            <button type="submit" class="msg-btn msg-btn-primary">Send message</button>
            <button type="reset" class="msg-btn msg-btn-secondary">Clear</button>
          </div>
          <p class="msg-compose-note">The recipient will also receive an RMS notification.</p>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- List view (Inbox or Sent) -->
  <section class="msg-panel msg-inbox-panel">
    <div class="msg-panel-header">
      <div>
        <div class="msg-panel-eyebrow"><?php echo $current_view === 'sent' ? 'Dispatch record' : 'Incoming correspondence'; ?></div>
        <h3 class="msg-panel-title"><?php echo $current_view === 'sent' ? 'Sent messages' : 'Inbox'; ?></h3>
        <p class="msg-panel-copy">
          <?php if ($current_view === 'sent'): ?>
            Messages you have sent to other RMS users.
          <?php else: ?>
            Newest first &middot; unread correspondence is highlighted.
          <?php endif; ?>
        </p>
      </div>
      <span class="msg-panel-count"><?php echo (int) $visible_count; ?> SHOWN</span>
    </div>
    <div>
      <?php if (!$rows): ?>
        <div class="msg-empty">
          <div class="msg-empty-icon"><?php echo $current_view === 'sent' ? 'OUT' : 'IN'; ?></div>
          <div class="msg-empty-title">
            <?php echo $current_view === 'sent' ? 'No sent messages yet' : 'Your inbox is empty'; ?>
          </div>
          <div class="msg-empty-subtitle">
            <?php echo $current_view === 'sent'
                ? 'Messages you send will appear here.'
                : 'New messages from faculty, staff, and administrators will show up here.'; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="msg-list">
          <?php foreach ($rows as $row):
              $mid       = (int) $row['message_id'];
              $is_unread = ($current_view === 'inbox' && (int) $row['is_read'] === 0);
              $other_name= (string) ($row['other_name'] ?? '');
              $other_role= (string) ($row['other_role'] ?? '');
              $subject   = (string) ($row['subject'] ?? '');
              $snippet   = msg_snippet($row['message'] ?? '');
              $rel       = msg_relative_time($row['created_at'] ?? '');
              $abs       = $row['created_at'] ? date('M d, Y', strtotime((string) $row['created_at'])) : '';
              $detail_url= SITE_URL . 'pages/shared/messages.php?id=' . $mid;
              $name_parts = preg_split('/\s+/', trim($other_name)) ?: [];
              $initials = $name_parts
                  ? mb_strtoupper(mb_substr($name_parts[0], 0, 1) . (count($name_parts) > 1 ? mb_substr($name_parts[count($name_parts) - 1], 0, 1) : ''))
                  : '?';
          ?>
            <a class="msg-item <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>"
               href="<?php echo msg_se($detail_url); ?>">
              <div class="msg-avatar" aria-hidden="true"><?php echo msg_se($initials); ?><span class="msg-dot"></span></div>
              <div class="msg-body">
                <div class="msg-line1">
                  <span class="msg-from"><?php echo msg_se($other_name); ?></span>
                  <?php echo msg_role_badge($other_role); ?>
                </div>
                <div class="msg-subject"><?php echo msg_se($subject); ?></div>
                <div class="msg-snippet"><?php echo msg_se($snippet); ?></div>
                <div class="msg-meta">
                  <span class="msg-time" title="<?php echo msg_se($abs); ?>"><?php echo msg_se($rel); ?></span>
                  <?php if ($abs !== ''): ?>
                    <span>&middot; <?php echo msg_se($abs); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="msg-right">
                <?php if ($is_unread): ?>
                  <span class="msg-tab-badge">New</span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (count($rows) >= $MSG_LIMIT): ?>
          <div class="msg-truncated-note">
            Showing the <?php echo (int) $MSG_LIMIT; ?> most recent <?php echo $current_view === 'sent' ? 'sent messages' : 'received messages'; ?>. Older messages are hidden.
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  </div>

<?php endif; ?>

</div>

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
