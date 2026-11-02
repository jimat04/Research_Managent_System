<?php
/**
 * Role-scoped Academic Affiliation data access and validation.
 * Students intentionally have no access to these helpers from profile forms.
 */

function rmsAcademicAffiliationTablesAvailable(mysqli $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $required = [
        'academic_affiliation_groups',
        'academic_affiliation_programs',
        'academic_group_programs',
        'user_academic_affiliations',
        'user_academic_program_assignments',
    ];
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        return $available = false;
    }
    foreach ($required as $table) {
        $stmt->bind_param('s', $table);
        if (!$stmt->execute() || (int) ($stmt->get_result()->fetch_row()[0] ?? 0) !== 1) {
            $stmt->close();
            return $available = false;
        }
    }
    $stmt->close();
    return $available = true;
}

function rmsAcademicAffiliationCatalog(mysqli $conn, bool $activeOnly = true): array
{
    $catalog = [];
    $sql = 'SELECT g.group_id, g.group_name, g.status AS group_status,
                   p.affiliation_program_id, p.program_id, p.program_code, p.program_name,
                   p.is_specialization, p.status AS program_status, gp.status AS mapping_status
              FROM academic_affiliation_groups g
              LEFT JOIN academic_group_programs gp ON gp.group_id = g.group_id AND (? = 0 OR gp.status = 1)
              LEFT JOIN academic_affiliation_programs p ON p.affiliation_program_id = gp.affiliation_program_id
              LEFT JOIN programs canonical ON canonical.program_id = p.program_id
             WHERE (? = 0 OR (g.status = 1 AND (p.affiliation_program_id IS NULL OR (p.status = 1 AND (p.program_id IS NULL OR canonical.status = 1)))) )
          ORDER BY g.group_name, p.is_specialization, p.program_name';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $catalog;
    }
    $active = $activeOnly ? 1 : 0;
    $stmt->bind_param('ii', $active, $active);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['group_id'];
            if (!isset($catalog[$groupId])) {
                $catalog[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => (string) $row['group_name'],
                    'status' => (int) $row['group_status'],
                    'programs' => [],
                ];
            }
            if ($row['affiliation_program_id'] !== null) {
                $catalog[$groupId]['programs'][] = [
                    'affiliation_program_id' => (int) $row['affiliation_program_id'],
                    'program_id' => $row['program_id'] === null ? null : (int) $row['program_id'],
                    'program_code' => (string) ($row['program_code'] ?? ''),
                    'program_name' => (string) $row['program_name'],
                    'is_specialization' => (int) $row['is_specialization'],
                    'status' => (int) $row['program_status'],
                    'mapping_status' => (int) $row['mapping_status'],
                ];
            }
        }
    }
    $stmt->close();
    return array_values($catalog);
}

function rmsAcademicAffiliationLoad(mysqli $conn, int $userId): array
{
    $rows = [];
    $stmt = $conn->prepare(
        'SELECT a.affiliation_id, a.group_id, a.is_primary,
                g.group_name, pa.affiliation_program_id
           FROM user_academic_affiliations a
           JOIN academic_affiliation_groups g ON g.group_id = a.group_id
           LEFT JOIN user_academic_program_assignments pa ON pa.affiliation_id = a.affiliation_id
          WHERE a.user_id = ?
          ORDER BY a.is_primary DESC, g.group_name, pa.affiliation_program_id'
    );
    if (!$stmt) {
        return $rows;
    }
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['group_id'];
            if (!isset($rows[$groupId])) {
                $rows[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => (string) $row['group_name'],
                    'is_primary' => (int) $row['is_primary'],
                    'program_ids' => [],
                ];
            }
            if ($row['affiliation_program_id'] !== null) {
                $rows[$groupId]['program_ids'][] = (int) $row['affiliation_program_id'];
            }
        }
    }
    $stmt->close();
    return array_values($rows);
}

/**
 * Persist validated group/program selections inside the caller's transaction.
 * Input shape: [ ['group_id' => 1, 'program_ids' => [2, 3]], ... ].
 */
