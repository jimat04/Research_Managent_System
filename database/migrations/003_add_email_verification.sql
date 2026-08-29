-- Migration: Add email verification system
-- Date: 2026-08-29
-- Purpose: Add email verification tokens and verified status to users table

ALTER TABLE users
ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER status,
ADD COLUMN email_verification_token VARCHAR(64) DEFAULT NULL AFTER email_verified,
ADD COLUMN email_verification_expires DATETIME DEFAULT NULL AFTER email_verification_token,
ADD INDEX idx_verification_token (email_verification_token);

-- Note: Students will have status='active' and email_verified=0 until they verify
-- Faculty/Staff will have status='pending' and email_verified=0 until admin approval + verification
