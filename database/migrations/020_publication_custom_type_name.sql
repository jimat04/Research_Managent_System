-- Preserve the user-provided label when a publication uses the "Other" type.
SET @publication_type_name_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publications'
    AND COLUMN_NAME = 'publication_type_name'
);

SET @publication_type_name_sql = IF(
  @publication_type_name_exists = 0,
  'ALTER TABLE `publications` ADD COLUMN `publication_type_name` varchar(120) DEFAULT NULL AFTER `publication_type`',
  'SELECT 1'
);
PREPARE publication_type_name_statement FROM @publication_type_name_sql;
EXECUTE publication_type_name_statement;
DEALLOCATE PREPARE publication_type_name_statement;
