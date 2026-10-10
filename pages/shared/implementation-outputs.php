<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
if (!in_array($role, ['admin', 'research_staff'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$viewInput = trim((string) ($_GET['view'] ?? 'ongoing'));
$view = preg_match('/^(ongoing|completed|outputs)$/D', $viewInput) ? $viewInput : 'ongoing';
$viewConfig = [
    'ongoing' => [
        'module_page' => 'ongoing_research',
        'title' => 'Ongoing Research',
        'description' => 'Track title-approved research as chapters move through implementation and review.',
    ],
    'completed' => [
        'module_page' => 'completed_research',
        'title' => 'Completed Research',
        'description' => 'Review projects whose configured chapters are approved, alongside legacy completed records.',
    ],
    'outputs' => [
        'module_page' => 'research_outputs',
        'title' => 'Research Outputs',
        'description' => 'Monitor published research and registered intellectual-property outputs.',
    ],
];
$modulePage = $viewConfig[$view]['module_page'];
if (!hasRole('admin') && !hasModulePermission($userId, 'research_implementation_outputs', 'view', $modulePage)) {
    http_response_code(403);
    exit('Access denied.');
}
$_GET['module_page'] = $modulePage;

function implementationOutputsQuery(mysqli $connection, string $sql): mysqli_result
{
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the implementation and outputs query: ' . $connection->error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load implementation and outputs data: ' . $error);
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function implementationOutputsTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the implementation and outputs schema.');
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect the implementation and outputs schema: ' . $error);
    }
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function implementationOutputsColumnExists(mysqli $connection, string $table, string $column): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect the implementation and outputs schema.');
    }
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect the implementation and outputs schema: ' . $error);
    }
    $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function implementationOutputsEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function implementationOutputsDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? 'Not recorded' : date('M j, Y', $timestamp);
}

$projectsHaveDeletedAt = implementationOutputsColumnExists($conn, 'research_projects', 'deleted_at');
$chaptersHaveDeletedAt = implementationOutputsColumnExists($conn, 'chapters', 'deleted_at');
$projectDeletedFilter = $projectsHaveDeletedAt ? ' AND rp.deleted_at IS NULL' : '';
$chapterDeletedFilter = $chaptersHaveDeletedAt ? ' WHERE deleted_at IS NULL' : '';
$chaptersJoinDeletedFilter = $chaptersHaveDeletedAt ? ' AND ch.deleted_at IS NULL' : '';
$publicationsAvailable = implementationOutputsTableExists($conn, 'publications');
$copyrightsAvailable = implementationOutputsTableExists($conn, 'copyright_applications');

$projects = [];
$attentionProjects = [];
$completedProjects = [];
$recentOutputs = [];
$stats = [];
$publishedAvailable = $publicationsAvailable;
$registeredAvailable = $copyrightsAvailable;
$currentYear = (int) date('Y');

$chapterAggregate = "
    SELECT project_id,
           COUNT(*) AS total_chapters,
           SUM(status = 'approved') AS approved_chapters,
           SUM(status = 'submitted') AS submitted_chapters,
           SUM(status = 'revision_required') AS returned_chapters,
           MAX(updated_at) AS latest_activity,
           MAX(CASE WHEN status = 'approved' THEN approved_at END) AS last_approved_at
      FROM chapters{$chapterDeletedFilter}
     GROUP BY project_id
";
$approvedTitleAggregate = "
    SELECT v.project_id,
           MAX(COALESCE(r.reviewed_at, v.submitted_at)) AS title_approved_at,
           MAX(v.submitted_at) AS approved_title_submitted_at
      FROM research_submission_versions v
 LEFT JOIN research_submission_reviews r
        ON r.version_id = v.version_id AND r.decision = 'approved'
     WHERE v.submission_type = 'title' AND v.status = 'approved'
     GROUP BY v.project_id
";

