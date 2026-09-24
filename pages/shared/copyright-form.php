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

function copyrightFormEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function copyrightFormStoredFile(string $relativePath): ?string
{
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!preg_match('#^uploads/copyrights/[A-Za-z0-9._-]+\.pdf$#D', $normalized)) return null;
    $uploadRoot = realpath(__DIR__ . '/../../uploads');
    $resolved = realpath(__DIR__ . '/../../' . $normalized);
    if (!$uploadRoot || !$resolved || !is_file($resolved)) return null;
    $prefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($resolved, $prefix) ? $resolved : null;
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$isOwnerRole = in_array($role, ['faculty', 'student'], true);
$isReviewer = in_array($role, ['research_staff', 'admin'], true);
if (!$isOwnerRole && !$isReviewer) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'copyright_applications'");
$copyrightsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) $tableCheck->free();

$typeOptions = ['software' => 'Software', 'research' => 'Research', 'instructional_material' => 'Instructional material', 'module' => 'Module', 'other' => 'Other'];
$statusOptions = ['pending' => 'Pending', 'under_review' => 'Under review', 'registered' => 'Registered', 'rejected' => 'Rejected'];
$currentName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
$editRequested = array_key_exists('id', $_GET);
$copyrightId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($editRequested && !$copyrightId) {
    header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php');
    exit;
}
$isEdit = $copyrightId !== null;
$existing = null;
$values = [
    'output_title' => '', 'applicant_name' => $isOwnerRole ? $currentName : '',
    'applicant_category' => $isOwnerRole ? $role : 'faculty', 'output_type' => 'research',
    'co_authors' => '', 'date_completed' => '', 'status' => 'pending',
    'file_path' => '', 'file_name' => '',
];
$errors = [];
if (!$copyrightsAvailable) $errors[] = 'The copyrights module is not installed.';

