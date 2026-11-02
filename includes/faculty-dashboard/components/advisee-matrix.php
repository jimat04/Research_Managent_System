<?php
/**
 * Advisee Progress Matrix Component
 *
 * Shows chapter completion status for each project the faculty advises.
 * Matrix layout: Research projects (rows) × Chapters 1-5 (columns)
 *
 * @var array $adviseeMatrix Matrix data from FacultyAdviseeProgressService
 * @var string $advisingUrl URL for "View All" link
 */

if (!isset($adviseeMatrix) || !is_array($adviseeMatrix)) {
    $adviseeMatrix = ['has_advisees' => false, 'projects' => [], 'chapters' => range(1, 5)];
}

$hasAdvisees = $adviseeMatrix['has_advisees'] ?? false;
$projects = $adviseeMatrix['projects'] ?? [];
$chapters = $adviseeMatrix['chapters'] ?? range(1, 5);
$totalProjects = (int) ($adviseeMatrix['total_projects'] ?? count($projects));
$currentPage = (int) ($adviseeMatrix['page'] ?? 1);
$pageSize = (int) ($adviseeMatrix['page_size'] ?? 10);
$totalPages = (int) ($adviseeMatrix['total_pages'] ?? 1);
$firstProject = $totalProjects > 0 ? (($currentPage - 1) * $pageSize) + 1 : 0;
$lastProject = min($totalProjects, $currentPage * $pageSize);
$startPage = max(1, $currentPage - 2);
$endPage = min($totalPages, $startPage + 4);
$startPage = max(1, $endPage - 4);
$pageUrl = static function (int $page): string {
    return SITE_URL . 'pages/faculty/faculty-dashboard.php?'
        . http_build_query(['advisee_page' => $page])
        . '#advisee-progress-heading';
};
$advisingUrl = $advisingUrl ?? (SITE_URL . 'pages/faculty/faculty-advising.php');

// Helper function for status icons
function getStatusIcon(string $status): string {
    return match($status) {
        'approved' => '✅',
        'submitted', 'under_review', 'revised' => '🟡',
        'revision_required' => '🟡',
        'rejected' => '❌',
        default => '⬜',
    };
}

function getStatusClass(string $status): string {
    return match($status) {
        'approved' => 'approved',
        'submitted', 'under_review', 'revised', 'revision_required' => 'in-review',
        'rejected' => 'not-started',
        default => 'not-started',
    };
}

function getStatusLabel(string $status): string {
    return match($status) {
        'approved' => 'Approved',
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'revised' => 'Revised',
        'revision_required' => 'Revision Required',
        'rejected' => 'Rejected',
        default => 'Not Started',
    };
}
?>

