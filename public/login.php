<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// If already logged in, redirect straight to their dashboard
if (isLoggedIn()) {
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

$error = '';
$success = '';
$selectedRole = 'student';
$validLoginRoles = ['student', 'faculty', 'research_staff', 'admin'];

$roleLabels = [
    'student'        => 'Student',
    'faculty'        => 'Faculty Adviser',
    'research_staff' => 'Research Staff',
    'admin'          => 'Administrator',
];

if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please log in again.';
}

function loginFailureKey() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return md5($ip . '|' . session_id());
}

function isLoginLocked() {
    $key = loginFailureKey();

    if (empty($_SESSION['login_locked_until'][$key])) {
        return false;
    }

    if ($_SESSION['login_locked_until'][$key] > time()) {
        return true;
    }

    unset($_SESSION['login_locked_until'][$key]);
    unset($_SESSION['login_failures'][$key]);

    return false;
}

function recordFailedLogin() {
    $key = loginFailureKey();

    if (!isset($_SESSION['login_failures'])) {
        $_SESSION['login_failures'] = [];
    }

    if (!isset($_SESSION['login_locked_until'])) {
        $_SESSION['login_locked_until'] = [];
    }

    $_SESSION['login_failures'][$key] = ($_SESSION['login_failures'][$key] ?? 0) + 1;

    if ($_SESSION['login_failures'][$key] >= 5) {
        $_SESSION['login_locked_until'][$key] = time() + (15 * 60);
        $_SESSION['login_failures'][$key] = 0;
    }
}

