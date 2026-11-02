<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');
$user = getCurrentUser();
$userId = (int) ($user['user_id'] ?? 0);
$contributions = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'copyright_applications'");
if ($tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0) {
    $contributorTableCheck = $conn->query("SHOW TABLES LIKE 'copyright_contributors'");
    $contributorTableAvailable = $contributorTableCheck instanceof mysqli_result && $contributorTableCheck->num_rows > 0;
    if ($contributorTableCheck instanceof mysqli_result) $contributorTableCheck->free();
    $legacyOwnerOutputs = "c.output_type NOT IN ('software','research','instructional_material','module','other')";
    $sql = $contributorTableAvailable
        ? "SELECT c.copyright_id, c.output_title, c.output_type, CASE WHEN c.output_type IN ('software','research','instructional_material','module','other') THEN c.archival_status ELSE c.status END AS status, CASE WHEN cc.user_id IS NOT NULL THEN COALESCE(cc.contribution, 'Contributor') ELSE 'Primary applicant' END AS contribution FROM copyright_applications c LEFT JOIN copyright_contributors cc ON cc.copyright_id = c.copyright_id AND cc.user_id = ? WHERE c.deactivated_at IS NULL AND (cc.user_id IS NOT NULL OR ($legacyOwnerOutputs AND c.applicant_id = ?)) ORDER BY c.created_at DESC, c.copyright_id DESC"
        : "SELECT c.copyright_id, c.output_title, c.output_type, c.status, 'Primary applicant' AS contribution FROM copyright_applications c WHERE c.deactivated_at IS NULL AND $legacyOwnerOutputs AND c.applicant_id = ? ORDER BY c.created_at DESC, c.copyright_id DESC";
    $stmt = $conn->prepare($sql);
    if ($contributorTableAvailable) $stmt->bind_param('ii', $userId, $userId);
    else $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $contributions[] = $row;
    $stmt->close();
}
function studentIpEscape(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function studentIpType(string $type): string { return match ($type) { 'patent' => 'Patent', 'utility_model' => 'Utility model', 'innovation' => 'Innovation', 'technology_transfer' => 'Technology transfer', default => 'Copyright' }; }
function studentIpStatus(string $status): string { return match ($status) { 'archived' => 'Archived', 'legacy' => 'Legacy record', 'pending' => 'Pending', 'under_review' => 'Under review', 'registered' => 'Registered', 'rejected' => 'Rejected', default => 'Not recorded' }; }
renderStudentShell($user, 'my-ip-contributions', 'My IP Contributions', 'A limited summary of intellectual property records that credit you.');
?>
<main class="student-output-summary"><section class="student-output-card"><h1>My IP Contributions</h1><p>This view shows only your credited records and contribution summary.</p>
<?php if (!$contributions): ?><p>No intellectual property contributions are linked to your account yet.</p><?php else: ?>
<div class="student-output-table-wrap"><table class="student-output-table"><thead><tr><th>Title</th><th>Type</th><th>Your contribution</th><th>Status</th></tr></thead><tbody>
<?php foreach ($contributions as $item): ?><tr><td><?= studentIpEscape($item['output_title']) ?></td><td><?= studentIpEscape(studentIpType((string) $item['output_type'])) ?></td><td><?= studentIpEscape($item['contribution'] ?: 'Contributor') ?></td><td><?= studentIpEscape(studentIpStatus((string) $item['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section></main>
<?php renderStudentShellClose(); ?>
