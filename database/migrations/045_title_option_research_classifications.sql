-- Store one course-specific category and one area for each proposed title.
CREATE TABLE IF NOT EXISTS research_project_title_classifications (
  project_id int(10) unsigned NOT NULL,
  title_option_number tinyint(3) unsigned NOT NULL,
  category_id int(10) unsigned NOT NULL,
  area_id int(10) unsigned NOT NULL,
  PRIMARY KEY (project_id, title_option_number),
  KEY idx_research_title_classifications_category (category_id),
  KEY idx_research_title_classifications_area (area_id),
  CONSTRAINT fk_research_title_classifications_project
    FOREIGN KEY (project_id) REFERENCES research_projects (project_id) ON DELETE CASCADE,
  CONSTRAINT fk_research_title_classifications_category
    FOREIGN KEY (category_id) REFERENCES research_categories (category_id) ON DELETE RESTRICT,
  CONSTRAINT fk_research_title_classifications_area
    FOREIGN KEY (area_id) REFERENCES research_areas (area_id) ON DELETE RESTRICT,
  CONSTRAINT chk_research_title_classifications_option CHECK (title_option_number BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
