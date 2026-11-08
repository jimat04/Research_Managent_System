<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/academic-years.php';
require_once __DIR__ . '/../includes/publication-classification.php';

function archive_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function archive_fetch_all($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

$search = trim($_GET['search'] ?? '');
$selected_category = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
$selected_ay = filter_input(INPUT_GET, 'ay_id', FILTER_VALIDATE_INT);
$selected_program = trim((string) ($_GET['program'] ?? ''));
$selected_type = is_string($_GET['type'] ?? null) ? $_GET['type'] : 'all';
if (!in_array($selected_type, ['all', 'student_project', 'faculty_publication', 'faculty_research_record'], true)) {
    $selected_type = 'all';
}
$selected_category = $selected_category ?: 0;
$selected_ay = array_key_exists('academic_year', $_GET) ? 0 : ($selected_ay ?: 0);
$selected_academic_year = is_string($_GET['academic_year'] ?? null) ? trim($_GET['academic_year']) : '';
// Preserve bookmarked semester-ID links, now matching the whole academic year.
if ($selected_ay > 0) {
    $legacy_year_stmt = $conn->prepare('SELECT label FROM academic_years WHERE ay_id = ?');
    $legacy_year_stmt->bind_param('i', $selected_ay);
    $legacy_year_stmt->execute();
    $legacy_year = $legacy_year_stmt->get_result()->fetch_assoc();
    $legacy_year_stmt->close();
    $selected_academic_year = (string) ($legacy_year['label'] ?? '');
}
if (function_exists('mb_substr')) {
    $selected_program = mb_substr($selected_program, 0, 120);
} else {
    $selected_program = substr($selected_program, 0, 120);
}

$deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$deleted_column_stmt->execute();
$has_deleted_at = $deleted_column_stmt->get_result()->num_rows > 0;
$deleted_column_stmt->close();
$deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

$snapshot_table_result = $conn->query("SHOW TABLES LIKE 'research_author_snapshots'");
$has_author_snapshots = $snapshot_table_result instanceof mysqli_result && $snapshot_table_result->num_rows > 0;
if ($snapshot_table_result instanceof mysqli_result) $snapshot_table_result->close();

$count_stmt = $conn->prepare(
    "SELECT
        (SELECT COUNT(*) FROM research_projects rp
          JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
         WHERE rp.status IN ('completed','archived')" . ($has_deleted_at ? ' AND rp.deleted_at IS NULL' : '') . ") +
        (SELECT COUNT(*) FROM publications p
          JOIN public_research_releases pr ON pr.publication_id = p.publication_id AND pr.source_type = 'faculty_publication' AND pr.release_status = 'published'
         WHERE p.researcher_category = 'faculty' AND (p.status = 'published' OR p.archival_status = 'archived') AND p.deactivated_at IS NULL) +
        (SELECT COUNT(*) FROM faculty_research_records fr
          JOIN public_research_releases pr ON pr.faculty_research_record_id = fr.research_record_id AND pr.source_type = 'faculty_research_record' AND pr.release_status = 'published'
         WHERE fr.status = 'archived' AND fr.deleted_at IS NULL)"
);
$count_stmt->execute();
$count_stmt->bind_result($archived_count);
$count_stmt->fetch();
$count_stmt->close();

$category_stmt = $conn->prepare(
    "SELECT DISTINCT rc.category_id, rc.category_name
       FROM research_categories rc
       JOIN research_projects rp ON rp.category_id = rc.category_id
      JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
      WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
      ORDER BY rc.category_name, rc.category_id"
);
$categories = archive_fetch_all($category_stmt);
$category_stmt->close();

$academic_year_result = $conn->query(
    "SELECT DISTINCT ay.label
       FROM academic_years ay
       JOIN research_projects rp ON rp.ay_id = ay.ay_id
       JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
      WHERE rp.status IN ('completed','archived'){$deleted_filter}
      ORDER BY ay.label DESC"
);
$academic_years = [];
if ($academic_year_result) {
    while ($academic_year_row = $academic_year_result->fetch_assoc()) $academic_years[] = $academic_year_row['label'];
    $academic_year_result->close();
}

if ($has_author_snapshots) {
    $program_sql = "
        SELECT DISTINCT program
        FROM (
            SELECT ras.program
              FROM research_author_snapshots ras
              JOIN research_projects rp ON rp.project_id = ras.project_id
              JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
             WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
               AND ras.program IS NOT NULL AND ras.program != ''
            UNION
            SELECT u.program
              FROM project_members pm
              JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
              JOIN research_projects rp ON rp.project_id = pm.project_id
              JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
             WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
               AND u.program IS NOT NULL AND u.program != ''
               AND NOT EXISTS (SELECT 1 FROM research_author_snapshots ras WHERE ras.project_id = rp.project_id)
            UNION
            SELECT u.program
              FROM research_projects rp
              JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
              JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
             WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
               AND u.program IS NOT NULL AND u.program != ''
               AND NOT EXISTS (SELECT 1 FROM research_author_snapshots ras WHERE ras.project_id = rp.project_id)
        ) archive_programs
        ORDER BY program
    ";
} else {
    $program_sql = "
        SELECT DISTINCT program
        FROM (
            SELECT u.program
              FROM project_members pm
              JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
              JOIN research_projects rp ON rp.project_id = pm.project_id
              JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
             WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
               AND u.program IS NOT NULL AND u.program != ''
            UNION
            SELECT u.program
              FROM research_projects rp
              JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
              JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
             WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
               AND u.program IS NOT NULL AND u.program != ''
        ) archive_programs
        ORDER BY program
    ";
}
$programs = [];
$program_result = $conn->query($program_sql);
if ($program_result) {
    while ($program_row = $program_result->fetch_assoc()) $programs[] = $program_row['program'];
    $program_result->close();
}

$query = "
    SELECT
        rp.project_id,
        rp.title,
        rp.abstract,
        rp.updated_at,
        pr.public_manuscript_version_id,
        rc.category_name,
        ay.label AS academic_year,
        lead_user.first_name AS lead_first_name,
        lead_user.last_name AS lead_last_name,
        lead_user.program AS lead_program
    FROM research_projects rp
    JOIN public_research_releases pr ON pr.project_id = rp.project_id AND pr.source_type = 'student_project' AND pr.release_status = 'published'
    LEFT JOIN research_categories rc ON rc.category_id = rp.category_id
    LEFT JOIN academic_years ay ON ay.ay_id = rp.ay_id
    LEFT JOIN (
        SELECT
            project_id,
            MAX(CASE WHEN role = 'lead' THEN user_id END) AS lead_user_id
        FROM project_members
        GROUP BY project_id
    ) pm ON pm.project_id = rp.project_id
    LEFT JOIN users lead_user ON lead_user.user_id = COALESCE(pm.lead_user_id, rp.created_by)
    WHERE rp.status IN ('completed', 'archived')
            $deleted_filter
";

$parameters = [];
$types = '';

if ($selected_type !== 'all' && $selected_type !== 'student_project') {
    $query .= ' AND 1 = 0';
}

if ($search !== '') {
    $query .= ' AND (rp.title LIKE ? OR rp.abstract LIKE ?)';
    $search_term = '%' . $search . '%';
    $parameters[] = $search_term;
    $parameters[] = $search_term;
    $types .= 'ss';
}

if ($selected_category > 0) {
    $query .= ' AND rp.category_id = ?';
    $parameters[] = $selected_category;
    $types .= 'i';
}

if ($selected_academic_year !== '') {
    $query .= ' AND ay.label = ?';
    $parameters[] = $selected_academic_year;
    $types .= 's';
} elseif ($selected_ay > 0) {
    // An unknown legacy ID must not silently broaden the archive results.
    $query .= ' AND rp.ay_id = ?';
    $parameters[] = $selected_ay;
    $types .= 'i';
}

if ($selected_program !== '') {
    if ($has_author_snapshots) {
        $query .= " AND (
            EXISTS (
                SELECT 1 FROM research_author_snapshots ras
                 WHERE ras.project_id = rp.project_id AND ras.program = ?
            )
            OR (
                NOT EXISTS (SELECT 1 FROM research_author_snapshots ras WHERE ras.project_id = rp.project_id)
                AND (
                    EXISTS (
                        SELECT 1 FROM project_members pm_program
                        JOIN users program_user ON program_user.user_id = pm_program.user_id AND program_user.role = 'student'
                        WHERE pm_program.project_id = rp.project_id AND program_user.program = ?
                    )
                    OR EXISTS (
                        SELECT 1 FROM users owner_program
                        WHERE owner_program.user_id = rp.created_by AND owner_program.role = 'student' AND owner_program.program = ?
                    )
                )
            )
        )";
        array_push($parameters, $selected_program, $selected_program, $selected_program);
        $types .= 'sss';
    } else {
        $query .= " AND (
            EXISTS (
                SELECT 1 FROM project_members pm_program
                JOIN users program_user ON program_user.user_id = pm_program.user_id AND program_user.role = 'student'
                WHERE pm_program.project_id = rp.project_id AND program_user.program = ?
            )
            OR EXISTS (
                SELECT 1 FROM users owner_program
                WHERE owner_program.user_id = rp.created_by AND owner_program.role = 'student' AND owner_program.program = ?
            )
        )";
        array_push($parameters, $selected_program, $selected_program);
        $types .= 'ss';
    }
}

