<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/module-pages.php';

$modules = [
    'my-research.php' => ['My Research', 'student', 'folder-kanban', 'Review your research projects and current submission status.'],
    'submit-research.php' => ['Submit Research', 'student', 'file-up', 'Start a new research submission and upload your proposal.'],
    'my-documents.php' => ['My Documents', 'student', 'files', 'Access proposals, chapters, defense files, and manuscripts.'],
    'progress-tracking.php' => ['Progress Tracking', 'student', 'chart-no-axes-combined', 'Follow milestones and review progress across your projects.'],
    'submit-chapter.php' => ['Submit Chapter', 'student', 'file-pen-line', 'Upload a research chapter for review.'],
    'messages.php' => ['Messages', null, 'messages-square', 'Communicate securely with students, faculty advisers, administrators, and research staff.'],
    'notifications.php' => ['Notifications', null, 'bell', 'View updates about reviews, approvals, revisions, and deadlines.'],
    'calendar.php' => ['Calendar', 'student', 'calendar-days', 'Keep track of research deadlines and scheduled activities.'],
    'profile.php' => ['Profile', null, 'circle-user-round', 'View and update your account information.'],
    'settings.php' => ['Settings', 'student', 'settings', 'Manage your account and notification preferences.'],
    'faculty-submissions.php' => ['Submissions', 'faculty', 'inbox', 'Browse research projects assigned to your faculty account.'],
    'faculty-review.php' => ['Review Queue', 'faculty', 'search-check', 'Review chapters and provide feedback to student researchers.'],
    'faculty-review-detail.php' => ['Review Research', 'faculty', 'clipboard-check', 'Inspect a research submission and record review feedback.'],
    'faculty-students.php' => ['My Students', 'faculty', 'graduation-cap', 'Monitor the students and projects assigned to you.'],
    'faculty-reports.php' => ['Reports', 'faculty', 'chart-column', 'View review activity and research progress reports.'],
    'contact-messages.php' => ['Contact Messages', ['admin', 'research_staff'], 'mail', 'Manage public contact form submissions and inquiries.'],
    'admin-users.php' => ['User Management', 'admin', 'users', 'Manage student, faculty, and administrator accounts.'],
    'admin-research.php' => ['Research Management', 'admin', 'folder-kanban', 'Manage all research records and their workflow status.'],
    'admin-archive.php' => ['Archive Management', 'admin', 'archive', 'Manage completed research in the institutional archive.'],
    'admin-reports.php' => ['Reports & Analytics', 'admin', 'chart-no-axes-combined', 'Review system-wide research and user statistics.'],
    'admin-logs.php' => ['System Logs', 'admin', 'scroll-text', 'Inspect recent activity recorded by the system.'],
    'admin-backup.php' => ['Backup', 'admin', 'database-backup', 'Manage database and document backup operations.'],
    'research-archive.php' => ['Research Archive', null, 'archive', 'Browse approved and completed research projects.'],
    'research-detail.php' => ['Research Details', null, 'file-text', 'View the details and current status of a research project.'],
    'view-research.php' => ['View Research', null, 'file-text', 'View research project information.']
];

$page_key = basename($_SERVER['SCRIPT_NAME']);
$module = $modules[$page_key] ?? ['Module', null, 'layout-dashboard', 'This module is available from the Research Management System.'];
requireLogin();
if ($module[1] !== null) {
    requireRole($module[1]);
}
$user = getCurrentUser();
rms_handle_module_action($page_key, $user);

$roleLabels = [
    'student' => 'Student Portal',
    'faculty' => 'Faculty Portal',
    'research_staff' => 'Research Staff',
    'admin' => 'Administration'
];

