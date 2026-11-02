<?php
/** Faculty generation and immutable history. Live actions stay behind the config gate. */
require_once __DIR__ . '/earist-document-service.php';
require_once __DIR__ . '/faculty-research-records.php';

function earistDocumentGenerationReviewKey(int $actorId, int $recordId, string $type, string $requestHash): string
{
    return 'earist_generation_' . hash('sha256', $actorId . ':' . $recordId . ':' . $type . ':' . $requestHash);
}

function earistDocumentGenerationIssueToken(int $actorId, int $recordId, string $type): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION[earistDocumentGenerationReviewKey($actorId, $recordId, $type, hash('sha256', $token))] =
        ['issued' => true, 'expires_at' => time() + 1800];
    return $token;
}

function earistDocumentGenerationAssertIssued(int $actorId, int $recordId, string $type, string $token): void
{
    $state = $_SESSION[earistDocumentGenerationReviewKey($actorId, $recordId, $type, hash('sha256', $token))] ?? null;
    if (!is_array($state) || empty($state['issued']) || (int) ($state['expires_at'] ?? 0) < time()) {
        throw new DomainException('This generation review expired. Open the preparation page again.', 409);
    }
}

/** Load the current saved answer set and its exact renderer review. */
function earistDocumentFacultyGenerationContext(mysqli $conn, int $recordId, string $type): array
{
    $actor = earistDocumentCurrentActor($type);
    if ($type === 'annex_d') throw new DomainException('Use the separate Admin preparation service.', 403);
    $recordId = earistDocumentId($recordId);
    $draft = earistDocumentFacultyDraftLoad($conn, $recordId, $type);
    if (!$draft) throw new DomainException('Save document-specific answers before generation.', 409);
    $envelope = $draft['_envelope'];
    if ($envelope['orphaned_author_inputs']) throw new DomainException('Reconcile answers for removed authors before generation.', 409);
    $context = earistDocumentFacultyReview($conn, $recordId, $type, $envelope['inputs']);
    return earistDocumentGenerationContextForDraft($actor, $draft, $context['review']['mapping'], $type);
}

/** Pure consistency checks shared by separately authorized Faculty and Admin readers. */
function earistDocumentGenerationContextForDraft(array $actor, array $draft, array $mapping, string $type): array
{
    earistDocumentAssertActor($actor, $type);
    $recordId = (int) $mapping['source']['record']['research_record_id'];
    earistDocumentAssertDraftOwner($actor, $draft, $recordId, $type);
    $envelope = earistDocumentDraftEnvelope($draft);
    if ($envelope['orphaned_author_inputs']) throw new DomainException('Reconcile answers for removed authors before generation.', 409);
    $fingerprint = earistDocumentSourceFingerprint($mapping);
    $versions = earistDocumentDraftVersionContext($type);
    if ((int) $draft['owner_id'] !== (int) $actor['user_id']
        || (int) $draft['research_record_id'] !== $recordId || $draft['document_type'] !== $type
        || (int) $draft['source_record_version'] !== (int) $mapping['source']['record']['version']
        || !hash_equals((string) $draft['source_fingerprint'], $fingerprint)
        || (int) $draft['input_schema_version'] !== (int) $versions['input_schema_version']
        || (int) $draft['mapping_definition_version'] !== (int) $versions['mapping_definition_version']
        || (int) $draft['binding_version'] !== (int) $versions['binding_version']
        || !hash_equals((string) $draft['template_sha256'], (string) $versions['template_sha256'])) {
        throw new DomainException('Research information or the saved draft changed. Review and save the current answers before generation.', 409);
    }
    if (!earistMappingTemplateInfo($type)['matches']) throw new DomainException('The approved template changed. Ask the administrator to review the template setup.', 409);
    if (!hash_equals(earistRenderHash($mapping['inputs']), earistRenderHash($envelope['inputs']))) {
        throw new DomainException('Saved answers need review before generation.', 409);
    }
    return ['actor' => $actor, 'draft' => $draft, 'mapping' => $mapping,
        'review' => earistRenderReview($mapping), 'versions' => $versions, 'source_fingerprint' => $fingerprint];
}

