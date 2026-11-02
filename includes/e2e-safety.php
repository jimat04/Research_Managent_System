<?php
declare(strict_types=1);

function rmsE2eModeEnabled(): bool
{
    return defined('RMS_E2E_MODE') && RMS_E2E_MODE === true;
}

function rmsE2eLocalHost(string $host): bool
{
    return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

function rmsE2eCanonicalPath(string $path, string $label, ?callable $pathResolver = null): string
{
    if ($path === '' || !is_dir($path)) {
        throw new RuntimeException("E2E safety check failed: {$label} is unavailable");
    }
    $canonical = $pathResolver === null ? realpath($path) : $pathResolver($path);
    if ($canonical === false) {
        throw new RuntimeException("E2E safety check failed: {$label} cannot be resolved");
    }
    return $canonical;
}

function rmsE2ePathsMatch(string $left, string $right): bool
{
    $left = rtrim(str_replace('\\', '/', $left), '/');
    $right = rtrim(str_replace('\\', '/', $right), '/');
    return DIRECTORY_SEPARATOR === '\\'
        ? strtolower($left) === strtolower($right)
        : $left === $right;
}

function rmsE2eValidateDocumentRoot(string $configuredRoot, ?string $serverRoot, bool $webRequest): string
{
    $canonicalConfiguredRoot = $configuredRoot === '' ? false : realpath($configuredRoot);
    if ($canonicalConfiguredRoot === false
        || !is_dir($canonicalConfiguredRoot)
        || !rmsE2ePathsMatch($configuredRoot, $canonicalConfiguredRoot)
        || rmsE2eHasReparsePoint($configuredRoot)) {
        throw new RuntimeException('E2E safety check failed: configured document root is unavailable or invalid');
    }

    if (!$webRequest) {
        return $canonicalConfiguredRoot;
    }

    if (!is_string($serverRoot) || trim($serverRoot) === '' || !is_dir($serverRoot)) {
        throw new RuntimeException('E2E safety check failed: serving document root is unavailable or invalid');
    }
    $canonicalServerRoot = realpath($serverRoot);
    if ($canonicalServerRoot === false
        || !rmsE2ePathsMatch($serverRoot, $canonicalServerRoot)
        || rmsE2eHasReparsePoint($serverRoot)
        || !rmsE2ePathsMatch($canonicalConfiguredRoot, $canonicalServerRoot)) {
        throw new RuntimeException('E2E safety check failed: serving document root does not match configuration');
    }

    return $canonicalConfiguredRoot;
}

function rmsE2ePathIsInside(string $parent, string $candidate): bool
{
    $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
    $candidate = rtrim(str_replace('\\', '/', $candidate), '/') . '/';
    if (DIRECTORY_SEPARATOR === '\\') {
        $parent = strtolower($parent);
        $candidate = strtolower($candidate);
    }
    return str_starts_with($candidate, $parent);
}

function rmsE2eHasReparsePoint(string $path, ?callable $reparsePointCheck = null): bool
{
    if ($reparsePointCheck !== null) {
        return $reparsePointCheck($path) === true;
    }
    $stat = @lstat($path);
    if ($stat === false) {
        return false;
    }
    if (((int) ($stat['mode'] ?? 0) & 0170000) === 0120000) {
        return true;
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        return false;
    }
    if (((int) ($stat['attributes'] ?? 0) & 0x400) !== 0) {
        return true;
    }

    $isAbsolute = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1;
    if (!$isAbsolute) {
        return true;
    }

    $canonical = @realpath($path);
    return $canonical === false || !rmsE2ePathsMatch($path, $canonical);
}

function rmsE2eRequireNoReparsePoints(
    string $path,
    string $root,
    string $label,
    ?callable $reparsePointCheck = null
): void
{
    $canonicalRoot = realpath($root);
    $candidate = str_replace('\\', '/', $path);
    $rootPath = str_replace('\\', '/', $root);
    if (!$canonicalRoot || !rmsE2ePathIsInside($canonicalRoot, $candidate)) {
        throw new RuntimeException("E2E safety check failed: {$label} is outside its approved root");
    }

    $relative = ltrim(substr($candidate, strlen(rtrim($rootPath, '/'))), '/');
    $cursor = rtrim($root, DIRECTORY_SEPARATOR);
    foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException("E2E safety check failed: {$label} contains an unsafe path component");
        }
        $cursor .= DIRECTORY_SEPARATOR . $part;
        if (file_exists($cursor) || is_link($cursor)) {
            if (rmsE2eHasReparsePoint($cursor, $reparsePointCheck)) {
                throw new RuntimeException("E2E safety check failed: {$label} contains a link or reparse point");
            }
        }
    }
}

