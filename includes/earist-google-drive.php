<?php
require_once __DIR__ . '/earist-document-generation.php';

function earistGoogleDriveConfig(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../config/earist-google-drive.php';
}

function earistGoogleDriveAssertEnabled(): array
{
    $config = earistGoogleDriveConfig();
    if (!$config['enabled']) throw new DomainException('Google Docs editing is not configured. Preview and download remain available.', 503);
    return $config;
}

function earistGoogleDriveEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function earistGoogleDriveEncryptToken(string $token): string
{
    $key = earistGoogleDriveAssertEnabled()['token_encryption_key'];
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'earist-google-refresh-token', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Google authorization could not be stored securely.');
    return base64_encode($nonce . $tag . $ciphertext);
}

function earistGoogleDriveDecryptToken(string $encoded): string
{
    $config = earistGoogleDriveAssertEnabled();
    $packed = base64_decode($encoded, true);
    if (!is_string($packed) || strlen($packed) < 29) throw new RuntimeException('Google authorization is unavailable. Reconnect your Google account.');
    $token = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', $config['token_encryption_key'], OPENSSL_RAW_DATA,
        substr($packed, 0, 12), substr($packed, 12, 16), 'earist-google-refresh-token');
    if (!is_string($token) || $token === '') throw new RuntimeException('Google authorization could not be decrypted. Reconnect your Google account.');
    return $token;
}

function earistGoogleDriveHttp(string $url, string $method, array $headers = [], ?string $body = null, int $maxBytes = 1048576): array
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['accounts.google.com', 'oauth2.googleapis.com', 'www.googleapis.com'], true)) {
        throw new RuntimeException('The Google service address is invalid.');
    }
    $response = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxBytes): int {
            if (strlen($response) + strlen($chunk) > $maxBytes) return 0;
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    if ($ok === false || $error !== 0) {
        throw new RuntimeException('Google could not be reached or returned an oversized response.', $error ?: 1);
    }
    return ['status' => $code, 'body' => $response];
}

function earistGoogleDriveJson(array $response, string $message): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $status = (int) ($response['status'] ?? 0);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $error = $decoded['error'] ?? null;
        $reason = is_array($error)
            ? ($error['errors'][0]['reason'] ?? $error['status'] ?? '')
            : (is_string($error) ? $error : '');
        $reason = is_string($reason) && preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $reason) ? $reason : 'unknown';
        error_log('EARIST Google API response failed: http_status=' . ($status > 0 ? $status : 'unknown') . ', reason=' . $reason);
        throw new RuntimeException($message, $status > 0 ? $status : 1);
    }
    return $decoded;
}

function earistGoogleDrivePostForm(string $url, array $values): array
{
    return earistGoogleDriveHttp($url, 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($values), 65536);
}

function earistGoogleDriveCurrentConnection(mysqli $conn, int $ownerId): ?array
{
    $stmt = $conn->prepare('SELECT owner_id, google_subject, google_email, refresh_token_ciphertext, connected_at, updated_at FROM earist_google_drive_connections WHERE owner_id = ? LIMIT 1');
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function earistGoogleDriveConnectionStatus(mysqli $conn, int $ownerId): ?array
{
    $row = earistGoogleDriveCurrentConnection($conn, $ownerId);
    if (!$row) return null;
    return ['google_email' => (string) $row['google_email'], 'connected_at' => (string) $row['connected_at']];
}

function earistGoogleDriveStartOAuth(int $ownerId): never
{
    $config = earistGoogleDriveAssertEnabled();
    $state = bin2hex(random_bytes(32));
    $_SESSION['earist_google_oauth_state'] = ['state' => $state, 'owner_id' => $ownerId, 'created_at' => time()];
    $query = http_build_query([
        'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'], 'response_type' => 'code',
        'scope' => 'openid email https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline',
        'include_granted_scopes' => 'true', 'prompt' => 'consent', 'state' => $state,
    ]);
    header('Location: ' . $config['oauth_authorize_url'] . '?' . $query, true, 302);
    exit;
}

