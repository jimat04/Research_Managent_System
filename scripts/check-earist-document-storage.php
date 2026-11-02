<?php
/** Database-free tests of 052 source synchronization and verifier rejection rules. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-document-schema.php';
$passed = 0;
$failed = [];
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $label;
};
$migration = file_get_contents(__DIR__ . '/../database/migrations/052_earist_document_storage.sql');
$schema = file_get_contents(__DIR__ . '/../database/schema/rms_db.sql');
$check(str_ends_with($schema, $migration), 'Canonical schema ends with byte-identical Migration 052');
$sql = preg_replace('/^\s*--[^\r\n]*/m', '', $migration);
$statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
$check(count($statements) === 2, 'Exactly two DDL statements');
$check(count(array_filter($statements, static fn(string $statement): bool =>
    (bool) preg_match('/\ACREATE TABLE IF NOT EXISTS earist_(document_drafts|generated_documents)\s*\(/', $statement)
)) === count($statements), 'Only additive CREATE TABLE statements; ON DELETE/UPDATE clauses are not statements');
$fixture = [];
foreach (earistDocumentSchemaDefinitions() as $table => $definition) {
    $check((bool) preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \(/', $sql), $table . ': expected create statement');
    preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s', $migration, $match);
    $tableSql = $match[1] ?? '';
    $data = ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
    foreach ($definition['columns'] as $name => $column) {
        $check((bool) preg_match('/^    ' . preg_quote($name, '/') . ' /m', $tableSql), $table . '.' . $name . ': SQL column present');
        $data['columns'][$name] = ['COLUMN_TYPE' => $column[0], 'IS_NULLABLE' => $column[1] ? 'YES' : 'NO', 'COLUMN_DEFAULT' => $column[2] ?? null, 'EXTRA' => $column[3] ?? '', 'COLLATION_NAME' => str_starts_with($column[0], 'varchar') || str_starts_with($column[0], 'char') || $column[0] === 'longtext' || str_starts_with($column[0], 'enum') ? 'utf8mb4_unicode_ci' : null];
    }
    foreach ($definition['indexes'] as $name => [$columns, $nonUnique]) {
        $data['indexes'][$name] = ['columns' => $columns, 'non_unique' => $nonUnique, 'prefixes' => 0];
        $indexSql = $name === 'PRIMARY' ? 'PRIMARY KEY (' . $columns . ')' : ($nonUnique ? 'KEY ' : 'UNIQUE KEY ') . $name . ' (' . $columns . ')';
        $check(str_contains(earistSchemaNormalize($tableSql), earistSchemaNormalize($indexSql)), $table . '.' . $name . ': SQL index matches');
    }
    foreach ($definition['foreign_keys'] as $name => [$column, $target, $targetColumn]) {
        $data['foreign_keys'][$name] = [$column, $target, $targetColumn, 'RESTRICT', 'RESTRICT', true];
        $check(str_contains(earistSchemaNormalize($tableSql), earistSchemaNormalize('CONSTRAINT ' . $name . ' FOREIGN KEY (' . $column . ') REFERENCES ' . $target . ' (' . $targetColumn . ') ON DELETE RESTRICT')), $name . ': SQL referential retention matches');
    }
    foreach ($definition['checks'] as $name => $expression) {
        $data['checks'][$name] = $expression;
        $check(str_contains(earistSchemaNormalize($tableSql), earistSchemaNormalize('CONSTRAINT ' . $name . ' CHECK (' . $expression . ')')), $name . ': SQL check matches');
    }
    $fixture[$table] = $data;
}
$check(earistDocumentSchemaCompare($fixture)['valid'], 'Matching synthetic metadata accepted');
$check(!earistDocumentSchemaCompare([])['valid'], 'Absent storage rejected');
$reject = static function (callable $change, string $label) use ($fixture, $check): void {
    $changed = $fixture;
    $change($changed);
    $check(!earistDocumentSchemaCompare($changed)['valid'], $label);
};
$reject(static function (&$m) { unset($m['earist_generated_documents']); }, 'Partial installation rejected');
$reject(static function (&$m) { $m['earist_document_drafts']['engine'] = 'MyISAM'; }, 'Nontransactional storage rejected');
$reject(static function (&$m) { $m['earist_document_drafts']['collation'] = 'latin1_swedish_ci'; }, 'Wrong collation rejected');
$reject(static function (&$m) { unset($m['earist_generated_documents']['columns']['snapshot_json']); }, 'Missing snapshot column rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['columns']['snapshot_json']['IS_NULLABLE'] = 'YES'; }, 'Nullable snapshot rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['columns']['docx_size']['COLUMN_TYPE'] = 'float'; }, 'Wrong size type rejected');
$reject(static function (&$m) { $m['earist_document_drafts']['columns']['draft_revision']['COLUMN_DEFAULT'] = '0'; }, 'Wrong optimistic revision default rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['columns']['generated_at']['EXTRA'] = 'on update current_timestamp()'; }, 'Mutable generation timestamp rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['indexes']['uq_earist_generated_version']['non_unique'] = 1; }, 'Nonunique version allocation rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['indexes']['uq_earist_generated_docx']['prefixes'] = 1; }, 'Prefix-only file uniqueness rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['foreign_keys']['fk_earist_generated_record'][3] = 'CASCADE'; }, 'Cascading history loss rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['foreign_keys']['fk_earist_generated_record'][5] = false; }, 'Cross-schema relationship rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_verification'] = '1'; }, 'Missing Annex D evidence protection rejected');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_pdf'] = '1'; }, 'Partial PDF metadata protection required');
$check(!str_contains($sql, 'FLOAT') && !str_contains($sql, 'DECIMAL('), 'No score-rounding storage column');

