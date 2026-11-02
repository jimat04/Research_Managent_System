-- Add the faculty registration rank accepted by public/login.php.
-- Preserve existing academic_rank values, NULL behavior, and the column comment.

ALTER TABLE `users`
  MODIFY COLUMN `academic_rank` ENUM('Instructor','Assistant Professor','Associate Professor','Professor','Dean','Director','College Professor') DEFAULT NULL COMMENT 'Faculty academic rank';
