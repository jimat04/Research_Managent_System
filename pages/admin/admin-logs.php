<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$user_filter = $_GET['user'] ?? '';
$module_filter = $_GET['module'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($user_filter)) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
    $types .= 'i';
}

if (!empty($module_filter)) {
    $where_clauses[] = "al.module = ?";
    $params[] = $module_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_clauses[] = "al.action LIKE ?";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get activity logs
$stmt = $conn->prepare("
    SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) as user_name, u.email, u.role
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    {$where_sql}
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result();

// Get total count
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);
$count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_log al {$where_sql}");
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_logs = $count_stmt->get_result()->fetch_assoc()['count'];
$total_pages = ceil($total_logs / $per_page);

// Get unique modules for filter
$modules = $conn->query("SELECT DISTINCT module FROM activity_log WHERE module IS NOT NULL ORDER BY module");

// Get total log count
$total_log_count = (int) ($conn->query("SELECT COUNT(*) as count FROM activity_log")->fetch_assoc()['count'] ?? 0);
$summary_result = $conn->query(
    "SELECT COUNT(DISTINCT user_id) AS actors,
            COUNT(DISTINCT module) AS modules,
            SUM(DATE(created_at) = CURDATE()) AS today_count
       FROM activity_log"
);
$summary_row = $summary_result ? ($summary_result->fetch_assoc() ?: []) : [];
$active_actors = (int) ($summary_row['actors'] ?? 0);
$module_count = (int) ($summary_row['modules'] ?? 0);
$today_count = (int) ($summary_row['today_count'] ?? 0);
$visible_logs = $logs ? (int) $logs->num_rows : 0;
$active_filter_count = (int) (!empty($search)) + (int) (!empty($module_filter)) + (int) (!empty($user_filter));

