<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity-schedule.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$isInvitationRestricted = in_array($role, ['student', 'faculty'], true);
$requestedView = trim((string) ($_GET['view'] ?? ''));
$requestedView = $requestedView === 'incoming' ? 'upcoming' : $requestedView;
$statusInput = trim((string) ($_GET['status'] ?? ''));
$statusFilter = preg_match('/^(upcoming|incoming|ongoing|conducted|cancelled|scheduled|completed)$/D', $statusInput) ? normalizeActivityStatus($statusInput) : '';
$status = $statusFilter;
$isStatusView = in_array($requestedView, ['upcoming', 'ongoing', 'conducted', 'past'], true);
$hasCrossTypeFilter = $isStatusView || $status !== '';
$requestedActivityType = normalizeActivityType(trim((string) ($_GET['type'] ?? '')));
$capacityModulePages = rmsPermissionModules()['research_capacity_building']['pages'];
$modulePageInput = trim((string) ($_GET['module_page'] ?? ''));
$modulePage = array_key_exists($modulePageInput, $capacityModulePages) ? $modulePageInput : '';
$statusPermissionPages = ['upcoming_activities', 'ongoing_activities', 'conducted_activities'];
if ($role === 'research_staff' && $modulePage !== ''
    && !in_array($modulePage, array_merge(['view_activity'], $statusPermissionPages), true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$statusPermissionPage = match (true) {
    in_array($requestedView, ['upcoming'], true), in_array($statusFilter, ['upcoming', 'scheduled'], true) => 'upcoming_activities',
    in_array($requestedView, ['ongoing'], true), $statusFilter === 'ongoing' => 'ongoing_activities',
    in_array($requestedView, ['conducted', 'past'], true), in_array($statusFilter, ['conducted', 'completed'], true) => 'conducted_activities',
    default => '',
};
if ($role === 'research_staff' && $statusPermissionPage !== '' && $modulePage !== ''
    && !in_array($modulePage, [$statusPermissionPage, 'view_activity'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$permissionPages = [$statusPermissionPage !== '' && $modulePage !== 'view_activity' ? $statusPermissionPage : 'view_activity'];
if ($role === 'research_staff' && $requestedActivityType === ''
    && $statusPermissionPage === '' && $modulePage !== 'view_activity') {
    foreach ($statusPermissionPages as $pageKey) {
        if (hasModulePermission($userId, 'research_capacity_building', 'view', $pageKey)) {
            header('Location: ' . SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($pageKey));
            exit;
        }
    }
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
if ($role === 'research_staff' && !in_array($requestedActivityType, ['', 'seminar_workshop', 'training', 'colloquium', 'conference_congress', 'other'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$capacityReturnUrl = in_array($role, ['admin', 'student', 'faculty'], true)
    ? SITE_URL . 'pages/shared/activities.php'
    : ($statusPermissionPage !== ''
        ? SITE_URL . 'pages/shared/activities.php?view=' . rawurlencode($requestedView !== '' ? $requestedView : ($statusPermissionPage === 'upcoming_activities' ? 'upcoming' : ($statusPermissionPage === 'ongoing_activities' ? 'ongoing' : 'conducted'))) . '&module_page=' . rawurlencode($modulePage !== '' ? $modulePage : $statusPermissionPage)
        : SITE_URL . 'pages/staff/staff-dashboard.php');
$canViewActivities = hasRole('admin') || $isInvitationRestricted;
foreach ($permissionPages as $permissionPage) {
    if (hasModulePermission($userId, 'research_capacity_building', 'view', $permissionPage)) {
        $canViewActivities = true;
        break;
    }
}
if (!$canViewActivities) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$canManagePage = static function (string $activityType, string $selectedPage) use ($role, $userId, $statusPermissionPages): bool {
    if ($role === 'admin') {
        return true;
    }
    $pageKey = $selectedPage !== '' ? $selectedPage : 'view_activity';
    if (!in_array($pageKey, array_merge(['view_activity'], $statusPermissionPages), true)) {
        return false;
    }
    return hasModulePermission($userId, 'research_capacity_building', 'manage', $pageKey);
};
$canManage = $canManagePage($requestedActivityType, $modulePage !== '' ? $modulePage : $statusPermissionPage);
$canAddActivity = hasRole('admin');
$addPermissionPages = ['add_activity', 'add_upcoming_activity', 'add_conducted_activity'];
if (!$canAddActivity) {
    foreach ($addPermissionPages as $addPage) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $addPage)) {
            $canAddActivity = true;
            break;
        }
    }
}
$clearFiltersUrl = $role === 'admin' ? SITE_URL . 'pages/shared/activities.php' : $capacityReturnUrl;

function activitiesEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activitiesDateSummary(array $activity): string
{
    if (!empty($activity['is_multi_day']) && !empty($activity['schedule_start_date']) && !empty($activity['schedule_end_date'])) {
        $range = date('M j, Y', strtotime((string) $activity['schedule_start_date']))
            . ' – ' . date('M j, Y', strtotime((string) $activity['schedule_end_date']));
        $sessionCount = (int) ($activity['scheduled_session_count'] ?? 0);
        return $range . ($sessionCount > 0 ? ' · ' . $sessionCount . ' sessions' : '');
    }
    return date('M j, Y', strtotime((string) $activity['activity_date']));
}

function activitiesRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
        $stmt->close();
        throw new RuntimeException('Unable to bind activity query parameters.');
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to execute the activity query.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Unable to read activity query results.');
    }
    return $result;
}

function normalizeActivityStatus(string $status): string
{
    return match (strtolower($status)) {
        'upcoming', 'incoming', 'scheduled' => 'upcoming',
        'ongoing' => 'ongoing',
        'conducted', 'completed' => 'conducted',
        'past' => 'past',
        'cancelled' => 'cancelled',
        default => 'upcoming',
    };
}

function normalizeActivityType(string $type): string
{
    return match (strtolower($type)) {
        'colloquium' => 'colloquium',
        'conference', 'congress', 'conference_congress' => 'conference_congress',
        'seminar', 'forum', 'workshop', 'seminar_workshop' => 'seminar_workshop',
        'training' => 'training',
        default => strtolower($type),
    };
}

function activitiesStatusBadge(string $status): array
{
    return match (normalizeActivityStatus($status)) {
        'upcoming' => ['label' => 'Upcoming', 'tone' => 'info'],
        'ongoing' => ['label' => 'Ongoing', 'tone' => 'warning'],
        'conducted' => ['label' => 'Conducted', 'tone' => 'success'],
        'past' => ['label' => 'Past', 'tone' => 'neutral'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$activitiesAvailable = rmsActivityScheduleTableExists($conn, 'research_activities');
$customActivityTypeAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'custom_activity_type');
$customActivityTypeSelect = $customActivityTypeAvailable
    ? 'ra.custom_activity_type'
    : 'NULL AS custom_activity_type';
$participantsAvailable = rmsActivityScheduleTableExists($conn, 'activity_participants');
$scheduleTablesAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_dates');
$scheduleParticipantsAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_participants');
$invitedActivityIds = $isInvitationRestricted ? rmsActivityScheduleInvitedActivityIds($conn, $userId) : [];
$activityVisibilityCondition = '';
if ($isInvitationRestricted) {
    $safeInvitedActivityIds = array_map('intval', $invitedActivityIds);
    $activityVisibilityCondition = ' AND ra.activity_id IN ('
        . ($safeInvitedActivityIds ? implode(',', $safeInvitedActivityIds) : '0')
        . ')';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $postCanManage = hasRole('admin');
    if (!$postCanManage && $activityId) {
        $activityTypeStmt = $conn->prepare('SELECT activity_type FROM research_activities WHERE activity_id = ? LIMIT 1');
        if ($activityTypeStmt) {
            $activityTypeStmt->bind_param('i', $activityId);
            $activityTypeStmt->execute();
            $activityTypeRow = $activityTypeStmt->get_result()->fetch_assoc();
            $activityTypeStmt->close();
            $postCanManage = $activityTypeRow
                ? $canManagePage((string) $activityTypeRow['activity_type'], $modulePage !== '' ? $modulePage : $statusPermissionPage)
                : false;
        }
    }
    if (!$postCanManage) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Your form expired. Please try again.'];
        header('Location: ' . $capacityReturnUrl);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $targetStatuses = ['complete' => 'conducted', 'cancel' => 'cancelled'];
    if (!$activitiesAvailable || !$activityId || !isset($targetStatuses[$action])) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The requested activity action is not available.'];
    } elseif ($action === 'cancel' && $scheduleTablesAvailable) {
        try {
            rmsActivityScheduleCancelActivity($conn, (int) $activityId);
            logActivity("Marked research activity #{$activityId} as cancelled", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity and scheduled dates cancelled; recipients were notified.'];
        } catch (Throwable $exception) {
            error_log('Research activity cancellation from directory failed: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The activity could not be cancelled and its recipients were not notified.'];
        }
    } else {
        $targetStatus = $targetStatuses[$action];
        $statusCondition = $action === 'complete'
            ? "status = 'ongoing'"
            : "status IN ('scheduled', 'upcoming', 'ongoing', 'completed', 'conducted')";
        $stmt = $conn->prepare("UPDATE research_activities SET status = ? WHERE activity_id = ? AND {$statusCondition}");
        if (!$stmt) {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The activity status could not be updated.'];
        } else {
        $stmt->bind_param('si', $targetStatus, $activityId);
        $updated = $stmt->execute();
        if ($updated && $stmt->affected_rows === 1) {
            $verb = $targetStatus === 'conducted' ? 'conducted' : 'cancelled';
            logActivity("Marked research activity #{$activityId} as {$verb}", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => "Activity marked {$verb}."];
        } else {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Only upcoming or ongoing activities can be marked as conducted or cancelled.'];
        }
        $stmt->close();
        }
    }
    header('Location: ' . $capacityReturnUrl);
    exit;
}

$flash = $_SESSION['activities_flash'] ?? null;
unset($_SESSION['activities_flash']);

$typeOptions = ['seminar_workshop' => 'Seminars / Workshops', 'training' => 'Trainings', 'colloquium' => 'Colloquia', 'conference_congress' => 'Conference / Congress', 'other' => 'Other'];
$filterTypeOptions = $typeOptions;
$statusOptions = ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'conducted' => 'Conducted', 'cancelled' => 'Cancelled'];
$query = trim((string) ($_GET['q'] ?? ''));
$query = strlen($query) > 150 ? substr($query, 0, 150) : $query;
$typeInput = normalizeActivityType(trim((string) ($_GET['type'] ?? '')));
$type = in_array($typeInput, ['seminar_workshop', 'training', 'colloquium', 'conference_congress', 'other'], true) ? $typeInput : '';
$createPermissionPage = 'add_activity';
if (!hasRole('admin') && !hasModulePermission($userId, 'research_capacity_building', 'manage', 'add_activity')) {
    $preferredAddPage = match ($statusPermissionPage) {
        'upcoming_activities' => 'add_upcoming_activity',
        'conducted_activities' => 'add_conducted_activity',
        default => '',
    };
    $createPermissionPage = $preferredAddPage !== ''
        && hasModulePermission($userId, 'research_capacity_building', 'manage', $preferredAddPage)
        ? $preferredAddPage
        : (hasModulePermission($userId, 'research_capacity_building', 'manage', 'add_upcoming_activity')
            ? 'add_upcoming_activity'
            : 'add_conducted_activity');
}
$createActivityUrl = SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($createPermissionPage);
if (in_array($requestedView, ['upcoming', 'ongoing', 'conducted'], true)) {
    $view = $requestedView;
    if ($statusFilter === '') {
        $status = normalizeActivityStatus($requestedView);
    }
} elseif ($requestedView === 'past') {
    $view = 'past';
    $status = '';
} elseif (in_array($status, ['upcoming', 'ongoing', 'conducted'], true)) {
    $view = $status;
} else {
    $view = 'overview';
    if ($type !== '') {
        $view = $type;
    }
}
$filtersActive = $query !== '' || $type !== '' || $status !== '';

// Preset views are resolved only from validated enum values. Additional filters
// remain in the query string and continue to narrow the directory in that view.


$typeLabels = [
    'seminar_workshop' => 'Seminars / Workshops',
    'training' => 'Trainings',
    'colloquium' => 'Colloquia',
    'conference_congress' => 'Conference / Congress',
    'other' => 'Other',
];
$typeSingularLabels = [
    'seminar_workshop' => 'Seminar / Workshop',
    'training' => 'Training',
    'colloquium' => 'Colloquium',
    'conference_congress' => 'Conference / Congress',
];
$createActivityLabel = $view !== 'overview' && isset($typeSingularLabels[$view])
    ? '+ Add ' . $typeSingularLabels[$view]
    : '+ Add Activity';
$stats = ['participants' => 0];
$upcoming = [];
$attendanceSummary = [];
$activities = [];
$activityPageSize = 5;
$activityTotal = 0;
$activityPage = 1;
$activityPageCount = 1;
$activityOffset = 0;
$currentSessionCondition = "EXISTS (
    SELECT 1 FROM activity_schedule_dates clock_date
    WHERE clock_date.activity_id = ra.activity_id AND clock_date.status = 'scheduled'
      AND TIMESTAMP(clock_date.schedule_date, COALESCE(clock_date.start_time, '00:00:00')) <= CURRENT_TIMESTAMP
      AND TIMESTAMP(clock_date.schedule_date, COALESCE(clock_date.end_time, '23:59:59')) >= CURRENT_TIMESTAMP
)";
$pastActivityCondition = $scheduleTablesAvailable
    ? "ra.status NOT IN ('conducted', 'completed', 'cancelled')
       AND EXISTS (
           SELECT 1 FROM activity_schedule_dates past_date
           WHERE past_date.activity_id = ra.activity_id AND past_date.status = 'scheduled'
       )
       AND NOT EXISTS (
           SELECT 1 FROM activity_schedule_dates pending_date
           WHERE pending_date.activity_id = ra.activity_id AND pending_date.status = 'scheduled'
             AND TIMESTAMP(pending_date.schedule_date, COALESCE(pending_date.end_time, '23:59:59')) >= CURRENT_TIMESTAMP
       )"
    : "ra.status NOT IN ('conducted', 'completed', 'cancelled') AND ra.activity_date < CURDATE()";
if ($activitiesAvailable) {
    if ($view === 'overview') {
        $stmt = $conn->prepare("SELECT COUNT(CASE WHEN activity_type = 'seminar_workshop' THEN 1 END) AS seminar_workshop,
                                       COUNT(CASE WHEN activity_type = 'training' THEN 1 END) AS training,
                                       COUNT(CASE WHEN activity_type = 'colloquium' THEN 1 END) AS colloquium,
                                       COUNT(CASE WHEN activity_type = 'conference_congress' THEN 1 END) AS conference_congress,
                                       COUNT(CASE WHEN activity_type = 'other' THEN 1 END) AS other
                                FROM research_activities ra WHERE 1 = 1{$activityVisibilityCondition}");
        $row = activitiesRun($stmt)->fetch_assoc() ?: [];
        foreach ($typeLabels as $activityType => $label) {
            $stats[$activityType] = (int) ($row[$activityType] ?? 0);
        }
        $stmt->close();
        if ($participantsAvailable) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS participants FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE 1 = 1' . $activityVisibilityCondition);
            $stats['participants'] = (int) (activitiesRun($stmt)->fetch_assoc()['participants'] ?? 0);
            $stmt->close();
        }
        $upcomingSql = $scheduleTablesAvailable
            ? "SELECT ra.activity_id, d.schedule_date AS activity_date, d.start_time AS activity_time, ra.title,
                      COALESCE(d.venue_override, ra.venue) AS venue
               FROM research_activities ra
               INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
               WHERE ra.status IN ('scheduled', 'upcoming', 'ongoing')
                 AND (d.schedule_date > CURDATE() OR (d.schedule_date = CURDATE() AND (d.start_time IS NULL OR d.start_time >= CURTIME())))
                 {$activityVisibilityCondition}
               ORDER BY d.schedule_date ASC, d.start_time ASC, ra.activity_id ASC LIMIT 5"
            : "SELECT ra.activity_id, ra.activity_date, ra.activity_time, ra.title, ra.venue FROM research_activities ra WHERE ra.status IN ('scheduled', 'upcoming', 'ongoing') AND ra.activity_date >= CURDATE(){$activityVisibilityCondition} ORDER BY ra.activity_date ASC, ra.activity_time ASC, ra.activity_id ASC LIMIT 5";
        $stmt = $conn->prepare($upcomingSql);
        $result = activitiesRun($stmt);
        while ($row = $result->fetch_assoc()) $upcoming[] = $row;
        $stmt->close();
    } elseif (in_array($view, ['upcoming', 'ongoing'], true)) {
        $statusCondition = $view === 'upcoming'
            ? "IN ('scheduled', 'upcoming')"
            : "= 'ongoing'";
        $activityTypeCondition = $type !== '' ? ' AND ra.activity_type = ?' : '';
        if ($scheduleTablesAvailable && $view === 'ongoing') {
            $upcomingSql = "SELECT ra.activity_id, d.schedule_date AS activity_date, d.start_time AS activity_time, ra.title,
                                   COALESCE(d.venue_override, ra.venue) AS venue
                            FROM research_activities ra
                            INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
                            WHERE ra.status NOT IN ('conducted', 'completed', 'cancelled')
                              AND {$currentSessionCondition}{$activityTypeCondition}{$activityVisibilityCondition}
                            ORDER BY d.schedule_date ASC, d.start_time ASC, ra.activity_id ASC LIMIT 5";
        } elseif ($scheduleTablesAvailable) {
            $upcomingSql = "SELECT ra.activity_id, d.schedule_date AS activity_date, d.start_time AS activity_time, ra.title,
                                   COALESCE(d.venue_override, ra.venue) AS venue
                            FROM research_activities ra
                            INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
                            WHERE ra.status {$statusCondition}{$activityTypeCondition}{$activityVisibilityCondition}
                            ORDER BY d.schedule_date ASC, d.start_time ASC, ra.activity_id ASC LIMIT 5";
        } else {
            $upcomingSql = "SELECT ra.activity_id, ra.activity_date, ra.activity_time, ra.title, ra.venue
                            FROM research_activities ra
                            WHERE ra.status {$statusCondition}{$activityTypeCondition}{$activityVisibilityCondition}
                            ORDER BY ra.activity_date ASC, ra.activity_time ASC, ra.activity_id ASC LIMIT 5";
        }
        $stmt = $conn->prepare($upcomingSql);
        $typeParams = $type !== '' ? [$type] : [];
        $typeParamsType = $type !== '' ? 's' : '';
        $result = activitiesRun($stmt, $typeParamsType, $typeParams);
        while ($row = $result->fetch_assoc()) $upcoming[] = $row;
        $stmt->close();

        $activityCountSql = $view === 'ongoing' && $scheduleTablesAvailable
            ? "SELECT COUNT(*) AS activity_count FROM research_activities ra
               WHERE ra.status NOT IN ('conducted', 'completed', 'cancelled')
                 AND {$currentSessionCondition}{$activityTypeCondition}{$activityVisibilityCondition}"
            : "SELECT COUNT(*) AS activity_count FROM research_activities ra WHERE ra.status {$statusCondition}{$activityTypeCondition}{$activityVisibilityCondition}";
        $activityCountStmt = $conn->prepare($activityCountSql);
        $activityCountRow = activitiesRun($activityCountStmt, $typeParamsType, $typeParams)->fetch_assoc() ?: [];
        $activityCountStmt->close();
        $stats[$view === 'upcoming' ? 'scheduled' : 'ongoing'] = (int) ($activityCountRow['activity_count'] ?? 0);

        if ($scheduleTablesAvailable && $scheduleParticipantsAvailable) {
            $participantCountStmt = $conn->prepare(
                "SELECT COUNT(DISTINCT CONCAT(ra.activity_id, ':', COALESCE(
                    CONCAT('user:', sp.user_id),
                    CONCAT('name:', LOWER(NULLIF(TRIM(sp.participant_name), ''))),
                    CONCAT('participant:', sp.participant_id)
                ))) AS participants
                 FROM research_activities ra
                 INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
                 INNER JOIN activity_schedule_participants sp ON sp.schedule_id = d.schedule_id AND sp.is_invited = 1
                 WHERE ra.status {$statusCondition}{$activityTypeCondition}{$activityVisibilityCondition}"
            );
            $participantRow = activitiesRun($participantCountStmt, $typeParamsType, $typeParams)->fetch_assoc() ?: [];
            $participantCountStmt->close();
            $stats['participants'] = (int) ($participantRow['participants'] ?? 0);
        } elseif ($participantsAvailable) {
            $participantCountStmt = $conn->prepare(
                "SELECT COUNT(DISTINCT CONCAT(ra.activity_id, ':', COALESCE(
                    CONCAT('user:', ap.user_id),
                    CONCAT('name:', LOWER(NULLIF(TRIM(ap.participant_name), ''))),
                    CONCAT('participant:', ap.participant_id)
                ))) AS participants
                 FROM research_activities ra
                 INNER JOIN activity_participants ap ON ap.activity_id = ra.activity_id
                 WHERE ra.status {$statusCondition}{$activityTypeCondition}{$activityVisibilityCondition}"
            );
            $participantRow = activitiesRun($participantCountStmt, $typeParamsType, $typeParams)->fetch_assoc() ?: [];
            $participantCountStmt->close();
            $stats['participants'] = (int) ($participantRow['participants'] ?? 0);
        }
    } elseif (isset($typeOptions[$view])) {
        $viewType = $view;
        $stmt = $conn->prepare("SELECT COUNT(CASE WHEN ra.status IN ('completed', 'conducted') THEN 1 END) AS held, COUNT(CASE WHEN ra.status IN ('scheduled', 'upcoming') THEN 1 END) AS scheduled FROM research_activities ra WHERE ra.activity_type = ?{$activityVisibilityCondition}");
        $stmt->bind_param('s', $viewType);
        $row = activitiesRun($stmt)->fetch_assoc() ?: [];
        $stats['held'] = (int) ($row['held'] ?? 0);
        $stats['scheduled'] = (int) ($row['scheduled'] ?? 0);
        $stmt->close();
        if ($participantsAvailable) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS participants FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE ra.activity_type = ?' . $activityVisibilityCondition);
            $stmt->bind_param('s', $viewType);
            $stats['participants'] = (int) (activitiesRun($stmt)->fetch_assoc()['participants'] ?? 0);
            $stmt->close();
        }
        $upcomingSql = $scheduleTablesAvailable
            ? "SELECT ra.activity_id, d.schedule_date AS activity_date, d.start_time AS activity_time, ra.title,
                      COALESCE(d.venue_override, ra.venue) AS venue
               FROM research_activities ra
               INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
               WHERE ra.activity_type = ? AND ra.status IN ('scheduled', 'upcoming', 'ongoing'){$activityVisibilityCondition}
                 AND (d.schedule_date > CURDATE() OR (d.schedule_date = CURDATE() AND (d.start_time IS NULL OR d.start_time >= CURTIME())))
               ORDER BY d.schedule_date ASC, d.start_time ASC, ra.activity_id ASC LIMIT 5"
            : "SELECT ra.activity_id, ra.activity_date, ra.activity_time, ra.title, ra.venue FROM research_activities ra WHERE ra.activity_type = ? AND ra.status IN ('scheduled', 'upcoming', 'ongoing') AND ra.activity_date >= CURDATE(){$activityVisibilityCondition} ORDER BY ra.activity_date ASC, ra.activity_time ASC, ra.activity_id ASC LIMIT 5";
        $stmt = $conn->prepare($upcomingSql);
        $stmt->bind_param('s', $viewType);
        $result = activitiesRun($stmt);
        while ($row = $result->fetch_assoc()) $upcoming[] = $row;
        $stmt->close();
    } elseif ($view === 'past') {
        $activityTypeCondition = $type !== '' ? ' AND ra.activity_type = ?' : '';
        $typeParams = $type !== '' ? [$type] : [];
        $typeParamsType = $type !== '' ? 's' : '';
        $pastCountStmt = $conn->prepare("SELECT COUNT(*) AS past_count FROM research_activities ra WHERE {$pastActivityCondition}{$activityTypeCondition}{$activityVisibilityCondition}");
        $stats['past'] = (int) (activitiesRun($pastCountStmt, $typeParamsType, $typeParams)->fetch_assoc()['past_count'] ?? 0);
        $pastCountStmt->close();
    } elseif ($view === 'conducted') {
        $activityTypeCondition = $type !== '' ? ' AND ra.activity_type = ?' : '';
        $typeParams = $type !== '' ? [$type] : [];
        $typeParamsType = $type !== '' ? 's' : '';
        $stmt = $conn->prepare("SELECT COUNT(*) AS completed FROM research_activities ra WHERE ra.status IN ('completed', 'conducted'){$activityTypeCondition}{$activityVisibilityCondition}");
        $stats['completed'] = (int) (activitiesRun($stmt, $typeParamsType, $typeParams)->fetch_assoc()['completed'] ?? 0);
        $stmt->close();
        $stats['attendance_rate'] = 0.0;
        $stats['unique_participants'] = 0;
        if ($participantsAvailable) {
            $stmt = $conn->prepare("SELECT COUNT(ap.participant_id) AS participant_count, COALESCE(SUM(ap.attended = 1), 0) AS attended_count, COUNT(DISTINCT COALESCE(CONCAT('user:', ap.user_id), CONCAT('name:', LOWER(NULLIF(TRIM(ap.participant_name), ''))))) AS unique_participants FROM research_activities ra LEFT JOIN activity_participants ap ON ap.activity_id = ra.activity_id WHERE ra.status IN ('completed', 'conducted'){$activityTypeCondition}{$activityVisibilityCondition}");
            $row = activitiesRun($stmt, $typeParamsType, $typeParams)->fetch_assoc() ?: [];
            $participantCount = (int) ($row['participant_count'] ?? 0);
            $attendedCount = (int) ($row['attended_count'] ?? 0);
            $stats['attendance_rate'] = $participantCount > 0 ? ($attendedCount / $participantCount) * 100 : 0.0;
            $stats['unique_participants'] = (int) ($row['unique_participants'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("SELECT ra.activity_id, ra.activity_date, ra.title, COUNT(ap.participant_id) AS participants, COALESCE(SUM(ap.attended = 1), 0) AS attended FROM research_activities ra LEFT JOIN activity_participants ap ON ap.activity_id = ra.activity_id WHERE ra.status IN ('completed', 'conducted'){$activityTypeCondition}{$activityVisibilityCondition} GROUP BY ra.activity_id, ra.activity_date, ra.title ORDER BY ra.activity_date DESC, ra.activity_id DESC");
        } else {
            $stmt = $conn->prepare("SELECT ra.activity_id, ra.activity_date, ra.title, 0 AS participants, 0 AS attended FROM research_activities ra WHERE ra.status IN ('completed', 'conducted'){$activityTypeCondition}{$activityVisibilityCondition} ORDER BY ra.activity_date DESC, ra.activity_id DESC");
        }
        $result = activitiesRun($stmt, $typeParamsType, $typeParams);
        while ($row = $result->fetch_assoc()) $attendanceSummary[] = $row;
        $stmt->close();
    }

    if ($scheduleTablesAvailable) {
        $sql = "SELECT ra.activity_id, ra.activity_date, ra.is_multi_day, ra.schedule_start_date,
                       ra.schedule_end_date, COUNT(d.schedule_id) AS scheduled_session_count,
                       ra.title, ra.activity_type, ' . $customActivityTypeSelect . ', ra.venue, ra.organizer, ra.status
                FROM research_activities ra
                LEFT JOIN activity_schedule_dates d
                  ON d.activity_id = ra.activity_id AND d.status = 'scheduled'
                ";
        $activityPrefix = 'ra.';
    } else {
        $sql = 'SELECT ra.activity_id, ra.activity_date, ra.activity_time, ra.title, ra.activity_type, ' . $customActivityTypeSelect . ', ra.venue, ra.organizer, ra.status FROM research_activities ra';
        $activityPrefix = 'ra.';
    }
    $whereSql = ' WHERE 1 = 1';
    $whereSql .= $activityVisibilityCondition;
    $params = [];
    $types = '';
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = '%' . $escaped . '%';
        $whereSql .= " AND ({$activityPrefix}title LIKE ? ESCAPE '\\\\' OR {$activityPrefix}venue LIKE ? ESCAPE '\\\\' OR {$activityPrefix}speaker LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($type !== '') {
        $whereSql .= ' AND ' . $activityPrefix . 'activity_type = ?';
        $params[] = $type;
        $types .= 's';
    }
    if ($view === 'past') {
        $whereSql .= ' AND ' . $pastActivityCondition;
    } elseif ($status !== '') {
        if ($scheduleTablesAvailable && in_array($status, ['upcoming', 'ongoing'], true)) {
            $whereSql .= " AND ra.status NOT IN ('conducted', 'completed', 'cancelled')";
            if ($status === 'ongoing') {
                $whereSql .= ' AND ' . $currentSessionCondition;
            } else {
                $whereSql .= " AND EXISTS (
                    SELECT 1 FROM activity_schedule_dates future_date
                    WHERE future_date.activity_id = ra.activity_id AND future_date.status = 'scheduled'
                      AND TIMESTAMP(future_date.schedule_date, COALESCE(future_date.start_time, '00:00:00')) > CURRENT_TIMESTAMP
                ) AND NOT " . $currentSessionCondition;
            }
        } else {
            match ($status) {
                'upcoming' => $whereSql .= " AND {$activityPrefix}status IN ('scheduled', 'upcoming')",
                'ongoing' => $whereSql .= " AND {$activityPrefix}status IN ('ongoing')",
                'conducted' => $whereSql .= " AND {$activityPrefix}status IN ('completed', 'conducted')",
                'cancelled' => $whereSql .= " AND {$activityPrefix}status IN ('cancelled')",
                default => $whereSql .= ' AND 1 = 0',
            };
        }
    }
    $countFrom = 'research_activities ra';
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM ' . $countFrom . $whereSql);
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare the activity count query.');
    }
    $countResult = activitiesRun($countStmt, $types, $params);
    $activityTotal = (int) ($countResult->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
    $activityPageCount = max(1, (int) ceil($activityTotal / $activityPageSize));
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $activityPage = min($requestedPage === false ? 1 : (int) $requestedPage, $activityPageCount);
    $activityOffset = ($activityPage - 1) * $activityPageSize;
    $sql .= $whereSql;
    if ($scheduleTablesAvailable) {
        $sql .= ' GROUP BY ra.activity_id, ra.activity_date, ra.is_multi_day, ra.schedule_start_date, ra.schedule_end_date, ra.title, ra.activity_type'
            . ($customActivityTypeAvailable ? ', ra.custom_activity_type' : '')
            . ', ra.venue, ra.organizer, ra.status';
        $sql .= ' ORDER BY ra.activity_date DESC, ra.activity_id DESC LIMIT ? OFFSET ?';
    } else {
        $sql .= ' ORDER BY activity_date DESC, activity_id DESC LIMIT ? OFFSET ?';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the activity list query.');
    }
    $result = activitiesRun($stmt, $types . 'ii', [...$params, $activityPageSize, $activityOffset]);
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    $stmt->close();

    $activityIds = array_values(array_unique(array_map(
        static fn (array $activity): int => (int) $activity['activity_id'],
        $activities
    )));
    $clockSessionsByActivity = [];
    if ($scheduleTablesAvailable && $activityIds) {
        $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
        $clockStmt = $conn->prepare(
            'SELECT activity_id, schedule_date, start_time, end_time, status,
                    UNIX_TIMESTAMP(TIMESTAMP(schedule_date, COALESCE(start_time, \'00:00:00\'))) AS clock_start_timestamp,
                    UNIX_TIMESTAMP(TIMESTAMP(schedule_date, COALESCE(end_time, \'23:59:59\'))) AS clock_end_timestamp
             FROM activity_schedule_dates
             WHERE activity_id IN (' . $placeholders . ')
             ORDER BY schedule_date ASC, schedule_id ASC'
        );
        if (!$clockStmt) {
            throw new RuntimeException('Unable to prepare activity clock status data.');
        }
        $clockResult = activitiesRun($clockStmt, str_repeat('i', count($activityIds)), $activityIds);
        while ($clockRow = $clockResult->fetch_assoc()) {
            $clockSessionsByActivity[(int) $clockRow['activity_id']][] = [
                'status' => (string) $clockRow['status'],
                'start' => (int) ($clockRow['clock_start_timestamp'] ?? 0) * 1000,
                'end' => (int) ($clockRow['clock_end_timestamp'] ?? 0) * 1000,
            ];
        }
        $clockStmt->close();
    }
    foreach ($activities as &$activity) {
        if (!$scheduleTablesAvailable) {
            $startAt = strtotime((string) $activity['activity_date'] . ' ' . ((string) ($activity['activity_time'] ?? '') ?: '00:00:00'));
            $endAt = strtotime((string) $activity['activity_date'] . ' 23:59:59');
            $clockSessionsByActivity[(int) $activity['activity_id']] = [[
                'status' => 'scheduled',
                'start' => $startAt === false ? 0 : $startAt * 1000,
                'end' => $endAt === false ? 0 : $endAt * 1000,
            ]];
        }
        $activity['clock_sessions'] = $clockSessionsByActivity[(int) $activity['activity_id']] ?? [];
        $activity['clock_status'] = rmsActivityScheduleClockStatus(
            array_map(
                static fn (array $session): array => [
                    'status' => (string) ($session['status'] ?? 'scheduled'),
                    'clock_start_timestamp' => (int) ($session['start'] ?? 0) > 0 ? (int) floor((int) $session['start'] / 1000) : 0,
                    'clock_end_timestamp' => (int) ($session['end'] ?? 0) > 0 ? (int) floor((int) $session['end'] / 1000) : 0,
                ],
                $activity['clock_sessions']
            ),
            (string) $activity['status']
        );
    }
    unset($activity);
}

$paginationParams = [];
if ($view !== 'overview') {
    $paginationParams['view'] = $view;
}
if (in_array($modulePage, ['view_activity', 'upcoming_activities', 'ongoing_activities', 'conducted_activities'], true)) {
    $paginationParams['module_page'] = $modulePage;
}
if ($query !== '') {
    $paginationParams['q'] = $query;
}
if ($type !== '') {
    $paginationParams['type'] = $type;
}
if ($statusFilter !== '') {
    $paginationParams['status'] = $statusFilter;
}
$activityPaginationUrl = static fn (int $page): string => SITE_URL . 'pages/shared/activities.php?' . http_build_query($paginationParams + ['page' => $page]);
$activityRangeStart = $activityTotal > 0 ? $activityOffset + 1 : 0;
$activityRangeEnd = min($activityOffset + $activityPageSize, $activityTotal);

$viewConfig = [
    'overview' => [
        'title' => 'Research Activities',
        'kicker' => 'Research engagement',
        'subtitle' => 'Institutional research activities and events',
        'description' => 'Review upcoming, ongoing, and conducted research events, their organizers, and participation records.',
        'stats' => array_map(static function (string $activityType, string $label) use ($stats): array {
            return ['value' => $stats[$activityType] ?? 0, 'label' => $label];
        }, array_keys($typeLabels), array_values($typeLabels)),
        'panel' => 'upcoming',
        'panel_title' => 'Upcoming activities',
        'shell_page' => SITE_URL . 'pages/shared/activities.php',
        'directory_title' => 'Activity directory',
    ],
    'upcoming' => [
        'title' => 'Upcoming Activities',
        'kicker' => 'Planned activities',
        'subtitle' => 'Activities scheduled for future delivery',
        'description' => 'Review upcoming research capacity-building activities that have not started yet.',
        'stats' => [
            ['value' => $activitiesAvailable ? ($stats['scheduled'] ?? 0) : '—', 'label' => 'Upcoming activities'],
            ['value' => $activitiesAvailable && ($participantsAvailable || ($scheduleTablesAvailable && $scheduleParticipantsAvailable)) ? ($stats['participants'] ?? 0) : '—', 'label' => 'Planned participants'],
        ],
        'panel' => 'upcoming',
        'panel_title' => 'Upcoming activity schedule',
        'shell_page' => SITE_URL . 'pages/shared/activities.php?view=upcoming',
        'directory_title' => 'Upcoming activity list',
    ],
    'ongoing' => [
        'title' => 'Ongoing Activities',
        'kicker' => 'Active activities',
        'subtitle' => 'Activities already in progress',
        'description' => 'Track research activities that have started and remain active.',
        'stats' => [
            ['value' => $activitiesAvailable ? ($stats['ongoing'] ?? 0) : '—', 'label' => 'Ongoing activities'],
            ['value' => $activitiesAvailable && ($participantsAvailable || ($scheduleTablesAvailable && $scheduleParticipantsAvailable)) ? ($stats['participants'] ?? 0) : '—', 'label' => 'Current participants'],
        ],
        'panel' => 'upcoming',
        'panel_title' => 'Ongoing activity schedule',
        'shell_page' => SITE_URL . 'pages/shared/activities.php?view=ongoing',
        'directory_title' => 'Ongoing activity list',
    ],
    'conducted' => [
        'title' => 'Conducted Activity Records',
        'kicker' => 'Attendance records',
        'subtitle' => 'Completed activities and attendance outcomes',
        'description' => 'Review conducted research activities and their recorded participation.',
        'stats' => [
            ['value' => $stats['completed'] ?? 0, 'label' => 'Conducted activities'],
            ['value' => (float) ($stats['attendance_rate'] ?? 0) === 0.0 ? '0%' : number_format((float) $stats['attendance_rate'], 1) . '%', 'label' => 'Attendance rate'],
            ['value' => $stats['unique_participants'] ?? 0, 'label' => 'Unique participants'],
        ],
        'panel' => 'attendance',
        'panel_title' => 'Attendance summary',
        'shell_page' => SITE_URL . 'pages/shared/activities.php?view=conducted',
        'directory_title' => 'Conducted activity records',
    ],
    'past' => [
        'title' => 'Past Activities',
        'kicker' => 'Ended sessions',
        'subtitle' => 'Activities whose scheduled sessions have ended',
        'description' => 'Review ended activities that have not yet been marked conducted.',
        'stats' => [
            ['value' => $activitiesAvailable ? ($stats['past'] ?? 0) : '—', 'label' => 'Past activities'],
        ],
        'panel' => 'none',
        'shell_page' => SITE_URL . 'pages/shared/activities.php?view=past&module_page=conducted_activities',
        'directory_title' => 'Past activities not yet marked conducted',
    ],
];
foreach ($typeOptions as $activityType => $singularLabel) {
    $pluralLabel = $typeLabels[$activityType];
    $viewConfig[$activityType] = [
        'title' => 'Research ' . $pluralLabel,
        'kicker' => $singularLabel . ' program',
        'subtitle' => $singularLabel . ' program of the research office',
        'description' => 'Track completed and scheduled ' . strtolower($pluralLabel) . ', participation, and upcoming sessions.',
        'stats' => [
            ['value' => $stats['held'] ?? 0, 'label' => $pluralLabel . ' held'],
            ['value' => $stats['scheduled'] ?? 0, 'label' => $pluralLabel . ' scheduled'],
            ['value' => $stats['participants'], 'label' => rtrim($pluralLabel, 's') . ' participants'],
        ],
        'panel' => 'upcoming',
        'panel_title' => 'Upcoming ' . strtolower($pluralLabel),
        'shell_page' => SITE_URL . 'pages/shared/activities.php?type=' . rawurlencode($activityType),
        'directory_title' => $singularLabel . ' directory',
    ];
}
$pageConfig = $viewConfig[$view];
$activityLabel = match ($view) {
    'overview' => 'activities',
    'upcoming' => 'upcoming activities',
    'ongoing' => 'ongoing activities',
    'past' => 'past activities',
    default => strtolower($typeLabels[$view] ?? 'activities'),
};
$activityPanelDescription = match ($view) {
    'upcoming' => 'The next five upcoming activities.',
    'ongoing' => 'Ongoing activities currently in progress.',
    'past' => 'Sessions have ended; records may still need to be marked conducted.',
    default => 'The next five scheduled ' . $activityLabel . '.',
};
$emptyActivityHeading = in_array($view, ['upcoming', 'ongoing', 'past'], true)
    ? 'No ' . $activityLabel
    : 'No upcoming ' . $activityLabel;
$emptyActivityDescription = match ($view) {
    'ongoing' => 'Activities in progress will appear here when recorded.',
    'past' => 'Ended activities that still need a conducted record will appear here.',
    default => 'Scheduled events will appear here when dates are added.',
};

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
if ($shell === 'admin') {
    $originalGet = $_GET;
    if ($view === 'overview') {
        unset($_GET['type'], $_GET['status']);
    }
    renderAdminShell($user, $pageConfig['shell_page'], $pageConfig['title'], $pageConfig['subtitle']);
    $_GET = $originalGet;
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'activities.php', $pageConfig['title'], $pageConfig['subtitle']);
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'activities.php', $pageConfig['title'], $pageConfig['subtitle']);
} else {
    renderStudentShell($user, 'activities.php', $pageConfig['title'], $pageConfig['subtitle']);
}
?>

<style>
.module-hub {
  --activity-accent: #0d9488;
  --activity-accent-dark: #087f75;
  --activity-accent-soft: #eaf7f5;
  --activity-ink: #172033;
  --activity-copy: #64748b;
  --activity-line: #e2e8f0;
  width: 100%;
  max-width: 1380px;
  margin: 0 auto;
  color: var(--activity-ink);
}
.module-intro {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 32px;
  overflow: hidden;
  margin: 0 0 20px;
  padding: clamp(24px, 3vw, 36px);
  border: 1px solid rgba(13, 148, 136, .14);
  border-left: 4px solid var(--activity-accent);
  border-radius: 14px;
  background:
    radial-gradient(circle at 88% 14%, rgba(13, 148, 136, .1), transparent 18rem),
    linear-gradient(115deg, #edf8f6, #fbfdfd 74%);
}
.module-intro::after {
  position: absolute;
  right: 22%;
  bottom: -110px;
  width: 230px;
  height: 230px;
  border: 1px solid rgba(13, 148, 136, .1);
  border-radius: 50%;
  content: "";
  pointer-events: none;
}
.module-intro > div { position: relative; z-index: 1; }
.module-intro-actions { position: relative; z-index: 1; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
.module-kicker {
  margin: 0 0 8px;
  color: var(--activity-accent-dark);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .11em;
  text-transform: uppercase;
}
.module-intro h1 {
  margin: 0;
  font-size: clamp(27px, 3vw, 38px);
  font-weight: 750;
  line-height: 1.1;
  letter-spacing: -.04em;
  text-wrap: balance;
}
.module-intro p {
  max-width: 66ch;
  margin: 10px 0 0;
  color: #526176;
  font-size: 13px;
  line-height: 1.65;
  text-wrap: pretty;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr));
  gap: 10px;
  margin-bottom: 18px;
}
.module-hub.centered-stats-view .stat-grid {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 260px));
  justify-content: center;
}
.module-hub.centered-stats-view.five-stats-view .stat-grid {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}
.module-hub.centered-stats-view .stat-card { text-align: center; }
.stat-card {
  position: relative;
  min-width: 0;
  overflow: hidden;
  padding: 17px 18px 16px;
  border: 1px solid var(--activity-line);
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 2px 7px rgba(29, 54, 77, .025);
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}
.stat-card::before {
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  background: var(--activity-accent);
  opacity: .38;
  content: "";
}
.stat-card:hover {
  transform: translateY(-2px);
  border-color: rgba(13, 148, 136, .3);
  box-shadow: 0 8px 20px rgba(15, 118, 110, .08);
}
.stat-card strong {
  display: block;
  color: var(--activity-ink);
  font-size: clamp(23px, 2.2vw, 30px);
  font-variant-numeric: tabular-nums;
  line-height: 1;
  letter-spacing: -.04em;
}
.stat-card span {
  display: block;
  margin-top: 8px;
  color: var(--activity-copy);
  font-size: 11px;
  font-weight: 650;
  line-height: 1.35;
}
.module-hub.upcoming-view .stat-card { text-align: center; }
.hub-panel {
  margin-bottom: 18px;
  overflow: hidden;
  border: 1px solid var(--activity-line);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 4px 16px rgba(29, 54, 77, .035);
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 21px;
  border-bottom: 1px solid #e9eef3;
}
.panel-head h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 750;
  letter-spacing: -.02em;
}
.panel-head p {
  margin: 4px 0 0;
  color: var(--activity-copy);
  font-size: 11px;
  line-height: 1.45;
}
.upcoming-list {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 8px;
  padding: 12px;
}
.upcoming-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-content: start;
  align-items: start;
  gap: 10px;
  min-width: 0;
  padding: 10px;
  border: 1px solid var(--activity-line);
  border-radius: 11px;
  background: #fff;
}
.upcoming-date {
  display: grid;
  width: max-content;
  max-width: 100%;
  min-height: 50px;
  padding: 7px 8px;
  place-content: center;
  border-radius: 9px;
  background: var(--activity-accent-soft);
  color: var(--activity-accent-dark);
  font-size: 10px;
  font-weight: 800;
  line-height: 1.35;
  text-align: center;
  text-transform: uppercase;
  white-space: nowrap;
}
.upcoming-date time { display: grid; gap: 3px; }
.upcoming-item .upcoming-date-label,
.upcoming-item .upcoming-time-label { margin: 0; color: inherit; font-size: inherit; line-height: inherit; }
.upcoming-item strong {
  display: block;
  margin-top: 1px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
body.admin-body .module-hub .upcoming-link strong { font-size: 15px; font-weight: 750; }
.upcoming-link {
  display: block;
  min-width: 0;
  color: var(--activity-ink);
  text-decoration: none;
}
.upcoming-link:focus-visible { border-radius: 4px; outline: 3px solid rgba(13, 148, 136, .22); outline-offset: 3px; }
.upcoming-item strong a, .title-cell a {
  color: var(--activity-ink);
  text-decoration: none;
}
.upcoming-link:hover strong, .upcoming-item strong a:hover, .title-cell a:hover { color: var(--activity-accent-dark); }
.upcoming-item span {
  display: block;
  margin-top: 5px;
  color: var(--activity-copy);
  font-size: 10px;
  line-height: 1.45;
}
.hub-panel > .empty-state {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr);
  align-items: center;
  column-gap: 14px;
  padding: 18px 21px;
  background: linear-gradient(100deg, rgba(234, 247, 245, .7), rgba(255, 255, 255, .88));
  text-align: left;
}
body.admin-body .admin-page-content .module-hub .hub-panel > .empty-state {
  padding: 18px 21px;
  color: var(--activity-copy);
  text-align: left;
}
.empty-mark {
  display: grid;
  width: 42px;
  height: 42px;
  grid-row: span 2;
  place-items: center;
  border: 1px solid rgba(13, 148, 136, .12);
  border-radius: 11px;
  background: #fff;
  color: var(--activity-accent-dark);
  font-size: 18px;
}
.empty-state h2 { margin: 0; font-size: 13px; font-weight: 750; }
.empty-state p {
  max-width: 60ch;
  margin: 3px 0 0;
  color: var(--activity-copy);
  font-size: 11px;
  line-height: 1.5;
}
.filter-form {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) minmax(130px, 170px) minmax(140px, 170px) auto;
  gap: 12px;
  align-items: end;
  padding: 17px 20px;
  border-bottom: 1px solid #edf1f5;
  background: #fbfcfd;
}
.field { min-width: 0; }
.field label {
  display: block;
  margin-bottom: 6px;
  color: #526176;
  font-size: 10px;
  font-weight: 750;
}
.field input, .field select {
  width: 100%;
  min-height: 41px;
  box-sizing: border-box;
  border: 1px solid #d7e0e8;
  border-radius: 8px;
  padding: 8px 11px;
  background: #fff;
  color: var(--activity-ink);
  font: inherit;
  font-size: 12px;
}
.field input::placeholder { color: #94a3b8; }
.field input:focus, .field select:focus {
  border-color: var(--activity-accent);
  outline: 3px solid rgba(13, 148, 136, .13);
  outline-offset: 1px;
}
.filter-actions { display: flex; gap: 7px; }
.hub-button {
  display: inline-flex;
  min-height: 41px;
  box-sizing: border-box;
  align-items: center;
  justify-content: center;
  gap: 7px;
  border: 1px solid var(--activity-accent);
  border-radius: 8px;
  padding: 8px 14px;
  background: var(--activity-accent);
  color: #fff;
  font: inherit;
  font-size: 11px;
  font-weight: 750;
  text-decoration: none;
  cursor: pointer;
  transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease;
}
.hub-button:hover {
  transform: translateY(-1px);
  border-color: var(--activity-accent-dark);
  background: var(--activity-accent-dark);
  color: #fff;
  box-shadow: 0 5px 14px rgba(13, 148, 136, .17);
}
.hub-button:active { transform: translateY(0) scale(.98); }
.hub-button.secondary {
  border-color: #d7e0e8;
  background: #fff;
  color: #475569;
}
.hub-button.secondary:hover {
  border-color: rgba(13, 148, 136, .3);
  background: var(--activity-accent-soft);
  color: var(--activity-accent-dark);
  box-shadow: none;
}
.hub-button:focus-visible, .row-link:focus-visible, .row-action:focus-visible {
  outline: 3px solid rgba(13, 148, 136, .22);
  outline-offset: 2px;
}
.hub-button.secondary.admin-activities-back {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  font-size: 13px;
  font-weight: 750;
  box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
}
.hub-button.secondary.admin-activities-back:hover {
  border-color: #2563eb;
  background: #2563eb;
  color: #fff;
  box-shadow: 0 4px 10px rgba(37, 99, 235, .2);
}
.hub-button.secondary.admin-activities-back:focus-visible { outline-color: rgba(245, 124, 0, .38); }
.table-wrap { max-width: 100%; overflow-x: auto; }
.activity-pagination {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) 40px;
  align-items: center;
  width: min(100%, 420px);
  box-sizing: border-box;
  margin: 16px auto 0;
  padding: 8px;
  border: 1px solid var(--activity-line);
  border-radius: 12px;
  background: #f8fafb;
}
.activity-pagination a,
.activity-pagination .is-disabled {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border: 1px solid #d7e0e8;
  border-radius: 9px;
  background: #fff;
  color: var(--activity-accent-dark);
  font-size: 22px;
  line-height: 1;
  text-decoration: none;
}
.activity-pagination a:hover { border-color: var(--activity-accent); background: var(--activity-accent-soft); }
.activity-pagination a:focus-visible { outline: 3px solid rgba(13, 148, 136, .22); outline-offset: 2px; }
.activity-pagination .is-disabled { color: #aab4bf; }
.activity-pagination-page { color: var(--activity-ink); font-size: 12px; font-weight: 700; text-align: center; }
.activity-pagination-summary { margin: 8px 0 0; color: var(--activity-copy); font-size: 11px; text-align: center; }
.hub-table {
  width: 100%;
  min-width: 980px;
  border-collapse: separate;
  border-spacing: 0;
}
.hub-table th {
  position: sticky;
  top: 0;
  padding: 11px 14px;
  background: #f4f7f9;
  color: #64748b;
  text-align: left;
  font-size: 9px;
  font-weight: 750;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.hub-table td {
  padding: 13px 14px;
  border-top: 1px solid #edf1f5;
  font-size: 11px;
  vertical-align: middle;
}
.hub-table tbody tr { transition: background .16s ease; }
.hub-table tbody tr:hover { background: #f7fbfa; }
.title-cell { max-width: 320px; color: var(--activity-ink); font-weight: 700; }
.title-cell a { display: inline-block; }
.muted { color: var(--activity-copy); }
.badge {
  display: inline-flex;
  align-items: center;
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 9px;
  font-weight: 750;
  white-space: nowrap;
}
.tone-info { background: #e2f3f8; color: #155e75; }
.tone-success { background: #e4f4e9; color: #23633d; }
.tone-danger { background: #fce9e7; color: #8f332e; }
.tone-neutral { background: #edf1f5; color: #475569; }
.row-actions { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.row-actions form { display: inline; }
.row-link, .row-action {
  border: 0;
  border-radius: 5px;
  padding: 5px 3px;
  background: transparent;
  color: var(--activity-accent-dark);
  font: inherit;
  font-size: 10px;
  font-weight: 750;
  text-decoration: none;
  cursor: pointer;
}
.row-link:hover, .row-action:hover { color: #075e57; text-decoration: underline; text-underline-offset: 3px; }
.row-action.danger { color: #b42318; }
.flash {
  margin-bottom: 16px;
  padding: 12px 15px;
  border: 1px solid;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 650;
}
.flash-success { border-color: #bbdfcf; background: #eefaf4; color: #166534; }
.flash-error { border-color: #fecaca; background: #fff1f2; color: #991b1b; }
@media (max-width: 1100px) {
  .module-hub.centered-stats-view.five-stats-view .stat-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .module-hub.centered-stats-view.five-stats-view .stat-card { grid-column: span 2; }
  .module-hub.centered-stats-view.five-stats-view .stat-card:nth-child(4) { grid-column: 2 / span 2; }
  .module-hub.centered-stats-view.five-stats-view .stat-card:nth-child(5) { grid-column: 4 / span 2; }
  .filter-form { grid-template-columns: minmax(180px, 1fr) minmax(120px, .7fr); }
  .filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 760px) {
  .upcoming-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .module-intro { grid-template-columns: 1fr; gap: 18px; padding: 23px 20px; }
  .module-intro-actions { width: 100%; flex-direction: column; }
  .module-intro .hub-button { width: 100%; }
  .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .module-hub.centered-stats-view .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .module-hub.centered-stats-view.five-stats-view .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .module-hub.centered-stats-view .stat-card:last-child:nth-child(odd) { grid-column: 1 / -1; width: calc(50% - 4px); justify-self: center; }
  .module-hub.centered-stats-view.five-stats-view .stat-card { grid-column: auto; }
  .module-hub.centered-stats-view.five-stats-view .stat-card:last-child { grid-column: 1 / -1; width: calc(50% - 4px); justify-self: center; }
  body.admin-body .admin-page-content .module-hub.centered-stats-view.five-stats-view .stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }
  body.admin-body .admin-page-content .module-hub.centered-stats-view.five-stats-view .stat-card { grid-column: auto; }
  body.admin-body .admin-page-content .module-hub.centered-stats-view.five-stats-view .stat-card:last-child {
    grid-column: 1 / -1;
    width: min(100%, 260px);
    box-sizing: border-box;
    justify-self: center;
  }
  .stat-card { padding: 15px 14px; }
  .stat-card strong { font-size: 25px; }
  .panel-head { padding: 16px; }
  .upcoming-list, .filter-form { grid-template-columns: 1fr; }
  .filter-form { gap: 11px; padding: 15px; }
  .filter-actions { grid-column: auto; }
  .filter-actions, .filter-actions .hub-button { width: 100%; }
  .hub-panel > .empty-state { padding: 16px; }
  .table-wrap { border-radius: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .stat-card, .hub-button, .hub-table tbody tr { transition: none; }
}
body.faculty-role .module-hub {
  --activity-accent: #1d4ed8;
  --activity-accent-dark: #1e40af;
  --activity-accent-soft: #e8f0ff;
}
body.faculty-role .module-intro {
  border-color: rgba(29, 78, 216, .18);
  background:
    radial-gradient(circle at 88% 14%, rgba(29, 78, 216, .1), transparent 18rem),
    linear-gradient(115deg, #eff6ff, #fbfdff 74%);
}
body.faculty-role .module-intro::after { border-color: rgba(29, 78, 216, .12); }
body.faculty-role .stat-card:hover {
  border-color: rgba(29, 78, 216, .3);
  box-shadow: 0 8px 20px rgba(29, 78, 216, .09);
}
body.faculty-role .empty-mark { border-color: rgba(29, 78, 216, .14); }
body.faculty-role .hub-panel > .empty-state {
  background: linear-gradient(100deg, rgba(232, 240, 255, .72), rgba(255, 255, 255, .9));
}
body.faculty-role .field input:focus,
body.faculty-role .field select:focus {
  outline-color: rgba(29, 78, 216, .16);
}
body.faculty-role .hub-button:hover { box-shadow: 0 5px 14px rgba(29, 78, 216, .18); }
body.faculty-role .hub-button.secondary:hover { border-color: rgba(29, 78, 216, .3); }
body.faculty-role .hub-button:focus-visible,
body.faculty-role .row-link:focus-visible,
body.faculty-role .row-action:focus-visible { outline-color: rgba(29, 78, 216, .24); }
body.faculty-role .hub-table tbody tr:hover { background: #f5f8ff; }
</style>

<div class="module-hub centered-stats-view<?= $view === 'upcoming' ? ' upcoming-view' : '' ?><?= count($pageConfig['stats']) === 5 ? ' five-stats-view' : '' ?>">
  <header class="module-intro">
    <div><p class="module-kicker"><?= activitiesEscape($pageConfig['kicker']) ?></p><h1><?= activitiesEscape($pageConfig['title']) ?></h1><p><?= activitiesEscape($pageConfig['description']) ?></p></div>
    <div class="module-intro-actions">
      <?php if ($shell === 'admin'): ?><a class="hub-button secondary admin-activities-back" href="<?= activitiesEscape(SITE_URL . 'pages/admin/admin-dashboard.php') ?>"><span aria-hidden="true">←</span> Back to dashboard</a><?php endif; ?>
      <?php if ($role === 'admin' && $view === 'conducted'): ?>
        <a class="hub-button secondary" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activities.php?view=past&module_page=conducted_activities') ?>">Past Activities</a>
      <?php elseif ($role === 'admin' && $view === 'past'): ?>
        <a class="hub-button secondary" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activities.php?view=conducted&module_page=conducted_activities') ?>">Conducted Records</a>
      <?php elseif ($canAddActivity): ?>
        <a class="hub-button" href="<?= activitiesEscape($createActivityUrl) ?>"><?= activitiesEscape($createActivityLabel) ?></a>
      <?php endif; ?>
    </div>
  </header>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= activitiesEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <section class="stat-grid" aria-label="Activity statistics">
    <?php foreach ($pageConfig['stats'] as $card): ?><article class="stat-card"><strong><?= activitiesEscape((string) $card['value']) ?></strong><span><?= activitiesEscape($card['label']) ?></span></article><?php endforeach; ?>
  </section>

  <?php if ($pageConfig['panel'] === 'upcoming'): ?>
      <section class="hub-panel" aria-labelledby="upcoming-activities-heading">
        <div class="panel-head"><div><h2 id="upcoming-activities-heading"><?= activitiesEscape($pageConfig['panel_title']) ?></h2><p><?= activitiesEscape($activityPanelDescription) ?></p></div></div>
        <?php if (!$activitiesAvailable): ?>
          <div class="empty-state"><div class="empty-mark" aria-hidden="true">!</div><h2>Activities are unavailable</h2><p>The research activities module is not installed on this database.</p></div>
        <?php elseif (!$upcoming): ?>
          <div class="empty-state"><div class="empty-mark" aria-hidden="true">&#8635;</div><h2><?= activitiesEscape($emptyActivityHeading) ?></h2><p><?= activitiesEscape($emptyActivityDescription) ?></p></div>
        <?php else: ?>
        <div class="upcoming-list">
          <?php foreach ($upcoming as $item): ?>
            <article class="upcoming-item"><div class="upcoming-date"><time datetime="<?= activitiesEscape(date('Y-m-d', strtotime((string) $item['activity_date'])) . (!empty($item['activity_time']) ? 'T' . date('H:i:s', strtotime((string) $item['activity_time'])) : '')) ?>"><span class="upcoming-date-label"><?= activitiesEscape(date('M j, Y', strtotime((string) $item['activity_date']))) ?></span><?php if (!empty($item['activity_time'])): ?><span class="upcoming-time-label"><?= activitiesEscape(date('g:i A', strtotime((string) $item['activity_time']))) ?></span><?php endif; ?></time></div><a class="upcoming-link" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $item['activity_id'] . ($role === 'research_staff' ? '&module_page=' . rawurlencode($modulePage !== '' ? $modulePage : ($statusPermissionPage !== '' ? $statusPermissionPage : 'view_activity')) : '')) ?>"><strong><?= activitiesEscape($item['title']) ?></strong><span><?= activitiesEscape($item['venue'] ?: 'Venue to be announced') ?></span></a></article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>
  <?php elseif ($pageConfig['panel'] === 'attendance'): ?>
    <section class="hub-panel" aria-labelledby="attendance-heading">
      <div class="panel-head"><div><h2 id="attendance-heading">Attendance summary</h2><p>Participant counts for completed activities.</p></div></div>
      <?php if (!$activitiesAvailable || !$attendanceSummary): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">&#10003;</div><h2><?= $activitiesAvailable ? 'No completed activities yet' : 'Activities are unavailable' ?></h2><p><?= $activitiesAvailable ? 'Attendance summaries will appear when an activity is marked completed.' : 'The research activities module is not installed on this database.' ?></p></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table"><thead><tr><th>Date</th><th>Activity</th><th>Participants</th><th>Attended</th></tr></thead><tbody>
          <?php foreach ($attendanceSummary as $record): ?><tr><td><?= activitiesEscape(date('M j, Y', strtotime((string) $record['activity_date']))) ?></td><td class="title-cell"><a href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $record['activity_id'] . ($role === 'research_staff' ? '&module_page=' . rawurlencode($modulePage !== '' ? $modulePage : ($statusPermissionPage !== '' ? $statusPermissionPage : 'view_activity')) : '')) ?>"><?= activitiesEscape($record['title']) ?></a></td><td><?= (int) $record['participants'] ?></td><td><?= (int) $record['attended'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="hub-panel" aria-labelledby="activity-directory-heading">
    <div class="panel-head"><div><h2 id="activity-directory-heading"><?= activitiesEscape($pageConfig['directory_title']) ?></h2><p><?= $activityTotal ?> <?= $activityTotal === 1 ? 'record' : 'records' ?> found</p></div></div>
    <form class="filter-form" method="get" action="<?= activitiesEscape(SITE_URL . 'pages/shared/activities.php') ?>">
      <?php if (in_array($view, ['upcoming', 'ongoing', 'conducted', 'past'], true)): ?><input type="hidden" name="view" value="<?= activitiesEscape($view) ?>"><?php endif; ?>
      <?php if (in_array($modulePage, ['view_activity', 'upcoming_activities', 'ongoing_activities', 'conducted_activities'], true)): ?><input type="hidden" name="module_page" value="<?= activitiesEscape($modulePage) ?>"><?php endif; ?>
      <div class="field"><label for="activity-q">Title, venue, or speaker</label><input id="activity-q" name="q" type="search" maxlength="150" value="<?= activitiesEscape($query) ?>" placeholder="Search activities"></div>
      <div class="field"><label for="activity-type">Type</label><select id="activity-type" name="type"><option value="">All types</option><?php foreach ($filterTypeOptions as $value => $label): ?><option value="<?= activitiesEscape($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= activitiesEscape($label) ?></option><?php endforeach; ?></select></div>
      <?php if ($view !== 'past'): ?><div class="field"><label for="activity-status">Status</label><select id="activity-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= activitiesEscape($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= activitiesEscape($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="filter-actions"><button class="hub-button" type="submit">Apply</button><?php if ($filtersActive): ?><a class="hub-button secondary" href="<?= activitiesEscape($clearFiltersUrl) ?>">Clear</a><?php endif; ?></div>
    </form>
    <?php if (!$activitiesAvailable || !$activities): ?>
      <div class="empty-state"><div class="empty-mark" aria-hidden="true">&#8635;</div><h2><?= !$activitiesAvailable ? 'Activities are unavailable' : ($view === 'past' ? 'No past activities' : 'No activities found') ?></h2><p><?= !$activitiesAvailable ? 'Apply database migration 012 to enable this module.' : ($view === 'past' ? 'Ended activities awaiting completion will appear here.' : 'Adjust the search or filters to see more activity records.') ?></p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="hub-table"><thead><tr><th>Scheduled dates</th><th>Title</th><th>Type</th><th>Venue</th><th>Organizer</th><th>Status</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($activities as $activity): $badge = activitiesStatusBadge((string) $activity['clock_status']); $activityPage = $modulePage !== '' ? $modulePage : ($statusPermissionPage !== '' ? $statusPermissionPage : 'view_activity'); $moduleSuffix = '&module_page=' . rawurlencode($activityPage); $detailUrl = SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id'] . $moduleSuffix; ?><tr><td><?= activitiesEscape(activitiesDateSummary($activity)) ?></td><td class="title-cell"><a href="<?= activitiesEscape($detailUrl) ?>"><?= activitiesEscape($activity['title']) ?></a></td><td><?= activitiesEscape($typeOptions[$activity['activity_type']] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?></td><td class="muted"><?= activitiesEscape($activity['venue'] ?: '—') ?></td><td class="muted"><?= activitiesEscape($activity['organizer'] ?: '—') ?></td><td><span class="badge tone-<?= activitiesEscape($badge['tone']) ?>" data-live-activity-status data-stored-status="<?= activitiesEscape((string) $activity['status']) ?>" data-clock-sessions="<?= activitiesEscape((string) json_encode($activity['clock_sessions'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"><?= activitiesEscape($badge['label']) ?></span></td><td><div class="row-actions"><a class="row-link" href="<?= activitiesEscape($detailUrl) ?>">View</a><?php if ($canManage): ?><a class="row-link" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . (int) $activity['activity_id'] . $moduleSuffix) ?>">Edit</a><?php if ($activity['status'] === 'ongoing'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="activity_id" value="<?= (int) $activity['activity_id'] ?>"><button class="row-action" type="submit">Complete</button></form><?php endif; ?><?php if (in_array((string) $activity['status'], ['scheduled', 'upcoming', 'ongoing'], true)): ?><form method="post" onsubmit="return confirm('Cancel this activity?');"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="activity_id" value="<?= (int) $activity['activity_id'] ?>"><button class="row-action danger" type="submit">Cancel</button></form><?php endif; ?><?php endif; ?></div></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($activityPageCount > 1): ?>
        <nav class="activity-pagination" aria-label="Activity pages">
          <?php if ($activityPage > 1): ?><a href="<?= activitiesEscape($activityPaginationUrl($activityPage - 1)) ?>" aria-label="Previous page">&#8592;</a><?php else: ?><span class="is-disabled" aria-disabled="true" aria-label="First page">&#8592;</span><?php endif; ?>
          <span class="activity-pagination-page">Page <?= $activityPage ?> of <?= $activityPageCount ?></span>
          <?php if ($activityPage < $activityPageCount): ?><a href="<?= activitiesEscape($activityPaginationUrl($activityPage + 1)) ?>" aria-label="Next page">&#8594;</a><?php else: ?><span class="is-disabled" aria-disabled="true" aria-label="Last page">&#8594;</span><?php endif; ?>
        </nav>
        <p class="activity-pagination-summary">Showing <?= $activityRangeStart ?>–<?= $activityRangeEnd ?> of <?= $activityTotal ?> activities</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<script src="<?= activitiesEscape(SITE_URL . 'js/activity-status-clock.js') ?>" defer></script>
<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
