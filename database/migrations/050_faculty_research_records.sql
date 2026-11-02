-- Independent faculty metadata for future document generation; no workflow data is changed.
CREATE TABLE IF NOT EXISTS faculty_research_records (
    research_record_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    title TEXT NOT NULL,
    work_type VARCHAR(64) NOT NULL,
    work_type_label VARCHAR(120) NOT NULL,
    work_type_other VARCHAR(120) NULL,
    date_started CHAR(7) NULL,
    date_completed CHAR(7) NULL,
    conference_title VARCHAR(500) NULL,
    conference_host VARCHAR(500) NULL,
    conference_organizer VARCHAR(500) NULL,
    conference_classification ENUM('regional_national_local','international') NULL,
    conference_start_date DATE NULL,
    conference_end_date DATE NULL,
    presentation_modality ENUM('in_person','virtual') NULL,
    conference_venue VARCHAR(500) NULL,
    journal_title VARCHAR(500) NULL,
    journal_publisher VARCHAR(500) NULL,
    sdg_other VARCHAR(500) NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    deleted_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    creation_token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (research_record_id),
    UNIQUE KEY uq_faculty_record_creation (owner_id, creation_token_hash),
    KEY idx_faculty_records_updated (owner_id, deleted_at, status, updated_at, research_record_id),
    KEY idx_faculty_records_created (owner_id, deleted_at, status, created_at, research_record_id),
    CONSTRAINT fk_faculty_records_owner FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_faculty_records_title CHECK (CHAR_LENGTH(TRIM(title)) BETWEEN 1 AND 2000),
    CONSTRAINT chk_faculty_records_months CHECK (
        (date_started IS NULL OR date_started REGEXP '^[1-9][0-9]{3}-(0[1-9]|1[0-2])$') AND
        (date_completed IS NULL OR date_completed REGEXP '^[1-9][0-9]{3}-(0[1-9]|1[0-2])$') AND
        (date_started IS NULL OR date_completed IS NULL OR date_completed >= date_started)
    ),
    CONSTRAINT chk_faculty_records_conference_dates CHECK (
        conference_start_date IS NULL OR conference_end_date IS NULL OR conference_end_date >= conference_start_date
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_research_record_authors (
    record_author_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    research_record_id INT UNSIGNED NOT NULL,
    faculty_user_id INT UNSIGNED NULL,
    author_type ENUM('internal','external') NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    position VARCHAR(255) NOT NULL,
    affiliation VARCHAR(500) NOT NULL,
    author_role ENUM('lead','coauthor') NOT NULL,
    display_order INT UNSIGNED NOT NULL,
    identity_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (record_author_id),
    UNIQUE KEY uq_faculty_record_author_user (research_record_id, faculty_user_id),
    UNIQUE KEY uq_faculty_record_author_identity (research_record_id, identity_key),
    UNIQUE KEY uq_faculty_record_author_order (research_record_id, display_order),
    KEY idx_faculty_record_author_user (faculty_user_id),
    CONSTRAINT fk_faculty_record_authors_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT fk_faculty_record_authors_user FOREIGN KEY (faculty_user_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT chk_faculty_record_author_type CHECK (author_type = 'internal' OR faculty_user_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_research_record_sdgs (
    research_record_id INT UNSIGNED NOT NULL,
    sdg_number TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (research_record_id, sdg_number),
    CONSTRAINT fk_faculty_record_sdgs_record FOREIGN KEY (research_record_id) REFERENCES faculty_research_records (research_record_id) ON DELETE RESTRICT,
    CONSTRAINT chk_faculty_record_sdg_number CHECK (sdg_number BETWEEN 1 AND 17)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