if ($view === 'ongoing') {
    $sql = "
        SELECT rp.project_id, rp.title, rp.status, rp.created_at,
               CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), '')) AS researcher_name,
               u.role AS researcher_role,
               COALESCE(ta.title_approved_at, ta.approved_title_submitted_at, rp.created_at) AS title_approved_at,
               COALESCE(ca.total_chapters, 0) AS total_chapters,
               COALESCE(ca.approved_chapters, 0) AS approved_chapters,
               COALESCE(ca.submitted_chapters, 0) AS submitted_chapters,
               COALESCE(ca.returned_chapters, 0) AS returned_chapters,
               ca.latest_activity,
               (ca.latest_activity IS NULL OR ca.latest_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS stale_activity
          FROM research_projects rp
     LEFT JOIN users u ON u.user_id = rp.created_by AND u.deleted_at IS NULL
     LEFT JOIN ({$chapterAggregate}) ca ON ca.project_id = rp.project_id
     LEFT JOIN ({$approvedTitleAggregate}) ta ON ta.project_id = rp.project_id
         WHERE rp.status IN ('title_approved', 'ongoing'){$projectDeletedFilter}
      ORDER BY ca.latest_activity IS NULL ASC, ca.latest_activity ASC, rp.updated_at DESC, rp.project_id DESC
    ";
    $result = implementationOutputsQuery($conn, $sql);
    while ($row = $result->fetch_assoc()) {
        $row['researcher_name'] = trim((string) ($row['researcher_name'] ?? '')) ?: 'Unknown researcher';
        $row['total_chapters'] = (int) $row['total_chapters'];
        $row['approved_chapters'] = (int) $row['approved_chapters'];
        $row['submitted_chapters'] = (int) $row['submitted_chapters'];
        $row['returned_chapters'] = (int) $row['returned_chapters'];
        $row['stale_activity'] = (bool) $row['stale_activity'];
        $projects[] = $row;
        if ($row['returned_chapters'] > 0 || $row['stale_activity']) {
            $attentionProjects[] = $row;
        }
    }
    $result->free();

    $stats = [
        'in_progress' => count($projects),
        'under_review' => array_sum(array_column($projects, 'submitted_chapters')),
        'approved' => array_sum(array_column($projects, 'approved_chapters')),
        'returned' => array_sum(array_column($projects, 'returned_chapters')),
    ];
} elseif ($view === 'completed') {
    $sql = "
        SELECT rp.project_id, rp.title, rp.status, rp.created_at, rp.updated_at,
               CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), '')) AS researcher_name,
               u.role AS researcher_role,
               COALESCE(ca.total_chapters, 0) AS total_chapters,
               COALESCE(ca.approved_chapters, 0) AS approved_chapters,
               ca.last_approved_at,
               COALESCE(ta.approved_title_submitted_at, rp.created_at) AS title_start_at
          FROM research_projects rp
     LEFT JOIN users u ON u.user_id = rp.created_by AND u.deleted_at IS NULL
     LEFT JOIN ({$chapterAggregate}) ca ON ca.project_id = rp.project_id
     LEFT JOIN ({$approvedTitleAggregate}) ta ON ta.project_id = rp.project_id
         WHERE (
                 (COALESCE(ca.total_chapters, 0) > 0 AND ca.approved_chapters = ca.total_chapters)
                 OR rp.status IN ('completed', 'archived')
               ){$projectDeletedFilter}
      ORDER BY COALESCE(ca.last_approved_at, rp.updated_at) DESC, rp.project_id DESC
    ";
    $result = implementationOutputsQuery($conn, $sql);
    $completedThisYear = 0;
    $completionDays = [];
    while ($row = $result->fetch_assoc()) {
        $row['researcher_name'] = trim((string) ($row['researcher_name'] ?? '')) ?: 'Unknown researcher';
        $row['total_chapters'] = (int) $row['total_chapters'];
        $row['approved_chapters'] = (int) $row['approved_chapters'];
        $row['completed_at'] = $row['last_approved_at'] ?: $row['updated_at'];
        if ($row['last_approved_at'] && (int) date('Y', strtotime((string) $row['last_approved_at'])) === $currentYear) {
            $completedThisYear++;
        }
        if ($row['last_approved_at']) {
            $start = strtotime((string) $row['title_start_at']);
            $finish = strtotime((string) $row['last_approved_at']);
            if ($start !== false && $finish !== false && $finish >= $start) {
                $completionDays[] = (int) floor(($finish - $start) / 86400);
            }
        }
        $completedProjects[] = $row;
    }
    $result->free();

    $stats = [
        'completed' => count($completedProjects),
        'completed_this_year' => $completedThisYear,
        'average_days' => $completionDays
            ? number_format(array_sum($completionDays) / count($completionDays), 1)
            : '—',
    ];
} else {
    $publishedCount = 0;
    $publishedThisYear = 0;
    if ($publicationsAvailable) {
        $result = implementationOutputsQuery(
            $conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(publication_date IS NOT NULL AND YEAR(publication_date) = YEAR(CURDATE())), 0) AS this_year
               FROM publications
              WHERE status = 'published'"
        );
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $publishedCount = (int) ($row['total'] ?? 0);
        $publishedThisYear = (int) ($row['this_year'] ?? 0);
    }
    $registeredCount = 0;
    $registeredThisYear = 0;
    if ($copyrightsAvailable) {
        $result = implementationOutputsQuery(
            $conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(registered_at IS NOT NULL AND YEAR(registered_at) = YEAR(CURDATE())), 0) AS this_year
               FROM copyright_applications
              WHERE status = 'registered'"
        );
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $registeredCount = (int) ($row['total'] ?? 0);
        $registeredThisYear = (int) ($row['this_year'] ?? 0);
    }

    $stats = [
        'published' => $publishedCount,
        'registered' => $registeredCount,
        'this_year' => $publishedThisYear + $registeredThisYear,
    ];
    if ($publicationsAvailable && $copyrightsAvailable) {
        $sql = "
            SELECT feed.record_type, feed.type_label, feed.record_title, feed.person_name,
                   feed.event_date, feed.sort_date, feed.link_url
              FROM (
                    SELECT 'publication' AS record_type,
                           CASE p.publication_type
                               WHEN 'journal' THEN 'Journal'
                               WHEN 'conference' THEN 'Conference'
                               WHEN 'book_chapter' THEN 'Book chapter'
                               ELSE 'Publication'
                           END AS type_label,
                           p.research_title AS record_title,
                           COALESCE(
                               NULLIF(TRIM(p.researcher_name), ''),
                               NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                               'Unknown researcher'
                           ) AS person_name,
                           p.publication_date AS event_date,
                           COALESCE(p.publication_date, DATE(p.created_at)) AS sort_date,
                           ? AS link_url
                      FROM publications p
                 LEFT JOIN users u ON u.user_id = p.researcher_id AND u.deleted_at IS NULL
                     WHERE p.status = 'published'
                    UNION ALL
                    SELECT 'copyright' AS record_type,
                           'Copyright' AS type_label,
                           c.output_title AS record_title,
                           COALESCE(
                               NULLIF(TRIM(c.applicant_name), ''),
                               NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                               'Unknown applicant'
                           ) AS person_name,
                           DATE(c.registered_at) AS event_date,
                           COALESCE(c.registered_at, c.created_at) AS sort_date,
                           ? AS link_url
                      FROM copyright_applications c
                 LEFT JOIN users u ON u.user_id = c.applicant_id AND u.deleted_at IS NULL
                     WHERE c.status = 'registered'
                   ) AS feed
          ORDER BY feed.sort_date DESC, feed.record_title ASC
             LIMIT 10
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the recent outputs query: ' . $conn->error);
        }
        $publicationHubUrl = SITE_URL . 'pages/shared/publications.php?status=published';
        $copyrightHubUrl = SITE_URL . 'pages/shared/copyrights.php?status=registered&module_page=research_outputs';
        $stmt->bind_param('ss', $publicationHubUrl, $copyrightHubUrl);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to load recent outputs: ' . $error);
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recentOutputs[] = $row;
        }
        $result->free();
        $stmt->close();
    }
}

