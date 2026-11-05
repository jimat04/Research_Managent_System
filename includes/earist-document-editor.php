<?php
require_once __DIR__ . '/earist-document-generation.php';

function earistDocumentEditorConfig(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../config/earist-document-editor.php';
}

function earistDocumentEditorAssertEnabled(): array
{
    $config = earistDocumentEditorConfig();
    if (!$config['enabled']) throw new DomainException('DOCX editing is not configured. You can still preview or download this version.', 503);
    return $config;
}

function earistDocumentEditorJwt(array $claims, string $secret): string
{
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = $encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $header . '.' . $payload . '.' . $encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
}

function earistDocumentEditorVerifyJwt(string $token, string $secret): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new DomainException('Invalid editor callback.', 403);
    [$header, $payload, $signature] = $parts;
    $decodedHeader = json_decode(base64_decode(strtr($header, '-_', '+/')), true);
    $decodedPayload = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true)), '+/', '-_'), '=');
    if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? '') !== 'HS256' || !is_array($decodedPayload)
        || !hash_equals($expected, $signature)) throw new DomainException('Invalid editor callback.', 403);
    return $decodedPayload;
}

function earistDocumentEditorCreateSession(mysqli $conn, int $generatedId, string $selector): array
{
    $config = earistDocumentEditorAssertEnabled();
    if (!function_exists('isCsrfTokenValid')) throw new RuntimeException('CSRF validation is unavailable.');
    $open = earistDocumentFacultyHistoryOpen($conn, $generatedId, $selector);
    $document = $open['document'];
    if ($document['document_type'] === 'annex_d') throw new DomainException('Admin Annex D is preview only.', 403);
    $actor = earistDocumentCurrentActor($document['document_type']);
    $sessionId = bin2hex(random_bytes(32));
    $readToken = bin2hex(random_bytes(32));
    $key = hash('sha256', $sessionId . ':' . $generatedId . ':' . $selector);
    $expires = gmdate('Y-m-d H:i:s', time() + $config['session_seconds']);
    $file = earistDocumentManifestFile($open['snapshot']['file_manifest'], $selector);

    $conn->begin_transaction();
    try {
        $readExpires = gmdate('Y-m-d H:i:s', time() + $config['read_token_seconds']);
        $readTokenHash = hash('sha256', $readToken);
        $stmt = $conn->prepare("INSERT INTO earist_document_edit_sessions (session_id, generated_document_id, owner_id, file_selector, editor_key, source_sha256, read_token_sha256, read_token_expires_at, state, active_lock_generated_document_id, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->bind_param('siisssssis', $sessionId, $generatedId, $actor['user_id'], $selector, $key, $file['sha256'], $readTokenHash, $readExpires, $generatedId, $expires);
        $stmt->execute(); $stmt->close();
        $conn->commit();
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        if ((int) $exception->getCode() === 1062) throw new DomainException('Another editor is already active for this saved version.', 409);
        throw $exception;
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }

    $origin = $config['rms_url'];
    $readUrl = $origin . 'pages/shared/earist-document-edit-file.php?session=' . rawurlencode($sessionId) . '&token=' . rawurlencode($readToken);
    $callbackUrl = $origin . 'pages/shared/earist-document-edit-callback.php?session=' . rawurlencode($sessionId);
    $editorConfig = ['document' => ['fileType' => 'docx', 'key' => $key, 'title' => $file['filename'],
        'url' => $readUrl, 'permissions' => ['edit' => true, 'download' => true]],
        'editorConfig' => ['mode' => 'edit', 'callbackUrl' => $callbackUrl, 'user' => ['id' => (string) $actor['user_id'],
            'name' => trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? ''))]],
        'token' => ''];
    unset($editorConfig['token']);
    $editorConfig['token'] = earistDocumentEditorJwt($editorConfig, $config['jwt_secret']);
    fclose($open['handle']);
    return ['session_id' => $sessionId, 'config' => $editorConfig, 'api_url' => $config['url'] . '/web-apps/apps/api/documents/api.js'];
}

