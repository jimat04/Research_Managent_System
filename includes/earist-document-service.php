<?php
/** Stage 4C live preparation services. Generation/history remain separately gated.
 * Pure policy helpers are not authentication. Live readers obtain the current user
 * themselves; request-supplied actor/source arrays must never reach internal helpers.
 */
require_once __DIR__ . '/earist-docx-renderer.php';
require_once __DIR__ . '/earist-document-files.php';

function earistDocumentId($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
        || strlen((string) $value) > 10 || (int) $value > 4294967295) throw new DomainException('Invalid document record identity.', 400);
    return (int) $value;
}

/** Exact role/active-account policy; intentionally has no general Admin override. */
function earistDocumentAssertActor(array $actor, string $type): void
{
    $document = earistMappingDocument($type);
    $role = $type === 'annex_d' ? 'admin' : 'faculty';
    if (($actor['role'] ?? '') !== $role || ($actor['status'] ?? '') !== 'active'
        || !empty($actor['deleted_at']) || ($type !== 'annex_d' && (!$document['enabled'] || $document['role'] !== 'faculty'))) {
        throw new DomainException('Document access denied.', 403);
    }
    earistDocumentId($actor['user_id'] ?? null);
}

/** Uses RMS live session/account validation, not session role fields in isolation. */
function earistDocumentCurrentActor(string $type): array
{
    $actor = getCurrentUser();
    if (!is_array($actor)) throw new DomainException('Document access denied.', 403);
    earistDocumentAssertActor($actor, $type);
    return $actor;
}

/** History deliberately permits a soft-deleted source; preparation does not. */
function earistDocumentAssertSource(array $actor, string $type, ?array $record, string $operation = 'prepare'): void
{
    earistDocumentAssertActor($actor, $type);
    if (!in_array($operation, ['prepare', 'history'], true)) throw new DomainException('Unsupported document operation.', 400);
    if (!$record) throw new DomainException('Research record is unavailable.', 404);
    earistDocumentId($record['research_record_id'] ?? null);
    $sourceOwner = earistDocumentId($record['owner_id'] ?? null);
    earistDocumentId($record['version'] ?? null);
    if (($type !== 'annex_d' && $sourceOwner !== earistDocumentId($actor['user_id']))
        || !in_array($record['status'] ?? '', ['active', 'archived'], true)
        || !array_key_exists('deleted_at', $record)
        || ($operation === 'prepare' && $record['deleted_at'] !== null)) {
        throw new DomainException('Research record is unavailable for this operation.', 404);
    }
}

/** All three rows must be loaded by the authorized live caller, never by the client. */
function earistDocumentAssertHistory(array $actor, array $document, array $draft, array $source): void
{
    $type = $document['document_type'] ?? '';
    if (!is_string($type)) throw new DomainException('Document access denied.', 403);
    earistDocumentAssertSource($actor, $type, $source, 'history');
    $owner = earistDocumentId($actor['user_id']);
    $recordId = earistDocumentId($source['research_record_id']);
    foreach ([$document, $draft] as $row) {
        if (earistDocumentId($row['owner_id'] ?? null) !== $owner
            || earistDocumentId($row['research_record_id'] ?? null) !== $recordId
            || ($row['document_type'] ?? '') !== $type) throw new DomainException('Document access denied.', 403);
    }
    if (earistDocumentId($document['draft_id'] ?? null) !== earistDocumentId($draft['draft_id'] ?? null)
        || earistDocumentId($document['generated_by'] ?? null) !== $owner
        || earistDocumentId($draft['created_by'] ?? null) !== $owner
        || earistDocumentId($draft['updated_by'] ?? null) !== $owner) throw new DomainException('Document access denied.', 403);
}

/** Full allowlisted source context includes profile changes as well as authors/record. */
function earistDocumentSourceFingerprint(array $mapping): string
{
    if (!is_array($mapping['source'] ?? null) || !is_string($mapping['document']['key'] ?? null)) throw new DomainException('Invalid document source.');
    return earistRenderHash(['document_type' => $mapping['document']['key'], 'source' => $mapping['source']]);
}

