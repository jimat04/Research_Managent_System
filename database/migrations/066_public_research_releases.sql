CREATE TABLE IF NOT EXISTS `public_research_releases` (
  `release_id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_type` enum('student_project','faculty_publication','faculty_research_record') NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `publication_id` int unsigned DEFAULT NULL,
  `faculty_research_record_id` int unsigned DEFAULT NULL,
  `release_status` enum('published','withdrawn') NOT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `public_manuscript_version_id` int unsigned DEFAULT NULL,
  `publication_paper_released` tinyint(1) NOT NULL DEFAULT 0,
  `publication_paper_path` varchar(500) DEFAULT NULL,
  `publication_paper_name` varchar(255) DEFAULT NULL,
  `file_reviewed_by` int unsigned DEFAULT NULL,
  `file_reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`release_id`),
  UNIQUE KEY `uq_public_release_project` (`project_id`),
  UNIQUE KEY `uq_public_release_publication` (`publication_id`),
  UNIQUE KEY `uq_public_release_faculty_record` (`faculty_research_record_id`),
  KEY `idx_public_release_status_type` (`release_status`,`source_type`),
  CONSTRAINT `fk_public_release_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_public_release_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_public_release_faculty_record` FOREIGN KEY (`faculty_research_record_id`) REFERENCES `faculty_research_records` (`research_record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_public_release_manuscript_version` FOREIGN KEY (`public_manuscript_version_id`) REFERENCES `research_submission_versions` (`version_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_public_release_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_public_release_file_reviewed_by` FOREIGN KEY (`file_reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_public_release_source` CHECK (
    (`source_type` = 'student_project' AND `project_id` IS NOT NULL AND `publication_id` IS NULL AND `faculty_research_record_id` IS NULL AND `publication_paper_released` = 0 AND `publication_paper_path` IS NULL)
    OR (`source_type` = 'faculty_publication' AND `project_id` IS NULL AND `publication_id` IS NOT NULL AND `faculty_research_record_id` IS NULL AND `public_manuscript_version_id` IS NULL AND ((`publication_paper_released` = 1 AND `publication_paper_path` IS NOT NULL) OR (`publication_paper_released` = 0 AND `publication_paper_path` IS NULL)))
    OR (`source_type` = 'faculty_research_record' AND `project_id` IS NULL AND `publication_id` IS NULL AND `faculty_research_record_id` IS NOT NULL AND `public_manuscript_version_id` IS NULL AND `publication_paper_released` = 0 AND `publication_paper_path` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
