<?php

/**
 * Reserve a student's single current research-project assignment.
 * The primary key on student_project_assignments serializes concurrent writes.
 */
function rms_claim_student_project_slot(mysqli $conn, int $student_id, int $project_id): bool
{
    $stmt = $conn->prepare(
        'INSERT INTO student_project_assignments (student_id, project_id) VALUES (?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the student project assignment.');
    }

    $stmt->bind_param('ii', $student_id, $project_id);
    try {
        $executed = $stmt->execute();
    } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        if ((int) $exception->getCode() === 1062) {
            return false;
        }
        throw new RuntimeException('Unable to save the student project assignment.', 0, $exception);
    }
    if (!$executed) {
        $duplicate = $stmt->errno === 1062;
        $stmtError = $stmt->error;
        $stmt->close();
        if ($duplicate) {
            return false;
        }
        throw new RuntimeException('Unable to save the student project assignment: ' . $stmtError);
    }
    $stmt->close();
    return true;
}

/**
 * Check whether a student's current project has an approved research title.
 * Historical projects outside the student's current assignment are ignored.
 */
function rms_student_has_approved_title(mysqli $conn, int $student_id): bool
{
    if ($student_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
           FROM student_project_assignments assignment
           JOIN research_projects rp ON rp.project_id = assignment.project_id
          WHERE assignment.student_id = ?
            AND rp.deleted_at IS NULL
            AND (
                rp.status IN ('title_approved', 'approved', 'ongoing', 'in_progress', 'progress_report', 'terminal_review', 'completed', 'archived')
                OR EXISTS (
                    SELECT 1
                      FROM research_submission_versions title_version
                     WHERE title_version.project_id = rp.project_id
                       AND title_version.submission_type = 'title'
                       AND title_version.status = 'approved'
                       AND NOT EXISTS (
                           SELECT 1
                             FROM research_submission_versions newer_title
                            WHERE newer_title.project_id = title_version.project_id
                              AND newer_title.submission_type = 'title'
                              AND (newer_title.version_number > title_version.version_number
                                OR (newer_title.version_number = title_version.version_number AND newer_title.version_id > title_version.version_id))
                       )
                )
            )
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check the student research title status: ' . $conn->error);
    }

    $stmt->bind_param('i', $student_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to check the student research title status: ' . $error);
    }
    $has_approved_title = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $has_approved_title;
}

function rms_release_student_project_slot(mysqli $conn, int $student_id, int $project_id): void
{
    $stmt = $conn->prepare(
        'DELETE FROM student_project_assignments WHERE student_id = ? AND project_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the student project release.');
    }
    $stmt->bind_param('ii', $student_id, $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to release the student project assignment: ' . $error);
    }
    $stmt->close();
}

function rms_validate_title_options($raw_options): array
{
    if (!is_array($raw_options) || count($raw_options) !== 3) {
        throw new InvalidArgumentException('Enter exactly three research title options.');
    }

    $titles = [];
    $normalized = [];
    foreach (array_values($raw_options) as $raw_title) {
        if (!is_string($raw_title)) {
            throw new InvalidArgumentException('Each research title must be text.');
        }
        $title = trim($raw_title);
        if ($title === '') {
            throw new InvalidArgumentException('All three research title options are required.');
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Each research title must not exceed 255 characters.');
        }
        $key = mb_strtolower($title);
        if (in_array($key, $normalized, true)) {
            throw new InvalidArgumentException('Research title options must be distinct.');
        }
        $titles[] = $title;
        $normalized[] = $key;
    }

    return $titles;
}

function rms_validate_research_area_ids($raw_area_ids): array
{
    if (!is_array($raw_area_ids) || count($raw_area_ids) < 1 || count($raw_area_ids) > 5) {
        throw new InvalidArgumentException('Select between one and five research areas.');
    }

    $area_ids = [];
    foreach ($raw_area_ids as $raw_area_id) {
        if ((!is_string($raw_area_id) && !is_int($raw_area_id))
            || !ctype_digit((string) $raw_area_id)
            || (int) $raw_area_id <= 0) {
            throw new InvalidArgumentException('One or more selected research areas are invalid.');
        }
        $area_id = (int) $raw_area_id;
        if (in_array($area_id, $area_ids, true)) {
            throw new InvalidArgumentException('Research areas cannot be selected more than once.');
        }
        $area_ids[] = $area_id;
    }

    return $area_ids;
}

