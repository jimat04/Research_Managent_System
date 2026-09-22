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
    if (in_array($role, ['admin', 'research_staff'], true)) {
        return '';
    }
    return ' AND 1 = 0';
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

$requestedDate = isset($_GET['date']) ? (string) $_GET['date'] : '';
$selectedDate = null;
if ($requestedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $requestedDate)) {
    $dateCandidate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate);
    if (
        $dateCandidate
        && $dateCandidate->format('Y-m-d') === $requestedDate
        && $dateCandidate >= $monthStart
        && $dateCandidate < $monthEnd
    ) {
        $selectedDate = $dateCandidate;
    }
}

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
$selectedDayEvents = $selectedDate ? ($eventsByDay[$selectedDate->format('Y-m-d')] ?? []) : [];

$shell = match ($role) {
    'admin' => 'admin', 'research_staff' => 'staff', 'faculty' => 'faculty', default => 'student',
};
$calendarCopy = match ($shell) {
    'admin' => [
        'page_title' => 'Institution Research Calendar',
        'page_subtitle' => 'Monitor every scheduled defense and colloquium across RMS.',
        'eyebrow' => 'Institution schedule oversight',
        'title' => 'See every research date across the institution.',
        'description' => 'Review proposal, pre-oral, final defense, and colloquium activity across all active research projects.',
        'scope_note' => 'Showing institution-wide schedules for administrative oversight.',
        'month_label' => 'Institution events this month',
        'upcoming_label' => 'Institution events ahead',
        'upcoming_title' => 'Institution schedule',
        'upcoming_description' => 'All active research events scheduled within the next 60 days.',
        'empty_upcoming' => 'No institution-wide research events are scheduled in the next 60 days.',
        'modal_kicker' => 'Institution schedule record',
        'event_context' => 'Institution-wide',
        'empty_day_note' => 'No defense or colloquium is scheduled across RMS.',
    ],
    'staff' => [
        'page_title' => 'Research Operations Calendar',
        'page_subtitle' => 'Coordinate upcoming defenses, venues, and colloquia.',
        'eyebrow' => 'Research operations schedule',
        'title' => 'Coordinate every scheduled research milestone.',
        'description' => 'Track the defense and colloquium dates that research offices need to prepare, support, and monitor.',
        'scope_note' => 'Showing operational schedules across active research projects.',
        'month_label' => 'Events to coordinate',
        'upcoming_label' => 'Operations ahead',
        'upcoming_title' => 'Operations queue',
        'upcoming_description' => 'Defense and colloquium activity requiring coordination in the next 60 days.',
        'empty_upcoming' => 'No research operations are scheduled in the next 60 days.',
        'modal_kicker' => 'Operations schedule',
        'event_context' => 'Operations',
        'empty_day_note' => 'No research activity requires coordination on this date.',
    ],
    'faculty' => [
        'page_title' => 'Advisement Calendar',
        'page_subtitle' => 'Schedules for projects you advise or review.',
        'eyebrow' => 'Adviser and reviewer schedule',
        'title' => 'Stay ready for every student research review.',
        'description' => 'See defenses and colloquia only for the projects where you are assigned as an adviser or reviewer.',
        'scope_note' => 'Showing your advised and assigned review projects only.',
        'month_label' => 'Assigned events this month',
        'upcoming_label' => 'Assigned events ahead',
        'upcoming_title' => 'Your review schedule',
        'upcoming_description' => 'Upcoming activity for your advised and reviewed projects within 60 days.',
        'empty_upcoming' => 'No advised or reviewed project events are scheduled in the next 60 days.',
        'modal_kicker' => 'Assigned project schedule',
        'event_context' => 'Assigned project',
        'empty_day_note' => 'None of your advised or reviewed projects is scheduled on this date.',
    ],
    default => [
        'page_title' => 'My Research Calendar',
        'page_subtitle' => 'Defense and colloquium dates for your research projects.',
        'eyebrow' => 'My research schedule',
        'title' => 'Know exactly what comes next in your research.',
        'description' => 'Keep track of defenses and colloquia for projects you lead or participate in as a student researcher.',
        'scope_note' => 'Showing only research projects you created or joined.',
        'month_label' => 'My events this month',
        'upcoming_label' => 'My events ahead',
        'upcoming_title' => 'My upcoming dates',
        'upcoming_description' => 'Your scheduled research activity within the next 60 days.',
        'empty_upcoming' => 'You have no research events scheduled in the next 60 days.',
        'modal_kicker' => 'My schedule',
        'event_context' => 'My project',
        'empty_day_note' => 'You have no research activity scheduled on this date.',
    ],
};
if ($shell === 'admin') {
    renderAdminShell($user, 'calendar.php', $calendarCopy['page_title'], $calendarCopy['page_subtitle']);
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'calendar.php', $calendarCopy['page_title'], $calendarCopy['page_subtitle']);
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'calendar.php', $calendarCopy['page_title'], $calendarCopy['page_subtitle']);
} else {
    renderStudentShell($user, 'calendar.php', $calendarCopy['page_title'], $calendarCopy['page_subtitle']);
}

