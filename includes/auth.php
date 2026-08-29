<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — AUTHENTICATION HELPERS
// ============================================================

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user data
 */
function getCurrentUser() {
    global $conn;
    
    if (!isLoggedIn()) {
        return null;
    }
    
    $user_id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, role, student_id, department, program, contact, avatar, status, created_at FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Check user role
 */
function hasRole($role) {
    if (!isLoggedIn()) return false;
    return $_SESSION['role'] === $role;
}

/**
 * Require specific role(s), redirect if not authorized
 * Accepts string or array of roles. Admins always pass.
 */
function requireRole($roles) {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'public/login.php');
        exit();
    }

    // Convert single role to array
    if (is_string($roles)) {
        $roles = [$roles];
    }

    // Check if user has any of the required roles or is admin
    $hasAccess = false;
    foreach ($roles as $role) {
        if (hasRole($role)) {
            $hasAccess = true;
            break;
        }
    }

    // Admins always have access
    if (hasRole('admin')) {
        $hasAccess = true;
    }

    if (!$hasAccess) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit();
    }
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'public/login.php');
        exit();
    }
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * CSRF token management
 */
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

/**
 * Sanitize input
 */
function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Create notification
 */
function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    global $conn;
    
    $user_id = intval($user_id);
    $title   = sanitize($title);
    $message = sanitize($message);
    $type    = sanitize($type);
    $link    = $link ? sanitize($link) : null;
    
    $sql = "INSERT INTO notifications (user_id, title, message, type, link) 
            VALUES ($user_id, '$title', '$message', '$type', " . ($link ? "'$link'" : "NULL") . ")";
    
    return $conn->query($sql);
}

/**
 * Log activity
 */
function logActivity($action, $module = null) {
    global $conn;
    
    $user_id   = isLoggedIn() ? intval($_SESSION['user_id']) : null;
    $action    = sanitize($action);
    $module    = $module ? sanitize($module) : null;
    $ip        = $_SERVER['REMOTE_ADDR'];
    
    $sql = "INSERT INTO activity_log (user_id, action, module, ip_address) 
            VALUES (" . ($user_id ? $user_id : "NULL") . ", '$action', " . ($module ? "'$module'" : "NULL") . ", '$ip')";
    
    $conn->query($sql);
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Redirect with message
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['message']      = $message;
    $_SESSION['message_type'] = $type;
    header('Location: ' . $url);
    exit();
}

/**
 * Get and clear message
 */
function getMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type    = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : 'success';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Display alert message
 */
function displayMessage() {
    $msg = getMessage();
    
    if (!$msg) return '';
    
    $icon = ['success' => '✓', 'error' => '✕', 'warning' => '⚠', 'info' => 'ℹ'][$msg['type']] ?? 'ℹ';
    $colors = [
        'success' => '#22c55e',
        'error'   => '#ef4444',
        'warning' => '#f59e0b',
        'info'    => '#0F6CBD'
    ];
    $color = $colors[$msg['type']] ?? '#0F6CBD';
    
    echo <<<HTML
    <div class="alert alert-{$msg['type']}" style="
        position: fixed; top: 20px; right: 20px; z-index: 9999;
        background: white; padding: 16px 20px; border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        display: flex; align-items: center; gap: 12px;
        animation: slideIn 0.3s ease;
    ">
        <span style="
            width: 24px; height: 24px; border-radius: 50%;
            background: $color; color: white; display: flex;
            align-items: center; justify-content: center;
            font-weight: bold; font-size: 0.75rem; flex-shrink: 0;
        ">$icon</span>
        <span style="color: #334155; font-size: 0.875rem;">{$msg['message']}</span>
        <button onclick="this.parentElement.remove()" style="
            background: none; border: none; color: #94a3b8;
            font-size: 1.2rem; cursor: pointer; padding: 0;
        ">×</button>
    </div>
    HTML;
}
?>
