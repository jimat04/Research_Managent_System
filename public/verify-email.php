<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$preserveArchiveIntent = ($_GET['continue'] ?? '') === RMS_RESEARCH_ARCHIVE_RETURN_VALUE;
if ($preserveArchiveIntent) {
    rmsSetResearchArchiveReturnIntent(RMS_RESEARCH_ARCHIVE_RETURN_VALUE);
}

$loginUrl = SITE_URL . 'public/login.php' . ($preserveArchiveIntent
    ? '?continue=' . rawurlencode(RMS_RESEARCH_ARCHIVE_RETURN_VALUE)
    : '');
$resendUrl = SITE_URL . 'public/resend-verification.php' . ($preserveArchiveIntent
    ? '?continue=' . rawurlencode(RMS_RESEARCH_ARCHIVE_RETURN_VALUE)
    : '');
$homeUrl = SITE_URL . 'public/index.php';

$error = '';
$success = '';
$verificationState = 'error';

if (isset($_GET['token'])) {
    $token = sanitize($_GET['token']);

    if ($token === '') {
        $error = 'No usable verification token was provided.';
    } else {
        $stmt = $conn->prepare('SELECT user_id, email_verified, email_verification_expires FROM users WHERE email_verification_token = ? LIMIT 1');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if ((int) $user['email_verified'] === 1) {
                $verificationState = 'already-verified';
                $error = 'This email address is already verified. You can sign in to RMS.';
            } elseif (empty($user['email_verification_expires'])
                || strtotime($user['email_verification_expires']) < time()) {
                $verificationState = 'expired';
                $error = 'This verification link has expired. Request a new email to continue.';
            } else {
                $updateStmt = $conn->prepare('UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires = NULL, updated_at = NOW() WHERE user_id = ?');
                $updateStmt->bind_param('i', $user['user_id']);

                if ($updateStmt->execute()) {
                    logActivity('Email verified', 'authentication');
                    $verificationState = 'verified';
                    $success = 'Your email address is confirmed. You can sign in to RMS now.';
                } else {
                    $error = 'We could not complete verification. Please try again.';
                }
                $updateStmt->close();
            }
        } else {
            $error = 'This verification link is invalid or has already been used.';
        }
        $stmt->close();
    }
} else {
    $error = 'No verification token was provided.';
}

$isVerified = in_array($verificationState, ['verified', 'already-verified'], true);
$pageHeading = $verificationState === 'verified'
    ? 'Email verified'
    : ($verificationState === 'already-verified' ? 'Email already verified' : 'Verification link unavailable');
$statusLabel = $isVerified ? 'Confirmation complete' : 'Confirmation not complete';
$statusMessage = $success !== '' ? $success : $error;
$primaryUrl = $isVerified ? $loginUrl : $resendUrl;
$primaryLabel = $isVerified ? 'Continue to sign in' : 'Request a new link';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Confirm your email address for the EARIST Research Management System.">
  <title><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?> | <?php echo htmlspecialchars(rmsSetting('site_title'), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL . 'css/style.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL . 'css/verify-email.css?v=' . filemtime(__DIR__ . '/../css/verify-email.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body class="verify-result-page">
<main class="verify-result-shell verify-result-<?php echo htmlspecialchars($isVerified ? 'success' : 'error', ENT_QUOTES, 'UTF-8'); ?>">
  <aside class="verify-result-brand" aria-label="EARIST Research Management System">
    <a class="verify-result-brandmark" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="EARIST RMS home">
      <img src="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="">
      <span>EARIST <strong>RMS</strong></span>
    </a>

    <div class="verify-result-brand-copy">
      <p class="verify-result-brand-kicker">Account access</p>
      <h1>Research Management System</h1>
      <p>Official workspace for EARIST research submissions and records.</p>
    </div>

    <p class="verify-result-brand-footer">Email confirmation</p>
  </aside>

  <section class="verify-result-content" aria-labelledby="verify-result-title">
    <div class="verify-result-content-inner">
      <p class="verify-result-eyebrow">Account setup</p>
      <div class="verify-result-status">
        <span class="verify-result-mark" aria-hidden="true"></span>
        <div>
          <p class="verify-result-status-label"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></p>
          <h2 id="verify-result-title"><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?></h2>
        </div>
      </div>

      <p class="verify-result-message" role="<?php echo $isVerified ? 'status' : 'alert'; ?>" aria-live="<?php echo $isVerified ? 'polite' : 'assertive'; ?>"><?php echo htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8'); ?></p>

      <?php if ($isVerified): ?>
        <p class="verify-result-guidance">Use the email address and password from your registration to sign in.</p>
      <?php endif; ?>

      <div class="verify-result-actions">
        <a class="verify-result-primary" href="<?php echo htmlspecialchars($primaryUrl, ENT_QUOTES, 'UTF-8'); ?>">
          <?php echo htmlspecialchars($primaryLabel, ENT_QUOTES, 'UTF-8'); ?>
          <span aria-hidden="true">→</span>
        </a>
        <?php if (!$isVerified): ?>
          <a class="verify-result-secondary" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to sign in</a>
        <?php endif; ?>
      </div>

      <a class="verify-result-home" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">Return to RMS home</a>
    </div>
  </section>
</main>
</body>
</html>
