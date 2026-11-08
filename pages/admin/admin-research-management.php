<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();
require_once __DIR__ . '/../../includes/admin-project-actions.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete_project', 'retry_project_cleanup'], true)) {
    try {
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session has expired. Refresh the page and try again.');
        }
        $id_input = $_POST['project_id'] ?? null;
        $delete_id = is_scalar($id_input) ? filter_var($id_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (!$delete_id) {
            throw new RuntimeException('Choose a valid project.');
        }
        if ($_POST['action'] === 'delete_project' && ($_POST['confirmation'] ?? '') !== 'DELETE') {
            throw new RuntimeException('Type DELETE to confirm permanent deletion.');
        }
        $clean = $_POST['action'] === 'delete_project'
            ? rms_admin_delete_project($conn, $delete_id)
            : rms_admin_finish_project_cleanup($conn, $delete_id);
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php', $clean
            ? 'Project deletion complete. Project documents and review history have been removed.'
            : 'Project records were deleted, but some files could not be removed. Use Retry document cleanup below.', $clean ? 'success' : 'error');
    } catch (Throwable $e) {
        $message = $e instanceof mysqli_sql_exception ? 'Deletion could not be completed. Refresh and try again.' : $e->getMessage();
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php', $message, 'error');
    }
}

// Detect optional soft-delete support so contributor listings remain schema-compatible.
$rp_has_deleted_at        = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'")->num_rows ?? 0);
$rp_deleted_filter_alias  = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';
$chapters_have_deleted_at = (bool) ($conn->query("SHOW COLUMNS FROM chapters LIKE 'deleted_at'")->num_rows ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_archived_project') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The restore request could not be verified. Please try again.', 'error');
    }

    $project_id_input = $_POST['project_id'] ?? null;
    $project_id = (is_string($project_id_input) || is_int($project_id_input))
        ? filter_var($project_id_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($project_id === false) {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The selected project ID is invalid.', 'error');
    }

    $restore_project_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';
    $restore_chapter_deleted_filter = $chapters_have_deleted_at ? ' AND ch.deleted_at IS NULL' : '';
    $restore_progress_check = "(SELECT COUNT(DISTINCT ch.chapter_number)
                                  FROM chapters ch
                                 WHERE ch.project_id = rp.project_id
                                   AND ch.chapter_number BETWEEN 1 AND 5
                                   AND ch.status = 'approved'{$restore_chapter_deleted_filter}) BETWEEN 1 AND 4";

    $restore_stmt = $conn->prepare(
        "UPDATE research_projects AS rp
            SET status = 'ongoing'
          WHERE rp.project_id = ?
            AND rp.status = 'archived'{$restore_project_deleted_filter}
            AND {$restore_progress_check}"
    );
    if (!$restore_stmt) {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The project could not be restored. Please try again.', 'error');
    }

    $restore_stmt->bind_param('i', $project_id);
    if (!$restore_stmt->execute()) {
        $restore_stmt->close();
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The project could not be restored. Please try again.', 'error');
    }
    $restore_updated = $restore_stmt->affected_rows === 1;
    $restore_stmt->close();

    if ($restore_updated) {
        $audit_logged = logActivity('Restored archived research project #' . $project_id . ' to ongoing', 'research');
        $success_message = $audit_logged
            ? 'The project was restored to ongoing.'
            : 'The project was restored to ongoing, but the audit log could not be confirmed.';
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', $success_message, 'success');
    }

    $restore_diagnostic_stmt = $conn->prepare(
        "SELECT rp.status,
                (SELECT COUNT(DISTINCT ch.chapter_number)
                   FROM chapters ch
                  WHERE ch.project_id = rp.project_id
                    AND ch.chapter_number BETWEEN 1 AND 5
                    AND ch.status = 'approved'{$restore_chapter_deleted_filter}) AS approved_chapters
           FROM research_projects rp
          WHERE rp.project_id = ?{$restore_project_deleted_filter}"
    );
    if (!$restore_diagnostic_stmt) {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The project could not be restored. Please try again.', 'error');
    }

    $restore_diagnostic_stmt->bind_param('i', $project_id);
    if (!$restore_diagnostic_stmt->execute()) {
        $restore_diagnostic_stmt->close();
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'The project could not be restored. Please try again.', 'error');
    }
    $restore_diagnostic = $restore_diagnostic_stmt->get_result()->fetch_assoc();
    $restore_diagnostic_stmt->close();

    if (!$restore_diagnostic) {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'That project is no longer available or has been removed.', 'error');
    }
    if ($restore_diagnostic['status'] !== 'archived') {
        redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'That project is no longer archived, so it was not changed.', 'error');
    }
    redirectWithMessage(SITE_URL . 'pages/admin/admin-research-management.php?status=archived', 'This archived project is not eligible for restore: it must have 1–4 currently approved chapters.', 'error');
}