renderAdminShell(
    $user,
    'admin-logs',
    'System Logs',
    'Review the institute activity trail and operational history.'
);

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  html{scroll-behavior:smooth}.logs-workspace{--logs-ink:#192235;--logs-gold:#d2a248;--logs-line:#dfe5ed;--logs-muted:#687386;max-width:1480px;margin:0 auto;color:var(--logs-ink)}
  .logs-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:46px;min-height:330px;padding:51px 55px 61px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 84% 14%,rgba(210,162,72,.2),transparent 29%),linear-gradient(135deg,#172033,#202d45 64%,#27344b);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.logs-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.16;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.logs-kicker,.log-stat-code,.ledger-eyebrow,.metric-index{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.logs-kicker{margin-bottom:18px;color:#e9bf6e}.logs-hero h2{max-width:790px;margin:0;font-size:clamp(40px,4.6vw,66px);line-height:.98;letter-spacing:-.055em;text-wrap:balance}.logs-hero-copy>p{max-width:620px;margin:24px 0 0;color:#bdc8d8;font-size:15px;line-height:1.74;text-wrap:pretty}.logs-note{display:inline-flex;align-items:center;gap:9px;margin-top:24px;color:#aeb9ca;font-size:12px}.logs-note::before{content:'';width:7px;height:7px;border-radius:50%;background:#70bd91;box-shadow:0 0 0 5px rgba(112,189,145,.11)}
  .log-stats{align-self:end;display:grid;gap:2px}.log-stat{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.log-stat:first-child{border-radius:14px 14px 5px 5px}.log-stat:last-child{border-radius:5px 5px 14px 14px}.log-stat-code{color:#e9bf6e}.log-stat-label{color:#d4dce8;font-size:13px}.log-stat-value{font-size:24px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
  .log-metrics{position:relative;z-index:2;display:grid;grid-template-columns:1.18fr repeat(3,1fr);gap:2px;margin:-22px 22px 0}.log-metric{position:relative;min-height:136px;padding:23px 24px 20px;overflow:hidden;border:1px solid #e2e7ee;background:#fff}.log-metric:first-child{border-radius:16px 5px 5px 16px}.log-metric:last-child{border-radius:5px 16px 16px 5px}.log-metric::after{content:'';position:absolute;right:-20px;bottom:-32px;width:76px;height:76px;border:17px solid var(--metric-accent,#64748b);border-radius:50%;opacity:.075}.metric-total{--metric-accent:#8b6528;background:#fcfaf5}.metric-visible{--metric-accent:#315b8c}.metric-pages{--metric-accent:#705487}.metric-filter{--metric-accent:#347451}.metric-index{margin-bottom:22px;color:var(--metric-accent)}.metric-value{font-size:33px;font-weight:720;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums}.metric-label{margin-top:8px;color:var(--logs-muted);font-size:13px;font-weight:620}
  .logs-ledger{margin-top:38px;overflow:hidden;border:1px solid var(--logs-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.065)}.ledger-header{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:28px 31px 23px}.ledger-eyebrow{margin-bottom:8px;color:#987027}.ledger-title{margin:0;color:#1c2639;font-size:25px;line-height:1.1;letter-spacing:-.03em}.ledger-copy{max-width:660px;margin:8px 0 0;color:var(--logs-muted);font-size:13px;line-height:1.55}.ledger-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}
  .filter-bar{display:grid;grid-template-columns:minmax(230px,1fr) minmax(190px,auto) auto auto;gap:9px;align-items:center;margin:0;padding:13px 31px 16px;border-top:1px solid #edf0f4;border-bottom:1px solid #e5e9ef;background:#f5f7fa}.search-input,.filter-select{width:100%;min-height:42px;padding:9px 13px;border:1px solid #d8dfe7;border-radius:8px;background:#fff;color:#000!important;font-family:inherit;font-size:13px;line-height:1.45}.search-input:focus,.filter-select:focus{outline:0;border-color:#b88731;box-shadow:0 0 0 3px rgba(210,162,72,.16)}.filter-select{cursor:pointer}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:9px 14px;border:1px solid transparent;border-radius:8px;background:none;color:inherit;font-family:inherit;font-size:12px;font-weight:680;line-height:1.2;text-decoration:none;cursor:pointer;transition:transform .2s ease,background .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease}.btn:hover{transform:translateY(-1px)}.btn:active{transform:translateY(0) scale(.98)}.btn:focus-visible{outline:3px solid rgba(210,162,72,.28);outline-offset:2px}.btn-primary{border-color:var(--logs-gold);background:var(--logs-gold);color:#182033}.btn-primary:hover{border-color:#dfb45f;background:#dfb45f}.btn-secondary{border-color:#d8dfe8;background:#fff;color:#344054}.btn-secondary:hover{border-color:#b3bdca;background:#f7f8fa}.btn-sm{min-height:36px;padding:8px 12px;font-size:11px}
  .table-wrap{overflow-x:auto}.logs-table{width:100%;min-width:980px;border-collapse:collapse}.logs-table thead{background:#fafbfc}.logs-table th{padding:12px 16px;border:0;color:#7a8494;font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.11em;text-align:left;text-transform:uppercase}.logs-table th:first-child,.logs-table td:first-child{padding-left:31px}.logs-table th:last-child,.logs-table td:last-child{padding-right:31px}.logs-table td{padding:17px 16px;border-top:1px solid #edf0f4;color:#475467;font-size:13px;vertical-align:middle}.logs-table tbody tr{transition:background .2s ease}.logs-table tbody tr:hover{background:#fbfaf7}.log-time{color:#596579;font:600 11px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.log-user{color:#202a3d;font-weight:660}.log-email{display:block;margin-top:3px;color:#8a95a5;font-size:10px}.log-module{display:inline-flex;padding:5px 8px;border:1px solid #dce3ea;border-radius:5px;background:#f3f6f8;color:#526074;font:680 9px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.055em;text-transform:uppercase}.log-action{max-width:520px;color:#39465a;line-height:1.5;overflow-wrap:anywhere}.badge{display:inline-flex;padding:5px 8px;border:1px solid #dce2e9;border-radius:5px;background:#f4f6f8;color:#586477;font-size:9px;font-weight:720;letter-spacing:.055em;text-transform:uppercase}.badge-admin{border-color:#eadaba;background:#faf4e8;color:#865f24}.badge-staff{border-color:#d2e7e2;background:#eff8f6;color:#27695f}.badge-faculty{border-color:#d7e3ef;background:#f0f5fa;color:#315b8c}.badge-student{border-color:#e3d9e9;background:#f7f2f8;color:#72528c}.logs-empty{padding:62px 24px!important;text-align:center!important}.empty-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 14px;border:1px solid #e3d4b4;border-radius:14px;background:#fbf6eb;color:#876225;font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.logs-empty strong{display:block;color:#344054;font-size:16px}.logs-empty span{display:block;margin-top:7px;color:#8490a1;font-size:13px}
  .pagination{display:flex;justify-content:center;align-items:center;gap:12px;padding:18px 22px;border-top:1px solid #edf0f4;background:#fafbfc}.page-label{color:#758093;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace}
  @media(max-width:1040px){.logs-hero{grid-template-columns:1fr;gap:30px}.log-stats{grid-template-columns:repeat(3,1fr)}.log-stat{grid-template-columns:32px 1fr}.log-stat-value{grid-column:2}.filter-bar{grid-template-columns:1fr 1fr}.filter-bar .search-input{grid-column:1/-1}}
  @media(max-width:760px){.logs-hero{min-height:0;padding:31px 24px 50px;border-radius:18px 18px 7px 7px}.logs-hero h2{font-size:40px}.log-stats{grid-template-columns:1fr}.log-stat{grid-template-columns:34px 1fr auto}.log-stat-value{grid-column:auto}.log-metrics{grid-template-columns:repeat(4,minmax(150px,1fr));margin:-18px 12px 0;overflow-x:auto}.log-metric{min-width:150px}.logs-ledger{margin-top:28px}.ledger-header{align-items:flex-start;flex-direction:column;padding:24px 20px 20px}.filter-bar{grid-template-columns:1fr 1fr;padding:14px 20px 17px}.filter-select{grid-column:1/-1}.table-wrap{overflow:visible}.logs-table{min-width:0}.logs-table thead{display:none}.logs-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.logs-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.logs-table tbody td{display:grid;grid-template-columns:88px minmax(0,1fr);width:100%;padding:11px 14px;border-top:1px solid #edf0f4;text-align:left;overflow-wrap:anywhere}.logs-table tbody td:first-child{padding:15px 14px;border-top:0}.logs-table tbody td:last-child{padding:13px 14px}.logs-table tbody td::before{content:attr(data-label);margin:2px 12px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.logs-table .logs-empty{display:block}.logs-table .logs-empty::before{display:none}.pagination{flex-wrap:wrap}}
  @media(prefers-reduced-motion:reduce){.btn,.logs-table tbody tr{transition:none}}
</style>
<div class="logs-workspace">
  <section class="logs-hero" aria-labelledby="logs-hero-title">
    <div class="logs-hero-copy">
      <div class="logs-kicker">System accountability &middot; Institute audit trail</div>
      <h2 id="logs-hero-title">Every system action, kept in view.</h2>
      <p>Review who changed what, where it happened, and when. The ledger gives administrators a clear operational history without exposing any editing controls.</p>
      <div class="logs-note">Read-only activity history generated by RMS</div>
    </div>
    <div class="log-stats" aria-label="Activity overview">
      <div class="log-stat"><span class="log-stat-code">01</span><span class="log-stat-label">Activity today</span><strong class="log-stat-value"><?php echo number_format($today_count); ?></strong></div>
      <div class="log-stat"><span class="log-stat-code">02</span><span class="log-stat-label">Recorded actors</span><strong class="log-stat-value"><?php echo number_format($active_actors); ?></strong></div>
      <div class="log-stat"><span class="log-stat-code">03</span><span class="log-stat-label">Tracked modules</span><strong class="log-stat-value"><?php echo number_format($module_count); ?></strong></div>
    </div>
  </section>

  <section class="log-metrics" aria-label="Ledger metrics">
    <article class="log-metric metric-total"><div class="metric-index">Archive</div><div class="metric-value"><?php echo number_format($total_log_count); ?></div><div class="metric-label">Total activities</div></article>
    <article class="log-metric metric-visible"><div class="metric-index">Result</div><div class="metric-value"><?php echo number_format((int) $total_logs); ?></div><div class="metric-label">Matching records</div></article>
    <article class="log-metric metric-pages"><div class="metric-index">Range</div><div class="metric-value"><?php echo number_format(max(1, (int) $total_pages)); ?></div><div class="metric-label">Result pages</div></article>
    <article class="log-metric metric-filter"><div class="metric-index">Focus</div><div class="metric-value"><?php echo number_format($active_filter_count); ?></div><div class="metric-label">Active filters</div></article>
  </section>

  <section class="logs-ledger" id="activity-ledger" aria-labelledby="activity-ledger-title">
    <header class="ledger-header">
      <div><div class="ledger-eyebrow">Audit ledger</div><h3 class="ledger-title" id="activity-ledger-title">Recorded system activity</h3><p class="ledger-copy">Filter the permanent activity stream by action or module. Newest events appear first.</p></div>
      <span class="ledger-count"><?php echo number_format($visible_logs); ?> SHOWN</span>
    </header>

    <form method="GET" action="admin-logs.php#activity-ledger" class="filter-bar" role="search">
      <input type="text" name="search" class="search-input" aria-label="Search actions" placeholder="Search actions..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
      <select name="module" class="filter-select" aria-label="Filter by module" onchange="this.form.submit()">
        <option value="">All Modules</option>
        <?php while ($mod = $modules->fetch_assoc()): ?>
          <option value="<?php echo htmlspecialchars($mod['module'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $module_filter === $mod['module'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $mod['module'])), ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
      <?php if ($active_filter_count > 0): ?><a href="admin-logs.php#activity-ledger" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>

    <div class="table-wrap">
      <table class="logs-table">
        <thead><tr><th>Timestamp</th><th>Actor</th><th>Role</th><th>Module</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($logs->num_rows > 0): ?>
            <?php while ($log = $logs->fetch_assoc()): ?>
              <?php
              $role_badges = ['student' => 'badge-student', 'faculty' => 'badge-faculty', 'research_staff' => 'badge-staff', 'admin' => 'badge-admin'];
              $role_labels = ['student' => 'Student', 'faculty' => 'Faculty', 'research_staff' => 'Staff', 'admin' => 'Admin'];
              $log_role = (string) ($log['role'] ?? '');
              $badge_class = $role_badges[$log_role] ?? 'badge-student';
              $role_label = $role_labels[$log_role] ?? ($log_role !== '' ? ucwords(str_replace('_', ' ', $log_role)) : 'System');
              ?>
              <tr>
                <td data-label="Time"><span class="log-time"><?php echo htmlspecialchars(date('M d, Y', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8'); ?><br><?php echo htmlspecialchars(date('h:i A', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td data-label="Actor"><span class="log-user"><?php echo htmlspecialchars($log['user_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?></span><?php if (!empty($log['email'])): ?><span class="log-email"><?php echo htmlspecialchars($log['email'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></td>
                <td data-label="Role"><span class="badge <?php echo htmlspecialchars($badge_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td data-label="Module"><span class="log-module"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['module'] ?? 'N/A')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td data-label="Action"><span class="log-action"><?php echo htmlspecialchars($log['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5" class="logs-empty"><div class="empty-mark">00</div><strong>No matching activity</strong><span>Adjust the action or module filter to broaden the ledger.</span></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
      <nav class="pagination" aria-label="Activity log pagination">
        <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&amp;search=' . urlencode($search) : ''; ?><?php echo $module_filter ? '&amp;module=' . urlencode($module_filter) : ''; ?><?php echo $user_filter ? '&amp;user=' . urlencode($user_filter) : ''; ?>#activity-ledger" class="btn btn-sm btn-secondary">&larr; Previous</a><?php endif; ?>
        <span class="page-label">PAGE <?php echo number_format($page); ?> OF <?php echo number_format((int) $total_pages); ?></span>
        <?php if ($page < $total_pages): ?><a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&amp;search=' . urlencode($search) : ''; ?><?php echo $module_filter ? '&amp;module=' . urlencode($module_filter) : ''; ?><?php echo $user_filter ? '&amp;user=' . urlencode($user_filter) : ''; ?>#activity-ledger" class="btn btn-sm btn-secondary">Next &rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</div>

<?php
renderAdminShellClose();
