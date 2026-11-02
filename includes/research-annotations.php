<?php
declare(strict_types=1);

function rms_submission_annotation_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rms_submission_annotation_normalize_anchors(string $anchor_json): string
{
    if (strlen($anchor_json) > 65535) {
        throw new InvalidArgumentException('The selected highlight is too large. Select a smaller passage.');
    }

    $anchors = json_decode($anchor_json, true);
    if (!is_array($anchors) || !array_is_list($anchors) || !$anchors || count($anchors) > 100) {
        throw new InvalidArgumentException('Select a passage or page area before adding a comment.');
    }

    $normalized = [];
    $rectCount = 0;
    foreach ($anchors as $pageAnchor) {
        if (!is_array($pageAnchor)
            || !is_int($pageAnchor['page'] ?? null)
            || $pageAnchor['page'] < 1
            || !is_array($pageAnchor['rects'] ?? null)
            || !array_is_list($pageAnchor['rects'])
            || !$pageAnchor['rects']) {
            throw new InvalidArgumentException('The selected highlight position is invalid.');
        }

        $rects = [];
        foreach ($pageAnchor['rects'] as $rect) {
            if (!is_array($rect) || !array_is_list($rect) || count($rect) !== 4) {
                throw new InvalidArgumentException('The selected highlight position is invalid.');
            }
            $values = [];
            foreach ($rect as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException('The selected highlight position is invalid.');
                }
                $number = (float) $value;
                if (!is_finite($number)) {
                    throw new InvalidArgumentException('The selected highlight position is invalid.');
                }
                $values[] = $number;
            }
            [$x, $y, $width, $height] = $values;
            if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0
                || $x + $width > 1.01 || $y + $height > 1.01) {
                throw new InvalidArgumentException('The selected highlight position is outside the document page.');
            }
            $rects[] = [$x, $y, $width, $height];
            $rectCount++;
            if ($rectCount > 500) {
                throw new InvalidArgumentException('Select a smaller passage before adding a comment.');
            }
        }

        $normalized[] = [
            'page' => $pageAnchor['page'],
            'rects' => $rects,
        ];
    }

    return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function rms_submission_annotation_list(mysqli $conn, int $version_id, int $upload_id): array
{
    $stmt = $conn->prepare(
        'SELECT annotation_id, selected_text, anchor_json, comment_text, highlight_color, created_at, updated_at
           FROM research_submission_annotations
          WHERE version_id = ? AND upload_id = ?
          ORDER BY created_at, annotation_id'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load document annotations.');
    }
    $stmt->bind_param('ii', $version_id, $upload_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load document annotations.');
    }

    $annotations = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $anchor = json_decode((string) $row['anchor_json'], true);
        if (!is_array($anchor) || !array_is_list($anchor)) {
            continue;
        }
        $annotations[] = [
            'id' => (int) $row['annotation_id'],
            'selected_text' => (string) ($row['selected_text'] ?? ''),
            'anchors' => $anchor,
            'comment' => (string) $row['comment_text'],
            'color' => (string) $row['highlight_color'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
    $stmt->close();
    return $annotations;
}

function rms_submission_annotation_count(mysqli $conn, int $version_id): int
{
    if ($version_id <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS annotation_count
           FROM research_submission_annotations
          WHERE version_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check whether this version has document annotations.');
    }
    $stmt->bind_param('i', $version_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to check whether this version has document annotations.');
    }
    $count = (int) ($stmt->get_result()->fetch_assoc()['annotation_count'] ?? 0);
    $stmt->close();
    return $count;
}

function rms_submission_annotation_save(
    mysqli $conn,
    int $version_id,
    int $upload_id,
    int $reviewer_id,
    string $selected_text,
    string $anchor_json,
    string $comment,
    string $highlight_color = 'yellow',
    ?int $annotation_id = null
): int {
    if ($version_id <= 0 || $upload_id <= 0 || $reviewer_id <= 0
        || !rms_submission_can_review($reviewer_id, 'returned')) {
        throw new RuntimeException('You are not authorized to annotate this submission. Annotating a version requires return-review permission.');
    }

    $selected_text = trim($selected_text);
    $comment = trim($comment);
    $highlight_color = strtolower(trim($highlight_color));
    if (!in_array($highlight_color, ['yellow', 'blue', 'green', 'pink', 'purple'], true)) {
        throw new InvalidArgumentException('Choose one of the available highlight colors.');
    }
    if (mb_strlen($selected_text) > 5000) {
        throw new InvalidArgumentException('The selected text must be 5,000 characters or fewer.');
    }
    if ($comment === '' || mb_strlen($comment) > 5000) {
        throw new InvalidArgumentException('Enter a comment of no more than 5,000 characters.');
    }
    $anchor_json = rms_submission_annotation_normalize_anchors($anchor_json);

    $conn->begin_transaction();
    try {
        $version = $conn->prepare('SELECT status FROM research_submission_versions WHERE version_id = ? FOR UPDATE');
        if (!$version) {
            throw new RuntimeException('Unable to prepare the annotation version check.');
        }
        $version->bind_param('i', $version_id);
        if (!$version->execute()) {
            $version->close();
            throw new RuntimeException('Unable to verify the submission version.');
        }
        $versionStatus = (string) ($version->get_result()->fetch_assoc()['status'] ?? '');
        $version->close();
        if ($versionStatus !== 'submitted') {
            throw new RuntimeException('Annotations can only be changed before a review decision is saved.');
        }

        $link = $conn->prepare('SELECT 1 FROM research_submission_version_uploads WHERE version_id = ? AND upload_id = ? LIMIT 1 FOR UPDATE');
        if (!$link) {
            throw new RuntimeException('Unable to prepare the annotation attachment check.');
        }
        $link->bind_param('ii', $version_id, $upload_id);
        if (!$link->execute() || $link->get_result()->num_rows !== 1) {
            $link->close();
            throw new RuntimeException('This document is not attached to the selected submission version.');
        }
        $link->close();

        if ($annotation_id !== null) {
            if ($annotation_id <= 0) {
                throw new InvalidArgumentException('The selected annotation is invalid.');
            }
            $stmt = $conn->prepare(
                'UPDATE research_submission_annotations
                    SET selected_text = NULLIF(?, \'\'), anchor_json = ?, comment_text = ?, highlight_color = ?, updated_by = ?, updated_at = NOW()
                  WHERE annotation_id = ? AND version_id = ? AND upload_id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the annotation update.');
            }
            $stmt->bind_param('ssssiiii', $selected_text, $anchor_json, $comment, $highlight_color, $reviewer_id, $annotation_id, $version_id, $upload_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to update the document annotation.');
            }
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $check = $conn->prepare('SELECT 1 FROM research_submission_annotations WHERE annotation_id = ? AND version_id = ? AND upload_id = ? LIMIT 1');
                if (!$check) {
                    throw new RuntimeException('Unable to verify the document annotation.');
                }
                $check->bind_param('iii', $annotation_id, $version_id, $upload_id);
                $check->execute();
                $exists = $check->get_result()->num_rows === 1;
                $check->close();
                if (!$exists) {
                    throw new RuntimeException('The selected annotation no longer exists.');
                }
            } else {
                $stmt->close();
            }
            $savedId = $annotation_id;
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO research_submission_annotations
                    (version_id, upload_id, reviewer_id, updated_by, selected_text, anchor_json, comment_text, highlight_color)
                 VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the annotation insert.');
            }
            $stmt->bind_param('iiiissss', $version_id, $upload_id, $reviewer_id, $reviewer_id, $selected_text, $anchor_json, $comment, $highlight_color);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to save the document annotation.');
            }
            $savedId = (int) $conn->insert_id;
            $stmt->close();
        }

        $conn->commit();
        return $savedId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function rms_submission_annotation_delete(
    mysqli $conn,
    int $version_id,
    int $upload_id,
    int $reviewer_id,
    int $annotation_id
): void {
    if ($version_id <= 0 || $upload_id <= 0 || $reviewer_id <= 0 || $annotation_id <= 0
        || !rms_submission_can_review($reviewer_id, 'returned')) {
        throw new RuntimeException('You are not authorized to remove this annotation. Annotating a version requires return-review permission.');
    }

    $conn->begin_transaction();
    try {
        $version = $conn->prepare('SELECT status FROM research_submission_versions WHERE version_id = ? FOR UPDATE');
        if (!$version) {
            throw new RuntimeException('Unable to prepare the annotation version check.');
        }
        $version->bind_param('i', $version_id);
        if (!$version->execute()) {
            $version->close();
            throw new RuntimeException('Unable to verify the submission version.');
        }
        $versionStatus = (string) ($version->get_result()->fetch_assoc()['status'] ?? '');
        $version->close();
        if ($versionStatus !== 'submitted') {
            throw new RuntimeException('Annotations can only be changed before a review decision is saved.');
        }

        $stmt = $conn->prepare(
            'DELETE FROM research_submission_annotations
              WHERE annotation_id = ? AND version_id = ? AND upload_id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the annotation removal.');
        }
        $stmt->bind_param('iii', $annotation_id, $version_id, $upload_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('The selected annotation no longer exists.');
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
