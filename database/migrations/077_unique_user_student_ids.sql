-- Treat blank optional IDs as absent before enforcing uniqueness. Existing
-- nonblank duplicates must be reviewed and resolved before this migration.
UPDATE `users`
   SET `student_id` = NULL
 WHERE `student_id` IS NOT NULL
   AND TRIM(`student_id`) = '';

ALTER TABLE `users`
  DROP INDEX `idx_users_student_id`,
  ADD UNIQUE KEY `uq_users_student_id` (`student_id`);
