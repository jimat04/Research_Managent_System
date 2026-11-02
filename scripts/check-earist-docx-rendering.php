<?php
/** Authorized synthetic file-only automation. Never loads config.php or a database.
 * --samples retains disposable synthetic outputs under HTTP-denied backups.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-docx-renderer.php';
if (array_diff(array_slice($argv, 1), ['--samples'])) { fwrite(STDERR, "Usage: php scripts/check-earist-docx-rendering.php [--samples]\n"); exit(2); }
$passed = 0;
$failed = [];
/** Compare expanded names, attributes, text and child order without expensive C14N. */
function earistCheckXmlTree(DOMNode $node): array
{
    $attributes = [];
    if ($node instanceof DOMElement) foreach ($node->attributes as $attribute) {
        $attributes['{' . $attribute->namespaceURI . '}' . $attribute->localName] = $attribute->value;
    }
    ksort($attributes, SORT_STRING);
    $children = [];
    foreach ($node->childNodes as $child) $children[] = earistCheckXmlTree($child);
    return [$node->nodeType, $node->namespaceURI, $node->localName, $attributes,
        $node instanceof DOMCharacterData ? $node->data : null, $children];
}
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $message;
};
$reject = static function (callable $operation, string $message) use ($check): void {
    try { $operation(); $check(false, $message); }
    catch (DomainException $exception) { $check(true, $message); }
};
$manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = [];
foreach (glob(__DIR__ . '/../templates/earist/blank/*.docx') as $path) $baseline[$path] = hash_file('sha256', $path);
$sampleDirectory = null;
$sampleManifest = [];
if (in_array('--samples', $argv, true)) {
    $sampleDirectory = __DIR__ . '/../backups/earist-stage4b-samples-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d') . '-' . bin2hex(random_bytes(4));
    if (!is_dir(dirname($sampleDirectory)) || !is_file(dirname($sampleDirectory) . '/.htaccess')
        || !str_contains(file_get_contents(dirname($sampleDirectory) . '/.htaccess'), 'Require all denied')) throw new RuntimeException('Protected sample storage is required.');
    if (!mkdir($sampleDirectory, 0700)) throw new RuntimeException('Unable to create exclusive sample directory.');
    file_put_contents($sampleDirectory . '/.htaccess', "Require all denied\n", LOCK_EX);
}
$save = static function (array $output, string $name) use ($sampleDirectory, &$sampleManifest): void {
    if ($sampleDirectory === null) return;
    $handle = fopen($sampleDirectory . '/' . $name, 'xb');
    if (!$handle) throw new RuntimeException('Cannot create exclusive sample output.');
    try { if (fwrite($handle, $output['bytes']) !== strlen($output['bytes'])) throw new RuntimeException('Incomplete sample write.'); }
    finally { fclose($handle); }
    $sampleManifest[$name] = ['sha256' => hash('sha256', $output['bytes']), 'size' => strlen($output['bytes']), 'synthetic' => true];
};
$open = static function (string $bytes) use ($check): array {
    $path = tempnam(sys_get_temp_dir(), 'rms-docx-check-');
    $zip = new ZipArchive(); $opened = false;
    try {
        file_put_contents($path, $bytes);
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Sample ZIP failed.');
        $opened = true; $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index); $content = $zip->getFromIndex($index);
            $check(is_string($content), 'Every ZIP entry is readable: ' . $name);
            $parts[$name] = $content;
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                earistXmlParse($content); $check(true, 'Package XML parses: ' . $name);
            }
        }
        return $parts;
    } finally { if ($opened) $zip->close(); if (is_file($path)) unlink($path); }
};
$profile = ['user_id' => 900001, 'first_name' => 'Synthetic', 'middle_name' => '', 'last_name' => 'Applicant',
    'academic_rank' => 'Instructor I', 'designation' => 'Synthetic Program Head', 'appointment_status' => 'Permanent',
    'department' => 'Synthetic Computing Department', 'contact' => '000-000-0000', 'email' => 'synthetic@example.test', 'affiliation' => 'Synthetic Institution'];
$record = array_replace(array_fill_keys(array_keys(facultyRecordTextFields()), ''), [
    'research_record_id' => 900002, 'owner_id' => 900001, 'version' => 7, 'status' => 'active', 'deleted_at' => null,
    'title' => 'SYNTHETIC SAMPLE — Research <&> “Unicode” 測試', 'work_type' => 'conference', 'work_type_label' => 'Conference',
    'date_started' => '2025-01', 'date_completed' => '2026-10', 'sdgs' => [4, 8, 9, 17], 'sdg_other' => 'Synthetic other alignment <&>',
    'conference_title' => 'Synthetic Conference <&>', 'conference_host' => 'Synthetic Host', 'conference_organizer' => 'Synthetic Organizer',
    'conference_classification' => 'international', 'conference_start_date' => '2026-11-01', 'conference_end_date' => '2026-11-03',
    'conference_venue' => 'Synthetic Venue <&>', 'presentation_modality' => 'in_person',
    'journal_title' => 'Synthetic Journal', 'journal_publisher' => 'Synthetic Publisher']);
