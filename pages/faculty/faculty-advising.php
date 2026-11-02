<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
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

function facultyAdvisingEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = (int) $user['user_id'];
$error = '';
$success = '';
$decisionMessage = [
    'accepted' => 'The adviser request was accepted.',
    'declined' => 'The adviser request was declined.',
];

function facultyAdviserRequestTableExists(mysqli $connection): bool
{
    $stmt = $connection->prepare("SHOW TABLES LIKE 'adviser_requests'");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function facultyAdvisingBindAndExecute(mysqli_stmt $stmt, string $types, array $values): bool
{
    $bindArguments = [&$types];
    foreach ($values as &$value) {
        $bindArguments[] = &$value;
    }
    unset($value);
    return call_user_func_array([$stmt, 'bind_param'], $bindArguments) && $stmt->execute();
}

function facultyAdvisingQueryText(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
}

function facultyAdvisingPageSize(string $key): int
{
    $value = filter_var($_GET[$key] ?? 10, FILTER_VALIDATE_INT);
    return in_array($value, [5, 10, 20], true) ? (int) $value : 10;
}

function facultyAdvisingPageNumber(string $key): int
{
    $value = filter_var($_GET[$key] ?? 1, FILTER_VALIDATE_INT);
    return $value !== false && $value > 0 ? (int) $value : 1;
}

function facultyAdvisingHiddenFields(array $keys): string
{
    $html = '';
    foreach ($keys as $key) {
        $value = $_GET[$key] ?? null;
        if (is_scalar($value)) {
            $html .= '<input type="hidden" name="' . facultyAdvisingEscape($key) . '" value="' . facultyAdvisingEscape($value) . '">';
        }
    }
    return $html;
}

function facultyAdvisingPaginationUrl(string $pageKey, int $page): string
{
    $query = $_GET;
    unset($query['saved']);
    $query[$pageKey] = max(1, $page);
    return SITE_URL . 'pages/faculty/faculty-advising.php?' . http_build_query($query);
}

function facultyAdvisingRenderPagination(string $pageKey, int $page, int $pageSize, int $total): string
{
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $start = $total > 0 ? (($page - 1) * $pageSize) + 1 : 0;
    $end = min($total, $page * $pageSize);
    $html = '<nav class="faculty-list-pagination" aria-label="List pagination"><span>Showing ' . $start . '–' . $end . ' of ' . $total . '</span>';
    if ($totalPages > 1) {
        $previous = $page > 1
            ? '<a href="' . facultyAdvisingEscape(facultyAdvisingPaginationUrl($pageKey, $page - 1)) . '">Previous</a>'
            : '<span aria-disabled="true">Previous</span>';
        $next = $page < $totalPages
            ? '<a href="' . facultyAdvisingEscape(facultyAdvisingPaginationUrl($pageKey, $page + 1)) . '">Next</a>'
            : '<span aria-disabled="true">Next</span>';
        $html .= '<div class="faculty-list-pagination-actions">' . $previous . '<span>Page ' . $page . ' of ' . $totalPages . '</span>' . $next . '</div>';
    }
    return $html . '</nav>';
}

$adviserRequestsAvailable = facultyAdviserRequestTableExists($conn);
if (!$adviserRequestsAvailable) {
    $error = 'The adviser request workflow is unavailable because the required adviser request table is not installed yet.';
}

$saved = (string) ($_GET['saved'] ?? '');
if (isset($decisionMessage[$saved])) {
    $success = $decisionMessage[$saved];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$adviserRequestsAvailable) {
        $error = 'The adviser request workflow is unavailable because the required adviser request table is not installed yet.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } else {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = (string) ($_POST['action'] ?? '');
        if (!$requestId || !in_array($action, ['accept_request', 'decline_request'], true)) {
            $error = 'Select a valid adviser request action.';
        } elseif (!$conn->begin_transaction()) {
            $error = 'The adviser request could not be updated. Please try again.';
        } else {
            try {
                $lookupStmt = $conn->prepare(
                    "SELECT project_id FROM adviser_requests
                      WHERE request_id = ? AND faculty_id = ? AND status = 'pending'
                      LIMIT 1"
                );
                $lookupStmt->bind_param('ii', $requestId, $userId);
                $lookupStmt->execute();
                $requestProjectId = (int) ($lookupStmt->get_result()->fetch_assoc()['project_id'] ?? 0);
                $lookupStmt->close();
                if ($requestProjectId <= 0) {
                    throw new RuntimeException('This pending adviser request is unavailable to your account.');
                }

                $projectStmt = $conn->prepare(
                    'SELECT project_id, title, status, adviser_id
                       FROM research_projects
                      WHERE project_id = ? AND deleted_at IS NULL
                      FOR UPDATE'
                );
                $projectStmt->bind_param('i', $requestProjectId);
                $projectStmt->execute();
                $project = $projectStmt->get_result()->fetch_assoc();
                $projectStmt->close();
                if (!$project) {
                    throw new RuntimeException('The research project is no longer available.');
                }

                $requestStmt = $conn->prepare(
                    "SELECT request_id, requester_id, faculty_id, status
                       FROM adviser_requests
                      WHERE request_id = ? AND project_id = ? AND faculty_id = ? AND status = 'pending'
                      FOR UPDATE"
                );
                $requestStmt->bind_param('iii', $requestId, $requestProjectId, $userId);
                $requestStmt->execute();
                $request = $requestStmt->get_result()->fetch_assoc();
                $requestStmt->close();
                if (!$request) {
                    throw new RuntimeException('This adviser request has already been handled.');
                }

                $newStatus = $action === 'accept_request' ? 'accepted' : 'declined';
                if ($action === 'accept_request') {
                    if (!in_array((string) $project['status'], ['title_approved', 'approved', 'ongoing'], true)) {
                        throw new RuntimeException('This project is no longer eligible for a new adviser assignment.');
                    }
                    $facultyStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'faculty' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
                    $facultyStmt->bind_param('i', $userId);
                    $facultyStmt->execute();
                    $facultyIsActive = $facultyStmt->get_result()->num_rows === 1;
                    $facultyStmt->close();
                    if (!$facultyIsActive) {
                        throw new RuntimeException('Only an active faculty account may accept an adviser request.');
                    }

                    $activeStmt = $conn->prepare('SELECT project_adviser_id FROM project_advisers WHERE project_id = ? AND adviser_id IS NOT NULL LIMIT 1');
                    $activeStmt->bind_param('i', $requestProjectId);
                    $activeStmt->execute();
                    $hasAssignment = $activeStmt->get_result()->num_rows > 0;
                    $activeStmt->close();
                    if ((int) ($project['adviser_id'] ?? 0) > 0 || $hasAssignment) {
                        throw new RuntimeException('An active adviser already exists for this project. The request was not accepted.');
                    }

                    $assignStmt = $conn->prepare('INSERT INTO project_advisers (project_id, adviser_id) VALUES (?, ?)');
                    $assignStmt->bind_param('ii', $requestProjectId, $userId);
                    if (!$assignStmt->execute()) {
                        throw new RuntimeException('Unable to create the faculty adviser assignment.');
                    }
                    $assignStmt->close();

                    $projectUpdate = $conn->prepare('UPDATE research_projects SET adviser_id = ?, updated_at = NOW() WHERE project_id = ? AND (adviser_id IS NULL OR adviser_id = 0) AND deleted_at IS NULL');
                    $projectUpdate->bind_param('ii', $userId, $requestProjectId);
                    $projectUpdate->execute();
                    $projectUpdated = $projectUpdate->affected_rows === 1;
                    $projectUpdate->close();
                    if (!$projectUpdated) {
                        throw new RuntimeException('The project adviser changed before this request could be accepted.');
                    }
                }

                $decisionStmt = $conn->prepare('UPDATE adviser_requests SET status = ?, decided_at = NOW(), updated_at = NOW() WHERE request_id = ? AND status = \'pending\'');
                $decisionStmt->bind_param('si', $newStatus, $requestId);
                $decisionStmt->execute();
                $decisionSaved = $decisionStmt->affected_rows === 1;
                $decisionStmt->close();
                if (!$decisionSaved || !$conn->commit()) {
                    throw new RuntimeException('The adviser decision could not be saved. Please try again.');
                }

                logActivity('Adviser request ' . $newStatus . ' for project #' . $requestProjectId, 'adviser_requests');
                createNotification(
                    (int) $request['requester_id'],
                    'Adviser request ' . $newStatus,
                    'Your adviser request for ' . (string) $project['title'] . ' was ' . $newStatus . '.',
                    $newStatus === 'accepted' ? 'success' : 'info',
                    SITE_URL . 'pages/researcher/request-adviser.php?id=' . $requestProjectId
                );
                header('Location: ' . SITE_URL . 'pages/faculty/faculty-advising.php?saved=' . $newStatus);
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            }
        }
    }
}

