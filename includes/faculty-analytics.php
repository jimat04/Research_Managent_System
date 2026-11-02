<?php
/**
 * Faculty Analytics Charts
 *
 * Visible on the standalone analytics page. Data tables remain available
 * when JavaScript or chart loading is unavailable.
 *
 * @var array $analytics Chart data from FacultyDashboardService
 */
?>
<section id="analytics-heading" data-faculty-analytics aria-label="Research analytics">
    <div id="analytics-content" class="faculty-analytics-content">
        <div class="faculty-analytics-grid">
            <!-- Projects by Status -->
            <div class="faculty-chart-wrapper">
                <h4>Projects by Status</h4>
                <canvas id="chart-projects-status" aria-label="Projects by status chart"
                    data-chart-data='<?= htmlspecialchars(json_encode($analytics['projects_by_status'] ?? []), ENT_QUOTES, 'UTF-8') ?>'></canvas>
                <details open>
                    <summary>View data</summary>
                    <table>
                        <caption>Projects by Status</caption>
                        <thead>
                            <tr><th>Status</th><th>Count</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($analytics['projects_by_status'])): ?>
                                <tr><td colspan="2">No advising projects yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach (($analytics['projects_by_status'] ?? []) as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['label']) ?></td>
                                    <td><?= htmlspecialchars($item['value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>

            <!-- Published Outputs (5yr) -->
            <div class="faculty-chart-wrapper">
                <h4>Published Outputs (5 Years)</h4>
                <canvas id="chart-publications-years" aria-label="Publications by year chart"
                    data-chart-data='<?= htmlspecialchars(json_encode($analytics['publications_by_year'] ?? []), ENT_QUOTES, 'UTF-8') ?>'></canvas>
                <details open>
                    <summary>View data</summary>
                    <table>
                        <caption>Publications by Year</caption>
                        <thead>
                            <tr><th>Year</th><th>Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (($analytics['publications_by_year'] ?? []) as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['label']) ?></td>
                                    <td><?= htmlspecialchars($item['value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>

            <!-- Engagement by Status -->
            <div class="faculty-chart-wrapper faculty-chart-wrapper--full">
                <h4>Engagement by Status</h4>
                <canvas id="chart-engagement-status" aria-label="Engagement by status chart"
                    data-chart-data='<?= htmlspecialchars(json_encode($analytics['engagements_by_status'] ?? []), ENT_QUOTES, 'UTF-8') ?>'></canvas>
                <details open>
                    <summary>View data</summary>
                    <table>
                        <caption>Engagement by Status</caption>
                        <thead>
                            <tr><th>Status</th><th>Count</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($analytics['engagements_by_status'])): ?>
                                <tr><td colspan="2">No engagement records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach (($analytics['engagements_by_status'] ?? []) as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['label']) ?></td>
                                    <td><?= htmlspecialchars($item['value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>
        </div>
    </div>
</section>
