-- Migration 015: Explicit intellectual-property classifications and project links
--
-- This migration is additive and intentionally does not reclassify existing rows.
-- Existing software/module/copyright records remain unchanged until a user confirms
-- their actual classification.

-- copyright_applications is the existing general output-application registry. Add
-- explicit classifications while preserving the legacy values used by existing data.
SET @output_type_has_new_values = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'output_type'
    AND COLUMN_TYPE LIKE '%patent%'
    AND COLUMN_TYPE LIKE '%utility_model%'
    AND COLUMN_TYPE LIKE '%innovation%'
    AND COLUMN_TYPE LIKE '%technology_transfer%'
);

SET @output_type_sql = IF(
  @output_type_has_new_values = 0,
  'ALTER TABLE `copyright_applications` MODIFY COLUMN `output_type` enum(''software'',''research'',''instructional_material'',''module'',''other'',''patent'',''utility_model'',''innovation'',''technology_transfer'') NOT NULL DEFAULT ''research''',
  'SELECT 1'
);
PREPARE output_type_statement FROM @output_type_sql;
EXECUTE output_type_statement;
DEALLOCATE PREPARE output_type_statement;

SET @copyright_project_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'research_project_id'
);
SET @copyright_project_sql = IF(
  @copyright_project_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `research_project_id` int unsigned DEFAULT NULL AFTER `applicant_category`',
  'SELECT 1'
);
PREPARE copyright_project_statement FROM @copyright_project_sql;
EXECUTE copyright_project_statement;
DEALLOCATE PREPARE copyright_project_statement;

SET @copyright_publication_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'publication_id'
);
SET @copyright_publication_sql = IF(
  @copyright_publication_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `publication_id` int unsigned DEFAULT NULL AFTER `research_project_id`',
  'SELECT 1'
);
PREPARE copyright_publication_statement FROM @copyright_publication_sql;
EXECUTE copyright_publication_statement;
DEALLOCATE PREPARE copyright_publication_statement;

SET @copyright_application_number_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'application_number'
);
SET @copyright_application_number_sql = IF(
  @copyright_application_number_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `application_number` varchar(120) DEFAULT NULL AFTER `copyright_ref_no`',
  'SELECT 1'
);
PREPARE copyright_application_number_statement FROM @copyright_application_number_sql;
EXECUTE copyright_application_number_statement;
DEALLOCATE PREPARE copyright_application_number_statement;

SET @copyright_filing_date_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'filing_date'
);
SET @copyright_filing_date_sql = IF(
  @copyright_filing_date_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `filing_date` date DEFAULT NULL AFTER `application_number`',
  'SELECT 1'
);
PREPARE copyright_filing_date_statement FROM @copyright_filing_date_sql;
EXECUTE copyright_filing_date_statement;
DEALLOCATE PREPARE copyright_filing_date_statement;

SET @copyright_inventors_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'inventors'
);
SET @copyright_inventors_sql = IF(
  @copyright_inventors_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `inventors` varchar(500) DEFAULT NULL AFTER `co_authors`',
  'SELECT 1'
);
PREPARE copyright_inventors_statement FROM @copyright_inventors_sql;
EXECUTE copyright_inventors_statement;
DEALLOCATE PREPARE copyright_inventors_statement;

SET @copyright_partners_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'potential_partners'
);
SET @copyright_partners_sql = IF(
  @copyright_partners_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `potential_partners` varchar(500) DEFAULT NULL AFTER `inventors`',
  'SELECT 1'
);
PREPARE copyright_partners_statement FROM @copyright_partners_sql;
EXECUTE copyright_partners_statement;
DEALLOCATE PREPARE copyright_partners_statement;

SET @copyright_activities_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'transfer_activities'
);
SET @copyright_activities_sql = IF(
  @copyright_activities_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `transfer_activities` text DEFAULT NULL AFTER `potential_partners`',
  'SELECT 1'
);
PREPARE copyright_activities_statement FROM @copyright_activities_sql;
EXECUTE copyright_activities_statement;
DEALLOCATE PREPARE copyright_activities_statement;

SET @copyright_agreement_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'agreement_reference'
);
SET @copyright_agreement_sql = IF(
  @copyright_agreement_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `agreement_reference` varchar(120) DEFAULT NULL AFTER `transfer_activities`',
  'SELECT 1'
);
PREPARE copyright_agreement_statement FROM @copyright_agreement_sql;
EXECUTE copyright_agreement_statement;
DEALLOCATE PREPARE copyright_agreement_statement;

