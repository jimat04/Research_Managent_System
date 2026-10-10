<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
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
$capacityPageInput = trim((string) ($_GET['capacity_page'] ?? ''));
$capacityPage = in_array($capacityPageInput, ['seminars', 'trainings', 'workshops'], true)
    ? $capacityPageInput
    : '';
$requestedActivityType = trim((string) ($_GET['type'] ?? ''));
$permissionPages = match ($requestedActivityType) {
    'seminar' => ['seminars'],
    'training' => ['trainings'],
    'workshop' => ['workshops'],
    default => ['seminars', 'trainings', 'workshops'],
};
if ($capacityPage !== '' && (
    ($capacityPage === 'seminars' && $requestedActivityType !== 'seminar')
    || ($capacityPage === 'trainings' && $requestedActivityType !== 'training')
    || ($capacityPage === 'workshops' && $requestedActivityType !== 'workshop')
)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
if ($capacityPage === '') {
    $capacityPage = match ($requestedActivityType) {
        'seminar' => 'seminars',
        'training' => 'trainings',
        'workshop' => 'workshops',
        default => '',
    };
}
if ($role === 'research_staff' && $capacityPage === '' && $requestedActivityType === '') {
    foreach (['seminars', 'trainings', 'workshops'] as $pageKey) {
        if (hasModulePermission($userId, 'research_capacity_building', 'view', $pageKey)) {
            header('Location: ' . SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($pageKey));
            exit;
        }
    }
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
if ($role === 'research_staff' && !in_array($requestedActivityType, ['', 'seminar', 'training', 'workshop'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$capacityReturnUrl = $capacityPage !== ''
    ? SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($capacityPage)
    : SITE_URL . 'pages/shared/activities.php';
$canViewActivities = hasRole('admin');
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
$canManagePage = static function (string $activityType, string $selectedPage) use ($role, $userId): bool {
    if ($role === 'admin') {
        return true;
    }
    $pages = match ($activityType) {
        'seminar' => ['seminars'],
        'training' => ['trainings'],
        'workshop' => ['workshops'],
        default => [],
    };
    foreach ($pages as $pageKey) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $pageKey)) {
            return true;
        }
    }
    return false;
};
$canManage = hasRole('admin');
if (!$canManage) {
    $canManage = $canManagePage($requestedActivityType, $capacityPage);
}

function activitiesEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activitiesRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function activitiesStatusBadge(string $status): array
{
    return match ($status) {
        'scheduled' => ['label' => 'Scheduled', 'tone' => 'info'],
        'completed' => ['label' => 'Completed', 'tone' => 'success'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$activityCheck = $conn->query("SHOW TABLES LIKE 'research_activities'");
$activitiesAvailable = $activityCheck instanceof mysqli_result && $activityCheck->num_rows > 0;
if ($activityCheck instanceof mysqli_result) {
    $activityCheck->free();
}
$participantCheck = $conn->query("SHOW TABLES LIKE 'activity_participants'");
$participantsAvailable = $participantCheck instanceof mysqli_result && $participantCheck->num_rows > 0;
if ($participantCheck instanceof mysqli_result) {
    $participantCheck->free();
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
                ? $canManagePage((string) $activityTypeRow['activity_type'], $capacityPage)
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
    $targetStatuses = ['complete' => 'completed', 'cancel' => 'cancelled'];
    if (!$activitiesAvailable || !$activityId || !isset($targetStatuses[$action])) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The requested activity action is not available.'];
    } else {
        $targetStatus = $targetStatuses[$action];
        $stmt = $conn->prepare("UPDATE research_activities SET status = ? WHERE activity_id = ? AND status = 'scheduled'");
        $stmt->bind_param('si', $targetStatus, $activityId);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            $verb = $targetStatus === 'completed' ? 'completed' : 'cancelled';
            logActivity("Marked research activity #{$activityId} as {$verb}", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => "Activity marked {$verb}."];
        } else {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Only scheduled activities can be completed or cancelled.'];
        }
        $stmt->close();
    }
    header('Location: ' . $capacityReturnUrl);
    exit;
}

$flash = $_SESSION['activities_flash'] ?? null;
unset($_SESSION['activities_flash']);

$typeOptions = ['seminar' => 'Seminar', 'training' => 'Training', 'presentation' => 'Presentation', 'workshop' => 'Workshop', 'forum' => 'Forum'];
$statusOptions = ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$query = trim((string) ($_GET['q'] ?? ''));
$query = strlen($query) > 150 ? substr($query, 0, 150) : $query;
$typeInput = trim((string) ($_GET['type'] ?? ''));
$type = preg_match('/^(seminar|training|presentation|workshop|forum)$/D', $typeInput) ? $typeInput : '';
if ($role === 'research_staff' && isset($typeOptions[$type])) {
    $typeOptions = [$type => $typeOptions[$type]];
}
$createActivityUrl = SITE_URL . 'pages/shared/activity-form.php';
if ($type !== '') {
    $createActivityUrl .= '?type=' . rawurlencode($type);
    if ($capacityPage !== '') {
        $createActivityUrl .= '&capacity_page=' . rawurlencode($capacityPage);
    }
}
$statusInput = trim((string) ($_GET['status'] ?? ''));
$status = preg_match('/^(scheduled|completed|cancelled)$/D', $statusInput) ? $statusInput : '';
$filtersActive = $query !== '' || $type !== '' || $status !== '';

// Preset views are resolved only from validated enum values. Additional filters
// remain in the query string and continue to narrow the directory in that view.
$view = 'overview';
if ($type !== '') {
    $view = $type;
} elseif ($status === 'completed') {
    $view = 'records';
}

$typeLabels = [
    'seminar' => 'Seminars',
    'training' => 'Trainings',
    'presentation' => 'Presentations',
    'workshop' => 'Workshops',
    'forum' => 'Forums',
];
$stats = ['participants' => 0];
$upcoming = [];
$attendanceSummary = [];
$activities = [];
if ($activitiesAvailable) {
    if ($view === 'overview') {
        $stmt = $conn->prepare("SELECT COUNT(CASE WHEN activity_type = 'seminar' THEN 1 END) AS seminar, COUNT(CASE WHEN activity_type = 'training' THEN 1 END) AS training, COUNT(CASE WHEN activity_type = 'presentation' THEN 1 END) AS presentation, COUNT(CASE WHEN activity_type = 'workshop' THEN 1 END) AS workshop, COUNT(CASE WHEN activity_type = 'forum' THEN 1 END) AS forum FROM research_activities");
        $row = activitiesRun($stmt)->fetch_assoc() ?: [];
        foreach ($typeLabels as $activityType => $label) {
            $stats[$activityType] = (int) ($row[$activityType] ?? 0);
        }
        $stmt->close();
        if ($participantsAvailable) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS participants FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id');
            $stats['participants'] = (int) (activitiesRun($stmt)->fetch_assoc()['participants'] ?? 0);
            $stmt->close();
        }
        $stmt = $conn->prepare("SELECT activity_id, activity_date, activity_time, title, venue FROM research_activities WHERE status = 'scheduled' AND activity_date >= CURDATE() ORDER BY activity_date ASC, activity_time ASC, activity_id ASC LIMIT 5");
        $result = activitiesRun($stmt);
        while ($row = $result->fetch_assoc()) $upcoming[] = $row;
        $stmt->close();
    } elseif (isset($typeOptions[$view])) {
        $viewType = $view;
        $stmt = $conn->prepare("SELECT COUNT(CASE WHEN status = 'completed' THEN 1 END) AS held, COUNT(CASE WHEN status = 'scheduled' THEN 1 END) AS scheduled FROM research_activities WHERE activity_type = ?");
        $stmt->bind_param('s', $viewType);
        $row = activitiesRun($stmt)->fetch_assoc() ?: [];
        $stats['held'] = (int) ($row['held'] ?? 0);
        $stats['scheduled'] = (int) ($row['scheduled'] ?? 0);
        $stmt->close();
        if ($participantsAvailable) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS participants FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE ra.activity_type = ?');
            $stmt->bind_param('s', $viewType);
            $stats['participants'] = (int) (activitiesRun($stmt)->fetch_assoc()['participants'] ?? 0);
            $stmt->close();
        }
        $stmt = $conn->prepare("SELECT activity_id, activity_date, activity_time, title, venue FROM research_activities WHERE activity_type = ? AND status = 'scheduled' AND activity_date >= CURDATE() ORDER BY activity_date ASC, activity_time ASC, activity_id ASC LIMIT 5");
        $stmt->bind_param('s', $viewType);
        $result = activitiesRun($stmt);
        while ($row = $result->fetch_assoc()) $upcoming[] = $row;
        $stmt->close();
    } elseif ($view === 'records') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS completed FROM research_activities WHERE status = 'completed'");
        $stats['completed'] = (int) (activitiesRun($stmt)->fetch_assoc()['completed'] ?? 0);
        $stmt->close();
        $stats['attendance_rate'] = 0.0;
        $stats['unique_participants'] = 0;
        if ($participantsAvailable) {
            $stmt = $conn->prepare("SELECT COUNT(ap.participant_id) AS participant_count, COALESCE(SUM(ap.attended = 1), 0) AS attended_count, COUNT(DISTINCT COALESCE(CONCAT('user:', ap.user_id), CONCAT('name:', LOWER(NULLIF(TRIM(ap.participant_name), ''))))) AS unique_participants FROM research_activities ra LEFT JOIN activity_participants ap ON ap.activity_id = ra.activity_id WHERE ra.status = 'completed'");
            $row = activitiesRun($stmt)->fetch_assoc() ?: [];
            $participantCount = (int) ($row['participant_count'] ?? 0);
            $attendedCount = (int) ($row['attended_count'] ?? 0);
            $stats['attendance_rate'] = $participantCount > 0 ? ($attendedCount / $participantCount) * 100 : 0.0;
            $stats['unique_participants'] = (int) ($row['unique_participants'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("SELECT ra.activity_id, ra.activity_date, ra.title, COUNT(ap.participant_id) AS participants, COALESCE(SUM(ap.attended = 1), 0) AS attended FROM research_activities ra LEFT JOIN activity_participants ap ON ap.activity_id = ra.activity_id WHERE ra.status = 'completed' GROUP BY ra.activity_id, ra.activity_date, ra.title ORDER BY ra.activity_date DESC, ra.activity_id DESC");
        } else {
            $stmt = $conn->prepare("SELECT activity_id, activity_date, title, 0 AS participants, 0 AS attended FROM research_activities WHERE status = 'completed' ORDER BY activity_date DESC, activity_id DESC");
        }
        $result = activitiesRun($stmt);
        while ($row = $result->fetch_assoc()) $attendanceSummary[] = $row;
        $stmt->close();
    }

    $sql = 'SELECT activity_id, activity_date, title, activity_type, venue, organizer, status FROM research_activities WHERE 1 = 1';
    $params = [];
    $types = '';
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = '%' . $escaped . '%';
        $sql .= " AND (title LIKE ? ESCAPE '\\\\' OR venue LIKE ? ESCAPE '\\\\' OR speaker LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($type !== '') {
        $sql .= ' AND activity_type = ?';
        $params[] = $type;
        $types .= 's';
    }
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
        $types .= 's';
    }
    $sql .= ' ORDER BY activity_date DESC, activity_id DESC LIMIT 250';
    $stmt = $conn->prepare($sql);
    $result = activitiesRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    $stmt->close();
}

