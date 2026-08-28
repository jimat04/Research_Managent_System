-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 31, 2026 at 08:23 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `ay_id` int(10) UNSIGNED NOT NULL,
  `label` varchar(20) NOT NULL COMMENT 'e.g. 2024-2025',
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`ay_id`, `label`, `semester`, `is_active`, `created_at`) VALUES
(1, '2023-2024', '1st', 0, '2026-05-30 17:49:59'),
(2, '2023-2024', '2nd', 0, '2026-05-30 17:49:59'),
(3, '2024-2025', '1st', 0, '2026-05-30 17:49:59'),
(4, '2024-2025', '2nd', 1, '2026-05-30 17:49:59');



CREATE TABLE `activity_log` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `module` varchar(80) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `module`, `ip_address`, `created_at`) VALUES
(1, 4, 'User logged in', 'authentication', '::1', '2026-05-30 18:10:36'),
(2, 4, 'User logged out', 'authentication', '::1', '2026-05-30 18:11:06'),
(3, 2, 'User logged in', 'authentication', '::1', '2026-05-30 18:11:19'),
(5, 1, 'User logged in', 'authentication', '::1', '2026-05-30 18:11:48'),
(8, 4, 'User logged out', 'authentication', '::1', '2026-05-30 18:18:08'),
(9, 2, 'User logged in', 'authentication', '::1', '2026-05-30 18:18:16'),
(10, 2, 'User logged out', 'authentication', '::1', '2026-05-30 18:18:31'),
(12, 1, 'User logged out', 'authentication', '::1', '2026-05-30 18:23:21'),
(15, 2, 'User logged in', 'authentication', '::1', '2026-05-30 18:58:48'),
(16, 2, 'User logged out', 'authentication', '::1', '2026-05-30 18:58:50'),
(17, 2, 'User logged in', 'authentication', '::1', '2026-05-30 18:59:30'),
(19, 1, 'User logged in', 'authentication', '::1', '2026-05-30 18:59:46'),
(20, 1, 'User logged out', 'authentication', '::1', '2026-05-30 19:00:00');

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `subject` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_recipient_read` (`recipient_id`,`is_read`),
  ADD KEY `idx_sender` (`sender_id`);

-- --------------------------------------------------------

--
-- AUTO_INCREMENT for table `messages`
--

ALTER TABLE `messages`
  MODIFY `message_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Constraints for table `messages`
--

ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

-- --------------------------------------------------------
--
-- Table structure for table `chapters`
--

