<?php
/** Read-only verifier for migration 069's generated-file retention metadata. */
require_once __DIR__ . '/earist-document-schema.php';

function earistDocumentRetentionSchemaStatus(mysqli $conn): bool
{
    if (empty(earistDocumentSchemaStatus($conn)['valid'])) return false;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'earist_generated_documents' AND COLUMN_NAME IN ('file_retention_status','file_retention_started_at','file_retention_completed_at','file_retention_actor_id','file_retention_bytes')");
    if (!$stmt || !$stmt->execute()) { if ($stmt) $stmt->close(); return false; }
    $count = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count === 5;
}
