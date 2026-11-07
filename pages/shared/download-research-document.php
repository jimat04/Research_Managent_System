<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/document-preview.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/research-annotations.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    http_response_code(403);
    exit('Access denied.');
}

$documentType = (string) ($_GET['type'] ?? '');
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (!$documentId || !in_array($documentType, ['publication', 'copyright', 'research', 'engagement'], true)) {
    http_response_code(404);
    exit('Document not found.');
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
$mode = (string) ($_GET['mode'] ?? 'download');
if (!in_array($mode, ['download', 'viewer', 'inline'], true)) {
    http_response_code(400);
    exit('Invalid document request.');
}

if ($documentType === 'publication') {
    $stmt = $conn->prepare('SELECT researcher_id, publication_type, archival_status, deactivated_at, file_path, file_name, status FROM publications WHERE publication_id = ? LIMIT 1');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $staffScopes = [];
    if ($document) {
        $staffScopes = [['publication_dissemination', 'publication_records'], ['publication_dissemination', 'publication_reports']];
        if ($document['status'] === 'published' || $document['archival_status'] === 'archived') {
            $staffScopes[] = ['research_implementation_outputs', 'research_outputs'];
        }
    }
    $staffCanView = false;
    $staffCanDownload = false;
    if ($document && $role === 'research_staff') {
        foreach ($staffScopes as [$moduleKey, $pageKey]) {
            $canViewScope = hasModulePermission($userId, $moduleKey, 'view', $pageKey);
            if ($canViewScope) {
                $staffCanView = true;
            }
            if ($canViewScope && hasDownloadPermission($userId, $moduleKey, $pageKey)) {
                $staffCanDownload = true;
            }
        }
    }
    $mayPreview = $document && (
        $role === 'admin'
        || ($role === 'research_staff' && $staffCanView)
        || ((int) ($document['researcher_id'] ?? 0) === $userId && $role === 'faculty')
    );
    $mayDownload = $document && ($role === 'admin' || ($role === 'research_staff' && $staffCanDownload));
    $allowedPathPattern = '#^uploads/publications/(?:[0-9]{4}/)?[^/]+$#uD';
    $allowedExtensions = ['pdf', 'docx'];
} elseif ($documentType === 'copyright') {
    $stmt = $conn->prepare('SELECT applicant_id, output_type, file_path, file_name FROM copyright_applications WHERE copyright_id = ? LIMIT 1');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    require_once __DIR__ . '/../../includes/archival-records.php';
    $mayPreview = $document && archiveCan($conn, 'copyright', $document, $user, 'view');
    $mayDownload = $document && archiveCan($conn, 'copyright', $document, $user, 'download');
    $allowedPathPattern = '#^uploads/(?:copyrights|copyright)/(?:[0-9]{4}/)?[^/]+$#uD';
    $allowedExtensions = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'];
} elseif ($documentType === 'engagement') {
    $stmt = $conn->prepare(
        'SELECT e.faculty_id, up.file_path, up.file_name, up.original_name
           FROM faculty_engagement_records e
           JOIN uploads up ON up.upload_id = e.evidence_upload_id
          WHERE e.engagement_id = ?
          LIMIT 1'
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $staffCanViewEngagement = $document
        && $role === 'research_staff'
        && hasModulePermission($userId, 'research_development', 'view', 'research_engagement_records');
    $mayPreview = $document && (
        $role === 'admin'
        || $staffCanViewEngagement
        || ($role === 'faculty' && (int) $document['faculty_id'] === $userId)
    );
    $mayDownload = $document && (
        $role === 'admin'
        || ($role === 'faculty' && (int) $document['faculty_id'] === $userId)
        || ($staffCanViewEngagement && hasDownloadPermission($userId, 'research_development', 'research_engagement_records'))
    );
    $allowedPathPattern = '#^uploads/engagement/[^/]+$#uD';
    $allowedExtensions = ['pdf', 'doc', 'docx'];
} else {
    $uploadDeletedColumn = $conn->query("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
    $uploadDeletedFilter = $uploadDeletedColumn && $uploadDeletedColumn->num_rows > 0 ? ' AND up.deleted_at IS NULL' : '';
    if ($uploadDeletedColumn) {
        $uploadDeletedColumn->close();
    }
    $stmt = $conn->prepare(
        'SELECT up.project_id, up.chapter_id, up.uploaded_by, up.file_path, up.file_name, up.original_name,
                rp.created_by, rp.adviser_id,
                CASE
                    WHEN rp.status IN (\'completed\', \'archived\')
                     AND ' . rms_completed_research_predicate() . '
                     AND EXISTS (
                         SELECT 1
                           FROM public_research_releases public_release
                           JOIN research_submission_versions approved_version
                             ON approved_version.version_id = public_release.public_manuscript_version_id
                           JOIN research_submission_version_uploads approved_upload
                             ON approved_upload.version_id = approved_version.version_id
                          WHERE public_release.source_type = \'student_project\'
                            AND public_release.project_id = rp.project_id
                            AND public_release.release_status = \'published\'
                            AND approved_upload.upload_id = up.upload_id
                            AND approved_version.project_id = rp.project_id
                            AND approved_version.submission_type = \'final_manuscript\'
                            AND approved_version.chapter_id IS NULL
                            AND approved_version.status = \'approved\'
                     )
                    THEN 1 ELSE 0
                END AS public_archive_document
           FROM uploads up
           JOIN research_projects rp ON rp.project_id = up.project_id
      LEFT JOIN (
                SELECT project_id,
                       COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 THEN chapter_number END) AS total_chapters,
                       COUNT(DISTINCT CASE WHEN chapter_number BETWEEN 1 AND 5 AND status = \'approved\' THEN chapter_number END) AS approved_chapters
                  FROM chapters
                 WHERE deleted_at IS NULL
                 GROUP BY project_id
           ) ca ON ca.project_id = rp.project_id
          WHERE up.upload_id = ?' . $uploadDeletedFilter . '
            AND rp.deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $isProjectMember = false;
    $isAssignedFaculty = false;
    $staffCanView = false;
    $isPublicArchiveDocument = false;
    if ($document) {
        $projectId = (int) $document['project_id'];
        $isPublicArchiveDocument = (int) ($document['public_archive_document'] ?? 0) === 1;
        $memberStmt = $conn->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
        $memberStmt->bind_param('ii', $projectId, $userId);
        $memberStmt->execute();
        $isProjectMember = $memberStmt->get_result()->num_rows > 0;
        $memberStmt->close();
        if ($role === 'faculty') {
            $facultyStmt = $conn->prepare(
                'SELECT 1 FROM project_advisers WHERE project_id = ? AND adviser_id = ? LIMIT 1'
            );
            if ($facultyStmt) {
                $facultyStmt->bind_param('ii', $projectId, $userId);
                $facultyStmt->execute();
                $isAssignedFaculty = (int) ($document['adviser_id'] ?? 0) === $userId
                    || $facultyStmt->get_result()->num_rows > 0;
                $facultyStmt->close();
            }
        }
        if ($role === 'research_staff') {
            foreach (['research_proposals', 'research_review', 'research_approval'] as $pageKey) {
                if (hasModulePermission($userId, 'research_development', 'view', $pageKey)) {
                    $staffCanView = true;
                    break;
                }
            }
        }
    }
    $mayPreview = $document && (
        $isPublicArchiveDocument
        || $role === 'admin'
        || ($role === 'research_staff' && $staffCanView)
        || ($role === 'student'
            && (
                (int) $document['created_by'] === $userId
                || $isProjectMember
                || ($isPublicArchiveDocument && in_array($mode, ['viewer', 'inline'], true))
            ))
        || ($role === 'faculty' && $isAssignedFaculty)
    );
    $staffCanDownload = false;
    if ($document && $role === 'research_staff') {
        foreach (['research_proposals', 'research_review', 'research_approval'] as $pageKey) {
            if (
                hasModulePermission($userId, 'research_development', 'view', $pageKey)
                && hasDownloadPermission($userId, 'research_development', $pageKey)
            ) {
                $staffCanDownload = true;
                break;
            }
        }
    }
    $mayDownload = $document && ($isPublicArchiveDocument || $role === 'admin' || ($role === 'research_staff' && $staffCanDownload));
    $allowedPathPattern = '#^uploads/(?:proposals|chapters|defense|manuscripts|milestones|other)/[^/]+$#uD';
    $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx'];
}

// Archive access includes the faculty owner and explicitly linked faculty authors.
// Non-copyright IP and research document policies above remain unchanged.
if (in_array($documentType, ['publication','copyright'], true)) {
    require_once __DIR__ . '/../../includes/archival-records.php';
    $archiveDocument = archiveLoad($conn, $documentType, $documentId);
    if ($archiveDocument && ($documentType === 'publication' || archiveCopyrightType((string) $archiveDocument['output_type']))) {
        $document = $archiveDocument;
        $mayPreview = archiveCan($conn, $documentType, $document, $user, 'view');
        $mayDownload = archiveCan($conn, $documentType, $document, $user, 'download');
        $pathColumn = 'file_path'; $nameColumn = 'file_name';
        if ($documentType === 'publication') {
            // The shared upload validator only verifies PDF bytes for this archive.
            $allowedExtensions = ['pdf'];
            $publicationDocumentSlot = is_string($_GET['document'] ?? 'manuscript') ? ($_GET['document'] ?? 'manuscript') : '';
            $publicationDocumentColumns = [
                'manuscript' => ['file_path','file_name'],
                'evidence' => ['evidence_file_path','evidence_file_name'],
                'references' => ['references_file_path','references_file_name'],
            ];
            if (!isset($publicationDocumentColumns[$publicationDocumentSlot])) { http_response_code(404); exit('Document not found.'); }
            [$pathColumn,$nameColumn] = $publicationDocumentColumns[$publicationDocumentSlot];
            if ($publicationDocumentSlot === 'manuscript') {
                $publicPaperStmt = $conn->prepare("SELECT pr.publication_paper_path, pr.publication_paper_name FROM public_research_releases pr JOIN publications p ON p.publication_id = pr.publication_id WHERE pr.source_type = 'faculty_publication' AND pr.publication_id = ? AND pr.release_status = 'published' AND pr.publication_paper_released = 1 AND pr.publication_paper_path IS NOT NULL AND pr.publication_paper_path <> '' AND p.researcher_category = 'faculty' AND (p.status = 'published' OR p.archival_status = 'archived') AND p.deactivated_at IS NULL LIMIT 1");
                $publicPaperStmt->bind_param('i', $documentId);
                $publicPaperStmt->execute();
                $publicPaperRelease = $publicPaperStmt->get_result()->fetch_assoc() ?: null;
                $isPublicReleasedPaper = $publicPaperRelease !== null;
                $publicPaperStmt->close();
                if ($isPublicReleasedPaper) {
                    $document['file_path'] = $publicPaperRelease['publication_paper_path'];
                    $document['file_name'] = $publicPaperRelease['publication_paper_name'];
                }
                $mayPreview = $mayPreview || $isPublicReleasedPaper;
                $mayDownload = $mayDownload || $isPublicReleasedPaper;
            }
            if ($publicationDocumentSlot !== 'manuscript') {
                $document['file_path'] = $document[$pathColumn] ?? null;
                $document['file_name'] = $document[$nameColumn] ?? null;
                $allowedExtensions = ['pdf'];
            }
        }
        if (isset($_GET['history_log'])) {
            if (!$mayPreview) archiveDeny();
            $historyId = filter_var($_GET['history_log'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $event = archiveQuery($conn, 'SELECT details FROM activity_log WHERE log_id = ? AND module = ?', [$historyId, archiveConfig($documentType)['module']])->get_result()->fetch_assoc();
            $details = json_decode((string) ($event['details'] ?? ''), true);
            if (($details['record_kind'] ?? '') !== $documentType || (int) ($details['record_id'] ?? 0) !== $documentId || empty($details['changes'][$pathColumn]['before'])) {
                http_response_code(404); exit('Document version not found.');
            }
            $document['file_path'] = $details['changes'][$pathColumn]['before'];
            $document['file_name'] = $details['changes'][$nameColumn]['before'] ?? basename($document['file_path']);
        }
    }
}
if (!$document || ($mode === 'download' ? !$mayDownload : !$mayPreview)) {
    http_response_code($document ? 403 : 404);
    exit($document ? 'Access denied.' : 'Document not found.');
}

$reviewFlashSuccess = '';
$reviewFlashError = '';
$reviewCommentsValue = trim((string) ($_POST['comments'] ?? ''));
$reviewRecommendationValue = trim((string) ($_POST['recommendation'] ?? ''));
$reviewVersion = null;
$annotationVersion = null;
$reviewCsrfToken = '';
$canApproveSubmission = false;
$canReturnSubmission = false;
$canAnnotateSubmission = false;
$hasReviewAnnotations = false;
$reviewViewerUrl = SITE_URL . 'pages/shared/download-research-document.php?type=research&id=' . $documentId . '&mode=viewer';

if ($documentType === 'research' && $mode === 'viewer') {
    $canApproveSubmission = rms_submission_can_review($userId, 'approved');
    $canReturnSubmission = rms_submission_can_review($userId, 'returned');

    $versionStmt = $conn->prepare(
        "SELECT v.version_id, v.version_number, v.submission_type, v.chapter_id,
                ch.chapter_number, rp.title
           FROM research_submission_version_uploads vu
           JOIN uploads linked_upload ON linked_upload.upload_id = vu.upload_id
           JOIN research_submission_versions v ON v.version_id = vu.version_id
           JOIN research_projects rp ON rp.project_id = v.project_id
      LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
          WHERE vu.upload_id = ?
            AND linked_upload.project_id = v.project_id
            AND (v.chapter_id IS NULL OR linked_upload.chapter_id = v.chapter_id)
            AND v.status = 'submitted'
            AND NOT EXISTS (
                SELECT 1
                  FROM research_submission_versions newer
                 WHERE newer.project_id = v.project_id
                   AND newer.submission_type = v.submission_type
                   AND newer.chapter_id <=> v.chapter_id
                   AND newer.version_number > v.version_number
            )
          ORDER BY v.version_number DESC
          LIMIT 2"
    );
    if (!$versionStmt) {
        throw new RuntimeException('Unable to verify the current submitted version for this attachment.');
    }
    $versionStmt->bind_param('i', $documentId);
    if (!$versionStmt->execute()) {
        $versionStmt->close();
        throw new RuntimeException('Unable to verify the current submitted version for this attachment.');
    }
    $matchingVersions = $versionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $versionStmt->close();
    if (count($matchingVersions) === 1) {
        $reviewVersion = $matchingVersions[0];
    }

    $annotationVersionStmt = $conn->prepare(
        'SELECT v.version_id, v.version_number, v.submission_type, v.status
           FROM research_submission_version_uploads vu
           JOIN research_submission_versions v ON v.version_id = vu.version_id
           JOIN uploads linked_upload ON linked_upload.upload_id = vu.upload_id
          WHERE vu.upload_id = ?
            AND linked_upload.project_id = v.project_id
            AND (v.chapter_id IS NULL OR linked_upload.chapter_id = v.chapter_id)
          ORDER BY v.version_number DESC, v.version_id DESC
          LIMIT 1'
    );
    if (!$annotationVersionStmt) {
        throw new RuntimeException('Unable to verify the document annotation version.');
    }
    $annotationVersionStmt->bind_param('i', $documentId);
    if (!$annotationVersionStmt->execute()) {
        $annotationVersionStmt->close();
        throw new RuntimeException('Unable to verify the document annotation version.');
    }
    $annotationVersion = $annotationVersionStmt->get_result()->fetch_assoc() ?: null;
    $annotationVersionStmt->close();
    $canAnnotateSubmission = $reviewVersion
        && $annotationVersion
        && (int) $reviewVersion['version_id'] === (int) $annotationVersion['version_id']
        && in_array((string) $annotationVersion['submission_type'], ['title', 'chapter'], true)
        && $canReturnSubmission;
    if ($reviewVersion && in_array((string) $reviewVersion['submission_type'], ['title', 'chapter'], true)) {
        $hasReviewAnnotations = rms_submission_annotation_count($conn, (int) $reviewVersion['version_id']) > 0;
    }
}

$relativePath = ltrim(str_replace('\\', '/', (string) $document['file_path']), '/');
$fileName = basename(str_replace('\\', '/', (string) ($document['file_name'] ?? '')));
if (str_ends_with($relativePath, '/')) {
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        http_response_code(404);
        exit('Document not found.');
    }
    $relativePath .= $fileName;
}
$extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
if (
    !preg_match($allowedPathPattern, $relativePath)
    || preg_match('/[\x00-\x1F\x7F]/', $relativePath)
    || !in_array($extension, $allowedExtensions, true)
) {
    http_response_code(404);
    exit('Document not found.');
}

$uploadRoot = realpath(UPLOADS_DIR);
$storedPath = substr($relativePath, strlen('uploads/'));
$resolvedPath = realpath(rtrim(UPLOADS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
if (!$uploadRoot || !$resolvedPath || !is_file($resolvedPath)) {
    http_response_code(404);
    exit('Document not found.');
}
$uploadPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!str_starts_with($resolvedPath, $uploadPrefix)) {
    http_response_code(404);
    exit('Document not found.');
}

if ($documentType === 'research' && $mode === 'viewer') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['annotation_action'])) {
        if (!$canAnnotateSubmission || !$reviewVersion || !$annotationVersion) {
            rms_submission_annotation_json_response(403, ['error' => 'You are not authorized to annotate this document.']);
        }
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            rms_submission_annotation_json_response(403, ['error' => 'Your session has expired. Refresh the page and try again.']);
        }

        $annotationAction = $_POST['annotation_action'];
        if (!is_string($annotationAction)) {
            rms_submission_annotation_json_response(400, ['error' => 'The annotation action is invalid.']);
        }
        $versionId = (int) $reviewVersion['version_id'];
        $annotationIdInput = $_POST['annotation_id'] ?? '';
        $annotationId = $annotationIdInput === ''
            ? null
            : filter_var($annotationIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($annotationIdInput !== '' && $annotationId === false) {
            rms_submission_annotation_json_response(400, ['error' => 'The selected annotation is invalid.']);
        }

        try {
            if ($annotationAction === 'delete') {
                if ($annotationId === null) {
                    throw new InvalidArgumentException('The selected annotation is invalid.');
                }
                rms_submission_annotation_delete($conn, $versionId, $documentId, $userId, (int) $annotationId);
                logActivity('Removed a draft document annotation for submission version #' . $versionId, 'research_submission_annotation');
            } elseif (in_array($annotationAction, ['create', 'update'], true)) {
                if (($annotationAction === 'create' && $annotationId !== null)
                    || ($annotationAction === 'update' && $annotationId === null)) {
                    throw new InvalidArgumentException('The selected annotation action is invalid.');
                }
                $selectedText = $_POST['selected_text'] ?? '';
                $anchorJson = $_POST['anchors'] ?? '';
                $commentText = $_POST['comment'] ?? '';
                $highlightColor = $_POST['highlight_color'] ?? 'yellow';
                if (!is_string($selectedText) || !is_string($anchorJson) || !is_string($commentText) || !is_string($highlightColor)) {
                    throw new InvalidArgumentException('Annotation fields must contain text values.');
                }
                $savedAnnotationId = rms_submission_annotation_save(
                    $conn,
                    $versionId,
                    $documentId,
                    $userId,
                    $selectedText,
                    $anchorJson,
                    $commentText,
                    $highlightColor,
                    $annotationId === null ? null : (int) $annotationId
                );
                logActivity(
                    ($annotationAction === 'create' ? 'Added' : 'Updated') . ' a draft document annotation for submission version #' . $versionId,
                    'research_submission_annotation'
                );
                $annotationId = $savedAnnotationId;
            } else {
                throw new InvalidArgumentException('The annotation action is invalid.');
            }

            rms_submission_annotation_json_response(200, [
                'annotations' => rms_submission_annotation_list($conn, $versionId, $documentId),
                'saved_id' => $annotationAction === 'delete' ? null : $annotationId,
            ]);
        } catch (InvalidArgumentException $exception) {
            rms_submission_annotation_json_response(422, ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $message = str_starts_with($exception->getMessage(), 'Annotations can only')
                || str_starts_with($exception->getMessage(), 'The selected annotation')
                ? $exception->getMessage()
                : 'The annotation could not be saved. Refresh the page and try again.';
            rms_submission_annotation_json_response(409, ['error' => $message]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canApproveSubmission && !$canReturnSubmission) {
            http_response_code(403);
            exit('Access denied.');
        }
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $reviewFlashError = 'Your session has expired. Refresh the page and try again.';
        } elseif (!$reviewVersion) {
            $reviewFlashError = 'This attachment is not linked to a current submitted version awaiting review.';
        } else {
            $decision = (string) ($_POST['decision'] ?? '');
            try {
                $version = rms_review_submission(
                    $conn,
                    (int) $reviewVersion['version_id'],
                    $userId,
                    $decision,
                    $reviewCommentsValue,
                    $reviewRecommendationValue
                );
                $reviewVersion = null;
                $submissionType = (string) $version['submission_type'];
                $kind = match ((string) $version['submission_type']) {
                    'title' => 'research title',
                    'final_manuscript' => 'complete research manuscript',
                    default => 'Chapter ' . (int) ($version['chapter_number'] ?? 0),
                };
                $decisionText = $decision === 'approved' ? 'approved' : 'returned for revision';
                $reviewerName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                if ($reviewerName === '') {
                    $reviewerName = 'User #' . $userId;
                }
                $reviewerRoleLabel = $role === 'admin' ? 'Administrator' : 'Authorized research staff';
                $notificationFeedback = $decision === 'returned'
                    ? '. Comments/Suggestions: ' . trim(implode(' ', array_filter([$reviewCommentsValue, $reviewRecommendationValue])))
                    : '.';

                createNotification(
                    (int) $version['created_by'],
                    ucfirst($kind) . ' ' . $decisionText,
                    'Your ' . $kind . ' submission for "' . (string) $version['title'] . '" was ' . $decisionText
                        . $notificationFeedback,
                    $decision === 'approved' ? 'success' : 'warning',
                    SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $version['project_id']
                );
                logActivity(
                    ucfirst($kind) . ' submission ' . $decisionText . ' for project #' . (int) $version['project_id'],
                    'research_submission_review'
                );

                $reviewFlashSuccess = ucfirst($kind) . ' ' . $decisionText
                    . ' by ' . $reviewerName . ' (' . $reviewerRoleLabel . ')'
                    . ' · Version ' . (int) $version['version_number'] . '.';
                $_SESSION['research_document_review_flash'] = [
                    'upload_id' => $documentId,
                    'message' => $reviewFlashSuccess,
                ];
                session_write_close();
                header('Location: ' . $reviewViewerUrl);
                exit;
            } catch (Throwable $exception) {
                $reviewFlashError = $exception->getMessage();
            }
        }
    } elseif (isset($_SESSION['research_document_review_flash'])) {
        $reviewFlash = $_SESSION['research_document_review_flash'];
        unset($_SESSION['research_document_review_flash']);
        if ((int) ($reviewFlash['upload_id'] ?? 0) === $documentId) {
            $reviewFlashSuccess = (string) ($reviewFlash['message'] ?? '');
        }
    }
    if ($reviewVersion && ($canApproveSubmission || $canReturnSubmission)) {
        $reviewCsrfToken = csrfToken();
    }
}

if ($documentType === 'research' && $mode === 'viewer' && ($_GET['annotations'] ?? '') === '1') {
    if (!$annotationVersion || !in_array((string) $annotationVersion['submission_type'], ['title', 'chapter'], true)) {
        rms_submission_annotation_json_response(200, ['annotations' => []]);
    }
    $versionStatus = (string) $annotationVersion['status'];
    if ($versionStatus === 'submitted' && !$canAnnotateSubmission) {
        rms_submission_annotation_json_response(200, ['annotations' => []]);
    }
    try {
        rms_submission_annotation_json_response(200, [
            'annotations' => rms_submission_annotation_list(
                $conn,
                (int) $annotationVersion['version_id'],
                $documentId
            ),
            'can_edit' => $canAnnotateSubmission,
        ]);
    } catch (Throwable $exception) {
        rms_submission_annotation_json_response(500, ['error' => 'Document annotations could not be loaded.']);
    }
}

$originalName = trim((string) ($document['original_name'] ?? $document['file_name'] ?? ''));
if ($originalName === '') {
    $originalName = match ($documentType) {
        'publication' => 'research-publication.pdf',
        'copyright' => 'copyright-supporting-document.pdf',
        'engagement' => 'research-engagement-evidence.pdf',
        default => 'research-document.pdf',
    };
}
$headerName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $originalName)));
if ($headerName === '' || $headerName === '.' || $headerName === '..') {
    $headerName = 'research-document.pdf';
}

$detectedMime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detectedMime = (string) finfo_file($finfo, $resolvedPath);
        finfo_close($finfo);
    }
}
$previewContentType = rmsDocumentPreviewContentType($relativePath, $detectedMime);
$isPreviewable = $previewContentType !== null;
$isWordDocument = $extension === 'docx';

