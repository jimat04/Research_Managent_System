<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/faculty-ranks.php';
require_once __DIR__ . '/../includes/logout-transition.php';

if (($_GET['continue'] ?? '') === 'public-record') {
    rmsSetPublicRecordReturnIntent($_GET['record_type'] ?? null, $_GET['record_id'] ?? null);
} elseif (isset($_GET['continue'])) {
    rmsClearPublicRecordReturnIntent();
    rmsSetResearchArchiveReturnIntent($_GET['continue']);
}

// If already logged in, redirect straight to their dashboard
if (isLoggedIn()) {
    $publicRecordUrl = rmsPublicRecordReturnUrl();
    if ($publicRecordUrl !== null) {
        rmsClearPublicRecordReturnIntent();
        header('Location: ' . $publicRecordUrl);
        exit();
    }
    $role = $_SESSION['role'] ?? 'student';
    if ($role === 'student' && rmsHasResearchArchiveReturnIntent()) {
        $studentId = (int) ($_SESSION['user_id'] ?? 0);
        $stateStmt = $conn->prepare("SELECT research_basics_completed FROM users WHERE user_id = ? AND role = 'student' AND deleted_at IS NULL LIMIT 1");
        if ($stateStmt) {
            $stateStmt->bind_param('i', $studentId);
            $stateStmt->execute();
            $stateRow = $stateStmt->get_result()->fetch_assoc();
            $stateStmt->close();
            if ((int) ($stateRow['research_basics_completed'] ?? 0) === 1) {
                rmsClearResearchArchiveReturnIntent();
            }
        }
    } elseif ($role !== 'student') {
        rmsClearResearchArchiveReturnIntent();
    }

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

$facultyDepartments = [
    'INFORMATION TECHNOLOGY',
    'COMPUTER SCIENCE',
];
$facultyAcademicRanks = array_keys(rmsFacultyAcademicRanks());

if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please log in again.';
}

function loginFailureKey($email) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return hash('sha256', $ip . '|' . strtolower(trim((string) $email)));
}

function getLoginRateLimit($key) {
    global $conn;
    $stmt = $conn->prepare('SELECT failures, locked_until FROM login_rate_limits WHERE rate_key = ? LIMIT 1');
    if (!$stmt) return ['failures' => 0, 'locked_until' => null];
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['failures' => 0, 'locked_until' => null];
    $stmt->close();
    return $row;
}

function isLoginLocked($email) {
    $row = getLoginRateLimit(loginFailureKey($email));
    return !empty($row['locked_until']) && strtotime($row['locked_until']) > time();
}

function recordFailedLogin($email) {
    global $conn;
    $key = loginFailureKey($email);
    $stmt = $conn->prepare("INSERT INTO login_rate_limits (rate_key, failures, locked_until, updated_at) VALUES (?, 1, NULL, NOW()) ON DUPLICATE KEY UPDATE failures = IF(updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failures + 1), locked_until = IF(failures >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL), updated_at = NOW()");
    if ($stmt) { $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close(); }
}

