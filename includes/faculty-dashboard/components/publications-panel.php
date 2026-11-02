<?php
/**
 * Publications Panel Component
 *
 * Shows recent publications with status and quick action.
 *
 * @var array $publications Recent publications (max 3) from FacultyDashboardService
 * @var string $publicationsUrl URL for "View All" link
 * @var string $addPublicationUrl URL for "+ Add Publication" button
 */
?>
<div class="faculty-panel">
    <div class="faculty-panel-head">
        <h3>Recent Publications</h3>
        <a href="<?= htmlspecialchars($publicationsUrl) ?>" class="faculty-panel-link">View All →</a>
    </div>

    <?php if (empty($publications)): ?>
        <div class="faculty-empty-state">
            <svg class="faculty-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
            <p>No publications recorded yet.</p>
            <a href="<?= htmlspecialchars($addPublicationUrl) ?>" class="faculty-btn faculty-btn--primary faculty-btn--sm">
                + Add Publication
            </a>
        </div>
    <?php else: ?>
        <ul class="faculty-simple-list">
            <?php foreach ($publications as $pub): ?>
                <?php
                $title = $pub['research_title'] ?? $pub['title'] ?? $pub['publication_title'] ?? 'Untitled Publication';
                $venue = $pub['journal_reference'] ?? $pub['publication_type'] ?? $pub['venue'] ?? null;
                $year = null;
                if (!empty($pub['publication_date'])) {
                    $year = date('Y', strtotime($pub['publication_date']));
                } elseif (!empty($pub['year'])) {
                    $year = $pub['year'];
                }
                $status = $pub['status'] ?? 'published';
                $statusLabels = [
                    'archived' => 'Archived',
                    'legacy' => 'Legacy record',
                    'published' => '✅ Published',
                    'accepted' => '📝 Accepted',
                    'submitted' => '📤 Submitted',
                    'in_progress' => '✍️ In Progress',
                ];
                $statusClassMap = [
                    'published' => 'success',
                    'accepted' => 'warning',
                    'submitted' => 'review',
                    'in_progress' => 'neutral',
                ];
                $statusClass = $statusClassMap[$status] ?? 'success';
                ?>
                <li class="faculty-simple-item">
                    <div class="faculty-simple-content">
                        <div class="faculty-simple-title"><?= htmlspecialchars($title) ?></div>
                        <div class="faculty-simple-meta">
                            <?php if (!empty($venue)): ?>
                                <span><?= htmlspecialchars($venue) ?></span>
                                <span>•</span>
                            <?php endif; ?>
                            <?php if (!empty($year)): ?>
                                <span><?= htmlspecialchars($year) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="faculty-simple-status">
                        <span class="faculty-status-badge faculty-status-badge--<?= $statusClass ?>">
                            <?= $statusLabels[$status] ?? 'Published' ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="faculty-panel-action">
        <a href="<?= htmlspecialchars($addPublicationUrl) ?>" class="faculty-btn faculty-btn--secondary faculty-btn--sm">
            + Add Publication
        </a>
    </div>
</div>