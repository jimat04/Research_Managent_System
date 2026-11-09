-- Canonical fresh-install schema, including activity budget changes through
-- migration 036. For LEGACY databases use numbered migrations.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `academic_years`;
DROP TABLE IF EXISTS `research_project_title_classifications`;
DROP TABLE IF EXISTS `activity_budget_notice_deliveries`;
DROP TABLE IF EXISTS `activity_budget_notices`;
DROP TABLE IF EXISTS `activity_contribution_responses`;
DROP TABLE IF EXISTS `activity_budget_revisions`;
DROP TABLE IF EXISTS `activity_budget_expenses`;
DROP TABLE IF EXISTS `activity_budgets`;
DROP TABLE IF EXISTS `publication_contributors`;
DROP TABLE IF EXISTS `copyright_contributors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_years` (
  `ay_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(20) NOT NULL COMMENT 'e.g. 2024-2025',
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ay_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  details longtext DEFAULT NULL,
  `log_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `module` varchar(80) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_activity_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=935 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chapter_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chapter_content` (
  `content_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chapter_id` int(10) unsigned NOT NULL,
  `background` longtext DEFAULT NULL,
  `problem_statement` longtext DEFAULT NULL,
  `objectives` longtext DEFAULT NULL,
  `scope` longtext DEFAULT NULL,
  `significance` longtext DEFAULT NULL,
  `definition_terms` longtext DEFAULT NULL,
  `local_literature` longtext DEFAULT NULL,
  `foreign_literature` longtext DEFAULT NULL,
  `related_studies` longtext DEFAULT NULL,
  `theoretical_fw` longtext DEFAULT NULL,
  `conceptual_fw` longtext DEFAULT NULL,
  `research_design` longtext DEFAULT NULL,
  `respondents` longtext DEFAULT NULL,
  `instruments` longtext DEFAULT NULL,
  `data_gathering` longtext DEFAULT NULL,
  `statistical` longtext DEFAULT NULL,
  `findings` longtext DEFAULT NULL,
  `analysis` longtext DEFAULT NULL,
  `summary_text` longtext DEFAULT NULL,
  `conclusions` longtext DEFAULT NULL,
  `recommendations` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`content_id`),
  UNIQUE KEY `chapter_id` (`chapter_id`),
  CONSTRAINT `fk_chapter_content_chapter_chapters` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chapters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chapters` (
  `chapter_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `chapter_number` tinyint(3) unsigned NOT NULL COMMENT '1-5',
  `chapter_title` varchar(200) NOT NULL,
  `status` enum('draft','submitted','under_review','revision_required','revised','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `version` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `research_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`chapter_id`),
  UNIQUE KEY `uk_proj_chap` (`project_id`,`chapter_number`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_chapters_research_status` (`research_id`,`status`),
  KEY `idx_chapters_created_at` (`created_at`),
  KEY `idx_chapters_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_chapters_project_projects` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chapters_research_projects` FOREIGN KEY (`research_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comments` (
  `comment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chapter_id` int(10) unsigned DEFAULT NULL,
  `project_id` int(10) unsigned DEFAULT NULL,
  `faculty_id` int(10) unsigned DEFAULT NULL,
  `comment` text NOT NULL,
  `type` enum('general','suggestion','correction','approval') NOT NULL DEFAULT 'general',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`comment_id`),
  KEY `chapter_id` (`chapter_id`),
  KEY `faculty_id` (`faculty_id`),
  KEY `idx_comments_user_created` (`user_id`,`created_at`),
  KEY `idx_comments_parent` (`parent_id`),
  KEY `idx_comments_deleted_at` (`deleted_at`),
  KEY `idx_comments_project_id` (`project_id`),
  CONSTRAINT `fk_comments_chapter_chapters` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_faculty_users` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_parent_comments` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`comment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `contact_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `email` varchar(160) NOT NULL,
  `concern_type` varchar(80) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','resolved','archived') NOT NULL DEFAULT 'pending',
  `resolved_by` int(10) unsigned DEFAULT NULL COMMENT 'user_id who resolved it',
  `resolved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'internal notes by staff',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`contact_id`),
  KEY `idx_status` (`status`),
  KEY `idx_concern_type` (`concern_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_contact_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(160) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `status` enum('applied','failed') NOT NULL DEFAULT 'applied',
  `verification_method` enum('executed','verified_existing') DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `execution_ms` decimal(12,3) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`migration_key`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`),
  KEY `idx_schema_migrations_status` (`status`),
  KEY `idx_schema_migrations_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `application_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `idx_application_settings_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `defense_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `defense_schedule` (
  `defense_id` int(10) unsigned NOT NULL,
  `project_id` int(10) unsigned NOT NULL,
  `schedule_date` datetime NOT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `type` enum('proposal','pre_oral','final') NOT NULL DEFAULT 'final',
  `status` enum('scheduled','done','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
  `remarks` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `research_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`defense_id`),
  KEY `project_id` (`project_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_defense_research_status` (`research_id`,`status`),
  KEY `idx_defense_created_at` (`created_at`),
  CONSTRAINT `fk_defense_schedule_research_projects` FOREIGN KEY (`research_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `dept_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dept_code` varchar(20) NOT NULL,
  `dept_name` varchar(150) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dept_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `history_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned DEFAULT NULL,
  `message_id` int(10) unsigned NOT NULL DEFAULT 0,
  `sender_id` int(10) unsigned NOT NULL,
  `recipient_id` int(10) unsigned NOT NULL,
  `subject` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_sender_system` (`sender_id`),
  KEY `idx_messages_thread_created` (`thread_id`,`created_at`,`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `activity_budget_delivery_key` char(64) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uq_notifications_budget_delivery` (`activity_budget_delivery_key`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_notifications_read_at` (`user_id`,`read_at`),
  KEY `idx_notifications_created_at` (`created_at`),
  CONSTRAINT `fk_notifications_user_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `programs` (
  `program_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dept_id` int(10) unsigned NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(150) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`program_id`),
  KEY `dept_id` (`dept_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_advisers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_advisers` (
  `project_adviser_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `adviser_id` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`project_adviser_id`),
  UNIQUE KEY `uk_proj_adv` (`project_id`,`adviser_id`),
  KEY `adviser_id` (`adviser_id`),
  CONSTRAINT `fk_project_advisers_adviser_users` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_advisers_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_advisers_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `adviser_id` int(10) unsigned NOT NULL,
  `role` varchar(60) DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  `removed_at` datetime NOT NULL,
  `removed_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pah_project` (`project_id`),
  KEY `idx_pah_adviser` (`adviser_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_members` (
  `project_member_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('lead','member') NOT NULL DEFAULT 'member',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`project_member_id`),
  UNIQUE KEY `uk_proj_user` (`project_id`,`user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_reviews` (
  `review_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `reviewer_id` int(10) unsigned NOT NULL COMMENT 'faculty users.user_id',
  `review_level` enum('crec','erec') NOT NULL DEFAULT 'crec',
  `methodology_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-20 (soundness of methodology)',
  `contribution_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-20 (contribution to knowledge)',
  `applicability_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-30 (applicability/marketability)',
  `capability_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-10 (capability of proponent to carry out research project)',
  `agenda_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-10 (alignment with college research agenda)',
  `thrusts_score` tinyint(3) unsigned DEFAULT NULL COMMENT '0-10 (conformity to national research thrusts: DOST/CHED)',
  `comments` text DEFAULT NULL,
  `recommendation` enum('approve','revise','reject') DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uk_project_reviewer_level` (`project_id`,`reviewer_id`,`review_level`),
  KEY `idx_project` (`project_id`),
  KEY `idx_reviewer` (`reviewer_id`),
  CONSTRAINT `fk_reviews_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_author_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_author_snapshots` (
  `author_snapshot_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `author_name` varchar(170) NOT NULL,
  `program` varchar(120) DEFAULT NULL,
  `author_role` enum('lead','member') NOT NULL DEFAULT 'member',
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`author_snapshot_id`),
  UNIQUE KEY `uk_research_author_snapshot` (`project_id`,`user_id`),
  KEY `idx_research_author_program` (`program`),
  KEY `idx_research_author_user` (`user_id`),
  CONSTRAINT `fk_research_author_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_author_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_categories` (
  `category_id` int(10) unsigned NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_category_programs`;
CREATE TABLE `research_category_programs` (
  `category_id` int(10) unsigned NOT NULL,
  `program_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`category_id`,`program_id`),
  KEY `idx_research_category_programs_program` (`program_id`),
  CONSTRAINT `fk_research_category_programs_category`
    FOREIGN KEY (`category_id`) REFERENCES `research_categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_category_programs_program`
    FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `research_project_areas`;
DROP TABLE IF EXISTS `research_area_selection_scope`;
DROP TABLE IF EXISTS `category_research_areas`;
DROP TABLE IF EXISTS `research_areas`;
CREATE TABLE `research_areas` (
  `area_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `area_code` varchar(8) NOT NULL,
  `area_name` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`area_id`),
  UNIQUE KEY `uq_research_areas_code` (`area_code`),
  UNIQUE KEY `uq_research_areas_name` (`area_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `category_research_areas` (
  `category_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`category_id`,`area_id`),
  KEY `idx_category_research_areas_area` (`area_id`),
  CONSTRAINT `fk_category_research_areas_category`
    FOREIGN KEY (`category_id`) REFERENCES `research_categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_category_research_areas_area`
    FOREIGN KEY (`area_id`) REFERENCES `research_areas` (`area_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `research_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_documents` (
  `document_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned DEFAULT NULL,
  `document_type` enum('proposal','revision_checklist','defense_material','mou','nda','progress_report','terminal_report','final_bound_report','publication_record','other') NOT NULL DEFAULT 'other',
  `status` enum('pending','submitted','approved','rejected','waived') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `submitted_by` int(10) unsigned DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`document_id`),
  KEY `idx_research_documents_project_type` (`project_id`,`document_type`),
  KEY `idx_research_documents_status` (`status`),
  KEY `idx_research_documents_upload` (`upload_id`),
  KEY `idx_research_documents_submitted_by` (`submitted_by`),
  KEY `idx_research_documents_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_research_documents_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_documents_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_research_documents_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_research_documents_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_projects` (
  `project_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `title_option_1` varchar(255) DEFAULT NULL,
  `title_option_2` varchar(255) DEFAULT NULL,
  `title_option_3` varchar(255) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `ay_id` int(10) unsigned DEFAULT NULL,
  `research_area` varchar(150) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `status` enum('draft','proposal','submitted','under_review','under_crec_review','under_erec_review','for_revision','revision_required','rejected','title_approved','approved','ongoing','progress_report','terminal_review','completed','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL COMMENT 'student user_id',
  `student_id` int(10) unsigned DEFAULT NULL,
  `adviser_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`project_id`),
  KEY `category_id` (`category_id`),
  KEY `ay_id` (`ay_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_projects_student_status` (`student_id`,`status`),
  KEY `idx_projects_adviser_status` (`adviser_id`,`status`),
  KEY `idx_projects_created_at` (`created_at`),
  KEY `idx_projects_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_research_projects_adviser_users` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_research_projects_created_by_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_research_projects_student_users` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
CREATE TABLE `research_project_areas` (
  `project_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `display_order` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`project_id`,`area_id`),
  UNIQUE KEY `uq_research_project_area_order` (`project_id`,`display_order`),
  KEY `idx_research_project_areas_area` (`area_id`),
  CONSTRAINT `fk_research_project_areas_project`
    FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_project_areas_area`
    FOREIGN KEY (`area_id`) REFERENCES `research_areas` (`area_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `research_project_title_classifications` (
  `project_id` int(10) unsigned NOT NULL,
  `title_option_number` tinyint(3) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`project_id`,`title_option_number`),
  KEY `idx_research_title_classifications_category` (`category_id`),
  KEY `idx_research_title_classifications_area` (`area_id`),
  CONSTRAINT `fk_research_title_classifications_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_title_classifications_category` FOREIGN KEY (`category_id`) REFERENCES `research_categories` (`category_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_research_title_classifications_area` FOREIGN KEY (`area_id`) REFERENCES `research_areas` (`area_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_research_title_classifications_option` CHECK (`title_option_number` BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `research_publication_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_publication_tracking` (
  `publication_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `colloquium_date` datetime DEFAULT NULL,
  `colloquium_status` enum('not_scheduled','scheduled','presented','cancelled') NOT NULL DEFAULT 'not_scheduled',
  `journal_status` enum('not_submitted','submitted','under_review','accepted','published','rejected') NOT NULL DEFAULT 'not_submitted',
  `journal_reference` varchar(255) DEFAULT NULL,
  `archive_status` enum('not_archived','ready','archived') NOT NULL DEFAULT 'not_archived',
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`publication_id`),
  UNIQUE KEY `uq_publication_project` (`project_id`),
  KEY `idx_publication_colloquium_status` (`colloquium_status`),
  KEY `idx_publication_journal_status` (`journal_status`),
  KEY `idx_publication_archive_status` (`archive_status`),
  CONSTRAINT `fk_publication_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `research_reports` (
  `report_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `report_type` enum('midway_progress','terminal') NOT NULL,
  `status` enum('draft','submitted','under_review','revision_required','approved','rejected') NOT NULL DEFAULT 'draft',
  `summary` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`report_id`),
  KEY `idx_research_reports_project_type` (`project_id`,`report_type`),
  KEY `idx_research_reports_status` (`status`),
  KEY `idx_research_reports_document` (`document_id`),
  KEY `idx_research_reports_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_research_reports_document` FOREIGN KEY (`document_id`) REFERENCES `research_documents` (`document_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_research_reports_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_reports_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uploads` (
  `upload_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned DEFAULT NULL,
  `chapter_id` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `type` enum('proposal','chapter','defense','revision','manuscript','other','engagement') NOT NULL DEFAULT 'other',
  `original_name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `research_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`upload_id`),
  KEY `project_id` (`project_id`),
  KEY `chapter_id` (`chapter_id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_uploads_research_created` (`research_id`,`created_at`),
  KEY `idx_uploads_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_uploads_research_projects` FOREIGN KEY (`research_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uploads_uploaded_by_users` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_uploads_user_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_permissions`;
CREATE TABLE `staff_permissions` (
  `permission_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `subpage_key` varchar(64) NOT NULL DEFAULT '',
  `access_level` enum('none','view','manage') NOT NULL DEFAULT 'none',
  `download_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_staff_permissions_user_module_subpage` (`user_id`,`module_key`,`subpage_key`),
  KEY `idx_staff_permissions_user` (`user_id`),
  KEY `idx_staff_permissions_module` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `login_rate_limits`;
CREATE TABLE `login_rate_limits` (
  `rate_key` char(64) NOT NULL,
  `failures` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`rate_key`),
  KEY `idx_login_rate_limits_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('student','faculty','research_staff','admin') NOT NULL DEFAULT 'student',
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL COMMENT 'Optional faculty middle name or initial',
  `last_name` varchar(80) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL COMMENT 'school ID / employee ID',
  `department` varchar(120) DEFAULT NULL,
  `office` varchar(120) DEFAULT NULL COMMENT 'Office assignment for staff',
  `specialization` varchar(120) DEFAULT NULL COMMENT 'Faculty field of expertise',
  `academic_rank` enum('Instructor','Assistant Professor','Associate Professor','Professor','Dean','Director','College Professor','Instructor I','Instructor II','Instructor III','Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV','Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V','Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI') DEFAULT NULL COMMENT 'Faculty academic rank',
  `designation` varchar(150) DEFAULT NULL COMMENT 'Optional faculty designation',
  `appointment_status` varchar(80) DEFAULT NULL COMMENT 'Optional faculty employment or appointment status; separate from RMS account status',
  `is_reviewer` tinyint(1) DEFAULT 0 COMMENT 'Can participate in CREC/EREC review',
  `program` varchar(120) DEFAULT NULL,
  `year_level` enum('1st','2nd','3rd','4th','Graduate','Masters','Doctorate') DEFAULT NULL COMMENT 'Student year level',
  `contact` varchar(30) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `status` enum('active','pending','suspended') NOT NULL DEFAULT 'pending',
  `email_verified` tinyint(1) DEFAULT 0,
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_expires` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `research_basics_completed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_verification_token` (`email_verification_token`),
  UNIQUE KEY `uq_users_student_id` (`student_id`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_role_status` (`role`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `adviser_request_messages`;
DROP TABLE IF EXISTS `adviser_requests`;
CREATE TABLE `adviser_requests` (
  `request_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `requester_id` int(10) unsigned NOT NULL,
  `faculty_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `decided_at` datetime DEFAULT NULL,
  `pending_project_id` int(10) unsigned GENERATED ALWAYS AS (case when `status` = 'pending' then `project_id` else NULL end) STORED,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_adviser_requests_pending_project` (`pending_project_id`),
  KEY `idx_adviser_requests_faculty_status` (`faculty_id`,`status`,`created_at`),
  KEY `idx_adviser_requests_project_created` (`project_id`,`created_at`),
  KEY `idx_adviser_requests_requester` (`requester_id`,`created_at`),
  CONSTRAINT `fk_adviser_requests_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adviser_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adviser_requests_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adviser_requests_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `adviser_request_messages` (
  `message_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `sender_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_adviser_request_messages_thread` (`request_id`,`created_at`,`message_id`),
  KEY `idx_adviser_request_messages_sender` (`sender_id`,`created_at`),
  CONSTRAINT `fk_adviser_request_messages_request` FOREIGN KEY (`request_id`) REFERENCES `adviser_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adviser_request_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `faculty_program_assignments`;
CREATE TABLE `faculty_program_assignments` (
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

DROP TABLE IF EXISTS `user_academic_program_assignments`;
DROP TABLE IF EXISTS `user_academic_affiliations`;
DROP TABLE IF EXISTS `academic_group_programs`;
DROP TABLE IF EXISTS `academic_affiliation_programs`;
DROP TABLE IF EXISTS `academic_affiliation_groups`;
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

DROP TABLE IF EXISTS `research_basics_lesson_progress`;
CREATE TABLE `research_basics_lesson_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `lesson_id` varchar(50) NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`lesson_id`),
  KEY `idx_research_basics_lesson_progress_lesson` (`lesson_id`),
  CONSTRAINT `fk_research_basics_lesson_progress_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `research_basics_roadmap_progress`;
CREATE TABLE `research_basics_roadmap_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `stage_id` varchar(50) NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`stage_id`),
  KEY `idx_research_basics_roadmap_progress_stage` (`stage_id`),
  CONSTRAINT `fk_research_basics_roadmap_progress_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `research_submission_annotations`;
DROP TABLE IF EXISTS `research_submission_version_uploads`;
DROP TABLE IF EXISTS `research_submission_reviews`;
DROP TABLE IF EXISTS `research_submission_versions`;
CREATE TABLE `research_submission_versions` (
  `version_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `chapter_id` int(10) unsigned DEFAULT NULL,
  `submission_type` enum('title','chapter','final_manuscript') NOT NULL,
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
CREATE TABLE `research_submission_reviews` (
  `review_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version_id` int(10) unsigned NOT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `decision` enum('approved','returned') NOT NULL,
  `comments` text NOT NULL,
  `recommendation` text DEFAULT NULL,
  `selected_title` varchar(255) DEFAULT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  KEY `idx_submission_reviews_version` (`version_id`,`reviewed_at`),
  KEY `idx_submission_reviews_reviewer` (`reviewer_id`,`reviewed_at`),
  CONSTRAINT `fk_submission_reviews_version` FOREIGN KEY (`version_id`) REFERENCES `research_submission_versions` (`version_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `research_submission_version_uploads` (
  `version_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`version_id`,`upload_id`),
  KEY `idx_submission_version_uploads_upload` (`upload_id`),
  CONSTRAINT `fk_submission_version_uploads_version` FOREIGN KEY (`version_id`) REFERENCES `research_submission_versions` (`version_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submission_version_uploads_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `research_submission_annotations` (
  `annotation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version_id` int(10) unsigned NOT NULL,
  `upload_id` int(10) unsigned NOT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `selected_text` text DEFAULT NULL,
  `anchor_json` longtext NOT NULL,
  `comment_text` text NOT NULL,
  `highlight_color` enum('yellow','blue','green','pink','purple') NOT NULL DEFAULT 'yellow',
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

-- ---------------------------------------------------------------------------
-- Research activity, publication, and copyright modules (Migration 012)
-- User ID columns are indexed soft references; there are no user foreign keys.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `research_activities` (
  `activity_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_type` enum('seminar_workshop','training','colloquium','conference_congress','other') NOT NULL,
  `custom_activity_type` varchar(100) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time DEFAULT NULL,
  `is_multi_day` tinyint(1) NOT NULL DEFAULT 0,
  `schedule_start_date` date DEFAULT NULL,
  `schedule_end_date` date DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `speaker` varchar(255) DEFAULT NULL,
  `organizer` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `source_project_id` int unsigned DEFAULT NULL,
  `source_publication_id` int unsigned DEFAULT NULL,
  `document_upload_id` int(10) unsigned DEFAULT NULL,
  `status` enum('upcoming','ongoing','conducted','cancelled','scheduled','completed') NOT NULL DEFAULT 'upcoming',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uq_research_activities_document_upload` (`document_upload_id`),
  KEY `idx_research_activities_source_project` (`source_project_id`),
  KEY `idx_research_activities_source_publication` (`source_publication_id`),
  CONSTRAINT `fk_research_activities_source_project`
    FOREIGN KEY (`source_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_research_activities_document_upload`
    FOREIGN KEY (`document_upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS `activity_schedule_dates` (
  `schedule_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `venue_override` varchar(255) DEFAULT NULL,
  `speaker_override` varchar(255) DEFAULT NULL,
  `audience_scope` enum('all','student','faculty','staff','specific','custom') NOT NULL DEFAULT 'all',
  `audience_roles` JSON DEFAULT NULL,
  `audience_user_ids` JSON DEFAULT NULL,
  `status` enum('scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
  `checkin_token_hash` char(64) DEFAULT NULL,
  `checkin_token_encrypted` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uq_activity_schedule_date` (`activity_id`,`schedule_date`),
  UNIQUE KEY `uq_activity_schedule_activity_schedule` (`activity_id`,`schedule_id`),
  UNIQUE KEY `uq_activity_schedule_checkin_token` (`checkin_token_hash`),
  KEY `idx_activity_schedule_date_status` (`schedule_date`,`status`),
  CONSTRAINT `fk_activity_schedule_dates_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_schedule_participants` (
  `participant_id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `participant_name` varchar(255) DEFAULT NULL,
  `attendee_affiliation` enum('student','faculty','staff','guest') DEFAULT NULL,
  `attendee_program_department` varchar(150) DEFAULT NULL,
  `role` varchar(120) DEFAULT NULL COMMENT 'speaker/attendee/organizer',
  `attended` tinyint(1) NOT NULL DEFAULT 0,
  `is_invited` tinyint(1) NOT NULL DEFAULT 0,
  `is_notified` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Invitation notification is currently active',
  `invitation_response` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `invitation_responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`participant_id`),
  UNIQUE KEY `uq_activity_schedule_participant_user` (`schedule_id`,`user_id`),
  KEY `idx_activity_schedule_participants_user` (`user_id`),
  CONSTRAINT `fk_activity_schedule_participants_date`
    FOREIGN KEY (`schedule_id`) REFERENCES `activity_schedule_dates` (`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_schedule_checkins` (
  `checkin_id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `participant_id` int unsigned NOT NULL,
  `identity_hash` char(64) NOT NULL,
  `checked_in_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`checkin_id`),
  UNIQUE KEY `uq_activity_schedule_checkin_identity` (`schedule_id`,`identity_hash`),
  KEY `idx_activity_schedule_checkins_participant` (`participant_id`),
  CONSTRAINT `fk_activity_schedule_checkins_date`
    FOREIGN KEY (`schedule_id`) REFERENCES `activity_schedule_dates` (`schedule_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_schedule_checkins_participant`
    FOREIGN KEY (`participant_id`) REFERENCES `activity_schedule_participants` (`participant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_budgets` (
  `budget_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `activity_format` enum('on_site','online','hybrid') NOT NULL DEFAULT 'on_site',
  `funding_source` enum('organizational','participants','combined','free') NOT NULL DEFAULT 'organizational',
  `budget_status` enum('pending','confirmed') NOT NULL DEFAULT 'pending',
  `organizational_allocation` decimal(12,2) DEFAULT NULL,
  `contribution_prompt` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`budget_id`),
  UNIQUE KEY `uq_activity_budgets_activity` (`activity_id`),
  UNIQUE KEY `uq_activity_budgets_pair` (`budget_id`,`activity_id`),
  CONSTRAINT `fk_activity_budgets_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_budget_expenses` (
  `expense_id` int unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL,
  `schedule_id` int unsigned DEFAULT NULL,
  `expense_category` enum('registration','transport','accommodation','meals','online_fee','other') NOT NULL DEFAULT 'other',
  `audience_scope` enum('all','student','faculty','staff','specific') NOT NULL DEFAULT 'all',
  `audience_user_id` int unsigned DEFAULT NULL,
  `charge_type` enum('none','optional','mandatory') NOT NULL DEFAULT 'none',
  `organizational_estimated_amount` decimal(12,2) DEFAULT NULL,
  `organizational_actual_amount` decimal(12,2) DEFAULT NULL,
  `participant_estimated_charge` decimal(12,2) DEFAULT NULL,
  `participant_actual_charge` decimal(12,2) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`expense_id`),
  KEY `idx_activity_budget_expenses_budget` (`budget_id`),
  KEY `idx_activity_budget_expenses_activity` (`activity_id`),
  KEY `idx_activity_budget_expenses_schedule` (`activity_id`,`schedule_id`),
  CONSTRAINT `fk_activity_budget_expenses_budget_activity`
    FOREIGN KEY (`budget_id`,`activity_id`) REFERENCES `activity_budgets` (`budget_id`,`activity_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_budget_expenses_activity_schedule`
    FOREIGN KEY (`activity_id`,`schedule_id`) REFERENCES `activity_schedule_dates` (`activity_id`,`schedule_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_budget_revisions` (
  `revision_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `changed_by` int unsigned DEFAULT NULL,
  `previous_values` json DEFAULT NULL,
  `new_values` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`revision_id`),
  KEY `idx_activity_budget_revisions_activity` (`activity_id`,`revision_id`),
  KEY `idx_activity_budget_revisions_actor` (`changed_by`),
  CONSTRAINT `fk_activity_budget_revisions_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_budget_revisions_actor`
    FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_contribution_responses` (
  `response_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_participant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `response` enum('willing','unwilling','needs_information') NOT NULL,
  `responded_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`response_id`),
  UNIQUE KEY `uq_activity_contribution_response_participant` (`schedule_participant_id`),
  KEY `idx_activity_contribution_response_user` (`user_id`),
  CONSTRAINT `fk_activity_contribution_response_participant`
    FOREIGN KEY (`schedule_participant_id`) REFERENCES `activity_schedule_participants` (`participant_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_contribution_response_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_budget_notices` (
  `notice_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `confirmation_key` char(64) NOT NULL,
  `notice_type` enum('pending','budget_update') NOT NULL,
  `message` text NOT NULL,
  `confirmed_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notice_id`),
  UNIQUE KEY `uq_activity_budget_notice_confirmation` (`confirmation_key`),
  KEY `idx_activity_budget_notices_activity` (`activity_id`,`notice_id`),
  CONSTRAINT `fk_activity_budget_notices_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_budget_notices_actor`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_budget_notice_deliveries` (
  `delivery_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `notification_id` int unsigned DEFAULT NULL,
  `delivery_key` char(64) NOT NULL,
  `status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`delivery_id`),
  UNIQUE KEY `uq_activity_budget_notice_recipient` (`notice_id`,`user_id`),
  UNIQUE KEY `uq_activity_budget_notice_delivery_key` (`delivery_key`),
  UNIQUE KEY `uq_activity_budget_notice_notification` (`notification_id`),
  KEY `idx_activity_budget_notice_delivery_status` (`notice_id`,`status`),
  CONSTRAINT `fk_activity_budget_notice_delivery_notice`
    FOREIGN KEY (`notice_id`) REFERENCES `activity_budget_notices` (`notice_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_budget_notice_delivery_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_budget_notice_delivery_notification`
    FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`notification_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_types (
  publication_type_id smallint unsigned NOT NULL AUTO_INCREMENT,
  type_code varchar(80) NOT NULL,
  label varchar(160) NOT NULL,
  is_proposed tinyint(1) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_type_id),
  UNIQUE KEY uq_publication_types_code (type_code),
  UNIQUE KEY uq_publication_types_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_subtypes (
  publication_subtype_id smallint unsigned NOT NULL AUTO_INCREMENT,
  publication_type_id smallint unsigned NOT NULL,
  subtype_code varchar(80) NOT NULL,
  label varchar(160) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_subtype_id),
  UNIQUE KEY uq_publication_subtypes_code (publication_type_id, subtype_code),
  UNIQUE KEY uq_publication_subtypes_label (publication_type_id, label),
  CONSTRAINT fk_publication_subtypes_type FOREIGN KEY (publication_type_id) REFERENCES publication_types(publication_type_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_scopes (
  publication_scope_id smallint unsigned NOT NULL AUTO_INCREMENT,
  scope_code varchar(40) NOT NULL,
  label varchar(120) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_scope_id),
  UNIQUE KEY uq_publication_scopes_code (scope_code),
  UNIQUE KEY uq_publication_scopes_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_form_rules (
  publication_subtype_id smallint unsigned NOT NULL,
  abstract_word_limit smallint unsigned DEFAULT NULL,
  keyword_count tinyint unsigned NOT NULL DEFAULT 0,
  manuscript_required tinyint(1) NOT NULL DEFAULT 0,
  references_required tinyint(1) NOT NULL DEFAULT 0,
  evidence_required tinyint(1) NOT NULL DEFAULT 1,
  manuscript_page_limit smallint unsigned DEFAULT NULL,
  allowed_attachment_extensions varchar(80) NOT NULL DEFAULT 'pdf,docx',
  PRIMARY KEY (publication_subtype_id),
  CONSTRAINT fk_publication_form_rules_subtype FOREIGN KEY (publication_subtype_id) REFERENCES publication_subtypes(publication_subtype_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publications` (
  archival_status enum('legacy','archived') NOT NULL DEFAULT 'legacy',
  encoded_by int unsigned DEFAULT NULL,
  last_modified_by int unsigned DEFAULT NULL,
  deactivated_at datetime DEFAULT NULL,
  volume varchar(60) DEFAULT NULL,
  issue varchar(60) DEFAULT NULL,
  page_numbers varchar(80) DEFAULT NULL,
  publication_url varchar(500) DEFAULT NULL,
  indexing_information varchar(255) DEFAULT NULL,
  sdg_alignment varchar(255) DEFAULT NULL,
  remarks text DEFAULT NULL,
  KEY idx_publications_archival (archival_status, deactivated_at),
  CONSTRAINT fk_publications_encoder FOREIGN KEY (encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_publications_editor FOREIGN KEY (last_modified_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  `publication_id` int unsigned NOT NULL AUTO_INCREMENT,
  `research_title` varchar(500) NOT NULL,
  `researcher_id` int unsigned DEFAULT NULL,
  `research_project_id` int unsigned DEFAULT NULL,
  `researcher_name` varchar(255) DEFAULT NULL COMMENT 'Denormalized for non-user authors',
  `researcher_category` enum('faculty','student') NOT NULL DEFAULT 'faculty',
  `publication_type` enum('journal','conference','book_chapter','other') NOT NULL DEFAULT 'journal',
  `publication_type_name` varchar(120) DEFAULT NULL,
  `publication_type_id` smallint unsigned DEFAULT NULL,
  `publication_subtype_id` smallint unsigned DEFAULT NULL,
  `publication_scope_id` smallint unsigned DEFAULT NULL,
  `research_area_id` int unsigned DEFAULT NULL,
  `abstract_text` text DEFAULT NULL,
  `authors_json` text DEFAULT NULL,
  `keywords_json` text DEFAULT NULL,
  `publication_metadata_json` longtext DEFAULT NULL,
  `evidence_url` varchar(500) DEFAULT NULL,
  `evidence_file_path` varchar(500) DEFAULT NULL,
  `evidence_file_name` varchar(255) DEFAULT NULL,
  `references_file_path` varchar(500) DEFAULT NULL,
  `references_file_name` varchar(255) DEFAULT NULL,
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
  KEY `idx_publications_publication_date` (`publication_date`),
  KEY `idx_publications_research_project_id` (`research_project_id`),
  KEY idx_publications_type_id (publication_type_id),
  KEY idx_publications_subtype_id (publication_subtype_id),
  KEY idx_publications_scope_id (publication_scope_id),
  KEY idx_publications_research_area (research_area_id),
  CONSTRAINT `fk_publications_research_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  CONSTRAINT fk_publications_classification_type FOREIGN KEY (publication_type_id) REFERENCES publication_types(publication_type_id) ON DELETE RESTRICT,
  CONSTRAINT fk_publications_classification_subtype FOREIGN KEY (publication_subtype_id) REFERENCES publication_subtypes(publication_subtype_id) ON DELETE RESTRICT,
  CONSTRAINT fk_publications_scope FOREIGN KEY (publication_scope_id) REFERENCES publication_scopes(publication_scope_id) ON DELETE RESTRICT,
  CONSTRAINT fk_publications_research_area FOREIGN KEY (research_area_id) REFERENCES research_areas(area_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `research_activities`
  ADD CONSTRAINT `fk_research_activities_source_publication`
    FOREIGN KEY (`source_publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `copyright_applications` (
  archival_status enum('legacy','archived') NOT NULL DEFAULT 'legacy',
  external_status enum('filed','processing','registered','refused','withdrawn','other') DEFAULT NULL,
  encoded_by int unsigned DEFAULT NULL,
  last_modified_by int unsigned DEFAULT NULL,
  deactivated_at datetime DEFAULT NULL,
  remarks text DEFAULT NULL,
  KEY idx_copyright_archival (archival_status, deactivated_at),
  CONSTRAINT fk_copyright_encoder FOREIGN KEY (encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_copyright_editor FOREIGN KEY (last_modified_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  `copyright_id` int unsigned NOT NULL AUTO_INCREMENT,
  `applicant_id` int unsigned DEFAULT NULL,
  `applicant_name` varchar(255) DEFAULT NULL,
  `applicant_category` enum('faculty','student') NOT NULL DEFAULT 'faculty',
  `research_project_id` int unsigned DEFAULT NULL,
  `publication_id` int unsigned DEFAULT NULL,
  `output_title` varchar(500) NOT NULL,
  `output_type` enum('software','research','instructional_material','module','other','patent','utility_model','innovation','technology_transfer') NOT NULL DEFAULT 'research',
  `co_authors` varchar(500) DEFAULT NULL,
  `inventors` varchar(500) DEFAULT NULL,
  `potential_partners` varchar(500) DEFAULT NULL,
  `transfer_activities` text DEFAULT NULL,
  `agreement_reference` varchar(120) DEFAULT NULL,
  `utilization_status` varchar(120) DEFAULT NULL,
  `date_completed` date DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `copyright_ref_no` varchar(120) DEFAULT NULL,
  `application_number` varchar(120) DEFAULT NULL,
  `filing_date` date DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `status` enum('pending','under_review','registered','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`copyright_id`),
  KEY `idx_copyright_applications_applicant_id` (`applicant_id`),
  KEY `idx_copyright_applications_status` (`status`),
  KEY `idx_copyright_applications_research_project_id` (`research_project_id`),
  KEY `idx_copyright_applications_publication_id` (`publication_id`),
  CONSTRAINT `fk_copyright_applications_research_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_copyright_applications_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publication_contributors` (
  `publication_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `contribution` varchar(120) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`publication_id`,`user_id`),
  KEY `idx_publication_contributors_user` (`user_id`,`publication_id`),
  CONSTRAINT `fk_publication_contributors_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_publication_contributors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO publication_types (publication_type_id,type_code,label,is_proposed,sort_order) VALUES
 (1,'journal','Journal (legacy classification)',0,10),
 (2,'conference','Conference (legacy classification)',0,20),
 (3,'book_chapter','Book chapter (legacy classification)',0,30),
 (4,'other','Other (legacy classification)',0,40),
 (5,'journal_article','Journal Article',1,100),
 (6,'conference_proceedings_paper','Conference Proceedings Paper',1,110),
 (7,'book','Book',1,120),
 (8,'book_chapter_scholarly','Book Chapter',1,130),
 (9,'research_monograph','Research Monograph',1,140);
INSERT INTO publication_subtypes (publication_subtype_id,publication_type_id,subtype_code,label,sort_order) VALUES
 (1,5,'original_research_article','Original Research Article',10),(2,5,'review_article','Review Article',20),(3,5,'technical_professional_article','Technical / Professional Article',30),(4,5,'other_journal_article','Other Journal Article',40),
 (5,6,'full_conference_paper','Full Conference Paper',10),(6,6,'short_conference_paper','Short Conference Paper',20),(7,6,'other_published_proceedings_paper','Other Published Proceedings Paper',30),
 (8,7,'authored_book','Authored Book',10),(9,7,'edited_book','Edited Book',20),(10,7,'compiled_book','Compiled Book',30),(11,7,'textbook','Textbook',40),
 (12,8,'scholarly_book_chapter','Scholarly Book Chapter',10),(13,8,'technical_professional_book_chapter','Technical / Professional Book Chapter',20),
 (14,9,'scholarly_monograph','Scholarly Monograph',10),(15,9,'technical_research_monograph','Technical Research Monograph',20);
INSERT INTO publication_scopes (publication_scope_id,scope_code,label,sort_order) VALUES
 (1,'local_institutional','Local / Institutional',10),(2,'national','National',20),(3,'international','International',30);
INSERT INTO publication_form_rules (publication_subtype_id,abstract_word_limit,keyword_count,manuscript_required,references_required,evidence_required,manuscript_page_limit,allowed_attachment_extensions) VALUES
 (6,250,4,1,0,1,NULL,'pdf,docx');

CREATE TABLE IF NOT EXISTS `copyright_contributors` (
  `copyright_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `contribution` varchar(120) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`copyright_id`,`user_id`),
  KEY `idx_copyright_contributors_user` (`user_id`,`copyright_id`),
  CONSTRAINT `fk_copyright_contributors_application` FOREIGN KEY (`copyright_id`) REFERENCES `copyright_applications` (`copyright_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_copyright_contributors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `faculty_engagement_records`;
DROP TABLE IF EXISTS `faculty_research_profiles`;
CREATE TABLE `faculty_research_profiles` (
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
CREATE TABLE `faculty_engagement_records` (
  `engagement_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faculty_id` int(10) unsigned NOT NULL,
  `activity_id` int(10) unsigned DEFAULT NULL,
  `engagement_type` enum('seminar_training','conference_presentation','extension_community','research_award','collaboration','other') NOT NULL,
  `custom_engagement_type` varchar(255) DEFAULT NULL,
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
  KEY `idx_faculty_engagement_activity` (`activity_id`),
  KEY `idx_faculty_engagement_date` (`activity_date`),
  KEY `idx_faculty_engagement_project` (`research_project_id`),
  KEY `idx_faculty_engagement_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_faculty_engagement_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_faculty_engagement_activity` FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_faculty_engagement_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_faculty_engagement_upload` FOREIGN KEY (`evidence_upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_faculty_engagement_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- ---------------------------------------------------------------------------
-- Required reference data and demo accounts
-- ---------------------------------------------------------------------------

INSERT INTO `research_categories` (`category_id`, `category_name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Applied Research', 'Research directed toward practical applications', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(2, 'Basic Research', 'Research aimed at expanding knowledge', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(3, 'Action Research', 'Research to solve a specific practical issue', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(4, 'Developmental Research', 'Research focused on developing new products/systems', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(5, 'Evaluation Research', 'Research that measures effectiveness of programs', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(6, 'Current Computer Science Topics', 'Computer Science course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(7, 'Foundations of Computer Science', 'Computer Science course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(8, 'Human-Computer Interaction', 'Computer Science course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(9, 'Software Development', 'Information Technology course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(10, 'Multimedia Systems', 'Information Technology course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(11, 'Network Design and Implementation', 'Information Technology course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00'),
(12, 'IT Management', 'Information Technology course-specific research category', 1, '2026-10-29 00:00:00', '2026-10-29 00:00:00');

INSERT INTO `research_areas` (`area_code`, `area_name`) VALUES
('RA001', 'Computer Science'),
('RA002', 'Information Technology'),
('RA003', 'Artificial Intelligence and Machine Learning'),
('RA004', 'Data Science and Analytics'),
('RA005', 'Cybersecurity and Information Security'),
('RA006', 'Software Engineering and Development'),
('RA007', 'Web and Mobile Application Development'),
('RA008', 'Database Management and Information Systems'),
('RA009', 'Computer Networks and Communications'),
('RA010', 'Human-Computer Interaction'),
('RA011', 'Educational Technology'),
('RA012', 'Education and Teaching Methodologies'),
('RA013', 'Curriculum and Instruction'),
('RA014', 'Educational Leadership and Management'),
('RA015', 'Student Development and Learning Outcomes'),
('RA016', 'Business Administration and Management'),
('RA017', 'Entrepreneurship and Innovation'),
('RA018', 'Marketing and Consumer Behavior'),
('RA019', 'Financial Management and Accounting'),
('RA020', 'Human Resource Management'),
('RA021', 'Office Administration and Management'),
('RA022', 'Criminology and Criminal Justice'),
('RA023', 'Crime Prevention and Public Safety'),
('RA024', 'Forensic Science and Investigation'),
('RA025', 'Hospitality and Tourism Management'),
('RA026', 'Food Science and Culinary Innovation'),
('RA027', 'Industrial Technology'),
('RA028', 'Engineering and Applied Technology'),
('RA029', 'Environmental Science and Sustainability'),
('RA030', 'Renewable Energy and Green Technology'),
('RA031', 'Health and Wellness'),
('RA032', 'Social Sciences and Humanities'),
('RA033', 'Gender and Development'),
('RA034', 'Community Development and Social Innovation'),
('RA035', 'Public Administration and Governance'),
('RA036', 'Digital Transformation and E-Governance'),
('RA037', 'Community Extension and Technology Transfer'),
('RA038', 'Sustainable Development and Social Responsibility'),
('RA039', 'Interdisciplinary and Multidisciplinary Studies'),
('RA040', 'Other Research Areas');

INSERT INTO `category_research_areas` (`category_id`, `area_id`)
SELECT rc.`category_id`, ra.`area_id`
FROM `research_categories` rc
JOIN `research_areas` ra
WHERE
  (rc.`category_name` = 'Action Research' AND ra.`area_code` IN (
    'RA011','RA012','RA013','RA014','RA015','RA020','RA021','RA023','RA025',
    'RA032','RA033','RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Applied Research' AND ra.`area_code` IN (
    'RA001','RA002','RA003','RA004','RA005','RA006','RA007','RA008','RA009','RA010','RA011',
    'RA016','RA017','RA018','RA019','RA020','RA021','RA022','RA023','RA024','RA025','RA026',
    'RA027','RA028','RA029','RA030','RA031','RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Basic Research' AND ra.`area_code` IN (
    'RA001','RA003','RA004','RA005','RA009','RA010','RA012','RA013','RA015','RA016','RA018',
    'RA019','RA022','RA024','RA026','RA029','RA030','RA031','RA032','RA033','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Developmental Research' AND ra.`area_code` IN (
    'RA001','RA002','RA003','RA004','RA005','RA006','RA007','RA008','RA009','RA010','RA011',
    'RA013','RA016','RA017','RA021','RA025','RA026','RA027','RA028','RA030','RA034','RA036',
    'RA037','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Evaluation Research' AND ra.`area_code` IN (
    'RA001','RA002','RA005','RA006','RA007','RA008','RA010','RA011','RA012','RA013','RA014',
    'RA015','RA016','RA018','RA020','RA021','RA022','RA023','RA025','RA027','RA028','RA031',
    'RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ));

INSERT INTO `academic_years` (`ay_id`, `label`, `semester`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2023-2024', '1st', 0, '2026-05-31 01:49:59', '2026-09-01 09:53:43'),
(3, '2024-2025', '1st', 0, '2026-05-31 01:49:59', '2026-09-01 09:53:43'),
(8, '2025-2026', '2nd', 1, '2026-05-31 02:55:48', '2026-09-01 09:53:43'),
(9, '2026-2027', '1st', 0, '2026-05-31 02:57:54', '2026-09-01 09:53:43');

INSERT INTO `departments` (`dept_id`, `dept_code`, `dept_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'CAS', 'College of Arts and Sciences', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(2, 'CBA', 'College of Business Administration', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(3, 'BINTECH', 'College of Industrial Technology', 1, '2026-09-01 09:53:43', '2026-09-01 13:32:56'),
(4, 'CHM', 'College of Hospitality Management', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(5, 'CED', 'College of Education', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(6, 'CPAC', 'College of Public Administration and Criminology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43');

INSERT INTO `programs` (`program_id`, `dept_id`, `program_code`, `program_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'BSIT', 'Bachelor of Science in Information Technology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(2, 1, 'BSCS', 'Bachelor of Science in Computer Science', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(3, 2, 'BSBA-MKTG', 'Bachelor of Science in Business Administration – Marketing Management', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(4, 2, 'BSBA-HRDM', 'Bachelor of Science in Business Administration – Human Resource Development', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(5, 2, 'BSBA-FM', 'Bachelor of Science in Business Administration – Financial Management', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(6, 2, 'BSEntrep', 'Bachelor of Science in Entrepreneurship', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(7, 2, 'BSOA', 'Bachelor of Science in Office Administration', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(8, 3, 'BIT-Auto', 'Bachelor of Industrial Technology – Automotive Technology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(9, 3, 'BIT-Electrical', 'Bachelor of Industrial Technology – Electrical Technology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(10, 3, 'BIT-Electronics', 'Bachelor of Industrial Technology – Electronics Technology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(11, 3, 'BIT-DM', 'Bachelor of Industrial Technology – Drafting/Mechanical Technology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(12, 4, 'BSHM', 'Bachelor of Science in Hospitality Management', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(13, 5, 'BSEd', 'Bachelor of Secondary Education', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(14, 5, 'BTLEd', 'Bachelor of Technology and Livelihood Education', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(15, 6, 'BSCrim', 'Bachelor of Science in Criminology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43'),
(16, 1, 'BSP', 'Bachelor of Science in Psychology', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43');

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `password`, `student_id`, `department`, `office`, `specialization`, `academic_rank`, `is_reviewer`, `program`, `year_level`, `contact`, `avatar`, `status`, `last_login`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'admin', 'System', 'Administrator', 'admin@rms.edu.ph', '$2y$12$1UzhJiJbBSdUw.3bEMUg/ub1Dx55.kgrVAQiphCfUKwe8ywCM9XCO', NULL, NULL, 'Research Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:59:46', NULL),
(2, 'faculty', 'Maria', 'Santos', 'msantos@rms.edu.ph', '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG', NULL, 'College of Computer Studies', NULL, NULL, 'Instructor', 1, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:59:30', NULL),
(3, 'faculty', 'Jose', 'Reyes', 'jreyes@rms.edu.ph', '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG', NULL, 'College of Computer Studies', NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(4, 'student', 'Juan', 'Dela Cruz', 'jdelacruz@rms.edu.ph', '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK', '2024-00001', 'College of Computer Studies', NULL, NULL, NULL, 0, 'BSIT', NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:58:09', NULL),
(5, 'student', 'Anna', 'Reyes', 'areyes@rms.edu.ph', '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK', '2024-00002', 'College of Computer Studies', NULL, NULL, NULL, 0, 'BSIT', NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(6, 'research_staff', 'staff1', '', 'staff1@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'EREC Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(7, 'research_staff', 'staff2', '', 'staff2@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'CREC Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL);

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

INSERT INTO `academic_affiliation_programs` (`program_id`, `program_code`, `program_name`)
SELECT `program_id`, `program_code`, `program_name` FROM `programs`;

INSERT INTO `academic_affiliation_programs` (`program_id`, `program_code`, `program_name`, `is_specialization`)
VALUES (NULL, 'BIT-FoodTech', 'Food Technology', 0);

INSERT INTO `academic_group_programs` (`group_id`, `affiliation_program_id`)
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

INSERT INTO `academic_group_programs` (`group_id`, `affiliation_program_id`)
SELECT g.`group_id`, ap.`affiliation_program_id`
  FROM `academic_affiliation_groups` g
  JOIN `academic_affiliation_programs` ap ON ap.`program_code` = 'BIT-FoodTech' AND ap.`program_name` = 'Food Technology'
 WHERE g.`group_code` = 'industrial-technology' AND ap.`program_id` IS NULL AND ap.`is_specialization` = 0;

INSERT INTO `user_academic_affiliations` (`user_id`, `group_id`, `is_primary`)
SELECT u.`user_id`, gp.`group_id`,
       MAX(CASE WHEN UPPER(TRIM(u.`program`)) IN (UPPER(ap.`program_code`), UPPER(ap.`program_name`)) THEN 1 ELSE 0 END)
  FROM `users` u
  JOIN `faculty_program_assignments` fpa ON fpa.`faculty_id` = u.`user_id`
  JOIN `academic_affiliation_programs` ap ON ap.`program_id` = fpa.`program_id`
  JOIN `academic_group_programs` gp ON gp.`affiliation_program_id` = ap.`affiliation_program_id` AND gp.`status` = 1
 WHERE u.`role` = 'faculty' AND u.`deleted_at` IS NULL
 GROUP BY u.`user_id`, gp.`group_id`;

INSERT INTO `user_academic_program_assignments` (`affiliation_id`, `group_id`, `affiliation_program_id`)
SELECT ua.`affiliation_id`, ua.`group_id`, gp.`affiliation_program_id`
  FROM `user_academic_affiliations` ua
  JOIN `faculty_program_assignments` fpa ON fpa.`faculty_id` = ua.`user_id`
  JOIN `academic_affiliation_programs` ap ON ap.`program_id` = fpa.`program_id`
  JOIN `academic_group_programs` gp ON gp.`group_id` = ua.`group_id` AND gp.`affiliation_program_id` = ap.`affiliation_program_id`
 WHERE ua.`user_id` IN (SELECT `user_id` FROM `users` WHERE `role` = 'faculty' AND `deleted_at` IS NULL);

-- DEMO: Research activity records for fresh-install previews.
INSERT INTO `research_activities` (`activity_id`, `activity_type`, `title`, `activity_date`, `activity_time`, `venue`, `speaker`, `organizer`, `description`, `status`, `created_by`) VALUES
(1, 'seminar_workshop', 'Responsible Research and Publication Ethics', '2026-10-08', '09:00:00', 'EARIST Cavite Audio-Visual Room', 'Dr. Maria Santos', 'EREC Office', 'Orientation on ethical research conduct and responsible publication.', 'scheduled', 6),
(2, 'seminar_workshop', 'Intellectual Property Documentation Workshop', '2026-08-21', '13:30:00', 'Research and Innovation Center', 'Engr. Jose Reyes', 'CREC', 'Hands-on preparation of copyright application materials.', 'completed', 7),
(3, 'seminar_workshop', 'Student Research Forum 2026', '2026-11-14', NULL, 'Campus Multipurpose Hall', NULL, 'CREC Office', 'Interdisciplinary student research exchange.', 'scheduled', 7);

-- DEMO: Activity participant records, including linked users and a named guest.
INSERT INTO `activity_participants` (`participant_id`, `activity_id`, `user_id`, `participant_name`, `role`, `attended`) VALUES
(1, 1, 2, 'Maria Santos', 'speaker', 0),
(2, 2, 4, 'Juan Dela Cruz', 'attendee', 1),
(3, 3, NULL, 'Ana Villanueva', 'organizer', 0);

UPDATE `research_activities`
SET `schedule_start_date` = `activity_date`, `schedule_end_date` = `activity_date`
WHERE `schedule_start_date` IS NULL OR `schedule_end_date` IS NULL;

INSERT INTO `activity_schedule_dates`
  (`activity_id`, `schedule_date`, `start_time`, `audience_scope`, `status`)
SELECT ra.`activity_id`, ra.`activity_date`, ra.`activity_time`, 'specific',
       IF(ra.`status` = 'cancelled', 'cancelled', 'scheduled')
FROM `research_activities` ra
WHERE NOT EXISTS (
  SELECT 1 FROM `activity_schedule_dates` d
  WHERE d.`activity_id` = ra.`activity_id` AND d.`schedule_date` = ra.`activity_date`
);

INSERT INTO `activity_schedule_participants`
  (`schedule_id`, `user_id`, `participant_name`, `role`, `attended`, `is_invited`, `is_notified`)
SELECT d.`schedule_id`, ap.`user_id`, MAX(ap.`participant_name`), MAX(ap.`role`), MAX(ap.`attended`),
       IF(ap.`user_id` IS NULL, 0, 1), 0
FROM `activity_participants` ap
INNER JOIN `activity_schedule_dates` d
  ON d.`activity_id` = ap.`activity_id`
INNER JOIN (
  SELECT `activity_id`, MIN(`schedule_date`) AS `schedule_date`
  FROM `activity_schedule_dates`
  GROUP BY `activity_id`
) first_date ON first_date.`activity_id` = d.`activity_id`
            AND first_date.`schedule_date` = d.`schedule_date`
WHERE NOT EXISTS (
  SELECT 1 FROM `activity_schedule_participants` sp
  WHERE sp.`schedule_id` = d.`schedule_id` AND sp.`user_id` <=> ap.`user_id`
    AND COALESCE(sp.`participant_name`, '') = COALESCE(ap.`participant_name`, '')
)
GROUP BY d.`schedule_id`, ap.`user_id`,
         CASE WHEN ap.`user_id` IS NULL THEN COALESCE(ap.`participant_name`, '') ELSE '' END;

-- DEMO: Faculty and student publication records.
INSERT INTO `publications` (`publication_id`, `research_title`, `researcher_id`, `researcher_name`, `researcher_category`, `publication_type`, `journal_publisher`, `publication_date`, `doi_identifier`, `file_path`, `file_name`, `status`) VALUES
(1, 'Campus Research Monitoring Through Integrated Digital Workflows', 2, 'Maria Santos', 'faculty', 'journal', 'Philippine Journal of Technology Education', '2026-06-30', '10.1234/pjte.2026.001', 'uploads/publications/2026/', 'campus-research-monitoring.pdf', 'published'),
(2, 'Low-Cost Environmental Sensor Network for Cavite Communities', 4, 'Juan Dela Cruz', 'student', 'conference', 'Cavite Research and Innovation Conference', NULL, NULL, 'uploads/publications/2026/', 'environmental-sensor-network.pdf', 'under_review'),
(3, 'Inclusive Learning Spaces in State Universities', NULL, 'Elena Navarro', 'faculty', 'book_chapter', 'Southern Luzon Academic Press', '2026-04-15', NULL, NULL, NULL, 'accepted');

-- DEMO: Copyright applications at representative workflow stages.
INSERT INTO `copyright_applications` (`copyright_id`, `applicant_id`, `applicant_name`, `applicant_category`, `output_title`, `output_type`, `co_authors`, `date_completed`, `file_path`, `file_name`, `copyright_ref_no`, `status`) VALUES
(1, 2, 'Maria Santos', 'faculty', 'EARIST Cavite Research Monitoring System', 'software', 'Jose Reyes', '2026-07-18', 'uploads/copyright/2026/', 'rms-source-and-manual.zip', 'CR-2026-0041', 'registered'),
(2, 4, 'Juan Dela Cruz', 'student', 'Community Sensor Deployment Guide', 'instructional_material', 'Anna Reyes', '2026-08-02', 'uploads/copyright/2026/', 'sensor-deployment-guide.pdf', NULL, 'under_review'),
(3, NULL, 'Roberto Mendoza', 'faculty', 'Applied Research Methods Module', 'module', NULL, '2026-09-10', NULL, NULL, NULL, 'pending');

-- Best-effort restoration of the two message relationships from the original
-- schema. The handler deliberately swallows DDL errors on engines that cannot
-- add them, so the rest of a fresh installation remains usable.
DELIMITER $$
DROP PROCEDURE IF EXISTS `add_messages_foreign_keys`$$
CREATE PROCEDURE `add_messages_foreign_keys`()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = 'messages_ibfk_1'
    ) THEN
        ALTER TABLE `messages`
            ADD CONSTRAINT `messages_ibfk_1`
            FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`)
            ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = 'messages_ibfk_2'
    ) THEN
        ALTER TABLE `messages`
            ADD CONSTRAINT `messages_ibfk_2`
            FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`)
            ON DELETE CASCADE;
    END IF;
END$$
CALL `add_messages_foreign_keys`()$$
DROP PROCEDURE `add_messages_foreign_keys`$$
DELIMITER ;

-- Curated BSIT/BSCS selection (migration 031); original catalog remains above.
-- Preserve all catalog IDs/names and all project/history associations.
-- Only first-time scoped entries receive the suggested category mappings.
-- Reapplication preserves administrator scope, labels, activation and mappings.
CREATE TABLE IF NOT EXISTS research_area_selection_scope (
  area_id int(10) unsigned NOT NULL,
  display_label varchar(180) NOT NULL,
  is_enabled tinyint(1) NOT NULL DEFAULT 1,
  display_order smallint(5) unsigned NOT NULL DEFAULT 100,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (area_id),
  KEY idx_research_area_scope_enabled (is_enabled, display_order),
  CONSTRAINT fk_research_area_scope_area FOREIGN KEY (area_id)
    REFERENCES research_areas (area_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Application Development'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Web Application Development');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Mobile Application Development'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Mobile Application Development');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Database Systems and Data Management'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Database Systems and Data Management');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Systems and Business Process Automation'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Information Systems and Business Process Automation');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Cloud Computing and Distributed Systems'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Cloud Computing and Distributed Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Algorithms and Computational Theory'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Algorithms and Computational Theory');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Internet of Things and Embedded Systems'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Internet of Things and Embedded Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Game Development and Gamification'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Game Development and Gamification');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'E-Governance and Community Informatics'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'E-Governance and Community Informatics');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Infrastructure and Technical Support'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'IT Infrastructure and Technical Support');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Quality Assurance and Testing'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Software Quality Assurance and Testing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer Vision and Natural Language Processing'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Computer Vision and Natural Language Processing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Emerging Technologies and Digital Transformation'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Emerging Technologies and Digital Transformation');

DROP TEMPORARY TABLE IF EXISTS rms_initial_curated_areas;
CREATE TEMPORARY TABLE rms_initial_curated_areas (
  selection_number tinyint unsigned NOT NULL PRIMARY KEY,
  area_id int(10) unsigned NOT NULL UNIQUE,
  display_label varchar(180) NOT NULL
) ENGINE=InnoDB;

INSERT INTO rms_initial_curated_areas
SELECT 1, ra.area_id, 'Software Engineering and System Development' FROM research_areas ra
WHERE ra.area_code = 'RA006'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 2, ra.area_id, 'Web Application Development' FROM research_areas ra
WHERE ra.area_name = 'Web Application Development'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 3, ra.area_id, 'Mobile Application Development' FROM research_areas ra
WHERE ra.area_name = 'Mobile Application Development'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 4, ra.area_id, 'Database Systems and Data Management' FROM research_areas ra
WHERE ra.area_name = 'Database Systems and Data Management'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 5, ra.area_id, 'Information Systems and Business Process Automation' FROM research_areas ra
WHERE ra.area_name = 'Information Systems and Business Process Automation'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 6, ra.area_id, 'Computer Networking and System Administration' FROM research_areas ra
WHERE ra.area_code = 'RA009'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 7, ra.area_id, 'Cybersecurity and Information Security' FROM research_areas ra
WHERE ra.area_code = 'RA005'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 8, ra.area_id, 'Cloud Computing and Distributed Systems' FROM research_areas ra
WHERE ra.area_name = 'Cloud Computing and Distributed Systems'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 9, ra.area_id, 'Artificial Intelligence and Machine Learning' FROM research_areas ra
WHERE ra.area_code = 'RA003'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 10, ra.area_id, 'Data Science and Analytics' FROM research_areas ra
WHERE ra.area_code = 'RA004'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 11, ra.area_id, 'Algorithms and Computational Theory' FROM research_areas ra
WHERE ra.area_name = 'Algorithms and Computational Theory'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 12, ra.area_id, 'Human-Computer Interaction and User Experience' FROM research_areas ra
WHERE ra.area_code = 'RA010'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 13, ra.area_id, 'Internet of Things and Embedded Systems' FROM research_areas ra
WHERE ra.area_name = 'Internet of Things and Embedded Systems'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 14, ra.area_id, 'Game Development and Gamification' FROM research_areas ra
WHERE ra.area_name = 'Game Development and Gamification'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 15, ra.area_id, 'Educational Technology and E-Learning Systems' FROM research_areas ra
WHERE ra.area_code = 'RA011'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 16, ra.area_id, 'E-Governance and Community Informatics' FROM research_areas ra
WHERE ra.area_name = 'E-Governance and Community Informatics'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 17, ra.area_id, 'IT Infrastructure and Technical Support' FROM research_areas ra
WHERE ra.area_name = 'IT Infrastructure and Technical Support'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 18, ra.area_id, 'Software Quality Assurance and Testing' FROM research_areas ra
WHERE ra.area_name = 'Software Quality Assurance and Testing'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 19, ra.area_id, 'Computer Vision and Natural Language Processing' FROM research_areas ra
WHERE ra.area_name = 'Computer Vision and Natural Language Processing'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 20, ra.area_id, 'Emerging Technologies and Digital Transformation' FROM research_areas ra
WHERE ra.area_name = 'Emerging Technologies and Digital Transformation'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

-- Refresh recommendations only for entries entering the scope for the first time.
-- This does not touch proposal associations, version snapshots, or non-scoped mappings.
DELETE cra FROM category_research_areas cra
JOIN rms_initial_curated_areas seed ON seed.area_id = cra.area_id;

INSERT INTO category_research_areas (category_id, area_id)
SELECT rc.category_id, seed.area_id
FROM research_categories rc
JOIN rms_initial_curated_areas seed
WHERE
  (rc.category_name = 'Action Research' AND seed.selection_number IN (5,12,15,16,17,20))
  OR   (rc.category_name = 'Applied Research' AND seed.selection_number IN (1,2,3,4,5,6,7,8,9,10,13,14,16,17,20))
  OR   (rc.category_name = 'Basic Research' AND seed.selection_number IN (4,7,8,9,10,11,12,13,19))
  OR   (rc.category_name = 'Developmental Research' AND seed.selection_number IN (1,2,3,4,5,7,8,9,13,14,15,16,18,19,20))
  OR   (rc.category_name = 'Evaluation Research' AND seed.selection_number IN (1,2,3,4,5,6,7,8,12,14,15,16,17,18,20));

INSERT INTO research_area_selection_scope (area_id, display_label, display_order)
SELECT area_id, display_label, selection_number FROM rms_initial_curated_areas;
DROP TEMPORARY TABLE rms_initial_curated_areas;
COMMIT;

-- Course-specific CS/IT research classification (migration 044).
INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Development and Theory'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Development and Theory');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Mobile Computing Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Mobile Computing Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Extensions or Plug-ins'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Extensions or Plug-ins');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Expert Systems and Decision Support Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Expert Systems and Decision Support Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Intelligent Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Intelligent Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Game Development'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Game Development');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer Vision'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Computer Vision');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Image / Signal Processing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Image / Signal Processing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Natural Language Processing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Natural Language Processing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Pattern Recognition and Data Mining'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Pattern Recognition and Data Mining');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Bioinformatics'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Bioinformatics');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Graphics Applications'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Graphics Applications');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Cloud Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Cloud Computing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Parallel Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Parallel Computing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Embedded Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Embedded Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Emerging Technologies'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Emerging Technologies');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Automata and Formal Languages'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Automata and Formal Languages');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Data Structures and Algorithm Design and Analysis'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Data Structures and Algorithm Design and Analysis');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Semantics'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Web Semantics');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Coding Theory'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Coding Theory');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Programming Languages'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Programming Languages');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Visualization Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Visualization Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer and Architecture'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Computer and Architecture');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Modeling and Simulation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Modeling and Simulation');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Usability'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Usability');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Affective Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Affective Computing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Empathic Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Empathic Computing');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Customization'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Customization');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Systems Development for an Actual Client (with pilot testing)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Information Systems Development for an Actual Client (with pilot testing)');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Applications Development (with at least alpha testing on live servers)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Web Applications Development (with at least alpha testing on live servers)');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'e-Learning Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'e-Learning Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Interactive Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Interactive Systems');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Kiosks'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Information Kiosks');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Network Design and Implementation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Network Design and Implementation');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Server Farm Configuration and Management'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Server Farm Configuration and Management');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Strategic Plan for Sufficiently Complex Enterprises'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'IT Strategic Plan for Sufficiently Complex Enterprises');

INSERT INTO `research_areas` (`area_code`, `area_name`)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Security Analysis, Planning and Implementation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'IT Security Analysis, Planning and Implementation');

INSERT INTO `research_area_selection_scope` (`area_id`, `display_label`, `display_order`)
SELECT ra.area_id, ra.area_name, 100 + areas.display_order
FROM (
  SELECT 'Software Development and Theory' AS area_name, 1 AS display_order UNION ALL
  SELECT 'Mobile Computing Systems', 2 UNION ALL
  SELECT 'Software Extensions or Plug-ins', 3 UNION ALL
  SELECT 'Expert Systems and Decision Support Systems', 4 UNION ALL
  SELECT 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)', 5 UNION ALL
  SELECT 'Intelligent Systems', 6 UNION ALL
  SELECT 'Game Development', 7 UNION ALL
  SELECT 'Computer Vision', 8 UNION ALL
  SELECT 'Image / Signal Processing', 9 UNION ALL
  SELECT 'Natural Language Processing', 10 UNION ALL
  SELECT 'Pattern Recognition and Data Mining', 11 UNION ALL
  SELECT 'Bioinformatics', 12 UNION ALL
  SELECT 'Graphics Applications', 13 UNION ALL
  SELECT 'Cloud Computing', 14 UNION ALL
  SELECT 'Parallel Computing', 15 UNION ALL
  SELECT 'Embedded Systems', 16 UNION ALL
  SELECT 'Emerging Technologies', 17 UNION ALL
  SELECT 'Automata and Formal Languages', 18 UNION ALL
  SELECT 'Data Structures and Algorithm Design and Analysis', 19 UNION ALL
  SELECT 'Web Semantics', 20 UNION ALL
  SELECT 'Coding Theory', 21 UNION ALL
  SELECT 'Programming Languages', 22 UNION ALL
  SELECT 'Visualization Systems', 23 UNION ALL
  SELECT 'Computer and Architecture', 24 UNION ALL
  SELECT 'Modeling and Simulation', 25 UNION ALL
  SELECT 'Usability', 26 UNION ALL
  SELECT 'Affective Computing', 27 UNION ALL
  SELECT 'Empathic Computing', 28 UNION ALL
  SELECT 'Software Customization', 29 UNION ALL
  SELECT 'Information Systems Development for an Actual Client (with pilot testing)', 30 UNION ALL
  SELECT 'Web Applications Development (with at least alpha testing on live servers)', 31 UNION ALL
  SELECT 'e-Learning Systems', 32 UNION ALL
  SELECT 'Interactive Systems', 33 UNION ALL
  SELECT 'Information Kiosks', 34 UNION ALL
  SELECT 'Network Design and Implementation', 35 UNION ALL
  SELECT 'Server Farm Configuration and Management', 36 UNION ALL
  SELECT 'IT Strategic Plan for Sufficiently Complex Enterprises', 37 UNION ALL
  SELECT 'IT Security Analysis, Planning and Implementation', 38
) areas
JOIN `research_areas` ra ON ra.area_name = areas.area_name
LEFT JOIN `research_area_selection_scope` scope ON scope.area_id = ra.area_id
WHERE scope.area_id IS NULL;

INSERT INTO `category_research_areas` (`category_id`, `area_id`)
SELECT rc.category_id, ra.area_id
FROM (
  SELECT 'Current Computer Science Topics' AS category_name, 'Software Development and Theory' AS area_name UNION ALL
  SELECT 'Current Computer Science Topics', 'Mobile Computing Systems' UNION ALL
  SELECT 'Current Computer Science Topics', 'Software Extensions or Plug-ins' UNION ALL
  SELECT 'Current Computer Science Topics', 'Expert Systems and Decision Support Systems' UNION ALL
  SELECT 'Current Computer Science Topics', 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)' UNION ALL
  SELECT 'Current Computer Science Topics', 'Intelligent Systems' UNION ALL
  SELECT 'Current Computer Science Topics', 'Game Development' UNION ALL
  SELECT 'Current Computer Science Topics', 'Computer Vision' UNION ALL
  SELECT 'Current Computer Science Topics', 'Image / Signal Processing' UNION ALL
  SELECT 'Current Computer Science Topics', 'Natural Language Processing' UNION ALL
  SELECT 'Current Computer Science Topics', 'Pattern Recognition and Data Mining' UNION ALL
  SELECT 'Current Computer Science Topics', 'Bioinformatics' UNION ALL
  SELECT 'Current Computer Science Topics', 'Graphics Applications' UNION ALL
  SELECT 'Current Computer Science Topics', 'Cloud Computing' UNION ALL
  SELECT 'Current Computer Science Topics', 'Parallel Computing' UNION ALL
  SELECT 'Current Computer Science Topics', 'Embedded Systems' UNION ALL
  SELECT 'Current Computer Science Topics', 'Emerging Technologies' UNION ALL
  SELECT 'Foundations of Computer Science', 'Automata and Formal Languages' UNION ALL
  SELECT 'Foundations of Computer Science', 'Data Structures and Algorithm Design and Analysis' UNION ALL
  SELECT 'Foundations of Computer Science', 'Web Semantics' UNION ALL
  SELECT 'Foundations of Computer Science', 'Coding Theory' UNION ALL
  SELECT 'Foundations of Computer Science', 'Programming Languages' UNION ALL
  SELECT 'Foundations of Computer Science', 'Visualization Systems' UNION ALL
  SELECT 'Foundations of Computer Science', 'Computer and Architecture' UNION ALL
  SELECT 'Foundations of Computer Science', 'Modeling and Simulation' UNION ALL
  SELECT 'Human-Computer Interaction', 'Usability' UNION ALL
  SELECT 'Human-Computer Interaction', 'Affective Computing' UNION ALL
  SELECT 'Human-Computer Interaction', 'Empathic Computing' UNION ALL
  SELECT 'Software Development', 'Software Customization' UNION ALL
  SELECT 'Software Development', 'Information Systems Development for an Actual Client (with pilot testing)' UNION ALL
  SELECT 'Software Development', 'Web Applications Development (with at least alpha testing on live servers)' UNION ALL
  SELECT 'Software Development', 'Mobile Computing Systems' UNION ALL
  SELECT 'Multimedia Systems', 'Game Development' UNION ALL
  SELECT 'Multimedia Systems', 'e-Learning Systems' UNION ALL
  SELECT 'Multimedia Systems', 'Interactive Systems' UNION ALL
  SELECT 'Multimedia Systems', 'Information Kiosks' UNION ALL
  SELECT 'Network Design and Implementation', 'Network Design and Implementation' UNION ALL
  SELECT 'Network Design and Implementation', 'Server Farm Configuration and Management' UNION ALL
  SELECT 'IT Management', 'IT Strategic Plan for Sufficiently Complex Enterprises' UNION ALL
  SELECT 'IT Management', 'IT Security Analysis, Planning and Implementation'
) course_areas
JOIN `research_categories` rc ON rc.category_name = course_areas.category_name
JOIN `research_areas` ra ON ra.area_name = course_areas.area_name
LEFT JOIN `category_research_areas` existing ON existing.category_id = rc.category_id AND existing.area_id = ra.area_id
WHERE existing.category_id IS NULL;

INSERT INTO `research_category_programs` (`category_id`, `program_id`)
SELECT rc.category_id, p.program_id
FROM (
  SELECT 'Current Computer Science Topics' AS category_name, 'BSCS' AS program_code UNION ALL
  SELECT 'Foundations of Computer Science', 'BSCS' UNION ALL
  SELECT 'Human-Computer Interaction', 'BSCS' UNION ALL
  SELECT 'Software Development', 'BSIT' UNION ALL
  SELECT 'Multimedia Systems', 'BSIT' UNION ALL
  SELECT 'Network Design and Implementation', 'BSIT' UNION ALL
  SELECT 'IT Management', 'BSIT'
) course_categories
JOIN `research_categories` rc ON rc.category_name = course_categories.category_name
JOIN `programs` p ON p.program_code = course_categories.program_code
LEFT JOIN `research_category_programs` existing ON existing.category_id = rc.category_id AND existing.program_id = p.program_id
WHERE existing.category_id IS NULL;

-- Independent faculty research records (migration 050).
-- Independent faculty metadata for future document generation; no workflow data is changed.
CREATE TABLE IF NOT EXISTS faculty_research_records (
    research_record_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    title TEXT NOT NULL,
    work_type VARCHAR(64) NOT NULL,
    work_type_label VARCHAR(120) NOT NULL,
    work_type_other VARCHAR(120) NULL,
    date_started CHAR(7) NULL,
    date_completed CHAR(7) NULL,
    conference_title VARCHAR(500) NULL,
    conference_host VARCHAR(500) NULL,
    conference_organizer VARCHAR(500) NULL,
    conference_classification ENUM('regional_national_local','international') NULL,
    conference_start_date DATE NULL,
    conference_end_date DATE NULL,
    presentation_modality ENUM('in_person','virtual') NULL,
    conference_venue VARCHAR(500) NULL,
    journal_title VARCHAR(500) NULL,
    journal_publisher VARCHAR(500) NULL,
    sdg_other VARCHAR(500) NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    deleted_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    creation_token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (research_record_id),
    UNIQUE KEY uq_faculty_record_creation (owner_id, creation_token_hash),
    KEY idx_faculty_records_updated (owner_id, deleted_at, status, updated_at, research_record_id),
    KEY idx_faculty_records_created (owner_id, deleted_at, status, created_at, research_record_id),
    CONSTRAINT fk_faculty_records_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_faculty_records_title CHECK (CHAR_LENGTH(TRIM(title)) BETWEEN 1 AND 2000),
    CONSTRAINT chk_faculty_records_months CHECK (
        (date_started IS NULL OR date_started REGEXP '^[1-9][0-9]{3}-(0[1-9]|1[0-2])$') AND
        (date_completed IS NULL OR date_completed REGEXP '^[1-9][0-9]{3}-(0[1-9]|1[0-2])$') AND
        (date_started IS NULL OR date_completed IS NULL OR date_completed >= date_started)
    ),
    CONSTRAINT chk_faculty_records_conference_dates CHECK (
        conference_start_date IS NULL OR conference_end_date IS NULL OR conference_end_date >= conference_start_date
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_research_record_authors (
    record_author_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    research_record_id INT UNSIGNED NOT NULL,
    faculty_user_id INT UNSIGNED NULL,
    author_type ENUM('internal','external') NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    position VARCHAR(255) NOT NULL,
    affiliation VARCHAR(500) NOT NULL,
    author_role ENUM('lead','coauthor') NOT NULL,
    display_order INT UNSIGNED NOT NULL,
    identity_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (record_author_id),
    UNIQUE KEY uq_faculty_record_author_user (research_record_id, faculty_user_id),
    UNIQUE KEY uq_faculty_record_author_identity (research_record_id, identity_key),
    UNIQUE KEY uq_faculty_record_author_order (research_record_id, display_order),
    KEY idx_faculty_record_author_user (faculty_user_id),
    CONSTRAINT fk_faculty_record_authors_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_faculty_record_authors_user FOREIGN KEY (faculty_user_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT chk_faculty_record_author_type CHECK (author_type = 'internal' OR faculty_user_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_research_record_sdgs (
    research_record_id INT UNSIGNED NOT NULL,
    sdg_number TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (research_record_id, sdg_number),
    CONSTRAINT fk_faculty_record_sdgs_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT chk_faculty_record_sdg_number CHECK (sdg_number BETWEEN 1 AND 17)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


-- Phase 4A: preparation only until explicitly approved for execution.
-- Additive storage; no accounts, research records, workflows or templates change.
-- The application must enforce live role/ownership checks and immutable snapshots.
-- Annex D scores remain exact decimal STRINGS inside versioned JSON, never FLOAT
-- or a fixed-scale DECIMAL. Decimal classification policy requires confirmation.

CREATE TABLE IF NOT EXISTS earist_document_drafts (
    draft_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    research_record_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    document_type ENUM('annex_a','annex_b','annex_c','annex_d','annex_e','annex_f','qsf_rds_019') NOT NULL,
    draft_revision INT UNSIGNED NOT NULL DEFAULT 1,
    source_record_version INT UNSIGNED NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    input_schema_version INT UNSIGNED NOT NULL,
    mapping_definition_version INT UNSIGNED NOT NULL,
    binding_version INT UNSIGNED NOT NULL,
    template_sha256 CHAR(64) NOT NULL,
    inputs_json LONGTEXT NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (draft_id),
    UNIQUE KEY uq_earist_draft_source (research_record_id, owner_id, document_type),
    KEY idx_earist_draft_owner_updated (owner_id, updated_at, draft_id),
    KEY idx_earist_draft_creator (created_by),
    KEY idx_earist_draft_updater (updated_by),
    CONSTRAINT fk_earist_draft_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_creator FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_updater FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_earist_draft_json CHECK (JSON_VALID(inputs_json)),
    CONSTRAINT chk_earist_draft_versions CHECK (draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0),
    CONSTRAINT chk_earist_draft_hashes CHECK (source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS earist_generated_documents (
    generated_document_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_id INT UNSIGNED NOT NULL,
    research_record_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    document_type ENUM('annex_a','annex_b','annex_c','annex_d','annex_e','annex_f','qsf_rds_019') NOT NULL,
    document_version INT UNSIGNED NOT NULL,
    draft_revision INT UNSIGNED NOT NULL,
    source_record_version INT UNSIGNED NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    input_schema_version INT UNSIGNED NOT NULL,
    mapping_definition_version INT UNSIGNED NOT NULL,
    binding_version INT UNSIGNED NOT NULL,
    renderer_version VARCHAR(32) NOT NULL,
    template_sha256 CHAR(64) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    missing_fields_json LONGTEXT NOT NULL,
    warnings_json LONGTEXT NOT NULL,
    warnings_acknowledged_at DATETIME NULL,
    preparation_mode ENUM('incomplete','prepared_for_printing') NOT NULL,
    generated_by INT UNSIGNED NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_token_hash CHAR(64) NOT NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    verified_payload_sha256 CHAR(64) NULL,
    docx_path VARCHAR(255) NOT NULL,
    docx_sha256 CHAR(64) NOT NULL,
    docx_size BIGINT UNSIGNED NOT NULL,
    file_retention_status ENUM('available','pruning','pruned') NOT NULL DEFAULT 'available',
    file_retention_started_at DATETIME NULL,
    file_retention_completed_at DATETIME NULL,
    file_retention_actor_id INT UNSIGNED NULL,
    file_retention_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    version_origin ENUM('generated','edited') NOT NULL DEFAULT 'generated',
    edited_from_generated_document_id INT UNSIGNED NULL,
    pdf_path VARCHAR(255) NULL,
    pdf_sha256 CHAR(64) NULL,
    pdf_size BIGINT UNSIGNED NULL,
    pdf_created_at DATETIME NULL,
    pdf_converter_version VARCHAR(120) NULL,
    PRIMARY KEY (generated_document_id),
    UNIQUE KEY uq_earist_generated_version (research_record_id, document_type, document_version),
    UNIQUE KEY uq_earist_generated_request (generated_by, request_token_hash),
    UNIQUE KEY uq_earist_generated_docx (docx_path),
    UNIQUE KEY uq_earist_generated_pdf (pdf_path),
    KEY idx_earist_generated_owner_record (owner_id, research_record_id, generated_at, generated_document_id),
    KEY idx_earist_generated_draft (draft_id),
    KEY idx_earist_generated_verifier (verified_by),
    KEY idx_earist_generated_type_date (document_type, generated_at, generated_document_id),
    KEY idx_earist_generated_edit_parent (edited_from_generated_document_id),
    KEY idx_earist_generated_retention (owner_id, research_record_id, document_type, file_retention_status, document_version),
    KEY idx_earist_generated_retention_actor (file_retention_actor_id),
    CONSTRAINT fk_earist_generated_draft FOREIGN KEY (draft_id) REFERENCES earist_document_drafts (draft_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_generator FOREIGN KEY (generated_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_verifier FOREIGN KEY (verified_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_edit_parent FOREIGN KEY (edited_from_generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_retention_actor FOREIGN KEY (file_retention_actor_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_earist_generated_json CHECK (JSON_VALID(snapshot_json) AND JSON_VALID(missing_fields_json) AND JSON_VALID(warnings_json)),
    CONSTRAINT chk_earist_generated_versions CHECK (document_version > 0 AND draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0),
    CONSTRAINT chk_earist_generated_hashes CHECK (source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$' AND request_token_hash REGEXP '^[0-9a-f]{64}$' AND docx_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_earist_generated_docx CHECK (CHAR_LENGTH(TRIM(docx_path)) > 0 AND docx_size > 0 AND CHAR_LENGTH(TRIM(renderer_version)) > 0),
    CONSTRAINT chk_earist_generated_warning_ack CHECK (JSON_LENGTH(warnings_json) = 0 OR warnings_acknowledged_at IS NOT NULL),
    CONSTRAINT chk_earist_generated_verification CHECK (
        (document_type = 'annex_d' AND preparation_mode = 'prepared_for_printing' AND verified_by IS NOT NULL AND verified_by = generated_by AND verified_at IS NOT NULL AND verified_payload_sha256 IS NOT NULL AND verified_payload_sha256 REGEXP '^[0-9a-f]{64}$')
        OR ((document_type <> 'annex_d' OR preparation_mode = 'incomplete') AND verified_by IS NULL AND verified_at IS NULL AND verified_payload_sha256 IS NULL)
    ),
    CONSTRAINT chk_earist_generated_pdf CHECK (
        (pdf_path IS NULL AND pdf_sha256 IS NULL AND pdf_size IS NULL AND pdf_created_at IS NULL AND pdf_converter_version IS NULL)
        OR (pdf_path IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_path)) > 0 AND pdf_sha256 IS NOT NULL AND pdf_sha256 REGEXP '^[0-9a-f]{64}$' AND pdf_size IS NOT NULL AND pdf_size > 0 AND pdf_created_at IS NOT NULL AND pdf_converter_version IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_converter_version)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS earist_document_edit_sessions (
    session_id CHAR(64) NOT NULL,
    generated_document_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    file_selector ENUM('primary','sdg_continuation') NOT NULL,
    editor_key CHAR(64) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    read_token_sha256 CHAR(64) NOT NULL,
    read_token_expires_at DATETIME NOT NULL,
    state ENUM('active','saved','closed','expired','failed') NOT NULL,
    active_lock_generated_document_id INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    saved_generated_document_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_earist_edit_active_lock (active_lock_generated_document_id),
    KEY idx_earist_edit_owner_state (owner_id,state,expires_at),
    KEY idx_earist_edit_generated (generated_document_id,created_at),
    CONSTRAINT fk_earist_edit_generated FOREIGN KEY (generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_edit_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_edit_active_lock FOREIGN KEY (active_lock_generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_edit_saved FOREIGN KEY (saved_generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT,
    CONSTRAINT chk_earist_edit_ids CHECK (session_id REGEXP '^[0-9a-f]{64}$' AND editor_key REGEXP '^[0-9a-f]{64}$' AND source_sha256 REGEXP '^[0-9a-f]{64}$' AND read_token_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_earist_edit_state CHECK ((state = 'active' AND active_lock_generated_document_id = generated_document_id AND saved_generated_document_id IS NULL) OR (state <> 'active' AND active_lock_generated_document_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `earist_google_drive_connections` (
    owner_id INT UNSIGNED NOT NULL,
    google_subject VARCHAR(255) NOT NULL,
    google_email VARCHAR(255) NOT NULL,
    refresh_token_ciphertext TEXT NOT NULL,
    connected_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (owner_id),
    CONSTRAINT fk_earist_google_connection_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `earist_google_drive_files` (
    drive_file_id VARCHAR(128) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    google_subject VARCHAR(255) NOT NULL,
    generated_document_id INT UNSIGNED NOT NULL,
    file_selector ENUM('primary','sdg_continuation') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    web_view_link VARCHAR(512) NOT NULL,
    drive_version VARCHAR(64) NULL,
    last_imported_drive_version VARCHAR(64) NULL,
    last_imported_generated_document_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_imported_at DATETIME NULL,
    PRIMARY KEY (drive_file_id),
    KEY idx_earist_google_file_owner_source (owner_id,generated_document_id,file_selector,created_at),
    KEY idx_earist_google_file_imported (last_imported_generated_document_id),
    CONSTRAINT fk_earist_google_file_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_google_file_source FOREIGN KEY (generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_google_file_imported FOREIGN KEY (last_imported_generated_document_id) REFERENCES earist_generated_documents (generated_document_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No seed data, triggers, grants, automatic cleanup or certificate-issuance state.
-- Rollback: disable the relevant feature and retain preparation inputs, generated
-- history, Drive metadata, and files. Do not DROP retained state automatically.

CREATE TABLE `student_project_assignments` (
  `student_id` int(10) unsigned NOT NULL,
  `project_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`student_id`),
  KEY `idx_student_project_assignments_project` (`project_id`),
  CONSTRAINT `fk_student_project_assignments_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_project_assignments_project` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
