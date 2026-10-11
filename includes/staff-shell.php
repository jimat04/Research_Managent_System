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
    $stat_defenses  = 0;
    $stat_revisions = 0;
    if (isset($conn)) {
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM contact_messages WHERE status = 'pending'");
        if ($count_result) {
            $stat_contact = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM research_projects WHERE status = 'submitted'");
        if ($count_result) {
            $stat_pending = (int) ($count_result->fetch_assoc()['count'] ?? 0);
        }
        $committee_count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('under_crec_review', 'under_erec_review')");
        if ($committee_count_stmt) {
            $committee_count_stmt->execute();
            $stat_crec = (int) ($committee_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);
            $committee_count_stmt->close();
        }
        // Upcoming defenses (status scheduled/rescheduled with date >= now).
        // defense_schedule is in the main schema, but defend against older
        // installs that may not have it yet.
        $has_defense_tbl = false;
        $def_tbl_check = $conn->query("SHOW TABLES LIKE 'defense_schedule'");
        if ($def_tbl_check) {
            $has_defense_tbl = ($def_tbl_check->num_rows > 0);
            $def_tbl_check->free();
        }
        if ($has_defense_tbl) {
            $def_res = $conn->query("SELECT COUNT(*) AS c FROM defense_schedule WHERE status IN ('scheduled','rescheduled') AND schedule_date >= NOW()");
            if ($def_res) {
                $stat_defenses = (int) ($def_res->fetch_assoc()['c'] ?? 0);
                $def_res->close();
            }
        }

        $project_deleted_clause = '';
        $project_deleted_check = $conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
        if ($project_deleted_check) {
            $project_deleted_clause = $project_deleted_check->num_rows > 0 ? ' AND deleted_at IS NULL' : '';
            $project_deleted_check->close();
        }
        $chapter_deleted_clause = '';
        $chapter_deleted_check = $conn->query("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
        if ($chapter_deleted_check) {
            $chapter_deleted_clause = $chapter_deleted_check->num_rows > 0 ? ' AND deleted_at IS NULL' : '';
            $chapter_deleted_check->close();
        }
        $revision_res = $conn->query("
            SELECT
                (SELECT COUNT(*) FROM research_projects WHERE status IN ('for_revision','revision_required')" . $project_deleted_clause . ")
                + (SELECT COUNT(*) FROM chapters WHERE status = 'revision_required'" . $chapter_deleted_clause . ") AS c
        ");
        if ($revision_res) {
            $stat_revisions = (int) ($revision_res->fetch_assoc()['c'] ?? 0);
            $revision_res->close();
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
    ];
    $moduleIcons = [
        'seminars' => '📋', 'trainings' => '🎓', 'workshops' => '🛠️',
        'research_proposals' => '📝', 'research_review' => '🔎', 'research_approval' => '✅',
        'ongoing_research' => '🔄', 'completed_research' => '🏁', 'research_outputs' => '📊',
        'journal_publications' => '📚', 'conference_presentations' => '🎤', 'research_repository' => '🗄️',
        'copyrights' => '©️', 'patents_utility_models' => '💡', 'innovation_technology_transfer' => '🚀',
    ];
    $permissionMatrix = ($shell_user['role'] ?? '') === 'admin'
        ? []
        : getStaffPermissionMatrix((int) ($user['user_id'] ?? 0));
    foreach (rmsPermissionModules() as $moduleKey => $module) {
        $moduleItems = [];
        foreach ($module['pages'] as $pageKey => $label) {
            if ($pageKey === 'ongoing_research') {
                continue;
            }
            $level = ($shell_user['role'] ?? '') === 'admin'
                ? 'manage'
                : ($permissionMatrix[$moduleKey]['pages'][$pageKey] ?? 'none');
            if (!in_array($level, ['view', 'manage'], true)) {
                continue;
            }
            $moduleItems[] = [
                SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($pageKey),
                $label,
                $moduleIcons[$pageKey] ?? '📄',
                true,
                0,
            ];
        }
        if ($moduleItems) {
            $nav[$module['label']] = $moduleItems;
        }
    }
    $nav['Research Lifecycle'] = [
        [SITE_URL . 'pages/staff/staff-defense.php', 'Defense Schedule', '🗓️', true, $stat_defenses],
        [SITE_URL . 'pages/staff/staff-revisions.php', 'Revision Returns', '↩️', true, $stat_revisions],
    ];
    $nav['Research Services'] = [
        [SITE_URL . 'pages/staff/document-verification.php', 'Document Verification', '📄', true, 0],
        [SITE_URL . 'pages/staff/contact-messages.php', 'Contact Messages', '✉️', true, $stat_contact],
    ];
    $nav['Communication'] = [
        [SITE_URL . 'pages/shared/messages.php', 'Messages', '💬', true, 0],
        [SITE_URL . 'pages/shared/notifications.php', 'Notifications', '🔔', true, 0],
    ];
    $nav['Account'] = [
        [SITE_URL . 'pages/shared/profile.php', 'Profile', '👤', true, 0],
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
    $url_style   = SITE_URL . 'css/style.css';
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
<div class="staff-dashboard">
  <button type="button" class="staff-sidebar-scrim" aria-label="Close staff navigation" tabindex="-1"></button>
  <!-- SIDEBAR -->
  <aside class="staff-sidebar" id="staff-sidebar" aria-label="Research staff navigation">
    <div class="staff-sidebar-header">
      <a href="<?php echo SITE_URL; ?>pages/staff/staff-dashboard.php" class="staff-sidebar-logo" aria-label="RMS Home">
        <img src="<?php echo SITE_URL; ?>photos/rms-logo.png" alt="RMS Logo">
      </a>
      <div class="staff-sidebar-brand-text">
        <div class="staff-sidebar-brand">EARIST Cavite</div>
        <small class="staff-sidebar-role">Research Management System</small>
      </div>
    </div>

    <nav class="staff-sidebar-nav" aria-label="Primary">
      <?php foreach ($nav as $group => $items): ?>
        <?php if ($group === 'Overview'): ?>
          <?php foreach ($items as $item): ?>
            <?php [$href, $label, $icon, $exists] = $item; $active = $exists && $is_active($href); ?>
            <a class="staff-nav-item staff-nav-dashboard<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
              <span class="staff-nav-icon" aria-hidden="true"><?php echo $icon; ?></span>
              <span class="staff-nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
          <?php endforeach; ?>
          <?php continue; ?>
        <?php endif; ?>
        <?php
          $groupId = 'staff-nav-group-' . substr(sha1($group), 0, 8);
          $groupActive = false;
          foreach ($items as $candidateItem) {
              if ($is_active($candidateItem[0])) {
                  $groupActive = true;
                  break;
              }
          }
          $groupIcon = $items[0][2] ?? '📁';
        ?>
        <details class="staff-nav-group"<?php echo $groupActive ? ' open' : ''; ?>>
          <summary class="staff-nav-group-summary" title="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" aria-controls="<?php echo $groupId; ?>">
            <span class="staff-nav-icon" aria-hidden="true"><?php echo $groupIcon; ?></span>
            <span class="staff-nav-label"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="staff-nav-chevron" aria-hidden="true"></span>
          </summary>
          <div class="staff-nav-subitems" id="<?php echo $groupId; ?>">
            <?php foreach ($items as $item): ?>
          <?php
            [$href, $label, $icon, $exists, $badge_count] = $item;
            $active   = $exists ? $is_active($href) : false;
            $classes  = 'staff-nav-item staff-nav-subitem' . ($active ? ' active' : '');
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
          </div>
        </details>
      <?php endforeach; ?>

      <div class="staff-nav-session">
        <div class="staff-nav-group-title">Session</div>
        <a class="staff-nav-item staff-nav-logout" href="<?php echo htmlspecialchars($url_logout, ENT_QUOTES, 'UTF-8'); ?>">
          <span class="staff-nav-icon" aria-hidden="true">🚪</span>
          <span class="staff-nav-label">Logout</span>
        </a>
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
  <main class="staff-main">
    <header class="staff-topbar">
      <div class="staff-topbar-left">
        <button type="button" class="staff-mobile-menu-btn" aria-label="Open staff navigation" aria-controls="staff-sidebar" aria-expanded="false">☰</button>
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
<?php renderLogoutTransition(); ?>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/public-motion.js', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
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
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });
})();
</script>
</body>
</html>
    <?php
}