function earistGoogleDriveValidateOAuthState(?array $state, mixed $providedState, int $ownerId, ?int $now = null): void
{
    $now ??= time();
    if (!is_array($state) || !is_string($state['state'] ?? null) || !is_string($providedState)
        || !preg_match('/^[a-f0-9]{64}$/D', $state['state']) || !hash_equals($state['state'], $providedState)
        || (int) ($state['owner_id'] ?? 0) !== $ownerId || !is_int($state['created_at'] ?? null)
        || $now - $state['created_at'] > 600 || $now < $state['created_at']) {
        throw new DomainException('Google authorization expired. Connect your account again.', 400);
    }
}

function earistGoogleDriveOAuthCallback(mysqli $conn, int $ownerId, array $query): void
{
    $config = earistGoogleDriveAssertEnabled();
    $state = $_SESSION['earist_google_oauth_state'] ?? null;
    unset($_SESSION['earist_google_oauth_state']);
    earistGoogleDriveValidateOAuthState(is_array($state) ? $state : null, $query['state'] ?? null, $ownerId);
    if (isset($query['error'])) throw new DomainException('Google account connection was canceled or denied.', 400);
    $code = is_string($query['code'] ?? null) ? $query['code'] : '';
    if ($code === '' || strlen($code) > 4096) throw new DomainException('Google did not return an authorization code.', 400);
    $tokens = earistGoogleDriveJson(earistGoogleDrivePostForm($config['oauth_token_url'], [
        'code' => $code, 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'], 'grant_type' => 'authorization_code',
    ]), 'Google account connection failed. Try connecting again.');
    $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
    if ($accessToken === '') throw new RuntimeException('Google did not authorize Drive access. Connect your account again.');
    $profileResponse = earistGoogleDriveHttp($config['userinfo_url'], 'GET', ['Authorization: Bearer ' . $accessToken], null, 65536);
    $profile = earistGoogleDriveJson($profileResponse, 'Google account details could not be confirmed.');
    $subject = is_string($profile['sub'] ?? null) ? $profile['sub'] : '';
    $email = is_string($profile['email'] ?? null) ? $profile['email'] : '';
    if ($subject === '' || strlen($subject) > 255 || $email === '' || strlen($email) > 255
        || filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
        throw new RuntimeException('Google did not provide a verified account email.');
    }
    $refresh = is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : '';
    if ($refresh === '') {
        $existing = earistGoogleDriveCurrentConnection($conn, $ownerId);
        if (!$existing || !hash_equals((string) $existing['google_subject'], $subject)) {
            throw new RuntimeException('Google did not provide a refresh token. Disconnect and connect again to grant persistent Drive access.');
        }
        $encrypted = (string) $existing['refresh_token_ciphertext'];
    } else {
        $encrypted = earistGoogleDriveEncryptToken($refresh);
    }
    $stmt = $conn->prepare('INSERT INTO earist_google_drive_connections (owner_id, google_subject, google_email, refresh_token_ciphertext, connected_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE google_subject = VALUES(google_subject), google_email = VALUES(google_email), refresh_token_ciphertext = VALUES(refresh_token_ciphertext), updated_at = UTC_TIMESTAMP()');
    $stmt->bind_param('isss', $ownerId, $subject, $email, $encrypted);
    $stmt->execute();
    $stmt->close();
}

