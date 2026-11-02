<?php
/** Local operator backup. Does not bootstrap the application or alter source data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env = static fn(string $key, string $default): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $default);
$credentials = null;
try {
    $host = $env('DB_HOST', 'localhost');
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $env('DB_NAME', 'rms_db') !== 'rms_db') throw new RuntimeException('Only local rms_db is authorized.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $env('DB_USER', 'root'), $env('DB_PASS', ''), 'rms_db');
    $db->set_charset('utf8mb4');
    $inventory = static function (mysqli $db): array {
        $rows = [];
        foreach ($db->query('SHOW FULL TABLES')->fetch_all() as [$table, $type]) {
            if ($type !== 'BASE TABLE' || !preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unsupported table.');
            $hashes = [];
            foreach ($db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC) as $row) $hashes[] = hash('sha256', serialize($row));
            sort($hashes);
            $rows[$table] = ['count' => count($hashes), 'data_sha256' => hash('sha256', implode('', $hashes))];
        }
        ksort($rows); return $rows;
    };
    $before = $inventory($db);
    $target = dirname(__DIR__) . '/backups/archive-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    if (!mkdir($target, 0700, true)) throw new RuntimeException('Backup directory unavailable.');
    file_put_contents($target . '/.htaccess', "Require all denied\n");
    $quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $s) . '"';
    $credentials = $target . '/client.cnf';
    file_put_contents($credentials, "[client]\nhost=" . $quote($host) . "\nuser=" . $quote($env('DB_USER', 'root')) . "\npassword=" . $quote($env('DB_PASS', '')) . "\n");
    $dump = $target . '/rms_db.sql';
    $process = proc_open(['C:/xampp/mysql/bin/mysqldump.exe', '--defaults-extra-file=' . $credentials, '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob', '--skip-extended-insert', '--complete-insert', '--result-file=' . $dump, 'rms_db'], [0 => ['pipe','r'], 1 => ['file', $target . '/stdout.log','x'], 2 => ['file', $target . '/stderr.log','x']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Dump unavailable.');
    fclose($pipes[0]); $code = proc_close($process);
    unlink($credentials); $credentials = null;
    if ($code !== 0 || !is_file($dump) || filesize($dump) === 0) throw new RuntimeException('Dump failed.');
    $sql = file_get_contents($dump);
    if (!str_contains($sql, '-- Dump completed on')) throw new RuntimeException('Incomplete dump.');
    foreach ($before as $table => $info) {
        if (!str_contains($sql, 'CREATE TABLE `' . $table . '`') || preg_match_all('/^INSERT INTO `' . preg_quote($table, '/') . '` /m', $sql) !== $info['count']) throw new RuntimeException('Dump inventory mismatch.');
    }
    $files = [];
    foreach (['publications', 'copyrights', 'copyright'] as $folder) {
        $root = dirname(__DIR__) . '/uploads/' . $folder;
        if (!is_dir($root)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Unsupported file.');
            $relative = 'uploads/' . $folder . '/' . substr($file->getPathname(), strlen($root) + 1);
            $destination = $target . '/' . $relative;
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
            if (!copy($file->getPathname(), $destination) || hash_file('sha256', $destination) !== hash_file('sha256', $file->getPathname())) throw new RuntimeException('File backup mismatch.');
            $files[$relative] = hash_file('sha256', $destination);
        }
    }
    if ($before !== $inventory($db)) throw new RuntimeException('Database changed during backup; retry in a quiet window.');
    file_put_contents($target . '/inventory.json', json_encode(['tables' => $before, 'files' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $summary = ['target' => $target, 'database' => 'rms_db', 'tables' => count($before), 'supporting_files' => count($files), 'bytes' => filesize($dump), 'sha256' => hash_file('sha256', $dump), 'integrity' => 'passed', 'restore' => 'not_rehearsed'];
    file_put_contents($target . '/verification.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($credentials && is_file($credentials)) unlink($credentials);
    fwrite(STDERR, 'Backup stopped: ' . ($e instanceof mysqli_sql_exception ? 'Database connection or query failed.' : $e->getMessage()) . PHP_EOL);
    exit(1);
}