$authors = [];
foreach (range(1, 5) as $index) $authors[] = ['record_author_id' => 900100 + $index, 'faculty_user_id' => null,
    'author_type' => 'external', 'full_name' => 'Synthetic Author ' . $index . ($index === 1 ? ' <&> José 測試' : ''),
    'position' => 'Synthetic Position ' . $index, 'affiliation' => 'External Synthetic Institution ' . $index,
    'author_role' => $index === 1 ? 'lead' : 'coauthor', 'display_order' => $index];
$record['authors'] = $authors;
$aInputs = ['submission_target' => 'both', 'conference_deadline' => '2026-10-31', 'proceedings' => 'yes', 'conference_indexing' => ['scopus'],
    'registration_fee' => '0.00', 'registration_currency' => 'PHP', 'journal_deadline' => '2026-12-01', 'peer_review_clear' => 'no',
    'journal_indexing' => ['wos'], 'scope_aligned' => 'yes', 'scope_explanation' => "Synthetic scope explanation <&>\nSecond paragraph.",
    'publication_fee' => '1234.50', 'publication_currency' => 'USD', 'ai_used' => 'yes',
    'checklist_profiles' => 'yes', 'checklist_declaration' => 'no', 'checklist_manuscript' => 'yes', 'checklist_target_details' => 'no'];
foreach (range(1, 4) as $index) $aInputs['ai_tools'][] = ['tool' => 'Synthetic Tool ' . $index, 'purpose' => 'Synthetic purpose ' . $index,
    'prompts' => 'Synthetic prompt <&> ' . $index, 'extent' => 'Synthetic extent ' . $index];
$qInputs = ['applicant_source' => 'profile', 'presenter_author_id' => '900103', 'qsf_classification' => 'philippines',
    'funding_source' => 'earist', 'estimated_expenses' => '12345.67', 'expense_currency' => 'PHP', 'recognition' => 'yes',
    'abstract_submitted' => 'yes', 'abstract_date' => '2026-09-01', 'accepted' => 'yes', 'acceptance_date' => '2026-09-15',
    'attachments' => ['full_text', 'brochure', 'itinerary']];
$dInputs = ['final_score' => '89.999999999999999999999999999999', 'classification' => 'recommended',
    'certification_statement' => 'SYNTHETIC PREPARATION ONLY — recommended for the synthetic test purpose <&>.',
    'certification_date' => '2026-10-30', 'certification_place' => 'Synthetic Place', 'record_reference' => 'SYNTHETIC-NOT-A-REAL-RECORD',
    'scoring_policy_confirmed' => true];
$build = static function (string $type, array $source, array $inputs = []) use ($profile): array {
    return $type === 'annex_d' ? earistAnnexDMappingBuild($source, $inputs) : earistMappingBuild($profile, $source, $type, $inputs);
};
$render = static function (array $mapping, bool $verified = false): array {
    $review = earistRenderReview($mapping);
    $options = ['acknowledged_payload_sha256' => $review['payload_sha256']];
    if ($verified) $options['annex_d_verification'] = ['verified_by' => 900009, 'verified_at' => '2026-10-30 12:00:00', 'verified_payload_sha256' => $review['payload_sha256']];
    return earistRenderDocx($mapping, $options);
};

