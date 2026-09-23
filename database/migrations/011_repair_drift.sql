-- Migration 011: Repair enum drift and legacy empty enum values
-- Date: 2026-09-24
-- Safe to re-run: every repair is guarded by the users table/column existing,
-- and the final enum definitions are canonical on both drifted and healthy DBs.

DELIMITER $$

DROP PROCEDURE IF EXISTS `repair_users_enum_drift`$$
CREATE PROCEDURE `repair_users_enum_drift`()
BEGIN
    IF EXISTS (
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'academic_rank'
    ) THEN
        -- Temporarily accept both the legacy typo and canonical values so the
        -- data repair is valid regardless of which enum version is installed.
        ALTER TABLE `users`
            MODIFY COLUMN `academic_rank`
            ENUM(
                'Instructor',
                'AssiProfessor',
                'Assistant Professor',
                'Associate Professor',
                'Professor',
                'Dean',
                'Director'
            ) DEFAULT NULL
            COMMENT 'Faculty academic rank';

        UPDATE `users`
           SET `academic_rank` = 'Assistant Professor'
         WHERE `academic_rank` = 'AssiProfessor';

        -- Empty enum values are legacy coercion artifacts, not valid ranks.
        UPDATE `users`
           SET `academic_rank` = NULL
         WHERE `academic_rank` = '';

        ALTER TABLE `users`
            MODIFY COLUMN `academic_rank`
            ENUM(
                'Instructor',
                'Assistant Professor',
                'Associate Professor',
                'Professor',
                'Dean',
                'Director'
            ) DEFAULT NULL
            COMMENT 'Faculty academic rank';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'role'
    ) THEN
        UPDATE `users`
           SET `role` = 'research_staff'
         WHERE `role` = ''
           AND `email` IN (
               'EREC@rms.edu.ph',
               'CREC@rms.edu.ph',
               'ORS@rms.edu.ph',
               'graduate@rms.edu.ph'
           );
    END IF;

    IF EXISTS (
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'status'
    ) THEN
        UPDATE `users`
           SET `status` = 'suspended'
         WHERE `status` = '';
    END IF;
END$$

CALL `repair_users_enum_drift`()$$
DROP PROCEDURE `repair_users_enum_drift`$$

DELIMITER ;