$courseOptions = [];
$courseResult = $conn->query(
    "SELECT DISTINCT TRIM(program) AS program
       FROM users
      WHERE role = 'student' AND deleted_at IS NULL
        AND program IS NOT NULL AND TRIM(program) <> ''
      ORDER BY program"
);
if ($courseResult) {
    while ($courseRow = $courseResult->fetch_assoc()) {
        $courseOptions[] = (string) $courseRow['program'];
    }
    $courseResult->free();
}

$requestSearch = facultyAdvisingQueryText('requests_search');
$requestStatus = (string) ($_GET['requests_status'] ?? '');
if (!in_array($requestStatus, ['', 'pending', 'accepted'], true)) {
    $requestStatus = '';
}
$requestCourse = (string) ($_GET['requests_course'] ?? '');
if (!in_array($requestCourse, $courseOptions, true)) {
    $requestCourse = '';
}
$requestPageSize = facultyAdvisingPageSize('requests_per_page');
$requestPage = facultyAdvisingPageNumber('requests_page');
$requestWhere = " WHERE ar.faculty_id = ? AND ar.status IN ('pending', 'accepted')";
$requestTypes = 'i';
$requestParams = [$userId];
if ($requestStatus !== '') {
    $requestWhere .= ' AND ar.status = ?';
    $requestTypes .= 's';
    $requestParams[] = $requestStatus;
}
if ($requestSearch !== '') {
    $requestPattern = '%' . $requestSearch . '%';
    $requestWhere .= " AND (rp.title LIKE ? OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?)";
    $requestTypes .= 'ss';
    $requestParams[] = $requestPattern;
    $requestParams[] = $requestPattern;
}
if ($requestCourse !== '') {
    $requestWhere .= ' AND TRIM(s.program) = ?';
    $requestTypes .= 's';
    $requestParams[] = $requestCourse;
}
$requestFrom = ' FROM adviser_requests ar
                  JOIN research_projects rp ON rp.project_id = ar.project_id AND rp.deleted_at IS NULL
                  JOIN users s ON s.user_id = ar.requester_id AND s.deleted_at IS NULL';
