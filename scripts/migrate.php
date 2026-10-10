<?php
/**
 * Safe migration status, reconciliation, and execution tool for RMS.
 *
 * Usage:
 *   php scripts/migrate.php status
 *   php scripts/migrate.php sync
 *   php scripts/migrate.php apply 016_create_migration_tracking.sql
 *
 * Existing schema is reconciled by verification before a migration is
 * recorded. An applied migration is never executed again automatically.
 * Migrations without a verifier must be reviewed and added explicitly rather
 * than being executed blindly.
 */

declare(strict_types=1);

// The shared web bootstrap starts a session. Keep CLI migration checks from
// depending on the web server's session directory and cookie transport.
if (PHP_SAPI === 'cli') {
    ini_set('session.save_path', sys_get_temp_dir());
    ini_set('session.use_cookies', '0');
}

require_once __DIR__ . '/../includes/config.php';

const MIGRATION_TABLE = 'schema_migrations';

function migration_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$message}" . PHP_EOL);
    exit($code);
}

function migration_query(mysqli $conn, string $sql): mysqli_result|bool
{
    $result = $conn->query($sql);
    if ($result === false) {
        migration_fail($conn->error);
    }
    return $result;
}

function migration_scalar(mysqli $conn, string $sql): mixed
{
    $result = migration_query($conn, $sql);
    if ($result instanceof mysqli_result) {
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }
    return null;
}

function migration_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function migration_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function migration_column_type(mysqli $conn, string $table, string $column): string
{
    $stmt = $conn->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $type = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
    $stmt->close();
    return $type;
}

function migration_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function migration_constraint_exists(mysqli $conn, string $table, string $constraint): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function migration_enum_contains(mysqli $conn, string $table, string $column, string $value): bool
{
    return str_contains(migration_column_type($conn, $table, $column), "'{$value}'");
}

/**
 * Verify the complete resulting state of the demo-account resynchronization.
 *
 * A mismatch is intentionally unresolved rather than treated as a simple
 * pending migration: a changed demo password may be legitimate user drift,
 * and executing this migration would overwrite it. The verifier only returns
 * true when every targeted account exists with the exact values changed by
 * migration 010, including the role predicates used by its conditional
 * updates.
 */
function verify_demo_accounts_resync(mysqli $conn): ?bool
{
    $expected = [
        'admin@rms.edu.ph' => [
            'password' => '$2y$12$1UzhJiJbBSdUw.3bEMUg/ub1Dx55.kgrVAQiphCfUKwe8ywCM9XCO',
            'status' => 'active',
        ],
        'msantos@rms.edu.ph' => [
            'role' => 'faculty',
            'password' => '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG',
            'status' => 'active',
            'is_reviewer' => 1,
        ],
        'jreyes@rms.edu.ph' => [
            'role' => 'faculty',
            'password' => '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG',
            'status' => 'active',
            'is_reviewer' => 1,
        ],
        'jdelacruz@rms.edu.ph' => [
            'password' => '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK',
            'status' => 'active',
        ],
        'areyes@rms.edu.ph' => [
            'password' => '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK',
            'status' => 'active',
        ],
        'EREC@rms.edu.ph' => [
            'role' => 'research_staff',
            'password' => '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq',
            'status' => 'active',
        ],
        'CREC@rms.edu.ph' => [
            'role' => 'research_staff',
            'password' => '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq',
            'status' => 'active',
        ],
        'ORS@rms.edu.ph' => [
            'role' => 'research_staff',
            'password' => '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq',
            'status' => 'active',
        ],
        'graduate@rms.edu.ph' => [
            'role' => 'research_staff',
            'password' => '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq',
            'status' => 'active',
        ],
    ];

    $stmt = $conn->prepare('SELECT role, password, status, is_reviewer FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    foreach ($expected as $email => $values) {
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row
            || (string) $row['password'] !== $values['password']
            || (string) $row['status'] !== $values['status']
            || (isset($values['role']) && (string) $row['role'] !== $values['role'])
            || (isset($values['is_reviewer']) && (int) $row['is_reviewer'] !== $values['is_reviewer'])) {
            $stmt->close();
            return null;
        }
    }

    $stmt->close();
    return true;
}

