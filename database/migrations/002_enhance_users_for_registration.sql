-- Migration: Enhance users table for role-specific registration
-- Date: 2026-08-29
-- Description: Add year_level, specialization, academic_rank, office fields and update role enum

-- Update role enum to include research_staff
ALTER TABLE `users`
MODIFY COLUMN `role` ENUM('student','faculty','research_staff','admin') NOT NULL DEFAULT 'student';

-- Add year_level for students
ALTER TABLE `users`
ADD COLUMN `year_level` ENUM('1st','2nd','3rd','4th','Graduate','Masters','Doctorate') DEFAULT NULL
COMMENT 'Student year level' AFTER `program`;

-- Add specialization for faculty
ALTER TABLE `users`
ADD COLUMN `specialization` VARCHAR(120) DEFAULT NULL
COMMENT 'Faculty field of expertise' AFTER `department`;

-- Add academic_rank for faculty
ALTER TABLE `users`
ADD COLUMN `academic_rank` ENUM('Instructor','Assistant Professor','Associate Professor','Professor','Dean','Director') DEFAULT NULL
COMMENT 'Faculty academic rank' AFTER `specialization`;

-- Add office for research_staff
ALTER TABLE `users`
ADD COLUMN `office` VARCHAR(120) DEFAULT NULL
COMMENT 'Office assignment for staff' AFTER `department`;

-- Add is_reviewer flag for faculty who can do CREC/EREC reviews
ALTER TABLE `users`
ADD COLUMN `is_reviewer` TINYINT(1) DEFAULT 0
COMMENT 'Can participate in CREC/EREC review' AFTER `academic_rank`;

-- Update existing research_staff users if any exist with old role name
UPDATE `users` SET `role` = 'research_staff' WHERE `role` = 'staff';
