<?php
/** Stage 4B internal renderer. Never expose directly as a request/download handler.
 * Callers must authenticate, authorize and load an eligible source before calling.
 * No database connection, history persistence, signing, issuance or PDF conversion.
 */
require_once __DIR__ . '/earist-docx-xml.php';
require_once __DIR__ . '/earist-annex-d.php';
const EARIST_DOCX_RENDERER_VERSION = '4b.15';

function earistRenderCanonical($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as &$item) $item = earistRenderCanonical($item);
    unset($item);
    return $value;
}

function earistRenderHash(array $value): string
{
    return hash('sha256', json_encode(earistRenderCanonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Rebuild rather than trust caller-supplied review flags or edited usable fields. */
function earistRenderReview(array $mapping): array
{
    if (($mapping['review_valid'] ?? false) !== true || !empty($mapping['errors'])) throw new DomainException('Correct all mapping errors before rendering.');
    $type = $mapping['document']['key'] ?? '';
    earistMappingDocument($type);
    if ($type === 'annex_d') $rebuilt = earistAnnexDMappingBuild($mapping['source']['record'] ?? [], $mapping['inputs'] ?? []);
    else $rebuilt = earistMappingBuild($mapping['source']['profile'] ?? [], $mapping['source']['record'] ?? [], $type, $mapping['inputs'] ?? []);
    if (!$rebuilt['review_valid'] || !hash_equals(earistRenderHash($rebuilt['fields']), earistRenderHash($mapping['fields'] ?? []))) {
        throw new DomainException('Mapping values differ from the validated source.');
    }
    $warnings = array_values(array_filter($rebuilt['warnings'], static fn(string $warning): bool =>
        !str_contains($warning, 'This is a field-mapping review.') && !str_contains($warning, 'Unrepresented selections retained')));
    $warnings[] = 'Review wrapping and pagination in Microsoft Word before printing; physical signature spaces remain blank.';
    $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
    $payload = ['renderer_version' => EARIST_DOCX_RENDERER_VERSION, 'binding_version' => $manifest['version'],
        'template_sha256' => $manifest['documents'][$type]['sha256'], 'mapping' => $rebuilt,
        'missing' => $rebuilt['missing'], 'warnings' => $warnings];
    return ['mapping' => $rebuilt, 'missing' => $rebuilt['missing'], 'warnings' => $warnings,
        'payload_sha256' => earistRenderHash($payload), 'template_sha256' => $payload['template_sha256'], 'binding_version' => $manifest['version']];
}

function earistRenderValue(array $mapping, string $key)
{
    $field = $mapping['fields'][$key] ?? null;
    if (!$field || !empty($field['errors']) || !($field['applicable'] ?? false) || in_array($field['origin'] ?? '', ['reserved', 'fixed'], true)) return null;
    return $field['value'];
}

function earistRenderText($value): string
{
    if ($value === null || $value === '') return '';
    if (!is_array($value)) return (string) $value;
    $lines = [];
    foreach ($value as $key => $item) {
        $text = earistRenderText($item);
        if ($text !== '') $lines[] = (is_string($key) ? ucfirst($key) . ': ' : '') . $text;
    }
    return implode("\n", $lines);
}

/** Format validated QSF conference dates for the printed request form. */
function earistRenderQsfConferenceDates($value): string
{
    if (!is_array($value)) throw new DomainException('QSF conference dates are invalid.');
    $parse = static function ($raw): ?DateTimeImmutable {
        if ($raw === null || $raw === '') return null;
        if (!is_string($raw)) throw new DomainException('QSF conference dates are invalid.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$date || $date->format('Y-m-d') !== $raw) throw new DomainException('QSF conference dates are invalid.');
        return $date;
    };
    $start = $parse($value['start'] ?? '');
    $end = $parse($value['end'] ?? '');
    if (!$start) return $end?->format('F j, Y') ?? '';
    if (!$end || $start == $end) return $start->format('F j, Y');
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('F j') . "\u{2013}" . $end->format('j, Y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('F j') . "\u{2013}" . $end->format('F j, Y');
    }
    return $start->format('F j, Y') . "\u{2013}" . $end->format('F j, Y');
}

/** Set explicit bold state on the single generated QSF cell run. */
function earistRenderQsfCellBold(DOMElement $cell, bool $bold): void
{
    $paragraphs = earistXmlQuery($cell, './w:p');
    if (!$paragraphs->length) throw new DomainException('QSF text cell has no paragraph.');
    foreach ($paragraphs as $paragraph) foreach (earistXmlQuery($paragraph, './w:r') as $run) {
        $properties = earistXmlQuery($run, './w:rPr')->item(0);
        if (!$properties instanceof DOMElement) {
            $properties = $run->ownerDocument->createElementNS(EARIST_WORD_NS, 'w:rPr');
            $run->insertBefore($properties, $run->firstChild);
        }
        foreach (['b' => ['rStyle', 'rFonts'], 'bCs' => ['rStyle', 'rFonts', 'b']] as $name => $preceding) {
            $nodes = earistXmlQuery($properties, './w:' . $name);
            if ($nodes->length > 1) throw new DomainException('QSF text style is ambiguous.');
            $node = $nodes->item(0);
            if ($node instanceof DOMElement) {
                $node->setAttributeNS(EARIST_WORD_NS, 'w:val', $bold ? '1' : '0');
                continue;
            }
            $node = $properties->ownerDocument->createElementNS(EARIST_WORD_NS, 'w:' . $name);
            $node->setAttributeNS(EARIST_WORD_NS, 'w:val', $bold ? '1' : '0');
            $insertBefore = null;
            foreach ($properties->childNodes as $child) {
                if ($child instanceof DOMElement && !in_array($child->localName, $preceding, true)) {
                    $insertBefore = $child;
                    break;
                }
            }
            $properties->insertBefore($node, $insertBefore);
        }
    }
}

/** Remove Annex E's guarded empty body spacers in the generated copy only. */
function earistRenderCompactAnnexESpacing(DOMDocument $dom): void
{
    $body = earistXmlOne($dom->documentElement, './w:body');
    $children = array_values(array_filter(iterator_to_array($body->childNodes), static fn(DOMNode $node): bool => $node instanceof DOMElement));
    $empty = [];
    foreach ($children as $index => $node) {
        if (!$node instanceof DOMElement || $node->namespaceURI !== EARIST_WORD_NS || $node->localName !== 'p'
            || trim(earistXmlText($node)) !== '') continue;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== EARIST_WORD_NS || $child->localName !== 'pPr') {
                throw new DomainException('Annex E spacing paragraph contains non-spacing content.');
            }
        }
        if (earistXmlQuery($node, './/w:pageBreakBefore | .//w:sectPr | .//w:keepNext | .//w:keepLines | .//w:framePr | .//w:drawing | .//w:pict | .//w:fldChar | .//w:br')->length) {
            throw new DomainException('Annex E spacing contains protected layout content.');
        }
        $empty[$index] = $node;
    }
    if (count($empty) !== 15) throw new DomainException('Annex E spacing differs from the approved template.');
    foreach ($empty as $paragraph) $body->removeChild($paragraph);
}

/** Remove only Annex F's guarded trailing blank-page filler in generated output. */
function earistRenderRemoveAnnexFTrailingFiller(DOMDocument $dom): void
{
    $body = earistXmlOne($dom->documentElement, './w:body');
    $children = array_values(array_filter(iterator_to_array($body->childNodes), static fn(DOMNode $node): bool => $node instanceof DOMElement));
    $sectionIndex = count($children) - 1;
    if (!$children[$sectionIndex] instanceof DOMElement || $children[$sectionIndex]->namespaceURI !== EARIST_WORD_NS
        || $children[$sectionIndex]->localName !== 'sectPr') throw new DomainException('Annex F section boundary differs.');
    $cursor = $sectionIndex - 1;
    $trailing = [];
    while ($cursor >= 0 && $children[$cursor]->namespaceURI === EARIST_WORD_NS
        && $children[$cursor]->localName === 'p' && trim(earistXmlText($children[$cursor])) === '') {
        $trailing[] = $children[$cursor];
        $cursor--;
    }
    if (count($trailing) !== 22 || !isset($children[$cursor])
        || !str_starts_with(trim(earistXmlText($children[$cursor])), 'Note: This certification shall form part of the review records')) {
        throw new DomainException('Annex F trailing blank-page filler differs from the approved template.');
    }
    // Remove all guarded trailing filler so the note cannot push a blank third page.
    foreach ($trailing as $paragraph) {
        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== EARIST_WORD_NS || $child->localName !== 'pPr') {
                throw new DomainException('Annex F trailing paragraph contains non-spacing content.');
            }
        }
        if (earistXmlQuery($paragraph, './/w:pageBreakBefore | .//w:sectPr | .//w:keepNext | .//w:keepLines | .//w:framePr | .//w:drawing | .//w:pict | .//w:fldChar | .//w:br')->length) {
            throw new DomainException('Annex F trailing filler contains protected layout content.');
        }
        $body->removeChild($paragraph);
    }
}

