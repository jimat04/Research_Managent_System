<?php
/**
 * Faculty Research Analytics
 * Uses the existing faculty-scoped summaries without loading dashboard content.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('faculty');

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

require_once __DIR__ . '/../../includes/services/FacultyDashboardService.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

$dashboardService = new FacultyDashboardService($conn);
$filterResult = $dashboardService->normalizeAnalyticsFilters($_GET);
$filters = $filterResult['filters'];
$filterErrors = $filterResult['errors'];
$analytics = $dashboardService->getAnalyticsData((int) $user['user_id'], $filters);

if (($_GET['export'] ?? '') === 'csv') {
    $safeCsvValue = static function ($value): string {
        $value = (string) $value;
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="faculty-portfolio-analytics-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, max-age=0');
    echo "\xEF\xBB\xBF";
    $csv = fopen('php://output', 'wb');
    if ($csv === false) {
        http_response_code(500);
        exit;
    }

    fputcsv($csv, ['Faculty portfolio analytics']);
    fputcsv($csv, ['Generated on', date('Y-m-d H:i:s')]);
    fputcsv($csv, ['Filters']);
    $filterLabels = [
        'projects_from' => 'Projects from', 'projects_to' => 'Projects to', 'academic_year' => 'Project academic year',
        'publications_from' => 'Publications from', 'publications_to' => 'Publications to',
        'engagements_from' => 'Engagements from', 'engagements_to' => 'Engagements to',
        'participation_from' => 'Participation from', 'participation_to' => 'Participation to',
        'records_from' => 'Research records from', 'records_to' => 'Research records to',
        'ip_from' => 'Copyright / IP records from', 'ip_to' => 'Copyright / IP records to',
    ];
    foreach ($filterLabels as $key => $label) {
        fputcsv($csv, [$safeCsvValue($label), $safeCsvValue($filters[$key] ?? '')]);
    }
    foreach ($filterErrors as $filterError) {
        fputcsv($csv, ['Filter notice', $safeCsvValue($filterError)]);
    }
    fputcsv($csv, []);
    fputcsv($csv, ['Summary']);
    fputcsv($csv, ['Metric', 'Value', 'Detail']);
    foreach ($analytics['kpis'] as $kpi) {
        fputcsv($csv, array_map($safeCsvValue, [
            $kpi['label'] ?? '',
            $kpi['value'] ?? '',
            $kpi['detail'] ?? '',
        ]));
    }

    foreach ($analytics['sections'] as $section) {
        fputcsv($csv, []);
        fputcsv($csv, [$safeCsvValue($section['title'] ?? 'Portfolio analytics')]);
        fputcsv($csv, [$safeCsvValue($section['description'] ?? '')]);
        $columns = $section['columns'] ?? [];
        fputcsv($csv, array_map(static fn(array $column): string => $safeCsvValue($column['label'] ?? ''), $columns));
        foreach (($section['rows'] ?? []) as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $safeCsvValue($row[$column['key'] ?? ''] ?? '');
            }
            fputcsv($csv, $values);
        }
    }
    fclose($csv);
    exit;
}

renderFacultyShell($user, 'faculty-analytics', 'Research Analytics', 'Your advising, research outputs, and faculty portfolio activity.', ['faculty-analytics.css']);
?>
<?php include __DIR__ . '/../../includes/faculty-analytics.php'; ?>
<script src="<?= htmlspecialchars(SITE_URL . 'js/faculty-analytics.js?v=' . filemtime(__DIR__ . '/../../js/faculty-analytics.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php renderFacultyShellClose(); ?>
