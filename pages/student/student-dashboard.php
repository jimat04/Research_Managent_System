<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');
$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Support both the base schema and installations with soft-delete columns.
$column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$has_deleted_at = false;
if ($column_stmt) {
    $column_stmt->execute();
    $has_deleted_at = $column_stmt->get_result()->num_rows > 0;
    $column_stmt->close();
}
$deleted_filter = $has_deleted_at ? ' AND deleted_at IS NULL' : '';

$project_stmt = $conn->prepare("SELECT * FROM research_projects WHERE created_by = ?" . $deleted_filter . " ORDER BY updated_at DESC, created_at DESC");
$project_stmt->bind_param('i', $user_id);
$project_stmt->execute();
$projects = $project_stmt->get_result();

$notification_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notification_stmt->bind_param('i', $user_id);
$notification_stmt->execute();
$notifications = $notification_stmt->get_result();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->bind_param('i', $user_id);
$unread_stmt->execute();
$unread_count = (int) ($unread_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$project_count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE created_by = ?" . $deleted_filter . " AND status <> 'draft'");
$project_count_stmt->bind_param('i', $user_id);
$project_count_stmt->execute();
$stat_projects = (int) ($project_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$review_count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE created_by = ?" . $deleted_filter . " AND status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review')");
$review_count_stmt->bind_param('i', $user_id);
$review_count_stmt->execute();
$stat_review = (int) ($review_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$approved_count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE created_by = ?" . $deleted_filter . " AND status IN ('approved', 'ongoing', 'completed', 'archived')");
$approved_count_stmt->bind_param('i', $user_id);
$approved_count_stmt->execute();
$stat_approved = (int) ($approved_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$revision_count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE created_by = ?" . $deleted_filter . " AND status IN ('for_revision', 'revision_required')");
$revision_count_stmt->bind_param('i', $user_id);
$revision_count_stmt->execute();
$stat_revision = (int) ($revision_count_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$active_project = null;
$chapter_progress = [];
if ($projects->num_rows > 0) {
    $projects->data_seek(0);
    $active_project = $projects->fetch_assoc();
    $chapter_stmt = $conn->prepare("SELECT chapter_number, status FROM chapters WHERE project_id = ? ORDER BY chapter_number ASC");
    if ($chapter_stmt) {
        $chapter_stmt->bind_param('i', $active_project['project_id']);
        $chapter_stmt->execute();
        $chapter_result = $chapter_stmt->get_result();
        while ($chapter = $chapter_result->fetch_assoc()) {
            $chapter_progress[(int) $chapter['chapter_number']] = $chapter['status'];
        }
        $chapter_stmt->close();
    }
}

$approved_chapters = count(array_filter($chapter_progress, static function ($status) {
    return $status === 'approved';
}));
$active_status = $active_project['status'] ?? 'draft';
$active_status_label = ucwords(str_replace('_', ' ', $active_status));
$workflow_stage_map = [
    'draft' => 0, 'submitted' => 1, 'under_review' => 1,
    'under_crec_review' => 1, 'under_erec_review' => 2,
    'for_revision' => 2, 'revision_required' => 2, 'approved' => 3,
    'ongoing' => 4, 'completed' => 5, 'archived' => 5,
];
$current_workflow_stage = $workflow_stage_map[$active_status] ?? 0;

// Use real report dates instead of dashboard-only placeholder deadlines.
$upcoming_deadlines = [];
$reports_stmt = $conn->prepare("SHOW TABLES LIKE 'research_reports'");
if ($reports_stmt) {
    $reports_stmt->execute();
    $has_reports_table = $reports_stmt->get_result()->num_rows > 0;
    $reports_stmt->close();
    if ($has_reports_table) {
        $join_deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';
        $deadline_stmt = $conn->prepare("
            SELECT rr.report_type, rr.due_date, rp.title
            FROM research_reports rr
            INNER JOIN research_projects rp ON rp.project_id = rr.project_id
            WHERE rp.created_by = ?
              AND rr.due_date IS NOT NULL
              AND rr.due_date >= CURDATE()
              AND rr.status NOT IN ('approved', 'rejected')
              {$join_deleted_filter}
            ORDER BY rr.due_date ASC
            LIMIT 3
        ");
        if ($deadline_stmt) {
            $deadline_stmt->bind_param('i', $user_id);
            $deadline_stmt->execute();
            $upcoming_deadlines = $deadline_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $deadline_stmt->close();
        }
    }
}

renderStudentShell(
    $user,
    'student-dashboard',
    'Welcome back, ' . $user['first_name'],
    'Track your research progress and stay connected with your adviser.'
);
?>

<style>
  .student-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 4%,rgba(91,30,188,.12),transparent 27%),radial-gradient(circle at 8% 42%,rgba(37,99,235,.07),transparent 25%),linear-gradient(180deg,#F4F1FA 0%,#ECE8F5 100%)}
  .student-topbar{background:#FBFAFE;border-bottom-color:#DDD6EA}
  .student-dashboard-page{--accent:#5B1EBC;--accent-dark:#481796;--accent-soft:#F1EAFB;--review:#2563EB;--review-soft:#EAF1FF;--success:#15805B;--success-soft:#E8F7F0;--attention:#C45D12;--attention-soft:#FFF1E5;--ink:#151728;--copy:#5E6578;--muted:#8B92A5;--line:#E6E7EE;max-width:1440px;margin:0 auto;color:var(--ink)}
  .student-dashboard-page *{box-sizing:border-box}
  .dashboard-brief{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr);gap:48px;overflow:hidden;margin-bottom:24px;padding:40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 88% 10%,rgba(216,195,255,.24),transparent 31%),radial-gradient(circle at 7% 100%,rgba(73,123,238,.20),transparent 28%),linear-gradient(135deg,#2B1257 0%,#4B188F 52%,#6B2CC4 100%);color:#F9F7FF;box-shadow:0 22px 54px rgba(55,25,104,.24)}
  .dashboard-brief::after{content:'';position:absolute;right:-86px;bottom:-98px;width:250px;height:250px;border:42px solid rgba(255,255,255,.06);border-radius:50%;pointer-events:none}
  .brief-copy,.brief-focus{position:relative;z-index:1}.brief-eyebrow{margin:0 0 12px;color:#DCCBFF;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
  .brief-copy h1,.brief-focus h2{color:#FFF}
  .dashboard-brief .dashboard-button.primary{background:#FFF;color:#4B188F;box-shadow:0 10px 22px rgba(24,8,54,.22)}.dashboard-brief .dashboard-button.primary:hover{background:#F1EAFB;color:#351064}
  .dashboard-brief .dashboard-button.secondary{border-color:rgba(255,255,255,.36);background:rgba(255,255,255,.09);color:#FFF}.dashboard-brief .dashboard-button.secondary:hover{border-color:rgba(255,255,255,.65);background:rgba(255,255,255,.15);color:#FFF}
  .brief-copy h1{max-width:650px;margin:0;font-size:clamp(30px,3vw,46px);font-weight:700;letter-spacing:-.045em;line-height:1.08;text-wrap:balance}
  .brief-copy>p:not(.brief-eyebrow){max-width:58ch;margin:16px 0 24px;color:#E6DFF2;font-size:15px;line-height:1.7}.dashboard-actions{display:flex;flex-wrap:wrap;gap:10px}
  .dashboard-button,.dashboard-text-link{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;border-radius:10px;font-size:13px;font-weight:650;line-height:1;text-decoration:none;white-space:nowrap;transition:transform .2s ease,background-color .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease}
  .dashboard-button{padding:0 18px;border:1px solid transparent}.dashboard-button.primary{background:var(--accent);color:#fff;box-shadow:0 8px 18px rgba(91,30,188,.18)}.dashboard-button.primary:hover{background:var(--accent-dark);transform:translateY(-1px)}
  .dashboard-button.secondary{border-color:var(--line);background:rgba(255,255,255,.78);color:var(--ink)}.dashboard-button.secondary:hover{border-color:rgba(91,30,188,.28);color:var(--accent)}
  .dashboard-button:active,.dashboard-text-link:active{transform:scale(.98)}.dashboard-button:focus-visible,.dashboard-text-link:focus-visible,.project-title-link:focus-visible,.table-action:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
  .brief-focus{align-self:stretch;padding:4px 0 4px 32px;border-left:1px solid rgba(255,255,255,.25)}.brief-focus-label{margin-bottom:13px;color:#E0D5F2;font-size:12px;font-weight:600}
  .brief-focus h2{margin:0 0 16px;font-size:clamp(18px,2vw,24px);font-weight:650;letter-spacing:-.025em;line-height:1.3;text-wrap:pretty}.brief-focus-meta{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:19px}
  .status-badge{display:inline-flex;align-items:center;min-height:27px;padding:5px 10px;border-radius:999px;background:var(--accent-soft);color:var(--accent-dark);font-size:11px;font-weight:700;line-height:1.2}.status-badge.status-draft,.status-badge.status-archived{background:#EDF0F5;color:#526071}.status-badge.status-submitted,.status-badge.status-under-review,.status-badge.status-under-crec-review{background:#E8F0FE;color:#1D4ED8}.status-badge.status-under-erec-review{background:#F1EAFB;color:#5B1EBC}.status-badge.status-for-revision,.status-badge.status-revision-required{background:#FFF2E2;color:#B45309}.status-badge.status-approved,.status-badge.status-ongoing,.status-badge.status-completed{background:#E8F7ED;color:#137A3E}.brief-chapter-count{color:var(--copy);font-size:12px;font-variant-numeric:tabular-nums}
  .brief-focus .status-badge{background:rgba(255,255,255,.16);color:#FFF;box-shadow:inset 0 0 0 1px rgba(255,255,255,.17)}.brief-focus .brief-chapter-count{color:#D9CFEA}.brief-focus .dashboard-text-link{color:#FFF}
  .dashboard-text-link{justify-content:flex-start;min-height:32px;color:var(--accent)}.dashboard-text-link:hover{color:var(--accent-dark);transform:translateX(2px)}.brief-focus .dashboard-text-link:hover{color:#E7D9FF}
  .metrics-strip{display:grid;grid-template-columns:repeat(4,1fr);margin-bottom:24px;overflow:hidden;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 8px 26px rgba(32,34,65,.045)}
  .metric{position:relative;display:grid;grid-template-columns:38px 1fr;gap:12px;align-items:center;min-width:0;padding:21px 24px}.metric::before{content:'';position:absolute;inset:0 0 auto;height:3px;background:var(--metric-color,var(--accent))}.metric+.metric{border-left:1px solid var(--line)}
  .metric-projects{--metric-color:var(--accent);--metric-soft:#E9DCF9;background:#F7F2FD}.metric-review{--metric-color:var(--review);--metric-soft:#DCE9FF;background:#F1F6FF}.metric-approved{--metric-color:var(--success);--metric-soft:#D9F1E5;background:#EFFAF5}.metric-revision{--metric-color:var(--attention);--metric-soft:#FFE3CB;background:#FFF6ED}
  .metric-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:var(--metric-soft,var(--accent-soft));color:var(--metric-color,var(--accent));font-size:17px}.metric-value{color:var(--metric-color,var(--ink));font-size:25px;font-weight:700;letter-spacing:-.035em;line-height:1;font-variant-numeric:tabular-nums}
  .metric-label{margin-top:5px;overflow:hidden;color:var(--copy);font-size:12px;font-weight:550;text-overflow:ellipsis;white-space:nowrap}
  .dashboard-workspace,.dashboard-secondary-grid{display:grid;gap:24px;margin-bottom:24px}.dashboard-workspace{grid-template-columns:minmax(0,1.55fr) minmax(280px,.65fr)}.dashboard-secondary-grid{grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr)}
  .dashboard-section{min-width:0;padding:28px;border:1px solid rgba(91,30,188,.12);border-radius:16px;background:#FCFAFF;box-shadow:0 12px 32px rgba(53,31,91,.07)}.dashboard-workspace>.dashboard-section:first-child{border-top:3px solid var(--accent);background:#FCFAFF}.dashboard-secondary-grid>.dashboard-section:first-child{border-top:3px solid var(--review);background:#F5F8FF}.dashboard-secondary-grid>.dashboard-section:last-child{border-top:3px solid var(--attention);background:#FFF8F1}.projects-section{border-top:3px solid var(--success);background:#F7FCF9}
  .section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}.section-heading h2{margin:0;font-size:18px;font-weight:700;letter-spacing:-.02em;line-height:1.25}.section-heading p{margin:6px 0 0;color:var(--copy);font-size:13px;line-height:1.5}.section-count{flex:none;color:var(--accent);font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}
  .chapter-list{display:grid;gap:8px}.chapter-item{display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:14px;align-items:center;padding:13px 14px;border-radius:12px;background:#F8F9FC;transition:background-color .2s ease,transform .2s ease}.chapter-item:hover{background:#F3F0F9;transform:translateX(2px)}
  .chapter-number{display:grid;place-items:center;width:38px;height:38px;border:1px solid #DDE0E9;border-radius:10px;background:#fff;color:var(--copy);font-size:13px;font-weight:700}.chapter-number.completed{border-color:#B7E4C7;background:#EAF8EF;color:#137A3E}.chapter-number.review{border-color:#BFD5FC;background:#EBF2FE;color:#1D4ED8}.chapter-number.revision{border-color:#F6D3A8;background:#FFF4E6;color:#B45309}
  .chapter-title{margin-bottom:2px;font-size:13px;font-weight:650}.chapter-desc{overflow:hidden;color:var(--copy);font-size:12px;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}.chapter-status{padding:5px 9px;border-radius:999px;background:#EDF0F5;color:#5E6578;font-size:10px;font-weight:700;white-space:nowrap}.chapter-status.approved{background:#E8F7ED;color:#137A3E}.chapter-status.review{background:#E8F0FE;color:#1D4ED8}.chapter-status.revision{background:#FFF2E2;color:#B45309}
  .workflow-panel{background:radial-gradient(circle at 100% 0%,rgba(91,30,188,.18),transparent 42%),linear-gradient(160deg,#F5EEFF,#E9DDF8);border-color:rgba(91,30,188,.22)}.workflow-list{position:relative;display:grid;gap:2px;margin:0;padding:0;list-style:none}.workflow-list::before{content:'';position:absolute;top:17px;bottom:17px;left:13px;width:1px;background:#CFC0E1}
  .workflow-step{position:relative;display:grid;grid-template-columns:28px 1fr;gap:12px;align-items:start;padding:8px 0}.workflow-mark{position:relative;z-index:1;display:grid;place-items:center;width:27px;height:27px;border:1px solid #D9DCE5;border-radius:50%;background:#FBFAFE;color:#9499A8;font-size:10px;font-weight:700}.workflow-step.completed .workflow-mark{border-color:var(--accent);background:var(--accent);color:#fff}.workflow-step.active .workflow-mark{border:7px solid var(--accent);background:#fff;box-shadow:0 0 0 4px rgba(91,30,188,.10)}
  .workflow-name{margin:1px 0 2px;font-size:13px;font-weight:650}.workflow-state{color:var(--muted);font-size:11px}.workflow-step.active .workflow-state{color:var(--accent);font-weight:650}
  .activity-feed,.deadline-list{margin:0;padding:0;list-style:none}.activity-item,.deadline-item{display:grid;gap:12px;padding:14px 0;border-bottom:1px solid #ECEEF3}.activity-item{grid-template-columns:36px minmax(0,1fr)}.activity-item:first-child,.deadline-item:first-child{padding-top:0}.activity-item:last-child,.deadline-item:last-child{padding-bottom:0;border-bottom:0}
  .activity-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:var(--review-soft);color:var(--review);font-size:13px;font-weight:700}.activity-icon.success{background:var(--success-soft);color:var(--success)}.activity-icon.warning{background:var(--attention-soft);color:var(--attention)}.activity-icon.error{background:#FDECEC;color:#B42318}
  .activity-message,.deadline-name{margin:0 0 4px;font-size:13px;font-weight:550;line-height:1.45}.activity-date,.deadline-meta{color:var(--muted);font-size:11px;line-height:1.4}.deadline-item{grid-template-columns:52px minmax(0,1fr);align-items:center}.deadline-date{display:grid;place-items:center;min-height:48px;padding:6px;border-radius:10px;background:var(--attention-soft);color:var(--attention);text-align:center}.deadline-date strong{display:block;font-size:18px;line-height:1}.deadline-date span{margin-top:4px;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
  .empty-compact{display:grid;place-items:center;min-height:170px;padding:24px;border-radius:12px;background:rgba(255,255,255,.55);color:var(--copy);text-align:center}.empty-compact-icon{margin-bottom:9px;font-size:26px}.empty-compact p{margin:0;font-size:13px}
  .projects-section{margin-bottom:8px}.projects-table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px}.projects-table{width:100%;min-width:720px;border-collapse:collapse}.projects-table th{padding:12px 16px;background:#F8F9FC;color:var(--copy);font-size:11px;font-weight:700;letter-spacing:.04em;text-align:left;text-transform:uppercase}.projects-table td{padding:16px;border-top:1px solid var(--line);color:var(--copy);font-size:13px;vertical-align:middle}.projects-table tbody tr{transition:background-color .2s ease}.projects-table tbody tr:hover{background:#FBFAFE}
  .project-title-link{display:inline-block;max-width:540px;color:var(--ink);font-weight:650;line-height:1.4;text-decoration:none}.project-title-link:hover{color:var(--accent)}.table-action{color:var(--accent);font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap}.table-action:hover{color:var(--accent-dark);text-decoration:underline;text-underline-offset:3px}
  .project-empty{padding:46px 24px;border:1px dashed rgba(91,30,188,.25);border-radius:12px;background:#FBFAFE;text-align:center}.project-empty-icon{margin-bottom:12px;font-size:32px}.project-empty h3{margin:0 0 7px;font-size:16px}.project-empty p{margin:0 auto 18px;color:var(--copy);font-size:13px}
  @media(prefers-reduced-motion:no-preference){.dashboard-brief,.metrics-strip,.dashboard-workspace,.dashboard-secondary-grid,.projects-section{animation:dashboardEnter .48s cubic-bezier(.16,1,.3,1) both}.metrics-strip{animation-delay:.06s}.dashboard-workspace{animation-delay:.12s}.dashboard-secondary-grid{animation-delay:.18s}.projects-section{animation-delay:.24s}}
  @keyframes dashboardEnter{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @media(max-width:1180px){.dashboard-brief{grid-template-columns:minmax(0,1fr) minmax(280px,.7fr);gap:32px}.metrics-strip{grid-template-columns:repeat(2,1fr)}.metric:nth-child(3){border-left:0;border-top:1px solid var(--line)}.metric:nth-child(4){border-top:1px solid var(--line)}}
  @media(max-width:980px){.dashboard-brief,.dashboard-workspace,.dashboard-secondary-grid{grid-template-columns:1fr}.brief-focus{padding:24px 0 0;border-top:1px solid rgba(255,255,255,.25);border-left:0}}
  @media(max-width:640px){.dashboard-brief{padding:28px 22px}.brief-copy h1{font-size:30px}.dashboard-actions{display:grid}.dashboard-button{width:100%}.metrics-strip{grid-template-columns:1fr}.metric+.metric,.metric:nth-child(3){border-top:1px solid var(--line);border-left:0}.dashboard-section{padding:22px 18px}.section-heading{align-items:flex-start}.chapter-item{grid-template-columns:38px minmax(0,1fr)}.chapter-status{grid-column:2;justify-self:start}.chapter-desc{white-space:normal}.projects-section .section-heading{flex-direction:column}}
  @media(prefers-reduced-motion:reduce){.student-dashboard-page *{animation:none!important;transition:none!important}}
</style>

<div class="student-dashboard-page">
  <section class="dashboard-brief" aria-labelledby="dashboard-focus-title">
    <div class="brief-copy">
      <p class="brief-eyebrow">Student research workspace</p>
      <h1 id="dashboard-focus-title">Keep your research moving.</h1>
      <p>Review your current stage, prepare the next chapter, and respond to adviser feedback from one clear workspace.</p>
      <div class="dashboard-actions">
        <?php if ($active_project): ?>
          <a class="dashboard-button primary" href="<?php echo SITE_URL; ?>pages/student/research-detail.php?id=<?php echo (int) $active_project['project_id']; ?>">Continue research</a>
          <a class="dashboard-button secondary" href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php">Submit a chapter</a>
        <?php else: ?>
          <a class="dashboard-button primary" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">Start a proposal</a>
          <a class="dashboard-button secondary" href="<?php echo SITE_URL; ?>public/research-archive.php">Browse the archive</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="brief-focus">
      <div class="brief-focus-label"><?php echo $active_project ? 'Active research' : 'Your next step'; ?></div>
      <?php if ($active_project): ?>
        <h2><?php echo htmlspecialchars($active_project['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php $active_status_class = 'status-' . str_replace('_', '-', strtolower($active_status)); ?>
        <div class="brief-focus-meta"><span class="status-badge <?php echo htmlspecialchars($active_status_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($active_status_label, ENT_QUOTES, 'UTF-8'); ?></span><span class="brief-chapter-count"><?php echo $approved_chapters; ?> of 5 chapters approved</span></div>
        <a class="dashboard-text-link" href="<?php echo SITE_URL; ?>pages/student/progress-tracking.php">Open progress tracking <span aria-hidden="true">→</span></a>
      <?php else: ?>
        <h2>Create your first research proposal and send it for review.</h2>
        <a class="dashboard-text-link" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">Begin proposal <span aria-hidden="true">→</span></a>
      <?php endif; ?>
    </div>
  </section>

  <section class="metrics-strip" aria-label="Research summary">
    <div class="metric metric-projects"><span class="metric-icon" aria-hidden="true">📁</span><div><div class="metric-value"><?php echo $stat_projects; ?></div><div class="metric-label">Submitted projects</div></div></div>
    <div class="metric metric-review"><span class="metric-icon" aria-hidden="true">🔎</span><div><div class="metric-value"><?php echo $stat_review; ?></div><div class="metric-label">Under review</div></div></div>
    <div class="metric metric-approved"><span class="metric-icon" aria-hidden="true">✓</span><div><div class="metric-value"><?php echo $stat_approved; ?></div><div class="metric-label">Approved projects</div></div></div>
    <div class="metric metric-revision"><span class="metric-icon" aria-hidden="true">✎</span><div><div class="metric-value"><?php echo $stat_revision; ?></div><div class="metric-label">Need revision</div></div></div>
  </section>

  <div class="dashboard-workspace">
    <section class="dashboard-section" aria-labelledby="chapter-progress-title">
      <div class="section-heading"><div><h2 id="chapter-progress-title">Chapter progress</h2><p><?php echo $active_project ? 'Status for your most recently updated project.' : 'Chapter status will appear after you create a project.'; ?></p></div><span class="section-count"><?php echo $approved_chapters; ?>/5 approved</span></div>
      <div class="chapter-list">
        <?php
        $chapter_titles = [
            1 => ['Chapter 1', 'The Problem and Its Background'],
            2 => ['Chapter 2', 'Review of Related Literature'],
            3 => ['Chapter 3', 'Methodology'],
            4 => ['Chapter 4', 'Results and Discussion'],
            5 => ['Chapter 5', 'Summary, Conclusions, and Recommendations'],
        ];
        foreach ($chapter_titles as $number => $chapter):
            $chapter_status = $chapter_progress[$number] ?? 'draft';
            $chapter_class = '';
            if ($chapter_status === 'approved') $chapter_class = 'completed';
            elseif ($chapter_status === 'under_review') $chapter_class = 'review';
            elseif ($chapter_status === 'revision_required') $chapter_class = 'revision';
            $badge_class = $chapter_class === 'completed' ? 'approved' : $chapter_class;
        ?>
          <div class="chapter-item"><span class="chapter-number <?php echo $chapter_class; ?>"><?php echo $number; ?></span><div><div class="chapter-title"><?php echo htmlspecialchars($chapter[0], ENT_QUOTES, 'UTF-8'); ?></div><div class="chapter-desc"><?php echo htmlspecialchars($chapter[1], ENT_QUOTES, 'UTF-8'); ?></div></div><span class="chapter-status <?php echo $badge_class; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $chapter_status)), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>

    <aside class="dashboard-section workflow-panel" aria-labelledby="workflow-title">
      <div class="section-heading"><div><h2 id="workflow-title">Research workflow</h2><p><?php echo $active_project ? 'Your current institutional review stage.' : 'Start with a proposal submission.'; ?></p></div></div>
      <?php $workflow_steps = ['Proposal', 'CREC evaluation', 'EREC forum', 'Approval', 'Implementation']; ?>
      <ol class="workflow-list">
        <?php foreach ($workflow_steps as $index => $step): ?>
          <?php
          $workflow_class = $index < $current_workflow_stage ? 'completed' : ($index === $current_workflow_stage ? 'active' : '');
          if ($current_workflow_stage >= count($workflow_steps)) $workflow_class = 'completed';
          $workflow_label = $workflow_class === 'completed' ? 'Completed' : ($workflow_class === 'active' ? 'Current stage' : 'Upcoming');
          ?>
          <li class="workflow-step <?php echo $workflow_class; ?>"><span class="workflow-mark" aria-hidden="true"><?php echo $workflow_class === 'completed' ? '✓' : $index + 1; ?></span><div><div class="workflow-name"><?php echo htmlspecialchars($step, ENT_QUOTES, 'UTF-8'); ?></div><div class="workflow-state"><?php echo $workflow_label; ?></div></div></li>
        <?php endforeach; ?>
      </ol>
    </aside>
  </div>

  <div class="dashboard-secondary-grid">
    <section class="dashboard-section" aria-labelledby="activity-title">
      <div class="section-heading"><div><h2 id="activity-title">Recent activity</h2><p>Your latest research and account updates.</p></div><a class="dashboard-text-link" href="<?php echo SITE_URL; ?>pages/shared/notifications.php">View all <span aria-hidden="true">→</span></a></div>
      <?php if ($notifications->num_rows > 0): ?>
        <ul class="activity-feed">
          <?php
          $notification_icons = ['success' => '✓', 'warning' => '!', 'error' => '×', 'info' => 'i'];
          while ($notification = $notifications->fetch_assoc()):
              $type = in_array($notification['type'], ['success', 'warning', 'error', 'info'], true) ? $notification['type'] : 'info';
          ?>
            <li class="activity-item"><span class="activity-icon <?php echo $type; ?>" aria-hidden="true"><?php echo $notification_icons[$type]; ?></span><div><p class="activity-message"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></p><div class="activity-date"><?php echo date('M d, Y', strtotime($notification['created_at'])); ?></div></div></li>
          <?php endwhile; ?>
        </ul>
      <?php else: ?>
        <div class="empty-compact"><div><div class="empty-compact-icon" aria-hidden="true">🔔</div><p>No recent activity yet.</p></div></div>
      <?php endif; ?>
    </section>

    <section class="dashboard-section" aria-labelledby="deadlines-title">
      <div class="section-heading"><div><h2 id="deadlines-title">Upcoming deadlines</h2><p>Report dates connected to your projects.</p></div><a class="dashboard-text-link" href="<?php echo SITE_URL; ?>pages/shared/calendar.php">Calendar <span aria-hidden="true">→</span></a></div>
      <?php if ($upcoming_deadlines): ?>
        <ul class="deadline-list">
          <?php foreach ($upcoming_deadlines as $deadline): ?>
            <?php
            $date = new DateTimeImmutable($deadline['due_date']);
            $days = (int) (new DateTimeImmutable('today'))->diff($date)->format('%a');
            $due = $days === 0 ? 'Due today' : ($days === 1 ? 'Due tomorrow' : 'Due in ' . $days . ' days');
            ?>
            <li class="deadline-item"><div class="deadline-date" aria-hidden="true"><strong><?php echo $date->format('d'); ?></strong><span><?php echo $date->format('M'); ?></span></div><div><p class="deadline-name"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $deadline['report_type'])), ENT_QUOTES, 'UTF-8'); ?></p><div class="deadline-meta"><?php echo htmlspecialchars($due, ENT_QUOTES, 'UTF-8'); ?> for <?php echo htmlspecialchars($deadline['title'], ENT_QUOTES, 'UTF-8'); ?></div></div></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-compact"><div><div class="empty-compact-icon" aria-hidden="true">📅</div><p>No upcoming report deadlines.</p></div></div>
      <?php endif; ?>
    </section>
  </div>

  <section class="dashboard-section projects-section" aria-labelledby="projects-title">
    <div class="section-heading"><div><h2 id="projects-title">My research projects</h2><p>Open a project to review its files, feedback, and current status.</p></div><a class="dashboard-button primary" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">New research</a></div>
    <?php if ($projects->num_rows > 0): ?>
      <div class="projects-table-wrap">
        <table class="projects-table">
          <thead><tr><th>Research title</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php
            $projects->data_seek(0);
            while ($project = $projects->fetch_assoc()):
                $project_url = SITE_URL . 'pages/student/research-detail.php?id=' . (int) $project['project_id'];
                $project_status_class = 'status-' . str_replace('_', '-', strtolower($project['status']));
            ?>
              <tr><td><a class="project-title-link" href="<?php echo htmlspecialchars($project_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?></a></td><td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td><td><span class="status-badge <?php echo htmlspecialchars($project_status_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $project['status'])), ENT_QUOTES, 'UTF-8'); ?></span></td><td><a class="table-action" href="<?php echo htmlspecialchars($project_url, ENT_QUOTES, 'UTF-8'); ?>">View project</a></td></tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="project-empty"><div class="project-empty-icon" aria-hidden="true">📁</div><h3>No research projects yet</h3><p>Create your first proposal to begin the review process.</p><a class="dashboard-button primary" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">Start a proposal</a></div>
    <?php endif; ?>
  </section>
</div>

<?php renderStudentShellClose(); ?>
