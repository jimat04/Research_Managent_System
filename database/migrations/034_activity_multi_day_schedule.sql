-- Add date-level scheduling and attendee/attendance records without replacing
-- activity IDs or legacy activity_participants history.

ALTER TABLE `research_activities`
  ADD COLUMN `is_multi_day` tinyint(1) NOT NULL DEFAULT 0 AFTER `activity_time`,
  ADD COLUMN `schedule_start_date` date DEFAULT NULL AFTER `is_multi_day`,
  ADD COLUMN `schedule_end_date` date DEFAULT NULL AFTER `schedule_start_date`;

-- Keep the activity types/statuses used by the current capacity-building UI
-- available on databases created from the historical activity migrations.
ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum('seminar','training','workshop','colloquium','conference') NOT NULL,
  MODIFY COLUMN `status` enum('incoming','ongoing','conducted','cancelled','scheduled','completed') NOT NULL DEFAULT 'incoming';

CREATE TABLE IF NOT EXISTS `activity_schedule_dates` (
  `schedule_id` int unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` int unsigned NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `venue_override` varchar(255) DEFAULT NULL,
  `speaker_override` varchar(255) DEFAULT NULL,
  `audience_scope` enum('all','student','faculty','staff','specific') NOT NULL DEFAULT 'all',
  `status` enum('scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uq_activity_schedule_date` (`activity_id`,`schedule_date`),
  KEY `idx_activity_schedule_date_status` (`schedule_date`,`status`),
  CONSTRAINT `fk_activity_schedule_dates_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_schedule_participants` (
  `participant_id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `participant_name` varchar(255) DEFAULT NULL,
  `role` varchar(120) DEFAULT NULL COMMENT 'speaker/attendee/organizer',
  `attended` tinyint(1) NOT NULL DEFAULT 0,
  `is_invited` tinyint(1) NOT NULL DEFAULT 0,
  `is_notified` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Invitation notification is currently active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`participant_id`),
  UNIQUE KEY `uq_activity_schedule_participant_user` (`schedule_id`,`user_id`),
  KEY `idx_activity_schedule_participants_user` (`user_id`),
  CONSTRAINT `fk_activity_schedule_participants_date`
    FOREIGN KEY (`schedule_id`) REFERENCES `activity_schedule_dates` (`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill one schedule date for every existing activity. The previous module
-- had no date-level audience/notification history, so known participants are
-- the safest recipient scope; existing records are not treated as notified.
INSERT INTO `activity_schedule_dates`
  (`activity_id`, `schedule_date`, `start_time`, `audience_scope`, `status`)
SELECT ra.`activity_id`, ra.`activity_date`, ra.`activity_time`, 'specific',
       IF(ra.`status` = 'cancelled', 'cancelled', 'scheduled')
FROM `research_activities` ra
WHERE NOT EXISTS (
  SELECT 1 FROM `activity_schedule_dates` d
  WHERE d.`activity_id` = ra.`activity_id` AND d.`schedule_date` = ra.`activity_date`
);

UPDATE `research_activities`
SET `schedule_start_date` = `activity_date`,
    `schedule_end_date` = `activity_date`,
    `is_multi_day` = 0
WHERE `schedule_start_date` IS NULL OR `schedule_end_date` IS NULL;

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
  WHERE sp.`schedule_id` = d.`schedule_id`
    AND sp.`user_id` <=> ap.`user_id`
    AND COALESCE(sp.`participant_name`, '') = COALESCE(ap.`participant_name`, '')
)
GROUP BY d.`schedule_id`, ap.`user_id`,
         CASE WHEN ap.`user_id` IS NULL THEN COALESCE(ap.`participant_name`, '') ELSE '' END;
