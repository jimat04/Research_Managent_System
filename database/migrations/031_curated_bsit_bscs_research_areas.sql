-- Preserve all catalog IDs/names and all project/history associations.
-- Only first-time scoped entries receive the suggested category mappings.
-- Reapplication preserves administrator scope, labels, activation and mappings.
CREATE TABLE IF NOT EXISTS research_area_selection_scope (
  area_id int(10) unsigned NOT NULL,
  display_label varchar(180) NOT NULL,
  is_enabled tinyint(1) NOT NULL DEFAULT 1,
  display_order smallint(5) unsigned NOT NULL DEFAULT 100,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (area_id),
  KEY idx_research_area_scope_enabled (is_enabled, display_order),
  CONSTRAINT fk_research_area_scope_area FOREIGN KEY (area_id)
    REFERENCES research_areas (area_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Application Development'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Web Application Development');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Mobile Application Development'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Mobile Application Development');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Database Systems and Data Management'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Database Systems and Data Management');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Systems and Business Process Automation'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Information Systems and Business Process Automation');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Cloud Computing and Distributed Systems'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Cloud Computing and Distributed Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Algorithms and Computational Theory'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Algorithms and Computational Theory');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Internet of Things and Embedded Systems'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Internet of Things and Embedded Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Game Development and Gamification'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Game Development and Gamification');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'E-Governance and Community Informatics'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'E-Governance and Community Informatics');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Infrastructure and Technical Support'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'IT Infrastructure and Technical Support');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Quality Assurance and Testing'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Software Quality Assurance and Testing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer Vision and Natural Language Processing'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Computer Vision and Natural Language Processing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Emerging Technologies and Digital Transformation'
FROM (SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
      FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$') codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas WHERE area_name = 'Emerging Technologies and Digital Transformation');

DROP TEMPORARY TABLE IF EXISTS rms_initial_curated_areas;
CREATE TEMPORARY TABLE rms_initial_curated_areas (
  selection_number tinyint unsigned NOT NULL PRIMARY KEY,
  area_id int(10) unsigned NOT NULL UNIQUE,
  display_label varchar(180) NOT NULL
) ENGINE=InnoDB;

INSERT INTO rms_initial_curated_areas
SELECT 1, ra.area_id, 'Software Engineering and System Development' FROM research_areas ra
WHERE ra.area_code = 'RA006'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 2, ra.area_id, 'Web Application Development' FROM research_areas ra
WHERE ra.area_name = 'Web Application Development'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 3, ra.area_id, 'Mobile Application Development' FROM research_areas ra
WHERE ra.area_name = 'Mobile Application Development'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 4, ra.area_id, 'Database Systems and Data Management' FROM research_areas ra
WHERE ra.area_name = 'Database Systems and Data Management'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 5, ra.area_id, 'Information Systems and Business Process Automation' FROM research_areas ra
WHERE ra.area_name = 'Information Systems and Business Process Automation'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 6, ra.area_id, 'Computer Networking and System Administration' FROM research_areas ra
WHERE ra.area_code = 'RA009'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 7, ra.area_id, 'Cybersecurity and Information Security' FROM research_areas ra
WHERE ra.area_code = 'RA005'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 8, ra.area_id, 'Cloud Computing and Distributed Systems' FROM research_areas ra
WHERE ra.area_name = 'Cloud Computing and Distributed Systems'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 9, ra.area_id, 'Artificial Intelligence and Machine Learning' FROM research_areas ra
WHERE ra.area_code = 'RA003'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 10, ra.area_id, 'Data Science and Analytics' FROM research_areas ra
WHERE ra.area_code = 'RA004'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 11, ra.area_id, 'Algorithms and Computational Theory' FROM research_areas ra
WHERE ra.area_name = 'Algorithms and Computational Theory'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 12, ra.area_id, 'Human-Computer Interaction and User Experience' FROM research_areas ra
WHERE ra.area_code = 'RA010'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 13, ra.area_id, 'Internet of Things and Embedded Systems' FROM research_areas ra
WHERE ra.area_name = 'Internet of Things and Embedded Systems'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 14, ra.area_id, 'Game Development and Gamification' FROM research_areas ra
WHERE ra.area_name = 'Game Development and Gamification'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 15, ra.area_id, 'Educational Technology and E-Learning Systems' FROM research_areas ra
WHERE ra.area_code = 'RA011'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 16, ra.area_id, 'E-Governance and Community Informatics' FROM research_areas ra
WHERE ra.area_name = 'E-Governance and Community Informatics'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 17, ra.area_id, 'IT Infrastructure and Technical Support' FROM research_areas ra
WHERE ra.area_name = 'IT Infrastructure and Technical Support'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 18, ra.area_id, 'Software Quality Assurance and Testing' FROM research_areas ra
WHERE ra.area_name = 'Software Quality Assurance and Testing'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 19, ra.area_id, 'Computer Vision and Natural Language Processing' FROM research_areas ra
WHERE ra.area_name = 'Computer Vision and Natural Language Processing'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

INSERT INTO rms_initial_curated_areas
SELECT 20, ra.area_id, 'Emerging Technologies and Digital Transformation' FROM research_areas ra
WHERE ra.area_name = 'Emerging Technologies and Digital Transformation'
  AND NOT EXISTS (SELECT 1 FROM research_area_selection_scope s WHERE s.area_id = ra.area_id);

-- Refresh recommendations only for entries entering the scope for the first time.
-- This does not touch proposal associations, version snapshots, or non-scoped mappings.
DELETE cra FROM category_research_areas cra
JOIN rms_initial_curated_areas seed ON seed.area_id = cra.area_id;

INSERT INTO category_research_areas (category_id, area_id)
SELECT rc.category_id, seed.area_id
FROM research_categories rc
JOIN rms_initial_curated_areas seed
WHERE
  (rc.category_name = 'Action Research' AND seed.selection_number IN (5,12,15,16,17,20))
  OR   (rc.category_name = 'Applied Research' AND seed.selection_number IN (1,2,3,4,5,6,7,8,9,10,13,14,16,17,20))
  OR   (rc.category_name = 'Basic Research' AND seed.selection_number IN (4,7,8,9,10,11,12,13,19))
  OR   (rc.category_name = 'Developmental Research' AND seed.selection_number IN (1,2,3,4,5,7,8,9,13,14,15,16,18,19,20))
  OR   (rc.category_name = 'Evaluation Research' AND seed.selection_number IN (1,2,3,4,5,6,7,8,12,14,15,16,17,18,20));

INSERT INTO research_area_selection_scope (area_id, display_label, display_order)
SELECT area_id, display_label, selection_number FROM rms_initial_curated_areas;
DROP TEMPORARY TABLE rms_initial_curated_areas;
COMMIT;

