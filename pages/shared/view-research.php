<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function archiveDetailColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }
    $stmt->close();
    return $columns;
}

function archiveDetailEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function archiveDetailLabel(?string $value): string
{
    return ucwords(str_replace('_', ' ', (string) $value));
}

$schemaTables = [
    'research_projects', 'project_members', 'project_advisers',
    'project_advisers_history', 'defense_schedule', 'research_publication_tracking',
];
$columns = [];
foreach ($schemaTables as $table) {
    $columns[$table] = archiveDetailColumns($conn, $table);
}

$projectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$project = null;
$proponents = [];
$currentAdvisers = [];
$formerAdvisers = [];
$finalDefense = null;
$publication = null;

if ($projectId !== false && $projectId !== null && !empty($columns['research_projects'])) {
    $abstractSelect = isset($columns['research_projects']['abstract']) ? 'rp.abstract' : 'NULL AS abstract';
    $deletedGuard = isset($columns['research_projects']['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';
    $sql = "SELECT rp.project_id, rp.title, rp.status, rp.created_by, {$abstractSelect},
                   CONCAT_WS(' ', owner.first_name, owner.last_name) AS owner_name
            FROM research_projects rp
            JOIN users owner ON owner.user_id = rp.created_by
            WHERE rp.project_id = ?
              AND rp.status IN ('completed', 'archived'){$deletedGuard}
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($project) {
    $seenProponents = [];
    $ownerId = (int) $project['created_by'];
    $proponents[] = ['name' => (string) $project['owner_name'], 'role' => 'Lead proponent'];
    $seenProponents[$ownerId] = true;

    if (!empty($columns['project_members'])
        && isset($columns['project_members']['project_id'], $columns['project_members']['user_id'])) {
        $memberRole = isset($columns['project_members']['role']) ? 'pm.role' : "'member'";
        $stmt = $conn->prepare(
            "SELECT pm.user_id, {$memberRole} AS member_role,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS member_name
             FROM project_members pm
             JOIN users u ON u.user_id = pm.user_id
             WHERE pm.project_id = ?
             ORDER BY CASE WHEN {$memberRole} = 'lead' THEN 0 ELSE 1 END, u.last_name, u.first_name"
        );
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $memberId = (int) $row['user_id'];
            if (isset($seenProponents[$memberId])) {
                continue;
            }
            $seenProponents[$memberId] = true;
            $proponents[] = [
                'name' => (string) $row['member_name'],
                'role' => $row['member_role'] === 'lead' ? 'Lead proponent' : 'Member',
            ];
        }
        $stmt->close();
    }

    if (!empty($columns['project_advisers'])
        && isset($columns['project_advisers']['project_id'], $columns['project_advisers']['adviser_id'])) {
        $stmt = $conn->prepare(
            "SELECT pa.adviser_id, CONCAT_WS(' ', u.first_name, u.last_name) AS adviser_name
             FROM project_advisers pa
             JOIN users u ON u.user_id = pa.adviser_id
             WHERE pa.project_id = ? AND pa.adviser_id IS NOT NULL
             ORDER BY u.last_name, u.first_name"
        );
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $currentAdvisers[] = $row;
        }
        $stmt->close();
    }

    $historyColumns = $columns['project_advisers_history'];
    if (isset($historyColumns['project_id'], $historyColumns['adviser_id'])) {
        $assignedSelect = isset($historyColumns['assigned_at']) ? 'pah.assigned_at' : 'NULL AS assigned_at';
        $removedSelect = isset($historyColumns['removed_at']) ? 'pah.removed_at' : 'NULL AS removed_at';
        $orderBy = isset($historyColumns['removed_at']) ? 'pah.removed_at DESC' : 'u.last_name, u.first_name';
        $stmt = $conn->prepare(
            "SELECT pah.adviser_id, {$assignedSelect}, {$removedSelect},
                    CONCAT_WS(' ', u.first_name, u.last_name) AS adviser_name
             FROM project_advisers_history pah
             JOIN users u ON u.user_id = pah.adviser_id
             WHERE pah.project_id = ?
             ORDER BY {$orderBy}"
        );
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $formerAdvisers[] = $row;
        }
        $stmt->close();
    }

    $defenseColumns = $columns['defense_schedule'];
    if (isset($defenseColumns['project_id'], $defenseColumns['schedule_date'], $defenseColumns['type'], $defenseColumns['status'])) {
        $venueSelect = isset($defenseColumns['venue']) ? 'venue' : 'NULL AS venue';
        $stmt = $conn->prepare(
            "SELECT schedule_date, {$venueSelect}
             FROM defense_schedule
             WHERE project_id = ? AND type = 'final' AND status = 'done'
             ORDER BY schedule_date DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $finalDefense = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $publicationColumns = $columns['research_publication_tracking'];
    if (isset($publicationColumns['project_id'])) {
        $publicationSelect = [];
        foreach (['journal_status', 'journal_reference', 'colloquium_status', 'colloquium_date', 'archive_status'] as $column) {
            $publicationSelect[] = isset($publicationColumns[$column]) ? $column : "NULL AS {$column}";
        }
        $stmt = $conn->prepare(
            'SELECT ' . implode(', ', $publicationSelect) . '
             FROM research_publication_tracking
             WHERE project_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $publication = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$role = (string) ($user['role'] ?? 'student');
$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};
if ($shell === 'admin') {
    renderAdminShell($user, 'research-archive.php', 'Research Detail (Archive)', 'Public research metadata');
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'research-archive.php', 'Research Detail (Archive)', 'Public research metadata');
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'research-archive.php', 'Research Detail (Archive)', 'Public research metadata');
} else {
    renderStudentShell($user, 'research-archive.php', 'Research Detail (Archive)', 'Public research metadata');
}
?>

<style>
    .detail-card { background:#fff; border:1px solid #e3e7ee; border-radius:12px; padding:1.2rem; margin-bottom:1rem; }
    .detail-card h2 { margin:0 0 1rem; color:#101828; font-size:1.08rem; }
    .detail-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
    .detail-heading h1 { margin:0; color:#101828; font-size:1.4rem; }
    .detail-badge, .person-tag { display:inline-flex; border-radius:999px; padding:.3rem .65rem; font-size:.75rem; font-weight:700; white-space:nowrap; }
    .status-completed { background:#dcfce7; color:#166534; }
    .status-archived { background:#e0e7ff; color:#3730a3; }
    .person-tag { background:#f2f4f7; color:#475467; }
    .person-tag.former { background:#fef3c7; color:#92400e; }
    .detail-copy { color:#475467; line-height:1.65; white-space:pre-wrap; }
    .people-list { display:grid; gap:.65rem; }
    .person-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding-bottom:.65rem; border-bottom:1px solid #eef1f5; }
    .person-row:last-child { border-bottom:0; padding-bottom:0; }
    .person-name { color:#101828; font-weight:700; }
    .person-meta { color:#667085; font-size:.83rem; margin-top:.15rem; }
    .detail-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
    .metadata-list { display:grid; gap:.7rem; margin:0; }
    .metadata-row { display:grid; grid-template-columns:145px minmax(0, 1fr); gap:.75rem; }
    .metadata-row dt { color:#667085; font-weight:600; }
    .metadata-row dd { margin:0; color:#101828; overflow-wrap:anywhere; }
    .detail-empty { text-align:center; padding:2rem 1rem; color:#667085; }
    .detail-back { display:inline-flex; margin-top:1rem; color:#6d28d9; font-weight:700; text-decoration:none; }
    @media (max-width:760px) { .detail-grid { grid-template-columns:1fr; } .detail-heading, .person-row { align-items:flex-start; flex-direction:column; } }
</style>

<?php if (!$project): ?>
    <section class="detail-card detail-empty">
        <h2>Research record unavailable</h2>
        <p>This archive record is unavailable or you do not have access to view it.</p>
        <a class="detail-back" href="<?= archiveDetailEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">&larr; Return to Research Archive</a>
    </section>
<?php else: ?>
    <section class="detail-card">
        <div class="detail-heading">
            <h1><?= archiveDetailEscape($project['title']) ?></h1>
            <span class="detail-badge <?= $project['status'] === 'archived' ? 'status-archived' : 'status-completed' ?>">
                <?= archiveDetailEscape(ucfirst($project['status'])) ?>
            </span>
        </div>
        <?php if (trim((string) ($project['abstract'] ?? '')) !== ''): ?>
            <h2 style="margin-top:1.25rem;">Abstract</h2>
            <div class="detail-copy"><?= archiveDetailEscape($project['abstract']) ?></div>
        <?php endif; ?>
    </section>

    <div class="detail-grid">
        <section class="detail-card">
            <h2>Proponents</h2>
            <div class="people-list">
                <?php foreach ($proponents as $proponent): ?>
                    <div class="person-row">
                        <span class="person-name"><?= archiveDetailEscape($proponent['name']) ?></span>
                        <span class="person-tag"><?= archiveDetailEscape($proponent['role']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="detail-card">
            <h2>Advisers</h2>
            <?php if (!$currentAdvisers && !$formerAdvisers): ?>
                <p class="detail-copy">No adviser information is recorded.</p>
            <?php else: ?>
                <div class="people-list">
                    <?php foreach ($currentAdvisers as $adviser): ?>
                        <div class="person-row">
                            <span class="person-name"><?= archiveDetailEscape($adviser['adviser_name']) ?></span>
                            <span class="person-tag">Current</span>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($formerAdvisers as $adviser): ?>
                        <div class="person-row">
                            <div>
                                <div class="person-name"><?= archiveDetailEscape($adviser['adviser_name']) ?></div>
                                <?php if (!empty($adviser['removed_at'])): ?>
                                    <div class="person-meta">Until <?= archiveDetailEscape(date('M j, Y', strtotime($adviser['removed_at']))) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="person-tag former">Former</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($finalDefense || $publication): ?>
        <div class="detail-grid">
            <?php if ($finalDefense): ?>
                <section class="detail-card">
                    <h2>Final Defense</h2>
                    <dl class="metadata-list">
                        <div class="metadata-row"><dt>Date</dt><dd><?= archiveDetailEscape(date('F j, Y, g:i A', strtotime($finalDefense['schedule_date']))) ?></dd></div>
                        <?php if (trim((string) ($finalDefense['venue'] ?? '')) !== ''): ?>
                            <div class="metadata-row"><dt>Venue</dt><dd><?= archiveDetailEscape($finalDefense['venue']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>
            <?php endif; ?>

            <?php if ($publication): ?>
                <section class="detail-card">
                    <h2>Publication &amp; Colloquium</h2>
                    <dl class="metadata-list">
                        <?php if (!empty($publication['journal_status'])): ?>
                            <div class="metadata-row"><dt>Journal status</dt><dd><?= archiveDetailEscape(archiveDetailLabel($publication['journal_status'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if (trim((string) ($publication['journal_reference'] ?? '')) !== ''): ?>
                            <div class="metadata-row"><dt>Journal reference</dt><dd><?= archiveDetailEscape($publication['journal_reference']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($publication['colloquium_status'])): ?>
                            <div class="metadata-row"><dt>Colloquium</dt><dd><?= archiveDetailEscape(archiveDetailLabel($publication['colloquium_status'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($publication['colloquium_date'])): ?>
                            <div class="metadata-row"><dt>Colloquium date</dt><dd><?= archiveDetailEscape(date('F j, Y, g:i A', strtotime($publication['colloquium_date']))) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($publication['archive_status'])): ?>
                            <div class="metadata-row"><dt>Archive tracking</dt><dd><?= archiveDetailEscape(archiveDetailLabel($publication['archive_status'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a class="detail-back" href="<?= archiveDetailEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">&larr; Return to Research Archive</a>
<?php endif; ?>

<?php
if ($shell === 'admin') {
    renderAdminShellClose();
} elseif ($shell === 'staff') {
    renderStaffShellClose();
} elseif ($shell === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
