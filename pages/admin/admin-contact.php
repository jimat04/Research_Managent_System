<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email.php';

// Only admin and research_staff can access this page
requireLogin();
requireRole(['admin', 'research_staff']);

$user = [
    'user_id' => $_SESSION['user_id'],
    'role' => $_SESSION['role'],
    'name' => $_SESSION['name']
];

$error = '';
$success = '';

// Filter by status
$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'resolved', 'archived'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'pending';
}

// Search filter
$search = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = ["cm.status = ?"];
$params = [$status_filter];
$param_types = 's';

if (!empty($search)) {
    $where_conditions[] = "(cm.name LIKE ? OR cm.email LIKE ? OR cm.message LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= 'sss';
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch contact messages
$stmt = $conn->prepare("
    SELECT
        cm.contact_id,
        cm.name,
        cm.email,
        cm.concern_type,
        cm.message,
        cm.status,
        cm.created_at,
        cm.resolved_by,
        cm.resolved_at,
        cm.notes,
        CONCAT(u.first_name, ' ', u.last_name) as resolved_by_name
    FROM contact_messages cm
    LEFT JOIN users u ON cm.resolved_by = u.user_id
    WHERE {$where_clause}
    ORDER BY cm.created_at DESC
    LIMIT ? OFFSET ?
");

$params[] = $per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}
$stmt->close();

// Get counts for each status
$status_counts = [];
foreach ($valid_statuses as $status) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM contact_messages WHERE status = ?");
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $status_counts[$status] = $count_result->fetch_assoc()['count'];
    $stmt->close();
}

// Get total count for pagination
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($param_types, 0, -2);
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM contact_messages cm WHERE {$where_clause}");
$stmt->bind_param($count_types, ...$count_params);
$stmt->execute();
$total_messages = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

$total_pages = ceil($total_messages / $per_page);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Please try again.';
    } else {
        $action = $_POST['action'];
        $contact_id = intval($_POST['contact_id'] ?? 0);

        if ($action === 'reply' && $contact_id > 0) {
            // Send email reply
            $reply_message = trim($_POST['reply_message'] ?? '');
            $mark_resolved = isset($_POST['mark_resolved']);

            if (empty($reply_message)) {
                $error = 'Reply message cannot be empty.';
            } else {
                // Get contact message details
                $stmt = $conn->prepare("SELECT name, email, concern_type, message FROM contact_messages WHERE contact_id = ?");
                $stmt->bind_param('i', $contact_id);
                $stmt->execute();
                $contact = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($contact) {
                    // Send reply email
                    $email_body = getEmailTemplate('contact_reply', [
                        'userName' => htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8'),
                        'concernType' => htmlspecialchars($contact['concern_type'], ENT_QUOTES, 'UTF-8'),
                        'originalMessage' => htmlspecialchars($contact['message'], ENT_QUOTES, 'UTF-8'),
                        'replyMessage' => nl2br(htmlspecialchars($reply_message, ENT_QUOTES, 'UTF-8')),
                        'staffName' => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8')
                    ]);

                    $subject = "Re: " . $contact['concern_type'] . " - RMS Support";

                    if (sendEmail($contact['email'], $subject, $email_body, $contact['name'])) {
                        // Update message with reply notes
                        if ($mark_resolved) {
                            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), notes = ? WHERE contact_id = ?");
                            $stmt->bind_param('isi', $user['user_id'], $reply_message, $contact_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE contact_messages SET notes = ? WHERE contact_id = ?");
                            $stmt->bind_param('si', $reply_message, $contact_id);
                        }
                        $stmt->execute();
                        $stmt->close();

                        logActivity("Replied to contact message from {$contact['name']}", 'contact_management');
                        $success = 'Email reply sent successfully!';
                    } else {
                        $error = 'Failed to send email reply. Please try again.';
                    }
                } else {
                    $error = 'Contact message not found.';
                }
            }
        } elseif ($action === 'resolve' && $contact_id > 0) {
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), notes = ? WHERE contact_id = ?");
            $stmt->bind_param('isi', $user['user_id'], $notes, $contact_id);
            if ($stmt->execute()) {
                logActivity("Resolved contact message ID: {$contact_id}", 'contact_management');
                $success = 'Contact message marked as resolved.';
            }
            $stmt->close();
        } elseif ($action === 'archive' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'archived' WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                logActivity("Archived contact message ID: {$contact_id}", 'contact_management');
                $success = 'Contact message archived.';
            }
            $stmt->close();
        } elseif ($action === 'reopen' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'pending', resolved_by = NULL, resolved_at = NULL WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                logActivity("Reopened contact message ID: {$contact_id}", 'contact_management');
                $success = 'Contact message reopened.';
            }
            $stmt->close();
        }

        if (!$error) {
            header('Location: admin-contact.php?status=' . $status_filter . ($search ? '&search=' . urlencode($search) : ''));
            exit;
        }
    }
}

