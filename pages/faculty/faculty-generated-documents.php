<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';
require_once __DIR__ . '/../../includes/earist-document-generation.php';
require_once __DIR__ . '/../../includes/earist-document-ui.php';
require_once __DIR__ . '/../../includes/earist-google-drive.php';
require_once __DIR__ . '/../../includes/earist-document-retention.php';

$user = facultyRecordRequireUser();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 5;
$history = ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize];
$error = '';
$createdFound = false;
$googleConfig = earistGoogleDriveConfig();
$googleEnabled = (bool) $googleConfig['enabled'];
$googleStorageUnavailable = false;
$googleConnection = null;
$googleLinks = [];
$googleNotice = '';
$retentionNotice = ['kind' => 'info', 'title' => '', 'message' => ''];
$retentionPreview = ['eligible_sets' => 0, 'pending_sets' => 0, 'bytes' => 0];
$retentionPreviewError = '';
if (isset($_GET['google']) && $_GET['google'] === '1' && isset($_SESSION['earist_google_notice'])) {
    $googleNotice = (string) $_SESSION['earist_google_notice'];
    unset($_SESSION['earist_google_notice']);
}
if (isset($_GET['retention']) && $_GET['retention'] === '1' && isset($_SESSION['earist_retention_notice'])) {
    $storedRetentionNotice = $_SESSION['earist_retention_notice'];
    if (is_array($storedRetentionNotice)) {
        $kind = $storedRetentionNotice['kind'] ?? 'info';
        $retentionNotice = [
            'kind' => in_array($kind, ['success', 'warning', 'error', 'info'], true) ? $kind : 'info',
            'title' => is_string($storedRetentionNotice['title'] ?? null) ? $storedRetentionNotice['title'] : 'Storage cleanup update',
            'message' => is_string($storedRetentionNotice['message'] ?? null) ? $storedRetentionNotice['message'] : '',
        ];
    } elseif (is_string($storedRetentionNotice)) {
        $retentionNotice = ['kind' => 'info', 'title' => 'Storage cleanup update', 'message' => $storedRetentionNotice];
    }
    unset($_SESSION['earist_retention_notice']);
}
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
    if ($googleEnabled) {
        try {
            $googleConnection = earistGoogleDriveConnectionStatus($conn, (int) $user['user_id']);
            $googleLinks = earistGoogleDriveLinksForHistory($conn, (int) $user['user_id'], array_column($history['rows'], 'generated_document_id'));
        } catch (Throwable $exception) {
            error_log('EARIST Google Drive history links failed: ' . get_class($exception));
            $googleStorageUnavailable = true;
        }
    }
}
catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? 403 : 503);
    error_log('EARIST faculty history failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $error = 'Generated document history is unavailable. Please try again later.';
}
if ($error === '') {
    try {
        $retentionPreview = earistDocumentRetentionPreview($conn);
    } catch (Throwable $exception) {
        error_log('EARIST faculty retention preview failed: ' . get_class($exception));
        $retentionPreviewError = 'Storage cleanup preview is unavailable. The RMS administrator may need to check the document storage folder and database migration.';
    }
}
renderFacultyShell($user, 'faculty-generated-documents', 'Generated Documents', 'Download your saved EARIST DOCX versions.');
facultyRecordAssets();
earistDocumentPreviewAssets();
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-workspace.php') ?>">← DOCX Generator</a>
  <section class="frr-panel">
    <h2>Generated Documents</h2>
    <p>Each entry is a saved version. Downloading opens the stored file and does not generate another version. PDF conversion is unavailable. Google Docs edits return to RMS only when you import them.</p>
    <?php if ($createdFound): ?><div class="frr-alert" role="status">DOCX version saved in Generated Documents.</div><?php endif; ?>
    <?php if ($googleNotice !== ''): ?><div class="frr-alert" role="status"><?= facultyRecordEscape($googleNotice) ?></div><?php endif; ?>
    <section class="earist-google-connection" aria-labelledby="earist-google-connection-title">
      <div class="earist-google-connection__copy"><h3 id="earist-google-connection-title">Google Docs editing</h3>
        <?php if (!$googleEnabled): ?><p>Google Docs editing is not enabled on this RMS server. Preview and download remain available.</p>
        <?php elseif ($googleStorageUnavailable): ?><p role="alert">Google Drive connection storage is unavailable. The RMS administrator may need to apply the Google Drive migration.</p>
        <?php elseif ($googleConnection): ?><p>Connected as <strong><?= facultyRecordEscape((string) $googleConnection['google_email']) ?></strong>. RMS uploads a working copy to this Google Drive; your saved RMS version remains unchanged.</p>
        <?php else: ?><p>Connect a Google account to edit DOCX working copies in Google Docs. RMS stores your connection securely; your RMS history changes only when you import an edit.</p><?php endif; ?>
      </div>
      <?php if ($googleEnabled && !$googleStorageUnavailable): ?>
        <?php if ($googleConnection): ?><form class="earist-google-connection__action" method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>" onsubmit="return typeof window.earistDisconnectConfirm !== 'function' ? confirm('Disconnect Google Drive? RMS will remove its saved authorization. Existing copies remain in Google Drive.') : window.earistDisconnectConfirm(this, event);">
          <?= csrfField() ?><input type="hidden" name="action" value="disconnect"><button class="frr-btn ed-google-button" type="submit"><span class="ed-google-button__mark" aria-hidden="true">G</span>Disconnect Google Drive</button>
        </form><?php else: ?><form class="earist-google-connection__action" method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>">
          <?= csrfField() ?><input type="hidden" name="action" value="connect"><button class="frr-btn ed-google-button" type="submit"><span class="ed-google-button__mark" aria-hidden="true">G</span>Connect Google Drive</button>
        </form><?php endif; ?>
      <?php endif; ?>
    </section>
    <?php if ($retentionNotice['message'] !== ''): ?>
      <div class="frr-alert" data-ed-retention-result-fallback role="<?= $retentionNotice['kind'] === 'error' ? 'alert' : 'status' ?>"><?= facultyRecordEscape($retentionNotice['message']) ?></div>
      <dialog class="ed-retention-result" data-ed-retention-result data-result-kind="<?= facultyRecordEscape($retentionNotice['kind']) ?>" aria-labelledby="ed-retention-result-title" aria-describedby="ed-retention-result-message">
        <div class="ed-retention-result__card">
          <span class="ed-retention-result__mark" aria-hidden="true"><?= $retentionNotice['kind'] === 'success' ? '✓' : ($retentionNotice['kind'] === 'warning' ? '!' : 'i') ?></span>
          <p class="ed-retention-result__eyebrow">Faculty · Generated Documents</p>
          <h2 id="ed-retention-result-title"><?= facultyRecordEscape($retentionNotice['title']) ?></h2>
          <p class="ed-retention-result__message" id="ed-retention-result-message"><?= facultyRecordEscape($retentionNotice['message']) ?></p>
          <button class="ed-retention-result__close" type="button" data-ed-retention-result-close>Done</button>
        </div>
      </dialog>
    <?php endif; ?>
    <section class="ed-retention-panel" aria-labelledby="ed-retention-title">
      <div class="ed-retention-panel__copy">
        <h3 id="ed-retention-title">Free RMS storage</h3>
        <p>Remove older DOCX file sets from RMS while keeping their version history entries. The latest version for each document type stays available. Google Drive copies are not affected.</p>
        <?php if ($retentionPreviewError !== ''): ?><p class="ed-retention-panel__error" role="alert"><?= facultyRecordEscape($retentionPreviewError) ?></p>
        <?php elseif ($retentionPreview['eligible_sets'] > 0): ?><p><strong><?= number_format((int) $retentionPreview['eligible_sets']) ?></strong> older file <?= $retentionPreview['eligible_sets'] === 1 ? 'set matches' : 'sets match' ?> the retention rule; about <strong><?= number_format((int) $retentionPreview['bytes'] / 1048576, 2) ?> MB</strong> of validated files are available to reclaim.<?php if ($retentionPreview['pending_sets'] > 0): ?> <?= number_format((int) $retentionPreview['pending_sets']) ?> set(s) need a cleanup retry.<?php endif; ?><?php if ($retentionPreview['blocked_sets'] > 0): ?> <?= number_format((int) $retentionPreview['blocked_sets']) ?> set(s) could not be safely inspected and will be left untouched.<?php endif; ?></p>
        <?php else: ?><p>No older DOCX files are eligible for cleanup. The latest file set for each document type is retained.</p><?php endif; ?>
      </div>
      <?php if ($retentionPreviewError === '' && $retentionPreview['eligible_sets'] > 0 && ($retentionPreview['bytes'] > 0 || $retentionPreview['pending_sets'] > 0)): ?>
        <form class="ed-retention-panel__action" method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-document-retention.php') ?>" data-ed-retention-confirm data-retention-sets="<?= (int) $retentionPreview['eligible_sets'] ?>" data-retention-bytes="<?= (int) $retentionPreview['bytes'] ?>" data-retention-pending="<?= (int) $retentionPreview['pending_sets'] ?>" onsubmit="return typeof window.earistRetentionConfirm !== 'function' ? confirm('Remove older RMS DOCX files? RMS history and the latest file set for each document type will remain. Google Drive copies will not be affected.') : window.earistRetentionConfirm(this, event);">
          <?= csrfField() ?><input type="hidden" name="action" value="cleanup"><button class="frr-btn ed-retention-panel__button" type="submit"><?= $retentionPreview['pending_sets'] > 0 ? 'Retry storage cleanup' : 'Remove older DOCX files' ?></button>
        </form>
      <?php endif; ?>
    </section>
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
          <td><?= is_array($missing) && !$missing ? 'Complete' : 'Missing information' ?><?= is_array($warnings) && $warnings ? '; warnings acknowledged' : '' ?><?php if (($row['file_retention_status'] ?? 'available') === 'pruned'): ?>; DOCX files removed to free storage<?php elseif (($row['file_retention_status'] ?? 'available') === 'pruning'): ?>; storage cleanup pending<?php endif; ?><?php earistDocumentRenderHistoryStatus($row); ?></td>
          <td><div class="ed-docx-actions">
            <?php $fileAvailable = ($row['file_retention_status'] ?? 'available') === 'available'; ?>
            <?php $primaryUrl = SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'primary']); ?>
            <?php if ($fileAvailable): ?><button class="frr-btn frr-btn--small ed-docx-actions__preview" type="button" data-earist-preview-open data-preview-url="<?= facultyRecordEscape($primaryUrl) ?>" data-download-url="<?= facultyRecordEscape($primaryUrl) ?>" data-preview-title="<?= facultyRecordEscape(earistMappingDocument($row['document_type'])['label'] . ' · Version ' . (int) $row['document_version']) ?>" hidden>Preview DOCX</button>
            <a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape($primaryUrl) ?>">Download DOCX</a><?php else: ?><span class="frr-hint">RMS DOCX file unavailable</span><?php endif; ?>
            <?php if ($googleEnabled && $googleConnection && !$googleStorageUnavailable): ?>
              <?php $primaryDriveLink = $googleLinks[(int) $row['generated_document_id']]['primary'] ?? null; ?>
              <?php if ($primaryDriveLink): ?><a class="frr-btn frr-btn--small ed-google-button" href="<?= facultyRecordEscape((string) $primaryDriveLink['web_view_link']) ?>" target="_blank" rel="noopener noreferrer"><span class="ed-google-button__mark" aria-hidden="true">G</span>Open Google Docs copy</a>
                <?php if ($fileAvailable): ?>
                <form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>" data-ed-import-confirm data-confirm-title="Import your latest edit?" data-confirm-message="<?= facultyRecordEscape('Import your latest Google Drive edits into your Faculty document history? RMS will add a new version, and your current version will remain unchanged.') ?>" data-progress-title="Importing the latest edit…" onsubmit="return typeof window.earistImportConfirm !== 'function' ? confirm(this.dataset.confirmMessage) : window.earistImportConfirm(this, event);">
                  <?= csrfField() ?><input type="hidden" name="action" value="import"><input type="hidden" name="drive_file_id" value="<?= facultyRecordEscape((string) $primaryDriveLink['drive_file_id']) ?>"><button class="frr-btn frr-btn--small ed-google-import-button" type="submit"><span aria-hidden="true">↓</span>Import latest edit</button>
                </form>
                <?php else: ?><span class="frr-hint">Import unavailable because the RMS source file was removed.</span><?php endif; ?>
              <?php elseif ($fileAvailable): ?><form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>" target="_blank" rel="noopener noreferrer" data-earist-google-open-form data-generated-document-id="<?= (int) $row['generated_document_id'] ?>" data-file-selector="primary">
                  <?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="generated_document_id" value="<?= (int) $row['generated_document_id'] ?>"><input type="hidden" name="file" value="primary"><button class="frr-btn frr-btn--small ed-google-button" type="submit"><span class="ed-google-button__mark" aria-hidden="true">G</span>Edit in Google Docs</button>
                </form><?php else: ?><span class="frr-hint">A Google Drive copy cannot be created from a removed RMS file.</span><?php endif; ?>
            <?php endif; ?>
            <?php if (isset($manifest['files']['sdg_continuation'])): $companionUrl = SITE_URL . 'pages/shared/download-earist-document.php?' . http_build_query(['id' => (int) $row['generated_document_id'], 'file' => 'sdg_continuation']); ?>
              <?php if ($fileAvailable): ?>
              <button class="frr-btn frr-btn--small ed-docx-actions__preview" type="button" data-earist-preview-open data-preview-url="<?= facultyRecordEscape($companionUrl) ?>" data-download-url="<?= facultyRecordEscape($companionUrl) ?>" data-preview-title="QSF SDG companion · Version <?= (int) $row['document_version'] ?>" hidden>Preview QSF companion</button>
              <a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape($companionUrl) ?>">Download QSF SDG companion</a>
              <?php endif; ?>
              <?php if ($googleEnabled && $googleConnection && !$googleStorageUnavailable): ?>
                <?php $companionDriveLink = $googleLinks[(int) $row['generated_document_id']]['sdg_continuation'] ?? null; ?>
                <?php if ($companionDriveLink): ?><a class="frr-btn frr-btn--small ed-google-button" href="<?= facultyRecordEscape((string) $companionDriveLink['web_view_link']) ?>" target="_blank" rel="noopener noreferrer"><span class="ed-google-button__mark" aria-hidden="true">G</span>Open QSF Google Docs copy</a>
                  <?php if ($fileAvailable): ?>
                  <form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>" data-ed-import-confirm data-confirm-title="Import your QSF companion?" data-confirm-message="<?= facultyRecordEscape('Import your latest QSF companion edits into your Faculty document history? RMS will add a new version, and the primary document will remain unchanged.') ?>" data-progress-title="Importing the latest QSF edit…" onsubmit="return typeof window.earistImportConfirm !== 'function' ? confirm(this.dataset.confirmMessage) : window.earistImportConfirm(this, event);">
                    <?= csrfField() ?><input type="hidden" name="action" value="import"><input type="hidden" name="drive_file_id" value="<?= facultyRecordEscape((string) $companionDriveLink['drive_file_id']) ?>"><button class="frr-btn frr-btn--small ed-google-import-button" type="submit"><span aria-hidden="true">↓</span>Import latest QSF edit</button>
                  </form>
                  <?php else: ?><span class="frr-hint">Import unavailable because the RMS source file was removed.</span><?php endif; ?>
                <?php elseif ($fileAvailable): ?><form method="post" action="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/earist-google-drive.php') ?>" target="_blank" rel="noopener noreferrer" data-earist-google-open-form data-generated-document-id="<?= (int) $row['generated_document_id'] ?>" data-file-selector="sdg_continuation">
                    <?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="generated_document_id" value="<?= (int) $row['generated_document_id'] ?>"><input type="hidden" name="file" value="sdg_continuation"><button class="frr-btn frr-btn--small ed-google-button" type="submit"><span class="ed-google-button__mark" aria-hidden="true">G</span>Edit QSF in Google Docs</button>
                  </form><?php else: ?><span class="frr-hint">A Google Drive copy cannot be created from a removed RMS file.</span><?php endif; ?>
              <?php endif; ?>
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
<?php earistDocumentRenderPreviewDialog(); ?>
<dialog class="ed-import-confirm" data-ed-import-dialog aria-labelledby="ed-import-confirm-title" aria-describedby="ed-import-confirm-message ed-retention-confirm-stats ed-import-confirm-note">
  <div class="ed-import-confirm__card">
    <div class="ed-import-confirm__mark" aria-hidden="true">↓</div>
    <p class="ed-import-confirm__eyebrow">Faculty · Generated Documents</p>
    <h2 id="ed-import-confirm-title" data-ed-import-title>Import your latest edit?</h2>
    <p class="ed-import-confirm__message" id="ed-import-confirm-message" data-ed-import-message></p>
    <div class="ed-retention-confirm__stats" id="ed-retention-confirm-stats" data-ed-retention-stats hidden>
      <div><strong data-ed-retention-sets>0</strong><span>older file sets</span></div>
      <div><strong data-ed-retention-bytes>0 MB</strong><span>validated files available to reclaim</span></div>
    </div>
    <div class="ed-import-confirm__note" id="ed-import-confirm-note"><span aria-hidden="true" data-ed-confirm-note-mark>✓</span><span data-ed-confirm-note>Your existing RMS history version will be kept.</span></div>
    <div class="ed-import-confirm__actions">
      <button class="ed-import-confirm__cancel" type="button" data-ed-import-cancel>Cancel</button>
      <button class="ed-import-confirm__submit" type="button" data-ed-import-submit>Import version</button>
    </div>
  </div>