function rmsE2eNormalizeBaseUrl(string $value, string $label): array
{
    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
        || !isset($parts['host'])
        || !rmsE2eLocalHost((string) $parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException("E2E safety check failed: {$label} must be a loopback HTTP base URL");
    }

    $path = (string) ($parts['path'] ?? '/');
    $decodedPath = rawurldecode($path);
    $port = (int) ($parts['port'] ?? 80);
    if ($path === ''
        || $path[0] !== '/'
        || substr($path, -1) !== '/'
        || str_contains($decodedPath, '\\')
        || preg_match('/%(?:2f|5c)/i', $path)
        || preg_match('/%(?![a-f0-9]{2})/i', $path)
        || $port < 1
        || $port > 65535
        || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath)) {
        throw new RuntimeException("E2E safety check failed: {$label} has an unsafe application base path");
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    return ['origin' => 'http://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port, 'path' => $decodedPath];
}

function rmsE2eValidateSiteUrl(string $siteUrl, string $targetUrl): void
{
    $site = rmsE2eNormalizeBaseUrl($siteUrl, 'SITE_URL');
    $target = rmsE2eNormalizeBaseUrl($targetUrl, 'RMS_E2E_BASE_URL');
    if ($site['origin'] !== $target['origin'] || $site['path'] !== $target['path']) {
        throw new RuntimeException('E2E safety check failed: SITE_URL does not match the approved E2E target');
    }
}

function rmsE2eValidateUploadRoot(
    string $applicationRoot,
    string $uploadRoot,
    ?callable $reparsePointCheck = null,
    ?callable $pathResolver = null
): string
{
    $applicationRoot = rmsE2eCanonicalPath($applicationRoot, 'application root');
    $expectedUploadRoot = $applicationRoot . DIRECTORY_SEPARATOR . 'uploads';
    if (rmsE2eHasReparsePoint($expectedUploadRoot, $reparsePointCheck)) {
        throw new RuntimeException('E2E safety check failed: upload root cannot be a link or reparse point');
    }
    $canonicalUploadRoot = rmsE2eCanonicalPath($uploadRoot, 'upload root', $pathResolver);
    if (!rmsE2ePathIsInside($applicationRoot, $canonicalUploadRoot)
        || !rmsE2ePathsMatch($canonicalUploadRoot, $expectedUploadRoot)
        || !rmsE2ePathsMatch($uploadRoot, $expectedUploadRoot)) {
        throw new RuntimeException('E2E safety check failed: upload root is not physically inside the isolated application');
    }
    rmsE2eRequireNoReparsePoints($expectedUploadRoot, $applicationRoot, 'upload root', $reparsePointCheck);
    return $canonicalUploadRoot;
}

function rmsE2eValidateRuntimePaths(
    string $runtimeRoot,
    string $temporaryRoot,
    string $uploadTemporaryRoot,
    string $sessionRoot,
    string $errorLog,
    ?callable $writableCheck = null,
    ?callable $reparsePointCheck = null
): array {
    $writableCheck = $writableCheck ?? static function (string $path): bool {
        return is_writable($path);
    };
    $runtimeRoot = rmsE2eCanonicalPath($runtimeRoot, 'runtime root');
    $expected = [
        'tempRoot' => $runtimeRoot . DIRECTORY_SEPARATOR . 'tmp',
        'uploadTempRoot' => $runtimeRoot . DIRECTORY_SEPARATOR . 'uploads-tmp',
        'sessionRoot' => $runtimeRoot . DIRECTORY_SEPARATOR . 'sessions',
        'logRoot' => $runtimeRoot . DIRECTORY_SEPARATOR . 'logs',
    ];
    $actual = [
        'tempRoot' => $temporaryRoot,
        'uploadTempRoot' => $uploadTemporaryRoot,
        'sessionRoot' => $sessionRoot,
    ];
    $canonical = [];
    foreach ($expected as $key => $path) {
        $directory = rmsE2eCanonicalPath($path, "runtime {$key} directory");
        rmsE2eRequireNoReparsePoints($path, $runtimeRoot, "runtime {$key} directory", $reparsePointCheck);
        if (!$writableCheck($directory)) {
            throw new RuntimeException("E2E safety check failed: runtime {$key} directory is not writable");
        }
        if (isset($actual[$key])) {
            $effective = rmsE2eCanonicalPath($actual[$key], "effective {$key}");
            if (!rmsE2ePathsMatch($effective, $directory)) {
                throw new RuntimeException("E2E safety check failed: effective {$key} is not isolated");
            }
            $canonical[$key] = $effective;
        }
        if (!rmsE2ePathIsInside($runtimeRoot, $directory)) {
            throw new RuntimeException("E2E safety check failed: runtime {$key} escapes the runtime root");
        }
    }

    $logRoot = $canonical['logRoot'] ?? realpath($expected['logRoot']);
    $expectedLog = $logRoot . DIRECTORY_SEPARATOR . 'php-error.log';
    if (!rmsE2ePathsMatch($errorLog, $expectedLog)
        || rmsE2eHasReparsePoint($expectedLog, $reparsePointCheck)) {
        throw new RuntimeException('E2E safety check failed: PHP error log path is outside the isolated runtime');
    }
    if (file_exists($expectedLog) && (!is_file($expectedLog) || !$writableCheck($expectedLog))) {
        throw new RuntimeException('E2E safety check failed: PHP error log file is not writable');
    }
    $canonical['errorLog'] = $expectedLog;
    return $canonical;
}

function rmsE2eMailIsDisabled(): bool
{
    $disabledFunctions = array_map(
        'strtolower',
        array_map('trim', explode(',', (string) ini_get('disable_functions')))
    );
    return in_array('mail', $disabledFunctions, true);
}

function rmsE2eValidateStaticConfiguration(string $applicationRoot, string $uploadRoot): void
{
    if (!rmsE2eModeEnabled()) {
        return;
    }

    $applicationRoot = rmsE2eCanonicalPath($applicationRoot, 'application root');
    $configuredRoot = rms_env('RMS_PROJECT_ROOT');
    $sourceRoot = rms_env('RMS_E2E_SOURCE_ROOT');
    $canonicalConfiguredRoot = rmsE2eCanonicalPath((string) $configuredRoot, 'configured application root');
    $canonicalSourceRoot = rmsE2eCanonicalPath((string) $sourceRoot, 'harness source root');
    $canonicalUploadRoot = rmsE2eValidateUploadRoot($applicationRoot, $uploadRoot);
    $sourceUploadRoot = rmsE2eCanonicalPath($canonicalSourceRoot . DIRECTORY_SEPARATOR . 'uploads', 'source checkout upload root');

    if ($canonicalConfiguredRoot !== $applicationRoot
        || rmsE2ePathsMatch($canonicalSourceRoot, $applicationRoot)
        || rmsE2ePathsMatch($canonicalUploadRoot, $sourceUploadRoot)) {
        throw new RuntimeException('E2E safety check failed: application and upload roots are not isolated');
    }
    rmsE2eValidateSiteUrl((string) SITE_URL, (string) rms_env('RMS_E2E_BASE_URL', ''));

    $databaseName = (string) DB_NAME;
    $databaseHost = (string) DB_HOST;
    $databaseUser = (string) DB_USER;
    if (!rmsE2eLocalHost($databaseHost)
        || strcasecmp($databaseName, 'rms_db') === 0
        || $databaseHost !== (string) rms_env('RMS_E2E_DB_HOST')
        || $databaseName !== (string) rms_env('RMS_E2E_DB_NAME')
        || $databaseUser !== (string) rms_env('RMS_E2E_DB_USER')
        || $databaseName === ''
        || $databaseUser === '') {
        throw new RuntimeException('E2E safety check failed: database configuration is not the explicit local target');
    }

    $instanceId = (string) rms_env('RMS_E2E_INSTANCE_ID');
    $marker = (string) rms_env('RMS_E2E_DB_MARKER');
    if (!preg_match('/^[a-f0-9-]{36}$/i', $instanceId)
        || !preg_match('/^[a-f0-9]{64}$/i', $marker)) {
        throw new RuntimeException('E2E safety check failed: database identity marker is missing or invalid');
    }

    $runtimeRoot = rmsE2eCanonicalPath((string) rms_env('RMS_E2E_RUNTIME_ROOT'), 'runtime root');
    if (rmsE2ePathIsInside($applicationRoot, $runtimeRoot)
        || rmsE2ePathIsInside($runtimeRoot, $applicationRoot)
        || rmsE2ePathIsInside($canonicalSourceRoot, $runtimeRoot)
        || rmsE2ePathIsInside($runtimeRoot, $canonicalSourceRoot)) {
        throw new RuntimeException('E2E safety check failed: runtime files must be outside application and source roots');
    }
    $expectedErrorLog = (string) rms_env('RMS_E2E_PHP_ERROR_LOG');
    rmsE2eValidateRuntimePaths(
        $runtimeRoot,
        sys_get_temp_dir(),
        (string) ini_get('upload_tmp_dir'),
        session_save_path(),
        (string) ini_get('error_log')
    );

    $mailHost = (string) rms_env('MAIL_HOST');
    $mailPort = (string) rms_env('MAIL_PORT');
    if (strtolower(trim((string) rms_env('MAIL_MAILER'))) !== 'smtp'
        || !rmsE2eLocalHost($mailHost)
        || $mailHost !== (string) rms_env('RMS_E2E_MAIL_HOST')
        || $mailPort !== (string) rms_env('RMS_E2E_MAIL_PORT')
        || !rmsE2eLocalHost((string) rms_env('RMS_E2E_MAIL_HOST'))
        || !preg_match('/^\d{1,5}$/', $mailPort)
        || (int) $mailPort < 1
        || (int) $mailPort > 65535
        || (string) rms_env('MAIL_USERNAME') === ''
        || (string) rms_env('MAIL_PASSWORD') === '') {
        throw new RuntimeException('E2E safety check failed: mail transport is not restricted to the local sink');
    }

    if (!rmsE2eMailIsDisabled()) {
        throw new RuntimeException('E2E safety check failed: PHP mail() must be disabled in the isolated runtime');
    }
}

function rmsE2eConfigureSessionPath(string $runtimeRoot): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        throw new RuntimeException('E2E safety check failed: session configuration is already active');
    }
    $sessionRoot = rtrim($runtimeRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sessions';
    rmsE2eCanonicalPath($sessionRoot, 'session directory');
    rmsE2eRequireNoReparsePoints($sessionRoot, $runtimeRoot, 'session directory');
    if (!is_writable($sessionRoot) || session_save_path($sessionRoot) === false
        || !rmsE2ePathsMatch(session_save_path(), realpath($sessionRoot) ?: '')) {
        throw new RuntimeException('E2E safety check failed: PHP session directory could not be configured');
    }
}

