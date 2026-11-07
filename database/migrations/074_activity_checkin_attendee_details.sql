-- Store optional self-reported attendee details with each scheduled-date roster entry.

ALTER TABLE `activity_schedule_participants`
  ADD COLUMN `attendee_affiliation` enum('student','faculty','staff','guest') DEFAULT NULL AFTER `participant_name`,
  ADD COLUMN `attendee_program_department` varchar(150) DEFAULT NULL AFTER `attendee_affiliation`;

-- Rollback: drop attendee_program_department and attendee_affiliation from activity_schedule_participants.
