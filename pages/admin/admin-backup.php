<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();
$success = '';
$error = '';

$backup_dir = __DIR__ . '/../../backups/';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*.sql');
    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'date' => filemtime($file)
        ];
    }
    usort($backups, function ($a, $b) {
        return $b['date'] - $a['date'];
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create_backup') {
            $timestamp = date('Y-m-d_H-i-s');
            $backup_file = $backup_dir . "rms_backup_{$timestamp}.sql";
            $temporary_file = $backup_file . '.tmp';
            $error_file = $backup_dir . "rms_backup_{$timestamp}.error.log";
            $db_host = DB_HOST;
            $db_user = DB_USER;
            $db_pass = DB_PASS;
            $db_name = DB_NAME;

            $configured_dump_binary = trim((string) rms_env('MYSQLDUMP_PATH', ''));
            $dump_candidates = array_filter([
                $configured_dump_binary,
                dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            ]);
            $dump_binary = 'mysqldump';
            foreach ($dump_candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $dump_binary = $candidate;
                    break;
                }
            }

            $command = sprintf(
                '%s --host=%s --user=%s --password=%s --single-transaction --routines --triggers --events --default-character-set=utf8mb4 --result-file=%s %s 2>%s',
                escapeshellarg($dump_binary),
                escapeshellarg($db_host),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($temporary_file),
                escapeshellarg($db_name),
                escapeshellarg($error_file)
            );

            exec($command, $output, $return_var);
            clearstatcache(true, $temporary_file);
            $dump_size = is_file($temporary_file) ? filesize($temporary_file) : 0;

            if ($return_var === 0 && $dump_size !== false && $dump_size > 0 && rename($temporary_file, $backup_file)) {
                if (is_file($error_file)) {
                    unlink($error_file);
                }
                logActivity("Created database backup: rms_backup_{$timestamp}.sql", 'system');
                $success = "Database backup created successfully: rms_backup_{$timestamp}.sql";
                header('Location: admin-backup.php');
                exit;
            } else {
                if (is_file($temporary_file)) {
                    unlink($temporary_file);
                }
                $dump_error = is_file($error_file) ? trim((string) file_get_contents($error_file)) : '';
                if (is_file($error_file)) {
                    unlink($error_file);
                }
                $error = 'Failed to create a valid database backup.';
                if ($dump_error !== '') {
                    $error .= ' ' . substr(preg_replace('/\\s+/', ' ', $dump_error), 0, 300);
                } elseif ($dump_binary === 'mysqldump') {
                    $error .= ' Configure MYSQLDUMP_PATH or install mysqldump on the server.';
                }
            }
        } elseif ($action === 'delete_backup') {
            $filename = $_POST['filename'] ?? '';
            $filepath = $backup_dir . basename($filename);

            if (file_exists($filepath) && unlink($filepath)) {
                logActivity("Deleted backup file: {$filename}", 'system');
                $success = 'Backup file deleted successfully.';
                header('Location: admin-backup.php');
                exit;
            } else {
                $error = 'Failed to delete backup file.';
            }
        }
    }
}