function verify_staff_demo_accounts(mysqli $conn): ?bool
{
    $expected = [
        'staff1@rms.edu.ph' => ['staff1', 'EREC Office'],
        'staff2@rms.edu.ph' => ['staff2', 'CREC Office'],
    ];
    $stmt = $conn->prepare('SELECT role, first_name, last_name, office, status, deleted_at FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    foreach ($expected as $email => [$name, $office]) {
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row
            || $row['role'] !== 'research_staff'
            || $row['first_name'] !== $name
            || $row['last_name'] !== ''
            || $row['office'] !== $office
            || $row['status'] !== 'active'
            || $row['deleted_at'] !== null) {
            $stmt->close();
            return false;
        }
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT role, status, deleted_at FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    foreach (['ORS@rms.edu.ph', 'graduate@rms.edu.ph'] as $email) {
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && ($row['role'] !== 'research_staff' || $row['status'] !== 'suspended' || $row['deleted_at'] === null)) {
            $stmt->close();
            return false;
        }
    }
    $stmt->close();
    return true;
}

function tracking_table_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `migration_key` varchar(160) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `status` enum('applied','failed') NOT NULL DEFAULT 'applied',
  `verification_method` enum('executed','verified_existing') DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `execution_ms` decimal(12,3) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`migration_key`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`),
  KEY `idx_schema_migrations_status` (`status`),
  KEY `idx_schema_migrations_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
}

function ensure_tracking_table(mysqli $conn): void
{
    migration_query($conn, tracking_table_sql());
}

/**
 * Return true when a migration's resulting state is present, false when it
 * is known to be absent, or null when the migration needs a human verifier.
 */
function verify_migration(mysqli $conn, string $filename): ?bool
{
    return match ($filename) {
        '002_enhance_users_for_registration.sql' =>
            migration_enum_contains($conn, 'users', 'role', 'research_staff')
            && migration_column_exists($conn, 'users', 'office')
            && migration_column_exists($conn, 'users', 'specialization')
            && migration_column_exists($conn, 'users', 'academic_rank')
            && migration_column_exists($conn, 'users', 'year_level'),
        '003_add_email_verification.sql' =>
            migration_column_exists($conn, 'users', 'email_verified')
            && migration_column_exists($conn, 'users', 'email_verification_token')
            && migration_column_exists($conn, 'users', 'email_verification_expires')
            && strtolower(migration_column_type($conn, 'users', 'email_verified')) === 'tinyint(1)'
            && strtolower(migration_column_type($conn, 'users', 'email_verification_token')) === 'varchar(64)'
            && strtolower(migration_column_type($conn, 'users', 'email_verification_expires')) === 'datetime'
            && migration_index_exists($conn, 'users', 'idx_verification_token'),
        '004_extend_status_enums.sql' =>
            migration_enum_contains($conn, 'research_projects', 'status', 'under_erec_review'),
        '006_create_project_reviews.sql' => migration_table_exists($conn, 'project_reviews'),
        '007_form3_full_criteria.sql' => migration_column_exists($conn, 'project_reviews', 'capability_score'),
        '008_comments_project_feedback.sql' => migration_column_exists($conn, 'comments', 'project_id'),
        '009_adviser_history.sql' => migration_table_exists($conn, 'project_advisers_history'),
        // This migration changes demo data. It is verified only when every
        // targeted row still exactly matches the migration's intended state;
        // any drift remains unresolved so a sync cannot overwrite it.
        '010_demo_accounts_resync.sql' => verify_demo_accounts_resync($conn),
        '011_repair_drift.sql' =>
            migration_enum_contains($conn, 'users', 'role', 'research_staff')
            && migration_enum_contains($conn, 'users', 'status', 'suspended'),
        '011_research_author_snapshots.sql' => migration_table_exists($conn, 'research_author_snapshots'),
        '012_activity_publication_copyright.sql' =>
            migration_table_exists($conn, 'research_activities')
            && migration_table_exists($conn, 'activity_participants')
            && migration_table_exists($conn, 'publications')
            && migration_table_exists($conn, 'copyright_applications'),
        '013_copyright_registration_date.sql' => migration_column_exists($conn, 'copyright_applications', 'registered_at'),
        '014_add_training_activity_type.sql' => migration_enum_contains($conn, 'research_activities', 'activity_type', 'training'),
        '015_explicit_ip_types_and_research_output_links.sql' =>
            migration_enum_contains($conn, 'copyright_applications', 'output_type', 'patent')
            && migration_enum_contains($conn, 'copyright_applications', 'output_type', 'utility_model')
            && migration_enum_contains($conn, 'copyright_applications', 'output_type', 'innovation')
            && migration_enum_contains($conn, 'copyright_applications', 'output_type', 'technology_transfer')
            && migration_column_exists($conn, 'copyright_applications', 'research_project_id')
            && migration_column_exists($conn, 'copyright_applications', 'publication_id')
            && migration_column_exists($conn, 'publications', 'research_project_id')
            && migration_index_exists($conn, 'copyright_applications', 'idx_copyright_applications_research_project_id')
            && migration_index_exists($conn, 'copyright_applications', 'idx_copyright_applications_publication_id')
            && migration_index_exists($conn, 'publications', 'idx_publications_research_project_id')
            && migration_constraint_exists($conn, 'copyright_applications', 'fk_copyright_applications_research_project')
            && migration_constraint_exists($conn, 'copyright_applications', 'fk_copyright_applications_publication')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_research_project'),
        '016_create_migration_tracking.sql' => migration_table_exists($conn, MIGRATION_TABLE),
        '017_research_submission_versions.sql' =>
            migration_table_exists($conn, 'research_submission_versions')
            && migration_table_exists($conn, 'research_submission_reviews')
            && migration_table_exists($conn, 'research_submission_version_uploads')
            && migration_column_exists($conn, 'research_submission_versions', 'snapshot_json')
            && migration_index_exists($conn, 'research_submission_versions', 'idx_submission_versions_project'),
        '018_research_title_approval_status.sql' =>
            migration_enum_contains($conn, 'research_projects', 'status', 'title_approved'),
        '019_staff1_staff2_demo_accounts.sql' => verify_staff_demo_accounts($conn),
        'add_contact_messages_table.sql' =>
            migration_table_exists($conn, 'contact_messages')
            && migration_column_exists($conn, 'contact_messages', 'concern_type'),
        'rms_db_migration.sql' =>
            migration_column_exists($conn, 'research_projects', 'deleted_at')
            && migration_column_exists($conn, 'chapters', 'deleted_at')
            && migration_table_exists($conn, 'research_documents'),
        default => null,
    };
}

