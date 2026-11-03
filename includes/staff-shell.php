<?php
/**
 * Staff Shell — shared sidebar, topbar, and page-content wrapper for Research Staff.
 *
 * Usage at the top of every staff page (after requireRole, before any HTML output):
 *
 *     require_once __DIR__ . '/../../includes/staff-shell.php';
 *     $user = getCurrentUser();
 *     renderStaffShell($user, 'staff-dashboard', 'Research Staff Dashboard', 'Process submissions and manage the EARIST research repository.');
 *     ... page body ...
 *     renderStaffShellClose();
 *
 * renderStaffShell() opens <!DOCTYPE>, <head>, the dashboard wrapper, the sidebar,
 * the main area, the topbar, and <div class="staff-page-content">.
 * renderStaffShellClose() emits the matching closing tags plus </body></html>.
 *
 * All navigation, CSS, and logout URLs are built from SITE_URL (defined in
 * includes/config.php with a trailing slash) so the same sidebar works from
 * any directory — /pages/staff/, /pages/shared/, etc. — without 404s.
 *
 * @param array  $user          Current user row from getCurrentUser()
 * @param string $current_page  Current page slug (e.g. 'staff-dashboard', 'contact-messages', 'messages.php')
 * @param string $page_title    Title shown in the topbar <h1>
 * @param string $page_subtitle Subtitle shown under the title (pass '' for none)
 */
require_once __DIR__ . '/logout-transition.php';
require_once __DIR__ . '/notification-popover.php';
require_once __DIR__ . '/shell-icons.php';

function staffShellNavigationContainsActive(array $node, callable $isActive): bool
{
    foreach ($node['links'] ?? [] as $link) {
        if ($isActive($link[0])) {
            return true;
        }
    }

    if (isset($node['href']) && $isActive($node['href'])) {
        return true;
    }

    foreach ($node['children'] ?? [] as $child) {
        if (staffShellNavigationContainsActive($child, $isActive)) {
            return true;
        }
    }

    return false;
}

function renderStaffShellNavigationNode(array $node, callable $isActive, string $parentId = '', int $depth = 0): void
{
    $title = (string) ($node['title'] ?? '');
    $icon = (string) ($node['icon'] ?? 'records');
    $links = $node['links'] ?? [];
    $children = $node['children'] ?? [];

    if ($links === [] && $children === []) {
        $href = (string) ($node['href'] ?? '#');
        $active = $isActive($href);
        $classes = 'staff-nav-item' . (!empty($node['dashboard']) ? ' staff-nav-dashboard' : ' staff-nav-subitem') . ($active ? ' active' : '');
        echo '<a class="' . $classes . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"' . ($active ? ' aria-current="page"' : '') . '>';
        echo '<span class="staff-nav-icon" aria-hidden="true">' . rmsShellIcon($icon) . '</span>';
        echo '<span class="staff-nav-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        if ((int) ($node['badge'] ?? 0) > 0) {
            echo '<span class="staff-nav-badge">' . (int) $node['badge'] . '</span>';
        }
        echo '</a>';
        return;
    }

    $path = $parentId . '/' . $title;
    $groupId = 'staff-nav-group-' . substr(sha1($path), 0, 8);
    $groupActive = staffShellNavigationContainsActive($node, $isActive);
    $groupClass = 'staff-nav-group' . ($depth > 0 ? ' staff-nav-subgroup' : '');
    echo '<details class="' . $groupClass . '"' . ($groupActive ? ' open' : '') . '>';
    echo '<summary class="staff-nav-group-summary" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" aria-controls="' . $groupId . '">';
    echo '<span class="staff-nav-icon" aria-hidden="true">' . rmsShellIcon($icon) . '</span>';
    echo '<span class="staff-nav-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<span class="staff-nav-chevron" aria-hidden="true"></span>';
    echo '</summary><div class="staff-nav-subitems" id="' . $groupId . '"><div class="staff-nav-subitems-inner">';

    foreach ($children as $child) {
        renderStaffShellNavigationNode($child, $isActive, $path, $depth + 1);
    }
    foreach ($links as [$href, $label, $linkIcon, $badgeCount]) {
        $active = $isActive($href);
        echo '<a class="staff-nav-item staff-nav-subitem' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . ($active ? ' aria-current="page"' : '') . '>';
        echo '<span class="staff-nav-icon" aria-hidden="true">' . rmsShellIcon($linkIcon) . '</span>';
        echo '<span class="staff-nav-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        if ((int) $badgeCount > 0) {
            echo '<span class="staff-nav-badge">' . (int) $badgeCount . '</span>';
        }
        echo '</a>';
    }

    echo '</div></div></details>';
}