function earistGoogleDriveRequestAccessToken(mysqli $conn, int $ownerId): array
{
    $config = earistGoogleDriveAssertEnabled();
    $connection = earistGoogleDriveCurrentConnection($conn, $ownerId);
    if (!$connection) throw new DomainException('Connect a Google account before editing in Google Docs.', 401);
    $refresh = earistGoogleDriveDecryptToken((string) $connection['refresh_token_ciphertext']);
    $response = earistGoogleDrivePostForm($config['oauth_token_url'], [
        'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
        'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
    ]);
    $tokens = json_decode((string) $response['body'], true);
    if ((int) $response['status'] === 400 && is_array($tokens) && ($tokens['error'] ?? '') === 'invalid_grant') {
        $stmt = $conn->prepare('DELETE FROM earist_google_drive_connections WHERE owner_id = ?');
        $stmt->bind_param('i', $ownerId); $stmt->execute(); $stmt->close();
        throw new DomainException('Google authorization expired or was revoked. Connect your account again.', 401);
    }
    $tokens = earistGoogleDriveJson($response, 'Google authorization could not be refreshed. Reconnect your Google account.');
    if (!is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') throw new RuntimeException('Google authorization could not be refreshed.');
    return ['access_token' => $tokens['access_token'], 'connection' => $connection];
}

function earistGoogleDriveApiJson(mysqli $conn, int $ownerId, string $method, string $url, ?string $body = null, array $headers = []): array
{
    $auth = earistGoogleDriveRequestAccessToken($conn, $ownerId);
    return earistGoogleDriveJson(earistGoogleDriveHttp($url, $method,
        array_merge(['Authorization: Bearer ' . $auth['access_token']], $headers), $body, 1048576), 'Google Drive request failed. Try again.');
}

function earistGoogleDriveFileUrl(string $id): string
{
    return 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id);
}

function earistGoogleDriveValidateWebLink(string $url): void
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')), ['drive.google.com', 'docs.google.com'], true)) {
        throw new RuntimeException('Google returned an invalid document link.');
    }
}

function earistGoogleDriveReadHandle($handle, int $expectedSize): string
{
    $max = (int) earistDocumentStorageConfig()['max_docx_bytes'];
    if ($expectedSize < 1 || $expectedSize > $max) throw new DomainException('The DOCX exceeds RMS storage limits.', 413);
    $bytes = stream_get_contents($handle, $max + 1);
    if (!is_string($bytes) || strlen($bytes) !== $expectedSize || strlen($bytes) > $max) {
        throw new RuntimeException('The DOCX could not be read completely.');
    }
    return $bytes;
}

