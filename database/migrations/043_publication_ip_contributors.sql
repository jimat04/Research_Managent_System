-- Store explicitly linked RMS authors and IP contributors.
-- Record ownership does not imply authorship; existing free-text names remain untouched.
CREATE TABLE IF NOT EXISTS `publication_contributors` (
  `publication_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `contribution` varchar(120) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`publication_id`, `user_id`),
  KEY `idx_publication_contributors_user` (`user_id`, `publication_id`),
  CONSTRAINT `fk_publication_contributors_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_publication_contributors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `copyright_contributors` (
  `copyright_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `contribution` varchar(120) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`copyright_id`, `user_id`),
  KEY `idx_copyright_contributors_user` (`user_id`, `copyright_id`),
  CONSTRAINT `fk_copyright_contributors_application` FOREIGN KEY (`copyright_id`) REFERENCES `copyright_applications` (`copyright_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_copyright_contributors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


