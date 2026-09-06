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

renderAdminShell(
    $user,
    'admin-settings',
    'System Settings & Status',
    'Read-only diagnostics for the RMS environment, database migrations, and uploaded-file storage.'
);
?>

<style>
  .settings-grid { display: grid; gap: 22px; }
  .settings-card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    overflow: hidden;
  }
  .settings-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 21px 24px;
    border-bottom: 1px solid #E5E7EB;
  }
  .settings-card-header h2 { margin: 0; color: #111827; font-size: 18px; }
  .settings-card-header p { margin: 5px 0 0; color: #64748B; font-size: 13px; }
  .read-only-badge {
    padding: 5px 10px;
    border-radius: 999px;
    background: #EEF2FF;
    color: #4F46E5;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
  }
  .environment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 30px;
    padding: 5px 24px 18px;
  }
  .environment-item { padding: 16px 0; border-bottom: 1px solid #F1F5F9; }
  .environment-label {
    margin-bottom: 6px;
    color: #94A3B8;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
  }
  .environment-value { color: #1E293B; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
  .status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    margin-right: 7px;
    border-radius: 50%;
    vertical-align: 1px;
  }
  .status-dot.on { background: #16A34A; }
  .status-dot.off { background: #DC2626; }
  .table-wrap { overflow-x: auto; }
  .settings-table { width: 100%; border-collapse: collapse; }
  .settings-table th {
    padding: 12px 18px;
    background: #F8FAFC;
    color: #64748B;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-size: 11px;
    white-space: nowrap;
  }
  .settings-table td {
    padding: 15px 18px;
    border-top: 1px solid #F1F5F9;
    color: #334155;
    font-size: 13px;
    vertical-align: middle;
  }
  .settings-table tbody tr:first-child td { border-top: 0; }
  .file-name { color: #111827; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; }
  .migration-status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
  }
  .migration-status.applied { background: #DCFCE7; color: #15803D; }
  .migration-status.missing { background: #FEE2E2; color: #B91C1C; }
  .table-total td { background: #F8FAFC; color: #111827; font-weight: 700; }
  .settings-note {
    margin: 0;
    padding: 14px 18px;
    border-top: 1px solid #E5E7EB;
    background: #FFFBEB;
    color: #92400E;
    font-size: 12px;
  }
  .managed-section { padding: 4px 2px 0; }
  .managed-section h2 { margin: 0; color: #111827; font-size: 18px; }
  .managed-section > p { margin: 5px 0 8px; color: #64748B; font-size: 13px; }
  .managed-list { margin: 0; padding: 8px 24px 20px; list-style: none; }
  .managed-list li {
    display: flex;
    align-items: baseline;
    gap: 8px;
    padding: 12px 0;
    border-bottom: 1px solid #F1F5F9;
    color: #475569;
    font-size: 13px;
  }
  .managed-list li:last-child { border-bottom: 0; }
  .managed-list strong { color: #111827; }
  .managed-list a { color: #6D28D9; font-weight: 650; text-decoration: none; }
  .managed-list a:hover { text-decoration: underline; }
  @media (max-width: 720px) {
    .environment-grid { grid-template-columns: 1fr; }
  }
</style>

<div class="settings-grid">
  <section class="settings-card">
    <div class="settings-card-header">
      <div>
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

  <section class="settings-card">
    <div class="settings-card-header">
      <div>
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
            <td class="file-name"><?php echo aset_escape($migration['file']); ?></td>
            <td><?php echo aset_escape($migration['adds']); ?></td>
            <td>
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

  <section class="settings-card">
    <div class="settings-card-header">
      <div>
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
              <td class="file-name">uploads/<?php echo aset_escape($storage['folder']); ?>/</td>
              <td><?php echo (int) $storage['files']; ?></td>
              <td><?php echo aset_escape(aset_format_bytes((int) $storage['bytes'])); ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-total">
            <td>Total</td>
            <td><?php echo (int) $total_files; ?></td>
            <td><?php echo aset_escape(aset_format_bytes($total_bytes)); ?></td>
          </tr>
        <?php endif; ?>
          <tr>
            <td class="file-name">scripts/backup-database.sh</td>
            <td colspan="2">
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
    <h2>What settings are managed where</h2>
    <p>This diagnostics page intentionally does not change system configuration.</p>
    <ul class="managed-list">
      <li><strong>Users:</strong> manage accounts, roles, and account status in <a href="<?php echo aset_escape(SITE_URL . 'pages/admin/admin-users.php'); ?>">User Management</a>.</li>
      <li><strong>Archive:</strong> manage publication, colloquium, and archive state in <a href="<?php echo aset_escape(SITE_URL . 'pages/admin/admin-archive.php'); ?>">Archive Management</a>.</li>
      <li><strong>Site credentials:</strong> managed in <code>.env</code>; credential values are never displayed here.</li>
    </ul>
  </section>
</div>

<?php renderAdminShellClose(); ?>
