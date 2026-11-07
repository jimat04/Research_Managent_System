<?php
/** Migration 052 metadata verifier. No bootstrap, DDL, DML, sessions or file writes. */
function earistDocumentSchemaDefinitions(): array
{
    $types = "enum('annex_a','annex_b','annex_c','annex_d','annex_e','annex_f','qsf_rds_019')";
    $shared = [
        'research_record_id' => ['int unsigned', false], 'owner_id' => ['int unsigned', false],
        'document_type' => [$types, false], 'draft_revision' => ['int unsigned', false],
        'source_record_version' => ['int unsigned', false], 'source_fingerprint' => ['char(64)', false],
        'input_schema_version' => ['int unsigned', false], 'mapping_definition_version' => ['int unsigned', false],
        'binding_version' => ['int unsigned', false], 'template_sha256' => ['char(64)', false],
    ];
    return [
        'earist_document_drafts' => [
            'columns' => array_merge($shared, [
                'draft_id' => ['int unsigned', false, null, 'auto_increment'],
                'draft_revision' => ['int unsigned', false, '1'],
                'inputs_json' => ['longtext', false], 'created_by' => ['int unsigned', false],
                'updated_by' => ['int unsigned', false],
                'created_at' => ['datetime', false, 'current_timestamp()'],
                'updated_at' => ['datetime', false, 'current_timestamp()', 'on update current_timestamp()'],
            ]),
            'indexes' => [
                'PRIMARY' => ['draft_id', 0],
                'uq_earist_draft_source' => ['research_record_id,owner_id,document_type', 0],
                'idx_earist_draft_owner_updated' => ['owner_id,updated_at,draft_id', 1],
                'idx_earist_draft_creator' => ['created_by', 1], 'idx_earist_draft_updater' => ['updated_by', 1],
            ],
            'foreign_keys' => [
                'fk_earist_draft_record' => ['research_record_id', 'faculty_research_records', 'research_record_id'],
                'fk_earist_draft_owner' => ['owner_id', 'users', 'user_id'],
                'fk_earist_draft_creator' => ['created_by', 'users', 'user_id'],
                'fk_earist_draft_updater' => ['updated_by', 'users', 'user_id'],
            ],
            'checks' => [
                'chk_earist_draft_json' => 'JSON_VALID(inputs_json)',
                'chk_earist_draft_versions' => 'draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0',
                'chk_earist_draft_hashes' => "source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$'",
            ],
        ],
        'earist_generated_documents' => [
            'columns' => array_merge($shared, [
                'generated_document_id' => ['int unsigned', false, null, 'auto_increment'],
                'draft_id' => ['int unsigned', false], 'document_version' => ['int unsigned', false],
                'renderer_version' => ['varchar(32)', false], 'snapshot_json' => ['longtext', false],
                'missing_fields_json' => ['longtext', false], 'warnings_json' => ['longtext', false],
                'warnings_acknowledged_at' => ['datetime', true],
                'preparation_mode' => ["enum('incomplete','prepared_for_printing')", false],
                'generated_by' => ['int unsigned', false], 'generated_at' => ['datetime', false, 'current_timestamp()'],
                'request_token_hash' => ['char(64)', false], 'verified_by' => ['int unsigned', true],
                'verified_at' => ['datetime', true], 'verified_payload_sha256' => ['char(64)', true],
                'docx_path' => ['varchar(255)', false], 'docx_sha256' => ['char(64)', false],
                'docx_size' => ['bigint unsigned', false], 'pdf_path' => ['varchar(255)', true],
                'pdf_sha256' => ['char(64)', true], 'pdf_size' => ['bigint unsigned', true],
                'pdf_created_at' => ['datetime', true], 'pdf_converter_version' => ['varchar(120)', true],
            ]),
            'indexes' => [
                'PRIMARY' => ['generated_document_id', 0],
                'uq_earist_generated_version' => ['research_record_id,document_type,document_version', 0],
                'uq_earist_generated_request' => ['generated_by,request_token_hash', 0],
                'uq_earist_generated_docx' => ['docx_path', 0], 'uq_earist_generated_pdf' => ['pdf_path', 0],
                'idx_earist_generated_owner_record' => ['owner_id,research_record_id,generated_at,generated_document_id', 1],
                'idx_earist_generated_draft' => ['draft_id', 1], 'idx_earist_generated_verifier' => ['verified_by', 1],
                'idx_earist_generated_type_date' => ['document_type,generated_at,generated_document_id', 1],
            ],
            'foreign_keys' => [
                'fk_earist_generated_draft' => ['draft_id', 'earist_document_drafts', 'draft_id'],
                'fk_earist_generated_record' => ['research_record_id', 'faculty_research_records', 'research_record_id'],
                'fk_earist_generated_owner' => ['owner_id', 'users', 'user_id'],
                'fk_earist_generated_generator' => ['generated_by', 'users', 'user_id'],
                'fk_earist_generated_verifier' => ['verified_by', 'users', 'user_id'],
            ],
            'checks' => [
                'chk_earist_generated_json' => 'JSON_VALID(snapshot_json) AND JSON_VALID(missing_fields_json) AND JSON_VALID(warnings_json)',
                'chk_earist_generated_versions' => 'document_version > 0 AND draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0',
                'chk_earist_generated_hashes' => "source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$' AND request_token_hash REGEXP '^[0-9a-f]{64}$' AND docx_sha256 REGEXP '^[0-9a-f]{64}$'",
                'chk_earist_generated_docx' => 'CHAR_LENGTH(TRIM(docx_path)) > 0 AND docx_size > 0 AND CHAR_LENGTH(TRIM(renderer_version)) > 0',
                'chk_earist_generated_warning_ack' => 'JSON_LENGTH(warnings_json) = 0 OR warnings_acknowledged_at IS NOT NULL',
                'chk_earist_generated_verification' => "(document_type = 'annex_d' AND preparation_mode = 'prepared_for_printing' AND verified_by IS NOT NULL AND verified_by = generated_by AND verified_at IS NOT NULL AND verified_payload_sha256 IS NOT NULL AND verified_payload_sha256 REGEXP '^[0-9a-f]{64}$') OR ((document_type <> 'annex_d' OR preparation_mode = 'incomplete') AND verified_by IS NULL AND verified_at IS NULL AND verified_payload_sha256 IS NULL)",
                'chk_earist_generated_pdf' => "(pdf_path IS NULL AND pdf_sha256 IS NULL AND pdf_size IS NULL AND pdf_created_at IS NULL AND pdf_converter_version IS NULL) OR (pdf_path IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_path)) > 0 AND pdf_sha256 IS NOT NULL AND pdf_sha256 REGEXP '^[0-9a-f]{64}$' AND pdf_size IS NOT NULL AND pdf_size > 0 AND pdf_created_at IS NOT NULL AND pdf_converter_version IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_converter_version)) > 0)",
            ],
        ],
    ];
}