function earistDocumentEditorReadFile(mysqli $conn, string $sessionId, string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $sessionId) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new DomainException('Document unavailable.', 404);
    $stmt = $conn->prepare("SELECT s.*, s.owner_id AS session_owner_id, gd.*, d.draft_id AS joined_draft_id, d.research_record_id AS joined_draft_record_id, d.owner_id AS joined_draft_owner_id, d.document_type AS joined_draft_type, d.created_by AS joined_draft_creator, d.updated_by AS joined_draft_updater, r.research_record_id AS joined_source_id, r.owner_id AS joined_source_owner, r.version AS joined_source_version, r.status AS joined_source_status, r.deleted_at AS joined_source_deleted FROM earist_document_edit_sessions s JOIN earist_generated_documents gd ON gd.generated_document_id = s.generated_document_id JOIN earist_document_drafts d ON d.draft_id = gd.draft_id JOIN faculty_research_records r ON r.research_record_id = gd.research_record_id WHERE s.session_id = ? AND s.state = 'active' AND s.expires_at > UTC_TIMESTAMP() AND s.read_token_expires_at > UTC_TIMESTAMP() LIMIT 1");
    $stmt->bind_param('s', $sessionId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    if (!$row || !hash_equals((string) $row['read_token_sha256'], hash('sha256', $token))) throw new DomainException('Document unavailable.', 404);
    $actorStmt = $conn->prepare("SELECT user_id, first_name, last_name, role, status, deleted_at FROM users WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $actorStmt->bind_param('i', $row['owner_id']); $actorStmt->execute(); $actor = $actorStmt->get_result()->fetch_assoc() ?: null; $actorStmt->close();
    if (!$actor || ($actor['role'] ?? '') !== 'faculty' || (int) $row['session_owner_id'] !== (int) $row['owner_id']) throw new DomainException('Document unavailable.', 404);
    $draft = ['draft_id' => $row['joined_draft_id'], 'research_record_id' => $row['joined_draft_record_id'],
        'owner_id' => $row['joined_draft_owner_id'], 'document_type' => $row['joined_draft_type'],
        'created_by' => $row['joined_draft_creator'], 'updated_by' => $row['joined_draft_updater']];
    $source = ['research_record_id' => $row['joined_source_id'], 'owner_id' => $row['joined_source_owner'],
        'version' => $row['joined_source_version'], 'status' => $row['joined_source_status'], 'deleted_at' => $row['joined_source_deleted']];
    earistDocumentAssertHistory($actor, $row, $draft, $source);
    $snapshot = earistDocumentHistorySnapshot($row);
    $file = earistDocumentManifestFile($snapshot['file_manifest'], $row['file_selector']);
    if (!hash_equals($row['source_sha256'], $file['sha256'])) throw new DomainException('Document unavailable.', 404);
    $handle = earistDocumentOpenFile($file);
    return ['handle' => $handle, 'filename' => $file['filename'], 'size' => $file['size']];
}

function earistDocumentEditorStatus(mysqli $conn, string $sessionId, ?int $recoveryMarker = null): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $sessionId)) throw new DomainException('Edit session unavailable.', 404);
    $actor = earistDocumentCurrentActor('annex_a');
    $stmt = $conn->prepare('SELECT state, saved_generated_document_id, expires_at, UNIX_TIMESTAMP(updated_at) AS updated_at_epoch FROM earist_document_edit_sessions WHERE session_id = ? AND owner_id = ? LIMIT 1');
    $stmt->bind_param('si', $sessionId, $actor['user_id']); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    if (!$row) throw new DomainException('Edit session unavailable.', 404);
    $state = $row['state'];
    if ($state === 'active' && strtotime((string) $row['expires_at'] . ' UTC') < time()) $state = 'expired';
    $savedId = $row['saved_generated_document_id'] ? (int) $row['saved_generated_document_id'] : null;
    if ($state === 'active') $savedId = earistDocumentEditorFindSavedVersion($conn, (int) $actor['user_id'], $sessionId);
    $result = ['state' => $savedId && $state === 'active' ? 'save_ready' : $state, 'generated_document_id' => $savedId];
    if ($recoveryMarker !== null) $result['recovery_checked'] = (int) $row['updated_at_epoch'] > $recoveryMarker;
    return $result;
}

