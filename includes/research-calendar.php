<?php

/** Return the filtered, paginated projects shown by the research calendar. */
function rms_research_calendar_load(mysqli $connection, array $input, string $course): array
{
    $search = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 200, 'UTF-8') : '';
    $requested_year_label = is_string($input['ay_label'] ?? null)
        ? trim(mb_substr($input['ay_label'], 0, 20, 'UTF-8'))
        : '';
    $status_options = [
        'awaiting_review' => 'Awaiting review',
        'returned' => 'Returned for revision',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
    ];
    $status = is_string($input['progress_status'] ?? null)
        && isset($status_options[$input['progress_status']])
        ? $input['progress_status']
        : '';
    $layout = is_string($input['layout'] ?? null) && in_array($input['layout'], ['timeline', 'agenda'], true)
        ? $input['layout']
        : 'timeline';
    $page_input = $input['calendar_page'] ?? null;
    $page = is_scalar($page_input)
        ? (filter_var($page_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1)
        : 1;
    $per_page = 10;

    $academic_years = [];
    $year_result = $connection->query(
        'SELECT DISTINCT label FROM academic_years ORDER BY label DESC'
    );
    if (!$year_result) {
        throw new RuntimeException('Unable to load academic years for the research calendar.');
    }
    while ($year_row = $year_result->fetch_assoc()) {
        $academic_years[] = trim((string) $year_row['label']);
    }
    $year_result->free();
    $year_label = in_array($requested_year_label, $academic_years, true) ? $requested_year_label : '';

    $search_like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
    $latest_version = "NOT EXISTS (
        SELECT 1 FROM research_submission_versions newer
         WHERE newer.project_id = v.project_id
           AND newer.submission_type = v.submission_type
           AND newer.chapter_id <=> v.chapter_id
           AND (newer.version_number > v.version_number
                OR (newer.version_number = v.version_number AND newer.version_id > v.version_id))
    )";
    $approved_title = "EXISTS (
        SELECT 1 FROM research_submission_versions title_version
         WHERE title_version.project_id = rp.project_id
           AND title_version.submission_type = 'title'
           AND title_version.chapter_id IS NULL
           AND title_version.status = 'approved'
           AND NOT EXISTS (
               SELECT 1 FROM research_submission_versions newer_title
                WHERE newer_title.project_id = title_version.project_id
                  AND newer_title.submission_type = 'title'
                  AND newer_title.chapter_id IS NULL
                  AND (newer_title.version_number > title_version.version_number
                       OR (newer_title.version_number = title_version.version_number AND newer_title.version_id > title_version.version_id))
           )
    )";
    $latest_status_exists = static function (string $wanted_status) use ($latest_version): string {
        return "EXISTS (
            SELECT 1 FROM research_submission_versions v
             WHERE v.project_id = rp.project_id AND v.status = '" . $wanted_status . "'
               AND v.submission_type IN ('chapter', 'final_manuscript')
               AND " . $latest_version . '
        )';
    };

    $chapter_counts = 'LEFT JOIN (
        SELECT project_id, COUNT(*) AS total_chapters,
               SUM(status = \'approved\') AS approved_chapters
          FROM chapters WHERE deleted_at IS NULL GROUP BY project_id
    ) ca ON ca.project_id = rp.project_id';
    $completed = rms_completed_research_predicate();
    $where = [
        'rp.deleted_at IS NULL',
        'researcher.deleted_at IS NULL',
        $approved_title,
    ];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(rp.title LIKE ? ESCAPE \'=\' OR CONCAT(researcher.first_name, \' \', researcher.last_name) LIKE ? ESCAPE \'=\')';
        $params[] = $search_like;
        $params[] = $search_like;
        $types .= 'ss';
    }
    if ($course !== '') {
        $where[] = "EXISTS (
            SELECT 1 FROM programs program_filter
             WHERE program_filter.program_code = ?
               AND (UPPER(TRIM(researcher.program)) = UPPER(program_filter.program_code)
                    OR UPPER(TRIM(researcher.program)) = UPPER(TRIM(program_filter.program_name)))
        )";
        $params[] = $course;
        $types .= 's';
    }
    if ($year_label !== '') {
        $where[] = 'ay.label = ?';
        $params[] = $year_label;
        $types .= 's';
    }
    if ($status === 'awaiting_review') {
        $where[] = $latest_status_exists('submitted');
    } elseif ($status === 'returned') {
        $where[] = $latest_status_exists('returned');
    } elseif ($status === 'completed') {
        $where[] = '(' . $completed . ')';
    } elseif ($status === 'in_progress') {
        $where[] = 'NOT (' . $completed . ')';
        $where[] = 'NOT (' . $latest_status_exists('submitted') . ')';
        $where[] = 'NOT (' . $latest_status_exists('returned') . ')';
    }
    $where_sql = implode("\n AND ", $where);
    $from_sql = 'FROM research_projects rp
        JOIN users researcher ON researcher.user_id = rp.created_by
        LEFT JOIN academic_years ay ON ay.ay_id = rp.ay_id
        ' . $chapter_counts;

    $count_stmt = $connection->prepare("SELECT COUNT(*) $from_sql WHERE $where_sql");
    if (!$count_stmt) {
        throw new RuntimeException('Unable to prepare the research calendar count.');
    }
    if ($types !== '') {
        $count_stmt->bind_param($types, ...$params);
    }
    if (!$count_stmt->execute()) {
        $count_stmt->close();
        throw new RuntimeException('Unable to count research calendar projects.');
    }
    $total = (int) $count_stmt->get_result()->fetch_row()[0];
    $count_stmt->close();
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $project_stmt = $connection->prepare("SELECT rp.project_id, rp.title, rp.created_by AS student_id,
            CONCAT(researcher.first_name, ' ', researcher.last_name) AS student_name,
            researcher.program AS student_program, ay.label AS academic_year,
            rp.updated_at, ($completed) AS is_completed
        $from_sql
        WHERE $where_sql
        ORDER BY rp.updated_at DESC, rp.project_id DESC
        LIMIT ? OFFSET ?");
    if (!$project_stmt) {
        throw new RuntimeException('Unable to prepare the research calendar projects.');
    }
    $paged_params = array_merge($params, [$per_page, $offset]);
    $paged_types = $types . 'ii';
    $project_stmt->bind_param($paged_types, ...$paged_params);
    if (!$project_stmt->execute()) {
        $project_stmt->close();
        throw new RuntimeException('Unable to load research calendar projects.');
    }
    $project_result = $project_stmt->get_result();
    $projects = [];
    while ($project = $project_result->fetch_assoc()) {
        $project['project_id'] = (int) $project['project_id'];
        $project['student_id'] = (int) $project['student_id'];
        $project['is_completed'] = (bool) $project['is_completed'];
        $project['milestones'] = [];
        $projects[$project['project_id']] = $project;
    }
    $project_result->free();
    $project_stmt->close();

    if ($projects) {
        $project_ids = array_keys($projects);
        $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
        $version_sql = "SELECT v.project_id, v.submission_type, v.version_number,
                v.submitted_at, v.status, ch.chapter_number,
                review.decision AS latest_decision, review.reviewed_at AS latest_reviewed_at
            FROM research_submission_versions v
            LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id AND ch.deleted_at IS NULL
            LEFT JOIN research_submission_reviews review ON review.review_id = (
                SELECT r.review_id FROM research_submission_reviews r
                 WHERE r.version_id = v.version_id
                 ORDER BY r.reviewed_at DESC, r.review_id DESC LIMIT 1
            )
            WHERE v.project_id IN ($placeholders)
              AND ((v.submission_type = 'title' AND v.chapter_id IS NULL)
                   OR (v.submission_type = 'chapter' AND v.chapter_id IS NOT NULL)
                   OR (v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL))
              AND $latest_version
            ORDER BY v.project_id, v.submitted_at";
        $version_stmt = $connection->prepare($version_sql);
        if (!$version_stmt) {
            throw new RuntimeException('Unable to prepare research calendar milestones.');
        }
        $version_types = str_repeat('i', count($project_ids));
        $version_stmt->bind_param($version_types, ...$project_ids);
        if (!$version_stmt->execute()) {
            $version_stmt->close();
            throw new RuntimeException('Unable to load research calendar milestones.');
        }
        $version_result = $version_stmt->get_result();
        $by_project = [];
        while ($version = $version_result->fetch_assoc()) {
            $kind = (string) $version['submission_type'];
            if ($kind === 'title') {
                $key = 'title';
                $label = 'Research title';
            } elseif ($kind === 'final_manuscript') {
                $key = 'final_manuscript';
                $label = 'Final manuscript';
            } else {
                $chapter_number = (int) ($version['chapter_number'] ?? 0);
                if ($chapter_number < 1 || $chapter_number > 5) {
                    continue;
                }
                $key = 'chapter_' . $chapter_number;
                $label = 'Chapter ' . $chapter_number;
            }
            $by_project[(int) $version['project_id']][$key] = [
                'key' => $key,
                'label' => $label,
                'version_number' => (int) $version['version_number'],
                'submitted_at' => (string) $version['submitted_at'],
                'status' => (string) $version['status'],
                'decision' => (string) ($version['latest_decision'] ?? ''),
                'reviewed_at' => (string) ($version['latest_reviewed_at'] ?? ''),
            ];
        }
        $version_result->free();
        $version_stmt->close();

        foreach ($projects as $project_id => &$project) {
            $stages = [
                ['title', 'Research title'],
                ['chapter_1', 'Chapter 1'],
                ['chapter_2', 'Chapter 2'],
                ['chapter_3', 'Chapter 3'],
                ['chapter_4', 'Chapter 4'],
                ['chapter_5', 'Chapter 5'],
                ['final_manuscript', 'Final manuscript'],
            ];
            foreach ($stages as [$key, $label]) {
                $milestone = $by_project[$project_id][$key] ?? [
                    'key' => $key,
                    'label' => $label,
                    'version_number' => 0,
                    'submitted_at' => '',
                    'status' => 'not_started',
                    'decision' => '',
                    'reviewed_at' => '',
                ];
                $project['milestones'][] = $milestone;
            }
            $project['current_progress'] = rms_research_calendar_current_progress($project['milestones'], $project['is_completed']);
        }
        unset($project);
    }

    return [
        'search' => $search,
        'course' => $course,
        'year_label' => $year_label,
        'academic_years' => $academic_years,
        'status' => $status,
        'status_options' => $status_options,
        'layout' => $layout,
        'page' => $page,
        'per_page' => $per_page,
        'offset' => $offset,
        'total' => $total,
        'total_pages' => $total_pages,
        'projects' => $projects,
    ];
}

function rms_research_calendar_current_progress(array $milestones, bool $completed): string
{
    if ($completed) {
        return 'Research completed';
    }
    foreach ($milestones as $milestone) {
        if ($milestone['key'] === 'title') {
            continue;
        }
        if ($milestone['status'] === 'not_started') {
            return $milestone['label'] . ' not started';
        }
        if ($milestone['status'] === 'submitted') {
            return $milestone['label'] . ' awaiting review';
        }
        if ($milestone['status'] === 'returned') {
            return $milestone['label'] . ' returned for revision';
        }
        if ($milestone['status'] !== 'approved') {
            return $milestone['label'] . ' status unavailable';
        }
    }
    return 'Final manuscript pending';
}

function rms_research_calendar_url(array $calendar, string $layout, int $page = 1): string
{
    $params = [
        'module_page' => 'research_approval',
        'view' => 'research_calendar',
        'q' => $calendar['search'],
        'course' => $calendar['course'],
        'ay_label' => $calendar['year_label'],
        'progress_status' => $calendar['status'],
        'layout' => $layout,
        'calendar_page' => max(1, $page),
    ];
    return SITE_URL . 'pages/shared/research-submission-review.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function rms_research_calendar_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
