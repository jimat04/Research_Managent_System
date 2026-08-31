-- Migration: Extend status ENUMs for the full EARIST 12-step workflow
-- Created: 2026-08-31

-- Research project statuses (full lifecycle)
ALTER TABLE research_projects
  MODIFY status ENUM(
    'draft',
    'proposal',
    'submitted',
    'under_review',
    'under_crec_review',
    'under_erec_review',
    'for_revision',
    'revision_required',
    'rejected',
    'approved',
    'ongoing',
    'progress_report',
    'terminal_review',
    'completed',
    'archived'
  ) NOT NULL DEFAULT 'draft';

-- Chapter statuses
ALTER TABLE chapters
  MODIFY status ENUM(
    'draft',
    'submitted',
    'under_review',
    'revision_required',
    'revised',
    'approved',
    'rejected'
  ) NOT NULL DEFAULT 'draft';