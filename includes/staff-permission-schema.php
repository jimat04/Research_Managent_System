<?php
declare(strict_types=1);

function rmsStaffPermissionSchemaIsCompatible(array $schema): bool
{
    $columns = $schema['columns'] ?? null;
    $indexes = $schema['indexes'] ?? null;
    if (!is_array($columns) || !is_array($indexes)) {
        return false;
    }

    $requiredColumns = [
        'permission_id',
        'user_id',
        'module_key',
        'subpage_key',
        'access_level',
        'download_allowed',
        'created_at',
        'updated_at',
    ];
    foreach ($requiredColumns as $column) {
        if (!isset($columns[$column])) {
            return false;
        }
    }

    $createdDefault = strtolower((string) ($columns['created_at']['default'] ?? ''));
    $updatedDefault = strtolower((string) ($columns['updated_at']['default'] ?? ''));
    if (!preg_match('/^int(?:\(\d+\))? unsigned$/i', (string) ($columns['permission_id']['type'] ?? ''))
        || stripos((string) ($columns['permission_id']['extra'] ?? ''), 'auto_increment') === false
        || strtoupper((string) ($columns['permission_id']['null'] ?? '')) !== 'NO'
        || !preg_match('/^int(?:\(\d+\))? unsigned$/i', (string) ($columns['user_id']['type'] ?? ''))
        || strtoupper((string) ($columns['user_id']['null'] ?? '')) !== 'NO'
        || strtolower((string) ($columns['module_key']['type'] ?? '')) !== 'varchar(64)'
        || strtoupper((string) ($columns['module_key']['null'] ?? '')) !== 'NO'
        || strtolower((string) ($columns['subpage_key']['type'] ?? '')) !== 'varchar(64)'
        || strtoupper((string) ($columns['subpage_key']['null'] ?? '')) !== 'NO'
        || (string) ($columns['subpage_key']['default'] ?? '') !== ''
        || strtolower((string) ($columns['access_level']['type'] ?? '')) !== "enum('none','view','manage')"
        || strtoupper((string) ($columns['access_level']['null'] ?? '')) !== 'NO'
        || strtolower((string) ($columns['access_level']['default'] ?? '')) !== 'none'
        || strtolower((string) ($columns['download_allowed']['type'] ?? '')) !== 'tinyint(1)'
        || strtoupper((string) ($columns['download_allowed']['null'] ?? '')) !== 'NO'
        || (string) ($columns['download_allowed']['default'] ?? '') !== '0'
        || strtolower((string) ($columns['created_at']['type'] ?? '')) !== 'timestamp'
        || strtoupper((string) ($columns['created_at']['null'] ?? '')) !== 'NO'
        || !preg_match('/^current_timestamp(?:\(\))?$/', $createdDefault)
        || strtolower((string) ($columns['updated_at']['type'] ?? '')) !== 'timestamp'
        || strtoupper((string) ($columns['updated_at']['null'] ?? '')) !== 'NO'
        || !preg_match('/^current_timestamp(?:\(\))?$/', $updatedDefault)
        || stripos((string) ($columns['updated_at']['extra'] ?? ''), 'on update current_timestamp') === false) {
        return false;
    }

    $requiredIndexes = [
        'PRIMARY' => [0, ['permission_id']],
        'uq_staff_permissions_user_module_subpage' => [0, ['user_id', 'module_key', 'subpage_key']],
        'idx_staff_permissions_user' => [1, ['user_id']],
        'idx_staff_permissions_module' => [1, ['module_key']],
    ];
    foreach ($requiredIndexes as $name => [$nonUnique, $expectedColumns]) {
        if (!isset($indexes[$name])
            || (int) ($indexes[$name]['nonUnique'] ?? -1) !== $nonUnique
            || ($indexes[$name]['columns'] ?? null) !== $expectedColumns) {
            return false;
        }
    }

    return true;
}

function rmsEnsureStaffPermissionSchema(
    bool $e2eMode,
    callable $inspectSchema,
    ?callable $initializeSchema = null
): void {
    if (!$e2eMode) {
        if ($initializeSchema !== null) {
            $initializeSchema();
        }
        return;
    }

    $schema = $inspectSchema();
    if (!is_array($schema) || !rmsStaffPermissionSchemaIsCompatible($schema)) {
        throw new RuntimeException(
            'E2E safety check failed: staff_permissions is missing or incompatible; provision the approved schema before testing'
        );
    }
}
