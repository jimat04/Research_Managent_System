<?php
/**
 * Student Shell — shared sidebar, topbar, and page-content wrapper for Students.
 *
 * Usage at the top of every student page (after requireRole, before any HTML output):
 *
 *     require_once __DIR__ . '/../../includes/student-shell.php';
 *     $user = getCurrentUser();
 *     renderStudentShell($user, 'student-dashboard', 'Student Dashboard', 'Track your research submissions.');
 *     ... page body ...
 *     renderStudentShellClose();
 *
 * renderStudentShell() opens <!DOCTYPE>, <head>, the dashboard wrapper, the sidebar,
 * the main area, the topbar, and <div class="student-page-content">.
 * renderStudentShellClose() emits the matching closing tags plus </body></html>.
 *
 * All navigation, CSS, and logout URLs are built from SITE_URL (defined in
 * includes/config.php with a trailing slash) so the same sidebar works from
 * any directory — /pages/student/, /pages/shared/, etc. — without 404s.
 *
 * @param array  $user          Current user row from getCurrentUser()
 * @param string $current_page  Current page slug (e.g. 'student-dashboard', 'my-research.php', 'messages.php')
 * @param string $page_title    Title shown in the topbar <h1>
 * @param string $page_subtitle Subtitle shown under the title (pass '' for none)
 */
function renderStudentShell($user, $current_page, $page_title, $page_subtitle = '') {
    // Normalise the user object
    $shell_user = [
        'first_name' => $user['first_name'] ?? '',
        'last_name'  => $user['last_name']  ?? '',
        'role'       => $user['role']       ?? 'student',
    ];
    if (empty($shell_user['first_name']) && empty($shell_user['last_name']) && !empty($user['name'])) {
        $parts = explode(' ', (string) $user['name'], 2);
        $shell_user['first_name'] = $parts[0] ?? '';
        $shell_user['last_name']  = $parts[1] ?? '';
    }

    $initials = strtoupper(
        substr($shell_user['first_name'], 0, 1) .
        substr($shell_user['last_name'],  0, 1)
    );
    if ($initials === '') {
        $initials = 'ST';
    }

    $full_name = trim($shell_user['first_name'] . ' ' . $shell_user['last_name']);
    if ($full_name === '') {
        $full_name = 'Student';
    }

    $role_label = '🎓 Student Researcher';

    $page_title_safe    = $page_title    !== '' ? $page_title    : 'Student Portal';
    $subtitle_default   = '';
    $page_subtitle_safe = $page_subtitle !== '' ? $page_subtitle : $subtitle_default;

    // Get unread message and notification counts for badges
    global $conn;
    $user_id = (int) ($user['user_id'] ?? 0);
    $unread_messages = 0;
    $unread_notifications = 0;

    if (isset($conn) && $user_id > 0) {
        $msg_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE recipient_id = ? AND is_read = 0");
        if ($msg_stmt) {
            $msg_stmt->bind_param('i', $user_id);
            $msg_stmt->execute();
            $unread_messages = (int) ($msg_stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $msg_stmt->close();
        }

        $notif_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($notif_stmt) {
            $notif_stmt->bind_param('i', $user_id);
            $notif_stmt->execute();
            $unread_notifications = (int) ($notif_stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $notif_stmt->close();
        }
    }

    // Canonical student nav. Each row: [href, label, icon, exists, badge_count].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/student/ and /pages/shared/ both resolve the same.
    $nav = [
        'Overview' => [
            [SITE_URL . 'pages/student/student-dashboard.php', 'Dashboard', '📊', true, 0],
        ],
        'Research' => [
            [SITE_URL . 'pages/student/my-research.php',       'My Research',       '📁', true, 0],
            [SITE_URL . 'pages/student/submit-research.php',   'Submit Research',   '📝', true, 0],
            [SITE_URL . 'pages/student/submit-chapter.php',    'Submit Chapter',    '📄', true, 0],
            [SITE_URL . 'pages/student/my-documents.php',      'My Documents',      '📂', true, 0],
            [SITE_URL . 'pages/student/progress-tracking.php', 'Progress Tracking', '📈', true, 0],
        ],
        'Communication' => [
            [SITE_URL . 'pages/shared/messages.php',       'Messages',      '💬', true, $unread_messages],
            [SITE_URL . 'pages/shared/notifications.php',  'Notifications', '🔔', true, $unread_notifications],
        ],
        'Resources' => [
            [SITE_URL . 'pages/shared/research-archive.php', 'Research Archive', '🗂️', true, 0],
            [SITE_URL . 'pages/shared/calendar.php',         'Calendar',         '📅', true, 0],
        ],
        'Account' => [
            [SITE_URL . 'pages/shared/profile.php', 'Profile', '👤', true, 0],
        ],
    ];

    // Active-state matcher. Matches either the full target (e.g.
    // 'http://localhost/rms/pages/shared/profile.php') or the bare basename,
    // so shared pages highlight correctly when visited.
    $current_basename = basename(str_replace('\\', '/', (string) $current_page));
    $is_active = function ($href) use ($current_page, $current_basename) {
        if ($href === $current_page) {
            return true;
        }
        $href_basename = basename(str_replace('\\', '/', (string) $href));
        return $current_basename !== '' && $current_basename === $href_basename;
    };

    // Absolute asset/logout URLs (SITE_URL has a trailing slash).
    $url_style   = SITE_URL . 'css/style.css';
    $url_shell   = SITE_URL . 'css/student-shell.css';
    $url_logout  = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Student — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="student-dashboard">
  <!-- SIDEBAR -->
  <aside class="student-sidebar">
    <div class="student-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/student/student-dashboard.php" class="student-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="student-sidebar-brand-text">
        <div class="student-sidebar-brand">Research Management</div>
        <small class="student-sidebar-role">Student</small>
      </div>
    </div>

    <nav class="student-sidebar-nav">
      <?php foreach ($nav as $group => $items): ?>
        <div class="student-nav-group-title"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists, $badge_count] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'student-nav-item' . ($active ? ' active' : '');
            $attrs    = $active ? ' aria-current="page"' : '';
            if (!$exists) {
                $attrs .= ' data-todo="build"';
                $href   = '#';
            }
          ?>
          <a class="<?php echo $classes; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attrs; ?>>
            <span class="student-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
            <span class="student-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($badge_count > 0): ?>
              <span class="student-nav-badge"><?php echo $badge_count; ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="student-nav-group-title">Session</div>
      <a class="student-nav-item student-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="student-nav-icon" aria-hidden="true">🚪</span>
        <span class="student-nav-label">Logout</span>
      </a>
    </nav>

    <div class="student-sidebar-footer">
      <div class="student-user-card">
        <div class="student-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="student-user-info">
          <div class="student-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="student-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="student-main">
    <header class="student-topbar">
      <div class="student-topbar-left">
        <h1 class="student-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($page_subtitle_safe !== ''): ?>
          <p class="student-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <div class="student-topbar-right">
        <div class="student-topbar-user">
          <div class="student-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="student-topbar-user-text">
            <div class="student-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Student', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="student-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
      </div>
    </header>

    <div class="student-page-content">
    <?php
}

/**
 * Closes the student shell — emits the matching closing wrappers and </body></html>.
 * Pages that call renderStudentShell() MUST call this once at the end of their body.
 */
function renderStudentShellClose() {
    ?>
    </div><!-- /.student-page-content -->
  </main>
</div><!-- /.student-dashboard -->
</body>
</html>
    <?php
}