$query .= ' ORDER BY rp.updated_at DESC, rp.title ASC';

$archive_stmt = $conn->prepare($query);
if (!empty($parameters)) {
    $archive_stmt->bind_param($types, ...$parameters);
}
$projects = archive_fetch_all($archive_stmt);
$archive_stmt->close();

$authors_by_project = [];
$project_ids = array_map(static fn($project) => (int) $project['project_id'], $projects);
if ($project_ids) {
    $project_id_list = implode(',', $project_ids);

    if ($has_author_snapshots) {
        $snapshot_result = $conn->query(
            "SELECT project_id, user_id, author_name, program, author_role
               FROM research_author_snapshots
              WHERE project_id IN ({$project_id_list})
              ORDER BY project_id, display_order, author_snapshot_id"
        );
        if ($snapshot_result) {
            while ($author = $snapshot_result->fetch_assoc()) {
                $authors_by_project[(int) $author['project_id']][] = $author;
            }
            $snapshot_result->close();
        }
    }

    $fallback_ids = array_values(array_filter(
        $project_ids,
        static fn($project_id) => empty($authors_by_project[$project_id])
    ));
    if ($fallback_ids) {
        $fallback_id_list = implode(',', $fallback_ids);
        $live_author_sql = "
            SELECT project_id, user_id, author_name, program, author_role
            FROM (
                SELECT pm.project_id, u.user_id,
                       TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                       NULLIF(TRIM(u.program), '') AS program,
                       pm.role AS author_role,
                       CASE WHEN pm.role = 'lead' THEN 0 ELSE 1 END AS display_order
                  FROM project_members pm
                  JOIN users u ON u.user_id = pm.user_id AND u.role = 'student'
                 WHERE pm.project_id IN ({$fallback_id_list})
                UNION ALL
                SELECT rp.project_id, u.user_id,
                       TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                       NULLIF(TRIM(u.program), '') AS program,
                       'lead' AS author_role,
                       0 AS display_order
                  FROM research_projects rp
                  JOIN users u ON u.user_id = rp.created_by AND u.role = 'student'
                 WHERE rp.project_id IN ({$fallback_id_list})
                   AND NOT EXISTS (
                       SELECT 1 FROM project_members pm
                        WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id
                   )
            ) live_authors
            ORDER BY project_id, display_order, user_id
        ";
        $live_author_result = $conn->query($live_author_sql);
        if ($live_author_result) {
            while ($author = $live_author_result->fetch_assoc()) {
                $authors_by_project[(int) $author['project_id']][] = $author;
            }
            $live_author_result->close();
        }
    }
}

