-- Consolidate activity classifications and staff scopes.
-- The Seminar / Workshop merge is intentionally lossy in the activity_type column.

ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum(
    'seminar','training','workshop','colloquium','conference',
    'seminar_workshop','conference_congress'
  ) NOT NULL;

UPDATE `research_activities`
   SET `activity_type` = CASE
     WHEN `activity_type` IN ('seminar', 'workshop') THEN 'seminar_workshop'
     WHEN `activity_type` = 'conference' THEN 'conference_congress'
     ELSE `activity_type`
   END
 WHERE `activity_type` IN ('seminar', 'workshop', 'conference');

INSERT INTO `staff_permissions`
  (`user_id`, `module_key`, `subpage_key`, `access_level`, `download_allowed`)
SELECT seminars.`user_id`,
       'research_capacity_building',
       'seminars_workshops',
       CASE
         WHEN seminars.`access_level` = 'manage' AND workshops.`access_level` = 'manage' THEN 'manage'
         ELSE 'view'
       END,
       CASE WHEN seminars.`download_allowed` = 1 AND workshops.`download_allowed` = 1 THEN 1 ELSE 0 END
  FROM `staff_permissions` seminars
  JOIN `staff_permissions` workshops
    ON workshops.`user_id` = seminars.`user_id`
   AND workshops.`module_key` = seminars.`module_key`
   AND workshops.`subpage_key` = 'workshops'
 WHERE seminars.`module_key` = 'research_capacity_building'
   AND seminars.`subpage_key` = 'seminars'
   AND seminars.`access_level` IN ('view', 'manage')
   AND workshops.`access_level` IN ('view', 'manage')
ON DUPLICATE KEY UPDATE
  `access_level` = VALUES(`access_level`),
  `download_allowed` = VALUES(`download_allowed`);

DELETE FROM `staff_permissions`
 WHERE `module_key` = 'research_capacity_building'
   AND `subpage_key` IN ('seminars', 'workshops');

ALTER TABLE `research_activities`
  MODIFY COLUMN `activity_type` enum(
    'seminar_workshop','training','colloquium','conference_congress'
  ) NOT NULL;
