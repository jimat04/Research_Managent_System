<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();
$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'];

        // CREATE USER (Faculty/Staff only)
        if ($action === 'create_user') {
            $role = $_POST['role'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $student_id = trim($_POST['student_id'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $specialization = trim($_POST['specialization'] ?? '');
            $academic_rank = $_POST['academic_rank'] ?? null;
            $is_reviewer = isset($_POST['is_reviewer']) ? 1 : 0;
            $office = trim($_POST['office'] ?? '');

            // Only allow faculty and research_staff creation
            if (!in_array($role, ['faculty', 'research_staff'], true)) {
                $error = 'Can only create Faculty or Research Staff accounts.';
            } elseif (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
                $error = 'Name, email, and password are required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                // Check if email exists
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check_stmt->bind_param('s', $email);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'Email already exists.';
                } else {
                    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $status = 'active'; // Admin-created accounts are auto-approved

                    $insert_stmt = $conn->prepare("INSERT INTO users (role, first_name, last_name, email, password, student_id, contact, department, specialization, academic_rank, is_reviewer, office, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $insert_stmt->bind_param('sssssssssssss', $role, $first_name, $last_name, $email, $password_hash, $student_id, $contact, $department, $specialization, $academic_rank, $is_reviewer, $office, $status);

                    if ($insert_stmt->execute()) {
                        logActivity("Created user account: {$first_name} {$last_name} ({$email})", 'user_management');
                        $success = "User {$first_name} {$last_name} created successfully.";
                    } else {
                        $error = 'Failed to create user account.';
                    }
                }
            }
        }

        // EDIT USER
        elseif ($action === 'edit_user') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $student_id = trim($_POST['student_id'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $program = trim($_POST['program'] ?? '');
            $year_level = $_POST['year_level'] ?? null;
            $specialization = trim($_POST['specialization'] ?? '');
            $academic_rank = $_POST['academic_rank'] ?? null;
            $is_reviewer = isset($_POST['is_reviewer']) ? 1 : 0;
            $office = trim($_POST['office'] ?? '');

            if (empty($first_name) || empty($last_name) || empty($email)) {
                $error = 'Name and email are required.';
            } else {
                // Check if email exists for another user
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $check_stmt->bind_param('si', $email, $user_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'Email already used by another user.';
                } else {
                    $update_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, student_id = ?, contact = ?, department = ?, program = ?, year_level = ?, specialization = ?, academic_rank = ?, is_reviewer = ?, office = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_stmt->bind_param('ssssssssssssi', $first_name, $last_name, $email, $student_id, $contact, $department, $program, $year_level, $specialization, $academic_rank, $is_reviewer, $office, $user_id);

                    if ($update_stmt->execute()) {
                        logActivity("Updated user account: {$first_name} {$last_name} (ID: {$user_id})", 'user_management');
                        $success = "User {$first_name} {$last_name} updated successfully.";
                    } else {
                        $error = 'Failed to update user account.';
                    }
                }
            }
        }

        // TOGGLE STATUS
        elseif ($action === 'toggle_status') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            $current_status = $_POST['current_status'] ?? '';

            // Prevent suspending admin accounts
            $check_stmt = $conn->prepare("SELECT role, first_name, last_name, email, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ?");
            $check_stmt->bind_param('i', $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows === 0) {
                $error = 'User not found.';
            } else {
                $target_user = $result->fetch_assoc();
                if ($target_user['role'] === 'admin') {
                    $error = 'Cannot modify administrator accounts.';
                } else {
                    $new_status = ($current_status === 'active') ? 'suspended' : 'active';
                    $update_stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_stmt->bind_param('si', $new_status, $user_id);

                    if ($update_stmt->execute()) {
                        $action_label = ($new_status === 'active') ? 'Activated' : 'Suspended';
                        logActivity("{$action_label} user: {$target_user['name']} (ID: {$user_id})", 'user_management');

                        // Send approval notification email when activating a pending account
                        if ($new_status === 'active' && $current_status === 'pending') {
                            require_once __DIR__ . '/../../includes/email.php';
                            sendApprovalNotification($target_user['email'], $target_user['first_name'], $target_user['role']);
                        }

                        $success = "User {$target_user['name']} {$action_label} successfully.";
                    } else {
                        $error = 'Failed to update user status.';
                    }
                }
            }
        }

        // RESET PASSWORD
        elseif ($action === 'reset_password') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            $new_password = $_POST['new_password'] ?? '';

            if (strlen($new_password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $check_stmt = $conn->prepare("SELECT role, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ?");
                $check_stmt->bind_param('i', $user_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();

                if ($result->num_rows === 0) {
                    $error = 'User not found.';
                } else {
                    $target_user = $result->fetch_assoc();
                    if ($target_user['role'] === 'admin') {
                        $error = 'Cannot reset administrator passwords from this interface.';
                    } else {
                        $password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                        $update_stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
                        $update_stmt->bind_param('si', $password_hash, $user_id);

                        if ($update_stmt->execute()) {
                            logActivity("Reset password for user: {$target_user['name']} (ID: {$user_id})", 'user_management');
                            $success = "Password reset successfully for {$target_user['name']}.";
                        } else {
                            $error = 'Failed to reset password.';
                        }
                    }
                }
            }
        }
    }
}

// Get all users with statistics
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

if (!empty($role_filter)) {
    $where_clauses[] = "role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT user_id, role, first_name, last_name, email, student_id, contact, department, program, year_level, specialization, academic_rank, is_reviewer, office, status, last_login, created_at FROM users {$where_sql} ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

// Get counts for statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0);
$total_students = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'] ?? 0);
$total_faculty = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'faculty'")->fetch_assoc()['count'] ?? 0);
$total_staff = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'research_staff'")->fetch_assoc()['count'] ?? 0);
$pending_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0);
$active_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'] ?? 0);
$visible_users = $users->num_rows;

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  .users-workspace {
    --ink: #182033;
    --navy: #172033;
    --navy-soft: #253149;
    --gold: #d3a348;
    --gold-pale: #f8edcf;
    --line: #dfe5ed;
    --muted: #667085;
    max-width: 1480px;
    margin: 0 auto;
    color: var(--ink);
  }

  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  .users-hero {
    position: relative;
    isolation: isolate;
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(300px, .7fr);
    gap: 48px;
    min-height: 330px;
    padding: 52px 56px 58px;
    overflow: hidden;
    border-radius: 24px 24px 8px 8px;
    background:
      radial-gradient(circle at 86% 10%, rgba(211, 163, 72, .2), transparent 27%),
      linear-gradient(135deg, #172033 0%, #1d2940 58%, #263149 100%);
    color: #fff;
    box-shadow: 0 26px 60px rgba(24, 32, 51, .17);
  }

  .users-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    opacity: .16;
    background-image: repeating-linear-gradient(90deg, transparent 0, transparent 67px, rgba(255,255,255,.1) 68px);
    pointer-events: none;
  }

  .users-kicker,
  .directory-eyebrow,
  .metric-index {
    font: 700 11px/1.2 ui-monospace, SFMono-Regular, Consolas, monospace;
    letter-spacing: .14em;
    text-transform: uppercase;
  }

  .users-kicker { margin-bottom: 18px; color: #e8bd67; }
  .users-hero h2 {
    max-width: 760px;
    margin: 0;
    font-size: clamp(38px, 4.6vw, 66px);
    line-height: .98;
    letter-spacing: -.055em;
    text-wrap: balance;
  }
  .users-hero-copy > p {
    max-width: 620px;
    margin: 24px 0 0;
    color: #bdc7d7;
    font-size: 15px;
    line-height: 1.75;
  }

  .hero-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
  .hero-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 18px;
    color: #9eabbf;
    font-size: 12px;
  }
  .hero-note::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #70bd91; box-shadow: 0 0 0 5px rgba(112,189,145,.12); }

  .registry-snapshot { align-self: end; display: grid; gap: 2px; }
  .snapshot-row {
    display: grid;
    grid-template-columns: 42px 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: rgba(255,255,255,.065);
    border: 1px solid rgba(255,255,255,.08);
  }
  .snapshot-row:first-child { border-radius: 14px 14px 5px 5px; }
  .snapshot-row:last-child { border-radius: 5px 5px 14px 14px; }
  .snapshot-code { color: #e8bd67; font: 700 11px/1 ui-monospace, SFMono-Regular, Consolas, monospace; }
  .snapshot-label { color: #d5dce8; font-size: 13px; }
  .snapshot-value { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.03em; }

  .metrics-grid {
    display: grid;
    grid-template-columns: 1.25fr repeat(4, 1fr);
    gap: 2px;
    margin: -22px 22px 0;
    position: relative;
    z-index: 2;
  }
  .metric-card {
    position: relative;
    min-height: 142px;
    padding: 24px 24px 21px;
    overflow: hidden;
    background: #fff;
    border: 1px solid #e3e8ef;
  }
  .metric-card:first-child { border-radius: 16px 5px 5px 16px; }
  .metric-card:last-child { border-radius: 5px 16px 16px 5px; }
  .metric-card::after { content: ''; position: absolute; right: -20px; bottom: -32px; width: 76px; height: 76px; border: 17px solid var(--metric-accent, #64748b); border-radius: 50%; opacity: .08; }
  .metric-card-total { --metric-accent: #172033; background: #fcfaf5; }
  .metric-card-student { --metric-accent: #315b8c; }
  .metric-card-faculty { --metric-accent: #705487; }
  .metric-card-staff { --metric-accent: #a2752e; }
  .metric-card-pending { --metric-accent: #b65f3a; }
  .metric-index { color: var(--metric-accent); margin-bottom: 24px; }
  .metric-value { font-size: 34px; font-weight: 720; line-height: 1; letter-spacing: -.04em; font-variant-numeric: tabular-nums; }
  .metric-label { margin-top: 8px; color: var(--muted); font-size: 13px; font-weight: 600; }

  .directory-card {
    margin-top: 38px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 14px 38px rgba(31, 42, 63, .07);
  }
  .directory-header {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 24px;
    padding: 30px 32px 24px;
  }
  .directory-eyebrow { margin-bottom: 9px; color: #9a7228; }
  .directory-title { margin: 0; font-size: 25px; line-height: 1.1; letter-spacing: -.025em; }
  .directory-meta { margin-top: 8px; color: var(--muted); font-size: 13px; }

  .filter-bar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 180px 180px auto auto;
    gap: 10px;
    align-items: center;
    padding: 14px 32px;
    border-top: 1px solid #edf0f4;
    border-bottom: 1px solid #e5e9ef;
    background: #f5f7fa;
  }
  .search-field { position: relative; }
  .search-field::before { content: '\2315'; position: absolute; left: 15px; top: 50%; transform: translateY(-52%) rotate(-20deg); color: #7b8798; font-size: 19px; pointer-events: none; }
  .search-input,
  .filter-select,
  .form-control {
    width: 100%;
    min-height: 44px;
    border: 1px solid #d9e0e8;
    border-radius: 9px;
    background: #fff;
    color: var(--ink);
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
  }
  .search-input { padding: 10px 14px 10px 43px; }
  .filter-select, .form-control { padding: 10px 13px; }
  .search-input:focus,
  .filter-select:focus,
  .form-control:focus { outline: none; border-color: #bc8e37; box-shadow: 0 0 0 3px rgba(211,163,72,.16); }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 9px 16px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 650;
    line-height: 1.2;
    text-decoration: none;
    cursor: pointer;
    transition: transform .2s ease, border-color .2s ease, background .2s ease, color .2s ease, box-shadow .2s ease;
  }
  .btn:hover { transform: translateY(-1px); }
  .btn:active { transform: translateY(0) scale(.98); }
  .btn:focus-visible { outline: 3px solid rgba(211,163,72,.28); outline-offset: 2px; }
  .btn-primary { border-color: #d3a348; background: #d3a348; color: #182033; box-shadow: 0 8px 20px rgba(211,163,72,.16); }
  .btn-primary:hover { background: #dfb45f; border-color: #dfb45f; box-shadow: 0 10px 24px rgba(211,163,72,.23); }
  .btn-secondary { border-color: #dce2e9; background: #fff; color: #344054; }
  .btn-secondary:hover { border-color: #b9c2ce; background: #f7f8fa; }
  .btn-sm { min-height: 34px; padding: 7px 10px; font-size: 12px; }
  .btn-danger { border-color: #f0d4cc; background: #fff8f6; color: #a7432f; }
  .btn-danger:hover { border-color: #d99a8b; background: #faebe7; }
  .btn-success { border-color: #cce4d6; background: #f3faf6; color: #27704a; }
  .btn-success:hover { border-color: #91c5a7; background: #e7f5ed; }
  .hero-action { min-height: 45px; padding-inline: 18px; }
  .hero-action-primary { background: #d3a348; color: #182033; }
  .hero-action-secondary { border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.055); color: #fff; }
  .hero-action-secondary:hover { border-color: #e8bd67; background: rgba(255,255,255,.1); }

  .table-wrap { overflow-x: auto; }
  .user-table { width: 100%; min-width: 1120px; border-collapse: collapse; table-layout: auto; }
  .user-table thead { background: #fafbfc; }
  .user-table th {
    padding: 12px 16px;
    color: #7a8494;
    font: 700 10px/1.2 ui-monospace, SFMono-Regular, Consolas, monospace;
    letter-spacing: .11em;
    text-align: left;
    text-transform: uppercase;
  }
  .user-table th:first-child,
  .user-table td:first-child { padding-left: 32px; }
  .user-table th:last-child,
  .user-table td:last-child { padding-right: 32px; }
  .user-table td { padding: 17px 16px; border-top: 1px solid #edf0f4; color: #475467; font-size: 13px; vertical-align: middle; }
  .user-table tbody tr { transition: background .2s ease; }
  .user-table tbody tr:hover { background: #fbfaf7; }
  .identity { display: flex; align-items: center; gap: 12px; min-width: 205px; }
  .identity-avatar { display: grid; place-items: center; width: 38px; height: 38px; flex: 0 0 38px; border-radius: 10px; background: #e9edf3; color: #344054; font-size: 12px; font-weight: 750; letter-spacing: .03em; }
  .identity-name { color: #20293b; font-weight: 680; line-height: 1.3; }
  .identity-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; color: #8a94a3; font-size: 11px; }
  .reviewer-mark { display: inline-flex; align-items: center; gap: 4px; color: #86611c; }
  .user-table th:nth-child(2),
  .user-table td:nth-child(2) { width: 250px; }
  .email-cell { min-width: 230px; line-height: 1.5; white-space: nowrap; }
  .id-cell, .date-cell { font-variant-numeric: tabular-nums; }
  .actions { display: flex; flex-wrap: wrap; gap: 6px; min-width: 255px; }
  .protected-note { color: #8b95a5; font-size: 12px; font-style: italic; }

  .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1px solid transparent; border-radius: 6px; font-size: 11px; font-weight: 680; white-space: nowrap; }
  .badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: .65; }
  .badge-student { background: #edf4fa; border-color: #d8e5f0; color: #315b8c; }
  .badge-faculty { background: #f5f0f7; border-color: #e7dcec; color: #705487; }
  .badge-staff { background: #faf4e8; border-color: #eadaba; color: #8b6528; }
  .badge-admin { background: #f7eeee; border-color: #ead8d8; color: #8b4a4a; }
  .badge-active { background: #edf8f1; border-color: #d4ebdc; color: #347451; }
  .badge-suspended { background: #f3f5f7; border-color: #e3e7eb; color: #6b7280; }
  .badge-pending { background: #fff5e8; border-color: #f0ddbf; color: #a46027; }

  .empty-state { padding: 58px 24px !important; text-align: center; }
  .empty-state strong { display: block; margin-bottom: 6px; color: #344054; font-size: 16px; }
  .empty-state span { color: #8490a1; }

  .alert { display: flex; align-items: center; gap: 12px; margin: 0 0 22px; padding: 14px 16px; border: 1px solid; border-radius: 10px; font-size: 14px; }
  .alert-success { border-color: #bcdcc9; background: #edf8f1; color: #2f6d4c; }
  .alert-error { border-color: #e8c5bc; background: #fff3f0; color: #9b3f2d; }
  .alert-icon { display: grid; place-items: center; width: 25px; height: 25px; border-radius: 7px; background: rgba(255,255,255,.65); font-weight: 800; }

  .modal-overlay { display: none; position: fixed; inset: 0; z-index: 1000; align-items: center; justify-content: center; padding: 24px; background: rgba(12,18,29,.68); backdrop-filter: blur(7px); }
  .modal-overlay.active { display: flex; animation: modal-fade .18s ease both; }
  .modal { width: min(100%, 640px); max-height: calc(100dvh - 48px); overflow-y: auto; padding: 0; border: 1px solid rgba(255,255,255,.6); border-radius: 18px; background: #fff; box-shadow: 0 30px 80px rgba(9,15,27,.3); animation: modal-rise .25s cubic-bezier(.2,.8,.2,1) both; }
  .modal-header { position: relative; margin: 0; padding: 28px 32px 24px; overflow: hidden; background: #182033; color: #fff; }
  .modal-header::after { content: ''; position: absolute; right: -42px; top: -72px; width: 170px; height: 170px; border: 30px solid rgba(211,163,72,.17); border-radius: 50%; }
  .modal-title { position: relative; z-index: 1; margin: 0 0 6px; font-size: 25px; font-weight: 720; letter-spacing: -.03em; }
  .modal-subtitle { position: relative; z-index: 1; color: #aeb9ca; font-size: 13px; }
  .modal form { padding: 28px 32px 32px; }
  .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 28px; padding-top: 22px; border-top: 1px solid #e8ecf1; }
  .form-group { margin-bottom: 18px; }
  .form-label { display: block; margin-bottom: 7px; color: #000; font-size: 12px; font-weight: 700; }
  .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-check { display: flex; align-items: center; gap: 9px; min-height: 42px; padding: 10px 12px; border: 1px solid #e0e5eb; border-radius: 9px; background: #f8f9fb; color: #000; font-size: 13px; font-weight: 600; }
  .form-check input { accent-color: #b7862f; }
  .form-hint { display: block; margin-top: 6px; color: #000; font-size: 11px; font-weight: 600; }
  .modal .form-control,
  .modal .form-control option { color: #000 !important; -webkit-text-fill-color: #000 !important; }
  .modal-footer .btn-secondary { border: 2px solid #182033; background: #fff; color: #000; font-weight: 700; }
  .modal-footer .btn-secondary:hover { border-color: #182033; background: #182033; color: #fff; }

  @keyframes modal-fade { from { opacity: 0; } to { opacity: 1; } }
  @keyframes modal-rise { from { opacity: 0; transform: translateY(12px) scale(.985); } to { opacity: 1; transform: none; } }

  @media (max-width: 1100px) {
    .users-hero { grid-template-columns: 1fr; gap: 32px; }
    .registry-snapshot { grid-template-columns: repeat(3, 1fr); }
    .snapshot-row { grid-template-columns: 34px 1fr; }
    .snapshot-value { grid-column: 2; }
    .metrics-grid { grid-template-columns: repeat(5, minmax(145px, 1fr)); overflow-x: auto; }
    .filter-bar { grid-template-columns: minmax(240px, 1fr) 150px 150px auto; }
    .filter-bar .clear-filter { grid-column: 1 / -1; justify-self: start; }
  }

  @media (max-width: 768px) {
    .users-hero { min-height: 0; padding: 30px 24px 50px; border-radius: 18px 18px 7px 7px; }
    .users-hero h2 { font-size: 38px; }
    .registry-snapshot { grid-template-columns: 1fr; }
    .snapshot-row { grid-template-columns: 36px 1fr auto; }
    .snapshot-value { grid-column: auto; }
    .metrics-grid { margin: -18px 12px 0; }
    .metric-card { min-width: 150px; }
    .directory-header { align-items: stretch; padding: 25px 20px 20px; flex-direction: column; }
    .directory-header .btn { width: 100%; }
    .filter-bar { grid-template-columns: 1fr; padding: 14px 20px 18px; }
    .filter-bar .clear-filter { grid-column: auto; width: 100%; }
    .filter-bar .btn { width: 100%; }
    .user-table { min-width: 0; table-layout: fixed; }
    .user-table thead { display: none; }
    .user-table tbody { display: grid; gap: 12px; padding: 16px; background: #f6f8fa; }
    .user-table tbody tr { display: block; overflow: hidden; border: 1px solid #e0e5eb; border-radius: 12px; background: #fff; }
    .user-table tbody td { display: grid; grid-template-columns: 92px minmax(0,1fr); width: 100%; padding: 11px 14px; border-top: 1px solid #edf0f4; text-align: left; overflow-wrap: anywhere; }
    .user-table tbody td:first-child { display: block; padding: 16px 14px; border-top: 0; }
    .user-table tbody td:last-child { padding: 14px; }
    .user-table tbody td::before { content: attr(data-label); margin: 2px 12px 0 0; color: #8a94a3; font: 700 9px/1.4 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .09em; text-transform: uppercase; }
    .user-table tbody td:first-child::before { display: none; }
    .actions { min-width: 0; }
    .email-cell { min-width: 0; white-space: normal; }
    .actions .btn { flex: 1 1 auto; }
    .empty-state { display: block !important; }
    .empty-state::before { display: none; }
    .form-grid-2 { grid-template-columns: 1fr; gap: 0; }
    .modal-overlay { padding: 12px; }
    .modal { max-height: calc(100dvh - 24px); }
    .modal-header { padding: 24px 22px 21px; }
    .modal form { padding: 24px 22px; }
  }

  @media (prefers-reduced-motion: reduce) {
    .btn, .user-table tbody tr { transition: none; }
    .modal-overlay.active, .modal { animation: none; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-users',
    'User Management',
    'Institutional access, account status, and role administration.'
);
?>

<div class="users-workspace">

    <?php if ($success): ?>
      <div class="alert alert-success">
        <span class="alert-icon" aria-hidden="true">&#10003;</span>
        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <span class="alert-icon" aria-hidden="true">&#215;</span>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <section class="users-hero" aria-labelledby="users-command-title">
      <div class="users-hero-copy">
        <div class="users-kicker">Access registry &middot; System administration</div>
        <h2 id="users-command-title">The people behind every research decision.</h2>
        <p>Manage institutional identities, faculty review access, and research staff assignments from one controlled directory.</p>
        <div class="hero-actions">
          <button type="button" class="btn hero-action hero-action-primary" onclick="openCreateModal()">Add faculty or staff <span aria-hidden="true">&#8594;</span></button>
          <a class="btn hero-action hero-action-secondary" href="#user-directory">Browse directory</a>
        </div>
        <div class="hero-note">Administrator accounts remain protected from status and password actions.</div>
      </div>

      <div class="registry-snapshot" aria-label="Account status snapshot">
        <div class="snapshot-row">
          <span class="snapshot-code">01</span>
          <span class="snapshot-label">Active access</span>
          <strong class="snapshot-value"><?php echo $active_users; ?></strong>
        </div>
        <div class="snapshot-row">
          <span class="snapshot-code">02</span>
          <span class="snapshot-label">Awaiting decision</span>
          <strong class="snapshot-value"><?php echo $pending_users; ?></strong>
        </div>
        <div class="snapshot-row">
          <span class="snapshot-code">03</span>
          <span class="snapshot-label">Directory total</span>
          <strong class="snapshot-value"><?php echo $total_users; ?></strong>
        </div>
      </div>
    </section>

    <section class="metrics-grid" aria-label="User totals by role">
      <article class="metric-card metric-card-total">
        <div class="metric-index">Registry</div>
        <div class="metric-value"><?php echo $total_users; ?></div>
        <div class="metric-label">Total accounts</div>
      </article>
      <article class="metric-card metric-card-student">
        <div class="metric-index">Students</div>
        <div class="metric-value"><?php echo $total_students; ?></div>
        <div class="metric-label">Proponents</div>
      </article>
      <article class="metric-card metric-card-faculty">
        <div class="metric-index">Faculty</div>
        <div class="metric-value"><?php echo $total_faculty; ?></div>
        <div class="metric-label">Advisers &amp; reviewers</div>
      </article>
      <article class="metric-card metric-card-staff">
        <div class="metric-index">Research staff</div>
        <div class="metric-value"><?php echo $total_staff; ?></div>
        <div class="metric-label">Office personnel</div>
      </article>
      <article class="metric-card metric-card-pending">
        <div class="metric-index">Attention</div>
        <div class="metric-value"><?php echo $pending_users; ?></div>
        <div class="metric-label">Pending approval</div>
      </article>
    </section>

    <section class="directory-card" id="user-directory" aria-labelledby="directory-title">
      <div class="directory-header">
        <div>
          <div class="directory-eyebrow">Institutional directory</div>
          <h3 class="directory-title" id="directory-title">User accounts</h3>
          <p class="directory-meta"><?php echo $visible_users; ?> record<?php echo $visible_users === 1 ? '' : 's'; ?> shown<?php echo (!empty($search) || !empty($role_filter) || !empty($status_filter)) ? ' for the current filters' : ''; ?>.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="openCreateModal()">Add faculty or staff</button>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-users.php" class="filter-bar">
        <div class="search-field">
          <label for="userSearch" class="sr-only">Search users</label>
          <input id="userSearch" type="search" name="search" class="search-input" placeholder="Search name, email, or ID" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label for="roleFilter" class="sr-only">Filter by role</label>
        <select id="roleFilter" name="role" class="filter-select" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
          <option value="faculty" <?php echo $role_filter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
          <option value="research_staff" <?php echo $role_filter === 'research_staff' ? 'selected' : ''; ?>>Research Staff</option>
          <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Administrators</option>
        </select>
        <label for="statusFilter" class="sr-only">Filter by status</label>
        <select id="statusFilter" name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
        </select>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
        <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter)): ?>
          <a href="admin-users.php" class="btn btn-secondary clear-filter">Clear filters</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table class="user-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>ID</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($users->num_rows > 0): ?>
              <?php while ($u = $users->fetch_assoc()): ?>
                <tr>
                  <?php
                    $person_name = trim($u['first_name'] . ' ' . $u['last_name']);
                    $person_initials = strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));
                  ?>
                  <td data-label="Name">
                    <div class="identity">
                      <span class="identity-avatar" aria-hidden="true"><?php echo htmlspecialchars($person_initials ?: 'U', ENT_QUOTES, 'UTF-8'); ?></span>
                      <div>
                        <div class="identity-name"><?php echo htmlspecialchars($person_name, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="identity-meta">
                          Account #<?php echo (int) $u['user_id']; ?>
                          <?php if ($u['is_reviewer'] == 1): ?>
                            <span class="reviewer-mark" title="CREC/EREC Reviewer">&bull; Committee reviewer</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td data-label="Email" class="email-cell"><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="ID" class="id-cell"><?php echo htmlspecialchars($u['student_id'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="Role">
                    <?php
                    $role_badges = [
                      'student' => 'badge-student',
                      'faculty' => 'badge-faculty',
                      'research_staff' => 'badge-staff',
                      'admin' => 'badge-admin'
                    ];
                    $role_labels = [
                      'student' => 'Student',
                      'faculty' => 'Faculty',
                      'research_staff' => 'Staff',
                      'admin' => 'Admin'
                    ];
                    $badge_class = $role_badges[$u['role']] ?? 'badge-student';
                    $role_label = $role_labels[$u['role']] ?? ucfirst($u['role']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $role_label; ?></span>
                  </td>
                  <td data-label="Status">
                    <?php
                    $status_badges = [
                      'active' => 'badge-active',
                      'suspended' => 'badge-suspended',
                      'pending' => 'badge-pending'
                    ];
                    $status_class = $status_badges[$u['status']] ?? 'badge-suspended';
                    ?>
                    <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($u['status']); ?></span>
                  </td>
                  <td data-label="Joined" class="date-cell"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                  <td data-label="Actions">
                    <?php if ($u['role'] !== 'admin'): ?>
                      <div class="actions">
                      <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?php echo json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>

                      <?php if ($u['status'] === 'active'): ?>
                        <button type="button" class="btn btn-sm btn-danger" onclick='confirmToggleStatus(<?php echo (int) $u['user_id']; ?>, "active", <?php echo json_encode($person_name, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Suspend</button>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm btn-success" onclick='confirmToggleStatus(<?php echo (int) $u['user_id']; ?>, <?php echo json_encode($u['status'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($person_name, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Activate</button>
                      <?php endif; ?>

                      <button type="button" class="btn btn-secondary btn-sm" onclick='openResetPasswordModal(<?php echo (int) $u['user_id']; ?>, <?php echo json_encode($person_name, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Reset password</button>
                      </div>
                    <?php else: ?>
                      <span class="protected-note">Protected administrator</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="empty-state">
                  <strong>No matching accounts</strong>
                  <span>Adjust the search or clear the active filters.</span>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
</div>

<!-- CREATE USER MODAL -->
<div class="modal-overlay" id="createModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="createModalTitle" tabindex="-1">
    <div class="modal-header">
      <div class="modal-title" id="createModalTitle">Create an account</div>
      <div class="modal-subtitle">Faculty or Research Staff only</div>
    </div>

    <form method="POST" action="admin-users.php" id="createForm">
      <input type="hidden" name="action" value="create_user">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label">Role *</label>
        <select name="role" id="createRole" class="form-control" required onchange="updateCreateFields()">
          <option value="">Select role</option>
          <option value="faculty">Faculty/Adviser</option>
          <option value="research_staff">Research Staff</option>
        </select>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">First Name *</label>
          <input type="text" name="first_name" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Last Name *</label>
          <input type="text" name="last_name" class="form-control" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Email *</label>
        <input type="email" name="email" class="form-control" required>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Employee ID</label>
          <input type="text" name="student_id" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="tel" name="contact" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Password *</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
        <small class="form-hint">Minimum 8 characters</small>
      </div>

      <!-- FACULTY FIELDS -->
      <div id="createFacultyFields" style="display: none;">
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" name="department" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Specialization</label>
          <input type="text" name="specialization" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Academic Rank</label>
          <select name="academic_rank" class="form-control">
            <option value="">Select rank</option>
            <option value="Instructor">Instructor</option>
            <option value="Assistant Professor">Assistant Professor</option>
            <option value="Associate Professor">Associate Professor</option>
            <option value="Professor">Professor</option>
            <option value="Dean">Dean</option>
            <option value="Director">Director</option>
          </select>
        </div>
        <div class="form-check">
          <input type="checkbox" name="is_reviewer" id="createReviewer" value="1">
          <label for="createReviewer">CREC/EREC Reviewer</label>
        </div>
      </div>

      <!-- STAFF FIELDS -->
      <div id="createStaffFields" style="display: none;">
        <div class="form-group">
          <label class="form-label">Office Assignment</label>
          <select name="office" class="form-control">
            <option value="">Select office</option>
            <option value="Office of Research Services">Office of Research Services (ORS)</option>
            <option value="CREC Office">CREC Office</option>
            <option value="EREC Office">EREC Office</option>
            <option value="Graduate School Office">Graduate School Office</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" name="department" class="form-control">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-overlay" id="editModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle" tabindex="-1">
    <div class="modal-header">
      <div class="modal-title" id="editModalTitle">Edit account</div>
      <div class="modal-subtitle">Update user information</div>
    </div>

    <form method="POST" action="admin-users.php" id="editForm">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="editUserId">
      <input type="hidden" name="role" id="editRole">
      <?php echo csrfField(); ?>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">First Name *</label>
          <input type="text" name="first_name" id="editFirstName" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Last Name *</label>
          <input type="text" name="last_name" id="editLastName" class="form-control" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Email *</label>
        <input type="email" name="email" id="editEmail" class="form-control" required>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Student/Employee ID</label>
          <input type="text" name="student_id" id="editStudentId" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="tel" name="contact" id="editContact" class="form-control">
        </div>
      </div>

      <!-- STUDENT FIELDS -->
      <div id="editStudentFields" style="display: none;">
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" name="department" id="editDepartmentStudent" class="form-control">
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Program</label>
            <input type="text" name="program" id="editProgram" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Year Level</label>
            <select name="year_level" id="editYearLevel" class="form-control">
              <option value="">Select year</option>
              <option value="1st">1st Year</option>
              <option value="2nd">2nd Year</option>
              <option value="3rd">3rd Year</option>
              <option value="4th">4th Year</option>
              <option value="Graduate">Graduate</option>
              <option value="Masters">Master's</option>
              <option value="Doctorate">Doctorate</option>
            </select>
          </div>
        </div>
      </div>

      <!-- FACULTY FIELDS -->
      <div id="editFacultyFields" style="display: none;">
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" name="department" id="editDepartmentFaculty" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Specialization</label>
          <input type="text" name="specialization" id="editSpecialization" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Academic Rank</label>
          <select name="academic_rank" id="editAcademicRank" class="form-control">
            <option value="">Select rank</option>
            <option value="Instructor">Instructor</option>
            <option value="Assistant Professor">Assistant Professor</option>
            <option value="Associate Professor">Associate Professor</option>
            <option value="Professor">Professor</option>
            <option value="Dean">Dean</option>
            <option value="Director">Director</option>
          </select>
        </div>
        <div class="form-check">
          <input type="checkbox" name="is_reviewer" id="editReviewer" value="1">
          <label for="editReviewer">CREC/EREC Reviewer</label>
        </div>
      </div>

      <!-- STAFF FIELDS -->
      <div id="editStaffFields" style="display: none;">
        <div class="form-group">
          <label class="form-label">Office Assignment</label>
          <select name="office" id="editOffice" class="form-control">
            <option value="">Select office</option>
            <option value="Office of Research Services">Office of Research Services (ORS)</option>
            <option value="CREC Office">CREC Office</option>
            <option value="EREC Office">EREC Office</option>
            <option value="Graduate School Office">Graduate School Office</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" name="department" id="editDepartmentStaff" class="form-control">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update User</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay" id="resetPasswordModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle" tabindex="-1">
    <div class="modal-header">
      <div class="modal-title" id="resetModalTitle">Reset password</div>
      <div class="modal-subtitle" id="resetPasswordName"></div>
    </div>

    <form method="POST" action="admin-users.php">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label">New Password *</label>
        <input type="password" name="new_password" class="form-control" minlength="8" required>
        <small class="form-hint">Minimum 8 characters</small>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<!-- TOGGLE STATUS FORM (Hidden) -->
<form method="POST" action="admin-users.php" id="toggleStatusForm" style="display: none;">
  <input type="hidden" name="action" value="toggle_status">
  <input type="hidden" name="user_id" id="toggleUserId">
  <input type="hidden" name="current_status" id="toggleCurrentStatus">
  <?php echo csrfField(); ?>
</form>

<script>
let lastFocusedElement = null;

function showModal(modalId) {
  const overlay = document.getElementById(modalId);
  if (!overlay) return;
  lastFocusedElement = document.activeElement;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  const dialog = overlay.querySelector('.modal');
  if (dialog) window.setTimeout(() => dialog.focus(), 0);
}

function hideModal(modalId) {
  const overlay = document.getElementById(modalId);
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  if (lastFocusedElement) lastFocusedElement.focus();
}

// CREATE MODAL
function openCreateModal() {
  showModal('createModal');
  document.getElementById('createForm').reset();
  updateCreateFields();
}

function closeCreateModal() {
  hideModal('createModal');
}

function updateCreateFields() {
  const role = document.getElementById('createRole').value;
  const facultyFields = document.getElementById('createFacultyFields');
  const staffFields = document.getElementById('createStaffFields');

  facultyFields.style.display = 'none';
  staffFields.style.display = 'none';

  if (role === 'faculty') {
    facultyFields.style.display = 'block';
  } else if (role === 'research_staff') {
    staffFields.style.display = 'block';
  }
}

// EDIT MODAL
function openEditModal(userData) {
  showModal('editModal');

  document.getElementById('editUserId').value = userData.user_id;
  document.getElementById('editRole').value = userData.role;
  document.getElementById('editFirstName').value = userData.first_name;
  document.getElementById('editLastName').value = userData.last_name;
  document.getElementById('editEmail').value = userData.email;
  document.getElementById('editStudentId').value = userData.student_id || '';
  document.getElementById('editContact').value = userData.contact || '';

  // Hide all role-specific fields
  document.getElementById('editStudentFields').style.display = 'none';
  document.getElementById('editFacultyFields').style.display = 'none';
  document.getElementById('editStaffFields').style.display = 'none';

  // Show relevant fields
  if (userData.role === 'student') {
    document.getElementById('editStudentFields').style.display = 'block';
    document.getElementById('editDepartmentStudent').value = userData.department || '';
    document.getElementById('editProgram').value = userData.program || '';
    document.getElementById('editYearLevel').value = userData.year_level || '';
  } else if (userData.role === 'faculty') {
    document.getElementById('editFacultyFields').style.display = 'block';
    document.getElementById('editDepartmentFaculty').value = userData.department || '';
    document.getElementById('editSpecialization').value = userData.specialization || '';
    document.getElementById('editAcademicRank').value = userData.academic_rank || '';
    document.getElementById('editReviewer').checked = userData.is_reviewer == 1;
  } else if (userData.role === 'research_staff') {
    document.getElementById('editStaffFields').style.display = 'block';
    document.getElementById('editOffice').value = userData.office || '';
    document.getElementById('editDepartmentStaff').value = userData.department || '';
  }
}

function closeEditModal() {
  hideModal('editModal');
}

// RESET PASSWORD MODAL
function openResetPasswordModal(userId, userName) {
  showModal('resetPasswordModal');
  document.getElementById('resetUserId').value = userId;
  document.getElementById('resetPasswordName').textContent = 'Reset password for ' + userName;
}

function closeResetPasswordModal() {
  hideModal('resetPasswordModal');
}

// TOGGLE STATUS
function confirmToggleStatus(userId, currentStatus, userName) {
  const action = (currentStatus === 'active') ? 'suspend' : 'activate';
  const message = `Are you sure you want to ${action} ${userName}?`;

  if (confirm(message)) {
    document.getElementById('toggleUserId').value = userId;
    document.getElementById('toggleCurrentStatus').value = currentStatus;
    document.getElementById('toggleStatusForm').submit();
  }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', function(e) {
    if (e.target === this) {
      hideModal(this.id);
    }
  });
});

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const openModal = document.querySelector('.modal-overlay.active');
  if (openModal) hideModal(openModal.id);
});
</script>

<?php
renderAdminShellClose();
