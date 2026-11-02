-- Add CS/IT course-specific categories and areas without changing the
-- existing catalog or any saved project classifications.
CREATE TABLE IF NOT EXISTS research_category_programs (
  category_id int(10) unsigned NOT NULL,
  program_id int(10) unsigned NOT NULL,
  PRIMARY KEY (category_id, program_id),
  KEY idx_research_category_programs_program (program_id),
  CONSTRAINT fk_research_category_programs_category
    FOREIGN KEY (category_id) REFERENCES research_categories (category_id) ON DELETE CASCADE,
  CONSTRAINT fk_research_category_programs_program
    FOREIGN KEY (program_id) REFERENCES programs (program_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS rms_course_category_areas;
CREATE TEMPORARY TABLE rms_course_category_areas (
  category_name varchar(120) NOT NULL,
  program_code varchar(20) NOT NULL,
  area_name varchar(180) NOT NULL,
  area_order smallint unsigned NOT NULL,
  PRIMARY KEY (category_name, area_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rms_course_category_areas (category_name, program_code, area_name, area_order) VALUES
('Current Computer Science Topics', 'BSCS', 'Software Development and Theory', 1),
('Current Computer Science Topics', 'BSCS', 'Mobile Computing Systems', 2),
('Current Computer Science Topics', 'BSCS', 'Software Extensions or Plug-ins', 3),
('Current Computer Science Topics', 'BSCS', 'Expert Systems and Decision Support Systems', 4),
('Current Computer Science Topics', 'BSCS', 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)', 5),
('Current Computer Science Topics', 'BSCS', 'Intelligent Systems', 6),
('Current Computer Science Topics', 'BSCS', 'Game Development', 7),
('Current Computer Science Topics', 'BSCS', 'Computer Vision', 8),
('Current Computer Science Topics', 'BSCS', 'Image / Signal Processing', 9),
('Current Computer Science Topics', 'BSCS', 'Natural Language Processing', 10),
('Current Computer Science Topics', 'BSCS', 'Pattern Recognition and Data Mining', 11),
('Current Computer Science Topics', 'BSCS', 'Bioinformatics', 12),
('Current Computer Science Topics', 'BSCS', 'Graphics Applications', 13),
('Current Computer Science Topics', 'BSCS', 'Cloud Computing', 14),
('Current Computer Science Topics', 'BSCS', 'Parallel Computing', 15),
('Current Computer Science Topics', 'BSCS', 'Embedded Systems', 16),
('Current Computer Science Topics', 'BSCS', 'Emerging Technologies', 17),
('Foundations of Computer Science', 'BSCS', 'Automata and Formal Languages', 18),
('Foundations of Computer Science', 'BSCS', 'Data Structures and Algorithm Design and Analysis', 19),
('Foundations of Computer Science', 'BSCS', 'Web Semantics', 20),
('Foundations of Computer Science', 'BSCS', 'Coding Theory', 21),
('Foundations of Computer Science', 'BSCS', 'Programming Languages', 22),
('Foundations of Computer Science', 'BSCS', 'Visualization Systems', 23),
('Foundations of Computer Science', 'BSCS', 'Computer and Architecture', 24),
('Foundations of Computer Science', 'BSCS', 'Modeling and Simulation', 25),
('Human-Computer Interaction', 'BSCS', 'Usability', 26),
('Human-Computer Interaction', 'BSCS', 'Affective Computing', 27),
('Human-Computer Interaction', 'BSCS', 'Empathic Computing', 28),
('Software Development', 'BSIT', 'Software Customization', 29),
('Software Development', 'BSIT', 'Information Systems Development for an Actual Client (with pilot testing)', 30),
('Software Development', 'BSIT', 'Web Applications Development (with at least alpha testing on live servers)', 31),
('Software Development', 'BSIT', 'Mobile Computing Systems', 2),
('Multimedia Systems', 'BSIT', 'Game Development', 7),
('Multimedia Systems', 'BSIT', 'e-Learning Systems', 32),
('Multimedia Systems', 'BSIT', 'Interactive Systems', 33),
('Multimedia Systems', 'BSIT', 'Information Kiosks', 34),
('Network Design and Implementation', 'BSIT', 'Network Design and Implementation', 35),
('Network Design and Implementation', 'BSIT', 'Server Farm Configuration and Management', 36),
('IT Management', 'BSIT', 'IT Strategic Plan for Sufficiently Complex Enterprises', 37),
('IT Management', 'BSIT', 'IT Security Analysis, Planning and Implementation', 38);

INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Current Computer Science Topics', 'BSCS course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Current Computer Science Topics');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Foundations of Computer Science', 'BSCS course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Foundations of Computer Science');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Human-Computer Interaction', 'BSCS course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Human-Computer Interaction');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Software Development', 'BSIT course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Software Development');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Multimedia Systems', 'BSIT course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Multimedia Systems');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'Network Design and Implementation', 'BSIT course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'Network Design and Implementation');
INSERT INTO research_categories (category_id, category_name, description, status)
SELECT (SELECT COALESCE(MAX(category_id), 0) + 1 FROM research_categories), 'IT Management', 'BSIT course-specific research category', 1
WHERE NOT EXISTS (SELECT 1 FROM research_categories existing WHERE existing.category_name = 'IT Management');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Development and Theory'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Development and Theory');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Mobile Computing Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Mobile Computing Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Extensions or Plug-ins'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Extensions or Plug-ins');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Expert Systems and Decision Support Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Expert Systems and Decision Support Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Systems Software (software toolkits, interpreters, simulators, compilers, security aspects)');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Intelligent Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Intelligent Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Game Development'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Game Development');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer Vision'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Computer Vision');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Image / Signal Processing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Image / Signal Processing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Natural Language Processing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Natural Language Processing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Pattern Recognition and Data Mining'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Pattern Recognition and Data Mining');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Bioinformatics'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Bioinformatics');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Graphics Applications'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Graphics Applications');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Cloud Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Cloud Computing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Parallel Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Parallel Computing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Embedded Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Embedded Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Emerging Technologies'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Emerging Technologies');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Automata and Formal Languages'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Automata and Formal Languages');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Data Structures and Algorithm Design and Analysis'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Data Structures and Algorithm Design and Analysis');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Semantics'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Web Semantics');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Coding Theory'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Coding Theory');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Programming Languages'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Programming Languages');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Visualization Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Visualization Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Computer and Architecture'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Computer and Architecture');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Modeling and Simulation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Modeling and Simulation');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Usability'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Usability');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Affective Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Affective Computing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Empathic Computing'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Empathic Computing');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Software Customization'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Software Customization');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Systems Development for an Actual Client (with pilot testing)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Information Systems Development for an Actual Client (with pilot testing)');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Web Applications Development (with at least alpha testing on live servers)'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Web Applications Development (with at least alpha testing on live servers)');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'e-Learning Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'e-Learning Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Interactive Systems'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Interactive Systems');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Information Kiosks'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Information Kiosks');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Network Design and Implementation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Network Design and Implementation');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'Server Farm Configuration and Management'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'Server Farm Configuration and Management');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Strategic Plan for Sufficiently Complex Enterprises'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'IT Strategic Plan for Sufficiently Complex Enterprises');

