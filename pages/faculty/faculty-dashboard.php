<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// Get assigned research projects
$assigned_stmt = $conn->prepare("
    SELECT rp.*, student.first_name AS student_first_name, student.last_name AS student_last_name
    FROM research_projects rp
    JOIN project_advisers pa ON rp.project_id = pa.project_id
    JOIN users student ON student.user_id = rp.created_by
    WHERE pa.adviser_id = ?
    ORDER BY rp.created_at DESC
");
$assigned_stmt->bind_param('i', $user_id);
$assigned_stmt->execute();
$assigned = $assigned_stmt->get_result();

// Get statistics
$pending_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'under_review'
");
$pending_stmt->bind_param('i', $user_id);
$pending_stmt->execute();
$stat_pending = (int) ($pending_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$approved_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'approved'
");
$approved_stmt->bind_param('i', $user_id);
$approved_stmt->execute();
$stat_approved = (int) ($approved_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$revision_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM chapters c
    WHERE c.project_id IN (
        SELECT rp.project_id FROM research_projects rp
        JOIN project_advisers pa ON rp.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    ) AND c.status = 'revision_required'
");
$revision_stmt->bind_param('i', $user_id);
$revision_stmt->execute();
$stat_revision = (int) ($revision_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$students_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT rp.created_by) as count FROM research_projects rp
    JOIN project_advisers pa ON rp.project_id = pa.project_id
    WHERE pa.adviser_id = ?
");
$students_stmt->bind_param('i', $user_id);
$students_stmt->execute();
$stat_students = (int) ($students_stmt->get_result()->fetch_assoc()['count'] ?? 0);

$activity_stmt = $conn->prepare("SELECT action, module, created_at
  FROM activity_log
  WHERE user_id = ?
  ORDER BY created_at DESC
  LIMIT 5");
$activity_stmt->bind_param('i', $user_id);
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// Get recent submissions (last 5)
$recent_stmt = $conn->prepare("
    SELECT c.*, rp.title as project_title, u.first_name, u.last_name
    FROM chapters c
    JOIN research_projects rp ON c.project_id = rp.project_id
    JOIN users u ON rp.created_by = u.user_id
    WHERE rp.project_id IN (
        SELECT rp2.project_id FROM research_projects rp2
        JOIN project_advisers pa ON rp2.project_id = pa.project_id
        WHERE pa.adviser_id = ?
    )
    ORDER BY c.submitted_at DESC
    LIMIT 5
");
$recent_stmt->bind_param('i', $user_id);
$recent_stmt->execute();
$recent_submissions = $recent_stmt->get_result();

// Render the faculty shell
renderFacultyShell(
    $user,
    'faculty-dashboard',
    'Good day, Prof. ' . $user['last_name'],
    'You have ' . $stat_pending . ' submission' . ($stat_pending !== 1 ? 's' : '') . ' awaiting your review.'
);
?>

<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
  }

  .stat-card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 24px;
    transition: all 0.3s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
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
    color: #64748B;
    font-weight: 500;
  }

  .stat-icon {
    font-size: 32px;
    opacity: 0.3;
  }

  .bento-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 24px;
    margin-bottom: 48px;
  }

  .bento-card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
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
    color: #111827;
  }

  .card-subtitle {
    font-size: 14px;
    color: #64748B;
  }

  .card-action {
    color: #1d4ed8;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }

  .activity-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .activity-item {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid #E5E7EB;
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
    margin-bottom: 4px;
  }

  .activity-time {
    font-size: 12px;
    color: #94A3B8;
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
    font-size: 13px;
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

  .badge-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
  }

  .badge-status.status-under-review {
    background: #DBEAFE;
    color: #2563EB;
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
    background: #1d4ed8;
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
  }

  .btn-sm {
    padding: 6px 14px;
    font-size: 13px;
  }

  .progress-bar-container {
    margin-bottom: 24px;
  }

  .progress-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
  }

  .progress-label {
    font-weight: 500;
    color: #64748B;
  }

  .progress-value {
    font-weight: 600;
    color: #111827;
  }

  .progress-bar {
    height: 8px;
    background: #F8FAFC;
    border-radius: 10px;
    overflow: hidden;
  }

  .progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s;
  }

  .progress-fill.blue { background: #2563EB; }
  .progress-fill.green { background: #16A34A; }
  .progress-fill.orange { background: #EA580C; }
  .progress-fill.purple { background: #7C3AED; }

  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #94A3B8;
  }

  .empty-state-icon {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
  }

  .empty-state p {
    font-size: 14px;
  }

  @media (max-width: 1200px) {
    .bento-card.span-8,
    .bento-card.span-4 {
      grid-column: span 12;
    }
  }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }

    .bento-card {
      padding: 24px;
    }
  }
</style>

<!-- STATS GRID -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-header">
      <div>
        <div class="stat-number"><?php echo $stat_pending; ?></div>
        <div class="stat-label">Pending Reviews</div>
      </div>
      <div class="stat-icon">📥</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <div>
        <div class="stat-number"><?php echo $stat_approved; ?></div>
        <div class="stat-label">Approved</div>
      </div>
      <div class="stat-icon">✅</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <div>
        <div class="stat-number"><?php echo $stat_revision; ?></div>
        <div class="stat-label">Revision Required</div>
      </div>
      <div class="stat-icon">🔄</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <div>
        <div class="stat-number"><?php echo $stat_students; ?></div>
        <div class="stat-label">Total Advisees</div>
      </div>
      <div class="stat-icon">👨‍🎓</div>
    </div>
  </div>
</div>

<!-- BENTO GRID -->
<div class="bento-grid">
  <!-- REVIEW QUEUE -->
  <div class="bento-card span-12">
    <div class="card-header">
      <div>
        <div class="card-title">Review Queue</div>
        <div class="card-subtitle">Research projects assigned to you</div>
      </div>
      <a href="faculty-review.php" class="btn btn-primary">View All Reviews</a>
    </div>

    <?php if ($assigned->num_rows > 0): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Research Title</th>
              <th>Student</th>
              <th>Date Submitted</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $assigned->data_seek(0);
            $count = 0;
            while ($proj = $assigned->fetch_assoc()):
              if ($count >= 5) break;
              $count++;
            ?>
              <tr>
                <td style="font-weight: 500;"><?php echo htmlspecialchars($proj['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($proj['student_first_name'] . ' ' . $proj['student_last_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo date('M d, Y', strtotime($proj['created_at'])); ?></td>
                <td>
                  <span class="badge-status status-under-review">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $proj['status'])), ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td>
                  <a class="btn btn-primary btn-sm" href="faculty-review-detail.php?id=<?php echo (int)$proj['project_id']; ?>">Review</a>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <p>No research projects assigned yet.</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- RECENT SUBMISSIONS -->
  <div class="bento-card span-6">
    <div class="card-header">
      <div>
        <div class="card-title">Recent Submissions</div>
        <div class="card-subtitle">Latest chapter submissions</div>
      </div>
      <a href="faculty-submissions.php" class="card-action">View all →</a>
    </div>

    <ul class="activity-list">
      <?php
      if ($recent_submissions->num_rows > 0):
        while ($sub = $recent_submissions->fetch_assoc()):
      ?>
        <li class="activity-item">
          <div class="activity-dot" style="background: #2563EB;"></div>
          <div class="activity-content">
            <p><strong><?php echo htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <p style="font-size: 13px; color: #64748B;">Chapter <?php echo $sub['chapter_number']; ?> — <?php echo htmlspecialchars($sub['project_title'], ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="activity-time"><?php echo date('M d, Y', strtotime($sub['created_at'])); ?></div>
          </div>
        </li>
      <?php
        endwhile;
      else:
      ?>
        <li class="activity-item">
          <div class="activity-content">
            <p style="color: #94A3B8;">No recent submissions</p>
          </div>
        </li>
      <?php endif; ?>
    </ul>
  </div>

  <!-- REVIEW ANALYTICS -->
  <div class="bento-card span-6">
    <div class="card-header">
      <div>
        <div class="card-title">Review Analytics</div>
        <div class="card-subtitle">Your review activity</div>
      </div>
    </div>

    <div class="progress-bar-container">
      <div class="progress-header">
        <span class="progress-label">Approved Chapters</span>
        <span class="progress-value"><?php echo $stat_approved; ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill green" style="width: <?php echo min(100, ($stat_approved / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
      </div>
    </div>

    <div class="progress-bar-container">
      <div class="progress-header">
        <span class="progress-label">Pending Review</span>
        <span class="progress-value"><?php echo $stat_pending; ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill blue" style="width: <?php echo min(100, ($stat_pending / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
      </div>
    </div>

    <div class="progress-bar-container">
      <div class="progress-header">
        <span class="progress-label">Revision Required</span>
        <span class="progress-value"><?php echo $stat_revision; ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill orange" style="width: <?php echo min(100, ($stat_revision / max(1, $stat_approved + $stat_pending + $stat_revision)) * 100); ?>%;"></div>
      </div>
    </div>

    <div class="progress-bar-container" style="margin-bottom: 0;">
      <div class="progress-header">
        <span class="progress-label">Total Advisees</span>
        <span class="progress-value"><?php echo $stat_students; ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill purple" style="width: <?php echo min(100, ($stat_students / 10) * 100); ?>%;"></div>
      </div>
    </div>
  </div>

  <!-- RECENT ACTIVITY -->
  <div class="bento-card span-12">
    <div class="card-header">
      <div>
        <div class="card-title">Your Recent Activity</div>
        <div class="card-subtitle">Latest actions and updates</div>
      </div>
    </div>

    <ul class="activity-list">
      <?php
      if ($activities->num_rows > 0):
        while ($activity = $activities->fetch_assoc()):
          $activity_action = strtolower($activity['action']);
          $activity_color = '#2563EB';
          if (strpos($activity_action, 'approved') !== false) {
              $activity_color = '#16A34A';
          } elseif (strpos($activity_action, 'revision') !== false || strpos($activity_action, 'rejected') !== false) {
              $activity_color = '#EA580C';
          }
      ?>
        <li class="activity-item">
          <div class="activity-dot" style="background: <?php echo $activity_color; ?>;"></div>
          <div class="activity-content">
            <p><?php echo htmlspecialchars($activity['action'], ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="activity-time"><?php echo date('M d, Y • h:i A', strtotime($activity['created_at'])); ?></div>
          </div>
        </li>
      <?php
        endwhile;
      else:
      ?>
        <li class="activity-item">
          <div class="activity-content">
            <p style="color: #94A3B8;">No recent activity</p>
          </div>
        </li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<?php renderFacultyShellClose(); ?>