// Actual MariaDB 10.4 serialization from the failed 052 verification. No live
// database or ignored backup files are needed to reproduce this regression.
$serialized = [
    'chk_earist_generated_verification' => "`document_type` = 'annex_d' and `preparation_mode` = 'prepared_for_printing' and `verified_by` is not null and `verified_by` = `generated_by` and `verified_at` is not null and `verified_payload_sha256` is not null and `verified_payload_sha256` regexp '^[0-9a-f]{64}$' or (`document_type` <> 'annex_d' or `preparation_mode` = 'incomplete') and `verified_by` is null and `verified_at` is null and `verified_payload_sha256` is null",
    'chk_earist_generated_pdf' => "`pdf_path` is null and `pdf_sha256` is null and `pdf_size` is null and `pdf_created_at` is null and `pdf_converter_version` is null or `pdf_path` is not null and char_length(trim(`pdf_path`)) > 0 and `pdf_sha256` is not null and `pdf_sha256` regexp '^[0-9a-f]{64}$' and `pdf_size` is not null and `pdf_size` > 0 and `pdf_created_at` is not null and `pdf_converter_version` is not null and char_length(trim(`pdf_converter_version`)) > 0",
];
$mariaFixture = $fixture;
foreach ($serialized as $name => $clause) {
    $mariaFixture['earist_generated_documents']['checks'][$name] = $clause;
    $check(earistSchemaCheckTree($clause) === earistSchemaCheckTree($fixture['earist_generated_documents']['checks'][$name]), $name . ': redundant grouping accepted');
}
$check(earistDocumentSchemaCompare($mariaFixture)['valid'], 'Actual MariaDB CHECK serialization accepted by full verifier');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_verification'] = str_replace('verified_by = generated_by', 'verified_by <> generated_by', $m['earist_generated_documents']['checks']['chk_earist_generated_verification']); }, 'Verifier identity condition cannot change');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_pdf'] = str_replace(' AND pdf_size > 0', '', $m['earist_generated_documents']['checks']['chk_earist_generated_pdf']); }, 'PDF size condition cannot disappear');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_pdf'] = str_replace('{64}', '{63}', $m['earist_generated_documents']['checks']['chk_earist_generated_pdf']); }, 'Hash literal cannot change');
$reject(static function (&$m) { $m['earist_generated_documents']['checks']['chk_earist_generated_verification'] = str_replace("'annex_d'", "'ANNEX_D'", $m['earist_generated_documents']['checks']['chk_earist_generated_verification']); }, 'Literal bytes remain significant');
$check(earistSchemaCheckTree('a AND (b OR c)') !== earistSchemaCheckTree('(a AND b) OR c'), 'Essential mixed-operator grouping preserved');
$check(earistSchemaCheckTree('(a AND b) OR (c AND d)') === earistSchemaCheckTree('a AND b OR c AND d'), 'AND before OR precedence preserved');
$check(earistSchemaCheckTree('a AND (b AND c)') === earistSchemaCheckTree('(a AND b) AND c'), 'Same-operator associative grouping accepted');
$check(earistSchemaCheckTree("a = 'A OR B (C)' AND f(x) > 0") === earistSchemaCheckTree("(a = 'A OR B (C)') AND (f(x) > 0)"), 'Literal text and function parentheses are not operators');
$check(earistSchemaCheckTree("a = 'A OR B'") !== earistSchemaCheckTree("a = 'A OR  B'"), 'Whitespace inside literals retained');
$check(earistSchemaCheckTree('`a b` = 1') !== earistSchemaCheckTree('`ab` = 1'), 'Quoted identifier boundaries retained');
foreach (['', '(a AND b', 'a AND b)', "a = 'unclosed", 'a AND', 'OR b'] as $invalid) {
    $check(earistSchemaCheckTree($invalid) === null, 'Malformed/empty grouping fails closed: ' . $invalid);
}
// Both affected CHECKs must continue rejecting changed AND/OR logic.
foreach ($serialized as $name => $clause) {
    $changed = $mariaFixture;
    $changed['earist_generated_documents']['checks'][$name] = preg_replace('/\band\b/', 'or', $clause, 1);
    $check(!earistDocumentSchemaCompare($changed)['valid'], $name . ': changed Boolean operator rejected');
}
$changed = $mariaFixture;
$changed['earist_generated_documents']['checks']['chk_earist_generated_verification'] = str_replace(["(`document_type` <> 'annex_d' or `preparation_mode` = 'incomplete')"], ["`document_type` <> 'annex_d' or `preparation_mode` = 'incomplete'"], $serialized['chk_earist_generated_verification']);
$check(!earistDocumentSchemaCompare($changed)['valid'], 'Removing essential Annex D grouping rejected');
echo $passed . ' passed, ' . count($failed) . ' failed' . PHP_EOL;
foreach ($failed as $failure) echo 'FAIL: ' . $failure . PHP_EOL;
exit($failed ? 1 : 0);
