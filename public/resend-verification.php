<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

$email = '';
$error = '';
$notice = '';
$preserveArchiveIntent = ($_GET['continue'] ?? '') === RMS_RESEARCH_ARCHIVE_RETURN_VALUE
    || rmsHasResearchArchiveReturnIntent();

if (($_GET['continue'] ?? '') === RMS_RESEARCH_ARCHIVE_RETURN_VALUE) {
    rmsSetResearchArchiveReturnIntent(RMS_RESEARCH_ARCHIVE_RETURN_VALUE);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? trim($emailInput) : '';

    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Please try again.';
    } else {
        // Keep the response identical for unknown, verified, and unverified
        // addresses so this public recovery form cannot disclose account state.
        if (isValidEmail($email)) {
            $stmt = $conn->prepare('SELECT user_id, first_name, email_verified, email_verification_expires, role FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                if ($stmt->execute()) {
                    $user = $stmt->get_result()->fetch_assoc();
                    if ($user && (int) ($user['email_verified'] ?? 0) !== 1
                        && strtolower((string) $user['role']) !== 'admin') {
                        $expiresAt = !empty($user['email_verification_expires'])
                            ? strtotime($user['email_verification_expires'])
                            : false;
                        // Verification links expire 24 hours after issue. This
                        // threshold identifies tokens issued in the last 10 minutes.
                        $recentlySent = $expiresAt !== false
                            && $expiresAt > time() + (24 * 60 * 60) - (10 * 60);

                        if (!$recentlySent) {
                            sendVerificationEmail(
                                (int) $user['user_id'],
                                $email,
                                (string) $user['first_name'],
                                $preserveArchiveIntent
                            );
                        }
                    }
                }
                $stmt->close();
            }
        }

        $notice = 'If this address belongs to an account that needs verification, a message will be sent when email delivery is available. Check your inbox and spam folder. If it does not arrive, wait a few minutes before trying again.';
    }
}

$formAction = $preserveArchiveIntent
    ? '?continue=' . rawurlencode(RMS_RESEARCH_ARCHIVE_RETURN_VALUE)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Request a new email verification link for your RMS account.">
  <title>Resend verification email | RMS</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL . 'css/style.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL . 'css/resend-verification.css?v=' . filemtime(__DIR__ . '/../css/resend-verification.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body class="verification-page">
<main class="verification-shell">
  <aside class="verification-brand" aria-label="RMS account verification">
    <a class="verification-brandmark" href="<?php echo htmlspecialchars(SITE_URL . 'public/index.php', ENT_QUOTES, 'UTF-8'); ?>" aria-label="EARIST RMS home">
      <img src="<?php echo htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="">
      <span>EARIST <strong>RMS</strong></span>
    </a>

    <div class="verification-brand-copy">
      <p class="verification-eyebrow"><span aria-hidden="true"></span> Account access</p>
      <h1>Keep your research account connected.</h1>
      <p class="verification-brand-description">Confirm the email address you registered with to continue to your RMS workspace.</p>
    </div>

    <div class="verification-brand-note">
      <span class="verification-note-icon" aria-hidden="true">✉</span>
      <p><strong>One quick check</strong><br>Verification links expire after 24 hours.</p>
    </div>
    <span class="verification-orbit verification-orbit-one" aria-hidden="true"></span>
    <span class="verification-orbit verification-orbit-two" aria-hidden="true"></span>
  </aside>

  <section class="verification-panel" aria-labelledby="verification-title">
    <div class="verification-content">
      <div class="verification-icon" aria-hidden="true">✉</div>
      <p class="verification-kicker">Email confirmation</p>
      <h2 id="verification-title">Resend verification email</h2>
      <p class="verification-intro">Enter the address you used to register. We’ll send a new link if the account needs verification.</p>

      <?php if ($error !== ''): ?>
        <div class="verification-feedback verification-feedback-error" role="alert">
          <span class="verification-feedback-symbol" aria-hidden="true">!</span>
          <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="verification-feedback verification-feedback-success" role="status" aria-live="polite">
          <span class="verification-feedback-symbol" aria-hidden="true">✓</span>
          <p><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      <?php endif; ?>

      <form class="verification-form" method="POST" action="<?php echo htmlspecialchars(SITE_URL . 'public/resend-verification.php' . $formAction, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo csrfField(); ?>
        <div class="verification-field">
          <label class="verification-label" for="verificationEmail">Email address</label>
          <input type="email" name="email" id="verificationEmail" class="verification-input" autocomplete="email" inputmode="email" required maxlength="160" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="verification-help">
          <p class="verification-help" id="verification-help">If you don’t see the message, check your spam folder.</p>
        </div>
        <button type="submit" class="verification-submit">
          <span>Send verification email</span>
          <span class="verification-submit-arrow" aria-hidden="true">→</span>
        </button>
      </form>

      <div class="verification-footer">
        <span aria-hidden="true">←</span>
        <a href="<?php echo htmlspecialchars(SITE_URL . 'public/login.php' . $formAction, ENT_QUOTES, 'UTF-8'); ?>">Back to sign in</a>
      </div>
    </div>
  </section>
</main>
</body>
</html>
