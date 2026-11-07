<?php
/**
 * Faculty-scoped analytics for the faculty research portfolio.
 */

declare(strict_types=1);

require_once __DIR__ . '/FacultyParticipationService.php';

class FacultyAnalyticsService
{
    private mysqli $conn;
    private FacultyParticipationService $participationService;
    private array $tableCache = [];

    public function __construct(mysqli $connection, FacultyParticipationService $participationService)
    {
        $this->conn = $connection;
        $this->participationService = $participationService;
    }

    public function academicYearOptions(): array
    {
        if (!$this->tableExists('academic_years')) {
            return [];
        }

        $rows = $this->queryRows(
            'SELECT DISTINCT label FROM academic_years WHERE label <> "" ORDER BY label DESC'
        );
        return array_map(static fn(array $row): array => [
            'value' => (string) $row['label'],
            'label' => (string) $row['label'],
        ], $rows);
    }

    /**
     * Validate page filters. Dates are strict ISO calendar dates and invalid
     * or reversed ranges are ignored with an inline explanation.
     */
    public function normalizeFilters(array $query): array
    {
        $filters = [];
        $errors = [];
        $domains = ['projects', 'publications', 'engagements', 'participation', 'records', 'ip'];
        foreach ($domains as $domain) {
            foreach (['from', 'to'] as $edge) {
                $key = $domain . '_' . $edge;
                $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
                if ($value !== '' && !$this->isValidDate($value)) {
                    $errors[] = 'A date filter was ignored because it was not a valid date.';
                    $value = '';
                }
                $filters[$key] = $value;
            }

            if ($filters[$domain . '_from'] !== '' && $filters[$domain . '_to'] !== ''
                && $filters[$domain . '_from'] > $filters[$domain . '_to']) {
                $filters[$domain . '_from'] = '';
                $filters[$domain . '_to'] = '';
                $errors[] = 'A date range was cleared because its start date was after its end date.';
            }
        }

        $academicYear = is_string($query['academic_year'] ?? null)
            ? trim($query['academic_year'])
            : '';
        $availableYears = array_column($this->academicYearOptions(), 'value');
        if ($academicYear !== '' && !in_array($academicYear, $availableYears, true)) {
            $academicYear = '';
            $errors[] = 'The selected academic year is unavailable; the project filter was cleared.';
        }
        $filters['academic_year'] = $academicYear;

        // Keep the established five-year publication window as the default.
        if ($filters['publications_from'] === '' && $filters['publications_to'] === '') {
            $year = (int) date('Y');
            $filters['publications_from'] = ($year - 4) . '-01-01';
            $filters['publications_to'] = date('Y-m-d');
        }

        return ['filters' => $filters, 'errors' => array_values(array_unique($errors))];
    }

