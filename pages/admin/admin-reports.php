<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/services/FacultyParticipationService.php';

requireRole('admin');

$user = getCurrentUser();

$reportRole = (string) ($_GET['role'] ?? 'all');
if (!in_array($reportRole, ['all', 'student', 'faculty'], true)) {
    $reportRole = 'all';
}

function adminReportsMonthlySeries(array $rows, array $months): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[(string) $row['month_key']] = (int) $row['total'];
    }

    return array_map(static function ($month) use ($counts) {
        return [
            'month' => $month['label'],
            'month_key' => $month['key'],
            'count' => $counts[$month['key']] ?? 0,
        ];
    }, $months);
}

function adminReportsFetchMonthlyRows(mysqli $conn, string $sql, string $start, string $end, string $role): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $start, $end, $role, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function adminReportsRenderMonthlyChart(string $id, string $title, array $series, string $color): void
{
    $maxCount = max(1, (int) max(array_column($series, 'count')));
    $axisMax = max(4, (int) (ceil($maxCount / 4) * 4));
    ?>
    <div class="monthly-chart-wrap" role="region" aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> by month, scroll horizontally on small screens" tabindex="0">
      <svg class="monthly-chart" viewBox="0 0 560 260" role="img" aria-labelledby="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-title <?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-description">
        <title id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> over the last six months</title>
        <desc id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-description"><?php
          echo htmlspecialchars(implode('; ', array_map(static function ($row) {
              return $row['month'] . ': ' . (int) $row['count'];
          }, $series)), ENT_QUOTES, 'UTF-8');
        ?></desc>
        <?php for ($tick = 0; $tick <= 4; $tick++): $gridY = 220 - ($tick * 48); ?>
          <line class="chart-grid" x1="44" y1="<?= $gridY ?>" x2="548" y2="<?= $gridY ?>" />
          <text class="chart-axis" x="34" y="<?= $gridY + 4 ?>" text-anchor="end"><?= (int) ($axisMax * $tick / 4) ?></text>
        <?php endfor; ?>
        <?php foreach ($series as $index => $data):
          $columnHeight = round(((int) $data['count'] / $axisMax) * 192, 2);
          $columnX = 68 + ($index * 84);
          $columnY = 220 - $columnHeight;
        ?>
          <rect class="chart-column" x="<?= $columnX ?>" y="<?= $columnY ?>" width="36" height="<?= $columnHeight ?>" rx="4" style="fill:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" />
          <text class="chart-value" x="<?= $columnX + 18 ?>" y="<?= max(18, $columnY - 10) ?>" text-anchor="middle"><?= (int) $data['count'] ?></text>
          <text class="chart-axis" x="<?= $columnX + 18 ?>" y="246" text-anchor="middle"><?= htmlspecialchars($data['month'], ENT_QUOTES, 'UTF-8') ?></text>
        <?php endforeach; ?>
      </svg>
    </div>
    <?php
}

function adminReportsRenderMonthlyAreaChart(string $id, string $title, array $series, string $color, string $unit = 'activities'): void
{
    $maxCount = max(1, (int) max(array_column($series, 'count')));
    $axisMax = max(4, (int) (ceil($maxCount / 4) * 4));
    $points = [];
    foreach ($series as $index => $data) {
        $points[] = [
            'x' => 68 + ($index * 84),
            'y' => round(220 - (((int) $data['count'] / $axisMax) * 180), 2),
            'data' => $data,
        ];
    }
    $lineCommands = [];
    foreach ($points as $index => $point) {
        $lineCommands[] = ($index === 0 ? 'M' : 'L') . ' ' . $point['x'] . ' ' . $point['y'];
    }
    $linePath = implode(' ', $lineCommands);
    $areaPath = $linePath . ' L ' . end($points)['x'] . ' 220 L ' . reset($points)['x'] . ' 220 Z';
    ?>
    <div class="monthly-chart-wrap" role="region" aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> by month, scroll horizontally on small screens" tabindex="0">
      <div class="monthly-chart-tooltip" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-tooltip" role="tooltip" hidden></div>
      <svg class="monthly-chart monthly-area-chart" viewBox="0 0 560 260" role="img" aria-labelledby="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-title <?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-description">
        <title id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> over the last six months</title>
        <desc id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-description"><?php
          echo htmlspecialchars(implode('; ', array_map(static function ($row) {
              return $row['month'] . ': ' . (int) $row['count'];
          }, $series)), ENT_QUOTES, 'UTF-8');
        ?></desc>
        <defs>
          <linearGradient id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-gradient" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" stop-opacity=".32" />
            <stop offset="100%" stop-color="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" stop-opacity=".03" />
          </linearGradient>
        </defs>
        <?php for ($tick = 0; $tick <= 4; $tick++): $gridY = 220 - ($tick * 45); ?>
          <line class="chart-grid" x1="44" y1="<?= $gridY ?>" x2="548" y2="<?= $gridY ?>" />
          <text class="chart-axis" x="34" y="<?= $gridY + 4 ?>" text-anchor="end"><?= (int) ($axisMax * $tick / 4) ?></text>
        <?php endfor; ?>
        <line class="monthly-area-crosshair" x1="0" y1="30" x2="0" y2="220" aria-hidden="true" />
        <path class="monthly-area-fill" d="<?= htmlspecialchars($areaPath, ENT_QUOTES, 'UTF-8') ?>" fill="url(#<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-gradient)" />
        <path class="monthly-area-line" d="<?= htmlspecialchars($linePath, ENT_QUOTES, 'UTF-8') ?>" stroke="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" />
        <?php foreach ($points as $point): $data = $point['data']; ?>
          <circle class="monthly-area-point" cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="5" fill="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" data-tooltip-month="<?= htmlspecialchars($data['month'], ENT_QUOTES, 'UTF-8') ?>" data-tooltip-value="<?= (int) $data['count'] ?>" data-tooltip-unit="<?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>-tooltip" tabindex="0" role="img" aria-label="<?= htmlspecialchars($data['month'] . ': ' . (int) $data['count'] . ' ' . $unit, ENT_QUOTES, 'UTF-8') ?>">
          </circle>
          <text class="chart-axis" x="<?= $point['x'] ?>" y="246" text-anchor="middle"><?= htmlspecialchars($data['month'], ENT_QUOTES, 'UTF-8') ?></text>
        <?php endforeach; ?>
      </svg>
    </div>
    <?php
}

$reportMonths = [];
for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
    $monthDate = new DateTimeImmutable(date('Y-m-01', strtotime("-{$monthOffset} months")));
    $reportMonths[] = ['key' => $monthDate->format('Y-m'), 'label' => $monthDate->format('M Y')];
}
$reportPeriodStart = $reportMonths[0]['key'] . '-01';
$reportPeriodEnd = (new DateTimeImmutable($reportMonths[count($reportMonths) - 1]['key'] . '-01'))->modify('+1 month')->format('Y-m-d');

// Get report statistics
$total_users_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE deleted_at IS NULL AND (? = 'all' OR role = ?)");
$total_users_stmt->bind_param('ss', $reportRole, $reportRole);
$total_users_stmt->execute();
$total_users = (int) ($total_users_stmt->get_result()->fetch_assoc()['count'] ?? 0);
$total_users_stmt->close();
$total_research_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects rp LEFT JOIN users report_user ON report_user.user_id = rp.created_by WHERE rp.deleted_at IS NULL AND (? = 'all' OR (report_user.role = ? AND report_user.deleted_at IS NULL))");
$total_research_stmt->bind_param('ss', $reportRole, $reportRole);
$total_research_stmt->execute();
$total_research = (int) ($total_research_stmt->get_result()->fetch_assoc()['count'] ?? 0);
$total_research_stmt->close();
$completedResearchSql = "SELECT COUNT(*) AS count
       FROM research_projects rp
       LEFT JOIN users report_user ON report_user.user_id = rp.created_by
      WHERE rp.deleted_at IS NULL
        AND (? = 'all' OR (report_user.role = ? AND report_user.deleted_at IS NULL))
        AND (
            SELECT COUNT(DISTINCT c.chapter_number)
              FROM chapters c
             WHERE c.project_id = rp.project_id
               AND c.chapter_number BETWEEN 1 AND 5
               AND c.status = 'approved'
               AND c.deleted_at IS NULL
        ) = 5
        AND EXISTS (
            SELECT 1
              FROM research_submission_versions v
             WHERE v.project_id = rp.project_id
               AND v.submission_type = 'final_manuscript'
               AND v.chapter_id IS NULL
               AND v.status = 'approved'
        )";
$completedResearchStmt = $conn->prepare($completedResearchSql);
$completedResearchStmt->bind_param('ss', $reportRole, $reportRole);
$completedResearchStmt->execute();
$completed_research = (int) ($completedResearchStmt->get_result()->fetch_assoc()['count'] ?? 0);
$completedResearchStmt->close();
$archivedResearchStmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects rp LEFT JOIN users report_user ON report_user.user_id = rp.created_by WHERE rp.deleted_at IS NULL AND rp.status = 'archived' AND (? = 'all' OR (report_user.role = ? AND report_user.deleted_at IS NULL))");
$archivedResearchStmt->bind_param('ss', $reportRole, $reportRole);
$archivedResearchStmt->execute();
$archived_research = (int) ($archivedResearchStmt->get_result()->fetch_assoc()['count'] ?? 0);
$archivedResearchStmt->close();

$moduleTables = [];
foreach (['research_activities', 'activity_participants', 'publications', 'copyright_applications', 'faculty_research_profiles', 'faculty_engagement_records', 'faculty_program_assignments', 'programs'] as $tableName) {
    $tableCheck = $conn->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $tableCheck->bind_param('s', $tableName);
    $tableCheck->execute();
    $moduleTables[$tableName] = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $tableCheck->close();
}

