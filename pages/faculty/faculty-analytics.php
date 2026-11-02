<?php
/**
 * Faculty Research Analytics
 * Uses the existing faculty-scoped summaries without loading dashboard content.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('faculty');

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

require_once __DIR__ . '/../../includes/services/FacultyDashboardService.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

$dashboardService = new FacultyDashboardService($conn);
$analytics = $dashboardService->getAnalyticsData((int) $user['user_id']);

renderFacultyShell($user, 'faculty-analytics', 'Research Analytics', 'Your advising projects, published outputs, and engagement summaries.');
?>
<div class="faculty-dashboard-hero-head">
    <p class="faculty-dashboard-subtitle">Track project status, publications over the last five years, and engagement verification.</p>
    <a class="faculty-btn faculty-btn--ghost" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-dashboard.php', ENT_QUOTES, 'UTF-8') ?>">Back to Dashboard</a>
</div>
<?php include __DIR__ . '/../../includes/faculty-analytics.php'; ?>
<script src="<?= htmlspecialchars(SITE_URL . 'js/faculty-analytics.js?v=' . filemtime(__DIR__ . '/../../js/faculty-analytics.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php renderFacultyShellClose(); ?>
