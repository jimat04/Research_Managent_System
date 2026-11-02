-- Rename the pre-event activity status while preserving all existing activity rows.
ALTER TABLE `research_activities`
  MODIFY COLUMN `status` enum('incoming','upcoming','ongoing','conducted','cancelled','scheduled','completed') NOT NULL DEFAULT 'incoming';

UPDATE `research_activities`
   SET `status` = 'upcoming'
 WHERE `status` = 'incoming';

ALTER TABLE `research_activities`
  MODIFY COLUMN `status` enum('upcoming','ongoing','conducted','cancelled','scheduled','completed') NOT NULL DEFAULT 'upcoming';