$flash = getMessage();
$flash_type = is_array($flash) && in_array($flash['type'] ?? '', ['success', 'error'], true)
    ? $flash['type']
    : '';
$flash_message = is_array($flash) ? (string) ($flash['message'] ?? '') : '';

$chapter_deleted_filter   = $chapters_have_deleted_at ? ' WHERE deleted_at IS NULL' : '';
$chapter_aggregate = "
    SELECT project_id,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 THEN chapter_number END) AS total_chapters,
           COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 AND status = 'approved' THEN chapter_number END) AS approved_chapters
      FROM chapters{$chapter_deleted_filter}
     GROUP BY project_id
";

// Keep lifecycle totals aligned with the project directory and shared implementation views.
$projectCountQuery = static function (string $predicate) use ($conn, $chapter_aggregate, $rp_deleted_filter_alias): int {
    $result = $conn->query(
        "SELECT COUNT(*) AS count
           FROM research_projects rp
      LEFT JOIN ({$chapter_aggregate}) ca ON ca.project_id = rp.project_id
          WHERE {$predicate}{$rp_deleted_filter_alias}"
    );
    if (!$result) {
        throw new RuntimeException('Unable to count research lifecycle projects: ' . $conn->error);
    }
    return (int) ($result->fetch_assoc()['count'] ?? 0);
};
$research_implementing = $projectCountQuery(rms_ongoing_research_predicate());
$research_completed = $projectCountQuery(rms_completed_research_predicate());

// Get all research projects with filters
$status_filter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$research_view = in_array($status_filter, ['ongoing', 'completed'], true) ? $status_filter : '';
$research_view_title = $research_view === 'ongoing' ? 'Ongoing Research' : 'Completed Research';
$research_view_description = $research_view === 'ongoing'
    ? 'Research with an approved title that has not yet received final manuscript approval.'
    : 'Research projects with all five chapters and the complete manuscript approved.';
$queue_filter = $_GET['queue'] ?? '';
$search = is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']), 0, 150) : '';
$year_input = $_GET['year'] ?? '';
$completion_year = is_string($year_input) && preg_match('/^[1-9][0-9]{3}$/D', $year_input) ? $year_input : '';
$record_status = is_string($_GET['record_status'] ?? null) ? $_GET['record_status'] : '';
$course_filter = is_string($_GET['course'] ?? null) ? trim($_GET['course']) : '';
$final_approval_aggregate = "SELECT v.project_id, MAX(COALESCE(r.reviewed_at, v.submitted_at)) AS final_approved_at
    FROM research_submission_versions v
    LEFT JOIN research_submission_reviews r ON r.version_id = v.version_id AND r.decision = 'approved'
    WHERE v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL AND v.status = 'approved'
    GROUP BY v.project_id";

$where_clauses = [];
$params = [];
$types = '';

