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
require_once __DIR__ . '/logout-transition.php';

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
        'My Research' => [
            [SITE_URL . 'pages/researcher/my-research.php',     'My Research',           '📁', true, 0],
            [SITE_URL . 'pages/researcher/submit-research.php', 'Submit Research Title', '📝', true, 0],
            [SITE_URL . 'pages/researcher/submit-chapter.php',  'Submit a Chapter',      '📄', true, 0],
        ],
        'Research Modules' => [
            [SITE_URL . 'pages/shared/activities.php',       'Research Activities', '&#128197;', true, 0],
            [SITE_URL . 'pages/shared/publications.php',     'Publications',        '&#128214;', true, 0],
            [SITE_URL . 'pages/shared/copyrights.php',       'Copyrights',          '&#169;', true, 0],
            [SITE_URL . 'pages/shared/research-archive.php', 'Repository',          '&#128451;', true, 0],
        ],
        'Communication' => [
            [SITE_URL . 'pages/shared/messages.php',       'Messages',      '💬', true, $unread_messages],
            [SITE_URL . 'pages/shared/notifications.php',  'Notifications', '🔔', true, $unread_notifications],
        ],
        'Account' => [
            [SITE_URL . 'pages/shared/profile.php', 'Profile', '👤', true, 0],
        ],
    ];

    // Active-state matcher. Matches either the full target (e.g.
    // 'http://localhost/rms/pages/shared/profile.php') or the bare basename,
    // so shared pages highlight correctly when visited.
    $current_basename = preg_replace('/\.php$/i', '', basename(str_replace('\\', '/', (string) $current_page)));
    $is_active = function ($href) use ($current_page, $current_basename) {
        if ($href === $current_page) {
            return true;
        }
      $href_basename = preg_replace('/\.php$/i', '', basename(str_replace('\\', '/', (string) $href)));
        return $current_basename !== '' && $current_basename === $href_basename;
    };

    // Absolute asset/logout URLs (SITE_URL has a trailing slash).
    $url_style   = SITE_URL . 'css/style.css';
    $url_shell   = SITE_URL . 'css/student-shell.css?v=' . filemtime(__DIR__ . '/../css/student-shell.css');
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
  <button type="button" class="student-sidebar-scrim" aria-label="Close student navigation" tabindex="-1"></button>
  <!-- SIDEBAR -->
  <aside class="student-sidebar" id="student-sidebar" aria-label="Student navigation">
    <div class="student-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/student/student-dashboard.php" class="student-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="student-sidebar-brand-text">
        <div class="student-sidebar-brand">EARIST Cavite</div>
        <small class="student-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="student-sidebar-nav" aria-label="Primary">
      <?php foreach ($nav as $group => $items): ?>
        <?php if ($group === 'Overview'): ?>
          <?php foreach ($items as $item): ?>
            <?php [$href, $label, $icon, $exists] = $item; $active = $exists && $is_active($href); ?>
            <a class="student-nav-item student-nav-dashboard<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
              <span class="student-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
              <span class="student-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
          <?php endforeach; ?>
          <?php continue; ?>
        <?php endif; ?>
        <?php
          $groupId = 'student-nav-group-' . substr(sha1($group), 0, 8);
          $groupActive = false;
          foreach ($items as $candidateItem) {
              if ($is_active($candidateItem[0])) {
                  $groupActive = true;
                  break;
              }
          }
          $groupIcon = $items[0][2] ?? '📁';
        ?>
        <details class="student-nav-group"<?php echo $groupActive ? ' open' : ''; ?>>
          <summary class="student-nav-group-summary" title="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $groupId; ?>">
            <span class="student-nav-icon" aria-hidden="true"><?php echo $groupIcon; ?></span>
            <span class="student-nav-label"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="student-nav-chevron" aria-hidden="true"></span>
          </summary>
          <div class="student-nav-subitems" id="<?php echo $groupId; ?>">
            <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists, $badge_count] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'student-nav-item student-nav-subitem' . ($active ? ' active' : '');
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
          </div>
        </details>
      <?php endforeach; ?>

      <div class="student-nav-session">
        <div class="student-nav-group-title">Session</div>
        <a class="student-nav-item student-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
          <span class="student-nav-icon" aria-hidden="true">🚪</span>
          <span class="student-nav-label">Logout</span>
        </a>
      </div>
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
        <button type="button" class="student-mobile-menu-btn" aria-label="Open student navigation" aria-controls="student-sidebar" aria-expanded="false">☰</button>
        <h1 class="student-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($page_subtitle_safe !== ''): ?>
          <p class="student-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <div class="student-topbar-right">
        <a class="student-topbar-notification" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/notifications.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($unread_notifications > 0 ? 'Notifications, ' . $unread_notifications . ' unread' : 'Notifications', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($unread_notifications > 0 ? $unread_notifications . ' unread notifications' : 'Notifications', ENT_QUOTES, 'UTF-8'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
          <?php if ($unread_notifications > 0): ?><span class="student-topbar-notification-badge" aria-hidden="true"><?php echo $unread_notifications > 99 ? '99+' : $unread_notifications; ?></span><?php endif; ?>
        </a>
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
<?php renderLogoutTransition(); ?>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(() => {
  const shell = document.querySelector('.student-dashboard');
  const button = document.querySelector('.student-mobile-menu-btn');
  const sidebar = document.querySelector('.student-sidebar');
  const scrim = document.querySelector('.student-sidebar-scrim');
  if (!button || !sidebar) return;
  const closeMenu = () => {
    sidebar.classList.remove('is-open');
    if (shell) shell.classList.remove('drawer-open');
    button.setAttribute('aria-expanded', 'false');
  };
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    const open = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', open);
    if (shell) shell.classList.toggle('drawer-open', open);
    button.setAttribute('aria-expanded', String(open));
  });
  if (scrim) scrim.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });
})();
</script>
</body>
</html>
    <?php
}
