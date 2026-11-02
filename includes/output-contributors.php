<?php
function rmsOutputContributorTablesAvailable(mysqli $conn): bool
{
    foreach (['publication_contributors', 'copyright_contributors'] as $table) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $available = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
        $stmt->close();
        if (!$available) return false;
    }
    return true;
}

function rmsOutputContributorOptions(mysqli $conn, ?int $programId = null): array
{
    $programId = $programId !== null ? filter_var($programId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    $options = [];

    $sql = "SELECT u.user_id, u.first_name, u.last_name, u.role,
                GROUP_CONCAT(DISTINCT p.program_id ORDER BY p.program_id SEPARATOR ',') AS program_ids,
                GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') AS program_codes
            FROM users u
            LEFT JOIN faculty_program_assignments fpa ON fpa.faculty_id = u.user_id
            LEFT JOIN programs p ON p.program_id = fpa.program_id
            WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.role = 'faculty'";
    $params = [];
    $types = '';
    if ($programId !== null) {
        $sql .= ' AND EXISTS (SELECT 1 FROM faculty_program_assignments fp WHERE fp.faculty_id = u.user_id AND fp.program_id = ?)';
        $params[] = $programId;
        $types .= 'i';
    }
    $sql .= ' GROUP BY u.user_id, u.first_name, u.last_name, u.role ORDER BY u.last_name, u.first_name, u.user_id';

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['program_ids'] = (string) ($row['program_ids'] ?? '');
        $row['program_codes'] = (string) ($row['program_codes'] ?? '');
        $options[] = $row;
    }
    $stmt->close();
    return $options;
}

function rmsValidateOutputContributorIds(mysqli $conn, array $candidateIds, int $primaryId): ?array
{
    $ids = [];
    foreach ($candidateIds as $candidate) {
        $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id && $id !== $primaryId) $ids[$id] = $id;
    }
    if (!$ids) return [];
    $ids = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE status = 'active' AND deleted_at IS NULL AND role = 'faculty' AND user_id IN ($placeholders)");
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = [];
    while ($row = $result->fetch_assoc()) $valid[] = (int) $row['user_id'];
    $stmt->close();
    return count($valid) === count($ids) ? $valid : null;
}

function rmsSyncOutputContributors(mysqli $conn, string $kind, int $recordId, int $primaryId, array $contributorIds): void
{
    $tables = [
        'publication' => ['publication_contributors', 'publication_id', 'publication_id'],
        'copyright' => ['copyright_contributors', 'copyright_id', 'copyright_id'],
    ];
    if (!isset($tables[$kind])) throw new InvalidArgumentException('Invalid contributor record type.');
    [$table, $recordColumn, $recordParam] = $tables[$kind];
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$recordColumn` = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO `$table` (`$recordParam`, user_id, contribution, is_primary) VALUES (?, ?, ?, ?)");
    $primaryLabel = $kind === 'publication' ? 'Primary contributor' : 'Primary applicant';
    $primary = 1;
    $stmt->bind_param('iisi', $recordId, $primaryId, $primaryLabel, $primary);
    $stmt->execute();
    $secondaryLabel = $kind === 'publication' ? 'Co-author' : 'Co-author / inventor';
    $secondary = 0;
    foreach ($contributorIds as $userId) {
        $stmt->bind_param('iisi', $recordId, $userId, $secondaryLabel, $secondary);
        $stmt->execute();
    }
    $stmt->close();
}
