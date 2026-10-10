ALTER TABLE `research_projects`
  MODIFY `status` enum(
    'draft',
    'proposal',
    'submitted',
    'under_review',
    'under_crec_review',
    'under_erec_review',
    'for_revision',
    'revision_required',
    'rejected',
    'title_approved',
    'approved',
    'ongoing',
    'progress_report',
    'terminal_review',
    'completed',
    'archived'
  ) NOT NULL DEFAULT 'draft';
