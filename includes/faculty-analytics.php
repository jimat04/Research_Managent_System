<?php
/**
 * Faculty portfolio analytics workspace.
 *
 * @var array $analytics
 * @var array $filters
 */
$facultyAnalyticsEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$facultyAnalyticsFilters = is_array($filters ?? null) ? $filters : [];
$facultyAnalyticsKpis = is_array($analytics['kpis'] ?? null) ? $analytics['kpis'] : [];
$facultyAnalyticsSections = is_array($analytics['sections'] ?? null) ? $analytics['sections'] : [];
$facultyAnalyticsErrors = is_array($filterErrors ?? null) ? $filterErrors : [];
$facultyAnalyticsGroups = [
    'projects' => ['title' => 'Advised projects', 'from' => 'projects_from', 'to' => 'projects_to'],
    'publications' => ['title' => 'Publications', 'from' => 'publications_from', 'to' => 'publications_to'],
    'engagements' => ['title' => 'Engagement records', 'from' => 'engagements_from', 'to' => 'engagements_to'],
    'participation' => ['title' => 'Event participation', 'from' => 'participation_from', 'to' => 'participation_to'],
    'records' => ['title' => 'My research records', 'from' => 'records_from', 'to' => 'records_to'],
    'ip' => ['title' => 'Copyright and IP records', 'from' => 'ip_from', 'to' => 'ip_to'],
];
$facultyAcademicYearOptions = is_array($analytics['academic_year_options'] ?? null)
    ? $analytics['academic_year_options']
    : [];
