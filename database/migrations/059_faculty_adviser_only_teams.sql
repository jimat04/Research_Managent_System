INSERT IGNORE INTO `research_author_snapshots`
  (`project_id`, `user_id`, `author_name`, `program`, `author_role`, `display_order`)
SELECT pm.`project_id`, pm.`user_id`,
       LEFT(TRIM(CONCAT_WS(' ', u.`first_name`, u.`middle_name`, u.`last_name`)), 170),
       NULLIF(TRIM(u.`program`), ''), pm.`role`,
       COALESCE((SELECT MAX(snapshot.`display_order`) + 1
                   FROM `research_author_snapshots` snapshot
                  WHERE snapshot.`project_id` = pm.`project_id`), 0)
  FROM `project_members` pm
  JOIN `users` u ON u.`user_id` = pm.`user_id` AND u.`role` = 'faculty';

DELETE pm
  FROM `project_members` pm
  JOIN `users` u ON u.`user_id` = pm.`user_id` AND u.`role` = 'faculty';