<div class="faculty-panel faculty-advisee-matrix">
    <div class="faculty-advisee-matrix-head">
        <h3>Advisee Progress</h3>
        <?php if ($hasAdvisees): ?>
            <span class="faculty-advisee-count"><?= $totalProjects ?> project<?= $totalProjects !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$hasAdvisees): ?>
        <div class="faculty-empty-state">
            <svg class="faculty-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            <p>No advising assignments are linked to your account yet.</p>
            <a href="<?= htmlspecialchars($advisingUrl, ENT_QUOTES, 'UTF-8') ?>" class="faculty-btn faculty-btn--secondary faculty-btn--sm">
                View Advising Page
            </a>
        </div>
    <?php else: ?>
        <div class="faculty-advisee-matrix-table" role="region" aria-label="Advisee project chapter progress matrix" aria-describedby="faculty-matrix-hint" tabindex="0">
            <table>
                <thead>
                    <tr>
                        <th scope="col" class="faculty-advisee-project-col">Research / student</th>
                        <?php foreach ($chapters as $chapterNum): ?>
                            <th scope="col" class="faculty-advisee-chapter-col">
                                <span class="faculty-advisee-chapter-label">Ch <?= (int) $chapterNum ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <tr class="faculty-advisee-project-row">
                            <th scope="row" class="faculty-advisee-project-cell">
                                <div class="faculty-advisee-project-info">
                                    <a class="faculty-advisee-project-title"
                                       href="<?= htmlspecialchars(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <div class="faculty-advisee-project-meta">
                                        <span><?= htmlspecialchars($project['student_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (($project['program'] ?? '') !== ''): ?>
                                            <span>Program: <?= htmlspecialchars($project['program'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </th>
                            <?php foreach ($chapters as $chapterNum): ?>
                                <?php
                                $chapterProgress = $project['chapters'][$chapterNum] ?? [];
                                $chapterStatus = $chapterProgress['status'] ?? 'not_started';
                                $statusLabel = $chapterProgress['status_label'] ?? getStatusLabel($chapterStatus);
                                $statusClass = $chapterProgress['status_class'] ?? getStatusClass($chapterStatus);
                                ?>
                                <td class="faculty-advisee-chapter-cell">
                                    <button type="button"
                                        class="faculty-advisee-cell faculty-advisee-cell--<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                                        data-project-id="<?= (int) $project['project_id'] ?>"
                                        data-detail-url="<?= htmlspecialchars(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-chapter="<?= (int) $chapterNum ?>"
                                        data-project-title="<?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-student-name="<?= htmlspecialchars($project['student_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-status-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="<?= htmlspecialchars($project['title'] . ' — ' . $project['student_name'] . ' — Chapter ' . $chapterNum . ': ' . $statusLabel, ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="faculty-advisee-cell-icon" aria-hidden="true"><?= getStatusIcon($chapterStatus) ?></span>
                                        <span class="faculty-advisee-cell-label"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </button>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="faculty-advisee-pagination" aria-label="Advisee project pages">
                <span class="faculty-advisee-pagination-info">
                    Showing <?= $firstProject ?>–<?= $lastProject ?> of <?= $totalProjects ?> projects
                </span>
                <div class="faculty-advisee-pagination-links">
                    <?php if ($currentPage > 1): ?>
                        <a class="faculty-advisee-page-link" href="<?= htmlspecialchars($pageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">Previous</a>
                    <?php else: ?>
                        <span class="faculty-advisee-page-link faculty-advisee-page-link--disabled" aria-disabled="true">Previous</span>
                    <?php endif; ?>

                    <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                        <?php if ($pageNumber === $currentPage): ?>
                            <span class="faculty-advisee-page-link faculty-advisee-page-link--current" aria-current="page"><?= $pageNumber ?></span>
                        <?php else: ?>
                            <a class="faculty-advisee-page-link" href="<?= htmlspecialchars($pageUrl($pageNumber), ENT_QUOTES, 'UTF-8') ?>" aria-label="Page <?= $pageNumber ?>"><?= $pageNumber ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a class="faculty-advisee-page-link" href="<?= htmlspecialchars($pageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">Next</a>
                    <?php else: ?>
                        <span class="faculty-advisee-page-link faculty-advisee-page-link--disabled" aria-disabled="true">Next</span>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <div class="faculty-advisee-legend">
            <span class="faculty-advisee-legend-item">
                <span class="faculty-advisee-legend-dot faculty-advisee-legend-dot--approved" aria-hidden="true"></span>
                Approved
            </span>
            <span class="faculty-advisee-legend-item">
                <span class="faculty-advisee-legend-dot faculty-advisee-legend-dot--in-review" aria-hidden="true"></span>
                In Review/Revision
            </span>
            <span class="faculty-advisee-legend-item">
                <span class="faculty-advisee-legend-dot faculty-advisee-legend-dot--not-started" aria-hidden="true"></span>
                Not Started
            </span>
        </div>

        <p class="faculty-matrix-hint" id="faculty-matrix-hint">
            Each row shows one research project. Select a title or chapter status to open its details; use the page controls to see more projects.
        </p>
    <?php endif; ?>
</div>
