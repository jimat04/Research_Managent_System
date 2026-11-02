<?php
/** Internal file primitives. No request handler, authentication, DB or history writes.
 * The caller must authorize before storing/opening any document. A file is not a
 * successful generated version until its complete manifest is committed to history.
 */
require_once __DIR__ . '/earist-docx-xml.php';

function earistDocumentStorageConfig(): array
{
    static $config;
    if (is_array($config ?? null)) {
        return $config;
    }

    $config = require __DIR__ . '/../config/earist-document-storage.php';
    if (function_exists('rmsSetting')) {
        $config['live_generation_enabled'] = rmsSetting('earist_live_generation_enabled') === '1';
    }
    return $config;
}

function earistDocumentPathKey(string $path): string
{
    $value = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
}

/** Reject links/junction redirects, missing guards and unexpected deployment roots. */
function earistDocumentStorageDirectories(): array
{
    $config = earistDocumentStorageConfig();
    $project = realpath(__DIR__ . '/..');
    $expected = $project . '/storage/earist';
    $root = realpath($config['root']);
    if (!$project || !$root || earistDocumentPathKey($config['root']) !== earistDocumentPathKey($expected)
        || earistDocumentPathKey($root) !== earistDocumentPathKey($expected)) {
        throw new RuntimeException('Protected document storage is unavailable.');
    }
    $directories = ['root' => $root];
    foreach (['root', 'scratch', 'generated'] as $kind) {
        $path = $kind === 'root' ? $root : $root . '/' . $kind;
        $resolved = realpath($path);
        $guard = $path . '/.htaccess';
        if (!$resolved || !is_dir($resolved) || is_link($path)
            || earistDocumentPathKey($resolved) !== earistDocumentPathKey($path)
            || is_link($guard) || !is_file($guard)
            || trim((string) file_get_contents($guard)) !== 'Require all denied') {
            throw new RuntimeException('Protected document storage guards are unavailable.');
        }
        $directories[$kind] = $resolved;
    }
    return $directories;
}

/** An exclusive random file, with its handle held until this call releases it. */
function earistDocumentCreateScratch(?string $directory = null): array
{
    $directories = earistDocumentStorageDirectories();
    $root = $directories['scratch'];
    if ($directory !== null && (!is_dir($directory)
        || earistDocumentPathKey($directory) !== earistDocumentPathKey($root))) {
        throw new DomainException('Use the configured protected scratch directory.');
    }
    if (!is_writable($root)) throw new RuntimeException('Protected scratch storage is not writable.');
    $allocationId = bin2hex(random_bytes(32));
    $directory = $root . '/' . $allocationId;
    if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to allocate protected scratch storage.');
    $directoryIdentity = lstat($directory);
    if (is_link($directory) || earistDocumentPathKey((string) realpath($directory)) !== earistDocumentPathKey($directory)) throw new RuntimeException('Scratch directory allocation changed.');
    $path = $directory . '/scratch-' . $allocationId . '.tmp';
    $handle = fopen($path, 'x+b');
    if ($handle === false) throw new RuntimeException('Unable to allocate protected scratch storage.');
    // chmod is meaningful on POSIX. Windows privacy requires the documented ACL check.
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
        fclose($handle);
        // Leave this exclusive allocation for recovery; never broaden its permissions.
        throw new RuntimeException('Unable to restrict scratch permissions.');
    }
    return ['path' => $path, 'handle' => $handle, 'allocation_id' => $allocationId, 'directory_identity' => $directoryIdentity];
}

