-- Retire activity-type-specific staff grants. Capacity Building access is now
-- controlled by the add and status pages, which cover all supported activity types.
DELETE FROM `staff_permissions`
 WHERE `module_key` = 'research_capacity_building'
   AND `subpage_key` IN ('seminars', 'seminars_workshops', 'trainings', 'workshops');