function earistRenderYesNo($value): array
{
    return [$value === 'yes', $value === 'no'];
}

function earistRenderIndexing($value): array
{
    return array_map(static fn(string $key): bool => in_array($key, $value ?? [], true), ['wos', 'scopus', 'na']);
}

/** Repeat template author rows, preserving the five columns and all signature spaces. */
function earistRenderContributions(DOMElement $row, array $mapping): void
{
    $table = $row->parentNode;
    $rows = iterator_to_array(earistXmlQuery($table, './w:tr'));
    if (count($rows) !== 3) throw new DomainException('Contribution row structure differs.');
    $prototype = earistXmlClone($row);
    $authors = earistRenderValue($mapping, 'research.authors');
    foreach ($authors as $index => $author) {
        $target = $rows[$index + 1] ?? null;
        if (!$target) { $target = earistXmlClone($prototype); $table->appendChild($target); }
        $cells = iterator_to_array(earistXmlQuery($target, './w:tc'));
        if (count($cells) !== 5) throw new DomainException('Contribution columns differ.');
        $contribution = [];
        foreach (['categories', 'description', 'percentage'] as $column) {
            $contribution[$column] = earistRenderValue($mapping, 'contributions.' . $author['record_author_id'] . '.' . $column);
        }
        $values = [$author['full_name'] . "\n" . $author['affiliation'],
            $author['author_role'] === 'lead' ? 'Lead Author' : 'Co-Author',
            implode("\n", $contribution['categories'] ?? []), $contribution['description'] ?? '',
            ($contribution['percentage'] ?? '') === '' ? '' : $contribution['percentage'] . '%'];
        foreach ($cells as $column => $cell) earistXmlFillCell($cell, $values[$column]);
    }
}