$activityMonthlyRows = [];
if ($moduleTables['research_activities'] && ($reportRole === 'all' || $moduleTables['activity_participants'])) {
    $activityMonthlySql = $reportRole === 'all'
        ? "SELECT DATE_FORMAT(ra.created_at, '%Y-%m') AS month_key, COUNT(*) AS total
             FROM research_activities ra WHERE ra.created_at >= ? AND ra.created_at < ?
            GROUP BY month_key ORDER BY month_key"
        : "SELECT DATE_FORMAT(ra.created_at, '%Y-%m') AS month_key, COUNT(*) AS total
             FROM research_activities ra
            WHERE ra.created_at >= ? AND ra.created_at < ?
              AND EXISTS (
                  SELECT 1 FROM activity_participants ap JOIN users report_user ON report_user.user_id = ap.user_id
                   WHERE ap.activity_id = ra.activity_id AND report_user.role = ? AND report_user.deleted_at IS NULL
              )
            GROUP BY month_key ORDER BY month_key";
    if ($reportRole === 'all') {
        $activityStmt = $conn->prepare($activityMonthlySql);
        $activityStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
        $activityStmt->execute();
        $activityMonthlyResult = $activityStmt->get_result();
        while ($row = $activityMonthlyResult->fetch_assoc()) $activityMonthlyRows[] = $row;
        $activityStmt->close();
    } else {
        $activityStmt = $conn->prepare($activityMonthlySql);
        $activityStmt->bind_param('sss', $reportPeriodStart, $reportPeriodEnd, $reportRole);
        $activityStmt->execute();
        $activityMonthlyResult = $activityStmt->get_result();
        while ($row = $activityMonthlyResult->fetch_assoc()) $activityMonthlyRows[] = $row;
        $activityStmt->close();
    }
}
$monthlyActivities = adminReportsMonthlySeries($activityMonthlyRows, $reportMonths);

$publicationMonthlyRows = [];
if ($moduleTables['publications']) {
    $publicationMonthlyRows = adminReportsFetchMonthlyRows(
        $conn,
        "SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS month_key, COUNT(*) AS total
           FROM publications p LEFT JOIN users report_user ON report_user.user_id = p.researcher_id
          WHERE p.deactivated_at IS NULL AND p.created_at >= ? AND p.created_at < ?
            AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, p.researcher_category) = ?)
          GROUP BY month_key ORDER BY month_key",
        $reportPeriodStart,
        $reportPeriodEnd,
        $reportRole
    );
}
$monthlyPublications = adminReportsMonthlySeries($publicationMonthlyRows, $reportMonths);

$copyrightMonthlyRows = [];
if ($moduleTables['copyright_applications']) {
    $copyrightMonthlyRows = adminReportsFetchMonthlyRows(
        $conn,
        "SELECT DATE_FORMAT(ca.created_at, '%Y-%m') AS month_key, COUNT(*) AS total
           FROM copyright_applications ca LEFT JOIN users report_user ON report_user.user_id = ca.applicant_id
          WHERE ca.deactivated_at IS NULL AND ca.output_type IN ('software','research','instructional_material','module','other') AND ca.created_at >= ? AND ca.created_at < ?
            AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, ca.applicant_category) = ?)
          GROUP BY month_key ORDER BY month_key",
        $reportPeriodStart,
        $reportPeriodEnd,
        $reportRole
    );
}
$monthlyCopyrights = adminReportsMonthlySeries($copyrightMonthlyRows, $reportMonths);

$facultyProjectMonthlyRows = [];
$facultyActivityMonthlyRows = [];
$facultyPublicationMonthlyRows = [];
$facultyCopyrightMonthlyRows = [];
$facultyEngagementMonthlyRows = [];
$facultyProjectMonthlySql = "SELECT DATE_FORMAT(rp.created_at, '%Y-%m') AS month_key, COUNT(DISTINCT rp.project_id) AS total
    FROM research_projects rp WHERE rp.deleted_at IS NULL AND rp.created_at >= ? AND rp.created_at < ?
      AND (EXISTS (SELECT 1 FROM users fu WHERE fu.user_id = rp.created_by AND fu.role = 'faculty' AND fu.deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM users fu WHERE fu.user_id = rp.adviser_id AND fu.role = 'faculty' AND fu.deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM project_advisers pa JOIN users fu ON fu.user_id = pa.adviser_id WHERE pa.project_id = rp.project_id AND fu.role = 'faculty' AND fu.deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM project_members pm JOIN users fu ON fu.user_id = pm.user_id WHERE pm.project_id = rp.project_id AND fu.role = 'faculty' AND fu.deleted_at IS NULL))
    GROUP BY month_key ORDER BY month_key";
$facultyProjectMonthlyStmt = $conn->prepare($facultyProjectMonthlySql);
$facultyProjectMonthlyStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
$facultyProjectMonthlyStmt->execute();
$facultyProjectMonthlyResult = $facultyProjectMonthlyStmt->get_result();
while ($row = $facultyProjectMonthlyResult->fetch_assoc()) $facultyProjectMonthlyRows[] = $row;
$facultyProjectMonthlyStmt->close();
if ($moduleTables['research_activities'] && $moduleTables['activity_participants']) {
    $facultyActivityStmt = $conn->prepare("SELECT DATE_FORMAT(ra.created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM research_activities ra WHERE ra.created_at >= ? AND ra.created_at < ? AND EXISTS (SELECT 1 FROM activity_participants ap JOIN users fu ON fu.user_id = ap.user_id WHERE ap.activity_id = ra.activity_id AND fu.role = 'faculty' AND fu.deleted_at IS NULL) GROUP BY month_key ORDER BY month_key");
    $facultyActivityStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
    $facultyActivityStmt->execute();
    $facultyActivityResult = $facultyActivityStmt->get_result();
    while ($row = $facultyActivityResult->fetch_assoc()) $facultyActivityMonthlyRows[] = $row;
    $facultyActivityStmt->close();
}
if ($moduleTables['publications']) {
    $facultyPublicationStmt = $conn->prepare("SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM publications p LEFT JOIN users fu ON fu.user_id = p.researcher_id WHERE p.deactivated_at IS NULL AND p.created_at >= ? AND p.created_at < ? AND COALESCE(CASE WHEN fu.deleted_at IS NULL THEN fu.role END, p.researcher_category) = 'faculty' GROUP BY month_key ORDER BY month_key");
    $facultyPublicationStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
    $facultyPublicationStmt->execute();
    $facultyPublicationResult = $facultyPublicationStmt->get_result();
    while ($row = $facultyPublicationResult->fetch_assoc()) $facultyPublicationMonthlyRows[] = $row;
    $facultyPublicationStmt->close();
}
if ($moduleTables['copyright_applications']) {
    $facultyCopyrightStmt = $conn->prepare("SELECT DATE_FORMAT(ca.created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM copyright_applications ca LEFT JOIN users fu ON fu.user_id = ca.applicant_id WHERE ca.deactivated_at IS NULL AND ca.output_type IN ('software','research','instructional_material','module','other') AND ca.created_at >= ? AND ca.created_at < ? AND COALESCE(CASE WHEN fu.deleted_at IS NULL THEN fu.role END, ca.applicant_category) = 'faculty' GROUP BY month_key ORDER BY month_key");
    $facultyCopyrightStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
    $facultyCopyrightStmt->execute();
    $facultyCopyrightResult = $facultyCopyrightStmt->get_result();
    while ($row = $facultyCopyrightResult->fetch_assoc()) $facultyCopyrightMonthlyRows[] = $row;
    $facultyCopyrightStmt->close();
}
if ($moduleTables['faculty_engagement_records']) {
    $facultyEngagementStmt = $conn->prepare("SELECT DATE_FORMAT(fer.created_at, '%Y-%m') AS month_key, COUNT(*) AS total FROM faculty_engagement_records fer WHERE fer.created_at >= ? AND fer.created_at < ? AND fer.review_status = 'verified' GROUP BY month_key ORDER BY month_key");
    $facultyEngagementStmt->bind_param('ss', $reportPeriodStart, $reportPeriodEnd);
    $facultyEngagementStmt->execute();
    $facultyEngagementResult = $facultyEngagementStmt->get_result();
    while ($row = $facultyEngagementResult->fetch_assoc()) $facultyEngagementMonthlyRows[] = $row;
    $facultyEngagementStmt->close();
}
$facultyMonthlyCounts = [];
foreach ([$facultyProjectMonthlyRows, $facultyActivityMonthlyRows, $facultyPublicationMonthlyRows, $facultyCopyrightMonthlyRows, $facultyEngagementMonthlyRows] as $facultyRows) {
    foreach ($facultyRows as $row) {
        $facultyMonthlyCounts[(string) $row['month_key']] = ($facultyMonthlyCounts[(string) $row['month_key']] ?? 0) + (int) $row['total'];
    }
}
$monthlyFaculty = array_map(static function ($month) use ($facultyMonthlyCounts) {
    return ['month' => $month['label'], 'month_key' => $month['key'], 'count' => $facultyMonthlyCounts[$month['key']] ?? 0];
}, $reportMonths);

// Get research by status
$status_stmt = $conn->prepare("SELECT rp.status, COUNT(*) AS count FROM research_projects rp LEFT JOIN users report_user ON report_user.user_id = rp.created_by WHERE rp.deleted_at IS NULL AND (? = 'all' OR (report_user.role = ? AND report_user.deleted_at IS NULL)) GROUP BY rp.status ORDER BY count DESC");
$status_stmt->bind_param('ss', $reportRole, $reportRole);
$status_stmt->execute();
$status_query = $status_stmt->get_result();
$research_by_status = [];
while ($row = $status_query->fetch_assoc()) {
    $research_by_status[] = $row;
}
$status_stmt->close();

$status_labels = [
    'draft' => 'Draft',
    'proposal' => 'Proposal',
  'submitted' => 'Submitted',
  'under_review' => 'Under Review',
    'under_crec_review' => 'Committee Review (legacy)',
    'under_erec_review' => 'Ethics Review (legacy)',
  'for_revision' => 'For Revision',
  'revision_required' => 'Revision Required',
  'rejected' => 'Rejected',
  'title_approved' => 'Title Approved',
    'approved' => 'Approved',
    'ongoing' => 'Ongoing',
  'progress_report' => 'Progress Report',
  'terminal_review' => 'Terminal Review',
    'completed' => 'Completed',
    'archived' => 'Archived',
];

