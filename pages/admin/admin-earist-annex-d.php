<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/earist-annex-d-service.php';
require_once __DIR__ . '/../../includes/earist-document-ui.php';

requireRole('admin');
try { $user = earistDocumentCurrentActor('annex_d'); }
catch (DomainException $exception) { header('Location: ' . SITE_URL . 'public/403.php'); exit; }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
function eadEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function eadUrl(array $query = []): string { return SITE_URL . 'pages/admin/admin-earist-annex-d.php' . ($query ? '?' . http_build_query($query) : ''); }

$enabled = earistDocumentStorageConfig()['live_generation_enabled'] === true;
$recordId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) ?: 0;
$search = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
$sourcePage = filter_var($_GET['source_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
$historyPage = filter_var($_GET['history_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
$error = '';
$notice = $_SESSION['earist_admin_notice'] ?? '';
unset($_SESSION['earist_admin_notice']);
$record = $draft = $mapping = $generationContext = null;
$inputs = $fieldErrors = [];
$answerReady = $generationReady = false;
$answerToken = $generationToken = '';
$expectedRevision = 0;
$action = '';
$postValid = false;
$generationState = null;
$labels = ['final_score' => 'Final score (0 to 100)', 'classification' => 'Classification',
    'certification_statement' => 'Certification result / statement', 'certification_date' => 'Certification date',
    'certification_place' => 'Certification place', 'record_reference' => 'Evaluation-record reference'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $allowed = ['csrf_token', 'action', 'draft_revision', 'request_token', 'reviewed_source', 'reviewed_payload',
            'answers', 'form_complete', 'ack_warnings', 'mode', 'confirm_policy', 'confirm_records'];
        if (array_diff(array_keys($_POST), $allowed) || ($_POST['form_complete'] ?? null) !== 'annex-d-complete') {
            throw new DomainException('The form is incomplete. Reload the page and enter the answers again.', 400);
        }
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) throw new DomainException('Your form expired. Reload the page.', 403);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!in_array($action, ['review_answers', 'save_answers', 'review_generation', 'generate'], true) || !$recordId) {
            throw new DomainException('Choose an available faculty research record.', 400);
        }
        $expectedRevision = filter_var($_POST['draft_revision'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
        $token = $_POST['request_token'] ?? null;
        if ($expectedRevision === false || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new DomainException('Reload this preparation before continuing.', 400);
        }
        $postValid = true;
        if ($action === 'generate') {
            $payload = $_POST['reviewed_payload'] ?? null;
            if (!is_string($payload)) throw new DomainException('Review the saved Annex D preparation again.', 400);
            $result = earistAnnexDGenerate($conn, (int) $recordId, $expectedRevision, $token, $payload);
            $_SESSION['earist_admin_notice'] = !empty($result['replayed'])
                ? 'This request was already completed. Its saved version remains in your Annex D history.'
                : 'Annex D preparation version saved. Generation does not establish official issuance.';
            header('Location: ' . eadUrl() . '#history', true, 303); exit;
        }
    } catch (Throwable $exception) {
        http_response_code($exception instanceof DomainException && in_array($exception->getCode(), [400,403,404,409,503], true) ? $exception->getCode() : 503);
        $error = $exception instanceof DomainException ? $exception->getMessage() : 'Annex D is temporarily unavailable. Saved answers and history were preserved.';
        if (!$exception instanceof DomainException) error_log('EARIST Admin POST failed: ' . get_class($exception));
        $postValid = false;
    }
}

if ($recordId) {
    try {
        $record = earistAnnexDSourceForUser($conn, (int) $recordId);
        $draft = earistAnnexDDraftLoad($conn, (int) $recordId);
        $inputs = $draft['_envelope']['inputs'] ?? [];
        if ($postValid && in_array($action, ['review_answers', 'save_answers'], true)) {
            if (!is_array($_POST['answers'] ?? null)) throw new DomainException('Enter the Annex D answers using this form.', 400);
            $inputs = $_POST['answers'];
            if (isset($inputs['scoring_policy_confirmed']) && $inputs['scoring_policy_confirmed'] !== '1') {
                throw new DomainException('Use the institutional policy checkbox shown on this form.', 400);
            }
            $inputs['scoring_policy_confirmed'] = ($inputs['scoring_policy_confirmed'] ?? '') === '1';
            $answerToken = $_POST['request_token'];
        }
        $mapping = earistAnnexDMappingBuild($record, $inputs);
        $fieldErrors = $mapping['errors'];
        $versions = earistDocumentDraftVersionContext('annex_d');
        $fingerprint = earistDocumentSourceFingerprint($mapping);
        $stale = $draft && ((int) $draft['source_record_version'] !== (int) $record['version']
            || !hash_equals((string) $draft['source_fingerprint'], $fingerprint)
            || (int) $draft['input_schema_version'] !== $versions['input_schema_version']
            || (int) $draft['mapping_definition_version'] !== $versions['mapping_definition_version']
            || (int) $draft['binding_version'] !== $versions['binding_version']
            || !hash_equals((string) $draft['template_sha256'], $versions['template_sha256']));
        if ($postValid && in_array($action, ['review_answers', 'save_answers'], true)) {
            if ($fieldErrors) {
                http_response_code(422);
                $error = 'Correct the entered values below. Your saved answers were not changed.';
            } elseif ($action === 'review_answers') {
                if ((int) ($draft['draft_revision'] ?? 0) !== $expectedRevision) throw new DomainException('These answers changed in another request. Reload the saved revision; your entries have not replaced it.', 409);
                $details = earistDocumentReviewDetails($mapping, (int) $user['user_id']);
                $content = earistDocumentDraftContentHash($fingerprint, $mapping['inputs'], [], false, $versions);
                $_SESSION[earistDocumentDraftReviewSessionKey((int) $user['user_id'], (int) $recordId, 'annex_d', hash('sha256', $answerToken))] =
                    ['source_fingerprint' => $fingerprint, 'content_sha256' => $content, 'draft_revision' => $expectedRevision,
                    'request_id_sha256' => hash('sha256', $answerToken), 'expires_at' => time() + 1800];
                $answerReady = true;
                $notice = 'Review complete. Save is available for these exact Annex D answers.';
            } else {
                $reviewedSource = $_POST['reviewed_source'] ?? null;
                if (!is_string($reviewedSource)) throw new DomainException('Review the answers before saving.', 400);
                $saved = earistAnnexDDraftSave($conn, (int) $recordId, $inputs, $expectedRevision, $answerToken, $reviewedSource);
                if (empty($saved['saved'])) throw new DomainException('Correct the Annex D answers before saving.', 422);
                $_SESSION['earist_admin_notice'] = !empty($saved['replayed']) ? 'These answers were already saved. The saved revision was preserved.' : 'Annex D answers saved.';
                header('Location: ' . eadUrl(['id' => $recordId]), true, 303); exit;
            }
        }
        if ($enabled && $draft && !$stale && !$fieldErrors) {
            $generationContext = earistAnnexDGenerationContext($conn, (int) $recordId);
            if ($postValid && $action === 'review_generation') {
                $generationToken = $_POST['request_token'];
                earistDocumentGenerationAssertIssued((int) $user['user_id'], (int) $recordId, 'annex_d', $generationToken);
                if ($expectedRevision !== (int) $draft['draft_revision'] || ($_POST['ack_warnings'] ?? null) !== '1') {
                    throw new DomainException('Review the current saved revision and acknowledge its warnings.', 409);
                }
                $mode = $_POST['mode'] ?? '';
                $generationState = ['issued' => true, 'acknowledged' => true, 'requested_mode' => $mode,
                    'payload_sha256' => $generationContext['review']['payload_sha256'], 'source_fingerprint' => $fingerprint,
                    'draft_revision' => $expectedRevision, 'expires_at' => time() + 1800,
                    'policy_confirmed' => ($_POST['confirm_policy'] ?? null) === '1',
                    'verified_against_records' => ($_POST['confirm_records'] ?? null) === '1',
                    'verified_by' => (int) $user['user_id'], 'verified_at' => date('Y-m-d H:i:s')];
                if (!is_string($mode) || !in_array($mode, ['verified', 'unissued'], true)) throw new DomainException('Choose an Annex D preparation mode.', 400);
                earistAnnexDGenerationEvidence($generationContext['review'], $user, $generationState);
                $_SESSION[earistDocumentGenerationReviewKey((int) $user['user_id'], (int) $recordId, 'annex_d', hash('sha256', $generationToken))] = $generationState;
                $generationReady = true;
            }
        } elseif ($postValid && $action === 'review_generation') {
            throw new DomainException('Generation is disabled or the saved draft requires a new review and save.', 409);
        }
    } catch (Throwable $exception) {
        http_response_code($exception instanceof DomainException && in_array($exception->getCode(), [400,403,404,409,422,503], true) ? $exception->getCode() : 503);
        $error = $exception instanceof DomainException ? $exception->getMessage() : 'This Annex D preparation is temporarily unavailable. Your saved answers and history were preserved.';
        if (!$exception instanceof DomainException) error_log('EARIST Admin preparation failed: ' . get_class($exception));
    }
}
if (!$postValid || !in_array($action, ['review_answers', 'save_answers'], true)) $expectedRevision = (int) ($draft['draft_revision'] ?? 0);
if ($answerToken === '') $answerToken = bin2hex(random_bytes(32));
if ($enabled && $generationContext && $generationToken === '') $generationToken = earistDocumentGenerationIssueToken((int) $user['user_id'], (int) $recordId, 'annex_d');
$sources = ['rows' => [], 'has_next' => false];
$history = ['rows' => [], 'total' => 0, 'page_size' => 5];
try { $sources = earistAnnexDSources($conn, $search, $sourcePage); $history = earistAnnexDHistoryList($conn, $historyPage); }
catch (Throwable $exception) { $error = $error ?: 'The source list or Annex D history is temporarily unavailable.'; error_log('EARIST Admin list failed: ' . get_class($exception)); }
$sourcePageQuery = ['q' => $search, 'source_page' => $sourcePage];
$historyPageQuery = ['q' => $search, 'source_page' => $sourcePage];
if ($recordId) {
    $sourcePageQuery['id'] = $recordId;
    $historyPageQuery['id'] = $recordId;
}
if ($historyPage > 1) $sourcePageQuery['history_page'] = $historyPage;

// The guide reflects completed server-side actions, not which panels are visible.
$generationComplete = in_array($notice, [
    'This request was already completed. Its saved version remains in your Annex D history.',
    'Annex D preparation version saved. Generation does not establish official issuance.',
], true);
$answerPostFailed = in_array($action, ['review_answers', 'save_answers'], true) && ($error !== '' || !empty($fieldErrors));
$generationReviewFailed = $action === 'review_generation' && $error !== '';
$generationPostFailed = $action === 'generate' && $error !== '';
$savedAnswersReady = $draft && empty($stale) && empty($fieldErrors) && !$answerPostFailed && !$answerReady;
$activeStep = 1;
if ($generationComplete) $activeStep = 5;
elseif ($generationReady) $activeStep = 5;
elseif ($answerReady) $activeStep = 3;
elseif ($answerPostFailed) $activeStep = 2;
elseif ($generationReviewFailed || $generationPostFailed) $activeStep = 4;
elseif ($savedAnswersReady) $activeStep = 4;
elseif ($record) $activeStep = 2;
$attentionStep = $answerPostFailed ? 2 : ($generationReviewFailed ? 4 : ($generationPostFailed ? 5 : 0));
$completedSteps = [];
if ($record || $generationComplete) $completedSteps[] = 1;
if ($answerReady || $savedAnswersReady || $generationReady || $generationComplete) $completedSteps[] = 2;
if ($savedAnswersReady || $generationReady || $generationComplete) $completedSteps[] = 3;
if ($generationReady || $generationComplete) $completedSteps[] = 4;
if ($generationComplete) $completedSteps[] = 5;
$guideSteps = [
    1 => ['title' => 'Select a research record', 'detail' => 'Choose the faculty record for this Annex D preparation.'],
    2 => ['title' => 'Enter and review answers', 'detail' => 'Complete the details and select Review Answers.'],
    3 => ['title' => 'Save reviewed answers', 'detail' => 'Save after the answer review passes.'],
    4 => ['title' => 'Review saved preparation', 'detail' => 'Check the saved values, missing information, warnings, and preparation mode.'],
    5 => ['title' => 'Generate and download', 'detail' => 'Generate after review, then download from My Annex D History.'],
];

renderAdminShell($user, 'admin-earist-annex-d', 'Annex D Preparation', 'Prepare certification copies for physical signing and view your saved versions.');
?>
<link rel="stylesheet" href="<?= eadEscape(SITE_URL . 'css/earist-documents.css?v=' . filemtime(__DIR__ . '/../../css/earist-documents.css')) ?>">
<div class="ed-wrap">
  <?php if ($error !== ''): ?><div class="ed-alert ed-error" role="alert"><?= eadEscape($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="ed-alert" role="status"><?= eadEscape($notice) ?></div><?php endif; ?>
  <section class="ed-panel ead-guide" aria-labelledby="ead-guide-title">
    <h2 id="ead-guide-title">Annex D generation steps</h2>
    <ol class="ead-steps" aria-label="Annex D preparation progress">
      <?php foreach ($guideSteps as $stepNumber => $step):
          $isComplete = in_array($stepNumber, $completedSteps, true);
          $isCurrent = $stepNumber === $activeStep && !$generationComplete;
          $isUnavailable = $stepNumber === 5 && !$enabled && !$generationComplete;
          $isAttention = $stepNumber === $attentionStep;
          $status = $isComplete ? 'Complete' : ($isAttention ? 'Needs attention' : ($isUnavailable ? 'Unavailable' : ($isCurrent ? 'Current step' : 'Upcoming')));
          $classes = ['ead-step'];
          if ($isComplete) $classes[] = 'is-complete';
          if ($isCurrent) $classes[] = 'is-current';
          if ($isUnavailable) $classes[] = 'is-unavailable';
          if ($isAttention) $classes[] = 'is-attention';
      ?>
        <li class="<?= eadEscape(implode(' ', $classes)) ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
          <span class="ead-step-number" aria-hidden="true"><?= (int) $stepNumber ?></span>
          <span class="ead-step-content">
            <strong class="ead-step-title"><?= eadEscape($step['title']) ?></strong>
            <span class="ead-step-detail"><?= eadEscape($step['detail']) ?></span>
            <span class="ead-step-status"><?= eadEscape($status) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($generationComplete): ?><p class="ead-guide-message" role="status">Generation completed. The saved version is listed in My Annex D History below.</p>
    <?php elseif (!$enabled): ?><p class="ead-guide-message ead-guide-unavailable" role="status">DOCX generation is unavailable while the live generation setting is disabled. You can still select a record, review, and save answers.</p><?php endif; ?>
  </section>
  <section class="ed-panel">
    <h2>Select Faculty Research Record</h2>
    <form method="get" action="<?= eadEscape(eadUrl()) ?>" class="ed-search"><label>Research title or faculty name<input name="q" maxlength="200" value="<?= eadEscape($search) ?>"></label><button type="submit">Search records</button></form>
    <?php if (!$sources['rows']): ?><p>No eligible faculty research records found.</p><?php else: ?>
      <div class="ed-table"><table><thead><tr><th>Research title</th><th>Faculty</th><th>State</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($sources['rows'] as $source): ?><tr><td><?= eadEscape($source['title']) ?></td><td><?= eadEscape($source['first_name'] . ' ' . $source['last_name']) ?></td><td><?= eadEscape(ucfirst($source['status'])) ?></td><td><a href="<?= eadEscape(eadUrl(['id' => (int) $source['research_record_id']])) ?>">Prepare Annex D</a></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <nav class="ed-pagination" aria-label="Source record pagination">
      <?php if ($sourcePage > 1): ?><a href="<?= eadEscape(eadUrl(array_merge($sourcePageQuery, ['source_page' => $sourcePage - 1]))) ?>">Previous</a><?php else: ?><span class="ed-page-disabled" aria-disabled="true">Previous</span><?php endif; ?>
      <span class="ed-page-current" aria-current="page">Page <?= $sourcePage ?></span>
      <?php if ($sources['has_next']): ?><a href="<?= eadEscape(eadUrl(array_merge($sourcePageQuery, ['source_page' => $sourcePage + 1]))) ?>">Next</a><?php else: ?><span class="ed-page-disabled" aria-disabled="true">Next</span><?php endif; ?>
    </nav>
  </section>
  <?php if ($record && $mapping): ?>
  <section class="ed-panel ed-annex-preparation">
    <h2 class="ed-record-title"><?= eadEscape($record['title']) ?></h2>
    <p>Source research version <?= (int) $record['version'] ?> · <?= eadEscape(ucfirst($record['status'])) ?></p>
    <p class="ed-revision"><?= $draft ? 'Saved answers · Revision ' . (int) $draft['draft_revision'] : 'No saved answers yet' ?></p>
    <?php if (!empty($stale)): ?><div class="ed-alert">Research information changed. Your answers are preserved. Review the current title and authors before saving again.</div><?php endif; ?>
    <h3 class="ed-subsection-heading">Authors on the document (in order)</h3><ol class="ed-author-list"><?php foreach ($record['authors'] as $author): ?><li><?= eadEscape($author['full_name']) ?></li><?php endforeach; ?></ol>
    <aside class="ed-important-note" aria-label="Important document information"><strong>Important</strong><p>This is a preparation copy, not proof that the document was signed or officially issued. Leave the physical signature spaces blank. The preparation is saved under your Admin account; the source research record remains owned by its Faculty account.</p></aside>
    <form method="post" action="<?= eadEscape(eadUrl(['id' => $recordId])) ?>">
      <?= csrfField() ?><input type="hidden" name="draft_revision" value="<?= $expectedRevision ?>"><input type="hidden" name="request_token" value="<?= eadEscape($answerToken) ?>"><input type="hidden" name="reviewed_source" value="<?= eadEscape($fingerprint ?? '') ?>">
      <h3 class="ed-subsection-heading"><?= $answerReady ? 'Step 3: Save reviewed answers' : ($savedAnswersReady ? 'Saved answers: changes are optional' : 'Step 2: Enter and review answers') ?></h3>
      <p class="ed-form-instructions"><?= $answerReady ? 'Answer review passed. Select Save reviewed answers to save this version.' : ($savedAnswersReady ? 'These answers are already saved. To change them, edit the fields, select Review Answers, and save the reviewed version. To continue with the saved version, review the preparation below.' : 'Enter the details below, then select Review Answers. Correct any messages shown. Save reviewed answers becomes available after the review passes.') ?></p>
      <div class="ed-score-guide" aria-labelledby="ed-score-guide-title">
        <p id="ed-score-guide-title"><strong>Classification bands</strong> <span>Confirm that these match the applicable institutional policy.</span></p>
        <ul>
          <li><strong>90 to 100:</strong> Highly Recommended</li>
          <li><strong>80 to below 90:</strong> Recommended</li>
          <li><strong>70 to below 80:</strong> Moderately Recommended</li>
          <li><strong>60 to below 70:</strong> Conditionally Recommended</li>
          <li><strong>Below 60:</strong> Not Recommended</li>
        </ul>
      </div>
      <p class="ed-form-guidance">Use the exact score from the evaluation record; do not round it. If you enter both a score and classification, they must match. Leave unknown values blank.</p>
      <div class="ed-grid">
      <?php foreach ($labels as $key => $label): ?>
        <label><?= eadEscape($label) ?>
        <?php if ($key === 'classification'): ?><select name="answers[classification]"><option value="">Not confirmed / leave blank</option><?php foreach (earistAnnexDClassifications() as $value => $text): ?><option value="<?= eadEscape($value) ?>" <?= ($inputs[$key] ?? '') === $value ? 'selected' : '' ?>><?= eadEscape($text) ?></option><?php endforeach; ?></select>
        <?php elseif ($key === 'certification_statement'): ?><textarea name="answers[<?= $key ?>]" maxlength="4000" rows="4"><?= eadEscape(is_string($inputs[$key] ?? null) ? $inputs[$key] : '') ?></textarea>
        <?php else: ?><input type="<?= $key === 'certification_date' ? 'date' : 'text' ?>" name="answers[<?= $key ?>]" maxlength="<?= $key === 'final_score' ? 2000 : ($key === 'certification_date' ? 10 : 500) ?>" value="<?= eadEscape(is_string($inputs[$key] ?? null) ? $inputs[$key] : '') ?>"><?php endif; ?>
        <?php if (isset($fieldErrors[$key])): ?><span class="ed-field-error"><?= eadEscape($fieldErrors[$key]) ?></span><?php endif; ?></label>
      <?php endforeach; ?>
      </div>
      <label class="ed-check"><input type="checkbox" name="answers[scoring_policy_confirmed]" value="1" aria-describedby="ead-policy-help" <?= ($inputs['scoring_policy_confirmed'] ?? false) === true ? 'checked' : '' ?>> I confirm these decimal score bands match the applicable institutional policy.</label>
      <p class="ed-check-help" id="ead-policy-help">Required for verified preparation. Leave unchecked if you cannot confirm the policy.</p>
      <?php foreach ($fieldErrors as $key => $message): if (isset($labels[$key])) continue; ?><p class="ed-field-error"><?= eadEscape($message) ?></p><?php endforeach; ?>
      <div class="ed-actions"><button name="action" value="review_answers" type="submit">Review Answers</button><button name="action" value="save_answers" type="submit" <?= $answerReady ? '' : 'disabled' ?>>Save reviewed answers</button></div>
      <input type="hidden" name="form_complete" value="annex-d-complete">
    </form>
  </section>
  <section class="ed-panel ed-generation-panel">
    <h2>Generate Annex D</h2>
    <?php $generationMapping = $generationContext['mapping'] ?? $mapping; ?>
    <?php if ($generationContext): ?>
      <h3>Exact saved answers for generation <span class="ed-revision-number">Revision <?= (int) $draft['draft_revision'] ?></span></h3>
      <p class="ed-generation-note">The editable form above may contain unsaved entries. Generation uses only the saved answers shown here.</p>
      <dl class="ed-answer-list"><?php foreach ($labels as $key => $label): ?><div class="ed-answer-item"><dt><?= eadEscape($label) ?></dt><dd><?= nl2br(eadEscape($generationMapping['inputs'][$key] !== '' ? $generationMapping['inputs'][$key] : 'Blank')) ?></dd></div><?php endforeach; ?></dl>
      <p class="ed-policy-confirmation">Saved scoring policy confirmation: <strong><?= $generationMapping['inputs']['scoring_policy_confirmed'] ? 'Confirmed' : 'Not confirmed' ?></strong></p>
    <?php endif; ?>
    <div class="ed-generation-checks">
      <section class="ed-generation-status"><h3>Missing information</h3><?php if ($generationMapping['missing']): ?><ul><?php foreach ($generationMapping['missing'] as $key => $status): ?><li><?= eadEscape($labels[substr($key, strlen('annex_d.'))] ?? 'Institutional scoring policy confirmation') ?></li><?php endforeach; ?></ul><?php else: ?><p>All required answers are present.</p><?php endif; ?></section>
      <section class="ed-generation-status ed-generation-warnings"><h3>Warnings</h3><?php if ($generationMapping['warnings']): ?><ul><?php foreach ($generationMapping['warnings'] as $warning): ?><li><?= eadEscape($warning) ?></li><?php endforeach; ?></ul><?php else: ?><p>No additional warnings.</p><?php endif; ?></section>
    </div>
    <?php if (!$enabled): ?><div class="ed-alert">Live DOCX generation is disabled. You can review and save Annex D answers; no document will be generated.</div>
    <?php elseif (!$generationContext): ?><p>Review and save valid current answers before generation.</p>
    <?php else: ?>
      <form method="post" action="<?= eadEscape(eadUrl(['id' => $recordId])) ?>">
        <?= csrfField() ?><input type="hidden" name="draft_revision" value="<?= (int) $draft['draft_revision'] ?>"><input type="hidden" name="request_token" value="<?= eadEscape($generationToken) ?>"><input type="hidden" name="reviewed_payload" value="<?= eadEscape($generationContext['review']['payload_sha256']) ?>">
        <?php if ($generationReady): ?><p>Reviewed mode: <strong><?= ($generationState['requested_mode'] ?? '') === 'verified' ? 'Prepared for physical signing' : 'UNISSUED — incomplete or unverified' ?></strong>. This action uses only your saved answers.</p><button name="action" value="generate" type="submit">Generate Annex D DOCX</button>
        <?php else: ?>
          <label>Preparation mode<select name="mode"><option value="unissued">Unissued — incomplete or unverified preparation</option><option value="verified">Complete and verified — for physical signing</option></select></label>
          <label class="ed-check"><input type="checkbox" name="ack_warnings" value="1" required> I reviewed the saved answers, missing information and warnings above.</label>
          <label class="ed-check"><input type="checkbox" name="confirm_policy" value="1"> For a verified copy, I freshly confirm the institutional scoring policy for this exact preparation.</label>
          <label class="ed-check"><input type="checkbox" name="confirm_records" value="1"> For a verified copy, I checked these exact saved answers against institutional evaluation records.</label>
          <button name="action" value="review_generation" type="submit">Review Saved Preparation</button>
        <?php endif; ?><input type="hidden" name="form_complete" value="annex-d-complete">
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="ed-panel" id="history">
    <h2>My Annex D History</h2><p>Only versions prepared by your current Admin account appear here. Downloads use stored files. Versions may have gaps because numbering is shared for the research record.</p>
    <?php if (!$history['rows']): ?><p>No Annex D versions yet.</p><?php else: ?>
      <div class="ed-table"><table><thead><tr><th>Research title</th><th>Version</th><th>Generated</th><th>Generated by</th><th>Source version</th><th>Status</th><th>Download</th></tr></thead><tbody>
      <?php foreach ($history['rows'] as $row): $snapshot = $row['_snapshot']; ?>
        <tr><td><?= eadEscape($snapshot['source']['record']['title']) ?><?php if ($row['joined_source_deleted'] !== null): ?><p>Source deleted · history retained</p><?php elseif ($row['joined_source_status'] === 'archived'): ?><p>Source archived</p><?php endif; ?></td><td><?= (int) $row['document_version'] ?></td><td><?= eadEscape($row['generated_at']) ?></td><td><?= eadEscape($snapshot['generated_by']['name'] ?? 'Preparing Admin') ?></td><td><?= (int) $row['source_record_version'] ?></td><td><?= $row['preparation_mode'] === 'prepared_for_printing' ? 'Prepared for physical signing' : 'Unissued preparation' ?><?php earistDocumentRenderHistoryStatus($row); ?></td><td><a href="<?= eadEscape(SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'primary', 'scope' => 'annex_d'])) ?>">Download Annex D DOCX</a></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <?php $last = max(1, (int) ceil($history['total'] / $history['page_size'])); ?>
      <?php $historyPageQuery['history_page'] = $historyPage; ?>
      <nav class="ed-pagination" aria-label="Annex D history pagination">
        <?php if ($historyPage > 1): ?><a href="<?= eadEscape(eadUrl(array_merge($historyPageQuery, ['history_page' => $historyPage - 1])) . '#history') ?>">Previous</a><?php else: ?><span class="ed-page-disabled" aria-disabled="true">Previous</span><?php endif; ?>
        <span class="ed-page-current" aria-current="page">Page <?= $historyPage ?> of <?= $last ?></span>
        <?php if ($historyPage < $last): ?><a href="<?= eadEscape(eadUrl(array_merge($historyPageQuery, ['history_page' => $historyPage + 1])) . '#history') ?>">Next</a><?php else: ?><span class="ed-page-disabled" aria-disabled="true">Next</span><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</div>
<?php renderAdminShellClose(); ?>
