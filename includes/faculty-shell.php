<?php
/**
 * Faculty Shell — shared sidebar, topbar, and page-content wrapper for Faculty.
 *
 * Usage at the top of every faculty page (after requireRole, before any HTML output):
 *
 *     require_once __DIR__ . '/../../includes/faculty-shell.php';
 *     $user = getCurrentUser();
 *     renderFacultyShell($user, 'faculty-dashboard', 'Faculty Dashboard', 'Review chapters and track your advisees.');
 *     ... page body ...
 *     renderFacultyShellClose();
 *
 * renderFacultyShell() opens <!DOCTYPE>, <head>, the dashboard wrapper, the sidebar,
 * the main area, the topbar, and <div class="faculty-page-content">.
 * renderFacultyShellClose() emits the matching closing tags plus </body></html>.
 *
 * All navigation, CSS, and logout URLs are built from SITE_URL (defined in
 * includes/config.php with a trailing slash) so the same sidebar works from
 * any directory — /pages/faculty/, /pages/shared/, etc. — without 404s.
 *
 * @param array  $user          Current user row from getCurrentUser()
 * @param string $current_page  Current page slug (e.g. 'faculty-dashboard', 'my-research', 'messages.php')
 * @param string $page_title    Title shown in the topbar <h1>
 * @param string $page_subtitle Subtitle shown under the title (pass '' for none)
 */
require_once __DIR__ . '/logout-transition.php';
require_once __DIR__ . '/notification-popover.php';

/**
 * Returns an inline SVG icon for the faculty sidebar, mirroring adminShellIcon().
 * All icons use viewBox="0 0 24 24", stroke="currentColor", stroke-width="1.7".
 */
function facultyShellIcon(string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'records' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'copyright' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M14.83 14.83a4 4 0 0 1-5.66 0"/><path d="M9.17 9.17a4 4 0 0 0 5.66 0"/></svg>',
        'document' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'profile' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 20v-2a6 6 0 0 1 12 0v2"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'seminar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 3a2.828 2.828 0 1 1 4 4V7"/><path d="M7 17v4a2.828 2.828 0 1 1-4-4h4"/><path d="M17 21h4a2.828 2.828 0 1 1 0-4h-4"/><path d="M3 7v-4a2.828 2.828 0 1 1 4 4h-4"/></svg>',
        'activity' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'publication' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        'monitor' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];
    return $icons[$name] ?? $icons['dashboard'];
}

