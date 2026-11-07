<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-google-drive.php';

$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'faculty') { http_response_code(403); exit('Access denied.'); }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
try {
    earistGoogleDriveOAuthCallback($conn, (int) $user['user_id'], $_GET);
    $_SESSION['earist_google_notice'] = 'Google Drive connected. RMS can now create DOCX working copies and import your edits.';
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? ((int) $exception->getCode() ?: 400) : 503);
    error_log('EARIST Google OAuth callback failed: ' . get_class($exception));
    $_SESSION['earist_google_notice'] = $exception instanceof DomainException
        ? $exception->getMessage()
        : 'Google account connection failed. Check the OAuth setup and try again.';
}
header('Location: ' . SITE_URL . 'pages/faculty/faculty-generated-documents.php?google=1', true, 303);
exit;
