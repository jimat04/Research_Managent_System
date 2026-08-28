<?php
/**
 * Contact Messages Management Page
 * For admin and research_staff to view and manage public contact form submissions
 */

// Only admin and research_staff can access this page
requireLogin();
requireRole(['admin', 'research_staff']);

$page_title = 'Contact Messages';
$page_description = 'Manage public contact form submissions';

// Get current user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

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

// Handle actions (mark as resolved, archive, add notes)
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
                $_SESSION['module_success'] = 'Contact message marked as resolved.';
            }
            $stmt->close();
        } elseif ($action === 'archive' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'archived' WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                $_SESSION['module_success'] = 'Contact message archived.';
            }
            $stmt->close();
        } elseif ($action === 'reopen' && $contact_id > 0) {
            $stmt = $conn->prepare("UPDATE contact_messages SET status = 'pending', resolved_by = NULL, resolved_at = NULL WHERE contact_id = ?");
            $stmt->bind_param('i', $contact_id);
            if ($stmt->execute()) {
                $_SESSION['module_success'] = 'Contact message reopened.';
            }
            $stmt->close();
        }

        header('Location: contact-messages.php?status=' . $status_filter);
        exit;
    }
}

function cm_escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo cm_escape($page_title); ?></h1>
        <p class="page-subtitle"><?php echo cm_escape($page_description); ?></p>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="status-tabs" style="margin-bottom: 24px; display: flex; gap: 8px; border-bottom: 2px solid var(--border); padding-bottom: 0;">
    <?php foreach ($valid_statuses as $status): ?>
        <a href="?status=<?php echo $status; ?>"
           class="status-tab <?php echo $status_filter === $status ? 'active' : ''; ?>"
           style="padding: 12px 20px; text-decoration: none; color: var(--text); border-bottom: 3px solid <?php echo $status_filter === $status ? 'var(--primary)' : 'transparent'; ?>; margin-bottom: -2px; font-weight: <?php echo $status_filter === $status ? '600' : '400'; ?>;">
            <?php echo ucfirst($status); ?> (<?php echo $status_counts[$status]; ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (isset($_SESSION['module_success'])): ?>
    <div class="alert alert-success" role="status" style="margin-bottom: 20px;">
        <?php echo cm_escape($_SESSION['module_success']); unset($_SESSION['module_success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['module_error'])): ?>
    <div class="alert alert-error" role="alert" style="margin-bottom: 20px;">
        <?php echo cm_escape($_SESSION['module_error']); unset($_SESSION['module_error']); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><?php echo ucfirst($status_filter); ?> Contact Messages</div>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($messages)): ?>
            <div style="padding: 60px 24px; text-align: center; color: var(--text-light);">
                <div style="font-size: 3rem; margin-bottom: 12px;">📭</div>
                <p style="margin: 0;">No <?php echo $status_filter; ?> contact messages</p>
            </div>
        <?php else: ?>
            <div style="padding: 0;">
                <?php foreach ($messages as $msg): ?>
                    <div style="padding: 20px 24px; border-bottom: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                                    <strong style="font-size: 1.1rem;"><?php echo cm_escape($msg['name']); ?></strong>
                                    <span style="color: var(--text-light); font-size: 0.9rem;"><?php echo cm_escape($msg['email']); ?></span>
                                    <span class="badge" style="background: var(--primary); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.8rem;">
                                        <?php echo cm_escape($msg['concern_type']); ?>
                                    </span>
                                </div>
                                <div style="color: var(--text-light); font-size: 0.85rem; margin-bottom: 12px;">
                                    Received: <?php echo date('F j, Y \a\t g:i A', strtotime($msg['created_at'])); ?>
                                </div>
                                <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                                    <?php echo nl2br(cm_escape($msg['message'])); ?>
                                </div>

                                <?php if ($msg['status'] !== 'pending' && $msg['notes']): ?>
                                    <div style="background: #e3f2fd; padding: 12px; border-radius: 6px; border-left: 3px solid var(--primary);">
                                        <strong style="font-size: 0.9rem;">Staff Notes:</strong>
                                        <div style="margin-top: 6px;"><?php echo nl2br(cm_escape($msg['notes'])); ?></div>
                                        <?php if ($msg['resolved_by_name']): ?>
                                            <div style="margin-top: 8px; font-size: 0.85rem; color: var(--text-light);">
                                                Resolved by <?php echo cm_escape($msg['resolved_by_name']); ?>
                                                on <?php echo date('M j, Y', strtotime($msg['resolved_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <?php if ($msg['status'] === 'pending'): ?>
                                <button class="btn btn-sm btn-primary" onclick="openResolveModal(<?php echo $msg['contact_id']; ?>)">
                                    Mark as Resolved
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="archiveMessage(<?php echo $msg['contact_id']; ?>)">
                                    Archive
                                </button>
                            <?php elseif ($msg['status'] === 'resolved'): ?>
                                <button class="btn btn-sm btn-secondary" onclick="reopenMessage(<?php echo $msg['contact_id']; ?>)">
                                    Reopen
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="archiveMessage(<?php echo $msg['contact_id']; ?>)">
                                    Archive
                                </button>
                            <?php elseif ($msg['status'] === 'archived'): ?>
                                <button class="btn btn-sm btn-secondary" onclick="reopenMessage(<?php echo $msg['contact_id']; ?>)">
                                    Reopen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div style="padding: 20px; border-top: 1px solid var(--border); display: flex; justify-content: center; gap: 8px;">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary">Previous</a>
                    <?php endif; ?>

                    <span style="padding: 8px 16px; color: var(--text-light);">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </span>

                    <?php if ($page < $total_pages): ?>
                        <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="contact_id" id="resolve_contact_id">

            <div class="modal-header">
                <h3 style="margin: 0; font-size: 1.25rem;">Resolve Contact Message</h3>
                <button type="button" class="modal-close" onclick="closeResolveModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Resolution Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="4" placeholder="Add any notes about how this was resolved..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeResolveModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Mark as Resolved</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal-content {
    background: var(--card-bg);
    border-radius: 12px;
    width: 90%;
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
    line-height: 1;
    cursor: pointer;
    color: var(--text-light);
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

.modal-close:hover {
    background: rgba(0, 0, 0, 0.05);
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>

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

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<?php echo csrfField(); ?>' +
        '<input type="hidden" name="action" value="archive">' +
        '<input type="hidden" name="contact_id" value="' + contactId + '">';
    document.body.appendChild(form);
    form.submit();
}

function reopenMessage(contactId) {
    if (!confirm('Reopen this contact message?')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<?php echo csrfField(); ?>' +
        '<input type="hidden" name="action" value="reopen">' +
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
</script>
