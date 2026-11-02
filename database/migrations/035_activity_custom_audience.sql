ALTER TABLE `activity_schedule_dates`
  MODIFY COLUMN `audience_scope` enum('all','student','faculty','staff','specific','custom') NOT NULL DEFAULT 'all',
  ADD COLUMN `audience_roles` JSON DEFAULT NULL AFTER `audience_scope`,
  ADD COLUMN `audience_user_ids` JSON DEFAULT NULL AFTER `audience_roles`;
