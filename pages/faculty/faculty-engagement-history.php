<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
if (($user['role'] ?? '') !== 'faculty') {
    http_response_code(403);
    exit('Faculty access is required.');
}

function facultyEngagementHistoryEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function facultyEngagementHistoryQueryText(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function facultyEngagementHistoryDateIsValid(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function facultyEngagementHistoryTableExists(mysqli $connection): bool
{
    $stmt = $connection->prepare("SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('faculty_engagement_records', 'uploads')");
    if (!$stmt) {
        throw new RuntimeException('Unable to check engagement setup: ' . $connection->error);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($result['table_count'] ?? 0) === 2;
}

function facultyEngagementHistoryBindAndExecute(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types !== '') {
        $arguments = [$types];
        foreach ($params as $index => $value) {
            $arguments[] = &$params[$index];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $arguments)) {
            return false;
        }
    }
    return $stmt->execute();
}

function facultyEngagementHistoryPageUrl(int $page, array $filters): string
{
    $query = array_filter($filters, static function ($value): bool {
        return $value !== '' && $value !== 'all';
    });
    $query['page'] = $page;
    return SITE_URL . 'pages/faculty/faculty-engagement-history.php?' . http_build_query($query);
}

$engagementTypes = [
    'seminar_training' => 'Seminar / Training / Workshop',
    'conference_presentation' => 'Conference / Presentation',
    'extension_community' => 'Extension / Community Research',
    'research_award' => 'Research Award',
    'collaboration' => 'Research Collaboration',
    'other' => 'Other Research Engagement',
];
$participationRoles = [
    'participant' => 'Participant',
    'presenter' => 'Presenter',
    'organizer' => 'Organizer',
    'adviser' => 'Adviser',
    'team_lead' => 'Team Lead',
];
$statusLabels = [
    'pending' => 'Pending',
    'verified' => 'Verified',
    'returned' => 'Returned',
];
$allowedStatuses = array_merge(['all'], array_keys($statusLabels));
$userId = (int) $user['user_id'];
$recordsAvailable = facultyEngagementHistoryTableExists($conn);
$status = facultyEngagementHistoryQueryText('status');
$engagementType = facultyEngagementHistoryQueryText('engagement_type');
$dateFrom = facultyEngagementHistoryQueryText('date_from');
$dateTo = facultyEngagementHistoryQueryText('date_to');
$keyword = facultyEngagementHistoryQueryText('q');
$filterError = '';

if ($status === '') {
    $status = 'all';
} elseif (!in_array($status, $allowedStatuses, true)) {
    $filterError = 'Choose a valid review status.';
    $status = 'all';
}
if ($engagementType !== '' && $engagementType !== 'all' && !isset($engagementTypes[$engagementType])) {
    $filterError = 'Choose a valid engagement type.';
    $engagementType = 'all';
}
if (!facultyEngagementHistoryDateIsValid($dateFrom) || !facultyEngagementHistoryDateIsValid($dateTo)) {
    $filterError = 'Enter valid dates using the date fields.';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $filterError = 'The start date must be on or before the end date.';
}
if (strlen($keyword) > 100) {
    $filterError = 'Keyword search must be 100 characters or fewer.';
    $keyword = substr($keyword, 0, 100);
}
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $pageValue === false ? 1 : (int) $pageValue;
$perPage = 25;
$totalRecords = 0;
$totalPages = 1;
$records = [];

if ($recordsAvailable && $filterError === '') {
    $conditions = ['e.faculty_id = ?'];
    $types = 'i';
    $params = [$userId];
    if ($status !== 'all') {
        $conditions[] = 'e.review_status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($engagementType !== '' && $engagementType !== 'all') {
        $conditions[] = 'e.engagement_type = ?';
        $types .= 's';
        $params[] = $engagementType;
    }
    if ($dateFrom !== '') {
        $conditions[] = 'e.activity_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $conditions[] = 'e.activity_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }
    if ($keyword !== '') {
        $conditions[] = '(e.title LIKE ? OR e.organizer LIKE ?)';
        $types .= 'ss';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }
    $whereSql = implode(' AND ', $conditions);

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM faculty_engagement_records e WHERE ' . $whereSql);
    if (!$countStmt || !facultyEngagementHistoryBindAndExecute($countStmt, $types, $params)) {
        throw new RuntimeException('Unable to count your engagement history: ' . $conn->error);
    }
    $totalRecords = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $conn->prepare(
        'SELECT e.engagement_id, e.engagement_type, e.custom_engagement_type, e.title, e.participation_role, e.activity_date, e.organizer, e.location, e.description, e.review_status, e.review_comments, e.evidence_upload_id, up.original_name
           FROM faculty_engagement_records e
           LEFT JOIN uploads up ON up.upload_id = e.evidence_upload_id
          WHERE ' . $whereSql . '
          ORDER BY e.activity_date DESC, e.engagement_id DESC
          LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    if (!$listStmt || !facultyEngagementHistoryBindAndExecute($listStmt, $types, $params)) {
        throw new RuntimeException('Unable to load your engagement history: ' . $conn->error);
    }
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $listStmt->close();
}

$filters = [
    'status' => $status,
    'engagement_type' => $engagementType,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => $keyword,
];
renderFacultyShell($user, 'faculty-engagement-history.php', 'My Engagement History', 'Review your submitted and verified engagement records.');
?>
<style>
.engagement-history-workspace{max-width:1200px;margin:0 auto}.engagement-history-panel{padding:24px;margin:0 0 24px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}.engagement-history-panel h2{margin:0 0 8px;color:#111827;font-size:22px}.engagement-history-panel>p{margin:0 0 20px;color:#64748b;line-height:1.6}.engagement-history-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.engagement-history-toolbar h2{margin:0}.engagement-history-add{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 16px;border-radius:9px;background:#1d4ed8;color:#fff;font-size:14px;font-weight:600;text-decoration:none}.engagement-history-add:hover{background:#1e40af}.engagement-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:end}.engagement-filter-grid label{display:grid;gap:6px;color:#475569;font-size:13px;font-weight:600}.engagement-filter-grid input,.engagement-filter-grid select{width:100%;box-sizing:border-box;min-height:42px;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#111827;font:inherit}.engagement-filter-grid input:focus-visible,.engagement-filter-grid select:focus-visible,.engagement-filter-actions a:focus-visible,.engagement-filter-actions button:focus-visible,.engagement-history-add:focus-visible,.engagement-history-pagination a:focus-visible{outline:3px solid rgba(29,78,216,.2);outline-offset:2px;border-color:#1d4ed8}.engagement-filter-actions{display:flex;flex-wrap:wrap;gap:10px;grid-column:1/-1}.engagement-filter-actions button,.engagement-filter-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 15px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#1f2937;font:600 13px Inter,Arial,sans-serif;text-decoration:none;cursor:pointer}.engagement-filter-actions button{border-color:#1d4ed8;background:#1d4ed8;color:#fff}.engagement-filter-actions button:hover{background:#1e40af}.engagement-filter-actions a:hover{background:#f8fafc}.engagement-history-message{padding:13px 16px;margin:0 0 18px;border-radius:10px;background:#eff6ff;color:#1e40af}.engagement-history-message.error{background:#fef2f2;color:#b91c1c}.engagement-history-count{margin:0 0 12px;color:#64748b;font-size:13px}.engagement-history-item{padding:22px 24px;margin:14px 0 0;border:1px solid #dbe5f1;border-left:3px solid #1d4ed8;border-radius:14px;background:#fff}.engagement-history-item h3{display:flex;flex-wrap:wrap;align-items:flex-start;gap:10px 14px;margin:0 0 14px;color:#111827;font-size:18px;line-height:1.4}.engagement-status{display:inline-flex;padding:6px 11px;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;text-transform:capitalize;white-space:nowrap}.engagement-status--verified{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.engagement-status--returned{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.engagement-history-meta{display:flex;flex-wrap:wrap;gap:8px;color:#475569;font-size:13px}.engagement-history-meta>span{padding:6px 9px;border:1px solid #e6edf5;border-radius:8px;background:#f8fafc}.engagement-history-evidence{display:inline-flex;align-items:center;margin-top:12px;padding:9px 12px;border:1px solid #dbe5f1;border-radius:9px;color:#1d4ed8;font-size:13px;font-weight:600;text-decoration:none;overflow-wrap:anywhere}.engagement-history-evidence:hover{text-decoration:underline;background:#f4f8ff}.engagement-history-description{margin:15px 0 0;color:#475569;line-height:1.7;white-space:pre-wrap}.engagement-history-comment{padding:12px 14px;margin:14px 0 0;border-left:3px solid #f59e0b;border-radius:0 9px 9px 0;background:#fffbeb;color:#713f12;line-height:1.6}.engagement-history-pagination{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:20px;color:#475569;font-size:13px}.engagement-history-pagination a{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#1d4ed8;text-decoration:none}.engagement-history-pagination a:hover{background:#f8fafc}@media(max-width:760px){.engagement-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.engagement-history-panel{padding:18px}.engagement-history-toolbar{align-items:stretch;flex-direction:column}.engagement-history-add{width:100%;box-sizing:border-box}.engagement-filter-grid{grid-template-columns:1fr}.engagement-filter-actions{grid-column:auto}.engagement-filter-actions>*{flex:1}.engagement-history-item{padding:18px 16px}}
</style>
<main class="engagement-history-workspace">
  <?php if (!$recordsAvailable): ?>
    <section class="engagement-history-panel"><h2>Engagement records are not installed</h2><p>Apply database migration 024 before viewing engagement history.</p></section>
  <?php else: ?>
    <section class="engagement-history-panel" aria-labelledby="engagement-history-heading">
      <div class="engagement-history-toolbar"><h2 id="engagement-history-heading">My Engagement History</h2><a class="engagement-history-add" href="<?= facultyEngagementHistoryEscape(SITE_URL . 'pages/faculty/faculty-engagement.php') ?>">Add an Engagement Record</a></div>
      <form method="get" class="engagement-filter-grid">
        <label for="engagement-status">Review status<select id="engagement-status" name="status"><option value="all">All statuses</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= facultyEngagementHistoryEscape($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= facultyEngagementHistoryEscape($label) ?></option><?php endforeach; ?></select></label>
        <label for="engagement-type">Engagement type<select id="engagement-type" name="engagement_type"><option value="all">All types</option><?php foreach ($engagementTypes as $value => $label): ?><option value="<?= facultyEngagementHistoryEscape($value) ?>" <?= $engagementType === $value ? 'selected' : '' ?>><?= facultyEngagementHistoryEscape($label) ?></option><?php endforeach; ?></select></label>
        <label for="engagement-keyword">Keyword<input id="engagement-keyword" type="search" name="q" maxlength="100" value="<?= facultyEngagementHistoryEscape($keyword) ?>" placeholder="Title or organizer"></label>
        <label for="engagement-date-from">Activity date from<input id="engagement-date-from" type="date" name="date_from" value="<?= facultyEngagementHistoryEscape($dateFrom) ?>"></label>
        <label for="engagement-date-to">Activity date to<input id="engagement-date-to" type="date" name="date_to" value="<?= facultyEngagementHistoryEscape($dateTo) ?>"></label>
        <div class="engagement-filter-actions"><button type="submit">Apply filters</button><a href="<?= facultyEngagementHistoryEscape(SITE_URL . 'pages/faculty/faculty-engagement-history.php') ?>">Clear filters</a></div>
      </form>
    </section>
    <?php if ($filterError !== ''): ?><p class="engagement-history-message error" role="alert"><?= facultyEngagementHistoryEscape($filterError) ?></p><?php endif; ?>
    <?php if ($filterError === ''): ?>
      <section class="engagement-history-panel" aria-label="Engagement records">
        <p class="engagement-history-count"><?php if ($totalRecords > 0): ?>Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $totalRecords) ?> of <?= $totalRecords ?> records<?php else: ?>No records match these filters.<?php endif; ?></p>
        <?php foreach ($records as $record): ?>
          <article id="engagement-<?= (int) $record['engagement_id'] ?>" class="engagement-history-item">
            <h3><?= facultyEngagementHistoryEscape($record['title']) ?><span class="engagement-status engagement-status--<?= facultyEngagementHistoryEscape($record['review_status']) ?>"><?= facultyEngagementHistoryEscape($statusLabels[$record['review_status']] ?? $record['review_status']) ?></span></h3>
            <div class="engagement-history-meta">
              <span><?php
                $recordType = $engagementTypes[$record['engagement_type']] ?? 'Research engagement';
                if ($record['engagement_type'] === 'other' && !empty($record['custom_engagement_type'])) {
                    $recordType .= ': ' . $record['custom_engagement_type'];
                }
                echo facultyEngagementHistoryEscape($recordType);
              ?></span>
              <span><?= facultyEngagementHistoryEscape(date('M j, Y', strtotime((string) $record['activity_date']))) ?></span>
              <span><?= facultyEngagementHistoryEscape($participationRoles[$record['participation_role']] ?? $record['participation_role']) ?></span>
              <?php if ($record['organizer']): ?><span><?= facultyEngagementHistoryEscape($record['organizer']) ?></span><?php endif; ?>
              <?php if ($record['location']): ?><span><?= facultyEngagementHistoryEscape($record['location']) ?></span><?php endif; ?>
            </div>
            <?php if ($record['evidence_upload_id']): ?><a class="engagement-history-evidence" href="<?= facultyEngagementHistoryEscape(SITE_URL . 'pages/shared/download-research-document.php?type=engagement&id=' . (int) $record['engagement_id'] . '&mode=viewer') ?>">📎 <?= facultyEngagementHistoryEscape($record['original_name'] ?: 'View evidence') ?></a><?php endif; ?>
            <?php if ($record['description']): ?><p class="engagement-history-description"><?= facultyEngagementHistoryEscape($record['description']) ?></p><?php endif; ?>
            <?php if ($record['review_comments']): ?><p class="engagement-history-comment"><strong>Reviewer comment:</strong> <?= facultyEngagementHistoryEscape($record['review_comments']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
        <?php if ($totalPages > 1): ?><nav class="engagement-history-pagination" aria-label="Engagement history pages">
          <?php if ($page > 1): ?><a href="<?= facultyEngagementHistoryEscape(facultyEngagementHistoryPageUrl($page - 1, $filters)) ?>">Previous</a><?php endif; ?>
          <span>Page <?= $page ?> of <?= $totalPages ?></span>
          <?php if ($page < $totalPages): ?><a href="<?= facultyEngagementHistoryEscape(facultyEngagementHistoryPageUrl($page + 1, $filters)) ?>">Next</a><?php endif; ?>
        </nav><?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php renderFacultyShellClose(); ?>