function rms_research_area_catalog(mysqli $conn, bool $include_inactive = false, array $saved_area_ids = []): array
{
    $sql = 'SELECT ra.area_id, ra.area_code, ra.area_name, ra.description, ra.is_active,
                   scope.display_label, scope.is_enabled AS scope_enabled, scope.display_order,
                   GROUP_CONCAT(cra.category_id ORDER BY cra.category_id SEPARATOR \',\') AS category_ids
              FROM research_areas ra
         LEFT JOIN research_area_selection_scope scope ON scope.area_id = ra.area_id
         LEFT JOIN category_research_areas cra ON cra.area_id = ra.area_id'
        . ($include_inactive ? '' : ' WHERE (ra.is_active = 1 AND scope.is_enabled = 1)'
            . ($saved_area_ids ? ' OR ra.area_id IN (' . implode(',', array_fill(0, count($saved_area_ids), '?')) . ')' : ''))
        . ' GROUP BY ra.area_id, ra.area_code, ra.area_name, ra.description, ra.is_active,
                     scope.display_label, scope.is_enabled, scope.display_order
            ORDER BY COALESCE(scope.display_order, 100), ra.area_name';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the research-area catalog.');
    }
    if (!$include_inactive && $saved_area_ids) {
        $stmt->bind_param(str_repeat('i', count($saved_area_ids)), ...$saved_area_ids);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Unable to load the research-area catalog: ' . $conn->error);
    }

    $areas = [];
    while ($row = $result->fetch_assoc()) {
        $row['category_ids'] = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($row['category_ids'] ?? ''))
        ), static function (int $id): bool {
            return $id > 0;
        }));
        $row['is_active'] = (int) $row['is_active'] === 1;
        $row['proposal_eligible'] = $row['is_active'] && (int) $row['scope_enabled'] === 1;
        $row['selection_label'] = (string) ($row['display_label'] ?? $row['area_name']);
        $areas[] = $row;
    }
    $result->free();
    $stmt->close();
    return $areas;
}

function rms_student_research_program_id(mysqli $conn, int $user_id): int
{
    if ($user_id <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT p.program_id
           FROM users u
           JOIN programs p
             ON p.status = 1
            AND p.program_code IN ('BSIT', 'BSCS')
            AND (
                UPPER(TRIM(p.program_code)) = UPPER(TRIM(u.program))
                OR UPPER(TRIM(p.program_name)) = UPPER(TRIM(u.program))
            )
          WHERE u.user_id = ?
            AND u.role = 'student'
            AND u.status = 'active'
            AND u.deleted_at IS NULL
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare student program lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to look up the student program: ' . $error);
    }
    $program_id = (int) ($stmt->get_result()->fetch_assoc()['program_id'] ?? 0);
    $stmt->close();
    return $program_id;
}

function rms_research_category_catalog(mysqli $conn, bool $for_student = false, int $program_id = 0, int $saved_category_id = 0): array
{
    $program_filter = $for_student
        ? ($program_id > 0
            ? 'EXISTS (SELECT 1 FROM research_category_programs rcp WHERE rcp.category_id = rc.category_id AND rcp.program_id = ?)'
            : '1 = 0')
        : 'NOT EXISTS (SELECT 1 FROM research_category_programs rcp WHERE rcp.category_id = rc.category_id)';
    $sql = 'SELECT rc.category_id, rc.category_name, rc.status
              FROM research_categories rc
             WHERE (rc.status = 1 AND ' . $program_filter . ')'
        . ($saved_category_id > 0 ? ' OR rc.category_id = ?' : '')
        . ' ORDER BY rc.category_name ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the research-category catalog: ' . $conn->error);
    }
    if ($for_student && $program_id > 0 && $saved_category_id > 0) {
        $stmt->bind_param('ii', $program_id, $saved_category_id);
    } elseif ($for_student && $program_id > 0) {
        $stmt->bind_param('i', $program_id);
    } elseif ($saved_category_id > 0) {
        $stmt->bind_param('i', $saved_category_id);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load the research-category catalog: ' . $error);
    }
    $result = $stmt->get_result();
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $result->free();
    $stmt->close();
    return $categories;
}

function rms_research_category_is_available_for_submitter(mysqli $conn, int $category_id, int $program_id = 0, bool $student_submission = false): bool
{
    if ($student_submission) {
        if ($program_id <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            'SELECT 1 FROM research_category_programs WHERE category_id = ? AND program_id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare course-category validation: ' . $conn->error);
        }
        $stmt->bind_param('ii', $category_id, $program_id);
    } else {
        $stmt = $conn->prepare(
            'SELECT 1 FROM research_categories rc
              WHERE rc.category_id = ?
                AND NOT EXISTS (SELECT 1 FROM research_category_programs rcp WHERE rcp.category_id = rc.category_id)
              LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare faculty category validation: ' . $conn->error);
        }
        $stmt->bind_param('i', $category_id);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to validate the research category: ' . $error);
    }
    $available = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $available;
}

function rms_validate_research_category(mysqli $conn, int $category_id, int $retained_category_id = 0, int $program_id = 0, bool $student_submission = false): array
{
    $stmt = $conn->prepare(
        'SELECT category_id, category_name, status FROM research_categories WHERE category_id = ? LOCK IN SHARE MODE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare research-category validation.');
    }
    $stmt->bind_param('i', $category_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to validate the research category.');
    }
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // The retained ID must come from the owned project's saved row, never from form input.
    $is_retained = $category_id === $retained_category_id;
    if (!$category || ((int) $category['status'] !== 1 && !$is_retained)) {
        throw new InvalidArgumentException('Select an active research category. An inactive category can be retained only by a project already saved under it.');
    }
    if (!$is_retained && !rms_research_category_is_available_for_submitter($conn, $category_id, $program_id, $student_submission)) {
        throw new InvalidArgumentException('Select a research category available for your course and account type.');
    }
    return $category;
}

