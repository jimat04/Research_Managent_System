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
        <div class="faculty-stats-grid" role="group" aria-label="Faculty dashboard metrics">
            <?php
            $statUrlKeys = [
                'Advising Projects' => 'advising',
                'Publication Records' => 'publications',
                'Verified Engagements' => 'engagements',
            ];
            ?>
            <?php foreach ($data['stats'] as $key => $stat): ?>
                <?php
                $statUrlKey = $statUrlKeys[$stat['label']] ?? null;
                $statUrl = $statUrlKey !== null ? ($urls[$statUrlKey] ?? null) : null;
                ?>
                <?php if ($statUrl !== null): ?>
                    <a class="faculty-stat-link" href="<?= htmlspecialchars($statUrl, ENT_QUOTES, 'UTF-8') ?>">
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
            <?php endforeach; ?>
            <?php if ($data['upcomingActivityCount'] === null): ?>
                <a class="faculty-stat-link faculty-stat-link--records" href="<?= htmlspecialchars($urls['researchRecords'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your active research records">
                    <article class="faculty-stat-card <?= $data['researchRecordCount'] === null ? 'faculty-stat-card--neutral' : 'faculty-stat-card--secondary' ?>">
                        <span class="faculty-stat-value"><?= $data['researchRecordCount'] === null ? '—' : (int) $data['researchRecordCount'] ?></span>
                        <span class="faculty-stat-label">Active Research Records</span>
                        <span class="faculty-stat-subtext"><?= $data['researchRecordCount'] === null ? 'Records are unavailable' : 'Ready to reuse in research documents' ?></span>
                    </article>
                </a>
                <a class="faculty-stat-link faculty-stat-link--activities" href="<?= htmlspecialchars($urls['activities'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View upcoming activities">
                    <article class="faculty-stat-card faculty-stat-card--neutral">
                        <span class="faculty-stat-value">—</span>
                        <span class="faculty-stat-label">Upcoming Activities</span>
                        <span class="faculty-stat-subtext"><?= $data['upcomingActivityCount'] === null ? 'Activities are unavailable' : 'Scheduled research activities' ?></span>
                    </article>
                </a>
            <?php else: ?>
                <a class="faculty-stat-link faculty-stat-link--records" href="<?= htmlspecialchars($urls['researchRecords'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your active research records">
                    <article class="faculty-stat-card <?= $data['researchRecordCount'] === null ? 'faculty-stat-card--neutral' : 'faculty-stat-card--secondary' ?>">
                        <span class="faculty-stat-value"><?= $data['researchRecordCount'] === null ? '—' : (int) $data['researchRecordCount'] ?></span>
                        <span class="faculty-stat-label">Active Research Records</span>
                        <span class="faculty-stat-subtext"><?= $data['researchRecordCount'] === null ? 'Records are unavailable' : 'Ready to reuse in research documents' ?></span>
                    </article>
                </a>
                <a class="faculty-stat-link faculty-stat-link--activities" href="<?= htmlspecialchars($urls['activities'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View upcoming activities">
                    <article class="faculty-stat-card faculty-stat-card--secondary">
                        <span class="faculty-stat-value"><?= (int) $data['upcomingActivityCount'] ?></span>
                        <span class="faculty-stat-label">Upcoming Activities</span>
                        <span class="faculty-stat-subtext"><?= $data['upcomingActivityCount'] === null ? 'Activities are unavailable' : 'Scheduled research activities' ?></span>
                    </article>
                </a>
            <?php endif; ?>
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
