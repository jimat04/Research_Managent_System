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
        'admin'          => ['🛡️ Administrator',     '#F57C00'],
        'research_staff' => ['📋 Research Staff',    '#0d9488'],
        'faculty'        => ['🎓 Faculty Adviser',   '#1d4ed8'],
        'student'        => ['🎒 Student',           '#5B1EBC'],
    ];
    return $map[$r] ?? [ucfirst(str_replace('_', ' ', (string) $r)), '#64748B'];
}

/** Render a small role badge (re-uses the same pill pattern as notif_type_badge). */
function msg_role_badge($r) {
    [$label, $color] = msg_role_meta($r);
    $hex = ltrim($color, '#');
    $r_hex = hexdec(substr($hex, 0, 2));
    $g_hex = hexdec(substr($hex, 2, 2));
    $b_hex = hexdec(substr($hex, 4, 2));
    $bg    = "rgba($r_hex, $g_hex, $b_hex, 0.10)";
    $border= "rgba($r_hex, $g_hex, $b_hex, 0.25)";
    return '<span style="display:inline-block;font-size:11px;font-weight:500;'
         . 'padding:2px 8px;border-radius:9999px;'
         . 'background:' . $bg . ';color:' . $color . ';'
         . 'border:1px solid ' . $border . ';">' . msg_se($label) . '</span>';
}

/** Flash message helper. */
function msg_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $color = $type === 'error' ? '#ef4444' : '#22c55e';
        echo '<div style="margin-bottom:20px;padding:14px 18px;border-left:4px solid ' . $color .
             ';background:#fff;color:#334155;border-radius:10px;">' . msg_se($message) . '</div>';
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

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors profile.php and notifications.php.
// ---------------------------------------------------------------
$page_title    = 'Messages';
$page_subtitle = $is_detail
    ? 'Message detail'
    : ($current_view === 'sent'
        ? 'Messages you have sent.'
        : ($unread_count > 0
            ? "You have {$unread_count} unread message" . ($unread_count === 1 ? '' : 's') . '.'
            : 'Your inbox is empty.'));

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
?>

<style>
  .msg-tabs {
    display: inline-flex;
    background: #F1F5F9;
    border-radius: 12px;
    padding: 4px;
    gap: 4px;
    margin-bottom: 18px;
  }
  .msg-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: #64748B;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
  }
  .msg-tab:hover { color: #111827; }
  .msg-tab.is-active {
    background: #FFFFFF;
    color: #111827;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
  }
  .msg-tab-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 9999px;
    background: #5B1EBC;
    color: #fff;
    min-width: 18px;
    text-align: center;
  }

  .msg-list { display: flex; flex-direction: column; gap: 10px; }

  .msg-item {
    display: grid;
    grid-template-columns: 14px 1fr auto;
    gap: 14px;
    align-items: start;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: border-color 0.2s, box-shadow 0.2s;
    text-decoration: none;
    color: inherit;
  }
  .msg-item:hover {
    border-color: rgba(91,30,188,0.25);
    box-shadow: 0 1px 6px rgba(91,30,188,0.08);
  }
  .msg-item.is-unread {
    background: rgba(91,30,188,0.03);
    border-color: rgba(91,30,188,0.18);
  }
  .msg-dot {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: #5B1EBC;
    margin-top: 7px;
  }
  .msg-item.is-read .msg-dot { background: transparent; }

  .msg-body { min-width: 0; }
  .msg-line1 {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }
  .msg-from {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
  }
  .msg-item.is-read .msg-from { font-weight: 500; }
  .msg-subject {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin: 2px 0 4px;
    word-wrap: break-word;
  }
  .msg-item.is-read .msg-subject { font-weight: 500; color: #334155; }
  .msg-snippet {
    font-size: 13px;
    color: #64748B;
    line-height: 1.45;
    margin: 0 0 6px;
    word-wrap: break-word;
  }
  .msg-meta {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 12px;
    color: #94A3B8;
  }
  .msg-time { font-weight: 500; }

  .msg-right {
    text-align: right;
    font-size: 12px;
    color: #94A3B8;
  }

  .msg-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .msg-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .msg-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
  }
  .msg-empty-subtitle { font-size: 14px; color: #64748B; }

  .msg-truncated-note {
    margin-top: 14px;
    text-align: center;
    font-size: 13px;
    color: #94A3B8;
  }

  /* Detail view */
  .msg-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
  }
  .msg-detail-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #1d4ed8;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
  }
  .msg-detail-back:hover { text-decoration: underline; }

  .msg-detail-meta {
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
    padding: 14px 0;
    border-bottom: 1px solid #E5E7EB;
    margin-bottom: 16px;
  }
  .msg-detail-avatar {
    width: 44px;
    height: 44px;
    border-radius: 9999px;
    background: linear-gradient(135deg, #5B1EBC 0%, #1d4ed8 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
  }
  .msg-detail-person { display: flex; flex-direction: column; gap: 2px; }
  .msg-detail-name {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
  }
  .msg-detail-line {
    font-size: 13px;
    color: #64748B;
  }
  .msg-detail-line strong { color: #475569; }

  .msg-detail-subject {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
    margin: 0 0 8px;
    word-wrap: break-word;
  }

  .msg-detail-body {
    font-size: 15px;
    line-height: 1.6;
    color: #1f2937;
    white-space: pre-wrap;
    word-wrap: break-word;
    padding: 16px 0 8px;
  }

  .msg-detail-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    flex-wrap: wrap;
  }

  /* Compose form */
  .msg-compose-grid {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 12px 16px;
    align-items: start;
  }
  .msg-compose-grid label {
    font-size: 13px;
    font-weight: 500;
    color: #111827;
    padding-top: 10px;
  }
  .msg-compose-grid .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #fff;
    color: #111827;
    transition: border-color 0.2s, box-shadow 0.2s;
    font-family: inherit;
  }
  .msg-compose-grid textarea.form-control {
    min-height: 140px;
    resize: vertical;
  }
  .msg-compose-grid .form-control:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.15);
  }

  @media (max-width: 640px) {
    .msg-item { grid-template-columns: 12px 1fr; }
    .msg-right { grid-column: 1 / -1; text-align: left; }
    .msg-compose-grid { grid-template-columns: 1fr; }
    .msg-compose-grid label { padding-top: 0; }
  }
