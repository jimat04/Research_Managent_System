<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — CONFIGURATION
// ============================================================

// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Validate required environment variables
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_NAME', 'SITE_URL'])->notEmpty();

// Database Configuration (from .env)
define('DB_HOST',     $_ENV['DB_HOST']);
define('DB_USER',     $_ENV['DB_USER']);
define('DB_PASS',     $_ENV['DB_PASS'] ?? '');
define('DB_NAME',     $_ENV['DB_NAME']);

// Application Configuration (from .env)
define('SITE_URL',    $_ENV['SITE_URL']);
define('SITE_NAME',   $_ENV['SITE_NAME'] ?? 'Research Management System');
define('SITE_TITLE',  $_ENV['SITE_TITLE'] ?? 'RMS - Your Research Platform');

// Upload Configuration
define('UPLOADS_DIR', __DIR__ . '/../uploads/');
define('UPLOADS_URL', SITE_URL . 'uploads/');

// Session Configuration (from .env)
define('SESSION_TIMEOUT', (int)($_ENV['SESSION_TIMEOUT'] ?? 1800)); // Default: 30 minutes

// Security Configuration (from .env)
define('BCRYPT_COST', (int)($_ENV['BCRYPT_COST'] ?? 12));

// Upload Limits (from .env)
define('MAX_UPLOAD_SIZE', (int)($_ENV['MAX_UPLOAD_SIZE'] ?? 10485760)); // Default: 10MB
define('ALLOWED_FILE_TYPES', $_ENV['ALLOWED_FILE_TYPES'] ?? 'pdf,doc,docx,xls,xlsx,ppt,pptx');

// Environment Mode (from .env)
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));

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

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout validation
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        header('Location: ' . SITE_URL . 'login.php?timeout=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}
?>