function rmsAcademicAffiliationSave(mysqli $conn, int $userId, array $affiliations, bool $requireGroup = false): bool
{
    if ($userId <= 0 || !rmsAcademicAffiliationTablesAvailable($conn)) {
        return false;
    }

    $roleStmt = $conn->prepare('SELECT role FROM users WHERE user_id = ? AND deleted_at IS NULL LIMIT 1');
    if (!$roleStmt) {
        return false;
    }
    $roleStmt->bind_param('i', $userId);
    $roleStmt->execute();
    $role = (string) ($roleStmt->get_result()->fetch_assoc()['role'] ?? '');
    $roleStmt->close();
    if (!in_array($role, ['admin', 'research_staff', 'faculty'], true)) {
        return false;
    }

    $normalized = [];
    $groupStmt = $conn->prepare('SELECT group_id FROM academic_affiliation_groups WHERE group_id = ? AND status = 1 LIMIT 1');
    $programStmt = $conn->prepare(
        'SELECT gp.group_id, p.affiliation_program_id, p.program_id
           FROM academic_group_programs gp
           JOIN academic_affiliation_groups g ON g.group_id = gp.group_id AND g.status = 1
           JOIN academic_affiliation_programs p ON p.affiliation_program_id = gp.affiliation_program_id AND p.status = 1
           LEFT JOIN programs canonical ON canonical.program_id = p.program_id
          WHERE gp.group_id = ? AND gp.affiliation_program_id = ? AND gp.status = 1
            AND (p.program_id IS NULL OR canonical.status = 1)
          LIMIT 1'
    );
    if (!$groupStmt || !$programStmt) {
        $groupStmt?->close();
        $programStmt?->close();
        return false;
    }

    foreach ($affiliations as $item) {
        if (!is_array($item) || !isset($item['group_id']) || !is_scalar($item['group_id'])
            || !preg_match('/^[1-9][0-9]*$/D', (string) $item['group_id'])) {
            $groupStmt->close();
            $programStmt->close();
            return false;
        }
        $groupId = (int) $item['group_id'];
        if (isset($normalized[$groupId])) {
            $groupStmt->close();
            $programStmt->close();
            return false;
        }
        $groupStmt->bind_param('i', $groupId);
        if (!$groupStmt->execute() || $groupStmt->get_result()->num_rows !== 1) {
            $groupStmt->close();
            $programStmt->close();
            return false;
        }

        $rawProgramIds = $item['program_ids'] ?? [];
        if (!is_array($rawProgramIds)) {
            $groupStmt->close();
            $programStmt->close();
            return false;
        }
        $normalized[$groupId] = [];
        foreach ($rawProgramIds as $rawProgramId) {
            if (!is_scalar($rawProgramId) || !preg_match('/^[1-9][0-9]*$/D', (string) $rawProgramId)) {
                $groupStmt->close();
                $programStmt->close();
                return false;
            }
            $programId = (int) $rawProgramId;
            if (in_array($programId, $normalized[$groupId], true)) {
                $groupStmt->close();
                $programStmt->close();
                return false;
            }
            $programStmt->bind_param('ii', $groupId, $programId);
            if (!$programStmt->execute() || $programStmt->get_result()->num_rows !== 1) {
                $groupStmt->close();
                $programStmt->close();
                return false;
            }
            $normalized[$groupId][] = $programId;
        }
    }
    $groupStmt->close();
    $programStmt->close();
    // Keep existing inactive classifications intact when they are omitted from
    // the active-only editor. Admins can reactivate them before changing them.
    $inactiveStmt = $conn->prepare(
        'SELECT a.group_id, g.status AS group_status, pa.affiliation_program_id,
                COALESCE(gp.status, 0) AS mapping_status, COALESCE(p.status, 0) AS program_status
           FROM user_academic_affiliations a
           JOIN academic_affiliation_groups g ON g.group_id = a.group_id
           LEFT JOIN user_academic_program_assignments pa ON pa.affiliation_id = a.affiliation_id
           LEFT JOIN academic_group_programs gp ON gp.group_id = a.group_id AND gp.affiliation_program_id = pa.affiliation_program_id
           LEFT JOIN academic_affiliation_programs p ON p.affiliation_program_id = pa.affiliation_program_id
          WHERE a.user_id = ? AND (g.status = 0 OR gp.status = 0 OR p.status = 0)'
    );
    if (!$inactiveStmt) {
        return false;
    }
    $inactiveStmt->bind_param('i', $userId);
    if (!$inactiveStmt->execute()) {
        $inactiveStmt->close();
        return false;
    }
    $inactiveResult = $inactiveStmt->get_result();
    while ($row = $inactiveResult->fetch_assoc()) {
        $groupId = (int) $row['group_id'];
        $normalized[$groupId] ??= [];
        if ((int) $row['group_status'] === 0 || (int) $row['mapping_status'] === 0 || (int) $row['program_status'] === 0) {
            if ($row['affiliation_program_id'] !== null) {
                $programId = (int) $row['affiliation_program_id'];
                if (!in_array($programId, $normalized[$groupId], true)) {
                    $normalized[$groupId][] = $programId;
                }
            }
        }
    }
    $inactiveStmt->close();
    if ($requireGroup && !$normalized) {
        return false;
    }

    $delete = $conn->prepare('DELETE FROM user_academic_affiliations WHERE user_id = ?');
    if (!$delete) {
        return false;
    }
    $delete->bind_param('i', $userId);
    $ok = $delete->execute();
    $delete->close();
    if (!$ok) {
        return false;
    }

    $insertGroup = $conn->prepare(
        'INSERT INTO user_academic_affiliations (user_id, group_id, is_primary) VALUES (?, ?, ?)'
    );
    $insertProgram = $conn->prepare(
        'INSERT INTO user_academic_program_assignments (affiliation_id, group_id, affiliation_program_id) VALUES (?, ?, ?)'
    );
    if (!$insertGroup || !$insertProgram) {
        $insertGroup?->close();
        $insertProgram?->close();
        return false;
    }
    $primary = 1;
    foreach ($normalized as $groupId => $selectedProgramIds) {
        $insertGroup->bind_param('iii', $userId, $groupId, $primary);
        if (!$insertGroup->execute()) {
            $insertGroup->close();
            $insertProgram->close();
            return false;
        }
        $affiliationId = (int) $conn->insert_id;
        foreach ($selectedProgramIds as $programId) {
            $insertProgram->bind_param('iii', $affiliationId, $groupId, $programId);
            if (!$insertProgram->execute()) {
                $insertGroup->close();
                $insertProgram->close();
                return false;
            }
        }
        $primary = 0;
    }
    $insertGroup->close();
    $insertProgram->close();

    if ($role === 'faculty') {
        $deleteLegacy = $conn->prepare(
            'DELETE FROM faculty_program_assignments
              WHERE faculty_id = ? AND program_id IN (
                    SELECT mapped_programs.program_id FROM (
                        SELECT DISTINCT ap.program_id
                          FROM academic_affiliation_programs ap
                          JOIN academic_group_programs gp ON gp.affiliation_program_id = ap.affiliation_program_id
                         WHERE ap.program_id IS NOT NULL
                    ) mapped_programs
              )'
        );
        $insertLegacy = $conn->prepare(
            'INSERT INTO faculty_program_assignments (faculty_id, program_id)
             SELECT DISTINCT ?, affiliation_programs.program_id
               FROM academic_affiliation_programs affiliation_programs
               JOIN user_academic_program_assignments assignments
                 ON assignments.affiliation_program_id = affiliation_programs.affiliation_program_id
               JOIN user_academic_affiliations affiliations
                 ON affiliations.affiliation_id = assignments.affiliation_id
              WHERE affiliations.user_id = ? AND affiliation_programs.program_id IS NOT NULL'
        );
        if (!$deleteLegacy || !$insertLegacy) {
            $deleteLegacy?->close();
            $insertLegacy?->close();
            return false;
        }
        $deleteLegacy->bind_param('i', $userId);
        $ok = $deleteLegacy->execute();
        $deleteLegacy->close();
        if ($ok) {
            $insertLegacy->bind_param('ii', $userId, $userId);
            $ok = $insertLegacy->execute();
        }
        $insertLegacy->close();
        if (!$ok) {
            return false;
        }
    }
    return true;
}

function rmsAcademicAffiliationDisplay(mysqli $conn, int $userId): array
{
    $labels = [];
    $stmt = $conn->prepare(
        'SELECT g.group_name, p.program_name, p.is_specialization
           FROM user_academic_affiliations a
           JOIN academic_affiliation_groups g ON g.group_id = a.group_id
           LEFT JOIN user_academic_program_assignments assignments ON assignments.affiliation_id = a.affiliation_id
           LEFT JOIN academic_affiliation_programs p ON p.affiliation_program_id = assignments.affiliation_program_id
          WHERE a.user_id = ?
          ORDER BY a.is_primary DESC, g.group_name, p.program_name'
    );
    if (!$stmt) {
        return $labels;
    }
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $group = (string) $row['group_name'];
            $program = trim((string) ($row['program_name'] ?? ''));
            $labels[$group] ??= [];
            if ($program !== '') {
                $labels[$group][] = $program;
            }
        }
    }
    $stmt->close();
    foreach ($labels as $group => $programs) {
        $labels[$group] = $programs ? implode(', ', array_values(array_unique($programs))) : 'Not Applicable';
    }
    return $labels;
}
