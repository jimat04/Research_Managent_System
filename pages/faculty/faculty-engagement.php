<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

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

function facultyEngagementEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function facultyEngagementTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to check engagement setup: ' . $connection->error);
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function facultyEngagementRemoveUploadedFile(?string $relativePath): void
{
    if (!$relativePath || !preg_match('#^uploads/engagement/rms_[A-Za-z0-9._-]+\.(?:pdf|doc|docx)$#iD', $relativePath)) {
        return;
    }
    $root = realpath(UPLOADS_DIR);
    $path = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($relativePath, strlen('uploads/')))) : false;
    if ($root && $path && strpos($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
        unlink($path);
    }
}

$recordsAvailable = facultyEngagementTableExists($conn, 'faculty_engagement_records')
    && facultyEngagementTableExists($conn, 'uploads');
$userId = (int) $user['user_id'];
$view = (string) ($_GET['view'] ?? 'all');
$legacyHistoryFilters = $_GET;
unset($legacyHistoryFilters['view']);
if ($view === 'history') {
    $historyQuery = http_build_query($legacyHistoryFilters);
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-engagement-history.php' . ($historyQuery !== '' ? '?' . $historyQuery : ''));
    exit;
}
$legacyViewRedirects = [
    'training' => SITE_URL . 'pages/faculty/faculty-engagement.php',
    'conference' => SITE_URL . 'pages/faculty/faculty-engagement.php',
    'extension' => SITE_URL . 'pages/faculty/faculty-engagement.php',
    'collaboration' => SITE_URL . 'pages/faculty/faculty-engagement.php',
    'opportunities' => SITE_URL . 'pages/shared/activities.php',
];
if (isset($legacyViewRedirects[$view])) {
    $returnQuery = isset($_GET['saved']) && $_GET['saved'] === '1' ? '?saved=1' : '';
    header('Location: ' . $legacyViewRedirects[$view] . ($view === 'opportunities' ? '' : $returnQuery));
    exit;
}
$formError = '';
$formSuccess = isset($_GET['saved']) && $_GET['saved'] === '1' ? 'Your engagement record was submitted for verification.' : '';
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
$values = [
    'engagement_type' => '',
    'custom_engagement_type' => '',
    'title' => '',
    'participation_role' => '',
    'activity_date' => '',
    'organizer' => '',
    'location' => '',
    'description' => '',
    'research_project_id' => '',
];

