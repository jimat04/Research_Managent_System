-- Migration: Resync office-based demo accounts
-- Requires migration 002; safe to re-run.
-- Updates are keyed by email and only reset demo passwords, reviewer flags, and status.

UPDATE `users`
SET `password` = '$2y$12$1UzhJiJbBSdUw.3bEMUg/ub1Dx55.kgrVAQiphCfUKwe8ywCM9XCO',
    `status` = 'active'
WHERE `email` = 'admin@rms.edu.ph';

UPDATE `users`
SET `password` = '$2y$12$0nZJcBqFuoWRifjqAJWJnugpalK5Zqz.vkd4UP5kYT1D.v8hbBhSG',
    `status` = 'active'
WHERE `email` IN ('msantos@rms.edu.ph', 'jreyes@rms.edu.ph');

UPDATE `users`
SET `password` = '$2y$12$C/ZwpxqDQ2LheFFOAnN4VOvGhqigkGgldLLFbNB/C8.UhFfTXRRCK',
    `status` = 'active'
WHERE `email` IN ('jdelacruz@rms.edu.ph', 'areyes@rms.edu.ph');

UPDATE `users`
SET `is_reviewer` = 1
WHERE `email` IN ('msantos@rms.edu.ph', 'jreyes@rms.edu.ph')
  AND `role` = 'faculty';

UPDATE `users`
SET `password` = '$2y$12$F5/mP1LrsQuBfPnIPMobKe2d6aaz3xuF7IYCGoz/lVl6qXXxkCDSq',
    `status` = 'active'
WHERE `email` IN (
    'EREC@rms.edu.ph',
    'CREC@rms.edu.ph',
    'ORS@rms.edu.ph',
    'graduate@rms.edu.ph'
)
  AND `role` = 'research_staff';