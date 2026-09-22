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
$settingsTheme = match ($shell) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'rgb' => '245, 124, 0'],
    'staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'rgb' => '13, 148, 136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'rgb' => '29, 78, 216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'rgb' => '91, 30, 188'],
};
$accountInitials = strtoupper(substr((string) $account['name'], 0, 1));
$nameParts = preg_split('/\s+/', trim((string) $account['name'])) ?: [];
if (count($nameParts) > 1) {
    $accountInitials .= strtoupper(substr((string) end($nameParts), 0, 1));
}

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
    .settings-page {
        --settings-accent: <?= settingsEscape($settingsTheme['accent']) ?>;
        --settings-deep: <?= settingsEscape($settingsTheme['deep']) ?>;
        --settings-tint: <?= settingsEscape($settingsTheme['tint']) ?>;
        --settings-rgb: <?= settingsEscape($settingsTheme['rgb']) ?>;
        max-width: 1240px;
        margin: 0 auto;
        color: #0f172a;
    }
    .settings-hero {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 34px;
        align-items: end;
        overflow: hidden;
        margin-bottom: 22px;
        padding: 34px 38px;
        border: 1px solid rgba(var(--settings-rgb), .35);
        border-radius: 20px;
        background: radial-gradient(circle at 88% 5%, rgba(255,255,255,.2), transparent 31%), linear-gradient(135deg, var(--settings-deep), var(--settings-accent));
        box-shadow: 0 20px 44px rgba(var(--settings-rgb), .15);
    }
    .settings-hero::after { content:""; position:absolute; right:-65px; bottom:-110px; width:270px; height:270px; border:1px solid rgba(255,255,255,.14); border-radius:50%; }
    .settings-hero-copy, .settings-identity { position:relative; z-index:1; }
    .settings-kicker { display:block; margin-bottom:9px; color:rgba(255,255,255,.76); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
    .settings-hero h1 { margin:0; color:#fff; font-size:clamp(28px,3vw,40px); line-height:1.08; letter-spacing:-.035em; }
    .settings-hero p { max-width:650px; margin:13px 0 0; color:rgba(255,255,255,.76); font-size:14px; line-height:1.65; }
    .settings-identity { display:flex; align-items:center; gap:13px; min-width:260px; padding:15px 17px; border:1px solid rgba(255,255,255,.2); border-radius:14px; background:rgba(255,255,255,.1); backdrop-filter:blur(8px); }
    .settings-avatar { display:grid; place-items:center; flex:0 0 43px; width:43px; height:43px; border-radius:11px; background:#fff; color:var(--settings-accent); font-size:13px; font-weight:850; }
    .settings-identity strong { display:block; color:#fff; font-size:13px; }
    .settings-identity div span { display:block; margin-top:4px; color:rgba(255,255,255,.72); font-size:10px; font-weight:700; }
    .settings-alert { display:flex; align-items:center; gap:10px; margin-bottom:14px; padding:12px 15px; border:1px solid; border-radius:11px; font-size:12px; }
    .settings-alert.success { border-color:#bbdfc9; background:#effaf3; color:#166534; }
    .settings-alert.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .settings-grid { display:grid; grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr); gap:20px; align-items:start; }
    .settings-card { overflow:hidden; border:1px solid #e2e8f0; border-radius:17px; background:#fff; box-shadow:0 12px 32px rgba(15,23,42,.05); }
    .settings-card-head { padding:24px 26px 20px; border-bottom:1px solid #edf1f5; }
    .settings-index { display:block; margin-bottom:7px; color:var(--settings-accent); font-size:9px; font-weight:850; letter-spacing:.1em; text-transform:uppercase; }
    .settings-card h2 { margin:0; color:#0f172a; font-size:18px; letter-spacing:-.018em; }
    .settings-card-head p { margin:7px 0 0; color:#64748b; font-size:11px; line-height:1.55; }
    .settings-card-body { padding:7px 26px 26px; }
    .account-list { display:grid; margin:0; }
    .account-row { display:grid; grid-template-columns:110px minmax(0,1fr); gap:16px; padding:15px 0; border-bottom:1px solid #edf1f5; }
    .account-row dt { color:#94a3b8; font-size:9px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; }
    .account-row dd { margin:0; color:#273449; font-size:12px; font-weight:700; overflow-wrap:anywhere; }
    .settings-profile-action { padding-top:18px; }
    .settings-profile-action p { margin:0 0 12px; color:#64748b; font-size:11px; line-height:1.55; }
    .settings-form { padding-top:18px; }
    .form-group { margin-bottom:16px; }
    .form-group label { display:block; margin-bottom:7px; color:#0f172a; font-size:11px; font-weight:750; }
    .form-group input { width:100%; min-height:44px; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:9px; padding:10px 12px; background:#fff; color:#0f172a; font:inherit; font-size:13px; transition:border-color .18s ease, box-shadow .18s ease; }
    .form-group input:focus { border-color:var(--settings-accent); box-shadow:0 0 0 3px rgba(var(--settings-rgb),.1); outline:0; }
    .form-help { margin:6px 0 0; color:#64748b; font-size:10px; line-height:1.5; }
    .settings-security-note { display:flex; gap:11px; margin:0 0 18px; padding:13px 14px; border-radius:10px; background:var(--settings-tint); color:#475569; font-size:11px; line-height:1.55; }
    .settings-security-note::before { content:"✓"; color:var(--settings-accent); font-weight:900; }
    .settings-button { display:inline-flex; align-items:center; justify-content:center; min-height:41px; box-sizing:border-box; border:1px solid transparent; border-radius:9px; padding:9px 15px; background:var(--settings-accent); color:#fff; font-size:11px; font-weight:800; text-decoration:none; cursor:pointer; transition:transform .18s ease, box-shadow .18s ease; }
    .settings-button:hover { color:#fff; transform:translateY(-1px); box-shadow:0 8px 18px rgba(var(--settings-rgb),.2); }
    .settings-button.secondary { border-color:#cbd5e1; background:#fff; color:#475569; }
    .settings-button.secondary:hover { border-color:rgba(var(--settings-rgb),.3); background:var(--settings-tint); color:var(--settings-accent); box-shadow:none; }
    @media (max-width:840px) { .settings-hero,.settings-grid{grid-template-columns:1fr}.settings-identity{width:min(100%,360px)} }
    @media (max-width:560px) { .settings-hero{padding:28px 23px}.settings-card-head{padding:22px 20px 18px}.settings-card-body{padding:7px 20px 22px}.account-row{grid-template-columns:1fr;gap:5px}.settings-button{width:100%} }
</style>

<div class="settings-page">
    <section class="settings-hero" aria-labelledby="settings-hero-title">
        <div class="settings-hero-copy">
            <span class="settings-kicker">Account controls</span>
            <h1 id="settings-hero-title">Keep your RMS account secure.</h1>
            <p>Review your account identity and update the password used to access your research workspace.</p>
        </div>
        <div class="settings-identity">
            <span class="settings-avatar" aria-hidden="true"><?= settingsEscape($accountInitials ?: 'RU') ?></span>
            <div><strong><?= settingsEscape($account['name']) ?></strong><span><?= settingsEscape($roleLabel) ?> · Member since <?= settingsEscape(date('M Y', strtotime((string) $account['created_at']))) ?></span></div>
        </div>
    </section>

    <?php if ($success !== ''): ?><div class="settings-alert success" role="status"><?= settingsEscape($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="settings-alert error" role="alert"><?= settingsEscape($error) ?></div><?php endif; ?>

    <div class="settings-grid">
        <section class="settings-card">
            <header class="settings-card-head"><span class="settings-index">01 · Identity</span><h2>Account summary</h2><p>The core information connected to this login.</p></header>
            <div class="settings-card-body">
                <dl class="account-list">
                    <div class="account-row"><dt>Name</dt><dd><?= settingsEscape($account['name']) ?></dd></div>
                    <div class="account-row"><dt>Email</dt><dd><?= settingsEscape($account['email']) ?></dd></div>
                    <div class="account-row"><dt>Role</dt><dd><?= settingsEscape($roleLabel) ?></dd></div>
                    <div class="account-row"><dt>Member since</dt><dd><?= settingsEscape($memberSince) ?></dd></div>
                </dl>
                <div class="settings-profile-action"><p>Update your name and role-specific information from your profile.</p><a class="settings-button secondary" href="<?= settingsEscape(SITE_URL . 'pages/shared/profile.php') ?>">Open profile</a></div>
            </div>
        </section>

        <section class="settings-card">
            <header class="settings-card-head"><span class="settings-index">02 · Security</span><h2>Change password</h2><p>Choose a new password for future RMS sessions.</p></header>
            <div class="settings-card-body">
                <form class="settings-form" method="post" action="<?= settingsEscape(SITE_URL . 'pages/shared/settings.php') ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="settings-security-note">Your current password is required before any security change can be saved.</div>
                    <div class="form-group"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
                    <div class="form-group"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required><p class="form-help">Use at least 8 characters and choose a password different from your current one.</p></div>
                    <div class="form-group"><label for="confirm_password">Confirm new password</label><input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
                    <button class="settings-button" type="submit">Update password</button>
                </form>
            </div>
        </section>
    </div>
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
