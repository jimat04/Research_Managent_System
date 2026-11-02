<?php
/**
 * Faculty Review Queue Service
 *
 * Unified review queue for all faculty reviewable items:
 * - Chapter submissions awaiting review
 * - Title proposals awaiting review
 * - Final manuscript submissions
 * - Returned engagement records requiring revision
 */

declare(strict_types=1);

class FacultyReviewQueueService
{
    private mysqli $conn;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }

    /**
     * Get pending reviews for faculty (unified queue)
     *
     * @param int $userId Faculty user ID
     * @param int $limit Maximum items to return
     * @return array Review queue items
     */
    public function getPendingReviews(int $userId, int $limit = 5): array
    {
        $items = [];

        // 1. Chapter submissions awaiting faculty review (where faculty is adviser/reviewer)
        $chapterReviews = $this->getPendingChapterReviews($userId, $limit);
        $items = array_merge($items, $chapterReviews);

        // 2. Title proposals awaiting review
        $titleReviews = $this->getPendingTitleReviews($userId, $limit);
        $items = array_merge($items, $titleReviews);

        // 3. Final manuscript submissions awaiting review
        $finalReviews = $this->getPendingFinalReviews($userId, $limit);
        $items = array_merge($items, $finalReviews);

        // 4. Returned engagement records (faculty's own submissions returned by admin)
        $returnedEngagements = $this->getReturnedEngagements($userId, $limit);
        $items = array_merge($items, $returnedEngagements);

        // Sort by submission date (newest first) and limit
        usort($items, fn($a, $b) => $b['submitted_at'] <=> $a['submitted_at']);
        return array_slice($items, 0, $limit);
    }

    /**
     * Get count of all pending reviews
     */
    public function getPendingCount(int $userId): int
    {
        $count = 0;
        $count += $this->countPendingChapterReviews($userId);
        $count += $this->countPendingTitleReviews($userId);
        $count += $this->countPendingFinalReviews($userId);
        $count += $this->countReturnedEngagements($userId);
        return $count;
    }

    /**
     * Get pending chapter reviews where faculty is the adviser
     */
    private function getPendingChapterReviews(int $userId, int $limit): array
    {
        $stmt = $this->conn->prepare(
            'SELECT rsv.version_id, rsv.project_id, rsv.chapter_id, rsv.submission_type,
                    rsv.submitted_at, rsv.submitted_by, rp.title AS project_title,
                    c.chapter_number, c.chapter_title,
                    s.first_name AS student_first_name, s.last_name AS student_last_name, s.email AS student_email
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN chapters c ON c.chapter_id = rsv.chapter_id
               JOIN users s ON s.user_id = rp.student_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "chapter"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )
              ORDER BY rsv.submitted_at DESC
              LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(function ($row) {
            return [
                'id' => 'chapter-' . $row['version_id'],
                'type' => 'chapter',
                'type_label' => 'Chapter',
                'type_icon' => '📄',
                'title' => 'Chapter ' . $row['chapter_number'] . ': ' . $row['chapter_title'],
                'project_title' => $row['project_title'],
                'project_id' => (int) $row['project_id'],
                'student_name' => trim($row['student_first_name'] . ' ' . $row['student_last_name']),
                'student_email' => $row['student_email'],
                'submitted_at' => $row['submitted_at'],
                'time_ago' => $this->formatTimeAgo($row['submitted_at']),
                'action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'] . '#chapter-' . $row['chapter_number'],
                'action_label' => 'View Chapter',
                'secondary_action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'],
                'secondary_action_label' => 'View Project',
                'badge' => null,
            ];
        }, $results);
    }

    /**
     * Count pending chapter reviews
     */
    private function countPendingChapterReviews(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "chapter"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get pending title reviews where faculty is the adviser
     */
    private function getPendingTitleReviews(int $userId, int $limit): array
    {
        $stmt = $this->conn->prepare(
            'SELECT rsv.version_id, rsv.project_id, rsv.submission_type,
                    rsv.submitted_at, rp.title AS project_title,
                    s.first_name AS student_first_name, s.last_name AS student_last_name, s.email AS student_email
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN users s ON s.user_id = rp.student_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "title"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )
              ORDER BY rsv.submitted_at DESC
              LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(function ($row) {
            return [
                'id' => 'title-' . $row['version_id'],
                'type' => 'title',
                'type_label' => 'Title Proposal',
                'type_icon' => '📝',
                'title' => 'Title Proposal: ' . $row['project_title'],
                'project_title' => $row['project_title'],
                'project_id' => (int) $row['project_id'],
                'student_name' => trim($row['student_first_name'] . ' ' . $row['student_last_name']),
                'student_email' => $row['student_email'],
                'submitted_at' => $row['submitted_at'],
                'time_ago' => $this->formatTimeAgo($row['submitted_at']),
                'action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'] . '#research-trail',
                'action_label' => 'View Title History',
                'secondary_action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'],
                'secondary_action_label' => 'View Project',
                'badge' => null,
            ];
        }, $results);
    }

    /**
     * Count pending title reviews
     */
    private function countPendingTitleReviews(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "title"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get pending final manuscript reviews where faculty is the adviser
     */
    private function getPendingFinalReviews(int $userId, int $limit): array
    {
        $stmt = $this->conn->prepare(
            'SELECT rsv.version_id, rsv.project_id, rsv.submission_type,
                    rsv.submitted_at, rp.title AS project_title,
                    s.first_name AS student_first_name, s.last_name AS student_last_name, s.email AS student_email
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN users s ON s.user_id = rp.student_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "final_manuscript"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )
              ORDER BY rsv.submitted_at DESC
              LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(function ($row) {
            return [
                'id' => 'final-' . $row['version_id'],
                'type' => 'final',
                'type_label' => 'Final Manuscript',
                'type_icon' => '📋',
                'title' => 'Final Manuscript: ' . $row['project_title'],
                'project_title' => $row['project_title'],
                'project_id' => (int) $row['project_id'],
                'student_name' => trim($row['student_first_name'] . ' ' . $row['student_last_name']),
                'student_email' => $row['student_email'],
                'submitted_at' => $row['submitted_at'],
                'time_ago' => $this->formatTimeAgo($row['submitted_at']),
                'action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'] . '#final-manuscript-heading',
                'action_label' => 'View Manuscript',
                'secondary_action_url' => SITE_URL . 'pages/shared/research-detail.php?id=' . $row['project_id'],
                'secondary_action_label' => 'View Project',
                'badge' => null,
            ];
        }, $results);
    }

    /**
     * Count pending final reviews
     */
    private function countPendingFinalReviews(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total
               FROM research_submission_versions rsv
               JOIN research_projects rp ON rp.project_id = rsv.project_id
               JOIN project_advisers pa ON pa.project_id = rp.project_id AND pa.adviser_id = ?
              WHERE rsv.submission_type = "final_manuscript"
                AND rsv.status = "submitted"
                AND rp.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM research_submission_reviews rsr
                    WHERE rsr.version_id = rsv.version_id
                )'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get returned engagement records (faculty's own submissions returned by admin)
     */
    private function getReturnedEngagements(int $userId, int $limit): array
    {
        // Check if table exists first
        $tableCheck = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "faculty_engagement_records"'
        );
        if (!$tableCheck) {
            return [];
        }
        $tableCheck->execute();
        $exists = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
        $tableCheck->close();

        if (!$exists) {
            return [];
        }

        $stmt = $this->conn->prepare(
            'SELECT engagement_id, title, engagement_type, custom_engagement_type, activity_date,
                    review_status, review_comments, reviewed_at
               FROM faculty_engagement_records
              WHERE faculty_id = ?
                AND review_status = "returned"
              ORDER BY reviewed_at DESC, engagement_id DESC
              LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(function ($row) {
            $typeLabels = [
                'seminar_training' => 'Seminar/Training',
                'conference_presentation' => 'Conference Presentation',
                'extension_community' => 'Extension/Community',
                'research_award' => 'Research Award',
                'collaboration' => 'Collaboration',
                'other' => 'Other',
            ];

            $engagementType = $row['engagement_type'];
            $engagementTypeLabel = $typeLabels[$engagementType] ?? ucwords(str_replace('_', ' ', $engagementType));
            if ($engagementType === 'other' && !empty($row['custom_engagement_type'])) {
                $engagementTypeLabel .= ': ' . $row['custom_engagement_type'];
            }

            return [
                'id' => 'engagement-' . $row['engagement_id'],
                'type' => 'engagement',
                'type_label' => 'Engagement Record',
                'type_icon' => '📋',
                'title' => $engagementTypeLabel,
                'project_title' => $row['title'],
                'project_id' => null,
                'student_name' => '(Self-submitted)',
                'student_email' => null,
                'submitted_at' => $row['reviewed_at'] ?? $row['activity_date'],
                'time_ago' => $this->formatTimeAgo($row['reviewed_at'] ?? $row['activity_date']),
                'action_url' => SITE_URL . 'pages/faculty/faculty-engagement.php?view=history#engagement-' . (int) $row['engagement_id'],
                'action_label' => 'View Feedback',
                'secondary_action_url' => SITE_URL . 'pages/faculty/faculty-engagement.php?view=history',
                'secondary_action_label' => 'View History',
                'badge' => 'Returned - Revise',
                'badge_class' => 'warning',
                'admin_comment' => $row['review_comments'] ?? null,
            ];
        }, $results);
    }

    /**
     * Count returned engagements
     */
    private function countReturnedEngagements(int $userId): int
    {
        $tableCheck = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "faculty_engagement_records"'
        );
        if (!$tableCheck) {
            return 0;
        }
        $tableCheck->execute();
        $exists = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
        $tableCheck->close();

        if (!$exists) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total
               FROM faculty_engagement_records
              WHERE faculty_id = ?
                AND review_status = "returned"'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Format time ago string
     */
    private function formatTimeAgo(string $datetime): string
    {
        $now = new DateTime();
        $then = new DateTime($datetime);
        $diff = $now->diff($then);

        if ($diff->d > 0) {
            return $diff->d . 'd ago';
        } elseif ($diff->h > 0) {
            return $diff->h . 'h ago';
        } elseif ($diff->i > 0) {
            return $diff->i . 'm ago';
        } else {
            return 'Just now';
        }
    }
}