function earistGoogleDriveCreateWorkingCopy(mysqli $conn, int $ownerId, int $generatedId, string $selector): array
{
    $config = earistGoogleDriveAssertEnabled();
    $opened = earistDocumentFacultyHistoryOpen($conn, $generatedId, $selector);
    try {
        $source = $opened['document'];
        if ($source['document_type'] === 'annex_d') throw new DomainException('Admin Annex D is preview only.', 403);
        $auth = earistGoogleDriveRequestAccessToken($conn, $ownerId);
        $subject = (string) $auth['connection']['google_subject'];
        $existing = $conn->prepare('SELECT drive_file_id, web_view_link, file_name FROM earist_google_drive_files WHERE owner_id = ? AND google_subject = ? AND generated_document_id = ? AND file_selector = ? ORDER BY created_at DESC LIMIT 1');
        $existing->bind_param('isis', $ownerId, $subject, $generatedId, $selector);
        $existing->execute();
        $link = $existing->get_result()->fetch_assoc() ?: null;
        $existing->close();
        if ($link) {
            earistGoogleDriveValidateWebLink((string) $link['web_view_link']);
            return $link;
        }
        $bytes = earistGoogleDriveReadHandle($opened['handle'], (int) $opened['size']);
        earistDocumentValidateDocx($bytes);
        $file = earistDocumentManifestFile($opened['snapshot']['file_manifest'], $selector);
        $metadata = json_encode(['name' => $file['filename'], 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'appProperties' => ['rms_owner_id' => (string) $ownerId, 'rms_generated_document_id' => (string) $generatedId, 'rms_file_selector' => $selector]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $boundary = 'rms_' . bin2hex(random_bytes(18));
        $body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . $metadata
            . "\r\n--" . $boundary . "\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\n\r\n"
            . $bytes . "\r\n--" . $boundary . "--\r\n";
        $created = earistGoogleDriveApiJson($conn, $ownerId, 'POST', $config['upload_base'] . '/files?uploadType=multipart&fields=id,name,mimeType,webViewLink,version', $body,
            ['Content-Type: multipart/related; boundary=' . $boundary]);
        $fileId = is_string($created['id'] ?? null) ? $created['id'] : '';
        $webLink = is_string($created['webViewLink'] ?? null) ? $created['webViewLink'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{10,128}$/D', $fileId) || ($created['mimeType'] ?? '') !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            throw new RuntimeException('Google Drive did not create a valid DOCX working copy.');
        }
        earistGoogleDriveValidateWebLink($webLink);
        $version = is_scalar($created['version'] ?? null) ? (string) $created['version'] : null;
        $stmt = $conn->prepare('INSERT INTO earist_google_drive_files (drive_file_id, owner_id, google_subject, generated_document_id, file_selector, file_name, web_view_link, drive_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->bind_param('sisissss', $fileId, $ownerId, $subject, $generatedId, $selector, $file['filename'], $webLink, $version);
        $stmt->execute();
        $stmt->close();
        return ['drive_file_id' => $fileId, 'web_view_link' => $webLink, 'file_name' => $file['filename']];
    } finally {
        fclose($opened['handle']);
    }
}

function earistGoogleDriveLinksForHistory(mysqli $conn, int $ownerId, array $generatedIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $generatedIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    $connection = earistGoogleDriveCurrentConnection($conn, $ownerId);
    if (!$connection) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT f.* FROM earist_google_drive_files f WHERE f.owner_id = ? AND f.google_subject = ? AND f.generated_document_id IN ($placeholders) AND NOT EXISTS (SELECT 1 FROM earist_google_drive_files newer WHERE newer.owner_id = f.owner_id AND newer.google_subject = f.google_subject AND newer.generated_document_id = f.generated_document_id AND newer.file_selector = f.file_selector AND (newer.created_at > f.created_at OR (newer.created_at = f.created_at AND newer.drive_file_id > f.drive_file_id))) ORDER BY f.created_at DESC, f.drive_file_id DESC";
    $stmt = $conn->prepare($sql);
    $types = 'is' . str_repeat('i', count($ids));
    $values = array_merge([$ownerId, (string) $connection['google_subject']], $ids);
    $refs = [];
    foreach ($values as $index => $value) $refs[$index] = &$values[$index];
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = [];
    foreach ($rows as $row) $result[(int) $row['generated_document_id']][(string) $row['file_selector']] = $row;
    return $result;
}

function earistGoogleDriveDownloadLatest(mysqli $conn, int $ownerId, string $fileId): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{10,128}$/D', $fileId)) throw new DomainException('Google Drive file is unavailable.', 404);
    $stmt = $conn->prepare('SELECT * FROM earist_google_drive_files WHERE drive_file_id = ? AND owner_id = ? LIMIT 1');
    $stmt->bind_param('si', $fileId, $ownerId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$link) throw new DomainException('Google Drive file is unavailable.', 404);
    $auth = earistGoogleDriveRequestAccessToken($conn, $ownerId);
    if (!hash_equals((string) $link['google_subject'], (string) $auth['connection']['google_subject'])) {
        throw new DomainException('Reconnect the Google account that owns this working copy.', 403);
    }
    $fields = rawurlencode('id,name,mimeType,size,version,appProperties');
    $metadata = earistGoogleDriveApiJson($conn, $ownerId, 'GET', earistGoogleDriveFileUrl($fileId) . '?fields=' . $fields);
    $properties = $metadata['appProperties'] ?? [];
    if (($metadata['id'] ?? '') !== $fileId || ($metadata['mimeType'] ?? '') !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        || !is_array($properties) || (string) ($properties['rms_owner_id'] ?? '') !== (string) $ownerId
        || (string) ($properties['rms_generated_document_id'] ?? '') !== (string) $link['generated_document_id']
        || (string) ($properties['rms_file_selector'] ?? '') !== (string) $link['file_selector']) {
        throw new DomainException('The Google Drive file changed type or no longer matches this RMS working copy.', 409);
    }
    $size = filter_var($metadata['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int) earistDocumentStorageConfig()['max_docx_bytes']]]);
    if ($size === false) throw new DomainException('The DOCX exceeds RMS storage limits.', 413);
    $remoteVersion = is_scalar($metadata['version'] ?? null) ? (string) $metadata['version'] : '';
    if (!preg_match('/^[0-9]{1,64}$/D', $remoteVersion)) throw new RuntimeException('Google Drive did not return a valid file version.');
    $auth = earistGoogleDriveRequestAccessToken($conn, $ownerId);
    $download = earistGoogleDriveHttp(earistGoogleDriveFileUrl($fileId) . '?alt=media', 'GET',
        ['Authorization: Bearer ' . $auth['access_token']], null, (int) earistDocumentStorageConfig()['max_docx_bytes']);
    if ((int) $download['status'] !== 200 || strlen((string) $download['body']) !== (int) $size) throw new RuntimeException('The latest Google Drive DOCX could not be downloaded completely.');
    $confirmed = earistGoogleDriveApiJson($conn, $ownerId, 'GET', earistGoogleDriveFileUrl($fileId) . '?fields=' . $fields);
    if ((string) ($confirmed['version'] ?? '') !== $remoteVersion) {
        throw new DomainException('The Google Drive file changed during import. Try importing it again.', 409);
    }
    earistDocumentValidateDocx((string) $download['body']);
    return ['link' => $link, 'bytes' => $download['body'], 'drive_version' => $remoteVersion];
}

function earistGoogleDrivePublishEditedVersion(mysqli $conn, array $source, array $snapshot, array $manifest, array $link, string $remoteVersion): int
{
    $primary = earistDocumentManifestFile($manifest, 'primary');
    $sourceId = (int) $source['generated_document_id'];
    $ownerId = (int) $source['owner_id'];
    $requestHash = hash('sha256', 'google-drive:' . $link['drive_file_id'] . ':' . $remoteVersion);
    $newSnapshot = $snapshot;
    $newSnapshot['file_manifest'] = $manifest;
    $newSnapshot['edited_document'] = ['origin' => 'google_drive', 'edited_from_generated_document_id' => $sourceId,
        'edited_from_version' => (int) $source['document_version'], 'file_selector' => (string) $link['file_selector'],
        'google_drive_file_id' => (string) $link['drive_file_id'], 'google_drive_version' => $remoteVersion];
    $json = json_encode($newSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $conn->begin_transaction();
    try {
        $draftLock = $conn->prepare('SELECT draft_id FROM earist_document_drafts WHERE draft_id = ? AND research_record_id = ? AND owner_id = ? AND document_type = ? FOR UPDATE');
        $draftLock->bind_param('iiis', $source['draft_id'], $source['research_record_id'], $ownerId, $source['document_type']);
        $draftLock->execute(); $locked = $draftLock->get_result()->fetch_assoc() ?: null; $draftLock->close();
        if (!$locked) throw new RuntimeException('The source draft is unavailable for version allocation.');
        $lastStmt = $conn->prepare('SELECT document_version FROM earist_generated_documents WHERE research_record_id = ? AND document_type = ? ORDER BY document_version DESC LIMIT 1 FOR UPDATE');
        $lastStmt->bind_param('is', $source['research_record_id'], $source['document_type']);
        $lastStmt->execute(); $last = $lastStmt->get_result()->fetch_assoc() ?: null; $lastStmt->close();
        $existingStmt = $conn->prepare('SELECT generated_document_id FROM earist_generated_documents WHERE generated_by = ? AND request_token_hash = ? LIMIT 1');
        $existingStmt->bind_param('is', $ownerId, $requestHash); $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc() ?: null; $existingStmt->close();
        if ($existing) {
            $newId = (int) $existing['generated_document_id'];
        } else {
            $version = (int) ($last['document_version'] ?? 0) + 1;
            $insert = $conn->prepare("INSERT INTO earist_generated_documents (draft_id, research_record_id, owner_id, document_type, document_version, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, renderer_version, template_sha256, snapshot_json, missing_fields_json, warnings_json, warnings_acknowledged_at, preparation_mode, generated_by, request_token_hash, docx_path, docx_sha256, docx_size, version_origin, edited_from_generated_document_id) SELECT draft_id, research_record_id, owner_id, document_type, ?, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, 'google_drive', template_sha256, ?, missing_fields_json, warnings_json, warnings_acknowledged_at, preparation_mode, generated_by, ?, ?, ?, ?, 'edited', generated_document_id FROM earist_generated_documents WHERE generated_document_id = ? AND owner_id = ? AND generated_by = ? AND document_type <> 'annex_d'");
            $insert->bind_param('issssiiii', $version, $json, $requestHash, $primary['path'], $primary['sha256'], $primary['size'], $sourceId, $ownerId, $ownerId);
            $insert->execute(); $newId = (int) $conn->insert_id; $insert->close();
            if ($newId < 1) throw new RuntimeException('Google Docs version could not be saved to RMS history.');
        }
        $update = $conn->prepare('UPDATE earist_google_drive_files SET drive_version = ?, last_imported_drive_version = ?, last_imported_generated_document_id = ?, last_imported_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE drive_file_id = ? AND owner_id = ? AND generated_document_id = ? AND file_selector = ?');
        $update->bind_param('ssisiis', $remoteVersion, $remoteVersion, $newId, $link['drive_file_id'], $ownerId, $sourceId, $link['file_selector']);
        $update->execute();
        $update->close();
        $conn->commit();
        return $newId;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $check = $conn->prepare("SELECT generated_document_id FROM earist_generated_documents WHERE generated_by = ? AND request_token_hash = ? AND version_origin = 'edited' AND edited_from_generated_document_id = ? LIMIT 1");
            $check->bind_param('isi', $ownerId, $requestHash, $sourceId); $check->execute();
            $existing = $check->get_result()->fetch_assoc() ?: null; $check->close();
            if ($existing) {
                $newId = (int) $existing['generated_document_id'];
                $update = $conn->prepare('UPDATE earist_google_drive_files SET drive_version = ?, last_imported_drive_version = ?, last_imported_generated_document_id = ?, last_imported_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE drive_file_id = ? AND owner_id = ? AND generated_document_id = ? AND file_selector = ?');
                $update->bind_param('ssisiis', $remoteVersion, $remoteVersion, $newId, $link['drive_file_id'], $ownerId, $sourceId, $link['file_selector']);
                $update->execute(); $update->close();
                return $newId;
            }
        }
        throw $exception;
    }
}

function earistGoogleDriveImportToRms(mysqli $conn, int $ownerId, string $fileId): int
{
    $latest = earistGoogleDriveDownloadLatest($conn, $ownerId, $fileId);
    $link = $latest['link'];
    $opened = earistDocumentFacultyHistoryOpen($conn, (int) $link['generated_document_id'], (string) $link['file_selector']);
    try {
        $manifest = earistDocumentStoreEditedOutput($opened['snapshot']['file_manifest'], (string) $link['file_selector'], (string) $latest['bytes']);
        return earistGoogleDrivePublishEditedVersion($conn, $opened['document'], $opened['snapshot'], $manifest, $link, (string) $latest['drive_version']);
    } finally {
        fclose($opened['handle']);
    }
}

function earistGoogleDriveDisconnect(mysqli $conn, int $ownerId): bool
{
    $config = earistGoogleDriveAssertEnabled();
    $connection = earistGoogleDriveCurrentConnection($conn, $ownerId);
    if (!$connection) return true;
    $revoked = false;
    try {
        $token = earistGoogleDriveDecryptToken((string) $connection['refresh_token_ciphertext']);
        $response = earistGoogleDrivePostForm($config['oauth_revoke_url'], ['token' => $token]);
        $revoked = (int) $response['status'] >= 200 && (int) $response['status'] < 300;
    } catch (Throwable $exception) {
        error_log('EARIST Google token revocation failed: ' . get_class($exception));
    }
    $stmt = $conn->prepare('DELETE FROM earist_google_drive_connections WHERE owner_id = ?');
    $stmt->bind_param('i', $ownerId); $stmt->execute(); $stmt->close();
    return $revoked;
}