$navigationByRole = [
    'student' => [
        'Research' => [
            ['student-dashboard.php', 'Dashboard', 'layout-dashboard'],
            ['my-research.php', 'My Research', 'folder-kanban'],
            ['submit-research.php', 'Submit Research', 'file-up'],
            ['my-documents.php', 'My Documents', 'files'],
            ['progress-tracking.php', 'Progress Tracking', 'chart-no-axes-combined']
        ],
        'Communication' => [
            ['messages.php', 'Messages', 'messages-square'],
            ['notifications.php', 'Notifications', 'bell']
        ],
        'Resources' => [
            ['research-archive.php', 'Research Archive', 'archive'],
            ['calendar.php', 'Calendar', 'calendar-days']
        ],
        'Account' => [
            ['profile.php', 'Profile', 'circle-user-round']
        ]
    ],
    'faculty' => [
        'Advising' => [
            ['faculty-dashboard.php', 'Dashboard', 'layout-dashboard'],
            ['faculty-submissions.php', 'Submissions', 'inbox'],
            ['faculty-review.php', 'Review Queue', 'search-check'],
            ['faculty-students.php', 'My Students', 'graduation-cap'],
            ['faculty-reports.php', 'Reports', 'chart-column']
        ],
        'Communication' => [
            ['messages.php', 'Messages', 'messages-square'],
            ['notifications.php', 'Notifications', 'bell']
        ],
        'Resources' => [
            ['research-archive.php', 'Research Archive', 'archive']
        ],
        'Account' => [
            ['profile.php', 'Profile', 'circle-user-round']
        ]
    ],
    'research_staff' => [
        'Overview' => [
            ['staff-dashboard.php', 'Dashboard', 'layout-dashboard']
        ],
        'Communication' => [
            ['messages.php', 'Messages', 'messages-square'],
            ['contact-messages.php', 'Contact Messages', 'mail'],
            ['notifications.php', 'Notifications', 'bell']
        ],
        'Resources' => [
            ['research-archive.php', 'Research Archive', 'archive']
        ],
        'Account' => [
            ['profile.php', 'Profile', 'circle-user-round']
        ]
    ],
    'admin' => [
        'Overview' => [
            ['admin-dashboard.php', 'Dashboard', 'layout-dashboard']
        ],
        'Management' => [
            ['admin-users.php', 'User Management', 'users'],
            ['admin-research.php', 'Research Management', 'folder-kanban'],
            ['admin-archive.php', 'Archive', 'archive']
        ],
        'Analytics' => [
            ['admin-reports.php', 'Reports & Analytics', 'chart-no-axes-combined']
        ],
        'Communication' => [
            ['messages.php', 'Messages', 'messages-square'],
            ['admin-contact.php', 'Contact Messages', 'mail']
        ],
        'System' => [
            ['admin-logs.php', 'System Logs', 'scroll-text'],
            ['admin-backup.php', 'Backup', 'database-backup'],
            ['notifications.php', 'Notifications', 'bell']
        ],
        'Account' => [
            ['profile.php', 'Profile', 'circle-user-round']
        ]
    ]
];

$pathPrefixes = [
    'student' => '../student/',
    'faculty' => '../faculty/',
    'research_staff' => '../staff/',
    'admin' => '../admin/'
];
$role = $user['role'];
$navigation = $navigationByRole[$role] ?? $navigationByRole['student'];
$prefix = $pathPrefixes[$role] ?? '../student/';
$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