$status_colors = [
    'draft' => '#64748b', 'proposal' => '#475569', 'submitted' => '#2563eb',
    'under_review' => '#3b82f6', 'under_crec_review' => '#1d4ed8',
    'under_erec_review' => '#1e40af', 'for_revision' => '#ea580c',
    'revision_required' => '#c2410c', 'rejected' => '#991b1b',
    'title_approved' => '#16a34a', 'approved' => '#15803d', 'ongoing' => '#166534',
    'progress_report' => '#0f766e', 'terminal_review' => '#115e59',
    'completed' => '#059669', 'archived' => '#334155',
];

$activityReport = ['seminars' => 0, 'workshops' => 0, 'scheduled' => 0, 'completed' => 0, 'participants' => 0, 'attended' => 0];
$activityTypeData = [];
$activityTypeLabels = [
    'seminar' => 'Seminars',
    'training' => 'Training',
    'workshop' => 'Workshops',
    'colloquium' => 'Colloquia',
    'conference' => 'Conferences',
];
$activityTypeColors = [
    'seminar' => '#0d9488',
    'training' => '#2563eb',
    'workshop' => '#f57c00',
    'colloquium' => '#7c3aed',
    'conference' => '#64748b',
];
if ($moduleTables['research_activities']) {
    if ($reportRole === 'all') {
        $activityStmt = $conn->prepare("SELECT SUM(activity_type = 'seminar' AND status <> 'cancelled') AS seminars, SUM(activity_type = 'workshop' AND status <> 'cancelled') AS workshops, SUM(status = 'scheduled') AS scheduled, SUM(status = 'completed') AS completed FROM research_activities");
        $activityStmt->execute();
        $activityRow = $activityStmt->get_result()->fetch_assoc() ?: [];
        foreach (['seminars', 'workshops', 'scheduled', 'completed'] as $key) {
            $activityReport[$key] = (int) ($activityRow[$key] ?? 0);
        }
        $activityStmt->close();
    } elseif ($moduleTables['activity_participants']) {
        $activityStmt = $conn->prepare("SELECT SUM(ra.activity_type = 'seminar' AND ra.status <> 'cancelled') AS seminars, SUM(ra.activity_type = 'workshop' AND ra.status <> 'cancelled') AS workshops, SUM(ra.status = 'scheduled') AS scheduled, SUM(ra.status = 'completed') AS completed FROM research_activities ra WHERE EXISTS (SELECT 1 FROM activity_participants ap JOIN users au ON au.user_id = ap.user_id WHERE ap.activity_id = ra.activity_id AND au.role = ? AND au.deleted_at IS NULL)");
        $activityStmt->bind_param('s', $reportRole);
        $activityStmt->execute();
        $activityRow = $activityStmt->get_result()->fetch_assoc() ?: [];
        foreach (['seminars', 'workshops', 'scheduled', 'completed'] as $key) {
            $activityReport[$key] = (int) ($activityRow[$key] ?? 0);
        }
        $activityStmt->close();
    }

    if ($reportRole === 'all' || $moduleTables['activity_participants']) {
        $activityTypesSql = $reportRole === 'all'
            ? "SELECT activity_type, COUNT(*) AS total FROM research_activities WHERE status <> 'cancelled' GROUP BY activity_type ORDER BY total DESC, activity_type ASC"
            : "SELECT ra.activity_type, COUNT(*) AS total FROM research_activities ra WHERE ra.status <> 'cancelled' AND EXISTS (SELECT 1 FROM activity_participants ap JOIN users au ON au.user_id = ap.user_id WHERE ap.activity_id = ra.activity_id AND au.role = ? AND au.deleted_at IS NULL) GROUP BY ra.activity_type ORDER BY total DESC, ra.activity_type ASC";
        $activityTypesStmt = $conn->prepare($activityTypesSql);
        if ($reportRole === 'all') {
            $activityTypesStmt->execute();
        } else {
            $activityTypesStmt->bind_param('s', $reportRole);
            $activityTypesStmt->execute();
        }
        $activityTypesResult = $activityTypesStmt->get_result();
        while ($row = $activityTypesResult->fetch_assoc()) {
            $activityType = (string) $row['activity_type'];
            $activityTypeData[] = [
                'label' => $activityTypeLabels[$activityType] ?? ucfirst(str_replace('_', ' ', $activityType)),
                'value' => (int) $row['total'],
                'color' => $activityTypeColors[$activityType] ?? '#64748b',
            ];
        }
        $activityTypesStmt->close();
    }
}
if ($moduleTables['research_activities'] && $moduleTables['activity_participants']) {
    if ($reportRole === 'all') {
        $attendanceStmt = $conn->prepare('SELECT COUNT(*) AS participants, COALESCE(SUM(attended = 1), 0) AS attended FROM activity_participants');
        $attendanceStmt->execute();
    } else {
        $attendanceStmt = $conn->prepare('SELECT COUNT(*) AS participants, COALESCE(SUM(ap.attended = 1), 0) AS attended FROM activity_participants ap JOIN users au ON au.user_id = ap.user_id WHERE au.role = ? AND au.deleted_at IS NULL');
        $attendanceStmt->bind_param('s', $reportRole);
        $attendanceStmt->execute();
    }
    $attendanceRow = $attendanceStmt->get_result()->fetch_assoc() ?: [];
    $activityReport['participants'] = (int) ($attendanceRow['participants'] ?? 0);
    $activityReport['attended'] = (int) ($attendanceRow['attended'] ?? 0);
    $attendanceStmt->close();
}

$publicationStages = ['archived' => 0, 'legacy' => 0];
$publicationTypes = [];
if ($moduleTables['publications']) {
    $publicationStmt = $conn->prepare("SELECT p.archival_status AS status, COUNT(*) AS total FROM publications p LEFT JOIN users report_user ON report_user.user_id = p.researcher_id WHERE p.deactivated_at IS NULL AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, p.researcher_category) = ?) GROUP BY p.archival_status");
    $publicationStmt->bind_param('ss', $reportRole, $reportRole);
    $publicationStmt->execute();
    $result = $publicationStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['status'], $publicationStages)) $publicationStages[$row['status']] = (int) $row['total'];
    }
    $publicationStmt->close();
    $publicationStmt = $conn->prepare("SELECT p.publication_type, COUNT(*) AS total FROM publications p LEFT JOIN users report_user ON report_user.user_id = p.researcher_id WHERE p.deactivated_at IS NULL AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, p.researcher_category) = ?) GROUP BY p.publication_type ORDER BY total DESC, p.publication_type ASC");
    $publicationStmt->bind_param('ss', $reportRole, $reportRole);
    $publicationStmt->execute();
    $result = $publicationStmt->get_result();
    while ($row = $result->fetch_assoc()) $publicationTypes[] = $row;
    $publicationStmt->close();
}

$copyrightStages = ['archived' => 0, 'legacy' => 0];
$copyrightTypes = [];
$registeredCopyrightsThisYear = 0;
if ($moduleTables['copyright_applications']) {
    $copyrightStmt = $conn->prepare("SELECT ca.archival_status AS status, COUNT(*) AS total FROM copyright_applications ca LEFT JOIN users report_user ON report_user.user_id = ca.applicant_id WHERE ca.deactivated_at IS NULL AND ca.output_type IN ('software','research','instructional_material','module','other') AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, ca.applicant_category) = ?) GROUP BY ca.archival_status");
    $copyrightStmt->bind_param('ss', $reportRole, $reportRole);
    $copyrightStmt->execute();
    $result = $copyrightStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['status'], $copyrightStages)) $copyrightStages[$row['status']] = (int) $row['total'];
    }
    $copyrightStmt->close();
    $copyrightStmt = $conn->prepare("SELECT ca.output_type, COUNT(*) AS total FROM copyright_applications ca LEFT JOIN users report_user ON report_user.user_id = ca.applicant_id WHERE ca.deactivated_at IS NULL AND ca.output_type IN ('software','research','instructional_material','module','other') AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, ca.applicant_category) = ?) GROUP BY ca.output_type ORDER BY total DESC, ca.output_type ASC");
    $copyrightStmt->bind_param('ss', $reportRole, $reportRole);
    $copyrightStmt->execute();
    $result = $copyrightStmt->get_result();
    while ($row = $result->fetch_assoc()) $copyrightTypes[] = $row;
    $copyrightStmt->close();
    $copyrightStmt = $conn->prepare("SELECT COUNT(*) AS total FROM copyright_applications ca LEFT JOIN users report_user ON report_user.user_id = ca.applicant_id WHERE ca.deactivated_at IS NULL AND ca.output_type IN ('software','research','instructional_material','module','other') AND (ca.external_status = 'registered' OR (ca.external_status IS NULL AND ca.status = 'registered' AND NULLIF(ca.copyright_ref_no, '') IS NOT NULL)) AND ca.registered_at >= ? AND (? = 'all' OR COALESCE(CASE WHEN report_user.deleted_at IS NULL THEN report_user.role END, ca.applicant_category) = ?)");
    $registrationYearStart = date('Y') . '-01-01 00:00:00';
    $copyrightStmt->bind_param('sss', $registrationYearStart, $reportRole, $reportRole);
    $copyrightStmt->execute();
    $registeredCopyrightsThisYear = (int) ($copyrightStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $copyrightStmt->close();
}

$facultyOverview = [
    'faculty' => 0,
    'profiled' => 0,
    'verified_engagements' => 0,
    'pending_engagements' => 0,
    'published' => 0,
    'registered_ip' => 0,
    'activity_attendance' => 0,
    'team_memberships' => 0,
    'advising_assignments' => 0,
];
$facultyParticipationSummary = [];
$facultyIndividualParticipation = [];
$facultyProgramRows = [];
$facultyTopContributors = [];

$facultyCountResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'faculty' AND status = 'active' AND deleted_at IS NULL");
$facultyOverview['faculty'] = (int) ($facultyCountResult->fetch_assoc()['total'] ?? 0);

$facultySummaryTotal = $facultyOverview['faculty'];
$facultyParticipationService = new FacultyParticipationService($conn);
$facultyParticipationReport = $facultyParticipationService->getAdminReport([
    'search' => $_GET['faculty_search'] ?? '',
    'department' => $_GET['faculty_department'] ?? '',
    'program_id' => $_GET['faculty_program'] ?? 0,
    'academic_year' => $_GET['faculty_year'] ?? '',
    'activity_type' => $_GET['faculty_activity_type'] ?? 'all',
    'participation_status' => $_GET['faculty_participation_status'] ?? 'all',
    'page' => $_GET['faculty_page'] ?? 1,
]);
$facultyIndividualParticipation = $facultyParticipationReport['rows'];