$requestTotal = 0;
if ($adviserRequestsAvailable) {
    $requestCountStmt = $conn->prepare('SELECT COUNT(*)' . $requestFrom . $requestWhere);
    if (!$requestCountStmt || !facultyAdvisingBindAndExecute($requestCountStmt, $requestTypes, $requestParams)) {
        throw new RuntimeException('Unable to count faculty adviser requests: ' . $conn->error);
    }
    $requestTotal = (int) $requestCountStmt->get_result()->fetch_row()[0];
    $requestCountStmt->close();
}
$requestTotalPages = max(1, (int) ceil($requestTotal / $requestPageSize));
$requestPage = min($requestPage, $requestTotalPages);
$requestOffset = ($requestPage - 1) * $requestPageSize;
$requests = [];
if ($adviserRequestsAvailable && $requestTotal > 0) {
    $requestListStmt = $conn->prepare(
        'SELECT ar.request_id, ar.project_id, ar.status, ar.upload_id, ar.created_at,
                rp.title AS project_title, s.first_name AS student_first_name,
                s.last_name AS student_last_name, s.program AS student_program' .
        $requestFrom . $requestWhere .
        " ORDER BY CASE WHEN ar.status = 'pending' THEN 0 ELSE 1 END, ar.created_at DESC
          LIMIT ? OFFSET ?"
    );
    $listTypes = $requestTypes . 'ii';
    $listParams = array_merge($requestParams, [$requestPageSize, $requestOffset]);
    if (!$requestListStmt || !facultyAdvisingBindAndExecute($requestListStmt, $listTypes, $listParams)) {
        throw new RuntimeException('Unable to load faculty adviser requests: ' . $conn->error);
    }
    $requestListResult = $requestListStmt->get_result();
    while ($request = $requestListResult->fetch_assoc()) {
        $requests[] = $request;
    }
    $requestListStmt->close();
}

$assignmentStatusOptions = [];
$assignmentStatusStmt = $conn->prepare(
    'SELECT DISTINCT rp.status
       FROM research_projects rp
      WHERE rp.deleted_at IS NULL
        AND (rp.adviser_id = ? OR EXISTS (
            SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
        ))
      ORDER BY rp.status'
);
if (!$assignmentStatusStmt || !facultyAdvisingBindAndExecute($assignmentStatusStmt, 'ii', [$userId, $userId])) {
    throw new RuntimeException('Unable to load advising project statuses: ' . $conn->error);
}
$assignmentStatusResult = $assignmentStatusStmt->get_result();
while ($statusRow = $assignmentStatusResult->fetch_assoc()) {
    $assignmentStatusOptions[] = (string) $statusRow['status'];
}
$assignmentStatusStmt->close();

$assignmentSearch = facultyAdvisingQueryText('assignments_search');
$assignmentStatus = (string) ($_GET['assignments_status'] ?? '');
if (!in_array($assignmentStatus, array_merge([''], $assignmentStatusOptions), true)) {
    $assignmentStatus = '';
}
$assignmentCourse = (string) ($_GET['assignments_course'] ?? '');
if (!in_array($assignmentCourse, $courseOptions, true)) {
    $assignmentCourse = '';
}
$assignmentPageSize = facultyAdvisingPageSize('assignments_per_page');
$assignmentPage = facultyAdvisingPageNumber('assignments_page');
$assignmentWhere = ' WHERE rp.deleted_at IS NULL
                       AND (rp.adviser_id = ? OR EXISTS (
                           SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
                       ))';
