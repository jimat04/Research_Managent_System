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
 * @param bool   $show_navigation Whether to render the sidebar and topbar navigation
 * @param bool   $animate_navigation Whether to animate navigation after onboarding
 * @param bool   $show_intro_logout Whether to show logout during Research Basics
 */
require_once __DIR__ . '/logout-transition.php';
require_once __DIR__ . '/notification-popover.php';
require_once __DIR__ . '/research-workflow.php';
require_once __DIR__ . '/shell-icons.php';

function renderStudentShell($user, $current_page, $page_title, $page_subtitle = '', $show_navigation = true, $animate_navigation = false, $show_intro_logout = false) {
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
    $has_approved_title = isset($conn) && $user_id > 0 && rms_student_has_approved_title($conn, $user_id);
    $unread_area_counts = getUnreadNotificationCountsByArea($user_id);

    // Canonical student nav. Each row: [href, label, icon, exists, badge_count].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/student/ and /pages/shared/ both resolve the same.
    $nav = [
        'Overview' => [
            [SITE_URL . 'pages/student/student-dashboard.php', "Let's start learning", 'dashboard', true, 0],
        ],
        'My Research' => [
            [SITE_URL . 'pages/researcher/my-research.php',     'My Research', 'repository', true, 0],
            [SITE_URL . 'pages/researcher/submit-research.php', 'Submit Research Proposal', 'document', true, 0],
            [SITE_URL . 'pages/researcher/submit-chapter.php',  'Chapter Submission', 'document', true, 0],
            [SITE_URL . 'pages/student/my-documents.php',       'My Documents', 'records', true, 0],
        ],
        'Research Modules' => [
            [SITE_URL . 'pages/shared/activities.php',       'Research Activities', 'activity', true, 0],
            [SITE_URL . 'pages/student/my-publications.php', 'Publication Archive', 'publication', true, 0],
            [SITE_URL . 'pages/student/my-ip-contributions.php', 'My IP Contributions', 'copyright', true, 0],
            [SITE_URL . 'pages/shared/research-archive.php', 'Research Archive', 'repository', true, 0],
        ],
        'Account' => [
            [SITE_URL . 'pages/shared/profile.php', 'Profile', 'profile', true, 0],
        ],
    ];

    if ($has_approved_title) {
        $nav['My Research'] = array_values(array_filter(
            $nav['My Research'],
            static function (array $item): bool {
                return $item[1] !== 'Submit Research Proposal';
            }
        ));
    }

    // Active-state matcher. Matches either the full target (e.g.
    // 'http://localhost/rms/pages/shared/profile.php') or the bare basename,
    // so shared pages highlight correctly when visited.
    $current_basename = preg_replace('/\.php$/i', '', basename(str_replace('\\', '/', (string) $current_page)));
    $is_active = function ($href) use ($current_page, $current_basename) {
        $hrefQuery = parse_url((string) $href, PHP_URL_QUERY);
        $hrefParams = [];
        if (is_string($hrefQuery) && $hrefQuery !== '') parse_str($hrefQuery, $hrefParams);
        if ((string) ($_GET['ip_scope'] ?? '') !== (string) ($hrefParams['ip_scope'] ?? '')) return false;
        if ($href === $current_page) return true;
        $hrefPath = parse_url((string) $href, PHP_URL_PATH);
        $href_basename = preg_replace('/\.php$/i', '', basename(str_replace('\\', '/', (string) $hrefPath)));
        return $current_basename !== '' && $current_basename === $href_basename;
    };

    // Absolute asset/logout URLs (SITE_URL has a trailing slash).
    $url_style   = SITE_URL . 'css/style.css?v=' . filemtime(__DIR__ . '/../css/style.css');
    $url_shell   = SITE_URL . 'css/student-shell.css?v=' . filemtime(__DIR__ . '/../css/student-shell.css');
    $url_logout  = SITE_URL . 'public/logout.php';
    $is_research_basics = $current_basename === 'student-dashboard';
    $dashboard_classes = 'student-dashboard';
    if ($is_research_basics) $dashboard_classes .= ' student-dashboard--research-basics';
    if (!$show_navigation) $dashboard_classes .= ' student-dashboard--research-intro';
    if ($animate_navigation) $dashboard_classes .= ' student-dashboard--research-nav-entering';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Student — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
  <?php if ($is_research_basics): ?>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL . 'css/research-basics.css?v=' . filemtime(__DIR__ . '/../css/research-basics.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <script src="<?php echo htmlspecialchars(SITE_URL . 'js/research-basics.js?v=' . filemtime(__DIR__ . '/../js/research-basics.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
  <?php endif; ?>
</head>
<body>
<div class="<?php echo htmlspecialchars($dashboard_classes, ENT_QUOTES, 'UTF-8'); ?>" data-role-shell="student">
  <?php if ($is_research_basics): ?><a class="research-basics-skip" href="#research-basics-start">Skip to the learning guide</a><?php endif; ?>
  <button type="button" class="student-sidebar-scrim" aria-label="Close student navigation" tabindex="-1"></button>
  <!-- SIDEBAR -->
  <aside class="student-sidebar" id="student-sidebar" aria-label="Student navigation" data-role-shell-sidebar<?php if (!$show_navigation): ?> aria-hidden="true" inert<?php endif; ?>>
    <div class="student-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/student/student-dashboard.php" class="student-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="student-sidebar-brand-text">
        <div class="student-sidebar-brand">EARIST Cavite</div>
        <small class="student-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="student-sidebar-nav" aria-label="Primary" data-role-shell-nav>
      <?php foreach ($nav as $group => $items): ?>
        <?php if ($group === 'Overview'): ?>
          <?php foreach ($items as $item): ?>
            <?php [$href, $label, $icon, $exists] = $item; $active = $exists && $is_active($href); ?>
            <a class="student-nav-item student-nav-dashboard<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
              <span class="student-nav-icon"><?php echo rmsShellIcon($icon); ?></span>
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
          $groupIcon = $items[0][2] ?? 'repository';
        ?>
        <details class="student-nav-group"<?php echo $groupActive ? ' open' : ''; ?>>
          <summary class="student-nav-group-summary" title="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $groupId; ?>">
            <span class="student-nav-icon"><?php echo rmsShellIcon($groupIcon); ?></span>
            <span class="student-nav-label"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php
              $group_notification_count = $group === 'My Research'
                  ? $unread_area_counts['my_research']
                  : ($group === 'Research Modules' ? $unread_area_counts['research_modules'] : 0);
            ?>
            <?php if ($group_notification_count > 0): ?>
              <span class="student-nav-badge" title="<?php echo $group_notification_count; ?> unread notifications in <?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo $group_notification_count; ?> unread notifications in <?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $group_notification_count > 99 ? '99+' : $group_notification_count; ?></span>
            <?php endif; ?>
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
            <span class="student-nav-icon"><?php echo rmsShellIcon($icon); ?></span>
            <span class="student-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($badge_count > 0): ?>
              <span class="student-nav-badge"><?php echo $badge_count; ?></span>
            <?php endif; ?>
          </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>

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
  <main class="student-main"<?php if ($is_research_basics): ?> aria-labelledby="research-basics-title"<?php endif; ?>>
    <header class="student-topbar" data-role-shell-topbar>
      <div class="student-topbar-left">
        <button type="button" class="student-mobile-menu-btn" aria-label="Open student navigation" aria-controls="student-sidebar" aria-expanded="false"<?php if (!$show_navigation): ?> hidden<?php endif; ?>>☰</button>
        <?php if ($show_navigation): ?>
        <button type="button" class="student-collapse-btn" aria-label="Collapse sidebar" aria-controls="student-sidebar" title="Collapse sidebar" data-role-shell-collapse><span aria-hidden="true">‹</span></button>
        <?php endif; ?>
        <div class="student-topbar-heading">
          <?php if ($is_research_basics): ?>
          <p class="student-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php else: ?>
          <h1 class="student-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
          <?php endif; ?>
          <?php if ($page_subtitle_safe !== ''): ?>
          <p class="student-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($show_navigation): ?>
      <div class="student-topbar-search">
        <span class="student-topbar-search-icon" aria-hidden="true">⌕</span>
        <input type="search" id="student-page-search" aria-label="Search pages in RMS" placeholder="Search pages..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="student-search-suggestions" aria-expanded="false" data-role-shell-search>
        <div class="student-search-suggestions" id="student-search-suggestions" role="listbox" aria-label="Matching RMS pages" hidden data-role-shell-suggestions></div>
      </div>
      <?php endif; ?>
      <div class="student-topbar-right">
        <a class="student-topbar-message" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/messages.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($unread_messages > 0 ? 'Messages, ' . $unread_messages . ' unread' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($unread_messages > 0 ? $unread_messages . ' unread messages' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H9l-5 4v-4.5A2.5 2.5 0 0 1 4 13.5v-7Z"/><path d="M8 8h8M8 11h6"/></svg>
          <?php if ($unread_messages > 0): ?><span class="student-topbar-message-badge" aria-hidden="true"><?php echo $unread_messages > 99 ? '99+' : $unread_messages; ?></span><?php endif; ?>
        </a>
        <?php renderNotificationPopover($user_id, $unread_notifications, 'student'); ?>
        <details class="student-profile-menu">
          <summary class="student-topbar-user" aria-label="Open student profile menu">
            <span class="student-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="student-topbar-user-text"><span class="student-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Student', ENT_QUOTES, 'UTF-8'); ?></span><span class="student-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <span class="student-profile-chevron" aria-hidden="true"></span>
          </summary>
          <div class="student-profile-dropdown">
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/profile.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo rmsShellIcon('profile'); ?><span>Profile</span></a>
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/settings.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo rmsShellIcon('settings'); ?><span>Account settings</span></a>
            <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" data-logout-confirm>
              <?php echo csrfField(); ?>
              <button type="submit" class="student-profile-action"><?php echo rmsShellIcon('logout'); ?><span>Log out</span></button>
            </form>
          </div>
        </details>
        <?php if ($is_research_basics && !$show_navigation && $show_intro_logout): ?>
        <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" class="student-topbar-intro-logout-form" data-logout-confirm>
          <?php echo csrfField(); ?>
          <button type="submit" class="student-topbar-intro-logout">Logout</button>
        </form>
        <?php endif; ?>
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
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/notification-popover.js?v=' . filemtime(__DIR__ . '/../js/notification-popover.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js?v=' . filemtime(__DIR__ . '/../js/public-motion.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/role-shell-controls.js?v=' . filemtime(__DIR__ . '/../js/role-shell-controls.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(() => {
  const shell = document.querySelector('.student-dashboard');
  const button = document.querySelector('.student-mobile-menu-btn');
  const sidebar = document.querySelector('.student-sidebar');
  const scrim = document.querySelector('.student-sidebar-scrim');
  const main = document.querySelector('.student-main');
  if (!button || !sidebar || button.hidden) return;
  const isMobile = () => window.matchMedia('(max-width: 768px)').matches;
  const getFocusableItems = () => Array.from(sidebar.querySelectorAll(
    'a[href], button:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'
  )).filter((item) => item.getClientRects().length > 0
    && getComputedStyle(item).visibility !== 'hidden'
    && !item.closest('[inert]'));
  const setDrawerOpen = (open, restoreFocus) => {
    sidebar.classList.remove('is-open');
    if (shell) shell.classList.remove('drawer-open');
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Close student navigation' : 'Open student navigation');
    if (open) {
      sidebar.inert = false;
      sidebar.removeAttribute('aria-hidden');
      sidebar.setAttribute('role', 'dialog');
      sidebar.setAttribute('aria-modal', 'true');
      sidebar.classList.add('is-open');
      if (shell) shell.classList.add('drawer-open');
      if (main) main.inert = true;
      const firstNavLink = sidebar.querySelector('.student-sidebar-nav a[href]');
      if (firstNavLink) firstNavLink.focus();
      return;
    }

    sidebar.classList.remove('is-open');
    if (shell) shell.classList.remove('drawer-open');
    if (main) main.inert = false;
    sidebar.removeAttribute('role');
    sidebar.removeAttribute('aria-modal');
    if (isMobile()) {
      sidebar.inert = true;
      sidebar.setAttribute('aria-hidden', 'true');
    } else {
      sidebar.inert = false;
      sidebar.removeAttribute('aria-hidden');
    }
    if (restoreFocus && isMobile()) button.focus();
  };

  const syncDrawerToViewport = () => {
    if (isMobile()) {
      if (!sidebar.classList.contains('is-open')) {
        const focusWasInSidebar = sidebar.contains(document.activeElement);
        setDrawerOpen(false, false);
        if (focusWasInSidebar) button.focus();
      }
      return;
    }
    setDrawerOpen(false, false);
  };

  button.addEventListener('click', (event) => {
    event.stopPropagation();
    setDrawerOpen(!sidebar.classList.contains('is-open'), false);
  });
  if (scrim) scrim.addEventListener('click', () => setDrawerOpen(false, true));
  sidebar.addEventListener('click', (event) => {
    if (event.target.closest('a[href]')) setDrawerOpen(false, false);
  });
  document.addEventListener('keydown', (event) => {
    if (!isMobile() || !sidebar.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      setDrawerOpen(false, true);
      return;
    }
    if (event.key !== 'Tab') return;
    const focusableItems = getFocusableItems();
    if (focusableItems.length === 0) {
      event.preventDefault();
      sidebar.focus();
      return;
    }
    const first = focusableItems[0];
    const last = focusableItems[focusableItems.length - 1];
    if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  });
  syncDrawerToViewport();
  window.addEventListener('resize', syncDrawerToViewport);
})();
</script>
</body>
</html>
    <?php
}
