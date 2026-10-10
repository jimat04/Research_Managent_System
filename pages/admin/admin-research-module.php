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
$moduleMap = [
    'seminars' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?type=seminar', 'title' => 'Seminars'],
    'trainings' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?type=training&capacity_page=trainings', 'title' => 'Trainings'],
    'workshops' => ['permission' => 'research_capacity_building', 'target' => SITE_URL . 'pages/shared/activities.php?type=workshop&capacity_page=workshops', 'title' => 'Workshops'],
    'research_proposals' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php?type=title', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php?type=title', 'title' => 'Research Proposals'],
    'research_review' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php', 'title' => 'Research Review'],
    'research_approval' => ['permission' => 'research_development', 'target' => SITE_URL . 'pages/shared/research-submission-review.php', 'staff_target' => SITE_URL . 'pages/shared/research-submission-review.php', 'title' => 'Research Approval'],
    'ongoing_research' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/admin/admin-research.php?status=ongoing', 'staff_target' => SITE_URL . 'pages/staff/staff-dashboard.php', 'title' => 'Ongoing Research'],
    'completed_research' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/admin/admin-research.php?status=completed', 'staff_target' => SITE_URL . 'pages/shared/research-archive.php', 'title' => 'Completed Research'],
    'research_outputs' => ['permission' => 'research_implementation_outputs', 'target' => SITE_URL . 'pages/shared/publications.php?status=published', 'title' => 'Research Outputs'],
    'journal_publications' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/shared/publications.php?publication_type=journal', 'title' => 'Journal Publications'],
    'conference_presentations' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/shared/publications.php?publication_type=conference', 'title' => 'Conference Presentations'],
    'research_repository' => ['permission' => 'publication_dissemination', 'target' => SITE_URL . 'pages/shared/publications.php?status=published&repository=1', 'title' => 'Research Repository'],
    'copyrights' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php', 'title' => 'Copyrights'],
    'patents_utility_models' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php?ip_scope=patents_utility_models', 'title' => 'Patents / Utility Models'],
    'innovation_technology_transfer' => ['permission' => 'intellectual_property_utilization', 'target' => SITE_URL . 'pages/shared/copyrights.php?ip_scope=innovation_technology_transfer', 'title' => 'Innovation & Technology Transfer'],
];

if (!isset($moduleMap[$moduleKey])) {
    header('Location: ' . SITE_URL . 'pages/admin/admin-dashboard.php');
    exit;
}

if (!hasRole('admin')) {
    requireModulePermission($moduleMap[$moduleKey]['permission'], 'view', $moduleKey);
}

$target = !hasRole('admin') && isset($moduleMap[$moduleKey]['staff_target'])
    ? $moduleMap[$moduleKey]['staff_target']
    : $moduleMap[$moduleKey]['target'];
$target .= (strpos($target, '?') === false ? '?' : '&') . 'module_page=' . rawurlencode($moduleKey);
header('Location: ' . $target);
exit;
