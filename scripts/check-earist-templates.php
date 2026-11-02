<?php
/** Read-only check that all approved EARIST DOCX templates are provisioned. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
$manifestPath = $projectRoot . '/config/earist-template-bindings.json';
$templateRoot = realpath($projectRoot . '/templates/earist/blank');

try {
    $manifestContents = file_get_contents($manifestPath);
    if (!is_string($manifestContents)) {
        throw new RuntimeException('Manifest unavailable.');
    }
    $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: The approved EARIST template manifest could not be read.\n");
    exit(2);
}

$documents = $manifest['documents'] ?? null;
if (!is_array($documents) || $documents === []) {
    fwrite(STDERR, "ERROR: The approved EARIST template manifest has no document entries.\n");
    exit(2);
}

$failed = false;
$seenFiles = [];
$verified = 0;
foreach ($documents as $documentType => $spec) {
    $filename = is_array($spec) ? ($spec['filename'] ?? null) : null;
    $expectedHash = is_array($spec) ? ($spec['sha256'] ?? null) : null;
    if (!is_string($filename) || $filename === '' || basename($filename) !== $filename
        || !is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/i', $expectedHash)) {
        fwrite(STDOUT, "INVALID  {$documentType}: invalid filename or SHA-256 in manifest\n");
        $failed = true;
        continue;
    }
    if (isset($seenFiles[$filename])) {
        fwrite(STDOUT, "INVALID  {$documentType}: duplicate manifest filename\n");
        $failed = true;
        continue;
    }
    $seenFiles[$filename] = true;

    $path = $templateRoot === false ? false : realpath($templateRoot . DIRECTORY_SEPARATOR . $filename);
    if ($templateRoot === false || $path === false || dirname($path) !== $templateRoot || !is_file($path)) {
        fwrite(STDOUT, "MISSING  {$documentType}: {$filename}\n");
        $failed = true;
        continue;
    }

    $actualHash = hash_file('sha256', $path);
    if (!is_string($actualHash) || !hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
        fwrite(STDOUT, "MISMATCH {$documentType}: {$filename}\n");
        $failed = true;
        continue;
    }

    fwrite(STDOUT, "OK      {$documentType}: {$filename}\n");
    $verified++;
}

fwrite(STDOUT, sprintf("EARIST template check: %d/%d approved templates verified.\n", $verified, count($documents)));
exit($failed ? 1 : 0);
