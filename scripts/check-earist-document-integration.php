<?php
/** Authorized synthetic/file-only Step 1 checks. No config.php, DB, HTTP, sessions,
 * real records or auth stubs. Pure actor arrays below test policy, not login.
 * One synthetic primary/companion set is retained privately with an evidence index.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-document-service.php';
require_once __DIR__ . '/../includes/earist-document-generation.php';
require_once __DIR__ . '/../includes/earist-annex-d-service.php';
if (count($argv) !== 1) { fwrite(STDERR, "Usage: php scripts/check-earist-document-integration.php\n"); exit(2); }
$passed = 0;
$failed = [];
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $label;
};
$reject = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); $check(false, $label); }
    catch (DomainException | RuntimeException $exception) { $check(true, $label); }
};
$partsOf = static function (string $bytes): array {
    $scratch = earistDocumentCreateScratch();
    $zip = new ZipArchive();
    $opened = false;
    try {
        earistDocumentWriteAll($scratch['handle'], $bytes);
        if ($zip->open($scratch['path'], ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Synthetic package did not open.');
        $opened = true;
        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) $parts[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        return $parts;
    } finally {
        if ($opened) $zip->close();
        earistDocumentReleaseScratch($scratch);
    }
};
$retained = [];
try {
    $directories = earistDocumentStorageDirectories();
    $scratchRoot = $directories['scratch'];
    $check(earistDocumentStorageConfig()['live_generation_enabled'] === false, 'Live generation remains disabled for Step 1');
    $reject(static fn() => earistDocumentAssertGenerationEnabled(), 'No accidental live enablement');
    foreach (['../scratch', sys_get_temp_dir(), $directories['generated'], $scratchRoot . '/..'] as $path) {
        $reject(static fn() => earistDocumentCreateScratch($path), 'Reject unconfigured scratch path');
    }
    $first = earistDocumentCreateScratch();
    $second = earistDocumentCreateScratch();
    $firstPath = $first['path'];
    $secondPath = $second['path'];
    $check($firstPath !== $secondPath && dirname($firstPath) !== dirname($secondPath), 'Concurrent calls have distinct scratch allocations');
    earistDocumentWriteAll($first['handle'], 'SYNTHETIC SCRATCH ONE');
    earistDocumentWriteAll($second['handle'], 'SYNTHETIC SCRATCH TWO');
    earistDocumentReleaseScratch($first);
    $check(!file_exists($firstPath) && !is_dir(dirname($firstPath)), 'Own scratch file/directory released');
    $check(file_get_contents($secondPath) === 'SYNTHETIC SCRATCH TWO', 'Other scratch allocation untouched');
    earistDocumentReleaseScratch($second);

    $faculty = ['user_id' => 910001, 'role' => 'faculty', 'status' => 'active', 'deleted_at' => null];
    $admin = ['user_id' => 910009, 'role' => 'admin', 'status' => 'active', 'deleted_at' => null];
    $profile = ['user_id' => 910001, 'first_name' => 'Synthetic', 'middle_name' => '', 'last_name' => 'Applicant',
        'academic_rank' => 'Instructor I', 'designation' => '', 'appointment_status' => 'Permanent',
        'department' => 'Synthetic Department', 'email' => 'synthetic@example.test', 'contact' => '', 'affiliation' => 'Synthetic Institution'];
    $record = array_replace(array_fill_keys(array_keys(facultyRecordTextFields()), ''), [
        'research_record_id' => 910002, 'owner_id' => 910001, 'version' => 3, 'status' => 'active', 'deleted_at' => null,
        'title' => 'SYNTHETIC STEP 1 ONLY <&> 測試', 'work_type' => 'conference', 'work_type_label' => 'Conference', 'sdgs' => [4, 8, 9, 17],
        'authors' => [
            ['record_author_id' => 910101, 'faculty_user_id' => null, 'author_type' => 'external', 'full_name' => 'Synthetic Lead',
                'position' => 'Synthetic Position', 'affiliation' => 'Synthetic Institution', 'author_role' => 'lead', 'display_order' => 1],
            ['record_author_id' => 910102, 'faculty_user_id' => null, 'author_type' => 'external', 'full_name' => 'Synthetic Coauthor',
                'position' => 'Synthetic Position', 'affiliation' => 'Synthetic Institution', 'author_role' => 'coauthor', 'display_order' => 2],
        ],
    ]);
    $types = ['annex_a', 'annex_b', 'annex_c', 'annex_d', 'annex_e', 'annex_f', 'qsf_rds_019'];
    $outputs = $contexts = [];
    foreach ($types as $type) {
        $actor = $type === 'annex_d' ? $admin : $faculty;
        earistDocumentAssertActor($actor, $type);
        $check(true, $type . ': intended role accepted');
        foreach (['student', 'research_staff', $type === 'annex_d' ? 'faculty' : 'admin'] as $role) {
            $denied = array_replace($actor, ['role' => $role]);
            $reject(static fn() => earistDocumentAssertActor($denied, $type), $type . ': deny ' . $role);
        }
        foreach ([['status' => 'suspended'], ['deleted_at' => '2026-10-30 12:00:00'], ['user_id' => 0]] as $change) {
            $denied = array_replace($actor, $change);
            $reject(static fn() => earistDocumentAssertActor($denied, $type), $type . ': reject inactive/invalid actor');
        }
        $context = earistDocumentBuildReview($actor, $profile, $record, $type);
        $contexts[$type] = $context;
        $check($context['document_owner_id'] === $actor['user_id'] && $context['source_owner_id'] === $faculty['user_id'], $type . ': document/source owners stay distinct');
        $options = ['acknowledged_payload_sha256' => $context['review']['payload_sha256'], 'scratch_directory' => $scratchRoot];
        $outputs[$type] = earistRenderDocx($context['review']['mapping'], $options);
        $inspection = earistDocumentValidateDocx($outputs[$type]['bytes']);
        $check($inspection['xml_parts'] >= 3, $type . ': protected-scratch output passes package validation');
        $archived = array_replace($record, ['status' => 'archived']);
        earistDocumentAssertSource($actor, $type, $archived);
        $check(true, $type . ': archived source eligible');
        $deleted = array_replace($record, ['deleted_at' => '2026-10-30 12:00:00']);
        $reject(static fn() => earistDocumentAssertSource($actor, $type, $deleted), $type . ': deleted source cannot prepare');
        earistDocumentAssertSource($actor, $type, $deleted, 'history');
        $check(true, $type . ': deleted source history remains eligible');
    }
    $defaultOutput = earistRenderDocx($contexts['qsf_rds_019']['review']['mapping'], ['acknowledged_payload_sha256' => $contexts['qsf_rds_019']['review']['payload_sha256']]);
    $check($partsOf($defaultOutput['bytes']) === $partsOf($outputs['qsf_rds_019']['bytes']), 'Scratch option preserves QSF primary package parts');
    $check($partsOf($defaultOutput['supplements'][0]['bytes']) === $partsOf($outputs['qsf_rds_019']['supplements'][0]['bytes']), 'Scratch option preserves QSF companion package parts');
    $changedProfile = array_replace($profile, ['email' => 'changed@example.test']);
    $changed = earistDocumentBuildReview($faculty, $changedProfile, $record, 'annex_a');
    $check($changed['source_fingerprint'] !== $contexts['annex_a']['source_fingerprint'], 'Profile changes invalidate source fingerprint without a record-version change');
    $changed = earistDocumentBuildReview($faculty, $profile, array_replace($record, ['version' => 4]), 'annex_a');
    $check($changed['source_fingerprint'] !== $contexts['annex_a']['source_fingerprint'], 'Record revision changes invalidate source fingerprint');
    $changedRecord = $record;
    $changedRecord['authors'][1]['record_author_id'] = 910103;
    $changed = earistDocumentBuildReview($faculty, $profile, $changedRecord, 'annex_a');
    $check($changed['source_fingerprint'] !== $contexts['annex_a']['source_fingerprint'], 'Replacement author with same name invalidates source fingerprint');
    $check(isset($changed['author_references'][910103]) && !isset($changed['author_references'][910102]), 'Author evidence uses saved IDs, not names');
    $changed = earistDocumentBuildReview($faculty, $profile, $record, 'annex_a', ['ai_used' => 'no']);
    $check($changed['source_fingerprint'] === $contexts['annex_a']['source_fingerprint']
        && $changed['review']['payload_sha256'] !== $contexts['annex_a']['review']['payload_sha256'], 'Answers change reviewed payload, not source identity');
    $reject(static fn() => earistDocumentBuildReview($faculty, $profile, $record, 'annex_a', ['ai_used' => 'no', 'ai_tools' => [['tool' => 'X', 'purpose' => 'X', 'prompts' => 'X', 'extent' => 'X']]]), 'Contradictory Phase 3 inputs cannot become reviewed output');
    $otherFaculty = array_replace($faculty, ['user_id' => 910003]);
    $reject(static fn() => earistDocumentAssertSource($otherFaculty, 'annex_a', $record), 'Coauthorship/other faculty does not grant source ownership');
    $draft = ['draft_id' => 910301, 'owner_id' => 910001, 'research_record_id' => 910002, 'document_type' => 'annex_a', 'created_by' => 910001, 'updated_by' => 910001];
    $document = ['draft_id' => 910301, 'owner_id' => 910001, 'research_record_id' => 910002, 'document_type' => 'annex_a', 'generated_by' => 910001];
    earistDocumentAssertHistory($faculty, $document, $draft, array_replace($record, ['deleted_at' => '2026-10-30 12:00:00']));
    $check(true, 'Owned historical document accessible after source soft deletion');
    foreach ([['owner_id' => 910003], ['research_record_id' => 910099], ['document_type' => 'annex_b'], ['draft_id' => 910302], ['generated_by' => 910003]] as $change) {
        $bad = array_replace($document, $change);
        $reject(static fn() => earistDocumentAssertHistory($faculty, $bad, $draft, $record), 'Mismatched history ownership/source/type/draft/generator rejected');
    }
    $adminDraft = array_replace($draft, ['owner_id' => 910009, 'document_type' => 'annex_d', 'created_by' => 910009, 'updated_by' => 910009]);
    $adminDocument = array_replace($document, ['owner_id' => 910009, 'document_type' => 'annex_d', 'generated_by' => 910009]);
    earistDocumentAssertHistory($admin, $adminDocument, $adminDraft, $record);
    $check(true, 'Own-Admin history permits distinct faculty source owner');
    $otherAdmin = array_replace($admin, ['user_id' => 910008]);
    $reject(static fn() => earistDocumentAssertHistory($otherAdmin, $adminDocument, $adminDraft, $record), 'Another Admin cannot access own-Admin history');
    $reject(static fn() => earistDocumentAssertHistory($faculty, $adminDocument, $adminDraft, $record), 'Faculty cannot access Annex D history');

    // Validate all approved template packages read-only using the new stricter validator.
    foreach (glob(__DIR__ . '/../templates/earist/blank/*.docx') as $path) {
        $before = hash_file('sha256', $path);
        earistDocumentValidateDocx(file_get_contents($path));
        $check(hash_equals($before, hash_file('sha256', $path)), 'Original template validates without mutation');
    }
    $minimal = [
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => '<w:document xmlns:w="' . EARIST_WORD_NS . '"><w:body><w:p><w:r><w:t>SYNTHETIC INTEGRATION CHECK</w:t></w:r></w:p></w:body></w:document>',
    ];
    $make = static fn(array $parts): string => earistRenderPackage($parts, $scratchRoot);
    $validBytes = $make($minimal);
    earistDocumentValidateDocx($validBytes);
    $check(true, 'Valid minimal synthetic DOCX accepted');
    foreach (['', 'not a ZIP', substr($validBytes, 0, -22), str_repeat('X', earistDocumentStorageConfig()['max_docx_bytes'] + 1)] as $invalid) {
        $reject(static fn() => earistDocumentValidateDocx($invalid), 'Empty/truncated/oversized package rejected');
    }
    $badPackages = [];
    $bad = $minimal; unset($bad['_rels/.rels']); $badPackages[] = $bad;
    $bad = $minimal; $bad['word/document.xml'] = '<broken>'; $badPackages[] = $bad;
    $bad = $minimal; $bad['word/document.xml'] = '<!DOCTYPE x [<!ENTITY x SYSTEM "https://example.invalid/no-fetch">]><x>&x;</x>'; $badPackages[] = $bad;
    $bad = $minimal; $bad['word/document.xml'] = '<w:document xmlns:w="' . EARIST_WORD_NS . '"/>'; $badPackages[] = $bad;
    $bad = $minimal; $bad['_rels/.rels'] = str_replace('word/document.xml', '../outside.xml', $bad['_rels/.rels']); $badPackages[] = $bad;
    $bad = $minimal; $bad['_rels/.rels'] = str_replace('word/document.xml', 'word/missing.xml', $bad['_rels/.rels']); $badPackages[] = $bad;
    $bad = $minimal; $bad['[Content_Types].xml'] = str_replace('/word/document.xml', '/word/missing.xml', $bad['[Content_Types].xml']); $badPackages[] = $bad;
    $bad = $minimal; $bad['word/Document.xml'] = $minimal['word/document.xml']; $badPackages[] = $bad;
    $bad = $minimal; $bad['word/document.xml'] = '<w:document xmlns:w="' . EARIST_WORD_NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:hyperlink r:id="missing"/></w:body></w:document>'; $badPackages[] = $bad;
    foreach (['../outside.xml', 'word/../outside.xml', '/absolute.xml', 'C:/drive.xml', 'word\\backslash.xml', 'word/%2e.xml', 'word/trailing.'] as $name) {
        $bad = $minimal; $bad[$name] = '<x/>'; $badPackages[] = $bad;
    }
    foreach ($badPackages as $parts) {
        $invalidBytes = $make($parts);
        $reject(static fn() => earistDocumentValidateDocx($invalidBytes), 'Unsafe/corrupt/invalid OPC package rejected');
    }
    // Force an actual CRC mismatch in an uncompressed synthetic entry.
    $allocation = earistDocumentCreateScratch();
    fclose($allocation['handle']); $allocation['handle'] = null;
    $zip = new ZipArchive();
    try {
        if ($zip->open($allocation['path'], ZipArchive::OVERWRITE) !== true) throw new RuntimeException('CRC fixture allocation failed.');
        foreach ($minimal as $name => $bytes) { $zip->addFromString($name, $bytes); $zip->setCompressionName($name, ZipArchive::CM_STORE); }
        $zip->close();
        $crcBytes = file_get_contents($allocation['path']);
    } finally { earistDocumentReleaseScratch($allocation); }
    $check(substr_count($crcBytes, 'SYNTHETIC INTEGRATION CHECK') === 1, 'CRC fixture has one uncompressed marker');
    $crcBytes = str_replace('SYNTHETIC INTEGRATION CHECK', 'SYNTHETIC INTEGRATION CHXCK', $crcBytes);
    $reject(static fn() => earistDocumentValidateDocx($crcBytes), 'CRC mismatch rejected');

    $stored = earistDocumentStoreOutput($outputs['qsf_rds_019']);
    $retained[] = $stored;
    $primaryStored = earistDocumentManifestFile($stored, 'primary');
    $historySnapshot = ['version' => 1, 'document_type' => 'qsf_rds_019',
        'source' => ['record' => ['title' => 'Synthetic immutable history title']],
        'file_manifest' => $stored,
        'generation_request' => ['request_token_hash' => str_repeat('a', 64),
            'payload_sha256' => $contexts['qsf_rds_019']['review']['payload_sha256'], 'draft_revision' => 1]];
    $historyRow = ['snapshot_json' => json_encode($historySnapshot, JSON_THROW_ON_ERROR),
        'missing_fields_json' => '[]', 'warnings_json' => '[]', 'document_type' => 'qsf_rds_019',
        'docx_path' => $primaryStored['path'], 'docx_sha256' => $primaryStored['sha256'], 'docx_size' => $primaryStored['size']];
    $check(earistDocumentHistorySnapshot($historyRow)['source']['record']['title'] === 'Synthetic immutable history title',
        'Immutable history snapshot validates its source and QSF file manifest');
    $mismatchedHistory = array_replace($historyRow, ['docx_path' => 'generated/' . str_repeat('b', 64) . '/primary.docx']);
    $reject(static fn() => earistDocumentHistorySnapshot($mismatchedHistory), 'History row cannot refer to a different file set');
    $duplicate = array_replace($historyRow, ['owner_id' => 910001, 'generated_by' => 910001,
        'research_record_id' => 910002, 'draft_revision' => 1]);
    $replay = earistDocumentGenerationDuplicate($duplicate, 910001, 910002, 'qsf_rds_019', 1,
        $contexts['qsf_rds_019']['review']['payload_sha256']);
    $check($replay['generated'] && $replay['replayed'], 'Same authorized request returns its immutable history version');
    $reject(static fn() => earistDocumentGenerationDuplicate($duplicate, 910003, 910002, 'qsf_rds_019', 1,
        $contexts['qsf_rds_019']['review']['payload_sha256']), 'Another faculty owner cannot replay a generated request');
    $reject(static fn() => earistDocumentGenerationDuplicate($duplicate, 910001, 910002, 'qsf_rds_019', 1,
        str_repeat('c', 64)), 'Reused generation request with a changed payload is rejected');
    $check(earistDocumentGenerationReviewKey(910001, 910002, 'qsf_rds_019', str_repeat('a', 64))
        !== earistDocumentGenerationReviewKey(910003, 910002, 'qsf_rds_019', str_repeat('a', 64)),
        'Generation review session is bound to its actor');
    foreach (['primary', 'sdg_continuation'] as $selector) {
        $reference = earistDocumentManifestFile($stored, $selector);
        $handle = earistDocumentOpenFile($reference);
        $bytes = stream_get_contents($handle); fclose($handle);
        $expectedBytes = $selector === 'primary' ? $outputs['qsf_rds_019']['bytes'] : $outputs['qsf_rds_019']['supplements'][0]['bytes'];
        $check($bytes === $expectedBytes, 'Stored primary/companion reopens with exact bytes');
        $before = hash_file('sha256', $directories['root'] . '/' . $reference['path']);
        $handle = earistDocumentOpenFile($reference); fclose($handle);
        $check(hash_equals($before, hash_file('sha256', $directories['root'] . '/' . $reference['path'])), 'Repeated historical read changes no bytes');
        $wrongHash = array_replace($reference, ['sha256' => str_repeat('0', 64)]);
        $reject(static fn() => earistDocumentOpenFile($wrongHash), 'Stored file checksum mismatch rejected without regeneration');
        $wrongSize = array_replace($reference, ['size' => $reference['size'] + 1]);
        $reject(static fn() => earistDocumentOpenFile($wrongSize), 'Stored file size mismatch rejected');
        foreach (['../outside.docx', 'generated/' . $stored['set_id'] . '/../primary.docx', 'C:/outside.docx', 'https://example.invalid/a.docx', $reference['path'] . ':stream'] as $path) {
            $unsafe = array_replace($reference, ['path' => $path]);
            $reject(static fn() => earistDocumentOpenFile($unsafe), 'Traversal/absolute/URL/alternate-stream path rejected');
        }
    }
    $badManifest = $stored; $badManifest['files']['sdg_continuation']['path'] = 'generated/' . str_repeat('a', 64) . '/sdg-continuation.docx';
    $reject(static fn() => earistDocumentManifestFile($badManifest, 'primary'), 'Cross-version companion manifest rejected even on primary download');
    $badManifest = $stored; $badManifest['document_type'] = 'annex_d';
    $reject(static fn() => earistDocumentManifestFile($badManifest, 'sdg_continuation'), 'Annex D cannot borrow QSF companions');
    $reject(static fn() => earistDocumentManifestFile($stored, '../primary'), 'Unlisted download selector rejected');
    $reference = earistDocumentManifestFile($stored, 'primary');
    $missing = array_replace($reference, ['path' => 'generated/' . bin2hex(random_bytes(32)) . '/primary.docx']);
    $reject(static fn() => earistDocumentOpenFile($missing), 'Missing historical file rejected without regeneration');
    $before = glob($directories['generated'] . '/*');
    $badOutput = $outputs['qsf_rds_019']; $badOutput['sha256'] = str_repeat('0', 64);
    $reject(static fn() => earistDocumentStoreOutput($badOutput), 'Inconsistent renderer metadata rejected before publication');
    $badOutput = $outputs['qsf_rds_019']; $badOutput['bytes'] = 'corrupt'; $badOutput['sha256'] = hash('sha256', 'corrupt'); $badOutput['size'] = 7;
    $reject(static fn() => earistDocumentStoreOutput($badOutput), 'Corrupt renderer package rejected before publication');
    $check(glob($directories['generated'] . '/*') === $before, 'Failed pre-publication validation allocates no successful file set');
} catch (Throwable $exception) {
    $failed[] = 'Unexpected synthetic check exception: ' . get_class($exception) . ': ' . $exception->getMessage();
}

$evidence = __DIR__ . '/../backups/earist-stage4c-step1-checks-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d') . '-' . bin2hex(random_bytes(4)) . '.json';
$backupGuard = __DIR__ . '/../backups/.htaccess';
if (is_file($backupGuard) && trim(file_get_contents($backupGuard)) === 'Require all denied') {
    $handle = fopen($evidence, 'xb');
    if ($handle !== false) {
        try { earistDocumentWriteAll($handle, json_encode(['synthetic_only' => true, 'history_rows_created' => 0,
            'passed' => $passed, 'failed' => $failed, 'retained_file_sets' => $retained], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        finally { fclose($handle); }
        echo 'Protected synthetic evidence: backups/' . basename($evidence) . PHP_EOL;
    } else $failed[] = 'Unable to retain the synthetic evidence index.';
} else $failed[] = 'Protected evidence storage is unavailable.';
echo $passed . ' passed; ' . count($failed) . " failed. Synthetic policy/file checks only; no DB, sessions, HTTP or live authentication.\n";
foreach ($failed as $failure) echo 'FAIL: ' . $failure . PHP_EOL;
exit($failed ? 1 : 0);
