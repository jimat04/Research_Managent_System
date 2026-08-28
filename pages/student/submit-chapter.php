<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('student');

$user = getCurrentUser();
$user_id = $user['user_id'];
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : (isset($_POST['project_id']) ? intval($_POST['project_id']) : 0);
$chapter_number = isset($_GET['chapter']) ? intval($_GET['chapter']) : (isset($_POST['chapter']) ? intval($_POST['chapter']) : 0);

$chapter_titles = [
    1 => 'Chapter 1: The Problem and Its Background',
    2 => 'Chapter 2: Review of Related Literature',
    3 => 'Chapter 3: Research Methodology',
    4 => 'Chapter 4: Presentation, Analysis and Interpretation of Data',
    5 => 'Chapter 5: Summary of Findings, Conclusions and Recommendations'
];

$chapter_fields = [
    1 => [
        'background' => 'Background of the Study',
        'problem_statement' => 'Statement of the Problem',
        'objectives' => 'Research Objectives',
        'scope' => 'Scope and Delimitations',
        'significance' => 'Significance of the Study',
        'definition_terms' => 'Definition of Terms'
    ],
    2 => [
        'local_literature' => 'Local Literature',
        'foreign_literature' => 'Foreign Literature',
        'related_studies' => 'Related Studies',
        'theoretical_fw' => 'Theoretical Framework',
        'conceptual_fw' => 'Conceptual Framework'
    ],
    3 => [
        'research_design' => 'Research Design',
        'respondents' => 'Respondents of the Study',
        'instruments' => 'Research Instruments',
        'data_gathering' => 'Data Gathering Procedure',
        'statistical' => 'Statistical Treatment'
    ],
    4 => [
        'findings' => 'Presentation of Findings',
        'analysis' => 'Analysis and Interpretation'
    ],
    5 => [
        'summary_text' => 'Summary of Findings',
        'conclusions' => 'Conclusions',
        'recommendations' => 'Recommendations'
    ]
];

$status_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'submitted' => ['class' => 'badge badge-info', 'style' => ''],
    'under_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'revision_required' => ['class' => 'badge badge-warning', 'style' => ''],
    'approved' => ['class' => 'badge badge-success', 'style' => '']
];

$errors = [];
$success = '';
$project = null;
$chapter = null;
$content = [];
$current_upload = null;
$invalid_chapter = $chapter_number < 1 || $chapter_number > 5;

