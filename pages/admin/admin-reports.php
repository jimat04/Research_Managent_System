<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');

$user = getCurrentUser();

// Get report statistics
$total_users = (int) ($conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0);
$total_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'] ?? 0);
$completed_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'completed'")->fetch_assoc()['count'] ?? 0);
$archived_research = (int) ($conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'archived'")->fetch_assoc()['count'] ?? 0);

// Get monthly research submissions (last 6 months)
$monthly_submissions = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-{$i} months"));
    $month_end = date('Y-m-t', strtotime("-{$i} months"));
    $month_label = date('M Y', strtotime("-{$i} months"));

    $count_query = $conn->query("SELECT COUNT(*) as count FROM research_projects WHERE created_at BETWEEN '{$month_start}' AND '{$month_end}'");
    $count = (int) ($count_query->fetch_assoc()['count'] ?? 0);

    $monthly_submissions[] = [
        'month' => $month_label,
        'count' => $count
    ];
}

// Get research by department
$dept_query = $conn->query("
    SELECT u.department, COUNT(*) as count
    FROM research_projects rp
    LEFT JOIN users u ON rp.created_by = u.user_id
    WHERE u.department IS NOT NULL AND u.department != ''
    GROUP BY u.department
    ORDER BY count DESC
    LIMIT 10
");
$research_by_dept = [];
while ($row = $dept_query->fetch_assoc()) {
    $research_by_dept[] = $row;
}

// Get research by status
$status_query = $conn->query("
    SELECT status, COUNT(*) as count
    FROM research_projects
    GROUP BY status
    ORDER BY count DESC
");
$research_by_status = [];
while ($row = $status_query->fetch_assoc()) {
    $research_by_status[] = $row;
}

// Page-specific styles only — sidebar/topbar styles live in css/admin-shell.css.
?>
<style>
  /* STATS GRID */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 24px;
    transition: all 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }

  .stat-number {
    font-size: 36px;
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

  .card-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 24px;
    color: var(--charcoal, #111827);
  }

  /* CHART */
  .chart-bar {
    margin-bottom: 16px;
  }

  .chart-bar-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 14px;
  }

  .chart-bar-track {
    height: 32px;
    background: var(--bg-surface, #F8FAFC);
    border-radius: 8px;
    overflow: hidden;
  }

  .chart-bar-fill {
    height: 100%;
    background: var(--gold, #C8A44D);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 0 12px;
    color: white;
    font-weight: 600;
    font-size: 13px;
    transition: width 0.5s;
  }

  /* BENTO GRID */
  .bento-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 24px;
  }

  .bento-card {
    background: var(--bg-card, #FFFFFF);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 20px;
    padding: 32px;
  }

  .bento-card.span-6 { grid-column: span 6; }
  .bento-card.span-12 { grid-column: span 12; }

  @media (max-width: 1200px) {
    .bento-card.span-6 {
      grid-column: span 12;
    }
  }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<?php

renderAdminShell(
    $user,
    'admin-reports',
    'Reports & Analytics',
    'System-wide insights and research metrics.'
);
?>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_users; ?></div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $total_research; ?></div>
        <div class="stat-label">Total Research</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $completed_research; ?></div>
        <div class="stat-label">Completed</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo $archived_research; ?></div>
        <div class="stat-label">Archived</div>
      </div>
    </div>

    <!-- BENTO GRID -->
    <div class="bento-grid">
      <!-- MONTHLY SUBMISSIONS -->
      <div class="bento-card span-6">
        <div class="card-title">Research Submissions (Last 6 Months)</div>
        <?php foreach ($monthly_submissions as $data): ?>
          <?php
          $max_count = max(array_column($monthly_submissions, 'count'));
          $width = $max_count > 0 ? ($data['count'] / $max_count) * 100 : 0;
          ?>
          <div class="chart-bar">
            <div class="chart-bar-label">
              <span style="font-weight: 500;"><?php echo htmlspecialchars($data['month'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span style="color: var(--text-secondary, #64748B);"><?php echo $data['count']; ?> projects</span>
            </div>
            <div class="chart-bar-track">
              <div class="chart-bar-fill" style="width: <?php echo $width; ?>%;">
                <?php if ($width > 15): ?>
                  <?php echo $data['count']; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- RESEARCH BY STATUS -->
      <div class="bento-card span-6">
        <div class="card-title">Research by Status</div>
        <?php
        $status_labels = [
          'draft' => 'Draft',
          'proposal' => 'Proposal',
          'under_crec_review' => 'CREC Review',
          'under_erec_review' => 'EREC Review',
          'approved' => 'Approved',
          'in_progress' => 'In Progress',
          'completed' => 'Completed',
          'archived' => 'Archived'
        ];
        $max_status_count = $research_by_status ? max(array_column($research_by_status, 'count')) : 1;
        foreach ($research_by_status as $data):
          $width = ($data['count'] / $max_status_count) * 100;
          $status_label = $status_labels[$data['status']] ?? ucfirst($data['status']);
        ?>
          <div class="chart-bar">
            <div class="chart-bar-label">
              <span style="font-weight: 500;"><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></span>
              <span style="color: var(--text-secondary, #64748B);"><?php echo $data['count']; ?> projects</span>
            </div>
            <div class="chart-bar-track">
              <div class="chart-bar-fill" style="width: <?php echo $width; ?>%;">
                <?php if ($width > 15): ?>
                  <?php echo $data['count']; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- RESEARCH BY DEPARTMENT -->
      <div class="bento-card span-12">
        <div class="card-title">Research by Department (Top 10)</div>
        <?php
        $max_dept_count = $research_by_dept ? max(array_column($research_by_dept, 'count')) : 1;
        if (empty($research_by_dept)): ?>
          <p style="color: var(--text-muted, #94A3B8); padding: 24px; text-align: center;">No department data available.</p>
        <?php else: ?>
          <?php foreach ($research_by_dept as $data):
            $width = ($data['count'] / $max_dept_count) * 100;
          ?>
            <div class="chart-bar">
              <div class="chart-bar-label">
                <span style="font-weight: 500;"><?php echo htmlspecialchars($data['department'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span style="color: var(--text-secondary, #64748B);"><?php echo $data['count']; ?> projects</span>
              </div>
              <div class="chart-bar-track">
                <div class="chart-bar-fill" style="width: <?php echo $width; ?>%;">
                  <?php if ($width > 10): ?>
                    <?php echo $data['count']; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

<?php
renderAdminShellClose();
