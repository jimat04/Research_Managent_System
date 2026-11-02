-- Repair blank enum values only when both snapshot and attachment identify a manuscript.
-- The runner verifier blocks unknown blank values before this migration can execute.
-- Temporarily permit the existing empty enum value while converting confirmed rows.
ALTER TABLE research_submission_versions
  MODIFY COLUMN submission_type ENUM('title','chapter','final_manuscript','') NOT NULL;

UPDATE research_submission_versions v
SET v.submission_type = 'final_manuscript'
WHERE v.submission_type = ''
  AND v.chapter_id IS NULL
  AND JSON_VALID(v.snapshot_json)
  AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(v.snapshot_json), v.snapshot_json, '{}'), '$.submission')) = 'Complete research manuscript'
  AND EXISTS (
    SELECT 1 FROM research_submission_version_uploads vu
    JOIN uploads up ON up.upload_id = vu.upload_id
    WHERE vu.version_id = v.version_id AND up.type = 'manuscript'
      AND up.project_id = v.project_id
  );

ALTER TABLE research_submission_versions
  MODIFY COLUMN submission_type ENUM('title','chapter','final_manuscript') NOT NULL;