SET @copyright_utilization_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND COLUMN_NAME = 'utilization_status'
);
SET @copyright_utilization_sql = IF(
  @copyright_utilization_column = 0,
  'ALTER TABLE `copyright_applications` ADD COLUMN `utilization_status` varchar(120) DEFAULT NULL AFTER `agreement_reference`',
  'SELECT 1'
);
PREPARE copyright_utilization_statement FROM @copyright_utilization_sql;
EXECUTE copyright_utilization_statement;
DEALLOCATE PREPARE copyright_utilization_statement;

-- Add the project relationship to publication records. NULL remains valid for
-- legacy or externally sourced outputs whose origin is not confirmed.
SET @publication_project_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publications'
    AND COLUMN_NAME = 'research_project_id'
);
SET @publication_project_sql = IF(
  @publication_project_column = 0,
  'ALTER TABLE `publications` ADD COLUMN `research_project_id` int unsigned DEFAULT NULL AFTER `researcher_id`',
  'SELECT 1'
);
PREPARE publication_project_statement FROM @publication_project_sql;
EXECUTE publication_project_statement;
DEALLOCATE PREPARE publication_project_statement;

SET @publication_project_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publications'
    AND INDEX_NAME = 'idx_publications_research_project_id'
);
SET @publication_project_index_sql = IF(
  @publication_project_index = 0,
  'ALTER TABLE `publications` ADD KEY `idx_publications_research_project_id` (`research_project_id`)',
  'SELECT 1'
);
PREPARE publication_project_index_statement FROM @publication_project_index_sql;
EXECUTE publication_project_index_statement;
DEALLOCATE PREPARE publication_project_index_statement;

SET @copyright_project_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND INDEX_NAME = 'idx_copyright_applications_research_project_id'
);
SET @copyright_project_index_sql = IF(
  @copyright_project_index = 0,
  'ALTER TABLE `copyright_applications` ADD KEY `idx_copyright_applications_research_project_id` (`research_project_id`)',
  'SELECT 1'
);
PREPARE copyright_project_index_statement FROM @copyright_project_index_sql;
EXECUTE copyright_project_index_statement;
DEALLOCATE PREPARE copyright_project_index_statement;

SET @copyright_publication_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND INDEX_NAME = 'idx_copyright_applications_publication_id'
);
SET @copyright_publication_index_sql = IF(
  @copyright_publication_index = 0,
  'ALTER TABLE `copyright_applications` ADD KEY `idx_copyright_applications_publication_id` (`publication_id`)',
  'SELECT 1'
);
PREPARE copyright_publication_index_statement FROM @copyright_publication_index_sql;
EXECUTE copyright_publication_index_statement;
DEALLOCATE PREPARE copyright_publication_index_statement;

-- Foreign keys protect confirmed relationships while allowing legacy rows to
-- remain NULL. SET NULL preserves the application record if a source is removed.
SET @publication_project_fk = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'publications'
    AND CONSTRAINT_NAME = 'fk_publications_research_project'
);
SET @publication_project_fk_sql = IF(
  @publication_project_fk = 0,
  'ALTER TABLE `publications` ADD CONSTRAINT `fk_publications_research_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE publication_project_fk_statement FROM @publication_project_fk_sql;
EXECUTE publication_project_fk_statement;
DEALLOCATE PREPARE publication_project_fk_statement;

SET @copyright_project_fk = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND CONSTRAINT_NAME = 'fk_copyright_applications_research_project'
);
SET @copyright_project_fk_sql = IF(
  @copyright_project_fk = 0,
  'ALTER TABLE `copyright_applications` ADD CONSTRAINT `fk_copyright_applications_research_project` FOREIGN KEY (`research_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE copyright_project_fk_statement FROM @copyright_project_fk_sql;
EXECUTE copyright_project_fk_statement;
DEALLOCATE PREPARE copyright_project_fk_statement;

SET @copyright_publication_fk = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'copyright_applications'
    AND CONSTRAINT_NAME = 'fk_copyright_applications_publication'
);
SET @copyright_publication_fk_sql = IF(
  @copyright_publication_fk = 0,
  'ALTER TABLE `copyright_applications` ADD CONSTRAINT `fk_copyright_applications_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE copyright_publication_fk_statement FROM @copyright_publication_fk_sql;
EXECUTE copyright_publication_fk_statement;
DEALLOCATE PREPARE copyright_publication_fk_statement;

-- Recovery guidance: restore the pre-migration dump with the mysql client if a
-- database-level rollback is required. No existing rows are updated here.