function earistRenderAiRows(DOMElement $table, array $values): void
{
    $rows = iterator_to_array(earistXmlQuery($table, './w:tr'));
    if (count($rows) !== 3) throw new DomainException('AI table structure differs.');
    $prototype = earistXmlClone($rows[1]);
    foreach ($values as $index => $value) {
        $target = $rows[$index + 1] ?? null;
        if (!$target) { $target = earistXmlClone($prototype); $table->appendChild($target); }
        $cells = iterator_to_array(earistXmlQuery($target, './w:tc'));
        if (count($cells) !== 4) throw new DomainException('AI table columns differ.');
        foreach (['tool', 'purpose', 'prompts', 'extent'] as $column => $key) earistXmlFillCell($cells[$column], $value[$key]);
    }
}

function earistRenderAuthorSignatures(DOMDocument $dom, string $type, int $authorCount): void
{
    $root = $dom->documentElement;
    if ($type === 'annex_a' && $authorCount > 2) {
        $table = earistXmlOne($root, './w:body/w:tbl[5]/w:tr/w:tc/w:tbl[2]');
        $rows = iterator_to_array(earistXmlQuery($table, './w:tr'));
        if (count($rows) !== 7 || !str_contains(earistXmlText($rows[5]), 'Signature over Printed Name of the Co-Author')) throw new DomainException('Author signature structure differs.');
        for ($index = 2; $index < $authorCount; $index++) {
            foreach ([3, 4, 5] as $part) $table->insertBefore(earistXmlClone($rows[$part]), $rows[6]);
        }
    }
    if ($type === 'annex_b' && $authorCount > 2) {
        $table = earistXmlOne($root, './w:body/w:tbl[3]');
        $row = earistXmlOne($table, './w:tr');
        if (earistXmlQuery($row, './w:tc')->length !== 2) throw new DomainException('Signature columns differ.');
        for ($index = 2; $index < $authorCount; $index += 2) $table->appendChild(earistXmlClone($row));
    }
}

