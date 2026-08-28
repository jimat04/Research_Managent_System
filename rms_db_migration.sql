-- RMS schema hardening migration.
-- Target: MySQL 8.0+/MariaDB 10.4+. Run against rms_db after a backup.
-- This file alters existing tables only; it does not drop tables or data.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Standardize storage engine and collation.
ALTER TABLE academic_years ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE activity_log ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE messages ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chapters ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chapter_content ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE comments ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE defense_schedule ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE departments ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notifications ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE programs ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_advisers ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_members ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE research_categories ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE research_projects ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE uploads ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users ENGINE = InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Add the standard timestamp columns and requested soft-delete columns.
ALTER TABLE academic_years ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE activity_log ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE messages ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE chapters ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD deleted_at DATETIME NULL;
ALTER TABLE chapter_content ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE comments ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD deleted_at DATETIME NULL;
ALTER TABLE defense_schedule ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE departments ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE notifications ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD read_at DATETIME NULL;
ALTER TABLE programs ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE project_advisers ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE project_members ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE research_categories ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE uploads ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL, MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD deleted_at DATETIME NULL;
ALTER TABLE research_projects ADD student_id INT UNSIGNED NULL, ADD adviser_id INT UNSIGNED NULL, ADD deleted_at DATETIME NULL, MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE defense_schedule ADD research_id INT UNSIGNED NULL;

-- 3. Normalize existing timestamp columns to DATETIME.
ALTER TABLE academic_years MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE activity_log MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE messages MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE chapter_content MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE comments MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE defense_schedule MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE notifications MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE project_advisers MODIFY assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE research_projects MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE users MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 4. Preserve application compatibility while adding the requested relationship names.
ALTER TABLE chapters ADD research_id INT UNSIGNED NULL;
UPDATE chapters SET research_id = project_id WHERE research_id IS NULL;
ALTER TABLE comments ADD user_id INT UNSIGNED NULL, ADD parent_id INT UNSIGNED NULL;
UPDATE comments SET user_id = faculty_id WHERE user_id IS NULL;
ALTER TABLE uploads ADD research_id INT UNSIGNED NULL, ADD user_id INT UNSIGNED NULL;
UPDATE uploads SET research_id = project_id WHERE research_id IS NULL;
UPDATE uploads SET user_id = uploaded_by WHERE user_id IS NULL;
UPDATE defense_schedule SET research_id = project_id WHERE research_id IS NULL;
UPDATE research_projects rp SET student_id = created_by WHERE student_id IS NULL;
UPDATE research_projects rp SET adviser_id = (SELECT pa.adviser_id FROM project_advisers pa WHERE pa.project_id = rp.project_id ORDER BY pa.assigned_at, pa.adviser_id LIMIT 1) WHERE adviser_id IS NULL;

-- 5. Bring content and user statuses to the requested finite sets.
UPDATE research_projects SET status = CASE status WHEN 'proposal' THEN 'submitted' WHEN 'in_progress' THEN 'revised' WHEN 'for_defense' THEN 'approved' WHEN 'completed' THEN 'approved' WHEN 'archived' THEN 'approved' ELSE status END;
UPDATE chapters SET status = CASE status WHEN 'under_review' THEN 'submitted' WHEN 'revision_required' THEN 'revised' ELSE status END;
UPDATE users SET status = 'suspended' WHERE status = 'inactive';
ALTER TABLE research_projects MODIFY status ENUM('draft','submitted','revised','approved','rejected') NOT NULL DEFAULT 'draft';
ALTER TABLE chapters MODIFY status ENUM('draft','submitted','revised','approved','rejected') NOT NULL DEFAULT 'draft';
ALTER TABLE users MODIFY status ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending';

-- 6. Fix non-conforming generic primary-key names.
ALTER TABLE project_advisers CHANGE COLUMN id project_adviser_id INT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE project_members CHANGE COLUMN id project_member_id INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- 7. Replace user relationship actions required for retained history.
ALTER TABLE research_projects DROP FOREIGN KEY research_projects_ibfk_3;
ALTER TABLE research_projects ADD CONSTRAINT fk_research_projects_created_by_users FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT;
ALTER TABLE comments DROP FOREIGN KEY comments_ibfk_2;
ALTER TABLE comments MODIFY faculty_id INT UNSIGNED NULL;
ALTER TABLE comments ADD CONSTRAINT fk_comments_faculty_users FOREIGN KEY (faculty_id) REFERENCES users (user_id) ON DELETE SET NULL;
ALTER TABLE project_advisers DROP FOREIGN KEY project_advisers_ibfk_2;
ALTER TABLE project_advisers MODIFY adviser_id INT UNSIGNED NULL;
ALTER TABLE project_advisers ADD CONSTRAINT fk_project_advisers_adviser_users FOREIGN KEY (adviser_id) REFERENCES users (user_id) ON DELETE SET NULL;
ALTER TABLE uploads DROP FOREIGN KEY uploads_ibfk_3;
ALTER TABLE uploads MODIFY uploaded_by INT UNSIGNED NULL;
ALTER TABLE uploads ADD CONSTRAINT fk_uploads_uploaded_by_users FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE SET NULL;

