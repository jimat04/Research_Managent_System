<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/file-uploader.php';

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
$chapter_has_review_activity = false;
$invalid_chapter = $chapter_number < 1 || $chapter_number > 5;

// research_projects.deleted_at is added by database/migrations/rms_db_migration.sql
// but is NOT present in the supplied base schema. Detect at runtime so this page
// works on both installs without throwing "Unknown column 'rp.deleted_at'".
$rp_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$rp_has_deleted_at = false;
if ($rp_deleted_column_stmt) {
    $rp_deleted_column_stmt->execute();
    $rp_has_deleted_at = $rp_deleted_column_stmt->get_result()->num_rows > 0;
    $rp_deleted_column_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// The migration adds chapters.deleted_at; keep deleted projects and chapters inaccessible.
$ch_has_deleted_at = false;
$ch_del_check = $conn->prepare("SHOW COLUMNS FROM chapters LIKE 'deleted_at'");
if ($ch_del_check) {
    $ch_del_check->execute();
    $ch_has_deleted_at = $ch_del_check->get_result()->num_rows > 0;
    $ch_del_check->close();
}
$ch_deleted_filter = $ch_has_deleted_at ? ' AND deleted_at IS NULL' : '';

// ── Friendly selection flow ───────────────────────────────────────────────
// Many entry points link here without one or both params. Instead of dead-end
// error cards, route the user through a project picker (when ?project_id is
// missing/invalid) or a chapter picker (when ?project_id is valid but
// ?chapter is missing/invalid). The full upload flow runs only when both
// params are present and valid.
$sch_picker_mode      = '';   // '' | 'project' | 'chapter'
$sch_student_projects = [];   // used by 'project' picker
$sch_project_chapters = [];   // used by 'chapter' picker
$sch_resolved_project = null; // for 'chapter' picker we still need access control

if ($project_id <= 0) {
    // No project_id at all — show the project picker.
    $sch_picker_mode = 'project';
} elseif ($invalid_chapter) {
    // project_id present but chapter missing/invalid — load project + chapters
    $sch_picker_mode = 'chapter';

    $sch_proj_stmt = $conn->prepare("SELECT rp.project_id, rp.title, rp.status
        FROM research_projects rp
        WHERE rp.project_id = ?" . $rp_deleted_filter . "
        AND (rp.created_by = ? OR EXISTS (
            SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?
        ))
        LIMIT 1");
    if ($sch_proj_stmt) {
        $sch_proj_stmt->bind_param('iii', $project_id, $user_id, $user_id);
        $sch_proj_stmt->execute();
        $sch_resolved_project = $sch_proj_stmt->get_result()->fetch_assoc() ?: null;
        $sch_proj_stmt->close();
    }

    if ($sch_resolved_project) {
        $sch_ch_stmt = $conn->prepare("SELECT chapter_id, chapter_number, chapter_title, status, version
            FROM chapters
            WHERE project_id = ?" . $ch_deleted_filter . "
            ORDER BY chapter_number ASC");
        if ($sch_ch_stmt) {
            $sch_ch_stmt->bind_param('i', $project_id);
            $sch_ch_stmt->execute();
            $r = $sch_ch_stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $sch_project_chapters[(int) $row['chapter_number']] = $row;
            }
            $sch_ch_stmt->close();
        }
    }
}

if ($sch_picker_mode === 'project') {
    // Load the student's projects (owned OR member), prepared statement, honour
    // soft-delete column if present.
    $sch_pp_stmt = $conn->prepare("SELECT rp.project_id, rp.title, rp.status, rp.updated_at,
            rc.category_name, aa.label AS ay_label
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
        WHERE (rp.created_by = ? OR EXISTS (
            SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?
        ))" . $rp_deleted_filter . "
        ORDER BY rp.updated_at DESC, rp.project_id DESC");
    if ($sch_pp_stmt) {
        $sch_pp_stmt->bind_param('ii', $user_id, $user_id);
        $sch_pp_stmt->execute();
        $r = $sch_pp_stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $sch_student_projects[] = $row;
        }
        $sch_pp_stmt->close();
    }

    // If exactly one project, skip the picker and go straight to the chapter picker.
    if (count($sch_student_projects) === 1) {
        $only_pid = (int) $sch_student_projects[0]['project_id'];
        header('Location: ' . SITE_URL . 'pages/student/submit-chapter.php?project_id=' . $only_pid);
        exit();
    }
}

// The migration adds chapters.deleted_at; keep deleted projects and chapters inaccessible.
if (!$invalid_chapter && $project_id > 0) {
    $project_stmt = $conn->prepare("SELECT rp.*, rc.category_name, aa.label AS ay_label, aa.semester
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
        WHERE rp.project_id = ?" . $rp_deleted_filter . "
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

        $review_stmt = $conn->prepare('SELECT 1 FROM comments WHERE chapter_id = ? LIMIT 1');
        if ($review_stmt) {
            $review_stmt->bind_param('i', $chapter['chapter_id']);
            $review_stmt->execute();
            $chapter_has_review_activity = $review_stmt->get_result()->num_rows > 0;
            $review_stmt->close();
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
    if (isset($_POST['withdraw_submission'])) {
        if (!$chapter || $chapter['status'] !== 'submitted') {
            $errors[] = 'Only a submitted chapter that has not entered review can be withdrawn.';
        } elseif ($chapter_has_review_activity) {
            $errors[] = 'This chapter can no longer be withdrawn because faculty review has already started.';
        } else {
            // Re-check status and review activity in the UPDATE to prevent a
            // race with a faculty action occurring after the page was loaded.
            $withdraw_stmt = $conn->prepare("UPDATE chapters ch
                SET status = 'draft', submitted_at = NULL, updated_at = NOW()
                WHERE ch.chapter_id = ?
                  AND ch.project_id = ?
                  AND ch.status = 'submitted'
                  AND NOT EXISTS (
                      SELECT 1 FROM comments c WHERE c.chapter_id = ch.chapter_id
                  )");
            if (!$withdraw_stmt) {
                $errors[] = 'Unable to prepare the withdrawal. Please try again.';
            } else {
                $withdraw_stmt->bind_param('ii', $chapter['chapter_id'], $project_id);
                $withdraw_stmt->execute();
                if ($withdraw_stmt->affected_rows === 1) {
                    $chapter['status'] = 'draft';
                    $chapter['submitted_at'] = null;
                    $success = 'Chapter submission withdrawn. You can edit and submit it again.';
                    logActivity('Chapter submission withdrawn', 'chapters');
                } else {
                    $errors[] = 'This chapter can no longer be withdrawn because review has already started.';
                }
                $withdraw_stmt->close();
            }
        }
    } else {
    $submitted_content = [];
    foreach ($chapter_fields[$chapter_number] as $field => $label) {
        $submitted_content[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    }

    $is_submit = isset($_POST['submit_review']);
    $editable_statuses = ['draft', 'revision_required'];
    if ($chapter && !in_array($chapter['status'], $editable_statuses, true)) {
        $errors[] = 'This chapter is locked while awaiting review or after approval.';
    }
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
                $chapters_dir = __DIR__ . '/../../uploads/chapters';
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
                $file_path = '../../uploads/chapters/' . $safe_file_name;
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
            header('Location: ' . SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id . '&chapter_saved=1');
            exit();
        } catch (Exception $exception) {
            $conn->rollback();
            $errors[] = 'Unable to save chapter. Please try again.';
        }
    }
    $content = array_merge($content, $submitted_content);
    }
}

$page_title = $invalid_chapter ? 'Invalid chapter' : ($chapter_titles[$chapter_number] ?? 'Submit Chapter');
$current_status = $chapter['status'] ?? null;
$status_badge = $current_status && isset($status_badges[$current_status]) ? $status_badges[$current_status] : $status_badges['draft'];
$can_edit_chapter = !$chapter || in_array($current_status, ['draft', 'revision_required'], true);
$can_withdraw_chapter = $chapter && $current_status === 'submitted' && !$chapter_has_review_activity;

// Page title + subtitle for the picker states.
$sch_page_title    = 'Submit Chapter';
$sch_page_subtitle = 'Pick a project and chapter to upload a new draft or revision.';
if ($sch_picker_mode === 'project') {
    $sch_page_title    = 'Choose a project';
    $sch_page_subtitle = 'Pick one of your research projects to continue.';
} elseif ($sch_picker_mode === 'chapter' && $sch_resolved_project) {
    $sch_page_title    = 'Choose a chapter';
    $sch_page_subtitle = 'Pick a chapter of "' . (string) $sch_resolved_project['title'] . '" to upload or edit.';
} elseif ($sch_picker_mode === 'chapter') {
    $sch_page_title    = 'Project not available';
    $sch_page_subtitle = 'We could not find that project on your account.';
}

renderStudentShell(
    $user,
    'submit-chapter',
    $sch_picker_mode !== '' ? $sch_page_title : $page_title,
    $sch_picker_mode !== '' ? $sch_page_subtitle : ($project ? htmlspecialchars($project['title']) : 'Upload a new chapter draft.')
);
?>

<link rel="stylesheet" href="<?php echo SITE_URL; ?>css/file-uploader.css">

<style>
  .alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 14px;
  }

  .alert-success {
    background: #DCFCE7;
    color: #16A34A;
    border: 1px solid #86EFAC;
  }

  .alert-error {
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
  }

  .card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 24px;
  }

  .card-header {
    margin-bottom: 16px;
  }

  .card-title {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
  }

  .card-body {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
  }

  .form-group {
    margin-bottom: 20px;
  }

  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 8px;
  }

  .form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.2s;
  }

  .form-control:focus {
    outline: none;
    border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }

  textarea.form-control {
    resize: vertical;
    min-height: 120px;
  }

  .text-muted {
    font-size: 13px;
    color: #64748B;
    margin-top: 6px;
  }

  .badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    background: #F1F5F9;
    color: #64748B;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
  }

  .btn-primary {
    background: #5B1EBC;
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(91, 30, 188, 0.3);
  }

  .btn-secondary {
    background: #F8FAFC;
    color: #111827;
    border: 1px solid #E5E7EB;
  }

  .btn-secondary:hover {
    background: #111827;
    color: white;
  }

  /* Picker (project list + chapter list) */
  .picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
  }
  .picker-item {
    display: block;
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 20px 22px;
    text-decoration: none;
    color: #111827;
    transition: all 0.2s;
  }
  .picker-item:hover {
    border-color: #5B1EBC;
    box-shadow: 0 4px 14px rgba(91, 30, 188, 0.10);
    transform: translateY(-1px);
  }
  .picker-item-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
  }
  .picker-item-title {
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
  }
  .picker-item-meta {
    font-size: 12px;
    color: #64748B;
    margin-top: 2px;
  }
  .picker-item-sub {
    font-size: 12px;
    color: #94A3B8;
    margin-top: 10px;
  }
  .picker-num {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #5B1EBC;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
  }
  .picker-num.approved          { background: #16A34A; }
  .picker-num.under_review      { background: #2563EB; }
  .picker-num.revision_required { background: #EA580C; }
  .picker-num.submitted         { background: #7C3AED; }
  .picker-num.draft             { background: #64748B; }
  .picker-empty {
    text-align: center;
    padding: 60px 40px;
  }
  .picker-empty .ico {
    font-size: 48px;
    margin-bottom: 12px;
    opacity: 0.6;
  }

  .student-page-content{background-color:#EEEAF8;background-image:radial-gradient(circle at 88% 3%,rgba(91,30,188,.13),transparent 27%),radial-gradient(circle at 7% 47%,rgba(37,99,235,.07),transparent 24%),linear-gradient(180deg,#F4F1FA 0%,#ECE8F5 100%)}
  .student-topbar{background:rgba(255,255,255,.93);backdrop-filter:blur(14px)}
  .chapter-page{--purple:#5B1EBC;--ink:#140D1E;--muted:#6D6279;--line:#DED5E8;max-width:1160px;margin:0 auto;padding-bottom:44px;color:var(--ink)}
  .chapter-breadcrumbs{display:flex;align-items:center;gap:10px;margin-bottom:18px!important}.chapter-breadcrumbs a{color:#4B168F!important;font-size:12px!important;font-weight:750;text-decoration:none!important}.chapter-breadcrumbs a:hover{color:#741FD1!important}.chapter-breadcrumbs span{color:#A397AE!important}
  .chapter-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(285px,.7fr);gap:42px;overflow:hidden;margin-bottom:24px;padding:38px 40px;border:1px solid rgba(255,255,255,.18);border-radius:20px;background:radial-gradient(circle at 91% 8%,rgba(220,198,255,.25),transparent 28%),radial-gradient(circle at 8% 118%,rgba(43,110,230,.24),transparent 32%),linear-gradient(135deg,#291050 0%,#4C188F 57%,#6C2CC7 100%);color:#fff;box-shadow:0 22px 52px rgba(54,24,103,.22)}.chapter-hero::after{content:'';position:absolute;right:-75px;bottom:-150px;width:300px;height:300px;border:46px solid rgba(255,255,255,.055);border-radius:50%;pointer-events:none}.chapter-hero-copy,.chapter-hero-guide{position:relative;z-index:1}.chapter-eyebrow{margin:0 0 11px;color:#DCCBFF;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.chapter-hero h1{max-width:650px;margin:0;font-size:clamp(31px,3.4vw,47px);font-weight:750;letter-spacing:-.045em;line-height:1.06;text-wrap:balance}.chapter-hero-description{max-width:60ch;margin:17px 0 0;color:#E8E0F3;font-size:15px;line-height:1.7}.chapter-hero-guide{align-content:center;padding-left:33px;border-left:1px solid rgba(255,255,255,.24)}.chapter-guide-label{margin:0 0 13px;color:#D9CDE9;font-size:11px;font-weight:700}.chapter-guide-steps{display:grid;gap:9px;margin:0;padding:0;list-style:none}.chapter-guide-steps li{display:grid;grid-template-columns:27px 1fr;align-items:center;gap:9px;color:#F4EFFF;font-size:11px;font-weight:650}.chapter-guide-steps span{display:grid;width:27px;height:27px;place-items:center;border:1px solid rgba(255,255,255,.24);border-radius:8px;background:rgba(255,255,255,.1);font-size:10px}.chapter-guide-steps .is-current span{border-color:#fff;background:#fff;color:#4B168F}.chapter-guide-steps .is-current{color:#fff}
  .chapter-page>h1,.chapter-page>h1+p{display:none}.chapter-page .alert{margin-bottom:18px;padding:17px 19px;border-radius:15px;font-size:12px;line-height:1.55}.chapter-page .alert-success{border-color:#A7DDC8;background:#EDFBF5;color:#087A59}.chapter-page .alert-error{border-color:#F1B5B5;background:#FFF1F1;color:#A82323}.chapter-page .alert ul{margin:0;padding-left:18px}
  .chapter-page .picker-grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.chapter-page .picker-item{position:relative;overflow:hidden;min-height:154px;padding:22px 23px;border-color:#D9D0E4;border-radius:17px;background:rgba(255,255,255,.96);box-shadow:0 10px 28px rgba(45,24,76,.06);transition:transform .22s ease,border-color .22s ease,box-shadow .22s ease}.chapter-page .picker-item::after{content:'→';position:absolute;right:21px;bottom:18px;color:#5B1EBC;font-size:17px;font-weight:700;transition:transform .22s ease}.chapter-page .picker-item:hover{border-color:#A985D1;box-shadow:0 16px 35px rgba(61,27,106,.12);transform:translateY(-3px)}.chapter-page .picker-item:hover::after{transform:translateX(3px)}.chapter-page .picker-item-head{margin-bottom:17px}.chapter-page .picker-item-title{max-width:90%;color:#24162F;font-size:16px;letter-spacing:-.02em}.chapter-page .picker-item-meta{margin-top:7px;color:#766A81;font-size:11px;line-height:1.5}.chapter-page .picker-item-sub{margin-top:8px;padding-right:25px;color:#8C8196;font-size:10px}.chapter-page .picker-num{width:42px;height:42px;border-radius:13px;background:#5B1EBC;font-size:15px;box-shadow:0 8px 16px rgba(91,30,188,.18)}.chapter-page .badge{padding:4px 9px;border-radius:8px;font-size:10px;font-weight:700}.chapter-page .picker-empty{padding:58px 30px;border-color:#D9D0E4;background:radial-gradient(circle at 50% 15%,rgba(91,30,188,.09),transparent 38%),#FBF9FD;text-align:center}.chapter-page .picker-empty .ico{display:grid;width:70px;height:70px;margin:0 auto 18px;place-items:center;border-radius:21px;background:#EEE4FA;font-size:31px;opacity:1}.chapter-page .picker-empty h3{color:#25172F!important;font-size:21px;letter-spacing:-.025em}.chapter-page .picker-empty p{max-width:520px;margin-right:auto!important;margin-left:auto!important;color:#766A81!important;line-height:1.65}
  .chapter-page form{display:grid;gap:18px}.chapter-page form>.card{overflow:hidden;margin:0!important;padding:0;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.96);box-shadow:0 12px 32px rgba(45,24,76,.07)}.chapter-page form>.card:nth-of-type(1){border-top:4px solid #5B1EBC}.chapter-page form>.card:nth-of-type(2){border-top:4px solid #07855F}.chapter-page form>.card:nth-of-type(3){border-top:4px solid #C55408}.chapter-page form>.card .card-header{margin:0;padding:22px 27px 17px;border-bottom:1px solid #EEE8F4}.chapter-page form>.card .card-title{display:flex;align-items:center;gap:12px;color:#201529;font-size:19px;letter-spacing:-.025em}.chapter-page form>.card .card-title::before{display:grid;width:34px;height:34px;place-items:center;border-radius:11px;background:#EEE6FB;color:#5B1EBC;font-size:11px;font-weight:800}.chapter-page form>.card:nth-of-type(1) .card-title::before{content:'01'}.chapter-page form>.card:nth-of-type(2) .card-title::before{content:'02';background:#E3F7EF;color:#07855F}.chapter-page form>.card:nth-of-type(3) .card-title::before{content:'03';background:#FFF0E4;color:#C55408}.chapter-page form>.card .card-body{padding:25px 27px 28px}.chapter-page form>.card:first-of-type .card-body{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0}.chapter-page form>.card:first-of-type .card-body>div{min-height:72px;padding:10px 18px;border-left:1px solid #EAE3EF}.chapter-page form>.card:first-of-type .card-body>div:first-child{padding-left:0;border-left:0}.chapter-page form>.card:first-of-type strong{display:block;margin-bottom:7px;color:#857A90;font-size:10px;font-weight:750;text-transform:uppercase;letter-spacing:.07em}.chapter-page form>.card:first-of-type strong+div{color:#2B2035;font-size:12px;font-weight:650;line-height:1.45}
  .chapter-page .form-group{margin-bottom:26px}.chapter-page .form-group:last-child{margin-bottom:0}.chapter-page .form-label{margin-bottom:9px;color:#281B33;font-size:12px;font-weight:750}.chapter-page textarea.form-control{min-height:190px;padding:15px 16px;border-color:#D8D0E3;border-radius:12px;background:#FCFBFD;color:#1D1425;font-size:13px;line-height:1.65}.chapter-page textarea.form-control:hover{border-color:#BFAECF}.chapter-page .form-control:focus{border-color:#5B1EBC;background:#fff;box-shadow:0 0 0 4px rgba(91,30,188,.1)}.chapter-page .form-control:disabled{cursor:not-allowed;background:#F1EEF4;color:#7D7386}.chapter-page .text-muted{display:block;margin-top:7px;color:#857A90;font-size:10px;line-height:1.5}.chapter-page input[type=file].form-control{min-height:112px;padding:24px;border:2px dashed #D3BDEB;border-radius:15px;background:#F8F3FD;color:#665672}.chapter-page input[type=file].form-control::file-selector-button{margin-right:14px;padding:10px 15px;border:0;border-radius:9px;background:#5B1EBC;color:#fff;font:700 11px 'Inter',sans-serif;cursor:pointer;transition:background .2s ease}.chapter-page input[type=file].form-control::file-selector-button:hover{background:#491598}.chapter-page form>.card:nth-of-type(3) .card-body>div{padding:13px 14px;border:1px solid #D9CBE7;border-radius:12px;background:#F8F3FC;color:#493658;font-size:12px}.chapter-page form>.card:nth-of-type(3) a{color:#5B1EBC;font-weight:700}
  .chapter-page form>div:last-child:not(.card){margin-top:3px;padding:18px 20px;border:1px solid #D8CBE8;border-radius:17px;background:rgba(255,255,255,.96);box-shadow:0 12px 30px rgba(45,24,76,.08)}.chapter-page .btn{min-height:43px;padding:10px 17px;border:1px solid transparent;border-radius:10px;font:700 12px/1 'Inter',sans-serif}.chapter-page .btn-primary{background:#5B1EBC;color:#fff}.chapter-page .btn-primary:hover{transform:translateY(-1px);background:#491598;box-shadow:0 9px 18px rgba(91,30,188,.21)}.chapter-page .btn-secondary{border-color:#D8D0E3;background:#fff;color:#281B35}.chapter-page .btn-secondary:hover{border-color:#B9AACA;background:#F5F0FA;color:#4B168F}.chapter-page .btn:active,.chapter-page .picker-item:active{transform:translateY(1px)}.chapter-page a:focus-visible,.chapter-page button:focus-visible,.chapter-page textarea:focus-visible,.chapter-page input:focus-visible{outline:3px solid rgba(91,30,188,.24);outline-offset:3px}
  .chapter-page .rms-file-uploader{margin:0}.chapter-page .rms-uploader-label{color:#281B33;font-size:12px;font-weight:750}.chapter-page .rms-uploader-description{color:#857A90;font-size:10px}.chapter-page .rms-uploader-dropzone{padding:40px 24px;border-color:#D3BDEB;border-radius:15px;background:#F8F3FD}.chapter-page .rms-uploader-dropzone:hover{border-color:#5B1EBC;background:#F1E9FB}.chapter-page .rms-uploader-file-item{border-color:#D9CBE7;border-radius:12px}.chapter-page .rms-uploader-prompt{color:#281B33}.chapter-page .rms-uploader-formats{color:#857A90}
  @keyframes chapterEnter{from{opacity:0;transform:translateY(13px)}to{opacity:1;transform:translateY(0)}}@media(prefers-reduced-motion:no-preference){.chapter-hero,.chapter-page .alert,.chapter-page .picker-item,.chapter-page form>.card,.chapter-page form>div:last-child{animation:chapterEnter .48s cubic-bezier(.16,1,.3,1) both}.chapter-page .picker-item:nth-child(2),.chapter-page form>.card:nth-of-type(2){animation-delay:.05s}.chapter-page .picker-item:nth-child(3),.chapter-page form>.card:nth-of-type(3){animation-delay:.1s}.chapter-page .picker-item:nth-child(4){animation-delay:.15s}.chapter-page .picker-item:nth-child(5){animation-delay:.2s}}
  @media(max-width:760px){.chapter-hero{grid-template-columns:1fr;padding:30px 24px}.chapter-hero-guide{padding:22px 0 0;border-top:1px solid rgba(255,255,255,.24);border-left:0}.chapter-page form>.card:first-of-type .card-body{grid-template-columns:1fr 1fr}.chapter-page form>.card:first-of-type .card-body>div:nth-child(odd){padding-left:0;border-left:0}.chapter-page form>.card .card-header,.chapter-page form>.card .card-body{padding-right:20px;padding-left:20px}.chapter-page form>div:last-child:not(.card){display:grid!important;grid-template-columns:1fr 1fr}.chapter-page form>div:last-child:not(.card) .btn-primary{grid-column:1/-1}.chapter-page .picker-grid{grid-template-columns:1fr}}
  @media(max-width:500px){.chapter-hero{padding:27px 21px}.chapter-guide-steps{grid-template-columns:1fr}.chapter-page form>.card:first-of-type .card-body{grid-template-columns:1fr}.chapter-page form>.card:first-of-type .card-body>div{padding:12px 0;border-bottom:1px solid #EAE3EF;border-left:0}.chapter-page form>.card:first-of-type .card-body>div:last-child{border-bottom:0}.chapter-page form>div:last-child:not(.card){grid-template-columns:1fr}.chapter-page form>div:last-child:not(.card) .btn-primary{grid-column:auto}.chapter-page .btn{width:100%}}
  @media(prefers-reduced-motion:reduce){.chapter-page *{animation:none!important;transition:none!important}}
</style>

<main class="chapter-page">
<nav class="chapter-breadcrumbs" aria-label="Breadcrumb" style="margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
  <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" style="color: #5B1EBC; text-decoration: none; font-size: 14px;">← Back to My Research</a>
  <?php if ($project): ?><span style="color: #64748B;">/</span><a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $project_id; ?>" style="color: #5B1EBC; text-decoration: none; font-size: 14px;">← Back to Project</a><?php endif; ?>
  <?php if ($sch_picker_mode === 'chapter' && $sch_resolved_project): ?>
    <span style="color: #64748B;">/</span>
    <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php" style="color: #5B1EBC; text-decoration: none; font-size: 14px;">← Switch project</a>
  <?php endif; ?>
</nav>

<section class="chapter-hero" aria-labelledby="chapter-workspace-title">
  <div class="chapter-hero-copy">
    <p class="chapter-eyebrow">
      <?php if ($sch_picker_mode === 'project'): ?>Chapter submission · Step 1
      <?php elseif ($sch_picker_mode === 'chapter'): ?>Chapter submission · Step 2
      <?php else: ?>Chapter writing workspace<?php endif; ?>
    </p>
    <h1 id="chapter-workspace-title">
      <?php if ($sch_picker_mode === 'project'): ?>Choose the research you want to continue.
      <?php elseif ($sch_picker_mode === 'chapter' && $sch_resolved_project): ?>Select the chapter you’re ready to work on.
      <?php elseif ($sch_picker_mode === 'chapter'): ?>That project is not available.
      <?php elseif ($invalid_chapter): ?>Choose a valid research chapter.
      <?php elseif (!$project): ?>We could not open this project.
      <?php else: ?>Build Chapter <?php echo (int) $chapter_number; ?> with a clear review path.<?php endif; ?>
    </h1>
    <p class="chapter-hero-description">
      <?php if ($sch_picker_mode === 'project'): ?>Start with a project you own or collaborate on, then select one of its five chapters.
      <?php elseif ($sch_picker_mode === 'chapter' && $sch_resolved_project): ?>Choose from the five-part EARIST research structure for <?php echo htmlspecialchars((string) $sch_resolved_project['title']); ?>.
      <?php elseif ($project): ?>Write directly in the workspace or attach a formatted document, then save a draft or send it for faculty review.
      <?php else: ?>Return to your research list and choose another project to continue.<?php endif; ?>
    </p>
  </div>
  <div class="chapter-hero-guide">
    <p class="chapter-guide-label">Chapter submission flow</p>
    <ol class="chapter-guide-steps">
      <li class="<?php echo $sch_picker_mode === 'project' ? 'is-current' : ''; ?>"><span>01</span> Choose a project</li>
      <li class="<?php echo $sch_picker_mode === 'chapter' ? 'is-current' : ''; ?>"><span>02</span> Select a chapter</li>
      <li class="<?php echo $sch_picker_mode === '' && $project && !$invalid_chapter ? 'is-current' : ''; ?>"><span>03</span> Write or upload</li>
    </ol>
  </div>
</section>

<?php if ($sch_picker_mode === 'project'): ?>
  <h1 style="margin: 0 0 8px; color: #111827; font-size: 28px;">Choose a project</h1>
  <p style="margin: 0 0 24px; color: #64748B;">Pick one of your research projects to continue. You can submit chapters to any project you own or are a member of.</p>
  <?php if (empty($sch_student_projects)): ?>
    <div class="card picker-empty">
      <div class="ico">📭</div>
      <h3 style="margin: 0 0 8px; color: #111827;">No research projects yet</h3>
      <p style="margin: 0 0 24px; color: #64748B;">Submit your first research proposal to start uploading chapters.</p>
      <a href="<?php echo SITE_URL; ?>pages/student/submit-research.php" class="btn btn-primary">+ Submit Your First Research</a>
    </div>
  <?php else: ?>
    <div class="picker-grid">
      <?php foreach ($sch_student_projects as $p):
        $ppid    = (int) ($p['project_id'] ?? 0);
        $ptitle  = (string) ($p['title'] ?? 'Untitled project');
        $pstatus = (string) ($p['status'] ?? '');
        $pcat    = (string) ($p['category_name'] ?? '');
        $pay     = (string) ($p['ay_label'] ?? '');
        $pupd    = (string) ($p['updated_at'] ?? '');
        $pupd_lbl = '';
        if ($pupd !== '' && $pupd !== '0000-00-00 00:00:00') {
            $pupd_ts = strtotime($pupd);
            if ($pupd_ts) $pupd_lbl = date('M d, Y', $pupd_ts);
        }
        $status_class = '';
        $status_label = ucwords(str_replace('_', ' ', $pstatus));
        if (in_array($pstatus, ['approved','ongoing'], true))      { $status_class = 'badge-success'; }
        elseif (in_array($pstatus, ['submitted','under_review','under_crec_review','under_erec_review'], true)) { $status_class = 'badge-info'; }
        elseif (in_array($pstatus, ['for_revision','revision_required'], true)) { $status_class = 'badge-warning'; }
        elseif ($pstatus === 'completed') { $status_class = 'badge-success'; }
        elseif ($pstatus === 'archived')  { $status_class = 'badge'; }
        else                              { $status_class = 'badge'; }
      ?>
        <a class="picker-item" href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $ppid; ?>">
          <div class="picker-item-head">
            <div>
              <div class="picker-item-title"><?php echo htmlspecialchars($ptitle); ?></div>
              <div class="picker-item-meta">
                <?php if ($pcat !== ''): ?>📚 <?php echo htmlspecialchars($pcat); ?><?php endif; ?>
                <?php if ($pay !== ''): ?><?php echo $pcat !== '' ? ' · ' : ''; ?>🎓 <?php echo htmlspecialchars($pay); ?><?php endif; ?>
              </div>
            </div>
            <?php if ($status_label !== ''): ?>
              <span class="badge <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status_label); ?></span>
            <?php endif; ?>
          </div>
          <div class="picker-item-sub">Click to choose a chapter →</div>
          <?php if ($pupd_lbl !== ''): ?>
            <div class="picker-item-sub">Last updated: <?php echo htmlspecialchars($pupd_lbl); ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($sch_picker_mode === 'chapter' && $sch_resolved_project): ?>
  <h1 style="margin: 0 0 8px; color: #111827; font-size: 28px;">Choose a chapter</h1>
  <p style="margin: 0 0 24px; color: #64748B;">Pick a chapter of <strong><?php echo htmlspecialchars((string) $sch_resolved_project['title']); ?></strong> to upload or edit. Each chapter follows the EARIST Research Manual 2015 structure.</p>
  <div class="picker-grid">
    <?php for ($n = 1; $n <= 5; $n++):
      $ch_row = $sch_project_chapters[$n] ?? null;
      $ch_status = strtolower((string) ($ch_row['status'] ?? ''));
      $ch_status_label = $ch_status !== '' ? ucwords(str_replace('_', ' ', $ch_status)) : 'Not Started';
      $ch_num_class = $ch_status !== '' ? $ch_status : 'draft';
      $ch_version = (int) ($ch_row['version'] ?? 0);
    ?>
      <a class="picker-item" href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $project_id; ?>&chapter=<?php echo (int) $n; ?>">
        <div class="picker-item-head">
          <div class="picker-num <?php echo htmlspecialchars($ch_num_class); ?>"><?php echo (int) $n; ?></div>
          <div style="text-align: right;">
            <span class="badge"><?php echo htmlspecialchars($ch_status_label); ?></span>
            <?php if ($ch_version > 0): ?>
              <div class="picker-item-sub">v<?php echo (int) $ch_version; ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="picker-item-title"><?php echo htmlspecialchars((string) ($chapter_titles[$n] ?? ('Chapter ' . $n))); ?></div>
        <div class="picker-item-sub">Click to <?php echo $ch_status === 'approved' ? 'view' : 'open'; ?> this chapter →</div>
      </a>
    <?php endfor; ?>
  </div>

<?php elseif ($sch_picker_mode === 'chapter'): ?>
  <div class="card picker-empty">
    <div class="ico">🔍</div>
    <h3 style="margin: 0 0 8px; color: #111827;">Project not found</h3>
    <p style="margin: 0 0 24px; color: #64748B;">The project you're trying to access doesn't exist or you no longer have access to it.</p>
    <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php" class="btn btn-primary">← Choose another project</a>
  </div>

<?php elseif ($invalid_chapter): ?>
  <div class="card picker-empty">
    <div class="ico">📑</div>
    <h3 style="margin: 0 0 8px; color: #111827;">Invalid chapter</h3>
    <p style="margin: 0 0 24px; color: #64748B;">Choose a chapter number from 1 to 5.</p>
    <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $project_id; ?>" class="btn btn-primary">← Back to chapter picker</a>
  </div>
<?php elseif (!$project): ?>
  <div class="card picker-empty">
    <div class="ico">🔍</div>
    <h3 style="margin: 0 0 8px; color: #111827;">Project not found</h3>
    <p style="margin: 0 0 24px; color: #64748B;">The research project you're looking for doesn't exist or you don't have permission to view it.</p>
    <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php" class="btn btn-primary">← Choose a project</a>
  </div>
<?php else: ?>
  <h1 style="margin: 0 0 8px; color: #111827; font-size: 28px;"><?php echo htmlspecialchars($chapter_titles[$chapter_number]); ?></h1>
  <p style="margin: 0 0 20px; color: #64748B;"><?php echo htmlspecialchars($project['title']); ?> · <?php if ($current_status): ?><span class="<?php echo htmlspecialchars($status_badge['class']); ?>" <?php echo $status_badge['style'] ? 'style="' . htmlspecialchars($status_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $current_status)); ?></span><?php else: ?><span class="badge">Not Started</span><?php endif; ?> · Version <?php echo (int) ($chapter['version'] ?? 1); ?></p>

  <?php if ($success): ?><div class="alert alert-success"><strong>Chapter updated.</strong> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-error"><ul style="margin: 0; padding-left: 20px;"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>"><input type="hidden" name="chapter" value="<?php echo $chapter_number; ?>">
    <?php echo csrfField(); ?>

    <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter Information</div></div><div class="card-body">
      <div><strong>Project</strong><div><?php echo htmlspecialchars($project['title']); ?></div></div>
      <div><strong>Chapter</strong><div><?php echo htmlspecialchars($chapter_titles[$chapter_number]); ?></div></div>
      <div><strong>Status</strong><div><?php if ($current_status): ?><span class="<?php echo htmlspecialchars($status_badge['class']); ?>" <?php echo $status_badge['style'] ? 'style="' . htmlspecialchars($status_badge['style']) . '"' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $current_status)); ?></span><?php else: ?><span class="badge">Not Started</span><?php endif; ?></div></div>
      <div><strong>Version</strong><div>Version <?php echo (int) ($chapter['version'] ?? 1); ?></div></div>
      <?php if ($chapter): ?><div><strong>Last updated</strong><div><?php echo !empty($chapter['updated_at']) ? date('M d, Y', strtotime($chapter['updated_at'])) : 'N/A'; ?></div></div><?php endif; ?>
    </div></div>

    <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter Content</div></div><div class="card-body" style="display: block;">
      <?php foreach ($chapter_fields[$chapter_number] as $field => $label): ?><div class="form-group"><label class="form-label" for="<?php echo $field; ?>"><?php echo htmlspecialchars($label); ?></label><textarea id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="form-control" rows="8" placeholder="Write your content here..." <?php echo $can_edit_chapter ? '' : 'disabled'; ?>><?php echo htmlspecialchars($content[$field] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea><small class="text-muted"><?php echo $can_edit_chapter ? 'Add your content for this section. You may continue editing it later.' : 'This chapter is locked while it is awaiting review or has already been approved.'; ?></small></div><?php endforeach; ?>
    </div></div>

    <div class="card" style="margin-bottom: 20px;"><div class="card-header"><div class="card-title">Chapter File</div></div><div class="card-body" style="display: block;">
      <?php echo renderFileUploader([
          'inputName' => 'chapter_file',
          'accept' => '.pdf,.doc,.docx',
          'maxSize' => 10000,
          'folderTarget' => 'chapters',
          'label' => 'Upload formatted chapter',
          'description' => 'Drag and drop a PDF, DOC, or DOCX file, or click to browse',
          'allowedFormatsText' => 'PDF, DOC, DOCX • Max 10 MB',
          'required' => false,
          'disabled' => !$can_edit_chapter,
          'projectId' => $project_id,
          'chapterId' => $chapter['chapter_id'] ?? null
      ]); ?>
      <small class="text-muted">A document is optional when saving a draft. Add written content or a file before submitting for review.</small>
      <?php if ($current_upload): ?><div style="margin-top: 14px;">📄 Current uploaded file: <a href="<?php echo htmlspecialchars($current_upload['file_path']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($current_upload['original_name']); ?></a><?php if ($can_edit_chapter): ?><div style="color: #64748B; font-size: 13px;">Uploading a new file will replace this.</div><?php endif; ?></div><?php endif; ?>
    </div></div>

    <div style="display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;">
      <a href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary"><?php echo $can_edit_chapter ? 'Cancel' : 'Back to Project'; ?></a>
      <?php if ($can_edit_chapter): ?>
        <button type="submit" name="save_draft" class="btn btn-secondary">Save as Draft</button>
        <button type="submit" name="submit_review" class="btn btn-primary"><?php echo $current_status === 'revision_required' ? 'Resubmit for Review' : 'Submit for Review'; ?></button>
      <?php elseif ($can_withdraw_chapter): ?>
        <button type="button" class="btn btn-secondary" disabled>Awaiting Review</button>
        <button type="submit" name="withdraw_submission" class="btn" style="background: #EA580C; border-color: #EA580C; color: #fff;" onclick="return confirm('Withdraw this chapter submission and reopen it for editing?');">Withdraw &amp; Edit</button>
      <?php elseif (in_array($current_status, ['submitted', 'under_review'], true)): ?>
        <button type="button" class="btn btn-secondary" disabled>Awaiting Review</button>
      <?php elseif ($current_status === 'approved'): ?>
        <button type="button" class="btn btn-secondary" disabled>Chapter Approved</button>
      <?php endif; ?>
    </div>
  </form>
<?php endif; ?>

</main>

<script src="<?php echo SITE_URL; ?>js/file-uploader.js"></script>
<script src="../../js/app-forms.js" defer></script>

<?php renderStudentShellClose(); ?>
