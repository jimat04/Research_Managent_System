<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function calendarTableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }
    $stmt->close();
    return $columns;
}

function calendarRun(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
}

function calendarScopeSql(string $role, int $userId, array $tables, array &$params, string &$types): string
{
    if ($role === 'student') {
        $parts = ['rp.created_by = ?'];
        $params[] = $userId;
        $types .= 'i';
        if ($tables['project_members']) {
            $parts[] = 'EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?)';
            $params[] = $userId;
            $types .= 'i';
        }
        return ' AND (' . implode(' OR ', $parts) . ')';
    }
    if ($role === 'faculty') {
        $parts = [];
        if ($tables['project_advisers']) {
            $parts[] = 'EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?)';
            $params[] = $userId;
            $types .= 'i';
        }
        if ($tables['project_reviews']) {
            $parts[] = 'EXISTS (SELECT 1 FROM project_reviews pr WHERE pr.project_id = rp.project_id AND pr.reviewer_id = ?)';
            $params[] = $userId;
            $types .= 'i';
        }
        return $parts ? ' AND (' . implode(' OR ', $parts) . ')' : ' AND 1 = 0';
    }
    return '';
}

function calendarEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function calendarStatusClass(string $status): string
{
    return match (strtolower($status)) {
        'done', 'presented', 'completed' => 'status-done',
        'rescheduled' => 'status-rescheduled',
        default => 'status-scheduled',
    };
}

$requestedMonth = isset($_GET['month']) ? (string) $_GET['month'] : '';
$month = null;
if ($requestedMonth !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requestedMonth)) {
    $candidate = DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth);
    if ($candidate && $candidate->format('Y-m') === $requestedMonth) {
        $month = $candidate;
    }
}
$month = $month ?: new DateTimeImmutable('first day of this month midnight');
$monthStart = $month->setTime(0, 0);
$monthEnd = $monthStart->modify('first day of next month');
$today = new DateTimeImmutable('today');
$upcomingEnd = $today->modify('+60 days');

$tableNames = [
    'defense_schedule', 'research_projects', 'project_members',
    'project_advisers', 'project_reviews', 'research_publication_tracking',
];
$columns = [];
$tables = [];
foreach ($tableNames as $table) {
    $columns[$table] = calendarTableColumns($conn, $table);
    $tables[$table] = !empty($columns[$table]);
}

