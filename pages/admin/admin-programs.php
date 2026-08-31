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

        // CREATE PROGRAM
        if ($action === 'create_program') {
            $dept_id = (int) ($_POST['dept_id'] ?? 0);
            $program_code = strtoupper(trim($_POST['program_code'] ?? ''));
            $program_name = trim($_POST['program_name'] ?? '');

            if ($dept_id <= 0) {
                $error = 'Please select a department.';
            } elseif ($program_code === '' || $program_name === '') {
                $error = 'Program code and name are required.';
            } else {
                // Confirm the department exists and is active
                $dept_check = $conn->prepare("SELECT dept_id FROM departments WHERE dept_id = ?");
                $dept_check->bind_param('i', $dept_id);
                $dept_check->execute();
                if ($dept_check->get_result()->num_rows === 0) {
                    $error = 'Selected department does not exist.';
                } else {
                    // Check for duplicate program code (within the same department)
                    $check_stmt = $conn->prepare("SELECT program_id FROM programs WHERE program_code = ? AND dept_id = ?");
                    $check_stmt->bind_param('si', $program_code, $dept_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        $error = 'A program with this code already exists in the selected department.';
                    } else {
                        $status = 1;
                        $insert_stmt = $conn->prepare("INSERT INTO programs (dept_id, program_code, program_name, status) VALUES (?, ?, ?, ?)");
                        $insert_stmt->bind_param('issi', $dept_id, $program_code, $program_name, $status);

                        if ($insert_stmt->execute()) {
                            logActivity("Created program: {$program_code} - {$program_name} (dept_id: {$dept_id})", 'program_management');
                            $success = "Program {$program_code} created successfully.";
                        } else {
                            $error = 'Failed to create program.';
                        }
                    }
                }
            }
        }

        // EDIT PROGRAM
        elseif ($action === 'edit_program') {
            $program_id = (int) ($_POST['program_id'] ?? 0);
            $dept_id = (int) ($_POST['dept_id'] ?? 0);
            $program_code = strtoupper(trim($_POST['program_code'] ?? ''));
            $program_name = trim($_POST['program_name'] ?? '');

            if ($program_id <= 0 || $dept_id <= 0) {
                $error = 'Invalid program or department ID.';
            } elseif ($program_code === '' || $program_name === '') {
                $error = 'Program code and name are required.';
            } else {
                $dept_check = $conn->prepare("SELECT dept_id FROM departments WHERE dept_id = ?");
                $dept_check->bind_param('i', $dept_id);
                $dept_check->execute();
                if ($dept_check->get_result()->num_rows === 0) {
                    $error = 'Selected department does not exist.';
                } else {
                    // Check for duplicate program code on a different program (within the same dept)
                    $check_stmt = $conn->prepare("SELECT program_id FROM programs WHERE program_code = ? AND dept_id = ? AND program_id != ?");
                    $check_stmt->bind_param('sii', $program_code, $dept_id, $program_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        $error = 'Another program already uses this code in the selected department.';
                    } else {
                        $update_stmt = $conn->prepare("UPDATE programs SET dept_id = ?, program_code = ?, program_name = ? WHERE program_id = ?");
                        $update_stmt->bind_param('issi', $dept_id, $program_code, $program_name, $program_id);

                        if ($update_stmt->execute()) {
                            logActivity("Updated program: {$program_code} (ID: {$program_id})", 'program_management');
                            $success = "Program {$program_code} updated successfully.";
                        } else {
                            $error = 'Failed to update program.';
                        }
                    }
                }
            }
        }

        // TOGGLE STATUS (soft delete / reactivate)
        elseif ($action === 'toggle_status') {
            $program_id = (int) ($_POST['program_id'] ?? 0);
            $current_status = (int) ($_POST['current_status'] ?? 0);

            $check_stmt = $conn->prepare("SELECT program_code FROM programs WHERE program_id = ?");
            $check_stmt->bind_param('i', $program_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows === 0) {
                $error = 'Program not found.';
            } else {
                $prog = $result->fetch_assoc();
                $new_status = $current_status === 1 ? 0 : 1;
                $update_stmt = $conn->prepare("UPDATE programs SET status = ? WHERE program_id = ?");
                $update_stmt->bind_param('ii', $new_status, $program_id);

                if ($update_stmt->execute()) {
                    $action_label = $new_status === 1 ? 'Activated' : 'Deactivated';
                    logActivity("{$action_label} program: {$prog['program_code']} (ID: {$program_id})", 'program_management');
                    $success = "Program {$prog['program_code']} {$action_label} successfully.";
                } else {
                    $error = 'Failed to update program status.';
                }
            }
        }
    }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dept_filter = isset($_GET['dept_id']) ? (int) $_GET['dept_id'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(p.program_code LIKE ? OR p.program_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

if ($dept_filter > 0) {
    $where_clauses[] = "p.dept_id = ?";
    $params[] = $dept_filter;
    $types .= 'i';
}

if ($status_filter !== '' && in_array($status_filter, ['0', '1'], true)) {
    $where_clauses[] = "p.status = ?";
    $params[] = (int) $status_filter;
    $types .= 'i';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Join departments to get the code/name for display
$query = "SELECT p.program_id, p.dept_id, p.program_code, p.program_name, p.status, d.dept_code, d.dept_name FROM programs p INNER JOIN departments d ON p.dept_id = d.dept_id {$where_sql} ORDER BY d.dept_code ASC, p.program_code ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$programs = $stmt->get_result();

// All departments (for filter dropdown and modal select)
$all_departments = $conn->query("SELECT dept_id, dept_code, dept_name, status FROM departments ORDER BY dept_code ASC");

// Stats
$total_programs = (int) ($conn->query("SELECT COUNT(*) AS count FROM programs")->fetch_assoc()['count'] ?? 0);
$active_programs = (int) ($conn->query("SELECT COUNT(*) AS count FROM programs WHERE status = 1")->fetch_assoc()['count'] ?? 0);
$inactive_programs = (int) ($conn->query("SELECT COUNT(*) AS count FROM programs WHERE status = 0")->fetch_assoc()['count'] ?? 0);
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
  .badge-dept {
    background: rgba(245,124,0,0.08);
    color: #EA580C;
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
  }
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
    'admin-programs',
    'Programs',
    'Manage academic programs offered by each department.'
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
        <div class="stat-number"><?php echo $total_programs; ?></div>
        <div class="stat-label">Total Programs</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: #16A34A;"><?php echo $active_programs; ?></div>
        <div class="stat-label">Active</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: #94A3B8;"><?php echo $inactive_programs; ?></div>
        <div class="stat-label">Inactive</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Programs</div>
        <button class="btn btn-primary" onclick="openCreateModal()">+ Add Program</button>
      </div>

      <form method="GET" action="admin-programs.php" class="filter-bar">
        <input type="text" name="search" class="search-input" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="dept_id" class="filter-select" onchange="this.form.submit()">
          <option value="0">All Departments</option>
          <?php
          // Rewind for filter dropdown
          $all_departments->data_seek(0);
          while ($dd = $all_departments->fetch_assoc()): ?>
            <option value="<?php echo (int) $dd['dept_id']; ?>" <?php echo $dept_filter === (int) $dd['dept_id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($dd['dept_code'] . ' — ' . $dd['dept_name'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endwhile; ?>
        </select>
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
          <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if (!empty($search) || $dept_filter > 0 || $status_filter !== ''): ?>
          <a href="admin-programs.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Program Name</th>
              <th>Department</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($programs->num_rows > 0): ?>
              <?php while ($p = $programs->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight: 600;"><?php echo htmlspecialchars($p['program_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo htmlspecialchars($p['program_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <span class="badge badge-dept"><?php echo htmlspecialchars($p['dept_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="color: #64748B; font-size: 13px; margin-left: 6px;">
                      <?php echo htmlspecialchars($p['dept_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?php echo (int) $p['status'] === 1 ? 'badge-active' : 'badge-inactive'; ?>">
                      <?php echo (int) $p['status'] === 1 ? 'Active' : 'Inactive'; ?>
                    </span>
                  </td>
                  <td>
                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                    <?php if ((int) $p['status'] === 1): ?>
                      <button class="btn btn-sm btn-danger" onclick="confirmToggleStatus(<?php echo (int) $p['program_id']; ?>, 1, '<?php echo htmlspecialchars($p['program_code'], ENT_QUOTES, 'UTF-8'); ?>')">Deactivate</button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-success" onclick="confirmToggleStatus(<?php echo (int) $p['program_id']; ?>, 0, '<?php echo htmlspecialchars($p['program_code'], ENT_QUOTES, 'UTF-8'); ?>')">Activate</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 32px; color: #94A3B8;">
                  No programs found matching your filters.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<!-- CREATE PROGRAM MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add New Program</div>
      <div class="modal-subtitle">Create a new academic program under a department.</div>
    </div>

    <form method="POST" action="admin-programs.php" id="createForm">
      <input type="hidden" name="action" value="create_program">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label" for="createDept">Department *</label>
        <select name="dept_id" id="createDept" class="form-control" required>
          <option value="">Select a department</option>
          <?php $all_departments->data_seek(0); while ($dd = $all_departments->fetch_assoc()): ?>
            <option value="<?php echo (int) $dd['dept_id']; ?>" <?php echo (int) $dd['status'] === 0 ? 'disabled' : ''; ?>>
              <?php echo htmlspecialchars($dd['dept_code'] . ' — ' . $dd['dept_name'], ENT_QUOTES, 'UTF-8'); ?>
              <?php echo (int) $dd['status'] === 0 ? '(inactive)' : ''; ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="createCode">Program Code *</label>
        <input type="text" name="program_code" id="createCode" class="form-control" maxlength="20" required placeholder="e.g. BSIT">
        <div class="form-help">Short identifier (e.g. BSIT, BSCS). Will be uppercased.</div>
      </div>

      <div class="form-group">
        <label class="form-label" for="createName">Program Name *</label>
        <input type="text" name="program_name" id="createName" class="form-control" maxlength="150" required placeholder="e.g. Bachelor of Science in Information Technology">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Program</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT PROGRAM MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Program</div>
      <div class="modal-subtitle">Update the program details or change its parent department.</div>
    </div>

    <form method="POST" action="admin-programs.php" id="editForm">
      <input type="hidden" name="action" value="edit_program">
      <input type="hidden" name="program_id" id="editProgramId">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label" for="editDept">Department *</label>
        <select name="dept_id" id="editDept" class="form-control" required>
          <option value="">Select a department</option>
          <?php $all_departments->data_seek(0); while ($dd = $all_departments->fetch_assoc()): ?>
            <option value="<?php echo (int) $dd['dept_id']; ?>">
              <?php echo htmlspecialchars($dd['dept_code'] . ' — ' . $dd['dept_name'], ENT_QUOTES, 'UTF-8'); ?>
              <?php echo (int) $dd['status'] === 0 ? '(inactive)' : ''; ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="editCode">Program Code *</label>
        <input type="text" name="program_code" id="editCode" class="form-control" maxlength="20" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="editName">Program Name *</label>
        <input type="text" name="program_name" id="editName" class="form-control" maxlength="150" required>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- TOGGLE STATUS FORM (hidden) -->
<form method="POST" action="admin-programs.php" id="toggleStatusForm" style="display: none;">
  <input type="hidden" name="action" value="toggle_status">
  <input type="hidden" name="program_id" id="toggleProgramId">
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
  document.getElementById('editProgramId').value = data.program_id;
  document.getElementById('editDept').value = data.dept_id;
  document.getElementById('editCode').value = data.program_code;
  document.getElementById('editName').value = data.program_name;
}

function closeEditModal() {
  document.getElementById('editModal').classList.remove('active');
}

function confirmToggleStatus(programId, currentStatus, code) {
  const action = currentStatus === 1 ? 'deactivate' : 'activate';
  const message = `Are you sure you want to ${action} program ${code}?`;
  if (confirm(message)) {
    document.getElementById('toggleProgramId').value = programId;
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
