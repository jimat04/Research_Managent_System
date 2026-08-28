-- Migration: Add contact_messages table for public contact form
-- Date: 2026-08-28
-- Purpose: Store contact form submissions and enable role-based message routing

CREATE TABLE IF NOT EXISTS `contact_messages` (
  `contact_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `email` varchar(160) NOT NULL,
  `concern_type` varchar(80) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','resolved','archived') NOT NULL DEFAULT 'pending',
  `resolved_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'user_id who resolved it',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add index to messages table for system-generated messages (sender_id = 0)
CREATE INDEX IF NOT EXISTS `idx_sender_system` ON `messages` (`sender_id`);
