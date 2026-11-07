-- Optional links from capacity-building activities to archived source records.

ALTER TABLE `research_activities`
  ADD COLUMN `source_project_id` int unsigned DEFAULT NULL AFTER `description`,
  ADD COLUMN `source_publication_id` int unsigned DEFAULT NULL AFTER `source_project_id`,
  ADD KEY `idx_research_activities_source_project` (`source_project_id`),
  ADD KEY `idx_research_activities_source_publication` (`source_publication_id`),
  ADD CONSTRAINT `fk_research_activities_source_project`
    FOREIGN KEY (`source_project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_research_activities_source_publication`
    FOREIGN KEY (`source_publication_id`) REFERENCES `publications` (`publication_id`) ON DELETE SET NULL;
