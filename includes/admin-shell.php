<?php
/**
 * Shared administrator shell: institutional navigation, responsive sidebar,
 * page heading, notifications, and account menu.
 *
 * Existing admin and shared module pages keep their own queries and forms;
 * this shell supplies the consistent navigation and visual frame.
 */
require_once __DIR__ . '/logout-transition.php';
require_once __DIR__ . '/notification-popover.php';
require_once __DIR__ . '/shell-icons.php';

if (!function_exists('adminShellIcon')) {
    function adminShellIcon(string $name): string
    {
        return rmsShellIcon($name);
    }
}

function renderAdminShell($user, $current_page, $page_title, $page_subtitle = '')
{
    global $conn;

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
    $user_id = (int) ($user['user_id'] ?? 0);
    $unread_notifications = 0;

    if (isset($conn) && $user_id > 0) {
        $notification_stmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
        if (!$notification_stmt) {
            throw new RuntimeException('Unable to load unread notifications: ' . $conn->error);
        }
        $notification_stmt->bind_param('i', $user_id);
        $notification_stmt->execute();
        $unread_notifications = (int) ($notification_stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
        $notification_stmt->close();
    }

    $nav_groups = [
        [
            'title' => 'Research Capacity Building', 'icon' => 'seminar',
            'href' => SITE_URL . 'pages/shared/activities.php',
            'children' => [
                [
                    'title' => 'Research Activities', 'icon' => 'activity',
                    'children' => [
                        [
                            'title' => 'Add', 'icon' => 'activity',
                            'links' => [
                                [SITE_URL . 'pages/admin/admin-research-module.php?module=add_upcoming_activity', 'Upcoming', 'seminar'],
                                [SITE_URL . 'pages/admin/admin-research-module.php?module=add_conducted_activity', 'Completed', 'records'],
                            ],
                        ],
                        [
                            'title' => 'View', 'icon' => 'monitor',
                            'links' => [
                                [SITE_URL . 'pages/admin/admin-research-module.php?module=upcoming_activities', 'Upcoming', 'document'],
                                [SITE_URL . 'pages/admin/admin-research-module.php?module=ongoing_activities', 'Ongoing', 'monitor'],
                                [SITE_URL . 'pages/admin/admin-research-module.php?module=conducted_activities', 'Completed', 'output'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            'title' => 'Research Development', 'icon' => 'records',
            'links' => [
                [SITE_URL . 'pages/admin/admin-research-module.php?module=research_proposals', 'Research Proposals', 'document'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=research_review', 'Chapter Review', 'monitor'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=research_approval', 'Approved Proposals', 'reports'],
            ],
        ],
        [
            'title' => 'Research Implementation & Outputs', 'icon' => 'output',
            'links' => [
                [SITE_URL . 'pages/admin/admin-research-module.php?module=ongoing_research', 'Ongoing Research', 'monitor'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=completed_research', 'Completed Research', 'records'],
                [SITE_URL . 'pages/admin/admin-public-releases.php', 'Public Research Releases', 'repository'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=research_outputs', 'Research Outputs', 'output'],
            ],
        ],
        [
            'title' => 'Publication & Dissemination', 'icon' => 'publication',
            'links' => [
                [SITE_URL . 'pages/admin/admin-publications.php', 'All Records', 'publication'],
                [SITE_URL . 'pages/admin/admin-publication-form.php', 'Add New', 'document'],
                [SITE_URL . 'pages/admin/admin-publication-reports.php', 'Reports', 'reports'],
            ],
        ],
        [
            'title' => 'Intellectual Property & Utilization', 'icon' => 'copyright',
            'links' => [
                [SITE_URL . 'pages/admin/admin-research-module.php?module=copyrights', 'Copyrights', 'copyright'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=patents_utility_models', 'Patents / Utility Models', 'output'],
                [SITE_URL . 'pages/admin/admin-research-module.php?module=innovation_technology_transfer', 'Innovation & Technology Transfer', 'users'],
            ],
        ],
        [
            'title' => 'Faculty Research & Engagement', 'icon' => 'users',
            'links' => [
                [SITE_URL . 'pages/admin/admin-researchers.php?role=faculty', 'Faculty Research Profiles', 'profile'],
                [SITE_URL . 'pages/admin/admin-earist-annex-d.php', 'Annex D Preparation & History', 'document'],
                [SITE_URL . 'pages/admin/admin-faculty-engagement.php', 'Research Engagement Records', 'activity'],
                [SITE_URL . 'pages/admin/admin-faculty-collaboration.php', 'Collaboration & Mentorship', 'users'],
                [SITE_URL . 'pages/admin/admin-reports.php?report=faculty', 'Faculty Research Analytics', 'reports'],
            ],
        ],
        [
            'title' => 'Institute administration', 'icon' => 'settings',
            'links' => [
                [SITE_URL . 'pages/admin/admin-reports.php', 'Reports', 'reports'],
                [SITE_URL . 'pages/admin/admin-users.php', 'Users', 'users'],
                [SITE_URL . 'pages/admin/admin-programs.php', 'Programs', 'records'],
                [SITE_URL . 'pages/admin/admin-research-areas.php', 'Research Classification', 'repository'],
                [SITE_URL . 'pages/admin/admin-logs.php', 'Activity log', 'logs'],
                [SITE_URL . 'pages/admin/admin-backup.php', 'Backup & recovery', 'backup'],
                [SITE_URL . 'pages/admin/admin-settings.php', 'Settings', 'settings'],
            ],
        ],
        [
            'title' => 'Communication', 'icon' => 'messages',
            'links' => [
                [SITE_URL . 'pages/shared/messages.php', 'Messages', 'messages'],
                [SITE_URL . 'pages/admin/admin-contact.php', 'Contact messages', 'contact'],
                [SITE_URL . 'pages/shared/profile.php', 'Profile', 'profile'],
            ],
        ],
    ];

    $current_basename = basename(str_replace('\\', '/', (string) $current_page));
    if ($current_basename !== '' && !str_contains($current_basename, '.')) {
        $current_basename .= '.php';
    }
    // Module links redirect through admin-research-module.php to their role destinations.
    // Preserve the originating module so its sidebar item remains active and
    // the parent details group stays open after the redirect.
    $current_module_key = trim((string) ($_GET['module_page'] ?? ''));
    if ($current_module_key === '') {
        $current_module_key = match ($current_basename) {
            'activities.php', 'activity-form.php', 'activity-detail.php' => match (true) {
                in_array((string) ($_GET['capacity_page'] ?? ''), ['seminars', 'trainings', 'workshops'], true) => (string) $_GET['capacity_page'],
                (string) ($_GET['type'] ?? '') === 'seminar' => 'seminars',
                (string) ($_GET['type'] ?? '') === 'training' => 'trainings',
                (string) ($_GET['type'] ?? '') === 'workshop' => 'workshops',
                default => '',
            },
            'publications.php', 'publication-form.php', 'publication-detail.php',
            'admin-publications.php', 'admin-publication-form.php', 'admin-publication-detail.php', 'admin-publication-reports.php' => match (true) {
                (string) ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'journal' => 'journal_publications',
                (string) ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'conference' => 'conference_presentations',
                (string) ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'book_chapter' => 'book_chapters',
                (string) ($_GET['status'] ?? '') === 'published' => 'research_outputs',
                default => '',
            },
            'copyrights.php', 'copyright-form.php', 'copyright-detail.php' => match (true) {
                in_array((string) ($_GET['ip_scope'] ?? ''), ['patents_utility_models', 'innovation_technology_transfer'], true) => (string) $_GET['ip_scope'],
                in_array((string) ($_GET['output_type'] ?? ''), ['patent', 'utility_model'], true) => 'patents_utility_models',
                in_array((string) ($_GET['output_type'] ?? ''), ['innovation', 'technology_transfer'], true) => 'innovation_technology_transfer',
                default => 'copyrights',
            },
            'admin-research-management.php' => match (true) {
                (string) ($_GET['queue'] ?? '') === 'review' => 'research_review',
                (string) ($_GET['status'] ?? '') === 'proposal' => 'research_proposals',
                (string) ($_GET['status'] ?? '') === 'approved' => 'research_approval',
                (string) ($_GET['status'] ?? '') === 'ongoing' => 'ongoing_research',
                (string) ($_GET['status'] ?? '') === 'completed' => 'completed_research',
                default => '',
            },
            'research-archive.php' => 'completed_research',
            default => '',
        };
    }
    $is_active = function ($href) use ($current_page, $current_basename, $current_module_key) {
        if ($href === SITE_URL . 'pages/admin/admin-publications.php?publication_type=book_chapter') {
            return $current_module_key === 'book_chapters' || ($current_module_key === '' &&
                (string) ($_GET['publication_type'] ?? $_GET['record_type'] ?? '') === 'book_chapter' && in_array($current_basename, [
                'admin-publications.php', 'admin-publication-form.php', 'admin-publication-detail.php', 'admin-publication-reports.php',
            ], true));
        }
        if ($href === $current_page) return true;
        $target = parse_url((string) $href);
        if (basename(str_replace('\\', '/', (string) ($target['path'] ?? ''))) === 'admin-research-module.php') {
            $target_query = [];
            parse_str((string) ($target['query'] ?? ''), $target_query);
            return $current_module_key !== '' && $current_module_key === (string) ($target_query['module'] ?? '');
        }
        $target_basename = basename(str_replace('\\', '/', (string) ($target['path'] ?? '')));
        if ($current_basename === 'admin-publication-detail.php' && $target_basename === 'admin-publications.php') return true;
        if ($current_basename === '' || $current_basename !== $target_basename) return false;

        $target_query = [];
        parse_str((string) ($target['query'] ?? ''), $target_query);
        if (isset($_GET['repository']) && !isset($target_query['repository'])) return false;
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
    $group_has_active_link = function (array $group) use (&$group_has_active_link, $is_active): bool {
        if (isset($group['href']) && $is_active($group['href'])) return true;
        foreach ($group['links'] ?? [] as $link) {
            if ($is_active($link[0])) return true;
        }
        foreach ($group['children'] ?? [] as $child_group) {
            if ($group_has_active_link($child_group)) return true;
        }
        return false;
    };

    $section_label = $current_basename === 'admin-dashboard.php' ? 'Dashboard' : 'Administration';
    foreach ($nav_groups as $group) {
        if ($group_has_active_link($group)) {
            $section_label = $group['title'];
            break;
        }
    }

    $url_style = SITE_URL . 'css/style.css?v=' . filemtime(__DIR__ . '/../css/style.css');
    $url_shell = SITE_URL . 'css/admin-shell.css?v=' . filemtime(__DIR__ . '/../css/admin-shell.css');
    $url_logout = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#101d3d">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Admin — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin-body">
<div class="admin-dashboard" data-role-shell="admin">
  <a class="admin-skip-link" href="#admin-main-content">Skip to main content</a>
  <button type="button" class="admin-sidebar-scrim" aria-label="Close administrator navigation" tabindex="-1"></button>
  <aside class="admin-sidebar" id="admin-sidebar" aria-label="Administrator navigation" data-role-shell-sidebar>
    <div class="admin-sidebar-header">
      <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" class="admin-sidebar-logo" aria-label="RMS dashboard">
        <img src="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="RMS logo">
      </a>
      <div class="admin-sidebar-brand-text">
        <div class="admin-sidebar-brand">EARIST Cavite</div>
        <small class="admin-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="admin-sidebar-nav" aria-label="Primary" data-role-shell-nav>
      <?php $dashboard_active = $current_basename === 'admin-dashboard.php'; ?>
      <a class="admin-nav-item admin-nav-dashboard<?php echo $dashboard_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" title="Dashboard"<?php echo $dashboard_active ? ' aria-current="page"' : ''; ?>>
        <span class="admin-nav-icon"><?php echo adminShellIcon('dashboard'); ?></span><span class="admin-nav-label">Dashboard</span>
      </a>
      <?php foreach ($nav_groups as $group): ?>
        <?php
          $group_id = 'nav-group-' . substr(sha1($group['title']), 0, 8);
          $group_open = $group_has_active_link($group);
          $group_has_link = !empty($group['href']);
          $group_link_active = $group_has_link && $is_active($group['href']);
        ?>
        <?php if ($group_has_link): ?>
          <div class="admin-nav-group admin-nav-linked">
            <a class="admin-nav-item admin-nav-group-link<?php echo $group_link_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($group['href'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $group_link_active ? ' aria-current="page"' : ''; ?>>
              <span class="admin-nav-icon"><?php echo adminShellIcon($group['icon']); ?></span>
              <span class="admin-nav-label"><?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <details class="admin-nav-group admin-nav-disclosure"<?php echo $group_open ? ' open' : ''; ?>>
              <summary class="admin-nav-group-summary admin-nav-disclosure-toggle" title="<?php echo htmlspecialchars('Toggle ' . $group['title'] . ' submenu', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars('Toggle ' . $group['title'] . ' submenu', ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $group_id; ?>">
                <span class="admin-nav-chevron" aria-hidden="true"></span>
              </summary>
        <?php else: ?>
          <details class="admin-nav-group"<?php echo $group_open ? ' open' : ''; ?>>
            <summary class="admin-nav-group-summary" title="<?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $group_id; ?>">
              <span class="admin-nav-icon"><?php echo adminShellIcon($group['icon']); ?></span>
              <span class="admin-nav-label"><?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="admin-nav-chevron" aria-hidden="true"></span>
            </summary>
        <?php endif; ?>
          <div class="admin-nav-subitems" id="<?php echo $group_id; ?>">
            <div class="admin-nav-subitems-inner">
              <?php if (!empty($group['children'])): ?>
                <?php foreach ($group['children'] as $child_group): ?>
                  <?php
                    $child_group_id = 'nav-group-' . substr(sha1($group['title'] . '|' . $child_group['title']), 0, 8);
                    $child_group_open = $group_has_active_link($child_group);
                  ?>
                  <details class="admin-nav-group admin-nav-subgroup"<?php echo $child_group_open ? ' open' : ''; ?>>
                    <summary class="admin-nav-group-summary" title="<?php echo htmlspecialchars($child_group['title'], ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $child_group_id; ?>">
                      <span class="admin-nav-icon"><?php echo adminShellIcon($child_group['icon']); ?></span>
                      <span class="admin-nav-label"><?php echo htmlspecialchars($child_group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <span class="admin-nav-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="admin-nav-subitems" id="<?php echo $child_group_id; ?>">
                      <div class="admin-nav-subitems-inner">
                        <?php if (!empty($child_group['children'])): ?>
                          <?php foreach ($child_group['children'] as $nested_group): ?>
                            <?php
                              $nested_group_id = 'nav-group-' . substr(sha1($group['title'] . '|' . $child_group['title'] . '|' . $nested_group['title']), 0, 8);
                              $nested_group_open = $group_has_active_link($nested_group);
                            ?>
                            <details class="admin-nav-group admin-nav-subgroup"<?php echo $nested_group_open ? ' open' : ''; ?>>
                              <summary class="admin-nav-group-summary" title="<?php echo htmlspecialchars($nested_group['title'], ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $nested_group_id; ?>">
                                <span class="admin-nav-icon"><?php echo adminShellIcon($nested_group['icon']); ?></span>
                                <span class="admin-nav-label"><?php echo htmlspecialchars($nested_group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="admin-nav-chevron" aria-hidden="true"></span>
                              </summary>
                              <div class="admin-nav-subitems" id="<?php echo $nested_group_id; ?>">
                                <div class="admin-nav-subitems-inner">
                                  <?php foreach ($nested_group['links'] as [$href, $label, $icon]): $active = $is_active($href); ?>
                                    <a class="admin-nav-item admin-nav-subitem<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                      <span class="admin-nav-icon"><?php echo adminShellIcon($icon); ?></span><span class="admin-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                  <?php endforeach; ?>
                                </div>
                              </div>
                            </details>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <?php foreach ($child_group['links'] as [$href, $label, $icon]): $active = $is_active($href); ?>
                            <a class="admin-nav-item admin-nav-subitem<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                              <span class="admin-nav-icon"><?php echo adminShellIcon($icon); ?></span><span class="admin-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </details>
                <?php endforeach; ?>
              <?php else: ?>
                <?php foreach ($group['links'] as [$href, $label, $icon]): $active = $is_active($href); ?>
                  <a class="admin-nav-item admin-nav-subitem<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                    <span class="admin-nav-icon"><?php echo adminShellIcon($icon); ?></span><span class="admin-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php if ($group_has_link): ?>
            </details>
          </div>
        <?php else: ?>
          </details>
        <?php endif; ?>
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

  <main class="admin-main" id="admin-main-content" tabindex="-1">
    <header class="admin-topbar" data-role-shell-topbar>
      <div class="admin-topbar-left">
        <button type="button" class="admin-mobile-menu-btn" aria-label="Open administrator navigation" aria-controls="admin-sidebar" aria-expanded="false"><span aria-hidden="true">&#9776;</span></button>
        <button type="button" class="admin-collapse-btn" aria-label="Collapse sidebar" aria-controls="admin-sidebar" title="Collapse sidebar" data-role-shell-collapse><span aria-hidden="true">&#8249;</span></button>
        <div class="admin-topbar-search">
          <span class="admin-topbar-search-icon" aria-hidden="true">⌕</span>
          <input type="search" id="admin-page-search" aria-label="Search pages in RMS" placeholder="Search pages..." value="" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="admin-search-suggestions" aria-expanded="false" data-role-shell-search>
          <div class="admin-search-suggestions" id="admin-search-suggestions" role="listbox" aria-label="Matching RMS pages" hidden data-role-shell-suggestions></div>
        </div>
      </div>
      <div class="admin-topbar-right">
        <?php renderNotificationPopover($user_id, $unread_notifications, 'admin'); ?>
        <div class="admin-topbar-separator" aria-hidden="true"></div>
        <details class="admin-profile-menu">
          <summary class="admin-topbar-user" aria-label="Open administrator profile menu">
            <span class="admin-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="admin-topbar-user-text"><span class="admin-topbar-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></span><span class="admin-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <span class="admin-profile-chevron" aria-hidden="true"></span>
          </summary>
          <div class="admin-profile-dropdown">
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/profile.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo adminShellIcon('profile'); ?><span>Profile</span></a>
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/settings.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo adminShellIcon('settings'); ?><span>Account settings</span></a>
            <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" data-logout-confirm>
              <?php echo csrfField(); ?>
              <button type="submit" class="admin-profile-action"><?php echo adminShellIcon('logout'); ?><span>Log out</span></button>
            </form>
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
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js?v=' . filemtime(__DIR__ . '/../js/public-motion.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/role-shell-controls.js?v=' . filemtime(__DIR__ . '/../js/role-shell-controls.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(() => {
  const shell = document.querySelector('.admin-dashboard');
  const sidebar = document.querySelector('.admin-sidebar');
  const mobileButton = document.querySelector('.admin-mobile-menu-btn');
  const scrim = document.querySelector('.admin-sidebar-scrim');
  if (!shell || !sidebar) return;

  const closeDrawer = () => {
    sidebar.classList.remove('is-open');
    shell.classList.remove('drawer-open');
    if (mobileButton) mobileButton.setAttribute('aria-expanded', 'false');
  };
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

  document.querySelectorAll('.admin-nav-item, .admin-nav-group-summary').forEach((navItem) => {
    navItem.addEventListener('click', () => {
      document.querySelectorAll('.admin-nav-item.is-clicked, .admin-nav-group-summary.is-clicked').forEach((item) => item.classList.remove('is-clicked'));
      navItem.classList.remove('is-clicked');
      void navItem.offsetWidth;
      navItem.classList.add('is-clicked');
      shell.classList.add('nav-transitioning');
      window.setTimeout(() => {
        navItem.classList.remove('is-clicked');
        shell.classList.remove('nav-transitioning');
      }, 260);
    });
  });

})();
</script>
<style>
  body.admin-body .admin-page-content {
    padding: 24px 22px 40px;
    background: #f8fafc;
  }

  body.admin-body .admin-page-content .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin: 0 0 22px;
  }

  body.admin-body .admin-page-content .stat-card {
    min-height: 138px;
    padding: 18px 18px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    transition: transform .18s ease, box-shadow .18s ease;
  }

  body.admin-body .admin-page-content .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 22px rgba(91, 30, 188, .08);
  }

  body.admin-body .admin-page-content .stat-number {
    margin: 0 0 8px;
    color: #111827;
    font-size: clamp(28px, 2vw, 34px);
    font-weight: 700;
    line-height: 1;
    letter-spacing: -.04em;
  }

  body.admin-body .admin-page-content .stat-label {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
  }

  body.admin-body .admin-page-content .card,
  body.admin-body .admin-page-content .directory-card,
  body.admin-body .admin-page-content .users-workspace,
  body.admin-body .admin-page-content .reports-workspace,
  body.admin-body .admin-page-content .research-workspace,
  body.admin-body .admin-page-content .archive-workspace,
  body.admin-body .admin-page-content .settings-workspace,
  body.admin-body .admin-page-content .backup-workspace {
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .04);
  }

  body.admin-body .admin-page-content .card {
    padding: 22px;
    margin: 0 0 20px;
  }

  body.admin-body .admin-page-content .card-header,
  body.admin-body .admin-page-content .directory-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }

  body.admin-body .admin-page-content .card-title,
  body.admin-body .admin-page-content .directory-title {
    margin: 0;
    color: #111827;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -.02em;
  }

  body.admin-body .admin-page-content .filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 0 0 18px;
    padding: 12px 14px;
    border: 1px solid #edf2f7;
    border-radius: 14px;
    background: #f8fafc;
  }

  body.admin-body .admin-page-content .search-input,
  body.admin-body .admin-page-content .filter-select,
  body.admin-body .admin-page-content .form-control,
  body.admin-body .admin-page-content input,
  body.admin-body .admin-page-content select,
  body.admin-body .admin-page-content textarea {
    min-height: 42px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    font-size: 14px;
    font-weight: 500;
    transition: border-color .18s ease, box-shadow .18s ease;
  }

  body.admin-body .admin-page-content .search-input,
  body.admin-body .admin-page-content .filter-select,
  body.admin-body .admin-page-content .form-control {
    min-width: 180px;
  }

  body.admin-body .admin-page-content .search-field {
    position: relative;
    flex: 1 1 240px;
  }

  body.admin-body .admin-page-content .search-field::before {
    content: '⌕';
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #7b8798;
    font-size: 18px;
    pointer-events: none;
  }

  body.admin-body .admin-page-content .search-field .search-input,
  body.admin-body .admin-page-content .search-field input {
    padding-left: 40px;
  }

  body.admin-body .admin-page-content .search-input:focus,
  body.admin-body .admin-page-content .filter-select:focus,
  body.admin-body .admin-page-content .form-control:focus,
  body.admin-body .admin-page-content input:focus,
  body.admin-body .admin-page-content select:focus,
  body.admin-body .admin-page-content textarea:focus {
    border-color: rgba(91, 30, 188, .45);
    box-shadow: 0 0 0 3px rgba(91, 30, 188, .08);
    outline: none;
  }

  body.admin-body .admin-page-content .btn,
  body.admin-body .admin-page-content a.btn,
  body.admin-body .admin-page-content button.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 10px 18px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all .18s ease;
  }

  body.admin-body .admin-page-content .btn:hover,
  body.admin-body .admin-page-content a.btn:hover,
  body.admin-body .admin-page-content button.btn:hover {
    transform: translateY(-1px);
  }

  body.admin-body .admin-page-content .btn-primary {
    background: linear-gradient(135deg, #5b1ebc 0%, #7c3aed 100%);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 8px 16px rgba(91, 30, 188, .16);
  }

  body.admin-body .admin-page-content .btn-primary:hover {
    background: linear-gradient(135deg, #4b1aa3 0%, #6d28d9 100%);
  }

  body.admin-body .admin-page-content .btn-secondary {
    background: #fff;
    border-color: #e5e7eb;
    color: #111827;
  }

  body.admin-body .admin-page-content .btn-secondary:hover {
    background: #f8fafc;
  }

  body.admin-body .admin-page-content .btn-danger {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
  }

  body.admin-body .admin-page-content .btn-danger:hover {
    background: #dc2626;
  }

  body.admin-body .admin-page-content .btn-success,
  body.admin-body .admin-page-content .btn-warning {
    color: #fff;
  }

  body.admin-body .admin-page-content .btn-success {
    background: #16a34a;
  }

  body.admin-body .admin-page-content .btn-warning {
    background: #f59e0b;
  }

  body.admin-body .admin-page-content .table-wrap,
  body.admin-body .admin-page-content table {
    border-radius: 14px;
    overflow: hidden;
  }

  body.admin-body .admin-page-content .table-wrap {
    border: 1px solid #e5e7eb;
    background: #fff;
  }

  body.admin-body .admin-page-content table {
    width: 100%;
    border-collapse: collapse;
  }

  body.admin-body .admin-page-content thead th {
    padding: 12px 16px;
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
  }

  body.admin-body .admin-page-content td {
    padding: 14px 16px;
    color: #1f2937;
    font-size: 14px;
    border-top: 1px solid #eef2f7;
    vertical-align: middle;
  }

  body.admin-body .admin-page-content tbody tr:hover {
    background: #f8fafc;
  }

  body.admin-body .admin-page-content .badge,
  body.admin-body .admin-page-content .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
  }

  body.admin-body .admin-page-content .badge-draft,
  body.admin-body .admin-page-content .badge-archived,
  body.admin-body .admin-page-content .status-archived,
  body.admin-body .admin-page-content .status-draft,
  body.admin-body .admin-page-content .status-pending {
    background: #f1f5f9;
    color: #475569;
  }

  body.admin-body .admin-page-content .badge-proposal,
  body.admin-body .admin-page-content .badge-crec,
  body.admin-body .admin-page-content .status-submitted,
  body.admin-body .admin-page-content .status-under-review,
  body.admin-body .admin-page-content .status-crec,
  body.admin-body .admin-page-content .status-active {
    background: #dbeafe;
    color: #1d4ed8;
  }

  body.admin-body .admin-page-content .badge-erec,
  body.admin-body .admin-page-content .status-erec,
  body.admin-body .admin-page-content .status-approved {
    background: #ede9fe;
    color: #6d28d9;
  }

  body.admin-body .admin-page-content .badge-approved,
  body.admin-body .admin-page-content .status-ongoing,
  body.admin-body .admin-page-content .status-completed,
  body.admin-body .admin-page-content .badge-ongoing,
  body.admin-body .admin-page-content .badge-completed {
    background: #dcfce7;
    color: #15803d;
  }

  body.admin-body .admin-page-content .admin-flash {
    margin: 0 0 18px;
    padding: 12px 14px;
    border: 1px solid transparent;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
  }

  body.admin-body .admin-page-content .admin-flash-ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #166534;
  }

  body.admin-body .admin-page-content .admin-flash-err {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
  }

  body.admin-body .admin-page-content .module-hub {
    max-width: 1460px;
    margin: 0 auto;
    color: #111827;
  }

  body.admin-body .admin-page-content .module-intro {
    display: flex !important;
    align-items: flex-end !important;
    justify-content: space-between !important;
    gap: 24px !important;
    margin: 0 0 22px !important;
    padding: 28px 30px !important;
    border-left: 4px solid #5b1ebc !important;
    border-radius: 18px 18px 18px 4px !important;
    background: linear-gradient(135deg, rgba(91, 30, 188, .08), rgba(15, 108, 189, .04)) !important;
    box-shadow: inset 0 0 0 1px rgba(91, 30, 188, .04) !important;
  }

  body.admin-body .admin-page-content .module-kicker {
    margin: 0 0 8px;
    color: #5b1ebc;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
  }

  body.admin-body .admin-page-content .module-intro h1 {
    margin: 0;
    color: #111827;
    font-size: clamp(27px, 2.5vw, 40px);
    line-height: 1.08;
    letter-spacing: -.04em;
  }

  body.admin-body .admin-page-content .module-intro p {
    max-width: 700px;
    margin: 10px 0 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
  }

  body.admin-body .admin-page-content .hub-button,
  body.admin-body .admin-page-content .repository-link,
  body.admin-body .admin-page-content .row-link,
  body.admin-body .admin-page-content .row-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 15px;
    border: 1px solid #dfe7ef;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
  }

  body.admin-body .admin-page-content .hub-button,
  body.admin-body .admin-page-content .repository-link {
    white-space: nowrap;
  }

  body.admin-body .admin-page-content .hub-button {
    background: linear-gradient(135deg, #5b1ebc 0%, #7c3aed 100%) !important;
    border-color: transparent !important;
    color: #fff !important;
    box-shadow: 0 8px 16px rgba(91, 30, 188, .14) !important;
  }

  body.admin-body .admin-page-content .hub-button.secondary,
  body.admin-body .admin-page-content .repository-link {
    background: #fff !important;
    border-color: #e5e7eb !important;
    color: #111827 !important;
  }

  body.admin-body .admin-page-content .hub-button:hover,
  body.admin-body .admin-page-content .repository-link:hover,
  body.admin-body .admin-page-content .row-link:hover,
  body.admin-body .admin-page-content .row-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 16px rgba(15, 23, 42, .06);
  }

  body.admin-body .admin-page-content .stat-grid,
  body.admin-body .admin-page-content .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin: 0 0 22px;
  }

  body.admin-body .admin-page-content .stat-card {
    min-height: 136px !important;
    padding: 18px 18px 16px !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 16px !important;
    background: #fff !important;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04) !important;
  }

  body.admin-body .admin-page-content .stat-card strong {
    display: block;
    color: #111827;
    font-size: clamp(29px, 2vw, 34px);
    line-height: 1;
    letter-spacing: -.04em;
    font-variant-numeric: tabular-nums;
  }

  body.admin-body .admin-page-content .stat-card span,
  body.admin-body .admin-page-content .stat-card small {
    display: block;
    margin-top: 8px;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
  }

  body.admin-body .admin-page-content .hub-panel {
    margin: 0 0 22px !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 18px !important;
    background: #fff !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .04) !important;
    overflow: hidden !important;
  }

  body.admin-body .admin-page-content .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid #edf2f7;
    background: #fff;
  }

  body.admin-body .admin-page-content .panel-head h2 {
    margin: 0;
    color: #111827;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -.02em;
  }

  body.admin-body .admin-page-content .panel-head p {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 12px;
  }

  body.admin-body .admin-page-content .filter-form {
    display: grid !important;
    grid-template-columns: minmax(220px, 1.2fr) repeat(2, minmax(160px, .7fr)) auto !important;
    gap: 10px !important;
    align-items: end !important;
    padding: 16px 18px 14px !important;
    border-bottom: 1px solid #edf2f7 !important;
    background: #f8fafc !important;
  }

  body.admin-body .admin-page-content .field {
    display: flex;
    flex-direction: column;
    gap: 7px;
  }

  body.admin-body .admin-page-content .field label {
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  body.admin-body .admin-page-content .field input,
  body.admin-body .admin-page-content .field select {
    min-height: 42px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #111827;
    font-size: 14px;
  }

  body.admin-body .admin-page-content .filter-form .filter-actions {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  body.admin-body .admin-page-content .table-wrap,
  body.admin-body .admin-page-content .hub-table,
  body.admin-body .admin-page-content table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
  }

  body.admin-body .admin-page-content .hub-table thead th,
  body.admin-body .admin-page-content table thead th {
    padding: 12px 16px;
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    text-align: left;
    border-bottom: 1px solid #edf2f7;
  }

  body.admin-body .admin-page-content .hub-table td,
  body.admin-body .admin-page-content table td {
    padding: 14px 16px;
    color: #1f2937;
    font-size: 14px;
    border-top: 1px solid #edf2f7;
    vertical-align: middle;
  }

  body.admin-body .admin-page-content .hub-table tbody tr:hover,
  body.admin-body .admin-page-content table tbody tr:hover {
    background: #f8fafc;
  }

  body.admin-body .admin-page-content .title-cell a,
  body.admin-body .admin-page-content .recent-item strong a,
  body.admin-body .admin-page-content .upcoming-item strong a {
    color: #111827;
    text-decoration: none;
  }

  body.admin-body .admin-page-content .title-cell a:hover,
  body.admin-body .admin-page-content .recent-item strong a:hover,
  body.admin-body .admin-page-content .upcoming-item strong a:hover {
    color: #5b1ebc;
  }

  body.admin-body .admin-page-content .badge,
  body.admin-body .admin-page-content .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
  }

  body.admin-body .admin-page-content .badge.tone-available,
  body.admin-body .admin-page-content .badge.tone-success,
  body.admin-body .admin-page-content .badge.tone-published,
  body.admin-body .admin-page-content .badge.tone-approved,
  body.admin-body .admin-page-content .status-approved,
  body.admin-body .admin-page-content .status-completed,
  body.admin-body .admin-page-content .status-ongoing {
    background: #dcfce7;
    color: #15803d;
  }

  body.admin-body .admin-page-content .badge.tone-review,
  body.admin-body .admin-page-content .badge.tone-warn,
  body.admin-body .admin-page-content .status-under_review,
  body.admin-body .admin-page-content .status-pending,
  body.admin-body .admin-page-content .status-submitted {
    background: #dbeafe;
    color: #1d4ed8;
  }

  body.admin-body .admin-page-content .badge.tone-muted,
  body.admin-body .admin-page-content .badge.tone-archived,
  body.admin-body .admin-page-content .status-draft,
  body.admin-body .admin-page-content .status-archived {
    background: #f1f5f9;
    color: #475569;
  }

  body.admin-body .admin-page-content .spacer,
  body.admin-body .admin-page-content .empty-state {
    padding: 28px 20px;
    text-align: center;
    color: #64748b;
  }

  body.admin-body .admin-page-content .empty-state h2 {
    margin: 10px 0 8px;
    color: #111827;
    font-size: 18px;
  }

  body.admin-body .admin-page-content .empty-state p {
    margin: 0;
    color: #64748b;
  }

  body.admin-body .admin-page-content .empty-mark {
    display: inline-grid;
    place-items: center;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: rgba(91, 30, 188, .08);
    color: #5b1ebc;
    font-size: 20px;
  }

  body.admin-body .admin-page-content .recent-list,
  body.admin-body .admin-page-content .upcoming-list,
  body.admin-body .admin-page-content .pipeline-list {
    display: grid;
    gap: 12px;
    padding: 18px 20px;
  }

  body.admin-body .admin-page-content .recent-item,
  body.admin-body .admin-page-content .upcoming-item {
    display: grid;
    gap: 6px;
    padding: 14px 16px;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    background: #fff;
  }

  body.admin-body .admin-page-content .recent-item span,
  body.admin-body .admin-page-content .upcoming-item span {
    color: #64748b;
    font-size: 12px;
  }

  body.admin-body .admin-page-content .upcoming-date,
  body.admin-body .admin-page-content .upcoming-item .upcoming-date {
    color: #5b1ebc;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
  }

  body.admin-body .admin-page-content .flash,
  body.admin-body .admin-page-content .admin-flash {
    margin: 0 0 16px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
  }

  body.admin-body .admin-page-content .flash-success,
  body.admin-body .admin-page-content .admin-flash-ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
  }

  body.admin-body .admin-page-content .flash-error,
  body.admin-body .admin-page-content .admin-flash-err {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
  }

  @media (max-width: 900px) {
    body.admin-body .admin-page-content .module-intro {
      display: block !important;
      padding: 22px 18px !important;
    }

    body.admin-body .admin-page-content .filter-form {
      grid-template-columns: 1fr !important;
      padding: 14px !important;
    }

    body.admin-body .admin-page-content .filter-actions {
      justify-content: flex-start;
    }
  }
</style>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/notification-popover.js?v=' . filemtime(__DIR__ . '/../js/notification-popover.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
<?php
}