$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$firstWeekday = (int) $monthStart->format('w');
$daysInMonth = (int) $monthStart->format('t');
$monthEventCount = array_sum(array_map('count', $eventsByDay));
$calendarTheme = match ($shell) {
    'admin' => ['#F57C00', '#C65300', '#FFF3E7', '245,124,0'],
    'staff' => ['#0D9488', '#08756C', '#E8F8F5', '13,148,136'],
    'faculty' => ['#1D4ED8', '#172554', '#EAF0FF', '29,78,216'],
    default => ['#5B1EBC', '#45148F', '#F2EBFB', '91,30,188'],
};
?>

<style>
    .student-page-content{--cal-rgb:91,30,188}.faculty-page-content{--cal-rgb:29,78,216}.staff-page-content{--cal-rgb:13,148,136}.admin-page-content{--cal-rgb:245,124,0}.student-page-content,.faculty-page-content,.staff-page-content,.admin-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 3%,rgba(var(--cal-rgb),.12),transparent 27%),radial-gradient(circle at 7% 47%,rgba(37,99,235,.055),transparent 24%),linear-gradient(180deg,#F5F2F9 0%,#ECE8F2 100%)}
    .calendar-page{--cal-accent:<?= calendarEscape($calendarTheme[0]) ?>;--cal-dark:<?= calendarEscape($calendarTheme[1]) ?>;--cal-tint:<?= calendarEscape($calendarTheme[2]) ?>;--cal-rgb:<?= calendarEscape($calendarTheme[3]) ?>;max-width:1240px;margin:0 auto;padding-bottom:44px;color:#17101F}
    .calendar-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(290px,.7fr);gap:42px;overflow:hidden;margin-bottom:22px;padding:36px 40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 91% 7%,rgba(255,255,255,.19),transparent 28%),radial-gradient(circle at 8% 118%,rgba(26,50,110,.18),transparent 32%),linear-gradient(135deg,var(--cal-dark) 0%,var(--cal-accent) 100%);color:#fff;box-shadow:0 22px 52px rgba(var(--cal-rgb),.2)}.calendar-hero::after{content:'';position:absolute;right:-75px;bottom:-150px;width:300px;height:300px;border:46px solid rgba(255,255,255,.055);border-radius:50%;pointer-events:none}.calendar-hero-copy,.calendar-hero-summary{position:relative;z-index:1}.calendar-eyebrow{margin:0 0 10px;color:rgba(255,255,255,.76);font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.calendar-hero h1{max-width:650px;margin:0;color:#fff;font-size:clamp(31px,3.3vw,46px);font-weight:750;letter-spacing:-.045em;line-height:1.07;text-wrap:balance}.calendar-hero-description{max-width:59ch;margin:16px 0 0;color:rgba(255,255,255,.82);font-size:14px;line-height:1.7}.calendar-scope-note{display:flex;align-items:center;gap:8px;margin:15px 0 0;color:rgba(255,255,255,.72);font-size:10px;font-weight:650;line-height:1.45}.calendar-scope-note::before{content:'';width:7px;height:7px;flex:0 0 7px;border-radius:3px;background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.1)}.calendar-hero-summary{display:grid;grid-template-columns:1fr 1fr;align-content:center;gap:10px;padding-left:34px;border-left:1px solid rgba(255,255,255,.24)}.calendar-hero-stat{padding:15px;border:1px solid rgba(255,255,255,.17);border-radius:13px;background:rgba(255,255,255,.1);backdrop-filter:blur(6px)}.calendar-hero-value{font-size:27px;font-weight:750;font-variant-numeric:tabular-nums}.calendar-hero-label{margin-top:3px;color:rgba(255,255,255,.72);font-size:10px;line-height:1.35}.calendar-legend{grid-column:1/-1;display:flex;gap:14px;margin-top:4px;color:rgba(255,255,255,.76);font-size:9px}.calendar-legend span{display:flex;align-items:center;gap:6px}.calendar-legend i{width:7px;height:7px;border-radius:3px;background:#fff}.calendar-legend span:last-child i{background:#F5C451}
    .calendar-layout{display:grid;grid-template-columns:minmax(0,1.48fr) minmax(300px,.62fr);gap:20px;align-items:start}.calendar-card{overflow:hidden;margin:0;border:1px solid #DCD4E4;border-radius:19px;background:rgba(255,255,255,.96);box-shadow:0 13px 34px rgba(45,24,76,.07)}.calendar-month-card{padding:0}.calendar-side{position:sticky;top:24px;display:grid;gap:16px}.calendar-upcoming-card{padding:0}.calendar-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0;padding:18px 20px;border-bottom:1px solid #E8E1ED;background:#FBF9FC}.calendar-toolbar h2{margin:0;color:#25182F;font-size:17px;font-weight:750;letter-spacing:-.025em}.calendar-upcoming-card .calendar-toolbar{display:block;padding:22px}.calendar-upcoming-card .calendar-toolbar p{margin:6px 0 0;color:#807489;font-size:10px;line-height:1.5}.calendar-nav{display:flex;gap:7px}.calendar-nav a{display:inline-flex;min-height:36px;align-items:center;justify-content:center;padding:8px 11px;border:1px solid #D9CFE1;border-radius:9px;background:#fff;color:#463652;font-size:10px;font-weight:750;text-decoration:none;transition:transform .2s ease,border-color .2s ease,color .2s ease}.calendar-nav a:hover{border-color:var(--cal-accent);color:var(--cal-accent);transform:translateY(-1px)}
    .calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:0}.calendar-weekday,.calendar-day{border-right:1px solid #E9E3EE;border-bottom:1px solid #E9E3EE}.calendar-weekday:nth-child(7n),.calendar-day:nth-child(7n){border-right:0}.calendar-weekday{padding:11px 5px;background:#F5F1F7;color:#80748B;font-size:9px;font-weight:800;letter-spacing:.06em;text-align:center;text-transform:uppercase}.calendar-day{position:relative;display:block;min-height:108px;padding:9px;background:#fff;color:inherit;text-decoration:none;transition:background .18s ease,box-shadow .18s ease}.calendar-day:hover{z-index:1;background:#FBF8FC;box-shadow:inset 0 0 0 2px rgba(var(--cal-rgb),.24)}.calendar-day.is-empty{background:#F7F4F8}.calendar-day.is-today{background:var(--cal-tint);box-shadow:inset 0 0 0 2px var(--cal-accent)}.calendar-day.is-selected{z-index:2;background:var(--cal-tint);box-shadow:inset 0 0 0 3px var(--cal-accent)}.calendar-day.is-today .day-head>span:first-child{display:grid;width:25px;height:25px;place-items:center;border-radius:8px;background:var(--cal-accent);color:#fff}.day-head{display:flex;align-items:center;justify-content:space-between;gap:6px;color:#372A41;font-size:11px;font-weight:750}.event-count{display:inline-grid;min-width:19px;height:19px;padding:0 5px;place-items:center;border-radius:7px;background:var(--cal-accent);color:#fff;font-size:8px}.day-event{overflow:hidden;margin-top:6px;padding:5px 6px;border-left:3px solid var(--cal-accent);border-radius:5px;background:var(--cal-tint);color:var(--cal-dark);font-size:8px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.day-event.source-colloquium{border-left-color:#D99B21;background:#FFF6DD;color:#81530C}
    .calendar-date-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:22px;animation:calendarModalFade .22s ease both}.calendar-modal-backdrop{position:absolute;inset:0;background:rgba(26,17,36,.64);backdrop-filter:blur(7px)}.calendar-modal-panel{position:relative;z-index:1;width:min(100%,490px);max-height:min(720px,calc(100dvh - 44px));overflow:auto;border:1px solid rgba(255,255,255,.72);border-radius:20px;background:#fff;box-shadow:0 34px 90px rgba(35,17,56,.34),0 0 0 1px rgba(var(--cal-rgb),.1);animation:calendarModalRise .3s cubic-bezier(.16,1,.3,1) both}.calendar-date-modal.is-closing{animation:calendarModalFadeOut .18s ease both}.calendar-date-modal.is-closing .calendar-modal-panel{animation:calendarModalDrop .18s ease both}.calendar-modal-accent{height:6px;background:linear-gradient(90deg,var(--cal-dark),var(--cal-accent))}.calendar-modal-close{position:absolute;top:17px;right:18px;z-index:2;display:grid;width:34px;height:34px;place-items:center;border:1px solid rgba(var(--cal-rgb),.16);border-radius:10px;background:rgba(255,255,255,.76);color:var(--cal-dark);font-size:22px;line-height:1;text-decoration:none;transition:transform .2s ease,background .2s ease}.calendar-modal-close:hover{background:#fff;transform:rotate(5deg)}.date-detail-head{display:flex;align-items:center;gap:16px;padding:27px 65px 24px 27px;border-bottom:1px solid #E8E1ED;background:radial-gradient(circle at 90% 0,rgba(var(--cal-rgb),.12),transparent 38%),var(--cal-tint)}.date-detail-mark{display:grid;width:58px;height:58px;flex:0 0 58px;place-items:center;border-radius:15px;background:var(--cal-accent);color:#fff;font-size:23px;font-weight:800;line-height:1;box-shadow:0 10px 24px rgba(var(--cal-rgb),.2)}.date-detail-mark small{display:block;margin-top:3px;font-size:8px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.date-detail-kicker{margin:0 0 6px!important;color:var(--cal-accent)!important;font-size:8px!important;font-weight:850!important;letter-spacing:.1em;text-transform:uppercase}.date-detail-copy h2{margin:0;color:#25182F;font-size:20px;font-weight:800;letter-spacing:-.035em}.date-detail-copy p{margin:5px 0 0;color:#76687F;font-size:10px;line-height:1.45}.date-detail-events{display:grid}.date-detail-event{position:relative;padding:20px 27px 21px;border-bottom:1px solid #EAE4EE}.date-detail-event::before{content:'';position:absolute;left:0;top:20px;bottom:20px;width:4px;border-radius:0 4px 4px 0;background:var(--cal-accent)}.date-detail-event:last-child{border-bottom:0}.date-detail-type{margin:0 0 7px;color:var(--cal-accent);font-size:9px;font-weight:850;letter-spacing:.09em;text-transform:uppercase}.date-detail-title{margin:0;color:#2B1F34;font-size:14px;font-weight:780;line-height:1.45}.date-detail-meta{margin:8px 0 0;color:#81768A;font-size:10px;line-height:1.55}.date-detail-empty{display:grid;min-height:210px;place-items:center;padding:38px 24px;color:#756A7D;text-align:center}.date-detail-empty-icon{display:grid;width:50px;height:50px;margin:0 auto 13px;place-items:center;border-radius:14px;background:var(--cal-tint);color:var(--cal-accent);font-size:20px}.date-detail-empty p{margin:0;font-size:13px;font-weight:750;line-height:1.55}.date-detail-empty small{display:block;margin-top:6px;color:#9A90A1;font-size:9px;font-weight:500}.calendar-modal-footer{display:flex;justify-content:flex-end;padding:15px 27px 20px;border-top:1px solid #EAE4EE;background:#FBF9FC}.calendar-modal-done{display:inline-flex;min-height:38px;align-items:center;justify-content:center;padding:8px 18px;border-radius:9px;background:var(--cal-accent);color:#fff!important;font-size:10px;font-weight:800;text-decoration:none;box-shadow:0 8px 18px rgba(var(--cal-rgb),.18);transition:transform .2s ease,background .2s ease}.calendar-modal-done:hover{background:var(--cal-dark);transform:translateY(-1px)}body.calendar-modal-open{overflow:hidden}
    .upcoming-list{display:grid;gap:0}.event-row{display:grid;grid-template-columns:56px minmax(0,1fr);gap:12px;align-items:start;padding:17px 19px;border:0;border-bottom:1px solid #EAE4EE;border-radius:0;background:#fff;transition:background .18s ease}.event-row:last-child{border-bottom:0}.event-row:hover{background:#FBF8FC}.event-date{display:grid;min-height:53px;align-content:center;padding:7px;border-radius:10px;background:var(--cal-tint);color:var(--cal-dark);font-size:10px;font-weight:800;line-height:1.35;text-align:center}.event-date small{font-size:8px;font-weight:650}.event-title{color:#2B1F34;font-size:11px;font-weight:750;line-height:1.45}.event-meta{margin-top:4px;color:#81768A;font-size:9px;line-height:1.5}.event-context{color:var(--cal-dark);font-weight:800}.event-status{grid-column:2;justify-self:start;padding:4px 8px;border-radius:7px;font-size:8px;font-weight:800;text-transform:capitalize;white-space:nowrap}.status-scheduled{background:#E5F1FF;color:#165F9F}.status-rescheduled{background:#FFF1D7;color:#9A5808}.status-done{background:#E2F6EC;color:#087A59}.empty-state{margin:0;padding:52px 22px;color:#7F7389;font-size:11px;line-height:1.6;text-align:center}.calendar-page a:focus-visible{outline:3px solid rgba(var(--cal-rgb),.24);outline-offset:3px}
    @keyframes calendarEnter{from{opacity:0;transform:translateY(13px)}to{opacity:1;transform:translateY(0)}}@keyframes calendarModalFade{from{opacity:0}to{opacity:1}}@keyframes calendarModalFadeOut{to{opacity:0}}@keyframes calendarModalRise{from{opacity:0;transform:translateY(18px) scale(.975)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes calendarModalDrop{to{opacity:0;transform:translateY(10px) scale(.985)}}@media(prefers-reduced-motion:no-preference){.calendar-hero,.calendar-month-card,.calendar-side{animation:calendarEnter .48s cubic-bezier(.16,1,.3,1) both}.calendar-month-card{animation-delay:.06s}.calendar-side{animation-delay:.12s}}
    @media(max-width:1000px){.calendar-layout{grid-template-columns:1fr}.calendar-side{position:static}.upcoming-list{grid-template-columns:1fr 1fr}.event-row:nth-child(odd){border-right:1px solid #EAE4EE}}
    @media(max-width:760px){.calendar-hero{grid-template-columns:1fr;padding:30px 24px}.calendar-hero-summary{padding:22px 0 0;border-top:1px solid rgba(255,255,255,.24);border-left:0}.calendar-month-card{overflow-x:auto}.calendar-toolbar{position:sticky;left:0;min-width:680px}.calendar-grid{min-width:680px}.calendar-day{min-height:96px}.upcoming-list{grid-template-columns:1fr}.event-row:nth-child(odd){border-right:0}}
    @media(max-width:480px){.calendar-hero{padding:27px 21px}.calendar-toolbar h2{font-size:14px}.calendar-nav a{padding:8px;font-size:9px}.calendar-hero-summary{grid-template-columns:1fr 1fr}.calendar-date-modal{padding:13px}.calendar-modal-panel{max-height:calc(100dvh - 26px);border-radius:16px}.date-detail-head{gap:13px;padding:23px 54px 21px 20px}.date-detail-mark{width:50px;height:50px;flex-basis:50px;font-size:20px}.date-detail-copy h2{font-size:17px}.date-detail-event{padding-inline:21px}.calendar-modal-footer{padding-inline:21px}.calendar-modal-done{width:100%}}
    @media(prefers-reduced-motion:reduce){.calendar-page *{animation:none!important;transition:none!important}}
</style>

<main class="calendar-page">
<section class="calendar-hero" aria-labelledby="calendar-workspace-title">
    <div class="calendar-hero-copy">
        <p class="calendar-eyebrow"><?= calendarEscape($calendarCopy['eyebrow']) ?></p>
        <h1 id="calendar-workspace-title"><?= calendarEscape($calendarCopy['title']) ?></h1>
        <p class="calendar-hero-description"><?= calendarEscape($calendarCopy['description']) ?></p>
        <p class="calendar-scope-note"><?= calendarEscape($calendarCopy['scope_note']) ?></p>
    </div>
    <div class="calendar-hero-summary" aria-label="Calendar summary">
        <div class="calendar-hero-stat"><div class="calendar-hero-value"><?= (int) $monthEventCount ?></div><div class="calendar-hero-label"><?= calendarEscape($calendarCopy['month_label']) ?></div></div>
        <div class="calendar-hero-stat"><div class="calendar-hero-value"><?= count($upcomingEvents) ?></div><div class="calendar-hero-label"><?= calendarEscape($calendarCopy['upcoming_label']) ?></div></div>
        <div class="calendar-legend"><span><i></i> Defense</span><span><i></i> Colloquium</span></div>
    </div>
</section>

<div class="calendar-layout">
<section class="calendar-card calendar-month-card">
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
            $isSelected = $selectedDate && $dateKey === $selectedDate->format('Y-m-d');
            $dayAriaLabel = $monthStart->format('F') . ' ' . $day . ', ' . $monthStart->format('Y');
            $dayAriaLabel .= $dayEvents
                ? ', ' . count($dayEvents) . ' scheduled ' . (count($dayEvents) === 1 ? 'event' : 'events')
                : ', no scheduled events';
        ?>
            <a class="calendar-day<?= $isSelected ? ' is-selected' : '' ?>"
               href="?month=<?= calendarEscape($monthStart->format('Y-m')) ?>&amp;date=<?= calendarEscape($dateKey) ?>"
               aria-label="<?= calendarEscape($dayAriaLabel) ?>"<?= $isSelected ? ' aria-current="date"' : '' ?>>
                <div class="day-head">
                    <span><?= $day ?></span>
                    <?php if ($dayEvents): ?><span class="event-count" title="<?= count($dayEvents) ?> event(s)"><?= count($dayEvents) ?></span><?php endif; ?>
                </div>
                <?php foreach (array_slice($dayEvents, 0, 2) as $dayEvent): ?>
                    <div class="day-event source-<?= calendarEscape($dayEvent['source']) ?>" title="<?= calendarEscape($dayEvent['label'] . ': ' . $dayEvent['title']) ?>"><?= calendarEscape($dayEvent['label']) ?></div>
                <?php endforeach; ?>
            </a>
        <?php endfor; ?>
    </div>
</section>

<aside class="calendar-side">
<section class="calendar-card calendar-upcoming-card">
    <div class="calendar-toolbar"><h2><?= calendarEscape($calendarCopy['upcoming_title']) ?></h2><p><?= calendarEscape($calendarCopy['upcoming_description']) ?></p></div>
    <?php if (!$upcomingEvents): ?>
        <p class="empty-state"><?= calendarEscape($calendarCopy['empty_upcoming']) ?></p>
    <?php else: ?>
        <div class="upcoming-list">
            <?php foreach ($upcomingEvents as $event): $eventDate = new DateTimeImmutable($event['date']); ?>
                <article class="event-row">
                    <div class="event-date"><?= calendarEscape($eventDate->format('M j, Y')) ?><br><small><?= calendarEscape($eventDate->format('g:i A')) ?></small></div>
                    <div>
                        <div class="event-title"><?= calendarEscape($event['title']) ?></div>
                        <div class="event-meta">
                            <span class="event-context"><?= calendarEscape($calendarCopy['event_context']) ?></span> &middot;
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
</aside>
</div>

<?php if ($selectedDate): ?>
<div class="calendar-date-modal" id="calendarDateModal">
    <a class="calendar-modal-backdrop" href="?month=<?= calendarEscape($monthStart->format('Y-m')) ?>" tabindex="-1" aria-label="Close schedule details"></a>
    <section class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="calendar-date-title" aria-describedby="calendar-date-summary">
        <div class="calendar-modal-accent" aria-hidden="true"></div>
        <a class="calendar-modal-close" href="?month=<?= calendarEscape($monthStart->format('Y-m')) ?>" aria-label="Close schedule details">&times;</a>
        <div class="date-detail-head">
            <div class="date-detail-mark">
                <span><?= calendarEscape($selectedDate->format('j')) ?><small><?= calendarEscape($selectedDate->format('M')) ?></small></span>
            </div>
            <div class="date-detail-copy">
                <p class="date-detail-kicker"><?= calendarEscape($calendarCopy['modal_kicker']) ?></p>
                <h2 id="calendar-date-title"><?= calendarEscape($selectedDate->format('l, F j')) ?></h2>
                <p id="calendar-date-summary"><?= calendarEscape($selectedDate->format('Y')) ?> &middot; <?= count($selectedDayEvents) ?> scheduled <?= count($selectedDayEvents) === 1 ? 'event' : 'events' ?></p>
            </div>
        </div>
        <?php if (!$selectedDayEvents): ?>
            <div class="date-detail-empty">
                <div><span class="date-detail-empty-icon" aria-hidden="true">&#10003;</span><p>No schedule for this date.<small><?= calendarEscape($calendarCopy['empty_day_note']) ?></small></p></div>
            </div>
        <?php else: ?>
            <div class="date-detail-events">
                <?php foreach ($selectedDayEvents as $selectedEvent):
                    $selectedEventDate = new DateTimeImmutable($selectedEvent['date']);
                    $selectedEventMeta = [];
                    if ($selectedEventDate->format('H:i') !== '00:00') {
                        $selectedEventMeta[] = $selectedEventDate->format('g:i A');
                    }
                    if ($selectedEvent['venue'] !== '') {
                        $selectedEventMeta[] = (string) $selectedEvent['venue'];
                    } elseif ($selectedEvent['source'] === 'colloquium') {
                        $selectedEventMeta[] = 'Venue not recorded';
                    }
                    if ($selectedEvent['status'] !== '') {
                        $selectedEventMeta[] = ucfirst(str_replace('_', ' ', (string) $selectedEvent['status']));
                    }
                ?>
                    <article class="date-detail-event">
                        <p class="date-detail-type"><?= calendarEscape($calendarCopy['event_context'] . ' / ' . $selectedEvent['label']) ?></p>
                        <h3 class="date-detail-title"><?= calendarEscape($selectedEvent['title']) ?></h3>
                        <?php if ($selectedEventMeta): ?><p class="date-detail-meta"><?= calendarEscape(implode(' / ', $selectedEventMeta)) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="calendar-modal-footer">
            <a class="calendar-modal-done" href="?month=<?= calendarEscape($monthStart->format('Y-m')) ?>">Done</a>
        </div>
    </section>
</div>
<script>
(() => {
    const modal = document.getElementById('calendarDateModal');
    if (!modal) return;

    const closeControls = modal.querySelectorAll('.calendar-modal-close, .calendar-modal-backdrop, .calendar-modal-done');
    const closeButton = modal.querySelector('.calendar-modal-close');
    const doneButton = modal.querySelector('.calendar-modal-done');
    const selectedDay = document.querySelector('.calendar-day.is-selected');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let closing = false;
    document.body.classList.add('calendar-modal-open');
    window.requestAnimationFrame(() => closeButton.focus());

    function dismissModal(event) {
        if (event) event.preventDefault();
        if (closing) return;
        closing = true;
        modal.classList.add('is-closing');
        document.body.classList.remove('calendar-modal-open');
        const url = new URL(window.location.href);
        url.searchParams.delete('date');
        window.history.replaceState({}, '', url);
        if (selectedDay) {
            selectedDay.classList.remove('is-selected');
            selectedDay.removeAttribute('aria-current');
            selectedDay.focus();
        }
        window.setTimeout(() => modal.remove(), reduceMotion ? 0 : 180);
    }

    closeControls.forEach((control) => control.addEventListener('click', dismissModal));
    document.addEventListener('keydown', (event) => {
        if (!document.body.classList.contains('calendar-modal-open')) return;
        if (event.key === 'Escape') {
            dismissModal(event);
            return;
        }
        if (event.key === 'Tab') {
            if (event.shiftKey && document.activeElement === closeButton) {
                event.preventDefault();
                doneButton.focus();
            } else if (!event.shiftKey && document.activeElement === doneButton) {
                event.preventDefault();
                closeButton.focus();
            }
        }
    });
})();
</script>
<?php endif; ?>
</main>

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
