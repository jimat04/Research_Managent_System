<?php
// Included only by the existing module routes; $archiveKind/$archiveMode are server-defined.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/archival-records.php';
require_once __DIR__ . '/file-uploader.php';
require_once __DIR__ . '/student-shell.php';
require_once __DIR__ . '/faculty-shell.php';
require_once __DIR__ . '/staff-shell.php';
require_once __DIR__ . '/admin-shell.php';
requireLogin();
$user = getCurrentUser();
if (!$user || !in_array($user['role'], ['student', 'faculty', 'admin', 'research_staff'], true)) archiveDeny();
$archiveFacultyOnly = ($archiveFacultyOnly ?? false) === true;
if ($archiveFacultyOnly && $user['role'] !== 'faculty') archiveDeny();
$archiveAdminOnly = ($archiveAdminOnly ?? false) === true;
if ($archiveAdminOnly && $user['role'] !== 'admin') archiveDeny();
$archiveScope = $archiveKind === 'copyright' && in_array(($archiveScope ?? ''), ['patents_utility_models', 'innovation_technology_transfer'], true) ? $archiveScope : '';
$c = archiveConfig($archiveKind, $archiveScope); $role = $user['role']; $userId = (int) $user['user_id'];
if ($archiveMode === 'list' && $role === 'student'
    && ((string) ($_GET['report'] ?? '') === '1' || (string) ($_GET['export'] ?? '') === 'csv')) archiveDeny();
