<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['faculty', 'student'], true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

function personalDashboardEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personalDashboardTableExists(mysqli $connection, string $table): bool
{
    $allowedTables = [
        'research_activities',
        'publications',
        'publication_contributors',
        'copyright_applications',
        'copyright_contributors',
        'notifications',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $connection->prepare("SHOW TABLES LIKE '{$table}'");
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function personalDashboardStatusBadge(string $status): array
{
    return match ($status) {
        'scheduled', 'pending', 'submitted' => ['label' => ucfirst($status), 'tone' => 'warning'],
        'under_review' => ['label' => 'Under review', 'tone' => 'review'],
        'accepted' => ['label' => 'Accepted', 'tone' => 'info'],
        'completed', 'published', 'registered' => ['label' => ucfirst($status), 'tone' => 'success'],
        'cancelled', 'rejected', 'error' => ['label' => ucfirst($status), 'tone' => 'danger'],
        'warning' => ['label' => 'Notice', 'tone' => 'warning'],
        'success' => ['label' => 'Update', 'tone' => 'success'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'tone' => 'neutral'],
    };
}

function personalDashboardRenderResearchBasicsLessonProgress(
    string $lessonId,
    array $lessonProgress,
    bool $lessonsAvailable,
    bool $accountStateAvailable,
    int $lastAvailableIndex
): void {
    if (!array_key_exists($lessonId, $lessonProgress)) {
        return;
    }

    echo '<div class="research-basics-lesson-progress">';
    if (!$lessonsAvailable || !$accountStateAvailable) {
        echo '<p class="research-basics-lesson-unavailable" role="status">Saved lesson progress is unavailable right now.</p>';
    } elseif ($lessonProgress[$lessonId]) {
        echo '<form method="post" action="' . personalDashboardEscape(SITE_URL . 'pages/student/student-dashboard.php#' . rawurlencode($lessonId)) . '">';
        echo csrfField();
        echo '<input type="hidden" name="complete_research_basics_lesson" value="1">';
        echo '<input type="hidden" name="lesson_id" value="' . personalDashboardEscape($lessonId) . '">';
        echo '<input type="hidden" name="lesson_action" value="undo">';
        echo '<button class="research-basics-mark is-complete" type="submit" aria-pressed="true">✓ Lesson complete (undo)</button>';
        echo '</form>';
    } else {
        echo '<form method="post" action="' . personalDashboardEscape(SITE_URL . 'pages/student/student-dashboard.php#' . rawurlencode($lessonId)) . '">';
        echo csrfField();
        echo '<input type="hidden" name="complete_research_basics_lesson" value="1">';
        echo '<input type="hidden" name="lesson_id" value="' . personalDashboardEscape($lessonId) . '">';
        echo '<input type="hidden" name="lesson_action" value="complete">';
        echo '<button class="research-basics-mark" type="submit" aria-pressed="false">Mark lesson complete</button>';
        echo '</form>';
        echo '<p class="research-basics-lesson-hint" role="status">' . ($lessonId === 'roadmap' ? 'Mark this lesson complete to unlock the final step.' : 'Mark this lesson complete to unlock the next lesson.') . '</p>';
    }
    echo '</div>';

    $lessonIds = array_keys($lessonProgress);
    $lessonIndex = array_search($lessonId, $lessonIds, true);
    echo '<nav class="research-basics-lesson-navigation" aria-label="Lesson navigation">';
    if ($lessonIndex > 0) {
        echo '<a href="#' . personalDashboardEscape($lessonIds[$lessonIndex - 1]) . '">&larr; Previous lesson</a>';
    }
    if ($lessonIndex < $lastAvailableIndex) {
        echo '<a class="research-basics-next-lesson" href="#' . personalDashboardEscape($lessonIds[$lessonIndex + 1]) . '">Next lesson &rarr;</a>';
    } else {
        echo '<a class="research-basics-overview-link" href="#research-basics-start">Back to overview &uarr;</a>';
    }
    echo '</nav>';
}

function personalDashboardRenderResearchBasicsLockNotice(
    string $lessonId,
    int $lessonIndex,
    array $lessonIds,
    array $lessons,
    ?int $lockedLessonIndex
): void {
    if ($lockedLessonIndex !== $lessonIndex || !isset($lessonIds[$lessonIndex - 1])) {
        return;
    }

    $prerequisiteId = $lessonIds[$lessonIndex - 1];
    echo '<p class="research-basics-lock-notice" id="research-basics-lock-note-' . personalDashboardEscape($lessonId) . '" role="status">🔒 Locked preview — complete '
        . personalDashboardEscape($lessons[$prerequisiteId]) . ' to unlock ' . personalDashboardEscape($lessons[$lessonId]) . '.</p>';
}

function personalDashboardRenderResearchBasicsStageProgress(
    string $stageId,
    string $stageName,
    array $stageProgress,
    bool $stagesAvailable,
    bool $accountStateAvailable
): void {
    if (!array_key_exists($stageId, $stageProgress)) {
        return;
    }

    $isComplete = $stageProgress[$stageId];
    echo '<div class="research-roadmap-stage-control">';
    if (!$stagesAvailable || !$accountStateAvailable) {
        echo '<p class="research-roadmap-stage-status" role="status">Saved progress for ' . personalDashboardEscape($stageName) . ' is unavailable right now.</p>';
    } else {
        echo '<p class="research-roadmap-stage-status" role="status">' . ($isComplete ? 'Completed: ' : 'Not yet completed: ') . personalDashboardEscape($stageName) . '</p>';
        echo '<form method="post" action="' . personalDashboardEscape(SITE_URL . 'pages/student/student-dashboard.php#roadmap') . '">';
        echo csrfField();
        echo '<input type="hidden" name="update_research_basics_stage" value="1">';
        echo '<input type="hidden" name="stage_id" value="' . personalDashboardEscape($stageId) . '">';
        echo '<input type="hidden" name="stage_action" value="' . ($isComplete ? 'undo' : 'complete') . '">';
        echo '<button type="submit" aria-label="' . ($isComplete ? 'Undo completion of ' : 'Mark ') . personalDashboardEscape($stageName) . ($isComplete ? '' : ' complete') . '">' . ($isComplete ? 'Undo stage completion' : 'Mark stage complete') . '</button>';
        echo '</form>';
    }
    echo '</div>';
}

$userId = (int) ($user['user_id'] ?? 0);
$studentHasApprovedTitle = $role === 'student' && rms_student_has_approved_title($conn, $userId);
$researchBasicsCompleted = false;
$researchBasicsStateAvailable = true;
$researchBasicsLessons = [
    'manual' => 'Manual',
    'foundations' => 'Foundations',
    'chapters-1-5' => 'Chapters 1-5',
    'methodology' => 'Methodology',
    'citation-apa' => 'Citation and APA',
    'roadmap' => 'Roadmap',
];
$researchBasicsLessonProgress = array_fill_keys(array_keys($researchBasicsLessons), false);
$researchBasicsLessonsAvailable = true;
$researchBasicsStages = [
    'understand-research' => 'Understand research',
    'find-research-problem' => 'Find a research problem',
    'focus-question' => 'Focus the question',
    'draft-research-title' => 'Draft a research title',
    'review-related-literature' => 'Review related literature',
    'choose-methodology' => 'Choose a methodology',
    'submit-title-review' => 'Submit the title for review',
    'write-chapters-1-3' => 'Write Chapters 1-3',
    'complete-chapters-4-5' => 'Complete Chapters 4-5',
];
$researchBasicsStageProgress = array_fill_keys(array_keys($researchBasicsStages), false);
$researchBasicsStagesAvailable = true;
$researchBasicsError = '';
$researchBasicsNavigationTransition = false;
if ($role === 'student') {
    $stateStmt = null;
    try {
        $stateStmt = $conn->prepare('SELECT research_basics_completed FROM users WHERE user_id = ? AND role = ? AND deleted_at IS NULL LIMIT 1');
        if (!$stateStmt) {
            error_log('Research Basics completion lookup could not be prepared: ' . $conn->error);
            $researchBasicsStateAvailable = false;
            $researchBasicsError = 'Research Basics could not load your saved progress. Please contact support.';
        } else {
            $studentRole = 'student';
            $stateStmt->bind_param('is', $userId, $studentRole);
            if (!$stateStmt->execute()) {
                error_log('Research Basics completion lookup failed: ' . $stateStmt->error);
                $researchBasicsStateAvailable = false;
                $researchBasicsError = 'Research Basics could not load your saved progress. Please contact support.';
            } else {
                $stateResult = $stateStmt->get_result();
                $stateRow = $stateResult ? $stateResult->fetch_assoc() : null;
                if (!$stateRow) {
                    $researchBasicsStateAvailable = false;
                    $researchBasicsError = 'Research Basics could not find your student account. Please sign in again.';
                } else {
                    $researchBasicsCompleted = (int) $stateRow['research_basics_completed'] === 1;
                }
            }
            $stateStmt->close();
            $stateStmt = null;
        }
    } catch (mysqli_sql_exception $exception) {
        if ($stateStmt instanceof mysqli_stmt) $stateStmt->close();
        error_log('Research Basics completion lookup failed: ' . $exception->getMessage());
        $researchBasicsStateAvailable = false;
        $researchBasicsError = 'Research Basics could not load your saved progress. Please contact support.';
    }

    $lessonProgressStmt = null;
    try {
        $lessonProgressStmt = $conn->prepare('SELECT lesson_id FROM research_basics_lesson_progress WHERE user_id = ?');
        if (!$lessonProgressStmt) {
            error_log('Research Basics lesson progress lookup could not be prepared: ' . $conn->error);
            $researchBasicsLessonsAvailable = false;
        } else {
            $lessonProgressStmt->bind_param('i', $userId);
            if (!$lessonProgressStmt->execute()) {
                error_log('Research Basics lesson progress lookup failed: ' . $lessonProgressStmt->error);
                $researchBasicsLessonsAvailable = false;
            } else {
                $lessonProgressResult = $lessonProgressStmt->get_result();
                while ($lessonProgressResult && ($lessonProgressRow = $lessonProgressResult->fetch_assoc())) {
                    $lessonId = (string) ($lessonProgressRow['lesson_id'] ?? '');
                    if (array_key_exists($lessonId, $researchBasicsLessonProgress)) {
                        $researchBasicsLessonProgress[$lessonId] = true;
                    }
                }
            }
            $lessonProgressStmt->close();
            $lessonProgressStmt = null;
        }
    } catch (mysqli_sql_exception $exception) {
        if ($lessonProgressStmt instanceof mysqli_stmt) $lessonProgressStmt->close();
        error_log('Research Basics lesson progress lookup failed: ' . $exception->getMessage());
        $researchBasicsLessonsAvailable = false;
    }

    $stageProgressStmt = null;
    try {
        $stageProgressStmt = $conn->prepare('SELECT stage_id FROM research_basics_roadmap_progress WHERE user_id = ?');
        if (!$stageProgressStmt) {
            error_log('Research Basics roadmap progress lookup could not be prepared: ' . $conn->error);
            $researchBasicsStagesAvailable = false;
        } else {
            $stageProgressStmt->bind_param('i', $userId);
            if (!$stageProgressStmt->execute()) {
                error_log('Research Basics roadmap progress lookup failed: ' . $stageProgressStmt->error);
                $researchBasicsStagesAvailable = false;
            } else {
                $stageProgressResult = $stageProgressStmt->get_result();
                while ($stageProgressResult && ($stageProgressRow = $stageProgressResult->fetch_assoc())) {
                    $stageId = (string) ($stageProgressRow['stage_id'] ?? '');
                    if (array_key_exists($stageId, $researchBasicsStageProgress)) {
                        $researchBasicsStageProgress[$stageId] = true;
                    }
                }
            }
            $stageProgressStmt->close();
            $stageProgressStmt = null;
        }
    } catch (mysqli_sql_exception $exception) {
        if ($stageProgressStmt instanceof mysqli_stmt) $stageProgressStmt->close();
        error_log('Research Basics roadmap progress lookup failed: ' . $exception->getMessage());
        $researchBasicsStagesAvailable = false;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_research_basics_lesson'])) {
        $lessonId = is_string($_POST['lesson_id'] ?? null) ? $_POST['lesson_id'] : '';
        $lessonAction = is_string($_POST['lesson_action'] ?? null) ? $_POST['lesson_action'] : '';
        $lessonIds = array_keys($researchBasicsLessons);
        $lessonIndex = array_search($lessonId, $lessonIds, true);
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $researchBasicsError = 'Your form has expired. Please refresh the page and try again.';
        } elseif (!array_key_exists($lessonId, $researchBasicsLessons) || !in_array($lessonAction, ['complete', 'undo'], true)) {
            $researchBasicsError = 'That Research Basics lesson could not be recognized.';
        } elseif (!$researchBasicsStateAvailable || !$researchBasicsLessonsAvailable) {
            $researchBasicsError = 'Your lesson progress could not be saved. Please try again later.';
        } elseif ($lessonAction === 'complete' && $lessonIndex > 0
            && in_array(false, array_slice(array_values($researchBasicsLessonProgress), 0, $lessonIndex), true)) {
            $researchBasicsError = 'Complete the earlier Research Basics lessons before unlocking this one.';
        } else {
            $lessonInsertStmt = null;
            $ownedLessonStmt = null;
            $lessonDeleteStmt = null;
            $studentAccountStmt = null;
            try {
                $conn->begin_transaction();
                $studentAccountStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'student' AND deleted_at IS NULL LIMIT 1");
                if (!$studentAccountStmt) {
                    throw new mysqli_sql_exception('Research Basics student ownership check could not be prepared: ' . $conn->error);
                }
                $studentAccountStmt->bind_param('i', $userId);
                if (!$studentAccountStmt->execute()) {
                    throw new mysqli_sql_exception('Research Basics student ownership check failed: ' . $studentAccountStmt->error);
                }
                $studentAccountResult = $studentAccountStmt->get_result();
                $studentAccountExists = $studentAccountResult && $studentAccountResult->num_rows === 1;
                $studentAccountStmt->close();
                $studentAccountStmt = null;
                if (!$studentAccountExists) {
                    throw new mysqli_sql_exception('Research Basics lesson update was not attached to an active student account.');
                }

                if ($lessonAction === 'complete') {
                    $lessonInsertStmt = $conn->prepare(
                        'INSERT INTO research_basics_lesson_progress (user_id, lesson_id)
                         SELECT user_id, ? FROM users
                         WHERE user_id = ? AND role = \'student\' AND deleted_at IS NULL
                         ON DUPLICATE KEY UPDATE completed_at = research_basics_lesson_progress.completed_at'
                    );
                    if (!$lessonInsertStmt) {
                        throw new mysqli_sql_exception('Research Basics lesson progress insert could not be prepared: ' . $conn->error);
                    }
                    $lessonInsertStmt->bind_param('si', $lessonId, $userId);
                    if (!$lessonInsertStmt->execute()) {
                        throw new mysqli_sql_exception('Research Basics lesson progress insert failed: ' . $lessonInsertStmt->error);
                    }
                    $lessonInsertStmt->close();
                    $lessonInsertStmt = null;

                    $ownedLessonStmt = $conn->prepare(
                        'SELECT p.lesson_id
                         FROM research_basics_lesson_progress AS p
                         INNER JOIN users AS u ON u.user_id = p.user_id
                         WHERE p.user_id = ? AND p.lesson_id = ? AND u.role = \'student\' AND u.deleted_at IS NULL
                         LIMIT 1'
                    );
                    if (!$ownedLessonStmt) {
                        throw new mysqli_sql_exception('Research Basics lesson progress confirmation could not be prepared: ' . $conn->error);
                    }
                    $ownedLessonStmt->bind_param('is', $userId, $lessonId);
                    if (!$ownedLessonStmt->execute()) {
                        throw new mysqli_sql_exception('Research Basics lesson progress confirmation failed: ' . $ownedLessonStmt->error);
                    }
                    $ownedLessonResult = $ownedLessonStmt->get_result();
                    $lessonStateConfirmed = $ownedLessonResult && $ownedLessonResult->num_rows === 1;
                } else {
                    $lessonDeleteStmt = $conn->prepare(
                        'DELETE progress
                         FROM research_basics_lesson_progress AS progress
                         INNER JOIN users AS student ON student.user_id = progress.user_id
                         WHERE progress.user_id = ? AND progress.lesson_id = ?
                           AND student.role = \'student\' AND student.deleted_at IS NULL'
                    );
                    if (!$lessonDeleteStmt) {
                        throw new mysqli_sql_exception('Research Basics lesson undo could not be prepared: ' . $conn->error);
                    }
                    $lessonDeleteStmt->bind_param('is', $userId, $lessonId);
                    if (!$lessonDeleteStmt->execute()) {
                        throw new mysqli_sql_exception('Research Basics lesson undo failed: ' . $lessonDeleteStmt->error);
                    }
                    $lessonDeleteStmt->close();
                    $lessonDeleteStmt = null;

                    $ownedLessonStmt = $conn->prepare(
                        'SELECT u.user_id
                         FROM users AS u
                         WHERE u.user_id = ? AND u.role = \'student\' AND u.deleted_at IS NULL
                           AND NOT EXISTS (
                             SELECT 1 FROM research_basics_lesson_progress AS progress
                             WHERE progress.user_id = u.user_id AND progress.lesson_id = ?
                           )
                         LIMIT 1'
                    );
                    if (!$ownedLessonStmt) {
                        throw new mysqli_sql_exception('Research Basics lesson undo confirmation could not be prepared: ' . $conn->error);
                    }
                    $ownedLessonStmt->bind_param('is', $userId, $lessonId);
                    if (!$ownedLessonStmt->execute()) {
                        throw new mysqli_sql_exception('Research Basics lesson undo confirmation failed: ' . $ownedLessonStmt->error);
                    }
                    $ownedLessonResult = $ownedLessonStmt->get_result();
                    $lessonStateConfirmed = $ownedLessonResult && $ownedLessonResult->num_rows === 1;
                }
                $ownedLessonStmt->close();
                $ownedLessonStmt = null;
                if (!$lessonStateConfirmed) {
                    throw new mysqli_sql_exception('Research Basics lesson state was not saved for the signed-in student.');
                }

                $conn->commit();
                $redirectLessonId = $lessonAction === 'complete'
                    ? ($lessonIds[$lessonIndex + 1] ?? 'research-basics-cta')
                    : ($lessonIds[max(0, $lessonIndex - 1)] ?? $lessonId);
                $transitionQuery = '?lesson-transition=' . rawurlencode($redirectLessonId);
                if ($lessonAction === 'undo' && $lessonIndex > 0) {
                    $transitionQuery .= '&lesson-transition-from=' . rawurlencode($lessonId);
                }
                header('Location: ' . SITE_URL . 'pages/student/student-dashboard.php' . $transitionQuery);
                exit;
            } catch (mysqli_sql_exception $exception) {
                if ($lessonInsertStmt instanceof mysqli_stmt) $lessonInsertStmt->close();
                if ($ownedLessonStmt instanceof mysqli_stmt) $ownedLessonStmt->close();
                if ($lessonDeleteStmt instanceof mysqli_stmt) $lessonDeleteStmt->close();
                if ($studentAccountStmt instanceof mysqli_stmt) $studentAccountStmt->close();
                $conn->rollback();
                error_log('Research Basics lesson progress update failed: ' . $exception->getMessage());
                $researchBasicsError = 'Your lesson progress could not be saved. Please try again.';
                $researchBasicsLessonsAvailable = false;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_research_basics_stage'])) {
        $stageId = is_string($_POST['stage_id'] ?? null) ? $_POST['stage_id'] : '';
        $stageAction = is_string($_POST['stage_action'] ?? null) ? $_POST['stage_action'] : '';
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $researchBasicsError = 'Your form has expired. Please refresh the page and try again.';
        } elseif (!array_key_exists($stageId, $researchBasicsStages) || !in_array($stageAction, ['complete', 'undo'], true)) {
            $researchBasicsError = 'That Research Basics roadmap stage could not be recognized.';
        } elseif (!$researchBasicsStateAvailable || !$researchBasicsStagesAvailable) {
            $researchBasicsError = 'Your roadmap progress could not be saved. Please try again later.';
        } else {
            $accountStmt = null;
            $stageUpdateStmt = null;
            try {
                $accountStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'student' AND deleted_at IS NULL LIMIT 1");
                if (!$accountStmt) {
                    throw new mysqli_sql_exception('Research Basics student ownership check could not be prepared: ' . $conn->error);
                }
                $accountStmt->bind_param('i', $userId);
                if (!$accountStmt->execute()) {
                    throw new mysqli_sql_exception('Research Basics student ownership check failed: ' . $accountStmt->error);
                }
                $accountResult = $accountStmt->get_result();
                $studentAccountExists = $accountResult && $accountResult->num_rows === 1;
                $accountStmt->close();
                $accountStmt = null;
                if (!$studentAccountExists) {
                    throw new mysqli_sql_exception('Research Basics roadmap update was not attached to an active student account.');
                }

                if ($stageAction === 'complete') {
                    $stageUpdateStmt = $conn->prepare(
                        'INSERT INTO research_basics_roadmap_progress (user_id, stage_id)
                         SELECT user_id, ? FROM users
                         WHERE user_id = ? AND role = \'student\' AND deleted_at IS NULL
                         ON DUPLICATE KEY UPDATE completed_at = research_basics_roadmap_progress.completed_at'
                    );
                    if (!$stageUpdateStmt) {
                        throw new mysqli_sql_exception('Research Basics roadmap completion could not be prepared: ' . $conn->error);
                    }
                    $stageUpdateStmt->bind_param('si', $stageId, $userId);
                } else {
                    $stageUpdateStmt = $conn->prepare(
                        'DELETE progress
                         FROM research_basics_roadmap_progress AS progress
                         INNER JOIN users AS student ON student.user_id = progress.user_id
                         WHERE progress.user_id = ? AND progress.stage_id = ?
                           AND student.role = \'student\' AND student.deleted_at IS NULL'
                    );
                    if (!$stageUpdateStmt) {
                        throw new mysqli_sql_exception('Research Basics roadmap undo could not be prepared: ' . $conn->error);
                    }
                    $stageUpdateStmt->bind_param('is', $userId, $stageId);
                }
                if (!$stageUpdateStmt->execute()) {
                    throw new mysqli_sql_exception('Research Basics roadmap update failed: ' . $stageUpdateStmt->error);
                }
                $stageUpdateStmt->close();
                $stageUpdateStmt = null;

                header('Location: ' . SITE_URL . 'pages/student/student-dashboard.php#roadmap');
                exit;
            } catch (mysqli_sql_exception $exception) {
                if ($accountStmt instanceof mysqli_stmt) $accountStmt->close();
                if ($stageUpdateStmt instanceof mysqli_stmt) $stageUpdateStmt->close();
                error_log('Research Basics roadmap update failed: ' . $exception->getMessage());
                $researchBasicsError = 'Your roadmap progress could not be saved. Please try again.';
                $researchBasicsStagesAvailable = false;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_research_basics'])) {
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $researchBasicsError = 'Your form has expired. Please refresh the page and try again.';
        } elseif (!$researchBasicsStateAvailable) {
            $researchBasicsError = 'Your progress could not be saved because account status is unavailable.';
        } elseif (!$researchBasicsLessonsAvailable || in_array(false, $researchBasicsLessonProgress, true)) {
            $researchBasicsError = 'Complete all six Research Basics lessons before continuing.';
        } else {
            $updateStmt = null;
            try {
                $updateStmt = $conn->prepare("UPDATE users SET research_basics_completed = 1 WHERE user_id = ? AND role = 'student' AND deleted_at IS NULL");
                if (!$updateStmt) {
                    error_log('Research Basics completion update could not be prepared: ' . $conn->error);
                    $researchBasicsError = 'Your progress could not be saved. Please try again.';
                } else {
                    $updateStmt->bind_param('i', $userId);
                    if (!$updateStmt->execute()) {
                        error_log('Research Basics completion update failed: ' . $updateStmt->error);
                        $researchBasicsError = 'Your progress could not be saved. Please try again.';
                    } else {
                        $updateStmt->close();
                        $updateStmt = null;
                        if (rmsHasResearchArchiveReturnIntent()) {
                            rmsClearResearchArchiveReturnIntent();
                            header('Location: ' . SITE_URL . 'public/research-archive.php');
                            exit;
                        }
                        header('Location: ' . SITE_URL . 'pages/student/student-dashboard.php?research-basics=started#research-basics-start');
                        exit;
                    }
                    $updateStmt->close();
                    $updateStmt = null;
                }
            } catch (mysqli_sql_exception $exception) {
                if ($updateStmt instanceof mysqli_stmt) $updateStmt->close();
                error_log('Research Basics completion update failed: ' . $exception->getMessage());
                $researchBasicsError = 'Your progress could not be saved. Please try again.';
            }
        }
    }

    $researchBasicsNavigationTransition = $researchBasicsCompleted
        && ($_GET['research-basics'] ?? '') === 'started';
}
$researchBasicsLessonCompletedCount = count(array_filter($researchBasicsLessonProgress));
$researchBasicsStageCompletedCount = count(array_filter($researchBasicsStageProgress));
$researchBasicsLessonIds = array_keys($researchBasicsLessons);
$researchBasicsFirstIncompleteIndex = array_search(false, array_values($researchBasicsLessonProgress), true);
$researchBasicsLockedLessonIndex = !$researchBasicsCompleted
    && $researchBasicsFirstIncompleteIndex !== false
    && $researchBasicsFirstIncompleteIndex < count($researchBasicsLessonIds) - 1
        ? $researchBasicsFirstIncompleteIndex + 1
        : null;

$researchBasicsLastAvailableIndex = !$researchBasicsCompleted && $researchBasicsFirstIncompleteIndex !== false
    ? (int) $researchBasicsFirstIncompleteIndex
    : count($researchBasicsLessonIds) - 1;

$tables = $role === 'faculty'
    ? [
        'activities' => personalDashboardTableExists($conn, 'research_activities'),
        'publications' => personalDashboardTableExists($conn, 'publications'),
        'publicationContributors' => personalDashboardTableExists($conn, 'publication_contributors'),
        'copyrights' => personalDashboardTableExists($conn, 'copyright_applications'),
        'copyrightContributors' => personalDashboardTableExists($conn, 'copyright_contributors'),
        'notifications' => personalDashboardTableExists($conn, 'notifications'),
    ]
    : ['activities' => false, 'publications' => false, 'publicationContributors' => false, 'copyrights' => false, 'copyrightContributors' => false, 'notifications' => false];

$publicationCounts = ['archived' => 0, 'legacy' => 0];
$recentPublications = [];
if ($tables['publications'] && $tables['publicationContributors']) {
    $stmt = $conn->prepare('SELECT p.archival_status AS status, COUNT(*) AS total FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?) GROUP BY p.archival_status');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $publicationCounts)) $publicationCounts[$status] = (int) $row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT p.publication_id, p.research_title, p.publication_type, p.publication_date, p.archival_status AS status, p.updated_at FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?) ORDER BY p.updated_at DESC, p.publication_id DESC LIMIT 5');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentPublications[] = $row;
    $stmt->close();
}

