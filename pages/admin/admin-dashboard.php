<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0);
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$total_archived = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

$total_students = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'] ?? 0);
$total_faculty = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'faculty'")->fetch_assoc()['count'] ?? 0);
$total_staff = (int) ($conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'research_staff'")->fetch_assoc()['count'] ?? 0);

$research_draft = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'draft'")->fetch_assoc()['count'] ?? 0);
$research_review = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review')")->fetch_assoc()['count'] ?? 0);
$research_approved = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('approved', 'ongoing')")->fetch_assoc()['count'] ?? 0);
$research_completed = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status IN ('completed', 'archived')")->fetch_assoc()['count'] ?? 0);

// Get recent activity
$activity_stmt = $conn->prepare("
    SELECT al.action, al.module, al.created_at, u.first_name, u.last_name
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 8
");
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: #ffffff;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 24px;
    transition: transform 0.3s, box-shadow 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  }

  .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .stat-number {
    font-size: 36px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }

  .stat-label {
    font-size: 14px;
    color: var(--text-light, #64748B);
    font-weight: 500;
  }

  .stat-icon {
    font-size: 32px;
    opacity: 0.3;
  }

  /* BENTO GRID */
  .bento-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 24px;
    margin-bottom: 48px;
  }

  .bento-card {
    background: #ffffff;
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
  }

  .bento-card.span-8 { grid-column: span 8; }
  .bento-card.span-4 { grid-column: span 4; }
  .bento-card.span-6 { grid-column: span 6; }
  .bento-card.span-12 { grid-column: span 12; }

  .card-header {
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .card-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--charcoal, #111827);
  }

  .card-subtitle {
    font-size: 14px;
    color: var(--text-light, #64748B);
  }

  .card-action {
    color: var(--gold, #C8A44D);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }

  /* DONUT CHART */
  .chart-container {
    display: flex;
    gap: 32px;
    align-items: center;
    flex-wrap: wrap;
  }

  .donut-chart {
    width: 160px;
    height: 160px;
    border-radius: 50%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .donut-center {
    width: 100px;
    height: 100px;
    background: #ffffff;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  }

  .donut-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
  }

  .donut-label {
    font-size: 12px;
    color: var(--text-light, #64748B);
    margin-top: 4px;
  }

  .chart-legend {
    flex: 1;
    min-width: 200px;
  }

  .legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 14px;
  }

  .legend-left {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .legend-label {
    color: var(--text-dark, #111827);
  }

  .legend-value {
    font-weight: 600;
    color: var(--text-light, #64748B);
  }

  /* ACTIVITY LIST */
  .activity-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .activity-item {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border, #E5E7EB);
  }

  .activity-item:last-child {
    border-bottom: none;
  }

  .activity-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 6px;
    flex-shrink: 0;
  }

  .activity-content {
    flex: 1;
  }

  .activity-content p {
    font-size: 14px;
    margin: 0 0 4px;
  }

  .activity-time {
    font-size: 12px;
    color: var(--text-muted, #94A3B8);
  }

  /* QUICK ACTIONS */
  .quick-actions {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }

  .action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--bg-light, #F8F9FE);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 12px;
    cursor: pointer;
    transition: background-color 0.2s, border-color 0.2s, transform 0.2s;
    text-decoration: none;
    color: inherit;
  }

  .action-btn:hover {
    background: #ffffff;
    border-color: var(--gold, #C8A44D);
    transform: translateY(-1px);
  }

  .action-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--gold, #C8A44D);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }

  .action-text {
    flex: 1;
  }

  .action-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
  }

  .action-desc {
    font-size: 12px;
    color: var(--text-light, #64748B);
  }

  /* RESPONSIVE */
  @media (max-width: 1200px) {
    .bento-card.span-8,
    .bento-card.span-4 {
      grid-column: span 12;
    }
  }

  @media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr; }
    .bento-card { padding: 24px; }
    .quick-actions { grid-template-columns: 1fr; }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-dashboard',
    'System Overview',
    'University-wide research management analytics and controls.'
);
?>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $total_users; ?></div>
            <div class="stat-label">Total Users</div>
          </div>
          <div class="stat-icon">👥</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $total_research; ?></div>
            <div class="stat-label">Total Research</div>
          </div>
          <div class="stat-icon">📁</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo $total_archived; ?></div>
            <div class="stat-label">Archived Projects</div>
          </div>
          <div class="stat-icon">🗂️</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <div>
            <div class="stat-number"><?php echo number_format($total_users + $total_research); ?></div>
            <div class="stat-label">Records Under Management</div>
          </div>
          <div class="stat-icon">🗒️</div>
        </div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- USER DISTRIBUTION -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">User Distribution</div>
            <div class="card-subtitle">By role</div>
          </div>
          <a href="admin-users.php" class="card-action">Manage users →</a>
        </div>

        <div class="chart-container">
          <?php
          $user_deg1 = $total_users > 0 ? ($total_students / $total_users) * 360 : 0;
          $user_deg2 = $user_deg1 + ($total_users > 0 ? ($total_faculty / $total_users) * 360 : 0);
          ?>
          <div class="donut-chart" style="background: conic-gradient(#2563EB 0deg <?php echo $user_deg1; ?>deg, #7C3AED <?php echo $user_deg1; ?>deg <?php echo $user_deg2; ?>deg, #C8A44D <?php echo $user_deg2; ?>deg 360deg);">
            <div class="donut-center">
              <div class="donut-value"><?php echo $total_users; ?></div>
              <div class="donut-label">Total</div>
            </div>
          </div>

          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #2563EB;"></div>
                <span class="legend-label">Students</span>
              </div>
              <span class="legend-value"><?php echo $total_students; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #7C3AED;"></div>
                <span class="legend-label">Faculty</span>
              </div>
              <span class="legend-value"><?php echo $total_faculty; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #C8A44D;"></div>
                <span class="legend-label">Staff</span>
              </div>
              <span class="legend-value"><?php echo $total_staff; ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- RESEARCH STATUS -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Research Status</div>
            <div class="card-subtitle">Distribution by stage</div>
          </div>
          <a href="admin-research.php" class="card-action">View all →</a>
        </div>

        <div class="chart-container">
          <?php
          $res_deg1 = $total_research > 0 ? ($research_draft / $total_research) * 360 : 0;
          $res_deg2 = $res_deg1 + ($total_research > 0 ? ($research_review / $total_research) * 360 : 0);
          $res_deg3 = $res_deg2 + ($total_research > 0 ? ($research_approved / $total_research) * 360 : 0);
          ?>
          <div class="donut-chart" style="background: conic-gradient(#64748B 0deg <?php echo $res_deg1; ?>deg, #2563EB <?php echo $res_deg1; ?>deg <?php echo $res_deg2; ?>deg, #16A34A <?php echo $res_deg2; ?>deg <?php echo $res_deg3; ?>deg, #059669 <?php echo $res_deg3; ?>deg 360deg);">
            <div class="donut-center">
              <div class="donut-value"><?php echo $total_research; ?></div>
              <div class="donut-label">Total</div>
            </div>
          </div>

          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #64748B;"></div>
                <span class="legend-label">Draft</span>
              </div>
              <span class="legend-value"><?php echo $research_draft; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #2563EB;"></div>
                <span class="legend-label">Under Review</span>
              </div>
              <span class="legend-value"><?php echo $research_review; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #16A34A;"></div>
                <span class="legend-label">Approved</span>
              </div>
              <span class="legend-value"><?php echo $research_approved; ?></span>
            </div>
            <div class="legend-item">
              <div class="legend-left">
                <div class="legend-dot" style="background: #059669;"></div>
                <span class="legend-label">Completed</span>
              </div>
              <span class="legend-value"><?php echo $research_completed; ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-subtitle">Common administrative tasks</div>
          </div>
        </div>

        <div class="quick-actions">
          <a href="admin-users.php" class="action-btn">
            <div class="action-icon">👤</div>
            <div class="action-text">
              <div class="action-title">Add User</div>
              <div class="action-desc">Create new account</div>
            </div>
          </a>

          <a href="admin-research.php" class="action-btn">
            <div class="action-icon">📁</div>
            <div class="action-text">
              <div class="action-title">Manage Research</div>
              <div class="action-desc">Review projects</div>
            </div>
          </a>

          <a href="admin-reports.php" class="action-btn">
            <div class="action-icon">📊</div>
            <div class="action-text">
              <div class="action-title">Generate Report</div>
              <div class="action-desc">Analytics & insights</div>
            </div>
          </a>

          <a href="admin-backup.php" class="action-btn">
            <div class="action-icon">💾</div>
            <div class="action-text">
              <div class="action-title">System Backup</div>
              <div class="action-desc">Export database</div>
            </div>
          </a>

          <a href="admin-archive.php" class="action-btn">
            <div class="action-icon">🗂️</div>
            <div class="action-text">
              <div class="action-title">Archive Management</div>
              <div class="action-desc">View completed research</div>
            </div>
          </a>

          <a href="admin-logs.php" class="action-btn">
            <div class="action-icon">📋</div>
            <div class="action-text">
              <div class="action-title">Activity Logs</div>
              <div class="action-desc">System audit trail</div>
            </div>
          </a>
        </div>
      </div>

      <!-- SYSTEM ACTIVITY -->
      <div class="bento-card span-6">
        <div class="card-header">
          <div>
            <div class="card-title">System Activity</div>
            <div class="card-subtitle">Recent actions across the platform</div>
          </div>
          <a href="admin-logs.php" class="card-action">View logs →</a>
        </div>

        <ul class="activity-list">
          <?php
          if ($activities->num_rows > 0):
            while ($activity = $activities->fetch_assoc()):
              $user_name = trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? ''));
              $action = $activity['action'] ?? 'Unknown action';
              $created_at = $activity['created_at'] ?? '';

              $activity_lower = strtolower($action);
              $dot_color = '#2563EB';
              if (strpos($activity_lower, 'approved') !== false) {
                $dot_color = '#16A34A';
              } elseif (strpos($activity_lower, 'submitted') !== false || strpos($activity_lower, 'created') !== false) {
                $dot_color = '#7C3AED';
              } elseif (strpos($activity_lower, 'revision') !== false || strpos($activity_lower, 'rejected') !== false) {
                $dot_color = '#EA580C';
              } elseif (strpos($activity_lower, 'login') !== false) {
                $dot_color = '#C8A44D';
              }
          ?>
            <li class="activity-item">
              <div class="activity-dot" style="background: <?php echo $dot_color; ?>;"></div>
              <div class="activity-content">
                <p><?php echo $user_name ? '<strong>' . htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') . '</strong>: ' : ''; ?><?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="activity-time"><?php echo $created_at ? date('M d, Y • h:i A', strtotime($created_at)) : ''; ?></div>
              </div>
            </li>
          <?php
            endwhile;
          else:
          ?>
            <li class="activity-item">
              <div class="activity-content">
                <p style="color: var(--text-muted, #94A3B8);">No recent activity</p>
              </div>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

<?php
renderAdminShellClose();