    public function getAnalyticsData(int $userId, array $filters): array
    {
        $projectRows = $this->projectStatusRows($userId, $filters);
        $chapterRows = $this->projectChapterRows($userId, $filters);
        $projectYearRows = $this->projectAcademicYearRows($userId, $filters);
        $publicationRows = $this->publicationYearRows($userId, $filters);
        $publicationTypes = $this->publicationTypeRows($userId, $filters);
        $publicationScopes = $this->publicationScopeRows($userId, $filters);
        $publicationStatuses = $this->publicationStatusRows($userId, $filters);
        $engagementStatuses = $this->engagementRows($userId, $filters, 'review_status');
        $engagementTypes = $this->engagementRows($userId, $filters, 'engagement_type');
        $engagementRoles = $this->engagementRows($userId, $filters, 'participation_role');
        $participation = $this->participationService->getAnalyticsSummary(
            $userId,
            $filters['participation_from'] ?: null,
            $filters['participation_to'] ?: null
        );
        $participationTypes = $participation['by_type'] ?? [];
        $participationKinds = $participation['by_kind'] ?? [];
        $recordTypes = $this->researchRecordRows($userId, $filters, 'work_type_label');
        $recordStatuses = $this->researchRecordRows($userId, $filters, 'status');
        $copyrightStatuses = $this->copyrightRows($userId, $filters, false, 'status');
        $ipTypes = $this->copyrightRows($userId, $filters, true, 'output_type');
        $ipExternalStatuses = $this->copyrightRows($userId, $filters, true, 'external_status');

        $sections = [
            $this->section('projects-status', 'Advised projects by status', 'Current workflow status for projects assigned to you as adviser.', $projectRows, SITE_URL . 'pages/faculty/faculty-advising.php'),
            $this->section('projects-chapters', 'Approved chapter progress', 'Count of approved Chapters 1–5 per advised project. Chapter approval alone does not mark a project complete.', $chapterRows, SITE_URL . 'pages/faculty/faculty-advising.php'),
            $this->section('projects-year', 'Projects by academic year', 'Academic year from the assigned project record.', $projectYearRows, SITE_URL . 'pages/faculty/faculty-advising.php'),
            $this->section('publications-year', 'Published outputs by year', 'Published or archived outputs credited to you, using publication date or record creation date.', $publicationRows, SITE_URL . 'pages/faculty/faculty-publications.php'),
            $this->section('publications-type', 'Publication records by type', 'Non-deactivated publication records where you are listed as a contributor.', $publicationTypes, SITE_URL . 'pages/faculty/faculty-publications.php'),
            $this->section('publications-scope', 'Publication records by scope', 'Classification scope from publication records; missing classifications are shown separately.', $publicationScopes, SITE_URL . 'pages/faculty/faculty-publications.php'),
            $this->section('publications-status', 'Publication records by status', 'Current publication workflow status, including items that are not yet published.', $publicationStatuses, SITE_URL . 'pages/faculty/faculty-publications.php'),
            $this->section('engagements-status', 'Engagements by verification status', 'Your engagement records grouped by their current verification status.', $engagementStatuses, SITE_URL . 'pages/faculty/faculty-engagement.php'),
            $this->section('engagements-type', 'Engagements by activity type', 'Your engagement records grouped by recorded activity type.', $engagementTypes, SITE_URL . 'pages/faculty/faculty-engagement.php'),
            $this->section('engagements-role', 'Engagements by participation role', 'Your engagement records grouped by your recorded role.', $engagementRoles, SITE_URL . 'pages/faculty/faculty-engagement.php'),
            $this->section('participation-types', 'Verified event participation by type', 'Deduplicated attendance and verified presentations at conducted or completed activities.', $participationTypes, SITE_URL . 'pages/faculty/faculty-profile.php?tab=achievements'),
            $this->section('participation-kinds', 'Verified event participation', 'Events attended or presented, plus verified standalone presentations.', $participationKinds, SITE_URL . 'pages/faculty/faculty-profile.php?tab=achievements'),
            $this->section('records-type', 'Research portfolio records by type', 'Your active and archived research portfolio records; these are saved portfolio entries, not publication verification.', $recordTypes, SITE_URL . 'pages/faculty/faculty-research-records.php'),
            $this->section('records-status', 'Research portfolio records by status', 'Active and archived records only; deleted / recoverable records are excluded from performance summaries.', $recordStatuses, SITE_URL . 'pages/faculty/faculty-research-records.php'),
            $this->section('copyright-status', 'Copyright applications by RMS status', 'Faculty-owned copyright output applications grouped by their RMS application status.', $copyrightStatuses, SITE_URL . 'pages/shared/copyrights.php'),
            $this->section('ip-type', 'IP records by output type', 'Faculty-owned patent, utility model, innovation, and technology-transfer records.', $ipTypes, SITE_URL . 'pages/shared/copyrights.php'),
            $this->section('ip-external-status', 'IP records by external status', 'External registration state is separate from RMS application status.', $ipExternalStatuses, SITE_URL . 'pages/shared/copyrights.php'),
        ];

        $available = [
            'engagements' => $this->tableExists('faculty_engagement_records'),
            'participation' => (bool) ($participation['available'] ?? false),
            'research_records' => $this->researchRecordsAvailable(),
            'ip_records' => $this->tableExists('copyright_applications'),
        ];
        foreach ($sections as &$section) {
            $sectionKey = (string) ($section['key'] ?? '');
            $availabilityKey = match (true) {
                str_starts_with($sectionKey, 'engagements-') => 'engagements',
                str_starts_with($sectionKey, 'participation-') => 'participation',
                str_starts_with($sectionKey, 'records-') => 'research_records',
                str_starts_with($sectionKey, 'copyright-'), str_starts_with($sectionKey, 'ip-') => 'ip_records',
                default => null,
            };
            $section['available'] = $availabilityKey === null || $available[$availabilityKey];
        }
        unset($section);

        $publishedTotal = $this->publishedOutputCount($userId, $filters);
        $registeredIpTotal = $this->registeredIpCount($userId, $filters);
        $activeRecords = $this->activeResearchRecordCount($userId, $filters);
        $kpis = [
            ['label' => 'Advising projects', 'value' => $this->sumRows($projectRows), 'detail' => 'Current assigned projects'],
            ['label' => 'Published outputs', 'value' => $publishedTotal, 'detail' => 'Published or archived publication records'],
            ['label' => 'Verified engagements', 'value' => $this->valueFor($engagementStatuses, 'Verified'), 'detail' => 'Engagement records verified'],
            ['label' => 'Event participations', 'value' => (int) ($participation['total_participations'] ?? 0), 'detail' => 'Deduplicated verified events and presentations'],
            ['label' => 'Active research records', 'value' => $activeRecords, 'detail' => 'Owned, active, non-deleted portfolio records'],
            ['label' => 'Registered IP records', 'value' => $registeredIpTotal, 'detail' => 'Owned IP with external registration recorded'],
        ];

        if (!$available['engagements']) {
            $kpis[2]['value'] = 'Unavailable';
        }
        if (!$available['participation']) {
            $kpis[3]['value'] = 'Unavailable';
        }
        if (!$available['research_records']) {
            $kpis[4]['value'] = 'Unavailable';
        }
        if (!$available['ip_records']) {
            $kpis[5]['value'] = 'Unavailable';
        }

        return [
            'kpis' => $kpis,
            'sections' => $sections,
            'academic_year_options' => $this->academicYearOptions(),
            'availability' => $available,
        ];
    }

