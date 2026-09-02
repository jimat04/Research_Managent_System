-- Migration: Support project-level faculty feedback
-- Created: 2026-09-03
-- Safe to run repeatedly on MariaDB installations used by RMS.

ALTER TABLE comments
  MODIFY chapter_id INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS project_id INT(10) UNSIGNED NULL AFTER chapter_id,
  ADD INDEX IF NOT EXISTS idx_comments_project_id (project_id);