// The migration adds chapters.deleted_at; keep deleted projects and chapters inaccessible.
if (!$invalid_chapter && $project_id > 0) {
    $project_stmt = $conn->prepare("SELECT rp.*, rc.category_name, aa.label AS ay_label, aa.semester
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
        WHERE rp.project_id = ? AND rp.deleted_at IS NULL
        AND (rp.created_by = ? OR EXISTS (
            SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?
        ))");
    if ($project_stmt) {
        $project_stmt->bind_param('iii', $project_id, $user_id, $user_id);
        $project_stmt->execute();
        $project_result = $project_stmt->get_result();
        $project = $project_result->fetch_assoc() ?: null;
        $project_stmt->close();
    }
}

if (!$invalid_chapter && $project) {
    $chapter_stmt = $conn->prepare("SELECT * FROM chapters
        WHERE project_id = ? AND chapter_number = ? AND deleted_at IS NULL LIMIT 1");
    if ($chapter_stmt) {
        $chapter_stmt->bind_param('ii', $project_id, $chapter_number);
        $chapter_stmt->execute();
        $chapter = $chapter_stmt->get_result()->fetch_assoc() ?: null;
        $chapter_stmt->close();
    }

    if ($chapter) {
        $content_stmt = $conn->prepare('SELECT * FROM chapter_content WHERE chapter_id = ? LIMIT 1');
        if ($content_stmt) {
            $content_stmt->bind_param('i', $chapter['chapter_id']);
            $content_stmt->execute();
            $content = $content_stmt->get_result()->fetch_assoc() ?: [];
            $content_stmt->close();
        }
    }

    // uploads.deleted_at is present in some installations but not in the supplied base dump.
    $deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
    $uploads_have_deleted_at = false;
    if ($deleted_column_stmt) {
        $deleted_column_stmt->execute();
        $uploads_have_deleted_at = $deleted_column_stmt->get_result()->num_rows > 0;
        $deleted_column_stmt->close();
    }
    $upload_query = $uploads_have_deleted_at
        ? "SELECT * FROM uploads WHERE project_id = ? AND chapter_id = ? AND type = 'chapter' AND deleted_at IS NULL ORDER BY upload_id DESC LIMIT 1"
        : "SELECT * FROM uploads WHERE project_id = ? AND chapter_id = ? AND type = 'chapter' ORDER BY upload_id DESC LIMIT 1";
    $upload_stmt = $conn->prepare($upload_query);
    if ($upload_stmt) {
        $existing_chapter_id = $chapter ? $chapter['chapter_id'] : 0;
        $upload_stmt->bind_param('ii', $project_id, $existing_chapter_id);
        $upload_stmt->execute();
        $current_upload = $upload_stmt->get_result()->fetch_assoc() ?: null;
        $upload_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$invalid_chapter && $project) {
    $submitted_content = [];
    foreach ($chapter_fields[$chapter_number] as $field => $label) {
        $submitted_content[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    }

    $is_submit = isset($_POST['submit_review']);
    $has_content = false;
    foreach ($submitted_content as $value) {
        if ($value !== '') {
            $has_content = true;
            break;
        }
    }

    $file_uploaded = isset($_FILES['chapter_file']) && $_FILES['chapter_file']['error'] !== UPLOAD_ERR_NO_FILE;
    $file_valid = false;
    $file_extension = '';
    if ($file_uploaded) {
        $file = $_FILES['chapter_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error. Please try again.';
        } elseif (!in_array($file_extension, ['pdf', 'doc', 'docx'], true)) {
            $errors[] = 'Invalid file format. Accepted formats: PDF, DOC, DOCX.';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File size must not exceed 10 MB.';
        } else {
            $file_valid = true;
        }
    }

    if ($is_submit && !$has_content && !$file_valid) {
        $errors[] = 'Add content or upload a chapter document before submitting for review.';
    }

    if (empty($errors)) {
        $new_status = $is_submit ? 'submitted' : 'draft';
        $conn->begin_transaction();
        try {
            if (!$chapter) {
                $insert_stmt = $conn->prepare("INSERT INTO chapters
                    (project_id, chapter_number, chapter_title, status, version, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
                if (!$insert_stmt) {
                    throw new Exception('Unable to prepare chapter insert.');
                }
                $chapter_title = $chapter_titles[$chapter_number];
                $insert_stmt->bind_param('iiss', $project_id, $chapter_number, $chapter_title, $new_status);
                if (!$insert_stmt->execute()) {
                    throw new Exception('Unable to save chapter.');
                }
                $chapter_id = $conn->insert_id;
                $insert_stmt->close();

                $content_insert = $conn->prepare('INSERT INTO chapter_content (chapter_id) VALUES (?)');
                if (!$content_insert) {
                    throw new Exception('Unable to prepare chapter content.');
                }
                $content_insert->bind_param('i', $chapter_id);
                if (!$content_insert->execute()) {
                    throw new Exception('Unable to create chapter content.');
                }
                $content_insert->close();
            } else {
                $chapter_id = $chapter['chapter_id'];
                $new_version = (int) $chapter['version'];
                if ($chapter['status'] === 'revision_required') {
                    $new_version++;
                }
                $update_chapter = $is_submit
                    ? $conn->prepare('UPDATE chapters SET status = ?, version = ?, submitted_at = NOW(), updated_at = NOW() WHERE chapter_id = ?')
                    : $conn->prepare('UPDATE chapters SET status = ?, version = ?, submitted_at = NULL, updated_at = NOW() WHERE chapter_id = ?');
                if (!$update_chapter) {
                    throw new Exception('Unable to prepare chapter update.');
                }
                $update_chapter->bind_param('sii', $new_status, $new_version, $chapter_id);
                if (!$update_chapter->execute()) {
                    throw new Exception('Unable to update chapter.');
                }
                $update_chapter->close();
            }

            $set_parts = [];
            foreach ($submitted_content as $field => $value) {
                $set_parts[] = "`$field` = ?";
            }
            $content_update = $conn->prepare('UPDATE chapter_content SET ' . implode(', ', $set_parts) . ' WHERE chapter_id = ?');
            if (!$content_update) {
                throw new Exception('Unable to prepare content update.');
            }
            $content_values = array_values($submitted_content);
            $content_values[] = $chapter_id;
            $content_types = str_repeat('s', count($submitted_content)) . 'i';
            $content_update->bind_param($content_types, ...$content_values);
            if (!$content_update->execute()) {
                throw new Exception('Unable to save chapter content.');
            }
            $content_update->close();

            if ($file_valid) {
                $chapters_dir = __DIR__ . '/../uploads/chapters';
                if (!is_dir($chapters_dir) && !@mkdir($chapters_dir, 0755, true)) {
                    throw new Exception('Unable to create the chapter upload directory.');
                }
                $safe_file_name = uniqid('ch_', true) . '.' . $file_extension;
                $full_path = $chapters_dir . '/' . $safe_file_name;
                if (!move_uploaded_file($_FILES['chapter_file']['tmp_name'], $full_path)) {
                    throw new Exception('Unable to save the uploaded chapter file.');
                }

                if ($current_upload && $uploads_have_deleted_at) {
                    $delete_upload = $conn->prepare('UPDATE uploads SET deleted_at = NOW() WHERE upload_id = ?');
                    if ($delete_upload) {
                        $delete_upload->bind_param('i', $current_upload['upload_id']);
                        $delete_upload->execute();
                        $delete_upload->close();
                    }
                }

                $original_name = $_FILES['chapter_file']['name'];
                $file_path = '../uploads/chapters/' . $safe_file_name;
                $file_size = (int) $_FILES['chapter_file']['size'];
                $mime_type = $_FILES['chapter_file']['type'];
                $upload_insert = $conn->prepare("INSERT INTO uploads
                    (project_id, chapter_id, uploaded_by, type, original_name, file_name, file_path, file_size, mime_type, upload_date)
                    VALUES (?, ?, ?, 'chapter', ?, ?, ?, ?, ?, NOW())");
                if (!$upload_insert) {
                    throw new Exception('Unable to prepare upload record.');
                }
                $upload_insert->bind_param('iiisssis', $project_id, $chapter_id, $user_id, $original_name, $safe_file_name, $file_path, $file_size, $mime_type);
                if (!$upload_insert->execute()) {
                    throw new Exception('Unable to save the upload record.');
                }
                $upload_insert->close();
            }

            logActivity($is_submit ? 'Chapter submitted' : 'Chapter saved as draft', 'chapters');
            $conn->commit();
            header('Location: research-detail.php?id=' . $project_id . '&chapter_saved=1');
            exit();
        } catch (Exception $exception) {
            $conn->rollback();
            $errors[] = 'Unable to save chapter. Please try again.';
        }
    }
    $content = array_merge($content, $submitted_content);
}

$page_title = $invalid_chapter ? 'Invalid chapter' : ($chapter_titles[$chapter_number] ?? 'Submit Chapter');
$current_status = $chapter['status'] ?? null;
$status_badge = $current_status && isset($status_badges[$current_status]) ? $status_badges[$current_status] : $status_badges['draft'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($page_title); ?> — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<div class="dashboard">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">Research<br>Management</div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item" onclick="location.href='student-dashboard.php'"><span class="icon">📊</span><span>Dashboard</span></div>
      <div class="nav-item active" onclick="location.href='my-research.php'"><span class="icon">📁</span><span>My Research</span></div>
      <div class="nav-item" onclick="location.href='submit-research.php'"><span class="icon">📤</span><span>Submit Research</span></div>
      <div class="nav-item" onclick="location.href='my-documents.php'"><span class="icon">📄</span><span>My Documents</span></div>
      <div class="nav-group-title">TRACKING</div>
      <div class="nav-item" onclick="location.href='progress-tracking.php'"><span class="icon">📈</span><span>Progress Tracking</span></div>
      <div class="nav-item" onclick="location.href='messages.php'"><span class="icon">💬</span><span>Messages</span></div>
      <div class="nav-item" onclick="location.href='notifications.php'"><span class="icon">🔔</span><span>Notifications</span></div>
      <div class="nav-group-title">RESOURCES</div>
      <div class="nav-item" onclick="location.href='research-archive.php'"><span class="icon">🗂️</span><span>Research Archive</span></div>
      <div class="nav-item" onclick="location.href='calendar.php'"><span class="icon">📅</span><span>Calendar</span></div>
      <div class="nav-group-title">ACCOUNT</div>
      <div class="nav-item" onclick="location.href='profile.php'"><span class="icon">👤</span><span>Profile</span></div>
      <div class="nav-item" onclick="location.href='settings.php'"><span class="icon">⚙️</span><span>Settings</span></div>
      <div class="nav-item" onclick="location.href='../logout.php'" style="color: #ef4444;"><span class="icon">🚪</span><span>Logout</span></div>
    </nav>
    <div class="sidebar-footer"><div class="user-card">
      <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
      <div class="user-info"><div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div><div class="user-role">🎓 Student</div></div>
    </div></div>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left"><h2><?php echo htmlspecialchars($page_title); ?></h2><p><?php echo $project ? htmlspecialchars($project['title']) : 'Chapter submission'; ?></p></div>
      <div class="topbar-right"><div class="search-box"><span style="color: #94a3b8;">🔍</span><input type="text" placeholder="Search anything..."></div><div class="topbar-icons"><div class="icon-btn">🔔</div></div><div class="user-profile-btn"><div class="profile-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div><div class="profile-text"><div class="profile-name"><?php echo htmlspecialchars($user['first_name']); ?></div><div class="profile-role">Student</div></div></div></div>
    </header>

    <div class="page-content">
      <div style="margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="my-research.php" style="color: var(--primary); text-decoration: none; font-size: 14px;">← Back to My Research</a>
        <?php if ($project): ?><span style="color: var(--text-light);">/</span><a href="research-detail.php?id=<?php echo $project_id; ?>" style="color: var(--primary); text-decoration: none; font-size: 14px;">← Back to Project</a><?php endif; ?>
        <!-- @rms-ui: breadcrumb separator style -->
      </div>

      <?php if ($invalid_chapter): ?>
        <div class="card" style="text-align: center; padding: 60px 40px;"><h3 style="margin: 0 0 8px;">Invalid chapter</h3><p style="margin: 0 0 24px; color: var(--text-light);">Choose a chapter number from 1 to 5.</p><a href="my-research.php" class="btn btn-primary">Go back to My Research</a></div>
      <?php elseif (!$project): ?>
        <div class="card" style="text-align: center; padding: 60px 40px;"><h3 style="margin: 0 0 8px;">Project not found or you don't have access</h3><p style="margin: 0 0 24px; color: var(--text-light);">The research project you're looking for doesn't exist or you don't have permission to view it.</p><a href="my-research.php" class="btn btn-primary">Go back to My Research</a></div>
      <?php else: ?>
        <h1 style="margin: 0 0 8px; color: var(--text-dark); font-size: 28px;"><?php echo htmlspecialchars($chapter_titles[$chapter_number]); ?></h1>
        <p style="margin: 0 0 20px; color: var(--text-light);"><?php echo htmlspecialchars($project['title']); ?> · <?php if ($current_status): ?><span class="<?php echo htmlspecialchars($status_badge['class']); ?>" <?php echo $status_badge['style'] ? 'style="' . htmlspecialchars($status_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $current_status)); ?></span><?php else: ?><span class="badge" style="background:#e2e8f0;color:#475569;">Not Started</span><?php endif; ?> · Version <?php echo (int) ($chapter['version'] ?? 1); ?></p>

        <?php if ($success): ?><div class="alert alert-success"><strong>Success!</strong> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-error"><ul style="margin: 0; padding-left: 20px;"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="chapter" value="<?php echo $chapter_number; ?>">
          <?php echo csrfField(); ?>
          <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter Information</div></div><div class="card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div><strong>Project</strong><div><?php echo htmlspecialchars($project['title']); ?></div></div>
            <div><strong>Chapter</strong><div><?php echo htmlspecialchars($chapter_titles[$chapter_number]); ?></div></div>
            <div><strong>Status</strong><div><?php if ($current_status): ?><span class="<?php echo htmlspecialchars($status_badge['class']); ?>" <?php echo $status_badge['style'] ? 'style="' . htmlspecialchars($status_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $current_status)); ?></span><?php else: ?><span class="badge" style="background:#e2e8f0;color:#475569;">Not Started</span><?php endif; ?></div></div>
            <div><strong>Version</strong><div>Version <?php echo (int) ($chapter['version'] ?? 1); ?></div></div>
            <?php if ($chapter): ?><div><strong>Last updated</strong><div><?php echo !empty($chapter['updated_at']) ? date('M d, Y', strtotime($chapter['updated_at'])) : 'N/A'; ?></div></div><?php endif; ?>
          </div></div>

          <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter Content</div></div><div class="card-body">
            <?php foreach ($chapter_fields[$chapter_number] as $field => $label): ?><div class="form-group"><label class="form-label" for="<?php echo $field; ?>"><?php echo htmlspecialchars($label); ?></label><textarea id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="form-control" rows="8" placeholder="Write your content here..."><?php echo htmlspecialchars($content[$field] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea><small class="text-muted">Add your content for this section. You may continue editing it later.</small></div><?php endforeach; ?>
            <!-- @rms-ui: chapter content textarea height -->
          </div></div>

          <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter File</div></div><div class="card-body">
            <input type="file" name="chapter_file" accept=".pdf,.doc,.docx" class="form-control"><small class="text-muted">Accepted formats: PDF, DOC, DOCX. Max 10MB. Upload your formatted chapter document if you prefer to submit a file instead of typing content above.</small>
            <?php if ($current_upload): ?><div style="margin-top: 14px;">📄 Current uploaded file: <a href="<?php echo htmlspecialchars($current_upload['file_path']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($current_upload['original_name']); ?></a><div style="color: var(--text-light); font-size: 13px;">Uploading a new file will replace this.</div></div><?php endif; ?>
            <!-- @rms-db: uploads.deleted_at is required for soft-delete replacement; this page falls back when the column is absent. -->
          </div></div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;"><a href="research-detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a><button type="submit" name="save_draft" class="btn btn-secondary">Save as Draft</button><button type="submit" name="submit_review" class="btn btn-primary">Submit for Review</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