$student_specific_filters = $selected_category > 0 || $selected_academic_year !== '' || $selected_ay > 0 || $selected_program !== '';
$includeFacultyRecords = !$student_specific_filters;
$archive_records = [];
foreach ($projects as $project) {
    $archive_records[] = [
        'source_type' => 'student_project',
        'source_id' => (int) $project['project_id'],
        'title' => $project['title'],
        'abstract' => $project['abstract'],
        'updated_at' => $project['updated_at'],
        'category_name' => $project['category_name'] ?: 'Student research',
        'academic_year' => $project['academic_year'] ?: 'Academic year unavailable',
        'authors' => $authors_by_project[(int) $project['project_id']] ?? [],
        'public_file_available' => (int) ($project['public_manuscript_version_id'] ?? 0) > 0,
    ];
}

if ($includeFacultyRecords && in_array($selected_type, ['all', 'faculty_publication'], true)) {
    $facultyPublicationSql = "SELECT p.publication_id, p.research_title, p.abstract_text, p.publication_type, p.publication_type_name, p.publication_date, p.updated_at, p.authors_json, p.researcher_name, r.publication_paper_released FROM publications p JOIN public_research_releases r ON r.publication_id = p.publication_id AND r.source_type = 'faculty_publication' AND r.release_status = 'published' WHERE p.researcher_category = 'faculty' AND (p.status = 'published' OR p.archival_status = 'archived') AND p.deactivated_at IS NULL";
    $facultyPublicationParams = [];
    $facultyPublicationTypes = '';
    if ($search !== '') {
        $facultyPublicationSql .= ' AND (p.research_title LIKE ? OR p.abstract_text LIKE ?)';
        $likeSearch = '%' . $search . '%';
        array_push($facultyPublicationParams, $likeSearch, $likeSearch);
        $facultyPublicationTypes .= 'ss';
    }
    if ($selected_type === 'faculty_publication') {
        // Type filter is handled by this source-specific query.
    }
    $facultyPublicationSql .= ' ORDER BY p.publication_date DESC, p.publication_id DESC';
    $facultyPublicationStmt = $conn->prepare($facultyPublicationSql);
    if ($facultyPublicationParams) $facultyPublicationStmt->bind_param($facultyPublicationTypes, ...$facultyPublicationParams);
    $facultyPublicationStmt->execute();
    $facultyPublications = $facultyPublicationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $facultyPublicationStmt->close();
    foreach ($facultyPublications as $publication) {
        $publicationAuthors = array_map(static fn($name) => ['author_name' => $name, 'author_role' => 'member'], publicationAuthors($publication));
        $archive_records[] = [
            'source_type' => 'faculty_publication',
            'source_id' => (int) $publication['publication_id'],
            'title' => $publication['research_title'],
            'abstract' => $publication['abstract_text'] ?? '',
            'updated_at' => $publication['publication_date'] ?: $publication['updated_at'],
            'category_name' => publicationTypeLabel($publication),
            'academic_year' => $publication['publication_date'] ? date('Y', strtotime($publication['publication_date'])) : 'Publication year unavailable',
            'authors' => $publicationAuthors,
            'public_file_available' => (int) $publication['publication_paper_released'] === 1,
        ];
    }
}

