<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$moduleKey = trim((string) ($_GET['module'] ?? ''));
$moduleKey = match ($moduleKey) {
    'add_incoming_activity' => 'add_upcoming_activity',
    'incoming_activities' => 'upcoming_activities',
    default => $moduleKey,
};
$moduleMap = [
    'add_activity' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activity-form.php?mode=add', 'title' => 'Add Activity'],
    'add_upcoming_activity' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activity-form.php?mode=add&status=upcoming', 'title' => 'Add Upcoming Activity'],
    'add_conducted_activity' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activity-form.php?mode=add&status=conducted', 'title' => 'Add Conducted Activity'],
    'view_activity' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?view=overview', 'title' => 'Research Activities'],
    'upcoming_activities' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?view=upcoming', 'title' => 'Upcoming'],
    'ongoing_activities' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?view=ongoing', 'title' => 'Ongoing'],
    'conducted_activities' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?view=conducted', 'title' => 'Conducted'],
    'research_proposals' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_proposals', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_proposals', 'title' => 'Research Proposals'],
    'research_review' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_review', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_review', 'title' => 'Chapter Review'],
    'research_approval' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_approval', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_approval', 'title' => 'Approved Proposals'],
    'research_engagement_records' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/admin/admin-faculty-engagement.php', 'staff_target' => SITE_URL . 'pages/admin/admin-faculty-engagement.php', 'title' => 'Research Engagement Records'],
    'ongoing_research' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/admin/admin-research-management.php?status=ongoing', 'staff_target' => SITE_URL . 'pages/shared/implementation-outputs.php?view=ongoing', 'title' => 'Ongoing Research'],
    'completed_research' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/shared/implementation-outputs.php?view=completed', 'staff_target' => SITE_URL . 'pages/shared/implementation-outputs.php?view=completed', 'title' => 'Completed Research'],
    'research_outputs' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/shared/implementation-outputs.php?view=outputs', 'staff_target' => SITE_URL . 'pages/shared/implementation-outputs.php?view=outputs', 'title' => 'Research Outputs'],
    'journal_publications' => ['permission' => 'publication_dissemination', 'permission_page' => 'publication_records', 'target' => SITE_URL . 'pages/admin/admin-publications.php?publication_type=journal', 'staff_target' => SITE_URL . 'pages/shared/publications.php?module_page=publication_records', 'title' => 'Journal Publications'],
    'conference_presentations' => ['permission' => 'publication_dissemination', 'permission_page' => 'publication_records', 'target' => SITE_URL . 'pages/admin/admin-publications.php?publication_type=conference', 'staff_target' => SITE_URL . 'pages/shared/publications.php?module_page=publication_records', 'title' => 'Conference Papers & Presentations'],
    'research_repository' => ['permission' => 'publication_dissemination', 'permission_page' => 'publication_records', 'target' => SITE_URL . 'pages/admin/admin-publications.php?status=published&repository=1', 'staff_target' => SITE_URL . 'pages/shared/publications.php?module_page=publication_records', 'title' => 'Research Repository'],
    'publication_records' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/admin/admin-publications.php', 'staff_target' => SITE_URL . 'pages/shared/publications.php?module_page=publication_records', 'title' => 'All Records'],
    'publication_add' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/admin/admin-publication-form.php', 'staff_target' => SITE_URL . 'pages/shared/publication-form.php?module_page=publication_add', 'title' => 'Add New'],
    'publication_reports' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/admin/admin-publication-reports.php', 'staff_target' => SITE_URL . 'pages/shared/publications.php?module_page=publication_reports&report=1', 'title' => 'Reports'],
    'copyrights' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php', 'title' => 'Copyrights'],
    'patents_utility_models' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php?ip_scope=patents_utility_models', 'title' => 'Patents / Utility Models'],
    'innovation_technology_transfer' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php?ip_scope=innovation_technology_transfer', 'title' => 'Innovation & Technology Transfer'],
];

if (!isset($moduleMap[$moduleKey])) {
    header('Location: ' . SITE_URL . 'pages/admin/admin-dashboard.php');
    exit;
}

if ($moduleKey === 'research_repository' && hasRole('admin')) {
    header('Location: ' . SITE_URL . 'pages/admin/admin-dashboard.php');
    exit;
}

if (!hasRole('admin')) {
    $managePages = ['add_activity', 'add_upcoming_activity', 'add_conducted_activity', 'publication_add'];
    requireModulePermission(
        $moduleMap[$moduleKey]['permission'],
        in_array($moduleKey, $managePages, true) ? 'manage' : 'view',
        $moduleMap[$moduleKey]['permission_page'] ?? $moduleKey
    );
}

$target = !hasRole('admin') && isset($moduleMap[$moduleKey]['staff_target'])
    ? $moduleMap[$moduleKey]['staff_target']
    : $moduleMap[$moduleKey]['target'];
$target_query = parse_url($target, PHP_URL_QUERY);
$target_parameters = [];
if (is_string($target_query)) {
    parse_str($target_query, $target_parameters);
}
if (!isset($target_parameters['module_page'])) {
    $target .= ($target_query === null ? '?' : '&') . 'module_page=' . rawurlencode($moduleKey);
}
header('Location: ' . $target);
exit;
