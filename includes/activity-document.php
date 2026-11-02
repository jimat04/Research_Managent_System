<?php

function rmsActivityDocumentLoad(mysqli $conn, int $activityId): ?array
{
    if ($activityId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT a.activity_id, a.document_upload_id, u.original_name, u.file_path, u.file_size, u.mime_type
           FROM research_activities a
           INNER JOIN uploads u ON u.upload_id = a.document_upload_id
          WHERE a.activity_id = ? AND u.type = \'other\'
            AND u.project_id IS NULL AND u.chapter_id IS NULL
          LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $document;
}

function rmsActivityDocumentUserIsInvited(mysqli $conn, int $activityId, int $userId): bool
{
    if ($activityId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT EXISTS (
            SELECT 1
              FROM activity_schedule_dates d
              INNER JOIN activity_schedule_participants p ON p.schedule_id = d.schedule_id
             WHERE d.activity_id = ? AND d.status = 'scheduled'
               AND p.user_id = ? AND p.is_invited = 1
        ) AS is_invited"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $activityId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $invited = (int) ($stmt->get_result()->fetch_assoc()['is_invited'] ?? 0) === 1;
    $stmt->close();
    return $invited;
}

function rmsActivityDocumentResolvePath(array $document): ?string
{
    $relativePath = str_replace('\\', '/', (string) ($document['file_path'] ?? ''));
    if (!preg_match('~^uploads/activities/[A-Za-z0-9_.-]+\.pdf$~iD', $relativePath)) {
        return null;
    }

    $activitiesRoot = realpath(UPLOADS_DIR . DIRECTORY_SEPARATOR . 'activities');
    $filePath = realpath(UPLOADS_DIR . DIRECTORY_SEPARATOR . 'activities' . DIRECTORY_SEPARATOR . basename($relativePath));
    if (!$activitiesRoot || !$filePath || !is_file($filePath)) {
        return null;
    }
    $rootPrefix = rtrim($activitiesRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($filePath, $rootPrefix) ? $filePath : null;
}

function rmsActivityDocumentAttach(mysqli $conn, int $activityId, int $uploadId, int $adminId): ?int
{
    $uploadStmt = $conn->prepare(
        "SELECT upload_id FROM uploads
          WHERE upload_id = ? AND uploaded_by = ? AND type = 'other'
            AND project_id IS NULL AND chapter_id IS NULL
            AND file_path LIKE 'uploads/activities/%' AND mime_type = 'application/pdf'
          LIMIT 1"
    );
    if (!$uploadStmt) {
        throw new RuntimeException('Unable to verify the activity document upload.');
    }
    $uploadStmt->bind_param('ii', $uploadId, $adminId);
    if (!$uploadStmt->execute() || !$uploadStmt->get_result()->fetch_assoc()) {
        $uploadStmt->close();
        throw new RuntimeException('The uploaded activity document is not valid.');
    }
    $uploadStmt->close();

    $oldStmt = $conn->prepare('SELECT document_upload_id FROM research_activities WHERE activity_id = ? LIMIT 1');
    if (!$oldStmt) {
        throw new RuntimeException('Unable to load the current activity document.');
    }
    $oldStmt->bind_param('i', $activityId);
    if (!$oldStmt->execute()) {
        $oldStmt->close();
        throw new RuntimeException('Unable to load the current activity document.');
    }
    $activity = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    if (!$activity) {
        throw new RuntimeException('The activity no longer exists.');
    }
    $oldUploadId = (int) ($activity['document_upload_id'] ?? 0);

    $stmt = $conn->prepare('UPDATE research_activities SET document_upload_id = ? WHERE activity_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to attach the document to this activity.');
    }
    $stmt->bind_param('ii', $uploadId, $activityId);
    $saved = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    if (!$saved) {
        throw new RuntimeException('Unable to attach the document to this activity.');
    }

    return $oldUploadId > 0 && $oldUploadId !== $uploadId ? $oldUploadId : null;
}

function rmsActivityDocumentDeleteUpload(mysqli $conn, int $uploadId): bool
{
    if ($uploadId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT file_path FROM uploads
          WHERE upload_id = ? AND type = 'other' AND project_id IS NULL AND chapter_id IS NULL
            AND mime_type = 'application/pdf'
            AND file_path LIKE 'uploads/activities/%' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $uploadId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $filePath = (string) ($stmt->get_result()->fetch_assoc()['file_path'] ?? '');
    $stmt->close();
    if ($filePath === '') {
        return false;
    }

    $references = $conn->prepare('SELECT COUNT(*) AS reference_count FROM research_activities WHERE document_upload_id = ?');
    if (!$references) {
        return false;
    }
    $references->bind_param('i', $uploadId);
    if (!$references->execute() || (int) ($references->get_result()->fetch_assoc()['reference_count'] ?? 0) > 0) {
        $references->close();
        return false;
    }
    $references->close();

    $documentPath = rmsActivityDocumentResolvePath(['file_path' => $filePath]);
    if (!$documentPath || !unlink($documentPath)) {
        return false;
    }
    $delete = $conn->prepare(
        "DELETE FROM uploads WHERE upload_id = ? AND type = 'other'
          AND project_id IS NULL AND chapter_id IS NULL AND file_path = ?"
    );
    if (!$delete) {
        return false;
    }
    $delete->bind_param('is', $uploadId, $filePath);
    $deleted = $delete->execute() && $delete->affected_rows === 1;
    $delete->close();
    return $deleted;
}
