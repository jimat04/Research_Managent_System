<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/earist-document-editor.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new DomainException('Invalid callback.', 405);
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!$headers && isset($_SERVER['HTTP_AUTHORIZATION'])) $headers = ['Authorization' => $_SERVER['HTTP_AUTHORIZATION']];
    $authorization = '';
    foreach ($headers as $name => $value) if (strtolower((string) $name) === 'authorization') $authorization = (string) $value;
    if (!preg_match('/^Bearer ([A-Za-z0-9._-]+)$/D', $authorization, $match)) throw new DomainException('Invalid callback.', 403);
    $raw = file_get_contents('php://input', false, null, 0, 1048576);
    $body = is_string($raw) ? json_decode($raw, true, 64) : null;
    if (!is_array($body)) throw new DomainException('Invalid callback.', 400);
    earistDocumentEditorCallback($conn, (string) ($_GET['session'] ?? ''), $match[1], $body);
    echo json_encode(['error' => 0]);
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? ($exception->getCode() ?: 400) : 503);
    // Log only allowlisted failure categories and the callback status. Never include
    // the bearer token, userdata, session id, document key, or callback URL.
    $message = $exception->getMessage();
    $reason = match ($message) {
        'Invalid callback.' => 'callback_auth_or_payload',
        'Invalid editor callback.' => 'editor_callback_validation',
        'Invalid recovery callback.' => 'recovery_validation',
        'Invalid save request.' => 'save_userdata_mismatch',
        'Editor returned an invalid document URL.' => 'editor_url_origin',
        'Edit session expired.' => 'session_expired',
        'Edit session access denied.' => 'access_check',
        'The saved source version has changed.' => 'source_version_changed',
        'Document unavailable.' => 'source_validation',
        default => $exception instanceof DomainException ? 'domain_validation' : 'unexpected_error',
    };
    $relationshipPart = '';
    if (str_starts_with($message, 'DOCX relationship source is missing: ')) {
        $candidatePart = substr($message, strlen('DOCX relationship source is missing: '));
        if (preg_match('#^[A-Za-z0-9._/-]{1,255}$#D', $candidatePart)) $relationshipPart = $candidatePart;
        $reason = 'docx_relationship_source';
    } elseif ($reason === 'domain_validation') {
        if (in_array($message, ['Document access denied.', 'Invalid document record identity.', 'Research record is unavailable.',
            'Research record is unavailable for this operation.'], true)) {
            $reason = 'history_access_or_identity';
        } elseif (str_contains($message, 'DOCX') || str_contains($message, 'document file')
            || in_array($message, ['Invalid edited document.', 'Invalid document file set.', 'Invalid stored document manifest.',
                'Invalid stored document reference.', 'Stored document files belong to different versions.',
                'Requested document file is unavailable.', 'The selected document is not available.'], true)) {
            $reason = 'edited_docx_validation';
        }
    }
    $callbackStatus = isset($body['status']) && is_numeric($body['status']) ? (int) $body['status'] : -1;
    $errorFingerprint = $message !== '' ? substr(hash('sha256', $message), 0, 16) : 'none';
    $partLog = $relationshipPart !== '' ? ' part=' . $relationshipPart : '';
    $exceptionClass = $exception instanceof DomainException ? 'DomainException' : get_class($exception);
    $exceptionCode = (int) $exception->getCode();
    $uniqueKey = 'none';
    if ($exception instanceof mysqli_sql_exception && $exceptionCode === 1062) {
        foreach (['uq_earist_generated_request', 'uq_earist_generated_version', 'uq_earist_generated_docx', 'uq_earist_generated_pdf'] as $candidateKey) {
            if (str_contains($message, $candidateKey)) { $uniqueKey = $candidateKey; break; }
        }
    }
    error_log('EARIST editor callback rejected: reason=' . $reason . ' status=' . $callbackStatus
        . ' class=' . $exceptionClass . ' code=' . $exceptionCode . ' unique=' . $uniqueKey
        . ' error=' . $errorFingerprint . $partLog);
    echo json_encode(['error' => 1]);
}