function resetFailedLogins($email) {
    global $conn;
    $key = loginFailureKey($email);
    $stmt = $conn->prepare('DELETE FROM login_rate_limits WHERE rate_key = ?');
    if ($stmt) { $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $error = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? sanitize($_POST['action']) : '';

    // LOGIN
    if ($action === 'login') {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif (isLoginLocked($email)) {
            $locked = getLoginRateLimit(loginFailureKey($email));
            $remaining = max(1, ceil((strtotime($locked['locked_until']) - time()) / 60));
            $error = "Too many failed attempts. Please try again in {$remaining} minute(s).";
        } else {
            $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, status, email_verified, research_basics_completed FROM users WHERE email = ? LIMIT 1");
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
                            recordFailedLogin($email);
                            $error = 'Invalid email, password, or role.';
                        } else {
                            if (REQUIRE_EMAIL_VERIFICATION && $dbRole !== 'admin' && (int) ($user['email_verified'] ?? 0) !== 1) {
                                $error = 'Please verify your email address before signing in.';
                            } else {
                                resetFailedLogins($email);
                                session_regenerate_id(true);

                                $_SESSION['user_id'] = (int) $user['user_id'];
                                $_SESSION['email']   = $user['email'];
                                $_SESSION['name']    = $user['first_name'] . ' ' . $user['last_name'];
                                $_SESSION['role']    = $dbRole;
                                $_SESSION['auth_password_hash'] = (string) $user['password'];
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
                                $publicRecordUrl = rmsPublicRecordReturnUrl();
                                if ($publicRecordUrl !== null) {
                                    $dashboard = $publicRecordUrl;
                                    rmsClearPublicRecordReturnIntent();
                                    rmsClearResearchArchiveReturnIntent();
                                } elseif ($dbRole === 'student' && rmsHasResearchArchiveReturnIntent()
                                    && (int) ($user['research_basics_completed'] ?? 0) === 1) {
                                    rmsClearResearchArchiveReturnIntent();
                                } elseif ($dbRole !== 'student') {
                                    rmsClearResearchArchiveReturnIntent();
                                }
                                $loginRedirect = htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8');
                                $welcomeName = trim((string) $user['first_name']);
                                $welcomeMessage = $welcomeName !== ''
                                    ? 'Welcome back, ' . $welcomeName . '. Preparing your workspace.'
                                    : 'Welcome back. Preparing your workspace.';
                                ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="2;url=<?php echo htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Login successful | RMS</title>
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body>
    <?php renderLogoutTransition([
        'heading' => 'Login successful',
        'message' => $welcomeMessage,
        'status' => 'Preparing workspace',
        'completion_status' => 'Opening your workspace',
        'caption' => 'Signing in',
        'aria_label' => 'Login successful',
        'redirect_url' => $dashboard,
    ]); ?>
</body>
</html>
                                <?php
                                exit();
                            }
                        }
                    }
                } else {
                    recordFailedLogin($email);
                    $error = 'Invalid email, password, or role.';
                }
            } else {
                recordFailedLogin($email);
                $error = 'Invalid email, password, or role.';
            }
        }
    }

    // REGISTER
    if ($action === 'register' && rmsSetting('maintenance_enabled') === '1') {
        $error = 'New registrations are temporarily unavailable during system maintenance.';
    } elseif ($action === 'register') {
        $first_name = isset($_POST['first_name']) ? sanitize($_POST['first_name']) : '';
        $last_name = isset($_POST['last_name']) ? sanitize($_POST['last_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
        $studentIdInput = $_POST['student_id'] ?? '';
        $student_id = is_string($studentIdInput) ? trim($studentIdInput) : '';
        if ($student_id === '') {
            $student_id = null;
        }
        $contact = isset($_POST['contact']) ? sanitize($_POST['contact']) : '';
        $role = isset($_POST['role']) ? sanitize($_POST['role']) : 'student';

        // Role-specific fields
        $departmentInput = $_POST['department'] ?? null;
        $department = is_string($departmentInput) ? sanitize($departmentInput) : null;
        $program = isset($_POST['program']) ? sanitize($_POST['program']) : null;
        $year_level = isset($_POST['year_level']) ? sanitize($_POST['year_level']) : null;
        $specialization = isset($_POST['specialization']) ? sanitize($_POST['specialization']) : null;
        $academicRankInput = $_POST['academic_rank'] ?? null;
        $academic_rank = is_string($academicRankInput) ? sanitize($academicRankInput) : null;
        $is_reviewer = 0;
        $office = '';

        $allowedRoles = ['student', 'faculty'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'student';
        }

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif ($contact !== '' && !preg_match('/^09[0-9]{9}$/D', $contact)) {
            $error = 'Contact number must contain 11 digits and start with 09.';
        } elseif (($passwordPolicyError = rmsValidatePasswordPolicy($password)) !== null) {
            $error = $passwordPolicyError;
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match';
        } elseif ($role === 'faculty' && (!is_string($departmentInput) || !in_array($departmentInput, $facultyDepartments, true))) {
            $error = 'Please select a valid faculty department.';
        } elseif ($role === 'faculty' && (!is_string($academicRankInput) || !in_array($academicRankInput, $facultyAcademicRanks, true))) {
            $error = 'Please select a valid academic rank.';
        } else {
            if ($role === 'faculty') {
                // Store only exact allowlisted values; the prepared insert below
                // persists these values without escaping or reinterpretation.
                $department = $departmentInput;
                $academic_rank = $academicRankInput;
            }

            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();

            if ($checkRes && $checkRes->num_rows > 0) {
                $error = 'Email already registered';
            } else {
                $duplicateStudentId = false;
                $studentIdCheckFailed = false;
                if ($student_id !== null) {
                    $studentIdStmt = $conn->prepare('SELECT user_id FROM users WHERE student_id = ? LIMIT 1');
                    if (!$studentIdStmt) {
                        $studentIdCheckFailed = true;
                    } else {
                        $studentIdStmt->bind_param('s', $student_id);
                        $studentIdCheckFailed = !$studentIdStmt->execute();
                        if (!$studentIdCheckFailed) {
                            $duplicateStudentId = $studentIdStmt->get_result()->num_rows > 0;
                        }
                        $studentIdStmt->close();
                    }
                }

                if ($studentIdCheckFailed) {
                    $error = 'Could not validate the Student/Employee ID. Please try again.';
                } elseif ($duplicateStudentId) {
                    $error = 'Student/Employee ID already registered.';
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
                        $preserveArchiveIntent = $role === 'student' && rmsHasResearchArchiveReturnIntent();
                        $verificationEmailSent = sendVerificationEmail($userId, $email, $first_name, $preserveArchiveIntent);

                        if ($role === 'student') {
                            $success = $verificationEmailSent
                                ? 'Registration successful! Please verify your email before signing in.'
                                : 'Registration successful, but we could not send the verification email. Use the resend verification link below to try again.';
                        } else {
                            // Notify admins about pending approval
                            sendPendingApprovalNotification($first_name, $last_name, $email, $role);
                            $success = $verificationEmailSent
                                ? 'Registration successful! Please verify your email. An administrator will also review and approve your account.'
                                : 'Registration successful, but we could not send the verification email. Use the resend verification link below to try again. An administrator will review and approve your account.';
                            rmsClearResearchArchiveReturnIntent();
                        }

                        $_POST = [];
                    } else {
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }
}

$publicRecordIntent = rmsPublicRecordReturnIntent();
$authContinuationQuery = $publicRecordIntent
    ? '?' . http_build_query([
        'continue' => 'public-record',
        'record_type' => $publicRecordIntent['type'],
        'record_id' => $publicRecordIntent['id'],
    ])
    : (rmsHasResearchArchiveReturnIntent()
        ? '?continue=' . rawurlencode(RMS_RESEARCH_ARCHIVE_RETURN_VALUE)
        : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login — <?php echo htmlspecialchars(rmsSetting('site_title'), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    html { color-scheme: light; }
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
    /* Faint crosshatch grid texture (research-paper feel, barely visible).
       Sits below the glows so the glows tint on top of the grid, not
       the other way around. */
    .auth-split-brand::before {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      background-image:
        repeating-linear-gradient(0deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 40px),
        repeating-linear-gradient(90deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 40px);
    }
    /* Three off-center soft radial glows, each in a different accent color.
       Stacked as layered background images so they blend into the gradient. */
    .auth-split-brand::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      z-index: 1;
      background:
        radial-gradient(circle at 20% 70%, rgba(91,30,188,0.15) 0%, transparent 45%),
        radial-gradient(circle at 80% 20%, rgba(59,79,138,0.12) 0%, transparent 50%),
        radial-gradient(circle at 60% 85%, rgba(212,166,87,0.10) 0%, transparent 55%);
    }
    .auth-split-brand-inner {
      position: relative;
      z-index: 2;
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
    @keyframes authCtaZoomIn {
      from { transform: scale(.985); }
      to { transform: scale(1); }
    }
    .auth-split.cta-zoom-arrival {
      animation: authCtaZoomIn 1.1s cubic-bezier(.16, 1, .3, 1) both;
      transform-origin: center center;
    }
    @keyframes authFormZoomIn {
      from { opacity: 0; transform: scale(.985); }
      to { opacity: 1; transform: scale(1); }
    }
    .auth-split-form .auth-card.form-entering {
      animation: authFormZoomIn .5s cubic-bezier(.22, 1, .36, 1) both;
    }
    @media (prefers-reduced-motion: reduce) {
      .auth-split.cta-zoom-arrival,
      .auth-split-form .auth-card.form-entering { animation: none; }
    }

    /* Section header inside the form card (replaces the old emoji logo header) */
    .auth-split-form .auth-header { text-align: center; margin-bottom: 24px; }
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
    .auth-split-form .form-control::placeholder { color: #6B5CA5; }
    .auth-split-form .form-control:focus {
      border-color: var(--primary);
      background: #ffffff;
      box-shadow: 0 0 0 3px rgba(91,30,188,0.18);
    }
    .auth-password-field { position: relative; }
    .auth-password-field .form-control { padding-right: 48px; }
    .auth-password-toggle {
      position: absolute;
      top: 50%;
      right: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      padding: 0;
      transform: translateY(-50%);
      border: 0;
      border-radius: 8px;
      background: transparent;
      color: #6b5ca5;
      cursor: pointer;
    }
    .auth-password-toggle:hover { background: rgba(91,30,188,0.08); color: #5B1EBC; }
    .auth-password-toggle:focus-visible { outline: 2px solid #5B1EBC; outline-offset: 2px; }
    .auth-password-toggle svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .auth-password-toggle svg[hidden] { display: none; }
    .auth-password-toggle.is-toggling svg:not([hidden]) { animation: password-eye-toggle 220ms ease-out both; }
    @keyframes password-eye-toggle {
      0% { opacity: 0; transform: scale(.72) rotate(-12deg); }
      70% { opacity: 1; transform: scale(1.12) rotate(3deg); }
      100% { opacity: 1; transform: scale(1) rotate(0); }
    }
    @media (prefers-reduced-motion: reduce) {
      .auth-password-toggle.is-toggling svg:not([hidden]) { animation: none; }
    }

    .auth-split-form .form-control option {
      background: #ffffff;
      color: #1e1b3a;
    }
    .auth-split-form .form-control option:hover { background: var(--primary); color: #ffffff; }
    .auth-split-form .form-check { color: #4B5563; }
    .auth-split-form .form-check input { accent-color: var(--primary); }

    /* Forgot password link — stays purple (already on brand) */
    .auth-split-form a[href="#forgot"] { color: var(--primary) !important; }

    /* "Create one" / "Sign In" / "or" links — lavender muted + purple CTA */
    .auth-split-form .auth-link-light { color: #6b5ca5 !important; }
    .auth-split-form a[onclick] { color: var(--primary) !important; font-weight: 600; }
    .auth-split-form .auth-or { color: #6B5CA5 !important; }

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
          rms-divider-draw 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0s forwards,
          rms-divider-pulse 4s ease-in-out 1.1s infinite;
      }
      /* Energy flow loop, starts after the divider has drawn. */
      .auth-split-divider-line::after {
        animation: rms-divider-flow 6s ease-in-out 1.1s infinite;
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
      .auth-split-brand-inner > .auth-split-logo     { animation-delay: 0s; }
      .auth-split-brand-inner > .auth-split-title    { animation-delay: 0.06s; }
      .auth-split-brand-inner > .auth-split-divider  { animation-delay: 0.12s; }
      .auth-split-brand-inner > .auth-split-tagline  { animation-delay: 0.18s; }

      /* Form card slides in from the right after the brand has settled. */
      .auth-split-form-inner {
        opacity: 0;
        transform: translateX(20px);
        animation: rms-card-slide 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0s forwards;
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
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body>

<div class="auth-split">
  <!-- LEFT: branding panel (shared by login + register) -->
  <aside class="auth-split-brand" aria-label="Branding">
    <div class="auth-split-brand-inner">
      <img class="auth-split-logo" src="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>photos/rms-logo.png" alt="EARIST Research Management System logo">
      <h1 class="auth-split-title"><?php echo htmlspecialchars(rmsSetting('site_name'), ENT_QUOTES, 'UTF-8'); ?></h1>
      <div class="auth-split-divider" aria-hidden="true"></div>
      <p class="auth-split-tagline">Sign in to access your research dashboard.</p>
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
          <h1>Welcome to RMS</h1>
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

        <form method="POST" action="login.php<?php echo $authContinuationQuery; ?>">
          <input type="hidden" name="action" value="login">
          <?php echo csrfField(); ?>

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
            <a href="about-us.php?password_help=1#contact-us" style="color: var(--primary-light); font-weight: 500;">Forgot Password?</a>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
            Sign In
          </button>
        </form>

        <p style="text-align: center; margin-top: 14px; font-size: 0.85rem;">
          <a href="resend-verification.php<?php echo $authContinuationQuery; ?>" style="color: var(--primary-light); font-weight: 600;">Need to verify your email? Resend the verification email</a>
        </p>

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
          <?php if (rmsSetting('maintenance_enabled') === '1'): ?>
            <p role="status">New registrations are temporarily unavailable during system maintenance.</p>
          <?php endif; ?>
        </div>

        <form method="POST" action="login.php<?php echo $authContinuationQuery; ?>" id="registrationForm">
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
              <option value="faculty" style="background: #1a1a2e; color: #ffffff; padding: 12px;">👨‍🏫 Faculty</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Student/Employee ID</label>
            <input type="text" name="student_id" id="studentEmployeeId" class="form-control" placeholder="e.g. 0316-00000C" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input type="tel" name="contact" class="form-control" placeholder="e.g. 09171234567" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" title="Enter 11 digits starting with 09." value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          </div>

          <!-- STUDENT-SPECIFIC FIELDS -->
          <div id="studentFields" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div class="form-group">
                <label class="form-label">Program/Course</label>
                <select name="program" class="form-control">
                  <option value="">Select program/course</option>
                  <option value="BSIT">BSIT (BS Information Technology)</option>
                  <option value="BSCS">BSCS (BS Computer Science)</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Year Level</label>
                <select name="year_level" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                  <option value="">Select year</option>
                  <option value="3rd">3rd Year</option>
                  <option value="4th">4th Year</option>
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
                <?php foreach ($facultyDepartments as $facultyDepartment): ?>
                  <option value="<?php echo htmlspecialchars($facultyDepartment, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($facultyDepartment, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
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
                <?php foreach ($facultyAcademicRanks as $facultyAcademicRank): ?>
                  <option value="<?php echo htmlspecialchars($facultyAcademicRank, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(rmsFacultyAcademicRankLabel($facultyAcademicRank), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

          </div>

          <!-- STAFF-SPECIFIC FIELDS -->
          <div id="staffFields" style="display: none;">
            <div class="form-group">
              <label class="form-label">Office Assignment</label>
              <select name="office" class="form-control" style="background: rgba(255,255,255,0.06); color: white; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%278%27 viewBox=%270 0 12 8%27%3e%3cpath fill=%27%23D0D3E8%27 d=%27M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 40px;">
                <option value="">Select office</option>
                <option value="Office of Research Services">Office of Research Services (ORS)</option>
                <option value="Graduate School Office">Graduate School Office</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Department</label>
              <input type="text" name="staff_department" class="form-control" placeholder="e.g. Research Management">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label">Password</label>
              <div class="auth-password-field">
                <input type="password" name="password" id="registerPassword" class="form-control" minlength="<?php echo (int) rmsSetting('password_min_length'); ?>" maxlength="64" placeholder="Minimum <?php echo (int) rmsSetting('password_min_length'); ?> characters" required autocomplete="new-password">
                <button type="button" class="auth-password-toggle" id="toggleRegisterPasswordBtn" aria-label="Show password" aria-pressed="false" onclick="togglePassword('registerPassword','toggleRegisterPasswordBtn')">
                  <svg class="password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="password-eye-closed" viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.3 3.6-.7"/></svg>
                </button>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Confirm Password</label>
              <div class="auth-password-field">
                <input type="password" name="password_confirm" id="registerPasswordConfirm" class="form-control" placeholder="Repeat password" required autocomplete="new-password">
                <button type="button" class="auth-password-toggle" id="toggleRegisterPasswordConfirmBtn" aria-label="Show password" aria-pressed="false" onclick="togglePassword('registerPasswordConfirm','toggleRegisterPasswordConfirmBtn')">
                  <svg class="password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="password-eye-closed" viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.3 3.6-.7"/></svg>
                </button>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 16px;" <?php echo rmsSetting('maintenance_enabled') === '1' ? 'disabled' : ''; ?>>
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
function switchToRegister() {
  const loginForm = document.getElementById('loginForm');
  const registerForm = document.getElementById('registerForm');
  if (!loginForm || !registerForm) return;

  loginForm.style.display = 'none';
  registerForm.style.display = 'block';
  registerForm.classList.remove('form-entering');
  void registerForm.offsetWidth;
  registerForm.classList.add('form-entering');
}

function switchToLogin() {
  const loginForm = document.getElementById('loginForm');
  const registerForm = document.getElementById('registerForm');
  if (!loginForm || !registerForm) return;

  registerForm.style.display = 'none';
  loginForm.style.display = 'block';
  loginForm.classList.remove('form-entering');
  void loginForm.offsetWidth;
  loginForm.classList.add('form-entering');
}

function togglePassword(inputId, btnId) {
  const input = document.getElementById(inputId);
  const btn = document.getElementById(btnId);
  if (!input || !btn) return;

  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  if (btn.hasAttribute('aria-pressed')) {
    btn.setAttribute('aria-pressed', String(isHidden));
    btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    const openEye = btn.querySelector('.password-eye-open');
    const closedEye = btn.querySelector('.password-eye-closed');
    if (openEye && closedEye) {
      openEye.hidden = isHidden;
      closedEye.hidden = !isHidden;
      btn.classList.remove('is-toggling');
      void btn.offsetWidth;
      btn.classList.add('is-toggling');
      const clearAnimation = () => btn.classList.remove('is-toggling');
      btn.addEventListener('animationend', clearAnimation, { once: true });
    }
  } else {
    btn.textContent = isHidden ? 'Hide' : 'Show';
  }
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
    if (idLabel) idLabel.placeholder = 'e.g. 0316-00000C (Student ID)';
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

try {
  if (window.sessionStorage.getItem('rmsPublicCtaZoom') === '1') {
    window.sessionStorage.removeItem('rmsPublicCtaZoom');
    document.querySelector('.auth-split')?.classList.add('cta-zoom-arrival');
  }
} catch (error) {
  // The login page remains available if session storage is unavailable.
}

</script>
</body>
</html>
