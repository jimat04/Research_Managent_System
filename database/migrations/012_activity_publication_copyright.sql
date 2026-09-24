-- Migration 012: Research activities, publications, and copyright management
--
-- Module structure:
--   * research_activities stores seminars, presentations, workshops, and forums.
--   * activity_participants records named or system-user participants per activity.
--   * publications tracks faculty and student publication outputs and files.
--   * copyright_applications tracks copyrightable outputs through registration.
-- User identifiers are indexed soft references: this module intentionally creates
-- no foreign keys to users so records can also represent external contributors.
-- Safe to re-run because every table uses CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `research_activities` (
  `activity_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_type` enum('seminar','presentation','workshop','forum') NOT NULL,
  `title` varchar(255) NOT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `speaker` varchar(255) DEFAULT NULL,
  `organizer` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_participants` (
  `participant_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `participant_name` varchar(255) DEFAULT NULL,
  `role` varchar(120) DEFAULT NULL COMMENT 'speaker/attendee/organizer',
  `attended` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`participant_id`),
  KEY `idx_activity_participants_activity_id` (`activity_id`),
  KEY `idx_activity_participants_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publications` (
  `publication_id` int unsigned NOT NULL AUTO_INCREMENT,
  `research_title` varchar(500) NOT NULL,
  `researcher_id` int unsigned DEFAULT NULL,
  `researcher_name` varchar(255) DEFAULT NULL COMMENT 'Denormalized for non-user authors',
  `researcher_category` enum('faculty','student') NOT NULL DEFAULT 'faculty',
  `publication_type` enum('journal','conference','book_chapter','other') NOT NULL DEFAULT 'journal',
  `journal_publisher` varchar(255) DEFAULT NULL,
  `publication_date` date DEFAULT NULL,
  `doi_identifier` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `status` enum('submitted','under_review','accepted','published') NOT NULL DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`publication_id`),
  KEY `idx_publications_researcher_id` (`researcher_id`),
  KEY `idx_publications_status` (`status`),
  KEY `idx_publications_publication_date` (`publication_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `copyright_applications` (
  `copyright_id` int unsigned NOT NULL AUTO_INCREMENT,
  `applicant_id` int unsigned DEFAULT NULL,
  `applicant_name` varchar(255) DEFAULT NULL,
  `applicant_category` enum('faculty','student') NOT NULL DEFAULT 'faculty',
  `output_title` varchar(500) NOT NULL,
  `output_type` enum('software','research','instructional_material','module','other') NOT NULL DEFAULT 'research',
  `co_authors` varchar(500) DEFAULT NULL,
  `date_completed` date DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `copyright_ref_no` varchar(120) DEFAULT NULL,
  `status` enum('pending','under_review','registered','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`copyright_id`),
  KEY `idx_copyright_applications_applicant_id` (`applicant_id`),
  KEY `idx_copyright_applications_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
