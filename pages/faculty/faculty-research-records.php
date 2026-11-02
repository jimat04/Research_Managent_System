<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/faculty-research-records.php';

$user = facultyRecordRequireUser();
$ownerId = (int) $user['user_id'];
$available = false;
try { $available = facultyRecordAvailable($conn); }
catch (Throwable $exception) {
    error_log('Faculty research records setup failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
}
$options = facultyRecordBrowseOptions($_GET);
$error = '';
$flash = is_string($_SESSION['faculty_records_flash'] ?? null) ? $_SESSION['faculty_records_flash'] : '';
unset($_SESSION['faculty_records_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available) {
    try {
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) throw new DomainException('Your form expired. Refresh the page and try again.');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = is_string($_POST['record_action'] ?? null) ? $_POST['record_action'] : '';
        if (!$id || !$version) throw new DomainException('Choose a valid research record.');
        if ($action === 'delete' && ($_POST['confirm_delete'] ?? null) !== '1') throw new DomainException('Confirm that you want to move this record to Deleted / Recoverable.');
        facultyRecordChangeState($conn, $ownerId, $id, $version, $action);
        $_SESSION['faculty_records_flash'] = match ($action) {
            'archive' => 'Research record archived. Its information has been preserved.',
            'delete' => 'Research record moved to Deleted / Recoverable. You can restore it later.',
            default => 'Research record restored.',
        };
        $returnToDetail = ($_POST['return_to'] ?? null) === 'detail';
        header('Location: ' . ($returnToDetail ? facultyRecordUrl('detail', ['id' => $id]) : facultyRecordUrl('list', $options)));
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Faculty research record action failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
        $error = 'We could not update this record. Please try again.';
    }
}
$result = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
if ($available) {
    try { $result = facultyRecordBrowse($conn, $ownerId, $options); }
    catch (Throwable $exception) {
        error_log('Faculty research record list failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
        $error = 'Research records could not be loaded. Please try again.';
    }
} else http_response_code(503);
$options['page'] = $result['page'];
$statuses = ['active' => 'Active', 'archived' => 'Archived', 'deleted' => 'Deleted / Recoverable'];
renderFacultyShell($user, 'faculty-research-records', 'My Research Records', 'Your research information, ready to reuse.');
facultyRecordAssets();
?>
<div class="frr-wrap">
  <div class="frr-page-intro frr-intro-row"><div><p class="frr-eyebrow">Faculty research</p><h2>My Research Records</h2><p>Keep titles, authors, and target publication details together. Add as many research records as you need.</p></div><?php if ($available): ?><a class="frr-btn frr-btn--primary" href="<?= facultyRecordEscape(facultyRecordUrl('form')) ?>">+ Add Research Record</a><?php endif; ?></div>
  <?php if ($flash): ?><div class="frr-alert frr-alert--success" role="status"><?= facultyRecordEscape($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="frr-alert frr-alert--error" role="alert"><?= facultyRecordEscape($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <section class="frr-panel"><h3>Research records are unavailable</h3><p>Please try again or ask the administrator to check the research-record database setup.</p></section>
  <?php else: ?>
    <section class="frr-panel frr-browse" aria-label="Find research records">
      <nav class="frr-tabs" aria-label="Research record status"><?php foreach ($statuses as $value => $label): $tabOptions = array_merge($options, ['status' => $value, 'page' => 1]); ?><a href="<?= facultyRecordEscape(facultyRecordUrl('list', $tabOptions)) ?>"<?= $options['status'] === $value ? ' aria-current="page"' : '' ?>><?= facultyRecordEscape($label) ?></a><?php endforeach; ?></nav>
      <form method="get" action="<?= facultyRecordEscape(facultyRecordUrl('list')) ?>" class="frr-filter-form">
        <input type="hidden" name="status" value="<?= facultyRecordEscape($options['status']) ?>">
        <div class="frr-field frr-filter-search"><label for="frr-search">Search title or author</label><input type="search" name="q" id="frr-search" maxlength="150" value="<?= facultyRecordEscape($options['q']) ?>" placeholder="Research title or author name"></div>
        <div class="frr-field"><label for="frr-sort">Sort by</label><select name="sort" id="frr-sort"><?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'updated' => 'Recently updated', 'title' => 'Research title (A–Z)'] as $value => $label): ?><option value="<?= $value ?>"<?= $options['sort'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="frr-btn frr-btn--primary">Apply</button><a class="frr-btn" href="<?= facultyRecordEscape(facultyRecordUrl('list', ['status' => $options['status']])) ?>">Reset</a>
      </form>
    </section>
    <?php if (!$error && !$result['rows']): ?>
      <section class="frr-panel frr-empty"><span class="frr-empty-icon" aria-hidden="true">📚</span><h3><?= $options['q'] !== '' ? 'No matching research records' : ($options['status'] === 'active' ? 'Your research records start here' : 'No ' . strtolower($statuses[$options['status']]) . ' records') ?></h3><p><?= $options['q'] !== '' ? 'Try another title or author name, or reset the search.' : ($options['status'] === 'active' ? 'Add a research record to save the information needed for the future DOCX Generator.' : 'Records moved here will remain available for viewing and restoration.') ?></p><?php if ($options['status'] === 'active' && $options['q'] === ''): ?><a class="frr-btn frr-btn--primary" href="<?= facultyRecordEscape(facultyRecordUrl('form')) ?>">Add Research Record</a><?php endif; ?></section>
    <?php elseif ($result['rows']): ?>
      <p class="frr-result-count">Showing <?= ($result['page'] - 1) * facultyRecordBrowsePageSize() + 1 ?>-<?= min($result['total'], $result['page'] * facultyRecordBrowsePageSize()) ?> of <?= number_format($result['total']) ?> records | <?= facultyRecordEscape($statuses[$options['status']]) ?> | <?= facultyRecordBrowsePageSize() ?> per page</p>
      <div class="frr-table-wrap" role="region" aria-label="Your research records" tabindex="0"><table class="frr-table"><caption class="frr-sr-only">Your <?= facultyRecordEscape(strtolower($statuses[$options['status']])) ?> research records</caption><thead><tr><th scope="col">Research title / type</th><th scope="col">Authors</th><th scope="col">Status</th><th scope="col">Created / updated</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($result['rows'] as $row): ?>
          <tr><td class="frr-title-cell"><a class="frr-record-title" href="<?= facultyRecordEscape(facultyRecordUrl('detail', ['id' => (int) $row['research_record_id']])) ?>"><?= facultyRecordEscape($row['title']) ?></a><p class="frr-hint"><?= facultyRecordEscape($row['work_type_label']) ?><?= $row['work_type_other'] ? ' · ' . facultyRecordEscape($row['work_type_other']) : '' ?></p></td><td><strong><?= facultyRecordEscape($row['lead_author'] ?: 'Not recorded') ?></strong><p class="frr-hint">Lead author · <?= (int) $row['author_count'] ?> total</p></td><td><span class="frr-badge frr-badge--<?= $row['deleted_at'] !== null ? 'deleted' : facultyRecordEscape($row['status']) ?>"><?= facultyRecordEscape(facultyRecordStatus($row)) ?></span></td><td><span class="frr-date"><?= facultyRecordEscape($row['created_at']) ?></span><span class="frr-date frr-hint">Updated <?= facultyRecordEscape($row['updated_at']) ?></span></td><td><?php facultyRecordRenderActions($row, $options); ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($result['pages'] > 1): ?><nav class="frr-pagination" aria-label="Research records pagination"><?php if ($result['page'] > 1): ?><a class="frr-btn" href="<?= facultyRecordEscape(facultyRecordUrl('list', array_merge($options, ['page' => $result['page'] - 1]))) ?>">← Previous</a><?php endif; ?><span>Page <?= $result['page'] ?> of <?= $result['pages'] ?></span><?php if ($result['page'] < $result['pages']): ?><a class="frr-btn" href="<?= facultyRecordEscape(facultyRecordUrl('list', array_merge($options, ['page' => $result['page'] + 1]))) ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php renderFacultyShellClose(); ?>