/** Reduce the Annex B output gap and start the taxonomy appendix on a new page. */
function earistRenderCompactAnnexBTaxonomyGap(DOMDocument $dom): void
{
    $body = earistXmlOne($dom->documentElement, './w:body');
    $headings = [];
    foreach ($body->childNodes as $child) {
        if ($child instanceof DOMElement && $child->namespaceURI === EARIST_WORD_NS && $child->localName === 'p'
            && trim(earistXmlText($child)) === 'Contributor Roles Taxonomy') {
            $headings[] = $child;
        }
    }
    if (count($headings) !== 1) throw new DomainException('Annex B taxonomy heading is missing or ambiguous.');

    $remove = [];
    for ($node = $headings[0]->previousSibling; $node instanceof DOMElement
        && $node->namespaceURI === EARIST_WORD_NS && $node->localName === 'p' && trim(earistXmlText($node)) === '';
        $node = $node->previousSibling) {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== EARIST_WORD_NS || $child->localName !== 'pPr') {
                throw new DomainException('Annex B taxonomy spacing contains non-spacing content.');
            }
        }
        if (earistXmlQuery($node, './/w:pageBreakBefore | .//w:sectPr | .//w:keepNext | .//w:keepLines | .//w:framePr')->length) {
            throw new DomainException('Annex B taxonomy spacing contains page-layout controls.');
        }
        $remove[] = $node;
    }
    if (count($remove) !== 39) throw new DomainException('Annex B taxonomy spacing differs from the approved structure.');
    foreach (array_slice($remove, 2) as $paragraph) $body->removeChild($paragraph);

    $heading = $headings[0];
    $properties = earistXmlOne($heading, './w:pPr');
    $propertyNames = [];
    foreach ($properties->childNodes as $property) {
        if (!$property instanceof DOMElement || $property->namespaceURI !== EARIST_WORD_NS) throw new DomainException('Annex B taxonomy heading properties differ.');
        $propertyNames[] = $property->localName;
    }
    if ($propertyNames !== ['tabs', 'spacing', 'jc', 'rPr'] || earistXmlQuery($properties, './w:pageBreakBefore')->length) {
        throw new DomainException('Annex B taxonomy page-break destination differs from the approved structure.');
    }
    $pageBreak = $dom->createElementNS(EARIST_WORD_NS, 'w:pageBreakBefore');
    $pageBreak->setAttributeNS(EARIST_WORD_NS, 'w:val', '1');
    $properties->insertBefore($pageBreak, $properties->firstChild);
}

