<?php
/** Read-only migration 068 verifier for Google Drive connection/file metadata. */

function earistGoogleDriveSchemaStatus(mysqli $conn): bool
{
    $expected = [
        'earist_google_drive_connections' => [
            'columns' => [
                'owner_id' => ['int unsigned', false], 'google_subject' => ['varchar(255)', false],
                'google_email' => ['varchar(255)', false], 'refresh_token_ciphertext' => ['text', false],
                'connected_at' => ['datetime', false], 'updated_at' => ['datetime', false],
            ],
            'indexes' => ['PRIMARY' => ['owner_id', 0]],
            'foreign_keys' => ['fk_earist_google_connection_owner' => ['owner_id', 'users', 'user_id']],
        ],
        'earist_google_drive_files' => [
            'columns' => [
                'drive_file_id' => ['varchar(128)', false], 'owner_id' => ['int unsigned', false],
                'google_subject' => ['varchar(255)', false], 'generated_document_id' => ['int unsigned', false],
                'file_selector' => ["enum('primary','sdg_continuation')", false], 'file_name' => ['varchar(255)', false],
                'web_view_link' => ['varchar(512)', false], 'drive_version' => ['varchar(64)', true],
                'last_imported_drive_version' => ['varchar(64)', true],
                'last_imported_generated_document_id' => ['int unsigned', true],
                'created_at' => ['datetime', false], 'updated_at' => ['datetime', false], 'last_imported_at' => ['datetime', true],
            ],
            'indexes' => [
                'PRIMARY' => ['drive_file_id', 0],
                // InnoDB creates this supporting index for the restrictive FK.
                'fk_earist_google_file_source' => ['generated_document_id', 1],
                'idx_earist_google_file_owner_source' => ['owner_id,generated_document_id,file_selector,created_at', 1],
                'idx_earist_google_file_imported' => ['last_imported_generated_document_id', 1],
            ],
            'foreign_keys' => [
                'fk_earist_google_file_owner' => ['owner_id', 'users', 'user_id'],
                'fk_earist_google_file_source' => ['generated_document_id', 'earist_generated_documents', 'generated_document_id'],
                'fk_earist_google_file_imported' => ['last_imported_generated_document_id', 'earist_generated_documents', 'generated_document_id'],
            ],
        ],
    ];

    foreach ($expected as $table => $definition) {
        $stmt = $conn->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $storage = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$storage || $storage['ENGINE'] !== 'InnoDB' || $storage['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') return false;

        $stmt = $conn->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) !== count($definition['columns'])) return false;
        foreach ($rows as $row) {
            if (!isset($definition['columns'][$row['COLUMN_NAME']])) return false;
        }
        foreach ($definition['columns'] as $name => [$type, $nullable]) {
            $row = null;
            foreach ($rows as $candidate) if ($candidate['COLUMN_NAME'] === $name) { $row = $candidate; break; }
            if (!$row) return false;
            $actualType = preg_replace('/\\b(int|bigint)\\(\\d+\\)/', '$1', strtolower($row['COLUMN_TYPE']));
            $default = $row['COLUMN_DEFAULT'] === 'NULL' ? null : $row['COLUMN_DEFAULT'];
            if ($actualType !== $type || ($row['IS_NULLABLE'] === 'YES') !== $nullable || $default !== null
                || $row['EXTRA'] !== '' || ($row['COLLATION_NAME'] !== null && $row['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')) return false;
        }

        $stmt = $conn->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $indexes = [];
        foreach ($rows as $row) {
            $name = $row['INDEX_NAME'];
            if (!isset($indexes[$name])) $indexes[$name] = ['columns' => [], 'non_unique' => (int) $row['NON_UNIQUE'], 'prefix' => false];
            $indexes[$name]['columns'][] = $row['COLUMN_NAME'];
            $indexes[$name]['prefix'] = $indexes[$name]['prefix'] || $row['SUB_PART'] !== null;
        }
        if (count($indexes) !== count($definition['indexes'])) return false;
        foreach ($definition['indexes'] as $name => [$columns, $nonUnique]) {
            $index = $indexes[$name] ?? null;
            if (!$index || implode(',', $index['columns']) !== $columns || $index['non_unique'] !== $nonUnique || $index['prefix']) return false;
        }

        $stmt = $conn->prepare('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.DELETE_RULE, r.UPDATE_RULE, (k.REFERENCED_TABLE_SCHEMA = DATABASE()) AS same_schema FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION');
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $foreignKeys = [];
        foreach ($rows as $row) {
            if ((int) $row['ORDINAL_POSITION'] !== 1 || isset($foreignKeys[$row['CONSTRAINT_NAME']])) return false;
            $foreignKeys[$row['CONSTRAINT_NAME']] = [$row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME'], $row['DELETE_RULE'], $row['UPDATE_RULE'], (bool) $row['same_schema']];
        }
        if (count($foreignKeys) !== count($definition['foreign_keys'])) return false;
        foreach ($definition['foreign_keys'] as $name => [$column, $target, $targetColumn]) {
            if (($foreignKeys[$name] ?? null) !== [$column, $target, $targetColumn, 'RESTRICT', 'RESTRICT', true]) return false;
        }
    }
    return true;
}