    private function projectStatusRows(int $userId, array $filters): array
    {
        [$where, $types, $params] = $this->projectWhere($userId, $filters);
        $rows = $this->queryRows(
            'SELECT rp.status AS label, COUNT(DISTINCT rp.project_id) AS value
               FROM research_projects rp
              WHERE ' . $where . '
              GROUP BY rp.status ORDER BY value DESC, rp.status ASC',
            $types,
            $params
        );
        return $this->labelRows($rows);
    }

    private function projectChapterRows(int $userId, array $filters): array
    {
        [$where, $types, $params] = $this->projectWhere($userId, $filters);
        $rows = $this->queryRows(
            'SELECT CONCAT(progress.approved_chapters, " of 5 chapters approved") AS label,
                    COUNT(*) AS value
               FROM (
                    SELECT rp.project_id, COUNT(DISTINCT ch.chapter_id) AS approved_chapters
                      FROM research_projects rp
                      LEFT JOIN chapters ch ON ch.project_id = rp.project_id
                           AND ch.deleted_at IS NULL AND ch.status = "approved"
                     WHERE ' . $where . '
                     GROUP BY rp.project_id
               ) progress
              GROUP BY progress.approved_chapters
              ORDER BY progress.approved_chapters',
            $types,
            $params
        );
        return $rows;
    }

    private function projectAcademicYearRows(int $userId, array $filters): array
    {
        [$where, $types, $params] = $this->projectWhere($userId, $filters, false);
        $rows = $this->queryRows(
            'SELECT COALESCE(NULLIF(ay.label, ""), "No academic year recorded") AS label,
                    COUNT(DISTINCT rp.project_id) AS value
               FROM research_projects rp
               LEFT JOIN academic_years ay ON ay.ay_id = rp.ay_id
              WHERE ' . $where . '
              GROUP BY label ORDER BY label DESC',
            $types,
            $params
        );
        return $rows;
    }

