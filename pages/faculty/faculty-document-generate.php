<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';
require_once __DIR__ . '/../../includes/earist-document-generation.php';
require_once __DIR__ . '/../../includes/earist-document-ui.php';

$user = facultyRecordRequireUser();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$type = is_string($_GET['document'] ?? null) ? $_GET['document'] : 'annex_a';
$facultyTypes = ['annex_a', 'annex_b', 'annex_c', 'annex_e', 'annex_f', 'qsf_rds_019'];
$typeAllowed = in_array($type, $facultyTypes, true);
$enabled = earistDocumentStorageConfig()['live_generation_enabled'] === true;
$context = null;
$error = '';
$notice = '';
$reviewed = false;
$reviewToken = '';
$acknowledged = false;
$requestRevision = 0;

if (!$typeAllowed) {
    http_response_code(404);
    $error = 'This document is not available in Faculty generation.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['generation_action'] ?? null) ? $_POST['generation_action'] : '';
    $fields = ['csrf_token', 'generation_action', 'draft_revision', 'request_token', 'acknowledge_warnings', 'reviewed_payload_sha256'];
    if (!in_array($action, ['review', 'generate'], true) || array_diff(array_keys($_POST), $fields)) {
        http_response_code(400);
        $error = 'The generation request was invalid. Return to the field review and try again.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Your form expired. Return to the field review and try again.';
    } elseif (filter_var($_POST['draft_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false
        || !is_string($_POST['request_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $_POST['request_token'])) {
        http_response_code(400);
        $error = 'Review the current saved revision before continuing.';
    } elseif (isset($_POST['acknowledge_warnings']) && $_POST['acknowledge_warnings'] !== '1') {
        http_response_code(400);
        $error = 'Use the warning acknowledgment checkbox shown on this page.';
    } elseif ($action === 'generate' && (!is_string($_POST['reviewed_payload_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $_POST['reviewed_payload_sha256']))) {
        http_response_code(400);
        $error = 'Review the current warnings and missing information before generation.';
    } else {
        $requestRevision = (int) $_POST['draft_revision'];
        $reviewToken = $_POST['request_token'];
        try {
            if (!$enabled) throw new DomainException('Live document generation is not enabled yet.', 503);
            if ($action === 'review') {
                earistDocumentGenerationAssertIssued((int) $user['user_id'], (int) $recordId, $type, $reviewToken);
                $context = earistDocumentFacultyGenerationContext($conn, (int) $recordId, $type);
                if ((int) $context['draft']['draft_revision'] !== $requestRevision) {
                    throw new DomainException('Saved answers changed. Review the current revision before generation.', 409);
                }
                $needsAcknowledgment = (bool) ($context['review']['warnings'] || $context['review']['missing']);
                $acknowledged = !$needsAcknowledgment || ($_POST['acknowledge_warnings'] ?? '') === '1';
                if ($needsAcknowledgment && !$acknowledged) {
                    throw new DomainException('Confirm that you reviewed the missing information and warnings before generation.', 400);
                }
                $requestHash = hash('sha256', $reviewToken);
                $reviewKey = earistDocumentGenerationReviewKey((int) $user['user_id'], (int) $recordId, $type, $requestHash);
                $_SESSION[$reviewKey] = ['issued' => true, 'payload_sha256' => $context['review']['payload_sha256'],
                    'source_fingerprint' => $context['source_fingerprint'], 'draft_revision' => $requestRevision,
                    'acknowledged' => $acknowledged, 'expires_at' => time() + 1800];
                $reviewed = true;
            } else {
                $payloadHash = $_POST['reviewed_payload_sha256'];
                $generated = earistDocumentFacultyGenerate($conn, (int) $recordId, $type, $requestRevision, $reviewToken, $payloadHash);
                $generatedId = $generated['generated_document_id'] ?? ($generated['document']['generated_document_id'] ?? 0);
                if ($generatedId < 1) throw new RuntimeException('Generated document history could not be confirmed.');
                header('Location: ' . SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId, 'created' => (int) $generatedId]));
                exit;
            }
        } catch (DomainException $exception) {
            http_response_code(in_array($exception->getCode(), [400, 403, 404, 409, 503], true) ? $exception->getCode() : 400);
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            http_response_code(503);
            error_log('EARIST faculty generation failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
            $error = 'The DOCX could not be generated and saved. Your existing document history was not replaced.';
        }
    }
}

if ($typeAllowed && $enabled && $context === null && $recordId > 0) {
    try { $context = earistDocumentFacultyGenerationContext($conn, (int) $recordId, $type); }
    catch (DomainException $exception) { http_response_code(in_array($exception->getCode(), [400, 403, 404, 409], true) ? $exception->getCode() : 400); $error = $exception->getMessage(); }
    catch (Throwable $exception) { http_response_code(503); $error = 'The current document review is unavailable. Return to Review Document Fields.'; }
}
if (!$reviewed && $context && $reviewToken !== '') {
    $requestHash = hash('sha256', $reviewToken);
    $state = $_SESSION[earistDocumentGenerationReviewKey((int) $user['user_id'], (int) $recordId, $type, $requestHash)] ?? null;
    $reviewed = is_array($state) && (int) ($state['draft_revision'] ?? 0) === (int) $context['draft']['draft_revision']
        && hash_equals((string) ($state['payload_sha256'] ?? ''), $context['review']['payload_sha256'])
        && hash_equals((string) ($state['source_fingerprint'] ?? ''), $context['source_fingerprint'])
        && (int) ($state['expires_at'] ?? 0) >= time();
    $acknowledged = $reviewed && !empty($state['acknowledged']);
}
if ($requestRevision < 1 && $context) $requestRevision = (int) $context['draft']['draft_revision'];
if ($reviewToken === '') $reviewToken = $enabled && $typeAllowed && $recordId > 0
    ? earistDocumentGenerationIssueToken((int) $user['user_id'], (int) $recordId, $type) : bin2hex(random_bytes(32));

renderFacultyShell($user, 'faculty-document-workspace.php', 'Generate EARIST DOCX', 'Review saved answers and current missing information before generation.');
facultyRecordAssets();
earistDocumentStyles();
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>">← EARIST Documents</a>
  <p class="frr-hint"><a href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-mapping.php?' . http_build_query(['id' => $recordId, 'document' => $type])) ?>">Back to review and save answers</a></p>
  <section class="frr-panel ed-generation-panel" aria-labelledby="generation-title">
    <p class="frr-eyebrow">Step 4 of 4 · Review and generate</p>
    <h2 id="generation-title">Generate EARIST DOCX</h2>
    <?php if (!$typeAllowed): ?>
      <div class="frr-alert frr-alert--error" role="alert"><p><?= facultyRecordEscape($error) ?></p></div>
    <?php elseif (!$enabled): ?>
      <div class="frr-alert" role="status"><p>Live document generation is not enabled yet. Your saved answers remain available, and no document was generated.</p></div>
    <?php elseif ($error !== ''): ?><div class="frr-alert frr-alert--error" role="alert"><p><?= facultyRecordEscape($error) ?></p></div><?php endif; ?>
    <?php if ($enabled && $context): ?>
      <div class="ed-generation-record">
        <p><strong><?= facultyRecordEscape($context['mapping']['source']['record']['title']) ?></strong></p>
        <p><span><?= facultyRecordEscape(earistMappingDocument($type)['label']) ?></span><span class="ed-revision-number">Saved answer revision <?= (int) $context['draft']['draft_revision'] ?></span></p>
      </div>
      <details class="ed-generation-details"><summary>Review exact mapped information and saved answers</summary><dl class="ed-answer-list">
        <?php foreach ($context['mapping']['fields'] as $field): ?><div class="ed-answer-item"><dt><strong><?= facultyRecordEscape($field['label']) ?></strong></dt><dd><?= nl2br(facultyRecordEscape(earistMappingDisplay($field['value']))) ?></dd></div><?php endforeach; ?>
      </dl></details>
      <div class="ed-generation-checks">
        <section class="ed-generation-status" aria-labelledby="generation-missing-title">
          <h3 id="generation-missing-title">Missing information</h3>
          <?php if ($context['review']['missing']): ?><ul><?php foreach ($context['review']['missing'] as $field => $status): ?><li><?= facultyRecordEscape(earistDocumentMissingLabel((string) $field, $context['mapping'])) ?> — <?= facultyRecordEscape($status) ?></li><?php endforeach; ?></ul><?php else: ?><p>None identified.</p><?php endif; ?>
        </section>
        <section class="ed-generation-status ed-generation-warnings" aria-labelledby="generation-warnings-title">
          <h3 id="generation-warnings-title">Warnings</h3>
          <?php if ($context['review']['warnings']): ?><ul><?php foreach ($context['review']['warnings'] as $warning): ?><li><?= facultyRecordEscape($warning) ?></li><?php endforeach; ?></ul><?php else: ?><p>None.</p><?php endif; ?>
        </section>
      </div>
      <?php if ((!$reviewed || !$acknowledged) && ($context['review']['missing'] || $context['review']['warnings'])): ?>
        <label class="frr-check"><input type="checkbox" form="generation-review" name="acknowledge_warnings" value="1" required> I reviewed the missing information and warnings shown above.</label>
      <?php endif; ?>
      <?php if ($reviewed && $acknowledged): ?>
        <p class="frr-alert" role="status">Review complete for these saved answers. The generated file will be a preparation copy; official signature areas stay blank.</p>
        <form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-generate.php?' . http_build_query(['id' => $recordId, 'document' => $type])) ?>">
          <?= csrfField() ?><input type="hidden" name="generation_action" value="generate"><input type="hidden" name="draft_revision" value="<?= (int) $context['draft']['draft_revision'] ?>"><input type="hidden" name="request_token" value="<?= facultyRecordEscape($reviewToken) ?>"><input type="hidden" name="reviewed_payload_sha256" value="<?= facultyRecordEscape($context['review']['payload_sha256']) ?>">
          <button class="frr-btn frr-btn--primary" type="submit">Generate DOCX</button>
        </form>
      <?php else: ?>
        <form id="generation-review" method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-generate.php?' . http_build_query(['id' => $recordId, 'document' => $type])) ?>">
          <?= csrfField() ?><input type="hidden" name="generation_action" value="review"><input type="hidden" name="draft_revision" value="<?= (int) $context['draft']['draft_revision'] ?>"><input type="hidden" name="request_token" value="<?= facultyRecordEscape($reviewToken) ?>">
          <button class="frr-btn" type="submit">Review warnings and missing information</button>
        </form>
      <?php endif; ?>
    <?php elseif ($enabled && $recordId < 1): ?><p class="frr-error">Choose a research record from its document review page.</p><?php endif; ?>
  </section>
</div>
<?php renderFacultyShellClose(); ?>
