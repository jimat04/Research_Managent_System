<?php
/**
 * Faculty Advisee Progress Service
 *
 * Generates the advisee progress matrix showing chapter completion status
 * across all projects the faculty advises.
 */

declare(strict_types=1);

class FacultyAdviseeProgressService
{
    private const PAGE_SIZE = 10;

    private mysqli $conn;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }

    /**
     * Get advisee progress matrix
     *
     * @param int $userId Faculty user ID
     * @param int $page Requested project page
     * @return array Matrix data with projects and their chapter statuses
     */
    public function getMatrix(int $userId, int $page = 1): array
    {
        $totalProjects = $this->countAdvisingProjects($userId);
        $totalPages = (int) ceil($totalProjects / self::PAGE_SIZE);

        if ($totalProjects === 0) {
            return [
                'has_advisees' => false,
                'projects' => [],
                'chapters' => range(1, 5),
                'total_projects' => 0,
                'page' => 1,
                'page_size' => self::PAGE_SIZE,
                'total_pages' => 0,
            ];
        }

        $page = max(1, min($page, $totalPages));
        $projects = $this->getAdvisingProjects(
            $userId,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );

        $projectIds = array_column($projects, 'project_id');
        $chapterStatuses = $this->getChapterStatuses($projectIds);

        // Keep each project's researcher and chapter statuses on one row.
        $projectRows = [];
        foreach ($projects as $project) {
            $studentName = trim(($project['student_first_name'] ?? '') . ' ' . ($project['student_last_name'] ?? ''));
            if ($studentName === '') {
                $studentName = 'Researcher not recorded';
            }

            $projectId = (int) $project['project_id'];
            $chapterProgress = [];
            for ($chapterNum = 1; $chapterNum <= 5; $chapterNum++) {
                $chapterKey = $projectId . '-' . $chapterNum;
                $status = $chapterStatuses[$chapterKey] ?? 'not_started';

                $chapterProgress[$chapterNum] = [
                    'status' => $status,
                    'status_label' => $this->getStatusLabel($status),
                    'status_class' => $this->getStatusClass($status),
                ];
            }

            $projectRows[] = [
                'project_id' => $projectId,
                'title' => (string) $project['title'],
                'status' => (string) $project['status'],
                'updated_at' => (string) $project['updated_at'],
                'student_id' => (int) $project['student_id'],
                'student_name' => $studentName,
                'program' => trim((string) ($project['student_program'] ?? '')),
                'chapters' => $chapterProgress,
            ];
        }

        return [
            'has_advisees' => true,
            'projects' => $projectRows,
            'chapters' => range(1, 5),
            'total_projects' => $totalProjects,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Count active projects assigned to this faculty member.
     */
    private function countAdvisingProjects(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(DISTINCT rp.project_id) AS total
               FROM project_advisers pa
               JOIN research_projects rp ON rp.project_id = pa.project_id AND rp.deleted_at IS NULL
              WHERE pa.adviser_id = ?'
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
     * Get one page of projects where faculty is adviser.
     */
    private function getAdvisingProjects(int $userId, int $limit, int $offset): array
    {
        $stmt = $this->conn->prepare(
            'SELECT DISTINCT rp.project_id, rp.title, rp.status, rp.updated_at,
                    COALESCE(rp.student_id, rp.created_by) AS student_id,
                    s.first_name AS student_first_name, s.last_name AS student_last_name,
                    s.program AS student_program
               FROM project_advisers pa
               JOIN research_projects rp ON rp.project_id = pa.project_id AND rp.deleted_at IS NULL
               LEFT JOIN users s ON s.user_id = COALESCE(rp.student_id, rp.created_by) AND s.deleted_at IS NULL
              WHERE pa.adviser_id = ?
              ORDER BY rp.updated_at DESC, rp.project_id DESC
              LIMIT ? OFFSET ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load advising projects: ' . $this->conn->error);
        }
        $stmt->bind_param('iii', $userId, $limit, $offset);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $results;
    }

    /**
     * Get latest chapter submission status for each project/chapter
     */
    private function getChapterStatuses(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $types = str_repeat('i', count($projectIds));

        $stmt = $this->conn->prepare(
            "SELECT rsv.project_id, c.chapter_number, rsv.status
               FROM research_submission_versions rsv
               JOIN chapters c ON c.chapter_id = rsv.chapter_id
              WHERE rsv.submission_type = 'chapter'
                AND rsv.project_id IN ($placeholders)
              ORDER BY rsv.project_id, c.chapter_number, rsv.version_number DESC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$projectIds);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Keep only the latest version per project/chapter
        $latestStatuses = [];
        foreach ($results as $row) {
            $key = $row['project_id'] . '-' . $row['chapter_number'];
            if (!isset($latestStatuses[$key])) {
                $latestStatuses[$key] = $row['status'];
            }
        }

        return $latestStatuses;
    }

    /**
     * Get human-readable status label
     */
    private function getStatusLabel(string $status): string
    {
        $labels = [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'under_review' => 'In Review',
            'revision_required' => 'Revision Required',
            'revised' => 'Revised',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'not_started' => 'Not Started',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Get CSS class for status badge
     */
    private function getStatusClass(string $status): string
    {
        $classes = [
            'draft' => 'neutral',
            'submitted' => 'review',
            'under_review' => 'review',
            'revision_required' => 'warning',
            'revised' => 'review',
            'approved' => 'success',
            'rejected' => 'danger',
            'not_started' => 'neutral',
        ];
        return $classes[$status] ?? 'neutral';
    }
}