$facultySummaryTypeLabels = [
    'seminar' => 'Seminars',
    'workshop' => 'Workshops',
    'training' => 'Trainings',
    'colloquium' => 'Colloquia',
    'conference' => 'Conferences',
    'conference_presentation' => 'Verified standalone presentations',
];
$facultySummaryCounts = array_fill_keys(array_values($facultySummaryTypeLabels), 0);
foreach ($facultyParticipationService->getFacultyTypeSummary() as $typeRow) {
    $activityType = (string) $typeRow['activity_type'];
    if (isset($facultySummaryTypeLabels[$activityType])) {
        $facultySummaryCounts[$facultySummaryTypeLabels[$activityType]] = (int) $typeRow['faculty_count'];
    }
}

foreach ($facultySummaryCounts as $label => $count) {
    $facultyParticipationSummary[] = [
        'label' => $label,
        'count' => $count,
        'percentage' => $facultySummaryTotal > 0 ? round(($count / $facultySummaryTotal) * 100, 1) : 0.0,
    ];
}
$facultyParticipationSummary[] = [
    'label' => 'Total faculty members',
    'count' => $facultySummaryTotal,
    'percentage' => $facultySummaryTotal > 0 ? 100.0 : 0.0,
];

if ($moduleTables['faculty_research_profiles']) {
    $facultyProfileStmt = $conn->prepare(
        "SELECT COUNT(*) AS profiled
           FROM faculty_research_profiles frp
           INNER JOIN users u ON u.user_id = frp.user_id
          WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL"
    );
    $facultyProfileStmt->execute();
    $facultyProfileCounts = $facultyProfileStmt->get_result()->fetch_assoc() ?: [];
    $facultyOverview['profiled'] = (int) ($facultyProfileCounts['profiled'] ?? 0);
    $facultyProfileStmt->close();
}

if ($moduleTables['faculty_engagement_records']) {
    $facultyEngagementStmt = $conn->prepare(
        "SELECT COALESCE(SUM(review_status = 'verified'), 0) AS verified_engagements,
                COALESCE(SUM(review_status = 'pending'), 0) AS pending_engagements
           FROM faculty_engagement_records fer
           INNER JOIN users u ON u.user_id = fer.faculty_id
          WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL"
    );
    $facultyEngagementStmt->execute();
    $facultyEngagementCounts = $facultyEngagementStmt->get_result()->fetch_assoc() ?: [];
    foreach (['verified_engagements', 'pending_engagements'] as $key) {
        $facultyOverview[$key] = (int) ($facultyEngagementCounts[$key] ?? 0);
    }
    $facultyEngagementStmt->close();
}

if ($moduleTables['research_activities'] && $moduleTables['activity_participants']) {
    $facultyAttendanceStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT ap.participant_id) AS total
           FROM activity_participants ap
           JOIN research_activities ra ON ra.activity_id = ap.activity_id
           JOIN users u ON u.user_id = ap.user_id
          WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL
            AND ap.attended = 1 AND ra.status = 'completed'"
    );
    $facultyAttendanceStmt->execute();
    $facultyOverview['activity_attendance'] = (int) ($facultyAttendanceStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $facultyAttendanceStmt->close();
}

foreach (['project_members' => 'team_memberships', 'project_advisers' => 'advising_assignments'] as $assignmentTable => $metricKey) {
    $userColumn = $assignmentTable === 'project_members' ? 'user_id' : 'adviser_id';
    $assignmentStmt = $conn->prepare(
        'SELECT COUNT(*) AS total
           FROM ' . $assignmentTable . ' assignments
           JOIN research_projects rp ON rp.project_id = assignments.project_id AND rp.deleted_at IS NULL
           JOIN users u ON u.user_id = assignments.' . $userColumn . '
          WHERE u.role = \'faculty\' AND u.status = \'active\' AND u.deleted_at IS NULL'
    );
    $assignmentStmt->execute();
    $facultyOverview[$metricKey] = (int) ($assignmentStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $assignmentStmt->close();
}

if ($moduleTables['publications']) {
    $facultyPublicationStmt = $conn->prepare("SELECT COUNT(*) AS total FROM publications p INNER JOIN users u ON u.user_id = p.researcher_id WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL AND p.deactivated_at IS NULL AND (p.archival_status='archived' OR p.status='published')");
    $facultyPublicationStmt->execute();
    $facultyOverview['published'] = (int) ($facultyPublicationStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $facultyPublicationStmt->close();
}
if ($moduleTables['copyright_applications']) {
    $facultyIpStmt = $conn->prepare("SELECT COUNT(*) AS total FROM copyright_applications ca INNER JOIN users u ON u.user_id = ca.applicant_id WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL AND ca.deactivated_at IS NULL AND (ca.external_status='registered' OR (ca.external_status IS NULL AND ca.status='registered' AND (ca.output_type IN ('patent','utility_model','innovation','technology_transfer') OR NULLIF(ca.copyright_ref_no,'') IS NOT NULL)))");
    $facultyIpStmt->execute();
    $facultyOverview['registered_ip'] = (int) ($facultyIpStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $facultyIpStmt->close();
}

$facultyAssignmentsAvailable = $moduleTables['faculty_program_assignments'] && $moduleTables['programs'];
$programExpr = $facultyAssignmentsAvailable
    ? "COALESCE(NULLIF(p.program_code, ''), NULLIF(u.program, ''), 'Program not recorded')"
    : "COALESCE(NULLIF(u.program, ''), 'Program not recorded')";
$facultyProgramSelects = [
    $moduleTables['faculty_research_profiles']
        ? 'COUNT(DISTINCT frp.user_id) AS profiles_saved'
        : '0 AS profiles_saved',
    $moduleTables['faculty_engagement_records']
        ? "COUNT(DISTINCT CASE WHEN fer.review_status = 'verified' THEN fer.engagement_id END) AS verified_engagements"
        : '0 AS verified_engagements',
];
$facultyProgramJoins = '';
if ($facultyAssignmentsAvailable) {
    $facultyProgramJoins .= ' LEFT JOIN faculty_program_assignments fpa ON fpa.faculty_id = u.user_id LEFT JOIN programs p ON p.program_id = fpa.program_id';
}
if ($moduleTables['faculty_research_profiles']) {
    $facultyProgramJoins .= ' LEFT JOIN faculty_research_profiles frp ON frp.user_id = u.user_id';
}
if ($moduleTables['faculty_engagement_records']) {
    $facultyProgramJoins .= ' LEFT JOIN faculty_engagement_records fer ON fer.faculty_id = u.user_id';
}
$facultyProgramSql = 'SELECT ' . $programExpr . ' AS program, '
    . 'COUNT(DISTINCT u.user_id) AS faculty_count, ' . implode(', ', $facultyProgramSelects)
    . ' FROM users u' . $facultyProgramJoins
    . " WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL"
    . ' GROUP BY ' . $programExpr
    . ' ORDER BY faculty_count DESC, program ASC LIMIT 20';
$facultyProgramStmt = $conn->prepare($facultyProgramSql);
$facultyProgramStmt->execute();
$facultyProgramResult = $facultyProgramStmt->get_result();
while ($row = $facultyProgramResult->fetch_assoc()) {
    $facultyProgramRows[] = $row;
}
$facultyProgramStmt->close();

$publicationCountSql = $moduleTables['publications']
    ? "(SELECT COUNT(*) FROM publications p WHERE p.researcher_id = u.user_id AND p.deactivated_at IS NULL AND (p.archival_status='archived' OR p.status='published'))"
    : '0';
$engagementCountSql = $moduleTables['faculty_engagement_records']
    ? "(SELECT COUNT(*) FROM faculty_engagement_records fer WHERE fer.faculty_id = u.user_id AND fer.review_status = 'verified')"
    : '0';
$registeredIpCountSql = $moduleTables['copyright_applications']
    ? "(SELECT COUNT(*) FROM copyright_applications ca WHERE ca.applicant_id = u.user_id AND ca.deactivated_at IS NULL AND (ca.external_status='registered' OR (ca.external_status IS NULL AND ca.status='registered' AND (ca.output_type IN ('patent','utility_model','innovation','technology_transfer') OR NULLIF(ca.copyright_ref_no,'') IS NOT NULL))))"
    : '0';
$activityAttendanceCountSql = $moduleTables['research_activities'] && $moduleTables['activity_participants']
    ? "(SELECT COUNT(DISTINCT ap.participant_id) FROM activity_participants ap JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE ap.user_id = u.user_id AND ap.attended = 1 AND ra.status = 'completed')"
    : '0';
$teamMembershipCountSql = '(SELECT COUNT(*) FROM project_members pm JOIN research_projects rp ON rp.project_id = pm.project_id AND rp.deleted_at IS NULL WHERE pm.user_id = u.user_id)';
$advisingAssignmentCountSql = '(SELECT COUNT(*) FROM project_advisers pa JOIN research_projects rp ON rp.project_id = pa.project_id AND rp.deleted_at IS NULL WHERE pa.adviser_id = u.user_id)';
$facultyProjectCountSql = "(SELECT COUNT(DISTINCT rp.project_id) FROM research_projects rp
    WHERE rp.deleted_at IS NULL AND (
      rp.created_by = u.user_id OR rp.adviser_id = u.user_id
      OR EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = u.user_id)
      OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id)
    ))";
$facultyContributorSql = 'SELECT u.user_id, u.first_name, u.last_name, u.program, u.academic_rank, '
    . $facultyProjectCountSql . ' AS project_count, '
    . $publicationCountSql . ' AS published_count, '
    . $engagementCountSql . ' AS verified_engagement_count, '
    . $registeredIpCountSql . ' AS registered_ip_count, '
    . $activityAttendanceCountSql . ' AS activity_attendance_count, '
    . $teamMembershipCountSql . ' AS team_membership_count, '
    . $advisingAssignmentCountSql . ' AS advising_assignment_count '
    . ', (' . $facultyProjectCountSql . ' + ' . $publicationCountSql . ' + ' . $engagementCountSql . ' + ' . $registeredIpCountSql . ' + ' . $activityAttendanceCountSql . ' + ' . $teamMembershipCountSql . ' + ' . $advisingAssignmentCountSql . ') AS contribution_count '
    . "FROM users u WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL "
    . 'ORDER BY contribution_count DESC, published_count DESC, verified_engagement_count DESC, registered_ip_count DESC, project_count DESC, u.last_name ASC, u.first_name ASC LIMIT 20';
