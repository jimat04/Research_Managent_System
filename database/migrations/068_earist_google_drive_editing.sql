-- Store per-Faculty Google connections and app-created DOCX working copies.
-- RMS history remains immutable; an import creates another generated-document row.
CREATE TABLE `earist_google_drive_connections` (
  `owner_id` int unsigned NOT NULL,
  `google_subject` varchar(255) NOT NULL,
  `google_email` varchar(255) NOT NULL,
  `refresh_token_ciphertext` text NOT NULL,
  `connected_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`owner_id`),
  CONSTRAINT `fk_earist_google_connection_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `earist_google_drive_files` (
  `drive_file_id` varchar(128) NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `google_subject` varchar(255) NOT NULL,
  `generated_document_id` int unsigned NOT NULL,
  `file_selector` enum('primary','sdg_continuation') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `web_view_link` varchar(512) NOT NULL,
  `drive_version` varchar(64) DEFAULT NULL,
  `last_imported_drive_version` varchar(64) DEFAULT NULL,
  `last_imported_generated_document_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `last_imported_at` datetime DEFAULT NULL,
  PRIMARY KEY (`drive_file_id`),
  KEY `idx_earist_google_file_owner_source` (`owner_id`,`generated_document_id`,`file_selector`,`created_at`),
  KEY `idx_earist_google_file_imported` (`last_imported_generated_document_id`),
  CONSTRAINT `fk_earist_google_file_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_earist_google_file_source` FOREIGN KEY (`generated_document_id`) REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_earist_google_file_imported` FOREIGN KEY (`last_imported_generated_document_id`) REFERENCES `earist_generated_documents` (`generated_document_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
