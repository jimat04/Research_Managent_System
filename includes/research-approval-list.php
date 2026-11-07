<?php
// Read-only project pagination for the authenticated research approval page.
function rms_approval_latest_version_condition(): string
{
    return "v.status = 'approved'
        AND submitter.deleted_at IS NULL
        AND (v.chapter_id IS NULL OR (ch.chapter_id IS NOT NULL AND ch.deleted_at IS NULL))
        AND NOT EXISTS (
            SELECT 1 FROM research_submission_versions newer
             WHERE newer.project_id = v.project_id
               AND newer.submission_type = v.submission_type
               AND newer.chapter_id <=> v.chapter_id
               AND newer.version_number > v.version_number
        )";
}

function rms_approval_query(mysqli $connection, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the research approval list.');
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the research approval list.');
    }
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function rms_approval_projects(mysqli $connection, array $input, string $submission_type = '', string $course = ''): array
{
    $search = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 200, 'UTF-8') : '';
    $year_filter_enabled = in_array($submission_type, ['title', 'final_manuscript'], true);
    $year = $year_filter_enabled && is_string($input['year'] ?? null)
        && preg_match('/^\d{4}$/D', $input['year'])
        ? $input['year']
        : '';
    $page_input = $input['approval_page'] ?? null;
    $page = is_scalar($page_input)
        ? (filter_var($page_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1)
        : 1;
    $per_page = 10;
    // Treat LIKE wildcards as ordinary search characters.
    $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
    $params = [$search, $like, $like, $like];
    $eligible = rms_approval_latest_version_condition();
    // Only fixed, allowlisted SQL fragments enter the shared eligibility predicate.
    $eligible .= [
        'title' => " AND v.submission_type = 'title'",
        'final_manuscript' => " AND v.submission_type = 'final_manuscript'
            AND (SELECT COUNT(DISTINCT approved_chapter.chapter_number) FROM chapters approved_chapter
                 WHERE approved_chapter.project_id = v.project_id AND approved_chapter.deleted_at IS NULL
                   AND approved_chapter.status = 'approved' AND approved_chapter.chapter_number BETWEEN 1 AND 5) = 5",
    ][$submission_type] ?? '';
    $version_from = 'FROM research_submission_versions v
        JOIN users submitter ON submitter.user_id = v.submitted_by
        LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id';
    $from = 'FROM research_projects rp
        JOIN users researcher ON researcher.user_id = rp.created_by';
    $years = [];
    if ($year_filter_enabled) {
        $year_result = rms_approval_query($connection, "SELECT DISTINCT YEAR(v.submitted_at) AS submission_year
            $version_from
            WHERE $eligible
              AND v.submitted_at IS NOT NULL
              AND (? = '' OR EXISTS (SELECT 1 FROM programs course
                                      WHERE course.program_code = ?
                                        AND (submitter.program = course.program_code OR submitter.program = course.program_name)))
            ORDER BY submission_year DESC", 'ss', [$course, $course]);
        while ($year_row = $year_result->fetch_assoc()) {
            if ($year_row['submission_year'] !== null) {
                $years[] = (string) (int) $year_row['submission_year'];
            }
        }
        $year_result->free();
        if (!in_array($year, $years, true)) {
            $year = '';
        }
    }
    $where = "WHERE rp.deleted_at IS NULL AND researcher.deleted_at IS NULL
        AND EXISTS (SELECT 1 $version_from WHERE v.project_id = rp.project_id AND $eligible)
        AND (? = '' OR rp.title LIKE ? ESCAPE '='
            OR CONCAT(researcher.first_name, ' ', researcher.last_name) LIKE ? ESCAPE '='
            OR EXISTS (
                SELECT 1 $version_from
                 WHERE v.project_id = rp.project_id AND $eligible
                   AND CONCAT(submitter.first_name, ' ', submitter.last_name) LIKE ? ESCAPE '='
            ))";
    $where .= " AND (? = '' OR EXISTS (
        SELECT 1 $version_from
         WHERE v.project_id = rp.project_id AND $eligible
           AND EXISTS (SELECT 1 FROM programs course
                        WHERE course.program_code = ?
                          AND (submitter.program = course.program_code OR submitter.program = course.program_name))
    ))";
    $params = array_merge($params, [$course, $course]);
    if ($year_filter_enabled) {
        $where .= " AND (? = '' OR EXISTS (
            SELECT 1 $version_from
             WHERE v.project_id = rp.project_id AND $eligible
               AND YEAR(v.submitted_at) = ?
        ))";
        $params = array_merge($params, [$year, $year]);
    }
    $count_result = rms_approval_query($connection, "SELECT COUNT(*) $from $where", str_repeat('s', count($params)), $params);
    $total = (int) $count_result->fetch_row()[0];
    $count_result->free();
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    $completed = rms_completed_research_predicate();
    $sql = "SELECT rp.project_id, rp.title, rp.created_by AS researcher_id,
            CONCAT(researcher.first_name, ' ', researcher.last_name) AS researcher_name,
            researcher.program AS researcher_program,
            ($completed) AS is_completed,
            (SELECT MAX(COALESCE(
                (SELECT MAX(r.reviewed_at) FROM research_submission_reviews r
                  WHERE r.version_id = v.version_id AND r.decision = 'approved'),
                v.submitted_at)) $version_from
              WHERE v.project_id = rp.project_id AND $eligible) AS latest_approval_sort
        $from
        LEFT JOIN (
            SELECT project_id, COUNT(*) AS total_chapters,
                   SUM(status = 'approved') AS approved_chapters
              FROM chapters WHERE deleted_at IS NULL GROUP BY project_id
        ) ca ON ca.project_id = rp.project_id
        $where
        ORDER BY latest_approval_sort DESC, rp.project_id DESC
        LIMIT ? OFFSET ?";
    $paged_params = array_merge($params, [$per_page, $offset]);
    $result = rms_approval_query($connection, $sql, str_repeat('s', count($params)) . 'ii', $paged_params);
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = [];
        $projects[(int) $row['project_id']] = $row;
    }
    $result->free();
    return compact('search', 'year', 'years', 'page', 'per_page', 'offset', 'total', 'total_pages', 'projects');
}

