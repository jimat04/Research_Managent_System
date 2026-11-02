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
 *   - Compose form: recipient dropdown (active administrators only, exclude self),
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

// Resolve the active administrator before POST handling because compose and
// reply submissions are processed before the page is rendered.
$admin_recipient_id = 0;
$a_stmt = $conn->prepare(
    "SELECT user_id
       FROM users
      WHERE status = 'active' AND role = 'admin' AND deleted_at IS NULL
   ORDER BY user_id ASC
      LIMIT 1"
);
if ($a_stmt) {
    $a_stmt->execute();
    $admin_row = $a_stmt->get_result()->fetch_assoc();
    $admin_recipient_id = (int) ($admin_row['user_id'] ?? 0);
    $a_stmt->close();
}

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/** Local HTML-escape helper. */
function msg_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Parse a nonnegative integer from scalar request input; return null if invalid. */
function msg_parse_nonnegative_int($value) {
    if (!is_scalar($value) || is_bool($value)) {
        return null;
    }
    $value = (string) $value;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $parsed === false ? null : (int) $parsed;
}

/** Load a participant-owned adviser request, with an explicit read-only admin view when requested. */
function msg_load_adviser_request(mysqli $connection, int $request_id, int $current_user_id, bool $allow_admin_view = false) {
    $stmt = $connection->prepare(
        'SELECT ar.request_id, ar.project_id, ar.requester_id, ar.faculty_id, ar.status, ar.created_at,
                rp.title AS project_title,
                s.first_name AS student_first, s.last_name AS student_last, s.role AS student_role,
                s.program AS student_program, s.year_level AS student_year_level,
                f.first_name AS faculty_first, f.last_name AS faculty_last, f.role AS faculty_role
           FROM adviser_requests ar
           JOIN research_projects rp ON rp.project_id = ar.project_id AND rp.deleted_at IS NULL
           JOIN users s ON s.user_id = ar.requester_id AND s.deleted_at IS NULL
           JOIN users f ON f.user_id = ar.faculty_id AND f.deleted_at IS NULL
          WHERE ar.request_id = ? AND (? = 1 OR ar.requester_id = ? OR ar.faculty_id = ?)
          LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $admin_view = $allow_admin_view ? 1 : 0;
    $stmt->bind_param('iiii', $request_id, $admin_view, $current_user_id, $current_user_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $request;
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
        'faculty'        => ['Faculty',         '#315b8c'],
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

    if ($action === 'send_adviser_request_message') {
        $request_id = msg_parse_nonnegative_int($_POST['request_id'] ?? null);
        $body = trim((string) ($_POST['message'] ?? ''));
        $request = $role === 'student' || $role === 'faculty'
            ? msg_load_adviser_request($conn, (int) ($request_id ?? 0), $user_id)
            : null;
        $errors = [];
        if (!$request_id || !$request) {
            $errors[] = 'Adviser request not found, or you do not have permission to message it.';
        } elseif ((string) $request['status'] === 'declined') {
            $errors[] = 'Messaging is closed for a declined adviser request.';
        }
        if ($body === '') {
            $errors[] = 'Message body is required.';
        } elseif (mb_strlen($body) > 5000) {
            $errors[] = 'Message body is too long (max 5000 characters).';
        }
        if ($errors) {
            $_SESSION['module_error'] = implode(' ', $errors);
            header('Location: ' . SITE_URL . 'pages/shared/messages.php' . ($request_id ? '?request_id=' . $request_id : ''));
            exit;
        }

        $insert = $conn->prepare(
            'INSERT INTO adviser_request_messages (request_id, sender_id, message) VALUES (?, ?, ?)'
        );
        if ($insert) {
            $insert->bind_param('iis', $request_id, $user_id, $body);
            $sent = $insert->execute();
            $insert->close();
        } else {
            $sent = false;
        }
        if ($sent) {
            $recipient_id = $user_id === (int) $request['requester_id']
                ? (int) $request['faculty_id']
                : (int) $request['requester_id'];
            createNotification(
                $recipient_id,
                'New adviser request message',
                'A message was added to the adviser request for ' . (string) $request['project_title'] . '.',
                'info',
                'pages/shared/messages.php?request_id=' . $request_id
            );
            logActivity('Sent an adviser request message', 'messages');
            $_SESSION['module_success'] = 'Message sent.';
        } else {
            $_SESSION['module_error'] = 'The message could not be sent. Please try again.';
        }
        header('Location: ' . SITE_URL . 'pages/shared/messages.php?request_id=' . $request_id);
        exit;
    } elseif ($action === 'send_message') {
        $posted_recipient_id = msg_parse_nonnegative_int($_POST['recipient_id'] ?? null);
        $has_thread_message_id = array_key_exists('thread_message_id', $_POST);
        $thread_message_id = msg_parse_nonnegative_int($_POST['thread_message_id'] ?? null);
        $recipient_id = 0;
        $thread_root_id = null;
        $subject      = trim((string) ($_POST['subject'] ?? ''));
        $body         = trim((string) ($_POST['message'] ?? ''));
        $errors       = [];

        if ($role === 'admin') {
            if (!$has_thread_message_id) {
                if ($posted_recipient_id === null || $posted_recipient_id <= 0) {
                    $errors[] = 'Please choose an active recipient.';
                } elseif ($posted_recipient_id === $user_id) {
                    $errors[] = 'You cannot send a message to yourself.';
                } else {
                    $recipient_id = $posted_recipient_id;
                }
            } elseif ($thread_message_id === null) {
                $errors[] = 'A valid message thread is required to send a reply.';
            } else {
                $thread_stmt = $conn->prepare(
                    'SELECT history_id, thread_id, sender_id, recipient_id FROM messages
                      WHERE history_id = ? AND (sender_id = ? OR recipient_id = ?) LIMIT 1'
                );
                if ($thread_stmt) {
                    $thread_stmt->bind_param('iii', $thread_message_id, $user_id, $user_id);
                    $thread_stmt->execute();
                    $thread_row = $thread_stmt->get_result()->fetch_assoc();
                    $thread_stmt->close();
                    if ($thread_row) {
                        $thread_root_id = (int) ($thread_row['thread_id'] ?? 0);
                        if ($thread_root_id <= 0) {
                            $thread_root_id = (int) $thread_row['history_id'];
                        }
                        $recipient_id = (int) $thread_row['sender_id'] === $user_id
                            ? (int) $thread_row['recipient_id']
                            : (int) $thread_row['sender_id'];
                        if ($posted_recipient_id === null || $posted_recipient_id !== $recipient_id) {
                            $errors[] = 'The reply recipient does not match this message.';
                        }
                    } else {
                        $errors[] = 'Message thread not found, or you do not have permission to reply.';
                    }
                } else {
                    $errors[] = 'Could not verify the message thread. Please try again.';
                }
            }
        } else {
            $recipient_id = $admin_recipient_id;
            if ($posted_recipient_id === null || $posted_recipient_id !== $recipient_id) {
                $errors[] = 'The recipient does not match the active administrator.';
            }
            if ($recipient_id <= 0) {
                $errors[] = 'No active administrator is available to receive messages.';
            } elseif ($recipient_id === $user_id) {
                $errors[] = 'You cannot send a message to yourself.';
            }
        }

        if (!$errors && $recipient_id > 0) {
            $recipient_sql = $role === 'admin'
                ? "SELECT user_id FROM users WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1"
                : "SELECT user_id FROM users WHERE user_id = ? AND status = 'active' AND role = 'admin' AND deleted_at IS NULL LIMIT 1";
            $r_stmt = $conn->prepare($recipient_sql);
            if ($r_stmt) {
                $r_stmt->bind_param('i', $recipient_id);
                $r_stmt->execute();
                $r_row = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if (!$r_row) {
                    $errors[] = $role === 'admin'
                        ? 'The user who sent this message is no longer active.'
                        : 'Only active administrators can receive messages.';
                }
            } else {
                $errors[] = 'Could not verify the recipient. Please try again.';
            }
        }

        if ($role === 'admin' && $recipient_id === $user_id) {
            $errors[] = 'You cannot send a message to yourself.';
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
        $sent_message_id = 0;
        $send_succeeded = false;
        if ($conn->begin_transaction()) {
            $ins->bind_param('iiss', $user_id, $recipient_id, $subject, $body);
            if ($ins->execute()) {
                $sent_message_id = (int) $conn->insert_id;
                $ins->close();
                $thread_root_id = $thread_root_id ?? $sent_message_id;
                $thread_update = $conn->prepare('UPDATE messages SET thread_id = ? WHERE history_id = ?');
                if ($thread_root_id > 0 && $sent_message_id > 0 && $thread_update) {
                    $thread_update->bind_param('ii', $thread_root_id, $sent_message_id);
                    $send_succeeded = $thread_update->execute() && $conn->commit();
                    $thread_update->close();
                }
            } else {
                $ins->close();
            }
            if (!$send_succeeded) {
                $conn->rollback();
            }
        } else {
            $ins->close();
        }
        if ($send_succeeded) {
            logActivity('Sent a message', 'messages');
            // Best-effort notification — createNotification() returns false if the
            // notifications table is missing; we don't want to fail the send.
            $sender_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            createNotification(
                $recipient_id,
                'New message',
                $sender_name !== '' ? "$sender_name sent you a message: \"$subject\"" : "You received a new message: \"$subject\"",
                'info',
                'pages/shared/messages.php?id=' . $sent_message_id
            );
            $_SESSION['module_success'] = 'Message sent.';
        } else {
            $_SESSION['module_error'] = 'The message could not be sent. Please try again.';
        }
        header('Location: ' . SITE_URL . 'pages/shared/messages.php?id=' . $sent_message_id);
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
$has_detail_id = array_key_exists('id', $_GET);
$detail_id     = $has_detail_id ? msg_parse_nonnegative_int($_GET['id']) : null;
$is_detail     = ($has_detail_id && $detail_id !== null);
$has_adviser_request_id = array_key_exists('request_id', $_GET);
$adviser_request_id = $has_adviser_request_id
    ? msg_parse_nonnegative_int($_GET['request_id'])
    : null;
$is_adviser_request_detail = $has_adviser_request_id && $adviser_request_id !== null;
$has_conversation_detail = $is_detail || $is_adviser_request_detail;
$admin_user_filter = $role === 'admin' ? trim((string) ($_GET['user'] ?? '')) : '';
if (mb_strlen($admin_user_filter) > 100) {
    $admin_user_filter = mb_substr($admin_user_filter, 0, 100);
}
$allowed_message_roles = array_values(array_diff(['admin', 'research_staff', 'faculty', 'student'], [$role]));
$admin_role_filter = $role === 'admin' ? (string) ($_GET['role'] ?? '') : '';
if (!in_array($admin_role_filter, $allowed_message_roles, true)) {
    $admin_role_filter = '';
}
if ($detail_id === null) {
    $detail_id = 0;
}
if ($has_adviser_request_id && !$is_adviser_request_detail) {
    $_SESSION['module_error'] = 'Adviser request not found.';
    header('Location: ' . SITE_URL . 'pages/shared/messages.php');
    exit;
}
if ($is_detail && $is_adviser_request_detail) {
    http_response_code(400);
    exit('Choose one conversation.');
}
if ($is_adviser_request_detail && !in_array($role, ['student', 'faculty', 'admin'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

// ---------------------------------------------------------------
// Automatic recipient is the active administrator.
// ---------------------------------------------------------------
$recipients = [];
if ($admin_recipient_id > 0) {
    $recipients[] = ['user_id' => $admin_recipient_id, 'first_name' => 'Administrator', 'last_name' => '', 'role' => 'admin'];
}
$admin_compose_recipients = [];
$admin_recipient_roles = [];
$admin_recipient_courses = [];
if ($role === 'admin') {
    $recipient_list_stmt = $conn->prepare(
        "SELECT u.user_id, u.first_name, u.last_name, u.role, u.program,
                (SELECT student_program.program_code
                   FROM programs student_program
                  WHERE student_program.status = 1
                    AND (UPPER(TRIM(student_program.program_code)) = UPPER(TRIM(u.program))
                         OR UPPER(TRIM(student_program.program_name)) = UPPER(TRIM(u.program)))
                  ORDER BY student_program.program_id ASC LIMIT 1) AS student_course_code,
                GROUP_CONCAT(DISTINCT faculty_program.program_code ORDER BY faculty_program.program_code SEPARATOR ',') AS faculty_program_codes
           FROM users u
      LEFT JOIN faculty_program_assignments fpa
             ON u.role = 'faculty' AND fpa.faculty_id = u.user_id
      LEFT JOIN programs faculty_program
             ON faculty_program.program_id = fpa.program_id AND faculty_program.status = 1
          WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.user_id <> ?
       GROUP BY u.user_id, u.first_name, u.last_name, u.role, u.program
       ORDER BY u.first_name ASC, u.last_name ASC, u.user_id ASC"
    );
    if ($recipient_list_stmt) {
        $recipient_list_stmt->bind_param('i', $user_id);
        $recipient_list_stmt->execute();
        $recipient_list_result = $recipient_list_stmt->get_result();
        $admin_compose_recipients = $recipient_list_result
            ? $recipient_list_result->fetch_all(MYSQLI_ASSOC)
            : [];
        $recipient_list_stmt->close();
    }
    foreach ($admin_compose_recipients as $recipient) {
        $recipient_role = (string) ($recipient['role'] ?? '');
        if (in_array($recipient_role, ['admin', 'research_staff', 'faculty', 'student'], true)
            && !in_array($recipient_role, $admin_recipient_roles, true)) {
            $admin_recipient_roles[] = $recipient_role;
        }
        $recipient_courses = [];
        if ($recipient_role === 'student') {
            $student_course = trim((string) ($recipient['student_course_code'] ?? ''));
            if ($student_course === '') {
                $student_course = trim((string) ($recipient['program'] ?? ''));
            }
            if ($student_course !== '') {
                $recipient_courses[] = $student_course;
            }
        } elseif ($recipient_role === 'faculty') {
            $recipient_courses = explode(',', (string) ($recipient['faculty_program_codes'] ?? ''));
        }
        foreach ($recipient_courses as $recipient_course) {
            $recipient_course = mb_strtoupper(trim((string) $recipient_course));
            if ($recipient_course !== '') {
                $admin_recipient_courses[$recipient_course] = $recipient_course;
            }
        }
    }
    natcasesort($admin_recipient_courses);
    $admin_recipient_courses = array_values($admin_recipient_courses);
}

// ---------------------------------------------------------------
// Pre-fill values for the compose form (sticky on validation failure
// and pre-filled when replying to a message).
// ---------------------------------------------------------------
$compose_recipient_id = msg_parse_nonnegative_int($_GET['reply_to'] ?? null) ?? 0;
// ?to={user_id} is a friendly alias for ?reply_to={user_id} — used by
// cross-role message links that just want
// to pre-select a recipient without a message-id context.
if ($compose_recipient_id <= 0) {
    $compose_recipient_id = msg_parse_nonnegative_int($_GET['to'] ?? null) ?? 0;
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
        'SELECT m.history_id AS message_id, m.thread_id, m.sender_id, m.recipient_id, m.subject, m.message,
                m.is_read, m.created_at,
                s.first_name AS s_first, s.last_name AS s_last, s.role AS s_role, s.status AS s_status,
                r.first_name AS r_first, r.last_name AS r_last, r.role AS r_role, r.status AS r_status
           FROM messages m
           JOIN users s ON s.user_id = m.sender_id
           JOIN users r ON r.user_id = m.recipient_id
          WHERE m.history_id = ?
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
    $detail_read_confirmed = ((int) $detail['is_read'] === 1);
    if ((int) $detail['recipient_id'] === $user_id && (int) $detail['is_read'] === 0) {
        $u = $conn->prepare('UPDATE messages SET is_read = 1 WHERE history_id = ? AND recipient_id = ?');
        if ($u) {
            $u->bind_param('ii', $detail_id, $user_id);
            if ($u->execute()) {
                $detail_read_confirmed = true;
                $detail['is_read'] = 1; // reflect change in the rendered view
                if ($u->affected_rows > 0) {
                    logActivity('Read message', 'messages');
                }
            }
            $u->close();
        }
    }

    // Read the corresponding notification for this recipient. New notifications
    // carry the message ID; older generic links are matched by exact generated
    // title/message text and recipient, and only the newest one is updated.
    if ((int) $detail['recipient_id'] === $user_id && $detail_read_confirmed) {
        $notification_link = 'pages/shared/messages.php?id=' . $detail_id;
        $n = $conn->prepare(
            'UPDATE notifications SET is_read = 1
              WHERE user_id = ? AND link = ? AND is_read = 0 LIMIT 1'
        );
        if ($n) {
            $n->bind_param('is', $user_id, $notification_link);
            $n->execute();
            $notification_updated = $n->affected_rows > 0;
            $n->close();
        } else {
            $notification_updated = false;
        }

        if (!$notification_updated) {
            $sender_name = trim((string) $detail['s_first'] . ' ' . (string) $detail['s_last']);
            $legacy_message = $sender_name !== ''
                ? $sender_name . ' sent you a message: "' . (string) $detail['subject'] . '"'
                : 'You received a new message: "' . (string) $detail['subject'] . '"';
            $legacy_stmt = $conn->prepare(
                "SELECT notification_id FROM notifications
                  WHERE user_id = ? AND title = 'New message' AND message = ?
                    AND link = 'pages/shared/messages.php' AND is_read = 0
                  ORDER BY notification_id DESC LIMIT 1"
            );
            if ($legacy_stmt) {
                $legacy_stmt->bind_param('is', $user_id, $legacy_message);
                $legacy_stmt->execute();
                $legacy_row = $legacy_stmt->get_result()->fetch_assoc();
                $legacy_stmt->close();
                if ($legacy_row) {
                    $legacy_id = (int) $legacy_row['notification_id'];
                    $legacy_update = $conn->prepare(
                        'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ? AND is_read = 0'
                    );
                    if ($legacy_update) {
                        $legacy_update->bind_param('ii', $legacy_id, $user_id);
                        $legacy_update->execute();
                        $legacy_update->close();
                    }
                }
            }
        }
    }

    // Load only the messages in this persisted thread that involve the current
    // user. Older rows remain separate roots because no prior reply link exists.
    $thread_root_id = (int) ($detail['thread_id'] ?? 0);
    if ($thread_root_id <= 0) {
        $thread_root_id = (int) $detail['message_id'];
    }
    $history_rows = [];
    $history_stmt = $conn->prepare(
        'SELECT h.history_id, h.sender_id, h.recipient_id, h.message, h.created_at,
                s.first_name AS sender_first, s.last_name AS sender_last, s.role AS sender_role,
                r.first_name AS recipient_first, r.last_name AS recipient_last, r.role AS recipient_role
           FROM messages h
           JOIN users s ON s.user_id = h.sender_id
           JOIN users r ON r.user_id = h.recipient_id
          WHERE h.thread_id = ?
            AND (h.sender_id = ? OR h.recipient_id = ?)
       ORDER BY h.created_at ASC, h.history_id ASC'
    );
    if ($history_stmt) {
        $history_stmt->bind_param('iii', $thread_root_id, $user_id, $user_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $history_rows = $history_result ? $history_result->fetch_all(MYSQLI_ASSOC) : [];
        $history_stmt->close();
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

// Adviser request conversations use their own message table and participant
// rules, but render in this shared inbox alongside direct messages.
$adviser_request = null;
$adviser_request_history = [];
if ($is_adviser_request_detail) {
    $adviser_request = msg_load_adviser_request($conn, $adviser_request_id, $user_id, $role === 'admin');
    if (!$adviser_request) {
        http_response_code(403);
        exit('Access denied.');
    }
    $request_history_stmt = $conn->prepare(
        'SELECT m.message_id, m.sender_id, m.message, m.created_at, sender.first_name, sender.last_name, sender.role
           FROM adviser_request_messages m
           JOIN users sender ON sender.user_id = m.sender_id
          WHERE m.request_id = ?
       ORDER BY m.created_at ASC, m.message_id ASC'
    );
    if ($request_history_stmt) {
        $request_history_stmt->bind_param('i', $adviser_request_id);
        $request_history_stmt->execute();
        $history_result = $request_history_stmt->get_result();
        $adviser_request_history = $history_result ? $history_result->fetch_all(MYSQLI_ASSOC) : [];
        $request_history_stmt->close();
    }
    if ($role !== 'admin') {
        $mark_request_read = $conn->prepare(
            'UPDATE adviser_request_messages
                SET read_at = CURRENT_TIMESTAMP
              WHERE request_id = ? AND sender_id <> ? AND read_at IS NULL'
        );
        if ($mark_request_read) {
            $mark_request_read->bind_param('ii', $adviser_request_id, $user_id);
            $mark_request_read->execute();
            if ($mark_request_read->affected_rows > 0) {
                logActivity('Read adviser request messages', 'messages');
            }
            $mark_request_read->close();
        }
        $request_notification_link = 'pages/shared/messages.php?request_id=' . $adviser_request_id;
        $legacy_request_notification_link = SITE_URL . 'pages/shared/adviser-request-messages.php?id=' . $adviser_request_id;
        $read_request_notifications = $conn->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND link IN (?, ?) AND is_read = 0'
        );
        if ($read_request_notifications) {
            $read_request_notifications->bind_param('iss', $user_id, $request_notification_link, $legacy_request_notification_link);
            $read_request_notifications->execute();
            $read_request_notifications->close();
        }
    }
}

// ---------------------------------------------------------------
// List views — inbox or sent, newest first, LIMIT $MSG_LIMIT.
// (Skipped if we are rendering the detail view.)
// ---------------------------------------------------------------
$rows = [];
$unread_count = 0;
if (!$has_conversation_detail || $role === 'admin') {
    if ($current_view === 'sent') {
        $s_stmt = $conn->prepare(
            "SELECT m.history_id AS message_id, m.subject, m.message, m.is_read, m.created_at,
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
        $filter_sql = '';
        if ($role === 'admin' && $admin_user_filter !== '') {
            $filter_sql .= ' AND CONCAT(s.first_name, \' \', s.last_name) LIKE ?';
        }
        if ($role === 'admin' && $admin_role_filter !== '') {
            $filter_sql .= ' AND s.role = ?';
        }
        $i_stmt = $conn->prepare(
            "SELECT m.history_id AS message_id, m.subject, m.message, m.is_read, m.created_at,
                    m.sender_id,
                    CONCAT(s.first_name, ' ', s.last_name) AS other_name,
                    s.role AS other_role
               FROM messages m
               JOIN users s ON s.user_id = m.sender_id
              WHERE m.recipient_id = ?
              {$filter_sql}
           ORDER BY m.created_at DESC
              LIMIT " . $MSG_LIMIT
        );
        if ($i_stmt) {
            if ($admin_user_filter !== '' && $admin_role_filter !== '') {
                $name_pattern = '%' . $admin_user_filter . '%';
                $i_stmt->bind_param('iss', $user_id, $name_pattern, $admin_role_filter);
            } elseif ($admin_user_filter !== '') {
                $name_pattern = '%' . $admin_user_filter . '%';
                $i_stmt->bind_param('is', $user_id, $name_pattern);
            } elseif ($admin_role_filter !== '') {
                $i_stmt->bind_param('is', $user_id, $admin_role_filter);
            } else {
                $i_stmt->bind_param('i', $user_id);
            }
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

// Surface adviser-request threads in the student/faculty inbox. These remain
// separate from ordinary messages so request ownership and read state stay scoped.
$adviser_request_rows = [];
if (!$has_conversation_detail && in_array($role, ['student', 'faculty'], true)) {
    $participant_column = $role === 'student' ? 'requester_id' : 'faculty_id';
    $adviser_list_stmt = $conn->prepare(
        "SELECT ar.request_id, ar.status AS request_status, ar.created_at AS request_created_at,
                rp.title AS project_title, partner.first_name, partner.last_name, partner.role AS other_role,
                (SELECT latest.message FROM adviser_request_messages latest
                  WHERE latest.request_id = ar.request_id
                  ORDER BY latest.created_at DESC, latest.message_id DESC LIMIT 1) AS last_message,
                (SELECT latest.created_at FROM adviser_request_messages latest
                  WHERE latest.request_id = ar.request_id
                  ORDER BY latest.created_at DESC, latest.message_id DESC LIMIT 1) AS last_message_at,
                (SELECT latest.sender_id FROM adviser_request_messages latest
                  WHERE latest.request_id = ar.request_id
                  ORDER BY latest.created_at DESC, latest.message_id DESC LIMIT 1) AS last_sender_id,
                (SELECT COUNT(*) FROM adviser_request_messages unread
                  WHERE unread.request_id = ar.request_id AND unread.sender_id <> ? AND unread.read_at IS NULL) AS unread_count
           FROM adviser_requests ar
           JOIN research_projects rp ON rp.project_id = ar.project_id AND rp.deleted_at IS NULL
           JOIN users partner ON partner.user_id = CASE WHEN ar.requester_id = ? THEN ar.faculty_id ELSE ar.requester_id END
                             AND partner.deleted_at IS NULL
          WHERE ar.{$participant_column} = ?
       ORDER BY COALESCE(
                    (SELECT latest.created_at FROM adviser_request_messages latest
                      WHERE latest.request_id = ar.request_id
                      ORDER BY latest.created_at DESC, latest.message_id DESC LIMIT 1),
                    ar.updated_at
                ) DESC, ar.request_id DESC
          LIMIT " . $MSG_LIMIT
    );
    if ($adviser_list_stmt) {
        $adviser_list_stmt->bind_param('iii', $user_id, $user_id, $user_id);
        $adviser_list_stmt->execute();
        $adviser_list_result = $adviser_list_stmt->get_result();
        $adviser_request_rows = $adviser_list_result ? $adviser_list_result->fetch_all(MYSQLI_ASSOC) : [];
        $adviser_list_stmt->close();
    }
    foreach ($adviser_request_rows as &$request_row) {
        $request_row['message_type'] = 'adviser_request';
        $request_row['message_id'] = 0;
        $request_row['subject'] = (string) $request_row['project_title'];
        $request_row['message'] = $request_row['last_message'] ?? '';
        $request_row['created_at'] = $request_row['last_message_at'] ?? $request_row['request_created_at'];
        $request_row['is_read'] = (int) $request_row['unread_count'] > 0 ? 0 : 1;
        $request_row['other_name'] = trim((string) $request_row['first_name'] . ' ' . (string) $request_row['last_name']);
        $unread_count += (int) $request_row['unread_count'];
    }
    unset($request_row);
    $rows = array_merge($rows, $adviser_request_rows);
    usort($rows, static function ($left, $right) {
        return strtotime((string) $right['created_at']) <=> strtotime((string) $left['created_at']);
    });
    $rows = array_slice($rows, 0, $MSG_LIMIT);
}

$visible_count   = count($rows);
$recipient_count = count($recipients);

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors profile.php and notifications.php.
// ---------------------------------------------------------------
$page_title    = 'Messages';
$page_subtitle = $has_conversation_detail
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

  .messages-layout { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(320px,.68fr); gap:24px; margin-top:34px; align-items:start; }.messages-layout.is-admin-list{grid-template-columns:minmax(0,1fr)}
  .msg-panel { overflow:hidden; border:1px solid var(--msg-line); border-radius:18px; background:#fff; box-shadow:0 14px 38px rgba(31,42,63,.065); }
  .msg-panel-header { display:flex; align-items:end; justify-content:space-between; gap:18px; padding:26px 28px 22px; border-bottom:1px solid #e8ecf1; }.msg-panel-eyebrow{margin-bottom:8px;color:var(--msg-accent)}.msg-panel-title{margin:0;color:#1c2639;font-size:24px;line-height:1.1;letter-spacing:-.03em}.msg-panel-copy{max-width:610px;margin:8px 0 0;color:var(--msg-muted);font-size:13px;line-height:1.55}.msg-panel-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}
  .msg-list { display:flex; flex-direction:column; }
  .msg-user-filter{display:grid;gap:8px;padding:16px 26px;border-bottom:1px solid #e8ecf1;background:#fbfcfd}.msg-user-filter>label{color:#586477;font-size:12px;font-weight:650}.msg-user-filter-controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.msg-user-filter input,.msg-user-filter select{flex:1;min-width:min(100%,190px);min-height:42px;padding:9px 12px;border:1px solid #d8dfe8;border-radius:8px;background:#fff;color:#202a3c;font:inherit}.msg-user-filter input:focus-visible,.msg-user-filter select:focus-visible{outline:3px solid rgba(var(--msg-rgb),.22);outline-offset:2px}.msg-user-filter .msg-btn{min-height:42px}.msg-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
  .msg-recipient-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}.msg-recipient-filter-field{display:grid;gap:6px}.msg-recipient-filter-field label{margin:0;color:#586477;font-size:12px;font-weight:650}.msg-recipient-filter-field input,.msg-recipient-filter-field select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #d8dfe8;border-radius:8px;background:#fff;color:#202a3c;font:inherit}.msg-recipient-filter-field input:focus-visible,.msg-recipient-filter-field select:focus-visible{outline:3px solid rgba(var(--msg-rgb),.22);outline-offset:2px}.msg-recipient-result{margin:0;color:#687386;font-size:12px}@media(max-width:900px){.msg-recipient-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.msg-recipient-filters{grid-template-columns:1fr}}
  .msg-item { position:relative; display:grid; grid-template-columns:42px minmax(0,1fr) auto; gap:15px; align-items:start; padding:20px 27px; border-bottom:1px solid #edf0f4; background:#fff; color:inherit; text-decoration:none; transition:background .2s ease,transform .2s ease; }.msg-item:last-child{border-bottom:0}.msg-item:hover{z-index:1;background:#fbfaf7;transform:translateX(3px)}.msg-item.is-unread{background:#fcfaf4}.msg-item.is-unread:hover{background:#faf6eb}
  .msg-avatar { position:relative; display:grid; place-items:center; width:40px; height:40px; border-radius:10px; background:#e9edf2; color:#465266; font-size:12px; font-weight:750; letter-spacing:.03em; }.msg-item.is-unread .msg-avatar{background:var(--msg-tint);color:var(--msg-accent)}.msg-dot{position:absolute;right:-3px;top:-3px;width:9px;height:9px;border:2px solid #fff;border-radius:50%;background:var(--msg-accent)}.msg-item.is-read .msg-dot{display:none}
  .msg-body{min-width:0}.msg-line1{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:4px}.msg-from{color:#202a3c;font-size:14px;font-weight:680}.msg-item.is-read .msg-from{font-weight:570}.msg-subject{margin:3px 0 5px;color:#263147;font-size:14px;font-weight:680;line-height:1.4;overflow-wrap:anywhere}.msg-item.is-read .msg-subject{color:#475267;font-weight:580}.msg-snippet{margin:0;max-width:760px;color:#707b8d;font-size:13px;line-height:1.52;overflow-wrap:anywhere}.msg-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#929cac;font-size:11px}.msg-list .msg-meta{margin-top:8px}.msg-time{font-weight:650}.msg-right{padding-top:2px;color:#929cac;font-size:11px;text-align:right}
  .msg-role-badge { display:inline-flex; align-items:center; padding:4px 7px; border:1px solid #dce2e9; border-radius:5px; background:#f4f6f8; color:#586477; font-size:9px; font-weight:720; letter-spacing:.055em; text-transform:uppercase; }.msg-role-badge.role-admin{border-color:#eadaba;background:#faf4e8;color:#865f24}.msg-role-badge.role-research-staff{border-color:#d2e7e2;background:#eff8f6;color:#27695f}.msg-role-badge.role-faculty{border-color:#d7e3ef;background:#f0f5fa;color:#315b8c}.msg-role-badge.role-student{border-color:#e3d9e9;background:#f7f2f8;color:#72528c}
  .msg-context-badge{display:inline-flex;align-items:center;padding:3px 6px;border-radius:4px;background:#eef2f7;color:#59667a;font-size:9px;font-weight:700;letter-spacing:.03em}
  .msg-chat-closed{margin:0;padding:15px 18px;border-top:1px solid var(--msg-line);background:#f8fafc;color:#64748b;font-size:12px}
  .msg-empty{padding:68px 28px;text-align:center}.msg-empty-icon{display:grid;place-items:center;width:50px;height:50px;margin:0 auto 15px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#886326;font:750 13px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.msg-empty-title{color:#2b3548;font-size:17px;font-weight:680}.msg-empty-subtitle{max-width:470px;margin:7px auto 0;color:#7b8697;font-size:13px;line-height:1.55}.msg-truncated-note{padding:14px 24px;border-top:1px solid #edf0f4;background:#fafbfc;color:#8791a1;font-size:11px;text-align:center}

  .msg-inbox-panel{grid-column:1;grid-row:1}.msg-compose-panel { position:sticky; top:92px; grid-column:2; grid-row:1; }.msg-compose-panel .msg-panel-header{padding-bottom:20px}.msg-compose-body{padding:24px 26px 28px}.msg-compose-grid{display:grid;gap:15px}.msg-compose-grid label{display:block;margin:0 0 -8px;color:#000;font-size:12px;font-weight:700}.msg-compose-grid .form-control{width:100%;min-height:44px;padding:10px 13px;border:1px solid #d9e0e8;border-radius:8px;background:#fff;color:#000!important;font-family:inherit;font-size:13px;font-weight:500;line-height:1.45;transition:border-color .2s ease,box-shadow .2s ease}.msg-compose-grid textarea.form-control{min-height:154px;resize:vertical}.msg-compose-grid .form-control:focus{outline:0;border-color:var(--msg-accent);box-shadow:0 0 0 3px rgba(var(--msg-rgb),.12)}.msg-compose-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:19px}.msg-compose-note{margin:13px 0 0;color:#818b9b;font-size:11px;line-height:1.5}

  .msg-detail-shell{max-width:1120px;margin:34px auto 0}.msg-detail-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}.msg-detail-back{display:inline-flex;align-items:center;gap:7px;color:#455166;font-size:12px;font-weight:680;text-decoration:none}.msg-detail-back:hover{color:#8b6425}.msg-detail-card{overflow:hidden;border:1px solid var(--msg-line);border-radius:18px;background:#fff;box-shadow:0 16px 42px rgba(31,42,63,.075)}.msg-detail-heading{padding:30px 34px 26px;border-bottom:1px solid #e8ecf1;background:#fbfcfd}.msg-detail-subject{max-width:840px;margin:0;color:#192235;font-size:clamp(27px,3vw,40px);font-weight:720;line-height:1.12;letter-spacing:-.04em;text-wrap:balance;overflow-wrap:anywhere}.msg-detail-meta{display:flex;align-items:center;gap:14px;margin-top:23px}.msg-detail-avatar{display:grid;place-items:center;width:46px;height:46px;border-radius:11px;background:#202c43;color:#e9bf6e;font-size:13px;font-weight:760;letter-spacing:.04em}.msg-detail-person{display:flex;flex-direction:column;gap:5px}.msg-detail-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#263147;font-size:14px;font-weight:680}.msg-detail-line{color:#748093;font-size:12px}.msg-detail-line strong{color:#4d596d}.msg-detail-body{min-height:190px;padding:35px 34px 43px;color:#273246;font-size:15px;line-height:1.78;white-space:pre-wrap;overflow-wrap:anywhere}.msg-history{padding:26px 34px 32px}.msg-history-title{margin:0 0 19px;color:#283448;font-size:13px;font-weight:730}.msg-history-list{display:grid;gap:15px}.msg-history-entry{padding:17px 19px;border:1px solid #e6ebf1;border-radius:12px;background:#fff}.msg-history-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;color:#59667a;font-size:12px}.msg-history-sender{display:inline-flex;align-items:center;gap:8px;font-weight:700}.msg-history-time{color:#8792a1;font-size:11px}.msg-history-message{margin:0;color:#273246;font-size:14px;line-height:1.72;white-space:pre-wrap;overflow-wrap:anywhere}.msg-detail-actions{display:flex;gap:9px;flex-wrap:wrap;padding:18px 34px 24px;border-top:1px solid #edf0f4;background:#fafbfc}.msg-reply-panel{margin-top:22px}
  .messages-workspace .msg-item.is-unread{background:var(--msg-tint)}.messages-workspace .msg-item.is-unread:hover{filter:brightness(.99)}
  .messages-workspace .msg-empty-icon{border-color:rgba(var(--msg-rgb),.2);background:var(--msg-tint);color:var(--msg-accent)}
  .messages-workspace .msg-detail-back:hover{color:var(--msg-accent)}
  .messages-workspace .msg-detail-avatar{background:var(--msg-deep);color:var(--msg-highlight)}

  @media(max-width:1050px){.msg-hero{grid-template-columns:1fr;gap:30px}.msg-stat-stack{grid-template-columns:repeat(3,1fr)}.msg-stat{grid-template-columns:32px 1fr}.msg-stat-value{grid-column:2}.messages-layout{grid-template-columns:1fr}.msg-inbox-panel,.msg-compose-panel{grid-column:1;grid-row:auto}.msg-inbox-panel{order:1}.msg-compose-panel{position:static;order:2}}
  @media(max-width:700px){.msg-hero{min-height:0;padding:30px 24px 47px;border-radius:18px 18px 7px 7px}.msg-hero h2{font-size:39px}.msg-stat-stack{grid-template-columns:1fr}.msg-stat{grid-template-columns:34px 1fr auto}.msg-stat-value{grid-column:auto}.msg-commandbar{align-items:stretch;flex-direction:column;margin:-17px 12px 0}.msg-tabs{width:100%}.msg-tab{flex:1;justify-content:center}.msg-commandbar>.msg-btn{width:100%}.messages-layout{margin-top:24px}.msg-panel-header{align-items:flex-start;flex-direction:column;padding:23px 20px 19px}.msg-panel-count{white-space:normal}.msg-item{grid-template-columns:40px minmax(0,1fr);padding:17px 19px}.msg-right{grid-column:2;text-align:left}.msg-compose-body{padding:21px 20px 24px}.msg-detail-shell{margin-top:24px}.msg-detail-heading,.msg-detail-body,.msg-detail-actions{padding-left:22px;padding-right:22px}.msg-history{padding:22px 18px}.msg-history-entry{padding:15px}.msg-history-sender{flex-wrap:wrap}.msg-detail-subject{font-size:28px}.msg-detail-header .msg-meta{width:100%}}
  @media(prefers-reduced-motion:reduce){.msg-btn,.msg-tab,.msg-item{transition:none}.msg-item:hover{transform:none}}

  /* Shared messenger presentation */
  .messages-workspace{max-width:1480px}
  .msg-hero{display:none}
  .msg-page-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 18px}
  .msg-page-heading h2{margin:0;color:#172033;font-size:clamp(25px,2.8vw,34px);font-weight:720;line-height:1.1;letter-spacing:-.04em}
  .msg-page-heading p{margin:6px 0 0;color:#687386;font-size:13px;line-height:1.45}
  .msg-heading-count{display:flex;align-items:center;gap:8px;color:#697588;font-size:12px;font-weight:650;white-space:nowrap}
  .msg-heading-count strong{display:grid;min-width:30px;height:30px;place-items:center;padding:0 8px;border-radius:9px;background:var(--msg-tint);color:var(--msg-accent);font-variant-numeric:tabular-nums}
  .msg-commandbar{margin:0 0 14px;padding:8px;border-radius:12px;box-shadow:none}
  .messages-layout{grid-template-columns:minmax(280px,350px) minmax(0,1fr);gap:0;margin:0;align-items:stretch;min-height:min(680px,calc(100dvh - 260px));overflow:hidden;border:1px solid var(--msg-line);border-radius:16px;background:#fff;box-shadow:0 12px 34px rgba(31,42,63,.06)}
  .messages-layout.is-admin-list{grid-template-columns:minmax(280px,350px) minmax(0,1fr)}
  .msg-panel{min-width:0;border:0;border-radius:0;box-shadow:none}
  .msg-inbox-panel{grid-column:1;grid-row:1;display:flex;min-height:0;flex-direction:column;border-right:1px solid var(--msg-line)}
  .msg-inbox-panel>.msg-panel-header{align-items:flex-start;padding:20px 20px 16px}
  .msg-inbox-panel .msg-panel-title{font-size:20px}
  .msg-user-filter{padding:12px 16px}
  .msg-list{min-height:0;overflow-y:auto;overscroll-behavior:contain}
  .msg-item{grid-template-columns:42px minmax(0,1fr);gap:12px;padding:16px 17px}
  .msg-item:hover{transform:none}
  .msg-right{grid-column:2;display:flex;justify-content:flex-end;padding-top:0}
  .msg-compose-panel{position:relative;top:auto;grid-column:2;grid-row:1;display:flex;min-width:0;min-height:0;flex-direction:column;background:#f8fafc}
  .msg-compose-panel>.msg-panel-header{align-items:flex-start;padding:24px 26px 18px;background:#fff}
  .msg-compose-panel .msg-compose-body{overflow:auto;padding:22px 26px 28px}
  .msg-compose-panel .msg-compose-grid textarea.form-control{min-height:120px}
  .msg-detail-shell{display:flex;max-width:none;height:min(720px,calc(100dvh - 240px));min-height:440px;flex-direction:column;margin:0;overflow:hidden;border:1px solid var(--msg-line);border-radius:16px;background:#fff;box-shadow:0 12px 34px rgba(31,42,63,.06)}
  .msg-detail-header{height:72px;margin:0;padding:12px 22px;border-bottom:1px solid #e8edf3;background:#fff}
  .msg-detail-back{color:var(--msg-accent)}
  .msg-detail-card{display:grid;min-height:0;flex:1;grid-template-rows:auto minmax(0,1fr) auto;overflow:hidden;border:0;border-radius:0;background:#fff;box-shadow:none}
  .msg-detail-heading{display:flex;align-items:center;gap:14px;padding:16px 24px;border-bottom:1px solid #e8edf3;background:#fff}
  .msg-detail-subject{order:2;flex:1;max-width:none;font-size:15px;font-weight:650;letter-spacing:0}
  .msg-detail-meta{order:1;flex:1;min-width:0;margin:0}
  .msg-detail-avatar{width:42px;height:42px;flex:0 0 42px;border-radius:50%;background:var(--msg-tint);color:var(--msg-accent)}
  .msg-detail-line{font-size:11px}
  .msg-history{min-height:0;overflow-y:auto;padding:22px clamp(16px,4vw,48px);background:#f6f8fc;overscroll-behavior:contain;scrollbar-color:#cbd5e1 transparent;scrollbar-width:thin}
  .msg-history-title{margin-bottom:16px;color:#7a8596;font-size:11px;font-weight:650;text-align:center}
  .msg-history-list{display:flex;flex-direction:column;gap:12px}
  .msg-history-entry{width:fit-content;max-width:min(78%,680px);padding:10px 13px;border:1px solid #e1e7ef;border-radius:15px 15px 15px 5px;background:#fff;box-shadow:0 2px 5px rgba(31,49,78,.035)}
  .msg-history-entry.is-mine{align-self:flex-end;border-color:var(--msg-accent);border-radius:15px 15px 5px 15px;background:var(--msg-accent);color:#fff}
  .msg-history-meta{justify-content:space-between;gap:8px 16px;margin:0 0 5px;font-size:10px}
  .msg-history-sender{color:#34435b;font-size:11px}
  .msg-history-entry.is-mine .msg-history-meta,.msg-history-entry.is-mine .msg-history-sender,.msg-history-entry.is-mine .msg-history-time{color:rgba(255,255,255,.82)}
  .msg-history-entry.is-mine .msg-role-badge{border-color:rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff}
  .msg-history-message{color:inherit;font-size:13px;line-height:1.58}
  .msg-detail-actions{display:none}
  .msg-reply-panel{flex:0 0 auto;margin:0;border-top:1px solid #e8edf3;border-radius:0;background:#fff}
  .msg-reply-panel>.msg-panel-header{display:none}
  .msg-reply-panel .msg-compose-body{padding:12px 16px 14px}
  .msg-reply-panel form{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:8px}
  .msg-reply-panel .msg-compose-grid{display:block;min-width:0}
  .msg-reply-panel .msg-compose-grid label,.msg-reply-panel .msg-compose-grid #new-recipient,.msg-reply-panel .msg-compose-grid #compose-subject{display:none}
  .msg-reply-panel .msg-compose-grid textarea.form-control{grid-column:1;min-height:46px;max-height:130px;resize:vertical;border-radius:12px;background:#f8fafc}
  .msg-reply-panel .msg-compose-actions{grid-column:2;align-self:end;margin:0}
  .msg-reply-panel .msg-compose-actions .msg-btn-secondary{display:none}
  @media(max-width:900px){.messages-layout,.messages-layout.is-admin-list{grid-template-columns:minmax(250px,.8fr) minmax(0,1.2fr)}.msg-inbox-panel>.msg-panel-header{padding:18px 15px 14px}.msg-item{padding:14px 13px}.msg-compose-panel>.msg-panel-header,.msg-compose-panel .msg-compose-body{padding-right:20px;padding-left:20px}}
  @media(max-width:700px){.msg-page-heading{align-items:flex-start}.msg-heading-count{font-size:11px}.messages-layout,.messages-layout.is-admin-list{grid-template-columns:1fr;min-height:0;overflow:visible}.msg-inbox-panel{max-height:360px;border-right:0;border-bottom:1px solid var(--msg-line)}.msg-list{max-height:280px}.msg-compose-panel{min-height:450px}.msg-detail-shell{height:calc(100dvh - 260px);min-height:420px}.msg-detail-header{height:58px;padding:10px 14px}.msg-detail-heading{gap:10px;padding:12px 14px}.msg-detail-subject{font-size:13px}.msg-detail-avatar{width:36px;height:36px;flex-basis:36px}.msg-history{padding:18px 12px}.msg-history-entry{max-width:88%}.msg-reply-panel .msg-compose-body{padding:10px}.msg-reply-panel form{grid-template-columns:minmax(0,1fr) auto;gap:8px}.msg-reply-panel .msg-compose-grid textarea.form-control{min-width:0}.msg-reply-panel .msg-compose-actions .msg-btn{min-height:44px;padding:9px 13px}.msg-detail-header .msg-meta{width:auto;margin:0;font-size:10px}}
  @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto!important}.messages-workspace *{scroll-behavior:auto!important;transition:none!important}}
</style>

<div class="messages-workspace">
  <header class="msg-page-heading">
    <div>
      <h2>Messages</h2>
      <p><?php echo $role === 'admin' ? 'Private conversations across the RMS community.' : ($role === 'faculty' ? 'Private messages and adviser request conversations.' : ($role === 'student' ? 'Private messages with the administrator and your adviser.' : 'Your private conversation with the RMS administrator.')); ?></p>
    </div>
    <?php if (!$has_conversation_detail): ?><div class="msg-heading-count"><strong><?php echo (int) $unread_count; ?></strong>unread</div><?php endif; ?>
  </header>
  <section class="msg-hero" aria-labelledby="messages-hero-title">
    <div class="msg-hero-copy">
      <div class="msg-kicker">RMS correspondence &middot; Private channel</div>
      <h2 id="messages-hero-title"><?php
        if ($has_conversation_detail) {
            echo 'Reply in context.';
        } elseif ($role === 'admin') {
            echo 'Start a conversation.';
        } else {
            echo 'A direct line to your administrator.';
        }
      ?></h2>
      <p><?php
        if ($has_conversation_detail) {
            echo 'Read the conversation history and reply to keep the discussion together.';
        } elseif ($role === 'admin') {
            echo 'Message an active RMS user, or narrow incoming conversations by name and role.';
        } else {
            echo 'Send a private message to the administrator and follow replies in this inbox.';
        }
      ?></p>
      <div class="msg-hero-actions">
        <?php if ($has_conversation_detail): ?>
          <a class="msg-btn msg-btn-hero" href="<?php echo msg_se(((int) $detail['recipient_id'] === $user_id) ? $tab_inbox_url : $tab_sent_url); ?>">Return to messages</a>
          <a class="msg-btn msg-btn-primary" href="#compose">Write a reply <span aria-hidden="true">&#8594;</span></a>
        <?php else: ?>
          <a class="msg-btn msg-btn-primary" href="#compose">Compose a message <span aria-hidden="true">&#8594;</span></a>
          <a class="msg-btn msg-btn-hero" href="<?php echo msg_se($current_view === 'sent' ? $tab_inbox_url : $tab_sent_url); ?>"><?php echo $current_view === 'sent' ? 'Open inbox' : 'Review sent messages'; ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="msg-stat-stack" aria-label="Message activity">
      <div class="msg-stat"><span class="msg-stat-code">01</span><span class="msg-stat-label">Unread now</span><strong class="msg-stat-value"><?php echo (int) $unread_count; ?></strong></div>
      <div class="msg-stat"><span class="msg-stat-code">02</span><span class="msg-stat-label"><?php echo $has_conversation_detail ? 'Open message' : 'In this view'; ?></span><strong class="msg-stat-value"><?php echo $has_conversation_detail ? '1' : (int) $visible_count; ?></strong></div>
      <div class="msg-stat"><span class="msg-stat-code">03</span><span class="msg-stat-label"><?php echo $role === 'admin' ? 'Available recipients' : 'Admin contact'; ?></span><strong class="msg-stat-value"><?php echo (int) ($role === 'admin' ? count($admin_compose_recipients) : $recipient_count); ?></strong></div>
    </div>
  </section>

  <?php if (!$has_conversation_detail): ?>
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
      <?php if ($role !== 'admin'): ?><a class="msg-btn msg-btn-secondary" href="#compose">New message</a><?php endif; ?>
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
    $conversation_started = $history_rows
        ? date('M d, Y \\a\\t h:i A', strtotime((string) $history_rows[0]['created_at']))
        : $created_abs;
$back_url    = $is_inbox_message ? $tab_inbox_url : $tab_sent_url;
if ($role === 'admin' && $is_inbox_message && ($admin_user_filter !== '' || $admin_role_filter !== '')) {
    $back_params = [];
    if ($admin_user_filter !== '') $back_params['user'] = $admin_user_filter;
    if ($admin_role_filter !== '') $back_params['role'] = $admin_role_filter;
    $back_url .= '?' . http_build_query($back_params);
}
  ?>
  <div class="msg-detail-shell">
  <div class="msg-detail-header">
    <a class="msg-detail-back" href="<?php echo msg_se($back_url); ?>">&larr; Back to <?php echo $is_inbox_message ? 'inbox' : 'sent'; ?></a>
    <span class="msg-meta">
      <?php echo count($history_rows); ?> messages in this conversation
    </span>
  </div>

  <article class="msg-detail-card">
    <div class="msg-detail-heading">
      <div class="msg-detail-meta">
        <div class="msg-detail-avatar"><?php echo msg_se($initials !== '' ? $initials : '?'); ?></div>
        <div class="msg-detail-person">
          <div class="msg-detail-name">
            <?php echo msg_se($other_name); ?>
            <?php echo msg_role_badge($other_role); ?>
          </div>
          <div class="msg-detail-line"><?php echo msg_se(msg_role_meta($other_role)[0]); ?></div>
        </div>
      </div>
      <h1 class="msg-detail-subject"><?php echo msg_se($detail['subject']); ?></h1>
    </div>

    <section class="msg-history" aria-label="Conversation history">
      <h2 class="msg-history-title">Conversation started <?php echo msg_se($conversation_started); ?></h2>
      <div class="msg-history-list">
        <?php foreach ($history_rows as $history):
          $history_sender = trim((string) $history['sender_first'] . ' ' . (string) $history['sender_last']);
          $history_date = date('M d, Y \\a\\t h:i A', strtotime((string) $history['created_at']));
        ?>
          <article class="msg-history-entry<?php echo (int) $history['sender_id'] === $user_id ? ' is-mine' : ''; ?>">
            <div class="msg-history-meta">
              <span class="msg-history-sender">
                <?php echo msg_se($history_sender !== '' ? $history_sender : 'Unknown user'); ?>
                <?php echo msg_role_badge((string) $history['sender_role']); ?>
              </span>
              <time class="msg-history-time" datetime="<?php echo msg_se($history['created_at']); ?>"><?php echo msg_se($history_date); ?></time>
            </div>
            <p class="msg-history-message"><?php echo msg_se($history['message']); ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

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
          <input type="hidden" name="thread_message_id" value="<?php echo (int) $detail['message_id']; ?>">
          <div class="msg-compose-grid">
            <label for="new-recipient">Recipient</label>
            <input class="form-control" id="new-recipient" type="text" readonly
                   value="<?php echo msg_se($other_name . ' — ' . msg_role_meta($other_role)[0]); ?>">

            <label for="compose-subject">Subject</label>
            <input class="form-control" id="compose-subject" type="text" name="subject" maxlength="160" required
                   value="<?php echo msg_se($compose_subject); ?>">

            <label for="compose-message">Message</label>
            <textarea class="form-control" id="compose-message" name="message" rows="2" maxlength="5000" required
                      aria-label="Write a message"
                      placeholder="Write a message…"><?php echo msg_se($compose_body); ?></textarea>
          </div>
          <div class="msg-compose-actions">
          <button type="submit" class="msg-btn msg-btn-primary" aria-label="Send reply">Send</button>
            <a class="msg-btn msg-btn-secondary" href="<?php echo msg_se($back_url); ?>">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  </div>
  <script>
  (function () {
    const history = document.querySelector('.msg-history');
    if (history) history.scrollTop = history.scrollHeight;
  }());
  </script>

<?php elseif ($is_adviser_request_detail && $adviser_request): ?>
  <?php
    $is_student_requester = $user_id === (int) $adviser_request['requester_id'];
    $request_partner_name = trim($is_student_requester
        ? (string) $adviser_request['faculty_first'] . ' ' . (string) $adviser_request['faculty_last']
        : (string) $adviser_request['student_first'] . ' ' . (string) $adviser_request['student_last']);
    $request_partner_role = $is_student_requester
        ? (string) $adviser_request['faculty_role']
        : (string) $adviser_request['student_role'];
    $request_partner_parts = preg_split('/\s+/u', trim($request_partner_name)) ?: [];
    $request_partner_initials = '';
    foreach ($request_partner_parts as $request_partner_part) {
        if ($request_partner_part !== '') {
            $request_partner_initials .= mb_strtoupper(mb_substr($request_partner_part, 0, 1, 'UTF-8'), 'UTF-8');
        }
        if (mb_strlen($request_partner_initials, 'UTF-8') >= 2) {
            break;
        }
    }
    $request_back_url = SITE_URL . 'pages/shared/messages.php';
  ?>
  <div class="msg-detail-shell">
    <div class="msg-detail-header">
      <a class="msg-detail-back" href="<?php echo msg_se($request_back_url); ?>">&larr; Back to inbox</a>
      <span class="msg-meta"><?php echo count($adviser_request_history); ?> messages &middot; Adviser request <?php echo msg_se(ucfirst((string) $adviser_request['status'])); ?></span>
    </div>
    <article class="msg-detail-card">
      <div class="msg-detail-heading">
        <div class="msg-detail-meta">
          <div class="msg-detail-avatar" aria-hidden="true"><?php echo msg_se($request_partner_initials !== '' ? $request_partner_initials : '?'); ?></div>
          <div class="msg-detail-person">
            <div class="msg-detail-name">
              <?php echo msg_se($request_partner_name !== '' ? $request_partner_name : 'Project participant'); ?>
              <?php echo msg_role_badge($request_partner_role); ?>
            </div>
            <div class="msg-detail-line">Private adviser request</div>
            <?php if (in_array($role, ['admin', 'faculty'], true)): ?>
              <div class="msg-detail-line">Course: <?php echo msg_se(trim((string) ($adviser_request['student_program'] ?? '')) ?: 'Not provided'); ?> &middot; Year: <?php echo msg_se(trim((string) ($adviser_request['student_year_level'] ?? '')) ?: 'Not provided'); ?></div>
            <?php endif; ?>
          </div>
        </div>
        <h1 class="msg-detail-subject"><?php echo msg_se((string) $adviser_request['project_title']); ?></h1>
      </div>
      <section class="msg-history" aria-label="Adviser request conversation">
        <?php if ($adviser_request_history): ?>
          <h2 class="msg-history-title">Conversation started <?php echo msg_se(date('M d, Y \\a\\t h:i A', strtotime((string) $adviser_request_history[0]['created_at']))); ?></h2>
          <div class="msg-history-list" role="log" aria-live="polite" aria-relevant="additions text">
            <?php foreach ($adviser_request_history as $request_message):
                $request_sender_name = trim((string) $request_message['first_name'] . ' ' . (string) $request_message['last_name']);
                $request_message_date = date('M d, Y \\a\\t h:i A', strtotime((string) $request_message['created_at']));
            ?>
              <article class="msg-history-entry<?php echo (int) $request_message['sender_id'] === $user_id ? ' is-mine' : ''; ?>">
                <div class="msg-history-meta">
                  <span class="msg-history-sender"><?php echo msg_se($request_sender_name !== '' ? $request_sender_name : 'Project participant'); ?></span>
                  <time class="msg-history-time" datetime="<?php echo msg_se($request_message['created_at']); ?>"><?php echo msg_se($request_message_date); ?></time>
                </div>
                <p class="msg-history-message"><?php echo msg_se($request_message['message']); ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="msg-empty">
            <div class="msg-empty-icon">AR</div>
            <div class="msg-empty-title">Start the conversation</div>
            <div class="msg-empty-subtitle">Send a private message about this adviser request.</div>
          </div>
        <?php endif; ?>
      </section>
    </article>
    <?php if ($role === 'admin'): ?>
      <p class="msg-chat-closed" role="note">Read-only admin view. Only the student and faculty adviser can send messages.</p>
    <?php elseif ((string) $adviser_request['status'] !== 'declined'): ?>
      <section class="msg-panel msg-reply-panel" id="compose" aria-label="Write a message">
        <div class="msg-compose-body">
          <form method="post" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php?request_id=' . (int) $adviser_request_id); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="send_adviser_request_message">
            <input type="hidden" name="request_id" value="<?php echo (int) $adviser_request_id; ?>">
            <div class="msg-compose-grid">
              <label for="compose-message">Message</label>
              <textarea class="form-control" id="compose-message" name="message" rows="2" maxlength="5000" required
                        aria-label="Write a message" placeholder="Write a message…"></textarea>
            </div>
            <div class="msg-compose-actions"><button type="submit" class="msg-btn msg-btn-primary" aria-label="Send message">Send</button></div>
          </form>
        </div>
      </section>
    <?php else: ?>
      <p class="msg-chat-closed" role="status">Messaging is closed for this declined adviser request.</p>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    const history = document.querySelector('.msg-history');
    if (history) history.scrollTop = history.scrollHeight;
  }());
  </script>

<?php else: ?>

  <div class="messages-layout<?= $role === 'admin' ? ' is-admin-list' : '' ?>">

  <!-- Compose form -->
  <?php if ($role !== 'admin'): ?>
  <section class="msg-panel msg-compose-panel" id="compose">
    <div class="msg-panel-header">
      <div>
        <div class="msg-panel-eyebrow">Direct correspondence</div>
        <h3 class="msg-panel-title">Compose a message</h3>
        <p class="msg-panel-copy">Write privately to the administrator.</p>
      </div>
    </div>
    <div class="msg-compose-body">
      <?php if (!$recipients): ?>
        <p class="msg-panel-copy">No active administrator is available to receive messages.</p>
      <?php else: ?>
        <form method="post" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php'); ?>">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_message">
          <input type="hidden" name="recipient_id" value="<?php echo (int) $admin_recipient_id; ?>">
          <div class="msg-compose-grid">
            <label for="new-recipient">Recipient</label>
            <input class="form-control" id="new-recipient" type="text" value="Administrator" readonly>

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
          <p class="msg-compose-note">The administrator will also receive an RMS notification.</p>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($role === 'admin'): ?>
  <section class="msg-panel msg-compose-panel" id="compose">
    <div class="msg-panel-header">
      <div>
        <div class="msg-panel-eyebrow">Direct correspondence</div>
        <h3 class="msg-panel-title">Compose a message</h3>
        <p class="msg-panel-copy">Start a private conversation with an active RMS user.</p>
      </div>
    </div>
    <div class="msg-compose-body">
      <?php if (!$admin_compose_recipients): ?>
        <p class="msg-panel-copy">There are no other active users available to message.</p>
      <?php else: ?>
        <form method="post" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php'); ?>">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="send_message">
          <div class="msg-compose-grid">
            <label for="admin-new-recipient">Recipient</label>
            <div class="msg-recipient-filters">
              <div class="msg-recipient-filter-field">
                <label for="admin-recipient-search">Search users</label>
                <input id="admin-recipient-search" type="search" autocomplete="off" placeholder="Type a name">
              </div>
              <div class="msg-recipient-filter-field">
                <label for="admin-recipient-role-filter">Filter by role</label>
                <select id="admin-recipient-role-filter">
                  <option value="">All roles</option>
                  <?php foreach ($admin_recipient_roles as $recipient_role): ?>
                    <option value="<?php echo msg_se($recipient_role); ?>"><?php echo msg_se(msg_role_meta($recipient_role)[0]); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="msg-recipient-filter-field">
                <label for="admin-recipient-course-filter">Filter by course</label>
                <select id="admin-recipient-course-filter">
                  <option value="">All courses</option>
                  <?php foreach ($admin_recipient_courses as $recipient_course): ?>
                    <option value="<?php echo msg_se($recipient_course); ?>"><?php echo msg_se($recipient_course); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <select class="form-control" id="admin-new-recipient" name="recipient_id" required>
              <option value="">Choose a user</option>
              <?php foreach ($admin_compose_recipients as $recipient):
                $recipient_name = trim((string) $recipient['first_name'] . ' ' . (string) $recipient['last_name']);
              ?>
                <?php
                  $recipient_course_codes = [];
                  if (($recipient['role'] ?? '') === 'student') {
                      $student_course = trim((string) ($recipient['student_course_code'] ?? ''));
                      if ($student_course === '') $student_course = trim((string) ($recipient['program'] ?? ''));
                      if ($student_course !== '') $recipient_course_codes[] = mb_strtoupper($student_course);
                  } elseif (($recipient['role'] ?? '') === 'faculty') {
                      $recipient_course_codes = array_filter(array_map('trim', explode(',', (string) ($recipient['faculty_program_codes'] ?? ''))));
                      $recipient_course_codes = array_map('mb_strtoupper', $recipient_course_codes);
                  }
                  $recipient_courses_data = '|' . implode('|', $recipient_course_codes) . '|';
                ?>
                <option value="<?php echo (int) $recipient['user_id']; ?>" data-name="<?php echo msg_se($recipient_name); ?>" data-role="<?php echo msg_se((string) $recipient['role']); ?>" data-courses="<?php echo msg_se($recipient_courses_data); ?>" <?php echo $compose_recipient_id === (int) $recipient['user_id'] ? 'selected' : ''; ?>>
                  <?php echo msg_se($recipient_name . ' — ' . msg_role_meta((string) $recipient['role'])[0]); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="msg-recipient-result" id="admin-recipient-result" aria-live="polite"><?php echo count($admin_compose_recipients); ?> active users available.</p>

            <label for="admin-new-subject">Subject</label>
            <input class="form-control" id="admin-new-subject" type="text" name="subject" maxlength="160" required
                   value="<?php echo msg_se($compose_subject); ?>" placeholder="Enter a subject">

            <label for="admin-new-message">Message</label>
            <textarea class="form-control" id="admin-new-message" name="message" rows="5" maxlength="5000" required
                      placeholder="Write your message…"><?php echo msg_se($compose_body); ?></textarea>
          </div>
          <div class="msg-compose-actions">
            <button type="submit" class="msg-btn msg-btn-primary">Send message</button>
            <button type="reset" class="msg-btn msg-btn-secondary">Clear</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- List view (Inbox or Sent) -->
  <section class="msg-panel msg-inbox-panel">
    <div class="msg-panel-header">
      <div>
        <div class="msg-panel-eyebrow"><?php echo $current_view === 'sent' ? 'Dispatch record' : 'Incoming correspondence'; ?></div>
        <h3 class="msg-panel-title"><?php echo $current_view === 'sent' ? 'Sent messages' : 'Inbox'; ?></h3>
        <p class="msg-panel-copy">
          <?php if ($current_view === 'sent'): ?>
            Messages you have sent to the administrator.
          <?php else: ?>
            Newest first &middot; unread correspondence is highlighted.
          <?php endif; ?>
        </p>
      </div>
      <span class="msg-panel-count"><?php echo (int) $visible_count; ?> SHOWN</span>
    </div>
    <?php if ($role === 'admin' && $current_view === 'inbox'): ?>
      <form class="msg-user-filter" method="get" action="<?php echo msg_se(SITE_URL . 'pages/shared/messages.php'); ?>">
        <label for="message-user-filter">Filter by user</label>
        <div class="msg-user-filter-controls">
          <input id="message-user-filter" name="user" type="search" maxlength="100" value="<?php echo msg_se($admin_user_filter); ?>" placeholder="Search sender name">
          <label class="msg-sr-only" for="message-role-filter">Filter by sender role</label>
          <select id="message-role-filter" name="role">
            <option value="">All roles</option>
            <?php foreach ($allowed_message_roles as $filter_role): ?>
              <option value="<?php echo msg_se($filter_role); ?>" <?php echo $admin_role_filter === $filter_role ? 'selected' : ''; ?>><?php echo msg_se(msg_role_meta($filter_role)[0]); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="msg-btn msg-btn-primary" type="submit">Search</button>
          <?php if ($admin_user_filter !== '' || $admin_role_filter !== ''): ?><a class="msg-btn msg-btn-secondary" href="<?php echo msg_se($tab_inbox_url); ?>">Clear</a><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
    <div>
      <?php if (!$rows): ?>
        <div class="msg-empty">
          <div class="msg-empty-icon"><?php echo $current_view === 'sent' ? 'OUT' : 'IN'; ?></div>
          <div class="msg-empty-title">
            <?php echo $current_view === 'sent' ? 'No sent messages yet' : 'Your inbox is empty'; ?>
          </div>
          <div class="msg-empty-subtitle">
            <?php echo $current_view === 'sent'
                ? 'Messages you send to the administrator will appear here.'
                : (in_array($role, ['student', 'faculty'], true)
                    ? 'New messages and adviser request conversations will appear here.'
                    : 'New messages from the RMS community will show up here.'); ?>
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
              $is_adviser_request_row = ($row['message_type'] ?? '') === 'adviser_request';
              $detail_url = $is_adviser_request_row
                  ? SITE_URL . 'pages/shared/messages.php?request_id=' . (int) $row['request_id']
                  : SITE_URL . 'pages/shared/messages.php?id=' . $mid;
              if (!$is_adviser_request_row && $role === 'admin' && $current_view === 'inbox' && ($admin_user_filter !== '' || $admin_role_filter !== '')) {
                  $detail_params = [];
                  if ($admin_user_filter !== '') $detail_params['user'] = $admin_user_filter;
                  if ($admin_role_filter !== '') $detail_params['role'] = $admin_role_filter;
                  $detail_url .= '&' . http_build_query($detail_params);
              }
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
                  <?php if ($is_adviser_request_row): ?><span class="msg-context-badge">Adviser request</span><?php endif; ?>
                </div>
                <div class="msg-subject"><?php echo msg_se($subject); ?></div>
                <div class="msg-snippet"><?php echo msg_se($snippet !== '' ? $snippet : ($is_adviser_request_row ? 'No messages yet — open to start the conversation.' : '')); ?></div>
                <?php if ($is_adviser_request_row && (string) $row['request_status'] === 'declined'): ?><span class="msg-context-badge">Closed</span><?php endif; ?>
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

<?php if ($role === 'admin' && !$is_detail): ?>
<script>
(function () {
  const search = document.getElementById('admin-recipient-search');
  const roleFilter = document.getElementById('admin-recipient-role-filter');
  const courseFilter = document.getElementById('admin-recipient-course-filter');
  const recipientSelect = document.getElementById('admin-new-recipient');
  const result = document.getElementById('admin-recipient-result');
  if (!search || !roleFilter || !courseFilter || !recipientSelect || !result) return;

  const recipientOptions = Array.from(recipientSelect.options).slice(1);
  const filterRecipients = function () {
    const searchValue = search.value.trim().toLocaleLowerCase();
    const selectedRole = roleFilter.value;
    const selectedCourse = courseFilter.value;
    let visibleCount = 0;

    recipientOptions.forEach(function (option) {
      const nameMatches = (option.dataset.name || '').toLocaleLowerCase().includes(searchValue);
      const roleMatches = selectedRole === '' || option.dataset.role === selectedRole;
      const courseMatches = selectedCourse === '' || (option.dataset.courses || '').split('|').includes(selectedCourse);
      option.hidden = !(nameMatches && roleMatches && courseMatches);
      if (!option.hidden) visibleCount += 1;
    });

    const selectedOption = recipientSelect.options[recipientSelect.selectedIndex];
    if (selectedOption && selectedOption.hidden) recipientSelect.value = '';
    result.textContent = visibleCount === 0
      ? 'No active users match these filters.'
      : visibleCount + ' active user' + (visibleCount === 1 ? '' : 's') + ' available.';
  };

  search.addEventListener('input', filterRecipients);
  roleFilter.addEventListener('change', filterRecipients);
  courseFilter.addEventListener('change', filterRecipients);
})();
</script>
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
