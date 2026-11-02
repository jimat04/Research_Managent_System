<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
if (!in_array($role, ['admin', 'research_staff'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$viewInput = trim((string) ($_GET['view'] ?? 'ongoing'));
$view = preg_match('/^(ongoing|completed|outputs)$/D', $viewInput) ? $viewInput : 'ongoing';
$viewConfig = [
    'ongoing' => [
        'module_page' => 'ongoing_research',
        'title' => 'Ongoing Research',
        'description' => 'Monitor title-approved projects as researchers submit Chapters 1–5 for review.',
    ],
    'completed' => [
        'module_page' => 'completed_research',
        'title' => 'Completed Research',
        'description' => 'Review projects with five approved chapters and an approved complete manuscript.',
    ],
    'outputs' => [
        'module_page' => 'research_outputs',
        'title' => 'Research Outputs',
        'description' => 'Monitor active publication records and registered copyrights.',
    ],
];
$modulePage = $viewConfig[$view]['module_page'];
if (!hasRole('admin') && !hasModulePermission($userId, 'research_implementation_outputs', 'view', $modulePage)) {
    http_response_code(403);
    exit('Access denied.');
}
$_GET['module_page'] = $modulePage;

// Administrators use the unified research directory; preserve the existing CSV handler.
if ($role === 'admin' && in_array($view, ['ongoing', 'completed'], true) && ($_GET['export'] ?? '') !== 'csv') {
    $directory_params = ['status' => $view];
    $directory_filters = $view === 'completed'
        ? ['q' => 'search', 'year' => 'year', 'status' => 'record_status']
        : ['q' => 'search'];
    foreach ($directory_filters as $source => $target) {
        if (is_string($_GET[$source] ?? null) && $_GET[$source] !== '') {
            $directory_params[$target] = $_GET[$source];
        }
    }
    header('Location: ' . SITE_URL . 'pages/admin/admin-research-management.php?' . http_build_query($directory_params));
    exit;
}

function implementationOutputsQuery(mysqli $connection, string $sql): mysqli_result
{
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the implementation and outputs query: ' . $connection->error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load implementation and outputs data: ' . $error);
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function implementationOutputsPreparedQuery(mysqli $connection, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the implementation and outputs query: ' . $connection->error);
    }
    if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to bind the implementation and outputs query: ' . $error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load implementation and outputs data: ' . $error);
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function implementationOutputsTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the implementation and outputs schema.');
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect the implementation and outputs schema: ' . $error);
    }
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function implementationOutputsColumnExists(mysqli $connection, string $table, string $column): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the implementation and outputs schema.');
    }
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect the implementation and outputs schema: ' . $error);
    }
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function implementationOutputsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function implementationOutputsDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? 'Not recorded' : date('M j, Y', $timestamp);
}

function implementationOutputsUrl(array $params): string
{
    return SITE_URL . 'pages/shared/implementation-outputs.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function implementationOutputsCsvValue($value): string
{
    $value = (string) $value;
    return preg_match('/^[\s\p{Z}\x{FEFF}]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

function implementationOutputsWriteCsvRow($stream, array $values): void
{
    $safeValues = array_map('implementationOutputsCsvValue', $values);
    if (fputcsv($stream, $safeValues, ',', '"', '') === false) {
        throw new RuntimeException('Unable to write the implementation and outputs CSV export.');
    }
}

$projectsHaveDeletedAt = implementationOutputsColumnExists($conn, 'research_projects', 'deleted_at');
$chaptersHaveDeletedAt = implementationOutputsColumnExists($conn, 'chapters', 'deleted_at');
$projectDeletedFilter = $projectsHaveDeletedAt ? ' AND rp.deleted_at IS NULL' : '';
$chapterDeletedFilter = $chaptersHaveDeletedAt ? ' WHERE deleted_at IS NULL' : '';
$chaptersJoinDeletedFilter = $chaptersHaveDeletedAt ? ' AND ch.deleted_at IS NULL' : '';
$publicationsAvailable = implementationOutputsTableExists($conn, 'publications');
$copyrightsAvailable = implementationOutputsTableExists($conn, 'copyright_applications');

$projects = [];
$completedProjects = [];
$outputRecords = [];
$outputTotal = 0;
$outputPage = 1;
$outputPageSize = 25;
$outputPageCount = 1;
$stats = [];
$currentYear = (int) date('Y');
$canViewPublications = $role === 'admin'
    || hasModulePermission($userId, 'research_implementation_outputs', 'view', 'research_outputs');
$canViewAllCopyrights = $role === 'admin'
    || hasModulePermission($userId, 'intellectual_property_utilization', 'view', 'copyrights');
$canViewPatentCopyrights = $role === 'admin'
    || hasModulePermission($userId, 'intellectual_property_utilization', 'view', 'patents_utility_models');
$canViewInnovationCopyrights = $role === 'admin'
    || hasModulePermission($userId, 'intellectual_property_utilization', 'view', 'innovation_technology_transfer');
$copyrightScopeTypes = [];
if (!$canViewAllCopyrights) {
    if ($canViewPatentCopyrights) {
        $copyrightScopeTypes = array_merge($copyrightScopeTypes, ['patent', 'utility_model']);
    }
    if ($canViewInnovationCopyrights) {
        $copyrightScopeTypes = array_merge($copyrightScopeTypes, ['innovation', 'technology_transfer']);
    }
}
$canViewCopyrights = $canViewAllCopyrights || $copyrightScopeTypes !== [];
$registeredOutputStatusSql = "(c.external_status = 'registered' OR (c.external_status IS NULL AND c.status = 'registered'))";
$patentOutputTypesSql = "c.output_type IN ('patent','utility_model')";
$innovationOutputTypesSql = "c.output_type IN ('innovation','technology_transfer')";
$genericCopyrightTypesSql = "c.output_type NOT IN ('patent','utility_model','innovation','technology_transfer') OR NULLIF(c.copyright_ref_no, '') IS NOT NULL";
if ($canViewPatentCopyrights || $canViewInnovationCopyrights) {
    $genericCopyrightTypesSql = "c.output_type NOT IN ('patent','utility_model','innovation','technology_transfer')";
}
$copyrightScopeSql = $canViewAllCopyrights || !$copyrightScopeTypes
    ? ''
    : " AND output_type IN ('" . implode("','", $copyrightScopeTypes) . "')";
$publicationRecordsAvailable = $publicationsAvailable && $canViewPublications;
$registeredAvailable = $copyrightsAvailable && $canViewCopyrights;

$searchInput = $_GET['q'] ?? '';
$searchQuery = is_string($searchInput) ? trim($searchInput) : '';
$searchQuery = strlen($searchQuery) > 150 ? substr($searchQuery, 0, 150) : $searchQuery;
$exportInput = $_GET['export'] ?? '';
$exportCsv = is_string($exportInput) && $exportInput === 'csv';

$chapterAggregate = "
    SELECT project_id,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 THEN chapter_number END) AS total_chapters,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 AND status = 'approved' THEN chapter_number END) AS approved_chapters,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 AND status = 'submitted' THEN chapter_number END) AS submitted_chapters,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 AND status = 'revision_required' THEN chapter_number END) AS returned_chapters,
           MAX(updated_at) AS latest_activity,
           MAX(CASE WHEN status = 'approved' THEN approved_at END) AS last_approved_at
      FROM chapters{$chapterDeletedFilter}
     GROUP BY project_id
";
$approvedTitleAggregate = "
    SELECT v.project_id,
           MAX(COALESCE(r.reviewed_at, v.submitted_at)) AS title_approved_at,
           MAX(v.submitted_at) AS approved_title_submitted_at
      FROM research_submission_versions v
 LEFT JOIN research_submission_reviews r
        ON r.version_id = v.version_id AND r.decision = 'approved'
     WHERE v.submission_type = 'title' AND v.status = 'approved'
     GROUP BY v.project_id
";
$approvedFinalManuscriptAggregate = "
    SELECT v.project_id,
           MAX(COALESCE(r.reviewed_at, v.submitted_at)) AS final_approved_at
      FROM research_submission_versions v
 LEFT JOIN research_submission_reviews r
        ON r.version_id = v.version_id AND r.decision = 'approved'
     WHERE v.submission_type = 'final_manuscript'
       AND v.chapter_id IS NULL
       AND v.status = 'approved'
     GROUP BY v.project_id
";

