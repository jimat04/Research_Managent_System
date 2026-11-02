<?php
/** Internal OOXML primitives. No HTTP entry point, database or authorization. */
const EARIST_WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function earistXmlParse(string $xml): DOMDocument
{
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new DomainException('Document XML declarations are unsupported.');
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (!$dom->loadXML($xml, LIBXML_NONET)) throw new DomainException('Document XML is invalid.');
        return $dom;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function earistXmlQuery(DOMNode $node, string $path): DOMNodeList
{
    $xpath = new DOMXPath($node instanceof DOMDocument ? $node : $node->ownerDocument);
    $xpath->registerNamespace('w', EARIST_WORD_NS);
    $result = $xpath->query($path, $node);
    if ($result === false) throw new LogicException('Invalid renderer binding.');
    return $result;
}

function earistXmlOne(DOMNode $node, string $path): DOMElement
{
    $nodes = earistXmlQuery($node, $path);
    if ($nodes->length !== 1 || !$nodes->item(0) instanceof DOMElement) {
        throw new DomainException('Template binding is missing or ambiguous.');
    }
    return $nodes->item(0);
}

function earistXmlText(DOMNode $node): string
{
    $text = '';
    foreach (earistXmlQuery($node, './/w:t') as $part) $text .= $part->textContent;
    return $text;
}

function earistXmlAssertText(DOMNode $node, string $expected): void
{
    if (earistXmlText($node) !== $expected) throw new DomainException('Template text guard differs.');
}

/** Replacements touch text only, including a placeholder split across styled runs. */
function earistXmlReplace(DOMNode $node, string $needle, string $replacement): void
{
    earistXmlValidateText($replacement);
    $text = earistXmlText($node);
    if ($needle === '' || substr_count($text, $needle) !== 1) throw new DomainException('Template placeholder is ambiguous.');
    $start = mb_strpos($text, $needle, 0, 'UTF-8');
    $end = $start + mb_strlen($needle, 'UTF-8');
    $offset = 0;
    $inserted = false;
    foreach (earistXmlQuery($node, './/w:t') as $part) {
        $value = $part->textContent;
        $length = mb_strlen($value, 'UTF-8');
        if ($offset < $end && $offset + $length > $start) {
            $prefix = mb_substr($value, 0, max(0, $start - $offset), 'UTF-8');
            $suffix = mb_substr($value, max(0, $end - $offset), null, 'UTF-8');
            $part->textContent = $prefix . ($inserted ? '' : $replacement) . $suffix;
            $part->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $inserted = true;
        }
        $offset += $length;
    }
}

function earistXmlValidateText(string $text): void
{
    if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text)) {
        throw new DomainException('Output contains unsupported XML characters.');
    }
}

/** Add text to an existing empty paragraph, inheriting its template font properties. */
function earistXmlWriteParagraph(DOMElement $paragraph, string $text): void
{
    earistXmlValidateText($text);
    earistXmlAssertText($paragraph, '');
    if (earistXmlQuery($paragraph, './/w:drawing | .//w:pict | .//w:fldChar | .//w:br')->length) {
        throw new DomainException('Expected a plain blank paragraph.');
    }
    if ($text === '') return;
    $dom = $paragraph->ownerDocument;
    $run = $dom->createElementNS(EARIST_WORD_NS, 'w:r');
    $properties = earistXmlQuery($paragraph, './w:pPr/w:rPr | ./w:r/w:rPr')->item(0);
    if ($properties) $run->appendChild($properties->cloneNode(true));
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $index => $line) {
        if ($index) $run->appendChild($dom->createElementNS(EARIST_WORD_NS, 'w:br'));
        $part = $dom->createElementNS(EARIST_WORD_NS, 'w:t');
        $part->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $part->appendChild($dom->createTextNode($line));
        $run->appendChild($part);
    }
    $paragraph->appendChild($run);
    // Variable text must wrap rather than be clipped by a fixed-height template row.
    foreach (earistXmlQuery($paragraph, 'ancestor::w:tr[1]/w:trPr/w:trHeight') as $height) {
        if ($height->getAttributeNS(EARIST_WORD_NS, 'hRule') === 'exact') $height->setAttributeNS(EARIST_WORD_NS, 'w:hRule', 'atLeast');
    }
}

