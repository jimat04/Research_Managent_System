<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');
$user = getCurrentUser();
$user_id = (int) $user['user_id'];

$column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$has_deleted_at = false;
if ($column_stmt) {
    $column_stmt->execute();
    $has_deleted_at = $column_stmt->get_result()->num_rows > 0;
    $column_stmt->close();
}
$deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

$query = "
    SELECT rp.project_id, rp.title, rp.category_id, rp.ay_id, rp.status,
           rp.created_by, rp.created_at, rp.updated_at, rc.category_name,
           aa.label AS ay_label, aa.semester, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM chapters WHERE project_id = rp.project_id AND status = 'approved') AS approved_chapters
    FROM research_projects rp
    LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
    LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE (
        rp.created_by = ?
        OR rp.project_id IN (SELECT project_id FROM project_members WHERE user_id = ?)
    )
    {$deleted_filter}
    ORDER BY rp.updated_at DESC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die('Unable to load research projects.');
}
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$projects = [];
while ($project = $result->fetch_assoc()) {
    $projects[] = $project;
}
$stmt->close();

$total_projects = count($projects);
$under_review = 0;
$for_revision = 0;
$completed = 0;
foreach ($projects as $project) {
    if (in_array($project['status'], ['submitted', 'under_review', 'under_crec_review', 'under_erec_review', 'proposal'], true)) {
        $under_review++;
    } elseif (in_array($project['status'], ['for_revision', 'revision_required'], true)) {
        $for_revision++;
    } elseif (in_array($project['status'], ['completed', 'archived'], true)) {
        $completed++;
    }
}

$status_display = [
    'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under Review',
    'under_crec_review' => 'CREC Review', 'under_erec_review' => 'EREC Review',
    'for_revision' => 'For Revision', 'revision_required' => 'Revision Required',
    'approved' => 'Approved', 'ongoing' => 'Ongoing', 'proposal' => 'Proposal',
    'in_progress' => 'In Progress', 'for_defense' => 'For Defense',
    'completed' => 'Completed', 'archived' => 'Archived',
];

renderStudentShell($user, 'my-research', 'My Research', 'Track your projects through review, implementation, and completion.');
?>

