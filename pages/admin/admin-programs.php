<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();
$success = '';
$error = '';

// The catalog is limited to the two supported courses. Existing IDs and
// historical records remain intact; this page cannot create or reassign programs.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $program_id = is_string($_POST['program_id'] ?? null) && ctype_digit($_POST['program_id'])
        ? (int) $_POST['program_id'] : 0;
    $program_name = is_string($_POST['program_name'] ?? null) ? trim($_POST['program_name']) : '';

    $csrf_token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!isCsrfTokenValid($csrf_token)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!in_array($action, ['edit_program', 'toggle_status'], true)) {
        $error = 'Only existing BSIT and BSCS programs can be managed.';
    } elseif ($program_id <= 0) {
        $error = 'Invalid program ID.';
    } elseif ($action === 'edit_program' && ($program_name === '' || mb_strlen($program_name, 'UTF-8') > 150)) {
        $error = 'Program name is required and must not exceed 150 characters.';
    } else {
        $check_stmt = $conn->prepare("SELECT program_code, status FROM programs WHERE program_id = ? AND program_code IN ('BSIT', 'BSCS')");
        $check_stmt->bind_param('i', $program_id);
        $check_stmt->execute();
        $program = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (!$program) {
            $error = 'Supported program not found.';
        } else {
            if ($action === 'edit_program') {
                $update_stmt = $conn->prepare("UPDATE programs SET program_name = ? WHERE program_id = ? AND program_code IN ('BSIT', 'BSCS')");
                $update_stmt->bind_param('si', $program_name, $program_id);
                $activity = "Updated program: {$program['program_code']} (ID: {$program_id})";
                $message = "Program {$program['program_code']} updated successfully.";
            } else {
                $new_status = (int) $program['status'] === 1 ? 0 : 1;
                $update_stmt = $conn->prepare("UPDATE programs SET status = ? WHERE program_id = ? AND program_code IN ('BSIT', 'BSCS')");
                $update_stmt->bind_param('ii', $new_status, $program_id);
                $action_label = $new_status === 1 ? 'Activated' : 'Deactivated';
                $activity = "{$action_label} program: {$program['program_code']} (ID: {$program_id})";
                $message = "Program {$program['program_code']} " . strtolower($action_label) . ' successfully.';
            }

            if ($update_stmt->execute()) {
                logActivity($activity, 'program_management');
                $success = $message;
            } else {
                $error = 'Failed to update program.';
            }
            $update_stmt->close();
        }
    }
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$status_filter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['0', '1'], true)
    ? $_GET['status'] : '';

$where_clauses = ["program_code IN ('BSIT', 'BSCS')"];
$params = [];
$types = '';

