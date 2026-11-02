<?php
/**
 * Faculty Engagement Service
 *
 * Handles faculty engagement records data operations.
 */

declare(strict_types=1);

class FacultyEngagementService
{
    private mysqli $conn;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }

    /**
     * Get recent engagement records for faculty
     *
     * @param int $userId Faculty user ID
     * @param int $limit Maximum records to return
     * @return array Recent engagements
     */
    public function getRecent(int $userId, int $limit = 5): array
    {
        // Check if table exists
        if (!$this->tableExists('faculty_engagement_records')) {
            return [];
        }

        $stmt = $this->conn->prepare(
            'SELECT engagement_id, title, engagement_type, custom_engagement_type, participation_role,
                    activity_date, review_status, review_comments, reviewed_at
               FROM faculty_engagement_records
              WHERE faculty_id = ?
              ORDER BY activity_date DESC, engagement_id DESC
              LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map([$this, 'formatEngagement'], $results);
    }

    /**
     * Get verified engagement count
     */
    public function getVerifiedCount(int $userId): ?int
    {
        if (!$this->tableExists('faculty_engagement_records')) {
            return null;
        }

        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total
               FROM faculty_engagement_records
              WHERE faculty_id = ?
                AND review_status = "verified"'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get engagement status breakdown for analytics
     */
    public function getStatusBreakdown(int $userId): array
    {
        if (!$this->tableExists('faculty_engagement_records')) {
            return [];
        }

        $stmt = $this->conn->prepare(
            'SELECT review_status, COUNT(*) AS total
               FROM faculty_engagement_records
              WHERE faculty_id = ?
              GROUP BY review_status'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $breakdown = [
            'verified' => 0,
            'pending' => 0,
            'returned' => 0,
        ];

        foreach ($results as $row) {
            $status = $row['review_status'];
            if (isset($breakdown[$status])) {
                $breakdown[$status] = (int) $row['total'];
            }
        }

        return array_map(function ($status, $total) {
            return [
                'status' => $status,
                'total' => $total,
                'label' => ucfirst($status),
            ];
        }, array_keys($breakdown), array_values($breakdown));
    }

    /**
     * Check if table exists
     */
    private function tableExists(string $table): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS table_count FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        return (int) ($result['table_count'] ?? 0) > 0;
    }

    /**
     * Format engagement record for display
     */
    private function formatEngagement(array $row): array
    {
        $typeLabels = [
            'seminar_training' => 'Seminar/Training',
            'conference_presentation' => 'Conference Presentation',
            'extension_community' => 'Extension/Community',
            'research_award' => 'Research Award',
            'collaboration' => 'Collaboration',
            'other' => 'Other',
        ];

        $roleLabels = [
            'participant' => 'Participant',
            'presenter' => 'Presenter',
            'organizer' => 'Organizer',
            'adviser' => 'Adviser',
            'team_lead' => 'Team Lead',
        ];

        $statusLabels = [
            'pending' => 'Pending',
            'verified' => 'Verified',
            'returned' => 'Returned',
        ];

        $statusClasses = [
            'pending' => 'neutral',
            'verified' => 'success',
            'returned' => 'warning',
        ];

        $engagementType = $row['engagement_type'];
        $engagementTypeLabel = $typeLabels[$engagementType] ?? ucwords(str_replace('_', ' ', $engagementType));
        if ($engagementType === 'other' && !empty($row['custom_engagement_type'])) {
            $engagementTypeLabel .= ': ' . $row['custom_engagement_type'];
        }

        return [
            'engagement_id' => (int) $row['engagement_id'],
            'title' => $row['title'],
            'engagement_type' => $engagementType,
            'custom_engagement_type' => $row['custom_engagement_type'] ?? null,
            'engagement_type_label' => $engagementTypeLabel,
            'participation_role' => $row['participation_role'],
            'participation_role_label' => $roleLabels[$row['participation_role']] ?? ucfirst($row['participation_role']),
            'activity_date' => $row['activity_date'],
            'review_status' => $row['review_status'],
            'review_status_label' => $statusLabels[$row['review_status']] ?? ucfirst($row['review_status']),
            'review_status_class' => $statusClasses[$row['review_status']] ?? 'neutral',
            'review_comments' => $row['review_comments'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
        ];
    }
}