/** Minimal stale-author evidence for reusable drafts; not a copied faculty profile. */
function earistDocumentAuthorReferences(array $mapping): array
{
    $references = [];
    foreach ($mapping['source']['record']['authors'] ?? [] as $author) {
        $id = earistDocumentId($author['record_author_id'] ?? null);
        if (isset($references[$id])) throw new DomainException('Duplicate saved author identity.');
        $identity = array_intersect_key($author, array_flip(['record_author_id', 'faculty_user_id', 'author_type',
            'full_name', 'position', 'affiliation', 'author_role', 'display_order']));
        $references[$id] = ['full_name' => $author['full_name'] ?? '',
            'display_order' => $author['display_order'] ?? 0, 'identity_sha256' => earistRenderHash($identity)];
    }
    return $references;
}

/** Internal presentation context. A content fingerprint is not user acknowledgment. */
function earistDocumentReviewDetails(array $mapping, int $documentOwner): array
{
    $review = earistRenderReview($mapping);
    $record = $review['mapping']['source']['record'];
    return ['document_owner_id' => earistDocumentId($documentOwner),
        'source_owner_id' => earistDocumentId($record['owner_id']),
        'research_record_id' => earistDocumentId($record['research_record_id']),
        'source_record_version' => earistDocumentId($record['version']),
        'source_fingerprint' => earistDocumentSourceFingerprint($review['mapping']),
        'input_schema_version' => earistDocumentStorageConfig()['input_schema_version'],
        'author_references' => earistDocumentAuthorReferences($review['mapping']), 'review' => $review];
}

/** Pure builder for an already-authorized snapshot; also usable by synthetic checks. */
function earistDocumentBuildReview(array $actor, array $profile, array $record, string $type, array $inputs = []): array
{
    earistDocumentAssertSource($actor, $type, $record);
    $mapping = $type === 'annex_d' ? earistAnnexDMappingBuild($record, $inputs) : earistMappingBuild($profile, $record, $type, $inputs);
    return earistDocumentReviewDetails($mapping, earistDocumentId($actor['user_id']));
}

/** Owns the existing service's read transaction; never call within a write transaction. */
function earistDocumentFacultyReview(mysqli $conn, int $recordId, string $type, array $inputs = []): array
{
    $actor = earistDocumentCurrentActor($type);
    if ($type === 'annex_d') throw new DomainException('Use the separate Admin preparation service.', 403);
    $mapping = earistMappingForUser($conn, $actor, $recordId, $type, $inputs);
    return earistDocumentReviewDetails($mapping, earistDocumentId($actor['user_id']));
}

/** Decode only the versioned, allowlisted faculty draft envelope. */
function earistDocumentDraftEnvelope(?array $row): array
{
    if ($row === null) return ['version' => 1, 'inputs' => [], 'author_references' => [], 'orphaned_author_inputs' => [], 'last_save' => null];
    try { $envelope = json_decode((string) $row['inputs_json'], true, 128, JSON_THROW_ON_ERROR); }
    catch (Throwable $exception) { throw new RuntimeException('Saved document answers could not be read. Ask the administrator to check the draft.', 0, $exception); }
    if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1
        || !is_array($envelope['inputs'] ?? null) || !is_array($envelope['author_references'] ?? null)
        || !is_array($envelope['orphaned_author_inputs'] ?? null)
        || !(($envelope['last_save'] ?? null) === null || is_array($envelope['last_save']))) {
        throw new RuntimeException('Saved document answers use an unsupported format. Ask the administrator to check the draft.');
    }
    return $envelope;
}

/** Read a faculty's own per-record/per-form draft; coauthors and other roles do not qualify. */
function earistDocumentFacultyDraftLoad(mysqli $conn, int $recordId, string $type): ?array
{
    if ($type === 'annex_d') throw new DomainException('Use the separate Admin preparation service.', 403);
    return earistDocumentDraftLoadForActor($conn, $recordId, $type);
}

/** Internal shared reader; exact current role determines the document owner. */
function earistDocumentDraftLoadForActor(mysqli $conn, int $recordId, string $type): ?array
{
    $actor = earistDocumentCurrentActor($type);
    $recordId = earistDocumentId($recordId);
    $ownerId = earistDocumentId($actor['user_id']);
    $stmt = $conn->prepare('SELECT * FROM earist_document_drafts WHERE research_record_id = ? AND owner_id = ? AND document_type = ? LIMIT 1');
    $stmt->bind_param('iis', $recordId, $ownerId, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) {
        earistDocumentAssertDraftOwner($actor, $row, $recordId, $type);
        $row['_envelope'] = earistDocumentDraftEnvelope($row);
    }
    return $row;
}

