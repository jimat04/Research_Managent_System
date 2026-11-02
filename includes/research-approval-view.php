<?php
// Included only after authentication, permission checks, and approval-list loading.
if (!isset($approval_list) || !is_array($approval_list)) {
    return;
}
?>
<section class="approval-list" aria-label="Approved research projects">
  <form class="approval-search" method="get" action="<?php echo SITE_URL; ?>pages/shared/research-submission-review.php" role="search">
    <input type="hidden" name="module_page" value="research_approval">
    <input type="hidden" name="view" value="<?php echo rms_approval_escape($active_tab); ?>">
    <input type="hidden" name="course" value="<?php echo rms_approval_escape($course_filter); ?>">
    <label for="approval-search">Search by research title or researcher</label>
    <div class="approval-search-controls">
      <input id="approval-search" type="search" name="q" maxlength="200" value="<?php echo rms_approval_escape($approval_list['search']); ?>">
      <button class="approval-button approval-button-primary" type="submit">Search</button>
      <?php if ($approval_list['search'] !== ''): ?>
        <a class="approval-button" href="<?php echo rms_approval_escape(rms_approval_url('', 1, $active_tab, $course_filter)); ?>">Clear search</a>
      <?php endif; ?>
    </div>
  </form>

  <p class="approval-results" role="status">
    <?php if ($approval_list['total'] > 0): ?>
      Showing <?php echo (int) $approval_list['offset'] + 1; ?>–<?php echo min($approval_list['offset'] + $approval_list['per_page'], $approval_list['total']); ?>
      of <?php echo (int) $approval_list['total']; ?> research <?php echo $approval_list['total'] === 1 ? 'project' : 'projects'; ?>.
      Most recent approvals first.
    <?php else: ?>
      <?php echo $approval_list['search'] !== '' ? 'No research projects match your search.' : 'No approved research submissions yet.'; ?>
    <?php endif; ?>
  </p>

  <?php if (!$approval_list['projects']): ?>
    <div class="approval-empty">
      <h2><?php echo $approval_list['search'] !== '' ? 'No matching research' : 'No approved submissions yet'; ?></h2>
      <p><?php echo $approval_list['search'] !== ''
          ? 'Try a shorter research title or the researcher’s name, or clear the search to see all projects.'
          : rms_approval_escape($section['empty_description']); ?></p>
    </div>
  <?php endif; ?>

  <div class="approval-projects">
    <?php foreach ($approval_list['projects'] as $project): ?>
      <article class="approval-project" aria-labelledby="approval-project-<?php echo (int) $project['project_id']; ?>">
        <header class="approval-project-header">
          <h2 id="approval-project-<?php echo (int) $project['project_id']; ?>"><?php echo rms_approval_escape((string) $project['title']); ?></h2>
          <p class="approval-researcher">Researcher: <?php echo rms_approval_escape((string) $project['researcher_name']); ?><?php if (trim((string) $project['researcher_program']) !== ''): ?> · <?php echo rms_approval_escape((string) $project['researcher_program']); ?><?php endif; ?></p>
          <p class="approval-progress"><strong>Progress:</strong> <?php echo rms_approval_escape(rms_approval_progress($project)); ?></p>
        </header>

        <details class="approval-documents">
          <summary>
            <span class="approval-show">View approved documents</span>
            <span class="approval-hide">Hide approved documents</span>
          </summary>
          <div class="approval-documents-body">
            <h3>Approved documents</h3>
            <ul class="approval-document-list">
              <?php foreach ($project['items'] as $item): ?>
                <?php
                  $label = $item['submission_type'] === 'title' ? 'Research title'
                      : ($item['submission_type'] === 'final_manuscript' ? 'Complete Research Manuscript' : 'Chapter ' . (int) $item['chapter_number']);
                  $legacy = $item['snapshot']['legacy_import'] ?? [];
                  $is_legacy = is_array($legacy) && !empty($legacy['imported']);
                  $approval_time = !$is_legacy && $item['latest_decision'] === 'approved' && $item['latest_reviewed_at'] !== ''
                      ? strtotime($item['latest_reviewed_at']) : false;
                  $legacy_date = '';
                  if ($is_legacy) {
                      foreach (['approximate_project_created_at', 'approximate_project_creation_date', 'project_created_at', 'project_creation_date', 'approximate_creation_date'] as $date_key) {
                          if (isset($legacy[$date_key]) && is_scalar($legacy[$date_key]) && trim((string) $legacy[$date_key]) !== '') {
                              $legacy_date = trim((string) $legacy[$date_key]);
                              break;
                          }
                      }
                      $legacy_time = $legacy_date !== '' ? strtotime($legacy_date) : false;
                      if (!preg_match('/^\d{4}$/', $legacy_date) && $legacy_time !== false) {
                          $legacy_date = date('F j, Y', $legacy_time);
                      }
                  }
                ?>
                <li id="submission-<?php echo (int) $item['version_id']; ?>" class="approval-document">
                  <h4><?php echo rms_approval_escape($label); ?></h4>
                  <p class="approval-date">
                    <?php if ($approval_time !== false): ?>
                      Approved <time datetime="<?php echo rms_approval_escape(date(DATE_ATOM, $approval_time)); ?>"><?php echo rms_approval_escape(date('F j, Y', $approval_time)); ?></time>
                    <?php else: ?>
                      Approval date unavailable
                    <?php endif; ?>
                  </p>
                  <?php if ($is_legacy): ?>
                    <p class="approval-note">Imported legacy record. Original submission and reviewer details are unavailable.<?php if ($legacy_date !== ''): ?> Approximate project-creation date: <?php echo rms_approval_escape($legacy_date); ?>.<?php endif; ?></p>
                  <?php elseif ((int) $item['submitted_by'] !== (int) $project['researcher_id']): ?>
                    <p class="approval-note">Submitted by <?php echo rms_approval_escape($item['submitter_name']); ?><?php if ($item['submitter_program'] !== ''): ?> · <?php echo rms_approval_escape($item['submitter_program']); ?><?php endif; ?></p>
                  <?php endif; ?>

                  <?php foreach ($item['uploads'] as $upload): ?>
                    <?php
                      $viewer_url = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $upload['id'] . '&mode=viewer';
                      $download_url = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $upload['id'] . '&mode=download';
                    ?>
                    <div class="approval-file">
                      <p class="approval-file-name"><?php echo rms_approval_escape($upload['name']); ?></p>
                      <div class="approval-file-actions">
                        <a class="approval-button approval-button-primary" href="<?php echo rms_approval_escape($viewer_url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo rms_approval_escape('Open ' . $label . ' document: ' . $upload['name'] . ' (opens in a new tab)'); ?>">Open document <span class="approval-new-tab">(new tab)</span></a>
                        <?php if ($can_download_submission_documents): ?>
                          <a class="approval-button" href="<?php echo rms_approval_escape($download_url); ?>" aria-label="<?php echo rms_approval_escape('Download ' . $label . ' document: ' . $upload['name']); ?>">Download</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (!$item['uploads']): ?>
                    <p class="approval-note">No document is attached to this approved submission.</p>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="approval-history-note">Versions, comments, and suggestions are available in the research history.</p>
            <a class="approval-button" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>#research-trail">View research history</a>
          </div>
        </details>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($approval_list['total_pages'] > 1): ?>
    <nav class="approval-pagination" aria-label="Research approval pages">
      <?php if ($approval_list['page'] > 1): ?>
        <a class="approval-button" rel="prev" href="<?php echo rms_approval_escape(rms_approval_url($approval_list['search'], $approval_list['page'] - 1, $active_tab, $course_filter)); ?>">Previous page</a>
      <?php endif; ?>
      <span>Page <?php echo (int) $approval_list['page']; ?> of <?php echo (int) $approval_list['total_pages']; ?></span>
      <?php if ($approval_list['page'] < $approval_list['total_pages']): ?>
        <a class="approval-button" rel="next" href="<?php echo rms_approval_escape(rms_approval_url($approval_list['search'], $approval_list['page'] + 1, $active_tab, $course_filter)); ?>">Next page</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
