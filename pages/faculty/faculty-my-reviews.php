<?php
/**
 * Faculty — My CREC/EREC Reviews
 *
 * Lists the CREC/EREC review assignments (project_reviews) created by
 * Research Staff on the Staff CREC Review page, and links each one to the
 * OVPREIS Form No. 3 scoring form (faculty-score-review.php).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireRole('faculty');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

function myrev_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$project_reviews_exists = false;
$tbl_stmt = $conn->prepare("SHOW TABLES LIKE 'project_reviews'");
if ($tbl_stmt) {
    $tbl_stmt->execute();
    $tbl_stmt->bind_result($tbl);
    while ($tbl_stmt->fetch()) { $project_reviews_exists = ($tbl === 'project_reviews'); }
    $tbl_stmt->close();
}

$reviews = [];
if ($project_reviews_exists) {
    // Migration 007 completes the six-criterion /100 form. Retain the legacy
    // four-criterion /80 display until both new columns are present.
    $form3_columns = ['capability_score' => false, 'thrusts_score' => false];
    foreach (array_keys($form3_columns) as $column) {
        $column_result = $conn->query("SHOW COLUMNS FROM project_reviews LIKE '" . $column . "'");
        if ($column_result) {
            $form3_columns[$column] = $column_result->num_rows > 0;
            $column_result->close();
        }
    }
    $form3_full = $form3_columns['capability_score'] && $form3_columns['thrusts_score'];
    $form3_extra_select = $form3_full ? ', pr.capability_score, pr.thrusts_score' : '';

    $stmt = $conn->prepare("
        SELECT pr.review_id, pr.review_level,
               pr.methodology_score, pr.contribution_score, pr.applicability_score, pr.agenda_score" . $form3_extra_select . ",
               pr.recommendation, pr.reviewed_at, pr.created_at AS assigned_at,
               rp.project_id, rp.title, rp.status,
               CONCAT(s.first_name, ' ', s.last_name) AS student_name
          FROM project_reviews pr
          JOIN research_projects rp ON rp.project_id = pr.project_id
          LEFT JOIN users s ON s.user_id = rp.created_by
         WHERE pr.reviewer_id = ?
         ORDER BY (pr.reviewed_at IS NULL) DESC, pr.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $reviews[] = $row; }
        $stmt->close();
    }
}

$pending_count = 0;
foreach ($reviews as $r) { if (empty($r['reviewed_at'])) { $pending_count++; } }
$saved = isset($_GET['saved']);

renderFacultyShell($user, 'faculty-my-reviews', 'My CREC/EREC Reviews', 'Proposals assigned to you for OVPREIS Form No. 3 evaluation.');
?>

<?php if ($saved): ?>
  <div class="alert alert-success myrev-alert">Review submitted successfully. Research Staff has been notified.</div>
<?php endif; ?>

<section class="myrev-summary" aria-label="Evaluation summary">
  <div class="myrev-summary-copy">
    <span class="myrev-kicker">Institutional evaluation</span>
    <h2><?php echo $pending_count > 0 ? 'Continue your assigned evaluations.' : 'Your evaluation queue is clear.'; ?></h2>
    <p>Score CREC and EREC proposals using OVPREIS Form No. 3. Completed evaluations remain available for review and editing.</p>
  </div>
  <div class="myrev-metrics">
    <div><strong><?php echo $pending_count; ?></strong><span>Pending</span></div>
    <div><strong><?php echo count($reviews) - $pending_count; ?></strong><span>Completed</span></div>
  </div>
</section>

<div class="card myrev-card">
  <div class="card-header">
    <div>
      <div class="card-title">Assigned Reviews</div>
      <div class="card-subtitle"><?php echo $pending_count; ?> pending &middot; <?php echo count($reviews) - $pending_count; ?> completed</div>
    </div>
  </div>
  <?php if (!$project_reviews_exists): ?>
    <p>The review system is not available yet. Ask Research Staff to open the Staff CREC Review page once (it creates the project_reviews table), or run migration 006_create_project_reviews.sql.</p>
  <?php elseif (!$reviews): ?>
    <p>No CREC/EREC proposals are assigned to you yet. When Research Staff assigns you on the CREC Review page, the proposal appears here and you get a notification.</p>
  <?php else: ?>
  <div class="table-responsive myrev-table-wrap">
    <table class="myrev-table table-mobile-stack">
      <thead>
        <tr>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Research</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Student</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Level</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Project Status</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Score</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Recommendation</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Assigned</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E5E7EB;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($reviews as $r):
          $total = null;
          $max_score = !empty($form3_full) ? 100 : 80;
          if (!empty($r['reviewed_at'])) {
              $total = (int) $r['methodology_score'] + (int) $r['contribution_score'] + (int) $r['applicability_score'] + (int) $r['agenda_score'];
              if (!empty($form3_full)) {
                  $total += (int) $r['capability_score'] + (int) $r['thrusts_score'];
              }
          }
      ?>
        <tr>
          <td data-label="Research" style="padding:10px;border-bottom:1px solid #E5E7EB;font-weight:500;"><?php echo myrev_se($r['title']); ?></td>
          <td data-label="Student" style="padding:10px;border-bottom:1px solid #E5E7EB;"><?php echo myrev_se($r['student_name'] ?? '—'); ?></td>
          <td data-label="Level" style="padding:10px;border-bottom:1px solid #E5E7EB;"><?php echo strtoupper(myrev_se($r['review_level'])); ?></td>
          <td data-label="Project status" style="padding:10px;border-bottom:1px solid #E5E7EB;"><span class="myrev-status myrev-status--<?php echo myrev_se(str_replace('_', '-', strtolower((string) $r['status']))); ?>"><?php echo myrev_se(ucwords(str_replace('_', ' ', (string) $r['status']))); ?></span></td>
          <td data-label="Score" style="padding:10px;border-bottom:1px solid #E5E7EB;"><?php echo $total === null ? '<em style="color:#64748B;">pending</em>' : '<strong>' . $total . '/' . $max_score . '</strong>'; ?></td>
          <td data-label="Recommendation" style="padding:10px;border-bottom:1px solid #E5E7EB;"><?php echo $r['recommendation'] ? '<span class="myrev-recommendation myrev-recommendation--' . myrev_se(strtolower((string) $r['recommendation'])) . '">' . ucfirst(myrev_se($r['recommendation'])) . '</span>' : '—'; ?></td>
          <td data-label="Assigned" style="padding:10px;border-bottom:1px solid #E5E7EB;"><?php echo date('M d, Y', strtotime($r['assigned_at'])); ?></td>
          <td data-label="Action" style="padding:10px;border-bottom:1px solid #E5E7EB;"><a class="btn btn-primary btn-sm" href="faculty-score-review.php?id=<?php echo (int) $r['review_id']; ?>"><?php echo $total === null ? 'Score' : 'Edit'; ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php renderFacultyShellClose(); ?>