if ($includeFacultyRecords && in_array($selected_type, ['all', 'faculty_research_record'], true)) {
    $facultyResearchSql = "SELECT fr.research_record_id, fr.title, fr.work_type_label, fr.date_completed, fr.updated_at FROM faculty_research_records fr JOIN public_research_releases r ON r.faculty_research_record_id = fr.research_record_id AND r.source_type = 'faculty_research_record' AND r.release_status = 'published' WHERE fr.status = 'archived' AND fr.deleted_at IS NULL";
    $facultyResearchParams = [];
    $facultyResearchTypes = '';
    if ($search !== '') {
        $facultyResearchSql .= ' AND fr.title LIKE ?';
        $facultyResearchParams[] = '%' . $search . '%';
        $facultyResearchTypes = 's';
    }
    $facultyResearchSql .= ' ORDER BY fr.date_completed DESC, fr.updated_at DESC, fr.research_record_id DESC';
    $facultyResearchStmt = $conn->prepare($facultyResearchSql);
    if ($facultyResearchParams) $facultyResearchStmt->bind_param($facultyResearchTypes, ...$facultyResearchParams);
    $facultyResearchStmt->execute();
    $facultyResearchRecords = $facultyResearchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $facultyResearchStmt->close();
    $facultyResearchAuthors = [];
    $facultyResearchIds = array_map(static fn($record) => (int) $record['research_record_id'], $facultyResearchRecords);
    if ($facultyResearchIds) {
        $facultyResearchIdList = implode(',', $facultyResearchIds);
        $facultyResearchAuthorResult = $conn->query("SELECT research_record_id, full_name, author_role FROM faculty_research_record_authors WHERE research_record_id IN ({$facultyResearchIdList}) ORDER BY research_record_id, display_order, record_author_id");
        if ($facultyResearchAuthorResult) {
            while ($author = $facultyResearchAuthorResult->fetch_assoc()) {
                $facultyResearchAuthors[(int) $author['research_record_id']][] = [
                    'author_name' => $author['full_name'],
                    'author_role' => $author['author_role'] === 'lead' ? 'lead' : 'member',
                ];
            }
            $facultyResearchAuthorResult->close();
        }
    }
    foreach ($facultyResearchRecords as $facultyRecord) {
        $archive_records[] = [
            'source_type' => 'faculty_research_record',
            'source_id' => (int) $facultyRecord['research_record_id'],
            'title' => $facultyRecord['title'],
            'abstract' => $facultyRecord['work_type_label'] . ($facultyRecord['date_completed'] ? ' · Completed ' . $facultyRecord['date_completed'] : ''),
            'updated_at' => $facultyRecord['date_completed'] ?: $facultyRecord['updated_at'],
            'category_name' => $facultyRecord['work_type_label'] ?: 'Faculty research',
            'academic_year' => $facultyRecord['date_completed'] ? substr((string) $facultyRecord['date_completed'], 0, 4) : 'Completion year unavailable',
            'authors' => $facultyResearchAuthors[(int) $facultyRecord['research_record_id']] ?? [],
            'public_file_available' => false,
        ];
    }
}

