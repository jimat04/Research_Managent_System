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

        // CREATE DEPARTMENT
        if ($action === 'create_department') {
            $dept_code = strtoupper(trim($_POST['dept_code'] ?? ''));
            $dept_name = trim($_POST['dept_name'] ?? '');

            if ($dept_code === '' || $dept_name === '') {
                $error = 'Department code and name are required.';
            } elseif (strlen($dept_code) > 20) {
                $error = 'Department code must be 20 characters or fewer.';
            } else {
                // Check for duplicate code
                $check_stmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_code = ?");
                $check_stmt->bind_param('s', $dept_code);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'A department with this code already exists.';
                } else {
                    $status = 1;
                    $insert_stmt = $conn->prepare("INSERT INTO departments (dept_code, dept_name, status) VALUES (?, ?, ?)");
                    $insert_stmt->bind_param('ssi', $dept_code, $dept_name, $status);

                    if ($insert_stmt->execute()) {
                        logActivity("Created department: {$dept_code} - {$dept_name}", 'department_management');
                        $success = "Department {$dept_code} created successfully.";
                    } else {
                        $error = 'Failed to create department.';
                    }
                }
            }
        }

        // EDIT DEPARTMENT
        elseif ($action === 'edit_department') {
            $dept_id = (int) ($_POST['dept_id'] ?? 0);
            $dept_code = strtoupper(trim($_POST['dept_code'] ?? ''));
            $dept_name = trim($_POST['dept_name'] ?? '');

            if ($dept_id <= 0) {
                $error = 'Invalid department ID.';
            } elseif ($dept_code === '' || $dept_name === '') {
                $error = 'Department code and name are required.';
            } else {
                // Check for duplicate code on a different department
                $check_stmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_code = ? AND dept_id != ?");
                $check_stmt->bind_param('si', $dept_code, $dept_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'Another department already uses this code.';
                } else {
                    $update_stmt = $conn->prepare("UPDATE departments SET dept_code = ?, dept_name = ? WHERE dept_id = ?");
                    $update_stmt->bind_param('ssi', $dept_code, $dept_name, $dept_id);

                    if ($update_stmt->execute()) {
                        logActivity("Updated department: {$dept_code} (ID: {$dept_id})", 'department_management');
                        $success = "Department {$dept_code} updated successfully.";
                    } else {
                        $error = 'Failed to update department.';
                    }
                }
            }
        }

        // TOGGLE STATUS (soft delete / reactivate)
        elseif ($action === 'toggle_status') {
            $dept_id = (int) ($_POST['dept_id'] ?? 0);
            $current_status = (int) ($_POST['current_status'] ?? 0);

            $check_stmt = $conn->prepare("SELECT dept_code, dept_name FROM departments WHERE dept_id = ?");
            $check_stmt->bind_param('i', $dept_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows === 0) {
                $error = 'Department not found.';
            } else {
                $dept = $result->fetch_assoc();
                $new_status = $current_status === 1 ? 0 : 1;
                $update_stmt = $conn->prepare("UPDATE departments SET status = ? WHERE dept_id = ?");
                $update_stmt->bind_param('ii', $new_status, $dept_id);

                if ($update_stmt->execute()) {
                    $action_label = $new_status === 1 ? 'Activated' : 'Deactivated';
                    logActivity("{$action_label} department: {$dept['dept_code']} (ID: {$dept_id})", 'department_management');
                    $success = "Department {$dept['dept_code']} {$action_label} successfully.";
                } else {
                    $error = 'Failed to update department status.';
                }
            }
        }
    }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(dept_code LIKE ? OR dept_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

if ($status_filter !== '' && in_array($status_filter, ['0', '1'], true)) {
    $where_clauses[] = "status = ?";
    $params[] = (int) $status_filter;
    $types .= 'i';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT dept_id, dept_code, dept_name, status FROM departments {$where_sql} ORDER BY dept_code ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$departments = $stmt->get_result();

// Stats
$total_departments = (int) ($conn->query("SELECT COUNT(*) AS count FROM departments")->fetch_assoc()['count'] ?? 0);
$active_departments = (int) ($conn->query("SELECT COUNT(*) AS count FROM departments WHERE status = 1")->fetch_assoc()['count'] ?? 0);
$inactive_departments = (int) ($conn->query("SELECT COUNT(*) AS count FROM departments WHERE status = 0")->fetch_assoc()['count'] ?? 0);

// Count programs per department for the table
$program_counts = [];
$pc_result = $conn->query("SELECT dept_id, COUNT(*) AS count FROM programs WHERE status = 1 GROUP BY dept_id");
if ($pc_result) {
    while ($row = $pc_result->fetch_assoc()) {
        $program_counts[(int) $row['dept_id']] = (int) $row['count'];
    }
}
?>
<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }
  .stat-card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px;
  }
  .stat-number {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
    color: #111827;
  }
  .stat-label {
    font-size: 14px;
    color: #64748B;
    font-weight: 500;
  }
  .card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
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
    color: #111827;
  }
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
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #F8FAFC;
  }
  .filter-select {
    padding: 10px 16px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #FFFFFF;
    cursor: pointer;
  }
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
    background: #F57C00;
    color: white;
  }
  .btn-primary:hover {
    background: #EA580C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245,124,0,0.3);
  }
  .btn-secondary {
    background: #F8FAFC;
    color: #111827;
    border: 1px solid #E5E7EB;
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
  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  thead {
    background: #F8FAFC;
  }
  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
  }
  tr:hover {
    background: #F8FAFC;
  }
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }
  .badge-active { background: #DCFCE7; color: #16A34A; }
  .badge-inactive { background: #F1F5F9; color: #64748B; }
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
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }
  .modal-overlay.active { display: flex; }
  .modal {
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 32px;
  }
  .modal-header { margin-bottom: 24px; }
  .modal-title {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 8px;
  }
  .modal-subtitle {
    font-size: 14px;
    color: #64748B;
  }
  .modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
  }
  .form-group { margin-bottom: 20px; }
  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #111827;
  }
  .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
  }
  .form-control:focus {
    outline: none;
    border-color: #F57C00;
    box-shadow: 0 0 0 3px rgba(245,124,0,0.12);
  }
  .form-help {
    color: #94A3B8;
    font-size: 12px;
    margin-top: 4px;
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-departments',
    'Departments',
    'Manage academic departments across the institution.'
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

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_departments; ?></div>
        <div class="stat-label">Total Departments</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: #16A34A;"><?php echo $active_departments; ?></div>
        <div class="stat-label">Active</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: #94A3B8;"><?php echo $inactive_departments; ?></div>
        <div class="stat-label">Inactive</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Departments</div>
        <button class="btn btn-primary" onclick="openCreateModal()">+ Add Department</button>
      </div>

      <form method="GET" action="admin-departments.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
          <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || $status_filter !== ''): ?>
          <a href="admin-departments.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Department Name</th>
              <th>Programs</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($departments->num_rows > 0): ?>
              <?php while ($d = $departments->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight: 600;"><?php echo htmlspecialchars($d['dept_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($d['dept_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php $pc = $program_counts[(int) $d['dept_id']] ?? 0; ?>
                    <span style="color: <?php echo $pc > 0 ? '#111827' : '#94A3B8'; ?>;">
                      <?php echo $pc; ?> program<?php echo $pc === 1 ? '' : 's'; ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?php echo (int) $d['status'] === 1 ? 'badge-active' : 'badge-inactive'; ?>">
                      <?php echo (int) $d['status'] === 1 ? 'Active' : 'Inactive'; ?>
                    </span>
                  </td>
                  <td>
                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?php echo json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                    <?php if ((int) $d['status'] === 1): ?>
                      <button class="btn btn-sm btn-danger" onclick="confirmToggleStatus(<?php echo (int) $d['dept_id']; ?>, 1, '<?php echo htmlspecialchars($d['dept_code'], ENT_QUOTES, 'UTF-8'); ?>')">Deactivate</button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-success" onclick="confirmToggleStatus(<?php echo (int) $d['dept_id']; ?>, 0, '<?php echo htmlspecialchars($d['dept_code'], ENT_QUOTES, 'UTF-8'); ?>')">Activate</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 32px; color: #94A3B8;">
                  No departments found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<!-- CREATE DEPARTMENT MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add New Department</div>
      <div class="modal-subtitle">Create a new academic department.</div>
    </div>

    <form method="POST" action="admin-departments.php" id="createForm">
      <input type="hidden" name="action" value="create_department">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label" for="createCode">Department Code *</label>
        <input type="text" name="dept_code" id="createCode" class="form-control" maxlength="20" required placeholder="e.g. CCS">
        <div class="form-help">Short identifier (e.g. CCS, COE). Will be uppercased.</div>
      </div>

      <div class="form-group">
        <label class="form-label" for="createName">Department Name *</label>
        <input type="text" name="dept_name" id="createName" class="form-control" maxlength="150" required placeholder="e.g. College of Computer Studies">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Department</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT DEPARTMENT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Department</div>
      <div class="modal-subtitle">Update the department code or name.</div>
    </div>

    <form method="POST" action="admin-departments.php" id="editForm">
      <input type="hidden" name="action" value="edit_department">
      <input type="hidden" name="dept_id" id="editDeptId">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label" for="editCode">Department Code *</label>
        <input type="text" name="dept_code" id="editCode" class="form-control" maxlength="20" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="editName">Department Name *</label>
        <input type="text" name="dept_name" id="editName" class="form-control" maxlength="150" required>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- TOGGLE STATUS FORM (hidden) -->
<form method="POST" action="admin-departments.php" id="toggleStatusForm" style="display: none;">
  <input type="hidden" name="action" value="toggle_status">
  <input type="hidden" name="dept_id" id="toggleDeptId">
  <input type="hidden" name="current_status" id="toggleCurrentStatus">
  <?php echo csrfField(); ?>
</form>

<script>
function openCreateModal() {
  document.getElementById('createModal').classList.add('active');
  document.getElementById('createForm').reset();
}

function closeCreateModal() {
  document.getElementById('createModal').classList.remove('active');
}

function openEditModal(data) {
  document.getElementById('editModal').classList.add('active');
  document.getElementById('editDeptId').value = data.dept_id;
  document.getElementById('editCode').value = data.dept_code;
  document.getElementById('editName').value = data.dept_name;
}

function closeEditModal() {
  document.getElementById('editModal').classList.remove('active');
}

function confirmToggleStatus(deptId, currentStatus, code) {
  const action = currentStatus === 1 ? 'deactivate' : 'activate';
  const message = `Are you sure you want to ${action} department ${code}?`;
  if (confirm(message)) {
    document.getElementById('toggleDeptId').value = deptId;
    document.getElementById('toggleCurrentStatus').value = currentStatus;
    document.getElementById('toggleStatusForm').submit();
  }
}

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