function earistRenderAnnexA(DOMElement $node, array $binding, array $mapping): void
{
    $key = $binding['fields'][0];
    $get = static fn(string $field) => earistRenderValue($mapping, $field);
    if ($binding['kind'] === 'repeat_ai_rows') { earistRenderAiRows($node, $get('input.ai_tools') ?? []); return; }
    if ($binding['kind'] === 'prechecked_documentary_region') {
        earistXmlCheckboxes($node, array_map(static fn(string $field): bool => $get($field) === 'yes', $binding['fields'])); return;
    }
    if ($binding['kind'] === 'mixed_nested_tables') {
        $paragraphs = iterator_to_array(earistXmlQuery($node, './w:p'));
        $declarations = [];
        foreach ($paragraphs as $paragraph) if (str_contains(earistXmlText($paragraph), '☐')) $declarations[] = $paragraph;
        if (count($declarations) !== 2) throw new DomainException('AI declaration structure differs.');
        earistXmlCheckboxes($declarations[0], [$get('input.ai_used') === 'yes']);
        earistXmlCheckboxes($declarations[1], [$get('input.ai_used') === 'no']);
        return;
    }
    switch ($key) {
        case 'research.sdgs':
            $first = str_ends_with($binding['target'], 'w:tc[1]');
            $selections = [];
            foreach (range($first ? 1 : 10, $first ? 9 : 17) as $number) $selections[] = in_array($number, $get($key) ?? [], true);
            if (!$first) $selections[] = ($get('research.sdg_other') ?? '') !== '';
            earistXmlCheckboxes($node, $selections);
            if (!$first && $get('research.sdg_other')) earistXmlReplace($node, '________________', $get('research.sdg_other'));
            break;
        case 'conference.classification':
            earistXmlCheckboxes($node, [$get($key) === 'Regional/National/Local Conference', $get($key) === 'International Conference']); break;
        case 'input.proceedings':
            earistXmlCheckboxes($node, [...earistRenderYesNo($get($key)), ...earistRenderIndexing($get('input.conference_indexing'))]); break;
        case 'conference.modality':
            earistXmlCheckboxes($node, [$get($key) === 'In-Person', $get($key) === 'Virtual']);
            if ($get('conference.venue')) earistXmlReplace($node, '___________________________', $get('conference.venue'));
            break;
        case 'input.peer_review_clear': earistXmlCheckboxes($node, earistRenderYesNo($get($key))); break;
        case 'input.journal_indexing': earistXmlCheckboxes($node, earistRenderIndexing($get($key))); break;
        case 'input.scope_aligned':
            earistXmlCheckboxes($node, earistRenderYesNo($get($key)));
            if ($get('input.scope_explanation')) earistXmlWriteParagraph(earistXmlOne($node, './w:p[3]'), $get('input.scope_explanation'));
            break;
        default:
            if (!str_starts_with($binding['kind'], 'protected_')) throw new LogicException('Unimplemented Annex A binding.');
    }
}

function earistRenderQsf(DOMElement $node, array $binding, array $mapping): void
{
    $key = $binding['fields'][0];
    $get = static fn(string $field) => earistRenderValue($mapping, $field);
    switch ($key) {
        case 'conference.modality': earistXmlCheckboxes($node, [$get($key) === 'In-Person', $get($key) === 'Virtual']); break;
        case 'input.qsf_classification': earistXmlCheckboxes($node, [$get($key) === 'philippines', $get($key) === 'international']); break;
        case 'input.funding_source':
            earistXmlCheckboxes($node, array_map(static fn(string $value): bool => $get($key) === $value, ['organizer_grant', 'personal', 'earist']));
            if ($get('input.estimated_expenses') !== null && $get('input.estimated_expenses') !== '') {
                earistXmlReplace($node, '___________________________', $get('input.expense_currency') . ' ' . $get('input.estimated_expenses'));
            }
            break;
        case 'research.sdgs':
            $paragraphs = iterator_to_array(earistXmlQuery($node, './w:p'));
            if (!$paragraphs) throw new DomainException('QSF SDG cell has no paragraph.');
            $lines = [];
            $labels = facultyRecordSdgs();
            foreach ($get($key) ?? [] as $number) {
                if (!is_int($number) || $number < 1 || $number > 17) throw new DomainException('QSF SDG selection is invalid.');
                $lines[] = $number . '. ' . $labels[$number];
            }
            $other = $get('research.sdg_other');
            if (is_string($other) && trim($other) !== '') $lines[] = 'Others: ' . trim($other);
            foreach (iterator_to_array($paragraphs[0]->childNodes) as $child) {
                if (!$child instanceof DOMElement || $child->namespaceURI !== EARIST_WORD_NS || $child->localName !== 'pPr') {
                    $paragraphs[0]->removeChild($child);
                }
            }
            earistXmlWriteParagraph($paragraphs[0], implode("\n", $lines));
            foreach (array_slice($paragraphs, 1) as $paragraph) $node->removeChild($paragraph);
            earistRenderQsfCellBold($node, false);
            break;
        case 'input.recognition': earistXmlCheckboxes($node, earistRenderYesNo($get($key))); break;
        case 'input.abstract_date': case 'input.acceptance_date':
            if ($get($key)) {
                $paragraph = earistXmlOne($node, './w:p');
                $prefix = $key === 'input.abstract_date' ? 'Date submitted: ' : 'Date accepted: ';
                earistXmlAssertText($paragraph, $prefix);
                earistXmlReplace($paragraph, $prefix, $prefix . $get($key));
            }
            break;
        case 'input.attachments':
            earistXmlCheckboxes($node, array_map(static fn(string $value): bool => in_array($value, $get($key) ?? [], true),
                ['full_text', 'invitation_acceptance', 'brochure', 'fee', 'itinerary', 'legitimacy'])); break;
        default:
            if (!str_starts_with($binding['kind'], 'protected_')) throw new LogicException('Unimplemented QSF binding.');
    }
}

