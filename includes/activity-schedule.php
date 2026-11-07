<?php

/**
 * Activity schedule helpers. Schedule dates and date-scoped attendees are
 * additive to the legacy activity/participant tables so existing records and
 * participant history remain available.
 */
function rmsActivitySchedulePrepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare activity schedule data.');
    }
    return $stmt;
}

function rmsActivityScheduleTableExists(mysqli $conn, string $tableName): bool
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $tableName);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to verify activity module prerequisites.');
    }
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function rmsActivityScheduleInvitedActivityIds(mysqli $conn, int $userId): array
{
    if ($userId <= 0
        || !rmsActivityScheduleTableExists($conn, 'activity_schedule_dates')
        || !rmsActivityScheduleTableExists($conn, 'activity_schedule_participants')) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT DISTINCT d.activity_id
         FROM activity_schedule_dates d
         INNER JOIN activity_schedule_participants sp ON sp.schedule_id = d.schedule_id
         WHERE sp.user_id = ? AND sp.is_invited = 1'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $activityIds = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $activityId = (int) ($row['activity_id'] ?? 0);
        if ($activityId > 0) {
            $activityIds[$activityId] = $activityId;
        }
    }
    $stmt->close();

    return array_values($activityIds);
}

function rmsActivityScheduleUserInvited(mysqli $conn, int $activityId, int $userId): bool
{
    if ($activityId <= 0 || $userId <= 0
        || !rmsActivityScheduleTableExists($conn, 'activity_schedule_dates')
        || !rmsActivityScheduleTableExists($conn, 'activity_schedule_participants')) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM activity_schedule_dates d
         INNER JOIN activity_schedule_participants sp ON sp.schedule_id = d.schedule_id
         WHERE d.activity_id = ? AND sp.user_id = ? AND sp.is_invited = 1
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $activityId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $invited = $result instanceof mysqli_result && $result->num_rows > 0;
    $stmt->close();

    return $invited;
}

function rmsActivityScheduleColumnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $tableName, $columnName);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to verify activity schedule prerequisites.');
    }
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function rmsActivityScheduleSessionClockStatus(array $schedule, ?int $now = null): string
{
    $scheduleStatus = strtolower((string) ($schedule['status'] ?? 'scheduled'));
    if ($scheduleStatus === 'cancelled') {
        return 'cancelled';
    }
    $start = (int) ($schedule['clock_start_timestamp'] ?? 0);
    $end = (int) ($schedule['clock_end_timestamp'] ?? 0);
    if ($start <= 0 || $end <= 0) {
        return 'scheduled';
    }
    $now ??= time();
    if ($now < $start) {
        return 'upcoming';
    }
    return $now <= $end ? 'ongoing' : 'past';
}

function rmsActivityScheduleClockStatus(array $schedules, string $storedStatus, ?int $now = null): string
{
    $storedStatus = strtolower($storedStatus);
    if ($storedStatus === 'cancelled') {
        return 'cancelled';
    }
    if (in_array($storedStatus, ['conducted', 'completed'], true)) {
        return 'conducted';
    }

    $now ??= time();
    $hasScheduledDates = false;
    $hasUnknownDates = false;
    $hasUpcomingDate = false;
    foreach ($schedules as $schedule) {
        if ((string) ($schedule['status'] ?? 'scheduled') === 'cancelled') {
            continue;
        }
        $hasScheduledDates = true;
        $sessionStatus = rmsActivityScheduleSessionClockStatus($schedule, $now);
        if ($sessionStatus === 'ongoing') {
            return 'ongoing';
        }
        if ($sessionStatus === 'upcoming') {
            $hasUpcomingDate = true;
        } elseif ($sessionStatus === 'scheduled') {
            $hasUnknownDates = true;
        }
    }
    if ($hasUpcomingDate) {
        return 'upcoming';
    }
    if ($hasScheduledDates && !$hasUnknownDates) {
        return 'past';
    }
    return match ($storedStatus) {
        'ongoing' => 'ongoing',
        'conducted', 'completed' => 'conducted',
        'cancelled' => 'cancelled',
        default => 'upcoming',
    };
}

function rmsActivityScheduleCanStartActivity(mysqli $conn, int $activityId, bool $scheduleDatesAvailable): bool
{
    if ($activityId <= 0 || !$scheduleDatesAvailable) {
        return false;
    }

    try {
        $stmt = rmsActivitySchedulePrepare(
            $conn,
            "SELECT EXISTS (
                SELECT 1
                FROM research_activities ra
                INNER JOIN activity_schedule_dates d ON d.activity_id = ra.activity_id
                WHERE ra.activity_id = ?
                  AND ra.status IN ('upcoming', 'scheduled')
                  AND d.status = 'scheduled'
                  AND d.schedule_date = CURRENT_DATE()
                  AND d.start_time IS NOT NULL
                  AND d.start_time <= CURRENT_TIME()
                  AND (d.end_time IS NULL OR d.end_time >= CURRENT_TIME())
            ) AS can_start"
        );
        $stmt->bind_param('i', $activityId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to check the activity start window.');
        }
        $canStart = (int) ($stmt->get_result()->fetch_assoc()['can_start'] ?? 0) === 1;
        $stmt->close();
        return $canStart;
    } catch (Throwable $exception) {
        error_log('Activity start-window check failed: ' . $exception->getMessage());
        return false;
    }
}

function rmsActivityScheduleStartActivity(mysqli $conn, int $activityId, bool $scheduleDatesAvailable): bool
{
    if ($activityId <= 0 || !$scheduleDatesAvailable) {
        return false;
    }

    $stmt = rmsActivitySchedulePrepare(
        $conn,
        "UPDATE research_activities
         SET status = 'ongoing'
         WHERE activity_id = ?
           AND status IN ('upcoming', 'scheduled')
           AND EXISTS (
               SELECT 1
               FROM activity_schedule_dates d
               WHERE d.activity_id = ?
                 AND d.status = 'scheduled'
                 AND d.schedule_date = CURRENT_DATE()
                 AND d.start_time IS NOT NULL
                 AND d.start_time <= CURRENT_TIME()
                 AND (d.end_time IS NULL OR d.end_time >= CURRENT_TIME())
           )"
    );
    $stmt->bind_param('ii', $activityId, $activityId);
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $updated;
}

function rmsActivityScheduleCanMarkConducted(mysqli $conn, int $activityId, bool $scheduleDatesAvailable): bool
{
    if ($activityId <= 0 || !$scheduleDatesAvailable) {
        return false;
    }

    try {
        $stmt = rmsActivitySchedulePrepare(
            $conn,
            "SELECT EXISTS (
                SELECT 1
                FROM research_activities ra
                WHERE ra.activity_id = ?
                  AND ra.status IN ('upcoming', 'scheduled')
                  AND EXISTS (
                      SELECT 1 FROM activity_schedule_dates d
                      WHERE d.activity_id = ra.activity_id AND d.status = 'scheduled'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM activity_schedule_dates d
                      WHERE d.activity_id = ra.activity_id
                        AND d.status = 'scheduled'
                        AND (d.schedule_date > CURRENT_DATE()
                             OR (d.schedule_date = CURRENT_DATE()
                                 AND (d.end_time IS NULL OR d.end_time >= CURRENT_TIME())))
                  )
            ) AS can_mark_conducted"
        );
        $stmt->bind_param('i', $activityId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to check whether the activity schedule has ended.');
        }
        $canMarkConducted = (int) ($stmt->get_result()->fetch_assoc()['can_mark_conducted'] ?? 0) === 1;
        $stmt->close();
        return $canMarkConducted;
    } catch (Throwable $exception) {
        error_log('Activity completion-window check failed: ' . $exception->getMessage());
        return false;
    }
}

