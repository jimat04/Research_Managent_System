<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    http_response_code(403);
    exit('Access denied.');
}

$documentType = (string) ($_GET['type'] ?? '');
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (!$documentId || !in_array($documentType, ['publication', 'copyright', 'research'], true)) {
    http_response_code(404);
    exit('Document not found.');
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
if ($documentType === 'publication') {
    $stmt = $conn->prepare('SELECT researcher_id, publication_type, file_path, file_name, status FROM publications WHERE publication_id = ? LIMIT 1');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $staffCanView = false;
    if ($document && $role === 'research_staff') {
        $staffPages = match ((string) $document['publication_type']) {
            'journal' => ['journal_publications'],
            'conference' => ['conference_presentations'],
            default => [],
        };
        if ($document['status'] === 'published') {
            $staffPages[] = 'research_outputs';
            $staffPages[] = 'research_repository';
        }
        foreach ($staffPages as $pageKey) {
            if (hasModulePermission($userId, 'publication_dissemination', 'view', $pageKey)) {
                $staffCanView = true;
                break;
            }
        }
    }
    $mayDownload = $document && (
        $role === 'admin'
        || ($role === 'research_staff' && $staffCanView)
        || ((int) ($document['researcher_id'] ?? 0) === $userId && in_array($role, ['faculty', 'student'], true))
        || ($document['status'] === 'published' && in_array($role, ['faculty', 'student'], true))
    );
    $allowedPathPattern = '#^uploads/publications/(?:[0-9]{4}/)?[^/]+$#uD';
    $allowedExtensions = ['pdf'];
} elseif ($documentType === 'copyright') {
    $stmt = $conn->prepare('SELECT applicant_id, output_type, file_path, file_name FROM copyright_applications WHERE copyright_id = ? LIMIT 1');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $staffCanView = false;
    if ($document && $role === 'research_staff') {
        $staffPages = match ((string) $document['output_type']) {
            'patent', 'utility_model' => ['patents_utility_models'],
            'innovation', 'technology_transfer' => ['innovation_technology_transfer'],
            default => ['copyrights'],
        };
        foreach ($staffPages as $pageKey) {
            if (hasModulePermission($userId, 'intellectual_property_utilization', 'view', $pageKey)) {
                $staffCanView = true;
                break;
            }
        }
    }
    $mayDownload = $document && (
        $role === 'admin'
        || ($role === 'research_staff' && $staffCanView)
        || ((int) ($document['applicant_id'] ?? 0) === $userId && in_array($role, ['faculty', 'student'], true))
    );
    $allowedPathPattern = '#^uploads/(?:copyrights|copyright)/(?:[0-9]{4}/)?[^/]+$#uD';
    $allowedExtensions = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'];
} else {
    $uploadDeletedColumn = $conn->query("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
    $uploadDeletedFilter = $uploadDeletedColumn && $uploadDeletedColumn->num_rows > 0 ? ' AND up.deleted_at IS NULL' : '';
    if ($uploadDeletedColumn) {
        $uploadDeletedColumn->close();
    }
    $stmt = $conn->prepare(
        'SELECT up.project_id, up.chapter_id, up.uploaded_by, up.file_path, up.file_name, up.original_name,
                rp.created_by
           FROM uploads up
           JOIN research_projects rp ON rp.project_id = up.project_id
          WHERE up.upload_id = ?' . $uploadDeletedFilter . '
          LIMIT 1'
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $isProjectMember = false;
    $isAssignedFaculty = false;
    $staffCanView = false;
    if ($document) {
        $projectId = (int) $document['project_id'];
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
                $isAssignedFaculty = $facultyStmt->get_result()->num_rows > 0;
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
    $mayDownload = $document && (
        $role === 'admin'
        || ($role === 'research_staff' && $staffCanView)
        || (in_array($role, ['student', 'faculty'], true)
            && (
                (int) $document['created_by'] === $userId
                || $isProjectMember
                || $isAssignedFaculty
            ))
    );
    $allowedPathPattern = '#^uploads/(?:proposals|chapters|defense|manuscripts)/[^/]+$#uD';
    $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx'];
}

if (!$document || !$mayDownload) {
    http_response_code($document ? 403 : 404);
    exit($document ? 'Access denied.' : 'Document not found.');
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

$uploadRoot = realpath(__DIR__ . '/../../uploads');
$resolvedPath = realpath(__DIR__ . '/../../' . $relativePath);
if (!$uploadRoot || !$resolvedPath || !is_file($resolvedPath)) {
    http_response_code(404);
    exit('Document not found.');
}
$uploadPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!str_starts_with($resolvedPath, $uploadPrefix)) {
    http_response_code(404);
    exit('Document not found.');
}

$file = fopen($resolvedPath, 'rb');
if ($file === false) {
    http_response_code(500);
    exit('The requested document could not be opened.');
}

$originalName = trim((string) ($document['original_name'] ?? $document['file_name'] ?? ''));
if ($originalName === '') {
    $originalName = $documentType === 'publication' ? 'research-publication.pdf' : 'copyright-supporting-document.pdf';
}
$headerName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $originalName)));
if ($headerName === '' || $headerName === '.' || $headerName === '..') {
    $headerName = 'research-document.pdf';
}

session_write_close();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $headerName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('Content-Length: ' . (string) filesize($resolvedPath));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
fpassthru($file);
fclose($file);
