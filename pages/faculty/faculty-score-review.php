<?php
/**
 * Faculty - Score CREC/EREC Review (OVPREIS Form No. 3)
 *
 * The assigned faculty member enters the OVPREIS Form No. 3 criterion
 * scores plus comments and a recommendation. Completed rows feed the
 * endorsement gate on the Staff CREC Review page
 * (>= 2 completed reviews AND average score >= 62.5% of the available total).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

$review_id = (int) ($_GET['id'] ?? $_POST['review_id'] ?? 0);
$errors    = [];

function scorerev_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Migration 007 completes the six-criterion /100 form. Retain the legacy
// four-criterion /80 form until both new columns are present.
$form3_columns = ['capability_score' => false, 'thrusts_score' => false];
foreach (array_keys($form3_columns) as $column) {
    // Column names come only from the fixed whitelist above. MariaDB does
    // not reliably support placeholders in SHOW COLUMNS ... LIKE.
    $column_result = $conn->query("SHOW COLUMNS FROM project_reviews LIKE '" . $column . "'");
    if ($column_result) {
        $form3_columns[$column] = $column_result->num_rows > 0;
        $column_result->close();
    }
}
$form3_full = $form3_columns['capability_score'] && $form3_columns['thrusts_score'];

// OVPREIS Form No. 3 criteria: [column, exact manual label, max score].
$CRITERIA = [
    ['contribution_score',  'Contribution to the body of knowledge/practice',               20],
    ['methodology_score',   'Soundness of research proposal/design',                        20],
    ['applicability_score', 'Applicability/Marketability of the research output',            30],
];
if ($form3_full) {
    $CRITERIA[] = ['capability_score', 'Capability of proponent to carry out research project', 10];
}
$CRITERIA[] = ['agenda_score', 'Aligned with the EARIST and College Research Agenda', 10];
if ($form3_full) {
    $CRITERIA[] = ['thrusts_score', 'Conformity to national research thrusts (DOST/CHED)', 10];
}
$form3_max_score = array_sum(array_column($CRITERIA, 2));

$review = null;
if ($review_id > 0) {
    $stmt = $conn->prepare("
        SELECT pr.*, rp.title, rp.status AS project_status, rp.created_by AS student_id,
               CONCAT(s.first_name, ' ', s.last_name) AS student_name
          FROM project_reviews pr
          JOIN research_projects rp ON rp.project_id = pr.project_id
          LEFT JOIN users s ON s.user_id = rp.created_by
         WHERE pr.review_id = ? AND pr.reviewer_id = ?
         LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $review_id, $user_id);
        $stmt->execute();
        $review = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if (!$review) {
        $errors[] = 'Review assignment not found, or it is not assigned to your account.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $review) {
    $scores         = [];
    $total          = 0;
    $recommendation = trim($_POST['recommendation'] ?? '');
    $comments       = trim($_POST['comments'] ?? '');

    foreach ($CRITERIA as [$column, $label, $max]) {
        $raw   = $_POST[$column] ?? '';
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > $max) {
            $errors[] = "$label must be a whole number between 0 and $max.";
        } else {
            $scores[$column] = $value;
            $total += $value;
        }
    }

    if (!in_array($recommendation, ['approve', 'revise', 'reject'], true)) {
        $errors[] = 'Please choose a recommendation (approve, revise, or reject).';
    }
    if ($recommendation !== 'approve' && $comments === '') {
        $errors[] = 'Comments are required when recommending revision or rejection, so the student knows what to fix.';
    }

    if (!$errors) {
        if ($form3_full) {
            $upd = $conn->prepare("
                UPDATE project_reviews
                   SET contribution_score = ?, methodology_score = ?, applicability_score = ?,
                       capability_score = ?, agenda_score = ?, thrusts_score = ?,
                       comments = ?, recommendation = ?, reviewed_at = NOW()
                 WHERE review_id = ? AND reviewer_id = ?
            ");
            $upd->bind_param(
                'iiiiiissii',
                $scores['contribution_score'], $scores['methodology_score'],
                $scores['applicability_score'], $scores['capability_score'],
                $scores['agenda_score'], $scores['thrusts_score'],
                $comments, $recommendation, $review_id, $user_id
            );
        } else {
            $upd = $conn->prepare("
                UPDATE project_reviews
                   SET contribution_score = ?, methodology_score = ?, applicability_score = ?, agenda_score = ?,
                       comments = ?, recommendation = ?, reviewed_at = NOW()
                 WHERE review_id = ? AND reviewer_id = ?
            ");
            $upd->bind_param(
                'iiiissii',
                $scores['contribution_score'], $scores['methodology_score'],
                $scores['applicability_score'], $scores['agenda_score'],
                $comments, $recommendation, $review_id, $user_id
            );
        }
        if ($upd->execute()) {
            $level = strtoupper((string) $review['review_level']);
            $title = (string) ($review['title'] ?? 'your research');
            logActivity("Submitted $level review (score $total/$form3_max_score, $recommendation) for project #{$review['project_id']}", 'faculty_review');

            if (!empty($review['student_id'])) {
                createNotification(
                    (int) $review['student_id'],
                    "$level review completed",
                    "A $level reviewer finished evaluating \"$title\".",
                    'info',
                    'pages/student/my-research.php'
                );
            }
            $staff_result = $conn->query("SELECT user_id FROM users WHERE role = 'research_staff' AND status = 'active'");
            if ($staff_result) {
                while ($staff = $staff_result->fetch_assoc()) {
                    createNotification(
                        (int) $staff['user_id'],
                        "$level review submitted",
                        "{$user['first_name']} {$user['last_name']} scored \"$title\" $total/$form3_max_score and recommended: $recommendation.",
                        'info',
                        'pages/staff/staff-crec.php'
                    );
                }
            }
            header('Location: faculty-my-reviews.php?saved=1');
            exit;
        }
        $errors[] = 'The review could not be saved. Please try again.';
    }
}

renderFacultyShell($user, 'faculty-score-review', 'Score Proposal', $review ? (string) $review['title'] : '');
?>

<?php if (!$review && !$errors): ?>
  <div class="card"><p>Missing review reference. Open <a href="faculty-my-reviews.php">My CREC/EREC Reviews</a> and choose a proposal to score.</p></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="margin-bottom:16px;">
    <ul style="margin:0;padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?php echo scorerev_se($e); ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($review): ?>
<div class="card">
  <div class="card-header">
    <div>
      <div class="card-title"><?php echo scorerev_se($review['title']); ?></div>
      <div class="card-subtitle">
        Student: <?php echo scorerev_se($review['student_name'] ?? 'Unknown'); ?> &middot;
        Level: <?php echo strtoupper(scorerev_se($review['review_level'])); ?> &middot;
        Status: <?php echo scorerev_se(ucwords(str_replace('_', ' ', (string) $review['project_status']))); ?>
      </div>
    </div>
    <a class="btn btn-primary btn-sm" href="faculty-review-detail.php?id=<?php echo (int) $review['project_id']; ?>">Open Full Submission</a>
  </div>

  <form method="post">
    <?php echo csrfField(); ?>
    <input type="hidden" name="review_id" value="<?php echo (int) $review_id; ?>">

    <h3 style="margin:8px 0 4px;">OVPREIS Form No. 3 - Criteria</h3>
    <p style="color:#64748B;margin:0 0 16px;">Maximum total: <?php echo (int) $form3_max_score; ?> points. Enter a whole-number score per criterion.</p>

    <?php foreach ($CRITERIA as [$column, $label, $max]):
        $current = $_POST[$column] ?? $review[$column] ?? '';
    ?>
      <label style="display:block;margin-bottom:14px;">
        <?php echo scorerev_se($label); ?> <span style="color:#64748B;">(0-<?php echo $max; ?>)</span><br>
        <input class="form-control" type="number" name="<?php echo $column; ?>" min="0" max="<?php echo $max; ?>" step="1" required
               value="<?php echo scorerev_se($current); ?>" style="max-width:140px;">
      </label>
    <?php endforeach; ?>

    <label style="display:block;margin-bottom:14px;">
      Recommendation<br>
      <select class="form-control" name="recommendation" required style="max-width:260px;">
        <?php $rec_current = $_POST['recommendation'] ?? $review['recommendation'] ?? ''; ?>
        <option value="">Select recommendation</option>
        <option value="approve" <?php echo $rec_current === 'approve' ? 'selected' : ''; ?>>Approve</option>
        <option value="revise"  <?php echo $rec_current === 'revise'  ? 'selected' : ''; ?>>Revise</option>
        <option value="reject"  <?php echo $rec_current === 'reject'  ? 'selected' : ''; ?>>Reject</option>
      </select>
    </label>

    <label style="display:block;margin-bottom:14px;">
      Comments <span style="color:#64748B;">(required for revise/reject, visible in feedback)</span><br>
      <textarea class="form-control" name="comments" rows="5"><?php echo scorerev_se($_POST['comments'] ?? $review['comments'] ?? ''); ?></textarea>
    </label>

    <button class="btn btn-primary"><?php echo empty($review['reviewed_at']) ? 'Submit Review' : 'Update Review'; ?></button>
    <a class="btn btn-secondary" href="faculty-my-reviews.php" style="margin-left:8px;">Cancel</a>
  </form>
</div>
<?php endif; ?>

<?php renderFacultyShellClose(); ?>
