<?php
/**
 * Admin Shell — shared sidebar, topbar, and page-content wrapper.
 *
 * Usage at the top of every admin page (after requireRole, before any HTML output):
 *
 *     require_once __DIR__ . '/../../includes/admin-shell.php';
 *     $user = getCurrentUser();
 *     renderAdminShell($user, 'admin-users', 'User Management', 'Manage system users and roles');
 *     ... page body ...
 *     renderAdminShellClose();
 *
 * renderAdminShell() opens <!DOCTYPE>, <head>, the dashboard wrapper, the sidebar,
 * the main area, the topbar, and <div class="admin-page-content">.
 * renderAdminShellClose() emits the matching closing tags plus </body></html>.
 *
 * All navigation, CSS, and logout URLs are built from SITE_URL (defined in
 * includes/config.php with a trailing slash) so the same sidebar works from
 * any directory — /pages/admin/, /pages/shared/, etc. — without 404s.
 *
 * @param array  $user          Current user row from getCurrentUser()
 * @param string $current_page  Current page slug (e.g. 'admin-users', 'messages.php')
 * @param string $page_title    Title shown in the topbar <h1>
 * @param string $page_subtitle Subtitle shown under the title (pass '' for none)
 */
function renderAdminShell($user, $current_page, $page_title, $page_subtitle = '') {
    // Normalise the user object — admin-contact builds a $user without first_name/last_name,
    // only a 'name'. Everything downstream relies on $shell_user only.
    $shell_user = [
        'first_name' => $user['first_name'] ?? '',
        'last_name'  => $user['last_name']  ?? '',
        'role'       => $user['role']       ?? 'admin',
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
        $initials = 'AD';
    }

    $full_name = trim($shell_user['first_name'] . ' ' . $shell_user['last_name']);
    if ($full_name === '') {
        $full_name = 'Administrator';
    }

    $role_label = '⚙️ Administrator';

    $page_title_safe    = $page_title    !== '' ? $page_title    : 'Admin';
    $subtitle_default   = ''; // No fake subtitle; pages pass their own.
    $page_subtitle_safe = $page_subtitle !== '' ? $page_subtitle : $subtitle_default;

    // Canonical admin nav. Each row: [href, label, icon, exists].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/admin/ and /pages/shared/ both resolve the same.
    $nav = [
        'Overview' => [
            [SITE_URL . 'pages/admin/admin-dashboard.php', 'Dashboard', '📊', true],
        ],
        'User Management' => [
            [SITE_URL . 'pages/admin/admin-users.php',       'User Management', '👥', true],
            [SITE_URL . 'pages/admin/admin-departments.php', 'Departments',     '🏛️', true],
            [SITE_URL . 'pages/admin/admin-programs.php',    'Programs',        '🎓', true],
        ],
        'Research Management' => [
            [SITE_URL . 'pages/admin/admin-research.php',    'Research Projects',  '📁', true],
            [SITE_URL . 'pages/admin/admin-archive.php',     'Archive',            '🗂️', true],
            [SITE_URL . 'pages/staff/staff-defense.php',     'Defense Schedule',   '🛡️', true],
        ],
        'Communication' => [
            [SITE_URL . 'pages/shared/messages.php',         'Messages',      '💬', true],
            [SITE_URL . 'pages/shared/notifications.php',    'Notifications', '🔔', true],
            [SITE_URL . 'pages/admin/admin-contact.php',     'Contact Inbox', '📨', true],
        ],
        'Analytics' => [
            [SITE_URL . 'pages/admin/admin-reports.php',     'Reports & Analytics', '📈', true],
            [SITE_URL . 'pages/admin/admin-logs.php',        'Activity Logs',       '📋', true],
        ],
        'System' => [
            [SITE_URL . 'pages/admin/admin-backup.php',      'Backup',   '💾', true],
            [SITE_URL . 'pages/admin/admin-settings.php',    'Settings', '🔧', false],
        ],
        'Account' => [
            [SITE_URL . 'pages/shared/profile.php',          'Profile', '👤', true],
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
    $url_shell   = SITE_URL . 'css/admin-shell.css';
    $url_logout  = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Admin — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-dashboard">
  <!-- SIDEBAR -->
  <aside class="admin-sidebar">
    <div class="admin-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/admin/admin-dashboard.php" class="admin-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="admin-sidebar-brand-text">
        <div class="admin-sidebar-brand">Research Management</div>
        <small class="admin-sidebar-role">Admin</small>
      </div>
    </div>

    <nav class="admin-sidebar-nav">
      <?php foreach ($nav as $group => $items): ?>
        <div class="admin-nav-group-title"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'admin-nav-item' . ($active ? ' active' : '');
            $attrs    = $active ? ' aria-current="page"' : '';
            if (!$exists) {
                $attrs .= ' data-todo="build"';
                $href   = '#';
            }
          ?>
          <a class="<?php echo $classes; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attrs; ?>>
            <span class="admin-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
            <span class="admin-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="admin-nav-group-title">Session</div>
      <a class="admin-nav-item admin-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="admin-nav-icon" aria-hidden="true">🚪</span>
        <span class="admin-nav-label">Logout</span>
      </a>
    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-user-card">
        <div class="admin-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="admin-user-info">
          <div class="admin-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="admin-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-left">
        <h1 class="admin-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($page_subtitle_safe !== ''): ?>
          <p class="admin-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <div class="admin-topbar-right">
        <div class="admin-topbar-user">
          <div class="admin-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="admin-topbar-user-text">
            <div class="admin-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Admin', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="admin-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
      </div>
    </header>

    <div class="admin-page-content">
    <?php
}

/**
 * Closes the admin shell — emits the matching closing wrappers and </body></html>.
 * Pages that call renderAdminShell() MUST call this once at the end of their body.
 */
function renderAdminShellClose() {
    ?>
    </div><!-- /.admin-page-content -->
  </main>
</div><!-- /.admin-dashboard -->
</body>
</html>
    <?php
}