$viewConfig = [
    'overview' => [
        'title' => 'Research Activities',
        'kicker' => 'Research engagement',
        'subtitle' => 'Institutional seminars, trainings, presentations, workshops, and forums',
        'description' => 'Review scheduled and completed research events, their organizers, and participation records.',
        'stats' => array_merge(
            array_map(static function (string $activityType, string $label) use ($stats): array {
                return ['value' => $stats[$activityType] ?? 0, 'label' => $label];
            }, array_keys($typeLabels), array_values($typeLabels)),
            [['value' => $stats['participants'], 'label' => 'Total participants']]
        ),
        'panel' => 'upcoming',
        'panel_title' => 'Upcoming activities',
        'shell_page' => SITE_URL . 'pages/shared/activities.php',
        'directory_title' => 'Activity directory',
    ],
    'records' => [
        'title' => 'Completed Activity Records',
        'kicker' => 'Attendance records',
        'subtitle' => 'Completed activities and attendance outcomes',
        'description' => 'Review completed research activities and their recorded participation.',
        'stats' => [
            ['value' => $stats['completed'] ?? 0, 'label' => 'Completed activities'],
            ['value' => (float) ($stats['attendance_rate'] ?? 0) === 0.0 ? '0%' : number_format((float) $stats['attendance_rate'], 1) . '%', 'label' => 'Attendance rate'],
            ['value' => $stats['unique_participants'] ?? 0, 'label' => 'Unique participants'],
        ],
        'panel' => 'attendance',
        'panel_title' => 'Attendance summary',
        'shell_page' => SITE_URL . 'pages/shared/activities.php?status=completed',
        'directory_title' => 'Completed activity records',
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
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 18px;
}
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
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 0;
}
.upcoming-item {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr);
  align-content: start;
  align-items: start;
  gap: 12px;
  min-width: 0;
  padding: 17px 18px;
}
.upcoming-item + .upcoming-item { border-left: 1px solid #edf1f5; }
.upcoming-date {
  display: grid;
  min-height: 50px;
  place-content: center;
  border-radius: 9px;
  background: var(--activity-accent-soft);
  color: var(--activity-accent-dark);
  font-size: 10px;
  font-weight: 800;
  line-height: 1.35;
  text-align: center;
  text-transform: uppercase;
}
.upcoming-item strong {
  display: block;
  margin-top: 1px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.upcoming-item strong a, .title-cell a {
  color: var(--activity-ink);
  text-decoration: none;
}
.upcoming-item strong a:hover, .title-cell a:hover { color: var(--activity-accent-dark); }
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
.table-wrap { max-width: 100%; overflow-x: auto; }
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
  .stat-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .upcoming-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .upcoming-item + .upcoming-item { border-left: 0; }
  .upcoming-item:nth-child(even) { border-left: 1px solid #edf1f5; }
  .upcoming-item:nth-child(n + 3) { border-top: 1px solid #edf1f5; }
  .filter-form { grid-template-columns: minmax(180px, 1fr) minmax(120px, .7fr); }
  .filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 640px) {
  .module-intro { grid-template-columns: 1fr; gap: 18px; padding: 23px 20px; }
  .module-intro .hub-button { width: 100%; }
  .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .stat-card { padding: 15px 14px; }
  .stat-card strong { font-size: 25px; }
  .panel-head { padding: 16px; }
  .upcoming-list, .filter-form { grid-template-columns: 1fr; }
  .upcoming-item, .upcoming-item:nth-child(even) { border-left: 0; }
  .upcoming-item + .upcoming-item { border-top: 1px solid #edf1f5; }
  .filter-form { gap: 11px; padding: 15px; }
  .filter-actions { grid-column: auto; }
  .filter-actions, .filter-actions .hub-button { width: 100%; }
  .hub-panel > .empty-state { padding: 16px; }
  .table-wrap { border-radius: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .stat-card, .hub-button, .hub-table tbody tr { transition: none; }
}
</style>

<div class="module-hub">
  <header class="module-intro">
    <div><p class="module-kicker"><?= activitiesEscape($pageConfig['kicker']) ?></p><h1><?= activitiesEscape($pageConfig['title']) ?></h1><p><?= activitiesEscape($pageConfig['description']) ?></p></div>
    <?php if ($canManage): ?><a class="hub-button" href="<?= activitiesEscape($createActivityUrl) ?>">+ Add Activity</a><?php endif; ?>
  </header>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= activitiesEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <section class="stat-grid" aria-label="Activity statistics">
    <?php foreach ($pageConfig['stats'] as $card): ?><article class="stat-card"><strong><?= activitiesEscape((string) $card['value']) ?></strong><span><?= activitiesEscape($card['label']) ?></span></article><?php endforeach; ?>
  </section>

  <?php if ($pageConfig['panel'] === 'upcoming'): ?>
    <section class="hub-panel" aria-labelledby="upcoming-heading">
      <div class="panel-head"><div><h2 id="upcoming-heading"><?= activitiesEscape($pageConfig['panel_title']) ?></h2><p>The next five scheduled <?= $view === 'overview' ? 'activities' : activitiesEscape(strtolower($typeLabels[$view])) ?>.</p></div></div>
      <?php if (!$activitiesAvailable || !$upcoming): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">&#128197;</div><h2>No upcoming <?= $view === 'overview' ? 'activities' : activitiesEscape(strtolower($typeLabels[$view])) ?></h2><p><?= $activitiesAvailable ? 'Scheduled events will appear here when dates are added.' : 'The research activities module is not installed on this database.' ?></p></div>
      <?php else: ?>
        <div class="upcoming-list">
          <?php foreach ($upcoming as $item): ?>
            <article class="upcoming-item"><div class="upcoming-date"><?= activitiesEscape(date('M j, Y', strtotime((string) $item['activity_date']))) ?></div><strong><a href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $item['activity_id']) ?>"><?= activitiesEscape($item['title']) ?></a></strong><span><?= activitiesEscape($item['venue'] ?: 'Venue to be announced') ?></span></article>
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
          <?php foreach ($attendanceSummary as $record): ?><tr><td><?= activitiesEscape(date('M j, Y', strtotime((string) $record['activity_date']))) ?></td><td class="title-cell"><a href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $record['activity_id']) ?>"><?= activitiesEscape($record['title']) ?></a></td><td><?= (int) $record['participants'] ?></td><td><?= (int) $record['attended'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="hub-panel" aria-labelledby="activity-directory-heading">
    <div class="panel-head"><div><h2 id="activity-directory-heading"><?= activitiesEscape($pageConfig['directory_title']) ?></h2><p><?= count($activities) ?> <?= count($activities) === 1 ? 'record' : 'records' ?> in view</p></div></div>
    <form class="filter-form" method="get" action="<?= activitiesEscape(SITE_URL . 'pages/shared/activities.php') ?>">
      <div class="field"><label for="activity-q">Title, venue, or speaker</label><input id="activity-q" name="q" type="search" maxlength="150" value="<?= activitiesEscape($query) ?>" placeholder="Search activities"></div>
      <div class="field"><label for="activity-type">Type</label><select id="activity-type" name="type"><option value="">All types</option><?php foreach ($typeOptions as $value => $label): ?><option value="<?= activitiesEscape($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= activitiesEscape($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="activity-status">Status</label><select id="activity-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= activitiesEscape($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= activitiesEscape($label) ?></option><?php endforeach; ?></select></div>
      <div class="filter-actions"><button class="hub-button" type="submit">Apply</button><?php if ($filtersActive): ?><a class="hub-button secondary" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activities.php') ?>">Clear</a><?php endif; ?></div>
    </form>
    <?php if (!$activitiesAvailable || !$activities): ?>
      <div class="empty-state"><div class="empty-mark" aria-hidden="true">&#8635;</div><h2><?= $activitiesAvailable ? 'No activities found' : 'Activities are unavailable' ?></h2><p><?= $activitiesAvailable ? 'Adjust the search or filters to see more activity records.' : 'Apply database migration 012 to enable this module.' ?></p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="hub-table"><thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Venue</th><th>Organizer</th><th>Status</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($activities as $activity): $badge = activitiesStatusBadge((string) $activity['status']); $capacitySuffix = $capacityPage !== '' ? '&capacity_page=' . rawurlencode($capacityPage) : ''; $detailUrl = SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id'] . $capacitySuffix; ?><tr><td><?= activitiesEscape(date('M j, Y', strtotime((string) $activity['activity_date']))) ?></td><td class="title-cell"><a href="<?= activitiesEscape($detailUrl) ?>"><?= activitiesEscape($activity['title']) ?></a></td><td><?= activitiesEscape($typeOptions[$activity['activity_type']] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?></td><td class="muted"><?= activitiesEscape($activity['venue'] ?: '—') ?></td><td class="muted"><?= activitiesEscape($activity['organizer'] ?: '—') ?></td><td><span class="badge tone-<?= activitiesEscape($badge['tone']) ?>"><?= activitiesEscape($badge['label']) ?></span></td><td><div class="row-actions"><a class="row-link" href="<?= activitiesEscape($detailUrl) ?>">View</a><?php if ($canManage): ?><a class="row-link" href="<?= activitiesEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . (int) $activity['activity_id'] . $capacitySuffix) ?>">Edit</a><?php if ($activity['status'] === 'scheduled'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="activity_id" value="<?= (int) $activity['activity_id'] ?>"><button class="row-action" type="submit">Complete</button></form><form method="post" onsubmit="return confirm('Cancel this activity?');"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="activity_id" value="<?= (int) $activity['activity_id'] ?>"><button class="row-action danger" type="submit">Cancel</button></form><?php endif; ?><?php endif; ?></div></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
