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
require_once __DIR__ . '/../../includes/faculty-ranks.php';
require_once __DIR__ . '/../../includes/academic-affiliation.php';
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
$academic_affiliation_roles = ['admin', 'research_staff', 'faculty'];
$academic_affiliation_enabled = in_array($role, $academic_affiliation_roles, true)
    && rmsAcademicAffiliationTablesAvailable($conn);
$academic_affiliation_catalog = $academic_affiliation_enabled
    ? rmsAcademicAffiliationCatalog($conn)
    : [];
$academic_affiliations = $academic_affiliation_enabled
    ? rmsAcademicAffiliationLoad($conn, $user_id)
    : [];
$academic_affiliation_summary = $academic_affiliation_enabled
    ? rmsAcademicAffiliationDisplay($conn, $user_id)
    : [];

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
$migration_columns = ['year_level', 'specialization', 'academic_rank', 'office', 'is_reviewer', 'middle_name', 'designation', 'appointment_status'];
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
if ($has_column['middle_name'])       $user_columns .= ', middle_name';
if ($has_column['designation'])       $user_columns .= ', designation';
if ($has_column['appointment_status']) $user_columns .= ', appointment_status';

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
        $academic_rank = $has_column['academic_rank'] ? ($_POST['academic_rank'] ?? '') : null;
        $office        = $has_column['office']         ? trim((string) ($_POST['office']        ?? '')) : null;
        $middle_name = $role === 'faculty' && $has_column['middle_name'] ? trim((string) ($_POST['middle_name'] ?? '')) : null;
        $designation = in_array($role, $academic_affiliation_roles, true) && $has_column['designation'] ? trim((string) ($_POST['designation'] ?? '')) : null;
        $appointment_status = $role === 'faculty' && $has_column['appointment_status'] ? trim((string) ($_POST['appointment_status'] ?? '')) : null;

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
        if ($has_column['academic_rank'] && !rmsFacultyAcademicRankIsValid($academic_rank, $user_row['academic_rank'] ?? null)) {
            $profile_errors[] = 'Invalid academic rank.';
        }
        if ($role === 'faculty' && $has_column['middle_name'] && mb_strlen($middle_name) > 80) {
            $profile_errors[] = 'Middle name or initial is too long (max 80 characters).';
        }
        if (in_array($role, $academic_affiliation_roles, true) && $has_column['designation'] && mb_strlen($designation) > 150) {
            $profile_errors[] = 'Designation is too long (max 150 characters).';
        }
        if ($academic_affiliation_enabled && (!isset($_POST['academic_affiliations_submitted'])
            || !is_array($_POST['academic_affiliations'] ?? []))) {
            $profile_errors[] = 'Submit valid academic affiliation selections.';
        }
        if ($role === 'faculty' && $has_column['appointment_status'] && mb_strlen($appointment_status) > 80) {
            $profile_errors[] = 'Appointment status is too long (max 80 characters).';
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
            if ($role === 'faculty' && $has_column['middle_name']) {
                $sets[] = 'middle_name = ?';
                $types .= 's';
                $values[] = $middle_name !== '' ? $middle_name : null;
            }
            if (in_array($role, $academic_affiliation_roles, true) && $has_column['designation']) {
                $sets[] = 'designation = ?';
                $types .= 's';
                $values[] = $designation !== '' ? $designation : null;
            }
            if ($role === 'faculty' && $has_column['appointment_status']) {
                $sets[] = 'appointment_status = ?';
                $types .= 's';
                $values[] = $appointment_status !== '' ? $appointment_status : null;
            }

            $types .= 'i';
            $values[] = $user_id;

            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?';
            $transactionStarted = !$academic_affiliation_enabled || $conn->begin_transaction();
            $upd = $transactionStarted ? $conn->prepare($sql) : false;
            if (!$transactionStarted) {
                $profile_errors[] = 'Could not begin the profile update. Please try again.';
            } elseif (!$upd) {
                $profile_errors[] = 'Could not save your changes. Please try again.';
            } else {
                $upd->bind_param($types, ...$values);
                $updated = $upd->execute() && $upd->affected_rows >= 0;
                $affiliationsSaved = !$academic_affiliation_enabled || ($updated && rmsAcademicAffiliationSave(
                    $conn,
                    $user_id,
                    $_POST['academic_affiliations'] ?? [],
                    $role === 'faculty'
                ));
                $committed = $updated && $affiliationsSaved
                    && (!$academic_affiliation_enabled || $conn->commit());
                if ($committed) {
                    logActivity('Updated profile information', 'profile');
                    $_SESSION['module_success'] = 'Profile updated successfully.';
                } else {
                    if ($academic_affiliation_enabled) {
                        $conn->rollback();
                    }
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
        } elseif (($passwordPolicyError = rmsValidatePasswordPolicy($new_password)) !== null) {
            $password_errors[] = $passwordPolicyError;
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
// Flash messages.
// ---------------------------------------------------------------
function profile_flash($type) {
    $key = 'module_' . $type;
    if (!empty($_SESSION[$key])) {
        $message = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        $class = $type === 'error' ? 'profile-alert-error' : 'profile-alert-success';
        $mark = $type === 'error' ? '!' : 'OK';
        echo '<div class="profile-alert ' . $class . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' .
             '<span class="profile-alert-mark">' . $mark . '</span><span>' .
             htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</span></div>';
    }
}

// ---------------------------------------------------------------
// Role-aware shell selection.
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
    'student'        => 'Student',
    'faculty'        => 'Faculty',
    'research_staff' => 'Research Staff',
    'admin'          => 'Administrator',
];
$role_label = $role_labels[$role] ?? ucwords(str_replace('_', ' ', $role));

// Year-level options (matches the ENUM in migration 002).
$year_level_options = ['', '1st', '2nd', '3rd', '4th', 'Graduate', 'Masters', 'Doctorate'];
$rank_options = array_merge([''], array_keys(rmsFacultyAcademicRanks()));
$current_rank = (string) ($user_row['academic_rank'] ?? '');
if ($current_rank !== '' && !in_array($current_rank, $rank_options, true)) {
    $rank_options[] = $current_rank;
}

// Pre-fill values for the form (sticky on validation failure).
$form_first_name    = $user_row['first_name']    ?? '';
$form_last_name     = $user_row['last_name']     ?? '';
$form_contact       = $user_row['contact']       ?? '';
$form_student_id    = $user_row['student_id']    ?? '';
$form_year_level    = $user_row['year_level']    ?? '';
$form_specialization = $user_row['specialization'] ?? '';
$form_academic_rank = $user_row['academic_rank'] ?? '';
$form_office        = $user_row['office']        ?? '';
$form_middle_name = $user_row['middle_name'] ?? '';
$form_designation = $user_row['designation'] ?? '';
$form_appointment_status = $user_row['appointment_status'] ?? '';
$profile_name = trim((string) $form_first_name . ' ' . ($role === 'faculty' ? (string) $form_middle_name . ' ' : '') . (string) $form_last_name);
$profile_name = $profile_name !== '' ? $profile_name : 'RMS User';
$profile_initials = mb_strtoupper(mb_substr((string) $form_first_name, 0, 1) . mb_substr((string) $form_last_name, 0, 1));
$profile_initials = $profile_initials !== '' ? $profile_initials : 'RU';
$member_since = date('M Y', strtotime((string) ($user_row['created_at'] ?? 'now')));
$profileTheme = match ($role) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'highlight' => '#FED7AA', 'rgb' => '245,124,0'],
    'research_staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'highlight' => '#99F6E4', 'rgb' => '13,148,136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'highlight' => '#BFDBFE', 'rgb' => '29,78,216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'highlight' => '#DDD6FE', 'rgb' => '91,30,188'],
};
?>