$canViewPublications = $role === 'admin'
    || hasModulePermission($userId, 'research_implementation_outputs', 'view', 'research_outputs');
$canViewCopyrights = $role === 'admin'
    || hasModulePermission($userId, 'intellectual_property_utilization', 'view', 'copyrights');
$canViewTab = static function (string $targetView) use ($role, $userId, $viewConfig): bool {
    return $role === 'admin'
        || hasModulePermission(
            $userId,
            'research_implementation_outputs',
            'view',
            $viewConfig[$targetView]['module_page']
        );
};

$pageTitle = $viewConfig[$view]['title'];
$pageDescription = $viewConfig[$view]['description'];
$shellPage = 'implementation-outputs.php';
if ($role === 'admin') {
    renderAdminShell($user, $shellPage, $pageTitle, $pageDescription);
} else {
    renderStaffShell($user, $shellPage, $pageTitle, $pageDescription);
}

$accent = $role === 'admin' ? '#F57C00' : '#0d9488';
$accentDark = $role === 'admin' ? '#c2410c' : '#0f766e';
$accentTint = $role === 'admin' ? '#fff7ed' : '#f0fdfa';
$tabs = [
    'ongoing' => 'Ongoing Research',
    'completed' => 'Completed Research',
    'outputs' => 'Research Outputs',
];
?>
<style>
.implementation-hub{--io-accent:<?= implementationOutputsEscape($accent) ?>;--io-accent-dark:<?= implementationOutputsEscape($accentDark) ?>;--io-accent-tint:<?= implementationOutputsEscape($accentTint) ?>;max-width:1320px;margin:0 auto;padding:0 0 36px;color:#172033}
.io-intro{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin:0 0 22px}
.io-intro h1{margin:0;color:#111827;font-size:clamp(25px,3vw,34px);letter-spacing:-.035em}
.io-intro p{max-width:66ch;margin:8px 0 0;color:#64748b;font-size:14px;line-height:1.65}
.io-tabs{display:flex;gap:8px;overflow-x:auto;margin:0 0 20px;padding:3px 2px 8px}
.io-tab{flex:0 0 auto;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;background:#fff;color:#475569;font-size:13px;font-weight:650;text-decoration:none;transition:background .18s ease,border-color .18s ease,color .18s ease}
.io-tab:hover,.io-tab[aria-current=page]{border-color:color-mix(in srgb,var(--io-accent) 30%,white);background:var(--io-accent-tint);color:var(--io-accent-dark)}
.io-tab:focus-visible,.io-link:focus-visible{outline:2px solid var(--io-accent);outline-offset:3px}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.stat-card{min-width:0;padding:19px 20px;border:1px solid #e5e7eb;border-top:3px solid var(--io-accent);border-radius:14px;background:#fff;box-shadow:0 5px 16px rgba(15,23,42,.035)}
.stat-card strong{display:block;color:#111827;font-size:27px;line-height:1.1;font-variant-numeric:tabular-nums}
.stat-card span{display:block;margin-top:7px;color:#64748b;font-size:12px;font-weight:600}
.hub-panel{margin-bottom:20px;overflow:hidden;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(15,23,42,.035)}
.panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 22px;border-bottom:1px solid #eef0f3}
.panel-head h2{margin:0;color:#111827;font-size:17px;letter-spacing:-.02em}
.panel-head p{margin:6px 0 0;color:#64748b;font-size:12px;line-height:1.55}
.table-wrap{width:100%;overflow-x:auto}
.hub-table{width:100%;border-collapse:collapse;text-align:left;font-size:12px}
.hub-table th{position:sticky;top:0;padding:12px 14px;background:#f8fafc;color:#64748b;font-size:10px;font-weight:750;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
.hub-table td{padding:14px;border-top:1px solid #eef0f3;color:#334155;vertical-align:middle}
.hub-table tbody tr:hover{background:#fafbfd}
.io-title{display:inline-block;color:#172033;font-size:12px;font-weight:700;line-height:1.45;text-decoration:none}
.io-title:hover{color:var(--io-accent-dark);text-decoration:underline}
.io-person{display:flex;align-items:center;gap:7px;flex-wrap:wrap;color:#334155}
.io-role,.io-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;background:var(--io-accent-tint);color:var(--io-accent-dark);font-size:10px;font-weight:700;white-space:nowrap}
.io-badge.neutral{background:#f1f5f9;color:#475569}
.io-badge.warning{background:#fff7ed;color:#c2410c}
.io-progress{display:grid;gap:6px;min-width:130px}
.io-progress-copy{color:#475569;font-size:11px;font-variant-numeric:tabular-nums}
.io-progress-track{height:6px;overflow:hidden;border-radius:999px;background:#e2e8f0}
.io-progress-track span{display:block;height:100%;border-radius:inherit;background:var(--io-accent)}
.io-muted{color:#64748b;font-size:11px;white-space:nowrap}
.empty-state{display:flex;align-items:flex-start;gap:14px;padding:28px 22px;color:#64748b}
.empty-mark{display:grid;place-items:center;flex:0 0 38px;height:38px;border-radius:11px;background:var(--io-accent-tint);color:var(--io-accent-dark);font-size:18px}
.empty-state h3{margin:0;color:#111827;font-size:14px}
.empty-state p{margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.6}
.io-attention-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:16px}
.io-attention-item{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px;border:1px solid #f1d7bd;border-radius:11px;background:#fffaf5}
.io-attention-item strong{display:block;color:#172033;font-size:12px;line-height:1.45}
.io-attention-item small{display:block;margin-top:5px;color:#64748b;font-size:11px;line-height:1.5}
.io-attention-reason{flex:0 0 auto;color:#9a3412;font-size:10px;font-weight:750}
.io-quick-links{display:flex;gap:10px;flex-wrap:wrap;padding:16px 22px 0}
.io-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #dbe2ea;border-radius:9px;padding:0 13px;background:#fff;color:#334155;font-size:12px;font-weight:700;text-decoration:none;transition:transform .18s ease,border-color .18s ease,background .18s ease}
.io-link:hover{transform:translateY(-1px);border-color:var(--io-accent);background:var(--io-accent-tint);color:var(--io-accent-dark)}
.io-link.disabled{color:#94a3b8;background:#f8fafc;cursor:not-allowed}
.io-output-type{display:inline-flex;border-radius:7px;padding:5px 8px;background:#f1f5f9;color:#475569;font-size:10px;font-weight:700;white-space:nowrap}
@media(max-width:980px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.io-attention-list{grid-template-columns:1fr}}
@media(max-width:640px){.implementation-hub{padding-bottom:24px}.io-intro{display:block}.io-intro p{font-size:13px}.stat-grid{gap:8px}.stat-card{padding:15px}.stat-card strong{font-size:23px}.panel-head{padding:17px}.hub-table th,.hub-table td{padding:11px}.io-quick-links{padding:14px 16px 0}.io-link{width:100%}}
@media(prefers-reduced-motion:reduce){.io-tab,.io-link{transition:none}}
</style>

<main class="implementation-hub">
  <header class="io-intro">
    <div>
      <h1><?= implementationOutputsEscape($pageTitle) ?></h1>
      <p><?= implementationOutputsEscape($pageDescription) ?></p>
    </div>
  </header>

  <nav class="io-tabs" aria-label="Research implementation sections">
    <?php foreach ($tabs as $tabView => $label): ?>
      <?php if ($canViewTab($tabView)): ?>
        <a class="io-tab" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/implementation-outputs.php?view=' . rawurlencode($tabView) . '&module_page=' . rawurlencode($viewConfig[$tabView]['module_page'])) ?>" <?= $view === $tabView ? 'aria-current="page"' : '' ?>><?= implementationOutputsEscape($label) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <?php if ($view === 'ongoing'): ?>
    <section class="stat-grid" aria-label="Implementation statistics">
      <div class="stat-card"><strong><?= (int) $stats['in_progress'] ?></strong><span>In progress</span></div>
      <div class="stat-card"><strong><?= (int) $stats['under_review'] ?></strong><span>Chapters under review</span></div>
      <div class="stat-card"><strong><?= (int) $stats['approved'] ?></strong><span>Chapters approved</span></div>
      <div class="stat-card"><strong><?= (int) $stats['returned'] ?></strong><span>Chapters returned</span></div>
    </section>

    <section class="hub-panel" aria-labelledby="attention-heading">
      <div class="panel-head"><div><h2 id="attention-heading">Needs attention</h2><p>Projects with a returned chapter or no chapter activity in the last 30 days.</p></div></div>
      <?php if (!$attentionProjects): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">✓</div><div><h3>Nothing needs attention</h3><p>Returned chapters and quiet projects will appear here.</p></div></div>
      <?php else: ?>
        <div class="io-attention-list">
          <?php foreach ($attentionProjects as $project): ?>
            <?php $reason = $project['returned_chapters'] > 0 ? 'Returned chapter' : 'No chapter activity in 30 days'; ?>
            <article class="io-attention-item">
              <div>
                <strong><a class="io-title" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= implementationOutputsEscape((string) $project['title']) ?></a></strong>
                <small><?= implementationOutputsEscape($project['researcher_name']) ?> · Last activity: <?= implementationOutputsEscape(implementationOutputsDate($project['latest_activity'])) ?></small>
              </div>
              <span class="io-attention-reason"><?= implementationOutputsEscape($reason) ?></span>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="ongoing-directory-heading">
      <div class="panel-head"><div><h2 id="ongoing-directory-heading">Research in implementation</h2><p><?= count($projects) ?> <?= count($projects) === 1 ? 'project' : 'projects' ?> · Titles appear here after title approval.</p></div></div>
      <?php if (!$projects): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">↗</div><div><h3>No research in implementation yet</h3><p>Projects appear here once a title is approved.</p></div></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table">
          <thead><tr><th>Project</th><th>Researcher</th><th>Title approved</th><th>Chapter progress</th><th>Latest activity</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($projects as $project): ?>
              <?php $progress = min(100, (int) round(((int) $project['approved_chapters'] / 5) * 100)); ?>
              <tr>
                <td><a class="io-title" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= implementationOutputsEscape((string) $project['title']) ?></a></td>
                <td><div class="io-person"><span><?= implementationOutputsEscape($project['researcher_name']) ?></span><span class="io-role"><?= implementationOutputsEscape(ucfirst((string) ($project['researcher_role'] ?? 'researcher'))) ?></span></div></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($project['title_approved_at'])) ?></td>
                <td><div class="io-progress"><span class="io-progress-copy"><?= (int) $project['approved_chapters'] ?> / 5 approved</span><span class="io-progress-track" role="img" aria-label="<?= (int) $project['approved_chapters'] ?> of 5 chapters approved"><span style="width:<?= $progress ?>%"></span></span></div></td>
                <td><span class="io-muted"><?= implementationOutputsEscape(implementationOutputsDate($project['latest_activity'])) ?></span></td>
                <td><span class="io-badge"><?= implementationOutputsEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

  <?php elseif ($view === 'completed'): ?>
    <section class="stat-grid" aria-label="Completed research statistics">
      <div class="stat-card"><strong><?= (int) $stats['completed'] ?></strong><span>Completed research</span></div>
      <div class="stat-card"><strong><?= (int) $stats['completed_this_year'] ?></strong><span>Completed this year</span></div>
      <div class="stat-card"><strong><?= implementationOutputsEscape((string) $stats['average_days']) ?></strong><span>Average days to last chapter approval</span></div>
    </section>

    <section class="hub-panel" aria-labelledby="completed-directory-heading">
      <div class="panel-head"><div><h2 id="completed-directory-heading">Completed research register</h2><p>Projects with approved chapter records, plus legacy records already marked complete or archived.</p></div></div>
      <?php if (!$completedProjects): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">✓</div><div><h3>No completed research yet</h3><p>Projects appear here when their configured chapters are approved.</p></div></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table">
          <thead><tr><th>Project</th><th>Researcher</th><th>Completed</th><th>Chapter tally</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($completedProjects as $project): ?>
              <tr>
                <td><a class="io-title" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= implementationOutputsEscape((string) $project['title']) ?></a></td>
                <td><div class="io-person"><span><?= implementationOutputsEscape($project['researcher_name']) ?></span><span class="io-role"><?= implementationOutputsEscape(ucfirst((string) ($project['researcher_role'] ?? 'researcher'))) ?></span></div></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($project['completed_at'])) ?></td>
                <td><span class="io-badge neutral"><?= (int) $project['approved_chapters'] ?> / <?= (int) $project['total_chapters'] ?> approved</span></td>
                <td><span class="io-badge"><?= implementationOutputsEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <section class="stat-grid" aria-label="Research output statistics">
      <div class="stat-card"><strong><?= (int) $stats['published'] ?></strong><span>Published publications</span></div>
      <div class="stat-card"><strong><?= (int) $stats['registered'] ?></strong><span>Registered copyrights</span></div>
      <div class="stat-card"><strong><?= (int) $stats['this_year'] ?></strong><span>Combined this year</span></div>
    </section>

    <section class="hub-panel" aria-labelledby="outputs-quick-links-heading">
      <div class="panel-head"><div><h2 id="outputs-quick-links-heading">Output registers</h2><p>Open the publication and intellectual-property hubs to review full records.</p></div></div>
      <div class="io-quick-links">
        <?php if ($canViewPublications): ?><a class="io-link" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/publications.php?status=published&module_page=research_outputs') ?>">Open publications hub</a><?php else: ?><span class="io-link disabled" aria-disabled="true">Publications hub unavailable to this account</span><?php endif; ?>
        <?php if ($canViewCopyrights): ?><a class="io-link" href="<?= implementationOutputsEscape(SITE_URL . 'pages/shared/copyrights.php?status=registered&module_page=research_outputs') ?>">Open copyrights hub</a><?php else: ?><span class="io-link disabled" aria-disabled="true">Copyrights hub unavailable to this account</span><?php endif; ?>
      </div>
      <?php if (!$publicationsAvailable || !$copyrightsAvailable): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">i</div><div><h3>Some output records are unavailable</h3><p><?= !$publicationsAvailable ? 'The publications table is missing. ' : '' ?><?= !$copyrightsAvailable ? 'The copyright registry is missing.' : '' ?></p></div></div>
      <?php elseif (!$recentOutputs): ?>
        <div class="empty-state"><div class="empty-mark" aria-hidden="true">↗</div><div><h3>No registered or published outputs yet</h3><p>Published publications and registered copyright records will appear here.</p></div></div>
      <?php else: ?>
        <div class="table-wrap"><table class="hub-table">
          <thead><tr><th>Type</th><th>Output</th><th>Researcher / applicant</th><th>Date</th><th>Register</th></tr></thead>
          <tbody>
            <?php foreach ($recentOutputs as $output): ?>
              <tr>
                <td><span class="io-output-type"><?= implementationOutputsEscape((string) $output['type_label']) ?></span></td>
                <td><?= implementationOutputsEscape((string) $output['record_title']) ?></td>
                <td><?= implementationOutputsEscape((string) $output['person_name']) ?></td>
                <td><?= implementationOutputsEscape(implementationOutputsDate($output['event_date'])) ?></td>
                <td><a class="io-title" href="<?= implementationOutputsEscape((string) $output['link_url']) ?>">Open hub</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
