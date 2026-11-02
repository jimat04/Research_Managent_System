-- Store activity budgets, schedule-linked expense lines, contribution responses,
-- budget revision snapshots, and explicitly confirmed idempotent notices.

ALTER TABLE `activity_schedule_dates`
  ADD UNIQUE KEY `uq_activity_schedule_activity_schedule` (`activity_id`,`schedule_id`);

ALTER TABLE `notifications`
  ADD COLUMN `activity_budget_delivery_key` char(64) DEFAULT NULL AFTER `link`,
  ADD UNIQUE KEY `uq_notifications_budget_delivery` (`activity_budget_delivery_key`);

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
