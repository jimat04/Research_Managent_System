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
$module_page = in_array((string) ($_GET['module_page'] ?? ''), ['research_proposals', 'research_review', 'research_approval'], true)
    ? (string) $_GET['module_page']
    : '';
$section_config = [
    'research_proposals' => [
        'title' => 'Research Proposals',
        'eyebrow' => 'TITLE INTAKE',
        'description' => 'A dedicated view of researcher-submitted titles, their supporting documents, and their review history.',
        'empty_title' => 'No versioned title submissions to display',
        'empty_description' => 'This page lists submissions preserved by the current review workflow. Older project records are not added to that history automatically and remain available in Research Management.',
        'guide_title' => 'What belongs in this section',
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
        'empty_title' => 'No submissions are waiting for review',
        'empty_description' => 'Submitted versions appear here until an authorized reviewer approves them or returns them with recommendations.',
        'guide_title' => 'Review checklist',
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
        'empty_title' => 'No submissions have been approved yet',
        'empty_description' => 'Once a reviewer approves a submitted title or chapter, its preserved version and review history will be listed here.',
        'guide_title' => 'Approval records include',
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

if ($role === 'admin') {
    renderAdminShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions separately.');
} else {
    renderStaffShell($user, $module_page !== '' ? $module_page : 'research-submission-review', $page_title, $section['description'] ?? 'Review title and chapter submissions according to your assigned permissions.');
}
?>
<style>
  .submission-review-workspace{max-width:1440px;margin:0 auto;padding-bottom:32px}
  .submission-review-tabs{display:flex;gap:8px;overflow-x:auto;margin:0 0 22px;padding:4px 2px 10px}
  .submission-review-tab{display:flex;align-items:center;gap:9px;flex:0 0 auto;padding:11px 15px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;color:#475569;text-decoration:none;font-size:13px;font-weight:600;transition:background .2s ease,border-color .2s ease,color .2s ease,transform .2s ease}
  .submission-review-tab:hover{border-color:#fdba74;color:#9a3412;transform:translateY(-1px)}
  .submission-review-tab[aria-current="page"]{border-color:#fed7aa;background:#fff7ed;color:#9a3412;box-shadow:0 5px 16px rgba(154,52,18,.08)}
  .submission-review-tab-index{display:grid;place-items:center;width:23px;height:23px;border-radius:7px;background:#f1f5f9;color:#64748b;font-size:11px}
  .submission-review-tab[aria-current="page"] .submission-review-tab-index{background:#ea580c;color:#fff}
  .submission-review-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.5fr) minmax(250px,.8fr);gap:28px;overflow:hidden;margin-bottom:22px;padding:clamp(24px,4vw,38px);border:1px solid #e5e7eb;border-radius:20px;background:radial-gradient(ellipse at 90% 10%,rgba(245,124,0,.09),transparent 44%),#fff;box-shadow:0 10px 28px rgba(30,41,59,.04)}
  .submission-review-hero:before{position:absolute;inset:0 auto 0 0;width:4px;background:#f57c00;content:""}
  .submission-review-eyebrow{margin:0 0 12px;color:#c2410c;font-size:11px;font-weight:700;letter-spacing:.14em}
  .submission-review-hero h2{max-width:700px;margin:0;color:#111827;font-size:clamp(25px,3.2vw,38px);font-weight:700;letter-spacing:-.04em;line-height:1.13;text-wrap:balance}
  .submission-review-hero-copy{max-width:62ch;margin:14px 0 0;color:#64748b;font-size:14px;line-height:1.7}
  .submission-review-path{align-self:center;padding:20px 22px;border-left:1px solid #e2e8f0}
  .submission-review-path-label{margin:0 0 13px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
  .submission-review-path ol{display:grid;gap:12px;margin:0;padding:0;list-style:none}
  .submission-review-path li{display:flex;align-items:center;gap:11px;color:#475569;font-size:13px;line-height:1.45}
  .submission-review-path li span{display:grid;place-items:center;flex:0 0 25px;height:25px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:700}
  .submission-review-section-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.58fr);gap:20px;align-items:start}
  .submission-review-grid{display:grid;gap:20px}
  .submission-review-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px}
  .submission-review-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
  .submission-review-title{font-size:18px;font-weight:700;color:#111827;margin:0}
  .submission-review-meta{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;color:#64748b;font-size:13px}
  .submission-review-pill{padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#334155}
  .submission-review-section{margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}
  .submission-review-section h3{font-size:14px;margin:0 0 8px;color:#111827}
  .submission-review-section p{white-space:pre-wrap;margin:4px 0;color:#475569}
  .submission-review-actions{display:grid;gap:10px;margin-top:18px}
  .submission-review-actions textarea{width:100%;min-height:85px;border:1px solid #d1d5db;border-radius:10px;padding:10px;font:inherit}
  .submission-review-actions button{border:0;border-radius:9px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}
  .submission-review-actions .approve{background:#0d9488;color:#fff}
  .submission-review-actions .return{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
  .submission-review-empty{min-height:255px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;padding:clamp(24px,4vw,38px);border:1px dashed #cbd5e1;border-radius:16px;background:linear-gradient(135deg,#fff 0%,#f8fafc 100%);color:#64748b}
  .submission-review-empty-mark{display:grid;place-items:center;width:48px;height:48px;margin-bottom:20px;border:1px solid #fed7aa;border-radius:15px;background:#fff7ed;color:#c2410c;font-size:22px}
  .submission-review-empty h3{margin:0;color:#111827;font-size:20px;letter-spacing:-.025em}
  .submission-review-empty p{max-width:58ch;margin:9px 0 0;font-size:14px;line-height:1.7}
  .submission-review-guide{padding:23px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
  .submission-review-guide h3{margin:0;color:#111827;font-size:15px}
  .submission-review-guide p{margin:9px 0 0;color:#64748b;font-size:13px;line-height:1.65}
  .submission-review-guide ul{display:grid;gap:11px;margin:18px 0 0;padding:0;list-style:none}
  .submission-review-guide li{display:flex;gap:10px;color:#475569;font-size:13px;line-height:1.55}
  .submission-review-guide li:before{flex:0 0 auto;color:#c2410c;font-weight:700;content:"✓"}
  .submission-review-guide-link{display:inline-flex;align-items:center;gap:8px;margin-top:20px;color:#9a3412;font-size:13px;font-weight:700;text-decoration:none}
  .submission-review-guide-link:hover{text-decoration:underline}
  .review-alert{padding:12px 16px;margin:0 0 18px;border-radius:10px}
  .review-alert.success{background:#dcfce7;color:#166534}.review-alert.error{background:#fee2e2;color:#991b1b}
  .review-history{padding-left:18px;color:#64748b}
  .review-history li{margin:8px 0}
  .submission-review-tab:focus-visible,.submission-review-guide-link:focus-visible{outline:2px solid #f57c00;outline-offset:3px}
  @media(max-width:850px){.submission-review-hero,.submission-review-section-layout{grid-template-columns:1fr}.submission-review-path{border-top:1px solid #e2e8f0;border-left:0;padding:18px 0 0}}
  @media(max-width:700px){.submission-review-card{padding:18px}.submission-review-tabs{margin-right:-12px}.submission-review-hero{gap:20px}}
  @media(prefers-reduced-motion:reduce){.submission-review-tab{transition:none}}
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
    <div class="submission-review-path">
      <p class="submission-review-path-label">Submission pathway</p>
      <ol>
        <li><span>01</span>Researcher submits a title or chapter</li>
        <li><span>02</span>Authorized staff review the locked version</li>
        <li><span>03</span>Approval or revision feedback is recorded</li>
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
    <article class="submission-review-card">
      <div class="submission-review-head">
        <div>
          <h2 class="submission-review-title"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <div class="submission-review-meta">
            <span class="submission-review-pill"><?php echo $item['submission_type'] === 'title' ? 'Research Title' : 'Chapter ' . (int) $item['chapter_number']; ?></span>
            <span class="submission-review-pill">Version <?php echo (int) $item['version_number']; ?></span>
            <span class="submission-review-pill"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $item['status'])), ENT_QUOTES, 'UTF-8'); ?></span>
            <span><?php echo htmlspecialchars($item['researcher_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(ucfirst($item['researcher_role']), ENT_QUOTES, 'UTF-8'); ?>)</span>
            <span>Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($item['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        </div>
      </div>
      <div class="submission-review-section">
        <h3>Submitted version</h3>
        <?php foreach ($item['snapshot'] as $key => $value): ?>
          <?php if ($key === 'content' && is_array($value)): ?>
            <?php foreach ($value as $field => $text): ?>
              <p><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $field)), ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endforeach; ?>
          <?php elseif (!is_array($value)): ?>
            <p><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key)), ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php echo nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')); ?></p>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($item['uploads']): ?>
          <h3 style="margin-top:14px">Supporting documents</h3>
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
              <li>Version <?php echo (int) $history['version_number']; ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $history['status'])), ENT_QUOTES, 'UTF-8'); ?> · Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($history['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?><?php if ($history['decision']): ?> · <?php echo htmlspecialchars(ucfirst($history['decision']), ENT_QUOTES, 'UTF-8'); ?> by <?php echo htmlspecialchars($history['reviewer_name'] ?: 'Authorized reviewer', ENT_QUOTES, 'UTF-8'); ?> at <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($history['reviewed_at'])), ENT_QUOTES, 'UTF-8'); ?>: <?php echo nl2br(htmlspecialchars($history['comments'], ENT_QUOTES, 'UTF-8')); ?><?php endif; ?></li>
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
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($section): ?>
    <aside class="submission-review-guide">
      <h3><?php echo htmlspecialchars($section['guide_title'], ENT_QUOTES, 'UTF-8'); ?></h3>
      <p>Every entry is taken from a preserved submission version; this page does not create sample records.</p>
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
    </aside>
  <?php endif; ?>
</div>
</div>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} else {
    renderStaffShellClose();
}
?>