/** Evidence is supplied only by a future live Admin service, never from form fields.
 * This pure validator can bind evidence to content; it cannot establish identity.
 */
function earistRenderAnnexDVerified(array $review, ?array $evidence): bool
{
    if ($evidence === null) return false;
    $time = $evidence['verified_at'] ?? '';
    $date = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time) : false;
    if (!is_int($evidence['verified_by'] ?? null) || $evidence['verified_by'] < 1
        || !$date || $date->format('Y-m-d H:i:s') !== $time
        || !is_string($evidence['verified_payload_sha256'] ?? null)
        || !hash_equals($review['payload_sha256'], $evidence['verified_payload_sha256'])) {
        throw new DomainException('Annex D verification evidence does not match this preparation.');
    }
    return !$review['missing'];
}

function earistRenderAnnexD(DOMDocument $dom, array $mapping, bool $verified): void
{
    $root = $dom->documentElement;
    $score = earistXmlOne($root, './w:body/w:p[14]');
    earistXmlAssertText($score, 'Based on the evaluation results, the scholarly work received a final quality review score of: _____________/100');
    $result = earistXmlOne($root, './w:body/w:p[20]');
    earistXmlAssertText(earistXmlOne($root, './w:body/w:p[19]'), 'Accordingly, the scholarly work is: ');
    earistXmlAssertText($result, '');
    $issuance = earistXmlOne($root, './w:body/w:p[22]');
    earistXmlAssertText($issuance, 'Issued this _____ day of __________ 20____ at ____________________________.');
    $table = earistXmlOne($root, './w:body/w:tbl[2]');
    $inputs = $mapping['inputs'];
    // Unverified official values never populate certificate assertions, even on a marked copy.
    if ($verified) {
        earistXmlReplace($score, '_____________', $inputs['final_score']);
        $selections = array_map(static fn(string $key): bool => $inputs['classification'] === $key, array_keys(earistAnnexDClassifications()));
        earistXmlCheckboxes($table, $selections);
        earistXmlWriteParagraph($result, $inputs['certification_statement']);
        $date = new DateTimeImmutable($inputs['certification_date']);
        earistXmlReplace($issuance, 'Issued this _____ day of __________ 20____ at ____________________________.',
            'Issued this ' . $date->format('j') . ' day of ' . $date->format('F Y') . ' at ' . $inputs['certification_place'] . '.');
    }
    $banner = earistXmlClone($result);
    // The result may now contain text; make a fresh paragraph with its formatting only.
    foreach (iterator_to_array($banner->childNodes) as $child) if ($child->localName !== 'pPr') $banner->removeChild($child);
    earistXmlWriteParagraph($banner, $verified ? 'Prepared for physical signing' : 'UNISSUED — INCOMPLETE PREPARATION');
    $run = earistXmlOne($banner, './w:r');
    $properties = earistXmlQuery($run, './w:rPr')->item(0);
    if (!$properties) { $properties = $dom->createElementNS(EARIST_WORD_NS, 'w:rPr'); $run->insertBefore($properties, $run->firstChild); }
    $properties->appendChild($dom->createElementNS(EARIST_WORD_NS, 'w:b'));
    $body = earistXmlOne($root, './w:body');
    $body->insertBefore($banner, $body->firstChild);
}

