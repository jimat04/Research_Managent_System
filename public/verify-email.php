<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';
$success = '';

if (isset($_GET['token'])) {
    $token = sanitize($_GET['token']);

    if (empty($token)) {
        $error = 'Invalid verification token.';
    } else {
        // Check if token exists and is not expired
        $stmt = $conn->prepare("SELECT user_id, first_name, email, email_verified, email_verification_expires FROM users WHERE email_verification_token = ? LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if ($user['email_verified'] == 1) {
                $error = 'Email already verified. You can log in now.';
            } elseif (strtotime($user['email_verification_expires']) < time()) {
                $error = 'Verification link has expired. Please request a new one.';
            } else {
                // Verify email
                $updateStmt = $conn->prepare("UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires = NULL, updated_at = NOW() WHERE user_id = ?");
                $updateStmt->bind_param('i', $user['user_id']);

                if ($updateStmt->execute()) {
                    logActivity('Email verified', 'authentication');
                    $success = 'Email verified successfully! You can now log in.';
                } else {
                    $error = 'Verification failed. Please try again.';
                }
            }
        } else {
            $error = 'Invalid verification token.';
        }
    }
} else {
    $error = 'No verification token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Email — <?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body style="background: linear-gradient(135deg, #0A0833 0%, #1a0a3a 50%, #0a1a3a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center;">

<div class="auth-container">
  <div class="auth-card" style="max-width: 500px;">
    <div class="auth-header">
      <div class="auth-logo" style="background: linear-gradient(135deg, <?php echo $success ? '#16A34A' : '#DC2626'; ?>, <?php echo $success ? '#059669' : '#B91C1C'; ?>);">
        <?php echo $success ? '✓' : '✕'; ?>
      </div>
      <h1><?php echo $success ? 'Email Verified' : 'Verification Failed'; ?></h1>
      <p><?php echo $success ? 'Your email has been successfully verified' : 'We could not verify your email'; ?></p>
    </div>

    <?php if ($error): ?>
      <div style="padding: 24px;">
        <div class="alert alert-error" style="margin-bottom: 20px;">
          <span style="color: #dc2626;">✕</span>
          <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <a href="login.php" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
          🔑 Go to Login
        </a>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div style="padding: 24px;">
        <div class="alert alert-success" style="margin-bottom: 20px;">
          <span style="color: #15803d;">✓</span>
          <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <div style="background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px; padding: 16px; margin-bottom: 20px; font-size: 0.85rem; color: #93c5fd; line-height: 1.6;">
          <strong style="color: #bfdbfe;">What's next?</strong><br>
          You can now log in to your account and start using the Research Management System.
        </div>

        <a href="login.php" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
          🔑 Log In Now
        </a>
      </div>
    <?php endif; ?>

    <p style="text-align: center; margin-top: 20px; padding: 0 24px 24px;">
      <a href="index.php" style="color: #8B8FAD; font-size: 0.85rem; text-decoration: none;">← Back to homepage</a>
    </p>
  </div>
</div>

</body>
</html>
