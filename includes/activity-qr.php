<?php
/**
 * Small dependency-free QR renderer for short RMS activity check-in links.
 * Encodes QR version 6 at error-correction level L.
 */

function rmsActivityQrGfMultiply(int $left, int $right): int
{
    $result = 0;
    while ($right > 0) {
        if (($right & 1) === 1) {
            $result ^= $left;
        }
        $left <<= 1;
        if (($left & 0x100) !== 0) {
            $left ^= 0x11D;
        }
        $right >>= 1;
    }
    return $result;
}

function rmsActivityQrErrorCorrection(array $data, int $degree): array
{
    $generator = [1];
    $root = 1;
    for ($index = 0; $index < $degree; $index++) {
        $next = array_fill(0, count($generator) + 1, 0);
        foreach ($generator as $position => $coefficient) {
            $next[$position] ^= $coefficient;
            $next[$position + 1] ^= rmsActivityQrGfMultiply($coefficient, $root);
        }
        $generator = $next;
        $root = rmsActivityQrGfMultiply($root, 2);
    }

    $message = array_merge($data, array_fill(0, $degree, 0));
    $dataLength = count($data);
    for ($position = 0; $position < $dataLength; $position++) {
        $factor = $message[$position];
        if ($factor === 0) {
            continue;
        }
        foreach ($generator as $offset => $coefficient) {
            $message[$position + $offset] ^= rmsActivityQrGfMultiply($coefficient, $factor);
        }
    }
    return array_slice($message, $dataLength);
}

function rmsActivityCheckinBaseUrl(): string
{
    $baseUrl = trim(ACTIVITY_CHECKIN_BASE_URL) !== '' ? trim(ACTIVITY_CHECKIN_BASE_URL) : trim(SITE_URL);
    $parts = parse_url($baseUrl);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $httpsRequired = APP_ENV === 'production';
    if (!is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array($scheme, ['http', 'https'], true)
        || ($httpsRequired && $scheme !== 'https')
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException($httpsRequired
            ? 'Configure an HTTPS check-in base URL for production.'
            : 'Configure a valid HTTP or HTTPS check-in base URL.');
    }
    return rtrim($baseUrl, '/') . '/';
}

function rmsActivityQrEncryptionKey(): string
{
    if (preg_match('/^[a-f0-9]{64}$/i', ACTIVITY_CHECKIN_HASH_KEY) !== 1) {
        throw new RuntimeException('The activity check-in encryption key is unavailable.');
    }

    $hashKey = hex2bin(ACTIVITY_CHECKIN_HASH_KEY);
    if ($hashKey === false) {
        throw new RuntimeException('The activity check-in encryption key is invalid.');
    }

    return hash_hkdf('sha256', $hashKey, 32, 'rms-activity-checkin-token-encryption');
}

