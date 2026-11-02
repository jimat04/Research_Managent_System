<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity-schedule.php';
require_once __DIR__ . '/../../includes/activity-budget.php';
require_once __DIR__ . '/../../includes/activity-document.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function activityDetailEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activityDetailStatusBadge(string $status): array
{
    return match ($status) {
        'upcoming', 'scheduled' => ['label' => 'Upcoming', 'tone' => 'info'],
        'ongoing' => ['label' => 'Ongoing', 'tone' => 'warning'],
        'conducted', 'completed' => ['label' => 'Conducted', 'tone' => 'success'],
        'past' => ['label' => 'Past', 'tone' => 'neutral'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

function activityDetailMoney(mixed $amount): string
{
    return $amount === null || $amount === '' ? 'Not recorded' : '₱' . number_format((float) $amount, 2);
}

function activityDetailExpenseValue(?string $actual, ?string $estimate, string $label): string
{
    if ($actual !== null && $actual !== '') {
        return $label . ' actual: ' . activityDetailMoney($actual);
    }
    if ($estimate !== null && $estimate !== '') {
        return $label . ' estimate: ' . activityDetailMoney($estimate);
    }
    return $label . ': Not recorded';
}

function activityDetailBudgetRevisionSummary(?array $snapshot): string
{
    if (!$snapshot || empty($snapshot['budget'])) {
        return 'No previous budget';
    }
    $budget = $snapshot['budget'];
    $allocation = activityDetailMoney($budget['organizational_allocation'] ?? null);
    return ucfirst(str_replace('_', ' ', (string) $budget['funding_source']))
        . ' · ' . ucfirst((string) $budget['budget_status'])
        . ' · allocation ' . $allocation
        . ' · ' . count($snapshot['expenses'] ?? []) . ' expense item(s)';
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$capacityPageInput = trim((string) ($_GET['capacity_page'] ?? ''));
$capacityPage = in_array($capacityPageInput, ['seminars', 'trainings', 'workshops'], true)
    ? $capacityPageInput
    : '';
$canManageActivity = static function (string $activityType, string $selectedPage) use ($role, $userId): bool {
    if ($role === 'admin') {
        return true;
    }
    $pages = match ($activityType) {
        'seminar' => ['seminars'],
        'training' => ['trainings'],
        'workshop' => ['workshops'],
        'colloquium', 'conference' => ['seminars', 'trainings', 'workshops'],
        default => ['seminars', 'trainings', 'workshops'],
    };
    foreach ($pages as $pageKey) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $pageKey)) {
            return true;
        }
    }
    return false;
};
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$activitiesAvailable = rmsActivityScheduleTableExists($conn, 'research_activities');
$activityDocumentsAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'document_upload_id');
$participantsAvailable = rmsActivityScheduleTableExists($conn, 'activity_participants');
$scheduleTablesAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_dates');
$invitationResponseSchemaAvailable = $scheduleTablesAvailable
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'invitation_response')
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'invitation_responded_at');
$budgetSchemaAvailable = rmsActivityBudgetSchemaAvailable($conn);

$activity = null;
if ($activitiesAvailable && $activityId) {
    $documentColumn = $activityDocumentsAvailable ? ', document_upload_id' : '';
    $activitySql = $scheduleTablesAvailable
        ? 'SELECT activity_id, activity_type, title, activity_date, activity_time, is_multi_day, schedule_start_date, schedule_end_date, venue, speaker, organizer, description, status, created_by' . $documentColumn . ' FROM research_activities WHERE activity_id = ? LIMIT 1'
        : 'SELECT activity_id, activity_type, title, activity_date, activity_time, venue, speaker, organizer, description, status, created_by' . $documentColumn . ' FROM research_activities WHERE activity_id = ? LIMIT 1';
    try {
        $stmt = rmsActivitySchedulePrepare($conn, $activitySql);
        $stmt->bind_param('i', $activityId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to load the activity details.');
        }
        $activity = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    } catch (Throwable $exception) {
        error_log('Activity detail query failed: ' . $exception->getMessage());
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Activity details could not be loaded.'];
    }
}
$scheduleDates = [];
if ($activity && $scheduleTablesAvailable) {
    try {
        $scheduleDates = rmsActivityScheduleLoad($conn, (int) $activityId, $invitationResponseSchemaAvailable);
    } catch (Throwable $exception) {
        error_log('Activity schedule detail load failed: ' . $exception->getMessage());
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The activity schedule could not be loaded.'];
    }
}
$currentUserIsActivityInvitee = false;
foreach ($scheduleDates as $scheduleRow) {
    foreach ($scheduleRow['participants'] ?? [] as $scheduleParticipant) {
        if ((int) ($scheduleParticipant['user_id'] ?? 0) === $userId
            && (int) ($scheduleParticipant['is_invited'] ?? 0) === 1) {
            $currentUserIsActivityInvitee = true;
            break 2;
        }
    }
}

$canManage = $activity ? $canManageActivity((string) $activity['activity_type'], $capacityPage) : false;
$activityDocument = $activity && $activityDocumentsAvailable && (int) ($activity['document_upload_id'] ?? 0) > 0
    ? rmsActivityDocumentLoad($conn, (int) $activityId)
    : null;
$userIsInvited = $activity && $role !== 'admin' && !$canManage
    && $scheduleTablesAvailable
    && rmsActivityDocumentUserIsInvited($conn, (int) $activityId, $userId);
$isConductedActivity = $activity && in_array((string) $activity['status'], ['conducted', 'completed'], true);
$canRecordActivityAttendance = $activity && in_array((string) $activity['status'], ['ongoing', 'conducted', 'completed'], true);
$canStartActivity = $role === 'admin'
    && $activity
    && in_array((string) $activity['status'], ['upcoming', 'scheduled'], true)
    && rmsActivityScheduleCanStartActivity($conn, (int) $activityId, $scheduleTablesAvailable);
$canMarkConductedActivity = $role === 'admin'
    && $activity
    && in_array((string) $activity['status'], ['upcoming', 'scheduled'], true)
    && rmsActivityScheduleCanMarkConducted($conn, (int) $activityId, $scheduleTablesAvailable);
