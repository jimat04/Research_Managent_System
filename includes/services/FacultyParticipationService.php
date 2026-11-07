<?php

declare(strict_types=1);

class FacultyParticipationService
{
    private mysqli $conn;
    private array $schemaColumns = [];

    private const PAGE_SIZE = 20;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
        $this->loadSchemaMetadata();
    }

    public function getMySummary(int $facultyId): array
    {
        if ($facultyId < 1) {
            throw new InvalidArgumentException('A valid faculty account is required.');
        }

        $available = $this->hasParticipationSource();
        $summary = [];
        if ($available) {
            $summaryRows = $this->fetchAll(
                $this->participationCtes() .
                " SELECT
                    COUNT(DISTINCT CASE WHEN record_kind = 'activity' THEN activity_id END) AS activity_count,
                    COUNT(DISTINCT CASE WHEN record_kind = 'activity' AND has_attendee = 1 THEN activity_id END) AS attendee_events,
                    COUNT(DISTINCT CASE WHEN record_kind = 'activity' AND has_presenter = 1 THEN activity_id END) AS presenter_events,
                    COUNT(DISTINCT CASE WHEN record_kind = 'presentation' THEN engagement_id END) AS standalone_presentations
                  FROM participation_records
                 WHERE user_id = ?",
                'i',
                [$facultyId]
            );
            $summary = $summaryRows[0] ?? [];
        }

        $byType = [];
        if ($available) {
            $byType = $this->fetchAll(
                $this->participationCtes() .
                " SELECT activity_type,
                    COUNT(DISTINCT CASE WHEN record_kind = 'activity' THEN activity_id END) AS activity_count,
                    COUNT(DISTINCT CASE WHEN record_kind = 'presentation' THEN engagement_id END) AS standalone_presentations
                  FROM participation_records
                 WHERE user_id = ?
                 GROUP BY activity_type
                 ORDER BY activity_type",
                'i',
                [$facultyId]
            );
            foreach ($byType as &$row) {
                $row['activity_count'] = (int) $row['activity_count'];
                $row['standalone_presentations'] = (int) $row['standalone_presentations'];
            }
            unset($row);
        }

        $activityCount = (int) ($summary['activity_count'] ?? 0);
        $standalonePresentations = (int) ($summary['standalone_presentations'] ?? 0);

        return [
            'available' => $available,
            'activity_count' => $activityCount,
            'attendee_events' => (int) ($summary['attendee_events'] ?? 0),
            'presenter_events' => (int) ($summary['presenter_events'] ?? 0),
            'standalone_presentations' => $standalonePresentations,
            'total_participations' => $activityCount + $standalonePresentations,
            'by_type' => $byType,
        ];
    }

    /**
     * Get the same deduplicated participation summary used by faculty profiles,
     * constrained to an optional date range for analytics.
     */
    public function getAnalyticsSummary(int $facultyId, ?string $fromDate = null, ?string $toDate = null): array
    {
        if ($facultyId < 1) {
            throw new InvalidArgumentException('A valid faculty account is required.');
        }

        if (!$this->hasParticipationSource()) {
            return [
                'available' => false,
                'activity_count' => 0,
                'attendee_events' => 0,
                'presenter_events' => 0,
                'standalone_presentations' => 0,
                'total_participations' => 0,
                'by_type' => [],
                'by_kind' => [],
            ];
        }

        $conditions = ['pr.user_id = ?'];
        $types = 'i';
        $params = [$facultyId];
        if ($fromDate !== null && $fromDate !== '') {
            $conditions[] = 'pr.participation_date >= ?';
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate !== null && $toDate !== '') {
            $conditions[] = 'pr.participation_date <= ?';
            $types .= 's';
            $params[] = $toDate;
        }

        $cte = $this->participationCtes() . ', filtered_records AS (
            SELECT pr.*
              FROM participation_records pr
             WHERE ' . implode(' AND ', $conditions) . '
        )';
        $summaryRows = $this->fetchAll(
            $cte . " SELECT
                COUNT(DISTINCT CASE WHEN record_kind = 'activity' THEN activity_id END) AS activity_count,
                COUNT(DISTINCT CASE WHEN record_kind = 'activity' AND has_attendee = 1 THEN activity_id END) AS attendee_events,
                COUNT(DISTINCT CASE WHEN record_kind = 'activity' AND has_presenter = 1 THEN activity_id END) AS presenter_events,
                COUNT(DISTINCT CASE WHEN record_kind = 'presentation' THEN engagement_id END) AS standalone_presentations
              FROM filtered_records",
            $types,
            $params
        );
        $summary = $summaryRows[0] ?? [];

        $typeRows = $this->fetchAll(
            $cte . " SELECT activity_type,
                COUNT(DISTINCT CASE WHEN record_kind = 'activity' THEN activity_id END) AS activity_count,
                COUNT(DISTINCT CASE WHEN record_kind = 'presentation' THEN engagement_id END) AS standalone_presentations
              FROM filtered_records
             GROUP BY activity_type
             ORDER BY activity_type",
            $types,
            $params
        );
        $byType = array_map(static function (array $row): array {
            $label = ucwords(str_replace('_', ' ', (string) $row['activity_type']));
            $count = (int) $row['activity_count'] + (int) $row['standalone_presentations'];
            return ['label' => $label, 'value' => $count];
        }, $typeRows);

        $activityCount = (int) ($summary['activity_count'] ?? 0);
        $attendeeEvents = (int) ($summary['attendee_events'] ?? 0);
        $presenterEvents = (int) ($summary['presenter_events'] ?? 0);
        $standalonePresentations = (int) ($summary['standalone_presentations'] ?? 0);
        $byKind = array_values(array_filter([
            ['label' => 'Attended events', 'value' => $attendeeEvents],
            ['label' => 'Presented events', 'value' => $presenterEvents],
            ['label' => 'Standalone presentations', 'value' => $standalonePresentations],
        ], static fn(array $row): bool => $row['value'] > 0));

        return [
            'available' => true,
            'activity_count' => $activityCount,
            'attendee_events' => $attendeeEvents,
            'presenter_events' => $presenterEvents,
            'standalone_presentations' => $standalonePresentations,
            'total_participations' => $activityCount + $standalonePresentations,
            'by_type' => $byType,
            'by_kind' => $byKind,
        ];
    }

    public function getFacultyTypeSummary(): array
    {
        if (!$this->hasTable('users')) {
            return [];
        }

        return $this->fetchAll(
            $this->participationCtes() .
            " SELECT pr.activity_type, COUNT(DISTINCT pr.user_id) AS faculty_count
                FROM participation_records pr
                INNER JOIN users u ON u.user_id = pr.user_id
               WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL
               GROUP BY pr.activity_type
               ORDER BY pr.activity_type",
            '',
            []
        );
    }

    public function getAdminReport(array $rawFilters): array
    {
        $options = $this->getFilterOptions();
        $filters = $this->normalizeFilters($rawFilters, $options);
        $recordConditions = [];
        $recordTypes = '';
        $recordParams = [];

        if ($filters['activity_type'] !== 'all') {
            $recordConditions[] = 'pr.activity_type = ?';
            $recordTypes .= 's';
            $recordParams[] = $filters['activity_type'];
        }
        if ($filters['academic_year'] !== '') {
            $recordConditions[] = 'YEAR(pr.participation_date) = ?';
            $recordTypes .= 'i';
            $recordParams[] = (int) $filters['academic_year'];
        }

        $recordWhere = $recordConditions ? implode(' AND ', $recordConditions) : '1 = 1';
        $cte = $this->participationCtes() .
            ",
            filtered_records AS (
                SELECT pr.*
                  FROM participation_records pr
                 WHERE {$recordWhere}
            )";

        $userConditions = [
            "u.role = 'faculty'",
            "u.status = 'active'",
            'u.deleted_at IS NULL',
        ];
        $userTypes = '';
        $userParams = [];

        if ($filters['search'] !== '') {
            $userConditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.email LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $userTypes .= 'ssss';
            array_push($userParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        }
        if ($filters['department'] !== '') {
            $userConditions[] = 'u.department = ?';
            $userTypes .= 's';
            $userParams[] = $filters['department'];
        }
        if ($filters['program_id'] > 0) {
            $userConditions[] = 'EXISTS (
                SELECT 1
                  FROM faculty_program_assignments filter_fpa
                 WHERE filter_fpa.faculty_id = u.user_id
                   AND filter_fpa.program_id = ?
            )';
            $userTypes .= 'i';
            $userParams[] = $filters['program_id'];
        }

        $programExpression = $this->hasProgramAssignments()
            ? "COALESCE(
                (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.program_code), '') ORDER BY p.program_code SEPARATOR ', ')
                   FROM faculty_program_assignments fpa
                   INNER JOIN programs p ON p.program_id = fpa.program_id
                  WHERE fpa.faculty_id = u.user_id),
                NULLIF(TRIM(u.program), ''),
                'Program not recorded'
            )"
            : "COALESCE(NULLIF(TRIM(u.program), ''), 'Program not recorded')";

        $totalExpression = "(COUNT(DISTINCT CASE WHEN pr.record_kind = 'activity' THEN pr.activity_id END)
            + COUNT(DISTINCT CASE WHEN pr.record_kind = 'presentation' THEN pr.engagement_id END))";
        $having = '';
        if ($filters['participation_status'] === 'participated') {
            $having = " HAVING {$totalExpression} > 0";
        } elseif ($filters['participation_status'] === 'none') {
            $having = " HAVING {$totalExpression} = 0";
        }

        $select = "SELECT u.user_id, u.first_name, u.last_name, u.email, u.department,
                    {$programExpression} AS programs,
                    COUNT(DISTINCT CASE WHEN pr.record_kind = 'activity' THEN pr.activity_id END) AS activity_count,
                    COUNT(DISTINCT CASE WHEN pr.record_kind = 'activity' AND pr.has_attendee = 1 THEN pr.activity_id END) AS attendee_events,
                    COUNT(DISTINCT CASE WHEN pr.record_kind = 'activity' AND pr.has_presenter = 1 THEN pr.activity_id END) AS presenter_events,
                    COUNT(DISTINCT CASE WHEN pr.record_kind = 'presentation' THEN pr.engagement_id END) AS standalone_presentations,
                    {$totalExpression} AS total_participations,
                    MAX(pr.participation_date) AS latest_participation_date
               FROM users u
               LEFT JOIN filtered_records pr ON pr.user_id = u.user_id
              WHERE " . implode(' AND ', $userConditions) .
            ' GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.department, u.program' .
            $having;

        $countParams = array_merge($recordParams, $userParams);
        $countRows = $this->fetchAll(
            $cte . ' SELECT COUNT(*) AS total FROM (' . $select . ') faculty_rows',
            $recordTypes . $userTypes,
            $countParams
        );
        $total = (int) ($countRows[0]['total'] ?? 0);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $filters['page'] = min($filters['page'], $pageCount);
        $offset = ($filters['page'] - 1) * self::PAGE_SIZE;
        $rowParams = array_merge($recordParams, $userParams, [self::PAGE_SIZE, $offset]);
        $rows = $this->fetchAll(
            $cte . ' ' . $select .
            ' ORDER BY total_participations DESC, u.last_name ASC, u.first_name ASC, u.user_id ASC LIMIT ? OFFSET ?',
            $recordTypes . $userTypes . 'ii',
            $rowParams
        );

        foreach ($rows as &$row) {
            foreach (['user_id', 'activity_count', 'attendee_events', 'presenter_events', 'standalone_presentations', 'total_participations'] as $key) {
                $row[$key] = (int) $row[$key];
            }
        }
        unset($row);

        return [
            'available' => $this->hasParticipationSource(),
            'filters' => $filters,
            'options' => $options,
            'rows' => $rows,
            'total' => $total,
            'page_count' => $pageCount,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    private function getFilterOptions(): array
    {
        $departments = [];
        if ($this->hasTable('users') && $this->hasColumn('users', 'department')) {
            $departmentRows = $this->fetchAll(
                "SELECT DISTINCT department
                   FROM users
                  WHERE role = 'faculty' AND status = 'active' AND deleted_at IS NULL
                    AND department IS NOT NULL AND TRIM(department) <> ''
                  ORDER BY department",
                '',
                []
            );
            $departments = array_map(static fn(array $row): string => (string) $row['department'], $departmentRows);
        }

        $programs = [];
        if ($this->hasProgramAssignments()) {
            $programs = $this->fetchAll(
                "SELECT DISTINCT p.program_id, p.program_code AS label
                   FROM programs p
                   INNER JOIN faculty_program_assignments fpa ON fpa.program_id = p.program_id
                   INNER JOIN users u ON u.user_id = fpa.faculty_id
                  WHERE u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL
                    AND p.program_code IS NOT NULL AND TRIM(p.program_code) <> ''
                  ORDER BY label, p.program_id",
                '',
                []
            );
            foreach ($programs as &$program) {
                $program['program_id'] = (int) $program['program_id'];
            }
            unset($program);
        }

        $years = [(int) date('Y')];
        if ($this->hasParticipationSource() && $this->hasTable('users')) {
            $yearRows = $this->fetchAll(
                $this->participationCtes() .
                " SELECT DISTINCT YEAR(pr.participation_date) AS academic_year
                    FROM participation_records pr
                    INNER JOIN users u ON u.user_id = pr.user_id
                   WHERE pr.participation_date IS NOT NULL
                     AND u.role = 'faculty' AND u.status = 'active' AND u.deleted_at IS NULL
                   ORDER BY academic_year DESC",
                '',
                []
            );
            $years = array_values(array_unique(array_merge(
                $years,
                array_map(static fn(array $row): int => (int) $row['academic_year'], $yearRows)
            )));
            rsort($years, SORT_NUMERIC);
        }

        return [
            'departments' => $departments,
            'programs' => $programs,
            'academic_years' => $years,
            'program_filter_available' => $this->hasProgramAssignments(),
        ];
    }

    private function normalizeFilters(array $rawFilters, array $options): array
    {
        $search = is_string($rawFilters['search'] ?? null) ? trim($rawFilters['search']) : '';
        if (function_exists('mb_substr')) {
            $search = mb_substr($search, 0, 120, 'UTF-8');
        } else {
            $search = substr($search, 0, 120);
        }

        $department = is_string($rawFilters['department'] ?? null) ? $rawFilters['department'] : '';
        if (!in_array($department, $options['departments'], true)) {
            $department = '';
        }

        $programId = filter_var($rawFilters['program_id'] ?? 0, FILTER_VALIDATE_INT);
        $programId = ($programId !== false && $programId > 0) ? (int) $programId : 0;
        $programIds = array_column($options['programs'], 'program_id');
        if (!in_array($programId, $programIds, true)) {
            $programId = 0;
        }

        $academicYear = is_string($rawFilters['academic_year'] ?? null)
            ? $rawFilters['academic_year']
            : (is_int($rawFilters['academic_year'] ?? null) ? (string) $rawFilters['academic_year'] : '');
        if (!preg_match('/^\d{4}$/', $academicYear) || !in_array((int) $academicYear, $options['academic_years'], true)) {
            $academicYear = '';
        }

        $activityTypes = ['all', 'seminar_workshop', 'training', 'colloquium', 'conference_congress', 'conference_presentation'];
        $activityType = is_string($rawFilters['activity_type'] ?? null) ? $rawFilters['activity_type'] : 'all';
        if (!in_array($activityType, $activityTypes, true)) {
            $activityType = 'all';
        }

        $participationStatuses = ['all', 'participated', 'none'];
        $participationStatus = is_string($rawFilters['participation_status'] ?? null)
            ? $rawFilters['participation_status']
            : 'all';
        if (!in_array($participationStatus, $participationStatuses, true)) {
            $participationStatus = 'all';
        }

        $page = filter_var($rawFilters['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            $page = 1;
        }

        return [
            'search' => $search,
            'department' => $department,
            'program_id' => $programId,
            'academic_year' => $academicYear,
            'activity_type' => $activityType,
            'participation_status' => $participationStatus,
            'page' => (int) $page,
        ];
    }

    private function participationCtes(): string
    {
        $hasActivities = $this->hasTable('research_activities');
        $hasScheduleDates = $this->hasTable('activity_schedule_dates');
        $hasScheduleParticipants = $this->hasTable('activity_schedule_participants');
        $evidenceParts = [];

        if ($hasActivities && $hasScheduleDates && $hasScheduleParticipants) {
            $evidenceParts[] = "SELECT sp.user_id, d.activity_id, d.schedule_date AS participation_date, sp.role AS participant_role
                                  FROM activity_schedule_participants sp
                                  INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id AND d.status = 'scheduled'
                                  INNER JOIN research_activities ra ON ra.activity_id = d.activity_id
                                 WHERE sp.user_id IS NOT NULL AND sp.attended = 1
                                   AND ra.status IN ('conducted', 'completed')";
        }

        if ($hasActivities && $this->hasTable('activity_participants')) {
            $dateExpression = 'ra.activity_date';
            $legacyDateAvailability = '';
            $legacyScheduleDeduplication = '';

            if ($hasScheduleDates) {
                $dateExpression = "COALESCE(
                    (SELECT MIN(legacy_dates.schedule_date)
                       FROM activity_schedule_dates legacy_dates
                      WHERE legacy_dates.activity_id = ra.activity_id
                        AND legacy_dates.status = 'scheduled'),
                    ra.activity_date
                )";
                $legacyDateAvailability = "AND (
                    NOT EXISTS (SELECT 1 FROM activity_schedule_dates any_dates WHERE any_dates.activity_id = ra.activity_id)
                    OR EXISTS (SELECT 1 FROM activity_schedule_dates active_dates
                                WHERE active_dates.activity_id = ra.activity_id
                                  AND active_dates.status = 'scheduled')
                )";
            }
            if ($hasScheduleDates && $hasScheduleParticipants) {
                $legacyScheduleDeduplication = "AND NOT EXISTS (
                    SELECT 1
                      FROM activity_schedule_participants existing_participant
                      INNER JOIN activity_schedule_dates existing_date
                              ON existing_date.schedule_id = existing_participant.schedule_id
                     WHERE existing_date.activity_id = ap.activity_id
                       AND existing_participant.user_id = ap.user_id
                )";
            }

            $evidenceParts[] = "SELECT ap.user_id, ap.activity_id, {$dateExpression} AS participation_date, ap.role AS participant_role
                                  FROM activity_participants ap
                                  INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id
                                 WHERE ap.user_id IS NOT NULL AND ap.attended = 1
                                   AND ra.status IN ('conducted', 'completed')
                                   {$legacyDateAvailability}
                                   {$legacyScheduleDeduplication}";
        }

        $emptyEvidence = "SELECT CAST(NULL AS UNSIGNED) AS user_id,
                                 CAST(NULL AS UNSIGNED) AS activity_id,
                                 CAST(NULL AS DATE) AS participation_date,
                                 CAST(NULL AS CHAR(120)) AS participant_role
                            WHERE 1 = 0";
        $attendanceEvidence = $evidenceParts ? implode("\nUNION ALL\n", $evidenceParts) : $emptyEvidence;

        $activityRecordParts = [];
        if ($hasActivities && $evidenceParts) {
            $activityRecordParts[] = "SELECT evidence.user_id, evidence.activity_id, ra.activity_type,
                                             MIN(evidence.participation_date) AS participation_date,
                                             MAX(CASE WHEN LOWER(TRIM(COALESCE(evidence.participant_role, ''))) IN ('speaker', 'presenter') THEN 0 ELSE 1 END) AS has_attendee,
                                             MAX(CASE WHEN LOWER(TRIM(COALESCE(evidence.participant_role, ''))) IN ('speaker', 'presenter') THEN 1 ELSE 0 END) AS has_presenter
                                        FROM attendance_evidence evidence
                                        INNER JOIN research_activities ra ON ra.activity_id = evidence.activity_id
                                       WHERE ra.status IN ('conducted', 'completed')
                                       GROUP BY evidence.user_id, evidence.activity_id, ra.activity_type";
        }

        $hasEngagements = $this->hasTable('faculty_engagement_records');
        $hasActivityLink = $this->hasColumn('faculty_engagement_records', 'activity_id');
        if ($hasActivities && $hasEngagements && $hasActivityLink) {
            $linkedDateJoin = '';
            $linkedDateExpression = 'ra.activity_date';
            $linkedDateAvailability = '';
            if ($hasScheduleDates) {
                $linkedDateJoin = "LEFT JOIN (
                    SELECT activity_id, MIN(schedule_date) AS first_schedule_date
                      FROM activity_schedule_dates
                     WHERE status = 'scheduled'
                     GROUP BY activity_id
                ) linked_dates ON linked_dates.activity_id = ra.activity_id";
                $linkedDateExpression = 'COALESCE(linked_dates.first_schedule_date, ra.activity_date)';
                $linkedDateAvailability = "AND (
                    linked_dates.first_schedule_date IS NOT NULL
                    OR NOT EXISTS (SELECT 1 FROM activity_schedule_dates any_dates WHERE any_dates.activity_id = ra.activity_id)
                )";
            }

            $activityRecordParts[] = "SELECT fer.faculty_id AS user_id, ra.activity_id, ra.activity_type,
                                             {$linkedDateExpression} AS participation_date,
                                             0 AS has_attendee, 1 AS has_presenter
                                        FROM faculty_engagement_records fer
                                        INNER JOIN research_activities ra ON ra.activity_id = fer.activity_id
                                        {$linkedDateJoin}
                                       WHERE fer.activity_id IS NOT NULL
                                         AND fer.engagement_type = 'conference_presentation'
                                         AND fer.participation_role = 'presenter'
                                         AND fer.review_status = 'verified'
                                         AND ra.status IN ('conducted', 'completed')
                                         {$linkedDateAvailability}
                                       GROUP BY fer.faculty_id, ra.activity_id, ra.activity_type, {$linkedDateExpression}";
        }

        $emptyActivityRecords = "SELECT CAST(NULL AS UNSIGNED) AS user_id,
                                        CAST(NULL AS UNSIGNED) AS activity_id,
                                        CAST(NULL AS CHAR(40)) AS activity_type,
                                        CAST(NULL AS DATE) AS participation_date,
                                        0 AS has_attendee,
                                        0 AS has_presenter
                                   WHERE 1 = 0";
        $activityRecords = $activityRecordParts
            ? "SELECT activity_rows.user_id, activity_rows.activity_id, activity_rows.activity_type,
                      MIN(activity_rows.participation_date) AS participation_date,
                      MAX(activity_rows.has_attendee) AS has_attendee,
                      MAX(activity_rows.has_presenter) AS has_presenter
                 FROM (" . implode("\nUNION ALL\n", $activityRecordParts) . ") activity_rows
                GROUP BY activity_rows.user_id, activity_rows.activity_id, activity_rows.activity_type"
            : $emptyActivityRecords;

        $standaloneActivityCondition = $hasActivityLink ? 'AND fer.activity_id IS NULL' : '';
        $standalonePresentations = $hasEngagements
            ? "SELECT fer.faculty_id AS user_id, fer.engagement_id, fer.activity_date AS participation_date,
                      'conference_presentation' AS activity_type
                 FROM faculty_engagement_records fer
                WHERE fer.engagement_type = 'conference_presentation'
                  AND fer.participation_role = 'presenter'
                  AND fer.review_status = 'verified'
                  {$standaloneActivityCondition}"
            : "SELECT CAST(NULL AS UNSIGNED) AS user_id,
                      CAST(NULL AS UNSIGNED) AS engagement_id,
                      CAST(NULL AS DATE) AS participation_date,
                      CAST(NULL AS CHAR(40)) AS activity_type
                 WHERE 1 = 0";

        return "WITH attendance_evidence AS ({$attendanceEvidence}),
            activity_records AS ({$activityRecords}),
            standalone_presentations AS ({$standalonePresentations}),
            participation_records AS (
                SELECT ar.user_id, ar.activity_id, NULL AS engagement_id, ar.activity_type,
                       ar.participation_date, ar.has_attendee, ar.has_presenter, 'activity' AS record_kind
                  FROM activity_records ar
                UNION ALL
                SELECT sp.user_id, NULL AS activity_id, sp.engagement_id, sp.activity_type,
                       sp.participation_date, 0 AS has_attendee, 1 AS has_presenter, 'presentation' AS record_kind
                  FROM standalone_presentations sp
            )";
    }

    private function hasParticipationSource(): bool
    {
        $hasActivities = $this->hasTable('research_activities')
            && ($this->hasTable('activity_participants')
                || ($this->hasTable('activity_schedule_dates') && $this->hasTable('activity_schedule_participants')));

        return $hasActivities || $this->hasTable('faculty_engagement_records');
    }

    private function hasProgramAssignments(): bool
    {
        return $this->hasTable('faculty_program_assignments') && $this->hasTable('programs');
    }

    private function loadSchemaMetadata(): void
    {
        $tableNames = [
            'users',
            'research_activities',
            'activity_participants',
            'activity_schedule_dates',
            'activity_schedule_participants',
            'faculty_engagement_records',
            'faculty_program_assignments',
            'programs',
        ];
        $tableList = "'" . implode("','", $tableNames) . "'";
        $result = $this->conn->query(
            "SELECT TABLE_NAME, COLUMN_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$tableList})"
        );
        if (!$result) {
            throw new RuntimeException('Unable to inspect faculty participation schema: ' . $this->conn->error);
        }

        while ($row = $result->fetch_assoc()) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            $this->schemaColumns[$table][$column] = true;
        }
        $result->free();
    }

    private function hasTable(string $table): bool
    {
        return isset($this->schemaColumns[$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset($this->schemaColumns[$table][$column]);
    }

    private function fetchAll(string $sql, string $types, array $params): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare faculty participation query: ' . $this->conn->error);
        }

        if ($types !== '') {
            if (strlen($types) !== count($params)) {
                $stmt->close();
                throw new LogicException('Faculty participation query parameters do not match their types.');
            }
            $boundParams = [$types];
            foreach ($params as &$param) {
                $boundParams[] = &$param;
            }
            unset($param);
            if (!call_user_func_array([$stmt, 'bind_param'], $boundParams)) {
                $stmt->close();
                throw new RuntimeException('Unable to bind faculty participation query parameters.');
            }
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to execute faculty participation query: ' . $error);
        }

        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('Unable to read faculty participation query results.');
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        return $rows;
    }
}