function rmsActivityQrEncryptToken(string $token, int $scheduleId): string
{
    if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || $scheduleId <= 0) {
        throw new RuntimeException('The activity check-in token is invalid.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $token,
        'aes-256-gcm',
        rmsActivityQrEncryptionKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'rms-activity-checkin:' . $scheduleId,
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('The activity check-in token could not be encrypted.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function rmsActivityQrDecryptToken(string $encryptedToken, int $scheduleId): ?string
{
    if ($scheduleId <= 0 || $encryptedToken === '') {
        return null;
    }

    $payload = base64_decode($encryptedToken, true);
    if ($payload === false || strlen($payload) !== 60) {
        return null;
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $token = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        rmsActivityQrEncryptionKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'rms-activity-checkin:' . $scheduleId
    );

    return is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token) === 1 ? $token : null;
}

function rmsActivityQrStartActivity(
    mysqli $conn,
    int $activityId,
    bool $scheduleDatesAvailable,
    bool $tokenStorageAvailable
): bool {
    if ($activityId <= 0 || !$scheduleDatesAvailable) {
        return false;
    }

    $conn->begin_transaction();
    try {
        $startStmt = $conn->prepare(
            "UPDATE research_activities
                SET status = 'ongoing'
              WHERE activity_id = ?
                AND status IN ('upcoming', 'scheduled')
                AND EXISTS (
                    SELECT 1 FROM activity_schedule_dates d
                     WHERE d.activity_id = ?
                       AND d.status = 'scheduled'
                       AND d.schedule_date = CURRENT_DATE()
                       AND d.start_time IS NOT NULL
                       AND d.start_time <= CURRENT_TIME()
                       AND (d.end_time IS NULL OR d.end_time >= CURRENT_TIME())
                )"
        );
        if (!$startStmt) {
            throw new RuntimeException('Unable to prepare the activity start update.');
        }
        $startStmt->bind_param('ii', $activityId, $activityId);
        if (!$startStmt->execute()) {
            $startStmt->close();
            throw new RuntimeException('Unable to start the activity.');
        }
        $started = $startStmt->affected_rows === 1;
        $startStmt->close();
        if (!$started) {
            $conn->rollback();
            return false;
        }

        if ($tokenStorageAvailable && preg_match('/^[a-f0-9]{64}$/i', ACTIVITY_CHECKIN_HASH_KEY) === 1) {
            $scheduleStmt = $conn->prepare(
                "SELECT schedule_id, checkin_token_hash, checkin_token_encrypted
                   FROM activity_schedule_dates
                  WHERE activity_id = ? AND status = 'scheduled'
                  ORDER BY schedule_date, schedule_id"
            );
            if (!$scheduleStmt) {
                throw new RuntimeException('Unable to prepare activity check-in token generation.');
            }
            $scheduleStmt->bind_param('i', $activityId);
            if (!$scheduleStmt->execute()) {
                $scheduleStmt->close();
                throw new RuntimeException('Unable to load scheduled dates for check-in token generation.');
            }
            $schedules = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $scheduleStmt->close();

            $updateStmt = $conn->prepare(
                'UPDATE activity_schedule_dates
                    SET checkin_token_hash = ?, checkin_token_encrypted = ?
                  WHERE schedule_id = ? AND activity_id = ? AND status = \'scheduled\''
            );
            if (!$updateStmt) {
                throw new RuntimeException('Unable to prepare scheduled check-in token storage.');
            }
            foreach ($schedules as $schedule) {
                $scheduleId = (int) $schedule['schedule_id'];
                $storedHash = (string) ($schedule['checkin_token_hash'] ?? '');
                $storedEncryptedToken = (string) ($schedule['checkin_token_encrypted'] ?? '');
                $currentToken = $storedEncryptedToken !== ''
                    ? rmsActivityQrDecryptToken($storedEncryptedToken, $scheduleId)
                    : null;
                if ($currentToken !== null && $storedHash !== ''
                    && hash_equals($storedHash, hash('sha256', $currentToken))) {
                    continue;
                }

                $token = bin2hex(random_bytes(16));
                $tokenHash = hash('sha256', $token);
                $encryptedToken = rmsActivityQrEncryptToken($token, $scheduleId);
                $updateStmt->bind_param('ssii', $tokenHash, $encryptedToken, $scheduleId, $activityId);
                if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                    $updateStmt->close();
                    throw new RuntimeException('Unable to save a scheduled activity check-in token.');
                }
            }
            $updateStmt->close();
        }

        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function rmsActivityQrSvg(string $text): string
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    if ($bytes === [] || count($bytes) > 134) {
        throw new RuntimeException('The QR link is too long to encode.');
    }

    $bits = [];
    $appendBits = static function (int $value, int $length) use (&$bits): void {
        for ($shift = $length - 1; $shift >= 0; $shift--) {
            $bits[] = ($value >> $shift) & 1;
        }
    };
    $appendBits(0b0100, 4);
    $appendBits(count($bytes), 8);
    foreach ($bytes as $byte) {
        $appendBits($byte, 8);
    }
    $capacityBits = 136 * 8;
    $appendBits(0, min(4, $capacityBits - count($bits)));
    while ((count($bits) % 8) !== 0) {
        $bits[] = 0;
    }

    $dataCodewords = [];
    for ($offset = 0; $offset < count($bits); $offset += 8) {
        $value = 0;
        for ($bitOffset = 0; $bitOffset < 8; $bitOffset++) {
            $value = ($value << 1) | $bits[$offset + $bitOffset];
        }
        $dataCodewords[] = $value;
    }
    $padCodeword = 0xEC;
    while (count($dataCodewords) < 136) {
        $dataCodewords[] = $padCodeword;
        $padCodeword = $padCodeword === 0xEC ? 0x11 : 0xEC;
    }

    $blocks = [array_slice($dataCodewords, 0, 68), array_slice($dataCodewords, 68, 68)];
    $eccBlocks = [
        rmsActivityQrErrorCorrection($blocks[0], 18),
        rmsActivityQrErrorCorrection($blocks[1], 18),
    ];
    $codewords = [];
    for ($index = 0; $index < 68; $index++) {
        $codewords[] = $blocks[0][$index];
        $codewords[] = $blocks[1][$index];
    }
    for ($index = 0; $index < 18; $index++) {
        $codewords[] = $eccBlocks[0][$index];
        $codewords[] = $eccBlocks[1][$index];
    }

    $size = 41;
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    $setFunction = static function (int $x, int $y, bool $dark) use (&$matrix, &$reserved, $size): void {
        if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
            $matrix[$y][$x] = $dark;
            $reserved[$y][$x] = true;
        }
    };

    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$centerX, $centerY]) {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $distance = max(abs($dx - 3), abs($dy - 3));
                $setFunction($centerX + $dx - 3, $centerY + $dy - 3, $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && $distance !== 2);
            }
        }
    }

    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $setFunction(34 + $dx, 34 + $dy, max(abs($dx), abs($dy)) !== 1);
        }
    }
    for ($index = 8; $index < $size - 8; $index++) {
        $setFunction(6, $index, ($index % 2) === 0);
        $setFunction($index, 6, ($index % 2) === 0);
    }

    $formatData = 0b01000;
    $formatRemainder = $formatData << 10;
    for ($bit = 14; $bit >= 10; $bit--) {
        if ((($formatRemainder >> $bit) & 1) !== 0) {
            $formatRemainder ^= 0x537 << ($bit - 10);
        }
    }
    $formatBits = (($formatData << 10) | $formatRemainder) ^ 0x5412;
    for ($index = 0; $index <= 5; $index++) {
        $setFunction(8, $index, (($formatBits >> $index) & 1) !== 0);
    }
    $setFunction(8, 7, (($formatBits >> 6) & 1) !== 0);
    $setFunction(8, 8, (($formatBits >> 7) & 1) !== 0);
    $setFunction(7, 8, (($formatBits >> 8) & 1) !== 0);
    for ($index = 9; $index < 15; $index++) {
        $setFunction(14 - $index, 8, (($formatBits >> $index) & 1) !== 0);
    }
    for ($index = 0; $index < 8; $index++) {
        $setFunction($size - 1 - $index, 8, (($formatBits >> $index) & 1) !== 0);
    }
    for ($index = 8; $index < 15; $index++) {
        $setFunction(8, $size - 15 + $index, (($formatBits >> $index) & 1) !== 0);
    }
    $setFunction(8, $size - 8, true);

    $dataBits = [];
    foreach ($codewords as $codeword) {
        for ($shift = 7; $shift >= 0; $shift--) {
            $dataBits[] = ($codeword >> $shift) & 1;
        }
    }
    $bitIndex = 0;
    $upward = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vertical = 0; $vertical < $size; $vertical++) {
            $y = $upward ? $size - 1 - $vertical : $vertical;
            for ($side = 0; $side < 2; $side++) {
                $x = $right - $side;
                if ($reserved[$y][$x]) {
                    continue;
                }
                $dark = $bitIndex < count($dataBits) && $dataBits[$bitIndex] === 1;
                $bitIndex++;
                if ((($x + $y) % 2) === 0) {
                    $dark = !$dark;
                }
                $matrix[$y][$x] = $dark;
            }
        }
        $upward = !$upward;
    }

    $quietZone = 4;
    $viewSize = $size + ($quietZone * 2);
    $path = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($matrix[$y][$x]) {
                $path .= 'M' . ($x + $quietZone) . ' ' . ($y + $quietZone) . 'h1v1h-1z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewSize . ' ' . $viewSize . '" role="img" aria-label="Activity check-in QR code" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#111827"/></svg>';
}
