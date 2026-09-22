<?php
/**
 * Admin — System Settings & Status
 *
 * Read-only environment, migration, and storage diagnostics.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

function aset_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function aset_table_exists(mysqli $conn, string $table): bool {
    $allowed_tables = [
        'users',
        'research_projects',
        'project_reviews',
        'comments',
        'project_advisers_history',
    ];
    if (!in_array($table, $allowed_tables, true)) {
        return false;
    }

    $escaped_table = $conn->real_escape_string($table);
    $stmt = $conn->prepare("SHOW TABLES LIKE '$escaped_table'");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) { $stmt->close(); }
        return false;
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function aset_column_info(mysqli $conn, string $table, string $column): ?array {
    $allowed_columns = [
        'users'            => ['office', 'email_verified'],
        'research_projects' => ['status'],
        'project_reviews'   => ['capability_score'],
        'comments'          => ['project_id'],
    ];
    if (!isset($allowed_columns[$table]) || !in_array($column, $allowed_columns[$table], true)) {
        return null;
    }

    $escaped_table = str_replace('`', '``', $table);
    $escaped_column = $conn->real_escape_string($column);
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$escaped_table` LIKE '$escaped_column'");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) { $stmt->close(); }
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function aset_format_bytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

// Environment diagnostics.
$mysql_version = 'Unavailable';
$version_stmt = $conn->prepare('SELECT VERSION() AS server_version');
if ($version_stmt && $version_stmt->execute()) {
    $version_row = $version_stmt->get_result()->fetch_assoc();
    $mysql_version = (string) ($version_row['server_version'] ?? 'Unavailable');
    $version_stmt->close();
} elseif ($version_stmt) {
    $version_stmt->close();
}

$upload_max_filesize = ini_get('upload_max_filesize');
$post_max_size = ini_get('post_max_size');
$file_uploads_enabled = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);

// Structural migration probes. Migration 005 does not exist in this project;
// migration 010 is data-only and is noted separately below.
$users_office = aset_column_info($conn, 'users', 'office');
$users_email_verified = aset_column_info($conn, 'users', 'email_verified');
$project_status = aset_column_info($conn, 'research_projects', 'status');
$project_reviews_exists = aset_table_exists($conn, 'project_reviews');
$capability_score = $project_reviews_exists
    ? aset_column_info($conn, 'project_reviews', 'capability_score')
    : null;
$comments_project_id = aset_column_info($conn, 'comments', 'project_id');
$adviser_history_exists = aset_table_exists($conn, 'project_advisers_history');

$migration_rows = [
    [
        'file' => '002_enhance_users_for_registration.sql',
        'adds' => 'Office and role-specific registration fields on users',
        'applied' => $users_office !== null,
    ],
    [
        'file' => '003_add_email_verification.sql',
        'adds' => 'Email verification fields on users',
        'applied' => $users_email_verified !== null,
    ],
    [
        'file' => '004_extend_status_enums.sql',
        'adds' => 'Full project lifecycle status values, including under_erec_review',
        'applied' => $project_status !== null
            && str_contains((string) ($project_status['Type'] ?? ''), "'under_erec_review'"),
    ],
    [
        'file' => '006_create_project_reviews.sql',
        'adds' => 'project_reviews table for CREC/EREC assignments and scoring',
        'applied' => $project_reviews_exists,
    ],
    [
        'file' => '007_form3_full_criteria.sql',
        'adds' => 'capability_score criterion on project_reviews',
        'applied' => $capability_score !== null,
    ],
    [
        'file' => '008_comments_project_feedback.sql',
        'adds' => 'project_id for project-level comments',
        'applied' => $comments_project_id !== null,
    ],
    [
        'file' => '009_adviser_history.sql',
        'adds' => 'project_advisers_history table',
        'applied' => $adviser_history_exists,
    ],
];

// Storage diagnostics. Only configured subfolders that currently exist are shown.
$upload_root = __DIR__ . '/../../uploads';
$upload_folders = ['proposals', 'chapters', 'defense', 'manuscripts', 'milestones', 'other'];
$storage_rows = [];
$total_files = 0;
$total_bytes = 0;

foreach ($upload_folders as $folder) {
    $folder_path = $upload_root . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($folder_path)) {
        continue;
    }

    $file_count = 0;
    $folder_bytes = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder_path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }
            $file_count++;
            try {
                $folder_bytes += (int) $file->getSize();
            } catch (Throwable $exception) {
                // Skip files whose metadata becomes unreadable during traversal.
            }
        }
    } catch (Throwable $exception) {
        // Keep the folder in the report with the readable totals collected so far.
    }

    $storage_rows[] = [
        'folder' => $folder,
        'files' => $file_count,
        'bytes' => $folder_bytes,
    ];
    $total_files += $file_count;
    $total_bytes += $folder_bytes;
}

$backup_script_exists = is_file(__DIR__ . '/../../scripts/backup-database.sh');
$applied_migration_count = count(array_filter($migration_rows, static function (array $migration): bool {
    return $migration['applied'] === true;
}));
$migration_count = count($migration_rows);
$storage_folder_count = count($storage_rows);

renderAdminShell(
    $user,
    'admin-settings',
    'System Settings & Status',
    'Read-only diagnostics for the RMS environment, database migrations, and uploaded-file storage.'
);
?>

<style>
  html{scroll-behavior:smooth}.settings-workspace{--settings-ink:#192235;--settings-gold:#d2a248;--settings-muted:#687386;--settings-line:#dfe5ed;max-width:1480px;margin:0 auto;color:var(--settings-ink)}
  .settings-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.18fr) minmax(310px,.82fr);gap:50px;min-height:344px;padding:54px 56px 68px;overflow:hidden;border-radius:24px 24px 8px 8px;background:radial-gradient(circle at 82% 15%,rgba(210,162,72,.21),transparent 29%),linear-gradient(135deg,#172033,#202e46 66%,#29364c);color:#fff;box-shadow:0 24px 58px rgba(24,34,53,.16)}.settings-hero::after{content:'';position:absolute;inset:0;z-index:-1;opacity:.15;background-image:repeating-linear-gradient(90deg,transparent 0,transparent 67px,rgba(255,255,255,.1) 68px);pointer-events:none}.settings-kicker,.health-code,.metric-index,.card-index,.read-only-badge{font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.15em;text-transform:uppercase}.settings-kicker{margin-bottom:18px;color:#e9bf6e}.settings-hero h2{max-width:780px;margin:0;font-size:clamp(40px,4.6vw,66px);line-height:.98;letter-spacing:-.055em;text-wrap:balance}.settings-hero-copy>p{max-width:640px;margin:24px 0 0;color:#bdc8d8;font-size:15px;line-height:1.75;text-wrap:pretty}.settings-signal{display:inline-flex;align-items:center;gap:9px;margin-top:25px;color:#aeb9ca;font-size:12px}.settings-signal::before{content:'';width:7px;height:7px;border-radius:50%;background:#70bd91;box-shadow:0 0 0 5px rgba(112,189,145,.11)}
  .health-readout{align-self:end;display:grid;gap:2px}.health-row{display:grid;grid-template-columns:40px 1fr auto;align-items:center;gap:12px;padding:16px 18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.06)}.health-row:first-child{border-radius:14px 14px 5px 5px}.health-row:last-child{border-radius:5px 5px 14px 14px}.health-code{color:#e9bf6e}.health-label{color:#d4dce8;font-size:12px}.health-value{color:#fff;font-size:20px;font-weight:720;letter-spacing:-.03em;font-variant-numeric:tabular-nums;white-space:nowrap}
  .settings-metrics{position:relative;z-index:2;display:grid;grid-template-columns:1.15fr repeat(3,1fr);gap:2px;margin:-24px 22px 0}.settings-metric{position:relative;min-height:136px;padding:23px 24px 20px;overflow:hidden;border:1px solid #e2e7ee;background:#fff}.settings-metric:first-child{border-radius:16px 5px 5px 16px;background:#fcfaf5}.settings-metric:last-child{border-radius:5px 16px 16px 5px}.settings-metric::after{content:'';position:absolute;right:-20px;bottom:-32px;width:76px;height:76px;border:17px solid var(--metric-accent,#64748b);border-radius:50%;opacity:.075}.metric-runtime{--metric-accent:#987027}.metric-database{--metric-accent:#315b8c}.metric-migrations{--metric-accent:#705487}.metric-storage{--metric-accent:#347451}.metric-index{margin-bottom:22px;color:var(--metric-accent)}.metric-value{font-size:29px;font-weight:720;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.metric-label{margin-top:8px;color:var(--settings-muted);font-size:13px;font-weight:620}
  .settings-grid{display:grid;grid-template-columns:minmax(0,.78fr) minmax(0,1.22fr);gap:24px;margin-top:38px;align-items:start}.settings-card,.managed-section{overflow:hidden;border:1px solid var(--settings-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(31,42,63,.06)}.migration-card{grid-column:2;grid-row:1 / span 2}.managed-section{grid-column:1/-1}.settings-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:27px 28px 22px;border-bottom:1px solid #edf0f4}.card-index{margin-bottom:9px;color:#987027}.settings-card-header h2,.managed-section h2{margin:0;color:#1c2639;font-size:24px;line-height:1.13;letter-spacing:-.03em}.settings-card-header p,.managed-section>p{max-width:620px;margin:9px 0 0;color:var(--settings-muted);font-size:13px;line-height:1.6}.read-only-badge{padding:6px 8px;border:1px solid #e2d4b8;border-radius:5px;background:#fbf6eb;color:#866224;white-space:nowrap}
  .environment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;padding:1px;background:#edf0f4}.environment-item{min-height:112px;padding:18px 20px;background:#fff}.environment-label{margin-bottom:10px;color:#8a94a3;font:700 9px/1.3 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.1em;text-transform:uppercase}.environment-value{color:#263247;font-size:14px;font-weight:650;line-height:1.55;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}.status-dot{display:inline-block;width:7px;height:7px;margin-right:8px;border-radius:50%;vertical-align:1px}.status-dot.on{background:#4b9b70;box-shadow:0 0 0 4px rgba(75,155,112,.1)}.status-dot.off{background:#b95b5b;box-shadow:0 0 0 4px rgba(185,91,91,.1)}
  .table-wrap{overflow-x:auto}.settings-table{width:100%;border-collapse:collapse}.settings-table thead{background:#fafbfc}.settings-table th{padding:12px 17px;color:#7a8494;font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.1em;text-align:left;text-transform:uppercase;white-space:nowrap}.settings-table th:first-child,.settings-table td:first-child{padding-left:28px}.settings-table th:last-child,.settings-table td:last-child{padding-right:28px}.settings-table td{padding:15px 17px;border-top:1px solid #edf0f4;color:#465368;font-size:12px;line-height:1.5;vertical-align:middle}.settings-table tbody tr{transition:background .2s ease}.settings-table tbody tr:hover{background:#fbfaf7}.file-name{color:#263247;font:650 10px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.migration-status{display:inline-flex;padding:5px 8px;border:1px solid;border-radius:5px;font-size:9px;font-weight:750;letter-spacing:.055em;white-space:nowrap;text-transform:uppercase}.migration-status.applied{border-color:#cfe4d7;background:#f0f7f2;color:#2f704d}.migration-status.missing{border-color:#e8caca;background:#fff4f4;color:#9b4141}.table-total td{background:#f7f8fa;color:#202a3d;font-weight:720}.settings-note{margin:0;padding:15px 28px;border-top:1px solid #e9edf2;background:#fbf7ed;color:#795b27;font-size:11px;line-height:1.55}.settings-note strong{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
  .managed-section{padding:27px 28px 29px}.managed-list{display:grid;grid-template-columns:repeat(3,1fr);gap:2px;margin:22px 0 0;padding:0;list-style:none}.managed-list li{min-height:112px;padding:18px;background:#f5f7fa;color:#556176;font-size:12px;line-height:1.55}.managed-list li:first-child{border-radius:10px 4px 4px 10px}.managed-list li:last-child{border-radius:4px 10px 10px 4px}.managed-list strong{display:block;margin-bottom:6px;color:#263247;font-size:13px}.managed-list a{color:#8a6528;font-weight:720;text-decoration:none}.managed-list a:hover{text-decoration:underline}.managed-list a:focus-visible{outline:3px solid rgba(210,162,72,.25);outline-offset:3px;border-radius:2px}.managed-list code{color:#263247;font-weight:700}
  @media(max-width:1100px){.settings-hero{grid-template-columns:1fr;gap:30px}.health-readout{grid-template-columns:repeat(3,1fr)}.health-row{grid-template-columns:32px 1fr}.health-value{grid-column:2}.settings-grid{grid-template-columns:1fr}.migration-card,.managed-section{grid-column:auto;grid-row:auto}}
  @media(max-width:760px){.settings-hero{min-height:0;padding:31px 24px 51px;border-radius:18px 18px 7px 7px}.settings-hero h2{font-size:40px}.health-readout{grid-template-columns:1fr}.health-row{grid-template-columns:34px 1fr auto}.health-value{grid-column:auto}.settings-metrics{grid-template-columns:repeat(4,minmax(150px,1fr));margin:-18px 12px 0;overflow-x:auto}.settings-metric{min-width:150px}.settings-grid{margin-top:28px}.settings-card-header,.managed-section{padding:23px 20px 20px}.environment-grid{grid-template-columns:1fr}.managed-list{grid-template-columns:1fr}.managed-list li:first-child,.managed-list li:last-child{border-radius:8px}.table-wrap{overflow:visible}.settings-table thead{display:none}.settings-table tbody{display:grid;gap:12px;padding:16px;background:#f6f8fa}.settings-table tbody tr{display:block;overflow:hidden;border:1px solid #e0e5eb;border-radius:12px;background:#fff}.settings-table tbody td{display:grid;grid-template-columns:94px minmax(0,1fr);width:100%;padding:11px 14px;border-top:1px solid #edf0f4;text-align:left;overflow-wrap:anywhere}.settings-table tbody td:first-child{padding:15px 14px;border-top:0}.settings-table tbody td:last-child{padding:13px 14px}.settings-table tbody td::before{content:attr(data-label);margin:2px 11px 0 0;color:#8a94a3;font:700 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.09em;text-transform:uppercase}.settings-table td[colspan]::before{display:none}.settings-note{padding:15px 20px}}
  @media(prefers-reduced-motion:reduce){.settings-table tbody tr{transition:none}}
</style>

<div class="settings-workspace">
  <section class="settings-hero" aria-labelledby="settings-hero-title">
    <div class="settings-hero-copy">
      <div class="settings-kicker">System observatory &middot; Read-only diagnostics</div>
      <h2 id="settings-hero-title">Know the system before it needs attention.</h2>
      <p>Inspect the RMS runtime, database structure, and uploaded-file footprint from one operational view. Configuration values remain protected and nothing on this page can alter the system.</p>
      <div class="settings-signal">Live diagnostics connected</div>
    </div>
    <div class="health-readout" aria-label="System health overview">
      <div class="health-row"><span class="health-code">01</span><span class="health-label">Migration coverage</span><strong class="health-value"><?php echo number_format($applied_migration_count); ?>/<?php echo number_format($migration_count); ?></strong></div>
      <div class="health-row"><span class="health-code">02</span><span class="health-label">Readable uploads</span><strong class="health-value"><?php echo number_format($total_files); ?></strong></div>
      <div class="health-row"><span class="health-code">03</span><span class="health-label">File uploads</span><strong class="health-value"><?php echo $file_uploads_enabled ? 'Enabled' : 'Disabled'; ?></strong></div>
    </div>
  </section>

  <section class="settings-metrics" aria-label="Environment metrics">
    <article class="settings-metric metric-runtime"><div class="metric-index">Runtime</div><div class="metric-value">PHP <?php echo aset_escape(PHP_VERSION); ?></div><div class="metric-label">Application engine</div></article>
    <article class="settings-metric metric-database"><div class="metric-index">Database</div><div class="metric-value"><?php echo aset_escape($mysql_version); ?></div><div class="metric-label">MySQL server</div></article>
    <article class="settings-metric metric-migrations"><div class="metric-index">Schema</div><div class="metric-value"><?php echo number_format($applied_migration_count); ?>/<?php echo number_format($migration_count); ?></div><div class="metric-label">Migrations applied</div></article>
    <article class="settings-metric metric-storage"><div class="metric-index">Uploads</div><div class="metric-value"><?php echo aset_escape(aset_format_bytes($total_bytes)); ?></div><div class="metric-label"><?php echo number_format($storage_folder_count); ?> folders scanned</div></article>
  </section>

<div class="settings-grid">
  <section class="settings-card environment-card">
    <div class="settings-card-header">
      <div>
        <div class="card-index">01 &middot; Runtime</div>
        <h2>Environment</h2>
        <p>Runtime values reported by PHP and the connected database server.</p>
      </div>
      <span class="read-only-badge">READ ONLY</span>
    </div>
    <div class="environment-grid">
      <div class="environment-item">
        <div class="environment-label">PHP version</div>
        <div class="environment-value"><?php echo aset_escape(PHP_VERSION); ?></div>
      </div>
      <div class="environment-item">
        <div class="environment-label">MySQL server version</div>
        <div class="environment-value"><?php echo aset_escape($mysql_version); ?></div>
      </div>
      <div class="environment-item">
        <div class="environment-label">Maximum upload size</div>
        <div class="environment-value">
          upload_max_filesize: <?php echo aset_escape($upload_max_filesize !== false ? $upload_max_filesize : 'Unavailable'); ?><br>
          post_max_size: <?php echo aset_escape($post_max_size !== false ? $post_max_size : 'Unavailable'); ?>
        </div>
      </div>
      <div class="environment-item">
        <div class="environment-label">File uploads</div>
        <div class="environment-value">
          <span class="status-dot <?php echo $file_uploads_enabled ? 'on' : 'off'; ?>"></span><?php echo $file_uploads_enabled ? 'Enabled' : 'Disabled'; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="settings-card migration-card">
    <div class="settings-card-header">
      <div>
        <div class="card-index">02 &middot; Schema</div>
        <h2>Database migrations</h2>
        <p>Structural artifacts detected in the currently connected RMS database.</p>
      </div>
      <span class="read-only-badge">LIVE PROBES</span>
    </div>
    <div class="table-wrap">
      <table class="settings-table">
        <thead><tr><th>Migration file</th><th>What it adds</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($migration_rows as $migration): ?>
          <tr>
            <td class="file-name" data-label="Migration"><?php echo aset_escape($migration['file']); ?></td>
            <td data-label="Artifact"><?php echo aset_escape($migration['adds']); ?></td>
            <td data-label="Status">
              <span class="migration-status <?php echo $migration['applied'] ? 'applied' : 'missing'; ?>">
                <?php echo $migration['applied'] ? 'Applied' : 'NOT APPLIED'; ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="settings-note"><strong>010_demo_accounts_resync.sql:</strong> data-only migration; run and verify manually when demo-account synchronization is needed.</p>
  </section>

  <section class="settings-card storage-card">
    <div class="settings-card-header">
      <div>
        <div class="card-index">03 &middot; Filesystem</div>
        <h2>Storage</h2>
        <p>Readable files currently stored in each configured uploads subfolder.</p>
      </div>
      <span class="read-only-badge">FILESYSTEM</span>
    </div>
    <div class="table-wrap">
      <table class="settings-table">
        <thead><tr><th>Upload folder</th><th>File count</th><th>Total size</th></tr></thead>
        <tbody>
        <?php if (empty($storage_rows)): ?>
          <tr><td colspan="3">No configured upload subfolders are currently available.</td></tr>
        <?php else: ?>
          <?php foreach ($storage_rows as $storage): ?>
            <tr>
              <td class="file-name" data-label="Folder">uploads/<?php echo aset_escape($storage['folder']); ?>/</td>
              <td data-label="Files"><?php echo (int) $storage['files']; ?></td>
              <td data-label="Size"><?php echo aset_escape(aset_format_bytes((int) $storage['bytes'])); ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-total">
            <td data-label="Folder">Total</td>
            <td data-label="Files"><?php echo (int) $total_files; ?></td>
            <td data-label="Size"><?php echo aset_escape(aset_format_bytes($total_bytes)); ?></td>
          </tr>
        <?php endif; ?>
          <tr>
            <td class="file-name" data-label="Script">scripts/backup-database.sh</td>
            <td colspan="2" data-label="Status">
              <span class="migration-status <?php echo $backup_script_exists ? 'applied' : 'missing'; ?>">
                <?php echo $backup_script_exists ? 'Available' : 'NOT FOUND'; ?>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="managed-section">
    <div class="card-index">04 &middot; Ownership</div>
    <h2>What settings are managed where</h2>
    <p>This diagnostics page intentionally does not change system configuration.</p>
    <ul class="managed-list">
      <li><strong>Users:</strong> manage accounts, roles, and account status in <a href="<?php echo aset_escape(SITE_URL . 'pages/admin/admin-users.php'); ?>">User Management</a>.</li>
      <li><strong>Archive:</strong> manage publication, colloquium, and archive state in <a href="<?php echo aset_escape(SITE_URL . 'pages/admin/admin-archive.php'); ?>">Archive Management</a>.</li>
      <li><strong>Site credentials:</strong> managed in <code>.env</code>; credential values are never displayed here.</li>
    </ul>
  </section>
</div>
</div>

<?php renderAdminShellClose(); ?>
