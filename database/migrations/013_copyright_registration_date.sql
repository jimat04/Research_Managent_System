-- Migration 013: Record the date a copyright application is registered.
-- Existing registered records use updated_at as the best available historical date.

SET @registered_at_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'registered_at'
);

SET @registered_at_sql = IF(
  @registered_at_exists = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `registered_at` datetime DEFAULT NULL AFTER `copyright_ref_no`',
  'SELECT 1'
);
PREPARE registered_at_statement FROM @registered_at_sql;
EXECUTE registered_at_statement;
DEALLOCATE PREPARE registered_at_statement;

UPDATE `copyright_applications`
SET `registered_at` = `updated_at`
WHERE `status` = 'registered' AND `registered_at` IS NULL;
