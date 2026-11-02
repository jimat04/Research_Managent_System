-- Add an independent, opt-in download grant to each staff module/subpage row.
-- Existing rows retain download_allowed = 0; module access never grants downloads.

CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `permission_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `subpage_key` varchar(64) NOT NULL DEFAULT '',
  `access_level` enum('none','view','manage') NOT NULL DEFAULT 'none',
  `download_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_staff_permissions_user_module_subpage` (`user_id`,`module_key`,`subpage_key`),
  KEY `idx_staff_permissions_user` (`user_id`),
  KEY `idx_staff_permissions_module` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @staff_download_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'staff_permissions'
    AND COLUMN_NAME = 'download_allowed'
);

SET @staff_download_column_sql = IF(
  @staff_download_column_exists = 0,
  'ALTER TABLE `staff_permissions` ADD COLUMN `download_allowed` tinyint(1) NOT NULL DEFAULT 0 AFTER `access_level`',
  'SELECT 1'
);
PREPARE staff_download_column_statement FROM @staff_download_column_sql;
EXECUTE staff_download_column_statement;
DEALLOCATE PREPARE staff_download_column_statement;
