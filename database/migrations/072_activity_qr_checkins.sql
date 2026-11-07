-- Add date-specific QR check-in credentials and deduplicated attendance records.

ALTER TABLE `activity_schedule_dates`
  ADD COLUMN `checkin_token_hash` char(64) DEFAULT NULL AFTER `status`,
  ADD UNIQUE KEY `uq_activity_schedule_checkin_token` (`checkin_token_hash`);

ALTER TABLE `users`
  ADD KEY `idx_users_student_id` (`student_id`);

CREATE TABLE `activity_schedule_checkins` (
  `checkin_id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `participant_id` int unsigned NOT NULL,
  `identity_hash` char(64) NOT NULL,
  `checked_in_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`checkin_id`),
  UNIQUE KEY `uq_activity_schedule_checkin_identity` (`schedule_id`,`identity_hash`),
  KEY `idx_activity_schedule_checkins_participant` (`participant_id`),
  CONSTRAINT `fk_activity_schedule_checkins_date`
    FOREIGN KEY (`schedule_id`) REFERENCES `activity_schedule_dates` (`schedule_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_schedule_checkins_participant`
    FOREIGN KEY (`participant_id`) REFERENCES `activity_schedule_participants` (`participant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback: remove the check-in table and token column after disabling check-in links.
