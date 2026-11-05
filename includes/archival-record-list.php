<?php
require_once __DIR__ . '/archival-record-ui.php';
if ($archiveKind === 'publication') require_once __DIR__ . '/publication-classification.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit('Archive lists accept GET only.'); }
$where = ['1=1']; $params = [];
if ($archiveKind === 'copyright') { $where[] = 'r.output_type IN (' . implode(',', array_fill(0, count($c['types']), '?')) . ')'; array_push($params, ...array_keys($c['types'])); }
if ($role === 'faculty') {
    $facultyScope = "(r.{$c['owner']} = ? OR EXISTS (SELECT 1 FROM {$archiveKind}_contributors ac WHERE ac.{$c['id']} = r.{$c['id']} AND ac.user_id = ?))";
    if ($archiveKind === 'publication') $facultyScope = "({$facultyScope} OR ((r.archival_status = 'archived' OR r.status = 'published') AND r.deactivated_at IS NULL))";
    $where[] = $facultyScope;
    array_push($params, $userId, $userId);
} elseif ($role === 'student') {
    $studentScope = "EXISTS (SELECT 1 FROM {$archiveKind}_contributors ac WHERE ac.{$c['id']} = r.{$c['id']} AND ac.user_id = ?)";
    if ($archiveKind === 'publication') $studentScope = "({$studentScope} OR ((r.archival_status = 'archived' OR r.status = 'published') AND r.deactivated_at IS NULL))";
    $where[] = $studentScope;
    $params[] = $userId;
} elseif ($role === 'research_staff') {
    $scopes = [];
    if ($archiveKind === 'copyright') {
        foreach ($c['types'] as $type => $_label) {
            $recordScopes = archiveStaffScopes($archiveKind, [$c['type'] => $type]);
            if ($recordScopes && hasModulePermission($userId, $recordScopes[0][0], 'view', $recordScopes[0][1])) $scopes[] = "r.{$c['type']} = '" . $type . "'";
        }
    } else {
        foreach (['journal' => 'journal_publications','conference' => 'conference_presentations'] as $type => $page) if (hasModulePermission($userId,'publication_dissemination','view',$page)) $scopes[] = "r.publication_type = '$type'";
        if (hasModulePermission($userId,'publication_dissemination','view','research_repository')) $scopes[] = "(r.archival_status = 'archived' OR r.status = 'published')";
        if (hasModulePermission($userId,'research_implementation_outputs','view','research_outputs')) $scopes[] = "(r.deactivated_at IS NULL OR r.archival_status = 'archived' OR r.status = 'published')";
    }
    if (!$scopes) archiveDeny();
    $where[] = '(' . implode(' OR ', $scopes) . ')';
}
$get = static fn(string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
$filters = ['q' => mb_substr($get('q'),0,150), 'record_type' => $get('record_type') ?: $get($c['type']), 'application_status' => $get('application_status'), 'classification_type' => $get('classification_type'), 'classification_subtype' => $get('classification_subtype'), 'scope' => $get('scope'), 'research_area' => $get('research_area'), 'year' => $get('year'), 'year_from' => $get('year_from'), 'year_to' => $get('year_to'), 'owner' => $get('owner'), 'program' => mb_substr($get('program'),0,255), 'archive_status' => $get('archive_status'), 'external_status' => $get('external_status'), 'indexing' => $get('indexing'), 'inactive' => $get('inactive'), 'sdg' => $get('sdg')];
// Section scope applies to the list, totals, summaries and CSV, even when filters conflict.
if ($publicationSectionType !== '') $filters['record_type'] = $publicationSectionType;
if ($publicationSection === 'research_repository') $where[] = "(r.archival_status = 'archived' OR r.status = 'published')";
$report = $dedicatedPublication ? $archiveReportView : $get('report') === '1';
$export = $get('export') === 'csv';
if ($role === 'student' && ($report || $export)) archiveDeny();
if ($filters['inactive'] !== 'all') $where[] = $filters['inactive'] === '1' ? 'r.deactivated_at IS NOT NULL' : 'r.deactivated_at IS NULL';
if ($archiveKind === 'publication' && $filters['q'] !== '') { $where[] = "(r.research_title LIKE ? OR r.researcher_name LIKE ? OR r.authors_json LIKE ? OR CONCAT_WS(' ',u.first_name,u.last_name) LIKE ? OR r.doi_identifier LIKE ? OR r.journal_publisher LIKE ? OR r.publication_url LIKE ? OR r.indexing_information LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.source_title')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.conference_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.book_title')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.proceedings_title')) LIKE ?)"; array_push($params,...array_fill(0,12,'%'.$filters['q'].'%')); }
elseif ($filters['q'] !== '' && $archiveKind === 'copyright') { $where[] = "(r.{$c['title']} LIKE ? OR r.{$c['authors']} LIKE ? OR r.copyright_ref_no LIKE ? OR CONCAT_WS(' ', u.first_name,u.last_name) LIKE ?)"; array_push($params, ...array_fill(0,4,'%' . $filters['q'] . '%')); }
elseif ($filters['q'] !== '') { $where[] = "(r.{$c['title']} LIKE ? OR r.{$c['authors']} LIKE ? OR CONCAT_WS(' ', u.first_name,u.last_name) LIKE ?)"; array_push($params, ...array_fill(0,3,'%' . $filters['q'] . '%')); }
if (isset($c['types'][$filters['record_type']])) { $where[] = "r.{$c['type']} = ?"; $params[] = $filters['record_type']; }
if ($archiveKind === 'copyright') {
    if (in_array($filters['application_status'], ['pending', 'under_review', 'registered', 'rejected'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $filters['application_status'];
    }
    if (preg_match('/^\d{4}$/D', $filters['year']) && (int)$filters['year'] > 0) {
        $where[] = 'YEAR(r.filing_date) = ?';
        $params[] = (int)$filters['year'];
    }
}
if ($archiveKind === 'publication') {
    $publicationTypes = publicationClassificationTypes($conn, false);
    $selectedSubtypeTypeId = null;
    if (ctype_digit($filters['classification_subtype']) && (int)$filters['classification_subtype'] > 0) {
        $subtypeOwner = archiveQuery($conn,'SELECT publication_type_id FROM publication_subtypes WHERE publication_subtype_id = ?', [(int)$filters['classification_subtype']])->get_result()->fetch_assoc();
        $selectedSubtypeTypeId = $subtypeOwner ? (int)$subtypeOwner['publication_type_id'] : null;
    }
    if ($filters['classification_type'] !== '' && $selectedSubtypeTypeId !== null && (int)$filters['classification_type'] !== $selectedSubtypeTypeId) {
        $filters['classification_subtype'] = '';
    }
    if (ctype_digit($filters['classification_type'])) {
        $selectedFilterType = null;
        foreach ($publicationTypes as $typeOption) if ((int)$typeOption['publication_type_id'] === (int)$filters['classification_type']) $selectedFilterType = $typeOption;
        if ($selectedFilterType) {
            $where[] = '(r.publication_type_id = ? OR (r.publication_type_id IS NULL AND r.publication_type = ?))';
            array_push($params, $selectedFilterType['publication_type_id'], $selectedFilterType['type_code']);
        }
    }
    if (ctype_digit($filters['classification_subtype']) && (int)$filters['classification_subtype'] > 0) { $where[] = 'r.publication_subtype_id = ?'; $params[] = (int)$filters['classification_subtype']; }
    foreach (['scope'=>'publication_scope_id','research_area'=>'research_area_id'] as $filterKey=>$column) {
        if (ctype_digit($filters[$filterKey]) && (int)$filters[$filterKey] > 0) { $where[] = "r.{$column} = ?"; $params[] = (int)$filters[$filterKey]; }
    }
    if (ctype_digit($filters['year']) && (int)$filters['year'] > 0) {
        $where[] = publicationYearSql() . ' = ?';
        $params[] = (int)$filters['year'];
    } elseif ($filters['year'] === 'custom') {
        $fromYear = $filters['year_from']; $toYear = $filters['year_to'];
        if (ctype_digit($fromYear) && ctype_digit($toYear) && (int)$fromYear <= (int)$toYear) {
            $where[] = publicationYearSql() . ' BETWEEN ? AND ?';
            array_push($params, (int)$fromYear, (int)$toYear);
        } elseif (ctype_digit($fromYear) && (int)$fromYear > 0) {
            $where[] = publicationYearSql() . ' >= ?'; $params[] = (int)$fromYear;
        } elseif (ctype_digit($toYear) && (int)$toYear > 0) {
            $where[] = publicationYearSql() . ' <= ?'; $params[] = (int)$toYear;
        }
    }
    if ($filters['indexing'] === 'indexed') {
        $where[] = "(r.indexing_information IS NOT NULL AND r.indexing_information <> '' AND r.indexing_information NOT LIKE '%Unknown / Not Verified%' AND r.indexing_information NOT LIKE '%Not Indexed%')";
    } elseif ($filters['indexing'] === 'not_indexed') {
        $where[] = "(r.indexing_information LIKE '%Not Indexed%' OR r.indexing_information LIKE '%Unknown / Not Verified%')";
    } elseif ($filters['indexing'] === 'unknown') {
        $where[] = "(r.indexing_information LIKE '%Unknown / Not Verified%' OR (r.indexing_information IS NULL OR r.indexing_information = ''))";
    }
    if ($filters['sdg'] !== '') {
        $sdgToken = 'SDG ' . trim((string)$filters['sdg']);
        $where[] = "(r.sdg_alignment LIKE ? OR JSON_CONTAINS(CAST(r.publication_metadata_json AS JSON), JSON_ARRAY(?), '$.sdgs'))";
        array_push($params, '%' . $sdgToken . '%', (int)$filters['sdg']);
    }
}
if (ctype_digit($filters['owner'])) { $where[] = "r.{$c['owner']} = ?"; $params[] = $filters['owner']; }
if ($filters['owner'] === 'unresolved') $where[] = "(u.user_id IS NULL OR u.role <> 'faculty' OR u.deleted_at IS NOT NULL)";
if ($filters['program'] !== '') { $where[] = $archiveKind === 'publication' ? 'COALESCE(pg.program_code,u.program) = ?' : 'u.program = ?'; $params[] = $filters['program']; }
if (in_array($filters['archive_status'],['legacy','archived'],true)) { $where[] = 'r.archival_status = ?'; $params[] = $filters['archive_status']; }
if ($archiveKind === 'copyright' && isset(archiveExternalOptions()[$filters['external_status']])) { $where[] = 'r.external_status = ?'; $params[] = $filters['external_status']; }
$publicationJoins = $archiveKind === 'publication' ? publicationJoinsSql() : '';
$from = " FROM {$c['table']} r LEFT JOIN users u ON u.user_id = r.{$c['owner']}" . $publicationJoins . ' WHERE ' . implode(' AND ', $where);
$totals = archiveQuery($conn, "SELECT COUNT(*) AS total, COALESCE(SUM(r.archival_status='archived'),0) AS archived, COALESCE(SUM(r.archival_status='legacy'),0) AS legacy" . $from, $params)->get_result()->fetch_assoc();
if ($role === 'faculty' && ($report || $export)) {
    $from .= " AND r.{$c['owner']} = ?";
    $params[] = $userId;
    $totals = archiveQuery($conn, "SELECT COUNT(*) AS total, COALESCE(SUM(r.archival_status='archived'),0) AS archived, COALESCE(SUM(r.archival_status='legacy'),0) AS legacy" . $from, $params)->get_result()->fetch_assoc();
}
$pageSize = $archiveKind === 'publication' ? 10 : 25;
$page = max(1, (int) $get('page')); $pages = max(1,(int) ceil($totals['total']/$pageSize)); $page = min($page,$pages);
$select = "SELECT r.*, CONCAT_WS(' ',u.first_name,u.last_name) AS owner_name, u.program AS owner_program, u.role AS owner_role, u.deleted_at AS owner_deleted" . ($archiveKind === 'publication' ? ',pt.label AS classification_type_label,pt.type_code AS classification_type_code,ps.label AS classification_subtype_label,ps.subtype_code AS classification_subtype_code,psc.label AS publication_scope_label,pra.area_name AS research_area_label,COALESCE(pg.program_code,u.program) AS archive_program' : '');
if ($export) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $archiveKind . '-archive.csv"'); header('Cache-Control: no-store');
    $out = fopen('php://output','w');
    fputcsv($out, $archiveKind === 'publication' ? ['Record ID','Title','Authors in order','Faculty owner','Program','Publication type','Subtype','Scope','Publication year','Publication source','Research area','Indexing','DOI','Publication URL','Archive status','Encoded by user ID','Created at','Last editor user ID','Last updated'] : ['Record ID','Title','Actual authors / creators','Faculty owner','Program','Type','Year','Archival status','External copyright status','Indexing','Created at','Last updated']);
    $rows = archiveQuery($conn, $select . $from . " ORDER BY r.{$c['id']} DESC", $params)->get_result();
    while ($row = $rows->fetch_assoc()) {
        $line = [$row[$c['id']],$row[$c['title']],$row[$c['authors']],$row['owner_name'],$row['owner_program'],$c['types'][$row[$c['type']]] ?? $row[$c['type']],substr((string)($row[$c['date']] ?: $row['created_at']),0,4),archiveStatus($row),$archiveKind === 'copyright' ? archiveExternalLabel($row) : '',$row['indexing_information'] ?? '',$row['created_at'],$row['updated_at']];
        if ($archiveKind === 'publication') $line = [$row['publication_id'],$row['research_title'],publicationAuthorLabel($row),$row['owner_name'],$row['archive_program'],publicationTypeLabel($row),$row['classification_subtype_label'] ?? '',$row['publication_scope_label'] ?? '',publicationYear($row),publicationSource($row),$row['research_area_label'] ?? '',$row['indexing_information'] ?? '',$row['doi_identifier'] ?? '',$row['publication_url'] ?? '',archiveStatus($row),$row['encoded_by'],$row['created_at'],$row['last_modified_by'],$row['updated_at']];
        fputcsv($out,array_map(static fn($v) => preg_match('/^[\s]*[=+@-]/u',(string)$v) ? "'" . $v : (string)$v,$line));
    }
    fclose($out); exit;
}
$records = archiveQuery($conn,$select . $from . " ORDER BY r.created_at DESC,r.{$c['id']} DESC LIMIT {$pageSize} OFFSET " . (($page-1)*$pageSize),$params)->get_result()->fetch_all(MYSQLI_ASSOC);
$listDescription = match ($role) {
    'faculty' => $archiveKind === 'publication' ? 'Browse active archived and published publication records across RMS. You can manage records you own.' : 'Your own and authorized shared records.',
    'student' => $archiveKind === 'publication' ? 'Browse active archived and published publication records across RMS, including records linked to you.' : 'Records explicitly linked to you as an author or creator.',
    'research_staff' => 'Records within your assigned permissions.',
    default => 'Institute records and documented external outcomes.',
};
$reportTitle = match ($role) {
    'faculty' => 'Own records report',
    'student' => 'My linked records report',
    'research_staff' => 'Authorized records report',
    default => 'Institute archive report',
};
$routeParams = $archiveRouteParams;
$link = static function(array $changes = []) use ($listUrl,$reportUrl,$dedicatedPublication,$filters,$report,$routeParams): string {
    $query = array_merge($routeParams,$filters,['report' => $report ? '1' : '0'],$changes);
    $url = $listUrl;
    if ($dedicatedPublication) {
        $url = (string) $query['report'] === '1' || ($query['export'] ?? '') === 'csv' ? $reportUrl : $listUrl;
        unset($query['report']);
    }
    return $url . '?' . http_build_query($query);
};
$browseUrl = $dedicatedPublication && $report ? $reportUrl : $listUrl;
$listTitle = $dedicatedPublication ? ($report ? 'Reports' : 'All Records') : $c['label'];
if ($facultyPublication && $report) $listDescription = 'Summaries and exports of publication records you own.';
if ($adminPublication && $report) $listDescription = 'Institute-wide publication summaries and exports.';
if ($adminPublication && !$report) $listDescription = 'View and manage scholarly publication records across the Institute.';
$sectionTitle = match ($publicationSection) {
    'journal_publications' => 'Journal Publications',
    'conference_presentations' => 'Conference Papers & Presentations',
    'book_chapters' => 'Book Chapters',
    'research_repository' => 'Research Repository',
    default => '',
};
if ($sectionTitle !== '') {
    $listTitle = $sectionTitle . ($report ? ' — Reports' : '');
    $listDescription = match ($publicationSection) {
        'journal_publications' => 'Journal articles, publication details, and supporting documents.',
        'conference_presentations' => 'Published proceedings papers and preserved legacy conference records.',
        'book_chapters' => 'Published book chapters and their supporting documents.',
        'research_repository' => 'Published and archived research outputs across all publication types.',
    };
    if ($role === 'research_staff') $listDescription .= ' Only records within your assigned permissions are shown.';
    if ($report) $reportTitle = $sectionTitle . ' summary';
}
$showListControls = true;
$canAdd = in_array($role,['admin','faculty'],true);
if ($role === 'research_staff') foreach (array_keys($c['types']) as $type) if (archiveCan($conn,$archiveKind,[$c['type']=>$type,'archival_status'=>'archived'],$user,'manage')) $canAdd = true;
if ($role === 'research_staff' && $publicationSectionType !== '') {
    $canAdd = archiveCan($conn, $archiveKind, ['publication_type' => $publicationSectionType, 'archival_status' => 'archived'], $user, 'manage');
}
archiveShellOpen($user,$c,$listTitle,$adminPublication);
?>
<div class="archive-heading<?= $adminPublication ? ' archive-admin-publication-heading' : '' ?>"><div><h1><?= archiveEscape($listTitle) ?></h1><p class="archive-muted"><?= archiveEscape($listDescription) ?></p></div><div class="archive-actions archive-no-print"><?php if ($canAdd && $showListControls): ?><?php $addParams = array_merge($routeParams, $filters['record_type'] !== '' ? [$c['type'] => $filters['record_type']] : []); ?><a class="archive-button" href="<?= archiveEscape($formUrl . '?' . http_build_query($addParams)) ?>">Add record</a><?php endif; ?><?php if ($role !== 'student'): ?><?php if ($report): ?><a class="archive-button secondary" href="<?= archiveEscape($link(['report'=>0,'page'=>1])) ?>"><?= archiveEscape($sectionTitle !== '' ? 'Back to ' . $sectionTitle : ($archiveKind === 'publication' ? 'All publications' : 'Back to records')) ?></a><?php else: ?><a class="archive-button secondary" href="<?= archiveEscape($link(['report'=>1])) ?>">Reports</a><?php endif; ?><a class="archive-button secondary" href="<?= archiveEscape($link(['export'=>'csv'])) ?>">Download CSV</a><?php endif; ?><?php if ($role === 'admin' && $archiveKind === 'publication'): ?><a class="archive-button secondary" href="<?= archiveEscape(SITE_URL . 'pages/admin/admin-publication-classifications.php') ?>">Manage publication classifications and form rules</a><?php endif; ?></div></div>
<?php if ($flash): ?><div class="archive-success" role="status"><?= archiveEscape($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if ($showListControls): ?>
<form method="get" action="<?= archiveEscape($browseUrl) ?>" class="archive-card archive-no-print"><?php if ($archiveScope !== ''): ?><input type="hidden" name="ip_scope" value="<?= archiveEscape($archiveScope) ?>"><?php endif; ?><?php if (!$dedicatedPublication): ?><input type="hidden" name="report" value="<?= $report ? '1' : '0' ?>"><?php endif; ?><div class="archive-filters archive-filters--publication">
<?php foreach ($archiveNavigationParams as $key => $value): ?><input type="hidden" name="<?= archiveEscape($key) ?>" value="<?= archiveEscape($value) ?>"><?php endforeach; ?>
<?php
$publicationYears = [];
if ($archiveKind === 'publication') {
    $publicationYears = archiveQuery($conn, "SELECT DISTINCT CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(publication_metadata_json) THEN publication_metadata_json ELSE '{}' END, '$.publication_year')), ''), YEAR(publication_date)) AS UNSIGNED) AS publication_year FROM publications WHERE publication_date IS NOT NULL OR publication_metadata_json IS NOT NULL ORDER BY publication_year DESC")->get_result()->fetch_all(MYSQLI_ASSOC);
    $publicationYears = array_values(array_filter(array_map(static fn(array $row): ?string => is_numeric($row['publication_year'] ?? null) ? (string)$row['publication_year'] : null, $publicationYears)));
    $yearOptions = ['' => 'All Years', 'custom' => 'Custom Range'];
    foreach ($publicationYears as $yearValue) $yearOptions[$yearValue] = $yearValue;
    $programOptions = archiveQuery($conn, 'SELECT program_id, program_code, program_name FROM programs WHERE status = 1 ORDER BY program_code, program_name')->get_result()->fetch_all(MYSQLI_ASSOC);
    $areaOptions = archiveQuery($conn, 'SELECT area_id, area_name FROM research_areas WHERE is_active = 1 OR area_id IN (SELECT research_area_id FROM publications WHERE research_area_id IS NOT NULL) ORDER BY area_name')->get_result()->fetch_all(MYSQLI_ASSOC);
    $scopeItems = publicationClassificationScopes($conn, false);
    $facultyOptions = archiveQuery($conn, "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name, program FROM users WHERE role = 'faculty' AND deleted_at IS NULL ORDER BY last_name, first_name")->get_result()->fetch_all(MYSQLI_ASSOC);
    $sdgOptions = archiveQuery($conn, "SELECT DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(sdg_alignment, ',', n.n), ',', -1)) AS sdg_label FROM publications CROSS JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n WHERE sdg_alignment IS NOT NULL AND sdg_alignment <> '' AND n.n <= 1 + LENGTH(sdg_alignment) - LENGTH(REPLACE(sdg_alignment, ',', '')) ORDER BY sdg_label")->get_result()->fetch_all(MYSQLI_ASSOC);
}
$advancedActiveCount = 0;
if ($archiveKind === 'publication') {
    foreach (['scope','research_area','indexing','owner','sdg','inactive'] as $filterKey) {
        if ($filterKey === 'inactive' && $filters['inactive'] !== '' && $filters['inactive'] !== 'all') $advancedActiveCount++;
        elseif ($filterKey !== 'inactive' && $filters[$filterKey] !== '' && $filters[$filterKey] !== 'all') $advancedActiveCount++;
    }
}
?>
<div class="archive-field archive-field--full">
    <label for="filter-q"><?= $archiveKind === 'publication' ? 'Search Publications' : 'Search Copyright Applications' ?></label>
    <input id="filter-q" name="q" type="search" maxlength="150" value="<?= archiveEscape($filters['q']) ?>" placeholder="<?= $archiveKind === 'publication' ? 'Search title, author, DOI, journal, conference...' : 'Search title, applicant, or reference number...' ?>">
</div>
<?php if ($archiveKind === 'publication'): ?>
    <?php $classificationTypes = array_values(array_filter($publicationTypes, static fn(array $typeItem): bool => (int)$typeItem['is_active'] === 1 && (int)$typeItem['is_proposed'] === 1)); ?>
    <div class="archive-field"><label for="filter-classification-type">Publication Type</label><select id="filter-classification-type" name="classification_type"><option value="">All Types</option><?php foreach($classificationTypes as $typeItem): ?><option value="<?= (int)$typeItem['publication_type_id'] ?>" data-type-code="<?= archiveEscape($typeItem['type_code']) ?>" <?= $filters['classification_type']===(string)$typeItem['publication_type_id']?'selected':'' ?>><?= archiveEscape($typeItem['label']) ?></option><?php endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-classification-subtype">Publication Subtype</label><select id="filter-classification-subtype" name="classification_subtype"><option value="">All Subtypes</option><?php foreach($classificationTypes as $typeItem): foreach(publicationClassificationSubtypes($conn,(int)$typeItem['publication_type_id'],true) as $subtypeItem): ?><option value="<?= (int)$subtypeItem['publication_subtype_id'] ?>" data-type-id="<?= (int)$typeItem['publication_type_id'] ?>" <?= $filters['classification_subtype']===(string)$subtypeItem['publication_subtype_id']?'selected':'' ?>><?= archiveEscape($typeItem['label'] . ' — ' . $subtypeItem['label']) ?></option><?php endforeach; endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-year">Publication Year</label><select id="filter-year" name="year"><option value="">All Years</option><?php foreach($yearOptions as $value=>$label): ?><option value="<?= archiveEscape($value) ?>" <?= $filters['year']===$value || ($value === '' && $filters['year']==='') ? 'selected' : '' ?>><?= archiveEscape($label) ?></option><?php endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-program">Department / Program</label><select id="filter-program" name="program"><option value="">All Programs</option><?php foreach($programOptions as $programItem): ?><option value="<?= archiveEscape((string)($programItem['program_code'] ?? '')) ?>" <?= $filters['program'] === ((string)($programItem['program_code'] ?? '')) ? 'selected' : '' ?>><?= archiveEscape((string)($programItem['program_code'] ?? '') . ' — ' . ($programItem['program_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-status">Archival Status</label><select id="filter-status" name="archive_status"><option value="">All Records</option><option value="archived" <?= $filters['archive_status']==='archived'?'selected':'' ?>>Archived</option></select></div>
    <?php if ($filters['year'] === 'custom' || $filters['year_from'] !== '' || $filters['year_to'] !== ''): ?><div class="archive-field archive-field--full archive-year-range"><label for="filter-year-from">From Year</label><input id="filter-year-from" type="number" name="year_from" min="1900" max="9999" inputmode="numeric" value="<?= archiveEscape($filters['year_from']) ?>" placeholder="From year"><label for="filter-year-to">To Year</label><input id="filter-year-to" type="number" name="year_to" min="1900" max="9999" inputmode="numeric" value="<?= archiveEscape($filters['year_to']) ?>" placeholder="To year"></div><?php else: ?><div class="archive-field archive-field--full archive-year-range" hidden><label for="filter-year-from">From Year</label><input id="filter-year-from" type="number" name="year_from" min="1900" max="9999" inputmode="numeric" value="<?= archiveEscape($filters['year_from']) ?>" placeholder="From year"><label for="filter-year-to">To Year</label><input id="filter-year-to" type="number" name="year_to" min="1900" max="9999" inputmode="numeric" value="<?= archiveEscape($filters['year_to']) ?>" placeholder="To year"></div><?php endif; ?>
<?php else: ?>
    <div class="archive-field"><label for="filter-output-type">Output Type</label><select id="filter-output-type" name="output_type"><option value="">All Types</option><?php foreach($c['types'] as $typeCode => $typeLabel): ?><option value="<?= archiveEscape($typeCode) ?>" <?= $filters['record_type'] === $typeCode ? 'selected' : '' ?>><?= archiveEscape($typeLabel) ?></option><?php endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-year">Filing Year</label><input id="filter-year" name="year" type="number" min="1900" max="9999" inputmode="numeric" value="<?= archiveEscape($filters['year']) ?>" placeholder="Any year"></div>
    <div class="archive-field"><label for="filter-application-status">Application Status</label><select id="filter-application-status" name="application_status"><option value="">All Statuses</option><?php foreach(['pending'=>'Pending','under_review'=>'Under review','registered'=>'Registered','rejected'=>'Rejected'] as $statusCode => $statusLabel): ?><option value="<?= $statusCode ?>" <?= $filters['application_status'] === $statusCode ? 'selected' : '' ?>><?= $statusLabel ?></option><?php endforeach; ?></select></div>
    <div class="archive-field"><label for="filter-external-status">External Registration / IP Status</label><select id="filter-external-status" name="external_status"><option value="">All External Statuses</option><?php foreach(archiveExternalOptions() as $statusCode => $statusLabel): ?><option value="<?= archiveEscape($statusCode) ?>" <?= $filters['external_status'] === $statusCode ? 'selected' : '' ?>><?= archiveEscape($statusLabel) ?></option><?php endforeach; ?></select></div>
    <?php foreach ($archiveNavigationParams as $key => $value): ?><input type="hidden" name="<?= archiveEscape($key) ?>" value="<?= archiveEscape($value) ?>"><?php endforeach; ?>
<?php endif; ?>
</div>
<?php if ($archiveKind === 'publication'): ?>
<details class="archive-filter-panel archive-no-print" <?= $archiveKind === 'publication' && $advancedActiveCount > 0 ? 'open' : '' ?>>
    <summary>Advanced Filters<?= $archiveKind === 'publication' && $advancedActiveCount > 0 ? ' (' . $advancedActiveCount . ')' : '' ?></summary>
    <div class="archive-filters archive-filters--advanced">
        <?php if ($archiveKind === 'publication'): ?>
            <div class="archive-field"><label for="filter-scope">Publication Scope</label><select id="filter-scope" name="scope"><option value="">All Scopes</option><?php foreach($scopeItems as $scopeItem): ?><option value="<?= (int)$scopeItem['publication_scope_id'] ?>" <?= $filters['scope']===(string)$scopeItem['publication_scope_id']?'selected':'' ?>><?= archiveEscape($scopeItem['label']) ?></option><?php endforeach; ?></select></div>
            <div class="archive-field"><label for="filter-area">Research Area</label><select id="filter-area" name="research_area"><option value="">All Research Areas</option><?php foreach($areaOptions as $areaItem): ?><option value="<?= (int)$areaItem['area_id'] ?>" <?= $filters['research_area']===(string)$areaItem['area_id']?'selected':'' ?>><?= archiveEscape($areaItem['area_name']) ?></option><?php endforeach; ?></select></div>
            <div class="archive-field"><label for="filter-indexing">Indexing Status</label><select id="filter-indexing" name="indexing"><option value="">All Indexing Statuses</option><option value="indexed" <?= $filters['indexing']==='indexed'?'selected':'' ?>>Indexed</option><option value="not_indexed" <?= $filters['indexing']==='not_indexed'?'selected':'' ?>>Not Indexed</option><option value="unknown" <?= $filters['indexing']==='unknown'?'selected':'' ?>>Unknown / Unverified</option></select></div>
            <div class="archive-field"><label for="filter-owner">Faculty / Record Owner</label><select id="filter-owner" name="owner"><option value="">All Faculty</option><?php foreach($facultyOptions as $facultyItem): ?><option value="<?= (int)$facultyItem['user_id'] ?>" <?= $filters['owner']===(string)$facultyItem['user_id']?'selected':'' ?>><?= archiveEscape($facultyItem['full_name'] . ' — ' . ($facultyItem['program'] ?: 'No program')) ?></option><?php endforeach; ?></select></div>
            <div class="archive-field"><label for="filter-sdg">SDG Alignment</label><select id="filter-sdg" name="sdg"><option value="">All SDGs</option><?php foreach($sdgOptions as $sdgItem): $sdgValue = trim((string)($sdgItem['sdg_label'] ?? '')); if ($sdgValue === '') continue; preg_match('/\d+/', $sdgValue, $sdgMatch); if (!$sdgMatch) continue; $sdgCode = $sdgMatch[0]; ?><option value="<?= archiveEscape($sdgCode) ?>" <?= $filters['sdg']===$sdgCode?'selected':'' ?>><?= archiveEscape($sdgValue) ?></option><?php endforeach; ?></select></div>
            <div class="archive-field"><label for="filter-active">Record Availability</label><select id="filter-active" name="inactive"><option value="all">All Records</option><option value="" <?= $filters['inactive']===''?'selected':'' ?>>Active</option><option value="1" <?= $filters['inactive']==='1'?'selected':'' ?>>Inactive</option></select></div>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>
<div class="archive-actions archive-filter-actions"><button class="archive-button" type="submit">Search / Filter</button><a href="<?= archiveEscape($browseUrl . $navigationQuery) ?>">Clear filters</a></div></form>
<?php endif; ?>
<?php if($report): ?>
<section class="archive-card"><div class="archive-heading"><h2><?= archiveEscape($reportTitle) ?></h2><button type="button" class="archive-button secondary archive-no-print" data-archive-print>Print report</button></div><p>All matching records are included in these summaries. Unique totals count each record once, regardless of linked authors. Legacy records are shown separately from directly archived records.</p>
<?php
$groups = ['Year' => "YEAR(COALESCE(r.{$c['date']},r.created_at))", 'Faculty owner' => "CONCAT(COALESCE(NULLIF(CONCAT_WS(' ',u.first_name,u.last_name),''),'Unresolved owner'), ' [', COALESCE(CAST(r.{$c['owner']} AS CHAR),'unassigned'), ']')", 'Academic program' => "COALESCE(NULLIF(u.program,''),'Not recorded')", 'Record type' => "r.{$c['type']}"];
if($archiveKind==='publication') {
    $groups['Year']="COALESCE(CAST(".publicationYearSql()." AS CHAR),'Not recorded')";
    $groups['Academic program']="COALESCE(pg.program_code,NULLIF(u.program,''),'Not recorded')";
    $groups['Record type']="COALESCE(pt.label,NULLIF(r.publication_type_name,''),r.publication_type)";
    $groups['Publication subtype']="COALESCE(ps.label,'Not recorded')";
    $groups['Publication scope']="COALESCE(psc.label,'Not recorded')";
    $groups['Research area']="COALESCE(pra.area_name,'Not recorded')";
}
if($archiveKind==='publication') $groups['Indexing classification']="COALESCE(NULLIF(r.indexing_information,''),'Not recorded')";
else $groups[$archiveScope === '' ? 'External copyright status' : 'External registration / IP status']="CASE WHEN r.external_status IS NOT NULL THEN r.external_status WHEN r.status='registered' AND NULLIF(r.copyright_ref_no,'') IS NOT NULL THEN 'registered (legacy)' ELSE 'not recorded (legacy)' END";
foreach($groups as $label=>$expression):
$summary=archiveQuery($conn,"SELECT $expression AS label, COUNT(*) AS total, SUM(r.archival_status='archived') AS archived".$from.' GROUP BY label ORDER BY label',$params)->get_result(); ?>
<h3><?= archiveEscape($label) ?></h3><div class="archive-table-wrap"><table class="archive-table<?= $role === 'admin' ? ' archive-admin-report-summary' : '' ?>"><thead><tr><th><?= archiveEscape($label) ?></th><th>Unique records</th><th>Archived</th></tr></thead><tbody><?php while($group=$summary->fetch_assoc()): ?><tr><td><?= archiveEscape($group['label']) ?></td><td><?= (int)$group['total'] ?></td><td><?= (int)$group['archived'] ?></td></tr><?php endwhile; ?></tbody></table></div><?php endforeach; ?></section>
<?php endif; ?>
<?php if($archiveKind==='publication'): require __DIR__.'/publication-archive-table.php'; else: ?>
<section class="archive-card"><h2>Records</h2><?php if(!$records): ?><p><?= $showListControls ? 'No records match these filters. Add a record or adjust the filters.' : 'No publication records match this report.' ?></p><?php else: ?><div class="archive-table-wrap"><table class="archive-table"><thead><tr><th>Title / authors</th><th>Faculty owner</th><th>Type / year</th><th>Record status</th><th class="archive-no-print">Actions</th></tr></thead><tbody>
<?php foreach($records as $row): $rid=(int)$row[$c['id']]; $url=$detailUrl.'?id='.$rid.$navigationSuffix; ?><tr><td><a href="<?= archiveEscape($url) ?>"><?= archiveEscape($row[$c['title']]) ?></a><p class="archive-muted"><?= archiveEscape($row[$c['authors']]) ?></p></td><td><?= archiveEscape($row['owner_name'] ?: 'Unresolved owner') ?><?php if(!$row['owner_name']||$row['owner_role']!=='faculty'||$row['owner_deleted']): ?><small> — needs administrative correction</small><?php endif; ?></td><td><?= archiveEscape($c['types'][$row[$c['type']]]??$row[$c['type']]) ?><br><?= archiveEscape(substr((string)($row[$c['date']]?:$row['created_at']),0,4)) ?></td><td><span class="archive-badge"><?= archiveEscape(archiveStatus($row)) ?></span><?php if($archiveKind==='copyright'): ?><p><?= archiveEscape(archiveExternalLabel($row)) ?></p><?php endif; ?></td><td class="archive-no-print"><div class="archive-links"><a href="<?= archiveEscape($url) ?>">View</a><?php if(archiveCan($conn,$archiveKind,$row,$user,'manage')&&empty($row['deactivated_at'])): ?><a href="<?= archiveEscape($formUrl.'?id='.$rid.$navigationSuffix) ?>">Edit</a><?php endif; ?><a href="<?= archiveEscape($url.'#documents') ?>">Documents</a><a href="<?= archiveEscape($url.'#history') ?>">History</a></div></td></tr><?php endforeach; ?>
</tbody></table></div><p>Page <?= $page ?> of <?= $pages ?> · <?= (int)$totals['total'] ?> records</p><div class="archive-links archive-no-print"><?php if($page>1): ?><a href="<?= archiveEscape($link(['page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if($page<$pages): ?><a href="<?= archiveEscape($link(['page'=>$page+1])) ?>">Next</a><?php endif; ?></div><?php endif; ?></section>
<?php endif; ?>
<script src="<?= archiveEscape(SITE_URL.'js/archival-records.js') ?>"></script>
<?php archiveShellClose($user); ?>
