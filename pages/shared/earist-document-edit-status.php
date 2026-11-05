<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/earist-document-editor.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireLogin();
try {
    $markerValue = $_GET['recovery_since'] ?? null;
    $marker = is_string($markerValue) && preg_match('/^[0-9]{10}$/D', $markerValue) ? (int) $markerValue : null;
    echo json_encode(earistDocumentEditorStatus($conn, (string) ($_GET['session'] ?? ''), $marker), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? ($exception->getCode() ?: 404) : 503);
    echo json_encode(['state' => 'unavailable']);
}
