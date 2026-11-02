ALTER TABLE `uploads`
  MODIFY COLUMN `project_id` int(10) unsigned DEFAULT NULL,
  MODIFY COLUMN `type` enum('proposal','chapter','defense','revision','manuscript','other','engagement') NOT NULL DEFAULT 'other';

CREATE TABLE IF NOT EXISTS `faculty_research_profiles` (
  `user_id` int(10) unsigned NOT NULL,
  `highest_degree` varchar(150) DEFAULT NULL,
  `research_interests` text DEFAULT NULL,
  `orcid_url` varchar(255) DEFAULT NULL,
  `google_scholar_url` varchar(500) DEFAULT NULL,
  `researchgate_url` varchar(500) DEFAULT NULL,
  `profile_status` enum('draft','submitted','verified','returned') NOT NULL DEFAULT 'draft',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `review_comments` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_faculty_research_profiles_status` (`profile_status`),
  KEY `idx_faculty_research_profiles_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_faculty_research_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_faculty_research_profiles_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faculty_engagement_records` (
  `engagement_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faculty_id` int(10) unsigned NOT NULL,
  `engagement_type` enum('seminar_training','conference_presentation','extension_community','research_award','collaboration','other') NOT NULL,
  `title` varchar(255) NOT NULL,
  `participation_role` enum('participant','presenter','organizer','adviser','team_lead') NOT NULL,
  `activity_date` date NOT NULL,
  `organizer` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `research_project_id` int(10) unsigned DEFAULT NULL,
  `evidence_upload_id` int(10) unsigned DEFAULT NULL,
  `review_status` enum('pending','verified','returned') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `review_comments` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`engagement_id`),
  UNIQUE KEY `uq_faculty_engagement_evidence` (`evidence_upload_id`),
  KEY `idx_faculty_engagement_faculty_status` (`faculty_id`,`review_status`),
  KEY `idx_faculty_engagement_date` (`activity_date`),
  KEY `idx_faculty_engagement_project` (`research_project_id`),
  KEY `idx_faculty_engagement_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_faculty_engagement_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_faculty_engagement_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_faculty_engagement_upload` FOREIGN KEY (`evidence_upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_faculty_engagement_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
