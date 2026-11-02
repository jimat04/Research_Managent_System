ALTER TABLE `research_activities`
  ADD COLUMN `document_upload_id` int(10) unsigned DEFAULT NULL AFTER `description`,
  ADD UNIQUE KEY `uq_research_activities_document_upload` (`document_upload_id`),
  ADD CONSTRAINT `fk_research_activities_document_upload`
    FOREIGN KEY (`document_upload_id`) REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL;