if ($isEdit && $copyrightsAvailable) {
    $stmt = $conn->prepare('SELECT copyright_id, applicant_id, applicant_name, applicant_category, output_title, output_type, co_authors, date_completed, file_path, file_name, status FROM copyright_applications WHERE copyright_id = ? LIMIT 1');
    $stmt->bind_param('i', $copyrightId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$existing) {
        header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php?id=' . $copyrightId);
        exit;
    }
    if ($isOwnerRole && ((int) $existing['applicant_id'] !== $userId || $existing['status'] !== 'pending')) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    foreach ($values as $key => $_value) $values[$key] = (string) ($existing[$key] ?? '');
    if ($isOwnerRole) {
        $values['applicant_name'] = $currentName;
        $values['applicant_category'] = $role;
        $values['status'] = 'pending';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['faculty', 'student', 'research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) $errors[] = 'Your form expired. Please try again.';
    if (!$copyrightsAvailable && !in_array('The copyrights module is not installed.', $errors, true)) $errors[] = 'The copyrights module is not installed.';

    $formMode = (string) ($_POST['form_mode'] ?? '');
    $postedId = filter_var($_POST['copyright_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    if (!in_array($formMode, ['create', 'edit'], true) || ($formMode === 'edit' && !$postedId)) {
        $errors[] = 'Invalid copyright application form request.';
    }
    $copyrightId = $formMode === 'edit' ? $postedId : null;
    $isEdit = $formMode === 'edit';
    $existing = null;
    if ($isEdit && $copyrightsAvailable) {
        $stmt = $conn->prepare('SELECT copyright_id, applicant_id, applicant_name, applicant_category, file_path, file_name, status FROM copyright_applications WHERE copyright_id = ? LIMIT 1');
        $stmt->bind_param('i', $copyrightId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$existing) {
            $errors[] = 'The copyright application you tried to edit was not found.';
        } elseif ($isOwnerRole && ((int) $existing['applicant_id'] !== $userId || $existing['status'] !== 'pending')) {
            header('Location: ' . SITE_URL . 'public/403.php');
            exit;
        } else {
            $values['file_path'] = (string) ($existing['file_path'] ?? '');
            $values['file_name'] = (string) ($existing['file_name'] ?? '');
        }
    }

    foreach (['output_title', 'output_type', 'co_authors', 'date_completed'] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    if ($isOwnerRole) {
        $values['applicant_name'] = $currentName;
        $values['applicant_category'] = $role;
        $values['status'] = 'pending';
    } else {
        $values['applicant_name'] = trim((string) ($_POST['applicant_name'] ?? ''));
        $categoryInput = trim((string) ($_POST['applicant_category'] ?? ''));
        $statusInput = trim((string) ($_POST['status'] ?? ''));
        $values['applicant_category'] = preg_match('/^(faculty|student)$/D', $categoryInput) ? $categoryInput : '';
        $values['status'] = preg_match('/^(pending|under_review|registered|rejected)$/D', $statusInput) ? $statusInput : '';
    }

    if ($values['output_title'] === '' || strlen($values['output_title']) > 500) $errors[] = 'Output title is required and must not exceed 500 characters.';
    if (!preg_match('/^(software|research|instructional_material|module|other)$/D', $values['output_type'])) $errors[] = 'Choose a valid output type.';
    if ($values['applicant_name'] === '' || strlen($values['applicant_name']) > 255) $errors[] = 'Applicant name is required and must not exceed 255 characters.';
    if (!preg_match('/^(faculty|student)$/D', $values['applicant_category'])) $errors[] = 'Choose a valid applicant category.';
    if (!preg_match('/^(pending|under_review|registered|rejected)$/D', $values['status'])) $errors[] = 'Choose a valid application status.';
    if (strlen($values['co_authors']) > 500) $errors[] = 'Co-authors must not exceed 500 characters.';
    if ($values['date_completed'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date_completed']);
        if (!$date || $date->format('Y-m-d') !== $values['date_completed']) $errors[] = 'Enter a valid completion date.';
    }

    $hasExistingFile = $existing && (string) ($existing['file_path'] ?? '') !== '';
    $file = $_FILES['supporting_file'] ?? null;
    $hasNewFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $validatedUpload = null;
    if (!$hasNewFile && !$hasExistingFile) {
        $errors[] = 'Attach a supporting PDF file.';
    } elseif ($hasNewFile) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'The PDF upload did not complete. Please try again.';
        } else {
            $originalName = basename((string) ($file['name'] ?? ''));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $size = (int) ($file['size'] ?? 0);
            if ($extension !== 'pdf') $errors[] = 'Only PDF files are accepted.';
            if ($size < 1 || $size > MAX_UPLOAD_SIZE) $errors[] = 'The PDF must not exceed ' . number_format(MAX_UPLOAD_SIZE / 1048576, 0) . ' MB.';
            if (strlen($originalName) > 255) $errors[] = 'The original file name must not exceed 255 characters.';
            if (!function_exists('finfo_open')) {
                $errors[] = 'Server-side file validation is unavailable.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file((string) ($file['tmp_name'] ?? ''));
                if ($mime !== 'application/pdf') $errors[] = 'The uploaded file is not a valid PDF.';
            }
            if (!$errors) $validatedUpload = ['tmp' => (string) $file['tmp_name'], 'original' => $originalName];
        }
    }

    if (!$errors) {
        $newFullPath = null;
        $newRelativePath = $hasExistingFile ? (string) $existing['file_path'] : null;
        $newOriginalName = $hasExistingFile ? (string) $existing['file_name'] : null;
        $oldFullPath = $hasExistingFile ? copyrightFormStoredFile((string) $existing['file_path']) : null;
        $saveCompleted = false;
        try {
            $conn->begin_transaction();
            if ($isEdit) {
                $lockSql = $isOwnerRole
                    ? "SELECT copyright_id FROM copyright_applications WHERE copyright_id = ? AND applicant_id = ? AND status = 'pending' FOR UPDATE"
                    : 'SELECT copyright_id FROM copyright_applications WHERE copyright_id = ? FOR UPDATE';
                $lock = $conn->prepare($lockSql);
                if ($isOwnerRole) $lock->bind_param('ii', $copyrightId, $userId);
                else $lock->bind_param('i', $copyrightId);
                $lock->execute();
                $lockedApplication = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$lockedApplication) throw new RuntimeException('Copyright application is no longer editable.');
            }

            if ($validatedUpload) {
                $targetDirectory = __DIR__ . '/../../uploads/copyrights';
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true)) throw new RuntimeException('Unable to create the copyright upload directory.');
                $uploadRoot = realpath(__DIR__ . '/../../uploads');
                $realTargetDirectory = realpath($targetDirectory);
                if (!$uploadRoot || !$realTargetDirectory || !str_starts_with($realTargetDirectory, rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) throw new RuntimeException('Invalid upload destination.');
                $safeName = 'copyright_' . bin2hex(random_bytes(12)) . '.pdf';
                $newFullPath = $realTargetDirectory . DIRECTORY_SEPARATOR . $safeName;
                if (!move_uploaded_file($validatedUpload['tmp'], $newFullPath)) throw new RuntimeException('Unable to save the uploaded PDF.');
                @chmod($newFullPath, 0644);
                $newRelativePath = 'uploads/copyrights/' . $safeName;
                $newOriginalName = $validatedUpload['original'];
            }

            $coAuthors = $values['co_authors'] !== '' ? $values['co_authors'] : null;
            $dateCompleted = $values['date_completed'] !== '' ? $values['date_completed'] : null;
            if ($isEdit && $isOwnerRole) {
                $stmt = $conn->prepare("UPDATE copyright_applications SET output_title = ?, applicant_name = ?, applicant_category = ?, output_type = ?, co_authors = ?, date_completed = ?, file_path = ?, file_name = ? WHERE copyright_id = ? AND applicant_id = ? AND status = 'pending'");
                $stmt->bind_param('ssssssssii', $values['output_title'], $values['applicant_name'], $values['applicant_category'], $values['output_type'], $coAuthors, $dateCompleted, $newRelativePath, $newOriginalName, $copyrightId, $userId);
            } elseif ($isEdit) {
                $stmt = $conn->prepare('UPDATE copyright_applications SET output_title = ?, applicant_name = ?, applicant_category = ?, output_type = ?, co_authors = ?, date_completed = ?, file_path = ?, file_name = ?, status = ? WHERE copyright_id = ?');
                $stmt->bind_param('sssssssssi', $values['output_title'], $values['applicant_name'], $values['applicant_category'], $values['output_type'], $coAuthors, $dateCompleted, $newRelativePath, $newOriginalName, $values['status'], $copyrightId);
            } else {
                $applicantId = $isOwnerRole ? $userId : null;
                $stmt = $conn->prepare('INSERT INTO copyright_applications (output_title, applicant_id, applicant_name, applicant_category, output_type, co_authors, date_completed, file_path, file_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sissssssss', $values['output_title'], $applicantId, $values['applicant_name'], $values['applicant_category'], $values['output_type'], $coAuthors, $dateCompleted, $newRelativePath, $newOriginalName, $values['status']);
            }
            $stmt->execute();
            if (!$isEdit) $copyrightId = (int) $stmt->insert_id;
            $stmt->close();
            $conn->commit();
            $saveCompleted = true;
        } catch (Throwable $exception) {
            $conn->rollback();
            if ($newFullPath && is_file($newFullPath)) @unlink($newFullPath);
            $errors[] = $exception->getMessage() === 'Copyright application is no longer editable.' ? $exception->getMessage() : 'The copyright application could not be saved.';
        }

        if ($saveCompleted) {
            if ($validatedUpload && $oldFullPath && $oldFullPath !== $newFullPath) @unlink($oldFullPath);
            if ($isEdit) {
                logActivity("Updated copyright application #{$copyrightId}: {$values['output_title']}", 'copyrights');
                $_SESSION['copyrights_flash'] = ['type' => 'success', 'message' => 'Copyright application updated.'];
            } else {
                $recipientIds = [];
                $recipients = $conn->prepare("SELECT user_id FROM users WHERE status = 'active' AND role IN ('research_staff', 'admin')");
                $recipients->execute();
                $result = $recipients->get_result();
                while ($recipient = $result->fetch_assoc()) $recipientIds[] = (int) $recipient['user_id'];
                $recipients->close();
                $message = $values['applicant_name'] . ' submitted the copyright application "' . $values['output_title'] . '".';
                foreach ($recipientIds as $recipientId) createNotification($recipientId, 'New copyright application', $message, 'info', SITE_URL . 'pages/shared/copyrights.php');
                logActivity("Created copyright application #{$copyrightId}: {$values['output_title']}", 'copyrights');
                $_SESSION['copyrights_flash'] = ['type' => 'success', 'message' => 'Copyright application submitted.'];
            }
            header('Location: ' . SITE_URL . 'pages/shared/copyright-detail.php?id=' . $copyrightId);
            exit;
        }
    }
}

$shell = match ($role) {
    'admin' => 'admin', 'research_staff' => 'staff', 'faculty' => 'faculty', default => 'student',
};
$pageTitle = $isEdit ? 'Edit Copyright Application' : 'Apply for Copyright';
$pageSubtitle = $isEdit ? 'Update application details and the supporting PDF' : 'Submit an output for copyright processing';
if ($shell === 'admin') renderAdminShell($user, 'copyrights.php', $pageTitle, $pageSubtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'copyrights.php', $pageTitle, $pageSubtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'copyrights.php', $pageTitle, $pageSubtitle);
else renderStudentShell($user, 'copyrights.php', $pageTitle, $pageSubtitle);
?>

<style>
.copyright-form-page{max-width:980px;margin:0 auto;color:#172033}.form-heading{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.form-heading h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.form-heading p{max-width:620px;margin:8px 0 0;color:#64748b;line-height:1.55}.back-link{color:#9a5b13;font-size:12px;font-weight:800;text-decoration:none}.form-card{overflow:hidden;border:1px solid #e9e1d5;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(88,62,30,.07)}.form-note{padding:13px 20px;border-bottom:1px solid #eee6db;background:#fcfaf7;color:#526176;font-size:12px}.form-errors{margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#991b1b}.form-errors strong{display:block;margin-bottom:6px}.form-errors ul{margin:0;padding-left:19px}.copyright-form{padding:26px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px 18px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.required{color:#b42318}.optional{margin-left:5px;color:#64748b;font-size:10px;font-weight:650}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#172033;font:inherit;font-size:13px}.field textarea{min-height:96px;resize:vertical;line-height:1.5}.field input[readonly]{background:#f8fafc;color:#526176}.field input:focus,.field select:focus,.field textarea:focus,.button:focus-visible,.back-link:focus-visible{border-color:#9a5b13;outline:3px solid rgba(154,91,19,.12);outline-offset:2px}.field small{display:block;margin-top:6px;color:#64748b;font-size:11px;line-height:1.45}.file-field{padding:17px;border:1px dashed #cdbb9e;border-radius:12px;background:#fcfaf7}.file-field input{padding:9px;background:#fff}.current-file{margin:0 0 10px;color:#9a5b13;font-size:12px;font-weight:750}.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #eee8e0}.button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;border:1px solid #9a5b13;border-radius:9px;padding:9px 17px;background:#9a5b13;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#79450b;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}@media(max-width:640px){.form-heading{display:block}.back-link{display:inline-block;margin-top:14px}.copyright-form{padding:20px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions{flex-direction:column-reverse}.button{width:100%;box-sizing:border-box}}
</style>

<div class="copyright-form-page">
  <header class="form-heading"><div><h1><?= copyrightFormEscape($pageTitle) ?></h1><p><?= copyrightFormEscape($pageSubtitle) ?></p></div><a class="back-link" href="<?= copyrightFormEscape($isEdit && $copyrightId ? SITE_URL . 'pages/shared/copyright-detail.php?id=' . $copyrightId : SITE_URL . 'pages/shared/copyrights.php') ?>">&larr; Back</a></header>
  <?php if ($errors): ?><div class="form-errors" role="alert"><strong>Review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= copyrightFormEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="form-card">
    <div class="form-note"><?= $isOwnerRole ? 'Your account is recorded as the applicant. Pending applications remain editable until review begins.' : 'Staff and administrators may submit on behalf of an applicant and set the workflow status.' ?></div>
    <form class="copyright-form" method="post" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="form_mode" value="<?= $isEdit ? 'edit' : 'create' ?>"><?php if ($isEdit && $copyrightId): ?><input type="hidden" name="copyright_id" value="<?= (int) $copyrightId ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="field full"><label for="output-title">Output title <span class="required">*</span></label><input id="output-title" name="output_title" type="text" maxlength="500" value="<?= copyrightFormEscape($values['output_title']) ?>" required></div>
        <div class="field"><label for="applicant-name">Applicant <span class="required">*</span></label><input id="applicant-name" name="applicant_name" type="text" maxlength="255" value="<?= copyrightFormEscape($values['applicant_name']) ?>" <?= $isOwnerRole ? 'readonly' : 'required' ?>></div>
        <div class="field"><label for="applicant-category">Category <span class="required">*</span></label><?php if ($isOwnerRole): ?><input type="text" value="<?= copyrightFormEscape(ucfirst($values['applicant_category'])) ?>" readonly><?php else: ?><select id="applicant-category" name="applicant_category" required><option value="faculty" <?= $values['applicant_category'] === 'faculty' ? 'selected' : '' ?>>Faculty</option><option value="student" <?= $values['applicant_category'] === 'student' ? 'selected' : '' ?>>Student</option></select><?php endif; ?></div>
        <div class="field"><label for="output-type">Output type <span class="required">*</span></label><select id="output-type" name="output_type" required><?php foreach ($typeOptions as $value => $label): ?><option value="<?= copyrightFormEscape($value) ?>" <?= $values['output_type'] === $value ? 'selected' : '' ?>><?= copyrightFormEscape($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="date-completed">Date completed</label><input id="date-completed" name="date_completed" type="date" value="<?= copyrightFormEscape($values['date_completed']) ?>"></div>
        <div class="field full"><label for="co-authors">Co-authors</label><textarea id="co-authors" name="co_authors" maxlength="500" placeholder="List co-authors or collaborators, separated by commas"><?= copyrightFormEscape($values['co_authors']) ?></textarea></div>
        <?php if ($isReviewer): ?><div class="field"><label for="application-status">Status <span class="required">*</span></label><select id="application-status" name="status" required><?php foreach ($statusOptions as $value => $label): ?><option value="<?= copyrightFormEscape($value) ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= copyrightFormEscape($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field full file-field"><label for="supporting-file">Supporting file (PDF) <?php if (!$values['file_path']): ?><span class="required">*</span><?php else: ?><span class="optional">optional replacement</span><?php endif; ?></label><?php if ($values['file_name']): ?><p class="current-file">Current file: <?= copyrightFormEscape($values['file_name']) ?></p><?php endif; ?><input id="supporting-file" name="supporting_file" type="file" accept=".pdf,application/pdf" <?= $values['file_path'] ? '' : 'required' ?>><small>PDF only, up to <?= copyrightFormEscape(number_format(MAX_UPLOAD_SIZE / 1048576, 0)) ?> MB. Uploading a new file replaces the current supporting document after the record saves.</small></div>
      </div>
      <div class="form-actions"><a class="button secondary" href="<?= copyrightFormEscape($isEdit && $copyrightId ? SITE_URL . 'pages/shared/copyright-detail.php?id=' . $copyrightId : SITE_URL . 'pages/shared/copyrights.php') ?>">Cancel</a><button class="button" type="submit"><?= $isEdit ? 'Save changes' : 'Submit application' ?></button></div>
    </form>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
