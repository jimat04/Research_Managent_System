-- Archive-only metadata. No legacy rows, statuses, owners, timestamps or files are rewritten.
ALTER TABLE publications
  ADD COLUMN archival_status enum('legacy','archived') NOT NULL DEFAULT 'legacy',
  ADD COLUMN encoded_by int unsigned DEFAULT NULL,
  ADD COLUMN last_modified_by int unsigned DEFAULT NULL,
  ADD COLUMN deactivated_at datetime DEFAULT NULL,
  ADD COLUMN volume varchar(60) DEFAULT NULL,
  ADD COLUMN issue varchar(60) DEFAULT NULL,
  ADD COLUMN page_numbers varchar(80) DEFAULT NULL,
  ADD COLUMN publication_url varchar(500) DEFAULT NULL,
  ADD COLUMN indexing_information varchar(255) DEFAULT NULL,
  ADD COLUMN sdg_alignment varchar(255) DEFAULT NULL,
  ADD COLUMN remarks text DEFAULT NULL,
  ADD KEY idx_publications_archival (archival_status, deactivated_at),
  ADD CONSTRAINT fk_publications_encoder FOREIGN KEY (encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_publications_editor FOREIGN KEY (last_modified_by) REFERENCES users(user_id) ON DELETE RESTRICT;
ALTER TABLE copyright_applications
  ADD COLUMN archival_status enum('legacy','archived') NOT NULL DEFAULT 'legacy',
  ADD COLUMN external_status enum('filed','processing','registered','refused','withdrawn','other') DEFAULT NULL,
  ADD COLUMN encoded_by int unsigned DEFAULT NULL,
  ADD COLUMN last_modified_by int unsigned DEFAULT NULL,
  ADD COLUMN deactivated_at datetime DEFAULT NULL,
  ADD COLUMN remarks text DEFAULT NULL,
  ADD KEY idx_copyright_archival (archival_status, deactivated_at),
  ADD CONSTRAINT fk_copyright_encoder FOREIGN KEY (encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_copyright_editor FOREIGN KEY (last_modified_by) REFERENCES users(user_id) ON DELETE RESTRICT;
-- Extend the existing audit mechanism rather than introducing a parallel history table.
ALTER TABLE activity_log ADD COLUMN details longtext DEFAULT NULL;
