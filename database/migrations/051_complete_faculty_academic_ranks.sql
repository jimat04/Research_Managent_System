-- Complete EARIST academic ranks using the 19-rank list supplied for this update.
-- Append values without reordering the existing ENUM members or changing users.
-- Broad/legacy ranks remain readable and retainable on their existing accounts;
-- the application offers only the 19 detailed ranks for new selections.
-- No level is inferred for existing Instructor/Professor/etc. records.

ALTER TABLE `users`
  MODIFY COLUMN `academic_rank` ENUM('Instructor','Assistant Professor','Associate Professor','Professor','Dean','Director','College Professor','Instructor I','Instructor II','Instructor III','Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV','Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V','Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI') DEFAULT NULL COMMENT 'Faculty academic rank';

-- Rollback: retain this additive enum while reverting PHP if necessary. Do not
-- narrow the enum after detailed ranks have been saved; that would lose data.