if ($search !== '') {
    $where_clauses[] = '(program_code LIKE ? OR program_name LIKE ?)';
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}
if ($status_filter !== '') {
    $where_clauses[] = 'status = ?';
    $params[] = (int) $status_filter;
    $types .= 'i';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
$stmt = $conn->prepare("SELECT program_id, program_code, program_name, status FROM programs {$where_sql} ORDER BY program_code ASC");
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$programs = $stmt->get_result();
$stmt->close();

$stats = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 1), 0) AS active, COALESCE(SUM(status = 0), 0) AS inactive FROM programs WHERE program_code IN ('BSIT', 'BSCS')")->fetch_assoc();
$total_programs = (int) $stats['total'];
$active_programs = (int) $stats['active'];
$inactive_programs = (int) $stats['inactive'];
$visible_programs = $programs->num_rows;
?>
<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }
  .stat-card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px;
  }
  .stat-number {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
    color: #111827;
  }
  .stat-label {
    font-size: 14px;
    color: #64748B;
    font-weight: 500;
  }
  .card {
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
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
    color: #111827;
  }
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
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #F8FAFC;
  }
  .filter-select {
    padding: 10px 16px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    background: #FFFFFF;
    cursor: pointer;
  }
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
  .btn-primary {
    background: #F57C00;
    color: white;
  }
  .btn-primary:hover {
    background: #EA580C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245,124,0,0.3);
  }
  .btn-secondary {
    background: #F8FAFC;
    color: #111827;
    border: 1px solid #E5E7EB;
  }
  .btn-secondary:hover {
    background: #E5E7EB;
  }
  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
  }
  .btn-danger {
    background: #EF4444;
    color: white;
  }
  .btn-success {
    background: #16A34A;
    color: white;
  }
  .table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  thead {
    background: #F8FAFC;
  }
  th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  td {
    padding: 16px;
    font-size: 14px;
    border-top: 1px solid #E5E7EB;
  }
  tr:hover {
    background: #F8FAFC;
  }
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }
  .badge-active { background: #DCFCE7; color: #16A34A; }
  .badge-inactive { background: #F1F5F9; color: #64748B; }
  .alert {
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .alert-success {
    background: #DCFCE7;
    color: #16A34A;
    border: 1px solid #86EFAC;
  }
  .alert-error {
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
  }
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }
  .modal-overlay.active { display: flex; }
  .modal {
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 32px;
  }
  .modal-header { margin-bottom: 24px; }
  .modal-title {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 8px;
  }
  .modal-subtitle {
    font-size: 14px;
    color: #64748B;
  }
  .modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
  }
  .form-group { margin-bottom: 20px; }
  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #111827;
  }
  .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
  }
  .form-control:focus {
    outline: none;
    border-color: #F57C00;
    box-shadow: 0 0 0 3px rgba(245,124,0,0.12);
  }
  .form-help {
    color: #94A3B8;
    font-size: 12px;
    margin-top: 4px;
  }
