-- Role-scoped academic affiliation catalog and relationships.
-- Food Technology is intentionally stored here only, not in `programs`.

CREATE TABLE `academic_affiliation_groups` (
  `group_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_code` varchar(40) NOT NULL,
  `group_name` varchar(150) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `uq_academic_affiliation_groups_code` (`group_code`),
  UNIQUE KEY `uq_academic_affiliation_groups_name` (`group_name`),
  KEY `idx_academic_affiliation_groups_status_name` (`status`, `group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `academic_affiliation_programs` (
  `affiliation_program_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int(10) unsigned DEFAULT NULL,
  `program_code` varchar(20) DEFAULT NULL,
  `program_name` varchar(150) NOT NULL,
  `is_specialization` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`affiliation_program_id`),
  UNIQUE KEY `uq_academic_affiliation_programs_program` (`program_id`),
  KEY `idx_academic_affiliation_programs_status_name` (`status`, `program_name`),
  CONSTRAINT `fk_academic_affiliation_programs_program`
    FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `academic_group_programs` (
  `group_id` int(10) unsigned NOT NULL,
  `affiliation_program_id` int(10) unsigned NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_id`, `affiliation_program_id`),
  KEY `idx_academic_group_programs_program` (`affiliation_program_id`, `status`),
  CONSTRAINT `fk_academic_group_programs_group`
    FOREIGN KEY (`group_id`) REFERENCES `academic_affiliation_groups` (`group_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_academic_group_programs_program`
    FOREIGN KEY (`affiliation_program_id`) REFERENCES `academic_affiliation_programs` (`affiliation_program_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_academic_affiliations` (
  `affiliation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`affiliation_id`),
  UNIQUE KEY `uq_user_academic_affiliations_user_group` (`user_id`, `group_id`),
  UNIQUE KEY `uq_user_academic_affiliations_id_group` (`affiliation_id`, `group_id`),
  KEY `idx_user_academic_affiliations_group_user` (`group_id`, `user_id`),
  CONSTRAINT `fk_user_academic_affiliations_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_academic_affiliations_group`
    FOREIGN KEY (`group_id`) REFERENCES `academic_affiliation_groups` (`group_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_academic_program_assignments` (
  `affiliation_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `affiliation_program_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`affiliation_id`, `affiliation_program_id`),
  KEY `idx_user_academic_program_assignments_mapping` (`group_id`, `affiliation_program_id`),
  CONSTRAINT `fk_user_academic_program_assignments_affiliation`
    FOREIGN KEY (`affiliation_id`, `group_id`) REFERENCES `user_academic_affiliations` (`affiliation_id`, `group_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_academic_program_assignments_mapping`
    FOREIGN KEY (`group_id`, `affiliation_program_id`) REFERENCES `academic_group_programs` (`group_id`, `affiliation_program_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_affiliation_groups` (`group_code`, `group_name`) VALUES
  ('computer-studies', 'Computer Studies'),
  ('business-office-administration', 'Business and Office Administration'),
  ('criminology', 'Criminology'),
  ('hospitality-management', 'Hospitality Management'),
  ('industrial-technology', 'Industrial Technology'),
  ('teacher-education', 'Teacher Education'),
  ('psychology', 'Psychology'),
  ('general-education', 'General Education'),
  ('graduate-studies', 'Graduate Studies');

INSERT IGNORE INTO `academic_affiliation_programs` (`program_id`, `program_code`, `program_name`)
SELECT `program_id`, `program_code`, `program_name` FROM `programs`;

INSERT INTO `academic_affiliation_programs` (`program_id`, `program_code`, `program_name`, `is_specialization`)
SELECT NULL, NULL, 'Food Technology', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `academic_affiliation_programs`
    WHERE `program_name` = 'Food Technology' AND `is_specialization` = 1
 );

INSERT IGNORE INTO `academic_group_programs` (`group_id`, `affiliation_program_id`)
SELECT g.`group_id`, ap.`affiliation_program_id`
  FROM `academic_affiliation_groups` g
  JOIN `academic_affiliation_programs` ap
  JOIN `programs` p ON p.`program_id` = ap.`program_id`
 WHERE (g.`group_code` = 'computer-studies' AND p.`program_code` IN ('BSIT', 'BSCS'))
    OR (g.`group_code` = 'business-office-administration' AND p.`program_code` IN ('BSBA-MKTG', 'BSBA-HRDM', 'BSBA-FM', 'BSOA'))
    OR (g.`group_code` = 'criminology' AND p.`program_code` = 'BSCrim')
    OR (g.`group_code` = 'hospitality-management' AND p.`program_code` = 'BSHM')
    OR (g.`group_code` = 'industrial-technology' AND (p.`program_code` IN ('BIT-Auto', 'BIT-Electrical', 'BIT-Electronics', 'BIT-DM') OR LOWER(p.`program_name`) = 'food trades'))
    OR (g.`group_code` = 'teacher-education' AND p.`program_code` = 'BTLEd')
    OR (g.`group_code` = 'psychology' AND p.`program_code` = 'BSP');

INSERT IGNORE INTO `academic_group_programs` (`group_id`, `affiliation_program_id`)
SELECT g.`group_id`, ap.`affiliation_program_id`
  FROM `academic_affiliation_groups` g
  JOIN `academic_affiliation_programs` ap ON ap.`program_name` = 'Food Technology'
 WHERE g.`group_code` = 'industrial-technology' AND ap.`is_specialization` = 1;

-- Preserve valid current Faculty program relationships by copying only assignments
-- whose program is explicitly mapped to an academic group. No user profile fields
-- or student records are inferred or rewritten.
INSERT IGNORE INTO `user_academic_affiliations` (`user_id`, `group_id`, `is_primary`)
SELECT u.`user_id`, gp.`group_id`,
       MAX(CASE WHEN UPPER(TRIM(u.`program`)) IN (UPPER(ap.`program_code`), UPPER(ap.`program_name`)) THEN 1 ELSE 0 END)
  FROM `users` u
  JOIN `faculty_program_assignments` fpa ON fpa.`faculty_id` = u.`user_id`
  JOIN `academic_affiliation_programs` ap ON ap.`program_id` = fpa.`program_id`
  JOIN `academic_group_programs` gp ON gp.`affiliation_program_id` = ap.`affiliation_program_id` AND gp.`status` = 1
 WHERE u.`role` = 'faculty' AND u.`deleted_at` IS NULL
 GROUP BY u.`user_id`, gp.`group_id`;

INSERT IGNORE INTO `user_academic_program_assignments` (`affiliation_id`, `group_id`, `affiliation_program_id`)
SELECT ua.`affiliation_id`, ua.`group_id`, gp.`affiliation_program_id`
  FROM `user_academic_affiliations` ua
  JOIN `faculty_program_assignments` fpa ON fpa.`faculty_id` = ua.`user_id`
  JOIN `academic_affiliation_programs` ap ON ap.`program_id` = fpa.`program_id`
  JOIN `academic_group_programs` gp ON gp.`group_id` = ua.`group_id` AND gp.`affiliation_program_id` = ap.`affiliation_program_id`
 WHERE ua.`user_id` IN (SELECT `user_id` FROM `users` WHERE `role` = 'faculty' AND `deleted_at` IS NULL);

-- Safe rollback only before any affiliation data has been entered. If data exists,
-- export it first; do not drop these tables as a routine rollback.
