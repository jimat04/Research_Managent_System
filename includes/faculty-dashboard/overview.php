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

    <!-- Hero Section: Stats + Quick Actions -->
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
            <?php if (!$data['participationSummary']['available']): ?>
                <a class="faculty-stat-link faculty-stat-link--records" href="<?= htmlspecialchars($urls['researchRecords'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your active research records">
                    <article class="faculty-stat-card <?= $data['researchRecordCount'] === null ? 'faculty-stat-card--neutral' : 'faculty-stat-card--secondary' ?>">
                        <span class="faculty-stat-value"><?= $data['researchRecordCount'] === null ? '—' : (int) $data['researchRecordCount'] ?></span>
                        <span class="faculty-stat-label">Active Research Records</span>
                        <span class="faculty-stat-subtext"><?= $data['researchRecordCount'] === null ? 'Records are unavailable' : 'Ready to reuse in research documents' ?></span>
                    </article>
                </a>
                <a class="faculty-stat-link faculty-stat-link--activities" href="<?= htmlspecialchars($urls['engagements'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your engagement history for activities participated">
                    <article class="faculty-stat-card faculty-stat-card--neutral">
                        <span class="faculty-stat-value">—</span>
                        <span class="faculty-stat-label">Activities Participated</span>
                        <span class="faculty-stat-subtext">Participation records unavailable</span>
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
                <a class="faculty-stat-link faculty-stat-link--activities" href="<?= htmlspecialchars($urls['engagements'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View your engagement history for activities participated">
                    <article class="faculty-stat-card faculty-stat-card--secondary">
                        <span class="faculty-stat-value"><?= (int) $data['participationSummary']['activity_count'] ?></span>
                        <span class="faculty-stat-label">Activities Participated</span>
                        <span class="faculty-stat-subtext">Unique events attended or presented</span>
                    </article>
                </a>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="faculty-quick-actions">
            <a href="<?= htmlspecialchars($urls['addEngagement']) ?>" class="faculty-btn faculty-btn--primary faculty-quick-action-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                <span>Add Engagement</span>
            </a>
            <a href="<?= htmlspecialchars($urls['profile'] . '?tab=research') ?>" class="faculty-btn faculty-btn--secondary faculty-quick-action-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                <span>Update Profile</span>
            </a>
            <a href="<?= htmlspecialchars($urls['advising']) ?>" class="faculty-btn faculty-btn--secondary faculty-quick-action-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <span>View All Advisees</span>
            </a>
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
