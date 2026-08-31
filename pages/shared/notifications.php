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
    $map = [
        'info'    => ['#2563EB', 'rgba(37,99,235,0.10)',  'rgba(37,99,235,0.25)'],
        'success' => ['#16A34A', 'rgba(22,163,74,0.10)',  'rgba(22,163,74,0.25)'],
        'warning' => ['#EA580C', 'rgba(234,88,12,0.10)',  'rgba(234,88,12,0.25)'],
        'error'   => ['#EF4444', 'rgba(239,68,68,0.10)',  'rgba(239,68,68,0.25)'],
    ];
    $pair = $map[$type] ?? $map['info'];
    $label = ucfirst((string) ($type !== '' ? $type : 'info'));
    return '<span style="display:inline-block;font-size:12px;font-weight:500;'
         . 'padding:3px 10px;border-radius:9999px;'
         . 'background:' . $pair[1] . ';color:' . $pair[0] . ';'
         . 'border:1px solid ' . $pair[2] . ';">'
         . notif_se($label) . '</span>';
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
foreach ($rows as $r) {
    if ((int) $r['is_read'] === 0) {
        $unread_count++;
    }
}

// ---------------------------------------------------------------
// Flash message helper (mirrors profile.php).
// ---------------------------------------------------------------
function notif_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $color = $type === 'error' ? '#ef4444' : '#22c55e';
        echo '<div style="margin-bottom:20px;padding:14px 18px;border-left:4px solid ' . $color .
             ';background:#fff;color:#334155;border-radius:10px;">' .
             notif_se($message) . '</div>';
    }
}

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors profile.php and module-page.php.
// ---------------------------------------------------------------
$page_title    = 'Notifications';
$page_subtitle = $unread_count > 0
    ? "You have {$unread_count} unread notification" . ($unread_count === 1 ? '' : 's') . '.'
    : 'You are all caught up.';

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
  .notif-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .notif-summary-counts {
    font-size: 14px;
    color: #64748B;
  }
  .notif-summary-counts strong { color: #111827; }

  .notif-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .notif-item {
    display: grid;
    grid-template-columns: 28px 1fr auto;
    gap: 14px;
    align-items: start;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .notif-item:hover {
    border-color: rgba(91,30,188,0.25);
    box-shadow: 0 1px 6px rgba(91,30,188,0.08);
  }
  .notif-item.is-unread {
    background: rgba(91,30,188,0.03);
    border-color: rgba(91,30,188,0.18);
  }
  .notif-dot {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: #5B1EBC;
    margin-top: 7px;
  }
  .notif-item.is-read .notif-dot { background: transparent; }

  .notif-body { min-width: 0; }
  .notif-title {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 4px;
    line-height: 1.3;
  }
  .notif-item.is-read .notif-title { font-weight: 500; }
  .notif-message {
    font-size: 14px;
    color: #475569;
    line-height: 1.5;
    margin: 0 0 8px;
    word-wrap: break-word;
  }
  .notif-meta {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 12px;
    color: #94A3B8;
  }
  .notif-time { font-weight: 500; }
  .notif-absolute { color: #94A3B8; }

  .notif-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .notif-action-form { display: inline; margin: 0; }

  .notif-link {
    color: #1d4ed8;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
  }
  .notif-link:hover { text-decoration: underline; }

  .notif-empty {
    text-align: center;
    padding: 64px 24px;
    color: #64748B;
  }
  .notif-empty-icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
  .notif-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
  }
  .notif-empty-subtitle { font-size: 14px; color: #64748B; }

  .notif-truncated-note {
    margin-top: 14px;
    text-align: center;
    font-size: 13px;
    color: #94A3B8;
  }

  @media (max-width: 640px) {
    .notif-item {
      grid-template-columns: 20px 1fr;
    }
    .notif-actions {
      grid-column: 1 / -1;
      justify-content: flex-start;
      margin-top: 4px;
    }
  }
</style>

<?php if (!$rows): ?>
  <div class="card">
    <div class="notif-empty">
      <div class="notif-empty-icon">🔔</div>
      <div class="notif-empty-title">No notifications yet</div>
      <div class="notif-empty-subtitle">When something happens on your account &mdash; new reviews, messages, status changes &mdash; you will see it here.</div>
    </div>
  </div>
<?php else: ?>
  <div class="notif-summary">
    <div class="notif-summary-counts">
      Showing <strong><?php echo (int) $total_count; ?></strong> most recent
      &middot; <strong><?php echo (int) $unread_count; ?></strong> unread
    </div>
    <?php if ($unread_count > 0): ?>
      <form method="post" class="notif-action-form" onsubmit="return true;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button class="btn btn-primary btn-sm" type="submit">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

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
    ?>
      <div class="notif-item <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>">
        <div class="notif-dot" aria-hidden="true"></div>
        <div class="notif-body">
          <div class="notif-title"><?php echo notif_se($title); ?></div>
          <p class="notif-message"><?php echo nl2br(notif_se($message)); ?></p>
          <div class="notif-meta">
            <?php echo notif_type_badge($type); ?>
            <span class="notif-time" title="<?php echo notif_se($abs); ?>"><?php echo notif_se($rel); ?></span>
            <?php if ($abs !== ''): ?>
              <span class="notif-absolute">&middot; <?php echo notif_se($abs); ?></span>
            <?php endif; ?>
            <?php if ($link !== ''): ?>
              <a class="notif-link" href="<?php echo notif_se(SITE_URL . ltrim($link, '/')); ?>" target="_blank" rel="noopener">Open &rarr;</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="notif-actions">
          <?php if ($is_unread): ?>
            <form method="post" class="notif-action-form">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="notification_id" value="<?php echo $nid; ?>">
              <button class="btn btn-secondary btn-sm" type="submit">Mark read</button>
            </form>
          <?php endif; ?>
          <form method="post" class="notif-action-form"
                onsubmit="return confirm('Delete this notification? This cannot be undone.');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notification_id" value="<?php echo $nid; ?>">
            <button class="btn btn-secondary btn-sm" type="submit"
                    style="color:#EF4444;border-color:rgba(239,68,68,0.25);">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($total_count >= $NOTIF_LIMIT): ?>
    <div class="notif-truncated-note">
      Showing the <?php echo (int) $NOTIF_LIMIT; ?> most recent notifications. Older notifications are hidden &mdash; mark some as read to keep your inbox tidy.
    </div>
  <?php endif; ?>
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
