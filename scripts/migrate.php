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
require_once __DIR__ . '/../includes/faculty-ranks.php';
require_once __DIR__ . '/../includes/earist-document-schema.php';
require_once __DIR__ . '/../includes/earist-document-edit-schema.php';
require_once __DIR__ . '/../includes/earist-google-drive-schema.php';
require_once __DIR__ . '/../includes/earist-document-retention-schema.php';

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

function migration_column_default(mysqli $conn, string $table, string $column): ?string
{
    $stmt = $conn->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row && $row[0] !== null ? (string) $row[0] : null;
}

function migration_column_extra(mysqli $conn, string $table, string $column): string
{
    $stmt = $conn->prepare('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $extra = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
    $stmt->close();
    return $extra;
}

function migration_default_matches(?string $actual, string $expected): bool
{
    return $actual === $expected || $actual === "'{$expected}'";
}

function migration_column_nullable(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $nullable = (string) ($stmt->get_result()->fetch_row()[0] ?? '') === 'YES';
    $stmt->close();
    return $nullable;
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

function migration_index_is_unique(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $isUnique = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $isUnique;
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

function verify_legacy_title_submission_backfill(mysqli $conn): ?bool
{
    $targets = [
        1 => 'The Effects of Online Learning on Student Performance',
        2 => 'The Awareness of Cybersecurity Practices Among College Students',
    ];
    $stmt = $conn->prepare("
        SELECT rp.title, rp.status AS project_status, rp.deleted_at,
               rp.created_by, rp.created_at,
               v.version_id, v.chapter_id, v.version_number,
               v.submitted_by, v.submitted_at, v.status, v.snapshot_json,
               EXISTS (
                   SELECT 1
                     FROM research_submission_reviews review
                    WHERE review.version_id = v.version_id
               ) AS has_review
          FROM research_projects rp
     LEFT JOIN research_submission_versions v
            ON v.project_id = rp.project_id
           AND v.submission_type = 'title'
         WHERE rp.project_id = ?
    ");
    if (!$stmt) {
        return null;
    }

    foreach ($targets as $projectId => $expectedTitle) {
        $stmt->bind_param('i', $projectId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        if (!$rows
            || (string) $rows[0]['title'] !== $expectedTitle
            || (string) $rows[0]['project_status'] !== 'ongoing'
            || $rows[0]['deleted_at'] !== null
            || (int) $rows[0]['created_by'] <= 0) {
            $stmt->close();
            return null;
        }
        if ($rows[0]['version_id'] === null) {
            $stmt->close();
            return false;
        }
        if (count($rows) !== 1) {
            $stmt->close();
            return null;
        }

        $row = $rows[0];
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        $legacyImport = is_array($snapshot) ? ($snapshot['legacy_import'] ?? null) : null;
        $originalSubmission = is_array($legacyImport)
            ? strtolower((string) ($legacyImport['original_submission_details'] ?? ''))
            : '';
        $originalReviewer = is_array($legacyImport)
            ? strtolower((string) ($legacyImport['original_reviewer_details'] ?? ''))
            : '';
        if (!is_array($legacyImport)
            || empty($legacyImport['imported'])
            || ($legacyImport['origin'] ?? '') !== 'legacy_title_submission_backfill'
            || ($snapshot['title'] ?? null) !== $expectedTitle
            || (int) $row['version_number'] !== 1
            || $row['chapter_id'] !== null
            || (int) $row['submitted_by'] !== (int) $row['created_by']
            || (string) $row['submitted_at'] !== (string) $row['created_at']
            || (string) $row['status'] !== 'approved'
            || (string) ($legacyImport['approximate_project_created_at'] ?? '') !== (string) $row['created_at']
            || !str_contains($originalSubmission, 'unavailable')
            || !str_contains($originalReviewer, 'unavailable')
            || (int) $row['has_review'] !== 0) {
            $stmt->close();
            return null;
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
function verify_final_manuscript_type_repair(mysqli $conn): ?bool
{
    foreach (['research_submission_versions', 'research_submission_version_uploads', 'uploads'] as $table) {
        if (!migration_table_exists($conn, $table)) {
            return null;
        }
    }
    $column_type = strtolower(migration_column_type($conn, 'research_submission_versions', 'submission_type'));
    if (!in_array($column_type, ["enum('title','chapter')", "enum('title','chapter','final_manuscript')", "enum('title','chapter','final_manuscript','')"], true)) {
        return null;
    }
    $blank = (int) migration_scalar($conn, "SELECT COUNT(*) FROM research_submission_versions WHERE submission_type = ''");
    $confirmed = (int) migration_scalar($conn, "SELECT COUNT(*) FROM research_submission_versions v
        WHERE v.submission_type = '' AND v.chapter_id IS NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(v.snapshot_json), v.snapshot_json, '{}'), '$.submission')) = 'Complete research manuscript'
          AND EXISTS (SELECT 1 FROM research_submission_version_uploads vu JOIN uploads up ON up.upload_id = vu.upload_id
                      WHERE vu.version_id = v.version_id AND up.type = 'manuscript' AND up.project_id = v.project_id)
          AND NOT EXISTS (SELECT 1 FROM research_submission_versions other_version
              WHERE other_version.project_id = v.project_id AND other_version.chapter_id IS NULL
                AND other_version.submission_type = 'final_manuscript' AND other_version.version_number = v.version_number)");
    if ($blank !== $confirmed) {
        return null; // Unknown or conflicting historical values require individual investigation.
    }
    return $blank === 0 && $column_type === "enum('title','chapter','final_manuscript')";
}

function verify_faculty_program_assignments(mysqli $conn): ?bool
{
    if (!migration_table_exists($conn, 'faculty_program_assignments')) {
        return false;
    }

    foreach (['faculty_id', 'program_id', 'assigned_at'] as $column) {
        if (!migration_column_exists($conn, 'faculty_program_assignments', $column)) {
            return false;
        }
    }

    if (!migration_index_exists($conn, 'faculty_program_assignments', 'PRIMARY')
        || !migration_index_exists($conn, 'faculty_program_assignments', 'idx_faculty_program_assignments_program')
        || !migration_constraint_exists($conn, 'faculty_program_assignments', 'fk_faculty_program_assignments_faculty')
        || !migration_constraint_exists($conn, 'faculty_program_assignments', 'fk_faculty_program_assignments_program')) {
        return false;
    }

    $missing_backfill = (int) migration_scalar(
        $conn,
        "SELECT COUNT(*)
           FROM users u
           JOIN programs p
             ON p.status = 1
            AND p.program_code IN ('BSIT', 'BSCS')
            AND (
                 UPPER(TRIM(u.program)) = UPPER(p.program_code)
                 OR UPPER(TRIM(u.program)) = UPPER(TRIM(p.program_name))
                 OR FIND_IN_SET(p.program_code, REPLACE(UPPER(TRIM(u.program)), ' ', '')) > 0
            )
      LEFT JOIN faculty_program_assignments fpa
             ON fpa.faculty_id = u.user_id AND fpa.program_id = p.program_id
          WHERE u.role = 'faculty' AND u.deleted_at IS NULL
            AND NULLIF(TRIM(u.program), '') IS NOT NULL
            AND fpa.faculty_id IS NULL"
    );

    return $missing_backfill === 0;
}

function verify_course_specific_research_categories(mysqli $conn): bool
{
    if (!migration_table_exists($conn, 'research_category_programs')
        || !migration_index_exists($conn, 'research_category_programs', 'PRIMARY')
        || !migration_index_exists($conn, 'research_category_programs', 'idx_research_category_programs_program')
        || !migration_constraint_exists($conn, 'research_category_programs', 'fk_research_category_programs_category')
        || !migration_constraint_exists($conn, 'research_category_programs', 'fk_research_category_programs_program')) {
        return false;
    }

    $expected = [
        'Current Computer Science Topics' => ['BSCS', 17],
        'Foundations of Computer Science' => ['BSCS', 8],
        'Human-Computer Interaction' => ['BSCS', 3],
        'Software Development' => ['BSIT', 4],
        'Multimedia Systems' => ['BSIT', 4],
        'Network Design and Implementation' => ['BSIT', 2],
        'IT Management' => ['BSIT', 2],
    ];

    foreach ($expected as $categoryName => [$programCode, $areaCount]) {
        $stmt = $conn->prepare(
            'SELECT COUNT(DISTINCT cra.area_id) AS area_count,
                    COUNT(DISTINCT rcp.program_id) AS program_count,
                    MAX(p.program_code) AS program_code,
                    MAX(rc.status) AS category_status
               FROM research_categories rc
               JOIN research_category_programs rcp ON rcp.category_id = rc.category_id
               JOIN programs p ON p.program_id = rcp.program_id
          LEFT JOIN category_research_areas cra ON cra.category_id = rc.category_id
              WHERE rc.category_name = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $categoryName);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        if ((int) ($row['area_count'] ?? 0) !== $areaCount
            || (int) ($row['program_count'] ?? 0) !== 1
            || (string) ($row['program_code'] ?? '') !== $programCode
            || (int) ($row['category_status'] ?? 0) !== 1) {
            return false;
        }
    }

    return (int) migration_scalar(
        $conn,
        'SELECT COUNT(*)
           FROM research_categories rc
           JOIN research_category_programs rcp ON rcp.category_id = rc.category_id'
    ) === count($expected)
        && (int) migration_scalar(
            $conn,
            'SELECT COUNT(DISTINCT cra.category_id, cra.area_id)
               FROM category_research_areas cra
               JOIN research_category_programs rcp ON rcp.category_id = cra.category_id'
        ) === 40
        && (int) migration_scalar(
            $conn,
            'SELECT COUNT(DISTINCT ra.area_id)
               FROM research_areas ra
               JOIN research_area_selection_scope scope ON scope.area_id = ra.area_id
               JOIN category_research_areas cra ON cra.area_id = ra.area_id
               JOIN research_category_programs rcp ON rcp.category_id = cra.category_id'
        ) === 38;
}

function verify_title_option_research_classifications(mysqli $conn): bool
{
    return migration_table_exists($conn, 'research_project_title_classifications')
        && migration_index_exists($conn, 'research_project_title_classifications', 'PRIMARY')
        && migration_index_exists($conn, 'research_project_title_classifications', 'idx_research_title_classifications_category')
        && migration_index_exists($conn, 'research_project_title_classifications', 'idx_research_title_classifications_area')
        && migration_constraint_exists($conn, 'research_project_title_classifications', 'fk_research_title_classifications_project')
        && migration_constraint_exists($conn, 'research_project_title_classifications', 'fk_research_title_classifications_category')
        && migration_constraint_exists($conn, 'research_project_title_classifications', 'fk_research_title_classifications_area');
}

function verify_faculty_research_records(mysqli $conn): bool
{
    $definitions = [
        'faculty_research_records' => [
            'research_record_id' => ['int(10) unsigned', false], 'owner_id' => ['int(10) unsigned', false],
            'title' => ['text', false], 'work_type' => ['varchar(64)', false], 'work_type_label' => ['varchar(120)', false],
            'work_type_other' => ['varchar(120)', true], 'date_started' => ['char(7)', true], 'date_completed' => ['char(7)', true],
            'conference_title' => ['varchar(500)', true], 'conference_host' => ['varchar(500)', true],
            'conference_organizer' => ['varchar(500)', true],
            'conference_classification' => ["enum('regional_national_local','international')", true],
            'conference_start_date' => ['date', true], 'conference_end_date' => ['date', true],
            'presentation_modality' => ["enum('in_person','virtual')", true], 'conference_venue' => ['varchar(500)', true],
            'journal_title' => ['varchar(500)', true], 'journal_publisher' => ['varchar(500)', true], 'sdg_other' => ['varchar(500)', true],
            'status' => ["enum('active','archived')", false], 'deleted_at' => ['datetime', true],
            'version' => ['int(10) unsigned', false], 'creation_token_hash' => ['char(64)', false],
            'created_at' => ['datetime', false], 'updated_at' => ['datetime', false],
        ],
        'faculty_research_record_authors' => [
            'record_author_id' => ['int(10) unsigned', false], 'research_record_id' => ['int(10) unsigned', false],
            'faculty_user_id' => ['int(10) unsigned', true], 'author_type' => ["enum('internal','external')", false],
            'full_name' => ['varchar(255)', false], 'position' => ['varchar(255)', false], 'affiliation' => ['varchar(500)', false],
            'author_role' => ["enum('lead','coauthor')", false], 'display_order' => ['int(10) unsigned', false],
            'identity_key' => ['char(64)', false], 'created_at' => ['datetime', false], 'updated_at' => ['datetime', false],
        ],
        'faculty_research_record_sdgs' => ['research_record_id' => ['int(10) unsigned', false], 'sdg_number' => ['tinyint(3) unsigned', false]],
    ];
    foreach ($definitions as $table => $columns) {
        if (!migration_table_exists($conn, $table)) return false;
        $stmt = $conn->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $storage = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($storage['ENGINE'] ?? '') !== 'InnoDB' || ($storage['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci') return false;
        foreach ($columns as $column => [$type, $nullable]) {
            if (strtolower(migration_column_type($conn, $table, $column)) !== $type
                || migration_column_nullable($conn, $table, $column) !== $nullable) return false;
        }
    }
    $indexes = [
        ['faculty_research_records', 'PRIMARY', 'research_record_id', 0],
        ['faculty_research_records', 'uq_faculty_record_creation', 'owner_id,creation_token_hash', 0],
        ['faculty_research_records', 'idx_faculty_records_updated', 'owner_id,deleted_at,status,updated_at,research_record_id', 1],
        ['faculty_research_records', 'idx_faculty_records_created', 'owner_id,deleted_at,status,created_at,research_record_id', 1],
        ['faculty_research_record_authors', 'PRIMARY', 'record_author_id', 0],
        ['faculty_research_record_authors', 'uq_faculty_record_author_user', 'research_record_id,faculty_user_id', 0],
        ['faculty_research_record_authors', 'uq_faculty_record_author_identity', 'research_record_id,identity_key', 0],
        ['faculty_research_record_authors', 'uq_faculty_record_author_order', 'research_record_id,display_order', 0],
        ['faculty_research_record_authors', 'idx_faculty_record_author_user', 'faculty_user_id', 1],
        ['faculty_research_record_sdgs', 'PRIMARY', 'research_record_id,sdg_number', 0],
    ];
    foreach ($indexes as [$table, $index, $columns, $nonUnique]) {
        $stmt = $conn->prepare('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list, MIN(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($row['columns_list'] ?? '') !== $columns || (int) ($row['non_unique'] ?? -1) !== $nonUnique) return false;
    }
    foreach ([
        ['faculty_research_records', 'fk_faculty_records_owner', 'owner_id', 'users', 'user_id', 'RESTRICT'],
        ['faculty_research_record_authors', 'fk_faculty_record_authors_record', 'research_record_id', 'faculty_research_records', 'research_record_id', 'RESTRICT'],
        ['faculty_research_record_authors', 'fk_faculty_record_authors_user', 'faculty_user_id', 'users', 'user_id', 'SET NULL'],
        ['faculty_research_record_sdgs', 'fk_faculty_record_sdgs_record', 'research_record_id', 'faculty_research_records', 'research_record_id', 'RESTRICT'],
    ] as [$table, $constraint, $column, $target, $targetColumn, $deleteRule]) {
        $stmt = $conn->prepare('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ?');
        $stmt->bind_param('ss', $table, $constraint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['COLUMN_NAME'] !== $column || $row['REFERENCED_TABLE_NAME'] !== $target
            || $row['REFERENCED_COLUMN_NAME'] !== $targetColumn || $row['DELETE_RULE'] !== $deleteRule) return false;
    }
    foreach ([
        'faculty_research_records' => ['chk_faculty_records_title', 'chk_faculty_records_months', 'chk_faculty_records_conference_dates'],
        'faculty_research_record_authors' => ['chk_faculty_record_author_type'],
        'faculty_research_record_sdgs' => ['chk_faculty_record_sdg_number'],
    ] as $table => $constraints) {
        foreach ($constraints as $constraint) if (!migration_constraint_exists($conn, $table, $constraint)) return false;
    }
    return migration_default_matches(migration_column_default($conn, 'faculty_research_records', 'status'), 'active')
        && migration_default_matches(migration_column_default($conn, 'faculty_research_records', 'version'), '1');
}

function verify_faculty_academic_ranks(mysqli $conn): bool
{
    // Keep the old ENUM indexes and account values, then append detailed ranks.
    $legacyRanks = ['Instructor', 'Assistant Professor', 'Associate Professor', 'Professor', 'Dean', 'Director', 'College Professor'];
    $storageRanks = array_values(array_unique(array_merge($legacyRanks, array_keys(rmsFacultyAcademicRanks()))));
    $expectedType = "enum('" . implode("','", $storageRanks) . "')";
    $default = migration_column_default($conn, 'users', 'academic_rank');

    return migration_column_type($conn, 'users', 'academic_rank') === $expectedType
        && migration_column_nullable($conn, 'users', 'academic_rank')
        && ($default === null || strtoupper($default) === 'NULL');
}

function verify_academic_affiliation(mysqli $conn): bool
{
    $definitions = [
        'academic_affiliation_groups' => [
            'group_id' => ['int(10) unsigned', false], 'group_code' => ['varchar(40)', false],
            'group_name' => ['varchar(150)', false], 'status' => ['tinyint(1)', false],
            'created_at' => ['datetime', false], 'updated_at' => ['datetime', false],
        ],
        'academic_affiliation_programs' => [
            'affiliation_program_id' => ['int(10) unsigned', false], 'program_id' => ['int(10) unsigned', true],
            'program_code' => ['varchar(20)', true], 'program_name' => ['varchar(150)', false],
            'is_specialization' => ['tinyint(1)', false], 'status' => ['tinyint(1)', false],
        ],
        'academic_group_programs' => [
            'group_id' => ['int(10) unsigned', false], 'affiliation_program_id' => ['int(10) unsigned', false],
            'status' => ['tinyint(1)', false],
        ],
        'user_academic_affiliations' => [
            'affiliation_id' => ['int(10) unsigned', false], 'user_id' => ['int(10) unsigned', false],
            'group_id' => ['int(10) unsigned', false], 'is_primary' => ['tinyint(1)', false],
        ],
        'user_academic_program_assignments' => [
            'affiliation_id' => ['int(10) unsigned', false], 'group_id' => ['int(10) unsigned', false],
            'affiliation_program_id' => ['int(10) unsigned', false], 'assigned_at' => ['datetime', false],
        ],
    ];
    foreach ($definitions as $table => $columns) {
        if (!migration_table_exists($conn, $table)) return false;
        $storageStmt = $conn->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $storageStmt->bind_param('s', $table);
        $storageStmt->execute();
        $storage = $storageStmt->get_result()->fetch_assoc();
        $storageStmt->close();
        if (($storage['ENGINE'] ?? '') !== 'InnoDB' || ($storage['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci') return false;
        foreach ($columns as $column => [$type, $nullable]) {
            if (strtolower(migration_column_type($conn, $table, $column)) !== $type
                || migration_column_nullable($conn, $table, $column) !== $nullable) return false;
        }
    }

    $indexes = [
        ['academic_affiliation_groups', 'PRIMARY', 'group_id', 0],
        ['academic_affiliation_groups', 'uq_academic_affiliation_groups_code', 'group_code', 0],
        ['academic_affiliation_groups', 'uq_academic_affiliation_groups_name', 'group_name', 0],
        ['academic_affiliation_programs', 'PRIMARY', 'affiliation_program_id', 0],
        ['academic_affiliation_programs', 'uq_academic_affiliation_programs_program', 'program_id', 0],
        ['academic_group_programs', 'PRIMARY', 'group_id,affiliation_program_id', 0],
        ['user_academic_affiliations', 'PRIMARY', 'affiliation_id', 0],
        ['user_academic_affiliations', 'uq_user_academic_affiliations_user_group', 'user_id,group_id', 0],
        ['user_academic_affiliations', 'uq_user_academic_affiliations_id_group', 'affiliation_id,group_id', 0],
        ['user_academic_program_assignments', 'PRIMARY', 'affiliation_id,affiliation_program_id', 0],
    ];
    foreach ($indexes as [$table, $index, $expectedColumns, $nonUnique]) {
        $stmt = $conn->prepare('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list, MIN(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($row['columns_list'] ?? '') !== $expectedColumns || (int) ($row['non_unique'] ?? -1) !== $nonUnique) return false;
    }

    $constraints = [
        ['academic_affiliation_programs', 'fk_academic_affiliation_programs_program', 'program_id', 'programs', 'program_id', 'RESTRICT'],
        ['academic_group_programs', 'fk_academic_group_programs_group', 'group_id', 'academic_affiliation_groups', 'group_id', 'RESTRICT'],
        ['academic_group_programs', 'fk_academic_group_programs_program', 'affiliation_program_id', 'academic_affiliation_programs', 'affiliation_program_id', 'RESTRICT'],
        ['user_academic_affiliations', 'fk_user_academic_affiliations_user', 'user_id', 'users', 'user_id', 'CASCADE'],
        ['user_academic_affiliations', 'fk_user_academic_affiliations_group', 'group_id', 'academic_affiliation_groups', 'group_id', 'RESTRICT'],
        ['user_academic_program_assignments', 'fk_user_academic_program_assignments_affiliation', 'affiliation_id', 'user_academic_affiliations', 'affiliation_id', 'CASCADE'],
        ['user_academic_program_assignments', 'fk_user_academic_program_assignments_mapping', 'group_id', 'academic_group_programs', 'group_id', 'RESTRICT'],
    ];
    foreach ($constraints as [$table, $constraint, $column, $target, $targetColumn, $deleteRule]) {
        $stmt = $conn->prepare('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ?');
        $stmt->bind_param('ss', $table, $constraint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['COLUMN_NAME'] !== $column || $row['REFERENCED_TABLE_NAME'] !== $target
            || $row['REFERENCED_COLUMN_NAME'] !== $targetColumn || $row['DELETE_RULE'] !== $deleteRule) return false;
    }

    $seedChecks = [
        "SELECT COUNT(*) FROM academic_affiliation_groups WHERE group_code IN ('computer-studies','business-office-administration','criminology','hospitality-management','industrial-technology','teacher-education','psychology','general-education','graduate-studies')" => 9,
        "SELECT COUNT(*) FROM academic_affiliation_programs WHERE program_name = 'Food Technology' AND program_id IS NULL AND ((program_code IS NULL AND is_specialization = 1) OR (program_code = 'BIT-FoodTech' AND is_specialization = 0))" => 1,
        "SELECT COUNT(*) FROM programs WHERE LOWER(program_name) = 'food technology' OR LOWER(program_code) = 'food technology'" => 0,
        "SELECT COUNT(*) FROM academic_group_programs gp JOIN academic_affiliation_groups g ON g.group_id = gp.group_id JOIN academic_affiliation_programs p ON p.affiliation_program_id = gp.affiliation_program_id WHERE g.group_code = 'industrial-technology' AND p.program_name = 'Food Technology' AND p.program_id IS NULL AND ((p.program_code IS NULL AND p.is_specialization = 1) OR (p.program_code = 'BIT-FoodTech' AND p.is_specialization = 0))" => 1,
    ];
    foreach ($seedChecks as $sql => $expected) {
        if ((int) migration_scalar($conn, $sql) !== $expected) return false;
    }
    return true;
}

function verify_public_research_releases(mysqli $conn): ?bool
{
    foreach (['research_projects', 'publications', 'faculty_research_records', 'research_submission_versions', 'users'] as $table) {
        if (!migration_table_exists($conn, $table)) {
            return null;
        }
    }

    if (!migration_table_exists($conn, 'public_research_releases')) {
        return false;
    }

    $columns = [
        'release_id' => 'int(10) unsigned',
        'source_type' => "enum('student_project','faculty_publication','faculty_research_record')",
        'project_id' => 'int(10) unsigned',
        'publication_id' => 'int(10) unsigned',
        'faculty_research_record_id' => 'int(10) unsigned',
        'release_status' => "enum('published','withdrawn')",
        'reviewed_by' => 'int(10) unsigned',
        'public_manuscript_version_id' => 'int(10) unsigned',
        'publication_paper_released' => 'tinyint(1)',
        'publication_paper_path' => 'varchar(500)',
        'publication_paper_name' => 'varchar(255)',
        'file_reviewed_by' => 'int(10) unsigned',
    ];
    foreach ($columns as $column => $type) {
        if (!migration_column_exists($conn, 'public_research_releases', $column)) {
            return null;
        }
        if (strtolower(migration_column_type($conn, 'public_research_releases', $column)) !== $type) {
            return null;
        }
    }

    foreach ([
        'uq_public_release_project',
        'uq_public_release_publication',
        'uq_public_release_faculty_record',
        'idx_public_release_status_type',
    ] as $index) {
        if (!migration_index_exists($conn, 'public_research_releases', $index)) {
            return null;
        }
    }
    foreach ([
        'fk_public_release_project',
        'fk_public_release_publication',
        'fk_public_release_faculty_record',
        'fk_public_release_manuscript_version',
        'fk_public_release_reviewed_by',
        'fk_public_release_file_reviewed_by',
        'chk_public_release_source',
    ] as $constraint) {
        if (!migration_constraint_exists($conn, 'public_research_releases', $constraint)) {
            return null;
        }
    }

    return true;
}

function verify_migration(mysqli $conn, string $filename): ?bool
{
    return match ($filename) {
        '078_activity_qr_token_encryption.sql' =>
            migration_column_exists($conn, 'activity_schedule_dates', 'checkin_token_encrypted')
            && strtolower(migration_column_type($conn, 'activity_schedule_dates', 'checkin_token_encrypted')) === 'varchar(128)'
            && migration_column_nullable($conn, 'activity_schedule_dates', 'checkin_token_encrypted'),
        '077_unique_user_student_ids.sql' =>
            migration_index_is_unique($conn, 'users', 'uq_users_student_id')
            && !migration_index_exists($conn, 'users', 'idx_users_student_id'),
        '066_public_research_releases.sql' => verify_public_research_releases($conn),
        '064_upload_soft_deletes.sql' =>
            !migration_table_exists($conn, 'uploads') ? null
            : (!migration_column_exists($conn, 'uploads', 'deleted_at') ? false
                : (strtolower(migration_column_type($conn, 'uploads', 'deleted_at')) === 'datetime'
                    && migration_column_nullable($conn, 'uploads', 'deleted_at') ? true : null)),
        '063_academic_affiliation.sql' => verify_academic_affiliation($conn),
        '065_academic_affiliation_food_technology_program.sql' =>
            (int) migration_scalar($conn, "SELECT COUNT(*) FROM academic_affiliation_programs WHERE program_id IS NULL AND program_code = 'BIT-FoodTech' AND program_name = 'Food Technology' AND is_specialization = 0") === 1
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM academic_group_programs gp JOIN academic_affiliation_groups g ON g.group_id = gp.group_id JOIN academic_affiliation_programs p ON p.affiliation_program_id = gp.affiliation_program_id WHERE g.group_code = 'industrial-technology' AND p.program_code = 'BIT-FoodTech' AND gp.status = 1") === 1
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM programs WHERE program_code = 'BIT-FoodTech' OR LOWER(program_name) = 'food technology'") === 0,
        '062_faculty_engagement_activity_link.sql' =>
            migration_column_exists($conn, 'faculty_engagement_records', 'activity_id')
            && strtolower(migration_column_type($conn, 'faculty_engagement_records', 'activity_id')) === 'int(10) unsigned'
            && migration_column_nullable($conn, 'faculty_engagement_records', 'activity_id')
            && migration_index_exists($conn, 'faculty_engagement_records', 'idx_faculty_engagement_activity')
            && migration_constraint_exists($conn, 'faculty_engagement_records', 'fk_faculty_engagement_activity'),
        '059_faculty_adviser_only_teams.sql' =>
            migration_table_exists($conn, 'research_author_snapshots')
            && (int) migration_scalar(
                $conn,
                "SELECT COUNT(*)
                   FROM project_members pm
                   JOIN users u ON u.user_id = pm.user_id
                  WHERE u.role = 'faculty'"
            ) === 0,
        '058_student_single_current_project.sql' =>
            migration_table_exists($conn, 'student_project_assignments')
            && migration_index_exists($conn, 'student_project_assignments', 'PRIMARY')
            && migration_index_exists($conn, 'student_project_assignments', 'idx_student_project_assignments_project')
            && migration_constraint_exists($conn, 'student_project_assignments', 'fk_student_project_assignments_student')
            && migration_constraint_exists($conn, 'student_project_assignments', 'fk_student_project_assignments_project')
            && (int) migration_scalar(
                $conn,
                "SELECT COUNT(*) FROM (
                    SELECT pm.user_id
                      FROM project_members pm
                      JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
                      JOIN research_projects rp ON rp.project_id = pm.project_id AND rp.deleted_at IS NULL
                     GROUP BY pm.user_id
                    HAVING COUNT(DISTINCT pm.project_id) > 1
                ) duplicate_student_memberships"
            ) === 0
            && (int) migration_scalar(
                $conn,
                "SELECT COUNT(*) FROM (
                    SELECT active_projects.user_id, active_projects.project_id
                      FROM (
                        SELECT pm.user_id, pm.project_id
                          FROM project_members pm
                          JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
                          JOIN research_projects rp ON rp.project_id = pm.project_id AND rp.deleted_at IS NULL
                        UNION
                        SELECT rp.created_by, rp.project_id
                          FROM research_projects rp
                          JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
                         WHERE rp.deleted_at IS NULL
                        UNION
                        SELECT rp.student_id, rp.project_id
                          FROM research_projects rp
                          JOIN users u ON u.user_id = rp.student_id AND u.role = 'student'
                         WHERE rp.deleted_at IS NULL
                      ) active_projects
                      LEFT JOIN student_project_assignments assignment
                        ON assignment.student_id = active_projects.user_id
                       AND assignment.project_id = active_projects.project_id
                     WHERE assignment.student_id IS NULL
                ) unassigned_student_projects"
            ) === 0,
        '053_activity_invitation_responses.sql' =>
            migration_column_type($conn, 'activity_schedule_participants', 'invitation_response') === "enum('pending','accepted','declined')"
            && !migration_column_nullable($conn, 'activity_schedule_participants', 'invitation_response')
            && migration_default_matches(migration_column_default($conn, 'activity_schedule_participants', 'invitation_response'), 'pending')
            && strtolower(migration_column_type($conn, 'activity_schedule_participants', 'invitation_responded_at')) === 'datetime'
            && migration_column_nullable($conn, 'activity_schedule_participants', 'invitation_responded_at'),
        '052_earist_document_storage.sql' => earistDocumentSchemaStatus($conn)['valid'],
        '067_earist_document_editing.sql' => earistDocumentEditSchemaStatus($conn),
        '068_earist_google_drive_editing.sql' => earistGoogleDriveSchemaStatus($conn),
        '069_earist_document_retention.sql' => earistDocumentRetentionSchemaStatus($conn),
        '070_research_activity_types_and_permissions.sql' =>
            strtolower(migration_column_type($conn, 'research_activities', 'activity_type'))
                === "enum('seminar_workshop','training','colloquium','conference_congress')"
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM research_activities WHERE activity_type IN ('seminar', 'workshop', 'conference')") === 0
            && migration_table_exists($conn, 'staff_permissions')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM staff_permissions WHERE module_key = 'research_capacity_building' AND subpage_key IN ('seminars', 'workshops')") === 0,
        '071_activity_source_links.sql' =>
            migration_column_exists($conn, 'research_activities', 'source_project_id')
            && strtolower(migration_column_type($conn, 'research_activities', 'source_project_id')) === 'int(10) unsigned'
            && migration_column_nullable($conn, 'research_activities', 'source_project_id')
            && migration_column_exists($conn, 'research_activities', 'source_publication_id')
            && strtolower(migration_column_type($conn, 'research_activities', 'source_publication_id')) === 'int(10) unsigned'
            && migration_column_nullable($conn, 'research_activities', 'source_publication_id')
            && migration_index_exists($conn, 'research_activities', 'idx_research_activities_source_project')
            && migration_index_exists($conn, 'research_activities', 'idx_research_activities_source_publication')
            && migration_constraint_exists($conn, 'research_activities', 'fk_research_activities_source_project')
            && migration_constraint_exists($conn, 'research_activities', 'fk_research_activities_source_publication'),
        '072_activity_qr_checkins.sql' =>
            migration_column_exists($conn, 'activity_schedule_dates', 'checkin_token_hash')
            && strtolower(migration_column_type($conn, 'activity_schedule_dates', 'checkin_token_hash')) === 'char(64)'
            && migration_column_nullable($conn, 'activity_schedule_dates', 'checkin_token_hash')
            && migration_index_exists($conn, 'activity_schedule_dates', 'uq_activity_schedule_checkin_token')
            && migration_index_exists($conn, 'users', 'idx_users_student_id')
            && migration_table_exists($conn, 'activity_schedule_checkins')
            && migration_column_type($conn, 'activity_schedule_checkins', 'identity_hash') === 'char(64)'
            && migration_column_type($conn, 'activity_schedule_checkins', 'checked_in_at') === 'datetime'
            && migration_index_exists($conn, 'activity_schedule_checkins', 'uq_activity_schedule_checkin_identity')
            && migration_constraint_exists($conn, 'activity_schedule_checkins', 'fk_activity_schedule_checkins_date')
            && migration_constraint_exists($conn, 'activity_schedule_checkins', 'fk_activity_schedule_checkins_participant'),
        '073_refresh_staff_permissions.sql' =>
            migration_table_exists($conn, 'staff_permissions')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM staff_permissions WHERE module_key = 'publication_dissemination' AND subpage_key IN ('journal_publications', 'conference_presentations', 'research_repository')") === 0
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM staff_permissions source WHERE source.module_key = 'research_development' AND source.subpage_key = 'research_review' AND NOT EXISTS (SELECT 1 FROM staff_permissions destination WHERE destination.user_id = source.user_id AND destination.module_key = 'research_development' AND destination.subpage_key = 'research_engagement_records')") === 0,
        '074_activity_checkin_attendee_details.sql' =>
            migration_column_type($conn, 'activity_schedule_participants', 'attendee_affiliation') === "enum('student','faculty','staff','guest')"
            && migration_column_nullable($conn, 'activity_schedule_participants', 'attendee_affiliation')
            && strtolower(migration_column_type($conn, 'activity_schedule_participants', 'attendee_program_department')) === 'varchar(150)'
            && migration_column_nullable($conn, 'activity_schedule_participants', 'attendee_program_department'),
        '075_staff_activity_status_permissions.sql' =>
            migration_table_exists($conn, 'staff_permissions')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM staff_permissions WHERE module_key = 'research_capacity_building' AND subpage_key IN ('seminars', 'seminars_workshops', 'trainings', 'workshops')") === 0,
        '076_custom_activity_type.sql' =>
            strtolower(migration_column_type($conn, 'research_activities', 'activity_type'))
                === "enum('seminar_workshop','training','colloquium','conference_congress','other')"
            && strtolower(migration_column_type($conn, 'research_activities', 'custom_activity_type')) === 'varchar(100)'
            && migration_column_nullable($conn, 'research_activities', 'custom_activity_type'),
        '051_complete_faculty_academic_ranks.sql' => verify_faculty_academic_ranks($conn),
        '050_faculty_research_records.sql' => verify_faculty_research_records($conn),
        '049_faculty_document_profile_fields.sql' =>
            migration_column_type($conn, 'users', 'middle_name') === 'varchar(80)'
            && migration_column_nullable($conn, 'users', 'middle_name')
            && migration_column_type($conn, 'users', 'designation') === 'varchar(150)'
            && migration_column_nullable($conn, 'users', 'designation')
            && migration_column_type($conn, 'users', 'appointment_status') === 'varchar(80)'
            && migration_column_nullable($conn, 'users', 'appointment_status'),
        '048_activity_invitation_document.sql' =>
            migration_column_exists($conn, 'research_activities', 'document_upload_id')
            && strtolower(migration_column_type($conn, 'research_activities', 'document_upload_id')) === 'int(10) unsigned'
            && migration_column_nullable($conn, 'research_activities', 'document_upload_id')
            && migration_index_exists($conn, 'research_activities', 'uq_research_activities_document_upload')
            && migration_constraint_exists($conn, 'research_activities', 'fk_research_activities_document_upload'),
        '047_research_submission_annotation_colors.sql' =>
            migration_table_exists($conn, 'research_submission_annotations')
            && migration_column_type($conn, 'research_submission_annotations', 'highlight_color') === "enum('yellow','blue','green','pink','purple')"
            && !migration_column_nullable($conn, 'research_submission_annotations', 'highlight_color')
            && migration_default_matches(migration_column_default($conn, 'research_submission_annotations', 'highlight_color'), 'yellow'),
        '046_research_submission_annotations.sql' =>
            migration_table_exists($conn, 'research_submission_annotations')
            && migration_column_exists($conn, 'research_submission_annotations', 'version_id')
            && migration_column_exists($conn, 'research_submission_annotations', 'upload_id')
            && migration_column_exists($conn, 'research_submission_annotations', 'selected_text')
            && migration_column_exists($conn, 'research_submission_annotations', 'anchor_json')
            && migration_column_exists($conn, 'research_submission_annotations', 'comment_text')
            && migration_column_exists($conn, 'research_submission_annotations', 'reviewer_id')
            && migration_column_exists($conn, 'research_submission_annotations', 'updated_by')
            && migration_column_exists($conn, 'research_submission_annotations', 'created_at')
            && migration_column_exists($conn, 'research_submission_annotations', 'updated_at')
            && migration_index_exists($conn, 'research_submission_annotations', 'idx_submission_annotations_version_upload')
            && migration_index_exists($conn, 'research_submission_annotations', 'idx_submission_annotations_reviewer')
            && migration_index_exists($conn, 'research_submission_annotations', 'idx_submission_annotations_updated_by')
            && migration_constraint_exists($conn, 'research_submission_annotations', 'fk_submission_annotations_version_upload')
            && migration_constraint_exists($conn, 'research_submission_annotations', 'fk_submission_annotations_reviewer')
            && migration_constraint_exists($conn, 'research_submission_annotations', 'fk_submission_annotations_updated_by'),
        '045_title_option_research_classifications.sql' => verify_title_option_research_classifications($conn),
        '044_course_specific_research_categories.sql' => verify_course_specific_research_categories($conn),
        '042_faculty_program_assignments.sql' => verify_faculty_program_assignments($conn),
        '033_repair_final_manuscript_submission_type.sql' => verify_final_manuscript_type_repair($conn),
        '036_activity_budgets_and_notices.sql' =>
            migration_table_exists($conn, 'activity_budgets')
            && migration_table_exists($conn, 'activity_budget_expenses')
            && migration_table_exists($conn, 'activity_budget_revisions')
            && migration_table_exists($conn, 'activity_contribution_responses')
            && migration_table_exists($conn, 'activity_budget_notices')
            && migration_table_exists($conn, 'activity_budget_notice_deliveries')
            && migration_column_exists($conn, 'notifications', 'activity_budget_delivery_key')
            && migration_index_exists($conn, 'notifications', 'uq_notifications_budget_delivery')
            && migration_column_exists($conn, 'activity_budgets', 'activity_format')
            && migration_column_exists($conn, 'activity_budgets', 'funding_source')
            && migration_column_exists($conn, 'activity_budgets', 'budget_status')
            && migration_column_exists($conn, 'activity_budgets', 'organizational_allocation')
            && migration_column_exists($conn, 'activity_budgets', 'contribution_prompt')
            && migration_column_exists($conn, 'activity_budget_expenses', 'schedule_id')
            && migration_column_exists($conn, 'activity_budget_expenses', 'organizational_actual_amount')
            && migration_column_exists($conn, 'activity_budget_expenses', 'participant_actual_charge')
            && migration_column_exists($conn, 'activity_budget_revisions', 'previous_values')
            && migration_column_exists($conn, 'activity_budget_revisions', 'new_values')
            && migration_column_exists($conn, 'activity_contribution_responses', 'response')
            && migration_column_exists($conn, 'activity_budget_notices', 'confirmation_key')
            && migration_column_exists($conn, 'activity_budget_notices', 'confirmed_at')
            && migration_index_exists($conn, 'activity_budget_notices', 'uq_activity_budget_notice_confirmation')
            && migration_column_exists($conn, 'activity_budget_notice_deliveries', 'delivery_key')
            && migration_column_exists($conn, 'activity_budget_notice_deliveries', 'status')
            && migration_index_exists($conn, 'activity_schedule_dates', 'uq_activity_schedule_activity_schedule')
            && migration_constraint_exists($conn, 'activity_budget_expenses', 'fk_activity_budget_expenses_budget_activity')
            && migration_constraint_exists($conn, 'activity_budget_expenses', 'fk_activity_budget_expenses_activity_schedule')
            && migration_constraint_exists($conn, 'activity_budget_notice_deliveries', 'fk_activity_budget_notice_delivery_notification'),
        '037_repair_activity_schedule_notification_flag.sql' =>
            migration_column_exists($conn, 'activity_schedule_participants', 'is_notified')
            && migration_default_matches(migration_column_default($conn, 'activity_schedule_participants', 'is_notified'), '0'),
        '038_repair_activity_type_status_enums.sql' =>
            migration_enum_contains($conn, 'research_activities', 'activity_type', 'colloquium')
            && (migration_enum_contains($conn, 'research_activities', 'activity_type', 'conference')
                || migration_enum_contains($conn, 'research_activities', 'activity_type', 'conference_congress'))
            && (migration_default_matches(migration_column_default($conn, 'research_activities', 'status'), 'incoming')
                || migration_default_matches(migration_column_default($conn, 'research_activities', 'status'), 'upcoming'))
            && (migration_enum_contains($conn, 'research_activities', 'status', 'incoming')
                || migration_enum_contains($conn, 'research_activities', 'status', 'upcoming'))
            && migration_enum_contains($conn, 'research_activities', 'status', 'ongoing')
            && migration_enum_contains($conn, 'research_activities', 'status', 'conducted')
            && migration_enum_contains($conn, 'research_activities', 'status', 'cancelled')
            && migration_enum_contains($conn, 'research_activities', 'status', 'scheduled')
            && migration_enum_contains($conn, 'research_activities', 'status', 'completed'),
        '040_rename_incoming_activity_status.sql' =>
            migration_default_matches(migration_column_default($conn, 'research_activities', 'status'), 'upcoming')
            && migration_enum_contains($conn, 'research_activities', 'status', 'upcoming')
            && !migration_enum_contains($conn, 'research_activities', 'status', 'incoming')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM research_activities WHERE status = 'incoming'") === 0,
        '041_rename_upcoming_activity_permission_keys.sql' =>
            migration_table_exists($conn, 'staff_permissions')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM staff_permissions WHERE subpage_key IN ('add_incoming_activity', 'incoming_activities')") === 0,
        '039_message_conversation_history.sql' =>
            migration_column_exists($conn, 'messages', 'history_id')
            && strtolower(migration_column_type($conn, 'messages', 'history_id')) === 'int(10) unsigned'
            && migration_column_exists($conn, 'messages', 'thread_id')
            && migration_index_exists($conn, 'messages', 'PRIMARY')
            && migration_index_exists($conn, 'messages', 'idx_messages_thread_created')
            && migration_default_matches(migration_column_default($conn, 'messages', 'message_id'), '0')
            && (int) migration_scalar($conn, 'SELECT COUNT(*) FROM messages WHERE thread_id IS NULL') === 0,
        '035_activity_custom_audience.sql' =>
            migration_table_exists($conn, 'activity_schedule_dates')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'all')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'student')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'faculty')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'staff')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'specific')
            && migration_enum_contains($conn, 'activity_schedule_dates', 'audience_scope', 'custom')
            && migration_default_matches(migration_column_default($conn, 'activity_schedule_dates', 'audience_scope'), 'all')
            && migration_column_exists($conn, 'activity_schedule_dates', 'audience_roles')
            && migration_column_nullable($conn, 'activity_schedule_dates', 'audience_roles')
            && in_array(strtolower(migration_column_type($conn, 'activity_schedule_dates', 'audience_roles')), ['json', 'longtext'], true)
            && migration_column_exists($conn, 'activity_schedule_dates', 'audience_user_ids')
            && migration_column_nullable($conn, 'activity_schedule_dates', 'audience_user_ids')
            && in_array(strtolower(migration_column_type($conn, 'activity_schedule_dates', 'audience_user_ids')), ['json', 'longtext'], true),
        '034_activity_multi_day_schedule.sql' =>
            migration_column_exists($conn, 'research_activities', 'is_multi_day')
            && migration_column_exists($conn, 'research_activities', 'schedule_start_date')
            && migration_column_exists($conn, 'research_activities', 'schedule_end_date')
            && migration_enum_contains($conn, 'research_activities', 'activity_type', 'colloquium')
            && (migration_enum_contains($conn, 'research_activities', 'activity_type', 'conference')
                || migration_enum_contains($conn, 'research_activities', 'activity_type', 'conference_congress'))
            && (migration_enum_contains($conn, 'research_activities', 'status', 'incoming')
                || migration_enum_contains($conn, 'research_activities', 'status', 'upcoming'))
            && migration_enum_contains($conn, 'research_activities', 'status', 'conducted')
            && migration_table_exists($conn, 'activity_schedule_dates')
            && migration_column_exists($conn, 'activity_schedule_dates', 'schedule_date')
            && migration_column_exists($conn, 'activity_schedule_dates', 'start_time')
            && migration_column_exists($conn, 'activity_schedule_dates', 'end_time')
            && migration_column_exists($conn, 'activity_schedule_dates', 'venue_override')
            && migration_column_exists($conn, 'activity_schedule_dates', 'speaker_override')
            && migration_column_exists($conn, 'activity_schedule_dates', 'audience_scope')
            && migration_column_exists($conn, 'activity_schedule_dates', 'status')
            && migration_index_exists($conn, 'activity_schedule_dates', 'uq_activity_schedule_date')
            && migration_constraint_exists($conn, 'activity_schedule_dates', 'fk_activity_schedule_dates_activity')
            && migration_table_exists($conn, 'activity_schedule_participants')
            && migration_column_exists($conn, 'activity_schedule_participants', 'user_id')
            && migration_column_exists($conn, 'activity_schedule_participants', 'participant_name')
            && migration_column_exists($conn, 'activity_schedule_participants', 'role')
            && migration_column_exists($conn, 'activity_schedule_participants', 'attended')
            && migration_column_exists($conn, 'activity_schedule_participants', 'is_invited')
            && migration_column_exists($conn, 'activity_schedule_participants', 'is_notified')
            && migration_index_exists($conn, 'activity_schedule_participants', 'uq_activity_schedule_participant_user')
            && migration_constraint_exists($conn, 'activity_schedule_participants', 'fk_activity_schedule_participants_date'),
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
        '032_auth_security_hardening.sql' =>
            migration_table_exists($conn, 'login_rate_limits')
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM users WHERE email_verified = 0 AND email_verification_token IS NULL") === 0,
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
        '020_publication_custom_type_name.sql' =>
            migration_column_exists($conn, 'publications', 'publication_type_name'),
        '021_staff_download_permissions.sql' =>
            !migration_table_exists($conn, 'staff_permissions') ? false
            : (!migration_column_exists($conn, 'staff_permissions', 'download_allowed') ? false
                : (strtolower(migration_column_type($conn, 'staff_permissions', 'download_allowed')) === 'tinyint(1)'
                    && migration_column_default($conn, 'staff_permissions', 'download_allowed') === '0' ? true : null)),
        '055_archive_contributor_storage.sql' =>
            migration_column_exists($conn, 'publication_contributors', 'contribution')
            && migration_column_exists($conn, 'publication_contributors', 'is_primary')
            && migration_column_exists($conn, 'copyright_contributors', 'contribution')
            && migration_column_exists($conn, 'copyright_contributors', 'is_primary')
            && migration_index_exists($conn, 'publication_contributors', 'idx_publication_contributors_user')
            && migration_index_exists($conn, 'copyright_contributors', 'idx_copyright_contributors_user')
            && migration_constraint_exists($conn, 'publication_contributors', 'fk_publication_contributors_publication')
            && migration_constraint_exists($conn, 'publication_contributors', 'fk_publication_contributors_user')
            && migration_constraint_exists($conn, 'copyright_contributors', 'fk_copyright_contributors_application')
            && migration_constraint_exists($conn, 'copyright_contributors', 'fk_copyright_contributors_user'),
        '060_publication_classification_metadata.sql' =>
            migration_table_exists($conn, 'publication_types')
            && migration_table_exists($conn, 'publication_subtypes')
            && migration_table_exists($conn, 'publication_scopes')
            && migration_table_exists($conn, 'publication_form_rules')
            && migration_column_exists($conn, 'publication_form_rules', 'manuscript_required')
            && migration_column_exists($conn, 'publications', 'publication_type_id')
            && migration_column_exists($conn, 'publications', 'publication_subtype_id')
            && migration_column_exists($conn, 'publications', 'publication_scope_id')
            && migration_column_exists($conn, 'publications', 'publication_metadata_json')
            && migration_column_exists($conn, 'publications', 'abstract_text')
            && migration_column_exists($conn, 'publications', 'authors_json')
            && migration_column_exists($conn, 'publications', 'keywords_json')
            && migration_column_exists($conn, 'publications', 'evidence_file_path')
            && migration_column_exists($conn, 'publications', 'evidence_file_name')
            && migration_column_exists($conn, 'publications', 'evidence_url')
            && migration_column_exists($conn, 'publications', 'references_file_path')
            && migration_column_exists($conn, 'publications', 'references_file_name')
            && migration_index_exists($conn, 'publications', 'idx_publications_type_id')
            && migration_index_exists($conn, 'publications', 'idx_publications_subtype_id')
            && migration_index_exists($conn, 'publications', 'idx_publications_scope_id')
            && migration_constraint_exists($conn, 'publication_subtypes', 'fk_publication_subtypes_type')
            && migration_constraint_exists($conn, 'publication_form_rules', 'fk_publication_form_rules_subtype')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_classification_type')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_classification_subtype')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_scope')
            && (int) migration_scalar($conn, "SELECT COUNT(DISTINCT type_code) FROM publication_types WHERE type_code IN ('journal_article','conference_proceedings_paper','book','book_chapter_scholarly','research_monograph')") >= 5
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM publication_subtypes WHERE subtype_code='short_conference_paper'") >= 1
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM publication_scopes WHERE scope_code='local_institutional'") === 1
            // Rules are administrator-configurable after migration; verify existence, not original values.
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM publication_form_rules r JOIN publication_subtypes s USING (publication_subtype_id) WHERE s.subtype_code='short_conference_paper'") >= 1,
        '061_publication_research_area.sql' =>
            migration_column_exists($conn, 'publications', 'research_area_id')
            && migration_index_exists($conn, 'publications', 'idx_publications_research_area')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_research_area'),
        '054_archival_publications_copyrights.sql' =>
            migration_column_exists($conn, 'publications', 'archival_status')
            && migration_column_exists($conn, 'publications', 'encoded_by')
            && migration_column_exists($conn, 'publications', 'last_modified_by')
            && migration_column_exists($conn, 'publications', 'deactivated_at')
            && migration_column_exists($conn, 'publications', 'volume')
            && migration_column_exists($conn, 'publications', 'issue')
            && migration_column_exists($conn, 'publications', 'page_numbers')
            && migration_column_exists($conn, 'publications', 'publication_url')
            && migration_column_exists($conn, 'publications', 'indexing_information')
            && migration_column_exists($conn, 'publications', 'sdg_alignment')
            && migration_column_exists($conn, 'publications', 'remarks')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_encoder')
            && migration_constraint_exists($conn, 'publications', 'fk_publications_editor')
            && migration_index_exists($conn, 'publications', 'idx_publications_archival')
            && migration_column_exists($conn, 'copyright_applications', 'archival_status')
            && migration_column_exists($conn, 'copyright_applications', 'external_status')
            && migration_column_exists($conn, 'copyright_applications', 'encoded_by')
            && migration_column_exists($conn, 'copyright_applications', 'last_modified_by')
            && migration_column_exists($conn, 'copyright_applications', 'deactivated_at')
            && migration_column_exists($conn, 'copyright_applications', 'remarks')
            && migration_constraint_exists($conn, 'copyright_applications', 'fk_copyright_encoder')
            && migration_constraint_exists($conn, 'copyright_applications', 'fk_copyright_editor')
            && migration_index_exists($conn, 'copyright_applications', 'idx_copyright_archival')
            && migration_column_exists($conn, 'activity_log', 'details')
            && migration_enum_contains($conn, 'publications', 'archival_status', 'archived')
            && migration_enum_contains($conn, 'copyright_applications', 'external_status', 'registered'),
        '043_publication_ip_contributors.sql' =>
            migration_table_exists($conn, 'publication_contributors')
            && migration_table_exists($conn, 'copyright_contributors')
            && migration_column_exists($conn, 'publication_contributors', 'contribution')
            && migration_column_exists($conn, 'copyright_contributors', 'contribution')
            && migration_index_exists($conn, 'publication_contributors', 'idx_publication_contributors_user')
            && migration_index_exists($conn, 'copyright_contributors', 'idx_copyright_contributors_user')
            && migration_constraint_exists($conn, 'publication_contributors', 'fk_publication_contributors_publication')
            && migration_constraint_exists($conn, 'publication_contributors', 'fk_publication_contributors_user')
            && migration_constraint_exists($conn, 'copyright_contributors', 'fk_copyright_contributors_application')
            && migration_constraint_exists($conn, 'copyright_contributors', 'fk_copyright_contributors_user'),
        '022_application_settings.sql' => migration_table_exists($conn, 'application_settings')
            && migration_column_exists($conn, 'application_settings', 'setting_key')
            && migration_column_exists($conn, 'application_settings', 'setting_value')
            && migration_column_exists($conn, 'application_settings', 'updated_by')
            && migration_column_exists($conn, 'application_settings', 'updated_at'),
        '023_research_basics_completion.sql' =>
            migration_column_exists($conn, 'users', 'research_basics_completed')
            && strtolower(migration_column_type($conn, 'users', 'research_basics_completed')) === 'tinyint(1)'
            && migration_column_default($conn, 'users', 'research_basics_completed') === '0',
        '024_faculty_research_engagement.sql' =>
            migration_table_exists($conn, 'faculty_research_profiles')
            && migration_table_exists($conn, 'faculty_engagement_records')
            && migration_column_nullable($conn, 'uploads', 'project_id')
            && migration_enum_contains($conn, 'uploads', 'type', 'engagement'),
        '056_add_custom_engagement_type.sql' =>
            migration_column_exists($conn, 'faculty_engagement_records', 'custom_engagement_type')
            && strtolower(migration_column_type($conn, 'faculty_engagement_records', 'custom_engagement_type')) === 'varchar(255)'
            && migration_column_nullable($conn, 'faculty_engagement_records', 'custom_engagement_type'),
        '057_student_adviser_requests.sql' =>
            migration_table_exists($conn, 'adviser_requests')
            && migration_column_exists($conn, 'adviser_requests', 'request_id')
            && migration_column_exists($conn, 'adviser_requests', 'project_id')
            && migration_column_exists($conn, 'adviser_requests', 'requester_id')
            && migration_column_exists($conn, 'adviser_requests', 'faculty_id')
            && migration_column_exists($conn, 'adviser_requests', 'upload_id')
            && migration_column_type($conn, 'adviser_requests', 'status') === "enum('pending','accepted','declined')"
            && !migration_column_nullable($conn, 'adviser_requests', 'status')
            && migration_default_matches(migration_column_default($conn, 'adviser_requests', 'status'), 'pending')
            && str_contains(strtolower(migration_column_extra($conn, 'adviser_requests', 'pending_project_id')), 'stored generated')
            && migration_column_exists($conn, 'adviser_requests', 'created_at')
            && migration_column_exists($conn, 'adviser_requests', 'updated_at')
            && migration_index_exists($conn, 'adviser_requests', 'uq_adviser_requests_pending_project')
            && migration_index_exists($conn, 'adviser_requests', 'idx_adviser_requests_faculty_status')
            && migration_index_exists($conn, 'adviser_requests', 'idx_adviser_requests_project_created')
            && migration_index_exists($conn, 'adviser_requests', 'idx_adviser_requests_requester')
            && migration_constraint_exists($conn, 'adviser_requests', 'fk_adviser_requests_project')
            && migration_constraint_exists($conn, 'adviser_requests', 'fk_adviser_requests_requester')
            && migration_constraint_exists($conn, 'adviser_requests', 'fk_adviser_requests_faculty')
            && migration_constraint_exists($conn, 'adviser_requests', 'fk_adviser_requests_upload')
            && migration_table_exists($conn, 'adviser_request_messages')
            && migration_column_exists($conn, 'adviser_request_messages', 'message_id')
            && migration_column_exists($conn, 'adviser_request_messages', 'request_id')
            && migration_column_exists($conn, 'adviser_request_messages', 'sender_id')
            && migration_column_exists($conn, 'adviser_request_messages', 'message')
            && migration_column_exists($conn, 'adviser_request_messages', 'created_at')
            && migration_column_exists($conn, 'adviser_request_messages', 'read_at')
            && migration_index_exists($conn, 'adviser_request_messages', 'idx_adviser_request_messages_thread')
            && migration_index_exists($conn, 'adviser_request_messages', 'idx_adviser_request_messages_sender')
            && migration_constraint_exists($conn, 'adviser_request_messages', 'fk_adviser_request_messages_request')
            && migration_constraint_exists($conn, 'adviser_request_messages', 'fk_adviser_request_messages_sender'),
        '025_final_manuscript_completion_gate.sql' =>
            migration_enum_contains($conn, 'research_submission_versions', 'submission_type', 'final_manuscript')
            && (int) migration_scalar(
                $conn,
                "SELECT COUNT(*)
                   FROM research_projects rp
                  WHERE rp.status IN ('completed', 'archived')
                    AND NOT EXISTS (
                        SELECT 1
                          FROM research_submission_versions v
                         WHERE v.project_id = rp.project_id
                           AND v.submission_type = 'final_manuscript'
                           AND v.chapter_id IS NULL
                           AND v.status = 'approved'
                    )"
            ) === 0,
        '026_backfill_legacy_title_submissions.sql' => verify_legacy_title_submission_backfill($conn),
        '027_submission_review_recommendation.sql' =>
            migration_column_exists($conn, 'research_submission_reviews', 'recommendation')
            && strtolower(migration_column_type($conn, 'research_submission_reviews', 'recommendation')) === 'text'
            && migration_column_nullable($conn, 'research_submission_reviews', 'recommendation'),
        '028_add_college_professor_academic_rank.sql' =>
            migration_enum_contains($conn, 'users', 'academic_rank', 'College Professor'),
        '030_three_title_proposals_and_research_areas.sql' =>
            migration_column_exists($conn, 'research_projects', 'title_option_1')
            && migration_column_exists($conn, 'research_projects', 'title_option_2')
            && migration_column_exists($conn, 'research_projects', 'title_option_3')
            && migration_column_exists($conn, 'research_submission_reviews', 'selected_title')
            && migration_table_exists($conn, 'research_areas')
            && migration_table_exists($conn, 'category_research_areas')
            && migration_table_exists($conn, 'research_project_areas')
            && (int) migration_scalar($conn, 'SELECT COUNT(*) FROM research_areas WHERE area_code BETWEEN \'RA001\' AND \'RA040\'') === 40,
        '031_curated_bsit_bscs_research_areas.sql' =>
            migration_table_exists($conn, 'research_area_selection_scope')
            && migration_column_exists($conn, 'research_area_selection_scope', 'display_label')
            && migration_column_exists($conn, 'research_area_selection_scope', 'is_enabled')
            && migration_column_exists($conn, 'research_area_selection_scope', 'display_order')
            && (int) migration_scalar($conn, 'SELECT COUNT(*) FROM research_area_selection_scope') >= 20
            && (int) migration_scalar($conn, "SELECT COUNT(*) FROM research_areas WHERE area_code BETWEEN 'RA001' AND 'RA040'") === 40,
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

function failed_migration_reconciliation_allowed(array $existing, string $checksum, ?bool $verified): bool
{
    return ($existing['status'] ?? null) === 'failed'
        && hash_equals((string) ($existing['checksum'] ?? ''), $checksum)
        && $verified === true;
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
        if (!hash_equals((string) $existing['checksum'], $checksum)) {
            migration_fail("Checksum changed for failed migration {$filename}; refusing reconciliation.");
        }
        if (!failed_migration_reconciliation_allowed($existing, $checksum, verify_migration($conn, $filename))) {
            migration_fail("{$filename} has a recorded failed attempt and its complete resulting schema is not verified; refusing reconciliation.");
        }
        record_applied($conn, $filename, $checksum, 'verified_existing', 0.0);
        echo "RECONCILED {$filename} (verified existing schema; SQL not rerun)" . PHP_EOL;
        return;
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
