<?php
/** Separate live Admin reader. Does not enable Annex D in the faculty mapping policy. */
require_once __DIR__ . '/earist-document-generation.php';

/** Owns its consistent read transaction; caller must not have a transaction open. */
function earistAnnexDSourceForUser(mysqli $conn, int $recordId): array
{
    $admin = earistDocumentCurrentActor('annex_d');
    earistDocumentId($recordId);
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
        || !$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
        throw new RuntimeException('Unable to start the Admin document read.');
    }
    try {
        // Source selection is restricted to current eligible faculty accounts. The
        // faculty owner comes from SQL, never from Admin input or Admin ownership.
        $stmt = $conn->prepare("SELECT r.owner_id FROM faculty_research_records r JOIN users u ON u.user_id = r.owner_id
            WHERE r.research_record_id = ? AND r.deleted_at IS NULL AND r.status IN ('active','archived')
            AND u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new DomainException('Faculty research record is unavailable.', 404);
        // Reuse the unchanged owner-scoped low-level loader with SQL-derived owner.
        // This does not grant the Admin access through any faculty entry point.
        $record = facultyRecordLoadOwned($conn, $recordId, (int) $row['owner_id']);
        earistDocumentAssertSource($admin, 'annex_d', $record);
        $conn->commit();
        return $record;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function earistAnnexDReviewForUser(mysqli $conn, int $recordId, array $inputs = []): array
{
    $admin = earistDocumentCurrentActor('annex_d');
    return earistDocumentBuildReview($admin, [], earistAnnexDSourceForUser($conn, $recordId), 'annex_d', $inputs);
}

/** Eligible source discovery confers no access to another Admin's drafts/history. */
function earistAnnexDSources(mysqli $conn, string $search, int $page = 1): array
{
    earistDocumentCurrentActor('annex_d');
    if (mb_strlen($search, 'UTF-8') > 200 || $page < 1 || $page > 100000) throw new DomainException('Invalid source search.', 400);
    $pattern = '%' . $search . '%';
    $pageSize = 5;
    $offset = ($page - 1) * $pageSize;
    $stmt = $conn->prepare("SELECT r.research_record_id, r.title, r.status, r.version, u.first_name, u.last_name
        FROM faculty_research_records r JOIN users u ON u.user_id = r.owner_id
        WHERE r.deleted_at IS NULL AND r.status IN ('active','archived') AND u.role = 'faculty'
        AND u.status = 'active' AND u.deleted_at IS NULL AND (r.title LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?)
        ORDER BY r.research_record_id DESC LIMIT 6 OFFSET ?");
    $stmt->bind_param('sssi', $pattern, $pattern, $pattern, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['rows' => array_slice($rows, 0, $pageSize), 'has_next' => count($rows) > $pageSize, 'page' => $page];
}

function earistAnnexDDraftLoad(mysqli $conn, int $recordId): ?array
{
    return earistDocumentDraftLoadForActor($conn, $recordId, 'annex_d');
}

function earistAnnexDDraftSave(mysqli $conn, int $recordId, array $inputs, int $revision, string $requestId, string $fingerprint): array
{
    return earistDocumentDraftSaveReviewed($conn, $recordId, 'annex_d', $inputs, $revision, $requestId, false, $fingerprint);
}

function earistAnnexDGenerationContext(mysqli $conn, int $recordId): array
{
    $actor = earistDocumentCurrentActor('annex_d');
    $draft = earistAnnexDDraftLoad($conn, $recordId);
    if (!$draft) throw new DomainException('Review and save Annex D answers before generation.', 409);
    $context = earistAnnexDReviewForUser($conn, $recordId, $draft['_envelope']['inputs']);
    return earistDocumentGenerationContextForDraft($actor, $draft, $context['review']['mapping'], 'annex_d');
}

/** Pure evidence validation. Identity/time/confirmations are set by the Admin page in the server session. */
function earistAnnexDGenerationEvidence(array $review, array $actor, array $state): ?array
{
    earistDocumentAssertActor($actor, 'annex_d');
    if (($state['requested_mode'] ?? '') === 'unissued') return null;
    if (($state['requested_mode'] ?? '') !== 'verified' || ($state['policy_confirmed'] ?? false) !== true
        || ($state['verified_against_records'] ?? false) !== true
        || ($state['verified_by'] ?? null) !== (int) $actor['user_id']) {
        throw new DomainException('Confirm institutional policy and evaluation-record verification for this exact preparation.', 409);
    }
    $evidence = ['verified_by' => (int) $actor['user_id'], 'verified_at' => $state['verified_at'] ?? '',
        'verified_payload_sha256' => $state['payload_sha256'] ?? ''];
    if (!earistRenderAnnexDVerified($review, $evidence)) throw new DomainException('Complete all required Annex D information before verified preparation.', 409);
    return $evidence;
}

function earistAnnexDGenerate(mysqli $conn, int $recordId, int $revision, string $token, string $payload): array
{
    return earistDocumentGenerateReviewed($conn, $recordId, 'annex_d', $revision, $token, $payload);
}

function earistAnnexDHistoryList(mysqli $conn, int $page = 1): array
{
    return earistDocumentHistoryListForScope($conn, true, $page, 5);
}

function earistAnnexDHistoryOpen(mysqli $conn, int $id, string $selector): array
{
    return earistDocumentHistoryOpenForScope($conn, $id, $selector, true);
}
