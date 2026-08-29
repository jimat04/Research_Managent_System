<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

$user = getCurrentUser();

$success = '';
$error = '';

// Get backup directory info
$backup_dir = __DIR__ . '/../../backups/';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Get list of existing backups
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
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Handle backup actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create_backup') {
            $timestamp = date('Y-m-d_H-i-s');
            $backup_file = $backup_dir . "rms_backup_{$timestamp}.sql";

            $db_host = $_ENV['DB_HOST'] ?? 'localhost';
            $db_user = $_ENV['DB_USER'] ?? 'root';
            $db_pass = $_ENV['DB_PASS'] ?? '';
            $db_name = $_ENV['DB_NAME'] ?? 'rms_db';

            // Use mysqldump command
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1',
                escapeshellarg($db_host),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($db_name),
                escapeshellarg($backup_file)
            );

            exec($command, $output, $return_var);

            if ($return_var === 0 && file_exists($backup_file)) {
                logActivity("Created database backup: rms_backup_{$timestamp}.sql", 'system');
                $success = "Database backup created successfully: rms_backup_{$timestamp}.sql";
                header('Location: admin-backup.php');
                exit;
            } else {
                $error = 'Failed to create backup. Ensure mysqldump is available in your PATH.';
            }
        } elseif ($action === 'delete_backup') {
            $filename = $_POST['filename'] ?? '';
            $filepath = $backup_dir . basename($filename);

            if (file_exists($filepath) && unlink($filepath)) {
                logActivity("Deleted backup file: {$filename}", 'system');
                $success = "Backup file deleted successfully.";
                header('Location: admin-backup.php');
                exit;
            } else {
                $error = 'Failed to delete backup file.';
            }
        }
    }
}