// Add email template for contact replies
function getContactReplyTemplate($userName, $concernType, $originalMessage, $replyMessage, $staffName) {
    $siteName = $_ENV['SITE_NAME'] ?? 'RMS Research System';
    $siteUrl = $_ENV['SITE_URL'] ?? 'http://localhost/rms/';
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8fafc; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #111827 0%, #1F2937 100%); padding: 32px 24px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 24px; font-weight: 600; }
        .header .icon { font-size: 48px; margin-bottom: 12px; }
        .content { padding: 32px 24px; color: #1f2937; line-height: 1.6; }
        .content h2 { color: #111827; margin: 0 0 16px 0; font-size: 20px; }
        .content p { margin: 0 0 16px 0; }
        .info-box { background: #f8fafc; border-left: 4px solid #C8A44D; padding: 16px; margin: 16px 0; border-radius: 8px; }
        .reply-box { background: #e3f2fd; border-left: 4px solid #3B82F6; padding: 16px; margin: 16px 0; border-radius: 8px; }
        .footer { padding: 24px; text-align: center; color: #64748b; font-size: 14px; background: #f8fafc; }
        .footer a { color: #3B82F6; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">💬</div>
            <h1>Response to Your Inquiry</h1>
        </div>
        <div class="content">
            <h2>Hello {$userName}!</h2>
            <p>Thank you for contacting {$siteName}. We've reviewed your inquiry and are responding below.</p>

            <div class="info-box">
                <strong>Your Inquiry ({$concernType}):</strong>
                <p style="margin: 8px 0 0 0; color: #64748b;">{$originalMessage}</p>
            </div>

            <div class="reply-box">
                <strong style="color: #1F2937;">Our Response:</strong>
                <p style="margin: 8px 0 0 0;">{$replyMessage}</p>
                <p style="margin: 16px 0 0 0; font-size: 14px; color: #64748b;">— {$staffName}</p>
            </div>

            <p style="font-size: 14px; color: #64748b;">If you have additional questions, please feel free to reply to this email or submit another inquiry through our website.</p>
        </div>
        <div class="footer">
            <p>&copy; {$year} {$siteName}. All rights reserved.</p>
            <p><a href="{$siteUrl}public/contact.php">Contact Us</a></p>
        </div>
    </div>
</body>
</html>
HTML;
}

function cm_escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages — Admin — RMS</title>
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --charcoal: #111827;
            --slate: #1F2937;
            --bg-surface: #F8FAFC;
            --bg-card: #FFFFFF;
            --border: #E5E7EB;
            --gold: #C8A44D;
            --text-primary: #111827;
            --text-secondary: #64748B;
            --text-muted: #94A3B8;
            --primary: #C8A44D;
            --card-bg: #FFFFFF;
            --text: #111827;
            --text-light: #64748B;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-surface);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .dashboard { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: 260px;
            background: var(--charcoal);
            color: white;
            padding: 32px 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 24px 32px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 24px;
        }

        .sidebar-brand {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
        }

        .sidebar-role {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .sidebar-nav { flex: 1; padding: 0 12px; }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 4px;
            transition: all 0.2s;
            font-size: 14px;
        }

        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: var(--gold); color: white; font-weight: 600; }
        .nav-icon { font-size: 18px; }

        .nav-group-title {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin: 24px 8px 8px;
            text-transform: uppercase;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            margin: 2px 0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            color: rgba(255,255,255,0.7);
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }

        .nav-item.active {
            background: var(--gold);
            color: white;
        }

        .sidebar-footer {
            padding: 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .user-card {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .user-role {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 32px 48px;
            max-width: 1600px;
        }

        .page-header {
            margin-bottom: 48px;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--charcoal);
        }

        .page-header p {
            font-size: 16px;
            color: var(--text-secondary);
        }

        .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
        .alert-error { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }

        .card {
            background: var(--bg-card);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group { margin-bottom: 20px; }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--gold); color: white; }
        .btn-primary:hover { background: #B39340; }
        .btn-secondary { background: var(--border); color: var(--text-primary); }
        .btn-secondary:hover { background: #D1D5DB; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }

        .search-bar { margin-bottom: 24px; display: flex; gap: 12px; }
        .search-bar input { flex: 1; max-width: 400px; }
        .status-tabs { margin-bottom: 24px; display: flex; gap: 8px; border-bottom: 2px solid var(--border); padding-bottom: 0; flex-wrap: wrap; }
        .status-tab { padding: 12px 20px; text-decoration: none; color: var(--text); border-bottom: 3px solid transparent; margin-bottom: -2px; font-weight: 400; transition: all 0.2s; }
        .status-tab.active { border-bottom-color: var(--primary); font-weight: 600; color: var(--primary); }
        .status-tab:hover { background: rgba(0,0,0,0.02); }
        .message-card { padding: 24px; border-bottom: 1px solid var(--border); transition: background 0.2s; }
        .message-card:hover { background: rgba(0,0,0,0.01); }
        .message-card:last-child { border-bottom: none; }
        .message-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; gap: 16px; }
        .message-meta { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .concern-badge { background: var(--primary); color: white; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; white-space: nowrap; }
        .message-body { background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 16px; line-height: 1.6; }
        .notes-box { background: #e3f2fd; padding: 16px; border-radius: 8px; border-left: 3px solid var(--primary); margin-bottom: 16px; }
        .message-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--card-bg); border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { padding: 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-close { background: none; border: none; font-size: 2rem; cursor: pointer; color: var(--text-light); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
        .modal-close:hover { background: rgba(0, 0, 0, 0.05); }
        .modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }
        .empty-state { padding: 80px 24px; text-align: center; color: var(--text-light); }
        .empty-state-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.5; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 24px; }
            .message-meta { font-size: 0.85rem; }
            .message-actions { flex-direction: column; }
            .message-actions button { width: 100%; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">🔬 RMS</div>
            <div class="sidebar-role">Administrator</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-group-title">Overview</div>
            <div class="nav-item" onclick="location.href='admin-dashboard.php'">
                <span>📊</span>
                <span>Dashboard</span>
            </div>

            <div class="nav-group-title">Management</div>
            <div class="nav-item" onclick="location.href='admin-users.php'">
                <span>👥</span>
                <span>User Management</span>
            </div>
            <div class="nav-item" onclick="location.href='admin-research.php'">
                <span>📁</span>
                <span>Research Management</span>
            </div>
            <div class="nav-item" onclick="location.href='admin-archive.php'">
                <span>🗂️</span>
                <span>Archive</span>
            </div>

            <div class="nav-group-title">Analytics</div>
            <div class="nav-item" onclick="location.href='admin-reports.php'">
                <span>📈</span>
                <span>Reports & Analytics</span>
            </div>

            <div class="nav-group-title">Communication</div>
            <div class="nav-item" onclick="location.href='../shared/messages.php'">
                <span>💬</span>
                <span>Messages</span>
            </div>
            <div class="nav-item active" onclick="location.href='admin-contact.php'">
                <span>📨</span>
                <span>Contact Messages</span>
            </div>

            <div class="nav-group-title">System</div>
            <div class="nav-item" onclick="location.href='admin-logs.php'">
                <span>⚙️</span>
                <span>System Logs</span>
            </div>
            <div class="nav-item" onclick="location.href='admin-backup.php'">
                <span>💾</span>
                <span>Backup</span>
            </div>
            <div class="nav-item" onclick="location.href='../shared/notifications.php'">
                <span>🔔</span>
                <span>Notifications</span>
            </div>

            <div class="nav-group-title">Account</div>
            <div class="nav-item" onclick="location.href='../shared/profile.php'">
                <span>👤</span>
                <span>Profile</span>
            </div>
            <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #EF4444;">
                <span>🚪</span>
                <span>Logout</span>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?></div>
                <div>
                    <div class="user-name"><?php echo cm_escape($user['name'] ?? 'Admin'); ?></div>
                    <div class="user-role">Administrator</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

    <div class="page-container">
        <div class="page-header">
            <h1>Contact Messages</h1>
            <p>Manage inquiries from the public contact form.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;">
                <span style="color: #dc2626;">✕</span> <?php echo cm_escape($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <span style="color: #15803d;">✓</span> <?php echo cm_escape($success); ?>
            </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <form method="GET" class="search-bar">
            <input type="hidden" name="status" value="<?php echo cm_escape($status_filter); ?>">
            <input type="text" name="search" class="form-control" placeholder="Search by name, email, or message..." value="<?php echo cm_escape($search); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search): ?>
                <a href="?status=<?php echo cm_escape($status_filter); ?>" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Status Filter Tabs -->
        <div class="status-tabs">
            <?php foreach ($valid_statuses as $status): ?>
                <a href="?status=<?php echo $status; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                   class="status-tab <?php echo $status_filter === $status ? 'active' : ''; ?>">
                    <?php echo ucfirst($status); ?> (<?php echo $status_counts[$status]; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Messages List -->
        <div class="card">
            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p style="font-size: 1.1rem; margin-bottom: 8px;">No <?php echo $status_filter; ?> messages</p>
                    <p style="font-size: 0.9rem;">
                        <?php if ($search): ?>
                            Try adjusting your search terms or <a href="?status=<?php echo cm_escape($status_filter); ?>">clear filters</a>.
                        <?php else: ?>
                            Messages from the public contact form will appear here.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message-card">
                        <div class="message-header">
                            <div style="flex: 1;">
                                <div class="message-meta">
                                    <strong style="font-size: 1.1rem;"><?php echo cm_escape($msg['name']); ?></strong>
                                    <span style="color: var(--text-light);"><?php echo cm_escape($msg['email']); ?></span>
                                    <span class="concern-badge"><?php echo cm_escape($msg['concern_type']); ?></span>
                                </div>
                                <div style="color: var(--text-light); font-size: 0.85rem;">
                                    📅 Received: <?php echo date('F j, Y \a\t g:i A', strtotime($msg['created_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <div class="message-body">
                            <?php echo nl2br(cm_escape($msg['message'])); ?>
                        </div>

                        <?php if ($msg['status'] !== 'pending' && $msg['notes']): ?>
                            <div class="notes-box">
                                <strong style="font-size: 0.9rem;">📝 Staff Notes / Reply:</strong>
                                <div style="margin-top: 8px;"><?php echo nl2br(cm_escape($msg['notes'])); ?></div>
                                <?php if ($msg['resolved_by_name']): ?>
                                    <div style="margin-top: 12px; font-size: 0.85rem; color: var(--text-light);">
                                        Handled by <?php echo cm_escape($msg['resolved_by_name']); ?>
                                        on <?php echo date('M j, Y', strtotime($msg['resolved_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="message-actions">
                            <?php if ($msg['status'] === 'pending'): ?>
                                <button class="btn btn-primary btn-sm" onclick="openReplyModal(<?php echo $msg['contact_id']; ?>, '<?php echo cm_escape($msg['name']); ?>', '<?php echo cm_escape($msg['email']); ?>')">
                                    ✉️ Reply via Email
                                </button>
                                <button class="btn btn-sm" style="background: #16A34A; color: white;" onclick="openResolveModal(<?php echo $msg['contact_id']; ?>)">
                                    ✓ Mark Resolved
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="archiveMessage(<?php echo $msg['contact_id']; ?>)">
                                    🗄️ Archive
                                </button>
                            <?php elseif ($msg['status'] === 'resolved'): ?>
                                <button class="btn btn-secondary btn-sm" onclick="reopenMessage(<?php echo $msg['contact_id']; ?>)">
                                    🔄 Reopen
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="archiveMessage(<?php echo $msg['contact_id']; ?>)">
                                    🗄️ Archive
                                </button>
                            <?php elseif ($msg['status'] === 'archived'): ?>
                                <button class="btn btn-secondary btn-sm" onclick="reopenMessage(<?php echo $msg['contact_id']; ?>)">
                                    🔄 Reopen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div style="padding: 20px; border-top: 1px solid var(--border); display: flex; justify-content: center; gap: 12px; align-items: center;">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm btn-secondary">← Previous</a>
                        <?php endif; ?>

                        <span style="padding: 8px 16px; color: var(--text-light);">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Reply Modal -->
<div id="replyModal" class="modal">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="contact_id" id="reply_contact_id">

                <div class="modal-header">
                    <h3 style="margin: 0; font-size: 1.25rem;">✉️ Reply via Email</h3>
                    <button type="button" class="modal-close" onclick="closeReplyModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                        <strong style="font-size: 0.9rem; color: var(--text-light);">Replying to:</strong>
                        <div style="margin-top: 4px;">
                            <strong id="reply_recipient_name"></strong> (<span id="reply_recipient_email" style="color: var(--text-light);"></span>)
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your Reply Message</label>
                        <textarea name="reply_message" class="form-control" rows="8" required placeholder="Type your response here..."></textarea>
                        <div style="font-size: 0.85rem; color: var(--text-light); margin-top: 8px;">
                            This message will be sent via email and saved as internal notes.
                        </div>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" name="mark_resolved" id="mark_resolved" checked>
                        <label for="mark_resolved">Mark as resolved after sending</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeReplyModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Email Reply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Resolve Modal -->
    <div id="resolveModal" class="modal">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="contact_id" id="resolve_contact_id">

                <div class="modal-header">
                    <h3 style="margin: 0; font-size: 1.25rem;">✓ Mark as Resolved</h3>
                    <button type="button" class="modal-close" onclick="closeResolveModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Resolution Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="4" placeholder="Add internal notes about how this was resolved..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResolveModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Mark as Resolved</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openReplyModal(contactId, name, email) {
        document.getElementById('reply_contact_id').value = contactId;
        document.getElementById('reply_recipient_name').textContent = name;
        document.getElementById('reply_recipient_email').textContent = email;
        document.getElementById('replyModal').classList.add('active');
    }

    function closeReplyModal() {
        document.getElementById('replyModal').classList.remove('active');
    }

    function openResolveModal(contactId) {
        document.getElementById('resolve_contact_id').value = contactId;
        document.getElementById('resolveModal').classList.add('active');
    }

    function closeResolveModal() {
        document.getElementById('resolveModal').classList.remove('active');
    }

    function archiveMessage(contactId) {
        if (!confirm('Archive this contact message?')) return;
        submitAction('archive', contactId);
    }

    function reopenMessage(contactId) {
        if (!confirm('Reopen this contact message?')) return;
        submitAction('reopen', contactId);
    }

    function submitAction(action, contactId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<?php echo csrfField(); ?>' +
            '<input type="hidden" name="action" value="' + action + '">' +
            '<input type="hidden" name="contact_id" value="' + contactId + '">';
        document.body.appendChild(form);
        form.submit();
    }

    // Close modals on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReplyModal();
            closeResolveModal();
        }
    });

    // Close modals on backdrop click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeReplyModal();
                closeResolveModal();
            }
        });
    });
    </script>
</body>
</html>
