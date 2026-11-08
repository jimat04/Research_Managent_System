<?php
// Included from the authenticated, permission-checked research approval page.
if (!isset($research_calendar) || !is_array($research_calendar)) {
    return;
}
$calendar = $research_calendar;
$layout = $calendar['layout'];
$format_date = static function (string $value): string {
    $timestamp = $value !== '' ? strtotime($value) : false;
    return $timestamp === false ? '' : date('M j, Y', $timestamp);
};
$format_datetime = static function (string $value): string {
    $timestamp = $value !== '' ? strtotime($value) : false;
    return $timestamp === false ? '' : date(DATE_ATOM, $timestamp);
};
$status_label = static function (string $status): string {
    return [
        'approved' => 'Approved',
        'submitted' => 'Awaiting review',
        'returned' => 'Returned for revision',
        'not_started' => 'Not started',
    ][$status] ?? 'Status unavailable';
};
$status_class = static function (string $status): string {
    return [
        'approved' => 'is-approved',
        'submitted' => 'is-submitted',
        'returned' => 'is-returned',
        'not_started' => 'is-not-started',
    ][$status] ?? 'is-not-started';
};
$milestone_dates = static function (array $milestone) use ($format_date): string {
    if ($milestone['submitted_at'] === '') {
        return 'No submission recorded';
    }
    $caption = 'Submitted ' . $format_date($milestone['submitted_at']);
    if ($milestone['reviewed_at'] !== '') {
        $caption .= ' · ' . ($milestone['decision'] === 'approved' ? 'Approved ' : 'Returned ')
            . $format_date($milestone['reviewed_at']);
    } elseif ($milestone['status'] === 'submitted') {
        $caption .= ' · In review';
    }
    return $caption;
};
$build_timeline = static function (array $milestones): array {
    $now = new DateTimeImmutable('now');
    $events = [];
    foreach ($milestones as $index => $milestone) {
        if ($milestone['submitted_at'] === '') {
            continue;
        }
        $start = strtotime($milestone['submitted_at']);
        if ($start === false) {
            continue;
        }
        $reviewed = $milestone['reviewed_at'] !== '' ? strtotime($milestone['reviewed_at']) : false;
        $end = $reviewed !== false && $reviewed >= $start
            ? $reviewed
            : ($milestone['status'] === 'submitted' ? $now->getTimestamp() : $start);
        $events[$index] = ['start' => $start, 'end' => $end];
    }
    if (!$events) {
        return ['months' => [], 'events' => []];
    }
    $timestamps = [];
    foreach ($events as $event) {
        $timestamps[] = $event['start'];
        $timestamps[] = $event['end'];
    }
    $minimum = min($timestamps);
    $maximum = max($timestamps);
    $first_month = (new DateTimeImmutable('@' . $minimum))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify('first day of this month')->modify('-2 months')->setTime(0, 0);
    $last_month = (new DateTimeImmutable('@' . $maximum))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify('first day of this month')->modify('+2 months')->setTime(0, 0);
    $months = [];
    $cursor = $first_month;
    while ($cursor <= $last_month && count($months) < 120) {
        $months[] = $cursor;
        $cursor = $cursor->modify('+1 month');
    }
    if (!$months) {
        return ['months' => [], 'events' => []];
    }
    $axis_start = $first_month->getTimestamp();
    $axis_end = $cursor->getTimestamp();
    $axis_span = max(1, $axis_end - $axis_start);
    foreach ($events as &$event) {
        $left = (($event['start'] - $axis_start) / $axis_span) * 100;
        $right = (($event['end'] - $axis_start) / $axis_span) * 100;
        $event['left'] = max(0, min(100, $left));
        $event['width'] = max(1.4, min(100 - $event['left'], $right - $left));
    }
    unset($event);
    return ['months' => $months, 'events' => $events];
};
?>
<section class="research-calendar approval-list" aria-labelledby="research-calendar-heading">
  <form class="approval-search research-calendar-filters" method="get" action="<?php echo SITE_URL; ?>pages/shared/research-submission-review.php" role="search">
    <input type="hidden" name="module_page" value="research_approval">
    <input type="hidden" name="view" value="research_calendar">
    <input type="hidden" name="layout" value="<?php echo rms_research_calendar_escape($layout); ?>">
    <div class="approval-filter-heading">
      <h2 id="research-calendar-heading">Find research progress</h2>
      <p>Search by student or project, then narrow results by course, academic year, or current status.</p>
    </div>
    <div class="research-calendar-filter-grid">
      <div class="approval-filter-field">
        <label for="calendar-search">Student or project title</label>
        <input id="calendar-search" type="search" name="q" maxlength="200" placeholder="Search students or projects" value="<?php echo rms_research_calendar_escape($calendar['search']); ?>">
      </div>
      <div class="approval-filter-field">
        <label for="calendar-course">Course</label>
        <select id="calendar-course" name="course">
          <option value="">All courses</option>
          <?php foreach (['BSIT', 'BSCS'] as $course): ?>
            <option value="<?php echo $course; ?>"<?php echo $calendar['course'] === $course ? ' selected' : ''; ?>><?php echo $course; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="approval-filter-field">
        <label for="calendar-year">Academic year</label>
        <select id="calendar-year" name="ay_label">
          <option value="">All academic years</option>
          <?php foreach ($calendar['academic_years'] as $year): ?>
            <option value="<?php echo rms_research_calendar_escape($year); ?>"<?php echo $calendar['year_label'] === $year ? ' selected' : ''; ?>><?php echo rms_research_calendar_escape($year); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="approval-filter-field">
        <label for="calendar-status">Current status</label>
        <select id="calendar-status" name="progress_status">
          <option value="">All statuses</option>
          <?php foreach ($calendar['status_options'] as $status_value => $status_text): ?>
            <option value="<?php echo rms_research_calendar_escape($status_value); ?>"<?php echo $calendar['status'] === $status_value ? ' selected' : ''; ?>><?php echo rms_research_calendar_escape($status_text); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="approval-button approval-button-primary" type="submit">Apply filters</button>
      <?php if ($calendar['search'] !== '' || $calendar['course'] !== '' || $calendar['year_label'] !== '' || $calendar['status'] !== ''): ?>
        <a class="approval-button" href="<?php echo rms_research_calendar_escape(rms_research_calendar_url(['search' => '', 'course' => '', 'year_label' => '', 'status' => '', 'layout' => $layout], $layout)); ?>">Clear filters</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="research-calendar-toolbar">
    <p class="approval-results" role="status">
      <?php if ($calendar['total'] > 0): ?>
        Showing <?php echo (int) $calendar['offset'] + 1; ?>–<?php echo min($calendar['offset'] + $calendar['per_page'], $calendar['total']); ?> of <?php echo (int) $calendar['total']; ?> projects with an approved title.
      <?php else: ?>
        No research projects match the current filters.
      <?php endif; ?>
    </p>
    <nav class="research-calendar-view-toggle" aria-label="Calendar display">
      <a class="approval-button<?php echo $layout === 'timeline' ? ' approval-button-primary' : ''; ?>" href="<?php echo rms_research_calendar_escape(rms_research_calendar_url($calendar, 'timeline')); ?>"<?php echo $layout === 'timeline' ? ' aria-current="page"' : ''; ?>>Timeline</a>
      <a class="approval-button<?php echo $layout === 'agenda' ? ' approval-button-primary' : ''; ?>" href="<?php echo rms_research_calendar_escape(rms_research_calendar_url($calendar, 'agenda')); ?>"<?php echo $layout === 'agenda' ? ' aria-current="page"' : ''; ?>>Agenda</a>
    </nav>
  </div>

  <?php if (!$calendar['projects']): ?>
    <div class="approval-empty" role="status">
      <h2>No matching research projects</h2>
      <p>Adjust or clear the search and filters to see projects with approved titles.</p>
    </div>
  <?php elseif ($layout === 'agenda'): ?>
    <div class="research-calendar-projects">
      <?php foreach ($calendar['projects'] as $project): ?>
        <?php $approved_count = count(array_filter($project['milestones'], static fn (array $milestone): bool => $milestone['status'] === 'approved')); ?>
        <article class="research-calendar-project" aria-labelledby="calendar-project-<?php echo (int) $project['project_id']; ?>">
          <header class="research-calendar-project-header">
            <div>
              <h2 id="calendar-project-<?php echo (int) $project['project_id']; ?>"><?php echo rms_research_calendar_escape((string) $project['title']); ?></h2>
              <p><?php echo rms_research_calendar_escape((string) $project['student_name']); ?><?php if (trim((string) $project['student_program']) !== ''): ?> · <?php echo rms_research_calendar_escape(trim((string) $project['student_program'])); ?><?php endif; ?><?php if (!empty($project['academic_year'])): ?> · AY <?php echo rms_research_calendar_escape((string) $project['academic_year']); ?><?php endif; ?></p>
            </div>
            <div class="research-calendar-overview">
              <div class="research-calendar-progress-copy"><span>Workflow milestones</span><strong><?php echo $approved_count; ?> of 7 approved</strong></div>
              <div class="research-calendar-progress-rail" role="progressbar" aria-label="<?php echo $approved_count; ?> of 7 workflow milestones approved" aria-valuemin="0" aria-valuemax="7" aria-valuenow="<?php echo $approved_count; ?>">
                <?php foreach ($project['milestones'] as $milestone): ?><span class="<?php echo $milestone['status'] === 'approved' ? 'is-approved' : ''; ?>" aria-hidden="true"></span><?php endforeach; ?>
              </div>
              <span class="research-calendar-current"><?php echo rms_research_calendar_escape((string) $project['current_progress']); ?></span>
            </div>
          </header>
          <ol class="research-calendar-agenda">
            <?php foreach ($project['milestones'] as $milestone): ?>
              <?php $milestone_class = $status_class($milestone['status']); ?>
              <li class="research-calendar-agenda-item <?php echo $milestone_class; ?>">
                <span class="research-calendar-agenda-mark" aria-hidden="true"></span>
                <div class="research-calendar-agenda-content">
                  <h3><?php echo rms_research_calendar_escape((string) $milestone['label']); ?> <span class="research-calendar-status"><?php echo rms_research_calendar_escape($status_label($milestone['status'])); ?></span></h3>
                  <?php if ($milestone['submitted_at'] !== ''): ?>
                    <p>Submitted <time datetime="<?php echo rms_research_calendar_escape($format_datetime($milestone['submitted_at'])); ?>"><?php echo rms_research_calendar_escape($format_date($milestone['submitted_at'])); ?></time><?php if ($milestone['reviewed_at'] !== ''): ?> · <?php echo $milestone['decision'] === 'approved' ? 'Approved' : 'Returned'; ?> <time datetime="<?php echo rms_research_calendar_escape($format_datetime($milestone['reviewed_at'])); ?>"><?php echo rms_research_calendar_escape($format_date($milestone['reviewed_at'])); ?></time><?php endif; ?></p>
                  <?php else: ?>
                    <p>No submission recorded yet.</p>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
          <a class="research-calendar-history" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>#research-trail">Open research history</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="research-calendar-projects research-calendar-timelines">
      <?php foreach ($calendar['projects'] as $project): ?>
        <?php $timeline = $build_timeline($project['milestones']); ?>
        <?php $approved_count = count(array_filter($project['milestones'], static fn (array $milestone): bool => $milestone['status'] === 'approved')); ?>
        <article class="research-calendar-project" aria-labelledby="calendar-project-<?php echo (int) $project['project_id']; ?>">
          <header class="research-calendar-project-header">
            <div>
              <h2 id="calendar-project-<?php echo (int) $project['project_id']; ?>"><?php echo rms_research_calendar_escape((string) $project['title']); ?></h2>
              <p><?php echo rms_research_calendar_escape((string) $project['student_name']); ?><?php if (trim((string) $project['student_program']) !== ''): ?> · <?php echo rms_research_calendar_escape(trim((string) $project['student_program'])); ?><?php endif; ?><?php if (!empty($project['academic_year'])): ?> · AY <?php echo rms_research_calendar_escape((string) $project['academic_year']); ?><?php endif; ?></p>
            </div>
            <div class="research-calendar-overview">
              <div class="research-calendar-progress-copy"><span>Workflow milestones</span><strong><?php echo $approved_count; ?> of 7 approved</strong></div>
              <div class="research-calendar-progress-rail" role="progressbar" aria-label="<?php echo $approved_count; ?> of 7 workflow milestones approved" aria-valuemin="0" aria-valuemax="7" aria-valuenow="<?php echo $approved_count; ?>">
                <?php foreach ($project['milestones'] as $milestone): ?><span class="<?php echo $milestone['status'] === 'approved' ? 'is-approved' : ''; ?>" aria-hidden="true"></span><?php endforeach; ?>
              </div>
              <span class="research-calendar-current"><?php echo rms_research_calendar_escape((string) $project['current_progress']); ?></span>
            </div>
          </header>
          <div class="research-calendar-legend" aria-label="Timeline status legend">
            <span class="is-approved">Approved</span><span class="is-submitted">Awaiting review</span><span class="is-returned">Returned</span><span class="is-not-started">Not started</span>
          </div>
          <div class="research-calendar-scroll" role="region" aria-label="Monthly research timeline for <?php echo rms_research_calendar_escape((string) $project['title']); ?>" tabindex="0">
            <?php if ($timeline['months']): ?>
              <div class="research-calendar-axis">
                <span>Research stage</span>
                <div class="research-calendar-months" style="grid-template-columns:repeat(<?php echo count($timeline['months']); ?>, minmax(68px, 1fr))">
                  <?php foreach ($timeline['months'] as $month): ?><span><?php echo rms_research_calendar_escape($month->format('M Y')); ?></span><?php endforeach; ?>
                </div>
              </div>
              <?php foreach ($project['milestones'] as $index => $milestone): ?>
                <?php $milestone_class = $status_class($milestone['status']); ?>
                <div class="research-calendar-timeline-row <?php echo $milestone_class; ?>">
                  <div class="research-calendar-stage-label">
                    <strong><?php echo rms_research_calendar_escape((string) $milestone['label']); ?></strong>
                    <span><?php echo rms_research_calendar_escape($status_label($milestone['status'])); ?></span>
                    <small><?php echo rms_research_calendar_escape($milestone_dates($milestone)); ?></small>
                  </div>
                  <div class="research-calendar-track" style="--month-count:<?php echo count($timeline['months']); ?>">
                    <div class="research-calendar-grid" style="grid-template-columns:repeat(<?php echo count($timeline['months']); ?>, minmax(68px, 1fr))" aria-hidden="true">
                      <?php foreach ($timeline['months'] as $_month): ?><span></span><?php endforeach; ?>
                    </div>
                    <?php if (isset($timeline['events'][$index])): ?>
                      <?php
                        $event = $timeline['events'][$index];
                        $left = number_format($event['left'], 3, '.', '');
                        $width = number_format($event['width'], 3, '.', '');
                      ?>
                      <span class="research-calendar-event <?php echo $milestone_class; ?>" style="left:<?php echo $left; ?>%;width:<?php echo $width; ?>%" aria-hidden="true"></span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p>Milestone dates are unavailable.</p>
            <?php endif; ?>
          </div>
          <a class="research-calendar-history" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>#research-trail">Open research history</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($calendar['total_pages'] > 1): ?>
    <nav class="approval-pagination" aria-label="Research calendar pages">
      <?php if ($calendar['page'] > 1): ?>
        <a class="approval-button" rel="prev" href="<?php echo rms_research_calendar_escape(rms_research_calendar_url($calendar, $layout, $calendar['page'] - 1)); ?>">Previous page</a>
      <?php endif; ?>
      <span>Page <?php echo (int) $calendar['page']; ?> of <?php echo (int) $calendar['total_pages']; ?></span>
      <?php if ($calendar['page'] < $calendar['total_pages']): ?>
        <a class="approval-button" rel="next" href="<?php echo rms_research_calendar_escape(rms_research_calendar_url($calendar, $layout, $calendar['page'] + 1)); ?>">Next page</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
