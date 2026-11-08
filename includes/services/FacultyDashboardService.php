<?php
/**
 * Faculty Dashboard Service
 *
 * Main orchestrator for faculty dashboard data aggregation.
 * Delegates to specialized services for each data domain.
 */

declare(strict_types=1);

require_once __DIR__ . '/FacultyAdviseeProgressService.php';
require_once __DIR__ . '/FacultyEngagementService.php';
require_once __DIR__ . '/FacultyParticipationService.php';
require_once __DIR__ . '/FacultyAnalyticsService.php';
require_once __DIR__ . '/FacultyProfileService.php';
require_once __DIR__ . '/../config.php';

class FacultyDashboardService
{
    private mysqli $conn;
    private FacultyAdviseeProgressService $adviseeProgressService;
    private FacultyEngagementService $engagementService;
    private FacultyParticipationService $participationService;
    private FacultyProfileService $profileService;
    private FacultyAnalyticsService $analyticsService;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
        $this->adviseeProgressService = new FacultyAdviseeProgressService($connection);
        $this->engagementService = new FacultyEngagementService($connection);
        $this->participationService = new FacultyParticipationService($connection);
        $this->profileService = new FacultyProfileService($connection);
        $this->analyticsService = new FacultyAnalyticsService($connection, $this->participationService);
    }

    /**
     * Get complete overview data for faculty dashboard
     *
     * @param int $userId Faculty user ID
     * @param int $adviseePage Requested page for the advisee project matrix
     * @return array Dashboard data
     */
    public function getOverviewData(int $userId, int $adviseePage = 1): array
    {
        // Fetch all data in parallel where possible
        $stats = $this->getStats($userId);
        $adviseeMatrix = $this->adviseeProgressService->getMatrix($userId, $adviseePage);
        $recentEngagements = $this->engagementService->getRecent($userId, 3);
        $profileStatus = $this->profileService->getStatus($userId);
        $recentPublications = $this->getRecentPublications($userId, 3);
        $participationSummary = $this->participationService->getMySummary($userId);
        $researchRecordCount = $this->getActiveResearchRecordCount($userId);
        $upcomingActivityCount = $this->getUpcomingActivityCount();

        return [
            'stats' => $stats,
            'adviseeMatrix' => $adviseeMatrix,
            'recentEngagements' => $recentEngagements,
            'profileStatus' => $profileStatus,
            'recentPublications' => $recentPublications,
            'participationSummary' => $participationSummary,
            'researchRecordCount' => $researchRecordCount,
            'upcomingActivityCount' => $upcomingActivityCount,
        ];
    }

    /**
     * Count scheduled activities shown in the shared upcoming activities view.
     * Returns null when the activity module is not installed.
     */
    private function getUpcomingActivityCount(): ?int
    {
        $tableCheck = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?'
        );
        if (!$tableCheck) {
            throw new RuntimeException('Unable to check upcoming activity availability: ' . $this->conn->error);
        }
        $activitiesTable = 'research_activities';
        $tableCheck->bind_param('s', $activitiesTable);
        $tableCheck->execute();
        $tableExists = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) === 1;
        $tableCheck->close();
        if (!$tableExists) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
               FROM research_activities
              WHERE status IN ('upcoming', 'scheduled')"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to count upcoming activities: ' . $this->conn->error);
        }
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total;
    }

    /**
     * Count the faculty member's active, non-deleted research records.
     * Returns null when the full research-record module is not installed.
     */
    private function getActiveResearchRecordCount(int $userId): ?int
    {
        $tableCheck = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (?, ?, ?)'
        );
        if (!$tableCheck) {
            throw new RuntimeException('Unable to check faculty research record availability: ' . $this->conn->error);
        }
        $recordsTable = 'faculty_research_records';
        $authorsTable = 'faculty_research_record_authors';
        $sdgsTable = 'faculty_research_record_sdgs';
        $tableCheck->bind_param('sss', $recordsTable, $authorsTable, $sdgsTable);
        $tableCheck->execute();
        $tableCount = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0);
        $tableCheck->close();
        if ($tableCount !== 3) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
               FROM faculty_research_records
              WHERE owner_id = ?
                AND status = 'active'
                AND deleted_at IS NULL"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to count faculty research records: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total;
    }

    /**
     * Get statistics cards data
     */
    private function getStats(int $userId): array
    {
        $projectCount = $this->getAdvisingProjectCount($userId);
        $publishedCount = $this->getPublishedOutputCount($userId);
        $verifiedEngagementCount = $this->engagementService->getVerifiedCount($userId);
        $registeredIpCount = $this->getRegisteredIpOutputCount($userId);
        $stats = [
            [
                'label' => 'Advising Projects',
                'value' => $projectCount,
                'tone' => 'primary',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>',
                'color' => 'var(--faculty-accent)',
                'subtext' => 'Active research projects',
            ],
        ];

        if ($publishedCount !== null) {
            $stats[] = [
                'label' => 'Publication Records',
                'value' => $publishedCount,
                'tone' => 'secondary',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>',
                'color' => 'var(--faculty-success)',
                'subtext' => 'Journal articles & papers',
            ];
        }

        if ($verifiedEngagementCount !== null) {
            $stats[] = [
                'label' => 'Verified Engagements',
                'value' => $verifiedEngagementCount,
                'tone' => 'success',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12" /></svg>',
                'color' => 'var(--faculty-success)',
                'subtext' => 'Approved activity records',
            ];
        }

        $stats[] = [
            'label' => 'Registered IP Outputs',
            'value' => $registeredIpCount === null ? '—' : $registeredIpCount,
            'tone' => 'secondary',
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><path d="M8 13h8M8 17h5" /></svg>',
            'color' => 'var(--faculty-success)',
            'subtext' => $registeredIpCount === null ? 'IP records are unavailable' : 'Registered intellectual property outputs',
        ];

        return $stats;
    }

    /**
     * Count registered IP outputs credited to this faculty member.
     * Returns null when the IP archive tables are not installed.
     */
    private function getRegisteredIpOutputCount(int $userId): ?int
    {
        $tableCheck = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (?, ?)'
        );
        if (!$tableCheck) {
            throw new RuntimeException('Unable to check faculty IP record availability: ' . $this->conn->error);
        }
        $applicationsTable = 'copyright_applications';
        $contributorsTable = 'copyright_contributors';
        $tableCheck->bind_param('ss', $applicationsTable, $contributorsTable);
        $tableCheck->execute();
        $tablesAvailable = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) === 2;
        $tableCheck->close();
        if (!$tablesAvailable) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
               FROM copyright_applications c
              WHERE c.output_type IN ('software','research','instructional_material','module','other','patent','utility_model','innovation','technology_transfer')
                AND c.deactivated_at IS NULL
                AND EXISTS (
                    SELECT 1
                      FROM copyright_contributors cc
                     WHERE cc.copyright_id = c.copyright_id
                       AND cc.user_id = ?
                )
                AND (
                    c.external_status = 'registered'
                    OR (c.external_status IS NULL AND c.status = 'registered' AND NULLIF(c.copyright_ref_no, '') IS NOT NULL)
                )"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to count faculty IP records: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total;
    }

    /**
     * Count advising projects for faculty
     */
    private function getAdvisingProjectCount(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(DISTINCT rp.project_id) AS total
               FROM research_projects rp
              WHERE rp.deleted_at IS NULL
                AND EXISTS (
                    SELECT 1 FROM project_advisers pa
                    WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
                )'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to count advising projects: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Count active publication records owned by or explicitly linked to this faculty member
     */
    private function getPublishedOutputCount(int $userId): ?int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM publications p WHERE p.deactivated_at IS NULL AND (p.researcher_id = ? OR EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?))");
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    /**
     * Get recent publications for faculty
     */
    private function getRecentPublications(int $userId, int $limit): array
    {
        $stmt = $this->conn->prepare("SELECT p.publication_id, p.research_title, p.publication_type, p.archival_status AS status, p.publication_date, p.journal_publisher AS journal_reference FROM publications p WHERE p.deactivated_at IS NULL AND (p.researcher_id = ? OR EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?)) ORDER BY p.publication_date DESC, p.created_at DESC LIMIT ?");
        $stmt->bind_param('iii', $userId, $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Get data for the standalone faculty analytics page.
     */
    public function normalizeAnalyticsFilters(array $query): array
    {
        return $this->analyticsService->normalizeFilters($query);
    }

    /**
     * Get faculty-scoped portfolio analytics. Filters must be normalized first.
     */
    public function getAnalyticsData(int $userId, array $filters): array
    {
        return $this->analyticsService->getAnalyticsData($userId, $filters);
    }
}
