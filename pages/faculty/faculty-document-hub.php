<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';
require_once __DIR__ . '/../../includes/earist-document-service.php';
require_once __DIR__ . '/../../includes/earist-document-generation.php';
require_once __DIR__ . '/../../includes/earist-document-ui.php';

$user = facultyRecordRequireUser();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 5;
$available = false;
$record = null;
$history = ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize];
$error = '';
$created = filter_var($_GET['created'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

try {
    $available = facultyRecordAvailable($conn);
    if ($available) $record = $recordId > 0 ? facultyRecordLoadOwned($conn, $recordId, (int) $user['user_id']) : null;
} catch (Throwable $exception) {
    error_log('Faculty document hub record lookup failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $available = false;
}
if (!$available) http_response_code(503);
elseif (!$record) facultyRecordNotFound($user);

$documents = [];
foreach (earistMappingDefinitions()['documents'] as $type => $document) {
    if (!empty($document['enabled']) && ($document['role'] ?? '') === 'faculty') $documents[$type] = $document;
}

if ($available && $record) {
    try {
        $history = earistDocumentFacultyHistoryList($conn, $page, $pageSize, $recordId);
        $lastPage = max(1, (int) ceil($history['total'] / $pageSize));
        if ($page > $lastPage) {
            $page = $lastPage;
            $history = earistDocumentFacultyHistoryList($conn, $page, $pageSize, $recordId);
        }
    } catch (Throwable $exception) {
        http_response_code($exception instanceof DomainException ? (int) $exception->getCode() : 503);
        error_log('Faculty document hub history failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
        $error = 'Generated document history is unavailable. Please try again later.';
    }
}
$createdFound = $created > 0 && count(array_filter($history['rows'], static fn(array $row): bool => (int) $row['generated_document_id'] === $created)) > 0;
$recordDeleted = $record && $record['deleted_at'] !== null;
$lastPage = max(1, (int) ceil($history['total'] / $pageSize));
$hubUrl = SITE_URL . 'pages/faculty/faculty-document-hub.php';
$detailUrl = SITE_URL . 'pages/faculty/faculty-research-record-detail.php?id=' . $recordId;
renderFacultyShell($user, 'faculty-document-workspace.php', 'EARIST Documents', 'Prepare and review documents for one research record.');
facultyRecordAssets();
?>
<div class="frr-wrap frr-document-hub">
  <a class="frr-back" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-workspace.php') ?>">← DOCX Generator</a>
  <?php if (!$available): ?>
    <section class="frr-panel"><h2>Research records are unavailable</h2><p>Please try again or ask the administrator to check the research-record database setup.</p></section>
  <?php else: ?>
    <header class="frr-page-intro frr-document-hub-intro" aria-labelledby="frr-hub-title">
      <p class="frr-eyebrow">Document workspace</p>
      <h2 id="frr-hub-title"><?= facultyRecordEscape($record['title']) ?></h2>
      <p>Choose a form to review its mapped research details and enter document-specific answers. Review is required before generation.</p>
      <div class="frr-document-hub-intro__meta">
        <span class="frr-badge frr-badge--<?= $recordDeleted ? 'deleted' : facultyRecordEscape($record['status']) ?>"><?= facultyRecordEscape(facultyRecordStatus($record)) ?></span>
        <a href="<?= facultyRecordEscape($detailUrl) ?>">View research record</a>
      </div>
      <?php if ($recordDeleted): ?><div class="frr-alert">This record is deleted / recoverable. Restore it before saving answers or preparing another DOCX. Previous versions remain available below.</div><?php endif; ?>
    </header>
    <?php if ($createdFound): ?>
      <dialog class="frr-docx-saved" data-docx-saved-dialog aria-labelledby="frr-docx-saved-title" aria-describedby="frr-docx-saved-message">
        <div class="frr-docx-saved__card">
          <span class="frr-docx-saved__mark" aria-hidden="true">✓</span>
          <p class="frr-docx-saved__eyebrow">Document ready</p>
          <h2 id="frr-docx-saved-title">DOCX version saved</h2>
          <p class="frr-docx-saved__message" id="frr-docx-saved-message">It is listed in this record’s history below.</p>
          <button class="frr-btn frr-btn--primary" type="button" data-docx-saved-close>Done</button>
        </div>
      </dialog>
      <noscript><div class="frr-alert frr-alert--success" role="status">DOCX version saved. It is listed in this record’s history below.</div></noscript>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="frr-alert frr-alert--error" role="alert"><?= facultyRecordEscape($error) ?></div><?php endif; ?>

    <section class="frr-panel" aria-labelledby="earist-document-types">
      <div class="frr-section-heading frr-document-types-heading"><span class="frr-section-number" aria-hidden="true">01</span><div><h3 id="earist-document-types">Choose a document</h3><p>Each document has its own answer set. You can review saved versions in this record’s history.</p></div></div>
      <div class="frr-document-grid">
        <?php foreach ($documents as $type => $document): ?>
          <?php $typeHistory = array_values(array_filter($history['rows'], static fn(array $row): bool => (string) $row['document_type'] === (string) $type)); ?>
          <article class="frr-document-card">
            <h4><?= facultyRecordEscape($document['label']) ?></h4>
            <p class="frr-document-card__count"><?= count($typeHistory) ?> recent <?= count($typeHistory) === 1 ? 'version' : 'versions' ?> shown in this record’s history.</p>
            <?php if (!$recordDeleted): ?><a class="frr-btn frr-btn--primary" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-mapping.php?' . http_build_query(['id' => $recordId, 'document' => $type])) ?>">Start field review</a>
            <?php else: ?><span class="frr-btn" aria-disabled="true">Restore record to prepare</span><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="frr-panel" aria-labelledby="earist-document-history">
      <div class="frr-section-heading frr-document-history-heading"><span class="frr-section-number" aria-hidden="true">02</span><div><h3 id="earist-document-history">Generated versions</h3><p><?= (int) $history['total'] ?> saved <?= $history['total'] === 1 ? 'version' : 'versions' ?> for this research record.</p></div><a class="frr-btn" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-generated-documents.php') ?>">All generated documents</a></div>
      <?php if ($error !== ''): ?>
        <p class="frr-hint">The history could not be loaded. Refresh this page to try again.</p>
      <?php elseif (!$history['rows']): ?>
        <p class="frr-document-empty"><?= $recordDeleted ? 'No DOCX versions have been generated for this record yet.' : 'No DOCX versions have been generated for this record yet. Choose a document above to begin a field review.' ?></p>
      <?php else: ?>
        <div class="frr-table-wrap frr-document-history-wrap"><table class="frr-table frr-document-history">
          <caption class="frr-sr-only">Generated document versions for <?= facultyRecordEscape($record['title']) ?></caption>
          <thead><tr><th scope="col">Document</th><th scope="col">Version</th><th scope="col">Generated</th><th scope="col">Review status</th><th scope="col">Downloads</th></tr></thead>
          <tbody>
          <?php foreach ($history['rows'] as $row): $snapshot = $row['_snapshot']; $manifest = $snapshot['file_manifest']; $missing = json_decode((string) $row['missing_fields_json'], true); $warnings = json_decode((string) $row['warnings_json'], true); $isComplete = is_array($missing) && !$missing; $hasWarnings = is_array($warnings) && $warnings; $filesAvailable = ($row['file_retention_status'] ?? 'available') === 'available'; ?>
            <tr>
              <td data-label="Document"><?= facultyRecordEscape(earistMappingDocument($row['document_type'])['label']) ?></td>
              <td data-label="Version"><span class="frr-version-number"><?= (int) $row['document_version'] ?></span></td>
              <td data-label="Generated"><time><?= facultyRecordEscape($row['generated_at']) ?></time></td>
              <td data-label="Review status">
                <div class="frr-history-status">
                  <span class="frr-history-status__badge<?= $isComplete ? ' frr-history-status__badge--complete' : ' frr-history-status__badge--incomplete' ?>"><?= $isComplete ? 'Complete' : 'Missing information' ?></span>
                  <?php if ($hasWarnings): ?><span class="frr-history-status__note">Warnings acknowledged</span><?php endif; ?>
                  <?php if (!$filesAvailable): ?><span class="frr-history-status__note">DOCX file removed or pending cleanup</span><?php endif; ?>
                  <?php earistDocumentRenderHistoryStatus($row); ?>
                </div>
              </td>
              <td data-label="Downloads">
                <div class="frr-document-downloads">
                  <?php if ($filesAvailable): ?>
                    <a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape(SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'primary'])) ?>">Download DOCX</a>
                    <?php if (isset($manifest['files']['sdg_continuation'])): ?><a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape(SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'sdg_continuation'])) ?>">Download QSF SDG companion</a><?php endif; ?>
                  <?php else: ?>
                    <span class="frr-hint">Files unavailable</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($lastPage > 1): ?><nav class="frr-pagination" aria-label="Record document history pages"><span>Page <?= (int) $page ?> of <?= $lastPage ?></span><?php if ($page > 1): ?><a class="frr-btn" href="<?= facultyRecordEscape($hubUrl . '?' . http_build_query(['id' => $recordId, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?><?php if ($page < $lastPage): ?><a class="frr-btn" href="<?= facultyRecordEscape($hubUrl . '?' . http_build_query(['id' => $recordId, 'page' => $page + 1])) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
