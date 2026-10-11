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
$type_filter = in_array((string) ($_GET['type'] ?? ''), ['title', 'chapter'], true)
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
        'title' => 'Research Proposals',
        'eyebrow' => 'TITLE INTAKE',
        'description' => 'A dedicated view of researcher-submitted titles, their supporting documents, and their review history.',
        'stage' => 1,
        'empty_title' => 'No versioned title submissions to display',
        'empty_description' => 'This page lists submissions preserved by the current review workflow. Older project records are not added to that history automatically and remain available in Research Management.',
        'guide_title' => 'What belongs in this section',
        'guide_description' => 'Includes title submissions, preserved versions, and proposal files when attached.',
        'guide_items' => ['Research title and abstract', 'Research area and category', 'Proposal file, when provided'],
        'next_title' => 'Looking for an older record?',
        'next_description' => 'Open Research Management to view submitted project records that predate the versioned review history.',
        'next_href' => SITE_URL . 'pages/admin/admin-research.php?status=submitted',
        'next_label' => 'Open submitted research records',
        'type' => 'title',
        'status' => '',
        'accent' => 'proposals',
    ],
    'research_review' => [
        'title' => 'Research Review',
        'eyebrow' => 'AWAITING A DECISION',
        'description' => 'Review the latest title and chapter versions that are awaiting an authorized decision.',
        'stage' => 2,
        'empty_title' => 'No submissions are waiting for review',
        'empty_description' => 'Submitted versions appear here until an authorized reviewer approves them or returns them with recommendations.',
        'guide_title' => 'Review checklist',
        'guide_description' => 'Only the latest submitted version is shown; decisions apply to that locked version.',
        'guide_items' => ['Read the locked submitted version', 'Check any attached supporting documents', 'Approve or return with clear recommendations'],
        'next_title' => 'Review outcome',
        'next_description' => 'Approved versions are kept in Research Approval with their decision history.',
        'next_module' => 'research_approval',
        'next_label' => 'View Research Approval',
        'type' => '',
        'status' => 'submitted',
        'accent' => 'review',
    ],
    'research_approval' => [
        'title' => 'Research Approval',
        'eyebrow' => 'APPROVED SUBMISSIONS',
        'description' => 'A record of title and chapter versions approved by an administrator or authorized staff member.',
        'stage' => 3,
        'empty_title' => 'No submissions have been approved yet',
        'empty_description' => 'Once a reviewer approves a submitted title or chapter, its preserved version and review history will be listed here.',
        'guide_title' => 'Approval records include',
        'guide_description' => 'Approved snapshots stay available with reviewer identity and earlier revision history.',
        'guide_items' => ['The exact version that was approved', 'Who submitted it and when', 'Reviewer decision and submission history'],
        'next_title' => 'Nothing to approve here?',
        'next_description' => 'Submissions still awaiting a decision are listed in Research Review.',
        'next_module' => 'research_review',
        'next_label' => 'Open Research Review',
        'type' => '',
        'status' => 'approved',
        'accent' => 'approval',
    ],
];
$section = $section_config[$module_page] ?? null;
$page_title = $section['title'] ?? 'Research Submission Review';
$type_filter = $section['type'] ?? $type_filter;
$status_filter = $section['status'] ?? '';

