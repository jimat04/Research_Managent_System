<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';

$user = facultyRecordRequireUser();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('The document workspace accepts GET only.');
}
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
$ownerId = (int) $user['user_id'];
$options = facultyRecordBrowseOptions($_GET);
$statuses = ['active' => 'Active', 'archived' => 'Archived', 'deleted' => 'Deleted / Recoverable'];
$available = false;
$error = '';
$result = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
try {
    $available = facultyRecordAvailable($conn);
    if ($available) $result = facultyRecordBrowse($conn, $ownerId, $options);
} catch (Throwable $exception) {
    error_log('Faculty DOCX workspace records failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $error = 'Research records could not be loaded. Please try again.';
}
if (!$available || $error !== '') http_response_code(503);
$options['page'] = $result['page'];
$workspaceUrl = SITE_URL . 'pages/faculty/faculty-document-workspace.php';

renderFacultyShell($user, 'faculty-document-workspace.php', 'DOCX Generator', 'Choose a research record to prepare EARIST documents.');
facultyRecordAssets();
?>
<div class="frr-wrap">
  <header class="frr-page-intro">
    <h2>Choose a research record</h2>
    <p>Open a record to choose an EARIST document, review its mapped details, save document-specific answers, and continue to a separate generation review.</p>
  </header>
  <?php if (!$available || $error !== ''): ?>
    <section class="frr-panel"><h3>Research records are unavailable</h3><p><?= facultyRecordEscape($error !== '' ? $error : 'Please try again or ask the administrator to check the research-record database setup.') ?></p></section>
  <?php else: ?>
    <section class="frr-panel frr-browse" aria-label="Choose a research record for documents">
      <nav class="frr-tabs" aria-label="Research record status">
        <?php foreach ($statuses as $status => $label): $tabOptions = array_merge($options, ['status' => $status, 'page' => 1]); ?>
          <a href="<?= facultyRecordEscape($workspaceUrl . '?' . http_build_query($tabOptions)) ?>"<?= $options['status'] === $status ? ' aria-current="page"' : '' ?>><?= facultyRecordEscape($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <form method="get" action="<?= facultyRecordEscape($workspaceUrl) ?>" class="frr-filter-form">
        <input type="hidden" name="status" value="<?= facultyRecordEscape($options['status']) ?>">
        <div class="frr-field frr-filter-search"><label for="docx-record-search">Search title or author</label><input type="search" name="q" id="docx-record-search" maxlength="150" value="<?= facultyRecordEscape($options['q']) ?>" placeholder="Research title or author name"></div>
        <button type="submit" class="frr-btn frr-btn--primary">Search</button>
        <?php if ($options['q'] !== ''): ?><a class="frr-btn" href="<?= facultyRecordEscape($workspaceUrl . '?' . http_build_query(['status' => $options['status']])) ?>">Clear</a><?php endif; ?>
      </form>
    </section>

    <?php if (!$result['rows']): ?>
      <section class="frr-panel frr-empty"><h3><?= $options['q'] !== '' ? 'No matching research records' : 'No ' . strtolower($statuses[$options['status']]) . ' research records' ?></h3><p><?= $options['q'] !== '' ? 'Try another title or author name, or clear the search.' : 'Research records in this status will appear here when available.' ?></p><?php if ($options['status'] === 'active' && $options['q'] === ''): ?><a class="frr-btn frr-btn--primary" href="<?= facultyRecordEscape(facultyRecordUrl('form')) ?>">Add Research Record</a><?php endif; ?></section>
    <?php else: ?>
      <p class="frr-result-count">Showing <?= ($result['page'] - 1) * facultyRecordBrowsePageSize() + 1 ?>–<?= min($result['total'], $result['page'] * facultyRecordBrowsePageSize()) ?> of <?= number_format($result['total']) ?> <?= facultyRecordEscape(strtolower($statuses[$options['status']])) ?> records</p>
      <div class="frr-table-wrap" role="region" aria-label="Research records for DOCX preparation" tabindex="0"><table class="frr-table"><caption class="frr-sr-only">Your research records available in the document workspace</caption><thead><tr><th scope="col">Research record</th><th scope="col">Lead author</th><th scope="col">Status</th><th scope="col">Document workspace</th></tr></thead><tbody>
        <?php foreach ($result['rows'] as $row): $recordId = (int) $row['research_record_id']; ?>
          <tr><td class="frr-title-cell"><strong><?= facultyRecordEscape($row['title']) ?></strong><p class="frr-hint"><?= facultyRecordEscape($row['work_type_label']) ?></p></td><td><?= facultyRecordEscape($row['lead_author'] ?: 'Not recorded') ?></td><td><span class="frr-badge frr-badge--<?= $row['deleted_at'] !== null ? 'deleted' : facultyRecordEscape($row['status']) ?>"><?= facultyRecordEscape(facultyRecordStatus($row)) ?></span></td><td><a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>"><?= $row['deleted_at'] !== null ? 'View document history' : 'Choose documents' ?></a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($result['pages'] > 1): ?><nav class="frr-pagination" aria-label="Document record pages"><span>Page <?= $result['page'] ?> of <?= $result['pages'] ?></span><?php if ($result['page'] > 1): ?><a class="frr-btn" href="<?= facultyRecordEscape($workspaceUrl . '?' . http_build_query(array_merge($options, ['page' => $result['page'] - 1]))) ?>">Previous</a><?php endif; ?><?php if ($result['page'] < $result['pages']): ?><a class="frr-btn" href="<?= facultyRecordEscape($workspaceUrl . '?' . http_build_query(array_merge($options, ['page' => $result['page'] + 1]))) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
