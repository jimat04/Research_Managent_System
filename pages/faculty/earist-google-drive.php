<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-google-drive.php';

$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'faculty') { http_response_code(403); exit('Access denied.'); }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

function earistGoogleDriveReturn(string $notice): never
{
    $_SESSION['earist_google_notice'] = $notice;
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-generated-documents.php?google=1', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    earistGoogleDriveReturn('The request expired. Return to Generated Documents and try again.');
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
try {
    earistGoogleDriveAssertEnabled();
    if ($action === 'connect') {
        earistGoogleDriveStartOAuth((int) $user['user_id']);
    }
    if ($action === 'disconnect') {
        $revoked = earistGoogleDriveDisconnect($conn, (int) $user['user_id']);
        earistGoogleDriveReturn($revoked
            ? 'Google Drive disconnected. RMS removed its saved authorization; your Drive files remain in Google Drive.'
            : 'RMS removed its saved authorization, but Google did not confirm revocation. You can also remove RMS in your Google Account security settings. Your Drive files remain in Google Drive.');
    }
    if ($action === 'open') {
        $generatedId = earistDocumentId($_POST['generated_document_id'] ?? null);
        $selector = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
        $workingCopy = earistGoogleDriveCreateWorkingCopy($conn, (int) $user['user_id'], $generatedId, $selector);
        earistGoogleDriveValidateWebLink((string) $workingCopy['web_view_link']);
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $documentUrl = (string) $workingCopy['web_view_link'];
        $documentUrlJson = json_encode($documentUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $selectorJson = json_encode($selector, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $documentUrlHtml = htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Opening Google Docs</title></head><body><p>Opening your Google Docs working copy…</p><p>If it does not open, <a href="' . $documentUrlHtml . '">continue to Google Docs</a>.</p><script>
          const documentUrl = ' . $documentUrlJson . ';
          try {
            const channel = new BroadcastChannel("earist-google-drive-copy-created");
            channel.postMessage({type: "copy-created", generatedDocumentId: ' . $generatedId . ', fileSelector: ' . $selectorJson . '});
            channel.close();
          } catch (error) {}
          window.location.replace(documentUrl);
        </script></body></html>';
        exit;
    }
    if ($action === 'import') {
        $fileId = is_string($_POST['drive_file_id'] ?? null) ? $_POST['drive_file_id'] : '';
        $generatedId = earistGoogleDriveImportToRms($conn, (int) $user['user_id'], $fileId);
        $_SESSION['earist_google_notice'] = 'The Google Drive version is now in RMS history. The original version remains available.';
        header('Location: ' . SITE_URL . 'pages/faculty/faculty-generated-documents.php?created=' . $generatedId . '&google=1', true, 303);
        exit;
    }
    throw new DomainException('Unknown Google Drive action.', 400);
} catch (Throwable $exception) {
    $code = $exception instanceof DomainException ? (int) $exception->getCode() : 503;
    http_response_code(in_array($code, [400, 401, 403, 404, 409, 413], true) ? $code : 503);
    $safeAction = in_array($action, ['connect', 'disconnect', 'open', 'import'], true) ? $action : 'unknown';
    $diagnosticCode = (int) $exception->getCode();
    error_log('EARIST Google Drive action failed: action=' . $safeAction . ', exception=' . get_class($exception)
        . ', diagnostic_code=' . ($diagnosticCode > 0 ? $diagnosticCode : 'none'));
    earistGoogleDriveReturn($exception instanceof DomainException
        ? $exception->getMessage()
        : 'Google Drive could not complete the request. Your saved RMS history remains available.');
}