?>
<section class="faculty-analytics" data-faculty-analytics aria-labelledby="faculty-analytics-heading">
    <header class="faculty-analytics__intro">
        <div>
            <p class="faculty-analytics__eyebrow">Faculty portfolio</p>
            <h2 id="faculty-analytics-heading">Research analytics</h2>
            <p>Review your advising work, scholarly outputs, research records, and verified participation in one place.</p>
        </div>
        <a class="faculty-analytics__back" href="<?= $facultyAnalyticsEscape(SITE_URL . 'pages/faculty/faculty-dashboard.php') ?>">Back to dashboard</a>
    </header>

    <form class="faculty-analytics__filters" method="get" aria-labelledby="faculty-analytics-filter-heading">
        <div class="faculty-analytics__filters-head">
            <div>
                <h3 id="faculty-analytics-filter-heading">Filter your portfolio</h3>
                <p>Choose separate periods for each area. Project academic year is independent of its date range.</p>
            </div>
            <a class="faculty-analytics__reset" href="<?= $facultyAnalyticsEscape(SITE_URL . 'pages/faculty/faculty-analytics.php') ?>">Reset filters</a>
        </div>
        <div class="faculty-analytics__filter-grid">
            <?php foreach ($facultyAnalyticsGroups as $groupKey => $group): ?>
                <fieldset class="faculty-analytics__filter-group">
                    <legend><?= $facultyAnalyticsEscape($group['title']) ?></legend>
                    <label>
                        <span>From</span>
                        <input type="date" name="<?= $facultyAnalyticsEscape($group['from']) ?>" value="<?= $facultyAnalyticsEscape($facultyAnalyticsFilters[$group['from']] ?? '') ?>">
                    </label>
                    <label>
                        <span>To</span>
                        <input type="date" name="<?= $facultyAnalyticsEscape($group['to']) ?>" value="<?= $facultyAnalyticsEscape($facultyAnalyticsFilters[$group['to']] ?? '') ?>">
                    </label>
                    <?php if ($groupKey === 'projects'): ?>
                        <label class="faculty-analytics__academic-year">
                            <span>Academic year</span>
                            <?php if ($facultyAcademicYearOptions): ?>
                                <select name="academic_year">
                                    <option value="">All academic years</option>
                                    <?php foreach ($facultyAcademicYearOptions as $academicYear): ?>
                                        <option value="<?= $facultyAnalyticsEscape($academicYear['value'] ?? '') ?>"<?= (string) ($facultyAnalyticsFilters['academic_year'] ?? '') === (string) ($academicYear['value'] ?? '') ? ' selected' : '' ?>><?= $facultyAnalyticsEscape($academicYear['label'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="academic_year" maxlength="9" pattern="[0-9]{4}-[0-9]{4}" value="<?= $facultyAnalyticsEscape($facultyAnalyticsFilters['academic_year'] ?? '') ?>" placeholder="e.g. 2025-2026">
                            <?php endif; ?>
                        </label>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <div class="faculty-analytics__filter-actions">
            <button class="faculty-analytics__button faculty-analytics__button--primary" type="submit">Apply filters</button>
            <button class="faculty-analytics__button" type="submit" name="export" value="csv">Download filtered CSV</button>
        </div>
        <?php if ($facultyAnalyticsErrors): ?>
            <div class="faculty-analytics__filter-errors" role="alert" aria-label="Filter errors">
                <strong>Check your filters</strong>
                <ul><?php foreach ($facultyAnalyticsErrors as $error): ?><li><?= $facultyAnalyticsEscape($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($facultyAnalyticsKpis): ?>
        <section class="faculty-analytics__summary" aria-label="Portfolio summary">
            <?php foreach ($facultyAnalyticsKpis as $index => $kpi): ?>
                <article class="faculty-analytics__kpi<?= $index === 0 ? ' faculty-analytics__kpi--featured' : '' ?>">
                    <p><?= $facultyAnalyticsEscape($kpi['label'] ?? 'Portfolio total') ?></p>
                    <strong><?= $facultyAnalyticsEscape($kpi['value'] ?? 0) ?></strong>
                    <?php if (!empty($kpi['detail'])): ?><span><?= $facultyAnalyticsEscape($kpi['detail']) ?></span><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!$facultyAnalyticsSections): ?>
        <div class="faculty-analytics__empty" role="status">
            <strong>No analytics are available yet.</strong>
            <p>Portfolio summaries will appear here when records are available for your account.</p>
        </div>
    <?php else: ?>
        <div class="faculty-analytics__section-grid">
            <?php foreach ($facultyAnalyticsSections as $sectionIndex => $section): ?>
                <?php
                    $sectionKey = preg_replace('/[^a-z0-9_-]/i', '-', (string) ($section['key'] ?? 'portfolio-' . $sectionIndex));
                    $sectionRows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
                    $sectionColumns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
                    $chartData = is_array($section['chart_data'] ?? null) ? $section['chart_data'] : [];
                    $sectionAvailable = (bool) ($section['available'] ?? true);
                    $tableId = 'faculty-analytics-table-' . $sectionKey;
                    $chartId = 'faculty-analytics-chart-' . $sectionKey;
                ?>
                <article class="faculty-analytics__section<?= $sectionIndex === 0 ? ' faculty-analytics__section--wide' : '' ?>" aria-labelledby="<?= $facultyAnalyticsEscape($sectionKey) ?>-title">
                    <header class="faculty-analytics__section-head">
                        <div>
                            <h3 id="<?= $facultyAnalyticsEscape($sectionKey) ?>-title"><?= $facultyAnalyticsEscape($section['title'] ?? 'Portfolio analytics') ?></h3>
                            <?php if (!empty($section['description'])): ?><p><?= $facultyAnalyticsEscape($section['description']) ?></p><?php endif; ?>
                        </div>
                        <?php if (!empty($section['href'])): ?>
                            <a href="<?= $facultyAnalyticsEscape($section['href']) ?>">View records<span aria-hidden="true"> →</span></a>
                        <?php endif; ?>
                    </header>

                    <?php if ($sectionAvailable && $chartData): ?>
                        <div class="faculty-analytics__chart-wrap">
                            <canvas id="<?= $facultyAnalyticsEscape($chartId) ?>" data-chart-data="<?= $facultyAnalyticsEscape(json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]') ?>" role="img" aria-label="Chart: <?= $facultyAnalyticsEscape($section['title'] ?? 'Portfolio analytics') ?>" aria-describedby="<?= $facultyAnalyticsEscape($tableId) ?>-caption"></canvas>
                        </div>
                    <?php endif; ?>

                    <details class="faculty-analytics__data" open>
                        <summary>View data table</summary>
                        <div class="faculty-analytics__table-scroll" role="region" aria-label="<?= $facultyAnalyticsEscape($section['title'] ?? 'Portfolio analytics') ?> data" tabindex="0">
                            <table id="<?= $facultyAnalyticsEscape($tableId) ?>">
                                <caption id="<?= $facultyAnalyticsEscape($tableId) ?>-caption"><?= $facultyAnalyticsEscape($section['title'] ?? 'Portfolio analytics') ?></caption>
                                <thead><tr><?php foreach ($sectionColumns as $column): ?><th scope="col"><?= $facultyAnalyticsEscape($column['label'] ?? '') ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                    <?php if (!$sectionAvailable): ?>
                                        <tr><td colspan="<?= max(1, count($sectionColumns)) ?>">This data source is unavailable in the current installation.</td></tr>
                                    <?php elseif (!$sectionRows): ?>
                                        <tr><td colspan="<?= max(1, count($sectionColumns)) ?>">No records in this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($sectionRows as $row): ?>
                                            <tr><?php foreach ($sectionColumns as $column): ?><td><?= $facultyAnalyticsEscape($row[$column['key'] ?? ''] ?? '—') ?></td><?php endforeach; ?></tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