if ($mode === 'viewer') {
    $roleDashboard = match ($role) {
        'admin' => 'pages/admin/admin-dashboard.php',
        'research_staff' => 'pages/staff/staff-dashboard.php',
        'faculty' => 'pages/faculty/faculty-dashboard.php',
        default => 'pages/student/student-dashboard.php',
    };
    $backUrl = SITE_URL . $roleDashboard;
    $backLabel = '← Dashboard';
    if ($documentType === 'research' && in_array($role, ['admin', 'research_staff'], true)) {
        $backUrl = SITE_URL . 'pages/shared/research-submission-review.php?module_page=research_review';
        $backLabel = '← Chapter Review';
    } elseif ($documentType === 'research' && $role === 'student' && $isPublicArchiveDocument) {
        $backUrl = SITE_URL . 'pages/shared/view-research.php?id=' . $projectId;
        $backLabel = '← Research Details';
    } elseif ($documentType === 'research' && $role === 'student') {
        $backUrl = SITE_URL . 'pages/researcher/my-research.php';
        $backLabel = '← My Research';
    }
    $viewerUrl = SITE_URL . 'pages/shared/download-research-document.php?type='
        . rawurlencode($documentType) . '&id=' . $documentId . '&mode=inline';
    $downloadUrl = SITE_URL . 'pages/shared/download-research-document.php?type='
        . rawurlencode($documentType) . '&id=' . $documentId . '&mode=download';
    if ($documentType === 'publication' && isset($publicationDocumentSlot)) {
        $viewerUrl .= '&document=' . rawurlencode($publicationDocumentSlot);
        $downloadUrl .= '&document=' . rawurlencode($publicationDocumentSlot);
    }
    if (isset($historyId) && $historyId > 0) {
        $viewerUrl .= '&history_log=' . $historyId;
        $downloadUrl .= '&history_log=' . $historyId;
    }
    $annotationsUrl = $reviewViewerUrl . '&annotations=1';
    $pdfjsBaseUrl = SITE_URL . 'public/assets/vendor/pdfjs/';
    $canReviewThisUpload = $reviewVersion && ($canApproveSubmission || $canReturnSubmission);
    $showReviewPanel = $reviewFlashSuccess !== '' || $reviewFlashError !== '' || $canReviewThisUpload;
    session_write_close();
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self' data: blob:; worker-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <?php if ($documentType === 'research' && $isPreviewable): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(SITE_URL . 'public/assets/vendor/pdfjs/web/pdf_viewer.css', ENT_QUOTES, 'UTF-8') ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(SITE_URL . 'css/pdf-review-viewer.css?v=' . filemtime(__DIR__ . '/../../css/pdf-review-viewer.css'), ENT_QUOTES, 'UTF-8') ?>">
        <script type="module" src="<?= htmlspecialchars(SITE_URL . 'js/pdf-review-annotations.js?v=' . filemtime(__DIR__ . '/../../js/pdf-review-annotations.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
      <?php endif; ?>
      <title><?= htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') ?> — RMS Document</title>
      <style>
        *{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#111827;font:14px/1.5 Inter,Arial,sans-serif}.viewer{min-height:100vh;display:grid;grid-template-rows:auto auto 1fr}.toolbar{display:flex;align-items:center;gap:16px;min-height:68px;padding:12px 20px;background:#fff;border-bottom:1px solid #e5e7eb}.back{color:#334155;text-decoration:none;font-weight:600;white-space:nowrap}.name{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.download{padding:9px 14px;border-radius:9px;background:#5b1ebc;color:#fff;text-decoration:none;font-weight:600;white-space:nowrap}.stage{display:grid;place-items:center;min-height:0;padding:20px}.preview{width:min(100%,1200px);height:calc(100vh - 108px);border:1px solid #e5e7eb;border-radius:12px;background:#fff}.unavailable{max-width:560px;padding:32px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:center}.unavailable h1{margin:0 0 8px;font-size:20px}.unavailable p{margin:0;color:#64748b}.unavailable .download{display:inline-flex;margin-top:20px}.review-panel{margin:16px 20px 0;padding:16px 18px;border:1px solid #dbe3ee;border-radius:12px;background:#fff;color-scheme:light}.review-panel h1{margin:0 0 6px;font-size:17px}.review-panel p{margin:4px 0 12px;color:#475569}.review-message{margin:0 0 12px;padding:10px 12px;border-radius:8px}.review-message.success{background:#ecfdf5;color:#166534}.review-message.error{background:#fef2f2;color:#991b1b}.review-form{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;align-items:start}.review-form label{display:block;font-weight:600}.review-form textarea{display:block;width:100%;min-height:76px;margin-top:5px;padding:9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#111827;font:inherit;resize:vertical}.review-form textarea:focus-visible,.review-form button:focus-visible{outline:2px solid #5b1ebc;outline-offset:2px}.review-actions{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap}.review-actions button{padding:9px 14px;border:0;border-radius:8px;font:inherit;font-weight:700;cursor:pointer}.review-actions .approve{background:#15803d;color:#fff}.review-actions .return{background:#b45309;color:#fff}.review-panel form{margin:0}@media(max-width:640px){.toolbar{gap:10px;padding:10px 12px}.name{font-size:12px}.stage{padding:10px}.preview{height:calc(100vh - 88px);border-radius:8px}.back{font-size:12px}.download{padding:8px 10px;font-size:12px}.review-panel{margin:10px 10px 0;padding:13px}.review-form{grid-template-columns:1fr}.review-actions{grid-column:auto}}
        .annotation-decision-note{margin:10px 0 0!important;padding:9px 11px;border-left:3px solid #b45309;border-radius:6px;background:#fff7ed;color:#7c2d12!important;font-size:13px}
        .review-confirm-dialog{position:fixed;inset:0;margin:auto;width:min(560px,calc(100vw - 32px));max-width:none;max-height:calc(100dvh - 32px);overflow:auto;padding:0;border:1px solid #fed7aa;border-radius:18px;background:#fff;color:#111827;box-shadow:0 24px 72px rgba(15,23,42,.28)}
        .review-confirm-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(3px)}
        .review-confirm-inner{padding:26px}
        .review-confirm-mark{display:grid;place-items:center;width:46px;height:46px;margin-bottom:14px;border-radius:14px;background:#c2410c;color:#fff;font-size:23px;font-weight:800}
        .review-confirm-dialog h2{margin:0;color:#111827;font-size:21px;line-height:1.3}
        .review-confirm-dialog .review-confirm-description{margin:9px 0 16px;color:#475569;line-height:1.6}
        .review-confirm-feedback{display:grid;gap:10px;max-height:34vh;overflow:auto}
        .review-confirm-feedback section{padding:11px 13px;border:1px solid #dbe3ee;border-radius:10px;background:#f8fafc}
        .review-confirm-feedback h3{margin:0 0 5px;color:#334155;font-size:12px;font-weight:750;text-transform:uppercase;letter-spacing:.04em}
        .review-confirm-feedback p{margin:0;color:#1f2937;white-space:pre-wrap;overflow-wrap:anywhere}
        .review-confirm-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0}
        .review-confirm-actions button{min-height:42px;padding:9px 13px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;font:inherit;font-weight:650;cursor:pointer}
        .review-confirm-actions button:hover{background:#f8fafc}
        .review-confirm-actions .review-confirm-approve{border-color:#15803d;background:#15803d;color:#fff}
        .review-confirm-actions .review-confirm-approve:hover{background:#166534}
        .review-confirm-actions .review-confirm-return{border-color:#b45309;background:#fff7ed;color:#9a3412}
        .review-confirm-actions :focus-visible{outline:2px solid #5b1ebc;outline-offset:3px}
        @media(max-width:520px){.review-confirm-inner{padding:21px}.review-confirm-actions{display:grid;grid-template-columns:1fr}.review-confirm-actions button{width:100%}}
        .viewer{grid-template-columns:minmax(0,1fr);grid-template-rows:auto auto}
        .viewer.has-review{grid-template-columns:minmax(0,1.9fr) minmax(18rem,1fr);grid-template-rows:auto minmax(0,1fr)}
        .toolbar{grid-column:1/-1}
        .stage{grid-column:1;grid-row:2;min-width:0}
        .preview{width:100%;max-width:1200px;min-width:0}
        .review-panel{grid-column:2;grid-row:2;min-width:0;margin:20px 20px 20px 0;overflow-wrap:anywhere}
        .review-form{grid-template-columns:minmax(0,1fr)}
        .review-actions{grid-column:1}
        @media(max-width:800px){
          .viewer.has-review{grid-template-columns:minmax(0,1fr);grid-template-rows:auto auto auto}
          .stage{grid-column:1;grid-row:2}
          .review-panel{grid-column:1;grid-row:3;margin:0 10px 12px}
          .review-form{grid-template-columns:minmax(0,1fr)}
          .review-actions{grid-column:1}
        }
      </style>
    </head>
    <body>
      <main class="viewer<?= $showReviewPanel ? ' has-review' : '' ?>">
        <header class="toolbar">
          <a class="back" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></a>
          <div class="name"><?= htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($mayDownload): ?><a class="download" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>">Download</a><?php endif; ?>
        </header>
        <section class="stage" aria-label="Document preview">
          <?php if ($isPreviewable): ?>
            <?php if ($documentType === 'research'): ?>
              <div class="pdf-review-app"
                data-pdf-url="<?= htmlspecialchars($viewerUrl, ENT_QUOTES, 'UTF-8') ?>"
                data-annotations-url="<?= htmlspecialchars($annotationsUrl, ENT_QUOTES, 'UTF-8') ?>"
                data-annotation-post-url="<?= htmlspecialchars($reviewViewerUrl, ENT_QUOTES, 'UTF-8') ?>"
                data-pdfjs-base="<?= htmlspecialchars($pdfjsBaseUrl, ENT_QUOTES, 'UTF-8') ?>"
                data-csrf-token="<?= htmlspecialchars($reviewCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
                data-can-edit="<?= $canAnnotateSubmission ? 'true' : 'false' ?>">
                <div class="pdf-review-toolbar" role="group" aria-label="PDF controls">
                  <div class="pdf-review-page-control" role="group" aria-label="Page navigation">
                    <button type="button" data-page-previous aria-label="Previous page">Previous</button>
                    <label>Page <input type="number" min="1" value="1" data-page-number aria-label="Current page"></label>
                    <span data-page-count>of …</span>
                    <button type="button" data-page-next aria-label="Next page">Next</button>
                  </div>
                  <div class="pdf-review-zoom-control" role="group" aria-label="Zoom and page fit">
                    <button type="button" data-zoom-out aria-label="Zoom out">−</button>
                    <span data-zoom-level aria-label="Current zoom">—</span>
                    <button type="button" data-zoom-in aria-label="Zoom in">+</button>
                    <button type="button" data-fit-width aria-pressed="true">Fit width</button>
                    <button type="button" data-fit-page aria-pressed="false">Fit page</button>
                    <button type="button" data-zoom-reset title="Return to fit width">Reset zoom</button>
                  </div>
                  <div class="pdf-review-document-control" role="group" aria-label="Document actions">
                    <button type="button" data-rotate aria-label="Rotate clockwise">Rotate</button>
                    <button type="button" data-fullscreen aria-label="Enter fullscreen">Fullscreen</button>
                    <button type="button" data-print>Print</button>
                  </div>
                  <div class="pdf-review-search-control" role="search" aria-label="Search document text">
                    <label class="visually-hidden" for="pdf-document-search">Search document</label>
                    <input type="search" id="pdf-document-search" data-search-input placeholder="Search document" autocomplete="off">
                    <button type="button" data-search-previous aria-label="Previous match">Previous match</button>
                    <button type="button" data-search-next aria-label="Next match">Next match</button>
                    <span data-search-count aria-live="polite">Enter text to search</span>
                  </div>
                  <?php if ($canAnnotateSubmission): ?>
                    <div class="pdf-review-annotation-control">
                      <button type="button" data-add-annotation disabled>Add comment to selection</button>
                      <button type="button" data-area-annotation>Mark a page area</button>
                    </div>
                  <?php endif; ?>
                </div>
                <p class="pdf-review-status" data-viewer-status role="status" aria-live="polite">Loading document…</p>
                <div class="pdf-review-scroll" data-pdf-pages tabindex="0" aria-label="PDF pages"></div>
                <dialog class="pdf-annotation-dialog" data-annotation-dialog aria-labelledby="pdf-annotation-title">
                  <div class="pdf-annotation-dialog-inner">
                    <h2 id="pdf-annotation-title">Add a comment to this highlight</h2>
                    <p class="pdf-annotation-quote" data-annotation-quote hidden></p>
                    <fieldset class="pdf-annotation-color-picker" data-annotation-color-picker<?= $canAnnotateSubmission ? '' : ' hidden' ?> aria-label="Highlight color">
                      <legend>Highlight color</legend>
                      <label><input type="radio" name="highlight_color" value="yellow" checked><span class="pdf-annotation-color-swatch is-color-yellow" aria-hidden="true"></span>Yellow</label>
                      <label><input type="radio" name="highlight_color" value="blue"><span class="pdf-annotation-color-swatch is-color-blue" aria-hidden="true"></span>Blue</label>
                      <label><input type="radio" name="highlight_color" value="green"><span class="pdf-annotation-color-swatch is-color-green" aria-hidden="true"></span>Green</label>
                      <label><input type="radio" name="highlight_color" value="pink"><span class="pdf-annotation-color-swatch is-color-pink" aria-hidden="true"></span>Pink</label>
                      <label><input type="radio" name="highlight_color" value="purple"><span class="pdf-annotation-color-swatch is-color-purple" aria-hidden="true"></span>Purple</label>
                    </fieldset>
                    <label for="pdf-annotation-comment">Reviewer comment</label>
                    <textarea id="pdf-annotation-comment" data-annotation-comment maxlength="5000" required></textarea>
                    <p class="pdf-annotation-error" data-annotation-error role="alert" hidden></p>
                    <div class="pdf-annotation-dialog-actions">
                      <button type="button" data-annotation-cancel>Cancel</button>
                      <button type="button" data-annotation-delete hidden>Remove highlight</button>
                      <button type="button" data-annotation-save>Save comment</button>
                    </div>
                  </div>
                </dialog>
              </div>
            <?php else: ?>
              <iframe class="preview" src="<?= htmlspecialchars($viewerUrl, ENT_QUOTES, 'UTF-8') ?>" title="Preview of <?= htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') ?>"></iframe>
            <?php endif; ?>
          <?php else: ?>
            <div class="unavailable" role="status">
              <?php if ($isWordDocument): ?>
                <h1>Word document ready to open</h1>
                <p>DOCX files cannot be previewed directly in this browser. Download the editable file and open it with Microsoft Word or LibreOffice. To preview it in RMS, upload a PDF copy; the original DOCX remains unchanged.</p>
                <?php if ($mayDownload): ?><a class="download" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>">Download DOCX</a><?php endif; ?>
              <?php else: ?>
                <h1>Preview unavailable</h1>
                <p>This file format is not currently supported for in-browser preview. The original file is unchanged.</p>
                <?php if ($mayDownload): ?><a class="download" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>">Download original</a><?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>
        <?php if ($showReviewPanel): ?>
          <section class="review-panel" aria-label="Submission review">
            <?php if ($reviewFlashSuccess !== ''): ?><p class="review-message success" role="status"><?= htmlspecialchars($reviewFlashSuccess, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($reviewFlashError !== ''): ?><p class="review-message error" role="alert"><?= htmlspecialchars($reviewFlashError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($canReviewThisUpload): ?>
              <?php
                $reviewSubmissionLabel = match ((string) $reviewVersion['submission_type']) {
                    'title' => 'Research title',
                    'final_manuscript' => 'Complete research manuscript',
                    default => 'Chapter ' . (int) ($reviewVersion['chapter_number'] ?? 0),
                };
              ?>
              <h1>Review <?= htmlspecialchars($reviewSubmissionLabel, ENT_QUOTES, 'UTF-8') ?> · Version <?= (int) $reviewVersion['version_number'] ?></h1>
              <p>Decision controls are available only for this attachment’s current submitted version.</p>
              <form class="review-form" method="post" action="<?= htmlspecialchars($reviewViewerUrl, ENT_QUOTES, 'UTF-8') ?>" data-submission-type="<?= htmlspecialchars((string) $reviewVersion['submission_type'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($reviewCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label for="review-comments">Comments/Suggestions<?= $canReturnSubmission ? ' (required when returning)' : ' (optional)' ?>
                  <textarea id="review-comments" name="comments" maxlength="5000"><?= htmlspecialchars($reviewCommentsValue, ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>
                <div class="review-actions">
                  <?php if ($canApproveSubmission): ?><button class="approve" type="submit" name="decision" value="approved" data-annotation-approval<?= $hasReviewAnnotations ? ' hidden' : '' ?>>Approve version</button><?php endif; ?>
                  <?php if ($canReturnSubmission): ?><button class="return" type="submit" name="decision" value="returned">Return for revision</button><?php endif; ?>
                </div>
                <?php if ($canReturnSubmission || ($canApproveSubmission && $hasReviewAnnotations)): ?>
                  <p class="annotation-decision-note" data-annotation-decision-note<?= $hasReviewAnnotations ? '' : ' hidden' ?>><?php if ($canReturnSubmission): ?>This version has document annotations. Return it for revision to send them to the researcher; approval is available again after all draft annotations are removed.<?php else: ?>This version has document annotations and cannot be approved. Ask a reviewer with return permission to return it for revision.<?php endif; ?></p>
                <?php endif; ?>
              </form>
              <?php if ($canApproveSubmission): ?>
                <dialog class="review-confirm-dialog" aria-labelledby="review-confirm-title" aria-describedby="review-confirm-description">
                  <div class="review-confirm-inner">
                    <div class="review-confirm-mark" aria-hidden="true">!</div>
                    <h2 id="review-confirm-title">Approve with feedback?</h2>
                    <p id="review-confirm-description" class="review-confirm-description">Review the feedback below. If it requires changes before this submission can advance, return it for revision instead.</p>
                    <div class="review-confirm-feedback">
                      <section data-review-confirm-comments hidden><h3>Comments/Suggestions</h3><p></p></section>
                    </div>
                    <div class="review-confirm-actions">
                      <button type="button" class="review-confirm-cancel">Cancel</button>
                      <?php if ($canReturnSubmission): ?><button type="button" class="review-confirm-return">Return for revision</button><?php endif; ?>
                      <button type="button" class="review-confirm-approve">Approve with guidance</button>
                    </div>
                  </div>
                </dialog>
                <script src="<?= htmlspecialchars(SITE_URL . 'js/research-document-review.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
              <?php endif; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </main>
    </body>
    </html>
    <?php
    exit;
}

if ($mode === 'inline' && !$isPreviewable) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    exit('Preview unavailable for this file format.');
}

$file = fopen($resolvedPath, 'rb');
if ($file === false) {
    http_response_code(500);
    exit('The requested document could not be opened.');
}

$fileSize = (int) filesize($resolvedPath);
$start = 0;
$end = max(0, $fileSize - 1);
$statusCode = 200;
if ($mode === 'inline') {
    $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($rangeHeader !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $rangeHeader, $rangeMatch) || $fileSize === 0) {
            fclose($file);
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        if ($rangeMatch[1] === '') {
            $suffixLength = (int) $rangeMatch[2];
            if ($suffixLength <= 0) {
                fclose($file);
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            $start = max(0, $fileSize - $suffixLength);
        } else {
            $start = (int) $rangeMatch[1];
            if ($rangeMatch[2] !== '') {
                $end = min((int) $rangeMatch[2], $fileSize - 1);
            }
        }
        if ($start >= $fileSize || $start > $end) {
            fclose($file);
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $statusCode = 206;
    }
}
$contentLength = $fileSize > 0 ? $end - $start + 1 : 0;
session_write_close();
if ($mode === 'inline') {
    http_response_code($statusCode);
    header('Content-Type: ' . $previewContentType);
    header('Content-Disposition: inline; filename="' . $headerName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
    header('Accept-Ranges: bytes');
    if ($statusCode === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }
    header('Content-Security-Policy: sandbox');
    header('X-Frame-Options: SAMEORIGIN');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $headerName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
}
header('Content-Length: ' . (string) $contentLength);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($start > 0 && fseek($file, $start) !== 0) {
    fclose($file);
    http_response_code(500);
    exit('The requested document could not be read.');
}
$remaining = $contentLength;
while ($remaining > 0 && !feof($file)) {
    $chunk = fread($file, min(8192, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($file);
