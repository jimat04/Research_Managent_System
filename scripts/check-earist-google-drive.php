<?php
/** Pure Google Drive OAuth, token-protection, and link-policy checks. No DB or HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SITE_URL', 'https://rms.example.test/rms/');
define('APP_ENV', 'production');
$settings = [
    'EARIST_GOOGLE_DRIVE_ENABLED' => '1',
    'EARIST_GOOGLE_CLIENT_ID' => 'synthetic-client-id',
    'EARIST_GOOGLE_CLIENT_SECRET' => 'synthetic-client-secret',
    'EARIST_GOOGLE_REDIRECT_URI' => 'https://rms.example.test/rms/pages/faculty/earist-google-drive-callback.php',
    'EARIST_GOOGLE_TOKEN_ENCRYPTION_KEY' => str_repeat('a', 64),
];
function rms_env($key, $default = null) { global $settings; return $settings[$key] ?? $default; }
require_once __DIR__ . '/../includes/earist-google-drive.php';

$passed = 0;
$failed = [];
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $label;
};
$reject = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); $check(false, $label); }
    catch (DomainException | RuntimeException $exception) { $check(true, $label); }
};

$check(earistGoogleDriveConfig()['enabled'] === true, 'Complete production HTTPS configuration enables the integration');
$token = 'synthetic-google-refresh-token';
$encrypted = earistGoogleDriveEncryptToken($token);
$check($encrypted !== $token && earistGoogleDriveDecryptToken($encrypted) === $token, 'Refresh token encrypts and decrypts with AES-256-GCM');
$packed = base64_decode($encrypted, true);
$packed[28] = chr(ord($packed[28]) ^ 1);
$tampered = base64_encode($packed);
$reject(static fn() => earistGoogleDriveDecryptToken($tampered), 'Tampered refresh-token ciphertext is rejected');

$now = time();
$oauthState = ['state' => str_repeat('a', 64), 'owner_id' => 91, 'created_at' => $now - 5];
try { earistGoogleDriveValidateOAuthState($oauthState, str_repeat('a', 64), 91, $now); $check(true, 'Correct unexpired state and Faculty owner are accepted'); }
catch (Throwable $exception) { $check(false, 'Correct unexpired state and Faculty owner are accepted'); }
$reject(static fn() => earistGoogleDriveValidateOAuthState($oauthState, str_repeat('b', 64), 91, $now), 'Mismatched OAuth state is rejected');
$reject(static fn() => earistGoogleDriveValidateOAuthState($oauthState, str_repeat('a', 64), 92, $now), 'OAuth state cannot cross RMS accounts');
$reject(static fn() => earistGoogleDriveValidateOAuthState(['state' => str_repeat('a', 64), 'owner_id' => 91, 'created_at' => $now - 601], str_repeat('a', 64), 91, $now), 'Expired OAuth state is rejected');
$reject(static fn() => earistGoogleDriveValidateOAuthState(['state' => str_repeat('a', 64), 'owner_id' => 91, 'created_at' => $now + 1], str_repeat('a', 64), 91, $now), 'Future-issued OAuth state is rejected');

try { earistGoogleDriveValidateWebLink('https://drive.google.com/file/d/synthetic/view'); $check(true, 'Google Drive HTTPS editor URL is allowed'); }
catch (Throwable $exception) { $check(false, 'Google Drive HTTPS editor URL is allowed'); }
$reject(static fn() => earistGoogleDriveValidateWebLink('https://drive.google.com.attacker.example/file/d/synthetic'), 'Lookalike Google host is rejected');
$reject(static fn() => earistGoogleDriveValidateWebLink('http://drive.google.com/file/d/synthetic'), 'Insecure HTTP editor URL is rejected');

if ($failed) {
    fwrite(STDERR, 'FAILED (' . count($failed) . '/' . ($passed + count($failed)) . "): \n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo 'Passed ' . $passed . " synthetic Google Drive checks.\n";
