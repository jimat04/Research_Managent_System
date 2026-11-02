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
$showFields = static function (array $fields) use ($record): void {
    echo '<dl class="frr-details">';
    foreach ($fields as $key => $label) echo '<div><dt>' . facultyRecordEscape($label) . '</dt><dd>' . facultyRecordEscape($record[$key] ?: 'Not specified') . '</dd></div>';
    echo '</dl>';
};
?>
<div class="frr-wrap">
  <a class="frr-back" href="<?= facultyRecordEscape(facultyRecordUrl('list', $record ? ['status' => $record['deleted_at'] !== null ? 'deleted' : $record['status']] : [])) ?>">← My Research Records</a>
  <?php if (!$available): ?>
    <section class="frr-panel"><h2>Research records are unavailable</h2><p>Please try again or ask the administrator to check the research-record database setup.</p></section>
  <?php else: ?>
    <?php if ($flash): ?><div class="frr-alert frr-alert--success" role="status"><?= facultyRecordEscape($flash) ?></div><?php endif; ?>
    <section class="frr-panel frr-record-heading"><p class="frr-eyebrow">Research record #<?= (int) $recordId ?></p><h2><?= facultyRecordEscape($record['title']) ?></h2><span class="frr-badge frr-badge--<?= $record['deleted_at'] !== null ? 'deleted' : facultyRecordEscape($record['status']) ?>"><?= facultyRecordEscape(facultyRecordStatus($record)) ?></span><p class="frr-hint">Created <?= facultyRecordEscape($record['created_at']) ?> · Last updated <?= facultyRecordEscape($record['updated_at']) ?></p><?php facultyRecordRenderActions($record, [], true); ?></section>
    <?php if ($record['deleted_at'] !== null): ?><div class="frr-alert">This record is recoverable. Restore it to <?= $record['status'] === 'archived' ? 'Archived' : 'Active' ?> before editing. Its information and author relationships have been retained.</div><?php endif; ?>
    <section class="frr-panel frr-section-heading frr-record-documents-heading"><span class="frr-section-number" aria-hidden="true">DOCX</span><div><h3>EARIST documents</h3><p>Review document fields, save answers, and access generated versions for this record.</p></div><a class="frr-btn frr-btn--primary frr-record-documents-action" href="<?= facultyRecordEscape(SITE_URL . 'pages/faculty/faculty-document-hub.php?' . http_build_query(['id' => $recordId])) ?>">Choose a document</a></section>
    <section class="frr-panel"><h3>Research information</h3><?php $showFields(['work_type_label' => 'Type of scholarly work', 'work_type_other' => 'Other scholarly-work type', 'date_started' => 'Date started (year-month)', 'date_completed' => 'Date completed (year-month)']); ?></section>
    <section class="frr-panel"><h3>Research authors</h3><ol class="frr-author-summary"><?php foreach ($record['authors'] as $author): ?><li><strong><?= facultyRecordEscape($author['full_name']) ?></strong><span class="frr-author-role"><?= $author['author_role'] === 'lead' ? 'Lead Author' : 'Co-Author' ?> · <?= $author['author_type'] === 'internal' ? 'Internal faculty' : 'External author' ?></span><p><?= facultyRecordEscape($author['position']) ?></p><p><?= facultyRecordEscape($author['affiliation']) ?></p></li><?php endforeach; ?></ol></section>
    <section class="frr-panel"><h3>SDG alignment</h3><?php if (!$record['sdgs'] && !$record['sdg_other']): ?><p class="frr-hint">No SDG alignment selected yet.</p><?php else: ?><ul class="frr-sdg-summary"><?php foreach ($record['sdgs'] as $number): ?><li><b><?= $number ?>.</b> <?= facultyRecordEscape(facultyRecordSdgs()[$number] ?? 'Unknown SDG') ?></li><?php endforeach; ?><?php if ($record['sdg_other']): ?><li><b>Others:</b> <?= facultyRecordEscape($record['sdg_other']) ?></li><?php endif; ?></ul><?php endif; ?></section>
    <section class="frr-panel"><h3>Conference information</h3><?php $showFields(['conference_title' => 'Conference title', 'conference_host' => 'Host institution', 'conference_organizer' => 'Organizer', 'conference_venue' => 'Venue', 'conference_start_date' => 'Start date', 'conference_end_date' => 'End date']); ?><dl class="frr-details"><div><dt>Conference classification</dt><dd><?= facultyRecordEscape(facultyRecordConferenceClasses()[$record['conference_classification'] ?? ''] ?? 'Not specified') ?></dd></div><div><dt>Presentation modality</dt><dd><?= facultyRecordEscape(facultyRecordModalities()[$record['presentation_modality'] ?? ''] ?? 'Not specified') ?></dd></div></dl></section>
    <section class="frr-panel"><h3>Journal information</h3><?php $showFields(['journal_title' => 'Target journal title', 'journal_publisher' => 'Journal publisher']); ?></section>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
