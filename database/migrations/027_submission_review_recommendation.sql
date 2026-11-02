-- Keep review comments and actionable recommendations in separate fields.
-- Existing review rows remain valid and receive NULL recommendations.

ALTER TABLE `research_submission_reviews`
  ADD COLUMN `recommendation` TEXT DEFAULT NULL AFTER `comments`;
