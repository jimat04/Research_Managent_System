<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/publication-classification.php';

function publicRecordEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$sourceType = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$sourceColumns = [
    'student_project' => 'project_id',
    'faculty_publication' => 'publication_id',
    'faculty_research_record' => 'faculty_research_record_id',
];
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!isset($sourceColumns[$sourceType]) || $recordId <= 0) {
    http_response_code(404);
    exit('Research record not found.');
}

if (!isLoggedIn()) {
    $loginQuery = http_build_query([
        'continue' => 'public-record',
        'record_type' => $sourceType,
        'record_id' => $recordId,
    ]);
    header('Location: ' . SITE_URL . 'public/login.php?' . $loginQuery);
    exit;
}

$sourceColumn = $sourceColumns[$sourceType];
$releaseStmt = $conn->prepare("SELECT * FROM public_research_releases WHERE source_type = ? AND `{$sourceColumn}` = ? AND release_status = 'published' LIMIT 1");
$releaseStmt->bind_param('si', $sourceType, $recordId);
$releaseStmt->execute();
$release = $releaseStmt->get_result()->fetch_assoc() ?: null;
$releaseStmt->close();
if (!$release) {
    http_response_code(404);
    exit('Research record not found.');
}

$record = null;
$authors = [];
$files = [];
$typeLabel = '';
$authorHeading = 'Authors';
$recordFields = [];