/** Zip bytes are built in an exclusive temporary file; source packages are read-only. */
function earistRenderPackage(array $parts, ?string $scratchDirectory = null): string
{
    $scratch = null;
    if ($scratchDirectory !== null) {
        require_once __DIR__ . '/earist-document-files.php';
        $scratch = earistDocumentCreateScratch($scratchDirectory);
        $path = $scratch['path'];
        // libzip may replace its exclusive package path when finalizing the ZIP.
        fclose($scratch['handle']);
        $scratch['handle'] = null;
    } else $path = tempnam(sys_get_temp_dir(), 'rms-earist-');
    if ($path === false) throw new RuntimeException('Temporary document storage is unavailable.');
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create document package.');
        $opened = true;
        foreach ($parts as $name => $bytes) if (!$zip->addFromString($name, $bytes)) throw new RuntimeException('Unable to write document package.');
        if (!$zip->close()) throw new RuntimeException('Unable to finalize document package.');
        $opened = false;
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Unable to read document package.');
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Generated package integrity check failed.');
        $opened = true;
        if ($zip->numFiles !== count($parts)) throw new RuntimeException('Generated package entries differ.');
        foreach ($parts as $name => $expected) if ($zip->getFromName($name) !== $expected) throw new RuntimeException('Generated package bytes differ.');
        return $bytes;
    } finally {
        if ($opened) $zip->close();
        // Remove only the exclusive temporary scratch file created by this call.
        if ($scratch !== null) earistDocumentReleaseScratch($scratch);
        elseif (is_file($path)) unlink($path);
    }
}

/** Return bytes + metadata; caller owns protected storage and all access decisions.
 * Review acknowledgment and Admin evidence are bound to the exact current payload.
 */
