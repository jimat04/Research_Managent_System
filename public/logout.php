<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    logActivity('User logged out', 'authentication');
}

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session completely
$_SESSION = [];
session_unset();
session_destroy();

// Regenerate session ID for security (start fresh)
session_start();
session_regenerate_id(true);

// Redirect to login page
header('Location: ' . SITE_URL . 'public/login.php');
exit();
?>
