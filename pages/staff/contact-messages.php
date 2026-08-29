<?php
/**
 * Contact Messages Management Page
 * For admin and research_staff to view and manage public contact form submissions
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Only admin and research_staff can access this page
requireLogin();
requireRole(['admin', 'research_staff']);

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Helper function
function cm_escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Stat counts for navigation badges
$stat_contact = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM contact_messages WHERE status = 'pending'"
)->fetch_assoc()['count'] ?? 0);

// Filter by status
$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'resolved', 'archived'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'pending';
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

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
    WHERE cm.status = ?
    ORDER BY cm.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('sii', $status_filter, $per_page, $offset);
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
$total_messages = $status_counts[$status_filter];
$total_pages = ceil($total_messages / $per_page);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
    } else {
        $action = $_POST['action'];
        $contact_id = intval($_POST['contact_id'] ?? 0);

        if ($action === 'resolve' && $contact_id > 0) {
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), notes = ? WHERE contact_id = ?");
            $stmt->bind_param('isi', $user_id, $notes, $contact_id);
            if ($stmt->execute()) {
                logActivity("Resolved contact message ID: {$contact_id}", 'contact_management');
                $_SESSION['module_success'] = 'Contact message marked as resolved.';
            }
            $stmt->close();
        } elseif ($action === 'archive' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'archived' WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                logActivity("Archived contact message ID: {$contact_id}", 'contact_management');
                $_SESSION['module_success'] = 'Contact message archived.';
            }
            $stmt->close();
        } elseif ($action === 'reopen' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'pending', resolved_by = NULL, resolved_at = NULL WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                logActivity("Reopened contact message ID: {$contact_id}", 'contact_management');
                $_SESSION['module_success'] = 'Contact message reopened.';
            }
            $stmt->close();
        }

        header('Location: contact-messages.php?status=' . $status_filter);
        exit;
    }
}

$full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$first_name = $user['first_name'] ?? 'Staff';
$initials = strtoupper(substr($first_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages — RMS</title>
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
            --text-light: #64748B;
            --primary: #C8A44D;
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

        .nav-group-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.5);
            padding: 16px 16px 8px;
        }

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
            cursor: pointer;
        }

        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: var(--gold); color: white; font-weight: 600; }
        .nav-item .badge {
            margin-left: auto;
            background: var(--gold);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .sidebar-footer {
            padding: 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }

        .user-name { font-weight: 600; font-size: 14px; }
        .user-role { font-size: 12px; color: rgba(255,255,255,0.6); }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 32px 48px;
            max-width: 1600px;
        }

        .page-header { margin-bottom: 32px; }
        .page-title { font-size: 32px; font-weight: 700; margin-bottom: 8px; }
        .page-subtitle { color: var(--text-secondary); font-size: 16px; }

        .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; }
        .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
        .alert-error { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }

        .card {
            background: var(--bg-card);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
        }

        .card-title { font-size: 18px; font-weight: 600; }

        .status-tabs {
            margin-bottom: 24px;
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 0;
            flex-wrap: wrap;
        }

        .status-tab {
            padding: 12px 20px;
            text-decoration: none;
            color: var(--text-primary);
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            font-weight: 400;
            transition: all 0.2s;
        }

        .status-tab.active {
            border-bottom-color: var(--primary);
            font-weight: 600;
            color: var(--primary);
        }

        .status-tab:hover { background: rgba(0,0,0,0.02); }

        .message-card {
            padding: 24px;
            border-bottom: 1px solid var(--border);
        }

        .message-card:last-child { border-bottom: none; }

        .badge {
            background: var(--primary);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            white-space: nowrap;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: var(--text-light);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .modal-close:hover { background: rgba(0, 0, 0, 0.05); }

        .modal-body { padding: 24px; overflow-y: auto; }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 24px; }
        }
    </style>
</head>
<body>

<div class="dashboard">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">EARIST RMS</div>
            <div class="sidebar-role">Research Staff</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-group-title">Overview</div>
            <div class="nav-item" onclick="location.href='staff-dashboard.php'">
                <span>📊</span>
                <span>Dashboard</span>
            </div>

            <div class="nav-group-title">Communication</div>
            <div class="nav-item active">
                <span>📨</span>
                <span>Contact Messages</span>
                <?php if ($stat_contact > 0): ?>
                    <span class="badge"><?php echo cm_escape($stat_contact); ?></span>
                <?php endif; ?>
            </div>
            <div class="nav-item" onclick="location.href='../shared/messages.php'">
                <span>💬</span>
                <span>Messages</span>
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
                <div class="user-avatar"><?php echo $initials; ?></div>
                <div>
                    <div class="user-name"><?php echo cm_escape($full_name); ?></div>
                    <div class="user-role">Research Staff</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">📨 Contact Messages</h1>
            <p class="page-subtitle">Manage inquiries from the public contact form</p>
        </div>

        <?php if (isset($_SESSION['module_success'])): ?>
            <div class="alert alert-success">
                ✓ <?php echo cm_escape($_SESSION['module_success']); unset($_SESSION['module_success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['module_error'])): ?>
            <div class="alert alert-error">
                ✕ <?php echo cm_escape($_SESSION['module_error']); unset($_SESSION['module_error']); ?>
            </div>
        <?php endif; ?>

        <!-- Status Filter Tabs -->
        <div class="status-tabs">
            <?php foreach ($valid_statuses as $status): ?>
                <a href="?status=<?php echo $status; ?>" class="status-tab <?php echo $status_filter === $status ? 'active' : ''; ?>">
                    <?php echo ucfirst($status); ?> (<?php echo $status_counts[$status]; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Messages List -->
        <div class="card">
            <?php if (empty($messages)): ?>
                <div style="padding: 80px 24px; text-align: center; color: var(--text-light);">
                    <div style="font-size: 4rem; margin-bottom: 16px; opacity: 0.5;">📭</div>
                    <p style="font-size: 1.1rem; margin-bottom: 8px;">No <?php echo $status_filter; ?> messages</p>
                    <p style="font-size: 0.9rem;">Messages from the public contact form will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message-card">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
                            <strong style="font-size: 1.1rem;"><?php echo cm_escape($msg['name']); ?></strong>
                            <span style="color: var(--text-light); font-size: 0.9rem;"><?php echo cm_escape($msg['email']); ?></span>
                            <span class="badge"><?php echo cm_escape($msg['concern_type']); ?></span>
                        </div>

                        <div style="color: var(--text-light); font-size: 0.85rem; margin-bottom: 12px;">
                            📅 Received: <?php echo date('F j, Y \a\t g:i A', strtotime($msg['created_at'])); ?>
                        </div>

                        <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 12px; line-height: 1.6;">
                            <?php echo nl2br(cm_escape($msg['message'])); ?>
                        </div>

                        <?php if ($msg['status'] !== 'pending' && $msg['notes']): ?>
                            <div style="background: #e3f2fd; padding: 16px; border-radius: 8px; border-left: 4px solid var(--primary); margin-bottom: 12px;">
                                <strong style="font-size: 0.9rem;">📝 Staff Notes:</strong>
                                <div style="margin-top: 8px;"><?php echo nl2br(cm_escape($msg['notes'])); ?></div>
                                <?php if ($msg['resolved_by_name']): ?>
                                    <div style="margin-top: 12px; font-size: 0.85rem; color: var(--text-light);">
                                        Handled by <?php echo cm_escape($msg['resolved_by_name']); ?>
                                        on <?php echo date('M j, Y', strtotime($msg['resolved_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($msg['status'] === 'pending'): ?>
                                <button class="btn btn-primary btn-sm" onclick="openResolveModal(<?php echo $msg['contact_id']; ?>)">
                                    ✓ Mark as Resolved
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
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary">← Previous</a>
                        <?php endif; ?>

                        <span style="padding: 8px 16px; color: var(--text-light);">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="modal">
    <div class="modal-content">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="contact_id" id="resolve_contact_id">

            <div class="modal-header">
                <h3 style="margin: 0; font-size: 1.25rem;">✓ Resolve Contact Message</h3>
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
function openResolveModal(contactId) {
    document.getElementById('resolve_contact_id').value = contactId;
    document.getElementById('resolveModal').style.display = 'flex';
}

function closeResolveModal() {
    document.getElementById('resolveModal').style.display = 'none';
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

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeResolveModal();
    }
});

// Close modal on backdrop click
document.getElementById('resolveModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeResolveModal();
    }
});
</script>

</body>
</html>