if ($view === 'ongoing') {
    $sql = "
        SELECT rp.project_id, rp.title, rp.status, rp.created_at,
               CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), '')) AS researcher_name,
               u.role AS researcher_role,
               COALESCE(ta.title_approved_at, ta.approved_title_submitted_at, rp.created_at) AS title_approved_at,
               COALESCE(ca.total_chapters, 0) AS total_chapters,
               COALESCE(ca.approved_chapters, 0) AS approved_chapters,
               COALESCE(ca.submitted_chapters, 0) AS submitted_chapters,
               COALESCE(ca.returned_chapters, 0) AS returned_chapters,
               ca.latest_activity,
               (ca.latest_activity IS NULL OR ca.latest_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS stale_activity
          FROM research_projects rp
     LEFT JOIN users u ON u.user_id = rp.created_by AND u.deleted_at IS NULL
     LEFT JOIN ({$chapterAggregate}) ca ON ca.project_id = rp.project_id
     LEFT JOIN ({$approvedTitleAggregate}) ta ON ta.project_id = rp.project_id
         WHERE " . rms_ongoing_research_predicate() . "{$projectDeletedFilter}
      ORDER BY ca.latest_activity IS NULL ASC, ca.latest_activity ASC, rp.updated_at DESC, rp.project_id DESC
    ";
    $result = implementationOutputsQuery($conn, $sql);
    while ($row = $result->fetch_assoc()) {
        $row['researcher_name'] = trim((string) ($row['researcher_name'] ?? '')) ?: 'Unknown researcher';
        $row['total_chapters'] = (int) $row['total_chapters'];
        $row['approved_chapters'] = (int) $row['approved_chapters'];
        $row['submitted_chapters'] = (int) $row['submitted_chapters'];
        $row['returned_chapters'] = (int) $row['returned_chapters'];
        $row['stale_activity'] = (bool) $row['stale_activity'];
        $projects[] = $row;
    }
    $result->free();

    $stats = [
        'in_progress' => count($projects),
        'under_review' => array_sum(array_column($projects, 'submitted_chapters')),
        'approved' => array_sum(array_column($projects, 'approved_chapters')),
        'returned' => array_sum(array_column($projects, 'returned_chapters')),
    ];

    $ongoingStatusOptions = [
        'title_approved' => 'Title approved',
        'ongoing' => 'Ongoing',
    ];
    $ongoingStatusInput = $_GET['status'] ?? '';
    $ongoingStatus = is_string($ongoingStatusInput) && isset($ongoingStatusOptions[$ongoingStatusInput])
        ? $ongoingStatusInput
        : 'title_approved';
    $projectMatchesFilters = static function (array $project) use ($searchQuery, $ongoingStatus): bool {
        if ($ongoingStatus !== '' && (string) $project['status'] !== $ongoingStatus) {
            return false;
        }
        return $searchQuery === ''
            || stripos((string) $project['title'], $searchQuery) !== false
            || stripos((string) $project['researcher_name'], $searchQuery) !== false;
    };
    $projects = array_values(array_filter($projects, $projectMatchesFilters));
} elseif ($view === 'completed') {
    $sql = "
        SELECT rp.project_id, rp.title, rp.status, rp.created_at, rp.updated_at,
               CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), '')) AS researcher_name,
               u.role AS researcher_role,
               COALESCE(ca.total_chapters, 0) AS total_chapters,
               COALESCE(ca.approved_chapters, 0) AS approved_chapters,
               fa.final_approved_at,
               COALESCE(ta.approved_title_submitted_at, rp.created_at) AS title_start_at
          FROM research_projects rp
     LEFT JOIN users u ON u.user_id = rp.created_by AND u.deleted_at IS NULL
     LEFT JOIN ({$chapterAggregate}) ca ON ca.project_id = rp.project_id
     LEFT JOIN ({$approvedTitleAggregate}) ta ON ta.project_id = rp.project_id
     LEFT JOIN ({$approvedFinalManuscriptAggregate}) fa ON fa.project_id = rp.project_id
         WHERE " . rms_completed_research_predicate() . "{$projectDeletedFilter}
      ORDER BY COALESCE(fa.final_approved_at, rp.updated_at) DESC, rp.project_id DESC
    ";
    $result = implementationOutputsQuery($conn, $sql);
    $completedThisYear = 0;
    $completionDays = [];
    while ($row = $result->fetch_assoc()) {
        $row['researcher_name'] = trim((string) ($row['researcher_name'] ?? '')) ?: 'Unknown researcher';
        $row['total_chapters'] = (int) $row['total_chapters'];
        $row['approved_chapters'] = (int) $row['approved_chapters'];
        $row['completed_at'] = $row['final_approved_at'] ?: $row['updated_at'];
        if ($row['final_approved_at'] && (int) date('Y', strtotime((string) $row['final_approved_at'])) === $currentYear) {
            $completedThisYear++;
        }
        if ($row['final_approved_at']) {
            $start = strtotime((string) $row['title_start_at']);
            $finish = strtotime((string) $row['final_approved_at']);
            if ($start !== false && $finish !== false && $finish >= $start) {
                $completionDays[] = (int) floor(($finish - $start) / 86400);
            }
        }
        $completedProjects[] = $row;
    }
    $result->free();

    $stats = [
        'completed' => count($completedProjects),
        'completed_this_year' => $completedThisYear,
        'average_days' => $completionDays
            ? number_format(array_sum($completionDays) / count($completionDays), 1)
            : '—',
    ];

    $completedStatusOptions = [];
    foreach ($completedProjects as $project) {
        $status = (string) $project['status'];
        $completedStatusOptions[$status] = ucwords(str_replace('_', ' ', $status));
    }
    asort($completedStatusOptions);
    $completedStatusInput = $_GET['status'] ?? '';
    $completedStatus = is_string($completedStatusInput) && isset($completedStatusOptions[$completedStatusInput])
        ? $completedStatusInput
        : '';
    $completedYearInput = $_GET['year'] ?? '';
    $completedYear = is_string($completedYearInput) && preg_match('/^\d{4}$/D', $completedYearInput)
        ? (int) $completedYearInput
        : 0;
    $completedProjects = array_values(array_filter(
        $completedProjects,
        static function (array $project) use ($searchQuery, $completedStatus, $completedYear): bool {
            if ($completedStatus !== '' && (string) $project['status'] !== $completedStatus) {
                return false;
            }
            if ($completedYear > 0) {
                $timestamp = strtotime((string) $project['completed_at']);
                if ($timestamp === false || (int) date('Y', $timestamp) !== $completedYear) {
                    return false;
                }
            }
            return $searchQuery === ''
                || stripos((string) $project['title'], $searchQuery) !== false
                || stripos((string) $project['researcher_name'], $searchQuery) !== false;
        }
    ));
} else {
    $yearResult = implementationOutputsQuery($conn, 'SELECT YEAR(CURDATE()) AS current_year');
    $outputYearRow = $yearResult->fetch_assoc() ?: [];
    $outputMetricYear = (int) ($outputYearRow['current_year'] ?? $currentYear);
    $yearResult->free();

    $publicationCount = 0;
    $publicationRecordsThisYear = 0;
    if ($publicationRecordsAvailable) {
        $result = implementationOutputsQuery(
            $conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(YEAR(COALESCE(publication_date, DATE(created_at))) = YEAR(CURDATE())), 0) AS this_year
               FROM publications
              WHERE deactivated_at IS NULL"
        );
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $publicationCount = (int) ($row['total'] ?? 0);
        $publicationRecordsThisYear = (int) ($row['this_year'] ?? 0);
    }
    $registeredCount = 0;
    $registeredThisYear = 0;
    if ($registeredAvailable) {
        $result = implementationOutputsQuery(
            $conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(YEAR(COALESCE(registered_at, created_at)) = YEAR(CURDATE())), 0) AS this_year
               FROM copyright_applications
              WHERE deactivated_at IS NULL AND (external_status = 'registered' OR (external_status IS NULL AND status = 'registered' AND (output_type IN ('patent','utility_model','innovation','technology_transfer') OR NULLIF(copyright_ref_no, '') IS NOT NULL))){$copyrightScopeSql}"
        );
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $registeredCount = (int) ($row['total'] ?? 0);
        $registeredThisYear = (int) ($row['this_year'] ?? 0);
    }

    $stats = [
        'publications' => $publicationRecordsAvailable ? $publicationCount : null,
        'registered' => $registeredAvailable ? $registeredCount : null,
        'this_year' => $publicationRecordsThisYear + $registeredThisYear,
    ];

    $outputTypeOptions = [];
    if ($publicationRecordsAvailable) {
        $outputTypeOptions['publication'] = 'Publications';
    }
    if ($registeredAvailable) {
        if ($canViewPatentCopyrights) {
            $outputTypeOptions['patents_utility_models'] = 'Patents & Utility Models';
        }
        if ($canViewInnovationCopyrights) {
            $outputTypeOptions['innovation_technology_transfer'] = 'Innovation & Technology Transfer';
        }
        if ($canViewAllCopyrights && !$canViewPatentCopyrights && !$canViewInnovationCopyrights) {
            $outputTypeOptions['copyright'] = 'Registered copyrights';
        } elseif (!$canViewAllCopyrights && ($canViewPatentCopyrights || $canViewInnovationCopyrights)) {
            // Already added specific types above
        } elseif ($canViewAllCopyrights) {
            $outputTypeOptions['copyright'] = 'All registered copyrights';
        } else {
            $outputTypeOptions['copyright'] = 'Authorized copyright records';
        }
    }
    $outputTypeInput = $_GET['type'] ?? '';
    $outputType = is_string($outputTypeInput) && isset($outputTypeOptions[$outputTypeInput])
        ? $outputTypeInput
        : '';
    $outputYearInput = $_GET['year'] ?? '';
    $outputYear = is_string($outputYearInput) && preg_match('/^\d{4}$/D', $outputYearInput)
        ? (int) $outputYearInput
        : 0;

    $outputBranches = [];
    if ($publicationRecordsAvailable) {
        $outputBranches[] = "
            SELECT 'publication' AS record_type,
                   CASE p.publication_type
                       WHEN 'journal' THEN 'Journal'
                       WHEN 'conference' THEN 'Conference'
                       WHEN 'book_chapter' THEN 'Book chapter'
                       ELSE 'Publication'
                   END AS type_label,
                   p.research_title AS record_title,
                   COALESCE(
                       NULLIF(TRIM(p.researcher_name), ''),
                       NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                       'Unknown researcher'
                   ) AS person_name,
                   COALESCE(p.publication_date, DATE(p.created_at)) AS event_date,
                   COALESCE(p.publication_date, DATE(p.created_at)) AS sort_date,
                   NULL AS record_scope,
                   p.publication_id AS record_id
              FROM publications p
         LEFT JOIN users u ON u.user_id = p.researcher_id AND u.deleted_at IS NULL
             WHERE p.deactivated_at IS NULL
        ";
    }
    if ($registeredAvailable) {
        // Patents & Utility Models branch
        if ($canViewPatentCopyrights) {
            $outputBranches[] = "
                SELECT 'patents_utility_models' AS record_type,
                       'Patent / Utility Model' AS type_label,
                       c.output_title AS record_title,
                       COALESCE(
                           NULLIF(TRIM(c.applicant_name), ''),
                           NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                           'Unknown applicant'
                       ) AS person_name,
                       COALESCE(DATE(c.registered_at), DATE(c.created_at)) AS event_date,
                       COALESCE(c.registered_at, c.created_at) AS sort_date,
                       c.output_type AS record_scope,
                       c.copyright_id AS record_id
                  FROM copyright_applications c
             LEFT JOIN users u ON u.user_id = c.applicant_id AND u.deleted_at IS NULL
                 WHERE c.deactivated_at IS NULL AND ({$patentOutputTypesSql}) AND {$registeredOutputStatusSql}{$copyrightScopeSql}
            ";
        }
        // Innovation & Technology Transfer branch
        if ($canViewInnovationCopyrights) {
            $outputBranches[] = "
                SELECT 'innovation_technology_transfer' AS record_type,
                       'Innovation / Technology Transfer' AS type_label,
                       c.output_title AS record_title,
                       COALESCE(
                           NULLIF(TRIM(c.applicant_name), ''),
                           NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                           'Unknown applicant'
                       ) AS person_name,
                       COALESCE(DATE(c.registered_at), DATE(c.created_at)) AS event_date,
                       COALESCE(c.registered_at, c.created_at) AS sort_date,
                       c.output_type AS record_scope,
                       c.copyright_id AS record_id
                  FROM copyright_applications c
             LEFT JOIN users u ON u.user_id = c.applicant_id AND u.deleted_at IS NULL
                 WHERE c.deactivated_at IS NULL AND ({$innovationOutputTypesSql}) AND {$registeredOutputStatusSql}{$copyrightScopeSql}
            ";
        }
        // Generic copyright branch (for other types or when all copyrights are viewable)
        if ($canViewAllCopyrights || (!$canViewPatentCopyrights && !$canViewInnovationCopyrights && $canViewCopyrights)) {
            $outputBranches[] = "
                SELECT 'copyright' AS record_type,
                       'Copyright' AS type_label,
                       c.output_title AS record_title,
                       COALESCE(
                           NULLIF(TRIM(c.applicant_name), ''),
                           NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                           'Unknown applicant'
                       ) AS person_name,
                       COALESCE(DATE(c.registered_at), DATE(c.created_at)) AS event_date,
                       COALESCE(c.registered_at, c.created_at) AS sort_date,
                       c.output_type AS record_scope,
                       c.copyright_id AS record_id
                  FROM copyright_applications c
             LEFT JOIN users u ON u.user_id = c.applicant_id AND u.deleted_at IS NULL
                 WHERE c.deactivated_at IS NULL AND ({$genericCopyrightTypesSql})
                   AND {$registeredOutputStatusSql}{$copyrightScopeSql}
            ";
        }
    }

    $outputConditions = [];
    $outputParams = [];
    $outputParamTypes = '';
    if ($searchQuery !== '') {
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $searchQuery);
        $likeSearch = '%' . $escapedSearch . '%';
        $outputConditions[] = "(feed.record_title LIKE ? ESCAPE '!' OR feed.person_name LIKE ? ESCAPE '!')";
        array_push($outputParams, $likeSearch, $likeSearch);
        $outputParamTypes .= 'ss';
    }
    if ($outputType !== '') {
        $outputConditions[] = 'feed.record_type = ?';
        $outputParams[] = $outputType;
        $outputParamTypes .= 's';
    }
    if ($outputYear > 0) {
        $outputConditions[] = 'feed.event_date >= ? AND feed.event_date < ?';
        $outputParams[] = sprintf('%04d-01-01', $outputYear);
        $outputParams[] = sprintf('%04d-01-01', $outputYear + 1);
        $outputParamTypes .= 'ss';
    }
    $outputWhere = $outputConditions ? ' WHERE ' . implode(' AND ', $outputConditions) : '';
    $outputUnionSql = implode(' UNION ALL ', $outputBranches);

    if ($outputBranches) {
        $outputSelectSql = "SELECT feed.record_type, feed.type_label, feed.record_title, feed.person_name, feed.event_date, feed.sort_date, feed.record_scope, feed.record_id FROM ({$outputUnionSql}) AS feed{$outputWhere}";
        if ($exportCsv) {
            $result = implementationOutputsPreparedQuery(
                $conn,
                $outputSelectSql . ' ORDER BY feed.sort_date DESC, feed.record_title ASC, feed.record_type ASC, feed.record_id DESC',
                $outputParamTypes,
                $outputParams
            );
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="research-outputs-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the research outputs CSV export.');
            }
            implementationOutputsWriteCsvRow($stream, ['Type', 'Output', 'Researcher / applicant', 'Date']);
            while ($row = $result->fetch_assoc()) {
                implementationOutputsWriteCsvRow($stream, [
                    $row['type_label'],
                    $row['record_title'],
                    $row['person_name'],
                    $row['event_date'] ?: '',
                ]);
            }
            $result->free();
            fclose($stream);
            exit;
        }

        $countResult = implementationOutputsPreparedQuery(
            $conn,
            "SELECT COUNT(*) AS total FROM ({$outputUnionSql}) AS feed{$outputWhere}",
            $outputParamTypes,
            $outputParams
        );
        $outputTotal = (int) (($countResult->fetch_assoc()['total'] ?? 0));
        $countResult->free();
        $outputPageCount = max(1, (int) ceil($outputTotal / $outputPageSize));
        $outputPageInput = $_GET['page'] ?? '1';
        $requestedPage = is_string($outputPageInput) && preg_match('/^\d+$/D', $outputPageInput)
            ? (int) $outputPageInput
            : 1;
        $outputPage = min(max(1, $requestedPage), $outputPageCount);
        $outputParamsWithPage = array_merge(
            $outputParams,
            [$outputPageSize, ($outputPage - 1) * $outputPageSize]
        );
        $result = implementationOutputsPreparedQuery(
            $conn,
            $outputSelectSql . ' ORDER BY feed.sort_date DESC, feed.record_title ASC, feed.record_type ASC, feed.record_id DESC LIMIT ? OFFSET ?',
            $outputParamTypes . 'ii',
            $outputParamsWithPage
        );
        $publicationHubUrl = SITE_URL . 'pages/shared/publications.php?module_page=research_outputs';
        $copyrightHubUrl = SITE_URL . 'pages/shared/copyrights.php?status=registered&module_page=research_outputs';
        $patentsHubUrl = SITE_URL . 'pages/shared/copyrights.php?ip_scope=patents_utility_models&status=registered&module_page=research_outputs';
        $innovationHubUrl = SITE_URL . 'pages/shared/copyrights.php?ip_scope=innovation_technology_transfer&status=registered&module_page=research_outputs';
        while ($row = $result->fetch_assoc()) {
            switch ($row['record_type']) {
                case 'patents_utility_models':
                    $row['link_url'] = $patentsHubUrl;
                    break;
                case 'innovation_technology_transfer':
                    $row['link_url'] = $innovationHubUrl;
                    break;
                case 'copyright':
                    if (!$canViewAllCopyrights) {
                        $scopePage = in_array($row['record_scope'], ['patent', 'utility_model'], true)
                            ? 'patents_utility_models'
                            : 'innovation_technology_transfer';
                        $row['link_url'] = SITE_URL . 'pages/shared/copyrights.php?ip_scope=' . rawurlencode($scopePage)
                            . '&status=registered&module_page=research_outputs';
                    } else {
                        $row['link_url'] = $copyrightHubUrl;
                    }
                    break;
                case 'publication':
                default:
                    $row['link_url'] = $publicationHubUrl;
                    break;
            }
            $outputRecords[] = $row;
        }
        $result->free();
    }

    if ($publicationRecordsAvailable && $registeredAvailable && $canViewAllCopyrights) {
        $yearMetricLabel = 'Outputs this year';
        $yearMetricType = '';
    } elseif ($publicationRecordsAvailable && $registeredAvailable && ($canViewPatentCopyrights || $canViewInnovationCopyrights)) {
        $yearMetricLabel = 'Outputs this year';
        $yearMetricType = '';
    } elseif ($publicationRecordsAvailable && $registeredAvailable) {
        $yearMetricLabel = 'Outputs this year';
        $yearMetricType = '';
    } elseif ($publicationRecordsAvailable) {
        $yearMetricLabel = 'Publications this year';
        $yearMetricType = 'publication';
    } elseif ($registeredAvailable) {
        if ($canViewPatentCopyrights && $canViewInnovationCopyrights) {
            $yearMetricLabel = 'Registered IP this year';
        } elseif ($canViewPatentCopyrights) {
            $yearMetricLabel = 'Patents & models this year';
        } elseif ($canViewInnovationCopyrights) {
            $yearMetricLabel = 'Innovation this year';
        } else {
            $yearMetricLabel = $canViewAllCopyrights
                ? 'Copyrights this year'
                : 'Authorized copyrights this year';
        }
        $yearMetricType = $canViewAllCopyrights || (!$canViewPatentCopyrights && !$canViewInnovationCopyrights) ? 'copyright' : '';
    } else {
        $yearMetricLabel = 'Outputs this year';
        $yearMetricType = '';
    }
}