function migration_files(): array
{
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    usort($files, static function (string $left, string $right): int {
        $leftName = basename($left);
        $rightName = basename($right);
        preg_match('/^(\d+)_/', $leftName, $leftMatch);
        preg_match('/^(\d+)_/', $rightName, $rightMatch);
        $leftNumber = isset($leftMatch[1]) ? (int) $leftMatch[1] : PHP_INT_MAX;
        $rightNumber = isset($rightMatch[1]) ? (int) $rightMatch[1] : PHP_INT_MAX;
        return $leftNumber === $rightNumber
            ? strnatcasecmp($leftName, $rightName)
            : $leftNumber <=> $rightNumber;
    });
    return $files;
}

function tracked_row(mysqli $conn, string $filename): ?array
{
    $stmt = $conn->prepare('SELECT migration_key, filename, checksum, status, verification_method, applied_at, verified_at, execution_ms, error_message FROM schema_migrations WHERE migration_key = ? LIMIT 1');
    $stmt->bind_param('s', $filename);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function record_applied(mysqli $conn, string $filename, string $checksum, string $method, float $executionMs): void
{
    $stmt = $conn->prepare("INSERT INTO schema_migrations (migration_key, filename, checksum, status, verification_method, applied_at, verified_at, execution_ms, error_message) VALUES (?, ?, ?, 'applied', ?, NOW(), NOW(), ?, NULL) ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'applied', verification_method = VALUES(verification_method), applied_at = VALUES(applied_at), verified_at = VALUES(verified_at), execution_ms = VALUES(execution_ms), error_message = NULL");
    $stmt->bind_param('ssssd', $filename, $filename, $checksum, $method, $executionMs);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        migration_fail('Could not record applied migration: ' . $error);
    }
    $stmt->close();
}

function record_failed(mysqli $conn, string $filename, string $checksum, string $error): void
{
    $stmt = $conn->prepare("INSERT INTO schema_migrations (migration_key, filename, checksum, status, verification_method, applied_at, verified_at, execution_ms, error_message) VALUES (?, ?, ?, 'failed', NULL, NULL, NULL, NULL, ?) ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'failed', verification_method = NULL, applied_at = NULL, verified_at = NULL, execution_ms = NULL, error_message = VALUES(error_message)");
    $stmt->bind_param('ssss', $filename, $filename, $checksum, $error);
    $stmt->execute();
    $stmt->close();
}

