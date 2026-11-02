CREATE TABLE IF NOT EXISTS adviser_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  requester_id INT UNSIGNED NOT NULL,
  faculty_id INT UNSIGNED NOT NULL,
  upload_id INT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  decided_at DATETIME DEFAULT NULL,
  pending_project_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN project_id ELSE NULL END) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id),
  UNIQUE KEY uq_adviser_requests_pending_project (pending_project_id),
  KEY idx_adviser_requests_faculty_status (faculty_id, status, created_at),
  KEY idx_adviser_requests_project_created (project_id, created_at),
  KEY idx_adviser_requests_requester (requester_id, created_at),
  CONSTRAINT fk_adviser_requests_project FOREIGN KEY (project_id) REFERENCES research_projects (project_id) ON DELETE CASCADE,
  CONSTRAINT fk_adviser_requests_requester FOREIGN KEY (requester_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_adviser_requests_faculty FOREIGN KEY (faculty_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_adviser_requests_upload FOREIGN KEY (upload_id) REFERENCES uploads (upload_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adviser_request_messages (
  message_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  PRIMARY KEY (message_id),
  KEY idx_adviser_request_messages_thread (request_id, created_at, message_id),
  KEY idx_adviser_request_messages_sender (sender_id, created_at),
  CONSTRAINT fk_adviser_request_messages_request FOREIGN KEY (request_id) REFERENCES adviser_requests (request_id) ON DELETE CASCADE,
  CONSTRAINT fk_adviser_request_messages_sender FOREIGN KEY (sender_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;