if ($role === 'research_staff' && $activity) {
    $viewPages = match ((string) $activity['activity_type']) {
        'seminar' => ['seminars'],
        'training' => ['trainings'],
        'workshop' => ['workshops'],
        'colloquium', 'conference' => ['seminars', 'trainings', 'workshops'],
        default => ['seminars', 'trainings', 'workshops'],
    };
    $canView = false;
    foreach ($viewPages as $pageKey) {
        if (hasModulePermission($userId, 'research_capacity_building', 'view', $pageKey)) {
            $canView = true;
            break;
        }
    }
    if (!$canView) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Your form expired. Please try again.'];
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if (!$activitiesAvailable || !$activity || !$activityId) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The requested activity is not available.'];
        header('Location: ' . SITE_URL . 'pages/shared/activities.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_invitation_response') {
        $participantId = filter_var($_POST['schedule_participant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $response = is_scalar($_POST['invitation_response'] ?? null) ? (string) $_POST['invitation_response'] : '';
        try {
            if (!$scheduleTablesAvailable || !$invitationResponseSchemaAvailable || !$participantId) {
                throw new RuntimeException('Invitation responses are not available for this activity.');
            }
            rmsActivityScheduleSaveInvitationResponse(
                $conn,
                (int) $activityId,
                (int) $participantId,
                $userId,
                $response,
                $invitationResponseSchemaAvailable
            );
            logActivity("Updated activity invitation response for activity #{$activityId}", 'research_activities');
            $_SESSION['activities_flash'] = [
                'type' => 'success',
                'message' => $response === 'accepted'
                    ? 'Your response is saved: you will attend. You can change it before the session starts.'
                    : 'Your response is saved: you cannot attend. You can change it before the session starts.',
            ];
        } catch (Throwable $exception) {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if ($action === 'remove_activity_document') {
        if ($role !== 'admin' || !$activityDocumentsAvailable || !$activityDocument) {
            http_response_code(403);
            exit('Access denied.');
        }
        try {
            $oldUploadId = (int) $activityDocument['document_upload_id'];
            $stmt = $conn->prepare('UPDATE research_activities SET document_upload_id = NULL WHERE activity_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to remove the activity document.');
            }
            $stmt->bind_param('i', $activityId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to remove the activity document.');
            }
            $stmt->close();
            if (!rmsActivityDocumentDeleteUpload($conn, $oldUploadId)) {
                throw new RuntimeException('The document link was removed, but the stored upload could not be cleaned up.');
            }
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity document removed.'];
        } catch (Throwable $exception) {
            error_log('Activity document removal failed: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if ($action === 'save_contribution_response') {
        $participantId = filter_var($_POST['schedule_participant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $response = is_scalar($_POST['contribution_response'] ?? null) ? (string) $_POST['contribution_response'] : '';
        try {
            if (!$budgetSchemaAvailable || !$participantId || !in_array((string) $activity['status'], ['upcoming', 'scheduled', 'ongoing'], true)) {
                throw new RuntimeException('Contribution responses are not available for this activity.');
            }
            rmsActivityBudgetSaveContributionResponse($conn, (int) $activityId, $participantId, $userId, $response);
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Your contribution response was saved. It does not record or confirm a payment.'];
        } catch (Throwable $exception) {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if ($action === 'start_activity') {
        if ($role !== 'admin') {
            header('Location: ' . SITE_URL . 'public/403.php');
            exit;
        }
        try {
            if (!rmsActivityScheduleStartActivity($conn, (int) $activityId, $scheduleTablesAvailable)) {
                throw new RuntimeException('This activity can only be started during one of its scheduled session times.');
            }
            logActivity("Marked research activity #{$activityId} as ongoing", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity marked ongoing.'];
        } catch (Throwable $exception) {
            error_log('Activity start action failed: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if ($action === 'mark_conducted') {
        if ($role !== 'admin') {
            header('Location: ' . SITE_URL . 'public/403.php');
            exit;
        }
        try {
            if (!rmsActivityScheduleMarkConducted($conn, (int) $activityId, $scheduleTablesAvailable)) {
                throw new RuntimeException('This activity can only be marked conducted after all scheduled sessions have ended.');
            }
            logActivity("Marked research activity #{$activityId} as conducted", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity marked conducted.'];
        } catch (Throwable $exception) {
            error_log('Activity conducted action failed: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId);
        exit;
    }
    if (!$canManage) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }

    $redirectToDetail = true;
    $flash = ['type' => 'error', 'message' => 'The requested action is invalid.'];

    if ($action === 'retry_activity_budget_notice' || $action === 'continue_activity_budget_notice') {
        $noticeId = filter_var($_POST['notice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (!$budgetSchemaAvailable || !$noticeId) {
            $flash['message'] = 'Choose a valid budget notice.';
        } else {
            try {
                $retryFailedOnly = $action === 'retry_activity_budget_notice';
                $retryResult = rmsActivityBudgetDeliverNotice($conn, $noticeId, (int) $activityId, $retryFailedOnly);
                $flash = [
                    'type' => $retryResult['failed'] > 0 ? 'error' : 'success',
                    'message' => $retryResult['delivered'] . ' notice(s) delivered; ' . $retryResult['failed'] . ' still failed. Successful recipients were not sent duplicate notices.',
                ];
                logActivity("Retried budget notice #{$noticeId} for activity #{$activityId}", 'research_activities');
            } catch (Throwable $exception) {
                error_log('Activity budget notice retry failed: ' . $exception->getMessage());
                $flash['message'] = 'The budget notice retry could not be completed.';
            }
        }
    } elseif ($action === 'save_schedule_attendance') {
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $listedIds = is_array($_POST['schedule_participant_ids'] ?? null) ? array_values(array_unique($_POST['schedule_participant_ids'])) : [];
        if (!$scheduleTablesAvailable || !$scheduleId || !$listedIds) {
            $flash['message'] = 'Choose a valid scheduled date with participants.';
        } else {
            try {
                rmsActivityScheduleBegin($conn);
                $scheduleStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'SELECT d.status AS date_status, ra.status AS activity_status
                     FROM activity_schedule_dates d
                     INNER JOIN research_activities ra ON ra.activity_id = d.activity_id
                     WHERE d.schedule_id = ? AND d.activity_id = ? FOR UPDATE'
                );
                $scheduleStmt->bind_param('ii', $scheduleId, $activityId);
                if (!$scheduleStmt->execute()) {
                    throw new RuntimeException('Unable to verify the scheduled date.');
                }
                $scheduleRow = $scheduleStmt->get_result()->fetch_assoc();
                $scheduleStmt->close();
                if (!$scheduleRow
                    || $scheduleRow['date_status'] !== 'scheduled'
                    || !in_array((string) $scheduleRow['activity_status'], ['ongoing', 'conducted', 'completed'], true)) {
                    throw new RuntimeException('Attendance can only be recorded while an activity is ongoing or after it is conducted.');
                }
                $ownerStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'SELECT participant_id FROM activity_schedule_participants WHERE participant_id = ? AND schedule_id = ? LIMIT 1'
                );
                $attendanceStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'UPDATE activity_schedule_participants SET attended = ? WHERE participant_id = ? AND schedule_id = ?'
                );
                $attendanceInput = is_array($_POST['schedule_attended'] ?? null) ? $_POST['schedule_attended'] : [];
                foreach ($listedIds as $rawParticipantId) {
                    $participantId = filter_var($rawParticipantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$participantId) {
                        throw new RuntimeException('An attendance participant ID is invalid.');
                    }
                    $ownerStmt->bind_param('ii', $participantId, $scheduleId);
                    if (!$ownerStmt->execute() || !$ownerStmt->get_result()->fetch_assoc()) {
                        throw new RuntimeException('A participant does not belong to this date.');
                    }
                    $attended = isset($attendanceInput[$participantId]) && (string) $attendanceInput[$participantId] === '1' ? 1 : 0;
                    $attendanceStmt->bind_param('iii', $attended, $participantId, $scheduleId);
                    if (!$attendanceStmt->execute()) {
                        throw new RuntimeException('Unable to save date attendance.');
                    }
                }
                $ownerStmt->close();
                $attendanceStmt->close();
                rmsActivityScheduleCommit($conn);
                logActivity("Updated date attendance for activity #{$activityId}", 'research_activities');
                $flash = ['type' => 'success', 'message' => 'Attendance saved for this scheduled date.'];
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Activity date attendance save failed: ' . $exception->getMessage());
                $flash['message'] = 'Attendance could not be saved. Confirm that every participant belongs to this date.';
            }
        }
    } elseif ($action === 'add_schedule_participant') {
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $participantRole = trim((string) ($_POST['participant_role'] ?? 'attendee'));
        $participantName = trim((string) ($_POST['participant_name'] ?? ''));
        $selectedUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (!$scheduleTablesAvailable || !$scheduleId || !preg_match('/^(speaker|organizer|attendee)$/D', $participantRole) || strlen($participantName) > 255) {
            $flash['message'] = 'Enter a valid participant and scheduled date.';
        } else {
            try {
                rmsActivityScheduleBegin($conn);
                $dateStmt = rmsActivitySchedulePrepare(
                    $conn,
                    "SELECT d.schedule_date
                     FROM activity_schedule_dates d
                     INNER JOIN research_activities ra ON ra.activity_id = d.activity_id
                     WHERE d.schedule_id = ? AND d.activity_id = ? AND d.status = 'scheduled'
                       AND ra.status <> 'cancelled' LIMIT 1 FOR UPDATE"
                );
                $dateStmt->bind_param('ii', $scheduleId, $activityId);
                if (!$dateStmt->execute()) {
                    throw new RuntimeException('Unable to verify the scheduled date.');
                }
                $scheduleRow = $dateStmt->get_result()->fetch_assoc();
                $dateStmt->close();
                if (!$scheduleRow) {
                    throw new RuntimeException('Choose an active scheduled date.');
                }

                $shouldNotifyParticipant = false;
                if ($selectedUserId) {
                    $userStmt = rmsActivitySchedulePrepare(
                        $conn,
                        "SELECT CONCAT_WS(' ', first_name, last_name) AS full_name
                         FROM users WHERE user_id = ? AND status = 'active'
                           AND role IN ('faculty','student','research_staff') AND deleted_at IS NULL LIMIT 1 FOR UPDATE"
                    );
                    $userStmt->bind_param('i', $selectedUserId);
                    if (!$userStmt->execute()) {
                        throw new RuntimeException('Unable to verify the participant account.');
                    }
                    $selectedUser = $userStmt->get_result()->fetch_assoc();
                    $userStmt->close();
                    if (!$selectedUser) {
                        throw new RuntimeException('Choose an active eligible account.');
                    }
                    if ($participantName === '') {
                        $participantName = trim((string) $selectedUser['full_name']);
                    }
                    $existingParticipantStmt = rmsActivitySchedulePrepare(
                        $conn,
                        'SELECT is_invited, is_notified FROM activity_schedule_participants
                         WHERE schedule_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
                    );
                    $existingParticipantStmt->bind_param('ii', $scheduleId, $selectedUserId);
                    if (!$existingParticipantStmt->execute()) {
                        throw new RuntimeException('Unable to check the existing date invitation.');
                    }
                    $existingParticipant = $existingParticipantStmt->get_result()->fetch_assoc();
                    $existingParticipantStmt->close();
                    $shouldNotifyParticipant = !$isConductedActivity && (!$existingParticipant
                        || (int) $existingParticipant['is_invited'] !== 1
                        || (int) $existingParticipant['is_notified'] !== 1);
                }
                if ($participantName === '') {
                    throw new RuntimeException('Enter a participant name or choose an eligible account.');
                }

                $participantAttended = $isConductedActivity ? 1 : 0;
                $participantInvited = $isConductedActivity ? 0 : 1;
                $participantStmt = rmsActivitySchedulePrepare(
                    $conn,
                    "INSERT INTO activity_schedule_participants
                     (schedule_id, user_id, participant_name, role, attended, is_invited)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE participant_name = VALUES(participant_name),
                         role = VALUES(role), attended = IF(VALUES(attended) = 1, 1, attended),
                         is_invited = VALUES(is_invited),
                         is_notified = IF(VALUES(is_invited) = 0, 0, is_notified)"
                );
                $participantStmt->bind_param('iissii', $scheduleId, $selectedUserId, $participantName, $participantRole, $participantAttended, $participantInvited);
                if (!$participantStmt->execute()) {
                    throw new RuntimeException('Unable to add the date participant.');
                }
                $participantStmt->close();
                if ($selectedUserId) {
                    $legacyStmt = rmsActivitySchedulePrepare(
                        $conn,
                        'INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended)
                         SELECT ?, ?, ?, ?, ?
                         WHERE NOT EXISTS (SELECT 1 FROM activity_participants WHERE activity_id = ? AND user_id = ?)'
                    );
                    $legacyStmt->bind_param('iissiii', $activityId, $selectedUserId, $participantName, $participantRole, $participantAttended, $activityId, $selectedUserId);
                    if (!$legacyStmt->execute()) {
                        throw new RuntimeException('Unable to preserve the activity participant record.');
                    }
                    $legacyStmt->close();
                    if ($isConductedActivity) {
                        $legacyAttendanceStmt = rmsActivitySchedulePrepare($conn, 'UPDATE activity_participants SET attended = 1 WHERE activity_id = ? AND user_id = ?');
                        $legacyAttendanceStmt->bind_param('ii', $activityId, $selectedUserId);
                        if (!$legacyAttendanceStmt->execute()) {
                            throw new RuntimeException('Unable to record actual activity attendance.');
                        }
                        $legacyAttendanceStmt->close();
                    }
                    if ($shouldNotifyParticipant && !createNotification(
                        $selectedUserId,
                        'Research activity invitation',
                        'You are invited to "' . (string) $activity['title'] . '" on ' . (string) $scheduleRow['schedule_date'] . '. Open your formal invitation to review the details and respond.',
                        'info',
                        SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId
                    )) {
                        throw new RuntimeException('Unable to notify the date participant.');
                    }
                    if ($shouldNotifyParticipant) {
                        $noticeStmt = rmsActivitySchedulePrepare(
                            $conn,
                            'UPDATE activity_schedule_participants
                             SET is_notified = 1 WHERE schedule_id = ? AND user_id = ? AND is_invited = 1'
                        );
                        $noticeStmt->bind_param('ii', $scheduleId, $selectedUserId);
                        if (!$noticeStmt->execute()) {
                            throw new RuntimeException('Unable to record the date invitation.');
                        }
                        $noticeStmt->close();
                    }
                }
                rmsActivityScheduleCommit($conn);
                logActivity("Added participant to activity #{$activityId} date #{$scheduleId}", 'research_activities');
                $flash = ['type' => 'success', 'message' => $isConductedActivity ? 'Actual attendee recorded for this activity date.' : 'Participant invited for this scheduled date.'];
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Activity date participant add failed: ' . $exception->getMessage());
                $flash['message'] = 'The participant could not be added or notified.';
            }
        }
    } elseif ($action === 'remove_schedule_participant') {
        $scheduleParticipantId = filter_var($_POST['schedule_participant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (!$scheduleTablesAvailable || !$scheduleParticipantId) {
            $flash['message'] = 'The date participant record is invalid.';
        } else {
            try {
                rmsActivityScheduleBegin($conn);
                $participantStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'SELECT sp.schedule_id, sp.user_id, sp.is_invited, sp.is_notified, sp.attended, d.schedule_date
                     FROM activity_schedule_participants sp
                     INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id
                     WHERE sp.participant_id = ? AND d.activity_id = ? LIMIT 1 FOR UPDATE'
                );
                $participantStmt->bind_param('ii', $scheduleParticipantId, $activityId);
                if (!$participantStmt->execute()) {
                    throw new RuntimeException('Unable to verify the participant record.');
                }
                $participant = $participantStmt->get_result()->fetch_assoc();
                $participantStmt->close();
                if (!$participant) {
                    throw new RuntimeException('The participant does not belong to this activity.');
                }
                if ((int) $participant['attended'] === 1) {
                    throw new RuntimeException('Attendance is recorded; this invitation cannot be cancelled.');
                }
                $clearInvitationResponse = $invitationResponseSchemaAvailable
                    ? ", invitation_response = 'pending', invitation_responded_at = NULL"
                    : '';
                $updateStmt = rmsActivitySchedulePrepare(
                    $conn,
                    'UPDATE activity_schedule_participants
                     SET is_invited = 0, is_notified = 0' . $clearInvitationResponse . '
                     WHERE participant_id = ? AND schedule_id = ?'
                );
                $scheduleId = (int) $participant['schedule_id'];
                $updateStmt->bind_param('ii', $scheduleParticipantId, $scheduleId);
                if (!$updateStmt->execute()) {
                    throw new RuntimeException('Unable to remove the participant invitation.');
                }
                $updateStmt->close();
                if ((int) $participant['is_invited'] === 1
                    && (int) $participant['is_notified'] === 1
                    && $participant['user_id'] !== null
                    && !createNotification(
                        (int) $participant['user_id'],
                        'Research activity invitation cancelled',
                        'Your invitation for "' . (string) $activity['title'] . '" on ' . (string) $participant['schedule_date'] . ' was cancelled.',
                        'warning',
                        SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activityId
                    )) {
                    throw new RuntimeException('Unable to notify the participant about the cancellation.');
                }
                logActivity("Removed date participant #{$scheduleParticipantId} from activity #{$activityId}", 'research_activities');
                rmsActivityScheduleCommit($conn);
                $flash = ['type' => 'success', 'message' => 'The participant invitation was cancelled; attendance history was retained.'];
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Activity date participant removal failed: ' . $exception->getMessage());
                $flash['message'] = 'The participant invitation could not be cancelled.';
            }
        }
    } elseif (in_array($action, ['complete', 'cancel'], true)) {
        $targetStatus = $action === 'complete' ? 'conducted' : 'cancelled';
        if ($action === 'cancel' && $scheduleTablesAvailable) {
            try {
                rmsActivityScheduleCancelActivity($conn, (int) $activityId);
                logActivity("Marked research activity #{$activityId} as cancelled", 'research_activities');
                $flash = ['type' => 'success', 'message' => 'Activity and all scheduled dates were cancelled; recipients were notified.'];
            } catch (Throwable $exception) {
                error_log('Research activity cancellation failed: ' . $exception->getMessage());
                $flash['message'] = 'The activity could not be cancelled and its recipients were not notified.';
            }
        } else {
            $stmt = $conn->prepare("UPDATE research_activities SET status = ? WHERE activity_id = ? AND status = 'ongoing'");
            if (!$stmt) {
                $flash['message'] = 'The activity status could not be updated.';
            } else {
                $stmt->bind_param('si', $targetStatus, $activityId);
                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    logActivity("Marked research activity #{$activityId} as {$targetStatus}", 'research_activities');
                    $flash = ['type' => 'success', 'message' => "Activity marked {$targetStatus}."];
                } else {
                    $flash['message'] = 'Only ongoing activities can be completed.';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete_activity') {
        $conn->begin_transaction();
        try {
            if ($participantsAvailable) {
                $stmt = $conn->prepare('DELETE FROM activity_participants WHERE activity_id = ?');
                $stmt->bind_param('i', $activityId);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare('DELETE FROM research_activities WHERE activity_id = ?');
            $stmt->bind_param('i', $activityId);
            $stmt->execute();
            $deleted = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) throw new RuntimeException('Activity was not deleted.');
            $conn->commit();
            if ($activityDocument && !rmsActivityDocumentDeleteUpload($conn, (int) $activityDocument['document_upload_id'])) {
                error_log('Deleted activity document upload could not be removed: ' . (int) $activityDocument['document_upload_id']);
            }
            logActivity("Deleted research activity #{$activityId}: {$activity['title']}", 'research_activities');
            $flash = ['type' => 'success', 'message' => 'Activity deleted.'];
            $redirectToDetail = false;
        } catch (Throwable $exception) {
            $conn->rollback();
            $flash['message'] = 'The activity could not be deleted.';
        }
    } elseif ($action === 'add_participant') {
        if (!$participantsAvailable) {
            $flash['message'] = 'Participant management is not installed.';
        } else {
            $participantRole = trim((string) ($_POST['participant_role'] ?? ''));
            $participantName = trim((string) ($_POST['participant_name'] ?? ''));
            $selectedUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            if (!preg_match('/^(speaker|organizer|attendee)$/D', $participantRole)) {
                $flash['message'] = 'Choose a valid participant role.';
            } elseif (strlen($participantName) > 255) {
                $flash['message'] = 'Participant name must not exceed 255 characters.';
            } else {
                if ($selectedUserId) {
                    $stmt = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS full_name FROM users WHERE user_id = ? AND status = 'active' AND role IN ('faculty', 'student', 'research_staff') AND deleted_at IS NULL LIMIT 1");
                    $stmt->bind_param('i', $selectedUserId);
                    $stmt->execute();
                    $selectedUser = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$selectedUser) {
                        $flash['message'] = 'Choose an active student, faculty, or staff user.';
                        $selectedUserId = null;
                    } elseif ($participantName === '') {
                        $participantName = (string) $selectedUser['full_name'];
                    }
                }
                if ($flash['message'] === 'The requested action is invalid.' && $participantName === '') {
                    $flash['message'] = 'Enter a participant name or choose a user.';
                } elseif ($flash['message'] === 'The requested action is invalid.') {
                    $stmt = $conn->prepare('INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended) VALUES (?, ?, ?, ?, 0)');
                    $stmt->bind_param('iiss', $activityId, $selectedUserId, $participantName, $participantRole);
                    $stmt->execute();
                    $participantId = (int) $stmt->insert_id;
                    $stmt->close();
                    logActivity("Added participant #{$participantId} to research activity #{$activityId}", 'research_activities');
                    $flash = ['type' => 'success', 'message' => 'Participant added.'];
                }
            }
        }
    } elseif ($action === 'remove_participant') {
        $participantId = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (!$participantsAvailable || !$participantId) {
            $flash['message'] = 'The participant record is invalid.';
        } else {
            $stmt = $conn->prepare('DELETE FROM activity_participants WHERE participant_id = ? AND activity_id = ?');
            $stmt->bind_param('ii', $participantId, $activityId);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                logActivity("Removed participant #{$participantId} from research activity #{$activityId}", 'research_activities');
                $flash = ['type' => 'success', 'message' => 'Participant removed.'];
            } else {
                $flash['message'] = 'The participant does not belong to this activity.';
            }
            $stmt->close();
        }
    } elseif ($action === 'save_attendance') {
        $listedIds = is_array($_POST['participant_ids'] ?? null) ? array_values(array_unique($_POST['participant_ids'])) : [];
        $attendedInput = is_array($_POST['attended'] ?? null) ? $_POST['attended'] : [];
        $participantIds = [];
        foreach ($listedIds as $listedId) {
            $validated = filter_var($listedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validated) $participantIds[] = (int) $validated;
        }
        if (!$participantsAvailable || !in_array((string) $activity['status'], ['scheduled', 'completed'], true)) {
            $flash['message'] = 'Attendance can only be recorded for scheduled or completed activities.';
        } elseif (!$participantIds) {
            $flash['message'] = 'There are no participants to update.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE activity_participants SET attended = ? WHERE participant_id = ? AND activity_id = ?');
                foreach ($participantIds as $participantId) {
                    $attended = isset($attendedInput[$participantId]) && (string) $attendedInput[$participantId] === '1' ? 1 : 0;
                    $stmt->bind_param('iii', $attended, $participantId, $activityId);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
                logActivity("Updated attendance for research activity #{$activityId}", 'research_activities');
                $flash = ['type' => 'success', 'message' => 'Attendance saved.'];
            } catch (Throwable $exception) {
                $conn->rollback();
                $flash['message'] = 'Attendance could not be saved.';
            }
        }
    }

    $_SESSION['activities_flash'] = $flash;
    $redirect = $redirectToDetail ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId : SITE_URL . 'pages/shared/activities.php';
    header('Location: ' . $redirect);
    exit;
}

$participantCount = 0;
$participants = [];
$activeUsers = [];
$canViewParticipants = $activity && ($canManage || $role === 'admin' || (int) ($activity['created_by'] ?? 0) === $userId);
if ($activity && $participantsAvailable) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS participant_count FROM activity_participants WHERE activity_id = ?');
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $participantCount = (int) ($stmt->get_result()->fetch_assoc()['participant_count'] ?? 0);
    $stmt->close();
    if ($canViewParticipants) {
        $stmt = $conn->prepare('SELECT participant_id, user_id, participant_name, role, attended FROM activity_participants WHERE activity_id = ? ORDER BY participant_name ASC, participant_id ASC');
        $stmt->bind_param('i', $activityId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $participants[] = $row;
        $stmt->close();
    }
}
if ($canManage && $activity) {
    try {
        if ($scheduleTablesAvailable) {
            $activeUsers = array_values(rmsActivityScheduleEligibleUsers($conn));
        } else {
            $stmt = rmsActivitySchedulePrepare($conn, "SELECT user_id, first_name, last_name, role FROM users WHERE status = 'active' AND role IN ('faculty','student','research_staff') AND deleted_at IS NULL ORDER BY role ASC, last_name ASC, first_name ASC");
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to load eligible activity participants.');
            }
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $activeUsers[] = $row;
            $stmt->close();
        }
    } catch (Throwable $exception) {
        error_log('Activity eligible participant load failed: ' . $exception->getMessage());
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Eligible participants could not be loaded.'];
    }
}

$budgetData = null;
$contributionResponses = [];
$budgetNotices = [];
$budgetRevisions = [];
$budgetNoticeDeliveries = [];
if ($activity && $budgetSchemaAvailable) {
    try {
        $budgetData = rmsActivityBudgetLoad($conn, (int) $activityId);
        if ($budgetData && in_array((string) $activity['status'], ['upcoming', 'scheduled', 'ongoing'], true)) {
            $contributionResponses = rmsActivityBudgetLoadContributionResponses($conn, (int) $activityId);
        }
        if ($canManage) {
            $budgetNotices = rmsActivityBudgetLoadNotices($conn, (int) $activityId);
            $budgetRevisions = rmsActivityBudgetLoadRevisions($conn, (int) $activityId);
            foreach ($budgetNotices as $budgetNotice) {
                $budgetNoticeDeliveries[(int) $budgetNotice['notice_id']] = rmsActivityBudgetLoadNoticeDeliveries(
                    $conn,
                    (int) $budgetNotice['notice_id'],
                    (int) $activityId
                );
            }
        }
    } catch (Throwable $exception) {
        error_log('Activity budget detail load failed: ' . $exception->getMessage());
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Activity budget details could not be loaded.'];
    }
}
$hasMandatoryParticipantCharge = false;
$organizationalActualTotal = 0.0;
$hasOrganizationalActuals = false;
if ($budgetData) {
    foreach ($budgetData['expenses'] as $budgetExpense) {
        if ($budgetExpense['charge_type'] === 'mandatory') {
            $hasMandatoryParticipantCharge = true;
        }
        if ($budgetExpense['organizational_actual_amount'] !== null) {
            $hasOrganizationalActuals = true;
            $organizationalActualTotal += (float) $budgetExpense['organizational_actual_amount'];
        }
    }
}
$canRespondToContribution = $budgetData
    && $budgetData['budget_status'] === 'pending'
    && $budgetData['funding_source'] !== 'free'
    && !empty($budgetData['contribution_prompt'])
    && !$hasMandatoryParticipantCharge
    && in_array((string) $activity['status'], ['upcoming', 'scheduled', 'ongoing'], true);

$flash = $_SESSION['activities_flash'] ?? null;
unset($_SESSION['activities_flash']);
$shell = match ($role) {
    'admin' => 'admin', 'research_staff' => 'staff', 'faculty' => 'faculty', default => 'student',
};
$pageTitle = $activity ? (string) $activity['title'] : 'Activity not found';
if ($shell === 'admin') renderAdminShell($user, 'activities.php', $pageTitle, 'Research activity details');
elseif ($shell === 'staff') renderStaffShell($user, 'activities.php', $pageTitle, 'Research activity details');
elseif ($shell === 'faculty') renderFacultyShell($user, 'activities.php', $pageTitle, 'Research activity details');
else renderStudentShell($user, 'activities.php', $pageTitle, 'Research activity details');

$typeLabels = ['seminar' => 'Seminar', 'training' => 'Training', 'workshop' => 'Workshop', 'colloquium' => 'Colloquium', 'conference' => 'Conference'];
$activityStoredStatus = (string) ($activity['status'] ?? '');
$activityDisplayStatus = $activity
    ? rmsActivityScheduleClockStatus(array_values($scheduleDates), $activityStoredStatus)
    : $activityStoredStatus;
$statusBadge = activityDetailStatusBadge($activityDisplayStatus);
$activityClockSessions = array_map(
    static fn (array $schedule): array => [
        'status' => (string) ($schedule['status'] ?? 'scheduled'),
        'start' => (int) ($schedule['clock_start_timestamp'] ?? 0) * 1000,
        'end' => (int) ($schedule['clock_end_timestamp'] ?? 0) * 1000,
    ],
    array_values($scheduleDates)
);
$activeScheduleCount = count(array_filter($scheduleDates, static fn (array $row): bool => (string) $row['status'] === 'scheduled'));
$mainDateLabel = $activity ? date('M j, Y', strtotime((string) $activity['activity_date'])) : '';
if ($activity && !empty($activity['is_multi_day'])) {
    $rangeStart = (string) ($activity['schedule_start_date'] ?? $activity['activity_date']);
    $rangeEnd = (string) ($activity['schedule_end_date'] ?? $activity['activity_date']);
    $mainDateLabel = date('M j, Y', strtotime($rangeStart)) . ' – ' . date('M j, Y', strtotime($rangeEnd));
    $mainDateLabel .= ' · ' . $activeScheduleCount . ' session' . ($activeScheduleCount === 1 ? '' : 's');
} elseif ($activity && !empty($activity['activity_time'])) {
    $mainDateLabel .= ' · ' . date('g:i A', strtotime((string) $activity['activity_time']));
}
?>

<style>
.detail-page{max-width:1180px;margin:0 auto;color:#172033}.detail-back{display:inline-flex;margin-bottom:16px;color:#0f766e;font-size:12px;font-weight:800;text-decoration:none}.detail-hero{padding:28px 30px;border-left:5px solid #0f766e;border-radius:4px 18px 18px 4px;background:#eef8f6}.detail-tags{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800}.type-badge{background:#d9ede9;color:#0f5f58}.tone-info{background:#e0f2fe;color:#075985}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.detail-hero h1{max-width:850px;margin:0;font-size:clamp(26px,4vw,42px);line-height:1.08;letter-spacing:-.04em;text-wrap:balance}.detail-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:25px;padding-top:20px;border-top:1px solid #cfdfdc}.meta-item span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em}.meta-item strong{display:block;margin-top:6px;font-size:13px;line-height:1.4}.description{max-width:760px;margin:22px 0 0;color:#475569;line-height:1.7}.action-bar{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 24px;padding:14px;border:1px solid #dfe7e5;border-radius:13px;background:#fff}.action-bar form{display:inline}.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;box-sizing:border-box;border:1px solid #0f766e;border-radius:8px;padding:8px 13px;background:#0f766e;color:#fff;font:inherit;font-size:11px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#115e59;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.button.danger{border-color:#dc2626;background:#fff;color:#b42318}.button:focus-visible,.detail-back:focus-visible,input:focus,select:focus{outline:3px solid rgba(15,118,110,.13);outline-offset:2px}.flash{margin:18px 0;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.panel{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.count-mark{display:grid;place-items:center;min-width:42px;height:42px;border-radius:11px;background:#e3f3f0;color:#0f766e;font-weight:850}.table-wrap{overflow-x:auto}.detail-table{width:100%;min-width:680px;border-collapse:collapse}.detail-table th{padding:11px 16px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.detail-table td{padding:14px 16px;border-top:1px solid #edf1f5;font-size:13px}.attendance-yes{color:#166534;font-weight:800}.attendance-no{color:#64748b}.inline-form{display:grid;grid-template-columns:1fr 1fr 160px auto;gap:13px;align-items:end;padding:20px}.field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.field input,.field select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:12px}.attendance-list{padding:7px 20px 20px}.attendance-row{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:12px 2px;border-bottom:1px solid #edf1f5}.attendance-row label{font-size:13px;font-weight:700}.attendance-row input{width:18px;height:18px;accent-color:#0f766e}.attendance-submit{display:flex;justify-content:flex-end;margin-top:16px}.empty{padding:45px 24px;text-align:center;color:#64748b}.empty h2{margin:0 0 6px;color:#172033;font-size:17px}.privacy-note{padding:32px 24px;text-align:center;color:#64748b;font-size:13px}.not-found{padding:70px 25px;border:1px solid #e2e8f0;border-radius:17px;background:#fff;text-align:center}.not-found h1{margin:0;font-size:24px}.not-found p{margin:9px auto 20px;max-width:500px;color:#64748b;line-height:1.6}@media(max-width:800px){.detail-meta{grid-template-columns:1fr 1fr}.inline-form{grid-template-columns:1fr 1fr}}@media(max-width:560px){.detail-hero{padding:24px 21px}.detail-meta,.inline-form{grid-template-columns:1fr}.action-bar,.action-bar form,.button{width:100%}}
.date-schedule-list{display:grid;gap:14px;padding:18px}.date-schedule-card{padding:18px;border:1px solid #e2e8f0;border-radius:13px;background:#fff}.date-schedule-card.cancelled{background:#f8fafc;opacity:.82}.date-schedule-card h3{margin:0 0 12px;font-size:16px}.date-schedule-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.date-schedule-meta span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}.date-schedule-meta strong{display:block;margin-top:4px;font-size:12px;line-height:1.5}.date-participant-list{margin:0;padding:0;list-style:none}.date-participant-list li{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid #edf1f5;font-size:12px}.date-participant-actions{margin-top:15px;padding-top:14px;border-top:1px solid #e8edf3}.date-participant-actions .inline-form{padding:10px 0 0}.date-attendance{margin-top:12px}.date-attendance .attendance-list{padding:0 0 8px}@media(max-width:800px){.date-schedule-meta{grid-template-columns:1fr 1fr}}@media(max-width:560px){.date-schedule-meta{grid-template-columns:1fr}.date-participant-list li{align-items:flex-start;flex-direction:column}}
.budget-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;padding:18px 20px}.budget-summary-item{padding:13px;border:1px solid #e2e8f0;border-radius:11px;background:#fbfefd}.budget-summary-item span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.budget-summary-item strong{display:block;margin-top:6px;font-size:13px}.budget-status-note{margin:0 20px 16px;padding:12px 14px;border-radius:10px;background:#fff8e8;color:#8a5b06;font-size:12px;line-height:1.6}.budget-cost-list{display:grid;gap:8px;padding:0 20px 18px}.budget-cost-row{display:flex;justify-content:space-between;gap:14px;padding:11px 13px;border:1px solid #e8edf3;border-radius:10px;font-size:12px}.budget-cost-row strong{display:block}.budget-cost-row small{display:block;margin-top:3px;color:#64748b}.budget-cost-values{text-align:right;white-space:nowrap}.budget-history-list{display:grid;gap:12px;padding:18px}.budget-history-item{padding:13px;border:1px solid #e2e8f0;border-radius:10px}.budget-history-item p{margin:6px 0;color:#475569;font-size:12px;line-height:1.6}.budget-history-item summary{cursor:pointer;font-size:12px;font-weight:800}.notice-recipient-list{display:grid;gap:6px;margin-top:10px;padding-left:18px;color:#475569;font-size:11px}.contribution-response{margin:12px 0;padding:13px;border:1px solid #dbe7e4;border-radius:11px;background:#f7fbfa}.contribution-response p{margin:0 0 8px;color:#475569;font-size:12px}.contribution-response form{display:flex;gap:9px;align-items:end;flex-wrap:wrap}.contribution-response select{min-width:230px;min-height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 9px;background:#fff;color:#172033;font:inherit;font-size:12px}.delivery-failed{color:#b42318}.delivery-done{color:#166534}@media(max-width:800px){.budget-summary-grid{grid-template-columns:1fr 1fr}.budget-cost-row{align-items:flex-start;flex-direction:column}.budget-cost-values{text-align:left;white-space:normal}}@media(max-width:560px){.budget-summary-grid{grid-template-columns:1fr}}
</style>

<style>
.invitation-letter{margin:18px 0;padding:clamp(20px,4vw,34px);border:1px solid #dce6e3;border-radius:12px;background:linear-gradient(180deg,#fff 0%,#fcfefd 100%);box-shadow:0 8px 24px rgba(30,50,70,.045);color:#243247}.invitation-letter-heading{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid #e5ece9;color:#0f5f58;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.invitation-letter p{margin:17px 0 0;font-size:13px;line-height:1.75}.invitation-letter .invitation-letter-greeting{margin-top:22px}.invitation-letter-signoff{margin-top:19px!important}.invitation-letter-signoff strong{display:block;margin-top:6px}.invitation-rsvp{margin-top:22px;padding-top:17px;border-top:1px solid #e5ece9}.invitation-rsvp-status{margin:0!important;font-weight:750}.invitation-rsvp-note{margin:6px 0 12px!important;color:#64748b;font-size:11px!important}.invitation-response-actions{display:flex;justify-content:flex-start;gap:9px;flex-wrap:wrap}.invitation-response-actions form{margin:0}.invitation-response-actions .button{width:auto}.invitation-response-actions .button.secondary{background:#fff}.invitation-response-edit{margin-top:10px}.invitation-response-edit summary{display:inline-flex;list-style:none;cursor:pointer}.invitation-response-edit summary::-webkit-details-marker{display:none}.invitation-response-edit[open] .invitation-response-actions{margin-top:10px}.invitation-rsvp-unavailable{color:#8a5b06}.invitation-visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.confirmed-attendees{padding:20px 24px;color:#475569}.confirmed-attendees h4{margin:0 0 8px;color:#172033;font-size:14px}.confirmed-attendees p{margin:0;font-size:12px;line-height:1.6}.confirmed-attendees .date-participant-list{padding:0}@media(max-width:560px){.invitation-response-actions,.invitation-response-actions form,.invitation-response-actions .button{width:100%}}
</style>

<style>
.activity-document-panel .panel-head{padding:20px 24px;border-bottom:1px solid #edf1f5}
.activity-document-panel .activity-document-row{display:flex;align-items:center;gap:16px;padding:24px}
.activity-document-panel .activity-document-badge{display:grid;place-items:center;flex:0 0 44px;height:52px;border:1px solid #dce7e5;border-radius:8px;background:#f1f7f6;color:#0f766e;font-size:11px;font-weight:800;letter-spacing:.04em}
.activity-document-panel .activity-document-info{flex:1;min-width:0}
.activity-document-panel .activity-document-name{margin:0;color:#172033;font-size:14px;font-weight:600;line-height:1.5;overflow-wrap:anywhere}
.activity-document-panel .activity-document-meta{display:block;margin-top:4px;color:#64748b;font-size:12px;line-height:1.5}
.activity-document-panel .activity-document-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.activity-document-panel .activity-document-actions form{margin:0}
.activity-document-panel .button{min-height:40px;padding:9px 14px;font-size:12px;white-space:nowrap}
.activity-document-panel .activity-document-actions a.button,.activity-document-panel .activity-document-actions a.button:visited,.activity-document-panel .activity-document-actions a.button:hover,.activity-document-panel .activity-document-actions a.button:focus{color:#fff}
.activity-document-panel .button.danger{background:#fff;border-color:#e2e8f0;color:#b42318}
.activity-document-panel .button.danger:hover{background:#fff5f4;border-color:#f5c4be;color:#912018}
.activity-document-panel .button:focus-visible{outline:3px solid #99d5cb;outline-offset:3px}
@media(max-width:640px){.activity-document-panel .activity-document-row{flex-wrap:wrap;padding:20px}.activity-document-panel .activity-document-actions{width:100%;padding-top:4px}.activity-document-panel .activity-document-actions .button,.activity-document-panel .activity-document-actions form{width:auto}.activity-document-panel .panel-head{padding:20px}}
</style>

<div class="detail-page">
  <a class="detail-back" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activities.php') ?>">← All research activities</a>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= activityDetailEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <?php if (!$activity): ?>
    <section class="not-found"><h1>Activity not found</h1><p><?= activityDetailEscape($activitiesAvailable ? 'The activity may have been removed, or the link is incomplete.' : 'The research activities module is not installed on this database.') ?></p><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activities.php') ?>">Return to activities</a></section>
  <?php else: ?>
    <header class="detail-hero">
      <div class="detail-tags">
        <?php if (!$currentUserIsActivityInvitee): ?><span class="badge type-badge"><?= activityDetailEscape($typeLabels[$activity['activity_type']] ?? 'Activity') ?></span><?php endif; ?>
        <span class="badge tone-<?= activityDetailEscape($statusBadge['tone']) ?>" data-live-activity-status data-stored-status="<?= activityDetailEscape($activityStoredStatus) ?>" data-clock-sessions="<?= activityDetailEscape((string) json_encode($activityClockSessions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"><?= activityDetailEscape($statusBadge['label']) ?></span>
      </div>
      <h1><?= activityDetailEscape($activity['title']) ?></h1>
      <?php if (!$currentUserIsActivityInvitee): ?>
        <div class="detail-meta">
          <div class="meta-item"><span><?= !empty($activity['is_multi_day']) ? 'Schedule window' : 'Date and time' ?></span><strong><?= activityDetailEscape($mainDateLabel) ?></strong></div>
          <div class="meta-item"><span>Venue default</span><strong><?= activityDetailEscape($activity['venue'] ?: 'To be announced') ?></strong></div>
          <div class="meta-item"><span>Speaker default</span><strong><?= activityDetailEscape($activity['speaker'] ?: 'Not specified') ?></strong></div>
          <div class="meta-item"><span>Organizer</span><strong><?= activityDetailEscape($activity['organizer'] ?: 'Not specified') ?></strong></div>
        </div>
        <?php if ($activity['description']): ?><p class="description"><?= nl2br(activityDetailEscape($activity['description'])) ?></p><?php endif; ?>
      <?php endif; ?>
    </header>

    <?php if ($activityDocument && ($role === 'admin' || $canManage || $userIsInvited)): ?>
      <section class="panel activity-document-panel" aria-labelledby="activity-document-heading">
        <div class="panel-head"><div><h2 id="activity-document-heading">Activity document</h2><p>This is a separate attachment for invited users.</p></div></div>
        <div class="activity-document-row">
          <span class="activity-document-badge" aria-hidden="true">PDF</span>
          <div class="activity-document-info">
            <p class="activity-document-name"><?= activityDetailEscape((string) $activityDocument['original_name']) ?></p>
            <span class="activity-document-meta">PDF document · <?= activityDetailEscape(number_format(((int) $activityDocument['file_size']) / 1024, 1)) ?> KB</span>
          </div>
          <div class="activity-document-actions">
            <a class="button" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-document.php?id=' . (int) $activityId) ?>">View document</a>
            <?php if ($role === 'admin'): ?><form method="post" onsubmit="return confirm('Remove the uploaded activity document?');"><?= csrfField() ?><input type="hidden" name="action" value="remove_activity_document"><button class="button danger" type="submit">Remove document</button></form><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($canManage): ?><nav class="action-bar" aria-label="Activity management"><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . $activityId . ($capacityPage !== '' ? '&capacity_page=' . rawurlencode($capacityPage) : '')) ?>">Edit activity</a><?php if (!$scheduleTablesAvailable): ?><a class="button secondary" href="#add-participants">Add Participants</a><?php if (in_array($activity['status'], ['scheduled', 'completed'], true)): ?><a class="button secondary" href="#attendance">Mark Attendance</a><?php endif; ?><?php endif; ?><?php if ($canStartActivity): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="start_activity"><button class="button" type="submit">Start activity</button></form><?php endif; ?><?php if ($canMarkConductedActivity): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_conducted"><button class="button" type="submit">Mark conducted</button></form><?php endif; ?><?php if ($activity['status'] === 'ongoing'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="complete"><button class="button" type="submit">Complete</button></form><form method="post" onsubmit="return confirm('Cancel this activity?');"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><button class="button danger" type="submit">Cancel</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this activity and all participant records? This cannot be undone.');"><?= csrfField() ?><input type="hidden" name="action" value="delete_activity"><button class="button danger" type="submit">Delete</button></form></nav><?php endif; ?>

    <?php
      $expenseLabels = ['registration' => 'Registration', 'transport' => 'Transportation / travel', 'accommodation' => 'Accommodation', 'meals' => 'Meals', 'online_fee' => 'Online participation fee', 'other' => 'Other related cost'];
      $scopeLabels = ['all' => 'All participants', 'student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff', 'specific' => 'Selected user'];
    ?>
    <?php if ($scheduleTablesAvailable): ?>
      <section class="panel" aria-labelledby="schedule-heading"><div class="panel-head"><div><h2 id="schedule-heading"><?= $isConductedActivity ? 'Activity dates and attendance' : 'Scheduled dates' ?></h2><p>Times, venue, and participant invitations are managed per date. Attendance can be recorded once the activity is ongoing or conducted.</p></div></div>
        <?php if (!$scheduleDates): ?><div class="empty"><h2>No date schedule is available</h2><p>Use Edit Schedule to add the session dates for this activity.</p></div>
        <?php else: ?><div class="date-schedule-list">
          <?php foreach ($scheduleDates as $scheduleDate => $scheduleRow):
            $scheduleVenue = $scheduleRow['venue_override'] ?: ($activity['venue'] ?? '');
            $scheduleSpeaker = $scheduleRow['speaker_override'] ?: ($activity['speaker'] ?? '');
            $scheduleParticipants = $scheduleRow['participants'];
            $scheduleActive = (string) $scheduleRow['status'] === 'scheduled';
            $currentInviteParticipant = null;
            foreach ($scheduleParticipants as $scheduleParticipant) {
                if ((int) ($scheduleParticipant['user_id'] ?? 0) === $userId
                    && (int) $scheduleParticipant['is_invited'] === 1) {
                    $currentInviteParticipant = $scheduleParticipant;
                    break;
                }
            }
            $invitationResponseOpen = $scheduleActive
                && !in_array((string) $activity['status'], ['conducted', 'completed', 'cancelled'], true)
                && (int) ($scheduleRow['response_window_open'] ?? 0) === 1;
            $scheduleExpenses = $budgetData
                ? array_values(array_filter($budgetData['expenses'], static fn (array $expense): bool => (int) ($expense['schedule_id'] ?? 0) === (int) $scheduleRow['schedule_id']))
                : [];
            $scheduleStartTime = $scheduleRow['start_time'] ? date('g:i A', strtotime((string) $scheduleRow['start_time'])) : 'Time not set';
            $scheduleEndTime = $scheduleRow['end_time'] ? date('g:i A', strtotime((string) $scheduleRow['end_time'])) : '';
            $scheduleDisplayStatus = !$scheduleActive
                ? 'cancelled'
                : ($isConductedActivity ? 'conducted' : rmsActivityScheduleSessionClockStatus($scheduleRow));
            $scheduleDisplayBadge = activityDetailStatusBadge($scheduleDisplayStatus);
            $scheduleClockPayload = [
                'status' => (string) ($scheduleRow['status'] ?? 'scheduled'),
                'activityStatus' => $activityStoredStatus,
                'start' => (int) ($scheduleRow['clock_start_timestamp'] ?? 0) * 1000,
                'end' => (int) ($scheduleRow['clock_end_timestamp'] ?? 0) * 1000,
            ];
          ?>
            <article class="date-schedule-card <?= $scheduleActive ? '' : 'cancelled' ?>">
              <h3><?= activityDetailEscape(date('l, F j, Y', strtotime((string) $scheduleDate))) ?> · <span class="badge tone-<?= activityDetailEscape($scheduleDisplayBadge['tone']) ?>" data-live-schedule-status data-session-clock="<?= activityDetailEscape((string) json_encode($scheduleClockPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"><?= activityDetailEscape($scheduleDisplayBadge['label']) ?></span></h3>
              <?php if (!$currentInviteParticipant): ?><div class="date-schedule-meta">
                <div><span>Time</span><strong><?= activityDetailEscape($scheduleStartTime . ($scheduleEndTime !== '' ? ' – ' . $scheduleEndTime : '')) ?></strong></div>
                <div><span>Venue</span><strong><?= activityDetailEscape($scheduleVenue ?: 'To be announced') ?></strong></div>
                <div><span>Speaker</span><strong><?= activityDetailEscape($scheduleSpeaker ?: 'Not specified') ?></strong></div>
                <div><span>Participants</span><strong><?= count($scheduleParticipants) ?></strong></div>
              </div><?php endif; ?>
              <?php if ($currentInviteParticipant): ?>
                <?php
                  $invitationResponse = (string) ($currentInviteParticipant['invitation_response'] ?? 'pending');
                  $invitationResponseLabel = match ($invitationResponse) {
                      'accepted' => 'Yes, I will attend',
                      'declined' => 'No, I cannot attend',
                      default => 'No response yet',
                  };
                  $invitationRecipientName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                  $invitationOrganizer = trim((string) ($activity['organizer'] ?? '')) ?: 'Research Capacity Building Office';
                  $invitationDateText = date('l, F j, Y', strtotime((string) $scheduleDate));
                  $invitationStartText = $scheduleRow['start_time'] ? date('g:i A', strtotime((string) $scheduleRow['start_time'])) : '';
                  $invitationEndText = $scheduleRow['end_time'] ? date('g:i A', strtotime((string) $scheduleRow['end_time'])) : '';
                  $invitationTimeText = $invitationStartText !== ''
                      ? ($invitationEndText !== '' ? 'from ' . $invitationStartText . ' to ' . $invitationEndText : 'beginning at ' . $invitationStartText)
                      : '';
                  $invitationVenueText = trim((string) $scheduleVenue) !== ''
                      ? trim((string) $scheduleVenue)
                      : 'a venue to be announced';
                  $invitationScheduleSentence = 'The session will take place on ' . $invitationDateText;
                  if ($invitationTimeText !== '') {
                      $invitationScheduleSentence .= ', ' . $invitationTimeText;
                  }
                  $invitationScheduleSentence .= ' at ' . $invitationVenueText . '.';
                  if ($invitationTimeText === '') {
                      $invitationScheduleSentence .= ' The time will be announced.';
                  }
                ?>
                <section class="invitation-letter" aria-labelledby="invitation-letter-<?= (int) $scheduleRow['schedule_id'] ?>">
                  <div class="invitation-letter-heading"><span>EARIST Cavite</span><span>Research Activity Invitation</span></div>
                  <p class="invitation-letter-greeting">Dear <?= activityDetailEscape($invitationRecipientName ?: 'Research Colleague') ?>,</p>
                  <p>On behalf of <?= activityDetailEscape($invitationOrganizer) ?>, we are pleased to invite you to attend <strong><?= activityDetailEscape((string) $activity['title']) ?></strong>, a <?= activityDetailEscape(strtolower($typeLabels[$activity['activity_type']] ?? 'research activity')) ?>.</p>
                  <h4 id="invitation-letter-<?= (int) $scheduleRow['schedule_id'] ?>" class="invitation-visually-hidden">Invitation details for <?= activityDetailEscape((string) $activity['title']) ?></h4>
                  <p><?= activityDetailEscape($invitationScheduleSentence) ?><?php if (trim((string) $scheduleSpeaker) !== ''): ?> The session will be led by <strong><?= activityDetailEscape((string) $scheduleSpeaker) ?></strong>.<?php endif; ?></p>
                  <?php if (trim((string) ($activity['description'] ?? '')) !== ''): ?><p><?= nl2br(activityDetailEscape((string) $activity['description'])) ?></p><?php endif; ?>
                  <p>We hope you can join us. Please let us know whether you will attend using the response buttons below.</p>
                  <p class="invitation-letter-signoff">Respectfully,<strong><?= activityDetailEscape($invitationOrganizer) ?></strong></p>
                  <div class="invitation-rsvp" aria-label="Attendance response">
                    <p class="invitation-rsvp-status">Your response: <?= activityDetailEscape($invitationResponseLabel) ?><?= !empty($currentInviteParticipant['invitation_responded_at']) ? ' · Updated ' . activityDetailEscape(date('M j, Y g:i A', strtotime((string) $currentInviteParticipant['invitation_responded_at']))) : '' ?></p>
                    <?php if ($invitationResponseSchemaAvailable && $invitationResponseOpen): ?>
                      <?php if ($invitationResponse === 'pending'): ?>
                        <p class="invitation-rsvp-note">Please choose whether you can attend. You can change your answer until this session starts.</p>
                        <form class="invitation-response-actions" method="post">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="save_invitation_response">
                          <input type="hidden" name="schedule_participant_id" value="<?= (int) $currentInviteParticipant['participant_id'] ?>">
                          <button class="button" type="submit" name="invitation_response" value="accepted">Yes, I will attend</button>
                          <button class="button secondary" type="submit" name="invitation_response" value="declined">No, I cannot attend</button>
                        </form>
                      <?php else: ?>
                        <p class="invitation-rsvp-note">You can change your answer until this session starts.</p>
                        <details class="invitation-response-edit">
                          <summary class="button secondary">Change response</summary>
                          <form class="invitation-response-actions" method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="save_invitation_response">
                            <input type="hidden" name="schedule_participant_id" value="<?= (int) $currentInviteParticipant['participant_id'] ?>">
                            <button class="button" type="submit" name="invitation_response" value="accepted">Yes, I will attend</button>
                            <button class="button secondary" type="submit" name="invitation_response" value="declined">No, I cannot attend</button>
                          </form>
                        </details>
                      <?php endif; ?>
                    <?php elseif (!$invitationResponseSchemaAvailable): ?>
                      <p class="invitation-rsvp-note invitation-rsvp-unavailable">Your invitation is visible, but responses are temporarily unavailable. Please contact the activity organizer.</p>
                    <?php else: ?>
                      <p class="invitation-rsvp-note">Responses closed when this session started.</p>
                    <?php endif; ?>
                  </div>
                </section>
              <?php endif; ?>
              <?php if ($scheduleExpenses): ?><div class="budget-cost-list"><strong>Date-specific costs</strong>
                <?php foreach ($scheduleExpenses as $expense): ?>
                  <div class="budget-cost-row"><span><strong><?= activityDetailEscape($expenseLabels[$expense['expense_category']] ?? 'Other cost') ?><?= $expense['description'] ? ' · ' . activityDetailEscape($expense['description']) : '' ?></strong><?php if ($budgetData['funding_source'] !== 'organizational'): ?><small><?= activityDetailEscape($scopeLabels[$expense['audience_scope']] ?? 'All participants') ?><?= $expense['charge_type'] !== 'none' ? ' · ' . ucfirst((string) $expense['charge_type']) . ' charge' : '' ?></small><?php endif; ?></span><span class="budget-cost-values"><?= activityDetailEscape(activityDetailExpenseValue($expense['organizational_actual_amount'], $expense['organizational_estimated_amount'], 'Organization')) ?><?php if ($budgetData['funding_source'] !== 'organizational'): ?><br><?= activityDetailEscape(activityDetailExpenseValue($expense['participant_actual_charge'], $expense['participant_estimated_charge'], 'Participant cost')) ?><?php endif; ?></span></div>
                <?php endforeach; ?>
              </div><?php endif; ?>
              <?php if (!$canViewParticipants): ?>
                <?php if ($currentInviteParticipant): ?>
                  <?php
                    $confirmedAttendees = array_values(array_filter(
                        $scheduleParticipants,
                        static fn (array $participant): bool => (int) ($participant['is_invited'] ?? 0) === 1
                            && (string) ($participant['invitation_response'] ?? 'pending') === 'accepted'
                            && trim((string) ($participant['participant_name'] ?? '')) !== ''
                    ));
                  ?>
                  <section class="confirmed-attendees" aria-labelledby="confirmed-attendees-<?= (int) $scheduleRow['schedule_id'] ?>">
                    <h4 id="confirmed-attendees-<?= (int) $scheduleRow['schedule_id'] ?>">Confirmed attendees</h4>
                    <?php if (!$invitationResponseSchemaAvailable): ?>
                      <p>Confirmed attendees are temporarily unavailable.</p>
                    <?php elseif (!$confirmedAttendees): ?>
                      <p>No one has confirmed attendance yet.</p>
                    <?php else: ?>
                      <ul class="date-participant-list">
                        <?php foreach ($confirmedAttendees as $confirmedAttendee): ?>
                          <li><span><?= activityDetailEscape(trim((string) $confirmedAttendee['participant_name'])) ?><?= $role === 'faculty' && (int) ($confirmedAttendee['user_id'] ?? 0) === $userId ? ' ( You )' : '' ?></span></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </section>
                <?php else: ?>
                  <p class="privacy-note">Participant names are limited to authorized viewers.</p>
                <?php endif; ?>
              <?php elseif (!$scheduleParticipants): ?><p class="schedule-window-note">No participants are registered for this date.</p>
              <?php else: ?>
                <ul class="date-participant-list">
                  <?php foreach ($scheduleParticipants as $scheduleParticipant): ?>
                    <?php $participantRsvpLabel = match ((string) ($scheduleParticipant['invitation_response'] ?? 'pending')) { 'accepted' => 'Accepted', 'declined' => 'Declined', default => 'No response' }; ?>
                    <li><span><?= activityDetailEscape($scheduleParticipant['participant_name'] ?: 'Unnamed participant') ?> · <?= activityDetailEscape(ucfirst((string) ($scheduleParticipant['role'] ?: 'attendee'))) ?><?= (int) $scheduleParticipant['is_invited'] === 1 || $isConductedActivity ? '' : ' · Invitation withdrawn' ?></span>
                      <?php if ($invitationResponseSchemaAvailable && (int) $scheduleParticipant['is_invited'] === 1): ?><small>RSVP: <?= activityDetailEscape($participantRsvpLabel) ?></small><?php endif; ?>
                      <span class="<?= (int) $scheduleParticipant['attended'] === 1 ? 'attendance-yes' : 'attendance-no' ?>"><?= (int) $scheduleParticipant['attended'] === 1 ? 'Attended' : 'Not marked attended' ?>
                        <?php if ($canManage && $scheduleActive && (int) $scheduleParticipant['is_invited'] === 1 && (int) $scheduleParticipant['attended'] !== 1): ?><form method="post" style="display:inline" onsubmit="return confirm('Cancel this participant invitation for this date?');"><?= csrfField() ?><input type="hidden" name="action" value="remove_schedule_participant"><input type="hidden" name="schedule_participant_id" value="<?= (int) $scheduleParticipant['participant_id'] ?>"><button class="button danger" type="submit">Cancel invitation</button></form><?php endif; ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if ($canRespondToContribution && $scheduleActive): ?>
                <?php foreach ($scheduleParticipants as $scheduleParticipant): ?>
                  <?php
                    $participantIsCurrentUser = (int) ($scheduleParticipant['user_id'] ?? 0) === $userId
                        && (int) $scheduleParticipant['is_invited'] === 1;
                    $canRespondForDate = $participantIsCurrentUser
                        && $budgetData['budget_status'] === 'pending';
                    $currentResponse = $contributionResponses[(int) $scheduleParticipant['participant_id']]['response'] ?? '';
                  ?>
                  <?php if ($canRespondForDate): ?>
                    <div class="contribution-response"><p>Optional contribution response · This records willingness only and does not indicate payment.</p><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_contribution_response"><input type="hidden" name="schedule_participant_id" value="<?= (int) $scheduleParticipant['participant_id'] ?>"><div><label for="contribution-response-<?= (int) $scheduleParticipant['participant_id'] ?>">Your response</label><select id="contribution-response-<?= (int) $scheduleParticipant['participant_id'] ?>" name="contribution_response" required><option value="">Choose a response</option><option value="willing" <?= $currentResponse === 'willing' ? 'selected' : '' ?>>Willing to contribute</option><option value="unwilling" <?= $currentResponse === 'unwilling' ? 'selected' : '' ?>>Not willing to contribute</option><option value="needs_information" <?= $currentResponse === 'needs_information' ? 'selected' : '' ?>>Need more information</option></select></div><button class="button" type="submit">Save response</button></form></div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if ($canManage && $scheduleActive): ?>
                <div class="date-participant-actions">
                  <form class="inline-form" method="post"><?= csrfField() ?><input type="hidden" name="action" value="add_schedule_participant"><input type="hidden" name="schedule_id" value="<?= (int) $scheduleRow['schedule_id'] ?>">
                    <div class="field"><label for="date-name-<?= (int) $scheduleRow['schedule_id'] ?>">Participant name</label><input id="date-name-<?= (int) $scheduleRow['schedule_id'] ?>" name="participant_name" maxlength="255" placeholder="Full name"></div>
                    <div class="field"><label for="date-user-<?= (int) $scheduleRow['schedule_id'] ?>">Eligible user (optional)</label><select id="date-user-<?= (int) $scheduleRow['schedule_id'] ?>" name="user_id"><option value="">No linked account</option><?php foreach ($activeUsers as $activeUser): ?><option value="<?= (int) $activeUser['user_id'] ?>"><?= activityDetailEscape(trim((string) $activeUser['first_name'] . ' ' . (string) $activeUser['last_name']) . ' · ' . ucfirst((string) $activeUser['role'])) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="date-role-<?= (int) $scheduleRow['schedule_id'] ?>">Role</label><select id="date-role-<?= (int) $scheduleRow['schedule_id'] ?>" name="participant_role"><option value="attendee">Attendee</option><option value="speaker">Speaker</option><option value="organizer">Organizer</option></select></div>
                    <button class="button" type="submit">Add to this date</button>
                  </form>
                  <?php if ($canViewParticipants && $canRecordActivityAttendance && $scheduleParticipants): ?>
                    <form class="attendance-list date-attendance" method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_schedule_attendance"><input type="hidden" name="schedule_id" value="<?= (int) $scheduleRow['schedule_id'] ?>">
                      <?php foreach ($scheduleParticipants as $scheduleParticipant): ?><input type="hidden" name="schedule_participant_ids[]" value="<?= (int) $scheduleParticipant['participant_id'] ?>"><div class="attendance-row"><label for="schedule-attended-<?= (int) $scheduleParticipant['participant_id'] ?>"><?= activityDetailEscape($scheduleParticipant['participant_name'] ?: 'Unnamed participant') ?></label><input id="schedule-attended-<?= (int) $scheduleParticipant['participant_id'] ?>" name="schedule_attended[<?= (int) $scheduleParticipant['participant_id'] ?>]" type="checkbox" value="1" <?= (int) $scheduleParticipant['attended'] === 1 ? 'checked' : '' ?>></div><?php endforeach; ?>
                      <div class="attendance-submit"><button class="button" type="submit">Save date attendance</button></div>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div><?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (!$scheduleTablesAvailable): ?>
    <section class="panel" aria-labelledby="participants-heading"><div class="panel-head"><div><h2 id="participants-heading">Participants</h2><p><?= $canViewParticipants ? 'Registered speakers, organizers, and attendees.' : 'Participant names are limited to authorized viewers.' ?></p></div><div class="count-mark" aria-label="<?= $participantCount ?> participants"><?= $participantCount ?></div></div>
      <?php if (!$canViewParticipants): ?><div class="privacy-note">You can see the participant count, but the participant list is private.</div>
      <?php elseif (!$participants): ?><div class="empty"><h2>No participants yet</h2><p>Participant records will appear here after they are added.</p></div>
      <?php else: ?><div class="table-wrap"><table class="detail-table"><thead><tr><th>Name</th><th>Role</th><th>Attended</th><?php if ($canManage): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php foreach ($participants as $participant): ?><tr><td><?= activityDetailEscape($participant['participant_name'] ?: 'Unnamed participant') ?></td><td><?= activityDetailEscape(ucfirst((string) $participant['role'])) ?></td><td class="<?= (int) $participant['attended'] === 1 ? 'attendance-yes' : 'attendance-no' ?>"><?= (int) $participant['attended'] === 1 ? 'Yes' : 'No' ?></td><?php if ($canManage): ?><td><form method="post" onsubmit="return confirm('Remove this participant?');"><?= csrfField() ?><input type="hidden" name="action" value="remove_participant"><input type="hidden" name="participant_id" value="<?= (int) $participant['participant_id'] ?>"><button class="button danger" type="submit">Remove</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canManage && !$scheduleTablesAvailable): ?>
      <section class="panel" id="add-participants" aria-labelledby="add-participants-heading"><div class="panel-head"><div><h2 id="add-participants-heading">Add participant</h2><p>Enter a name, optionally connect an active student, faculty, or staff account, and assign a role.</p></div></div><form class="inline-form" method="post"><?= csrfField() ?><input type="hidden" name="action" value="add_participant"><div class="field"><label for="participant-name">Participant name</label><input id="participant-name" name="participant_name" type="text" maxlength="255" placeholder="Full name"></div><div class="field"><label for="participant-user">Linked user (optional)</label><select id="participant-user" name="user_id"><option value="">No linked account</option><?php foreach ($activeUsers as $activeUser): ?><option value="<?= (int) $activeUser['user_id'] ?>"><?= activityDetailEscape(trim($activeUser['first_name'] . ' ' . $activeUser['last_name']) . ' · ' . ucfirst((string) $activeUser['role'])) ?></option><?php endforeach; ?></select></div><div class="field"><label for="participant-role">Role</label><select id="participant-role" name="participant_role" required><option value="attendee">Attendee</option><option value="speaker">Speaker</option><option value="organizer">Organizer</option></select></div><button class="button" type="submit">Add participant</button></form></section>

      <?php if (in_array($activity['status'], ['scheduled', 'completed'], true)): ?><section class="panel" id="attendance" aria-labelledby="attendance-heading"><div class="panel-head"><div><h2 id="attendance-heading">Mark attendance</h2><p>Check each participant who attended, then save the list.</p></div></div><?php if (!$participants): ?><div class="empty"><h2>No attendance list yet</h2><p>Add participants before recording attendance.</p></div><?php else: ?><form class="attendance-list" method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_attendance"><?php foreach ($participants as $participant): ?><input type="hidden" name="participant_ids[]" value="<?= (int) $participant['participant_id'] ?>"><div class="attendance-row"><label for="attended-<?= (int) $participant['participant_id'] ?>"><?= activityDetailEscape($participant['participant_name'] ?: 'Unnamed participant') ?></label><input id="attended-<?= (int) $participant['participant_id'] ?>" name="attended[<?= (int) $participant['participant_id'] ?>]" type="checkbox" value="1" <?= (int) $participant['attended'] === 1 ? 'checked' : '' ?>></div><?php endforeach; ?><div class="attendance-submit"><button class="button" type="submit">Save attendance</button></div></form><?php endif; ?></section><?php endif; ?>
    <?php endif; ?>
    <?php if ($budgetData): ?>
      <?php
        $fundingLabels = ['organizational' => 'Institutional budget', 'participants' => 'Participant-funded', 'combined' => 'Combined funding', 'free' => 'Free of charge'];
        $formatLabels = ['on_site' => 'On-site', 'online' => 'Online', 'hybrid' => 'Hybrid'];
        $activityWideExpenses = array_values(array_filter($budgetData['expenses'], static fn (array $expense): bool => $expense['schedule_id'] === null));
      ?>
      <section class="panel" aria-labelledby="budget-heading"><div class="panel-head"><div><h2 id="budget-heading">Budget and participation details</h2><p>Organizational spending and participant charges are recorded separately.</p></div><?php if ($canManage): ?><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . (int) $activityId . '&mode=budget' . ($capacityPage !== '' ? '&capacity_page=' . rawurlencode($capacityPage) : '') . '#budget-section') ?>">Edit budget</a><?php endif; ?></div>
        <div class="budget-summary-grid">
          <div class="budget-summary-item"><span>Format</span><strong><?= activityDetailEscape($formatLabels[$budgetData['activity_format']] ?? 'Not specified') ?></strong></div>
          <div class="budget-summary-item"><span>Funding</span><strong><?= activityDetailEscape($fundingLabels[$budgetData['funding_source']] ?? 'Not specified') ?></strong></div>
          <div class="budget-summary-item"><span>Budget status</span><strong><?= activityDetailEscape(ucfirst((string) $budgetData['budget_status'])) ?></strong></div>
          <div class="budget-summary-item"><span>Organizational allocation</span><strong><?= activityDetailEscape(activityDetailMoney($budgetData['organizational_allocation'] ?? null)) ?></strong></div>
          <div class="budget-summary-item"><span>Recorded organizational actual expenses</span><strong><?= activityDetailEscape($hasOrganizationalActuals ? '₱' . number_format($organizationalActualTotal, 2) : 'Not recorded') ?></strong></div>
        </div>
        <?php if ($budgetData['budget_status'] === 'pending'): ?><p class="budget-status-note">Funding arrangements and participant expenses are still being finalized and may change.</p><?php endif; ?>
        <?php if ($activityWideExpenses): ?><div class="budget-cost-list"><strong>Activity-wide costs</strong>
          <?php foreach ($activityWideExpenses as $expense): ?>
            <div class="budget-cost-row"><span><strong><?= activityDetailEscape($expenseLabels[$expense['expense_category']] ?? 'Other cost') ?><?= $expense['description'] ? ' · ' . activityDetailEscape($expense['description']) : '' ?></strong><?php if ($budgetData['funding_source'] !== 'organizational'): ?><small><?= activityDetailEscape($scopeLabels[$expense['audience_scope']] ?? 'All participants') ?><?= $expense['charge_type'] !== 'none' ? ' · ' . ucfirst((string) $expense['charge_type']) . ' charge' : '' ?></small><?php endif; ?></span><span class="budget-cost-values"><?= activityDetailEscape(activityDetailExpenseValue($expense['organizational_actual_amount'], $expense['organizational_estimated_amount'], 'Organization')) ?><?php if ($budgetData['funding_source'] !== 'organizational'): ?><br><?= activityDetailEscape(activityDetailExpenseValue($expense['participant_actual_charge'], $expense['participant_estimated_charge'], 'Participant cost')) ?><?php endif; ?></span></div>
          <?php endforeach; ?>
        </div><?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($canManage && $budgetData && $budgetNotices): ?>
      <section class="panel" aria-labelledby="budget-notices-heading"><div class="panel-head"><div><h2 id="budget-notices-heading">Budget notice history</h2><p>Delivery results are tracked by recipient. Retries only target failed deliveries.</p></div></div><div class="budget-history-list">
        <?php foreach ($budgetNotices as $budgetNotice): ?>
          <?php $noticeId = (int) $budgetNotice['notice_id']; $failedCount = (int) ($budgetNotice['failed_count'] ?? 0); ?>
          <?php $pendingCount = (int) ($budgetNotice['pending_count'] ?? 0); ?>
          <details class="budget-history-item"><summary>Notice #<?= $noticeId ?> · <?= (int) ($budgetNotice['delivered_count'] ?? 0) ?> delivered · <?= $failedCount ?> failed · <?= $pendingCount ?> pending · <?= activityDetailEscape($budgetNotice['confirmed_at']) ?></summary>
            <p><?= nl2br(activityDetailEscape($budgetNotice['message'])) ?></p>
            <ul class="notice-recipient-list"><?php foreach ($budgetNoticeDeliveries[$noticeId] ?? [] as $delivery): ?>
              <li class="<?= $delivery['status'] === 'delivered' ? 'delivery-done' : ($delivery['status'] === 'failed' ? 'delivery-failed' : '') ?>"><?= activityDetailEscape(trim((string) ($delivery['first_name'] ?? '') . ' ' . (string) ($delivery['last_name'] ?? '')) ?: 'Unavailable user') ?> · <?= activityDetailEscape(ucfirst((string) $delivery['status'])) ?><?= $delivery['error_message'] ? ' — ' . activityDetailEscape($delivery['error_message']) : '' ?></li>
            <?php endforeach; ?></ul>
            <?php if ($failedCount > 0): ?><form method="post" style="margin-top:12px"><?= csrfField() ?><input type="hidden" name="action" value="retry_activity_budget_notice"><input type="hidden" name="notice_id" value="<?= $noticeId ?>"><button class="button secondary" type="submit">Retry failed recipients</button></form><?php endif; ?>
            <?php if ($pendingCount > 0): ?><form method="post" style="margin-top:12px"><?= csrfField() ?><input type="hidden" name="action" value="continue_activity_budget_notice"><input type="hidden" name="notice_id" value="<?= $noticeId ?>"><button class="button secondary" type="submit">Continue pending deliveries</button></form><?php endif; ?>
          </details>
        <?php endforeach; ?>
      </div></section>
    <?php endif; ?>

    <?php if ($canManage && $budgetData && $budgetRevisions): ?>
      <section class="panel" aria-labelledby="budget-revisions-heading"><div class="panel-head"><div><h2 id="budget-revisions-heading">Budget revision history</h2><p>Changes record before and after values; revisions do not change status or send notices.</p></div></div><div class="budget-history-list">
        <?php foreach ($budgetRevisions as $revision): ?>
          <details class="budget-history-item"><summary>Revision #<?= (int) $revision['revision_id'] ?> · <?= activityDetailEscape(trim((string) ($revision['first_name'] ?? '') . ' ' . (string) ($revision['last_name'] ?? '')) ?: 'Former user') ?> · <?= activityDetailEscape($revision['created_at']) ?></summary><p><strong>Previous:</strong> <?= activityDetailEscape(activityDetailBudgetRevisionSummary($revision['previous_values'])) ?></p><p><strong>New:</strong> <?= activityDetailEscape(activityDetailBudgetRevisionSummary($revision['new_values'])) ?></p></details>
        <?php endforeach; ?>
      </div></section>
    <?php endif; ?>


  <?php endif; ?>
</div>

<script src="<?= activityDetailEscape(SITE_URL . 'js/activity-status-clock.js') ?>" defer></script>
<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