function print_status(mysqli $conn): void
{
    $rows = [];
    $result = migration_query($conn, 'SELECT migration_key, checksum, status, verification_method, applied_at, error_message FROM schema_migrations ORDER BY migration_key');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) $rows[$row['migration_key']] = $row;
        $result->free();
    }

    foreach (migration_files() as $path) {
        $filename = basename($path);
        $row = $rows[$filename] ?? null;
        $state = $row['status'] ?? 'pending';
        $method = $row['verification_method'] ?? '';
        if ($row && $row['status'] === 'applied') {
            $checksum = hash_file('sha256', $path);
            if (!hash_equals((string) ($row['checksum'] ?? ''), $checksum)) {
                $state = 'checksum-mismatch';
            }
        } elseif (!$row) {
            $verified = verify_migration($conn, $filename);
            $state = $verified === true ? 'ready-to-sync' : ($verified === false ? 'pending' : 'needs-verifier');
        }
        echo str_pad($filename, 58) . str_pad($state, 20) . ($method !== '' ? $method : '') . PHP_EOL;
    }
}

function sync_existing(mysqli $conn): int
{
    $count = 0;
    foreach (migration_files() as $path) {
        $filename = basename($path);
        $checksum = hash_file('sha256', $path);
        $existing = tracked_row($conn, $filename);
        if ($existing && $existing['status'] === 'applied') {
            if (!hash_equals((string) $existing['checksum'], $checksum)) {
                migration_fail("Checksum changed for applied migration {$filename}; review manually.");
            }
            continue;
        }
        if ($existing && $existing['status'] === 'failed') continue;
        if (verify_migration($conn, $filename) === true) {
            record_applied($conn, $filename, $checksum, 'verified_existing', 0.0);
            echo "RECORDED {$filename} (verified existing schema)" . PHP_EOL;
            $count++;
        }
    }
    return $count;
}

function execute_one(mysqli $conn, string $path): void
{
    $filename = basename($path);
    $checksum = hash_file('sha256', $path);
    $existing = tracked_row($conn, $filename);
    if ($existing && $existing['status'] === 'applied') {
        if (!hash_equals((string) $existing['checksum'], $checksum)) {
            migration_fail("Checksum changed for applied migration {$filename}; refusing to execute it.");
        }
        echo "SKIPPED {$filename} (already applied)" . PHP_EOL;
        return;
    }
    if ($existing && $existing['status'] === 'failed') {
        migration_fail("{$filename} has a recorded failed attempt; inspect and reconcile it before retrying.");
    }

    $before = verify_migration($conn, $filename);
    if ($before === true) {
        record_applied($conn, $filename, $checksum, 'verified_existing', 0.0);
        echo "RECORDED {$filename} (verified existing schema; SQL not rerun)" . PHP_EOL;
        return;
    }
    if ($before === null) {
        migration_fail("No conclusive verifier result is available for {$filename}; refusing blind execution.");
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') migration_fail("Could not read {$filename}.");
    $started = microtime(true);
    try {
        if (!$conn->multi_query($sql)) {
            throw new RuntimeException($conn->error);
        }
        do {
            if ($result = $conn->store_result()) $result->free();
            if ($conn->errno) throw new RuntimeException($conn->error);
        } while ($conn->more_results() && $conn->next_result());
        $after = verify_migration($conn, $filename);
        if ($after !== true) throw new RuntimeException('SQL completed but the registered schema verifier did not pass.');
        $elapsed = (microtime(true) - $started) * 1000;
        record_applied($conn, $filename, $checksum, 'executed', $elapsed);
        echo "APPLIED {$filename}" . PHP_EOL;
    } catch (Throwable $exception) {
        record_failed($conn, $filename, $checksum, $exception->getMessage());
        migration_fail("{$filename} failed and was not recorded as applied: {$exception->getMessage()}");
    }
}

$command = $argv[1] ?? 'status';
$conn->set_charset('utf8mb4');
ensure_tracking_table($conn);

if ($command === 'status') {
    print_status($conn);
    exit(0);
}

if ($command === 'sync') {
    $count = sync_existing($conn);
    echo "SYNCHRONIZED {$count} verified migration(s)." . PHP_EOL;
    exit(0);
}

if ($command === 'apply') {
    $requested = $argv[2] ?? '';
    if ($requested === '') migration_fail('Specify one migration filename to apply explicitly.');
    $path = realpath(__DIR__ . '/../database/migrations/' . basename($requested));
    $root = realpath(__DIR__ . '/../database/migrations');
    if ($path === false || $root === false || dirname($path) !== $root || !is_file($path)) {
        migration_fail('Migration file was not found in database/migrations.');
    }
    execute_one($conn, $path);
    exit(0);
}

migration_fail('Unknown command. Use status, sync, or apply <migration.sql>.');