function rms_module_navigation_href($target, $prefix) {
    // Truly shared modules that live in pages/shared/ - always use ../shared/
    $trulySharedModules = [
        'messages.php',
        'notifications.php',
        'profile.php',
        'research-archive.php',
        'calendar.php'
    ];

    // Student modules that delegate to module-page.php but are accessed via pages/student/
    $studentDelegateModules = [
        'my-documents.php',
        'progress-tracking.php'
    ];

    if (in_array($target, $trulySharedModules, true)) {
        return '../shared/' . $target;
    }

    if (in_array($target, $studentDelegateModules, true)) {
        return $prefix . $target;
    }

    return $prefix . $target;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($module[0], ENT_QUOTES, 'UTF-8'); ?> — RMS</title>
  <link rel="stylesheet" href="../../css/style.css">
  <script src="https://unpkg.com/lucide@latest" defer></script>
  <style>
    :root {
      --rms-charcoal: #111827;
      --rms-slate: #1f2937;
      --rms-surface: #f8fafc;
      --rms-border: #e5e7eb;
      --rms-gold: #c8a44d;
      --rms-muted: #94a3b8;
    }

    .module-shell .sidebar { background: var(--rms-charcoal); border-right-color: rgba(255, 255, 255, .1); }
    .module-shell .sidebar-header { display: block; padding: 32px 24px 24px; border-bottom-color: rgba(255, 255, 255, .1); }
    .module-shell .sidebar-brand { font-size: 20px; line-height: 1.2; }
    .module-shell .sidebar-role { color: var(--rms-muted); font-size: 13px; font-weight: 500; margin-top: 4px; }
    .module-shell .sidebar-nav { padding: 16px 12px 24px; }
    .module-shell .nav-group-title { color: var(--rms-muted); margin-top: 16px; padding: 8px 12px; }
    .module-shell .nav-item { color: #cbd5e1; border-radius: 10px; margin: 2px 0; padding: 11px 12px; text-decoration: none; }
    .module-shell .nav-item:hover { background: rgba(255, 255, 255, .08); color: #fff; }
    .module-shell .nav-item.active { background: rgba(200, 164, 77, .18); color: #fff; }
    .module-shell .nav-item.active::before { background: var(--rms-gold); }
    .module-shell .nav-item .icon { display: inline-flex; align-items: center; justify-content: center; width: 18px; }
    .module-shell .nav-item .icon svg { height: 18px; width: 18px; stroke-width: 1.8; }
    .module-shell .nav-item.logout { color: #fecaca; margin-top: 12px; }
    .module-shell .nav-item.logout:hover { background: rgba(239, 68, 68, .15); color: #fff; }
    .module-shell .user-avatar { background: var(--rms-gold); color: var(--rms-charcoal); }
    .module-shell .topbar { min-height: 92px; height: auto; padding: 20px 32px; }
    .module-shell .topbar-left h2 { color: var(--rms-charcoal); }
    .module-shell .topbar-left p { color: #64748b; max-width: 760px; }
    .module-shell .page-content { background: var(--rms-surface); min-height: calc(100vh - 92px); }
    .module-shell .card { border: 1px solid var(--rms-border); border-radius: 16px; box-shadow: none; }

    @media (max-width: 768px) {
      .module-shell .topbar { padding: 20px 24px; }
    }
  </style>
</head>
<body>
<div class="dashboard module-shell">
  <aside class="sidebar" aria-label="Primary navigation">
    <div class="sidebar-header">
      <div class="sidebar-brand">EARIST RMS</div>
      <div class="sidebar-role"><?php echo htmlspecialchars($roleLabels[$role] ?? 'RMS User', ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navigation as $group => $items): ?>
        <div class="nav-group-title"><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($items as $item): ?>
          <?php
          $isActive = $item[0] === $page_key;
          $href = rms_module_navigation_href($item[0], $prefix);
          ?>
          <a class="nav-item<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
            <span class="icon" aria-hidden="true"><i data-lucide="<?php echo htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8'); ?>"></i></span>
            <span><?php echo htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <a class="nav-item logout" href="../../public/logout.php">
        <span class="icon" aria-hidden="true"><i data-lucide="log-out"></i></span>
        <span>Logout</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $role)), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
    </div>
  </aside>
  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h2><?php echo htmlspecialchars($module[0], ENT_QUOTES, 'UTF-8'); ?></h2>
        <p><?php echo htmlspecialchars($module[3], ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
      <div class="topbar-right">
        <div class="user-profile-btn">
          <div class="profile-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="profile-role"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $role)), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
      </div>
    </header>
    <div class="page-content">
      <?php rms_render_module($page_key, $user, $module); ?>
    </div>
  </main>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.lucide) {
      window.lucide.createIcons();
    }
  });
</script>
</body>
</html>
