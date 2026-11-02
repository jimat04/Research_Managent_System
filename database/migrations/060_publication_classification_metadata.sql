-- Configurable archive classifications and nullable type-aware publication metadata.
-- Legacy rows and their original classification/status/file values are unchanged.
CREATE TABLE publication_types (
  publication_type_id smallint unsigned NOT NULL AUTO_INCREMENT,
  type_code varchar(80) NOT NULL,
  label varchar(160) NOT NULL,
  is_proposed tinyint(1) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_type_id),
  UNIQUE KEY uq_publication_types_code (type_code),
  UNIQUE KEY uq_publication_types_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_subtypes (
  publication_subtype_id smallint unsigned NOT NULL AUTO_INCREMENT,
  publication_type_id smallint unsigned NOT NULL,
  subtype_code varchar(80) NOT NULL,
  label varchar(160) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_subtype_id),
  UNIQUE KEY uq_publication_subtypes_code (publication_type_id, subtype_code),
  UNIQUE KEY uq_publication_subtypes_label (publication_type_id, label),
  CONSTRAINT fk_publication_subtypes_type FOREIGN KEY (publication_type_id) REFERENCES publication_types(publication_type_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_scopes (
  publication_scope_id smallint unsigned NOT NULL AUTO_INCREMENT,
  scope_code varchar(40) NOT NULL,
  label varchar(120) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order smallint unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (publication_scope_id),
  UNIQUE KEY uq_publication_scopes_code (scope_code),
  UNIQUE KEY uq_publication_scopes_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_form_rules (
  publication_subtype_id smallint unsigned NOT NULL,
  abstract_word_limit smallint unsigned DEFAULT NULL,
  keyword_count tinyint unsigned NOT NULL DEFAULT 0,
  manuscript_required tinyint(1) NOT NULL DEFAULT 0,
  references_required tinyint(1) NOT NULL DEFAULT 0,
  evidence_required tinyint(1) NOT NULL DEFAULT 1,
  manuscript_page_limit smallint unsigned DEFAULT NULL,
  allowed_attachment_extensions varchar(80) NOT NULL DEFAULT 'pdf,docx',
  PRIMARY KEY (publication_subtype_id),
  CONSTRAINT fk_publication_form_rules_subtype FOREIGN KEY (publication_subtype_id) REFERENCES publication_subtypes(publication_subtype_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO publication_types (type_code,label,is_proposed,sort_order) VALUES
 ('journal','Journal (legacy classification)',0,10),
 ('conference','Conference (legacy classification)',0,20),
 ('book_chapter','Book chapter (legacy classification)',0,30),
 ('other','Other (legacy classification)',0,40),
 ('journal_article','Journal Article',1,100),
 ('conference_proceedings_paper','Conference Proceedings Paper',1,110),
 ('book','Book',1,120),
 ('book_chapter_scholarly','Book Chapter',1,130),
 ('research_monograph','Research Monograph',1,140);

INSERT INTO publication_subtypes (publication_type_id,subtype_code,label,sort_order)
SELECT t.publication_type_id,s.subtype_code,s.label,s.sort_order
FROM publication_types t JOIN (
 SELECT 'journal_article' type_code,'original_research_article' subtype_code,'Original Research Article' label,10 sort_order UNION ALL
 SELECT 'journal_article','review_article','Review Article',20 UNION ALL
 SELECT 'journal_article','technical_professional_article','Technical / Professional Article',30 UNION ALL
 SELECT 'journal_article','other_journal_article','Other Journal Article',40 UNION ALL
 SELECT 'conference_proceedings_paper','full_conference_paper','Full Conference Paper',10 UNION ALL
 SELECT 'conference_proceedings_paper','short_conference_paper','Short Conference Paper',20 UNION ALL
 SELECT 'conference_proceedings_paper','other_published_proceedings_paper','Other Published Proceedings Paper',30 UNION ALL
 SELECT 'book','authored_book','Authored Book',10 UNION ALL
 SELECT 'book','edited_book','Edited Book',20 UNION ALL
 SELECT 'book','compiled_book','Compiled Book',30 UNION ALL
 SELECT 'book','textbook','Textbook',40 UNION ALL
 SELECT 'book_chapter_scholarly','scholarly_book_chapter','Scholarly Book Chapter',10 UNION ALL
 SELECT 'book_chapter_scholarly','technical_professional_book_chapter','Technical / Professional Book Chapter',20 UNION ALL
 SELECT 'research_monograph','scholarly_monograph','Scholarly Monograph',10 UNION ALL
 SELECT 'research_monograph','technical_research_monograph','Technical Research Monograph',20
) s ON s.type_code=t.type_code;

INSERT INTO publication_scopes (scope_code,label,sort_order) VALUES
 ('local_institutional','Local / Institutional',10),
 ('national','National',20),
 ('international','International',30);

INSERT INTO publication_form_rules (publication_subtype_id,abstract_word_limit,keyword_count,manuscript_required,references_required,evidence_required,manuscript_page_limit,allowed_attachment_extensions)
SELECT publication_subtype_id,250,4,1,0,1,NULL,'pdf,docx'
FROM publication_subtypes WHERE subtype_code='short_conference_paper';

ALTER TABLE publications
  ADD COLUMN publication_type_id smallint unsigned DEFAULT NULL,
  ADD COLUMN publication_subtype_id smallint unsigned DEFAULT NULL,
  ADD COLUMN publication_scope_id smallint unsigned DEFAULT NULL,
  ADD COLUMN abstract_text text DEFAULT NULL,
  ADD COLUMN authors_json text DEFAULT NULL,
  ADD COLUMN keywords_json text DEFAULT NULL,
  ADD COLUMN publication_metadata_json longtext DEFAULT NULL,
  ADD COLUMN evidence_url varchar(500) DEFAULT NULL,
  ADD COLUMN evidence_file_path varchar(500) DEFAULT NULL,
  ADD COLUMN evidence_file_name varchar(255) DEFAULT NULL,
  ADD COLUMN references_file_path varchar(500) DEFAULT NULL,
  ADD COLUMN references_file_name varchar(255) DEFAULT NULL,
  ADD KEY idx_publications_type_id (publication_type_id),
  ADD KEY idx_publications_subtype_id (publication_subtype_id),
  ADD KEY idx_publications_scope_id (publication_scope_id),
  ADD CONSTRAINT fk_publications_classification_type FOREIGN KEY (publication_type_id) REFERENCES publication_types(publication_type_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_publications_classification_subtype FOREIGN KEY (publication_subtype_id) REFERENCES publication_subtypes(publication_subtype_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_publications_scope FOREIGN KEY (publication_scope_id) REFERENCES publication_scopes(publication_scope_id) ON DELETE RESTRICT;
