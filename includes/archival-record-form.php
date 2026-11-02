<?php
require_once __DIR__ . '/archival-record-ui.php';
if ($archiveKind === 'publication') { require __DIR__ . '/publication-archive-form.php'; return; }
if ($record && (!archiveCan($conn, $archiveKind, $record, $user, 'manage') || !empty($record['deactivated_at']))) archiveDeny();
$fields = [
    $c['title'] => ['Title', 'text', 500, true],
    $c['authors'] => ['Actual authors / creators', 'text', 255, true],
];
    $fields[$c['type']] = ['Record type', 'select', 60, true];
    $fields += ['co_authors' => ['Additional author / creator names', 'text', 500, false], 'filing_date' => ['Application / filing date', 'date', 10, false], 'application_number' => ['Application / reference number', 'text', 120, false], 'external_status' => [$archiveScope === '' ? 'External copyright status' : 'External registration / IP status', 'external', 60, !$record], 'copyright_ref_no' => ['Issued registration number', 'text', 120, false], 'registered_at' => ['Registration date', 'date', 10, false], 'date_completed' => ['Work completion date', 'date', 10, false]];
    if ($archiveScope === 'patents_utility_models') $fields['inventors'] = ['Inventors / researchers', 'text', 500, false];
    if ($archiveScope === 'innovation_technology_transfer') $fields += ['potential_partners' => ['Potential partners', 'text', 500, false], 'agreement_reference' => ['Agreement reference', 'text', 120, false], 'utilization_status' => ['Utilization status', 'text', 120, false], 'transfer_activities' => ['Technology-transfer activities', 'textarea', 10000, false]];
