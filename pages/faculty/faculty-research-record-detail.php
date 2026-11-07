<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';

$user = facultyRecordRequireUser();
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$available = false;
$record = null;
try {
    $available = facultyRecordAvailable($conn);
    $record = $available ? facultyRecordLoadOwned($conn, $recordId, (int) $user['user_id']) : null;
} catch (Throwable $exception) {
    error_log('Faculty research record detail failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    $available = false;
}
if ($available && !$record) facultyRecordNotFound($user);
if (!$available) http_response_code(503);
$flash = is_string($_SESSION['faculty_records_flash'] ?? null) ? $_SESSION['faculty_records_flash'] : '';
unset($_SESSION['faculty_records_flash']);
renderFacultyShell($user, 'faculty-research-records', 'Research Record', 'Saved research information.');
facultyRecordAssets();
$showFields = static function (array $fields, array $values = []) use ($record): void {
    $visibleFields = [];
    foreach ($fields as $key => $label) {
        $value = $values[$key] ?? $record[$key] ?? '';
        if (trim((string) $value) !== '') $visibleFields[$key] = [$label, $value];
    }
    if (!$visibleFields) {
        echo '<p class="frr-empty-section">No information has been added for this section.</p>';
        return;
    }
    echo '<dl class="frr-details">';
    foreach ($visibleFields as [$label, $value]) echo '<div><dt>' . facultyRecordEscape($label) . '</dt><dd>' . facultyRecordEscape($value) . '</dd></div>';
    echo '</dl>';
};
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(facultyRecordUrl('list', $record ? ['status' => $record['deleted_at'] !== null ? 'deleted' : $record['status']] : [])) ?>">← My Research Records</a>
  <?php if (!$available): ?>
    <section class="frr-panel"><h2>Research records are unavailable</h2><p>Please try again or ask the administrator to check the research-record database setup.</p></section>
  <?php else: ?>
    <?php if ($flash): ?><div class="frr-alert frr-alert--success" role="status"><?= facultyRecordEscape($flash) ?></div><?php endif; ?>
    <section class="frr-panel frr-record-overview" aria-labelledby="frr-record-title">
      <div class="frr-record-overview__main">
        <p class="frr-eyebrow">Research record #<?= (int) $recordId ?></p>
        <h2 id="frr-record-title"><?= facultyRecordEscape($record['title']) ?></h2>
        <div class="frr-record-overview__meta">
          <span class="frr-badge frr-badge--<?= $record['deleted_at'] !== null ? 'deleted' : facultyRecordEscape($record['status']) ?>"><?= facultyRecordEscape(facultyRecordStatus($record)) ?></span>
          <span><strong>Created</strong> <?= facultyRecordEscape($record['created_at']) ?></span>
          <span><strong>Updated</strong> <?= facultyRecordEscape($record['updated_at']) ?></span>
        </div>
      </div>
      <div class="frr-record-overview__actions" role="group" aria-label="Research record actions"><?php facultyRecordRenderActions($record, [], true); ?></div>
    </section>
    <?php if ($record['deleted_at'] !== null): ?><div class="frr-alert">This record is recoverable. Restore it to <?= $record['status'] === 'archived' ? 'Archived' : 'Active' ?> before editing. Its information and author relationships have been retained.</div><?php endif; ?>
    <section class="frr-panel frr-record-document-link" aria-labelledby="frr-documents-title">
      <span class="frr-section-number" aria-hidden="true">DOCX</span>
      <div class="frr-record-document-link__copy"><h3 id="frr-documents-title">EARIST documents</h3><p>Review document fields, save answers, and open generated versions for this record.</p></div>
      <a class="frr-btn frr-btn--primary frr-record-documents-action" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>">Open document workspace</a>
    </section>
    <div class="frr-record-layout">
      <div class="frr-record-layout__main">
        <section class="frr-panel frr-record-section" aria-labelledby="frr-research-information">
          <h3 id="frr-research-information">Research information</h3>
          <?php $showFields(['work_type_label' => 'Type of scholarly work', 'work_type_other' => 'Other scholarly-work type', 'date_started' => 'Date started (year-month)', 'date_completed' => 'Date completed (year-month)']); ?>
        </section>
        <section class="frr-panel frr-record-section" aria-labelledby="frr-conference-information">
          <h3 id="frr-conference-information">Conference information</h3>
          <?php $showFields(
              ['conference_title' => 'Conference title', 'conference_host' => 'Host institution', 'conference_organizer' => 'Organizer', 'conference_venue' => 'Venue', 'conference_start_date' => 'Start date', 'conference_end_date' => 'End date', 'conference_classification' => 'Conference classification', 'presentation_modality' => 'Presentation modality'],
              ['conference_classification' => facultyRecordConferenceClasses()[$record['conference_classification'] ?? ''] ?? '', 'presentation_modality' => facultyRecordModalities()[$record['presentation_modality'] ?? ''] ?? '']
          ); ?>
        </section>
        <section class="frr-panel frr-record-section" aria-labelledby="frr-journal-information">
          <h3 id="frr-journal-information">Journal information</h3>
          <?php $showFields(['journal_title' => 'Target journal title', 'journal_publisher' => 'Journal publisher']); ?>
        </section>
      </div>
      <aside class="frr-record-layout__aside" aria-label="Authors and alignment">
        <section class="frr-panel frr-record-section" aria-labelledby="frr-research-authors">
          <h3 id="frr-research-authors">Research authors</h3>
          <?php if (!$record['authors']): ?><p class="frr-empty-section">No authors have been added for this record.</p><?php else: ?>
            <ol class="frr-author-summary"><?php foreach ($record['authors'] as $author): ?><li><strong><?= facultyRecordEscape($author['full_name']) ?></strong><span class="frr-author-role"><?= $author['author_role'] === 'lead' ? 'Lead Author' : 'Co-Author' ?> &middot; <?= $author['author_type'] === 'internal' ? 'Internal faculty' : 'External author' ?></span><?php if (trim((string) $author['position']) !== ''): ?><p><?= facultyRecordEscape($author['position']) ?></p><?php endif; ?><?php if (trim((string) $author['affiliation']) !== ''): ?><p><?= facultyRecordEscape($author['affiliation']) ?></p><?php endif; ?></li><?php endforeach; ?></ol>
          <?php endif; ?>
        </section>
        <section class="frr-panel frr-record-section" aria-labelledby="frr-sdg-alignment">
          <h3 id="frr-sdg-alignment">SDG alignment</h3>
          <?php if (!$record['sdgs'] && trim((string) $record['sdg_other']) === ''): ?><p class="frr-empty-section">No SDG alignment has been added.</p><?php else: ?>
            <ul class="frr-sdg-summary"><?php foreach ($record['sdgs'] as $number): ?><li><b><?= $number ?>.</b> <?= facultyRecordEscape(facultyRecordSdgs()[$number] ?? 'Unknown SDG') ?></li><?php endforeach; ?><?php if (trim((string) $record['sdg_other']) !== ''): ?><li><b>Others:</b> <?= facultyRecordEscape($record['sdg_other']) ?></li><?php endif; ?></ul>
          <?php endif; ?>
        </section>
      </aside>
    </div>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
