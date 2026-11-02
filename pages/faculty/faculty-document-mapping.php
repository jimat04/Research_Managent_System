<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/earist-document-service.php';

$user = facultyRecordRequireUser();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$type = !isset($_GET['document']) ? 'annex_a' : (is_string($_GET['document']) ? $_GET['document'] : '');
$result = null;
$pageError = '';
$transportError = '';
$saveError = '';
$saveNotice = '';
$template = null;
$raw = [];
$addAi = false;
$action = 'review';
$requestId = '';
$expectedRevision = 0;
$discardOrphans = false;
$wasReviewAction = false;
$draft = null;
$orphanedAuthorInputs = [];
$reviewReady = false;
$currentFingerprint = '';
$versionContext = [];
$wizardStep = 1;
$postedWizardStep = 1;
$wizardErrorGroup = '';

function earistDocumentWizardAnswerGroup(string $type, string $key): array
{
    $groups = [
        'annex_a' => [
            'conference' => ['submission_target', 'conference_deadline', 'proceedings', 'conference_indexing', 'registration_fee', 'registration_currency'],
            'journal' => ['journal_deadline', 'peer_review_clear', 'journal_indexing', 'scope_aligned', 'scope_explanation', 'publication_fee', 'publication_currency'],
            'declarations' => ['ai_used', 'ai_tools', 'checklist_profiles', 'checklist_declaration', 'checklist_manuscript', 'checklist_target_details'],
        ],
        'annex_b' => ['contributions' => ['contributions']],
        'qsf_rds_019' => [
            'applicant' => ['applicant_source', 'applicant_name', 'applicant_appointment', 'applicant_position', 'applicant_department', 'applicant_contact', 'applicant_email', 'presenter_author_id'],
            'event_funding' => ['qsf_classification', 'funding_source', 'estimated_expenses', 'expense_currency', 'recognition'],
            'milestones' => ['abstract_submitted', 'abstract_date', 'accepted', 'acceptance_date', 'attachments'],
        ],
    ];
    foreach ($groups[$type] ?? [] as $group => $keys) {
        if (in_array($key, $keys, true) || ($group === 'contributions' && str_starts_with($key, 'contributions.'))) return [$group, $keys];
    }
    return ['general', []];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedWizardStep = filter_var($_POST['wizard_step'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]) ?: 1;
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) $transportError = 'Your form expired. Reload this page before reviewing answers.';
    elseif (($_POST['form_complete'] ?? null) !== '1') $transportError = 'The form was incomplete or exceeded server input limits. Nothing was saved. Keep your current browser values and ask the administrator about the input limit.';
    elseif (array_diff(array_keys($_POST), ['csrf_token', 'form_complete', 'inputs', 'review_action', 'save_request_id', 'draft_revision', 'reviewed_source_fingerprint', 'discard_removed_author_answers', 'wizard_step'])) $transportError = 'Unsupported request fields were rejected.';
    elseif (filter_var($_POST['wizard_step'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]) === false) $transportError = 'Reload the current document review before continuing.';
    elseif (isset($_POST['inputs']) && !is_array($_POST['inputs'])) $transportError = 'Supply valid document-specific inputs.';
    elseif (!in_array($_POST['review_action'] ?? 'review', ['review', 'add_ai_row', 'save'], true)) $transportError = 'Choose a supported review action.';
    elseif (!is_string($_POST['save_request_id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $_POST['save_request_id'])) $transportError = 'Reload the review before continuing.';
    elseif (!is_string($_POST['reviewed_source_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $_POST['reviewed_source_fingerprint'])) $transportError = 'Reload the current source review before continuing.';
    elseif (filter_var($_POST['draft_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false) $transportError = 'Reload the saved revision before continuing.';
    elseif (isset($_POST['discard_removed_author_answers']) && $_POST['discard_removed_author_answers'] !== '1') $transportError = 'Use the explicit author-answer reconciliation option.';
    else {
        $raw = $_POST['inputs'] ?? [];
        $action = (string) ($_POST['review_action'] ?? 'review');
        $wasReviewAction = $action === 'review';
        $addAi = ($_POST['review_action'] ?? '') === 'add_ai_row' && $type === 'annex_a';
        $requestId = is_string($_POST['save_request_id'] ?? null) ? $_POST['save_request_id'] : '';
        $expectedRevision = filter_var($_POST['draft_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
        $discardOrphans = ($_POST['discard_removed_author_answers'] ?? '') === '1';
    }
}
try {
    // First obtain the current saved-author IDs, then keep removed-author answers outside the live mapping.
    $sourceReviewContext = earistDocumentFacultyReview($conn, (int) $recordId, $type, []);
    $sourceReview = $sourceReviewContext['review']['mapping'];
    $draft = earistDocumentFacultyDraftLoad($conn, (int) $recordId, $type);
    $savedSplit = earistDocumentDraftSplitInputs($draft['_envelope']['inputs'] ?? [],
        $draft['_envelope']['author_references'] ?? [], $sourceReview['source']['record']['authors'] ?? []);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $raw = $savedSplit['inputs'];
    $split = earistDocumentDraftSplitInputs($raw, $draft['_envelope']['author_references'] ?? [], $sourceReview['source']['record']['authors'] ?? []);
    $raw = $split['inputs'];
    $orphanedAuthorInputs = array_replace($draft['_envelope']['orphaned_author_inputs'] ?? [], $savedSplit['orphaned'], $split['orphaned']);
    if ($discardOrphans) $orphanedAuthorInputs = [];

    if ($action === 'save' && $transportError === '') {
        try {
            $saved = earistDocumentFacultyDraftSave($conn, (int) $recordId, $type, $raw, $expectedRevision,
                $requestId, $discardOrphans, (string) ($_POST['reviewed_source_fingerprint'] ?? ''));
            if (!$saved['saved']) {
                $saveError = 'These answers did not pass validation. Your last saved revision was preserved.';
                $action = 'review';
            } else {
                $draft = $saved['draft'];
                $raw = $draft['_envelope']['inputs'] ?? $raw;
                $orphanedAuthorInputs = $draft['_envelope']['orphaned_author_inputs'] ?? [];
                $saveNotice = $saved['replayed'] ? 'These answers were already saved. No duplicate draft revision was created.' : 'Document-specific answers saved.';
                $requestId = bin2hex(random_bytes(32));
                $expectedRevision = (int) $draft['draft_revision'];
            }
        } catch (DomainException $exception) {
            if ($exception->getCode() !== 409) throw $exception;
            $saveError = $exception->getMessage();
            $action = 'review';
        }
    }
    // Keep field errors available for this UI; the renderer review helper intentionally throws on invalid mappings.
    $result = earistMappingForUser($conn, $user, (int) $recordId, $type, $raw);
    $currentFingerprint = earistDocumentSourceFingerprint($result);
    $versionContext = earistDocumentDraftVersionContext($type);
    if ($wasReviewAction && $_SERVER['REQUEST_METHOD'] === 'POST' && $transportError === '' && !$result['errors']) {
        $normalizedHash = earistDocumentDraftContentHash($currentFingerprint, $result['inputs'], $orphanedAuthorInputs, $discardOrphans, $versionContext);
        $_SESSION[earistDocumentDraftReviewSessionKey((int) $user['user_id'], (int) $recordId, $type, hash('sha256', $requestId))] = [
            'source_fingerprint' => $currentFingerprint, 'content_sha256' => $normalizedHash,
            'draft_revision' => $draft ? (int) $draft['draft_revision'] : 0,
            'request_id_sha256' => hash('sha256', $requestId), 'expires_at' => time() + 1800,
        ];
        $saveNotice = 'Review complete. Save is available for these exact answers.';
    }
    $wizardStep = $_SERVER['REQUEST_METHOD'] === 'POST' ? $postedWizardStep : 1;
    if ($addAi) $wizardStep = 2;
    elseif ($wasReviewAction && $_SERVER['REQUEST_METHOD'] === 'POST' && $transportError === '') {
        $wizardStep = $result['errors'] ? 2 : 4;
        if ($result['errors']) {
            foreach (array_keys($result['errors']) as $errorKey) {
                [$wizardErrorGroup] = earistDocumentWizardAnswerGroup($type, explode('.', (string) $errorKey, 2)[0]);
                if ($wizardErrorGroup !== 'general') break;
            }
        }
    }
    $normalizedHash = !$result['errors'] ? earistDocumentDraftContentHash($currentFingerprint, $result['inputs'],
        $orphanedAuthorInputs, $discardOrphans, $versionContext) : '';
    $reviewState = $_SESSION[earistDocumentDraftReviewSessionKey((int) $user['user_id'], (int) $recordId, $type, hash('sha256', $requestId))] ?? null;
    $reviewReady = is_array($reviewState) && $normalizedHash !== ''
        && hash_equals((string) ($reviewState['source_fingerprint'] ?? ''), $currentFingerprint)
        && hash_equals((string) ($reviewState['content_sha256'] ?? ''), $normalizedHash)
        && (int) ($reviewState['draft_revision'] ?? -1) === ($draft ? (int) $draft['draft_revision'] : 0)
        && (int) ($reviewState['expires_at'] ?? 0) >= time()
        && hash_equals((string) ($reviewState['request_id_sha256'] ?? ''), hash('sha256', $requestId));
    if ($requestId === '' || !preg_match('/^[a-f0-9]{64}$/D', $requestId)) $requestId = bin2hex(random_bytes(32));
    if (!isset($_POST['draft_revision'])) $expectedRevision = $draft ? (int) $draft['draft_revision'] : 0;
    $template = earistMappingTemplateInfo($type);
    if (!$template['matches']) $result['warnings'][] = 'The reference template is missing or differs from the inspected version. Its structural bindings need review before any future renderer uses it.';
} catch (DomainException $exception) {
    http_response_code(in_array($exception->getCode(), [400, 403, 404], true) ? $exception->getCode() : 400);
    $pageError = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('EARIST mapping review failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $pageError = 'Document mapping is unavailable. Please try again or ask the administrator to check the setup.';
}
if ($transportError !== '') http_response_code(400);
$draftStale = $draft && ($draft['source_fingerprint'] !== $currentFingerprint
    || (int) $draft['source_record_version'] !== (int) ($result['source']['record_version'] ?? 0)
    || (int) $draft['input_schema_version'] !== (int) ($versionContext['input_schema_version'] ?? 0)
    || (int) $draft['mapping_definition_version'] !== (int) ($versionContext['mapping_definition_version'] ?? 0)
    || (int) $draft['binding_version'] !== (int) ($versionContext['binding_version'] ?? 0)
    || !hash_equals((string) ($draft['template_sha256'] ?? ''), (string) ($versionContext['template_sha256'] ?? '')));
$document = $result ? earistMappingDocument($type) : null;
$generationEnabled = false;
if ($document !== null && $draft && !$draftStale && empty($result['errors']) && $template['matches']) {
    $generationEnabled = earistDocumentStorageConfig()['live_generation_enabled'] === true;
}
$canGenerateDocument = $draft && !$draftStale && empty($result['errors']) && $template['matches'] && $generationEnabled;
$formValues = $result['inputs'] ?? [];
$authors = $result['source']['record']['authors'] ?? [];

function earistReviewControl(string $name, string $id, array $rule, $value, array $options = []): void
{
    $escape = 'facultyRecordEscape';
    $choices = $options ?: $rule['options'];
    if (in_array($rule['type'], ['choice', 'author'], true)) {
        echo '<select id="' . $escape($id) . '" name="' . $escape($name) . '"><option value="">Not confirmed / leave blank</option>';
        foreach ($choices as $key => $label) echo '<option value="' . $escape($key) . '"' . ((string) $value === (string) $key ? ' selected' : '') . '>' . $escape($label) . '</option>';
        echo '</select>';
    } elseif ($rule['type'] === 'multi') {
        foreach ($choices as $key => $label) echo '<label class="frr-check"><input type="checkbox" name="' . $escape($name) . '[]" value="' . $escape($key) . '"' . (in_array($key, (array) $value, true) ? ' checked' : '') . '> ' . $escape($label) . '</label>';
    } elseif ($rule['type'] === 'textarea') {
        echo '<textarea id="' . $escape($id) . '" name="' . $escape($name) . '" rows="4">' . $escape($value) . '</textarea>';
    } else {
        // Text preserves invalid entered dates/amounts for server-side correction.
        echo '<input type="text" id="' . $escape($id) . '" name="' . $escape($name) . '" value="' . $escape($value) . '"' . ($rule['type'] === 'decimal' ? ' inputmode="decimal"' : '') . '>';
    }
}

renderFacultyShell($user, 'faculty-document-workspace.php', 'Document Mapping Review', 'Reuse saved research information for EARIST forms.');
facultyRecordAssets();
echo '<link rel="stylesheet" href="' . facultyRecordEscape(SITE_URL . 'css/faculty-document-mapping.css?v=' . filemtime(__DIR__ . '/../../css/faculty-document-mapping.css')) . '">';
echo '<script src="' . facultyRecordEscape(SITE_URL . 'js/faculty-document-mapping.js?v=' . filemtime(__DIR__ . '/../../js/faculty-document-mapping.js')) . '" defer></script>';
?>
<main class="frr-wrap frr-mapping-wizard" data-document-wizard data-active-step="<?= (int) $wizardStep ?>" data-review-ready="<?= $reviewReady ? '1' : '0' ?>"<?= $wizardErrorGroup !== '' ? ' data-error-group="' . facultyRecordEscape($wizardErrorGroup) . '"' : '' ?>>
  <a class="frr-back" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>">← EARIST Documents</a>
  <?php if ($pageError === ''): $wizardLabels = ['Confirm research source', 'Answer document questions', 'Review mapped fields', 'Review and save']; ?>
  <nav class="frr-wizard-progress" aria-label="Document mapping steps"><ol>
    <?php foreach ($wizardLabels as $index => $label): $stepNumber = $index + 1; ?><li<?= $stepNumber === $wizardStep ? ' class="is-current"' : '' ?>><button type="button" data-wizard-goto="<?= $stepNumber ?>"<?= $stepNumber === $wizardStep ? ' aria-current="step"' : '' ?>><span class="frr-wizard-step-number" aria-hidden="true"><?= $stepNumber ?></span><span><?= facultyRecordEscape($label) ?></span></button></li><?php endforeach; ?>
  </ol><p class="frr-wizard-live" role="status" aria-live="polite" data-wizard-status>Step <?= (int) $wizardStep ?> of 4: <?= facultyRecordEscape($wizardLabels[$wizardStep - 1]) ?></p></nav>
  <?php endif; ?>
  <?php if ($pageError !== ''): ?>
    <section class="frr-panel"><h2><?= facultyRecordEscape($pageError) ?></h2><p>Return to your records to choose an available document source.</p></section>
  <?php else: ?>
    <div class="frr-step-one-grid frr-wizard-panel" data-wizard-panel="1">
      <section class="frr-panel frr-record-heading frr-step-one-source" aria-labelledby="wizard-source-heading">
        <p class="frr-eyebrow">Step 1 of 4</p><h2 id="wizard-source-heading" tabindex="-1">Review your document</h2>
        <div class="frr-selected-form">
          <div class="frr-selected-form-title"><span>Selected form</span><strong><?= facultyRecordEscape($document['label']) ?></strong></div>
          <p class="frr-draft-status<?= $draft ? ' frr-draft-status--saved' : '' ?>" role="status"><?php if ($draft): ?><span>Saved answers</span><strong>Revision <?= (int) $draft['draft_revision'] ?></strong><?php else: ?><strong>No saved answers yet</strong><?php endif; ?></p>
        </div>
        <div class="frr-source-summary">
          <p class="frr-source-meta">Research record #<?= (int) $result['source']['record_id'] ?> · version <?= (int) $result['source']['record_version'] ?></p>
          <h3><?= facultyRecordEscape($result['source']['record']['title']) ?></h3>
          <p class="frr-source-links"><span><?= facultyRecordEscape(ucfirst($result['source']['record']['status'])) ?></span><span aria-hidden="true">·</span><a href="<?= facultyRecordEscape(facultyRecordUrl('detail', ['id' => $recordId])) ?>">View research record</a></p>
        </div>
      </section>
      <section class="frr-panel frr-step-one-notes" aria-labelledby="mapping-notes-heading"><h3 id="mapping-notes-heading">Mapping notes</h3><ul class="frr-sdg-summary"><?php foreach ($result['warnings'] as $warning): ?><li><?= facultyRecordEscape($warning) ?></li><?php endforeach; ?></ul>
        <p class="frr-hint">Reference: <?= facultyRecordEscape($template['filename']) ?>. <?= $template['matches'] ? 'Matches the inspected template hash.' : 'Template binding review required.' ?></p>
      </section>
      <div class="frr-wizard-actions frr-step-one-actions"><button class="frr-btn frr-btn--primary" type="button" data-wizard-next>Continue to document questions</button></div>
    </div>
    <?php if ($draftStale): ?><section class="frr-alert frr-wizard-panel" data-wizard-panel="1" role="status"><h3>Research information changed</h3><p>Your saved answers are preserved. Review the current mapped information below before saving this draft again. Contributions remain linked to their saved author IDs.</p></section><?php endif; ?>
    <details class="frr-panel frr-wizard-panel" data-wizard-panel="1"><summary>Faculty profile suggestion and complete saved SDG selection</summary>
      <dl class="frr-details"><?php foreach ($result['source']['profile'] as $key => $value): if ($key === 'user_id') continue; ?><div><dt><?= facultyRecordEscape(ucwords(str_replace('_', ' ', $key))) ?></dt><dd><?= facultyRecordEscape(earistMappingDisplay($value)) ?></dd></div><?php endforeach; ?></dl>
      <p>All saved SDGs: <?= facultyRecordEscape(earistMappingDisplay($result['source']['record']['sdgs'])) ?>. Others: <?= facultyRecordEscape($result['source']['record']['sdg_other'] ?: 'Blank') ?></p>
      <p class="frr-hint">Profile identity is separate from saved authorship, applicant, presenter and reviewer roles. Profile changes do not refresh saved author snapshots.</p>
    </details>
    <form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-mapping.php?' . http_build_query(['id' => $recordId, 'document' => $type]) . '#document-answers') ?>" data-wizard-form>
      <input type="hidden" name="wizard_step" value="<?= (int) $wizardStep ?>" data-wizard-step-input>
    <section class="frr-panel frr-wizard-panel" id="document-answers" data-wizard-panel="2" aria-labelledby="document-answers-heading"><p class="frr-eyebrow">Step 2 of 4 · <?= facultyRecordEscape($document['label']) ?></p><h2 id="document-answers-heading" tabindex="-1">Answer the document questions</h2><p>Leave unknown answers blank. Dates use YYYY-MM-DD. Amounts and currency are entered separately. No official decisions or signatures can be entered.</p>
        <?php if ($saveNotice !== ''): ?><div class="frr-alert" id="document-answer-feedback" role="status" aria-live="polite" tabindex="-1"><p><?= facultyRecordEscape($saveNotice) ?></p></div>
        <?php elseif ($saveError !== '' || $transportError !== '' || !empty($result['errors'])): ?><div class="frr-alert frr-alert--error" id="document-answer-feedback" role="alert" aria-live="assertive" tabindex="-1">
          <?php if ($saveError !== ''): ?><p><?= facultyRecordEscape($saveError) ?></p><p>Your last saved answers were preserved. Values in this form have not replaced them.</p><?php endif; ?>
          <?php if ($transportError !== '' || !empty($result['errors'])): ?><h4>Review the entered values</h4><ul>
            <?php if ($transportError !== ''): ?><li><?= facultyRecordEscape($transportError) ?></li><?php endif; ?>
            <?php foreach ($result['errors'] as $message): ?><li><?= facultyRecordEscape($message) ?></li><?php endforeach; ?>
          </ul><p>Saved answers were not changed. Correct the entries and review them again.</p><?php endif; ?>
        </div><?php endif; ?>
          <?= csrfField() ?>
          <input type="hidden" name="save_request_id" value="<?= facultyRecordEscape($requestId) ?>">
          <input type="hidden" name="draft_revision" value="<?= (int) $expectedRevision ?>">
          <input type="hidden" name="reviewed_source_fingerprint" value="<?= facultyRecordEscape($currentFingerprint) ?>">
          <p class="frr-hint">Review confirms the exact current source and entered answers. Save is enabled only after a successful review. Your research record is never changed here.</p>
          <?php if (!$document['inputs']): ?><div class="frr-wizard-empty"><h3>No additional answers are required</h3><p>This form uses saved research details and keeps official fields blank. Continue to review the mapped fields.</p></div><?php endif; ?>
          <?php $wizardGroupLabels = ['conference' => ['Conference details', 'Confirm the submission target and any conference information you know.'], 'journal' => ['Journal details', 'Add journal information when it applies to your submission target.'], 'declarations' => ['AI use and submission checklist', 'Confirm declarations and supporting materials based on what you know.'], 'contributions' => ['Author contributions', 'Complete contribution details for each saved author.'], 'applicant' => ['Applicant and presenter', 'Confirm who is applying and which saved author will present.'], 'event_funding' => ['Event and funding', 'Select the conference classification and confirm the funding details.'], 'milestones' => ['Milestones and attachments', 'Enter confirmed dates and select the supporting items you will provide.'], 'general' => ['Document-specific answers', 'Enter only information you can confirm.']]; $openWizardGroup = ''; ?>
          <?php foreach ($document['inputs'] as $key => $rule): [$wizardGroup] = earistDocumentWizardAnswerGroup($type, (string) $key); if ($wizardGroup !== $openWizardGroup): if ($openWizardGroup !== ''): ?></section><?php endif; ?><?php [$wizardGroupHeading, $wizardGroupHint] = $wizardGroupLabels[$wizardGroup] ?? $wizardGroupLabels['general']; $openWizardGroup = $wizardGroup; ?><section class="frr-answer-group" data-answer-group="<?= facultyRecordEscape($wizardGroup) ?>"><h3><?= facultyRecordEscape($wizardGroupHeading) ?></h3><p class="frr-hint"><?= facultyRecordEscape($wizardGroupHint) ?></p><?php endif; ?><?php $value = $formValues[$key]; $controlId = 'input-' . $key; ?>
            <?php if ($rule['type'] === 'contributions'): ?>
              <fieldset class="frr-panel" id="<?= facultyRecordEscape($controlId) ?>"><legend>Contributions for each saved author</legend>
                <?php foreach ($authors as $author): $authorId = (string) $author['record_author_id']; $row = $value[$authorId] ?? []; ?>
                  <fieldset class="frr-panel"><legend><?= facultyRecordEscape($author['full_name']) ?></legend>
                    <fieldset class="frr-category-field"><legend>Contribution categories (choose one or more)</legend>
                      <div class="frr-category-options">
                        <?php foreach (earistMappingDefinitions()['contribution_categories'] as $category): ?>
                          <label class="frr-check"><input type="checkbox" name="inputs[contributions][<?= (int) $authorId ?>][categories][]" value="<?= facultyRecordEscape($category) ?>"<?= in_array($category, $row['categories'] ?? [], true) ? ' checked' : '' ?>> <?= facultyRecordEscape($category) ?></label>
                        <?php endforeach; ?>
                      </div>
                    </fieldset>
                    <div class="frr-field"><label for="contribution-<?= (int) $authorId ?>-description">Contribution description</label><textarea id="contribution-<?= (int) $authorId ?>-description" name="inputs[contributions][<?= (int) $authorId ?>][description]" rows="3"><?= facultyRecordEscape($row['description'] ?? '') ?></textarea></div>
                    <div class="frr-field"><label for="contribution-<?= (int) $authorId ?>-percentage">Percentage (0–100)</label><input inputmode="decimal" id="contribution-<?= (int) $authorId ?>-percentage" name="inputs[contributions][<?= (int) $authorId ?>][percentage]" value="<?= facultyRecordEscape($row['percentage'] ?? '') ?>"></div>
                  </fieldset>
                <?php endforeach; ?>
              </fieldset>
            <?php elseif ($rule['type'] === 'ai_rows'): $rows = $value ?: [[], []]; if ($addAi && count($rows) < 100) $rows[] = []; ?>
              <fieldset class="frr-panel" id="<?= facultyRecordEscape($controlId) ?>"><legend>AI-use details</legend>
                <?php foreach ($rows as $index => $row): ?>
                  <fieldset class="frr-panel frr-ai-row" data-ai-row><legend data-ai-row-label>AI detail <?= (int) $index + 1 ?></legend>
                    <?php foreach (['tool' => 'Tool', 'purpose' => 'Purpose', 'prompts' => 'Prompts', 'extent' => 'Extent of use'] as $column => $label): ?>
                      <div class="frr-field"><label for="ai-<?= (int) $index ?>-<?= $column ?>"><?= $label ?></label><textarea rows="2" id="ai-<?= (int) $index ?>-<?= $column ?>" name="inputs[ai_tools][<?= (int) $index ?>][<?= $column ?>]"><?= facultyRecordEscape($row[$column] ?? '') ?></textarea></div>
                    <?php endforeach; ?>
                    <button class="frr-btn frr-btn--danger-text frr-btn--small frr-ai-row-remove" type="button" data-ai-row-remove aria-label="Delete AI detail row <?= (int) $index + 1 ?>">Delete row</button>
                  </fieldset>
                <?php endforeach; ?>
                <button class="frr-btn" type="submit" name="review_action" value="add_ai_row">Add AI-detail row</button>
                <p class="frr-hint">Clear a row to leave it blank. Up to 100 rows; no values are silently shortened.</p>
              </fieldset>
            <?php else: ?>
              <fieldset class="frr-field" id="group-<?= facultyRecordEscape($key) ?>"><legend><?= facultyRecordEscape($rule['label']) ?></legend>
                <?php if ($rule['type'] !== 'multi'): ?><label class="frr-sr-only" for="<?= facultyRecordEscape($controlId) ?>"><?= facultyRecordEscape($rule['label']) ?></label><?php endif; ?>
                <?php $options = $rule['type'] === 'author' ? array_column($authors, 'full_name', 'record_author_id') : []; earistReviewControl('inputs[' . $key . ']', $controlId, $rule, $value, $options); ?>
                <?php if (isset($result['errors'][$key])): ?><p class="frr-error"><?= facultyRecordEscape($result['errors'][$key]) ?></p><?php endif; ?>
              </fieldset>
            <?php endif; ?>
            <?php if ($type === 'qsf_rds_019' && $key === 'applicant_source'): ?><p class="frr-hint">Applicant details stay blank until you confirm who the applicant is. Choose the faculty profile only if that person is the applicant, or choose manual details for another confirmed applicant.</p><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($openWizardGroup !== ''): ?></section><?php endif; ?>
          <?php if ($orphanedAuthorInputs): ?><fieldset class="frr-panel"><legend>Answers for authors no longer on this record</legend><p>These answers remain saved under their original author IDs and will not be assigned to anyone else.</p>
            <?php foreach ($orphanedAuthorInputs as $authorId => $orphan): ?><p><strong><?= facultyRecordEscape($orphan['author']['full_name'] ?? 'Previously saved author') ?></strong> · saved author ID <?= (int) $authorId ?> · contribution answers are preserved.</p><?php endforeach; ?>
            <label class="frr-check"><input type="checkbox" name="discard_removed_author_answers" value="1"<?= $discardOrphans ? ' checked' : '' ?>> I have reviewed these entries and want to discard the removed authors’ contribution answers.</label>
            <p class="frr-hint">The choice takes effect only after review and Save Answers. Leaving this unchecked keeps these answers in the separate draft envelope.</p>
          </fieldset><?php endif; ?>
          <div class="frr-wizard-actions"><button class="frr-btn" type="button" data-wizard-back>Back to source</button><button class="frr-btn frr-btn--primary" type="button" data-wizard-next>Continue to mapped fields</button></div>
          <?php if ($draft): ?><p class="frr-hint">A stale or concurrent save is rejected; your last saved revision remains available.</p><?php endif; ?>
      </section>
    <section class="frr-panel frr-wizard-panel" data-wizard-panel="3" aria-labelledby="wizard-mapping-heading"><p class="frr-eyebrow">Step 3 of 4</p><h2 id="wizard-mapping-heading" tabindex="-1">Review the mapped fields</h2><p>Compare each value with its source. Official decision and signature fields stay blank; non-applicable values remain in your saved research record.</p></section>
    <?php foreach (['common' => 'Saved research mappings', 'document_specific' => 'Document-specific mappings', 'reserved' => 'Official fields kept blank', 'fixed' => 'Protected template content'] as $origin => $heading): if (!array_filter($result['fields'], static fn(array $field): bool => $field['origin'] === $origin)) continue; ?>
      <section class="frr-panel frr-wizard-panel" data-wizard-panel="3"><h3><?= $heading ?></h3><dl class="frr-details">
        <?php foreach ($result['fields'] as $key => $field): if ($field['origin'] !== $origin) continue; ?>
          <div><dt><?= facultyRecordEscape($field['label']) ?> · <?= facultyRecordEscape($field['classification']) ?></dt><dd><?= facultyRecordEscape($field['applicable'] ? earistMappingDisplay($field['value']) : 'Not applicable to the selected submission target; saved source retained.') ?></dd>
            <?php if ($field['errors']): ?><dd class="frr-error"><?= facultyRecordEscape(implode(' ', $field['errors'])) ?></dd><?php endif; ?>
            <dd class="frr-hint"><?= facultyRecordEscape(['common' => 'From the saved research record.', 'document_specific' => 'Saved answer or current review value.', 'reserved' => 'Reserved for authorized officials.', 'fixed' => 'Preserved in the official template.'][$origin]) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl></section>
    <?php endforeach; ?>
    <section class="frr-wizard-panel" data-wizard-panel="3"><div class="frr-wizard-actions"><button class="frr-btn" type="button" data-wizard-back>Back to answers</button><button class="frr-btn frr-btn--primary" type="button" data-wizard-next>Continue to review and save</button></div></section>
    <div class="frr-step-four-grid frr-wizard-panel" data-wizard-panel="4">
      <section class="frr-panel frr-step-four-review" aria-labelledby="wizard-save-heading"><p class="frr-eyebrow">Step 4 of 4</p><h2 id="wizard-save-heading" tabindex="-1">Review and save your answers</h2><p>Review validates this complete answer set before Save Answers becomes available. Saving answers does not create a DOCX or change your research record.</p>
        <div class="frr-wizard-actions frr-review-actions"><button class="frr-btn" type="button" data-wizard-back>Back to mapped fields</button><button class="frr-btn frr-btn--primary" type="submit" name="review_action" value="review" data-review-button<?= $reviewReady ? ' hidden' : '' ?>>Review all answers</button><?php if ($reviewReady): ?><button class="frr-btn frr-btn--primary" type="submit" name="review_action" value="save" data-save-button>Save Answers</button><?php endif; ?></div>
        <p class="frr-review-confirmation" role="status" aria-live="polite" data-review-confirmation<?= $reviewReady ? '' : ' hidden' ?>>Review complete. Save Answers will save this reviewed version. If you change an answer, review it again before saving.</p>
        <input type="hidden" name="form_complete" value="1">
      </section>
      <section class="frr-panel frr-step-four-generated" aria-labelledby="generated-documents-heading"><h3 id="generated-documents-heading">Generated Documents</h3>
        <p>Previous DOCX versions remain available in your document history.</p>
        <div class="frr-step-four-generated-actions">
          <a class="frr-btn" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>">View this record’s document history</a>
          <?php if ($canGenerateDocument): ?><a class="frr-btn frr-btn--primary" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-generate.php?' . http_build_query(['id' => $recordId, 'document' => $type])) ?>">Review and Generate DOCX</a><?php endif; ?>
        </div>
        <?php if (!$draft || $draftStale || !empty($result['errors']) || !$template['matches']): ?><p class="frr-hint">Save valid answers and review current research information before generation is available.</p>
        <?php elseif (!$generationEnabled): ?><p class="frr-hint">DOCX generation is not enabled yet. Your saved answers are preserved.</p><?php endif; ?>
      </section>
    </div>
    </form>
  <?php endif; ?>
</main>
<?php renderFacultyShellClose(); ?>
