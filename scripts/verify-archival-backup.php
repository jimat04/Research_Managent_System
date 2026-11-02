<?php
/** Explicitly authorized restore rehearsal into a newly created isolated database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env = static fn(string $key, string $default): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $default);
try {
    $target = realpath($argv[1] ?? ''); $root = realpath(__DIR__ . '/../backups');
    if (!$target || !$root || dirname($target) !== $root || ($argv[2] ?? '') !== '--authorized-isolated-restore') throw new RuntimeException('Use a backup directory and explicit restore authorization.');
    $verification = json_decode(file_get_contents($target . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
    $inventory = json_decode(file_get_contents($target . '/inventory.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals($verification['sha256'], hash_file('sha256', $target . '/rms_db.sql'))) throw new RuntimeException('Backup checksum mismatch.');
    $host = $env('DB_HOST', 'localhost');
    if (!in_array($host, ['localhost','127.0.0.1','::1'], true) || $env('DB_NAME', 'rms_db') !== 'rms_db') throw new RuntimeException('Local target required.');
    $sql = file_get_contents($target . '/rms_db.sql');
    if (preg_match('/^\s*(?:USE\s+|CREATE\s+DATABASE)/mi', $sql) || str_contains($sql, '`rms_db`.')) throw new RuntimeException('Dump contains a database target override.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $env('DB_USER','root'), $env('DB_PASS',''));
    $db->set_charset('utf8mb4');
    $name = 'rms_archive_restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db->select_db($name);
    if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== $name) throw new RuntimeException('Isolation check failed.');
    $quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $s) . '"';
    $credentialFile = $target . '/restore-client.cnf';
    file_put_contents($credentialFile, "[client]\nhost=" . $quote($host) . "\nuser=" . $quote($env('DB_USER','root')) . "\npassword=" . $quote($env('DB_PASS','')) . "\n");
    try {
        $process = proc_open(['C:/xampp/mysql/bin/mysql.exe', '--defaults-extra-file=' . $credentialFile, '--default-character-set=utf8mb4', $name], [0 => ['file', $target . '/rms_db.sql', 'r'], 1 => ['file', $target . '/restore.stdout.log','a'], 2 => ['file', $target . '/restore.stderr.log','a']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Restore client failed.');
    } finally { unlink($credentialFile); }
    $tables = $db->query('SHOW TABLES')->fetch_all();
    if (count($tables) !== count($inventory['tables'])) throw new RuntimeException('Table inventory mismatch.');
    foreach ($inventory['tables'] as $table => $expected) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid table.');
        $hashes = [];
        foreach ($db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC) as $row) $hashes[] = hash('sha256', serialize($row));
        sort($hashes);
        if (count($hashes) !== $expected['count'] || hash('sha256', implode('', $hashes)) !== $expected['data_sha256']) throw new RuntimeException('Restored row integrity mismatch.');
        $check = $db->query('CHECK TABLE `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        if (($check[count($check)-1]['Msg_text'] ?? '') !== 'OK') throw new RuntimeException('Restored table integrity failed.');
    }
    foreach ($inventory['files'] as $relative => $hash) if (!hash_equals($hash, hash_file('sha256', $target . '/' . $relative))) throw new RuntimeException('Supporting file mismatch.');
    $verification['restore'] = 'passed'; $verification['restored_database'] = $name;
    $verification['restored_tables_verified'] = count($tables);
    file_put_contents($target . '/verification.json', json_encode($verification, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($verification, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore verification stopped; isolated database retained: ' . ($name ?? 'none created') . '. ' . ($e instanceof mysqli_sql_exception ? 'Database operation failed.' : $e->getMessage()) . PHP_EOL); exit(1);
}