$assignmentTypes = 'ii';
$assignmentParams = [$userId, $userId];
if ($assignmentStatus !== '') {
    $assignmentWhere .= ' AND rp.status = ?';
    $assignmentTypes .= 's';
    $assignmentParams[] = $assignmentStatus;
}
if ($assignmentSearch !== '') {
    $assignmentPattern = '%' . $assignmentSearch . '%';
    $assignmentWhere .= " AND (rp.title LIKE ? OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?
        OR EXISTS (
            SELECT 1 FROM project_members search_pm
            JOIN users search_student ON search_student.user_id = search_pm.user_id
            WHERE search_pm.project_id = rp.project_id AND search_student.role = 'student'
              AND search_student.deleted_at IS NULL
              AND CONCAT_WS(' ', search_student.first_name, search_student.last_name) LIKE ?
        ))";
    $assignmentTypes .= 'sss';
    $assignmentParams[] = $assignmentPattern;
    $assignmentParams[] = $assignmentPattern;
    $assignmentParams[] = $assignmentPattern;
}
if ($assignmentCourse !== '') {
    $assignmentWhere .= ' AND (TRIM(s.program) = ? OR EXISTS (
        SELECT 1 FROM project_members course_pm
        JOIN users course_student ON course_student.user_id = course_pm.user_id
        WHERE course_pm.project_id = rp.project_id AND course_student.role = \'student\'
          AND course_student.deleted_at IS NULL AND TRIM(course_student.program) = ?
    ))';
    $assignmentTypes .= 'ss';
    $assignmentParams[] = $assignmentCourse;
    $assignmentParams[] = $assignmentCourse;
}
$assignmentFrom = ' FROM research_projects rp
                    LEFT JOIN users s ON s.user_id = COALESCE(rp.student_id, rp.created_by) AND s.deleted_at IS NULL';
