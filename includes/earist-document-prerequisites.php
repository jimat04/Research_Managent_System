<?php
/** Read-only, database-free capability probe. No generated files or configuration changes. */
function earistDocumentPrerequisites(): array
{
    $result = [
        'sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'zip_extension' => extension_loaded('zip'),
        'zip_archive_class' => class_exists('ZipArchive', false),
        'libzip_version' => null,
        'dom' => class_exists('DOMDocument', false),
        'libxml' => extension_loaded('libxml'),
        'mbstring' => extension_loaded('mbstring'),
        'template_read' => false,
        'template_xml' => false,
        'template_unchanged' => false,
        'ready' => false,
        'message' => 'Document-processing prerequisites are unavailable.',
    ];
    if (!$result['zip_extension'] || !$result['zip_archive_class'] || !$result['dom'] || !$result['libxml'] || !$result['mbstring']) return $result;

    $zip = null;
    $opened = false;
    $previousErrors = libxml_use_internal_errors(true);
    try {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
        $spec = $manifest['documents']['annex_a'];
        $root = realpath(__DIR__ . '/../templates/earist/blank');
        $filename = (string) $spec['filename'];
        if ($filename !== basename($filename)) throw new RuntimeException('Invalid template filename.');
        $path = realpath($root . DIRECTORY_SEPARATOR . $filename);
        if (!$root || !$path || dirname($path) !== $root || !is_file($path)) throw new RuntimeException('Template unavailable.');
        $before = hash_file('sha256', $path);
        if (!hash_equals((string) $spec['sha256'], $before)) throw new RuntimeException('Template hash differs.');
        $zip = new ZipArchive();
        $result['libzip_version'] = ZipArchive::LIBZIP_VERSION;
        $opened = $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true;
        if (!$opened) throw new RuntimeException('Unable to open template.');
        $xml = $zip->getFromName('word/document.xml');
        if (!is_string($xml) || stripos($xml, '<!DOCTYPE') !== false) throw new RuntimeException('Invalid template XML.');
        $result['template_read'] = true;
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $result['template_xml'] = $document->loadXML($xml, LIBXML_NONET)
            && $document->documentElement->localName === 'document'
            && $document->documentElement->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $zip->close();
        $opened = false;
        $result['template_unchanged'] = hash_equals($before, hash_file('sha256', $path));
        $result['ready'] = $result['template_xml'] && $result['template_unchanged'];
        $result['message'] = $result['ready']
            ? 'ZipArchive successfully opened an approved DOCX read-only and DOM parsed its document XML. Template unchanged.'
            : 'Template XML or preservation check did not pass.';
    } catch (Throwable $exception) {
        // Do not expose paths, document contents or server exception messages.
        $result['message'] = 'The approved template could not be verified read-only. Check deployment and PHP prerequisites.';
    } finally {
        if ($opened && $zip instanceof ZipArchive) $zip->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }
    return $result;
}