if ($archiveMode === 'form' && !in_array($role, ['faculty', 'admin', 'research_staff'], true)) archiveDeny();
$base = SITE_URL . 'pages/shared/';
$listUrl = $base . ($archiveKind === 'publication' ? 'publications.php' : 'copyrights.php');
$formUrl = $base . $archiveKind . '-form.php'; $detailUrl = $base . $archiveKind . '-detail.php';
$documentDownloadUrl = $base . 'download-research-document.php';
$legacyDetailUrl = $detailUrl;
$reportUrl = $listUrl;
$facultyPublication = $archiveKind === 'publication' && $role === 'faculty';
$adminPublication = $archiveKind === 'publication' && $role === 'admin';
$dedicatedPublication = $facultyPublication || $adminPublication;
$archiveReportView = $dedicatedPublication && ($archiveReportView ?? false) === true;
if ($dedicatedPublication) {
    $publicationBase = SITE_URL . 'pages/' . $role . '/' . $role . '-';
    $listUrl = $publicationBase . 'publications.php';
    $formUrl = $publicationBase . 'publication-form.php';
    $detailUrl = $publicationBase . 'publication-detail.php';
    $reportUrl = $publicationBase . 'publication-reports.php';
    $requestedReport = ($_GET['report'] ?? '') === '1' || ($_GET['export'] ?? '') === 'csv';
    // Redirect only reads. Existing shared POST handlers retain their CSRF and access checks.
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && ((!$archiveFacultyOnly && !$archiveAdminOnly) || ($archiveMode === 'list' && !$archiveReportView && $requestedReport))) {
        $destination = match ($archiveMode) {
            'form' => $formUrl,
            'detail' => $detailUrl,
            default => $requestedReport ? $reportUrl : $listUrl,
        };
        $query = array_intersect_key($_GET, array_flip([
            'id', 'history_page', 'publication_type', 'q', 'record_type', 'year', 'owner',
            'program', 'archive_status', 'external_status', 'indexing', 'inactive', 'page', 'export',
            'classification_type', 'classification_subtype', 'scope', 'research_area',
            'module_page', 'repository', 'status',
        ]));
        $queryString = http_build_query($query);
        header('Location: ' . $destination . ($queryString !== '' ? '?' . $queryString : ''), true, 302);
        exit;
    }
}
$archiveNavigationParams = [];
$publicationSection = '';
$publicationSectionType = '';
$hidePublicationHistory = false;
if ($archiveKind === 'publication' && in_array($role, ['admin', 'research_staff'], true)) {
    $modulePage = is_string($_GET['module_page'] ?? null) ? $_GET['module_page'] : '';
    $validModulePages = $role === 'admin'
        ? ['journal_publications', 'conference_presentations', 'book_chapters', 'research_outputs']
        : ['publication_records', 'publication_add', 'publication_reports', 'research_outputs'];
    if (!in_array($modulePage, $validModulePages, true)) {
        $modulePage = match (true) {
            $role === 'research_staff' && $archiveMode === 'form' && !isset($_GET['id']) => 'publication_add',
            $role === 'research_staff' => 'publication_records',
            ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'journal' => 'journal_publications',
            ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'conference' => 'conference_presentations',
            $role === 'admin' && ($_GET['record_type'] ?? $_GET['publication_type'] ?? '') === 'book_chapter' => 'book_chapters',
            ($_GET['status'] ?? '') === 'published' => 'research_outputs',
            default => '',
        };
    }
    if ($modulePage !== '') $archiveNavigationParams['module_page'] = $modulePage;
    if ($modulePage === 'journal_publications') $archiveNavigationParams['publication_type'] = 'journal';
    elseif ($modulePage === 'conference_presentations') $archiveNavigationParams['publication_type'] = 'conference';
    elseif ($modulePage === 'book_chapters') $archiveNavigationParams['publication_type'] = 'book_chapter';
    elseif ($modulePage === 'research_repository') $archiveNavigationParams += ['repository' => '1', 'status' => 'published'];
    elseif ($modulePage === 'research_outputs') $archiveNavigationParams['status'] = 'published';
    $hidePublicationHistory = $role === 'admin'
        && $modulePage === 'research_outputs'
        && ($_GET['status'] ?? '') === 'published';
    if (in_array($modulePage, ['journal_publications', 'conference_presentations', 'book_chapters', 'research_repository'], true)) {
        $publicationSection = $modulePage;
        $publicationSectionType = $archiveNavigationParams['publication_type'] ?? '';
        if ($archiveMode === 'list' && $role === 'research_staff') {
            requireModulePermission('publication_dissemination', 'view', $publicationSection);
        }
    }
    if ($role === 'research_staff' && in_array($modulePage, ['publication_records', 'publication_add', 'publication_reports'], true)) {
        if ($modulePage === 'publication_add') {
            if ($archiveMode !== 'form'
                || !hasModulePermission($userId, 'publication_dissemination', 'manage', 'publication_add')) {
                archiveDeny();
            }
            // The form's return/detail links should land in the staff-wide authorized list.
            $archiveNavigationParams['module_page'] = 'publication_records';
        } elseif (!hasModulePermission($userId, 'publication_dissemination', 'view', $modulePage)
            || ($modulePage === 'publication_reports' && !in_array($archiveMode, ['list', 'detail'], true))) {
            archiveDeny();
        }
    }
}
$archiveRouteParams = $archiveScope !== '' ? ['ip_scope' => $archiveScope] : $archiveNavigationParams;
$navigationQuery = $archiveRouteParams ? '?' . http_build_query($archiveRouteParams) : '';
$navigationSuffix = $archiveRouteParams ? '&' . http_build_query($archiveRouteParams) : '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (isset($_GET['id']) && !$id) { http_response_code(404); exit('Record not found.'); }
$record = $id ? archiveLoad($conn, $archiveKind, $id) : null;
if ($id && !$record) { http_response_code(404); exit('Record not found.'); }
if ($archiveKind === 'copyright' && $record) {
    $recordScope = archiveCopyrightScopeForType((string) $record['output_type']);
    if ($archiveScope !== '' && $recordScope !== $archiveScope) { http_response_code(404); exit('Record not found.'); }
    $archiveScope = $recordScope;
    $c = archiveConfig($archiveKind, $archiveScope);
}
if ($archiveKind === 'copyright' && isset($_GET['output_type']) && !isset($c['types'][(string) $_GET['output_type']])) { http_response_code(404); exit('Record type not found.'); }
$recordAccessAction = $archiveKind === 'publication' && $archiveMode === 'detail' ? 'catalog' : 'view';
if ($record && !archiveCan($conn, $archiveKind, $record, $user, $recordAccessAction)) archiveDeny();
if ($archiveMode === 'detail' && !$record) { http_response_code(404); exit('Record not found.'); }
if ($facultyPublication) {
    $c['faculty_page'] = match (true) {
        $archiveMode === 'form' && !$record => 'faculty-publication-form.php',
        $archiveMode === 'list' && $archiveReportView => 'faculty-publication-reports.php',
        default => 'faculty-publications.php',
    };
}
$errors = []; $flash = $_SESSION[$c['module'] . '_flash'] ?? null; unset($_SESSION[$c['module'] . '_flash']);
if ($archiveMode === 'form') require __DIR__ . '/archival-record-form.php';
elseif ($archiveMode === 'list') require __DIR__ . '/archival-record-list.php';
elseif ($archiveMode === 'detail') require __DIR__ . '/archival-record-detail.php';
else throw new LogicException('Invalid archive view.');
