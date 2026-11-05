<?php
/** External ONLYOFFICE settings. Keep the JWT secret outside source control. */
$setting = static function (string $name, string $fallback = ''): string {
    if (function_exists('rms_env')) return (string) rms_env($name, $fallback);
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : $fallback;
};
$enabled = $setting('EARIST_DOCX_EDITOR_ENABLED') === '1';
$url = rtrim(trim($setting('EARIST_ONLYOFFICE_URL')), '/');
$secret = $setting('EARIST_ONLYOFFICE_JWT_SECRET');
$rmsUrl = rtrim(trim($setting('EARIST_DOCX_EDITOR_RMS_URL', defined('SITE_URL') ? SITE_URL : '')), '/') . '/';
$validUrl = false;
if ($url !== '') {
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $validUrl = is_array($parts) && in_array($parts['scheme'] ?? '', $loopback ? ['http', 'https'] : ['https'], true)
        && $host !== '' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
}
$rmsParts = parse_url($rmsUrl);
$validRmsUrl = is_array($rmsParts) && in_array($rmsParts['scheme'] ?? '', ['http', 'https'], true)
    && is_string($rmsParts['host'] ?? null) && $rmsParts['host'] !== ''
    && !isset($rmsParts['user']) && !isset($rmsParts['pass']) && !isset($rmsParts['query']) && !isset($rmsParts['fragment']);
return ['enabled' => $enabled && $validUrl && $validRmsUrl && strlen($secret) >= 32 && extension_loaded('curl'),
    'url' => $url, 'jwt_secret' => $secret, 'session_seconds' => 7200,
    'rms_url' => $rmsUrl, 'read_token_seconds' => 600, 'max_callback_bytes' => 52428800];