$assignmentCountStmt = $conn->prepare('SELECT COUNT(*)' . $assignmentFrom . $assignmentWhere);
if (!$assignmentCountStmt || !facultyAdvisingBindAndExecute($assignmentCountStmt, $assignmentTypes, $assignmentParams)) {
    throw new RuntimeException('Unable to count faculty advising assignments: ' . $conn->error);
}
$assignmentTotal = (int) $assignmentCountStmt->get_result()->fetch_row()[0];
$assignmentCountStmt->close();
$assignmentTotalPages = max(1, (int) ceil($assignmentTotal / $assignmentPageSize));
$assignmentPage = min($assignmentPage, $assignmentTotalPages);
$assignmentOffset = ($assignmentPage - 1) * $assignmentPageSize;
$advisingProjects = [];
if ($assignmentTotal > 0) {
    $adviserStmt = $conn->prepare(
        'SELECT rp.project_id, rp.title, rp.status, rp.updated_at,
                COALESCE(
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(\' · \',
                                NULLIF(TRIM(CONCAT_WS(\' \', student.first_name, student.last_name)), \'\'),
                                NULLIF(TRIM(student.program), \'\'))
                              ORDER BY student.last_name, student.first_name
                              SEPARATOR \'; \')
                       FROM project_members pm
                       JOIN users student ON student.user_id = pm.user_id
                                          AND student.role = \'student\'
                                          AND student.deleted_at IS NULL
                      WHERE pm.project_id = rp.project_id),
                    NULLIF(TRIM(CONCAT_WS(\' · \',
                              NULLIF(TRIM(CONCAT_WS(\' \', s.first_name, s.last_name)), \'\'),
                              NULLIF(TRIM(s.program), \'\'))), \'\')
                ) AS student_researchers' .
        $assignmentFrom . $assignmentWhere .
        ' ORDER BY rp.updated_at DESC, rp.project_id DESC LIMIT ? OFFSET ?'
    );
    $listTypes = $assignmentTypes . 'ii';
    $listParams = array_merge($assignmentParams, [$assignmentPageSize, $assignmentOffset]);
    if (!$adviserStmt || !facultyAdvisingBindAndExecute($adviserStmt, $listTypes, $listParams)) {
        throw new RuntimeException('Unable to load faculty advising assignments: ' . $conn->error);
    }
    $adviserResult = $adviserStmt->get_result();
    while ($project = $adviserResult->fetch_assoc()) {
        $advisingProjects[] = $project;
    }
    $adviserStmt->close();
}

renderFacultyShell($user, 'faculty-advising.php', 'Advising', 'Track research projects assigned to you as an adviser.');
?>
<main class="faculty-profile-page">
    <?php if ($error !== ''): ?><p class="adviser-request-alert adviser-request-alert--error" role="alert"><?= facultyAdvisingEscape($error) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p class="adviser-request-alert adviser-request-alert--success" role="status"><?= facultyAdvisingEscape($success) ?></p><?php endif; ?>
    <?php if (!$adviserRequestsAvailable): ?>
        <section class="faculty-panel faculty-panel-large" aria-live="polite">
            <div class="faculty-panel-head"><div><p class="faculty-panel-label">Student requests</p><h3 id="adviser-requests-heading">Adviser requests</h3></div></div>
            <p>The adviser request workflow is currently disabled until the required database table is installed.</p>
        </section>
    <?php else: ?>
    <section class="faculty-panel faculty-panel-large" aria-labelledby="adviser-requests-heading">
        <div class="faculty-panel-head"><div><p class="faculty-panel-label">Student requests</p><h3 id="adviser-requests-heading">Adviser requests</h3></div></div>
        <form class="faculty-list-filter" method="get" action="<?= facultyAdvisingEscape(SITE_URL . 'pages/faculty/faculty-advising.php') ?>">
            <?= facultyAdvisingHiddenFields(['assignments_search', 'assignments_status', 'assignments_course', 'assignments_per_page', 'assignments_page']) ?>
            <label class="faculty-filter-field faculty-filter-field--search">Search requests
                <input type="search" name="requests_search" maxlength="100" value="<?= facultyAdvisingEscape($requestSearch) ?>" placeholder="Research title or student">
            </label>
            <label class="faculty-filter-field">Request status
                <select name="requests_status">
                    <option value="">All statuses</option>
                    <option value="pending"<?= $requestStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
                    <option value="accepted"<?= $requestStatus === 'accepted' ? ' selected' : '' ?>>Accepted</option>
                </select>
            </label>
            <label class="faculty-filter-field">Course
                <select name="requests_course">
                    <option value="">All courses</option>
                    <?php foreach ($courseOptions as $courseOption): ?>
                        <option value="<?= facultyAdvisingEscape($courseOption) ?>"<?= $requestCourse === $courseOption ? ' selected' : '' ?>><?= facultyAdvisingEscape($courseOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="faculty-filter-field faculty-filter-field--page-size">Rows per page
                <select name="requests_per_page">
                    <?php foreach ([5, 10, 20] as $pageSizeOption): ?>
                        <option value="<?= $pageSizeOption ?>"<?= $requestPageSize === $pageSizeOption ? ' selected' : '' ?>><?= $pageSizeOption ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="faculty-filter-submit">Apply filters</button>
        </form>
        <?php if (!$requests): ?>
            <p><?= $requestTotal > 0 ? 'No requests on this page.' : 'No adviser requests match these filters.' ?></p>
        <?php else: ?>
            <ul class="faculty-list">
                <?php foreach ($requests as $request): ?>
                    <?php $studentName = trim((string) $request['student_first_name'] . ' ' . (string) $request['student_last_name']); ?>
                    <li class="faculty-adviser-request-item">
                        <div>
                            <strong><?= facultyAdvisingEscape($request['project_title']) ?></strong>
                            <small><?= facultyAdvisingEscape($studentName) ?> · <?= facultyAdvisingEscape(ucfirst((string) $request['status'])) ?></small>
                            <small><a href="<?= SITE_URL ?>pages/shared/messages.php?request_id=<?= (int) $request['request_id'] ?>">Private messages</a></small>
                        </div>
                        <div class="adviser-request-actions">
                            <div class="adviser-request-document-actions" role="group" aria-label="Request document">
                                <button type="button" class="adviser-request-button adviser-request-button--preview" data-docx-preview-open data-docx-url="<?= facultyAdvisingEscape(SITE_URL . 'pages/shared/download-adviser-request.php?id=' . (int) $request['request_id']) ?>" aria-controls="adviser-docx-preview">Preview in RMS</button>
                            </div>
                        <?php if ((string) $request['status'] === 'pending'): ?>
                            <div class="adviser-request-decision-actions" role="group" aria-label="Adviser request decision">
                                <form method="post"><?= csrfField() ?><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><input type="hidden" name="action" value="accept_request"><button type="submit" class="adviser-request-button adviser-request-button--accept">Accept</button></form>
                                <form method="post"><?= csrfField() ?><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><input type="hidden" name="action" value="decline_request"><button type="submit" class="adviser-request-button adviser-request-button--decline">Decline</button></form>
                            </div>
                        <?php else: ?>
                            <div class="adviser-request-decision-actions" role="group" aria-label="Adviser request status"><span class="faculty-status-badge faculty-status-badge--review">Accepted</span></div>
                        <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?= facultyAdvisingRenderPagination('requests_page', $requestPage, $requestPageSize, $requestTotal) ?>
    </section>
    <dialog class="adviser-docx-dialog" id="adviser-docx-preview" data-adviser-docx-dialog aria-labelledby="adviser-docx-title">
        <div class="adviser-docx-dialog-inner">
            <header class="adviser-docx-dialog-header">
                <div><h2 id="adviser-docx-title">Completed adviser request</h2><p>Private preview of the student's DOCX form.</p></div>
                <button type="button" class="adviser-docx-close" data-docx-preview-close>Close</button>
            </header>
            <p class="adviser-docx-status" data-docx-preview-status role="status" aria-live="polite">Select Preview in RMS to load the document.</p>
            <div class="adviser-docx-preview-scroll" data-docx-preview-scroll tabindex="0" aria-label="Adviser request document preview" hidden>
                <div data-docx-preview-content></div>
            </div>
            <footer class="adviser-docx-dialog-footer">
                <a class="adviser-request-button adviser-request-button--document" data-docx-preview-download href="#">Download DOCX</a>
            </footer>
        </div>
    </dialog>
    <?php endif; ?>
    <section class="faculty-profile-grid faculty-profile-grid--single" aria-label="Research advising assignments">
        <article class="faculty-panel faculty-panel-large">
            <div class="faculty-panel-head">
                <div><p class="faculty-panel-label">Mentorship</p><h3>Research advising assignments</h3></div>
            </div>
            <form class="faculty-list-filter" method="get" action="<?= facultyAdvisingEscape(SITE_URL . 'pages/faculty/faculty-advising.php') ?>">
                <?= facultyAdvisingHiddenFields(['requests_search', 'requests_status', 'requests_course', 'requests_per_page', 'requests_page']) ?>
                <label class="faculty-filter-field faculty-filter-field--search">Search assignments
                    <input type="search" name="assignments_search" maxlength="100" value="<?= facultyAdvisingEscape($assignmentSearch) ?>" placeholder="Research title or student">
                </label>
                <label class="faculty-filter-field">Project status
                    <select name="assignments_status">
                        <option value="">All statuses</option>
                        <?php foreach ($assignmentStatusOptions as $statusOption): ?>
                            <option value="<?= facultyAdvisingEscape($statusOption) ?>"<?= $assignmentStatus === $statusOption ? ' selected' : '' ?>><?= facultyAdvisingEscape(ucwords(str_replace('_', ' ', $statusOption))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="faculty-filter-field">Course
                    <select name="assignments_course">
                        <option value="">All courses</option>
                        <?php foreach ($courseOptions as $courseOption): ?>
                            <option value="<?= facultyAdvisingEscape($courseOption) ?>"<?= $assignmentCourse === $courseOption ? ' selected' : '' ?>><?= facultyAdvisingEscape($courseOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="faculty-filter-field faculty-filter-field--page-size">Rows per page
                    <select name="assignments_per_page">
                        <?php foreach ([5, 10, 20] as $pageSizeOption): ?>
                            <option value="<?= $pageSizeOption ?>"<?= $assignmentPageSize === $pageSizeOption ? ' selected' : '' ?>><?= $pageSizeOption ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="faculty-filter-submit">Apply filters</button>
            </form>
            <?php if (!$advisingProjects): ?>
                <p><?= $assignmentTotal > 0 ? 'No assignments on this page.' : 'No advising assignments match these filters.' ?></p>
            <?php else: ?>
                <ul class="faculty-list">
                    <?php foreach ($advisingProjects as $project): ?>
                        <?php $studentResearchers = trim((string) ($project['student_researchers'] ?? '')); ?>
                        <li>
                            <div>
                                <strong><?= facultyAdvisingEscape($project['title']) ?></strong>
                                <small>Students · course: <?= facultyAdvisingEscape($studentResearchers !== '' ? $studentResearchers : 'Not recorded') ?></small>
                                <small>Updated <?= facultyAdvisingEscape(date('M j, Y', strtotime((string) $project['updated_at']))) ?></small>
                                <a class="faculty-project-progress-link" href="<?= SITE_URL ?>pages/shared/research-detail.php?id=<?= (int) $project['project_id'] ?>">View research progress</a>
                            </div>
                            <span class="faculty-status-badge faculty-status-badge--review"><?= facultyAdvisingEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?= facultyAdvisingRenderPagination('assignments_page', $assignmentPage, $assignmentPageSize, $assignmentTotal) ?>
        </article>
    </section>
</main>
<style>
.adviser-request-alert{padding:12px 14px;border:1px solid;border-radius:10px}.adviser-request-alert--error{border-color:#FECACA;background:#FEF2F2;color:#991B1B}.adviser-request-alert--success{border-color:#BBF7D0;background:#F0FDF4;color:#166534}.adviser-request-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.adviser-request-actions form{margin:0}.adviser-request-button{min-height:34px;padding:6px 11px;border:1px solid #D1D5DB;border-radius:8px;background:#fff;color:#1F2937;font:600 12px inherit;cursor:pointer}.adviser-request-button--document{display:inline-flex;align-items:center;justify-content:center;border-color:#1D4ED8;background:#fff;color:#1D4ED8;text-decoration:none;white-space:nowrap}.adviser-request-button--accept{border-color:#1D4ED8;background:#1D4ED8;color:#fff}.adviser-request-button--decline{border-color:#FECACA;color:#991B1B}.adviser-request-button:hover{filter:brightness(.97)}.adviser-request-button:focus-visible{outline:2px solid #1D4ED8;outline-offset:2px}@media(max-width:600px){.adviser-request-actions{align-items:stretch;flex-direction:column}.adviser-request-button--document{min-height:40px}}
.adviser-request-alert{padding:12px 14px;border:1px solid;border-radius:10px}.adviser-request-alert--error{border-color:#FECACA;background:#FEF2F2;color:#991B1B}.adviser-request-alert--success{border-color:#BBF7D0;background:#F0FDF4;color:#166534}.adviser-request-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.adviser-request-actions form{margin:0}.adviser-request-button{min-height:34px;padding:6px 11px;border:1px solid #D1D5DB;border-radius:8px;background:#fff;color:#1F2937;font:600 12px inherit;cursor:pointer}.adviser-request-button--document{display:inline-flex;align-items:center;justify-content:center;border-color:#1D4ED8;background:#fff;color:#1D4ED8;text-decoration:none;white-space:nowrap}.adviser-request-button--preview{border-color:#1D4ED8;background:#EFF6FF;color:#1D4ED8}.adviser-request-button--accept{border-color:#1D4ED8;background:#1D4ED8;color:#fff}.adviser-request-button--decline{border-color:#FECACA;color:#991B1B}.adviser-request-button:hover{filter:brightness(.97)}.adviser-request-button:focus-visible{outline:2px solid #1D4ED8;outline-offset:2px}.adviser-docx-dialog{width:min(980px,calc(100vw - 28px));max-width:none;max-height:min(92dvh,1100px);padding:0;border:1px solid #CBD5E1;border-radius:12px;background:#F1F5F9;color:#111827;box-shadow:0 24px 80px rgba(15,23,42,.28)}.adviser-docx-dialog::backdrop{background:rgba(15,23,42,.58)}.adviser-docx-dialog-inner{display:flex;max-height:min(92dvh,1100px);flex-direction:column;padding:18px}.adviser-docx-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:14px}.adviser-docx-dialog-header h2{margin:0;font-size:18px}.adviser-docx-dialog-header p{margin:4px 0 0;color:#64748B;font-size:13px}.adviser-docx-close{min-height:36px;padding:7px 12px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;color:#334155;font:inherit;font-weight:600;cursor:pointer}.adviser-docx-status{margin:0;padding:16px;border:1px solid #D8E0EA;border-radius:8px;background:#fff;color:#475569}.adviser-docx-preview-scroll{max-height:calc(92dvh - 140px);overflow:auto;border:1px solid #D8E0EA;border-radius:8px;background:#E2E8F0}.adviser-docx-preview-scroll [data-docx-preview-content]{min-height:100%;padding:20px 12px}.adviser-docx-preview-scroll .docx-wrapper{min-height:100%;padding:20px 12px;background:#E2E8F0}.adviser-docx-preview-scroll .docx-wrapper>section.docx{max-width:100%;margin:0 auto 18px;box-shadow:0 2px 12px rgba(15,23,42,.14)}.adviser-docx-dialog-footer{display:flex;justify-content:flex-end;padding-top:14px}@media(max-width:600px){.adviser-request-actions{align-items:stretch;flex-direction:column}.adviser-request-button--document,.adviser-request-button--preview{min-height:40px}.adviser-docx-dialog{width:calc(100vw - 16px);max-height:96dvh}.adviser-docx-dialog-inner{max-height:96dvh;padding:12px}.adviser-docx-preview-scroll{max-height:calc(96dvh - 132px)}}
</style>
<style>
    .adviser-request-actions{justify-content:space-between;gap:16px}
    .faculty-project-progress-link{display:inline-flex;align-items:center;margin-top:8px;color:#1D4ED8;font-size:12px;font-weight:600;text-decoration:none}
    .faculty-project-progress-link:hover{text-decoration:underline;text-underline-offset:3px}
    .faculty-project-progress-link:focus-visible{outline:2px solid #1D4ED8;outline-offset:3px;border-radius:3px}
    .adviser-request-document-actions{display:flex;flex:0 0 auto}
    .adviser-request-decision-actions{display:flex;align-items:center;gap:8px;margin-left:auto}
    .adviser-request-decision-actions form{flex:0 0 auto}
    @media(max-width:600px){
        .adviser-request-actions{align-items:stretch;flex-direction:column}
        .adviser-request-document-actions .adviser-request-button{width:100%}
        .adviser-request-decision-actions{margin-left:0}
        .adviser-request-decision-actions form{flex:1}
        .adviser-request-decision-actions button{width:100%}
    }
</style>
<style>
    .adviser-docx-dialog[open]{position:fixed;top:50%;left:50%;right:auto;bottom:auto;margin:0;transform:translate(-50%,-50%)}
</style>
<style>
    .faculty-list li.faculty-adviser-request-item{display:grid;grid-template-columns:minmax(0,1fr) max-content;align-items:center;gap:12px 24px}
    .faculty-adviser-request-item>div:first-child{min-width:0}
    .faculty-adviser-request-item>div:first-child strong{display:block;overflow-wrap:anywhere}
    .faculty-adviser-request-item .adviser-request-actions{width:max-content;max-width:100%;flex-wrap:nowrap;align-items:center;gap:16px}
    .faculty-adviser-request-item .adviser-request-button{font-family:inherit;font-size:12px;font-weight:600;line-height:1.2;white-space:nowrap}
    @media(max-width:900px){
        .faculty-list li.faculty-adviser-request-item{grid-template-columns:minmax(0,1fr)}
        .faculty-adviser-request-item .adviser-request-actions{grid-column:1;width:100%;justify-content:space-between}
    }
    @media(max-width:600px){
        .faculty-adviser-request-item .adviser-request-actions{align-items:stretch;flex-direction:column}
        .faculty-adviser-request-item .adviser-request-document-actions .adviser-request-button{width:100%}
        .faculty-adviser-request-item .adviser-request-decision-actions{margin-left:0}
        .faculty-adviser-request-item .adviser-request-decision-actions form{flex:1}
        .faculty-adviser-request-item .adviser-request-decision-actions button{width:100%}
    }
</style>
<style>
    .faculty-list-filter{display:grid;grid-template-columns:minmax(190px,1.5fr) minmax(125px,.85fr) minmax(125px,.85fr) minmax(105px,.55fr) auto;gap:12px;align-items:end;margin:16px 0;padding:16px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC}
    .faculty-filter-field{display:grid;min-width:0;gap:6px;color:#475569;font-size:12px;font-weight:600}
    .faculty-filter-field input,.faculty-filter-field select{width:100%;min-width:0;height:40px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;color:#1F2937;font:inherit;font-weight:400}
    .faculty-filter-field input:focus-visible,.faculty-filter-field select:focus-visible,.faculty-filter-submit:focus-visible,.faculty-list-pagination a:focus-visible{outline:2px solid #1D4ED8;outline-offset:2px}
    .faculty-filter-submit{min-height:40px;padding:0 14px;border:1px solid #1D4ED8;border-radius:8px;background:#1D4ED8;color:#fff;font:inherit;font-size:12px;font-weight:700;white-space:nowrap;cursor:pointer}
    .faculty-filter-submit:hover{background:#1E40AF}
    .faculty-list-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #E2E8F0;color:#64748B;font-size:12px}
    .faculty-list-pagination-actions{display:flex;align-items:center;gap:10px}
    .faculty-list-pagination-actions a,.faculty-list-pagination-actions span[aria-disabled="true"]{display:inline-flex;min-height:34px;align-items:center;padding:0 10px;border:1px solid #CBD5E1;border-radius:7px;background:#fff;color:#334155;text-decoration:none}
    .faculty-list-pagination-actions span[aria-disabled="true"]{color:#94A3B8;background:#F8FAFC}
    .faculty-list-pagination-actions>span:not([aria-disabled]){white-space:nowrap}
    @media(max-width:1000px){.faculty-list-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.faculty-filter-field--search{grid-column:1/-1}.faculty-filter-submit{justify-self:start}}
    @media(max-width:600px){.faculty-list-filter{grid-template-columns:minmax(0,1fr);padding:12px}.faculty-filter-field--search{grid-column:auto}.faculty-filter-submit{width:100%}.faculty-list-pagination{align-items:flex-start;flex-direction:column}.faculty-list-pagination-actions{width:100%;justify-content:space-between}}
</style>
<script src="<?= SITE_URL ?>public/assets/vendor/docx-preview/jszip.min.js" defer></script>
<script src="<?= SITE_URL ?>public/assets/vendor/docx-preview/docx-preview.min.js" defer></script>
<script src="<?= SITE_URL ?>public/assets/js/adviser-docx-preview.js" defer></script>
<?php renderFacultyShellClose(); ?>
