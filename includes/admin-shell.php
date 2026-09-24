<?php
/**
 * Shared administrator shell: institutional navigation, responsive sidebar,
 * page heading, notifications, and account menu.
 *
 * Existing admin and shared module pages keep their own queries and forms;
 * this shell supplies the consistent navigation and visual frame.
 */
require_once __DIR__ . '/logout-transition.php';

if (!function_exists('adminShellIcon')) {
    function adminShellIcon(string $name): string
    {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'activity' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h3M8 17h7"/>',
            'seminar' => '<circle cx="12" cy="8" r="3"/><path d="M5 20c.7-3.2 3.1-5 7-5s6.3 1.8 7 5M4 4v4M2 6h4"/>',
            'presentation' => '<path d="M3 4h18v12H3zM12 16v4M8 20h8M7 13l3-3 2 2 4-5"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M23 11h-6"/>',
            'records' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'publication' => '<path d="M5 3h12a2 2 0 0 1 2 2v16H7a2 2 0 0 1-2-2V3ZM5 17h14M9 7h6M9 11h6"/><path d="M7 21a2 2 0 0 1-2-2"/>',
            'document' => '<path d="M6 3h9l5 5v13H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v6h6M8 13h8M8 17h8"/>',
            'repository' => '<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>',
            'monitor' => '<path d="M4 19V5M4 19h17M8 15l3-4 3 2 5-7"/><circle cx="19" cy="6" r="1"/>',
            'copyright' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/>',
            'output' => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5zM4 7.5l8 4.5 8-4.5M12 12v9M8 5.2l8 4.6"/>',
            'reports' => '<path d="M4 20V4M4 20h17"/><path d="M8 16v-4M12 16V7M16 16v-6M20 16V9"/>',
            'settings' => '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
            'contact' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            'logs' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'backup' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'messages' => '<path d="M4 5h16v12H9l-5 4z"/><path d="M8 9h8M8 13h5"/>',
            'notifications' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'logout' => '<path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
        ];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['records']) . '</svg>';
    }
}

