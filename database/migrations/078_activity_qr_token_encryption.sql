-- Store the current activity check-in token as authenticated ciphertext so
-- authorized invitees and activity managers can display the current QR code.
ALTER TABLE `activity_schedule_dates`
  ADD COLUMN `checkin_token_encrypted` varchar(128) DEFAULT NULL AFTER `checkin_token_hash`;

-- Rollback: disable invitee QR display, then drop the encrypted token column.
