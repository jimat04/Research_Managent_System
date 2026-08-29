<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

// Only admin and research_staff can access this page
requireLogin();
requireRole(['admin', 'research_staff']);

$user = getCurrentUser();

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
                        'staffName' => htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8')
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

function cm_escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }

  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }

  .form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border, #E5E7EB);
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

  .btn-primary { background: var(--gold, #C8A44D); color: white; }
  .btn-primary:hover { background: #B39340; }
  .btn-secondary { background: var(--border, #E5E7EB); color: var(--text-primary, #111827); }
  .btn-secondary:hover { background: #D1D5DB; }
  .btn-sm { padding: 8px 16px; font-size: 13px; }

  .search-bar { margin-bottom: 24px; display: flex; gap: 12px; }
  .search-bar input { flex: 1; max-width: 400px; }
  .status-tabs { margin-bottom: 24px; display: flex; gap: 8px; border-bottom: 2px solid var(--border, #E5E7EB); padding-bottom: 0; flex-wrap: wrap; }
  .status-tab { padding: 12px 20px; text-decoration: none; color: var(--text-primary, #111827); border-bottom: 3px solid transparent; margin-bottom: -2px; font-weight: 400; transition: all 0.2s; }
  .status-tab.active { border-bottom-color: var(--primary, #C8A44D); font-weight: 600; color: var(--primary, #C8A44D); }
  .status-tab:hover { background: rgba(0,0,0,0.02); }
  .message-card { padding: 24px; border-bottom: 1px solid var(--border, #E5E7EB); transition: background 0.2s; }
  .message-card:hover { background: rgba(0,0,0,0.01); }
  .message-card:last-child { border-bottom: none; }
  .message-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; gap: 16px; }
  .message-meta { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
  .concern-badge { background: var(--gold, #C8A44D); color: white; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; white-space: nowrap; }
  .message-body { background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 16px; line-height: 1.6; }
  .notes-box { background: #e3f2fd; padding: 16px; border-radius: 8px; border-left: 3px solid var(--gold, #C8A44D); margin-bottom: 16px; }
  .message-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center; }
  .modal.active { display: flex; }
  .modal-content { background: #fff; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
  .modal-header { padding: 24px; border-bottom: 1px solid var(--border, #E5E7EB); display: flex; justify-content: space-between; align-items: center; }
  .modal-close { background: none; border: none; font-size: 2rem; cursor: pointer; color: var(--text-secondary, #64748B); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
  .modal-close:hover { background: rgba(0, 0, 0, 0.05); }
  .modal-body { padding: 24px; overflow-y: auto; flex: 1; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border, #E5E7EB); display: flex; justify-content: flex-end; gap: 8px; }
  .empty-state { padding: 80px 24px; text-align: center; color: var(--text-secondary, #64748B); }
  .empty-state-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.5; }

  @media (max-width: 768px) {
    .message-meta { font-size: 0.85rem; }
    .message-actions { flex-direction: column; }
    .message-actions button { width: 100%; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-contact',
    'Contact Messages',
    'Manage inquiries from the public contact form.'
);
?>

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
                                    <span style="color: var(--text-secondary, #64748B);"><?php echo cm_escape($msg['email']); ?></span>
                                    <span class="concern-badge"><?php echo cm_escape($msg['concern_type']); ?></span>
                                </div>
                                <div style="color: var(--text-secondary, #64748B); font-size: 0.85rem;">
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
                                    <div style="margin-top: 12px; font-size: 0.85rem; color: var(--text-secondary, #64748B);">
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
                    <div style="padding: 20px; border-top: 1px solid var(--border, #E5E7EB); display: flex; justify-content: center; gap: 12px; align-items: center;">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm btn-secondary">← Previous</a>
                        <?php endif; ?>

                        <span style="padding: 8px 16px; color: var(--text-secondary, #64748B);">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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
                        <strong style="font-size: 0.9rem; color: var(--text-secondary, #64748B);">Replying to:</strong>
                        <div style="margin-top: 4px;">
                            <strong id="reply_recipient_name"></strong> (<span id="reply_recipient_email" style="color: var(--text-secondary, #64748B);"></span>)
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your Reply Message</label>
                        <textarea name="reply_message" class="form-control" rows="8" required placeholder="Type your response here..."></textarea>
                        <div style="font-size: 0.85rem; color: var(--text-secondary, #64748B); margin-top: 8px;">
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

<?php
renderAdminShellClose();
