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
    $stat_contact   = 0;
    $stat_pending   = 0;
    $stat_crec      = 0;
    $stat_milestones = 0;
    if (isset($conn)) {
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM contact_messages WHERE status = 'pending'");
        if ($count_result) {
            $stat_contact = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM research_projects WHERE status = 'submitted'");
        if ($count_result) {
            $stat_pending = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM research_projects WHERE status = 'under_crec_review'");
        if ($count_result) {
            $stat_crec = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
        // Pending milestone verifications: research_documents + research_reports
        // with status 'submitted'. Both tables are added by a migration and may
        // not exist on every install — check SHOW TABLES first so the shell
        // renders cleanly on older schemas.
        $milestone_present = ['documents' => false, 'reports' => false];
        $tbl_check = $conn->query("SHOW TABLES");
        if ($tbl_check) {
            while ($trow = $tbl_check->fetch_array()) {
                if ($trow[0] === 'research_documents') $milestone_present['documents'] = true;
                if ($trow[0] === 'research_reports')   $milestone_present['reports']   = true;
            }
            $tbl_check->close();
        }
        $milestone_parts = [];
        if ($milestone_present['documents']) {
            $milestone_parts[] = "SELECT COUNT(*) AS c FROM research_documents WHERE status = 'submitted'";
        }
        if ($milestone_present['reports']) {
            $milestone_parts[] = "SELECT COUNT(*) AS c FROM research_reports WHERE status = 'submitted'";
        }
        if (!empty($milestone_parts)) {
            $milestone_sql = implode(' UNION ALL ', $milestone_parts);
            $milestone_res = $conn->query($milestone_sql);
            if ($milestone_res) {
                while ($mrow = $milestone_res->fetch_assoc()) {
                    $stat_milestones += (int) ($mrow['c'] ?? 0);
                }
                $milestone_res->close();
            }
        }
    }

    // Canonical staff nav. Each row: [href, label, icon, exists, badge_count].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/staff/ and /pages/shared/ both resolve the same.
    $nav = [
        'Overview' => [
            [SITE_URL . 'pages/staff/staff-dashboard.php', 'Dashboard', '📊', true, 0],
        ],
        'Processing' => [
            [SITE_URL . 'pages/staff/staff-submissions.php', 'Submissions Inbox', '📥', true,  $stat_pending],
            [SITE_URL . 'pages/staff/staff-crec.php',       'For CREC Review',    '🏛️', true,  $stat_crec],
            [SITE_URL . 'pages/staff/staff-milestones.php', 'Milestones',         '📑', true,  $stat_milestones],
            [SITE_URL . 'pages/staff/staff-revisions.php',  'Revision Returns',   '🔄', false, 0],
        ],
        'Repository' => [
            [SITE_URL . 'pages/shared/research-archive.php',     'Research Archive',       '🗂️', true, 0],
            [SITE_URL . 'pages/staff/document-verification.php', 'Document Verification',  '📄', false, 0],
        ],
        'Communication' => [
            [SITE_URL . 'pages/shared/messages.php',         'Messages',         '💬', true, 0],
            [SITE_URL . 'pages/staff/contact-messages.php',  'Contact Messages', '📨', true, $stat_contact],
            [SITE_URL . 'pages/shared/notifications.php',    'Notifications',    '🔔', true, 0],
        ],
        'Account' => [
            [SITE_URL . 'pages/shared/profile.php',          'Profile', '👤', true, 0],
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
    $url_shell   = SITE_URL . 'css/staff-shell.css';
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
<div class="staff-dashboard">
  <!-- SIDEBAR -->
  <aside class="staff-sidebar">
    <div class="staff-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/staff/staff-dashboard.php" class="staff-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="staff-sidebar-brand-text">
        <div class="staff-sidebar-brand">Research Management</div>
        <small class="staff-sidebar-role">Staff</small>
      </div>
    </div>

    <nav class="staff-sidebar-nav">
      <?php foreach ($nav as $group => $items): ?>
        <div class="staff-nav-group-title"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists, $badge_count] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'staff-nav-item' . ($active ? ' active' : '');
            $attrs    = $active ? ' aria-current="page"' : '';
            if (!$exists) {
                $attrs .= ' data-todo="build"';
                $href   = '#';
            }
          ?>
          <a class="<?php echo $classes; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attrs; ?>>
            <span class="staff-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
            <span class="staff-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($badge_count > 0): ?>
              <span class="staff-nav-badge"><?php echo $badge_count; ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="staff-nav-group-title">Session</div>
      <a class="staff-nav-item staff-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="staff-nav-icon" aria-hidden="true">🚪</span>
        <span class="staff-nav-label">Logout</span>
      </a>
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
  <main class="staff-main">
    <header class="staff-topbar">
      <div class="staff-topbar-left">
        <h1 class="staff-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($page_subtitle_safe !== ''): ?>
          <p class="staff-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <div class="staff-topbar-right">
        <div class="staff-topbar-user">
          <div class="staff-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="staff-topbar-user-text">
            <div class="staff-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Staff', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="staff-topbar-user-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
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
</body>
</html>
    <?php
}
