<?php
/**
 * Faculty Profile Service
 *
 * Handles faculty research profile data operations.
 */

declare(strict_types=1);

class FacultyProfileService
{
    private mysqli $conn;

    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }

    /**
     * Get faculty profile status and data
     *
     * @param int $userId Faculty user ID
     * @return array Profile status and quick-edit links
     */
    public function getStatus(int $userId): array
    {
        // Check if table exists
        if (!$this->tableExists('faculty_research_profiles')) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'status_label' => 'Profile module not installed',
                'needs_attention' => false,
                'guidance' => 'The faculty research profile feature is not available.',
                'quick_edits' => [],
            ];
        }

        $stmt = $this->conn->prepare(
            'SELECT highest_degree, research_interests,
                    orcid_url, google_scholar_url, researchgate_url, updated_at
               FROM faculty_research_profiles
              WHERE user_id = ?
              LIMIT 1'
        );
        if (!$stmt) {
            return $this->getDefaultProfile();
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return $this->getDefaultProfile();
        }

        return [
            'available' => true,
            'status' => 'saved',
            'status_label' => 'Saved',
            'needs_attention' => false,
            'guidance' => 'Your research profile is saved. You can edit it anytime.',
            'updated_at' => $result['updated_at'] ?? null,
            'quick_edits' => [
                [
                    'label' => 'Research Interests',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#interests',
                    'icon' => '📝',
                    'has_value' => !empty($result['research_interests']),
                ],
                [
                    'label' => 'ORCID',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#orcid',
                    'icon' => '🔗',
                    'has_value' => !empty($result['orcid_url']),
                    'value' => $result['orcid_url'] ?? null,
                ],
                [
                    'label' => 'Google Scholar',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#scholar',
                    'icon' => '🎓',
                    'has_value' => !empty($result['google_scholar_url']),
                    'value' => $result['google_scholar_url'] ?? null,
                ],
                [
                    'label' => 'ResearchGate',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#researchgate',
                    'icon' => '🔬',
                    'has_value' => !empty($result['researchgate_url']),
                    'value' => $result['researchgate_url'] ?? null,
                ],
            ],
            'highest_degree' => $result['highest_degree'] ?? null,
        ];
    }

    /**
     * Get default profile when not created yet
     */
    private function getDefaultProfile(): array
    {
        return [
            'available' => true,
            'status' => 'not_saved',
            'status_label' => 'Not saved',
            'needs_attention' => false,
            'guidance' => 'Add your research interests and scholarly links, then save your profile.',
            'updated_at' => null,
            'quick_edits' => [
                [
                    'label' => 'Research Interests',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#interests',
                    'icon' => '📝',
                    'has_value' => false,
                ],
                [
                    'label' => 'ORCID',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#orcid',
                    'icon' => '🔗',
                    'has_value' => false,
                ],
                [
                    'label' => 'Google Scholar',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#scholar',
                    'icon' => '🎓',
                    'has_value' => false,
                ],
                [
                    'label' => 'ResearchGate',
                    'url' => SITE_URL . 'pages/faculty/faculty-profile.php?tab=research#researchgate',
                    'icon' => '🔬',
                    'has_value' => false,
                ],
            ],
            'highest_degree' => null,
        ];
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
}
