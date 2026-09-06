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
    .archive-card { background:#fff; border:1px solid #e3e7ee; border-radius:12px; padding:1rem; margin-bottom:1rem; }
    .archive-filters { display:grid; grid-template-columns:minmax(240px, 1fr) 150px auto; gap:.75rem; align-items:end; }
    .archive-field label { display:block; margin-bottom:.35rem; color:#475467; font-size:.85rem; font-weight:700; }
    .archive-field input { width:100%; box-sizing:border-box; border:1px solid #d0d5dd; border-radius:8px; padding:.65rem .75rem; font:inherit; }
    .archive-actions { display:flex; gap:.5rem; }
    .archive-button { border:0; border-radius:8px; padding:.67rem .9rem; background:#7c3aed; color:#fff; font-weight:700; text-decoration:none; cursor:pointer; }
    .archive-button.secondary { background:#fff; border:1px solid #d0d5dd; color:#344054; }
    .archive-list { display:grid; gap:.75rem; }
    .archive-row { display:grid; grid-template-columns:minmax(0, 1fr) 150px auto; gap:1rem; align-items:center; padding:1rem; border:1px solid #e3e7ee; border-radius:10px; }
    .archive-title { margin:0 0 .3rem; font-size:1rem; }
    .archive-title a { color:#101828; text-decoration:none; }
    .archive-title a:hover { color:#7c3aed; text-decoration:underline; }
    .archive-meta { color:#667085; font-size:.88rem; }
    .archive-badges { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.4rem; }
    .archive-badge { display:inline-flex; border-radius:999px; padding:.3rem .6rem; font-size:.74rem; font-weight:700; white-space:nowrap; }
    .badge-completed { background:#dcfce7; color:#166534; }
    .badge-archived { background:#e0e7ff; color:#3730a3; }
    .badge-published { background:#f3e8ff; color:#6b21a8; }
    .badge-scheduled { background:#e0f2fe; color:#075985; }
    .archive-empty, .archive-note { color:#667085; text-align:center; }
    .archive-note { margin:.9rem 0 0; font-size:.85rem; }
    @media (max-width:760px) { .archive-filters, .archive-row { grid-template-columns:1fr; } .archive-badges { justify-content:flex-start; } }
</style>

<section class="archive-card">
    <form method="get" action="<?= archiveEscape(SITE_URL . 'pages/shared/research-archive.php') ?>" class="archive-filters">
        <div class="archive-field">
            <label for="archive-q">Search title or lead proponent</label>
            <input id="archive-q" name="q" type="search" maxlength="150" value="<?= archiveEscape($query) ?>" placeholder="Enter a title or name">
        </div>
        <div class="archive-field">
            <label for="archive-year">Year</label>
            <input id="archive-year" name="year" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" value="<?= archiveEscape($year) ?>" placeholder="YYYY">
        </div>
        <div class="archive-actions">
            <button type="submit" class="archive-button">Filter</button>
            <a class="archive-button secondary" href="<?= archiveEscape(SITE_URL . 'pages/shared/research-archive.php') ?>">Clear</a>
        </div>
    </form>
</section>

<section class="archive-card">
    <?php if (!$projects): ?>
        <p class="archive-empty">No completed or archived research matched your filters.</p>
    <?php else: ?>
        <div class="archive-list">
            <?php foreach ($projects as $project): ?>
                <article class="archive-row">
                    <div>
                        <h2 class="archive-title">
                            <a href="<?= archiveEscape(SITE_URL . 'pages/shared/view-research.php?id=' . (int) $project['project_id']) ?>">
                                <?= archiveEscape($project['title']) ?>
                            </a>
                        </h2>
                        <div class="archive-meta">
                            Lead proponent: <?= archiveEscape($project['owner_name']) ?>
                            &middot; <?= archiveEscape(date('Y', strtotime($project['updated_at']))) ?>
                        </div>
                    </div>
                    <div>
                        <span class="archive-badge <?= $project['status'] === 'archived' ? 'badge-archived' : 'badge-completed' ?>">
                            <?= archiveEscape(ucfirst($project['status'])) ?>
                        </span>
                    </div>
                    <div class="archive-badges">
                        <?php if (($project['journal_status'] ?? '') === 'published'): ?>
                            <span class="archive-badge badge-published">Published</span>
                        <?php endif; ?>
                        <?php if (($project['colloquium_status'] ?? '') === 'scheduled'): ?>
                            <span class="archive-badge badge-scheduled">Presentation scheduled</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($isCapped): ?>
            <p class="archive-note">Showing the first 100 results. Refine the search or year filter to narrow the archive.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

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