INSERT INTO research_areas (area_code, area_name)
SELECT CONCAT('RA', LPAD(next_code, GREATEST(3, CHAR_LENGTH(next_code)), '0')), 'IT Security Analysis, Planning and Implementation'
FROM (
  SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
  FROM research_areas WHERE area_code REGEXP '^RA[0-9]+$'
) codes
WHERE NOT EXISTS (SELECT 1 FROM research_areas existing WHERE existing.area_name = 'IT Security Analysis, Planning and Implementation');

INSERT INTO research_area_selection_scope (area_id, display_label, display_order)
SELECT ra.area_id, ra.area_name, 100 + area_names.area_order
FROM (
  SELECT area_name, MIN(area_order) AS area_order
  FROM rms_course_category_areas
  GROUP BY area_name
) area_names
JOIN research_areas ra ON ra.area_name = area_names.area_name
LEFT JOIN research_area_selection_scope existing ON existing.area_id = ra.area_id
WHERE existing.area_id IS NULL;

INSERT INTO category_research_areas (category_id, area_id)
SELECT rc.category_id, ra.area_id
FROM rms_course_category_areas source
JOIN research_categories rc ON rc.category_name = source.category_name
JOIN research_areas ra ON ra.area_name = source.area_name
LEFT JOIN category_research_areas existing
  ON existing.category_id = rc.category_id AND existing.area_id = ra.area_id
WHERE existing.category_id IS NULL;

INSERT INTO research_category_programs (category_id, program_id)
SELECT rc.category_id, p.program_id
FROM (
  SELECT category_name, MIN(program_code) AS program_code
  FROM rms_course_category_areas
  GROUP BY category_name
) source
JOIN research_categories rc ON rc.category_name = source.category_name
JOIN programs p ON p.program_code = source.program_code
LEFT JOIN research_category_programs existing
  ON existing.category_id = rc.category_id AND existing.program_id = p.program_id
WHERE existing.category_id IS NULL;

DROP TEMPORARY TABLE rms_course_category_areas;