function renderFacultyShell($user, $current_page, $page_title, $page_subtitle = '', $extra_css = []) {
    global $conn;

    // Normalise the user object
    $shell_user = [
        'first_name' => $user['first_name'] ?? '',
        'last_name'  => $user['last_name']  ?? '',
        'role'       => $user['role']       ?? 'faculty',
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
        $initials = 'FA';
    }

    $full_name = trim($shell_user['first_name'] . ' ' . $shell_user['last_name']);
    if ($full_name === '') {
        $full_name = 'Faculty';
    }

    $role_label = 'Faculty';

    $page_title_safe    = $page_title    !== '' ? $page_title    : 'Faculty';
    $subtitle_default   = '';
    $page_subtitle_safe = $page_subtitle !== '' ? $page_subtitle : $subtitle_default;

    // Get unread message and notification counts for badges
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
    $unread_area_counts = getUnreadNotificationCountsByArea($user_id);

    // Faculty navigation follows the work members do in the portal.
    // Each row: [href, label, icon_name, exists, badge_count].
    // Icons are rendered via facultyShellIcon() — see function above.
    $nav = [
        'Dashboard' => [
            [SITE_URL . 'pages/faculty/faculty-dashboard.php', 'Dashboard', 'dashboard', true, 0],
        ],
        'Research Portfolio' => [
            [SITE_URL . 'pages/faculty/faculty-research-records.php', 'View Portfolio', 'records', true, 0],
            [SITE_URL . 'pages/faculty/faculty-research-record-form.php', 'Add Entry', 'document', true, 0],
        ],
        'Document Center' => [
            [SITE_URL . 'pages/faculty/faculty-document-workspace.php', 'DOCX Generator', 'document', true, 0],
            [SITE_URL . 'pages/faculty/faculty-generated-documents.php', 'Generated Documents', 'document', true, 0],
        ],
        'IP Applications' => [
            [SITE_URL . 'pages/shared/copyrights.php', 'Copyright Applications', 'copyright', true, 0],
            [SITE_URL . 'pages/shared/copyrights.php?ip_scope=patents_utility_models&module_page=patents_utility_models', 'Patents / Utility Models', 'copyright', true, 0],
            [SITE_URL . 'pages/shared/copyrights.php?ip_scope=innovation_technology_transfer&module_page=innovation_technology_transfer', 'Innovation & Technology Transfer', 'activity', true, 0],
        ],
        'Publications' => [
            [SITE_URL . 'pages/faculty/faculty-publications.php', 'Publication Archive', 'publication', true, 0],
            [SITE_URL . 'pages/faculty/faculty-publication-form.php', 'Add Publication', 'document', true, 0],
            [SITE_URL . 'pages/faculty/faculty-publication-reports.php', 'Reports', 'monitor', true, 0],
        ],
        'Activities' => [
            [SITE_URL . 'pages/shared/activities.php', 'Research Activities', 'activity', true, 0],
            [SITE_URL . 'pages/faculty/faculty-analytics.php', 'Research Analytics', 'monitor', true, 0],
        ],
        'Profile & Settings' => [
            [SITE_URL . 'pages/faculty/faculty-profile.php', 'Faculty Profile', 'users', true, 0],
            [SITE_URL . 'pages/shared/profile.php', 'Account Profile', 'profile', true, 0],
            [SITE_URL . 'pages/shared/settings.php', 'Account Settings', 'settings', true, 0],
        ],
        'Advising & Engagement' => [
            [SITE_URL . 'pages/faculty/faculty-advising.php', 'Advising', 'seminar', true, 0],
            [SITE_URL . 'pages/faculty/faculty-engagement.php', 'Add an Engagement Record', 'activity', true, 0],
            [SITE_URL . 'pages/faculty/faculty-engagement-history.php', 'My Engagement History', 'activity', true, 0],
        ],
    ];

    // Active-state matcher. Matches either the full target (e.g.
    // 'http://localhost/rms/pages/shared/profile.php') or the bare basename,
    // so shared pages highlight correctly when visited. Selector destinations
    // (tabs/views) must also match the current request query.
    $current_basename = basename(str_replace('\\', '/', (string) $current_page));
    $selector_keys = ['tab', 'view', 'ip_scope'];
    $current_selectors = array_intersect_key($_GET, array_flip($selector_keys));
    $is_active = function ($href) use ($current_page, $current_basename, $current_selectors, $selector_keys) {
        $href_query = parse_url((string) $href, PHP_URL_QUERY);
        $href_params = [];
        if (is_string($href_query) && $href_query !== '') {
            parse_str($href_query, $href_params);
        }
        $href_selectors = array_intersect_key($href_params, array_flip($selector_keys));

        if ($current_selectors || $href_selectors) {
            $href_basename = basename(str_replace('\\', '/', (string) $href));
            $profile_parent_link = $current_basename === 'faculty-profile.php'
                && $href_basename === 'faculty-profile.php'
                && !$href_selectors;
            if (!$profile_parent_link) {
                if (count($current_selectors) !== count($href_selectors)) {
                    return false;
                }
                foreach ($href_selectors as $key => $value) {
                    if (!array_key_exists($key, $current_selectors) || $current_selectors[$key] !== $value) {
                        return false;
                    }
                }
            }
        }

        if ($href === $current_page) {
            return true;
        }

        $href_basename = basename(str_replace('\\', '/', (string) $href));
        $current_slug = pathinfo($current_basename, PATHINFO_FILENAME);
        $href_slug = pathinfo($href_basename, PATHINFO_FILENAME);
        return $current_slug !== '' && $current_slug === $href_slug;
    };

    // Absolute asset/logout URLs (SITE_URL has a trailing slash).
    $url_style   = SITE_URL . 'css/style.css?v=' . filemtime(__DIR__ . '/../css/style.css');
    $url_shell   = SITE_URL . 'css/faculty-shell.css?v=' . filemtime(__DIR__ . '/../css/faculty-shell.css');
    $url_logout  = SITE_URL . 'public/logout.php';
    $page_class  = 'faculty-page--' . preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($current_basename, PATHINFO_FILENAME)));

    // Build extra CSS links
    $extra_css_links = '';
    if (!empty($extra_css) && is_array($extra_css)) {
        foreach ($extra_css as $css_file) {
            $css_path = __DIR__ . '/../css/' . ltrim($css_file, '/');
            if (file_exists($css_path)) {
                $extra_css_links .= '<link rel="stylesheet" href="' . htmlspecialchars(SITE_URL . 'css/' . ltrim($css_file, '/') . '?v=' . filemtime($css_path), ENT_QUOTES, 'UTF-8') . '">' . "\n  ";
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Faculty — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
  <?php echo $extra_css_links; ?>
</head>
<body class="faculty-role <?php echo htmlspecialchars($page_class, ENT_QUOTES, 'UTF-8'); ?>">
<a class="faculty-skip-link" href="#faculty-content">Skip to main content</a>
<div class="faculty-dashboard" data-role-shell="faculty">
  <button type="button" class="faculty-sidebar-scrim" aria-label="Close faculty navigation" tabindex="-1"></button>
  <!-- SIDEBAR -->
  <aside class="faculty-sidebar" id="faculty-sidebar" aria-label="Faculty navigation" data-role-shell-sidebar>
    <div class="faculty-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/faculty/faculty-dashboard.php" class="faculty-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="faculty-sidebar-brand-text">
        <div class="faculty-sidebar-brand">EARIST Cavite</div>
        <small class="faculty-sidebar-role">Faculty</small>
      </div>
    </div>

    <nav class="faculty-sidebar-nav" aria-label="Primary" data-role-shell-nav>
      <?php foreach ($nav as $group => $items): ?>
        <?php if ($group === 'Dashboard'): ?>
          <?php foreach ($items as $item): ?>
            <?php [$href, $label, $icon, $exists] = $item; $active = $exists && $is_active($href); ?>
            <a class="faculty-nav-item faculty-nav-dashboard<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
              <span class="faculty-nav-icon" aria-hidden="true"><?php echo facultyShellIcon($icon); ?></span>
              <span class="faculty-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
          <?php endforeach; ?>
          <?php continue; ?>
        <?php endif; ?>
        <?php
          $group_id = 'faculty-nav-group-' . substr(sha1($group), 0, 8);
          $group_active = false;
          foreach ($items as $candidate_item) {
              if ($is_active($candidate_item[0])) {
                  $group_active = true;
                  break;
              }
          }
          $group_icon = $items[0][2] ?? 'dashboard';
        ?>
        <details class="faculty-nav-group"<?php echo $group_active ? ' open' : ''; ?>>
          <summary class="faculty-nav-group-summary" title="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $group_id; ?>">
            <span class="faculty-nav-icon" aria-hidden="true"><?php echo facultyShellIcon($group_icon); ?></span>
            <span class="faculty-nav-label"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php
              // Research Portfolio group gets notification badge for unread research notifications
              $group_notification_count = $group === 'Research Portfolio'
                  ? $unread_area_counts['my_research']
                  : 0;
            ?>
            <?php if ($group_notification_count > 0): ?>
              <span class="faculty-nav-badge" title="<?php echo $group_notification_count; ?> unread notifications in <?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo $group_notification_count; ?> unread notifications in <?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $group_notification_count > 99 ? '99+' : $group_notification_count; ?></span>
            <?php endif; ?>
            <span class="faculty-nav-chevron" aria-hidden="true"></span>
          </summary>
          <div class="faculty-nav-subitems" id="<?php echo $group_id; ?>">
            <?php foreach ($items as $item): ?>
              <?php
                [$href, $label, $icon, $exists, $badge_count] = $item;
                $active   = $exists ? $is_active($href) : false;
                $classes  = 'faculty-nav-item faculty-nav-subitem' . ($active ? ' active' : '');
                $attrs    = $active ? ' aria-current="page"' : '';
                if (!$exists) {
                    $attrs .= ' data-todo="build"';
                    $href   = '#';
                }
              ?>
              <a class="<?php echo $classes; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attrs; ?>>
                <span class="faculty-nav-icon" aria-hidden="true"><?php echo facultyShellIcon($icon); ?></span>
                <span class="faculty-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($badge_count > 0): ?>
                  <span class="faculty-nav-badge"><?php echo $badge_count; ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>

    </nav>

    <div class="faculty-sidebar-footer">
      <div class="faculty-user-card">
        <div class="faculty-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="faculty-user-info">
          <div class="faculty-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="faculty-user-role faculty-role-line">
            <span><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="faculty-main">
    <header class="faculty-topbar" data-role-shell-topbar>
      <div class="faculty-topbar-left">
        <button type="button" class="faculty-mobile-menu-btn" aria-label="Open faculty navigation" aria-controls="faculty-sidebar" aria-expanded="false">☰</button>
        <button type="button" class="faculty-collapse-btn" aria-label="Collapse sidebar" aria-controls="faculty-sidebar" title="Collapse sidebar" data-role-shell-collapse><span aria-hidden="true">‹</span></button>
        <div class="faculty-topbar-heading">
          <h1 class="faculty-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
          <?php if ($page_subtitle_safe !== ''): ?>
          <p class="faculty-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="faculty-topbar-search">
        <span class="faculty-topbar-search-icon" aria-hidden="true">⌕</span>
        <input type="search" id="faculty-page-search" aria-label="Search pages in RMS" placeholder="Search pages..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="faculty-search-suggestions" aria-expanded="false" data-role-shell-search>
        <div class="faculty-search-suggestions" id="faculty-search-suggestions" role="listbox" aria-label="Matching RMS pages" hidden data-role-shell-suggestions></div>
      </div>
      <div class="faculty-topbar-right">
        <a class="faculty-topbar-message" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/messages.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($unread_messages > 0 ? 'Messages, ' . $unread_messages . ' unread' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($unread_messages > 0 ? $unread_messages . ' unread messages' : 'Messages', ENT_QUOTES, 'UTF-8'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H9l-5 4v-4.5A2.5 2.5 0 0 1 4 13.5v-7Z"/><path d="M8 8h8M8 11h6"/></svg>
          <?php if ($unread_messages > 0): ?><span class="faculty-topbar-message-badge" aria-hidden="true"><?php echo $unread_messages > 99 ? '99+' : $unread_messages; ?></span><?php endif; ?>
        </a>
        <?php renderNotificationPopover($user_id, $unread_notifications, 'faculty'); ?>
        <details class="faculty-profile-menu">
          <summary class="faculty-topbar-user" aria-label="Open faculty profile menu">
            <span class="faculty-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="faculty-topbar-user-text"><span class="faculty-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Faculty', ENT_QUOTES, 'UTF-8'); ?></span><span class="faculty-topbar-user-role faculty-role-line"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></span>
            <span class="faculty-profile-chevron" aria-hidden="true"></span>
          </summary>
          <div class="faculty-profile-dropdown">
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/profile.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo facultyShellIcon('profile'); ?><span>Profile</span></a>
            <a href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/settings.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo facultyShellIcon('settings'); ?><span>Account settings</span></a>
            <form method="post" action="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>" data-logout-confirm>
              <?php echo csrfField(); ?>
              <button type="submit" class="faculty-profile-action"><?php echo facultyShellIcon('logout'); ?><span>Log out</span></button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <div class="faculty-page-content" id="faculty-content" tabindex="-1">
    <?php
}

/**
 * Closes the faculty shell — emits the matching closing wrappers and </body></html>.
 * Pages that call renderFacultyShell() MUST call this once at the end of their body.
 */
function renderFacultyShellClose() {
    ?>
    </div><!-- /.faculty-page-content -->
  </main>
</div><!-- /.faculty-dashboard -->
<?php renderLogoutTransition(); ?>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/notification-popover.js?v=' . filemtime(__DIR__ . '/../js/notification-popover.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js?v=' . filemtime(__DIR__ . '/../js/public-motion.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/role-shell-controls.js?v=' . filemtime(__DIR__ . '/../js/role-shell-controls.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/faculty-dashboard.js?v=' . filemtime(__DIR__ . '/../js/faculty-dashboard.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
    <?php
}