$projects = [];
if ($recordsAvailable) {
    $projectStmt = $conn->prepare(
        'SELECT rp.project_id, rp.title
           FROM research_projects rp
          WHERE rp.deleted_at IS NULL
            AND (
                rp.created_by = ?
                OR rp.student_id = ?
                OR rp.adviser_id = ?
                OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?)
                OR EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?)
            )
          ORDER BY rp.updated_at DESC, rp.project_id DESC
          LIMIT 100'
    );
    if (!$projectStmt) {
        throw new RuntimeException('Unable to load linked research projects: ' . $conn->error);
    }
    $projectStmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $userId);
    $projectStmt->execute();
    $projectResult = $projectStmt->get_result();
    while ($row = $projectResult->fetch_assoc()) {
        $projects[] = $row;
    }
    $projectStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    if (!$recordsAvailable) {
        $formError = 'Engagement submissions are unavailable until database migration 024 is applied.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $formError = 'Your form has expired. Refresh the page and try again.';
    } else {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['activity_date']);
        $validDate = $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $values['activity_date'];
        $projectId = $values['research_project_id'] === ''
            ? null
            : filter_var($values['research_project_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!isset($engagementTypes[$values['engagement_type']])) {
            $formError = 'Select a valid engagement type.';
        } elseif ($values['title'] === '' || strlen($values['title']) > 255) {
            $formError = 'Provide an activity title of 1 to 255 characters.';
        } elseif (!isset($participationRoles[$values['participation_role']])) {
            $formError = 'Select a valid participation role.';
        } elseif (!$validDate) {
            $formError = 'Enter a valid activity date.';
        } elseif (strlen($values['organizer']) > 255 || strlen($values['location']) > 255 || strlen($values['description']) > 5000) {
            $formError = 'Organizer and location must be 255 characters or fewer; the description must be 5,000 characters or fewer.';
        } elseif ($values['engagement_type'] === 'other' && ($values['custom_engagement_type'] === '' || strlen($values['custom_engagement_type']) > 255)) {
            $formError = 'Specify the engagement type (1 to 255 characters) when selecting "Other Research Engagement".';
        } elseif ($values['research_project_id'] !== '' && !$projectId) {
            $formError = 'Select a valid linked research project.';
        } elseif ($projectId && !in_array((int) $projectId, array_map(static function ($project) {
            return (int) $project['project_id'];
        }, $projects), true)) {
            $formError = 'You can only link this record to a research project assigned to your account.';
        } elseif (!isset($_FILES['engagement_evidence']) || (int) ($_FILES['engagement_evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $formError = 'Attach supporting evidence before submitting this engagement record.';
        } else {
            $file = $_FILES['engagement_evidence'] ?? null;
            $hasEvidence = $file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $uploadResult = null;
            $savedPath = null;
            $conn->begin_transaction();
            if ($hasEvidence) {
                $uploadResult = handleRmsUpload([
                    'inputName' => 'engagement_evidence',
                    'folderTarget' => 'engagement',
                    'maxSize' => 10000,
                    'accept' => ['.pdf', '.doc', '.docx'],
                    'projectId' => null,
                    'chapterId' => null,
                    'type' => 'engagement',
                ], $_FILES, $conn);
                if (!$uploadResult['success']) {
                    $conn->rollback();
                    $formError = (string) ($uploadResult['error'] ?? 'Evidence upload failed.');
                } else {
                    $savedPath = (string) $uploadResult['file_path'];
                }
            }

            if ($formError === '') {
                $uploadId = $uploadResult && $uploadResult['success'] ? (int) $uploadResult['upload_id'] : null;
                $type = $values['engagement_type'];
                $customType = $values['engagement_type'] === 'other' && $values['custom_engagement_type'] !== '' ? $values['custom_engagement_type'] : null;
                $title = $values['title'];
                $role = $values['participation_role'];
                $activityDate = $values['activity_date'];
                $organizer = $values['organizer'] !== '' ? $values['organizer'] : null;
                $location = $values['location'] !== '' ? $values['location'] : null;
                $description = $values['description'] !== '' ? $values['description'] : null;
                $stmt = $conn->prepare(
                    "INSERT INTO faculty_engagement_records
                        (faculty_id, engagement_type, custom_engagement_type, title, participation_role, activity_date, organizer, location, description, research_project_id, evidence_upload_id, review_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
                if (!$stmt) {
                    $conn->rollback();
                    facultyEngagementRemoveUploadedFile($savedPath);
                    throw new RuntimeException('Unable to prepare engagement submission: ' . $conn->error);
                }
                $stmt->bind_param('issssssssii', $userId, $type, $customType, $title, $role, $activityDate, $organizer, $location, $description, $projectId, $uploadId);
                if (!$stmt->execute()) {
                    $dbError = $stmt->error;
                    $stmt->close();
                    $conn->rollback();
                    facultyEngagementRemoveUploadedFile($savedPath);
                    throw new RuntimeException('Unable to save engagement submission: ' . $dbError);
                }
                $stmt->close();
                if (!$conn->commit()) {
                    $conn->rollback();
                    facultyEngagementRemoveUploadedFile($savedPath);
                    throw new RuntimeException('Unable to finalize engagement submission: ' . $conn->error);
                }
                logActivity('Submitted faculty engagement: ' . $title, 'faculty_research_engagement');
                $returnQuery = $view === 'all' ? '?' : '?view=' . rawurlencode($view) . '&';
                header('Location: ' . SITE_URL . 'pages/faculty/faculty-engagement.php' . $returnQuery . 'saved=1');
                exit;
            }
        }
    }
}

renderFacultyShell($user, 'faculty-engagement.php', 'Add an Engagement Record', 'Submit activities for institutional verification.');
?>
<style>
.faculty-engagement-workspace{max-width:1200px;margin:0 auto}.engagement-panel{padding:24px;margin:0 0 24px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}.engagement-panel h2{margin:0 0 8px;color:#111827;font-size:22px}.engagement-panel>p{margin:0 0 20px;color:#64748b;line-height:1.6}.engagement-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.engagement-form label{display:grid;gap:6px;color:#475569;font-size:13px;font-weight:600}.engagement-form input,.engagement-form select,.engagement-form textarea{width:100%;box-sizing:border-box;min-height:42px;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#111827;font:inherit}.engagement-form textarea{min-height:110px;resize:vertical}.engagement-form input:focus-visible,.engagement-form select:focus-visible,.engagement-form textarea:focus-visible,.engagement-form button:focus-visible{outline:3px solid rgba(29,78,216,.2);outline-offset:2px;border-color:#1d4ed8}.engagement-form .full{grid-column:1/-1}.engagement-form-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px}.engagement-form-actions button{min-height:44px;padding:10px 18px;border:0;border-radius:9px;background:#1d4ed8;color:#fff;font:600 14px/1.2 Inter,Arial,sans-serif;cursor:pointer}.engagement-status{display:inline-flex;padding:6px 11px;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;text-transform:capitalize;white-space:nowrap}.engagement-status--verified{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.engagement-status--returned{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.engagement-status--pending{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}.engagement-message{padding:13px 16px;margin-bottom:18px;border-radius:10px;background:#eff6ff;color:#1e40af}.engagement-message.error{background:#fef2f2;color:#b91c1c}.engagement-history-item{position:relative;padding:22px 24px;margin:16px 0 0;border:1px solid #dbe5f1;border-left:3px solid #1d4ed8;border-radius:14px;background:linear-gradient(115deg,#f8fbff 0%,#fff 42%);transition:border-color .2s ease,box-shadow .2s ease}.engagement-history-item:first-of-type{margin-top:18px}.engagement-history-item:hover{border-color:#bfd0e5;box-shadow:0 5px 18px rgba(29,78,216,.07)}.engagement-history-item h3{display:flex;flex-wrap:wrap;align-items:flex-start;gap:10px 14px;margin:0 0 15px;color:#111827;font-size:18px;line-height:1.4;letter-spacing:-.015em}.engagement-history-item h3 .engagement-status{margin-top:1px}.engagement-history-meta{display:flex;flex-wrap:wrap;gap:9px 10px;color:#475569;font-size:13px}.engagement-history-meta>span{display:inline-flex;align-items:center;gap:5px;min-width:0;padding:6px 9px;border:1px solid #e6edf5;border-radius:8px;background:rgba(255,255,255,.82);line-height:1.4}.engagement-history-meta>span::before{color:#94a3b8;font-size:10px;font-weight:700;letter-spacing:.045em;text-transform:uppercase}.engagement-history-meta .engagement-meta-type::before{content:"Activity"}.engagement-history-meta .engagement-meta-date::before{content:"Date"}.engagement-history-meta .engagement-meta-role::before{content:"Role"}.engagement-history-meta .engagement-meta-organizer::before{content:"Organizer"}.engagement-history-meta .engagement-evidence-link{display:flex;align-items:center;gap:9px;width:fit-content;max-width:100%;margin-top:8px;padding:9px 12px;border:1px solid #dbe5f1;border-radius:9px;background:#fff;color:#1d4ed8;font-size:13px;font-weight:600;text-decoration:none;overflow-wrap:anywhere;transition:background-color .2s ease,border-color .2s ease}.engagement-history-meta .engagement-evidence-link::before{content:"📎";flex:none}.engagement-history-meta .engagement-evidence-link:hover{border-color:#93b4e5;background:#f4f8ff;text-decoration:underline}.engagement-history-meta .engagement-evidence-link:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}.engagement-history-item .engagement-description{max-width:82ch;margin:17px 0 0;color:#475569;line-height:1.7;white-space:pre-wrap}.engagement-history-item .engagement-review-comment{padding:12px 14px;margin:16px 0 0;border-left:3px solid #f59e0b;border-radius:0 9px 9px 0;background:#fffbeb;color:#713f12;line-height:1.6}.engagement-history-item p strong{color:#713f12}.engagement-note{padding:12px 14px;border-radius:9px;background:#fff7ed;color:#9a3412}@media(max-width:700px){.engagement-panel{padding:18px}.engagement-form{grid-template-columns:1fr}.engagement-form .full{grid-column:auto}.engagement-form-actions button{width:100%}.engagement-history-item{padding:18px 16px}.engagement-history-item h3{font-size:16px}.engagement-history-meta{align-items:stretch}.engagement-history-meta>span{max-width:100%;overflow-wrap:anywhere}.engagement-history-meta .engagement-evidence-link{width:100%;box-sizing:border-box}}@media(prefers-reduced-motion:reduce){.engagement-history-item,.engagement-history-meta .engagement-evidence-link{transition:none}}
</style>
<style>
.engagement-evidence .rms-file-uploader{margin:0;padding:18px 20px 20px;border:1px solid #dbe5f1;border-radius:14px;background:linear-gradient(145deg,#f8fbff 0%,#fff 68%)}
.engagement-evidence .rms-uploader-label{margin:0 0 4px;color:#172b4d;font-size:14px;font-weight:700;letter-spacing:-.01em}
.engagement-evidence .rms-uploader-description{margin:0 0 14px;color:#64748b;font-size:12px;line-height:1.55}
.engagement-evidence .rms-uploader-dropzone{display:grid;grid-template-columns:48px minmax(0,1fr);grid-template-areas:"icon prompt" "icon hint" "icon formats";align-items:center;column-gap:14px;row-gap:3px;padding:18px 20px;border:1px dashed #9db5d7;border-radius:11px;background:#fff;text-align:left;transition:border-color .2s ease,background-color .2s ease,box-shadow .2s ease}
.engagement-evidence .rms-uploader-dropzone:hover,.engagement-evidence .rms-uploader-dropzone:focus-visible{border:1px solid #1d4ed8;background:#f6f9ff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.engagement-evidence .rms-uploader-dropzone:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}
.engagement-evidence .rms-uploader-dropzone.drag-over{border:2px solid #1d4ed8;background:#eff6ff;box-shadow:0 6px 18px rgba(29,78,216,.12)}
.engagement-evidence .rms-uploader-icon{grid-area:icon;display:grid;place-items:center;width:46px;height:46px;margin:0;border-radius:12px;background:#eaf1ff;color:#1d4ed8;font-size:24px}
.engagement-evidence .rms-uploader-prompt{grid-area:prompt;margin:0;color:#172b4d;font-size:14px;font-weight:700}
.engagement-evidence .rms-uploader-hint{grid-area:hint;margin:0;color:#64748b;font-size:12px}
.engagement-evidence .rms-uploader-formats{grid-area:formats;width:max-content;padding:3px 8px;border:1px solid #e2e8f0;border-radius:5px;background:#f8fafc;color:#52627a;font-size:10px;font-weight:700;letter-spacing:.035em}
.engagement-evidence .rms-uploader-drag-prompt{grid-column:2;margin:2px 0 0;color:#1d4ed8;font-size:12px}
.engagement-evidence .rms-uploader-file-list{gap:8px;margin-top:10px}
.engagement-evidence .rms-uploader-file-item{border-color:#dbe5f1;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.engagement-evidence .rms-uploader-file-item:hover{border-color:#9db5d7;box-shadow:0 3px 10px rgba(29,78,216,.08)}
.engagement-evidence .rms-uploader-btn-remove:focus-visible,.engagement-evidence .rms-uploader-btn-replace:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}
.engagement-history-item{padding:24px 30px;border-radius:18px}.engagement-history-item h3{font-size:20px}.engagement-history-meta{font-size:16px}.engagement-history-meta>span::before{font-size:11px}.engagement-history-item .engagement-description{max-width:none;font-size:20px;line-height:1.6}.engagement-history-item .engagement-review-comment{font-size:16px}
.engagement-evidence .rms-uploader-input{width:1px;height:1px;min-height:0;padding:0;margin:-1px;border:0;background:transparent}
@media(max-width:700px){.engagement-history-item{padding:20px 18px}.engagement-history-item h3{font-size:18px}.engagement-history-meta{font-size:14px}.engagement-history-item .engagement-description{font-size:17px}}
@media(max-width:520px){.engagement-evidence .rms-file-uploader{padding:15px}.engagement-evidence .rms-uploader-dropzone{grid-template-columns:40px minmax(0,1fr);column-gap:11px;padding:16px 13px}.engagement-evidence .rms-uploader-icon{width:40px;height:40px;font-size:21px}.engagement-evidence .rms-uploader-file-item{align-items:flex-start;flex-wrap:wrap}.engagement-evidence .rms-uploader-file-actions{width:100%;justify-content:flex-end}}
@media(prefers-reduced-motion:reduce){.engagement-evidence .rms-uploader-dropzone,.engagement-evidence .rms-uploader-file-item{transition:none}}
</style>
<main class="faculty-engagement-workspace">
  <?php if ($formSuccess !== ''): ?><p class="engagement-message" role="status"><?= facultyEngagementEscape($formSuccess) ?></p><?php endif; ?>
  <?php if ($formError !== ''): ?><p class="engagement-message error" role="alert"><?= facultyEngagementEscape($formError) ?></p><?php endif; ?>
  <?php if (!$recordsAvailable): ?>
    <section class="engagement-panel"><h2>Engagement records are not installed</h2><p>Apply database migration 024 before submitting records.</p></section>
  <?php else: ?>
    <section class="engagement-panel">
      <h2>Submit an engagement record</h2>
      <p>Records are saved as pending and become verified contributions only after administrative review. Do not enter a verification decision here.</p>
      <form class="engagement-form" method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <label>Engagement type<select name="engagement_type" id="engagement_type" required><option value="">Choose a type</option><?php foreach ($engagementTypes as $key => $label): ?><option value="<?= facultyEngagementEscape($key) ?>" <?= $values['engagement_type'] === $key ? 'selected' : '' ?>><?= facultyEngagementEscape($label) ?></option><?php endforeach; ?></select></label>
        <label id="custom_engagement_type_label" style="<?= $values['engagement_type'] === 'other' ? 'display: grid;' : 'display: none;' ?>">Specify other research engagement<input type="text" name="custom_engagement_type" id="custom_engagement_type" maxlength="255" value="<?= facultyEngagementEscape($values['custom_engagement_type']) ?>" placeholder="e.g., Hackathon, Webinar, Industry Project" <?= $values['engagement_type'] === 'other' ? 'required' : '' ?>></label>
        <label>Participation role<select name="participation_role" required><option value="">Choose a role</option><?php foreach ($participationRoles as $key => $label): ?><option value="<?= facultyEngagementEscape($key) ?>" <?= $values['participation_role'] === $key ? 'selected' : '' ?>><?= facultyEngagementEscape($label) ?></option><?php endforeach; ?></select></label>
        <label class="full">Activity title<input type="text" name="title" maxlength="255" value="<?= facultyEngagementEscape($values['title']) ?>" required></label>
        <label>Activity date<input type="date" name="activity_date" value="<?= facultyEngagementEscape($values['activity_date']) ?>" required></label>
        <label>Organizer<input type="text" name="organizer" maxlength="255" value="<?= facultyEngagementEscape($values['organizer']) ?>"></label>
        <label>Location<input type="text" name="location" maxlength="255" value="<?= facultyEngagementEscape($values['location']) ?>"></label>
        <label>Related research project<select name="research_project_id"><option value="">Not linked</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['project_id'] ?>" <?= (string) $values['research_project_id'] === (string) $project['project_id'] ? 'selected' : '' ?>><?= facultyEngagementEscape($project['title']) ?></option><?php endforeach; ?></select></label>
        <label class="full">Description<textarea name="description" maxlength="5000" rows="4"><?= facultyEngagementEscape($values['description']) ?></textarea></label>
        <div class="full engagement-evidence"><?= renderFileUploader(['inputName' => 'engagement_evidence', 'accept' => '.pdf,.doc,.docx', 'maxSize' => 10000, 'folderTarget' => 'engagement', 'label' => 'Supporting evidence', 'description' => 'Upload a certificate, program, or supporting document', 'required' => true]); ?></div>
        <div class="engagement-form-actions full"><button type="submit">Submit for verification</button></div>
      </form>
      <script>
        (function() {
            var engagementTypeSelect = document.getElementById('engagement_type');
            var customTypeLabel = document.getElementById('custom_engagement_type_label');
            var customTypeInput = document.getElementById('custom_engagement_type');
            function toggleCustomType() {
                if (engagementTypeSelect.value === 'other') {
                    customTypeLabel.style.display = 'grid';
                    customTypeInput.required = true;
                } else {
                    customTypeLabel.style.display = 'none';
                    customTypeInput.required = false;
                    customTypeInput.value = '';
                }
            }
            engagementTypeSelect.addEventListener('change', toggleCustomType);
            toggleCustomType();
        })();
      </script>
      <p class="engagement-note">Evidence uploads are limited to PDF or Word documents, up to 10 MB. Only you and administrators can access evidence attached to your record.</p>
    </section>
  <?php endif; ?>
</main>
<script src="<?= facultyEngagementEscape(SITE_URL . 'js/file-uploader.js') ?>"></script>
<?php renderFacultyShellClose(); ?>
