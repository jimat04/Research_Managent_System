-- Migration 016: Track verified database migrations
--
-- The migration runner bootstraps this same table before it processes a
-- migration file, so this file is safe to apply on both new and existing
-- installations. A row is written only after the migration's verifier has
-- confirmed the resulting schema. Failed attempts are retained with status
-- 'failed' and are never treated as applied.

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `migration_key` varchar(160) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `status` enum('applied','failed') NOT NULL DEFAULT 'applied',
  `verification_method` enum('executed','verified_existing') DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `execution_ms` decimal(12,3) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`migration_key`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`),
  KEY `idx_schema_migrations_status` (`status`),
  KEY `idx_schema_migrations_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