foreach (['annex_a', 'annex_b', 'annex_c', 'annex_e', 'annex_f', 'qsf_rds_019', 'annex_d'] as $type) {
    foreach (['one-author', 'multiple-authors', 'long-title'] as $scenario) {
        echo 'Checking ' . $type . '/' . $scenario . PHP_EOL;
        try {
            $source = $record;
            if ($scenario === 'one-author') $source['authors'] = [$authors[0]];
            if ($scenario === 'long-title') {
                $suffix = ' COMPLETE_END 測試 ';
                $source['title'] = mb_substr('SYNTHETIC LONG TITLE — ' . str_repeat('Full title <&> José 測試 must remain complete. ', 60), 0, 2000 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
                $check(mb_strlen($source['title'], 'UTF-8') === 2000, 'Fixture uses exact 2000-character source title limit');
            }
            $inputs = [];
            if ($type === 'annex_a') $inputs = $aInputs;
            if ($type === 'qsf_rds_019') $inputs = array_replace($qInputs, ['presenter_author_id' => (string) $source['authors'][count($source['authors']) - 1]['record_author_id']]);
            if ($type === 'annex_b') foreach ($source['authors'] as $author) $inputs['contributions'][(string) $author['record_author_id']] = [
                'categories' => ['Conceptualization', 'Writing – review & editing'], 'description' => 'Synthetic contribution for ' . $author['full_name'] . "\nComplete description <&>.",
                'percentage' => count($source['authors']) === 1 ? '100' : '20'];
            if ($type === 'annex_d') $inputs = $dInputs;
            $mapping = $build($type, $source, $inputs);
            $output = $render($mapping, $type === 'annex_d');
            $parts = $open($output['bytes']);
            $originalZip = new ZipArchive();
            $originalZip->open(__DIR__ . '/../templates/earist/blank/' . $manifest['documents'][$type]['filename'], ZipArchive::RDONLY);
            try {
                $check(count($parts) === $originalZip->numFiles, $type . ': original package entry count');
                for ($index = 0; $index < $originalZip->numFiles; $index++) {
                    $name = $originalZip->getNameIndex($index);
                    if ($name !== 'word/document.xml') $check(($parts[$name] ?? null) === $originalZip->getFromIndex($index), $type . ': preserved part ' . $name);
                }
                $original = earistXmlParse($originalZip->getFromName('word/document.xml'));
            } finally { $originalZip->close(); }
            $dom = earistXmlParse($parts['word/document.xml']); $root = $dom->documentElement;
            $titleCell = $type === 'qsf_rds_019' ? './w:body/w:tbl[3]/w:tr[1]/w:tc[2]' : './w:body/w:tbl[1]/w:tr[1]/w:tc[2]';
            $check(earistXmlText(earistXmlOne($root, $titleCell)) === $source['title'], $type . ': complete title, Unicode and XML escaping');
            $text = earistXmlText($root); $offset = -1;
            foreach ($source['authors'] as $author) {
                $next = mb_strpos($text, $author['full_name'], 0, 'UTF-8');
                $check($next !== false && $next > $offset, $type . ': all authors in saved order'); $offset = $next;
            }
            if (in_array($type, ['annex_a', 'annex_c', 'annex_e', 'annex_f'], true)) {
                foreach ($manifest['documents'][$type]['bindings'] as $binding) if ($binding['kind'] === 'author_block') {
                    $block = earistXmlOne($root, $binding['target']);
                    foreach ($source['authors'] as $index => $author) $check(
                        earistXmlText(earistXmlOne($block, './w:p[' . ($index + 1) . ']')) === $author['full_name'] . ', ' . $author['position'] . ', ' . $author['affiliation'],
                        $type . ': comma-separated name, position and affiliation');
                }
            }
            $check($output['sha256'] === hash('sha256', $output['bytes']) && $output['size'] === strlen($output['bytes']), $type . ': output metadata');
            $check(!str_contains($text, 'DRAFT'), $type . ': no mandatory draft watermark');
            $protected = [];
            if ($type === 'annex_a') $protected = ['./w:body/w:tbl[6]'];
            if ($type === 'annex_c') foreach (range(2, 10) as $number) $protected[] = './w:body/w:tbl[' . $number . ']';
            if ($type === 'annex_e') $protected = ['./w:body/w:tbl[2]'];
            if ($type === 'annex_f') $protected = ['./w:body/w:tbl[2]'];
            if ($type === 'annex_b') $protected = ['./w:body/w:tbl[3]/w:tr[1]'];
            if ($type === 'qsf_rds_019') $protected = ['./w:body/w:tbl[6]', './w:body/w:tbl[7]'];
            foreach ($protected as $path) {
                $before = earistXmlQuery($original->documentElement, $path); $after = earistXmlQuery($root, $path);
                $check($before->length === $after->length, $type . ': protected node count ' . $path);
                foreach ($before as $index => $node) $check(earistCheckXmlTree($node) === earistCheckXmlTree($after->item($index)), $type . ': protected subtree ' . $path);
            }
            if (in_array($type, ['annex_b', 'annex_c', 'annex_e', 'annex_f', 'qsf_rds_019'], true)) {
                $before = earistXmlQuery($original->documentElement, './w:body/w:p'); $after = earistXmlQuery($root, './w:body/w:p');
                $beforeNodes = iterator_to_array($before);
                $afterNodes = iterator_to_array($after);
                if ($type === 'annex_b') {
                    $taxonomyIndex = null;
                    foreach ($beforeNodes as $index => $node) if (trim(earistXmlText($node)) === 'Contributor Roles Taxonomy') { $taxonomyIndex = $index; break; }
                    $originalGap = is_int($taxonomyIndex) && $taxonomyIndex >= 39 ? array_slice($beforeNodes, $taxonomyIndex - 39, 39) : [];
                    $check(count($originalGap) === 39 && count(array_filter($originalGap, static fn(DOMElement $node): bool => trim(earistXmlText($node)) !== '')) === 0,
                        'B: exact thirty-nine blank spacing paragraphs identified before taxonomy');
                    $expectedNodes = is_int($taxonomyIndex) && $taxonomyIndex >= 39
                        ? array_merge(array_slice($beforeNodes, 0, $taxonomyIndex - 39), array_slice($beforeNodes, $taxonomyIndex - 2)) : $beforeNodes;
                    $check(count($expectedNodes) === count($afterNodes), 'B: only thirty-seven taxonomy spacing paragraphs removed');
                    foreach ($expectedNodes as $index => $node) {
                        $afterNode = $afterNodes[$index] ?? new DOMDocument();
                        if (trim(earistXmlText($node)) === 'Contributor Roles Taxonomy' && $afterNode instanceof DOMElement) {
                            $pageBreak = earistXmlQuery($afterNode, './w:pPr/w:pageBreakBefore');
                            $check($pageBreak->length === 1 && $pageBreak->item(0)->getAttributeNS(EARIST_WORD_NS, 'val') === '1',
                                'B: taxonomy appendix starts on a new page');
                            $normalizedHeading = $afterNode->cloneNode(true);
                            foreach (iterator_to_array(earistXmlQuery($normalizedHeading, './w:pPr/w:pageBreakBefore')) as $break) $break->parentNode->removeChild($break);
                            $check(earistCheckXmlTree($node) === earistCheckXmlTree($normalizedHeading), 'B: only page-break instruction added to taxonomy heading');
                        } else {
                            $check(earistCheckXmlTree($node) === earistCheckXmlTree($afterNode), 'B: all other body paragraphs preserved ' . $index);
                        }
                    }
                    $afterTaxonomyIndex = null;
                    foreach ($afterNodes as $index => $node) if (trim(earistXmlText($node)) === 'Contributor Roles Taxonomy') { $afterTaxonomyIndex = $index; break; }
                    $remainingGap = is_int($afterTaxonomyIndex) && $afterTaxonomyIndex >= 2
                        ? array_slice($afterNodes, $afterTaxonomyIndex - 2, 2) : [];
                    $check(count($remainingGap) === 2
                        && count(array_filter($remainingGap, static fn(DOMElement $node): bool => trim(earistXmlText($node)) !== '')) === 0,
                        'B: two blank paragraphs retained before taxonomy');
                } elseif (in_array($type, ['annex_e', 'annex_f'], true)) {
                    $beforeContent = array_values(array_filter($beforeNodes, static fn(DOMElement $node): bool => trim(earistXmlText($node)) !== ''));
                    $afterContent = array_values(array_filter($afterNodes, static fn(DOMElement $node): bool => trim(earistXmlText($node)) !== ''));
                    $check(count($beforeContent) === count($afterContent), $type . ': all official body paragraph count preserved');
                    foreach ($beforeContent as $index => $node) $check(earistCheckXmlTree($node) === earistCheckXmlTree($afterContent[$index] ?? new DOMDocument()),
                        $type . ': all official body text and formatting preserved ' . $index);
                    $beforeBlankCount = count(array_filter($beforeNodes, static fn(DOMElement $node): bool => trim(earistXmlText($node)) === ''));
                    $afterBlankCount = count(array_filter($afterNodes, static fn(DOMElement $node): bool => trim(earistXmlText($node)) === ''));
                    if ($type === 'annex_e') {
                        $check($beforeBlankCount === 15 && $afterBlankCount === 0, 'E: remove all fifteen guarded empty body spacers');
                    } else {
                        $trailingBlank = 0;
                        foreach (array_reverse($afterNodes) as $node) {
                            if (trim(earistXmlText($node)) !== '') break;
                            $trailingBlank++;
                        }
                        $check($beforeBlankCount >= 22 && $afterBlankCount === $beforeBlankCount - 22,
                            'F: remove all twenty-two trailing blank-page filler paragraphs');
                        $check($trailingBlank === 0, 'F: remove trailing blank paragraphs after the certification note');
                    }
                } else {
                    $check(count($beforeNodes) === count($afterNodes), $type . ': unchanged body paragraphs');
                    foreach ($beforeNodes as $index => $node) $check(earistCheckXmlTree($node) === earistCheckXmlTree($afterNodes[$index] ?? new DOMDocument()), $type . ': protected body paragraph ' . $index);
                }
            }
            if ($type === 'annex_a') {
                $documentary = earistXmlText(earistXmlOne($root, './w:body/w:tbl[4]'));
                $check(preg_match('/☒ Updated.*☐ Author.*☒ The scholarly.*☐ Conference/us', $documentary) === 1, 'A: only explicitly confirmed documentary requirements');
                $aiTable = earistXmlOne($root, './w:body/w:tbl[5]/w:tr/w:tc/w:tbl[1]');
                $check(earistXmlQuery($aiTable, './w:tr')->length === 5 && str_contains(earistXmlText($aiTable), 'Synthetic Tool 4'), 'A: expanded AI rows');
                $check(str_contains($text, 'Host: Synthetic Host') && str_contains($text, 'Organizer: Synthetic Organizer'), 'A: distinct host and organizer');
                $check(str_contains($text, '2026-11-01') && str_contains($text, '2026-11-03') && str_contains($text, '0.00 PHP'), 'A: dates and zero-valued fees');
                $check(str_contains($text, '☒ International Conference') && str_contains($text, 'Synthetic Journal') && str_contains($text, 'Synthetic Publisher'), 'A: conference and journal populated');
                $check(str_contains($text, '☒1.') === false && str_contains($text, '☒4.') && str_contains($text, '☒17.'), 'A: selected SDGs only');
                $signatures = earistXmlOne($root, './w:body/w:tbl[5]/w:tr/w:tc/w:tbl[2]');
                $check(earistXmlQuery($signatures, './w:tr')->length === 7 + max(0, count($source['authors']) - 2) * 3, 'A: coauthor signature spaces expand');
                $check(!str_contains(earistXmlText($signatures), 'Synthetic'), 'A: handwriting spaces remain blank');
            }
            if ($type === 'annex_b') {
                $table = earistXmlOne($root, './w:body/w:tbl[2]');
                $check(earistXmlQuery($table, './w:tr')->length === max(2, count($source['authors'])) + 1, 'B: contribution row expansion');
                foreach ($source['authors'] as $index => $author) $check(
                    earistXmlText(earistXmlOne($table, './w:tr[' . ($index + 2) . ']/w:tc[1]')) === $author['full_name'] . $author['affiliation'],
                    'B: first column follows name-and-affiliation binding');
                $check(substr_count(earistXmlText($table), 'Complete description <&>.') === count($source['authors']), 'B: all descriptions preserved');
                $check(substr_count(earistXmlText($table), count($source['authors']) === 1 ? '100%' : '20%') === count($source['authors']), 'B: exact percentages');
                $check(str_contains($text, '2025-01') && str_contains($text, '2026-10'), 'B: research month precision');
                $check(earistXmlQuery($root, './w:body/w:tbl[3]/w:tr')->length === (int) ceil(count($source['authors']) / 2), 'B: expanded blank signature rows');
            }
            if ($type === 'qsf_rds_019') {
                $check(str_contains($text, 'Synthetic Applicant') && str_contains($text, 'Instructor I / Synthetic Program Head'), 'QSF: confirmed applicant');
                $applicantTable = earistXmlOne($root, './w:body/w:tbl[1]');
                $check(earistXmlText(earistXmlOne($applicantTable, './w:tr[1]/w:tc[2]')) === 'Synthetic Applicant', 'QSF: confirmed profile applicant name in its field');
                $check(earistXmlText(earistXmlOne($applicantTable, './w:tr[2]/w:tc[2]')) === 'Permanent', 'QSF: confirmed profile appointment in its field');
                $check(earistXmlText(earistXmlOne($applicantTable, './w:tr[3]/w:tc[2]')) === 'Instructor I / Synthetic Program Head', 'QSF: confirmed profile position in its field');
                $check(earistXmlText(earistXmlOne($root, './w:body/w:tbl[3]/w:tr[6]/w:tc[2]')) === 'November 1-3, 2026', 'QSF: conference dates use readable month range');
                $check(substr_count($text, '(Presenting author)') === 1, 'QSF: one confirmed presenter without reordering');
                $check(str_contains($text, '☒8.') && str_contains($text, '☒9.'), 'QSF: printed SDG selections');
                $check(str_contains($text, 'Date submitted: 2026-09-01') && str_contains($text, 'Date accepted: 2026-09-15'), 'QSF: milestone dates');
                $check(str_contains($text, 'PHP 12345.67') && str_contains($text, '☒ Regional/national'), 'QSF: confirmed funding/classification');
                $check(count($output['supplements']) === 1, 'QSF: separate SDG supplement');
                $supplement = $output['supplements'][0]; $supplementParts = $open($supplement['bytes']);
                $supplementText = earistXmlText(earistXmlParse($supplementParts['word/document.xml'])->documentElement);
                $check(str_contains($supplementText, 'SDG 4') && str_contains($supplementText, 'SDG 17') && str_contains($supplementText, $source['sdg_other']), 'QSF: additional SDGs and Others complete');
                $check(str_contains($supplementText, $source['title']), 'QSF: supplementary title complete');
                $save($supplement, $type . '-' . $scenario . '-sdg-continuation.docx');
            }
            if ($type === 'annex_d') {
                $authorCell = earistXmlOne($root, './w:body/w:tbl[1]/w:tr[2]/w:tc[2]');
                foreach ($source['authors'] as $index => $author) $check(
                    earistXmlText(earistXmlOne($authorCell, './w:p[' . ($index + 1) . ']')) === ($index + 1) . '. ' . $author['full_name'] . ', ' . $author['position'] . ', ' . $author['affiliation'],
                    'D: numbered author name, faculty rank and institute');
                $check(str_contains($text, $dInputs['final_score'] . '/100'), 'D: exact unrounded score');
                $check(str_contains($text, '☒ Recommended (80–89)') && substr_count(earistXmlText(earistXmlOne($root, './w:body/w:tbl[2]')), '☒') === 1, 'D: explicit matching classification');
                $check(str_contains($text, 'Issued this 30 day of October 2026 at Synthetic Place.'), 'D: certification date/place');
                $check(str_contains($text, $dInputs['certification_statement']) && str_contains($text, 'Prepared for physical signing') && !str_contains($text, 'UNISSUED'), 'D: verified statement and preparation mode');
                $check($output['preparation_mode'] === 'prepared_for_printing' && $output['verified_evidence']['verified_by'] === 900009, 'D: evidence retained');
                $before = earistXmlQuery($original->documentElement, './w:body/w:p'); $after = earistXmlQuery($root, './w:body/w:p');
                foreach ($before as $index => $node) if (!in_array($index + 1, [14, 20, 22], true)) {
                    $check(earistCheckXmlTree($node) === earistCheckXmlTree($after->item($index + 1)), 'D: unchanged official paragraph/signature ' . ($index + 1));
                }
                $check(!str_contains($text, $dInputs['record_reference']), 'D: institutional reference remains internal');
            }
            $save($output, $type . '-' . $scenario . '.docx');
        } catch (Throwable $exception) { $check(false, $type . '/' . $scenario . ': ' . $exception->getMessage()); }
    }
}

// Validation and payload binding, deliberately independent of application sessions.
$map = $build('annex_a', $record, $aInputs); $review = earistRenderReview($map);
$reject(static fn() => earistRenderDocx($map), 'Require warning acknowledgment');
$reject(static fn() => earistRenderDocx($map, ['acknowledged_payload_sha256' => str_repeat('0', 64)]), 'Reject stale acknowledgment');
$changed = $build('annex_a', array_replace($record, ['version' => 8]), $aInputs);
$reject(static fn() => earistRenderDocx($changed, ['acknowledged_payload_sha256' => $review['payload_sha256']]), 'Source revision invalidates acknowledgment');
$tampered = $map; $tampered['fields']['research.title']['value'] = 'tampered';
$reject(static fn() => earistRenderReview($tampered), 'Reject edited usable mapping values');
$invalid = $build('annex_a', $record, ['ai_used' => 'no', 'ai_tools' => $aInputs['ai_tools']]);
$reject(static fn() => earistRenderReview($invalid), 'Reject contradictory supplied inputs');
$reject(static fn() => $build('annex_f', array_replace($record, ['deleted_at' => '2026-10-30 10:00:00'])), 'Reject deleted source');
$reject(static fn() => earistRenderDocx($map, ['acknowledged_payload_sha256' => $review['payload_sha256'], 'annex_d_verification' => []]), 'Reject faculty attribution of Admin evidence');
$blank = $render($build('annex_a', $record)); $blankParts = $open($blank['bytes']);
$blankDom = earistXmlParse($blankParts['word/document.xml']);
$check(!str_contains(earistXmlText(earistXmlOne($blankDom->documentElement, './w:body/w:tbl[4]')), '☒'), 'A: all template documentary prechecks cleared without confirmation');
$check($blank['preparation_mode'] === 'incomplete', 'Missing inputs may remain blank after current acknowledgment');
$save($blank, 'annex_a-unanswered.docx');
$qRecord = array_replace($record, ['sdgs' => [8, 9], 'sdg_other' => '']);
$q = $render($build('qsf_rds_019', $qRecord));
$check(!$q['supplements'], 'QSF: no unneeded continuation');
$qParts = $open($q['bytes']); $qDom = earistXmlParse($qParts['word/document.xml']);
$check(earistXmlText(earistXmlOne($qDom->documentElement, './w:body/w:tbl[1]/w:tr[1]/w:tc[2]')) === '', 'QSF: no unconfirmed applicant');
$check(earistXmlText(earistXmlOne($qDom->documentElement, './w:body/w:tbl[1]/w:tr[2]/w:tc[2]')) === ''
    && earistXmlText(earistXmlOne($qDom->documentElement, './w:body/w:tbl[1]/w:tr[3]/w:tc[2]')) === '', 'QSF: all applicant fields stay blank until applicant confirmation');
$check(!str_contains(earistXmlText($qDom->documentElement), '(Presenting author)'), 'QSF: no inferred presenter');

foreach ([
    [['start' => '2026-11-27', 'end' => '2026-11-28'], 'November 27-28, 2026'],
    [['start' => '2026-11-27', 'end' => '2026-11-30'], 'November 27-30, 2026'],
    [['start' => '2026-11-27', 'end' => '2026-12-02'], 'November 27-December 2, 2026'],
    [['start' => '2026-12-30', 'end' => '2027-01-02'], 'December 30, 2026-January 2, 2027'],
    [['start' => '2026-11-27', 'end' => ''], 'November 27, 2026'],
] as [$dates, $expected]) $check(earistRenderQsfConferenceDates($dates) === $expected, 'QSF: date format ' . $expected);
$reject(static fn() => earistRenderQsfConferenceDates(['start' => '2026-02-30', 'end' => '']), 'QSF: reject invalid rendered calendar date');

$manualQInputs = array_replace($qInputs, ['applicant_source' => 'manual', 'applicant_name' => 'Confirmed Other Applicant',
    'applicant_appointment' => 'Contractual', 'applicant_position' => 'Associate Professor', 'applicant_department' => 'Research Office',
    'applicant_contact' => '09123456789', 'applicant_email' => 'other@example.test']);
$manualQ = $render($build('qsf_rds_019', $qRecord, $manualQInputs));
$manualQDom = earistXmlParse($open($manualQ['bytes'])['word/document.xml']);
$check(earistXmlText(earistXmlOne($manualQDom->documentElement, './w:body/w:tbl[1]/w:tr[1]/w:tc[2]')) === 'Confirmed Other Applicant'
    && earistXmlText(earistXmlOne($manualQDom->documentElement, './w:body/w:tbl[1]/w:tr[2]/w:tc[2]')) === 'Contractual'
    && earistXmlText(earistXmlOne($manualQDom->documentElement, './w:body/w:tbl[1]/w:tr[3]/w:tc[2]')) === 'Associate Professor',
    'QSF: manually confirmed applicant details fill the applicant section');

// Opposite choices and complete SDG/attachment sets exercise every checkbox region.
$alternateSource = array_replace($record, ['presentation_modality' => 'virtual', 'conference_classification' => 'regional_national_local', 'sdgs' => range(1, 17)]);
$alternateInputs = array_replace($aInputs, ['proceedings' => 'no', 'conference_indexing' => [], 'peer_review_clear' => 'yes',
    'journal_indexing' => ['na'], 'scope_aligned' => 'no', 'scope_explanation' => '', 'ai_used' => 'no', 'ai_tools' => [],
    'checklist_profiles' => 'yes', 'checklist_declaration' => 'yes', 'checklist_manuscript' => 'yes', 'checklist_target_details' => 'yes']);
$alternate = $render($build('annex_a', $alternateSource, $alternateInputs));
$alternateParts = $open($alternate['bytes']); $alternateDom = earistXmlParse($alternateParts['word/document.xml']);
$alternateText = earistXmlText($alternateDom->documentElement);
$check(str_contains($alternateText, '☒ Regional/National/Local Conference') && str_contains($alternateText, '☐ In-Person ☒ Virtual'), 'A: opposite conference class/modality');
$check(substr_count(earistXmlText(earistXmlOne($alternateDom->documentElement, './w:body/w:tbl[1]/w:tr[4]')), '☒') === 18, 'A: all 17 SDGs and Others');
$check(substr_count(earistXmlText(earistXmlOne($alternateDom->documentElement, './w:body/w:tbl[4]')), '☒') === 4, 'A: all four documentary answers explicitly confirmed');
$check(str_contains($alternateText, '☒ I/We did not use') && !str_contains($alternateText, 'Synthetic Tool'), 'A: confirmed no-AI keeps AI rows blank');
$check(str_contains(earistXmlText(earistXmlOne($alternateDom->documentElement, './w:body/w:tbl[2]/w:tr[6]/w:tc[2]')), '☐ Yes    ☒ No'), 'A: confirmed no proceedings');
foreach (['personal', 'organizer_grant'] as $funding) {
    $alternateQ = $render($build('qsf_rds_019', $alternateSource, array_replace($qInputs, ['funding_source' => $funding,
        'estimated_expenses' => '', 'expense_currency' => '', 'recognition' => 'no', 'qsf_classification' => 'international',
        'attachments' => ['full_text', 'invitation_acceptance', 'brochure', 'fee', 'itinerary', 'legitimacy']])));
    $alternateQParts = $open($alternateQ['bytes']); $alternateQDom = earistXmlParse($alternateQParts['word/document.xml']);
    $check(substr_count(earistXmlText(earistXmlOne($alternateQDom->documentElement, './w:body/w:tbl[5]')), '☒') === 6, 'QSF: all six confirmed attachments');
    $check(str_contains(earistXmlText(earistXmlOne($alternateQDom->documentElement, './w:body/w:tbl[2]/w:tr[2]/w:tc[3]')),
        $funding === 'personal' ? '☒ Personal Funding' : '☒ Funded by Organizer'), 'QSF: independently confirmed funding ' . $funding);
    $check(str_contains(earistXmlText(earistXmlOne($alternateQDom->documentElement, './w:body/w:tbl[2]/w:tr[2]/w:tc[2]')), '☒ International Conference'), 'QSF: alternate conference classification');
}
$overlong = $build('annex_c', array_replace($record, ['title' => str_repeat('x', 2001)]));
$reject(static fn() => earistRenderReview($overlong), 'Reject title beyond source limit without truncation');
$overlongD = $build('annex_d', array_replace($record, ['title' => str_repeat('x', 2000) . ' ']), $dInputs);
$reject(static fn() => earistRenderReview($overlongD), 'D: raw saved title limit includes whitespace');

foreach (['0' => 'not_recommended', '59.9999999999999999999999999' => 'not_recommended', '60' => 'conditionally_recommended',
    '69.9999999999999999999999999' => 'conditionally_recommended', '70.0000' => 'moderately_recommended', '79.99999999999999999999999' => 'moderately_recommended',
    '80' => 'recommended', '89.9999999999999999999999999' => 'recommended', '90' => 'highly_recommended', '99.99999999999999999999999' => 'highly_recommended',
    '100.0000000000000000000000000000000' => 'highly_recommended'] as $score => $expected) {
    $check(earistAnnexDScoreClassification((string) $score) === $expected, 'D: exact decimal boundary ' . $score);
    $boundary = $build('annex_d', $record, array_replace($dInputs, ['final_score' => (string) $score, 'classification' => $expected]));
    $check($boundary['review_valid'], 'D: matching boundary validates ' . $score);
    $boundaryOutput = $render($boundary, true); $boundaryParts = $open($boundaryOutput['bytes']);
    $boundaryText = earistXmlText(earistXmlParse($boundaryParts['word/document.xml'])->documentElement);
    $check(str_contains($boundaryText, (string) $score . '/100') && str_contains($boundaryText, '☒ ' . earistAnnexDClassifications()[$expected] . ' ('), 'D: boundary rendered exactly ' . $score);
}
foreach (['-1', '100.00000000000000000000001', '101', '1e2', 'NaN', '.5', '90.', '80,5'] as $score) {
    $reject(static fn() => earistAnnexDScoreClassification($score), 'D: reject invalid exact score ' . $score);
}
foreach ([['final_score' => 90.0], ['classification' => 'highly_recommended'], ['certification_date' => '2026-02-30'],
    ['scoring_policy_confirmed' => 'yes'], ['signature' => 'forbidden']] as $change) {
    $bad = $build('annex_d', $record, array_replace($dInputs, $change));
    $reject(static fn() => earistRenderReview($bad), 'D: invalid values cannot render ' . implode(',', array_keys($change)));
}
$d = $build('annex_d', $record, $dInputs); $dReview = earistRenderReview($d);
$evidence = ['verified_by' => 900009, 'verified_at' => '2026-10-30 12:00:00', 'verified_payload_sha256' => $dReview['payload_sha256']];
$changedD = $build('annex_d', $record, array_replace($dInputs, ['certification_place' => 'Edited place']));
$changedReview = earistRenderReview($changedD);
$reject(static fn() => earistRenderDocx($changedD, ['acknowledged_payload_sha256' => $changedReview['payload_sha256'], 'annex_d_verification' => $evidence]), 'D: verification cannot attest a later edit');
foreach ([['verified_by' => 0], ['verified_at' => '2026-02-30 00:00:00'], ['verified_payload_sha256' => str_repeat('0', 64)]] as $change) {
    $reject(static fn() => earistRenderDocx($d, ['acknowledged_payload_sha256' => $dReview['payload_sha256'], 'annex_d_verification' => array_replace($evidence, $change)]), 'D: malformed evidence rejected');
}
foreach (['unverified' => $d, 'incomplete' => $build('annex_d', $record, []),
    'policy-unconfirmed' => $build('annex_d', $record, array_replace($dInputs, ['scoring_policy_confirmed' => false]))] as $scenario => $mapping) {
    $output = $render($mapping); $parts = $open($output['bytes']); $text = earistXmlText(earistXmlParse($parts['word/document.xml'])->documentElement);
    $check(str_contains($text, 'UNISSUED — INCOMPLETE PREPARATION') && !str_contains($text, $dInputs['final_score']), 'D: ' . $scenario . ' marked and official values blank');
    $check($output['preparation_mode'] === 'incomplete' && $output['verified_evidence'] === null, 'D: no evidence attributed to incomplete copy');
    $save($output, 'annex_d-' . $scenario . '.docx');
}
// Structural rejection without altering a source template.
$templateZip = new ZipArchive(); $templateZip->open(__DIR__ . '/../templates/earist/blank/' . $manifest['documents']['annex_a']['filename'], ZipArchive::RDONLY);
$templateDom = earistXmlParse($templateZip->getFromName('word/document.xml')); $templateZip->close();
$badDom = clone $templateDom;
earistXmlOne($badDom->documentElement, './w:body/w:tbl[1]/w:tr[1]/w:tc[1]/w:p/w:r/w:t')->textContent = 'Changed label';
$reject(static fn() => earistXmlResolveBindings($badDom, $manifest['documents']['annex_a']), 'Reject changed structural guard');
$badSpec = $manifest['documents']['annex_a']; $badSpec['bindings'][0]['target'] = './w:body/w:tbl/w:tr/w:tc';
$reject(static fn() => earistXmlResolveBindings($templateDom, $badSpec), 'Reject ambiguous structural targets');
$reject(static fn() => earistXmlCheckboxes(earistXmlOne($templateDom->documentElement, './w:body/w:tbl[4]'), [true]), 'Reject changed checkbox count');
$reject(static fn() => earistXmlParse('<!DOCTYPE a [<!ENTITY x SYSTEM "file:///unused">]><a>&x;</a>'), 'Reject external XML entities');
$reject(static fn() => earistXmlValidateText("bad\x01text"), 'Reject XML control characters');
foreach ($baseline as $path => $hash) $check(hash_equals($hash, hash_file('sha256', $path)), 'Source template unchanged: ' . basename($path));

if ($sampleDirectory !== null) {
    file_put_contents($sampleDirectory . '/manifest.json', json_encode(['synthetic_only' => true, 'no_database' => true,
        'renderer_version' => EARIST_DOCX_RENDERER_VERSION, 'checks' => ['passed' => $passed, 'failed' => $failed], 'files' => $sampleManifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    file_put_contents($sampleDirectory . '/README.txt', "SYNTHETIC STAGE 4B SAMPLES — NOT INSTITUTIONAL RECORDS\nNo real faculty data, application sessions or database changes.\nOpen these files locally in Microsoft Word; browser downloads are intentionally unavailable until Stage 4C.\nInspect complete titles, saved author order, fonts/logos, row wrapping, pagination, checkboxes and handwriting spaces.\nQSF SDG continuation is a separate companion DOCX; retain it with the matching request.\nAnnex D verified examples use fabricated evidence for pure rendering tests only and establish no authorization, evaluation, signing or issuance.\nLong-title files intentionally stress pagination; no Word/printing result is claimed.\nNo automatic cleanup or retention deletion.\n");
    echo 'Samples: ' . realpath($sampleDirectory) . ' (' . count($sampleManifest) . ' DOCX files)' . PHP_EOL;
}
foreach ($failed as $message) fwrite(STDERR, 'FAILED: ' . $message . PHP_EOL);
echo $passed . ' passed; ' . count($failed) . ' failed. Synthetic file-only rendering; no database, sessions or application requests.' . PHP_EOL;
exit($failed ? 1 : 0);
