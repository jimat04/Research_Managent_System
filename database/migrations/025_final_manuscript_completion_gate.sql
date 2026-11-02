ALTER TABLE `research_submission_versions`
  MODIFY COLUMN `submission_type` enum('title','chapter','final_manuscript') NOT NULL;

UPDATE `research_projects` rp
   SET rp.status = 'ongoing',
       rp.updated_at = NOW()
 WHERE rp.status IN ('completed', 'archived')
   AND NOT EXISTS (
       SELECT 1
         FROM `research_submission_versions` v
        WHERE v.project_id = rp.project_id
          AND v.submission_type = 'final_manuscript'
          AND v.chapter_id IS NULL
          AND v.status = 'approved'
   );
