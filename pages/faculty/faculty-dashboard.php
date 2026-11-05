<?php
/**
 * Faculty Dashboard
 *
 * Main entry point for faculty dashboard. Delegates data fetching to
 * FacultyDashboardService and renders via component templates.
 */

// Load configuration first (defines $conn)
require_once __DIR__ . '/../../includes/config.php';

// Require authentication and faculty role
require_once __DIR__ . '/../../includes/auth.php';
requireRole('faculty');

// Preserve existing analytics bookmarks while keeping the dashboard separate.
if (($_GET['view'] ?? '') === 'analytics') {
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-analytics.php');
    exit;
}

// Load services
require_once __DIR__ . '/../../includes/services/FacultyDashboardService.php';

// Load faculty shell (provides renderFacultyShell() and renderFacultyShellClose())
require_once __DIR__ . '/../../includes/faculty-shell.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

$requestedAdviseePage = filter_var($_GET['advisee_page'] ?? 1, FILTER_VALIDATE_INT);
$adviseePage = ($requestedAdviseePage !== false && $requestedAdviseePage > 0)
    ? $requestedAdviseePage
    : 1;

// Fetch dashboard data with a validated advisee project page.
$dashboardService = new FacultyDashboardService($conn);
$data = $dashboardService->getOverviewData($userId, $adviseePage);

// Navigation URLs
$urls = [
    'analytics' => SITE_URL . 'pages/faculty/faculty-analytics.php',
    'advising' => SITE_URL . 'pages/faculty/faculty-advising.php',
    'engagements' => SITE_URL . 'pages/faculty/faculty-engagement.php?view=history',
    'activities' => SITE_URL . 'pages/shared/activities.php?view=upcoming',
    'researchRecords' => SITE_URL . 'pages/faculty/faculty-research-records.php',
    'addEngagement' => SITE_URL . 'pages/faculty/faculty-engagement.php',
    'profile' => SITE_URL . 'pages/faculty/faculty-profile.php',
    'publications' => SITE_URL . 'pages/faculty/faculty-publications.php',
    'addPublication' => SITE_URL . 'pages/faculty/faculty-publication-form.php',
];

// Get current user for shell
$user = getCurrentUser();

// Render shell header
renderFacultyShell($user, 'faculty-dashboard', 'Dashboard', 'Your research, advising, and engagement at a glance.');

// Render dashboard overview
include __DIR__ . '/../../includes/faculty-dashboard/overview.php';

// Render shell footer
renderFacultyShellClose();
