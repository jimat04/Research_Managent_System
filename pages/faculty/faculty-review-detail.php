<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('faculty');

$user = getCurrentUser();
$user_id = $user['user_id'];
$project_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['project_id']) ? intval($_POST['project_id']) : 0);
$errors = [];
$success = isset($_GET['action']) && $_GET['action'] === 'done' ? 'Action completed successfully.' : '';

$status_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'submitted' => ['class' => 'badge badge-info', 'style' => ''],
    'under_crec_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'under_erec_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'for_revision' => ['class' => 'badge badge-warning', 'style' => ''],
    'progress_report' => ['class' => 'badge badge-info', 'style' => ''],
    'terminal_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'approved' => ['class' => 'badge badge-success', 'style' => ''],
    'ongoing' => ['class' => 'badge badge-primary', 'style' => '']
];
$chapter_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'submitted' => ['class' => 'badge badge-info', 'style' => ''],
    'under_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'revision_required' => ['class' => 'badge badge-warning', 'style' => ''],
    'approved' => ['class' => 'badge badge-success', 'style' => '']
];
$chapter_titles = [
    1 => 'The Problem and Its Background',
    2 => 'Review of Related Literature',
    3 => 'Research Methodology',
    4 => 'Presentation, Analysis and Interpretation of Data',
    5 => 'Summary of Findings, Conclusions and Recommendations'
];