function earistSchemaNormalize(string $value): string
{
    return preg_replace('/[\s`]+/', '', strtolower($value));
}

/**
 * Conservative Boolean structure for the two compound 052 CHECKs. This is not
 * a general SQL equivalence engine: order and every atomic condition remain
 * significant. Quotes/function parentheses are not Boolean grouping. SQL
 * literals retain their exact bytes; malformed grouping/quotes fail closed.
 */
function earistSchemaCheckTree(string $expression): ?array
{
    $text = trim($expression);
    $length = strlen($text);
    if ($length === 0) return null;
    $depth = 0;
    $firstGroupEnd = null;
    $operators = ['or' => [], 'and' => []];
    $atom = '';
    for ($i = 0; $i < $length; $i++) {
        $character = $text[$i];
        if ($character === "'" || $character === '`') {
            $start = $i;
            $quote = $character;
            $closed = false;
            for ($i++; $i < $length; $i++) {
                if ($quote === "'" && $text[$i] === '\\') { $i++; continue; }
                if ($text[$i] !== $quote) continue;
                if ($i + 1 < $length && $text[$i + 1] === $quote) { $i++; continue; }
                $closed = true;
                break;
            }
            if (!$closed) return null;
            $quoted = substr($text, $start, $i - $start + 1);
            // Token arrays preserve boundaries: e.g. `a b` never equals `ab`.
            $atom .= $quote === "'"
                ? json_encode(['literal', $quoted], JSON_THROW_ON_ERROR)
                : json_encode(['word', strtolower(str_replace('``', '`', substr($quoted, 1, -1)))], JSON_THROW_ON_ERROR);
            continue;
        }
        if ($character === '(') $depth++;
        elseif ($character === ')') {
            if (--$depth < 0) return null;
            if ($depth === 0 && $text[0] === '(' && $firstGroupEnd === null) $firstGroupEnd = $i;
        }
        if (ctype_alpha($character) || $character === '_') {
            $start = $i;
            while ($i + 1 < $length && (ctype_alnum($text[$i + 1]) || $text[$i + 1] === '_')) $i++;
            $word = strtolower(substr($text, $start, $i - $start + 1));
            if ($depth === 0 && isset($operators[$word])) $operators[$word][] = [$start, $i + 1];
            $atom .= json_encode(['word', $word], JSON_THROW_ON_ERROR);
        } elseif (!ctype_space($character)) {
            $atom .= json_encode(['symbol', $character], JSON_THROW_ON_ERROR);
        }
    }
    if ($depth !== 0) return null;
    if ($text[0] === '(' && $firstGroupEnd === $length - 1) return earistSchemaCheckTree(substr($text, 1, -1));
    // OR has lower precedence. Keep essential OR groups within AND branches.
    foreach (['or', 'and'] as $operator) {
        if (!$operators[$operator]) continue;
        $children = [];
        $start = 0;
        foreach (array_merge($operators[$operator], [[$length, $length]]) as [$end, $next]) {
            $child = earistSchemaCheckTree(substr($text, $start, $end - $start));
            if ($child === null) return null;
            // AND/OR associativity makes nested groups of the SAME operator redundant.
            if ($child[0] === $operator) array_push($children, ...$child[1]);
            else $children[] = $child;
            $start = $next;
        }
        return [$operator, $children];
    }
    return ['atom', $atom];
}

