<?php

function rmsSystemSettingDefaults(): array
{
    $defaultExtensions = array_values(array_unique(array_filter(array_map(
        static function ($extension) {
            return strtolower(ltrim(trim($extension), '.'));
        },
        explode(',', (string) ALLOWED_FILE_TYPES)
    ))));
    if (!in_array('pdf', $defaultExtensions, true)) {
        array_unshift($defaultExtensions, 'pdf');
    }

    return [
        'site_name' => (string) SITE_NAME,
        'site_title' => (string) SITE_TITLE,
        'institutional_affiliation' => 'Eulogio “Amang” Rodriguez Institute of Science and Technology (EARIST)',
        'contact_email' => 'research@earist.edu.ph',
        'admin_notification_email' => '',
        'contact_phone' => '(02) 1234-5678',
        'contact_address' => '2 Congressional Rd, General Mariano Alvarez, Cavite, Philippines',
        'maintenance_enabled' => '0',
        'maintenance_message' => 'The system is temporarily unavailable for maintenance. Please try again later.',
        'upload_max_size' => (string) min((int) MAX_UPLOAD_SIZE, rmsPhpUploadLimit()),
        'upload_allowed_extensions' => implode(',', $defaultExtensions),
        'session_timeout' => (string) SESSION_TIMEOUT,
        'password_min_length' => '8',
    ];
}

function rmsPhpSizeInBytes($value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    if (!preg_match('/^\s*(\d+)\s*([KMG])?\s*$/i', $value, $matches)) {
        return null;
    }

    $size = (int) $matches[1];
    $unit = strtoupper($matches[2] ?? '');
    $multiplier = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][$unit];
    return $size * $multiplier;
}

function rmsPhpUploadLimit(): int
{
    $limits = [];
    foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
        $parsed = rmsPhpSizeInBytes(ini_get($directive));
        if ($parsed !== null && $parsed > 0) {
            $limits[] = $parsed;
        }
    }

    return $limits ? min($limits) : max(1, (int) MAX_UPLOAD_SIZE);
}

function rmsEffectiveUploadLimit(int $requestedBytes = PHP_INT_MAX): int
{
    return min($requestedBytes, (int) rmsSetting('upload_max_size'), rmsPhpUploadLimit());
}

function rmsAllowedUploadExtensions(): array
{
    return explode(',', rmsSetting('upload_allowed_extensions'));
}

function rmsLoadSystemSettings(): array
{
    static $loaded = false;
    static $available = false;
    static $settings = [];

    if ($loaded) {
        return [$available, $settings];
    }

    global $conn;
    $tableCheck = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_settings'"
    );
    if (!$tableCheck || !$tableCheck->execute()) {
        if ($tableCheck) {
            $tableCheck->close();
        }
        throw new RuntimeException('Unable to check system settings storage.');
    }
    $available = (int) $tableCheck->get_result()->fetch_row()[0] > 0;
    $tableCheck->close();

    if (!$available) {
        $loaded = true;
        return [$available, $settings];
    }

    $result = $conn->query('SELECT setting_key, setting_value FROM application_settings');
    if (!$result) {
        throw new RuntimeException('Unable to load system settings: ' . $conn->error);
    }
    while ($row = $result->fetch_assoc()) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    $result->free();
    $loaded = true;

    return [$available, $settings];
}

function rmsSystemSettingsAvailable(): bool
{
    [$available] = rmsLoadSystemSettings();
    return $available;
}

function rmsSetting(string $key): string
{
    $defaults = rmsSystemSettingDefaults();
    if (!array_key_exists($key, $defaults)) {
        throw new InvalidArgumentException('Unknown system setting.');
    }

    [, $settings] = rmsLoadSystemSettings();
    $value = $settings[$key] ?? $defaults[$key];
    return rmsValidateSystemSetting($key, $value);
}

function rmsSystemSettings(): array
{
    $defaults = rmsSystemSettingDefaults();
    [, $stored] = rmsLoadSystemSettings();
    $values = [];
    foreach ($defaults as $key => $default) {
        $values[$key] = rmsValidateSystemSetting($key, $stored[$key] ?? $default);
    }
    return $values;
}

