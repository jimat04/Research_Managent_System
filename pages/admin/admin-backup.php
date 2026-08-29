<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

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

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
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
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
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
    background: var(--gold, #C8A44D);
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(200,164,77,0.3);
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

  .btn-danger {
    background: #EF4444;
    color: white;
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

  .info-box {
    background: #EFF6FF;
    border-left: 4px solid #3B82F6;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: 14px;
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-backup',
    'Database Backup',
    'Create and manage database backups for system recovery.'
);
?>

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
        <p style="color: var(--text-muted, #94A3B8); padding: 24px; text-align: center;">No backups found. Create your first backup above.</p>
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

<?php
renderAdminShellClose();
