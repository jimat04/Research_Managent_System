<?php
/** Operator-only coordinated checkpoint. No application bootstrap, SQL writes or restore.
 * Run only in an agreed quiet window, after verifying inherited Windows ACLs on
 * the new destination. Retains failed backups; deletes only its credential file.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/earist-document-generation.php';
require_once __DIR__ . '/../includes/earist-document-schema.php';

function checkpointWrite(string $path, string $bytes): void
{
    $h = fopen($path, 'xb');
    if (!$h) throw new RuntimeException('Exclusive backup write failed.');
    try { earistDocumentWriteAll($h, $bytes); } finally { fclose($h); }
    if (hash_file('sha256', $path) !== hash('sha256', $bytes)) throw new RuntimeException('Backup read-back failed.');
}

function checkpointJson(string $path, array $data): void
{
    checkpointWrite($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function checkpointDatabase(mysqli $db): array
{
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $tables = $db->query("SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
        $inventory = [];
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name) || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('Checkpoint requires reviewed InnoDB base tables only.');
            }
            $result = $db->query('SELECT * FROM `' . $name . '`');
            $hashes = [];
            while ($row = $result->fetch_assoc()) $hashes[] = hash('sha256', serialize($row));
            sort($hashes, SORT_STRING);
            $definition = $db->query('SHOW CREATE TABLE `' . $name . '`')->fetch_row()[1];
            $inventory[$name] = ['rows' => count($hashes), 'data_sha256' => hash('sha256', implode('', $hashes)),
                'definition_sha256' => hash('sha256', $definition)];
        }
        $history = $db->query('SELECT * FROM earist_generated_documents ORDER BY generated_document_id')->fetch_all(MYSQLI_ASSOC);
        $ledger = $db->query('SELECT * FROM schema_migrations ORDER BY filename')->fetch_all(MYSQLI_ASSOC);
        $schema = earistDocumentSchemaStatus($db);
        if (empty($schema['valid'])) throw new RuntimeException('Installed EARIST schema validation failed.');
        $applied = false;
        foreach ($ledger as $entry) if ($entry['filename'] === '052_earist_document_storage.sql' && $entry['status'] === 'applied') $applied = true;
        if (!$applied) throw new RuntimeException('Migration 052 is not recorded applied.');
        // SQL collation ordering can differ from PHP byte ordering (notably '_').
        // Compare table membership in one explicit order, not database collation order.
        ksort($inventory, SORT_STRING);
        return ['tables' => $inventory, 'history' => $history, 'ledger' => $ledger];
    } finally { $db->rollback(); }
}

function checkpointFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isFile()) throw new RuntimeException('Unexpected backup source object.');
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        $files[$relative] = ['size' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
    }
    ksort($files);
    return $files;
}

$credentialPath = null;
try {
    $project = realpath(__DIR__ . '/..');
    $target = realpath($argv[1] ?? '');
    if (count($argv) !== 3 || $argv[2] !== '--quiet-window-confirmed' || !$target
        || !preg_match('#^' . preg_quote(str_replace('\\', '/', $project), '#') . '/storage/earist/recovery/b2-[a-z0-9-]+$#iD', str_replace('\\', '/', $target))) {
        throw new RuntimeException('Use a preflighted private B2 directory and explicit quiet-window argument.');
    }
    if (earistDocumentStorageConfig()['live_generation_enabled'] !== false) throw new RuntimeException('Disable generation before backup.');
    $dirs = earistDocumentStorageDirectories();
    Dotenv\Dotenv::createImmutable($project)->safeLoad();
    $env = static fn(string $key, string $fallback): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $fallback);
    $host = $env('DB_HOST', 'localhost');
    if ($env('DB_NAME', 'rms_db') !== 'rms_db' || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Only local rms_db is authorized.');
    $db = new mysqli($host, $env('DB_USER', 'root'), $env('DB_PASS', ''), 'rms_db');
    $db->set_charset('utf8mb4');
    $before = checkpointDatabase($db);
    $sourceFiles = checkpointFiles($dirs['generated']);
    $quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $s) . '"';
    $credentialPath = $target . '/client-' . bin2hex(random_bytes(12)) . '.cnf';
    checkpointWrite($credentialPath, "[client]\nhost=" . $quote($host) . "\nuser=" . $quote($env('DB_USER', 'root')) . "\npassword=" . $quote($env('DB_PASS', '')) . "\n");
    $dumpPath = $target . '/rms_db.sql';
    if (file_exists($dumpPath)) throw new RuntimeException('Never overwrite an earlier dump.');
    $command = ['C:/xampp/mysql/bin/mysqldump.exe', '--defaults-extra-file=' . $credentialPath,
        '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob', '--skip-extended-insert',
        '--complete-insert', '--default-character-set=utf8mb4', '--result-file=' . $dumpPath, 'rms_db'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $target . '/dump.stdout.log', 'x'],
        2 => ['file', $target . '/dump.stderr.log', 'x']], $pipes, $project, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Dump process unavailable.');
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    if (!unlink($credentialPath)) throw new RuntimeException('Private credential cleanup failed.');
    $credentialPath = null;
    if ($exitCode !== 0 || !is_file($dumpPath) || filesize($dumpPath) < 1) throw new RuntimeException('Dump failed; inspect private diagnostics.');
    $sql = file_get_contents($dumpPath);
    if (!str_contains($sql, '-- Dump completed on')) throw new RuntimeException('Dump completion marker missing.');
    preg_match_all('/^CREATE TABLE `([^`]+)`/m', $sql, $definitions);
    $names = $definitions[1]; sort($names, SORT_STRING);
    if ($names !== array_keys($before['tables'])) throw new RuntimeException('Dump table inventory mismatch.');
    foreach ($before['tables'] as $name => $table) {
        $count = preg_match_all('/^INSERT INTO `' . preg_quote($name, '/') . '` /m', $sql);
        if ($count !== $table['rows']) throw new RuntimeException('Dump row inventory mismatch.');
    }
    if (!str_contains($sql, '052_earist_document_storage.sql')) throw new RuntimeException('Dump lacks current migration entry.');
    foreach ($sourceFiles as $relative => $reference) {
        $destination = $target . '/generated/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) throw new RuntimeException('Backup subdirectory creation failed.');
        checkpointWrite($destination, file_get_contents($dirs['generated'] . '/' . $relative));
        if (filesize($destination) !== $reference['size'] || hash_file('sha256', $destination) !== $reference['sha256']) throw new RuntimeException('Generated file copy mismatch.');
    }
    $sets = [];
    foreach ($sourceFiles as $relative => $reference) {
        if (!str_ends_with($relative, '/manifest.json')) continue;
        $manifest = json_decode(file_get_contents($target . '/generated/' . $relative), true, 512, JSON_THROW_ON_ERROR);
        earistDocumentManifestFile($manifest, 'primary');
        if ($relative !== $manifest['set_id'] . '/manifest.json') throw new RuntimeException('Manifest directory mismatch.');
        foreach ($manifest['files'] as $file) {
            $copy = $target . '/' . $file['path'];
            if (!is_file($copy) || filesize($copy) !== $file['size'] || hash_file('sha256', $copy) !== $file['sha256']) throw new RuntimeException('Manifest file integrity mismatch.');
            earistDocumentInspectPackage($copy);
        }
        $sets[$manifest['set_id']] = $manifest;
    }
    $referenced = [];
    foreach ($before['history'] as $row) {
        $snapshot = earistDocumentHistorySnapshot($row);
        $manifest = $snapshot['file_manifest'];
        if (($sets[$manifest['set_id']] ?? null) !== $manifest) throw new RuntimeException('SQL and file manifest disagree.');
        $referenced[] = $manifest['set_id'];
    }
    $metadata = ['config/earist-document-storage.php', 'config/earist-documents.php', 'config/earist-template-bindings.json',
        'includes/earist-docx-renderer.php', 'includes/earist-docx-xml.php', 'includes/earist-annex-d.php',
        'includes/earist-document-mapping.php', 'includes/earist-document-files.php', 'includes/earist-document-service.php',
        'includes/earist-document-generation.php', 'includes/earist-annex-d-service.php',
        'database/migrations/052_earist_document_storage.sql', 'docs/EARIST_DOCUMENT_OPERATIONS.md'];
    foreach (glob($project . '/templates/earist/blank/*.docx') as $template) $metadata[] = substr(str_replace('\\', '/', $template), strlen(str_replace('\\', '/', $project)) + 1);
    foreach ($metadata as $relative) {
        $destination = $target . '/metadata/' . $relative;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
        checkpointWrite($destination, file_get_contents($project . '/' . $relative));
    }
    $after = checkpointDatabase($db);
    $db->close();
    if ($before !== $after || $sourceFiles !== checkpointFiles($dirs['generated'])) throw new RuntimeException('Source changed during backup; keep generation disabled.');
    checkpointJson($target . '/database-inventory.json', $before);
    checkpointJson($target . '/generated-inventory.json', $sourceFiles);
    $inventory = checkpointFiles($target);
    checkpointJson($target . '/checksums.json', $inventory);
    $summary = ['status' => 'integrity_verified_restore_not_rehearsed', 'created_at' => date(DATE_ATOM),
        'database' => 'rms_db', 'tables' => count($before['tables']), 'history_rows' => count($before['history']),
        'draft_rows' => $before['tables']['earist_document_drafts']['rows'], 'generated_files' => count($sourceFiles),
        'manifest_sets' => count($sets), 'unreferenced_sets' => count(array_diff(array_keys($sets), $referenced)),
        'sql_bytes' => filesize($dumpPath), 'sql_sha256' => hash_file('sha256', $dumpPath),
        'checksums_sha256' => hash_file('sha256', $target . '/checksums.json'), 'live_generation_enabled' => false];
    checkpointJson($target . '/verification.json', $summary);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($credentialPath !== null && is_file($credentialPath)) {
        if (!unlink($credentialPath)) fwrite(STDERR, "Private credential file retained; operator attention required.\n");
    }
    fwrite(STDERR, 'Backup stopped: ' . get_class($e) . '. Keep generation disabled. No earlier backups removed.' . PHP_EOL);
    // Message retained privately; never print database errors, records or credentials.
    if (isset($target) && $target && is_dir($target)) {
        $safeMessage = $e instanceof mysqli_sql_exception ? 'Database operation failed (details suppressed).' : $e->getMessage();
        @file_put_contents($target . '/failure.txt', $safeMessage, LOCK_EX);
    }
    exit(1);
}
