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

function archiveColumns(mysqli $conn, string $table): array
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

function archiveEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function archiveBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
}

$projectColumns = archiveColumns($conn, 'research_projects');
$publicationColumns = archiveColumns($conn, 'research_publication_tracking');
$publicationAvailable = isset($publicationColumns['project_id']);

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) > 150) {
    $query = substr($query, 0, 150);
}
$yearInput = trim((string) ($_GET['year'] ?? ''));
$year = preg_match('/^\d{4}$/D', $yearInput) ? $yearInput : '';

$selectPublication = [
    isset($publicationColumns['journal_status']) ? 'rpt.journal_status' : "NULL AS journal_status",
    isset($publicationColumns['colloquium_status']) ? 'rpt.colloquium_status' : "NULL AS colloquium_status",
];
$publicationJoin = $publicationAvailable
    ? 'LEFT JOIN research_publication_tracking rpt ON rpt.project_id = rp.project_id'
    : '';
$deletedGuard = isset($projectColumns['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';

$sql = 'SELECT rp.project_id, rp.title, rp.status, rp.updated_at,
               CONCAT_WS(\' \', owner.first_name, owner.last_name) AS owner_name,
               ' . implode(', ', $selectPublication) . '
        FROM research_projects rp
        JOIN users owner ON owner.user_id = rp.created_by
        ' . $publicationJoin . "
        WHERE rp.status IN ('completed', 'archived')" . $deletedGuard;
$params = [];
$types = '';
if ($query !== '') {
    $escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
    $likeQuery = '%' . $escapedQuery . '%';
    $sql .= " AND (rp.title LIKE ? ESCAPE '\\\\'
                   OR CONCAT_WS(' ', owner.first_name, owner.last_name) LIKE ? ESCAPE '\\\\')";
    $params[] = $likeQuery;
    $params[] = $likeQuery;
    $types .= 'ss';
}
if ($year !== '') {
    $sql .= ' AND YEAR(rp.updated_at) = ?';
    $params[] = (int) $year;
    $types .= 'i';
}
$sql .= ' ORDER BY rp.updated_at DESC, rp.project_id DESC LIMIT 101';

$projects = [];
$stmt = $conn->prepare($sql);
archiveBind($stmt, $types, $params);
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}
$stmt->close();
$isCapped = count($projects) > 100;
if ($isCapped) {
    $projects = array_slice($projects, 0, 100);
}

$role = (string) ($user['role'] ?? 'student');
$shell = match ($role) {
    'admin' => 'admin',
    'research_staff' => 'staff',
    'faculty' => 'faculty',
    default => 'student',
};

$archiveTheme = match ($shell) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'rgb' => '245, 124, 0'],
    'staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'rgb' => '13, 148, 136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'rgb' => '29, 78, 216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'rgb' => '91, 30, 188'],
};
$publishedCount = count(array_filter(
    $projects,
    static fn (array $project): bool => ($project['journal_status'] ?? '') === 'published'
));
$filtersActive = $query !== '' || $year !== '';

if ($shell === 'admin') {
    renderAdminShell($user, 'research-archive.php', 'Research Archive', 'Completed and archived research projects');
} elseif ($shell === 'staff') {
    renderStaffShell($user, 'research-archive.php', 'Research Archive', 'Completed and archived research projects');
} elseif ($shell === 'faculty') {
    renderFacultyShell($user, 'research-archive.php', 'Research Archive', 'Completed and archived research projects');
} else {
    renderStudentShell($user, 'research-archive.php', 'Research Archive', 'Completed and archived research projects');
}
?>

