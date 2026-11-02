<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');
$user = getCurrentUser();
$userId = (int) ($user['user_id'] ?? 0);
$publications = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'publications'");
if ($tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0) {
    $contributorTableCheck = $conn->query("SHOW TABLES LIKE 'publication_contributors'");
    $contributorTableAvailable = $contributorTableCheck instanceof mysqli_result && $contributorTableCheck->num_rows > 0;
    if ($contributorTableCheck instanceof mysqli_result) $contributorTableCheck->free();
    $sql = $contributorTableAvailable
        ? "SELECT p.publication_id, p.research_title, p.publication_type, p.publication_date, p.archival_status AS status FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?) ORDER BY p.created_at DESC, p.publication_id DESC"
        : "SELECT publication_id, research_title, publication_type, publication_date, archival_status AS status FROM publications WHERE 1 = 0";
    $stmt = $conn->prepare($sql);
    if ($contributorTableAvailable) $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $publications[] = $row;
    $stmt->close();
}
function studentPublicationEscape(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
renderStudentShell($user, 'my-publications', 'My Publications', 'Publications that list your RMS account as a contributor.');
?>
<main class="student-output-summary"><section class="student-output-card"><h1>My Publications</h1><p>Read-only records that credit your RMS account.</p>
<?php if (!$publications): ?><p>No credited publications are linked to your account yet.</p><?php else: ?>
<div class="student-output-table-wrap"><table class="student-output-table"><thead><tr><th>Title</th><th>Type</th><th>Publication date</th><th>Status</th></tr></thead><tbody>
<?php foreach ($publications as $publication): ?><tr><td><?= studentPublicationEscape($publication['research_title']) ?></td><td><?= studentPublicationEscape(ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?></td><td><?= studentPublicationEscape($publication['publication_date'] ?: '—') ?></td><td><?= studentPublicationEscape(ucwords(str_replace('_', ' ', (string) $publication['status']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section></main>
<?php renderStudentShellClose(); ?>
