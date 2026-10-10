-- Preserve each submitted title/chapter version and its review decisions.
-- Current editable content remains in research_projects / chapter_content.

CREATE TABLE IF NOT EXISTS `research_submission_versions` (
  `version_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `chapter_id` int(10) unsigned DEFAULT NULL,
  `submission_type` enum('title','chapter') NOT NULL,
  `version_number` smallint(5) unsigned NOT NULL,
  `submitted_by` int(10) unsigned NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  `snapshot_json` longtext NOT NULL,
  PRIMARY KEY (`version_id`),
  KEY `idx_submission_versions_project` (`project_id`,`submission_type`,`submitted_at`),
  KEY `idx_submission_versions_chapter` (`chapter_id`,`version_number`),
  CONSTRAINT `fk_submission_versions_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_versions_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_versions_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research_submission_reviews` (
  `review_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version_id` int(10) unsigned NOT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `decision` enum('approved','returned') NOT NULL,
  `comments` text NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  KEY `idx_submission_reviews_version` (`version_id`,`reviewed_at`),
  KEY `idx_submission_reviews_reviewer` (`reviewer_id`,`reviewed_at`),
  CONSTRAINT `fk_submission_reviews_version` FOREIGN KEY (`version_id`) REFERENCES `research_submission_versions` (`version_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research_submission_version_uploads` (
  `version_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`version_id`,`upload_id`),
  KEY `idx_submission_version_uploads_upload` (`upload_id`),
  CONSTRAINT `fk_submission_version_uploads_version` FOREIGN KEY (`version_id`) REFERENCES `research_submission_versions` (`version_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_version_uploads_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