function rms_validate_mapped_research_areas(mysqli $conn, int $category_id, array $area_ids, array $retained_area_ids = [], int $retained_category_id = 0, int $program_id = 0, bool $student_submission = false): void
{
    if ($category_id <= 0 || !$area_ids) {
        throw new InvalidArgumentException('Select a research category and at least one research area.');
    }

    // Recheck category availability inside proposal-save transactions, including retained-only selections.
    rms_validate_research_category($conn, $category_id, $retained_category_id, $program_id, $student_submission);

    // A historical category can be retained, but it cannot be used to add
    // legacy areas after a student account changes course or taxonomy.
    if ($student_submission && $category_id === $retained_category_id
        && !rms_research_category_is_available_for_submitter($conn, $category_id, $program_id, true)
        && array_diff($area_ids, $retained_area_ids)) {
        throw new InvalidArgumentException('Saved historical areas may be retained only as they were originally recorded.');
    }

    // Only server-loaded saved associations may be retained under the same category.
    $area_ids = array_values(array_diff($area_ids, $retained_area_ids));
    if (!$area_ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($area_ids), '?'));
    $types = 'i' . str_repeat('i', count($area_ids));
    $sql = 'SELECT COUNT(DISTINCT ra.area_id) AS valid_count
              FROM research_areas ra
              JOIN category_research_areas cra ON cra.area_id = ra.area_id
              JOIN research_area_selection_scope scope ON scope.area_id = ra.area_id AND scope.is_enabled = 1
             WHERE cra.category_id = ?
               AND ra.is_active = 1
               AND ra.area_id IN (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare research-area validation: ' . $conn->error);
    }
    $values = array_merge([$category_id], $area_ids);
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to validate selected research areas: ' . $error);
    }
    $valid_count = (int) ($stmt->get_result()->fetch_assoc()['valid_count'] ?? 0);
    $stmt->close();
    if ($valid_count !== count($area_ids)) {
        throw new InvalidArgumentException('Choose enabled BSIT/BSCS research areas mapped to the selected category. Saved legacy areas may be retained only under their original category.');
    }
}

function rms_project_area_ids(mysqli $conn, int $project_id): array
{
    $stmt = $conn->prepare(
        'SELECT area_id FROM research_project_areas WHERE project_id = ? ORDER BY display_order'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare project research-area lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load project research areas: ' . $error);
    }
    $ids = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['area_id'];
    }
    $stmt->close();
    return $ids;
}

function rms_replace_project_areas(mysqli $conn, int $project_id, array $area_ids): void
{
    $delete = $conn->prepare('DELETE FROM research_project_areas WHERE project_id = ?');
    if (!$delete) {
        throw new RuntimeException('Unable to prepare project research-area update: ' . $conn->error);
    }
    $delete->bind_param('i', $project_id);
    if (!$delete->execute()) {
        $error = $delete->error;
        $delete->close();
        throw new RuntimeException('Unable to replace project research areas: ' . $error);
    }
    $delete->close();

    $insert = $conn->prepare(
        'INSERT INTO research_project_areas (project_id, area_id, display_order) VALUES (?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare project research-area insert: ' . $conn->error);
    }
    foreach (array_values($area_ids) as $index => $area_id) {
        $display_order = $index + 1;
        $insert->bind_param('iii', $project_id, $area_id, $display_order);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException('Unable to save project research areas: ' . $error);
        }
    }
    $insert->close();
}

function rms_research_area_snapshot(mysqli $conn, array $area_ids): array
{
    if (!$area_ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($area_ids), '?'));
    $stmt = $conn->prepare(
        'SELECT area_id, area_code, area_name
           FROM research_areas
          WHERE area_id IN (' . $placeholders . ')'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare research-area snapshot lookup: ' . $conn->error);
    }
    $types = str_repeat('i', count($area_ids));
    $stmt->bind_param($types, ...$area_ids);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load research-area labels: ' . $error);
    }
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[(int) $row['area_id']] = [
            'area_id' => (int) $row['area_id'],
            'area_code' => (string) $row['area_code'],
            'area_name' => (string) $row['area_name'],
        ];
    }
    $stmt->close();
    $snapshot = [];
    foreach ($area_ids as $area_id) {
        if (!isset($rows[$area_id])) {
            throw new RuntimeException('A selected research area no longer exists.');
        }
        $snapshot[] = $rows[$area_id];
    }
    return $snapshot;
}

