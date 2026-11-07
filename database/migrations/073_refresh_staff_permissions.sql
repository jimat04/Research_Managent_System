-- Move staff publication category grants to the current action based catalog.
-- Insert only missing destination rows so reruns preserve administrator edits.

INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed)
SELECT source.user_id, 'publication_dissemination', 'publication_records',
       CASE WHEN MAX(source.access_level = 'manage') = 1 THEN 'manage'
            WHEN MAX(source.access_level = 'view') = 1 THEN 'view'
            ELSE 'none' END,
       0
  FROM staff_permissions source
 WHERE source.module_key = 'publication_dissemination'
   AND source.subpage_key IN ('journal_publications', 'conference_presentations', 'research_repository')
 GROUP BY source.user_id
HAVING NOT EXISTS (
    SELECT 1 FROM staff_permissions destination
     WHERE destination.user_id = source.user_id
       AND destination.module_key = 'publication_dissemination'
       AND destination.subpage_key = 'publication_records'
);

INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed)
SELECT source.user_id, 'publication_dissemination', 'publication_reports',
       CASE WHEN MAX(source.access_level IN ('view', 'manage')) = 1 THEN 'view' ELSE 'none' END,
       0
  FROM staff_permissions source
 WHERE source.module_key = 'publication_dissemination'
   AND source.subpage_key IN ('journal_publications', 'conference_presentations', 'research_repository')
 GROUP BY source.user_id
HAVING NOT EXISTS (
    SELECT 1 FROM staff_permissions destination
     WHERE destination.user_id = source.user_id
       AND destination.module_key = 'publication_dissemination'
       AND destination.subpage_key = 'publication_reports'
);

INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed)
SELECT source.user_id, 'publication_dissemination', 'publication_add',
       CASE WHEN MAX(source.access_level = 'manage') = 1 THEN 'manage' ELSE 'none' END,
       0
  FROM staff_permissions source
 WHERE source.module_key = 'publication_dissemination'
   AND source.subpage_key IN ('journal_publications', 'conference_presentations')
 GROUP BY source.user_id
HAVING NOT EXISTS (
    SELECT 1 FROM staff_permissions destination
     WHERE destination.user_id = source.user_id
       AND destination.module_key = 'publication_dissemination'
       AND destination.subpage_key = 'publication_add'
);

INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed)
SELECT source.user_id, 'research_development', 'research_engagement_records',
       source.access_level, source.download_allowed
  FROM staff_permissions source
 WHERE source.module_key = 'research_development'
   AND source.subpage_key = 'research_review'
   AND NOT EXISTS (
       SELECT 1 FROM staff_permissions destination
        WHERE destination.user_id = source.user_id
          AND destination.module_key = 'research_development'
          AND destination.subpage_key = 'research_engagement_records'
   );

DELETE FROM staff_permissions
 WHERE module_key = 'publication_dissemination'
   AND subpage_key IN ('journal_publications', 'conference_presentations', 'research_repository');
