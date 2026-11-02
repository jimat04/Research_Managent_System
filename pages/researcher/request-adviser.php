<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/file-uploader.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$userId = (int) $user['user_id'];
$projectId = filter_var($_POST['project_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$projectId) {
    http_response_code(404);
    exit('Research project not found.');
}

function studentAdviserRequestEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';
$eligibleProjectStatuses = ['title_approved', 'approved', 'ongoing'];

function adviserRequestTableExists(mysqli $connection): bool
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

$adviserRequestsAvailable = adviserRequestTableExists($conn);
if (!$adviserRequestsAvailable) {
    $error = 'The adviser request feature is unavailable because the required database table is not installed yet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$adviserRequestsAvailable) {
        $error = 'The adviser request feature is unavailable because the required database table is not installed yet.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } elseif (!rms_student_program_is_eligible($conn, $userId)) {
        $error = 'Only active BSIT and BSCS students may request an adviser.';
    } else {
        $facultyId = filter_var($_POST['faculty_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$facultyId) {
            $error = 'Choose an active faculty adviser.';
        } elseif (!$conn->begin_transaction()) {
            $error = 'The adviser request could not be started. Please try again.';
        } else {
            $uploadedFile = null;
            $requestCommitted = false;
            try {
                $projectStmt = $conn->prepare(
                    'SELECT rp.project_id, rp.title, rp.status, rp.created_by, rp.adviser_id,
                            EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?) AS is_member,
                            EXISTS(SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id IS NOT NULL) AS has_adviser
                       FROM research_projects rp
                      WHERE rp.project_id = ? AND rp.deleted_at IS NULL
                      FOR UPDATE'
                );
                if (!$projectStmt) {
                    throw new RuntimeException('Unable to verify the research project.');
                }
                $projectStmt->bind_param('ii', $userId, $projectId);
                $projectStmt->execute();
                $project = $projectStmt->get_result()->fetch_assoc();
                $projectStmt->close();

                if (!$project || ((int) $project['created_by'] !== $userId && !(int) $project['is_member'])) {
                    throw new RuntimeException('This research project is unavailable to your account.');
                }
                if (!in_array((string) $project['status'], $eligibleProjectStatuses, true)) {
                    throw new RuntimeException('An adviser may be requested only after the research title is approved.');
                }
                if ((int) ($project['adviser_id'] ?? 0) > 0 || (int) $project['has_adviser'] === 1) {
                    throw new RuntimeException('This project already has an active adviser.');
                }

                $pendingStmt = $conn->prepare("SELECT request_id FROM adviser_requests WHERE project_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
                if (!$pendingStmt) {
                    throw new RuntimeException('Adviser requests are not available yet.');
                }
                $pendingStmt->bind_param('i', $projectId);
                $pendingStmt->execute();
                $hasPendingRequest = $pendingStmt->get_result()->num_rows > 0;
                $pendingStmt->close();
                if ($hasPendingRequest) {
                    throw new RuntimeException('This project already has a pending adviser request.');
                }

                $facultyStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'faculty' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
                if (!$facultyStmt) {
                    throw new RuntimeException('Unable to verify the selected faculty account.');
                }
                $facultyStmt->bind_param('i', $facultyId);
                $facultyStmt->execute();
                $facultyExists = $facultyStmt->get_result()->num_rows === 1;
                $facultyStmt->close();
                if (!$facultyExists) {
                    throw new RuntimeException('Choose an active faculty adviser.');
                }

                $uploadedFile = handleRmsUpload([
                    'inputName' => 'request_document',
                    'folderTarget' => 'adviser_requests',
                    'maxSize' => 10000,
                    'accept' => ['.docx'],
                    'projectId' => $projectId,
                    'type' => 'other',
                ], $_FILES, $conn);
                if (empty($uploadedFile['success'])) {
                    throw new RuntimeException((string) ($uploadedFile['error'] ?? 'Upload the completed DOCX request form.'));
                }

                $insertStmt = $conn->prepare(
                    "INSERT INTO adviser_requests (project_id, requester_id, faculty_id, upload_id, status)
                     VALUES (?, ?, ?, ?, 'pending')"
                );
                if (!$insertStmt) {
                    throw new RuntimeException('Unable to prepare the adviser request.');
                }
                $uploadId = (int) $uploadedFile['upload_id'];
                $insertStmt->bind_param('iiii', $projectId, $userId, $facultyId, $uploadId);
                if (!$insertStmt->execute()) {
                    $insertStmt->close();
                    throw new RuntimeException('This project already has a pending adviser request.');
                }
                $insertStmt->close();

                if (!$conn->commit()) {
                    throw new RuntimeException('The adviser request could not be saved. Please try again.');
                }
                $requestCommitted = true;
                logActivity('Submitted adviser request for project #' . $projectId, 'adviser_requests');
                createNotification(
                    (int) $facultyId,
                    'New adviser request',
                    'A student requested your advising for ' . (string) $project['title'] . '.',
                    'info',
                    SITE_URL . 'pages/faculty/faculty-advising.php'
                );
                header('Location: ' . SITE_URL . 'pages/researcher/request-adviser.php?id=' . $projectId . '&saved=1');
                exit;
            } catch (Throwable $exception) {
                if (!$requestCommitted) {
                    $conn->rollback();
                }
                if (!$requestCommitted && is_array($uploadedFile) && !empty($uploadedFile['file_path'])) {
                    $relativePath = str_replace('\\', '/', (string) $uploadedFile['file_path']);
                    if (preg_match('#^uploads/adviser_requests/rms_[A-Za-z0-9._-]+\.docx$#D', $relativePath)) {
                        $uploadsRoot = realpath(UPLOADS_DIR);
                        $physicalPath = realpath(UPLOADS_DIR . DIRECTORY_SEPARATOR . 'adviser_requests' . DIRECTORY_SEPARATOR . basename($relativePath));
                        if ($uploadsRoot && $physicalPath
                            && strpos($physicalPath, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
                            && is_file($physicalPath)) {
                            unlink($physicalPath);
                        }
                    }
                }
                $error = $exception->getMessage();
            }
        }
    }
}

$projectStmt = $conn->prepare(
    'SELECT rp.project_id, rp.title, rp.status, rp.created_by, rp.adviser_id,
            EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?) AS is_member,
            EXISTS(SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id IS NOT NULL) AS has_adviser
       FROM research_projects rp
      WHERE rp.project_id = ? AND rp.deleted_at IS NULL
      LIMIT 1'
);
$projectStmt->bind_param('ii', $userId, $projectId);
$projectStmt->execute();
$project = $projectStmt->get_result()->fetch_assoc();
$projectStmt->close();
if (!$project || ((int) $project['created_by'] !== $userId && !(int) $project['is_member'])) {
    http_response_code(404);
    exit('Research project not found.');
}

$latestRequest = null;
if ($adviserRequestsAvailable) {
    $requestStmt = $conn->prepare(
        'SELECT ar.request_id, ar.status, ar.upload_id, ar.created_at,
                f.first_name AS faculty_first_name, f.last_name AS faculty_last_name
           FROM adviser_requests ar
           JOIN users f ON f.user_id = ar.faculty_id
          WHERE ar.project_id = ? AND ar.requester_id = ?
          ORDER BY ar.request_id DESC
          LIMIT 1'
    );
    $requestStmt->bind_param('ii', $projectId, $userId);
    $requestStmt->execute();
    $latestRequest = $requestStmt->get_result()->fetch_assoc() ?: null;
    $requestStmt->close();
}

$faculty = [];
if ($adviserRequestsAvailable) {
    $facultyStmt = $conn->prepare("SELECT user_id, first_name, last_name, department FROM users WHERE role = 'faculty' AND status = 'active' AND deleted_at IS NULL ORDER BY last_name, first_name");
    $facultyStmt->execute();
    $facultyResult = $facultyStmt->get_result();
    while ($row = $facultyResult->fetch_assoc()) {
        $faculty[] = $row;
    }
    $facultyStmt->close();
}

$canRequest = $adviserRequestsAvailable
    && in_array((string) $project['status'], $eligibleProjectStatuses, true)
    && (int) ($project['adviser_id'] ?? 0) === 0
    && (int) $project['has_adviser'] === 0
    && (!$latestRequest || (string) $latestRequest['status'] !== 'pending');
$saved = ($_GET['saved'] ?? '') === '1';

renderStudentShell($user, 'my-research', 'Request an Adviser', 'Request faculty advising for an approved research title.');
?>
<link rel="stylesheet" href="<?= SITE_URL ?>css/file-uploader.css">
<style>
.adviser-request-page{--request-accent:#5B1EBC;--request-border:#E2E8F0;max-width:960px;margin:0 auto;color:#111827}.adviser-request-header{margin:0 0 24px}.adviser-request-page h1{margin:0;font-size:30px;line-height:1.2;font-weight:700}.adviser-request-project{margin-top:18px;padding:12px 16px;border-left:3px solid var(--request-accent);background:#F1F4F9}.adviser-request-project-label{margin:0 0 3px;color:#64748B;font-size:12px;font-weight:600}.adviser-request-project-title{margin:0;color:#1F2937;font-size:16px;line-height:1.5;font-weight:600;overflow-wrap:anywhere}.adviser-request-panel{margin-top:20px;padding:clamp(20px,3vw,30px);border:1px solid var(--request-border);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.04)}.adviser-request-panel-heading{display:flex;align-items:flex-start;gap:14px;margin-bottom:24px}.adviser-request-step{display:grid;width:38px;height:38px;flex:0 0 38px;place-items:center;border:1px solid #DDD2F4;border-radius:10px;background:#F7F3FF;color:var(--request-accent);font-size:13px;font-weight:700}.adviser-request-panel-heading h2{margin:0;color:#111827;font-size:18px;line-height:1.35}.adviser-request-panel-heading p{margin:4px 0 0;color:#64748B;font-size:14px;line-height:1.5}.adviser-request-alert{margin:0 0 16px;padding:12px 14px;border:1px solid;border-radius:10px}.adviser-request-alert.error{border-color:#FECACA;background:#FEF2F2;color:#991B1B}.adviser-request-alert.success{border-color:#BBF7D0;background:#F0FDF4;color:#166534}.adviser-request-form{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:24px}.adviser-request-form label{display:grid;gap:8px;color:#334155;font-size:13px;font-weight:600}.adviser-request-form select{width:100%;min-height:48px;padding:10px 12px;border:1px solid #CBD5E1;border-radius:9px;background:#fff;color:#111827;font:inherit}.adviser-request-form select:focus-visible,.adviser-request-form button:focus-visible,.adviser-request-back:focus-visible,.adviser-request-template-link:focus-visible{outline:2px solid var(--request-accent);outline-offset:3px}.adviser-request-documents{min-width:0;padding-left:24px;border-left:1px solid var(--request-border)}.adviser-request-download-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid #EEF1F5}.adviser-request-download-copy{display:grid;gap:3px}.adviser-request-download-copy strong{color:#334155;font-size:13px}.adviser-request-download-copy span{color:#64748B;font-size:12px}.adviser-request-template-link{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:8px;padding:8px 12px;border:1px solid #D9CDEE;border-radius:8px;background:#FAF8FE;color:#4C1D95;font-size:13px;font-weight:600;text-align:center;text-decoration:none;transition:background-color .18s ease,border-color .18s ease,transform .18s ease}.adviser-request-template-link:hover{border-color:#BFA9E8;background:#F3EEFB}.adviser-request-template-link:active{transform:translateY(1px)}.adviser-request-page .rms-uploader-hint{display:none}.adviser-request-page .rms-file-uploader{margin-bottom:0}.adviser-request-page .rms-uploader-dropzone{padding:24px 18px}.adviser-request-page .rms-uploader-icon{font-size:34px;margin-bottom:8px}.adviser-request-page .rms-uploader-prompt{font-size:14px}.adviser-request-page .rms-uploader-description{margin-bottom:10px}.adviser-request-actions{grid-column:1/-1;display:flex;justify-content:flex-end;padding-top:18px;border-top:1px solid #EEF1F5}.adviser-request-form button{min-height:44px;padding:10px 18px;border:0;border-radius:9px;background:var(--request-accent);color:#fff;font:inherit;font-weight:600;cursor:pointer;transition:background-color .18s ease,transform .18s ease}.adviser-request-form button:hover{background:#4C19A5}.adviser-request-form button:active{transform:translateY(1px)}.adviser-request-back{display:inline-flex;align-items:center;gap:7px;margin-top:18px;color:#4C1D95;font-size:14px;font-weight:600;text-decoration:none}.adviser-request-back:hover{text-decoration:underline;text-underline-offset:3px}@media(max-width:760px){.adviser-request-page h1{font-size:26px}.adviser-request-form{grid-template-columns:1fr;gap:20px}.adviser-request-documents{padding:20px 0 0;border-top:1px solid var(--request-border);border-left:0}.adviser-request-actions{grid-column:auto}}@media(max-width:520px){.adviser-request-panel{padding:18px}.adviser-request-download-row{align-items:flex-start;flex-direction:column}.adviser-request-template-link{width:100%}.adviser-request-actions button{width:100%}}
.adviser-request-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0}
.adviser-request-summary>div{display:grid;align-content:start;gap:6px;min-width:0;padding:14px 16px;border:1px solid #E8ECF2;border-radius:11px;background:#FAFBFD}
.adviser-request-summary dt{color:#64748B;font-size:11px;font-weight:650;letter-spacing:.045em;text-transform:uppercase}
.adviser-request-summary dd{margin:0;color:#1F2937;font-size:14px;font-weight:600;line-height:1.45;overflow-wrap:anywhere}
.adviser-request-status{display:inline-flex;align-items:center;width:max-content;min-height:26px;padding:4px 9px;border-radius:6px;font-size:12px;font-weight:700}
.adviser-request-status.is-pending{background:#F1F5F9;color:#475569}.adviser-request-status.is-accepted{background:#ECFDF3;color:#15803D}.adviser-request-status.is-declined{background:#FEF2F2;color:#B42318}
.adviser-request-file-link{display:inline-flex;align-items:center;gap:7px;color:#4C1D95;font-size:13px;font-weight:650;text-decoration:none}
.adviser-request-file-link:hover{text-decoration:underline;text-underline-offset:3px}
.adviser-request-file-link:focus-visible{outline:2px solid var(--request-accent);outline-offset:3px;border-radius:3px}
@media(max-width:520px){.adviser-request-summary{grid-template-columns:1fr}}
</style>
<style>@media(prefers-reduced-motion:reduce){.adviser-request-template-link,.adviser-request-form button,.adviser-request-page .rms-uploader-dropzone{transition:none}}</style>
<main class="adviser-request-page">
    <header class="adviser-request-header">
        <h1>Request faculty advising</h1>
        <div class="adviser-request-project">
            <p class="adviser-request-project-label">Research project</p>
            <p class="adviser-request-project-title"><?= studentAdviserRequestEscape($project['title']) ?></p>
        </div>
    </header>
  <?php if ($error !== ''): ?><p class="adviser-request-alert error" role="alert"><?= studentAdviserRequestEscape($error) ?></p><?php endif; ?>
  <?php if ($saved): ?><p class="adviser-request-alert success" role="status">Your adviser request was submitted.</p><?php endif; ?>
  <?php if (!$adviserRequestsAvailable): ?>
    <section class="adviser-request-panel" aria-live="polite">
      <h2>Feature unavailable</h2>
      <p>The adviser request workflow is currently disabled until the student adviser request migration is applied in the database.</p>
    </section>
  <?php elseif ($latestRequest): ?>
    <section class="adviser-request-panel" aria-labelledby="request-status-heading">
      <div class="adviser-request-panel-heading">
        <span class="adviser-request-step" aria-hidden="true">02</span>
        <div>
          <h2 id="request-status-heading">Latest request</h2>
          <p>Check the response from your selected faculty adviser.</p>
        </div>
      </div>
      <?php
        $latestRequestStatus = (string) $latestRequest['status'];
        $latestRequestStatusClass = match ($latestRequestStatus) {
            'accepted' => 'accepted',
            'declined' => 'declined',
            default => 'pending',
        };
      ?>
      <dl class="adviser-request-summary">
        <div>
          <dt>Status</dt>
          <dd><span class="adviser-request-status is-<?= $latestRequestStatusClass ?>"><?= studentAdviserRequestEscape(ucfirst($latestRequestStatus)) ?></span></dd>
        </div>
        <div>
          <dt>Faculty adviser</dt>
          <dd><?= studentAdviserRequestEscape(trim((string) $latestRequest['faculty_first_name'] . ' ' . (string) $latestRequest['faculty_last_name'])) ?></dd>
        </div>
        <?php if ((int) $latestRequest['upload_id'] > 0): ?>
          <div>
            <dt>Submitted form</dt>
            <dd><a class="adviser-request-file-link" href="<?= SITE_URL ?>pages/shared/download-adviser-request.php?id=<?= (int) $latestRequest['request_id'] ?>">Download DOCX <span aria-hidden="true">&#8594;</span></a></dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>
  <?php endif; ?>
  <?php if ($canRequest && $faculty): ?>
        <section class="adviser-request-panel" aria-labelledby="request-form-heading">
            <div class="adviser-request-panel-heading"><span class="adviser-request-step" aria-hidden="true">01</span><div><h2 id="request-form-heading">Adviser request</h2><p>Choose an active faculty member and attach the completed form.</p></div></div>
      <form class="adviser-request-form" method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">
                <div>
                    <label for="faculty-id">Faculty adviser
                        <select id="faculty-id" name="faculty_id" required>
                            <option value="">Choose faculty</option>
                            <?php foreach ($faculty as $facultyMember): ?>
                                <option value="<?= (int) $facultyMember['user_id'] ?>"><?= studentAdviserRequestEscape(trim((string) $facultyMember['first_name'] . ' ' . (string) $facultyMember['last_name']) . (!empty($facultyMember['department']) ? ' · ' . $facultyMember['department'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="adviser-request-documents">
                    <div class="adviser-request-download-row">
                        <div class="adviser-request-download-copy"><strong>Request form</strong><span>DOCX · up to 10 MB</span></div>
                        <a class="adviser-request-template-link" href="<?= SITE_URL ?>public/assets/request-for-adviser.docx" download="request-for-adviser.docx"><span aria-hidden="true">↓</span>Download form</a>
                    </div>
                <?= renderFileUploader([
            'inputName' => 'request_document',
            'accept' => '.docx',
            'maxSize' => 10000,
            'folderTarget' => 'adviser_requests',
            'label' => 'Completed adviser request form',
            'description' => 'Upload your completed adviser request form',
            'allowedFormatsText' => 'DOCX · Max 10 MB',
            'required' => true,
            'projectId' => $projectId,
            'uploadEndpoint' => SITE_URL . 'public/api/upload.php',
        ]) ?>
                </div>
                <div class="adviser-request-actions"><button type="submit">Submit adviser request</button></div>
      </form>
    </section>
  <?php elseif (!$canRequest): ?>
    <section class="adviser-request-panel"><p>An adviser request is available only after title approval, while the project has no active adviser and no pending request.</p></section>
  <?php else: ?>
    <section class="adviser-request-panel"><p>No active faculty accounts are available for adviser requests.</p></section>
  <?php endif; ?>
    <a class="adviser-request-back" href="<?= SITE_URL ?>pages/researcher/my-research.php"><span aria-hidden="true">←</span>Back to My Research</a>
</main>
<script src="<?= SITE_URL ?>js/file-uploader.js"></script>
<?php renderStudentShellClose(); ?>
