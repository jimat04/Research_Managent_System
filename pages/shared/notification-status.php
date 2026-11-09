<?php
/**
 * Read-only notification status endpoint for the authenticated role shells.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$respond = static function (array $payload, int $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!isLoggedIn()) {
    $respond(['error' => 'Authentication required.'], 401);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    $respond(['error' => 'Authentication required.'], 401);
}

try {
    global $conn;

    $countStmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare notification count query.');
    }
    $countStmt->bind_param('i', $userId);
    if (!$countStmt->execute()) {
        throw new RuntimeException('Unable to load notification count.');
    }
    $unreadCount = (int) ($countStmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
    $countStmt->close();

    $loadRows = static function (string $whereClause) use ($conn, $userId) {
        $stmt = $conn->prepare(
            'SELECT notification_id, title, message, link, is_read, created_at
               FROM notifications
              WHERE user_id = ?' . $whereClause . '
           ORDER BY created_at DESC, notification_id DESC
              LIMIT 5'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare notification list query.');
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to load notifications.');
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return array_map(static function (array $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $createdTimestamp = strtotime($createdAt);

            return [
                'notification_id' => (int) ($row['notification_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'has_link' => trim((string) ($row['link'] ?? '')) !== '',
                'is_read' => (int) ($row['is_read'] ?? 0) === 1,
                'created_at' => $createdAt,
                'time_label' => $createdTimestamp === false ? '' : date('H:i', $createdTimestamp),
            ];
        }, $rows);
    };

    $areas = getUnreadNotificationCountsByArea($userId);

    $respond([
        'unread_count' => $unreadCount,
        'areas' => [
            'my_research' => (int) ($areas['my_research'] ?? 0),
            'research_modules' => (int) ($areas['research_modules'] ?? 0),
        ],
        'notifications' => [
            'all' => $loadRows(''),
            'unread' => $loadRows(' AND is_read = 0'),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('Notification status request failed.');
    $respond(['error' => 'Unable to load notification status.'], 500);
}
