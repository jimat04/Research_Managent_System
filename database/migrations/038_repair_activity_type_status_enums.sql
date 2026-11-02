-- Complete the activity enum expansion from migration 034 on installations
-- where its columns existed but its enum alteration did not.
ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum('seminar','training','workshop','colloquium','conference') NOT NULL,
  MODIFY COLUMN `status` enum('incoming','ongoing','conducted','cancelled','scheduled','completed') NOT NULL DEFAULT 'incoming';
