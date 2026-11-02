<?php
/** Internal Admin Settings diagnostic. No bootstrap, route, SQL or document writes. */
require_once __DIR__ . '/earist-document-files.php';

/** Create a diagnostic-only allocation under the protected generated root. */
function earistStorageDiagnosticCreateGenerated(?array &$allocation): void
{
    $root = earistDocumentStorageDirectories()['generated'];
    if (!is_writable($root)) throw new RuntimeException('Protected generated storage is not writable.');
    $id = bin2hex(random_bytes(32));
    $directory = $root . '/' . $id;
    if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to allocate protected generated storage.');
    $allocation = ['allocation_id' => $id, 'directory' => $directory, 'directory_identity' => @lstat($directory),
        'path' => $directory . '/diagnostic-' . $id . '.tmp', 'handle' => null, 'file_identity' => null];
    if (!$allocation['directory_identity'] || is_link($directory)
        || earistDocumentPathKey((string) realpath($directory)) !== earistDocumentPathKey($directory)) {
        throw new RuntimeException('Generated diagnostic allocation changed.');
    }
    $handle = fopen($allocation['path'], 'x+b');
    if ($handle === false) throw new RuntimeException('Unable to allocate protected generated file.');
    $allocation['handle'] = $handle;
    $allocation['file_identity'] = fstat($handle);
    if (!$allocation['file_identity'] || ($allocation['file_identity']['mode'] & 0170000) !== 0100000) {
        throw new RuntimeException('Generated diagnostic file is not a regular file.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($allocation['path'], 0600)) {
        throw new RuntimeException('Unable to restrict generated diagnostic permissions.');
    }
}

/** Release only an unchanged diagnostic allocation created by this request. */
function earistStorageDiagnosticReleaseGenerated(array $allocation): void
{
    $root = earistDocumentStorageDirectories()['generated'];
    $id = $allocation['allocation_id'] ?? '';
    $directory = $allocation['directory'] ?? '';
    $path = $allocation['path'] ?? '';
    $directoryIdentity = $allocation['directory_identity'] ?? null;
    $fileIdentity = $allocation['file_identity'] ?? null;
    if (!is_string($id) || !preg_match('/^[0-9a-f]{64}$/D', $id)) throw new RuntimeException('Invalid generated diagnostic allocation.');
    $expectedDirectory = $root . '/' . $id;
    $expectedPath = $expectedDirectory . '/diagnostic-' . $id . '.tmp';
    clearstatcache(true, $directory);
    clearstatcache(true, $path);
    $currentDirectory = @lstat($expectedDirectory);
    if (earistDocumentPathKey($directory) !== earistDocumentPathKey($expectedDirectory)
        || earistDocumentPathKey($path) !== earistDocumentPathKey($expectedPath)
        || !$currentDirectory || is_link($expectedDirectory) || is_link($expectedPath)
        || earistDocumentPathKey((string) realpath($expectedDirectory)) !== earistDocumentPathKey($expectedDirectory)
        || ($currentDirectory['mode'] & 0170000) !== 0040000
        || !$directoryIdentity || $currentDirectory['dev'] !== $directoryIdentity['dev']
        || $currentDirectory['ino'] !== $directoryIdentity['ino']) {
        throw new RuntimeException('Generated diagnostic allocation changed; retain it for review.');
    }
    $handle = $allocation['handle'] ?? null;
    if (is_resource($handle) && !fclose($handle)) throw new RuntimeException('Could not close the diagnostic file.');
    $currentFile = @lstat($expectedPath);
    if ($fileIdentity) {
        if (!$currentFile || is_link($expectedPath) || ($currentFile['mode'] & 0170000) !== 0100000
            || $currentFile['dev'] !== $fileIdentity['dev'] || $currentFile['ino'] !== $fileIdentity['ino']) {
            throw new RuntimeException('Generated diagnostic file changed; retain it for review.');
        }
        if (!unlink($expectedPath)) throw new RuntimeException('Unable to remove the diagnostic file.');
    } elseif (file_exists($expectedPath) || is_link($expectedPath)) {
        throw new RuntimeException('Unverified generated diagnostic file; retain it for review.');
    }
    if (!rmdir($expectedDirectory)) throw new RuntimeException('Generated diagnostic allocation is not empty; retain it for review.');
    clearstatcache(true, $expectedPath);
    if (file_exists($expectedPath) || is_link($expectedPath) || is_dir($expectedDirectory)) {
        throw new RuntimeException('Generated diagnostic removal could not be confirmed.');
    }
}

/** Only the protected, CSRF-validated Admin POST handler may call this helper. */
function earistStorageDiagnosticForAdmin(string $target = 'scratch'): array
{
    $user = getCurrentUser();
    if (!$user || ($user['role'] ?? '') !== 'admin' || ($user['status'] ?? '') !== 'active') {
        throw new DomainException('Only a current active Admin can run this private-storage test.', 403);
    }
    if (PHP_SAPI !== 'apache2handler') {
        throw new DomainException('Run this test from Admin Settings under the Apache PHP runtime.', 400);
    }
    if (!in_array($target, ['scratch', 'generated'], true)) throw new DomainException('Choose an approved private-storage test.', 400);
    $generated = $target === 'generated';
    $result = ['runtime' => PHP_SAPI, 'target' => $target, 'passed' => false, 'steps' => [
        'create' => ['label' => $generated ? 'Create generated allocation and disposable file' : 'Create disposable file', 'status' => 'Skipped', 'message' => 'Not reached.'],
        'write' => ['label' => 'Write and flush', 'status' => 'Skipped', 'message' => 'Not reached.'],
        'read' => ['label' => 'Read back and validate', 'status' => 'Skipped', 'message' => 'Not reached.'],
        'delete' => ['label' => $generated ? 'Delete own file and generated allocation' : 'Delete own disposable file', 'status' => 'Skipped', 'message' => 'No owned allocation to release.'],
    ]];
    $allocation = null;
    $identity = null;
    $readHandle = null;
    $step = 'create';
    set_error_handler(static function (int $severity): bool {
        if ((error_reporting() & $severity) === 0) return false;
        throw new RuntimeException('Private-storage diagnostic filesystem operation failed.');
    }, E_WARNING | E_NOTICE);
    try {
        // Uses only the configured protected root, exclusive random file creation
        // and a separate random directory. No request path or payload is accepted.
        if ($generated) earistStorageDiagnosticCreateGenerated($allocation);
        else $allocation = earistDocumentCreateScratch();
        $identity = fstat($allocation['handle']);
        if (!$identity || ($identity['mode'] & 0170000) !== 0100000) throw new RuntimeException('Scratch allocation is not a regular file.');
        $result['steps']['create'] = ['label' => $generated ? 'Create generated allocation and disposable file' : 'Create disposable file', 'status' => 'Passed',
            'message' => $generated
                ? 'Created one random allocation directory and exclusive file inside protected EARIST generated storage.'
                : 'Created one exclusive randomly named file inside protected EARIST scratch storage.'];
        $step = 'write';
        $payload = "RMS EARIST disposable storage diagnostic\n" . bin2hex(random_bytes(32)) . "\n";
        earistDocumentWriteAll($allocation['handle'], $payload);
        if (!fclose($allocation['handle'])) throw new RuntimeException('Could not close the written diagnostic file.');
        $allocation['handle'] = null;
        $result['steps']['write'] = ['label' => 'Write and flush', 'status' => 'Passed',
            'message' => 'Wrote and flushed ' . strlen($payload) . ' synthetic bytes, then closed the write handle.'];
        $step = 'read';
        clearstatcache(true, $allocation['path']);
        if (is_link($allocation['path']) || earistDocumentPathKey((string) realpath($allocation['path'])) !== earistDocumentPathKey($allocation['path'])) {
            throw new RuntimeException('Diagnostic file path changed.');
        }
        $readHandle = @fopen($allocation['path'], 'rb');
        if ($readHandle === false) throw new RuntimeException('Could not reopen the diagnostic file.');
        $readIdentity = fstat($readHandle);
        $read = stream_get_contents($readHandle, strlen($payload) + 1);
        if (!$readIdentity || $readIdentity['dev'] !== $identity['dev'] || $readIdentity['ino'] !== $identity['ino']
            || $readIdentity['size'] !== strlen($payload) || !is_string($read) || !hash_equals($payload, $read)) {
            throw new RuntimeException('Scratch read-back did not match.');
        }
        $result['steps']['read'] = ['label' => 'Read back and validate', 'status' => 'Passed',
            'message' => 'Reopened the same file and matched its complete contents and byte count.'];
    } catch (Throwable $exception) {
        // Fixed messages only: native errors, paths and payloads are never rendered.
        $result['steps'][$step]['status'] = 'Failed';
        $result['steps'][$step]['message'] = 'This step did not complete. Check private-storage permissions and server diagnostics.';
    } finally {
        try {
            if (is_resource($readHandle)) fclose($readHandle);
            if ($allocation !== null) {
                try {
                    if ($generated) {
                        earistStorageDiagnosticReleaseGenerated($allocation);
                        $result['steps']['delete'] = ['label' => 'Delete own file and generated allocation', 'status' => 'Passed',
                            'message' => 'Removed only this test file and its own empty generated allocation directory.'];
                    } else {
                        clearstatcache(true, $allocation['path']);
                        $current = @lstat($allocation['path']);
                        // Unlike libzip, this test never replaces its file inode. Refuse
                        // deletion if the owned file was replaced, redirected or removed.
                        if (!$identity || !$current || is_link($allocation['path']) || ($current['mode'] & 0170000) !== 0100000
                            || $current['dev'] !== $identity['dev'] || $current['ino'] !== $identity['ino']) {
                            throw new RuntimeException('Diagnostic identity changed; no deletion attempted.');
                        }
                        earistDocumentReleaseScratch($allocation);
                        clearstatcache(true, $allocation['path']);
                        if (file_exists($allocation['path']) || is_link($allocation['path']) || is_dir(dirname($allocation['path']))) {
                            throw new RuntimeException('Scratch removal could not be confirmed.');
                        }
                        $result['steps']['delete'] = ['label' => 'Delete own disposable file', 'status' => 'Passed',
                            'message' => 'Removed only this test file and its own empty allocation directory.'];
                    }
                } catch (Throwable $exception) {
                    if (is_resource($allocation['handle'] ?? null)) fclose($allocation['handle']);
                    $result['steps']['delete'] = ['label' => $generated ? 'Delete own file and generated allocation' : 'Delete own disposable file', 'status' => 'Failed',
                        'message' => 'Cleanup was not confirmed. No other files or directories were cleaned. Retain this allocation for review.'];
                    $result['retained_allocation'] = $allocation['allocation_id'] ?? 'unknown';
                }
            }
        } finally {
            restore_error_handler();
        }
    }
    $result['passed'] = count(array_filter($result['steps'], static fn(array $row): bool => $row['status'] === 'Passed')) === 4;
    return $result;
}