$fields['remarks'] = ['Remarks', 'textarea', 10000, false];
$values = [];
foreach ($fields as $key => $field) $values[$key] = (string) ($record[$key] ?? '');
if (!$record) $values[$c['type']] = isset($c['types'][$_GET[$c['type']] ?? '']) ? $_GET[$c['type']] : array_key_first($c['types']);
if ($archiveKind === 'copyright') $values['registered_at'] = substr($values['registered_at'], 0, 10);
$ownerId = $record[$c['owner']] ?? ($role === 'faculty' ? $userId : '');
$contributorsBefore = $record ? archiveContributors($conn, $archiveKind, $id) : [];
$selectedAuthors = array_map('intval', array_column($contributorsBefore, 'user_id'));
$version = $record ? hash('sha256', json_encode([$record, $contributorsBefore], JSON_THROW_ON_ERROR)) : '';
$allContributors = rmsOutputContributorOptions($conn);
$facultyOwners = archiveQuery($conn, "SELECT user_id, first_name, last_name, program FROM users WHERE role = 'faculty' AND deleted_at IS NULL AND status = 'active' ORDER BY last_name, first_name")->get_result()->fetch_all(MYSQLI_ASSOC);
// Keep historical associations visible even when an account is no longer active.
$knownIds = array_map('intval', array_column($allContributors, 'user_id'));
foreach (array_diff($selectedAuthors, $knownIds) as $uid) {
    $old = archiveQuery($conn, 'SELECT user_id, first_name, last_name, role FROM users WHERE user_id = ?', [$uid])->get_result()->fetch_assoc();
    if ($old) { $old['last_name'] .= ' (historical account)'; $allContributors[] = $old; }
}
$projectId = (string) ($record['research_project_id'] ?? '');
$publicationLinkId = (string) ($record['publication_id'] ?? '');
// Copyright publication_id is a link; publication publication_id is its own primary key.
$projects = archiveQuery($conn, 'SELECT project_id, title FROM research_projects WHERE deleted_at IS NULL' . ($role === 'faculty' ? ' AND (created_by = ? OR student_id = ? OR adviser_id = ?)' : '') . ' ORDER BY title', $role === 'faculty' ? [$userId,$userId,$userId] : [])->get_result()->fetch_all(MYSQLI_ASSOC);
$linkedPublications = $archiveKind === 'copyright' ? archiveQuery($conn, 'SELECT publication_id, research_title FROM publications WHERE 1=1' . ($role === 'faculty' ? ' AND researcher_id = ?' : '') . ' ORDER BY research_title', $role === 'faculty' ? [$userId] : [])->get_result()->fetch_all(MYSQLI_ASSOC) : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) $errors[] = 'Your form expired. Please try again.';
    if (($_POST['action'] ?? '') !== 'save_record' || (int) ($_POST['record_id'] ?? 0) !== $id) $errors[] = 'Invalid archive request.';
    foreach ($fields as $key => [$label,$type,$max,$required]) {
        $input = $_POST[$key] ?? '';
        $values[$key] = is_string($input) ? trim($input) : '';
        if (($required && $values[$key] === '') || mb_strlen($values[$key]) > $max) $errors[] = $label . ' is required where marked and must fit its maximum length.';
        if ($type === 'date' && $values[$key] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values[$key]);
            if (!$date || $date->format('Y-m-d') !== $values[$key]) $errors[] = 'Enter a valid ' . strtolower($label) . '.';
        }
    }
    if (!isset($c['types'][$values[$c['type']]])) $errors[] = 'Choose a supported record type.';
    if ($archiveKind === 'copyright') {
        if ($values['external_status'] !== '' && !isset(archiveExternalOptions()[$values['external_status']])) $errors[] = 'Choose a valid external status.';
        if ($values['external_status'] === 'registered' && ($values['copyright_ref_no'] === '' || $values['registered_at'] === '')) $errors[] = 'An issued registration requires its number and registration date.';
        if (in_array($values['external_status'], ['refused','withdrawn','other'], true) && $values['remarks'] === '') $errors[] = 'Document this external outcome in Remarks.';
    }
    $postedOwner = $_POST['owner_id'] ?? '';
    if ($role === 'admin' || (!$record && $role === 'research_staff')) {
        $ownerId = filter_var($postedOwner, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        // A normal edit preserves unresolved or historical ownership. Corrections require a current faculty account.
        $changedOwner = !$record || (string) ($record[$c['owner']] ?? '') !== (string) ($ownerId ?? '');
        if ($changedOwner && (!$ownerId || !in_array($ownerId, array_map('intval', array_column($facultyOwners, 'user_id')), true))) $errors[] = 'Choose an active Faculty Record Owner.';
        if ($record && $changedOwner && ($_POST['confirm_owner_change'] ?? '') !== '1') $errors[] = 'Confirm the explicit ownership correction.';
    } elseif (isset($_POST['owner_id']) && (string) $postedOwner !== (string) $ownerId) {
        archiveDeny();
    }
    $postedAuthors = $_POST['contributor_ids'] ?? [];
    $selectedAuthors = [];
    if (!is_array($postedAuthors) || count($postedAuthors) > 500) $errors[] = 'Invalid author selection.';
    else foreach ($postedAuthors as $candidate) {
        $uid = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$uid) { $errors[] = 'Invalid author selection.'; break; }
        $selectedAuthors[] = $uid;
    }
    $selectedAuthors = array_values(array_unique($selectedAuthors)); sort($selectedAuthors);
    $newAuthors = array_values(array_diff($selectedAuthors, array_map('intval', array_column($contributorsBefore, 'user_id'))));
    if (rmsValidateOutputContributorIds($conn, $newAuthors, 0) === null) $errors[] = 'New author links must refer to active faculty or student accounts.';
    $projectId = is_string($_POST['research_project_id'] ?? null) ? $_POST['research_project_id'] : '';
    if ($projectId !== (string) ($record['research_project_id'] ?? '') && $projectId !== '' && (!ctype_digit($projectId) || !in_array((int) $projectId, array_map('intval', array_column($projects, 'project_id')), true))) $errors[] = 'Choose an authorized originating research project.';
    if ($archiveKind === 'copyright') {
        $publicationLinkId = is_string($_POST['linked_publication_id'] ?? null) ? $_POST['linked_publication_id'] : '';
        if ($publicationLinkId !== (string) ($record['publication_id'] ?? '') && $publicationLinkId !== '' && (!ctype_digit($publicationLinkId) || !in_array((int) $publicationLinkId, array_map('intval', array_column($linkedPublications, 'publication_id')), true))) $errors[] = 'Choose an authorized publication link.';
    }
    $candidate = array_merge($record ?? [], $values, [$c['owner'] => $ownerId, 'archival_status' => $record['archival_status'] ?? 'archived']);
    if ($role === 'research_staff' && !archiveCan($conn, $archiveKind, $candidate, $user, 'manage')) archiveDeny();
    $file = $_FILES['supporting_file'] ?? null;
    $hasUpload = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload && mb_strlen(basename((string) ($file['name'] ?? ''))) > 255) $errors[] = 'The supporting filename is too long.';
    if (!$errors) {
        $uploaded = null;
        try {
            $conn->begin_transaction();
            $locked = $id ? archiveLoad($conn, $archiveKind, $id, true) : null;
            if ($id && (!$locked || !archiveCan($conn, $archiveKind, $locked, $user, 'manage') || !empty($locked['deactivated_at']))) throw new RuntimeException('This record is no longer editable.');
            if ($locked && !hash_equals(hash('sha256', json_encode([$locked, archiveContributors($conn, $archiveKind, $id)], JSON_THROW_ON_ERROR)), (string) ($_POST['record_version'] ?? ''))) throw new RuntimeException('The record changed while you were editing. Reopen it before saving.');
            // Recheck the selected owner under a lock before writing.
            if (!$record || (string) $ownerId !== (string) ($record[$c['owner']] ?? '')) {
                $owner = archiveQuery($conn, "SELECT user_id FROM users WHERE user_id = ? AND role = 'faculty' AND status = 'active' AND deleted_at IS NULL FOR UPDATE", [$ownerId])->get_result()->fetch_assoc();
                if (!$owner) throw new RuntimeException('The selected faculty owner is unavailable.');
            }
            $data = $values;
            foreach ($data as $key => $value) if ($value === '' && !$fields[$key][3]) $data[$key] = null;
            $data[$c['owner']] = $ownerId ?: null;
            $data[$c['category']] = $locked && (string) $ownerId === (string) ($locked[$c['owner']] ?? '') ? $locked[$c['category']] : 'faculty';
            $data['research_project_id'] = $projectId !== '' ? (int) $projectId : null;
            if ($archiveKind === 'copyright') {
                $data['publication_id'] = $publicationLinkId !== '' ? (int) $publicationLinkId : null;
                if ($locked && $values['registered_at'] === substr((string) ($locked['registered_at'] ?? ''), 0, 10)) $data['registered_at'] = $locked['registered_at'];
            }
            $data['last_modified_by'] = $userId;
            if (!$locked) { $data['encoded_by'] = $userId; $data['archival_status'] = 'archived'; }
            if ($hasUpload) {
                $uploaded = handleRmsUpload(['inputName'=>'supporting_file','folderTarget'=>$c['module'],'type'=>'other','accept'=>['.pdf'],'maxSize'=>(int)floor(rmsEffectiveUploadLimit()/1024),'archiveAuthorized'=>true],$_FILES,$conn);
                if (empty($uploaded['success'])) throw new RuntimeException('The supporting PDF could not be saved. Check its type and size.');
                $data['file_path'] = $uploaded['file_path']; $data['file_name'] = $uploaded['original_name'];
            }
            $columns = array_keys($data); $params = array_values($data);
            if ($locked) {
                $set = implode(', ', array_map(static fn($column) => "`$column` = ?", $columns));
                $params[] = $id;
                archiveQuery($conn, "UPDATE {$c['table']} SET $set, updated_at = CURRENT_TIMESTAMP WHERE {$c['id']} = ?", $params);
            } else {
                archiveQuery($conn, "INSERT INTO {$c['table']} (`" . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0,count($columns),'?')) . ')', $params);
                $id = $conn->insert_id;
            }
            archiveSyncAuthors($conn, $archiveKind, $id, $selectedAuthors);
            $beforeAudit = $locked ?? []; $beforeAudit['authors_linked'] = $contributorsBefore;
            $afterAudit = archiveLoad($conn, $archiveKind, $id); $afterAudit['authors_linked'] = archiveContributors($conn, $archiveKind, $id);
            archiveAudit($conn, $archiveKind, $id, $locked ? ($role === 'admin' ? 'admin_correction' : 'edited') : 'created', $beforeAudit, $afterAudit);
            $conn->commit();
            $_SESSION[$c['module'] . '_flash'] = ['type' => 'success', 'message' => $locked ? 'Record changes saved.' : 'Record saved to the archive.'];
            header('Location: ' . $detailUrl . '?id=' . $id . $navigationSuffix); exit;
        } catch (Throwable $e) {
            $conn->rollback();
            // Keep all previous supporting files. Only a failed new upload may be removed.
            if (!empty($uploaded['success'])) {
                $path = realpath(dirname(__DIR__) . '/' . $uploaded['file_path']);
                $root = realpath(UPLOADS_DIR . '/' . $c['module']);
                if ($path && $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) unlink($path);
            }
            $errors[] = $e instanceof mysqli_sql_exception ? 'The record could not be saved. No record changes were committed.' : $e->getMessage();
        }
    }
}
if (!$record && $role === 'research_staff') {
    $canCreate = false;
    foreach (array_keys($c['types']) as $type) if (archiveCan($conn, $archiveKind, [$c['type'] => $type, 'archival_status' => 'archived'], $user, 'manage')) $canCreate = true;
    if (!$canCreate) archiveDeny();
}
$pageHeading = $adminPublication ? ($record ? 'Edit record' : 'Add New') : (($record ? 'Edit ' : 'Add ') . ($archiveKind === 'publication' ? 'Publication Record' : 'Copyright Record'));
archiveShellOpen($user, $c, $pageHeading);
?>
<div class="archive-heading"><div><h1><?= archiveEscape($pageHeading) ?></h1><p class="archive-muted">Save directly to the Institute archive. Authorship is independent of ownership and encoding.</p></div><?php if (!$facultyPublication): ?><a class="archive-button secondary" href="<?= archiveEscape($record ? $detailUrl . '?id=' . $id . $navigationSuffix : $listUrl . ($archiveScope !== '' ? '?ip_scope=' . rawurlencode($archiveScope) : $navigationQuery)) ?>">Back</a><?php endif; ?></div>
<?php if ($errors): ?><div class="archive-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= archiveEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="archive-card">
<?= csrfField() ?><input type="hidden" name="action" value="save_record"><input type="hidden" name="record_id" value="<?= (int) $id ?>"><input type="hidden" name="record_version" value="<?= archiveEscape($version) ?>">
<div class="archive-grid">
<?php if ($role === 'admin' || (!$record && $role === 'research_staff')): ?>
<div class="archive-field full"><label for="owner-search">Search Faculty Owner</label><input id="owner-search" type="search" placeholder="Search name or program" aria-controls="owner-id"><label for="owner-id">Faculty Record Owner <?= !$record ? '*' : '' ?></label><select id="owner-id" name="owner_id" <?= !$record ? 'required' : '' ?>><option value="">Select faculty / unresolved legacy owner</option>
<?php if ($record && $record[$c['owner']] && !in_array((int) $record[$c['owner']], array_map('intval',array_column($facultyOwners,'user_id')),true)): ?><option value="<?= (int) $record[$c['owner']] ?>" <?= (int) $ownerId === (int) $record[$c['owner']] ? 'selected' : '' ?>><?= archiveEscape(archiveUserName($conn, $record[$c['owner']])) ?> (historical owner)</option><?php endif; ?>
<?php foreach ($facultyOwners as $owner): ?><option value="<?= (int) $owner['user_id'] ?>" <?= (int) $ownerId === (int) $owner['user_id'] ? 'selected' : '' ?>><?= archiveEscape(trim($owner['first_name'] . ' ' . $owner['last_name']) . ($owner['program'] ? ' — ' . $owner['program'] : '')) ?></option><?php endforeach; ?></select>
<?php if ($record): ?><label><input type="checkbox" name="confirm_owner_change" value="1" <?= ($_POST['confirm_owner_change'] ?? '') === '1' ? 'checked' : '' ?>> I am explicitly correcting record ownership.</label><?php endif; ?><small>Selecting an owner does not add an author. The authenticated account is recorded as encoder or last editor.</small></div>
<?php else: ?><div class="archive-field full"><span class="archive-label">Record owner</span><?= archiveEscape(archiveUserName($conn, $ownerId)) ?></div><?php endif; ?>
<?php foreach ($fields as $key => [$label,$type,$max,$required]): ?>
<div class="archive-field <?= in_array($key, [$c['title'],$c['authors'],'remarks'],true) ? 'full' : '' ?>"><label for="field-<?= archiveEscape($key) ?>"><?= archiveEscape($label) ?> <?= $required ? '*' : '' ?></label>
<?php if ($type === 'select' || $type === 'external'): ?><select id="field-<?= archiveEscape($key) ?>" name="<?= archiveEscape($key) ?>" <?= $required ? 'required' : '' ?>><?php if ($type === 'external'): ?><option value="">Not recorded</option><?php endif; ?><?php foreach ($type === 'select' ? $c['types'] : archiveExternalOptions() as $value => $text): ?><option value="<?= archiveEscape($value) ?>" <?= $values[$key] === $value ? 'selected' : '' ?>><?= archiveEscape($text) ?></option><?php endforeach; ?></select>
<?php elseif ($type === 'textarea'): ?><textarea id="field-<?= archiveEscape($key) ?>" name="<?= archiveEscape($key) ?>" maxlength="<?= $max ?>"><?= archiveEscape($values[$key]) ?></textarea>
<?php else: ?><input id="field-<?= archiveEscape($key) ?>" name="<?= archiveEscape($key) ?>" type="<?= archiveEscape($type) ?>" maxlength="<?= $max ?>" value="<?= archiveEscape($values[$key]) ?>" <?= $required ? 'required' : '' ?>><?php endif; ?>
<?php if ($key === 'journal_publisher'): ?><small>Required for journal, conference and book chapter records; optional for other types. Volume, issue and pages are optional.</small><?php endif; ?>
</div><?php endforeach; ?>
<fieldset class="archive-field full"><legend>Linked RMS authors / creators</legend><div class="archive-authors"><?php foreach ($allContributors as $author): ?><label><input type="checkbox" name="contributor_ids[]" value="<?= (int) $author['user_id'] ?>" <?= in_array((int) $author['user_id'],$selectedAuthors,true) ? 'checked' : '' ?>> <?= archiveEscape($author['first_name'] . ' ' . $author['last_name'] . ' (' . $author['role'] . ')') ?></label><?php endforeach; ?></div><small>Link actual contributors to this one record. Linked faculty may view the record; only its owner or authorized administrators/staff may edit it.</small></fieldset>
<div class="archive-field"><label for="project-id">Originating research project</label><select id="project-id" name="research_project_id"><option value="">Not linked</option><?php if ($record && $record['research_project_id'] && !in_array((int) $record['research_project_id'], array_map('intval',array_column($projects,'project_id')),true)): ?><option value="<?= (int) $record['research_project_id'] ?>" selected>Existing project #<?= (int) $record['research_project_id'] ?></option><?php endif; ?><?php foreach ($projects as $project): ?><option value="<?= (int) $project['project_id'] ?>" <?= (int) $projectId === (int) $project['project_id'] ? 'selected' : '' ?>><?= archiveEscape($project['title']) ?></option><?php endforeach; ?></select></div>
<?php if ($archiveKind === 'copyright'): ?><div class="archive-field"><label for="publication-link">Related publication</label><select id="publication-link" name="linked_publication_id"><option value="">Not linked</option><?php foreach ($linkedPublications as $linked): ?><option value="<?= (int) $linked['publication_id'] ?>" <?= (int) $publicationLinkId === (int) $linked['publication_id'] ? 'selected' : '' ?>><?= archiveEscape($linked['research_title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="archive-field full"><link rel="stylesheet" href="<?= archiveEscape(SITE_URL . 'css/file-uploader.css') ?>"><?php if (!empty($record['file_name'])): ?><p>Current document: <?= archiveEscape($record['file_name']) ?></p><?php endif; ?><?= renderFileUploader(['inputName'=>'supporting_file','accept'=>'.pdf','maxSize'=>(int)floor(rmsEffectiveUploadLimit()/1024),'folderTarget'=>$c['module'],'label'=>'Supporting PDF','required'=>false]) ?><small>Replacement documents retain the previous file and its reference in record history.</small></div>
</div><div class="archive-actions" style="margin-top:24px"><a href="<?= archiveEscape($listUrl . ($archiveScope !== '' ? '?ip_scope=' . rawurlencode($archiveScope) : $navigationQuery)) ?>">Cancel</a><button type="submit" class="archive-button"><?= $record ? 'Save changes' : 'Save record' ?></button></div>
</form><script src="<?= archiveEscape(SITE_URL . 'js/file-uploader.js') ?>"></script><script src="<?= archiveEscape(SITE_URL . 'js/archival-records.js') ?>"></script>
<?php archiveShellClose($user); ?>