$copyrightCounts = ['archived' => 0, 'legacy' => 0];
$recentCopyrights = [];
if ($tables['copyrights'] && $tables['copyrightContributors']) {
    $stmt = $conn->prepare("SELECT c.archival_status AS status, COUNT(*) AS total FROM copyright_applications c WHERE c.output_type IN ('software','research','instructional_material','module','other') AND c.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = ?) GROUP BY c.archival_status");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $copyrightCounts)) $copyrightCounts[$status] = (int) $row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT c.copyright_id, c.output_title, c.output_type, c.copyright_ref_no, c.archival_status AS status, c.updated_at FROM copyright_applications c WHERE c.output_type IN ('software','research','instructional_material','module','other') AND c.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = ?) ORDER BY c.updated_at DESC, c.copyright_id DESC LIMIT 5");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentCopyrights[] = $row;
    $stmt->close();
}

$upcomingActivities = [];
if ($tables['activities']) {
    $scheduled = 'scheduled';
    $stmt = $conn->prepare('SELECT activity_id, activity_type, title, activity_date, activity_time, venue FROM research_activities WHERE status = ? AND activity_date >= CURDATE() ORDER BY activity_date ASC, activity_time ASC, activity_id ASC LIMIT 5');
    $stmt->bind_param('s', $scheduled);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $upcomingActivities[] = $row;
    $stmt->close();
}

