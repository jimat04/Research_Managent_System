ALTER TABLE `research_submission_annotations`
  ADD COLUMN `highlight_color` enum('yellow','blue','green','pink','purple') NOT NULL DEFAULT 'yellow' AFTER `comment_text`;
