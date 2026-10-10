<?php

function rms_capture_submission_version(mysqli $conn, int $project_id, ?int $chapter_id, string $type, int $submitter_id, array $snapshot, array $upload_ids = []): int
{
    if (!in_array($type, ['title', 'chapter'], true) || $project_id <= 0 || $submitter_id <= 0) {
        throw new InvalidArgumentException('Invalid submission version reference.');
    }

    $version_stmt = $conn->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
           FROM research_submission_versions
          WHERE project_id = ? AND submission_type = ? AND chapter_id ' . ($chapter_id === null ? 'IS NULL' : '= ?')
    );
    if (!$version_stmt) {
        throw new RuntimeException('Unable to prepare submission version lookup: ' . $conn->error);
    }
    if ($chapter_id === null) {
        $version_stmt->bind_param('is', $project_id, $type);
    } else {
        $version_stmt->bind_param('isi', $project_id, $type, $chapter_id);
    }
    if (!$version_stmt->execute()) {
        $error = $version_stmt->error;
        $version_stmt->close();
        throw new RuntimeException('Unable to determine submission version: ' . $error);
    }
    $version_number = (int) ($version_stmt->get_result()->fetch_assoc()['next_version'] ?? 1);
    $version_stmt->close();

    $snapshot_json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insert = $conn->prepare(
        "INSERT INTO research_submission_versions
            (project_id, chapter_id, submission_type, version_number, submitted_by, submitted_at, status, snapshot_json)
         VALUES (?, ?, ?, ?, ?, NOW(), 'submitted', ?)"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare submission version insert: ' . $conn->error);
    }
    $insert->bind_param('iisiis', $project_id, $chapter_id, $type, $version_number, $submitter_id, $snapshot_json);
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        throw new RuntimeException('Unable to preserve submitted version: ' . $error);
    }
    $version_id = (int) $insert->insert_id;
    $insert->close();

    if ($upload_ids) {
        $link = $conn->prepare('INSERT INTO research_submission_version_uploads (version_id, upload_id) VALUES (?, ?)');
        if (!$link) {
            throw new RuntimeException('Unable to prepare submitted attachment links: ' . $conn->error);
        }
        foreach (array_unique(array_map('intval', $upload_ids)) as $upload_id) {
            if ($upload_id <= 0) {
                continue;
            }
            $link->bind_param('ii', $version_id, $upload_id);
            if (!$link->execute()) {
                $error = $link->error;
                $link->close();
                throw new RuntimeException('Unable to preserve submitted attachment: ' . $error);
            }
        }
        $link->close();
    }

    return $version_id;
}

function rms_submission_can_review(int $user_id, string $decision): bool
{
    if ($user_id <= 0 || !in_array($decision, ['approved', 'returned'], true)) {
        return false;
    }

    $user_stmt = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    if (!$user_stmt) {
        return false;
    }
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $role = (string) ($user_stmt->get_result()->fetch_assoc()['role'] ?? '');
    $user_stmt->close();
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'research_staff') {
        return false;
    }

    $permission = $decision === 'approved' ? 'research_approval' : 'research_review';
    return hasModulePermission($user_id, 'research_development', 'manage', $permission);
}

function rms_review_submission(mysqli $conn, int $version_id, int $reviewer_id, string $decision, string $comments): array
{
    if ($version_id <= 0 || !rms_submission_can_review($reviewer_id, $decision)) {
        throw new RuntimeException('You are not authorized to make this submission decision.');
    }
    if ($decision === 'returned' && mb_strlen(trim($comments)) < 10) {
        throw new InvalidArgumentException('Add meaningful comments and recommendations (at least 10 characters).');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT v.version_id, v.project_id, v.chapter_id, v.submission_type, v.version_number, v.status,
                    rp.title, rp.created_by, rp.status AS project_status,
                    ch.status AS chapter_status, ch.chapter_number
               FROM research_submission_versions v
               JOIN research_projects rp ON rp.project_id = v.project_id
          LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
              WHERE v.version_id = ?
              FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare submission review lookup.');
        }
        $stmt->bind_param('i', $version_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load the submission for review.');
        }
        $version = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$version || $version['status'] !== 'submitted') {
            throw new RuntimeException('This version is no longer awaiting review.');
        }

        $latest = $conn->prepare(
            'SELECT version_id FROM research_submission_versions
              WHERE project_id = ? AND submission_type = ? AND chapter_id ' . ($version['chapter_id'] === null ? 'IS NULL' : '= ?') . '
              ORDER BY version_number DESC LIMIT 1'
        );
        if (!$latest) {
            throw new RuntimeException('Unable to verify the current submission version.');
        }
        if ($version['chapter_id'] === null) {
            $latest->bind_param('is', $version['project_id'], $version['submission_type']);
        } else {
            $latest->bind_param('isi', $version['project_id'], $version['submission_type'], $version['chapter_id']);
        }
        $latest->execute();
        $latest_id = (int) ($latest->get_result()->fetch_assoc()['version_id'] ?? 0);
        $latest->close();
        if ($latest_id !== $version_id) {
            throw new RuntimeException('Only the latest submitted version can be reviewed.');
        }

        $review = $conn->prepare(
            'INSERT INTO research_submission_reviews (version_id, reviewer_id, decision, comments, reviewed_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        if (!$review) {
            throw new RuntimeException('Unable to prepare review history insert.');
        }
        $review->bind_param('iiss', $version_id, $reviewer_id, $decision, $comments);
        if (!$review->execute()) {
            throw new RuntimeException('Unable to record review decision.');
        }
        $review->close();

        $version_status = $decision === 'approved' ? 'approved' : 'returned';
        $version_update = $conn->prepare('UPDATE research_submission_versions SET status = ? WHERE version_id = ? AND status = \'submitted\'');
        if (!$version_update) {
            throw new RuntimeException('Unable to prepare version status update.');
        }
        $version_update->bind_param('si', $version_status, $version_id);
        if (!$version_update->execute() || $version_update->affected_rows !== 1) {
            throw new RuntimeException('The submission version changed while it was being reviewed.');
        }
        $version_update->close();

        if ($version['submission_type'] === 'title') {
            $project_status = $decision === 'approved' ? 'title_approved' : 'for_revision';
            $project_update = $conn->prepare(
                "UPDATE research_projects SET status = ?, updated_at = NOW()
                  WHERE project_id = ? AND status IN ('submitted','under_review','under_crec_review','under_erec_review')"
            );
            if (!$project_update) {
                throw new RuntimeException('Unable to prepare title decision update.');
            }
            $project_update->bind_param('si', $project_status, $version['project_id']);
        } else {
            $chapter_status = $decision === 'approved' ? 'approved' : 'revision_required';
            $project_update = $decision === 'approved'
                ? $conn->prepare("UPDATE chapters SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE chapter_id = ? AND status IN ('submitted','under_review')")
                : $conn->prepare("UPDATE chapters SET status = ?, approved_by = NULL, approved_at = NULL, updated_at = NOW() WHERE chapter_id = ? AND status IN ('submitted','under_review')");
            if (!$project_update) {
                throw new RuntimeException('Unable to prepare chapter decision update.');
            }
            if ($decision === 'approved') {
                $project_update->bind_param('sii', $chapter_status, $reviewer_id, $version['chapter_id']);
            } else {
                $project_update->bind_param('si', $chapter_status, $version['chapter_id']);
            }
        }
        if (!$project_update->execute() || $project_update->affected_rows !== 1) {
            throw new RuntimeException('The submission status changed before the decision could be recorded.');
        }
        $project_update->close();
        $conn->commit();
        return $version;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
