<?php
function rms_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rms_status($value) {
    return ucwords(str_replace('_', ' ', (string) $value));
}

function rms_rows($result) {
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function rms_project_access($project_id, $user) {
    global $conn;
    $project_id = (int) $project_id;
    $user_id = (int) $user['user_id'];
    if ($user['role'] === 'admin') return true;
    if ($user['role'] === 'student') {
        $stmt = $conn->prepare('SELECT project_id FROM research_projects WHERE project_id = ? AND created_by = ?');
        $stmt->bind_param('ii', $project_id, $user_id);
    } else {
        $stmt = $conn->prepare('SELECT rp.project_id FROM research_projects rp JOIN project_advisers pa ON pa.project_id = rp.project_id WHERE rp.project_id = ? AND pa.adviser_id = ?');
        $stmt->bind_param('ii', $project_id, $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result && $result->num_rows > 0;
}

function rms_handle_module_action($page_key, $user) {
    global $conn;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $user_id = (int) $user['user_id'];

    if ($page_key === 'submit-research.php' && $user['role'] === 'student') {
        $title = trim($_POST['title'] ?? '');
        $area = trim($_POST['research_area'] ?? '');
        $abstract = trim($_POST['abstract'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        if ($title === '') {
            $_SESSION['module_error'] = 'Research title is required.';
        } else {
            $statement = $conn->prepare('INSERT INTO research_projects (title, category_id, research_area, abstract, created_by) VALUES (?, NULLIF(?, 0), ?, ?, ?)');
            $statement->bind_param('sissi', $title, $category_id, $area, $abstract, $user_id);
            if ($statement->execute()) {
                logActivity('Created research project', 'research');
                header('Location: view-research.php?id=' . $statement->insert_id);
                exit;
            }
            $_SESSION['module_error'] = 'The research project could not be saved.';
        }
    }

    if ($page_key === 'notifications.php' && isset($_POST['notification_id'])) {
        $notification_id = (int) $_POST['notification_id'];
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $notification_id, $user_id);
        $stmt->execute();
    }

    if ($page_key === 'messages.php') {
        if (isset($_POST['message_id'])) {
            $message_id = (int) $_POST['message_id'];
            $stmt = $conn->prepare('UPDATE messages SET is_read = 1 WHERE message_id = ? AND recipient_id = ?');
            $stmt->bind_param('ii', $message_id, $user_id);
            $stmt->execute();
        } elseif (isset($_POST['recipient_id'])) {
            $recipient_id = (int) $_POST['recipient_id'];
            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $recipient_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND status = 'active'");
            $recipient_stmt->bind_param('i', $recipient_id);
            $recipient_stmt->execute();
            $recipient = $recipient_stmt->get_result();
            if ($recipient_id === $user_id || $subject === '' || $message === '' || !$recipient || $recipient->num_rows === 0) {
                $_SESSION['module_error'] = 'Choose an active recipient and complete all message fields.';
            } else {
                $statement = $conn->prepare('INSERT INTO messages (sender_id, recipient_id, subject, message) VALUES (?, ?, ?, ?)');
                $statement->bind_param('iiss', $user_id, $recipient_id, $subject, $message);
                if ($statement->execute()) {
                    createNotification($recipient_id, 'New message', 'You received a new message from ' . $user['first_name'], 'info', 'pages/shared/messages.php');
                    logActivity('Sent a message', 'messages');
                    $_SESSION['module_success'] = 'Message sent successfully.';
                } else {
                    $_SESSION['module_error'] = 'The message could not be sent.';
                }
            }
        }
    }

    if ($page_key === 'profile.php') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        if ($first_name === '' || $last_name === '') {
            $_SESSION['module_error'] = 'First name and last name are required.';
        } else {
            $statement = $conn->prepare('UPDATE users SET first_name = ?, last_name = ?, contact = ? WHERE user_id = ?');
            $statement->bind_param('sssi', $first_name, $last_name, $contact, $user_id);
            $statement->execute();
            $_SESSION['module_success'] = 'Profile updated successfully.';
        }
    }

    if ($page_key === 'settings.php' && !empty($_POST['new_password'])) {
        $new_password = $_POST['new_password'];
        if (strlen($new_password) < 8 || $new_password !== ($_POST['confirm_password'] ?? '')) {
            $_SESSION['module_error'] = 'Passwords must match and contain at least 8 characters.';
        } else {
            $password_hash = hashPassword($new_password);
            $statement = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $statement->bind_param('si', $password_hash, $user_id);
            $statement->execute();
            $_SESSION['module_success'] = 'Password updated successfully.';
        }
    }

    if ($page_key === 'faculty-review-detail.php' && $user['role'] === 'faculty') {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed_statuses = ['proposal', 'in_progress', 'for_defense', 'completed', 'archived'];
        if (rms_project_access($project_id, $user) && in_array($status, $allowed_statuses, true)) {
            $statement = $conn->prepare('UPDATE research_projects SET status = ? WHERE project_id = ?');
            $statement->bind_param('si', $status, $project_id);
            $statement->execute();
            $comment = trim($_POST['comment'] ?? '');
            $chapter_id = (int) ($_POST['chapter_id'] ?? 0);
            if ($comment !== '' && $chapter_id > 0) {
                $comment_statement = $conn->prepare('INSERT INTO comments (chapter_id, faculty_id, comment) VALUES (?, ?, ?)');
                $comment_statement->bind_param('iis', $chapter_id, $user_id, $comment);
                $comment_statement->execute();
            }
            logActivity('Updated research review', 'review');
            $_SESSION['module_success'] = 'Review update saved.';
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

function rms_message($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        $color = $type === 'error' ? '#ef4444' : '#22c55e';
        echo '<div style="margin-bottom:20px;padding:14px 18px;border-left:4px solid ' . $color . ';background:#fff;color:#334155">' . rms_escape($message) . '</div>';
    }
}

function rms_table($headers, $rows, $empty = 'No records found.') {
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $header) echo '<th>' . rms_escape($header) . '</th>';
    echo '</tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:24px;color:var(--text-muted)">' . rms_escape($empty) . '</td></tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td>' . $cell . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function rms_render_module($page_key, $user, $module) {
    global $conn;
    $user_id = (int) $user['user_id'];
    rms_message('error');
    rms_message('success');

    if ($page_key === 'submit-research.php') {
        $categories = rms_rows($conn->query('SELECT category_id, category_name FROM research_categories WHERE status = 1 ORDER BY category_name'));
        echo '<div class="card" style="max-width:820px"><div class="card-header"><div><div class="card-title">New Research Submission</div><div class="card-subtitle">Create a project record to begin tracking your research.</div></div></div><div class="card-body"><form method="post">' . csrfField() . '<label>Research title<br><input class="form-control" name="title" required maxlength="255"></label><br><label>Research area<br><input class="form-control" name="research_area" maxlength="150"></label><br><label>Category<br><select class="form-control" name="category_id"><option value="0">Select a category</option>';
        foreach ($categories as $category) echo '<option value="' . (int) $category['category_id'] . '">' . rms_escape($category['category_name']) . '</option>';
        echo '</select></label><br><label>Abstract<br><textarea class="form-control" name="abstract" rows="7"></textarea></label><br><button class="btn btn-primary" type="submit">Create Research Project</button></form></div></div>';
        return;
    }

    if ($page_key === 'my-research.php') {
        $stmt = $conn->prepare('SELECT project_id, title, status, created_at FROM research_projects WHERE created_by = ? ORDER BY created_at DESC');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [rms_escape($row['title']), rms_escape(rms_status($row['status'])), date('M d, Y', strtotime($row['created_at'])), '<a class="btn btn-primary btn-sm" href="view-research.php?id=' . (int) $row['project_id'] . '">View</a>']; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">My Research Projects</div><a class="btn btn-primary btn-sm" href="submit-research.php">New Submission</a></div>';
        rms_table(['Title', 'Status', 'Created', 'Action'], $formatted);
        echo '</div>';
        return;
    }

    if ($page_key === 'view-research.php') {
        $project_id = (int) ($_GET['id'] ?? 0);
        if (!rms_project_access($project_id, $user)) { echo '<div class="card"><h2>Research not found</h2><p>You do not have access to this project.</p></div>'; return; }
        $project_stmt = $conn->prepare('SELECT rp.*, rc.category_name FROM research_projects rp LEFT JOIN research_categories rc ON rc.category_id = rp.category_id WHERE rp.project_id = ?');
        $project_stmt->bind_param('i', $project_id);
        $project_stmt->execute();
        $project = $project_stmt->get_result()->fetch_assoc();
        $chapters_stmt = $conn->prepare('SELECT chapter_id, chapter_number, chapter_title, status FROM chapters WHERE project_id = ? ORDER BY chapter_number');
        $chapters_stmt->bind_param('i', $project_id);
        $chapters_stmt->execute();
        $chapters = rms_rows($chapters_stmt->get_result());
        echo '<div class="card"><div class="card-header"><div><div class="card-title">' . rms_escape($project['title']) . '</div><div class="card-subtitle">' . rms_escape($project['category_name'] ?? 'Uncategorized') . ' · ' . rms_escape(rms_status($project['status'])) . '</div></div></div><div class="card-body"><p>' . nl2br(rms_escape($project['abstract'] ?? 'No abstract provided.')) . '</p><p><strong>Research area:</strong> ' . rms_escape($project['research_area'] ?? 'Not specified') . '</p></div></div><div class="card"><div class="card-header"><div class="card-title">Chapter Progress</div></div>';
        $chapter_rows = array_map(function ($row) { return ['Chapter ' . (int) $row['chapter_number'], rms_escape($row['chapter_title']), rms_escape(rms_status($row['status']))]; }, $chapters);
        rms_table(['Chapter', 'Title', 'Status'], $chapter_rows, 'No chapters have been created yet.');
        echo '</div>';
        return;
    }

    if ($page_key === 'my-documents.php') {
        if ($user['role'] === 'admin') {
            $stmt = $conn->prepare('SELECT u.original_name, u.type, u.file_size, u.upload_date, rp.title FROM uploads u JOIN research_projects rp ON rp.project_id = u.project_id ORDER BY u.upload_date DESC');
        } else {
            $stmt = $conn->prepare('SELECT u.original_name, u.type, u.file_size, u.upload_date, rp.title FROM uploads u JOIN research_projects rp ON rp.project_id = u.project_id WHERE rp.created_by = ? ORDER BY u.upload_date DESC');
            $stmt->bind_param('i', $user_id);
        }
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [rms_escape($row['original_name']), rms_escape($row['title']), rms_escape(ucfirst($row['type'])), number_format(((int) $row['file_size']) / 1024, 1) . ' KB', date('M d, Y', strtotime($row['upload_date']))]; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Documents</div></div>'; rms_table(['File', 'Research', 'Type', 'Size', 'Uploaded'], $formatted); echo '</div>'; return;
    }

    if ($page_key === 'progress-tracking.php') {
        $stmt = $conn->prepare('SELECT rp.title, c.chapter_number, c.chapter_title, c.status FROM chapters c JOIN research_projects rp ON rp.project_id = c.project_id WHERE rp.created_by = ? ORDER BY rp.title, c.chapter_number');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [rms_escape($row['title']), 'Chapter ' . (int) $row['chapter_number'], rms_escape($row['chapter_title']), rms_escape(rms_status($row['status']))]; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Research Progress</div></div>'; rms_table(['Research', 'Chapter', 'Title', 'Status'], $formatted, 'Chapter progress will appear after chapters are added.'); echo '</div>'; return;
    }

    if ($page_key === 'notifications.php') {
        $stmt = $conn->prepare('SELECT notification_id, title, message, type, is_read, link, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) use ($user_id) {
            $actions = '';

            // Check if this is a message notification (link points to messages.php)
            $is_message_notification = !empty($row['link']) && strpos($row['link'], 'messages.php') !== false;

            if (!$row['is_read']) {
                $actions .= '<form method="post" style="display:inline; margin-right: 8px;">' . csrfField() .
                           '<input type="hidden" name="notification_id" value="' . (int) $row['notification_id'] . '">' .
                           '<button class="btn btn-primary btn-sm">Mark read</button></form>';
            } else {
                $actions .= '<span style="color: var(--text-light); font-size: 0.9rem;">Read</span>';
            }

            // Add Reply button for message notifications
            if ($is_message_notification) {
                $actions .= ' <button class="btn btn-secondary btn-sm" onclick="location.href=\'messages.php\'">Reply</button>';
            }

            return [
                rms_escape($row['title']),
                rms_escape($row['message']),
                rms_escape(ucfirst($row['type'])),
                date('M d, Y H:i', strtotime($row['created_at'])),
                $actions
            ];
        }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Notifications</div></div>';
        rms_table(['Title', 'Message', 'Type', 'Date', 'Action'], $formatted, 'You have no notifications.');
        echo '</div>';
        return;
    }

    if ($page_key === 'calendar.php') {
        if ($user['role'] === 'admin') {
            $stmt = $conn->prepare('SELECT ds.schedule_date, ds.type, ds.venue, ds.status, rp.title FROM defense_schedule ds JOIN research_projects rp ON rp.project_id = ds.project_id ORDER BY ds.schedule_date');
        } else {
            $stmt = $conn->prepare('SELECT ds.schedule_date, ds.type, ds.venue, ds.status, rp.title FROM defense_schedule ds JOIN research_projects rp ON rp.project_id = ds.project_id WHERE ds.project_id IN (SELECT project_id FROM research_projects WHERE created_by = ?) ORDER BY ds.schedule_date');
            $stmt->bind_param('i', $user_id);
        }
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [date('M d, Y H:i', strtotime($row['schedule_date'])), rms_escape($row['title']), rms_escape(ucwords(str_replace('_', ' ', $row['type']))), rms_escape($row['venue'] ?? 'TBA'), rms_escape(ucwords($row['status']))]; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Research Calendar</div></div>'; rms_table(['Date', 'Research', 'Defense Type', 'Venue', 'Status'], $formatted, 'No defense schedules have been posted.'); echo '</div>'; return;
    }

    if ($page_key === 'settings.php') {
        echo '<div class="card" style="max-width:700px"><div class="card-header"><div class="card-title">Account Settings</div></div><div class="card-body"><form method="post">' . csrfField() . '<label>New password<br><input class="form-control" type="password" name="new_password" minlength="8" required></label><br><label>Confirm password<br><input class="form-control" type="password" name="confirm_password" minlength="8" required></label><br><button class="btn btn-primary">Update Password</button></form></div></div>'; return;
    }

    if ($page_key === 'messages.php') {
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, role FROM users WHERE status = 'active' AND user_id <> ? ORDER BY last_name, first_name");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $recipients = rms_rows($stmt->get_result());

        $stmt = $conn->prepare("SELECT m.message_id, m.subject, m.message, m.is_read, m.created_at, m.sender_id, CASE WHEN m.sender_id = 0 THEN 'System' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS sender_name FROM messages m LEFT JOIN users u ON u.user_id = m.sender_id WHERE m.recipient_id = ? ORDER BY m.created_at DESC");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $inbox = rms_rows($stmt->get_result());

        $stmt = $conn->prepare("SELECT m.subject, m.message, m.created_at, CONCAT(u.first_name, ' ', u.last_name) AS recipient_name FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE m.sender_id = ? ORDER BY m.created_at DESC LIMIT 20");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $sent = rms_rows($stmt->get_result());
        echo '<div class="card" style="max-width:820px"><div class="card-header"><div><div class="card-title">Compose Message</div><div class="card-subtitle">Send a message to an active RMS user.</div></div></div><div class="card-body"><form method="post">' . csrfField() . '<label>Recipient<br><select class="form-control" name="recipient_id" required><option value="">Select recipient</option>';
        foreach ($recipients as $recipient) echo '<option value="' . (int) $recipient['user_id'] . '">' . rms_escape($recipient['first_name'] . ' ' . $recipient['last_name'] . ' (' . ucfirst($recipient['role']) . ')') . '</option>';
        echo '</select></label><br><label>Subject<br><input class="form-control" name="subject" maxlength="160" required></label><br><label>Message<br><textarea class="form-control" name="message" rows="5" required></textarea></label><br><button class="btn btn-primary">Send Message</button></form></div></div>';
        $inbox_rows = array_map(function ($row) {
            $sender_display = $row['sender_id'] == 0 ? '<strong style="color: var(--primary);">🔔 ' . rms_escape($row['sender_name']) . '</strong>' : rms_escape($row['sender_name']);
            return [$sender_display, rms_escape($row['subject']), nl2br(rms_escape($row['message'])), date('M d, Y H:i', strtotime($row['created_at'])), $row['is_read'] ? 'Read' : '<form method="post" style="display:inline">' . csrfField() . '<input type="hidden" name="message_id" value="' . (int) $row['message_id'] . '"><button class="btn btn-primary btn-sm">Mark read</button></form>'];
        }, $inbox);
        echo '<div class="card"><div class="card-header"><div class="card-title">Inbox</div></div>'; rms_table(['From', 'Subject', 'Message', 'Date', 'Status'], $inbox_rows, 'Your inbox is empty.'); echo '</div>';
        $sent_rows = array_map(function ($row) { return [rms_escape($row['recipient_name']), rms_escape($row['subject']), nl2br(rms_escape($row['message'])), date('M d, Y H:i', strtotime($row['created_at']))]; }, $sent);
        echo '<div class="card"><div class="card-header"><div class="card-title">Sent Messages</div></div>'; rms_table(['To', 'Subject', 'Message', 'Date'], $sent_rows, 'You have not sent any messages.'); echo '</div>'; return;
    }

    if ($page_key === 'profile.php') {
        echo '<div class="card" style="max-width:700px"><div class="card-header"><div class="card-title">Account Profile</div></div><div class="card-body"><form method="post">' . csrfField() . '<label>First name<br><input class="form-control" name="first_name" value="' . rms_escape($user['first_name']) . '" required></label><br><label>Last name<br><input class="form-control" name="last_name" value="' . rms_escape($user['last_name']) . '" required></label><br><label>Email<br><input class="form-control" value="' . rms_escape($user['email']) . '" disabled></label><br><label>Contact<br><input class="form-control" name="contact" value="' . rms_escape($user['contact'] ?? '') . '"></label><br><button class="btn btn-primary">Save Profile</button></form></div></div>'; return;
    }

    if ($page_key === 'faculty-submissions.php' || $page_key === 'faculty-review.php' || $page_key === 'faculty-students.php') {
        if ($page_key === 'faculty-students.php') {
            $stmt = $conn->prepare('SELECT DISTINCT u.first_name, u.last_name, u.email, u.program FROM users u JOIN research_projects rp ON rp.created_by = u.user_id JOIN project_advisers pa ON pa.project_id = rp.project_id WHERE pa.adviser_id = ? ORDER BY u.last_name');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $rows = rms_rows($stmt->get_result());
            $formatted = array_map(function ($row) { return [rms_escape($row['first_name'] . ' ' . $row['last_name']), rms_escape($row['email']), rms_escape($row['program'] ?? 'Not specified')]; }, $rows);
            echo '<div class="card"><div class="card-header"><div class="card-title">My Students</div></div>'; rms_table(['Student', 'Email', 'Program'], $formatted, 'No students are assigned yet.'); echo '</div>'; return;
        }
        $stmt = $conn->prepare('SELECT rp.project_id, rp.title, rp.status, u.first_name, u.last_name, rp.created_at FROM research_projects rp JOIN users u ON u.user_id = rp.created_by JOIN project_advisers pa ON pa.project_id = rp.project_id WHERE pa.adviser_id = ? ORDER BY rp.created_at DESC');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [rms_escape($row['title']), rms_escape($row['first_name'] . ' ' . $row['last_name']), rms_escape(rms_status($row['status'])), date('M d, Y', strtotime($row['created_at'])), '<a class="btn btn-primary btn-sm" href="faculty-review-detail.php?id=' . (int) $row['project_id'] . '">Review</a>']; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">' . rms_escape($module[0]) . '</div></div>'; rms_table(['Research', 'Student', 'Status', 'Date', 'Action'], $formatted, 'No assigned submissions found.'); echo '</div>'; return;
    }

    if ($page_key === 'faculty-review-detail.php') {
        $project_id = (int) ($_GET['id'] ?? 0);
        if (!rms_project_access($project_id, $user)) { echo '<div class="card"><h2>Research not found</h2><p>This project is not assigned to you.</p></div>'; return; }
        $project_stmt = $conn->prepare('SELECT rp.*, u.first_name, u.last_name FROM research_projects rp JOIN users u ON u.user_id = rp.created_by WHERE rp.project_id = ?');
        $project_stmt->bind_param('i', $project_id);
        $project_stmt->execute();
        $project = $project_stmt->get_result()->fetch_assoc();
        $chapters_stmt = $conn->prepare('SELECT chapter_id, chapter_number, chapter_title, status FROM chapters WHERE project_id = ? ORDER BY chapter_number');
        $chapters_stmt->bind_param('i', $project_id);
        $chapters_stmt->execute();
        $chapters = rms_rows($chapters_stmt->get_result());
        echo '<div class="card"><div class="card-header"><div class="card-title">Review: ' . rms_escape($project['title']) . '</div></div><div class="card-body"><p><strong>Student:</strong> ' . rms_escape($project['first_name'] . ' ' . $project['last_name']) . '</p><p>' . nl2br(rms_escape($project['abstract'] ?? 'No abstract provided.')) . '</p><form method="post">' . csrfField() . '<input type="hidden" name="project_id" value="' . $project_id . '"><label>Project status<br><select class="form-control" name="status">';
        foreach (['proposal', 'in_progress', 'for_defense', 'completed', 'archived'] as $status) echo '<option value="' . $status . '"' . ($project['status'] === $status ? ' selected' : '') . '>' . rms_escape(rms_status($status)) . '</option>';
        echo '</select></label><br><label>Chapter for comment<br><select class="form-control" name="chapter_id"><option value="0">General project update</option>'; foreach ($chapters as $chapter) echo '<option value="' . (int) $chapter['chapter_id'] . '">Chapter ' . (int) $chapter['chapter_number'] . ' - ' . rms_escape($chapter['chapter_title']) . '</option>'; echo '</select></label><br><label>Feedback<br><textarea class="form-control" name="comment" rows="5"></textarea></label><br><button class="btn btn-primary">Save Review</button></form></div></div>'; return;
    }

    if ($page_key === 'faculty-reports.php') {
        $stmt = $conn->prepare('SELECT rp.status, COUNT(*) AS total FROM research_projects rp JOIN project_advisers pa ON pa.project_id = rp.project_id WHERE pa.adviser_id = ? GROUP BY rp.status ORDER BY rp.status');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = rms_rows($stmt->get_result());
        $formatted = array_map(function ($row) { return [rms_escape(rms_status($row['status'])), (int) $row['total']]; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Assigned Research Report</div></div>'; rms_table(['Status', 'Projects'], $formatted, 'No assigned projects found.'); echo '</div>'; return;
    }

    if ($page_key === 'research-archive.php') {
        $rows = rms_rows($conn->query("SELECT rp.project_id, rp.title, rp.research_area, rp.updated_at, CONCAT(u.first_name, ' ', u.last_name) AS owner FROM research_projects rp JOIN users u ON u.user_id = rp.created_by WHERE rp.status IN ('completed', 'archived') ORDER BY rp.updated_at DESC"));
        $formatted = array_map(function ($row) { return [rms_escape($row['title']), rms_escape($row['owner']), rms_escape($row['research_area'] ?? 'Not specified'), date('M d, Y', strtotime($row['updated_at'])), '<a class="btn btn-primary btn-sm" href="view-research.php?id=' . (int) $row['project_id'] . '">View</a>']; }, $rows);
        echo '<div class="card"><div class="card-header"><div class="card-title">Research Archive</div></div>'; rms_table(['Title', 'Researcher', 'Area', 'Updated', 'Action'], $formatted, 'No completed research is available yet.'); echo '</div>'; return;
    }

    if (strpos($page_key, 'admin-') === 0) {
        if ($page_key === 'admin-users.php') {
            $rows = rms_rows($conn->query('SELECT first_name, last_name, email, role, status, created_at FROM users ORDER BY created_at DESC'));
            $formatted = array_map(function ($row) { return [rms_escape($row['first_name'] . ' ' . $row['last_name']), rms_escape($row['email']), rms_escape(ucfirst($row['role'])), rms_escape(ucfirst($row['status'])), date('M d, Y', strtotime($row['created_at']))]; }, $rows);
            echo '<div class="card"><div class="card-header"><div class="card-title">User Management</div></div>'; rms_table(['Name', 'Email', 'Role', 'Status', 'Joined'], $formatted); echo '</div>'; return;
        }
        if ($page_key === 'admin-logs.php') {
            $rows = rms_rows($conn->query('SELECT al.action, al.module, al.ip_address, al.created_at, CONCAT(u.first_name, " ", u.last_name) AS user_name FROM activity_log al LEFT JOIN users u ON u.user_id = al.user_id ORDER BY al.created_at DESC LIMIT 100'));
            $formatted = array_map(function ($row) { return [rms_escape($row['user_name'] ?? 'System'), rms_escape($row['action']), rms_escape($row['module'] ?? ''), date('M d, Y H:i', strtotime($row['created_at']))]; }, $rows);
            echo '<div class="card"><div class="card-header"><div class="card-title">System Logs</div></div>'; rms_table(['User', 'Action', 'Module', 'Date'], $formatted); echo '</div>'; return;
        }
        $status_filter = $page_key === 'admin-archive.php' ? "WHERE rp.status = 'archived'" : '';
        if ($page_key === 'admin-research.php' || $page_key === 'admin-archive.php') {
            $rows = rms_rows($conn->query("SELECT rp.title, rp.status, CONCAT(u.first_name, ' ', u.last_name) AS owner, rp.created_at FROM research_projects rp JOIN users u ON u.user_id = rp.created_by $status_filter ORDER BY rp.created_at DESC"));
            $formatted = array_map(function ($row) { return [rms_escape($row['title']), rms_escape($row['owner']), rms_escape(rms_status($row['status'])), date('M d, Y', strtotime($row['created_at']))]; }, $rows);
            echo '<div class="card"><div class="card-header"><div class="card-title">' . rms_escape($module[0]) . '</div></div>'; rms_table(['Title', 'Owner', 'Status', 'Created'], $formatted); echo '</div>'; return;
        }
        $stats = ['Users' => $conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'], 'Projects' => $conn->query('SELECT COUNT(*) c FROM research_projects')->fetch_assoc()['c'], 'Completed' => $conn->query("SELECT COUNT(*) c FROM research_projects WHERE status = 'completed'")->fetch_assoc()['c'], 'Archived' => $conn->query("SELECT COUNT(*) c FROM research_projects WHERE status = 'archived'")->fetch_assoc()['c']];
        echo '<div class="stats-grid">'; foreach ($stats as $label => $value) echo '<div class="stat-card blue"><div class="stat-number">' . (int) $value . '</div><div class="stat-label">' . rms_escape($label) . '</div></div>'; echo '</div><div class="card"><div class="card-header"><div class="card-title">' . rms_escape($module[0]) . '</div></div><div class="card-body"><p>System data is connected and ready for this module.</p></div></div>'; return;
    }

    echo '<div class="card"><div class="card-body"><h3>' . rms_escape($module[0]) . '</h3><p>' . rms_escape($module[3]) . '</p></div></div>';
}
