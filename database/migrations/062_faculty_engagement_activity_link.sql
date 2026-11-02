ALTER TABLE `faculty_engagement_records`
  ADD COLUMN `activity_id` int(10) unsigned DEFAULT NULL AFTER `faculty_id`,
  ADD KEY `idx_faculty_engagement_activity` (`activity_id`),
  ADD CONSTRAINT `fk_faculty_engagement_activity`
    FOREIGN KEY (`activity_id`) REFERENCES `research_activities` (`activity_id`) ON DELETE SET NULL;
