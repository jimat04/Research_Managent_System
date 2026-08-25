<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — CONFIGURATION
// ============================================================

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'rms_db');

define('SITE_URL',    'http://localhost/rms/');
define('SITE_NAME',   'Research Management System');
define('SITE_TITLE',  'RMS - Your Research Platform');

define('UPLOADS_DIR', __DIR__ . '/../uploads/');
define('UPLOADS_URL', SITE_URL . 'uploads/');

define('SESSION_TIMEOUT', 1800); // 30 minutes

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// Start session
session_start();

// Set session timeout
if (isset($_SESSION['user_id'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . SITE_URL . 'login.php?timeout=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}
?>
