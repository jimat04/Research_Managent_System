<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity-schedule.php';
require_once __DIR__ . '/../../includes/activity-budget.php';
require_once __DIR__ . '/../../includes/activity-document.php';
require_once __DIR__ . '/../../includes/activity-qr.php';
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
$capacityModulePages = rmsPermissionModules()['research_capacity_building']['pages'];
$modulePageInput = trim((string) ($_GET['module_page'] ?? ''));
$modulePage = array_key_exists($modulePageInput, $capacityModulePages) ? $modulePageInput : '';
$activityManagePages = ['view_activity', 'upcoming_activities', 'ongoing_activities', 'conducted_activities'];
if ($role === 'research_staff' && $modulePage !== '' && !in_array($modulePage, $activityManagePages, true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$canManageActivity = static function (string $activityType, string $permissionPage) use ($role, $userId, $activityManagePages): bool {
    if ($role === 'admin') {
        return true;
    }
    return in_array($activityType, ['seminar_workshop', 'training', 'colloquium', 'conference_congress', 'other'], true)
        && in_array($permissionPage, $activityManagePages, true)
        && hasModulePermission($userId, 'research_capacity_building', 'manage', $permissionPage);
};
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$activityDetailUrl = SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) ($activityId ?? 0)
    . ($modulePage !== '' ? '&module_page=' . rawurlencode($modulePage) : '');

$activitiesAvailable = rmsActivityScheduleTableExists($conn, 'research_activities');
$activityDocumentsAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'document_upload_id');
$customActivityTypeAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'custom_activity_type');
$customActivityTypeSelect = $customActivityTypeAvailable
    ? 'ra.custom_activity_type'
    : 'NULL AS custom_activity_type';
$activitySourceLinksAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'source_project_id')
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'source_publication_id');
$participantsAvailable = rmsActivityScheduleTableExists($conn, 'activity_participants');
$scheduleTablesAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_dates');
$activityCheckinDetailsAvailable = $scheduleTablesAvailable
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'attendee_affiliation')
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'attendee_program_department');
$activityCheckinKeyConfigured = preg_match('/^[a-f0-9]{64}$/i', ACTIVITY_CHECKIN_HASH_KEY) === 1;
$activityCheckinSchemaAvailable = $scheduleTablesAvailable
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_dates', 'checkin_token_hash')
    && rmsActivityScheduleTableExists($conn, 'activity_schedule_checkins')
    && $activityCheckinDetailsAvailable;
$activityCheckinAvailable = $activityCheckinSchemaAvailable && $activityCheckinKeyConfigured;
$invitationResponseSchemaAvailable = $scheduleTablesAvailable
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'invitation_response')
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'invitation_responded_at');
$budgetSchemaAvailable = rmsActivityBudgetSchemaAvailable($conn);