$db_size = 0;
$size_query = $conn->query("
    SELECT SUM(data_length + index_length) as size
    FROM information_schema.TABLES
    WHERE table_schema = '" . ($_ENV['DB_NAME'] ?? 'rms_db') . "'
");
if ($size_query) {
    $db_size = $size_query->fetch_assoc()['size'] ?? 0;
}

function formatBytes($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' bytes';
}

$backup_total_size = array_sum(array_column($backups, 'size'));
$latest_backup = $backups[0] ?? null;
$backup_dir_ready = is_dir($backup_dir) && is_writable($backup_dir);

renderAdminShell(
    $user,
    'admin-backup',
    'Backup & Recovery',
    'Protect the institute research record with managed database snapshots.'
);
?>
<style>
  html{scroll-behavior:smooth}.backup-workspace{--backup-ink:#192235;--backup-gold:#d2a248;--backup-muted:#687386;--backup-line:#dfe5ed;max-width:1480px;margin:0 auto;color:var(--backup-ink)}
  .backup-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.18fr) minmax(310px,.82fr);gap:50px;min-height:344px;padding:54px 56px 68px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 81% 16%,rgba(210,162,72,.21),transparent 29%),linear-gradient(135deg,#172033,#202e46 66%,#29364c);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.backup-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.15;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.backup-kicker,.recovery-code,.metric-index,.section-kicker,.file-type{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.backup-kicker{margin-bottom:18px;color:#e9bf6e}.backup-hero h2{max-width:780px;margin:0;font-size:clamp(40px,4.6vw,66px);line-height:.98;letter-spacing:-.055em;text-wrap:balance}.backup-hero-copy>p{max-width:640px;margin:24px 0 0;color:#bdc8d8;font-size:15px;line-height:1.75;text-wrap:pretty}.backup-signal{display:inline-flex;align-items:center;gap:9px;margin-top:25px;color:#aeb9ca;font-size:12px}.backup-signal::before{content:'';width:7px;height:7px;border-radius:50%;background:#70bd91;box-shadow:0 0 0 5px rgba(112,189,145,.11)}
  .recovery-readout{align-self:end;display:grid;gap:2px}.recovery-row{display:grid;grid-template-columns:40px 1fr;gap:12px;padding:17px 18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.recovery-row:first-child{border-radius:14px 14px 5px 5px}.recovery-row:last-child{border-radius:5px 5px 14px 14px}.recovery-code{padding-top:3px;color:#e9bf6e}.recovery-label{display:block;color:#aeb9ca;font-size:11px}.recovery-value{display:block;margin-top:3px;color:#fff;font-size:20px;font-weight:720;line-height:1.2;letter-spacing:-.025em;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
  .backup-metrics{position:relative;z-index:2;display:grid;grid-template-columns:1.15fr repeat(3,1fr);gap:2px;margin:-24px 22px 0}.backup-metric{position:relative;min-height:136px;padding:23px 24px 20px;overflow:hidden;border:1px solid #e2e7ee;background:#fff}.backup-metric:first-child{border-radius:16px 5px 5px 16px;background:#fcfaf5}.backup-metric:last-child{border-radius:5px 16px 16px 5px}.backup-metric::after{content:'';position:absolute;right:-20px;bottom:-32px;width:76px;height:76px;border:17px solid var(--metric-accent,#64748b);border-radius:50%;opacity:.075}.metric-database{--metric-accent:#987027}.metric-count{--metric-accent:#315b8c}.metric-storage{--metric-accent:#705487}.metric-directory{--metric-accent:#347451}.metric-index{margin-bottom:22px;color:var(--metric-accent)}.metric-value{font-size:30px;font-weight:720;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums}.metric-label{margin-top:8px;color:var(--backup-muted);font-size:13px;font-weight:620}
  .backup-alert{display:flex;align-items:flex-start;gap:12px;margin:28px 0 0;padding:14px 17px;border:1px solid;border-radius:10px;font-size:13px;line-height:1.55}.backup-alert-mark{display:grid;place-items:center;flex:0 0 24px;height:24px;border-radius:7px;font-weight:800}.backup-alert-success{border-color:#cde7d8;background:#f0f8f3;color:#276446}.backup-alert-success .backup-alert-mark{background:#dcefe3}.backup-alert-error{border-color:#edcaca;background:#fff4f4;color:#9a3535}.backup-alert-error .backup-alert-mark{background:#f7dddd}
  .backup-grid{display:grid;grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr);gap:24px;margin-top:38px;align-items:start}.backup-panel{overflow:hidden;border:1px solid var(--backup-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.06)}.create-panel{position:sticky;top:24px}.panel-head{padding:27px 28px 22px}.section-kicker{margin-bottom:9px;color:#987027}.panel-title{margin:0;color:#1c2639;font-size:24px;line-height:1.13;letter-spacing:-.03em}.panel-copy{max-width:630px;margin:9px 0 0;color:var(--backup-muted);font-size:13px;line-height:1.6}.create-body{padding:0 28px 29px}.backup-notes{display:grid;gap:1px;margin:0 0 24px;padding:0;list-style:none}.backup-notes li{display:grid;grid-template-columns:28px 1fr;gap:11px;align-items:start;padding:13px 14px;background:#f5f7fa;color:#4c596c;font-size:12px;line-height:1.5}.backup-notes li:first-child{border-radius:10px 10px 4px 4px}.backup-notes li:last-child{border-radius:4px 4px 10px 10px}.backup-notes span{color:#987027;font:700 10px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace}.backup-path{display:block;margin-top:3px;color:#26344a;font:650 11px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.create-form{display:grid}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 15px;border:1px solid transparent;border-radius:8px;background:none;color:inherit;font-family:inherit;font-size:12px;font-weight:700;line-height:1.2;text-decoration:none;cursor:pointer;transition:transform .2s ease,background .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease}.btn:hover{transform:translateY(-1px)}.btn:active{transform:translateY(0) scale(.98)}.btn:focus-visible{outline:3px solid rgba(210,162,72,.28);outline-offset:2px}.btn-primary{border-color:var(--backup-gold);background:var(--backup-gold);color:#182033}.btn-primary:hover{border-color:#dfb45f;background:#dfb45f;box-shadow:0 9px 22px rgba(159,117,42,.18)}.btn-secondary{border-color:#d8dfe8;background:#fff;color:#344054}.btn-secondary:hover{border-color:#b3bdca;background:#f7f8fa}.btn-danger{border-color:#e6c4c4;background:#fff7f7;color:#9a3535}.btn-danger:hover{border-color:#d8a8a8;background:#fcecec}.btn-sm{min-height:35px;padding:8px 11px;font-size:10px}
  .inventory-head{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:27px 29px 22px}.inventory-count{color:#8a95a5;font:650 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.table-wrap{overflow-x:auto;border-top:1px solid #edf0f4}.backup-table{width:100%;min-width:720px;border-collapse:collapse}.backup-table thead{background:#fafbfc}.backup-table th{padding:12px 15px;border:0;color:#7a8494;font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.11em;text-align:left;text-transform:uppercase}.backup-table th:first-child,.backup-table td:first-child{padding-left:29px}.backup-table th:last-child,.backup-table td:last-child{padding-right:29px}.backup-table td{padding:17px 15px;border-top:1px solid #edf0f4;color:#475467;font-size:13px;vertical-align:middle}.backup-table tbody tr{transition:background .2s ease}.backup-table tbody tr:hover{background:#fbfaf7}.backup-file{display:block;color:#202a3d;font:650 11px/1.45 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.file-type{display:block;margin-top:4px;color:#9a752f;font-size:8px}.backup-size,.backup-date{color:#5e6a7d;font-variant-numeric:tabular-nums;white-space:nowrap}.backup-actions{display:flex;justify-content:flex-end;gap:7px}.backup-actions form{display:inline-flex}.empty-backups{padding:62px 24px;text-align:center}.empty-mark{display:grid;place-items:center;width:50px;height:50px;margin:0 auto 15px;border:1px solid #e4d5b5;border-radius:14px;background:#fbf6eb;color:#876225;font:750 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace}.empty-backups strong{display:block;color:#344054;font-size:16px}.empty-backups span{display:block;max-width:360px;margin:7px auto 0;color:#8490a1;font-size:13px;line-height:1.55}
  @media(max-width:1100px){.backup-hero{grid-template-columns:1fr;gap:30px}.recovery-readout{grid-template-columns:repeat(3,1fr)}.backup-grid{grid-template-columns:1fr}.create-panel{position:static}.backup-notes{grid-template-columns:repeat(3,1fr)}.backup-notes li{display:block}.backup-notes span{display:block;margin-bottom:5px}}
  @media(max-width:760px){.backup-hero{min-height:0;padding:31px 24px 51px;border-radius:18px 18px 7px 7px}.backup-hero h2{font-size:40px}.recovery-readout{grid-template-columns:1fr}.backup-metrics{grid-template-columns:repeat(4,minmax(150px,1fr));margin:-18px 12px 0;overflow-x:auto}.backup-metric{min-width:150px}.backup-grid{margin-top:28px}.panel-head,.inventory-head{padding:23px 20px 19px}.create-body{padding:0 20px 23px}.backup-notes{grid-template-columns:1fr}.inventory-head{align-items:flex-start;flex-direction:column}.table-wrap{overflow:visible}.backup-table{min-width:0}.backup-table thead{display:none}.backup-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.backup-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.backup-table tbody td{display:grid;grid-template-columns:84px minmax(0,1fr);width:100%;padding:11px 14px;border-top:1px solid #edf0f4;text-align:left}.backup-table tbody td:first-child{padding:15px 14px;border-top:0}.backup-table tbody td:last-child{padding:13px 14px}.backup-table tbody td::before{content:attr(data-label);margin:2px 11px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.backup-actions{justify-content:flex-start;flex-wrap:wrap}.backup-size,.backup-date{white-space:normal}}
  @media(prefers-reduced-motion:reduce){.btn,.backup-table tbody tr{transition:none}}
</style>

<div class="backup-workspace">
  <section class="backup-hero" aria-labelledby="backup-hero-title">
    <div class="backup-hero-copy">
      <div class="backup-kicker">Data stewardship &middot; Recovery readiness</div>
      <h2 id="backup-hero-title">Recovery starts before failure.</h2>
      <p>Create complete SQL snapshots of the RMS database, review the local backup inventory, and take copies off-server for dependable recovery.</p>
      <div class="backup-signal"><?php echo $backup_dir_ready ? 'Backup directory ready' : 'Backup directory needs attention'; ?></div>
    </div>
    <div class="recovery-readout" aria-label="Recovery overview">
      <div class="recovery-row"><span class="recovery-code">01</span><div><span class="recovery-label">Live database</span><strong class="recovery-value"><?php echo htmlspecialchars(formatBytes($db_size), ENT_QUOTES, 'UTF-8'); ?></strong></div></div>
      <div class="recovery-row"><span class="recovery-code">02</span><div><span class="recovery-label">Stored snapshots</span><strong class="recovery-value"><?php echo number_format(count($backups)); ?></strong></div></div>
      <div class="recovery-row"><span class="recovery-code">03</span><div><span class="recovery-label">Latest snapshot</span><strong class="recovery-value"><?php echo $latest_backup ? htmlspecialchars(date('M d, Y', $latest_backup['date']), ENT_QUOTES, 'UTF-8') : 'Not created'; ?></strong></div></div>
    </div>
  </section>

  <section class="backup-metrics" aria-label="Backup metrics">
    <article class="backup-metric metric-database"><div class="metric-index">Database</div><div class="metric-value"><?php echo htmlspecialchars(formatBytes($db_size), ENT_QUOTES, 'UTF-8'); ?></div><div class="metric-label">Current data size</div></article>
    <article class="backup-metric metric-count"><div class="metric-index">Inventory</div><div class="metric-value"><?php echo number_format(count($backups)); ?></div><div class="metric-label">SQL snapshots</div></article>
    <article class="backup-metric metric-storage"><div class="metric-index">Footprint</div><div class="metric-value"><?php echo htmlspecialchars(formatBytes($backup_total_size), ENT_QUOTES, 'UTF-8'); ?></div><div class="metric-label">Backup storage used</div></article>
    <article class="backup-metric metric-directory"><div class="metric-index">Storage</div><div class="metric-value"><?php echo $backup_dir_ready ? 'Ready' : 'Check'; ?></div><div class="metric-label">Directory status</div></article>
  </section>

  <?php if ($success): ?>
    <div class="backup-alert backup-alert-success" role="status"><span class="backup-alert-mark">OK</span><span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="backup-alert backup-alert-error" role="alert"><span class="backup-alert-mark">!</span><span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span></div>
  <?php endif; ?>

  <div class="backup-grid">
    <section class="backup-panel create-panel" aria-labelledby="create-backup-title">
      <header class="panel-head"><div class="section-kicker">New snapshot</div><h3 class="panel-title" id="create-backup-title">Create a database backup</h3><p class="panel-copy">Capture the current tables, structure, and records in one SQL file.</p></header>
      <div class="create-body">
        <ul class="backup-notes">
          <li><span>01</span><div>Saved locally in the protected backup workspace.<code class="backup-path">/backups/</code></div></li>
          <li><span>02</span><div>The snapshot includes the complete schema and all current data.</div></li>
          <li><span>03</span><div>Download a copy and store it securely outside the web server.</div></li>
        </ul>
        <form method="POST" action="admin-backup.php" class="create-form" onsubmit="return confirm('Create a new database backup? This may take a few moments.');">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="create_backup">
          <button type="submit" class="btn btn-primary">Create backup now</button>
        </form>
      </div>
    </section>

    <section class="backup-panel inventory-panel" aria-labelledby="backup-inventory-title">
      <header class="inventory-head">
        <div><div class="section-kicker">Snapshot archive</div><h3 class="panel-title" id="backup-inventory-title">Existing backups</h3><p class="panel-copy">Download retained SQL snapshots or remove files that are no longer required.</p></div>
        <span class="inventory-count"><?php echo number_format(count($backups)); ?> FILES</span>
      </header>

      <?php if (empty($backups)): ?>
        <div class="empty-backups"><div class="empty-mark">SQL</div><strong>No backups created yet</strong><span>Create the first snapshot to establish a local recovery point.</span></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="backup-table">
            <thead><tr><th>Backup file</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($backups as $backup): ?>
                <tr>
                  <td data-label="Backup file"><span class="backup-file"><?php echo htmlspecialchars($backup['filename'], ENT_QUOTES, 'UTF-8'); ?></span><span class="file-type">SQL snapshot</span></td>
                  <td data-label="Size"><span class="backup-size"><?php echo htmlspecialchars(formatBytes($backup['size']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="Created"><span class="backup-date"><?php echo htmlspecialchars(date('M d, Y h:i A', $backup['date']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td data-label="Actions">
                    <div class="backup-actions">
                      <a href="../../backups/<?php echo urlencode($backup['filename']); ?>" download class="btn btn-secondary btn-sm">Download</a>
                      <form method="POST" action="admin-backup.php" onsubmit="return confirm('Delete this backup file? This cannot be undone.');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_backup">
                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php
renderAdminShellClose();
