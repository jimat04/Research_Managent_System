<?php
/**
 * Faculty Dashboard Overview Template
 *
 * Main overview view composed from component partials.
 *
 * @var array $data Complete dashboard data from FacultyDashboardService
 * @var array $urls Navigation URLs
 */
?>
<div class="faculty-dashboard-overview">

    <!-- Dashboard Header and Stats -->
    <section class="faculty-dashboard-hero" aria-labelledby="dashboard-title">
        <div class="faculty-dashboard-hero-head">
            <div>
                <h1 id="dashboard-title" class="faculty-topbar-title">Faculty Workspace</h1>
            </div>
            <a href="<?= htmlspecialchars($urls['analytics']) ?>" class="faculty-btn faculty-btn--ghost faculty-analytics-link">
                Research Analytics
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="5" y1="12" x2="19" y2="12" />
                    <polyline points="12 5 19 12 12 19" />
                </svg>
            </a>
        </div>

        <!-- Stat Cards Grid -->
        <?php
        $statUrlKeys = [
            'Advising Projects' => 'advising',
            'Publication Records' => 'publications',
            'Verified Engagements' => 'engagements',
            'Registered IP Outputs' => 'registeredIp',
        ];
        $statsByLabel = array_column($data['stats'], null, 'label');
        $statOrder = array_values(array_filter([
            'Active Research Records',
            'Registered IP Outputs',
            'Publication Records',
            'Upcoming Activities',
            'Advising Projects',
            'Verified Engagements',
        ], static function (string $label) use ($statsByLabel): bool {
            return in_array($label, ['Active Research Records', 'Upcoming Activities'], true)
                || isset($statsByLabel[$label]);
        }));
        $statPosition = 0;
        ?>
        <div class="faculty-stats-grid<?= count($statOrder) === 6 ? ' faculty-stats-grid--six' : '' ?>" role="group" aria-label="Faculty dashboard metrics">
            <?php foreach ($statOrder as $label): ?>
                <?php
                $statPosition++;
                if (count($statOrder) === 6) {
                    $positionClass = match ($statPosition) {
                        4 => ' faculty-stat-link--fourth',
                        5 => ' faculty-stat-link--fifth',
                        6 => ' faculty-stat-link--sixth',
                        default => '',
                    };
                } else {
                    $positionClass = $statPosition === 4
                        ? ' faculty-stat-link--fourth'
                        : ($statPosition === 5 ? ' faculty-stat-link--fifth' : '');
                }
                ?>
                <?php if ($label === 'Active Research Records'): ?>
                    <a class="faculty-stat-link<?= $positionClass ?>" href="<?= htmlspecialchars($urls['researchRecords'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your active research records">
                        <article class="faculty-stat-card <?= $data['researchRecordCount'] === null ? 'faculty-stat-card--neutral' : 'faculty-stat-card--secondary' ?>">
                            <div class="faculty-stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="4" y="4" width="16" height="16" rx="2" />
                                    <path d="M8 8h8M8 12h8M8 16h5" />
                                </svg>
                            </div>
                            <div class="faculty-stat-content">
                                <div class="faculty-stat-value"><?= $data['researchRecordCount'] === null ? '—' : (int) $data['researchRecordCount'] ?></div>
                                <div class="faculty-stat-label">Active Research Records</div>
                                <div class="faculty-stat-subtext"><?= $data['researchRecordCount'] === null ? 'Records are unavailable' : 'Ready to reuse in research documents' ?></div>
                            </div>
                        </article>
                    </a>
                <?php elseif ($label === 'Upcoming Activities'): ?>
                    <a class="faculty-stat-link<?= $positionClass ?>" href="<?= htmlspecialchars($urls['activities'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View upcoming activities">
                        <article class="faculty-stat-card <?= $data['upcomingActivityCount'] === null ? 'faculty-stat-card--neutral' : 'faculty-stat-card--secondary' ?>">
                            <div class="faculty-stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="2" />
                                    <path d="M16 3v4M8 3v4M3 11h18M8 16l2 2 5-5" />
                                </svg>
                            </div>
                            <div class="faculty-stat-content">
                                <div class="faculty-stat-value"><?= $data['upcomingActivityCount'] === null ? '—' : (int) $data['upcomingActivityCount'] ?></div>
                                <div class="faculty-stat-label">Upcoming Activities</div>
                                <div class="faculty-stat-subtext"><?= $data['upcomingActivityCount'] === null ? 'Activities are unavailable' : 'Scheduled research activities' ?></div>
                            </div>
                        </article>
                    </a>
                <?php elseif (isset($statsByLabel[$label])): ?>
                    <?php
                    $stat = $statsByLabel[$label];
                    $statUrlKey = $statUrlKeys[$label] ?? null;
                    $statUrl = $statUrlKey !== null ? ($urls[$statUrlKey] ?? null) : null;
                    ?>
                    <?php if ($statUrl !== null): ?>
                        <a class="faculty-stat-link<?= $positionClass ?>" href="<?= htmlspecialchars($statUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <article class="faculty-stat-card faculty-stat-card--<?= $stat['tone'] ?>">
                        <div class="faculty-stat-icon" style="--stat-color: <?= $stat['color'] ?>">
                            <?= $stat['icon'] ?>
                        </div>
                        <div class="faculty-stat-content">
                            <div class="faculty-stat-value"><?= $stat['value'] ?></div>
                            <div class="faculty-stat-label"><?= htmlspecialchars($stat['label']) ?></div>
                            <?php if (!empty($stat['subtext'])): ?>
                                <div class="faculty-stat-subtext"><?= htmlspecialchars($stat['subtext']) ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php if ($statUrl !== null): ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

    </section>

    <!-- Advisee Progress Matrix -->
    <section class="faculty-primary-row" aria-labelledby="advisee-progress-heading">
        <h2 id="advisee-progress-heading" class="visually-hidden">Advisee Progress</h2>
        <div class="faculty-primary-col">
            <?php
            $adviseeMatrix = $data['adviseeMatrix'];
            $advisingUrl = $urls['advising'];
            include __DIR__ . '/components/advisee-matrix.php';
            ?>
        </div>
    </section>

    <!-- Secondary Row: Engagement + Profile + Publications -->
    <section class="faculty-secondary-row" aria-labelledby="secondary-row-heading">
        <h2 id="secondary-row-heading" class="visually-hidden">Secondary Dashboard Sections</h2>

        <div class="faculty-secondary-col">
            <?php
            $engagements = $data['recentEngagements'];
            $engagementUrl = $urls['engagements'];
            $addEngagementUrl = $urls['addEngagement'];
            include __DIR__ . '/components/engagement-panel.php';
            ?>
        </div>

        <div class="faculty-secondary-col">
            <?php
            $profile = $data['profileStatus'];
            $profileUrl = $urls['profile'];
            include __DIR__ . '/components/profile-panel.php';
            ?>
        </div>

        <div class="faculty-secondary-col">
            <?php
            $publications = $data['recentPublications'];
            $publicationsUrl = $urls['publications'];
            $addPublicationUrl = $urls['addPublication'];
            include __DIR__ . '/components/publications-panel.php';
            ?>
        </div>
    </section>

</div>