function earistDocumentAssertDraftOwner(array $actor, array $draft, int $recordId, string $type): void
{
    earistDocumentAssertActor($actor, $type);
    $id = earistDocumentId($actor['user_id']);
    if ((int) ($draft['owner_id'] ?? 0) !== $id || (int) ($draft['created_by'] ?? 0) !== $id
        || (int) ($draft['updated_by'] ?? 0) !== $id || (int) ($draft['research_record_id'] ?? 0) !== $recordId
        || ($draft['document_type'] ?? '') !== $type) throw new DomainException('Document draft access denied.', 403);
}

/** Keep removed-author contribution answers separate until the faculty explicitly reconciles them. */
function earistDocumentDraftSplitInputs(array $inputs, array $authorReferences, array $authors): array
{
    $current = [];
    foreach ($authors as $author) $current[(string) earistDocumentId($author['record_author_id'] ?? null)] = true;
    $orphaned = [];
    $contributions = $inputs['contributions'] ?? [];
    if (is_array($contributions)) foreach ($contributions as $authorId => $answers) {
        $safeId = (string) earistDocumentId((string) $authorId);
        if (!isset($current[$safeId])) {
            $orphaned[$safeId] = ['author' => $authorReferences[$safeId] ?? ['full_name' => 'Previously saved author', 'display_order' => 0],
                'answers' => is_array($answers) ? $answers : []];
            unset($contributions[$authorId]);
        }
    }
    if (array_key_exists('contributions', $inputs)) $inputs['contributions'] = $contributions;
    return ['inputs' => $inputs, 'orphaned' => $orphaned];
}

function earistDocumentDraftVersionContext(string $type): array
{
    $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
    $template = $manifest['documents'][$type] ?? null;
    if (!is_array($template) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($template['sha256'] ?? ''))) {
        throw new RuntimeException('The approved document template definition is unavailable.');
    }
    return ['input_schema_version' => (int) earistDocumentStorageConfig()['input_schema_version'],
        'mapping_definition_version' => (int) earistMappingDefinitions()['version'],
        'binding_version' => (int) $manifest['version'], 'template_sha256' => $template['sha256']];
}

function earistDocumentDraftContentHash(string $sourceFingerprint, array $inputs, array $orphaned,
    bool $discardOrphans, array $versionContext = []): string
{
    return earistRenderHash(['source_fingerprint' => $sourceFingerprint, 'versions' => $versionContext, 'inputs' => $inputs,
        'orphaned_author_inputs' => $discardOrphans ? [] : $orphaned, 'discard_orphans' => $discardOrphans]);
}

function earistDocumentDraftReviewSessionKey(int $actorId, int $recordId, string $type, string $requestHash = ''): string
{
    return 'earist_draft_review_' . hash('sha256', $actorId . ':' . $recordId . ':' . $type . ':' . $requestHash);
}

/** Exact replay is a no-op; the same successful request identity cannot change content. */
function earistDocumentDraftIsReplay(?array $lastSave, string $requestHash, string $contentHash): bool
{
    if ($lastSave === null || !hash_equals((string) ($lastSave['request_id_sha256'] ?? ''), $requestHash)) return false;
    if (!hash_equals((string) ($lastSave['content_sha256'] ?? ''), $contentHash)) {
        throw new DomainException('This save request was already used for different answers. Reload and review again.', 409);
    }
    return true;
}

/** Internal: caller owns a write transaction. Lock account, source owner, then parent. */
function earistDocumentLockPreparationSource(mysqli $conn, array $actor, string $type, int $recordId): array
{
    earistDocumentAssertActor($actor, $type);
    $actorId = earistDocumentId($actor['user_id']);
    $role = $type === 'annex_d' ? 'admin' : 'faculty';
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = ? AND status = 'active' AND deleted_at IS NULL FOR UPDATE");
    $stmt->bind_param('is', $actorId, $role);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) throw new DomainException('Document access denied.', 403);
    $sourceOwner = $actorId;
    if ($type === 'annex_d') {
        $stmt = $conn->prepare('SELECT owner_id FROM faculty_research_records WHERE research_record_id = ?');
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new DomainException('Faculty research record is unavailable.', 404);
        $sourceOwner = (int) $row['owner_id'];
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'faculty' AND status = 'active' AND deleted_at IS NULL FOR UPDATE");
        $stmt->bind_param('i', $sourceOwner);
        $stmt->execute();
        $faculty = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$faculty) throw new DomainException('Faculty research record is unavailable.', 404);
    }
    $stmt = $conn->prepare('SELECT version FROM faculty_research_records WHERE research_record_id = ? AND owner_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $recordId, $sourceOwner);
    $stmt->execute();
    $locked = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$locked) throw new DomainException('Research record is unavailable.', 404);
    $record = facultyRecordLoadOwned($conn, $recordId, $sourceOwner);
    earistDocumentAssertSource($actor, $type, $record);
    if ((int) $record['version'] !== (int) $locked['version']) throw new DomainException('Research information changed. Review it again.', 409);
    $profile = $type === 'annex_d' ? [] : facultyRecordProfile($conn, $sourceOwner);
    if ($type !== 'annex_d' && !$profile) throw new DomainException('Document access denied.', 403);
    return ['record' => $record, 'profile' => $profile];
}