function rmsValidateSystemSetting(string $key, $value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        throw new InvalidArgumentException('Settings values must be plain text or numbers.');
    }
    $value = trim((string) $value);

    switch ($key) {
        case 'site_name':
        case 'site_title':
            if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Enter a site name or title of 1–120 characters.');
            }
            return $value;

        case 'institutional_affiliation':
            if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Enter an institutional affiliation of 1–255 characters.');
            }
            return $value;

        case 'contact_email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 254) {
                throw new InvalidArgumentException('Enter a valid public contact email address.');
            }
            return $value;

        case 'admin_notification_email':
            if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 254)) {
                throw new InvalidArgumentException('Enter a valid research head email address, or leave it blank to notify active admin accounts.');
            }
            return $value;

        case 'contact_phone':
        case 'contact_address':
            $limit = $key === 'contact_phone' ? 40 : 255;
            if ($value === '' || strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Enter a public contact value of no more than ' . $limit . ' characters.');
            }
            return $value;

        case 'maintenance_enabled':
            if (!in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose whether maintenance mode is enabled.');
            }
            return $value;

        case 'maintenance_message':
            if ($value === '' || strlen($value) > 500) {
                throw new InvalidArgumentException('Enter a maintenance message of up to 500 characters.');
            }
            return $value;

        case 'upload_max_size':
            if (!ctype_digit($value)) {
                throw new InvalidArgumentException('Enter the upload limit in bytes.');
            }
            $bytes = (int) $value;
            if ($bytes < 65536 || $bytes > rmsPhpUploadLimit()) {
                throw new InvalidArgumentException('The upload limit must be at least 64 KB and no greater than the active PHP upload/post limit.');
            }
            return (string) $bytes;

        case 'upload_allowed_extensions':
            $supported = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif'];
            $extensions = array_values(array_unique(array_filter(array_map(
                static function ($extension) {
                    return strtolower(ltrim(trim($extension), '.'));
                },
                explode(',', $value)
            ))));
            if (!$extensions || array_diff($extensions, $supported) || !in_array('pdf', $extensions, true)) {
                throw new InvalidArgumentException('Choose supported extensions from the provided list and keep PDF enabled for research-document workflows.');
            }
            return implode(',', $extensions);

        case 'session_timeout':
            if (!ctype_digit($value) || (int) $value < 300 || (int) $value > 86400) {
                throw new InvalidArgumentException('Session timeout must be between 5 minutes and 24 hours.');
            }
            return (string) (int) $value;

        case 'password_min_length':
            if (!ctype_digit($value) || (int) $value < 8 || (int) $value > 64) {
                throw new InvalidArgumentException('Minimum password length must be between 8 and 64 characters.');
            }
            return (string) (int) $value;
    }

    throw new InvalidArgumentException('Unknown system setting.');
}

function rmsUpdateSystemSettings(array $values, int $userId): array
{
    global $conn;

    if (!rmsSystemSettingsAvailable()) {
        throw new RuntimeException('System settings storage is not installed. Apply the application-settings migration first.');
    }

    $validated = [];
    foreach ($values as $key => $value) {
        if (!is_string($key)) {
            throw new InvalidArgumentException('Invalid settings request.');
        }
        $validated[$key] = rmsValidateSystemSetting($key, $value);
    }

    $defaults = rmsSystemSettingDefaults();
    if (array_diff(array_keys($defaults), array_keys($validated))
        || array_diff(array_keys($validated), array_keys($defaults))) {
        throw new InvalidArgumentException('Submit all settings before saving.');
    }

    [$available, $currentValues] = rmsLoadSystemSettings();
    $changed = [];
    foreach ($validated as $key => $value) {
        if (($currentValues[$key] ?? $defaults[$key]) !== $value) {
            $changed[] = $key;
        }
    }
    if (!$changed) {
        return [];
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start the system settings transaction.');
    }
    try {
        $stmt = $conn->prepare(
            'INSERT INTO application_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare system settings update: ' . $conn->error);
        }
        try {
            foreach ($validated as $key => $value) {
                if (!$stmt->bind_param('ssi', $key, $value, $userId)) {
                    throw new RuntimeException('Unable to bind system setting "' . $key . '".');
                }
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to save system setting "' . $key . '": ' . $stmt->error);
                }
            }
        } finally {
            $stmt->close();
        }

        $action = 'Updated system settings: ' . implode(', ', $changed);
        if (!logActivity($action, 'system_settings')) {
            throw new RuntimeException('System settings were not saved because the audit log entry could not be recorded.');
        }

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit system settings.');
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return $changed;
}

function rmsValidatePasswordPolicy(string $password): ?string
{
    $minimum = (int) rmsSetting('password_min_length');
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length > 64 || strlen($password) > 72) {
        return 'Password cannot exceed 64 characters or 72 bytes.';
    }
    if ($length < $minimum) {
        return 'Password must be at least ' . $minimum . ' characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if (preg_match('/\s/', $password)) {
        return 'Password cannot contain spaces.';
    }
    return null;
}

function rmsIsAdministratorSession(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    global $conn;
    $userId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role, status FROM users WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['role'] === 'admin' && $row['status'] === 'active';
}

function rmsEnforceMaintenanceMode(): void
{
    if (rmsSetting('maintenance_enabled') !== '1') {
        return;
    }

    if (rmsIsAdministratorSession()) {
        return;
    }

    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (is_string($requestPath) && preg_match('~/public/login\.php$~i', $requestPath)) {
        return;
    }

    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $message = htmlspecialchars(rmsSetting('maintenance_message'), ENT_QUOTES, 'UTF-8');
    $loginUrl = htmlspecialchars(SITE_URL . 'public/login.php', ENT_QUOTES, 'UTF-8');
    $loginLink = empty($_SESSION['user_id']) ? '<p><a href="' . $loginUrl . '">Administrator sign in</a></p>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System maintenance</title></head><body><main><h1>System maintenance</h1><p>' . $message . '</p>' . $loginLink . '</main></body></html>';
    exit;
}