function earistXmlFillCell(DOMElement $cell, string $text): void
{
    earistXmlAssertText($cell, '');
    $paragraphs = earistXmlQuery($cell, './w:p');
    if (!$paragraphs->length) throw new DomainException('Blank cell has no template paragraph.');
    earistXmlWriteParagraph($paragraphs->item(0), $text);
}

/** Duplicate only known template fragments; discard Word editing identities on the clone. */
function earistXmlClone(DOMElement $node): DOMElement
{
    $copy = $node->cloneNode(true);
    $elements = [$copy, ...iterator_to_array($copy->getElementsByTagName('*'))];
    foreach ($elements as $element) {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (in_array($attribute->localName, ['paraId', 'textId'], true)) $element->removeAttributeNode($attribute);
        }
    }
    return $copy;
}

function earistXmlAuthors(DOMElement $cell, array $authors, bool $numbered = false): void
{
    earistXmlAssertText($cell, '');
    $paragraphs = iterator_to_array(earistXmlQuery($cell, './w:p'));
    if (!$paragraphs) throw new DomainException('Author block has no paragraphs.');
    $prototype = earistXmlClone($paragraphs[count($paragraphs) - 1]);
    foreach ($authors as $index => $author) {
        if (!isset($paragraphs[$index])) {
            $paragraphs[$index] = earistXmlClone($prototype);
            $cell->appendChild($paragraphs[$index]);
        }
        $text = ($numbered ? ($index + 1) . '. ' : '') . $author['full_name'];
        $text .= ', ' . $author['position'] . ', ' . $author['affiliation'];
        if (!empty($author['is_presenter'])) $text .= ' (Presenting author)';
        earistXmlWriteParagraph($paragraphs[$index], $text);
    }
}

/** Exact checkbox count, exact source package hash: never select by a fuzzy label. */
function earistXmlCheckboxes(DOMElement $region, array $selections): void
{
    $count = preg_match_all('/[☐☒☑]/u', earistXmlText($region));
    if ($count !== count($selections)) throw new DomainException('Template checkbox structure differs.');
    $index = 0;
    foreach (earistXmlQuery($region, './/w:t') as $part) {
        $part->textContent = preg_replace_callback('/[☐☒☑]/u', static function () use (&$index, $selections): string {
            return $selections[$index++] === true ? '☒' : '☐';
        }, $part->textContent);
    }
}

/** Resolve and validate ALL bindings before edits can shift any positional XPath. */
function earistXmlResolveBindings(DOMDocument $dom, array $spec): array
{
    $root = $dom->documentElement;
    if ($root->namespaceURI !== EARIST_WORD_NS || $root->localName !== 'document'
        || earistXmlQuery($root, './w:body/w:tbl')->length !== $spec['body_table_count']
        || earistXmlQuery($root, './/w:tbl')->length !== $spec['total_table_count']) {
        throw new DomainException('Template structure differs.');
    }
    $resolved = [];
    foreach ($spec['bindings'] as $binding) {
        $nodes = earistXmlQuery($root, $binding['target']);
        if ($nodes->length !== $binding['expected_count'] || $nodes->length !== 1) throw new DomainException('Template binding count differs.');
        if (isset($binding['guard'])) earistXmlAssertText(earistXmlOne($root, $binding['guard']), $binding['guard_text']);
        if (array_key_exists('expected_text', $binding)) earistXmlAssertText($nodes->item(0), $binding['expected_text']);
        $resolved[] = [$binding, $nodes->item(0)];
    }
    return $resolved;
}