if (!empty($status_filter)) {
    if ($status_filter === 'ongoing') {
        $where_clauses[] = rms_ongoing_research_predicate();
    } elseif ($status_filter === 'completed') {
        $where_clauses[] = rms_completed_research_predicate();
    } elseif ($status_filter === 'for_revision') {
        $where_clauses[] = "rp.status IN ('for_revision', 'revision_required')";
    } else {
        $where_clauses[] = "rp.status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
}

if ($rp_has_deleted_at) {
    $where_clauses[] = 'rp.deleted_at IS NULL';
}

if ($queue_filter === 'review') {
    $where_clauses[] = "rp.status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review', 'for_revision', 'revision_required')";
}

if (!empty($search)) {
    $where_clauses[] = "(rp.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($course_filter)) {
    $where_clauses[] = "u.program LIKE ?";
    $params[] = "%{$course_filter}%";
    $types .= 's';
}

if ($research_view === 'completed' && $completion_year !== '') {
    $where_clauses[] = 'YEAR(COALESCE(fa.final_approved_at, rp.updated_at)) = ?';
    $params[] = $completion_year;
    $types .= 's';
}
if ($research_view === 'completed' && $record_status !== '') {
    $where_clauses[] = 'rp.status = ?';
    $params[] = $record_status;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "
    SELECT rp.*, COALESCE(fa.final_approved_at, rp.updated_at) AS completed_at,
           COALESCE(ca.approved_chapters, 0) AS approved_chapters,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           advisers.adviser_name,
           EXISTS (
               SELECT 1 FROM research_submission_versions revision_version
               LEFT JOIN chapters revision_chapter ON revision_chapter.chapter_id = revision_version.chapter_id
               WHERE revision_version.project_id = rp.project_id AND revision_version.status = 'approved'
                 AND ((revision_version.submission_type = 'final_manuscript' AND revision_version.chapter_id IS NULL)
                   OR (revision_version.submission_type = 'chapter' AND revision_chapter.status = 'approved' AND revision_chapter.deleted_at IS NULL))
                 AND NOT EXISTS (SELECT 1 FROM research_submission_versions newer_revision
                     WHERE newer_revision.project_id = revision_version.project_id
                       AND newer_revision.submission_type = revision_version.submission_type
                       AND newer_revision.chapter_id <=> revision_version.chapter_id
                       AND newer_revision.version_number > revision_version.version_number)
           ) AS can_return_approved
    FROM research_projects rp
    LEFT JOIN ({$chapter_aggregate}) ca ON ca.project_id = rp.project_id
    LEFT JOIN ({$final_approval_aggregate}) fa ON fa.project_id = rp.project_id
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN (
        SELECT pa.project_id, GROUP_CONCAT(DISTINCT CONCAT(f.first_name, ' ', f.last_name) ORDER BY f.last_name SEPARATOR ', ') AS adviser_name
        FROM project_advisers pa JOIN users f ON f.user_id = pa.adviser_id
        GROUP BY pa.project_id
    ) advisers ON advisers.project_id = rp.project_id
    {$where_sql}
    ORDER BY " . ($research_view === 'completed' ? 'completed_at' : 'rp.created_at') . " DESC
";

$count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $query . ') research_matches');
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$matching_projects = (int) $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($matching_projects / 50));
$page_input = $_GET['page'] ?? null;
$page = is_scalar($page_input) ? (filter_var($page_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1) : 1;
$page = min($page, $total_pages);
$offset = ($page - 1) * 50;
$stmt = $conn->prepare($query . ' LIMIT ? OFFSET ?');
$params = array_merge($params, [50, $offset]);
$types .= 'ii';
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects = $stmt->get_result();
$visible_projects = $projects->num_rows;

$directory_context = $research_view !== ''
    ? ' in ' . strtolower($research_view_title)
    : ((!empty($search) || !empty($status_filter)) ? ' for the current filters' : '');

renderAdminShell(
    $user,
    'admin-research-management.php',
    'Research Management',
    $research_view !== '' ? $research_view_description : 'Track research from title submission through chapter review and final-manuscript approval.'
);
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/research-management.css">

<div class="research-workspace<?php echo $research_view !== '' ? ' research-workspace-focused' : ''; ?>">

    <?php if ($research_view !== '' || $status_filter === ''): ?>
      <header class="io-intro">
        <p class="io-eyebrow">Research implementation &middot; portfolio overview</p>
        <h1><?php echo htmlspecialchars($research_view !== '' ? $research_view_title : 'All Research', ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="io-intro-copy"><?php if ($research_view === 'ongoing'): ?>Monitor title-approved projects as researchers submit Chapters 1&ndash;5 for review.<?php elseif ($research_view === 'completed'): ?><?php echo htmlspecialchars($research_view_description, ENT_QUOTES, 'UTF-8'); ?><?php else: ?>Track research from title submission through chapter review and final-manuscript approval.<?php endif; ?></p>
      </header>
    <?php endif; ?>

    <?php if ($flash_type !== '' && $flash_message !== ''): ?>
      <div class="research-flash research-flash-<?php echo htmlspecialchars($flash_type, ENT_QUOTES, 'UTF-8'); ?>" role="<?php echo $flash_type === 'error' ? 'alert' : 'status'; ?>">
        <?php echo htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <?php foreach (glob(__DIR__ . '/../../includes/project-deletion-jobs/*.json') ?: [] as $cleanup_file): ?>
      <?php $cleanup_id = (int) basename($cleanup_file, '.json'); ?>
      <form method="post" class="research-flash research-flash-error">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="retry_project_cleanup">
        <input type="hidden" name="project_id" value="<?php echo $cleanup_id; ?>">
        <span>Pending document cleanup for deleted project #<?php echo $cleanup_id; ?>.</span>
        <button class="btn" type="submit">Retry document cleanup</button>
      </form>
    <?php endforeach; ?>

    <!-- RESEARCH TABLE CARD -->
    <section class="research-directory" id="research-directory" aria-labelledby="research-directory-title">

      <!-- FILTERS -->
      <form method="GET" action="admin-research-management.php" class="filter-bar">
        <div class="search-field">
          <label for="researchSearch" class="sr-only">Search projects</label>
          <input id="researchSearch" type="search" name="search" class="search-input" placeholder="Search title or proponent" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label for="courseFilter" class="sr-only">Filter by course</label>
        <select id="courseFilter" name="course" class="filter-select" size="1">
          <option value="">All Courses</option>
          <option value="BSIT" <?php echo $course_filter === 'BSIT' ? 'selected' : ''; ?>>BSIT (BS Information Technology)</option>
          <option value="BSCS" <?php echo $course_filter === 'BSCS' ? 'selected' : ''; ?>>BSCS (BS Computer Science)</option>
        </select>
        <?php if ($research_view !== ''): ?>
          <input type="hidden" name="status" value="<?php echo htmlspecialchars($research_view, ENT_QUOTES, 'UTF-8'); ?>">
          <?php if ($research_view === 'completed'): ?>
            <label for="completionYear">Completion year</label>
            <input id="completionYear" class="filter-select" type="number" name="year" min="1900" max="9999" placeholder="Any year" value="<?php echo htmlspecialchars($completion_year, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($record_status !== ''): ?><input type="hidden" name="record_status" value="<?php echo htmlspecialchars($record_status, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
        <label for="researchStatus" class="sr-only">Filter by project status</label>
        <select id="researchStatus" name="status" class="filter-select">
          <option value="">All Statuses</option>
          <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
          <option value="for_revision" <?php echo $status_filter === 'for_revision' ? 'selected' : ''; ?>>Returned for Revision</option>
          <option value="title_approved" <?php echo $status_filter === 'title_approved' ? 'selected' : ''; ?>>Title Approved</option>
          <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
          <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
        <?php if ($research_view === 'completed'): ?>
          <a class="btn btn-secondary" href="<?php echo htmlspecialchars(SITE_URL . 'pages/shared/implementation-outputs.php?' . http_build_query(['view' => 'completed', 'module_page' => 'completed_research', 'q' => $search, 'year' => $completion_year, 'status' => $record_status, 'export' => 'csv']), ENT_QUOTES, 'UTF-8'); ?>">Export CSV</a>
        <?php endif; ?>
        <?php if (!empty($search) || !empty($status_filter) || !empty($course_filter)): ?>
          <a href="<?php echo htmlspecialchars('admin-research-management.php' . ($research_view === '' ? '' : '?status=' . rawurlencode($research_view)), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Clear filters</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table class="research-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Researcher</th>
              <th><?php echo $research_view === 'completed' ? 'Chapters' : 'Adviser'; ?></th>
              <th>Status</th>
              <th><?php echo $research_view === 'completed' ? 'Completed' : 'Created'; ?></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($projects->num_rows > 0): ?>
              <?php while ($project = $projects->fetch_assoc()): ?>
                <tr>
                  <td data-label="Project">
                    <a class="project-title" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>"><?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <div class="project-ref">PROJECT #<?php echo (int) $project['project_id']; ?></div>
                  </td>
                  <td data-label="Proponent"><span class="person-name"><?php echo htmlspecialchars($project['student_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="<?php echo $research_view === 'completed' ? 'Chapters' : 'Adviser'; ?>"><?php echo $research_view === 'completed' ? (int) $project['approved_chapters'] . ' / 5 approved' : htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td data-label="Status">
                    <?php
                    $status_badges = [
                      'draft' => 'badge-draft',
                      'proposal' => 'badge-proposal',
                      'under_crec_review' => 'badge-crec',
                      'under_erec_review' => 'badge-erec',
                      'approved' => 'badge-approved',
                      'title_approved' => 'badge-approved',
                      'ongoing' => 'badge-ongoing',
                      'in_progress' => 'badge-ongoing',
                      'completed' => 'badge-completed',
                      'archived' => 'badge-archived'
                    ];
                    $status_labels = [
                      'draft' => 'Draft',
                      'proposal' => 'Proposal',
                      'under_crec_review' => 'Committee Review (legacy)',
                      'under_erec_review' => 'Ethics Review (legacy)',
                      'approved' => 'Approved',
                      'title_approved' => 'Title Approved',
                      'ongoing' => 'Ongoing',
                      'in_progress' => 'In Progress',
                      'completed' => 'Completed',
                      'archived' => 'Archived'
                    ];
                    $badge_class = $status_badges[$project['status']] ?? 'badge-draft';
                    $status_label = $status_labels[$project['status']] ?? ucfirst($project['status']);
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
                  </td>
                  <td data-label="<?php echo $research_view === 'completed' ? 'Completed' : 'Created'; ?>" class="date-cell"><?php echo date('M d, Y', strtotime($project[$research_view === 'completed' ? 'completed_at' : 'created_at'])); ?></td>
                  <td data-label="Actions">
                    <div class="row-actions">
                      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                      <?php if (!empty($project['can_return_approved'])): ?>
                        <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>&amp;revision=1#admin-revision-actions" class="btn btn-secondary btn-sm">Return approved work for revision</a>
                      <?php endif; ?>
                      <?php if ($research_view !== 'completed'): ?>
                        <button type="button" class="btn project-delete-button" data-project-id="<?php echo (int) $project['project_id']; ?>" data-project-title="<?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?>">Delete</button>
                      <?php endif; ?>
                      <?php if ($project['status'] === 'archived' && (int) $project['approved_chapters'] >= 1 && (int) $project['approved_chapters'] <= 4): ?>
                        <form method="POST" action="admin-research-management.php?status=archived">
                          <?php echo csrfField(); ?>
                          <input type="hidden" name="action" value="restore_archived_project">
                          <input type="hidden" name="project_id" value="<?php echo (int) $project['project_id']; ?>">
                          <button type="submit" class="btn btn-primary btn-sm" aria-label="Restore project #<?php echo (int) $project['project_id']; ?> to ongoing">Restore to ongoing</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="empty-row">
                  <div class="empty-state">
                    <div class="empty-state-mark" aria-hidden="true">00</div>
                    <strong>No matching research projects</strong>
                    <p>Adjust the title, proponent, or lifecycle-status filter.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total_pages > 1): ?>
        <nav class="research-pagination" aria-label="Research result pages">
          <?php foreach ([$page - 1 => 'Previous', $page + 1 => 'Next'] as $target_page => $page_label): ?>
            <?php if ($target_page >= 1 && $target_page <= $total_pages): ?>
              <a class="btn" href="<?php echo htmlspecialchars(SITE_URL . 'pages/admin/admin-research-management.php?' . http_build_query(['status' => $status_filter, 'search' => $search, 'year' => $completion_year, 'record_status' => $record_status, 'queue' => is_string($queue_filter) ? $queue_filter : '', 'page' => $target_page]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $page_label; ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        </nav>
      <?php endif; ?>
    </section>
</div>
<dialog id="delete-project-dialog" class="project-delete-dialog" aria-labelledby="delete-project-heading">
  <form method="post" action="<?php echo SITE_URL; ?>pages/admin/admin-research-management.php">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_project">
    <input type="hidden" name="project_id" id="delete-project-id">
    <h2 id="delete-project-heading">Delete research project?</h2>
    <p>Are you sure you want to delete this project?</p>
    <p><strong id="delete-project-title"></strong></p>
    <p>This permanently deletes its chapters, project documents and review history. It cannot be restored. Separate publication, copyright and engagement records are retained.</p>
    <label for="delete-project-confirmation">Type DELETE to confirm</label>
    <input id="delete-project-confirmation" name="confirmation" required pattern="DELETE" autocomplete="off">
    <div class="row-actions">
      <button type="button" id="cancel-project-delete" class="btn">Cancel</button>
      <button type="submit" class="btn project-delete-button">Delete project permanently</button>
    </div>
  </form>
</dialog>
<script>
(() => {
  const dialog = document.getElementById('delete-project-dialog');
  document.querySelectorAll('button[data-project-id]').forEach(button => {
    button.addEventListener('click', () => {
      document.getElementById('delete-project-id').value = button.dataset.projectId;
      document.getElementById('delete-project-title').textContent = button.dataset.projectTitle;
      document.getElementById('delete-project-confirmation').value = '';
      dialog.showModal();
      document.getElementById('cancel-project-delete').focus();
    });
  });
  document.getElementById('cancel-project-delete').addEventListener('click', () => dialog.close());
})();
</script>

<?php
renderAdminShellClose();
?>
