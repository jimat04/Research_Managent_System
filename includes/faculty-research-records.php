<?php
/** Independent, owner-scoped faculty metadata. No submission/workflow side effects. */

function facultyRecordEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function facultyRecordRequireUser(): array
{
    requireLogin();
    $user = getCurrentUser();
    // requireRole('faculty') deliberately also allows admins; this module does not.
    if (!$user || ($user['role'] ?? '') !== 'faculty') {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    return $user;
}

function facultyRecordAvailable(mysqli $conn): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('faculty_research_records','faculty_research_record_authors','faculty_research_record_sdgs')");
    $stmt->execute();
    $available = (int) $stmt->get_result()->fetch_row()[0] === 3;
    $stmt->close();
    return $available;
}

function facultyRecordWorkTypes(): array
{
    $types = require __DIR__ . '/../config/faculty-research-records.php';
    if (!is_array($types) || !$types) throw new RuntimeException('Research record configuration is unavailable.');
    foreach ($types as $key => $label) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)
            || !is_string($label) || trim($label) === '' || mb_strlen($label, 'UTF-8') > 120) {
            throw new RuntimeException('Research record configuration is invalid.');
        }
    }
    return $types;
}

function facultyRecordSdgs(): array
{
    return [1 => 'No Poverty', 2 => 'Zero Hunger', 3 => 'Good Health and Well-being',
        4 => 'Quality Education', 5 => 'Gender Equality', 6 => 'Clean Water and Sanitation',
        7 => 'Affordable and Clean Energy', 8 => 'Decent Work and Economic Growth',
        9 => 'Industry, Innovation and Infrastructure', 10 => 'Reduced Inequalities',
        11 => 'Sustainable Cities and Communities', 12 => 'Responsible Consumption and Production',
        13 => 'Climate Action', 14 => 'Life Below Water', 15 => 'Life on Land',
        16 => 'Peace, Justice and Strong Institutions', 17 => 'Partnerships for the Goals'];
}

function facultyRecordConferenceClasses(): array
{
    // Exact categories in the supplied Annex A; QSF-RDS-019 wording differs.
    return ['regional_national_local' => 'Regional/National/Local Conference', 'international' => 'International Conference'];
}

function facultyRecordModalities(): array
{
    return ['in_person' => 'In-Person', 'virtual' => 'Virtual'];
}

function facultyRecordProfile(mysqli $conn, int $facultyId): ?array
{
    $stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, academic_rank, designation, appointment_status, department, email, contact FROM users WHERE user_id = ? AND role = 'faculty' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($profile) {
        $profile['full_name'] = trim(implode(' ', array_filter([$profile['first_name'], $profile['middle_name'], $profile['last_name']], static fn($part) => $part !== null && $part !== '')));
        $profile['position'] = trim((string) ($profile['academic_rank'] ?: $profile['designation']));
        $profile['affiliation'] = 'EARIST';
    }
    return $profile;
}

function facultyRecordSuggestedAuthor(array $profile, string $authorRole = 'coauthor'): array
{
    return ['record_author_id' => '', 'faculty_user_id' => (string) $profile['user_id'],
        'author_type' => 'internal', 'full_name' => $profile['full_name'], 'position' => $profile['position'],
        'affiliation' => $profile['affiliation'], 'author_role' => $authorRole];
}

