<?php
/** Shared archive rules for publication and intellectual-property record routes. */
require_once __DIR__ . '/output-contributors.php';

function archiveEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function archiveQuery(mysqli $db, string $sql, array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute(); return $stmt;
}
function archiveCopyrightScopeForType(string $type): string
{
    return match ($type) {
        'patent', 'utility_model' => 'patents_utility_models',
        'innovation', 'technology_transfer' => 'innovation_technology_transfer',
        default => '',
    };
}
function archiveConfig(string $kind, string $scope = ''): array
{
    $config = match ($kind) {
        'publication' => ['table' => 'publications', 'id' => 'publication_id', 'owner' => 'researcher_id', 'authors' => 'researcher_name', 'category' => 'researcher_category', 'title' => 'research_title', 'type' => 'publication_type', 'date' => 'publication_date', 'module' => 'publications', 'label' => 'Publication Records', 'types' => ['journal' => 'Journal', 'conference' => 'Conference', 'book_chapter' => 'Book chapter', 'other' => 'Other']],
        'copyright' => ['table' => 'copyright_applications', 'id' => 'copyright_id', 'owner' => 'applicant_id', 'authors' => 'applicant_name', 'category' => 'applicant_category', 'title' => 'output_title', 'type' => 'output_type', 'date' => 'filing_date', 'module' => 'copyrights', 'label' => 'Copyright Applications', 'types' => ['software' => 'Software', 'research' => 'Research', 'instructional_material' => 'Instructional material', 'module' => 'Module', 'other' => 'Other', 'patent' => 'Patent', 'utility_model' => 'Utility model', 'innovation' => 'Innovation', 'technology_transfer' => 'Technology transfer']],
        default => throw new InvalidArgumentException('Unsupported archive.'),
    };
    if ($kind === 'copyright') {
        if ($scope === 'patents_utility_models') {
            $config['label'] = 'Patents / Utility Models';
            $config['types'] = array_intersect_key($config['types'], ['patent' => true, 'utility_model' => true]);
        } elseif ($scope === 'innovation_technology_transfer') {
            $config['label'] = 'Innovation & Technology Transfer';
            $config['types'] = array_intersect_key($config['types'], ['innovation' => true, 'technology_transfer' => true]);
        } else {
            $config['types'] = array_intersect_key($config['types'], ['software' => true, 'research' => true, 'instructional_material' => true, 'module' => true, 'other' => true]);
        }
    }
    return $config;
}
function archiveCopyrightType(string $type): bool { return archiveCopyrightScopeForType($type) !== '' || isset(archiveConfig('copyright')['types'][$type]); }
function archiveExternalOptions(): array { return ['filed' => 'Applied / Filed', 'processing' => 'Under external processing', 'registered' => 'Registered / Issued', 'refused' => 'Refused externally', 'withdrawn' => 'Withdrawn externally', 'other' => 'Other documented outcome']; }
function archiveExternalLabel(array $row): string
{
    return archiveExternalOptions()[$row['external_status'] ?? ''] ?? (($row['status'] ?? '') === 'registered' && !empty($row['copyright_ref_no']) ? 'Registered (legacy record)' : 'Not recorded — legacy status retained');
}
function archiveStatus(array $row): string
{
    if (!empty($row['deactivated_at'])) return 'Inactive';
    return ($row['archival_status'] ?? '') === 'archived' ? 'Archived' : 'Legacy record';
}
function archiveLoad(mysqli $db, string $kind, int $id, bool $lock = false): ?array
{
    $c = archiveConfig($kind);
    return archiveQuery($db, "SELECT * FROM {$c['table']} WHERE {$c['id']} = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->get_result()->fetch_assoc() ?: null;
}
function archiveStaffScopes(string $kind, array $row): array
{
    if ($kind === 'copyright') {
        $type = (string) ($row['output_type'] ?? '');
        $page = match (archiveCopyrightScopeForType($type)) {
            'patents_utility_models' => 'patents_utility_models',
            'innovation_technology_transfer' => 'innovation_technology_transfer',
            default => 'copyrights',
        };
        return archiveCopyrightType($type) ? [['intellectual_property_utilization', $page]] : [];
    }
    $scopes = [['publication_dissemination', 'publication_records'], ['publication_dissemination', 'publication_reports']];
    // Research outputs remains an independent staff scope for implementation output records.
    if (($row['archival_status'] ?? '') === 'archived' || ($row['status'] ?? '') === 'published') {
        $scopes[] = ['research_implementation_outputs', 'research_outputs'];
    } elseif (empty($row['deactivated_at'])) {
        $scopes[] = ['research_implementation_outputs', 'research_outputs'];
    }
    return $scopes;
}
function archiveCan(mysqli $db, string $kind, array $row, array $user, string $action = 'view'): bool
{
    $c = archiveConfig($kind); $role = $user['role'] ?? ''; $uid = (int) ($user['user_id'] ?? 0);
    if ($kind === 'copyright' && !archiveCopyrightType((string) ($row['output_type'] ?? ''))) return false;
    if ($role === 'admin') return true;
    if ($action === 'deactivate' || $action === 'restore') return false;
    $catalogPublication = $kind === 'publication'
        && empty($row['deactivated_at'])
        && (($row['archival_status'] ?? '') === 'archived' || ($row['status'] ?? '') === 'published');
    if ($role === 'faculty') {
        if ($action === 'manage') return empty($row['deactivated_at']) && (int) $row[$c['owner']] === $uid;
        if ($action === 'catalog' && $catalogPublication) return true;
        if ((int) $row[$c['owner']] === $uid) return true;
        return (bool) archiveQuery($db, "SELECT 1 FROM {$kind}_contributors WHERE {$c['id']} = ? AND user_id = ?", [$row[$c['id']], $uid])->get_result()->fetch_row();
    }
    if ($role === 'student') {
        if (!in_array($action, ['view', 'catalog', 'download'], true)) return false;
        if ($action === 'catalog' && $catalogPublication) return true;
        return (bool) archiveQuery($db, "SELECT 1 FROM {$kind}_contributors WHERE {$c['id']} = ? AND user_id = ?", [$row[$c['id']], $uid])->get_result()->fetch_row();
    }
    if ($role !== 'research_staff') return false;
    if ($action === 'manage' && !empty($row['deactivated_at'])) return false;
    foreach (archiveStaffScopes($kind, $row) as [$module, $page]) {
        if ($kind === 'publication' && $action === 'manage' && $page === 'publication_reports') continue;
        if (hasModulePermission($uid, $module, 'view', $page)
            && (in_array($action, ['view', 'catalog'], true) || ($action === 'download' ? hasDownloadPermission($uid, $module, $page) : hasModulePermission($uid, $module, 'manage', $page)))) return true;
    }
    return false;
}
function archiveDeny(): never { http_response_code(403); exit('Access denied.'); }
function archiveAudit(mysqli $db, string $kind, int $id, string $event, array $before, array $after): void
{
    $changes = [];
    foreach ($after as $field => $value) if (($before[$field] ?? null) != $value) $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
    $module = archiveConfig($kind)['module'];
    if (!logActivity("Archive {$kind} #{$id}: {$event}", $module)) throw new RuntimeException('Audit could not be recorded.');
    $logId = $db->insert_id;
    archiveQuery($db, 'UPDATE activity_log SET details = ? WHERE log_id = ?', [json_encode(['record_kind' => $kind, 'record_id' => $id, 'event' => $event, 'changes' => $changes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $logId]);
}
function archiveContributors(mysqli $db, string $kind, int $id): array
{
    $c = archiveConfig($kind);
    return archiveQuery($db, "SELECT user_id, contribution, is_primary, created_at FROM {$kind}_contributors WHERE {$c['id']} = ? ORDER BY user_id", [$id])->get_result()->fetch_all(MYSQLI_ASSOC);
}
function archiveSyncAuthors(mysqli $db, string $kind, int $id, array $ids, ?string $contribution = null): void
{
    $c = archiveConfig($kind); $existing = archiveContributors($db, $kind, $id);
    $oldIds = array_map('intval', array_column($existing, 'user_id'));
    // Preserve retained IDs and timestamps; an explicit publication subtype change may update contributor roles.
    foreach (array_diff($oldIds, $ids) as $uid) archiveQuery($db, "DELETE FROM {$kind}_contributors WHERE {$c['id']} = ? AND user_id = ?", [$id, $uid]);
    if ($contribution !== null) {
        foreach (array_intersect($oldIds, $ids) as $uid) archiveQuery($db, "UPDATE {$kind}_contributors SET contribution = ? WHERE {$c['id']} = ? AND user_id = ? AND (contribution IS NULL OR contribution <> ?)", [$contribution, $id, $uid, $contribution]);
    }
    foreach (array_diff($ids, $oldIds) as $uid) archiveQuery($db, "INSERT INTO {$kind}_contributors ({$c['id']}, user_id, contribution, is_primary) VALUES (?, ?, ?, 0)", [$id, $uid, $contribution ?? 'Author / creator']);
}
