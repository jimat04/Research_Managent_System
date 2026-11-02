<?php
/**
 * RMS File Upload AJAX Endpoint
 *
 * Handles file uploads via AJAX with JSON response
 * Called by the file-uploader.js component
 */

// Load dependencies
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

// Set JSON header
header('Content-Type: application/json');

// Security checks
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF validation
if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get upload configuration from POST data
$folderTarget = $_POST['folder_target'] ?? 'proposals';
$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : null;
$chapterId = isset($_POST['chapter_id']) ? (int) $_POST['chapter_id'] : null;

// Determine upload type and settings based on folder target
$uploadConfig = [
    'proposals' => [
        'accept' => ['.pdf'],
        'maxSize' => 10000, // 10MB
        'type' => 'proposal'
    ],
    'chapters' => [
        'accept' => ['.pdf'],
        'maxSize' => 10000, // 10MB
        'type' => 'chapter'
    ],
    'defense' => [
        'accept' => ['.pdf', '.ppt', '.pptx'],
        'maxSize' => 20000, // 20MB
        'type' => 'defense'
    ],
    'manuscripts' => [
        'accept' => ['.pdf'],
        'maxSize' => 20000, // 20MB
        'type' => 'manuscript'
    ],
    'engagement' => [
        'accept' => ['.pdf', '.doc', '.docx'],
        'maxSize' => 10000, // 10MB
        'type' => 'engagement'
    ],
    'activities' => [
        'accept' => ['.pdf'],
        'maxSize' => 20000, // 20MB
        'type' => 'other'
    ]
];

if (!isset($uploadConfig[$folderTarget])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload destination']);
    exit;
}
$config = $uploadConfig[$folderTarget];
if ($folderTarget === 'engagement' && !in_array((string) ($_SESSION['role'] ?? ''), ['faculty', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You are not allowed to upload engagement evidence']);
    exit;
}
if ($folderTarget === 'activities' && (string) ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only administrators may upload activity documents']);
    exit;
}

// Detect input name (check both singular and array formats)
$inputName = null;
foreach ($_FILES as $key => $file) {
    $inputName = $key;
    break;
}

if (!$inputName) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

// Handle the upload
$result = handleRmsUpload([
    'inputName' => $inputName,
    'folderTarget' => $folderTarget,
    'maxSize' => $config['maxSize'],
    'accept' => $config['accept'],
    'projectId' => $projectId,
    'chapterId' => $chapterId,
    'type' => $config['type'],
    'workflowType' => [
        'proposals' => 'title',
        'chapters' => 'chapter',
        'manuscripts' => 'final_manuscript',
    ][$folderTarget] ?? null
], $_FILES, $conn);

// Return JSON response
if ($result['success']) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'upload_id' => $result['upload_id'],
        'file_name' => $result['file_name'],
        'file_path' => $result['file_path'],
        'original_name' => $result['original_name'],
        'size' => $result['size']
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $result['error']
    ]);
}