function earistDocumentEditorSaveCloseUserdata(string $sessionId): string
{
    return hash_hmac('sha256', 'save-close:' . $sessionId, earistDocumentEditorConfig()['jwt_secret']);
}

function earistDocumentEditorFindSavedVersion(mysqli $conn, int $ownerId, string $sessionId): ?int
{
    $requestHash = hash('sha256', 'edit:' . $sessionId);
    $stmt = $conn->prepare('SELECT generated_document_id FROM earist_generated_documents WHERE generated_by = ? AND request_token_hash = ? LIMIT 1');
    $stmt->bind_param('is', $ownerId, $requestHash); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    return $row ? (int) $row['generated_document_id'] : null;
}

function earistDocumentEditorRequestSaveClose(mysqli $conn, string $sessionId): array
{
    $config = earistDocumentEditorAssertEnabled();
    if (!preg_match('/^[a-f0-9]{64}$/D', $sessionId)) throw new DomainException('Edit session unavailable.', 404);
    $actor = earistDocumentCurrentActor('annex_a');
    if (($actor['role'] ?? '') !== 'faculty') throw new DomainException('Edit session access denied.', 403);
    $stmt = $conn->prepare("SELECT s.editor_key, s.expires_at, gd.owner_id, gd.generated_by, gd.document_type FROM earist_document_edit_sessions s JOIN earist_generated_documents gd ON gd.generated_document_id = s.generated_document_id WHERE s.session_id = ? AND s.owner_id = ? AND s.state = 'active' LIMIT 1");
    $stmt->bind_param('si', $sessionId, $actor['user_id']); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    if (!$row || (int) $row['owner_id'] !== (int) $actor['user_id'] || (int) $row['generated_by'] !== (int) $actor['user_id']
        || $row['document_type'] === 'annex_d' || strtotime((string) $row['expires_at'] . ' UTC') < time()) {
        throw new DomainException('Edit session is unavailable or expired.', 409);
    }
    $existing = earistDocumentEditorFindSavedVersion($conn, (int) $actor['user_id'], $sessionId);
    if ($existing) return ['accepted' => true, 'already_saved' => true];
    $command = ['c' => 'forcesave', 'key' => (string) $row['editor_key'], 'userdata' => earistDocumentEditorSaveCloseUserdata($sessionId)];
    $command['token'] = earistDocumentEditorJwt($command, $config['jwt_secret']);
    $curl = curl_init($config['url'] . '/command');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($command, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $response = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_errno($curl); curl_close($curl);
    $result = is_string($response) ? json_decode($response, true) : null;
    if ($error || $code !== 200 || !is_array($result) || (int) ($result['error'] ?? -1) === 6) {
        throw new RuntimeException('The editor did not accept the save request. Your document remains open.');
    }
    if ((int) ($result['error'] ?? -1) === 4) return ['accepted' => false, 'no_changes' => true];
    if ((int) ($result['error'] ?? -1) !== 0 || !hash_equals((string) $row['editor_key'], (string) ($result['key'] ?? ''))) {
        throw new RuntimeException('The editor could not save this document. Your document remains open.');
    }
    return ['accepted' => true, 'already_saved' => false];
}

function earistDocumentEditorRecoveryUserdata(string $sessionId, int $marker): string
{
    $signature = hash_hmac('sha256', 'recovery-check:' . $sessionId . ':' . $marker, earistDocumentEditorConfig()['jwt_secret']);
    return 'rms-recovery:' . $marker . ':' . $signature;
}

function earistDocumentEditorRequestRecoveryCheck(mysqli $conn, int $generatedId, string $selector): array
{
    $config = earistDocumentEditorAssertEnabled();
    $actor = earistDocumentCurrentActor('annex_a');
    if (($actor['role'] ?? '') !== 'faculty') throw new DomainException('Edit session access denied.', 403);
    $open = earistDocumentFacultyHistoryOpen($conn, $generatedId, $selector);
    $document = $open['document'];
    fclose($open['handle']);
    if ($document['document_type'] === 'annex_d') throw new DomainException('Admin Annex D is preview only.', 403);

    $stmt = $conn->prepare("SELECT session_id, editor_key, expires_at FROM earist_document_edit_sessions WHERE generated_document_id = ? AND owner_id = ? AND active_lock_generated_document_id = ? AND state = 'active' LIMIT 1");
    $stmt->bind_param('iii', $generatedId, $actor['user_id'], $generatedId); $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    if (!$session) return ['reopen' => true];

    $sessionId = (string) $session['session_id'];
    $reset = $conn->prepare("UPDATE earist_document_edit_sessions SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 SECOND) WHERE session_id = ? AND state = 'active'");
    $reset->bind_param('s', $sessionId); $reset->execute(); $reset->close();
    $markerStmt = $conn->prepare('SELECT UNIX_TIMESTAMP(updated_at) AS marker FROM earist_document_edit_sessions WHERE session_id = ? AND state = \'active\' LIMIT 1');
    $markerStmt->bind_param('s', $sessionId); $markerStmt->execute(); $markerRow = $markerStmt->get_result()->fetch_assoc() ?: null; $markerStmt->close();
    if (!$markerRow) return ['reopen' => true];
    $marker = (int) $markerRow['marker'];
    $editorKey = (string) $session['editor_key'];
    $command = ['c' => 'info', 'key' => $editorKey, 'userdata' => earistDocumentEditorRecoveryUserdata($sessionId, $marker)];
    $command['token'] = earistDocumentEditorJwt($command, $config['jwt_secret']);
    $curl = curl_init($config['url'] . '/command');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($command, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $response = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_errno($curl); curl_close($curl);
    $result = is_string($response) ? json_decode($response, true) : null;
    if ($error || $code !== 200 || !is_array($result)) {
        throw new RuntimeException('RMS could not confirm whether the other editor is closed. The lock was kept.');
    }
    $commandError = (int) ($result['error'] ?? -1);
    if ($commandError === 1 && is_string($result['key'] ?? null) && hash_equals($editorKey, $result['key'])) {
        earistDocumentEditorSetState($conn, $sessionId, 'closed', null);
        return ['reopen' => true];
    }
    if ($commandError !== 0) throw new RuntimeException('RMS could not confirm whether the other editor is closed. The lock was kept.');
    return ['reopen' => false, 'session_id' => $sessionId, 'marker' => $marker];
}

function earistDocumentEditorCallback(mysqli $conn, string $sessionId, string $jwt, array $body): void
{
    $config = earistDocumentEditorAssertEnabled();
    $claims = earistDocumentEditorVerifyJwt($jwt, $config['jwt_secret']);
    $signedBody = is_array($claims['payload'] ?? null) ? $claims['payload'] : $claims;
    if (!is_string($body['key'] ?? null) || !hash_equals((string) ($signedBody['key'] ?? ''), $body['key'])) {
        throw new DomainException('Invalid editor callback.', 403);
    }
    $stmt = $conn->prepare('SELECT s.*, s.owner_id AS session_owner_id, gd.*, d.draft_id AS joined_draft_id, d.research_record_id AS joined_draft_record_id, d.owner_id AS joined_draft_owner_id, d.document_type AS joined_draft_type, d.created_by AS joined_draft_creator, d.updated_by AS joined_draft_updater, r.research_record_id AS joined_source_id, r.owner_id AS joined_source_owner, r.version AS joined_source_version, r.status AS joined_source_status, r.deleted_at AS joined_source_deleted, u.first_name AS actor_first_name, u.last_name AS actor_last_name, u.role AS actor_role, u.status AS actor_status, u.deleted_at AS actor_deleted FROM earist_document_edit_sessions s JOIN earist_generated_documents gd ON gd.generated_document_id = s.generated_document_id JOIN earist_document_drafts d ON d.draft_id = gd.draft_id JOIN faculty_research_records r ON r.research_record_id = gd.research_record_id JOIN users u ON u.user_id = s.owner_id WHERE s.session_id = ? LIMIT 1');
    $stmt->bind_param('s', $sessionId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
    if (!$row || !hash_equals((string) $row['editor_key'], $body['key'])) throw new DomainException('Invalid editor callback.', 403);
    $actor = ['user_id' => $row['owner_id'], 'first_name' => $row['actor_first_name'], 'last_name' => $row['actor_last_name'],
        'role' => $row['actor_role'], 'status' => $row['actor_status'], 'deleted_at' => $row['actor_deleted']];
    $draft = ['draft_id' => $row['joined_draft_id'], 'research_record_id' => $row['joined_draft_record_id'],
        'owner_id' => $row['joined_draft_owner_id'], 'document_type' => $row['joined_draft_type'],
        'created_by' => $row['joined_draft_creator'], 'updated_by' => $row['joined_draft_updater']];
    $sourceRow = ['research_record_id' => $row['joined_source_id'], 'owner_id' => $row['joined_source_owner'],
        'version' => $row['joined_source_version'], 'status' => $row['joined_source_status'], 'deleted_at' => $row['joined_source_deleted']];
    earistDocumentAssertHistory($actor, $row, $draft, $sourceRow);
    if ((int) $row['owner_id'] !== (int) $row['generated_by'] || (int) $row['session_owner_id'] !== (int) $row['owner_id']
        || $row['document_type'] === 'annex_d') throw new DomainException('Edit session access denied.', 403);
    $recoveryUserdata = $body['userdata'] ?? null;
    if (is_string($recoveryUserdata) && str_starts_with($recoveryUserdata, 'rms-recovery:')) {
        if (!preg_match('/^rms-recovery:([0-9]{10}):([a-f0-9]{64})$/D', $recoveryUserdata, $recoveryMatch)) {
            throw new DomainException('Invalid recovery callback.', 403);
        }
        $marker = (int) $recoveryMatch[1];
        $expectedRecovery = hash_hmac('sha256', 'recovery-check:' . $sessionId . ':' . $marker, $config['jwt_secret']);
        if ($marker > time() || $marker < time() - 600 || !hash_equals($expectedRecovery, $recoveryMatch[2])) {
            throw new DomainException('Invalid recovery callback.', 403);
        }
        if ((int) ($body['error'] ?? -1) === 0 && hash_equals((string) $row['editor_key'], (string) ($body['key'] ?? ''))
            && is_array($body['users'] ?? null) && ($row['state'] ?? '') === 'active') {
            if (count($body['users']) === 0) {
                $savedId = earistDocumentEditorFindSavedVersion($conn, (int) $row['owner_id'], $sessionId);
                earistDocumentEditorSetState($conn, $sessionId, $savedId ? 'saved' : 'closed', $savedId);
            } else {
                $touch = $conn->prepare("UPDATE earist_document_edit_sessions SET updated_at = UTC_TIMESTAMP() WHERE session_id = ? AND state = 'active'");
                $touch->bind_param('s', $sessionId); $touch->execute(); $touch->close();
            }
        }
        return;
    }
    $status = (int) ($body['status'] ?? -1);
    if ($status === 4) {
        $savedId = earistDocumentEditorFindSavedVersion($conn, (int) $row['owner_id'], $sessionId);
        earistDocumentEditorSetState($conn, $sessionId, $savedId ? 'saved' : 'closed', $savedId);
        return;
    }
    if ($status === 7) {
        if (is_string($body['userdata'] ?? null) && hash_equals(earistDocumentEditorSaveCloseUserdata($sessionId), $body['userdata'])) {
            earistDocumentEditorSetState($conn, $sessionId, 'failed', null);
        }
        return;
    }
    if ($status !== 2 && $status !== 6) return;
    if (($row['state'] ?? '') === 'saved') return;
    if (($row['state'] ?? '') !== 'active' || strtotime((string) $row['expires_at'] . ' UTC') < time()) throw new DomainException('Edit session expired.', 409);
    $saveCloseRequest = $status === 6;
    if ($saveCloseRequest && (!is_string($body['userdata'] ?? null)
        || !hash_equals(earistDocumentEditorSaveCloseUserdata($sessionId), $body['userdata']))) {
        throw new DomainException('Invalid save request.', 403);
    }
    $existing = earistDocumentEditorFindSavedVersion($conn, (int) $row['owner_id'], $sessionId);
    if ($existing) {
        if (!$saveCloseRequest) earistDocumentEditorSetState($conn, $sessionId, 'saved', $existing);
        return;
    }
    $sourceFile = earistDocumentManifestFile(earistDocumentHistorySnapshot($row)['file_manifest'], (string) $row['file_selector']);
    if (!hash_equals((string) $row['source_sha256'], (string) $sourceFile['sha256'])) throw new DomainException('The saved source version has changed.', 409);
    $url = $body['url'] ?? '';
    $parts = is_string($url) ? parse_url($url) : false;
    $service = parse_url($config['url']);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== ($service['scheme'] ?? '') || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($service['host'] ?? ''))
        || (int) ($parts['port'] ?? 0) !== (int) ($service['port'] ?? (($service['scheme'] ?? '') === 'https' ? 443 : 80))) throw new DomainException('Editor returned an invalid document URL.', 403);
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_MAXFILESIZE => $config['max_callback_bytes']]);
    $bytes = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_errno($curl); curl_close($curl);
    if ($error || $code !== 200 || !is_string($bytes) || $bytes === '' || strlen($bytes) > $config['max_callback_bytes']) throw new RuntimeException('The edited DOCX could not be retrieved from the editor.');
    $snapshot = earistDocumentHistorySnapshot($row);
    if ((int) $row['owner_id'] !== (int) $row['generated_by'] || $row['document_type'] === 'annex_d') throw new DomainException('Edit session access denied.', 403);
    $manifest = earistDocumentStoreEditedOutput($snapshot['file_manifest'], (string) $row['file_selector'], $bytes);
    earistDocumentPublishEditedVersion($conn, $row, $snapshot, $manifest, $sessionId, !$saveCloseRequest);
}