</dialog>
<dialog class="ed-import-progress" data-ed-import-progress aria-labelledby="ed-import-progress-title" aria-describedby="ed-import-progress-message">
  <div class="ed-import-progress__content" role="status" aria-live="polite">
    <span class="ed-import-progress__spinner" aria-hidden="true"></span>
    <p class="ed-import-progress__eyebrow">Faculty · Generated Documents</p>
    <h2 id="ed-import-progress-title" data-ed-import-progress-title>Importing the latest edit…</h2>
    <p id="ed-import-progress-message" data-ed-import-progress-message>RMS is saving a new version to your document history.</p>
  </div>
</dialog>
<script>
(() => {
  const resultDialog = document.querySelector('[data-ed-retention-result]');
  if (resultDialog && typeof resultDialog.showModal === 'function') {
    const fallback = document.querySelector('[data-ed-retention-result-fallback]');
    const closeButton = resultDialog.querySelector('[data-ed-retention-result-close]');
    fallback.hidden = true;
    closeButton.addEventListener('click', () => resultDialog.close());
    resultDialog.addEventListener('click', event => {
      if (event.target === resultDialog) resultDialog.close();
    });
    resultDialog.showModal();
    closeButton.focus({ preventScroll: true });
  }

  const dialog = document.querySelector('[data-ed-import-dialog]');
  const progressDialog = document.querySelector('[data-ed-import-progress]');
  if (dialog && typeof dialog.showModal === 'function') {
    const title = dialog.querySelector('[data-ed-import-title]');
    const message = dialog.querySelector('[data-ed-import-message]');
    const note = dialog.querySelector('[data-ed-confirm-note]');
    const noteMark = dialog.querySelector('[data-ed-confirm-note-mark]');
    const mark = dialog.querySelector('.ed-import-confirm__mark');
    const retentionStats = dialog.querySelector('[data-ed-retention-stats]');
    const retentionSets = dialog.querySelector('[data-ed-retention-sets]');
    const retentionBytes = dialog.querySelector('[data-ed-retention-bytes]');
    const cancelButton = dialog.querySelector('[data-ed-import-cancel]');
    const submitButton = dialog.querySelector('[data-ed-import-submit]');
    const progressTitle = progressDialog.querySelector('[data-ed-import-progress-title]');
    const progressMessage = progressDialog.querySelector('[data-ed-import-progress-message]');
    let pendingForm = null;
    let pendingSubmitter = null;

    window.earistImportConfirm = (form, event) => {
      if (form.dataset.edImportConfirmed === 'true') {
        delete form.dataset.edImportConfirmed;
        return true;
      }
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      dialog.dataset.confirmKind = 'import';
      mark.textContent = '↓';
      title.textContent = form.dataset.confirmTitle || 'Import your latest edit?';
      message.textContent = form.dataset.confirmMessage || '';
      retentionStats.hidden = true;
      noteMark.textContent = '✓';
      note.textContent = 'Your existing RMS history version will be kept.';
      submitButton.textContent = 'Import version';
      dialog.returnValue = 'cancel';
      dialog.showModal();
      cancelButton.focus({ preventScroll: true });
      return false;
    };

    window.earistRetentionConfirm = (form, event) => {
      if (form.dataset.edRetentionConfirmed === 'true') {
        delete form.dataset.edRetentionConfirmed;
        return true;
      }
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      const sets = Math.max(0, Number.parseInt(form.dataset.retentionSets || '0', 10) || 0);
      const bytes = Math.max(0, Number.parseInt(form.dataset.retentionBytes || '0', 10) || 0);
      const pending = Math.max(0, Number.parseInt(form.dataset.retentionPending || '0', 10) || 0);
      const megabytes = (bytes / 1048576).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      dialog.dataset.confirmKind = 'retention';
      mark.textContent = '−';
      title.textContent = pending > 0 ? 'Retry removing older files?' : 'Free up RMS storage?';
      message.textContent = `This will remove ${sets.toLocaleString()} older DOCX file ${sets === 1 ? 'set' : 'sets'} from RMS. This action cannot be undone.`;
      retentionSets.textContent = sets.toLocaleString();
      retentionBytes.textContent = `${megabytes} MB`;
      retentionStats.hidden = false;
      noteMark.textContent = '✓';
      note.textContent = 'Your version history stays in RMS. The latest set for each document type, including both QSF files, stays available. Google Drive copies are not affected.';
      submitButton.textContent = pending > 0 ? 'Retry cleanup' : 'Remove older files';
      dialog.returnValue = 'cancel';
      dialog.showModal();
      cancelButton.focus({ preventScroll: true });
      return false;
    };

    window.earistDisconnectConfirm = (form, event) => {
      if (form.dataset.edDisconnectConfirmed === 'true') {
        delete form.dataset.edDisconnectConfirmed;
        return true;
      }
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      dialog.dataset.confirmKind = 'disconnect';
      mark.textContent = '×';
      title.textContent = 'Disconnect Google Drive?';
      message.textContent = 'RMS will remove its saved Google authorization. You can reconnect at any time.';
      retentionStats.hidden = true;
      noteMark.textContent = '✓';
      note.textContent = 'Existing copies remain in Google Drive. Your saved RMS documents and version history are unchanged.';
      submitButton.textContent = 'Disconnect Google Drive';
      dialog.returnValue = 'cancel';
      dialog.showModal();
      cancelButton.focus({ preventScroll: true });
      return false;
    };

    cancelButton.addEventListener('click', () => dialog.close('cancel'));
    submitButton.addEventListener('click', () => dialog.close('confirm'));
    dialog.addEventListener('click', event => {
      if (event.target === dialog) dialog.close('cancel');
    });
    dialog.addEventListener('close', () => {
      const form = pendingForm;
      const submitter = pendingSubmitter;
      pendingForm = null;
      pendingSubmitter = null;
      if (dialog.returnValue !== 'confirm' || !form || !form.isConnected) return;
      if (dialog.dataset.confirmKind === 'retention') {
        form.dataset.edRetentionConfirmed = 'true';
        if (submitter && form.contains(submitter)) form.requestSubmit(submitter);
        else form.requestSubmit();
        return;
      }
      if (dialog.dataset.confirmKind === 'disconnect') {
        form.dataset.edDisconnectConfirmed = 'true';
        if (submitter && form.contains(submitter)) form.requestSubmit(submitter);
        else form.requestSubmit();
        return;
      }
      if (dialog.dataset.confirmKind !== 'import') return;
      form.dataset.edImportConfirmed = 'true';
      progressTitle.textContent = form.dataset.progressTitle || 'Importing the latest edit…';
      progressMessage.textContent = form.dataset.progressMessage || 'RMS is saving a new version to your document history.';
      progressDialog.showModal();
      window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        if (!form.isConnected) {
          progressDialog.close();
          return;
        }
        if (submitter && form.contains(submitter)) form.requestSubmit(submitter);
        else form.requestSubmit();
      }));
    });
  }

  if (!('BroadcastChannel' in window)) return;
  const forms = Array.from(document.querySelectorAll('[data-earist-google-open-form]'));
  if (!forms.length) return;
  const availableCopies = new Set(forms.map((form) => `${form.dataset.generatedDocumentId}:${form.dataset.fileSelector}`));
  const channel = new BroadcastChannel('earist-google-drive-copy-created');
  channel.addEventListener('message', (event) => {
    const message = event.data;
    if (!message || message.type !== 'copy-created' || !Number.isSafeInteger(message.generatedDocumentId)
      || !['primary', 'sdg_continuation'].includes(message.fileSelector)
      || !availableCopies.has(`${message.generatedDocumentId}:${message.fileSelector}`)) return;
    channel.close();
    window.location.reload();
  });
})();
</script>
<?php renderFacultyShellClose(); ?>