</style>

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
  <div class="msg-detail-header">
    <a class="msg-detail-back" href="<?php echo msg_se($back_url); ?>">&larr; Back to <?php echo $is_inbox_message ? 'inbox' : 'sent'; ?></a>
    <span class="msg-meta" style="font-size:12px;color:#94A3B8;">
      <span title="<?php echo msg_se($created_abs); ?>"><?php echo msg_se($created_rel); ?></span>
      &middot; <?php echo msg_se($created_abs); ?>
    </span>
  </div>

  <div class="card">
    <div class="card-body">
      <h1 class="msg-detail-subject"><?php echo msg_se($detail['subject']); ?></h1>

      <div class="msg-detail-meta">
        <div class="msg-detail-avatar"><?php echo msg_se($initials !== '' ? $initials : '?'); ?></div>
        <div class="msg-detail-person">
          <div class="msg-detail-name">
            <?php echo msg_se($other_name); ?>
            <span style="margin-left:8px;"><?php echo msg_role_badge($other_role); ?></span>
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

      <div class="msg-detail-body"><?php echo msg_se($detail['message']); ?></div>

      <div class="msg-detail-actions">
        <a class="btn btn-primary" href="<?php echo msg_se($compose_url); ?>">↩ Reply</a>
        <a class="btn btn-secondary" href="<?php echo msg_se($back_url); ?>">Back</a>
      </div>
    </div>
  </div>

  <?php if (in_array($other_role, ['admin', 'research_staff', 'faculty', 'student'], true)): ?>
    <div class="card" id="compose" style="margin-top:24px;">
      <div class="card-header">
        <div>
          <div class="card-title">Reply to <?php echo msg_se($other_name); ?></div>
          <div class="card-subtitle">Your message will be delivered to their RMS inbox and notification feed.</div>
        </div>
      </div>
      <div class="card-body">
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
          <div style="display:flex; gap:12px; margin-top:18px;">
            <button type="submit" class="btn btn-primary">Send reply</button>
            <a class="btn btn-secondary" href="<?php echo msg_se($back_url); ?>">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

<?php else: ?>

  <!-- Tab strip -->
  <div class="msg-tabs" role="tablist">
    <a class="msg-tab <?php echo $current_view === 'inbox' ? 'is-active' : ''; ?>"
       href="<?php echo msg_se($tab_inbox_url); ?>" role="tab">
      💬 Inbox
      <?php if ($unread_count > 0): ?>
        <span class="msg-tab-badge"><?php echo (int) $unread_count; ?></span>
      <?php endif; ?>
    </a>
    <a class="msg-tab <?php echo $current_view === 'sent' ? 'is-active' : ''; ?>"
       href="<?php echo msg_se($tab_sent_url); ?>" role="tab">
      ✉️ Sent
    </a>
  </div>

  <!-- Compose form -->
  <div class="card" id="compose">
    <div class="card-header">
      <div>
        <div class="card-title">Compose Message</div>
        <div class="card-subtitle">Send a private message to any active RMS user. They will also see a notification.</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (!$recipients): ?>
        <p style="color:#64748B; margin:0;">No other active users are available to receive messages.</p>
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
                  [, $role_label] = msg_role_meta($rrole);
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
          <div style="display:flex; gap:12px; margin-top:18px;">
            <button type="submit" class="btn btn-primary">Send message</button>
            <button type="reset" class="btn btn-secondary">Clear</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List view (Inbox or Sent) -->
  <div class="card" style="margin-top:24px;">
    <div class="card-header">
      <div>
        <div class="card-title">
          <?php echo $current_view === 'sent' ? 'Sent Messages' : 'Inbox'; ?>
        </div>
        <div class="card-subtitle">
          <?php if ($current_view === 'sent'): ?>
            Messages you have sent to other RMS users.
          <?php else: ?>
            Newest first &middot; unread highlighted in purple
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php if (!$rows): ?>
        <div class="msg-empty">
          <div class="msg-empty-icon"><?php echo $current_view === 'sent' ? '✉️' : '📭'; ?></div>
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
          ?>
            <a class="msg-item <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>"
               href="<?php echo msg_se($detail_url); ?>">
              <div class="msg-dot" aria-hidden="true"></div>
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
  </div>

<?php endif; ?>

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
