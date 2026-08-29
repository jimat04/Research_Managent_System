<?php
/**
 * Email Notification System
 * Uses PHPMailer for sending emails
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('rms_mail_config')) {
    /**
     * Resolve a mail-related env value with a sensible default.
     *
     * Treats empty strings and the literal "null" / "false" as missing so a
     * half-configured .env never causes sendEmail() to fail with an empty
     * SMTP username.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function rms_mail_config($key, $default = null) {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '' || strtolower((string) $value) === 'null') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('rms_smtp_is_configured')) {
    /**
     * Decide whether the SMTP transport is actually usable.
     *
     * Requires MAIL_MAILER=smtp plus a non-empty host and username. Without
     * these, sendEmail() silently falls back to PHP's mail() so verification
     * emails still go out on a dev machine.
     *
     * @return bool
     */
    function rms_smtp_is_configured() {
        return rms_mail_config('MAIL_MAILER') === 'smtp'
            && rms_mail_config('MAIL_HOST') !== null
            && rms_mail_config('MAIL_USERNAME') !== null
            && rms_mail_config('MAIL_PASSWORD') !== null;
    }
}

/**
 * Send an email using PHPMailer
 *
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body HTML email body
 * @param string $toName Recipient name (optional)
 * @return bool Success status
 */
function sendEmail($to, $subject, $body, $toName = '') {
    $mail = new PHPMailer(true);

    try {
        // Server settings — auto-fallback when SMTP creds are missing so
        // dev/test environments still get emails via PHP's mail() function.
        if (rms_smtp_is_configured()) {
            $mail->isSMTP();
            $mail->Host       = rms_mail_config('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = rms_mail_config('MAIL_USERNAME');
            $mail->Password   = rms_mail_config('MAIL_PASSWORD');
            $mail->SMTPSecure = rms_mail_config('MAIL_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
            $mail->Port       = (int) rms_mail_config('MAIL_PORT', 587);
        } else {
            // No usable SMTP config — fall back to PHP mail() rather than fail.
            // Logged so a half-configured .env surfaces in php_error_log instead of
            // looking like a successful send.
            error_log("sendEmail: SMTP not configured, using mail() fallback for recipient {$to}");
            $mail->isMail();
        }

        // Recipients
        $fromAddress = rms_mail_config('MAIL_FROM_ADDRESS', 'noreply@rms.edu.ph');
        $fromName    = rms_mail_config('MAIL_FROM_NAME', 'RMS Research System');
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to, $toName);
        $replyTo = rms_mail_config('MAIL_REPLY_TO', $fromAddress);
        $mail->addReplyTo($replyTo, 'RMS Support');

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); // Plain text fallback

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email send failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Generate email verification token
 *
 * @return string 64-character hex token
 */
function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Send email verification email
 *
 * @param int $userId User ID
 * @param string $email User email
 * @param string $firstName User first name
 * @return bool Success status
 */
function sendVerificationEmail($userId, $email, $firstName) {
    global $conn;

    // Generate verification token
    $token = generateVerificationToken();
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Store token in database
    $stmt = $conn->prepare("UPDATE users SET email_verification_token = ?, email_verification_expires = ? WHERE user_id = ?");
    $stmt->bind_param('ssi', $token, $expires, $userId);

    if (!$stmt->execute()) {
        return false;
    }

    // Build verification link
    $verifyLink = rms_mail_config('SITE_URL', 'http://localhost/rms/') . "public/verify-email.php?token=" . urlencode($token);

    // Email template
    $body = getEmailTemplate('verification', [
        'firstName' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
        'verifyLink' => $verifyLink,
        'expiresIn' => '24 hours'
    ]);

    $subject = "Verify Your Email - " . rms_mail_config('SITE_NAME', 'RMS Research System');

    return sendEmail($email, $subject, $body, $firstName);
}

/**
 * Send account approval notification (for faculty/staff)
 *
 * @param string $email User email
 * @param string $firstName User first name
 * @param string $role User role
 * @return bool Success status
 */
function sendApprovalNotification($email, $firstName, $role) {
    $body = getEmailTemplate('approval', [
        'firstName' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
        'role' => ucfirst($role),
        'loginLink' => rms_mail_config('SITE_URL', 'http://localhost/rms/') . "public/login.php"
    ]);

    $subject = "Your Account Has Been Approved - " . rms_mail_config('SITE_NAME', 'RMS Research System');

    return sendEmail($email, $subject, $body, $firstName);
}

/**
 * Send pending approval notification to admin (for faculty/staff registration)
 *
 * @param string $firstName User first name
 * @param string $lastName User last name
 * @param string $email User email
 * @param string $role User role
 * @return bool Success status
 */
function sendPendingApprovalNotification($firstName, $lastName, $email, $role) {
    global $conn;

    // Get all admin emails
    $stmt = $conn->prepare("SELECT email, first_name FROM users WHERE role = 'admin' AND status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result();

    $success = false;
    while ($admin = $result->fetch_assoc()) {
        $body = getEmailTemplate('pending_approval', [
            'adminName' => htmlspecialchars($admin['first_name'], ENT_QUOTES, 'UTF-8'),
            'firstName' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
            'lastName' => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
            'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'role' => ucfirst(str_replace('_', ' ', $role)),
            'managementLink' => rms_mail_config('SITE_URL', 'http://localhost/rms/') . "pages/admin/admin-users.php"
        ]);

        $subject = "New " . ucfirst(str_replace('_', ' ', $role)) . " Registration Pending Approval";

        if (sendEmail($admin['email'], $subject, $body, $admin['first_name'])) {
            $success = true;
        }
    }

    return $success;
}

/**
 * Send research status change notification
 *
 * @param int $userId User ID
 * @param string $researchTitle Research title
 * @param string $oldStatus Old status
 * @param string $newStatus New status
 * @param string $comments Optional comments from reviewer
 * @return bool Success status
 */
function sendResearchStatusNotification($userId, $researchTitle, $oldStatus, $newStatus, $comments = '') {
    global $conn;

    $stmt = $conn->prepare("SELECT email, first_name FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        return false;
    }

    $body = getEmailTemplate('status_change', [
        'firstName' => htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8'),
        'researchTitle' => htmlspecialchars($researchTitle, ENT_QUOTES, 'UTF-8'),
        'oldStatus' => ucfirst(str_replace('_', ' ', $oldStatus)),
        'newStatus' => ucfirst(str_replace('_', ' ', $newStatus)),
        'comments' => htmlspecialchars($comments, ENT_QUOTES, 'UTF-8'),
        'dashboardLink' => rms_mail_config('SITE_URL', 'http://localhost/rms/') . "pages/student/student-dashboard.php"
    ]);

    $subject = "Research Status Update: " . $researchTitle;

    return sendEmail($user['email'], $subject, $body, $user['first_name']);
}

/**
 * Send a staff reply to a public contact-form submission.
 *
 * @param string $toEmail Recipient email
 * @param string $toName  Recipient name
 * @param string $concernType
 * @param string $originalMessage
 * @param string $replyMessage
 * @param string $staffName
 * @return bool Success status
 */
function sendContactReply($toEmail, $toName, $concernType, $originalMessage, $replyMessage, $staffName) {
    $body = getEmailTemplate('contact_reply', [
        'userName'        => htmlspecialchars($toName, ENT_QUOTES, 'UTF-8'),
        'concernType'     => htmlspecialchars($concernType, ENT_QUOTES, 'UTF-8'),
        'originalMessage' => htmlspecialchars($originalMessage, ENT_QUOTES, 'UTF-8'),
        'replyMessage'    => nl2br(htmlspecialchars($replyMessage, ENT_QUOTES, 'UTF-8')),
        'staffName'       => htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8'),
    ]);

    $subject = "Re: " . $concernType . " - RMS Support";

    return sendEmail($toEmail, $subject, $body, $toName);
}

/**
 * Get email template with variable substitution
 *
 * @param string $template Template name
 * @param array $vars Variables to substitute
 * @return string HTML email content
 */
function getEmailTemplate($template, $vars = []) {
    $siteName = rms_mail_config('SITE_NAME', 'RMS Research System');
    $siteUrl  = rms_mail_config('SITE_URL', 'http://localhost/rms/');
    $year = date('Y');

    // Base template wrapper
    $baseTemplate = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8fafc; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #111827 0%, #1F2937 100%); padding: 32px 24px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 24px; font-weight: 600; }
        .header .icon { font-size: 48px; margin-bottom: 12px; }
        .content { padding: 32px 24px; color: #1f2937; line-height: 1.6; }
        .content h2 { color: #111827; margin: 0 0 16px 0; font-size: 20px; }
        .content p { margin: 0 0 16px 0; }
        .button { display: inline-block; padding: 14px 32px; background: #C8A44D; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 16px 0; }
        .button:hover { background: #B39340; }
        .info-box { background: #f8fafc; border-left: 4px solid #3B82F6; padding: 16px; margin: 16px 0; border-radius: 8px; }
        .footer { padding: 24px; text-align: center; color: #64748b; font-size: 14px; background: #f8fafc; }
        .footer a { color: #3B82F6; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        {{CONTENT}}
        <div class="footer">
            <p>&copy; {$year} {$siteName}. All rights reserved.</p>
            <p><a href="{$siteUrl}">Visit our website</a></p>
        </div>
    </div>
</body>
</html>
HTML;

    // Template content
    $templates = [
        'verification' => <<<HTML
<div class="header">
    <div class="icon">🔬</div>
    <h1>Verify Your Email</h1>
</div>
<div class="content">
    <h2>Hello {{firstName}}!</h2>
    <p>Welcome to {$siteName}! Please verify your email address to complete your registration.</p>
    <p style="text-align: center;">
        <a href="{{verifyLink}}" class="button">Verify Email Address</a>
    </p>
    <p style="font-size: 14px; color: #64748b;">This link will expire in {{expiresIn}}.</p>
    <div class="info-box">
        <strong>Didn't register?</strong> You can safely ignore this email.
    </div>
</div>
HTML,

        'approval' => <<<HTML
<div class="header">
    <div class="icon">✅</div>
    <h1>Account Approved</h1>
</div>
<div class="content">
    <h2>Hello {{firstName}}!</h2>
    <p>Great news! Your {{role}} account has been approved by an administrator.</p>
    <p>You can now log in and start using the Research Management System.</p>
    <p style="text-align: center;">
        <a href="{{loginLink}}" class="button">Log In Now</a>
    </p>
</div>
HTML,

        'pending_approval' => <<<HTML
<div class="header">
    <div class="icon">📋</div>
    <h1>New Registration Pending</h1>
</div>
<div class="content">
    <h2>Hello {{adminName}}!</h2>
    <p>A new {{role}} has registered and requires your approval:</p>
    <div class="info-box">
        <strong>Name:</strong> {{firstName}} {{lastName}}<br>
        <strong>Email:</strong> {{email}}<br>
        <strong>Role:</strong> {{role}}
    </div>
    <p style="text-align: center;">
        <a href="{{managementLink}}" class="button">Review Registration</a>
    </p>
</div>
HTML,

        'status_change' => <<<HTML
<div class="header">
    <div class="icon">🔄</div>
    <h1>Research Status Update</h1>
</div>
<div class="content">
    <h2>Hello {{firstName}}!</h2>
    <p>Your research project status has been updated:</p>
    <div class="info-box">
        <strong>Research:</strong> {{researchTitle}}<br>
        <strong>Previous Status:</strong> {{oldStatus}}<br>
        <strong>New Status:</strong> {{newStatus}}
    </div>
    {{#if comments}}
    <p><strong>Comments from reviewer:</strong></p>
    <p style="background: #f8fafc; padding: 16px; border-radius: 8px;">{{comments}}</p>
    {{/if}}
    <p style="text-align: center;">
        <a href="{{dashboardLink}}" class="button">View Research</a>
    </p>
</div>
HTML,

        'contact_reply' => <<<HTML
<div class="header">
    <div class="icon">💬</div>
    <h1>Response to Your Inquiry</h1>
</div>
<div class="content">
    <h2>Hello {{userName}}!</h2>
    <p>Thank you for contacting {$siteName}. We've reviewed your inquiry and are responding below.</p>

    <div class="info-box">
        <strong>Your Inquiry ({{concernType}}):</strong>
        <p style="margin: 8px 0 0 0; color: #64748b;">{{originalMessage}}</p>
    </div>

    <div style="background: #e3f2fd; border-left: 4px solid #3B82F6; padding: 16px; margin: 16px 0; border-radius: 8px;">
        <strong style="color: #1F2937;">Our Response:</strong>
        <p style="margin: 8px 0 0 0;">{{replyMessage}}</p>
        <p style="margin: 16px 0 0 0; font-size: 14px; color: #64748b;">— {{staffName}}</p>
    </div>

    <p style="font-size: 14px; color: #64748b;">If you have additional questions, please feel free to reply to this email or submit another inquiry through our website.</p>
</div>
HTML
    ];

    if (!isset($templates[$template])) {
        return '';
    }

    $content = $templates[$template];

    // Simple variable substitution
    foreach ($vars as $key => $value) {
        $content = str_replace('{{' . $key . '}}', $value, $content);
    }

    // Handle conditional blocks (simple if statements)
    $content = preg_replace('/\{\{#if \w+\}\}.*?\{\{\/if\}\}/s', function($matches) use ($vars) {
        preg_match('/\{\{#if (\w+)\}\}(.*?)\{\{\/if\}\}/s', $matches[0], $parts);
        $varName = $parts[1];
        $block = $parts[2];
        return !empty($vars[$varName]) ? $block : '';
    }, $content);

    return str_replace('{{CONTENT}}', $content, $baseTemplate);
}