function facultyRecordSearchFaculty(mysqli $conn, string $query): array
{
    $query = mb_substr(trim($query), 0, 120, 'UTF-8');
    if (mb_strlen($query, 'UTF-8') < 2) return [];
    $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, academic_rank, designation FROM users WHERE role = 'faculty' AND status = 'active' AND deleted_at IS NULL AND CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ? ESCAPE '!' ORDER BY last_name, first_name, user_id LIMIT 20");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Always owner-scoped. A future admin reader must be a separate authorized mechanism. */
function facultyRecordLoadOwned(mysqli $conn, int $recordId, int $ownerId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM faculty_research_records WHERE research_record_id = ? AND owner_id = ? LIMIT 1');
    $stmt->bind_param('ii', $recordId, $ownerId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$record) return null;
    $stmt = $conn->prepare('SELECT a.* FROM faculty_research_record_authors a JOIN faculty_research_records r ON r.research_record_id = a.research_record_id WHERE r.research_record_id = ? AND r.owner_id = ? ORDER BY a.display_order, a.record_author_id');
    $stmt->bind_param('ii', $recordId, $ownerId);
    $stmt->execute();
    $record['authors'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare('SELECT s.sdg_number FROM faculty_research_record_sdgs s JOIN faculty_research_records r ON r.research_record_id = s.research_record_id WHERE r.research_record_id = ? AND r.owner_id = ? ORDER BY s.sdg_number');
    $stmt->bind_param('ii', $recordId, $ownerId);
    $stmt->execute();
    $record['sdgs'] = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'sdg_number'));
    $stmt->close();
    return $record;
}

function facultyRecordTextFields(): array
{
    return ['title' => 2000, 'work_type' => 64, 'work_type_other' => 120,
        'date_started' => 7, 'date_completed' => 7, 'conference_title' => 500,
        'conference_host' => 500, 'conference_organizer' => 500, 'conference_classification' => 40,
        'conference_start_date' => 10, 'conference_end_date' => 10, 'presentation_modality' => 20,
        'conference_venue' => 500, 'journal_title' => 500, 'journal_publisher' => 500, 'sdg_other' => 500];
}

function facultyRecordInput(array $source, string $key, array &$errors): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value) && !is_int($value)) {
        $errors[$key] = 'Please supply a valid value.';
        return '';
    }
    $value = trim((string) $value);
    if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
        $errors[$key] = 'Please supply valid text.';
        return '';
    }
    return $value;
}

/** Retain input during server-rendered author search/reordering without saving it. */
function facultyRecordReadInput(array $post): array
{
    $errors = [];
    $values = [];
    foreach (facultyRecordTextFields() as $key => $limit) $values[$key] = facultyRecordInput($post, $key, $errors);
    $authors = [];
    if (isset($post['authors']) && !is_array($post['authors'])) $errors['authors'] = 'Please supply a valid author list.';
    foreach (is_array($post['authors'] ?? null) ? $post['authors'] : [] as $row) {
        if (!is_array($row)) { $errors['authors'] = 'Please supply a valid author list.'; continue; }
        $author = [];
        foreach (['record_author_id', 'faculty_user_id', 'author_type', 'full_name', 'position', 'affiliation', 'author_role'] as $key) {
            $rowErrors = [];
            $author[$key] = facultyRecordInput($row, $key, $rowErrors);
            if ($rowErrors) $errors['authors'] = 'Please supply valid author details.';
        }
        $authors[] = $author;
    }
    $sdgs = [];
    if (isset($post['sdgs']) && !is_array($post['sdgs'])) $errors['sdgs'] = 'Please select valid SDGs.';
    foreach (is_array($post['sdgs'] ?? null) ? $post['sdgs'] : [] as $raw) {
        $number = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 17]]) : false;
        if (!$number || in_array($number, $sdgs, true)) $errors['sdgs'] = 'Choose each SDG once, using the listed options.';
        else $sdgs[] = $number;
    }
    return ['values' => $values, 'authors' => $authors, 'sdgs' => $sdgs, 'errors' => $errors];
}