$facultyContributorResult = $conn->query($facultyContributorSql);
while ($row = $facultyContributorResult->fetch_assoc()) {
    $facultyTopContributors[] = $row;
}
$reportPageValues = ['development', 'capacity', 'dissemination', 'ip', 'faculty'];
$selectedReportValue = (string) ($_GET['report'] ?? 'development');
$selectedReport = in_array($selectedReportValue, $reportPageValues, true)
    ? $selectedReportValue
    : 'development';
$selectedReports = $selectedReport === 'faculty' ? ['faculty'] : ['development'];
if (isset($_GET['reports_submitted'])) {
    $requestedReports = $_GET['reports'] ?? [];
    $requestedReports = is_array($requestedReports) ? $requestedReports : [];
    $selectedReports = array_values(array_intersect($reportPageValues, array_map('strval', $requestedReports)));
}
if ($reportRole === 'student') {
    $selectedReports = array_values(array_diff($selectedReports, ['faculty']));
    if ($selectedReport === 'faculty') {
        $selectedReport = 'development';
        if (!isset($_GET['reports_submitted'])) {
            $selectedReports = ['development'];
        }
    }
}

renderAdminShell(
    $user,
    'admin-reports',
    'Reports & Analytics',
    'Research, activities, publications and faculty reports.'
);

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<link rel="stylesheet" href="<?= htmlspecialchars(SITE_URL . 'css/admin-reports.css?v=' . filemtime(__DIR__ . '/../../css/admin-reports.css'), ENT_QUOTES, 'UTF-8') ?>">