$recentNotifications = [];
if ($tables['notifications']) {
    $stmt = $conn->prepare('SELECT notification_id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 5');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recentNotifications[] = $row;
    $stmt->close();
}

$publicationTotal = array_sum($publicationCounts);
$copyrightTotal = array_sum($copyrightCounts);
$firstName = trim((string) ($user['first_name'] ?? '')) ?: ($role === 'faculty' ? 'Faculty' : 'Student');
$lastName = trim((string) ($user['last_name'] ?? ''));
$greeting = $role === 'faculty' ? 'Good day, Prof. ' . ($lastName ?: $firstName) : 'Welcome back, ' . $firstName;
$roleLabel = $role === 'faculty' ? 'Faculty Researcher' : 'Student Researcher';
$currentPage = $role === 'faculty' ? 'faculty-dashboard.php' : 'student-dashboard.php';
$pageTitle = $role === 'faculty' ? 'Dashboard' : "Let's start learning";
$pageSubtitle = $role === 'faculty'
    ? 'Your research workspace at a glance'
    : 'Learn the foundations before you begin your proposal.';
if ($role === 'faculty') renderFacultyShell($user, $currentPage, 'Dashboard', $pageSubtitle);
else renderStudentShell(
    $user,
    $currentPage,
    $pageTitle,
    $pageSubtitle,
    $researchBasicsStateAvailable && $researchBasicsCompleted,
    $researchBasicsNavigationTransition,
    $researchBasicsStateAvailable && !$researchBasicsCompleted
);
?>

