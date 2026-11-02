<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/research-workflow.php';

requireLogin();
$user = getCurrentUser();
$user_id = (int) ($user['user_id'] ?? 0);
$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['admin', 'research_staff'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$can_view_review = $role === 'admin'
    || hasModulePermission($user_id, 'research_development', 'view', 'research_proposals')
    || hasModulePermission($user_id, 'research_development', 'view', 'research_review')
    || hasModulePermission($user_id, 'research_development', 'view', 'research_approval');
if (!$can_view_review) {
    http_response_code(403);
    exit('Access denied.');
}
$can_return = rms_submission_can_review($user_id, 'returned');
$can_approve = rms_submission_can_review($user_id, 'approved');
$can_download_submission_documents = $role === 'admin';
if (!$can_download_submission_documents && $role === 'research_staff') {
    foreach (['research_proposals', 'research_review', 'research_approval'] as $review_page) {
        if (
            hasModulePermission($user_id, 'research_development', 'view', $review_page)
            && hasDownloadPermission($user_id, 'research_development', $review_page)
        ) {
            $can_download_submission_documents = true;
            break;
        }
    }
}
$type_filter = in_array((string) ($_GET['type'] ?? ''), ['title', 'chapter', 'final_manuscript'], true)
    ? (string) $_GET['type']
    : '';
$requested_module_page = (string) ($_GET['module_page'] ?? '');
$module_page = in_array($requested_module_page, ['research_proposals', 'research_review', 'research_approval'], true)
  ? $requested_module_page
  : '';
if ($module_page === '' && !array_key_exists('type', $_GET)) {
  $module_page = 'research_review';
}
$section_config = [
    'research_proposals' => [
        'title' => 'Review Proposals',
        'description' => 'Review submitted proposals awaiting a decision and their uploaded title-description documents.',
        'empty_title' => 'No proposals are waiting for review',
        'empty_description' => 'Submitted proposals appear here until approved or returned for revision. Approved proposals are listed under Approved Proposal.',
        'type' => 'title',
        'status' => 'submitted',
        'accent' => 'proposals',
    ],
    'research_review' => [
        'title' => 'Chapter Review',
        'description' => 'Review chapter versions awaiting an authorized decision.',
        'empty_title' => 'No submissions are waiting for review',
        'empty_description' => 'Submitted versions appear here until an authorized reviewer approves them or returns them with suggestions.',
        'type' => 'chapter',
        'status' => 'submitted',
        'accent' => 'review',
    ],
    'research_approval' => [
        'title' => 'Approved Proposal',
        'description' => 'Find a research project and open its approved proposal.',
        'empty_title' => 'No submissions have been approved yet',
        'empty_description' => 'Approved proposals and their review history appear here.',
        'type' => 'title',
        'status' => 'approved',
        'accent' => 'approval',
    ],
];
$review_tabs = [
    'proposals' => ['module' => 'research_proposals', 'title' => 'Review Proposals'],
    'approved_proposal' => ['module' => 'research_approval', 'title' => 'Approved Proposal'],
    'chapters' => ['module' => 'research_review', 'title' => 'Chapter Review'],
    'final_manuscript' => ['module' => 'research_review', 'title' => 'Review Final Manuscript'],
    'approved_final_manuscript' => ['module' => 'research_approval', 'title' => 'Approved Final Manuscript'],
];
$active_tab = is_string($_GET['view'] ?? null) ? $_GET['view'] : '';
if (!isset($review_tabs[$active_tab]) || $review_tabs[$active_tab]['module'] !== $module_page) {
    $active_tab = ['research_proposals' => 'proposals', 'research_review' => 'chapters', 'research_approval' => 'approved_proposal'][$module_page] ?? '';
}
if ($module_page !== '' && $role !== 'admin') {
    requireModulePermission('research_development', 'view', $module_page);
}
$section = $section_config[$module_page] ?? null;
if ($section && in_array($active_tab, ['final_manuscript', 'approved_final_manuscript'], true)) {
    $section['title'] = $review_tabs[$active_tab]['title'];
    $section['type'] = 'final_manuscript';
    $section['description'] = $active_tab === 'final_manuscript'
        ? 'Review final manuscripts awaiting an authorized decision.'
        : 'Find a research project and open its approved final manuscript.';
    $section['empty_description'] = $active_tab === 'final_manuscript'
        ? 'Submitted final manuscripts appear here for review.'
        : 'Approved final manuscripts and their review history appear here.';
}
$page_title = $section['title'] ?? 'Research Submission Review';
$type_filter = $section['type'] ?? $type_filter;
$status_filter = $section['status'] ?? '';
$course_filter = is_string($_GET['course'] ?? null) && in_array($_GET['course'], ['BSIT', 'BSCS'], true)
    ? $_GET['course'] : '';

$flash = ['success' => '', 'error' => ''];
if (!empty($_SESSION['module_error'])) {
    $flash['error'] = (string) $_SESSION['module_error'];
    unset($_SESSION['module_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $flash['error'] = 'Your session has expired. Refresh the page and try again.';
    } else {
        try {
            $decision_input = $_POST['decision'] ?? '';
            $comments_input = $_POST['comments'] ?? '';
            $recommendation_input = $_POST['recommendation'] ?? '';
            $selected_title_input = $_POST['selected_title'] ?? '';
            $version_id_input = $_POST['version_id'] ?? null;
            if (!is_string($decision_input)
                || !is_string($comments_input)
                || !is_string($recommendation_input)
                || !is_string($selected_title_input)
                || (!is_string($version_id_input) && !is_int($version_id_input))) {
                throw new InvalidArgumentException('Review fields must contain text values.');
            }
            $version_id = filter_var($version_id_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($version_id === false) {
                throw new InvalidArgumentException('The selected submission version is invalid.');
            }
            $decision = $decision_input;
            $version = rms_review_submission(
                $conn,
                $version_id,
                $user_id,
                $decision,
                trim($comments_input),
                trim($recommendation_input),
                trim($selected_title_input)
            );
            $project_id = (int) $version['project_id'];
            $researcher_id = (int) $version['created_by'];
            $reviewer_name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            if ($reviewer_name === '') {
                $reviewer_name = 'User #' . $user_id;
            }
            $reviewer_role_label = $role === 'admin' ? 'Administrator' : 'Authorized research staff';
            if ($version['submission_type'] === 'title') {
                $kind = 'research title';
            } elseif ($version['submission_type'] === 'final_manuscript') {
                $kind = 'complete research manuscript';
            } else {
                $kind = 'Chapter ' . (int) $version['chapter_number'];
            }
            $decision_text = $decision === 'approved' ? 'approved' : 'returned for revision';
            $notification_feedback = $decision === 'returned'
                ? '. Comments/Suggestions: ' . trim(implode(' ', array_filter(
                    [$comments_input, $recommendation_input],
                    static fn ($feedback) => trim($feedback) !== ''
                )))
                : ($version['submission_type'] === 'title' && !empty($version['selected_title'])
                    ? '. Approved title: ' . $version['selected_title']
                    : '.');
            createNotification(
                $researcher_id,
                ucfirst($kind) . ' ' . $decision_text,
                'Your ' . $kind . ' submission for "' . (string) $version['title'] . '" was ' . $decision_text
                    . $notification_feedback,
                $decision === 'approved' ? 'success' : 'warning',
                SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
            );
            logActivity(
                ucfirst($kind) . ' submission ' . $decision_text . ' for project #' . $project_id,
                'research_submission_review'
            );
            $flash['success'] = ucfirst($kind) . ' ' . $decision_text
                . ' by ' . $reviewer_name . ' (' . $reviewer_role_label . ')'
                . ' · Version ' . (int) $version['version_number'] . '.';
        } catch (Throwable $exception) {
            $flash['error'] = $exception->getMessage();
        }
    }
}

$is_approval_list = $module_page === 'research_approval';
$approval_list = null;
$approval_project_filter = '';
$queue_version_condition = "NOT EXISTS (
    SELECT 1 FROM research_submission_versions newer
     WHERE newer.project_id = v.project_id
       AND newer.submission_type = v.submission_type
       AND newer.chapter_id <=> v.chapter_id
       AND newer.version_number > v.version_number
)";
$queue_params = [$type_filter, $type_filter, $status_filter, $status_filter, $course_filter, $course_filter];
$queue_types = 'ssssss';
if ($is_approval_list) {
    require_once __DIR__ . '/../../includes/research-approval-list.php';
    $approval_list = rms_approval_projects($conn, $_GET, $type_filter, $course_filter);
    $approval_project_ids = array_keys($approval_list['projects']);
    $approval_project_filter = $approval_project_ids
        ? ' AND v.project_id IN (' . implode(',', array_fill(0, count($approval_project_ids), '?')) . ')'
        : ' AND 1 = 0';
    $approval_project_filter .= ' AND rp.deleted_at IS NULL';
    $queue_version_condition = rms_approval_latest_version_condition();
    $queue_params = array_merge($queue_params, $approval_project_ids);
    $queue_types .= str_repeat('i', count($approval_project_ids));
}

$sql = "
    SELECT v.version_id, v.project_id, v.chapter_id, v.submission_type, v.version_number,
           v.submitted_at, v.status, v.snapshot_json,
            rp.title, v.submitted_by, submitter.role AS submitter_role,
           CONCAT(submitter.first_name, ' ', submitter.last_name) AS submitter_name,
           submitter.program AS submitter_program,
           ch.chapter_number,
           latest_review.comments AS latest_comments,
           latest_review.recommendation AS latest_recommendation,
           latest_review.selected_title AS latest_selected_title,
           latest_review.decision AS latest_decision,
           latest_review.reviewed_at AS latest_reviewed_at,
           (SELECT COUNT(*) FROM research_submission_annotations ann WHERE ann.version_id = v.version_id) AS annotation_count,
           u.upload_id, u.original_name, u.file_size
      FROM research_submission_versions v
      JOIN research_projects rp ON rp.project_id = v.project_id
      JOIN users submitter ON submitter.user_id = v.submitted_by
 LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
 LEFT JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id
 LEFT JOIN uploads u ON u.upload_id = vu.upload_id
 LEFT JOIN research_submission_reviews latest_review
        ON latest_review.review_id = (
            SELECT r.review_id FROM research_submission_reviews r
             WHERE r.version_id = v.version_id
             ORDER BY r.reviewed_at DESC, r.review_id DESC LIMIT 1
        )
     WHERE $queue_version_condition
       AND (? = '' OR v.submission_type = ?)
        AND (? = '' OR v.status = ?)
        AND (? = '' OR EXISTS (
            SELECT 1 FROM programs course
             WHERE course.program_code = ?
               AND (submitter.program = course.program_code OR submitter.program = course.program_name)
        ))
        $approval_project_filter
  ORDER BY v.submitted_at DESC, v.version_id DESC"
    . ($is_approval_list ? '' : ' LIMIT 100');
$queue_stmt = $conn->prepare($sql);
if (!$queue_stmt) {
    throw new RuntimeException('Unable to prepare research submission queue: ' . $conn->error);
}
$queue_stmt->bind_param($queue_types, ...$queue_params);
if (!$queue_stmt->execute()) {
    throw new RuntimeException('Unable to load research submission queue: ' . $queue_stmt->error);
}
$result = $queue_stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $id = (int) $row['version_id'];
    if (!isset($items[$id])) {
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        $items[$id] = [
            'version_id' => $id,
            'project_id' => (int) $row['project_id'],
            'chapter_id' => $row['chapter_id'] !== null ? (int) $row['chapter_id'] : null,
            'submission_type' => (string) $row['submission_type'],
            'version_number' => (int) $row['version_number'],
            'submitted_at' => (string) $row['submitted_at'],
            'status' => (string) $row['status'],
            'title' => (string) $row['title'],
            'submitted_by' => (int) $row['submitted_by'],
            'submitter_name' => (string) $row['submitter_name'],
            'submitter_role' => (string) $row['submitter_role'],
            'submitter_program' => trim((string) ($row['submitter_program'] ?? '')),
            'chapter_number' => $row['chapter_number'] !== null ? (int) $row['chapter_number'] : null,
            'latest_comments' => (string) ($row['latest_comments'] ?? ''),
            'latest_recommendation' => (string) ($row['latest_recommendation'] ?? ''),
            'latest_selected_title' => (string) ($row['latest_selected_title'] ?? ''),
            'latest_decision' => (string) ($row['latest_decision'] ?? ''),
            'latest_reviewed_at' => (string) ($row['latest_reviewed_at'] ?? ''),
            'annotation_count' => (int) ($row['annotation_count'] ?? 0),
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'uploads' => [],
        ];
    }
    if (!empty($row['upload_id'])) {
        $items[$id]['uploads'][(int) $row['upload_id']] = [
            'id' => (int) $row['upload_id'],
            'name' => (string) $row['original_name'],
            'size' => (int) $row['file_size']
        ];
    }
}
$result->free();
$queue_stmt->close();

uasort($items, static function (array $left, array $right): int {
    $submitted_order = strcmp($left['submitted_at'], $right['submitted_at']);
    return $submitted_order !== 0 ? $submitted_order : $left['version_id'] <=> $right['version_id'];
});

if ($is_approval_list) {
    foreach ($items as $item) {
        $approval_list['projects'][$item['project_id']]['items'][$item['version_id']] = $item;
    }
    foreach ($approval_list['projects'] as &$approval_project) {
        uasort($approval_project['items'], static function (array $left, array $right): int {
            $left_order = $left['submission_type'] === 'title' ? 0
                : ($left['submission_type'] === 'final_manuscript' ? 6 : (int) $left['chapter_number']);
            $right_order = $right['submission_type'] === 'title' ? 0
                : ($right['submission_type'] === 'final_manuscript' ? 6 : (int) $right['chapter_number']);
            return $left_order <=> $right_order ?: $left['version_id'] <=> $right['version_id'];
        });
    }
    unset($approval_project);
}

if ($role === 'admin') {
    renderAdminShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions separately.');
} else {
    renderStaffShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions according to your assigned permissions.');
}
?>
<?php if ($is_approval_list): ?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/research-approval.css">
<?php endif; ?>
<style>
  .submission-review-workspace{--review-accent:#f57c00;--review-ink:#9a3412;--review-tint:#fff7ed;--review-border:#fed7aa;max-width:1440px;margin:0 auto;padding-bottom:32px}
  .staff-dashboard .submission-review-workspace{--review-accent:#0d9488;--review-ink:#0b7a71;--review-tint:#f0fdfa;--review-border:#99f6e4}
  .submission-review-tabs{display:flex;flex-wrap:wrap;gap:4px 24px;margin:0 0 24px;padding:0;border-bottom:1px solid #e5e7eb}
  .submission-course-filter{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:24px}
  .submission-course-filter label{font-size:14px;font-weight:600}
  .submission-course-filter select,.submission-course-filter button{min-height:44px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font:inherit}
  .submission-course-filter button{cursor:pointer;font-weight:600}
  .submission-course-filter :is(select,button):focus-visible{outline:2px solid var(--review-accent);outline-offset:3px}
  .submission-review-tab{display:flex;align-items:center;min-height:44px;padding:12px 0;border-bottom:3px solid transparent;color:#64748b;text-decoration:none;font-size:14px;font-weight:600;transition:border-color .2s ease,color .2s ease}
  .submission-review-tab:hover{border-bottom-color:var(--review-border);color:var(--review-ink)}
  .submission-review-tab[aria-current="page"]{border-bottom-color:var(--review-accent);color:var(--review-ink)}
  .submission-review-hero{margin-bottom:24px;padding:24px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
  .submission-review-hero h2{max-width:700px;margin:0;color:#111827;font-size:clamp(24px,2.6vw,30px);font-weight:700;letter-spacing:-.02em;line-height:1.25;text-wrap:balance}
  .submission-review-hero-copy{max-width:65ch;margin:8px 0 0;color:#64748b;font-size:14px;line-height:1.65}
  .submission-review-grid{display:grid;gap:16px}
  .submission-review-card{scroll-margin-top:90px;background:#fff;border:1px solid #e5e7eb;border-top:3px solid #cbd5e1;border-radius:16px}
  .submission-review-card[data-status="submitted"]{border-top-color:#2563eb}
  .submission-review-card[data-status="approved"]{border-top-color:#16a34a}
  .submission-review-card[data-status="returned"]{border-top-color:#ea580c}
  .submission-review-summary{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px 18px;align-items:center;padding:19px 22px;cursor:pointer;list-style:none}
  .submission-review-summary::-webkit-details-marker{display:none}
  .submission-review-summary::marker{content:""}
  .submission-review-summary:hover{background:#f8fafc;border-radius:14px}
  .submission-review-summary:focus-visible{outline:2px solid var(--review-accent);outline-offset:-4px;border-radius:14px}
  .submission-review-card[open] .submission-review-summary{border-bottom:1px solid #e5e7eb}
  .submission-review-summary-title{min-width:0;color:#111827;font-size:18px;font-weight:700;line-height:1.4;overflow-wrap:anywhere}
  .submission-review-summary-meta{display:flex;flex-wrap:wrap;align-items:center;gap:7px 10px;min-width:0;color:#475569;font-size:14px;line-height:1.5}
  .submission-review-summary-submitted{font-variant-numeric:tabular-nums;font-weight:600}
  .submission-review-summary-type{padding:3px 9px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:12px;font-weight:600}
  .submission-review-summary-action{grid-column:2;grid-row:1/span 2;display:flex;align-items:center;gap:9px;color:var(--review-ink);font-size:13px;font-weight:600;white-space:nowrap}
  .submission-review-summary-action-close{display:none}
  .submission-review-card[open] .submission-review-summary-action-open{display:none}
  .submission-review-card[open] .submission-review-summary-action-close{display:inline}
  .submission-review-summary-icon{display:grid;place-items:center;width:32px;height:32px;border:1px solid var(--review-border);border-radius:8px;background:var(--review-tint);font-size:18px;line-height:1;transition:transform .18s ease}
  .submission-review-card[open] .submission-review-summary-icon{transform:rotate(45deg)}
  .submission-review-detail{padding:0 22px 22px}
  .submission-review-head{display:flex;justify-content:space-between;gap:8px 16px;align-items:center;flex-wrap:wrap;padding-top:16px}
  .submission-review-trail-link{padding:8px 0;color:var(--review-ink);font-size:14px;font-weight:600;text-decoration:none}
  .submission-review-trail-link:hover{text-decoration:underline}
  .submission-review-title{font-size:20px;font-weight:700;color:#111827;margin:0;line-height:1.4}
  .submission-review-meta{display:flex;gap:8px;flex-wrap:wrap;margin:0;color:#64748b;font-size:13px}
  .submission-review-pill{padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#334155}
  .submission-review-status{background:#f1f5f9;color:#475569}
  .submission-review-card[data-status="submitted"] .submission-review-status{background:#eff6ff;color:#1d4ed8}
  .submission-review-card[data-status="approved"] .submission-review-status{background:#dcfce7;color:#166534}
  .submission-review-card[data-status="returned"] .submission-review-status{background:#fff7ed;color:#9a3412}
  .submission-review-section{margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}
  .submission-review-section h3{font-size:15px;margin:0 0 8px;color:#111827}
  .submission-review-section p{white-space:pre-wrap;margin:4px 0;color:#475569}
  .submission-review-document-row{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
  .submission-review-document-name{min-width:0;overflow-wrap:anywhere}
  .submission-review-document-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .submission-review-document-action{display:inline-flex;justify-content:center;align-items:center;min-height:40px;padding:8px 14px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#334155;font:inherit;font-size:13px;font-weight:650;text-decoration:none;transition:background .18s ease,border-color .18s ease,color .18s ease,transform .18s ease}
  .submission-review-document-action:hover{border-color:#f57c00;background:#fff7ed;color:#9a3412;transform:translateY(-1px)}
  .submission-review-document-action--download{border-color:#f57c00;background:#f57c00;color:#fff}
  .submission-review-document-action--download:hover{border-color:#ea580c;background:#ea580c;color:#fff}
  .submission-review-fields{display:grid;gap:16px;margin:16px 0 0}
  .submission-review-field{display:grid;grid-template-columns:minmax(120px,160px) minmax(0,1fr);gap:8px 16px;min-width:0}
  .submission-review-field dt{margin:0;color:#64748b;font-size:13px;font-weight:500}
  .submission-review-field dd{max-width:75ch;margin:0;color:#334155;font-size:14px;line-height:1.65;overflow-wrap:anywhere}
  .submission-review-content{margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}
  .submission-review-content summary{padding:8px 0;color:var(--review-ink);font-size:14px;font-weight:600;cursor:pointer}
  .submission-review-content h3{margin:16px 0 8px;font-size:14px}
  .submission-review-content p{max-width:75ch;color:#475569;line-height:1.7;overflow-wrap:anywhere}
  .submission-review-actions{display:grid;gap:10px;margin-top:18px}
  .title-decision-options{display:grid;gap:9px;margin:4px 0 8px;padding:12px;border:1px solid #d1d5db;border-radius:10px}
  .title-decision-options label{display:flex;align-items:flex-start;gap:8px}
  .title-decision-options input{margin-top:3px;accent-color:#0d9488}
  .submission-review-actions textarea{width:100%;min-height:85px;border:1px solid #d1d5db;border-radius:10px;padding:10px;font:inherit}
  .submission-review-actions button{border:0;border-radius:9px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}
  .submission-review-actions .approve{background:#0d9488;color:#fff}
  .submission-review-actions .return{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
  .submission-annotation-note{margin:2px 0 0;padding:9px 11px;border-left:3px solid #b45309;border-radius:6px;background:#fff7ed;color:#7c2d12;font-size:13px}
  .review-approval-dialog{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);margin:0;width:min(520px,calc(100vw - 32px));max-width:none;padding:0;border:1px solid var(--review-border);border-radius:18px;background:#fff;color:#111827;box-shadow:0 24px 70px rgba(15,23,42,.24)}
  .review-approval-dialog::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(3px)}
  .review-approval-dialog-inner{padding:28px}
  .review-approval-dialog-mark{display:grid;place-items:center;width:44px;height:44px;margin-bottom:18px;border:1px solid var(--review-border);border-radius:13px;background:var(--review-tint);color:var(--review-ink);font-size:21px;font-weight:700}
  .review-approval-dialog h2{margin:0;color:#111827;font-size:22px;line-height:1.3}
  .review-approval-dialog p{margin:10px 0 0;color:#475569;font-size:14px;line-height:1.65}
  .review-approval-dialog-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:9px;margin-top:24px}
  .review-approval-dialog-actions button{min-height:44px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font:inherit;font-size:14px;font-weight:650;cursor:pointer}
  .review-approval-dialog-actions button:hover{background:#f8fafc}
  .review-approval-dialog-actions .review-dialog-approve{border-color:var(--review-accent);background:var(--review-accent);color:#fff}
  .review-approval-dialog-actions .review-dialog-approve:hover{filter:brightness(.94)}
  .review-approval-dialog-actions .review-dialog-return{border-color:var(--review-border);background:var(--review-tint);color:var(--review-ink)}
  .review-approval-dialog :focus-visible{outline:2px solid var(--review-accent);outline-offset:3px}
  @media(max-width:520px){.review-approval-dialog-inner{padding:22px}.review-approval-dialog-actions{display:grid;grid-template-columns:1fr}.review-approval-dialog-actions button{width:100%}}
  .submission-review-empty{min-height:224px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;padding:clamp(24px,4vw,32px);border:1px dashed #cbd5e1;border-radius:16px;background:#fff;color:#64748b}
  .submission-review-empty-mark{display:grid;place-items:center;width:40px;height:40px;margin-bottom:16px;border:1px solid var(--review-border);border-radius:10px;background:var(--review-tint);color:var(--review-ink);font-size:20px}
  .submission-review-empty h3{margin:0;color:#111827;font-size:20px;letter-spacing:0}
  .submission-review-empty p{max-width:58ch;margin:9px 0 0;font-size:14px;line-height:1.7}
  .review-alert{padding:12px 16px;margin:0 0 18px;border-radius:10px}
  .review-alert.success{background:#dcfce7;color:#166534}.review-alert.error{background:#fee2e2;color:#991b1b}
  .submission-review-tab:focus-visible,.submission-review-trail-link:focus-visible,.submission-review-content summary:focus-visible,.submission-review-document-action:focus-visible,.submission-review-actions button:focus-visible,.submission-review-actions textarea:focus-visible{outline:2px solid var(--review-accent);outline-offset:3px}
  @media(max-width:480px){.submission-review-field{grid-template-columns:minmax(0,1fr);gap:4px}}
  @media(max-width:700px){.submission-review-summary{gap:7px 10px;padding:16px}.submission-review-summary-title{font-size:16px}.submission-review-summary-action{gap:6px;font-size:12px}.submission-review-summary-action-label{display:none}.submission-review-detail{padding:0 16px 18px}.submission-review-tabs{gap:4px 16px}.submission-review-tab{min-width:0;font-size:13px}.submission-review-hero{padding:24px}.submission-review-empty h3{font-size:18px}}
  @media(prefers-reduced-motion:reduce){.submission-review-tab,.submission-review-summary-icon,.submission-review-document-action{transition:none}}
</style>

<div class="submission-review-workspace">
<?php if ($section): ?>
  <nav class="submission-review-tabs" aria-label="Research development sections">
    <?php foreach ($review_tabs as $key => $tab): ?>
      <a class="submission-review-tab" href="<?php echo SITE_URL; ?>pages/shared/research-submission-review.php?module_page=<?php echo rawurlencode($tab['module']); ?>&amp;view=<?php echo rawurlencode($key); ?>&amp;course=<?php echo rawurlencode($course_filter); ?>"<?php echo $active_tab === $key ? ' aria-current="page"' : ''; ?>>
        <?php echo htmlspecialchars($tab['title'], ENT_QUOTES, 'UTF-8'); ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$is_approval_list): ?>
  <section class="submission-review-hero" aria-labelledby="submission-review-heading">
    <div>
      <h2 id="submission-review-heading"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="submission-review-hero-copy"><?php echo htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>

<form class="submission-course-filter" method="get" action="<?php echo SITE_URL; ?>pages/shared/research-submission-review.php">
  <input type="hidden" name="module_page" value="<?php echo htmlspecialchars($module_page, ENT_QUOTES, 'UTF-8'); ?>">
  <input type="hidden" name="view" value="<?php echo htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8'); ?>">
  <?php if (!$section): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
  <?php if ($is_approval_list): ?><input type="hidden" name="q" value="<?php echo htmlspecialchars($approval_list['search'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
  <label for="review-course">Course</label>
  <select id="review-course" name="course">
    <option value="">All courses</option>
    <?php foreach (['BSIT', 'BSCS'] as $course): ?>
      <option value="<?php echo $course; ?>"<?php echo $course_filter === $course ? ' selected' : ''; ?>><?php echo $course; ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit">Apply filter</button>
</form>

<?php if ($flash['success'] !== ''): ?><div class="review-alert success"><?php echo htmlspecialchars($flash['success'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($flash['error'] !== ''): ?><div class="review-alert error"><?php echo htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<?php if ($is_approval_list): ?>
  <?php require __DIR__ . '/../../includes/research-approval-view.php'; ?>
<?php else: ?>
<div class="submission-review-grid">
  <?php if (!$items): ?>
    <section class="submission-review-empty" aria-live="polite">
      <span class="submission-review-empty-mark" aria-hidden="true"><?php echo $section && $section['accent'] === 'approval' ? '✓' : ($section && $section['accent'] === 'review' ? '⌕' : '▤'); ?></span>
      <h3><?php echo htmlspecialchars($section['empty_title'] ?? 'No title or chapter submissions are available yet', ENT_QUOTES, 'UTF-8'); ?></h3>
      <p><?php echo htmlspecialchars($section['empty_description'] ?? 'Submitted title and chapter versions will appear here with their review history.', ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
  <?php endif; ?>
  <?php foreach ($items as $item): ?>
    <?php
      $review_title_options = [];
      if ($item['submission_type'] === 'title') {
          $raw_title_options = $item['snapshot']['title_options'] ?? null;
          if (is_array($raw_title_options)) {
              $review_title_options = array_values(array_filter(array_map(static function ($title): string {
                  return is_string($title) ? trim($title) : '';
              }, $raw_title_options), static function (string $title): bool {
                  return $title !== '';
              }));
          } elseif (is_string($item['snapshot']['title'] ?? null) && trim($item['snapshot']['title']) !== '') {
              $review_title_options = [trim($item['snapshot']['title'])];
          }
      }
      $review_area_names = [];
      if (is_array($item['snapshot']['research_areas'] ?? null)) {
          foreach ($item['snapshot']['research_areas'] as $area) {
              if (is_array($area) && is_string($area['area_name'] ?? null) && trim($area['area_name']) !== '') {
                  $review_area_names[] = trim($area['area_name']);
              }
          }
      }
      $display_snapshot = [];
      $normalize_review_title = static function (string $title): string {
          return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title), 'UTF-8');
      };
      if ($item['submission_type'] === 'title') {
          // Pending approvals already show all title choices in the decision form.
          if (!($item['status'] === 'submitted' && $can_approve)) {
              $other_titles = [];
              $seen_titles = [$normalize_review_title($item['title']) => true];
              foreach ($review_title_options as $option_title) {
                  $title_key = $normalize_review_title($option_title);
                  if (!isset($seen_titles[$title_key])) {
                      $other_titles[] = $option_title;
                      $seen_titles[$title_key] = true;
                  }
              }
              if ($other_titles) {
                  $display_snapshot['other_proposed_titles'] = implode("\n", $other_titles);
              }
          }
          if (is_string($item['snapshot']['category_name'] ?? null) && trim($item['snapshot']['category_name']) !== '') {
              $display_snapshot['research_category'] = trim($item['snapshot']['category_name']);
          }
          if (is_array($item['snapshot']['title_classifications'] ?? null)) {
              $classification_lines = [];
              foreach ($item['snapshot']['title_classifications'] as $classification) {
                  if (!is_array($classification)) continue;
                  $option_number = (int) ($classification['title_option_number'] ?? 0);
                  $category_label = trim((string) ($classification['category_name'] ?? ''));
                  $area_label = trim((string) ($classification['area_name'] ?? ''));
                  if ($option_number >= 1 && $option_number <= 3 && ($category_label !== '' || $area_label !== '')) {
                      $classification_lines[] = 'Title option ' . $option_number . ': ' . $category_label . ($area_label !== '' ? ' — ' . $area_label : '');
                  }
              }
              if ($classification_lines) {
                  unset($display_snapshot['research_category']);
                  unset($display_snapshot['research_areas']);
                  $display_snapshot['title_classifications'] = implode("\n", $classification_lines);
              }
          }
          if ($review_area_names) {
              $display_snapshot['research_areas'] = implode(', ', $review_area_names);
          }
      }
      if ($item['submission_type'] === 'chapter') {
          if (is_string($item['snapshot']['chapter_title'] ?? null) && trim($item['snapshot']['chapter_title']) !== '') {
              $display_snapshot['chapter_title'] = trim($item['snapshot']['chapter_title']);
          }
      }
      if ($item['submission_type'] === 'final_manuscript'
          && is_string($item['snapshot']['abstract'] ?? null)) {
          $display_snapshot['abstract'] = $item['snapshot']['abstract'];
      }
      $submitted_content = is_array($item['snapshot']['content'] ?? null)
          ? array_filter($item['snapshot']['content'], static function ($value): bool {
              return is_string($value) && trim($value) !== '';
          })
          : [];
      $legacy_import = $item['snapshot']['legacy_import'] ?? [];
      $is_legacy_import = is_array($legacy_import) && !empty($legacy_import['imported']);
      $legacy_project_created_at = '';
      if ($is_legacy_import) {
          foreach (['approximate_project_created_at', 'approximate_project_creation_date', 'project_created_at', 'project_creation_date', 'approximate_creation_date'] as $date_key) {
              if (isset($legacy_import[$date_key]) && is_scalar($legacy_import[$date_key]) && trim((string) $legacy_import[$date_key]) !== '') {
                  $legacy_project_created_at = trim((string) $legacy_import[$date_key]);
                  break;
              }
          }
          if ($legacy_project_created_at === '') {
              $legacy_project_created_at = 'Unavailable';
          }
      }
      $legacy_project_created_timestamp = $is_legacy_import ? strtotime($legacy_project_created_at) : false;
      $legacy_project_created_label = preg_match('/^\d{4}$/', $legacy_project_created_at)
          ? $legacy_project_created_at
          : ($legacy_project_created_timestamp !== false
          ? date('M j, Y', $legacy_project_created_timestamp)
          : $legacy_project_created_at);
    ?>
    <details id="submission-<?php echo (int) $item['version_id']; ?>" class="submission-review-card" data-status="<?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?>">
      <summary class="submission-review-summary">
        <span class="submission-review-summary-title" role="heading" aria-level="3"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="submission-review-summary-meta">
          <?php if ($is_legacy_import): ?>
            <span>Imported legacy record · Original submission/reviewer details unavailable</span>
          <?php else: ?>
            <span>Submitted by <?php echo htmlspecialchars($item['submitter_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(ucfirst($item['submitter_role']), ENT_QUOTES, 'UTF-8'); ?>)</span>
          <?php endif; ?>
          <?php if (!$is_legacy_import): ?>
            <span class="submission-review-summary-course">Course: <?php echo htmlspecialchars($item['submitter_program'] !== '' ? $item['submitter_program'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
          <?php if ($is_legacy_import): ?>
            <span class="submission-review-summary-submitted">Approximate project-creation date <?php echo htmlspecialchars($legacy_project_created_label, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php else: ?>
            <span class="submission-review-summary-submitted">Submitted <time datetime="<?php echo htmlspecialchars(date(DATE_ATOM, strtotime($item['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($item['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?></time></span>
          <?php endif; ?>
          <span class="submission-review-summary-type"><?php
            if ($item['submission_type'] === 'title') {
                echo 'Research Title';
            } elseif ($item['submission_type'] === 'final_manuscript') {
                echo 'Complete Research Manuscript';
            } else {
                echo 'Chapter ' . (int) $item['chapter_number'];
            }
          ?></span>
        </span>
        <span class="submission-review-summary-action"><span class="submission-review-summary-action-label"><span class="submission-review-summary-action-open">View details</span><span class="submission-review-summary-action-close">Hide details</span></span><span class="submission-review-summary-icon" aria-hidden="true">+</span></span>
      </summary>
      <div class="submission-review-detail">
        <div class="submission-review-head">
          <div class="submission-review-meta">
            <span class="submission-review-pill">Version <?php echo (int) $item['version_number']; ?></span>
            <span class="submission-review-pill submission-review-status"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $item['status'])), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <a class="submission-review-trail-link" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $item['project_id']; ?>#research-trail">Research trail &rarr;</a>
        </div>
      <?php if ($item['uploads']): ?>
        <div class="submission-review-section">
          <h3><?php
            if ($item['submission_type'] === 'title') {
                echo 'Title proposal document';
            } elseif ($item['submission_type'] === 'final_manuscript') {
                echo 'Complete research manuscript';
            } else {
                echo 'Chapter document';
            }
          ?></h3>
          <?php foreach ($item['uploads'] as $upload): ?>
            <?php
              $document_extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
              $document_viewer_url = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $upload['id'] . '&mode=viewer';
              $document_download_url = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . (int) $upload['id'] . '&mode=download';
              $document_viewer_label = $document_extension === 'docx' ? 'Open DOCX options' : 'View full document';
            ?>
            <div class="submission-review-document-row">
              <p class="submission-review-document-name">📄 <?php echo htmlspecialchars($upload['name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo number_format($upload['size'] / 1024, 1); ?> KB</p>
              <div class="submission-review-document-actions">
                <a class="submission-review-document-action" href="<?php echo htmlspecialchars($document_viewer_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($document_viewer_label, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php if ($can_download_submission_documents): ?>
                  <a class="submission-review-document-action submission-review-document-action--download" href="<?php echo htmlspecialchars($document_download_url, ENT_QUOTES, 'UTF-8'); ?>">Download</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="submission-review-section">
          <h3>Submission document</h3>
          <p>No document is attached to this preserved version.</p>
        </div>
      <?php endif; ?>
      <?php if ($display_snapshot || $is_legacy_import): ?>
      <div class="submission-review-section">
        <h3>Submission details</h3>
        <?php if ($is_legacy_import): ?>
          <p><strong>Imported legacy record.</strong> Original submission and reviewer details are unavailable. The date shown is an approximate project-creation date, not an actual submission time.</p>
        <?php endif; ?>
        <dl class="submission-review-fields">
          <?php foreach ($display_snapshot as $key => $value): ?>
            <div class="submission-review-field">
              <dt><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key)), ENT_QUOTES, 'UTF-8'); ?></dt>
              <dd><?php echo nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')); ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>
      <?php endif; ?>
      <?php if ($item['latest_comments'] !== '' || $item['latest_recommendation'] !== ''): ?>
        <div class="submission-review-section">
          <h3>Latest feedback</h3>
          <?php $latest_feedback = implode("\n", array_filter([$item['latest_comments'], $item['latest_recommendation']], static fn ($feedback) => $feedback !== '')); ?>
          <?php if ($latest_feedback !== ''): ?><p><strong>Comments/Suggestions</strong><br><?php echo nl2br(htmlspecialchars($latest_feedback, ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
          <?php if ($item['latest_selected_title'] !== '' && $normalize_review_title($item['latest_selected_title']) !== $normalize_review_title($item['title'])): ?><p><strong>Approved title</strong><br><?php echo htmlspecialchars($item['latest_selected_title'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($submitted_content): ?>
        <details class="submission-review-content">
          <summary>Read submitted chapter content</summary>
          <?php foreach ($submitted_content as $field => $text): ?>
            <h3><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $field)), ENT_QUOTES, 'UTF-8'); ?></h3>
            <p><?php echo nl2br(htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8')); ?></p>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <?php if ($item['status'] === 'submitted' && ($can_return || $can_approve)): ?>
        <?php $item_has_annotations = (int) ($item['annotation_count'] ?? 0) > 0; ?>
        <form method="post" class="submission-review-actions">
          <?php echo csrfField(); ?>
          <input type="hidden" name="version_id" value="<?php echo (int) $item['version_id']; ?>">
          <?php if ($item['submission_type'] === 'title'): ?>
            <?php if ($can_approve && !$item_has_annotations): ?>
              <fieldset class="title-decision-options">
                <legend>Select one title to approve</legend>
                <?php foreach ($review_title_options as $option_index => $option_title): ?>
                  <?php
                    $option_classification = null;
                    foreach (($item['snapshot']['title_classifications'] ?? []) as $classification) {
                        if ((int) ($classification['title_option_number'] ?? 0) === $option_index + 1) {
                            $option_classification = $classification;
                            break;
                        }
                    }
                  ?>
                  <label>
                    <input type="radio" name="selected_title" value="<?php echo htmlspecialchars($option_title, ENT_QUOTES, 'UTF-8'); ?>"
                      <?php echo (string) ($_POST['selected_title'] ?? '') === $option_title ? 'checked' : ''; ?>>
                    Title option <?php echo $option_index + 1; ?>: <?php echo htmlspecialchars($option_title, ENT_QUOTES, 'UTF-8'); ?><?php if (is_array($option_classification)): ?> <span><?php echo htmlspecialchars((string) ($option_classification['category_name'] ?? '') . ' — ' . (string) ($option_classification['area_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                  </label>
                <?php endforeach; ?>
                <?php if ($can_return): ?>
                  <label>
                    <input type="radio" name="selected_title" value=""
                      <?php echo trim((string) ($_POST['selected_title'] ?? '')) === '' ? 'checked' : ''; ?>>
                    None — return for revision
                  </label>
                <?php endif; ?>
              </fieldset>
            <?php endif; ?>
          <?php endif; ?>
          <label for="comments-<?php echo (int) $item['version_id']; ?>">Comments/Suggestions <?php echo $can_return ? '(required when returning; optional when approving)' : '(optional)'; ?></label>
          <textarea id="comments-<?php echo (int) $item['version_id']; ?>" name="comments" maxlength="5000"><?php echo htmlspecialchars((string) ($_POST['comments'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if ($can_approve && !$item_has_annotations): ?><button class="approve" type="submit" name="decision" value="approved">Approve</button><?php endif; ?>
            <?php if ($can_return): ?><button class="return" type="submit" name="decision" value="returned">Return for Revision</button><?php endif; ?>
          </div>
          <?php if ($item_has_annotations): ?><p class="submission-annotation-note"><?php if ($can_return): ?>This version has document annotations. Return it for revision before it can be approved.<?php else: ?>This version has document annotations and cannot be approved. Ask a reviewer with return permission to return it for revision.<?php endif; ?></p><?php endif; ?>
        </form>
      <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
</div>
<dialog class="review-approval-dialog" aria-labelledby="review-approval-dialog-title" aria-describedby="review-approval-dialog-description">
  <div class="review-approval-dialog-inner">
    <div class="review-approval-dialog-mark" aria-hidden="true">?</div>
    <h2 id="review-approval-dialog-title">Approve with feedback?</h2>
    <p id="review-approval-dialog-description">You entered review feedback. Approval advances this submission. If the feedback describes changes required before it can advance, return it for revision instead.</p>
    <div class="review-approval-dialog-actions">
      <button type="button" class="review-dialog-cancel">Cancel</button>
      <button type="button" class="review-dialog-return">Return for Revision</button>
      <button type="button" class="review-dialog-approve">Approve with Guidance</button>
    </div>
  </div>
</dialog>
<script>
(() => {
  document.querySelectorAll('.submission-review-recent-title').forEach((link) => {
    link.addEventListener('click', () => {
      const target = document.getElementById(link.hash.slice(1));
      if (target && target.tagName === 'DETAILS') target.open = true;
    });
  });

  const dialog = document.querySelector('.review-approval-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;

  let activeForm = null;
  let approveButton = null;
  let returnButton = null;

  document.querySelectorAll('.submission-review-actions').forEach((form) => {
    const approve = form.querySelector('button[name="decision"][value="approved"]');
    if (!approve) return;

    approve.addEventListener('click', (event) => {
      const feedback = [...form.querySelectorAll('textarea[name="comments"]')]
        .some((field) => field.value.trim() !== '');
      if (!feedback) return;

      event.preventDefault();
      activeForm = form;
      approveButton = approve;
      returnButton = form.querySelector('button[name="decision"][value="returned"]');
      dialog.querySelector('.review-dialog-return').hidden = !returnButton;
      dialog.showModal();
      dialog.querySelector('.review-dialog-cancel').focus();
    });
  });

  dialog.querySelector('.review-dialog-cancel').addEventListener('click', () => dialog.close());
  dialog.querySelector('.review-dialog-approve').addEventListener('click', () => {
    if (activeForm && approveButton) activeForm.requestSubmit(approveButton);
    dialog.close();
  });
  dialog.querySelector('.review-dialog-return').addEventListener('click', () => {
    if (activeForm && returnButton) {
      const noTitle = activeForm.querySelector('input[name="selected_title"][value=""]');
      if (noTitle) noTitle.checked = true;
      activeForm.requestSubmit(returnButton);
    }
    dialog.close();
  });
  dialog.addEventListener('close', () => {
    if (approveButton) approveButton.focus();
    activeForm = null;
    approveButton = null;
    returnButton = null;
  });
})();
</script>
<?php endif; ?>
</div>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
