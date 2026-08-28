<?php
include 'includes/config.php';
include 'includes/auth.php';

$error = '';
$success = '';

function loginFailureKey() {
    return md5($_SERVER['REMOTE_ADDR'] . '|' . session_id());
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? sanitize($_POST['action']) : '';

    // LOGIN
    if ($action === 'login') {
        $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $selectedRole = isset($_POST['role']) ? sanitize($_POST['role']) : '';

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif (isLoginLocked()) {
    $remaining = max(0, ceil(($_SESSION['login_locked_until'][loginFailureKey()] - time()) / 60));
    $error = "Too many failed attempts. Please try again in {$remaining} minute(s).";
} else {
            $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, status FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user['password'])) {
                    $dbRole = strtolower((string) $user['role']);

                    if ($selectedRole !== $dbRole || !in_array($dbRole, ['student', 'faculty', 'admin'], true)) {
                        recordFailedLogin();
                        $error = 'Invalid email, password, or role.';
                    } else {
                        resetFailedLogins();

                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();

                        $updateStmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?');
                        $updateStmt->bind_param('i', $user['user_id']);
                        $updateStmt->execute();

                        logActivity('User logged in', 'authentication');

                        $redirect = 'pages/' . $dbRole . '-dashboard.php';
                        header('Location: ' . $redirect);
                        exit();
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
        $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
        $student_id = isset($_POST['student_id']) ? sanitize($_POST['student_id']) : '';
        $role = isset($_POST['role']) ? sanitize($_POST['role']) : 'student';

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif (!isValidEmail($email)) {
            $error = 'Invalid email format';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match';
        } else {
            // Check if email exists
            $check = $conn->query("SELECT user_id FROM users WHERE email = '$email'");
            if ($check && $check->num_rows > 0) {
                $error = 'Email already registered';
            } else {
                // Hash password and insert
                $password_hash = hashPassword($password);
                $sql = "INSERT INTO users (first_name, last_name, email, password, student_id, role, status)
                        VALUES ('$first_name', '$last_name', '$email', '$password_hash', '$student_id', '$role', 'pending')";

                if ($conn->query($sql)) {
                    logActivity('New user registered', 'authentication');

                    $success = 'Registration successful! Please wait for admin approval.';
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
  <link rel="stylesheet" href="css/style.css" />
</head>
<body style="background: linear-gradient(135deg, #0A0833 0%, #1a0a3a 50%, #0a1a3a 100%); min-height: 100vh;">

<div class="auth-container">
  <div class="auth-card">
    <div id="loginForm" style="display: block;">
      <div class="auth-header">
        <div class="auth-logo">🔬</div>
        <h1>Welcome Back</h1>
        <p>Sign in to your research account</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px;">
          <span style="color: #dc2626;">✕</span>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 24px; background: rgba(255,255,255,0.06); border-radius: 8px; padding: 4px;">
        <button type="button" class="role-tab active" data-role="student" onclick="switchRole('student')" style="padding: 10px; background: var(--primary); color: white; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 600;">🎓 Student</button>
        <button type="button" class="role-tab" data-role="faculty" onclick="switchRole('faculty')" style="padding: 10px; background: transparent; color: #D0D3E8; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 500;">👨‍🏫 Faculty</button>
        <button type="button" class="role-tab" data-role="admin" onclick="switchRole('admin')" style="padding: 10px; background: transparent; color: #D0D3E8; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 500;">⚙️ Admin</button>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="role" id="role" value="student">

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter your email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
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
        <a href="index.php" class="btn btn-secondary" style="display:inline-block; padding:10px 14px; background: transparent; color: #D0D3E8; border: 1px solid rgba(255,255,255,0.06); border-radius: 6px; text-decoration: none; font-weight: 600;">← Back to homepage</a>
      </p>

      <div style="text-align: center; margin: 20px 0; color: #8B8FAD; font-size: 0.85rem;">
        or
      </div>

      <p style="text-align: center; color: #8B8FAD; font-size: 0.85rem;">
        Don't have an account?
        <a href="#" onclick="switchToRegister()" style="color: var(--primary-light); font-weight: 600;">Create one</a>
      </p>
    </div>

    <div id="registerForm" style="display: none;">
      <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, var(--secondary), var(--accent));">✨</div>
        <h1>Create Account</h1>
        <p>Join the Research Management System</p>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
          <span style="color: #15803d;">✓</span>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="action" value="register">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control" placeholder="Juan" required value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz" required value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="you@university.edu.ph" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Student/Employee ID</label>
          <input type="text" name="student_id" class="form-control" placeholder="e.g. 2024-00001" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control">
            <option value="student">🎓 Student</option>
            <option value="faculty">👨‍🏫 Faculty/Staff</option>
          </select>
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

      <p style="text-align: center; color: #8B8FAD; font-size: 0.85rem;">
        Already have an account?
        <a href="#" onclick="switchToLogin()" style="color: var(--primary-light); font-weight: 600;">Sign In</a>
      </p>
    </div>
  </div>
</div>

<script>
function switchRole(role) {
  const roleInput = document.getElementById('role');
  const tabs = document.querySelectorAll('.role-tab');

  if (roleInput) {
    roleInput.value = role;
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

if (window.location.hash === '#register') {
  switchToRegister();
}
</script>
</body>
</html>
