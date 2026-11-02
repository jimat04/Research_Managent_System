-- Treat Food Technology as the Industrial Technology program BIT-FoodTech.
-- This catalog remains affiliation-only and does not add a Student program.

UPDATE `academic_affiliation_programs`
   SET `program_code` = 'BIT-FoodTech',
       `is_specialization` = 0,
       `updated_at` = CURRENT_TIMESTAMP
 WHERE `program_id` IS NULL
   AND `program_code` IS NULL
   AND `program_name` = 'Food Technology'
   AND `is_specialization` = 1;

INSERT IGNORE INTO `academic_group_programs` (`group_id`, `affiliation_program_id`, `status`)
SELECT g.`group_id`, p.`affiliation_program_id`, 1
  FROM `academic_affiliation_groups` g
  JOIN `academic_affiliation_programs` p
    ON p.`program_id` IS NULL
   AND p.`program_code` = 'BIT-FoodTech'
   AND p.`program_name` = 'Food Technology'
   AND p.`is_specialization` = 0
 WHERE g.`group_code` = 'industrial-technology';
