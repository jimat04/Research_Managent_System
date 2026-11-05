<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';
require_once __DIR__ . '/../../includes/research-workflow.php';

requireRole('student');

$user = getCurrentUser();
$user_id = (int) ($user['user_id'] ?? 0);
$project_id = isset($_GET['project_id'])
    ? (int) $_GET['project_id']
    : (int) ($_POST['project_id'] ?? 0);
$errors = [];
$success = (string) ($_SESSION['final_manuscript_success'] ?? '');
unset($_SESSION['final_manuscript_success']);
$upload_result = null;

$deleted_column = $conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($deleted_column === false) {
    throw new RuntimeException('Unable to verify project availability: ' . $conn->error);
}
$has_deleted_at = $deleted_column->num_rows > 0;
$deleted_column->close();
$deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

$project = null;
if ($project_id > 0) {
    $project_stmt = $conn->prepare(
        'SELECT rp.project_id, rp.title, rp.abstract, rp.status, rp.created_by
           FROM research_projects rp
          WHERE rp.project_id = ?' . $deleted_filter . '
            AND (
                rp.created_by = ?
                OR EXISTS (
                    SELECT 1
                      FROM project_members pm
                     WHERE pm.project_id = rp.project_id
                       AND pm.user_id = ?
                )
            )
          LIMIT 1'
    );
    if (!$project_stmt) {
        throw new RuntimeException('Unable to prepare final manuscript project lookup: ' . $conn->error);
    }
    $project_stmt->bind_param('iii', $project_id, $user_id, $user_id);
    if (!$project_stmt->execute()) {
        throw new RuntimeException('Unable to load the research project: ' . $project_stmt->error);
    }
    $project = $project_stmt->get_result()->fetch_assoc() ?: null;
    $project_stmt->close();
}

if (!$project) {
    http_response_code($project_id > 0 ? 403 : 400);
}

$actor_eligible = ($user['role'] ?? '') !== 'student'
    || rms_student_program_is_eligible($conn, $user_id);
