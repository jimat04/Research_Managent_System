<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/earist-document-editor.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
try {
    $file = earistDocumentEditorReadFile($conn, (string) ($_GET['session'] ?? ''), (string) ($_GET['token'] ?? ''));
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('Content-Length: ' . (int) $file['size']);
    fpassthru($file['handle']); fclose($file['handle']);
} catch (Throwable $exception) { http_response_code(404); }