/** Save only a reviewed, valid payload. The source parent lock serializes author/source edits. */
function earistDocumentFacultyDraftSave(mysqli $conn, int $recordId, string $type, array $rawInputs,
    int $expectedRevision, string $requestId, bool $discardOrphans, string $reviewedFingerprint): array
{
    if ($type === 'annex_d') throw new DomainException('Use the separate Admin preparation service.', 403);
    return earistDocumentDraftSaveReviewed($conn, $recordId, $type, $rawInputs, $expectedRevision,
        $requestId, $discardOrphans, $reviewedFingerprint);
}

/** Internal common save; callers cannot supply an actor or source owner. */
function earistDocumentDraftSaveReviewed(mysqli $conn, int $recordId, string $type, array $rawInputs,
    int $expectedRevision, string $requestId, bool $discardOrphans, string $reviewedFingerprint): array
{
    $actor = earistDocumentCurrentActor($type);
    $recordId = earistDocumentId($recordId);
    $ownerId = earistDocumentId($actor['user_id']);
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestId) || !preg_match('/^[a-f0-9]{64}$/D', $reviewedFingerprint)
        || $expectedRevision < 0 || $expectedRevision > 4294967295) throw new DomainException('Reload the review and try saving again.', 400);

    $conn->begin_transaction();
    try {
        $source = earistDocumentLockPreparationSource($conn, $actor, $type, $recordId);
        $profile = $source['profile'];
        $record = $source['record'];
        $mapping = $type === 'annex_d' ? earistAnnexDMappingBuild($record, $rawInputs)
            : earistMappingBuild($profile, $record, $type, $rawInputs);
        if (!empty($mapping['errors'])) {
            $conn->rollback();
            return ['saved' => false, 'errors' => $mapping['errors'], 'mapping' => $mapping];
        }
        $details = earistDocumentReviewDetails($mapping, $ownerId);
        if (!hash_equals($details['source_fingerprint'], $reviewedFingerprint)) throw new DomainException('Research information changed after review. Reload and review the current fields before saving.', 409);
        $inputs = $mapping['inputs'];
        $authorRefs = $details['author_references'];

        $stmt = $conn->prepare('SELECT * FROM earist_document_drafts WHERE research_record_id = ? AND owner_id = ? AND document_type = ? FOR UPDATE');
        $stmt->bind_param('iis', $recordId, $ownerId, $type);
        $stmt->execute();
        $draft = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($draft) earistDocumentAssertDraftOwner($actor, $draft, $recordId, $type);
        $oldEnvelope = earistDocumentDraftEnvelope($draft);
        $orphanSplit = earistDocumentDraftSplitInputs($oldEnvelope['inputs'], $oldEnvelope['author_references'], $record['authors'] ?? []);
        $oldEnvelope['orphaned_author_inputs'] = array_replace($oldEnvelope['orphaned_author_inputs'], $orphanSplit['orphaned']);
        $oldRevision = $draft ? (int) $draft['draft_revision'] : 0;
        $requestHash = hash('sha256', $requestId);
        $versionContext = earistDocumentDraftVersionContext($type);
        $contentHash = earistDocumentDraftContentHash($details['source_fingerprint'], $inputs,
            $oldEnvelope['orphaned_author_inputs'], $discardOrphans, $versionContext);

        if (earistDocumentDraftIsReplay($oldEnvelope['last_save'], $requestHash, $contentHash)) {
            $conn->commit();
            $draft['_envelope'] = $oldEnvelope;
            return ['saved' => true, 'replayed' => true, 'draft' => $draft, 'mapping' => $details['review']['mapping']];
        }
        if ($oldRevision !== $expectedRevision) throw new DomainException('These answers changed in another request. Your entries are still shown; reload the saved revision before saving again.', 409);

        $reviewKey = earistDocumentDraftReviewSessionKey($ownerId, $recordId, $type, $requestHash);
        $reviewed = $_SESSION[$reviewKey] ?? null;
        if (!is_array($reviewed) || !hash_equals((string) ($reviewed['source_fingerprint'] ?? ''), $details['source_fingerprint'])
            || !hash_equals((string) ($reviewed['content_sha256'] ?? ''), $contentHash)
            || (int) ($reviewed['draft_revision'] ?? -1) !== $expectedRevision
            || !hash_equals((string) ($reviewed['request_id_sha256'] ?? ''), $requestHash)
            || (int) ($reviewed['expires_at'] ?? 0) < time()) {
            throw new DomainException('Review these exact answers before saving.', 409);
        }

        $orphaned = $discardOrphans ? [] : $oldEnvelope['orphaned_author_inputs'];
        $newRevision = $oldRevision + 1;
        if ($newRevision > 4294967295) throw new RuntimeException('This draft cannot accept another revision.');
        $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
        $templateHash = $details['review']['template_sha256'];
        $envelope = ['version' => 1, 'inputs' => $inputs, 'author_references' => $authorRefs,
            'orphaned_author_inputs' => $orphaned,
            'last_save' => ['request_id_sha256' => $requestHash, 'content_sha256' => $contentHash]];
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sourceVersion = $details['source_record_version'];
        $sourceFingerprint = $details['source_fingerprint'];
        $inputVersion = $versionContext['input_schema_version'];
        $mappingVersion = $versionContext['mapping_definition_version'];
        $bindingVersion = $versionContext['binding_version'];
        $savedDraftId = 0;
        if ($draft === null) {
            $insert = $conn->prepare('INSERT INTO earist_document_drafts (research_record_id, owner_id, document_type, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, template_sha256, inputs_json, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->bind_param('iisiisiiissii', $recordId, $ownerId, $type, $newRevision, $sourceVersion,
                $sourceFingerprint, $inputVersion, $mappingVersion, $bindingVersion, $templateHash, $json, $ownerId, $ownerId);
            $ok = $insert->execute();
            if ($ok) $savedDraftId = (int) $conn->insert_id;
            $insert->close();
        } else {
            $update = $conn->prepare('UPDATE earist_document_drafts SET draft_revision = ?, source_record_version = ?, source_fingerprint = ?, input_schema_version = ?, mapping_definition_version = ?, binding_version = ?, template_sha256 = ?, inputs_json = ?, updated_by = ? WHERE draft_id = ? AND draft_revision = ?');
            $draftId = (int) $draft['draft_id'];
            $savedDraftId = $draftId;
            $update->bind_param('iisiiissiii', $newRevision, $sourceVersion, $sourceFingerprint, $inputVersion,
                $mappingVersion, $bindingVersion, $templateHash, $json, $ownerId, $draftId, $oldRevision);
            $ok = $update->execute() && $update->affected_rows === 1;
            $update->close();
        }
        if (!$ok) throw new RuntimeException('Answers were not saved. Reload the review and try again.');
        $conn->commit();
        unset($_SESSION[$reviewKey]);
        try { logActivity('Saved EARIST ' . earistMappingDocument($type)['label'] . ' answers for research record #' . $recordId, 'earist_document_draft'); }
        catch (Throwable $exception) { error_log('EARIST faculty draft save audit failed: ' . get_class($exception)); }
        $saved = array_replace($draft ?? [], ['draft_id' => $savedDraftId, 'research_record_id' => $recordId,
            'owner_id' => $ownerId, 'document_type' => $type, 'draft_revision' => $newRevision,
            'source_record_version' => $sourceVersion, 'source_fingerprint' => $sourceFingerprint,
            'input_schema_version' => $inputVersion, 'mapping_definition_version' => $mappingVersion,
            'binding_version' => $bindingVersion, 'template_sha256' => $templateHash,
            'inputs_json' => $json, 'created_by' => $draft['created_by'] ?? $ownerId, 'updated_by' => $ownerId,
            '_envelope' => $envelope]);
        return ['saved' => true, 'replayed' => false, 'draft' => $saved, 'mapping' => $details['review']['mapping']];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function earistDocumentAssertGenerationEnabled(): void
{
    if (earistDocumentStorageConfig()['live_generation_enabled'] !== true) {
        throw new DomainException('Live document generation is not enabled.', 503);
    }
}