<style>
    .archive-page {
        --archive-accent: <?= archiveEscape($archiveTheme['accent']) ?>;
        --archive-deep: <?= archiveEscape($archiveTheme['deep']) ?>;
        --archive-tint: <?= archiveEscape($archiveTheme['tint']) ?>;
        --archive-rgb: <?= archiveEscape($archiveTheme['rgb']) ?>;
        max-width: 1320px;
        margin: 0 auto;
        color: #111827;
    }
    .archive-hero {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 40px;
        align-items: end;
        overflow: hidden;
        margin-bottom: 20px;
        padding: 34px 38px;
        border: 1px solid rgba(var(--archive-rgb), .34);
        border-radius: 20px;
        background:
            radial-gradient(circle at 88% 5%, rgba(255, 255, 255, .2), transparent 31%),
            linear-gradient(135deg, var(--archive-deep), var(--archive-accent));
        box-shadow: 0 20px 45px rgba(var(--archive-rgb), .15);
    }
    .archive-hero::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -105px;
        width: 270px;
        height: 270px;
        border: 1px solid rgba(255, 255, 255, .14);
        border-radius: 50%;
    }
    .archive-hero-copy, .archive-hero-summary { position: relative; z-index: 1; }
    .archive-kicker {
        display: block;
        margin-bottom: 9px;
        color: rgba(255, 255, 255, .76);
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .13em;
        text-transform: uppercase;
    }
    .archive-hero h1 {
        max-width: 730px;
        margin: 0;
        color: #fff;
        font-size: clamp(27px, 3vw, 40px);
        line-height: 1.08;
        letter-spacing: -.035em;
    }
    .archive-hero p {
        max-width: 680px;
        margin: 13px 0 0;
        color: rgba(255, 255, 255, .78);
        font-size: 15px;
        line-height: 1.65;
    }
    .archive-hero-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(118px, 1fr));
        min-width: 270px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, .2);
        border-radius: 15px;
        background: rgba(255, 255, 255, .1);
        backdrop-filter: blur(8px);
    }
    .archive-hero-stat { padding: 19px 18px; }
    .archive-hero-stat + .archive-hero-stat { border-left: 1px solid rgba(255, 255, 255, .18); }
    .archive-hero-stat strong { display: block; color: #fff; font-size: 29px; line-height: 1; }
    .archive-hero-stat span { display: block; margin-top: 7px; color: rgba(255, 255, 255, .76); font-size: 11px; font-weight: 700; }
    .archive-toolbar {
        display: grid;
        grid-template-columns: minmax(190px, .4fr) minmax(0, 1fr);
        gap: 30px;
        align-items: end;
        margin-bottom: 28px;
        padding: 22px 24px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .05);
    }
    .archive-toolbar-copy strong { display: block; color: #0f172a; font-size: 15px; }
    .archive-toolbar-copy span { display: block; margin-top: 5px; color: #64748b; font-size: 12px; line-height: 1.5; }
    .archive-filters { display: grid; grid-template-columns: minmax(230px, 1fr) 130px auto; gap: 12px; align-items: end; }
    .archive-field label { display: block; margin-bottom: 6px; color: #475569; font-size: 11px; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
    .archive-field input {
        width: 100%;
        box-sizing: border-box;
        min-height: 43px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 12px;
        background: #fff;
        color: #0f172a;
        font: inherit;
        font-size: 13px;
        transition: border-color .18s ease, box-shadow .18s ease;
    }
    .archive-field input::placeholder { color: #94a3b8; }
    .archive-field input:focus { border-color: var(--archive-accent); box-shadow: 0 0 0 3px rgba(var(--archive-rgb), .1); outline: 0; }
    .archive-actions { display: flex; gap: 8px; }
    .archive-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 43px;
        box-sizing: border-box;
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 10px 15px;
        background: var(--archive-accent);
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .archive-button:hover { color: #fff; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(var(--archive-rgb), .2); }
    .archive-button.secondary { border-color: #cbd5e1; background: #fff; color: #475569; }
    .archive-button.secondary:hover { border-color: rgba(var(--archive-rgb), .35); background: var(--archive-tint); color: var(--archive-accent); box-shadow: none; }
    .archive-results-head { display: flex; justify-content: space-between; gap: 20px; align-items: end; margin: 0 2px 12px; }
    .archive-results-head h2 { margin: 0; color: #0f172a; font-size: 18px; letter-spacing: -.015em; }
    .archive-results-head p { margin: 5px 0 0; color: #64748b; font-size: 12px; }
    .archive-result-count { flex: 0 0 auto; color: var(--archive-accent); font-size: 12px; font-weight: 800; }
    .archive-list { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 17px; background: #fff; box-shadow: 0 12px 32px rgba(15, 23, 42, .05); }
    .archive-row {
        display: grid;
        grid-template-columns: 46px minmax(0, 1fr) auto;
        gap: 18px;
        align-items: center;
        padding: 20px 22px;
        transition: background .18s ease;
    }
    .archive-row + .archive-row { border-top: 1px solid #e2e8f0; }
    .archive-row:hover { background: linear-gradient(90deg, var(--archive-tint), #fff 48%); }
    .archive-index {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border: 1px solid rgba(var(--archive-rgb), .2);
        border-radius: 11px;
        background: var(--archive-tint);
        color: var(--archive-accent);
        font-size: 11px;
        font-weight: 850;
        letter-spacing: .04em;
    }
    .archive-title { margin: 0; font-size: 15px; line-height: 1.45; }
    .archive-title a { color: #0f172a; text-decoration: none; transition: color .18s ease; }
    .archive-title a:hover { color: var(--archive-accent); }
    .archive-meta { display: flex; flex-wrap: wrap; gap: 7px 16px; margin-top: 7px; color: #64748b; font-size: 12px; }
    .archive-meta span { display: inline-flex; align-items: center; gap: 6px; }
    .archive-meta span + span::before { content: ""; width: 3px; height: 3px; border-radius: 50%; background: #cbd5e1; }
    .archive-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 11px; }
    .archive-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 10px; font-weight: 800; letter-spacing: .025em; white-space: nowrap; }
    .badge-completed { background: #dcfce7; color: #166534; }
    .badge-archived { background: #e2e8f0; color: #475569; }
    .badge-published { background: #f3e8ff; color: #6b21a8; }
    .badge-scheduled { background: #e0f2fe; color: #075985; }
    .archive-row-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 2px 9px 12px;
        color: var(--archive-accent);
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
        white-space: nowrap;
    }
    .archive-row-action span { font-size: 17px; transition: transform .18s ease; }
    .archive-row-action:hover span { transform: translateX(3px); }
    .archive-empty {
        display: grid;
        justify-items: center;
        padding: 58px 24px;
        border: 1px solid #e2e8f0;
        border-radius: 17px;
        background: #fff;
        text-align: center;
    }
    .archive-empty-mark { display: grid; place-items: center; width: 52px; height: 52px; border-radius: 15px; background: var(--archive-tint); color: var(--archive-accent); font-size: 21px; font-weight: 800; }
    .archive-empty h2 { margin: 16px 0 5px; color: #0f172a; font-size: 17px; }
    .archive-empty p { max-width: 440px; margin: 0; color: #64748b; font-size: 13px; line-height: 1.55; }
    .archive-empty .archive-button { margin-top: 18px; }
    .archive-note { margin: 12px 0 0; color: #64748b; text-align: center; font-size: 11px; }
    @media (max-width: 960px) {
        .archive-hero { grid-template-columns: 1fr; gap: 26px; }
        .archive-hero-summary { width: min(100%, 360px); }
        .archive-toolbar { grid-template-columns: 1fr; gap: 18px; }
    }
    @media (max-width: 680px) {
        .archive-hero { padding: 28px 24px; }
        .archive-hero-summary { min-width: 0; width: 100%; }
        .archive-toolbar { padding: 19px; }
        .archive-filters { grid-template-columns: 1fr; }
        .archive-actions, .archive-button { width: 100%; }
        .archive-results-head { align-items: flex-start; }
        .archive-row { grid-template-columns: 40px minmax(0, 1fr); gap: 13px; padding: 18px 16px; }
        .archive-index { width: 38px; height: 38px; }
        .archive-row-action { grid-column: 2; justify-self: start; padding: 3px 0 0; }
    }
</style>

<div class="archive-page">
    <section class="archive-hero" aria-labelledby="archive-hero-title">
        <div class="archive-hero-copy">
            <span class="archive-kicker">Institutional research repository</span>
            <h1 id="archive-hero-title">Explore completed research and institutional work.</h1>
            <p>Search finalized studies by title, lead proponent, or year, then open a record to review its full research details.</p>
        </div>
        <div class="archive-hero-summary" aria-label="Archive summary">
            <div class="archive-hero-stat">
                <strong><?= count($projects) ?></strong>
                <span>Results in view</span>
            </div>
            <div class="archive-hero-stat">
                <strong><?= $publishedCount ?></strong>
                <span>Published outputs</span>
            </div>
        </div>
    </section>

    <section class="archive-toolbar" aria-labelledby="archive-search-title">
        <div class="archive-toolbar-copy">
            <strong id="archive-search-title">Search the collection</strong>
            <span>Use one or both fields to narrow the archive.</span>
        </div>
        <form method="get" action="<?= archiveEscape(SITE_URL . 'pages/shared/research-archive.php') ?>" class="archive-filters">
            <div class="archive-field">
                <label for="archive-q">Title or lead proponent</label>
                <input id="archive-q" name="q" type="search" maxlength="150" value="<?= archiveEscape($query) ?>" placeholder="Search research records">
            </div>
            <div class="archive-field">
                <label for="archive-year">Year</label>
                <input id="archive-year" name="year" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" value="<?= archiveEscape($year) ?>" placeholder="YYYY">
            </div>
            <div class="archive-actions">
                <button type="submit" class="archive-button">Search</button>
                <?php if ($filtersActive): ?>
                    <a class="archive-button secondary" href="<?= archiveEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <div class="archive-results-head">
        <div>
            <h2>Research collection</h2>
            <p><?= $filtersActive ? 'Showing records that match your current filters.' : 'Completed and archived work from across the institution.' ?></p>
        </div>
        <span class="archive-result-count"><?= count($projects) ?> <?= count($projects) === 1 ? 'record' : 'records' ?></span>
    </div>

    <?php if (!$projects): ?>
        <section class="archive-empty" aria-live="polite">
            <div class="archive-empty-mark" aria-hidden="true">⌕</div>
            <h2>No research records found</h2>
            <p>Try a broader title, check the proponent name, or remove the year to see more results.</p>
            <?php if ($filtersActive): ?>
                <a class="archive-button" href="<?= archiveEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">View all research</a>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="archive-list" aria-label="Research archive results">
            <?php foreach ($projects as $index => $project): ?>
                <?php $projectUrl = SITE_URL . 'pages/shared/view-research.php?id=' . (int) $project['project_id']; ?>
                <article class="archive-row">
                    <div class="archive-index" aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <div>
                        <h3 class="archive-title">
                            <a href="<?= archiveEscape($projectUrl) ?>"><?= archiveEscape($project['title']) ?></a>
                        </h3>
                        <div class="archive-meta">
                            <span>Lead proponent: <?= archiveEscape($project['owner_name']) ?></span>
                            <span>Completed <?= archiveEscape(date('Y', strtotime($project['updated_at']))) ?></span>
                        </div>
                        <div class="archive-badges">
                            <span class="archive-badge <?= $project['status'] === 'archived' ? 'badge-archived' : 'badge-completed' ?>">
                                <?= archiveEscape(ucfirst($project['status'])) ?>
                            </span>
                            <?php if (($project['journal_status'] ?? '') === 'published'): ?>
                                <span class="archive-badge badge-published">Journal published</span>
                            <?php endif; ?>
                            <?php if (($project['colloquium_status'] ?? '') === 'scheduled'): ?>
                                <span class="archive-badge badge-scheduled">Presentation scheduled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="archive-row-action" href="<?= archiveEscape($projectUrl) ?>">
                        View research <span aria-hidden="true">→</span>
                    </a>
                </article>
            <?php endforeach; ?>
        </section>
        <?php if ($isCapped): ?>
            <p class="archive-note">Showing the first 100 results. Refine the search or year filter to narrow the archive.</p>
        <?php endif; ?>
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
