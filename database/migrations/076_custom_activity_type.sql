-- Add a general activity bucket with a separately stored, user-entered label.
ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum(
    'seminar_workshop','training','colloquium','conference_congress','other'
  ) NOT NULL,
  ADD COLUMN `custom_activity_type` varchar(100) DEFAULT NULL AFTER `activity_type`;
