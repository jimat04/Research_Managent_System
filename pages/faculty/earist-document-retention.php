<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-document-retention.php';

$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'faculty') { http_response_code(403); exit('Access denied.'); }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

function earistDocumentRetentionReturn(string $title, string $notice, string $kind = 'info'): never
{
    $_SESSION['earist_retention_notice'] = [
        'title' => $title,
        'message' => $notice,
        'kind' => in_array($kind, ['success', 'warning', 'error', 'info'], true) ? $kind : 'info',
    ];
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-generated-documents.php?retention=1', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    earistDocumentRetentionReturn('Request expired', 'Return to Generated Documents and try again.', 'error');
}
if (($_POST['action'] ?? '') !== 'cleanup') {
    http_response_code(400);
    earistDocumentRetentionReturn('Cleanup unavailable', 'The requested storage cleanup action was not recognized.', 'error');
}

try {
    $result = earistDocumentRetentionCleanup($conn);
    $completed = (int) $result['completed_sets'];
    $pending = (int) $result['pending_sets'];
    $setsLabel = $completed === 1 ? 'file set' : 'file sets';
    if ($pending > 0) {
        $title = $completed > 0 ? 'Cleanup partly complete' : 'Cleanup needs another try';
        $notice = $completed > 0
            ? number_format($completed) . ' older ' . $setsLabel . ' removed, reclaiming approximately ' . number_format((int) $result['bytes'] / 1048576, 2) . ' MB. '
                . number_format($pending) . ' set(s) still need attention. Your history and latest versions remain.'
            : number_format($pending) . ' older file set(s) could not be removed safely. Your history and latest versions remain available.';
        earistDocumentRetentionReturn($title, $notice, 'warning');
    } elseif ($completed > 0) {
        $notice = number_format($completed) . ' older ' . $setsLabel . ' were removed, reclaiming approximately '
            . number_format((int) $result['bytes'] / 1048576, 2) . ' MB. History entries remain and the latest versions were kept.';
        earistDocumentRetentionReturn('Storage cleanup complete', $notice, 'success');
    } else {
        earistDocumentRetentionReturn('You’re all caught up', 'No older DOCX file sets needed cleanup. The latest versions remain available.', 'info');
    }
} catch (Throwable $exception) {
    error_log('EARIST Faculty document retention failed: ' . get_class($exception));
    earistDocumentRetentionReturn('Cleanup couldn’t finish', 'RMS could not complete storage cleanup. Your document history remains available; refresh the preview and retry if older files are still listed.', 'error');
}
