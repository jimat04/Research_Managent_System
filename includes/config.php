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

define('RMS_E2E_MODE', rms_env('RMS_E2E_MODE', '0') === '1');

if (RMS_E2E_MODE) {
    $credentialDirectory = getenv('RMS_E2E_CREDENTIALS_DIR');
    if (!is_string($credentialDirectory)) {
        throw new RuntimeException('E2E application database configuration is unavailable or invalid.');
    }
    require_once __DIR__ . '/e2e-safety.php';
    require_once __DIR__ . '/e2e-credentials.php';
    $e2eDatabaseConfig = rmsE2eLoadAppSqlConfiguration(
        static function ($key, $default = null) {
            return rms_env($key, $default);
        },
        $credentialDirectory
    );
    define('DB_HOST', $e2eDatabaseConfig['host']);
    define('DB_USER', $e2eDatabaseConfig['user']);
    define('DB_PASS', $e2eDatabaseConfig['password']);
    define('DB_NAME', $e2eDatabaseConfig['name']);
} else {
    // Preserve the operational application's existing configuration behavior.
    define('DB_HOST', rms_env('DB_HOST', 'localhost'));
    define('DB_USER', rms_env('DB_USER', 'root'));
    define('DB_PASS', rms_env('DB_PASS', ''));
    define('DB_NAME', rms_env('DB_NAME', 'rms_db'));
}

// Application
define('SITE_URL',    rms_env('SITE_URL', 'http://localhost/rms/'));
define('SITE_NAME',   rms_env('SITE_NAME', 'Research Management System'));
define('SITE_TITLE',  rms_env('SITE_TITLE', 'RMS - Your Research Platform'));