$canViewTab = static function (string $targetView) use ($role, $userId, $viewConfig): bool {
    return $role === 'admin'
        || hasModulePermission(
            $userId,
            'research_implementation_outputs',
            'view',
            $viewConfig[$targetView]['module_page']
        );
};

if ($exportCsv && $view !== 'outputs') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="research-' . $view . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open the research register CSV export.');
    }
    if ($view === 'ongoing') {
        implementationOutputsWriteCsvRow($stream, [
            'Project',
            'Researcher',
            'Status',
            'Title approval date',
            'Approved chapters',
            'Total chapters',
            'Last chapter activity',
        ]);
        foreach ($projects as $project) {
            implementationOutputsWriteCsvRow($stream, [
                $project['title'],
                $project['researcher_name'],
                ucwords(str_replace('_', ' ', (string) $project['status'])),
                $project['title_approved_at'],
                $project['approved_chapters'],
                5,
                $project['latest_activity'],
            ]);
        }
    } else {
        implementationOutputsWriteCsvRow($stream, [
            'Project',
            'Researcher',
            'Status',
            'Completion / recorded date',
            'Approved chapters',
            'Total chapters',
        ]);
        foreach ($completedProjects as $project) {
            implementationOutputsWriteCsvRow($stream, [
                $project['title'],
                $project['researcher_name'],
                ucwords(str_replace('_', ' ', (string) $project['status'])),
                $project['completed_at'],
                $project['approved_chapters'],
                $project['total_chapters'],
            ]);
        }
    }
    fclose($stream);
    exit;
}