function facultyRecordValidate(mysqli $conn, array $input, array $workTypes, ?array $existing): array
{
    $errors = $input['errors'];
    $values = $input['values'];
    foreach (facultyRecordTextFields() as $key => $limit) {
        if (mb_strlen($values[$key], 'UTF-8') > $limit) $errors[$key] = "Use {$limit} characters or fewer.";
    }
    if ($values['title'] === '') $errors['title'] = 'Enter the research title.';
    if (!isset($workTypes[$values['work_type']])) $errors['work_type'] = 'Choose a listed scholarly-work type.';
    if ($values['work_type'] === 'other' && $values['work_type_other'] === '') $errors['work_type_other'] = 'Describe the other scholarly-work type.';
    if ($values['work_type'] !== 'other') $values['work_type_other'] = '';
    foreach (['date_started', 'date_completed'] as $key) {
        if ($values[$key] !== '' && !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $values[$key])) $errors[$key] = 'Enter a valid month and year (1000–9999).';
    }
    if ($values['date_started'] !== '' && $values['date_completed'] !== '' && $values['date_completed'] < $values['date_started']) $errors['date_completed'] = 'Completion cannot be earlier than the start month.';
    foreach (['conference_start_date', 'conference_end_date'] as $key) {
        if ($values[$key] === '') continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values[$key]);
        if (!$date || $date->format('Y-m-d') !== $values[$key] || $values[$key] < '1000-01-01') $errors[$key] = 'Enter a valid calendar date (1000–9999).';
    }
    if ($values['conference_start_date'] !== '' && $values['conference_end_date'] !== '' && $values['conference_end_date'] < $values['conference_start_date']) $errors['conference_end_date'] = 'Conference end cannot be earlier than its start.';
    foreach (['conference_classification' => facultyRecordConferenceClasses(), 'presentation_modality' => facultyRecordModalities()] as $key => $options) {
        if ($values[$key] !== '' && !isset($options[$values[$key]])) $errors[$key] = 'Choose a listed option or leave this blank.';
    }
    $authors = $input['authors'];
    if (!$authors) $errors['authors'] = 'Add at least one author.';
    $existingAuthors = [];
    foreach ($existing['authors'] ?? [] as $author) $existingAuthors[(int) $author['record_author_id']] = $author;
    $seenIds = $seenUsers = $seenIdentities = [];
    $leadCount = 0;
    foreach ($authors as $index => &$author) {
        $prefix = 'Author ' . ($index + 1) . ': ';
        $id = $author['record_author_id'] === '' ? 0 : filter_var($author['record_author_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ($id && (!isset($existingAuthors[$id]) || isset($seenIds[$id])))) $errors['authors'] = $prefix . 'the saved author reference is invalid. Reload this record.';
        if ($id) $seenIds[$id] = true;
        $author['record_author_id'] = $id ?: 0;
        foreach (['full_name' => 255, 'position' => 255, 'affiliation' => 500] as $key => $limit) {
            if ($author[$key] === '' || mb_strlen($author[$key], 'UTF-8') > $limit) $errors['authors'] = $prefix . "name, position, and affiliation are required within their displayed limits ({$key}: {$limit}).";
        }
        if (!in_array($author['author_type'], ['internal', 'external'], true)) $errors['authors'] = $prefix . 'choose Internal or External.';
        if (!in_array($author['author_role'], ['lead', 'coauthor'], true)) $errors['authors'] = $prefix . 'choose an authorship role.';
        if ($author['author_role'] === 'lead') $leadCount++;
        $userId = null;
        if ($author['author_type'] === 'internal') {
            $prior = $id ? ($existingAuthors[$id] ?? null) : null;
            $raw = $author['faculty_user_id'];
            $candidate = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $unchanged = $prior && $prior['author_type'] === 'internal' && (string) ($prior['faculty_user_id'] ?? '') === $raw;
            if (!$unchanged && (!$candidate || !facultyRecordProfile($conn, (int) $candidate))) $errors['authors'] = $prefix . 'select a registered active faculty author using the search.';
            $userId = $candidate ?: null;
            if ($userId && isset($seenUsers[$userId])) $errors['authors'] = $prefix . 'this faculty account is already listed.';
            if ($userId) $seenUsers[$userId] = true;
        }
        $author['faculty_user_id'] = $userId;
        $normalize = static fn(string $text): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
        $identity = hash('sha256', $normalize($author['full_name']) . "\n" . $normalize($author['affiliation']));
        if (isset($seenIdentities[$identity])) $errors['authors'] = $prefix . 'this name and affiliation are already listed.';
        $seenIdentities[$identity] = true;
        $author['identity_key'] = $identity;
        $author['display_order'] = $index + 1;
    }
    unset($author);
    if ($leadCount !== 1) $errors['authors'] = 'Choose exactly one Lead Author; mark the remaining authors as Co-Authors.';
    if ($authors && $authors[0]['author_role'] !== 'lead') {
        $errors['authors'] = 'Author number 1 must be the Lead Author. Move the lead author to the top before saving.';
    }
    $values['work_type_label'] = $workTypes[$values['work_type']] ?? '';
    return ['values' => $values, 'authors' => $authors, 'sdgs' => $input['sdgs'], 'errors' => $errors];
}

function facultyRecordCreateToken(): string
{
    foreach ($_SESSION['faculty_record_create_tokens'] ?? [] as $token => $created) {
        if ($created < time() - 86400) unset($_SESSION['faculty_record_create_tokens'][$token]);
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['faculty_record_create_tokens'][$token] = time();
    return $token;
}

function facultyRecordTokenValid(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/D', $token) === 1
        && (int) ($_SESSION['faculty_record_create_tokens'][$token] ?? 0) >= time() - 86400;
}

function facultyRecordCreatedId(mysqli $conn, int $ownerId, string $hash): ?int
{
    $stmt = $conn->prepare('SELECT research_record_id FROM faculty_research_records WHERE owner_id = ? AND creation_token_hash = ? LIMIT 1');
    $stmt->bind_param('is', $ownerId, $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['research_record_id'] : null;
}

/** Caller must validate CSRF and all input first. Ownership is enforced again here. */
function facultyRecordSave(mysqli $conn, int $ownerId, ?int $recordId, int $version, string $token, array $data): int
{
    if ($data['errors']) throw new DomainException('Correct the highlighted fields before saving.');
    if (!$recordId && !facultyRecordTokenValid($token)) throw new DomainException('This form expired. Open Add Research Record again.');
    $tokenHash = hash('sha256', $token);
    $conn->begin_transaction();
    try {
        if ($recordId) {
            $stmt = $conn->prepare('SELECT version, deleted_at FROM faculty_research_records WHERE research_record_id = ? AND owner_id = ? FOR UPDATE');
            $stmt->bind_param('ii', $recordId, $ownerId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current || $current['deleted_at'] !== null) throw new DomainException('This record is unavailable for editing. Restore it first if it was deleted.');
            if ((int) $current['version'] !== $version) throw new DomainException('This record changed in another tab. Your entries are still shown below. Open the latest saved record in a new tab and reconcile your changes before editing again.');
        } else {
            $alreadyCreated = facultyRecordCreatedId($conn, $ownerId, $tokenHash);
            if ($alreadyCreated) { $conn->rollback(); return $alreadyCreated; }
        }
        $values = $data['values'];
        $columns = array_keys(facultyRecordTextFields());
        $columns[] = 'work_type_label';
        $params = [];
        foreach ($columns as $column) $params[] = $values[$column] === '' ? null : $values[$column];
        $types = str_repeat('s', count($params));
        if ($recordId) {
            // Identifiers come only from the fixed field map, never request data.
            $sets = implode(', ', array_map(static fn($key) => '`' . $key . '` = ?', $columns));
            $stmt = $conn->prepare('UPDATE faculty_research_records SET ' . $sets . ', version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE research_record_id = ? AND owner_id = ? AND version = ? AND deleted_at IS NULL');
            array_push($params, $recordId, $ownerId, $version);
            $types .= 'iii';
        } else {
            $sqlColumns = implode(', ', array_map(static fn($key) => '`' . $key . '`', $columns));
            $stmt = $conn->prepare('INSERT INTO faculty_research_records (' . $sqlColumns . ', owner_id, creation_token_hash) VALUES (' . implode(',', array_fill(0, count($columns) + 2, '?')) . ')');
            array_push($params, $ownerId, $tokenHash);
            $types .= 'is';
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if (!$recordId) $recordId = (int) $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare('SELECT record_author_id FROM faculty_research_record_authors WHERE research_record_id = ?');
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $oldIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'record_author_id'));
        $stmt->close();
        $retained = [];
        foreach ($data['authors'] as $author) {
            $id = (int) $author['record_author_id'];
            if ($id && !in_array($id, $oldIds, true)) throw new DomainException('An author reference changed. Reload this record before saving.');
            if ($id) $retained[] = $id;
        }
        // Free uniqueness slots before swaps/reordering, preserving retained author IDs.
        foreach ($oldIds as $offset => $id) {
            if (!in_array($id, $retained, true)) {
                $stmt = $conn->prepare('DELETE FROM faculty_research_record_authors WHERE research_record_id = ? AND record_author_id = ?');
                $stmt->bind_param('ii', $recordId, $id);
            } else {
                $temporaryOrder = count($oldIds) + count($data['authors']) + $offset + 1;
                $temporaryKey = hash('sha256', random_bytes(32));
                $stmt = $conn->prepare('UPDATE faculty_research_record_authors SET faculty_user_id = NULL, display_order = ?, identity_key = ? WHERE research_record_id = ? AND record_author_id = ?');
                $stmt->bind_param('isii', $temporaryOrder, $temporaryKey, $recordId, $id);
            }
            $stmt->execute();
            $stmt->close();
        }
        foreach ($data['authors'] as $author) {
            $params = [$author['faculty_user_id'], $author['author_type'], $author['full_name'], $author['position'], $author['affiliation'], $author['author_role'], $author['display_order'], $author['identity_key'], $recordId];
            $types = 'isssssisi';
            if ($author['record_author_id']) {
                $stmt = $conn->prepare('UPDATE faculty_research_record_authors SET faculty_user_id = ?, author_type = ?, full_name = ?, position = ?, affiliation = ?, author_role = ?, display_order = ?, identity_key = ? WHERE research_record_id = ? AND record_author_id = ?');
                $params[] = $author['record_author_id'];
                $types .= 'i';
            } else {
                $stmt = $conn->prepare('INSERT INTO faculty_research_record_authors (faculty_user_id, author_type, full_name, position, affiliation, author_role, display_order, identity_key, research_record_id) VALUES (?,?,?,?,?,?,?,?,?)');
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare('DELETE FROM faculty_research_record_sdgs WHERE research_record_id = ?');
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('INSERT INTO faculty_research_record_sdgs (research_record_id, sdg_number) VALUES (?, ?)');
        foreach ($data['sdgs'] as $number) { $stmt->bind_param('ii', $recordId, $number); $stmt->execute(); }
        $stmt->close();
        if (!logActivity('Saved faculty research record #' . $recordId, 'faculty_research_records')) throw new RuntimeException('Unable to record activity.');
        $conn->commit();
        return $recordId;
    } catch (Throwable $exception) {
        $conn->rollback();
        // Concurrent retries may lose the unique creation-token race.
        if (!$version && (int) $exception->getCode() === 1062) {
            $alreadyCreated = facultyRecordCreatedId($conn, $ownerId, $tokenHash);
            if ($alreadyCreated) return $alreadyCreated;
        }
        throw $exception;
    }
}

function facultyRecordChangeState(mysqli $conn, int $ownerId, int $recordId, int $version, string $action): void
{
    if (!in_array($action, ['archive', 'delete', 'restore'], true)) throw new DomainException('Choose a supported action.');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT status, deleted_at, version FROM faculty_research_records WHERE research_record_id = ? AND owner_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $recordId, $ownerId);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$record) throw new DomainException('This record is unavailable.');
        if ((int) $record['version'] !== $version) throw new DomainException('This record changed. Refresh the list before trying again.');
        $status = $record['status'];
        $deletedAt = $record['deleted_at'];
        if ($action === 'archive') {
            if ($deletedAt !== null || $status !== 'active') throw new DomainException('Only active records can be archived.');
            $status = 'archived';
        } elseif ($action === 'delete') {
            if ($deletedAt !== null) throw new DomainException('This record is already in Deleted / Recoverable.');
            // Use the database clock for deletion and modification timestamps.
            $deletedAt = 'delete';
        } elseif ($deletedAt !== null) {
            $deletedAt = null; // Retain the status from before deletion.
        } elseif ($status === 'archived') {
            $status = 'active';
        } else {
            throw new DomainException('This record is already active.');
        }
        if ($deletedAt === 'delete') {
            $stmt = $conn->prepare('UPDATE faculty_research_records SET deleted_at = CURRENT_TIMESTAMP, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE research_record_id = ? AND owner_id = ? AND version = ?');
            $stmt->bind_param('iii', $recordId, $ownerId, $version);
        } else {
            $stmt = $conn->prepare('UPDATE faculty_research_records SET status = ?, deleted_at = NULL, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE research_record_id = ? AND owner_id = ? AND version = ?');
            $stmt->bind_param('siii', $status, $recordId, $ownerId, $version);
        }
        $stmt->execute();
        $stmt->close();
        if (!logActivity(ucfirst($action) . ' faculty research record #' . $recordId, 'faculty_research_records')) throw new RuntimeException('Unable to record activity.');
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function facultyRecordStatus(array $record): string
{
    return $record['deleted_at'] !== null ? 'Deleted / Recoverable' : ($record['status'] === 'archived' ? 'Archived' : 'Active');
}

function facultyRecordBrowseOptions(array $query): array
{
    $string = static fn(string $key, string $fallback): string => is_string($query[$key] ?? null) ? $query[$key] : $fallback;
    $status = $string('status', 'active');
    $sort = $string('sort', 'newest');
    return ['q' => mb_substr(trim($string('q', '')), 0, 150, 'UTF-8'),
        'status' => in_array($status, ['active', 'archived', 'deleted'], true) ? $status : 'active',
        'sort' => in_array($sort, ['newest', 'oldest', 'updated', 'title'], true) ? $sort : 'newest',
        'page' => filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1];
}

function facultyRecordBrowsePageSize(): int
{
    return 10;
}

function facultyRecordBrowse(mysqli $conn, int $ownerId, array $options): array
{
    $where = 'r.owner_id = ?';
    $params = [$ownerId];
    $types = 'i';
    if ($options['status'] === 'deleted') $where .= ' AND r.deleted_at IS NOT NULL';
    else {
        $where .= ' AND r.deleted_at IS NULL AND r.status = ?';
        $params[] = $options['status'];
        $types .= 's';
    }
    if ($options['q'] !== '') {
        $like = '%' . strtr($options['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where .= " AND (r.title LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM faculty_research_record_authors a WHERE a.research_record_id = r.research_record_id AND a.full_name LIKE ? ESCAPE '!'))";
        array_push($params, $like, $like);
        $types .= 'ss';
    }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM faculty_research_records r WHERE ' . $where);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $pageSize = facultyRecordBrowsePageSize();
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($options['page'], $pages);
    $orders = ['newest' => 'r.created_at DESC, r.research_record_id DESC', 'oldest' => 'r.created_at ASC, r.research_record_id ASC',
        'updated' => 'r.updated_at DESC, r.research_record_id DESC', 'title' => 'r.title ASC, r.research_record_id ASC'];
    $stmt = $conn->prepare("SELECT r.research_record_id, r.title, r.work_type_label, r.work_type_other, r.status, r.deleted_at, r.version, r.created_at, r.updated_at,
        (SELECT a.full_name FROM faculty_research_record_authors a WHERE a.research_record_id = r.research_record_id AND a.author_role = 'lead' ORDER BY a.display_order LIMIT 1) AS lead_author,
        (SELECT COUNT(*) FROM faculty_research_record_authors a WHERE a.research_record_id = r.research_record_id) AS author_count
        FROM faculty_research_records r WHERE " . $where . ' ORDER BY ' . $orders[$options['sort']] . ' LIMIT ? OFFSET ?');
    array_push($params, $pageSize, ($page - 1) * $pageSize);
    $stmt->bind_param($types . 'ii', ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function facultyRecordRenderActions(array $record, array $browse = [], bool $detail = false): void
{
    $id = (int) $record['research_record_id'];
    $deleted = $record['deleted_at'] !== null;
    $actionUrl = facultyRecordUrl('list', $browse);
    $hidden = static function () use ($record, $id, $detail): void {
        echo csrfField();
        echo '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="version" value="' . (int) $record['version'] . '">';
        if ($detail) echo '<input type="hidden" name="return_to" value="detail">';
    };
    ?>
    <div class="frr-actions">
      <?php if (!$detail): ?><a class="frr-btn frr-btn--small" href="<?= facultyRecordEscape(facultyRecordUrl('detail', ['id' => $id])) ?>">View</a><?php endif; ?>
      <?php if (!$deleted): ?><a class="frr-btn frr-btn--small<?= $detail ? ' frr-btn--primary' : '' ?>" href="<?= facultyRecordEscape(facultyRecordUrl('form', ['id' => $id])) ?>">Edit</a><?php endif; ?>
      <?php if ($deleted || $record['status'] === 'archived'): ?>
        <form method="post" action="<?= facultyRecordEscape($actionUrl) ?>" data-state-form><?php $hidden(); ?><button class="frr-btn frr-btn--small" type="submit" name="record_action" value="restore">Restore<?= $detail && $deleted ? ' to ' . ($record['status'] === 'archived' ? 'Archived' : 'Active') : '' ?></button></form>
      <?php else: ?>
        <form method="post" action="<?= facultyRecordEscape($actionUrl) ?>" data-state-form><?php $hidden(); ?><button class="frr-btn frr-btn--small" type="submit" name="record_action" value="archive">Archive</button></form>
      <?php endif; ?>
      <?php if (!$deleted): ?>
        <details class="frr-delete" data-delete-disclosure><summary class="frr-btn frr-btn--small frr-btn--danger-text">Delete</summary><form method="post" action="<?= facultyRecordEscape($actionUrl) ?>" data-state-form data-delete-confirm><?php $hidden(); ?><p>This record will move to Deleted / Recoverable. Its research information and authors will be kept. You can restore it later.</p><label class="frr-check"><input type="checkbox" name="confirm_delete" value="1" required><span>I want to move this record to Deleted / Recoverable.</span></label><button type="submit" name="record_action" value="delete" class="frr-btn frr-btn--danger">Move to Deleted</button></form></details>
      <?php endif; ?>
    </div>
    <?php
}

function facultyRecordUrl(string $page, array $params = []): string
{
    $pages = ['list' => 'faculty-research-records.php', 'form' => 'faculty-research-record-form.php', 'detail' => 'faculty-research-record-detail.php'];
    return SITE_URL . 'pages/faculty/' . $pages[$page] . ($params ? '?' . http_build_query($params) : '');
}

function facultyRecordAssets(): void
{
    echo '<link rel="stylesheet" href="' . facultyRecordEscape(SITE_URL . 'css/faculty-research-records.css?v=' . filemtime(__DIR__ . '/../css/faculty-research-records.css')) . '">';
    echo '<script src="' . facultyRecordEscape(SITE_URL . 'js/faculty-research-records.js?v=' . filemtime(__DIR__ . '/../js/faculty-research-records.js')) . '" defer></script>';
}

function facultyRecordNotFound(array $user): never
{
    http_response_code(404);
    renderFacultyShell($user, 'faculty-research-records', 'My Research Records');
    facultyRecordAssets();
    echo '<section class="frr-panel"><h2>Record unavailable</h2><p>This record could not be found in your research records.</p><a href="' . facultyRecordEscape(facultyRecordUrl('list')) . '">Return to My Research Records</a></section>';
    renderFacultyShellClose();
    exit;
}