<style>
  .student-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 4%,rgba(91,30,188,.12),transparent 27%),radial-gradient(circle at 8% 42%,rgba(37,99,235,.07),transparent 25%),linear-gradient(180deg,#F4F1FA 0%,#ECE8F5 100%)}
  .student-topbar{background:#FBFAFE;border-bottom-color:#DDD6EA}
  .research-page{--accent:#5B1EBC;--accent-dark:#42148B;--accent-soft:#F0E6FC;--review:#2563EB;--review-soft:#EAF1FF;--success:#15805B;--success-soft:#E7F6EF;--attention:#C45D12;--attention-soft:#FFF0E3;--ink:#151728;--copy:#5D6477;--muted:#8990A2;--line:#DED8EA;max-width:1440px;margin:0 auto;color:var(--ink)}
  .research-page *{box-sizing:border-box}
  .research-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(260px,.75fr);gap:40px;overflow:hidden;margin-bottom:24px;padding:38px 40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 92% 14%,rgba(213,190,255,.25),transparent 28%),radial-gradient(circle at 8% 110%,rgba(50,111,232,.22),transparent 30%),linear-gradient(135deg,#291050 0%,#4C188F 55%,#6C2CC7 100%);color:#fff;box-shadow:0 22px 52px rgba(54,24,103,.23)}
  .research-hero::after{content:'';position:absolute;right:-70px;bottom:-130px;width:280px;height:280px;border:44px solid rgba(255,255,255,.055);border-radius:50%;pointer-events:none}
  .research-hero-copy,.research-hero-summary{position:relative;z-index:1}.research-eyebrow{margin:0 0 10px;color:#DCCBFF;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
  .research-hero h1{max-width:650px;margin:0;font-size:clamp(30px,3vw,46px);font-weight:700;letter-spacing:-.045em;line-height:1.08;text-wrap:balance}.research-hero-copy>p:not(.research-eyebrow){max-width:58ch;margin:16px 0 24px;color:#E5DDF1;font-size:15px;line-height:1.7}
  .research-primary-action{display:inline-flex;align-items:center;justify-content:center;min-height:43px;padding:0 19px;border:1px solid transparent;border-radius:10px;background:#fff;color:var(--accent-dark);font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 10px 22px rgba(25,8,54,.22);transition:transform .2s ease,background-color .2s ease,box-shadow .2s ease}.research-primary-action:hover{background:#F2EAFB;transform:translateY(-1px);box-shadow:0 13px 26px rgba(25,8,54,.28)}.research-primary-action:active{transform:scale(.98)}
  .research-primary-action:focus-visible,.project-title:focus-visible,.project-action:focus-visible,.research-control:focus-visible{outline:2px solid #8B5CF6;outline-offset:3px}
  .research-hero-summary{display:grid;align-content:center;padding-left:32px;border-left:1px solid rgba(255,255,255,.24)}.hero-summary-label{margin-bottom:12px;color:#D9CDE9;font-size:12px;font-weight:600}.hero-summary-value{font-size:clamp(42px,5vw,64px);font-weight:700;letter-spacing:-.055em;line-height:.9;font-variant-numeric:tabular-nums}.hero-summary-copy{max-width:28ch;margin:13px 0 0;color:#E7DDF4;font-size:13px;line-height:1.55}
  .research-metrics{display:grid;grid-template-columns:repeat(4,1fr);overflow:hidden;margin-bottom:24px;border:1px solid var(--line);border-radius:16px;box-shadow:0 9px 28px rgba(48,28,85,.07)}
  .research-metric{position:relative;display:grid;grid-template-columns:40px minmax(0,1fr);gap:12px;align-items:center;padding:21px 22px;background:var(--metric-bg)}.research-metric+.research-metric{border-left:1px solid rgba(90,76,112,.12)}.research-metric::before{content:'';position:absolute;inset:0 0 auto;height:3px;background:var(--metric-color)}
  .research-metric.total{--metric-color:var(--accent);--metric-bg:#F7F1FD;--metric-soft:#E8D9FA}.research-metric.review{--metric-color:var(--review);--metric-bg:#F0F5FF;--metric-soft:#D9E7FF}.research-metric.revision{--metric-color:var(--attention);--metric-bg:#FFF6ED;--metric-soft:#FFE0C5}.research-metric.complete{--metric-color:var(--success);--metric-bg:#EFF9F4;--metric-soft:#D6F0E3}
  .research-metric-icon{display:grid;place-items:center;width:40px;height:40px;border-radius:10px;background:var(--metric-soft);color:var(--metric-color);font-size:17px}.research-metric-value{color:var(--metric-color);font-size:26px;font-weight:700;letter-spacing:-.04em;line-height:1;font-variant-numeric:tabular-nums}.research-metric-label{margin-top:5px;color:var(--copy);font-size:12px;font-weight:600}
  .research-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) minmax(180px,240px) auto;gap:12px;align-items:end;margin-bottom:18px;padding:20px;border:1px solid rgba(91,30,188,.15);border-radius:16px;background:rgba(251,249,255,.86);box-shadow:0 10px 28px rgba(54,35,88,.07);backdrop-filter:blur(12px)}
  .research-field{display:grid;gap:7px}.research-field label{color:var(--copy);font-size:11px;font-weight:700}.research-control{width:100%;height:43px;padding:0 13px;border:1px solid #D8D1E5;border-radius:10px;background:#fff;color:var(--ink);font:500 13px/1.2 inherit;transition:border-color .2s ease,box-shadow .2s ease}.research-control::placeholder{color:#8B91A1}.research-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(91,30,188,.11)}
  .research-results{align-self:center;padding:0 4px;color:var(--copy);font-size:12px;font-weight:650;white-space:nowrap;font-variant-numeric:tabular-nums}
  .project-list{display:grid;gap:14px}.project-record{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(420px,.7fr);gap:28px;padding:26px 28px;border:1px solid rgba(91,30,188,.13);border-left:4px solid var(--status-color,var(--accent));border-radius:16px;background:#FCFAFF;box-shadow:0 10px 28px rgba(48,30,80,.065);transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease}.project-record[hidden]{display:none}.project-record:hover{transform:translateY(-2px);border-color:rgba(91,30,188,.24);box-shadow:0 16px 36px rgba(48,30,80,.11)}
  .project-record[data-status="submitted"],.project-record[data-status="under_review"],.project-record[data-status="under_crec_review"]{--status-color:var(--review)}.project-record[data-status="under_erec_review"]{--status-color:var(--accent)}.project-record[data-status="for_revision"],.project-record[data-status="revision_required"]{--status-color:var(--attention)}.project-record[data-status="approved"],.project-record[data-status="ongoing"],.project-record[data-status="completed"]{--status-color:var(--success)}.project-record[data-status="archived"]{--status-color:#64748B}
  .project-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.project-title{max-width:700px;color:var(--ink);font-size:18px;font-weight:700;letter-spacing:-.02em;line-height:1.35;text-decoration:none;text-wrap:pretty}.project-title:hover{color:var(--accent)}
  .status-badge{flex:none;display:inline-flex;align-items:center;min-height:27px;padding:5px 10px;border-radius:999px;background:#EDF0F5;color:#526071;font-size:10px;font-weight:700;white-space:nowrap}.status-badge.info{background:var(--review-soft);color:var(--review)}.status-badge.primary{background:var(--accent-soft);color:var(--accent)}.status-badge.warning{background:var(--attention-soft);color:var(--attention)}.status-badge.success{background:var(--success-soft);color:var(--success)}
  .project-description{margin:10px 0 18px;color:var(--copy);font-size:12px;line-height:1.55}.project-progress{display:flex;align-items:center;gap:12px;max-width:420px}.project-progress-track{flex:1;height:5px;overflow:hidden;border-radius:999px;background:#E7E0EF}.project-progress-fill{display:block;width:var(--chapter-progress);height:100%;border-radius:inherit;background:var(--status-color,var(--accent))}.project-progress-label{color:var(--copy);font-size:11px;font-weight:650;white-space:nowrap;font-variant-numeric:tabular-nums}
  .project-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 22px;align-content:start;padding-left:26px;border-left:1px solid var(--line)}.project-detail-label{display:block;margin-bottom:3px;color:var(--muted);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.project-detail-value{display:block;overflow:hidden;color:var(--ink);font-size:12px;font-weight:600;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}
  .project-actions{grid-column:1/-1;display:flex;gap:8px;margin-top:3px}.project-action{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border:1px solid transparent;border-radius:9px;font-size:12px;font-weight:700;text-decoration:none;transition:transform .2s ease,background-color .2s ease,border-color .2s ease}.project-action.view{background:var(--accent);color:#fff}.project-action.view:hover{background:var(--accent-dark)}.project-action.edit{border-color:#D8D1E5;background:#fff;color:var(--ink)}.project-action.edit:hover{border-color:rgba(91,30,188,.3);color:var(--accent)}.project-action.disabled{border-color:#E2DEE9;background:#F4F2F7;color:#9A96A3;cursor:not-allowed}.project-action:not(.disabled):active{transform:scale(.98)}
  .no-results,.research-empty{padding:48px 24px;border:1px dashed rgba(91,30,188,.28);border-radius:16px;background:rgba(252,250,255,.84);text-align:center}.no-results[hidden]{display:none}.empty-icon{margin-bottom:12px;font-size:36px}.no-results h2,.research-empty h2{margin:0 0 8px;font-size:20px}.no-results p,.research-empty p{max-width:48ch;margin:0 auto 20px;color:var(--copy);font-size:13px;line-height:1.6}.research-empty .research-primary-action{background:var(--accent);color:#fff}.research-empty .research-primary-action:hover{background:var(--accent-dark)}
  @media(prefers-reduced-motion:no-preference){.research-hero,.research-metrics,.research-toolbar,.project-record,.research-empty{animation:researchEnter .48s cubic-bezier(.16,1,.3,1) both}.research-metrics{animation-delay:.06s}.research-toolbar{animation-delay:.12s}.project-record{animation-delay:calc(.16s + var(--row-index,0)*.045s)}}
  @keyframes researchEnter{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @media(max-width:1100px){.research-metrics{grid-template-columns:repeat(2,1fr)}.research-metric:nth-child(3){border-top:1px solid rgba(90,76,112,.12);border-left:0}.research-metric:nth-child(4){border-top:1px solid rgba(90,76,112,.12)}.project-record{grid-template-columns:1fr}.project-details{padding:20px 0 0;border-top:1px solid var(--line);border-left:0}}
  @media(max-width:760px){.research-hero{grid-template-columns:1fr;padding:30px 24px}.research-hero-summary{padding:24px 0 0;border-top:1px solid rgba(255,255,255,.24);border-left:0}.research-toolbar{grid-template-columns:1fr}.research-results{padding-top:3px}.project-title-row{display:grid}.status-badge{justify-self:start}.project-details{grid-template-columns:1fr}.project-actions{grid-column:1}.project-action{flex:1}}
  @media(max-width:520px){.research-metrics{grid-template-columns:1fr}.research-metric+.research-metric,.research-metric:nth-child(3){border-top:1px solid rgba(90,76,112,.12);border-left:0}.project-record{padding:22px 18px}.research-primary-action{width:100%}}
  @media(prefers-reduced-motion:reduce){.research-page *{animation:none!important;transition:none!important}}
</style>

<div class="research-page">
  <section class="research-hero" aria-labelledby="research-page-title">
    <div class="research-hero-copy">
      <p class="research-eyebrow">Research portfolio</p>
      <h1 id="research-page-title">Your work, from proposal to archive.</h1>
      <p>Follow every submission, chapter approval, and review decision without losing sight of what comes next.</p>
      <a class="research-primary-action" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">New research</a>
    </div>
    <div class="research-hero-summary">
      <span class="hero-summary-label">Projects in your workspace</span>
      <strong class="hero-summary-value"><?php echo $total_projects; ?></strong>
      <p class="hero-summary-copy"><?php echo $total_projects === 1 ? 'One project is connected to your account.' : $total_projects . ' projects are connected to your account.'; ?></p>
    </div>
  </section>

  <?php if ($total_projects > 0): ?>
    <section class="research-metrics" aria-label="Project summary">
      <div class="research-metric total"><span class="research-metric-icon" aria-hidden="true">▦</span><div><div class="research-metric-value"><?php echo $total_projects; ?></div><div class="research-metric-label">Total projects</div></div></div>
      <div class="research-metric review"><span class="research-metric-icon" aria-hidden="true">⌕</span><div><div class="research-metric-value"><?php echo $under_review; ?></div><div class="research-metric-label">Under review</div></div></div>
      <div class="research-metric revision"><span class="research-metric-icon" aria-hidden="true">✎</span><div><div class="research-metric-value"><?php echo $for_revision; ?></div><div class="research-metric-label">For revision</div></div></div>
      <div class="research-metric complete"><span class="research-metric-icon" aria-hidden="true">✓</span><div><div class="research-metric-value"><?php echo $completed; ?></div><div class="research-metric-label">Completed</div></div></div>
    </section>

    <section class="research-toolbar" aria-label="Project filters">
      <div class="research-field"><label for="searchInput">Search projects</label><input class="research-control" type="search" id="searchInput" placeholder="Search by research title"></div>
      <div class="research-field">
        <label for="statusFilter">Project status</label>
        <select class="research-control" id="statusFilter">
          <option value="">All statuses</option>
          <?php foreach ($status_display as $status_value => $status_label): ?>
            <option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="research-results" id="resultCount" aria-live="polite"><?php echo $total_projects; ?> shown</div>
    </section>

    <section class="project-list" id="projectList" aria-label="Research projects">
      <?php foreach ($projects as $index => $project): ?>
        <?php
        $status = $project['status'] ?? 'draft';
        $status_label = $status_display[$status] ?? ucwords(str_replace('_', ' ', $status));
        $badge_class = 'default';
        if (in_array($status, ['submitted', 'under_review', 'under_crec_review', 'proposal'], true)) $badge_class = 'info';
        elseif (in_array($status, ['under_erec_review', 'in_progress'], true)) $badge_class = 'primary';
        elseif (in_array($status, ['for_revision', 'revision_required', 'for_defense'], true)) $badge_class = 'warning';
        elseif (in_array($status, ['approved', 'ongoing', 'completed'], true)) $badge_class = 'success';
        $approved_chapters = min(5, (int) $project['approved_chapters']);
        $progress_percent = $approved_chapters * 20;
        $project_url = SITE_URL . 'pages/student/research-detail.php?id=' . (int) $project['project_id'];
        $can_edit = in_array($status, ['draft', 'for_revision', 'revision_required'], true);
        $lead_name = trim(($project['first_name'] ?? '') . ' ' . ($project['last_name'] ?? ''));
        ?>
        <article class="project-record" data-title="<?php echo htmlspecialchars(strtolower($project['title']), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" style="--row-index:<?php echo (int) $index; ?>">
          <div>
            <div class="project-title-row"><a class="project-title" href="<?php echo htmlspecialchars($project_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?></a><span class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <p class="project-description"><?php echo htmlspecialchars($project['category_name'] ?? 'Uncategorized research', ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="project-progress" aria-label="<?php echo $approved_chapters; ?> of 5 chapters approved"><span class="project-progress-track" aria-hidden="true"><span class="project-progress-fill" style="--chapter-progress:<?php echo $progress_percent; ?>%"></span></span><span class="project-progress-label"><?php echo $approved_chapters; ?>/5 chapters</span></div>
          </div>
          <div class="project-details">
            <div><span class="project-detail-label">Academic term</span><span class="project-detail-value"><?php echo htmlspecialchars(($project['ay_label'] ?? 'Not assigned') . (!empty($project['semester']) ? ' / ' . $project['semester'] : ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div><span class="project-detail-label">Research lead</span><span class="project-detail-value"><?php echo htmlspecialchars($lead_name !== '' ? $lead_name : 'Not assigned', ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div><span class="project-detail-label">Last updated</span><span class="project-detail-value"><?php echo date('M d, Y', strtotime($project['updated_at'])); ?></span></div>
            <div><span class="project-detail-label">Your access</span><span class="project-detail-value"><?php echo (int) $project['created_by'] === $user_id ? 'Owner' : 'Project member'; ?></span></div>
            <div class="project-actions">
              <a class="project-action view" href="<?php echo htmlspecialchars($project_url, ENT_QUOTES, 'UTF-8'); ?>">View project</a>
              <?php if ($can_edit): ?>
                <a class="project-action edit" href="<?php echo SITE_URL; ?>pages/student/edit-research.php?id=<?php echo (int) $project['project_id']; ?>">Edit</a>
              <?php else: ?>
                <span class="project-action disabled" aria-disabled="true" title="Editing is disabled while this project is in review.">Edit locked</span>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
    <section class="no-results" id="noResults" hidden><div class="empty-icon" aria-hidden="true">⌕</div><h2>No matching projects</h2><p>Try a different title or choose another project status.</p></section>
  <?php else: ?>
    <section class="research-empty"><div class="empty-icon" aria-hidden="true">📁</div><h2>No research projects yet</h2><p>Create your first proposal and follow it through CREC and EREC review, implementation, and completion.</p><a class="research-primary-action" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">Start a proposal</a></section>
  <?php endif; ?>
</div>

<script>
(() => {
  const searchInput = document.getElementById('searchInput');
  const statusFilter = document.getElementById('statusFilter');
  const projectRows = Array.from(document.querySelectorAll('.project-record'));
  const resultCount = document.getElementById('resultCount');
  const noResults = document.getElementById('noResults');
  if (!searchInput || !statusFilter) return;

  const filterProjects = () => {
    const searchTerm = searchInput.value.trim().toLocaleLowerCase();
    const statusValue = statusFilter.value;
    let visible = 0;
    projectRows.forEach((row) => {
      const matchesTitle = (row.dataset.title || '').includes(searchTerm);
      const matchesStatus = statusValue === '' || row.dataset.status === statusValue;
      row.hidden = !(matchesTitle && matchesStatus);
      if (!row.hidden) visible++;
    });
    if (resultCount) resultCount.textContent = visible + ' shown';
    if (noResults) noResults.hidden = visible !== 0;
  };

  searchInput.addEventListener('input', filterProjects);
  statusFilter.addEventListener('change', filterProjects);
})();
</script>

<?php renderStudentShellClose(); ?>
