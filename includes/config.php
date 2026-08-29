<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — CONFIGURATION
// ============================================================

// Load environment variables from .env (if present).
// composer.json requires vlucas/phpdotenv, and the upstream files
// (e.g. includes/email.php) read from $_ENV, so this bootstrap must
// run before any code that consumes those values.
$envPath = __DIR__ . '/../.env';
if (is_readable($envPath) && !defined('RMS_DOTENV_LOADED')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    define('RMS_DOTENV_LOADED', true);
}

if (!function_exists('rms_env')) {
    /**
     * Resolve a configuration value from $_ENV with an optional default.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function rms_env($key, $default = null) {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return $default;
    }
}

// Database
define('DB_HOST',     rms_env('DB_HOST', 'localhost'));
define('DB_USER',     rms_env('DB_USER', 'root'));
define('DB_PASS',     rms_env('DB_PASS', ''));
define('DB_NAME',     rms_env('DB_NAME', 'rms_db'));

// Application
define('SITE_URL',    rms_env('SITE_URL', 'http://localhost/rms/'));
define('SITE_NAME',   rms_env('SITE_NAME', 'Research Management System'));
define('SITE_TITLE',  rms_env('SITE_TITLE', 'RMS - Your Research Platform'));

// Uploads (paths are filesystem-only; URLs derive from SITE_URL)
define('UPLOADS_DIR', __DIR__ . '/../uploads/');
define('UPLOADS_URL', SITE_URL . 'uploads/');

// Security / sessions
define('SESSION_TIMEOUT', (int) rms_env('SESSION_TIMEOUT', 1800));
define('BCRYPT_COST',     (int) rms_env('BCRYPT_COST', 12));
define('MAX_UPLOAD_SIZE', (int) rms_env('MAX_UPLOAD_SIZE', 10485760));
define('ALLOWED_FILE_TYPES', rms_env(
    'ALLOWED_FILE_TYPES',
    'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png'
));

// Application mode
define('APP_ENV',   rms_env('APP_ENV', 'development'));
define('APP_DEBUG', filter_var(rms_env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

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
        header('Location: ' . SITE_URL . 'public/login.php?timeout=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}
?>
