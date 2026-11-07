-- Keep generated-document history while allowing older DOCX file sets to be pruned.
ALTER TABLE `earist_generated_documents`
  ADD COLUMN `file_retention_status` enum('available','pruning','pruned') NOT NULL DEFAULT 'available' AFTER `docx_size`,
  ADD COLUMN `file_retention_started_at` datetime DEFAULT NULL AFTER `file_retention_status`,
  ADD COLUMN `file_retention_completed_at` datetime DEFAULT NULL AFTER `file_retention_started_at`,
  ADD COLUMN `file_retention_actor_id` int unsigned DEFAULT NULL AFTER `file_retention_completed_at`,
  ADD COLUMN `file_retention_bytes` bigint unsigned NOT NULL DEFAULT 0 AFTER `file_retention_actor_id`,
  ADD KEY `idx_earist_generated_retention` (`owner_id`,`research_record_id`,`document_type`,`file_retention_status`,`document_version`),
  ADD KEY `idx_earist_generated_retention_actor` (`file_retention_actor_id`),
  ADD CONSTRAINT `fk_earist_generated_retention_actor`
    FOREIGN KEY (`file_retention_actor_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT;
