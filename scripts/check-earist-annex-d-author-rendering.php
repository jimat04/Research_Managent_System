<?php
/** Synthetic, file-only regression check for verified Annex D author details. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-docx-renderer.php';

$record = array_replace(array_fill_keys(array_keys(facultyRecordTextFields()), ''), [
    'research_record_id' => 900002, 'owner_id' => 900001, 'version' => 7,
    'status' => 'active', 'deleted_at' => null, 'title' => 'Synthetic Annex D author metadata check',
    'authors' => [
        ['record_author_id' => 900101, 'full_name' => 'Synthetic Faculty Author', 'position' => 'Instructor I',
            'affiliation' => 'Synthetic Institute', 'display_order' => 1, 'author_role' => 'lead'],
        ['record_author_id' => 900102, 'full_name' => 'Synthetic Coauthor', 'position' => 'Assistant Professor',
            'affiliation' => 'Second Synthetic Institute', 'display_order' => 2, 'author_role' => 'coauthor'],
    ],
]);
$inputs = [
    'final_score' => '89.5', 'classification' => 'recommended',
    'certification_statement' => 'Synthetic verification only.', 'certification_date' => '2026-10-31',
    'certification_place' => 'Synthetic Place', 'record_reference' => 'SYNTHETIC-ONLY',
    'scoring_policy_confirmed' => true,
];
$mapping = earistAnnexDMappingBuild($record, $inputs);
$review = earistRenderReview($mapping);
$output = earistRenderDocx($mapping, [
    'acknowledged_payload_sha256' => $review['payload_sha256'],
    'annex_d_verification' => [
        'verified_by' => 900009, 'verified_at' => '2026-10-31 12:00:00',
        'verified_payload_sha256' => $review['payload_sha256'],
    ],
]);

$path = tempnam(sys_get_temp_dir(), 'rms-annex-d-check-');
$zip = new ZipArchive();
$opened = false;
try {
    if (file_put_contents($path, $output['bytes']) !== strlen($output['bytes'])) throw new RuntimeException('Could not write temporary DOCX bytes.');
    if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Generated DOCX package is invalid.');
    $opened = true;
    $xml = $zip->getFromName('word/document.xml');
    if (!is_string($xml)) throw new RuntimeException('Generated DOCX has no main document part.');
    $dom = earistXmlParse($xml);
    $authorCell = earistXmlOne($dom->documentElement, './w:body/w:tbl[1]/w:tr[2]/w:tc[2]');
    foreach ($record['authors'] as $index => $author) {
        $expected = ($index + 1) . '. ' . $author['full_name'] . ', ' . $author['position'] . ', ' . $author['affiliation'];
        $actual = earistXmlText(earistXmlOne($authorCell, './w:p[' . ($index + 1) . ']'));
        if ($actual !== $expected) throw new RuntimeException('Annex D author metadata differs at author ' . ($index + 1) . '.');
    }
    echo "Annex D author rendering passed: numbered names, ranks and institutes are present in saved order.\n";
} finally {
    if ($opened) $zip->close();
    if (is_file($path)) unlink($path);
}
