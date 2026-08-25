<?php
include 'includes/config.php';
include 'includes/auth.php';

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
