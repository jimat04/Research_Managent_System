-- Migration: Preserve student author names and programs when research is archived.

CREATE TABLE IF NOT EXISTS research_author_snapshots (
    author_snapshot_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    author_name VARCHAR(170) NOT NULL,
    program VARCHAR(120) NULL,
    author_role ENUM('lead', 'member') NOT NULL DEFAULT 'member',
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (author_snapshot_id),
    UNIQUE KEY uk_research_author_snapshot (project_id, user_id),
    KEY idx_research_author_program (program),
    KEY idx_research_author_user (user_id),
    CONSTRAINT fk_research_author_project
        FOREIGN KEY (project_id) REFERENCES research_projects (project_id) ON DELETE CASCADE,
    CONSTRAINT fk_research_author_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill projects that were archived before this snapshot table existed.
INSERT IGNORE INTO research_author_snapshots
    (project_id, user_id, author_name, program, author_role, display_order)
SELECT
    pm.project_id,
    u.user_id,
    TRIM(CONCAT(u.first_name, ' ', u.last_name)),
    NULLIF(TRIM(u.program), ''),
    pm.role,
    CASE WHEN pm.role = 'lead' THEN 0 ELSE 1 END
FROM project_members pm
INNER JOIN research_projects rp ON rp.project_id = pm.project_id
INNER JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
WHERE rp.status = 'archived';

INSERT IGNORE INTO research_author_snapshots
    (project_id, user_id, author_name, program, author_role, display_order)
SELECT
    rp.project_id,
    u.user_id,
    TRIM(CONCAT(u.first_name, ' ', u.last_name)),
    NULLIF(TRIM(u.program), ''),
    'lead',
    0
FROM research_projects rp
INNER JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
WHERE rp.status = 'archived'
  AND NOT EXISTS (
      SELECT 1
      FROM project_members pm
      WHERE pm.project_id = rp.project_id
        AND pm.user_id = u.user_id
  );
