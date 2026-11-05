<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-document-editor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'faculty') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new DomainException('Invalid recovery request.', 405);
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) throw new DomainException('Your request expired. Return to Generated Documents and try again.', 400);
    $generatedId = earistDocumentId($_POST['generated_document_id'] ?? null);
    $selector = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
    echo json_encode(earistDocumentEditorRequestRecoveryCheck($conn, $generatedId, $selector), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? ($exception->getCode() ?: 400) : 503);
    error_log('EARIST editor recovery check failed: ' . get_class($exception));
    echo json_encode(['error' => $exception instanceof DomainException ? $exception->getMessage() : 'RMS could not confirm whether the other editor is closed. The lock was kept.']);
}
