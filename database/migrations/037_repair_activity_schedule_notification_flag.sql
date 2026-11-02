-- Complete the schedule participant schema when the original multi-day
-- migration was only partially applied on an existing RMS installation.
ALTER TABLE `activity_schedule_participants`
  ADD COLUMN `is_notified` tinyint(1) NOT NULL DEFAULT 0
    COMMENT 'Invitation notification is currently active' AFTER `is_invited`;