CREATE TABLE `chapters` (
  `chapter_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `chapter_number` tinyint(3) UNSIGNED NOT NULL COMMENT '1-5',
  `chapter_title` varchar(200) NOT NULL,
  `status` enum('draft','submitted','under_review','revision_required','approved') NOT NULL DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `version` tinyint(3) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chapter_content`
--

CREATE TABLE `chapter_content` (
  `content_id` int(10) UNSIGNED NOT NULL,
  `chapter_id` int(10) UNSIGNED NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `comment_id` int(10) UNSIGNED NOT NULL,
  `chapter_id` int(10) UNSIGNED NOT NULL,
  `faculty_id` int(10) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `type` enum('general','suggestion','correction','approval') NOT NULL DEFAULT 'general',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `defense_schedule`
--

CREATE TABLE `defense_schedule` (
  `defense_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `schedule_date` datetime NOT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `type` enum('proposal','pre_oral','final') NOT NULL DEFAULT 'final',
  `status` enum('scheduled','done','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
  `remarks` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `dept_id` int(10) UNSIGNED NOT NULL,
  `dept_code` varchar(20) NOT NULL,
  `dept_name` varchar(150) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`dept_id`, `dept_code`, `dept_name`, `status`) VALUES
(1, 'CCS', 'College of Computer Studies', 1),
(2, 'CAS', 'College of Arts and Sciences', 1),
(3, 'COE', 'College of Engineering', 1),
(4, 'CBA', 'College of Business Administration', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(150) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program_id`, `dept_id`, `program_code`, `program_name`, `status`) VALUES
(1, 1, 'BSIT', 'Bachelor of Science in Information Technology', 1),
(2, 1, 'BSCS', 'Bachelor of Science in Computer Science', 1),
(3, 1, 'BSIS', 'Bachelor of Science in Information Systems', 1),
(4, 2, 'BSBio', 'Bachelor of Science in Biology', 1),
(5, 3, 'BSCE', 'Bachelor of Science in Civil Engineering', 1);

-- --------------------------------------------------------

--
-- Table structure for table `project_advisers`
--

CREATE TABLE `project_advisers` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `adviser_id` int(10) UNSIGNED NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_members`
--

CREATE TABLE `project_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('lead','member') NOT NULL DEFAULT 'member'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `research_categories`
--

CREATE TABLE `research_categories` (
  `category_id` int(10) UNSIGNED NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `research_categories`
--

INSERT INTO `research_categories` (`category_id`, `category_name`, `description`, `status`) VALUES
(1, 'Applied Research', 'Research directed toward practical applications', 1),
(2, 'Basic Research', 'Research aimed at expanding knowledge', 1),
(3, 'Action Research', 'Research to solve a specific practical issue', 1),
(4, 'Developmental Research', 'Research focused on developing new products/systems', 1),
(5, 'Evaluation Research', 'Research that measures effectiveness of programs', 1);

-- --------------------------------------------------------

--
-- Table structure for table `research_projects`
--

CREATE TABLE `research_projects` (
  `project_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `ay_id` int(10) UNSIGNED DEFAULT NULL,
  `research_area` varchar(150) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `status` enum('draft','proposal','in_progress','for_defense','completed','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(10) UNSIGNED NOT NULL COMMENT 'student user_id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uploads`
--

CREATE TABLE `uploads` (
  `upload_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `chapter_id` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `type` enum('proposal','chapter','defense','revision','manuscript','other') NOT NULL DEFAULT 'other',
  `original_name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `research_documents`
--

CREATE TABLE `research_documents` (
  `document_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `upload_id` int(10) UNSIGNED DEFAULT NULL,
  `document_type` enum('proposal','revision_checklist','defense_material','mou','nda','progress_report','terminal_report','final_bound_report','publication_record','other') NOT NULL DEFAULT 'other',
  `status` enum('pending','submitted','approved','rejected','waived') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `submitted_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `research_reports`
--

CREATE TABLE `research_reports` (
  `report_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `document_id` int(10) UNSIGNED DEFAULT NULL,
  `report_type` enum('midway_progress','terminal') NOT NULL,
  `status` enum('draft','submitted','under_review','revision_required','approved','rejected') NOT NULL DEFAULT 'draft',
  `summary` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `research_publication_tracking`
--

CREATE TABLE `research_publication_tracking` (
  `publication_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `colloquium_date` datetime DEFAULT NULL,
  `colloquium_status` enum('not_scheduled','scheduled','presented','cancelled') NOT NULL DEFAULT 'not_scheduled',
  `journal_status` enum('not_submitted','submitted','under_review','accepted','published','rejected') NOT NULL DEFAULT 'not_submitted',
  `journal_reference` varchar(255) DEFAULT NULL,
  `archive_status` enum('not_archived','ready','archived') NOT NULL DEFAULT 'not_archived',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('student','faculty','admin') NOT NULL DEFAULT 'student',
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL COMMENT 'school ID / employee ID',
  `department` varchar(120) DEFAULT NULL,
  `program` varchar(120) DEFAULT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'pending',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `password`, `student_id`, `department`, `program`, `contact`, `avatar`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'System', 'Administrator', 'admin@rms.edu.ph', '$2y$12$1UzhJiJbBSdUw.3bEMUg/ub1Dx55.kgrVAQiphCfUKwe8ywCM9XCO', NULL, NULL, NULL, NULL, NULL, 'active', '2026-05-31 02:59:46', '2026-05-30 17:49:59', '2026-05-30 18:59:46'),
(2, 'faculty', 'Maria', 'Santos', 'msantos@rms.edu.ph', '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG', NULL, 'College of Computer Studies', NULL, NULL, NULL, 'active', '2026-05-31 02:59:30', '2026-05-30 17:49:59', '2026-05-30 18:59:30'),
(3, 'faculty', 'Jose', 'Reyes', 'jreyes@rms.edu.ph', '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG', NULL, 'College of Computer Studies', NULL, NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54'),
(4, 'student', 'Juan', 'Dela Cruz', 'jdelacruz@rms.edu.ph', '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK', '2024-00001', 'College of Computer Studies', 'BSIT', NULL, NULL, 'active', '2026-05-31 02:58:09', '2026-05-30 17:49:59', '2026-05-30 18:58:09'),
(5, 'student', 'Anna', 'Reyes', 'areyes@rms.edu.ph', '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK', '2024-00002', 'College of Computer Studies', 'BSIT', NULL, NULL, 'active', NULL, '2026-05-30 17:49:59', '2026-05-30 18:57:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`ay_id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `chapters`
--
ALTER TABLE `chapters`
  ADD PRIMARY KEY (`chapter_id`),
  ADD UNIQUE KEY `uk_proj_chap` (`project_id`,`chapter_number`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `chapter_content`
--
ALTER TABLE `chapter_content`
  ADD PRIMARY KEY (`content_id`),
  ADD UNIQUE KEY `chapter_id` (`chapter_id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `chapter_id` (`chapter_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `defense_schedule`
--
ALTER TABLE `defense_schedule`
  ADD PRIMARY KEY (`defense_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`dept_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD KEY `dept_id` (`dept_id`);

--
-- Indexes for table `project_advisers`
--
ALTER TABLE `project_advisers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_proj_adv` (`project_id`,`adviser_id`),
  ADD KEY `adviser_id` (`adviser_id`);

--
-- Indexes for table `project_members`
--
ALTER TABLE `project_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_proj_user` (`project_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `research_categories`
--
ALTER TABLE `research_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `research_projects`
--
ALTER TABLE `research_projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `ay_id` (`ay_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `uploads`
--
ALTER TABLE `uploads`
  ADD PRIMARY KEY (`upload_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `chapter_id` (`chapter_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `research_documents`
--
ALTER TABLE `research_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_research_documents_project_type` (`project_id`,`document_type`),
  ADD KEY `idx_research_documents_status` (`status`),
  ADD KEY `upload_id` (`upload_id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `research_reports`
--
ALTER TABLE `research_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_research_reports_project_type` (`project_id`,`report_type`),
  ADD KEY `idx_research_reports_status` (`status`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `research_publication_tracking`
--
ALTER TABLE `research_publication_tracking`
  ADD PRIMARY KEY (`publication_id`),
  ADD UNIQUE KEY `project_id` (`project_id`),
  ADD KEY `idx_publication_colloquium_status` (`colloquium_status`),
  ADD KEY `idx_publication_journal_status` (`journal_status`),
  ADD KEY `idx_publication_archive_status` (`archive_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `ay_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `chapters`
--
ALTER TABLE `chapters`
  MODIFY `chapter_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chapter_content`
--
ALTER TABLE `chapter_content`
  MODIFY `content_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `comment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `defense_schedule`
--
ALTER TABLE `defense_schedule`
  MODIFY `defense_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `dept_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `project_advisers`
--
ALTER TABLE `project_advisers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_members`
--
ALTER TABLE `project_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `research_categories`
--
ALTER TABLE `research_categories`
  MODIFY `category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `research_projects`
--
ALTER TABLE `research_projects`
  MODIFY `project_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uploads`
--
ALTER TABLE `uploads`
  MODIFY `upload_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `research_documents`
--
ALTER TABLE `research_documents`
  MODIFY `document_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `research_reports`
--
ALTER TABLE `research_reports`
  MODIFY `report_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `research_publication_tracking`
--
ALTER TABLE `research_publication_tracking`
  MODIFY `publication_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `chapters`
--
ALTER TABLE `chapters`
  ADD CONSTRAINT `chapters_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chapters_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `chapter_content`
--
ALTER TABLE `chapter_content`
  ADD CONSTRAINT `chapter_content_ibfk_1` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `defense_schedule`
--
ALTER TABLE `defense_schedule`
  ADD CONSTRAINT `defense_schedule_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `defense_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE;

--
-- Constraints for table `project_advisers`
--
ALTER TABLE `project_advisers`
  ADD CONSTRAINT `project_advisers_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_advisers_ibfk_2` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `project_members`
--
ALTER TABLE `project_members`
  ADD CONSTRAINT `project_members_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `research_projects`
--
ALTER TABLE `research_projects`
  ADD CONSTRAINT `research_projects_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `research_categories` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_projects_ibfk_2` FOREIGN KEY (`ay_id`) REFERENCES `academic_years` (`ay_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_projects_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `uploads`
--
ALTER TABLE `uploads`
  ADD CONSTRAINT `uploads_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `uploads_ibfk_2` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`chapter_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `uploads_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `research_documents`
--
ALTER TABLE `research_documents`
  ADD CONSTRAINT `research_documents_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `research_documents_ibfk_2` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_documents_ibfk_3` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_documents_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `research_reports`
--
ALTER TABLE `research_reports`
  ADD CONSTRAINT `research_reports_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `research_reports_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `research_documents` (`document_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_reports_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `research_publication_tracking`
--
ALTER TABLE `research_publication_tracking`
  ADD CONSTRAINT `research_publication_tracking_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
