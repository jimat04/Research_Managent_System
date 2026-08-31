<?php
/**
 * Student — Submit Milestone Document
 *
 * Lets a student submit Research-Manual milestone documents/reports:
 *   - MOU  (Memorandum of Understanding)        → research_documents
 *   - NDA  (Non-Disclosure Agreement)          → research_documents
 *   - Midway Progress Report                    → research_reports
 *   - Terminal Report                           → research_reports
 *
 * The four tables (`research_documents`, `research_reports`) already exist;
 * this page is the first place that actually INSERTs/UPDATEs them from a
 * student upload. The companion `progress-tracking.php` only reads them.
 *
 * Storage layout (mirrors submit-chapter.php):
 *   - Files go to uploads/milestones/<safe_name>
 *   - A row is written to `uploads` (type = 'other')
 *   - For MOU/NDA:    `research_documents` row is INSERTed (first time) or
 *                     UPDATEd to point at the new upload_id + status='submitted'
 *   - For Midway/Terminal: a `research_documents` row is also written
 *                     (document_type='progress_report'/'terminal_report') so the
 *                     file is properly tracked, and the matching
 *                     `research_reports` row is INSERTed or UPDATEd to point
 *                     at the document_id and set status='submitted'.
 *   - Re-uploading after `rejected` replaces the file reference and resets
 *     status to 'submitted' (and clears reviewed_at/reviewed_by).
 *
 * Advisers (project_advisers.adviser_id) are notified via createNotification()
 * using the same best-effort pattern as messages.php.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

// ── local helpers (escape + display) ─────────────────────────────────────
function msub_se($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function msub_status_label($key) {
    $map = [
        'pending'            => 'Pending',
        'submitted'          => 'Submitted',
        'under_review'       => 'Under Review',
        'under_crec_review'  => 'CREC Review',
        'under_erec_review'  => 'EREC Review',
        'for_revision'       => 'For Revision',
        'revision_required'  => 'Revision Required',
        'approved'           => 'Approved',
        'ongoing'            => 'Ongoing',
        'completed'          => 'Completed',
        'archived'           => 'Archived',
        'rejected'           => 'Rejected',
        'waived'             => 'Waived',
        'draft'              => 'Draft',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
}

function msub_status_class($key) {
    $map = [
        'pending'            => 'slate',
        'submitted'          => 'blue',
        'under_review'       => 'blue',
        'under_crec_review'  => 'blue',
        'under_erec_review'  => 'violet',
        'for_revision'       => 'orange',
        'revision_required'  => 'orange',
        'approved'           => 'green',
        'ongoing'            => 'green',
        'completed'          => 'emerald',
        'archived'           => 'slate',
        'rejected'           => 'orange',
        'waived'             => 'slate',
        'draft'              => 'slate',
    ];
    return $map[$key] ?? 'slate';
}

// ── defensive schema detection ───────────────────────────────────────────
// research_projects.deleted_at may or may not exist (added by migration).
$rp_has_deleted_at = false;
$rp_check = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
if ($rp_check) {
    $rp_check->execute();
    $rp_has_deleted_at = $rp_check->get_result()->num_rows > 0;
    $rp_check->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// uploads.deleted_at may or may not exist.
$uploads_have_deleted_at = false;
$uc_check = $conn->prepare("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
if ($uc_check) {
    $uc_check->execute();
    $uploads_have_deleted_at = $uc_check->get_result()->num_rows > 0;
    $uc_check->close();
}

// research_documents / research_reports may not exist on every install.
function msub_table_exists($conn, $name) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $name)) {
        return false;
    }
    $sql = "SHOW TABLES LIKE '" . str_replace("'", "''", (string) $name) . "'";
    $res = $conn->query($sql);
    $exists = $res instanceof mysqli_result && $res->num_rows > 0;
    if ($res instanceof mysqli_result) { $res->free(); }
    return $exists;
}
$tbl_documents = msub_table_exists($conn, 'research_documents');
$tbl_reports   = msub_table_exists($conn, 'research_reports');

// research_reports columns — detect presence so we can build a safe UPDATE.
$rr_has_document_id = false;
if ($tbl_reports) {
    $col_check = $conn->prepare("SHOW COLUMNS FROM research_reports LIKE 'document_id'");
    if ($col_check) {
        $col_check->execute();
        $rr_has_document_id = $col_check->get_result()->num_rows > 0;
        $col_check->close();
    }
}

// project_advisers may not exist (older installs).
$tbl_advisers = msub_table_exists($conn, 'project_advisers');

// ── project_id (validated) ───────────────────────────────────────────────
$project_id = isset($_GET['project_id']) ? (int) $_GET['project_id']
         : (isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0);

// ── fetch all student's projects (owned OR member) for the switcher ─────
$projects = [];
$ps = $conn->prepare("
    SELECT rp.project_id, rp.title, rp.status
    FROM research_projects rp
    WHERE (rp.created_by = ? OR rp.project_id IN (
            SELECT project_id FROM project_members WHERE user_id = ?
        ))" . $rp_deleted_filter . "
    ORDER BY rp.updated_at DESC
");
if ($ps) {
    $ps->bind_param('ii', $user_id, $user_id);
    $ps->execute();
    $r = $ps->get_result();
    while ($row = $r->fetch_assoc()) {
        $projects[] = $row;
    }
    $ps->close();
}

// Validate ?project_id= against owned/member list.
$project = null;
foreach ($projects as $p) {
    if ((int) $p['project_id'] === $project_id) { $project = $p; break; }
}
if (!$project && !empty($projects)) {
    $project = $projects[0];
    $project_id = (int) $project['project_id'];
}

// ── load the four milestone rows for the selected project ───────────────
// $slots: keyed by slot id; each has display label + storage target.
$slots = [
    'mou' => [
        'label'        => 'Memorandum of Understanding (MOU)',
        'doc_type'     => 'mou',
        'report_type'  => null,
        'description'  => 'A signed agreement between EARIST and the partner institution/agency where the research will be conducted.',
        'doc_row'      => null,
        'report_row'   => null,
        'upload_row'   => null,
    ],
    'nda' => [
        'label'        => 'Non-Disclosure Agreement (NDA)',
        'doc_type'     => 'nda',
        'report_type'  => null,
        'description'  => 'Confidentiality agreement covering data, results, and other sensitive information gathered during the study.',
        'doc_row'      => null,
        'report_row'   => null,
        'upload_row'   => null,
    ],
    'midway' => [
        'label'        => 'Midway Progress Report',
        'doc_type'     => 'progress_report',
        'report_type'  => 'midway_progress',
        'description'  => 'Status update on data gathering, preliminary findings, and any issues encountered halfway through implementation.',
        'doc_row'      => null,
        'report_row'   => null,
        'upload_row'   => null,
    ],
    'terminal' => [
        'label'        => 'Terminal Report',
        'doc_type'     => 'terminal_report',
        'report_type'  => 'terminal',
        'description'  => 'Final written report covering all five chapters, conclusions, and recommendations, submitted before the defense.',
        'doc_row'      => null,
        'report_row'   => null,
        'upload_row'   => null,
    ],
];

if ($project && $project_id > 0) {
    // Existing research_documents rows for this project.
    $doc_by_type = [];
    if ($tbl_documents) {
        $ds = $conn->prepare("
            SELECT document_id, document_type, status, remarks,
                   submitted_by, submitted_at, reviewed_by, reviewed_at, upload_id
            FROM research_documents
            WHERE project_id = ?
            ORDER BY document_id ASC
        ");
        if ($ds) {
            $ds->bind_param('i', $project_id);
            $ds->execute();
            $r = $ds->get_result();
            while ($row = $r->fetch_assoc()) {
                $doc_by_type[(string) $row['document_type']] = $row;
            }
            $ds->close();
        }
    }

    // Existing research_reports rows for this project.
    $rep_by_type = [];
    if ($tbl_reports) {
        $rs = $conn->prepare("
            SELECT report_id, report_type, status, summary,
                   submitted_at, reviewed_at, document_id
            FROM research_reports
            WHERE project_id = ?
        ");
        if ($rs) {
            $rs->bind_param('i', $project_id);
            $rs->execute();
            $r = $rs->get_result();
            while ($row = $r->fetch_assoc()) {
                $rep_by_type[(string) $row['report_type']] = $row;
            }
            $rs->close();
        }
    }

    // Stitch the rows into $slots.
    foreach ($slots as $sid => $slot) {
        $slots[$sid]['doc_row']    = $doc_by_type[$slot['doc_type']]    ?? null;
        if ($slot['report_type']) {
            $slots[$sid]['report_row'] = $rep_by_type[$slot['report_type']] ?? null;
        }
    }

    // Look up the file for each slot's doc.upload_id (skip deleted ones).
    $upload_ids = [];
    foreach ($slots as $slot) {
        if (!empty($slot['doc_row']['upload_id'])) {
            $upload_ids[] = (int) $slot['doc_row']['upload_id'];
        }
    }
    if (!empty($upload_ids) && $tbl_documents) {
        $uploads_by_id = [];
        $placeholders = implode(',', array_fill(0, count($upload_ids), '?'));
        $types = str_repeat('i', count($upload_ids));
        $uploads_where = $uploads_have_deleted_at ? ' AND deleted_at IS NULL' : '';
        $us = $conn->prepare("
            SELECT upload_id, original_name, file_name, file_path, file_size, mime_type, upload_date
            FROM uploads
            WHERE upload_id IN ($placeholders)" . $uploads_where
        );
        if ($us) {
            $us->bind_param($types, ...$upload_ids);
            $us->execute();
            $r = $us->get_result();
            while ($row = $r->fetch_assoc()) {
                $uploads_by_id[(int) $row['upload_id']] = $row;
            }
            $us->close();
        }
        // Re-stitch: assign each doc row's matching upload back to its slot.
        foreach ($slots as $sid => $slot) {
            $uid = !empty($slot['doc_row']['upload_id']) ? (int) $slot['doc_row']['upload_id'] : 0;
            $slots[$sid]['upload_row'] = $uid > 0 ? ($uploads_by_id[$uid] ?? null) : null;
        }
    }
}

// ── POST handling ────────────────────────────────────────────────────────
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form has expired. Please try again.';
    } else {
        $post_project = (int) ($_POST['project_id'] ?? 0);
        $slot_key     = isset($_POST['slot']) ? (string) $_POST['slot'] : '';
        $remarks      = trim((string) ($_POST['remarks'] ?? ''));

        // Re-validate the project against the user's owned/member list
        // (a tampered POST project_id must not slip through).
        $post_project_valid = false;
        foreach ($projects as $p) {
            if ((int) $p['project_id'] === $post_project) {
                $post_project_valid = true;
                break;
            }
        }
        if (!$post_project_valid) {
            $errors[] = 'Invalid project. Please pick a project you own or are a member of.';
        } elseif (!isset($slots[$slot_key])) {
            $errors[] = 'Invalid milestone. Please pick a valid milestone slot.';
        } else {
            $slot = $slots[$slot_key];
            $project_id = $post_project;

            // ── file validation ─────────────────────────────────────────
            $file_uploaded = isset($_FILES['milestone_file'])
                && is_array($_FILES['milestone_file'])
                && $_FILES['milestone_file']['error'] !== UPLOAD_ERR_NO_FILE;
            $file_valid  = false;
            $ext          = '';
            $detected_mime = '';
            $tmp_path     = '';
            $orig_name    = '';
            $file_size    = 0;

            if (!$file_uploaded) {
                $errors[] = 'Please attach a file for this milestone.';
            } else {
                $f = $_FILES['milestone_file'];
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'File upload error. Please try again.';
                } else {
                    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) {
                        $errors[] = 'Invalid file format. Accepted formats: PDF, DOC, DOCX.';
                    } elseif ((int) $f['size'] > 10 * 1024 * 1024) {
                        $errors[] = 'File size must not exceed 10 MB.';
                    } else {
                        // Server-side MIME detection (mirrors file-uploader.php pattern).
                        if (function_exists('finfo_open')) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $detected_mime = (string) $finfo->file((string) $f['tmp_name']);
                        }
                        $allowed_mimes = [
                            'pdf'  => ['application/pdf'],
                            'doc'  => ['application/msword', 'application/octet-stream'],
                            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
                        ];
                        if ($detected_mime !== '' && isset($allowed_mimes[$ext])
                            && !in_array($detected_mime, $allowed_mimes[$ext], true)) {
                            $errors[] = 'File appears to be corrupted or is not a valid ' . strtoupper($ext) . ' file.';
                        } else {
                            $file_valid   = true;
                            $tmp_path     = (string) $f['tmp_name'];
                            $orig_name    = (string) $f['name'];
                            $file_size    = (int) $f['size'];
                        }
                    }
                }
            }

            // Trim remarks length defensively.
            if (mb_strlen($remarks) > 1000) {
                $remarks = mb_substr($remarks, 0, 1000);
            }

            // ── write the upload, document, and report rows ─────────────
            if (empty($errors) && $file_valid && $tbl_documents) {
                $conn->begin_transaction();
                try {
                    // 1) Move the file into uploads/milestones/.
                    $milestones_dir = __DIR__ . '/../../uploads/milestones';
                    if (!is_dir($milestones_dir) && !@mkdir($milestones_dir, 0755, true)) {
                        throw new Exception('Unable to create the milestone upload directory.');
                    }
                    $safe_name = uniqid('ms_', true) . '.' . $ext;
                    $full_path = $milestones_dir . '/' . $safe_name;
                    if (!move_uploaded_file($tmp_path, $full_path)) {
                        throw new Exception('Unable to save the uploaded file.');
                    }
                    @chmod($full_path, 0644);
                    $relative_path = 'uploads/milestones/' . $safe_name;

                    // 2) Insert a row into `uploads` (so the file is tracked).
                    $upload_insert = $conn->prepare("
                        INSERT INTO uploads
                            (project_id, chapter_id, uploaded_by, type,
                             original_name, file_name, file_path, file_size, mime_type, upload_date)
                        VALUES (?, NULL, ?, 'other', ?, ?, ?, ?, ?, NOW())
                    ");
                    if (!$upload_insert) {
                        throw new Exception('Unable to prepare upload record.');
                    }
                    $mime_for_db = $detected_mime !== '' ? $detected_mime : (string) $f['type'];
                    $upload_insert->bind_param(
                        'iisssis',
                        $project_id,
                        $user_id,
                        $orig_name,
                        $safe_name,
                        $relative_path,
                        $file_size,
                        $mime_for_db
                    );
                    if (!$upload_insert->execute()) {
                        throw new Exception('Unable to save the upload record.');
                    }
                    $new_upload_id = (int) $conn->insert_id;
                    $upload_insert->close();

                    // 3) Insert or UPDATE the research_documents row.
                    $existing_doc = $slot['doc_row'];
                    if ($existing_doc && !empty($existing_doc['document_id'])) {
                        $doc_id = (int) $existing_doc['document_id'];
                        // Re-upload after rejection (or any re-submit): point at the new
                        // upload, reset to 'submitted', clear review fields, refresh
                        // submitted_by/at, replace remarks.
                        $upd_doc = $conn->prepare("
                            UPDATE research_documents
                            SET upload_id = ?,
                                status = 'submitted',
                                remarks = ?,
                                submitted_by = ?,
                                submitted_at = NOW(),
                                reviewed_by = NULL,
                                reviewed_at = NULL
                            WHERE document_id = ?
                        ");
                        if (!$upd_doc) {
                            throw new Exception('Unable to prepare document update.');
                        }
                        $upd_doc->bind_param('isii', $new_upload_id, $remarks, $user_id, $doc_id);
                        if (!$upd_doc->execute()) {
                            throw new Exception('Unable to update the document record.');
                        }
                        $upd_doc->close();
                    } else {
                        $ins_doc = $conn->prepare("
                            INSERT INTO research_documents
                                (project_id, upload_id, document_type, status, remarks,
                                 submitted_by, submitted_at, created_at, updated_at)
                            VALUES (?, ?, ?, 'submitted', ?, ?, NOW(), NOW(), NOW())
                        ");
                        if (!$ins_doc) {
                            throw new Exception('Unable to prepare document insert.');
                        }
                        $ins_doc->bind_param(
                            'iissi',
                            $project_id,
                            $new_upload_id,
                            $slot['doc_type'],
                            $remarks,
                            $user_id
                        );
                        if (!$ins_doc->execute()) {
                            throw new Exception('Unable to save the document record.');
                        }
                        $doc_id = (int) $conn->insert_id;
                        $ins_doc->close();
                    }

                    // 4) For Midway / Terminal: also write the research_reports row.
                    if (!empty($slot['report_type']) && $tbl_reports) {
                        $existing_rep = $slot['report_row'];
                        $summary_text = $remarks !== '' ? $remarks : null;

                        if ($existing_rep && !empty($existing_rep['report_id'])) {
                            $rep_id = (int) $existing_rep['report_id'];
                            if ($rr_has_document_id) {
                                $upd_rep = $conn->prepare("
                                    UPDATE research_reports
                                    SET document_id = ?,
                                        status = 'submitted',
                                        summary = ?,
                                        submitted_at = NOW(),
                                        reviewed_at = NULL,
                                        reviewed_by = NULL
                                    WHERE report_id = ?
                                ");
                                if (!$upd_rep) {
                                    throw new Exception('Unable to prepare report update.');
                                }
                                $upd_rep->bind_param('isi', $doc_id, $summary_text, $rep_id);
                            } else {
                                $upd_rep = $conn->prepare("
                                    UPDATE research_reports
                                    SET status = 'submitted',
                                        summary = ?,
                                        submitted_at = NOW(),
                                        reviewed_at = NULL,
                                        reviewed_by = NULL
                                    WHERE report_id = ?
                                ");
                                if (!$upd_rep) {
                                    throw new Exception('Unable to prepare report update.');
                                }
                                $upd_rep->bind_param('si', $summary_text, $rep_id);
                            }
                            if (!$upd_rep->execute()) {
                                throw new Exception('Unable to update the report record.');
                            }
                            $upd_rep->close();
                        } else {
                            if ($rr_has_document_id) {
                                $ins_rep = $conn->prepare("
                                    INSERT INTO research_reports
                                        (project_id, report_type, status, summary, document_id,
                                         submitted_at, created_at, updated_at)
                                    VALUES (?, ?, 'submitted', ?, ?, NOW(), NOW(), NOW())
                                ");
                                if (!$ins_rep) {
                                    throw new Exception('Unable to prepare report insert.');
                                }
                                $ins_rep->bind_param(
                                    'issi',
                                    $project_id,
                                    $slot['report_type'],
                                    $summary_text,
                                    $doc_id
                                );
                            } else {
                                $ins_rep = $conn->prepare("
                                    INSERT INTO research_reports
                                        (project_id, report_type, status, summary,
                                         submitted_at, created_at, updated_at)
                                    VALUES (?, ?, 'submitted', ?, NOW(), NOW(), NOW())
                                ");
                                if (!$ins_rep) {
                                    throw new Exception('Unable to prepare report insert.');
                                }
                                $ins_rep->bind_param(
                                    'iss',
                                    $project_id,
                                    $slot['report_type'],
                                    $summary_text
                                );
                            }
                            if (!$ins_rep->execute()) {
                                throw new Exception('Unable to save the report record.');
                            }
                            $ins_rep->close();
                        }
                    }

                    logActivity(
                        'Submitted milestone: ' . $slot['label'],
                        'milestones'
                    );

                    // Best-effort adviser notification (mirrors messages.php pattern:
                    // createNotification() may return false if the notifications table
                    // is unavailable; we don't want the upload to fail in that case).
                    if ($tbl_advisers) {
                        $adv_stmt = $conn->prepare("
                            SELECT pa.adviser_id
                            FROM project_advisers pa
                            WHERE pa.project_id = ?
                        ");
                        if ($adv_stmt) {
                            $adv_stmt->bind_param('i', $project_id);
                            $adv_stmt->execute();
                            $adv_res = $adv_stmt->get_result();
                            $student_name = trim(
                                (string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')
                            );
                            $link = 'pages/student/submit-milestone.php?project_id=' . $project_id;
                            while ($adv_row = $adv_res->fetch_assoc()) {
                                $adv_id = (int) $adv_row['adviser_id'];
                                if ($adv_id > 0 && $adv_id !== $user_id) {
                                    createNotification(
                                        $adv_id,
                                        'Milestone submitted',
                                        ($student_name !== '' ? $student_name . ' submitted ' : 'A student submitted ')
                                            . $slot['label']
                                            . ' for "' . (string) $project['title'] . '".',
                                        'info',
                                        $link
                                    );
                                }
                            }
                            $adv_stmt->close();
                        }
                    }

                    $conn->commit();
                    $success = $slot['label'] . ' submitted successfully.';
                } catch (Exception $exception) {
                    $conn->rollback();
                    $errors[] = 'Unable to save the milestone. Please try again.';
                }
            } elseif (empty($errors) && !$tbl_documents) {
                $errors[] = 'The research_documents table is not available on this installation. Please contact the administrator.';
            }
        }
    }
}

// ── Re-load slots so the page shows the just-submitted data ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors) && $project && $project_id > 0) {
    $doc_by_type = [];
    if ($tbl_documents) {
        $ds = $conn->prepare("
            SELECT document_id, document_type, status, remarks,
                   submitted_by, submitted_at, reviewed_by, reviewed_at, upload_id
            FROM research_documents
            WHERE project_id = ?
            ORDER BY document_id ASC
        ");
        if ($ds) {
            $ds->bind_param('i', $project_id);
            $ds->execute();
            $r = $ds->get_result();
            while ($row = $r->fetch_assoc()) {
                $doc_by_type[(string) $row['document_type']] = $row;
            }
            $ds->close();
        }
    }
    $rep_by_type = [];
    if ($tbl_reports) {
        $rs = $conn->prepare("
            SELECT report_id, report_type, status, summary,
                   submitted_at, reviewed_at, document_id
            FROM research_reports
            WHERE project_id = ?
        ");
        if ($rs) {
            $rs->bind_param('i', $project_id);
            $rs->execute();
            $r = $rs->get_result();
            while ($row = $r->fetch_assoc()) {
                $rep_by_type[(string) $row['report_type']] = $row;
            }
            $rs->close();
        }
    }
    foreach ($slots as $sid => $slot) {
        $slots[$sid]['doc_row']    = $doc_by_type[$slot['doc_type']]    ?? null;
        if ($slot['report_type']) {
            $slots[$sid]['report_row'] = $rep_by_type[$slot['report_type']] ?? null;
        }
    }
    $upload_ids = [];
    foreach ($slots as $slot) {
        if (!empty($slot['doc_row']['upload_id'])) {
            $upload_ids[] = (int) $slot['doc_row']['upload_id'];
        }
    }
    if (!empty($upload_ids) && $tbl_documents) {
        $uploads_by_id = [];
        $placeholders = implode(',', array_fill(0, count($upload_ids), '?'));
        $types = str_repeat('i', count($upload_ids));
        $uploads_where = $uploads_have_deleted_at ? ' AND deleted_at IS NULL' : '';
        $us = $conn->prepare("
            SELECT upload_id, original_name, file_name, file_path, file_size, mime_type, upload_date
            FROM uploads
            WHERE upload_id IN ($placeholders)" . $uploads_where
        );
        if ($us) {
            $us->bind_param($types, ...$upload_ids);
            $us->execute();
            $r = $us->get_result();
            while ($row = $r->fetch_assoc()) {
                $uploads_by_id[(int) $row['upload_id']] = $row;
            }
            $us->close();
        }
        foreach ($slots as $sid => $slot) {
            $uid = !empty($slot['doc_row']['upload_id']) ? (int) $slot['doc_row']['upload_id'] : 0;
            $slots[$sid]['upload_row'] = $uid > 0 ? ($uploads_by_id[$uid] ?? null) : null;
        }
    }
}

// ── Page title ──────────────────────────────────────────────────────────
$page_title    = 'Submit Milestone Documents';
$page_subtitle = 'Upload the Research Manual 2015 milestone files (MOU, NDA, midway progress, terminal).';
if ($project) {
    $page_subtitle = 'Upload milestone files for "' . (string) $project['title'] . '".';
}

renderStudentShell($user, 'submit-milestone', $page_title, $page_subtitle);
?>

<style>
  .msub-page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 16px; flex-wrap: wrap; margin-bottom: 24px;
  }
  .msub-page-title { margin: 0 0 6px 0; color: #111827; font-size: 28px; font-weight: 600; }
  .msub-page-sub   { margin: 0; color: #64748B; font-size: 14px; }

  .msub-card {
    background: #ffffff; border: 1px solid #E5E7EB; border-radius: 20px;
    padding: 24px; margin-bottom: 20px;
  }
  .msub-card-title {
    font-size: 17px; font-weight: 700; color: #111827; margin: 0 0 4px 0;
  }
  .msub-card-sub {
    font-size: 13px; color: #64748B; margin: 0 0 16px 0;
  }

  .msub-switcher {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
  }
  .msub-switcher select {
    padding: 10px 14px; border: 1px solid #E5E7EB; border-radius: 10px;
    background: #ffffff; color: #111827; font-size: 14px; min-width: 280px;
  }
  .msub-switcher select:focus {
    outline: none; border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }

  .msub-slot {
    border: 1px solid #E5E7EB; border-radius: 16px; padding: 20px;
    background: #F8FAFC; margin-bottom: 16px;
  }
  .msub-slot-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; margin-bottom: 8px;
  }
  .msub-slot-name { font-size: 16px; font-weight: 700; color: #111827; margin: 0 0 2px 0; }
  .msub-slot-desc { font-size: 13px; color: #64748B; margin: 0; }
  .msub-slot-current {
    margin-top: 12px; padding: 12px 14px; background: #ffffff;
    border: 1px solid #E5E7EB; border-radius: 10px;
    font-size: 13px; color: #111827;
  }
  .msub-slot-current .label {
    font-size: 11px; color: #7C3AED; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 4px;
  }
  .msub-slot-row {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    font-size: 13px; color: #111827;
  }
  .msub-slot-row .label-text { color: #94A3B8; }

  .msub-form-group { margin-top: 14px; }
  .msub-form-label {
    display: block; font-size: 13px; font-weight: 600; color: #111827;
    margin-bottom: 6px;
  }
  .msub-form-control {
    width: 100%; padding: 10px 14px; border: 1px solid #E5E7EB;
    border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif;
    background: #ffffff; color: #111827; box-sizing: border-box;
  }
  .msub-form-control:focus {
    outline: none; border-color: #5B1EBC;
    box-shadow: 0 0 0 3px rgba(91, 30, 188, 0.1);
  }
  textarea.msub-form-control { resize: vertical; min-height: 80px; }
  .msub-help { font-size: 12px; color: #94A3B8; margin-top: 4px; }

  .msub-badge {
    display: inline-block; padding: 4px 12px; border-radius: 9999px;
    font-size: 12px; font-weight: 600; white-space: nowrap;
  }
  .msub-badge-slate   { background: #F1F5F9; color: #475569; }
  .msub-badge-blue    { background: #DBEAFE; color: #2563EB; }
  .msub-badge-violet  { background: #EDE9FE; color: #7C3AED; }
  .msub-badge-orange  { background: #FEF3C7; color: #EA580C; }
  .msub-badge-green   { background: #DCFCE7; color: #16A34A; }
  .msub-badge-emerald { background: #D1FAE5; color: #059669; }

  .msub-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px;
    border-radius: 10px; font-weight: 600; font-size: 14px; border: none;
    cursor: pointer; text-decoration: none; transition: all 0.2s;
    font-family: 'Inter', sans-serif;
  }
  .msub-btn-primary   { background: #5B1EBC; color: white; }
  .msub-btn-primary:hover {
    transform: translateY(-1px); box-shadow: 0 4px 12px rgba(91,30,188,0.3);
  }
  .msub-btn-primary:disabled {
    background: #C4B5FD; cursor: not-allowed; transform: none; box-shadow: none;
  }
  .msub-btn-secondary { background: #F8FAFC; color: #111827; border: 1px solid #E5E7EB; }
  .msub-btn-secondary:hover { background: #111827; color: white; }

  .msub-alert {
    padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;
    font-size: 14px; border: 1px solid;
  }
  .msub-alert-success { background: #DCFCE7; color: #16A34A; border-color: #86EFAC; }
  .msub-alert-error   { background: #FEE2E2; color: #DC2626; border-color: #FCA5A5; }
  .msub-alert ul { margin: 0; padding-left: 20px; }

  .msub-link { color: #5B1EBC; text-decoration: none; font-size: 13px; }
  .msub-link:hover { text-decoration: underline; }

  .msub-empty {
    background: #ffffff; border: 1px solid #E5E7EB; border-radius: 20px;
    padding: 48px 24px; text-align: center;
  }
  .msub-empty .ico { font-size: 56px; margin-bottom: 12px; }
  .msub-empty h3  { margin: 0 0 6px 0; color: #111827; font-size: 18px; }
  .msub-empty p   { margin: 0 0 20px 0; color: #64748B; font-size: 14px; }

  .msub-form-actions {
    display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px;
  }

  @media (max-width: 768px) {
    .msub-page-header { flex-direction: column; }
    .msub-switcher select { min-width: 0; width: 100%; }
  }
</style>

<?php if (empty($projects)): ?>
  <div class="msub-empty">
    <div class="ico">📭</div>
    <h3>No research projects yet</h3>
    <p>You need a research project before you can submit milestone documents. Submit your research proposal first.</p>
    <a class="msub-btn msub-btn-primary" href="<?php echo SITE_URL; ?>pages/student/submit-research.php">+ Submit Your Research</a>
  </div>
<?php else: ?>

  <div class="msub-page-header">
    <div>
      <h2 class="msub-page-title">Submit Milestone Documents</h2>
      <p class="msub-page-sub">EARIST Research Manual 2015 — MOU, NDA, Midway Progress Report, and Terminal Report.</p>
    </div>
    <form method="get" class="msub-switcher">
      <label for="project_id" style="font-size: 13px; color: #64748B; font-weight: 500;">Project:</label>
      <select id="project_id" name="project_id" onchange="this.form.submit()">
        <?php foreach ($projects as $p): ?>
          <option value="<?php echo (int) $p['project_id']; ?>"
            <?php echo ((int) $p['project_id'] === $project_id) ? 'selected' : ''; ?>>
            <?php echo msub_se($p['title']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="msub-btn msub-btn-primary" type="submit">View</button></noscript>
    </form>
  </div>

  <div style="margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
    <a class="msub-link" href="<?php echo SITE_URL; ?>pages/student/progress-tracking.php?project_id=<?php echo $project_id; ?>">← Back to Progress Tracking</a>
    <?php if ($project): ?>
      <span style="color: #94A3B8;">·</span>
      <a class="msub-link" href="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $project_id; ?>">View Project</a>
    <?php endif; ?>
  </div>

  <?php if ($success): ?>
    <div class="msub-alert msub-alert-success"><strong>Success.</strong> <?php echo msub_se($success); ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="msub-alert msub-alert-error">
      <strong>Please fix the following:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?php echo msub_se($e); ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php foreach ($slots as $slot_key => $slot):
    $doc    = $slot['doc_row'];
    $report = $slot['report_row'];
    $upl    = $slot['upload_row'];
    $status = '';
    if ($report) {
        $status = (string) $report['status'];
    } elseif ($doc) {
        $status = (string) $doc['status'];
    }
    $is_approved = in_array($status, ['approved'], true);
    $is_locked   = $is_approved; // do not allow re-upload once approved
    $is_existing = (bool) $doc;
    $submitted_at = $report['submitted_at'] ?? ($doc['submitted_at'] ?? null);
    $remarks      = $doc['remarks']        ?? ($report['summary'] ?? '');
    $label        = msub_status_label($status);
    $class        = msub_status_class($status);
  ?>
    <div class="msub-slot">
      <div class="msub-slot-head">
        <div>
          <h3 class="msub-slot-name"><?php echo msub_se($slot['label']); ?></h3>
          <p class="msub-slot-desc"><?php echo msub_se($slot['description']); ?></p>
        </div>
        <div>
          <?php if ($status !== ''): ?>
            <span class="msub-badge msub-badge-<?php echo msub_se($class); ?>"><?php echo msub_se($label); ?></span>
          <?php else: ?>
            <span class="msub-badge msub-badge-slate">Not Submitted</span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($is_existing): ?>
        <div class="msub-slot-current">
          <div class="label">Current submission</div>
          <?php if ($upl): ?>
            <div class="msub-slot-row">
              <span class="label-text">📄 File:</span>
              <a class="msub-link" href="<?php echo msub_se($upl['file_path']); ?>" target="_blank" rel="noopener">
                <?php echo msub_se($upl['original_name']); ?>
              </a>
              <span class="label-text">·</span>
              <span class="label-text"><?php echo number_format(((int) $upl['file_size']) / 1024, 1); ?> KB</span>
            </div>
          <?php endif; ?>
          <?php if ($submitted_at): ?>
            <div class="msub-slot-row">
              <span class="label-text">🕒 Submitted:</span>
              <span><?php echo msub_se(date('M d, Y h:i A', strtotime((string) $submitted_at))); ?></span>
            </div>
          <?php endif; ?>
          <?php if (!empty($doc['reviewed_at'])): ?>
            <div class="msub-slot-row">
              <span class="label-text">✅ Reviewed:</span>
              <span><?php echo msub_se(date('M d, Y', strtotime((string) $doc['reviewed_at']))); ?></span>
            </div>
          <?php endif; ?>
          <?php if ($remarks !== '' && $remarks !== null): ?>
            <div class="msub-slot-row" style="margin-top:6px;">
              <span class="label-text">💬 Remarks:</span>
              <span><?php echo msub_se((string) $remarks); ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($is_locked): ?>
        <div class="msub-slot-row" style="margin-top: 12px; color: #16A34A;">
          <span>✅ This milestone has been approved. No further submissions are needed.</span>
        </div>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" style="margin-top: 12px;">
          <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
          <input type="hidden" name="slot" value="<?php echo msub_se($slot_key); ?>">
          <?php echo csrfField(); ?>

          <div class="msub-form-group">
            <label class="msub-form-label" for="milestone_file_<?php echo msub_se($slot_key); ?>">
              <?php echo $is_existing ? 'Re-upload file' : 'Upload file'; ?>
              <span style="color:#DC2626;">*</span>
            </label>
            <input id="milestone_file_<?php echo msub_se($slot_key); ?>"
                   class="msub-form-control"
                   type="file"
                   name="milestone_file"
                   accept=".pdf,.doc,.docx"
                   required>
            <div class="msub-help">Accepted formats: PDF, DOC, DOCX · Maximum size: 10 MB.</div>
          </div>

          <div class="msub-form-group">
            <label class="msub-form-label" for="remarks_<?php echo msub_se($slot_key); ?>">Remarks (optional)</label>
            <textarea id="remarks_<?php echo msub_se($slot_key); ?>"
                      class="msub-form-control"
                      name="remarks"
                      rows="3"
                      placeholder="Add a short note for the adviser (e.g. context, partner agency, scope)"><?php
              echo msub_se((string) ($_POST['remarks'] ?? ($remarks ?? '')));
            ?></textarea>
          </div>

          <div class="msub-form-actions">
            <button type="submit" class="msub-btn msub-btn-primary">
              <?php
                $is_rejected_resubmit = $doc && strtolower((string) $doc['status']) === 'rejected';
                echo $is_rejected_resubmit
                    ? '↺ Resubmit'
                    : ($is_existing ? '↺ Replace & Resubmit' : '📤 Submit');
              ?>
            </button>
            <a class="msub-btn msub-btn-secondary"
               href="<?php echo SITE_URL; ?>pages/student/progress-tracking.php?project_id=<?php echo $project_id; ?>">
              Cancel
            </a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="msub-card">
    <h3 class="msub-card-title">Need help?</h3>
    <p class="msub-card-sub" style="margin-bottom: 0;">
      Files you upload here are visible to your adviser(s) and the research office for review.
      After rejection you can re-upload — your file and remarks will be replaced and the status
      will be reset to <em>Submitted</em>. Once approved, the milestone is locked.
    </p>
  </div>

<?php endif; ?>

<?php renderStudentShellClose(); ?>