<style>
  html{scroll-behavior:smooth}.profile-workspace{--profile-ink:#192235;--profile-gold:<?= htmlspecialchars($profileTheme['accent'], ENT_QUOTES, 'UTF-8') ?>;--profile-accent:<?= htmlspecialchars($profileTheme['accent'], ENT_QUOTES, 'UTF-8') ?>;--profile-deep:<?= htmlspecialchars($profileTheme['deep'], ENT_QUOTES, 'UTF-8') ?>;--profile-tint:<?= htmlspecialchars($profileTheme['tint'], ENT_QUOTES, 'UTF-8') ?>;--profile-highlight:<?= htmlspecialchars($profileTheme['highlight'], ENT_QUOTES, 'UTF-8') ?>;--profile-rgb:<?= htmlspecialchars($profileTheme['rgb'], ENT_QUOTES, 'UTF-8') ?>;--profile-muted:#687386;--profile-line:#dfe5ed;max-width:1380px;margin:0 auto;color:var(--profile-ink)}
  .profile-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(310px,.8fr);gap:50px;min-height:335px;padding:52px 56px 64px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 83% 14%,rgba(210,162,72,.21),transparent 29%),linear-gradient(135deg,#172033,#202e46 66%,#29364c);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.profile-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.15;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.profile-kicker,.identity-code,.card-index,.profile-role{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.profile-kicker{margin-bottom:21px;color:#e9bf6e}.hero-identity{display:flex;align-items:center;gap:23px}.profile-monogram{display:grid;place-items:center;flex:0 0 82px;height:82px;border:1px solid rgba(255,255,255,.18);border-radius:22px 22px 8px 22px;background:rgba(255,255,255,.09);color:#f0c97e;font-size:25px;font-weight:750;letter-spacing:-.04em;box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}.profile-hero h2{max-width:760px;margin:0;color:#fff;font-size:clamp(38px,4.2vw,61px);line-height:.98;letter-spacing:-.055em;text-wrap:balance}.profile-role{display:inline-flex;margin-top:14px;padding:6px 8px;border:1px solid rgba(233,191,110,.25);border-radius:5px;background:rgba(233,191,110,.08);color:#e9bf6e}.profile-hero-email{margin:17px 0 0;color:#b9c4d4;font-size:14px;overflow-wrap:anywhere}.identity-readout{align-self:end;display:grid;gap:2px}.identity-row{display:grid;grid-template-columns:40px 1fr auto;align-items:center;gap:12px;padding:16px 18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.identity-row:first-child{border-radius:14px 14px 5px 5px}.identity-row:last-child{border-radius:5px 5px 14px 14px}.identity-code{color:#e9bf6e}.identity-label{color:#d4dce8;font-size:12px}.identity-value{color:#fff;font-size:15px;font-weight:680;text-align:right;overflow-wrap:anywhere}
  .profile-alert{display:flex;align-items:flex-start;gap:12px;margin:26px 0 0;padding:14px 17px;border:1px solid;border-radius:10px;font-size:13px;line-height:1.55}.profile-alert-mark{display:grid;place-items:center;flex:0 0 24px;height:24px;border-radius:7px;font-size:10px;font-weight:800}.profile-alert-success{border-color:#cde7d8;background:#f0f8f3;color:#276446}.profile-alert-success .profile-alert-mark{background:#dcefe3}.profile-alert-error{border-color:#edcaca;background:#fff4f4;color:#9a3535}.profile-alert-error .profile-alert-mark{background:#f7dddd}
  .profile-grid{display:grid;grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr);gap:24px;margin-top:36px;align-items:start}.profile-workspace .card{margin:0;overflow:hidden;border:1px solid var(--profile-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.06)}.profile-workspace .card:first-child{position:sticky;top:24px;grid-row:1 / span 2}.profile-workspace .card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:0;padding:27px 28px 22px;border-bottom:1px solid #edf0f4;background:#fff}.card-index{margin-bottom:9px;color:#987027}.profile-workspace .card-title{margin:0;color:#1c2639;font-size:24px;font-weight:720;line-height:1.13;letter-spacing:-.03em}.profile-workspace .card-subtitle{max-width:620px;margin:9px 0 0;color:var(--profile-muted);font-size:13px;line-height:1.6}.profile-workspace .card-body{padding:6px 28px 28px}
  .profile-info-row{display:grid;grid-template-columns:minmax(108px,.76fr) minmax(0,1fr);gap:16px;padding:15px 0;border-bottom:1px solid #edf0f4;font-size:13px}.profile-info-row:last-child{border-bottom:0}.profile-info-label{color:#8791a0;font:700 9px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.08em;text-transform:uppercase}.profile-info-value{color:#263247;font-weight:650;line-height:1.5;overflow-wrap:anywhere}
  .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.form-grid .full{grid-column:1/-1}.form-group{display:flex;flex-direction:column;gap:7px;margin-bottom:17px}.form-group label{color:#000;font-size:12px;font-weight:680}.form-group input,.form-group select{width:100%;min-height:44px;padding:10px 13px;border:1px solid #d7dee7;border-radius:8px;background:#fff;color:#000!important;font-family:inherit;font-size:13px;line-height:1.45;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease}.form-group input::placeholder{color:#8b95a4}.form-group input:focus,.form-group select:focus{outline:0;border-color:#b88731;box-shadow:0 0 0 3px rgba(210,162,72,.16)}.form-group input[disabled]{border-color:#e3e7ec;background:#f4f6f8;color:#596579!important;cursor:not-allowed}.form-help{color:#8791a0;font-size:11px;line-height:1.45}.form-actions{display:flex;gap:10px;margin-top:5px;padding-top:19px;border-top:1px solid #edf0f4}.profile-workspace .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 16px;border:1px solid transparent;border-radius:8px;background:none;color:inherit;font-family:inherit;font-size:12px;font-weight:700;line-height:1.2;text-decoration:none;cursor:pointer;transition:transform .2s ease,background .2s ease,border-color .2s ease,box-shadow .2s ease}.profile-workspace .btn:hover{transform:translateY(-1px)}.profile-workspace .btn:active{transform:translateY(0) scale(.98)}.profile-workspace .btn:focus-visible{outline:3px solid rgba(210,162,72,.28);outline-offset:2px}.profile-workspace .btn-primary{border-color:var(--profile-gold);background:var(--profile-gold);color:#182033}.profile-workspace .btn-primary:hover{border-color:#dfb45f;background:#dfb45f;box-shadow:0 9px 22px rgba(159,117,42,.18)}.security-note{display:flex;gap:11px;margin:0 0 20px;padding:13px 14px;border-radius:9px;background:#f5f7fa;color:#5b6779;font-size:11px;line-height:1.55}.security-note::before{content:'08';color:#987027;font:700 10px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
  @media(max-width:980px){.profile-hero{grid-template-columns:1fr;gap:30px}.identity-readout{grid-template-columns:repeat(3,1fr)}.identity-row{grid-template-columns:31px 1fr}.identity-value{grid-column:2;text-align:left}.profile-grid{grid-template-columns:1fr}.profile-workspace .card:first-child{position:static;grid-row:auto}}
  @media(max-width:640px){.profile-hero{min-height:0;padding:31px 24px 38px;border-radius:18px 18px 7px 7px}.hero-identity{align-items:flex-start;flex-direction:column}.profile-monogram{flex-basis:68px;width:68px;height:68px;border-radius:18px 18px 7px 18px}.profile-hero h2{font-size:39px}.identity-readout{grid-template-columns:1fr}.identity-row{grid-template-columns:34px 1fr auto}.identity-value{grid-column:auto;text-align:right}.profile-grid{margin-top:27px}.profile-workspace .card-header{padding:23px 20px 19px}.profile-workspace .card-body{padding:5px 20px 22px}.profile-info-row,.form-grid{grid-template-columns:1fr}.profile-info-row{gap:5px}.form-actions .btn{width:100%}}
  .profile-workspace .profile-hero{background:radial-gradient(circle at 83% 14%,rgba(255,255,255,.2),transparent 29%),linear-gradient(135deg,var(--profile-deep),var(--profile-accent));box-shadow:0 24px 58px rgba(var(--profile-rgb),.15)}
  .profile-workspace .profile-kicker,.profile-workspace .profile-monogram,.profile-workspace .profile-role,.profile-workspace .identity-code{color:var(--profile-highlight)}
  .profile-workspace .profile-role{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.09)}
  .profile-workspace .card-index{color:var(--profile-accent)}
  .profile-workspace .form-group input:focus,.profile-workspace .form-group select:focus{border-color:var(--profile-accent);box-shadow:0 0 0 3px rgba(var(--profile-rgb),.12)}
  .profile-workspace .btn:focus-visible{outline-color:rgba(var(--profile-rgb),.28)}
  .profile-workspace .btn-primary{border-color:var(--profile-accent);background:var(--profile-accent);color:#fff}
  .profile-workspace .btn-primary:hover{filter:brightness(1.08);box-shadow:0 9px 22px rgba(var(--profile-rgb),.18)}
  .profile-workspace .security-note::before{color:var(--profile-accent)}
  @media(prefers-reduced-motion:reduce){.profile-workspace .btn,.form-group input,.form-group select{transition:none}}
</style>

<div class="profile-workspace">
  <section class="profile-hero" aria-labelledby="profile-hero-title">
    <div class="profile-hero-copy">
      <div class="profile-kicker">Personal record &middot; RMS identity</div>
      <div class="hero-identity">
        <div class="profile-monogram" aria-hidden="true"><?php echo htmlspecialchars($profile_initials, ENT_QUOTES, 'UTF-8'); ?></div>
        <div>
          <h2 id="profile-hero-title"><?php echo htmlspecialchars($profile_name, ENT_QUOTES, 'UTF-8'); ?></h2>
          <span class="profile-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
      <p class="profile-hero-email"><?php echo htmlspecialchars((string) ($user_row['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="identity-readout" aria-label="Account overview">
      <div class="identity-row"><span class="identity-code">01</span><span class="identity-label">Account status</span><strong class="identity-value"><?php echo htmlspecialchars(ucfirst((string) ($user_row['status'] ?? 'active')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
      <div class="identity-row"><span class="identity-code">02</span><span class="identity-label">Member since</span><strong class="identity-value"><?php echo htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8'); ?></strong></div>
      <div class="identity-row"><span class="identity-code">03</span><span class="identity-label">Account ID</span><strong class="identity-value">#<?php echo number_format($user_id); ?></strong></div>
    </div>
  </section>

  <?php profile_flash('success'); ?>
  <?php profile_flash('error'); ?>

<div class="profile-grid">

  <!-- ACCOUNT INFORMATION (read-only) -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-index">01 &middot; Identity</div>
        <div class="card-title">Account Information</div>
        <div class="card-subtitle">Your basic account details. Email and role cannot be changed.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="profile-info-row">
        <div class="profile-info-label">Full name</div>
        <div class="profile-info-value">
          <?php echo htmlspecialchars(trim(($user_row['first_name'] ?? '') . ' ' . ($role === 'faculty' ? (string) ($user_row['middle_name'] ?? '') . ' ' : '') . ($user_row['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
        </div>
      </div>
      <div class="profile-info-row">
        <div class="profile-info-label">Email</div>
        <div class="profile-info-value"><?php echo htmlspecialchars((string) ($user_row['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="profile-info-row">
        <div class="profile-info-label">Role</div>
        <div class="profile-info-value"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <?php if (in_array($role, $academic_affiliation_roles, true)): ?>
      <div class="profile-info-row">
        <div class="profile-info-label">Institute</div>
        <div class="profile-info-value">EARIST</div>
      </div>
      <?php endif; ?>
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
          <div class="profile-info-value"><?php echo htmlspecialchars(rmsFacultyAcademicRankLabel($user_row['academic_rank'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($has_column['designation'] && !empty($user_row['designation'])): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Designation</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) $user_row['designation'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($has_column['appointment_status'] && !empty($user_row['appointment_status'])): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Appointment status</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) $user_row['appointment_status'], ENT_QUOTES, 'UTF-8'); ?></div>
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
        <?php if ($has_column['academic_rank'] && !empty($user_row['academic_rank'])): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Academic rank</div>
          <div class="profile-info-value"><?php echo htmlspecialchars(rmsFacultyAcademicRankLabel($user_row['academic_rank']), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($has_column['designation'] && !empty($user_row['designation'])): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Designation</div>
          <div class="profile-info-value"><?php echo htmlspecialchars((string) $user_row['designation'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($academic_affiliation_enabled): ?>
        <?php foreach ($academic_affiliation_summary as $groupName => $programNames): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Academic Unit / Program Group</div>
          <div class="profile-info-value"><?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="profile-info-row">
          <div class="profile-info-label">Academic Program</div>
          <div class="profile-info-value"><?php echo htmlspecialchars($programNames, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endforeach; ?>
      <?php elseif (in_array($role, $academic_affiliation_roles, true)): ?>
        <div class="profile-info-row">
          <div class="profile-info-label">Academic affiliation</div>
          <div class="profile-info-value">The affiliation catalog is not available yet.</div>
        </div>
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
        <div class="card-index">02 &middot; Personal details</div>
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
          <?php if ($role === 'faculty' && $has_column['middle_name']): ?>
          <div class="form-group">
            <label for="middle_name">Middle name / initial <span class="form-help">(optional)</span></label>
            <input type="text" id="middle_name" name="middle_name" maxlength="80"
                   value="<?php echo htmlspecialchars((string) $form_middle_name, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="Middle name or initial">
          </div>
          <?php endif; ?>
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
            <?php if ($has_column['designation']): ?>
            <div class="form-group">
              <label for="designation">Designation</label>
              <input type="text" id="designation" name="designation" maxlength="150"
                     value="<?php echo htmlspecialchars((string) $form_designation, ENT_QUOTES, 'UTF-8'); ?>"
                     placeholder="Optional designation">
            </div>
            <?php endif; ?>
            <?php if ($has_column['appointment_status']): ?>
            <div class="form-group">
              <label for="appointment_status">Appointment / employment status</label>
              <input type="text" id="appointment_status" name="appointment_status" maxlength="80"
                     value="<?php echo htmlspecialchars((string) $form_appointment_status, ENT_QUOTES, 'UTF-8'); ?>"
                     placeholder="Optional appointment status">
              <span class="form-help">This is employment information and does not control RMS account access.</span>
            </div>
            <?php endif; ?>
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
                    <?php echo $opt === '' ? '— Not set —' : htmlspecialchars(rmsFacultyAcademicRankLabel($opt) . (!isset(rmsFacultyAcademicRanks()[$opt]) ? ' (previous rank; select a level)' : ''), ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          <?php else: /* research_staff / admin */ ?>
            <?php if ($has_column['designation']): ?>
            <div class="form-group">
              <label for="designation">Designation</label>
              <input type="text" id="designation" name="designation" maxlength="150"
                     value="<?php echo htmlspecialchars((string) $form_designation, ENT_QUOTES, 'UTF-8'); ?>"
                     placeholder="Optional designation">
            </div>
            <?php endif; ?>
            <?php if ($has_column['academic_rank']): ?>
            <div class="form-group">
              <label for="academic_rank">Academic rank <span class="form-help">(optional)</span></label>
              <select id="academic_rank" name="academic_rank">
                <?php foreach ($rank_options as $opt): ?>
                  <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) $form_academic_rank === $opt ? 'selected' : ''; ?>>
                    <?php echo $opt === '' ? 'Not applicable / not recorded' : htmlspecialchars(rmsFacultyAcademicRankLabel($opt), ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
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

        <?php if (in_array($role, $academic_affiliation_roles, true)): ?>
          <?php if ($academic_affiliation_enabled): ?>
          <section class="academic-affiliation-editor" id="profileAcademicAffiliationEditor" data-academic-affiliation-editor
                   data-catalog="<?php echo htmlspecialchars(json_encode($academic_affiliation_catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>"
                   data-initial="<?php echo htmlspecialchars(json_encode($academic_affiliations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="academic_affiliations_submitted" value="1">
            <div class="affiliation-editor-heading">
              <div>
                <h4>Academic Affiliation</h4>
                <p>Institute: <strong>EARIST</strong>. Leave the program selection empty when it does not apply.</p>
              </div>
              <button class="btn btn-secondary btn-sm" type="button" data-add-affiliation>Add academic unit</button>
            </div>
            <div class="affiliation-editor-rows" data-affiliation-rows></div>
          </section>
          <?php else: ?>
          <section class="academic-affiliation-editor unavailable">
            <h4>Academic Affiliation</h4>
            <p>Institute: <strong>EARIST</strong>. The affiliation catalog is not available yet.</p>
          </section>
          <?php endif; ?>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- CHANGE PASSWORD -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-index">03 &middot; Account security</div>
        <div class="card-title">Change Password</div>
        <div class="card-subtitle">Choose a new password with at least <?php echo (int) rmsSetting('password_min_length'); ?> characters.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="security-note">Changing your password signs in with the new credential on your next session. Use at least eight characters and do not reuse your current password.</div>
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
            <input type="password" id="new_password" name="new_password" minlength="<?php echo (int) rmsSetting('password_min_length'); ?>" maxlength="64" required autocomplete="new-password">
            <span class="form-help">Minimum <?php echo (int) rmsSetting('password_min_length'); ?> characters; include a letter and number.</span>
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="<?php echo (int) rmsSetting('password_min_length'); ?>" maxlength="64" required autocomplete="new-password">
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Update password</button>
        </div>
      </form>
    </div>
  </div>

</div>
</div>

<?php
if ($academic_affiliation_enabled):
?>
<style>
.academic-affiliation-editor{margin:22px 0 8px;padding:20px;border:1px solid #dbe3ec;border-radius:14px;background:#fbfcfe;--affiliation-accent:var(--profile-accent,#1d4ed8)}
.academic-affiliation-editor.unavailable{color:#64748b}
.academic-affiliation-editor h4{margin:0;color:#172033;font-size:15px}
.academic-affiliation-editor p{margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.5}
.affiliation-editor-heading,.affiliation-row-controls{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.affiliation-editor-rows{display:grid;gap:12px;margin-top:16px}
.affiliation-row{padding:15px;border:1px solid #e2e8f0;border-radius:11px;background:#fff}
.affiliation-group-field{display:grid;gap:7px;flex:1;max-width:520px}
.affiliation-field-label{display:block;color:#475569;font-size:12px;font-weight:700}
.affiliation-group-field select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#172033;font:inherit}
.affiliation-group-field select:focus{outline:2px solid var(--affiliation-accent);outline-offset:2px}
.affiliation-remove{padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font:inherit;font-size:12px;cursor:pointer}
.affiliation-program-field{margin:14px 0 0;padding:0;border:0}
.affiliation-program-options{margin-top:8px}
.affiliation-program-select{display:block;width:100%;min-height:96px;padding:8px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font:inherit;font-size:12px;line-height:1.4}
.affiliation-program-select:focus{outline:2px solid var(--affiliation-accent);outline-offset:2px}
.affiliation-empty{grid-column:1/-1;color:#64748b;font-size:12px}
@media(max-width:640px){.academic-affiliation-editor{padding:15px}.affiliation-editor-heading{flex-direction:column}}
</style>
<script>window.RmsAcademicAffiliationCatalog = <?php echo json_encode($academic_affiliation_catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo htmlspecialchars(SITE_URL . 'js/academic-affiliation.js', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>
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
