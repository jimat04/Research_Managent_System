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

function publicationFormEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function publicationFormStoredFile(string $relativePath): ?string
{
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!preg_match('#^uploads/publications/[A-Za-z0-9._-]+\.pdf$#D', $normalized)) return null;
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

$tableCheck = $conn->query("SHOW TABLES LIKE 'publications'");
$publicationsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) $tableCheck->free();

$typeOptions = ['journal' => 'Journal', 'conference' => 'Conference', 'book_chapter' => 'Book chapter', 'other' => 'Other'];
$statusOptions = ['submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'published' => 'Published'];
$currentName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
$editRequested = array_key_exists('id', $_GET);
$publicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($editRequested && !$publicationId) {
    header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php');
    exit;
}
$isEdit = $publicationId !== null;
$existing = null;
$values = [
    'research_title' => '', 'researcher_name' => $isOwnerRole ? $currentName : '',
    'researcher_category' => $isOwnerRole ? $role : 'faculty', 'publication_type' => 'journal',
    'journal_publisher' => '', 'publication_date' => '', 'doi_identifier' => '',
    'status' => 'submitted', 'file_path' => '', 'file_name' => '',
];
$errors = [];
if (!$publicationsAvailable) $errors[] = 'The publications module is not installed.';

if ($isEdit && $publicationsAvailable) {
    $stmt = $conn->prepare('SELECT publication_id, research_title, researcher_id, researcher_name, researcher_category, publication_type, journal_publisher, publication_date, doi_identifier, file_path, file_name, status FROM publications WHERE publication_id = ? LIMIT 1');
    $stmt->bind_param('i', $publicationId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$existing) {
        header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php?id=' . $publicationId);
        exit;
    }
    if ($isOwnerRole && ((int) $existing['researcher_id'] !== $userId || $existing['status'] !== 'submitted')) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    foreach ($values as $key => $_value) $values[$key] = (string) ($existing[$key] ?? '');
    if ($isOwnerRole) {
        $values['researcher_name'] = $currentName;
        $values['researcher_category'] = $role;
        $values['status'] = 'submitted';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['faculty', 'student', 'research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) $errors[] = 'Your form expired. Please try again.';
    if (!$publicationsAvailable && !in_array('The publications module is not installed.', $errors, true)) $errors[] = 'The publications module is not installed.';

    $formMode = (string) ($_POST['form_mode'] ?? '');
    $postedId = filter_var($_POST['publication_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    if (!in_array($formMode, ['create', 'edit'], true) || ($formMode === 'edit' && !$postedId)) {
        $errors[] = 'Invalid publication form request.';
    }
    $publicationId = $formMode === 'edit' ? $postedId : null;
    $isEdit = $formMode === 'edit';
    $existing = null;
    if ($isEdit && $publicationsAvailable) {
        $stmt = $conn->prepare('SELECT publication_id, researcher_id, researcher_name, researcher_category, file_path, file_name, status FROM publications WHERE publication_id = ? LIMIT 1');
        $stmt->bind_param('i', $publicationId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$existing) {
            $errors[] = 'The publication you tried to edit was not found.';
        } elseif ($isOwnerRole && ((int) $existing['researcher_id'] !== $userId || $existing['status'] !== 'submitted')) {
            header('Location: ' . SITE_URL . 'public/403.php');
            exit;
        } else {
            $values['file_path'] = (string) ($existing['file_path'] ?? '');
            $values['file_name'] = (string) ($existing['file_name'] ?? '');
        }
    }

    foreach (['research_title', 'publication_type', 'journal_publisher', 'publication_date', 'doi_identifier'] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    if ($isOwnerRole) {
        $values['researcher_name'] = $currentName;
        $values['researcher_category'] = $role;
        $values['status'] = 'submitted';
    } else {
        $values['researcher_name'] = trim((string) ($_POST['researcher_name'] ?? ''));
        $categoryInput = trim((string) ($_POST['researcher_category'] ?? ''));
        $statusInput = trim((string) ($_POST['status'] ?? ''));
        $values['researcher_category'] = preg_match('/^(faculty|student)$/D', $categoryInput) ? $categoryInput : '';
        $values['status'] = preg_match('/^(submitted|under_review|accepted|published)$/D', $statusInput) ? $statusInput : '';
    }

    if ($values['research_title'] === '' || strlen($values['research_title']) > 500) $errors[] = 'Research title is required and must not exceed 500 characters.';
    if (!preg_match('/^(journal|conference|book_chapter|other)$/D', $values['publication_type'])) $errors[] = 'Choose a valid publication type.';
    if ($values['researcher_name'] === '' || strlen($values['researcher_name']) > 255) $errors[] = 'Researcher name is required and must not exceed 255 characters.';
    if (!preg_match('/^(faculty|student)$/D', $values['researcher_category'])) $errors[] = 'Choose a valid researcher category.';
    if (!preg_match('/^(submitted|under_review|accepted|published)$/D', $values['status'])) $errors[] = 'Choose a valid publication status.';
    if (strlen($values['journal_publisher']) > 255) $errors[] = 'Journal or publisher must not exceed 255 characters.';
    if (strlen($values['doi_identifier']) > 255) $errors[] = 'DOI or identifier must not exceed 255 characters.';
    if ($values['publication_date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['publication_date']);
        if (!$date || $date->format('Y-m-d') !== $values['publication_date']) $errors[] = 'Enter a valid publication date.';
    }

    $hasExistingFile = $existing && (string) ($existing['file_path'] ?? '') !== '';
    $file = $_FILES['research_file'] ?? null;
    $hasNewFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $validatedUpload = null;
    if (!$hasNewFile && !$hasExistingFile) {
        $errors[] = 'Attach a PDF research file.';
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
        $oldFullPath = $hasExistingFile ? publicationFormStoredFile((string) $existing['file_path']) : null;
        $saveCompleted = false;
        try {
            $conn->begin_transaction();
            if ($isEdit) {
                $lockSql = $isOwnerRole
                    ? "SELECT publication_id FROM publications WHERE publication_id = ? AND researcher_id = ? AND status = 'submitted' FOR UPDATE"
                    : 'SELECT publication_id FROM publications WHERE publication_id = ? FOR UPDATE';
                $lock = $conn->prepare($lockSql);
                if ($isOwnerRole) $lock->bind_param('ii', $publicationId, $userId);
                else $lock->bind_param('i', $publicationId);
                $lock->execute();
                $lockedPublication = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$lockedPublication) throw new RuntimeException('Publication is no longer editable.');
            }

            if ($validatedUpload) {
                $targetDirectory = __DIR__ . '/../../uploads/publications';
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true)) throw new RuntimeException('Unable to create the publication upload directory.');
                $uploadRoot = realpath(__DIR__ . '/../../uploads');
                $realTargetDirectory = realpath($targetDirectory);
                if (!$uploadRoot || !$realTargetDirectory || !str_starts_with($realTargetDirectory, rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) throw new RuntimeException('Invalid upload destination.');
                $safeName = 'pub_' . bin2hex(random_bytes(12)) . '.pdf';
                $newFullPath = $realTargetDirectory . DIRECTORY_SEPARATOR . $safeName;
                if (!move_uploaded_file($validatedUpload['tmp'], $newFullPath)) throw new RuntimeException('Unable to save the uploaded PDF.');
                @chmod($newFullPath, 0644);
                $newRelativePath = 'uploads/publications/' . $safeName;
                $newOriginalName = $validatedUpload['original'];
            }

            $publisher = $values['journal_publisher'] !== '' ? $values['journal_publisher'] : null;
            $publicationDate = $values['publication_date'] !== '' ? $values['publication_date'] : null;
            $doi = $values['doi_identifier'] !== '' ? $values['doi_identifier'] : null;
            if ($isEdit && $isOwnerRole) {
                $stmt = $conn->prepare("UPDATE publications SET research_title = ?, researcher_name = ?, researcher_category = ?, publication_type = ?, journal_publisher = ?, publication_date = ?, doi_identifier = ?, file_path = ?, file_name = ? WHERE publication_id = ? AND researcher_id = ? AND status = 'submitted'");
                $stmt->bind_param('sssssssssii', $values['research_title'], $values['researcher_name'], $values['researcher_category'], $values['publication_type'], $publisher, $publicationDate, $doi, $newRelativePath, $newOriginalName, $publicationId, $userId);
            } elseif ($isEdit) {
                $stmt = $conn->prepare('UPDATE publications SET research_title = ?, researcher_name = ?, researcher_category = ?, publication_type = ?, journal_publisher = ?, publication_date = ?, doi_identifier = ?, file_path = ?, file_name = ?, status = ? WHERE publication_id = ?');
                $stmt->bind_param('ssssssssssi', $values['research_title'], $values['researcher_name'], $values['researcher_category'], $values['publication_type'], $publisher, $publicationDate, $doi, $newRelativePath, $newOriginalName, $values['status'], $publicationId);
            } else {
                $researcherId = $isOwnerRole ? $userId : null;
                $stmt = $conn->prepare('INSERT INTO publications (research_title, researcher_id, researcher_name, researcher_category, publication_type, journal_publisher, publication_date, doi_identifier, file_path, file_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sisssssssss', $values['research_title'], $researcherId, $values['researcher_name'], $values['researcher_category'], $values['publication_type'], $publisher, $publicationDate, $doi, $newRelativePath, $newOriginalName, $values['status']);
            }
            $stmt->execute();
            if (!$isEdit) $publicationId = (int) $stmt->insert_id;
            $stmt->close();
            $conn->commit();
            $saveCompleted = true;
        } catch (Throwable $exception) {
            $conn->rollback();
            if ($newFullPath && is_file($newFullPath)) @unlink($newFullPath);
            $errors[] = $exception->getMessage() === 'Publication is no longer editable.' ? $exception->getMessage() : 'The publication could not be saved.';
        }

        if ($saveCompleted) {
            if ($validatedUpload && $oldFullPath && $oldFullPath !== $newFullPath) @unlink($oldFullPath);
            if ($isEdit) {
                logActivity("Updated publication #{$publicationId}: {$values['research_title']}", 'publications');
                $_SESSION['publications_flash'] = ['type' => 'success', 'message' => 'Publication updated.'];
            } else {
                $recipientIds = [];
                $recipients = $conn->prepare("SELECT user_id FROM users WHERE status = 'active' AND role IN ('research_staff', 'admin')");
                $recipients->execute();
                $result = $recipients->get_result();
                while ($recipient = $result->fetch_assoc()) $recipientIds[] = (int) $recipient['user_id'];
                $recipients->close();
                $message = $values['researcher_name'] . ' submitted the publication "' . $values['research_title'] . '" for review.';
                foreach ($recipientIds as $recipientId) createNotification($recipientId, 'New publication submission', $message, 'info', SITE_URL . 'pages/shared/publications.php');
                logActivity("Created publication #{$publicationId}: {$values['research_title']}", 'publications');
                $_SESSION['publications_flash'] = ['type' => 'success', 'message' => 'Publication submitted for review.'];
            }
            header('Location: ' . SITE_URL . 'pages/shared/publication-detail.php?id=' . $publicationId);
            exit;
        }
    }
}

$shell = match ($role) {
    'admin' => 'admin', 'research_staff' => 'staff', 'faculty' => 'faculty', default => 'student',
};
$pageTitle = $isEdit ? 'Edit Publication' : 'Add Publication';
$pageSubtitle = $isEdit ? 'Update publication metadata and its PDF manuscript' : 'Submit a publication record and PDF manuscript';
if ($shell === 'admin') renderAdminShell($user, 'publications.php', $pageTitle, $pageSubtitle);
elseif ($shell === 'staff') renderStaffShell($user, 'publications.php', $pageTitle, $pageSubtitle);
elseif ($shell === 'faculty') renderFacultyShell($user, 'publications.php', $pageTitle, $pageSubtitle);
else renderStudentShell($user, 'publications.php', $pageTitle, $pageSubtitle);
?>

<style>
.publication-form-page{max-width:980px;margin:0 auto;color:#172033}.form-heading{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.form-heading h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.form-heading p{max-width:620px;margin:8px 0 0;color:#64748b;line-height:1.55}.back-link{color:#315b9f;font-size:12px;font-weight:800;text-decoration:none}.form-card{overflow:hidden;border:1px solid #dfe5ee;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(42,67,108,.07)}.form-note{padding:13px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#526176;font-size:12px}.form-errors{margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#991b1b}.form-errors strong{display:block;margin-bottom:6px}.form-errors ul{margin:0;padding-left:19px}.publication-form{padding:26px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px 18px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.required{color:#b42318}.optional{margin-left:5px;color:#64748b;font-size:10px;font-weight:650}.field input,.field select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#172033;font:inherit;font-size:13px}.field input[readonly]{background:#f8fafc;color:#526176}.field input:focus,.field select:focus,.button:focus-visible,.back-link:focus-visible{border-color:#315b9f;outline:3px solid rgba(49,91,159,.12);outline-offset:2px}.field small{display:block;margin-top:6px;color:#64748b;font-size:11px;line-height:1.45}.file-field{padding:17px;border:1px dashed #aebdd3;border-radius:12px;background:#f8fafc}.file-field input{padding:9px;background:#fff}.current-file{margin:0 0 10px;color:#315b9f;font-size:12px;font-weight:750}.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #e8edf3}.button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;border:1px solid #315b9f;border-radius:9px;padding:9px 17px;background:#315b9f;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#24477e;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}@media(max-width:640px){.form-heading{display:block}.back-link{display:inline-block;margin-top:14px}.publication-form{padding:20px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions{flex-direction:column-reverse}.button{width:100%;box-sizing:border-box}}
</style>

<div class="publication-form-page">
  <header class="form-heading"><div><h1><?= publicationFormEscape($pageTitle) ?></h1><p><?= publicationFormEscape($pageSubtitle) ?></p></div><a class="back-link" href="<?= publicationFormEscape($isEdit && $publicationId ? SITE_URL . 'pages/shared/publication-detail.php?id=' . $publicationId : SITE_URL . 'pages/shared/publications.php') ?>">&larr; Back</a></header>
  <?php if ($errors): ?><div class="form-errors" role="alert"><strong>Review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= publicationFormEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="form-card">
    <div class="form-note"><?= $isOwnerRole ? 'Your account is recorded as the researcher. Submitted records remain editable until review begins.' : 'Staff and administrators may submit on behalf of a researcher and set the workflow status.' ?></div>
    <form class="publication-form" method="post" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="form_mode" value="<?= $isEdit ? 'edit' : 'create' ?>"><?php if ($isEdit && $publicationId): ?><input type="hidden" name="publication_id" value="<?= (int) $publicationId ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="field full"><label for="research-title">Research title <span class="required">*</span></label><input id="research-title" name="research_title" type="text" maxlength="500" value="<?= publicationFormEscape($values['research_title']) ?>" required></div>
        <div class="field"><label for="researcher-name">Researcher <span class="required">*</span></label><input id="researcher-name" name="researcher_name" type="text" maxlength="255" value="<?= publicationFormEscape($values['researcher_name']) ?>" <?= $isOwnerRole ? 'readonly' : 'required' ?>></div>
        <div class="field"><label for="researcher-category">Category <span class="required">*</span></label><?php if ($isOwnerRole): ?><input type="text" value="<?= publicationFormEscape(ucfirst($values['researcher_category'])) ?>" readonly><?php else: ?><select id="researcher-category" name="researcher_category" required><option value="faculty" <?= $values['researcher_category'] === 'faculty' ? 'selected' : '' ?>>Faculty</option><option value="student" <?= $values['researcher_category'] === 'student' ? 'selected' : '' ?>>Student</option></select><?php endif; ?></div>
        <div class="field"><label for="publication-type">Publication type <span class="required">*</span></label><select id="publication-type" name="publication_type" required><?php foreach ($typeOptions as $value => $label): ?><option value="<?= publicationFormEscape($value) ?>" <?= $values['publication_type'] === $value ? 'selected' : '' ?>><?= publicationFormEscape($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="publication-date">Publication date</label><input id="publication-date" name="publication_date" type="date" value="<?= publicationFormEscape($values['publication_date']) ?>"></div>
        <div class="field full"><label for="journal-publisher">Journal or publisher</label><input id="journal-publisher" name="journal_publisher" type="text" maxlength="255" value="<?= publicationFormEscape($values['journal_publisher']) ?>"></div>
        <div class="field full"><label for="doi-identifier">DOI or identifier</label><input id="doi-identifier" name="doi_identifier" type="text" maxlength="255" value="<?= publicationFormEscape($values['doi_identifier']) ?>" placeholder="10.1234/example or https://doi.org/..."></div>
        <?php if ($isReviewer): ?><div class="field"><label for="publication-status">Status <span class="required">*</span></label><select id="publication-status" name="status" required><?php foreach ($statusOptions as $value => $label): ?><option value="<?= publicationFormEscape($value) ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= publicationFormEscape($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field full file-field"><label for="research-file">Research file (PDF) <?php if (!$values['file_path']): ?><span class="required">*</span><?php else: ?><span class="optional">optional replacement</span><?php endif; ?></label><?php if ($values['file_name']): ?><p class="current-file">Current file: <?= publicationFormEscape($values['file_name']) ?></p><?php endif; ?><input id="research-file" name="research_file" type="file" accept=".pdf,application/pdf" <?= $values['file_path'] ? '' : 'required' ?>><small>PDF only, up to <?= publicationFormEscape(number_format(MAX_UPLOAD_SIZE / 1048576, 0)) ?> MB. Uploading a new file replaces the current manuscript after the record saves.</small></div>
      </div>
      <div class="form-actions"><a class="button secondary" href="<?= publicationFormEscape($isEdit && $publicationId ? SITE_URL . 'pages/shared/publication-detail.php?id=' . $publicationId : SITE_URL . 'pages/shared/publications.php') ?>">Cancel</a><button class="button" type="submit"><?= $isEdit ? 'Save changes' : 'Submit publication' ?></button></div>
    </form>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
