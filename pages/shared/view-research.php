<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/research-workflow.php';

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
    'research_projects', 'project_members', 'research_author_snapshots', 'defense_schedule', 'research_publication_tracking',
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
$finalDefense = null;
$publication = null;
$finalManuscript = null;

if ($projectId !== false && $projectId !== null && !empty($columns['research_projects'])) {
    $deletedGuard = isset($columns['research_projects']['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';
    $studentProgramCandidates = [];
    if (isset($columns['research_projects']['student_id'])) {
        $studentProgramCandidates[] = "(SELECT NULLIF(TRIM(student_researcher.program), '')
                                        FROM users student_researcher
                                        WHERE student_researcher.user_id = rp.student_id
                                          AND student_researcher.role = 'student'
                                          AND student_researcher.deleted_at IS NULL
                                        LIMIT 1)";
    }
    if (!empty($columns['project_members'])
        && isset($columns['project_members']['project_id'], $columns['project_members']['user_id'])) {
        $studentMemberOrder = isset($columns['project_members']['role'])
            ? "ORDER BY CASE WHEN pm.role = 'lead' THEN 0 ELSE 1 END, pm.user_id"
            : 'ORDER BY pm.user_id';
        $studentProgramCandidates[] = "(SELECT NULLIF(TRIM(student_member.program), '')
                                        FROM project_members pm
                                        JOIN users student_member
                                          ON student_member.user_id = pm.user_id
                                         AND student_member.role = 'student'
                                         AND student_member.deleted_at IS NULL
                                        WHERE pm.project_id = rp.project_id
                                        {$studentMemberOrder}
                                        LIMIT 1)";
    }
    $studentProgramSelect = $studentProgramCandidates
        ? 'COALESCE(' . implode(', ', $studentProgramCandidates) . ') AS student_program'
        : 'NULL AS student_program';
    $sql = "SELECT rp.project_id, rp.title, rp.status, rp.created_by, rp.updated_at,
                   {$studentProgramSelect},
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

if ($project && ($user['role'] ?? '') === 'faculty') {
        $adviserStmt = $conn->prepare(
                'SELECT 1 FROM research_projects rp
                    WHERE rp.project_id = ? AND rp.deleted_at IS NULL
                        AND (rp.adviser_id = ? OR EXISTS (
                                SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
                        ))
                    LIMIT 1'
        );
    if (!$adviserStmt) {
        throw new RuntimeException('Unable to check faculty advising access: ' . $conn->error);
    }
    $adviserStmt->bind_param('iii', $projectId, $user['user_id'], $user['user_id']);
    $adviserStmt->execute();
    $isAssignedAdviser = $adviserStmt->get_result()->num_rows > 0;
    $adviserStmt->close();
    if (!$isAssignedAdviser) {
        http_response_code(403);
        exit('Access denied.');
    }
}

if ($project) {
    $project['approved_final_abstract'] = rms_approved_final_manuscript_abstract($conn, (int) $project['project_id']);

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

    if (isset($columns['research_author_snapshots']['project_id'], $columns['research_author_snapshots']['user_id'], $columns['research_author_snapshots']['author_name'], $columns['research_author_snapshots']['author_role'])) {
        $stmt = $conn->prepare(
            'SELECT author_snapshot_id, user_id, author_name, author_role
               FROM research_author_snapshots
              WHERE project_id = ?
              ORDER BY display_order, author_snapshot_id'
        );
        if ($stmt) {
            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $memberId = (int) ($row['user_id'] ?? 0);
                $seenKey = $memberId > 0 ? $memberId : 'snapshot-' . (int) $row['author_snapshot_id'];
                if (isset($seenProponents[$seenKey])) {
                    continue;
                }
                $seenProponents[$seenKey] = true;
                $proponents[] = [
                    'name' => (string) $row['author_name'],
                    'role' => $row['author_role'] === 'lead' ? 'Lead proponent' : 'Member',
                ];
            }
            $stmt->close();
        }
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

    // Fetch final manuscript document for students to view
    $stmt = $conn->prepare(
        "SELECT upload_id, original_name, file_name, file_path, file_size, upload_date
         FROM uploads
         WHERE project_id = ? AND type = 'manuscript'
         ORDER BY upload_date DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $finalManuscript = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
    .detail-facts-panel { overflow: hidden; }
    .detail-facts-panel .detail-section-head { align-items: flex-start; margin: 0 22px; padding: 18px 0 12px; border-top: 1px solid #e2e8f0; }
    .detail-facts-panel .detail-section-head h2 { font-size: 15px; }
    .detail-facts-panel .detail-section-head p { margin-top: 3px; }
    .detail-facts-panel .detail-people { display: block; overflow: visible; }
    .detail-facts-panel .detail-people-group { padding: 0 22px 18px; }
    .detail-document-section { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; padding: 26px 28px; border-top: 1px solid #e2e8f0; }
    .detail-document-section > .detail-section-label { grid-column: 1 / -1; margin-bottom: -8px; }
    .detail-document-details { min-width: 0; }
    .detail-document-open:focus-visible { outline: 2px solid var(--detail-accent); outline-offset: 3px; }
    .detail-document-preview { min-width: 0; }
    .detail-document-preview-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin-bottom: 10px; }
    .detail-document-preview-label { color: #475569; font-size: 11px; font-weight: 750; }
    .detail-document-open { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 7px 11px; border: 1px solid rgba(var(--detail-rgb), .3); border-radius: 9px; background: var(--detail-tint); color: var(--detail-deep); font-size: 11px; font-weight: 750; line-height: 1.3; text-decoration: none; transition: background-color .18s ease, border-color .18s ease, color .18s ease; }
    .detail-document-open:hover { border-color: var(--detail-deep); background: var(--detail-deep); color: #fff; }
    .detail-document-open-arrow { font-size: 14px; line-height: 1; }
    .detail-document-frame { display: block; width: 100%; height: clamp(520px, 72vh, 900px); border: 1px solid #dbe3ee; border-radius: 12px; background: #f8fafc; }
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
        .detail-document-section { grid-template-columns: minmax(0, 1fr); padding: 22px 20px; }
        .detail-document-section > .detail-section-label { grid-column: 1; }
        .detail-document-frame { height: clamp(420px, 72vh, 720px); }
        .detail-facts, .detail-people, .detail-milestones { grid-template-columns: 1fr; }
        .detail-fact + .detail-fact { border-top: 1px solid #e2e8f0; border-left: 0; }
        .detail-people-group + .detail-people-group, .detail-milestone + .detail-milestone { border-top: 1px solid #e2e8f0; border-left: 0; }
        .person-row { align-items: flex-start; }
        .metadata-row { grid-template-columns: 1fr; gap: 4px; }
        .detail-footer { align-items: flex-start; flex-direction: column; }
    }
    @media (prefers-reduced-motion: reduce) {
        .detail-document-open { transition: none; }
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
                    <span>Student researcher program <strong><?= archiveDetailEscape(trim((string) ($project['student_program'] ?? '')) ?: 'Not recorded') ?></strong></span>
                    <span>Archive year <strong><?= archiveDetailEscape(date('Y', strtotime($project['updated_at']))) ?></strong></span>
                </div>
                <span class="detail-badge"><?= archiveDetailEscape(ucfirst($project['status'])) ?> research</span>
            </div>
        </section>

        <div class="detail-overview">
            <?php if ($project['approved_final_abstract'] !== null): ?>
                <section class="detail-panel detail-section" aria-labelledby="detail-abstract-title">
                    <span class="detail-section-label">Approved manuscript</span>
                    <h2 id="detail-abstract-title">Abstract</h2>
                    <div class="detail-copy"><?= archiveDetailEscape($project['approved_final_abstract']) ?></div>
                </section>
            <?php endif; ?>

            <aside class="detail-panel detail-facts-panel" aria-label="Research facts">
                <dl class="detail-facts">
                    <div class="detail-fact"><dt>Archive status</dt><dd><?= archiveDetailEscape(archiveDetailLabel($project['status'])) ?></dd></div>
                    <div class="detail-fact"><dt>Research team</dt><dd><?= count($proponents) ?> <?= count($proponents) === 1 ? 'proponent' : 'proponents' ?></dd></div>
                </dl>
                <div class="detail-section-head">
                    <div>
                        <h2>People behind the research</h2>
                        <p>Recorded proponents for this project.</p>
                    </div>
                </div>
                <section class="detail-people" aria-label="Research proponents">
                    <div class="detail-people-group">
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
                </section>
            </aside>
        </div>

        <?php if ($finalManuscript): ?>
            <?php $manuscriptViewerUrl = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $finalManuscript['upload_id'] . '&mode=viewer'; ?>
            <?php $manuscriptInlineUrl = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $finalManuscript['upload_id'] . '&mode=inline'; ?>
            <section class="detail-panel detail-document-section" aria-labelledby="detail-manuscript-title">
                <span class="detail-section-label">Research document</span>
                <div class="detail-document-details">
                    <h2 id="detail-manuscript-title">Final manuscript</h2>
                </div>
                <div class="detail-document-preview">
                    <div class="detail-document-preview-head">
                        <span class="detail-document-preview-label">Inline document preview</span>
                        <a class="detail-document-open" href="<?= archiveDetailEscape($manuscriptViewerUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open final manuscript in full view (opens a new tab)">
                            <span class="detail-document-open-arrow" aria-hidden="true">↗</span>
                            <span>Click to open full view</span>
                        </a>
                    </div>
                    <iframe class="detail-document-frame" src="<?= archiveDetailEscape($manuscriptInlineUrl) ?>" title="Inline preview of <?= archiveDetailEscape($finalManuscript['original_name']) ?>" loading="lazy"></iframe>
                </div>
            </section>
        <?php endif; ?>

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
