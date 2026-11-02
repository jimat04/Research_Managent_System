<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-document-generation.php';
require_once __DIR__ . '/../../includes/earist-annex-d-service.php';

requireLogin();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
try {
    $generatedId = earistDocumentId($_GET['id'] ?? null);
    $selector = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
    $scope = $_GET['scope'] ?? 'faculty';
    if (!is_string($scope) || !in_array($scope, ['faculty', 'annex_d'], true)) throw new DomainException('Invalid download scope.', 400);
    $download = $scope === 'annex_d' ? earistAnnexDHistoryOpen($conn, $generatedId, $selector)
        : earistDocumentFacultyHistoryOpen($conn, $generatedId, $selector);
    $filename = $download['filename'];
    if (!preg_match('/^[a-z0-9-]+\.docx$/D', $filename)) throw new DomainException('Generated document is unavailable.', 404);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (int) $download['size']);
    fpassthru($download['handle']);
    fclose($download['handle']);
    exit;
} catch (DomainException $exception) {
    http_response_code(in_array($exception->getCode(), [400, 403, 404], true) ? $exception->getCode() : 404);
    exit('Generated document is unavailable.');
} catch (Throwable $exception) {
    error_log('EARIST faculty download failed: ' . get_class($exception) . ', code ' . (int) $exception->getCode());
    http_response_code(503);
    exit('Generated document is unavailable.');
}
