-- Migration: CREC/EREC review assignments and scoring
-- Created: 2026-08-31
-- Stores individual reviewer scores for a proposal using OVPREIS Form No. 3 criteria.

CREATE TABLE IF NOT EXISTS project_reviews (
  review_id      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id     INT(10) UNSIGNED NOT NULL,
  reviewer_id    INT(10) UNSIGNED NOT NULL COMMENT 'faculty users.user_id',
  review_level   ENUM('crec','erec') NOT NULL DEFAULT 'crec',
  methodology_score TINYINT UNSIGNED DEFAULT NULL COMMENT '0-20 (soundness of methodology)',
  contribution_score TINYINT UNSIGNED DEFAULT NULL COMMENT '0-20 (contribution to knowledge)',
  applicability_score TINYINT UNSIGNED DEFAULT NULL COMMENT '0-30 (applicability/marketability)',
  agenda_score    TINYINT UNSIGNED DEFAULT NULL COMMENT '0-10 (alignment with college research agenda)',
  comments        TEXT NULL,
  recommendation  ENUM('approve','revise','reject') DEFAULT NULL,
  reviewed_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id),
  UNIQUE KEY uk_project_reviewer_level (project_id, reviewer_id, review_level),
  KEY idx_project (project_id),
  KEY idx_reviewer (reviewer_id),
  CONSTRAINT fk_reviews_project FOREIGN KEY (project_id) REFERENCES research_projects (project_id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
