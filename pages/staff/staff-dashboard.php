<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['research_staff', 'admin'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$userId = (int) ($user['user_id'] ?? 0);
$staffPermissionMatrix = $role === 'research_staff' ? getStaffPermissionMatrix($userId) : [];
$dashboardCanViewPage = static function (string $moduleKey, string $pageKey) use ($role, $staffPermissionMatrix): bool {
    if ($role === 'admin') {
        return true;
    }

    $level = $staffPermissionMatrix[$moduleKey]['pages'][$pageKey] ?? 'none';
    return in_array($level, ['view', 'manage'], true);
};
$permittedPageCount = 0;
foreach (rmsPermissionModules() as $permissionModuleKey => $permissionModule) {
    foreach ($permissionModule['pages'] as $permissionPageKey => $permissionPageLabel) {
        if ($dashboardCanViewPage($permissionModuleKey, $permissionPageKey)) {
            $permittedPageCount++;
        }
    }
}
$showAggregateMonitoring = $role === 'admin' || $permittedPageCount === 15;

function monitoringDashboardEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function monitoringDashboardTableExists(mysqli $connection, string $table): bool
{
    $allowedTables = [
        'research_activities',
        'activity_participants',
        'publications',
        'copyright_applications',
        'research_projects',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $connection->prepare("SHOW TABLES LIKE '{$table}'");
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function monitoringDashboardStatusBadge(string $status): array
{
    return match ($status) {
        'scheduled', 'pending', 'submitted' => ['label' => ucfirst($status), 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'completed', 'published', 'registered' => ['label' => ucfirst($status), 'tone' => 'success'],
        'cancelled', 'rejected' => ['label' => ucfirst($status), 'tone' => 'danger'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'tone' => 'neutral'],
    };
}

$tables = [
    'activities' => monitoringDashboardTableExists($conn, 'research_activities'),
    'participants' => monitoringDashboardTableExists($conn, 'activity_participants'),
    'publications' => monitoringDashboardTableExists($conn, 'publications'),
    'copyrights' => monitoringDashboardTableExists($conn, 'copyright_applications'),
    'projects' => monitoringDashboardTableExists($conn, 'research_projects'),
];

$projectHasDeletedAt = false;
if ($tables['projects']) {
    $projectDeletedStmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
    if ($projectDeletedStmt) {
        $projectDeletedStmt->execute();
        $projectHasDeletedAt = $projectDeletedStmt->get_result()->num_rows > 0;
        $projectDeletedStmt->close();
    }
}

$researchers = ['total' => 0, 'faculty' => 0, 'student' => 0];
$activeStatus = 'active';
$facultyRole = 'faculty';
$studentRole = 'student';
$stmt = $conn->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(role = ?), 0) AS faculty, COALESCE(SUM(role = ?), 0) AS student FROM users WHERE status = ? AND role IN (?, ?) AND deleted_at IS NULL');
$stmt->bind_param('sssss', $facultyRole, $studentRole, $activeStatus, $facultyRole, $studentRole);
$stmt->execute();
$researcherRow = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
foreach (array_keys($researchers) as $key) $researchers[$key] = (int) ($researcherRow[$key] ?? 0);

$stats = [
    'seminars' => 0,
    'trainings' => 0,
    'workshops' => 0,
    'capacity_upcoming' => 0,
    'capacity_total' => 0,
    'journal_publications' => 0,
    'conference_publications' => 0,
    'published_repository' => 0,
    'publication_total' => 0,
    'journal_unpublished' => 0,
    'conference_unpublished' => 0,
    'published' => 0,
    'pending_publications' => 0,
    'copyright_total' => 0,
    'copyright_applications' => 0,
    'registered_copyrights' => 0,
    'software_outputs' => 0,
    'module_outputs' => 0,
    'proposals' => 0,
    'review_required' => 0,
    'approval_required' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'archived' => 0,
];
if ($tables['activities']) {
    $seminarType = 'seminar';
    $trainingType = 'training';
    $workshopType = 'workshop';
    $stmt = $conn->prepare("SELECT COALESCE(SUM(activity_type = ?), 0) AS seminars, COALESCE(SUM(activity_type = ?), 0) AS trainings, COALESCE(SUM(activity_type = ?), 0) AS workshops, COALESCE(SUM(activity_type = 'seminar' AND status = 'scheduled' AND activity_date >= CURDATE()), 0) AS upcoming_seminars, COALESCE(SUM(activity_type = 'training' AND status = 'scheduled' AND activity_date >= CURDATE()), 0) AS upcoming_trainings, COALESCE(SUM(activity_type = 'workshop' AND status = 'scheduled' AND activity_date >= CURDATE()), 0) AS upcoming_workshops FROM research_activities");
    $stmt->bind_param('sss', $seminarType, $trainingType, $workshopType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['seminars'] = (int) ($row['seminars'] ?? 0);
    $stats['trainings'] = (int) ($row['trainings'] ?? 0);
    $stats['workshops'] = (int) ($row['workshops'] ?? 0);
    $stats['capacity_total'] = $stats['seminars'] + $stats['trainings'] + $stats['workshops'];
    $stats['upcoming_seminars'] = (int) ($row['upcoming_seminars'] ?? 0);
    $stats['upcoming_trainings'] = (int) ($row['upcoming_trainings'] ?? 0);
    $stats['upcoming_workshops'] = (int) ($row['upcoming_workshops'] ?? 0);
}
if ($tables['publications']) {
    $published = 'published';
    $submitted = 'submitted';
    $underReview = 'under_review';
    $stmt = $conn->prepare("SELECT COUNT(*) AS publication_total, COALESCE(SUM(status = ?), 0) AS published, COALESCE(SUM(status IN (?, ?)), 0) AS pending_publications, COALESCE(SUM(publication_type = 'journal'), 0) AS journal_publications, COALESCE(SUM(publication_type = 'conference'), 0) AS conference_publications, COALESCE(SUM(publication_type = 'journal' AND status <> 'published'), 0) AS journal_unpublished, COALESCE(SUM(publication_type = 'conference' AND status <> 'published'), 0) AS conference_unpublished FROM publications");
    $stmt->bind_param('sss', $published, $submitted, $underReview);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['publication_total'] = (int) ($row['publication_total'] ?? 0);
    $stats['published'] = (int) ($row['published'] ?? 0);
    $stats['pending_publications'] = (int) ($row['pending_publications'] ?? 0);
    $stats['journal_publications'] = (int) ($row['journal_publications'] ?? 0);
    $stats['conference_publications'] = (int) ($row['conference_publications'] ?? 0);
    $stats['published_repository'] = $stats['published'];
    $stats['journal_unpublished'] = (int) ($row['journal_unpublished'] ?? 0);
    $stats['conference_unpublished'] = (int) ($row['conference_unpublished'] ?? 0);
}
if ($tables['copyrights']) {
    $pending = 'pending';
    $underReview = 'under_review';
    $registered = 'registered';
    $stmt = $conn->prepare("SELECT COUNT(*) AS copyright_total, COALESCE(SUM(status IN (?, ?)), 0) AS copyright_applications, COALESCE(SUM(status = ?), 0) AS registered_copyrights, COALESCE(SUM(output_type = 'software'), 0) AS software_outputs, COALESCE(SUM(output_type = 'module'), 0) AS module_outputs FROM copyright_applications");
    $stmt->bind_param('sss', $pending, $underReview, $registered);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['copyright_total'] = (int) ($row['copyright_total'] ?? 0);
    $stats['copyright_applications'] = (int) ($row['copyright_applications'] ?? 0);
    $stats['registered_copyrights'] = (int) ($row['registered_copyrights'] ?? 0);
    $stats['software_outputs'] = (int) ($row['software_outputs'] ?? 0);
    $stats['module_outputs'] = (int) ($row['module_outputs'] ?? 0);
}
if ($tables['projects']) {
    $deletedClause = $projectHasDeletedAt ? ' WHERE deleted_at IS NULL' : '';
    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status = 'proposal') AS proposals, SUM(status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review', 'for_revision', 'revision_required')) AS review_required, SUM(status = 'approved') AS approval_required, SUM(status IN ('ongoing', 'in_progress')) AS ongoing, SUM(status = 'completed') AS completed, SUM(status = 'archived') AS archived FROM research_projects{$deletedClause}");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stats['proposals'] = (int) ($row['proposals'] ?? 0);
    $stats['review_required'] = (int) ($row['review_required'] ?? 0);
    $stats['approval_required'] = (int) ($row['approval_required'] ?? 0);
    $stats['ongoing'] = (int) ($row['ongoing'] ?? 0);
    $stats['completed'] = (int) ($row['completed'] ?? 0);
    $stats['archived'] = (int) ($row['archived'] ?? 0);
    $stats['total_projects'] = (int) ($row['total'] ?? 0);
}

$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$monthlyActivity = [];
for ($index = 0; $index < 6; $index++) {
    $month = $monthStart->modify('+' . $index . ' months');
    $monthlyActivity[$month->format('Y-m')] = ['label' => $month->format('M Y'), 'count' => 0];
}
if ($tables['activities']) {
    $cancelled = 'cancelled';
    $startDate = $monthStart->format('Y-m-d');
    $endDate = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
    $stmt = $conn->prepare("SELECT DATE_FORMAT(activity_date, '%Y-%m') AS month_key, COUNT(*) AS total FROM research_activities WHERE status <> ? AND activity_date >= ? AND activity_date < ? GROUP BY DATE_FORMAT(activity_date, '%Y-%m') ORDER BY month_key");
    $stmt->bind_param('sss', $cancelled, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = (string) $row['month_key'];
        if (isset($monthlyActivity[$key])) $monthlyActivity[$key]['count'] = (int) $row['total'];
    }
    $stmt->close();
}
$monthlyMax = 0;
foreach ($monthlyActivity as $month) $monthlyMax = max($monthlyMax, $month['count']);

$chartStart = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
$chartStartDate = $chartStart->format('Y-m-01');
$chartNextMonthDate = (new DateTimeImmutable('first day of next month'))->format('Y-m-01');
$chartMonthKeys = [];
for ($chartIndex = 0; $chartIndex < 12; $chartIndex++) {
    $monthDate = $chartStart->modify('+' . $chartIndex . ' months');
    $chartMonthKeys[] = $monthDate->format('Y-m');
}
$chartData = [];
foreach ($chartMonthKeys as $monthKey) {
    $chartData[$monthKey] = ['seminars' => 0, 'workshops' => 0, 'publications' => 0, 'copyrights' => 0];
}
if ($tables['activities']) {
    $stmt = $conn->prepare("SELECT DATE_FORMAT(activity_date, '%Y-%m') AS month_key, SUM(activity_type = 'seminar' AND status <> 'cancelled') AS seminars, SUM(activity_type = 'workshop' AND status <> 'cancelled') AS workshops FROM research_activities WHERE activity_date >= ? AND activity_date < ? GROUP BY DATE_FORMAT(activity_date, '%Y-%m')");
    $stmt->bind_param('ss', $chartStartDate, $chartNextMonthDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthKey = (string) ($row['month_key'] ?? '');
        if (isset($chartData[$monthKey])) {
            $chartData[$monthKey]['seminars'] = (int) ($row['seminars'] ?? 0);
            $chartData[$monthKey]['workshops'] = (int) ($row['workshops'] ?? 0);
        }
    }
    $stmt->close();
}
if ($tables['publications']) {
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM publications WHERE created_at >= ? AND created_at < ? GROUP BY DATE_FORMAT(created_at, '%Y-%m')");
    $stmt->bind_param('ss', $chartStartDate, $chartNextMonthDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthKey = (string) ($row['month_key'] ?? '');
        if (isset($chartData[$monthKey])) {
            $chartData[$monthKey]['publications'] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
}
if ($tables['copyrights']) {
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM copyright_applications WHERE created_at >= ? AND created_at < ? GROUP BY DATE_FORMAT(created_at, '%Y-%m')");
    $stmt->bind_param('ss', $chartStartDate, $chartNextMonthDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthKey = (string) ($row['month_key'] ?? '');
        if (isset($chartData[$monthKey])) {
            $chartData[$monthKey]['copyrights'] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
}
$chartMaxValue = 0;
foreach ($chartData as $entry) {
    $chartMaxValue = max($chartMaxValue, max($entry['seminars'], $entry['workshops'], $entry['publications'], $entry['copyrights']));
}
$chartMaxValue = max(1, $chartMaxValue);
$chartMonthLabels = [];
foreach ($chartMonthKeys as $monthKey) {
    $chartMonthLabels[] = date('M', strtotime($monthKey . '-01'));
}

$eventParts = [];
if ($tables['activities']) {
    $eventParts[] = "SELECT ra.created_at AS event_at, CONCAT(UCASE(LEFT(ra.activity_type, 1)), SUBSTRING(ra.activity_type, 2), ' created') AS event_label, COALESCE(NULLIF(ra.organizer, ''), NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'Institutional activity') AS actor_name, ra.status, 'activity' AS source_type, ra.activity_id AS source_id FROM research_activities ra LEFT JOIN users u ON u.user_id = ra.created_by";
}
if ($tables['publications']) {
    $eventParts[] = "SELECT p.created_at AS event_at, 'Publication submitted' AS event_label, COALESCE(NULLIF(p.researcher_name, ''), 'Unnamed researcher') AS actor_name, p.status, 'publication' AS source_type, p.publication_id AS source_id FROM publications p";
    $eventParts[] = "SELECT p.updated_at AS event_at, 'Publication published' AS event_label, COALESCE(NULLIF(p.researcher_name, ''), 'Unnamed researcher') AS actor_name, 'published' AS status, 'publication' AS source_type, p.publication_id AS source_id FROM publications p WHERE p.status = 'published'";
}
if ($tables['copyrights']) {
    $eventParts[] = "SELECT c.created_at AS event_at, 'Copyright applied' AS event_label, COALESCE(NULLIF(c.applicant_name, ''), 'Unnamed applicant') AS actor_name, c.status, 'copyright' AS source_type, c.copyright_id AS source_id FROM copyright_applications c";
    $eventParts[] = "SELECT c.updated_at AS event_at, 'Copyright registered' AS event_label, COALESCE(NULLIF(c.applicant_name, ''), 'Unnamed applicant') AS actor_name, 'registered' AS status, 'copyright' AS source_type, c.copyright_id AS source_id FROM copyright_applications c WHERE c.status = 'registered'";
}
$recentEvents = [];
if ($eventParts) {
    $eventSql = 'SELECT event_at, event_label, actor_name, status, source_type, source_id FROM (' . implode(' UNION ALL ', $eventParts) . ') AS module_events ORDER BY event_at DESC LIMIT 6';
    $stmt = $conn->prepare($eventSql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentEvents[] = $row;
    $stmt->close();
}

$eventUrl = static function (array $event): string {
    $path = match ($event['source_type']) {
        'activity' => 'pages/shared/activity-detail.php?id=',
        'publication' => 'pages/shared/publication-detail.php?id=',
        'copyright' => 'pages/shared/copyright-detail.php?id=',
        default => 'pages/shared/activities.php?id=',
    };
    return SITE_URL . $path . (int) $event['source_id'];
};

$firstName = trim((string) ($user['first_name'] ?? '')) ?: ($role === 'admin' ? 'Administrator' : 'Research Staff');
$roleLabel = $role === 'admin' ? 'Administrator' : 'Research Staff';
$activeAcademicYear = ['label' => '2026 - 2027', 'semester' => '2nd Semester'];
if ($conn) {
    $academicYearStmt = $conn->prepare('SELECT label, semester FROM academic_years WHERE is_active = 1 ORDER BY ay_id DESC LIMIT 1');
    if ($academicYearStmt) {
        $academicYearStmt->execute();
        $academicYearRow = $academicYearStmt->get_result()->fetch_assoc();
        if ($academicYearRow) {
            $activeAcademicYear['label'] = trim((string) ($academicYearRow['label'] ?? '')) !== '' ? (string) $academicYearRow['label'] : $activeAcademicYear['label'];
            $activeAcademicYear['semester'] = trim((string) ($academicYearRow['semester'] ?? '')) !== '' ? (string) $academicYearRow['semester'] : $activeAcademicYear['semester'];
        }
        $academicYearStmt->close();
    }
}
$currentPage = $role === 'admin' ? 'admin-dashboard.php' : 'staff-dashboard.php';
$pageTitle = $role === 'admin' ? 'Dashboard' : 'Research monitoring';
$pageSubtitle = $role === 'admin' ? 'Institution-wide research monitoring overview' : 'Research activity, publication, and copyright monitoring';
$facultyShare = $researchers['total'] > 0 ? round(($researchers['faculty'] / $researchers['total']) * 100, 1) : 0;
$studentShare = $researchers['total'] > 0 ? round(($researchers['student'] / $researchers['total']) * 100, 1) : 0;
$moduleRoute = static function (string $pageKey): string {
    return SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($pageKey);
};
$dashboardModuleCards = [
    [
        'key' => 'research_capacity_building',
        'class' => 'module-capacity',
        'label' => 'Research Capacity Building',
        'pages' => [
            ['key' => 'seminars', 'label' => 'Seminars', 'count' => $tables['activities'] ? $stats['seminars'] : null, 'detail' => $tables['activities'] ? 'recorded' : 'unavailable'],
            ['key' => 'trainings', 'label' => 'Trainings', 'count' => $tables['activities'] ? $stats['trainings'] : null, 'detail' => $tables['activities'] ? 'recorded' : 'unavailable'],
            ['key' => 'workshops', 'label' => 'Workshops', 'count' => $tables['activities'] ? $stats['workshops'] : null, 'detail' => $tables['activities'] ? 'recorded' : 'unavailable'],
        ],
    ],
    [
        'key' => 'research_development',
        'class' => 'module-development',
        'label' => 'Research Development',
        'pages' => [
            ['key' => 'research_proposals', 'label' => 'Research Proposals', 'count' => $tables['projects'] ? $stats['proposals'] : null, 'detail' => $tables['projects'] ? 'proposals' : 'unavailable'],
            ['key' => 'research_review', 'label' => 'Research Review', 'count' => $tables['projects'] ? $stats['review_required'] : null, 'detail' => $tables['projects'] ? 'requiring review' : 'unavailable'],
            ['key' => 'research_approval', 'label' => 'Research Approval', 'count' => $tables['projects'] ? $stats['approval_required'] : null, 'detail' => $tables['projects'] ? 'awaiting approval' : 'unavailable'],
        ],
    ],
    [
        'key' => 'research_implementation_outputs',
        'class' => 'module-implementation',
        'label' => 'Research Implementation & Outputs',
        'pages' => [
            ['key' => 'ongoing_research', 'label' => 'Ongoing Research', 'count' => $tables['projects'] ? $stats['ongoing'] : null, 'detail' => $tables['projects'] ? 'ongoing projects' : 'unavailable'],
            ['key' => 'completed_research', 'label' => 'Completed Research', 'count' => $tables['projects'] ? $stats['completed'] : null, 'detail' => $tables['projects'] ? 'completed projects' : 'unavailable'],
            ['key' => 'research_outputs', 'label' => 'Research Outputs', 'count' => $tables['publications'] ? $stats['published'] : null, 'detail' => $tables['publications'] ? 'published outputs' : 'unavailable'],
        ],
    ],
    [
        'key' => 'publication_dissemination',
        'class' => 'module-publication',
        'label' => 'Publication & Dissemination',
        'pages' => [
            ['key' => 'journal_publications', 'label' => 'Journal Publications', 'count' => $tables['publications'] ? $stats['journal_publications'] : null, 'detail' => $tables['publications'] ? 'records' : 'unavailable'],
            ['key' => 'conference_presentations', 'label' => 'Conference Presentations', 'count' => $tables['publications'] ? $stats['conference_publications'] : null, 'detail' => $tables['publications'] ? 'records' : 'unavailable'],
            ['key' => 'research_repository', 'label' => 'Research Repository', 'count' => $tables['publications'] ? $stats['published_repository'] : null, 'detail' => $tables['publications'] ? 'published records' : 'unavailable'],
        ],
    ],
    [
        'key' => 'intellectual_property_utilization',
        'class' => 'module-ip',
        'label' => 'Intellectual Property & Utilization',
        'pages' => [
            ['key' => 'copyrights', 'label' => 'Copyrights', 'count' => $tables['copyrights'] ? $stats['copyright_total'] : null, 'detail' => $tables['copyrights'] ? 'applications and records' : 'unavailable'],
            ['key' => 'patents_utility_models', 'label' => 'Patents / Utility Models', 'count' => $tables['copyrights'] ? $stats['software_outputs'] : null, 'detail' => $tables['copyrights'] ? 'software outputs recorded' : 'unavailable'],
            ['key' => 'innovation_technology_transfer', 'label' => 'Innovation & Technology Transfer', 'count' => $tables['copyrights'] ? $stats['module_outputs'] : null, 'detail' => $tables['copyrights'] ? 'module outputs recorded' : 'unavailable'],
        ],
    ],
];
foreach ($dashboardModuleCards as &$moduleCard) {
    $moduleCard['pages'] = array_values(array_filter(
        $moduleCard['pages'],
        static fn (array $page): bool => $dashboardCanViewPage($moduleCard['key'], $page['key'])
    ));
    $moduleCard['visible'] = $moduleCard['pages'] !== [];
    $moduleCard['count'] = 0;
    $moduleCard['hasCount'] = false;
    foreach ($moduleCard['pages'] as $page) {
        if ($page['count'] !== null) {
            $moduleCard['count'] += (int) $page['count'];
            $moduleCard['hasCount'] = true;
        }
    }

    if ($moduleCard['key'] === 'publication_dissemination') {
        $hasRepository = $dashboardCanViewPage($moduleCard['key'], 'research_repository');
        $hasJournal = $dashboardCanViewPage($moduleCard['key'], 'journal_publications');
        $hasConference = $dashboardCanViewPage($moduleCard['key'], 'conference_presentations');
        $moduleCard['count'] = $hasRepository ? $stats['published_repository'] : 0;
        $moduleCard['count'] += $hasJournal
            ? ($hasRepository ? $stats['journal_unpublished'] : $stats['journal_publications'])
            : 0;
        $moduleCard['count'] += $hasConference
            ? ($hasRepository ? $stats['conference_unpublished'] : $stats['conference_publications'])
            : 0;
    } elseif ($moduleCard['key'] === 'intellectual_property_utilization') {
        if ($dashboardCanViewPage($moduleCard['key'], 'copyrights')) {
            $moduleCard['count'] = $stats['copyright_total'];
        } else {
            $moduleCard['count'] = 0;
            if ($dashboardCanViewPage($moduleCard['key'], 'patents_utility_models')) {
                $moduleCard['count'] += $stats['software_outputs'];
            }
            if ($dashboardCanViewPage($moduleCard['key'], 'innovation_technology_transfer')) {
                $moduleCard['count'] += $stats['module_outputs'];
            }
        }
    }
    $moduleCard['sourceAvailable'] = match ($moduleCard['key']) {
        'research_capacity_building' => $tables['activities'],
        'research_development' => $tables['projects'],
        'research_implementation_outputs' => $tables['projects'] || $tables['publications'],
        'publication_dissemination' => $tables['publications'],
        'intellectual_property_utilization' => $tables['copyrights'],
        default => false,
    };
    $moduleCard['upcoming'] = 0;
    if ($moduleCard['key'] === 'research_capacity_building') {
        $upcomingCountsByPage = [
            'seminars' => $stats['upcoming_seminars'] ?? 0,
            'trainings' => $stats['upcoming_trainings'] ?? 0,
            'workshops' => $stats['upcoming_workshops'] ?? 0,
        ];
        foreach ($moduleCard['pages'] as $modulePage) {
            $moduleCard['upcoming'] += (int) ($upcomingCountsByPage[$modulePage['key']] ?? 0);
        }
    }
}
unset($moduleCard);
$dashboardQuickPages = [];
$quickPageOrder = ['seminars', 'research_proposals', 'research_review', 'ongoing_research', 'journal_publications', 'copyrights', 'workshops', 'research_outputs'];
foreach ($quickPageOrder as $quickPageKey) {
    foreach ($dashboardModuleCards as $moduleCard) {
        foreach ($moduleCard['pages'] as $modulePage) {
            if ($modulePage['key'] === $quickPageKey) {
                $dashboardQuickPages[] = $modulePage;
                break 2;
            }
        }
    }
    if (count($dashboardQuickPages) >= 5) {
        break;
    }
}
if ($role === 'admin') renderAdminShell($user, $currentPage, $pageTitle, $pageSubtitle);
else renderStaffShell($user, $currentPage, $pageTitle, $pageSubtitle);
?>

<style>
.module-hub{--accent:#315b9f;--ink:#172033;--copy:#64748b;--line:#e2e8f0;max-width:1380px;margin:0 auto;color:var(--ink)}.module-greeting{position:relative;display:grid;grid-template-columns:minmax(0,1.8fr) 220px;align-items:center;gap:22px;min-height:160px;margin:0 0 24px;padding:28px 32px;border:1px solid rgba(255,255,255,.06);border-radius:14px;overflow:hidden;background:linear-gradient(90deg,#071d3d 0%,#0f2b5c 46%,#1b3d8c 100%);box-shadow:0 12px 32px rgba(17,41,82,.15)}.module-greeting::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(36,77,152,.08),transparent 48%),url('<?php echo htmlspecialchars(SITE_URL . 'photos/admin-hero-building.jpg', ENT_QUOTES, 'UTF-8'); ?>') right center / cover no-repeat;opacity:.92;mix-blend-mode:screen}.module-greeting::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(9,20,41,.74) 0%,rgba(14,31,61,.68) 28%,rgba(20,42,88,.1) 58%,rgba(20,42,88,0) 100%)}.module-kicker,.module-greeting h1,.module-greeting p,.greeting-meta{position:relative;z-index:1}.module-kicker{margin:0 0 8px;color:#dfe7ff;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-greeting h1{margin:0;color:#fff;font-size:clamp(28px,2.7vw,52px);font-weight:700;letter-spacing:-.05em;line-height:1.04;text-wrap:balance}.module-greeting p{max-width:620px;margin:10px 0 0;color:#dfeafc;line-height:1.6}.greeting-meta{display:flex;justify-content:flex-end}.greeting-meta .academic-year-card{display:flex;align-items:center;gap:12px;min-width:160px;padding:14px 18px;border:1px solid rgba(255,255,255,.16);border-radius:12px;background:rgba(255,255,255,.08);backdrop-filter:blur(7px)}.academic-year-icon{display:grid;width:34px;height:34px;place-items:center;border-radius:10px;background:rgba(255,255,255,.12);color:#fff;font-size:18px}.academic-year-meta{display:grid;gap:3px}.academic-year-label{color:#d5def6;font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}.academic-year-meta strong{color:#fff;font-size:15px;font-weight:700;letter-spacing:-.02em}.academic-year-meta span{color:#d6def5;font-size:11px}.stat-grid{display:grid;grid-template-columns:repeat(7,minmax(145px,1fr));gap:10px;margin-bottom:24px}.stat-card{min-width:0;padding:18px;border:1px solid #dfe5ee;border-radius:14px;background:#fff}.stat-card strong{display:block;font-size:28px;line-height:1;font-variant-numeric:tabular-nums}.stat-card>span{display:block;margin-top:8px;color:#475569;font-size:11px;font-weight:800;line-height:1.35}.stat-card small{display:block;margin-top:8px;color:#7b8798;font-size:10px;line-height:1.45}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(360px,.8fr);gap:18px;margin-bottom:18px}.hub-panel{min-width:0;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px;letter-spacing:-.015em}.panel-head p{margin:5px 0 0;color:var(--copy);font-size:12px;line-height:1.45}.bar-chart{display:grid;gap:15px;padding:22px}.bar-row{display:grid;grid-template-columns:72px minmax(0,1fr) 28px;gap:12px;align-items:center}.bar-label,.bar-value{font-size:11px;font-weight:750}.bar-label{color:#526176}.bar-value{text-align:right;font-variant-numeric:tabular-nums}.bar-track{height:11px;overflow:hidden;border-radius:3px;background:#edf1f6}.bar-fill{height:100%;min-width:0;border-radius:3px;background:#315b9f}.event-wrap{overflow-x:auto}.event-table{width:100%;min-width:720px;border-collapse:collapse}.event-table th{padding:11px 15px;background:#f8fafc;color:var(--copy);font-size:10px;letter-spacing:.06em;text-align:left;text-transform:uppercase}.event-table td{padding:14px 15px;border-top:1px solid #edf1f5;font-size:12px;vertical-align:middle}.event-name{color:var(--ink);font-weight:750;text-decoration:none}.event-name:hover{color:var(--accent)}.event-date{white-space:nowrap;color:#526176;font-variant-numeric:tabular-nums}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.quick-actions{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:20px}.action-link{display:flex;align-items:center;justify-content:center;min-height:46px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font-size:12px;font-weight:800;text-align:center;text-decoration:none;transition:transform .18s ease,border-color .18s ease,color .18s ease,background .18s ease}.action-link.primary{border-color:var(--accent);background:var(--accent);color:#fff}.action-link:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px)}.action-link.primary:hover{background:#24477e;color:#fff}.action-link:active{transform:scale(.98)}.action-link:focus-visible,.event-name:focus-visible{outline:3px solid rgba(49,91,159,.15);outline-offset:3px}.empty-state{padding:38px 22px;text-align:center;color:var(--copy);font-size:13px;line-height:1.55}@media(max-width:1180px){.stat-grid{grid-template-columns:repeat(4,1fr)}.dashboard-grid{grid-template-columns:1fr}.quick-actions{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.module-greeting{display:block;padding:24px 21px}.greeting-meta{margin-top:18px;text-align:left}.stat-grid{grid-template-columns:repeat(2,1fr)}.quick-actions{grid-template-columns:1fr}.bar-row{grid-template-columns:64px minmax(0,1fr) 24px}}
 .module-hub:not(.admin-monitoring-dashboard) .stat-icon{display:none}
</style>

<style>
  .module-hub:not(.admin-monitoring-dashboard) .dashboard-chart-panel,
  .module-hub:not(.admin-monitoring-dashboard) .dashboard-recent-panel {
    min-width: 0;
    padding: 22px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
  }

  .module-hub:not(.admin-monitoring-dashboard) .dashboard-grid:not(.dashboard-grid-secondary) {
    grid-template-columns: minmax(0, 1.1fr) minmax(520px, 0.9fr);
  }

  .module-hub:not(.admin-monitoring-dashboard) .dashboard-grid-secondary {
    grid-template-columns: minmax(0, 1fr);
  }

  .module-hub:not(.admin-monitoring-dashboard) .quick-actions {
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
    gap: 12px;
  }

  .module-hub:not(.admin-monitoring-dashboard) .quick-actions .action-link {
    min-width: 0;
    min-height: 72px;
    padding: 14px;
    font-size: 13px;
    line-height: 1.4;
    text-align: center;
    overflow-wrap: anywhere;
  }

  .module-hub:not(.admin-monitoring-dashboard) .chart-header-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
  }

  .module-hub:not(.admin-monitoring-dashboard) .chart-header-row h2,
  .module-hub:not(.admin-monitoring-dashboard) .panel-head h2 {
    margin: 0;
    color: #172033;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.35;
  }

  .module-hub:not(.admin-monitoring-dashboard) .panel-link {
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.45;
    text-align: right;
  }

  .module-hub:not(.admin-monitoring-dashboard) .chart-legend {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 8px 16px;
    margin: 8px 0 12px;
    color: #475569;
    font-size: 12px;
    line-height: 1.4;
  }

  .module-hub:not(.admin-monitoring-dashboard) .chart-legend-item {
    display: inline-flex;
    width: auto;
    flex: 0 0 auto;
    align-items: center;
    gap: 7px;
    white-space: nowrap;
  }

  .module-hub:not(.admin-monitoring-dashboard) .chart-legend-swatch {
    display: inline-block;
    width: 9px;
    height: 9px;
    flex: 0 0 9px;
    border-radius: 2px;
  }

  .module-hub:not(.admin-monitoring-dashboard) .month-chart {
    display: block;
    width: 100%;
    height: auto;
    aspect-ratio: 540 / 160;
  }

  .module-hub:not(.admin-monitoring-dashboard) .dashboard-recent-panel .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-wrap {
    width: 100%;
    min-width: 0;
    overflow: visible;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-table {
    width: 100%;
    min-width: 0;
    table-layout: fixed;
    border-collapse: collapse;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-table th,
  .module-hub:not(.admin-monitoring-dashboard) .event-table td {
    padding: 10px 4px;
    border-bottom: 1px solid #edf1f7;
    color: #334155;
    font-size: 11px;
    line-height: 1.4;
    text-align: left;
    vertical-align: top;
    overflow-wrap: anywhere;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-table th {
    color: #64748b;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(1),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(1) { width: 18%; }
  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(2),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(2) { width: 25%; }
  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(3),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(3) { width: 25%; }
  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(4),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(4) { width: 14%; }
  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(5),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(5) { width: 18%; }
  .module-hub:not(.admin-monitoring-dashboard) .event-table th:nth-child(6),
  .module-hub:not(.admin-monitoring-dashboard) .event-table td:nth-child(6) { display: none; }

  .module-hub:not(.admin-monitoring-dashboard) .event-name {
    color: #172033;
    font-weight: 650;
    text-decoration: none;
    overflow-wrap: anywhere;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-name:hover {
    color: #0d9488;
    text-decoration: underline;
  }

  .module-hub:not(.admin-monitoring-dashboard) .event-table .badge {
    max-width: 100%;
    white-space: normal;
    font-size: 10px;
  }

  @media (max-width: 768px) {
    .module-hub:not(.admin-monitoring-dashboard) .event-table,
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody {
      display: block;
      width: 100%;
    }

    .module-hub:not(.admin-monitoring-dashboard) .event-table thead {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
    }

    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody tr {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px 14px;
      padding: 12px 0;
      border-bottom: 1px solid #edf1f7;
    }

    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td {
      display: block;
      width: auto !important;
      padding: 2px 0 !important;
      border: 0;
    }

    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td::before {
      display: block;
      margin-bottom: 2px;
      color: #64748b;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(1)::before { content: 'Date'; }
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(2)::before { content: 'Activity'; }
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(3)::before { content: 'Researcher'; }
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(4)::before { content: 'Category'; }
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(5)::before { content: 'Status'; }
    .module-hub:not(.admin-monitoring-dashboard) .event-table tbody td:nth-child(5) { grid-column: 1 / -1; }
  }

  @media (max-width: 1400px) {
    .module-hub:not(.admin-monitoring-dashboard) .dashboard-grid:not(.dashboard-grid-secondary) {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .admin-body .admin-dashboard .admin-page-content .module-hub {
    max-width: 100%;
    margin: 0;
    color: #172033;
  }

  .admin-body .admin-dashboard .admin-page-content .stat-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 10px;
    margin: 0 0 12px;
  }

  .admin-body .admin-dashboard .admin-page-content .stat-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 146px;
    padding: 16px 12px 14px;
    border: 1px solid #edf1f7;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(15, 30, 60, 0.04);
    text-align: center;
  }

  .admin-body .admin-dashboard .admin-page-content .stat-card .stat-icon {
    display: inline-flex;
    width: 38px;
    height: 38px;
    min-width: 38px;
    min-height: 38px;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
    border-radius: 8px;
    color: #fff;
    background: linear-gradient(135deg, #4f8df7, #2f6ae5);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.2);
  }

  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #f43f5e, #ef4444); }
  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, #22c55e, #16a34a); }
  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(5) .stat-icon { background: linear-gradient(135deg, #f59e0b, #ea580c); }
  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(6) .stat-icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
  .admin-body .admin-dashboard .admin-page-content .stat-card:nth-child(7) .stat-icon { background: linear-gradient(135deg, #22d3ee, #0ea5e9); }

  .admin-body .admin-dashboard .admin-page-content .stat-card strong {
    margin: 0;
    font-size: 24px;
    line-height: 1.1;
    letter-spacing: -0.04em;
    color: #1f2937;
  }

  .admin-body .admin-dashboard .admin-page-content .stat-card span {
    margin-top: 4px;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.3;
    color: #2b3a4a;
  }

  .admin-body .admin-dashboard .admin-page-content .stat-card small {
    margin-top: 4px;
    font-size: 9px;
    line-height: 1.2;
    color: #64748b;
  }

  .admin-body .admin-dashboard .admin-page-content .dashboard-grid {
    display: grid;
    grid-template-columns: 1.05fr 1.05fr;
    gap: 12px;
    margin: 0 0 12px;
  }

  .admin-body .admin-dashboard .admin-page-content .hub-panel {
    border: 1px solid #edf1f7;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(15, 30, 60, 0.04);
    padding: 12px 12px 10px;
  }

  .admin-body .admin-dashboard .admin-page-content .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
  }

  .admin-body .admin-dashboard .admin-page-content .panel-head h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
  }

  .admin-body .admin-dashboard .admin-page-content .panel-head p {
    margin: 2px 0 0;
    font-size: 10px;
    color: #64748b;
  }

  .admin-body .admin-dashboard .admin-page-content .panel-link {
    font-size: 11px;
    font-weight: 600;
    color: #3b82f6;
    text-decoration: none;
  }

  .admin-body .admin-dashboard .admin-page-content .chart-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
  }

  .admin-body .admin-dashboard .admin-page-content .chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 14px;
    margin: 8px 0 4px;
    font-size: 10px;
    color: #475569;
  }

  .admin-body .admin-dashboard .admin-page-content .chart-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .admin-body .admin-dashboard .admin-page-content .chart-legend-swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    display: inline-block;
  }

  .admin-body .admin-dashboard .admin-page-content .month-chart {
    width: 100%;
    height: 160px;
    display: block;
    overflow: visible;
  }

  .admin-body .admin-dashboard .admin-page-content .event-table {
    width: 100%;
    border-collapse: collapse;
  }

  .admin-body .admin-dashboard .admin-page-content .event-table th,
  .admin-body .admin-dashboard .admin-page-content .event-table td {
    padding: 7px 6px;
    border-bottom: 1px solid #edf1f7;
    font-size: 10px;
    text-align: left;
    vertical-align: middle;
  }

  .admin-body .admin-dashboard .admin-page-content .event-table th {
    color: #64748b;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .admin-body .admin-dashboard .admin-page-content .event-table td {
    color: #1f2937;
  }

  .admin-body .admin-dashboard .admin-page-content .event-name {
    color: #1f2937;
    text-decoration: none;
    font-weight: 500;
  }

  .admin-body .admin-dashboard .admin-page-content .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 20px;
    padding: 3px 7px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 700;
    border: 1px solid transparent;
  }

  .admin-body .admin-dashboard .admin-page-content .badge.tone-success { background: #eaf7f0; color: #0f8a5f; }
  .admin-body .admin-dashboard .admin-page-content .badge.tone-warning { background: #fff4d8; color: #b76a00; }
  .admin-body .admin-dashboard .admin-page-content .badge.tone-review { background: #fbe9d6; color: #b15a12; }
  .admin-body .admin-dashboard .admin-page-content .badge.tone-danger { background: #fdeaea; color: #bc2d2d; }
  .admin-body .admin-dashboard .admin-page-content .badge.tone-neutral { background: #edf2ff; color: #3b5cb2; }

  .admin-body .admin-dashboard .admin-page-content .dashboard-grid-secondary {
    grid-template-columns: 1.05fr 1.05fr;
  }

  .admin-body .admin-dashboard .admin-page-content .quick-actions {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    margin-top: 8px;
  }

  .admin-body .admin-dashboard .admin-page-content .action-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 86px;
    padding: 10px 8px;
    border: 1px solid #edf1f7;
    border-radius: 10px;
    background: linear-gradient(180deg, #f7fbff 0%, #f2f6ff 100%);
    color: #1f2937;
    font-size: 10px;
    font-weight: 600;
    text-align: center;
    line-height: 1.25;
    text-decoration: none;
  }

  .admin-body .admin-dashboard .admin-page-content .action-link .action-icon {
    display: inline-flex;
    width: 32px;
    height: 32px;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    color: #fff;
    font-size: 15px;
    background: linear-gradient(135deg, #4f8df7, #6d5ef6);
  }

  .admin-body .admin-dashboard .admin-page-content .action-link:nth-child(2) .action-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
  .admin-body .admin-dashboard .admin-page-content .action-link:nth-child(3) .action-icon { background: linear-gradient(135deg, #22c55e, #16a34a); }
  .admin-body .admin-dashboard .admin-page-content .action-link:nth-child(4) .action-icon { background: linear-gradient(135deg, #f59e0b, #ea580c); }
  .admin-body .admin-dashboard .admin-page-content .action-link:nth-child(5) .action-icon { background: linear-gradient(135deg, #f472b6, #ec4899); }

  .admin-body .admin-dashboard .admin-page-content .researcher-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 120px;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-donut {
    position: relative;
    display: grid;
    width: 108px;
    height: 108px;
    place-items: center;
    border-radius: 50%;
    background: conic-gradient(#2563eb 0 var(--faculty-share, 50%), #7aaaf8 var(--faculty-share, 50%) 100%);
    flex: 0 0 auto;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-donut::before {
    content: "";
    position: absolute;
    inset: 16px;
    border-radius: 50%;
    background: #fff;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-donut div {
    position: relative;
    z-index: 1;
    display: grid;
    text-align: center;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-donut strong {
    font-size: 22px;
    line-height: 1;
    letter-spacing: -0.04em;
    color: #1f2937;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-donut span {
    font-size: 9px;
    color: #64748b;
    text-transform: lowercase;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-legend-row {
    display: grid;
    grid-template-columns: 10px 1fr auto auto;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: #334155;
  }

  .admin-body .admin-dashboard .admin-page-content .researcher-key {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
  }

  .admin-body .admin-dashboard .admin-page-content .faculty-key { background: #2563eb; }
  .admin-body .admin-dashboard .admin-page-content .student-key { background: #7aaaf8; }
</style>

<div class="module-hub<?= $role === 'admin' ? ' admin-monitoring-dashboard' : '' ?>">
  <header class="module-greeting">
    <div class="greeting-copy">
      <p class="module-kicker">WELCOME BACK, <?= monitoringDashboardEscape(strtoupper($firstName)) ?></p>
      <h1>Research Management System</h1>
      <p>Track research capacity building, project development, outputs, and dissemination across EARIST Cavite.</p>
    </div>
    <div class="greeting-meta">
      <div class="academic-year-date"><?= date('l, M j, Y') ?></div>
      <div class="academic-year-card">
        <span class="academic-year-icon" aria-hidden="true">🗓️</span>
        <div class="academic-year-meta">
          <span class="academic-year-label">Academic Year</span>
          <strong><?= monitoringDashboardEscape($activeAcademicYear['label']) ?></strong>
          <span><?= monitoringDashboardEscape($activeAcademicYear['semester']) ?></span>
        </div>
      </div>
    </div>
  </header>

  <style>
    .module-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
      gap: 12px;
      margin: 0 0 18px;
    }
    .module-summary-card {
      display: flex;
      flex-direction: column;
      gap: 12px;
      min-width: 0;
      min-height: 270px;
      height: 100%;
      padding: 20px;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
      color: #111827;
      text-decoration: none;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }
    .module-summary-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 18px 25px rgba(15, 23, 42, 0.08);
      border-color: rgba(91, 30, 188, 0.28);
    }
    .module-summary-card strong {
      font-size: clamp(28px, 2.1vw, 42px);
      line-height: 1;
      letter-spacing: -0.05em;
      color: #0f172a;
    }
    .module-summary-card p {
      margin: 0;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.35;
      color: #1f2937;
    }
    .module-summary-card small {
      font-size: 11px;
      line-height: 1.5;
      color: #64748b;
    }
    .module-summary-metric {
      display: flex;
      align-items: baseline;
      gap: 9px;
    }
    .module-summary-metric span {
      color: #64748b;
      font-size: 11px;
      font-weight: 600;
    }
    .module-summary-links {
      display: grid;
      gap: 6px;
      margin-top: auto;
      padding-top: 12px;
      border-top: 1px solid #edf0f4;
    }
    .module-summary-links a {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      min-width: 0;
      align-items: center;
      gap: 4px 8px;
      padding: 7px 9px;
      border-radius: 8px;
      color: #334155;
      font-size: 12px;
      font-weight: 600;
      line-height: 1.35;
      text-decoration: none;
    }
    .module-summary-links a:hover {
      background: #f8fafc;
      color: #5b1ebc;
    }
    .module-summary-links a:focus-visible {
      outline: 2px solid #5b1ebc;
      outline-offset: 2px;
    }
    .module-summary-links a span:first-child {
      min-width: 0;
      overflow-wrap: break-word;
    }
    .module-summary-links a span:last-child {
      min-width: 0;
      color: #64748b;
      font-size: 10px;
      font-weight: 500;
      text-align: right;
      overflow-wrap: anywhere;
    }
    .module-pill {
      display: inline-flex;
      align-items: center;
      width: fit-content;
      padding: 5px 8px;
      border-radius: 999px;
      font-size: 9px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .module-capacity .module-pill { background: rgba(91, 30, 188, 0.08); color: #5b1ebc; }
    .module-development .module-pill { background: rgba(29, 78, 216, 0.08); color: #1d4ed8; }
    .module-implementation .module-pill { background: rgba(13, 148, 136, 0.08); color: #0d9488; }
    .module-publication .module-pill { background: rgba(16, 185, 129, 0.08); color: #0f766e; }
    .module-ip .module-pill { background: rgba(245, 124, 0, 0.08); color: #b45309; }
    .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid {
      grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid > .module-summary-card:nth-child(-n + 3) {
      grid-column: span 2;
    }
    .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid > .module-summary-card:nth-child(n + 4) {
      grid-column: span 3;
    }
    .admin-monitoring-dashboard > .module-summary-grid {
      grid-column: 1 / -1;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      grid-auto-rows: 1fr;
      gap: 16px;
    }
    .admin-monitoring-dashboard > .module-summary-grid > .module-summary-card:nth-child(-n + 3) {
      grid-column: span 2;
    }
    .admin-monitoring-dashboard > .module-summary-grid > .module-summary-card:nth-child(n + 4) {
      grid-column: span 3;
    }
    @media (max-width: 768px) {
      body.admin-body .admin-dashboard > .admin-main {
        width: 100%;
        margin-left: 0;
      }
    }
    @media (max-width: 1180px) {
      .module-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid > .module-summary-card:nth-child(-n + 4) {
        grid-column: span 1;
      }
      .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid > .module-summary-card:nth-child(5) {
        grid-column: 1 / -1;
      }
    }
    @media (max-width: 900px) {
      .admin-monitoring-dashboard > .module-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: auto;
      }
      .admin-monitoring-dashboard > .module-summary-grid > .module-summary-card:nth-child(n) {
        grid-column: span 1;
      }
      .admin-monitoring-dashboard > .module-summary-grid > .module-summary-card:nth-child(5) {
        grid-column: 1 / -1;
      }
    }
    @media (max-width: 640px) {
      .module-summary-grid { grid-template-columns: 1fr; }
      .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid {
        grid-template-columns: 1fr;
      }
      .module-hub:not(.admin-monitoring-dashboard) > .module-summary-grid > .module-summary-card:nth-child(n) {
        grid-column: 1 / -1;
      }
      .admin-monitoring-dashboard > .module-summary-grid > .module-summary-card:nth-child(n) {
        grid-column: 1 / -1;
      }
    }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-greeting .module-kicker { font-size: 12px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-greeting p:not(.module-kicker) { font-size: 15px; line-height: 1.6; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .academic-year-card { font-size: 13px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .academic-year-card .academic-year-label,
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .academic-year-card .academic-year-meta > span:last-child { font-size: 12px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-card { gap: 13px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-card .module-pill { font-size: 10px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-card p { font-size: 15px; line-height: 1.4; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-card small { font-size: 13px; line-height: 1.55; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-metric span { font-size: 13px; line-height: 1.45; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-links a { font-size: 14px; line-height: 1.45; padding-block: 8px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .module-summary-links a span:last-child { font-size: 12px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .stat-card > span { font-size: 12px; line-height: 1.45; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .stat-card > small { font-size: 11px; line-height: 1.5; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .panel-head h2 { font-size: 18px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .panel-head p,
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .panel-link { font-size: 13px; line-height: 1.5; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .chart-legend { font-size: 12px; line-height: 1.5; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .dashboard-chart-panel { padding: 20px 20px 26px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .event-table th { font-size: 11px; line-height: 1.4; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .event-table td { font-size: 12px; line-height: 1.5; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .event-table .badge { font-size: 11px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .action-link { font-size: 12px; line-height: 1.45; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .researcher-donut span { font-size: 11px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .researcher-legend-row { font-size: 12px; line-height: 1.45; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .researcher-legend-row strong { font-size: 13px; }
    body.admin-body .admin-dashboard .admin-page-content .admin-monitoring-dashboard .researcher-legend-row small { font-size: 11px; line-height: 1.45; }
  </style>

  <section class="module-summary-grid" aria-label="Research module overview">
    <?php foreach ($dashboardModuleCards as $moduleCard): ?>
      <?php if (!$moduleCard['visible']) continue; ?>
      <article class="module-summary-card <?= monitoringDashboardEscape($moduleCard['class']) ?>">
        <span class="module-pill"><?= monitoringDashboardEscape($moduleCard['label']) ?></span>
        <div class="module-summary-metric">
          <strong><?= $moduleCard['hasCount'] ? (int) max(0, $moduleCard['count']) : '&mdash;' ?></strong>
          <span><?= $moduleCard['hasCount'] ? 'records across available pages' : ($moduleCard['sourceAvailable'] ? 'no separate count' : 'record source unavailable') ?></span>
        </div>
        <p><?= monitoringDashboardEscape($moduleCard['label']) ?></p>
        <?php if ($moduleCard['key'] === 'research_capacity_building'): ?>
          <?php if ($tables['activities']): ?>
            <small><?= (int) $moduleCard['upcoming'] ?> upcoming activities across permitted Capacity Building pages.</small>
          <?php endif; ?>
        <?php elseif ($moduleCard['key'] === 'research_development'): ?>
          <small>
            <?php if ($tables['projects'] && ($role === 'admin' || $dashboardCanViewPage($moduleCard['key'], 'research_review'))): ?>
              <?= (int) $stats['review_required'] ?> requiring review
            <?php endif; ?>
            <?php if ($tables['projects'] && ($role === 'admin' || $dashboardCanViewPage($moduleCard['key'], 'research_review')) && ($role === 'admin' || $dashboardCanViewPage($moduleCard['key'], 'research_approval'))): ?>
              &middot;
            <?php endif; ?>
            <?php if ($tables['projects'] && ($role === 'admin' || $dashboardCanViewPage($moduleCard['key'], 'research_approval'))): ?>
              <?= (int) $stats['approval_required'] ?> awaiting approval
            <?php endif; ?>
          </small>
        <?php elseif ($moduleCard['key'] === 'publication_dissemination'): ?>
          <?php if ($tables['publications'] && ($role === 'admin' || $dashboardCanViewPage($moduleCard['key'], 'journal_publications') || $dashboardCanViewPage($moduleCard['key'], 'conference_presentations'))): ?>
            <small><?= (int) $stats['pending_publications'] ?> publication records are submitted or under review.</small>
          <?php endif; ?>
        <?php elseif ($moduleCard['key'] === 'intellectual_property_utilization'): ?>
          <small>Patent and technology-transfer shortcuts show the matching software and module output records in the existing registry.</small>
        <?php else: ?>
          <small>Counts are based on current research project and publication records.</small>
        <?php endif; ?>
        <nav class="module-summary-links" aria-label="<?= monitoringDashboardEscape($moduleCard['label']) ?> pages">
          <?php foreach ($moduleCard['pages'] as $modulePage): ?>
            <a href="<?= monitoringDashboardEscape($moduleRoute($modulePage['key'])) ?>">
              <span><?= monitoringDashboardEscape($modulePage['label']) ?></span>
              <span><?= $modulePage['count'] === null ? monitoringDashboardEscape($modulePage['detail']) : (int) $modulePage['count'] . ' ' . monitoringDashboardEscape($modulePage['detail']) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
      </article>
    <?php endforeach; ?>
  </section>

  <?php if ($showAggregateMonitoring): ?>
  <div class="dashboard-grid">
    <section class="hub-panel dashboard-chart-panel" aria-labelledby="activity-chart-heading">
      <div class="chart-header-row">
        <div>
          <h2 id="activity-chart-heading">Research Activities by Month</h2>
        </div>
        <div class="panel-link">Academic Year: <?= monitoringDashboardEscape($activeAcademicYear['label']) ?></div>
      </div>
      <div class="chart-legend" aria-label="Chart legend">
        <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#4F8DF7"></span>Seminars</span>
        <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#7C4DFF"></span>Workshops</span>
        <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#19B98A"></span>Publications</span>
        <span class="chart-legend-item"><span class="chart-legend-swatch" style="background:#F5AB18"></span>Copyright Applications</span>
      </div>
      <svg class="month-chart" viewBox="0 0 540 160" role="img" aria-label="Research activities by month">
        <?php
        $chartPxHeight = 160;
        $chartPaddingTop = 12;
        $chartPaddingBottom = 18;
        $chartPaddingLeft = 20;
        $chartPaddingRight = 12;
        $chartInnerWidth = 540 - $chartPaddingLeft - $chartPaddingRight;
        $chartInnerHeight = $chartPxHeight - $chartPaddingTop - $chartPaddingBottom;
        $groupWidth = $chartInnerWidth / count($chartMonthLabels);
        $barWidth = min(8, max(4, $groupWidth / 7));
        $series = [
            'seminars' => ['color' => '#4F8DF7', 'values' => []],
            'workshops' => ['color' => '#7C4DFF', 'values' => []],
            'publications' => ['color' => '#19B98A', 'values' => []],
            'copyrights' => ['color' => '#F5AB18', 'values' => []],
        ];
        foreach ($chartMonthLabels as $label) {
            $series['seminars']['values'][] = 0;
            $series['workshops']['values'][] = 0;
            $series['publications']['values'][] = 0;
            $series['copyrights']['values'][] = 0;
        }
        foreach ($chartData as $monthKey => $monthEntry) {
            $monthIndex = array_search($monthKey, $chartMonthKeys, true);
            if ($monthIndex === false) continue;
            $series['seminars']['values'][$monthIndex] = (int) $monthEntry['seminars'];
            $series['workshops']['values'][$monthIndex] = (int) $monthEntry['workshops'];
            $series['publications']['values'][$monthIndex] = (int) $monthEntry['publications'];
            $series['copyrights']['values'][$monthIndex] = (int) $monthEntry['copyrights'];
        }
        $gridLines = 5;
        for ($line = 0; $line <= $gridLines; $line++) {
            $y = $chartPaddingTop + ($chartInnerHeight / $gridLines) * $line;
            echo '<line x1="' . $chartPaddingLeft . '" y1="' . $y . '" x2="' . (540 - $chartPaddingRight) . '" y2="' . $y . '" stroke="#E5E7EB" stroke-width="1" />';
        }
        foreach ($chartMonthLabels as $index => $monthLabel) {
            $monthX = $chartPaddingLeft + ($index * $groupWidth) + ($groupWidth / 2);
            echo '<text x="' . $monthX . '" y="' . ($chartPxHeight - 4) . '" text-anchor="middle" font-size="9" fill="#64748B">' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '</text>';
            $columnOffset = 0;
            foreach ($series as $datasetKey => $dataset) {
                $value = (int) $dataset['values'][$index];
                $barHeight = $chartInnerHeight * ($value / $chartMaxValue);
                $x = $monthX - ($barWidth * 2) + ($columnOffset * ($barWidth + 4));
                $y = $chartPxHeight - $chartPaddingBottom - $barHeight;
                echo '<rect x="' . $x . '" y="' . $y . '" width="' . $barWidth . '" height="' . $barHeight . '" rx="2" fill="' . $dataset['color'] . '" opacity="0.92" />';
                $columnOffset++;
            }
        }
        ?>
      </svg>
    </section>

    <section class="hub-panel dashboard-recent-panel" aria-labelledby="recent-events-heading">
      <div class="panel-head">
        <div><h2 id="recent-events-heading">Recent Research Activities</h2></div>
        <?php if ($role === 'admin'): ?><a class="panel-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/admin/admin-reports.php#module-portfolio') ?>">View Reports</a><?php endif; ?>
      </div>
      <?php if (!$recentEvents): ?><div class="empty-state">Module activity will appear here after the first activity, publication, or copyright application is recorded.</div>
      <?php else: ?><div class="event-wrap"><table class="event-table"><thead><tr><th>Date</th><th>Activity</th><th>Researcher</th><th>Category</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($recentEvents as $event): $badge = monitoringDashboardStatusBadge((string) $event['status']); ?><tr><td><?= monitoringDashboardEscape(date('M j, Y', strtotime((string) $event['event_at']))) ?></td><td><a class="event-name" href="<?= monitoringDashboardEscape($eventUrl($event)) ?>"><?= monitoringDashboardEscape($event['event_label']) ?></a></td><td><?= monitoringDashboardEscape($event['actor_name']) ?></td><td><?= monitoringDashboardEscape(($event['source_type'] === 'publication' ? 'Publication' : ($event['source_type'] === 'copyright' ? 'Copyright' : 'Research'))) ?></td><td><span class="badge tone-<?= monitoringDashboardEscape($badge['tone']) ?>"><?= monitoringDashboardEscape($badge['label']) ?></span></td><td>⋯</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  </div>
  <?php endif; ?>

  <div class="dashboard-grid dashboard-grid-secondary">
    <section class="hub-panel dashboard-quick-panel" aria-labelledby="quick-actions-heading">
      <div class="panel-head"><div><h2 id="quick-actions-heading">Quick Actions</h2></div></div>
      <div class="quick-actions">
        <?php foreach ($dashboardQuickPages as $quickPage): ?>
          <a class="action-link" href="<?= monitoringDashboardEscape($moduleRoute($quickPage['key'])) ?>"><?= monitoringDashboardEscape($quickPage['label']) ?></a>
        <?php endforeach; ?>
        <?php if (!$dashboardQuickPages): ?><p class="empty-state">No module shortcuts are available for your current permissions.</p><?php endif; ?>
      </div>
    </section>
    <?php if ($role === 'admin'): ?>
      <section class="hub-panel dashboard-researcher-panel" aria-labelledby="researcher-classification-heading">
        <div class="panel-head"><div><h2 id="researcher-classification-heading">Researcher Classification</h2></div><a class="panel-link" href="<?= monitoringDashboardEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">View All</a></div>
        <?php if ($researchers['total'] === 0): ?><div class="empty-state">No active faculty or student researchers were found.</div><?php else: ?><div class="researcher-summary"><div class="researcher-donut" style="--faculty-share:<?= monitoringDashboardEscape(number_format($facultyShare, 1, '.', '')) ?>%"><div><strong><?= $researchers['total'] ?></strong><span>Researchers</span></div></div><div class="researcher-legend"><div class="researcher-legend-row"><span class="researcher-key faculty-key"></span><span>Faculty Researchers</span><strong><?= $researchers['faculty'] ?></strong><small><?= monitoringDashboardEscape(number_format($facultyShare, 1)) ?>%</small></div><div class="researcher-legend-row"><span class="researcher-key student-key"></span><span>Student Researchers</span><strong><?= $researchers['student'] ?></strong><small><?= monitoringDashboardEscape(number_format($studentShare, 1)) ?>%</small></div></div></div><?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</div>

<?php
if ($role === 'admin') renderAdminShellClose();
else renderStaffShellClose();
?>
