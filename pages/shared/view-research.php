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
    $sql = "SELECT rp.project_id, rp.title, rp.status, rp.created_by, rp.updated_at, {$abstractSelect},
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
$detailTheme = match ($shell) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'rgb' => '245, 124, 0'],
    'staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'rgb' => '13, 148, 136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'rgb' => '29, 78, 216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'rgb' => '91, 30, 188'],
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
    .research-detail {
        --detail-accent: <?= archiveDetailEscape($detailTheme['accent']) ?>;
        --detail-deep: <?= archiveDetailEscape($detailTheme['deep']) ?>;
        --detail-tint: <?= archiveDetailEscape($detailTheme['tint']) ?>;
        --detail-rgb: <?= archiveDetailEscape($detailTheme['rgb']) ?>;
        max-width: 1320px;
        margin: 0 auto;
        color: #0f172a;
    }
    .detail-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        color: #64748b;
        font-size: 12px;
        font-weight: 750;
        text-decoration: none;
        transition: color .18s ease, transform .18s ease;
    }
    .detail-back:hover { color: var(--detail-accent); transform: translateX(-2px); }
    .detail-hero {
        position: relative;
        overflow: hidden;
        margin-bottom: 22px;
        padding: 36px 40px;
        border: 1px solid rgba(var(--detail-rgb), .35);
        border-radius: 20px;
        background:
            radial-gradient(circle at 88% 8%, rgba(255, 255, 255, .2), transparent 31%),
            linear-gradient(135deg, var(--detail-deep), var(--detail-accent));
        box-shadow: 0 20px 45px rgba(var(--detail-rgb), .16);
    }
    .detail-hero::after {
        content: "";
        position: absolute;
        right: -65px;
        bottom: -125px;
        width: 310px;
        height: 310px;
        border: 1px solid rgba(255, 255, 255, .14);
        border-radius: 50%;
    }
    .detail-hero-inner { position: relative; z-index: 1; max-width: 920px; }
    .detail-eyebrow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 15px; }
    .detail-kicker { color: rgba(255, 255, 255, .72); font-size: 10px; font-weight: 850; letter-spacing: .13em; text-transform: uppercase; }
    .detail-id { padding-left: 9px; border-left: 1px solid rgba(255, 255, 255, .25); color: rgba(255, 255, 255, .72); font-size: 10px; font-weight: 750; letter-spacing: .07em; }
    .detail-hero h1 { max-width: 850px; margin: 0; color: #fff; font-size: clamp(27px, 3vw, 42px); line-height: 1.12; letter-spacing: -.035em; }
    .detail-hero-meta { display: flex; flex-wrap: wrap; gap: 10px 24px; margin-top: 22px; }
    .detail-hero-meta span { color: rgba(255, 255, 255, .76); font-size: 12px; }
    .detail-hero-meta strong { color: #fff; font-weight: 750; }
    .detail-badge, .person-tag {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 10px;
        font-weight: 850;
        letter-spacing: .025em;
        white-space: nowrap;
    }
    .detail-hero .detail-badge { margin-top: 20px; border: 1px solid rgba(255, 255, 255, .22); background: rgba(255, 255, 255, .14); color: #fff; }
    .detail-overview { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(270px, .65fr); gap: 20px; margin-bottom: 20px; }
    .detail-panel { border: 1px solid #e2e8f0; border-radius: 17px; background: #fff; box-shadow: 0 12px 32px rgba(15, 23, 42, .05); }
    .detail-section { padding: 26px 28px; }
    .detail-section-label { display: block; margin-bottom: 9px; color: var(--detail-accent); font-size: 10px; font-weight: 850; letter-spacing: .12em; text-transform: uppercase; }
    .detail-section h2 { margin: 0; color: #0f172a; font-size: 19px; letter-spacing: -.018em; }
    .detail-copy { margin: 17px 0 0; color: #475569; font-size: 14px; line-height: 1.78; white-space: pre-wrap; }
    .detail-copy.muted { color: #64748b; }
    .detail-facts { display: grid; }
    .detail-fact { padding: 19px 22px; }
    .detail-fact + .detail-fact { border-top: 1px solid #e2e8f0; }
    .detail-fact dt { margin: 0 0 6px; color: #94a3b8; font-size: 10px; font-weight: 850; letter-spacing: .07em; text-transform: uppercase; }
    .detail-fact dd { margin: 0; color: #0f172a; font-size: 13px; font-weight: 720; line-height: 1.5; overflow-wrap: anywhere; }
    .detail-section-head { display: flex; justify-content: space-between; gap: 20px; align-items: end; padding: 0 2px; margin: 28px 0 12px; }
    .detail-section-head h2 { margin: 0; color: #0f172a; font-size: 18px; letter-spacing: -.015em; }
    .detail-section-head p { margin: 5px 0 0; color: #64748b; font-size: 12px; }
    .detail-section-count { color: var(--detail-accent); font-size: 11px; font-weight: 850; }
    .detail-people { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: hidden; }
    .detail-people-group { padding: 24px 26px; }
    .detail-people-group + .detail-people-group { border-left: 1px solid #e2e8f0; }
    .detail-group-title { display: flex; justify-content: space-between; gap: 14px; align-items: center; margin-bottom: 15px; }
    .detail-group-title h3 { margin: 0; color: #0f172a; font-size: 14px; }
    .detail-group-title span { color: #94a3b8; font-size: 10px; font-weight: 800; }
    .people-list { display: grid; }
    .person-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; border-top: 1px solid #edf1f5; }
    .person-initials { display: grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; border-radius: 10px; background: var(--detail-tint); color: var(--detail-accent); font-size: 10px; font-weight: 850; }
    .person-identity { display: flex; align-items: center; min-width: 0; gap: 10px; }
    .person-name { color: #0f172a; font-size: 12px; font-weight: 750; overflow-wrap: anywhere; }
    .person-meta { margin-top: 3px; color: #64748b; font-size: 10px; }
    .person-tag { background: #f1f5f9; color: #475569; }
    .person-tag.current { background: #dcfce7; color: #166534; }
    .person-tag.former { background: #fef3c7; color: #92400e; }
    .detail-empty-copy { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
    .detail-milestones { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: hidden; }
    .detail-milestone { padding: 25px 27px; }
    .detail-milestone + .detail-milestone { border-left: 1px solid #e2e8f0; }
    .detail-milestone h3 { margin: 0 0 18px; color: #0f172a; font-size: 14px; }
    .metadata-list { display: grid; gap: 0; margin: 0; }
    .metadata-row { display: grid; grid-template-columns: 145px minmax(0, 1fr); gap: 16px; padding: 11px 0; border-top: 1px solid #edf1f5; }
    .metadata-row dt { color: #64748b; font-size: 11px; font-weight: 650; }
    .metadata-row dd { margin: 0; color: #0f172a; font-size: 11px; font-weight: 720; line-height: 1.5; overflow-wrap: anywhere; }
    .detail-footer { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-top: 22px; padding: 18px 2px 4px; border-top: 1px solid #e2e8f0; }
    .detail-footer p { margin: 0; color: #94a3b8; font-size: 11px; }
    .detail-footer .detail-back { margin: 0; color: var(--detail-accent); }
    .detail-unavailable { display: grid; justify-items: center; padding: 64px 26px; text-align: center; }
    .detail-unavailable-mark { display: grid; place-items: center; width: 54px; height: 54px; border-radius: 16px; background: var(--detail-tint); color: var(--detail-accent); font-size: 22px; font-weight: 850; }
    .detail-unavailable h1 { margin: 17px 0 6px; color: #0f172a; font-size: 20px; }
    .detail-unavailable p { max-width: 450px; margin: 0; color: #64748b; font-size: 13px; line-height: 1.6; }
    .detail-unavailable .detail-back { margin: 20px 0 0; color: var(--detail-accent); }
    @media (max-width: 900px) {
        .detail-overview { grid-template-columns: 1fr; }
        .detail-facts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .detail-fact + .detail-fact { border-top: 0; border-left: 1px solid #e2e8f0; }
    }
    @media (max-width: 680px) {
        .detail-hero { padding: 29px 24px; }
        .detail-hero-meta { flex-direction: column; gap: 7px; }
        .detail-section { padding: 22px 20px; }
        .detail-facts, .detail-people, .detail-milestones { grid-template-columns: 1fr; }
        .detail-fact + .detail-fact { border-top: 1px solid #e2e8f0; border-left: 0; }
        .detail-people-group + .detail-people-group, .detail-milestone + .detail-milestone { border-top: 1px solid #e2e8f0; border-left: 0; }
        .person-row { align-items: flex-start; }
        .metadata-row { grid-template-columns: 1fr; gap: 4px; }
        .detail-footer { align-items: flex-start; flex-direction: column; }
    }
</style>

<div class="research-detail">
    <?php if (!$project): ?>
        <section class="detail-panel detail-unavailable" aria-live="polite">
            <div class="detail-unavailable-mark" aria-hidden="true">!</div>
            <h1>Research record unavailable</h1>
            <p>This record may no longer be part of the archive, or the address may be incorrect.</p>
            <a class="detail-back" href="<?= archiveDetailEscape(SITE_URL . 'pages/shared/research-archive.php') ?>"><span aria-hidden="true">←</span> Return to Research Archive</a>
        </section>
    <?php else: ?>
        <a class="detail-back" href="<?= archiveDetailEscape(SITE_URL . 'pages/shared/research-archive.php') ?>"><span aria-hidden="true">←</span> Research Archive</a>

        <section class="detail-hero" aria-labelledby="research-detail-title">
            <div class="detail-hero-inner">
                <div class="detail-eyebrow">
                    <span class="detail-kicker">Institutional research record</span>
                    <span class="detail-id">RESEARCH #<?= (int) $project['project_id'] ?></span>
                </div>
                <h1 id="research-detail-title"><?= archiveDetailEscape($project['title']) ?></h1>
                <div class="detail-hero-meta">
                    <span>Lead proponent <strong><?= archiveDetailEscape($project['owner_name']) ?></strong></span>
                    <span>Archive year <strong><?= archiveDetailEscape(date('Y', strtotime($project['updated_at']))) ?></strong></span>
                </div>
                <span class="detail-badge"><?= archiveDetailEscape(ucfirst($project['status'])) ?> research</span>
            </div>
        </section>

        <div class="detail-overview">
            <section class="detail-panel detail-section" aria-labelledby="detail-abstract-title">
                <span class="detail-section-label">Research overview</span>
                <h2 id="detail-abstract-title">Abstract</h2>
                <?php if (trim((string) ($project['abstract'] ?? '')) !== ''): ?>
                    <div class="detail-copy"><?= archiveDetailEscape($project['abstract']) ?></div>
                <?php else: ?>
                    <p class="detail-copy muted">No abstract has been recorded for this research project.</p>
                <?php endif; ?>
            </section>

            <aside class="detail-panel" aria-label="Research facts">
                <dl class="detail-facts">
                    <div class="detail-fact"><dt>Archive status</dt><dd><?= archiveDetailEscape(archiveDetailLabel($project['status'])) ?></dd></div>
                    <div class="detail-fact"><dt>Research team</dt><dd><?= count($proponents) ?> <?= count($proponents) === 1 ? 'proponent' : 'proponents' ?></dd></div>
                    <div class="detail-fact"><dt>Adviser record</dt><dd><?= count($currentAdvisers) ?> current<?= $formerAdvisers ? ' · ' . count($formerAdvisers) . ' former' : '' ?></dd></div>
                </dl>
            </aside>
        </div>

        <div class="detail-section-head">
            <div>
                <h2>People behind the research</h2>
                <p>Recorded proponents and academic advisers for this project.</p>
            </div>
            <span class="detail-section-count"><?= count($proponents) + count($currentAdvisers) + count($formerAdvisers) ?> people</span>
        </div>
        <section class="detail-panel detail-people" aria-label="Research team and advisers">
            <div class="detail-people-group">
                <div class="detail-group-title"><h3>Proponents</h3><span><?= count($proponents) ?> recorded</span></div>
                <div class="people-list">
                    <?php foreach ($proponents as $proponent): ?>
                        <?php
                        $nameParts = preg_split('/\s+/', trim((string) $proponent['name'])) ?: [];
                        $initials = '';
                        foreach (array_slice($nameParts, 0, 2) as $namePart) {
                            $initials .= strtoupper(substr($namePart, 0, 1));
                        }
                        ?>
                        <div class="person-row">
                            <div class="person-identity">
                                <span class="person-initials" aria-hidden="true"><?= archiveDetailEscape($initials ?: 'P') ?></span>
                                <span class="person-name"><?= archiveDetailEscape($proponent['name']) ?></span>
                            </div>
                            <span class="person-tag"><?= archiveDetailEscape($proponent['role']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="detail-people-group">
                <div class="detail-group-title"><h3>Advisers</h3><span><?= count($currentAdvisers) + count($formerAdvisers) ?> recorded</span></div>
                <?php if (!$currentAdvisers && !$formerAdvisers): ?>
                    <p class="detail-empty-copy">No adviser information is recorded for this project.</p>
                <?php else: ?>
                    <div class="people-list">
                        <?php foreach ($currentAdvisers as $adviser): ?>
                            <div class="person-row">
                                <div class="person-identity">
                                    <span class="person-initials" aria-hidden="true">AD</span>
                                    <span class="person-name"><?= archiveDetailEscape($adviser['adviser_name']) ?></span>
                                </div>
                                <span class="person-tag current">Current</span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($formerAdvisers as $adviser): ?>
                            <div class="person-row">
                                <div class="person-identity">
                                    <span class="person-initials" aria-hidden="true">AD</span>
                                    <div>
                                        <div class="person-name"><?= archiveDetailEscape($adviser['adviser_name']) ?></div>
                                        <?php if (!empty($adviser['removed_at'])): ?>
                                            <div class="person-meta">Until <?= archiveDetailEscape(date('M j, Y', strtotime($adviser['removed_at']))) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="person-tag former">Former</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($finalDefense || $publication): ?>
            <div class="detail-section-head">
                <div>
                    <h2>Research milestones</h2>
                    <p>Defense, publication, colloquium, and archive tracking details.</p>
                </div>
            </div>
            <section class="detail-panel detail-milestones" aria-label="Research milestones">
                <?php if ($finalDefense): ?>
                    <div class="detail-milestone">
                        <h3>Final defense</h3>
                        <dl class="metadata-list">
                            <div class="metadata-row"><dt>Date</dt><dd><?= archiveDetailEscape(date('F j, Y, g:i A', strtotime($finalDefense['schedule_date']))) ?></dd></div>
                            <?php if (trim((string) ($finalDefense['venue'] ?? '')) !== ''): ?>
                                <div class="metadata-row"><dt>Venue</dt><dd><?= archiveDetailEscape($finalDefense['venue']) ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>

                <?php if ($publication): ?>
                    <div class="detail-milestone">
                        <h3>Publication &amp; colloquium</h3>
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
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer class="detail-footer">
            <p>Institutional Research Management System · Archived research record</p>
            <a class="detail-back" href="<?= archiveDetailEscape(SITE_URL . 'pages/shared/research-archive.php') ?>"><span aria-hidden="true">←</span> Return to Research Archive</a>
        </footer>
    <?php endif; ?>
</div>

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
