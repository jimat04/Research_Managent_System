<?php
/** Pure synthetic draft-envelope checks. No application config.php, DB, session, HTTP or runtime files. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-document-service.php';
$passed = 0;
$failed = [];
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $label;
};
$reject = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); $check(false, $label); }
    catch (DomainException | RuntimeException $exception) { $check(true, $label); }
};

$empty = earistDocumentDraftEnvelope(null);
$check($empty === ['version' => 1, 'inputs' => [], 'author_references' => [], 'orphaned_author_inputs' => [], 'last_save' => null],
    'No row yields an empty versioned draft envelope');
$valid = ['version' => 1, 'inputs' => ['ai_used' => 'no', 'contributions' => [
    '910101' => ['categories' => ['conceptualization'], 'description' => 'Synthetic first author', 'percentage' => '60'],
    '910102' => ['categories' => ['methodology'], 'description' => 'Synthetic removed author', 'percentage' => '40'],
]], 'author_references' => [
    '910101' => ['full_name' => 'Synthetic First', 'display_order' => 1, 'identity_sha256' => str_repeat('a', 64)],
    '910102' => ['full_name' => 'Synthetic Removed', 'display_order' => 2, 'identity_sha256' => str_repeat('b', 64)],
], 'orphaned_author_inputs' => [], 'last_save' => ['request_id_sha256' => str_repeat('c', 64), 'content_sha256' => str_repeat('d', 64)]];
$row = ['inputs_json' => json_encode($valid, JSON_THROW_ON_ERROR)];
$check(earistDocumentDraftEnvelope($row) === $valid, 'Versioned answer envelope round-trips without profile copies');
$reject(static fn() => earistDocumentDraftEnvelope(['inputs_json' => '{broken']), 'Malformed JSON draft rejected');
$reject(static fn() => earistDocumentDraftEnvelope(['inputs_json' => json_encode(['version' => 2, 'inputs' => []])]), 'Unsupported envelope version rejected');

$split = earistDocumentDraftSplitInputs($valid['inputs'], $valid['author_references'], [
    ['record_author_id' => 910101], ['record_author_id' => 910103],
]);
$check(isset($split['inputs']['contributions']['910101']) && !isset($split['inputs']['contributions']['910102']),
    'Existing author answers remain keyed to the original saved ID');
$check(isset($split['orphaned']['910102']['answers']['percentage']) && $split['orphaned']['910102']['author']['full_name'] === 'Synthetic Removed',
    'Removed-author answers remain separately recoverable with their prior identity');
$hash = earistDocumentDraftContentHash(str_repeat('e', 64), $split['inputs'], $split['orphaned'], false);
$check($hash !== earistDocumentDraftContentHash(str_repeat('f', 64), $split['inputs'], $split['orphaned'], false),
    'Source change alters save-content identity');
$check($hash !== earistDocumentDraftContentHash(str_repeat('e', 64), $split['inputs'], $split['orphaned'], true),
    'Explicit orphan-discard choice alters save-content identity');
$check($hash !== earistDocumentDraftContentHash(str_repeat('e', 64), $split['inputs'], $split['orphaned'], false, ['binding_version' => 2]),
    'Mapping/template version changes invalidate reviewed save identity');
$changedAssociation = $split['inputs'];
$changedAssociation['contributions'] = ['910103' => $changedAssociation['contributions']['910101']];
$check($hash !== earistDocumentDraftContentHash(str_repeat('e', 64), $changedAssociation, $split['orphaned'], false),
    'Reassigning contribution answers to another author ID changes save-content identity');
$check(earistDocumentDraftReviewSessionKey(910001, 910002, 'annex_a') !== earistDocumentDraftReviewSessionKey(910001, 910003, 'annex_a')
    && earistDocumentDraftReviewSessionKey(910001, 910002, 'annex_a') !== earistDocumentDraftReviewSessionKey(910001, 910002, 'annex_b'),
    'Review state is isolated per owner, source record and document type');

if ($failed) {
    fwrite(STDERR, 'EARIST document draft checks failed: ' . count($failed) . "\n");
    foreach ($failed as $failure) fwrite(STDERR, "- {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "EARIST document draft checks passed: {$passed}, failed: 0\n");
