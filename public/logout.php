<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    logActivity('User logged out', 'authentication');
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header('Location: ' . SITE_URL . 'login.php');
exit();
?>