    private function projectWhere(int $userId, array $filters, bool $useAcademicYear = true): array
    {
        $where = [
            'rp.deleted_at IS NULL',
            'EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?)',
        ];
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'rp.created_at', $filters['projects_from'], $filters['projects_to'], true);
        if ($useAcademicYear && $filters['academic_year'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM academic_years ay_filter WHERE ay_filter.ay_id = rp.ay_id AND ay_filter.label = ?)';
            $types .= 's';
            $params[] = $filters['academic_year'];
        }
        return [implode(' AND ', $where), $types, $params];
    }

    private function publicationYearRows(int $userId, array $filters): array
    {
        $types = '';
        $params = [];
        $where = $this->publicationWhere($userId, $filters, true, $types, $params);
        $rows = $this->queryRows(
            'SELECT YEAR(COALESCE(p.publication_date, p.created_at)) AS label,
                    COUNT(*) AS value
               FROM publications p
              WHERE ' . $where . '
              GROUP BY YEAR(COALESCE(p.publication_date, p.created_at))
              ORDER BY label',
            $types,
            $params
        );
        return array_map(static fn(array $row): array => [
            'label' => (string) $row['label'],
            'value' => (int) $row['value'],
        ], $rows);
    }

    private function publicationTypeRows(int $userId, array $filters): array
    {
        $types = '';
        $params = [];
        $where = $this->publicationWhere($userId, $filters, false, $types, $params);
        $rows = $this->queryRows(
            'SELECT COALESCE(NULLIF(pt.label, ""), NULLIF(p.publication_type_name, ""), p.publication_type, "Not recorded") AS label,
                    COUNT(*) AS value
               FROM publications p
               LEFT JOIN publication_types pt ON pt.publication_type_id = p.publication_type_id
              WHERE ' . $where . '
              GROUP BY label ORDER BY value DESC, label ASC',
            $types,
            $params
        );
        return $rows;
    }

    private function publicationScopeRows(int $userId, array $filters): array
    {
        $types = '';
        $params = [];
        $where = $this->publicationWhere($userId, $filters, false, $types, $params);
        $rows = $this->queryRows(
            'SELECT COALESCE(NULLIF(ps.label, ""), "Not recorded") AS label,
                    COUNT(*) AS value
               FROM publications p
               LEFT JOIN publication_scopes ps ON ps.publication_scope_id = p.publication_scope_id
              WHERE ' . $where . '
              GROUP BY label ORDER BY value DESC, label ASC',
            $types,
            $params
        );
        return $rows;
    }

    private function publicationStatusRows(int $userId, array $filters): array
    {
        $types = '';
        $params = [];
        $where = $this->publicationWhere($userId, $filters, false, $types, $params);
        $rows = $this->queryRows(
            'SELECT p.status AS label, COUNT(*) AS value
               FROM publications p
              WHERE ' . $where . '
              GROUP BY p.status ORDER BY value DESC, p.status ASC',
            $types,
            $params
        );
        return $this->labelRows($rows);
    }

    private function publicationWhere(int $userId, array $filters, bool $publishedOnly, string &$types, array &$params): string
    {
        $where = [
            'p.deactivated_at IS NULL',
            'EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?)',
        ];
        $types = 'i';
        $params = [$userId];
        if ($publishedOnly) {
            $where[] = '(p.status = "published" OR p.archival_status = "archived")';
        }
        $this->appendDateRange($where, $types, $params, 'COALESCE(p.publication_date, p.created_at)', $filters['publications_from'], $filters['publications_to'], true);
        return implode(' AND ', $where);
    }

    private function engagementRows(int $userId, array $filters, string $groupColumn): array
    {
        if (!$this->tableExists('faculty_engagement_records')) {
            return [];
        }
        $allowed = ['review_status', 'engagement_type', 'participation_role'];
        if (!in_array($groupColumn, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported faculty engagement grouping.');
        }
        $where = ['faculty_id = ?'];
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'activity_date', $filters['engagements_from'], $filters['engagements_to']);
        $rows = $this->queryRows(
            'SELECT ' . $groupColumn . ' AS label, COUNT(*) AS value
               FROM faculty_engagement_records
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY ' . $groupColumn . '
              ORDER BY value DESC, label ASC',
            $types,
            $params
        );
        return $this->labelRows($rows);
    }

    private function researchRecordRows(int $userId, array $filters, string $groupColumn): array
    {
        if (!$this->researchRecordsAvailable()) {
            return [];
        }
        $allowed = ['work_type_label', 'status'];
        if (!in_array($groupColumn, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported faculty portfolio grouping.');
        }
        $where = ['owner_id = ?', 'deleted_at IS NULL'];
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'created_at', $filters['records_from'], $filters['records_to'], true);
        $rows = $this->queryRows(
            'SELECT COALESCE(NULLIF(' . $groupColumn . ', ""), "Not recorded") AS label, COUNT(*) AS value
               FROM faculty_research_records
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY label ORDER BY value DESC, label ASC',
            $types,
            $params
        );
        return $this->labelRows($rows);
    }

    private function copyrightRows(int $userId, array $filters, bool $ipOnly, string $groupColumn): array
    {
        if (!$this->tableExists('copyright_applications')) {
            return [];
        }
        $allowed = ['status', 'output_type', 'external_status'];
        if (!in_array($groupColumn, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported faculty IP grouping.');
        }
        $where = ['applicant_id = ?', 'deactivated_at IS NULL'];
        if ($ipOnly) {
            $where[] = "output_type IN ('patent', 'utility_model', 'innovation', 'technology_transfer')";
        } else {
            $where[] = "output_type IN ('software', 'research', 'instructional_material', 'module', 'other')";
        }
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'COALESCE(filing_date, created_at)', $filters['ip_from'], $filters['ip_to'], true);
        $rows = $this->queryRows(
            'SELECT COALESCE(NULLIF(' . $groupColumn . ', ""), "Not recorded") AS label,
                    COUNT(*) AS value
               FROM copyright_applications
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY label ORDER BY value DESC, label ASC',
            $types,
            $params
        );
        return $this->labelRows($rows);
    }

    private function publishedOutputCount(int $userId, array $filters): int
    {
        $types = '';
        $params = [];
        $where = $this->publicationWhere($userId, $filters, true, $types, $params);
        $rows = $this->queryRows('SELECT COUNT(*) AS total FROM publications p WHERE ' . $where, $types, $params);
        return (int) ($rows[0]['total'] ?? 0);
    }

    private function registeredIpCount(int $userId, array $filters): int
    {
        if (!$this->tableExists('copyright_applications')) {
            return 0;
        }
        $where = [
            'applicant_id = ?',
            'deactivated_at IS NULL',
            "output_type IN ('patent', 'utility_model', 'innovation', 'technology_transfer')",
            'external_status = "registered"',
        ];
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'COALESCE(filing_date, created_at)', $filters['ip_from'], $filters['ip_to'], true);
        $rows = $this->queryRows('SELECT COUNT(*) AS total FROM copyright_applications WHERE ' . implode(' AND ', $where), $types, $params);
        return (int) ($rows[0]['total'] ?? 0);
    }

    private function activeResearchRecordCount(int $userId, array $filters): int
    {
        if (!$this->researchRecordsAvailable()) {
            return 0;
        }
        $where = ['owner_id = ?', 'status = "active"', 'deleted_at IS NULL'];
        $types = 'i';
        $params = [$userId];
        $this->appendDateRange($where, $types, $params, 'created_at', $filters['records_from'], $filters['records_to'], true);
        $rows = $this->queryRows('SELECT COUNT(*) AS total FROM faculty_research_records WHERE ' . implode(' AND ', $where), $types, $params);
        return (int) ($rows[0]['total'] ?? 0);
    }

    private function researchRecordsAvailable(): bool
    {
        return $this->tablesExist([
            'faculty_research_records',
            'faculty_research_record_authors',
            'faculty_research_record_sdgs',
        ]);
    }

    private function section(string $key, string $title, string $description, array $rows, string $href): array
    {
        $cleanRows = array_map(static fn(array $row): array => [
            'label' => (string) ($row['label'] ?? 'Not recorded'),
            'value' => (int) ($row['value'] ?? 0),
        ], $rows);
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'rows' => $cleanRows,
            'columns' => [
                ['key' => 'label', 'label' => 'Category'],
                ['key' => 'value', 'label' => 'Count'],
            ],
            'chart_data' => $cleanRows,
            'href' => $href,
        ];
    }

    private function labelRows(array $rows): array
    {
        $labels = [
            'under_review' => 'Under review',
            'revision_required' => 'Revision required',
            'for_revision' => 'For revision',
            'title_approved' => 'Title approved',
            'under_crec_review' => 'Under CREC review',
            'under_erec_review' => 'Under EREC review',
            'seminar_training' => 'Seminar / training',
            'conference_presentation' => 'Conference presentation',
            'extension_community' => 'Extension / community',
            'research_award' => 'Research award',
            'collaboration' => 'Collaboration',
            'participant' => 'Participant',
            'presenter' => 'Presenter',
            'organizer' => 'Organizer',
            'adviser' => 'Adviser',
            'team_lead' => 'Team lead',
            'pending' => 'Pending',
            'verified' => 'Verified',
            'returned' => 'Returned',
            'under_review' => 'Under review',
            'accepted' => 'Accepted',
            'published' => 'Published',
            'submitted' => 'Submitted',
            'active' => 'Active',
            'archived' => 'Archived',
            'registered' => 'Registered',
            'filed' => 'Filed',
            'processing' => 'Processing',
            'refused' => 'Refused',
            'withdrawn' => 'Withdrawn',
            'not recorded' => 'Not recorded',
        ];
        return array_map(static function (array $row) use ($labels): array {
            $raw = (string) ($row['label'] ?? '');
            return [
                'label' => $labels[$raw] ?? ($raw === '' ? 'Not recorded' : ucwords(str_replace('_', ' ', $raw))),
                'value' => (int) ($row['value'] ?? 0),
            ];
        }, $rows);
    }

    private function sumRows(array $rows): int
    {
        return array_sum(array_map(static fn(array $row): int => (int) ($row['value'] ?? 0), $rows));
    }

    private function valueFor(array $rows, string $label): int
    {
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['label'] ?? ''), $label) === 0) {
                return (int) ($row['value'] ?? 0);
            }
        }
        return 0;
    }

    private function appendDateRange(array &$where, string &$types, array &$params, string $column, string $from, string $to, bool $timestampColumn = false): void
    {
        if ($from !== '') {
            $where[] = $column . ' >= ?';
            $types .= 's';
            $params[] = $from;
        }
        if ($to !== '') {
            $where[] = $column . ($timestampColumn ? ' < DATE_ADD(?, INTERVAL 1 DAY)' : ' <= ?');
            $types .= 's';
            $params[] = $to;
        }
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches)) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }
        return true;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $rows = $this->queryRows(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            [$table]
        );
        return $this->tableCache[$table] = (int) ($rows[0]['total'] ?? 0) > 0;
    }

    private function queryRows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare faculty analytics query: ' . $this->conn->error);
        }
        if ($types !== '') {
            $bound = [$types];
            foreach ($params as &$param) {
                $bound[] = &$param;
            }
            unset($param);
            if (!call_user_func_array([$stmt, 'bind_param'], $bound)) {
                $stmt->close();
                throw new RuntimeException('Unable to bind faculty analytics query parameters.');
            }
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to execute faculty analytics query: ' . $error);
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('Unable to read faculty analytics query results.');
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        return $rows;
    }
}
