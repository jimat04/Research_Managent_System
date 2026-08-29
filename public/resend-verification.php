<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

// Must be logged in but email not verified
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get user info
$stmt = $conn->prepare("SELECT first_name, email, email_verified, email_verification_token, email_verification_expires FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    header('Location: login.php');
    exit();
}

// Already verified - redirect to dashboard
if ($user['email_verified'] == 1) {
    $role = $_SESSION['role'] ?? 'student';
    $dashboardMap = [
        'student'        => '../pages/student/student-dashboard.php',
        'faculty'        => '../pages/faculty/faculty-dashboard.php',
        'research_staff' => '../pages/staff/staff-dashboard.php',
        'admin'          => '../pages/admin/admin-dashboard.php',
    ];
    header('Location: ' . ($dashboardMap[$role] ?? '../pages/student/student-dashboard.php'));
    exit();
}

// Handle resend request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Check if existing token is still valid (less than 10 minutes old)
        if (!empty($user['email_verification_expires']) && strtotime($user['email_verification_expires']) > time() + (14 * 60)) {
            $error = 'A verification email was recently sent. Please check your inbox or wait a few minutes before requesting another.';
        } else {
            // Send new verification email
            if (sendVerificationEmail($user_id, $user['email'], $user['first_name'])) {
                $success = 'Verification email sent! Please check your inbox (and spam folder).';
            } else {
                $error = 'Failed to send verification email. Please try again later or contact support.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Your Email — <?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body style="background: linear-gradient(135deg, #0A0833 0%, #1a0a3a 50%, #0a1a3a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center;">

<div class="auth-container">
  <div class="auth-card" style="max-width: 500px;">
    <div class="auth-header">
      <div class="auth-logo" style="background: linear-gradient(135deg, #EA580C, #DC2626);">📧</div>
      <h1>Verify Your Email</h1>
      <p>Check your inbox to complete registration</p>
    </div>

    <div style="padding: 24px;">
      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px;">
          <span style="color: #dc2626;">✕</span>
          <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
          <span style="color: #15803d;">✓</span>
          <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <div style="background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px; padding: 16px; margin-bottom: 20px; font-size: 0.85rem; color: #93c5fd; line-height: 1.6;">
        <strong style="color: #bfdbfe;">📬 Email sent to:</strong><br>
        <span style="color: #ffffff; font-size: 0.95rem;"><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></span><br><br>

        <strong style="color: #bfdbfe;">What to do next:</strong><br>
        1. Check your inbox (and spam/junk folder)<br>
        2. Click the verification link in the email<br>
        3. Return here and log in
      </div>

      <form method="POST" style="margin-bottom: 16px;">
        <input type="hidden" name="action" value="resend">
        <?php echo csrfField(); ?>
        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
          🔄 Resend Verification Email
        </button>
      </form>

      <a href="logout.php" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 12px; background: transparent; border: 1px solid rgba(255,255,255,0.1);">
        ← Back to Login
      </a>
    </div>
  </div>
</div>

</body>
</html>