$flash = ['success' => '', 'error' => ''];
if (!empty($_SESSION['module_error'])) {
    $flash['error'] = (string) $_SESSION['module_error'];
    unset($_SESSION['module_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $flash['error'] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $decision = (string) ($_POST['decision'] ?? '');
        try {
            $version = rms_review_submission(
                $conn,
                (int) ($_POST['version_id'] ?? 0),
                $user_id,
                $decision,
                trim((string) ($_POST['comments'] ?? ''))
            );
            $project_id = (int) $version['project_id'];
            $researcher_id = (int) $version['created_by'];
            $kind = $version['submission_type'] === 'title'
                ? 'research title'
                : 'Chapter ' . (int) $version['chapter_number'];
            $decision_text = $decision === 'approved' ? 'approved' : 'returned for revision';
            createNotification(
                $researcher_id,
                ucfirst($kind) . ' ' . $decision_text,
                'Your ' . $kind . ' submission for "' . (string) $version['title'] . '" was ' . $decision_text
                    . ($decision === 'returned' ? '. Review comments: ' . trim((string) $_POST['comments']) : '.'),
                $decision === 'approved' ? 'success' : 'warning',
                SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id
            );
            logActivity(
                ucfirst($kind) . ' submission ' . $decision_text . ' for project #' . $project_id,
                'research_submission_review'
            );
            $flash['success'] = ucfirst($kind) . ' ' . $decision_text . '.';
        } catch (Throwable $exception) {
            $flash['error'] = $exception->getMessage();
        }
    }
}

$sql = "
    SELECT v.version_id, v.project_id, v.chapter_id, v.submission_type, v.version_number,
           v.submitted_by, v.submitted_at, v.status, v.snapshot_json,
           rp.title, rp.created_by, researcher.role AS researcher_role,
           CONCAT(researcher.first_name, ' ', researcher.last_name) AS researcher_name,
           researcher.program AS researcher_program,
           ch.chapter_number,
           latest_review.comments AS latest_comments,
           latest_review.decision AS latest_decision,
           latest_review.reviewed_at AS latest_reviewed_at,
           u.upload_id, u.original_name, u.file_size
      FROM research_submission_versions v
      JOIN research_projects rp ON rp.project_id = v.project_id
      JOIN users researcher ON researcher.user_id = rp.created_by
 LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
 LEFT JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id
 LEFT JOIN uploads u ON u.upload_id = vu.upload_id
 LEFT JOIN research_submission_reviews latest_review
        ON latest_review.review_id = (
            SELECT r.review_id FROM research_submission_reviews r
             WHERE r.version_id = v.version_id
             ORDER BY r.reviewed_at DESC, r.review_id DESC LIMIT 1
        )
     WHERE NOT EXISTS (
        SELECT 1 FROM research_submission_versions newer
         WHERE newer.project_id = v.project_id
           AND newer.submission_type = v.submission_type
           AND newer.chapter_id <=> v.chapter_id
           AND newer.version_number > v.version_number
     )
       AND (? = '' OR v.submission_type = ?)
       AND (? = '' OR v.status = ?)
  ORDER BY v.submitted_at DESC, v.version_id DESC
     LIMIT 100";
$queue_stmt = $conn->prepare($sql);
if (!$queue_stmt) {
    throw new RuntimeException('Unable to prepare research submission queue: ' . $conn->error);
}
$queue_stmt->bind_param('ssss', $type_filter, $type_filter, $status_filter, $status_filter);
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
            'created_by' => (int) $row['created_by'],
            'researcher_name' => (string) $row['researcher_name'],
            'researcher_role' => (string) $row['researcher_role'],
            'researcher_program' => trim((string) ($row['researcher_program'] ?? '')),
            'chapter_number' => $row['chapter_number'] !== null ? (int) $row['chapter_number'] : null,
            'latest_comments' => (string) ($row['latest_comments'] ?? ''),
            'latest_decision' => (string) ($row['latest_decision'] ?? ''),
            'latest_reviewed_at' => (string) ($row['latest_reviewed_at'] ?? ''),
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'uploads' => [],
            'history' => []
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

foreach ($items as &$item) {
    $chapterCondition = $item['chapter_id'] === null ? 'IS NULL' : '= ?';
    $historyStmt = $conn->prepare(
        'SELECT v.version_number, v.status, v.submitted_at, r.decision, r.comments, r.reviewed_at,
                CONCAT(reviewer.first_name, " ", reviewer.last_name) AS reviewer_name
           FROM research_submission_versions v
      LEFT JOIN research_submission_reviews r ON r.version_id = v.version_id
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewer_id
          WHERE v.project_id = ? AND v.submission_type = ? AND v.chapter_id ' . $chapterCondition . '
          ORDER BY v.version_number DESC, r.reviewed_at DESC, r.review_id DESC'
    );
    if (!$historyStmt) {
        throw new RuntimeException('Unable to load review history.');
    }
    if ($item['chapter_id'] === null) {
        $historyStmt->bind_param('is', $item['project_id'], $item['submission_type']);
    } else {
        $historyStmt->bind_param('isi', $item['project_id'], $item['submission_type'], $item['chapter_id']);
    }
    if (!$historyStmt->execute()) {
        throw new RuntimeException('Unable to retrieve submission history.');
    }
    $historyResult = $historyStmt->get_result();
    while ($history = $historyResult->fetch_assoc()) {
        $item['history'][] = $history;
    }
    $historyStmt->close();
}
unset($item);

$review_counts = ['total' => count($items), 'titles' => 0, 'chapters' => 0, 'waiting' => 0];
foreach ($items as $item) {
  if ($item['submission_type'] === 'title') {
    $review_counts['titles']++;
  } else {
    $review_counts['chapters']++;
  }
  if ($item['status'] === 'submitted') {
    $review_counts['waiting']++;
  }
}
$recent_submissions = array_slice(array_values($items), 0, 4);

if ($role === 'admin') {
    renderAdminShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions separately.');
} else {
    renderStaffShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions according to your assigned permissions.');
}
?>
<style>
  .submission-review-workspace{max-width:1440px;margin:0 auto;padding-bottom:32px;container:review / inline-size}
  .submission-review-tabs{display:flex;gap:8px;overflow-x:auto;margin:0 0 22px;padding:4px 2px 10px}
  .submission-review-tab{display:flex;align-items:center;gap:9px;flex:0 0 auto;padding:11px 15px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;color:#475569;text-decoration:none;font-size:13px;font-weight:600;transition:background .2s ease,border-color .2s ease,color .2s ease,transform .2s ease}
  .submission-review-tab:hover{border-color:#fdba74;color:#9a3412;transform:translateY(-1px)}
  .submission-review-tab[aria-current="page"]{border-color:#fed7aa;background:#fff7ed;color:#9a3412;box-shadow:0 5px 16px rgba(154,52,18,.08)}
  .submission-review-tab-index{display:grid;place-items:center;width:23px;height:23px;border-radius:7px;background:#f1f5f9;color:#64748b;font-size:11px}
  .submission-review-tab[aria-current="page"] .submission-review-tab-index{background:#ea580c;color:#fff}
  .submission-review-hero{position:relative;display:grid;grid-template-columns:minmax(0,1fr);gap:22px;overflow:hidden;margin-bottom:22px;padding:28px 32px;border:1px solid #e5e7eb;border-radius:20px;background:radial-gradient(ellipse at 90% 10%,rgba(245,124,0,.09),transparent 44%),#fff;box-shadow:0 10px 28px rgba(30,41,59,.04)}
  .submission-review-hero:before{position:absolute;inset:0 auto 0 0;width:4px;background:#f57c00;content:""}
  .submission-review-eyebrow{margin:0 0 12px;color:#c2410c;font-size:11px;font-weight:700;letter-spacing:.14em}
  .submission-review-hero h2{max-width:700px;margin:0;color:#111827;font-size:clamp(25px,3.2vw,38px);font-weight:700;letter-spacing:0;line-height:1.13;text-wrap:balance}
  .submission-review-hero-copy{max-width:62ch;margin:14px 0 0;color:#64748b;font-size:14px;line-height:1.7}
  .submission-review-stage{display:flex;align-items:center;gap:18px;padding-top:17px;border-top:1px solid #e2e8f0}
  .submission-review-stage-label{flex:0 0 132px;margin:0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase}
  .submission-review-stage ol{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;flex:1;margin:0;padding:0;list-style:none}
  .submission-review-stage li{display:flex;align-items:center;gap:9px;min-width:0;color:#64748b;font-size:12px;line-height:1.35}
  .submission-review-stage li span{display:grid;place-items:center;flex:0 0 29px;height:29px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc;color:#64748b;font-size:10px;font-weight:700}
  .submission-review-stage li.is-complete span{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
  .submission-review-stage li[aria-current="step"]{color:#9a3412;font-weight:700}
  .submission-review-stage li[aria-current="step"] span{border-color:#ea580c;background:#ea580c;color:#fff}
  .submission-review-section-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}
  .submission-review-grid{display:grid;gap:20px}
  .submission-review-card{scroll-margin-top:90px;background:#fff;border:1px solid #e5e7eb;border-top:3px solid #cbd5e1;border-radius:12px}
  .submission-review-card[data-status="submitted"]{border-top-color:#2563eb}
  .submission-review-card[data-status="approved"]{border-top-color:#16a34a}
  .submission-review-card[data-status="returned"]{border-top-color:#ea580c}
  .submission-review-summary{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px 18px;align-items:center;padding:19px 22px;cursor:pointer;list-style:none}
  .submission-review-summary::-webkit-details-marker{display:none}
  .submission-review-summary::marker{content:""}
  .submission-review-summary:focus-visible{outline:2px solid #f57c00;outline-offset:-4px;border-radius:10px}
  .submission-review-card[open] .submission-review-summary{border-bottom:1px solid #e5e7eb}
  .submission-review-summary-title{min-width:0;color:#111827;font-size:18px;font-weight:700;line-height:1.4;overflow-wrap:anywhere}
  .submission-review-summary-meta{display:flex;flex-wrap:wrap;align-items:center;gap:7px 10px;min-width:0;color:#475569;font-size:14px;line-height:1.5}
  .submission-review-summary-type{padding:3px 9px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:12px;font-weight:600}
  .submission-review-summary-action{grid-column:2;grid-row:1/span 2;display:flex;align-items:center;gap:9px;color:#9a3412;font-size:13px;font-weight:700;white-space:nowrap}
  .submission-review-summary-action-close{display:none}
  .submission-review-card[open] .submission-review-summary-action-open{display:none}
  .submission-review-card[open] .submission-review-summary-action-close{display:inline}
  .submission-review-summary-icon{display:grid;place-items:center;width:28px;height:28px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;font-size:18px;line-height:1;transition:transform .18s ease}
  .submission-review-card[open] .submission-review-summary-icon{transform:rotate(45deg)}
  .submission-review-detail{padding:0 22px 22px}
  .submission-review-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
  .submission-review-title{font-size:20px;font-weight:700;color:#111827;margin:0;line-height:1.4}
  .submission-review-meta{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;color:#64748b;font-size:13px}
  .submission-review-pill{padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#334155}
  .submission-review-status{background:#f1f5f9;color:#475569}
  .submission-review-card[data-status="submitted"] .submission-review-status{background:#eff6ff;color:#1d4ed8}
  .submission-review-card[data-status="approved"] .submission-review-status{background:#dcfce7;color:#166534}
  .submission-review-card[data-status="returned"] .submission-review-status{background:#fff7ed;color:#9a3412}
  .submission-review-section{margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}
  .submission-review-section h3{font-size:15px;margin:0 0 8px;color:#111827}
  .submission-review-section p{white-space:pre-wrap;margin:4px 0;color:#475569}
  .submission-review-fields{display:grid;align-items:start;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:10px}
  .submission-review-field{min-width:0;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc}
  .submission-review-field--long{grid-column:1/-1}
  .submission-review-field dt{margin:0 0 6px;color:#475569;font-size:13px;font-weight:700;text-transform:uppercase}
  .submission-review-field dd{max-width:85ch;margin:0;color:#334155;font-size:16px;line-height:1.65;overflow-wrap:anywhere}
  .submission-review-field dd p{margin:0}
  .submission-review-field dd p+p{margin-top:12px}
  .submission-review-field--long dd p{text-align:justify;text-justify:inter-word}
  .submission-review-subheading{margin-top:18px!important}
  .submission-review-actions{display:grid;gap:10px;margin-top:18px}
  .submission-review-actions textarea{width:100%;min-height:85px;border:1px solid #d1d5db;border-radius:10px;padding:10px;font:inherit}
  .submission-review-actions button{border:0;border-radius:9px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}
  .submission-review-actions .approve{background:#0d9488;color:#fff}
  .submission-review-actions .return{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
  .submission-review-empty{min-height:255px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;padding:clamp(24px,4vw,38px);border:1px dashed #cbd5e1;border-radius:16px;background:linear-gradient(135deg,#fff 0%,#f8fafc 100%);color:#64748b}
  .submission-review-empty-mark{display:grid;place-items:center;width:48px;height:48px;margin-bottom:20px;border:1px solid #fed7aa;border-radius:15px;background:#fff7ed;color:#c2410c;font-size:22px}
  .submission-review-empty h3{margin:0;color:#111827;font-size:20px;letter-spacing:0}
  .submission-review-empty p{max-width:58ch;margin:9px 0 0;font-size:14px;line-height:1.7}
  .submission-review-guide{position:static;display:grid;gap:18px;align-content:start;padding:20px;border:1px solid #e5e7eb;border-top:3px solid #f57c00;border-radius:16px;background:#fff;box-shadow:0 8px 22px rgba(30,41,59,.045)}
  .submission-review-rail-section~.submission-review-rail-section{padding-top:17px;border-top:1px solid #e5e7eb}
  .submission-review-guide h3{margin:0;color:#111827;font-size:16px}
  .submission-review-guide p{margin:7px 0 0;color:#475569;font-size:14px;line-height:1.6}
  .submission-review-counts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;margin:14px 0 0}
  .submission-review-counts div{padding:10px 0;border-top:1px solid #e5e7eb}
  .submission-review-counts dt{color:#64748b;font-size:12px;line-height:1.4}
  .submission-review-counts dd{margin:3px 0 0;color:#111827;font-size:22px;font-weight:700;line-height:1.2}
  .submission-review-recent{display:grid;gap:12px;margin:14px 0 0;padding:0;list-style:none}
  .submission-review-recent li{min-width:0;padding-bottom:12px;border-bottom:1px solid #e5e7eb}
  .submission-review-recent li:last-child{padding-bottom:0;border-bottom:0}
  .submission-review-recent-title{display:block;color:#1f2937;font-size:14px;font-weight:650;line-height:1.45;overflow-wrap:anywhere}
  .submission-review-recent-title{color:#1f2937;text-decoration:none}
  .submission-review-recent-title:hover{text-decoration:underline;text-decoration-color:#c2410c;text-underline-offset:3px}
  .submission-review-recent-title:focus-visible{border-radius:2px;outline:2px solid #f57c00;outline-offset:3px}
  .submission-review-recent-meta{display:flex;align-items:center;flex-wrap:wrap;gap:6px 8px;margin-top:7px;color:#64748b;font-size:12px;line-height:1.5}
  .submission-review-recent-meta span{white-space:nowrap}
  .submission-review-recent-meta span:first-child{color:#475569}
  .submission-review-recent-meta span:last-child{margin-left:auto}
  .submission-review-recent-status{padding:2px 7px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:700}
  .submission-review-recent-status[data-status="approved"]{background:#dcfce7;color:#166534}
  .submission-review-recent-status[data-status="submitted"]{background:#eff6ff;color:#1d4ed8}
  .submission-review-recent-status[data-status="returned"]{background:#fff7ed;color:#9a3412}
  .submission-review-guide ul{display:grid;gap:10px;margin:12px 0 0;padding:0;list-style:none}
  .submission-review-guide li{display:flex;gap:10px;color:#334155;font-size:14px;line-height:1.55}
  .submission-review-guide ul>li:before{flex:0 0 auto;color:#c2410c;font-weight:700;content:"✓"}
  .submission-review-guide-link{display:inline-flex;align-items:center;gap:8px;margin-top:20px;color:#9a3412;font-size:13px;font-weight:700;text-decoration:none}
  .submission-review-guide-link:hover{text-decoration:underline}
  .review-alert{padding:12px 16px;margin:0 0 18px;border-radius:10px}
  .review-alert.success{background:#dcfce7;color:#166534}.review-alert.error{background:#fee2e2;color:#991b1b}
  .review-history{display:grid;gap:10px;margin:0;padding:0;list-style:none;color:#475569}
  .review-history li{margin:0;padding:12px 14px;border-left:3px solid #cbd5e1;border-radius:0 9px 9px 0;background:#f8fafc;line-height:1.6}
  .submission-review-tab:focus-visible,.submission-review-guide-link:focus-visible,.submission-review-actions button:focus-visible,.submission-review-actions textarea:focus-visible{outline:2px solid #f57c00;outline-offset:3px}
  @container review (min-width:980px){.submission-review-section-layout{grid-template-columns:minmax(0,1.65fr) minmax(300px,.9fr)}.submission-review-guide{position:sticky;top:82px}}
  @media(max-height:700px){.submission-review-guide{position:static}}
  @media(max-width:700px){.submission-review-summary{gap:7px 10px;padding:16px}.submission-review-summary-title{font-size:16px}.submission-review-summary-action{gap:6px;font-size:12px}.submission-review-summary-action-label{display:none}.submission-review-detail{padding:0 16px 18px}.submission-review-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible;margin-right:0}.submission-review-tab{min-width:0;padding:10px;white-space:normal}.submission-review-hero{gap:18px;padding:24px 20px}.submission-review-stage{align-items:flex-start;flex-direction:column;gap:10px}.submission-review-stage-label{flex:auto}.submission-review-stage ol{width:100%}.submission-review-stage li{align-items:flex-start;flex-direction:column;gap:6px;font-size:12px}}
  @media(prefers-reduced-motion:reduce){.submission-review-tab,.submission-review-summary-icon{transition:none}}
</style>

<div class="submission-review-workspace">
<?php if ($section): ?>
  <nav class="submission-review-tabs" aria-label="Research development sections">
    <?php foreach ([
        'research_proposals' => ['01', 'Research Proposals'],
        'research_review' => ['02', 'Research Review'],
        'research_approval' => ['03', 'Research Approval'],
    ] as $key => [$index, $label]): ?>
      <a class="submission-review-tab" href="<?php echo SITE_URL; ?>pages/admin/admin-research-module.php?module=<?php echo rawurlencode($key); ?>"<?php echo $module_page === $key ? ' aria-current="page"' : ''; ?>>
        <span class="submission-review-tab-index"><?php echo $index; ?></span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <section class="submission-review-hero" aria-labelledby="submission-review-heading">
    <div>
      <p class="submission-review-eyebrow"><?php echo htmlspecialchars($section['eyebrow'], ENT_QUOTES, 'UTF-8'); ?> · RESEARCH DEVELOPMENT</p>
      <h2 id="submission-review-heading"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="submission-review-hero-copy"><?php echo htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="submission-review-stage" aria-label="Review workflow progress">
      <p class="submission-review-stage-label">Workflow stage</p>
      <ol>
        <?php foreach ([1 => ['01', 'Title intake'], 2 => ['02', 'Staff review'], 3 => ['03', 'Decision record']] as $stage_number => [$stage_index, $stage_label]): ?>
          <?php $stage_class = $stage_number < (int) $section['stage'] ? ' class="is-complete"' : ''; ?>
          <li<?php echo $stage_class; ?><?php echo $stage_number === (int) $section['stage'] ? ' aria-current="step"' : ''; ?>>
            <span><?php echo $stage_index; ?></span><?php echo htmlspecialchars($stage_label, ENT_QUOTES, 'UTF-8'); ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>
<?php endif; ?>

<?php if ($flash['success'] !== ''): ?><div class="review-alert success"><?php echo htmlspecialchars($flash['success'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($flash['error'] !== ''): ?><div class="review-alert error"><?php echo htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<div class="<?php echo $section ? 'submission-review-section-layout' : 'submission-review-grid'; ?>">
  <div class="submission-review-grid">
  <?php if (!$items): ?>
    <section class="submission-review-empty" aria-live="polite">
      <span class="submission-review-empty-mark" aria-hidden="true"><?php echo $section && $section['accent'] === 'approval' ? '✓' : ($section && $section['accent'] === 'review' ? '⌕' : '▤'); ?></span>
      <h3><?php echo htmlspecialchars($section['empty_title'] ?? 'No title or chapter submissions are available yet', ENT_QUOTES, 'UTF-8'); ?></h3>
      <p><?php echo htmlspecialchars($section['empty_description'] ?? 'Submitted title and chapter versions will appear here with their review history.', ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
  <?php endif; ?>
  <?php foreach ($items as $item): ?>
    <details id="submission-<?php echo (int) $item['version_id']; ?>" class="submission-review-card" open data-status="<?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?>">
      <summary class="submission-review-summary">
        <span class="submission-review-summary-title" role="heading" aria-level="3"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="submission-review-summary-meta">
          <span><?php echo htmlspecialchars($item['researcher_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(ucfirst($item['researcher_role']), ENT_QUOTES, 'UTF-8'); ?>)</span>
          <span class="submission-review-summary-course">Course: <?php echo htmlspecialchars($item['researcher_program'] !== '' ? $item['researcher_program'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="submission-review-summary-type"><?php echo $item['submission_type'] === 'title' ? 'Research Title' : 'Chapter ' . (int) $item['chapter_number']; ?></span>
        </span>
        <span class="submission-review-summary-action"><span class="submission-review-summary-action-label"><span class="submission-review-summary-action-open">View details</span><span class="submission-review-summary-action-close">Hide details</span></span><span class="submission-review-summary-icon" aria-hidden="true">+</span></span>
      </summary>
      <div class="submission-review-detail">
        <div class="submission-review-head">
          <div class="submission-review-meta">
            <span class="submission-review-pill">Version <?php echo (int) $item['version_number']; ?></span>
            <span class="submission-review-pill submission-review-status"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $item['status'])), ENT_QUOTES, 'UTF-8'); ?></span>
            <span>Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($item['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        </div>
      <div class="submission-review-section">
        <h3>Submitted version</h3>
        <dl class="submission-review-fields">
        <?php foreach ($item['snapshot'] as $key => $value): ?>
          <?php if ($key === 'content' && is_array($value)): ?>
            <?php foreach ($value as $field => $text): ?>
              <div class="submission-review-field<?php echo strlen((string) $text) > 180 ? ' submission-review-field--long' : ''; ?>">
                <dt><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $field)), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd>
                  <?php foreach (preg_split('/(?:\r\n|\r|\n)[\t ]*(?:(?:\r\n|\r|\n)[\t ]*)+/', trim((string) $text)) ?: [(string) $text] as $paragraph): ?>
                    <?php if (trim($paragraph) !== ''): ?><p><?php echo nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                  <?php endforeach; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          <?php elseif (!is_array($value)): ?>
            <div class="submission-review-field<?php echo strlen((string) $value) > 180 ? ' submission-review-field--long' : ''; ?>">
              <dt><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key)), ENT_QUOTES, 'UTF-8'); ?></dt>
              <dd>
                <?php foreach (preg_split('/(?:\r\n|\r|\n)[\t ]*(?:(?:\r\n|\r|\n)[\t ]*)+/', trim((string) $value)) ?: [(string) $value] as $paragraph): ?>
                  <?php if (trim($paragraph) !== ''): ?><p><?php echo nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                <?php endforeach; ?>
              </dd>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
        </dl>
        <?php if ($item['uploads']): ?>
          <h3 class="submission-review-subheading">Supporting documents</h3>
          <?php foreach ($item['uploads'] as $upload): ?>
            <p>📄 <a href="<?php echo SITE_URL; ?>pages/shared/download-research-document.php?type=research&id=<?php echo (int) $upload['id']; ?>"><?php echo htmlspecialchars($upload['name'], ENT_QUOTES, 'UTF-8'); ?></a> · <?php echo number_format($upload['size'] / 1024, 1); ?> KB</p>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if ($item['latest_comments'] !== ''): ?>
        <div class="submission-review-section">
          <h3>Latest feedback</h3>
          <p><?php echo nl2br(htmlspecialchars($item['latest_comments'], ENT_QUOTES, 'UTF-8')); ?></p>
        </div>
      <?php endif; ?>
      <?php if ($item['history']): ?>
        <div class="submission-review-section">
          <h3>Submission and review history</h3>
          <ul class="review-history">
            <?php foreach ($item['history'] as $history): ?>
              <li>Version <?php echo (int) $history['version_number']; ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $history['status'])), ENT_QUOTES, 'UTF-8'); ?> · Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($history['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?><?php if ($history['decision']): ?> · <?php echo htmlspecialchars(ucfirst($history['decision']), ENT_QUOTES, 'UTF-8'); ?> by <?php echo htmlspecialchars($history['reviewer_name'] ?: 'Authorized reviewer', ENT_QUOTES, 'UTF-8'); ?> at <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($history['reviewed_at'])), ENT_QUOTES, 'UTF-8'); ?><?php if (trim((string) $history['comments']) !== ''): ?>: <?php echo nl2br(htmlspecialchars($history['comments'], ENT_QUOTES, 'UTF-8')); ?><?php endif; ?><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($item['status'] === 'submitted' && ($can_return || $can_approve)): ?>
        <form method="post" class="submission-review-actions">
          <?php echo csrfField(); ?>
          <input type="hidden" name="version_id" value="<?php echo (int) $item['version_id']; ?>">
          <label for="comments-<?php echo (int) $item['version_id']; ?>">Decision comments <?php echo $can_return ? '(required when returning)' : '(optional)'; ?></label>
          <textarea id="comments-<?php echo (int) $item['version_id']; ?>" name="comments" maxlength="5000"></textarea>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if ($can_approve): ?><button class="approve" type="submit" name="decision" value="approved">Approve</button><?php endif; ?>
            <?php if ($can_return): ?><button class="return" type="submit" name="decision" value="returned">Return for Revision</button><?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <?php if ($section): ?>
    <aside class="submission-review-guide">
      <section class="submission-review-rail-section">
        <h3>Queue at a glance</h3>
        <p>Counts for the latest preserved versions currently shown in this section.</p>
        <dl class="submission-review-counts">
          <div><dt>Versions shown</dt><dd><?php echo (int) $review_counts['total']; ?></dd></div>
          <div><dt>Title submissions</dt><dd><?php echo (int) $review_counts['titles']; ?></dd></div>
          <div><dt>Chapter submissions</dt><dd><?php echo (int) $review_counts['chapters']; ?></dd></div>
          <div><dt>Awaiting decision</dt><dd><?php echo (int) $review_counts['waiting']; ?></dd></div>
        </dl>
      </section>
      <section class="submission-review-rail-section">
        <h3>Recent submissions</h3>
        <?php if ($recent_submissions): ?>
          <ol class="submission-review-recent">
            <?php foreach ($recent_submissions as $recent): ?>
              <li>
                <a class="submission-review-recent-title" href="#submission-<?php echo (int) $recent['version_id']; ?>"><?php echo htmlspecialchars($recent['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                <div class="submission-review-recent-meta">
                  <span><?php echo $recent['submission_type'] === 'title' ? 'Research title' : 'Chapter ' . (int) $recent['chapter_number']; ?></span>
                  <span class="submission-review-recent-status" data-status="<?php echo htmlspecialchars($recent['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $recent['status'])), ENT_QUOTES, 'UTF-8'); ?></span>
                  <span><?php echo htmlspecialchars(date('M j, Y', strtotime($recent['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <p>No submissions are listed in this section yet.</p>
        <?php endif; ?>
      </section>
      <section class="submission-review-rail-section">
        <h3><?php echo htmlspecialchars($section['guide_title'], ENT_QUOTES, 'UTF-8'); ?></h3>
        <p><?php echo htmlspecialchars($section['guide_description'], ENT_QUOTES, 'UTF-8'); ?></p>
        <ul>
          <?php foreach ($section['guide_items'] as $guide_item): ?>
            <li><?php echo htmlspecialchars($guide_item, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="submission-review-section">
          <h3><?php echo htmlspecialchars($section['next_title'], ENT_QUOTES, 'UTF-8'); ?></h3>
          <p><?php echo htmlspecialchars($section['next_description'], ENT_QUOTES, 'UTF-8'); ?></p>
          <a class="submission-review-guide-link" href="<?php echo htmlspecialchars($section['next_href'] ?? (SITE_URL . 'pages/admin/admin-research-module.php?module=' . rawurlencode($section['next_module'])), ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($section['next_label'], ENT_QUOTES, 'UTF-8'); ?> <span aria-hidden="true">→</span>
          </a>
        </div>
      </section>
    </aside>
  <?php endif; ?>
</div>
</div>

<script>
document.querySelectorAll('.submission-review-recent-title').forEach(function (link) {
  link.addEventListener('click', function () {
    var target = document.getElementById(link.hash.slice(1));
    if (target && target.tagName === 'DETAILS') {
      target.open = true;
    }
  });
});
</script>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
