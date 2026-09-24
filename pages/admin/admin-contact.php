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
$warning = '';
$flash = getMessage();
if (is_array($flash)) {
    $flash_type = (string) ($flash['type'] ?? 'info');
    $flash_message = (string) ($flash['message'] ?? '');
    if ($flash_type === 'success') {
        $success = $flash_message;
    } elseif ($flash_type === 'warning') {
        $warning = $flash_message;
    } elseif ($flash_type === 'error') {
        $error = $flash_message;
    }
}
$smtp_configured = rms_smtp_is_configured();

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
$visible_messages = count($messages);
$all_messages = array_sum(array_map('intval', $status_counts));
$current_status_label = ucfirst($status_filter);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Please try again.';
    } else {
        $action = $_POST['action'];
        $contact_id = intval($_POST['contact_id'] ?? 0);

        if ($action === 'reply' && $contact_id > 0) {
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
                    // Persist the reply first so delivery failures never lose staff work.
                    if ($mark_resolved) {
                        $stmt = $conn->prepare("UPDATE contact_messages SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), notes = ? WHERE contact_id = ?");
                        $stmt->bind_param('isi', $user['user_id'], $reply_message, $contact_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE contact_messages SET notes = ? WHERE contact_id = ?");
                        $stmt->bind_param('si', $reply_message, $contact_id);
                    }
                    $reply_saved = $stmt->execute();
                    $stmt->close();

                    if (!$reply_saved) {
                        $error = 'The reply could not be saved. Please try again.';
                    } else {
                        logActivity("Replied to contact message from {$contact['name']}", 'contact_management');

                        // Delivery is best-effort and happens only after the record is safe.
                        $email_body = getEmailTemplate('contact_reply', [
                            'userName' => htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8'),
                            'concernType' => htmlspecialchars($contact['concern_type'], ENT_QUOTES, 'UTF-8'),
                            'originalMessage' => htmlspecialchars($contact['message'], ENT_QUOTES, 'UTF-8'),
                            'replyMessage' => nl2br(htmlspecialchars($reply_message, ENT_QUOTES, 'UTF-8')),
                            'staffName' => htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8')
                        ]);
                        $subject = "Re: " . $contact['concern_type'] . " - RMS Support";
                        $email_sent = sendEmail($contact['email'], $subject, $email_body, $contact['name']);

                        $redirect_url = 'admin-contact.php?status=' . $status_filter
                            . ($search ? '&search=' . urlencode($search) : '');
                        if ($email_sent) {
                            redirectWithMessage($redirect_url, 'Reply sent and saved.', 'success');
                        }
                        redirectWithMessage(
                            $redirect_url,
                            'Reply saved to the message record, but the email could not be delivered - SMTP may be unconfigured.',
                            'warning'
                        );
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

renderAdminShell(
    $user,
    'admin-contact',
    'Contact Messages',
    'Manage public inquiries and document each response.'
);

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  html{scroll-behavior:smooth}.contact-workspace{--contact-ink:#192235;--contact-gold:#d2a248;--contact-line:#dfe5ed;--contact-muted:#687386;max-width:1480px;margin:0 auto;color:var(--contact-ink)}
  .contact-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(290px,.8fr);gap:46px;min-height:320px;padding:50px 54px 58px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 84% 14%,rgba(210,162,72,.2),transparent 29%),linear-gradient(135deg,#172033,#202d45 64%,#27344b);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.contact-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.16;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.contact-kicker,.contact-stat-code,.inbox-eyebrow{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.contact-kicker{margin-bottom:17px;color:#e9bf6e}.contact-hero h2{max-width:760px;margin:0;font-size:clamp(39px,4.4vw,64px);line-height:.98;letter-spacing:-.054em;text-wrap:balance}.contact-hero-copy>p{max-width:610px;margin:23px 0 0;color:#bdc8d8;font-size:15px;line-height:1.72;text-wrap:pretty}.contact-hero-note{display:inline-flex;align-items:center;gap:9px;margin-top:24px;color:#aeb9ca;font-size:12px}.contact-hero-note::before{content:'';width:7px;height:7px;border-radius:50%;background:<?php echo ($status_counts['pending'] ?? 0) > 0 ? '#e2ad4c' : '#70bd91'; ?>;box-shadow:0 0 0 5px rgba(255,255,255,.07)}
  .contact-stats{align-self:end;display:grid;gap:2px}.contact-stat{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.contact-stat:first-child{border-radius:14px 14px 5px 5px}.contact-stat:last-child{border-radius:5px 5px 14px 14px}.contact-stat-code{color:#e9bf6e}.contact-stat-label{color:#d4dce8;font-size:13px}.contact-stat-value{font-size:24px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
  .contact-commandbar{position:relative;z-index:2;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px;margin:-18px 22px 0;padding:13px 15px;border:1px solid var(--contact-line);border-radius:13px;background:#f8fafc;box-shadow:0 12px 30px rgba(25,34,53,.08)}.search-bar{display:flex;gap:9px;margin:0}.search-bar .form-control{flex:1;min-width:0}.status-tabs{display:flex;gap:3px;padding:3px;border:1px solid #e0e5ec;border-radius:9px;background:#e9edf2}.status-tab{display:inline-flex;align-items:center;gap:7px;min-height:37px;padding:9px 13px;border-radius:6px;color:#657083;font-size:12px;font-weight:680;text-decoration:none;white-space:nowrap;transition:background .2s ease,color .2s ease,box-shadow .2s ease}.status-tab:hover{color:#182033}.status-tab.active{background:#fff;color:#182033;box-shadow:0 1px 4px rgba(24,32,51,.1)}.status-count{display:inline-grid;place-items:center;min-width:19px;height:19px;padding:0 5px;border-radius:5px;background:#d8dde4;color:#556174;font:720 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.status-tab.active .status-count{background:#eadab9;color:#79571f}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:9px 14px;border:1px solid transparent;border-radius:8px;background:none;color:inherit;font-family:inherit;font-size:12px;font-weight:680;line-height:1.2;text-decoration:none;cursor:pointer;transition:transform .2s ease,background .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease}.btn:hover{transform:translateY(-1px)}.btn:active{transform:translateY(0) scale(.98)}.btn:focus-visible,.status-tab:focus-visible,.form-control:focus-visible{outline:3px solid rgba(210,162,72,.28);outline-offset:2px}.btn-primary{border-color:var(--contact-gold);background:var(--contact-gold);color:#182033;box-shadow:0 7px 17px rgba(210,162,72,.14)}.btn-primary:hover{border-color:#dfb45f;background:#dfb45f}.btn-secondary{border-color:#d8dfe8;background:#fff;color:#344054}.btn-secondary:hover{border-color:#b3bdca;background:#f7f8fa}.btn-success{border-color:#cfe5d7;background:#f1f9f4;color:#2d704c}.btn-success:hover{border-color:#9ac9ac;background:#e7f5ed}.btn-danger{border-color:#ead6d1;background:#fff8f6;color:#984633}.btn-danger:hover{border-color:#d39a8d;background:#faece8}.btn-sm{min-height:34px;padding:7px 10px;font-size:11px}
  .form-control{width:100%;min-height:42px;padding:9px 13px;border:1px solid #d8dfe7;border-radius:8px;background:#fff;color:#000!important;font-family:inherit;font-size:13px;line-height:1.45}.form-control:focus{outline:0;border-color:#b88731;box-shadow:0 0 0 3px rgba(210,162,72,.16)}.form-label{display:block;margin-bottom:7px;color:#000;font-size:12px;font-weight:700}.form-group{margin-bottom:18px}.form-check{display:flex;align-items:center;gap:8px;margin-bottom:4px;color:#000;font-size:13px;font-weight:600}
  .alert{display:flex;align-items:center;gap:10px;margin:0 0 19px;padding:13px 16px;border:1px solid #d9e6de;border-radius:10px;background:#f3faf6;color:#245d40;font-size:13px;font-weight:600}.alert-error{border-color:#edd8d2;background:#fff6f3;color:#93432f}.alert-warning{border-color:#f1d49b;background:#fff8e8;color:#805a16}.alert-info{border-color:#cbddeb;background:#f0f7fc;color:#28566f}.alert-mark{display:grid;place-items:center;flex:0 0 auto;width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,.72);font-weight:800}.alert-dismiss{margin-left:auto;border:0;background:transparent;color:inherit;font-size:22px;line-height:1;cursor:pointer}.alert-dismiss:focus-visible{outline:2px solid currentColor;outline-offset:3px}
  .contact-inbox{margin-top:34px;overflow:hidden;border:1px solid var(--contact-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.065)}.inbox-header{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:28px 31px 23px;border-bottom:1px solid #e8ecf1}.inbox-eyebrow{margin-bottom:8px;color:#987027}.inbox-title{margin:0;color:#1c2639;font-size:25px;line-height:1.1;letter-spacing:-.03em}.inbox-copy{max-width:650px;margin:8px 0 0;color:var(--contact-muted);font-size:13px;line-height:1.55}.inbox-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}
  .message-card{position:relative;padding:25px 30px 27px;border-bottom:1px solid #edf0f4;background:#fff;transition:background .2s ease}.message-card:last-child{border-bottom:0}.message-card:hover{background:#fbfaf7}.message-header{display:grid;grid-template-columns:48px minmax(0,1fr) auto;gap:16px;align-items:start;margin-bottom:17px}.sender-avatar{display:grid;place-items:center;width:44px;height:44px;border:1px solid #e0d2b5;border-radius:11px;background:#f8f1e3;color:#805c21;font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.message-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.sender-name{color:#202a3d;font-size:15px;font-weight:690}.sender-email{color:#6e798b;font-size:12px;overflow-wrap:anywhere}.concern-badge{display:inline-flex;padding:4px 7px;border:1px solid #d8e3ed;border-radius:5px;background:#f0f5fa;color:#315b8c;font-size:9px;font-weight:750;letter-spacing:.055em;text-transform:uppercase}.message-date{margin-top:7px;color:#929cab;font-size:11px}.message-id{color:#8a95a5;font:650 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.message-body{max-width:900px;margin:0 0 18px 64px;padding:17px 19px;border-left:3px solid #d6b36b;background:#f8f9fb;color:#344054;font-size:13px;line-height:1.68;overflow-wrap:anywhere}.notes-box{max-width:900px;margin:0 0 18px 64px;padding:16px 18px;border:1px solid #d8e6e1;border-radius:9px;background:#f2f8f6;color:#355b54;font-size:12px;line-height:1.6}.notes-title{margin-bottom:7px;color:#254c44;font-weight:720}.notes-handler{margin-top:10px;color:#6e827d;font-size:11px}.message-actions{display:flex;gap:8px;flex-wrap:wrap;margin-left:64px}
  .empty-state{padding:72px 28px;text-align:center}.empty-state-icon{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 16px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#876225;font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.empty-title{color:#273247;font-size:18px;font-weight:690}.empty-copy{max-width:520px;margin:7px auto 0;color:#7a8596;font-size:13px;line-height:1.58}.empty-copy a{color:#805c21;font-weight:680}.pagination{display:flex;justify-content:center;align-items:center;gap:12px;padding:18px 22px;border-top:1px solid #edf0f4;background:#fafbfc}.page-label{color:#758093;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace}
  .modal{display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;padding:24px;background:rgba(12,18,29,.68);backdrop-filter:blur(7px)}.modal.active{display:flex}.modal-content{display:flex;flex-direction:column;width:min(680px,100%);max-height:calc(100dvh - 48px);overflow:hidden;border:1px solid rgba(255,255,255,.6);border-radius:18px;background:#fff;box-shadow:0 30px 80px rgba(9,15,27,.3);animation:contact-modal-rise .25s cubic-bezier(.2,.8,.2,1) both}.modal-header{position:relative;display:flex;align-items:center;justify-content:space-between;padding:26px 30px 23px;overflow:hidden;background:#182033;color:#fff}.modal-header::after{content:'';position:absolute;right:-42px;top:-72px;width:170px;height:170px;border:30px solid rgba(210,162,72,.17);border-radius:50%}.modal-header h3{position:relative;z-index:1;margin:0;color:#fff;font-size:23px;letter-spacing:-.025em}.modal-close{position:relative;z-index:2;display:grid;place-items:center;width:34px;height:34px;border:0;border-radius:8px;background:none;color:#fff;font-size:25px;cursor:pointer}.modal-close:hover{background:rgba(255,255,255,.1)}.modal-body{flex:1;overflow-y:auto;padding:26px 30px}.modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:20px 30px 26px;border-top:1px solid #e8ecf1}.recipient-summary{margin-bottom:20px;padding:15px 17px;border:1px solid #e0e5ec;border-radius:9px;background:#f7f9fb}.recipient-label{color:#707b8e;font-size:11px;font-weight:680}.recipient-person{margin-top:5px;color:#202a3d;font-size:14px}.recipient-email{color:#6e798b}.form-help{margin-top:7px;color:#000;font-size:11px;font-weight:600;line-height:1.5}@keyframes contact-modal-rise{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}
  @media(max-width:1040px){.contact-hero{grid-template-columns:1fr;gap:30px}.contact-stats{grid-template-columns:repeat(3,1fr)}.contact-stat{grid-template-columns:32px 1fr}.contact-stat-value{grid-column:2}.contact-commandbar{grid-template-columns:1fr}.status-tabs{width:max-content;max-width:100%;overflow-x:auto}}
  @media(max-width:700px){.contact-hero{min-height:0;padding:30px 24px 48px;border-radius:18px 18px 7px 7px}.contact-hero h2{font-size:39px}.contact-stats{grid-template-columns:1fr}.contact-stat{grid-template-columns:34px 1fr auto}.contact-stat-value{grid-column:auto}.contact-commandbar{margin:-17px 12px 0}.search-bar{display:grid;grid-template-columns:1fr 1fr}.search-bar .form-control{grid-column:1/-1}.status-tabs{width:100%}.status-tab{flex:1;justify-content:center}.inbox-header{align-items:flex-start;flex-direction:column;padding:24px 20px 20px}.message-card{padding:21px 19px 23px}.message-header{grid-template-columns:42px minmax(0,1fr)}.sender-avatar{width:40px;height:40px}.message-id{grid-column:2}.message-body,.notes-box,.message-actions{margin-left:0}.message-actions,.message-actions .btn{width:100%}.pagination{flex-wrap:wrap}.modal{padding:12px}.modal-content{max-height:calc(100dvh - 24px)}.modal-header,.modal-body,.modal-footer{padding-left:22px;padding-right:22px}}
  @media(prefers-reduced-motion:reduce){.btn,.message-card{transition:none}.modal-content{animation:none}}
</style>
<div class="contact-workspace">
  <?php if ($error): ?>
    <div class="alert alert-error"><span class="alert-mark" aria-hidden="true">&times;</span><?php echo cm_escape($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert"><span class="alert-mark" aria-hidden="true">&#10003;</span><?php echo cm_escape($success); ?></div>
  <?php endif; ?>
  <?php if ($warning): ?>
    <div class="alert alert-warning" role="status"><span class="alert-mark" aria-hidden="true">!</span><?php echo cm_escape($warning); ?></div>
  <?php endif; ?>
  <?php if (!$smtp_configured): ?>
    <div class="alert alert-info" role="status">
      <span class="alert-mark" aria-hidden="true">i</span>
      <span>Email delivery is not configured - replies will be saved but not emailed.</span>
      <button type="button" class="alert-dismiss" aria-label="Dismiss email configuration notice" onclick="this.parentElement.remove()">&times;</button>
    </div>
  <?php endif; ?>

  <section class="contact-hero" aria-labelledby="contact-hero-title">
    <div class="contact-hero-copy">
      <div class="contact-kicker">Public correspondence &middot; Institute response desk</div>
      <h2 id="contact-hero-title">Turn every inquiry into a clear next step.</h2>
      <p>Review public questions, reply by email, and preserve a complete resolution record for the institute.</p>
      <div class="contact-hero-note"><?php echo (int) ($status_counts['pending'] ?? 0); ?> pending message<?php echo (int) ($status_counts['pending'] ?? 0) === 1 ? '' : 's'; ?> currently need attention.</div>
    </div>
    <div class="contact-stats" aria-label="Contact message totals">
      <div class="contact-stat"><span class="contact-stat-code">01</span><span class="contact-stat-label">Pending</span><strong class="contact-stat-value"><?php echo (int) ($status_counts['pending'] ?? 0); ?></strong></div>
      <div class="contact-stat"><span class="contact-stat-code">02</span><span class="contact-stat-label">Resolved</span><strong class="contact-stat-value"><?php echo (int) ($status_counts['resolved'] ?? 0); ?></strong></div>
      <div class="contact-stat"><span class="contact-stat-code">03</span><span class="contact-stat-label">All inquiries</span><strong class="contact-stat-value"><?php echo (int) $all_messages; ?></strong></div>
    </div>
  </section>

  <div class="contact-commandbar">
    <form method="GET" action="admin-contact.php#contact-inbox" class="search-bar" role="search">
      <input type="hidden" name="status" value="<?php echo cm_escape($status_filter); ?>">
      <input type="text" name="search" class="form-control" aria-label="Search contact messages" placeholder="Search name, email, or message..." value="<?php echo cm_escape($search); ?>">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($search): ?><a href="?status=<?php echo cm_escape($status_filter); ?>#contact-inbox" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>

    <nav class="status-tabs" aria-label="Contact message status">
            <?php foreach ($valid_statuses as $status): ?>
                <a href="?status=<?php echo $status; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>#contact-inbox"
                   class="status-tab <?php echo $status_filter === $status ? 'active' : ''; ?>"
                   aria-current="<?php echo $status_filter === $status ? 'page' : 'false'; ?>">
                    <?php echo ucfirst($status); ?> <span class="status-count"><?php echo (int) $status_counts[$status]; ?></span>
                </a>
            <?php endforeach; ?>
    </nav>
  </div>

        <!-- Messages List -->
        <section class="contact-inbox" id="contact-inbox" aria-labelledby="contact-inbox-title">
          <header class="inbox-header">
            <div>
              <div class="inbox-eyebrow"><?php echo cm_escape($current_status_label); ?> queue</div>
              <h3 class="inbox-title" id="contact-inbox-title">Public inquiries</h3>
              <p class="inbox-copy"><?php echo $search ? 'Results matching “' . cm_escape($search) . '” in this queue.' : 'Newest first. Keep replies and resolution notes attached to the original inquiry.'; ?></p>
            </div>
            <span class="inbox-count"><?php echo (int) $visible_messages; ?> SHOWN</span>
          </header>
            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon" aria-hidden="true">00</div>
                    <div class="empty-title">No <?php echo cm_escape($status_filter); ?> messages</div>
                    <p class="empty-copy">
                        <?php if ($search): ?>
                            Try adjusting your search terms or <a href="?status=<?php echo cm_escape($status_filter); ?>#contact-inbox">clear filters</a>.
                        <?php else: ?>
                            Messages from the public contact form will appear here.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg):
                    $name_parts = preg_split('/\s+/', trim((string) $msg['name'])) ?: [];
                    $initials = $name_parts
                        ? mb_strtoupper(mb_substr($name_parts[0], 0, 1) . (count($name_parts) > 1 ? mb_substr($name_parts[count($name_parts) - 1], 0, 1) : ''))
                        : '?';
                ?>
                    <article class="message-card">
                        <div class="message-header">
                            <div class="sender-avatar" aria-hidden="true"><?php echo cm_escape($initials); ?></div>
                            <div>
                                <div class="message-meta">
                                    <strong class="sender-name"><?php echo cm_escape($msg['name']); ?></strong>
                                    <span class="sender-email"><?php echo cm_escape($msg['email']); ?></span>
                                    <span class="concern-badge"><?php echo cm_escape($msg['concern_type']); ?></span>
                                </div>
                                <div class="message-date">
                                    Received <?php echo date('F j, Y \a\t g:i A', strtotime($msg['created_at'])); ?>
                                </div>
                            </div>
                            <span class="message-id">INQUIRY #<?php echo (int) $msg['contact_id']; ?></span>
                        </div>

                        <div class="message-body">
                            <?php echo nl2br(cm_escape($msg['message'])); ?>
                        </div>

                        <?php if ($msg['status'] !== 'pending' && $msg['notes']): ?>
                            <div class="notes-box">
                                <div class="notes-title">Staff notes / reply</div>
                                <div><?php echo nl2br(cm_escape($msg['notes'])); ?></div>
                                <?php if ($msg['resolved_by_name']): ?>
                                    <div class="notes-handler">
                                        Handled by <?php echo cm_escape($msg['resolved_by_name']); ?>
                                        on <?php echo date('M j, Y', strtotime($msg['resolved_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="message-actions">
                            <?php if ($msg['status'] === 'pending'): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick='openReplyModal(<?php echo (int) $msg['contact_id']; ?>, <?php echo json_encode((string) $msg['name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode((string) $msg['email'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    Reply by email
                                </button>
                                <button type="button" class="btn btn-success btn-sm" onclick="openResolveModal(<?php echo (int) $msg['contact_id']; ?>)">
                                    Mark resolved
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="archiveMessage(<?php echo (int) $msg['contact_id']; ?>)">
                                    Archive
                                </button>
                            <?php elseif ($msg['status'] === 'resolved'): ?>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="reopenMessage(<?php echo (int) $msg['contact_id']; ?>)">
                                    Reopen
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="archiveMessage(<?php echo (int) $msg['contact_id']; ?>)">
                                    Archive
                                </button>
                            <?php elseif ($msg['status'] === 'archived'): ?>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="reopenMessage(<?php echo (int) $msg['contact_id']; ?>)">
                                    Reopen
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav class="pagination" aria-label="Contact message pages">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>#contact-inbox" class="btn btn-sm btn-secondary">&larr; Previous</a>
                        <?php endif; ?>

                        <span class="page-label">
                            PAGE <?php echo (int) $page; ?> OF <?php echo (int) $total_pages; ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>#contact-inbox" class="btn btn-sm btn-secondary">Next &rarr;</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

<!-- Reply Modal -->
<div id="replyModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="reply-modal-title">
        <div class="modal-content" tabindex="-1">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="contact_id" id="reply_contact_id">

                <div class="modal-header">
                    <h3 id="reply-modal-title">Reply by email</h3>
                    <button type="button" class="modal-close" onclick="closeReplyModal()" aria-label="Close reply dialog">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="recipient-summary">
                        <div class="recipient-label">REPLYING TO</div>
                        <div class="recipient-person">
                            <strong id="reply_recipient_name"></strong> <span class="recipient-email">(<span id="reply_recipient_email"></span>)</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reply_message">Your reply message</label>
                        <textarea id="reply_message" name="reply_message" class="form-control" rows="8" required placeholder="Type your response here..."></textarea>
                        <div class="form-help">
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
                    <button type="submit" class="btn btn-primary">Send email reply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Resolve Modal -->
    <div id="resolveModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="resolve-modal-title">
        <div class="modal-content" tabindex="-1">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="contact_id" id="resolve_contact_id">

                <div class="modal-header">
                    <h3 id="resolve-modal-title">Mark as resolved</h3>
                    <button type="button" class="modal-close" onclick="closeResolveModal()" aria-label="Close resolution dialog">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="resolution_notes">Resolution notes (optional)</label>
                        <textarea id="resolution_notes" name="notes" class="form-control" rows="4" placeholder="Add internal notes about how this was resolved..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResolveModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Mark as resolved</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const replyModal = document.getElementById('replyModal');
    const resolveModal = document.getElementById('resolveModal');
    let lastContactFocus = null;

    function showContactModal(modal, focusTarget) {
        lastContactFocus = document.activeElement;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => focusTarget.focus(), 50);
    }

    function hideContactModal(modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastContactFocus) lastContactFocus.focus();
    }

    function openReplyModal(contactId, name, email) {
        document.getElementById('reply_contact_id').value = contactId;
        document.getElementById('reply_recipient_name').textContent = name;
        document.getElementById('reply_recipient_email').textContent = email;
        const replyField = document.getElementById('reply_message');
        replyField.value = '';
        showContactModal(replyModal, replyField);
    }

    function closeReplyModal() {
        hideContactModal(replyModal);
    }

    function openResolveModal(contactId) {
        document.getElementById('resolve_contact_id').value = contactId;
        const notesField = document.getElementById('resolution_notes');
        notesField.value = '';
        showContactModal(resolveModal, notesField);
    }

    function closeResolveModal() {
        hideContactModal(resolveModal);
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
            if (replyModal.classList.contains('active')) closeReplyModal();
            if (resolveModal.classList.contains('active')) closeResolveModal();
        }
    });

    // Close modals on backdrop click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                if (modal === replyModal) closeReplyModal();
                if (modal === resolveModal) closeResolveModal();
            }
        });
    });
    </script>
</div>

<?php
renderAdminShellClose();