/** Load the category and single area recorded for each proposed student title. */
function rms_project_title_classifications(mysqli $conn, int $project_id, int $fallback_category_id = 0, array $fallback_area_ids = []): array
{
    $classifications = array_fill(0, 3, ['category_id' => 0, 'area_id' => 0]);
    $stmt = $conn->prepare('SELECT title_option_number, category_id, area_id FROM research_project_title_classifications WHERE project_id = ? ORDER BY title_option_number');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare title classification lookup.');
    }
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load title classifications.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $index = (int) $row['title_option_number'] - 1;
        if ($index >= 0 && $index < 3) {
            $classifications[$index] = ['category_id' => (int) $row['category_id'], 'area_id' => (int) $row['area_id']];
        }
    }
    $result->free();
    $stmt->close();

    // Older student projects have one shared classification. Preserve it in
    // each title slot until the owner explicitly replaces it while editing.
    if ($fallback_category_id > 0 && !array_filter($classifications, static fn(array $item): bool => $item['category_id'] > 0)) {
        $fallback_area_id = (int) ($fallback_area_ids[0] ?? 0);
        foreach ($classifications as &$classification) {
            $classification = ['category_id' => $fallback_category_id, 'area_id' => $fallback_area_id];
        }
        unset($classification);
    }
    return $classifications;
}

function rms_validate_title_classifications(mysqli $conn, $raw_classifications, array $saved_classifications, int $program_id): array
{
    if (!is_array($raw_classifications) || count($raw_classifications) !== 3) {
        throw new InvalidArgumentException('Choose one research category and one research area for each of the three title options.');
    }
    $validated = [];
    for ($index = 0; $index < 3; $index++) {
        $raw = $raw_classifications[$index] ?? null;
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Choose a category and a research area for all three title options.');
        }
        $category_id = filter_var($raw['category_id'] ?? null, FILTER_VALIDATE_INT);
        $area_id = filter_var($raw['area_id'] ?? null, FILTER_VALIDATE_INT);
        $category_id = $category_id === false ? 0 : (int) $category_id;
        $area_id = $area_id === false ? 0 : (int) $area_id;
        if ($category_id <= 0 || $area_id <= 0) {
            throw new InvalidArgumentException('Choose one research category and one related research area for each title option.');
        }
        $saved = $saved_classifications[$index] ?? ['category_id' => 0, 'area_id' => 0];
        rms_validate_mapped_research_areas(
            $conn,
            $category_id,
            [$area_id],
            $category_id === (int) $saved['category_id'] && $area_id === (int) $saved['area_id'] ? [$area_id] : [],
            (int) $saved['category_id'],
            $program_id,
            true
        );
        $category = rms_validate_research_category($conn, $category_id, (int) $saved['category_id'], $program_id, true);
        $area = rms_research_area_snapshot($conn, [$area_id]);
        if (!$area) {
            throw new InvalidArgumentException('Choose an available research area for each title option.');
        }
        $validated[] = [
            'title_option_number' => $index + 1,
            'category_id' => $category_id,
            'category_name' => (string) $category['category_name'],
            'area_id' => $area_id,
            'area_name' => (string) $area[0]['area_name'],
        ];
    }
    return $validated;
}

function rms_save_title_classifications(mysqli $conn, int $project_id, array $classifications): void
{
    $delete = $conn->prepare('DELETE FROM research_project_title_classifications WHERE project_id = ?');
    if (!$delete) {
        throw new RuntimeException('Unable to prepare title classification update.');
    }
    $delete->bind_param('i', $project_id);
    if (!$delete->execute()) {
        $delete->close();
        throw new RuntimeException('Unable to update title classifications.');
    }
    $delete->close();
    $insert = $conn->prepare('INSERT INTO research_project_title_classifications (project_id, title_option_number, category_id, area_id) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to prepare title classification save.');
    }
    foreach ($classifications as $classification) {
        $option_number = (int) $classification['title_option_number'];
        $category_id = (int) $classification['category_id'];
        $area_id = (int) $classification['area_id'];
        $insert->bind_param('iiii', $project_id, $option_number, $category_id, $area_id);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('Unable to save all title classifications.');
        }
    }
    $insert->close();
}

function rms_ongoing_research_predicate(): string
{
    return "rp.status IN ('title_approved', 'ongoing', 'in_progress')
            AND NOT (
                COALESCE(ca.total_chapters, 0) = 5
                AND COALESCE(ca.approved_chapters, 0) = 5
                AND EXISTS (
                    SELECT 1
                      FROM research_submission_versions final_version
                     WHERE final_version.project_id = rp.project_id
                       AND final_version.submission_type = 'final_manuscript'
                       AND final_version.chapter_id IS NULL
                       AND final_version.status = 'approved'
                       AND NOT EXISTS (SELECT 1 FROM research_submission_versions newer_final WHERE newer_final.project_id = final_version.project_id AND newer_final.submission_type = 'final_manuscript' AND newer_final.chapter_id IS NULL AND newer_final.version_number > final_version.version_number)
                )
            )";
}