</style>
<style>
  .programs-workspace { --ink:#182033; --gold:#d3a348; --line:#dfe5ed; --muted:#667085; max-width:1480px; margin:0 auto; color:var(--ink); }
  .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }

  .programs-hero { position:relative; isolation:isolate; display:grid; grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr); gap:48px; min-height:330px; padding:52px 56px 58px; overflow:hidden; border-radius:24px 24px 8px 8px; background:radial-gradient(circle at 84% 12%,rgba(211,163,72,.2),transparent 28%),linear-gradient(135deg,#172033 0%,#1d2940 58%,#263149 100%); color:#fff; box-shadow:0 26px 60px rgba(24,32,51,.17); }
  .programs-hero::after { content:''; position:absolute; inset:0; z-index:-1; opacity:.16; background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px); pointer-events:none; }
  .programs-kicker,.directory-eyebrow,.metric-index { font:700 11px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.14em; text-transform:uppercase; }
  .programs-kicker { margin-bottom:18px; color:#e8bd67; }
  .programs-hero h2 { max-width:760px; margin:0; font-size:clamp(38px,4.6vw,66px); line-height:.98; letter-spacing:-.055em; text-wrap:balance; }
  .programs-hero-copy>p { max-width:620px; margin:24px 0 0; color:#bdc7d7; font-size:15px; line-height:1.75; }
  .hero-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:28px; }
  .hero-note { display:inline-flex; align-items:center; gap:8px; margin-top:18px; color:#9eabbf; font-size:12px; }
  .hero-note::before { content:''; width:7px; height:7px; border-radius:50%; background:#70bd91; box-shadow:0 0 0 5px rgba(112,189,145,.12); }
  .catalog-snapshot { align-self:end; display:grid; gap:2px; }
  .snapshot-row { display:grid; grid-template-columns:42px 1fr auto; align-items:center; gap:12px; padding:14px 16px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.065); }
  .snapshot-row:first-child { border-radius:14px 14px 5px 5px; }
  .snapshot-row:last-child { border-radius:5px 5px 14px 14px; }
  .snapshot-code { color:#e8bd67; font:700 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; }
  .snapshot-label { color:#d5dce8; font-size:13px; }
  .snapshot-value { font-size:24px; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.03em; }

  .program-metrics { position:relative; z-index:2; display:grid; grid-template-columns:1.2fr repeat(2,1fr); gap:2px; margin:-22px 22px 0; }
  .program-metric { position:relative; min-height:142px; padding:24px 24px 21px; overflow:hidden; border:1px solid #e3e8ef; background:#fff; }
  .program-metric:first-child { border-radius:16px 5px 5px 16px; }
  .program-metric:last-child { border-radius:5px 16px 16px 5px; }
  .program-metric::after { content:''; position:absolute; right:-20px; bottom:-32px; width:76px; height:76px; border:17px solid var(--metric-accent,#64748b); border-radius:50%; opacity:.08; }
  .metric-total { --metric-accent:#172033; background:#fcfaf5; }
  .metric-active { --metric-accent:#347451; }
  .metric-inactive { --metric-accent:#7d8795; }
  .metric-index { margin-bottom:24px; color:var(--metric-accent); }
  .metric-value { font-size:34px; font-weight:720; line-height:1; letter-spacing:-.04em; font-variant-numeric:tabular-nums; }
  .metric-label { margin-top:8px; color:var(--muted); font-size:13px; font-weight:600; }

  .program-directory { margin-top:38px; overflow:hidden; border:1px solid var(--line); border-radius:18px; background:#fff; box-shadow:0 14px 38px rgba(31,42,63,.07); }
  .directory-header { display:flex; justify-content:space-between; align-items:end; gap:24px; padding:30px 32px 24px; }
  .directory-eyebrow { margin-bottom:9px; color:#9a7228; }
  .directory-title { margin:0; color:var(--ink); font-size:25px; line-height:1.1; letter-spacing:-.025em; }
  .directory-meta { margin-top:8px; color:var(--muted); font-size:13px; }
  .programs-workspace .filter-bar { display:grid; grid-template-columns:minmax(260px,1fr) 160px auto auto; gap:10px; align-items:center; margin:0; padding:14px 32px; border-top:1px solid #edf0f4; border-bottom:1px solid #e5e9ef; background:#f5f7fa; }
  .search-field { position:relative; }
  .search-field::before { content:'\2315'; position:absolute; left:15px; top:50%; transform:translateY(-52%) rotate(-20deg); color:#7b8798; font-size:19px; pointer-events:none; }
  .programs-workspace .search-input,.programs-workspace .filter-select,.programs-workspace .form-control { width:100%; min-height:44px; box-sizing:border-box; border:1px solid #d9e0e8; border-radius:9px; background:#fff; color:#000; font-family:inherit; font-size:14px; font-weight:500; line-height:1.4; transition:border-color .2s ease,box-shadow .2s ease,background .2s ease; }
  .programs-workspace .search-input { min-width:0; padding:10px 14px 10px 43px; }
  .programs-workspace .filter-select,.programs-workspace .form-control { padding:10px 13px; }
  .programs-workspace .search-input:focus,.programs-workspace .filter-select:focus,.programs-workspace .form-control:focus { outline:none; border-color:#bc8e37; box-shadow:0 0 0 3px rgba(211,163,72,.16); }

  .programs-workspace .btn,.modal-overlay .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:9px 16px; border:1px solid transparent; border-radius:8px; font-family:inherit; font-size:13px; font-weight:650; line-height:1.2; text-decoration:none; cursor:pointer; transition:transform .2s ease,border-color .2s ease,background .2s ease,color .2s ease,box-shadow .2s ease; }
  .programs-workspace .btn:hover,.modal-overlay .btn:hover { transform:translateY(-1px); }
  .programs-workspace .btn:active,.modal-overlay .btn:active { transform:translateY(0) scale(.98); }
  .programs-workspace .btn:focus-visible,.modal-overlay .btn:focus-visible { outline:3px solid rgba(211,163,72,.28); outline-offset:2px; }
  .programs-workspace .btn-primary,.modal-overlay .btn-primary { border-color:#d3a348; background:#d3a348; color:#182033; box-shadow:0 8px 20px rgba(211,163,72,.16); }
  .programs-workspace .btn-primary:hover,.modal-overlay .btn-primary:hover { border-color:#dfb45f; background:#dfb45f; box-shadow:0 10px 24px rgba(211,163,72,.23); }
  .programs-workspace .btn-secondary,.modal-overlay .btn-secondary { border-color:#dce2e9; background:#fff; color:#344054; }
  .programs-workspace .btn-secondary:hover,.modal-overlay .btn-secondary:hover { border-color:#b9c2ce; background:#f7f8fa; }
  .programs-workspace .btn-sm { min-height:34px; padding:7px 11px; font-size:12px; }
  .programs-workspace .btn-danger { border-color:#f0d4cc; background:#fff8f6; color:#a7432f; }
  .programs-workspace .btn-danger:hover { border-color:#d99a8b; background:#faebe7; }
  .programs-workspace .btn-success { border-color:#cce4d6; background:#f3faf6; color:#27704a; }
  .programs-workspace .btn-success:hover { border-color:#91c5a7; background:#e7f5ed; }
  .hero-action { min-height:45px!important; padding-inline:18px!important; }
  .hero-action-secondary { border-color:rgba(255,255,255,.18)!important; background:rgba(255,255,255,.055)!important; color:#fff!important; }
  .hero-action-secondary:hover { border-color:#e8bd67!important; background:rgba(255,255,255,.1)!important; }

  .program-directory .table-wrap { overflow-x:auto; border:0; border-radius:0; }
  .program-table { width:100%; min-width:680px; border-collapse:collapse; }
  .program-table thead { background:#fafbfc; }
  .program-table th { padding:12px 18px; color:#7a8494; font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.11em; text-align:left; text-transform:uppercase; }
  .program-table th:first-child,.program-table td:first-child { padding-left:32px; }
  .program-table th:last-child,.program-table td:last-child { padding-right:32px; }
  .program-table td { padding:18px; border-top:1px solid #edf0f4; color:#475467; font-size:13px; vertical-align:middle; }
  .program-table tbody tr { transition:background .2s ease; }
  .program-table tbody tr:hover { background:#fbfaf7; }
  .program-code { display:inline-flex; min-width:72px; justify-content:center; padding:7px 10px; border:1px solid #e2d5b8; border-radius:7px; background:#fbf6eb; color:#79591f; font:750 12px/1 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.06em; }
  .program-name { color:#20293b; font-size:14px; font-weight:670; }
  .program-id { margin-top:4px; color:#8a94a3; font-size:11px; }
  .actions { display:flex; flex-wrap:wrap; gap:7px; }
  .programs-workspace .badge { display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border:1px solid transparent; border-radius:6px; font-size:11px; font-weight:680; white-space:nowrap; }
  .programs-workspace .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; opacity:.65; }
  .programs-workspace .badge-active { border-color:#d4ebdc; background:#edf8f1; color:#347451; }
  .programs-workspace .badge-inactive { border-color:#e3e7eb; background:#f3f5f7; color:#6b7280; }
  .empty-state { padding:58px 24px!important; text-align:center; }
  .empty-state strong { display:block; margin-bottom:6px; color:#344054; font-size:16px; }
  .empty-state span { color:#8490a1; }
  .programs-workspace .alert { display:flex; align-items:center; gap:12px; margin:0 0 22px; padding:14px 16px; border:1px solid; border-radius:10px; font-size:14px; }
  .programs-workspace .alert-success { border-color:#bcdcc9; background:#edf8f1; color:#2f6d4c; }
  .programs-workspace .alert-error { border-color:#e8c5bc; background:#fff3f0; color:#9b3f2d; }
  .alert-icon { display:grid; place-items:center; width:25px; height:25px; border-radius:7px; background:rgba(255,255,255,.65); font-weight:800; }

  .modal-overlay { padding:24px; background:rgba(12,18,29,.68); backdrop-filter:blur(7px); }
  .modal-overlay.active { animation:modal-fade .18s ease both; }
  .modal { width:min(100%,600px); max-height:calc(100dvh - 48px); padding:0; border:1px solid rgba(255,255,255,.6); border-radius:18px; box-shadow:0 30px 80px rgba(9,15,27,.3); animation:modal-rise .25s cubic-bezier(.2,.8,.2,1) both; }
  .modal-header { position:relative; margin:0; padding:28px 32px 24px; overflow:hidden; background:#182033; color:#fff; }
  .modal-header::after { content:''; position:absolute; right:-42px; top:-72px; width:170px; height:170px; border:30px solid rgba(211,163,72,.17); border-radius:50%; }
  .modal-title { position:relative; z-index:1; margin:0 0 6px; color:#fff; font-size:25px; font-weight:720; letter-spacing:-.03em; }
  .modal-subtitle { position:relative; z-index:1; color:#aeb9ca; font-size:13px; }
  .modal form { padding:28px 32px 32px; }
  .modal-footer { margin-top:28px; padding-top:22px; border-top:1px solid #e8ecf1; }
  .modal-footer .btn-secondary { border:2px solid #182033; background:#fff; color:#000; font-weight:700; }
  .modal-footer .btn-secondary:hover { border-color:#182033; background:#182033; color:#fff; }
  .modal .form-label { color:#000; font-size:13px; font-weight:700; }
  .modal .form-help { margin-top:6px; color:#000; font-size:11px; font-weight:600; }
  .modal .form-control { min-height:44px; border-color:#d9e0e8; border-radius:9px; color:#000; }
  @keyframes modal-fade { from{opacity:0} to{opacity:1} }
  @keyframes modal-rise { from{opacity:0;transform:translateY(12px) scale(.985)} to{opacity:1;transform:none} }

  @media (max-width:1120px) { .programs-hero{grid-template-columns:1fr;gap:32px}.catalog-snapshot{grid-template-columns:repeat(3,1fr)}.snapshot-row{grid-template-columns:34px 1fr}.snapshot-value{grid-column:2}.programs-workspace .filter-bar{grid-template-columns:minmax(240px,1fr) 150px auto}.programs-workspace .filter-bar>a{grid-column:1/-1;justify-self:start} }
  @media (max-width:768px) {
    .programs-hero{min-height:0;padding:30px 24px 50px;border-radius:18px 18px 7px 7px}.programs-hero h2{font-size:38px}.catalog-snapshot{grid-template-columns:1fr}.snapshot-row{grid-template-columns:36px 1fr auto}.snapshot-value{grid-column:auto}
    .program-metrics{grid-template-columns:repeat(3,minmax(150px,1fr));margin:-18px 12px 0;overflow-x:auto}.program-metric{min-width:150px}.directory-header{align-items:stretch;padding:25px 20px 20px;flex-direction:column}.directory-header .btn{width:100%}.programs-workspace .filter-bar{grid-template-columns:1fr;padding:14px 20px 18px}.programs-workspace .filter-bar .btn,.programs-workspace .filter-bar>a{grid-column:auto;width:100%}
    .program-table{min-width:0}.program-table thead{display:none}.program-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.program-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.program-table tbody td{display:grid;grid-template-columns:92px minmax(0,1fr);width:100%;padding:12px 14px;border-top:1px solid #edf0f4;text-align:left;overflow-wrap:anywhere}.program-table tbody td:first-child{padding:16px 14px;border-top:0}.program-table tbody td:last-child{padding:14px}.program-table tbody td::before{content:attr(data-label);margin:2px 12px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.program-table tbody td:first-child::before{display:none}.actions .btn{flex:1 1 auto}.empty-state{display:block!important}.empty-state::before{display:none}
    .modal-overlay{padding:12px}.modal{max-height:calc(100dvh - 24px)}.modal-header{padding:24px 22px 21px}.modal form{padding:24px 22px}
  }
  @media (prefers-reduced-motion:reduce) { .programs-workspace .btn,.program-table tbody tr{transition:none}.modal-overlay.active,.modal{animation:none} }
</style>
<?php

renderAdminShell(
    $user,
    'admin-programs',
    'Programs',
    'BS Information Technology and BS Computer Science program status.'
);
?>

<div class="programs-workspace">

    <?php if ($success): ?>
      <div class="alert alert-success">
        <span class="alert-icon" aria-hidden="true">&#10003;</span>
        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <span class="alert-icon" aria-hidden="true">&#215;</span>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <section class="programs-hero" aria-labelledby="programs-command-title">
      <div class="programs-hero-copy">
        <div class="programs-kicker">Academic catalog &middot; Institute registry</div>
        <h2 id="programs-command-title">Research for BSIT and BSCS.</h2>
        <p>This project supports Bachelor of Science in Information Technology and Bachelor of Science in Computer Science. Manage the names and operating status of these two programs.</p>
        <div class="hero-actions">
          <a class="btn hero-action hero-action-secondary" href="#program-directory">Browse catalog</a>
        </div>
        <div class="hero-note">Program deactivation is reversible and preserves existing records.</div>
      </div>

      <div class="catalog-snapshot" aria-label="Program catalog snapshot">
        <div class="snapshot-row">
          <span class="snapshot-code">01</span>
          <span class="snapshot-label">Active programs</span>
          <strong class="snapshot-value"><?php echo $active_programs; ?></strong>
        </div>
        <div class="snapshot-row">
          <span class="snapshot-code">02</span>
          <span class="snapshot-label">Inactive programs</span>
          <strong class="snapshot-value"><?php echo $inactive_programs; ?></strong>
        </div>
        <div class="snapshot-row">
          <span class="snapshot-code">03</span>
          <span class="snapshot-label">Catalog total</span>
          <strong class="snapshot-value"><?php echo $total_programs; ?></strong>
        </div>
      </div>
    </section>

    <section class="program-metrics" aria-label="Program totals">
      <article class="program-metric metric-total"><div class="metric-index">Catalog</div><div class="metric-value"><?php echo $total_programs; ?></div><div class="metric-label">Total programs</div></article>
      <article class="program-metric metric-active"><div class="metric-index">Operating</div><div class="metric-value"><?php echo $active_programs; ?></div><div class="metric-label">Active programs</div></article>
      <article class="program-metric metric-inactive"><div class="metric-index">Retained</div><div class="metric-value"><?php echo $inactive_programs; ?></div><div class="metric-label">Inactive programs</div></article>
    </section>

    <section class="program-directory" id="program-directory" aria-labelledby="directory-title">
      <div class="directory-header">
        <div>
          <div class="directory-eyebrow">Academic directory</div>
          <h3 class="directory-title" id="directory-title">Programs</h3>
          <p class="directory-meta"><?php echo $visible_programs; ?> program<?php echo $visible_programs === 1 ? '' : 's'; ?> shown<?php echo ($search !== '' || $status_filter !== '') ? ' for the current filters' : ''; ?>.</p>
        </div>
      </div>

      <form method="GET" action="admin-programs.php" class="filter-bar">
        <div class="search-field">
          <label for="programSearch" class="sr-only">Search programs</label>
          <input id="programSearch" type="search" name="search" class="search-input" placeholder="Search code or program name" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label for="programStatus" class="sr-only">Filter by status</label>
        <select id="programStatus" name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
          <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
        <?php if ($search !== '' || $status_filter !== ''): ?>
          <a href="admin-programs.php" class="btn btn-secondary">Clear filters</a>
        <?php endif; ?>
      </form>

      <div class="table-wrap">
        <table class="program-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Program Name</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($programs->num_rows > 0): ?>
              <?php while ($p = $programs->fetch_assoc()): ?>
                <tr>
                  <td data-label="Code"><span class="program-code"><?php echo htmlspecialchars($p['program_code'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="Program">
                    <div class="program-name"><?php echo htmlspecialchars($p['program_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="program-id">Catalog #<?php echo (int) $p['program_id']; ?></div>
                  </td>
                  <td data-label="Status">
                    <span class="badge <?php echo (int) $p['status'] === 1 ? 'badge-active' : 'badge-inactive'; ?>">
                      <?php echo (int) $p['status'] === 1 ? 'Active' : 'Inactive'; ?>
                    </span>
                  </td>
                  <td data-label="Actions">
                    <div class="actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                    <?php if ((int) $p['status'] === 1): ?>
                      <button type="button" class="btn btn-sm btn-danger" onclick='confirmToggleStatus(<?php echo (int) $p['program_id']; ?>, 1, <?php echo json_encode($p['program_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Deactivate</button>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-success" onclick='confirmToggleStatus(<?php echo (int) $p['program_id']; ?>, 0, <?php echo json_encode($p['program_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Activate</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="empty-state">
                  <strong>No matching programs</strong>
                  <span>Only BSIT and BSCS are supported. Clear the filters to check the existing catalog.</span>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
</div>

<!-- EDIT PROGRAM MODAL -->
<div class="modal-overlay" id="editModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle" tabindex="-1">
    <div class="modal-header">
      <div class="modal-title" id="editModalTitle">Edit program</div>
      <div class="modal-subtitle">Update the name of this supported program. Its code is fixed.</div>
    </div>

    <form method="POST" action="admin-programs.php" id="editForm">
      <input type="hidden" name="action" value="edit_program">
      <input type="hidden" name="program_id" id="editProgramId">
      <?php echo csrfField(); ?>

      <div class="form-group">
        <label class="form-label" for="editCode">Program Code</label>
        <input type="text" id="editCode" class="form-control" readonly>
      </div>

      <div class="form-group">
        <label class="form-label" for="editName">Program Name *</label>
        <input type="text" name="program_name" id="editName" class="form-control" maxlength="150" required>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- TOGGLE STATUS FORM (hidden) -->
<form method="POST" action="admin-programs.php" id="toggleStatusForm" style="display: none;">
  <input type="hidden" name="action" value="toggle_status">
  <input type="hidden" name="program_id" id="toggleProgramId">
  <?php echo csrfField(); ?>
</form>

<script>
let lastFocusedElement = null;

function showModal(modalId) {
  const overlay = document.getElementById(modalId);
  if (!overlay) return;
  lastFocusedElement = document.activeElement;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  const dialog = overlay.querySelector('.modal');
  if (dialog) window.setTimeout(() => dialog.focus(), 0);
}

function hideModal(modalId) {
  const overlay = document.getElementById(modalId);
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  if (lastFocusedElement) lastFocusedElement.focus();
}

function openEditModal(data) {
  showModal('editModal');
  document.getElementById('editProgramId').value = data.program_id;
  document.getElementById('editCode').value = data.program_code;
  document.getElementById('editName').value = data.program_name;
}

function closeEditModal() {
  hideModal('editModal');
}

function confirmToggleStatus(programId, currentStatus, code) {
  const action = currentStatus === 1 ? 'deactivate' : 'activate';
  const message = `Are you sure you want to ${action} program ${code}?`;
  if (confirm(message)) {
    document.getElementById('toggleProgramId').value = programId;
    document.getElementById('toggleStatusForm').submit();
  }
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', function(e) {
    if (e.target === this) {
      hideModal(this.id);
    }
  });
});

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const openModal = document.querySelector('.modal-overlay.active');
  if (openModal) hideModal(openModal.id);
});
</script>

<?php
renderAdminShellClose();
