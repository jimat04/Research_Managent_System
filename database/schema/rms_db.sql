-- Canonical fresh-install schema - matches live DB as of 2026-09 including migrations 002-012.
-- For LEGACY databases use the numbered migrations instead.

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
  `message_id` int(10) unsigned NOT NULL,
  `sender_id` int(10) unsigned NOT NULL,
  `recipient_id` int(10) unsigned NOT NULL,
  `subject` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_sender_system` (`sender_id`)
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
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
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
  `category_id` int(10) unsigned DEFAULT NULL,
  `ay_id` int(10) unsigned DEFAULT NULL,
  `research_area` varchar(150) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `status` enum('draft','proposal','submitted','under_review','under_crec_review','under_erec_review','for_revision','revision_required','rejected','approved','ongoing','progress_report','terminal_review','completed','archived') NOT NULL DEFAULT 'draft',
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
  `project_id` int(10) unsigned NOT NULL,
  `chapter_id` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `type` enum('proposal','chapter','defense','revision','manuscript','other') NOT NULL DEFAULT 'other',
  `original_name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('student','faculty','research_staff','admin') NOT NULL DEFAULT 'student',
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL COMMENT 'school ID / employee ID',
  `department` varchar(120) DEFAULT NULL,
  `office` varchar(120) DEFAULT NULL COMMENT 'Office assignment for staff',
  `specialization` varchar(120) DEFAULT NULL COMMENT 'Faculty field of expertise',
  `academic_rank` enum('Instructor','Assistant Professor','Associate Professor','Professor','Dean','Director') DEFAULT NULL COMMENT 'Faculty academic rank',
  `is_reviewer` tinyint(1) DEFAULT 0 COMMENT 'Can participate in CREC/EREC review',
  `program` varchar(120) DEFAULT NULL,
  `year_level` enum('1st','2nd','3rd','4th','Graduate','Masters','Doctorate') DEFAULT NULL COMMENT 'Student year level',
  `contact` varchar(30) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `status` enum('active','pending','suspended') NOT NULL DEFAULT 'pending',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_role_status` (`role`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ---------------------------------------------------------------------------
-- Research activity, publication, and copyright modules (Migration 012)
-- User ID columns are indexed soft references; there are no user foreign keys.
-- ---------------------------------------------------------------------------

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
(5, 'Evaluation Research', 'Research that measures effectiveness of programs', 1, '2026-09-01 09:53:43', '2026-09-01 09:53:43');

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
(6, 'research_staff', 'EREC', 'Staff', 'EREC@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'EREC Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(7, 'research_staff', 'CREC', 'Staff', 'CREC@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'CREC Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(8, 'research_staff', 'ORS', 'Staff', 'ORS@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'Office of Research Services', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL),
(9, 'research_staff', 'Graduate', 'Staff', 'graduate@rms.edu.ph', '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq', NULL, NULL, 'Graduate School Office', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54', NULL);

-- DEMO: Research activity records for fresh-install previews.
INSERT INTO `research_activities` (`activity_id`, `activity_type`, `title`, `activity_date`, `activity_time`, `venue`, `speaker`, `organizer`, `description`, `status`, `created_by`) VALUES
(1, 'seminar', 'Responsible Research and Publication Ethics', '2026-10-08', '09:00:00', 'EARIST Cavite Audio-Visual Room', 'Dr. Maria Santos', 'Office of Research Services', 'Orientation on ethical research conduct and responsible publication.', 'scheduled', 8),
(2, 'workshop', 'Intellectual Property Documentation Workshop', '2026-08-21', '13:30:00', 'Research and Innovation Center', 'Engr. Jose Reyes', 'CREC', 'Hands-on preparation of copyright application materials.', 'completed', 7),
(3, 'forum', 'Student Research Forum 2026', '2026-11-14', NULL, 'Campus Multipurpose Hall', NULL, 'Graduate School Office', 'Interdisciplinary student research exchange.', 'scheduled', 9);

-- DEMO: Activity participant records, including linked users and a named guest.
INSERT INTO `activity_participants` (`participant_id`, `activity_id`, `user_id`, `participant_name`, `role`, `attended`) VALUES
(1, 1, 2, 'Maria Santos', 'speaker', 0),
(2, 2, 4, 'Juan Dela Cruz', 'attendee', 1),
(3, 3, NULL, 'Ana Villanueva', 'organizer', 0);

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