function earistRenderDocx(array $mapping, array $options = []): array
{
    // Internal server option only; live callers must never pass raw request options.
    $scratchDirectory = $options['scratch_directory'] ?? null;
    if ($scratchDirectory !== null && !is_string($scratchDirectory)) throw new DomainException('Invalid protected scratch configuration.');
    $review = earistRenderReview($mapping);
    if (!is_string($options['acknowledged_payload_sha256'] ?? null)
        || !hash_equals($review['payload_sha256'], $options['acknowledged_payload_sha256'])) {
        throw new DomainException('Acknowledge the current warnings and missing information before rendering.');
    }
    $mapping = $review['mapping'];
    $type = $mapping['document']['key'];
    if ($type !== 'annex_d' && isset($options['annex_d_verification'])) throw new DomainException('Admin verification cannot be attributed to faculty forms.');
    $evidence = $options['annex_d_verification'] ?? null;
    $verified = $type === 'annex_d' && earistRenderAnnexDVerified($review, $evidence);
    $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
    $spec = $manifest['documents'][$type];
    $root = realpath(__DIR__ . '/../templates/earist/blank');
    $path = realpath($root . DIRECTORY_SEPARATOR . $spec['filename']);
    if (!$root || !$path || dirname($path) !== $root || !is_file($path)
        || !hash_equals($spec['sha256'], hash_file('sha256', $path))) throw new DomainException('Approved template is missing or changed.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Unable to read approved template.');
    $parts = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (isset($parts[$name])) throw new DomainException('Duplicate template package entry.');
            $bytes = $zip->getFromIndex($index);
            if ($bytes === false) throw new RuntimeException('Unreadable template package entry.');
            $parts[$name] = $bytes;
        }
    } finally { $zip->close(); }
    foreach ($spec['header_footer_hashes'] as $name => $hash) if (!isset($parts[$name]) || !hash_equals($hash, hash('sha256', $parts[$name]))) throw new DomainException('Template header/footer differs.');
    $dom = earistXmlParse($parts['word/document.xml']);
    $bindings = earistXmlResolveBindings($dom, $spec);
    foreach ($bindings as [$binding, $node]) {
        $kind = $binding['kind'];
        if ($type === 'qsf_rds_019' && ($binding['fields'][0] ?? '') === 'conference.dates') {
            earistXmlFillCell($node, earistRenderQsfConferenceDates(earistRenderValue($mapping, 'conference.dates')));
            earistRenderQsfCellBold($node, true);
        } elseif ($kind === 'blank_cell') {
            $values = array_map(static fn(string $key): string => earistRenderText(earistRenderValue($mapping, $key)), $binding['fields']);
            if ($type === 'qsf_rds_019' && in_array($binding['fields'][0] ?? '', ['applicant.name', 'applicant.appointment', 'applicant.position', 'applicant.department'], true) && $values[0] !== '') {
                $values[0] = mb_strtoupper($values[0], 'UTF-8');
            }
            earistXmlFillCell($node, implode(' ', array_filter($values, static fn(string $value): bool => $value !== '')));
            if ($type === 'qsf_rds_019' && in_array($binding['fields'][0] ?? '', ['research.title', 'conference.title', 'conference.venue', 'conference.organizer'], true)) {
                earistRenderQsfCellBold($node, false);
            }
        } elseif ($kind === 'author_block') {
            earistXmlAuthors($node, earistRenderValue($mapping, 'research.authors'), $type === 'annex_d');
            if ($type === 'qsf_rds_019') {
                earistRenderQsfCellBold($node, false);
                // Keep wrapped author details aligned with their own numbered entry.
                foreach (earistXmlQuery($node, './w:p/w:pPr/w:jc') as $alignment) {
                    $alignment->setAttributeNS(EARIST_WORD_NS, 'w:val', 'left');
                }
            }
        } elseif ($kind === 'repeat_author_rows') earistRenderContributions($node, $mapping);
        elseif ($type === 'annex_a') earistRenderAnnexA($node, $binding, $mapping);
        elseif ($type === 'qsf_rds_019') earistRenderQsf($node, $binding, $mapping);
        elseif (!str_starts_with($kind, 'protected_') && $kind !== 'fixed_officials') throw new LogicException('Unimplemented template binding.');
    }
    earistRenderAuthorSignatures($dom, $type, count(earistRenderValue($mapping, 'research.authors')));
    if ($type === 'annex_b') earistRenderCompactAnnexBTaxonomyGap($dom);
    if ($type === 'annex_e') earistRenderCompactAnnexESpacing($dom);
    if ($type === 'annex_f') earistRenderRemoveAnnexFTrailingFiller($dom);
    if ($type === 'annex_d') earistRenderAnnexD($dom, $mapping, $verified);
    $xml = $dom->saveXML();
    earistXmlParse($xml);
    $parts['word/document.xml'] = $xml;
    $bytes = earistRenderPackage($parts, $scratchDirectory);
    if (!hash_equals($spec['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Source template changed during rendering.');
    return ['bytes' => $bytes, 'filename' => str_replace('_', '-', $type) . '.docx', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
        'document_type' => $type, 'renderer_version' => EARIST_DOCX_RENDERER_VERSION, 'binding_version' => $manifest['version'],
        'template_sha256' => $spec['sha256'], 'review_payload_sha256' => $review['payload_sha256'],
        'missing' => $review['missing'], 'warnings' => $review['warnings'],
        'preparation_mode' => ($type === 'annex_d' ? $verified : !$review['missing']) ? 'prepared_for_printing' : 'incomplete',
        'verified_evidence' => $verified ? $evidence : null, 'supplements' => []];
}
