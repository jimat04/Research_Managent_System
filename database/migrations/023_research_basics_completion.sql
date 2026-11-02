SET @research_basics_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'research_basics_completed'
);

SET @research_basics_column_sql = IF(
  @research_basics_column_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `research_basics_completed` tinyint(1) NOT NULL DEFAULT 0 AFTER `deleted_at`',
  'SELECT 1'
);
PREPARE research_basics_column_statement FROM @research_basics_column_sql;
EXECUTE research_basics_column_statement;
DEALLOCATE PREPARE research_basics_column_statement;