$pageTitle = $viewConfig[$view]['title'];
$pageDescription = $viewConfig[$view]['description'];
$shellPage = 'implementation-outputs.php';
if ($role === 'admin') {
    renderAdminShell($user, $shellPage, $pageTitle, $pageDescription);
} else {
    renderStaffShell($user, $shellPage, $pageTitle, $pageDescription);
}

$accent = $role === 'admin' ? '#F57C00' : '#0d9488';
$accentDark = $role === 'admin' ? '#c2410c' : '#0f766e';
$accentTint = $role === 'admin' ? '#fff7ed' : '#f0fdfa';
$tabs = [
    'ongoing' => 'Ongoing Research',
    'completed' => 'Completed Research',
    'outputs' => 'Research Outputs',
];
?>
<style>
.implementation-hub {
  --io-accent: <?= implementationOutputsEscape($accent) ?>;
  --io-accent-dark: <?= implementationOutputsEscape($accentDark) ?>;
  --io-accent-tint: <?= implementationOutputsEscape($accentTint) ?>;
  --io-accent-border: <?= $role === 'admin' ? 'rgba(245,124,0,.22)' : 'rgba(13,148,136,.22)' ?>;
  max-width: 1440px;
  margin: 0 auto;
  padding: 0 0 36px;
  color: #172033;
  container: implementation / inline-size;
}
.implementation-hub .io-tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin: 0 0 22px;
  padding: 4px 2px 10px;
}
.implementation-hub .io-tab {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 9px;
  min-height: 44px;
  padding: 9px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 11px;
  background: #fff;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s ease;
}
.implementation-hub .io-tab:hover {
  transform: translateY(-1px);
  border-color: var(--io-accent-border);
  color: var(--io-accent-dark);
}
.implementation-hub .io-tab[aria-current="page"] {
  border-color: var(--io-accent-border);
  background: var(--io-accent-tint);
  color: var(--io-accent-dark);
  box-shadow: 0 5px 16px rgba(30, 41, 59, .06);
}
.implementation-hub .io-tab-index {
  display: grid;
  width: 23px;
  height: 23px;
  flex: 0 0 23px;
  place-items: center;
  border-radius: 7px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 10px;
  font-weight: 700;
}
.implementation-hub .io-tab[aria-current="page"] .io-tab-index {
  background: var(--io-accent);
  color: #fff;
}
.implementation-hub .io-intro {
  position: relative;
  display: grid;
  gap: 22px;
  overflow: hidden;
  margin: 0 0 22px;
  padding: 28px 32px;
  border: 1px solid #e5e7eb;
  border-radius: 20px;
  background: radial-gradient(ellipse at 92% 8%, var(--io-accent-tint), transparent 42%), #fff;
  box-shadow: 0 10px 28px rgba(30, 41, 59, .04);
}
.implementation-hub .io-intro::before {
  position: absolute;
  inset: 0 auto 0 0;
  width: 4px;
  background: var(--io-accent);
  content: "";
}
.implementation-hub .io-eyebrow {
  margin: 0 0 10px;
  color: var(--io-accent-dark);
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .13em;
  text-transform: uppercase;
}
.implementation-hub .io-intro h1 {
  margin: 0;
  color: #111827;
  font-size: clamp(27px, 3.2vw, 38px);
  font-weight: 750;
  letter-spacing: -.035em;
  line-height: 1.13;
  text-wrap: balance;
}
.implementation-hub .io-intro-copy {
  max-width: 66ch;
  margin: 12px 0 0;
  color: #64748b;
  font-size: 14px;
  line-height: 1.7;
}
.implementation-hub .io-stage {
  display: flex;
  align-items: center;
  gap: 18px;
  padding-top: 17px;
  border-top: 1px solid #e2e8f0;
}
.implementation-hub .io-stage-label {
  flex: 0 0 132px;
  margin: 0;
  color: #64748b;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.implementation-hub .io-stage-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  flex: 1;
  margin: 0;
  padding: 0;
  list-style: none;
}
.implementation-hub .io-stage-list li {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.35;
}
.implementation-hub .io-stage-list li span {
  display: grid;
  width: 29px;
  height: 29px;
  flex: 0 0 29px;
  place-items: center;
  border: 1px solid #e2e8f0;
  border-radius: 9px;
  background: #f8fafc;
  color: #64748b;
  font-size: 10px;
  font-weight: 700;
}
.implementation-hub .io-stage-list li.is-complete span {
  border-color: var(--io-accent-border);
  background: var(--io-accent-tint);
  color: var(--io-accent-dark);
}
.implementation-hub .io-stage-list li[aria-current="step"] {
  color: var(--io-accent-dark);
  font-weight: 700;
}
.implementation-hub .io-stage-list li[aria-current="step"] span {
  border-color: var(--io-accent);
  background: var(--io-accent);
  color: #fff;
}
.implementation-hub .stat-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}
.implementation-hub .stat-card {
  min-width: 0;
  padding: 19px 20px 20px;
  border: 1px solid #e5e7eb;
  border-top: 3px solid var(--io-accent);
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 4px 14px rgba(30, 41, 59, .035);
}
.implementation-hub .stat-card strong {
  display: block;
  color: #111827;
  font-size: 27px;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.implementation-hub .stat-card span {
  display: block;
  margin-top: 7px;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
}
.implementation-hub .outputs-stat-grid .stat-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  min-height: 112px;
  padding: 18px 20px;
  border: 1px solid #e5e7eb;
  border-left: 4px solid var(--io-accent);
  border-radius: 14px;
  background: linear-gradient(145deg, #fff 0%, #f8fafc 100%);
  text-align: center;
}
.implementation-hub .stat-grid.outputs-stat-grid {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: 14px;
}
.implementation-hub .outputs-stat-grid .stat-card span {
  order: 0;
  min-height: 36px;
  margin: 0 0 10px;
  color: #64748b;
  font-size: 12px;
  font-weight: 650;
  line-height: 1.45;
}
.implementation-hub .outputs-stat-grid .stat-card strong {
  order: 1;
  color: #0f172a;
  font-size: clamp(27px, 2.3vw, 32px);
  line-height: 1;
}
.implementation-hub .outputs-stat-grid .stat-card-link {
  border-left-color: var(--io-accent);
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.implementation-hub .outputs-stat-grid .stat-card-link:hover {
  border-color: var(--io-accent-border);
  border-left-color: var(--io-accent);
  background: #fff;
}
@container implementation (max-width: 520px) {
  .implementation-hub .stat-grid.outputs-stat-grid { gap: 10px; }
  .implementation-hub .outputs-stat-grid .stat-card { min-height: 100px; padding: 15px 16px; }
}
.implementation-hub .hub-panel {
  margin-bottom: 20px;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 5px 18px rgba(30, 41, 59, .035);
}
.implementation-hub .panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 22px;
  border-bottom: 1px solid #eef0f3;
}
.implementation-hub .panel-head h2 {
  margin: 0;
  color: #111827;
  font-size: 17px;
  letter-spacing: -.02em;
}
.implementation-hub .panel-head p {
  max-width: 80ch;
  margin: 6px 0 0;
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
}
.implementation-hub .completed-register-head {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr);
  align-items: center;
  gap: 28px;
}
.implementation-hub .completed-register-summary { min-width: 0; }
.implementation-hub .completed-register-method {
  display: grid;
  min-width: 0;
  border-left: 2px solid var(--io-accent-border);
  padding-left: 20px;
}
.implementation-hub .completed-register-method h3 {
  margin: 0 0 5px;
  color: #334155;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.implementation-hub .completed-register-method p {
  max-width: none;
  margin: 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.55;
}
.implementation-hub .completed-register-method p + p {
  margin-top: 5px;
  padding-top: 5px;
  border-top: 1px solid #eef0f3;
}
.implementation-hub .outputs-register-head {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(280px, .85fr);
  align-items: center;
  gap: 28px;
}
.implementation-hub .outputs-register-summary { min-width: 0; }
.implementation-hub .outputs-register-scope {
  display: grid;
  min-width: 0;
  border-left: 2px solid var(--io-accent-border);
  padding-left: 20px;
}
.implementation-hub .outputs-register-scope h3 {
  margin: 0 0 5px;
  color: #334155;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.implementation-hub .outputs-register-scope p {
  max-width: none;
  margin: 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.55;
}
.implementation-hub .outputs-register-scope ul {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
}
.implementation-hub .outputs-register-scope li {
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  padding: 4px 8px;
  background: #f8fafc;
  color: #475569;
  font-size: 10px;
  font-weight: 650;
}
.implementation-hub .table-wrap { width: 100%; overflow-x: auto; }
.implementation-hub .hub-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 12px;
}
.implementation-hub .hub-table th {
  position: sticky;
  top: 0;
  padding: 12px 14px;
  background: #f8fafc;
  color: #64748b;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.implementation-hub .hub-table td {
  padding: 14px;
  border-top: 1px solid #eef0f3;
  color: #334155;
  vertical-align: middle;
}
.implementation-hub .hub-table tbody tr { transition: background .16s ease; }
.implementation-hub .hub-table tbody tr:hover { background: #fafbfd; }
.implementation-hub .io-title {
  display: inline-block;
  color: #172033;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.45;
  text-decoration: none;
}
.implementation-hub .io-title:hover { color: var(--io-accent-dark); text-decoration: underline; }
.implementation-hub .io-person {
  display: flex;
  align-items: center;
  gap: 7px;
  flex-wrap: wrap;
  color: #334155;
}
.implementation-hub .io-role,
.implementation-hub .io-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 4px 8px;
  background: var(--io-accent-tint);
  color: var(--io-accent-dark);
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
}
.implementation-hub .io-badge.neutral { background: #f1f5f9; color: #475569; }
.implementation-hub .io-badge.warning { background: #fff7ed; color: #c2410c; }
.implementation-hub .io-progress { display: grid; gap: 6px; min-width: 130px; }
.implementation-hub .io-progress-copy { color: #475569; font-size: 11px; font-variant-numeric: tabular-nums; }
.implementation-hub .io-progress-track { height: 6px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
.implementation-hub .io-progress-track span { display: block; height: 100%; border-radius: inherit; background: var(--io-accent); }
.implementation-hub .io-muted { color: #64748b; font-size: 11px; white-space: nowrap; }
.implementation-hub .empty-state {
  display: flex;
  min-height: 200px;
  align-items: center;
  gap: 14px;
  padding: 28px 30px;
  border-radius: 14px;
  background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
  color: #64748b;
}
.implementation-hub .empty-mark {
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  place-items: center;
  border: 1px solid var(--io-accent-border);
  border-radius: 12px;
  background: var(--io-accent-tint);
  color: var(--io-accent-dark);
  font-size: 18px;
}
.implementation-hub .empty-state h3 { margin: 0; color: #111827; font-size: 16px; }
.implementation-hub .empty-state p { max-width: 58ch; margin: 6px 0 0; color: #64748b; font-size: 13px; line-height: 1.65; }
.implementation-hub .io-attention-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding: 16px;
}
.implementation-hub .io-attention-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
  border: 1px solid #f1d7bd;
  border-radius: 11px;
  background: #fffaf5;
}
.implementation-hub .io-attention-item strong { display: block; color: #172033; font-size: 12px; line-height: 1.45; }
.implementation-hub .io-attention-item small { display: block; margin-top: 5px; color: #64748b; font-size: 11px; line-height: 1.5; }
.implementation-hub .io-attention-reason { flex: 0 0 auto; color: #9a3412; font-size: 10px; font-weight: 750; }
.implementation-hub .io-output-type {
  display: inline-flex;
  border-radius: 7px;
  padding: 5px 8px;
  background: #f1f5f9;
  color: #475569;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
}
.implementation-hub .io-filters {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 12px;
  margin: 0 0 20px;
  padding: 18px 20px;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 5px 18px rgba(30, 41, 59, .025);
}
.implementation-hub .io-filter-field { display: grid; flex: 1 1 190px; gap: 6px; min-width: 170px; }
.implementation-hub .io-filter-field span { color: #475569; font-size: 12px; font-weight: 650; }
.implementation-hub .io-filter-field input,
.implementation-hub .io-filter-field select {
  width: 100%;
  min-height: 42px;
  border: 1px solid #cbd5e1;
  border-radius: 9px;
  padding: 9px 12px;
  background: #fff;
  color: #172033;
  font: inherit;
}
.implementation-hub .io-filter-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.implementation-hub .io-button {
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--io-accent);
  border-radius: 9px;
  padding: 0 14px;
  background: var(--io-accent);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  transition: transform .18s ease, background .18s ease, border-color .18s ease;
}
.implementation-hub .io-button.secondary { border-color: #cbd5e1; background: #fff; color: #334155; }
.implementation-hub .io-button:hover { transform: translateY(-1px); filter: brightness(.96); }
.implementation-hub .io-button:active { transform: translateY(0) scale(.98); }
.implementation-hub .io-reset { padding: 9px 4px; color: #475569; font-size: 12px; font-weight: 650; text-decoration: underline; text-underline-offset: 3px; }
.implementation-hub .io-tab:focus-visible,
.implementation-hub .io-button:focus-visible,
.implementation-hub .io-reset:focus-visible,
.implementation-hub .io-title:focus-visible,
.implementation-hub .stat-card-link:focus-visible { outline: 2px solid var(--io-accent); outline-offset: 3px; }
.implementation-hub .io-filter-field input:focus-visible,
.implementation-hub .io-filter-field select:focus-visible { border-color: var(--io-accent); outline: 2px solid var(--io-accent); outline-offset: 2px; }
.implementation-hub .stat-card-link { display: block; color: inherit; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease; }
.implementation-hub .stat-card-link:hover { transform: translateY(-2px); box-shadow: 0 9px 22px rgba(30, 41, 59, .09); }
.implementation-hub .io-metric-note,
.implementation-hub .io-results-summary { margin: -8px 0 18px; color: #64748b; font-size: 12px; line-height: 1.5; }
.implementation-hub .io-results-summary { margin: 0; padding: 12px 18px; border-bottom: 1px solid #eef0f3; }
.implementation-hub .io-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 16px; border-top: 1px solid #eef0f3; }
.implementation-hub .io-page-status { color: #64748b; font-size: 12px; font-weight: 650; }
.implementation-hub .io-notice { margin: 16px 20px 0; border: 1px solid #fed7aa; border-radius: 10px; padding: 12px 14px; background: #fff7ed; color: #9a3412; font-size: 12px; line-height: 1.5; }
.implementation-hub .io-sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; clip-path: inset(50%); }
@container implementation (max-width: 980px) {
  .implementation-hub .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .implementation-hub .io-attention-list { grid-template-columns: 1fr; }
}
@container implementation (max-width: 700px) {
  .implementation-hub .io-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: visible; }
  .implementation-hub .io-tab { min-width: 0; white-space: normal; }
  .implementation-hub .io-intro { gap: 18px; padding: 24px 20px; }
  .implementation-hub .io-stage { align-items: flex-start; flex-direction: column; gap: 10px; }
  .implementation-hub .io-stage-label { flex: auto; }
  .implementation-hub .io-stage-list { width: 100%; }
  .implementation-hub .io-stage-list li { align-items: flex-start; flex-direction: column; gap: 6px; }
  .implementation-hub .completed-register-head { grid-template-columns: minmax(0, 1fr); gap: 14px; }
  .implementation-hub .completed-register-method { border-top: 1px solid #eef0f3; border-left: 0; padding: 13px 0 0; }
  .implementation-hub .outputs-register-head { grid-template-columns: minmax(0, 1fr); gap: 14px; }
  .implementation-hub .outputs-register-scope { border-top: 1px solid #eef0f3; border-left: 0; padding: 13px 0 0; }
}
@container implementation (max-width: 520px) {
  .implementation-hub { padding-bottom: 24px; }
  .implementation-hub .stat-grid { gap: 8px; }
  .implementation-hub .stat-card { padding: 16px; }
  .implementation-hub .stat-card strong { font-size: 23px; }
  .implementation-hub .panel-head { padding: 17px; }
  .implementation-hub .hub-table th,
  .implementation-hub .hub-table td { padding: 11px; }
  .implementation-hub .io-filters { align-items: stretch; padding: 15px; }
  .implementation-hub .io-filter-field { flex-basis: 100%; }
  .implementation-hub .io-filter-actions { width: 100%; }
  .implementation-hub .io-button { flex: 1; }
  .implementation-hub .io-pagination { gap: 8px; }
  .implementation-hub .io-pagination .io-button { flex: 0 1 auto; }
}
@media (prefers-reduced-motion: reduce) {
  .implementation-hub .io-tab,
  .implementation-hub .io-button,
  .implementation-hub .stat-card-link { transition: none; }
  .implementation-hub .io-button:hover,
  .implementation-hub .stat-card-link:hover { transform: none; }
}
</style>

<main class="implementation-hub">
  <?php if ($view !== 'ongoing' && $view !== 'outputs'): ?>
  <nav class="io-tabs" aria-label="Research implementation sections">
    <?php foreach ($tabs as $tabView => $label): ?>
      <?php if ($canViewTab($tabView)): ?>
        <a class="io-tab" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/implementation-outputs.php?view=' . rawurlencode($tabView) . '&module_page=' . rawurlencode($viewConfig[$tabView]['module_page'])) ?>" <?= $view === $tabView ? 'aria-current="page"' : '' ?>>
          <span class="io-tab-index" aria-hidden="true"><?= sprintf('%02d', array_search($tabView, array_keys($tabs), true) + 1) ?></span>
          <?= implementationOutputsEscape($label) ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <header class="io-intro">
    <div>
      <p class="io-eyebrow">Research implementation · portfolio overview</p>
      <h1><?= implementationOutputsEscape($pageTitle) ?></h1>
      <p class="io-intro-copy"><?= implementationOutputsEscape($pageDescription) ?></p>
    </div>
  </header>

  <?php if ($view === 'ongoing'): ?>
    <section class="stat-grid" aria-label="Implementation statistics">
      <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'ongoing', 'module_page' => 'ongoing_research'])) ?>#ongoing-directory-heading" aria-label="View all projects in implementation">
        <strong><?= (int) $stats['in_progress'] ?></strong><span>Projects in progress · view register</span>
      </a>
      <div class="stat-card"><strong><?= (int) $stats['under_review'] ?></strong><span>Chapters awaiting review</span></div>
      <div class="stat-card"><strong><?= (int) $stats['approved'] ?></strong><span>Chapters approved</span></div>
      <div class="stat-card"><strong><?= (int) $stats['returned'] ?></strong><span>Chapters returned</span></div>
    </section>
    <p class="io-metric-note">Metrics show all title-approved projects and chapter activity. Filters below narrow the project lists.</p>

    <form class="io-filters" method="get" action="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/implementation-outputs.php') ?>" aria-label="Filter ongoing research">
      <input type="hidden" name="view" value="ongoing">
      <input type="hidden" name="module_page" value="ongoing_research">
      <label class="io-filter-field"><span>Search project or researcher</span><input type="search" name="q" value="<?= implementationOutputsEscape($searchQuery) ?>" maxlength="150" placeholder="Enter a title or name"></label>
      <label class="io-filter-field"><span>Project status</span>
        <select name="status">
          <?php foreach ($ongoingStatusOptions as $statusValue => $statusLabel): ?>
            <option value="<?= implementationOutputsEscape($statusValue) ?>" <?= $ongoingStatus === $statusValue ? 'selected' : '' ?>><?= implementationOutputsEscape($statusLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="io-filter-actions">
        <button class="io-button" type="submit">Apply filters</button>
        <button class="io-button secondary" type="submit" name="export" value="csv">Export CSV</button>
        <a class="io-reset" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'ongoing', 'module_page' => 'ongoing_research'])) ?>">Clear</a>
      </div>
    </form>

    <section class="hub-panel" aria-labelledby="ongoing-directory-heading">
      <div class="panel-head"><div><h2 id="ongoing-directory-heading">Research in implementation</h2><p><?= count($projects) ?> matching <?= count($projects) === 1 ? 'project' : 'projects' ?> · Titles appear here after title approval.</p></div></div>
      <?php if (!$projects): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">↗</div><div><h3><?= $searchQuery !== '' || $ongoingStatus !== '' ? 'No projects match these filters' : 'No research in implementation yet' ?></h3><p><?= $searchQuery !== '' || $ongoingStatus !== '' ? 'Adjust or clear the filters to see more records.' : 'Projects appear here once a title is approved.' ?></p></div></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table">
          <caption class="io-sr-only">Research projects currently in implementation matching the selected filters</caption>
          <thead><tr><th>Project</th><th>Researcher</th><th>Title approval date</th><th>Chapter progress</th><th>Last chapter activity</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($projects as $project): ?>
              <?php $progress = min(100, (int) round(((int) $project['approved_chapters'] / 5) * 100)); ?>
              <tr>
                <td><a class="io-title" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= implementationOutputsEscape((string) $project['title']) ?></a></td>
                <td><div class="io-person"><span><?= implementationOutputsEscape($project['researcher_name']) ?></span><span class="io-role"><?= implementationOutputsEscape(ucfirst((string) ($project['researcher_role'] ?? 'researcher'))) ?></span></div></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($project['title_approved_at'])) ?></td>
                <td><div class="io-progress"><span class="io-progress-copy"><?= (int) $project['approved_chapters'] ?> / 5 approved</span><span class="io-progress-track" role="img" aria-label="<?= (int) $project['approved_chapters'] ?> of 5 chapters approved"><span style="width:<?= $progress ?>%"></span></span></div></td>
                <td><span class="io-muted"><?= implementationOutputsEscape(implementationOutputsDate($project['latest_activity'])) ?></span></td>
                <td><span class="io-badge"><?= implementationOutputsEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

  <?php elseif ($view === 'completed'): ?>
    <section class="stat-grid" aria-label="Completed research statistics">
      <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'completed', 'module_page' => 'completed_research'])) ?>#completed-directory-heading" aria-label="View all completed research records">
        <strong><?= (int) $stats['completed'] ?></strong><span>Completed research · view register</span>
      </a>
      <div class="stat-card"><strong><?= (int) $stats['completed_this_year'] ?></strong><span>Final manuscripts approved this year</span></div>
      <div class="stat-card"><strong><?= implementationOutputsEscape((string) $stats['average_days']) ?></strong><span>Average days from title approval to final manuscript approval</span></div>
    </section>
    <p class="io-metric-note">Summary metrics retain their all-record definitions; the filters narrow the register below.</p>

    <form class="io-filters" method="get" action="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/implementation-outputs.php') ?>" aria-label="Filter completed research">
      <input type="hidden" name="view" value="completed">
      <input type="hidden" name="module_page" value="completed_research">
      <label class="io-filter-field"><span>Search project or researcher</span><input type="search" name="q" value="<?= implementationOutputsEscape($searchQuery) ?>" maxlength="150" placeholder="Enter a title or name"></label>
      <label class="io-filter-field"><span>Completion / recorded year</span><input type="number" name="year" value="<?= $completedYear > 0 ? $completedYear : '' ?>" min="1900" max="9999" inputmode="numeric" placeholder="Any year"></label>
      <label class="io-filter-field"><span>Project status</span>
        <select name="status">
          <option value="">All statuses</option>
          <?php foreach ($completedStatusOptions as $statusValue => $statusLabel): ?>
            <option value="<?= implementationOutputsEscape($statusValue) ?>" <?= $completedStatus === $statusValue ? 'selected' : '' ?>><?= implementationOutputsEscape($statusLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="io-filter-actions">
        <button class="io-button" type="submit">Apply filters</button>
        <button class="io-button secondary" type="submit" name="export" value="csv">Export CSV</button>
        <a class="io-reset" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'completed', 'module_page' => 'completed_research'])) ?>">Clear</a>
      </div>
    </form>

    <section class="hub-panel" aria-labelledby="completed-directory-heading">
      <div class="panel-head completed-register-head">
        <div class="completed-register-summary">
          <h2 id="completed-directory-heading">Completed research register</h2>
          <p><?= count($completedProjects) ?> matching <?= count($completedProjects) === 1 ? 'record' : 'records' ?>. Completed research requires all five approved chapters and an approved complete manuscript.</p>
        </div>
        <aside class="completed-register-method" aria-label="How this register is calculated">
          <h3>How this register is calculated</h3>
          <p><strong>Year filter:</strong> Uses the final-manuscript approval date. The yearly metric counts projects whose final manuscript was approved this year.</p>
          <p><strong>Average duration:</strong> Runs from approved-title submission (or project creation when unavailable) to final-manuscript approval.</p>
        </aside>
      </div>
      <?php if (!$completedProjects): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">✓</div><div><h3><?= $searchQuery !== '' || $completedStatus !== '' || $completedYear > 0 ? 'No records match these filters' : 'No completed research yet' ?></h3><p><?= $searchQuery !== '' || $completedStatus !== '' || $completedYear > 0 ? 'Adjust or clear the filters to see more records.' : 'Projects appear here after all five chapters and the complete manuscript are approved.' ?></p></div></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table">
          <caption class="io-sr-only">Completed research records matching the selected filters</caption>
          <thead><tr><th>Project</th><th>Researcher</th><th>Completion / recorded date</th><th>Chapter tally</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($completedProjects as $project): ?>
              <tr>
                <td><a class="io-title" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= implementationOutputsEscape((string) $project['title']) ?></a></td>
                <td><div class="io-person"><span><?= implementationOutputsEscape($project['researcher_name']) ?></span><span class="io-role"><?= implementationOutputsEscape(ucfirst((string) ($project['researcher_role'] ?? 'researcher'))) ?></span></div></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($project['completed_at'])) ?></td>
                <td><span class="io-badge neutral"><?= (int) $project['approved_chapters'] ?> / <?= (int) $project['total_chapters'] ?> approved</span></td>
                <td><span class="io-badge"><?= implementationOutputsEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <form class="io-filters" method="get" action="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/implementation-outputs.php') ?>" aria-label="Filter research outputs">
      <input type="hidden" name="view" value="outputs">
      <input type="hidden" name="module_page" value="research_outputs">
      <label class="io-filter-field"><span>Search output or researcher / applicant</span><input type="search" name="q" value="<?= implementationOutputsEscape($searchQuery) ?>" maxlength="150" placeholder="Enter a title or name"></label>
      <label class="io-filter-field"><span>Record type</span>
        <select name="type">
          <option value="">All available types</option>
          <?php foreach ($outputTypeOptions as $typeValue => $typeLabel): ?>
            <option value="<?= implementationOutputsEscape($typeValue) ?>" <?= $outputType === $typeValue ? 'selected' : '' ?>><?= implementationOutputsEscape($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="io-filter-field"><span>Publication / registration / record year</span><input type="number" name="year" value="<?= $outputYear > 0 ? $outputYear : '' ?>" min="1900" max="9999" inputmode="numeric" placeholder="Any year"></label>
      <div class="io-filter-actions">
        <button class="io-button" type="submit">Apply filters</button>
        <button class="io-button secondary" type="submit" name="export" value="csv">Export CSV</button>
        <a class="io-reset" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'outputs', 'module_page' => 'research_outputs'])) ?>">Clear</a>
      </div>
    </form>

    <section class="stat-grid outputs-stat-grid" aria-label="Research output statistics">
      <?php if ($publicationRecordsAvailable): ?>
        <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'outputs', 'module_page' => 'research_outputs', 'type' => 'publication'])) ?>" aria-label="View all active publication records">
          <strong><?= (int) $stats['publications'] ?></strong><span>Publications</span>
        </a>
      <?php else: ?>
        <div class="stat-card"><strong>Unavailable</strong><span>Publications</span></div>
      <?php endif; ?>
      <?php if ($registeredAvailable && $canViewPatentCopyrights): ?>
        <?php
          $patentCount = 0;
          $patentThisYear = 0;
          $patentResult = implementationOutputsQuery(
              $conn,
              "SELECT COUNT(*) AS total,
                      COALESCE(SUM(YEAR(COALESCE(registered_at, created_at)) = YEAR(CURDATE())), 0) AS this_year
                 FROM copyright_applications c
                WHERE deactivated_at IS NULL AND ({$patentOutputTypesSql})
                  AND {$registeredOutputStatusSql}{$copyrightScopeSql}"
          );
          $patentRow = $patentResult->fetch_assoc() ?: [];
          $patentResult->free();
          $patentCount = (int) ($patentRow['total'] ?? 0);
          $patentThisYear = (int) ($patentRow['this_year'] ?? 0);
        ?>
        <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'outputs', 'module_page' => 'research_outputs', 'type' => 'patents_utility_models'])) ?>" aria-label="View patents & utility models register">
          <strong><?= $patentCount ?></strong><span>Patents / utility models</span>
        </a>
      <?php elseif ($registeredAvailable && !$canViewPatentCopyrights && $canViewCopyrights): ?>
        <div class="stat-card"><strong>Restricted</strong><span>Patents / utility models</span></div>
      <?php endif; ?>
      <?php if ($registeredAvailable && $canViewInnovationCopyrights): ?>
        <?php
          $innovationCount = 0;
          $innovationThisYear = 0;
          $innovationResult = implementationOutputsQuery(
              $conn,
              "SELECT COUNT(*) AS total,
                      COALESCE(SUM(YEAR(COALESCE(registered_at, created_at)) = YEAR(CURDATE())), 0) AS this_year
                 FROM copyright_applications c
                WHERE deactivated_at IS NULL AND ({$innovationOutputTypesSql})
                  AND {$registeredOutputStatusSql}{$copyrightScopeSql}"
          );
          $innovationRow = $innovationResult->fetch_assoc() ?: [];
          $innovationResult->free();
          $innovationCount = (int) ($innovationRow['total'] ?? 0);
          $innovationThisYear = (int) ($innovationRow['this_year'] ?? 0);
        ?>
        <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'outputs', 'module_page' => 'research_outputs', 'type' => 'innovation_technology_transfer'])) ?>" aria-label="View innovation & technology transfer register">
          <strong><?= $innovationCount ?></strong><span>Innovation / tech transfer</span>
        </a>
      <?php elseif ($registeredAvailable && !$canViewInnovationCopyrights && $canViewCopyrights): ?>
        <div class="stat-card"><strong>Restricted</strong><span>Innovation / tech transfer</span></div>
      <?php endif; ?>
      <?php if ($registeredAvailable && ($canViewAllCopyrights || (!$canViewPatentCopyrights && !$canViewInnovationCopyrights))): ?>
        <?php
          $otherCopyrightCount = 0;
          $otherCopyrightThisYear = 0;
          $otherCopyrightResult = implementationOutputsQuery(
              $conn,
              "SELECT COUNT(*) AS total,
                      COALESCE(SUM(YEAR(COALESCE(registered_at, created_at)) = YEAR(CURDATE())), 0) AS this_year
                 FROM copyright_applications c
                WHERE deactivated_at IS NULL AND ({$genericCopyrightTypesSql})
                  AND {$registeredOutputStatusSql}{$copyrightScopeSql}"
          );
          $otherCopyrightRow = $otherCopyrightResult->fetch_assoc() ?: [];
          $otherCopyrightResult->free();
          $otherCopyrightCount = (int) ($otherCopyrightRow['total'] ?? 0);
          $otherCopyrightThisYear = (int) ($otherCopyrightRow['this_year'] ?? 0);
        ?>
        <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl(['view' => 'outputs', 'module_page' => 'research_outputs', 'type' => 'copyright'])) ?>" aria-label="<?= $canViewAllCopyrights ? 'View all registered copyrights' : 'View registered copyrights available through your authorized modules' ?>">
          <strong><?= $otherCopyrightCount ?></strong><span><?= $canViewAllCopyrights ? 'Other copyrights' : 'Authorized copyrights' ?></span>
        </a>
      <?php elseif ($registeredAvailable && $canViewCopyrights): ?>
        <div class="stat-card"><strong><?= $otherCopyrightCount ?? 'Unavailable' ?></strong><span>Copyrights</span></div>
      <?php endif; ?>
      <?php if ($publicationRecordsAvailable || $registeredAvailable): ?>
        <?php
          $thisYearTotal = ($publicationRecordsAvailable ? $publicationRecordsThisYear : 0) +
                           ($canViewPatentCopyrights ? ($patentThisYear ?? 0) : 0) +
                           ($canViewInnovationCopyrights ? ($innovationThisYear ?? 0) : 0) +
                           ($canViewAllCopyrights || (!$canViewPatentCopyrights && !$canViewInnovationCopyrights && $canViewCopyrights) ? ($otherCopyrightThisYear ?? 0) : 0);
          $yearMetricParams = ['view' => 'outputs', 'module_page' => 'research_outputs', 'year' => $outputMetricYear];
          if ($yearMetricType !== '') {
              $yearMetricParams['type'] = $yearMetricType;
          }
        ?>
        <a class="stat-card stat-card-link" href="<?= implementationOutputsEscape(implementationOutputsUrl($yearMetricParams)) ?>" aria-label="View outputs dated this year">
          <strong><?= $thisYearTotal ?></strong><span><?= implementationOutputsEscape($yearMetricLabel) ?></span>
        </a>
      <?php else: ?>
        <div class="stat-card"><strong>Unavailable</strong><span><?= implementationOutputsEscape($yearMetricLabel) ?></span></div>
      <?php endif; ?>
    </section>
    <p class="io-metric-note">Summary metrics cover available records you are permitted to view. When a publication or registration date is missing, the record creation date is used for the year filter and display.</p>

    <section class="hub-panel">
      <?php if (!$publicationsAvailable || ($canViewCopyrights && !$copyrightsAvailable)): ?>
        <div class="io-notice" role="status">Some output records are unavailable because a required register table is missing. Available permitted records remain listed below.</div>
      <?php endif; ?>
      <?php if (!$outputRecords): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">↗</div><div><h3><?= $searchQuery !== '' || $outputType !== '' || $outputYear > 0 ? 'No output records match these filters' : 'No active publication or registered output records available' ?></h3><p><?= $searchQuery !== '' || $outputType !== '' || $outputYear > 0 ? 'Adjust or clear the filters to see more records.' : 'Active publication records and registered copyright records will appear here when available.' ?></p></div></div>
      <?php else: ?>
        <?php $firstOutput = (($outputPage - 1) * $outputPageSize) + 1; $lastOutput = min($outputPage * $outputPageSize, $outputTotal); ?>
        <p class="io-results-summary">Showing <?= $firstOutput ?>–<?= $lastOutput ?> of <?= $outputTotal ?> matching <?= $outputTotal === 1 ? 'record' : 'records' ?>.</p>
        <div class="table-wrap"><table class="hub-table">
          <caption class="io-sr-only">Active publication records and registered copyrights matching the selected filters</caption>
          <thead><tr><th>Type</th><th>Output</th><th>Researcher / applicant</th><th>Output date (publication, registration, or record)</th><th>Register</th></tr></thead>
          <tbody>
            <?php foreach ($outputRecords as $output): ?>
              <?php
                $typeLabel = (string) $output['type_label'];
                if (($output['record_type'] ?? '') === 'copyright') {
                    $recordScope = trim((string) ($output['record_scope'] ?? ''));
                    if ($recordScope !== '') {
                        $typeLabel = ucwords(str_replace('_', ' ', $recordScope));
                    }
                }
              ?>
              <tr>
                <td><span class="io-output-type"><?= implementationOutputsEscape($typeLabel) ?></span></td>
                <td><?= implementationOutputsEscape((string) $output['record_title']) ?></td>
                <td><?= implementationOutputsEscape((string) $output['person_name']) ?></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($output['event_date'])) ?></td>
                <td><a class="io-title" href="<?= implementationOutputsEscape((string) $output['link_url']) ?>">Open register</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($outputPageCount > 1): ?>
          <?php
            $outputPageParams = ['view' => 'outputs', 'module_page' => 'research_outputs'];
            if ($searchQuery !== '') {
                $outputPageParams['q'] = $searchQuery;
            }
            if ($outputType !== '') {
                $outputPageParams['type'] = $outputType;
            }
            if ($outputYear > 0) {
                $outputPageParams['year'] = $outputYear;
            }
          ?>
          <nav class="io-pagination" aria-label="Research output pages">
            <?php if ($outputPage > 1): ?>
              <?php $previousPageParams = $outputPageParams; $previousPageParams['page'] = $outputPage - 1; ?>
              <a class="io-button secondary" href="<?= implementationOutputsEscape(implementationOutputsUrl($previousPageParams)) ?>">Previous</a>
            <?php endif; ?>
            <span class="io-page-status">Page <?= $outputPage ?> of <?= $outputPageCount ?></span>
            <?php if ($outputPage < $outputPageCount): ?>
              <?php $nextPageParams = $outputPageParams; $nextPageParams['page'] = $outputPage + 1; ?>
              <a class="io-button secondary" href="<?= implementationOutputsEscape(implementationOutputsUrl($nextPageParams)) ?>">Next</a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