/** Remove only this allocation; never enumerate, recursively clean or follow a link. */
function earistDocumentReleaseScratch(array $scratch): void
{
    $handle = $scratch['handle'] ?? null;
    $path = $scratch['path'] ?? '';
    $id = $scratch['allocation_id'] ?? '';
    $identity = $scratch['directory_identity'] ?? [];
    try {
        $root = earistDocumentStorageDirectories()['scratch'];
        if (!is_string($id) || !preg_match('/^[0-9a-f]{64}$/D', $id)) throw new RuntimeException('Invalid scratch allocation.');
        $directory = $root . '/' . $id;
        $expected = $directory . '/scratch-' . $id . '.tmp';
        clearstatcache(true, $path);
        $current = lstat($directory);
        // libzip may atomically replace the file inode. Own its exclusive directory
        // instead of treating that expected replacement as a foreign allocation.
        if (earistDocumentPathKey($path) !== earistDocumentPathKey($expected)
            || !$current || is_link($directory) || is_link($path)
            || earistDocumentPathKey((string) realpath($directory)) !== earistDocumentPathKey($directory)
            || ($current['mode'] & 0170000) !== 0040000
            || $current['dev'] !== ($identity['dev'] ?? null) || $current['ino'] !== ($identity['ino'] ?? null)
            || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Scratch allocation changed; retain it for review.');
        }
    } finally {
        if (is_resource($handle)) fclose($handle);
    }
    if (is_file($path) && !unlink($path)) throw new RuntimeException('Unable to release this scratch allocation.');
    if (!rmdir($directory)) throw new RuntimeException('Scratch directory is not empty; retain it for review.');
}

function earistDocumentWriteAll($handle, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($handle, substr($bytes, $offset, 1024 * 1024));
        if ($written === false || $written === 0) throw new RuntimeException('Document write did not complete.');
        $offset += $written;
    }
    if (!fflush($handle)) throw new RuntimeException('Document write could not be flushed.');
    if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Document write could not be synchronized.');
}

/** ZIP member names are never extracted. Reject ambiguous, drive and traversal paths. */
function earistDocumentPackageName(string $name): bool
{
    if ($name === '' || strlen($name) > 255 || str_contains($name, '\\') || str_contains($name, ':')
        || str_contains($name, '%') || preg_match('/[\x00-\x20\x7f?#]/', $name) || $name[0] === '/') return false;
    foreach (explode('/', $name) as $part) if ($part === '' || $part === '.' || $part === '..' || rtrim($part, '. ') !== $part) return false;
    return true;
}

/** OPC targets may legitimately use ../; normalize within the ZIP, never the filesystem. */
function earistDocumentRelationshipTarget(string $source, string $target): string
{
    if ($target === '' || str_contains($target, '\\') || str_contains($target, ':') || str_contains($target, '?')
        || preg_match('/[\x00-\x20\x7f]/', $target) || str_starts_with($target, '//')) {
        throw new DomainException('Invalid internal document relationship.');
    }
    $target = explode('#', $target, 2)[0];
    // OPC relationship targets are URI-escaped; decode once and reject double encoding.
    $target = rawurldecode($target);
    if ($target === '' || str_contains($target, '%') || str_contains($target, '\\') || str_contains($target, ':')
        || preg_match('/[\x00-\x20\x7f?#]/', $target)) throw new DomainException('Invalid internal document relationship.');
    $segments = str_starts_with($target, '/') ? [] : ($source === '' ? [] : explode('/', dirname($source)));
    if ($segments === ['.']) $segments = [];
    foreach (explode('/', ltrim($target, '/')) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            if (!$segments) throw new DomainException('Document relationship escapes its package.');
            array_pop($segments);
        } else $segments[] = $segment;
    }
    $name = implode('/', $segments);
    if (!earistDocumentPackageName($name)) throw new DomainException('Invalid internal document relationship.');
    return $name;
}