function renderAdminShell($user, $current_page, $page_title, $page_subtitle = '')
{
    $shell_user = [
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'role' => $user['role'] ?? 'admin',
    ];
    if ($shell_user['first_name'] === '' && $shell_user['last_name'] === '' && !empty($user['name'])) {
        $parts = explode(' ', (string) $user['name'], 2);
        $shell_user['first_name'] = $parts[0] ?? '';
        $shell_user['last_name'] = $parts[1] ?? '';
    }

    $initials = strtoupper(substr((string) $shell_user['first_name'], 0, 1) . substr((string) $shell_user['last_name'], 0, 1));
    $initials = $initials !== '' ? $initials : 'AD';
    $full_name = trim((string) $shell_user['first_name'] . ' ' . (string) $shell_user['last_name']);
    $full_name = $full_name !== '' ? $full_name : 'Administrator';
    $role_label = ucfirst(str_replace('_', ' ', (string) $shell_user['role']));
    $page_title_safe = $page_title !== '' ? $page_title : 'Administration';
    $page_subtitle_safe = $page_subtitle !== '' ? $page_subtitle : '';

    $nav_groups = [
        [
            'title' => 'Research activities', 'icon' => 'activity',
            'links' => [
                [SITE_URL . 'pages/shared/activities.php', 'Activity overview', 'activity'],
                [SITE_URL . 'pages/shared/activities.php?type=seminar', 'Research seminars', 'seminar'],
                [SITE_URL . 'pages/shared/activities.php?type=presentation', 'Presentations', 'presentation'],
                [SITE_URL . 'pages/shared/activities.php?status=completed', 'Completed records', 'records'],
                [SITE_URL . 'pages/admin/admin-users.php?role=faculty', 'Faculty researchers', 'users'],
                [SITE_URL . 'pages/admin/admin-users.php?role=student', 'Student researchers', 'users'],
            ],
        ],
        [
            'title' => 'Publications', 'icon' => 'publication',
            'links' => [
                [SITE_URL . 'pages/shared/publications.php', 'All submissions', 'publication'],
                [SITE_URL . 'pages/shared/publications.php?status=submitted', 'Manuscripts', 'document'],
                [SITE_URL . 'pages/shared/publications.php?status=published', 'Published research', 'publication'],
                [SITE_URL . 'pages/shared/research-archive.php', 'Research repository', 'repository'],
                [SITE_URL . 'pages/shared/publications.php?status=under_review', 'Publication monitoring', 'monitor'],
            ],
        ],
        [
            'title' => 'Copyrights & outputs', 'icon' => 'copyright',
            'links' => [
                [SITE_URL . 'pages/shared/copyrights.php', 'Applications', 'copyright'],
                [SITE_URL . 'pages/shared/copyrights.php?status=registered', 'Copyright records', 'records'],
                [SITE_URL . 'pages/shared/copyrights.php?output_type=research', 'Research outputs', 'output'],
                [SITE_URL . 'pages/shared/copyrights.php?status=under_review', 'Copyright monitoring', 'monitor'],
            ],
        ],
        [
            'title' => 'Institute administration', 'icon' => 'settings',
            'links' => [
                [SITE_URL . 'pages/admin/admin-reports.php', 'Reports', 'reports'],
                [SITE_URL . 'pages/admin/admin-users.php', 'Users', 'users'],
                [SITE_URL . 'pages/admin/admin-departments.php', 'Departments', 'records'],
                [SITE_URL . 'pages/admin/admin-programs.php', 'Programs', 'records'],
                [SITE_URL . 'pages/admin/admin-contact.php', 'Contact messages', 'contact'],
                [SITE_URL . 'pages/admin/admin-logs.php', 'Activity log', 'logs'],
                [SITE_URL . 'pages/admin/admin-backup.php', 'Backup & recovery', 'backup'],
                [SITE_URL . 'pages/admin/admin-settings.php', 'Settings', 'settings'],
            ],
        ],
        [
            'title' => 'Communication', 'icon' => 'messages',
            'links' => [
                [SITE_URL . 'pages/shared/messages.php', 'Messages', 'messages'],
                [SITE_URL . 'pages/shared/notifications.php', 'Notifications', 'notifications'],
                [SITE_URL . 'pages/shared/profile.php', 'Administrator profile', 'profile'],
            ],
        ],
    ];

    $current_basename = basename(str_replace('\\', '/', (string) $current_page));
    if ($current_basename !== '' && !str_contains($current_basename, '.')) {
        $current_basename .= '.php';
    }
    $is_active = function ($href) use ($current_page, $current_basename) {
        if ($href === $current_page) return true;
        $target = parse_url((string) $href);
        $target_basename = basename(str_replace('\\', '/', (string) ($target['path'] ?? '')));
        if ($current_basename === '' || $current_basename !== $target_basename) return false;

        $target_query = [];
        parse_str((string) ($target['query'] ?? ''), $target_query);
        foreach ($target_query as $key => $value) {
            if ((string) ($_GET[$key] ?? '') !== (string) $value) return false;
        }
        if (!$target_query) {
            foreach (['type', 'status', 'publication_type', 'output_type'] as $filter_key) {
                if (isset($_GET[$filter_key]) && $_GET[$filter_key] !== '') return false;
            }
            if ($target_basename === 'admin-users.php' && in_array((string) ($_GET['role'] ?? ''), ['faculty', 'student'], true)) return false;
        }
        return true;
    };

    $section_label = $current_basename === 'admin-dashboard.php' ? 'Dashboard' : 'Administration';
    foreach ($nav_groups as $group) {
        foreach ($group['links'] as [$href]) {
            if ($is_active($href)) {
                $section_label = $group['title'];
                break 2;
            }
        }
    }

    $url_style = SITE_URL . 'css/style.css';
    $url_shell = SITE_URL . 'css/admin-shell.css';
    $url_logout = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#172333">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Admin — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin-body">
<div class="admin-dashboard">
  <button type="button" class="admin-sidebar-scrim" aria-label="Close administrator navigation" tabindex="-1"></button>
  <aside class="admin-sidebar" id="admin-sidebar" aria-label="Administrator navigation">
    <div class="admin-sidebar-header">
      <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="admin-sidebar-logo" aria-label="RMS dashboard">
        <img src="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="RMS logo">
      </a>
      <div class="admin-sidebar-brand-text">
        <div class="admin-sidebar-brand">EARIST Cavite</div>
        <small class="admin-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="admin-sidebar-nav" aria-label="Primary">
      <?php $dashboard_active = $current_basename === 'admin-dashboard.php'; ?>
      <a class="admin-nav-item admin-nav-dashboard<?php echo $dashboard_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" title="Dashboard"<?php echo $dashboard_active ? ' aria-current="page"' : ''; ?>>
        <span class="admin-nav-icon"><?php echo adminShellIcon('dashboard'); ?></span><span class="admin-nav-label">Dashboard</span>
      </a>
      <?php foreach ($nav_groups as $group): ?>
        <?php
          $group_active = false;
          foreach ($group['links'] as [$candidate_href]) {
              if ($is_active($candidate_href)) { $group_active = true; break; }
          }
          $group_id = 'nav-group-' . substr(sha1($group['title']), 0, 8);
        ?>
        <details class="admin-nav-group<?php echo $group_active ? ' is-active' : ''; ?>"<?php echo $group_active ? ' open' : ''; ?>>
          <summary class="admin-nav-group-summary" title="<?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $group_id; ?>">
            <span class="admin-nav-icon"><?php echo adminShellIcon($group['icon']); ?></span>
            <span class="admin-nav-label"><?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="admin-nav-chevron" aria-hidden="true"></span>
          </summary>
          <div class="admin-nav-subitems" id="<?php echo $group_id; ?>">
            <?php foreach ($group['links'] as [$href, $label, $icon]): $active = $is_active($href); ?>
              <a class="admin-nav-item admin-nav-subitem<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                <span class="admin-nav-icon"><?php echo adminShellIcon($icon); ?></span><span class="admin-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-user-card" title="<?php echo htmlspecialchars($full_name . ' · ' . $role_label, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="admin-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="admin-user-info">
          <div class="admin-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="admin-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    </div>
  </aside>

  <main class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-left">
        <button type="button" class="admin-mobile-menu-btn" aria-label="Open administrator navigation" aria-controls="admin-sidebar" aria-expanded="false"><span aria-hidden="true">&#9776;</span></button>
        <button type="button" class="admin-collapse-btn" aria-label="Collapse sidebar" aria-controls="admin-sidebar" title="Collapse sidebar"><span aria-hidden="true">&#8249;</span></button>
        <div class="admin-topbar-heading">
          <nav class="admin-breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">Administration</a><span aria-hidden="true">/</span><span><?php echo htmlspecialchars($section_label, ENT_QUOTES, 'UTF-8'); ?></span></nav>
          <h1 class="admin-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
          <?php if ($page_subtitle_safe !== ''): ?><p class="admin-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        </div>
      </div>
      <div class="admin-topbar-right">
        <a class="admin-topbar-icon-link" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/notifications.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="Notifications" title="Notifications"><?php echo adminShellIcon('notifications'); ?></a>
        <details class="admin-profile-menu">
          <summary class="admin-topbar-user" aria-label="Open administrator profile menu">
            <span class="admin-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="admin-topbar-user-text"><span class="admin-topbar-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></span><span class="admin-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <span class="admin-profile-chevron" aria-hidden="true"></span>
          </summary>
          <div class="admin-profile-dropdown">
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/profile.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo adminShellIcon('profile'); ?><span>Profile</span></a>
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-settings.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo adminShellIcon('settings'); ?><span>Account settings</span></a>
            <a href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>"><?php echo adminShellIcon('logout'); ?><span>Log out</span></a>
          </div>
        </details>
      </div>
    </header>
    <div class="admin-page-content">
    <?php
}

function renderAdminShellClose()
{
    ?>
    </div>
  </main>
</div>
<?php renderLogoutTransition(); ?>
<script>
(() => {
  const shell = document.querySelector('.admin-dashboard');
  const sidebar = document.querySelector('.admin-sidebar');
  const mobileButton = document.querySelector('.admin-mobile-menu-btn');
  const collapseButton = document.querySelector('.admin-collapse-btn');
  const scrim = document.querySelector('.admin-sidebar-scrim');
  if (!shell || !sidebar) return;

  const storageKey = 'rms-admin-sidebar-collapsed';
  const setCollapsed = (collapsed, persist = true) => {
    shell.classList.toggle('is-collapsed', collapsed);
    if (collapsed) {
      document.querySelectorAll('.admin-nav-group[open]').forEach((group) => group.removeAttribute('open'));
    }
    if (collapseButton) {
      collapseButton.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      collapseButton.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      collapseButton.querySelector('span').textContent = collapsed ? '›' : '‹';
    }
    if (persist) {
      try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (error) { /* Storage may be disabled. */ }
    }
  };
  try { setCollapsed(localStorage.getItem(storageKey) === '1', false); } catch (error) { setCollapsed(false, false); }

  const closeDrawer = () => {
    sidebar.classList.remove('is-open');
    shell.classList.remove('drawer-open');
    if (mobileButton) mobileButton.setAttribute('aria-expanded', 'false');
  };
  if (collapseButton) collapseButton.addEventListener('click', () => setCollapsed(!shell.classList.contains('is-collapsed')));
  if (mobileButton) mobileButton.addEventListener('click', (event) => {
    event.stopPropagation();
    const open = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', open);
    shell.classList.toggle('drawer-open', open);
    mobileButton.setAttribute('aria-expanded', String(open));
  });
  if (scrim) scrim.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeDrawer(); });
  window.addEventListener('resize', () => { if (window.innerWidth > 768) closeDrawer(); });
  document.addEventListener('click', (event) => {
    document.querySelectorAll('.admin-profile-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });
  document.querySelectorAll('.admin-nav-group[open]').forEach((group) => {
    group.addEventListener('toggle', () => {
      if (shell.classList.contains('is-collapsed') && group.open) setCollapsed(false);
    });
  });
})();
</script>
</body>
</html>
    <?php
}
