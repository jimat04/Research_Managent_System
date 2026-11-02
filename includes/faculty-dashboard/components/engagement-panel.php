<?php
/**
 * Engagement Panel Component
 *
 * Shows recent engagement records with status badges and quick action.
 *
 * @var array $engagements Recent engagement records (max 3) from FacultyEngagementService
 * @var string $engagementUrl URL for "View All" link
 * @var string $addEngagementUrl URL for "+ Add Engagement" button
 */
?>
<div class="faculty-panel">
    <div class="faculty-panel-head">
        <h3>Recent Engagements</h3>
        <a href="<?= htmlspecialchars($engagementUrl) ?>" class="faculty-panel-link">View All →</a>
    </div>

    <?php if (empty($engagements)): ?>
        <div class="faculty-empty-state">
            <svg class="faculty-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p>No engagement records yet.</p>
            <a href="<?= htmlspecialchars($addEngagementUrl) ?>" class="faculty-btn faculty-btn--primary faculty-btn--sm">
                + Add Engagement
            </a>
        </div>
    <?php else: ?>
        <ul class="faculty-track-list">
            <?php foreach ($engagements as $engagement): ?>
                <?php
                // Map engagement_type to icon key
                $type = $engagement['engagement_type'] ?? 'generic';
                $iconKey = match($type) {
                    'conference_presentation' => 'conference',
                    'seminar_training' => 'conference',
                    'extension_community' => 'extension',
                    'research_award' => 'research',
                    'collaboration' => 'research',
                    default => 'generic',
                };

                $status = $engagement['review_status'] ?? 'pending';
                $statusLabels = [
                    'verified' => '✅ Verified',
                    'pending' => '⏳ Pending Review',
                    'returned' => '⚠️ Returned - Revise',
                    'rejected' => '❌ Rejected',
                ];
                $statusClass = $engagement['review_status_class'] ?? 'neutral';
                ?>
                <li class="faculty-track-item">
                    <div class="faculty-track-icon faculty-track-icon--<?= $iconKey ?>">
                        <?php
                        $icons = [
                            'conference' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />' .
                                           '<polyline points="17 8 12 3 7 8" />' .
                                           '<line x1="12" y1="3" x2="12" y2="15" />',
                            'publication' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />' .
                                            '<polyline points="14 2 14 8 20 8" />' .
                                            '<line x1="16" y1="13" x2="8" y2="13" />' .
                                            '<line x1="16" y1="17" x2="8" y2="17" />' .
                                            '<polyline points="10 9 9 9 8 9" />',
                            'extension' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />',
                            'research' => '<circle cx="12" cy="12" r="10" />' .
                                         '<line x1="12" y1="16" x2="12" y2="12" />' .
                                         '<line x1="12" y1="8" x2="12.01" y2="8" />',
                            'generic' => '<rect x="2" y="3" width="20" height="14" rx="2" />' .
                                        '<path d="M8 21h8" />' .
                                        '<path d="M12 17v4" />',
                        ];
                        echo $icons[$iconKey] ?? $icons['generic'];
                        ?>
                    </div>
                    <div class="faculty-track-content">
                        <div class="faculty-track-title"><?= htmlspecialchars($engagement['title'] ?? 'Untitled Engagement') ?></div>
                        <div class="faculty-track-meta">
                            <span><?= htmlspecialchars($engagement['engagement_type_label'] ?? ucfirst(str_replace('_', ' ', $type))) ?></span>
                            <span>•</span>
                            <time datetime="<?= htmlspecialchars($engagement['activity_date'] ?? '') ?>">
                                <?= date('M j, Y', strtotime($engagement['activity_date'] ?? '')) ?>
                            </time>
                        </div>
                    </div>
                    <div class="faculty-track-status">
                        <span class="faculty-status-badge faculty-status-badge--<?= $statusClass ?>">
                            <?= $statusLabels[$status] ?? 'Pending' ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="faculty-panel-action">
        <a href="<?= htmlspecialchars($addEngagementUrl) ?>" class="faculty-btn faculty-btn--secondary faculty-btn--sm">
            + Add Engagement
        </a>
    </div>
</div>