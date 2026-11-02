CREATE TABLE IF NOT EXISTS `research_basics_lesson_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `lesson_id` varchar(50) NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`lesson_id`),
  KEY `idx_research_basics_lesson_progress_lesson` (`lesson_id`),
  CONSTRAINT `fk_research_basics_lesson_progress_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research_basics_roadmap_progress` (
  `user_id` int(10) unsigned NOT NULL,
  `stage_id` varchar(50) NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`stage_id`),
  KEY `idx_research_basics_roadmap_progress_stage` (`stage_id`),
  CONSTRAINT `fk_research_basics_roadmap_progress_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