$events = [];
$role = (string) ($user['role'] ?? '');
$userId = (int) $user['user_id'];
$deletedGuard = isset($columns['research_projects']['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';

$defense = $columns['defense_schedule'];
if ($tables['defense_schedule'] && $tables['research_projects']
    && isset($defense['project_id'], $defense['schedule_date'])) {
    $typeColumn = isset($defense['type']) ? 'ds.type' : "'final'";
    $venueColumn = isset($defense['venue']) ? 'ds.venue' : "''";
    $statusColumn = isset($defense['status']) ? 'ds.status' : "'scheduled'";
    $statusGuard = isset($defense['status']) ? " AND ds.status <> 'cancelled'" : '';
    $params = [
        $monthStart->format('Y-m-d H:i:s'),
        $monthEnd->format('Y-m-d H:i:s'),
        $today->format('Y-m-d H:i:s'),
        $upcomingEnd->format('Y-m-d H:i:s'),
    ];
    $types = 'ssss';
    $scope = calendarScopeSql($role, $userId, $tables, $params, $types);
    $sql = "SELECT ds.schedule_date AS event_date, {$typeColumn} AS event_type,
                   {$venueColumn} AS venue, {$statusColumn} AS event_status, rp.title
            FROM defense_schedule ds
            JOIN research_projects rp ON rp.project_id = ds.project_id
            WHERE ((ds.schedule_date >= ? AND ds.schedule_date < ?)
                   OR (ds.schedule_date >= ? AND ds.schedule_date < ?))
                  {$statusGuard}{$deletedGuard}{$scope}
            ORDER BY ds.schedule_date ASC";
    $stmt = $conn->prepare($sql);
    calendarRun($stmt, $types, $params);
    $result = $stmt->get_result();
    $typeLabels = ['proposal' => 'Proposal Defense', 'pre_oral' => 'Pre-Oral Defense', 'final' => 'Final Defense'];
    while ($row = $result->fetch_assoc()) {
        $rawType = (string) $row['event_type'];
        $events[] = [
            'date' => (string) $row['event_date'],
            'title' => (string) $row['title'],
            'label' => $typeLabels[$rawType] ?? ucwords(str_replace('_', ' ', $rawType)),
            'venue' => (string) $row['venue'],
            'status' => (string) $row['event_status'],
            'source' => 'defense',
        ];
    }
    $stmt->close();
}

$publication = $columns['research_publication_tracking'];
if ($tables['research_publication_tracking'] && $tables['research_projects']
    && isset($publication['project_id'], $publication['colloquium_date'])) {
    $statusColumn = isset($publication['colloquium_status']) ? 'rpt.colloquium_status' : "'scheduled'";
    $statusGuard = isset($publication['colloquium_status'])
        ? " AND (rpt.colloquium_status IS NULL OR rpt.colloquium_status <> 'cancelled')"
        : '';
    $params = [
        $monthStart->format('Y-m-d H:i:s'),
        $monthEnd->format('Y-m-d H:i:s'),
        $today->format('Y-m-d H:i:s'),
        $upcomingEnd->format('Y-m-d H:i:s'),
    ];
    $types = 'ssss';
    $scope = calendarScopeSql($role, $userId, $tables, $params, $types);
    $sql = "SELECT rpt.colloquium_date AS event_date, {$statusColumn} AS event_status, rp.title
            FROM research_publication_tracking rpt
            JOIN research_projects rp ON rp.project_id = rpt.project_id
            WHERE rpt.colloquium_date IS NOT NULL
                  AND ((rpt.colloquium_date >= ? AND rpt.colloquium_date < ?)
                       OR (rpt.colloquium_date >= ? AND rpt.colloquium_date < ?))
                  {$statusGuard}{$deletedGuard}{$scope}
            ORDER BY rpt.colloquium_date ASC";
    $stmt = $conn->prepare($sql);
    calendarRun($stmt, $types, $params);
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'date' => (string) $row['event_date'],
            'title' => (string) $row['title'],
            'label' => 'Research Colloquium',
            'venue' => '',
            'status' => (string) ($row['event_status'] ?: 'scheduled'),
            'source' => 'colloquium',
        ];
    }
    $stmt->close();
}

usort($events, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
$eventsByDay = [];
$upcomingEvents = [];
foreach ($events as $event) {
    $eventDate = new DateTimeImmutable($event['date']);
    if ($eventDate >= $monthStart && $eventDate < $monthEnd) {
        $eventsByDay[$eventDate->format('Y-m-d')][] = $event;
    }
    if ($eventDate >= $today && $eventDate < $upcomingEnd) {
        $upcomingEvents[] = $event;
    }
}

$shell = match ($role) {
    'admin' => 'admin', 'research_staff' => 'staff', 'faculty' => 'faculty', default => 'student',
};
if ($shell === 'admin') {
    renderAdminShell($user, 'calendar.php', 'Research Calendar', 'Defense and colloquium schedules');
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'calendar.php', 'Research Calendar', 'Defense and colloquium schedules');
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'calendar.php', 'Research Calendar', 'Your advised and reviewed projects');
} else {
    renderStudentShell($user, 'calendar.php', 'Research Calendar', 'Schedules for your research projects');
}

$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$firstWeekday = (int) $monthStart->format('w');
$daysInMonth = (int) $monthStart->format('t');
?>