$activity = null;
if ($activitiesAvailable && $activityId) {
    $documentColumn = $activityDocumentsAvailable ? ', document_upload_id' : '';
    $activitySourceSelect = $activitySourceLinksAvailable
        ? ', ra.source_project_id, ra.source_publication_id, rp.title AS source_project_title, p.research_title AS source_publication_title'
        : ', NULL AS source_project_id, NULL AS source_publication_id, NULL AS source_project_title, NULL AS source_publication_title';
    $activitySourceJoins = $activitySourceLinksAvailable
        ? ' LEFT JOIN research_projects rp ON rp.project_id = ra.source_project_id LEFT JOIN publications p ON p.publication_id = ra.source_publication_id'
        : '';
    $activitySql = $scheduleTablesAvailable
        ? 'SELECT ra.activity_id, ra.activity_type, ' . $customActivityTypeSelect . ', ra.title, ra.activity_date, ra.activity_time, ra.is_multi_day, ra.schedule_start_date, ra.schedule_end_date, ra.venue, ra.speaker, ra.organizer, ra.description, ra.status, ra.created_by' . $activitySourceSelect . $documentColumn . ' FROM research_activities ra' . $activitySourceJoins . ' WHERE ra.activity_id = ? LIMIT 1'
        : 'SELECT ra.activity_id, ra.activity_type, ' . $customActivityTypeSelect . ', ra.title, ra.activity_date, ra.activity_time, ra.venue, ra.speaker, ra.organizer, ra.description, ra.status, ra.created_by' . $activitySourceSelect . $documentColumn . ' FROM research_activities ra' . $activitySourceJoins . ' WHERE ra.activity_id = ? LIMIT 1';
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
if ($activity && in_array($role, ['student', 'faculty'], true)
    && !rmsActivityScheduleUserInvited($conn, (int) $activity['activity_id'], $userId)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
$canManage = $activity ? $canManageActivity((string) $activity['activity_type'], $modulePage) : false;
$scheduleDates = [];
if ($activity && $scheduleTablesAvailable) {
    try {
        $scheduleDates = rmsActivityScheduleLoad(
            $conn,
            (int) $activityId,
            $invitationResponseSchemaAvailable,
            $activityCheckinDetailsAvailable,
            !$canManage,
            $userId
        );
    } catch (Throwable $exception) {
        error_log('Activity schedule detail load failed: ' . $exception->getMessage());
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The activity schedule could not be loaded.'];
    }
}
$activityPageForStatus = 'view_activity';
if ($activity) {
    $effectiveActivityStatus = rmsActivityScheduleClockStatus(array_values($scheduleDates), (string) ($activity['status'] ?? ''));
    $activityPageForStatus = match ($effectiveActivityStatus) {
        'upcoming' => 'upcoming_activities',
        'ongoing' => 'ongoing_activities',
        'conducted', 'past' => 'conducted_activities',
        default => 'view_activity',
    };
}
if ($role === 'research_staff' && $activity) {
    if ($modulePage === '') {
        $modulePage = $activityPageForStatus;
    }
    if (!in_array($modulePage, $activityManagePages, true)
        || ($modulePage !== 'view_activity' && $modulePage !== $activityPageForStatus)
        || !hasModulePermission($userId, 'research_capacity_building', 'view', $modulePage)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    $canManage = $canManageActivity((string) $activity['activity_type'], $modulePage);
    if ($canManage && $scheduleTablesAvailable) {
        try {
            $scheduleDates = rmsActivityScheduleLoad(
                $conn,
                (int) $activityId,
                $invitationResponseSchemaAvailable,
                $activityCheckinDetailsAvailable,
                true,
                $userId
            );
        } catch (Throwable $exception) {
            error_log('Activity schedule management data failed to load: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Activity management data could not be loaded.'];
        }
    }
}
$rosterPageData = [];
if ($activity && $canManage && $scheduleTablesAvailable) {
    foreach ($scheduleDates as $scheduleRow) {
        if ((string) ($scheduleRow['status'] ?? '') !== 'scheduled') {
            continue;
        }
        $rosterScheduleId = (int) $scheduleRow['schedule_id'];
        $readRosterQuery = static function (string $key, string $default = '') use ($rosterScheduleId): string {
            $values = $_GET[$key] ?? null;
            $value = is_array($values) ? ($values[$rosterScheduleId] ?? null) : null;
            return is_scalar($value) ? (string) $value : $default;
        };
        $requestedPage = filter_var($readRosterQuery('roster_page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $rosterFilters = [
            'search' => substr(trim($readRosterQuery('roster_search')), 0, 400),
            'role' => $readRosterQuery('roster_role'),
            'affiliation' => $readRosterQuery('roster_affiliation'),
            'rsvp' => $readRosterQuery('roster_rsvp'),
            'attendance' => $readRosterQuery('roster_attendance'),
        ];
        try {
            $rosterPageData[$rosterScheduleId] = rmsActivityScheduleLoadRosterPage(
                $conn,
                (int) $activityId,
                $rosterScheduleId,
                $requestedPage,
                $rosterFilters['search'],
                $rosterFilters['role'],
                $rosterFilters['affiliation'],
                $rosterFilters['rsvp'],
                $rosterFilters['attendance'],
                $invitationResponseSchemaAvailable,
                $activityCheckinDetailsAvailable
            );
            $rosterPageData[$rosterScheduleId]['filters'] = $rosterFilters;
        } catch (Throwable $exception) {
            error_log('Activity roster page load failed: ' . $exception->getMessage());
            $rosterPageData[$rosterScheduleId] = [
                'participants' => [], 'total_count' => 0, 'attended_count' => 0,
                'invited_count' => 0, 'other_count' => 0, 'filtered_count' => 0,
                'filtered_invited_count' => 0, 'filtered_other_count' => 0,
                'page' => 1, 'page_size' => 50, 'page_count' => 1, 'filters' => $rosterFilters,
                'error' => true,
            ];
        }
    }
}
$checkinTokenHashes = [];
if ($activity && $activityCheckinSchemaAvailable) {
    try {
        $tokenStmt = $conn->prepare('SELECT schedule_id, checkin_token_hash FROM activity_schedule_dates WHERE activity_id = ?');
        if (!$tokenStmt) {
            throw new RuntimeException('Unable to prepare activity check-in tokens.');
        }
        $tokenStmt->bind_param('i', $activityId);
        if (!$tokenStmt->execute()) {
            throw new RuntimeException('Unable to load activity check-in tokens.');
        }
        $tokenResult = $tokenStmt->get_result();
        while ($tokenRow = $tokenResult->fetch_assoc()) {
            $checkinTokenHashes[(int) $tokenRow['schedule_id']] = (string) ($tokenRow['checkin_token_hash'] ?? '');
        }
        $tokenStmt->close();
    } catch (Throwable $exception) {
        error_log('Activity check-in token load failed: ' . $exception->getMessage());
        $activityCheckinAvailable = false;
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

$canViewActivitySourceResearch = in_array($role, ['admin', 'student'], true)
    || ($role === 'research_staff'
        && hasModulePermission($userId, 'research_implementation_outputs', 'view', 'completed_research'));
$canViewActivitySourcePublication = in_array($role, ['admin', 'faculty', 'student'], true)
    || ($role === 'research_staff'
        && (hasModulePermission($userId, 'research_implementation_outputs', 'view', 'research_outputs')
            || hasModulePermission($userId, 'publication_dissemination', 'view', 'publication_records')));
if ($activity && $role === 'faculty' && !empty($activity['source_project_id'])) {
    try {
        $sourceAccessStmt = $conn->prepare(
            'SELECT 1 FROM research_projects rp
              WHERE rp.project_id = ? AND rp.deleted_at IS NULL
                AND (rp.adviser_id = ? OR EXISTS (
                    SELECT 1 FROM project_advisers pa
                     WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
                ))
              LIMIT 1'
        );
        $sourceProjectId = (int) $activity['source_project_id'];
        $sourceAccessStmt->bind_param('iii', $sourceProjectId, $userId, $userId);
        $sourceAccessStmt->execute();
        $canViewActivitySourceResearch = $sourceAccessStmt->get_result()->num_rows > 0;
        $sourceAccessStmt->close();
    } catch (Throwable $exception) {
        error_log('Activity source research access check failed: ' . $exception->getMessage());
        $canViewActivitySourceResearch = false;
    }
}
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Your form expired. Please try again.'];
        header('Location: ' . $activityDetailUrl);
        exit;
    }
    if (!$activitiesAvailable || !$activity || !$activityId) {
        $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The requested activity is not available.'];
        header('Location: ' . SITE_URL . 'pages/shared/activities.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'generate_checkin_qr' || $action === 'revoke_checkin_qr') {
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (!$canManage || !$activityCheckinSchemaAvailable || !$scheduleId
            || ($action === 'generate_checkin_qr' && !$activityCheckinKeyConfigured)) {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'Activity check-in QR management is unavailable.'];
            header('Location: ' . $activityDetailUrl);
            exit;
        }
        try {
            $verifyStmt = $conn->prepare(
                'SELECT d.schedule_id, d.status FROM activity_schedule_dates d
                  WHERE d.schedule_id = ? AND d.activity_id = ? LIMIT 1'
            );
            if (!$verifyStmt) {
                throw new RuntimeException('Unable to verify the scheduled date.');
            }
            $verifyStmt->bind_param('ii', $scheduleId, $activityId);
            if (!$verifyStmt->execute() || !($verifiedSchedule = $verifyStmt->get_result()->fetch_assoc())) {
                $verifyStmt->close();
                throw new RuntimeException('Choose a valid scheduled date.');
            }
            $verifyStmt->close();
            if ($action === 'generate_checkin_qr' && (string) $verifiedSchedule['status'] !== 'scheduled') {
                throw new RuntimeException('A check-in QR can only be generated for an active scheduled date.');
            }

            if ($action === 'generate_checkin_qr') {
                $checkinToken = bin2hex(random_bytes(16));
                $tokenHash = hash('sha256', $checkinToken);
                $updateStmt = $conn->prepare('UPDATE activity_schedule_dates SET checkin_token_hash = ? WHERE schedule_id = ? AND activity_id = ?');
                if (!$updateStmt) {
                    throw new RuntimeException('Unable to prepare the check-in QR update.');
                }
                $updateStmt->bind_param('sii', $tokenHash, $scheduleId, $activityId);
                if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                    $updateStmt->close();
                    throw new RuntimeException('Unable to generate the activity check-in QR.');
                }
                $updateStmt->close();
                $_SESSION['activity_checkin_tokens'][$scheduleId] = $checkinToken;
                $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'A new check-in QR code is ready. Any previous code for this date has been revoked.'];
                logActivity("Generated attendance QR for activity #{$activityId}, date #{$scheduleId}", 'research_activities');
            } else {
                $updateStmt = $conn->prepare('UPDATE activity_schedule_dates SET checkin_token_hash = NULL WHERE schedule_id = ? AND activity_id = ?');
                if (!$updateStmt) {
                    throw new RuntimeException('Unable to prepare the check-in QR revocation.');
                }
                $updateStmt->bind_param('ii', $scheduleId, $activityId);
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    throw new RuntimeException('Unable to revoke the activity check-in QR.');
                }
                $updateStmt->close();
                unset($_SESSION['activity_checkin_tokens'][$scheduleId]);
                $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'The check-in QR code has been revoked.'];
                logActivity("Revoked attendance QR for activity #{$activityId}, date #{$scheduleId}", 'research_activities');
            }
        } catch (Throwable $exception) {
            error_log('Activity check-in QR update failed: ' . $exception->getMessage());
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => 'The check-in QR could not be updated.'];
        }
        header('Location: ' . $activityDetailUrl);
        exit;
    }
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
            if ($response === 'accepted') {
                $_SESSION['activity_contribution_prompt_after_rsvp'][(int) $activityId] = [
                    'participant_id' => (int) $participantId,
                    'user_id' => $userId,
                ];
            } else {
                unset($_SESSION['activity_contribution_prompt_after_rsvp'][(int) $activityId]);
            }
            logActivity("Updated activity invitation response for activity #{$activityId}", 'research_activities');
            $_SESSION['activities_flash'] = [
                'type' => 'success',
                'message' => $response === 'accepted'
                    ? 'Your response is saved: you will attend. You can change it before the session starts.'
                    : 'Your response is saved: you cannot attend. You can change it before the session starts.',
            ];
        } catch (Throwable $exception) {
            unset($_SESSION['activity_contribution_prompt_after_rsvp'][(int) $activityId]);
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . $activityDetailUrl);
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
        header('Location: ' . $activityDetailUrl);
        exit;
    }
    if ($action === 'save_contribution_response') {
        $participantId = filter_var($_POST['schedule_participant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $response = is_scalar($_POST['contribution_response'] ?? null) ? (string) $_POST['contribution_response'] : '';
        try {
            if (!$budgetSchemaAvailable || !$invitationResponseSchemaAvailable || !$participantId
                || !in_array((string) $activity['status'], ['upcoming', 'scheduled', 'ongoing'], true)) {
                throw new RuntimeException('Contribution responses are not available for this activity.');
            }
            rmsActivityBudgetSaveContributionResponse($conn, (int) $activityId, $participantId, $userId, $response);
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Your contribution response was saved. It does not record or confirm a payment.'];
        } catch (Throwable $exception) {
            $_SESSION['activities_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        header('Location: ' . $activityDetailUrl);
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
        header('Location: ' . $activityDetailUrl);
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
        header('Location: ' . $activityDetailUrl);
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
        $hasAttendanceChangesPayload = is_string($_POST['schedule_attendance_changes'] ?? null)
            && trim((string) $_POST['schedule_attendance_changes']) !== '';
        $attendanceChanges = [];
        $invalidAttendanceChangesPayload = false;
        if ($hasAttendanceChangesPayload) {
            $changesJson = (string) $_POST['schedule_attendance_changes'];
            $attendanceChanges = strlen($changesJson) <= 2000000 ? json_decode($changesJson, true) : null;
            $invalidAttendanceChangesPayload = !is_array($attendanceChanges) || json_last_error() !== JSON_ERROR_NONE;
        }
        $listedIds = $hasAttendanceChangesPayload
            ? (is_array($attendanceChanges) ? array_keys($attendanceChanges) : [])
            : (is_array($_POST['schedule_participant_ids'] ?? null) ? array_values(array_unique($_POST['schedule_participant_ids'])) : []);
        if (!$scheduleTablesAvailable || !$scheduleId || $invalidAttendanceChangesPayload) {
            $flash['message'] = 'Choose a valid scheduled date with participants.';
        } elseif (!$listedIds) {
            $flash = ['type' => 'success', 'message' => 'No attendance changes to save.'];
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
                    if ($hasAttendanceChangesPayload) {
                        $changeValue = $attendanceChanges[(string) $participantId] ?? $attendanceChanges[$participantId] ?? null;
                        if (!in_array($changeValue, [0, 1, '0', '1'], true)) {
                            throw new RuntimeException('An attendance value is invalid.');
                        }
                        $attended = (int) $changeValue;
                    } else {
                        $attended = isset($attendanceInput[$participantId]) && (string) $attendanceInput[$participantId] === '1' ? 1 : 0;
                    }
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
                $_SESSION['activity_attendance_saved'] = ['activity_id' => (int) $activityId, 'schedule_id' => $scheduleId];
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
        $participantAffiliation = strtolower(trim((string) ($_POST['attendee_affiliation'] ?? '')));
        $participantProgramDepartment = trim((string) ($_POST['attendee_program_department'] ?? ''));
        if (!$scheduleTablesAvailable || !$scheduleId || !preg_match('/^(speaker|organizer|attendee)$/D', $participantRole) || strlen($participantName) > 255
            || ($activityCheckinDetailsAvailable && (!in_array($participantAffiliation, ['student', 'faculty', 'staff', 'guest'], true) || strlen($participantProgramDepartment) > 150))) {
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
                    $participantName = trim((string) $selectedUser['full_name']);
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
                if ($activityCheckinDetailsAvailable) {
                    $participantStmt = rmsActivitySchedulePrepare(
                        $conn,
                        "INSERT INTO activity_schedule_participants
                         (schedule_id, user_id, participant_name, attendee_affiliation, attendee_program_department, role, attended, is_invited)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE participant_name = VALUES(participant_name),
                             attendee_affiliation = VALUES(attendee_affiliation), attendee_program_department = VALUES(attendee_program_department),
                             role = VALUES(role), attended = IF(VALUES(attended) = 1, 1, attended),
                             is_invited = VALUES(is_invited), is_notified = IF(VALUES(is_invited) = 0, 0, is_notified)"
                    );
                    $participantStmt->bind_param('iissssii', $scheduleId, $selectedUserId, $participantName, $participantAffiliation, $participantProgramDepartment, $participantRole, $participantAttended, $participantInvited);
                } else {
                    $participantStmt = rmsActivitySchedulePrepare(
                        $conn,
                        "INSERT INTO activity_schedule_participants
                         (schedule_id, user_id, participant_name, role, attended, is_invited)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE participant_name = VALUES(participant_name),
                             role = VALUES(role), attended = IF(VALUES(attended) = 1, 1, attended),
                             is_invited = VALUES(is_invited), is_notified = IF(VALUES(is_invited) = 0, 0, is_notified)"
                    );
                    $participantStmt->bind_param('iissii', $scheduleId, $selectedUserId, $participantName, $participantRole, $participantAttended, $participantInvited);
                }
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
    $redirect = SITE_URL . 'pages/shared/activities.php';
    if ($redirectToDetail) {
        $redirectParams = ['id' => (int) $activityId];
        if ($modulePage !== '') {
            $redirectParams['module_page'] = $modulePage;
        }
        $allowedScheduleIds = array_map(static fn (array $row): int => (int) $row['schedule_id'], array_values($scheduleDates));
        foreach (['roster_page', 'roster_search', 'roster_role', 'roster_affiliation', 'roster_rsvp', 'roster_attendance'] as $queryKey) {
            $inputValues = $_GET[$queryKey] ?? null;
            if (!is_array($inputValues)) {
                continue;
            }
            foreach ($allowedScheduleIds as $allowedScheduleId) {
                $value = $inputValues[$allowedScheduleId] ?? null;
                if (!is_scalar($value)) {
                    continue;
                }
                if ($queryKey === 'roster_page') {
                    $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
                } elseif ($queryKey === 'roster_search') {
                    $value = substr(trim((string) $value), 0, 400);
                } elseif ($queryKey === 'roster_role') {
                    $value = in_array((string) $value, ['attendee', 'speaker', 'organizer'], true) ? (string) $value : '';
                } elseif ($queryKey === 'roster_affiliation') {
                    $value = in_array((string) $value, ['student', 'faculty', 'staff', 'guest'], true) ? (string) $value : '';
                } elseif ($queryKey === 'roster_rsvp') {
                    $value = in_array((string) $value, ['pending', 'accepted', 'declined'], true) ? (string) $value : '';
                } elseif ($queryKey === 'roster_attendance') {
                    $value = in_array((string) $value, ['attended', 'not_attended'], true) ? (string) $value : '';
                }
                $redirectParams[$queryKey][$allowedScheduleId] = $value;
            }
        }
        $redirect = SITE_URL . 'pages/shared/activity-detail.php?' . http_build_query($redirectParams);
    }
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
$autoOpenContributionParticipantId = 0;
$pendingContributionPrompt = $activityId
    ? ($_SESSION['activity_contribution_prompt_after_rsvp'][(int) $activityId] ?? null)
    : null;
if ($activityId) {
    unset($_SESSION['activity_contribution_prompt_after_rsvp'][(int) $activityId]);
}
if ($canRespondToContribution
    && is_array($pendingContributionPrompt)
    && (int) ($pendingContributionPrompt['user_id'] ?? 0) === $userId) {
    $pendingParticipantId = (int) ($pendingContributionPrompt['participant_id'] ?? 0);
    foreach ($scheduleDates as $scheduleRow) {
        if ((string) ($scheduleRow['status'] ?? '') !== 'scheduled') {
            continue;
        }
        foreach ($scheduleRow['participants'] ?? [] as $scheduleParticipant) {
            if ((int) ($scheduleParticipant['participant_id'] ?? 0) === $pendingParticipantId
                && (int) ($scheduleParticipant['user_id'] ?? 0) === $userId
                && (int) ($scheduleParticipant['is_invited'] ?? 0) === 1
                && (string) ($scheduleParticipant['invitation_response'] ?? '') === 'accepted'
                && empty($contributionResponses[$pendingParticipantId]['response'])) {
                $autoOpenContributionParticipantId = $pendingParticipantId;
                break 2;
            }
        }
    }
}

$attendanceSavedScheduleId = 0;
$attendanceSavedState = $_SESSION['activity_attendance_saved'] ?? null;
if (is_array($attendanceSavedState)
    && (int) ($attendanceSavedState['activity_id'] ?? 0) === (int) ($activityId ?? 0)) {
    $attendanceSavedScheduleId = (int) ($attendanceSavedState['schedule_id'] ?? 0);
    unset($_SESSION['activity_attendance_saved']);
}

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

$typeLabels = ['seminar_workshop' => 'Seminars / Workshops', 'training' => 'Trainings', 'colloquium' => 'Colloquia', 'conference_congress' => 'Conference / Congress', 'other' => (string) ($activity['custom_activity_type'] ?? 'Other')];
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
.date-participant-actions{margin-top:20px;padding-top:20px;border-top:1px solid #e8edf3}.date-management-head{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:16px}.date-management-head h4,.date-attendance-head h4{margin:0;color:#172033;font-size:15px;line-height:1.3}.date-management-head p{margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.5}.date-management-head .date-management-kicker{margin:0 0 4px;color:#0f766e;font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.date-attendance-summary{display:flex;flex:none;align-items:baseline;gap:5px;padding:9px 12px;border:1px solid #d9e9e5;border-radius:10px;background:#f3faf8;color:#526174;font-size:11px}.date-attendance-summary strong{color:#0f766e;font-size:15px;font-variant-numeric:tabular-nums}.date-participant-form{display:grid;grid-template-columns:minmax(160px,1fr) minmax(220px,1.2fr) minmax(135px,.75fr) auto;gap:12px;align-items:end;padding:16px;border:1px solid #e1e9e7;border-radius:12px;background:#f8fbfa}.date-participant-form .field{min-width:0;margin:0}.date-participant-form .field label,.date-attendance-search label{margin-bottom:6px;color:#526174;font-size:11px;font-weight:750}.date-participant-form .field label span{color:#7b8794;font-weight:500}.date-participant-form .field input,.date-participant-form .field select,.date-attendance-search input{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:12px}.date-participant-form .field input[readonly]{background:#f1f5f9;color:#475569}.date-participant-form .button{width:auto;min-height:42px;white-space:nowrap}.date-participant-hint,.date-duplicate-name-note{grid-column:1/-1;margin:0;color:#64748b;font-size:11px;line-height:1.5}.date-duplicate-name-note{padding:9px 11px;border-left:3px solid #d97706;border-radius:5px;background:#fff8e8;color:#805b15}.date-duplicate-name-note[hidden]{display:none}.date-attendance-form{margin-top:18px;padding-top:17px;border-top:1px solid #e8edf3}.date-attendance-head{display:flex;justify-content:space-between;align-items:end;gap:18px}.date-attendance-head p{margin:5px 0 0;color:#64748b;font-size:11px}.date-attendance-search{width:min(100%,260px)}.date-attendance-search label{display:block}.date-attendance-filter-count{margin:9px 0 0;color:#718096;font-size:10px}.date-attendance-rows{display:grid;margin-top:5px}.date-attendance-row{display:flex;justify-content:space-between;align-items:center;gap:16px;min-height:57px;padding:10px 4px;border-top:1px solid #edf1f5}.date-attendance-row[hidden]{display:none}.date-attendance-person{display:grid;gap:3px;min-width:0}.date-attendance-person>label{color:#172033;font-size:12px;font-weight:750}.date-attendance-person small{color:#718096;font-size:10px;line-height:1.4}.date-attendance-toggle{display:flex;align-items:center;gap:9px;flex:none;color:#64748b;font-size:10px;font-weight:700;cursor:pointer}.date-attendance-toggle input{width:19px;height:19px;margin:0;accent-color:#0f766e}.date-attendance-empty{display:grid;gap:4px;margin-top:14px;padding:15px 16px;border:1px dashed #cbd8d4;border-radius:10px;background:#f8fbfa}.date-attendance-empty strong{color:#334155;font-size:12px}.date-attendance-empty span{color:#64748b;font-size:11px}.date-attendance-form .attendance-submit{margin-top:13px}.date-attendance-form .attendance-submit .button{width:auto}@media(max-width:800px){.date-participant-form{grid-template-columns:1fr 1fr}.date-participant-form>.button{justify-self:start}.date-attendance-head{align-items:stretch;flex-direction:column}.date-attendance-search{width:100%}}@media(max-width:560px){.date-management-head{align-items:flex-start;flex-direction:column}.date-attendance-summary{align-self:flex-start}.date-participant-form{grid-template-columns:1fr;padding:13px}.date-participant-form>.button,.date-attendance-form .attendance-submit .button{width:100%}.date-attendance-row{align-items:flex-start}.date-attendance-toggle{padding-top:2px}}
.date-participant-form .field label,.date-attendance-search label{text-transform:none;letter-spacing:0}.date-attendance-row{flex-wrap:wrap}.date-attendance-person{flex:1 1 180px}.date-cancel-invitation{min-height:32px;padding:6px 9px;font-size:10px;white-space:nowrap}@media(max-width:560px){.date-attendance-row .date-cancel-invitation{width:auto;order:3}}
.roster-filter-form{display:grid;grid-template-columns:minmax(190px,1.6fr) repeat(4,minmax(120px,1fr)) auto;gap:10px;align-items:end;margin:18px 0 10px;padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.roster-filter-form .field{min-width:0;margin:0}.roster-filter-form .field label{display:block;margin-bottom:5px;color:#526174;font-size:10px;font-weight:750}.roster-filter-form input,.roster-filter-form select{width:100%;min-height:39px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 9px;background:#fff;color:#172033;font:inherit;font-size:11px}.roster-filter-form>.button{width:auto;min-height:39px;white-space:nowrap}.roster-page-summary{margin:12px 0;color:#64748b;font-size:11px}.invited-audience-toggle{display:flex;width:auto;min-height:36px;gap:9px;margin:10px 0 0;padding:7px 11px;font-size:11px}.invited-audience-toggle[hidden],.roster-table tbody[hidden]{display:none}.invited-audience-toggle span{display:inline-grid;place-items:center;min-width:22px;height:22px;border-radius:20px;background:#d9ede9;color:#0f5f58}.roster-table-wrap{width:100%;overflow:visible}.roster-table{width:100%;border-collapse:collapse;color:#172033;font-size:11px}.roster-table thead th{padding:10px 9px;background:#f8fafc;color:#64748b;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.05em}.roster-table td{padding:11px 9px;border-top:1px solid #edf1f5;vertical-align:middle;line-height:1.45}.roster-table td:first-child{font-weight:750}.roster-group-heading th{padding:12px 9px 7px;border-top:1px solid #e5ecea;background:#f2f8f6;color:#334155;text-align:left;font-size:10px}.roster-group-heading th span{margin-left:5px;color:#64748b;font-weight:600}.roster-empty-row td{color:#64748b;text-align:center}.roster-attendance-control{display:inline-flex;align-items:center;justify-content:center;min-width:38px;min-height:34px;cursor:pointer}.roster-attendance-control input{width:19px;height:19px;margin:0;accent-color:#0f766e}.roster-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;color:#64748b;font-size:11px}.roster-pagination>div{display:flex;gap:7px}.roster-pagination .button{width:auto;min-height:34px;padding:6px 10px;font-size:10px}.date-attendance-form .attendance-submit{margin-top:14px}.date-attendance-form .attendance-submit .button{width:auto}@media(max-width:1050px){.roster-filter-form{grid-template-columns:repeat(3,minmax(0,1fr))}.roster-filter-form .field:first-child{grid-column:span 2}.roster-filter-form>.button{justify-self:start}}@media(max-width:700px){.roster-filter-form{grid-template-columns:1fr 1fr;padding:12px}.roster-filter-form .field:first-child{grid-column:1/-1}.roster-filter-form>.button{width:100%}.roster-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.roster-table,.roster-table tbody{display:block}.roster-table tbody{margin-top:8px}.roster-table tr:not(.roster-group-heading):not(.roster-empty-row){display:grid;grid-template-columns:1fr 1fr;margin:0 0 10px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;overflow:hidden}.roster-table td{display:grid;grid-template-columns:minmax(95px,38%) 1fr;gap:8px;padding:9px 10px;border-top:1px solid #edf1f5}.roster-table td::before{content:attr(data-label);color:#64748b;font-size:9px;font-weight:750;text-transform:uppercase;letter-spacing:.03em}.roster-table td:first-child{grid-column:1/-1;background:#f8fafc;font-size:13px}.roster-group-heading th{display:block;border:0;border-radius:9px}.roster-empty-row td{display:block}.roster-empty-row td::before{content:none}}@media(max-width:480px){.roster-filter-form{grid-template-columns:1fr}.roster-filter-form .field:first-child{grid-column:auto}.roster-table tr:not(.roster-group-heading):not(.roster-empty-row){grid-template-columns:1fr}.roster-table td,.roster-table td:first-child{grid-column:1/-1}.roster-pagination{align-items:flex-start;flex-direction:column}.roster-pagination>div{width:100%}.roster-pagination .button{flex:1}.date-attendance-form .attendance-submit .button{width:100%}}
</style>

<style>
.checkin-qr-tools{margin-top:16px;padding:16px;border:1px solid #dce9e6;border-radius:12px;background:#f7fbfa}.checkin-qr-tools h4{margin:0 0 5px;font-size:14px}.checkin-qr-tools p{margin:8px 0;color:#526174;font-size:12px;line-height:1.55}.checkin-qr-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.checkin-qr-actions form{margin:0}.checkin-qr-preview{display:flex;align-items:center;flex-direction:column;gap:6px;width:min(100%,270px);margin-top:14px;padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;text-align:center}.checkin-qr-preview>div{width:100%;max-width:240px}.checkin-qr-preview svg{display:block;width:100%;height:auto}.checkin-qr-preview span,.checkin-qr-preview a{font-size:11px}.checkin-qr-preview a{color:#0f766e;font-weight:700}.checkin-qr-error{color:#991b1b!important}@media(max-width:560px){.checkin-qr-actions,.checkin-qr-actions form,.checkin-qr-actions .button{width:100%}}
</style>

<style>
.invitation-letter{margin:18px 0;padding:clamp(20px,4vw,34px);border:1px solid #dce6e3;border-radius:12px;background:linear-gradient(180deg,#fff 0%,#fcfefd 100%);box-shadow:0 8px 24px rgba(30,50,70,.045);color:#243247}.invitation-letter-heading{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid #e5ece9;color:#0f5f58;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.invitation-letter p{margin:17px 0 0;font-size:13px;line-height:1.75}.invitation-letter .invitation-letter-greeting{margin-top:22px}.invitation-letter-signoff{margin-top:19px!important}.invitation-letter-signoff strong{display:block;margin-top:6px}.invitation-rsvp{margin-top:22px;padding-top:17px;border-top:1px solid #e5ece9}.invitation-rsvp-status{margin:0!important;font-weight:750}.invitation-rsvp-note{margin:6px 0 12px!important;color:#64748b;font-size:11px!important}.invitation-response-actions{display:flex;justify-content:flex-start;gap:9px;flex-wrap:wrap}.invitation-response-actions form{margin:0}.invitation-response-actions .button{width:auto}.invitation-response-actions .button.secondary{background:#fff}.invitation-response-edit{margin-top:10px}.invitation-response-edit summary{display:inline-flex;list-style:none;cursor:pointer}.invitation-response-edit summary::-webkit-details-marker{display:none}.invitation-response-edit[open] .invitation-response-actions{margin-top:10px}.invitation-rsvp-unavailable{color:#8a5b06}.invitation-visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.confirmed-attendees{padding:20px 24px;color:#475569}.confirmed-attendees h4{margin:0 0 8px;color:#172033;font-size:14px}.confirmed-attendees p{margin:0;font-size:12px;line-height:1.6}.confirmed-attendees .date-participant-list{padding:0}@media(max-width:560px){.invitation-response-actions,.invitation-response-actions form,.invitation-response-actions .button{width:100%}}
</style>

<style>
.contribution-response button[hidden]{display:none!important}.contribution-dialog{width:min(520px,calc(100% - 32px));max-height:min(88vh,720px);overflow:auto;padding:0;border:1px solid #d5e2df;border-radius:16px;background:#fff;color:#172033;box-shadow:0 24px 80px rgba(15,23,42,.24)}.contribution-dialog::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(2px)}.contribution-dialog-content{padding:24px}.contribution-dialog h2{margin:0;font-size:20px;line-height:1.25}.contribution-dialog p{margin:10px 0 0;color:#475569;font-size:13px;line-height:1.6}.contribution-dialog fieldset{display:grid;gap:9px;margin:20px 0 0;padding:0;border:0}.contribution-dialog legend{margin-bottom:8px;color:#334155;font-size:12px;font-weight:800}.contribution-dialog-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #dbe5e2;border-radius:10px;background:#fbfefd;color:#334155;font-size:13px;line-height:1.45;cursor:pointer}.contribution-dialog-option input{flex:0 0 16px;width:16px;height:16px;margin:2px 0 0;accent-color:#0f766e}.contribution-dialog-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px;margin-top:20px}.contribution-dialog-actions .button{width:auto}.contribution-dialog :focus-visible{outline:3px solid rgba(15,118,110,.3);outline-offset:2px}@media(max-width:560px){.contribution-dialog-content{padding:20px}.contribution-dialog-actions,.contribution-dialog-actions .button{width:100%}}
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

<style>
.activity-delete-dialog{position:fixed;inset:50% auto auto 50%;transform:translate(-50%,-50%);margin:0;width:min(520px,calc(100% - 32px));max-height:min(88vh,720px);overflow:auto;padding:0;border:1px solid #d5e2df;border-radius:16px;background:#fff;color:#172033;box-shadow:0 24px 80px rgba(15,23,42,.24)}.activity-delete-dialog::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(2px)}.activity-delete-dialog-content{padding:24px}.activity-delete-dialog h2{margin:0;font-size:20px;line-height:1.25}.activity-delete-dialog p{margin:10px 0 0;color:#475569;font-size:13px;line-height:1.6}.activity-delete-dialog-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px;margin-top:20px}.activity-delete-dialog-actions .button{width:auto}.activity-delete-dialog :focus-visible{outline:3px solid rgba(15,118,110,.3);outline-offset:2px}@media(max-width:560px){.activity-delete-dialog-content{padding:20px}.activity-delete-dialog-actions,.activity-delete-dialog-actions .button{width:100%}}
.checkin-qr-tools{width:min(100%,680px);box-sizing:border-box;margin:16px auto 0}.checkin-qr-preview{width:min(100%,360px);margin-right:auto;margin-left:auto}.checkin-qr-preview>div{max-width:320px}
.checkin-qr-fullscreen-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}.checkin-qr-fullscreen-actions[hidden],.checkin-qr-fullscreen-actions [hidden]{display:none!important}.checkin-qr-fullscreen-status{color:#991b1b!important}.checkin-qr-tools:fullscreen{display:flex;width:100vw;max-width:100vw;min-height:100vh;min-height:100dvh;box-sizing:border-box;flex-direction:column;justify-content:center;overflow:auto;margin:0;padding:clamp(20px,5vw,56px);border:0;border-radius:0;background:#f7fbfa;color:#172033}.checkin-qr-tools:fullscreen h4{font-size:clamp(20px,3vw,30px)}.checkin-qr-tools:fullscreen .checkin-qr-fullscreen-actions{position:absolute;top:clamp(16px,3vw,32px);right:clamp(16px,3vw,32px);margin:0}.checkin-qr-tools:fullscreen .checkin-qr-fullscreen-actions .button{width:auto;min-height:46px;padding:10px 18px;font-size:14px}.checkin-qr-tools:fullscreen .checkin-qr-preview{width:min(74vmin,760px);max-width:100%;box-sizing:border-box;margin:18px auto 0;padding:clamp(14px,3vmin,28px)}.checkin-qr-tools:fullscreen .checkin-qr-preview>div{width:min(66vmin,680px);max-width:100%}.checkin-qr-tools:fullscreen .checkin-qr-preview span,.checkin-qr-tools:fullscreen .checkin-qr-preview a{font-size:clamp(13px,2vmin,18px)}.checkin-qr-tools:fullscreen :focus-visible{outline:3px solid #0f766e;outline-offset:3px}@media(max-width:560px){.checkin-qr-fullscreen-actions,.checkin-qr-fullscreen-actions .button{width:100%}.checkin-qr-tools:fullscreen{justify-content:flex-start;padding:88px 18px 24px}.checkin-qr-tools:fullscreen .checkin-qr-fullscreen-actions{top:16px;right:16px;left:16px}.checkin-qr-tools:fullscreen .checkin-qr-fullscreen-actions .button{width:100%}.checkin-qr-tools:fullscreen .checkin-qr-preview{width:min(100%,76vmin);margin-top:auto;margin-bottom:auto}.checkin-qr-tools:fullscreen .checkin-qr-preview>div{width:min(100%,68vmin)}}
.activity-save-toast{position:fixed;z-index:1300;top:50%;left:50%;right:auto;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:start;width:min(520px,calc(100vw - 32px));box-sizing:border-box;padding:18px 18px 18px 20px;border:1px solid #a7d7bf;border-left:5px solid #16a34a;border-radius:14px;background:#fff;box-shadow:0 16px 42px rgba(15,23,42,.18);color:#14532d;transform:translate(-50%,-50%);animation:activity-toast-enter .24s cubic-bezier(.2,.75,.3,1) both}
.activity-save-toast-content{display:grid;grid-template-columns:32px minmax(0,1fr);gap:12px;align-items:start}
.activity-save-toast-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#dcfce7;color:#166534;font-size:18px;font-weight:800}
.activity-save-toast-copy strong{display:block;color:#14532d;font-size:14px;line-height:1.4}
.activity-save-toast-copy p{margin:4px 0 0;color:#365b45;font-size:12px;font-weight:500;line-height:1.55}
.activity-save-toast-dismiss{display:grid;place-items:center;width:34px;height:34px;margin:-6px -6px 0 0;border:1px solid transparent;border-radius:8px;background:transparent;color:#365b45;font:inherit;font-size:22px;line-height:1;cursor:pointer}
.activity-save-toast-dismiss:hover{border-color:#bbdfcf;background:#f0fdf4}
.activity-save-toast-dismiss:focus-visible{outline:3px solid rgba(22,163,74,.3);outline-offset:2px}
@keyframes activity-toast-enter{from{opacity:0;margin-top:-8px}to{opacity:1;margin-top:0}}
@media(max-width:640px){.activity-save-toast{width:calc(100vw - 32px);padding:16px;gap:12px}}
@media(prefers-reduced-motion:reduce){.activity-save-toast{animation:none}}
</style>

<div class="detail-page">
  <a class="detail-back" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activities.php') ?>">← All research activities</a>
  <?php if (is_array($flash)): ?>
    <?php if (($flash['type'] ?? '') === 'success' && !empty($flash['popup'])): ?>
      <div class="activity-save-toast" data-activity-save-toast>
        <div class="activity-save-toast-content" role="status" aria-live="polite" aria-atomic="true"><span class="activity-save-toast-icon" aria-hidden="true">✓</span><div class="activity-save-toast-copy"><strong>Activity created</strong><p><?= activityDetailEscape($flash['message'] ?? '') ?></p></div></div>
        <button class="activity-save-toast-dismiss" type="button" aria-label="Dismiss activity saved message" data-dismiss-activity-save-toast>×</button>
      </div>
    <?php else: ?>
      <div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= activityDetailEscape($flash['message'] ?? '') ?></div>
    <?php endif; ?>
  <?php endif; ?>
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
          <?php if ($canViewActivitySourceResearch && !empty($activity['source_project_id']) && !empty($activity['source_project_title'])): ?>
            <div class="meta-item"><span>Archived research</span><strong><a href="<?= activityDetailEscape(SITE_URL . 'pages/shared/view-research.php?id=' . (int) $activity['source_project_id']) ?>"><?= activityDetailEscape((string) $activity['source_project_title']) ?></a></strong></div>
          <?php endif; ?>
          <?php if ($canViewActivitySourcePublication && !empty($activity['source_publication_id']) && !empty($activity['source_publication_title'])): ?>
            <div class="meta-item"><span>Archived publication</span><strong><a href="<?= activityDetailEscape(SITE_URL . 'pages/shared/publication-detail.php?id=' . (int) $activity['source_publication_id']) ?>"><?= activityDetailEscape((string) $activity['source_publication_title']) ?></a></strong></div>
          <?php endif; ?>
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

    <?php if ($canManage): ?><nav class="action-bar" aria-label="Activity management"><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . $activityId . '&module_page=' . rawurlencode($modulePage)) ?>">Edit activity</a><?php if (!$scheduleTablesAvailable): ?><a class="button secondary" href="#add-participants">Add Participants</a><?php if (in_array($activity['status'], ['scheduled', 'completed'], true)): ?><a class="button secondary" href="#attendance">Mark Attendance</a><?php endif; ?><?php endif; ?><?php if ($canStartActivity): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="start_activity"><button class="button" type="submit">Start activity</button></form><?php endif; ?><?php if ($canMarkConductedActivity): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_conducted"><button class="button" type="submit">Mark conducted</button></form><?php endif; ?><?php if ($activity['status'] === 'ongoing'): ?><form method="post" onsubmit="return confirm('Cancel this activity?');"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><button class="button danger" type="submit">Cancel</button></form><?php endif; ?><form method="post" data-delete-activity-form onsubmit="return this.dataset.confirmed === 'true' || this.dataset.nativeConfirm !== 'false' && confirm('Delete this activity and its participant records?');"><?= csrfField() ?><input type="hidden" name="action" value="delete_activity"><button class="button danger" type="submit">Delete</button></form></nav>
      <dialog class="activity-delete-dialog" data-delete-activity-dialog aria-labelledby="delete-activity-title" aria-describedby="delete-activity-description"><div class="activity-delete-dialog-content"><h2 id="delete-activity-title">Delete this activity?</h2><p id="delete-activity-description">This permanently deletes the activity and its participant records. This action cannot be undone.</p><div class="activity-delete-dialog-actions"><button class="button danger" type="button" data-confirm-delete-activity>Delete activity</button><button class="button secondary" type="button" data-cancel-delete-activity>Cancel</button></div></div></dialog>
      <dialog class="activity-delete-dialog" data-revoke-checkin-dialog aria-labelledby="revoke-checkin-title" aria-describedby="revoke-checkin-description"><div class="activity-delete-dialog-content"><h2 id="revoke-checkin-title">Revoke QR check-in code?</h2><p id="revoke-checkin-description">This makes the current QR code unusable. Generate a new code if attendees still need to check in.</p><div class="activity-delete-dialog-actions"><button class="button danger" type="button" data-confirm-revoke-checkin>Revoke QR code</button><button class="button secondary" type="button" data-cancel-revoke-checkin autofocus>Keep QR code</button></div></div></dialog>
    <?php endif; ?>

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
            $scheduleIdForRoster = (int) $scheduleRow['schedule_id'];
            $scheduleRoster = $rosterPageData[$scheduleIdForRoster] ?? null;
            $scheduleRosterParticipants = is_array($scheduleRoster['participants'] ?? null) ? $scheduleRoster['participants'] : [];
            $scheduleRosterTotal = $canManage ? (int) ($scheduleRoster['total_count'] ?? 0) : count($scheduleParticipants);
            $scheduleActive = (string) $scheduleRow['status'] === 'scheduled';
            $scheduleIdForQr = (int) $scheduleRow['schedule_id'];
            $checkinSessionToken = (string) ($_SESSION['activity_checkin_tokens'][$scheduleIdForQr] ?? '');
            $storedCheckinHash = $checkinTokenHashes[$scheduleIdForQr] ?? '';
            $checkinQrMarkup = '';
            $checkinLink = '';
            $checkinQrError = false;
            $checkinQrUsesLocalhost = false;
            $hasCurrentCheckinQr = $checkinSessionToken !== ''
                && $storedCheckinHash !== ''
                && hash_equals($storedCheckinHash, hash('sha256', $checkinSessionToken));
            if ($canManage && $activityCheckinAvailable && $scheduleActive && $hasCurrentCheckinQr) {
                try {
                    $checkinBaseUrl = rmsActivityCheckinBaseUrl();
                    $checkinLink = $checkinBaseUrl . 'public/activity-check-in.php?code=' . rawurlencode($checkinSessionToken);
                    $checkinQrHost = strtolower((string) parse_url($checkinBaseUrl, PHP_URL_HOST));
                    $checkinQrUsesLocalhost = in_array($checkinQrHost, ['localhost', '127.0.0.1', '::1'], true);
                    $checkinQrMarkup = rmsActivityQrSvg(
                        $checkinLink
                    );
                } catch (Throwable $exception) {
                    $checkinQrError = true;
                }
            }
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
                <div><span>Participants</span><strong><?= $scheduleRosterTotal ?></strong></div>
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
              <?php elseif (!$canManage && !$scheduleParticipants): ?><p class="schedule-window-note">No participants are registered for this date.</p>
              <?php elseif (!$canManage): ?>
                <ul class="date-participant-list">
                  <?php foreach ($scheduleParticipants as $scheduleParticipant): ?>
                    <?php $participantRsvpLabel = match ((string) ($scheduleParticipant['invitation_response'] ?? 'pending')) { 'accepted' => 'Accepted', 'declined' => 'Declined', default => 'No response' }; ?>
                    <li><span><?= activityDetailEscape($scheduleParticipant['participant_name'] ?: 'Unnamed participant') ?> · <?= activityDetailEscape(ucfirst((string) ($scheduleParticipant['role'] ?: 'attendee'))) ?><?= (int) $scheduleParticipant['is_invited'] === 1 || $isConductedActivity ? '' : ' · Invitation withdrawn' ?></span>
                      <?php if (!empty($scheduleParticipant['attendee_affiliation']) || !empty($scheduleParticipant['attendee_program_department'])): ?><small><?php if (!empty($scheduleParticipant['attendee_affiliation'])): ?>Affiliation: <?= activityDetailEscape(ucfirst((string) $scheduleParticipant['attendee_affiliation'])) ?><?php endif; ?><?php if (!empty($scheduleParticipant['attendee_program_department'])): ?><?= !empty($scheduleParticipant['attendee_affiliation']) ? ' · ' : '' ?>Program/department: <?= activityDetailEscape((string) $scheduleParticipant['attendee_program_department']) ?><?php endif; ?></small><?php endif; ?>
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
                        && (string) ($scheduleParticipant['invitation_response'] ?? '') === 'accepted'
                        && $budgetData['budget_status'] === 'pending';
                    $scheduleParticipantId = (int) $scheduleParticipant['participant_id'];
                    $currentResponse = $contributionResponses[$scheduleParticipantId]['response'] ?? '';
                    $contributionDialogId = 'contribution-dialog-' . $scheduleParticipantId;
                    $autoOpenContributionDialog = $autoOpenContributionParticipantId === $scheduleParticipantId;
                  ?>
                  <?php if ($canRespondForDate): ?>
                    <div class="contribution-response">
                      <p>Your attendance is saved. Your contribution response will not change whether you attend or record a payment.</p>
                      <?php if ($currentResponse !== ''): ?><p>Saved contribution response: <?= activityDetailEscape(match ($currentResponse) { 'willing' => 'Willing to contribute', 'unwilling' => 'Not willing to contribute', 'needs_information' => 'Needs more information', default => 'Recorded' }) ?></p><?php endif; ?>
                      <button class="button secondary" type="button" data-open-contribution-dialog="<?= activityDetailEscape($contributionDialogId) ?>" hidden><?= $currentResponse !== '' ? 'Change contribution response' : 'Respond about contributing' ?></button>
                    </div>
                    <dialog class="contribution-dialog" id="<?= activityDetailEscape($contributionDialogId) ?>" data-contribution-dialog data-auto-open="<?= $autoOpenContributionDialog ? 'true' : 'false' ?>" aria-labelledby="<?= activityDetailEscape($contributionDialogId) ?>-title" aria-describedby="<?= activityDetailEscape($contributionDialogId) ?>-description">
                      <div class="contribution-dialog-content">
                        <h2 id="<?= activityDetailEscape($contributionDialogId) ?>-title">Would you be willing to contribute?</h2>
                        <p id="<?= activityDetailEscape($contributionDialogId) ?>-description">Your attendance response is already saved. This answer will not affect your attendance and does not confirm or record a payment.</p>
                        <form method="post">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="save_contribution_response">
                          <input type="hidden" name="schedule_participant_id" value="<?= $scheduleParticipantId ?>">
                          <fieldset>
                            <legend>Choose a response</legend>
                            <label class="contribution-dialog-option"><input type="radio" name="contribution_response" value="willing" required <?= $currentResponse === 'willing' ? 'checked' : '' ?>><span>Willing to contribute</span></label>
                            <label class="contribution-dialog-option"><input type="radio" name="contribution_response" value="unwilling" required <?= $currentResponse === 'unwilling' ? 'checked' : '' ?>><span>Not willing to contribute</span></label>
                            <label class="contribution-dialog-option"><input type="radio" name="contribution_response" value="needs_information" required <?= $currentResponse === 'needs_information' ? 'checked' : '' ?>><span>Need more information</span></label>
                          </fieldset>
                          <div class="contribution-dialog-actions"><button class="button" type="submit">Save response</button><button class="button secondary" type="button" data-close-contribution-dialog>Not now</button></div>
                        </form>
                      </div>
                    </dialog>
                    <noscript>
                      <form class="contribution-response" method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_contribution_response"><input type="hidden" name="schedule_participant_id" value="<?= $scheduleParticipantId ?>"><div class="field"><label for="contribution-response-fallback-<?= $scheduleParticipantId ?>">Your contribution response</label><select id="contribution-response-fallback-<?= $scheduleParticipantId ?>" name="contribution_response" required><option value="">Choose a response</option><option value="willing" <?= $currentResponse === 'willing' ? 'selected' : '' ?>>Willing to contribute</option><option value="unwilling" <?= $currentResponse === 'unwilling' ? 'selected' : '' ?>>Not willing to contribute</option><option value="needs_information" <?= $currentResponse === 'needs_information' ? 'selected' : '' ?>>Need more information</option></select></div><button class="button" type="submit">Save response</button></form>
                    </noscript>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <section class="checkin-qr-tools" aria-label="Attendance QR check-in">
                  <h4>QR attendance check-in</h4>
                  <?php if ($hasCurrentCheckinQr && !$checkinQrError): ?>
                    <div class="checkin-qr-fullscreen-actions" data-checkin-fullscreen-actions hidden>
                      <button class="button secondary" type="button" data-enter-checkin-fullscreen>Full screen</button>
                      <button class="button secondary" type="button" data-exit-checkin-fullscreen hidden>Exit full screen</button>
                    </div>
                    <p class="checkin-qr-fullscreen-status" data-checkin-fullscreen-status role="status" aria-live="polite" hidden></p>
                  <?php endif; ?>
                  <?php if (!$activityCheckinSchemaAvailable): ?>
                    <p>QR check-in is unavailable until its database migration is applied.</p>
                  <?php elseif (!$activityCheckinKeyConfigured): ?>
                    <p>QR check-in needs a 64-character hexadecimal <code>ACTIVITY_CHECKIN_HASH_KEY</code> in the local environment file before new codes can be used.</p>
                    <?php if ($storedCheckinHash !== ''): ?><form method="post" data-revoke-checkin-form onsubmit="return this.dataset.confirmed === 'true' || this.dataset.nativeConfirm !== 'false' && confirm('Revoke the current QR check-in code?');"><?= csrfField() ?><input type="hidden" name="action" value="revoke_checkin_qr"><input type="hidden" name="schedule_id" value="<?= $scheduleIdForQr ?>"><button class="button secondary" type="submit">Revoke QR code</button></form><?php endif; ?>
                  <?php elseif (!$scheduleActive): ?>
                    <p>This date is cancelled, so it cannot accept QR check-ins.</p>
                    <?php if ($storedCheckinHash !== ''): ?><form method="post" data-revoke-checkin-form onsubmit="return this.dataset.confirmed === 'true' || this.dataset.nativeConfirm !== 'false' && confirm('Revoke the current QR check-in code?');"><?= csrfField() ?><input type="hidden" name="action" value="revoke_checkin_qr"><input type="hidden" name="schedule_id" value="<?= $scheduleIdForQr ?>"><button class="button secondary" type="submit">Revoke QR code</button></form><?php endif; ?>
                  <?php else: ?>
                    <p>Attendees can use this date’s QR code while the activity is ongoing. Check-in closes after the scheduled date.</p>
                    <?php if ($hasCurrentCheckinQr && $checkinQrUsesLocalhost): ?><p class="checkin-qr-error">This QR link uses localhost, which attendee phones cannot reach. Set <code>ACTIVITY_CHECKIN_BASE_URL</code> to this server’s LAN or public address to use the QR from another device.</p><?php endif; ?>
                    <div class="checkin-qr-actions">
                      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="generate_checkin_qr"><input type="hidden" name="schedule_id" value="<?= $scheduleIdForQr ?>"><button class="button" type="submit"><?= $storedCheckinHash !== '' ? 'Regenerate QR code' : 'Generate QR code' ?></button></form>
                      <?php if ($storedCheckinHash !== ''): ?><form method="post" data-revoke-checkin-form onsubmit="return this.dataset.confirmed === 'true' || this.dataset.nativeConfirm !== 'false' && confirm('Revoke the current QR check-in code?');"><?= csrfField() ?><input type="hidden" name="action" value="revoke_checkin_qr"><input type="hidden" name="schedule_id" value="<?= $scheduleIdForQr ?>"><button class="button secondary" type="submit">Revoke QR code</button></form><?php endif; ?>
                    </div>
                    <?php if ($hasCurrentCheckinQr): ?>
                      <?php if ($checkinQrError): ?><p class="checkin-qr-error">This check-in link is too long or invalid for QR encoding. Review the configured site and check-in base URLs.</p><?php if ($checkinLink !== ''): ?><a href="<?= activityDetailEscape($checkinLink) ?>">Open check-in link</a><?php endif; ?>
                      <?php else: ?><div class="checkin-qr-preview"><div><?= $checkinQrMarkup ?></div><span>Scan to open the public check-in form</span><a href="<?= activityDetailEscape($checkinLink) ?>">Open check-in link</a></div><?php endif; ?>
                    <?php elseif ($storedCheckinHash !== ''): ?>
                      <p>The current QR code was generated in another or expired session. Regenerate it here to display a new code; this revokes the previous one.</p>
                    <?php endif; ?>
                  <?php endif; ?>
                </section>
              <?php endif; ?>
              <?php if ($canManage && $scheduleActive): ?>
                <?php
                  $dateParticipantCount = (int) ($scheduleRoster['total_count'] ?? 0);
                  $dateAttendedCount = (int) ($scheduleRoster['attended_count'] ?? 0);
                  $dateParticipantFormId = 'date-participant-form-' . (int) $scheduleRow['schedule_id'];
                  $dateAttendanceFormId = 'date-attendance-form-' . (int) $scheduleRow['schedule_id'];
                  $rosterPage = (int) ($scheduleRoster['page'] ?? 1);
                  $rosterPageCount = (int) ($scheduleRoster['page_count'] ?? 1);
                  $rosterFilteredCount = (int) ($scheduleRoster['filtered_count'] ?? 0);
                  $rosterFilters = $scheduleRoster['filters'] ?? ['search' => '', 'role' => '', 'affiliation' => '', 'rsvp' => '', 'attendance' => ''];
                  $invitedRosterRows = array_values(array_filter($scheduleRosterParticipants, static fn (array $participant): bool => (int) ($participant['is_invited'] ?? 0) === 1));
                  $otherRosterRows = array_values(array_filter($scheduleRosterParticipants, static fn (array $participant): bool => (int) ($participant['is_invited'] ?? 0) !== 1));
                  $rosterQuery = [
                      'id' => (int) $activityId,
                      'module_page' => $modulePage,
                      'roster_page' => [$scheduleIdForRoster => $rosterPage],
                      'roster_search' => [$scheduleIdForRoster => $rosterFilters['search']],
                      'roster_role' => [$scheduleIdForRoster => $rosterFilters['role']],
                      'roster_affiliation' => [$scheduleIdForRoster => $rosterFilters['affiliation']],
                      'roster_rsvp' => [$scheduleIdForRoster => $rosterFilters['rsvp']],
                      'roster_attendance' => [$scheduleIdForRoster => $rosterFilters['attendance']],
                  ];
                  $rosterPageUrl = static function (int $targetPage) use ($rosterQuery, $scheduleIdForRoster): string {
                      $query = $rosterQuery;
                      $query['roster_page'][$scheduleIdForRoster] = $targetPage;
                      return SITE_URL . 'pages/shared/activity-detail.php?' . http_build_query($query);
                  };
                  $rosterStart = $rosterFilteredCount > 0 ? (($rosterPage - 1) * 50) + 1 : 0;
                  $rosterEnd = min($rosterPage * 50, $rosterFilteredCount);
                  $invitedAudienceId = 'invited-audience-' . $scheduleIdForRoster;
                ?>
                <section class="date-participant-actions" data-date-participant-panel>
                  <details class="date-roster-disclosure">
                  <summary class="date-management-head" style="display:flex;list-style:none;cursor:pointer">
                    <div>
                      <p class="date-management-kicker">Roster management</p>
                      <h4>Manage participants</h4>
                      <p>Add an RMS account or a guest, then mark who attended.</p>
                    </div>
                    <span class="date-attendance-summary" data-attendance-summary>
                      <strong data-attendance-count data-attendance-base="<?= $dateAttendedCount ?>" data-attendance-total="<?= $dateParticipantCount ?>"><?= $dateAttendedCount ?> of <?= $dateParticipantCount ?></strong>
                      <span>marked attended</span>
                    </span>
                  </summary>

                  <form id="<?= activityDetailEscape($dateParticipantFormId) ?>" class="date-participant-form" method="post" data-participant-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_schedule_participant">
                    <input type="hidden" name="schedule_id" value="<?= (int) $scheduleRow['schedule_id'] ?>">
                    <div class="field">
                      <label for="date-name-<?= (int) $scheduleRow['schedule_id'] ?>">Participant name</label>
                      <input id="date-name-<?= (int) $scheduleRow['schedule_id'] ?>" name="participant_name" maxlength="255" placeholder="Guest full name" autocomplete="name" data-participant-name-input>
                    </div>
                    <div class="field">
                      <label for="date-user-<?= (int) $scheduleRow['schedule_id'] ?>">Link RMS account <span>(optional)</span></label>
                      <select id="date-user-<?= (int) $scheduleRow['schedule_id'] ?>" name="user_id" data-participant-user-select>
                        <option value="">Guest / no linked account</option>
                        <?php foreach ($activeUsers as $activeUser): $activeUserName = trim((string) $activeUser['first_name'] . ' ' . (string) $activeUser['last_name']); ?>
                          <option value="<?= (int) $activeUser['user_id'] ?>" data-account-name="<?= activityDetailEscape($activeUserName) ?>" data-account-affiliation="<?= activityDetailEscape((string) $activeUser['role'] === 'research_staff' ? 'staff' : (string) $activeUser['role']) ?>" data-account-program-department="<?= activityDetailEscape((string) ((string) $activeUser['role'] === 'student' ? ($activeUser['program'] ?? '') : ($activeUser['department'] ?? ''))) ?>"><?= activityDetailEscape($activeUserName . ' · ' . ucfirst((string) $activeUser['role'])) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php if ($activityCheckinDetailsAvailable): ?>
                    <div class="field">
                      <label for="date-affiliation-<?= (int) $scheduleRow['schedule_id'] ?>">Affiliation</label>
                      <select id="date-affiliation-<?= (int) $scheduleRow['schedule_id'] ?>" name="attendee_affiliation" required data-participant-affiliation>
                        <option value="student">Student</option><option value="faculty">Faculty</option><option value="staff">Staff</option><option value="guest">Guest</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="date-program-department-<?= (int) $scheduleRow['schedule_id'] ?>">Program/Department</label>
                      <input id="date-program-department-<?= (int) $scheduleRow['schedule_id'] ?>" name="attendee_program_department" type="text" maxlength="150" placeholder="Program or department" autocomplete="organization" data-participant-program-department>
                    </div>
                    <?php endif; ?>
                    <div class="field">
                      <label for="date-role-<?= (int) $scheduleRow['schedule_id'] ?>">Role</label>
                      <select id="date-role-<?= (int) $scheduleRow['schedule_id'] ?>" name="participant_role">
                        <option value="attendee">Attendee</option>
                        <option value="speaker">Speaker</option>
                        <option value="organizer">Organizer</option>
                      </select>
                    </div>
                    <button class="button" type="submit">Add participant</button>
                    <p class="date-participant-hint" data-participant-name-hint>Choose an RMS account to use its saved name, or leave it unlinked to add a guest.</p>
                    <p class="date-duplicate-name-note" data-duplicate-name-note role="status" aria-live="polite" hidden></p>
                  </form>

                  <?php
                    foreach (array_merge($invitedRosterRows, $otherRosterRows) as $scheduleParticipant) {
                        if ((int) ($scheduleParticipant['is_invited'] ?? 0) !== 1 || (int) ($scheduleParticipant['attended'] ?? 0) === 1) {
                            continue;
                        }
                        $cancelInvitationFormId = 'cancel-date-invitation-' . (int) $scheduleParticipant['participant_id'];
                  ?>
                    <form id="<?= activityDetailEscape($cancelInvitationFormId) ?>" method="post" hidden onsubmit="return confirm('Cancel this participant invitation for this date?');">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="remove_schedule_participant">
                      <input type="hidden" name="schedule_participant_id" value="<?= (int) $scheduleParticipant['participant_id'] ?>">
                    </form>
                  <?php } ?>

                  <?php if (!empty($scheduleRoster['error'])): ?>
                    <div class="date-attendance-empty"><strong>Roster unavailable</strong><span>Refresh the page or contact an activity administrator.</span></div>
                  <?php elseif ($dateParticipantCount === 0): ?>
                    <div class="date-attendance-empty"><strong>No participants on this date yet</strong><span>Add an RMS account or guest above to start the attendance roster.</span></div>
                  <?php else: ?>
                    <form class="roster-filter-form" method="get" action="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-detail.php') ?>">
                      <input type="hidden" name="id" value="<?= (int) $activityId ?>">
                      <?php if ($modulePage !== ''): ?><input type="hidden" name="module_page" value="<?= activityDetailEscape($modulePage) ?>"><?php endif; ?>
                      <div class="field"><label for="roster-search-<?= $scheduleIdForRoster ?>">Search roster</label><input id="roster-search-<?= $scheduleIdForRoster ?>" name="roster_search[<?= $scheduleIdForRoster ?>]" type="search" maxlength="100" value="<?= activityDetailEscape($rosterFilters['search']) ?>" placeholder="Name or program" autocomplete="off"></div>
                      <div class="field"><label for="roster-role-<?= $scheduleIdForRoster ?>">Role</label><select id="roster-role-<?= $scheduleIdForRoster ?>" name="roster_role[<?= $scheduleIdForRoster ?>]"><option value="">All roles</option><option value="attendee" <?= $rosterFilters['role'] === 'attendee' ? 'selected' : '' ?>>Attendee</option><option value="speaker" <?= $rosterFilters['role'] === 'speaker' ? 'selected' : '' ?>>Speaker</option><option value="organizer" <?= $rosterFilters['role'] === 'organizer' ? 'selected' : '' ?>>Organizer</option></select></div>
                      <?php if ($activityCheckinDetailsAvailable): ?><div class="field"><label for="roster-affiliation-<?= $scheduleIdForRoster ?>">Affiliation</label><select id="roster-affiliation-<?= $scheduleIdForRoster ?>" name="roster_affiliation[<?= $scheduleIdForRoster ?>]"><option value="">All affiliations</option><option value="student" <?= $rosterFilters['affiliation'] === 'student' ? 'selected' : '' ?>>Student</option><option value="faculty" <?= $rosterFilters['affiliation'] === 'faculty' ? 'selected' : '' ?>>Faculty</option><option value="staff" <?= $rosterFilters['affiliation'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="guest" <?= $rosterFilters['affiliation'] === 'guest' ? 'selected' : '' ?>>Guest</option></select></div><?php endif; ?>
                      <?php if ($invitationResponseSchemaAvailable): ?><div class="field"><label for="roster-rsvp-<?= $scheduleIdForRoster ?>">RSVP</label><select id="roster-rsvp-<?= $scheduleIdForRoster ?>" name="roster_rsvp[<?= $scheduleIdForRoster ?>]"><option value="">All RSVP states</option><option value="pending" <?= $rosterFilters['rsvp'] === 'pending' ? 'selected' : '' ?>>No response</option><option value="accepted" <?= $rosterFilters['rsvp'] === 'accepted' ? 'selected' : '' ?>>Accepted</option><option value="declined" <?= $rosterFilters['rsvp'] === 'declined' ? 'selected' : '' ?>>Declined</option></select></div><?php endif; ?>
                      <div class="field"><label for="roster-attendance-<?= $scheduleIdForRoster ?>">Attendance</label><select id="roster-attendance-<?= $scheduleIdForRoster ?>" name="roster_attendance[<?= $scheduleIdForRoster ?>]"><option value="">All attendance</option><option value="attended" <?= $rosterFilters['attendance'] === 'attended' ? 'selected' : '' ?>>Attended</option><option value="not_attended" <?= $rosterFilters['attendance'] === 'not_attended' ? 'selected' : '' ?>>Not marked</option></select></div>
                      <button class="button secondary" type="submit">Apply filters</button>
                    </form>

                    <p class="roster-page-summary" aria-live="polite">Showing <?= $rosterStart ?>–<?= $rosterEnd ?> of <?= $rosterFilteredCount ?> matching participants · <?= $dateAttendedCount ?> of <?= $dateParticipantCount ?> marked attended overall</p>
                    <button class="button secondary invited-audience-toggle" type="button" aria-expanded="true" aria-controls="<?= activityDetailEscape($invitedAudienceId) ?>" data-invited-audience-toggle hidden><span data-invited-toggle-label>Hide invited audience</span><span><?= (int) ($scheduleRoster['invited_count'] ?? 0) ?></span></button>
                    <?php if ($canRecordActivityAttendance): ?>
                      <form id="<?= activityDetailEscape($dateAttendanceFormId) ?>" class="date-attendance-form" method="post" data-date-attendance-form data-roster-state-key="rms-attendance-<?= (int) $activityId ?>-<?= $scheduleIdForRoster ?>" data-attendance-saved="<?= (int) ($attendanceSavedScheduleId ?? 0) === $scheduleIdForRoster ? 'true' : 'false' ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_schedule_attendance">
                        <input type="hidden" name="schedule_id" value="<?= $scheduleIdForRoster ?>">
                        <input type="hidden" name="schedule_attendance_changes" value="" data-attendance-changes>
                        <?php foreach ($scheduleRosterParticipants as $scheduleParticipant): ?><input type="hidden" name="schedule_participant_ids[]" value="<?= (int) $scheduleParticipant['participant_id'] ?>"><?php endforeach; ?>
                    <?php endif; ?>
                    <div class="roster-table-wrap">
                      <table class="roster-table">
                        <thead><tr><th scope="col">Participant</th><th scope="col">Affiliation</th><th scope="col">Program/Department</th><th scope="col">Role</th><th scope="col">RSVP</th><th scope="col">Attendance</th><th scope="col">Actions</th></tr></thead>
                        <tbody id="<?= activityDetailEscape($invitedAudienceId) ?>" data-invited-audience-rows>
                          <?php if ($invitedRosterRows): foreach ($invitedRosterRows as $scheduleParticipant):
                            $scheduleParticipantName = trim((string) ($scheduleParticipant['participant_name'] ?? '')) ?: 'Unnamed participant';
                            $scheduleParticipantId = (int) $scheduleParticipant['participant_id'];
                            $scheduleParticipantRole = ucfirst((string) ($scheduleParticipant['role'] ?? 'attendee') ?: 'Attendee');
                            $scheduleParticipantRsvp = match ((string) ($scheduleParticipant['invitation_response'] ?? 'pending')) { 'accepted' => 'Accepted', 'declined' => 'Declined', default => 'No response' };
                          ?>
                            <tr data-attendance-row data-attendance-name="<?= activityDetailEscape($scheduleParticipantName) ?>" data-user-id="<?= activityDetailEscape((string) ($scheduleParticipant['user_id'] ?? '')) ?>">
                              <td data-label="Participant"><span><?= activityDetailEscape($scheduleParticipantName) ?></span></td>
                              <td data-label="Affiliation"><span><?= activityDetailEscape(ucfirst((string) ($scheduleParticipant['attendee_affiliation'] ?? '')) ?: '—') ?></span></td>
                              <td data-label="Program/Department"><span><?= activityDetailEscape((string) ($scheduleParticipant['attendee_program_department'] ?? '') ?: '—') ?></span></td>
                              <td data-label="Role"><span><?= activityDetailEscape($scheduleParticipantRole) ?></span></td>
                              <td data-label="RSVP"><span><?= activityDetailEscape($scheduleParticipantRsvp) ?></span></td>
                              <td data-label="Attendance"><?php if ($canRecordActivityAttendance): ?><label class="roster-attendance-control" for="schedule-attended-<?= $scheduleParticipantId ?>"><input id="schedule-attended-<?= $scheduleParticipantId ?>" name="schedule_attended[<?= $scheduleParticipantId ?>]" type="checkbox" value="1" aria-label="Mark <?= activityDetailEscape($scheduleParticipantName) ?> as attended" data-attendance-checkbox data-initial-attended="<?= (int) $scheduleParticipant['attended'] ?>" <?= (int) $scheduleParticipant['attended'] === 1 ? 'checked' : '' ?>></label><?php else: ?><span class="<?= (int) $scheduleParticipant['attended'] === 1 ? 'attendance-yes' : 'attendance-no' ?>"><?= (int) $scheduleParticipant['attended'] === 1 ? 'Attended' : 'Not marked' ?></span><?php endif; ?></td>
                              <td data-label="Actions"><?php if ((int) ($scheduleParticipant['attended'] ?? 0) !== 1): ?><?php $cancelInvitationFormId = 'cancel-date-invitation-' . $scheduleParticipantId; ?><button class="button secondary date-cancel-invitation" type="submit" form="<?= activityDetailEscape($cancelInvitationFormId) ?>">Cancel invitation</button><?php else: ?><span>—</span><?php endif; ?></td>
                            </tr>
                          <?php endforeach; else: ?><tr class="roster-empty-row"><td colspan="7">No invited participants match this page and filter.</td></tr><?php endif; ?>
                        </tbody>
                        <tbody data-other-roster-rows>
                          <tr class="roster-group-heading"><th colspan="7" scope="rowgroup">Guests and other participants <span><?= (int) ($scheduleRoster['other_count'] ?? 0) ?></span></th></tr>
                          <?php if ($otherRosterRows): foreach ($otherRosterRows as $scheduleParticipant):
                            $scheduleParticipantName = trim((string) ($scheduleParticipant['participant_name'] ?? '')) ?: 'Unnamed participant';
                            $scheduleParticipantId = (int) $scheduleParticipant['participant_id'];
                            $scheduleParticipantRole = ucfirst((string) ($scheduleParticipant['role'] ?? 'attendee') ?: 'Attendee');
                          ?>
                            <tr data-attendance-row data-attendance-name="<?= activityDetailEscape($scheduleParticipantName) ?>" data-user-id="<?= activityDetailEscape((string) ($scheduleParticipant['user_id'] ?? '')) ?>">
                              <td data-label="Participant"><span><?= activityDetailEscape($scheduleParticipantName) ?></span></td>
                              <td data-label="Affiliation"><span><?= activityDetailEscape(ucfirst((string) ($scheduleParticipant['attendee_affiliation'] ?? '')) ?: '—') ?></span></td>
                              <td data-label="Program/Department"><span><?= activityDetailEscape((string) ($scheduleParticipant['attendee_program_department'] ?? '') ?: '—') ?></span></td>
                              <td data-label="Role"><span><?= activityDetailEscape($scheduleParticipantRole) ?></span></td>
                              <td data-label="RSVP"><span>—</span></td>
                              <td data-label="Attendance"><?php if ($canRecordActivityAttendance): ?><label class="roster-attendance-control" for="schedule-attended-<?= $scheduleParticipantId ?>"><input id="schedule-attended-<?= $scheduleParticipantId ?>" name="schedule_attended[<?= $scheduleParticipantId ?>]" type="checkbox" value="1" aria-label="Mark <?= activityDetailEscape($scheduleParticipantName) ?> as attended" data-attendance-checkbox data-initial-attended="<?= (int) $scheduleParticipant['attended'] ?>" <?= (int) $scheduleParticipant['attended'] === 1 ? 'checked' : '' ?>></label><?php else: ?><span class="<?= (int) $scheduleParticipant['attended'] === 1 ? 'attendance-yes' : 'attendance-no' ?>"><?= (int) $scheduleParticipant['attended'] === 1 ? 'Attended' : 'Not marked' ?></span><?php endif; ?></td>
                              <td data-label="Actions"><span>—</span></td>
                            </tr>
                          <?php endforeach; else: ?><tr class="roster-empty-row"><td colspan="7">No guests or other participants match this page and filter.</td></tr><?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php if ($rosterPageCount > 1): ?><nav class="roster-pagination" aria-label="Roster pages"><span>Page <?= $rosterPage ?> of <?= $rosterPageCount ?></span><div><?php if ($rosterPage > 1): ?><a class="button secondary" href="<?= activityDetailEscape($rosterPageUrl($rosterPage - 1)) ?>" rel="prev">Previous</a><?php endif; ?><?php if ($rosterPage < $rosterPageCount): ?><a class="button secondary" href="<?= activityDetailEscape($rosterPageUrl($rosterPage + 1)) ?>" rel="next">Next</a><?php endif; ?></div></nav><?php endif; ?>
                    <?php if ($canRecordActivityAttendance): ?><div class="attendance-submit"><button class="button" type="submit">Save date attendance</button></div></form><?php endif; ?>
                  <?php endif; ?>
                  </details>
                </section>
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
      <section class="panel" aria-labelledby="budget-heading"><div class="panel-head"><div><h2 id="budget-heading">Budget and participation details</h2><p>Organizational spending and participant charges are recorded separately.</p></div><?php if ($canManage): ?><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . (int) $activityId . '&mode=budget&module_page=' . rawurlencode($modulePage) . '#budget-section') ?>">Edit budget</a><?php endif; ?></div>
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

<script>
document.querySelectorAll('[data-dismiss-activity-save-toast]').forEach((button) => {
  button.addEventListener('click', () => button.closest('[data-activity-save-toast]')?.remove());
});

document.querySelectorAll('.checkin-qr-tools').forEach((section) => {
  const controls = section.querySelector('[data-checkin-fullscreen-actions]');
  const enterButton = section.querySelector('[data-enter-checkin-fullscreen]');
  const exitButton = section.querySelector('[data-exit-checkin-fullscreen]');
  const status = section.querySelector('[data-checkin-fullscreen-status]');
  if (!controls || !enterButton || !exitButton || typeof section.requestFullscreen !== 'function' || typeof document.exitFullscreen !== 'function') return;

  controls.hidden = false;
  const updateButtons = () => {
    const isFullscreen = document.fullscreenElement === section;
    enterButton.hidden = isFullscreen;
    exitButton.hidden = !isFullscreen;
  };

  enterButton.addEventListener('click', async () => {
    if (status) status.hidden = true;
    try {
      await section.requestFullscreen();
    } catch (error) {
      if (status) {
        status.textContent = 'Full screen could not be opened. Check your browser settings and try again.';
        status.hidden = false;
      }
    }
  });
  exitButton.addEventListener('click', async () => {
    try {
      await document.exitFullscreen();
    } catch (error) {
      if (status) {
        status.textContent = 'Full screen could not be closed. Press Escape to return to the page.';
        status.hidden = false;
      }
    }
  });
  document.addEventListener('fullscreenchange', updateButtons);
  updateButtons();
});

const deleteActivityForm = document.querySelector('[data-delete-activity-form]');
const deleteActivityDialog = document.querySelector('[data-delete-activity-dialog]');
if (deleteActivityForm && deleteActivityDialog && typeof deleteActivityDialog.showModal === 'function') {
  deleteActivityForm.dataset.nativeConfirm = 'false';
  deleteActivityForm.addEventListener('submit', (event) => {
    if (deleteActivityForm.dataset.confirmed === 'true') {
      deleteActivityForm.dataset.confirmed = 'false';
      return;
    }
    event.preventDefault();
    deleteActivityDialog.showModal();
  });
  deleteActivityDialog.querySelector('[data-confirm-delete-activity]')?.addEventListener('click', () => {
    deleteActivityDialog.close();
    deleteActivityForm.dataset.confirmed = 'true';
    deleteActivityForm.requestSubmit();
  });
  deleteActivityDialog.querySelector('[data-cancel-delete-activity]')?.addEventListener('click', () => deleteActivityDialog.close());
  deleteActivityDialog.addEventListener('click', (event) => {
    if (event.target === deleteActivityDialog) deleteActivityDialog.close();
  });
}

const revokeCheckinDialog = document.querySelector('[data-revoke-checkin-dialog]');
const confirmRevokeCheckinButton = revokeCheckinDialog?.querySelector('[data-confirm-revoke-checkin]');
const cancelRevokeCheckinButton = revokeCheckinDialog?.querySelector('[data-cancel-revoke-checkin]');
let pendingRevokeCheckinForm = null;
if (revokeCheckinDialog && typeof revokeCheckinDialog.showModal === 'function') {
  document.querySelectorAll('[data-revoke-checkin-form]').forEach((form) => {
    form.dataset.nativeConfirm = 'false';
    form.addEventListener('submit', (event) => {
      if (form.dataset.confirmed === 'true') {
        form.dataset.confirmed = 'false';
        return;
      }
      event.preventDefault();
      pendingRevokeCheckinForm = form;
      revokeCheckinDialog.showModal();
    });
  });
  confirmRevokeCheckinButton?.addEventListener('click', () => {
    if (!pendingRevokeCheckinForm) return;
    const form = pendingRevokeCheckinForm;
    pendingRevokeCheckinForm = null;
    revokeCheckinDialog.close();
    form.dataset.confirmed = 'true';
    form.requestSubmit();
  });
  cancelRevokeCheckinButton?.addEventListener('click', () => {
    pendingRevokeCheckinForm = null;
    revokeCheckinDialog.close();
  });
  revokeCheckinDialog.addEventListener('cancel', () => {
    pendingRevokeCheckinForm = null;
  });
  revokeCheckinDialog.addEventListener('click', (event) => {
    if (event.target === revokeCheckinDialog) {
      pendingRevokeCheckinForm = null;
      revokeCheckinDialog.close();
    }
  });
}

document.querySelectorAll('[data-open-contribution-dialog]').forEach((button) => {
  const dialog = document.getElementById(button.dataset.openContributionDialog || '');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  button.hidden = false;
  button.addEventListener('click', () => dialog.showModal());
  dialog.querySelectorAll('[data-close-contribution-dialog]').forEach((closeButton) => {
    closeButton.addEventListener('click', () => dialog.close());
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  if (dialog.dataset.autoOpen === 'true') dialog.showModal();
});

document.querySelectorAll('[data-participant-form]').forEach((form) => {
  const nameInput = form.querySelector('[data-participant-name-input]');
  const userSelect = form.querySelector('[data-participant-user-select]');
  const nameHint = form.querySelector('[data-participant-name-hint]');
  const duplicateNote = form.querySelector('[data-duplicate-name-note]');
  const roster = form.closest('[data-date-participant-panel]');
  const rows = Array.from(roster?.querySelectorAll('[data-attendance-row]') || []);
  if (!nameInput || !userSelect || !duplicateNote) return;

  const normalizeName = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
  const refreshDuplicateNote = () => {
    const selectedUserId = userSelect.value;
    const existingAccount = selectedUserId
      ? rows.find((row) => row.dataset.userId === selectedUserId)
      : null;
    if (existingAccount) {
      duplicateNote.textContent = 'This RMS account is already on this date. Adding it again updates its role instead of creating another row.';
      duplicateNote.hidden = false;
      return;
    }

    const currentName = normalizeName(nameInput.value);
    const duplicateName = currentName !== ''
      && rows.some((row) => normalizeName(row.dataset.attendanceName || '') === currentName);
    duplicateNote.textContent = duplicateName
      ? 'A participant with this name is already listed. Check the details; people with the same name can still be added.'
      : '';
    duplicateNote.hidden = !duplicateName;
  };

  userSelect.addEventListener('change', () => {
    const selectedOption = userSelect.selectedOptions[0];
    const accountName = selectedOption?.dataset.accountName || '';
    const affiliationSelect = form.querySelector('[data-participant-affiliation]');
    const programDepartmentInput = form.querySelector('[data-participant-program-department]');
    if (userSelect.value && accountName !== '') {
      nameInput.value = accountName;
      nameInput.readOnly = true;
      nameInput.dataset.linkedName = accountName;
      if (nameHint) nameHint.textContent = 'Using the selected RMS account’s saved name.';
      if (affiliationSelect) affiliationSelect.value = selectedOption.dataset.accountAffiliation || 'guest';
      if (programDepartmentInput) programDepartmentInput.value = selectedOption.dataset.accountProgramDepartment || '';
    } else {
      if (nameInput.readOnly && nameInput.value === (nameInput.dataset.linkedName || '')) {
        nameInput.value = '';
      }
      nameInput.readOnly = false;
      delete nameInput.dataset.linkedName;
      if (nameHint) nameHint.textContent = 'Enter a guest name, or choose an RMS account to use its saved name.';
      if (affiliationSelect) affiliationSelect.value = 'guest';
      if (programDepartmentInput) programDepartmentInput.value = '';
    }
    refreshDuplicateNote();
  });
  nameInput.addEventListener('input', refreshDuplicateNote);
  refreshDuplicateNote();
});

document.querySelectorAll('[data-date-attendance-form]').forEach((form) => {
  const countOutput = form.closest('[data-date-participant-panel]')?.querySelector('[data-attendance-count]');
  const changesInput = form.querySelector('[data-attendance-changes]');
  const storageKey = form.dataset.rosterStateKey || '';
  const changes = new Map();
  if (form.dataset.attendanceSaved === 'true' && storageKey !== '') {
    try { sessionStorage.removeItem(storageKey); } catch (error) {}
  } else if (storageKey !== '') {
    try {
      const saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
      Object.entries(saved).forEach(([id, state]) => {
        if (state && [0, 1].includes(Number(state.initial)) && [0, 1].includes(Number(state.value))) {
          changes.set(id, {initial: Number(state.initial), value: Number(state.value)});
        }
      });
    } catch (error) {}
  }
  const checkboxes = Array.from(form.querySelectorAll('[data-attendance-checkbox]'));
  checkboxes.forEach((checkbox) => {
    const id = checkbox.name.match(/\[(\d+)\]/)?.[1];
    const saved = id ? changes.get(id) : null;
    if (saved) checkbox.checked = saved.value === 1;
  });
  const persistChanges = () => {
    if (storageKey === '') return;
    try { sessionStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(changes))); } catch (error) {}
  };
  const updateAttendanceCount = () => {
    const baseCount = Number(countOutput?.dataset.attendanceBase || 0);
    const totalCount = Number(countOutput?.dataset.attendanceTotal || 0);
    const pendingDelta = Array.from(changes.values()).reduce((sum, state) => sum + state.value - state.initial, 0);
    if (countOutput) countOutput.textContent = `${baseCount + pendingDelta} of ${totalCount}`;
  };
  checkboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      const id = checkbox.name.match(/\[(\d+)\]/)?.[1];
      if (!id) return;
      const initial = Number(checkbox.dataset.initialAttended || 0);
      const value = checkbox.checked ? 1 : 0;
      if (value === initial) changes.delete(id);
      else changes.set(id, {initial, value});
      persistChanges();
      updateAttendanceCount();
    });
  });
  form.addEventListener('submit', () => {
    const payload = Object.fromEntries(Array.from(changes, ([id, state]) => [id, state.value]));
    if (changesInput) changesInput.value = JSON.stringify(payload);
    persistChanges();
  });
  updateAttendanceCount();
});

document.querySelectorAll('[data-invited-audience-toggle]').forEach((button) => {
  const rows = document.getElementById(button.getAttribute('aria-controls') || '');
  const label = button.querySelector('[data-invited-toggle-label]');
  if (!rows || !label) return;
  button.hidden = false;
  button.addEventListener('click', () => {
    const expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    rows.hidden = expanded;
    label.textContent = expanded ? 'Show invited audience' : 'Hide invited audience';
  });
});

document.querySelectorAll('form[id^="cancel-date-invitation-"]').forEach((cancelForm) => {
  cancelForm.addEventListener('submit', () => {
    const panel = cancelForm.closest('[data-date-participant-panel]');
    const attendanceForm = panel?.querySelector('[data-date-attendance-form]');
    const storageKey = attendanceForm?.dataset.rosterStateKey || '';
    const participantId = cancelForm.querySelector('[name="schedule_participant_id"]')?.value || '';
    if (storageKey === '' || participantId === '') return;
    try {
      const pending = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
      delete pending[participantId];
      sessionStorage.setItem(storageKey, JSON.stringify(pending));
    } catch (error) {}
  });
});
</script>
<script src="<?= activityDetailEscape(SITE_URL . 'js/activity-status-clock.js') ?>" defer></script>
<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
