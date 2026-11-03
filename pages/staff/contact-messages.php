<?php
/**
 * Contact Messages Management Page
 * For admin and research_staff to view and manage public contact form submissions
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

// Only admin and research_staff can access this page
requireLogin();
requireRole(['admin', 'research_staff']);

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Helper function
function cm_escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Filter by status
$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'resolved', 'archived'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'pending';
}
$search = trim((string) ($_GET['search'] ?? ''));
$search = substr($search, 0, 200);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where_conditions = ['cm.status = ?'];
$query_params = [$status_filter];
$query_types = 's';
if ($search !== '') {
    $where_conditions[] = '(cm.name LIKE ? OR cm.email LIKE ? OR cm.message LIKE ?)';
    $search_term = '%' . $search . '%';
    array_push($query_params, $search_term, $search_term, $search_term);
    $query_types .= 'sss';
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
$query_params[] = $per_page;
$query_params[] = $offset;
$query_types .= 'ii';
$stmt->bind_param($query_types, ...$query_params);
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

// Get filtered total count for pagination
$count_params = array_slice($query_params, 0, count($query_params) - 2);
$count_types = substr($query_types, 0, -2);
$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM contact_messages cm WHERE {$where_clause}");
$stmt->bind_param($count_types, ...$count_params);
$stmt->execute();
$total_messages = (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);
$stmt->close();
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

        header('Location: ' . SITE_URL . 'pages/staff/contact-messages.php?' . http_build_query(['status' => $status_filter, 'search' => $search]));
        exit;
    }
}

// Render staff shell
renderStaffShell($user, 'contact-messages', 'Contact Messages', 'Manage public contact form submissions.');
?>

<style>
  .staff-contact-workspace { display: grid; gap: 20px; }
  .contact-overview {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 24px;
    padding: 24px 28px;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
  }
  .contact-overview-kicker { margin: 0 0 6px; color: #0D9488; font-size: 11px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
  .contact-overview h1 { margin: 0; color: #111827; font-size: clamp(22px, 2vw, 28px); line-height: 1.2; letter-spacing: -.025em; }
  .contact-overview-copy { max-width: 560px; margin: 7px 0 0; color: #64748B; font-size: 13px; line-height: 1.55; }
  .contact-stats { display: grid; grid-template-columns: repeat(3, minmax(84px, 1fr)); gap: 8px; }
  .contact-stat { min-width: 84px; padding: 12px 14px; border: 1px solid #E5E7EB; border-radius: 10px; background: #F8FAFC; }
  .contact-stat-label { display: block; color: #64748B; font-size: 11px; }
  .contact-stat-value { display: block; margin-top: 4px; color: #0F766E; font-size: 22px; line-height: 1; font-weight: 750; font-variant-numeric: tabular-nums; }
  .alert { padding: 16px 20px; border-radius: 12px; margin: 0; }
  .alert-success { background: #DCFCE7; color: #15803d; border: 1px solid #BBF7D0; }
  .alert-error { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }

  .card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
    overflow: hidden;
  }

  .status-tabs {
    margin-bottom: 0;
    display: flex;
    gap: 8px;
    border-bottom: 1px solid #E5E7EB;
    padding-bottom: 0;
    flex-wrap: wrap;
  }

  .status-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 14px;
    text-decoration: none;
    color: #64748B;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    font-size: 13px;
    font-weight: 600;
    transition: color 0.18s ease, border-color 0.18s ease;
  }

  .status-tab.active {
    border-bottom-color: #0D9488;
    color: #0F766E;
  }

  .status-tab:hover { color: #0F766E; }
  .status-tab:focus-visible { outline: 3px solid rgba(13,148,136,.2); outline-offset: 2px; }
  .status-count { display: inline-grid; min-width: 20px; height: 20px; place-items: center; padding: 0 5px; border-radius: 6px; background: #F1F5F9; color: #475569; font-size: 10px; font-variant-numeric: tabular-nums; }
  .status-tab.active .status-count { background: #DDF4F1; color: #0F766E; }

  .message-card {
    padding: 22px 24px;
    border-bottom: 1px solid var(--border, #E5E7EB);
    transition: background .18s ease;
  }
  .message-card:hover { background: #FBFCFD; }

  .message-card:last-child { border-bottom: none; }

  .badge {
    background: #0d9488;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    white-space: nowrap;
  }

  .btn {
    min-height: 38px;
    padding: 9px 14px;
    border: 1px solid transparent;
    border-radius: 9px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease;
  }

  .btn-primary { background: #0D9488; border-color: #0D9488; color: white; }
  .btn-primary:hover { background: #059669; border-color: #059669; transform: translateY(-1px); }
  .btn-secondary { background: #fff; border-color: #D8DFE8; color: #344054; }
  .btn-secondary:hover { background: #F7F8FA; border-color: #B3BDCA; }
  .btn-sm { min-height: 34px; padding: 7px 12px; font-size: 12px; }
  .btn:focus-visible { outline: 3px solid rgba(13,148,136,.22); outline-offset: 2px; }

  .form-control {
    width: 100%;
    padding: 10px 13px;
    border: 1px solid #CBD5E1;
    border-radius: 9px;
    font-size: 14px;
    font-family: inherit;
  }
  .form-control:focus-visible { outline: 3px solid rgba(13,148,136,.2); outline-offset: 2px; border-color: #0D9488; }

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
    background: var(--bg-card, #FFFFFF);
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow: hidden;
  }

  .modal-header {
    padding: 24px;
    border-bottom: 1px solid var(--border, #E5E7EB);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    cursor: pointer;
    color: var(--text-light, #64748B);
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
    border-top: 1px solid var(--border, #E5E7EB);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
  .contact-message-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
  .contact-name { color: #111827; font-size: 15px; font-weight: 700; }
  .contact-email, .contact-message-date { color: #64748B; font-size: 12px; overflow-wrap: anywhere; }
  .contact-message-date { margin-bottom: 12px; }
  .contact-message-body { margin-bottom: 12px; padding: 15px 17px; border-left: 3px solid #0D9488; border-radius: 0 9px 9px 0; background: #F8FAFC; color: #334155; line-height: 1.65; overflow-wrap: anywhere; }
  .contact-staff-notes { margin-bottom: 12px; padding: 15px 17px; border: 1px solid #D9EFEC; border-radius: 10px; background: #F3FBFA; color: #355B54; }
  .contact-staff-notes-copy { margin-top: 8px; line-height: 1.6; }
  .contact-staff-notes-meta { margin-top: 10px; color: #64748B; font-size: 12px; }
  .contact-message-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .contact-empty { padding: 56px 24px; text-align: center; color: #64748B; }
  .contact-empty-icon { display: grid; width: 48px; height: 48px; place-items: center; margin: 0 auto 14px; border: 1px solid #D9EFEC; border-radius: 13px; background: #F3FBFA; font-size: 23px; }
  .contact-empty-title { margin: 0 0 6px; color: #111827; font-size: 17px; font-weight: 700; }
  .contact-empty-copy { margin: 0; color: #64748B; font-size: 13px; line-height: 1.55; }
  .contact-pagination { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 18px 22px; border-top: 1px solid #E5E7EB; background: #FAFBFC; }
  .contact-page-label { color: #64748B; font-size: 12px; font-variant-numeric: tabular-nums; }
  .contact-modal-title { margin: 0; color: #111827; font-size: 20px; letter-spacing: -.02em; }
  @media (max-width: 760px) { .contact-overview { grid-template-columns: 1fr; } }
  @media (max-width: 560px) { .contact-stats { grid-template-columns: 1fr; } .contact-stat { display: flex; align-items: center; justify-content: space-between; } .contact-message-actions .btn { flex: 1 1 100%; } .contact-pagination { flex-wrap: wrap; } }
</style>

<style>
  .staff-dashboard .staff-page-content .staff-contact-workspace { max-width: 1480px; margin: 0 auto; color: #111827; }
  .staff-dashboard .staff-page-content .contact-overview { position: relative; isolation: isolate; display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(290px, .8fr); gap: 46px; min-height: 320px; padding: 50px 54px 58px; overflow: hidden; border: 0; border-radius: 24px 24px 8px 8px; background: radial-gradient(circle at 84% 14%, rgba(210,162,72,.2), transparent 29%), linear-gradient(135deg,#172033,#202d45 64%,#27344b); color: #fff; box-shadow: 0 24px 58px rgba(24,34,53,.16); }
  .staff-dashboard .staff-page-content .contact-overview::after { position: absolute; inset: 0; z-index: -1; background: repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); content: ''; opacity: .16; pointer-events: none; }
  .staff-dashboard .staff-page-content .contact-overview-kicker { margin: 0 0 17px; color: #a98bdf; font: 700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing: .15em; text-transform: uppercase; }
  .staff-dashboard .staff-page-content .contact-overview h1 { max-width: 760px; margin: 0; color: #fff; font-size: clamp(39px,4.4vw,64px); line-height: .98; letter-spacing: -.054em; text-wrap: balance; }
  .staff-dashboard .staff-page-content .contact-overview-copy { max-width: 610px; margin: 23px 0 0; color: #bdc8d8; font-size: 15px; line-height: 1.72; }
  .staff-dashboard .staff-page-content .contact-stats { align-self: end; display: grid; gap: 2px; }
  .staff-dashboard .staff-page-content .contact-stat { display: grid; grid-template-columns: 38px 1fr auto; align-items: center; gap: 12px; min-width: 0; padding: 14px 16px; border: 1px solid rgba(255,255,255,.08); border-radius: 0; background: rgba(255,255,255,.06); }
  .staff-dashboard .staff-page-content .contact-stat:first-child { border-radius: 14px 14px 5px 5px; }
  .staff-dashboard .staff-page-content .contact-stat:last-child { border-radius: 5px 5px 14px 14px; }
  .staff-dashboard .staff-page-content .contact-stat-code { color: #a98bdf; font: 700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing: .15em; }
  .staff-dashboard .staff-page-content .contact-stat-label { color: #d4dce8; font-size: 13px; }
  .staff-dashboard .staff-page-content .contact-stat-value { margin: 0; color: #fff; font-size: 24px; font-weight: 720; letter-spacing: -.03em; }
  .staff-dashboard .staff-page-content .contact-commandbar { position: relative; z-index: 2; display: grid; grid-template-columns: minmax(0,1fr) auto; align-items: center; gap: 16px; margin: -18px 22px 0; padding: 13px 15px; border: 1px solid #e5e7eb; border-radius: 13px; background: #f8fafc; box-shadow: 0 12px 30px rgba(25,34,53,.08); }
  .staff-dashboard .staff-page-content .contact-search-form { display: flex; gap: 9px; margin: 0; }
  .staff-dashboard .staff-page-content .contact-search-form .form-control { flex: 1; min-width: 0; }
  .staff-dashboard .staff-page-content .contact-commandbar .status-tabs { display: flex; flex-wrap: wrap; gap: 3px; padding: 3px; border: 1px solid #e0e5ec; border-radius: 9px; background: #e9edf2; }
  .staff-dashboard .staff-page-content .contact-commandbar .status-tab { display: inline-flex; min-height: 37px; align-items: center; gap: 7px; padding: 9px 13px; border: 0; border-radius: 6px; color: #657083; font-size: 12px; font-weight: 680; text-decoration: none; white-space: nowrap; }
  .staff-dashboard .staff-page-content .contact-commandbar .status-tab.active { background: #fff; color: #182033; box-shadow: 0 1px 4px rgba(24,32,51,.1); }
  .staff-dashboard .staff-page-content .contact-commandbar .status-count { display: inline-grid; min-width: 19px; height: 19px; place-items: center; padding: 0 5px; border-radius: 5px; background: #d8dde4; color: #556174; font: 720 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .staff-dashboard .staff-page-content .contact-commandbar .status-tab.active .status-count { background: #efebff; color: #4d1ea5; }
  .staff-dashboard .staff-page-content .contact-inbox { margin-top: 34px; overflow: hidden; border: 1px solid #e5e7eb; border-radius: 18px; background: #fff; box-shadow: 0 14px 38px rgba(31,42,63,.065); }
  .staff-dashboard .staff-page-content .contact-sender-avatar { flex: 0 0 44px; }
  .staff-dashboard .staff-page-content .contact-message-meta > * { min-width: 0; }
  .staff-dashboard .staff-page-content .contact-message-meta .concern-badge { display: inline-flex; padding: 4px 7px; border: 1px solid #d8e3ed; border-radius: 5px; background: #f0f5fa; color: #315b8c; font-size: 9px; font-weight: 750; letter-spacing: .055em; text-transform: uppercase; }
  .staff-dashboard .staff-page-content .notes-title { margin-bottom: 7px; color: #254c44; font-size: 12px; font-weight: 720; }
  .staff-dashboard .staff-page-content .inbox-header { display: flex; align-items: end; justify-content: space-between; gap: 18px; padding: 28px 31px 23px; border-bottom: 1px solid #e8ecf1; }
  .staff-dashboard .staff-page-content .inbox-eyebrow { margin-bottom: 8px; color: #5b1ebc; font: 700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing: .15em; text-transform: uppercase; }
  .staff-dashboard .staff-page-content .inbox-title { margin: 0; color: #1c2639; font-size: 25px; line-height: 1.1; letter-spacing: -.03em; }
  .staff-dashboard .staff-page-content .inbox-copy { max-width: 650px; margin: 8px 0 0; color: #64748b; font-size: 13px; line-height: 1.55; }
  .staff-dashboard .staff-page-content .inbox-count { color: #8a95a5; font: 650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; white-space: nowrap; }
  .staff-dashboard .staff-page-content .message-card { position: relative; padding: 25px 30px 27px; border-bottom: 1px solid #edf0f4; background: #fff; }
  .staff-dashboard .staff-page-content .message-card:hover { background: #fbfaf7; }
  .staff-dashboard .staff-page-content .contact-message-header { display: grid; grid-template-columns: 48px minmax(0,1fr) auto; gap: 16px; align-items: start; margin-bottom: 17px; }
  .staff-dashboard .staff-page-content .contact-sender-avatar { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #d9d2ef; border-radius: 11px; background: #f5f2ff; color: #4d1ea5; font: 750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .staff-dashboard .staff-page-content .contact-message-meta { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
  .staff-dashboard .staff-page-content .contact-name { color: #202a3d; font-size: 15px; font-weight: 690; }
  .staff-dashboard .staff-page-content .contact-email { color: #6e798b; font-size: 12px; overflow-wrap: anywhere; }
  .staff-dashboard .staff-page-content .contact-message-date { margin-top: 7px; color: #929cab; font-size: 11px; }
  .staff-dashboard .staff-page-content .contact-message-body { max-width: 900px; margin: 0 0 18px 64px; padding: 17px 19px; border: 0; border-left: 3px solid #5b1ebc; border-radius: 0; background: #f8f9fb; color: #344054; font-size: 13px; line-height: 1.68; }
  .staff-dashboard .staff-page-content .contact-staff-notes { max-width: 900px; margin: 0 0 18px 64px; padding: 16px 18px; border: 1px solid #d8e6e1; border-radius: 9px; background: #f2f8f6; color: #355b54; font-size: 12px; line-height: 1.6; }
  .staff-dashboard .staff-page-content .contact-message-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-left: 64px; }
  .staff-dashboard .staff-page-content .contact-empty { padding: 72px 28px; text-align: center; }
  .staff-dashboard .staff-page-content .contact-empty-icon { display: grid; width: 52px; height: 52px; place-items: center; margin: 0 auto 16px; border: 1px solid #d9d2ef; border-radius: 14px; background: #f5f2ff; color: #4d1ea5; font: 750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .staff-dashboard .staff-page-content .contact-empty-title { color: #273247; font-size: 18px; font-weight: 690; }
  .staff-dashboard .staff-page-content .contact-empty-copy { max-width: 520px; margin: 7px auto 0; color: #7a8596; font-size: 13px; line-height: 1.58; }
  .staff-dashboard .staff-page-content .contact-pagination { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 18px 22px; border-top: 1px solid #edf0f4; background: #fafbfc; }
  .staff-dashboard .staff-page-content .contact-page-label { color: #758093; font: 650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  @media (max-width: 900px) { .staff-dashboard .staff-page-content .contact-overview { grid-template-columns: 1fr; gap: 24px; padding: 36px 30px; } .staff-dashboard .staff-page-content .contact-stats { max-width: 420px; } }
  @media (max-width: 900px) { .staff-dashboard .staff-page-content .contact-commandbar { grid-template-columns: 1fr; } }
  @media (max-width: 640px) { .staff-dashboard .staff-page-content .contact-overview { padding: 29px 22px 32px; } .staff-dashboard .staff-page-content .contact-overview h1 { font-size: clamp(34px,10vw,46px); } .staff-dashboard .staff-page-content .contact-commandbar { margin: -14px 10px 0; padding: 10px; } .staff-dashboard .staff-page-content .contact-search-form { flex-wrap: wrap; } .staff-dashboard .staff-page-content .contact-search-form .form-control { flex-basis: 100%; } .staff-dashboard .staff-page-content .inbox-header { align-items: flex-start; flex-direction: column; padding: 22px; } .staff-dashboard .staff-page-content .message-card { padding: 20px 18px; } .staff-dashboard .staff-page-content .contact-message-body, .staff-dashboard .staff-page-content .contact-staff-notes, .staff-dashboard .staff-page-content .contact-message-actions { margin-left: 0; } }
</style>

<div class="staff-contact-workspace">
  <section class="contact-overview" aria-labelledby="contact-overview-title">
    <div>
      <p class="contact-overview-kicker">Public correspondence · Institute response desk</p>
      <h1 id="contact-overview-title">Turn every inquiry into a clear next step.</h1>
      <p class="contact-overview-copy">Review public questions and keep resolution notes attached to each original inquiry.</p>
    </div>
    <div class="contact-stats" aria-label="Contact message totals">
      <div class="contact-stat"><span class="contact-stat-code">01</span><span class="contact-stat-label">Pending</span><strong class="contact-stat-value"><?php echo (int) ($status_counts['pending'] ?? 0); ?></strong></div>
      <div class="contact-stat"><span class="contact-stat-code">02</span><span class="contact-stat-label">Resolved</span><strong class="contact-stat-value"><?php echo (int) ($status_counts['resolved'] ?? 0); ?></strong></div>
      <div class="contact-stat"><span class="contact-stat-code">03</span><span class="contact-stat-label">All inquiries</span><strong class="contact-stat-value"><?php echo (int) array_sum(array_map('intval', $status_counts)); ?></strong></div>
    </div>
  </section>

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

<div class="contact-commandbar">
<form method="GET" action="contact-messages.php#contact-inbox" class="contact-search-form" role="search">
  <input type="hidden" name="status" value="<?php echo cm_escape($status_filter); ?>">
  <input type="text" name="search" class="form-control" aria-label="Search contact messages" placeholder="Search name, email, or message..." value="<?php echo cm_escape($search); ?>">
  <button type="submit" class="btn btn-primary">Search</button>
  <?php if ($search !== ''): ?><a href="?status=<?php echo cm_escape($status_filter); ?>#contact-inbox" class="btn btn-secondary">Clear</a><?php endif; ?>
</form>
<!-- Status Filter Tabs -->
<nav class="status-tabs" aria-label="Contact message status">
  <?php foreach ($valid_statuses as $status): ?>
    <a href="?status=<?php echo $status; ?>&amp;search=<?php echo rawurlencode($search); ?>#contact-inbox" class="status-tab <?php echo $status_filter === $status ? 'active' : ''; ?>" aria-current="<?php echo $status_filter === $status ? 'page' : 'false'; ?>">
      <?php echo ucfirst($status); ?> <span class="status-count"><?php echo (int) ($status_counts[$status] ?? 0); ?></span>
    </a>
  <?php endforeach; ?>
</nav>
</div>

<!-- Messages List -->
  <section class="contact-inbox" aria-labelledby="contact-inbox-title">
  <header class="inbox-header">
    <div>
      <div class="inbox-eyebrow"><?php echo ucfirst(cm_escape($status_filter)); ?> queue</div>
      <h2 class="inbox-title" id="contact-inbox-title">Public inquiries</h2>
      <p class="inbox-copy"><?php echo $search !== '' ? 'Results matching “' . cm_escape($search) . '” in this queue.' : 'Newest first. Keep resolution notes attached to the original inquiry.'; ?></p>
    </div>
    <span class="inbox-count"><?php echo (int) count($messages); ?> SHOWN</span>
  </header>
  <?php if (empty($messages)): ?>
        <div class="contact-empty">
      <div class="contact-empty-icon" aria-hidden="true">📭</div>
      <h3 class="contact-empty-title">No <?php echo cm_escape($status_filter); ?> messages</h3>
          <p class="contact-empty-copy"><?php if ($search !== ''): ?>Try adjusting your search terms or <a href="?status=<?php echo cm_escape($status_filter); ?>#contact-inbox">clear filters</a>.<?php else: ?>Messages from the public contact form will appear here.<?php endif; ?></p>
    </div>
  <?php else: ?>
    <?php foreach ($messages as $msg):
      $name_parts = preg_split('/\s+/', trim((string) $msg['name'])) ?: [];
      $contact_initials = $name_parts
          ? strtoupper(substr($name_parts[0], 0, 1) . (count($name_parts) > 1 ? substr($name_parts[count($name_parts) - 1], 0, 1) : ''))
          : '?';
    ?>
      <article class="message-card">
        <div class="contact-message-header">
          <span class="contact-sender-avatar" aria-hidden="true"><?php echo cm_escape($contact_initials); ?></span>
          <div>
            <div class="contact-message-meta">
              <strong class="contact-name"><?php echo cm_escape($msg['name']); ?></strong>
              <span class="contact-email"><?php echo cm_escape($msg['email']); ?></span>
              <span class="badge concern-badge"><?php echo cm_escape($msg['concern_type']); ?></span>
            </div>
          </div>
          <span class="inbox-count">INQUIRY #<?php echo (int) $msg['contact_id']; ?></span>
        </div>

        <div class="contact-message-date">
          📅 Received: <?php echo date('F j, Y \a\t g:i A', strtotime($msg['created_at'])); ?>
        </div>

        <div class="contact-message-body">
          <?php echo nl2br(cm_escape($msg['message'])); ?>
        </div>

        <?php if ($msg['status'] !== 'pending' && $msg['notes']): ?>
          <div class="contact-staff-notes">
            <div class="notes-title">Staff notes / reply</div>
            <div class="contact-staff-notes-copy"><?php echo nl2br(cm_escape($msg['notes'])); ?></div>
            <?php if ($msg['resolved_by_name']): ?>
              <div class="contact-staff-notes-meta">
                Handled by <?php echo cm_escape($msg['resolved_by_name']); ?>
                on <?php echo date('M j, Y', strtotime($msg['resolved_at'])); ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="contact-message-actions">
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
      </article>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="contact-pagination">
        <?php if ($page > 1): ?>
          <a href="?status=<?php echo $status_filter; ?>&amp;search=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $page - 1; ?>#contact-inbox" class="btn btn-sm btn-secondary">← Previous</a>
        <?php endif; ?>

        <span class="contact-page-label">
          Page <?php echo $page; ?> of <?php echo $total_pages; ?>
        </span>

        <?php if ($page < $total_pages): ?>
          <a href="?status=<?php echo $status_filter; ?>&amp;search=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $page + 1; ?>#contact-inbox" class="btn btn-sm btn-secondary">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
  </div>

<!-- Resolve Modal -->
<div id="resolveModal" class="modal">
  <div class="modal-content">
    <form method="POST">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="resolve">
      <input type="hidden" name="contact_id" id="resolve_contact_id">

      <div class="modal-header">
        <h3 class="contact-modal-title">✓ Resolve Contact Message</h3>
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

<?php renderStaffShellClose(); ?>
