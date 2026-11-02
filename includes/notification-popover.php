<?php
/**
 * Shared authenticated notification popover.
 *
 * The current user's latest notifications are rendered by the role shells;
 * state changes are posted through the existing shared notifications page.
 */

if (!function_exists('renderNotificationPopover')) {
    function renderNotificationPopover($user_id, $unread_count, $role) {
        global $conn;

        $user_id = (int) $user_id;
        $unread_count = max(0, (int) $unread_count);
        $role = in_array($role, ['admin', 'staff', 'faculty', 'student'], true) ? $role : 'student';
        $notifications_url = SITE_URL . 'pages/shared/notifications.php';
        $rows = [];
        $unread_rows = [];

        if (isset($conn) && $user_id > 0) {
            foreach ([false, true] as $only_unread) {
                $filter_sql = $only_unread ? ' AND is_read = 0' : '';
                $stmt = $conn->prepare(
                    'SELECT notification_id, title, message, link, is_read, created_at
                       FROM notifications
                      WHERE user_id = ?' . $filter_sql . '
                   ORDER BY created_at DESC, notification_id DESC
                      LIMIT 5'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $user_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $loaded_rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                        if ($only_unread) {
                            $unread_rows = $loaded_rows;
                        } else {
                            $rows = $loaded_rows;
                        }
                    }
                    $stmt->close();
                }
            }
        }

        $escape = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $button_label = $unread_count > 0
            ? 'Notifications, ' . $unread_count . ' unread'
            : 'Notifications';
        ?>
        <div class="rms-notification rms-notification--<?php echo $role; ?>" data-notification-popover>
          <button type="button"
                  class="<?php echo $role === 'admin' ? 'admin-topbar-icon-link ' : ''; ?><?php echo $role; ?>-topbar-notification rms-notification-toggle"
                  data-notification-toggle
                  aria-label="<?php echo $escape($button_label); ?>"
                  title="<?php echo $escape($button_label); ?>"
                  aria-haspopup="dialog"
                  aria-expanded="false"
                  aria-controls="rms-notification-panel">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <?php if ($unread_count > 0): ?>
              <span class="<?php echo $role === 'admin' ? 'admin-topbar-badge' : $role . '-topbar-notification-badge'; ?>" aria-hidden="true"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
            <?php endif; ?>
          </button>

          <section class="rms-notification-panel" id="rms-notification-panel" data-notification-panel role="dialog" aria-labelledby="rms-notification-title" hidden>
            <header class="rms-notification-header">
              <h2 id="rms-notification-title">Notifications</h2>
              <form method="post" action="<?php echo $escape($notifications_url); ?>" class="rms-notification-menu-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="rms-notification-menu" aria-label="Mark all notifications as read" title="Mark all as read">•••</button>
              </form>
            </header>

            <div class="rms-notification-filters" role="group" aria-label="Filter notifications">
              <button type="button" class="is-active" data-notification-filter="all" aria-pressed="true">All</button>
              <button type="button" data-notification-filter="unread" aria-pressed="false">Unread<?php if ($unread_count > 0): ?> <span><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span><?php endif; ?></button>
            </div>

            <div class="rms-notification-section-heading">
              <h3>Recent</h3>
              <a href="<?php echo $escape($notifications_url); ?>">View all <span aria-hidden="true">›</span></a>
            </div>

            <ul class="rms-notification-list" data-notification-list>
              <?php foreach ([['rows' => $rows, 'view' => 'all'], ['rows' => $unread_rows, 'view' => 'unread']] as $notification_view): ?>
                <?php foreach ($notification_view['rows'] as $row): ?>
                <?php
                  $is_unread = (int) ($row['is_read'] ?? 0) === 0;
                  $created_at = strtotime((string) ($row['created_at'] ?? ''));
                  $time_label = $created_at === false ? '' : date('H:i', $created_at);
                  $notification_id = (int) ($row['notification_id'] ?? 0);
                  $has_link = trim((string) ($row['link'] ?? '')) !== '';
                  $item_action = $has_link ? 'open_record' : 'mark_read';
                ?>
                <li class="rms-notification-item<?php echo $is_unread ? ' is-unread' : ''; ?>"
                    data-notification-item
                    data-notification-view="<?php echo $notification_view['view']; ?>"<?php echo $notification_view['view'] === 'unread' ? ' hidden' : ''; ?>>
                  <?php if ($has_link || $is_unread): ?>
                    <form method="post" action="<?php echo $escape($notifications_url); ?>" class="rms-notification-item-form">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="<?php echo $item_action; ?>">
                      <input type="hidden" name="notification_id" value="<?php echo $notification_id; ?>">
                      <button type="submit" class="rms-notification-item-button" aria-label="<?php echo $escape(($has_link ? 'Open ' : 'Mark as read: ') . ($row['title'] ?? 'Notification')); ?>">
                        <span class="rms-notification-dot" aria-hidden="true"></span>
                        <span class="rms-notification-copy">
                          <span class="rms-notification-title"><?php echo $escape($row['title'] ?? 'Notification'); ?></span>
                          <span class="rms-notification-message"><?php echo $escape($row['message'] ?? ''); ?></span>
                        </span>
                        <?php if ($time_label !== ''): ?><time class="rms-notification-time" datetime="<?php echo $escape($row['created_at'] ?? ''); ?>"><?php echo $escape($time_label); ?></time><?php endif; ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <div class="rms-notification-item-static">
                      <span class="rms-notification-dot" aria-hidden="true"></span>
                      <span class="rms-notification-copy">
                        <span class="rms-notification-title"><?php echo $escape($row['title'] ?? 'Notification'); ?></span>
                        <span class="rms-notification-message"><?php echo $escape($row['message'] ?? ''); ?></span>
                      </span>
                      <?php if ($time_label !== ''): ?><time class="rms-notification-time" datetime="<?php echo $escape($row['created_at'] ?? ''); ?>"><?php echo $escape($time_label); ?></time><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              <?php endforeach; ?>
              <?php if (!$rows): ?><li class="rms-notification-empty" data-notification-empty>No notifications yet.</li><?php endif; ?>
              <?php if (!$unread_rows): ?><li class="rms-notification-empty" data-notification-no-unread hidden>No unread notifications.</li><?php endif; ?>
            </ul>
          </section>
        </div>
        <?php
    }
}
