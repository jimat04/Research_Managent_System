<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — AUTH HELPERS
// ============================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, role, student_id, department, program, contact, avatar, status, created_at FROM users WHERE user_id = ? LIMIT 1");
    $user_id = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Require that the current session holds one of the given roles.
 * Accepts a single role string or an array of role strings.
 * Admins always pass through regardless of $roles.
 *
 * @param string|string[] $roles
 */
function requireRole($roles) {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'login.php');
        exit();
    }
    if (is_string($roles)) {
        $roles = [$roles];
    }
    $roles[] = 'admin'; // Admins always have access
    $ok = false;
    foreach ($roles as $r) {
        if (hasRole($r)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        header('Location: ' . SITE_URL . '403.php');
        exit();
    }
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'login.php');
        exit();
    }
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim((string) $input)), ENT_QUOTES, 'UTF-8');
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function isCsrfTokenValid($token) {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function logActivity($action, $module = '') {
    global $conn;
    if (!isLoggedIn()) return;

    $user_id = (int) $_SESSION['user_id'];
    $action  = substr((string) $action, 0, 255);
    $module  = substr((string) $module, 0, 100);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, module, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('isss', $user_id, $action, $module, $ip);
        $stmt->execute();
    }
}

/**
 * Create a notification for a specific user
 *
 * @param int $user_id The user to notify
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type (info, success, warning, error)
 * @param string|null $link Optional link to navigate to
 * @return bool Success status
 */
function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    global $conn;

    $user_id = (int) $user_id;
    $title = substr((string) $title, 0, 160);
    $message = (string) $message;
    $type = in_array($type, ['info', 'success', 'warning', 'error']) ? $type : 'info';
    $link = $link ? substr((string) $link, 0, 255) : null;

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    if ($stmt) {
        $stmt->bind_param('issss', $user_id, $title, $message, $type, $link);
        return $stmt->execute();
    }

    return false;
}
?>