function rms_completed_research_predicate(): string
{
    return "COALESCE(ca.total_chapters, 0) = 5
            AND COALESCE(ca.approved_chapters, 0) = 5
            AND EXISTS (
                SELECT 1
                  FROM research_submission_versions final_version
                 WHERE final_version.project_id = rp.project_id
                   AND final_version.submission_type = 'final_manuscript'
                   AND final_version.chapter_id IS NULL
                   AND final_version.status = 'approved'
                       AND NOT EXISTS (SELECT 1 FROM research_submission_versions newer_final WHERE newer_final.project_id = final_version.project_id AND newer_final.submission_type = 'final_manuscript' AND newer_final.chapter_id IS NULL AND newer_final.version_number > final_version.version_number)
            )";
}

function rms_student_program_is_eligible(mysqli $conn, int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
           FROM users u
          WHERE u.user_id = ?
            AND u.role = 'student'
            AND u.status = 'active'
            AND u.deleted_at IS NULL
            AND EXISTS (
                SELECT 1
                  FROM programs p
                 WHERE p.status = 1
                   AND p.program_code IN ('BSIT', 'BSCS')
                   AND (
                       UPPER(TRIM(p.program_code)) = UPPER(TRIM(u.program))
                       OR UPPER(TRIM(p.program_name)) = UPPER(TRIM(u.program))
                   )
            )
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare student program eligibility check: ' . $conn->error);
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to verify student program eligibility: ' . $error);
    }
    $eligible = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $eligible;
}

function rms_project_students_are_eligible(mysqli $conn, int $project_id): bool
{
    if ($project_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT DISTINCT u.user_id
           FROM research_projects rp
           JOIN users u ON u.user_id = rp.created_by
          WHERE rp.project_id = ?
            AND u.role = 'student'
         UNION
         SELECT DISTINCT u.user_id
           FROM project_members pm
           JOIN users u ON u.user_id = pm.user_id
          WHERE pm.project_id = ?
            AND u.role = 'student'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare research team eligibility check: ' . $conn->error);
    }
    $stmt->bind_param('ii', $project_id, $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load research team eligibility: ' . $error);
    }
    $result = $stmt->get_result();
    $student_ids = [];
    while ($row = $result->fetch_assoc()) {
        $student_ids[] = (int) $row['user_id'];
    }
    $stmt->close();

    foreach ($student_ids as $student_id) {
        if (!rms_student_program_is_eligible($conn, $student_id)) {
            return false;
        }
    }
    return true;
}

function rms_project_status_allows_chapter_submission(string $status): bool
{
    return in_array($status, ['title_approved', 'approved', 'ongoing'], true);
}

function rms_chapter_sequence_allows_submission(int $chapter_number, ?string $previous_chapter_status): bool
{
    if ($chapter_number < 1 || $chapter_number > 5) {
        return false;
    }

    return $chapter_number === 1 || $previous_chapter_status === 'approved';
}

function rms_approved_chapter_numbers_are_complete(array $chapter_numbers): bool
{
    $approved_numbers = [];
    foreach ($chapter_numbers as $chapter_number) {
        if (!is_int($chapter_number) && !(is_string($chapter_number) && ctype_digit($chapter_number))) {
            continue;
        }
        $chapter_number = (int) $chapter_number;
        if ($chapter_number >= 1 && $chapter_number <= 5) {
            $approved_numbers[$chapter_number] = true;
        }
    }

    return count($approved_numbers) === 5;
}

function rms_submission_review_completes_project(string $submission_type, string $decision): bool
{
    return $submission_type === 'final_manuscript' && $decision === 'approved';
}

function rms_submission_review_permission(string $decision): ?string
{
    return match ($decision) {
        'approved' => 'research_approval',
        'returned' => 'research_review',
        default => null,
    };
}

function rms_review_role_can_decide(string $role, string $decision, ?string $granted_permission): bool
{
    $required_permission = rms_submission_review_permission($decision);
    if ($required_permission === null) {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }

    return $role === 'research_staff' && $granted_permission === $required_permission;
}

function rms_project_has_all_approved_chapters(mysqli $conn, int $project_id): bool
{
    if ($project_id <= 0) {
        return false;
    }

    $deleted_column = $conn->query("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
    if ($deleted_column === false) {
        throw new RuntimeException('Unable to verify chapter deletion status: ' . $conn->error);
    }
    $has_deleted_at = $deleted_column->num_rows > 0;
    $deleted_column->close();
    $deleted_filter = $has_deleted_at ? ' AND deleted_at IS NULL' : '';

    $stmt = $conn->prepare(
        'SELECT DISTINCT chapter_number
           FROM chapters
          WHERE project_id = ?
            AND chapter_number BETWEEN 1 AND 5
            AND status = \'approved\'' . $deleted_filter
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare final-stage chapter check: ' . $conn->error);
    }
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to verify approved chapters: ' . $error);
    }
    $result = $stmt->get_result();
    $approved_chapter_numbers = [];
    while ($row = $result->fetch_assoc()) {
        $approved_chapter_numbers[] = (int) $row['chapter_number'];
    }
    $stmt->close();
    return rms_approved_chapter_numbers_are_complete($approved_chapter_numbers);
}

