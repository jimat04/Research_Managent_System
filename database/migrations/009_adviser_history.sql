-- Migration: Preserve completed adviser assignment stints
-- Created: 2026-09-04
-- Safe to run repeatedly. Foreign keys are intentionally omitted.

CREATE TABLE IF NOT EXISTS project_advisers_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  adviser_id INT UNSIGNED NOT NULL,
  role VARCHAR(60) NULL,
  assigned_at DATETIME NOT NULL,
  removed_at DATETIME NOT NULL,
  removed_by INT UNSIGNED NULL,
  INDEX idx_pah_project (project_id),
  INDEX idx_pah_adviser (adviser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
