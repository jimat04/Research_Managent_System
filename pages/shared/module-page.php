<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/module-pages.php';

$modules = [
    'my-research.php' => ['My Research', 'student', '📁', 'Review your research projects and current submission status.'],
    'submit-research.php' => ['Submit Research', 'student', '📤', 'Start a new research submission and upload your proposal.'],
    'my-documents.php' => ['My Documents', 'student', '📄', 'Access proposals, chapters, defense files, and manuscripts.'],
    'progress-tracking.php' => ['Progress Tracking', 'student', '📈', 'Follow milestones and review progress across your projects.'],
    'submit-chapter.php' => ['Submit Chapter', 'student', '📝', 'Upload a research chapter for review.'],
    'messages.php' => ['Messages', null, '💬', 'Communicate with advisers, reviewers, and research collaborators.'],
    'notifications.php' => ['Notifications', null, '🔔', 'View updates about reviews, approvals, revisions, and deadlines.'],
    'calendar.php' => ['Calendar', 'student', '📅', 'Keep track of research deadlines and scheduled activities.'],
    'profile.php' => ['Profile', null, '👤', 'View and update your account information.'],
    'settings.php' => ['Settings', 'student', '⚙️', 'Manage your account and notification preferences.'],
    'faculty-submissions.php' => ['Submissions', 'faculty', '📥', 'Browse research projects assigned to your faculty account.'],
    'faculty-review.php' => ['Review Queue', 'faculty', '🔍', 'Review chapters and provide feedback to student researchers.'],
    'faculty-review-detail.php' => ['Review Research', 'faculty', '🔍', 'Inspect a research submission and record review feedback.'],
    'faculty-students.php' => ['My Students', 'faculty', '👨‍🎓', 'Monitor the students and projects assigned to you.'],
    'faculty-reports.php' => ['Reports', 'faculty', '📊', 'View review activity and research progress reports.'],
    'contact-messages.php' => ['Contact Messages', ['admin', 'research_staff'], '📨', 'Manage public contact form submissions and inquiries.'],
    'admin-users.php' => ['User Management', 'admin', '👥', 'Manage student, faculty, and administrator accounts.'],
    'admin-research.php' => ['Research Management', 'admin', '📁', 'Manage all research records and their workflow status.'],
    'admin-archive.php' => ['Archive Management', 'admin', '🗂️', 'Manage completed research in the institutional archive.'],
    'admin-reports.php' => ['Reports & Analytics', 'admin', '📈', 'Review system-wide research and user statistics.'],
    'admin-logs.php' => ['System Logs', 'admin', '⚙️', 'Inspect recent activity recorded by the system.'],
    'admin-backup.php' => ['Backup', 'admin', '💾', 'Manage database and document backup operations.'],
    'research-archive.php' => ['Research Archive', null, '🗂️', 'Browse approved and completed research projects.'],
    'research-detail.php' => ['Research Details', null, '📄', 'View the details and current status of a research project.'],
    'view-research.php' => ['View Research', null, '📄', 'View research project information.']
];

$page_key = basename($_SERVER['SCRIPT_NAME']);
$module = $modules[$page_key] ?? ['Module', null, '🔧', 'This module is available from the Research Management System.'];
requireLogin();
if ($module[1] !== null) {
    requireRole($module[1]);
}
$user = getCurrentUser();
rms_handle_module_action($page_key, $user);

// Map role to dashboard path
$dashboardMap = [
    'student' => '../student/student-dashboard.php',
    'faculty' => '../faculty/faculty-dashboard.php',
    'research_staff' => '../staff/staff-dashboard.php',
    'admin' => '../admin/admin-dashboard.php'
];
$dashboard = $dashboardMap[$user['role']] ?? '../student/student-dashboard.php';

$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($module[0]); ?> - RMS</title>
  <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
<div class="dashboard">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">Research<br>Management</div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-title">NAVIGATION</div>
      <div class="nav-item" onclick="location.href='<?php echo htmlspecialchars($dashboard); ?>'"><span class="icon">📊</span><span>Dashboard</span></div>
      <div class="nav-item active"><span class="icon"><?php echo $module[2]; ?></span><span><?php echo htmlspecialchars($module[0]); ?></span></div>
      <div class="nav-item" onclick="history.back()"><span class="icon">←</span><span>Back</span></div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color:#ef4444"><span class="icon">🚪</span><span>Logout</span></div>
    </nav>
    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo $initials; ?></div>
        <div class="user-info"><div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div><div class="user-role"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></div></div>
      </div>
    </div>
  </aside>
  <main class="main-content">
    <header class="topbar"><div class="topbar-left"><h2><?php echo htmlspecialchars($module[0]); ?></h2><p><?php echo htmlspecialchars($module[3]); ?></p></div><div class="topbar-right"><div class="user-profile-btn"><div class="profile-avatar"><?php echo $initials; ?></div><div class="profile-text"><div class="profile-name"><?php echo htmlspecialchars($user['first_name']); ?></div><div class="profile-role"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></div></div></div></div></header>
    <div class="page-content">
      <?php rms_render_module($page_key, $user, $module); ?>
    </div>
  </main>
</div>
</body>
</html>