function rmsE2eMarkerMatches(string $expectedInstance, string $expectedMarker, array $guard): bool
{
    if (($guard['purpose'] ?? null) !== 'RMS_E2E_DISPOSABLE'
        || !is_string($guard['instance_id'] ?? null)
        || !is_string($guard['marker_sha256'] ?? null)) {
        return false;
    }
    return hash_equals($expectedInstance, $guard['instance_id'])
        && hash_equals(hash('sha256', $expectedMarker), strtolower($guard['marker_sha256']));
}

function rmsE2eVerifyDatabase(mysqli $connection, ?string $expectedDatabaseUser = null): array
{
    if (!rmsE2eModeEnabled()) {
        return [];
    }

    $expectedDatabaseUser = $expectedDatabaseUser ?? (string) DB_USER;
    $identityResult = $connection->query(
        'SELECT DATABASE() AS database_name, @@hostname AS server_host, CURRENT_USER() AS current_user'
    );
    $identity = $identityResult instanceof mysqli_result ? $identityResult->fetch_assoc() : null;
    $expectedName = (string) rms_env('RMS_E2E_DB_NAME');
    if (!$identity || $identity['database_name'] !== $expectedName
        || strcasecmp((string) $identity['database_name'], 'rms_db') === 0
        || strcasecmp(explode('@', (string) $identity['current_user'], 2)[0], $expectedDatabaseUser) !== 0) {
        throw new RuntimeException('E2E safety check failed: effective database identity is not authorized');
    }

    $guardResult = $connection->query(
        "SELECT instance_id, purpose, marker_sha256 FROM rms_e2e_guard WHERE purpose = 'RMS_E2E_DISPOSABLE'"
    );
    if (!$guardResult instanceof mysqli_result || $guardResult->num_rows !== 1) {
        throw new RuntimeException('E2E safety check failed: disposable database marker is missing or ambiguous');
    }
    $guard = $guardResult->fetch_assoc();
    $expectedMarkerHash = hash('sha256', (string) rms_env('RMS_E2E_DB_MARKER'));
    if (!$guard || !rmsE2eMarkerMatches(
        (string) rms_env('RMS_E2E_INSTANCE_ID'),
        (string) rms_env('RMS_E2E_DB_MARKER'),
        $guard
    ) || !hash_equals($expectedMarkerHash, strtolower((string) $guard['marker_sha256']))) {
        throw new RuntimeException('E2E safety check failed: disposable database marker does not match this instance');
    }

    return [
        'configuredHost' => (string) DB_HOST,
        'configuredDatabase' => (string) DB_NAME,
        'configuredUser' => $expectedDatabaseUser,
        'database' => (string) $identity['database_name'],
        'serverHost' => (string) $identity['server_host'],
        'currentUser' => (string) $identity['current_user'],
        'instanceId' => (string) $guard['instance_id'],
        'markerVerified' => true,
    ];
}
