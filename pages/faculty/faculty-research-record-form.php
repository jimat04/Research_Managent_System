<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';
require_once __DIR__ . '/../../includes/faculty-ranks.php';

$user = facultyRecordRequireUser();
$ownerId = (int) $user['user_id'];
$editRequested = array_key_exists('id', $_GET);
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$available = false;
$record = $profile = null;
$workTypes = [];
try {
    $available = facultyRecordAvailable($conn);
    $workTypes = facultyRecordWorkTypes();
    $record = $available && $recordId ? facultyRecordLoadOwned($conn, $recordId, $ownerId) : null;
    $profile = $available ? facultyRecordProfile($conn, $ownerId) : null;
} catch (Throwable $exception) {
    error_log('Faculty research record setup failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $available = false;
}
if ($available && $editRequested && !$record) facultyRecordNotFound($user);
$isEdit = $record !== null;
if ($record && !isset($workTypes[$record['work_type']])) $workTypes[$record['work_type']] = $record['work_type_label'];
$values = array_fill_keys(array_keys(facultyRecordTextFields()), '');
$authors = [];
$sdgs = [];
$errors = [];
$facultyQuery = '';
$searchResults = [];
$searchPerformed = false;
$version = $record ? (int) $record['version'] : 0;
$token = '';
if ($record) {
    foreach ($values as $key => $_) $values[$key] = (string) ($record[$key] ?? '');
    $authors = $record['authors'];
    $sdgs = $record['sdgs'];
} elseif ($profile) {
    $authors[] = facultyRecordSuggestedAuthor($profile, 'lead');
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && $available && (!$record || $record['deleted_at'] === null)) {
    $input = facultyRecordReadInput($_POST);
    $values = $input['values'];
    $authors = $input['authors'];
    $sdgs = $input['sdgs'];
    $errors = $input['errors'];
    $intent = facultyRecordInput($_POST, 'form_action', $errors);
    $token = facultyRecordInput($_POST, 'creation_token', $errors);
    $facultyQuery = mb_substr(facultyRecordInput($_POST, 'faculty_query', $errors), 0, 120, 'UTF-8');
    $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ;
    if ($version === false || $version === null || ($isEdit && $version < 1)) $errors['form'] = 'The record version is invalid. Reload this form.';
    $version = (int) $version;
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) $errors['form'] = 'Your form expired. Reload the page and try again.';
    if (($_POST['form_complete'] ?? null) !== '1') $errors['form'] = 'The form was incomplete or exceeded the server input limit. Nothing was saved. Keep this page open and contact the administrator.';
    if (!$isEdit && !facultyRecordTokenValid($token)) $errors['form'] = 'This form expired. Open Add Research Record again.';
    if (!$errors) {
        try {
            if ($intent === 'save') {
                $validated = facultyRecordValidate($conn, $input, $workTypes, $record);
                $errors = $validated['errors'];
                if (!$errors) {
                    $savedId = facultyRecordSave($conn, $ownerId, $recordId, $version, $token, $validated);
                    $_SESSION['faculty_records_flash'] = $isEdit ? 'Research record updated.' : 'Research record saved.';
                    header('Location: ' . facultyRecordUrl('detail', ['id' => $savedId]));
                    exit;
                }
            } elseif ($intent === 'add_external') {
                $authors[] = ['record_author_id' => '', 'faculty_user_id' => '', 'author_type' => 'external', 'full_name' => '', 'position' => '', 'affiliation' => '', 'author_role' => ''];
            } elseif ($intent === 'search_faculty') {
                $searchPerformed = true;
                $searchResults = facultyRecordSearchFaculty($conn, $facultyQuery);
            } elseif (preg_match('/^add_internal:([1-9][0-9]*)$/D', $intent, $match)) {
                $candidate = facultyRecordProfile($conn, (int) $match[1]);
                if (!$candidate) $errors['authors'] = 'That faculty account is unavailable. Search again.';
                elseif (in_array((string) $candidate['user_id'], array_column($authors, 'faculty_user_id'), true)) $errors['authors'] = 'That faculty account is already in your author list.';
                else $authors[] = facultyRecordSuggestedAuthor($candidate);
            } elseif (preg_match('/^(move_up|move_down|remove):([0-9]+)$/D', $intent, $match)) {
                $index = (int) $match[2];
                if (!isset($authors[$index])) $errors['authors'] = 'That author is unavailable. Please try again.';
                elseif ($match[1] === 'remove') array_splice($authors, $index, 1);
                else {
                    $target = $index + ($match[1] === 'move_up' ? -1 : 1);
                    if (isset($authors[$target])) [$authors[$index], $authors[$target]] = [$authors[$target], $authors[$index]];
                    foreach ($authors as $authorIndex => &$orderedAuthor) $orderedAuthor['author_role'] = $authorIndex === 0 ? 'lead' : 'coauthor';
                    unset($orderedAuthor);
                }
            } else {
                $errors['form'] = 'Choose a supported form action.';
            }
        } catch (DomainException $exception) {
            $errors['form'] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Faculty research record form failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
            $errors['form'] = 'We could not save or load these changes. Your entries remain below. Please try again.';
        }
    }
} elseif (!$isPost && !$isEdit && $available) {
    $token = facultyRecordCreateToken();
}

