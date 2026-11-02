CREATE TABLE IF NOT EXISTS `faculty_program_assignments` (
  `faculty_id` int(10) unsigned NOT NULL,
  `program_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`faculty_id`, `program_id`),
  KEY `idx_faculty_program_assignments_program` (`program_id`, `faculty_id`),
  CONSTRAINT `fk_faculty_program_assignments_faculty`
    FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_faculty_program_assignments_program`
    FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `faculty_program_assignments` (`faculty_id`, `program_id`)
SELECT u.`user_id`, p.`program_id`
FROM `users` u
JOIN `programs` p
  ON p.`status` = 1
 AND p.`program_code` IN ('BSIT', 'BSCS')
 AND (
      UPPER(TRIM(u.`program`)) = UPPER(p.`program_code`)
      OR UPPER(TRIM(u.`program`)) = UPPER(TRIM(p.`program_name`))
      OR FIND_IN_SET(p.`program_code`, REPLACE(UPPER(TRIM(u.`program`)), ' ', '')) > 0
 )
WHERE u.`role` = 'faculty'
  AND u.`deleted_at` IS NULL
  AND NULLIF(TRIM(u.`program`), '') IS NOT NULL;
