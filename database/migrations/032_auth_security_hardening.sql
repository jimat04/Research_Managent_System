-- Migration: Persist login throttles and preserve legacy verification state
CREATE TABLE IF NOT EXISTS login_rate_limits (
  rate_key CHAR(64) NOT NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_key),
  KEY idx_login_rate_limits_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing accounts without a verification token predate the verification flow.
UPDATE users
SET email_verified = 1
WHERE email_verified IS NULL
   OR (email_verification_token IS NULL AND email_verified = 0 AND created_at < NOW());
