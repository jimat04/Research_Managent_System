-- Preserve the selected source document and publish Office edits as new history rows.
ALTER TABLE `earist_generated_documents`
  ADD COLUMN `version_origin` enum('generated','edited') NOT NULL DEFAULT 'generated' AFTER `docx_size`,
  ADD COLUMN `edited_from_generated_document_id` int unsigned DEFAULT NULL AFTER `version_origin`,
  ADD KEY `idx_earist_generated_edit_parent` (`edited_from_generated_document_id`),
  ADD CONSTRAINT `fk_earist_generated_edit_parent` FOREIGN KEY (`edited_from_generated_document_id`)
    REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT;

CREATE TABLE `earist_document_edit_sessions` (
  `session_id` char(64) NOT NULL,
  `generated_document_id` int unsigned NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `file_selector` enum('primary','sdg_continuation') NOT NULL,
  `editor_key` char(64) NOT NULL,
  `source_sha256` char(64) NOT NULL,
  `read_token_sha256` char(64) NOT NULL,
  `read_token_expires_at` datetime NOT NULL,
  `state` enum('active','saved','closed','expired','failed') NOT NULL,
  `active_lock_generated_document_id` int unsigned DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `saved_generated_document_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_earist_edit_active_lock` (`active_lock_generated_document_id`),
  KEY `idx_earist_edit_owner_state` (`owner_id`,`state`,`expires_at`),
  KEY `idx_earist_edit_generated` (`generated_document_id`,`created_at`),
  CONSTRAINT `fk_earist_edit_generated` FOREIGN KEY (`generated_document_id`) REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_earist_edit_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_earist_edit_active_lock` FOREIGN KEY (`active_lock_generated_document_id`) REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_earist_edit_saved` FOREIGN KEY (`saved_generated_document_id`) REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_earist_edit_ids` CHECK (`session_id` REGEXP '^[0-9a-f]{64}$' AND `editor_key` REGEXP '^[0-9a-f]{64}$' AND `source_sha256` REGEXP '^[0-9a-f]{64}$' AND `read_token_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_earist_edit_state` CHECK ((`state` = 'active' AND `active_lock_generated_document_id` = `generated_document_id` AND `saved_generated_document_id` IS NULL) OR (`state` <> 'active' AND `active_lock_generated_document_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
