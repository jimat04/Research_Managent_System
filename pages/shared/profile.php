<?php
/**
 * Shared Profile page.
 *
 * Works for all roles (student, faculty, research_staff, admin).
 * Routes to the matching role shell so the sidebar/topbar match the
 * rest of the logged-in user's experience.
 *
 * Features:
 *   - Read-only profile view (name, email, role, role-specific fields)
 *   - Edit profile form (first_name, last_name, contact, role-specific fields)
 *   - Change password form (verify current, hash new, minimum 8 chars)
 *
 * All POST handling uses CSRF, prepared statements, and logActivity().
 * Email and role are NOT editable.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireLogin();

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user_id = (int) $user['user_id'];
$role    = (string) ($user['role'] ?? 'student');

// ---------------------------------------------------------------
// Defensive column detection — migration 002 added year_level,
// specialization, academic_rank, office, and the research_staff
// role value. If a particular install hasn't run that migration
// yet, we silently omit those columns from queries/forms instead
// of throwing "Unknown column" SQL errors.
//
// We query information_schema (which accepts bound parameters)
// rather than SHOW COLUMNS — MariaDB/MySQL do not support `?`
// placeholders in SHOW statements.
// ---------------------------------------------------------------
$migration_columns = ['year_level', 'specialization', 'academic_rank', 'office', 'is_reviewer'];
$has_column = array_fill_keys($migration_columns, false);
$col_check = $conn->prepare(
    "SELECT COLUMN_NAME AS c
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = ?"
);
if ($col_check) {
    foreach ($migration_columns as $col) {
        $col_check->bind_param('s', $col);
        $col_check->execute();
        $row = $col_check->get_result()->fetch_assoc();
        if ($row) {
            $has_column[$col] = true;
        }
    }
    $col_check->close();
}

// Load the full user row so we can show the migration-002 fields
// (getCurrentUser() predates that migration and doesn't SELECT them).
$user_columns = 'user_id, role, first_name, last_name, email, student_id, department, program, contact, status, created_at, updated_at';
if ($has_column['year_level'])     $user_columns .= ', year_level';
if ($has_column['specialization']) $user_columns .= ', specialization';
if ($has_column['academic_rank'])  $user_columns .= ', academic_rank';
if ($has_column['office'])         $user_columns .= ', office';

$user_stmt = $conn->prepare("SELECT $user_columns FROM users WHERE user_id = ? LIMIT 1");
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_row = $user_stmt->get_result()->fetch_assoc() ?: $user;
$user_stmt->close();

// ---------------------------------------------------------------
// POST handling — runs before any HTML output so we can redirect.
// Two distinct actions: update_profile, change_password.
// ---------------------------------------------------------------
$profile_errors  = [];
$password_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['module_error'] = 'Your form has expired. Please try again.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ---- Update profile ------------------------------------------------
    if ($action === 'update_profile') {
        $first_name = trim((string) ($_POST['first_name'] ?? ''));
        $last_name  = trim((string) ($_POST['last_name']  ?? ''));
        $contact    = trim((string) ($_POST['contact']    ?? ''));
        $student_id = trim((string) ($_POST['student_id'] ?? ''));

        // Role-specific optional fields.
        $year_level    = $has_column['year_level']     ? trim((string) ($_POST['year_level']    ?? '')) : null;
        $specialization = $has_column['specialization'] ? trim((string) ($_POST['specialization'] ?? '')) : null;
        $academic_rank = $has_column['academic_rank']  ? trim((string) ($_POST['academic_rank'] ?? '')) : null;
        $office        = $has_column['office']         ? trim((string) ($_POST['office']        ?? '')) : null;

        if ($first_name === '') $profile_errors[] = 'First name is required.';
        if ($last_name  === '') $profile_errors[] = 'Last name is required.';
        if (mb_strlen($first_name) > 80) $profile_errors[] = 'First name is too long (max 80 characters).';
        if (mb_strlen($last_name)  > 80) $profile_errors[] = 'Last name is too long (max 80 characters).';
        if (mb_strlen($contact) > 30)    $profile_errors[] = 'Contact number is too long (max 30 characters).';

        // Student: validate year_level against the ENUM (only if the column exists)
        $allowed_year_levels = ['1st', '2nd', '3rd', '4th', 'Graduate', 'Masters', 'Doctorate'];
        if ($has_column['year_level'] && $year_level !== '' && !in_array($year_level, $allowed_year_levels, true)) {
            $profile_errors[] = 'Invalid year level.';
        }
        $allowed_ranks = ['Instructor', 'Assistant Professor', 'Associate Professor', 'Professor', 'Dean', 'Director'];
        if ($has_column['academic_rank'] && $academic_rank !== '' && !in_array($academic_rank, $allowed_ranks, true)) {
            $profile_errors[] = 'Invalid academic rank.';
        }

        if (!$profile_errors) {
            // Build UPDATE dynamically so we only touch columns that exist.
            $sets   = ['first_name = ?', 'last_name = ?', 'contact = ?'];
            $types  = 'sss';
            $values = [$first_name, $last_name, $contact !== '' ? $contact : null];

            if ($has_column['year_level']) {
                $sets[]   = 'year_level = ?';
                $types   .= 's';
                $values[] = $year_level !== '' ? $year_level : null;
            }
            if ($has_column['specialization']) {
                $sets[]   = 'specialization = ?';
                $types   .= 's';
                $values[] = $specialization !== '' ? $specialization : null;
            }
            if ($has_column['academic_rank']) {
                $sets[]   = 'academic_rank = ?';
                $types   .= 's';
                $values[] = $academic_rank !== '' ? $academic_rank : null;
            }
            if ($has_column['office']) {
                $sets[]   = 'office = ?';
                $types   .= 's';
                $values[] = $office !== '' ? $office : null;
            }

            $types .= 'i';
            $values[] = $user_id;

            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?';
            $upd = $conn->prepare($sql);
            if (!$upd) {
                $profile_errors[] = 'Could not save your changes. Please try again.';
            } else {
                $upd->bind_param($types, ...$values);
                if ($upd->execute() && $upd->affected_rows >= 0) {
                    logActivity('Updated profile information', 'profile');
                    $_SESSION['module_success'] = 'Profile updated successfully.';
                } else {
                    $profile_errors[] = 'Could not save your changes. Please try again.';
                }
                $upd->close();
            }
        }

        if ($profile_errors) {
            $_SESSION['module_error'] = implode(' ', $profile_errors);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // ---- Change password ----------------------------------------------
    if ($action === 'change_password') {
        $current_password     = (string) ($_POST['current_password']     ?? '');
        $new_password         = (string) ($_POST['new_password']         ?? '');
        $confirm_password     = (string) ($_POST['confirm_password']     ?? '');

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $password_errors[] = 'All password fields are required.';
        } elseif (strlen($new_password) < 8) {
            $password_errors[] = 'New password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $password_errors[] = 'New password and confirmation do not match.';
        } else {
            // Verify the current password against the stored hash.
            $pw_stmt = $conn->prepare('SELECT password FROM users WHERE user_id = ? LIMIT 1');
            $pw_stmt->bind_param('i', $user_id);
            $pw_stmt->execute();
            $pw_row = $pw_stmt->get_result()->fetch_assoc();
            $pw_stmt->close();

            if (!$pw_row || !verifyPassword($current_password, (string) $pw_row['password'])) {
                $password_errors[] = 'Current password is incorrect.';
            } else {
                $new_hash = hashPassword($new_password);
                $upd = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                if ($upd) {
                    $upd->bind_param('si', $new_hash, $user_id);
                    if ($upd->execute()) {
                        logActivity('Changed account password', 'profile');
                        $_SESSION['module_success'] = 'Password updated successfully.';
                    } else {
                        $password_errors[] = 'Could not update the password. Please try again.';
                    }
                    $upd->close();
                } else {
                    $password_errors[] = 'Could not update the password. Please try again.';
                }
            }
        }

        if ($password_errors) {
            $_SESSION['module_error'] = implode(' ', $password_errors);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ---------------------------------------------------------------
// Flash messages (mirrors rms_message() in module-pages.php).
// ---------------------------------------------------------------
function profile_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $color = $type === 'error' ? '#ef4444' : '#22c55e';
        echo '<div style="margin-bottom:20px;padding:14px 18px;border-left:4px solid ' . $color .
             ';background:#fff;color:#334155;border-radius:10px;">' .
             htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

// ---------------------------------------------------------------
// Role-aware shell selection — mirrors module-page.php.
// ---------------------------------------------------------------
$page_title    = 'My Profile';
$page_subtitle = 'View and update your account information.';

if ($role === 'admin') {
    renderAdminShell($user_row, 'profile.php', $page_title, $page_subtitle);
} elseif ($role === 'research_staff') {
    renderStaffShell($user_row, 'profile.php', $page_title, $page_subtitle);
} elseif ($role === 'faculty') {
    renderFacultyShell($user_row, 'profile.php', $page_title, $page_subtitle);
} else {
    renderStudentShell($user_row, 'profile.php', $page_title, $page_subtitle);
}

// Display labels for the role badge.
$role_labels = [
    'student'        => '🎒 Student',
    'faculty'        => '🎓 Faculty Adviser',
    'research_staff' => '📋 Research Staff',
    'admin'          => '🛡️ Administrator',
];
$role_label = $role_labels[$role] ?? htmlspecialchars(ucwords(str_replace('_', ' ', $role)), ENT_QUOTES, 'UTF-8');

// Year-level options (matches the ENUM in migration 002).
$year_level_options = ['', '1st', '2nd', '3rd', '4th', 'Graduate', 'Masters', 'Doctorate'];
$rank_options = ['', 'Instructor', 'Assistant Professor', 'Associate Professor', 'Professor', 'Dean', 'Director'];

// Pre-fill values for the form (sticky on validation failure).
$form_first_name    = $user_row['first_name']    ?? '';
$form_last_name     = $user_row['last_name']     ?? '';
$form_contact       = $user_row['contact']       ?? '';
$form_student_id    = $user_row['student_id']    ?? '';
$form_year_level    = $user_row['year_level']    ?? '';
$form_specialization = $user_row['specialization'] ?? '';
$form_academic_rank = $user_row['academic_rank'] ?? '';
$form_office        = $user_row['office']        ?? '';

profile_flash('success');
profile_flash('error');
?>

<style>
  .profile-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
    max-width: 820px;
  }

  .profile-info-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #E5E7EB;
    font-size: 14px;
  }
  .profile-info-row:last-child { border-bottom: none; }
  .profile-info-label {
    color: #64748B;
    font-weight: 500;
  }
  .profile-info-value {
    color: #111827;
    font-weight: 500;
  }

  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .form-grid .full { grid-column: 1 / -1; }

  .form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
  }
  .form-group label {
    font-size: 13px;
    font-weight: 500;
    color: #111827;
  }
  .form-group input,
  .form-group select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #fff;
    color: #111827;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .form-group input:focus,
  .form-group select:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.15);
  }
  .form-group input[disabled] {
    background: #F8FAFC;
    color: #64748B;
    cursor: not-allowed;
  }
  .form-help {
    font-size: 12px;
    color: #94A3B8;
  }

  @media (max-width: 640px) {
    .profile-info-row { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
  }
</style>

<div class="profile-grid">

  <!-- ACCOUNT INFORMATION (read-only) -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Account Information</div>
        <div class="card-subtitle">Your basic account details. Email and role cannot be changed.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="profile-info-row">
        <div class="profile-info-label">Full name</div>
        <div class="profile-info-value">
          <?php echo htmlspecialchars(trim(($user_row['first_name'] ?? '') . ' ' . ($user_row['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
        </div>
      </div>
      <div class="profile-info-row">
        <div class="profile-info-label">Email</div>
        <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="profile-info-row">
        <div class="profile-info-label">Role</div>
        <div class="profile-info-value"><?php echo $role_label; ?></div>
      </div>
      <div class="profile-info-row">
        <div class="profile-info-label">Status</div>
        <div class="profile-info-value"><?php echo htmlspecialchars(ucfirst((string) ($user_row['status'] ?? 'active')), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <?php if ($role === 'student'): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Student ID</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['student_id'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="profile-info-row">
          <div class="profile-info-label">Program</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['program'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php if ($has_column['year_level']): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Year level</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['year_level'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
      <?php elseif ($role === 'faculty'): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Department</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['department'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php if ($has_column['specialization']): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Specialization</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['specialization'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($has_column['academic_rank']): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Academic rank</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['academic_rank'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
      <?php else: /* research_staff or admin */ ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Department</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['department'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php if ($has_column['office']): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Office</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['office'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="profile-info-row">
        <div class="profile-info-label">Member since</div>
        <div class="profile-info-value"><?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($user_row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </div>
  </div>

  <!-- EDIT PROFILE -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Edit Profile</div>
        <div class="card-subtitle">Update your name, contact details, and role-specific information.</div>
      </div>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="on">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="update_profile">

        <div class="form-grid">
          <div class="form-group">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" maxlength="80" required
                   value="<?php echo htmlspecialchars((string) $form_first_name, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="form-group">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" maxlength="80" required
                   value="<?php echo htmlspecialchars((string) $form_last_name, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" value="<?php echo htmlspecialchars((string) ($user_row['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" disabled>
            <span class="form-help">Email cannot be changed.</span>
          </div>
          <div class="form-group">
            <label for="contact">Contact number</label>
            <input type="text" id="contact" name="contact" maxlength="30"
                   value="<?php echo htmlspecialchars((string) $form_contact, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="e.g. +63 917 123 4567">
          </div>

          <?php if ($role === 'student'): ?>
            <?php if ($has_column['year_level']): ?>
            <div class="form-group">
              <label for="year_level">Year level</label>
              <select id="year_level" name="year_level">
                <?php foreach ($year_level_options as $opt): ?>
                  <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"
                          <?php echo (string) $form_year_level === $opt ? 'selected' : ''; ?>>
                    <?php echo $opt === '' ? '— Not set —' : htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          <?php elseif ($role === 'faculty'): ?>
            <?php if ($has_column['specialization']): ?>
            <div class="form-group full">
              <label for="specialization">Specialization</label>
              <input type="text" id="specialization" name="specialization" maxlength="120"
                     value="<?php echo htmlspecialchars((string) $form_specialization, ENT_QUOTES, 'UTF-8'); ?>"
                     placeholder="e.g. Machine Learning, Software Engineering">
            </div>
            <?php endif; ?>
            <?php if ($has_column['academic_rank']): ?>
            <div class="form-group full">
              <label for="academic_rank">Academic rank</label>
              <select id="academic_rank" name="academic_rank">
                <?php foreach ($rank_options as $opt): ?>
                  <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"
                          <?php echo (string) $form_academic_rank === $opt ? 'selected' : ''; ?>>
                    <?php echo $opt === '' ? '— Not set —' : htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          <?php else: /* research_staff / admin */ ?>
            <?php if ($has_column['office']): ?>
            <div class="form-group full">
              <label for="office">Office</label>
              <input type="text" id="office" name="office" maxlength="120"
                     value="<?php echo htmlspecialchars((string) $form_office, ENT_QUOTES, 'UTF-8'); ?>"
                     placeholder="e.g. Research Office, Dean's Office">
            </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:12px; margin-top:8px;">
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- CHANGE PASSWORD -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Change Password</div>
        <div class="card-subtitle">Choose a new password with at least 8 characters.</div>
      </div>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="change_password">

        <div class="form-group">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
            <span class="form-help">Minimum 8 characters.</span>
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
          </div>
        </div>

        <div style="display:flex; gap:12px; margin-top:8px;">
          <button type="submit" class="btn btn-primary">Update password</button>
        </div>
      </form>
    </div>
  </div>

</div>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} elseif ($role === 'research_staff') {
    renderStaffShellClose();
} elseif ($role === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
