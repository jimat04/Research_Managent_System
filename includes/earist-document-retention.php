<?php
/** Faculty-owned generated DOCX retention. History rows and manifests are never deleted. */
require_once __DIR__ . '/earist-document-generation.php';

function earistDocumentRetentionCandidates(mysqli $conn, int $ownerId, bool $forUpdate = false): array
{
    $sql = "SELECT gd.* FROM earist_generated_documents gd
        WHERE gd.owner_id = ? AND gd.generated_by = ? AND gd.document_type <> 'annex_d'
          AND gd.document_version < (
              SELECT MAX(newer.document_version) FROM earist_generated_documents newer
              WHERE newer.owner_id = gd.owner_id AND newer.generated_by = gd.generated_by
                AND newer.research_record_id = gd.research_record_id AND newer.document_type = gd.document_type
          )
          AND gd.file_retention_status IN ('available','pruning')
        ORDER BY gd.draft_id, gd.document_type, gd.document_version, gd.generated_document_id";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $ownerId, $ownerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function earistDocumentRetentionManifest(array $row): array
{
    $snapshot = earistDocumentHistorySnapshot($row);
    return $snapshot['file_manifest'];
}

/** Validate a stored set and report its remaining bytes; missing files are allowed only during retry. */
function earistDocumentRetentionMeasureSet(array $manifest, bool $allowMissing = false): int
{
    $primary = earistDocumentManifestFile($manifest, 'primary');
    $directories = earistDocumentStorageDirectories();
    if (!preg_match('#^generated/([0-9a-f]{64})/primary\.docx$#D', $primary['path'], $match)) {
        throw new DomainException('Invalid generated document set path.');
    }
    $directory = $directories['generated'] . '/' . $match[1];
    clearstatcache(true, $directory);
    if (!file_exists($directory)) {
        if ($allowMissing) return 0;
        throw new RuntimeException('The generated document set is missing.');
    }
    if (is_link($directory) || !is_dir($directory) || earistDocumentPathKey((string) realpath($directory)) !== earistDocumentPathKey($directory)) {
        throw new RuntimeException('The generated document set path is unsafe.');
    }
    $directoryStat = lstat($directory);
    if (!$directoryStat || ($directoryStat['mode'] & 0170000) !== 0040000) throw new RuntimeException('The generated document set is unavailable.');

    $files = [];
    foreach (array_keys($manifest['files']) as $selector) {
        $files[] = earistDocumentManifestFile($manifest, $selector);
    }
    $allowedNames = ['manifest.json'];
    foreach ($files as $file) $allowedNames[] = basename($file['path']);
    $entries = scandir($directory);
    if (!is_array($entries) || array_diff(array_values(array_diff($entries, ['.', '..'])), $allowedNames)) {
        throw new RuntimeException('The generated document set contains unexpected files.');
    }

    $bytes = 0;
    $manifestPath = $directory . '/manifest.json';
    clearstatcache(true, $manifestPath);
    if (!file_exists($manifestPath)) {
        if (!$allowMissing) throw new RuntimeException('The generated document manifest is missing.');
    } else {
        $manifestStat = lstat($manifestPath);
        if (is_link($manifestPath) || !$manifestStat || ($manifestStat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('The generated document manifest is unsafe.');
        }
        $onDisk = json_decode((string) file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($onDisk) || $onDisk != $manifest) throw new RuntimeException('The generated document manifest does not match RMS history.');
        $bytes += (int) $manifestStat['size'];
    }

    foreach ($files as $file) {
        $path = $directories['root'] . '/' . $file['path'];
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            if ($allowMissing) continue;
            throw new RuntimeException('A generated DOCX file is missing.');
        }
        $stat = lstat($path);
        if (is_link($path) || !$stat || ($stat['mode'] & 0170000) !== 0100000
            || (int) $stat['size'] !== (int) $file['size']) {
            throw new RuntimeException('A generated DOCX file is unsafe or has changed size.');
        }
        // Reuse the normal protected-file reader so cleanup verifies the immutable SHA-256 too.
        $handle = earistDocumentOpenFile($file);
        try {
            $verified = fstat($handle);
            if (!$verified || (int) $verified['size'] !== (int) $file['size']) {
                throw new RuntimeException('A generated DOCX file changed during inspection.');
            }
            $bytes += (int) $verified['size'];
        } finally {
            fclose($handle);
        }
    }
    $currentDirectoryStat = lstat($directory);
    if (!$currentDirectoryStat || $currentDirectoryStat['dev'] !== $directoryStat['dev'] || $currentDirectoryStat['ino'] !== $directoryStat['ino']) {
        throw new RuntimeException('The generated document set changed during inspection.');
    }
    return $bytes;
}

/** Delete only validated members of one immutable set. Repeating a pruning attempt is safe. */
function earistDocumentRetentionDeleteSet(array $manifest): void
{
    $primary = earistDocumentManifestFile($manifest, 'primary');
    $directories = earistDocumentStorageDirectories();
    if (!preg_match('#^generated/([0-9a-f]{64})/primary\.docx$#D', $primary['path'], $match)) {
        throw new DomainException('Invalid generated document set path.');
    }
    $directory = $directories['generated'] . '/' . $match[1];
    clearstatcache(true, $directory);
    if (!file_exists($directory)) return;
    earistDocumentRetentionMeasureSet($manifest, true);
    $directoryStat = lstat($directory);
    if (!$directoryStat) throw new RuntimeException('The generated document set is unavailable.');

    $files = [];
    foreach (array_keys($manifest['files']) as $selector) $files[] = earistDocumentManifestFile($manifest, $selector);
    $paths = array_map(static fn(array $file): string => $directories['root'] . '/' . $file['path'], $files);
    $paths[] = $directory . '/manifest.json';
    foreach ($paths as $path) {
        clearstatcache(true, $path);
        if (!file_exists($path)) continue;
        $currentDirectoryStat = lstat($directory);
        $stat = lstat($path);
        if (is_link($directory) || is_link($path) || !$currentDirectoryStat || !$stat
            || $currentDirectoryStat['dev'] !== $directoryStat['dev'] || $currentDirectoryStat['ino'] !== $directoryStat['ino']
            || ($stat['mode'] & 0170000) !== 0100000 || !unlink($path)) {
            throw new RuntimeException('A generated document file could not be safely removed.');
        }
    }
    clearstatcache(true, $directory);
    if (is_dir($directory) && !rmdir($directory)) throw new RuntimeException('The generated document set still contains files.');
}

function earistDocumentRetentionPreview(mysqli $conn): array
{
    $actor = earistDocumentCurrentActor('annex_a');
    $rows = earistDocumentRetentionCandidates($conn, (int) $actor['user_id']);
    $bytes = 0;
    $pending = 0;
    $blocked = 0;
    foreach ($rows as $row) {
        $isPending = ($row['file_retention_status'] ?? '') === 'pruning';
        if ($isPending) $pending++;
        try {
            $bytes += earistDocumentRetentionMeasureSet(earistDocumentRetentionManifest($row), $isPending);
        } catch (Throwable $exception) {
            $blocked++;
        }
    }
    return ['eligible_sets' => count($rows), 'pending_sets' => $pending, 'blocked_sets' => $blocked, 'bytes' => $bytes];
}

/** Server-side re-evaluation plus a retryable per-set delete; the latest set is never selected. */
function earistDocumentRetentionCleanup(mysqli $conn): array
{
    $actor = earistDocumentCurrentActor('annex_a');
    $ownerId = (int) $actor['user_id'];
    $conn->begin_transaction();
    $workRows = [];
    $blocked = 0;
    try {
        // Generation/import version allocation takes this same draft mutex.
        $drafts = $conn->prepare("SELECT draft_id FROM earist_document_drafts WHERE owner_id = ? AND document_type <> 'annex_d' ORDER BY draft_id FOR UPDATE");
        $drafts->bind_param('i', $ownerId);
        $drafts->execute();
        $drafts->get_result()->free();
        $drafts->close();

        $rows = earistDocumentRetentionCandidates($conn, $ownerId, true);
        foreach ($rows as $row) {
            try {
                $manifest = earistDocumentRetentionManifest($row);
                if (($row['file_retention_status'] ?? '') === 'available') {
                    $bytes = earistDocumentRetentionMeasureSet($manifest, false);
                    if ($bytes < 1) throw new RuntimeException('The generated document set is empty.');
                    $generatedId = (int) $row['generated_document_id'];
                    $update = $conn->prepare("UPDATE earist_generated_documents SET file_retention_status = 'pruning', file_retention_started_at = UTC_TIMESTAMP(), file_retention_actor_id = ?, file_retention_bytes = ? WHERE generated_document_id = ? AND owner_id = ? AND generated_by = ? AND file_retention_status = 'available'");
                    $update->bind_param('iiiii', $ownerId, $bytes, $generatedId, $ownerId, $ownerId);
                    $update->execute();
                    $update->close();
                    $row['file_retention_status'] = 'pruning';
                    $row['file_retention_bytes'] = $bytes;
                }
                $workRows[] = $row;
            } catch (Throwable $exception) {
                $blocked++;
                error_log('EARIST document retention inspection failed: document_id=' . (int) $row['generated_document_id'] . ', exception=' . get_class($exception));
            }
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    $completed = 0;
    $pending = 0;
    $bytes = 0;
    foreach ($workRows as $row) {
        $documentId = (int) $row['generated_document_id'];
        try {
            earistDocumentRetentionDeleteSet(earistDocumentRetentionManifest($row));
            $update = $conn->prepare("UPDATE earist_generated_documents SET file_retention_status = 'pruned', file_retention_completed_at = COALESCE(file_retention_completed_at, UTC_TIMESTAMP()) WHERE generated_document_id = ? AND owner_id = ? AND file_retention_status = 'pruning'");
            $update->bind_param('ii', $documentId, $ownerId);
            $update->execute();
            $updated = $update->affected_rows;
            $update->close();
            if ($updated || ($row['file_retention_status'] ?? '') === 'pruning') {
                $completed++;
                $bytes += (int) $row['file_retention_bytes'];
            }
        } catch (Throwable $exception) {
            $pending++;
            error_log('EARIST document retention retry required: document_id=' . $documentId . ', exception=' . get_class($exception));
        }
    }
    return ['selected_sets' => count($rows), 'completed_sets' => $completed, 'pending_sets' => $pending + $blocked, 'bytes' => $bytes];
}