/** Validate one already-open package: all CRCs, XML, required parts and OPC links. */
function earistDocumentInspectPackage(string $path): array
{
    $config = earistDocumentStorageConfig();
    $zip = new ZipArchive();
    if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new DomainException('DOCX package is unreadable or corrupt.');
    try {
        if ($zip->numFiles < 3 || $zip->numFiles > $config['max_package_entries']) throw new DomainException('DOCX package entry limit exceeded.');
        $parts = $xml = $folded = [];
        $uncompressed = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat['name'] ?? '';
            if (!earistDocumentPackageName($name) || isset($folded[strtolower($name)])) throw new DomainException('Unsafe or duplicate DOCX package entry.');
            $folded[strtolower($name)] = true;
            if (!is_int($stat['size'] ?? null) || $stat['size'] < 0
                || $stat['size'] > $config['max_uncompressed_bytes'] - $uncompressed
                || ($stat['encryption_method'] ?? 0) !== 0) throw new DomainException('Unsupported or oversized DOCX package entry.');
            $uncompressed += $stat['size'];
            $bytes = $zip->getFromIndex($index);
            if (!is_string($bytes) || strlen($bytes) !== $stat['size']
                || sprintf('%08x', crc32($bytes)) !== sprintf('%08x', $stat['crc'])) throw new DomainException('DOCX package integrity check failed.');
            $parts[$name] = true;
            if (str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels')) $xml[$name] = earistXmlParse($bytes);
        }
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
            if (!isset($xml[$required])) throw new DomainException('DOCX package is missing required content.');
        }
        $word = $xml['word/document.xml']->documentElement;
        if (!$word || $word->localName !== 'document' || $word->namespaceURI !== EARIST_WORD_NS) throw new DomainException('Invalid DOCX document root.');
        $wordXpath = new DOMXPath($xml['word/document.xml']);
        $wordXpath->registerNamespace('w', EARIST_WORD_NS);
        if ($wordXpath->query('/w:document/w:body')->length !== 1) throw new DomainException('DOCX document body is missing or ambiguous.');
        $content = $xml['[Content_Types].xml']->documentElement;
        $contentNs = 'http://schemas.openxmlformats.org/package/2006/content-types';
        if (!$content || $content->localName !== 'Types' || $content->namespaceURI !== $contentNs) throw new DomainException('Invalid DOCX content types.');
        $documentType = false;
        $overrides = [];
        $defaults = [];
        foreach ($content->childNodes as $entry) {
            if (!$entry instanceof DOMElement) continue;
            if ($entry->namespaceURI !== $contentNs) throw new DomainException('Unsupported DOCX content type declaration.');
            $type = $entry->getAttribute('ContentType');
            if ($entry->localName === 'Override') {
                $part = $entry->getAttribute('PartName');
                if (!str_starts_with($part, '/') || !isset($parts[substr($part, 1)]) || isset($overrides[$part]) || $type === '') throw new DomainException('Invalid DOCX content type part.');
                $overrides[$part] = $type;
                if ($part === '/word/document.xml' && $type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml') $documentType = true;
            } elseif ($entry->localName === 'Default') {
                $extension = strtolower($entry->getAttribute('Extension'));
                if (!preg_match('/^[a-z0-9]+$/D', $extension) || isset($defaults[$extension]) || $type === '') throw new DomainException('Invalid DOCX default content type.');
                $defaults[$extension] = $type;
            } else throw new DomainException('Unsupported DOCX content type declaration.');
        }
        if (!$documentType) throw new DomainException('Package is not a standard DOCX document.');
        foreach (array_keys($parts) as $name) {
            if ($name === '[Content_Types].xml') continue;
            if (!isset($overrides['/' . $name]) && !isset($defaults[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) throw new DomainException('DOCX part has no content type.');
        }
        $relationships = 0;
        $external = 0;
        $officeDocument = false;
        $relationshipIds = [];
        foreach ($xml as $name => $dom) {
            if (!str_ends_with($name, '.rels')) continue;
            if ($name === '_rels/.rels') $source = '';
            elseif (preg_match('#^(.*?/)?_rels/([^/]+)\.rels$#D', $name, $match)) {
                $source = ($match[1] ?? '') . $match[2];
                if (!isset($parts[$source])) throw new DomainException('DOCX relationship source is missing.');
            } else throw new DomainException('Invalid DOCX relationship part.');
            $root = $dom->documentElement;
            $ns = 'http://schemas.openxmlformats.org/package/2006/relationships';
            if (!$root || $root->localName !== 'Relationships' || $root->namespaceURI !== $ns) throw new DomainException('Invalid DOCX relationships.');
            $ids = [];
            foreach ($root->childNodes as $relationship) {
                if (!$relationship instanceof DOMElement) continue;
                if ($relationship->namespaceURI !== $ns || $relationship->localName !== 'Relationship') throw new DomainException('Invalid DOCX relationship element.');
                $id = $relationship->getAttribute('Id');
                $type = $relationship->getAttribute('Type');
                $target = $relationship->getAttribute('Target');
                $mode = $relationship->getAttribute('TargetMode');
                if ($id === '' || isset($ids[$id]) || $type === '' || $target === '' || !in_array($mode, ['', 'Internal', 'External'], true)) throw new DomainException('Invalid or duplicate DOCX relationship.');
                $ids[$id] = true;
                $relationships++;
                if ($mode === 'External') { $external++; continue; } // Preserve approved links; never fetch them.
                $resolved = earistDocumentRelationshipTarget($source, $target);
                if (!isset($parts[$resolved])) throw new DomainException('DOCX relationship target is missing.');
                if ($source === '' && $type === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument') {
                    if ($officeDocument || $resolved !== 'word/document.xml') throw new DomainException('Ambiguous DOCX main document.');
                    $officeDocument = true;
                }
            }
            $relationshipIds[$source] = $ids;
        }
        if (!$officeDocument) throw new DomainException('DOCX main document relationship is missing.');
        foreach ($xml as $name => $dom) {
            if (str_ends_with($name, '.rels')) continue;
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $xpath->registerNamespace('office', 'urn:schemas-microsoft-com:office:office');
            foreach ($xpath->query('//@rel:* | //@office:relid') as $reference) {
                if (!isset($relationshipIds[$name][$reference->value])) throw new DomainException('DOCX content references a missing relationship.');
            }
        }
        return ['entries' => count($parts), 'xml_parts' => count($xml), 'relationships' => $relationships,
            'external_relationships_not_fetched' => $external, 'uncompressed_bytes' => $uncompressed];
    } finally {
        $zip->close();
    }
}

function earistDocumentValidateDocx(string $bytes): array
{
    $config = earistDocumentStorageConfig();
    if ($bytes === '' || strlen($bytes) > $config['max_docx_bytes']) throw new DomainException('DOCX output is empty or exceeds the storage limit.');
    $scratch = earistDocumentCreateScratch();
    try {
        earistDocumentWriteAll($scratch['handle'], $bytes);
        return earistDocumentInspectPackage($scratch['path']);
    } finally {
        earistDocumentReleaseScratch($scratch);
    }
}

/** Accept only server manifest paths; no client filenames, URLs or absolute paths. */
function earistDocumentFileReference(array $file): void
{
    if (!is_string($file['path'] ?? null)
        || !preg_match('#^generated/[0-9a-f]{64}/(?:primary|sdg-continuation)\.docx$#D', $file['path'])
        || !is_string($file['sha256'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $file['sha256'])
        || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > earistDocumentStorageConfig()['max_docx_bytes']) {
        throw new DomainException('Invalid stored document reference.');
    }
}

/** Validate every reference against one immutable set before selecting a download. */
function earistDocumentManifestFile(array $manifest, string $selector): array
{
    $type = $manifest['document_type'] ?? '';
    $id = $manifest['set_id'] ?? '';
    $files = $manifest['files'] ?? null;
    if (($manifest['version'] ?? null) !== earistDocumentStorageConfig()['file_manifest_version']
        || !is_string($id) || !preg_match('/^[0-9a-f]{64}$/D', $id)
        || !in_array($type, ['annex_a', 'annex_b', 'annex_c', 'annex_d', 'annex_e', 'annex_f', 'qsf_rds_019'], true)
        || !is_array($files) || !isset($files['primary']) || array_diff(array_keys($files), ['primary', 'sdg_continuation'])
        || ($type !== 'qsf_rds_019' && isset($files['sdg_continuation']))) throw new DomainException('Invalid stored document manifest.');
    foreach ($files as $key => $file) {
        if (!is_array($file)) throw new DomainException('Invalid stored document manifest.');
        earistDocumentFileReference($file);
        $base = $key === 'primary' ? 'primary.docx' : 'sdg-continuation.docx';
        $filename = $key === 'primary' ? str_replace('_', '-', $type) . '.docx' : 'qsf-rds-019-sdg-continuation.docx';
        if ($file['path'] !== 'generated/' . $id . '/' . $base || ($file['filename'] ?? null) !== $filename) throw new DomainException('Stored document files belong to different versions.');
    }
    if (!in_array($selector, ['primary', 'sdg_continuation'], true) || !isset($files[$selector])) throw new DomainException('Requested document file is unavailable.', 404);
    return $files[$selector];
}

/** Authorization belongs to the live service, which must run before this helper. */
function earistDocumentOpenFile(array $file)
{
    earistDocumentFileReference($file);
    $root = earistDocumentStorageDirectories()['root'];
    $expected = $root . '/' . $file['path'];
    clearstatcache(true, $expected);
    $path = realpath($expected);
    if (!$path || is_link($expected) || is_link(dirname($expected))
        || earistDocumentPathKey($path) !== earistDocumentPathKey($expected)) throw new RuntimeException('Stored document is unavailable.');
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Stored document is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Stored document cannot be locked for reading.');
        $stat = fstat($handle);
        $disk = lstat($path);
        if (!$stat || !$disk || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $file['size']
            || $stat['dev'] !== $disk['dev'] || $stat['ino'] !== $disk['ino']) throw new RuntimeException('Stored document integrity check failed.');
        $hash = hash_init('sha256');
        if (hash_update_stream($hash, $handle) !== $file['size'] || !hash_equals($file['sha256'], hash_final($hash))
            || !rewind($handle)) throw new RuntimeException('Stored document integrity check failed.');
        return $handle; // Caller streams this same handle, then closes it.
    } catch (Throwable $exception) {
        fclose($handle);
        throw $exception;
    }
}

/** Store the entire renderer result, with one manifest for the primary/companion set.
 * Failed allocations are retained privately; there is deliberately no deletion API.
 */
function earistDocumentStoreOutput(array $output): array
{
    $types = ['annex_a', 'annex_b', 'annex_c', 'annex_d', 'annex_e', 'annex_f', 'qsf_rds_019'];
    $type = $output['document_type'] ?? '';
    if (!in_array($type, $types, true) || !is_string($output['bytes'] ?? null)
        || !is_string($output['sha256'] ?? null) || !hash_equals(hash('sha256', $output['bytes']), $output['sha256'])
        || ($output['size'] ?? null) !== strlen($output['bytes']) || !is_array($output['supplements'] ?? null)
        || !array_is_list($output['supplements']) || count($output['supplements']) > ($type === 'qsf_rds_019' ? 1 : 0)) {
        throw new DomainException('Incomplete or inconsistent renderer output.');
    }
    $items = ['primary' => ['bytes' => $output['bytes'], 'basename' => 'primary.docx', 'filename' => str_replace('_', '-', $type) . '.docx']];
    if ($output['supplements']) {
        $supplement = $output['supplements'][0];
        if (!is_array($supplement) || !is_string($supplement['bytes'] ?? null)
            || ($supplement['filename'] ?? '') !== 'qsf-rds-019-sdg-continuation.docx') throw new DomainException('Invalid QSF companion.');
        $items['sdg_continuation'] = ['bytes' => $supplement['bytes'], 'basename' => 'sdg-continuation.docx', 'filename' => $supplement['filename']];
    }
    foreach ($items as $item) earistDocumentValidateDocx($item['bytes']);
    $directories = earistDocumentStorageDirectories();
    $setId = bin2hex(random_bytes(32));
    $directory = $directories['generated'] . '/' . $setId;
    if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to allocate immutable document storage.');
    if (is_link($directory) || earistDocumentPathKey((string) realpath($directory)) !== earistDocumentPathKey($directory)) throw new RuntimeException('Document storage allocation changed.');
    $manifest = ['version' => earistDocumentStorageConfig()['file_manifest_version'], 'set_id' => $setId, 'document_type' => $type, 'files' => []];
    foreach ($items as $key => $item) {
        $path = $directory . '/' . $item['basename'];
        $handle = fopen($path, 'x+b');
        if ($handle === false) throw new RuntimeException('Unable to allocate immutable document file.');
        try {
            if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) throw new RuntimeException('Unable to restrict document permissions.');
            earistDocumentWriteAll($handle, $item['bytes']);
        } finally {
            fclose($handle);
        }
        $reference = ['path' => 'generated/' . $setId . '/' . $item['basename'], 'sha256' => hash('sha256', $item['bytes']),
            'size' => strlen($item['bytes']), 'filename' => $item['filename']];
        $verified = earistDocumentOpenFile($reference);
        fclose($verified);
        earistDocumentInspectPackage($path);
        $manifest['files'][$key] = $reference;
    }
    $handle = fopen($directory . '/manifest.json', 'xb');
    if ($handle === false) throw new RuntimeException('Unable to allocate the document manifest.');
    try {
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($directory . '/manifest.json', 0600)) throw new RuntimeException('Unable to restrict manifest permissions.');
        earistDocumentWriteAll($handle, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } finally {
        fclose($handle);
    }
    return $manifest;
}
