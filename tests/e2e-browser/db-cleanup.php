<?php
declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());
require dirname(__DIR__, 2) . '/includes/config.php';

$marker = (string) ($argv[1] ?? '');
$emails = array_slice($argv, 2);
if ($marker === '') {
    fwrite(STDERR, "A test marker is required.\n");
    exit(2);
}

$filePaths = [];
$projectIds = [];
$projectLike = $marker . '%';
$stmt = $conn->prepare('SELECT project_id FROM research_projects WHERE title LIKE ?');
$stmt->bind_param('s', $projectLike);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) $projectIds[] = (int) $row['project_id'];
$stmt->close();

if ($projectIds) {
    $ids = implode(',', array_map('intval', $projectIds));
    $result = $conn->query("SELECT file_path FROM uploads WHERE project_id IN ($ids) OR research_id IN ($ids)");
    if ($result) while ($row = $result->fetch_assoc()) $filePaths[] = (string) $row['file_path'];
    $result = $conn->query("SELECT file_path FROM publications WHERE research_project_id IN ($ids)");
    if ($result) while ($row = $result->fetch_assoc()) $filePaths[] = (string) $row['file_path'];
    $result = $conn->query("SELECT file_path FROM copyright_applications WHERE research_project_id IN ($ids)");
    if ($result) while ($row = $result->fetch_assoc()) $filePaths[] = (string) $row['file_path'];

    $conn->query("DELETE FROM copyright_applications WHERE research_project_id IN ($ids)");
    $conn->query("DELETE FROM publications WHERE research_project_id IN ($ids)");
    foreach ([
        'project_reviews' => 'project_id',
        'research_reports' => 'project_id',
        'research_documents' => 'project_id',
        'research_author_snapshots' => 'project_id',
        'research_publication_tracking' => 'project_id',
        'defense_schedule' => 'research_id',
        'chapters' => 'project_id',
        'project_members' => 'project_id',
        'project_advisers_history' => 'project_id',
        'project_advisers' => 'project_id',
    ] as $table => $column) {
        $conn->query("DELETE FROM `$table` WHERE `$column` IN ($ids)");
    }
    $conn->query(
        "DELETE vu FROM research_submission_version_uploads vu
          JOIN research_submission_versions v ON v.version_id = vu.version_id
         WHERE v.project_id IN ($ids)"
    );
    $conn->query("DELETE FROM uploads WHERE project_id IN ($ids) OR research_id IN ($ids)");
    $conn->query("DELETE FROM research_projects WHERE project_id IN ($ids)");
}

$activityIds = [];
$stmt = $conn->prepare('SELECT activity_id FROM research_activities WHERE title LIKE ?');
$stmt->bind_param('s', $projectLike);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) $activityIds[] = (int) $row['activity_id'];
$stmt->close();
if ($activityIds) {
    $ids = implode(',', array_map('intval', $activityIds));
    $conn->query("DELETE FROM activity_participants WHERE activity_id IN ($ids)");
    $conn->query("DELETE FROM research_activities WHERE activity_id IN ($ids)");
}

foreach ([
    ['publications', 'publication_id', 'research_title'],
    ['copyright_applications', 'copyright_id', 'output_title'],
] as [$table, $idColumn, $titleColumn]) {
    $ids = [];
    $stmt = $conn->prepare("SELECT `$idColumn`, file_path FROM `$table` WHERE `$titleColumn` LIKE ?");
    $stmt->bind_param('s', $projectLike);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $ids[] = (int) $row[$idColumn];
        $filePaths[] = (string) ($row['file_path'] ?? '');
    }
    $stmt->close();
    if ($ids) $conn->query("DELETE FROM `$table` WHERE `$idColumn` IN (" . implode(',', array_map('intval', $ids)) . ")");
}

$markerLike = '%' . $marker . '%';
$allTestDataLike = '%RMS E2E %';
$stmt = $conn->prepare('DELETE FROM notifications WHERE (title LIKE ? OR message LIKE ?) OR (title LIKE ? OR message LIKE ?)');
$stmt->bind_param('ssss', $markerLike, $markerLike, $allTestDataLike, $allTestDataLike);
$stmt->execute();
$stmt->close();
$stmt = $conn->prepare('DELETE FROM activity_log WHERE action LIKE ? OR action LIKE ?');
$stmt->bind_param('ss', $markerLike, $allTestDataLike);
$stmt->execute();
$stmt->close();

$userIds = [];
$stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
foreach ($emails as $email) {
    if ($email === '') continue;
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $userIds[] = (int) $row['user_id'];
}
$stmt->close();
if ($userIds) {
    $ids = implode(',', array_map('intval', $userIds));
    $conn->query("DELETE FROM staff_permissions WHERE user_id IN ($ids)");
    $conn->query("DELETE FROM notifications WHERE user_id IN ($ids)");
    $conn->query("DELETE FROM activity_log WHERE user_id IN ($ids)");
    $conn->query("DELETE FROM users WHERE user_id IN ($ids) AND role <> 'admin'");
}

$root = dirname(__DIR__, 2);
$uploadsRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads');
foreach (array_unique($filePaths) as $relativePath) {
    if (!$relativePath) continue;
    $resolved = realpath($root . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));
    if ($uploadsRoot && $resolved && str_starts_with($resolved, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) && is_file($resolved)) @unlink($resolved);
}

echo json_encode([
    'projects' => count($projectIds),
    'activities' => count($activityIds),
    'users' => count($userIds),
]);
