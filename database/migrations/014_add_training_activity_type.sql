-- Migration 014: Store trainings separately from workshops.
ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum('seminar','training','presentation','workshop','forum') NOT NULL;