function renderStaffShell($user, $current_page, $page_title, $page_subtitle = '') {
    // Normalise the user object
    $shell_user = [
        'first_name' => $user['first_name'] ?? '',
        'last_name'  => $user['last_name']  ?? '',
        'role'       => $user['role']       ?? 'research_staff',
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
        $initials = 'RS';
    }

    $full_name = trim($shell_user['first_name'] . ' ' . $shell_user['last_name']);
    if ($full_name === '') {
        $full_name = 'Research Staff';
    }

    $role_label = '📋 Research Staff';

    $page_title_safe    = $page_title    !== '' ? $page_title    : 'Staff';
    $subtitle_default   = '';
    $page_subtitle_safe = $page_subtitle !== '' ? $page_subtitle : $subtitle_default;

    // Get badge counts for nav items
    global $conn;
    $user_id = (int) ($user['user_id'] ?? 0);
    $stat_contact   = 0;
    $unread_notifications = 0;
    $unread_messages = 0;
    if (isset($conn)) {
        if ($user_id > 0) {
            $message_stmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM messages WHERE recipient_id = ? AND is_read = 0');
            if ($message_stmt) {
                $message_stmt->bind_param('i', $user_id);
                $message_stmt->execute();
                $unread_messages = (int) ($message_stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
                $message_stmt->close();
            }

            $notification_stmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
            if (!$notification_stmt) {
                throw new RuntimeException('Unable to load unread notifications: ' . $conn->error);
            }
            $notification_stmt->bind_param('i', $user_id);
            $notification_stmt->execute();
            $unread_notifications = (int) ($notification_stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
            $notification_stmt->close();
        }

        $count_result = $conn->query("SELECT COUNT(*) AS count FROM contact_messages WHERE status = 'pending'");
        if ($count_result) {
            $stat_contact = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
    }

    // Canonical staff nav. Each row: [href, label, icon, exists, badge_count].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/staff/ and /pages/shared/ both resolve the same.
    $permissionModules = rmsPermissionModules();
    $permissionMatrix = ($shell_user['role'] ?? '') === 'admin'
        ? []
        : getStaffPermissionMatrix((int) ($user['user_id'] ?? 0));
    $moduleIcons = [
        'add_activity' => 'activity', 'add_upcoming_activity' => 'seminar', 'add_conducted_activity' => 'records',
        'view_activity' => 'monitor', 'upcoming_activities' => 'document', 'ongoing_activities' => 'monitor',
        'conducted_activities' => 'output', 'seminars' => 'seminar', 'trainings' => 'presentation', 'workshops' => 'activity',
        'research_proposals' => 'document', 'research_review' => 'monitor', 'research_approval' => 'reports',
        'ongoing_research' => 'monitor', 'completed_research' => 'records', 'research_outputs' => 'output',
        'journal_publications' => 'publication', 'conference_presentations' => 'presentation', 'research_repository' => 'repository',
        'copyrights' => 'copyright', 'patents_utility_models' => 'output', 'innovation_technology_transfer' => 'users',
    ];
    $moduleLinks = static function (string $moduleKey, array $pageKeys, array $labelOverrides = []) use ($permissionModules, $permissionMatrix, $moduleIcons, $shell_user): array {
        $modulePages = $permissionModules[$moduleKey]['pages'] ?? [];
        $links = [];
        foreach ($pageKeys as $pageKey) {
            if (!isset($modulePages[$pageKey])) {
                continue;
            }
            $level = ($shell_user['role'] ?? '') === 'admin'
                ? 'manage'
                : ($permissionMatrix[$moduleKey]['pages'][$pageKey] ?? 'none');
            if (!in_array($level, ['view', 'manage'], true)) {
                continue;
            }
            $links[] = [
                SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($pageKey),
                $labelOverrides[$pageKey] ?? $modulePages[$pageKey],
                $moduleIcons[$pageKey] ?? 'document',
                0,
            ];
        }
        return $links;
    };

    $navTree = [
        ['title' => 'Dashboard', 'href' => SITE_URL . 'pages/staff/staff-dashboard.php', 'icon' => 'dashboard', 'dashboard' => true],
    ];

    $capacityChildren = [];
    $capacityAddLinks = $moduleLinks('research_capacity_building',
        ['add_upcoming_activity', 'add_conducted_activity'],
        ['add_upcoming_activity' => 'Upcoming', 'add_conducted_activity' => 'Completed']
    );
    if ($capacityAddLinks) {
        $capacityChildren[] = ['title' => 'Add', 'icon' => 'activity', 'links' => $capacityAddLinks];
    }
    $capacityViewLinks = $moduleLinks('research_capacity_building',
        ['upcoming_activities', 'ongoing_activities', 'conducted_activities']
    );
    if ($capacityViewLinks) {
        $capacityChildren[] = ['title' => 'View', 'icon' => 'monitor', 'links' => $capacityViewLinks];
    }
    if ($capacityChildren) {
        $navTree[] = [
            'title' => 'Research Capacity Building',
            'icon' => 'seminar',
            'children' => [
                ['title' => 'Research Activities', 'icon' => 'activity', 'children' => $capacityChildren],
            ],
        ];
    }

    $developmentLinks = $moduleLinks('research_development', ['research_proposals', 'research_review', 'research_approval']);
    $navTree[] = ['title' => 'Research Development', 'icon' => 'records', 'links' => $developmentLinks];

    $implementationLinks = $moduleLinks('research_implementation_outputs', ['ongoing_research', 'completed_research', 'research_outputs']);
    if ($implementationLinks) {
        $navTree[] = ['title' => 'Research Implementation & Outputs', 'icon' => 'output', 'links' => $implementationLinks];
    }

    $publicationLinks = $moduleLinks('publication_dissemination',
        ['journal_publications', 'conference_presentations', 'research_repository'],
        ['conference_presentations' => 'Conference presentations']
    );
    if ($publicationLinks) {
        $navTree[] = ['title' => 'Publication & Dissemination', 'icon' => 'publication', 'links' => $publicationLinks];
    }

    $ipLinks = $moduleLinks('intellectual_property_utilization', ['copyrights', 'patents_utility_models', 'innovation_technology_transfer']);
    if ($ipLinks) {
        $navTree[] = ['title' => 'Intellectual Property & Utilization', 'icon' => 'copyright', 'links' => $ipLinks];
    }

    $navTree[] = [
        'title' => 'Communication',
        'icon' => 'messages',
        'links' => [
            [SITE_URL . 'pages/shared/notifications.php', 'Notifications', 'notifications', 0],
            [SITE_URL . 'pages/staff/contact-messages.php', 'Contact messages', 'contact', $stat_contact],
            [SITE_URL . 'pages/shared/profile.php', 'Profile', 'profile', 0],
        ],
    ];
    $navTree[] = [
        'title' => 'Account',
        'icon' => 'settings',
        'links' => [[SITE_URL . 'pages/shared/settings.php', 'Account settings', 'settings', 0]],
    ];

    // Active-state matcher. Matches either the full target (e.g.
    // 'http://localhost/rms/pages/shared/profile.php') or the bare basename,
    // so shared pages highlight correctly when visited.
    $current_basename = basename(str_replace('\\', '/', (string) $current_page));
    if ($current_basename !== '' && !str_contains($current_basename, '.')) {
        $current_basename .= '.php';
    }
    $current_module_key = trim((string) ($_GET['module_page'] ?? ''));
    if ($current_module_key === '') {
        $current_module_key = match ($current_basename) {
            'activities.php' => match ((string) ($_GET['type'] ?? '')) {
                'seminar' => 'seminars',
                'training' => 'trainings',
                'workshop' => 'workshops',
                default => '',
            },
            'publications.php' => match (true) {
                (string) ($_GET['repository'] ?? '') === '1' => 'research_repository',
                (string) ($_GET['publication_type'] ?? '') === 'journal' => 'journal_publications',
                (string) ($_GET['publication_type'] ?? '') === 'conference' => 'conference_presentations',
                (string) ($_GET['status'] ?? '') === 'published' => 'research_outputs',
                default => '',
            },
            'copyrights.php' => match (true) {
                in_array((string) ($_GET['ip_scope'] ?? ''), ['patents_utility_models', 'innovation_technology_transfer'], true) => (string) $_GET['ip_scope'],
                in_array((string) ($_GET['output_type'] ?? ''), ['patent', 'utility_model'], true) => 'patents_utility_models',
                in_array((string) ($_GET['output_type'] ?? ''), ['innovation', 'technology_transfer'], true) => 'innovation_technology_transfer',
                default => 'copyrights',
            },
            'research-archive.php' => 'completed_research',
            default => '',
        };
    }
    $is_active = function ($href) use ($current_page, $current_basename, $current_module_key) {
        if ($href === $current_page) {
            return true;
        }
        $target = parse_url((string) $href);
        $href_basename = basename(str_replace('\\', '/', (string) ($target['path'] ?? '')));
        if ($href_basename === 'admin-research-module.php') {
            parse_str((string) ($target['query'] ?? ''), $targetQuery);
            return $current_module_key !== '' && $current_module_key === (string) ($targetQuery['module'] ?? '');
        }
        return $current_basename !== '' && $current_basename === $href_basename;
    };

    // Absolute asset/logout URLs (SITE_URL has a trailing slash).
    $url_style   = SITE_URL . 'css/style.css?v=' . filemtime(__DIR__ . '/../css/style.css');
    $url_shell   = SITE_URL . 'css/staff-shell.css?v=' . filemtime(__DIR__ . '/../css/staff-shell.css');
    $url_logout  = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Staff — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="staff-dashboard" data-role-shell="staff">
  <a class="staff-skip-link" href="#staff-main-content">Skip to main content</a>
  <button type="button" class="staff-sidebar-scrim" aria-label="Close staff navigation" tabindex="-1"></button>
  <!-- SIDEBAR -->
  <aside class="staff-sidebar" id="staff-sidebar" aria-label="Research staff navigation" data-role-shell-sidebar>
    <div class="staff-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/staff/staff-dashboard.php" class="staff-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="staff-sidebar-brand-text">
        <div class="staff-sidebar-brand">EARIST Cavite</div>
        <small class="staff-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="staff-sidebar-nav" aria-label="Primary" data-role-shell-nav>
      <?php foreach ($navTree as $navNode) { renderStaffShellNavigationNode($navNode, $is_active); } ?>

      <div class="staff-nav-session">
        <div class="staff-nav-group-title">Session</div>
        <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" class="staff-nav-logout-form" data-logout-confirm>
          <?php echo csrfField(); ?>
          <button type="submit" class="staff-nav-item staff-nav-logout">
          <span class="staff-nav-icon" aria-hidden="true"><?php echo rmsShellIcon('logout'); ?></span>
          <span class="staff-nav-label">Logout</span>
          </button>
        </form>
      </div>
    </nav>

    <div class="staff-sidebar-footer">
      <div class="staff-user-card">
        <div class="staff-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="staff-user-info">
          <div class="staff-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="staff-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="staff-main" id="staff-main-content" tabindex="-1">
    <header class="staff-topbar" data-role-shell-topbar>
      <div class="staff-topbar-left">
        <button type="button" class="staff-mobile-menu-btn" aria-label="Open staff navigation" aria-controls="staff-sidebar" aria-expanded="false">☰</button>
        <button type="button" class="staff-collapse-btn" aria-label="Collapse sidebar" aria-controls="staff-sidebar" title="Collapse sidebar" data-role-shell-collapse><span aria-hidden="true">‹</span></button>
      </div>
      <div class="staff-topbar-search">
        <span class="staff-topbar-search-icon" aria-hidden="true">⌕</span>
        <input type="search" id="staff-page-search" aria-label="Search pages in RMS" placeholder="Search pages..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="staff-search-suggestions" aria-expanded="false" data-role-shell-search>
        <div class="staff-search-suggestions" id="staff-search-suggestions" role="listbox" aria-label="Matching RMS pages" hidden data-role-shell-suggestions></div>
      </div>
      <div class="staff-topbar-right">
        <a class="staff-topbar-message" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/messages.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($unread_messages > 0 ? 'Messages, ' . $unread_messages . ' unread' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($unread_messages > 0 ? $unread_messages . ' unread messages' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>">
          <?php echo rmsShellIcon('messages'); ?>
          <?php if ($unread_messages > 0): ?><span class="staff-topbar-message-badge" aria-hidden="true"><?php echo $unread_messages > 99 ? '99+' : $unread_messages; ?></span><?php endif; ?>
        </a>
        <?php renderNotificationPopover($user_id, $unread_notifications, 'staff'); ?>
        <div class="staff-topbar-separator" aria-hidden="true"></div>
        <details class="staff-profile-menu">
          <summary class="staff-topbar-user" aria-label="Open staff profile menu">
            <span class="staff-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="staff-topbar-user-text"><span class="staff-topbar-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></span><span class="staff-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <span class="staff-profile-chevron" aria-hidden="true"></span>
          </summary>
          <div class="staff-profile-dropdown">
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/profile.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo rmsShellIcon('profile'); ?><span>Profile</span></a>
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/settings.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo rmsShellIcon('settings'); ?><span>Account settings</span></a>
            <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" data-logout-confirm>
              <?php echo csrfField(); ?>
              <button type="submit" class="staff-profile-action"><?php echo rmsShellIcon('logout'); ?><span>Log out</span></button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <div class="staff-page-content">
    <?php
}

/**
 * Closes the staff shell — emits the matching closing wrappers and </body></html>.
 * Pages that call renderStaffShell() MUST call this once at the end of their body.
 */
function renderStaffShellClose() {
    ?>
    </div><!-- /.staff-page-content -->
  </main>
</div><!-- /.staff-dashboard -->
<?php renderLogoutTransition(); ?>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/notification-popover.js?v=' . filemtime(__DIR__ . '/../js/notification-popover.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js?v=' . filemtime(__DIR__ . '/../js/public-motion.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/role-shell-controls.js?v=' . filemtime(__DIR__ . '/../js/role-shell-controls.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(() => {
  const shell = document.querySelector('.staff-dashboard');
  const button = document.querySelector('.staff-mobile-menu-btn');
  const sidebar = document.querySelector('.staff-sidebar');
  const scrim = document.querySelector('.staff-sidebar-scrim');
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
  document.addEventListener('click', (event) => {
    const targetMenu = event.target instanceof Element ? event.target.closest('.staff-profile-menu') : null;
    document.querySelectorAll('.staff-profile-menu[open]').forEach((menu) => {
      if (menu !== targetMenu) menu.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeMenu();
    document.querySelectorAll('.staff-profile-menu[open]').forEach((menu) => menu.removeAttribute('open'));
  });
  window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });
})();
</script>
</body>
</html>
    <?php
}
