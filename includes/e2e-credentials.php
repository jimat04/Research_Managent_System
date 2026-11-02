<?php
declare(strict_types=1);

require_once __DIR__ . '/e2e-safety.php';

function rmsE2eSkipCredentialJsonWhitespace(string $contents, int &$offset): void
{
    $length = strlen($contents);
    while ($offset < $length && strpos(" \t\r\n", $contents[$offset]) !== false) {
        $offset++;
    }
}

function rmsE2eReadCredentialJsonString(string $contents, int &$offset): string
{
    $length = strlen($contents);
    if ($offset >= $length || $contents[$offset] !== '"') {
        throw new RuntimeException('Invalid credential JSON.');
    }

    $start = $offset++;
    while ($offset < $length) {
        if ($contents[$offset] === '\\') {
            $offset += 2;
            continue;
        }
        if ($contents[$offset] === '"') {
            $offset++;
            try {
                $value = json_decode(substr($contents, $start, $offset - $start), false, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                throw new RuntimeException('Invalid credential JSON.');
            }
            if (!is_string($value)) {
                throw new RuntimeException('Invalid credential JSON.');
            }
            return $value;
        }
        $offset++;
    }

    throw new RuntimeException('Invalid credential JSON.');
}

function rmsE2eDecodeCredentialJson(string $contents): array
{
    $offset = 0;
    $length = strlen($contents);
    rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
    if ($offset >= $length || $contents[$offset] !== '{') {
        throw new RuntimeException('Invalid credential JSON.');
    }
    $offset++;
    $values = [];
    $keys = [];
    rmsE2eSkipCredentialJsonWhitespace($contents, $offset);

    while ($offset < $length && $contents[$offset] !== '}') {
        $key = rmsE2eReadCredentialJsonString($contents, $offset);
        if (array_key_exists($key, $keys)) {
            throw new RuntimeException('Invalid credential JSON.');
        }
        $keys[$key] = true;

        rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
        if ($offset >= $length || $contents[$offset] !== ':') {
            throw new RuntimeException('Invalid credential JSON.');
        }
        $offset++;
        rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
        $values[$key] = rmsE2eReadCredentialJsonString($contents, $offset);

        rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
        if ($offset < $length && $contents[$offset] === ',') {
            $offset++;
            rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
            if ($offset >= $length || $contents[$offset] === '}') {
                throw new RuntimeException('Invalid credential JSON.');
            }
            continue;
        }
        break;
    }

    if ($offset >= $length || $contents[$offset] !== '}') {
        throw new RuntimeException('Invalid credential JSON.');
    }
    $offset++;
    rmsE2eSkipCredentialJsonWhitespace($contents, $offset);
    if ($offset !== $length) {
        throw new RuntimeException('Invalid credential JSON.');
    }

    return $values;
}

function rmsE2eLoadAppSqlCredentials(string $directory, string $documentRoot): array
{
    $isDriveAbsolute = strlen($directory) >= 3
        && ctype_alpha($directory[0])
        && $directory[1] === ':'
        && ($directory[2] === '\\' || $directory[2] === '/');
    $isAbsolute = DIRECTORY_SEPARATOR === '\\'
        ? ($isDriveAbsolute || str_starts_with($directory, '\\\\'))
        : str_starts_with($directory, DIRECTORY_SEPARATOR);
    if ($directory === '' || !$isAbsolute || !is_dir($directory)
        || rmsE2eHasReparsePoint($directory)) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $canonicalDirectory = realpath($directory);
    $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/');
    $normalizedCanonical = $canonicalDirectory === false
        ? ''
        : rtrim(str_replace('\\', '/', $canonicalDirectory), '/');
    if ($canonicalDirectory === false
        || (DIRECTORY_SEPARATOR === '\\'
            ? strtolower($normalizedDirectory) !== strtolower($normalizedCanonical)
            : $normalizedDirectory !== $normalizedCanonical)) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $serverDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $canonicalDocumentRoot = rmsE2eValidateDocumentRoot(
        $documentRoot,
        is_string($serverDocumentRoot) ? $serverDocumentRoot : null,
        PHP_SAPI !== 'cli'
    );
    if (rmsE2ePathIsInside($canonicalDocumentRoot, $canonicalDirectory)) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $applicationRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
    $normalizedApplicationRoot = $applicationRoot === false
        ? ''
        : rtrim(str_replace('\\', '/', $applicationRoot), '/') . '/';
    $normalizedCredentialRoot = rtrim(str_replace('\\', '/', $canonicalDirectory), '/') . '/';
    if ($applicationRoot === false
        || (DIRECTORY_SEPARATOR === '\\'
            ? (str_starts_with(strtolower($normalizedCredentialRoot), strtolower($normalizedApplicationRoot))
                || str_starts_with(strtolower($normalizedApplicationRoot), strtolower($normalizedCredentialRoot)))
            : (str_starts_with($normalizedCredentialRoot, $normalizedApplicationRoot)
                || str_starts_with($normalizedApplicationRoot, $normalizedCredentialRoot)))) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $path = $canonicalDirectory . DIRECTORY_SEPARATOR . 'app-sql-credentials.json';
    $stat = @lstat($path);
    if ($stat === false || !is_file($path) || rmsE2eHasReparsePoint($path)) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    try {
        $credentials = rmsE2eDecodeCredentialJson($contents);
    } catch (Throwable $error) {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    $keys = array_keys($credentials);
    sort($keys);
    if ($keys !== ['host', 'password']
        || $credentials['host'] !== 'localhost'
        || trim($credentials['password']) === '') {
        throw new RuntimeException('E2E application SQL credentials are unavailable or invalid.');
    }

    return ['host' => $credentials['host'], 'password' => $credentials['password']];
}

function rmsE2eLoadAppSqlConfiguration(callable $environment, string $credentialDirectory): array
{
    $databaseName = $environment('DB_NAME', '');
    $databaseUser = $environment('DB_USER', '');
    $expectedDatabaseName = $environment('RMS_E2E_DB_NAME', '');
    $expectedDatabaseUser = $environment('RMS_E2E_DB_USER', '');
    $expectedDatabaseHost = $environment('RMS_E2E_DB_HOST', '');

    if (!is_string($databaseName)
        || $databaseName !== 'rms_e2e_disposable'
        || $databaseName !== $expectedDatabaseName
        || !is_string($databaseUser)
        || !preg_match('/^[A-Za-z0-9_$.-]+$/', $databaseUser)
        || $databaseUser === ''
        || $databaseUser !== $expectedDatabaseUser
        || $expectedDatabaseHost !== 'localhost') {
        throw new RuntimeException('E2E application database configuration is unavailable or invalid.');
    }

    $documentRoot = $environment('RMS_E2E_DOCUMENT_ROOT', '');
    if (!is_string($documentRoot) || $documentRoot === '') {
        throw new RuntimeException('E2E application database configuration is unavailable or invalid.');
    }
    $credentials = rmsE2eLoadAppSqlCredentials($credentialDirectory, $documentRoot);
    if ($credentials['host'] !== $expectedDatabaseHost) {
        throw new RuntimeException('E2E application database configuration is unavailable or invalid.');
    }

    return [
        'host' => $credentials['host'],
        'name' => $databaseName,
        'user' => $databaseUser,
        'password' => $credentials['password'],
    ];
}