function rms_latest_final_manuscript_status(mysqli $conn, int $project_id): ?string
{
    $stmt = $conn->prepare(
        "SELECT status
           FROM research_submission_versions
          WHERE project_id = ?
            AND submission_type = 'final_manuscript'
            AND chapter_id IS NULL
          ORDER BY version_number DESC
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare final manuscript status lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load final manuscript status: ' . $error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string) $row['status'] : null;
}

function rms_approved_final_manuscript_abstract(mysqli $conn, int $project_id): ?string
{
    if ($project_id <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT status, snapshot_json
           FROM research_submission_versions
          WHERE project_id = ?
            AND submission_type = 'final_manuscript'
            AND chapter_id IS NULL
          ORDER BY version_number DESC, version_id DESC
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare approved manuscript abstract lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load approved manuscript abstract: ' . $error);
    }
    $version = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$version || $version['status'] !== 'approved') {
        return null;
    }

    $snapshot = json_decode((string) $version['snapshot_json'], true);
    $abstract = is_array($snapshot) && is_string($snapshot['abstract'] ?? null)
        ? trim($snapshot['abstract'])
        : '';

    return $abstract !== '' ? $abstract : null;
}

function rms_capture_submission_version(mysqli $conn, int $project_id, ?int $chapter_id, string $type, int $submitter_id, array $snapshot, array $upload_ids = []): int
{
    if (!in_array($type, ['title', 'chapter', 'final_manuscript'], true)
        || $project_id <= 0
        || $submitter_id <= 0
        || ($type === 'chapter' && ($chapter_id === null || $chapter_id <= 0))
        || ($type !== 'chapter' && $chapter_id !== null)) {
        throw new InvalidArgumentException('Invalid submission version reference.');
    }

    $version_stmt = $conn->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
           FROM research_submission_versions
          WHERE project_id = ? AND submission_type = ? AND chapter_id ' . ($chapter_id === null ? 'IS NULL' : '= ?')
    );
    if (!$version_stmt) {
        throw new RuntimeException('Unable to prepare submission version lookup: ' . $conn->error);
    }
    if ($chapter_id === null) {
        $version_stmt->bind_param('is', $project_id, $type);
    } else {
        $version_stmt->bind_param('isi', $project_id, $type, $chapter_id);
    }
    if (!$version_stmt->execute()) {
        $error = $version_stmt->error;
        $version_stmt->close();
        throw new RuntimeException('Unable to determine submission version: ' . $error);
    }
    $version_number = (int) ($version_stmt->get_result()->fetch_assoc()['next_version'] ?? 1);
    $version_stmt->close();

    $snapshot_json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insert = $conn->prepare(
        "INSERT INTO research_submission_versions
            (project_id, chapter_id, submission_type, version_number, submitted_by, submitted_at, status, snapshot_json)
         VALUES (?, ?, ?, ?, ?, NOW(), 'submitted', ?)"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare submission version insert: ' . $conn->error);
    }
    $insert->bind_param('iisiis', $project_id, $chapter_id, $type, $version_number, $submitter_id, $snapshot_json);
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        throw new RuntimeException('Unable to preserve submitted version: ' . $error);
    }
    $version_id = (int) $insert->insert_id;
    $insert->close();

    if ($upload_ids) {
        $link = $conn->prepare('INSERT INTO research_submission_version_uploads (version_id, upload_id) VALUES (?, ?)');
        if (!$link) {
            throw new RuntimeException('Unable to prepare submitted attachment links: ' . $conn->error);
        }
        foreach (array_unique(array_map('intval', $upload_ids)) as $upload_id) {
            if ($upload_id <= 0) {
                continue;
            }
            $link->bind_param('ii', $version_id, $upload_id);
            if (!$link->execute()) {
                $error = $link->error;
                $link->close();
                throw new RuntimeException('Unable to preserve submitted attachment: ' . $error);
            }
        }
        $link->close();
    }

    return $version_id;
}

function rms_submission_can_review(int $user_id, string $decision): bool
{
    if ($user_id <= 0) {
        return false;
    }
    $permission = rms_submission_review_permission($decision);
    if ($permission === null) {
        return false;
    }

    $user_stmt = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    if (!$user_stmt) {
        return false;
    }
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $role = (string) ($user_stmt->get_result()->fetch_assoc()['role'] ?? '');
    $user_stmt->close();
    if ($role === 'admin') {
        return rms_review_role_can_decide($role, $decision, null);
    }
    if ($role !== 'research_staff') {
        return rms_review_role_can_decide($role, $decision, null);
    }

    $granted_permission = hasModulePermission($user_id, 'research_development', 'manage', $permission)
        ? $permission
        : null;
    return rms_review_role_can_decide($role, $decision, $granted_permission);
}