function rms_approval_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rms_approval_url(string $search, int $page = 1, string $view = 'approved_proposal', string $course = '', string $year = ''): string
{
    $params = [
        'module_page' => 'research_approval',
        'view' => $view,
        'course' => $course,
        'q' => $search,
        'approval_page' => $page,
    ];
    if (in_array($view, ['approved_proposal', 'approved_final_manuscript'], true) && $year !== '') {
        $params['year'] = $year;
    }
    return SITE_URL . 'pages/shared/research-submission-review.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function rms_approval_progress(array $project): string
{
    if (!empty($project['is_completed'])) {
        return 'Research completed';
    }
    $chapters = [];
    $has_title = false;
    $has_manuscript = false;
    foreach ($project['items'] as $item) {
        $has_title = $has_title || $item['submission_type'] === 'title';
        $has_manuscript = $has_manuscript || $item['submission_type'] === 'final_manuscript';
        if ($item['submission_type'] === 'chapter' && $item['chapter_number'] !== null) {
            $chapters[] = (int) $item['chapter_number'];
        }
    }
    if ($has_manuscript) {
        return 'Complete manuscript approved';
    }
    if ($chapters) {
        $chapters = array_values(array_unique($chapters));
        sort($chapters);
        if (count($chapters) === 1) {
            return 'Chapter ' . $chapters[0] . ' approved';
        }
        $consecutive = $chapters === range($chapters[0], $chapters[count($chapters) - 1]);
        return 'Chapters ' . ($consecutive
            ? $chapters[0] . '–' . $chapters[count($chapters) - 1]
            : implode(', ', $chapters)) . ' approved';
    }
    return $has_title ? 'Research title approved' : 'Approved submissions';
}