$project = null;
if ($project_id > 0) {
    $project_stmt = $conn->prepare("SELECT rp.*, rc.category_name, ay.label AS ay_label, ay.semester,
            CONCAT(owner.first_name, ' ', owner.last_name) AS student_name
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years ay ON rp.ay_id = ay.ay_id
        LEFT JOIN users owner ON rp.created_by = owner.user_id
        WHERE rp.project_id = ? AND rp.deleted_at IS NULL
        AND (EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?)
            OR rp.status IN ('submitted', 'under_crec_review', 'under_erec_review', 'for_revision', 'progress_report', 'terminal_review'))");
    if ($project_stmt) {
        $project_stmt->bind_param('ii', $project_id, $user_id);
        $project_stmt->execute();
        $project = $project_stmt->get_result()->fetch_assoc() ?: null;
        $project_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    $action = $_POST['action'] ?? '';
    $new_status = null;
    $log_message = '';
    $comment_text = '';
    $comment_type = 'general';
    $chapter_id = null;

    if ($action === 'project_approve') {
        $next_status = ['submitted' => 'under_crec_review', 'under_crec_review' => 'under_erec_review', 'under_erec_review' => 'approved'];
        if (isset($next_status[$project['status']])) {
            $new_status = $next_status[$project['status']];
            $log_message = 'Approved ' . $project['title'];
        } else {
            $errors[] = 'This project cannot be approved from its current status.';
        }
    } elseif ($action === 'project_request_revision') {
        $comment_text = trim($_POST['reason'] ?? '');
        if ($comment_text === '') $errors[] = 'A revision reason is required.';
        else { $new_status = 'for_revision'; $comment_type = 'correction'; $log_message = 'Requested revision on ' . $project['title']; }
    } elseif ($action === 'project_ongoing') {
        if ($project['status'] === 'approved') { $new_status = 'ongoing'; $log_message = 'Marked ' . $project['title'] . ' as ongoing'; }
        else $errors[] = 'Only approved projects can be marked as ongoing.';
    } elseif ($action === 'chapter_approve' || $action === 'chapter_revise') {
        $chapter_id = intval($_POST['chapter_id'] ?? 0);
        $comment_text = trim($_POST['chapter_comment'] ?? '');
        if ($chapter_id < 1) $errors[] = 'Invalid chapter.';
        elseif ($action === 'chapter_revise' && $comment_text === '') $errors[] = 'A revision comment is required.';
        else {
            $chapter_check = $conn->prepare('SELECT chapter_number FROM chapters WHERE chapter_id = ? AND project_id = ? AND deleted_at IS NULL');
            $chapter_check->bind_param('ii', $chapter_id, $project_id);
            $chapter_check->execute();
            $chapter_row = $chapter_check->get_result()->fetch_assoc();
            $chapter_check->close();
            if (!$chapter_row) $errors[] = 'Chapter not found.';
            else { $comment_type = $action === 'chapter_approve' ? 'approval' : 'correction'; $log_message = ($action === 'chapter_approve' ? 'Approved Chapter ' : 'Requested revision on Chapter ') . $chapter_row['chapter_number'] . ' of ' . $project['title']; }
        }
    } elseif ($action === 'new_comment') {
        $comment_text = trim($_POST['comment'] ?? '');
        $chapter_id = intval($_POST['comment_chapter_id'] ?? 0) ?: null;
        if ($comment_text === '') $errors[] = 'Comment text is required.';
        else $log_message = 'Posted feedback on ' . $project['title'];
    }

    if (empty($errors) && $action !== '') {
        $conn->begin_transaction();
        try {
            if ($new_status !== null) {
                $status_stmt = $conn->prepare('UPDATE research_projects SET status = ?, updated_at = NOW() WHERE project_id = ?');
                if (!$status_stmt) throw new Exception('Unable to prepare project update.');
                $status_stmt->bind_param('si', $new_status, $project_id);
                if (!$status_stmt->execute()) throw new Exception('Unable to update project status.');
                $status_stmt->close();
            }
            if ($action === 'chapter_approve' || $action === 'chapter_revise') {
                $chapter_status = $action === 'chapter_approve' ? 'approved' : 'revision_required';
                if ($action === 'chapter_approve') {
                    $chapter_update = $conn->prepare('UPDATE chapters SET status = ?, approved_at = NOW(), approved_by = ?, updated_at = NOW() WHERE chapter_id = ? AND project_id = ?');
                  if (!$chapter_update) throw new Exception('Unable to prepare chapter update.');
                    $chapter_update->bind_param('siii', $chapter_status, $user_id, $chapter_id, $project_id);
                } else {
                    $chapter_update = $conn->prepare('UPDATE chapters SET status = ?, approved_at = NULL, approved_by = NULL, updated_at = NOW() WHERE chapter_id = ? AND project_id = ?');
                  if (!$chapter_update) throw new Exception('Unable to prepare chapter update.');
                    $chapter_update->bind_param('sii', $chapter_status, $chapter_id, $project_id);
                }
                if (!$chapter_update->execute()) throw new Exception('Unable to update chapter status.');
                $chapter_update->close();
            }
            if ($comment_text !== '') {
                if ($chapter_id === null) {
                  // @rms-db: comments needs a nullable project_id to associate General feedback safely.
                  $comment_stmt = $conn->prepare('INSERT INTO comments (chapter_id, faculty_id, comment, type) VALUES (NULL, ?, ?, ?)');
                  if (!$comment_stmt) throw new Exception('Unable to prepare comment insert.');
                  $comment_stmt->bind_param('iss', $user_id, $comment_text, $comment_type);
                } else {
                  $comment_stmt = $conn->prepare('INSERT INTO comments (chapter_id, faculty_id, comment, type) VALUES (?, ?, ?, ?)');
                  if (!$comment_stmt) throw new Exception('Unable to prepare comment insert.');
                  $comment_stmt->bind_param('iiss', $chapter_id, $user_id, $comment_text, $comment_type);
                }
                if (!$comment_stmt->execute()) throw new Exception('Unable to save comment.');
                $comment_stmt->close();
            }
            logActivity($log_message, 'faculty-review');
            $conn->commit();
            header('Location: faculty-review-detail.php?id=' . $project_id . '&action=done');
            exit();
        } catch (Exception $exception) {
            $conn->rollback();
            $errors[] = 'Unable to complete this action. Please verify the database migration is applied.';
        }
    }
}

$chapters = [];
$uploads_by_chapter = [];
$comments = [];
$members = [];
$proposal = null;
if ($project) {
    $chapter_stmt = $conn->prepare('SELECT c.*, u.first_name AS approver_first, u.last_name AS approver_last FROM chapters c LEFT JOIN users u ON c.approved_by = u.user_id WHERE c.project_id = ? AND c.deleted_at IS NULL ORDER BY c.chapter_number');
    $chapter_stmt->bind_param('i', $project_id);
    $chapter_stmt->execute();
    $chapter_result = $chapter_stmt->get_result();
    while ($row = $chapter_result->fetch_assoc()) $chapters[$row['chapter_number']] = $row;
    $chapter_stmt->close();

    $upload_stmt = $conn->prepare("SELECT * FROM uploads WHERE project_id = ? AND type = 'chapter' ORDER BY upload_id DESC");
    $upload_stmt->bind_param('i', $project_id);
    $upload_stmt->execute();
    $upload_result = $upload_stmt->get_result();
    while ($row = $upload_result->fetch_assoc()) if (!isset($uploads_by_chapter[$row['chapter_id']])) $uploads_by_chapter[$row['chapter_id']] = $row;
    $upload_stmt->close();

    $proposal_stmt = $conn->prepare("SELECT * FROM uploads WHERE project_id = ? AND type = 'proposal' ORDER BY upload_id DESC LIMIT 1");
    $proposal_stmt->bind_param('i', $project_id);
    $proposal_stmt->execute();
    $proposal = $proposal_stmt->get_result()->fetch_assoc() ?: null;
    $proposal_stmt->close();

    // @rms-db: project-level comments require comments.project_id; general comments are not displayed without that relationship.
    $comment_stmt = $conn->prepare('SELECT c.*, ch.chapter_number, u.first_name, u.last_name FROM comments c INNER JOIN chapters ch ON c.chapter_id = ch.chapter_id LEFT JOIN users u ON c.faculty_id = u.user_id WHERE ch.project_id = ? ORDER BY c.created_at DESC');
    $comment_stmt->bind_param('i', $project_id);
    $comment_stmt->execute();
    $comment_result = $comment_stmt->get_result();
    while ($row = $comment_result->fetch_assoc()) $comments[] = $row;
    $comment_stmt->close();

    $member_stmt = $conn->prepare('SELECT pm.role, u.first_name, u.last_name FROM project_members pm LEFT JOIN users u ON pm.user_id = u.user_id WHERE pm.project_id = ? ORDER BY pm.role DESC, u.first_name');
    $member_stmt->bind_param('i', $project_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    while ($row = $member_result->fetch_assoc()) $members[] = $row;
    $member_stmt->close();
}
$project_status = $project['status'] ?? '';
$project_badge = $status_badges[$project_status] ?? $status_badges['draft'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $project ? htmlspecialchars($project['title']) . ' — Review' : 'Review Project'; ?> — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<div class="dashboard">
  <aside class="sidebar">
    <div class="sidebar-header"><div class="sidebar-logo" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); border-radius: 8px;">🔬</div><div class="sidebar-brand">Research<br>Management</div></div>
    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item" onclick="location.href='faculty-dashboard.php'"><span class="icon">📊</span><span>Dashboard</span></div>
      <div class="nav-item" onclick="location.href='faculty-submissions.php'"><span class="icon">📥</span><span>Submissions</span></div>
      <div class="nav-item active" onclick="location.href='faculty-review.php'"><span class="icon">🔍</span><span>Review Queue</span></div>
      <div class="nav-item" onclick="location.href='faculty-students.php'"><span class="icon">👨‍🎓</span><span>My Students</span></div>
      <div class="nav-group-title">COMMUNICATION</div>
      <div class="nav-item" onclick="location.href='../shared/messages.php'"><span class="icon">💬</span><span>Messages</span></div>
      <div class="nav-item" onclick="location.href='notifications.php'"><span class="icon">🔔</span><span>Notifications</span></div>
      <div class="nav-group-title">RESOURCES</div>
      <div class="nav-item" onclick="location.href='research-archive.php'"><span class="icon">🗂️</span><span>Research Archive</span></div>
      <div class="nav-item" onclick="location.href='faculty-reports.php'"><span class="icon">📊</span><span>Reports</span></div>
      <div class="nav-group-title">ACCOUNT</div>
      <div class="nav-item" onclick="location.href='profile.php'"><span class="icon">👤</span><span>Profile</span></div>
      <div class="nav-item" onclick="location.href='../../public/logout.php'" style="color: #ef4444;"><span class="icon">🚪</span><span>Logout</span></div>
    </nav>
    <div class="sidebar-footer"><div class="user-card"><div class="user-avatar" style="background: linear-gradient(135deg, var(--secondary), var(--accent));"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div><div class="user-info"><div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div><div class="user-role">👨‍🏫 Faculty</div></div></div></div>
  </aside>
  <div class="main-content">
    <header class="topbar"><div class="topbar-left"><h2>Review Project</h2><p><?php echo $project ? htmlspecialchars($project['title']) : 'Project details'; ?></p></div><div class="topbar-right"><div class="search-box"><span style="color: #94a3b8;">🔍</span><input type="text" placeholder="Search submissions..."></div><div class="topbar-icons"><div class="icon-btn">🔔</div></div><div class="user-profile-btn"><div class="profile-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div><div class="profile-text"><div class="profile-name"><?php echo htmlspecialchars($user['first_name']); ?></div><div class="profile-role">Faculty</div></div></div></div></header>
    <div class="page-content">
      <div style="margin-bottom: 20px;"><a href="faculty-dashboard.php" style="color: var(--primary); text-decoration: none; font-size: 14px;">← Dashboard</a><?php if ($project): ?> <span style="color: var(--text-light);">/</span> <span><?php echo htmlspecialchars($project['title']); ?></span><?php endif; ?> <!-- @rms-ui: breadcrumb separator style --></div>
      <?php if (!$project): ?>
        <div class="card" style="text-align: center; padding: 60px 40px;"><h3 style="margin: 0 0 8px;">Project not found or you don't have access</h3><p style="margin: 0 0 24px; color: var(--text-light);">The research project you're looking for doesn't exist or you don't have permission to view it.</p><a href="faculty-dashboard.php" class="btn btn-primary">Go back to Dashboard</a></div>
      <?php else: ?>
        <?php if ($success): ?><div class="alert alert-success"><strong>Success!</strong> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul style="margin: 0; padding-left: 20px;"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="card" style="margin-bottom: 20px;"><div class="card-body"><div style="display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; flex-wrap: wrap;"><div style="flex: 1; min-width: 240px;"><h2 style="margin: 0 0 10px;"><?php echo htmlspecialchars($project['title']); ?></h2><div style="color: var(--text-light); font-size: 14px;">Student: <?php echo htmlspecialchars($project['student_name'] ?: 'N/A'); ?> · <?php echo htmlspecialchars($project['category_name'] ?? 'Uncategorized'); ?> · <?php echo htmlspecialchars(($project['ay_label'] ?? 'N/A') . ' / ' . ($project['semester'] ?? 'N/A')); ?> · Submitted: <?php echo !empty($project['created_at']) ? date('M d, Y', strtotime($project['created_at'])) : 'N/A'; ?></div></div><span class="<?php echo htmlspecialchars($project_badge['class']); ?>" <?php echo $project_badge['style'] ? 'style="' . htmlspecialchars($project_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $project_status)); ?></span></div>
          <?php if ($project_status === 'for_revision'): ?><div class="alert alert-warning" style="margin: 18px 0 0;">This project has been returned for revision.</div><?php elseif (in_array($project_status, ['submitted', 'under_crec_review', 'under_erec_review'], true)): ?><div class="alert alert-info" style="margin: 18px 0 0;">Awaiting review.</div><?php endif; ?>
          <?php if (!empty($project['abstract'])): ?><details style="margin-top: 18px;"><summary style="cursor: pointer; color: var(--primary);">Show abstract</summary><div style="white-space: pre-wrap; line-height: 1.6; margin-top: 10px;"><?php echo htmlspecialchars($project['abstract'], ENT_QUOTES, 'UTF-8'); ?></div></details><?php endif; ?>
          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 20px;"><button type="button" class="btn btn-warning" onclick="document.getElementById('revisionModal').style.display='block'">Request Revision</button><?php if (in_array($project_status, ['submitted', 'under_crec_review', 'under_erec_review'], true)): ?><form method="post"><?php echo csrfField(); ?><input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="action" value="project_approve"><button class="btn btn-success">Approve</button></form><?php endif; ?><?php if ($project_status === 'approved'): ?><form method="post"><?php echo csrfField(); ?><input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="action" value="project_ongoing"><button class="btn btn-secondary">Mark as Ongoing</button></form><?php endif; ?><?php if ($proposal): ?><a class="btn btn-secondary" href="../../uploads/proposals/<?php echo rawurlencode($proposal['file_name']); ?>" target="_blank" rel="noopener">Download Proposal</a><?php else: ?><button class="btn btn-secondary" disabled>Download Proposal</button><?php endif; ?></div>
        </div></div>
        <div id="revisionModal" class="card" style="display: none; margin-bottom: 20px;"><div class="card-header"><div class="card-title">Request Revision</div></div><div class="card-body"><form method="post"><?php echo csrfField(); ?><input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="action" value="project_request_revision"><textarea name="reason" class="form-control" rows="4" required placeholder="Explain what needs to be revised..."></textarea><div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px;"><button type="button" class="btn btn-secondary" onclick="document.getElementById('revisionModal').style.display='none'">Cancel</button><button class="btn btn-warning">Request Revision</button></div></form></div></div><!-- @rms-ui: modal styling --></div>
        <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapters</div></div><div class="card-body"><div style="display: flex; flex-direction: column; gap: 12px;">
          <?php for ($number = 1; $number <= 5; $number++): $item = $chapters[$number] ?? null; $chapter_status = $item['status'] ?? 'draft'; $chapter_badge = $chapter_badges[$chapter_status] ?? $chapter_badges['draft']; ?>
            <div id="chapter-<?php echo $number; ?>" style="padding: 14px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;"><div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap;"><div><strong>Ch. <?php echo $number; ?> — <?php echo htmlspecialchars($chapter_titles[$number]); ?></strong><div style="font-size: 13px; color: var(--text-light); margin-top: 5px;"><span class="<?php echo htmlspecialchars($chapter_badge['class']); ?>" <?php echo $chapter_badge['style'] ? 'style="' . htmlspecialchars($chapter_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $chapter_status)); ?></span><?php if ($item && !empty($item['submitted_at'])): ?> · Submitted: <?php echo date('M d, Y', strtotime($item['submitted_at'])); ?><?php endif; ?><?php if ($item && !empty($item['approved_at'])): ?> · Approved by <?php echo htmlspecialchars(($item['approver_first'] ?? '') . ' ' . ($item['approver_last'] ?? '')); ?> on <?php echo date('M d, Y', strtotime($item['approved_at'])); ?><?php endif; ?></div></div><div style="display: flex; gap: 6px; flex-wrap: wrap;"><?php if ($item && isset($uploads_by_chapter[$item['chapter_id']])): $chapter_upload = $uploads_by_chapter[$item['chapter_id']]; ?><a href="<?php echo htmlspecialchars($chapter_upload['file_path']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📄 View File</a><?php endif; ?><?php if ($item && in_array($chapter_status, ['submitted', 'revision_required'], true)): ?><a href="#review-<?php echo $item['chapter_id']; ?>" class="btn btn-sm btn-primary">Review</a><?php endif; ?></div></div>
              <?php if ($item && in_array($chapter_status, ['submitted', 'revision_required'], true)): ?><div id="review-<?php echo $item['chapter_id']; ?>" style="display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap; margin-top: 12px;"><form method="post" style="display: flex; gap: 8px; flex: 1; flex-wrap: wrap;"><?php echo csrfField(); ?><input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="chapter_id" value="<?php echo $item['chapter_id']; ?>"><textarea name="chapter_comment" class="form-control" rows="2" placeholder="Optional approval feedback or required revision comment..."></textarea><div style="display: flex; gap: 6px; align-items: flex-start;"><button name="action" value="chapter_approve" class="btn btn-sm btn-success">Approve Chapter</button><button name="action" value="chapter_revise" class="btn btn-sm btn-warning">Revise</button></div></form></div><!-- @rms-ui: per-chapter action row styling --><?php endif; ?>
            </div>
          <?php endfor; ?></div></div></div>
        <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Feedback History</div></div><div class="card-body"><?php if ($comments): ?><div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px;"><?php foreach ($comments as $comment): ?><div style="border-bottom: 1px solid var(--border); padding-bottom: 12px;"><div style="display: flex; gap: 10px; align-items: center;"><div class="profile-avatar"><?php echo strtoupper(substr($comment['first_name'] ?? '?', 0, 1) . substr($comment['last_name'] ?? '', 0, 1)); ?></div><strong><?php echo htmlspecialchars(($comment['first_name'] ?? 'Faculty') . ' ' . ($comment['last_name'] ?? '')); ?></strong><?php $comment_type_display = $comment['type'] ?? 'general'; ?><span class="badge"><?php echo htmlspecialchars(ucfirst($comment_type_display)); ?></span><span style="color: var(--text-light); font-size: 13px;">Re: <?php echo $comment['chapter_number'] ? 'Chapter ' . (int) $comment['chapter_number'] : 'General'; ?></span></div><div style="margin: 8px 0 0 42px; white-space: pre-wrap;"><?php echo htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8'); ?></div><div class="time" style="margin-left: 42px;"><?php echo date('M d, Y h:i A', strtotime($comment['created_at'])); ?></div></div><?php endforeach; ?></div><?php else: ?><p style="color: var(--text-light);">No feedback has been posted yet.</p><?php endif; ?><form method="post"><?php echo csrfField(); ?><input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="action" value="new_comment"><select name="comment_chapter_id" class="form-control" style="max-width: 240px; margin-bottom: 8px;"><option value="0">General</option><?php foreach ($chapters as $chapter_item): ?><option value="<?php echo $chapter_item['chapter_id']; ?>">Chapter <?php echo $chapter_item['chapter_number']; ?></option><?php endforeach; ?></select><textarea name="comment" class="form-control" rows="3" required placeholder="Write general feedback..."></textarea><div style="display: flex; justify-content: flex-end; margin-top: 8px;"><button class="btn btn-primary">Post Comment</button></div></form></div></div>
        <div class="card"><div class="card-header"><div class="card-title">Research Team</div></div><div class="card-body"><?php if ($members): ?><div style="display: flex; flex-direction: column; gap: 10px;"><?php foreach ($members as $member): ?><div style="display: flex; justify-content: space-between; padding: 12px; border: 1px solid var(--border); border-radius: 6px;"><span><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></span><span class="badge <?php echo $member['role'] === 'lead' ? 'badge-primary' : 'badge-info'; ?>"><?php echo htmlspecialchars(ucfirst($member['role'])); ?></span></div><?php endforeach; ?></div><?php else: ?><p style="color: var(--text-light);">No team members found.</p><?php endif; ?></div></div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
