-- Rename activity permission keys while retaining the strongest existing grant.
UPDATE staff_permissions current_permission
JOIN staff_permissions legacy_permission
  ON legacy_permission.user_id = current_permission.user_id
 AND legacy_permission.module_key = current_permission.module_key
 AND legacy_permission.subpage_key = 'add_incoming_activity'
SET current_permission.access_level = CASE GREATEST(
      FIELD(current_permission.access_level, 'none', 'view', 'manage'),
      FIELD(legacy_permission.access_level, 'none', 'view', 'manage')
    )
      WHEN 3 THEN 'manage'
      WHEN 2 THEN 'view'
      ELSE 'none'
    END,
    current_permission.download_allowed = GREATEST(current_permission.download_allowed, legacy_permission.download_allowed)
WHERE current_permission.subpage_key = 'add_upcoming_activity';

DELETE legacy_permission
FROM staff_permissions legacy_permission
JOIN staff_permissions current_permission
  ON current_permission.user_id = legacy_permission.user_id
 AND current_permission.module_key = legacy_permission.module_key
 AND current_permission.subpage_key = 'add_upcoming_activity'
WHERE legacy_permission.subpage_key = 'add_incoming_activity';

UPDATE staff_permissions
   SET subpage_key = 'add_upcoming_activity'
 WHERE subpage_key = 'add_incoming_activity';

UPDATE staff_permissions current_permission
JOIN staff_permissions legacy_permission
  ON legacy_permission.user_id = current_permission.user_id
 AND legacy_permission.module_key = current_permission.module_key
 AND legacy_permission.subpage_key = 'incoming_activities'
SET current_permission.access_level = CASE GREATEST(
      FIELD(current_permission.access_level, 'none', 'view', 'manage'),
      FIELD(legacy_permission.access_level, 'none', 'view', 'manage')
    )
      WHEN 3 THEN 'manage'
      WHEN 2 THEN 'view'
      ELSE 'none'
    END,
    current_permission.download_allowed = GREATEST(current_permission.download_allowed, legacy_permission.download_allowed)
WHERE current_permission.subpage_key = 'upcoming_activities';

DELETE legacy_permission
FROM staff_permissions legacy_permission
JOIN staff_permissions current_permission
  ON current_permission.user_id = legacy_permission.user_id
 AND current_permission.module_key = legacy_permission.module_key
 AND current_permission.subpage_key = 'upcoming_activities'
WHERE legacy_permission.subpage_key = 'incoming_activities';

UPDATE staff_permissions
   SET subpage_key = 'upcoming_activities'
 WHERE subpage_key = 'incoming_activities';