-- 8. Add explicit foreign keys for the requested relationship columns.
ALTER TABLE chapters ADD CONSTRAINT fk_chapters_research_projects FOREIGN KEY (research_id) REFERENCES research_projects (project_id) ON DELETE CASCADE;
ALTER TABLE comments ADD CONSTRAINT fk_comments_user_users FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL, ADD CONSTRAINT fk_comments_parent_comments FOREIGN KEY (parent_id) REFERENCES comments (comment_id) ON DELETE CASCADE;
ALTER TABLE uploads ADD CONSTRAINT fk_uploads_research_projects FOREIGN KEY (research_id) REFERENCES research_projects (project_id) ON DELETE CASCADE, ADD CONSTRAINT fk_uploads_user_users FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL;
ALTER TABLE defense_schedule ADD CONSTRAINT fk_defense_schedule_research_projects FOREIGN KEY (research_id) REFERENCES research_projects (project_id) ON DELETE CASCADE;
ALTER TABLE research_projects ADD CONSTRAINT fk_research_projects_student_users FOREIGN KEY (student_id) REFERENCES users (user_id) ON DELETE RESTRICT, ADD CONSTRAINT fk_research_projects_adviser_users FOREIGN KEY (adviser_id) REFERENCES users (user_id) ON DELETE SET NULL;

-- Existing requested relationships, restated with explicit names/actions.
ALTER TABLE chapters DROP FOREIGN KEY chapters_ibfk_1;
ALTER TABLE chapters ADD CONSTRAINT fk_chapters_project_projects FOREIGN KEY (project_id) REFERENCES research_projects (project_id) ON DELETE CASCADE;
ALTER TABLE chapter_content DROP FOREIGN KEY chapter_content_ibfk_1;
ALTER TABLE chapter_content ADD CONSTRAINT fk_chapter_content_chapter_chapters FOREIGN KEY (chapter_id) REFERENCES chapters (chapter_id) ON DELETE CASCADE;
ALTER TABLE comments DROP FOREIGN KEY comments_ibfk_1;
ALTER TABLE comments ADD CONSTRAINT fk_comments_chapter_chapters FOREIGN KEY (chapter_id) REFERENCES chapters (chapter_id) ON DELETE CASCADE;
ALTER TABLE notifications DROP FOREIGN KEY notifications_ibfk_1;
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user_users FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE;

-- 9. Add indexes for common dashboard/list filters.
ALTER TABLE users ADD KEY idx_users_deleted_at (deleted_at), ADD KEY idx_users_role_status (role, status);
ALTER TABLE research_projects ADD KEY idx_projects_student_status (student_id, status), ADD KEY idx_projects_adviser_status (adviser_id, status), ADD KEY idx_projects_created_at (created_at), ADD KEY idx_projects_deleted_at (deleted_at);
ALTER TABLE chapters ADD KEY idx_chapters_research_status (research_id, status), ADD KEY idx_chapters_created_at (created_at), ADD KEY idx_chapters_deleted_at (deleted_at);
ALTER TABLE comments ADD KEY idx_comments_user_created (user_id, created_at), ADD KEY idx_comments_parent (parent_id), ADD KEY idx_comments_deleted_at (deleted_at);
ALTER TABLE uploads ADD KEY idx_uploads_research_created (research_id, created_at), ADD KEY idx_uploads_user_created (user_id, created_at);
ALTER TABLE notifications ADD KEY idx_notifications_read_at (user_id, read_at), ADD KEY idx_notifications_created_at (created_at);
ALTER TABLE defense_schedule ADD KEY idx_defense_research_status (research_id, status), ADD KEY idx_defense_created_at (created_at);
ALTER TABLE activity_log ADD KEY idx_activity_user_created (user_id, created_at);

-- 10. users.email already has a UNIQUE KEY named email in rms_db.sql; verify it before running.
-- No password UPDATE is emitted: the three README demo hashes match their bcrypt passwords.

SET FOREIGN_KEY_CHECKS = 1;