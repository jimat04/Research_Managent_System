-- Phase 4A: preparation only until explicitly approved for execution.
-- Additive storage; no accounts, research records, workflows or templates change.
-- The application must enforce live role/ownership checks and immutable snapshots.
-- Annex D scores remain exact decimal STRINGS inside versioned JSON, never FLOAT
-- or a fixed-scale DECIMAL. Decimal classification policy requires confirmation.

CREATE TABLE IF NOT EXISTS earist_document_drafts (
    draft_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    research_record_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    document_type ENUM('annex_a','annex_b','annex_c','annex_d','annex_e','annex_f','qsf_rds_019') NOT NULL,
    draft_revision INT UNSIGNED NOT NULL DEFAULT 1,
    source_record_version INT UNSIGNED NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    input_schema_version INT UNSIGNED NOT NULL,
    mapping_definition_version INT UNSIGNED NOT NULL,
    binding_version INT UNSIGNED NOT NULL,
    template_sha256 CHAR(64) NOT NULL,
    inputs_json LONGTEXT NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (draft_id),
    UNIQUE KEY uq_earist_draft_source (research_record_id, owner_id, document_type),
    KEY idx_earist_draft_owner_updated (owner_id, updated_at, draft_id),
    KEY idx_earist_draft_creator (created_by),
    KEY idx_earist_draft_updater (updated_by),
    CONSTRAINT fk_earist_draft_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_creator FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_draft_updater FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_earist_draft_json CHECK (JSON_VALID(inputs_json)),
    CONSTRAINT chk_earist_draft_versions CHECK (draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0),
    CONSTRAINT chk_earist_draft_hashes CHECK (source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS earist_generated_documents (
    generated_document_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_id INT UNSIGNED NOT NULL,
    research_record_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    document_type ENUM('annex_a','annex_b','annex_c','annex_d','annex_e','annex_f','qsf_rds_019') NOT NULL,
    document_version INT UNSIGNED NOT NULL,
    draft_revision INT UNSIGNED NOT NULL,
    source_record_version INT UNSIGNED NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    input_schema_version INT UNSIGNED NOT NULL,
    mapping_definition_version INT UNSIGNED NOT NULL,
    binding_version INT UNSIGNED NOT NULL,
    renderer_version VARCHAR(32) NOT NULL,
    template_sha256 CHAR(64) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    missing_fields_json LONGTEXT NOT NULL,
    warnings_json LONGTEXT NOT NULL,
    warnings_acknowledged_at DATETIME NULL,
    preparation_mode ENUM('incomplete','prepared_for_printing') NOT NULL,
    generated_by INT UNSIGNED NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_token_hash CHAR(64) NOT NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    verified_payload_sha256 CHAR(64) NULL,
    docx_path VARCHAR(255) NOT NULL,
    docx_sha256 CHAR(64) NOT NULL,
    docx_size BIGINT UNSIGNED NOT NULL,
    pdf_path VARCHAR(255) NULL,
    pdf_sha256 CHAR(64) NULL,
    pdf_size BIGINT UNSIGNED NULL,
    pdf_created_at DATETIME NULL,
    pdf_converter_version VARCHAR(120) NULL,
    PRIMARY KEY (generated_document_id),
    UNIQUE KEY uq_earist_generated_version (research_record_id, document_type, document_version),
    UNIQUE KEY uq_earist_generated_request (generated_by, request_token_hash),
    UNIQUE KEY uq_earist_generated_docx (docx_path),
    UNIQUE KEY uq_earist_generated_pdf (pdf_path),
    KEY idx_earist_generated_owner_record (owner_id, research_record_id, generated_at, generated_document_id),
    KEY idx_earist_generated_draft (draft_id),
    KEY idx_earist_generated_verifier (verified_by),
    KEY idx_earist_generated_type_date (document_type, generated_at, generated_document_id),
    CONSTRAINT fk_earist_generated_draft FOREIGN KEY (draft_id) REFERENCES earist_document_drafts (draft_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_generator FOREIGN KEY (generated_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_earist_generated_verifier FOREIGN KEY (verified_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_earist_generated_json CHECK (JSON_VALID(snapshot_json) AND JSON_VALID(missing_fields_json) AND JSON_VALID(warnings_json)),
    CONSTRAINT chk_earist_generated_versions CHECK (document_version > 0 AND draft_revision > 0 AND source_record_version > 0 AND input_schema_version > 0 AND mapping_definition_version > 0 AND binding_version > 0),
    CONSTRAINT chk_earist_generated_hashes CHECK (source_fingerprint REGEXP '^[0-9a-f]{64}$' AND template_sha256 REGEXP '^[0-9a-f]{64}$' AND request_token_hash REGEXP '^[0-9a-f]{64}$' AND docx_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_earist_generated_docx CHECK (CHAR_LENGTH(TRIM(docx_path)) > 0 AND docx_size > 0 AND CHAR_LENGTH(TRIM(renderer_version)) > 0),
    CONSTRAINT chk_earist_generated_warning_ack CHECK (JSON_LENGTH(warnings_json) = 0 OR warnings_acknowledged_at IS NOT NULL),
    CONSTRAINT chk_earist_generated_verification CHECK (
        (document_type = 'annex_d' AND preparation_mode = 'prepared_for_printing' AND verified_by IS NOT NULL AND verified_by = generated_by AND verified_at IS NOT NULL AND verified_payload_sha256 IS NOT NULL AND verified_payload_sha256 REGEXP '^[0-9a-f]{64}$')
        OR ((document_type <> 'annex_d' OR preparation_mode = 'incomplete') AND verified_by IS NULL AND verified_at IS NULL AND verified_payload_sha256 IS NULL)
    ),
    CONSTRAINT chk_earist_generated_pdf CHECK (
        (pdf_path IS NULL AND pdf_sha256 IS NULL AND pdf_size IS NULL AND pdf_created_at IS NULL AND pdf_converter_version IS NULL)
        OR (pdf_path IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_path)) > 0 AND pdf_sha256 IS NOT NULL AND pdf_sha256 REGEXP '^[0-9a-f]{64}$' AND pdf_size IS NOT NULL AND pdf_size > 0 AND pdf_created_at IS NOT NULL AND pdf_converter_version IS NOT NULL AND CHAR_LENGTH(TRIM(pdf_converter_version)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No seed data, triggers, grants, automatic cleanup or certificate-issuance state.
-- Rollback: disable generation access and retain both tables/files. Do not DROP
-- retained preparation inputs or generated history as an automatic rollback.
