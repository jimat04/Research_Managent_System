<?php
include '../includes/config.php';
include '../includes/auth.php';

requireRole('research_staff');
$user = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── Helpers ──────────────────────────────────────────────────────────────────

function se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── Stat counts (static aggregate queries — no user-supplied input) ───────────

// Pending Staff Review: submissions awaiting completeness check
$stat_pending = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status = 'submitted'"
)->fetch_assoc()['count'] ?? 0);

// With CREC / Review
$stat_crec = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('under_review', 'under_crec_review')"
)->fetch_assoc()['count'] ?? 0);

// For Revision Return
$stat_revision = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('revision_required', 'for_revision')"
)->fetch_assoc()['count'] ?? 0);

// Completed / Archived this month
$stat_archive = (int) ($conn->query(
    "SELECT COUNT(*) AS count FROM research_projects
     WHERE status IN ('completed','archived')
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
)->fetch_assoc()['count'] ?? 0);

// ── Submissions Inbox (latest 10 submitted projects) ─────────────────────────
$inbox_stmt = $conn->prepare("
    SELECT
        rp.project_id,
        rp.title,
        rp.created_at,
        rp.status,
        u.first_name,
        u.last_name,
        rc.category_name,
        CASE WHEN pu.project_id IS NOT NULL THEN 1 ELSE 0 END AS has_proposal
    FROM research_projects rp
    LEFT JOIN users           u  ON u.user_id      = rp.created_by
    LEFT JOIN research_categories rc ON rc.category_id = rp.category_id
    LEFT JOIN (
        SELECT DISTINCT project_id FROM uploads WHERE type = 'proposal'
    ) pu ON pu.project_id = rp.project_id
    WHERE rp.status = 'submitted'
    ORDER BY rp.created_at DESC
    LIMIT 10
");
$inbox_stmt->execute();
$inbox = $inbox_stmt->get_result();

// ── Repository Overview (donut chart — 5 buckets) ────────────────────────────
$repo_row = $conn->query("
    SELECT
        SUM(status = 'draft')                                                                                           AS draft_count,
        SUM(status IN ('submitted','under_review','under_crec_review','under_erec_review'))                             AS review_count,
        SUM(status IN ('for_revision','revision_required'))                                                             AS revision_count,
        SUM(status IN ('approved','ongoing','progress_report','terminal_review'))                                        AS ongoing_count,
        SUM(status IN ('completed','archived'))                                                                         AS completed_count
    FROM research_projects
")->fetch_assoc();

$repo_draft     = (int) ($repo_row['draft_count']     ?? 0);
$repo_review    = (int) ($repo_row['review_count']    ?? 0);
$repo_revision  = (int) ($repo_row['revision_count']  ?? 0);
$repo_ongoing   = (int) ($repo_row['ongoing_count']   ?? 0);
$repo_completed = (int) ($repo_row['completed_count'] ?? 0);
$repo_total     = $repo_draft + $repo_review + $repo_revision + $repo_ongoing + $repo_completed;

// Build conic-gradient degree stops
$deg = function (int $count) use ($repo_total): float {
    return $repo_total > 0 ? round(($count / $repo_total) * 360, 2) : 0;
};
$d1 = $deg($repo_draft);
$d2 = $d1 + $deg($repo_review);
$d3 = $d2 + $deg($repo_revision);
$d4 = $d3 + $deg($repo_ongoing);

// Colors for donut segments
$donut_css = $repo_total > 0
    ? "conic-gradient(
        #94a3b8   0deg {$d1}deg,
        #5B1EBC   {$d1}deg {$d2}deg,
        #f59e0b   {$d2}deg {$d3}deg,
        #0F6CBD   {$d3}deg {$d4}deg,
        #10b981   {$d4}deg 360deg
      )"
    : '#e2e8f0';

// ── Recent Activity (latest 10 rows from activity_log) ───────────────────────
$activity_stmt = $conn->prepare("
    SELECT al.action, al.module, al.created_at,
           u.first_name, u.last_name
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
");
$activity_stmt->execute();
$activities = $activity_stmt->get_result();

// ── Status → CSS class + label map ───────────────────────────────────────────
function statusBadge(string $status): array {
    $map = [
        'draft'               => ['status-draft',    'Draft'],
        'submitted'           => ['status-review',   'Submitted'],
        'under_review'        => ['status-review',   'Under Review'],
        'under_crec_review'   => ['status-review',   'CREC Review'],
        'under_erec_review'   => ['status-review',   'EREC Review'],
        'for_revision'        => ['status-pending',  'For Revision'],
        'revision_required'   => ['status-pending',  'Revision Required'],
        'progress_report'     => ['status-pending',  'Progress Report'],
        'terminal_review'     => ['status-pending',  'Terminal Review'],
        'approved'            => ['status-approved', 'Approved'],
        'ongoing'             => ['status-approved', 'Ongoing'],
        'completed'           => ['status-approved', 'Completed'],
        'archived'            => ['status-approved', 'Archived'],
    ];
    return $map[$status] ?? ['status-draft', ucwords(str_replace('_', ' ', $status))];
}

// ── Activity dot color ───────────────────────────────────────────────────────
function activityDotColor(string $action): string {
    $a = strtolower($action);
    if (strpos($a, 'approv') !== false || strpos($a, 'login') !== false)  return '#22c55e';
    if (strpos($a, 'submi')  !== false || strpos($a, 'revis') !== false)  return '#f59e0b';
    if (strpos($a, 'creat')  !== false || strpos($a, 'regist') !== false) return '#0F6CBD';
    return '#94a3b8';
}

$initials = se(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)));
$full_name = se($user['first_name'] . ' ' . $user['last_name']);
$first_name = se($user['first_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Research Staff Dashboard — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<div class="dashboard">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, #0d9488, #059669); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">
        Research<br>Management<br><small style="font-size: 0.65rem; color: #8B8FAD;">Staff</small>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-title">OVERVIEW</div>
      <div class="nav-item active" onclick="location.href='staff-dashboard.php'">
        <span class="icon">📊</span><span>Dashboard</span>
      </div>

      <div class="nav-group-title">PROCESSING</div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span class="icon">📥</span><span>Submissions Inbox</span>
        <span class="badge"><?php echo se($stat_pending); ?></span>
      </div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span class="icon">🏛️</span><span>For CREC Review</span>
        <?php if ($stat_crec > 0): ?>
          <span class="badge"><?php echo se($stat_crec); ?></span>
        <?php endif; ?>
      </div>
      <div class="nav-item" onclick="location.href='#inbox'">
        <span class="icon">🔄</span><span>Revision Returns</span>
        <?php if ($stat_revision > 0): ?>
          <span class="badge"><?php echo se($stat_revision); ?></span>
        <?php endif; ?>
      </div>

      <div class="nav-group-title">REPOSITORY</div>
      <div class="nav-item" onclick="location.href='research-archive.php'">
        <span class="icon">🗂️</span><span>Research Archive</span>
      </div>
      <div class="nav-item" onclick="location.href='#'">
        <span class="icon">📄</span><span>Document Verification</span>
      </div>

      <div class="nav-group-title">COMMUNICATION</div>
      <div class="nav-item" onclick="location.href='messages.php'">
        <span class="icon">💬</span><span>Messages</span>
        <span class="badge">0</span>
      </div>
      <div class="nav-item" onclick="location.href='notifications.php'">
        <span class="icon">🔔</span><span>Notifications</span>
      </div>

      <div class="nav-group-title">ACCOUNT</div>
      <div class="nav-item" onclick="location.href='profile.php'">
        <span class="icon">👤</span><span>Profile</span>
      </div>
      <div class="nav-item" onclick="location.href='../logout.php'" style="color: #ef4444;">
        <span class="icon">🚪</span><span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar" style="background: linear-gradient(135deg, #0d9488, #059669);"><?php echo $initials; ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo $full_name; ?></div>
          <div class="user-role" style="color: #0d9488;">📋 Research Staff</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">

    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <h2>Research Staff Dashboard</h2>
        <p>Process submissions and manage the research repository.</p>
      </div>
      <div class="topbar-right">
        <div class="search-box">
          <span style="color: #94a3b8;">🔍</span>
          <input type="text" placeholder="Search submissions...">
        </div>
        <div class="topbar-icons">
          <div class="icon-btn has-notif">🔔</div>
        </div>
        <div class="user-profile-btn" onclick="alert('Profile menu')">
          <div class="profile-avatar" style="background: linear-gradient(135deg, #0d9488, #059669);"><?php echo $initials; ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo $first_name; ?></div>
            <div class="profile-role" style="color: #0d9488;">Research Staff</div>
          </div>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="page-content">

      <!-- WELCOME BANNER -->
      <div class="card" style="background: linear-gradient(135deg, #0d9488, #059669); color: #fff; margin-bottom: 20px;">
        <div class="card-body">
          <h3 style="margin: 0 0 8px; font-size: 1.3rem;">Welcome back, <?php echo $first_name; ?> 👋</h3>
          <p style="margin: 0 0 16px; opacity: 0.9; font-size: 0.95rem;">
            You have <strong><?php echo se($stat_pending); ?></strong> new submission<?php echo $stat_pending !== 1 ? 's' : ''; ?> awaiting completeness verification,
            <strong><?php echo se($stat_crec); ?></strong> in review, and
            <strong><?php echo se($stat_revision); ?></strong> pending revision return.
          </p>
          <a href="#inbox"
             onclick="document.getElementById('inbox').scrollIntoView({behavior:'smooth'}); return false;"
             style="display: inline-block; padding: 9px 20px; background: rgba(255,255,255,0.2); color: #fff;
                    border: 1px solid rgba(255,255,255,0.4); border-radius: 6px; font-weight: 600;
                    text-decoration: none; font-size: 0.88rem;">
            View Inbox ↓
          </a>
        </div>
      </div>

      <!-- STAT CARDS -->
      <div class="stats-grid">
        <div class="stat-card orange">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo se($stat_pending); ?></div>
              <div class="stat-label">Pending Staff Review</div>
            </div>
            <div class="stat-icon">📥</div>
          </div>
        </div>

        <div class="stat-card blue">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo se($stat_crec); ?></div>
              <div class="stat-label">In Review</div>
            </div>
            <div class="stat-icon">🏛️</div>
          </div>
        </div>

        <div class="stat-card orange">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo se($stat_revision); ?></div>
              <div class="stat-label">For Revision Return</div>
            </div>
            <div class="stat-icon">🔄</div>
          </div>
        </div>

        <div class="stat-card green">
          <div class="stat-header">
            <div style="flex: 1;">
              <div class="stat-number"><?php echo se($stat_archive); ?></div>
              <div class="stat-label">Archived (Last 30 Days)</div>
            </div>
            <div class="stat-icon">🗂️</div>
          </div>
        </div>
      </div>

      <!-- MIDDLE ROW -->
      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">

        <!-- REPOSITORY OVERVIEW -->
        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Repository Overview</div>
              <div class="card-subtitle">All active &amp; completed research projects</div>
            </div>
            <a href="research-archive.php" class="card-action" style="font-size: 0.82rem; color: var(--primary); text-decoration: none; font-weight: 600;">View Full Archive ↗</a>
          </div>
          <div class="card-body">
            <div style="display: flex; gap: 32px; align-items: center; flex-wrap: wrap;">
              <div class="chart-placeholder" style="flex-shrink: 0;">
                <div class="donut-chart" style="background: <?php echo $donut_css; ?>; width: 140px; height: 140px; border-radius: 50%; position: relative; display: flex; align-items: center; justify-content: center;">
                  <div class="donut-center" style="width: 84px; height: 84px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 1px 4px rgba(0,0,0,0.08);">
                    <div class="value" style="font-size: 1.3rem; font-weight: 700; color: var(--primary); line-height: 1;"><?php echo se($repo_total); ?></div>
                    <div class="label" style="font-size: 0.65rem; color: #64748b; margin-top: 2px;">Total</div>
                  </div>
                </div>
              </div>

              <div class="chart-legend" style="flex: 1; min-width: 180px;">
                <div class="legend-item" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 0.82rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="legend-dot" style="width: 10px; height: 10px; border-radius: 50%; background: #94a3b8; flex-shrink: 0;"></div>
                    <span class="legend-label" style="color: var(--text-dark);">Draft</span>
                  </div>
                  <span class="legend-pct" style="font-weight: 600; color: #64748b;"><?php echo se($repo_draft); ?></span>
                </div>
                <div class="legend-item" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 0.82rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="legend-dot" style="width: 10px; height: 10px; border-radius: 50%; background: #5B1EBC; flex-shrink: 0;"></div>
                    <span class="legend-label" style="color: var(--text-dark);">In Review</span>
                  </div>
                  <span class="legend-pct" style="font-weight: 600; color: #5B1EBC;"><?php echo se($repo_review); ?></span>
                </div>
                <div class="legend-item" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 0.82rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="legend-dot" style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b; flex-shrink: 0;"></div>
                    <span class="legend-label" style="color: var(--text-dark);">For Revision</span>
                  </div>
                  <span class="legend-pct" style="font-weight: 600; color: #f59e0b;"><?php echo se($repo_revision); ?></span>
                </div>
                <div class="legend-item" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 0.82rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="legend-dot" style="width: 10px; height: 10px; border-radius: 50%; background: #0F6CBD; flex-shrink: 0;"></div>
                    <span class="legend-label" style="color: var(--text-dark);">Ongoing</span>
                  </div>
                  <span class="legend-pct" style="font-weight: 600; color: #0F6CBD;"><?php echo se($repo_ongoing); ?></span>
                </div>
                <div class="legend-item" style="display: flex; align-items: center; justify-content: space-between; font-size: 0.82rem;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="legend-dot" style="width: 10px; height: 10px; border-radius: 50%; background: #10b981; flex-shrink: 0;"></div>
                    <span class="legend-label" style="color: var(--text-dark);">Completed / Archived</span>
                  </div>
                  <span class="legend-pct" style="font-weight: 600; color: #10b981;"><?php echo se($repo_completed); ?></span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Recent Activity</div>
            <a href="notifications.php" class="card-action" style="font-size: 0.82rem; color: var(--primary); text-decoration: none; font-weight: 600;">View all ↗</a>
          </div>
          <div class="card-body" style="max-height: 280px; overflow-y: auto;">
            <ul class="activity-list" style="margin: 0; padding: 0; list-style: none;">
              <?php
              $act_count = 0;
              while ($act = $activities->fetch_assoc()):
                  $act_count++;
                  $dot_color = activityDotColor($act['action'] ?? '');
                  $act_user  = trim(($act['first_name'] ?? '') . ' ' . ($act['last_name'] ?? ''));
                  $time_str  = !empty($act['created_at']) ? date('M d, Y • h:i A', strtotime($act['created_at'])) : '';
              ?>
                <li class="activity-item" style="display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-start;">
                  <div class="activity-dot" style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $dot_color; ?>; margin-top: 5px; flex-shrink: 0;"></div>
                  <div class="activity-content" style="flex: 1; font-size: 0.82rem;">
                    <p style="margin: 0; color: var(--text-dark); line-height: 1.4;">
                      <?php if ($act_user): ?><strong><?php echo se($act_user); ?>:</strong> <?php endif; ?>
                      <?php echo se($act['action']); ?>
                    </p>
                    <?php if ($time_str): ?>
                      <div class="time" style="font-size: 0.72rem; color: #94a3b8; margin-top: 2px;"><?php echo se($time_str); ?></div>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endwhile; ?>
              <?php if ($act_count === 0): ?>
                <li style="font-size: 0.82rem; color: #94a3b8; text-align: center; padding: 16px 0;">No recent activity.</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>

      </div>

      <!-- SUBMISSIONS INBOX -->
      <div class="card" id="inbox">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
          <div>
            <div class="card-title">Submissions Inbox</div>
            <div class="card-subtitle">New submissions awaiting completeness verification</div>
          </div>
          <span style="font-size: 0.82rem; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; font-weight: 500;">
            Showing up to 10 latest
          </span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Research Title</th>
                <th>Proponent</th>
                <th>Category</th>
                <th>Submitted</th>
                <th>Proposal Doc</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $inbox_count = 0;
              while ($row = $inbox->fetch_assoc()):
                  $inbox_count++;
                  [$badge_class, $badge_label] = statusBadge($row['status'] ?? 'submitted');
                  $proponent = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
                  $category  = $row['category_name'] ?? 'General';
                  $sub_date  = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—';
                  $has_doc   = (int) ($row['has_proposal'] ?? 0) === 1;
              ?>
                <tr>
                  <td style="font-weight: 500; max-width: 260px;">
                    <span title="<?php echo se($row['title']); ?>">
                      <?php echo se(mb_strimwidth($row['title'], 0, 50, '…')); ?>
                    </span>
                  </td>
                  <td><?php echo se($proponent); ?></td>
                  <td><span style="font-size: 0.8rem; color: #64748b;"><?php echo se($category); ?></span></td>
                  <td><?php echo se($sub_date); ?></td>
                  <td>
                    <?php if ($has_doc): ?>
                      <span style="color: #10b981; font-size: 0.8rem; font-weight: 600;">✓ Attached</span>
                    <?php else: ?>
                      <span style="color: #f59e0b; font-size: 0.8rem;">⚠️ Missing</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge-status <?php echo se($badge_class); ?>">
                      <?php echo se($badge_label); ?>
                    </span>
                  </td>
                  <td>
                    <a class="btn btn-primary btn-sm"
                       href="research-detail.php?id=<?php echo (int) $row['project_id']; ?>"
                       style="padding: 4px 10px; font-size: 0.78rem;">
                      Review
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if ($inbox_count === 0): ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 28px; color: #94a3b8; font-size: 0.88rem;">
                    🎉 No pending submissions in inbox.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
  });
});
</script>
</body>
</html>