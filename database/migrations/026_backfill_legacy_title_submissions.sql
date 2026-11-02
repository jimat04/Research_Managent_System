-- Backfill the two known legacy title approvals into the versioned submission
-- history. research_projects.created_at is used as an explicitly approximate
-- legacy submission date because the original submission timestamp is unknown.
-- Original reviewer details are unavailable; this migration creates no review
-- decision or reviewer identity.

INSERT INTO `research_submission_versions` (
  `project_id`,
  `chapter_id`,
  `submission_type`,
  `version_number`,
  `submitted_by`,
  `submitted_at`,
  `status`,
  `snapshot_json`
)
SELECT
  rp.`project_id`,
  NULL,
  'title',
  1,
  rp.`created_by`,
  rp.`created_at`,
  'approved',
  JSON_OBJECT(
    'title', rp.`title`,
    'legacy_import', JSON_OBJECT(
      'imported', TRUE,
      'origin', 'legacy_title_submission_backfill',
      'approximate_project_created_at', rp.`created_at`,
      'date_basis', 'research_projects.created_at',
      'original_submission_details',
        'Unavailable in the legacy data; research_projects.created_at is used as an explicitly approximate submission date.',
      'original_reviewer_details',
        'Unavailable in the legacy data; no reviewer decision or identity has been created.'
    )
  )
FROM `research_projects` rp
WHERE (
       (rp.`project_id` = 1
        AND rp.`title` = 'The Effects of Online Learning on Student Performance')
    OR (rp.`project_id` = 2
        AND rp.`title` = 'The Awareness of Cybersecurity Practices Among College Students')
  )
  AND rp.`deleted_at` IS NULL
  AND rp.`status` = 'ongoing'
  AND rp.`created_by` IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM `research_submission_versions` existing_version
    WHERE existing_version.`project_id` = rp.`project_id`
      AND existing_version.`submission_type` = 'title'
  );
