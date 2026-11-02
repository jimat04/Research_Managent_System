CREATE TABLE IF NOT EXISTS `research_submission_annotations` (
  `annotation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned NOT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `selected_text` text DEFAULT NULL,
  `anchor_json` longtext NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`annotation_id`),
  KEY `idx_submission_annotations_version_upload` (`version_id`,`upload_id`,`created_at`),
  KEY `idx_submission_annotations_reviewer` (`reviewer_id`,`created_at`),
  KEY `idx_submission_annotations_updated_by` (`updated_by`),
  CONSTRAINT `fk_submission_annotations_version_upload` FOREIGN KEY (`version_id`,`upload_id`) REFERENCES `research_submission_version_uploads` (`version_id`,`upload_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_annotations_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_submission_annotations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
