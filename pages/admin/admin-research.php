<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get research statistics
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$research_submitted = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'submitted'")->fetch_assoc()['count'] ?? 0);
$research_returned = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('for_revision', 'revision_required')")->fetch_assoc()['count'] ?? 0);
$research_title_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'title_approved'")->fetch_assoc()['count'] ?? 0);
$research_awaiting_review = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('draft', 'submitted')")->fetch_assoc()['count'] ?? 0);
$research_in_revision = $research_returned;
$research_implementing = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('title_approved', 'ongoing', 'in_progress')")->fetch_assoc()['count'] ?? 0);
$research_completed = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'completed'")->fetch_assoc()['count'] ?? 0);
$research_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

// Detect optional soft-delete support so contributor listings remain schema-compatible.
$rp_has_deleted_at        = (bool) ($conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'")->num_rows ?? 0);
$rp_deleted_filter_alias  = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// Get all research projects with filters
$status_filter = $_GET['status'] ?? '';
$research_view = in_array($status_filter, ['ongoing', 'completed'], true) ? $status_filter : '';
$research_view_title = $research_view === 'ongoing' ? 'Ongoing Research' : 'Completed Research';
$research_view_description = $research_view === 'ongoing'
    ? 'Research titles approved for implementation and projects currently in progress.'
    : 'Research projects that have completed their implementation work.';
$queue_filter = $_GET['queue'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($status_filter)) {
    if ($status_filter === 'ongoing') {
        $where_clauses[] = "rp.status IN ('title_approved', 'ongoing', 'in_progress')";
    } elseif ($status_filter === 'for_revision') {
        $where_clauses[] = "rp.status IN ('for_revision', 'revision_required')";
    } else {
        $where_clauses[] = "rp.status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
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

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "
    SELECT rp.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           u.email as student_email,
           CONCAT(f.first_name, ' ', f.last_name) as adviser_name
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    LEFT JOIN project_advisers pa ON rp.project_id = pa.project_id
    LEFT JOIN users f ON pa.adviser_id = f.user_id
    {$where_sql}
    ORDER BY rp.created_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$projects = $stmt->get_result();
$visible_projects = $projects->num_rows;
$finished_total = $research_completed + $research_archived;
$directory_context = $research_view !== ''
    ? ' in ' . strtolower($research_view_title)
    : ((!empty($search) || !empty($status_filter)) ? ' for the current filters' : '');

$author_project_rows = ['faculty' => [], 'student' => []];
$author_researcher_counts = ['faculty' => 0, 'student' => 0];
$author_project_capped = ['faculty' => false, 'student' => false];
$author_project_query = "
    SELECT u.user_id, u.first_name, u.last_name, u.department, u.program,
           rp.project_id, rp.title, rp.status
      FROM (
          SELECT project_id, created_by AS user_id
            FROM research_projects
          UNION
          SELECT project_id, user_id
            FROM project_members
      ) AS project_authors
      INNER JOIN research_projects rp ON rp.project_id = project_authors.project_id
      INNER JOIN users u ON u.user_id = project_authors.user_id
     WHERE u.role = ? AND u.deleted_at IS NULL" . $rp_deleted_filter_alias . "
     ORDER BY u.last_name ASC, u.first_name ASC, rp.created_at DESC, rp.project_id DESC
     LIMIT 101
";

foreach (['faculty', 'student'] as $author_role) {
    $author_stmt = $conn->prepare($author_project_query);
    if (!$author_stmt) {
        throw new RuntimeException('Unable to prepare research authorship listing: ' . $conn->error);
    }
    $author_stmt->bind_param('s', $author_role);
    if (!$author_stmt->execute()) {
        $error = $author_stmt->error;
        $author_stmt->close();
        throw new RuntimeException('Unable to load research authorship listing: ' . $error);
    }

    $author_result = $author_stmt->get_result();
    $author_project_capped[$author_role] = $author_result->num_rows > 100;
    $researcher_ids = [];
    while ($author_row = $author_result->fetch_assoc()) {
        if (count($author_project_rows[$author_role]) >= 100) {
            break;
        }
        $author_project_rows[$author_role][] = $author_row;
        $researcher_ids[(int) $author_row['user_id']] = true;
    }
    $author_researcher_counts[$author_role] = count($researcher_ids);
    $author_stmt->close();
}

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 16px;
    padding: 20px;
    transition: all 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }

  .stat-number {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }

  .stat-label {
    font-size: 14px;
    color: var(--text-secondary, #64748B);
    font-weight: 500;
  }

  /* CARD */
  .card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 24px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
  }

  .card-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--charcoal, #111827);
  }

  /* FILTER BAR */
  .filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 24px;
  }

  .search-input {
    flex: 1;
    min-width: 250px;
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-surface, #F8FAFC);
  }

  .filter-select {
    padding: 10px 16px;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-card, #FFFFFF);
    cursor: pointer;
  }

  /* BUTTON */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
  }

  .btn-secondary {
    background: var(--bg-surface, #F8FAFC);
    color: var(--text-primary, #111827);
    border: 1px solid var(--border, #E5E7EB);
  }

  .btn-secondary:hover {
    background: #E5E7EB;
  }

  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
  }

  /* TABLE */
  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border, #E5E7EB);
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead {
    background: var(--bg-surface, #F8FAFC);
  }

  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748B);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid var(--border, #E5E7EB);
  }

  tr:hover {
    background: var(--bg-surface, #F8FAFC);
  }

  /* BADGE */
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .badge-draft { background: #F1F5F9; color: #64748B; }
  .badge-proposal { background: #DBEAFE; color: #2563EB; }
  .badge-crec { background: #DBEAFE; color: #3B82F6; }
  .badge-erec { background: #F3E8FF; color: #7C3AED; }
  .badge-approved { background: #DCFCE7; color: #16A34A; }
  .badge-ongoing { background: #D1FAE5; color: #059669; }
  .badge-completed { background: #D1FAE5; color: #059669; }
  .badge-archived { background: #F1F5F9; color: #475569; }

  .btn-primary {
    background: var(--accent-primary, #F57C00);
    color: #ffffff;
  }
  .btn-primary:hover { background: var(--accent-hover, #EA580C); }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<style>
  .research-workspace { --ink:#182033; --gold:#d3a348; --line:#dfe5ed; --muted:#667085; max-width:1480px; margin:0 auto; color:var(--ink); }
  .research-workspace .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
  .research-hero { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr); gap:48px; min-height:340px; padding:52px 56px 60px; overflow:hidden; border-radius:24px 24px 8px 8px; background:radial-gradient(circle at 84% 12%,rgba(211,163,72,.2),transparent 28%),linear-gradient(135deg,#172033 0%,#1d2940 58%,#263149 100%); color:#fff; box-shadow:0 26px 60px rgba(24,32,51,.17); }
  .research-workspace-focused .research-hero { grid-template-columns:1fr; min-height:300px; }
  .research-hero::after { content:''; position:absolute; inset:0; z-index:-1; opacity:.16; background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); pointer-events:none; }
  .research-kicker,.section-eyebrow,.metric-index { font:700 11px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.14em; text-transform:uppercase; }
  .research-kicker { margin-bottom:18px; color:#e8bd67; }
  .research-hero h2 { max-width:800px; margin:0; font-size:clamp(38px,4.6vw,66px); line-height:.98; letter-spacing:-.055em; text-wrap:balance; }
  .research-hero-copy>p { max-width:640px; margin:24px 0 0; color:#bdc7d7; font-size:15px; line-height:1.75; }
  .hero-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:28px; }
  .hero-note { display:inline-flex; align-items:center; gap:8px; margin-top:18px; color:#9eabbf; font-size:12px; }
  .hero-note::before { content:''; width:7px; height:7px; border-radius:50%; background:#70bd91; box-shadow:0 0 0 5px rgba(112,189,145,.12); }
  .lifecycle-snapshot { align-self:end; display:grid; gap:2px; }
  .snapshot-row { display:grid; grid-template-columns:42px 1fr auto; align-items:center; gap:12px; padding:14px 16px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.065); }
  .snapshot-row:first-child { border-radius:14px 14px 5px 5px; }
  .snapshot-row:last-child { border-radius:5px 5px 14px 14px; }
  .snapshot-code { color:#e8bd67; font:700 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .snapshot-label { color:#d5dce8; font-size:13px; }
  .snapshot-value { font-size:24px; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.03em; }
  .research-focus-total { display:inline-flex; align-items:baseline; gap:10px; margin-top:24px; padding-top:16px; border-top:1px solid rgba(255,255,255,.16); color:#d5dce8; }
  .research-focus-total strong { color:#fff; font-size:34px; line-height:1; font-variant-numeric:tabular-nums; }
  .research-focus-total span { font-size:13px; }

  .research-metrics { position:relative; z-index:2; display:grid; grid-template-columns:1.2fr repeat(4,1fr); gap:2px; margin:-22px 22px 0; }
  .research-metric { position:relative; min-height:142px; padding:24px 24px 21px; overflow:hidden; border:1px solid #e3e8ef; background:#fff; }
  .research-metric:first-child { border-radius:16px 5px 5px 16px; }
  .research-metric:last-child { border-radius:5px 16px 16px 5px; }
  .research-metric::after { content:''; position:absolute; right:-20px; bottom:-32px; width:76px; height:76px; border:17px solid var(--metric-accent,#64748b); border-radius:50%; opacity:.08; }
  .metric-total { --metric-accent:#172033; background:#fcfaf5; }.metric-proposal{--metric-accent:#315b8c}.metric-review{--metric-accent:#705487}.metric-implementation{--metric-accent:#347451}.metric-finished{--metric-accent:#8b6528}
  .metric-index { margin-bottom:24px; color:var(--metric-accent); }
  .metric-value { font-size:34px; font-weight:720; line-height:1; letter-spacing:-.04em; font-variant-numeric:tabular-nums; }
  .metric-label { margin-top:8px; color:var(--muted); font-size:13px; font-weight:600; }

  .research-directory { margin-top:38px; overflow:hidden; border:1px solid var(--line); border-radius:18px; background:#fff; box-shadow:0 14px 38px rgba(31,42,63,.07); }
  .research-directory { margin-top:24px; }
  .directory-header { display:flex; justify-content:space-between; align-items:end; gap:24px; padding:30px 32px 24px; }
  .section-eyebrow { margin-bottom:9px; color:#9a7228; }
  .section-title { margin:0; color:var(--ink); font-size:25px; line-height:1.1; letter-spacing:-.025em; }
  .section-copy { max-width:720px; margin:9px 0 0; color:var(--muted); font-size:13px; line-height:1.6; }
  .author-directory-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:20px; margin-top:28px; }
  .author-directory { min-width:0; overflow:hidden; border:1px solid var(--line); border-radius:16px; background:#fff; box-shadow:0 10px 28px rgba(31,42,63,.05); }
  .author-directory-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:22px 24px 18px; border-bottom:1px solid #e8ecf1; }
  .author-directory-header .section-eyebrow { margin-bottom:7px; }
  .author-directory-header .section-title { font-size:21px; }
  .author-directory-header .section-copy { margin-top:7px; font-size:12px; }
  .author-directory-count { flex:0 0 auto; padding:7px 9px; border:1px solid #e5d5b3; border-radius:8px; background:#fbf7ed; color:#8a6424; font-size:11px; font-weight:700; white-space:nowrap; }
  .author-table-wrap { overflow-x:auto; }
  .research-workspace .author-table { width:100%; min-width:540px; border-collapse:collapse; }
  .research-workspace .author-table th { padding:11px 14px; font-size:9px; }
  .research-workspace .author-table th:first-child,.research-workspace .author-table td:first-child { padding-left:24px; }
  .research-workspace .author-table th:last-child,.research-workspace .author-table td:last-child { padding-right:24px; }
  .research-workspace .author-table td { padding:13px 14px; font-size:12px; }
  .author-profile-link { color:#263247; font-weight:680; text-decoration:none; }
  .author-profile-link:hover,.author-project-link:hover { color:#8a6424; text-decoration:underline; text-underline-offset:2px; }
  .author-profile-link:focus-visible,.author-project-link:focus-visible { outline:3px solid rgba(211,163,72,.28); outline-offset:3px; border-radius:2px; }
  .author-project-link { color:#315b8c; font-weight:650; text-decoration:none; }
  .author-status { display:inline-flex; padding:4px 7px; border:1px solid #dfe3e8; border-radius:6px; background:#f4f5f6; color:#525e6d; font-size:10px; font-weight:680; white-space:nowrap; }
  .author-limit-note { margin:0; padding:12px 24px; border-top:1px solid #edf0f4; color:#687386; font-size:11px; line-height:1.5; }

  .research-workspace .table-wrap { overflow-x:auto; border:0; border-radius:0; }
  .research-table { width:100%; min-width:1040px; border-collapse:collapse; }
  .research-table thead { background:#fafbfc; }
  .research-table th { padding:12px 16px; color:#7a8494; font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.11em; text-align:left; text-transform:uppercase; }
  .research-table th:first-child,.research-table td:first-child { padding-left:32px; }
  .research-table th:last-child,.research-table td:last-child { padding-right:32px; }
  .research-table td { padding:18px 16px; border-top:1px solid #edf0f4; color:#475467; font-size:13px; vertical-align:middle; }
  .research-table tbody tr { transition:background .2s ease; }
  .research-table tbody tr:hover { background:#fbfaf7; }
  .project-title { display:block; max-width:430px; color:#20293b; font-size:14px; font-weight:680; line-height:1.4; text-decoration:none; text-wrap:pretty; }
  .project-title:hover { color:#8a6424; }
  .project-ref { margin-top:5px; color:#8a94a3; font:600 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .person-name { color:#20293b; font-weight:650; }
  .person-meta,.stakeholder-meta { margin-top:4px; color:#8490a1; font-size:11px; }
  .date-cell { white-space:nowrap; color:#687386; font-variant-numeric:tabular-nums; }
  .row-actions { display:flex; flex-wrap:wrap; gap:7px; min-width:280px; }
  .research-workspace .badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border:1px solid transparent; border-radius:6px; font-size:11px; font-weight:680; white-space:nowrap; }
  .research-workspace .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; opacity:.65; }
  .research-workspace .badge-draft{border-color:#e3e7eb;background:#f3f5f7;color:#6b7280}.research-workspace .badge-proposal{border-color:#d8e5f0;background:#edf4fa;color:#315b8c}.research-workspace .badge-crec{border-color:#d8e5f0;background:#edf4fa;color:#315b8c}.research-workspace .badge-erec{border-color:#e7dcec;background:#f5f0f7;color:#705487}.research-workspace .badge-approved{border-color:#eadaba;background:#faf4e8;color:#8b6528}.research-workspace .badge-ongoing,.research-workspace .badge-completed{border-color:#d4ebdc;background:#edf8f1;color:#347451}.research-workspace .badge-archived{border-color:#dfe3e8;background:#f4f5f6;color:#525e6d}

  .research-workspace .filter-bar { display:grid; grid-template-columns:minmax(280px,1fr) 210px auto auto; gap:10px; align-items:center; margin:0; padding:14px 32px; border-top:1px solid #edf0f4; border-bottom:1px solid #e5e9ef; background:#f5f7fa; }
  .search-field { position:relative; }
  .search-field::before { content:'\2315'; position:absolute; left:15px; top:50%; transform:translateY(-52%) rotate(-20deg); color:#7b8798; font-size:19px; pointer-events:none; }
  .research-workspace .search-input,.research-workspace .filter-select { width:100%; min-width:0; min-height:44px; box-sizing:border-box; border:1px solid #d9e0e8; border-radius:9px; background:#fff; color:#000; font-family:inherit; font-size:14px; font-weight:500; }
  .research-workspace .search-input { padding:10px 14px 10px 43px; }.research-workspace .filter-select{padding:10px 13px}
  .research-workspace .search-input:focus,.research-workspace .filter-select:focus { outline:none; border-color:#bc8e37; box-shadow:0 0 0 3px rgba(211,163,72,.16); }
  .research-workspace .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:9px 16px; border:1px solid transparent; border-radius:8px; font-family:inherit; font-size:13px; font-weight:650; line-height:1.2; text-decoration:none; cursor:pointer; transition:transform .2s ease,border-color .2s ease,background .2s ease,color .2s ease,box-shadow .2s ease; }
  .research-workspace .btn:hover { transform:translateY(-1px); }.research-workspace .btn:active{transform:translateY(0) scale(.98)}
  .research-workspace .btn:focus-visible { outline:3px solid rgba(211,163,72,.28); outline-offset:2px; }
  .research-workspace .btn-primary { border-color:#d3a348; background:#d3a348; color:#182033; box-shadow:0 8px 20px rgba(211,163,72,.16); }
  .research-workspace .btn-primary:hover { border-color:#dfb45f; background:#dfb45f; }.research-workspace .btn-secondary{border-color:#dce2e9;background:#fff;color:#344054}.research-workspace .btn-secondary:hover{border-color:#b9c2ce;background:#f7f8fa}
  .research-workspace .btn-sm { min-height:34px; padding:7px 11px; font-size:12px; }
  .hero-action{min-height:45px!important;padding-inline:18px!important}.hero-action-primary{background:#d3a348!important;color:#182033!important}.hero-action-secondary{border-color:rgba(255,255,255,.18)!important;background:rgba(255,255,255,.055)!important;color:#fff!important}.hero-action-secondary:hover{border-color:#e8bd67!important;background:rgba(255,255,255,.1)!important}
  .empty-state { padding:54px 24px; text-align:center; }.empty-state-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#8b6528;font:700 16px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.empty-state strong{display:block;color:#344054;font-size:16px}.empty-state p{max-width:520px;margin:7px auto 0;color:#8490a1;font-size:13px;line-height:1.55}
  @media(max-width:1120px){.research-hero{grid-template-columns:1fr;gap:32px}.lifecycle-snapshot{grid-template-columns:repeat(3,1fr)}.snapshot-row{grid-template-columns:34px 1fr}.snapshot-value{grid-column:2}.research-metrics{grid-template-columns:repeat(5,minmax(150px,1fr));overflow-x:auto}}
  @media(max-width:768px){.research-hero{min-height:0;padding:30px 24px 50px;border-radius:18px 18px 7px 7px}.research-hero h2{font-size:38px}.lifecycle-snapshot{grid-template-columns:1fr}.snapshot-row{grid-template-columns:36px 1fr auto}.snapshot-value{grid-column:auto}.research-metrics{margin:-18px 12px 0}.research-metric{min-width:150px}.directory-header{align-items:stretch;padding:25px 20px 20px;flex-direction:column}.research-workspace .filter-bar{grid-template-columns:1fr;padding:14px 20px 18px}.research-workspace .filter-bar .btn{width:100%}.research-table{min-width:0}.research-table thead{display:none}.research-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.research-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.research-table tbody td{display:grid;grid-template-columns:92px minmax(0,1fr);width:100%;padding:12px 14px;border-top:1px solid #edf0f4;text-align:left;overflow-wrap:anywhere}.research-table tbody td:first-child{padding:16px 14px;border-top:0}.research-table tbody td:last-child{padding:14px}.research-table tbody td::before{content:attr(data-label);margin:2px 12px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.research-table tbody td:first-child::before{display:none}.row-actions{min-width:0}.row-actions .btn,.row-actions form{flex:1 1 auto}.row-actions form .btn{width:100%}.empty-row{display:block!important}.empty-row::before{display:none}}
  @media(max-width:768px){.research-workspace .author-table{min-width:0}.author-directory-header{padding:20px}.author-directory-count{font-size:10px}}
  @media(prefers-reduced-motion:reduce){.research-workspace .btn,.research-table tbody tr{transition:none}}
</style>
<?php

renderAdminShell(
    $user,
    'admin-research',
    $research_view !== '' ? $research_view_title : 'Research Management',
    $research_view !== '' ? $research_view_description : 'Track research from title submission through review, chapter approval, implementation, and completion.'
);
?>

<div class="research-workspace<?php echo $research_view !== '' ? ' research-workspace-focused' : ''; ?>">

    <section class="research-hero" aria-labelledby="research-command-title">
      <div class="research-hero-copy">
        <div class="research-kicker"><?php echo $research_view !== '' ? htmlspecialchars($research_view_title, ENT_QUOTES, 'UTF-8') : 'Research lifecycle &middot; Administrative command'; ?></div>
        <h2 id="research-command-title"><?php echo $research_view !== '' ? htmlspecialchars($research_view_title, ENT_QUOTES, 'UTF-8') : 'Every project, one clear line of sight.'; ?></h2>
        <p><?php echo htmlspecialchars($research_view !== '' ? $research_view_description : 'Track research from title submission through review, chapter approval, implementation, and completion.', ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="hero-actions">
          <?php if ($research_view !== ''): ?>
            <a class="btn hero-action hero-action-primary" href="#research-directory">View <?php echo strtolower($research_view_title); ?> <span aria-hidden="true">&#8594;</span></a>
            <a class="btn hero-action hero-action-secondary" href="<?php echo SITE_URL; ?>pages/admin/admin-research.php">All research</a>
          <?php else: ?>
            <a class="btn hero-action hero-action-primary" href="<?php echo SITE_URL; ?>pages/shared/research-submission-review.php">Open review queue <span aria-hidden="true">&#8594;</span></a>
            <a class="btn hero-action hero-action-secondary" href="#research-directory">Browse all projects</a>
          <?php endif; ?>
        </div>
        <?php if ($research_view !== ''): ?>
          <div class="research-focus-total"><strong><?php echo $research_view === 'ongoing' ? $research_implementing : $research_completed; ?></strong><span>matching projects</span></div>
        <?php endif; ?>
      </div>

      <?php if ($research_view === ''): ?>
      <div class="lifecycle-snapshot" aria-label="Research review status">
        <div class="snapshot-row"><span class="snapshot-code">01</span><span class="snapshot-label">Titles awaiting review</span><strong class="snapshot-value"><?php echo $research_submitted; ?></strong></div>
        <div class="snapshot-row"><span class="snapshot-code">02</span><span class="snapshot-label">Returned for revision</span><strong class="snapshot-value"><?php echo $research_returned; ?></strong></div>
        <div class="snapshot-row"><span class="snapshot-code">03</span><span class="snapshot-label">Title approved</span><strong class="snapshot-value"><?php echo $research_title_approved; ?></strong></div>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($research_view === ''): ?>
    <section class="research-metrics" aria-label="Research totals">
      <article class="research-metric metric-total"><div class="metric-index">Portfolio</div><div class="metric-value"><?php echo $total_research; ?></div><div class="metric-label">Total projects</div></article>
      <article class="research-metric metric-proposal"><div class="metric-index">Intake</div><div class="metric-value"><?php echo $research_awaiting_review; ?></div><div class="metric-label">Awaiting review</div></article>
      <article class="research-metric metric-review"><div class="metric-index">Revision</div><div class="metric-value"><?php echo $research_in_revision; ?></div><div class="metric-label">In revision</div></article>
      <article class="research-metric metric-implementation"><div class="metric-index">Delivery</div><div class="metric-value"><?php echo $research_implementing; ?></div><div class="metric-label">Implementing</div></article>
      <article class="research-metric metric-finished"><div class="metric-index">Record</div><div class="metric-value"><?php echo $finished_total; ?></div><div class="metric-label">Completed or archived</div></article>
    </section>

    <section class="author-directory-grid" aria-label="Research project authors">
      <?php foreach (['faculty' => 'Faculty', 'student' => 'Students'] as $author_role => $author_label): ?>
        <section class="author-directory" aria-labelledby="<?php echo $author_role; ?>-authors-title">
          <div class="author-directory-header">
            <div>
              <div class="section-eyebrow">Research contributors</div>
              <h3 class="section-title" id="<?php echo $author_role; ?>-authors-title"><?php echo $author_label; ?></h3>
              <p class="section-copy">Researchers linked as project owners or team members, with their authored RMS projects.</p>
            </div>
            <span class="author-directory-count"><?php echo number_format($author_researcher_counts[$author_role]); ?> researchers</span>
          </div>
          <?php if (empty($author_project_rows[$author_role])): ?>
            <div class="empty-state">
              <div class="empty-state-mark" aria-hidden="true">00</div>
              <strong>No <?php echo strtolower($author_label); ?> authors found</strong>
              <p>Authorship appears here when an account owns or joins a research project.</p>
            </div>
          <?php else: ?>
            <div class="author-table-wrap">
              <table class="research-table author-table">
                <thead>
                  <tr><th>Researcher</th><th>Research project</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($author_project_rows[$author_role] as $author_project): ?>
                    <tr>
                      <td data-label="Researcher">
                        <a class="author-profile-link" href="<?php echo SITE_URL; ?>pages/admin/admin-researchers.php?id=<?php echo (int) $author_project['user_id']; ?>">
                          <?php echo htmlspecialchars(trim($author_project['first_name'] . ' ' . $author_project['last_name']), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <div class="person-meta"><?php
                          $author_affiliation = $author_role === 'faculty' ? $author_project['department'] : $author_project['program'];
                          echo htmlspecialchars((string) ($author_affiliation ?: 'Affiliation not recorded'), ENT_QUOTES, 'UTF-8');
                        ?></div>
                      </td>
                      <td data-label="Research project">
                        <a class="author-project-link" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $author_project['project_id']; ?>">
                          <?php echo htmlspecialchars($author_project['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                      </td>
                      <td data-label="Status"><span class="author-status"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $author_project['status'])), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($author_project_capped[$author_role]): ?>
              <p class="author-limit-note">Showing the 100 most recent researcher-project links in this section. Refine the main project list below to review individual records.</p>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <!-- RESEARCH TABLE CARD -->
    <section class="research-directory" id="research-directory" aria-labelledby="research-directory-title">
      <div class="directory-header">
        <div>
          <div class="section-eyebrow"><?php echo $research_view !== '' ? htmlspecialchars($research_view_title, ENT_QUOTES, 'UTF-8') : 'Institute portfolio'; ?></div>
          <h3 class="section-title" id="research-directory-title"><?php echo $research_view !== '' ? htmlspecialchars($research_view_title, ENT_QUOTES, 'UTF-8') : 'All research projects'; ?></h3>
          <p class="section-copy"><?php echo $visible_projects; ?> project<?php echo $visible_projects === 1 ? '' : 's'; ?> shown<?php echo htmlspecialchars($directory_context, ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>
      </div>

      <!-- FILTERS -->
      <form method="GET" action="admin-research.php" class="filter-bar">
        <div class="search-field">
          <label for="researchSearch" class="sr-only">Search projects</label>
          <input id="researchSearch" type="search" name="search" class="search-input" placeholder="Search title or proponent" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label for="researchStatus" class="sr-only">Filter by project status</label>
        <select id="researchStatus" name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
          <option value="for_revision" <?php echo $status_filter === 'for_revision' ? 'selected' : ''; ?>>Returned for Revision</option>
          <option value="title_approved" <?php echo $status_filter === 'title_approved' ? 'selected' : ''; ?>>Title Approved</option>
          <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
          <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
        <?php if (!empty($search) || !empty($status_filter)): ?>
          <a href="admin-research.php" class="btn btn-secondary">Clear filters</a>
        <?php endif; ?>
      </form>

      <!-- TABLE -->
      <div class="table-wrap">
        <table class="research-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Student</th>
              <th>Adviser</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($projects->num_rows > 0): ?>
              <?php while ($project = $projects->fetch_assoc()): ?>
                <tr>
                  <td data-label="Project">
                    <a class="project-title" href="../shared/research-detail.php?id=<?php echo (int) $project['project_id']; ?>"><?php echo htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <div class="project-ref">PROJECT #<?php echo (int) $project['project_id']; ?></div>
                  </td>
                  <td data-label="Proponent"><span class="person-name"><?php echo htmlspecialchars($project['student_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="Adviser"><?php echo htmlspecialchars($project['adviser_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
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
                      'under_crec_review' => 'CREC Review',
                      'under_erec_review' => 'EREC Review',
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
                  <td data-label="Created" class="date-cell"><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                  <td data-label="Actions">
                    <a href="../shared/research-detail.php?id=<?php echo $project['project_id']; ?>" class="btn btn-secondary btn-sm">View</a>
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
    </section>
</div>

<?php
renderAdminShellClose();
?>