function earistDocumentEditorSetState(mysqli $conn, string $sessionId, string $state, ?int $savedId): void
{
    $stmt = $conn->prepare('UPDATE earist_document_edit_sessions SET state = ?, saved_generated_document_id = ?, active_lock_generated_document_id = NULL, updated_at = UTC_TIMESTAMP() WHERE session_id = ? AND state = \'active\'');
    $stmt->bind_param('sis', $state, $savedId, $sessionId); $stmt->execute(); $stmt->close();
}

function earistDocumentPublishEditedVersion(mysqli $conn, array $source, array $snapshot, array $manifest, string $sessionId, bool $completeSession = true): void
{
    $primary = earistDocumentManifestFile($manifest, 'primary');
    $newSnapshot = $snapshot; $newSnapshot['file_manifest'] = $manifest;
    $newSnapshot['edited_document'] = ['origin' => 'onlyoffice', 'edited_from_generated_document_id' => (int) $source['generated_document_id'],
        'edited_from_version' => (int) $source['document_version'], 'file_selector' => (string) $source['file_selector']];
    $json = json_encode($newSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestHash = hash('sha256', 'edit:' . $sessionId);
    $conn->begin_transaction();
    try {
        // Use the same stable draft-row mutex as normal generation before assigning
        // a version, so callbacks, edits, and generation serialize consistently.
        $draftLock = $conn->prepare('SELECT draft_id FROM earist_document_drafts WHERE draft_id = ? AND research_record_id = ? AND owner_id = ? AND document_type = ? FOR UPDATE');
        $draftLock->bind_param('iiis', $source['draft_id'], $source['research_record_id'], $source['owner_id'], $source['document_type']);
        $draftLock->execute(); $lockedDraft = $draftLock->get_result()->fetch_assoc() ?: null; $draftLock->close();
        if (!$lockedDraft) throw new RuntimeException('The source draft is unavailable for version allocation.');
        $versionStmt = $conn->prepare('SELECT document_version FROM earist_generated_documents WHERE research_record_id = ? AND document_type = ? ORDER BY document_version DESC LIMIT 1 FOR UPDATE');
        $versionStmt->bind_param('is', $source['research_record_id'], $source['document_type']);
        $versionStmt->execute(); $last = $versionStmt->get_result()->fetch_assoc() ?: null; $versionStmt->close();
        $existing = earistDocumentEditorFindSavedVersion($conn, (int) $source['owner_id'], $sessionId);
        if ($existing) {
            if ($completeSession) {
                $session = $conn->prepare("UPDATE earist_document_edit_sessions SET state = 'saved', saved_generated_document_id = ?, active_lock_generated_document_id = NULL, updated_at = UTC_TIMESTAMP() WHERE session_id = ? AND state = 'active'");
                $session->bind_param('is', $existing, $sessionId); $session->execute(); $session->close();
            }
            $conn->commit();
            return;
        }
        $version = (int) ($last['document_version'] ?? 0) + 1;
        $insert = $conn->prepare("INSERT INTO earist_generated_documents (draft_id, research_record_id, owner_id, document_type, document_version, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, renderer_version, template_sha256, snapshot_json, missing_fields_json, warnings_json, warnings_acknowledged_at, preparation_mode, generated_by, request_token_hash, docx_path, docx_sha256, docx_size, version_origin, edited_from_generated_document_id) SELECT draft_id, research_record_id, owner_id, document_type, ?, draft_revision, source_record_version, source_fingerprint, input_schema_version, mapping_definition_version, binding_version, 'onlyoffice', template_sha256, ?, missing_fields_json, warnings_json, warnings_acknowledged_at, preparation_mode, generated_by, ?, ?, ?, ?, 'edited', generated_document_id FROM earist_generated_documents WHERE generated_document_id = ? AND owner_id = ? AND document_type <> 'annex_d'");
        $insert->bind_param('issssiii', $version, $json, $requestHash, $primary['path'], $primary['sha256'], $primary['size'], $source['generated_document_id'], $source['owner_id']);
        $insert->execute(); $newId = (int) $conn->insert_id; $insert->close();
        if ($newId < 1) throw new RuntimeException('Edited document history could not be saved.');
        if ($completeSession) {
            $session = $conn->prepare("UPDATE earist_document_edit_sessions SET state = 'saved', saved_generated_document_id = ?, active_lock_generated_document_id = NULL, updated_at = UTC_TIMESTAMP() WHERE session_id = ? AND state = 'active'");
            $session->bind_param('is', $newId, $sessionId); $session->execute();
            if ($session->affected_rows !== 1) throw new RuntimeException('Edit session was already completed.');
            $session->close();
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        // ONLYOFFICE may deliver status 2 and status 6 callbacks concurrently.
        // If the idempotency token won that race, treat its existing immutable
        // history row as success rather than leaving Save & Close in a retry loop.
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $duplicateMessage = $exception->getMessage();
            if (str_contains($duplicateMessage, 'uq_earist_generated_request')) {
                $existing = earistDocumentEditorFindSavedVersion($conn, (int) $source['owner_id'], $sessionId);
                if ($existing !== null) {
                    $verify = $conn->prepare("SELECT generated_document_id FROM earist_generated_documents WHERE generated_document_id = ? AND research_record_id = ? AND document_type = ? AND owner_id = ? AND generated_by = ? AND version_origin = 'edited' AND edited_from_generated_document_id = ? AND request_token_hash = ? LIMIT 1");
                    $verify->bind_param('iisiiis', $existing, $source['research_record_id'], $source['document_type'],
                        $source['owner_id'], $source['generated_by'], $source['generated_document_id'], $requestHash);
                    $verify->execute(); $verifiedExisting = $verify->get_result()->fetch_assoc() ?: null; $verify->close();
                    if ($verifiedExisting) {
                        if ($completeSession) earistDocumentEditorSetState($conn, $sessionId, 'saved', $existing);
                        return;
                    }
                }
            }
        }
        throw $exception;
    }
}
