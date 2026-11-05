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
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 5;
$history = ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize];
$error = '';
$createdFound = false;
$created = filter_var($_GET['created'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$readGetString = static fn(string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
$documentOptions = [];
foreach (earistMappingDefinitions()['documents'] as $documentType => $definition) {
    if ($documentType !== 'annex_d' && ($definition['role'] ?? '') === 'faculty') {
        $documentOptions[$documentType] = (string) ($definition['label'] ?? $documentType);
    }
}
$filters = [
    'search' => $readGetString('search'),
    'document_type' => $readGetString('document_type'),
    'status' => $readGetString('status'),
    'origin' => $readGetString('origin'),
];
if (!isset($documentOptions[$filters['document_type']])) $filters['document_type'] = '';
if (!in_array($filters['status'], ['', 'complete', 'missing', 'warnings'], true)) $filters['status'] = '';
if (!in_array($filters['origin'], ['', 'generated', 'edited'], true)) $filters['origin'] = '';
$filtersActive = $filters['search'] !== '' || $filters['document_type'] !== '' || $filters['status'] !== '' || $filters['origin'] !== '';
try {
    $history = earistDocumentFacultyHistoryList($conn, $page, $pageSize, null, $filters);
    $createdFound = $created > 0 && earistDocumentFacultyHistoryContains($conn, $created);
}
catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? 403 : 503);
    error_log('EARIST faculty history failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $error = 'Generated document history is unavailable. Please try again later.';
}
renderFacultyShell($user, 'faculty-generated-documents', 'Generated Documents', 'Download your saved EARIST DOCX versions.');
facultyRecordAssets();
earistDocumentPreviewAssets();
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-workspace.php') ?>">← DOCX Generator</a>
  <section class="frr-panel">
    <h2>Generated Documents</h2>
    <p>Each entry is a saved version. Downloading opens the stored file and does not generate another version. PDF conversion is unavailable.</p>
    <?php if ($createdFound): ?><div class="frr-alert" role="status">DOCX version saved in Generated Documents.</div><?php endif; ?>
    <?php if ($error === '' && ($history['total'] > 0 || $filtersActive)): ?>
      <form class="frr-filter-form" method="get" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-generated-documents.php') ?>">
        <div class="frr-field frr-filter-search">
          <label for="generated-documents-search">Research title</label>
          <input id="generated-documents-search" type="search" name="search" value="<?= facultyRecordEscape($filters['search']) ?>" placeholder="Search research titles">
        </div>
        <div class="frr-field">
          <label for="generated-documents-type">Document type</label>
          <select id="generated-documents-type" name="document_type">
            <option value="">All document types</option>
            <?php foreach ($documentOptions as $documentType => $documentLabel): ?>
              <option value="<?= facultyRecordEscape($documentType) ?>" <?= $filters['document_type'] === $documentType ? 'selected' : '' ?>><?= facultyRecordEscape($documentLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="frr-field">
          <label for="generated-documents-status">Status</label>
          <select id="generated-documents-status" name="status">
            <option value="">All statuses</option>
            <option value="complete" <?= $filters['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
            <option value="missing" <?= $filters['status'] === 'missing' ? 'selected' : '' ?>>Missing information</option>
            <option value="warnings" <?= $filters['status'] === 'warnings' ? 'selected' : '' ?>>Warnings</option>
          </select>
        </div>
        <div class="frr-field">
          <label for="generated-documents-origin">Version origin</label>
          <select id="generated-documents-origin" name="origin">
            <option value="">All versions</option>
            <option value="generated" <?= $filters['origin'] === 'generated' ? 'selected' : '' ?>>Generated</option>
            <option value="edited" <?= $filters['origin'] === 'edited' ? 'selected' : '' ?>>Edited</option>
          </select>
        </div>
        <button class="frr-btn frr-btn--primary" type="submit">Apply filters</button>
        <?php if ($filtersActive): ?>
          <?php $clearUrl = SITE_URL . 'pages/faculty/faculty-generated-documents.php' . ($created > 0 ? '?' . http_build_query(['created' => $created]) : ''); ?>
          <a class="frr-btn frr-btn--secondary" href="<?= facultyRecordEscape($clearUrl) ?>">Clear filters</a>
        <?php endif; ?>
        <?php if ($created > 0): ?><input type="hidden" name="created" value="<?= (int) $created ?>"><?php endif; ?>
      </form>
      <p class="frr-result-count" role="status"><?= number_format((int) $history['total']) ?> matching saved version<?= (int) $history['total'] === 1 ? '' : 's' ?>.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="frr-alert frr-alert--error" role="alert"><?= facultyRecordEscape($error) ?></div>
    <?php elseif (!$history['rows']): ?>
      <?php if ($filtersActive): ?>
        <?php $clearUrl ??= SITE_URL . 'pages/faculty/faculty-generated-documents.php'; ?>
        <div class="frr-empty"><h3>No versions match these filters</h3><p>Try another title, document type, status, or version origin.</p><a class="frr-btn frr-btn--secondary" href="<?= facultyRecordEscape($clearUrl) ?>">Clear filters</a></div>
      <?php elseif ((int) $history['total'] === 0): ?><p>No generated documents yet.</p>
      <?php else: ?><p>No history entries are available on this page.</p><?php endif; ?>
    <?php else: ?>
      <div class="frr-table-wrap"><table class="frr-table"><thead><tr><th>Research title</th><th>Document</th><th>Version</th><th>Date generated</th><th>Generated by</th><th>Source version</th><th>Status</th><th>Document actions</th></tr></thead><tbody>
      <?php foreach ($history['rows'] as $row): $snapshot = $row['_snapshot']; $missing = json_decode((string) $row['missing_fields_json'], true); $warnings = json_decode((string) $row['warnings_json'], true); $manifest = $snapshot['file_manifest']; $sourceRecord = $snapshot['source']['record']; ?>
        <tr>
          <td><?= facultyRecordEscape($sourceRecord['title'] ?? 'Saved title unavailable') ?><?php if ($row['joined_source_deleted'] !== null): ?><p class="frr-hint">Source deleted · history only</p><?php elseif ($row['joined_source_status'] === 'archived'): ?><p class="frr-hint">Source archived</p><?php endif; ?></td>
          <td><?= facultyRecordEscape(earistMappingDocument($row['document_type'])['label']) ?></td>
          <td><?= (int) $row['document_version'] ?><?php if (($row['version_origin'] ?? '') === 'edited' && is_array($snapshot['edited_document'] ?? null)): ?><p class="frr-hint">Edited copy of Version <?= (int) ($snapshot['edited_document']['edited_from_version'] ?? 0) ?></p><?php endif; ?></td>
          <td><?= facultyRecordEscape($row['generated_at']) ?></td>
          <td><?= facultyRecordEscape($snapshot['generated_by']['name'] ?? 'Faculty account') ?></td>
          <td><?= (int) $row['source_record_version'] ?></td>
          <td><?= is_array($missing) && !$missing ? 'Complete' : 'Missing information' ?><?= is_array($warnings) && $warnings ? '; warnings acknowledged' : '' ?><?php earistDocumentRenderHistoryStatus($row); ?></td>
          <td><div class="ed-docx-actions">
            <?php $primaryUrl = SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'primary']); ?>
            <button class="frr-btn frr-btn--small ed-docx-actions__preview" type="button" data-earist-preview-open data-preview-url="<?= facultyRecordEscape($primaryUrl) ?>" data-download-url="<?= facultyRecordEscape($primaryUrl) ?>" data-preview-title="<?= facultyRecordEscape(earistMappingDocument($row['document_type'])['label'] . ' · Version ' . (int) $row['document_version']) ?>" data-generated-id="<?= (int) $row['generated_document_id'] ?>" data-file-selector="primary" hidden>Preview DOCX</button>
            <a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape($primaryUrl) ?>">Download DOCX</a>
            <?php if (isset($manifest['files']['sdg_continuation'])): $companionUrl = SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'sdg_continuation']); ?>
              <button class="frr-btn frr-btn--small ed-docx-actions__preview" type="button" data-earist-preview-open data-preview-url="<?= facultyRecordEscape($companionUrl) ?>" data-download-url="<?= facultyRecordEscape($companionUrl) ?>" data-preview-title="QSF SDG companion · Version <?= (int) $row['document_version'] ?>" data-generated-id="<?= (int) $row['generated_document_id'] ?>" data-file-selector="sdg_continuation" hidden>Preview QSF companion</button>
              <a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape($companionUrl) ?>">Download QSF SDG companion</a>
            <?php endif; ?>
          </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php
        $lastPage = max(1, (int) ceil($history['total'] / $history['page_size']));
        $total = (int) $history['total'];
        $pageSize = (int) $history['page_size'];

        // Build page range with ellipsis
        function buildPageRange(int $currentPage, int $lastPage, int $window = 2): array {
            $range = [];
            if ($lastPage <= 7) {
                for ($i = 1; $i <= $lastPage; $i++) $range[] = $i;
            } else {
                $range[] = 1;
                $start = max(2, $currentPage - $window);
                $end = min($lastPage - 1, $currentPage + $window);
                if ($start > 2) $range[] = '...';
                for ($i = $start; $i <= $end; $i++) $range[] = $i;
                if ($end < $lastPage - 1) $range[] = '...';
                $range[] = $lastPage;
            }
            return $range;
        }

        $pageRange = buildPageRange($page, $lastPage);
        $baseUrl = SITE_URL . 'pages/faculty/faculty-generated-documents.php';
        $pageQuery = array_filter([
            'search' => $filters['search'], 'document_type' => $filters['document_type'],
            'status' => $filters['status'], 'origin' => $filters['origin'], 'created' => $created > 0 ? $created : null,
        ], static fn($value): bool => $value !== null && $value !== '');
        $pageUrl = static fn(int $targetPage): string => facultyRecordEscape($baseUrl . '?' . http_build_query(['page' => $targetPage] + $pageQuery));
      ?>
      <nav aria-label="Document history pages" class="frr-pagination">
        <p class="frr-pagination__info">Page <?= (int) $page ?> of <?= $lastPage ?> · <?= $total ?> versions</p>
        <ul class="frr-pagination__list">
          <?php if ($page > 1): ?>
            <li><a class="frr-pagination__link" href="<?= $pageUrl(1) ?>" aria-label="First page">« First</a></li>
            <li><a class="frr-pagination__link" href="<?= $pageUrl($page - 1) ?>" aria-label="Previous page">‹ Previous</a></li>
          <?php else: ?>
            <li><span class="frr-pagination__link frr-pagination__link--disabled" aria-hidden="true">« First</span></li>
            <li><span class="frr-pagination__link frr-pagination__link--disabled" aria-hidden="true">‹ Previous</span></li>
          <?php endif; ?>

          <?php foreach ($pageRange as $p): ?>
            <?php if ($p === '...'): ?>
              <li><span class="frr-pagination__ellipsis" aria-hidden="true">…</span></li>
            <?php elseif ($p === $page): ?>
              <li><span class="frr-pagination__link frr-pagination__link--current" aria-current="page"><?= $p ?></span></li>
            <?php else: ?>
              <li><a class="frr-pagination__link" href="<?= $pageUrl((int) $p) ?>"><?= $p ?></a></li>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($page < $lastPage): ?>
            <li><a class="frr-pagination__link" href="<?= $pageUrl($page + 1) ?>" aria-label="Next page">Next ›</a></li>
            <li><a class="frr-pagination__link" href="<?= $pageUrl($lastPage) ?>" aria-label="Last page">Last »</a></li>
          <?php else: ?>
            <li><span class="frr-pagination__link frr-pagination__link--disabled" aria-hidden="true">Next ›</span></li>
            <li><span class="frr-pagination__link frr-pagination__link--disabled" aria-hidden="true">Last »</span></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </section>
</div>
<?php earistDocumentRenderPreviewDialog(true); ?>
<?php renderFacultyShellClose(); ?>
