<?php
/**
 * Staff — Revision Returns
 *
 * Read-only monitor for project-level and chapter-level revision requests.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole(['research_staff', 'admin']);

$user = getCurrentUser();

function srev_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function srev_table_columns(mysqli $conn, string $table): array {
    $allowed = ['research_projects', 'chapters', 'comments'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $columns = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[(string) $row['COLUMN_NAME']] = true;
    }
    $stmt->close();
    return $columns;
}

function srev_status_badge(string $status): array {
    $map = [
        'for_revision'      => ['status-returned', 'For Revision'],
        'revision_required' => ['status-required', 'Revision Required'],
    ];
    return $map[$status] ?? ['status-neutral', ucwords(str_replace('_', ' ', $status))];
}

function srev_date(?string $date): string {
    if (!$date) {
        return 'Date unavailable';
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? 'Date unavailable' : date('M d, Y h:i A', $timestamp);
}

$project_columns = srev_table_columns($conn, 'research_projects');
$chapter_columns = srev_table_columns($conn, 'chapters');
$comment_columns = srev_table_columns($conn, 'comments');

$project_deleted_filter = isset($project_columns['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';
$chapter_deleted_filter = isset($chapter_columns['deleted_at']) ? ' AND ch.deleted_at IS NULL' : '';
$comments_available = isset(
    $comment_columns['comment_id'],
    $comment_columns['chapter_id'],
    $comment_columns['comment'],
    $comment_columns['created_at']
);

$project_returns = [];
$project_stmt = $conn->prepare(
    "SELECT rp.project_id, rp.title, rp.status, rp.updated_at
       FROM research_projects rp
      WHERE rp.status IN ('for_revision', 'revision_required')"
      . $project_deleted_filter . '
      ORDER BY rp.updated_at DESC'
);
if ($project_stmt) {
    $project_stmt->execute();
    $project_result = $project_stmt->get_result();
    while ($row = $project_result->fetch_assoc()) {
        $project_returns[] = $row;
    }
    $project_stmt->close();
}

$chapter_returns = [];
if (!empty($chapter_columns)) {
    $comment_select = 'NULL AS latest_comment, NULL AS comment_created_at,
                       NULL AS reviewer_first_name, NULL AS reviewer_last_name';
    $comment_join = '';

    if ($comments_available) {
        $comment_deleted_filter = isset($comment_columns['deleted_at'])
            ? ' AND c2.deleted_at IS NULL'
            : '';
        $comment_select = 'lc.comment AS latest_comment, lc.created_at AS comment_created_at,
                           reviewer.first_name AS reviewer_first_name,
                           reviewer.last_name AS reviewer_last_name';
        $reviewer_join = isset($comment_columns['faculty_id'])
            ? ' LEFT JOIN users reviewer ON reviewer.user_id = lc.faculty_id'
            : '';
        $comment_join = "
            LEFT JOIN comments lc ON lc.comment_id = (
                SELECT c2.comment_id
                  FROM comments c2
                 WHERE c2.chapter_id = ch.chapter_id"
                 . $comment_deleted_filter . "
                 ORDER BY c2.created_at DESC, c2.comment_id DESC
                 LIMIT 1
            )" . $reviewer_join;
        if (!isset($comment_columns['faculty_id'])) {
            $comment_select = 'lc.comment AS latest_comment, lc.created_at AS comment_created_at,
                               NULL AS reviewer_first_name, NULL AS reviewer_last_name';
        }
    }

    $chapter_stmt = $conn->prepare(
        "SELECT ch.chapter_id, ch.project_id, ch.chapter_title, ch.chapter_number,
                ch.status, ch.updated_at, rp.title AS project_title,
                $comment_select
           FROM chapters ch
           JOIN research_projects rp ON rp.project_id = ch.project_id"
           . $comment_join . "
          WHERE ch.status = 'revision_required'"
           . $chapter_deleted_filter
           . $project_deleted_filter . '
          ORDER BY ch.updated_at DESC'
    );
    if ($chapter_stmt) {
        $chapter_stmt->execute();
        $chapter_result = $chapter_stmt->get_result();
        while ($row = $chapter_result->fetch_assoc()) {
            $chapter_returns[] = $row;
        }
        $chapter_stmt->close();
    }
}

renderStaffShell(
    $user,
    'staff-revisions.php',
    'Revision Returns',
    'Monitor projects and chapters that have been returned to proponents for revision.'
);
?>

<style>
  .revision-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .summary-card, .revision-card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
  }
  .summary-card { padding: 22px 24px; }
  .summary-number { font-size: 30px; line-height: 1; font-weight: 700; color: #111827; }
  .summary-label { margin-top: 7px; color: #64748B; font-size: 13px; }
  .revision-card { margin-bottom: 24px; overflow: hidden; }
  .revision-card-header {
    padding: 20px 24px;
    border-bottom: 1px solid #E5E7EB;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .revision-card-header h2 { margin: 0; color: #111827; font-size: 18px; }
  .revision-card-header p { margin: 5px 0 0; color: #64748B; font-size: 13px; }
  .revision-count {
    min-width: 32px;
    padding: 5px 10px;
    border-radius: 999px;
    background: #F3E8FF;
    color: #7E22CE;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
  }
  .table-wrap { overflow-x: auto; }
  .revision-table { width: 100%; border-collapse: collapse; }
  .revision-table th {
    padding: 12px 18px;
    background: #F8FAFC;
    color: #64748B;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-size: 11px;
    white-space: nowrap;
  }
  .revision-table td {
    padding: 16px 18px;
    border-top: 1px solid #F1F5F9;
    color: #334155;
    font-size: 13px;
    vertical-align: top;
  }
  .revision-table tbody tr:first-child td { border-top: 0; }
  .item-title { color: #111827; font-size: 14px; font-weight: 650; }
  .item-meta { margin-top: 4px; color: #64748B; font-size: 12px; }
  .status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
  }
  .status-returned { background: #FFEDD5; color: #C2410C; }
  .status-required { background: #FEE2E2; color: #B91C1C; }
  .status-neutral { background: #F1F5F9; color: #475569; }
  .comment-box {
    max-width: 460px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    color: #475569;
    line-height: 1.45;
  }
  .comment-meta { margin-top: 5px; color: #94A3B8; font-size: 11px; }
  .action-link {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 9px;
    background: #6D28D9;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 650;
    white-space: nowrap;
  }
  .action-link:hover { background: #5B21B6; }
  .empty-state { padding: 44px 24px; text-align: center; color: #64748B; }
  .empty-icon { margin-bottom: 10px; font-size: 38px; }
  .empty-state strong { display: block; margin-bottom: 5px; color: #334155; }
  @media (max-width: 720px) {
    .revision-summary { grid-template-columns: 1fr; }
  }
</style>

<div class="revision-summary">
  <div class="summary-card">
    <div class="summary-number"><?php echo count($project_returns); ?></div>
    <div class="summary-label">Project-level returns awaiting resubmission</div>
  </div>
  <div class="summary-card">
    <div class="summary-number"><?php echo count($chapter_returns); ?></div>
    <div class="summary-label">Chapter-level returns awaiting revision</div>
  </div>
</div>

<section class="revision-card">
  <div class="revision-card-header">
    <div>
      <h2>Project-level returns</h2>
      <p>Projects returned by the research office or review committees.</p>
    </div>
    <span class="revision-count"><?php echo count($project_returns); ?></span>
  </div>

  <?php if (empty($project_returns)): ?>
    <div class="empty-state">
      <div class="empty-icon">✅</div>
      <strong>No project revisions waiting</strong>
      All returned projects have been resubmitted or processed.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="revision-table">
        <thead>
          <tr><th>Research project</th><th>Status</th><th>Returned since</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($project_returns as $row):
            [$badge_class, $badge_label] = srev_status_badge((string) $row['status']);
        ?>
          <tr>
            <td>
              <div class="item-title"><?php echo srev_escape($row['title']); ?></div>
              <div class="item-meta">Project #<?php echo (int) $row['project_id']; ?></div>
            </td>
            <td><span class="status-badge <?php echo srev_escape($badge_class); ?>"><?php echo srev_escape($badge_label); ?></span></td>
            <td><?php echo srev_escape(srev_date($row['updated_at'] ?? null)); ?></td>
            <td>
              <a class="action-link" href="<?php echo SITE_URL; ?>pages/staff/staff-submissions.php?project_id=<?php echo (int) $row['project_id']; ?>">Open submissions</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="revision-card">
  <div class="revision-card-header">
    <div>
      <h2>Chapter-level returns</h2>
      <p>Submitted chapters awaiting changes requested by a faculty reviewer.</p>
    </div>
    <span class="revision-count"><?php echo count($chapter_returns); ?></span>
  </div>

  <?php if (empty($chapter_returns)): ?>
    <div class="empty-state">
      <div class="empty-icon">📘</div>
      <strong>No chapter revisions waiting</strong>
      There are no chapters currently marked revision required.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="revision-table">
        <thead>
          <tr><th>Chapter</th><th>Status</th><th>Returned since</th><th>Latest reviewer comment</th><th>Project</th></tr>
        </thead>
        <tbody>
        <?php foreach ($chapter_returns as $row):
            [$badge_class, $badge_label] = srev_status_badge((string) $row['status']);
            $reviewer_name = trim((string) ($row['reviewer_first_name'] ?? '') . ' ' . (string) ($row['reviewer_last_name'] ?? ''));
        ?>
          <tr>
            <td>
              <div class="item-title">Chapter <?php echo (int) $row['chapter_number']; ?> — <?php echo srev_escape($row['chapter_title']); ?></div>
              <div class="item-meta"><?php echo srev_escape($row['project_title']); ?></div>
            </td>
            <td><span class="status-badge <?php echo srev_escape($badge_class); ?>"><?php echo srev_escape($badge_label); ?></span></td>
            <td><?php echo srev_escape(srev_date($row['updated_at'] ?? null)); ?></td>
            <td>
              <?php if (!empty($row['latest_comment'])): ?>
                <div class="comment-box">
                  <?php echo nl2br(srev_escape($row['latest_comment'])); ?>
                  <div class="comment-meta">
                    <?php echo srev_escape($reviewer_name !== '' ? $reviewer_name : 'Faculty reviewer'); ?>
                    <?php if (!empty($row['comment_created_at'])): ?>
                      · <?php echo srev_escape(srev_date($row['comment_created_at'])); ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <span class="item-meta">No reviewer comment recorded.</span>
              <?php endif; ?>
            </td>
            <td>
              <a class="action-link" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $row['project_id']; ?>">View project</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php renderStaffShellClose(); ?>
