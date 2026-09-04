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
 * @param string $current_page  Current page slug (e.g. 'faculty-dashboard', 'faculty-review', 'messages.php')
 * @param string $page_title    Title shown in the topbar <h1>
 * @param string $page_subtitle Subtitle shown under the title (pass '' for none)
 */
function renderFacultyShell($user, $current_page, $page_title, $page_subtitle = '') {
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
    $pending_reviews = 0;
    $unread_messages = 0;
    $unread_notifications = 0;
    $faculty_sub_roles = [];

    // Appointment badges are informative only. A missing table or database
    // error must not prevent the shared shell from rendering.
    if ($conn instanceof mysqli && $user_id > 0) {
        try {
            $adviser_stmt = $conn->prepare(
                'SELECT EXISTS(SELECT 1 FROM project_advisers WHERE adviser_id = ? LIMIT 1) AS held'
            );
            if ($adviser_stmt) {
                $adviser_stmt->bind_param('i', $user_id);
                $adviser_stmt->execute();
                $is_adviser = (bool) ($adviser_stmt->get_result()->fetch_assoc()['held'] ?? false);
                $adviser_stmt->close();
            } else {
                $is_adviser = false;
            }

            $crec_stmt = $conn->prepare(
                "SELECT EXISTS(SELECT 1 FROM project_reviews WHERE reviewer_id = ? AND review_level = 'crec' LIMIT 1) AS held"
            );
            if ($crec_stmt) {
                $crec_stmt->bind_param('i', $user_id);
                $crec_stmt->execute();
                $is_crec_reviewer = (bool) ($crec_stmt->get_result()->fetch_assoc()['held'] ?? false);
                $crec_stmt->close();
            } else {
                $is_crec_reviewer = false;
            }

            $erec_stmt = $conn->prepare(
                "SELECT EXISTS(SELECT 1 FROM project_reviews WHERE reviewer_id = ? AND review_level = 'erec' LIMIT 1) AS held"
            );
            if ($erec_stmt) {
                $erec_stmt->bind_param('i', $user_id);
                $erec_stmt->execute();
                $is_erec_reviewer = (bool) ($erec_stmt->get_result()->fetch_assoc()['held'] ?? false);
                $erec_stmt->close();
            } else {
                $is_erec_reviewer = false;
            }

            if ($is_adviser) {
                $faculty_sub_roles[] = ['label' => 'Adviser', 'class' => 'adviser'];
            }
            if ($is_crec_reviewer) {
                $faculty_sub_roles[] = ['label' => 'CREC Reviewer', 'class' => 'crec'];
            }
            if ($is_erec_reviewer) {
                $faculty_sub_roles[] = ['label' => 'EREC Reviewer', 'class' => 'erec'];
            }
            if (!empty($user['is_reviewer']) && !$is_crec_reviewer && !$is_erec_reviewer) {
                $faculty_sub_roles[] = ['label' => 'Reviewer', 'class' => 'eligible'];
            }
        } catch (Throwable $exception) {
            $faculty_sub_roles = [];
        }
    }

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

        // Pending CREC/EREC review assignments (project_reviews, migration 006).
        $tbl_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
        if ($tbl_stmt) {
            $tbl_stmt->execute();
            $tbl_stmt->bind_result($tbl);
            $has_project_reviews = false;
            while ($tbl_stmt->fetch()) { $has_project_reviews = ($tbl === 'project_reviews'); }
            $tbl_stmt->close();
            if ($has_project_reviews) {
                $rev_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM project_reviews WHERE reviewer_id = ? AND reviewed_at IS NULL");
                if ($rev_stmt) {
                    $rev_stmt->bind_param('i', $user_id);
                    $rev_stmt->execute();
                    $pending_reviews = (int) ($rev_stmt->get_result()->fetch_assoc()['c'] ?? 0);
                    $rev_stmt->close();
                }
            }
        }
    }

    // Canonical faculty nav. Each row: [href, label, icon, exists, badge_count].
    // exists=false items render as <a href="#" data-todo="build"> so we mark
    // unfinished entries without a 404 or broken-link console error.
    //
    // All hrefs are absolute (SITE_URL-based) so the sidebar works from any
    // directory — e.g. /pages/faculty/ and /pages/shared/ both resolve the same.
    $nav = [
        'Overview' => [
            [SITE_URL . 'pages/faculty/faculty-dashboard.php', 'Dashboard', '📊', true, 0],
        ],
        'Advisement' => [
            [SITE_URL . 'pages/faculty/faculty-submissions.php', 'My Submissions',    '📥', true, 0],
            [SITE_URL . 'pages/faculty/faculty-review.php',      'Review Chapters',   '🔍', true, 0],
            [SITE_URL . 'pages/faculty/faculty-students.php',    'My Students',       '👨‍🎓', true, 0],
        ],
        'Evaluation' => [
            [SITE_URL . 'pages/faculty/faculty-my-reviews.php',  'My CREC/EREC Reviews', '📋', true, $pending_reviews],
        ],
        'Resources' => [
            [SITE_URL . 'pages/shared/research-archive.php',     'Research Archive',  '🗂️', true, 0],
            [SITE_URL . 'pages/faculty/faculty-reports.php',     'Reports',           '📊', true, 0],
        ],
        'Communication' => [
            [SITE_URL . 'pages/shared/messages.php',         'Messages',      '💬', true, $unread_messages],
            [SITE_URL . 'pages/shared/notifications.php',    'Notifications', '🔔', true, $unread_notifications],
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
    $url_shell   = SITE_URL . 'css/faculty-shell.css';
    $url_logout  = SITE_URL . 'public/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?> — Faculty — RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_style, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($url_shell, ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    .faculty-role-line { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
    .faculty-role-pill { display: inline-block; padding: 2px 7px; border-radius: 9999px; font-size: 11px; font-weight: 600; line-height: 1.35; white-space: nowrap; }
    .faculty-role-pill--adviser { color: #1d4ed8; background: #dbeafe; }
    .faculty-role-pill--crec { color: #92400e; background: #fef3c7; }
    .faculty-role-pill--erec { color: #6b21a8; background: #f3e8ff; }
    .faculty-role-pill--eligible { color: #475569; background: #e2e8f0; }
  </style>
</head>
<body>
<div class="faculty-dashboard">
  <!-- SIDEBAR -->
  <aside class="faculty-sidebar">
    <div class="faculty-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/faculty/faculty-dashboard.php" class="faculty-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="faculty-sidebar-brand-text">
        <div class="faculty-sidebar-brand">Research Management</div>
        <small class="faculty-sidebar-role">Faculty</small>
      </div>
    </div>

    <nav class="faculty-sidebar-nav">
      <?php foreach ($nav as $group => $items): ?>
        <div class="faculty-nav-group-title"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists, $badge_count] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'faculty-nav-item' . ($active ? ' active' : '');
            $attrs    = $active ? ' aria-current="page"' : '';
            if (!$exists) {
                $attrs .= ' data-todo="build"';
                $href   = '#';
            }
          ?>
          <a class="<?php echo $classes; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attrs; ?>>
            <span class="faculty-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
            <span class="faculty-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($badge_count > 0): ?>
              <span class="faculty-nav-badge"><?php echo $badge_count; ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="faculty-nav-group-title">Session</div>
      <a class="faculty-nav-item faculty-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="faculty-nav-icon" aria-hidden="true">🚪</span>
        <span class="faculty-nav-label">Logout</span>
      </a>
    </nav>

    <div class="faculty-sidebar-footer">
      <div class="faculty-user-card">
        <div class="faculty-user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="faculty-user-info">
          <div class="faculty-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="faculty-user-role faculty-role-line">
            <span><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php foreach ($faculty_sub_roles as $sub_role): ?>
              <span class="faculty-role-pill faculty-role-pill--<?php echo htmlspecialchars($sub_role['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sub_role['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="faculty-main">
    <header class="faculty-topbar">
      <div class="faculty-topbar-left">
        <h1 class="faculty-topbar-title"><?php echo htmlspecialchars($page_title_safe, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($page_subtitle_safe !== ''): ?>
          <p class="faculty-topbar-subtitle"><?php echo htmlspecialchars($page_subtitle_safe, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </div>
      <div class="faculty-topbar-right">
        <div class="faculty-topbar-user">
          <div class="faculty-topbar-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="faculty-topbar-user-text">
            <div class="faculty-topbar-user-name"><?php echo htmlspecialchars($shell_user['first_name'] !== '' ? $shell_user['first_name'] : 'Faculty', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="faculty-topbar-user-role faculty-role-line">
              <span><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span>
              <?php foreach ($faculty_sub_roles as $sub_role): ?>
                <span class="faculty-role-pill faculty-role-pill--<?php echo htmlspecialchars($sub_role['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sub_role['label'], ENT_QUOTES, 'UTF-8'); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div class="faculty-page-content">
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
</body>
</html>
    <?php
}
