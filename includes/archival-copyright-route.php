<?php
/** Keep non-copyright IP routes on their existing workflow, including direct POSTs. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/archival-records.php';
requireLogin();
$routeId = $_GET['id'] ?? ($_POST['copyright_id'] ?? null);
if (isset($_GET['id'], $_POST['copyright_id']) && (string) $_GET['id'] !== (string) $_POST['copyright_id']) archiveDeny();
$routeRow = null;
if ($routeId !== null) {
    $validRouteId = filter_var($routeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$validRouteId || !($routeRow = archiveLoad($conn, 'copyright', $validRouteId))) { http_response_code(404); exit('Record not found.'); }
}
$routeType = (string) ($routeRow['output_type'] ?? $_POST['output_type'] ?? $_GET['output_type'] ?? '');
$requestedScope = (string) ($_GET['ip_scope'] ?? '');
$archiveScope = in_array($requestedScope, ['patents_utility_models', 'innovation_technology_transfer'], true)
    ? $requestedScope
    : archiveCopyrightScopeForType($routeType);
$useArchive = $archiveScope !== '' || archiveCopyrightType($routeType) || (!$routeRow && $routeType === '' && $requestedScope === '');
if ($useArchive) {
    if ($routeId !== null && !isset($_GET['id'])) $_GET['id'] = (string) $routeId;
    $archiveKind = 'copyright';
    require __DIR__ . '/archival-record-page.php';
    exit;
}
// Legacy request types remain protected from crossing into a different archive scope.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['output_type']) && archiveCopyrightType((string) $_POST['output_type'])) archiveDeny();