if (!$available) http_response_code(503);
if ($record && $record['deleted_at'] !== null) http_response_code(409);
renderFacultyShell($user, 'faculty-research-record-form', $isEdit ? 'Edit Research Record' : 'Add Research Record', 'Keep research information ready to reuse.');
facultyRecordAssets();

$field = static function (string $key, string $label, string $type = 'text', bool $required = false) use (&$values, &$errors): void {
    $limit = facultyRecordTextFields()[$key] ?? 500;
    $error = $errors[$key] ?? '';
    $describedBy = trim(($type === 'month' ? 'frr-month-hint ' : '') . ($error ? 'frr-error-' . $key : ''));
    ?>
    <div class="frr-field">
      <label for="frr-<?= facultyRecordEscape($key) ?>"><?= facultyRecordEscape($label) ?><?= $required ? ' <span aria-hidden="true">*</span>' : '' ?></label>
      <input id="frr-<?= facultyRecordEscape($key) ?>" name="<?= facultyRecordEscape($key) ?>" type="<?= facultyRecordEscape($type) ?>" value="<?= facultyRecordEscape($values[$key]) ?>" maxlength="<?= $limit ?>"<?= $type === 'month' ? ' data-month-picker' : '' ?><?= $required ? ' required' : '' ?><?= $type === 'month' ? ' min="1000-01" max="9999-12"' : '' ?><?= $type === 'date' ? ' min="1000-01-01" max="9999-12-31"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . facultyRecordEscape($describedBy) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?>>
      <?php if ($error): ?><p class="frr-error" id="frr-error-<?= facultyRecordEscape($key) ?>"><?= facultyRecordEscape($error) ?></p><?php endif; ?>
    </div>
    <?php
};
$select = static function (string $key, string $label, array $options, bool $required = false) use (&$values, &$errors): void {
    ?>
    <div class="frr-field">
      <label for="frr-<?= facultyRecordEscape($key) ?>"><?= facultyRecordEscape($label) ?><?= $required ? ' <span aria-hidden="true">*</span>' : '' ?></label>
      <select id="frr-<?= facultyRecordEscape($key) ?>" name="<?= facultyRecordEscape($key) ?>"<?= $required ? ' required' : '' ?><?= isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="frr-error-' . facultyRecordEscape($key) . '"' : '' ?>>
        <option value=""><?= $required ? 'Choose a type' : 'Not specified' ?></option>
        <?php foreach ($options as $value => $text): ?><option value="<?= facultyRecordEscape($value) ?>"<?= $values[$key] === $value ? ' selected' : '' ?>><?= facultyRecordEscape($text) ?></option><?php endforeach; ?>
      </select>
      <?php if (isset($errors[$key])): ?><p class="frr-error" id="frr-error-<?= facultyRecordEscape($key) ?>"><?= facultyRecordEscape($errors[$key]) ?></p><?php endif; ?>
    </div>
    <?php
};
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(facultyRecordUrl('list')) ?>">← My Research Records</a>
  <?php if (!$available): ?>
    <section class="frr-panel"><h2>Research records are unavailable</h2><p>Please try again or ask the administrator to check the research-record database setup.</p></section>
  <?php elseif ($record && $record['deleted_at'] !== null): ?>
    <section class="frr-panel"><h2>Restore this record before editing</h2><p>Your deleted record and its authors are recoverable.</p><a class="frr-btn" href="<?= facultyRecordEscape(facultyRecordUrl('detail', ['id' => $recordId])) ?>">View recoverable record</a></section>
  <?php else: ?>
    <div class="frr-page-intro"><p class="frr-eyebrow"><?= $isEdit ? 'Record #' . (int) $recordId : 'Faculty research' ?></p><h2><?= $isEdit ? 'Update your research information' : 'Enter it once. Keep it together.' ?></h2><p>Start with a title, work type, and author list. Dates, SDGs, conference, and journal details can be added later. Required fields are marked *.</p></div>
    <?php if ($errors): ?><div class="frr-alert frr-alert--error" role="alert" tabindex="-1" data-error-summary><h3>Please review these details</h3><ul><?php foreach ($errors as $message): ?><li><?= facultyRecordEscape($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?= facultyRecordEscape(facultyRecordUrl('form', $recordId ? ['id' => $recordId] : [])) ?>" class="frr-form" data-record-form>
      <?= csrfField() ?>
      <input type="hidden" name="version" value="<?= $version ?>">
      <input type="hidden" name="creation_token" value="<?= facultyRecordEscape($token) ?>">
      <section class="frr-panel" aria-labelledby="frr-research-heading">
        <div class="frr-section-heading"><span class="frr-section-number" aria-hidden="true">01</span><div><h3 id="frr-research-heading">Research information</h3><p>The scholarly work and its research dates.</p></div></div>
        <div class="frr-field"><label for="frr-title">Research title <span aria-hidden="true">*</span></label><textarea id="frr-title" name="title" rows="3" maxlength="2000" required aria-describedby="frr-title-hint<?= isset($errors['title']) ? ' frr-error-title' : '' ?>"<?= isset($errors['title']) ? ' aria-invalid="true"' : '' ?>><?= facultyRecordEscape($values['title']) ?></textarea><p class="frr-hint" id="frr-title-hint">Up to 2,000 characters. Different records may have the same title.</p><?php if (isset($errors['title'])): ?><p class="frr-error" id="frr-error-title"><?= facultyRecordEscape($errors['title']) ?></p><?php endif; ?></div>
        <div class="frr-grid">
          <?php $select('work_type', 'Type of scholarly work', $workTypes, true); $field('work_type_other', 'Other scholarly-work type'); ?>
        </div>
        <p class="frr-hint">These are RMS classifications. Choose conference or journal submission targets separately below.</p>
        <div class="frr-grid"><?php $field('date_started', 'Date started (month and year)', 'month'); $field('date_completed', 'Date completed (month and year)', 'month'); ?></div>
        <p class="frr-hint" id="frr-month-hint">Choose the month and year; the exact day is not required.</p>
      </section>
      <section class="frr-panel" aria-labelledby="frr-authors-heading">
        <div class="frr-section-heading"><span class="frr-section-number" aria-hidden="true">02</span><div><h3 id="frr-authors-heading">Research authors</h3><p>Choose one Lead Author and arrange everyone in the intended author order.</p></div></div>
        <p class="frr-hint">Your account is suggested as Lead Author. Changes here apply only to this record. <a href="<?= facultyRecordEscape(SITE_URL . 'pages/shared/profile.php') ?>" target="_blank" rel="noopener">Complete your Account Profile (opens a new tab)</a>.</p>
        <div data-author-list>
        <?php foreach ($authors as $index => $author): ?>
          <fieldset class="frr-author" data-author-row>
            <legend data-author-legend>Author <?= $index + 1 ?></legend>
            <input type="hidden" name="authors[<?= $index ?>][record_author_id]" value="<?= facultyRecordEscape($author['record_author_id']) ?>" data-author-key="record_author_id">
            <input type="hidden" name="authors[<?= $index ?>][faculty_user_id]" value="<?= facultyRecordEscape($author['faculty_user_id'] ?? '') ?>" data-author-key="faculty_user_id">
            <div class="frr-grid">
              <?php
              $rankOptions = array_merge(['' => ''], rmsFacultyAcademicRanks());
              foreach (['full_name' => ['Full name', 255], 'position' => ['Academic rank / position', 255], 'affiliation' => ['Institutional affiliation', 500]] as $key => [$label, $limit]):
              ?>
                <div class="frr-field<?= $key === 'affiliation' ? ' frr-field--wide' : '' ?>">
                  <label for="frr-author-<?= $index ?>-<?= $key ?>"><?= $label ?> *</label>
                  <?php if ($key === 'position'): ?>
                    <select id="frr-author-<?= $index ?>-<?= $key ?>" name="authors[<?= $index ?>][<?= $key ?>]" required data-author-key="<?= $key ?>">
                      <option value="">Choose academic rank</option>
                      <?php foreach ($rankOptions as $value => $sg): ?>
                        <option value="<?= facultyRecordEscape($value) ?>"<?= $author[$key] === $value ? ' selected' : '' ?>><?= facultyRecordEscape($value) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <input type="text" id="frr-author-<?= $index ?>-<?= $key ?>" name="authors[<?= $index ?>][<?= $key ?>]" value="<?= facultyRecordEscape($author[$key]) ?>" maxlength="<?= $limit ?>" required data-author-key="<?= $key ?>">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="frr-field"><label for="frr-author-<?= $index ?>-author_type">Internal / external author *</label><select id="frr-author-<?= $index ?>-author_type" name="authors[<?= $index ?>][author_type]" data-author-key="author_type" required><option value="internal"<?= $author['author_type'] === 'internal' ? ' selected' : '' ?>>Internal faculty</option><option value="external"<?= $author['author_type'] === 'external' ? ' selected' : '' ?>>External author</option></select></div>
              <div class="frr-field"><label for="frr-author-<?= $index ?>-author_role">Authorship role *</label><select id="frr-author-<?= $index ?>-author_role" name="authors[<?= $index ?>][author_role]" data-author-key="author_role" required><option value="">Choose a role</option><option value="lead"<?= $author['author_role'] === 'lead' ? ' selected' : '' ?>>Lead Author</option><option value="coauthor"<?= $author['author_role'] === 'coauthor' ? ' selected' : '' ?>>Co-Author</option></select></div>
            </div>
            <p class="frr-hint" data-author-account><?= $author['author_type'] === 'internal' && !empty($author['faculty_user_id']) ? 'Linked to a registered faculty account. Saved author details remain editable.' : ($author['author_type'] === 'internal' ? 'Historical internal author; account link is no longer available.' : 'External author; no RMS account is required.') ?></p>
            <div class="frr-author-actions"><button type="submit" name="form_action" value="move_up:<?= $index ?>" class="frr-btn frr-btn--small" formnovalidate data-author-action="move_up"<?= $index === 0 ? ' disabled' : '' ?> aria-label="Move author <?= $index + 1 ?> up">↑ Move up</button><button type="submit" name="form_action" value="move_down:<?= $index ?>" class="frr-btn frr-btn--small" formnovalidate data-author-action="move_down"<?= $index === count($authors) - 1 ? ' disabled' : '' ?> aria-label="Move author <?= $index + 1 ?> down">↓ Move down</button><button type="submit" name="form_action" value="remove:<?= $index ?>" class="frr-btn frr-btn--small frr-btn--danger-text" formnovalidate data-author-action="remove" aria-label="Remove author <?= $index + 1 ?>">Remove</button></div>
          </fieldset>
        <?php endforeach; ?>
        </div>
        <p class="frr-live" role="status" aria-live="polite" data-author-live></p>
        <button type="submit" name="form_action" value="add_external" class="frr-btn" formnovalidate data-add-external>+ Add external author</button>
        <div class="frr-faculty-search"><label for="frr-faculty-query">Add a registered faculty author</label><div class="frr-search-row"><input type="search" id="frr-faculty-query" name="faculty_query" maxlength="120" value="<?= facultyRecordEscape($facultyQuery) ?>" placeholder="Search faculty name" aria-describedby="frr-faculty-search-hint"><button type="submit" name="form_action" value="search_faculty" class="frr-btn" formnovalidate>Search faculty</button></div><p class="frr-hint" id="frr-faculty-search-hint">Use at least two characters. Up to 20 matches are shown; narrow your search if needed. Adding a co-author does not give them access to your record.</p>
          <?php if ($searchPerformed && !$searchResults): ?><p class="frr-hint">No matching faculty found. Try a different name.</p><?php endif; ?>
          <?php foreach ($searchResults as $candidate): $name = trim(implode(' ', array_filter([$candidate['first_name'], $candidate['middle_name'], $candidate['last_name']]))); ?>
            <div class="frr-faculty-result"><div><strong><?= facultyRecordEscape($name) ?></strong><p><?= facultyRecordEscape($candidate['academic_rank'] ?: $candidate['designation'] ?: 'Position not recorded') ?></p></div><button type="submit" name="form_action" value="add_internal:<?= (int) $candidate['user_id'] ?>" class="frr-btn frr-btn--small" formnovalidate>Add author</button></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="frr-panel" aria-labelledby="frr-sdg-heading">
        <div class="frr-section-heading"><span class="frr-section-number" aria-hidden="true">03</span><div><h3 id="frr-sdg-heading">SDG alignment</h3><p>Optional. Select all goals that apply to this research.</p></div></div>
        <div class="frr-sdg-grid"><?php foreach (facultyRecordSdgs() as $number => $label): ?><label class="frr-check"><input type="checkbox" name="sdgs[]" value="<?= $number ?>"<?= in_array($number, $sdgs, true) ? ' checked' : '' ?>><span><b><?= $number ?>.</b> <?= facultyRecordEscape($label) ?></span></label><?php endforeach; ?></div>
        <?php $field('sdg_other', 'Others / additional alignment (please specify)'); ?>
      </section>
      <section class="frr-panel" aria-labelledby="frr-conference-heading">
        <div class="frr-section-heading"><span class="frr-section-number" aria-hidden="true">04</span><div><h3 id="frr-conference-heading">Conference information</h3><p>Optional. Add one target conference now or complete it later.</p></div></div>
        <div class="frr-grid"><?php $field('conference_title', 'Conference title'); $field('conference_host', 'Conference host institution'); $field('conference_organizer', 'Conference organizer'); $select('conference_classification', 'Conference classification', facultyRecordConferenceClasses()); $field('conference_start_date', 'Conference start date', 'date'); $field('conference_end_date', 'Conference end date', 'date'); $select('presentation_modality', 'Presentation modality', facultyRecordModalities()); $field('conference_venue', 'Conference venue'); ?></div>
      </section>
      <section class="frr-panel" aria-labelledby="frr-journal-heading">
        <div class="frr-section-heading"><span class="frr-section-number" aria-hidden="true">05</span><div><h3 id="frr-journal-heading">Journal information</h3><p>Optional. Keep your target journal and publisher with this research.</p></div></div>
        <div class="frr-grid"><?php $field('journal_title', 'Target journal title'); $field('journal_publisher', 'Journal publisher'); ?></div>
      </section>
      <input type="hidden" name="form_complete" value="1">
      <div class="frr-form-footer"><a class="frr-btn" href="<?= facultyRecordEscape($recordId ? facultyRecordUrl('detail', ['id' => $recordId]) : facultyRecordUrl('list')) ?>">Cancel</a><button type="submit" name="form_action" value="save" class="frr-btn frr-btn--primary" data-save-record><?= $isEdit ? 'Save changes' : 'Save research record' ?></button></div>
      <noscript><p class="frr-hint">Author search, ordering, and removal work by reloading this form. Changes are saved only when you choose Save.</p></noscript>
    </form>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
