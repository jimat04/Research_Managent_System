<?php

$setting = static fn(string $key, string $default = ''): string => trim((string) rms_env($key, $default));
$siteUrl = rtrim((string) SITE_URL, '/') . '/';
$clientId = $setting('EARIST_GOOGLE_CLIENT_ID');
$clientSecret = $setting('EARIST_GOOGLE_CLIENT_SECRET');
$redirectUri = $setting('EARIST_GOOGLE_REDIRECT_URI', $siteUrl . 'pages/faculty/earist-google-drive-callback.php');
$encryptionKey = $setting('EARIST_GOOGLE_TOKEN_ENCRYPTION_KEY');
$enabled = filter_var($setting('EARIST_GOOGLE_DRIVE_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN);

$redirect = parse_url($redirectUri);
$site = parse_url($siteUrl);
$redirectParts = is_array($redirect) ? $redirect : [];
$siteParts = is_array($site) ? $site : [];
$siteHost = strtolower((string) ($siteParts['host'] ?? ''));
$sitePath = rtrim((string) ($siteParts['path'] ?? ''), '/');
$expectedCallbackPath = $sitePath . '/pages/faculty/earist-google-drive-callback.php';
$localHttpAllowed = APP_ENV === 'development' && ($siteParts['scheme'] ?? '') === 'http'
    && in_array($siteHost, ['localhost', '127.0.0.1', '[::1]'], true);
$transportValid = ($siteParts['scheme'] ?? '') === 'https' || $localHttpAllowed;
$redirectPort = (int) ($redirectParts['port'] ?? (($redirectParts['scheme'] ?? '') === 'https' ? 443 : 80));
$sitePort = (int) ($siteParts['port'] ?? (($siteParts['scheme'] ?? '') === 'https' ? 443 : 80));
$redirectValid = is_array($redirect) && is_array($site)
    && $transportValid
    && ($redirectParts['scheme'] ?? '') === ($siteParts['scheme'] ?? '')
    && strtolower((string) ($redirectParts['host'] ?? '')) === $siteHost
    && $redirectPort === $sitePort
    && (string) ($redirectParts['path'] ?? '') === $expectedCallbackPath
    && !isset($redirectParts['user'])
    && !isset($redirectParts['pass'])
    && !isset($redirectParts['query'])
    && !isset($redirectParts['fragment']);

return [
    'enabled' => $enabled && $clientId !== '' && $clientSecret !== '' && $redirectValid
        && preg_match('/^[a-f0-9]{64}$/D', $encryptionKey) === 1 && extension_loaded('openssl') && function_exists('curl_init'),
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'token_encryption_key' => preg_match('/^[a-f0-9]{64}$/D', $encryptionKey) === 1 ? hex2bin($encryptionKey) : '',
    'api_base' => 'https://www.googleapis.com/drive/v3',
    'upload_base' => 'https://www.googleapis.com/upload/drive/v3',
    'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'oauth_token_url' => 'https://oauth2.googleapis.com/token',
    'oauth_revoke_url' => 'https://oauth2.googleapis.com/revoke',
    'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
];