function resetFailedLogins() {
    $key = loginFailureKey();
    unset($_SESSION['login_failures'][$key]);
    unset($_SESSION['login_locked_until'][$key]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $error = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? sanitize($_POST['action']) : '';

    // LOGIN
    if ($action === 'login') {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Preserve the user's chosen tab on any error path below, so it
        // doesn't snap back to Student.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
            $postedRole = strtolower((string) ($_POST['role'] ?? ''));
            if (in_array($postedRole, $validLoginRoles, true)) {
                $selectedRole = $postedRole;
            }
        }

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif (isLoginLocked()) {
            $remaining = max(1, ceil(($_SESSION['login_locked_until'][loginFailureKey()] - time()) / 60));
            $error = "Too many failed attempts. Please try again in {$remaining} minute(s).";
        } else {
            $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, status FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if (verifyPassword($password, $user['password'])) {
                    if ($user['status'] !== 'active') {
                        $error = 'Your account is pending administrator approval or suspended.';
                    } else {
                        $dbRole = strtolower((string) $user['role']);
                        $validRoles = ['student', 'faculty', 'research_staff', 'admin'];

                        if (!in_array($dbRole, $validRoles, true)) {
                            recordFailedLogin();
                            $error = 'Invalid email, password, or role.';
                        } else {
                            // Read the role tab the user clicked. Validate strictly
                            // against the four allowed values; anything else is
                            // treated as a malformed POST and falls back to the
                            // default tab.
                            $postedRole = strtolower((string) ($_POST['role'] ?? ''));
                            if (!in_array($postedRole, $validLoginRoles, true)) {
                                $postedRole = 'student';
                            }

                            // Gate: the tab must match the DB role. If not, show a
                            // helpful, specific error that names the correct tab.
                            // This is NOT a failed login attempt — we do not
                            // increment the lockout counter, log activity, set
                            // the session, or redirect. The user simply has to
                            // click the right tab and try again.
                            if ($postedRole !== $dbRole) {
                                $label    = $roleLabels[$dbRole] ?? 'a different role';
                                $tabWord  = $roleLabels[$dbRole] ?? ucfirst($dbRole);
                                $error    = "This account is registered as {$label}. Please click the {$tabWord} tab and sign in again.";
                                $selectedRole = $dbRole; // auto-highlight the correct tab
                                // Password is intentionally NOT re-rendered into
                                // the form (security: never echo passwords back).
                            } else {
                                resetFailedLogins();
                                session_regenerate_id(true);

                                $_SESSION['user_id'] = (int) $user['user_id'];
                                $_SESSION['email']   = $user['email'];
                                $_SESSION['name']    = $user['first_name'] . ' ' . $user['last_name'];
                                $_SESSION['role']    = $dbRole;
                                $_SESSION['last_activity'] = time();

                                $updateStmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?');
                                $updateStmt->bind_param('i', $user['user_id']);
                                $updateStmt->execute();

                                logActivity('User logged in', 'authentication');

                                $dashboardMap = [
                                    'student'        => '../pages/student/student-dashboard.php',
                                    'faculty'        => '../pages/faculty/faculty-dashboard.php',
                                    'research_staff' => '../pages/staff/staff-dashboard.php',
                                    'admin'          => '../pages/admin/admin-dashboard.php',
                                ];

                                $dashboard = $dashboardMap[$dbRole] ?? '../pages/student/student-dashboard.php';
                                header('Location: ' . $dashboard);
                                exit();
                            }
                        }
                    }
                } else {
                    recordFailedLogin();
                    $error = 'Invalid email, password, or role.';
                }
            } else {
                recordFailedLogin();
                $error = 'Invalid email, password, or role.';
            }
        }
    }

    // REGISTER
    if ($action === 'register') {
        $first_name = isset($_POST['first_name']) ? sanitize($_POST['first_name']) : '';
        $last_name = isset($_POST['last_name']) ? sanitize($_POST['last_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
        $student_id = isset($_POST['student_id']) ? sanitize($_POST['student_id']) : '';
        $contact = isset($_POST['contact']) ? sanitize($_POST['contact']) : '';
        $role = isset($_POST['role']) ? sanitize($_POST['role']) : 'student';

        // Role-specific fields
        $department = isset($_POST['department']) ? sanitize($_POST['department']) : null;
        $program = isset($_POST['program']) ? sanitize($_POST['program']) : null;
        $year_level = isset($_POST['year_level']) ? sanitize($_POST['year_level']) : null;
        $specialization = isset($_POST['specialization']) ? sanitize($_POST['specialization']) : null;
        $academic_rank = isset($_POST['academic_rank']) ? sanitize($_POST['academic_rank']) : null;
        $is_reviewer = isset($_POST['is_reviewer']) ? 1 : 0;
        $office = isset($_POST['office']) ? sanitize($_POST['office']) : null;

        $allowedRoles = ['student', 'faculty'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'student';
        }

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one letter and one number';
        } elseif (preg_match('/\s/', $password)) {
            $error = 'Password cannot contain spaces';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match';
        } else {
            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();

            if ($checkRes && $checkRes->num_rows > 0) {
                $error = 'Email already registered';
            } else {
                $password_hash = hashPassword($password);

                // Students get auto-approved, faculty/staff need admin approval
                $status = ($role === 'student') ? 'active' : 'pending';

                $insertStmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, student_id, contact, role, department, program, year_level, specialization, academic_rank, is_reviewer, office, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $insertStmt->bind_param('sssssssssssssss', $first_name, $last_name, $email, $password_hash, $student_id, $contact, $role, $department, $program, $year_level, $specialization, $academic_rank, $is_reviewer, $office, $status);

                if ($insertStmt->execute()) {
                    $userId = $conn->insert_id;

                    // Send email verification
                    require_once __DIR__ . '/../includes/email.php';
                    sendVerificationEmail($userId, $email, $first_name);

                    if ($role === 'student') {
                        $success = 'Registration successful! Please check your email to verify your account. You can now log in.';
                    } else {
                        // Notify admins about pending approval
                        sendPendingApprovalNotification($first_name, $last_name, $email, $role);
                        $success = 'Registration successful! Please check your email to verify your account. An administrator will review and approve your account shortly.';
                    }

                    $_POST = [];
                } else {
                    $error = 'Registration failed. Please try again.';
                }
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
  <title>Login — <?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* ============================================================
       SPLIT-SCREEN LOGIN LAYOUT
       Replaces the old .auth-container / .auth-card centered card.
       The right panel keeps the .auth-card class (form-card styling)
       but is now scoped under .auth-split so dark-theme form styles
       from style.css can be overridden with a light palette here
       without affecting other pages that use the same classes.
       ============================================================ */

    /* Full-bleed reset for this page only */
    html, body { height: 100%; margin: 0; }
    body { background: linear-gradient(135deg, #0A0833 0%, #1a0a3a 50%, #0a1a3a 100%); }

    /* Two-column grid: brand | form.
       position:relative so the absolute-positioned .auth-split-divider-line
       anchors to the grid bounding box (not the viewport). */
    .auth-split {
      display: grid;
      grid-template-columns: 55fr 45fr;
      min-height: 100vh;
      width: 100%;
      position: relative;
    }

    /* LEFT — branding panel */
    .auth-split-brand {
      position: relative;
      background: linear-gradient(135deg, #0A0833 0%, #1a0a3a 50%, #0a1a3a 100%);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 60px 48px;
      overflow: hidden;
    }
    /* Subtle radial purple glow behind the logo */
    .auth-split-brand::before {
      content: '';
      position: absolute;
      top: 20%;
      left: 50%;
      width: 520px;
      height: 520px;
      max-width: 90%;
      transform: translate(-50%, -50%);
      background: radial-gradient(circle at center, rgba(91,30,188,0.35) 0%, rgba(91,30,188,0.12) 40%, transparent 70%);
      pointer-events: none;
      z-index: 0;
    }
    .auth-split-brand-inner {
      position: relative;
      z-index: 1;
      max-width: 480px;
      width: 100%;
      text-align: center;
    }
    .auth-split-logo {
      display: block;
      width: 400px;
      height: auto;
      object-fit: contain;
      margin: 0 auto 24px;
      filter: drop-shadow(0 10px 32px rgba(91,30,188,0.55));
    }
    .auth-split-title {
      color: #ffffff;
      font-size: 2.2rem;
      font-weight: 700;
      margin: 0 0 8px 0;
      letter-spacing: -0.5px;
      line-height: 1.2;
    }
    .auth-split-subtitle {
      color: rgba(255,255,255,0.7);
      font-size: 1.05rem;
      font-weight: 500;
      margin: 0 0 28px 0;
    }
    .auth-split-divider {
      width: 60px;
      height: 2px;
      margin: 0 auto 28px;
      border-radius: 2px;
      background: linear-gradient(90deg, var(--primary), var(--secondary));
    }
    .auth-split-tagline {
      color: rgba(255,255,255,0.65);
      font-size: 0.95rem;
      line-height: 1.6;
      max-width: 360px;
      margin: 0 auto;
    }
    /* Institutional badge under the tagline */
    .auth-split-badge {
      display: inline-block;
      margin-top: 20px;
      padding: 6px 14px;
      background: rgba(255,255,255,0.08);
      color: rgba(255,255,255,0.75);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 50px;
      font-size: 0.78rem;
      font-weight: 500;
      letter-spacing: 0.3px;
    }

    /* RIGHT — form panel (soft lavender to harmonize with the dark left) */
    .auth-split-form {
      background: linear-gradient(180deg, #F8F4FF 0%, #EDE5FA 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 60px 56px;
      overflow-y: auto;
      position: relative; /* anchor for the ::before purple wash blend zone */
      /* Subtle inset shadow on the left edge so the panel boundary feels
         intentional, not a hard color jump. */
      box-shadow: inset 6px 0 18px -8px rgba(91,30,188,0.18);
    }
    /* Purple wash blend zone — a 60px-wide gradient just inside the right
       panel's left edge that fades the lavender into a faint purple tint,
       making the dark→lavender boundary feel soft and connected. */
    .auth-split-form::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 60px;
      height: 100%;
      background: linear-gradient(90deg, rgba(91,30,188,0.08) 0%, rgba(91,30,188,0) 100%);
      pointer-events: none;
      z-index: 1;
    }
    .auth-split-form-inner {
      width: 100%;
      max-width: 480px;
    }

    /* The form card — overrides dark-theme defaults from style.css.
       Card is white and floats on the lavender panel with a soft purple
       shadow. */
    .auth-split-form .auth-card {
      background: #FEFCFF;
      border: 1px solid #EAE0F5;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(91,30,188,0.15);
      backdrop-filter: none;
      padding: 32px 28px;
      width: 100%;
      max-width: 100%;
    }

    /* Section header inside the form card (replaces the old emoji logo header) */
    .auth-split-form .auth-header { text-align: left; margin-bottom: 24px; }
    .auth-split-form .auth-header h1 {
      color: #1e1b3a;
      font-size: 1.4rem;
      font-weight: 700;
      margin: 0 0 4px 0;
    }
    .auth-split-form .auth-header p {
      color: #6b5ca5;
      font-size: 0.9rem;
      margin: 0;
    }
    .auth-split-form .auth-logo { display: none; }

    /* Form controls: purple-tinted overrides */
    .auth-split-form .form-label {
      color: #4c3a91;
      font-size: 0.85rem;
      font-weight: 500;
    }
    .auth-split-form .form-control {
      background: #ffffff;
      border: 1px solid #DDD0F5;
      color: #1e1b3a;
    }
    .auth-split-form .form-control::placeholder { color: #9b8fc4; }
    .auth-split-form .form-control:focus {
      border-color: var(--primary);
      background: #ffffff;
      box-shadow: 0 0 0 3px rgba(91,30,188,0.18);
    }
    .auth-split-form .form-control option {
      background: #ffffff;
      color: #1e1b3a;
    }
    .auth-split-form .form-control option:hover { background: var(--primary); color: #ffffff; }
    .auth-split-form .form-check { color: #4B5563; }
    .auth-split-form .form-check input { accent-color: var(--primary); }

    /* Role tabs in the light panel — soft lavender track, deeper text.
       The PHP loop emits inline style="background: transparent; color: #D0D3E8"
       on inactive tabs (and var(--primary)/white on the active one). We use
       :not(.active) with !important to override only the INACTIVE tabs while
       leaving the active tab's inline var(--primary) untouched. */
    .auth-split-form .role-tab:not(.active) {
      background: #EDE5FA !important;
      color: #6b5ca5 !important;
    }
    .auth-split-form .role-tab:not(.active):hover {
      background: #DDD0F5 !important;
      color: #1e1b3a !important;
    }
    .auth-split-form [style*="grid-template-columns: repeat(4, 1fr)"] {
      background: #EDE5FA !important;
    }

    /* Demo credentials box — soft purple tint to harmonize with the panel */
    .auth-split-form .demo-creds {
      background: rgba(91,30,188,0.08) !important;
      border: 1px solid rgba(91,30,188,0.18) !important;
      color: #4c3a91 !important;
    }
    .auth-split-form .demo-creds strong { color: #4c3a91 !important; font-weight: 700; }
    .auth-split-form .demo-creds span,
    .auth-split-form .demo-creds { color: #4c3a91 !important; }

    /* Forgot password link — stays purple (already on brand) */
    .auth-split-form a[href="#forgot"] { color: var(--primary) !important; }

    /* "Create one" / "Sign In" / "or" links — lavender muted + purple CTA */
    .auth-split-form .auth-link-light { color: #6b5ca5 !important; }
    .auth-split-form a[onclick] { color: var(--primary) !important; font-weight: 600; }
    .auth-split-form .auth-or { color: #9b8fc4 !important; }

    /* "Back to homepage" link */
    .auth-split-form .back-home {
      display: inline-block;
      padding: 10px 14px;
      background: transparent !important;
      color: #4c3a91 !important;
      border: 1px solid #DDD0F5 !important;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .auth-split-form .back-home:hover { background: rgba(91,30,188,0.06) !important; }

    /* Password show/hide button — dark glyph on light field */
    .auth-split-form #togglePasswordBtn { color: #6B7280 !important; }

    /* Submit button — keep primary, ensure good contrast */
    .auth-split-form .btn-primary { width: 100%; justify-content: center; padding: 12px; }

    /* Alerts in light theme */
    .auth-split-form .alert { border-radius: 10px; }
    .auth-split-form .alert-error {
      background: #FEF2F2 !important;
      color: #991B1B !important;
      border: 1px solid #FECACA !important;
    }
    .auth-split-form .alert-error span { color: #DC2626 !important; }
    .auth-split-form .alert-success {
      background: #F0FDF4 !important;
      color: #166534 !important;
      border: 1px solid #BBF7D0 !important;
    }
    .auth-split-form .alert-success span { color: #15803D !important; }

    /* ============================================================
       ANIMATED VERTICAL DIVIDER + ENTRANCE ANIMATIONS
       ============================================================ */

    /* The vertical divider line at the seam between the two panels.
       Sits exactly at the 55% / 45% grid boundary. */
    .auth-split-divider-line {
      position: absolute;
      top: 12%;
      bottom: 12%;
      left: calc(55% - 2px);
      width: 3px;
      border-radius: 3px;
      z-index: 5;
      pointer-events: none;
      background: linear-gradient(180deg,
        rgba(91,30,188,0) 0%,
        rgba(91,30,188,0.8) 15%,
        rgba(15,108,189,0.9) 50%,
        rgba(245,124,0,0.8) 85%,
        rgba(245,124,0,0) 100%);
      box-shadow:
        0 0 18px 2px rgba(91,30,188,0.45),
        0 0 40px 6px rgba(15,108,189,0.2);
    }
    /* Energy-flow highlight — a 60px-tall bright streak that travels
       up and down the divider continuously. */
    .auth-split-divider-line::after {
      content: '';
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 2px;
      height: 60px;
      border-radius: 2px;
      background: linear-gradient(180deg,
        rgba(255,255,255,0) 0%,
        rgba(255,255,255,0.95) 50%,
        rgba(91,30,188,0.85) 100%);
      filter: blur(0.4px);
    }

    /* All animations + entrance effects gated behind reduced-motion
       check so users who prefer reduced motion get the static design. */
    @media (prefers-reduced-motion: no-preference) {
      /* Page-load entrance: divider draws itself from center. */
      .auth-split-divider-line {
        transform: scaleY(0);
        transform-origin: center center;
        animation:
          rms-divider-draw 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.15s forwards,
          rms-divider-pulse 4s ease-in-out 1.25s infinite;
      }
      /* Energy flow loop, starts after the divider has drawn. */
      .auth-split-divider-line::after {
        animation: rms-divider-flow 6s ease-in-out 1.25s infinite;
      }
    }

    /* Keyframes */
    @keyframes rms-divider-draw {
      0%   { transform: scaleY(0); }
      100% { transform: scaleY(1); }
    }
    @keyframes rms-divider-pulse {
      0%, 100% {
        box-shadow:
          0 0 18px 2px rgba(91,30,188,0.45),
          0 0 40px 6px rgba(15,108,189,0.2);
      }
      50% {
        box-shadow:
          0 0 30px 4px rgba(91,30,188,0.6),
          0 0 60px 10px rgba(15,108,189,0.35);
      }
    }
    @keyframes rms-divider-flow {
      0%   { transform: translate(-50%, -100%); opacity: 0; }
      10%  { opacity: 1; }
      50%  { transform: translate(-50%, 200%); opacity: 1; }
      60%  { opacity: 0; }
      100% { transform: translate(-50%, 200%); opacity: 0; }
    }

    /* Brand content fade-in (staggered children of brand-inner).
       Each child has its own animation-delay so they appear sequentially. */
    @media (prefers-reduced-motion: no-preference) {
      .auth-split-brand-inner > * {
        opacity: 0;
        transform: translateY(8px);
        animation: rms-content-fade 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
      }
      .auth-split-brand-inner > .auth-split-logo     { animation-delay: 0.10s; }
      .auth-split-brand-inner > .auth-split-title    { animation-delay: 0.20s; }
      .auth-split-brand-inner > .auth-split-subtitle { animation-delay: 0.30s; }
      .auth-split-brand-inner > .auth-split-divider  { animation-delay: 0.40s; }
      .auth-split-brand-inner > .auth-split-tagline  { animation-delay: 0.50s; }
      .auth-split-brand-inner > .auth-split-badge    { animation-delay: 0.60s; }

      /* Form card slides in from the right after the brand has settled. */
      .auth-split-form-inner {
        opacity: 0;
        transform: translateX(20px);
        animation: rms-card-slide 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.30s forwards;
      }
    }
    @keyframes rms-content-fade {
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes rms-card-slide {
      to { opacity: 1; transform: translateX(0); }
    }

    /* Tablet — collapse to single column, brand on top, form below.
       The brand panel's lavender-context transition is handled by simply
       showing the brand panel above (its own dark background) and the
       form panel below (its lavender background). The hard color
       transition is fine here because they're stacked, not side-by-side. */
    @media (max-width: 899px) {
      .auth-split { grid-template-columns: 1fr; }
      .auth-split-brand { min-height: 280px; padding: 40px 24px; }
      .auth-split-brand::before { width: 360px; height: 360px; }
      .auth-split-logo { width: 110px; margin-bottom: 16px; }
      .auth-split-title { font-size: 1.6rem; margin-bottom: 6px; }
      .auth-split-subtitle { font-size: 0.95rem; margin-bottom: 16px; }
      .auth-split-divider { margin-bottom: 16px; }
      .auth-split-tagline { font-size: 0.9rem; }
      .auth-split-form { padding: 32px 24px; box-shadow: none; }
      /* The vertical divider is between two columns that no longer exist
         on mobile. Hide it and the lavender-side purple wash so the
         stacked layout doesn't have a stray seam effect. */
      .auth-split-divider-line { display: none; }
      .auth-split-form::before { display: none; }
    }

    /* Phone — even tighter */
    @media (max-width: 479px) {
      .auth-split-brand { min-height: 240px; padding: 32px 20px; }
      .auth-split-brand::before { width: 280px; height: 280px; }
      .auth-split-logo { width: 100px; }
      .auth-split-title { font-size: 1.4rem; }
      .auth-split-form { padding: 24px 18px; }
      .auth-split-form .auth-card { padding: 24px 20px; }
    }
  </style>
</head>
<body>

<div class="auth-split">
  <!-- LEFT: branding panel (shared by login + register) -->
  <aside class="auth-split-brand" aria-label="Branding">
    <div class="auth-split-brand-inner">
      <img class="auth-split-logo" src="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>photos/rms-logo.png" alt="EARIST Research Management System logo">
      <h1 class="auth-split-title">Research Management System</h1>
      <p class="auth-split-subtitle">EARIST Cavite Campus</p>
      <div class="auth-split-divider" aria-hidden="true"></div>
      <p class="auth-split-tagline">Sign in to access your research dashboard.</p>
      <div class="auth-split-badge">📜 EARIST Research Manual 2015</div>
    </div>
  </aside>

  <!-- Animated vertical divider at the seam between brand and form panels.
       aria-hidden because it's purely decorative; CSS-only animation. -->
  <div class="auth-split-divider-line" aria-hidden="true"></div>

  <!-- RIGHT: form panel -->
  <section class="auth-split-form">
    <div class="auth-split-form-inner">
      <div id="loginForm" class="auth-card" style="display: block;">
        <div class="auth-header">
          <h1>Welcome Back</h1>
          <p>Sign in to your account</p>
        </div>

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

        <div class="demo-creds" style="background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.78rem; color: #93c5fd; line-height: 1.6;">
          <strong style="color: #bfdbfe;">🔑 Demo Credentials</strong><br>
          Student: jdelacruz@rms.edu.ph / Student@123<br>
          Faculty: msantos@rms.edu.ph / Faculty@123<br>
          Staff: staff@rms.edu.ph / Staff@123<br>
          Admin: admin@rms.edu.ph / Admin@123
        </div>

        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 24px; background: rgba(255,255,255,0.06); border-radius: 8px; padding: 4px;">
          <?php
          $tabs = [
              'student'        => '🎓 Student',
              'faculty'        => '👨‍🏫 Faculty',
              'research_staff' => '📋 Staff',
              'admin'          => '⚙️ Admin',
          ];
          foreach ($tabs as $tabKey => $tabLabel):
              $isActive = ($selectedRole === $tabKey);
              $bg       = $isActive ? 'var(--primary)' : 'transparent';
              $fg       = $isActive ? 'white' : '#D0D3E8';
              $weight   = $isActive ? '600' : '500';
              $cls      = 'role-tab' . ($isActive ? ' active' : '');
          ?>
          <button type="button" class="<?php echo $cls; ?>" data-role="<?php echo htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8'); ?>" onclick="switchRole('<?php echo htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8'); ?>')" style="padding: 10px; background: <?php echo $bg; ?>; color: <?php echo $fg; ?>; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: <?php echo $weight; ?>;"><?php echo $tabLabel; ?></button>
          <?php endforeach; ?>
        </div>

        <form method="POST" action="login.php">
          <input type="hidden" name="action" value="login">
          <?php echo csrfField(); ?>
          <input type="hidden" name="role" id="role" value="<?php echo htmlspecialchars($selectedRole, ENT_QUOTES, 'UTF-8'); ?>">

          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" id="loginEmail" class="form-control" placeholder="Enter your email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Password</label>
            <div style="position: relative;">
              <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Enter your password" required>
              <button type="button" id="togglePasswordBtn" onclick="togglePassword('passwordInput','togglePasswordBtn')" style="position:absolute; right: 10px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #94a3b8; cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                Show
              </button>
            </div>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; font-size: 0.85rem;">
            <label class="form-check">
              <input type="checkbox"> Remember me
            </label>
            <a href="#forgot" style="color: var(--primary-light); font-weight: 500;">Forgot Password?</a>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
            🔑 Sign In
          </button>
        </form>

        <p style="text-align:center; margin-top:12px;">
          <a href="index.php" class="back-home">← Back to homepage</a>
        </p>

        <div class="auth-or" style="text-align: center; margin: 20px 0; color: #8B8FAD; font-size: 0.85rem;">
          or
        </div>

        <p class="auth-link-light" style="text-align: center; color: #8B8FAD; font-size: 0.85rem;">
          Don't have an account?
          <a href="#" onclick="switchToRegister()" style="color: var(--primary-light); font-weight: 600;">Create one</a>
        </p>
      </div>

      <div id="registerForm" class="auth-card" style="display: none;">
        <div class="auth-header">
          <h1>Create Account</h1>
          <p>Join the Research Management System</p>
        </div>

        <form method="POST" action="login.php" id="registrationForm">
          <input type="hidden" name="action" value="register">
          <?php echo csrfField(); ?>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label">First Name</label>
              <input type="text" name="first_name" class="form-control" placeholder="Juan" required value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Last Name</label>
              <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz" required value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" placeholder="you@earist.edu.ph" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" id="registerRole" class="form-control" required onchange="updateRegistrationFields()" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
              <option value="" disabled selected style="background: #0F1729; color: #8B8FAD;">Choose a role</option>
              <option value="student" style="background: #1a1a2e; color: #ffffff; padding: 12px;">🎓 Student</option>
              <option value="faculty" style="background: #1a1a2e; color: #ffffff; padding: 12px;">👨‍🏫 Faculty/Adviser</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Student/Employee ID</label>
            <input type="text" name="student_id" id="studentEmployeeId" class="form-control" placeholder="e.g. 2024-00001" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input type="tel" name="contact" class="form-control" placeholder="09XX XXX XXXX" value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <!-- STUDENT-SPECIFIC FIELDS -->
          <div id="studentFields" style="display: none;">
            <div class="form-group">
              <label class="form-label">College/Department</label>
              <select name="department" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                <option value="">Select college</option>
                <option value="College of Information and Communications Technology">CICT</option>
                <option value="College of Engineering">College of Engineering</option>
                <option value="College of Arts and Sciences">College of Arts and Sciences</option>
                <option value="College of Education">College of Education</option>
                <option value="Graduate School">Graduate School</option>
              </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div class="form-group">
                <label class="form-label">Program/Course</label>
                <input type="text" name="program" class="form-control" placeholder="e.g. BSIT, BSCS">
              </div>
              <div class="form-group">
                <label class="form-label">Year Level</label>
                <select name="year_level" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                  <option value="">Select year</option>
                  <option value="1st">1st Year</option>
                  <option value="2nd">2nd Year</option>
                  <option value="3rd">3rd Year</option>
                  <option value="4th">4th Year</option>
                  <option value="Graduate">Graduate</option>
                  <option value="Masters">Master's</option>
                  <option value="Doctorate">Doctorate</option>
                </select>
              </div>
            </div>
          </div>

          <!-- FACULTY-SPECIFIC FIELDS -->
          <div id="facultyFields" style="display: none;">
            <div class="form-group">
              <label class="form-label">Department</label>
              <select name="department" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                <option value="">Select department</option>
                <option value="Information Technology">Information Technology</option>
                <option value="Computer Science">Computer Science</option>
                <option value="Engineering">Engineering</option>
                <option value="Education">Education</option>
                <option value="Arts and Sciences">Arts and Sciences</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Specialization/Field of Expertise</label>
              <input type="text" name="specialization" class="form-control" placeholder="e.g. Data Science, Software Engineering">
            </div>

            <div class="form-group">
              <label class="form-label">Academic Rank</label>
              <select name="academic_rank" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                <option value="">Select rank</option>
                <option value="Instructor">Instructor</option>
                <option value="Assistant Professor">Assistant Professor</option>
                <option value="Associate Professor">Associate Professor</option>
                <option value="Professor">Professor</option>
                <option value="Dean">Dean</option>
                <option value="Director">Director</option>
              </select>
            </div>

            <div class="form-check" style="margin-bottom: 16px;">
              <input type="checkbox" name="is_reviewer" id="isReviewer" value="1">
              <label for="isReviewer" style="color: #D0D3E8; font-size: 0.85rem;">I'm willing to participate in CREC/EREC reviews</label>
            </div>
          </div>

          <!-- STAFF-SPECIFIC FIELDS -->
          <div id="staffFields" style="display: none;">
            <div class="form-group">
              <label class="form-label">Office Assignment</label>
              <select name="office" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                <option value="">Select office</option>
                <option value="Office of Research Services">Office of Research Services (ORS)</option>
                <option value="CREC Office">CREC Office</option>
                <option value="EREC Office">EREC Office</option>
                <option value="Graduate School Office">Graduate School Office</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Department</label>
              <input type="text" name="department" class="form-control" placeholder="e.g. Research Management">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required>
            </div>
            <div class="form-group">
              <label class="form-label">Confirm Password</label>
              <input type="password" name="password_confirm" class="form-control" placeholder="Repeat password" required>
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 16px;">
            ✨ Create Account
          </button>
        </form>

        <p class="auth-link-light" style="text-align: center; color: #8B8FAD; font-size: 0.85rem;">
          Already have an account?
          <a href="#" onclick="switchToLogin()" style="color: var(--primary-light); font-weight: 600;">Sign In</a>
        </p>
      </div>
    </div>
  </section>
</div>

<script>
function switchRole(role) {
  const roleInput = document.getElementById('role');
  const tabs = document.querySelectorAll('.role-tab');

  switch (role) {
    case 'student':
    case 'faculty':
    case 'research_staff':
    case 'admin':
      if (roleInput) {
        roleInput.value = role;
      }
      break;
    default:
      return;
  }

  tabs.forEach((btn) => {
    const isActive = btn.getAttribute('data-role') === role;
    btn.style.background = isActive ? 'var(--primary)' : 'transparent';
    btn.style.color = isActive ? 'white' : '#D0D3E8';
    btn.style.fontWeight = isActive ? '600' : '500';
  });
}

function switchToRegister() {
  document.getElementById('loginForm').style.display = 'none';
  document.getElementById('registerForm').style.display = 'block';
}

function switchToLogin() {
  document.getElementById('registerForm').style.display = 'none';
  document.getElementById('loginForm').style.display = 'block';
}

function togglePassword(inputId, btnId) {
  const input = document.getElementById(inputId);
  const btn = document.getElementById(btnId);
  if (!input || !btn) return;

  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  btn.textContent = isHidden ? 'Hide' : 'Show';
}

function updateRegistrationFields() {
  const role = document.getElementById('registerRole').value;
  const studentFields = document.getElementById('studentFields');
  const facultyFields = document.getElementById('facultyFields');
  const staffFields = document.getElementById('staffFields');
  const idLabel = document.getElementById('studentEmployeeId');

  // Hide all role-specific fields
  studentFields.style.display = 'none';
  facultyFields.style.display = 'none';
  staffFields.style.display = 'none';

  // Clear required attributes from hidden fields
  studentFields.querySelectorAll('input, select').forEach(el => el.removeAttribute('required'));
  facultyFields.querySelectorAll('input, select').forEach(el => el.removeAttribute('required'));
  staffFields.querySelectorAll('input, select').forEach(el => el.removeAttribute('required'));

  // Show relevant fields based on role
  if (role === 'student') {
    studentFields.style.display = 'block';
    if (idLabel) idLabel.placeholder = 'e.g. 2024-00001 (Student ID)';
  } else if (role === 'faculty') {
    facultyFields.style.display = 'block';
    if (idLabel) idLabel.placeholder = 'e.g. F-2024-001 (Employee ID)';
  } else if (role === 'research_staff') {
    staffFields.style.display = 'block';
    if (idLabel) idLabel.placeholder = 'e.g. S-2024-001 (Employee ID)';
  }
}

if (window.location.hash === '#register') {
  switchToRegister();
}

// Auto-detect role from email as the user types. Unobtrusive: only switches
// the active tab, never moves the cursor or steals focus. The substring
// checks are intentionally simple — exact demo creds are covered, plus
// any email that contains a role keyword (admin/staff/faculty/student).
(function () {
  const emailEl = document.getElementById('loginEmail');
  if (!emailEl) return;

  // Order matters: more specific patterns (e.g. "msantos") are checked
  // before generic ones (e.g. "admin"). The first match wins.
  const patterns = [
    { role: 'admin',          rx: /admin/i },
    { role: 'research_staff', rx: /staff/i },
    { role: 'faculty',        rx: /msantos|jreyes|faculty|adviser/i },
    { role: 'student',        rx: /jdelacruz|areyes|student/i },
  ];

  function detect(email) {
    for (let i = 0; i < patterns.length; i++) {
      if (patterns[i].rx.test(email)) return patterns[i].role;
    }
    return null;
  }

  emailEl.addEventListener('input', function () {
    const v = emailEl.value || '';
    const role = detect(v);
    if (role) {
      switchRole(role);
    }
  });
})();
</script>
</body>
</html>