<style>
.module-hub{--accent:#5b1ebc;--accent-dark:#481796;--accent-soft:#f1eafb;--ink:#172033;--copy:#64748b;--line:#e2e8f0;max-width:1380px;margin:0 auto;color:var(--ink)}.module-hub.role-faculty{--accent:#0f766e;--accent-dark:#115e59;--accent-soft:#e7f5f3}.module-greeting{display:flex;justify-content:space-between;gap:26px;align-items:end;margin:0 0 22px;padding:30px;border-left:5px solid var(--accent);border-radius:4px 18px 18px 4px;background:var(--accent-soft)}.module-kicker{margin:0 0 7px;color:var(--accent);font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.module-greeting h1{margin:0;font-size:clamp(27px,3vw,40px);line-height:1.08;letter-spacing:-.04em;text-wrap:balance}.module-greeting p{max-width:650px;margin:10px 0 0;color:#526176;line-height:1.6}.greeting-meta{text-align:right}.greeting-meta strong{display:block;font-size:13px}.greeting-meta span{display:block;margin-top:5px;color:var(--copy);font-size:11px}.quick-links{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:22px}.quick-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #cbd5e1;border-radius:9px;padding:0 15px;background:#fff;color:#334155;font-size:12px;font-weight:800;text-decoration:none;transition:transform .18s ease,border-color .18s ease,color .18s ease,background .18s ease}.quick-link.primary{border-color:var(--accent);background:var(--accent);color:#fff}.quick-link:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px)}.quick-link.primary:hover{background:var(--accent-dark);color:#fff}.quick-link:active{transform:scale(.98)}.dashboard-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:18px}.hub-panel{min-width:0;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px;letter-spacing:-.015em}.panel-head p{margin:5px 0 0;color:var(--copy);font-size:12px;line-height:1.45}.panel-link{color:var(--accent);font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap}.count-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-bottom:1px solid #edf1f5;background:#fbfcfe}.count-item{padding:13px 12px;text-align:center}.count-item+.count-item{border-left:1px solid #edf1f5}.count-item strong{display:block;font-size:20px;font-variant-numeric:tabular-nums}.count-item span{display:block;margin-top:5px;color:var(--copy);font-size:9px;font-weight:800;line-height:1.25;text-transform:uppercase}.record-list{margin:0;padding:0;list-style:none}.record-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:15px 20px;border-bottom:1px solid #edf1f5}.record-item:last-child{border-bottom:0}.record-title{display:block;overflow:hidden;color:var(--ink);font-size:13px;font-weight:750;line-height:1.4;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.record-title:hover{color:var(--accent)}.record-meta{display:block;margin-top:5px;color:var(--copy);font-size:10px;line-height:1.4}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;white-space:nowrap}.tone-warning{background:#fef3c7;color:#92400e}.tone-review{background:#ede9fe;color:#5b21b6}.tone-info{background:#cffafe;color:#155e75}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.activity-list,.notification-list{margin:0;padding:0;list-style:none}.activity-item,.notification-item{display:grid;gap:13px;padding:15px 20px;border-bottom:1px solid #edf1f5}.activity-item{grid-template-columns:56px minmax(0,1fr)}.notification-item{grid-template-columns:10px minmax(0,1fr)}.activity-item:last-child,.notification-item:last-child{border-bottom:0}.activity-date{display:grid;place-items:center;align-content:center;min-height:52px;border-radius:10px;background:var(--accent-soft);color:var(--accent);text-align:center}.activity-date strong{font-size:18px;line-height:1}.activity-date span{margin-top:3px;font-size:9px;font-weight:800;text-transform:uppercase}.activity-title,.notification-title{margin:0;color:var(--ink);font-size:13px;font-weight:750;line-height:1.4}.activity-title a{color:inherit;text-decoration:none}.activity-title a:hover{color:var(--accent)}.activity-meta,.notification-message,.notification-date{margin:4px 0 0;color:var(--copy);font-size:11px;line-height:1.45}.notification-dot{width:8px;height:8px;margin-top:5px;border-radius:50%;background:#94a3b8}.notification-dot.info{background:#2563eb}.notification-dot.success{background:#15803d}.notification-dot.warning{background:#d97706}.notification-dot.error{background:#b42318}.empty-state{padding:42px 22px;text-align:center}.empty-state strong{display:block;font-size:15px}.empty-state p{max-width:430px;margin:7px auto 0;color:var(--copy);font-size:12px;line-height:1.5}.quick-link:focus-visible,.panel-link:focus-visible,.record-title:focus-visible,.activity-title a:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 20%,transparent);outline-offset:3px}@media(max-width:900px){.dashboard-grid{grid-template-columns:1fr}}@media(max-width:640px){.module-greeting{display:block;padding:24px 21px}.greeting-meta{margin-top:18px;text-align:left}.quick-links{display:grid}.quick-link{width:100%;box-sizing:border-box}.count-strip{grid-template-columns:repeat(2,1fr)}.count-item:nth-child(3){border-left:0;border-top:1px solid #edf1f5}.count-item:nth-child(4){border-top:1px solid #edf1f5}.record-item{align-items:start}.record-title{white-space:normal}}
</style>
<style>
.module-hub {
  --accent: #5b1ebc;
  --accent-dark: #481796;
  --accent-soft: #f1ebff;
  --ink: #172033;
  --copy: #617087;
  --line: #e1e7ef;
  max-width: 1440px;
  margin: 0 auto;
  color: var(--ink);
}
.module-hub.role-faculty {
  --accent: #1d4ed8;
  --accent-dark: #1e40af;
  --accent-soft: #eaf0ff;
}
.module-greeting {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 28px;
  overflow: hidden;
  margin: 0 0 16px;
  padding: clamp(24px, 3vw, 36px);
  border: 1px solid color-mix(in srgb, var(--accent) 20%, white);
  border-left: 4px solid var(--accent);
  border-radius: 14px;
  background: radial-gradient(circle at 88% 10%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 34%), linear-gradient(115deg, color-mix(in srgb, var(--accent) 14%, white) 0%, #f8faff 76%);
}
.module-greeting::after {
  position: absolute;
  right: 17%;
  bottom: -82px;
  width: 220px;
  height: 220px;
  border: 1px solid color-mix(in srgb, var(--accent) 20%, transparent);
  border-radius: 50%;
  content: "";
  pointer-events: none;
}
.module-kicker {
  margin: 0 0 8px;
  color: var(--accent);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .11em;
  text-transform: uppercase;
}
.module-greeting h1 {
  margin: 0;
  font-size: clamp(25px, 2.6vw, 36px);
  font-weight: 750;
  line-height: 1.12;
  letter-spacing: -.035em;
  text-wrap: balance;
}
.module-greeting > div:first-child > p:last-child {
  max-width: 620px;
  margin: 10px 0 0;
  color: #526176;
  font-size: 13px;
  line-height: 1.6;
}
.greeting-meta {
  position: relative;
  z-index: 1;
  min-width: 176px;
  padding: 15px 17px;
  border: 1px solid rgba(255, 255, 255, .8);
  border-radius: 11px;
  background: rgba(255, 255, 255, .76);
  text-align: left;
}
.greeting-meta strong {
  display: block;
  color: var(--ink);
  font-size: 12px;
}
.greeting-meta span {
  display: block;
  margin-top: 4px;
  color: var(--copy);
  font-size: 11px;
}
.quick-links {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.quick-link {
  display: inline-flex;
  min-height: 46px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--accent);
  border-radius: 9px;
  padding: 0 14px;
  background: var(--accent);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
  text-decoration: none;
  transition: transform .18s ease, border-color .18s ease, color .18s ease, background .18s ease, box-shadow .18s ease;
  box-shadow: 0 5px 14px color-mix(in srgb, var(--accent) 18%, transparent);
}
.quick-link:hover {
  transform: translateY(-1px);
  border-color: var(--accent-dark);
  background: var(--accent-dark);
  color: #fff;
}
.quick-link:active { transform: scale(.985); }
.module-shortcuts {
  display: flex;
  min-height: 34px;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin: 0 0 18px;
}
.module-shortcuts-label {
  margin-right: 3px;
  color: #718096;
  font-size: 9px;
  font-weight: 750;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.module-shortcuts a {
  display: inline-flex;
  min-height: 30px;
  align-items: center;
  padding: 4px 10px;
  border: 1px solid #e0e6ef;
  border-radius: 7px;
  background: rgba(255, 255, 255, .72);
  color: #4b5a70;
  font-size: 10px;
  font-weight: 650;
  text-decoration: none;
  transition: color .16s ease, border-color .16s ease, background .16s ease;
}
.module-shortcuts a:hover {
  border-color: color-mix(in srgb, var(--accent) 28%, white);
  background: #fff;
  color: var(--accent);
}
.module-shortcuts a:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 24%, transparent);
  outline-offset: 2px;
}
.dashboard-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr);
  gap: 16px;
  margin-bottom: 16px;
}
.dashboard-grid--records { grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr); }
.dashboard-grid--updates { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.hub-panel {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 5px 18px rgba(35, 54, 80, .035);
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 15px 18px;
  border-bottom: 1px solid #e9edf3;
}
.panel-head h2 { margin: 0; font-size: 15px; font-weight: 750; letter-spacing: -.015em; }
.panel-head p { margin: 4px 0 0; color: var(--copy); font-size: 11px; line-height: 1.45; }
.panel-link { color: var(--accent); font-size: 11px; font-weight: 750; text-decoration: none; white-space: nowrap; }
.panel-link:hover { color: var(--accent-dark); text-decoration: underline; text-underline-offset: 3px; }
.count-strip { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-bottom: 1px solid #edf1f5; background: #fafbfd; }
.count-item { padding: 12px 9px; text-align: center; }
.count-item + .count-item { border-left: 1px solid #e9edf3; }
.count-item strong { display: block; color: #152239; font-size: 19px; font-variant-numeric: tabular-nums; }
.count-item span { display: block; margin-top: 4px; color: var(--copy); font-size: 9px; font-weight: 750; line-height: 1.25; text-transform: uppercase; }
.record-list, .activity-list, .notification-list { margin: 0; padding: 0; list-style: none; }
.record-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 14px 19px;
  border-bottom: 1px solid #edf1f5;
}
.record-item:last-child, .activity-item:last-child, .notification-item:last-child { border-bottom: 0; }
.record-title { display: block; overflow: hidden; color: var(--ink); font-size: 12px; font-weight: 700; line-height: 1.4; text-decoration: none; text-overflow: ellipsis; white-space: nowrap; }
.record-title:hover, .activity-title a:hover { color: var(--accent); }
.record-meta { display: block; margin-top: 4px; color: var(--copy); font-size: 10px; line-height: 1.45; }
.badge { display: inline-flex; border-radius: 7px; padding: 5px 8px; font-size: 9px; font-weight: 750; white-space: nowrap; }
.tone-warning { background: #fff4dc; color: #89530b; }
.tone-review { background: #eee9ff; color: #5428a5; }
.tone-info { background: #e4f4fa; color: #155e75; }
.tone-success { background: #e5f5ec; color: #23633d; }
.tone-danger { background: #fce9e7; color: #8f332e; }
.tone-neutral { background: #edf1f5; color: #475569; }
.activity-item, .notification-item { display: grid; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #edf1f5; }
.activity-item { grid-template-columns: 50px minmax(0, 1fr); }
.notification-item { grid-template-columns: 9px minmax(0, 1fr); }
.activity-date { display: grid; min-height: 50px; align-content: center; place-items: center; border-radius: 9px; background: var(--accent-soft); color: var(--accent); text-align: center; }
.activity-date strong { font-size: 17px; line-height: 1; }
.activity-date span { margin-top: 3px; font-size: 9px; font-weight: 750; text-transform: uppercase; }
.activity-title, .notification-title { margin: 0; color: var(--ink); font-size: 12px; font-weight: 700; line-height: 1.4; }
.activity-title a { color: inherit; text-decoration: none; }
.activity-meta, .notification-message, .notification-date { margin: 4px 0 0; color: var(--copy); font-size: 10px; line-height: 1.5; }
.notification-meta { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 10px; margin-top: 5px; }
.notification-date { margin: 0; }
.notification-details { margin-left: auto; font-size: 10px; }
.notification-details summary { color: var(--accent); cursor: pointer; font-weight: 750; list-style: none; }
.notification-details summary::-webkit-details-marker { display: none; }
.notification-details summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.notification-details[open] { flex-basis: 100%; margin-left: 0; }
.notification-dot { width: 8px; height: 8px; margin-top: 4px; border-radius: 50%; background: #94a3b8; }
.notification-dot.info { background: #2563eb; }
.notification-dot.success { background: #15803d; }
.notification-dot.warning { background: #b7791f; }
.notification-dot.error { background: #b42318; }
.empty-state { padding: 34px 20px; text-align: center; }
.empty-state strong { display: block; font-size: 14px; }
.empty-state p { max-width: 430px; margin: 7px auto 0; color: var(--copy); font-size: 11px; line-height: 1.55; }
.quick-link:focus-visible, .panel-link:focus-visible, .record-title:focus-visible, .activity-title a:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 24%, transparent);
  outline-offset: 3px;
}
@media (max-width: 1000px) {
  .dashboard-grid, .dashboard-grid--records, .dashboard-grid--updates { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 680px) {
  .module-greeting { grid-template-columns: 1fr; gap: 18px; padding: 23px 20px; }
  .module-greeting::after { right: -90px; }
  .greeting-meta { min-width: 0; }
  .quick-links { grid-template-columns: 1fr; gap: 8px; }
  .module-shortcuts { gap: 6px; }
  .module-shortcuts-label { flex-basis: 100%; }
  .dashboard-grid, .dashboard-grid--records, .dashboard-grid--updates { grid-template-columns: 1fr; }
  .count-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .count-item:nth-child(3) { border-top: 1px solid #e9edf3; border-left: 0; }
  .count-item:nth-child(4) { border-top: 1px solid #e9edf3; }
  .record-item { align-items: start; }
  .record-title { white-space: normal; }
}
@media (prefers-reduced-motion: reduce) {
  .quick-link { transition: none; }
}
</style>

<?php if ($role === 'student'): ?>
<div class="research-basics" aria-labelledby="research-basics-title" data-existing-completed="<?= $researchBasicsCompleted ? 'true' : 'false' ?>" data-lesson-progress-available="<?= ($researchBasicsLessonsAvailable && $researchBasicsStateAvailable) ? 'true' : 'false' ?>">
  <?php if ($researchBasicsError !== ''): ?>
    <p class="research-basics-alert" role="alert"><?= personalDashboardEscape($researchBasicsError) ?></p>
  <?php endif; ?>
  <header class="research-basics-hero" id="research-basics-start" tabindex="-1">
    <p class="research-basics-kicker">A student guide to getting started</p>
    <h1 class="research-basics-title" id="research-basics-title">Research Basics</h1>
    <p>Learn how to shape a research question, plan your chapters, and prepare to submit a proposal at EARIST.</p>
    <div class="research-basics-how"><strong>How this page works:</strong> The six lessons below teach the basics. The roadmap in lesson 6 shows the full nine-stage process, with each stage linked to a helpful lesson. Mark each lesson complete to unlock the next one.</div>
    <div class="research-basics-hero-actions">
      <a href="#manual">Start with the manual</a>
    </div>
  </header>
  <nav class="research-basics-tracker" aria-label="Your place in the guide">
    <div class="research-basics-tracker-heading">
      <span class="research-basics-tracker-count" id="research-basics-done-count"><?= ($researchBasicsStateAvailable && $researchBasicsLessonsAvailable) ? (int) $researchBasicsLessonCompletedCount . ' of 6 lessons done' : 'Progress unavailable' ?></span>
      <strong id="research-basics-current-section" aria-live="polite" aria-atomic="true">Lesson <?= $researchBasicsLastAvailableIndex + 1 ?> of 6: <?= personalDashboardEscape($researchBasicsLessons[$researchBasicsLessonIds[$researchBasicsLastAvailableIndex]]) ?></strong>
    </div>
    <div class="research-basics-tracker-bar"<?= (!$researchBasicsStateAvailable || !$researchBasicsLessonsAvailable) ? ' hidden' : '' ?> role="progressbar" aria-label="Lessons completed" aria-valuemin="0" aria-valuemax="6" aria-valuenow="<?= (int) $researchBasicsLessonCompletedCount ?>" aria-valuetext="<?= (int) $researchBasicsLessonCompletedCount ?> of 6 lessons done">
      <div class="research-basics-tracker-fill" id="research-basics-tracker-fill" style="width: <?= number_format(($researchBasicsLessonCompletedCount / count($researchBasicsLessons)) * 100, 2, '.', '') ?>%"></div>
    </div>
    <div class="research-basics-tabs">
      <a class="research-basics-tab" href="#manual" data-title="The manual"<?= $researchBasicsLastAvailableIndex < 0 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 0 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['manual'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>1. The manual</a>
      <a class="research-basics-tab" href="#foundations" data-title="Foundations"<?= $researchBasicsLastAvailableIndex < 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 1 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['foundations'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>2. Foundations</a>
      <a class="research-basics-tab" href="#chapters-1-5" data-title="Chapters 1–5"<?= $researchBasicsLastAvailableIndex < 2 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 2 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['chapters-1-5'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>3. Chapters 1–5</a>
      <a class="research-basics-tab" href="#methodology" data-title="Methodology"<?= $researchBasicsLastAvailableIndex < 3 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 3 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['methodology'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>4. Methodology</a>
      <a class="research-basics-tab" href="#citation-apa" data-title="Citation and APA"<?= $researchBasicsLastAvailableIndex < 4 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 4 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['citation-apa'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>5. Citation and APA</a>
      <a class="research-basics-tab" href="#roadmap" data-title="Roadmap"<?= $researchBasicsLastAvailableIndex < 5 ? ' aria-disabled="true" tabindex="-1"' : '' ?><?= $researchBasicsLastAvailableIndex === 5 ? ' aria-current="true"' : '' ?>><?php if (!empty($researchBasicsLessonProgress['roadmap'])): ?><span class="research-basics-tab-check" aria-hidden="true">&#10003;</span> <?php endif; ?>6. Roadmap</a>
    </div>
  </nav>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 0 ? ' is-locked' : '' ?>" id="manual" data-lesson-title="The manual" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['manual']) ? 'true' : 'false' ?>" aria-labelledby="manual-title"<?= $researchBasicsLastAvailableIndex + 1 < 0 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('manual', 0, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 0 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 01 of 06</p>
    <h2 id="manual-title">About the Research Manual</h2>
    <p>The EARIST Research Manual 2015 explains the institution's research process, review requirements, and submission standards. Use it as the official reference for your program. This guide adds plain-language explanations to help you understand the basics before you begin.</p>
    <?php personalDashboardRenderResearchBasicsLessonProgress('manual', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 1 ? ' is-locked' : '' ?>" id="foundations" data-lesson-title="Foundations" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['foundations']) ? 'true' : 'false' ?>" aria-labelledby="foundations-title"<?= $researchBasicsLastAvailableIndex + 1 < 1 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('foundations', 1, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 1 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 02 of 06</p>
    <h2 id="foundations-title">Research Foundations</h2>
    <p>Start here. Learn what research is, turn a topic into a focused question, and shape that question into a title you can take to your adviser.</p>
    <div class="research-basics-subsection" id="research-101">
      <h3><span class="research-basics-part">Part 1</span>Research 101</h3>
      <p>Research is a planned way to answer a focused question using evidence. Start with a problem that matters, find out what is already known, choose a suitable method, and report what the evidence supports.</p>
      <ol class="basics-learning-steps">
        <li><strong>Ask a clear question</strong><span>Define what you want to understand and who or what your study concerns.</span></li>
        <li><strong>Gather relevant evidence</strong><span>Use credible sources or data that directly help answer the question.</span></li>
        <li><strong>Explain your findings</strong><span>Describe what the evidence shows, its limits, and why it matters.</span></li>
      </ol>
    </div>
    <div class="research-basics-subsection" id="question">
      <h3><span class="research-basics-part">Part 2</span>Shape a Research Question</h3>
      <p>A good question is specific, answerable with evidence, and small enough for your time and resources. Your title and chapters all grow from it.</p>
      <div class="basics-example-grid">
        <article class="basics-example is-bad">
          <h3><span aria-hidden="true">✕</span> Too broad</h3>
          <p>“How does social media affect students?”</p>
          <p>No group, no setting, and no way to measure “affect.”</p>
        </article>
        <article class="basics-example is-good">
          <h3><span aria-hidden="true">✓</span> Focused</h3>
          <p>“Is there a relationship between weekly study time and quiz performance among first-year BSIT students?”</p>
          <p>Names the group, the variables, and what you will measure.</p>
        </article>
      </div>
      <p class="research-basics-tip"><strong>Try it yourself:</strong> Write your topic as a question. Then add who, where, and what you will measure or explore. If you cannot, the question is still too broad.</p>
    </div>
    <div class="research-basics-subsection" id="title">
      <h3><span class="research-basics-part">Part 3</span>Choose a Research Title</h3>
      <p>A useful title tells readers what the study examines and its scope. Choose a topic specific enough to study with the time, participants, and resources available to you.</p>
      <div class="basics-example-grid">
        <article class="basics-example is-bad">
          <h3><span aria-hidden="true">✕</span> Too broad</h3>
          <p>“Social Media and Students” does not identify a question, group, or setting.</p>
        </article>
        <article class="basics-example is-good">
          <h3><span aria-hidden="true">✓</span> Focused examples</h3>
          <p><strong>Quantitative:</strong> “Association between weekly study time and quiz performance among first-year BSIT students.”</p>
          <p><strong>Qualitative:</strong> “Experiences of first-year BSIT students adapting to independent study.”</p>
        </article>
      </div>
      <p class="research-basics-tip"><strong>Ask your adviser:</strong> These are examples to adapt, not approved topics. Check that your topic is researchable, ethically appropriate, and connected to your field, and confirm the final wording with your adviser.</p>
    </div>
    <?php personalDashboardRenderResearchBasicsLessonProgress('foundations', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 2 ? ' is-locked' : '' ?>" id="chapters-1-5" data-lesson-title="Chapters 1–5" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['chapters-1-5']) ? 'true' : 'false' ?>" aria-labelledby="chapters-1-5-title"<?= $researchBasicsLastAvailableIndex + 1 < 2 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('chapters-1-5', 2, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 2 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 03 of 06</p>
    <h2 id="chapters-1-5-title">Chapters 1-5 at a Glance</h2>
    <p>Many research papers use these five chapters. Your department's manual and adviser decide the required headings and format. Open a chapter to see what to include and a common mistake.</p>
    <div class="basics-chapter-list">
      <details>
        <summary>Chapter 1: The Problem <span>Introduce the background, research problem, objectives or questions, significance, and scope.</span></summary>
        <div class="basics-chapter-detail"><div class="basics-chapter-tip"><strong>Tip:</strong> Start with the broad context, then narrow to your specific problem and questions.</div><div class="basics-chapter-mistake"><strong>Common mistake:</strong> Objectives that do not match the research question.</div></div>
      </details>
      <details>
        <summary>Chapter 2: Related Literature <span>Compare credible sources, explain what is known, and show how your study connects to a gap or need.</span></summary>
        <div class="basics-chapter-detail"><div class="basics-chapter-tip"><strong>Tip:</strong> Group sources by theme, not one summary per source.</div><div class="basics-chapter-mistake"><strong>Common mistake:</strong> A list of summaries with no link to your study.</div></div>
      </details>
      <details>
        <summary>Chapter 3: Methodology <span>Explain the design, participants or data sources, instruments, procedures, ethical safeguards, and analysis plan.</span></summary>
        <div class="basics-chapter-detail"><div class="basics-chapter-tip"><strong>Tip:</strong> Write it so another researcher could repeat your steps.</div><div class="basics-chapter-mistake"><strong>Common mistake:</strong> Naming a method without justifying why it fits the question.</div></div>
      </details>
      <details>
        <summary>Chapter 4: Results and Discussion <span>Present findings clearly, then interpret them in relation to your questions and the literature.</span></summary>
        <div class="basics-chapter-detail"><div class="basics-chapter-tip"><strong>Tip:</strong> Answer each research question in order.</div><div class="basics-chapter-mistake"><strong>Common mistake:</strong> Interpreting results that are not supported by the data.</div></div>
      </details>
      <details>
        <summary>Chapter 5: Summary and Suggestions <span>Summarize main findings, state evidence-based conclusions, and offer suggestions or future research.</span></summary>
        <div class="basics-chapter-detail"><div class="basics-chapter-tip"><strong>Tip:</strong> Keep conclusions tied to what you actually found.</div><div class="basics-chapter-mistake"><strong>Common mistake:</strong> Introducing new findings or claims.</div></div>
      </details>
    </div>
    <?php personalDashboardRenderResearchBasicsLessonProgress('chapters-1-5', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 3 ? ' is-locked' : '' ?>" id="methodology" data-lesson-title="Methodology" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['methodology']) ? 'true' : 'false' ?>" aria-labelledby="methodology-title"<?= $researchBasicsLastAvailableIndex + 1 < 3 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('methodology', 3, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 3 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 04 of 06</p>
    <h2 id="methodology-title">Research Methodology Basics</h2>
    <p>Choose a method that can answer your research question. The labels below describe common approaches; they do not replace your department's requirements.</p>
    <div class="basics-method-grid">
      <article class="basics-method">
        <h3>Quantitative</h3>
        <p>Uses numerical data to measure patterns, compare groups, or test relationships. The plan should define variables and how they will be measured.</p>
      </article>
      <article class="basics-method">
        <h3>Qualitative</h3>
        <p>Examines experiences, meanings, or processes through detailed non-numerical data such as interviews or observations.</p>
      </article>
      <article class="basics-method">
        <h3>Mixed methods</h3>
        <p>Combines both kinds of evidence when each is needed to answer different parts of the same question.</p>
      </article>
    </div>
    <?php personalDashboardRenderResearchBasicsLessonProgress('methodology', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 4 ? ' is-locked' : '' ?>" id="citation-apa" data-lesson-title="Citation and APA" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['citation-apa']) ? 'true' : 'false' ?>" aria-labelledby="citation-apa-title"<?= $researchBasicsLastAvailableIndex + 1 < 4 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('citation-apa', 4, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 4 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 05 of 06</p>
    <h2 id="citation-apa-title">Citation and APA Guide</h2>
    <p>Cite the source whenever you use another author's ideas, words, data, or images. Paraphrasing means expressing an idea in your own words and structure, not just replacing a few terms. Keep source details as you read so you can build an accurate reference list.</p>
    <div class="basics-citation-example">
      <strong>APA 7 templates</strong>
      <p><em>In-text, parenthetical:</em> (Author, Year)</p>
      <p><em>In-text, narrative:</em> Author (Year)</p>
      <p><em>Journal article:</em> Author, A. A. (Year). Title of article. <i>Journal Title, volume</i>(issue), page range. DOI or URL</p>
      <p><em>Book:</em> Author, A. A. (Year). <i>Title of book</i>. Publisher.</p>
    </div>
    <p class="research-basics-tip"><strong>Common mistake:</strong> These are templates, not complete references. Verify punctuation, capitalization, and DOI formatting against the current APA guide and your institution's rules. Never present another person's work as your own.</p>
    <?php personalDashboardRenderResearchBasicsLessonProgress('citation-apa', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <section class="research-basics-section<?= $researchBasicsLockedLessonIndex === 5 ? ' is-locked' : '' ?>" id="roadmap" data-lesson-title="Roadmap" data-lesson-complete="<?= !empty($researchBasicsLessonProgress['roadmap']) ? 'true' : 'false' ?>" aria-labelledby="roadmap-title"<?= $researchBasicsLastAvailableIndex + 1 < 5 ? ' hidden' : '' ?>>
    <?php personalDashboardRenderResearchBasicsLockNotice('roadmap', 5, $researchBasicsLessonIds, $researchBasicsLessons, $researchBasicsLockedLessonIndex); ?>
    <div class="research-basics-lesson-content"<?= $researchBasicsLockedLessonIndex === 5 ? ' inert' : '' ?>>
    <p class="research-basics-lesson-label">Lesson 06 of 06</p>
    <h2 id="roadmap-title">Interactive Research Roadmap</h2>
    <p>The formal review and submission steps remain governed by the current RMS workflow and your program's instructions.</p>
    <div class="research-roadmap-card" id="research-roadmap-widget">
      <div class="research-roadmap-progress" hidden>
        <p class="research-roadmap-progress-label">Your learning roadmap</p>
        <span class="research-roadmap-progress-count" id="roadmap-progress-count">Stage 1 of 9</span>
      </div>
      <?php if ($researchBasicsStagesAvailable && $researchBasicsStateAvailable): ?>
      <div class="research-roadmap-progress-track" hidden role="progressbar" aria-label="Roadmap stages completed" aria-valuemin="0" aria-valuemax="9" aria-valuenow="<?= (int) $researchBasicsStageCompletedCount ?>" aria-valuetext="<?= (int) $researchBasicsStageCompletedCount ?> of 9 stages complete" id="roadmap-progress">
        <div class="research-roadmap-progress-value" id="roadmap-progress-value" style="width: <?= number_format(($researchBasicsStageCompletedCount / count($researchBasicsStages)) * 100, 2, '.', '') ?>%"></div>
      </div>
      <p class="research-roadmap-completed-count" id="roadmap-completed-count"><?= (int) $researchBasicsStageCompletedCount ?> of 9 stages done</p>
      <?php else: ?>
      <p class="research-roadmap-stage-status" role="status">Saved roadmap progress is unavailable right now. Please contact support if this continues.</p>
      <?php endif; ?>
      <div class="research-roadmap-layout">
        <ol class="research-roadmap-stages" aria-label="Select a learning stage" hidden>
          <?php $roadmapStageNumber = 0; foreach ($researchBasicsStages as $stageId => $stageName): $roadmapStageNumber++; $stageIsComplete = !empty($researchBasicsStageProgress[$stageId]); ?>
          <li><button class="research-roadmap-stage<?= $stageIsComplete ? ' is-complete' : '' ?>" type="button"<?= $roadmapStageNumber === 1 ? ' aria-current="step"' : '' ?> aria-controls="roadmap-stage-<?= $roadmapStageNumber ?>" aria-label="Stage <?= $roadmapStageNumber ?>: <?= personalDashboardEscape($stageName) ?><?= $stageIsComplete ? ', completed' : ', not completed' ?>"><span class="research-roadmap-stage-number" aria-hidden="true"><?= $stageIsComplete ? '✓' : $roadmapStageNumber ?></span><span><?= personalDashboardEscape($stageName) ?></span></button></li>
          <?php endforeach; ?>
        </ol>
        <div class="research-roadmap-detail" id="roadmap-stage-1" role="region" aria-labelledby="roadmap-stage-1-title">
          <p class="research-roadmap-step-label">Stage 1</p>
          <h3 id="roadmap-stage-1-title">Understand research</h3>
          <h4>Learning goal</h4>
          <p>Learn how systematic inquiry builds knowledge and addresses questions while protecting the people involved.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A brief explanation of what research can answer and the ethical responsibilities researchers have.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Explain research in your own words</li><li>Name two ethical responsibilities</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('understand-research', 'Understand research', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#research-101">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-2" role="region" aria-labelledby="roadmap-stage-2-title">
          <p class="research-roadmap-step-label">Stage 2</p>
          <h3 id="roadmap-stage-2-title">Find a research problem</h3>
          <h4>Learning goal</h4>
          <p>Spot a real problem or need in your field worth studying.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A short list of 2–3 possible problems.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Talk to classmates or your adviser</li><li>Check that the problem is researchable</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('find-research-problem', 'Find a research problem', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#foundations">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-3" role="region" aria-labelledby="roadmap-stage-3-title">
          <p class="research-roadmap-step-label">Stage 3</p>
          <h3 id="roadmap-stage-3-title">Focus the question</h3>
          <h4>Learning goal</h4>
          <p>Narrow your problem into one clear, answerable question.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>One draft research question.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Add who, where, and what you measure</li><li>Compare with the focused example</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('focus-question', 'Focus the question', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#question">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-4" role="region" aria-labelledby="roadmap-stage-4-title">
          <p class="research-roadmap-step-label">Stage 4</p>
          <h3 id="roadmap-stage-4-title">Draft a research title</h3>
          <h4>Learning goal</h4>
          <p>Turn your question into a title that shows scope.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A draft title.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Include topic, group, and setting</li><li>Avoid titles that are too broad</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('draft-research-title', 'Draft a research title', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#title">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-5" role="region" aria-labelledby="roadmap-stage-5-title">
          <p class="research-roadmap-step-label">Stage 5</p>
          <h3 id="roadmap-stage-5-title">Review related literature</h3>
          <h4>Learning goal</h4>
          <p>Find credible sources and see what is already known.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A list of sources with notes.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Collect at least 10 credible sources</li><li>Record citation details as you read</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('review-related-literature', 'Review related literature', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#citation-apa">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-6" role="region" aria-labelledby="roadmap-stage-6-title">
          <p class="research-roadmap-step-label">Stage 6</p>
          <h3 id="roadmap-stage-6-title">Choose a methodology</h3>
          <h4>Learning goal</h4>
          <p>Pick a method that can answer your research question.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A one-paragraph method choice with a reason.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Match the method to your question</li><li>Confirm with your adviser</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('choose-methodology', 'Choose a methodology', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#methodology">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-7" role="region" aria-labelledby="roadmap-stage-7-title">
          <p class="research-roadmap-step-label">Stage 7</p>
          <h3 id="roadmap-stage-7-title">Submit the title for review</h3>
          <h4>Learning goal</h4>
          <p>Send your title through the current RMS workflow.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A submitted title and confirmation.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Check your program's title requirements</li><li>Keep proof of submission</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('submit-title-review', 'Submit the title for review', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#title">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-8" role="region" aria-labelledby="roadmap-stage-8-title">
          <p class="research-roadmap-step-label">Stage 8</p>
          <h3 id="roadmap-stage-8-title">Write Chapters 1-3</h3>
          <h4>Learning goal</h4>
          <p>Draft the proposal chapters using your approved title.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>A full draft of Chapters 1-3.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Follow the department format</li><li>Share drafts with your adviser</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('write-chapters-1-3', 'Write Chapters 1-3', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#chapters-1-5">Review the related lesson</a>
          </div>
        </div>
        <div class="research-roadmap-detail" id="roadmap-stage-9" role="region" aria-labelledby="roadmap-stage-9-title">
          <p class="research-roadmap-step-label">Stage 9</p>
          <h3 id="roadmap-stage-9-title">Complete Chapters 4-5</h3>
          <h4>Learning goal</h4>
          <p>After data gathering, report findings and conclusions.</p>
          <div class="research-roadmap-output">
            <h4>Expected output</h4>
            <p>Finished Chapters 4-5.</p>
          </div>
          <h4>Before you move on</h4>
          <ul><li>Answer each research question</li><li>Keep conclusions evidence-based</li></ul>
          <div class="research-roadmap-stage-progress">
            <?php personalDashboardRenderResearchBasicsStageProgress('complete-chapters-4-5', 'Complete Chapters 4-5', $researchBasicsStageProgress, $researchBasicsStagesAvailable, $researchBasicsStateAvailable); ?>
            <a href="#chapters-1-5">Review the related lesson</a>
          </div>
        </div>
      </div>
      <div class="research-roadmap-controls" role="group" aria-label="Roadmap stage controls" hidden>
        <button type="button" id="roadmap-previous" disabled>Previous</button>
        <button type="button" id="roadmap-next">Next</button>
      </div>
      <p class="research-roadmap-visually-hidden" id="roadmap-selection-status" aria-live="polite">Stage 1: Understand research</p>
    </div>
    <?php personalDashboardRenderResearchBasicsLessonProgress('roadmap', $researchBasicsLessonProgress, $researchBasicsLessonsAvailable, $researchBasicsStateAvailable, $researchBasicsLastAvailableIndex); ?>
    </div>
  </section>

  <footer<?= (!$researchBasicsCompleted && $researchBasicsLessonCompletedCount < count($researchBasicsLessons)) ? ' hidden' : '' ?> class="research-basics-cta" id="research-basics-cta" aria-labelledby="research-basics-cta-title">
    <div>
      <h2 id="research-basics-cta-title"><?= $studentHasApprovedTitle ? 'Continue your research' : 'Start your research proposal?' ?></h2>
      <p><?= $studentHasApprovedTitle ? 'Your research title is approved. Open My Research to follow your project and continue with the next workflow step.' : 'When you are ready, start a title draft and review the requirements with your adviser.' ?></p>
    </div>
    <div class="research-basics-actions">
      <a href="#research-basics-start">Review the lessons</a>
      <?php if ($studentHasApprovedTitle): ?>
        <a href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/my-research.php') ?>">View My Research</a>
      <?php elseif ($researchBasicsCompleted): ?>
        <a href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/submit-research.php') ?>">Start my title draft</a>
      <?php else: ?>
        <form method="post" action="<?= personalDashboardEscape(SITE_URL . 'pages/student/student-dashboard.php') ?>">
          <?= csrfField() ?>
          <input type="hidden" name="complete_research_basics" value="1">
          <button type="submit"<?= (!$researchBasicsStateAvailable || !$researchBasicsLessonsAvailable || $researchBasicsLessonCompletedCount !== count($researchBasicsLessons)) ? ' disabled' : '' ?>><?= $researchBasicsLessonCompletedCount === count($researchBasicsLessons) ? 'Complete guide and begin' : 'Complete all 6 lessons to continue' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </footer>
  <p class="research-basics-source-note">Content is based on the EARIST Research Manual 2015 and your department's requirements.</p>
</div>
<?php else: ?>
<div class="module-hub role-<?= $role === 'faculty' ? 'faculty' : 'student' ?>">
  <header class="module-greeting"><div><p class="module-kicker">Your research workspace</p><h1><?= personalDashboardEscape($greeting) ?></h1><p>Manage title and chapter submissions, review feedback, publications, and campus research activities.</p></div><div class="greeting-meta"><strong><?= personalDashboardEscape($roleLabel) ?></strong><span><?= personalDashboardEscape(date('l, F j, Y')) ?></span></div></header>

  <nav class="quick-links" aria-label="Research workflow shortcuts">
    <a class="quick-link primary" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/my-research.php') ?>">My Research</a>
    <?php if (!$studentHasApprovedTitle): ?><a class="quick-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/submit-research.php') ?>">Submit Research Title</a><?php endif; ?>
    <a class="quick-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/researcher/submit-chapter.php') ?>">Submit a Chapter</a>
  </nav>
  <div class="dashboard-grid">
    <section class="hub-panel" aria-labelledby="my-publications-heading"><div class="panel-head"><div><h2 id="my-publications-heading">My Publications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/publications.php') ?>">Open module</a></div>
      <div class="count-strip"><?php foreach ($publicationCounts as $status => $count): $badge = personalDashboardStatusBadge($status); ?><div class="count-item"><strong><?= (int) $count ?></strong><span><?= personalDashboardEscape($badge['label']) ?></span></div><?php endforeach; ?></div>
      <?php if (!$tables['publications'] || !$recentPublications): ?><div class="empty-state"><strong><?= $tables['publications'] ? 'No publications yet' : 'Publications unavailable' ?></strong><p><?= $tables['publications'] ? 'Recent publication submissions will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="record-list"><?php foreach ($recentPublications as $publication): $badge = personalDashboardStatusBadge((string) $publication['status']); ?><li class="record-item"><div><a class="record-title" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $publication['publication_id']) ?>"><?= personalDashboardEscape($publication['research_title']) ?></a><span class="record-meta"><?= personalDashboardEscape(ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?><?= $publication['publication_date'] ? ' &middot; ' . personalDashboardEscape(date('M j, Y', strtotime((string) $publication['publication_date']))) : '' ?></span></div><span class="badge tone-<?= personalDashboardEscape($badge['tone']) ?>"><?= personalDashboardEscape($badge['label']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="my-copyrights-heading"><div class="panel-head"><div><h2 id="my-copyrights-heading">My Copyright Applications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/copyrights.php') ?>">Open module</a></div>
      <div class="count-strip"><?php foreach ($copyrightCounts as $status => $count): $badge = personalDashboardStatusBadge($status); ?><div class="count-item"><strong><?= (int) $count ?></strong><span><?= personalDashboardEscape($badge['label']) ?></span></div><?php endforeach; ?></div>
      <?php if (!$tables['copyrights'] || !$recentCopyrights): ?><div class="empty-state"><strong><?= $tables['copyrights'] ? 'No copyright applications yet' : 'Copyrights unavailable' ?></strong><p><?= $tables['copyrights'] ? 'Recent copyright applications will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="record-list"><?php foreach ($recentCopyrights as $application): $badge = personalDashboardStatusBadge((string) $application['status']); ?><li class="record-item"><div><a class="record-title" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $application['copyright_id']) ?>"><?= personalDashboardEscape($application['output_title']) ?></a><span class="record-meta"><?= personalDashboardEscape(ucwords(str_replace('_', ' ', (string) $application['output_type']))) ?><?= $application['copyright_ref_no'] ? ' &middot; ' . personalDashboardEscape($application['copyright_ref_no']) : '' ?></span></div><span class="badge tone-<?= personalDashboardEscape($badge['tone']) ?>"><?= personalDashboardEscape($badge['label']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>

  <div class="dashboard-grid">
    <section class="hub-panel" aria-labelledby="upcoming-activities-heading"><div class="panel-head"><div><h2 id="upcoming-activities-heading">Upcoming Activities</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/activities.php') ?>">View all</a></div>
      <?php if (!$tables['activities'] || !$upcomingActivities): ?><div class="empty-state"><strong><?= $tables['activities'] ? 'No upcoming activities' : 'Activities unavailable' ?></strong><p><?= $tables['activities'] ? 'Scheduled seminars and research events will appear here.' : 'Apply migration 012 to enable this module.' ?></p></div>
      <?php else: ?><ul class="activity-list"><?php foreach ($upcomingActivities as $activity): $activityDate = new DateTimeImmutable((string) $activity['activity_date']); ?><li class="activity-item"><div class="activity-date" aria-hidden="true"><strong><?= personalDashboardEscape($activityDate->format('d')) ?></strong><span><?= personalDashboardEscape($activityDate->format('M')) ?></span></div><div><p class="activity-title"><a href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id']) ?>"><?= personalDashboardEscape($activity['title']) ?></a></p><p class="activity-meta"><?= personalDashboardEscape(ucfirst((string) $activity['activity_type'])) ?><?= $activity['activity_time'] ? ' &middot; ' . personalDashboardEscape(date('g:i a', strtotime((string) $activity['activity_time']))) : '' ?><?= $activity['venue'] ? ' &middot; ' . personalDashboardEscape($activity['venue']) : '' ?></p></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="hub-panel" aria-labelledby="recent-notifications-heading"><div class="panel-head"><div><h2 id="recent-notifications-heading">Recent Notifications</h2></div><a class="panel-link" href="<?= personalDashboardEscape(SITE_URL . 'pages/shared/notifications.php') ?>">View all</a></div>
      <?php if (!$tables['notifications'] || !$recentNotifications): ?><div class="empty-state"><strong>No notifications yet</strong><p>Submission and review updates will appear here.</p></div>
      <?php else: ?><ul class="notification-list"><?php foreach ($recentNotifications as $notification): $notificationType = in_array($notification['type'], ['info', 'success', 'warning', 'error'], true) ? $notification['type'] : 'info'; ?><li class="notification-item"><span class="notification-dot <?= personalDashboardEscape($notificationType) ?>" aria-hidden="true"></span><div><p class="notification-title"><?= personalDashboardEscape($notification['title']) ?></p><div class="notification-meta"><p class="notification-date"><?= personalDashboardEscape(date('M j, Y g:i a', strtotime((string) $notification['created_at']))) ?><?= (int) $notification['is_read'] === 0 ? ' &middot; Unread' : '' ?></p><details class="notification-details"><summary>Details</summary><p class="notification-message"><?= personalDashboardEscape($notification['message']) ?></p></details></div></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
</div>
<?php endif; ?>

<?php
if ($role === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
