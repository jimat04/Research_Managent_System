<?php
/**
 * Review Queue Component
 *
 * Unified review queue showing all items awaiting faculty review:
 * - Chapter submissions
 * - Title proposals
 * - Final manuscripts
 * - Returned engagement records
 *
 * @var array $reviewQueue Review queue items (max 5)
 * @var string $reviewUrl URL for the faculty advising overview
 * @var int $totalCount Total count of pending reviews
 */

if (!isset($reviewQueue) || !is_array($reviewQueue)) {
    $reviewQueue = [];
}
$reviewUrl = $reviewUrl ?? (SITE_URL . 'pages/faculty/faculty-advising.php');
$totalCount = $totalCount ?? count($reviewQueue);
$items = array_slice($reviewQueue, 0, 5); // Show max 5 on dashboard
?>

<div class="faculty-panel faculty-review-queue">
    <div class="faculty-review-queue-head">
        <h3>Review Queue</h3>
        <?php if ($totalCount > 0): ?>
            <a class="faculty-panel-link" href="<?= htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8') ?>">
                View Advising →
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($items)): ?>
        <div class="faculty-empty-state">
            <svg class="faculty-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p>No pending reviews. All caught up! 🎉</p>
            <a href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-advising.php', ENT_QUOTES, 'UTF-8') ?>" class="faculty-btn faculty-btn--secondary faculty-btn--sm">
                View Advisees
            </a>
        </div>
    <?php else: ?>
        <div class="faculty-review-list" role="list" aria-label="Pending reviews">
            <?php foreach ($items as $item): ?>
                <article class="faculty-review-item"
                    data-review-id="<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-review-type="<?= htmlspecialchars($item['type'] ?? 'chapter', ENT_QUOTES, 'UTF-8') ?>">
                    <div class="faculty-review-main">
                        <div class="faculty-review-type">
                            <svg class="faculty-review-type-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <?php
                                $typeIcons = [
                                    'chapter' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" />',
                                    'title' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />',
                                    'final' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /><line x1="10" y1="9" x2="8" y2="9" />',
                                    'engagement' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />',
                                ];
                                echo $typeIcons[$item['type'] ?? 'chapter'] ?? $typeIcons['chapter'];
                                ?>
                            </svg>
                            <span><?= htmlspecialchars(ucfirst($item['type'] ?? 'chapter')) ?></span>
                        </div>
                        <div class="faculty-review-title"><?= htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="faculty-review-context">
                            <span>Project: <?= htmlspecialchars($item['project_title'] ?? 'Unknown Project', ENT_QUOTES, 'UTF-8') ?></span>
                            <span>•</span>
                            <span><?= htmlspecialchars($item['student_name'] ?? 'Unknown Student', ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($item['student_email'])): ?>
                                <span><<?= htmlspecialchars($item['student_email'], ENT_QUOTES, 'UTF-8') ?>></span>
                            <?php endif; ?>
                        </div>
                        <div class="faculty-review-actions">
                            <a class="faculty-btn faculty-btn--primary faculty-btn--sm"
                                href="<?= htmlspecialchars($item['action_url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($item['action_label'] ?? 'Review', ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php if (!empty($item['secondary_action_url'])): ?>
                                <a class="faculty-btn faculty-btn--secondary faculty-btn--sm"
                                    href="<?= htmlspecialchars($item['secondary_action_url'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($item['secondary_action_label'] ?? 'View', ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="faculty-review-side">
                        <div class="faculty-review-student"><?= htmlspecialchars($item['student_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="faculty-review-time">
                            <time datetime="<?= htmlspecialchars(date('c', strtotime($item['submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                Submitted <?= htmlspecialchars($item['time_ago'] ?? 'recently', ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </div>
                        <?php if (!empty($item['attachment_count'])): ?>
                            <span class="faculty-review-badge faculty-review-badge--attachment">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <?= (int)$item['attachment_count'] ?> file<?= (int)$item['attachment_count'] > 1 ? 's' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if (($item['status'] ?? '') === 'returned'): ?>
                            <span class="faculty-review-badge faculty-review-badge--returned">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/></svg>
                                Returned — Revise
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($item['admin_comment'])): ?>
                            <span class="faculty-review-badge faculty-review-badge--comment">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                Admin comment
                            </span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
