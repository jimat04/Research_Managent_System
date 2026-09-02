-- Migration: Complete OVPREIS Form No. 3 evaluation criteria
-- Created: 2026-09-03
-- Adds the two criteria omitted by migration 006. Safe to run repeatedly.

ALTER TABLE project_reviews
  ADD COLUMN IF NOT EXISTS capability_score TINYINT UNSIGNED NULL
    COMMENT '0-10 (capability of proponent to carry out research project)'
    AFTER applicability_score,
  ADD COLUMN IF NOT EXISTS thrusts_score TINYINT UNSIGNED NULL
    COMMENT '0-10 (conformity to national research thrusts: DOST/CHED)'
    AFTER agenda_score;