// Get database size
$db_size = 0;
$size_query = $conn->query("
    SELECT SUM(data_length + index_length) as size
    FROM information_schema.TABLES
    WHERE table_schema = '" . ($_ENV['DB_NAME'] ?? 'rms_db') . "'
");
if ($size_query) {
    $db_size = $size_query->fetch_assoc()['size'] ?? 0;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backup — Admin — RMS</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    * { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --charcoal: #111827;
      --slate: #1F2937;
      --bg-surface: #F8FAFC;
      --bg-card: #FFFFFF;
      --border: #E5E7EB;
      --gold: #C8A44D;
      --text-primary: #111827;
      --text-secondary: #64748B;
      --text-muted: #94A3B8;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-surface);
      color: var(--text-primary);
      line-height: 1.6;
    }

    .dashboard {
      display: flex;
      min-height: 100vh;
    }

    /* SIDEBAR */
    .sidebar {
      width: 260px;
      background: var(--charcoal);
      color: white;
      padding: 32px 0;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
    }

    .sidebar-header {
      padding: 0 24px 32px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      margin-bottom: 24px;
    }

    .sidebar-brand {
      font-size: 20px;
      font-weight: 700;
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .sidebar-role {
      font-size: 13px;
      color: var(--text-muted);
      font-weight: 500;
    }

    .sidebar-nav {
      flex: 1;
      padding: 0 16px;
    }

    .nav-group-title {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin: 24px 8px 8px;
      text-transform: uppercase;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      margin: 2px 0;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s;
      font-size: 14px;
      color: rgba(255,255,255,0.7);
    }

    .nav-item:hover {
      background: rgba(255,255,255,0.08);
      color: white;
    }

    .nav-item.active {
      background: var(--gold);
      color: white;
    }

    .sidebar-footer {
      padding: 24px;
      border-top: 1px solid rgba(255,255,255,0.1);
    }

    .user-card {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: var(--gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }

    .user-name {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 2px;
    }

    .user-role {
      font-size: 12px;
      color: var(--text-muted);
    }

    /* MAIN CONTENT */
    .main-content {
      flex: 1;
      margin-left: 260px;
      padding: 48px;
    }

    .page-header {
      margin-bottom: 48px;
    }

    .page-header h1 {
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--charcoal);
    }

    .page-header p {
      font-size: 16px;
      color: var(--text-secondary);
    }

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
      color: #15803d;
      border: 1px solid #BBF7D0;
    }

    .alert-error {
      background: #FEE2E2;
      color: #DC2626;
      border: 1px solid #FECACA;
    }

    /* STATS */
    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 32px;
      display: inline-block;
    }

    .stat-number {
      font-size: 32px;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 8px;
    }

    .stat-label {
      font-size: 14px;
      color: var(--text-secondary);
      font-weight: 500;
    }

    /* CARD */
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
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
      color: var(--charcoal);
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
      background: var(--gold);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(200,164,77,0.3);
    }

    .btn-secondary {
      background: var(--bg-surface);
      color: var(--text-primary);
      border: 1px solid var(--border);
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

    /* TABLE */
    .table-wrap {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    thead {
      background: var(--bg-surface);
    }

    th {
      text-align: left;
      padding: 12px 16px;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    td {
      padding: 16px;
      font-size: 14px;
      border-top: 1px solid var(--border);
    }

    tr:hover {
      background: var(--bg-surface);
    }

    .info-box {
      background: #EFF6FF;
      border-left: 4px solid #3B82F6;
      padding: 16px;
      border-radius: 8px;
      margin-bottom: 24px;
      font-size: 14px;
    }

    /* RESPONSIVE */
    @media (max-width: 768px) {
      .sidebar {
        display: none;
      }

      .main-content {
        margin-left: 0;
        padding: 24px;
      }
    }
  </style>
</head>
<body>

<div class="dashboard">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand">EARIST RMS</div>
      <div class="sidebar-role">Administrator</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">Overview</div>
      <div class="nav-item" onclick="location.href='admin-dashboard.php'">
        <span>📊</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-group-title">Management</div>
      <div class="nav-item" onclick="location.href='admin-users.php'">
        <span>👥</span>
        <span>User Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-research.php'">
        <span>📁</span>
        <span>Research Management</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-archive.php'">
        <span>🗂️</span>
        <span>Archive</span>
      </div>

      <div class="nav-group-title">Analytics</div>
      <div class="nav-item" onclick="location.href='admin-reports.php'">
        <span>📈</span>
        <span>Reports & Analytics</span>
      </div>

      <div class="nav-group-title">Communication</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'">
        <span>💬</span>
        <span>Messages</span>
      </div>
      <div class="nav-item" onclick="location.href='admin-contact.php'">
        <span>📨</span>
        <span>Contact Messages</span>
      </div>

      <div class="nav-group-title">System</div>
      <div class="nav-item" onclick="location.href='admin-logs.php'">
        <span>⚙️</span>
        <span>System Logs</span>
      </div>
      <div class="nav-item active" onclick="location.href='admin-backup.php'">
        <span>💾</span>
        <span>Backup</span>
      </div>
      <div class="nav-item" onclick="location.href='../shared/notifications.php'">
        <span>🔔</span>
        <span>Notifications</span>
      </div>

      <div class="nav-group-title">Account</div>
      <div class="nav-item" onclick="location.href='../shared/profile.php'">
        <span>👤</span>
        <span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #EF4444;">
        <span>🚪</span>
        <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-role">Administrator</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="page-header">
      <h1>Database Backup</h1>
      <p>Create and manage database backups for system recovery.</p>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <span>✓</span>
        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <span>✕</span>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    <?php endif; ?>

    <!-- STAT -->
    <div class="stat-card">
      <div class="stat-number"><?php echo formatBytes($db_size); ?></div>
      <div class="stat-label">Current Database Size</div>
    </div>

    <!-- CREATE BACKUP CARD -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Create New Backup</div>
      </div>

      <div class="info-box">
        <strong>📌 Backup Information</strong>
        <p style="margin-top: 8px;">Database backups are saved to the <code>/backups/</code> directory. Backups include all tables, data, and structure. Store backups in a secure location outside the web root for production systems.</p>
      </div>

      <form method="POST" action="admin-backup.php" onsubmit="return confirm('Create a new database backup? This may take a few moments.');">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="create_backup">
        <button type="submit" class="btn btn-primary">💾 Create Backup Now</button>
      </form>
    </div>

    <!-- EXISTING BACKUPS -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Existing Backups (<?php echo count($backups); ?>)</div>
      </div>

      <?php if (empty($backups)): ?>
        <p style="color: var(--text-muted); padding: 24px; text-align: center;">No backups found. Create your first backup above.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Filename</th>
                <th>Size</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($backups as $backup): ?>
                <tr>
                  <td style="font-weight: 500; font-family: 'Courier New', monospace; font-size: 13px;">
                    <?php echo htmlspecialchars($backup['filename'], ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td><?php echo formatBytes($backup['size']); ?></td>
                  <td><?php echo date('M d, Y h:i A', $backup['date']); ?></td>
                  <td>
                    <a href="../../backups/<?php echo urlencode($backup['filename']); ?>" download class="btn btn-secondary btn-sm">Download</a>
                    <form method="POST" action="admin-backup.php" style="display: inline;" onsubmit="return confirm('Delete this backup file? This cannot be undone.');">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="delete_backup">
                      <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