function earistDocumentGenerationRequestFind(mysqli $conn, int $actorId, string $requestHash): ?array
{
    $stmt = $conn->prepare('SELECT * FROM earist_generated_documents WHERE generated_by = ? AND request_token_hash = ? LIMIT 1');
    $stmt->bind_param('is', $actorId, $requestHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function earistDocumentGenerationDuplicate(array $row, int $actorId, int $recordId, string $type,
    int $draftRevision, string $payloadHash, ?string $requestedMode = null): array
{
    if ((int) $row['owner_id'] !== $actorId || (int) $row['generated_by'] !== $actorId
        || (int) $row['research_record_id'] !== $recordId || $row['document_type'] !== $type
        || (int) $row['draft_revision'] !== $draftRevision) {
        throw new DomainException('This generation request was already used for another review. Review again before generating.', 409);
    }
    try { $snapshot = json_decode((string) $row['snapshot_json'], true, 128, JSON_THROW_ON_ERROR); }
    catch (Throwable $exception) { throw new RuntimeException('The saved document history could not be read.', 0, $exception); }
    $savedHash = $snapshot['generation_request']['payload_sha256'] ?? '';
    if (!is_string($savedHash) || !hash_equals($savedHash, $payloadHash)) {
        throw new DomainException('This generation request no longer matches its reviewed answers.', 409);
    }
    if ($requestedMode !== null && ($snapshot['generation_request']['requested_mode'] ?? 'faculty') !== $requestedMode) {
        throw new DomainException('This request was already used for a different preparation mode.', 409);
    }
    return ['generated' => true, 'replayed' => true, 'document' => $row, 'snapshot' => $snapshot];
}

/** Render a reviewed faculty draft and commit exactly one immutable history version. */
function earistDocumentFacultyGenerate(mysqli $conn, int $recordId, string $type, int $expectedRevision,
    string $requestToken, string $payloadHash): array
{
    if ($type === 'annex_d') throw new DomainException('Use the separate Admin preparation service.', 403);
    return earistDocumentGenerateReviewed($conn, $recordId, $type, $expectedRevision, $requestToken, $payloadHash);
}

/** Internal publication service: current role is always checked for this exact type. */
function earistDocumentGenerateReviewed(mysqli $conn, int $recordId, string $type, int $expectedRevision,
    string $requestToken, string $payloadHash): array
{
    earistDocumentAssertGenerationEnabled();
    $actor = earistDocumentCurrentActor($type);
    $actorId = earistDocumentId($actor['user_id']);
    $recordId = earistDocumentId($recordId);
    if ($expectedRevision < 1 || !preg_match('/^[a-f0-9]{64}$/D', $requestToken)
        || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) throw new DomainException('Review this document again before generation.', 400);
    $requestHash = hash('sha256', $requestToken);
    $sessionKey = earistDocumentGenerationReviewKey($actorId, $recordId, $type, $requestHash);
    $reviewState = $_SESSION[$sessionKey] ?? null;
    if (!is_array($reviewState) || empty($reviewState['issued']) || empty($reviewState['acknowledged'])
        || (int) ($reviewState['draft_revision'] ?? 0) !== $expectedRevision
        || !hash_equals((string) ($reviewState['payload_sha256'] ?? ''), $payloadHash)
        || (int) ($reviewState['expires_at'] ?? 0) < time()) {
        throw new DomainException('Review and acknowledge the current warnings before generation.', 409);
    }

    $requestedMode = $type === 'annex_d' ? ($reviewState['requested_mode'] ?? '') : 'faculty';
    if ($type === 'annex_d' && !in_array($requestedMode, ['verified', 'unissued'], true)) {
        throw new DomainException('Review the Annex D preparation mode again.', 409);
    }

    // A committed retry is returned before requiring the source to remain active.
    $existing = earistDocumentGenerationRequestFind($conn, $actorId, $requestHash);
    if ($existing) return earistDocumentGenerationDuplicate($existing, $actorId, $recordId, $type, $expectedRevision, $payloadHash, $requestedMode);

    $context = $type === 'annex_d' ? earistAnnexDGenerationContext($conn, $recordId)
        : earistDocumentFacultyGenerationContext($conn, $recordId, $type);
    if ((int) $context['draft']['draft_revision'] !== $expectedRevision
        || !hash_equals($context['review']['payload_sha256'], $payloadHash)
        || !hash_equals((string) ($reviewState['source_fingerprint'] ?? ''), $context['source_fingerprint'])) {
        throw new DomainException('Research information or answers changed after review. Review them again before generation.', 409);
    }
    $review = $context['review'];
    $options = ['acknowledged_payload_sha256' => $payloadHash,
        'scratch_directory' => earistDocumentStorageDirectories()['scratch']];
    if ($type === 'annex_d') {
        $evidence = earistAnnexDGenerationEvidence($context['review'], $actor, $reviewState);
        if ($evidence !== null) $options['annex_d_verification'] = $evidence;
    }
    $output = earistRenderDocx($context['mapping'], $options);
    // Validate the whole primary/companion set before opening the SQL write transaction.
    earistDocumentValidateDocx($output['bytes']);
    foreach ($output['supplements'] as $supplement) earistDocumentValidateDocx($supplement['bytes']);

    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start document publication.');
    try {
        $source = earistDocumentLockPreparationSource($conn, $actor, $type, $recordId);
        $profile = $source['profile'];
        $record = $source['record'];

        $draftStmt = $conn->prepare('SELECT * FROM earist_document_drafts WHERE research_record_id = ? AND owner_id = ? AND document_type = ? FOR UPDATE');
        $draftStmt->bind_param('iis', $recordId, $actorId, $type);
        $draftStmt->execute();
        $draft = $draftStmt->get_result()->fetch_assoc() ?: null;
        $draftStmt->close();
        if (!$draft || (int) $draft['draft_revision'] !== $expectedRevision) throw new DomainException('Saved answers changed during generation. Review them again.', 409);
        earistDocumentAssertDraftOwner($actor, $draft, $recordId, $type);
        $envelope = earistDocumentDraftEnvelope($draft);
        if ($envelope['orphaned_author_inputs']) throw new DomainException('Reconcile answers for removed authors before generation.', 409);
        $mapping = $type === 'annex_d' ? earistAnnexDMappingBuild($record, $envelope['inputs'])
            : earistMappingBuild($profile, $record, $type, $envelope['inputs']);
        if (!empty($mapping['errors'])) throw new DomainException('Correct saved mapping errors before generation.', 409);
        $fingerprint = earistDocumentSourceFingerprint($mapping);
        $versions = earistDocumentDraftVersionContext($type);
        $freshReview = earistRenderReview($mapping);
        if (!hash_equals((string) $draft['source_fingerprint'], $fingerprint)
            || !hash_equals((string) $reviewState['source_fingerprint'], $fingerprint)
            || !hash_equals($freshReview['payload_sha256'], $payloadHash)
            || (int) $draft['source_record_version'] !== (int) $record['version']
            || (int) $draft['input_schema_version'] !== (int) $versions['input_schema_version']
            || (int) $draft['mapping_definition_version'] !== (int) $versions['mapping_definition_version']
            || (int) $draft['binding_version'] !== (int) $versions['binding_version']
            || !hash_equals((string) $draft['template_sha256'], (string) $versions['template_sha256'])) {
            throw new DomainException('The reviewed source or template changed. Review again before generation.', 409);
        }
        $existing = earistDocumentGenerationRequestFind($conn, $actorId, $requestHash);
        if ($existing) {
            $conn->rollback();
            return earistDocumentGenerationDuplicate($existing, $actorId, $recordId, $type, $expectedRevision, $payloadHash, $requestedMode);
        }

        // The parent source row lock serializes version allocation for this record.
        // A locking read sees the latest commit even when an Admin source read
        // established an earlier repeatable-read snapshot before waiting on locks.
        $versionStmt = $conn->prepare('SELECT document_version FROM earist_generated_documents WHERE research_record_id = ? AND document_type = ? ORDER BY document_version DESC LIMIT 1 FOR UPDATE');
        $versionStmt->bind_param('is', $recordId, $type);
        $versionStmt->execute();
        $lastVersion = $versionStmt->get_result()->fetch_assoc();
        $documentVersion = (int) ($lastVersion['document_version'] ?? 0) + 1;
        $versionStmt->close();
        if ($documentVersion < 1 || $documentVersion > 4294967295) throw new RuntimeException('No document version number is available.');

        $manifest = earistDocumentStoreOutput($output);
        $primary = earistDocumentManifestFile($manifest, 'primary');
        $snapshot = ['version' => 1, 'document_type' => $type, 'generated_by' => ['user_id' => $actorId, 'name' => $type === 'annex_d' ? trim($actor['first_name'] . ' ' . $actor['last_name']) : $profile['full_name']],
            'source' => $mapping['source'],
            'inputs' => $envelope['inputs'], 'fields' => $mapping['fields'],
            'renderer_version' => $output['renderer_version'], 'template_sha256' => $output['template_sha256'],
            'payload_sha256' => $payloadHash, 'file_manifest' => $manifest,
            'verified_evidence' => $output['verified_evidence'],
            'generation_request' => ['request_token_hash' => $requestHash, 'payload_sha256' => $payloadHash,
                'draft_revision' => $expectedRevision, 'requested_mode' => $requestedMode]];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $missingJson = json_encode($review['missing'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $warningsJson = json_encode($review['warnings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $draftId = (int) $draft['draft_id'];
        $sourceVersion = (int) $record['version'];
        $inputVersion = (int) $versions['input_schema_version'];
        $mappingVersion = (int) $versions['mapping_definition_version'];
        $bindingVersion = (int) $versions['binding_version'];
        $rendererVersion = (string) $output['renderer_version'];
        $templateHash = (string) $output['template_sha256'];
        $preparationMode = (string) $output['preparation_mode'];
        $docxPath = (string) $primary['path'];
        $docxHash = (string) $primary['sha256'];
        $docxSize = (int) $primary['size'];
        $verifiedBy = $output['verified_evidence']['verified_by'] ?? null;
        $verifiedAt = $output['verified_evidence']['verified_at'] ?? null;
        $verifiedHash = $output['verified_evidence']['verified_payload_sha256'] ?? null;
        $insert = $conn->prepare('INSERT INTO earist_generated_documents (draft_id, research_record_id, owner_id, document_type, document_version, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, renderer_version, template_sha256, snapshot_json, missing_fields_json, warnings_json, warnings_acknowledged_at, preparation_mode, generated_by, request_token_hash, docx_path, docx_sha256, docx_size, verified_by, verified_at, verified_payload_sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->bind_param('iiisiiisiiissssssisssiiss', $draftId, $recordId, $actorId, $type, $documentVersion,
            $expectedRevision, $sourceVersion, $fingerprint, $inputVersion, $mappingVersion, $bindingVersion,
            $rendererVersion, $templateHash, $snapshotJson, $missingJson, $warningsJson, $preparationMode,
            $actorId, $requestHash, $docxPath, $docxHash, $docxSize, $verifiedBy, $verifiedAt, $verifiedHash);
        if (!$insert->execute()) throw new RuntimeException('The document history entry could not be saved.');
        $generatedId = (int) $conn->insert_id;
        $insert->close();
        if ($generatedId < 1 || !$conn->commit()) throw new RuntimeException('Document publication could not be confirmed.');
        try { logActivity('Generated EARIST ' . earistMappingDocument($type)['label'] . ' version ' . $documentVersion . ' for research record #' . $recordId, 'earist_document_generated'); }
        catch (Throwable $exception) { error_log('EARIST generation audit failed: ' . get_class($exception)); }
        return ['generated' => true, 'replayed' => false, 'generated_document_id' => $generatedId,
            'document_version' => $documentVersion, 'manifest' => $manifest, 'snapshot' => $snapshot];
    } catch (Throwable $exception) {
        try { $conn->rollback(); } catch (Throwable $rollbackException) { error_log('EARIST publication rollback was not confirmed.'); }
        if (isset($manifest['set_id'])) error_log('EARIST publication not confirmed; private file set retained: ' . $manifest['set_id']);
        throw $exception;
    }
}

/** Return only the current faculty owner's history, including deleted sources. */
function earistDocumentFacultyHistoryList(mysqli $conn, int $page = 1, int $pageSize = 50, ?int $recordId = null): array
{
    return earistDocumentHistoryListForScope($conn, false, $page, $pageSize, $recordId);
}

function earistDocumentHistoryListForScope(mysqli $conn, bool $adminScope, int $page = 1, int $pageSize = 50, ?int $recordId = null): array
{
    $actor = earistDocumentCurrentActor($adminScope ? 'annex_d' : 'annex_a');
    $actorId = earistDocumentId($actor['user_id']);
    $typeFilter = $adminScope ? "= 'annex_d'" : "<> 'annex_d'";
    if ($page < 1 || $page > 100000 || !in_array($pageSize, [5, 50], true)) throw new DomainException('Invalid history page.', 400);
    if ($recordId !== null && ($recordId < 1 || $recordId > 4294967295)) throw new DomainException('Invalid research record.', 400);
    $recordFilter = $recordId === null ? '' : ' AND research_record_id = ?';
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM earist_generated_documents WHERE owner_id = ? AND generated_by = ? AND document_type {$typeFilter}{$recordFilter}");
    if ($recordId === null) $count->bind_param('ii', $actorId, $actorId);
    else $count->bind_param('iii', $actorId, $actorId, $recordId);
    $count->execute();
    $total = (int) $count->get_result()->fetch_assoc()['total'];
    $count->close();
    $offset = ($page - 1) * $pageSize;
    $stmt = $conn->prepare("SELECT gd.*, d.draft_id AS joined_draft_id, d.research_record_id AS joined_draft_record_id, d.owner_id AS joined_draft_owner_id, d.document_type AS joined_draft_type, d.created_by AS joined_draft_creator, d.updated_by AS joined_draft_updater, r.research_record_id AS joined_source_id, r.owner_id AS joined_source_owner, r.version AS joined_source_version, r.status AS joined_source_status, r.deleted_at AS joined_source_deleted FROM earist_generated_documents gd JOIN earist_document_drafts d ON d.draft_id = gd.draft_id JOIN faculty_research_records r ON r.research_record_id = gd.research_record_id WHERE gd.owner_id = ? AND gd.generated_by = ? AND gd.document_type {$typeFilter}" . ($recordId === null ? '' : ' AND gd.research_record_id = ?') . ' ORDER BY gd.generated_at DESC, gd.generated_document_id DESC LIMIT ? OFFSET ?');
    if ($recordId === null) $stmt->bind_param('iiii', $actorId, $actorId, $pageSize, $offset);
    else $stmt->bind_param('iiiii', $actorId, $actorId, $recordId, $pageSize, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $draft = ['draft_id' => $row['joined_draft_id'], 'research_record_id' => $row['joined_draft_record_id'],
            'owner_id' => $row['joined_draft_owner_id'], 'document_type' => $row['joined_draft_type'],
            'created_by' => $row['joined_draft_creator'], 'updated_by' => $row['joined_draft_updater']];
        $source = ['research_record_id' => $row['joined_source_id'], 'owner_id' => $row['joined_source_owner'],
            'version' => $row['joined_source_version'], 'status' => $row['joined_source_status'],
            'deleted_at' => $row['joined_source_deleted']];
        earistDocumentAssertHistory($actor, $row, $draft, $source);
        $row['_snapshot'] = earistDocumentHistorySnapshot($row);
    }
    unset($row);
    return ['rows' => $rows, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
}

function earistDocumentHistorySnapshot(array $row): array
{
    try { $snapshot = json_decode((string) $row['snapshot_json'], true, 256, JSON_THROW_ON_ERROR); }
    catch (Throwable $exception) { throw new RuntimeException('A saved document history entry could not be read.', 0, $exception); }
    if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
        || !is_array($snapshot['source']['record'] ?? null) || !is_array($snapshot['file_manifest'] ?? null)) {
        throw new RuntimeException('A saved document history entry has an unsupported format.');
    }
    try {
        $missing = json_decode((string) $row['missing_fields_json'], true, 128, JSON_THROW_ON_ERROR);
        $warnings = json_decode((string) $row['warnings_json'], true, 128, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) { throw new RuntimeException('A saved document history status could not be read.', 0, $exception); }
    if (!is_array($missing) || !is_array($warnings)
        || (!is_string($snapshot['source']['record']['title'] ?? null))) {
        throw new RuntimeException('A saved document history status is invalid.');
    }
    $primary = earistDocumentManifestFile($snapshot['file_manifest'], 'primary');
    if ($primary['path'] !== $row['docx_path'] || $primary['sha256'] !== $row['docx_sha256']
        || $primary['size'] !== (int) $row['docx_size'] || $snapshot['document_type'] !== $row['document_type']
        || $snapshot['file_manifest']['document_type'] !== $row['document_type']) {
        throw new RuntimeException('A saved document history reference is inconsistent.');
    }
    if (isset($row['research_record_id']) && ((int) ($snapshot['source']['record']['research_record_id'] ?? 0) !== (int) $row['research_record_id']
        || (int) ($snapshot['source']['record']['version'] ?? 0) !== (int) $row['source_record_version']
        || (int) ($snapshot['generated_by']['user_id'] ?? 0) !== (int) $row['generated_by'])) {
        throw new RuntimeException('A saved document source snapshot is inconsistent.');
    }
    $evidence = $snapshot['verified_evidence'] ?? null;
    if ($row['document_type'] === 'annex_d' && ($row['preparation_mode'] ?? '') === 'prepared_for_printing') {
        if (!is_array($evidence) || ($evidence['verified_by'] ?? null) !== (int) $row['verified_by']
            || ($evidence['verified_at'] ?? null) !== $row['verified_at']
            || ($evidence['verified_payload_sha256'] ?? null) !== $row['verified_payload_sha256']
            || $row['verified_payload_sha256'] !== ($snapshot['payload_sha256'] ?? null)) {
            throw new RuntimeException('A saved Annex D verification snapshot is inconsistent.');
        }
    } elseif ($evidence !== null) {
        throw new RuntimeException('Unverified history contains inconsistent verification evidence.');
    }
    return $snapshot;
}

/** Resolve an authorized faculty history row and pin its stored bytes for download. */
function earistDocumentFacultyHistoryOpen(mysqli $conn, int $generatedId, string $selector): array
{
    return earistDocumentHistoryOpenForScope($conn, $generatedId, $selector, false);
}

function earistDocumentHistoryOpenForScope(mysqli $conn, int $generatedId, string $selector, bool $adminScope): array
{
    $actor = earistDocumentCurrentActor($adminScope ? 'annex_d' : 'annex_a');
    $actorId = earistDocumentId($actor['user_id']);
    $typeFilter = $adminScope ? "= 'annex_d'" : "<> 'annex_d'";
    $generatedId = earistDocumentId($generatedId);
    $stmt = $conn->prepare("SELECT gd.*, d.draft_id AS joined_draft_id, d.research_record_id AS joined_draft_record_id, d.owner_id AS joined_draft_owner_id, d.document_type AS joined_draft_type, d.created_by AS joined_draft_creator, d.updated_by AS joined_draft_updater, r.research_record_id AS joined_source_id, r.owner_id AS joined_source_owner, r.version AS joined_source_version, r.status AS joined_source_status, r.deleted_at AS joined_source_deleted FROM earist_generated_documents gd JOIN earist_document_drafts d ON d.draft_id = gd.draft_id JOIN faculty_research_records r ON r.research_record_id = gd.research_record_id WHERE gd.generated_document_id = ? AND gd.owner_id = ? AND gd.generated_by = ? AND gd.document_type {$typeFilter} LIMIT 1");
    $stmt->bind_param('iii', $generatedId, $actorId, $actorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) throw new DomainException('Generated document is unavailable.', 404);
    $draft = ['draft_id' => $row['joined_draft_id'], 'research_record_id' => $row['joined_draft_record_id'],
        'owner_id' => $row['joined_draft_owner_id'], 'document_type' => $row['joined_draft_type'],
        'created_by' => $row['joined_draft_creator'], 'updated_by' => $row['joined_draft_updater']];
    $source = ['research_record_id' => $row['joined_source_id'], 'owner_id' => $row['joined_source_owner'],
        'version' => $row['joined_source_version'], 'status' => $row['joined_source_status'],
        'deleted_at' => $row['joined_source_deleted']];
    earistDocumentAssertHistory($actor, $row, $draft, $source);
    $snapshot = earistDocumentHistorySnapshot($row);
    $file = earistDocumentManifestFile($snapshot['file_manifest'], $selector);
    $handle = earistDocumentOpenFile($file);
    return ['handle' => $handle, 'filename' => $file['filename'], 'size' => $file['size'],
        'document' => $row, 'snapshot' => $snapshot];
}
