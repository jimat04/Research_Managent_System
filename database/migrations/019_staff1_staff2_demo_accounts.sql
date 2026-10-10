-- Keep the two CREC/EREC demo staff accounts and retire the other office demos.
-- Retired accounts are soft-deleted so existing review and notification history
-- retains its user references.
START TRANSACTION;

UPDATE `users`
SET `first_name` = 'staff1',
    `last_name` = '',
    `email` = 'staff1@rms.edu.ph',
    `status` = 'active',
    `deleted_at` = NULL,
    `updated_at` = CURRENT_TIMESTAMP
WHERE `email` = 'EREC@rms.edu.ph'
  AND `role` = 'research_staff';

UPDATE `users`
SET `first_name` = 'staff2',
    `last_name` = '',
    `email` = 'staff2@rms.edu.ph',
    `status` = 'active',
    `deleted_at` = NULL,
    `updated_at` = CURRENT_TIMESTAMP
WHERE `email` = 'CREC@rms.edu.ph'
  AND `role` = 'research_staff';

UPDATE `users`
SET `status` = 'suspended',
    `deleted_at` = COALESCE(`deleted_at`, CURRENT_TIMESTAMP),
    `updated_at` = CURRENT_TIMESTAMP
WHERE `email` IN ('ORS@rms.edu.ph', 'graduate@rms.edu.ph')
  AND `role` = 'research_staff';

COMMIT;
