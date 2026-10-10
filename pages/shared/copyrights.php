<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function copyrightsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function copyrightsRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function copyrightsStatusBadge(string $status): array
{
    return match ($status) {
        'pending' => ['label' => 'Pending', 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'registered' => ['label' => 'Registered', 'tone' => 'success'],
        'rejected' => ['label' => 'Rejected', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'copyright_applications'");
$copyrightsAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) {
    $tableCheck->free();
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$staffCopyrightManagePages = [
    'copyrights' => $role === 'research_staff' && hasModulePermission($userId, 'intellectual_property_utilization', 'manage', 'copyrights'),
    'patents_utility_models' => $role === 'research_staff' && hasModulePermission($userId, 'intellectual_property_utilization', 'manage', 'patents_utility_models'),
    'innovation_technology_transfer' => $role === 'research_staff' && hasModulePermission($userId, 'intellectual_property_utilization', 'manage', 'innovation_technology_transfer'),
];
$staffCanManageCopyrightType = static function (string $type) use ($staffCopyrightManagePages): bool {
    $pages = match ($type) {
        'patent', 'utility_model' => ['patents_utility_models'],
        'innovation', 'technology_transfer' => ['innovation_technology_transfer'],
        default => ['copyrights'],
    };
    foreach ($pages as $pageKey) {
        if ($staffCopyrightManagePages[$pageKey] ?? false) {
            return true;
        }
    }
    return false;
};
$ownScope = in_array($role, ['faculty', 'student'], true);
$canReview = $role === 'admin';
$flash = $_SESSION['copyrights_flash'] ?? null;
unset($_SESSION['copyrights_flash']);
$statusOptions = ['pending' => 'Pending', 'under_review' => 'Under review', 'registered' => 'Registered', 'rejected' => 'Rejected'];
$typeOptions = [
    'software' => 'Software',
    'research' => 'Research',
    'instructional_material' => 'Instructional material',
    'module' => 'Module',
    'other' => 'Other',
    'patent' => 'Patent',
    'utility_model' => 'Utility model',
    'innovation' => 'Innovation',
    'technology_transfer' => 'Technology transfer',
];
$query = trim((string) ($_GET['q'] ?? ''));
$query = strlen($query) > 150 ? substr($query, 0, 150) : $query;
$statusInput = trim((string) ($_GET['status'] ?? ''));
$status = preg_match('/^(pending|under_review|registered|rejected)$/D', $statusInput) ? $statusInput : '';
$typeInput = trim((string) ($_GET['output_type'] ?? ''));
$outputType = preg_match('/^(software|research|instructional_material|module|other|patent|utility_model|innovation|technology_transfer)$/D', $typeInput) ? $typeInput : '';
$ipScopeInput = trim((string) ($_GET['ip_scope'] ?? ''));
$ipScope = preg_match('/^(patents_utility_models|innovation_technology_transfer)$/D', $ipScopeInput) ? $ipScopeInput : '';
$scopeOutputTypes = match ($ipScope) {
    'patents_utility_models' => ['patent', 'utility_model'],
    'innovation_technology_transfer' => ['innovation', 'technology_transfer'],
    default => [],
};
$staffPageKey = '';
$staffScopeSql = '';
if ($role === 'research_staff') {
    $hasCopyrightAccess = hasModulePermission($userId, 'intellectual_property_utilization', 'view', 'copyrights');
    if ($ipScope !== '') {
        $staffPageKey = $ipScope;
        $staffScopeSql = ' AND output_type IN (' . implode(',', array_map(static fn(string $type): string => "'{$type}'", $scopeOutputTypes)) . ')';
    } elseif ($outputType !== '') {
        $staffPageKey = 'copyrights';
    } elseif ($hasCopyrightAccess) {
        $staffPageKey = 'copyrights';
    } else {
        foreach (['patents_utility_models', 'innovation_technology_transfer'] as $candidatePage) {
            if (hasModulePermission($userId, 'intellectual_property_utilization', 'view', $candidatePage)) {
                header('Location: ' . SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($candidatePage));
                exit;
            }
        }
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    requireModulePermission('intellectual_property_utilization', 'view', $staffPageKey);
    if ($staffPageKey === 'patents_utility_models') {
        $ipScope = 'patents_utility_models';
        $scopeOutputTypes = ['patent', 'utility_model'];
        $staffScopeSql = " AND output_type IN ('patent', 'utility_model')";
        $typeOptions = ['patent' => 'Patent', 'utility_model' => 'Utility model'];
    } elseif ($staffPageKey === 'innovation_technology_transfer') {
        $ipScope = 'innovation_technology_transfer';
        $scopeOutputTypes = ['innovation', 'technology_transfer'];
        $staffScopeSql = " AND output_type IN ('innovation', 'technology_transfer')";
        $typeOptions = ['innovation' => 'Innovation', 'technology_transfer' => 'Technology transfer'];
    }
    $canReview = hasModulePermission($userId, 'intellectual_property_utilization', 'manage', $staffPageKey);
    if ($staffPageKey === 'copyrights') {
        foreach (['copyrights', 'patents_utility_models', 'innovation_technology_transfer'] as $managePage) {
            $canReview = $canReview || hasModulePermission($userId, 'intellectual_property_utilization', 'manage', $managePage);
        }
    }
}
if ($role !== 'research_staff' && $scopeOutputTypes !== []) {
    $staffScopeSql = ' AND output_type IN (' . implode(',', array_map(static fn(string $type): string => "'{$type}'", $scopeOutputTypes)) . ')';
}
$filtersActive = $query !== '' || $status !== '' || $outputType !== '' || $ipScope !== '';

$view = match (true) {
    $status === 'registered' => 'records',
    $status === 'under_review' => 'monitoring',
    $outputType === 'research' => 'outputs',
    default => 'applications',
};

$stats = ['total' => 0, 'pending' => 0, 'registered' => 0];
$outputCounts = array_fill_keys(array_keys($typeOptions), 0);
$registeredStats = ['total' => 0, 'this_year' => 0, 'top_type' => '', 'top_type_count' => 0];
$stageCounts = array_fill_keys(array_keys($statusOptions), 0);
$applications = [];
$registryRecords = [];
if ($copyrightsAvailable) {
    $statsSql = "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'pending'), 0) AS pending, COALESCE(SUM(status = 'registered'), 0) AS registered FROM copyright_applications";
    if ($ownScope) {
        $statsSql .= ' WHERE applicant_id = ?';
    } elseif ($staffScopeSql !== '') {
        $statsSql .= ' WHERE 1 = 1';
    }
    $statsSql .= $staffScopeSql;
    $stmt = $conn->prepare($statsSql);
    $statsRow = copyrightsRun($stmt, $ownScope ? 'i' : '', $ownScope ? [$userId] : [])->fetch_assoc() ?: [];
    foreach (array_keys($stats) as $key) {
        $stats[$key] = (int) ($statsRow[$key] ?? 0);
    }
    $stmt->close();

    $scopeTypes = $ownScope ? 'i' : '';
    $scopeParams = $ownScope ? [$userId] : [];

    $outputSql = 'SELECT output_type, COUNT(*) AS total FROM copyright_applications';
    if ($ownScope) $outputSql .= ' WHERE applicant_id = ?';
    elseif ($staffScopeSql !== '') $outputSql .= ' WHERE 1 = 1';
    $outputSql .= $staffScopeSql;
    $outputSql .= ' GROUP BY output_type';
    $stmt = $conn->prepare($outputSql);
    $result = copyrightsRun($stmt, $scopeTypes, $scopeParams);
    while ($row = $result->fetch_assoc()) {
        if (isset($outputCounts[$row['output_type']])) $outputCounts[$row['output_type']] = (int) $row['total'];
    }
    $stmt->close();

    $yearStart = date('Y') . '-01-01 00:00:00';
    $registeredSql = "SELECT COUNT(*) AS total, COALESCE(SUM(registered_at >= ?), 0) AS this_year FROM copyright_applications WHERE status = 'registered'";
    if ($ownScope) $registeredSql .= ' AND applicant_id = ?';
    $registeredSql .= $staffScopeSql;
    $stmt = $conn->prepare($registeredSql);
    $registeredRow = copyrightsRun($stmt, 's' . $scopeTypes, array_merge([$yearStart], $scopeParams))->fetch_assoc() ?: [];
    $registeredStats['total'] = (int) ($registeredRow['total'] ?? 0);
    $registeredStats['this_year'] = (int) ($registeredRow['this_year'] ?? 0);
    $stmt->close();

    $topTypeSql = "SELECT output_type, COUNT(*) AS total FROM copyright_applications WHERE status = 'registered' AND registered_at >= ?";
    if ($ownScope) $topTypeSql .= ' AND applicant_id = ?';
    $topTypeSql .= $staffScopeSql;
    $topTypeSql .= ' GROUP BY output_type ORDER BY total DESC, output_type ASC LIMIT 1';
    $stmt = $conn->prepare($topTypeSql);
    $topTypeRow = copyrightsRun($stmt, 's' . $scopeTypes, array_merge([$yearStart], $scopeParams))->fetch_assoc() ?: [];
    $registeredStats['top_type'] = (string) ($topTypeRow['output_type'] ?? '');
    $registeredStats['top_type_count'] = (int) ($topTypeRow['total'] ?? 0);
    $stmt->close();

    $stageSql = 'SELECT status, COUNT(*) AS total FROM copyright_applications';
    if ($ownScope) $stageSql .= ' WHERE applicant_id = ?';
    elseif ($staffScopeSql !== '') $stageSql .= ' WHERE 1 = 1';
    $stageSql .= $staffScopeSql;
    $stageSql .= ' GROUP BY status';
    $stmt = $conn->prepare($stageSql);
    $result = copyrightsRun($stmt, $scopeTypes, $scopeParams);
    while ($row = $result->fetch_assoc()) {
        if (isset($stageCounts[$row['status']])) $stageCounts[$row['status']] = (int) $row['total'];
    }
    $stmt->close();

    $registrySql = "SELECT copyright_id, copyright_ref_no, output_title, applicant_name, output_type, registered_at FROM copyright_applications WHERE status = 'registered' AND copyright_ref_no IS NOT NULL AND TRIM(copyright_ref_no) <> ''";
    if ($ownScope) $registrySql .= ' AND applicant_id = ?';
    $registrySql .= $staffScopeSql;
    $registrySql .= ' ORDER BY copyright_ref_no ASC, copyright_id ASC LIMIT 250';
    $stmt = $conn->prepare($registrySql);
    $result = copyrightsRun($stmt, $scopeTypes, $scopeParams);
    while ($row = $result->fetch_assoc()) $registryRecords[] = $row;
    $stmt->close();

    $sql = 'SELECT c.copyright_id, c.applicant_id, c.research_project_id, c.publication_id, c.copyright_ref_no, c.output_title, c.applicant_name, c.applicant_category, c.output_type, c.status, c.created_at, rp.title AS research_project_title, p.research_title AS publication_title FROM copyright_applications c LEFT JOIN research_projects rp ON rp.project_id = c.research_project_id LEFT JOIN publications p ON p.publication_id = c.publication_id WHERE 1 = 1';
    $params = [];
    $types = '';
    if ($ownScope) {
        $sql .= ' AND c.applicant_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    $sql .= $staffScopeSql;
    if ($view === 'records') {
        $sql .= " AND status = 'registered'";
    } elseif ($view === 'monitoring') {
        $sql .= " AND status IN ('pending', 'under_review')";
    }
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = '%' . $escaped . '%';
        $sql .= " AND (c.output_title LIKE ? ESCAPE '\\\\' OR c.applicant_name LIKE ? ESCAPE '\\\\' OR c.copyright_ref_no LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    $statusIsViewPreset = ($view === 'records' && $status === 'registered')
        || ($view === 'monitoring' && $status === 'under_review');
    if ($status !== '' && !$statusIsViewPreset) {
        $sql .= ' AND c.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($outputType !== '' && !($view === 'outputs' && $outputType === 'research')) {
        $sql .= ' AND c.output_type = ?';
        $params[] = $outputType;
        $types .= 's';
    }
    $sql .= $view === 'outputs'
        ? ' ORDER BY c.output_type ASC, c.created_at DESC, c.copyright_id DESC LIMIT 250'
        : ' ORDER BY c.created_at DESC, c.copyright_id DESC LIMIT 250';
    $stmt = $conn->prepare($sql);
    $result = copyrightsRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    $stmt->close();
}

$topOutputTypeLabel = $registeredStats['top_type'] !== ''
    ? ($typeOptions[$registeredStats['top_type']] ?? ucwords(str_replace('_', ' ', $registeredStats['top_type'])))
    : 'No registrations this year';
$maxStageCount = max($stageCounts ?: [0]);
$viewConfig = [
    'applications' => [
        'title' => 'Copyright Applications',
        'kicker' => 'Intellectual property',
        'description' => $ownScope ? 'Review the progress and registration details of your submitted creative and research outputs.' : 'Monitor copyright, patent, utility-model, innovation, and technology-transfer applications.',
        'stats' => [
            ['value' => $stats['total'], 'label' => 'Total applications'],
            ['value' => $stats['pending'], 'label' => 'Pending'],
            ['value' => $stats['registered'], 'label' => 'Registered'],
        ],
        'stats_mode' => 'cards',
        'panel' => 'none',
        'shell_page' => SITE_URL . 'pages/shared/copyrights.php',
        'directory_title' => $ownScope ? 'My applications' : 'Application directory',
    ],
    'records' => [
        'title' => 'Copyright Records',
        'kicker' => 'Copyright registry',
        'description' => $ownScope ? 'Review your registered outputs and their assigned reference numbers.' : 'Browse registered outputs and their official reference numbers.',
        'stats' => [
            ['value' => $registeredStats['total'], 'label' => 'Registered total'],
            ['value' => $registeredStats['this_year'], 'label' => 'Registered this year'],
            ['value' => $registeredStats['top_type_count'], 'label' => 'Top type this year', 'detail' => $topOutputTypeLabel],
        ],
        'stats_mode' => 'cards',
        'panel' => 'registry',
        'panel_title' => 'Registry',
        'shell_page' => SITE_URL . 'pages/shared/copyrights.php?status=registered',
        'directory_title' => $ownScope ? 'My registered outputs' : 'Registered applications',
    ],
    'outputs' => [
        'title' => 'Intellectual Outputs',
        'kicker' => 'Output portfolio',
        'description' => $ownScope ? 'Review the range of outputs associated with your applications.' : 'Review the research office’s software, research, instructional, and other outputs.',
        'stats' => array_map(static fn(string $typeKey, string $typeLabel): array => [
            'value' => $outputCounts[$typeKey],
            'label' => $typeLabel,
        ], array_keys($typeOptions), array_values($typeOptions)),
        'stats_mode' => 'rows',
        'panel' => 'registered_breakdown',
        'panel_title' => 'Registered output breakdown',
        'shell_page' => SITE_URL . 'pages/shared/copyrights.php?output_type=research',
        'directory_title' => $ownScope ? 'My output applications' : 'All output applications',
    ],
    'monitoring' => [
        'title' => 'Copyright Monitoring',
        'kicker' => 'Application pipeline',
        'description' => 'Track applications as they move through review, registration, or rejection.',
        'stats' => [
            ['value' => $stageCounts['pending'], 'label' => 'Pending'],
            ['value' => $stageCounts['under_review'], 'label' => 'Under review'],
            ['value' => $stageCounts['registered'], 'label' => 'Registered'],
            ['value' => $stageCounts['rejected'], 'label' => 'Rejected'],
        ],
        'stats_mode' => 'cards',
        'panel' => 'pipeline',
        'panel_title' => 'Stage pipeline',
        'shell_page' => SITE_URL . 'pages/shared/copyrights.php?status=under_review',
        'directory_title' => $ownScope ? 'My active applications' : 'Active applications',
    ],
];
$pageConfig = $viewConfig[$view];
if ($staffPageKey === 'patents_utility_models' || $ipScope === 'patents_utility_models') {
    $pageConfig['title'] = 'Patents / Utility Models';
    $pageConfig['kicker'] = 'Intellectual property';
    $pageConfig['description'] = 'Manage patent and utility-model applications with their inventors, filing details, and supporting records.';
    $pageConfig['directory_title'] = 'Patent and utility-model records';
} elseif ($staffPageKey === 'innovation_technology_transfer' || $ipScope === 'innovation_technology_transfer') {
    $pageConfig['title'] = 'Innovation & Technology Transfer';
    $pageConfig['kicker'] = 'Research utilization';
    $pageConfig['description'] = 'Manage innovation and technology-transfer records connected to research projects and outputs.';
    $pageConfig['directory_title'] = 'Innovation and technology-transfer records';
}

$showReferenceColumn = false;
foreach ($applications as $application) {
    if (trim((string) ($application['copyright_ref_no'] ?? '')) !== '') {
        $showReferenceColumn = true;
        break;
    }
}

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
if ($shell === 'admin') {
    $originalGet = $_GET;
    if ($view === 'applications') unset($_GET['status'], $_GET['output_type']);
    renderAdminShell($user, $pageConfig['shell_page'], $pageConfig['title'], $pageConfig['description']);
    $_GET = $originalGet;
} elseif ($shell === 'staff') renderStaffShell($user, 'copyrights.php', $pageConfig['title'], $pageConfig['description']);
elseif ($shell === 'faculty') renderFacultyShell($user, 'copyrights.php', $pageConfig['title'], $pageConfig['description']);
else renderStudentShell($user, 'copyrights.php', $pageConfig['title'], $pageConfig['description']);
?>

<style>
.module-hub{max-width:1320px;margin:0 auto;color:#172033}.module-intro{display:flex;justify-content:space-between;gap:24px;align-items:end;margin:0 0 24px;padding:28px 30px;border-left:5px solid #9a5b13;border-radius:4px 18px 18px 4px;background:#fbf4e8}.module-kicker{margin:0 0 7px;color:#9a5b13;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-intro h1{margin:0;font-size:clamp(25px,3vw,38px);line-height:1.08;letter-spacing:-.035em}.module-intro p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}.stat-card{padding:20px 22px;border:1px solid #e9e1d5;border-radius:14px;background:#fff}.stat-card strong{display:block;font-size:30px;line-height:1;font-variant-numeric:tabular-nums}.stat-card span{display:block;margin-top:8px;color:#64748b;font-size:12px;font-weight:700}.hub-panel{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.filter-form{display:grid;grid-template-columns:minmax(220px,1fr) 170px 210px auto;gap:12px;align-items:end;padding:20px}.field label{display:block;margin-bottom:6px;color:#526176;font-size:11px;font-weight:800}.field input,.field select{width:100%;min-height:43px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:13px}.field input:focus,.field select:focus,.hub-button:focus-visible,.row-link:focus-visible{border-color:#9a5b13;outline:3px solid rgba(154,91,19,.12);outline-offset:2px}.filter-actions{display:flex;gap:8px}.hub-button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;box-sizing:border-box;border:1px solid #9a5b13;border-radius:9px;padding:9px 15px;background:#9a5b13;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.hub-button:hover{background:#79450b;color:#fff;transform:translateY(-1px)}.hub-button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.table-wrap{overflow-x:auto}.hub-table{width:100%;min-width:1050px;border-collapse:collapse}.hub-table th{padding:12px 16px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;letter-spacing:.06em;text-transform:uppercase}.hub-table td{padding:15px 16px;border-top:1px solid #edf1f5;font-size:13px;vertical-align:middle}.title-cell{max-width:330px;font-weight:750;line-height:1.4}.title-cell a{color:#172033;text-decoration:none}.title-cell a:hover{color:#9a5b13}.muted{color:#64748b}.ref{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:#526176;font-size:12px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.row-actions{display:flex;gap:9px;white-space:nowrap}.row-link{color:#9a5b13;font-size:11px;font-weight:800;text-decoration:none}.flash{margin-bottom:18px;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.empty-state{padding:48px 24px;text-align:center}.empty-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border-radius:13px;background:#f8ecd8;color:#9a5b13;font-size:21px}.empty-state h2{margin:0;font-size:17px}.empty-state p{max-width:480px;margin:7px auto 0;color:#64748b;font-size:13px;line-height:1.55}@media(max-width:980px){.filter-form{grid-template-columns:1fr 1fr}}@media(max-width:640px){.module-intro{display:block;padding:24px 21px}.module-intro .hub-button{margin-top:18px}.stat-grid,.filter-form{grid-template-columns:1fr}.filter-actions,.hub-button{width:100%}}
</style>

<style>
.stat-grid-four{grid-template-columns:repeat(4,minmax(0,1fr))}
.stat-card small{display:block;margin-top:7px;color:#64748b;font-size:10px;line-height:1.5}
.intellectual-output-list{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:18px}
.intellectual-output-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px;border:1px solid #eee5d8;border-radius:10px;background:#fffdf9;color:#526176;font-size:12px}
.intellectual-output-row strong{color:#172033;font-size:18px;font-variant-numeric:tabular-nums}
.pipeline-list{display:grid;gap:17px;padding:22px 24px 24px}
.pipeline-row{display:grid;grid-template-columns:minmax(150px,190px) minmax(0,1fr);align-items:center;gap:18px}
.pipeline-label{display:flex;justify-content:space-between;gap:12px;color:#526176;font-size:12px}
.pipeline-label strong{color:#172033;font-variant-numeric:tabular-nums}
.pipeline-track{height:10px;overflow:hidden;border-radius:99px;background:#f1ece4}
.pipeline-track span{display:block;height:100%;border-radius:inherit;background:#9a5b13;transition:width .25s ease}
@media(max-width:980px){.stat-grid-four{grid-template-columns:repeat(2,minmax(0,1fr))}.intellectual-output-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.stat-grid-four,.intellectual-output-list{grid-template-columns:1fr}.pipeline-row{grid-template-columns:1fr;gap:7px}.pipeline-list{padding:18px}}
</style>

<div class="module-hub">
  <header class="module-intro"><div><p class="module-kicker"><?= copyrightsEscape($pageConfig['kicker']) ?></p><h1><?= copyrightsEscape($pageConfig['title']) ?></h1><p><?= copyrightsEscape($pageConfig['description']) ?></p></div><?php if ($role !== 'research_staff' || $canReview): ?><a class="hub-button" href="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyright-form.php' . ($ipScope !== '' ? '?ip_scope=' . rawurlencode($ipScope) : '')) ?>">+ Apply</a><?php endif; ?></header>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= copyrightsEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <?php if ($pageConfig['stats_mode'] === 'rows'): ?>
    <section class="hub-panel" aria-labelledby="intellectual-outputs-heading"><div class="panel-head"><div><h2 id="intellectual-outputs-heading">Intellectual outputs</h2><p>Application counts by output type<?= $ownScope ? ' in your portfolio' : '' ?>.</p></div></div><div class="intellectual-output-list"><?php foreach ($pageConfig['stats'] as $row): ?><div class="intellectual-output-row"><span><?= copyrightsEscape($row['label']) ?></span><strong><?= (int) $row['value'] ?></strong></div><?php endforeach; ?></div></section>
  <?php else: ?>
    <section class="stat-grid<?= count($pageConfig['stats']) === 4 ? ' stat-grid-four' : '' ?>" aria-label="Copyright statistics"><?php foreach ($pageConfig['stats'] as $card): ?><article class="stat-card"><strong><?= copyrightsEscape((string) $card['value']) ?></strong><span><?= copyrightsEscape($card['label']) ?></span><?php if (!empty($card['detail'])): ?><small><?= copyrightsEscape($card['detail']) ?></small><?php endif; ?></article><?php endforeach; ?></section>
  <?php endif; ?>

  <?php if ($pageConfig['panel'] === 'registry' || $pageConfig['panel'] === 'registered_breakdown'): ?>
  <section class="hub-panel" aria-labelledby="registry-panel-heading"><div class="panel-head"><div><h2 id="registry-panel-heading"><?= copyrightsEscape($pageConfig['panel_title']) ?></h2><p>Registered outputs with assigned reference numbers, sorted by reference.</p></div></div>
    <?php if (!$copyrightsAvailable || !$registryRecords): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#169;</div><h2>No registered outputs with reference numbers</h2><p><?= $copyrightsAvailable ? 'Registered records will appear here once a reference number has been assigned.' : 'The copyrights module is not installed on this database.' ?></p></div>
    <?php else: ?><div class="table-wrap"><table class="hub-table"><thead><tr><th>Reference no.</th><th>Output title</th><th>Applicant</th><th>Output type</th><th>Date registered</th></tr></thead><tbody><?php foreach ($registryRecords as $record): ?><tr><td class="ref"><?= copyrightsEscape($record['copyright_ref_no']) ?></td><td class="title-cell"><a href="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $record['copyright_id']) ?>"><?= copyrightsEscape($record['output_title']) ?></a></td><td><?= copyrightsEscape($record['applicant_name'] ?: '—') ?></td><td><?= copyrightsEscape($typeOptions[$record['output_type']] ?? ucwords(str_replace('_', ' ', (string) $record['output_type']))) ?></td><td><?= $record['registered_at'] ? copyrightsEscape(date('M j, Y', strtotime((string) $record['registered_at']))) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <?php elseif ($pageConfig['panel'] === 'pipeline'): ?>
  <section class="hub-panel" aria-labelledby="copyright-pipeline-heading"><div class="panel-head"><div><h2 id="copyright-pipeline-heading">Stage pipeline</h2><p>Applications by current status<?= $ownScope ? ' in your portfolio' : '' ?>.</p></div></div>
    <?php if (!$copyrightsAvailable || array_sum($stageCounts) === 0): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#8635;</div><h2>No applications in the pipeline</h2><p><?= $copyrightsAvailable ? 'Stage counts will appear as applications are submitted.' : 'The copyrights module is not installed on this database.' ?></p></div>
    <?php else: ?><div class="pipeline-list"><?php foreach ($statusOptions as $stage => $stageLabel): $stageCount = $stageCounts[$stage]; $barWidth = $maxStageCount > 0 ? round(($stageCount / $maxStageCount) * 100, 1) : 0; ?><div class="pipeline-row"><div class="pipeline-label"><span><?= copyrightsEscape($stageLabel) ?></span><strong><?= $stageCount ?></strong></div><div class="pipeline-track" role="img" aria-label="<?= copyrightsEscape($stageLabel . ': ' . $stageCount) ?>"><span style="width: <?= number_format($barWidth, 1, '.', '') ?>%"></span></div></div><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="hub-panel" aria-labelledby="copyright-directory-heading"><div class="panel-head"><div><h2 id="copyright-directory-heading"><?= copyrightsEscape($pageConfig['directory_title']) ?></h2><p><?= count($applications) ?> <?= count($applications) === 1 ? 'record' : 'records' ?> in view</p></div></div>
    <form class="filter-form" method="get" action="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyrights.php') ?>"><?php if ($ipScope !== ''): ?><input type="hidden" name="ip_scope" value="<?= copyrightsEscape($ipScope) ?>"><?php endif; ?><div class="field"><label for="copyright-q">Title, applicant, or reference no.</label><input id="copyright-q" name="q" type="search" maxlength="150" value="<?= copyrightsEscape($query) ?>" placeholder="Search applications"></div><div class="field"><label for="copyright-status">Status</label><select id="copyright-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= copyrightsEscape($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= copyrightsEscape($label) ?></option><?php endforeach; ?></select></div><?php if ($ipScope === '' || $staffPageKey === 'copyrights'): ?><div class="field"><label for="copyright-type">Output type</label><select id="copyright-type" name="output_type"><option value="">All types</option><?php foreach ($typeOptions as $value => $label): ?><option value="<?= copyrightsEscape($value) ?>" <?= $outputType === $value ? 'selected' : '' ?>><?= copyrightsEscape($label) ?></option><?php endforeach; ?></select></div><?php endif; ?><div class="filter-actions"><button class="hub-button" type="submit">Apply</button><?php if ($filtersActive): ?><a class="hub-button secondary" href="<?= copyrightsEscape($role === 'research_staff' ? SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($staffPageKey) : SITE_URL . 'pages/shared/copyrights.php') ?>">Clear</a><?php endif; ?></div></form>
    <?php if (!$copyrightsAvailable || !$applications): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">&#169;</div><h2><?= $copyrightsAvailable ? 'No applications found' : 'Copyrights are unavailable' ?></h2><p><?= $copyrightsAvailable ? ($ownScope ? 'No applications match your current search and filters.' : 'Adjust the search or filters to see more copyright records.') : 'Apply database migration 012 to enable this module.' ?></p></div>
    <?php else: ?><div class="table-wrap"><table class="hub-table"><thead><tr><?php if ($showReferenceColumn): ?><th>Reference no.</th><?php endif; ?><th>Output title</th><th>Applicant</th><th>Category</th><th>Output type</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($applications as $application): $badge = copyrightsStatusBadge((string) $application['status']); $detailUrl = SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $application['copyright_id']; $canEdit = $role === 'admin' || ($ownScope && (int) $application['applicant_id'] === $userId && $application['status'] === 'pending') || ($role === 'research_staff' && $staffCanManageCopyrightType((string) $application['output_type'])); ?><tr><?php if ($showReferenceColumn): ?><td class="ref"><?= copyrightsEscape($application['copyright_ref_no'] ?: '—') ?></td><?php endif; ?><td class="title-cell"><a href="<?= copyrightsEscape($detailUrl) ?>"><?= copyrightsEscape($application['output_title']) ?></a></td><td><?= copyrightsEscape($application['applicant_name'] ?: '—') ?></td><td><?= copyrightsEscape(ucfirst((string) $application['applicant_category'])) ?></td><td><?= copyrightsEscape($typeOptions[$application['output_type']] ?? ucwords(str_replace('_', ' ', (string) $application['output_type']))) ?></td><td><span class="badge tone-<?= copyrightsEscape($badge['tone']) ?>"><?= copyrightsEscape($badge['label']) ?></span></td><td><div class="row-actions"><a class="row-link" href="<?= copyrightsEscape($detailUrl) ?>">View</a><?php if ($canEdit): ?><a class="row-link" href="<?= copyrightsEscape(SITE_URL . 'pages/shared/copyright-form.php?id=' . (int) $application['copyright_id']) ?>">Edit</a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
