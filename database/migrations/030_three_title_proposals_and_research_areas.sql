ALTER TABLE `research_projects`
  ADD COLUMN `title_option_1` varchar(255) DEFAULT NULL AFTER `title`,
  ADD COLUMN `title_option_2` varchar(255) DEFAULT NULL AFTER `title_option_1`,
  ADD COLUMN `title_option_3` varchar(255) DEFAULT NULL AFTER `title_option_2`;

ALTER TABLE `research_submission_reviews`
  ADD COLUMN `selected_title` varchar(255) DEFAULT NULL AFTER `recommendation`;

CREATE TABLE `research_areas` (
  `area_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `area_code` varchar(8) NOT NULL,
  `area_name` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`area_id`),
  UNIQUE KEY `uq_research_areas_code` (`area_code`),
  UNIQUE KEY `uq_research_areas_name` (`area_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_research_areas` (
  `category_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`category_id`,`area_id`),
  KEY `idx_category_research_areas_area` (`area_id`),
  CONSTRAINT `fk_category_research_areas_category`
    FOREIGN KEY (`category_id`) REFERENCES `research_categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_category_research_areas_area`
    FOREIGN KEY (`area_id`) REFERENCES `research_areas` (`area_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `research_project_areas` (
  `project_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `display_order` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`project_id`,`area_id`),
  UNIQUE KEY `uq_research_project_area_order` (`project_id`,`display_order`),
  KEY `idx_research_project_areas_area` (`area_id`),
  CONSTRAINT `fk_research_project_areas_project`
    FOREIGN KEY (`project_id`) REFERENCES `research_projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_research_project_areas_area`
    FOREIGN KEY (`area_id`) REFERENCES `research_areas` (`area_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `research_areas` (`area_code`, `area_name`) VALUES
('RA001', 'Computer Science'),
('RA002', 'Information Technology'),
('RA003', 'Artificial Intelligence and Machine Learning'),
('RA004', 'Data Science and Analytics'),
('RA005', 'Cybersecurity and Information Security'),
('RA006', 'Software Engineering and Development'),
('RA007', 'Web and Mobile Application Development'),
('RA008', 'Database Management and Information Systems'),
('RA009', 'Computer Networks and Communications'),
('RA010', 'Human-Computer Interaction'),
('RA011', 'Educational Technology'),
('RA012', 'Education and Teaching Methodologies'),
('RA013', 'Curriculum and Instruction'),
('RA014', 'Educational Leadership and Management'),
('RA015', 'Student Development and Learning Outcomes'),
('RA016', 'Business Administration and Management'),
('RA017', 'Entrepreneurship and Innovation'),
('RA018', 'Marketing and Consumer Behavior'),
('RA019', 'Financial Management and Accounting'),
('RA020', 'Human Resource Management'),
('RA021', 'Office Administration and Management'),
('RA022', 'Criminology and Criminal Justice'),
('RA023', 'Crime Prevention and Public Safety'),
('RA024', 'Forensic Science and Investigation'),
('RA025', 'Hospitality and Tourism Management'),
('RA026', 'Food Science and Culinary Innovation'),
('RA027', 'Industrial Technology'),
('RA028', 'Engineering and Applied Technology'),
('RA029', 'Environmental Science and Sustainability'),
('RA030', 'Renewable Energy and Green Technology'),
('RA031', 'Health and Wellness'),
('RA032', 'Social Sciences and Humanities'),
('RA033', 'Gender and Development'),
('RA034', 'Community Development and Social Innovation'),
('RA035', 'Public Administration and Governance'),
('RA036', 'Digital Transformation and E-Governance'),
('RA037', 'Community Extension and Technology Transfer'),
('RA038', 'Sustainable Development and Social Responsibility'),
('RA039', 'Interdisciplinary and Multidisciplinary Studies'),
('RA040', 'Other Research Areas');

INSERT INTO `category_research_areas` (`category_id`, `area_id`)
SELECT rc.`category_id`, ra.`area_id`
FROM `research_categories` rc
JOIN `research_areas` ra
WHERE
  (rc.`category_name` = 'Action Research' AND ra.`area_code` IN (
    'RA011','RA012','RA013','RA014','RA015','RA020','RA021','RA023','RA025',
    'RA032','RA033','RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Applied Research' AND ra.`area_code` IN (
    'RA001','RA002','RA003','RA004','RA005','RA006','RA007','RA008','RA009','RA010','RA011',
    'RA016','RA017','RA018','RA019','RA020','RA021','RA022','RA023','RA024','RA025','RA026',
    'RA027','RA028','RA029','RA030','RA031','RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Basic Research' AND ra.`area_code` IN (
    'RA001','RA003','RA004','RA005','RA009','RA010','RA012','RA013','RA015','RA016','RA018',
    'RA019','RA022','RA024','RA026','RA029','RA030','RA031','RA032','RA033','RA038','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Developmental Research' AND ra.`area_code` IN (
    'RA001','RA002','RA003','RA004','RA005','RA006','RA007','RA008','RA009','RA010','RA011',
    'RA013','RA016','RA017','RA021','RA025','RA026','RA027','RA028','RA030','RA034','RA036',
    'RA037','RA039','RA040'
  ))
  OR (rc.`category_name` = 'Evaluation Research' AND ra.`area_code` IN (
    'RA001','RA002','RA005','RA006','RA007','RA008','RA010','RA011','RA012','RA013','RA014',
    'RA015','RA016','RA018','RA020','RA021','RA022','RA023','RA025','RA027','RA028','RA031',
    'RA034','RA035','RA036','RA037','RA038','RA039','RA040'
  ));