usort($archive_records, static function (array $left, array $right): int {
    $dateOrder = (strtotime((string) ($right['updated_at'] ?? '')) ?: 0) <=> (strtotime((string) ($left['updated_at'] ?? '')) ?: 0);
    if ($dateOrder !== 0) return $dateOrder;
    $titleOrder = strcasecmp((string) $left['title'], (string) $right['title']);
    if ($titleOrder !== 0) return $titleOrder;
    $typeOrder = strcmp((string) $left['source_type'], (string) $right['source_type']);
    return $typeOrder !== 0 ? $typeOrder : ((int) $right['source_id'] <=> (int) $left['source_id']);
});
$archive_total_records = count($archive_records);
$archive_page_size = 10;
$requested_page = $_GET['page'] ?? '1';
$validated_page = is_scalar($requested_page)
    ? filter_var((string) $requested_page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$archive_page = $validated_page === false ? 1 : (int) $validated_page;
$archive_total_pages = max(1, (int) ceil($archive_total_records / $archive_page_size));
$archive_page = min($archive_page, $archive_total_pages);
$archive_first_record = $archive_total_records > 0 ? (($archive_page - 1) * $archive_page_size) + 1 : 0;
$archive_last_record = min($archive_page * $archive_page_size, $archive_total_records);
$archive_records = array_slice($archive_records, ($archive_page - 1) * $archive_page_size, $archive_page_size);
$archived_count = $archive_total_records;
$archive_pagination_url = static function (int $page) use ($search, $selected_category, $selected_ay, $selected_academic_year, $selected_program, $selected_type): string {
    $query = [
        'search' => $search,
        'category_id' => $selected_category,
        'academic_year' => $selected_academic_year,
        'program' => $selected_program,
        'type' => $selected_type,
        'page' => $page,
    ];
    if ($selected_ay > 0 && $selected_academic_year === '') $query['ay_id'] = $selected_ay;
    return SITE_URL . 'public/research-archive.php?' . http_build_query($query);
};

$is_logged_in = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Archive | <?php echo htmlspecialchars(rmsSetting('site_name'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-theme.css'); ?>">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* @rms-ui: Keep the archive scannable while reusing the shared public palette. */
        .archive-hero { min-height: 56vh; }
        .archive-hero .hero-container { grid-template-columns: 1.2fr 0.8fr; }
        .archive-hero .hero-content p { max-width: 620px; }
        .archive-hero .hero-actions { margin-bottom: 16px; }
        .archive-stat { display: flex; width: fit-content; gap: 12px; align-items: center; color: #D0D3E8; font-size: 0.9rem; line-height: 1.2; }
        .archive-stat strong { display: block; color: white; font-family: 'Poppins', sans-serif; font-size: 1.5rem; line-height: 1; }
        .archive-visual-icon { font-size: 7rem; filter: drop-shadow(0 12px 30px rgba(91, 30, 188, 0.35)); }
        .archive-section { background: linear-gradient(180deg, var(--public-surface) 0%, var(--public-bg) 100%); }
        .archive-filter { max-width: 1200px; margin: 0 auto 32px; }
        .archive-filter .card-body { display: grid; grid-template-columns: minmax(220px, 2fr) repeat(4, minmax(145px, 1fr)) auto auto; gap: 16px; align-items: end; }
        .archive-filter .form-group { margin-bottom: 0; }
        .archive-filter .form-label { color: var(--public-text); }
        .archive-filter .form-control { background: var(--public-surface); border-color: var(--public-border); color: var(--public-text); }
        .archive-filter .form-control::placeholder { color: var(--public-muted); }
        .archive-filter select.form-control option { color: var(--public-text); }
        .archive-reset { color: var(--rms-public-link); font-size: 0.85rem; font-weight: 600; padding-bottom: 12px; }
        .archive-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; align-items: start; }
        .archive-card { display: flex; flex-direction: column; min-width: 0; height: 100%; transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease; }
        .archive-card:hover { transform: translateY(-3px); border-color: rgba(91, 30, 188, .28); box-shadow: 0 18px 42px rgba(45, 20, 84, .12); }
        .archive-card .card-body { position: relative; display: flex; flex-direction: column; flex: 1; cursor: pointer; outline: none; color: inherit; text-decoration: none; }
        .archive-card .card-body:focus-visible { box-shadow: inset 0 0 0 3px rgba(91, 30, 188, .22); }
        .archive-card-title { color: var(--public-link); font-size: 1.1rem; line-height: 1.35; margin-bottom: 12px; }
        .archive-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
        .archive-badge { background: var(--rms-public-accent-wash); color: var(--rms-public-link); border-radius: 50px; padding: 4px 10px; font-size: 0.72rem; font-weight: 600; }
        .archive-year { color: var(--public-muted); font-size: 0.78rem; }
        .archive-authors { display: grid; gap: 6px; margin-bottom: 14px; }
        .archive-author { color: var(--public-body); font-size: 0.85rem; margin: 0; }
        .archive-program { display: inline-block; margin-left: 6px; color: var(--public-link); font-size: 0.75rem; font-weight: 700; }
        .archive-abstract { color: var(--public-body); font-size: 0.9rem; line-height: 1.65; margin-bottom: 20px; }
        .archive-published { display: flex; align-items: center; justify-content: space-between; gap: 14px; color: var(--public-muted); font-size: 0.78rem; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--public-border); }
        .archive-expand-label { color: var(--public-link); font-weight: 700; white-space: nowrap; }
        .archive-expand-label::after { content: '\2192'; display: inline-block; margin-left: 7px; }
        .archive-access-dialog { position: fixed; inset: 0; width: min(480px, calc(100vw - 32px)); max-width: calc(100vw - 32px); max-height: calc(100dvh - 32px); overflow-y: auto; margin: auto; padding: 36px; border: 1px solid var(--public-border); border-radius: 20px; background: var(--public-surface); color: var(--public-text); box-shadow: 0 28px 80px rgba(15, 23, 42, .28); }
        .archive-access-dialog::before { content: ''; position: absolute; inset: 0 0 auto; height: 4px; background: var(--rms-public-primary-gradient); }
        .archive-access-dialog::backdrop { background: rgba(15, 23, 42, .62); backdrop-filter: blur(3px); }
        .archive-dialog-close { position: absolute; top: 16px; right: 16px; display: grid; width: 40px; height: 40px; place-items: center; border: 1px solid var(--public-border); border-radius: 50%; background: var(--public-surface); color: var(--public-body); font-size: 1.4rem; line-height: 1; cursor: pointer; transition: background .2s, color .2s, border-color .2s; }
        .archive-dialog-close:hover { border-color: var(--public-link); background: var(--rms-public-accent-wash); color: var(--public-link); }
        .archive-dialog-heading { display: flex; align-items: center; gap: 14px; margin: 0 36px 22px 0; }
        .archive-dialog-icon { display: grid; flex: 0 0 52px; width: 52px; height: 52px; place-items: center; border: 1px solid rgba(91, 30, 188, .14); border-radius: 15px; background: var(--rms-public-accent-wash); color: var(--public-link); }
        .archive-dialog-icon svg { width: 25px; height: 25px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.7; }
        .archive-access-dialog h2 { margin: 0; color: var(--public-text); font-size: 1.5rem; letter-spacing: -.035em; line-height: 1.22; text-wrap: balance; }
        .archive-access-dialog p { margin: 0; color: var(--public-body); line-height: 1.7; }
        .archive-access-dialog .archive-dialog-eyebrow { margin: 0 0 6px; color: var(--public-link); font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        .archive-dialog-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 28px; }
        .archive-dialog-actions .btn { min-height: 48px; padding: 0 16px; justify-content: center; }
        body.public-v2 .archive-dialog-actions .btn-primary { color: var(--rms-public-primary-contrast); background: var(--rms-public-primary-gradient); border: 1px solid transparent; box-shadow: 0 8px 20px rgba(91, 30, 188, .24); transition: transform .2s ease, box-shadow .2s ease, filter .2s ease; }
        body.public-v2 .archive-dialog-actions .btn-primary:hover { color: var(--rms-public-primary-contrast); background: var(--rms-public-primary-gradient); transform: translateY(-2px); box-shadow: 0 12px 26px rgba(91, 30, 188, .32); filter: saturate(1.08); }
        body.public-v2 .archive-dialog-actions .btn-primary:active { transform: translateY(0) scale(.98); }
        .archive-access-dialog :focus-visible { outline: 3px solid var(--rms-public-link); outline-offset: 3px; }
        .archive-empty { max-width: 700px; margin: 0 auto; text-align: center; padding: 56px 32px; }
        .archive-empty-icon { display: block; font-size: 2.5rem; margin-bottom: 12px; }
        .archive-pagination-wrap { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; max-width: 1200px; margin: 28px auto 0; }
        .archive-pagination-summary { margin: 0; color: var(--public-muted); font-size: .9rem; }
        .archive-pagination { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
        .archive-pagination a, .archive-pagination span { display: inline-flex; min-width: 40px; min-height: 40px; box-sizing: border-box; align-items: center; justify-content: center; padding: 0 12px; border: 1px solid var(--public-border); border-radius: 9px; background: var(--public-surface); color: var(--rms-public-link); font-size: .875rem; font-weight: 650; text-decoration: none; }
        .archive-pagination a:hover { border-color: var(--rms-public-link); background: var(--public-bg); }
        .archive-pagination [aria-current="page"] { border-color: var(--rms-public-primary); background: var(--rms-public-primary); color: var(--rms-public-primary-contrast); }
        .archive-pagination a:focus-visible { outline: 3px solid var(--rms-public-link); outline-offset: 3px; }
        .archive-pagination .is-disabled { color: var(--public-muted); background: var(--public-bg); cursor: not-allowed; }
        @media (max-width: 1000px) {
            .archive-filter .card-body { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .archive-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 768px) {
            .archive-hero .hero-container { grid-template-columns: 1fr; }
            .archive-visual { display: none; }
            .archive-filter .card-body, .archive-grid { grid-template-columns: 1fr; }
            .archive-filter .btn, .archive-reset { width: 100%; text-align: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            .archive-card { transition: none; }
        }
        @media (max-width: 480px) {
            .archive-access-dialog { padding: 30px 22px 24px; border-radius: 18px; }
            .archive-dialog-heading { gap: 12px; margin-right: 34px; }
            .archive-dialog-icon { flex-basis: 44px; width: 44px; height: 44px; border-radius: 13px; }
            .archive-access-dialog h2 { font-size: 1.3rem; }
            .archive-dialog-actions { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/public-pages-v2.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-pages-v2.css'); ?>">
    <link rel="stylesheet" href="assets/css/public-header.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-header.css'); ?>">
    <link rel="stylesheet" href="assets/css/public-footer.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-footer.css'); ?>">
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body class="public-v2">
    <?php $publicHeaderActivePage = 'archive'; require __DIR__ . '/../includes/public-header.php'; ?>

    <section class="hero archive-hero">
        <div class="hero-container">
            <div class="hero-content hero-entrance">
                <h1>Research <span class="highlight">Archive</span></h1>
                <p>Browse completed and published research from EARIST students and faculty.</p>
                <div class="hero-actions">
                    <?php if ($is_logged_in): ?>
                        <a href="<?php echo archive_escape(SITE_URL . 'pages/researcher/submit-research.php'); ?>" class="btn btn-primary btn-lg">+ Submit Research</a>
                    <?php else: ?>
                        <a href="login.php?continue=research-archive" class="btn btn-primary btn-lg">Login to submit your research</a>
                    <?php endif; ?>
                </div>
                <div class="archive-stat"><strong><?php echo archive_escape($archived_count); ?></strong> research works archived</div>
            </div>
            <div class="hero-visual archive-visual" aria-hidden="true"><span class="archive-visual-icon">📚</span></div>
        </div>
    </section>

    <section class="section archive-section" data-reveal>
        <div class="card archive-filter">
            <div class="card-header">
                <div>
                    <div class="card-title">Find Research</div>
                    <div class="card-subtitle">Search released student research and faculty publications and records.</div>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="research-archive.php" style="display: contents;">
                    <div class="form-group">
                        <label class="form-label" for="search">Search</label>
                        <input class="form-control" type="search" id="search" name="search" placeholder="Search title or abstract" value="<?php echo archive_escape($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-control" id="category_id" name="category_id">
                            <option value="0">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo archive_escape($category['category_id']); ?>"<?php echo (int) $selected_category === (int) $category['category_id'] ? ' selected' : ''; ?>><?php echo archive_escape($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="academic_year">Academic Year</label>
                        <select class="form-control" id="academic_year" name="academic_year">
                            <option value="">All academic years</option>
                            <?php foreach ($academic_years as $academic_year): ?>
                                <option value="<?php echo archive_escape($academic_year); ?>"<?php echo $selected_academic_year === $academic_year ? ' selected' : ''; ?>><?php echo archive_escape($academic_year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="program">Program</label>
                        <select class="form-control" id="program" name="program">
                            <option value="">All programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo archive_escape($program); ?>"<?php echo $selected_program === $program ? ' selected' : ''; ?>><?php echo archive_escape($program); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="type">Record type</label>
                        <select class="form-control" id="type" name="type">
                            <option value="all"<?php echo $selected_type === 'all' ? ' selected' : ''; ?>>All record types</option>
                            <option value="student_project"<?php echo $selected_type === 'student_project' ? ' selected' : ''; ?>>Student projects</option>
                            <option value="faculty_publication"<?php echo $selected_type === 'faculty_publication' ? ' selected' : ''; ?>>Faculty publications</option>
                            <option value="faculty_research_record"<?php echo $selected_type === 'faculty_research_record' ? ' selected' : ''; ?>>Faculty research records</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="archive-reset" href="research-archive.php">Reset</a>
                </form>
            </div>
        </div>

        <?php if (empty($archive_records)): ?>
            <div class="card archive-empty">
                <span class="archive-empty-icon">📭</span>
                <h2 class="card-title">No research works match your filters.</h2>
                <p style="color: var(--text-light); margin-top: 8px;">Try another search or reset the filters to browse the full archive.</p>
            </div>
        <?php else: ?>
            <div class="archive-grid">
                <?php foreach ($archive_records as $project): ?>
                    <?php
                    $abstract = trim((string) ($project['abstract'] ?? ''));
                    $abstract_preview = function_exists('mb_substr') ? mb_substr($abstract, 0, 150) : substr($abstract, 0, 150);
                    $abstract_length = function_exists('mb_strlen') ? mb_strlen($abstract) : strlen($abstract);
                    $project_authors = $project['authors'] ?? [];
                    $project_type = (string) $project['source_type'];
                    $record_url = SITE_URL . 'public/research-record.php?' . http_build_query(['type' => $project_type, 'id' => (int) $project['source_id']]);
                    if (!$is_logged_in) {
                        $record_url = SITE_URL . 'public/login.php?' . http_build_query(['continue' => 'public-record', 'record_type' => $project_type, 'record_id' => (int) $project['source_id']]);
                    }
                    $updated_timestamp = strtotime((string) ($project['updated_at'] ?? ''));
                    $published_year = $updated_timestamp ? date('Y', $updated_timestamp) : 'Year unavailable';
                    $year_label = $project_type === 'student_project' ? 'Academic year: ' : 'Year: ';
                    ?>
                    <article class="card archive-card">
                        <a class="card-body" href="<?php echo archive_escape($record_url); ?>"<?php echo $is_logged_in ? '' : ' data-archive-record-link'; ?> aria-label="Read full record for <?php echo archive_escape($project['title']); ?>">
                            <h2 class="archive-card-title"><?php echo archive_escape($project['title']); ?></h2>
                            <div class="archive-meta">
                                <span class="archive-badge"><?php echo archive_escape($project['category_name'] ?: 'Research'); ?></span>
                                <span class="archive-year"><?php echo archive_escape($project['academic_year'] ?: 'Year unavailable'); ?></span>
                            </div>
                            <div class="archive-authors" aria-label="Recorded research authors">
                                <?php if ($project_authors): ?>
                                    <?php foreach ($project_authors as $author): ?>
                                        <?php $authorRole = ($author['author_role'] ?? '') === 'lead' ? 'Lead author:' : (($project_type === 'student_project') ? 'Co-author:' : 'Author:'); ?>
                                        <p class="archive-author"><strong><?php echo archive_escape($authorRole); ?></strong> <?php echo archive_escape($author['author_name']); ?><?php if ($project_type === 'student_project' && !empty($author['program'])): ?><span class="archive-program"><?php echo archive_escape($author['program']); ?></span><?php endif; ?></p>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="archive-author">Authorship information unavailable</p>
                                <?php endif; ?>
                            </div>
                            <p class="archive-abstract"><?php echo archive_escape($abstract ? $abstract_preview . ($abstract_length > 150 ? '…' : '') : 'No abstract available.'); ?></p>
                            <div class="archive-published"><span><?php echo archive_escape($year_label . ($project['academic_year'] ?? $published_year)); ?></span><span class="archive-expand-label">Read full record</span></div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($archive_total_records > 0): ?>
                <div class="archive-pagination-wrap">
                    <p class="archive-pagination-summary">Showing <?php echo archive_escape($archive_first_record); ?>–<?php echo archive_escape($archive_last_record); ?> of <?php echo archive_escape($archive_total_records); ?> <?php echo $archive_total_records === 1 ? 'record' : 'records'; ?></p>
                    <?php if ($archive_total_pages > 1): ?>
                        <?php
                        $pagination_start = max(1, min($archive_page - 2, $archive_total_pages - 4));
                        $pagination_end = min($archive_total_pages, $pagination_start + 4);
                        ?>
                        <nav class="archive-pagination" aria-label="Research archive pagination">
                            <?php if ($archive_page > 1): ?><a href="<?php echo archive_escape($archive_pagination_url(1)); ?>" aria-label="First page">First</a><a href="<?php echo archive_escape($archive_pagination_url($archive_page - 1)); ?>" rel="prev">Previous</a><?php else: ?><span class="is-disabled" aria-disabled="true">First</span><span class="is-disabled" aria-disabled="true">Previous</span><?php endif; ?>
                            <?php for ($page_number = $pagination_start; $page_number <= $pagination_end; $page_number++): ?>
                                <?php if ($page_number === $archive_page): ?><span aria-current="page" aria-label="Page <?php echo archive_escape($page_number); ?>"><?php echo archive_escape($page_number); ?></span><?php else: ?><a href="<?php echo archive_escape($archive_pagination_url($page_number)); ?>" aria-label="Page <?php echo archive_escape($page_number); ?>"><?php echo archive_escape($page_number); ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($archive_page < $archive_total_pages): ?><a href="<?php echo archive_escape($archive_pagination_url($archive_page + 1)); ?>" rel="next">Next</a><a href="<?php echo archive_escape($archive_pagination_url($archive_total_pages)); ?>" aria-label="Last page">Last</a><?php else: ?><span class="is-disabled" aria-disabled="true">Next</span><span class="is-disabled" aria-disabled="true">Last</span><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if (!$is_logged_in): ?>
        <dialog class="archive-access-dialog" id="archive-access-dialog" aria-labelledby="archive-access-title" aria-describedby="archive-access-description">
            <button class="archive-dialog-close" type="button" aria-label="Close sign-in prompt" data-archive-dialog-close>&times;</button>
            <div class="archive-dialog-heading">
                <span class="archive-dialog-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 10V7a5 5 0 0 1 10 0v3"></path><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M12 14v3"></path></svg>
                </span>
                <div>
                    <p class="archive-dialog-eyebrow">Research archive</p>
                    <h2 id="archive-access-title">Sign in to read the full record</h2>
                </div>
            </div>
            <p id="archive-access-description">The public archive shows a preview. Sign in to view the full abstract, record details, and any released research files.</p>
            <div class="archive-dialog-actions">
                <a class="btn btn-primary" id="archive-access-login" href="<?php echo archive_escape(SITE_URL . 'public/login.php'); ?>">Log in to continue</a>
                <button class="btn btn-secondary" type="button" data-archive-dialog-close>Keep browsing</button>
            </div>
        </dialog>
        <script>
            (() => {
                const accessDialog = document.getElementById('archive-access-dialog');
                const loginLink = document.getElementById('archive-access-login');

                if (!accessDialog || !loginLink || typeof accessDialog.showModal !== 'function') return;

                document.querySelectorAll('[data-archive-record-link]').forEach((recordLink) => {
                    recordLink.addEventListener('click', (event) => {
                        event.preventDefault();
                        loginLink.href = recordLink.href;
                        accessDialog.showModal();
                    });
                });

                accessDialog.querySelectorAll('[data-archive-dialog-close]').forEach((closeButton) => {
                    closeButton.addEventListener('click', () => accessDialog.close());
                });

                accessDialog.addEventListener('click', (event) => {
                    if (event.target === accessDialog) accessDialog.close();
                });
            })();
        </script>
    <?php endif; ?>

    <?php require __DIR__ . '/../includes/public-footer.php'; ?>
    <script src="assets/js/public-pages-v2.js?v=<?php echo filemtime(__DIR__ . '/assets/js/public-pages-v2.js'); ?>" defer></script>
    <script src="../js/public-motion.js?v=<?php echo filemtime(__DIR__ . '/../js/public-motion.js'); ?>" defer></script>
</body>
</html>