$team_eligible = $project && rms_project_students_are_eligible($conn, $project_id);
$chapters_approved = $project && rms_project_has_all_approved_chapters($conn, $project_id);
$latest_status = $project ? rms_latest_final_manuscript_status($conn, $project_id) : null;
$project_finished = $project && in_array($project['status'], ['completed', 'archived'], true);
$locked_status = $project_finished || in_array($latest_status, ['submitted', 'approved'], true);
$abstract = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_string($_POST['abstract'] ?? null) ? trim($_POST['abstract']) : '')
    : (string) ($project['abstract'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form has expired. Refresh the page and try again.';
    } elseif (!$project) {
        $errors[] = 'The selected project is unavailable or you do not have access to it.';
    } elseif (!$actor_eligible || !$team_eligible) {
        $errors[] = 'All student researchers on this project must be active BSIT or BSCS students before submitting the final manuscript.';
    } elseif (!$chapters_approved) {
        $errors[] = 'The final manuscript can be submitted only after all five chapters are approved.';
    } elseif ($locked_status) {
        $errors[] = 'The final manuscript is locked while awaiting review or after approval.';
    } elseif (($_POST['action'] ?? '') !== 'submit_final_manuscript') {
        $errors[] = 'Choose the final manuscript submission action.';
    } elseif ($abstract === '') {
        $errors[] = 'Enter the abstract for your complete research manuscript.';
    } elseif (strlen($abstract) > 65535) {
        $errors[] = 'The abstract is too long. Shorten it before submitting.';
    } elseif (!isset($_FILES['final_manuscript'])
        || ($_FILES['final_manuscript']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Upload the complete research manuscript before submitting it for review.';
    } else {
        $conn->begin_transaction();
        $uploaded_file_path = '';
        try {
            $lock_stmt = $conn->prepare(
                'SELECT project_id, status
                   FROM research_projects
                  WHERE project_id = ?' . ($has_deleted_at ? ' AND deleted_at IS NULL' : '') . '
                  FOR UPDATE'
            );
            if (!$lock_stmt) {
                throw new RuntimeException('Unable to lock the research project for final submission.');
            }
            $lock_stmt->bind_param('i', $project_id);
            if (!$lock_stmt->execute() || !($locked_project = $lock_stmt->get_result()->fetch_assoc())) {
                throw new RuntimeException('The research project is no longer available.');
            }
            $lock_stmt->close();
            if (in_array($locked_project['status'], ['completed', 'archived'], true)) {
                throw new RuntimeException('This research is completed. Another complete research manuscript cannot be submitted for it.');
            }

            if (!(($user['role'] ?? '') !== 'student' || rms_student_program_is_eligible($conn, $user_id))
                || !rms_project_students_are_eligible($conn, $project_id)) {
                throw new RuntimeException('All student researchers on this project must be active BSIT or BSCS students.');
            }
            if (!rms_project_has_all_approved_chapters($conn, $project_id)) {
                throw new RuntimeException('All five chapters must be approved before final manuscript submission.');
            }
            if (in_array(rms_latest_final_manuscript_status($conn, $project_id), ['submitted', 'approved'], true)) {
                throw new RuntimeException('The final manuscript is locked while awaiting review or after approval.');
            }

            $upload_result = handleRmsUpload([
                'inputName' => 'final_manuscript',
                'folderTarget' => 'manuscripts',
                'maxSize' => 20000,
                'accept' => ['.pdf'],
                'projectId' => $project_id,
                'type' => 'manuscript',
                'workflowType' => 'final_manuscript',
            ], $_FILES, $conn);
            if (empty($upload_result['success'])) {
                throw new RuntimeException('File upload failed: ' . (string) ($upload_result['error'] ?? 'Unknown upload error'));
            }
            $uploaded_file_path = (string) $upload_result['file_path'];

            rms_capture_submission_version(
                $conn,
                $project_id,
                null,
                'final_manuscript',
                $user_id,
                [
                    'submission' => 'Complete Research Manuscript',
                    'abstract' => $abstract,
                    'file_name' => (string) $upload_result['original_name'],
                ],
                [(int) $upload_result['upload_id']]
            );

            $status_update = $conn->prepare(
                "UPDATE research_projects
                    SET abstract = ?, updated_at = NOW()
                  WHERE project_id = ?"
            );
            if (!$status_update) {
                throw new RuntimeException('Unable to save the manuscript abstract.');
            }
            $status_update->bind_param('si', $abstract, $project_id);
            if (!$status_update->execute()) {
                throw new RuntimeException('Unable to save the manuscript abstract: ' . $status_update->error);
            }
            $status_update->close();

            logActivity('Final research manuscript submitted for review', 'research_submission_review');
            $conn->commit();
            $_SESSION['final_manuscript_success'] = 'Your complete research manuscript was submitted for authorized review.';
            header('Location: ' . SITE_URL . 'pages/researcher/submit-final-research.php?project_id=' . $project_id);
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            if ($uploaded_file_path !== '') {
                $relative_path = str_replace('\\', '/', $uploaded_file_path);
                $resolved_file = strpos($relative_path, 'uploads/') === 0
                    ? realpath(rtrim(UPLOADS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($relative_path, strlen('uploads/'))))
                    : false;
                $uploads_root = realpath(UPLOADS_DIR);
                if ($resolved_file && $uploads_root && strpos($resolved_file, $uploads_root . DIRECTORY_SEPARATOR) === 0 && is_file($resolved_file)) {
                    unlink($resolved_file);
                }
            }
            $errors[] = $exception->getMessage();
        }
    }
}

$history = [];
if ($project) {
    $history_stmt = $conn->prepare(
        'SELECT v.version_number, v.status, v.snapshot_json, v.submitted_at, u.upload_id, u.original_name,
                r.decision, r.comments, r.recommendation, r.reviewed_at,
                CONCAT(reviewer.first_name, " ", reviewer.last_name) AS reviewer_name
           FROM research_submission_versions v
      LEFT JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id
      LEFT JOIN uploads u ON u.upload_id = vu.upload_id
      LEFT JOIN research_submission_reviews r
             ON r.review_id = (
                 SELECT latest.review_id
                   FROM research_submission_reviews latest
                  WHERE latest.version_id = v.version_id
                  ORDER BY latest.reviewed_at DESC, latest.review_id DESC
                  LIMIT 1
             )
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewer_id
          WHERE v.project_id = ?
            AND v.submission_type = \'final_manuscript\'
            AND v.chapter_id IS NULL
          ORDER BY v.version_number DESC, v.submitted_at DESC'
    );
    if (!$history_stmt) {
        throw new RuntimeException('Unable to prepare final manuscript history lookup: ' . $conn->error);
    }
    $history_stmt->bind_param('i', $project_id);
    if (!$history_stmt->execute()) {
        throw new RuntimeException('Unable to load final manuscript history: ' . $history_stmt->error);
    }
    $history_result = $history_stmt->get_result();
    while ($row = $history_result->fetch_assoc()) {
        $history[] = $row;
    }
    $history_stmt->close();
}

$can_submit = $project
    && $actor_eligible
    && $team_eligible
    && $chapters_approved
    && !$locked_status;
$page_title = 'Submit Final Research';
if (($user['role'] ?? '') === 'faculty') {
    renderFacultyShell($user, 'my-research', $page_title, 'Submit the complete manuscript after all five chapters are approved.');
} else {
    renderStudentShell($user, 'my-research', $page_title, 'Submit the complete manuscript after all five chapters are approved.');
}
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/file-uploader.css">
<main class="research-final-submission" style="max-width:1100px;margin:0 auto;padding:12px 0 40px">
  <p><a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo (int) $project_id; ?>">← Back to research details</a></p>
  <h1>Submit the complete research manuscript</h1>
  <?php if ($project): ?>
    <p><strong><?php echo htmlspecialchars((string) $project['title'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
  <?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if (!$project): ?>
    <div class="card"><p>The project is unavailable or you do not have permission to submit for it.</p></div>
  <?php elseif (!$actor_eligible || !$team_eligible): ?>
    <div class="card"><h2>Student program eligibility required</h2><p>The project lead and every student co-researcher must be active BSIT or BSCS students. Existing project history remains available; ask an administrator to correct an account program or update the team.</p></div>
  <?php elseif (!$chapters_approved): ?>
    <div class="card"><h2>Chapter approvals required</h2><p>The complete manuscript step unlocks after Chapters 1–5 have all been approved.</p></div>
  <?php elseif ($locked_status): ?>
    <div class="card"><h2>Final manuscript <?php echo ($project_finished || $latest_status === 'approved') ? 'approved - research completed' : 'awaiting review'; ?></h2><p>This version is locked. After approval, this research is completed and no further complete manuscript can be submitted for it. Its reviewer decision and history are shown below.</p></div>
  <?php elseif ($can_submit): ?>
    <form method="post" enctype="multipart/form-data" class="card" style="padding:24px">
      <?php echo csrfField(); ?>
      <input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
      <div class="form-group">
        <label for="manuscript-abstract">Abstract <span aria-hidden="true">*</span></label>
        <textarea id="manuscript-abstract" name="abstract" class="form-control" rows="8" required aria-describedby="abstract-help abstract-word-count"><?php echo htmlspecialchars($abstract, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <p id="abstract-help">Enter the abstract for this manuscript version. It will be preserved with your submission for review.</p>
        <p id="abstract-word-count" role="status" aria-live="polite">0 words. Recommended length: 200–250 words.</p>
        <section aria-labelledby="abstract-guidance-title" style="margin-top:16px;padding:16px;border:1px solid #E5E7EB;border-radius:12px;background:#F8FAFC">
          <h2 id="abstract-guidance-title" style="margin:0 0 8px;font-size:1rem">Recommended abstract checklist</h2>
          <ul style="margin:0;padding-left:20px">
            <li>Write one continuous paragraph in formal academic English and third person.</li>
            <li>Use past tense for completed activities and findings.</li>
            <li>Summarize the problem, objectives, methodology, respondents, important actual results (including relevant statistics), and conclusions.</li>
            <li>Avoid citations and references in the abstract. Include 3–5 keywords in the manuscript.</li>
            <li>For the manuscript, use lowercase Roman page numbers in the upper-right corner, following the guidance provided for your thesis.</li>
          </ul>
          <p style="margin:8px 0 0">The word-count range is a recommendation and will not prevent submission. Follow your adviser’s or department’s current instructions if they differ.</p>
        </section>
      </div>
      <?php echo renderFileUploader([
          'inputName' => 'final_manuscript',
          'accept' => '.pdf',
          'maxSize' => 20000,
          'folderTarget' => 'manuscripts',
          'projectId' => $project_id,
          'label' => 'Complete research manuscript',
          'description' => 'Upload one complete manuscript containing the approved Chapters 1–5.',
          'allowedFormatsText' => 'PDF only · Max 20 MB',
          'required' => false,
      ]); ?>
      <p>Authorized administrators and staff will review this version. If it is returned, address the comments and submit a revised version.</p>
      <button type="submit" name="action" value="submit_final_manuscript" class="btn btn-primary">Submit final manuscript for review</button>
    </form>
  <?php endif; ?>

  <?php if ($history): ?>
    <section class="card" style="margin-top:24px;padding:24px" aria-labelledby="final-manuscript-history">
      <h2 id="final-manuscript-history">Submission and review history</h2>
      <?php foreach ($history as $version): ?>
        <article style="padding:16px 0;border-top:1px solid #E5E7EB">
          <h3>Version <?php echo (int) $version['version_number']; ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $version['status'])), ENT_QUOTES, 'UTF-8'); ?></h3>
          <p>Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $version['submitted_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
          <?php $version_snapshot = json_decode((string) $version['snapshot_json'], true); ?>
          <?php if (is_string($version_snapshot['abstract'] ?? null)): ?><p style="white-space:pre-wrap"><strong>Abstract:</strong><br><?php echo htmlspecialchars($version_snapshot['abstract'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
          <?php if (!empty($version['upload_id'])): ?>
            <p>📄 <a href="<?php echo SITE_URL; ?>pages/shared/download-research-document.php?type=research&amp;id=<?php echo (int) $version['upload_id']; ?>&amp;mode=viewer"><?php echo htmlspecialchars((string) $version['original_name'], ENT_QUOTES, 'UTF-8'); ?></a></p>
          <?php endif; ?>
          <?php if (!empty($version['decision'])): ?>
            <p><strong><?php echo htmlspecialchars(ucfirst((string) $version['decision']), ENT_QUOTES, 'UTF-8'); ?></strong> by <?php echo htmlspecialchars((string) ($version['reviewer_name'] ?: 'Authorized reviewer'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (trim((string) ($version['comments'] ?? '')) !== ''): ?><p style="white-space:pre-wrap"><strong>Review comments:</strong><br><?php echo htmlspecialchars((string) $version['comments'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            <?php if (trim((string) ($version['recommendation'] ?? '')) !== ''): ?><p style="white-space:pre-wrap"><strong>Suggestion:</strong><br><?php echo htmlspecialchars((string) $version['recommendation'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
          <?php else: ?>
            <p>Awaiting an authorized reviewer decision.</p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
<script>
(() => {
  const abstractField = document.getElementById('manuscript-abstract');
  const wordCount = document.getElementById('abstract-word-count');
  if (!abstractField || !wordCount) return;

  const updateWordCount = () => {
    const words = abstractField.value.trim().match(/\S+/g) || [];
    let guidance = 'Recommended length: 200–250 words.';
    if (words.length > 0 && words.length < 200) {
      guidance = 'Below the recommended 200–250-word range.';
    } else if (words.length > 250) {
      guidance = 'Above the recommended 200–250-word range.';
    } else if (words.length >= 200) {
      guidance = 'Within the recommended 200–250-word range.';
    }
    wordCount.textContent = `${words.length} ${words.length === 1 ? 'word' : 'words'}. ${guidance} Submission is not blocked by this recommendation.`;
  };

  abstractField.addEventListener('input', updateWordCount);
  updateWordCount();
})();
</script>
<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>
<?php
if (($user['role'] ?? '') === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
