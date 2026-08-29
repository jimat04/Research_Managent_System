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

            // Prevent deactivating admin accounts
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
                    $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                    $update_stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?");
                    $update_stmt->bind_param('si', $new_status, $user_id);

                    if ($update_stmt->execute()) {
                        $action_label = ($new_status === 'active') ? 'Activated' : 'Deactivated';
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

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }

  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 16px;
    padding: 20px;
  }

  .stat-number {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }

  .stat-label {
    font-size: 14px;
    color: var(--text-secondary, #64748B);
    font-weight: 500;
  }

  /* CARD */
  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
  }

  .card-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--charcoal, #111827);
  }

  /* FILTER BAR */
  .filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 24px;
  }

  .search-input {
    flex: 1;
    min-width: 250px;
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-surface, #F8FAFC);
  }

  .filter-select {
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-card, #FFFFFF);
    cursor: pointer;
  }

  /* BUTTON */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
  }

  .btn-primary {
    background: var(--gold, #C8A44D);
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(200,164,77,0.3);
  }

  .btn-secondary {
    background: var(--bg-surface, #F8FAFC);
    color: var(--text-primary, #111827);
    border: 1px solid var(--border, #E5E7EB);
  }

  .btn-secondary:hover {
    background: #E5E7EB;
  }

  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
  }

  .btn-danger {
    background: #EF4444;
    color: white;
  }

  .btn-success {
    background: #16A34A;
    color: white;
  }

  /* TABLE */
  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border, #E5E7EB);
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead {
    background: var(--bg-surface, #F8FAFC);
  }

  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748B);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid var(--border, #E5E7EB);
  }

  tr:hover {
    background: var(--bg-surface, #F8FAFC);
  }

  /* BADGE */
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .badge-student { background: #DBEAFE; color: #2563EB; }
  .badge-faculty { background: #F3E8FF; color: #7C3AED; }
  .badge-staff { background: #FEF3C7; color: #D97706; }
  .badge-admin { background: #FEE2E2; color: #DC2626; }

  .badge-active { background: #DCFCE7; color: #16A34A; }
  .badge-inactive { background: #F1F5F9; color: #64748B; }
  .badge-pending { background: #FEF3C7; color: #EA580C; }

  /* ALERT */
  .alert {
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .alert-success {
    background: #DCFCE7;
    color: #16A34A;
    border: 1px solid #86EFAC;
  }

  .alert-error {
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
  }

  /* MODAL OVERLAY */
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }

  .modal-overlay.active {
    display: flex;
  }

  .modal {
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 32px;
  }

  .modal-header {
    margin-bottom: 24px;
  }

  .modal-title {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 8px;
  }

  .modal-subtitle {
    font-size: 14px;
    color: var(--text-secondary, #64748B);
  }

  .modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
  }

  /* FORM */
  .form-group {
    margin-bottom: 20px;
  }

  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-primary, #111827);
  }

  .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--gold, #C8A44D);
    box-shadow: 0 0 0 3px rgba(200,164,77,0.1);
  }

  .form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  .form-check {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  @media (max-width: 768px) {
    .form-grid-2 {
      grid-template-columns: 1fr;
    }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-users',
    'User Management',
    'Create, edit, and manage user accounts across all roles.'
);
?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <span>✓</span>
        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <span>✕</span>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_users; ?></div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_students; ?></div>
        <div class="stat-label">Students</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_faculty; ?></div>
        <div class="stat-label">Faculty</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_staff; ?></div>
        <div class="stat-label">Research Staff</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: #EA580C;"><?php echo $pending_users; ?></div>
        <div class="stat-label">Pending Approval</div>
      </div>
    </div>

    <!-- USER TABLE CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Users</div>
        <button class="btn btn-primary" onclick="openCreateModal()">+ Create Faculty/Staff</button>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-users.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by name, email, or ID..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="role" class="filter-select" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
          <option value="faculty" <?php echo $role_filter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
          <option value="research_staff" <?php echo $role_filter === 'research_staff' ? 'selected' : ''; ?>>Research Staff</option>
          <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Administrators</option>
        </select>
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter)): ?>
          <a href="admin-users.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table>
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
                  <td style="font-weight: 500;">
                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($u['is_reviewer'] == 1): ?>
                      <span title="CREC/EREC Reviewer" style="font-size: 12px;">⭐</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($u['student_id'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
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
                  <td>
                    <?php
                    $status_badges = [
                      'active' => 'badge-active',
                      'inactive' => 'badge-inactive',
                      'pending' => 'badge-pending'
                    ];
                    $status_class = $status_badges[$u['status']] ?? 'badge-inactive';
                    ?>
                    <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($u['status']); ?></span>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                  <td>
                    <?php if ($u['role'] !== 'admin'): ?>
                      <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?php echo json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>

                      <?php if ($u['status'] === 'active'): ?>
                        <button class="btn btn-sm btn-danger" onclick="confirmToggleStatus(<?php echo $u['user_id']; ?>, 'active', '<?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>')">Deactivate</button>
                      <?php else: ?>
                        <button class="btn btn-sm btn-success" onclick="confirmToggleStatus(<?php echo $u['user_id']; ?>, '<?php echo $u['status']; ?>', '<?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>')">Activate</button>
                      <?php endif; ?>

                      <button class="btn btn-secondary btn-sm" onclick="openResetPasswordModal(<?php echo $u['user_id']; ?>, '<?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>')">Reset Password</button>
                    <?php else: ?>
                      <span style="color: var(--text-muted, #94A3B8); font-size: 13px;">Protected</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-muted, #94A3B8);">
                  No users found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<!-- CREATE USER MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Create New User</div>
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
        <small style="color: var(--text-muted, #94A3B8); font-size: 12px;">Minimum 8 characters</small>
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
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit User</div>
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
<div class="modal-overlay" id="resetPasswordModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Reset Password</div>
      <div class="modal-subtitle" id="resetPasswordName"></div>
    </div>

    <form method="POST" action="admin-users.php">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label">New Password *</label>
        <input type="password" name="new_password" class="form-control" minlength="8" required>
        <small style="color: var(--text-muted, #94A3B8); font-size: 12px;">Minimum 8 characters</small>
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
// CREATE MODAL
function openCreateModal() {
  document.getElementById('createModal').classList.add('active');
  document.getElementById('createForm').reset();
  updateCreateFields();
}

function closeCreateModal() {
  document.getElementById('createModal').classList.remove('active');
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
  document.getElementById('editModal').classList.add('active');

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
  document.getElementById('editModal').classList.remove('active');
}

// RESET PASSWORD MODAL
function openResetPasswordModal(userId, userName) {
  document.getElementById('resetPasswordModal').classList.add('active');
  document.getElementById('resetUserId').value = userId;
  document.getElementById('resetPasswordName').textContent = 'Reset password for ' + userName;
}

function closeResetPasswordModal() {
  document.getElementById('resetPasswordModal').classList.remove('active');
}

// TOGGLE STATUS
function confirmToggleStatus(userId, currentStatus, userName) {
  const action = (currentStatus === 'active') ? 'deactivate' : 'activate';
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
      this.classList.remove('active');
    }
  });
});
</script>

<?php
renderAdminShellClose();