function rmsActivityScheduleMarkConducted(mysqli $conn, int $activityId, bool $scheduleDatesAvailable): bool
{
    if ($activityId <= 0 || !$scheduleDatesAvailable) {
        return false;
    }

    $stmt = rmsActivitySchedulePrepare(
        $conn,
        "UPDATE research_activities
         SET status = 'conducted'
         WHERE activity_id = ?
           AND status IN ('upcoming', 'scheduled')
           AND EXISTS (
               SELECT 1 FROM activity_schedule_dates d
               WHERE d.activity_id = ? AND d.status = 'scheduled'
           )
           AND NOT EXISTS (
               SELECT 1 FROM activity_schedule_dates d
               WHERE d.activity_id = ?
                 AND d.status = 'scheduled'
                 AND (d.schedule_date > CURRENT_DATE()
                      OR (d.schedule_date = CURRENT_DATE()
                          AND (d.end_time IS NULL OR d.end_time >= CURRENT_TIME())))
           )"
    );
    $stmt->bind_param('iii', $activityId, $activityId, $activityId);
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $updated;
}

function rmsActivityScheduleBegin(mysqli $conn): void
{
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to begin activity schedule transaction.');
    }
}

function rmsActivityScheduleCommit(mysqli $conn): void
{
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit activity schedule changes.');
    }
}

function rmsActivityScheduleEligibleUsers(mysqli $conn): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        "SELECT user_id, first_name, last_name, role, program, department
         FROM users
         WHERE status = 'active' AND role IN ('faculty', 'student', 'research_staff')
           AND deleted_at IS NULL
         ORDER BY role ASC, last_name ASC, first_name ASC"
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load eligible activity users.');
    }
    $result = $stmt->get_result();
    $users = [];
    while ($user = $result->fetch_assoc()) {
        $user['user_id'] = (int) $user['user_id'];
        $users[$user['user_id']] = $user;
    }
    $stmt->close();
    return $users;
}