<div class="reports-workspace">
  <header class="report-summary">
    <div class="report-summary-copy">
      <p class="report-eyebrow">Reports &amp; analytics</p>
      <h1>Research reports</h1>
      <p class="report-summary-description">A current view of research projects, activities, publications, copyrights, and faculty contributions.</p>
    </div>
    <div class="report-date-card">
      <span>Report date</span>
      <time datetime="<?= date('Y-m-d') ?>"><?= date('F j, Y') ?></time>
    </div>
  </header>

  <section class="report-filter-panel" aria-labelledby="report-filter-heading">
    <div class="report-filter-copy">
      <p class="report-section-eyebrow">Report scope</p>
      <h2 id="report-filter-heading">Choose a researcher group</h2>
      <p>Projects follow the creator, activities follow linked participants, and outputs follow the recorded researcher or applicant. Faculty statistics stay faculty-only.</p>
    </div>
    <form class="report-filter" id="report-role-filter-form" method="get" aria-label="Filter reports by researcher role">
      <label for="report-role-filter">Researcher group
        <select id="report-role-filter" name="role">
          <option value="all" <?= $reportRole === 'all' ? 'selected' : '' ?>>All students and faculty</option>
          <option value="student" <?= $reportRole === 'student' ? 'selected' : '' ?>>Students</option>
          <option value="faculty" <?= $reportRole === 'faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>
      </label>
      <input type="hidden" name="report" value="<?= htmlspecialchars($selectedReport, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit">Apply filter</button>
    </form>
  </section>

  <section class="report-overview" aria-labelledby="report-overview-heading">
    <header class="report-section-heading">
      <div>
        <p class="report-section-eyebrow">Portfolio overview</p>
        <h2 id="report-overview-heading">Research at a glance</h2>
      </div>
      <p>Totals for the selected researcher group</p>
    </header>
    <div class="report-metrics" aria-label="Portfolio totals for the selected researcher group">
      <article class="report-metric metric-users"><div class="metric-label">Registered users</div><div class="metric-value"><?php echo (int) $total_users; ?></div></article>
      <article class="report-metric metric-portfolio"><div class="metric-label">Research projects</div><div class="metric-value"><?php echo (int) $total_research; ?></div></article>
      <article class="report-metric metric-completed"><div class="metric-label">Completed projects</div><div class="metric-value"><?php echo (int) $completed_research; ?></div></article>
      <article class="report-metric metric-archive"><div class="metric-label">Archived projects</div><div class="metric-value"><?php echo (int) $archived_research; ?></div></article>
    </div>
  </section>

  <section class="report-controls" aria-label="Report selection and printing">
    <div class="report-controls-copy"><p class="report-section-eyebrow">Report builder</p><strong id="selected-report-title" aria-live="polite">1 report selected</strong><span id="selected-report-description">Choose reports to view and print. Each prints separately.</span></div>
    <div class="report-controls-actions">
      <div class="report-selector" role="group" aria-label="Select report pages">
        <label class="report-choice"><input type="checkbox" data-report-choice="development" value="development" <?= in_array('development', $selectedReports, true) ? 'checked' : '' ?>><span class="report-choice-index" aria-hidden="true">01</span><span>Research</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="capacity" value="capacity" <?= in_array('capacity', $selectedReports, true) ? 'checked' : '' ?>><span class="report-choice-index" aria-hidden="true">02</span><span>Activities</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="dissemination" value="dissemination" <?= in_array('dissemination', $selectedReports, true) ? 'checked' : '' ?>><span class="report-choice-index" aria-hidden="true">03</span><span>Publications</span></label>
        <label class="report-choice"><input type="checkbox" data-report-choice="ip" value="ip" <?= in_array('ip', $selectedReports, true) ? 'checked' : '' ?>><span class="report-choice-index" aria-hidden="true">04</span><span>Copyrights</span></label>
        <?php if ($reportRole !== 'student'): ?>
          <label class="report-choice"><input type="checkbox" data-report-choice="faculty" value="faculty" <?= in_array('faculty', $selectedReports, true) ? 'checked' : '' ?>><span class="report-choice-index" aria-hidden="true">05</span><span>Faculty</span></label>
        <?php endif; ?>
      </div>
      <button class="report-print" type="button" id="print-report" aria-describedby="selected-report-description report-print-status">
        <span class="report-print-icon" aria-hidden="true">🖨</span>
        <span class="report-print-label">Print selected report (1)</span>
      </button>
      <p class="report-print-status" id="report-print-status" aria-live="polite"></p>
    </div>
  </section>

  <section class="report-page analytics-grid research-overview-grid" data-report-page="development" data-report-title="Research overview">
    <h2 class="report-page-heading">Research overview</h2>
    <section class="report-panel panel-status" aria-labelledby="status-distribution-title">
      <header class="report-panel-header">
        <div><h3 class="report-title" id="status-distribution-title">Research status</h3><p class="report-copy">Projects by current status.</p></div>
      </header>
      <?php if (empty($research_by_status)): ?>
        <div class="report-empty"><strong>No research projects yet</strong><p>Status counts will appear here.</p></div>
      <?php else: ?>
        <?php $status_total = (int) array_sum(array_column($research_by_status, 'count')); ?>
        <div class="report-donut-layout" data-default-label="projects">
          <svg class="status-donut" viewBox="0 0 200 200" aria-hidden="true" focusable="false">
            <circle cx="100" cy="100" r="74" fill="none" stroke="#e5e7eb" stroke-width="22" />
            <?php $status_offset = 0;
            foreach ($research_by_status as $index => $data):
              $share = $status_total > 0 ? ((int) $data['count'] / $status_total) * 100 : 0;
              $color = $status_colors[$data['status']] ?? '#64748b';
            ?>
              <circle class="report-donut-slice" data-slice-index="<?= (int) $index ?>" data-slice-label="<?= htmlspecialchars($status_labels[$data['status']] ?? ucfirst(str_replace('_', ' ', (string) $data['status'])), ENT_QUOTES, 'UTF-8') ?>" data-slice-count="<?= (int) $data['count'] ?>" cx="100" cy="100" r="74" fill="none" stroke="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" stroke-width="22" pathLength="100" stroke-dasharray="<?= number_format($share, 6, '.', '') ?> <?= number_format(100 - $share, 6, '.', '') ?>" stroke-dashoffset="<?= number_format(-$status_offset, 6, '.', '') ?>" transform="rotate(-90 100 100)" />
            <?php $status_offset += $share; endforeach; ?>
            <text class="donut-total" data-slice-center-value x="100" y="102" text-anchor="middle"><?= $status_total ?></text>
            <text class="donut-caption" data-slice-center-label x="100" y="123" text-anchor="middle">projects</text>
          </svg>
          <ul class="status-legend" aria-label="Research status counts">
          <?php foreach ($research_by_status as $index => $data):
            $status_label = $status_labels[$data['status']] ?? ucfirst(str_replace('_', ' ', (string) $data['status']));
            $color = $status_colors[$data['status']] ?? '#64748b';
            $share = $status_total > 0 ? ((int) $data['count'] / $status_total) * 100 : 0;
          ?>
            <li><button class="report-donut-legend-button" type="button" data-slice-index="<?= (int) $index ?>" aria-label="<?= htmlspecialchars($status_label . ': ' . (int) $data['count'] . ' projects, ' . number_format($share, 1) . '%', ENT_QUOTES, 'UTF-8') ?>"><span class="status-key" style="--status-color:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span><span class="report-donut-legend-label"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?><small><?= number_format($share, 1) ?>%</small></span><strong><?= (int) $data['count'] ?></strong></button></li>
          <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <section class="report-panel" aria-labelledby="activity-type-distribution-title">
      <header class="report-panel-header">
        <div><h3 class="report-title" id="activity-type-distribution-title">Research activities</h3><p class="report-copy">Activity distribution by type<?= $reportRole === 'all' ? '' : ' for the selected researcher role' ?>; cancelled activities are excluded.</p></div>
      </header>
      <?php if (!$moduleTables['research_activities'] || ($reportRole !== 'all' && !$moduleTables['activity_participants'])): ?>
        <div class="report-empty"><strong>Activity type data is unavailable</strong><p>The required activity records or participant links are not available.</p></div>
      <?php else: ?>
        <?php
          $activityTypeTotal = array_sum(array_column($activityTypeData, 'value'));
        ?>
        <?php if ($activityTypeTotal === 0): ?>
          <div class="report-empty"><strong>No research activities yet</strong><p>Activity type counts will appear here.</p></div>
        <?php else: ?>
          <div class="report-donut-layout" data-default-label="activities">
            <svg class="status-donut" viewBox="0 0 200 200" aria-hidden="true" focusable="false">
              <circle cx="100" cy="100" r="74" fill="none" stroke="#e5e7eb" stroke-width="22" />
              <?php $activityTypeOffset = 0;
              foreach ($activityTypeData as $index => $activityType):
                $activityTypeShare = ($activityType['value'] / $activityTypeTotal) * 100;
              ?>
                <circle class="report-donut-slice" data-slice-index="<?= (int) $index ?>" data-slice-label="<?= htmlspecialchars($activityType['label'], ENT_QUOTES, 'UTF-8') ?>" data-slice-count="<?= (int) $activityType['value'] ?>" cx="100" cy="100" r="74" fill="none" stroke="<?= htmlspecialchars($activityType['color'], ENT_QUOTES, 'UTF-8') ?>" stroke-width="22" pathLength="100" stroke-dasharray="<?= number_format($activityTypeShare, 6, '.', '') ?> <?= number_format(100 - $activityTypeShare, 6, '.', '') ?>" stroke-dashoffset="<?= number_format(-$activityTypeOffset, 6, '.', '') ?>" transform="rotate(-90 100 100)" />
              <?php $activityTypeOffset += $activityTypeShare; endforeach; ?>
              <text class="donut-total" data-slice-center-value x="100" y="102" text-anchor="middle"><?= (int) $activityTypeTotal ?></text>
              <text class="donut-caption" data-slice-center-label x="100" y="123" text-anchor="middle">activities</text>
            </svg>
            <ul class="status-legend" aria-label="Research activity type counts">
              <?php foreach ($activityTypeData as $index => $activityType):
                $activityTypeShare = ($activityType['value'] / $activityTypeTotal) * 100;
              ?>
                <li><button class="report-donut-legend-button" type="button" data-slice-index="<?= (int) $index ?>" aria-label="<?= htmlspecialchars($activityType['label'] . ': ' . (int) $activityType['value'] . ' activities, ' . number_format($activityTypeShare, 1) . '%', ENT_QUOTES, 'UTF-8') ?>"><span class="status-key" style="--status-color:<?= htmlspecialchars($activityType['color'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span><span class="report-donut-legend-label"><?= htmlspecialchars($activityType['label'], ENT_QUOTES, 'UTF-8') ?><small><?= number_format($activityTypeShare, 1) ?>%</small></span><strong><?= (int) $activityType['value'] ?></strong></button></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

  </section>

  <section class="module-reports" id="module-portfolio" aria-label="Module reports">
    <div class="module-report-grid">
      <article class="module-report-card report-page" data-report-page="capacity" data-report-title="Activities">
        <h2 class="report-page-heading">Activities</h2>
        <header><h3>Research activities</h3><p>Seminars, workshops and attendance.</p></header>
        <?php if ($moduleTables['research_activities']): ?>
          <div class="module-report-list">
            <div class="module-report-row"><span>Seminars</span><strong><?= $activityReport['seminars'] ?></strong></div>
            <div class="module-report-row"><span>Workshops</span><strong><?= $activityReport['workshops'] ?></strong></div>
            <div class="module-report-row"><span>Scheduled / completed</span><strong><?= $activityReport['scheduled'] ?> / <?= $activityReport['completed'] ?></strong></div>
            <div class="module-report-row"><span>Recorded attendance</span><strong><?= $activityReport['attended'] ?> / <?= $activityReport['participants'] ?></strong></div>
          </div>
        <?php else: ?><div class="module-report-empty">Research activity data is unavailable.</div><?php endif; ?>
        <?php if (!$moduleTables['research_activities'] || ($reportRole !== 'all' && !$moduleTables['activity_participants'])): ?>
          <p class="module-report-empty">Monthly activity counts are unavailable for this filter.</p>
        <?php else: ?>
          <section class="report-trend" aria-labelledby="activities-trend-heading">
            <header class="report-panel-header"><div><h3 id="activities-trend-heading">Activities by month</h3><p class="report-copy">Records created per month.</p></div><span class="report-tag">Last 6 months</span></header>
            <?php adminReportsRenderMonthlyAreaChart('activities-monthly', 'Research activities', $monthlyActivities, '#0d9488', 'activities'); ?>
          </section>
        <?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/shared/activities.php', ENT_QUOTES, 'UTF-8') ?>">Open activity records</a></footer>
      </article>

      <article class="module-report-card report-page" data-report-page="dissemination" data-report-title="Publications">
        <h2 class="report-page-heading">Publications</h2>
        <header><h3>Publication monitoring</h3><p>Active archive records by archival status and type.</p></header>
        <?php if ($moduleTables['publications']): ?>
          <div class="module-report-list">
            <?php foreach (['archived' => 'Archived', 'legacy' => 'Legacy records'] as $stage => $label): ?>
              <div class="module-report-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= $publicationStages[$stage] ?></strong></div>
            <?php endforeach; ?>
            <?php foreach ($publicationTypes as $typeRow): ?>
              <div class="module-report-row"><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $typeRow['publication_type'])), ENT_QUOTES, 'UTF-8') ?></span><strong><?= (int) $typeRow['total'] ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="module-report-empty">Publication data is unavailable.</div><?php endif; ?>
        <?php if (!$moduleTables['publications']): ?>
          <p class="module-report-empty">Monthly publication counts are unavailable.</p>
        <?php else: ?>
          <section class="report-trend" aria-labelledby="publications-trend-heading">
            <header class="report-panel-header"><div><h3 id="publications-trend-heading">Publications by month</h3><p class="report-copy">Records created per month.</p></div><span class="report-tag">Last 6 months</span></header>
            <?php adminReportsRenderMonthlyAreaChart('publications-monthly', 'Publications', $monthlyPublications, '#2563eb', 'publications'); ?>
          </section>
        <?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/admin/admin-publication-reports.php', ENT_QUOTES, 'UTF-8') ?>">Open publication monitoring</a></footer>
      </article>

      <article class="module-report-card report-page" data-report-page="ip" data-report-title="Copyrights">
        <h2 class="report-page-heading">Copyrights</h2>
        <header><h3>Copyrights and research outputs</h3><p>Active copyright records and documented registration outcomes.</p></header>
        <?php if ($moduleTables['copyright_applications']): ?>
          <div class="module-report-list">
            <?php foreach (['archived' => 'Archived', 'legacy' => 'Legacy records'] as $stage => $label): ?>
              <div class="module-report-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= $copyrightStages[$stage] ?></strong></div>
            <?php endforeach; ?>
            <div class="module-report-row"><span>Registered this year</span><strong><?= $registeredCopyrightsThisYear ?></strong></div>
            <?php foreach ($copyrightTypes as $typeRow): ?>
              <div class="module-report-row"><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $typeRow['output_type'])), ENT_QUOTES, 'UTF-8') ?> outputs</span><strong><?= (int) $typeRow['total'] ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="module-report-empty">Copyright data is unavailable.</div><?php endif; ?>
        <?php if (!$moduleTables['copyright_applications']): ?>
          <p class="module-report-empty">Monthly copyright counts are unavailable.</p>
        <?php else: ?>
          <section class="report-trend" aria-labelledby="copyrights-trend-heading">
            <header class="report-panel-header"><div><h3 id="copyrights-trend-heading">Copyrights by month</h3><p class="report-copy">Records created per month.</p></div><span class="report-tag">Last 6 months</span></header>
            <?php adminReportsRenderMonthlyAreaChart('copyrights-monthly', 'Copyright applications', $monthlyCopyrights, '#7c3aed', 'applications'); ?>
          </section>
        <?php endif; ?>
        <footer><a href="<?= htmlspecialchars(SITE_URL . 'pages/shared/copyrights.php?report=1', ENT_QUOTES, 'UTF-8') ?>">Open copyright monitoring</a></footer>
      </article>

      <?php if ($reportRole !== 'student'): ?>
      <article class="module-report-card report-page faculty-analytics-report" data-report-page="faculty" data-report-title="Faculty Research Analytics">
        <h2 class="report-page-heading">Faculty Research Analytics</h2>
        <header><h3>Faculty research and engagement</h3><p>Faculty projects, outputs and verified engagement.</p></header>
        <section class="report-trend faculty-monthly-trend" aria-labelledby="faculty-trend-heading">
          <header class="report-panel-header"><div><h3 id="faculty-trend-heading">Faculty-linked records by month</h3><p class="report-copy">Projects, activities, publications, copyrights, and verified engagements created per month.</p></div><span class="report-tag">Last 6 months</span></header>
          <?php adminReportsRenderMonthlyAreaChart('faculty-monthly', 'Faculty-linked records', $monthlyFaculty, '#1d4ed8', 'records'); ?>
          <?php if (!$moduleTables['research_activities'] || !$moduleTables['activity_participants'] || !$moduleTables['publications'] || !$moduleTables['copyright_applications'] || !$moduleTables['faculty_engagement_records']): ?>
            <p class="faculty-report-note">This trend includes available linked records; some module data is unavailable.</p>
          <?php endif; ?>
        </section>
        <div class="faculty-report-metrics" aria-label="Faculty research indicators">
          <div class="faculty-report-metric"><span>Active faculty accounts</span><strong><?= $facultyOverview['faculty'] ?></strong></div>
          <div class="faculty-report-metric"><span>Verified engagement records</span><strong><?= $facultyOverview['verified_engagements'] ?></strong></div>
          <div class="faculty-report-metric"><span>Engagement records awaiting review</span><strong><?= $facultyOverview['pending_engagements'] ?></strong></div>
          <div class="faculty-report-metric"><span>Verified seminar / activity attendance</span><strong><?= $facultyOverview['activity_attendance'] ?></strong></div>
          <div class="faculty-report-metric"><span>Faculty research team memberships</span><strong><?= $facultyOverview['team_memberships'] ?></strong></div>
          <div class="faculty-report-metric"><span>Faculty advising assignments</span><strong><?= $facultyOverview['advising_assignments'] ?></strong></div>
          <div class="faculty-report-metric"><span>Published faculty outputs</span><strong><?= $facultyOverview['published'] ?></strong></div>
          <div class="faculty-report-metric"><span>Registered faculty IP outputs</span><strong><?= $facultyOverview['registered_ip'] ?></strong></div>
          <div class="faculty-report-metric"><span>Faculty with a profile record</span><strong><?= $facultyOverview['profiled'] ?></strong></div>
        </div>
        <section class="faculty-report-section" aria-labelledby="faculty-participation-summary-heading">
          <h4 id="faculty-participation-summary-heading">1. Summary of Faculty Participation</h4>
          <?php if (!$facultyParticipationSummary): ?>
            <p class="module-report-empty">No faculty participation totals are available.</p>
          <?php else: ?>
            <div class="faculty-summary-table-wrap">
              <table class="faculty-summary-table">
                <thead>
                  <tr>
                    <th>Participation type</th>
                    <th>Faculty with participation</th>
                    <th>Percentage</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($facultyParticipationSummary as $row): ?>
                    <tr>
                      <td class="faculty-summary-label"><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= (int) $row['count'] ?></td>
                      <td><?= number_format((float) $row['percentage'], 1, '.', '') ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
        <section class="faculty-report-section" aria-labelledby="faculty-individual-participation-heading">
          <h4 id="faculty-individual-participation-heading">2. Individual Faculty Participation Summary</h4>
          <?php
            $facultyFilters = $facultyParticipationReport['filters'];
            $facultyOptions = $facultyParticipationReport['options'];
            $facultyActivityTypeLabels = [
                'all' => 'All activity types',
                'seminar' => 'Seminars',
                'training' => 'Trainings',
                'workshop' => 'Workshops',
                'colloquium' => 'Colloquia',
                'conference' => 'Conferences',
                'conference_presentation' => 'Standalone verified presentations',
            ];
            $facultyPageQuery = [
                'role' => $reportRole,
                'report' => 'faculty',
                'reports_submitted' => '1',
                'reports' => $selectedReports,
                'faculty_search' => $facultyFilters['search'],
                'faculty_department' => $facultyFilters['department'],
                'faculty_program' => $facultyFilters['program_id'],
                'faculty_year' => $facultyFilters['academic_year'],
                'faculty_activity_type' => $facultyFilters['activity_type'],
                'faculty_participation_status' => $facultyFilters['participation_status'],
            ];
          ?>
          <form class="faculty-participation-filters" method="get" aria-label="Filter individual faculty participation">
            <input type="hidden" name="role" value="<?= htmlspecialchars($reportRole, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="report" value="faculty">
            <input type="hidden" name="reports_submitted" value="1">
            <?php foreach ($selectedReports as $selectedPage): ?>
              <input type="hidden" name="reports[]" value="<?= htmlspecialchars((string) $selectedPage, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
            <label for="faculty-participation-search">Search faculty
              <input id="faculty-participation-search" type="search" name="faculty_search" maxlength="120" value="<?= htmlspecialchars($facultyFilters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Name or email">
            </label>
            <label for="faculty-participation-department">Department
              <select id="faculty-participation-department" name="faculty_department">
                <option value="">All departments</option>
                <?php foreach ($facultyOptions['departments'] as $department): ?>
                  <option value="<?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?>" <?= $facultyFilters['department'] === $department ? 'selected' : '' ?>><?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="faculty-participation-program">Program
              <select id="faculty-participation-program" name="faculty_program" <?= !$facultyOptions['program_filter_available'] ? 'disabled' : '' ?>>
                <option value="0">All programs</option>
                <?php foreach ($facultyOptions['programs'] as $program): ?>
                  <option value="<?= (int) $program['program_id'] ?>" <?= $facultyFilters['program_id'] === (int) $program['program_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $program['label'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="faculty-participation-year">Academic year
              <select id="faculty-participation-year" name="faculty_year">
                <option value="">All years</option>
                <?php foreach ($facultyOptions['academic_years'] as $year): ?>
                  <option value="<?= (int) $year ?>" <?= $facultyFilters['academic_year'] === (string) $year ? 'selected' : '' ?>><?= (int) $year ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="faculty-participation-type">Activity type
              <select id="faculty-participation-type" name="faculty_activity_type">
                <?php foreach ($facultyActivityTypeLabels as $type => $label): ?>
                  <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $facultyFilters['activity_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="faculty-participation-status">Participation status
              <select id="faculty-participation-status" name="faculty_participation_status">
                <option value="all" <?= $facultyFilters['participation_status'] === 'all' ? 'selected' : '' ?>>All faculty</option>
                <option value="participated" <?= $facultyFilters['participation_status'] === 'participated' ? 'selected' : '' ?>>Has participation</option>
                <option value="none" <?= $facultyFilters['participation_status'] === 'none' ? 'selected' : '' ?>>No participation</option>
              </select>
            </label>
            <button type="submit">Apply filters</button>
          </form>
          <?php if (!$facultyParticipationReport['available']): ?>
            <p class="module-report-empty">Participation records are unavailable. Confirm the activity and faculty-engagement modules are installed.</p>
          <?php elseif (!$facultyIndividualParticipation): ?>
            <p class="module-report-empty">No active faculty match these filters.</p>
          <?php else: ?>
            <div class="faculty-individual-scrollbar" tabindex="0" role="region" aria-label="Scroll individual faculty participation table horizontally">
              <div class="faculty-individual-scrollbar-spacer" aria-hidden="true"></div>
            </div>
            <div class="faculty-individual-table-wrap">
              <table class="faculty-individual-table">
                <thead>
                  <tr>
                    <th>Faculty</th>
                    <th>Department</th>
                    <th>Program(s)</th>
                    <th>Unique activity events</th>
                    <th>Attendee events</th>
                    <th>Presenter events</th>
                    <th>Standalone verified presentations</th>
                    <th>Total unique participation</th>
                    <th>Most recent</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($facultyIndividualParticipation as $row): ?>
                    <tr>
                      <td class="faculty-individual-name"><?= htmlspecialchars(trim((string) $row['first_name'] . ' ' . (string) $row['last_name']), ENT_QUOTES, 'UTF-8') ?><br><span><?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td><?= htmlspecialchars((string) ($row['department'] ?: 'Not recorded'), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string) $row['programs'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= (int) $row['activity_count'] ?></td>
                      <td><?= (int) $row['attendee_events'] ?></td>
                      <td><?= (int) $row['presenter_events'] ?></td>
                      <td><?= (int) $row['standalone_presentations'] ?></td>
                      <td><strong><?= (int) $row['total_participations'] ?></strong></td>
                      <td><?= $row['latest_participation_date'] ? htmlspecialchars(date('M j, Y', strtotime((string) $row['latest_participation_date'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <nav class="faculty-participation-pagination" aria-label="Individual faculty participation pages">
              <span>Page <?= (int) $facultyFilters['page'] ?> of <?= (int) $facultyParticipationReport['page_count'] ?> · <?= (int) $facultyParticipationReport['total'] ?> faculty</span>
              <?php if ($facultyFilters['page'] > 1): ?>
                <?php $previousFacultyPage = '?' . http_build_query($facultyPageQuery + ['faculty_page' => $facultyFilters['page'] - 1]); ?>
                <a href="<?= htmlspecialchars($previousFacultyPage, ENT_QUOTES, 'UTF-8') ?>">Previous</a>
              <?php endif; ?>
              <?php if ($facultyFilters['page'] < $facultyParticipationReport['page_count']): ?>
                <?php $nextFacultyPage = '?' . http_build_query($facultyPageQuery + ['faculty_page' => $facultyFilters['page'] + 1]); ?>
                <a href="<?= htmlspecialchars($nextFacultyPage, ENT_QUOTES, 'UTF-8') ?>">Next</a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        </section>
        <?php if (!$moduleTables['faculty_research_profiles'] || !$moduleTables['faculty_engagement_records']): ?>
          <p class="faculty-report-note">Some faculty profile and engagement data is unavailable. Available linked records are shown below.</p>
        <?php endif; ?>
        <section class="faculty-report-section" aria-labelledby="faculty-program-breakdown-heading">
          <h4 id="faculty-program-breakdown-heading">Faculty by program</h4>
          <?php if (!$facultyProgramRows): ?>
            <p class="module-report-empty">No faculty program records are available.</p>
          <?php else: ?>
            <div class="faculty-report-table-wrap">
              <table class="faculty-report-table">
                <thead><tr><th>Program</th><th>Faculty</th><th>Profiles saved</th><th>Verified engagements</th></tr></thead>
                <tbody>
                  <?php foreach ($facultyProgramRows as $row): ?>
                    <tr>
                      <td><?= htmlspecialchars((string) $row['program'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= (int) $row['faculty_count'] ?></td>
                      <td><?= (int) $row['profiles_saved'] ?></td>
                      <td><?= (int) $row['verified_engagements'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
        <section class="faculty-report-section" aria-labelledby="faculty-contributors-heading">
          <h4 id="faculty-contributors-heading">Top linked faculty contributions</h4>
          <?php if (!$facultyTopContributors): ?>
            <p class="module-report-empty">No active faculty accounts were found.</p>
          <?php else: ?>
            <div class="faculty-report-table-wrap">
              <table class="faculty-report-table">
                <thead><tr><th>Faculty</th><th>Program</th><th>Contributions</th><th>Projects</th><th>Published</th><th>Verified engagement</th><th>Activity attendance</th><th>Team memberships</th><th>Advising</th><th>Registered IP</th></tr></thead>
                <tbody>
                  <?php foreach ($facultyTopContributors as $row): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars(trim((string) $row['first_name'] . ' ' . (string) $row['last_name']), ENT_QUOTES, 'UTF-8') ?></strong><br><?= htmlspecialchars((string) ($row['academic_rank'] ?? 'Rank not recorded'), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string) ($row['program'] ?: 'Program not recorded'), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= (int) $row['contribution_count'] ?></td>
                      <td><?= (int) $row['project_count'] ?></td>
                      <td><?= (int) $row['published_count'] ?></td>
                      <td><?= (int) $row['verified_engagement_count'] ?></td>
                      <td><?= (int) $row['activity_attendance_count'] ?></td>
                      <td><?= (int) $row['team_membership_count'] ?></td>
                      <td><?= (int) $row['advising_assignment_count'] ?></td>
                      <td><?= (int) $row['registered_ip_count'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
        <footer>
          <a href="<?= htmlspecialchars(SITE_URL . 'pages/admin/admin-researchers.php?role=faculty', ENT_QUOTES, 'UTF-8') ?>">Open faculty profiles</a>
          · <a href="<?= htmlspecialchars(SITE_URL . 'pages/admin/admin-faculty-engagement.php', ENT_QUOTES, 'UTF-8') ?>">Review engagement records</a>
        </footer>
      </article>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
(() => {
  const workspace = document.querySelector('.reports-workspace');
  const choices = Array.from(workspace.querySelectorAll('[data-report-choice]'));
  const pages = Array.from(workspace.querySelectorAll('[data-report-page]'));
  const moduleReports = workspace.querySelector('#module-portfolio');
  const moduleGrid = workspace.querySelector('.module-report-grid');
  const title = workspace.querySelector('#selected-report-title');
  const description = workspace.querySelector('#selected-report-description');
  const printButton = workspace.querySelector('#print-report');
  const printButtonLabel = workspace.querySelector('.report-print-label');
  const printStatus = workspace.querySelector('#report-print-status');
  const roleFilterForm = workspace.querySelector('#report-role-filter-form');
  const donutCharts = Array.from(workspace.querySelectorAll('.report-donut-layout'));
  const monthlyCharts = Array.from(workspace.querySelectorAll('.monthly-chart-wrap'));
  const individualTableWrap = workspace.querySelector('.faculty-individual-table-wrap');
  const individualTable = individualTableWrap?.querySelector('.faculty-individual-table');
  const individualScrollbar = workspace.querySelector('.faculty-individual-scrollbar');
  let printStatusTimer;

  if (individualTableWrap && individualTable && individualScrollbar) {
    const scrollbarSpacer = individualScrollbar.querySelector('.faculty-individual-scrollbar-spacer');
    let syncingScroll = false;

    const updateIndividualScrollbar = () => {
      scrollbarSpacer.style.width = `${individualTable.scrollWidth}px`;
      individualScrollbar.hidden = individualTableWrap.scrollWidth <= individualTableWrap.clientWidth;
    };
    const syncIndividualScroll = (source, target) => {
      if (syncingScroll) return;
      syncingScroll = true;
      target.scrollLeft = source.scrollLeft;
      syncingScroll = false;
    };

    individualScrollbar.addEventListener('scroll', () => {
      syncIndividualScroll(individualScrollbar, individualTableWrap);
    }, { passive: true });
    individualTableWrap.addEventListener('scroll', () => {
      syncIndividualScroll(individualTableWrap, individualScrollbar);
    }, { passive: true });
    if ('ResizeObserver' in window) {
      const individualTableResizeObserver = new ResizeObserver(updateIndividualScrollbar);
      individualTableResizeObserver.observe(individualTableWrap);
      individualTableResizeObserver.observe(individualTable);
    } else {
      window.addEventListener('resize', updateIndividualScrollbar, { passive: true });
    }
    updateIndividualScrollbar();
  }

  donutCharts.forEach((chart) => {
    const slices = Array.from(chart.querySelectorAll('.report-donut-slice'));
    const legendButtons = Array.from(chart.querySelectorAll('.report-donut-legend-button'));
    const centerValue = chart.querySelector('[data-slice-center-value]');
    const centerLabel = chart.querySelector('[data-slice-center-label]');
    const total = centerValue.textContent;

    const showStatus = (index) => {
      const activeSlice = slices.find((slice) => slice.dataset.sliceIndex === index);
      if (!activeSlice) return;
      centerValue.textContent = activeSlice.dataset.sliceCount;
      centerLabel.textContent = activeSlice.dataset.sliceLabel;
      centerLabel.style.fontSize = `${Math.max(8, Math.min(12, 110 / activeSlice.dataset.sliceLabel.length))}px`;
      slices.forEach((slice) => slice.classList.toggle('is-muted', slice !== activeSlice));
      legendButtons.forEach((button) => button.classList.toggle('is-active', button.dataset.sliceIndex === index));
    };
    const resetStatus = () => {
      centerValue.textContent = total;
      centerLabel.textContent = chart.dataset.defaultLabel;
      centerLabel.style.fontSize = '';
      slices.forEach((slice) => slice.classList.remove('is-muted'));
      legendButtons.forEach((button) => button.classList.remove('is-active'));
    };

    legendButtons.forEach((button) => {
      button.addEventListener('mouseenter', () => showStatus(button.dataset.sliceIndex));
      button.addEventListener('focus', () => showStatus(button.dataset.sliceIndex));
      button.addEventListener('mouseleave', () => {
        if (!chart.contains(document.activeElement)) resetStatus();
      });
      button.addEventListener('blur', resetStatus);
    });
    slices.forEach((slice) => {
      slice.addEventListener('mouseenter', () => showStatus(slice.dataset.sliceIndex));
      slice.addEventListener('mouseleave', resetStatus);
    });
  });

  monthlyCharts.forEach((wrap) => {
    const svg = wrap.querySelector('.monthly-area-chart');
    const tooltip = wrap.querySelector('.monthly-chart-tooltip');
    const crosshair = svg?.querySelector('.monthly-area-crosshair');
    const points = Array.from(svg?.querySelectorAll('.monthly-area-point') ?? []);
    if (!svg || !tooltip || !crosshair || points.length === 0) return;

    const showPoint = (point) => {
      const pointRect = point.getBoundingClientRect();
      const wrapRect = wrap.getBoundingClientRect();
      const pointX = pointRect.left + pointRect.width / 2 - wrapRect.left + wrap.scrollLeft;
      const pointY = pointRect.top + pointRect.height / 2 - wrapRect.top + wrap.scrollTop;
      const x = Number(point.getAttribute('cx'));
      crosshair.setAttribute('x1', String(x));
      crosshair.setAttribute('x2', String(x));
      tooltip.textContent = `${point.dataset.tooltipMonth}: ${point.dataset.tooltipValue} ${point.dataset.tooltipUnit}`;
      tooltip.hidden = false;
      const edge = tooltip.offsetWidth / 2;
      tooltip.style.left = `${Math.max(edge + 8, Math.min(wrap.scrollWidth - edge - 8, pointX))}px`;
      tooltip.style.top = `${Math.max(4, pointY - tooltip.offsetHeight - 12)}px`;
    };
    const hidePoint = () => {
      if (points.includes(document.activeElement)) return;
      tooltip.hidden = true;
      crosshair.setAttribute('x1', '0');
      crosshair.setAttribute('x2', '0');
    };

    points.forEach((point) => {
      point.addEventListener('mouseenter', () => showPoint(point));
      point.addEventListener('focus', () => showPoint(point));
      point.addEventListener('mouseleave', hidePoint);
      point.addEventListener('blur', hidePoint);
    });
    wrap.addEventListener('scroll', () => {
      const activePoint = points.find((point) => point === document.activeElement || point.matches(':hover'));
      if (activePoint) showPoint(activePoint);
      else hidePoint();
    }, { passive: true });
  });

  const announcePrintStatus = (message) => {
    window.clearTimeout(printStatusTimer);
    printStatus.textContent = message;
    printStatusTimer = window.setTimeout(() => {
      printStatus.textContent = '';
    }, 5000);
  };

  const updateSelection = () => {
    const selectedChoices = choices.filter((choice) => choice.checked);
    const selectedReports = new Set(selectedChoices.map((choice) => choice.value));
    const selectedPages = pages.filter((page) => selectedReports.has(page.dataset.reportPage));

    pages.forEach((page) => {
      const isSelected = selectedReports.has(page.dataset.reportPage);
      page.hidden = !isSelected;
      page.toggleAttribute('data-report-print-active', isSelected);
      page.toggleAttribute('data-report-print-first', isSelected && selectedPages[0] === page);
      page.toggleAttribute('data-report-print-last', isSelected && selectedPages[selectedPages.length - 1] === page);
    });
    const selectedModuleCards = selectedPages.filter((page) => page.classList.contains('module-report-card'));
    moduleGrid.dataset.reportCount = String(Math.max(1, selectedModuleCards.length));
    moduleReports.hidden = !selectedPages.some((page) => page.closest('#module-portfolio'));

    const count = selectedChoices.length;
    title.textContent = `${count} ${count === 1 ? 'report' : 'reports'} selected`;
    description.textContent = count > 0
      ? 'Choose reports to view and print. Each prints separately.'
      : 'Select at least one report to enable printing.';
    printButtonLabel.textContent = `Print selected ${count === 1 ? 'report' : 'reports'} (${count})`;
    printButton.disabled = count === 0;
    printButton.setAttribute('aria-label', count > 0
      ? `Print ${count} selected ${count === 1 ? 'report' : 'reports'}`
      : 'Select at least one report to enable printing');
    if (count === 0) {
      announcePrintStatus('Select at least one report before printing.');
    }
  };

  choices.forEach((choice) => choice.addEventListener('change', () => {
    updateSelection();
    const count = choices.filter((choice) => choice.checked).length;
    announcePrintStatus(count > 0
      ? `${count} ${count === 1 ? 'report is' : 'reports are'} ready to print.`
      : 'Select at least one report before printing.');
  }));
  printButton.addEventListener('click', () => {
    const count = choices.filter((choice) => choice.checked).length;
    if (count === 0) {
      announcePrintStatus('Select at least one report before printing.');
      choices[0]?.focus();
      return;
    }

    announcePrintStatus(`Opening the print dialog for ${count} selected ${count === 1 ? 'report' : 'reports'}.`);
    window.setTimeout(() => window.print(), 150);
  });
  roleFilterForm.addEventListener('submit', () => {
    const selectionMarker = document.createElement('input');
    selectionMarker.type = 'hidden';
    selectionMarker.name = 'reports_submitted';
    selectionMarker.value = '1';
    roleFilterForm.appendChild(selectionMarker);
    choices.filter((choice) => choice.checked).forEach((choice) => {
      const selectedReport = document.createElement('input');
      selectedReport.type = 'hidden';
      selectedReport.name = 'reports[]';
      selectedReport.value = choice.value;
      roleFilterForm.appendChild(selectedReport);
    });
  });
  updateSelection();
})();
</script>

<?php
renderAdminShellClose();
