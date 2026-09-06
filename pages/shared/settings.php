<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function settingsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$accountStmt = $conn->prepare(
    "SELECT user_id, CONCAT_WS(' ', first_name, last_name) AS name,
            email, role, created_at, password
     FROM users
     WHERE user_id = ?
     LIMIT 1"
);
$accountStmt->bind_param('i', $user['user_id']);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$account) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $error = 'Your session token expired. Please try again.';
    } elseif (($_POST['action'] ?? '') !== 'change_password') {
        $error = 'Invalid settings request.';
    } else {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Complete all password fields.';
        } elseif (!password_verify($currentPassword, $account['password'])) {
            $error = 'The current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'The new password must be at least 8 characters.';
        } elseif (password_verify($newPassword, $account['password'])) {
            $error = 'The new password must be different from your current password.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new password and confirmation do not match.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($passwordHash === false) {
                $error = 'The password could not be secured. Please try again.';
            } else {
                $updateStmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                $updateStmt->bind_param('si', $passwordHash, $account['user_id']);
                $updateStmt->execute();
                $updated = $updateStmt->affected_rows === 1;
                $updateStmt->close();

                if ($updated) {
                    createNotification(
                        (int) $account['user_id'],
                        'Your password was changed',
                        'Your RMS account password was changed successfully.',
                        'warning',
                        'pages/shared/settings.php'
                    );
                    $_SESSION['settings_success'] = 'Your password was changed successfully.';
                    header('Location: ' . SITE_URL . 'pages/shared/settings.php');
                    exit;
                }
                $error = 'The password could not be updated. Please try again.';
            }
        }
    }

    $_SESSION['settings_error'] = $error;
    header('Location: ' . SITE_URL . 'pages/shared/settings.php');
    exit;
}

$success = $_SESSION['settings_success'] ?? '';
$error = $_SESSION['settings_error'] ?? '';
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

$roleLabels = [
    'student' => 'Student',
    'faculty' => 'Faculty',
    'research_staff' => 'Research Staff',
    'admin' => 'Administrator',
];
$role = (string) $account['role'];
$roleLabel = $roleLabels[$role] ?? ucwords(str_replace('_', ' ', $role));
$memberSince = !empty($account['created_at'])
    ? date('F j, Y', strtotime($account['created_at']))
    : 'Not recorded';

$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};

if ($shell === 'admin') {
    renderAdminShell($user, 'settings.php', 'Settings', 'Account and security');
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'settings.php', 'Settings', 'Account and security');
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'settings.php', 'Settings', 'Account and security');
} else {
    renderStudentShell($user, 'settings.php', 'Settings', 'Account and security');
}
?>

<style>
    .settings-grid { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:1rem; }
    .settings-card { background:#fff; border:1px solid #e3e7ee; border-radius:12px; padding:1.25rem; }
    .settings-card h2 { margin:0 0 1rem; font-size:1.15rem; color:#101828; }
    .account-list { display:grid; gap:.8rem; margin:0; }
    .account-row { display:grid; grid-template-columns:120px minmax(0, 1fr); gap:1rem; padding-bottom:.8rem; border-bottom:1px solid #eef1f5; }
    .account-row:last-child { padding-bottom:0; border-bottom:0; }
    .account-row dt { color:#667085; font-weight:600; }
    .account-row dd { margin:0; color:#101828; overflow-wrap:anywhere; }
    .form-group { margin-bottom:1rem; }
    .form-group label { display:block; margin-bottom:.35rem; color:#344054; font-weight:600; }
    .form-group input { width:100%; box-sizing:border-box; border:1px solid #d0d5dd; border-radius:8px; padding:.65rem .75rem; font:inherit; }
    .form-help { margin:.35rem 0 0; color:#667085; font-size:.83rem; }
    .settings-button { display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:8px; padding:.65rem 1rem; background:#7c3aed; color:#fff; font-weight:700; text-decoration:none; cursor:pointer; }
    .settings-button.secondary { background:#fff; color:#344054; border:1px solid #d0d5dd; }
    .settings-alert { border-radius:9px; padding:.8rem 1rem; margin-bottom:1rem; }
    .settings-alert.success { background:#dcfce7; color:#166534; }
    .settings-alert.error { background:#fee2e2; color:#991b1b; }
    .settings-note { margin:1rem 0 0; padding-top:1rem; border-top:1px solid #eef1f5; color:#667085; }
    @media (max-width:800px) { .settings-grid { grid-template-columns:1fr; } }
</style>

<?php if ($success !== ''): ?>
    <div class="settings-alert success" role="status"><?= settingsEscape($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="settings-alert error" role="alert"><?= settingsEscape($error) ?></div>
<?php endif; ?>

<div class="settings-grid">
    <section class="settings-card">
        <h2>Account Summary</h2>
        <dl class="account-list">
            <div class="account-row"><dt>Name</dt><dd><?= settingsEscape($account['name']) ?></dd></div>
            <div class="account-row"><dt>Email</dt><dd><?= settingsEscape($account['email']) ?></dd></div>
            <div class="account-row"><dt>Role</dt><dd><?= settingsEscape($roleLabel) ?></dd></div>
            <div class="account-row"><dt>Member since</dt><dd><?= settingsEscape($memberSince) ?></dd></div>
        </dl>

        <p class="settings-note">Personal details are managed from your profile.</p>
        <a class="settings-button secondary" href="<?= settingsEscape(SITE_URL . 'pages/shared/profile.php') ?>">Edit Personal Details</a>
        <p class="settings-note">Notification preferences are not yet implemented.</p>
    </section>

    <section class="settings-card">
        <h2>Change Password</h2>
        <form method="post" action="<?= settingsEscape(SITE_URL . 'pages/shared/settings.php') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
                <p class="form-help">Use at least 8 characters and choose a password different from your current one.</p>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </div>
            <button class="settings-button" type="submit">Change Password</button>
        </form>
    </section>
</div>

<?php
if ($shell === 'admin') {
    renderAdminShellClose();
} elseif ($shell === 'staff') {
    renderStaffShellClose();
} elseif ($shell === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
