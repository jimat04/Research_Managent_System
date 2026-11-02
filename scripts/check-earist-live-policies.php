<?php
/** Pure synthetic Stage 4C checks. No DB/config bootstrap, sessions, HTTP or private storage access. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-annex-d-service.php';
require_once __DIR__ . '/../includes/earist-document-ui.php';
$passed = 0;
$failed = [];
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) $passed++; else $failed[] = $label;
};
$reject = static function (callable $fn, string $label) use ($check): void {
    try { $fn(); $check(false, $label); }
    catch (DomainException | RuntimeException $exception) { $check(true, $label); }
};
try {
    $faculty = ['user_id' => 910001, 'role' => 'faculty', 'status' => 'active', 'deleted_at' => null];
    $admin = ['user_id' => 910009, 'role' => 'admin', 'status' => 'active', 'deleted_at' => null];
    $record = array_replace(array_fill_keys(array_keys(facultyRecordTextFields()), ''), [
        'research_record_id' => 910002, 'owner_id' => 910001, 'version' => 3, 'status' => 'active', 'deleted_at' => null,
        'title' => 'SYNTHETIC ADMIN PREPARATION <&> 測試', 'work_type' => 'conference', 'work_type_label' => 'Conference', 'sdgs' => [8,9],
        'authors' => [
            ['record_author_id' => 910101, 'faculty_user_id' => null, 'author_type' => 'external', 'full_name' => 'Synthetic Lead', 'position' => 'Synthetic Position', 'affiliation' => 'Synthetic Institution', 'author_role' => 'lead', 'display_order' => 1],
            ['record_author_id' => 910102, 'faculty_user_id' => null, 'author_type' => 'external', 'full_name' => 'Synthetic Coauthor', 'position' => 'Synthetic Position', 'affiliation' => 'Synthetic Institution', 'author_role' => 'coauthor', 'display_order' => 2],
        ]]);
    $inputs = ['final_score' => '89.999999999999999999999999999999', 'classification' => 'recommended',
        'certification_statement' => 'Synthetic certification result', 'certification_date' => '2026-10-30',
        'certification_place' => 'Synthetic place', 'record_reference' => 'SYNTHETIC-ONLY', 'scoring_policy_confirmed' => true];
    $mapping = earistAnnexDMappingBuild($record, $inputs);
    $review = earistRenderReview($mapping);
    $check(!$mapping['errors'] && !$review['missing'] && $mapping['inputs']['final_score'] === $inputs['final_score'], 'Complete Admin mapping preserves exact decimal score');
    foreach (['faculty','research_staff','student'] as $role) {
        $reject(static fn() => earistDocumentAssertActor(array_replace($admin, ['role' => $role]), 'annex_d'), 'Annex D rejects role ' . $role);
    }
    foreach (['suspended','inactive'] as $status) $reject(static fn() => earistDocumentAssertActor(array_replace($admin, ['status' => $status]), 'annex_d'), 'Inactive Admin rejected');
    earistDocumentAssertSource($admin, 'annex_d', $record);
    $check(true, 'Admin preparation permits distinct Faculty source owner');
    earistDocumentAssertSource($admin, 'annex_d', array_replace($record, ['status' => 'archived']));
    $check(true, 'Archived source permits Admin preparation');
    $deleted = array_replace($record, ['deleted_at' => '2026-10-30 10:00:00']);
    $reject(static fn() => earistDocumentAssertSource($admin, 'annex_d', $deleted), 'Deleted source rejects new preparation');
    $draftPolicy = ['draft_id' => 910010, 'owner_id' => 910009, 'research_record_id' => 910002, 'document_type' => 'annex_d', 'created_by' => 910009, 'updated_by' => 910009];
    $documentPolicy = array_replace($draftPolicy, ['generated_by' => 910009]);
    earistDocumentAssertHistory($admin, $documentPolicy, $draftPolicy, $deleted);
    $check(true, 'Own Admin history survives deleted source');
    $reject(static fn() => earistDocumentAssertHistory(array_replace($admin, ['user_id' => 910008]), $documentPolicy, $draftPolicy, $record), 'Another Admin receives no implicit history access');
    $reject(static fn() => earistDocumentAssertHistory($faculty, $documentPolicy, $draftPolicy, $record), 'Source Faculty owner cannot access Admin history');
    $reject(static fn() => earistDocumentAssertHistory($admin, $documentPolicy, array_replace($draftPolicy, ['research_record_id' => 910003]), $record), 'Draft/history source mismatch rejected');
    $versions = earistDocumentDraftVersionContext('annex_d');
    $envelope = ['version' => 1, 'inputs' => $mapping['inputs'], 'author_references' => earistDocumentAuthorReferences($mapping), 'orphaned_author_inputs' => [], 'last_save' => null];
    $draft = array_replace($draftPolicy, $versions, ['draft_revision' => 2, 'source_record_version' => 3,
        'source_fingerprint' => earistDocumentSourceFingerprint($mapping), 'inputs_json' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
    $context = earistDocumentGenerationContextForDraft($admin, $draft, $mapping, 'annex_d');
    $check($context['review']['payload_sha256'] === $review['payload_sha256'], 'Saved Admin draft resolves to exact reviewed payload');
    $reject(static fn() => earistDocumentAssertDraftOwner($admin, array_replace($draft, ['created_by' => 910008]), 910002, 'annex_d'), 'Inconsistent draft creator rejected');
    $profile = ['user_id' => 910001, 'first_name' => 'Synthetic', 'middle_name' => '', 'last_name' => 'Faculty',
        'academic_rank' => 'Instructor I', 'designation' => '', 'appointment_status' => 'Permanent', 'department' => 'Synthetic Department',
        'email' => 'synthetic@example.test', 'contact' => '', 'affiliation' => 'Synthetic Institution'];
    $facultyMapping = earistMappingBuild($profile, $record, 'annex_a', ['ai_used' => 'no']);
    $facultyEnvelope = array_replace($envelope, ['inputs' => $facultyMapping['inputs']]);
    $facultyDraft = array_replace($draft, earistDocumentDraftVersionContext('annex_a'), ['owner_id' => 910001, 'created_by' => 910001,
        'updated_by' => 910001, 'document_type' => 'annex_a', 'source_fingerprint' => earistDocumentSourceFingerprint($facultyMapping),
        'inputs_json' => json_encode($facultyEnvelope)]);
    $facultyContext = earistDocumentGenerationContextForDraft($faculty, $facultyDraft, $facultyMapping, 'annex_a');
    $check($facultyContext['mapping']['source']['record']['title'] === $record['title'], 'Shared context preserves Faculty title and saved answers');
    $changedProfile = earistMappingBuild(array_replace($profile, ['email' => 'changed@example.test']), $record, 'annex_a', ['ai_used' => 'no']);
    $reject(static fn() => earistDocumentGenerationContextForDraft($faculty, $facultyDraft, $changedProfile, 'annex_a'), 'Faculty profile changes invalidate generation review');
    $reject(static fn() => earistDocumentAssertSource(array_replace($faculty, ['user_id' => 910003]), 'annex_a', $record), 'Coauthor identity alone never grants source ownership');
    foreach (['source_record_version','input_schema_version','mapping_definition_version','binding_version'] as $key) {
        $changed = $draft; $changed[$key]++;
        $reject(static fn() => earistDocumentGenerationContextForDraft($admin, $changed, $mapping, 'annex_d'), 'Stale draft version rejected: ' . $key);
    }
    $reject(static fn() => earistDocumentGenerationContextForDraft($admin, array_replace($draft, ['owner_id' => 910008]), $mapping, 'annex_d'), 'Another Admin draft cannot generate');
    $changedRecord = $record; $changedRecord['authors'][1]['record_author_id'] = 910103;
    $changedMapping = earistAnnexDMappingBuild($changedRecord, $inputs);
    $reject(static fn() => earistDocumentGenerationContextForDraft($admin, $draft, $changedMapping, 'annex_d'), 'Same author name with replacement ID is stale');
    $check(earistDocumentSourceFingerprint($changedMapping) !== $draft['source_fingerprint'], 'Author identity is included in source fingerprint');
    $state = ['requested_mode' => 'verified', 'policy_confirmed' => true, 'verified_against_records' => true,
        'verified_by' => 910009, 'verified_at' => '2026-10-30 12:00:00', 'payload_sha256' => $review['payload_sha256']];
    $evidence = earistAnnexDGenerationEvidence($review, $admin, $state);
    $check($evidence['verified_by'] === 910009 && $evidence['verified_payload_sha256'] === $review['payload_sha256'], 'Fresh verification binds current Admin and exact payload');
    foreach ([['policy_confirmed' => false], ['verified_against_records' => false], ['verified_by' => 910008], ['payload_sha256' => str_repeat('f',64)], ['verified_at' => 'invalid']] as $change) {
        $reject(static fn() => earistAnnexDGenerationEvidence($review, $admin, array_replace($state, $change)), 'Missing or stale verification is rejected');
    }
    $incompleteReview = earistRenderReview(earistAnnexDMappingBuild($record, []));
    $reject(static fn() => earistAnnexDGenerationEvidence($incompleteReview, $admin, array_replace($state, ['payload_sha256' => $incompleteReview['payload_sha256']])), 'Incomplete saved answers cannot produce verified copy');
    $check(earistAnnexDGenerationEvidence($incompleteReview, $admin, ['requested_mode' => 'unissued']) === null, 'Incomplete/unverified mode supplies no certification evidence');
    $check(isset(earistAnnexDMappingBuild($record, array_replace($inputs, ['classification' => 'highly_recommended']))['errors']['classification']), 'Contradictory exact score/classification rejected');
    foreach (['-1','100.0001','NaN','8e1','80,1'] as $score) $check(isset(earistAnnexDMappingBuild($record, array_replace($inputs, ['final_score' => $score]))['errors']['final_score']), 'Invalid score rejected: ' . $score);
    foreach (['0'=>'not_recommended','59.999999'=>'not_recommended','60'=>'conditionally_recommended','69.999999'=>'conditionally_recommended','70'=>'moderately_recommended','79.999999'=>'moderately_recommended','80'=>'recommended','89.999999'=>'recommended','90'=>'highly_recommended','100.0000'=>'highly_recommended'] as $score => $classification) {
        $check(earistAnnexDScoreClassification((string) $score) === $classification, 'Printed score boundary: ' . $score);
    }
    $save = ['request_id_sha256' => str_repeat('a',64), 'content_sha256' => str_repeat('b',64)];
    $check(earistDocumentDraftIsReplay($save, str_repeat('a',64), str_repeat('b',64)), 'Exact draft save replay is a no-op');
    $reject(static fn() => earistDocumentDraftIsReplay($save, str_repeat('a',64), str_repeat('c',64)), 'Reused save identity cannot change answers');
    $check(!earistDocumentDraftIsReplay($save, str_repeat('d',64), str_repeat('b',64)), 'Intentional new save remains a new request');
    $check(earistDocumentDraftReviewSessionKey(910009,910002,'annex_d',str_repeat('a',64)) !== earistDocumentDraftReviewSessionKey(910009,910002,'annex_d',str_repeat('b',64)), 'Concurrent review tabs keep separate request identities');
    $dupSnapshot = ['generation_request' => ['payload_sha256' => $review['payload_sha256'], 'requested_mode' => 'verified']];
    $duplicate = array_replace($documentPolicy, ['draft_revision' => 2, 'snapshot_json' => json_encode($dupSnapshot)]);
    $check(earistDocumentGenerationDuplicate($duplicate, 910009, 910002, 'annex_d', 2, $review['payload_sha256'], 'verified')['replayed'], 'Authorized repeated generation returns existing version');
    $reject(static fn() => earistDocumentGenerationDuplicate($duplicate, 910009, 910002, 'annex_d', 2, $review['payload_sha256'], 'unissued'), 'Repeated token cannot change preparation mode');
    $reject(static fn() => earistDocumentGenerationDuplicate($duplicate, 910008, 910002, 'annex_d', 2, $review['payload_sha256'], 'verified'), 'Another Admin cannot replay a generation token');
    $set = str_repeat('a',64);
    $manifest = ['version' => 1, 'set_id' => $set, 'document_type' => 'qsf_rds_019', 'files' => [
        'primary' => ['path' => 'generated/' . $set . '/primary.docx', 'sha256' => str_repeat('b',64), 'size' => 123, 'filename' => 'qsf-rds-019.docx'],
        'sdg_continuation' => ['path' => 'generated/' . $set . '/sdg-continuation.docx', 'sha256' => str_repeat('c',64), 'size' => 124, 'filename' => 'qsf-rds-019-sdg-continuation.docx']]];
    $snapshot = ['version' => 1, 'document_type' => 'qsf_rds_019', 'source' => ['record' => ['title' => 'Synthetic frozen title']], 'file_manifest' => $manifest];
    $historyRow = ['snapshot_json' => json_encode($snapshot), 'missing_fields_json' => '[]', 'warnings_json' => '[]', 'document_type' => 'qsf_rds_019',
        'docx_path' => $manifest['files']['primary']['path'], 'docx_sha256' => str_repeat('b',64), 'docx_size' => 123];
    $check(earistDocumentHistorySnapshot($historyRow)['file_manifest']['files']['sdg_continuation']['size'] === 124, 'Immutable QSF companion remains associated to one version');
    $adminManifest = $manifest;
    $adminManifest['document_type'] = 'annex_d';
    $adminManifest['files'] = ['primary' => array_replace($manifest['files']['primary'], ['filename' => 'annex-d.docx'])];
    $adminSnapshot = ['version' => 1, 'document_type' => 'annex_d', 'source' => $mapping['source'], 'generated_by' => ['user_id' => 910009],
        'payload_sha256' => $review['payload_sha256'], 'verified_evidence' => $evidence, 'file_manifest' => $adminManifest];
    $adminHistory = array_replace($historyRow, ['snapshot_json' => json_encode($adminSnapshot), 'research_record_id' => 910002,
        'document_type' => 'annex_d', 'source_record_version' => 3, 'generated_by' => 910009, 'preparation_mode' => 'prepared_for_printing',
        'verified_by' => 910009, 'verified_at' => $evidence['verified_at'], 'verified_payload_sha256' => $review['payload_sha256']]);
    $check(earistDocumentHistorySnapshot($adminHistory)['source']['record']['owner_id'] === 910001, 'Admin history retains Faculty source ownership independently');
    $reject(static fn() => earistDocumentHistorySnapshot(array_replace($adminHistory, ['verified_by' => 910008])), 'Admin history verifier mismatch rejected');
    $reject(static fn() => earistDocumentHistorySnapshot(array_replace($adminHistory, ['source_record_version' => 4])), 'Historical source version cannot be relabeled');
    $bad = $snapshot; $bad['file_manifest']['files']['sdg_continuation']['path'] = 'generated/' . str_repeat('d',64) . '/sdg-continuation.docx';
    $reject(static fn() => earistDocumentHistorySnapshot(array_replace($historyRow, ['snapshot_json' => json_encode($bad)])), 'Companion from another version rejected');
    $reject(static fn() => earistDocumentHistorySnapshot(array_replace($historyRow, ['docx_path' => '../primary.docx'])), 'History cannot select an unrelated filesystem path');
    $bad = $snapshot; $bad['document_type'] = 'annex_d';
    $reject(static fn() => earistDocumentHistorySnapshot(array_replace($historyRow, ['snapshot_json' => json_encode($bad), 'document_type' => 'annex_d'])), 'Manifest cannot be relabeled as Annex D');
    $check(earistDocumentGenerationReviewKey(910009,910002,'annex_d',$set) !== earistDocumentGenerationReviewKey(910008,910002,'annex_d',$set), 'Generation review identity binds preparing Admin');
    $reject(static fn() => earistDocumentAssertGenerationEnabled(), 'Live generation remains disabled');
    $check(earistDocumentEscape('<script>') === '&lt;script&gt;', 'Document UI escapes status content');
} catch (Throwable $exception) { $failed[] = 'Unexpected check exception: ' . get_class($exception) . ': ' . $exception->getMessage(); }
foreach ($failed as $failure) fwrite(STDERR, 'FAIL: ' . $failure . "\n");
echo 'EARIST live policy checks: ' . $passed . ' passed; ' . count($failed) . " failed. No DB, sessions, HTTP or runtime-file writes.\n";
exit($failed ? 1 : 0);