if ($sourceType === 'student_project') {
    $stmt = $conn->prepare("SELECT rp.project_id, rp.title, rp.abstract, rp.updated_at, rc.category_name, ay.label AS academic_year FROM research_projects rp LEFT JOIN research_categories rc ON rc.category_id = rp.category_id LEFT JOIN academic_years ay ON ay.ay_id = rp.ay_id WHERE rp.project_id = ? AND rp.status IN ('completed','archived') AND rp.deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $typeLabel = 'Student research project';
    $hasSnapshotTable = $conn->query("SHOW TABLES LIKE 'research_author_snapshots'");
    $hasSnapshots = $hasSnapshotTable instanceof mysqli_result && $hasSnapshotTable->num_rows > 0;
    if ($hasSnapshotTable instanceof mysqli_result) $hasSnapshotTable->close();
    if ($record && $hasSnapshots) {
        $authorStmt = $conn->prepare('SELECT author_name, program, author_role FROM research_author_snapshots WHERE project_id = ? ORDER BY display_order, author_snapshot_id');
        $authorStmt->bind_param('i', $recordId);
        $authorStmt->execute();
        $authors = $authorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $authorStmt->close();
    }
    if ($record && !$authors) {
        $authorStmt = $conn->prepare("SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name, u.program, pm.role AS author_role FROM project_members pm JOIN users u ON u.user_id = pm.user_id AND u.role = 'student' WHERE pm.project_id = ? ORDER BY CASE WHEN pm.role = 'lead' THEN 0 ELSE 1 END, pm.user_id");
        $authorStmt->bind_param('i', $recordId);
        $authorStmt->execute();
        $authors = $authorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $authorStmt->close();
        if (!$authors) {
            $authorStmt = $conn->prepare("SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name, u.program, 'lead' AS author_role FROM research_projects rp JOIN users u ON u.user_id = rp.created_by AND u.role = 'student' WHERE rp.project_id = ? LIMIT 1");
            $authorStmt->bind_param('i', $recordId);
            $authorStmt->execute();
            $authors = $authorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $authorStmt->close();
        }
    }
    $versionId = (int) ($release['public_manuscript_version_id'] ?? 0);
    if ($record && $versionId > 0) {
        $fileStmt = $conn->prepare("SELECT DISTINCT up.upload_id, up.original_name, up.file_size, up.mime_type FROM research_submission_versions v JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id JOIN uploads up ON up.upload_id = vu.upload_id AND up.deleted_at IS NULL WHERE v.version_id = ? AND v.project_id = ? AND v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL AND v.status = 'approved' ORDER BY up.upload_id");
        $fileStmt->bind_param('ii', $versionId, $recordId);
        $fileStmt->execute();
        $files = $fileStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fileStmt->close();
    }
    $recordFields = [
        'Research category' => $record['category_name'] ?? 'Not recorded',
        'Academic year' => $record['academic_year'] ?? 'Not recorded',
    ];
} elseif ($sourceType === 'faculty_publication') {
    $stmt = $conn->prepare("SELECT p.* FROM publications p WHERE p.publication_id = ? AND p.researcher_category = 'faculty' AND (p.status = 'published' OR p.archival_status = 'archived') AND p.deactivated_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $typeLabel = 'Faculty publication';
    if ($record) {
        $authors = array_map(static fn($name) => ['author_name' => $name, 'author_role' => 'member'], publicationAuthors($record));
        $recordFields = [
            'Publication type' => publicationTypeLabel($record),
            'Journal or publisher' => $record['journal_publisher'] ?? '',
            'Publication date' => $record['publication_date'] ?? '',
            'DOI / identifier' => $record['doi_identifier'] ?? '',
        ];
        $paperReleased = (int) ($release['publication_paper_released'] ?? 0) === 1;
        if ($paperReleased && !empty($release['publication_paper_path'])) {
            $files[] = ['download_url' => SITE_URL . 'pages/shared/download-research-document.php?type=publication&id=' . $recordId . '&document=manuscript&mode=viewer', 'original_name' => $release['publication_paper_name'] ?: 'View published paper'];
        }
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM faculty_research_records WHERE research_record_id = ? AND status = 'archived' AND deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $typeLabel = 'Independent faculty research record';
    $authorHeading = 'Recorded authorship';
    if ($record) {
        $authorStmt = $conn->prepare('SELECT full_name AS author_name, position, affiliation, author_role, author_type FROM faculty_research_record_authors WHERE research_record_id = ? ORDER BY display_order, record_author_id');
        $authorStmt->bind_param('i', $recordId);
        $authorStmt->execute();
        $authors = $authorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $authorStmt->close();
        $recordFields = [
            'Type of scholarly work' => $record['work_type_label'],
            'Date started' => $record['date_started'] ?? '',
            'Date completed' => $record['date_completed'] ?? '',
            'Journal title' => $record['journal_title'] ?? '',
            'Journal publisher' => $record['journal_publisher'] ?? '',
            'Conference' => $record['conference_title'] ?? '',
            'Conference host' => $record['conference_host'] ?? '',
            'Conference venue' => $record['conference_venue'] ?? '',
        ];
    }
}

if (!$record) {
    http_response_code(404);
    exit('Research record not found.');
}
$recordTitle = (string) ($record['title'] ?? $record['research_title'] ?? 'Research record');
$abstract = (string) ($record['abstract'] ?? $record['abstract_text'] ?? '');
$safeRecordFields = array_filter($recordFields, static fn($value) => trim((string) $value) !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo publicRecordEscape($recordTitle); ?> | Research Archive</title>
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-theme.css'); ?>">
    <link rel="stylesheet" href="assets/css/public-pages-v2.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-pages-v2.css'); ?>">
    <link rel="stylesheet" href="assets/css/public-header.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-header.css'); ?>">
    <link rel="stylesheet" href="assets/css/public-footer.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-footer.css'); ?>">
    <style>
      .public-record-page{max-width:1040px;margin:0 auto;padding:44px 24px 72px}.public-record-back{display:inline-flex;margin-bottom:22px;color:#5b1ebc;text-decoration:none;font-weight:700}.public-record-card{padding:clamp(24px,5vw,52px);border:1px solid #e5e7eb;border-radius:22px;background:#fff;box-shadow:0 18px 48px rgba(22,30,48,.07)}.public-record-eyebrow{margin:0 0 12px;color:#5b1ebc;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.public-record-card h1{margin:0;color:#111827;font-size:clamp(30px,5vw,48px);line-height:1.12;letter-spacing:-.04em}.public-record-meta{display:flex;flex-wrap:wrap;gap:9px;margin:20px 0 0}.public-record-meta span{padding:7px 11px;border:1px solid #e5e7eb;border-radius:999px;color:#475569;font-size:12px}.public-record-section{margin-top:34px;padding-top:24px;border-top:1px solid #e5e7eb}.public-record-section h2{margin:0 0 14px;color:#111827;font-size:19px}.public-record-section p{color:#475569;line-height:1.75}.public-record-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0}.public-record-fields div{padding:14px 16px;border-radius:12px;background:#f8fafc}.public-record-fields dt{color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.public-record-fields dd{margin:6px 0 0;color:#111827;line-height:1.5;overflow-wrap:anywhere}.public-record-authors{display:grid;gap:10px;margin:0;padding:0;list-style:none;counter-reset:authors}.public-record-authors li{display:grid;grid-template-columns:32px minmax(0,1fr);gap:12px;padding:13px 15px;border:1px solid #e5e7eb;border-radius:12px}.public-record-author-index{grid-row:span 3;color:#9ca3af;font-size:12px;font-weight:800}.public-record-author-name{color:#111827;font-weight:750}.public-record-author-meta{color:#64748b;font-size:12px;line-height:1.5}.public-record-files{display:grid;gap:10px}.public-record-file{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;color:#111827;text-decoration:none}.public-record-file:hover{border-color:#5b1ebc;background:#faf8ff}.public-record-empty{color:#64748b}.public-record-file:focus-visible,.public-record-back:focus-visible{outline:2px solid #5b1ebc;outline-offset:3px}@media(max-width:640px){.public-record-page{padding:25px 14px 46px}.public-record-fields{grid-template-columns:1fr}.public-record-file{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body class="public-v2">
    <?php $publicHeaderActivePage = 'archive'; require __DIR__ . '/../includes/public-header.php'; ?>
    <main class="public-record-page">
      <a class="public-record-back" href="<?php echo publicRecordEscape(SITE_URL . 'public/research-archive.php'); ?>">← Back to Research Archive</a>
      <article class="public-record-card">
        <p class="public-record-eyebrow"><?php echo publicRecordEscape($typeLabel); ?> · Public record</p>
        <h1><?php echo publicRecordEscape($recordTitle); ?></h1>
        <?php if ($safeRecordFields): ?><dl class="public-record-fields public-record-section"><?php foreach ($safeRecordFields as $label => $value): ?><div><dt><?php echo publicRecordEscape($label); ?></dt><dd><?php echo publicRecordEscape($value); ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
        <?php if ($authors): ?><section class="public-record-section"><h2><?php echo publicRecordEscape($authorHeading); ?></h2><ol class="public-record-authors"><?php foreach ($authors as $index => $author): ?><li><span class="public-record-author-index" aria-hidden="true"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span><span class="public-record-author-name"><?php echo publicRecordEscape($author['author_name'] ?? 'Author'); ?><?php if (($author['author_role'] ?? '') === 'lead'): ?> · Lead author<?php endif; ?></span><?php if (!empty($author['program'])): ?><span class="public-record-author-meta"><?php echo publicRecordEscape($author['program']); ?></span><?php endif; ?><?php if (!empty($author['position']) || !empty($author['affiliation'])): ?><span class="public-record-author-meta"><?php echo publicRecordEscape(trim(($author['position'] ?? '') . (!empty($author['position']) && !empty($author['affiliation']) ? ' · ' : '') . ($author['affiliation'] ?? ''))); ?></span><?php endif; ?></li><?php endforeach; ?></ol></section><?php endif; ?>
        <?php if ($abstract !== ''): ?><section class="public-record-section"><h2>Abstract</h2><p><?php echo nl2br(publicRecordEscape($abstract)); ?></p></section><?php endif; ?>
        <?php if ($files): ?><section class="public-record-section"><h2>Released research file<?php echo count($files) === 1 ? '' : 's'; ?></h2><div class="public-record-files"><?php foreach ($files as $file): ?><?php $fileUrl = $file['download_url'] ?? (SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $file['upload_id'] . '&mode=viewer'); ?><a class="public-record-file" href="<?php echo publicRecordEscape($fileUrl); ?>"><span><?php echo publicRecordEscape($file['original_name'] ?? 'View released file'); ?></span><span aria-hidden="true">Open ↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
      </article>
    </main>
    <?php require __DIR__ . '/../includes/public-footer.php'; ?>
    <script src="assets/js/public-pages-v2.js?v=<?php echo filemtime(__DIR__ . '/assets/js/public-pages-v2.js'); ?>" defer></script>
    <script src="../js/public-motion.js?v=<?php echo filemtime(__DIR__ . '/../js/public-motion.js'); ?>" defer></script>
</body>
</html>
