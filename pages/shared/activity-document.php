<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity-schedule.php';
require_once __DIR__ . '/../../includes/activity-document.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);

if ($activityId <= 0 || !rmsActivityScheduleTableExists($conn, 'research_activities')
    || !rmsActivityScheduleColumnExists($conn, 'research_activities', 'document_upload_id')) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = $conn->prepare(
    'SELECT activity_id, activity_type, document_upload_id
       FROM research_activities WHERE activity_id = ? LIMIT 1'
);
if (!$stmt) {
    http_response_code(404);
    exit('Document not found.');
}
$stmt->bind_param('i', $activityId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(404);
    exit('Document not found.');
}
$activity = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$activity || (int) ($activity['document_upload_id'] ?? 0) <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$canManage = $role === 'admin';
if ($role === 'research_staff') {
    $pages = match ((string) $activity['activity_type']) {
        'seminar_workshop' => ['seminars_workshops'],
        'training' => ['trainings'],
        'colloquium', 'conference_congress' => ['seminars_workshops', 'trainings'],
        default => ['seminars_workshops', 'trainings'],
    };
    foreach ($pages as $page) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $page)) {
            $canManage = true;
            break;
        }
    }
}

if (!$canManage && !rmsActivityDocumentUserIsInvited($conn, $activityId, $userId)) {
    http_response_code(403);
    exit('Access denied.');
}

$document = rmsActivityDocumentLoad($conn, $activityId);
$documentPath = $document ? rmsActivityDocumentResolvePath($document) : null;
if (!$document || !$documentPath || (string) ($document['mime_type'] ?? '') !== 'application/pdf') {
    http_response_code(404);
    exit('Document not found.');
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
if (($_GET['mode'] ?? '') === 'stream') {
    $downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $document['original_name']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($documentPath));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
    readfile($documentPath);
    exit;
}

$styleNonce = base64_encode(random_bytes(18));
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$styleNonce}'; frame-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
$backUrl = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
$pdfUrl = SITE_URL . 'pages/shared/activity-document.php?id=' . $activityId . '&mode=stream';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Activity document — RMS</title>
    <style nonce="<?php echo htmlspecialchars($styleNonce, ENT_QUOTES, 'UTF-8'); ?>">
        *{box-sizing:border-box}
        body{margin:0;height:100vh;height:100dvh;display:flex;flex-direction:column;background:#f8fafc;color:#172033;font-family:Arial,sans-serif}
        .document-toolbar{display:flex;align-items:center;gap:24px;padding:16px 24px;background:#fff;border-bottom:1px solid #e2e8f0}
        .document-back{flex-shrink:0;display:inline-flex;align-items:center;min-height:44px;padding:10px 16px;border:1px solid #cbd5e1;border-radius:8px;color:#172033;text-decoration:none;font-size:14px;font-weight:600}
        .document-back:hover{background:#f1f5f9}
        .document-back:focus-visible{outline:3px solid #0d9488;outline-offset:3px}
        .document-name{margin:0;min-width:0;overflow-wrap:anywhere;font-size:14px;line-height:1.5;font-weight:600}
        .document-frame{display:block;flex:1;min-height:0;width:100%;border:0}
        @media(max-width:640px){.document-toolbar{align-items:flex-start;flex-direction:column;gap:8px;padding:12px 16px}.document-name{font-size:12px}}
    </style>
</head>
<body>
    <header class="document-toolbar">
        <a class="document-back" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">← Back to activity</a>
        <h1 class="document-name"><?php echo htmlspecialchars((string) $document['original_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <iframe class="document-frame" title="Activity PDF document" src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"></iframe>
</body>
</html>
