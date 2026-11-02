-- Track one editable RSVP per invited account and scheduled activity date.
-- Existing invitations begin as pending; attendance remains a separate record.
ALTER TABLE `activity_schedule_participants`
  ADD COLUMN `invitation_response` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending' AFTER `is_notified`,
  ADD COLUMN `invitation_responded_at` datetime DEFAULT NULL AFTER `invitation_response`;
