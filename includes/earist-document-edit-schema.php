<?php
/** Read-only migration 067 verifier; checks the full edit-session schema. */
require_once __DIR__ . '/earist-document-schema.php';

function earistDocumentEditSchemaStatus(mysqli $conn): bool
{
    // Migration 067 extends the append-only generated-document history table.
    // Reuse its strict verifier so partial or malformed parent metadata fails closed.
    if (empty(earistDocumentSchemaStatus($conn)['valid'])) return false;

    $rows = static function (string $sql, string $table) use ($conn): ?array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        if (!$result) { $stmt->close(); return null; }
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    };

    $table = 'earist_document_edit_sessions';
    $storage = $rows('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table);
    if (!$storage || count($storage) !== 1 || $storage[0]['ENGINE'] !== 'InnoDB' || $storage[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') return false;

    $expectedColumns = [
        'session_id' => ['char(64)', false, null],
        'generated_document_id' => ['int unsigned', false, null],
        'owner_id' => ['int unsigned', false, null],
        'file_selector' => ["enum('primary','sdg_continuation')", false, null],
        'editor_key' => ['char(64)', false, null],
        'source_sha256' => ['char(64)', false, null],
        'read_token_sha256' => ['char(64)', false, null],
        'read_token_expires_at' => ['datetime', false, null],
        'state' => ["enum('active','saved','closed','expired','failed')", false, null],
        'active_lock_generated_document_id' => ['int unsigned', true, null],
        'expires_at' => ['datetime', false, null],
        'saved_generated_document_id' => ['int unsigned', true, null],
        'created_at' => ['datetime', false, null],
        'updated_at' => ['datetime', false, null],
    ];
    $actualColumns = [];
    $columnRows = $rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table);
    if ($columnRows === null || count($columnRows) !== count($expectedColumns)) return false;
    foreach ($columnRows as $column) {
        $actualColumns[$column['COLUMN_NAME']] = $column;
    }
    foreach ($expectedColumns as $name => [$type, $nullable, $default]) {
        $column = $actualColumns[$name] ?? null;
        if (!$column) return false;
        $actualType = preg_replace('/\\b(int|bigint)\\(\\d+\\)/', '$1', strtolower($column['COLUMN_TYPE']));
        $actualDefault = $column['COLUMN_DEFAULT'];
        if ($actualDefault === 'NULL') $actualDefault = null;
        if ($actualDefault === 'current_timestamp') $actualDefault = 'current_timestamp()';
        if ($actualType !== $type || ($column['IS_NULLABLE'] === 'YES') !== $nullable
            || $actualDefault !== $default || $column['EXTRA'] !== ''
            || ($column['COLLATION_NAME'] !== null && $column['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')) return false;
    }

    $expectedIndexes = [
        'PRIMARY' => ['session_id', 0],
        'uq_earist_edit_active_lock' => ['active_lock_generated_document_id', 0],
        // InnoDB creates this supporting index from the saved-document foreign key.
        'fk_earist_edit_saved' => ['saved_generated_document_id', 1],
        'idx_earist_edit_owner_state' => ['owner_id,state,expires_at', 1],
        'idx_earist_edit_generated' => ['generated_document_id,created_at', 1],
    ];
    $indexes = [];
    $indexRows = $rows('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table);
    if ($indexRows === null) return false;
    foreach ($indexRows as $index) {
        $name = $index['INDEX_NAME'];
        if (!isset($indexes[$name])) $indexes[$name] = ['columns' => [], 'non_unique' => (int) $index['NON_UNIQUE'], 'has_prefix' => false];
        $indexes[$name]['columns'][] = $index['COLUMN_NAME'];
        $indexes[$name]['has_prefix'] = $indexes[$name]['has_prefix'] || $index['SUB_PART'] !== null;
    }
    if (count($indexes) !== count($expectedIndexes)) return false;
    foreach ($expectedIndexes as $name => [$columns, $nonUnique]) {
        $index = $indexes[$name] ?? null;
        if (!$index || implode(',', $index['columns']) !== $columns || $index['non_unique'] !== $nonUnique || $index['has_prefix']) return false;
    }

    $expectedForeignKeys = [
        'fk_earist_edit_generated' => ['generated_document_id', 'earist_generated_documents', 'generated_document_id'],
        'fk_earist_edit_owner' => ['owner_id', 'users', 'user_id'],
        'fk_earist_edit_active_lock' => ['active_lock_generated_document_id', 'earist_generated_documents', 'generated_document_id'],
        'fk_earist_edit_saved' => ['saved_generated_document_id', 'earist_generated_documents', 'generated_document_id'],
    ];
    $foreignKeys = [];
    $foreignRows = $rows('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.DELETE_RULE, r.UPDATE_RULE, (k.REFERENCED_TABLE_SCHEMA = DATABASE()) AS same_schema FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', $table);
    if ($foreignRows === null) return false;
    foreach ($foreignRows as $foreign) {
        $name = $foreign['CONSTRAINT_NAME'];
        $definition = [$foreign['COLUMN_NAME'], $foreign['REFERENCED_TABLE_NAME'], $foreign['REFERENCED_COLUMN_NAME'], $foreign['DELETE_RULE'], $foreign['UPDATE_RULE'], (bool) $foreign['same_schema']];
        $foreignKeys[$name] = isset($foreignKeys[$name]) ? [] : $definition;
        if ((int) $foreign['ORDINAL_POSITION'] !== 1) $foreignKeys[$name] = [];
    }
    if (count($foreignKeys) !== count($expectedForeignKeys)) return false;
    foreach ($expectedForeignKeys as $name => [$column, $target, $targetColumn]) {
        if (($foreignKeys[$name] ?? null) !== [$column, $target, $targetColumn, 'RESTRICT', 'RESTRICT', true]) return false;
    }

    $expectedChecks = [
        'chk_earist_edit_ids' => "session_id REGEXP '^[0-9a-f]{64}$' AND editor_key REGEXP '^[0-9a-f]{64}$' AND source_sha256 REGEXP '^[0-9a-f]{64}$' AND read_token_sha256 REGEXP '^[0-9a-f]{64}$'",
        'chk_earist_edit_state' => "(state = 'active' AND active_lock_generated_document_id = generated_document_id AND saved_generated_document_id IS NULL) OR (state <> 'active' AND active_lock_generated_document_id IS NULL)",
    ];
    $checks = [];
    $checkRows = $rows('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table);
    if ($checkRows === null) return false;
    foreach ($checkRows as $check) $checks[$check['CONSTRAINT_NAME']] = $check['CHECK_CLAUSE'];
    if (count($checks) !== count($expectedChecks)) return false;
    foreach ($expectedChecks as $name => $expression) {
        $actualTree = isset($checks[$name]) ? earistSchemaCheckTree($checks[$name]) : null;
        if ($actualTree === null || $actualTree !== earistSchemaCheckTree($expression)) return false;
    }

    return true;
}
