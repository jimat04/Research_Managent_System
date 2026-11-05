<?php
/** Read-only capabilities/optional local metadata inspection. Never bootstraps RMS. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-document-prerequisites.php';
require_once __DIR__ . '/../includes/earist-document-schema.php';
require_once __DIR__ . '/../includes/earist-document-edit-schema.php';

$arguments = array_slice($argv, 1);
if (array_diff($arguments, ['--database'])) {
    fwrite(STDERR, "Usage: php scripts/check-earist-prerequisites.php [--database]\n");
    exit(1);
}
$result = ['runtime' => earistDocumentPrerequisites()];
$success = $result['runtime']['ready'];
if (in_array('--database', $arguments, true)) {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        $readEnv = static fn(string $key, string $fallback): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $fallback);
        if ($readEnv('DB_NAME', 'rms_db') !== 'rms_db' || !in_array($readEnv('DB_HOST', 'localhost'), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Only the authorized local rms_db target is permitted.');
        }
        $conn = new mysqli($readEnv('DB_HOST', 'localhost'), $readEnv('DB_USER', 'root'), $readEnv('DB_PASS', ''), 'rms_db');
        $conn->set_charset('utf8mb4');
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
        $result['database'] = ['target' => $conn->query('SELECT DATABASE()')->fetch_row()[0], 'server' => $conn->query('SELECT VERSION()')->fetch_row()[0]];
        $ledgerExists = (int) $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'")->fetch_row()[0] === 1;
        $result['database']['ledger_exists'] = $ledgerExists;
        $result['database']['ledger'] = $ledgerExists
            ? $conn->query("SELECT filename, status FROM schema_migrations WHERE filename REGEXP '^05[0-9]_' OR filename = '049_faculty_document_profile_fields.sql' ORDER BY filename")->fetch_all(MYSQLI_ASSOC) : [];
        $result['database']['storage'] = earistDocumentSchemaStatus($conn);
        $result['database']['editing_schema'] = earistDocumentEditSchemaStatus($conn);
        $result['database']['latest_recorded_numbered_migration'] = $conn->query("SELECT filename FROM schema_migrations WHERE filename REGEXP '^[0-9]{3}_' ORDER BY filename DESC LIMIT 1")->fetch_row()[0] ?? null;
        $conn->rollback();
        $conn->close();
        $result['database']['note'] = 'Read-only inspection; no ledger creation, synchronization or migration performed.';
    } catch (Throwable $exception) {
        $success = false;
        $result['database'] = ['error' => 'Local read-only metadata inspection unavailable.', 'exception_class' => get_class($exception), 'code' => (int) $exception->getCode()];
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($success ? 0 : 1);