/** Pure comparison, also usable by database-free verifier safety checks. */
function earistDocumentSchemaCompare(array $actual): array
{
    $errors = [];
    foreach (earistDocumentSchemaDefinitions() as $table => $expected) {
        $data = $actual[$table] ?? null;
        if (!$data) { $errors[] = $table . ': absent'; continue; }
        // Migration 067 is an additive extension of the append-only history table.
        // Keep the 052 verifier strict while recognizing the complete known extension.
        if ($table === 'earist_generated_documents'
            && isset($data['columns']['version_origin'], $data['columns']['edited_from_generated_document_id'])) {
            $expected['columns']['version_origin'] = ["enum('generated','edited')", false, 'generated'];
            $expected['columns']['edited_from_generated_document_id'] = ['int unsigned', true];
            $expected['indexes']['idx_earist_generated_edit_parent'] = ['edited_from_generated_document_id', 1];
            $expected['foreign_keys']['fk_earist_generated_edit_parent'] = [
                'edited_from_generated_document_id', 'earist_generated_documents', 'generated_document_id'];
        }
        if ($table === 'earist_generated_documents'
            && array_intersect(['file_retention_status', 'file_retention_started_at', 'file_retention_completed_at', 'file_retention_actor_id', 'file_retention_bytes'], array_keys($data['columns']))) {
            $expected['columns']['file_retention_status'] = ["enum('available','pruning','pruned')", false, 'available'];
            $expected['columns']['file_retention_started_at'] = ['datetime', true];
            $expected['columns']['file_retention_completed_at'] = ['datetime', true];
            $expected['columns']['file_retention_actor_id'] = ['int unsigned', true];
            $expected['columns']['file_retention_bytes'] = ['bigint unsigned', false, '0'];
            $expected['indexes']['idx_earist_generated_retention'] = ['owner_id,research_record_id,document_type,file_retention_status,document_version', 1];
            $expected['indexes']['idx_earist_generated_retention_actor'] = ['file_retention_actor_id', 1];
            $expected['foreign_keys']['fk_earist_generated_retention_actor'] = ['file_retention_actor_id', 'users', 'user_id'];
        }
        if ($data['engine'] !== 'InnoDB' || $data['collation'] !== 'utf8mb4_unicode_ci') $errors[] = $table . ': storage differs';
        if (count($data['columns']) !== count($expected['columns'])) $errors[] = $table . ': column count differs';
        foreach ($expected['columns'] as $name => $definition) {
            [$type, $nullable] = $definition;
            $column = $data['columns'][$name] ?? null;
            if (!$column) { $errors[] = $table . '.' . $name . ': absent'; continue; }
            $actualType = preg_replace('/\b(int|bigint)\(\d+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            $default = $column['COLUMN_DEFAULT'];
            if ($default === 'NULL') $default = null;
            if ($default === 'current_timestamp') $default = 'current_timestamp()';
            if (str_starts_with($type, 'enum(') && is_string($default)
                && strlen($default) >= 2 && $default[0] === "'" && $default[strlen($default) - 1] === "'") {
                $default = str_replace("''", "'", substr($default, 1, -1));
            }
            if ($actualType !== $type || ($column['IS_NULLABLE'] === 'YES') !== $nullable
                || $default !== ($definition[2] ?? null)
                || earistSchemaNormalize($column['EXTRA']) !== earistSchemaNormalize($definition[3] ?? '')
                || ($column['COLLATION_NAME'] !== null && $column['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')) {
                $errors[] = $table . '.' . $name . ': definition differs';
            }
        }
        if (count($data['indexes']) !== count($expected['indexes'])) $errors[] = $table . ': index count differs';
        foreach ($expected['indexes'] as $name => [$columns, $nonUnique]) {
            $index = $data['indexes'][$name] ?? null;
            if (!$index || $index['columns'] !== $columns || $index['non_unique'] !== $nonUnique || $index['prefixes'] !== 0) {
                $errors[] = $table . '.' . $name . ': index differs';
            }
        }
        if (count($data['foreign_keys']) !== count($expected['foreign_keys'])) $errors[] = $table . ': foreign-key count differs';
        foreach ($expected['foreign_keys'] as $name => [$column, $target, $targetColumn]) {
            $foreign = $data['foreign_keys'][$name] ?? null;
            if (!$foreign || $foreign !== [$column, $target, $targetColumn, 'RESTRICT', 'RESTRICT', true]) {
                $errors[] = $table . '.' . $name . ': foreign key differs';
            }
        }
        if (count($data['checks']) !== count($expected['checks'])) $errors[] = $table . ': check count differs';
        foreach ($expected['checks'] as $name => $expression) {
            $matches = isset($data['checks'][$name]);
            if ($matches && in_array($name, ['chk_earist_generated_verification', 'chk_earist_generated_pdf'], true)) {
                $tree = earistSchemaCheckTree($data['checks'][$name]);
                $matches = $tree !== null && $tree === earistSchemaCheckTree($expression);
            } elseif ($matches) {
                $matches = earistSchemaNormalize($data['checks'][$name]) === earistSchemaNormalize($expression);
            }
            if (!$matches) {
                $errors[] = $table . '.' . $name . ': check differs';
            }
        }
    }
    return ['valid' => !$errors, 'errors' => $errors];
}

/** Only information_schema SELECTs. Missing/partial storage never verifies true. */
function earistDocumentSchemaStatus(mysqli $conn): array
{
    $rows = static function (string $sql, string $table) use ($conn): array {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    };
    $actual = [];
    foreach (array_keys(earistDocumentSchemaDefinitions()) as $table) {
        $storage = $rows('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table);
        if (!$storage) continue;
        $data = ['engine' => $storage[0]['ENGINE'], 'collation' => $storage[0]['TABLE_COLLATION'], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
        foreach ($rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table) as $column) {
            $data['columns'][$column['COLUMN_NAME']] = $column;
        }
        foreach ($rows('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table) as $index) {
            $name = $index['INDEX_NAME'];
            if (!isset($data['indexes'][$name])) $data['indexes'][$name] = ['columns' => '', 'non_unique' => (int) $index['NON_UNIQUE'], 'prefixes' => 0];
            $data['indexes'][$name]['columns'] .= ($data['indexes'][$name]['columns'] === '' ? '' : ',') . $index['COLUMN_NAME'];
            if ($index['SUB_PART'] !== null) $data['indexes'][$name]['prefixes']++;
        }
        foreach ($rows('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE, (k.REFERENCED_TABLE_SCHEMA = DATABASE()) AS same_schema FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? ORDER BY k.ORDINAL_POSITION', $table) as $foreign) {
            $name = $foreign['CONSTRAINT_NAME'];
            $definition = [$foreign['COLUMN_NAME'], $foreign['REFERENCED_TABLE_NAME'], $foreign['REFERENCED_COLUMN_NAME'], $foreign['DELETE_RULE'], $foreign['UPDATE_RULE'], (bool) $foreign['same_schema']];
            // Duplicate rows indicate an unexpected composite FK; fail comparison.
            $data['foreign_keys'][$name] = isset($data['foreign_keys'][$name]) ? [] : $definition;
        }
        foreach ($rows('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?', $table) as $check) {
            $data['checks'][$check['CONSTRAINT_NAME']] = $check['CHECK_CLAUSE'];
        }
        $actual[$table] = $data;
    }
    return earistDocumentSchemaCompare($actual);
}
