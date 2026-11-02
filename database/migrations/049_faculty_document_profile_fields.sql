ALTER TABLE `users`
  ADD COLUMN `middle_name` varchar(80) DEFAULT NULL COMMENT 'Optional faculty middle name or initial' AFTER `first_name`,
  ADD COLUMN `designation` varchar(150) DEFAULT NULL COMMENT 'Optional faculty designation' AFTER `academic_rank`,
  ADD COLUMN `appointment_status` varchar(80) DEFAULT NULL COMMENT 'Optional faculty employment or appointment status; separate from RMS account status' AFTER `designation`;