<style>
    .calendar-toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1rem; }
    .calendar-toolbar h2 { margin:0; font-size:1.2rem; }
    .calendar-nav { display:flex; gap:.5rem; }
    .calendar-nav a { border:1px solid #d8dee8; border-radius:8px; padding:.5rem .75rem; color:#25324b; text-decoration:none; background:#fff; }
    .calendar-card { background:#fff; border:1px solid #e3e7ee; border-radius:12px; padding:1rem; margin-bottom:1rem; }
    .calendar-grid { display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); border-top:1px solid #e3e7ee; border-left:1px solid #e3e7ee; }
    .calendar-weekday, .calendar-day { border-right:1px solid #e3e7ee; border-bottom:1px solid #e3e7ee; }
    .calendar-weekday { padding:.55rem .35rem; text-align:center; font-size:.75rem; font-weight:700; color:#667085; background:#f8fafc; }
    .calendar-day { min-height:92px; padding:.55rem; background:#fff; }
    .calendar-day.is-empty { background:#f8fafc; }
    .calendar-day.is-today { box-shadow:inset 0 0 0 2px #7c3aed; }
    .day-head { display:flex; align-items:center; justify-content:space-between; gap:.35rem; font-weight:700; }
    .event-count { min-width:1.35rem; height:1.35rem; padding:0 .35rem; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; font-size:.7rem; color:#fff; background:#7c3aed; }
    .day-event { margin-top:.4rem; font-size:.72rem; color:#475467; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .upcoming-list { display:grid; gap:.75rem; }
    .event-row { display:grid; grid-template-columns:120px minmax(0, 1fr) auto; gap:1rem; align-items:center; border:1px solid #e3e7ee; border-radius:10px; padding:.8rem; }
    .event-date, .event-title { font-weight:700; color:#101828; }
    .event-meta { margin-top:.2rem; color:#667085; font-size:.88rem; }
    .event-status { border-radius:999px; padding:.3rem .6rem; font-size:.75rem; font-weight:700; text-transform:capitalize; white-space:nowrap; }
    .status-scheduled { background:#e0f2fe; color:#075985; }
    .status-rescheduled { background:#fef3c7; color:#92400e; }
    .status-done { background:#dcfce7; color:#166534; }
    .empty-state { margin:0; padding:1rem; color:#667085; text-align:center; }
    @media (max-width:760px) { .calendar-card { overflow-x:auto; } .calendar-grid { min-width:680px; } .event-row { grid-template-columns:1fr; gap:.35rem; } }
</style>

<section class="calendar-card">
    <div class="calendar-toolbar">
        <div class="calendar-nav"><a href="?month=<?= calendarEscape($previousMonth) ?>">&larr; Previous</a></div>
        <h2><?= calendarEscape($monthStart->format('F Y')) ?></h2>
        <div class="calendar-nav"><a href="?month=<?= calendarEscape($nextMonth) ?>">Next &rarr;</a></div>
    </div>
    <div class="calendar-grid">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
            <div class="calendar-weekday"><?= calendarEscape($weekday) ?></div>
        <?php endforeach; ?>
        <?php for ($blank = 0; $blank < $firstWeekday; $blank++): ?>
            <div class="calendar-day is-empty" aria-hidden="true"></div>
        <?php endfor; ?>
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dateKey = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $day)->format('Y-m-d');
            $dayEvents = $eventsByDay[$dateKey] ?? [];
        ?>
            <div class="calendar-day<?= $dateKey === $today->format('Y-m-d') ? ' is-today' : '' ?>">
                <div class="day-head">
                    <span><?= $day ?></span>
                    <?php if ($dayEvents): ?><span class="event-count" title="<?= count($dayEvents) ?> event(s)"><?= count($dayEvents) ?></span><?php endif; ?>
                </div>
                <?php foreach (array_slice($dayEvents, 0, 2) as $dayEvent): ?>
                    <div class="day-event" title="<?= calendarEscape($dayEvent['label'] . ': ' . $dayEvent['title']) ?>"><?= calendarEscape($dayEvent['label']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>
</section>

<section class="calendar-card">
    <div class="calendar-toolbar"><h2>Upcoming (next 60 days)</h2></div>
    <?php if (!$upcomingEvents): ?>
        <p class="empty-state">No scheduled research events in the next 60 days.</p>
    <?php else: ?>
        <div class="upcoming-list">
            <?php foreach ($upcomingEvents as $event): $eventDate = new DateTimeImmutable($event['date']); ?>
                <article class="event-row">
                    <div class="event-date"><?= calendarEscape($eventDate->format('M j, Y')) ?><br><small><?= calendarEscape($eventDate->format('g:i A')) ?></small></div>
                    <div>
                        <div class="event-title"><?= calendarEscape($event['title']) ?></div>
                        <div class="event-meta">
                            <?= calendarEscape($event['label']) ?>
                            <?php if ($event['venue'] !== ''): ?>&middot; <?= calendarEscape($event['venue']) ?>
                            <?php elseif ($event['source'] === 'colloquium'): ?>&middot; Venue not recorded<?php endif; ?>
                        </div>
                    </div>
                    <span class="event-status <?= calendarEscape(calendarStatusClass($event['status'])) ?>"><?= calendarEscape(str_replace('_', ' ', $event['status'])) ?></span>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
if ($shell === 'admin') {
    renderAdminShellClose();
} elseif ($shell === 'staff') {
    renderStaffShellClose();
} elseif ($shell === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