// Uploads (paths are filesystem-only; URLs derive from SITE_URL)
define('UPLOADS_DIR', RMS_E2E_MODE
    ? rtrim((string) rms_env('RMS_E2E_UPLOAD_ROOT', ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    : __DIR__ . '/../uploads/');
define('UPLOADS_URL', SITE_URL . 'uploads/');

// Security / sessions
define('SESSION_TIMEOUT', (int) rms_env('SESSION_TIMEOUT', 1800));
define('BCRYPT_COST',     (int) rms_env('BCRYPT_COST', 12));
define('MAX_UPLOAD_SIZE', (int) rms_env('MAX_UPLOAD_SIZE', 10485760));
define('ACTIVITY_CHECKIN_HASH_KEY', (string) rms_env('ACTIVITY_CHECKIN_HASH_KEY', ''));
define('ACTIVITY_CHECKIN_BASE_URL', trim((string) rms_env('ACTIVITY_CHECKIN_BASE_URL', '')));
define('ALLOWED_FILE_TYPES', rms_env(
    'ALLOWED_FILE_TYPES',
    'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png'
));

// Application mode
define('APP_ENV',   rms_env('APP_ENV', 'development'));
define('APP_DEBUG', filter_var(rms_env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

if (!in_array(APP_ENV, ['development', 'production'], true)) {
    error_log('RMS configuration rejected an unsupported APP_ENV value.');
    http_response_code(500);
    exit('The application is not configured correctly.');
}

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    if (!RMS_E2E_MODE) {
        $productionConfigErrors = [];
        $siteUrlParts = parse_url(SITE_URL);

        if (APP_DEBUG) {
            $productionConfigErrors[] = 'APP_DEBUG';
        }
        if (!is_array($siteUrlParts)
            || filter_var(SITE_URL, FILTER_VALIDATE_URL) === false
            || strtolower((string) ($siteUrlParts['scheme'] ?? '')) !== 'https'
            || empty($siteUrlParts['host'])
            || isset($siteUrlParts['user'])
            || isset($siteUrlParts['pass'])
            || isset($siteUrlParts['query'])
            || isset($siteUrlParts['fragment'])
            || !str_ends_with(SITE_URL, '/')) {
            $productionConfigErrors[] = 'SITE_URL';
        }

        foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $key) {
            $value = rms_env($key);
            if (!is_string($value) || trim($value) === '') {
                $productionConfigErrors[] = $key;
            }
        }

        $configuredDbUser = strtolower(trim((string) rms_env('DB_USER', '')));
        $configuredDbPassword = trim((string) rms_env('DB_PASS', ''));
        if (in_array($configuredDbUser, ['root', 'your_database_user'], true)) {
            $productionConfigErrors[] = 'DB_USER';
        }
        if ($configuredDbPassword === 'your_database_password') {
            $productionConfigErrors[] = 'DB_PASS';
        }
        if (preg_match('/^[a-f0-9]{64}$/i', ACTIVITY_CHECKIN_HASH_KEY) !== 1) {
            $productionConfigErrors[] = 'ACTIVITY_CHECKIN_HASH_KEY';
        }

        if ($productionConfigErrors !== []) {
            error_log('RMS production configuration rejected invalid settings: ' . implode(', ', array_unique($productionConfigErrors)));
            http_response_code(500);
            exit('The application is not configured for production.');
        }
    }
}

// Email verification can only be disabled explicitly for a local development site.
// Use the configured URL, never the request Host header, to determine locality.
define('REQUIRE_EMAIL_VERIFICATION', !(
    APP_ENV === 'development'
    && in_array(strtolower((string) parse_url(SITE_URL, PHP_URL_HOST)), ['localhost', '127.0.0.1', '[::1]'], true)
    && (string) rms_env('REQUIRE_EMAIL_VERIFICATION', '1') === '0'
));

if (RMS_E2E_MODE) {
    require_once __DIR__ . '/e2e-safety.php';
    $e2eRuntimeRoot = rmsE2eCanonicalPath((string) rms_env('RMS_E2E_RUNTIME_ROOT'), 'runtime root');
    rmsE2eConfigureSessionPath($e2eRuntimeRoot);
    $e2eUploadTemp = $e2eRuntimeRoot . DIRECTORY_SEPARATOR . 'uploads-tmp';
    $e2eErrorLog = (string) rms_env('RMS_E2E_PHP_ERROR_LOG');
    $currentErrorLog = (string) ini_get('error_log');
    if (!rmsE2ePathsMatch((string) ini_get('upload_tmp_dir'), $e2eUploadTemp)
        || (!rmsE2ePathsMatch($currentErrorLog, $e2eErrorLog)
            && (ini_set('error_log', $e2eErrorLog) === false
                || !rmsE2ePathsMatch((string) ini_get('error_log'), $e2eErrorLog)))) {
        throw new RuntimeException('E2E safety check failed: PHP runtime paths could not be isolated');
    }
    rmsE2eValidateStaticConfiguration(__DIR__ . '/..', UPLOADS_DIR);
}

// E2E identity probes need to validate the target before opening a connection.
// This flag is only set by the loopback-restricted E2E endpoint and CLI preflight.
if (!defined('RMS_E2E_IDENTITY_ONLY') || RMS_E2E_IDENTITY_ONLY !== true) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            if (APP_ENV === 'production') {
                error_log('RMS database connection failed in production; check private database configuration and connectivity.');
                http_response_code(503);
                die('The application is temporarily unavailable.');
            }
            die("Connection failed: " . $conn->connect_error);
        }

        $conn->set_charset("utf8mb4");
        if (RMS_E2E_MODE) {
            rmsE2eVerifyDatabase($conn);
        }

    } catch (Exception $e) {
        if (APP_ENV === 'production') {
            error_log('RMS database connection failed in production; check private database configuration and connectivity.');
            http_response_code(503);
            die('The application is temporarily unavailable.');
        }
        die(RMS_E2E_MODE ? 'E2E safety check failed; database access refused.' : "Database Error: " . $e->getMessage());
    }
}

// Start session
if (!defined('RMS_E2E_IDENTITY_ONLY') || RMS_E2E_IDENTITY_ONLY !== true) {
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $sitePath = parse_url(SITE_URL, PHP_URL_PATH);
        $sessionCookiePath = is_string($sitePath) && $sitePath !== '' ? $sitePath : '/';
        $httpsActive = isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $sessionCookiePath,
            'secure' => APP_ENV === 'production' || $httpsActive,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

require_once __DIR__ . '/system-settings.php';

// Set session timeout
if (isset($_SESSION['user_id'])) {
    $sessionTimeout = (int) rmsSetting('session_timeout');
    if (time() - (int) ($_SESSION['last_activity'] ?? time()) > $sessionTimeout) {
        session_unset();
        session_destroy();
        header('Location: ' . SITE_URL . 'public/login.php?timeout=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}

if (PHP_SAPI !== 'cli' && (!defined('RMS_E2E_IDENTITY_ONLY') || RMS_E2E_IDENTITY_ONLY !== true)) {
    rmsEnforceMaintenanceMode();
}
?>