function rmsActivityScheduleLoad(
    mysqli $conn,
    int $activityId,
    bool $invitationResponsesAvailable = false,
    bool $checkinDetailsAvailable = false,
    bool $loadAllParticipants = true,
    ?int $participantUserId = null
): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT schedule_id, schedule_date, start_time, end_time, venue_override,
                speaker_override, audience_scope, audience_roles, audience_user_ids, status,
                TIMESTAMP(schedule_date, COALESCE(start_time, \'00:00:00\')) > NOW() AS response_window_open,
                UNIX_TIMESTAMP(TIMESTAMP(schedule_date, COALESCE(start_time, \'00:00:00\'))) AS clock_start_timestamp,
                UNIX_TIMESTAMP(TIMESTAMP(schedule_date, COALESCE(end_time, \'23:59:59\'))) AS clock_end_timestamp
         FROM activity_schedule_dates WHERE activity_id = ? ORDER BY schedule_date ASC'
    );
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load activity schedule dates.');
    }
    $result = $stmt->get_result();
    $dates = [];
    while ($row = $result->fetch_assoc()) {
        $row['schedule_id'] = (int) $row['schedule_id'];
        $row['response_window_open'] = (int) ($row['response_window_open'] ?? 0);
        $decodedRoles = is_string($row['audience_roles'] ?? null)
            ? json_decode($row['audience_roles'], true)
            : null;
        $row['audience_roles'] = is_array($decodedRoles)
            ? array_values(array_filter(
                $decodedRoles,
                static fn ($role): bool => is_string($role)
                    && in_array($role, ['student', 'faculty', 'research_staff'], true)
            ))
            : [];
        $decodedUserIds = is_string($row['audience_user_ids'] ?? null)
            ? json_decode($row['audience_user_ids'], true)
            : null;
        $row['manual_recipient_ids'] = is_array($decodedUserIds)
            ? array_values(array_unique(array_filter(
                $decodedUserIds,
                static fn ($userId): bool => (is_int($userId) || (is_string($userId) && ctype_digit($userId)))
                    && (int) $userId > 0
            )))
            : [];
        $row['recipients'] = [];
        $row['participants'] = [];
        $dates[(string) $row['schedule_date']] = $row;
    }
    $stmt->close();

    if ($dates) {
        $invitationResponseColumns = $invitationResponsesAvailable
            ? ', invitation_response, invitation_responded_at'
            : '';
        $checkinDetailColumns = $checkinDetailsAvailable
            ? ', attendee_affiliation, attendee_program_department'
            : ', NULL AS attendee_affiliation, NULL AS attendee_program_department';
        $participantSql = 'SELECT schedule_id, participant_id, user_id, participant_name, role, attended, is_invited'
            . $invitationResponseColumns . $checkinDetailColumns . '
             FROM activity_schedule_participants
             WHERE schedule_id IN (SELECT schedule_id FROM activity_schedule_dates WHERE activity_id = ?)';
        if (!$loadAllParticipants) {
            $participantSql .= ' AND user_id = ? AND is_invited = 1';
        }
        $participantSql .= ' ORDER BY participant_name ASC, participant_id ASC';
        $participantStmt = rmsActivitySchedulePrepare($conn, $participantSql);
        if ($loadAllParticipants) {
            $participantStmt->bind_param('i', $activityId);
        } elseif ($participantUserId !== null && $participantUserId > 0) {
            $participantStmt->bind_param('ii', $activityId, $participantUserId);
        } else {
            $participantStmt->close();
            throw new RuntimeException('A user is required when loading only personal activity invitations.');
        }
        if (!$participantStmt->execute()) {
            $participantStmt->close();
            throw new RuntimeException('Unable to load date participants.');
        }
        $participantResult = $participantStmt->get_result();
        $scheduleIds = [];
        foreach ($dates as $date => $row) {
            $scheduleIds[(int) $row['schedule_id']] = $date;
        }
        while ($participant = $participantResult->fetch_assoc()) {
            $scheduleId = (int) $participant['schedule_id'];
            if (!isset($scheduleIds[$scheduleId])) {
                continue;
            }
            $date = $scheduleIds[$scheduleId];
            $participant['participant_id'] = (int) $participant['participant_id'];
            $participant['user_id'] = $participant['user_id'] === null ? null : (int) $participant['user_id'];
            $participant['attended'] = (int) $participant['attended'];
            $participant['is_invited'] = (int) $participant['is_invited'];
            $participant['invitation_response'] = (string) ($participant['invitation_response'] ?? 'pending');
            $participant['invitation_responded_at'] = $participant['invitation_responded_at'] ?? null;
            $dates[$date]['participants'][] = $participant;
            if ($participant['user_id'] && $participant['is_invited'] === 1) {
                $dates[$date]['recipients'][] = $participant['user_id'];
            }
        }
        $participantStmt->close();
        foreach ($dates as &$dateRow) {
            if ($loadAllParticipants
                && $dateRow['audience_user_ids'] === null
                && (string) $dateRow['audience_scope'] === 'specific') {
                $dateRow['manual_recipient_ids'] = array_values(array_unique($dateRow['recipients']));
            }
        }
        unset($dateRow);
    }
    return $dates;
}

function rmsActivityScheduleBindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    $typeString = $types;
    $parameters = [&$typeString];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!$stmt->bind_param(...$parameters)) {
        throw new RuntimeException('Unable to bind activity roster filters.');
    }
}

function rmsActivityScheduleLoadRosterPage(
    mysqli $conn,
    int $activityId,
    int $scheduleId,
    int $page = 1,
    string $search = '',
    string $role = '',
    string $affiliation = '',
    string $rsvp = '',
    string $attendance = '',
    bool $invitationResponsesAvailable = false,
    bool $checkinDetailsAvailable = false
): array {
    $pageSize = 50;
    $page = max(1, $page);
    $search = trim(substr($search, 0, 400));
    $role = in_array($role, ['attendee', 'speaker', 'organizer'], true) ? $role : '';
    $affiliation = in_array($affiliation, ['student', 'faculty', 'staff', 'guest'], true) ? $affiliation : '';
    $rsvp = in_array($rsvp, ['pending', 'accepted', 'declined'], true) ? $rsvp : '';
    $attendance = in_array($attendance, ['attended', 'not_attended'], true) ? $attendance : '';

    $globalStmt = rmsActivitySchedulePrepare($conn, 'SELECT COUNT(*) AS total_count, COALESCE(SUM(attended = 1), 0) AS attended_count, COALESCE(SUM(is_invited = 1), 0) AS invited_count, COALESCE(SUM(is_invited = 0), 0) AS other_count FROM activity_schedule_participants WHERE schedule_id = ?');
    $globalStmt->bind_param('i', $scheduleId);
    if (!$globalStmt->execute()) {
        $globalStmt->close();
        throw new RuntimeException('Unable to count date participants.');
    }
    $globalCounts = $globalStmt->get_result()->fetch_assoc() ?: [];
    $globalStmt->close();

    $invitationResponseSelect = $invitationResponsesAvailable ? ', COALESCE(sp.invitation_response, \'pending\') AS invitation_response' : ', NULL AS invitation_response';
    $checkinDetailsSelect = $checkinDetailsAvailable ? ', sp.attendee_affiliation, sp.attendee_program_department' : ', NULL AS attendee_affiliation, NULL AS attendee_program_department';
    $where = ['d.activity_id = ?', 'sp.schedule_id = ?'];
    $types = 'ii';
    $values = [$activityId, $scheduleId];
    if ($search !== '') {
        $where[] = '(sp.participant_name LIKE ?' . ($checkinDetailsAvailable ? ' OR sp.attendee_affiliation LIKE ? OR sp.attendee_program_department LIKE ?' : '') . ')';
        $pattern = '%' . $search . '%';
        $types .= $checkinDetailsAvailable ? 'sss' : 's';
        $values[] = $pattern;
        if ($checkinDetailsAvailable) {
            $values[] = $pattern;
            $values[] = $pattern;
        }
    }
    if ($role !== '') {
        $where[] = 'sp.role = ?';
        $types .= 's';
        $values[] = $role;
    }
    if ($affiliation !== '' && $checkinDetailsAvailable) {
        $where[] = 'sp.attendee_affiliation = ?';
        $types .= 's';
        $values[] = $affiliation;
    }
    if ($rsvp !== '' && $invitationResponsesAvailable) {
        $where[] = 'sp.is_invited = 1 AND COALESCE(sp.invitation_response, \'pending\') = ?';
        $types .= 's';
        $values[] = $rsvp;
    }
    if ($attendance !== '') {
        $where[] = 'sp.attended = ?';
        $types .= 'i';
        $values[] = $attendance === 'attended' ? 1 : 0;
    }
    $whereSql = implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*) AS filtered_count, COALESCE(SUM(sp.is_invited = 1), 0) AS filtered_invited_count, COALESCE(SUM(sp.is_invited = 0), 0) AS filtered_other_count FROM activity_schedule_participants sp INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id WHERE ' . $whereSql;
    $countStmt = rmsActivitySchedulePrepare($conn, $countSql);
    $countValues = $values;
    rmsActivityScheduleBindDynamic($countStmt, $types, $countValues);
    if (!$countStmt->execute()) {
        $countStmt->close();
        throw new RuntimeException('Unable to filter date participants.');
    }
    $filteredCounts = $countStmt->get_result()->fetch_assoc() ?: [];
    $countStmt->close();

    $filteredCount = (int) ($filteredCounts['filtered_count'] ?? 0);
    $pageCount = max(1, (int) ceil($filteredCount / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;
    $rowSql = 'SELECT sp.schedule_id, sp.participant_id, sp.user_id, sp.participant_name, sp.role, sp.attended, sp.is_invited' . $invitationResponseSelect . $checkinDetailsSelect . ' FROM activity_schedule_participants sp INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id WHERE ' . $whereSql . ' ORDER BY sp.is_invited DESC, sp.participant_name ASC, sp.participant_id ASC LIMIT ? OFFSET ?';
    $rowStmt = rmsActivitySchedulePrepare($conn, $rowSql);
    $rowValues = $values;
    $rowValues[] = $pageSize;
    $rowValues[] = $offset;
    rmsActivityScheduleBindDynamic($rowStmt, $types . 'ii', $rowValues);
    if (!$rowStmt->execute()) {
        $rowStmt->close();
        throw new RuntimeException('Unable to load this roster page.');
    }
    $rows = [];
    $rowResult = $rowStmt->get_result();
    while ($participant = $rowResult->fetch_assoc()) {
        $participant['participant_id'] = (int) $participant['participant_id'];
        $participant['user_id'] = $participant['user_id'] === null ? null : (int) $participant['user_id'];
        $participant['attended'] = (int) $participant['attended'];
        $participant['is_invited'] = (int) $participant['is_invited'];
        $rows[] = $participant;
    }
    $rowStmt->close();
    return [
        'participants' => $rows,
        'total_count' => (int) ($globalCounts['total_count'] ?? 0),
        'attended_count' => (int) ($globalCounts['attended_count'] ?? 0),
        'invited_count' => (int) ($globalCounts['invited_count'] ?? 0),
        'other_count' => (int) ($globalCounts['other_count'] ?? 0),
        'filtered_count' => $filteredCount,
        'filtered_invited_count' => (int) ($filteredCounts['filtered_invited_count'] ?? 0),
        'filtered_other_count' => (int) ($filteredCounts['filtered_other_count'] ?? 0),
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => $pageCount,
    ];
}

function rmsActivityScheduleSaveInvitationResponse(
    mysqli $conn,
    int $activityId,
    int $participantId,
    int $userId,
    string $response,
    bool $responseSchemaAvailable
): void {
    if (!$responseSchemaAvailable || $activityId <= 0 || $participantId <= 0 || $userId <= 0
        || !in_array($response, ['accepted', 'declined'], true)) {
        throw new RuntimeException('This invitation response is not valid.');
    }

    $conn->begin_transaction();
    try {
        $stmt = rmsActivitySchedulePrepare(
            $conn,
            "SELECT sp.schedule_id, sp.attended
             FROM activity_schedule_participants sp
             INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id
             INNER JOIN research_activities a ON a.activity_id = d.activity_id
             WHERE sp.participant_id = ? AND sp.user_id = ? AND sp.is_invited = 1
               AND d.activity_id = ? AND d.status = 'scheduled'
               AND a.status NOT IN ('conducted', 'completed', 'cancelled')
               AND TIMESTAMP(d.schedule_date, COALESCE(d.start_time, '00:00:00')) > NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('iii', $participantId, $userId, $activityId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to verify this activity invitation.');
        }
        $invitation = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$invitation || (int) $invitation['attended'] === 1) {
            throw new RuntimeException('This invitation is closed or is not assigned to your account.');
        }

        $stmt = rmsActivitySchedulePrepare(
            $conn,
            'UPDATE activity_schedule_participants
             SET invitation_response = ?, invitation_responded_at = CURRENT_TIMESTAMP
             WHERE participant_id = ? AND user_id = ? AND is_invited = 1'
        );
        $stmt->bind_param('sii', $response, $participantId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to save your invitation response.');
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function rmsActivityScheduleValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function rmsActivitySchedulePostString(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function rmsActivityScheduleValidatePost(array $post, array $eligibleUsers): array
{
    $errors = [];
    $multiDayValue = $post['multi_day'] ?? null;
    if ($multiDayValue !== null
        && (!is_scalar($multiDayValue) || (string) $multiDayValue !== '1')) {
        $errors[] = 'Choose a valid schedule type.';
    }
    $multiDay = is_scalar($multiDayValue) && (string) $multiDayValue === '1';
    $startDate = rmsActivitySchedulePostString($post['window_start'] ?? '');
    $endDate = rmsActivitySchedulePostString($post['window_end'] ?? $startDate);
    if (!rmsActivityScheduleValidDate($startDate) || !rmsActivityScheduleValidDate($endDate)) {
        $errors[] = 'Enter a valid activity date window.';
        return ['errors' => array_values(array_unique($errors)), 'dates' => [], 'window_start' => $startDate, 'window_end' => $endDate, 'multi_day' => $multiDay];
    }
    if (!$multiDay) {
        $endDate = $startDate;
    }
    if ($endDate < $startDate) {
        $errors[] = 'The schedule end date must be on or after the start date.';
    } else {
        $windowDays = (int) (new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days + 1;
        if ($windowDays > 366) {
            $errors[] = 'Choose a schedule window of no more than 366 days.';
        }
    }

    $postedDates = is_array($post['schedule_dates'] ?? null) ? $post['schedule_dates'] : [];
    $singleSchedule = is_array($post['single_schedule'] ?? null) ? $post['single_schedule'] : [];
    if ($multiDay) {
        $selectedDates = [];
        foreach ($postedDates as $date => $row) {
            if (is_array($row) && is_scalar($row['selected'] ?? null) && (string) $row['selected'] === '1') {
                $selectedDates[] = (string) $date;
            }
        }
    } else {
        $selectedDates = [$startDate];
    }
    $selectedDates = array_values(array_unique($selectedDates));
    sort($selectedDates, SORT_STRING);
    if (!$selectedDates && !$errors) {
        $errors[] = 'Select at least one date in the schedule window.';
    }

    $scopeRoles = [
        'all' => ['student', 'faculty', 'research_staff'],
        'student' => ['student'],
        'faculty' => ['faculty'],
        'staff' => ['research_staff'],
        'specific' => [],
        'custom' => [],
    ];
    $allowedCustomRoles = ['student', 'faculty', 'research_staff'];
    $dates = [];
    foreach ($selectedDates as $date) {
        if (!rmsActivityScheduleValidDate($date) || $date < $startDate || $date > $endDate) {
            $errors[] = 'A selected schedule date is outside the date window.';
            continue;
        }
        if ($multiDay) {
            $dateRow = is_array($postedDates[$date] ?? null) ? $postedDates[$date] : [];
            $postedRow = $date === $startDate
                ? array_merge($singleSchedule, $dateRow)
                : $dateRow;
        } else {
            $postedRow = $singleSchedule ?: (is_array($postedDates[$date] ?? null) ? $postedDates[$date] : []);
        }
        foreach (['start_time', 'end_time', 'recipient_scope', 'venue_override', 'speaker_override'] as $field) {
            if (array_key_exists($field, $postedRow) && !is_scalar($postedRow[$field])) {
                $errors[] = 'Enter valid session details for ' . date('M j, Y', strtotime($date)) . '.';
            }
        }
        $startTime = rmsActivitySchedulePostString($postedRow['start_time'] ?? '');
        $endTime = rmsActivitySchedulePostString($postedRow['end_time'] ?? '');
        if ($startTime === '' || $endTime === '') {
            $errors[] = 'Enter both a start time and end time for ' . date('M j, Y', strtotime($date)) . '.';
        }
        foreach (['Start' => $startTime, 'End' => $endTime] as $label => $time) {
            if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
                $errors[] = "Enter a valid {$label} time for " . date('M j, Y', strtotime($date)) . '.';
            }
        }
        if ($startTime !== '' && $endTime !== '') {
            $normalizedStart = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
            $normalizedEnd = strlen($endTime) === 5 ? $endTime . ':00' : $endTime;
            if ($normalizedEnd <= $normalizedStart) {
                $errors[] = 'The end time must be later than the start time for ' . date('M j, Y', strtotime($date)) . '.';
            }
        }

        $scope = rmsActivitySchedulePostString($postedRow['recipient_scope'] ?? 'all');
        if (!isset($scopeRoles[$scope])) {
            $errors[] = 'Choose a valid audience for ' . date('M j, Y', strtotime($date)) . '.';
            $scope = 'all';
        }
        $rawIds = $postedRow['recipient_user_ids'] ?? [];
        if (!is_array($rawIds)) {
            $errors[] = 'Choose valid recipients for ' . date('M j, Y', strtotime($date)) . '.';
            $rawIds = [];
        }
        $rawRoles = $postedRow['recipient_roles'] ?? [];
        if (!is_array($rawRoles)) {
            $errors[] = 'Choose valid audience roles for ' . date('M j, Y', strtotime($date)) . '.';
            $rawRoles = [];
        }
        $audienceRoles = [];
        foreach ($rawRoles as $rawRole) {
            if (!is_string($rawRole) || !in_array($rawRole, $allowedCustomRoles, true)) {
                $errors[] = 'Choose valid audience roles for ' . date('M j, Y', strtotime($date)) . '.';
                continue;
            }
            $audienceRoles[$rawRole] = $rawRole;
        }
        $audienceRoles = array_values($audienceRoles);
        if ($scope !== 'custom' && $audienceRoles) {
            $errors[] = 'Audience roles can only be selected for a custom audience on ' . date('M j, Y', strtotime($date)) . '.';
        }
        $selectedUserIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId)) {
                $errors[] = 'Choose valid recipients for ' . date('M j, Y', strtotime($date)) . '.';
                continue;
            }
            $userId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$userId || !isset($eligibleUsers[$userId])) {
                $errors[] = 'A selected recipient is no longer eligible. Refresh the page and choose recipients again.';
                continue;
            }
            $selectedUserIds[(int) $userId] = (int) $userId;
        }
        $recipients = [];
        if ($scope === 'specific') {
            $recipients = array_values($selectedUserIds);
            if (!$recipients) {
                $errors[] = 'Select at least one recipient for ' . date('M j, Y', strtotime($date)) . '.';
            }
        } elseif ($scope === 'custom') {
            foreach ($eligibleUsers as $eligibleUser) {
                if (in_array((string) $eligibleUser['role'], $audienceRoles, true)) {
                    $recipients[(int) $eligibleUser['user_id']] = (int) $eligibleUser['user_id'];
                }
            }
            foreach ($selectedUserIds as $recipientId) {
                $recipients[$recipientId] = $recipientId;
            }
            if (!$recipients) {
                $errors[] = 'Select at least one role or individual recipient for the custom audience on ' . date('M j, Y', strtotime($date)) . '.';
            }
            $recipients = array_values($recipients);
        } else {
            foreach ($eligibleUsers as $eligibleUser) {
                if (in_array((string) $eligibleUser['role'], $scopeRoles[$scope], true)) {
                    $recipients[] = (int) $eligibleUser['user_id'];
                }
            }
            if (!$recipients) {
                $errors[] = 'There are no active users in the selected audience for ' . date('M j, Y', strtotime($date)) . '.';
            }
        }

        $venueOverride = rmsActivitySchedulePostString($postedRow['venue_override'] ?? '');
        $speakerOverride = rmsActivitySchedulePostString($postedRow['speaker_override'] ?? '');
        if (strlen($venueOverride) > 255) {
            $errors[] = 'A venue override must not exceed 255 characters.';
        }
        if (strlen($speakerOverride) > 255) {
            $errors[] = 'A speaker override must not exceed 255 characters.';
        }
        $dates[$date] = [
            'schedule_date' => $date,
            'start_time' => $startTime !== '' ? $startTime : null,
            'end_time' => $endTime !== '' ? $endTime : null,
            'venue_override' => $venueOverride !== '' ? $venueOverride : null,
            'speaker_override' => $speakerOverride !== '' ? $speakerOverride : null,
            'audience_scope' => $scope,
            'audience_roles' => $scope === 'custom' ? $audienceRoles : [],
            'manual_recipient_ids' => in_array($scope, ['specific', 'custom'], true)
                ? array_values($selectedUserIds)
                : [],
            'recipients' => array_values(array_unique($recipients)),
        ];
    }

    return [
        'errors' => array_values(array_unique($errors)),
        'dates' => $dates,
        'window_start' => $startDate,
        'window_end' => $endDate,
        'multi_day' => $multiDay,
    ];
}

function rmsActivityScheduleCancelActivity(mysqli $conn, int $activityId): bool
{
    rmsActivityScheduleBegin($conn);
    try {
        $activityStmt = rmsActivitySchedulePrepare($conn, 'SELECT title, status FROM research_activities WHERE activity_id = ? FOR UPDATE');
        $activityStmt->bind_param('i', $activityId);
        if (!$activityStmt->execute()) {
            $activityStmt->close();
            throw new RuntimeException('Unable to load the activity to cancel.');
        }
        $activity = $activityStmt->get_result()->fetch_assoc();
        $activityStmt->close();
        if (!$activity || !in_array((string) $activity['status'], ['scheduled', 'upcoming', 'ongoing', 'completed', 'conducted'], true)) {
            throw new RuntimeException('The activity is not eligible for cancellation.');
        }

        $recipientStmt = rmsActivitySchedulePrepare(
            $conn,
            "SELECT d.schedule_date, p.user_id, p.is_notified
             FROM activity_schedule_dates d
             INNER JOIN activity_schedule_participants p
               ON p.schedule_id = d.schedule_id AND p.is_invited = 1
                  AND p.is_notified = 1 AND p.user_id IS NOT NULL
             WHERE d.activity_id = ? AND d.status = 'scheduled' ORDER BY d.schedule_date ASC"
        );
        $recipientStmt->bind_param('i', $activityId);
        if (!$recipientStmt->execute()) {
            $recipientStmt->close();
            throw new RuntimeException('Unable to load activity recipients.');
        }
        $recipientDates = [];
        $result = $recipientStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipientDates[(int) $row['user_id']][] = (string) $row['schedule_date'];
        }
        $recipientStmt->close();

        $statusStmt = rmsActivitySchedulePrepare(
            $conn,
            "UPDATE research_activities SET status = 'cancelled'
             WHERE activity_id = ? AND status IN ('scheduled','upcoming','ongoing','completed','conducted')"
        );
        $statusStmt->bind_param('i', $activityId);
        if (!$statusStmt->execute() || $statusStmt->affected_rows !== 1) {
            $statusStmt->close();
            throw new RuntimeException('The activity status could not be changed.');
        }
        $statusStmt->close();

        $dateStmt = rmsActivitySchedulePrepare(
            $conn,
            "UPDATE activity_schedule_dates SET status = 'cancelled' WHERE activity_id = ? AND status = 'scheduled'"
        );
        $dateStmt->bind_param('i', $activityId);
        if (!$dateStmt->execute()) {
            $dateStmt->close();
            throw new RuntimeException('The scheduled dates could not be cancelled.');
        }
        $dateStmt->close();

        $participantStmt = rmsActivitySchedulePrepare(
            $conn,
            'UPDATE activity_schedule_participants sp
             INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id
             SET sp.is_invited = 0, sp.is_notified = 0 WHERE d.activity_id = ?'
        );
        $participantStmt->bind_param('i', $activityId);
        if (!$participantStmt->execute()) {
            $participantStmt->close();
            throw new RuntimeException('Activity recipient invitations could not be cancelled.');
        }
        $participantStmt->close();

        $link = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
        foreach ($recipientDates as $recipientId => $dates) {
            $uniqueDates = array_values(array_unique($dates));
            $dateList = implode(', ', array_map(static fn (string $date): string => date('M j, Y', strtotime($date)), $uniqueDates));
            if (!createNotification(
                (int) $recipientId,
                'Research activity cancelled',
                'The activity "' . (string) $activity['title'] . '" was cancelled. Cancelled date'
                    . (count($uniqueDates) === 1 ? '' : 's') . ': ' . $dateList . '.',
                'warning',
                $link
            )) {
                throw new RuntimeException('Activity cancellation notices could not be sent.');
            }
        }
        rmsActivityScheduleCommit($conn);
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function rmsActivityScheduleSave(
    mysqli $conn,
    array $activity,
    ?int $activityId,
    array $scheduleRows,
    array $eligibleUsers,
    int $userId,
    string $initialStatus,
    bool $multiDay,
    string $windowStart,
    string $windowEnd,
    ?array $budgetHeader = null,
    array $budgetExpenses = []
): int {
    if (!$scheduleRows) {
        throw new RuntimeException('A saved activity must have at least one selected date.');
    }

    ksort($scheduleRows, SORT_STRING);
    $firstDate = reset($scheduleRows);
    $legacyDate = (string) $firstDate['schedule_date'];
    $legacyTime = $firstDate['start_time'];
    $checkinTokenColumnAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_dates')
        && rmsActivityScheduleColumnExists($conn, 'activity_schedule_dates', 'checkin_token_hash');
    $checkinTokenResetSql = $checkinTokenColumnAvailable
        ? "checkin_token_hash = IF(status = 'cancelled', NULL, checkin_token_hash), "
        : '';
    $oldDates = [];
    $oldDefaults = ['venue' => null, 'speaker' => null];
    $recordOnly = $initialStatus === 'conducted';
    $sourceProjectId = isset($activity['source_project_id']) && $activity['source_project_id'] !== ''
        ? (int) $activity['source_project_id']
        : null;
    $sourcePublicationId = isset($activity['source_publication_id']) && $activity['source_publication_id'] !== ''
        ? (int) $activity['source_publication_id']
        : null;
    $activityType = (string) ($activity['activity_type'] ?? '');
    if (($sourceProjectId !== null && $sourceProjectId <= 0)
        || ($sourcePublicationId !== null && $sourcePublicationId <= 0)
        || ($sourceProjectId !== null && $sourcePublicationId !== null)
        || ($sourceProjectId !== null && $activityType !== 'colloquium')
        || ($sourcePublicationId !== null && $activityType !== 'conference_congress')) {
        throw new RuntimeException('Choose a source record that matches the activity type.');
    }
    if ($sourceProjectId !== null && !hasRole('admin')
        && !hasModulePermission($userId, 'research_implementation_outputs', 'view', 'completed_research')) {
        throw new RuntimeException('You do not have access to the student research archive.');
    }
    if ($sourcePublicationId !== null && !hasRole('admin')
        && !hasModulePermission($userId, 'research_implementation_outputs', 'view', 'research_outputs')
        && !hasModulePermission($userId, 'publication_dissemination', 'view', 'publication_records')) {
        throw new RuntimeException('You do not have access to the faculty publication archive.');
    }

    rmsActivityScheduleBegin($conn);
    try {
        if ($sourceProjectId !== null) {
            $sourceStmt = rmsActivitySchedulePrepare(
                $conn,
                "SELECT rp.project_id
                   FROM research_projects rp
                   JOIN users owner ON owner.user_id = rp.created_by AND owner.role = 'student'
                  WHERE rp.project_id = ? AND rp.deleted_at IS NULL
                    AND rp.status IN ('completed', 'archived')
                  LIMIT 1 FOR UPDATE"
            );
            $sourceStmt->bind_param('i', $sourceProjectId);
            if (!$sourceStmt->execute() || !$sourceStmt->get_result()->fetch_assoc()) {
                $sourceStmt->close();
                throw new RuntimeException('Choose an active archived student research record.');
            }
            $sourceStmt->close();
        }
        if ($sourcePublicationId !== null) {
            $sourceStmt = rmsActivitySchedulePrepare(
                $conn,
                "SELECT publication_id
                   FROM publications
                  WHERE publication_id = ? AND researcher_category = 'faculty'
                    AND deactivated_at IS NULL
                    AND (archival_status = 'archived' OR status = 'published')
                  LIMIT 1 FOR UPDATE"
            );
            $sourceStmt->bind_param('i', $sourcePublicationId);
            if (!$sourceStmt->execute() || !$sourceStmt->get_result()->fetch_assoc()) {
                $sourceStmt->close();
                throw new RuntimeException('Choose an active archived or published faculty publication.');
            }
            $sourceStmt->close();
        }

        $recipientIdsToVerify = [];
        foreach ($scheduleRows as $row) {
            foreach ($row['recipients'] as $recipientId) {
                $recipientIdsToVerify[(int) $recipientId] = true;
            }
        }
        $eligibleStmt = rmsActivitySchedulePrepare(
            $conn,
            "SELECT user_id FROM users
             WHERE user_id = ? AND status = 'active'
               AND role IN ('faculty','student','research_staff') AND deleted_at IS NULL LIMIT 1"
        );
        foreach (array_keys($recipientIdsToVerify) as $recipientId) {
            $eligibleStmt->bind_param('i', $recipientId);
            if (!$eligibleStmt->execute() || !$eligibleStmt->get_result()->fetch_assoc()) {
                $eligibleStmt->close();
                throw new RuntimeException('A selected activity recipient is no longer eligible.');
            }
        }
        $eligibleStmt->close();

        if ($activityId !== null) {
            $stmt = rmsActivitySchedulePrepare($conn, 'SELECT activity_id, status, venue, speaker FROM research_activities WHERE activity_id = ? FOR UPDATE');
            $stmt->bind_param('i', $activityId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to lock the activity.');
            }
            $existingActivity = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$existingActivity) {
                throw new RuntimeException('The activity no longer exists.');
            }
            $recordOnly = $recordOnly || in_array((string) $existingActivity['status'], ['conducted', 'completed'], true);
            if ((string) $existingActivity['status'] === 'cancelled') {
                throw new RuntimeException('A cancelled activity schedule cannot be changed.');
            }
            $oldDefaults = ['venue' => $existingActivity['venue'], 'speaker' => $existingActivity['speaker']];

            $dateStmt = rmsActivitySchedulePrepare(
                $conn,
                'SELECT schedule_id, schedule_date, start_time, end_time,
                        venue_override, speaker_override, status
                 FROM activity_schedule_dates WHERE activity_id = ? FOR UPDATE'
            );
            $dateStmt->bind_param('i', $activityId);
            if (!$dateStmt->execute()) {
                $dateStmt->close();
                throw new RuntimeException('Unable to lock the existing schedule.');
            }
            $result = $dateStmt->get_result();
            while ($old = $result->fetch_assoc()) {
                $date = (string) $old['schedule_date'];
                $oldDates[$date] = [
                    'schedule_id' => (int) $old['schedule_id'],
                    'schedule_date' => $date,
                    'start_time' => $old['start_time'],
                    'end_time' => $old['end_time'],
                    'venue' => $old['venue_override'] ?: $oldDefaults['venue'],
                    'speaker' => $old['speaker_override'] ?: $oldDefaults['speaker'],
                    'status' => (string) $old['status'],
                    'recipients' => [],
                ];
            }
            $dateStmt->close();

            $recipientStmt = rmsActivitySchedulePrepare(
                $conn,
                "SELECT d.schedule_date, p.user_id, p.is_notified
                 FROM activity_schedule_dates d
                 INNER JOIN activity_schedule_participants p
                   ON p.schedule_id = d.schedule_id AND p.is_invited = 1 AND p.user_id IS NOT NULL
                 WHERE d.activity_id = ? FOR UPDATE"
            );
            $recipientStmt->bind_param('i', $activityId);
            if (!$recipientStmt->execute()) {
                $recipientStmt->close();
                throw new RuntimeException('Unable to lock the existing schedule recipients.');
            }
            $result = $recipientStmt->get_result();
            while ($recipient = $result->fetch_assoc()) {
                $date = (string) $recipient['schedule_date'];
                if (isset($oldDates[$date])) {
                    $oldDates[$date]['recipients'][(int) $recipient['user_id']] = [
                        'notified' => (int) $recipient['is_notified'] === 1,
                    ];
                }
            }
            $recipientStmt->close();

            $stmt = rmsActivitySchedulePrepare(
                $conn,
                'UPDATE research_activities SET activity_type = ?, title = ?, activity_date = ?,
                    activity_time = ?, is_multi_day = ?, schedule_start_date = ?, schedule_end_date = ?,
                    venue = ?, speaker = ?, organizer = ?, description = ?,
                    source_project_id = ?, source_publication_id = ?
                 WHERE activity_id = ?'
            );
            $stmt->bind_param(
                'ssssissssssiii',
                $activity['activity_type'],
                $activity['title'],
                $legacyDate,
                $legacyTime,
                $multiDay,
                $windowStart,
                $windowEnd,
                $activity['venue'],
                $activity['speaker'],
                $activity['organizer'],
                $activity['description'],
                $sourceProjectId,
                $sourcePublicationId,
                $activityId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to update activity details.');
            }
            $stmt->close();
        } else {
            $stmt = rmsActivitySchedulePrepare(
                $conn,
                'INSERT INTO research_activities
                    (activity_type, title, activity_date, activity_time, is_multi_day, schedule_start_date, schedule_end_date,
                     venue, speaker, organizer, description, source_project_id, source_publication_id, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssss' . 'i' . 'ssssss' . 'iis' . 'i',
                $activity['activity_type'],
                $activity['title'],
                $legacyDate,
                $legacyTime,
                $multiDay,
                $windowStart,
                $windowEnd,
                $activity['venue'],
                $activity['speaker'],
                $activity['organizer'],
                $activity['description'],
                $sourceProjectId,
                $sourcePublicationId,
                $initialStatus,
                $userId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to create the activity.');
            }
            $activityId = (int) $stmt->insert_id;
            $stmt->close();
        }

        $scheduleIdsByDate = [];
        foreach ($scheduleRows as $date => $row) {
            if (isset($oldDates[$date])) {
                $scheduleId = (int) $oldDates[$date]['schedule_id'];
                $audienceRolesJson = $row['audience_scope'] === 'custom'
                    ? json_encode($row['audience_roles'], JSON_THROW_ON_ERROR)
                    : null;
                $manualRecipientIdsJson = in_array($row['audience_scope'], ['specific', 'custom'], true)
                    ? json_encode($row['manual_recipient_ids'], JSON_THROW_ON_ERROR)
                    : null;
                $stmt = rmsActivitySchedulePrepare(
                    $conn,
                    "UPDATE activity_schedule_dates
                     SET {$checkinTokenResetSql}start_time = ?, end_time = ?, venue_override = ?, speaker_override = ?,
                         audience_scope = ?, audience_roles = ?, audience_user_ids = ?, status = 'scheduled'
                     WHERE schedule_id = ? AND activity_id = ?"
                );
                $stmt->bind_param(
                    'sssssssii',
                    $row['start_time'],
                    $row['end_time'],
                    $row['venue_override'],
                    $row['speaker_override'],
                    $row['audience_scope'],
                    $audienceRolesJson,
                    $manualRecipientIdsJson,
                    $scheduleId,
                    $activityId
                );
            } else {
                $audienceRolesJson = $row['audience_scope'] === 'custom'
                    ? json_encode($row['audience_roles'], JSON_THROW_ON_ERROR)
                    : null;
                $manualRecipientIdsJson = in_array($row['audience_scope'], ['specific', 'custom'], true)
                    ? json_encode($row['manual_recipient_ids'], JSON_THROW_ON_ERROR)
                    : null;
                $stmt = rmsActivitySchedulePrepare(
                    $conn,
                    "INSERT INTO activity_schedule_dates
                     (activity_id, schedule_date, start_time, end_time, venue_override, speaker_override, audience_scope, audience_roles, audience_user_ids, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')"
                );
                $stmt->bind_param(
                    'issssssss',
                    $activityId,
                    $row['schedule_date'],
                    $row['start_time'],
                    $row['end_time'],
                    $row['venue_override'],
                    $row['speaker_override'],
                    $row['audience_scope'],
                    $audienceRolesJson,
                    $manualRecipientIdsJson
                );
            }
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to save a scheduled date.');
            }
            if (!isset($oldDates[$date])) {
                $scheduleId = (int) $stmt->insert_id;
            }
            $stmt->close();
            $scheduleIdsByDate[(string) $date] = $scheduleId;
            $deactivateStmt = rmsActivitySchedulePrepare(
                $conn,
                'UPDATE activity_schedule_participants SET is_invited = 0 WHERE schedule_id = ?'
            );
            $deactivateStmt->bind_param('i', $scheduleId);
            if (!$deactivateStmt->execute()) {
                $deactivateStmt->close();
                throw new RuntimeException('Unable to reconcile date recipients.');
            }
            $deactivateStmt->close();

            foreach ($row['recipients'] as $recipientId) {
                if (!isset($eligibleUsers[$recipientId])) {
                    throw new RuntimeException('A selected activity recipient is no longer eligible.');
                }
                $recipient = $eligibleUsers[$recipientId];
                $participantName = trim((string) $recipient['first_name'] . ' ' . (string) $recipient['last_name']);
                $attended = $recordOnly ? 1 : 0;
                $isInvited = $recordOnly ? 0 : 1;
                $participantStmt = rmsActivitySchedulePrepare(
                    $conn,
                    "INSERT INTO activity_schedule_participants
                     (schedule_id, user_id, participant_name, role, attended, is_invited)
                     VALUES (?, ?, ?, 'attendee', ?, ?)
                     ON DUPLICATE KEY UPDATE participant_name = VALUES(participant_name),
                         attended = IF(VALUES(attended) = 1, 1, attended),
                         is_invited = VALUES(is_invited),
                         is_notified = IF(VALUES(is_invited) = 0, 0, is_notified)"
                );
                $participantStmt->bind_param('iisii', $scheduleId, $recipientId, $participantName, $attended, $isInvited);
                if (!$participantStmt->execute()) {
                    $participantStmt->close();
                    throw new RuntimeException('Unable to register a date participant.');
                }
                $participantStmt->close();

                $legacyStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended)
                     SELECT ?, ?, ?, \'attendee\', ?
                     WHERE NOT EXISTS (
                         SELECT 1 FROM activity_participants WHERE activity_id = ? AND user_id = ?
                     )'
                );
                $legacyStmt->bind_param('iisiii', $activityId, $recipientId, $participantName, $attended, $activityId, $recipientId);
                if (!$legacyStmt->execute()) {
                    $legacyStmt->close();
                    throw new RuntimeException('Unable to preserve the activity participant record.');
                }
                $legacyStmt->close();
                if ($recordOnly) {
                    $legacyAttendanceStmt = rmsActivitySchedulePrepare(
                        $conn,
                        "UPDATE activity_participants SET attended = 1
                         WHERE activity_id = ? AND user_id = ?"
                    );
                    $legacyAttendanceStmt->bind_param('ii', $activityId, $recipientId);
                    if (!$legacyAttendanceStmt->execute()) {
                        $legacyAttendanceStmt->close();
                        throw new RuntimeException('Unable to record conducted activity attendance.');
                    }
                    $legacyAttendanceStmt->close();
                }
            }
            $clearRemovedNoticeStmt = rmsActivitySchedulePrepare(
                $conn,
                'UPDATE activity_schedule_participants
                 SET is_notified = 0 WHERE schedule_id = ? AND is_invited = 0'
            );
            $clearRemovedNoticeStmt->bind_param('i', $scheduleId);
            if (!$clearRemovedNoticeStmt->execute()) {
                $clearRemovedNoticeStmt->close();
                throw new RuntimeException('Unable to reconcile date invitation history.');
            }
            $clearRemovedNoticeStmt->close();
        }

        foreach ($oldDates as $date => $oldDate) {
            if (isset($scheduleRows[$date])) {
                continue;
            }
            $scheduleId = (int) $oldDate['schedule_id'];
            $cancelStmt = rmsActivitySchedulePrepare(
                $conn,
                "UPDATE activity_schedule_dates SET status = 'cancelled' WHERE schedule_id = ? AND activity_id = ?"
            );
            $cancelStmt->bind_param('ii', $scheduleId, $activityId);
            if (!$cancelStmt->execute()) {
                $cancelStmt->close();
                throw new RuntimeException('Unable to cancel a removed schedule date.');
            }
            $cancelStmt->close();
            $deactivateStmt = rmsActivitySchedulePrepare(
                $conn,
                'UPDATE activity_schedule_participants SET is_invited = 0, is_notified = 0 WHERE schedule_id = ?'
            );
            $deactivateStmt->bind_param('i', $scheduleId);
            if (!$deactivateStmt->execute()) {
                $deactivateStmt->close();
                throw new RuntimeException('Unable to cancel date recipients.');
            }
            $deactivateStmt->close();
        }

        if ($budgetHeader !== null) {
            rmsActivityBudgetSave($conn, (int) $activityId, $userId, $budgetHeader, $budgetExpenses, false);
        }

        if (!$recordOnly) {
        $notifications = [];
        $notificationStates = [];
        $describeSession = static function (array $row, ?string $defaultVenue, ?string $defaultSpeaker): string {
            $time = $row['start_time'] ? date('g:i A', strtotime((string) $row['start_time'])) : 'time not set';
            if (!empty($row['end_time'])) {
                $time .= '–' . date('g:i A', strtotime((string) $row['end_time']));
            }
            $venue = $row['venue_override'] ?: $defaultVenue ?: 'venue to be announced';
            $speaker = $row['speaker_override'] ?: $defaultSpeaker ?: 'speaker not specified';
            return 'Time: ' . $time . '; venue: ' . $venue . '; speaker: ' . $speaker . '.';
        };
        foreach ($oldDates as $date => $oldDate) {
            if (!isset($scheduleRows[$date])) {
                foreach ($oldDate['recipients'] as $recipientId => $recipientState) {
                    if (!$recipientState['notified']) {
                        continue;
                    }
                    $notifications[$recipientId][$date] = 'Cancelled date: ' . date('M j, Y', strtotime($date)) . '.';
                    $notificationStates[$recipientId][$date] = false;
                }
                continue;
            }
            $newRecipients = array_fill_keys($scheduleRows[$date]['recipients'], true);
            foreach ($oldDate['recipients'] as $recipientId => $recipientState) {
                if (!isset($newRecipients[$recipientId])) {
                    if ($recipientState['notified']) {
                        $notifications[$recipientId][$date] = 'Your invitation for ' . date('M j, Y', strtotime($date)) . ' was cancelled.';
                        $notificationStates[$recipientId][$date] = false;
                    }
                }
            }
            $newVenue = $scheduleRows[$date]['venue_override'] ?: $activity['venue'];
            $newSpeaker = $scheduleRows[$date]['speaker_override'] ?: $activity['speaker'];
            $changed = (string) ($oldDate['start_time'] ?? '') !== (string) ($scheduleRows[$date]['start_time'] ?? '')
                || (string) ($oldDate['end_time'] ?? '') !== (string) ($scheduleRows[$date]['end_time'] ?? '')
                || (string) ($oldDate['venue'] ?? '') !== (string) ($newVenue ?? '')
                || (string) ($oldDate['speaker'] ?? '') !== (string) ($newSpeaker ?? '');
            foreach ($oldDate['recipients'] as $recipientId => $recipientState) {
                if (isset($newRecipients[$recipientId])) {
                    if ($changed && $recipientState['notified']) {
                        $notifications[$recipientId][$date] = 'Updated schedule for ' . date('M j, Y', strtotime($date)) . ': '
                            . $describeSession($scheduleRows[$date], $activity['venue'], $activity['speaker']);
                        $notificationStates[$recipientId][$date] = true;
                    } elseif (!$recipientState['notified']) {
                        $notifications[$recipientId][$date] = 'Invitation: ' . date('M j, Y', strtotime($date)) . '. '
                            . $describeSession($scheduleRows[$date], $activity['venue'], $activity['speaker']);
                        $notificationStates[$recipientId][$date] = true;
                    }
                }
            }
            foreach (array_keys($newRecipients) as $recipientId) {
                if (!isset($oldDate['recipients'][$recipientId])) {
                    $notifications[$recipientId][$date] = 'Invitation: ' . date('M j, Y', strtotime($date)) . '. '
                        . $describeSession($scheduleRows[$date], $activity['venue'], $activity['speaker']);
                    $notificationStates[$recipientId][$date] = true;
                }
            }
        }
        foreach ($scheduleRows as $date => $row) {
            if (isset($oldDates[$date])) {
                continue;
            }
            foreach ($row['recipients'] as $recipientId) {
                $notifications[$recipientId][$date] = 'Invitation: ' . date('M j, Y', strtotime($date)) . '. '
                    . $describeSession($row, $activity['venue'], $activity['speaker']);
                $notificationStates[$recipientId][$date] = true;
            }
        }

        $notificationLink = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
        foreach ($notifications as $recipientId => $events) {
            $descriptions = array_values($events);
            $hasInvitation = count(array_filter($descriptions, static fn (string $event): bool => str_starts_with($event, 'Invitation:'))) > 0;
            $hasCancellation = count(array_filter($descriptions, static fn (string $event): bool => str_contains(strtolower($event), 'cancelled'))) > 0;
            $hasUpdate = count(array_filter($descriptions, static fn (string $event): bool => str_starts_with($event, 'Updated schedule for '))) > 0;
            $title = $hasInvitation && !$hasCancellation && !$hasUpdate
                ? 'Research activity invitation'
                : ($hasCancellation && !$hasInvitation && !$hasUpdate
                    ? 'Research activity schedule cancellation'
                    : 'Research activity schedule update');
            $message = 'Schedule update for "' . $activity['title'] . '": ' . implode(' ', $descriptions);
            if ($hasInvitation) {
                $message .= ' Open your formal invitation to review the details and respond.';
            }
            $notificationType = $hasCancellation && !$hasInvitation && !$hasUpdate ? 'warning' : 'info';
            if (!createNotification((int) $recipientId, $title, $message, $notificationType, $notificationLink)) {
                throw new RuntimeException('Unable to notify an activity recipient.');
            }
            foreach ($notificationStates[$recipientId] as $date => $isNotified) {
                $scheduleId = $scheduleIdsByDate[$date] ?? ($oldDates[$date]['schedule_id'] ?? null);
                if (!$scheduleId) {
                    throw new RuntimeException('Unable to reconcile an activity notification with its schedule date.');
                }
                $isInvited = $isNotified ? 1 : 0;
                $noticeStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'UPDATE activity_schedule_participants
                     SET is_notified = ?
                     WHERE schedule_id = ? AND user_id = ? AND is_invited = ?'
                );
                $noticeStmt->bind_param('iiii', $isInvited, $scheduleId, $recipientId, $isInvited);
                if (!$noticeStmt->execute()) {
                    $noticeStmt->close();
                    throw new RuntimeException('Unable to reconcile activity notification status.');
                }
                $noticeStmt->close();
            }
        }
        }

        rmsActivityScheduleCommit($conn);
        return $activityId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
