CREATE TABLE IF NOT EXISTS `student_project_assignments` (
  `student_id` int(10) unsigned NOT NULL,
  `project_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`student_id`),
  KEY `idx_student_project_assignments_project` (`project_id`),
  CONSTRAINT `fk_student_project_assignments_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_project_assignments_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE `tmp_student_project_candidates` ENGINE=InnoDB AS
SELECT candidate.`user_id`, candidate.`project_id`, MIN(candidate.`assigned_at`) AS `assigned_at`
  FROM (
    SELECT pm.`user_id`, pm.`project_id`, pm.`created_at` AS `assigned_at`
      FROM `project_members` pm
      JOIN `users` u ON u.`user_id` = pm.`user_id` AND u.`role` = 'student'
      JOIN `research_projects` rp ON rp.`project_id` = pm.`project_id` AND rp.`deleted_at` IS NULL
    UNION ALL
    SELECT rp.`created_by`, rp.`project_id`, rp.`created_at`
      FROM `research_projects` rp
      JOIN `users` u ON u.`user_id` = rp.`created_by` AND u.`role` = 'student'
     WHERE rp.`deleted_at` IS NULL
    UNION ALL
    SELECT rp.`student_id`, rp.`project_id`, rp.`created_at`
      FROM `research_projects` rp
      JOIN `users` u ON u.`user_id` = rp.`student_id` AND u.`role` = 'student'
     WHERE rp.`deleted_at` IS NULL
  ) candidate
 GROUP BY candidate.`user_id`, candidate.`project_id`;

CREATE TEMPORARY TABLE `tmp_student_project_rank` ENGINE=InnoDB AS
SELECT candidate.`user_id`, candidate.`project_id`, candidate.`assigned_at`,
       CASE WHEN EXISTS (
         SELECT 1
           FROM `research_submission_versions` final_version
          WHERE final_version.`project_id` = candidate.`project_id`
            AND final_version.`submission_type` = 'final_manuscript'
            AND final_version.`status` = 'approved'
            AND NOT EXISTS (
              SELECT 1
                FROM `research_submission_versions` newer_final
               WHERE newer_final.`project_id` = final_version.`project_id`
                 AND newer_final.`submission_type` = 'final_manuscript'
                 AND (newer_final.`version_number` > final_version.`version_number`
                   OR (newer_final.`version_number` = final_version.`version_number` AND newer_final.`version_id` > final_version.`version_id`))
            )
       ) THEN 1 ELSE 0 END AS `approved_final`,
       (SELECT COUNT(DISTINCT ch.`chapter_id`)
          FROM `chapters` ch
         WHERE ch.`project_id` = candidate.`project_id`
           AND ch.`status` = 'approved'
           AND ch.`deleted_at` IS NULL) AS `approved_chapters`,
       CASE WHEN rp.`status` IN ('title_approved','approved','ongoing','progress_report','terminal_review','completed')
              OR EXISTS (
                SELECT 1
                  FROM `research_submission_versions` title_version
                 WHERE title_version.`project_id` = candidate.`project_id`
                   AND title_version.`submission_type` = 'title'
                   AND title_version.`status` = 'approved'
                   AND NOT EXISTS (
                     SELECT 1
                       FROM `research_submission_versions` newer_title
                      WHERE newer_title.`project_id` = title_version.`project_id`
                        AND newer_title.`submission_type` = 'title'
                        AND (newer_title.`version_number` > title_version.`version_number`
                          OR (newer_title.`version_number` = title_version.`version_number` AND newer_title.`version_id` > title_version.`version_id`))
                   )
              ) THEN 1 ELSE 0 END AS `approved_title`
  FROM `tmp_student_project_candidates` candidate
  JOIN `research_projects` rp ON rp.`project_id` = candidate.`project_id`;

INSERT INTO `student_project_assignments` (`student_id`, `project_id`, `assigned_at`)
SELECT candidate.`user_id`, candidate.`project_id`, candidate.`assigned_at`
  FROM `tmp_student_project_rank` candidate
  LEFT JOIN `tmp_student_project_rank` better
    ON better.`user_id` = candidate.`user_id`
   AND (
        better.`approved_final` > candidate.`approved_final`
     OR (better.`approved_final` = candidate.`approved_final` AND better.`approved_chapters` > candidate.`approved_chapters`)
     OR (better.`approved_final` = candidate.`approved_final` AND better.`approved_chapters` = candidate.`approved_chapters` AND better.`approved_title` > candidate.`approved_title`)
     OR (better.`approved_final` = candidate.`approved_final` AND better.`approved_chapters` = candidate.`approved_chapters` AND better.`approved_title` = candidate.`approved_title` AND better.`assigned_at` < candidate.`assigned_at`)
     OR (better.`approved_final` = candidate.`approved_final` AND better.`approved_chapters` = candidate.`approved_chapters` AND better.`approved_title` = candidate.`approved_title` AND better.`assigned_at` = candidate.`assigned_at` AND better.`project_id` < candidate.`project_id`)
   )
 WHERE better.`user_id` IS NULL
ON DUPLICATE KEY UPDATE `project_id` = VALUES(`project_id`), `assigned_at` = VALUES(`assigned_at`);

INSERT INTO `project_members` (`project_id`, `user_id`, `role`)
SELECT assignment.`project_id`, assignment.`student_id`,
       CASE WHEN rp.`created_by` = assignment.`student_id` OR rp.`student_id` = assignment.`student_id` THEN 'lead' ELSE 'member' END
  FROM `student_project_assignments` assignment
  JOIN `research_projects` rp ON rp.`project_id` = assignment.`project_id` AND rp.`deleted_at` IS NULL
  LEFT JOIN `project_members` pm ON pm.`project_id` = assignment.`project_id` AND pm.`user_id` = assignment.`student_id`
 WHERE pm.`project_id` IS NULL;

DELETE pm
  FROM `project_members` pm
  JOIN `users` u ON u.`user_id` = pm.`user_id` AND u.`role` = 'student'
  JOIN `research_projects` rp ON rp.`project_id` = pm.`project_id` AND rp.`deleted_at` IS NULL
  LEFT JOIN `student_project_assignments` assignment
    ON assignment.`student_id` = pm.`user_id` AND assignment.`project_id` = pm.`project_id`
 WHERE assignment.`student_id` IS NULL;

DROP TEMPORARY TABLE `tmp_student_project_rank`;
DROP TEMPORARY TABLE `tmp_student_project_candidates`;
