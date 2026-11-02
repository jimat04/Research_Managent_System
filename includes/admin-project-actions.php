<?php
// Admin-only project lifecycle actions. Callers must also validate CSRF.
function rms_admin_project_query(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt || ($types !== '' && !$stmt->bind_param($types, ...$params)) || !$stmt->execute()) {
        throw new RuntimeException('The project action could not be completed. No changes were saved.');
    }
    return $stmt;
}

function rms_admin_project_guard(): void
{
    if (!isLoggedIn() || !hasRole('admin')) {
        throw new RuntimeException('Only administrators can perform this action.');
    }
}

function rms_admin_return_approved(mysqli $conn, int $project_id, int $version_id, string $comments): void
{
    rms_admin_project_guard();
    $comments = trim($comments);
    if (mb_strlen($comments) < 10 || mb_strlen($comments) > 5000) {
        throw new InvalidArgumentException('Explain the required revision in 10–5,000 characters.');
    }
    $conn->begin_transaction();
    try {
        $project = rms_admin_project_query($conn, 'SELECT project_id FROM research_projects WHERE project_id = ? AND deleted_at IS NULL FOR UPDATE', 'i', [$project_id])->get_result()->fetch_assoc();
        $version = rms_admin_project_query($conn, 'SELECT * FROM research_submission_versions WHERE version_id = ? AND project_id = ? FOR UPDATE', 'ii', [$version_id, $project_id])->get_result()->fetch_assoc();
        if (!$project || !$version || $version['status'] !== 'approved' || !in_array($version['submission_type'], ['chapter', 'final_manuscript'], true)) {
            throw new RuntimeException('Choose a currently approved chapter or final manuscript.');
        }
        $latest = rms_admin_project_query($conn, 'SELECT version_id FROM research_submission_versions WHERE project_id = ? AND submission_type = ? AND chapter_id <=> ? ORDER BY version_number DESC, version_id DESC LIMIT 1 FOR UPDATE', 'isi', [$project_id, $version['submission_type'], $version['chapter_id']])->get_result()->fetch_assoc();
        if ((int) $latest['version_id'] !== $version_id) {
            throw new RuntimeException('This version has been superseded. Refresh and choose the latest approved version.');
        }
        if ($version['submission_type'] === 'chapter') {
            $changed = rms_admin_project_query($conn, "UPDATE chapters SET status = 'revision_required', approved_by = NULL, approved_at = NULL, updated_at = NOW() WHERE chapter_id = ? AND project_id = ? AND status = 'approved' AND deleted_at IS NULL", 'ii', [$version['chapter_id'], $project_id]);
            if ($changed->affected_rows !== 1) {
                throw new RuntimeException('This chapter is no longer approved or available.');
            }
        } elseif ($version['chapter_id'] !== null) {
            throw new RuntimeException('This is not a valid final manuscript.');
        }
        rms_admin_project_query($conn, "UPDATE research_submission_versions SET status = 'returned' WHERE version_id = ? AND status = 'approved'", 'i', [$version_id]);
        $admin = getCurrentUser();
        rms_admin_project_query($conn, "INSERT INTO research_submission_reviews (version_id, reviewer_id, decision, comments, reviewed_at) VALUES (?, ?, 'returned', ?, NOW())", 'iis', [$version_id, (int) $admin['user_id'], $comments]);
        rms_admin_project_query($conn, "UPDATE research_projects SET status = 'ongoing', updated_at = NOW() WHERE project_id = ?", 'i', [$project_id]);
        if (!logActivity('Reopened approved submission #' . $version_id . ' for revision in project #' . $project_id, 'research')) {
            throw new RuntimeException('Unable to save the audit record. No changes were saved.');
        }
        $recipients = rms_admin_project_query($conn, 'SELECT created_by AS user_id FROM research_projects WHERE project_id = ? UNION SELECT user_id FROM project_members WHERE project_id = ? UNION SELECT submitted_by FROM research_submission_versions WHERE version_id = ?', 'iii', [$project_id, $project_id, $version_id])->get_result();
        while ($recipient = $recipients->fetch_assoc()) {
            if (!createNotification((int) $recipient['user_id'], 'Approved work returned for revision', 'An administrator requested corrections. Open the project research trail to read the comments.', 'warning', SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id)) {
                throw new RuntimeException('Unable to notify the researchers. No changes were saved.');
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function rms_project_file_path(string $stored): ?string
{
    $stored = str_replace('\\', '/', $stored);
    if (!str_starts_with($stored, 'uploads/') || str_contains($stored, '..') || str_contains($stored, "\0")) {
        throw new RuntimeException('A project document has an unsafe storage path. Deletion was stopped.');
    }
    $root = realpath(UPLOADS_DIR);
    $candidate = rtrim(UPLOADS_DIR, '/\\') . DIRECTORY_SEPARATOR . substr($stored, 8);
    if (!file_exists($candidate)) {
        return null;
    }
    $path = realpath($candidate);
    if (!$root || !$path || !is_file($path) || is_link($candidate) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('A project document is outside the allowed storage directory. Deletion was stopped.');
    }
    return $path;
}

function rms_project_cleanup_directory(): string
{
    // This directory contains pending file-removal manifests, never document contents.
    $directory = __DIR__ . '/project-deletion-jobs';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare document cleanup. Deletion was stopped.');
    }
    if (file_put_contents($directory . '/.htaccess', "Require all denied\n") === false) {
        throw new RuntimeException('Unable to protect document cleanup records.');
    }
    return $directory;
}

function rms_admin_finish_project_cleanup(mysqli $conn, int $project_id): bool
{
    rms_admin_project_guard();
    if ($project_id <= 0) {
        throw new InvalidArgumentException('Invalid project.');
    }
    if (rms_admin_project_query($conn, 'SELECT project_id FROM research_projects WHERE project_id = ?', 'i', [$project_id])->get_result()->num_rows) {
        throw new RuntimeException('The project still exists. Its documents have not been deleted.');
    }
    $manifest = rms_project_cleanup_directory() . '/' . $project_id . '.json';
    if (!is_file($manifest)) {
        return true;
    }
    $paths = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($paths)) {
        throw new RuntimeException('Unable to read the pending document cleanup record.');
    }
    $complete = true;
    foreach ($paths as $stored) {
        // Recheck references before retrying a previously failed filesystem cleanup.
        $references = rms_admin_project_query($conn, 'SELECT upload_id FROM uploads WHERE file_path = ? UNION ALL SELECT publication_id FROM publications WHERE file_path = ? UNION ALL SELECT copyright_id FROM copyright_applications WHERE file_path = ?', 'sss', [$stored, $stored, $stored])->get_result();
        if ($references->num_rows) {
            $complete = false;
            continue;
        }
        $path = rms_project_file_path($stored);
        if ($path !== null && !@unlink($path)) {
            $complete = false;
        }
    }
    return $complete && @unlink($manifest);
}

function rms_admin_delete_project(mysqli $conn, int $project_id): bool
{
    rms_admin_project_guard();
    if ($project_id <= 0) {
        throw new InvalidArgumentException('Invalid project.');
    }
    $manifest = rms_project_cleanup_directory() . '/' . $project_id . '.json';
    $committed = false;
    $conn->begin_transaction();
    try {
        $project = rms_admin_project_query($conn, 'SELECT project_id FROM research_projects WHERE project_id = ? FOR UPDATE', 'i', [$project_id])->get_result()->fetch_assoc();
        if (!$project) {
            throw new RuntimeException('This project no longer exists.');
        }
        $uploads = rms_admin_project_query($conn, 'SELECT up.upload_id, up.file_path FROM uploads up WHERE up.project_id = ? OR up.research_id = ? OR up.chapter_id IN (SELECT chapter_id FROM chapters WHERE project_id = ? OR research_id = ?) OR up.upload_id IN (SELECT vu.upload_id FROM research_submission_version_uploads vu JOIN research_submission_versions v ON v.version_id = vu.version_id WHERE v.project_id = ?) OR up.upload_id IN (SELECT upload_id FROM research_documents WHERE project_id = ?) FOR UPDATE', 'iiiiii', array_fill(0, 6, $project_id))->get_result()->fetch_all(MYSQLI_ASSOC);
        $paths = [];
        foreach ($uploads as $upload) {
            $id = (int) $upload['upload_id'];
            $shared = rms_admin_project_query($conn, 'SELECT vu.upload_id FROM research_submission_version_uploads vu JOIN research_submission_versions v ON v.version_id = vu.version_id WHERE vu.upload_id = ? AND v.project_id <> ? UNION ALL SELECT upload_id FROM research_documents WHERE upload_id = ? AND project_id <> ? UNION ALL SELECT evidence_upload_id FROM faculty_engagement_records WHERE evidence_upload_id = ?', 'iiiii', [$id, $project_id, $id, $project_id, $id])->get_result();
            $owners = rms_admin_project_query($conn, 'SELECT upload_id FROM uploads WHERE upload_id = ? AND ((project_id IS NOT NULL AND project_id <> ?) OR (research_id IS NOT NULL AND research_id <> ?) OR chapter_id IN (SELECT chapter_id FROM chapters WHERE project_id <> ?))', 'iiii', [$id, $project_id, $project_id, $project_id])->get_result();
            $same_path = rms_admin_project_query($conn, 'SELECT upload_id FROM uploads WHERE file_path = ? AND upload_id <> ? UNION ALL SELECT publication_id FROM publications WHERE file_path = ? UNION ALL SELECT copyright_id FROM copyright_applications WHERE file_path = ?', 'siss', [$upload['file_path'], $id, $upload['file_path'], $upload['file_path']])->get_result();
            if ($shared->num_rows || $owners->num_rows || $same_path->num_rows) {
                throw new RuntimeException('A document is shared with another record. Resolve that link before permanently deleting this project.');
            }
            rms_project_file_path($upload['file_path']);
            $paths[] = $upload['file_path'];
        }
        if (file_put_contents($manifest, json_encode(array_values(array_unique($paths)), JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Unable to record document cleanup. No project data was deleted.');
        }
        // These legacy relations do not all have project foreign keys.
        foreach (['comments', 'project_advisers', 'project_advisers_history', 'project_members'] as $table) {
            rms_admin_project_query($conn, 'DELETE FROM ' . $table . ' WHERE project_id = ?', 'i', [$project_id]);
        }
        rms_admin_project_query($conn, 'DELETE FROM defense_schedule WHERE project_id = ? OR research_id = ?', 'ii', [$project_id, $project_id]);
        rms_admin_project_query($conn, 'DELETE vu FROM research_submission_version_uploads vu JOIN research_submission_versions v ON v.version_id = vu.version_id WHERE v.project_id = ?', 'i', [$project_id]);
        // Cascades remove chapters/content, submission versions/reviews, document/report rows and project review history.
        rms_admin_project_query($conn, 'DELETE FROM research_projects WHERE project_id = ?', 'i', [$project_id]);
        foreach ($uploads as $upload) {
            rms_admin_project_query($conn, 'DELETE FROM uploads WHERE upload_id = ?', 'i', [(int) $upload['upload_id']]);
        }
        if (!logActivity('Permanently deleted project #' . $project_id . ', its project documents and review history', 'research')) {
            throw new RuntimeException('Unable to record the deletion audit. No changes were saved.');
        }
        $conn->commit();
        $committed = true;
    } catch (Throwable $e) {
        if (!$committed) {
            $conn->rollback();
            if (is_file($manifest)) {
                @unlink($manifest);
            }
        }
        throw $e;
    }
    try {
        return rms_admin_finish_project_cleanup($conn, $project_id);
    } catch (Throwable $e) {
        // The manifest survives; the admin can retry without deleting more database records.
        return false;
    }
}
