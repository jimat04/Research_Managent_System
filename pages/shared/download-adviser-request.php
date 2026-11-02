<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
$user = getCurrentUser();
if (!$user || !in_array((string) ($user['role'] ?? ''), ['student', 'faculty'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) {
    http_response_code(404);
    exit('Document not found.');
}

function adviserRequestDownloadTableExists(mysqli $connection): bool
{
    $stmt = $connection->prepare("SHOW TABLES LIKE 'adviser_requests'");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

if (!adviserRequestDownloadTableExists($conn)) {
    http_response_code(404);
    exit('Document not found.');
}

$userId = (int) $user['user_id'];
$stmt = $conn->prepare(
    'SELECT ar.requester_id, ar.faculty_id, ar.status, up.file_path, up.original_name, up.file_size
       FROM adviser_requests ar
       JOIN research_projects rp ON rp.project_id = ar.project_id AND rp.deleted_at IS NULL
       JOIN uploads up ON up.upload_id = ar.upload_id AND up.project_id = ar.project_id
      WHERE ar.request_id = ?
      LIMIT 1'
);
$stmt->bind_param('i', $requestId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if (!$document || !in_array($userId, [(int) $document['requester_id'], (int) $document['faculty_id']], true)) {
    http_response_code($document ? 403 : 404);
    exit($document ? 'Access denied.' : 'Document not found.');
}

$storedPath = str_replace('\\', '/', (string) $document['file_path']);
if (!preg_match('#^uploads/adviser_requests/rms_[A-Za-z0-9._-]+\.docx$#D', $storedPath)) {
    http_response_code(404);
    exit('Document not found.');
}
$uploadsRoot = realpath(__DIR__ . '/../../uploads');
$requestDirectory = $uploadsRoot ? realpath($uploadsRoot . DIRECTORY_SEPARATOR . 'adviser_requests') : false;
$filePath = $requestDirectory ? realpath($requestDirectory . DIRECTORY_SEPARATOR . basename($storedPath)) : false;
if (!$uploadsRoot || !$requestDirectory || !$filePath
    || strpos($requestDirectory, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
    || strpos($filePath, rtrim($requestDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
    || !is_file($filePath)) {
    http_response_code(404);
    exit('Document not found.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $document['original_name']);
$downloadName = trim((string) $downloadName);
if ($downloadName === '' || strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== 'docx') {
    $downloadName = 'adviser-request.docx';
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;