function rms_review_submission(
    mysqli $conn,
    int $version_id,
    int $reviewer_id,
    string $decision,
    string $comments,
    string $recommendation = '',
    string $selected_title = ''
): array
{
    if ($version_id <= 0 || !rms_submission_can_review($reviewer_id, $decision)) {
        throw new RuntimeException('You are not authorized to make this submission decision.');
    }
    $comments = trim($comments);
    $recommendation = trim($recommendation);
    $selected_title = trim($selected_title);
    if (mb_strlen($comments) > 5000 || mb_strlen($recommendation) > 5000) {
        throw new InvalidArgumentException('Review comments and suggestions must each be 5,000 characters or fewer.');
    }
    if (mb_strlen($selected_title) > 255) {
        throw new InvalidArgumentException('The selected title must not exceed 255 characters.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT v.version_id, v.project_id, v.chapter_id, v.submission_type, v.version_number, v.status, v.snapshot_json,
                    rp.title, rp.created_by, rp.status AS project_status,
                    ch.status AS chapter_status, ch.chapter_number
               FROM research_submission_versions v
               JOIN research_projects rp ON rp.project_id = v.project_id
          LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
              WHERE v.version_id = ?
              FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare submission review lookup.');
        }
        $stmt->bind_param('i', $version_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load the submission for review.');
        }
        $version = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$version || $version['status'] !== 'submitted') {
            throw new RuntimeException('This version is no longer awaiting review.');
        }

        if ($decision === 'approved') {
            $annotation_count_stmt = $conn->prepare(
                'SELECT COUNT(*) AS annotation_count
                   FROM research_submission_annotations
                  WHERE version_id = ?'
            );
            if (!$annotation_count_stmt) {
                throw new RuntimeException('Unable to verify document annotations before approval.');
            }
            $annotation_count_stmt->bind_param('i', $version_id);
            if (!$annotation_count_stmt->execute()) {
                $annotation_count_stmt->close();
                throw new RuntimeException('Unable to verify document annotations before approval.');
            }
            $annotation_count = (int) ($annotation_count_stmt->get_result()->fetch_assoc()['annotation_count'] ?? 0);
            $annotation_count_stmt->close();
            if ($annotation_count > 0) {
                throw new InvalidArgumentException('This version has document annotations. Return it for revision or remove all annotations before approving.');
            }
        }

        if ($version['submission_type'] === 'title') {
            $snapshot = json_decode((string) $version['snapshot_json'], true);
            $title_options = is_array($snapshot) && is_array($snapshot['title_options'] ?? null)
                ? array_values(array_filter($snapshot['title_options'], 'is_string'))
                : [];
            if (!$title_options && is_array($snapshot) && is_string($snapshot['title'] ?? null)) {
                $title_options = [trim($snapshot['title'])];
            }
            $title_options = array_values(array_filter(array_map('trim', $title_options), static function (string $title): bool {
                return $title !== '';
            }));
            $selected_title_classification = null;

            if ($decision === 'approved') {
                if ($selected_title === '' || !in_array($selected_title, $title_options, true)) {
                    throw new InvalidArgumentException('Choose exactly one title from this submitted version before approving it.');
                }
                foreach (($snapshot['title_classifications'] ?? []) as $classification) {
                    $option_number = (int) ($classification['title_option_number'] ?? 0);
                    if (($title_options[$option_number - 1] ?? null) === $selected_title) {
                        $selected_title_classification = $classification;
                        break;
                    }
                }
            } elseif ($selected_title !== '') {
                throw new InvalidArgumentException('Clear the title selection when returning a proposal for revision.');
            }

            if ($decision === 'returned' && mb_strlen($comments . $recommendation) < 10) {
                throw new InvalidArgumentException('Add consolidated Comments/Suggestions of at least 10 characters before returning this proposal.');
            }
        } elseif ($decision === 'returned' && mb_strlen($comments . $recommendation) < 10) {
            throw new InvalidArgumentException('Add Comments/Suggestions of at least 10 characters before returning this version.');
        } elseif ($selected_title !== '') {
            throw new InvalidArgumentException('A selected title is only valid when reviewing a title proposal.');
        }

        $latest = $conn->prepare(
            'SELECT version_id FROM research_submission_versions
              WHERE project_id = ? AND submission_type = ? AND chapter_id ' . ($version['chapter_id'] === null ? 'IS NULL' : '= ?') . '
              ORDER BY version_number DESC LIMIT 1'
        );
        if (!$latest) {
            throw new RuntimeException('Unable to verify the current submission version.');
        }
        if ($version['chapter_id'] === null) {
            $latest->bind_param('is', $version['project_id'], $version['submission_type']);
        } else {
            $latest->bind_param('isi', $version['project_id'], $version['submission_type'], $version['chapter_id']);
        }
        $latest->execute();
        $latest_id = (int) ($latest->get_result()->fetch_assoc()['version_id'] ?? 0);
        $latest->close();
        if ($latest_id !== $version_id) {
            throw new RuntimeException('Only the latest submitted version can be reviewed.');
        }
        if ($version['submission_type'] === 'final_manuscript'
            && ($version['chapter_id'] !== null
                || !rms_project_has_all_approved_chapters($conn, (int) $version['project_id']))) {
            throw new RuntimeException('A final manuscript can only be reviewed after all five chapters are approved.');
        }

        $review = $conn->prepare(
            'INSERT INTO research_submission_reviews
                (version_id, reviewer_id, decision, comments, recommendation, selected_title, reviewed_at)
             VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NOW())'
        );
        if (!$review) {
            throw new RuntimeException('Unable to prepare review history insert.');
        }
        $review->bind_param('iissss', $version_id, $reviewer_id, $decision, $comments, $recommendation, $selected_title);
        if (!$review->execute()) {
            throw new RuntimeException('Unable to record review decision.');
        }
        $review->close();

        $version_status = $decision === 'approved' ? 'approved' : 'returned';
        $version_update = $conn->prepare('UPDATE research_submission_versions SET status = ? WHERE version_id = ? AND status = \'submitted\'');
        if (!$version_update) {
            throw new RuntimeException('Unable to prepare version status update.');
        }
        $version_update->bind_param('si', $version_status, $version_id);
        if (!$version_update->execute() || $version_update->affected_rows !== 1) {
            throw new RuntimeException('The submission version changed while it was being reviewed.');
        }
        $version_update->close();

        if ($version['submission_type'] === 'title') {
            $project_status = $decision === 'approved' ? 'title_approved' : 'for_revision';
            $has_selected_classification = is_array($selected_title_classification)
                && (int) ($selected_title_classification['category_id'] ?? 0) > 0;
            $project_update = $decision === 'approved'
                ? $conn->prepare($has_selected_classification
                    ? "UPDATE research_projects SET title = ?, category_id = ?, status = ?, updated_at = NOW()
                        WHERE project_id = ? AND status IN ('submitted','under_review','under_crec_review','under_erec_review')"
                    : "UPDATE research_projects SET title = ?, status = ?, updated_at = NOW()
                        WHERE project_id = ? AND status IN ('submitted','under_review','under_crec_review','under_erec_review')")
                : $conn->prepare(
                    "UPDATE research_projects SET status = ?, updated_at = NOW()
                      WHERE project_id = ? AND status IN ('submitted','under_review','under_crec_review','under_erec_review')"
                );
            if (!$project_update) {
                throw new RuntimeException('Unable to prepare title decision update.');
            }
            if ($decision === 'approved') {
                if ($has_selected_classification) {
                    $selected_category_id = (int) $selected_title_classification['category_id'];
                    $project_update->bind_param('sisi', $selected_title, $selected_category_id, $project_status, $version['project_id']);
                } else {
                    $project_update->bind_param('ssi', $selected_title, $project_status, $version['project_id']);
                }
            } else {
                $project_update->bind_param('si', $project_status, $version['project_id']);
            }
        } elseif ($version['submission_type'] === 'chapter') {
            $chapter_status = $decision === 'approved' ? 'approved' : 'revision_required';
            $project_update = $decision === 'approved'
                ? $conn->prepare("UPDATE chapters SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE chapter_id = ? AND status IN ('submitted','under_review')")
                : $conn->prepare("UPDATE chapters SET status = ?, approved_by = NULL, approved_at = NULL, updated_at = NOW() WHERE chapter_id = ? AND status IN ('submitted','under_review')");
            if (!$project_update) {
                throw new RuntimeException('Unable to prepare chapter decision update.');
            }
            if ($decision === 'approved') {
                $project_update->bind_param('sii', $chapter_status, $reviewer_id, $version['chapter_id']);
            } else {
                $project_update->bind_param('si', $chapter_status, $version['chapter_id']);
            }
        } else {
            $project_update = null;
            if (rms_submission_review_completes_project((string) $version['submission_type'], $decision)) {
                $project_status = 'completed';
                $project_update = $conn->prepare(
                    "UPDATE research_projects SET status = ?, updated_at = NOW()
                      WHERE project_id = ?
                        AND status IN ('title_approved','approved','ongoing','in_progress','completed','archived')"
                );
                if (!$project_update) {
                    throw new RuntimeException('Unable to prepare final manuscript project status update.');
                }
                $project_update->bind_param('si', $project_status, $version['project_id']);
            }
        }
        if ($project_update !== null) {
            if (!$project_update->execute() || $project_update->affected_rows !== 1) {
                throw new RuntimeException('The submission status changed before the decision could be recorded.');
            }
            $project_update->close();
        }
        if ($version['submission_type'] === 'chapter' && $decision === 'approved'
            && rms_project_has_all_approved_chapters($conn, (int) $version['project_id'])
            && rms_latest_final_manuscript_status($conn, (int) $version['project_id']) === 'approved') {
            $restore_completion = $conn->prepare("UPDATE research_projects SET status = 'completed', updated_at = NOW() WHERE project_id = ? AND status = 'ongoing' AND deleted_at IS NULL");
            $restore_completion->bind_param('i', $version['project_id']);
            if (!$restore_completion->execute()) {
                throw new RuntimeException('Unable to restore project completion.');
            }
            $restore_completion->close();
        }
        $conn->commit();
        if ($version['submission_type'] === 'title' && $decision === 'approved') {
            $version['title'] = $selected_title;
            $version['selected_title'] = $selected_title;
        }
        return $version;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
