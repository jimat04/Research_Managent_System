<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity-schedule.php';
require_once __DIR__ . '/../../includes/activity-budget.php';
require_once __DIR__ . '/../../includes/activity-document.php';
require_once __DIR__ . '/../../includes/file-uploader.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireLogin();
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}

function activityFormEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activityFormNormalizeType(string $type): string
{
    return match (strtolower($type)) {
        'seminar', 'forum', 'workshop', 'seminar_workshop' => 'seminar_workshop',
        'conference', 'congress', 'conference_congress' => 'conference_congress',
        'training' => 'training',
        'colloquium' => 'colloquium',
        default => strtolower($type),
    };
}

function activityFormRecipientRoles(mixed $roles): array
{
    if (!is_array($roles)) {
        return [];
    }
    return array_values(array_unique(array_filter(
        $roles,
        static fn ($role): bool => is_string($role)
            && in_array($role, ['student', 'faculty', 'research_staff'], true)
    )));
}

function activityFormRecipientIds(mixed $ids): array
{
    if (!is_array($ids)) {
        return [];
    }
    $validIds = [];
    foreach ($ids as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $userId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId) {
            $validIds[(int) $userId] = (int) $userId;
        }
    }
    return array_values($validIds);
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
$capacityModulePages = rmsPermissionModules()['research_capacity_building']['pages'];
$modulePageInput = trim((string) ($_POST['module_page'] ?? $_GET['module_page'] ?? ''));
$modulePage = array_key_exists($modulePageInput, $capacityModulePages) ? $modulePageInput : '';
$requestedType = activityFormNormalizeType(trim((string) ($_GET['type'] ?? '')));
$canManageActivity = static function (string $activityType, string $permissionPage, bool $isEdit) use ($role, $userId): bool {
    if ($role === 'admin') {
        return true;
    }
    if (!in_array($activityType, ['seminar_workshop', 'training', 'colloquium', 'conference_congress', 'other'], true)) {
        return false;
    }
    $addPages = ['add_activity', 'add_upcoming_activity', 'add_conducted_activity'];
    $managePages = ['view_activity', 'upcoming_activities', 'ongoing_activities', 'conducted_activities'];
    if ($isEdit && in_array($permissionPage, $managePages, true)) {
        return hasModulePermission($userId, 'research_capacity_building', 'manage', $permissionPage);
    }
    if (!$isEdit && in_array($permissionPage, $addPages, true)) {
        return hasModulePermission($userId, 'research_capacity_building', 'manage', $permissionPage);
    }
    return false;
};

$activitiesAvailable = rmsActivityScheduleTableExists($conn, 'research_activities');
$activityDocumentsAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'document_upload_id');
$activitySourceLinksAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'source_project_id')
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'source_publication_id');
$customActivityTypeAvailable = $activitiesAvailable
    && rmsActivityScheduleColumnExists($conn, 'research_activities', 'custom_activity_type');
$canChooseArchivedResearchSource = $role === 'admin'
    || ($role === 'research_staff'
        && hasModulePermission($userId, 'research_implementation_outputs', 'view', 'completed_research'));
$canChooseArchivedPublicationSource = $role === 'admin'
    || ($role === 'research_staff'
        && (hasModulePermission($userId, 'research_implementation_outputs', 'view', 'research_outputs')
            || hasModulePermission($userId, 'publication_dissemination', 'view', 'publication_records')));
$participantsAvailable = rmsActivityScheduleTableExists($conn, 'activity_participants');
$scheduleTablesAvailable = rmsActivityScheduleTableExists($conn, 'activity_schedule_dates')
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_dates', 'audience_roles')
    && rmsActivityScheduleColumnExists($conn, 'activity_schedule_dates', 'audience_user_ids');
$budgetSchemaAvailable = rmsActivityBudgetSchemaAvailable($conn);

$typeOptions = $role === 'admin'
    || hasModulePermission($userId, 'research_capacity_building', 'manage', $modulePage)
        ? ['seminar_workshop' => 'Seminars / Workshops', 'training' => 'Trainings', 'colloquium' => 'Colloquia', 'conference_congress' => 'Conference / Congress', 'other' => 'Other']
        : [];
$editRequested = array_key_exists('id', $_GET);
$requestedScheduleMode = (string) ($_GET['mode'] ?? '') === 'schedule';
$requestedBudgetMode = (string) ($_GET['mode'] ?? '') === 'budget';
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($editRequested && !$activityId) {
    header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php');
    exit;
}
$isEdit = $activityId !== null;
$requestedInitialStatus = $_GET['status'] ?? null;
$hasRequestedInitialStatus = is_string($requestedInitialStatus) && in_array($requestedInitialStatus, ['upcoming', 'incoming', 'conducted'], true);
$initialStatus = match ($modulePage) {
    'add_upcoming_activity' => 'upcoming',
    'add_conducted_activity' => 'conducted',
    default => $hasRequestedInitialStatus
        ? ($requestedInitialStatus === 'incoming' ? 'upcoming' : $requestedInitialStatus)
        : 'upcoming',
};
$isConductedActivityCreation = !$isEdit && $initialStatus === 'conducted';
$values = [
    'activity_type' => isset($typeOptions[$requestedType]) ? $requestedType : (string) array_key_first($typeOptions),
    'custom_activity_type' => '',
    'title' => '', 'activity_date' => '', 'activity_time' => '',
    'venue' => '', 'speaker' => '', 'organizer' => '', 'description' => '',
    'source_project_id' => '', 'source_publication_id' => '', 'status' => $initialStatus,
];
$errors = [];
if (!$activitiesAvailable) {
    $errors[] = 'The research activities module is not installed.';
} elseif (!$activitySourceLinksAvailable) {
    $errors[] = 'Apply migration 071_activity_source_links.sql before editing or saving activities.';
} elseif (!$customActivityTypeAvailable) {
    $errors[] = 'Apply migration 076_custom_activity_type.sql before editing or saving activities.';
}
$eligibleUsers = [];
$availableRecipientRoles = [];
$recipientScopeOptions = [
    'all' => ['label' => 'All users', 'description' => 'Active students, faculty, and staff', 'roles' => ['student', 'faculty', 'research_staff']],
    'student' => ['label' => 'Students', 'description' => 'All active student accounts', 'roles' => ['student']],
    'faculty' => ['label' => 'Faculty', 'description' => 'All active faculty accounts', 'roles' => ['faculty']],
    'staff' => ['label' => 'Staff', 'description' => 'All active research staff accounts', 'roles' => ['research_staff']],
    'specific' => ['label' => 'Specific users', 'description' => 'Choose one or more people below', 'roles' => []],
    'custom' => ['label' => 'Custom audience', 'description' => 'Combine role groups with optional individual users', 'roles' => []],
];
$recipientRoleLabels = ['student' => 'Student', 'faculty' => 'Faculty', 'research_staff' => 'Staff'];
$activity = null;
$activityDocument = null;
$storedSchedule = [];
if ($isEdit && $activitiesAvailable) {
    $activityDocumentColumn = $activityDocumentsAvailable ? ', document_upload_id' : '';
    $activitySourceColumns = $activitySourceLinksAvailable ? ', source_project_id, source_publication_id' : '';
    $activityCustomTypeSelect = $customActivityTypeAvailable ? 'custom_activity_type' : 'NULL AS custom_activity_type';
    $activitySql = $scheduleTablesAvailable
        ? 'SELECT activity_type, ' . $activityCustomTypeSelect . ', title, activity_date, activity_time, is_multi_day, schedule_start_date, schedule_end_date, venue, speaker, organizer, description' . $activitySourceColumns . ', status' . $activityDocumentColumn . ' FROM research_activities WHERE activity_id = ? LIMIT 1'
        : 'SELECT activity_type, ' . $activityCustomTypeSelect . ', title, activity_date, activity_time, venue, speaker, organizer, description' . $activitySourceColumns . ', status' . $activityDocumentColumn . ' FROM research_activities WHERE activity_id = ? LIMIT 1';
    $stmt = $conn->prepare($activitySql);
    if (!$stmt) {
        $errors[] = 'Unable to load the activity.';
    } else {
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        $errors[] = 'Unable to load the activity.';
    } else {
    $activity = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$activity) {
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . '&module_page=' . rawurlencode($modulePage));
        exit;
    } else {
        foreach ($values as $key => $_value) {
            $values[$key] = (string) ($activity[$key] ?? '');
        }
        if ($values['activity_time'] !== '') {
            $values['activity_time'] = substr($values['activity_time'], 0, 5);
        }
    }
    if ($activity && $activityDocumentsAvailable && (int) ($activity['document_upload_id'] ?? 0) > 0) {
        $activityDocument = rmsActivityDocumentLoad($conn, (int) $activityId);
    }
    }
    }
    if ($activity && $scheduleTablesAvailable) {
        try {
            $storedSchedule = rmsActivityScheduleLoad($conn, (int) $activityId);
        } catch (Throwable $exception) {
            error_log('Activity schedule load failed: ' . $exception->getMessage());
            $errors[] = 'Unable to load the activity schedule.';
        }
    }
}

if ($isEdit && $activity && !$canManageActivity((string) $values['activity_type'], $modulePage, true)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
if (!$isEdit && !$canManageActivity((string) $values['activity_type'], $modulePage, false)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

try {
    if ($scheduleTablesAvailable && $participantsAvailable) {
        $eligibleUsers = rmsActivityScheduleEligibleUsers($conn);
        foreach ($eligibleUsers as $eligibleUser) {
            $availableRecipientRoles[(string) $eligibleUser['role']] = true;
        }
    }
} catch (Throwable $exception) {
    error_log('Eligible activity user load failed: ' . $exception->getMessage());
    $errors[] = 'Unable to load eligible activity participants.';
}
$activeUsers = array_values($eligibleUsers);
$sourceResearchProjects = [];
$sourceFacultyPublications = [];
if ($activitySourceLinksAvailable && ($canChooseArchivedResearchSource || $canChooseArchivedPublicationSource)) {
    try {
        if ($canChooseArchivedResearchSource) {
            $sourceStmt = $conn->prepare(
                "SELECT rp.project_id, rp.title
                   FROM research_projects rp
                   JOIN users owner ON owner.user_id = rp.created_by AND owner.role = 'student'
                  WHERE rp.status IN ('completed', 'archived') AND rp.deleted_at IS NULL
                  ORDER BY rp.updated_at DESC, rp.project_id DESC"
            );
            if (!$sourceStmt || !$sourceStmt->execute()) {
                throw new RuntimeException('Unable to load archived student research.');
            }
            $sourceResearchProjects = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sourceStmt->close();
        }

        if ($canChooseArchivedPublicationSource) {
            $sourceStmt = $conn->prepare(
                "SELECT publication_id, research_title, researcher_name, publication_type, publication_date
                   FROM publications
                  WHERE researcher_category = 'faculty' AND deactivated_at IS NULL
                    AND (archival_status = 'archived' OR status = 'published')
                  ORDER BY publication_date DESC, publication_id DESC"
            );
            if (!$sourceStmt || !$sourceStmt->execute()) {
                throw new RuntimeException('Unable to load archived faculty publications.');
            }
            $sourceFacultyPublications = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sourceStmt->close();
        }
    } catch (Throwable $exception) {
        error_log('Activity source record options failed: ' . $exception->getMessage());
        $errors[] = 'Unable to load the optional archived research and publication choices.';
    }
}
$budgetData = null;
$budgetValues = [
    'activity_format' => 'on_site',
    'funding_source' => 'organizational',
    'budget_status' => $isConductedActivityCreation ? 'confirmed' : 'pending',
    'contribution_prompt' => 0,
];
$budgetExpenses = [];
$budgetNoticeValues = [
    'confirmed' => false,
    'message' => '',
    'roles' => [],
    'user_ids' => [],
];
$budgetNoticeConfirmationKey = bin2hex(random_bytes(32));
if ($isEdit && $budgetSchemaAvailable) {
    try {
        $budgetData = rmsActivityBudgetLoad($conn, (int) $activityId);
        if ($budgetData) {
            foreach ($budgetValues as $key => $_default) {
                $budgetValues[$key] = $budgetData[$key];
            }
            foreach ($budgetData['expenses'] as $expense) {
                $budgetExpenses[] = [
                    'expense_category' => $expense['expense_category'],
                    'schedule_date' => $expense['schedule_date'] ?? '',
                    'audience_scope' => $expense['audience_scope'],
                    'audience_user_id' => $expense['audience_user_id'] ?? '',
                    'charge_type' => $expense['charge_type'],
                    'organizational_estimated_amount' => $expense['organizational_estimated_amount'] ?? '',
                    'organizational_actual_amount' => $expense['organizational_actual_amount'] ?? '',
                    'participant_estimated_charge' => $expense['participant_estimated_charge'] ?? '',
                    'participant_actual_charge' => $expense['participant_actual_charge'] ?? '',
                    'description' => $expense['description'] ?? '',
                ];
            }
        }
    } catch (Throwable $exception) {
        error_log('Activity budget load failed: ' . $exception->getMessage());
        $errors[] = 'Unable to load the activity budget.';
    }
}
if (!$budgetSchemaAvailable) {
    $errors[] = 'Apply activity budget migration 036 before saving activity budgets.';
}
if (!$budgetExpenses) {
    $budgetExpenses[] = [
        'expense_category' => 'registration', 'schedule_date' => '', 'audience_scope' => 'all',
        'audience_user_id' => '', 'charge_type' => 'none',
        'organizational_estimated_amount' => '', 'organizational_actual_amount' => '',
        'participant_estimated_charge' => '', 'participant_actual_charge' => '', 'description' => '',
    ];
}

$scheduleWindowStart = ($activity['schedule_start_date'] ?? '') ?: ($values['activity_date'] !== '' ? $values['activity_date'] : date('Y-m-d'));
$scheduleWindowEnd = ($activity['schedule_end_date'] ?? '') ?: $scheduleWindowStart;
$multiDay = (bool) ($activity['is_multi_day'] ?? false);
$scheduleFormRows = [];
if ($storedSchedule) {
    $activeSchedule = array_filter($storedSchedule, static fn (array $row): bool => (string) $row['status'] === 'scheduled');
    if ($activeSchedule) {
        foreach ($activeSchedule as $date => $row) {
            $scheduleFormRows[$date] = [
                'start_time' => $row['start_time'] ? substr((string) $row['start_time'], 0, 5) : '',
                'end_time' => $row['end_time'] ? substr((string) $row['end_time'], 0, 5) : '',
                'venue_override' => (string) ($row['venue_override'] ?? ''),
                'speaker_override' => (string) ($row['speaker_override'] ?? ''),
                'recipient_scope' => (string) $row['audience_scope'],
                'recipient_roles' => $row['audience_roles'],
                'recipient_user_ids' => $row['manual_recipient_ids'],
            ];
        }
    }
}
if (!$scheduleFormRows && rmsActivityScheduleValidDate($scheduleWindowStart)) {
    $scheduleFormRows[$scheduleWindowStart] = [
        'start_time' => $values['activity_time'],
        'end_time' => '',
        'venue_override' => '',
        'speaker_override' => '',
        'recipient_scope' => 'all',
        'recipient_roles' => [],
        'recipient_user_ids' => [],
    ];
}
$dateChoices = [];
if (rmsActivityScheduleValidDate($scheduleWindowStart) && rmsActivityScheduleValidDate($scheduleWindowEnd) && $scheduleWindowEnd >= $scheduleWindowStart) {
    $windowLength = (int) (new DateTimeImmutable($scheduleWindowStart))->diff(new DateTimeImmutable($scheduleWindowEnd))->days + 1;
    if ($windowLength <= 366) {
        $cursor = new DateTimeImmutable($scheduleWindowStart);
        for ($day = 0; $day < $windowLength; $day++) {
            $dateChoices[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $refreshingWindow = isset($_POST['refresh_schedule_window']);
    $activityDocumentFile = $_FILES['activity_document'] ?? null;
    $activityDocumentSelected = is_array($activityDocumentFile)
        && (int) ($activityDocumentFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($refreshingWindow && $activityDocumentSelected) {
        $errors[] = 'Update the schedule choices first, then select the PDF before saving the activity.';
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    $postedModulePage = trim((string) ($_POST['module_page'] ?? $_GET['module_page'] ?? ''));
    $modulePage = array_key_exists($postedModulePage, $capacityModulePages) ? $postedModulePage : '';
    $postedId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $isEdit = $postedId !== null;
    $activityId = $postedId;
    if (array_key_exists('id', $_GET) && !$postedId) {
        $errors[] = 'The activity edit form is incomplete. Reopen the activity schedule and try again.';
    }
    foreach (['activity_type', 'custom_activity_type', 'title', 'activity_date', 'activity_time', 'venue', 'speaker', 'organizer', 'description'] as $field) {
        $postedValue = $_POST[$field] ?? '';
        if (!is_scalar($postedValue)) {
            $errors[] = 'Enter a valid value for ' . str_replace('_', ' ', $field) . '.';
            $values[$field] = '';
            continue;
        }
        $values[$field] = trim((string) $postedValue);
    }
    $values['activity_type'] = activityFormNormalizeType((string) $values['activity_type']);
    if (!$isEdit || (!$requestedScheduleMode && !$requestedBudgetMode)) {
        foreach (['source_project_id', 'source_publication_id'] as $sourceField) {
            $postedSourceId = $_POST[$sourceField] ?? '';
            if (!is_scalar($postedSourceId)) {
                $errors[] = 'Choose a valid optional source record.';
                $values[$sourceField] = '';
                continue;
            }
            $postedSourceId = trim((string) $postedSourceId);
            if ($postedSourceId !== '' && !filter_var($postedSourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                $errors[] = 'Choose a valid optional source record.';
                $postedSourceId = '';
            } elseif ($postedSourceId !== '') {
                $postedSourceId = (string) (int) $postedSourceId;
            }
            $values[$sourceField] = $postedSourceId;
        }
    }
    if ($values['activity_type'] !== 'colloquium') {
        $values['source_project_id'] = '';
    }
    if ($values['activity_type'] !== 'conference_congress') {
        $values['source_publication_id'] = '';
    }
    if ($isEdit && (!$activity || (int) ($_GET['id'] ?? 0) !== $activityId)) {
        $errors[] = 'The activity you tried to edit was not found.';
    }

    if (!in_array($values['activity_type'], ['seminar_workshop', 'training', 'colloquium', 'conference_congress', 'other'], true)) {
        $errors[] = 'Choose a valid activity type.';
    }
    if ($values['activity_type'] === 'other') {
        if ($values['custom_activity_type'] === '' || strlen($values['custom_activity_type']) > 100) {
            $errors[] = 'Enter a custom activity type no longer than 100 characters.';
        }
    } else {
        $values['custom_activity_type'] = '';
    }
    if (!$canManageActivity((string) $values['activity_type'], $modulePage, $isEdit)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if ($activityDocumentSelected && $role !== 'admin') {
        $errors[] = 'Only administrators may upload activity documents.';
    }
    if ($activityDocumentSelected && !$activityDocumentsAvailable) {
        $errors[] = 'Apply migration 048_activity_invitation_document.sql before uploading activity documents.';
    }
    if ($activityDocumentSelected && ($requestedScheduleMode || $requestedBudgetMode)) {
        $errors[] = 'Upload activity documents from the activity details form.';
    }
    if ($values['title'] === '' || strlen($values['title']) > 255) {
        $errors[] = 'Title is required and must not exceed 255 characters.';
    }
    foreach (['venue' => 255, 'speaker' => 255, 'organizer' => 255] as $field => $limit) {
        if (strlen($values[$field]) > $limit) {
            $errors[] = ucfirst($field) . " must not exceed {$limit} characters.";
        }
    }

    if (!$errors && $isEdit) {
        $values['status'] = (string) ($activity['status'] ?? $values['status']);
    }

    if (!$scheduleTablesAvailable || !$participantsAvailable) {
        $errors[] = 'Apply activity schedule migrations 034 and 035 before saving schedules and invitations.';
    } else {
        $scheduleValidation = rmsActivityScheduleValidatePost($_POST, $eligibleUsers);
        if (!$refreshingWindow) {
            $errors = array_merge($errors, $scheduleValidation['errors']);
        }
        $scheduleWindowStart = $scheduleValidation['window_start'];
        $scheduleWindowEnd = $scheduleValidation['window_end'];
        $multiDay = $scheduleValidation['multi_day'];
        $scheduleFormRows = [];
        foreach ($scheduleValidation['dates'] as $date => $row) {
            $row['start_time'] = $row['start_time'] ? substr((string) $row['start_time'], 0, 5) : '';
            $row['end_time'] = $row['end_time'] ? substr((string) $row['end_time'], 0, 5) : '';
            $row['recipient_user_ids'] = $row['manual_recipient_ids'] ?? [];
            $row['recipient_scope'] = $row['audience_scope'];
            $row['recipient_roles'] = $row['audience_roles'];
            $scheduleFormRows[$date] = $row;
        }
    }

    if ($budgetSchemaAvailable) {
        $validBudgetScheduleDates = array_fill_keys(array_keys($scheduleValidation['dates'] ?? []), true);
        $inviteeRecipientIds = [];
        foreach ($scheduleValidation['dates'] ?? [] as $scheduleDateRow) {
            foreach ($scheduleDateRow['recipients'] ?? [] as $inviteeId) {
                $inviteeRecipientIds[(int) $inviteeId] = (int) $inviteeId;
            }
        }
        foreach ($storedSchedule as $storedDate => $_storedRow) {
            $validBudgetScheduleDates[(string) $storedDate] = true;
        }
        $budgetValidation = rmsActivityBudgetValidatePost(
            $_POST,
            $eligibleUsers,
            $validBudgetScheduleDates,
            $isEdit ? (string) ($activity['status'] ?? $values['status']) : $initialStatus,
            array_values($inviteeRecipientIds)
        );
        if ($isConductedActivityCreation) {
            $budgetValidation['header']['budget_status'] = 'confirmed';
        }
        if (!$refreshingWindow) {
            $errors = array_merge($errors, $budgetValidation['errors']);
        }
        $budgetValues = $budgetValidation['header'];
        $budgetExpenses = $budgetValidation['expenses'] ?: [[
            'expense_category' => 'registration', 'schedule_date' => null, 'audience_scope' => 'all',
            'audience_user_id' => null, 'charge_type' => 'none',
            'organizational_estimated_amount' => null, 'organizational_actual_amount' => null,
            'participant_estimated_charge' => null, 'participant_actual_charge' => null, 'description' => null,
        ]];
        $budgetNoticeValues = $budgetValidation['notice'];
        $postedConfirmationKey = $_POST['budget_notice_confirmation_key'] ?? '';
        if (is_string($postedConfirmationKey) && preg_match('/^[a-f0-9]{64}$/D', $postedConfirmationKey)) {
            $budgetNoticeConfirmationKey = $postedConfirmationKey;
        } else {
            $errors[] = 'The budget notice confirmation expired. Reload the activity form and try again.';
        }
    }

    if (!$errors && !$refreshingWindow) {
        $venue = $values['venue'] !== '' ? $values['venue'] : null;
        $speaker = $values['speaker'] !== '' ? $values['speaker'] : null;
        $organizer = $values['organizer'] !== '' ? $values['organizer'] : null;
        $description = $values['description'] !== '' ? $values['description'] : null;
        $activityValues = [
            'activity_type' => $values['activity_type'],
            'custom_activity_type' => $values['custom_activity_type'] !== '' ? $values['custom_activity_type'] : null,
            'title' => $values['title'],
            'venue' => $venue,
            'speaker' => $speaker,
            'organizer' => $organizer,
            'description' => $description,
            'source_project_id' => $values['source_project_id'] !== '' ? (int) $values['source_project_id'] : null,
            'source_publication_id' => $values['source_publication_id'] !== '' ? (int) $values['source_publication_id'] : null,
        ];
        $activityWasSaved = false;
        try {
            $activityId = rmsActivityScheduleSave(
                $conn,
                $activityValues,
                $activityId,
                $scheduleValidation['dates'],
                $eligibleUsers,
                $userId,
                $initialStatus,
                $multiDay,
                $scheduleValidation['window_start'],
                $scheduleValidation['window_end'],
                $budgetValues,
                $budgetValidation['expenses']
            );
            $activityWasSaved = true;
            $noticeSummary = '';
            $documentUploadFailed = false;
            $documentUploadError = '';
            if ($budgetNoticeValues['confirmed']) {
                try {
                    $noticeResult = rmsActivityBudgetCreateNotice(
                        $conn,
                        $activityId,
                        $userId,
                        $budgetValues,
                        $budgetValidation['expenses'],
                        $eligibleUsers,
                        $budgetNoticeValues,
                        $budgetNoticeConfirmationKey
                    );
                    $noticeSummary = $noticeResult['failed'] > 0
                        ? ' Budget saved; ' . $noticeResult['delivered'] . ' notice(s) delivered and ' . $noticeResult['failed'] . ' failed. Review notice history to retry failures.'
                        : ' Budget saved and notice delivered to ' . $noticeResult['delivered'] . ' recipient(s).';
                } catch (Throwable $noticeException) {
                    error_log('Activity budget notice setup failed: ' . $noticeException->getMessage());
                    $noticeSummary = ' Budget saved, but the confirmed notice could not be prepared. Review the activity before sending again.';
                }
            }
            if ($activityDocumentSelected) {
                $uploadResult = handleRmsUpload([
                    'inputName' => 'activity_document',
                    'folderTarget' => 'activities',
                    'maxSize' => 20000,
                    'accept' => ['.pdf'],
                    'type' => 'other',
                ], $_FILES, $conn);
                if (empty($uploadResult['success'])) {
                    $documentUploadFailed = true;
                    $documentUploadError = (string) ($uploadResult['error'] ?? 'The PDF could not be uploaded.');
                } else {
                    $newUploadId = (int) ($uploadResult['upload_id'] ?? 0);
                    try {
                        $oldUploadId = rmsActivityDocumentAttach($conn, (int) $activityId, $newUploadId, $userId);
                        if ($oldUploadId !== null && !rmsActivityDocumentDeleteUpload($conn, $oldUploadId)) {
                            error_log('Replaced activity document upload could not be removed: ' . $oldUploadId);
                        }
                        $noticeSummary .= ' Activity document uploaded.';
                    } catch (Throwable $documentException) {
                        rmsActivityDocumentDeleteUpload($conn, $newUploadId);
                        error_log('Activity document attachment failed: ' . $documentException->getMessage());
                        $documentUploadFailed = true;
                        $documentUploadError = 'The PDF uploaded but could not be attached to the activity.';
                    }
                }
            }
            logActivity(($isEdit ? 'Updated' : 'Created') . " research activity #{$activityId}: {$values['title']}", 'research_activities');
            $savedAsConducted = ($isEdit ? (string) ($activity['status'] ?? '') : $initialStatus) === 'conducted';
            $_SESSION['activities_flash'] = [
                'type' => $documentUploadFailed ? 'error' : 'success',
                'popup' => !$isEdit && !$savedAsConducted && !$documentUploadFailed,
                'message' => $documentUploadFailed
                    ? 'The activity was saved, but its document was not attached: ' . $documentUploadError . ' Edit the activity to try again.'
                    : ($isEdit
                    ? ($savedAsConducted
                        ? 'Conducted activity details, actual attendees, schedule, and budget saved. No invitation was sent.' . $noticeSummary
                        : 'Activity details, schedule, and budget saved. Schedule changes were handled for affected invitees.' . $noticeSummary)
                    : ($savedAsConducted
                        ? 'Conducted activity recorded with its schedule, actual attendees, and budget. No invitation was sent.' . $noticeSummary
                        : 'Activity created with its schedule and budget. Selected users were registered and notified for each scheduled date.' . $noticeSummary)),
            ];
        } catch (Throwable $exception) {
            error_log('Research activity schedule save failed: ' . $exception->getMessage());
            if ($activityWasSaved) {
                $_SESSION['activities_flash'] = [
                    'type' => 'error',
                    'message' => 'The activity was saved, but a later schedule or budget operation failed. Review the activity before retrying.',
                ];
                header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . '&module_page=' . rawurlencode($modulePage));
                exit;
            }
            $errors[] = 'The activity, schedule, participant records, and budget could not be saved. Please review and try again.';
        }
        if (!$errors) {
            header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . '&module_page=' . rawurlencode($modulePage));
            exit;
        }
    }

    $rawScheduleDates = is_array($_POST['schedule_dates'] ?? null) ? $_POST['schedule_dates'] : [];
    $scheduleFormRows = [];
    if ($multiDay) {
        $singleRow = is_array($_POST['single_schedule'] ?? null) ? $_POST['single_schedule'] : [];
        foreach ($rawScheduleDates as $date => $row) {
            if (!is_array($row) || !isset($row['selected'])) {
                continue;
            }
            $scheduleRow = (string) $date === $scheduleWindowStart
                ? array_merge($singleRow, $row)
                : $row;
            $scheduleFormRows[(string) $date] = [
                'start_time' => (string) ($scheduleRow['start_time'] ?? ''),
                'end_time' => (string) ($scheduleRow['end_time'] ?? ''),
                'venue_override' => (string) ($scheduleRow['venue_override'] ?? ''),
                'speaker_override' => (string) ($scheduleRow['speaker_override'] ?? ''),
                'recipient_scope' => (string) ($scheduleRow['recipient_scope'] ?? 'all'),
                'recipient_roles' => activityFormRecipientRoles($scheduleRow['recipient_roles'] ?? []),
                'recipient_user_ids' => activityFormRecipientIds($scheduleRow['recipient_user_ids'] ?? []),
            ];
        }
    } else {
        $singleRow = is_array($_POST['single_schedule'] ?? null)
            ? $_POST['single_schedule']
            : (is_array($rawScheduleDates[$scheduleWindowStart] ?? null) ? $rawScheduleDates[$scheduleWindowStart] : []);
        if ($singleRow) {
            $scheduleFormRows[$scheduleWindowStart] = [
                'start_time' => (string) ($singleRow['start_time'] ?? ''),
                'end_time' => (string) ($singleRow['end_time'] ?? ''),
                'venue_override' => (string) ($singleRow['venue_override'] ?? ''),
                'speaker_override' => (string) ($singleRow['speaker_override'] ?? ''),
                'recipient_scope' => (string) ($singleRow['recipient_scope'] ?? 'all'),
                'recipient_roles' => activityFormRecipientRoles($singleRow['recipient_roles'] ?? []),
                'recipient_user_ids' => activityFormRecipientIds($singleRow['recipient_user_ids'] ?? []),
            ];
        }
    }
}

$dateChoices = [];
if (rmsActivityScheduleValidDate($scheduleWindowStart) && rmsActivityScheduleValidDate($scheduleWindowEnd) && $scheduleWindowEnd >= $scheduleWindowStart) {
    $windowLength = (int) (new DateTimeImmutable($scheduleWindowStart))->diff(new DateTimeImmutable($scheduleWindowEnd))->days + 1;
    if ($windowLength <= 366) {
        $cursor = new DateTimeImmutable($scheduleWindowStart);
        for ($day = 0; $day < $windowLength; $day++) {
            $dateChoices[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    }
}

$budgetScheduleChoices = $dateChoices;
foreach ($storedSchedule as $storedDate => $_storedRow) {
    $budgetScheduleChoices[] = (string) $storedDate;
}
$budgetScheduleChoices = array_values(array_unique($budgetScheduleChoices));
sort($budgetScheduleChoices, SORT_STRING);
$effectiveActivityStatus = $isEdit ? (string) ($values['status'] ?? $initialStatus) : $initialStatus;
$isUpcomingActivity = in_array($effectiveActivityStatus, ['upcoming', 'scheduled', 'ongoing'], true);

$shell = $role === 'admin' ? 'admin' : 'staff';
$activityTypeLabels = [
    'seminar_workshop' => 'Seminar / Workshop',
    'training' => 'Training',
    'colloquium' => 'Colloquium',
    'conference_congress' => 'Conference / Congress',
    'other' => $values['custom_activity_type'] !== '' ? $values['custom_activity_type'] : 'Other',
];
$hasSpecificActivityType = $isEdit || isset($typeOptions[$requestedType]);
$selectedActivityLabel = $hasSpecificActivityType
    ? ($activityTypeLabels[$values['activity_type']] ?? 'Activity')
    : 'Activity';
$scheduleDescription = $hasSpecificActivityType
    ? 'a ' . strtolower($selectedActivityLabel)
    : 'an activity';
$creationContextLabel = $initialStatus === 'conducted' ? 'Conducted Activity' : 'Upcoming Activity';
$pageTitle = $isEdit
    ? ($requestedScheduleMode ? 'Edit Schedule' : ($requestedBudgetMode ? 'Edit Budget' : 'Edit ' . $selectedActivityLabel))
    : 'Add ' . ($hasRequestedInitialStatus ? $creationContextLabel : $selectedActivityLabel);
$pageSubtitle = $isEdit
    ? ($requestedScheduleMode
        ? 'Manage selected session dates, per-date audiences, and schedule notices without replacing the activity record'
        : ($requestedBudgetMode
            ? 'Update budget and participation details for this activity'
            : 'Update the ' . strtolower($selectedActivityLabel) . ' details and schedule; status changes remain on the detail page'))
    : ($initialStatus === 'conducted' && $hasRequestedInitialStatus
        ? 'Record a completed activity, its actual attendees, schedule, and actual budget'
        : 'Schedule ' . $scheduleDescription . ' and set who should be notified');
$activityListUrl = $role === 'admin'
    ? SITE_URL . 'pages/shared/activities.php'
    : SITE_URL . 'pages/staff/staff-dashboard.php';
if (in_array($modulePage, ['upcoming_activities', 'ongoing_activities', 'conducted_activities'], true)) {
    $activityView = match ($modulePage) {
        'upcoming_activities' => 'upcoming',
        'ongoing_activities' => 'ongoing',
        default => 'conducted',
    };
    $activityListUrl = SITE_URL . 'pages/shared/activities.php?view=' . $activityView . '&module_page=' . rawurlencode($modulePage);
} elseif ($modulePage === 'view_activity') {
    $activityListUrl = SITE_URL . 'pages/shared/activities.php?view=overview&module_page=view_activity';
}
if ($shell === 'admin') renderAdminShell($user, 'activities.php', $pageTitle, $pageSubtitle);
else renderStaffShell($user, 'activities.php', $pageTitle, $pageSubtitle);
?>

<style>
.activity-form-page{max-width:980px;margin:0 auto;color:#172033}.form-heading{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.form-heading h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.form-heading p{max-width:620px;margin:8px 0 0;color:#64748b;line-height:1.55}.back-link{color:#0f766e;font-size:12px;font-weight:800;text-decoration:none}.form-card{overflow:hidden;border:1px solid #dfe7e5;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(30,65,60,.07)}.form-status{padding:13px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#526176;font-size:12px}.form-errors{margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#991b1b}.form-errors strong{display:block;margin-bottom:6px}.form-errors ul{margin:0;padding-left:19px}.activity-form{padding:26px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px 18px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.required{color:#b42318}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#172033;font:inherit;font-size:13px}.field textarea{min-height:140px;resize:vertical;line-height:1.55}.field input:focus,.field select:focus,.field textarea:focus,.button:focus-visible,.back-link:focus-visible{border-color:#0f766e;outline:3px solid rgba(15,118,110,.12);outline-offset:2px}.field small{display:block;margin-top:6px;color:#64748b;font-size:11px}.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #e8edf3}.button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;border:1px solid #0f766e;border-radius:9px;padding:9px 17px;background:#0f766e;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#115e59;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}
.activity-review-dialog{position:fixed;inset:0;margin:auto;width:min(680px,calc(100% - 32px));max-width:none;max-height:min(86vh,760px);padding:0;border:1px solid #e2e8f0;border-radius:18px;background:#fff;color:#172033;box-shadow:0 24px 80px rgba(15,23,42,.28)}.activity-review-dialog::backdrop{background:rgba(15,23,42,.52);backdrop-filter:blur(2px)}.activity-review-header{padding:24px 26px 18px;border-bottom:1px solid #edf0f4}.activity-review-eyebrow{margin:0 0 6px;color:#c45c00;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.activity-review-header h2{margin:0;font-size:22px;letter-spacing:-.025em}.activity-review-header p{margin:7px 0 0;color:#64748b;font-size:13px;line-height:1.55}.activity-review-content{display:grid;gap:14px;max-height:calc(86vh - 170px);overflow:auto;padding:20px 26px}.activity-review-section{padding:15px 16px;border:1px solid #e8edf2;border-radius:12px;background:#fbfcfd}.activity-review-section h3{margin:0 0 11px;color:#334155;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.activity-review-list{display:grid;grid-template-columns:minmax(120px,.75fr) minmax(0,1.5fr);gap:8px 16px;margin:0}.activity-review-list dt{color:#64748b;font-size:12px;font-weight:600}.activity-review-list dd{min-width:0;margin:0;color:#172033;font-size:13px;line-height:1.5;overflow-wrap:anywhere;white-space:pre-wrap}.activity-review-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 26px 22px;border-top:1px solid #edf0f4}.activity-review-footer .button{min-width:112px}.activity-review-footer .button.review-confirm{border-color:#f57c00;background:#f57c00}.activity-review-footer .button.review-confirm:hover{background:#ea580c}.activity-review-dialog :focus-visible{outline:3px solid rgba(245,124,0,.38);outline-offset:3px}
.activity-review-header{text-align:center}
@media(max-width:640px){.form-heading{display:block}.back-link{display:inline-block;margin-top:14px}.activity-form{padding:20px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions{flex-direction:column-reverse}.button{width:100%;box-sizing:border-box}.activity-review-dialog{width:calc(100% - 20px);max-height:calc(100vh - 20px);border-radius:14px}.activity-review-header{padding:20px 18px 15px}.activity-review-header h2{font-size:19px}.activity-review-content{max-height:calc(100vh - 175px);padding:16px 18px}.activity-review-section{padding:13px}.activity-review-list{grid-template-columns:1fr;gap:3px}.activity-review-list dd{margin-bottom:8px}.activity-review-list dd:last-child{margin-bottom:0}.activity-review-footer{flex-direction:column-reverse;padding:14px 18px 18px}.activity-review-footer .button{width:100%}}
.activity-form .budget-recipient-list.aligned-recipient-list{grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));align-items:start}.activity-form .budget-recipient-list.aligned-recipient-list label{display:grid;grid-template-columns:16px minmax(0,1fr);align-items:center;gap:9px;min-width:0;min-height:40px;box-sizing:border-box;padding:8px 10px;border:1px solid #e8edf2;border-radius:8px;background:#fff;cursor:pointer}.activity-form .budget-recipient-list.aligned-recipient-list label span{min-width:0;overflow-wrap:anywhere;line-height:1.4}.activity-form .budget-recipient-list.aligned-recipient-list input{width:16px;height:16px;margin:0;accent-color:#0f766e}.activity-form .budget-recipient-list.aligned-recipient-list input:focus-visible{outline:3px solid rgba(15,118,110,.2);outline-offset:2px}@media(max-width:720px){.activity-form .budget-recipient-list.aligned-recipient-list{grid-template-columns:1fr}}
.participant-picker{display:grid;gap:10px;padding:14px;border:1px solid #dbe3ea;border-radius:12px;background:#f8fafc}.participant-picker-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.participant-picker-head label{margin:0!important}.participant-picker-head button{border:0;background:none;color:#0f766e;font:inherit;font-size:11px;font-weight:800;text-decoration:underline;cursor:pointer}.recipient-scope{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}.scope-option{display:flex;align-items:flex-start;gap:9px;min-width:0;padding:12px;border:1px solid #dbe3ea;border-radius:10px;background:#fff;color:#334155;cursor:pointer}.scope-option:has(input:checked){border-color:#0f766e;background:#f0fdfa;box-shadow:0 0 0 2px rgba(15,118,110,.08)}.scope-option input{width:16px;height:16px;flex:0 0 16px;margin-top:1px;accent-color:#0f766e}.scope-option strong{display:block;font-size:12px}.scope-option small{display:block;margin-top:4px;color:#64748b;font-size:10px;font-weight:500;line-height:1.4}.participant-list{display:grid;gap:12px;max-height:260px;overflow:auto}.participant-group{display:grid;gap:6px}.participant-group h3{margin:0;color:#334155;font-size:12px;font-weight:800;line-height:1.4}.participant-group-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.participant-group-empty{margin:0;color:#64748b;font-size:11px;line-height:1.5}.participant-option{display:flex;align-items:center;gap:9px;min-width:0;padding:9px 10px;border:1px solid #e4eaf0;border-radius:8px;background:#fff;color:#334155;font-size:12px;font-weight:600;cursor:pointer}.participant-option input{width:16px;height:16px;flex:0 0 16px;accent-color:#0f766e}.participant-option span{min-width:0;overflow-wrap:anywhere}.participant-option small{margin:0 0 0 auto;flex:0 0 auto;color:#64748b;font-size:10px;font-weight:500}@media(max-width:900px){.recipient-scope{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.recipient-scope,.participant-group-list{grid-template-columns:1fr}}
.recipient-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}.recipient-filter-field label{display:block;margin:0 0 6px;color:#334155;font-size:11px;font-weight:700}.recipient-filter-field select{width:100%;min-height:40px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;background:#fff;color:#172033;font:inherit;font-size:12px}.recipient-filter-field select:focus-visible,.recipient-selection-actions button:focus-visible{border-color:#0f766e;outline:3px solid rgba(15,118,110,.16);outline-offset:2px}.recipient-filter-status,.recipient-filter-empty,.recipient-filter-help{margin:0;color:#64748b;font-size:11px;line-height:1.5}.recipient-filter-empty{padding:10px;border:1px dashed #cbd5e1;border-radius:8px;background:#fff}.recipient-selection-actions{display:flex;flex-wrap:wrap;gap:8px}.recipient-selection-actions button{min-height:36px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;background:#fff;color:#334155;font:inherit;font-size:11px;font-weight:700;cursor:pointer}.recipient-user-list{display:grid;gap:6px;max-height:280px;overflow:auto;min-width:0;min-inline-size:0;margin:0;padding:0;border:0}.specific-date-users .recipient-user-choice{display:flex;align-items:center;gap:9px;min-width:0;margin:0;padding:9px 10px;border:1px solid #e4eaf0;border-radius:8px;background:#fff;color:#334155;font-size:12px;font-weight:600;cursor:pointer}.recipient-user-choice input{width:16px;height:16px;flex:0 0 16px;margin:0;padding:0;border-radius:4px;background:#fff;accent-color:#0f766e}.recipient-user-choice span{min-width:0;overflow-wrap:anywhere}.recipient-user-choice small{display:block;margin-top:2px;color:#64748b;font-size:10px;font-weight:500}.recipient-user-choice[hidden]{display:none!important}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:640px){.recipient-filters{grid-template-columns:1fr}}
.schedule-window,.schedule-date-card{padding:16px;border:1px solid #dbe3ea;border-radius:12px;background:#f8fafc}.schedule-window{display:grid;gap:14px}.multi-day-toggle{display:flex;align-items:center;gap:9px;font-weight:750;line-height:1.4}.activity-form .multi-day-toggle input{flex:0 0 16px;width:16px;height:16px;margin:0;padding:0;accent-color:#0f766e}.schedule-date-choice input{width:16px;height:16px;accent-color:#0f766e}.schedule-window-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.schedule-date-choices{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:12px}.schedule-date-choice{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #dbe3ea;border-radius:9px;background:#fff;font-size:12px;font-weight:650}.schedule-date-card{display:grid;gap:14px;margin-top:12px;background:#fff}.schedule-date-card h3{margin:0;color:#172033;font-size:15px}.schedule-date-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.specific-date-users{display:none}.schedule-date-card[data-audience="specific"] .specific-date-users{display:block}.schedule-window-note{color:#64748b;font-size:11px;line-height:1.5}.activity-form #refresh-window{border-color:#F57C00;background:#F57C00;color:#fff;font-weight:800;box-shadow:0 2px 5px rgba(124,61,2,.18)}.activity-form #refresh-window:hover{border-color:#EA580C;background:#EA580C}.activity-form #refresh-window:focus-visible{outline:3px solid rgba(245,124,0,.28);outline-offset:2px}.schedule-date-card[hidden],.schedule-date-choices[hidden],.schedule-window-fields .field[hidden]{display:none!important}@media(max-width:640px){.schedule-window-fields,.schedule-date-fields{grid-template-columns:1fr}}
.schedule-date-fields .schedule-override-help{margin:-2px 0 8px;color:#64748b;font-size:11px;line-height:1.4}
.field-label{display:block;margin:0 0 7px;color:#334155;font-size:12px;font-weight:800}.recipient-filters[hidden],.recipient-selection-actions[hidden],.participant-filter-empty[hidden],.specific-date-users[hidden]{display:none!important}
@media(min-width:641px){.activity-form #activity-type-field{grid-column:1/-1;text-align:center}.activity-form #activity-type-field label{text-align:center}.activity-form #activity-type-field select{display:block;max-width:480px;margin:0 auto}}
</style>
<style>
.activity-form input[type="date"],
.activity-form input[type="time"]{min-height:52px;border-color:#cbd8d6;border-radius:12px;padding:12px 14px;background:linear-gradient(180deg,#fff 0%,#fbfdfc 100%);font-size:15px;font-variant-numeric:tabular-nums;letter-spacing:.015em;color-scheme:light;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
.activity-form input[type="date"]:hover,
.activity-form input[type="time"]:hover{border-color:#8bb8b1;background:#fff}
.activity-form input[type="date"]:focus,
.activity-form input[type="time"]:focus{border-color:#0f766e;outline:3px solid rgba(15,118,110,.13);outline-offset:2px;box-shadow:0 5px 16px rgba(15,118,110,.08)}
.activity-form input[type="date"]::-webkit-calendar-picker-indicator,
.activity-form input[type="time"]::-webkit-calendar-picker-indicator{padding:5px;border-radius:7px;cursor:pointer;opacity:.78;transition:background .18s ease,opacity .18s ease}
.activity-form input[type="date"]::-webkit-calendar-picker-indicator:hover,
.activity-form input[type="time"]::-webkit-calendar-picker-indicator:hover{background:#e9f5f2;opacity:1}
.schedule-window-fields .field{padding:13px;border:1px solid #e3ecea;border-radius:12px;background:rgba(255,255,255,.76)}
.schedule-window-fields .field label{margin-bottom:9px}
.schedule-date-choices{grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:9px}
.activity-form .field label.schedule-date-choice{position:relative;display:grid;min-height:104px;align-content:center;justify-items:center;gap:6px;box-sizing:border-box;margin:0;padding:12px 10px;border:1px solid #dbe6e3;border-top:3px solid #dbe6e3;border-radius:11px;background:#fff;color:#334155;text-align:center;cursor:pointer;transition:transform .18s ease,border-color .18s ease,background .18s ease,box-shadow .18s ease}
.schedule-date-choice input{position:absolute;top:9px;right:9px;width:17px;height:17px;margin:0;accent-color:#0f766e}
.schedule-date-choice time{display:grid;justify-items:center;gap:2px;font-variant-numeric:tabular-nums}
.schedule-date-choice time strong{color:#172033;font-size:24px;font-weight:750;line-height:1.05}
.schedule-date-weekday{color:#0f766e;font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.schedule-date-month{color:#64748b;font-size:10px;font-weight:600}
.activity-form .field label.schedule-date-choice:hover{transform:translateY(-2px);border-color:#8bb8b1;box-shadow:0 5px 14px rgba(15,118,110,.08)}
.activity-form .field label.schedule-date-choice:has(input:checked){border-color:#0f766e;border-top-color:#0f766e;background:#f0f8f6;box-shadow:inset 0 0 0 1px rgba(15,118,110,.08)}
.activity-form .field label.schedule-date-choice:focus-within{outline:3px solid rgba(15,118,110,.18);outline-offset:2px}
.schedule-date-card{border-color:#dce8e5;border-radius:14px;box-shadow:0 5px 16px rgba(30,65,60,.045)}
.schedule-date-card h3{padding-bottom:11px;border-bottom:1px solid #e8efed;color:#0f514d;font-size:16px}
.schedule-date-fields .field:has(input[type="time"]){padding:12px;border:1px solid #e6efed;border-radius:11px;background:#f9fcfb}
.activity-form .calendar-picker{position:relative;min-width:0}
.activity-form .calendar-picker input[type="date"]{padding-right:52px;pointer-events:none}
.activity-form .calendar-picker input[type="date"]::-webkit-calendar-picker-indicator{display:none}
.activity-form .calendar-picker-trigger{position:absolute;top:50%;right:8px;display:grid;width:34px;height:34px;place-items:center;transform:translateY(-50%);border:0;border-radius:9px;background:#eaf5f2;color:#0f766e;font-size:17px;line-height:1;cursor:pointer;transition:background .18s ease,transform .18s ease}
.activity-form .calendar-picker-trigger:hover{background:#d9eee9}
.activity-form .calendar-picker-trigger:focus-visible,.activity-calendar button:focus-visible{outline:3px solid rgba(15,118,110,.25);outline-offset:2px}
.activity-form .time-picker{position:relative;min-width:0}
.activity-form .time-picker input[type="time"]{padding-right:52px;pointer-events:none}
.activity-form .time-picker input[type="time"]::-webkit-calendar-picker-indicator{display:none}
.activity-form .time-picker-trigger{position:absolute;top:50%;right:8px;display:grid;width:34px;height:34px;place-items:center;transform:translateY(-50%);border:0;border-radius:9px;background:#eaf5f2;color:#0f766e;font-size:17px;line-height:1;cursor:pointer;transition:background .18s ease,transform .18s ease}
.activity-form .time-picker-trigger:hover{background:#d9eee9}
.activity-form .time-picker-trigger:focus-visible,.activity-time-panel button:focus-visible,.activity-time-panel input:focus-visible{outline:3px solid rgba(15,118,110,.25);outline-offset:2px}
.activity-time-panel{position:fixed;z-index:50;width:min(326px,calc(100vw - 24px));box-sizing:border-box;padding:16px;border:1px solid #d6e6e2;border-radius:16px;background:#fff;color:#172033;box-shadow:0 18px 48px rgba(17,54,49,.2);font:inherit}
.activity-time-panel[hidden]{display:none}
.activity-time-heading{margin:0;color:#173f3a;font-size:14px;font-weight:750}
.activity-time-preview{margin:10px 0 15px;padding:12px;border:1px solid #dfede9;border-radius:12px;background:linear-gradient(135deg,#f3faf8,#fff);color:#0f514d;text-align:center;font-size:27px;font-variant-numeric:tabular-nums;font-weight:750;letter-spacing:.04em}
.activity-time-controls{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px}
.activity-time-control{display:grid;align-content:start;gap:7px;min-width:0}
.activity-time-control>label{color:#71847f;font-size:10px;font-weight:750;letter-spacing:.08em;text-align:center;text-transform:uppercase}
.activity-time-value{display:grid;justify-items:center;gap:4px}
.activity-time-value button{display:grid;width:29px;height:25px;place-items:center;border:1px solid #e0eae7;border-radius:8px;background:#f8fbfa;color:#315c56;font:inherit;font-size:15px;font-weight:700;cursor:pointer}
.activity-time-value button:hover{border-color:#a8cfc6;background:#edf7f4}
.activity-time-value input{width:100%;height:44px;box-sizing:border-box;border:1px solid #d6e6e2;border-radius:10px;background:#fff;color:#172033;font:inherit;font-size:19px;font-variant-numeric:tabular-nums;font-weight:700;text-align:center}
.activity-time-period{display:grid;grid-template-columns:1fr 1fr;align-self:center;gap:4px;padding:4px;border:1px solid #e0eae7;border-radius:11px;background:#f8fbfa}
.activity-time-period button{min-height:38px;border:0;border-radius:8px;background:transparent;color:#64748b;font:inherit;font-size:12px;font-weight:750;cursor:pointer}
.activity-time-period button[aria-pressed="true"]{background:#0f766e;color:#fff;box-shadow:0 3px 8px rgba(15,118,110,.18)}
.activity-time-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid #e8efed}
.activity-time-actions button{min-height:36px;padding:0 12px;border:1px solid #d7e4e1;border-radius:9px;background:#fff;color:#315c56;font:inherit;font-size:11px;font-weight:750;cursor:pointer}
.activity-time-actions button[data-time-apply]{border-color:#0f766e;background:#0f766e;color:#fff}
.activity-time-actions button[data-time-apply]:hover{border-color:#115e59;background:#115e59}
.activity-calendar{position:fixed;z-index:50;width:min(326px,calc(100vw - 24px));box-sizing:border-box;padding:14px;border:1px solid #d6e6e2;border-radius:16px;background:#fff;color:#172033;box-shadow:0 18px 48px rgba(17,54,49,.2);font:inherit}
.activity-calendar[hidden]{display:none}
.activity-calendar-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:2px 2px 12px;border-bottom:1px solid #e8efed}
.activity-calendar-month{margin:0;color:#173f3a;font-size:15px;font-weight:750;letter-spacing:-.015em}
.activity-calendar-nav{display:flex;gap:6px}
.activity-calendar-nav button,.activity-calendar-footer button{display:grid;min-width:34px;min-height:34px;place-items:center;border:1px solid #e0eae7;border-radius:9px;background:#f8fbfa;color:#315c56;font:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .16s ease,border-color .16s ease}
.activity-calendar-nav button:hover,.activity-calendar-footer button:hover{border-color:#a8cfc6;background:#edf7f4}
.activity-calendar-nav button:disabled,.activity-calendar-footer button:disabled{opacity:.45;cursor:not-allowed}
.activity-calendar-weekdays,.activity-calendar-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;text-align:center}
.activity-calendar-weekdays{padding:12px 0 6px;color:#71847f;font-size:10px;font-weight:750;text-transform:uppercase}
.activity-calendar-days{grid-auto-rows:36px}
.activity-calendar-day{display:grid;min-width:0;place-items:center;border:1px solid transparent;border-radius:10px;background:transparent;color:#334155;font:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:background .16s ease,color .16s ease,border-color .16s ease}
.activity-calendar-day:hover{border-color:#cce3dd;background:#f0f8f6;color:#0f514d}
.activity-calendar-day.is-selected{border-color:#0f766e;background:#0f766e;color:#fff;box-shadow:0 4px 10px rgba(15,118,110,.2)}
.activity-calendar-day.is-today:not(.is-selected){border-color:#8bb8b1;color:#0f766e;font-weight:800}
.activity-calendar-day.is-outside{color:#a8b5b1}
.activity-calendar-day:disabled{cursor:not-allowed;opacity:.35}
.activity-calendar-footer{display:flex;justify-content:space-between;gap:8px;margin-top:11px;padding-top:10px;border-top:1px solid #e8efed}
.activity-calendar-footer button{min-height:30px;padding:0 10px;font-size:11px}
@media(max-width:640px){.schedule-date-choices{grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:7px}.activity-form .field label.schedule-date-choice{min-height:94px;padding:10px 8px}}
@media(prefers-reduced-motion:reduce){.activity-form input[type="date"],.activity-form input[type="time"],.activity-form .field label.schedule-date-choice,.activity-form .calendar-picker-trigger,.activity-form .time-picker-trigger,.activity-calendar button,.activity-time-panel button{transition:none}.activity-form .field label.schedule-date-choice:hover,.activity-form .calendar-picker-trigger:hover,.activity-form .time-picker-trigger:hover{transform:none}}
</style>
<style>
.budget-recipient-picker{margin-top:12px;overflow:hidden;border:1px solid #d8e5e2;border-radius:12px;background:#fff}
.budget-recipient-picker>summary{display:flex;min-height:46px;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;background:#f7faf9;color:#253746;font-size:12px;font-weight:750;cursor:pointer;list-style:none}
.budget-recipient-picker>summary::-webkit-details-marker{display:none}
.budget-recipient-picker>summary::after{width:8px;height:8px;flex:0 0 8px;border-right:2px solid #0f766e;border-bottom:2px solid #0f766e;content:"";transform:rotate(45deg) translateY(-2px);transition:transform .16s ease}
.budget-recipient-picker[open]>summary{border-bottom:1px solid #d8e5e2;background:#eef7f5;color:#0f514d}
.budget-recipient-picker[open]>summary::after{transform:rotate(225deg) translate(-2px,-1px)}
.budget-recipient-picker>summary:hover{background:#eef7f5}
.budget-recipient-picker>summary:focus-visible{position:relative;z-index:1;outline:3px solid rgba(15,118,110,.24);outline-offset:-3px}
.budget-recipient-picker .budget-recipient-list.aligned-recipient-list{max-height:240px;gap:8px;padding:12px;border:0;border-radius:0;background:#fff}
.budget-recipient-picker .budget-recipient-list.aligned-recipient-list label{min-height:44px;padding:9px 11px;border-color:#e2e8f0;border-radius:10px;background:#fff;color:#334155;font-size:12px;transition:border-color .16s ease,background .16s ease}
.budget-recipient-picker .budget-recipient-list.aligned-recipient-list label:hover{border-color:#a9cbc5;background:#f8fbfa}
.budget-recipient-picker .budget-recipient-list.aligned-recipient-list label:has(input:checked){border-color:#85b8ae;background:#eff8f6;color:#154e4a}
.budget-recipient-picker .budget-recipient-list.aligned-recipient-list label:focus-within{outline:3px solid rgba(15,118,110,.18);outline-offset:1px}
@media(prefers-reduced-motion:reduce){.budget-recipient-picker>summary::after,.budget-recipient-picker .budget-recipient-list.aligned-recipient-list label{transition:none}}
</style>
<style>
.custom-audience-roles{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:12px;border:1px solid #dbe3ea;border-radius:10px;background:#f8fafc}
.custom-audience-roles[hidden],.custom-audience-role[hidden],[data-specific-required][hidden],[data-custom-optional][hidden]{display:none!important}
.custom-audience-roles legend{padding:0 5px;color:#334155;font-size:12px;font-weight:800}
.custom-role-optional{color:#64748b;font-size:11px;font-weight:500}
.custom-audience-role{display:flex;align-items:center;gap:8px;min-height:36px;padding:7px 10px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;color:#334155;font-size:12px;font-weight:650;cursor:pointer}
.custom-audience-role input{width:16px;height:16px;margin:0;accent-color:#0f766e}
.schedule-date-card[data-audience="specific"] .specific-date-users,.schedule-date-card[data-audience="custom"] .specific-date-users{display:grid;gap:10px}
.activity-form-page.is-details-edit #activity-type-field,.activity-form-page.is-details-edit #activity-title-field,.activity-form-page.is-details-edit #activity-venue-field,.activity-form-page.is-details-edit #activity-speaker-field,.activity-form-page.is-details-edit #activity-organizer-field,.activity-form-page.is-details-edit #activity-description-field,.activity-form-page.is-schedule-edit #activity-schedule-section,.activity-form-page.is-schedule-edit #activity-session-details,.activity-form-page.is-schedule-edit .schedule-date-card,.activity-form-page.is-budget-edit #budget-section{position:relative;border-radius:10px;background:rgba(245,124,0,.07);outline:2px solid rgba(245,124,0,.42);outline-offset:5px;scroll-margin-top:28px}
.budget-section{margin-top:10px;padding:22px;border:1px solid #dce7e5;border-radius:15px;background:#fbfefd}.budget-section legend{padding:0 8px;color:#172033;font-size:16px;font-weight:850}.budget-section .budget-intro{margin:4px 0 16px;color:#64748b;font-size:12px;line-height:1.6}.budget-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.budget-expenses{display:grid;gap:12px;margin-top:15px}.expense-row{padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.expense-row-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.expense-row-head strong{font-size:12px}.expense-row-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.budget-tools{display:flex;justify-content:flex-end;margin-top:12px}.budget-check{display:flex;align-items:flex-start;gap:9px;margin-top:14px;padding:12px;border:1px solid #dbe7e4;border-radius:10px;background:#f5faf9;color:#334155;font-size:12px;line-height:1.5}.budget-check input{width:17px;height:17px;margin-top:1px;accent-color:#0f766e}.budget-notice{margin-top:18px;padding-top:17px;border-top:1px solid #e2e8f0}.budget-notice h3{margin:0 0 5px;font-size:14px}.budget-recipient-options{display:flex;flex-wrap:wrap;gap:10px 16px;margin:10px 0}.budget-recipient-options label{display:flex;align-items:center;gap:7px;font-size:12px}.budget-recipient-options input{accent-color:#0f766e}.budget-recipient-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;max-height:180px;overflow:auto;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}.budget-recipient-list label{display:flex;align-items:center;gap:8px;color:#334155;font-size:11px}.budget-recipient-list input{accent-color:#0f766e}.notice-preview{white-space:pre-wrap;margin-top:10px;padding:13px;border:1px solid #dbe7e4;border-radius:10px;background:#f7faf9;color:#475569;font-size:12px;line-height:1.65}.budget-add{min-height:36px;padding:7px 12px}.expense-remove{border:0;background:transparent;color:#b42318;font:inherit;font-size:11px;font-weight:800;cursor:pointer}@media(max-width:720px){.budget-grid,.expense-row-fields,.budget-recipient-list{grid-template-columns:1fr}.budget-section{padding:17px}.expense-row{padding:13px}}
</style>
<link rel="stylesheet" href="<?= activityFormEscape(SITE_URL . 'css/file-uploader.css') ?>">

<style>
.activity-form .form-errors{margin:0 0 20px;padding:16px 18px;border:1px solid #f3c3c3;border-left:5px solid #f3c3c3;border-radius:10px;background:#fffafa;color:#7f1d1d;box-shadow:0 1px 3px rgba(127,29,29,.06);transition:background-color .8s ease,border-color .8s ease,box-shadow .8s ease}
.activity-form .form-errors.is-highlighted{border-color:#dc2626;background:#fff1f2;box-shadow:0 0 0 4px rgba(239,68,68,.15)}
.activity-form .form-errors:focus-visible{outline:3px solid rgba(245,124,0,.4);outline-offset:3px}
.activity-form .form-errors a{color:inherit;font-weight:700;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}
.activity-form .form-errors a:focus-visible{border-radius:3px;outline:3px solid rgba(245,124,0,.45);outline-offset:3px}
.activity-form .activity-field-error{grid-column:1/-1;margin:7px 0 0;color:#b42318;font-size:12px;font-weight:650;line-height:1.45}
.activity-form [aria-invalid="true"]{border-color:#b42318}
.activity-form .button{min-height:46px;box-shadow:0 2px 5px rgba(15,23,42,.14)}
.activity-form .button.secondary{border-color:#64748b;background:#f8fafc;color:#1e293b;box-shadow:0 1px 3px rgba(15,23,42,.12)}
.activity-form .button.secondary:hover{border-color:#475569;background:#e2e8f0;color:#0f172a}
.activity-form .button:focus-visible{outline:3px solid rgba(15,118,110,.32);outline-offset:3px}
.activity-form-page.is-admin .activity-form .button:not(.secondary){border-color:#F57C00;background:#F57C00;color:#fff;box-shadow:0 2px 5px rgba(124,61,2,.2)}
.activity-form-page.is-admin .activity-form .button:not(.secondary):hover{border-color:#EA580C;background:#EA580C}
.activity-form .field label.multi-day-toggle{display:flex;align-items:center;gap:9px;margin-top:4px;margin-bottom:0;cursor:pointer}
.activity-form .field label.schedule-date-choice{display:flex;align-items:center;margin-bottom:0}
.activity-form .field label.custom-audience-role{display:flex;align-items:flex-start;margin-bottom:0}
.activity-form .field label.budget-check{display:grid;grid-template-columns:16px minmax(0,1fr);align-items:start;gap:10px;margin:14px 0 0}
.activity-form .budget-recipient-options label{display:flex;align-items:center;margin-bottom:0}
.activity-form .budget-recipient-list.aligned-recipient-list label{display:grid;grid-template-columns:16px minmax(0,1fr);align-items:start}
.activity-form .budget-recipient-picker{border-color:#64748b;box-shadow:0 1px 3px rgba(15,23,42,.1)}
.activity-form .budget-recipient-picker>summary{background:#f1f5f9;color:#0f172a}
.activity-form .budget-recipient-picker>summary:hover{background:#e2e8f0;color:#0f172a}
.activity-form .budget-recipient-picker>summary:focus-visible{outline:3px solid rgba(13,148,136,.35);outline-offset:-3px}
.activity-form #activity-session-details[hidden],.activity-form #activity-single-session-details[hidden]{display:none!important}
.activity-form #schedule-details-update-hint{margin-top:4px}
.activity-form [hidden]{display:none!important}
.activity-form input[type="checkbox"]{box-sizing:border-box;width:16px;height:16px;min-width:16px;flex:0 0 16px;margin:1px 0 0;padding:0;accent-color:#0f766e;vertical-align:top}
.activity-form .budget-check{display:grid;grid-template-columns:16px minmax(0,1fr);align-items:start;gap:10px}
.activity-form .budget-check input[type="checkbox"]{margin-top:2px}
.activity-form .budget-check span{min-width:0;line-height:1.5}
.activity-form .custom-audience-role,.activity-form .recipient-user-choice,.activity-form .budget-recipient-list label{align-items:flex-start}
.activity-form .custom-audience-role input[type="checkbox"],.activity-form .recipient-user-choice input[type="checkbox"],.activity-form .budget-recipient-list input[type="checkbox"]{margin-top:2px}
@media(prefers-reduced-motion:reduce){.activity-form .form-errors{transition:none}}
</style>
<style>
body.admin-body .admin-dashboard .admin-page-content .activity-form-page.is-admin .back-link {
  display: inline-flex;
  min-height: 44px;
  box-sizing: border-box;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 9px 15px;
  background: #fff;
  color: #334155;
  font-size: 13px;
  font-weight: 800;
  line-height: 1.2;
  box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
  transition: border-color .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease;
}
body.admin-body .admin-dashboard .admin-page-content .activity-form-page.is-admin .back-link:hover {
  border-color: #2563eb;
  background: #2563eb;
  color: #fff;
  box-shadow: 0 4px 10px rgba(37, 99, 235, .2);
}
body.admin-body .admin-dashboard .admin-page-content .activity-form-page.is-admin .back-link:focus-visible {
  border-color: #2563eb;
  outline: 3px solid rgba(37, 99, 235, .24);
  outline-offset: 3px;
}
@media (prefers-reduced-motion: reduce) {
  body.admin-body .admin-dashboard .admin-page-content .activity-form-page.is-admin .back-link { transition: none; }
}
</style>

<style>.activity-form [data-activity-source][hidden] { display: none !important; }</style>
<div class="activity-form-page<?= $shell === 'admin' ? ' is-admin' : '' ?><?= $isEdit ? ($requestedScheduleMode ? ' is-schedule-edit' : ($requestedBudgetMode ? ' is-budget-edit' : ' is-details-edit')) : '' ?>">
  <header class="form-heading"><div><h1><?= activityFormEscape($pageTitle) ?></h1><p><?= activityFormEscape($pageSubtitle) ?></p></div><a class="back-link" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . ($modulePage !== '' ? '&module_page=' . rawurlencode($modulePage) : '') : $activityListUrl) ?>">← Back</a></header>
  <section class="form-card">
    <?php if ($isEdit): ?><div class="form-status">Current status: <strong><?= activityFormEscape(ucfirst($values['status'])) ?></strong>. Use the activity detail page to complete or cancel it.</div><?php endif; ?>
    <form class="activity-form" id="activity-form" method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <?php if ($errors): ?><div class="form-errors is-highlighted" id="activity-form-errors" role="alert" tabindex="-1"><strong>Review the following and correct the marked fields:</strong><ul><?php foreach ($errors as $error): ?><li><?= activityFormEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if ($modulePage !== ''): ?><input type="hidden" name="module_page" value="<?= activityFormEscape($modulePage) ?>"><?php endif; ?>
      <?php if ($isEdit && $activityId): ?><input type="hidden" name="activity_id" value="<?= (int) $activityId ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="field" id="activity-type-field"><label for="activity-type">Type <span class="required">*</span></label><select id="activity-type" name="activity_type" required><?php foreach ($typeOptions as $value => $label): ?><option value="<?= activityFormEscape($value) ?>" <?= $values['activity_type'] === $value ? 'selected' : '' ?>><?= activityFormEscape($label) ?></option><?php endforeach; ?></select></div>
        <div class="field" id="custom-activity-type-field" <?= $values['activity_type'] === 'other' ? '' : 'hidden' ?>><label for="custom-activity-type">Custom activity type <span class="required">*</span></label><input id="custom-activity-type" name="custom_activity_type" type="text" maxlength="100" value="<?= activityFormEscape($values['custom_activity_type']) ?>" <?= $values['activity_type'] === 'other' ? 'required' : '' ?>><small>Enter the activity category, such as “Research exhibit”.</small></div>
        <div class="field full" id="activity-title-field"><label for="activity-title">Title <span class="required">*</span></label><input id="activity-title" name="title" type="text" maxlength="255" value="<?= activityFormEscape($values['title']) ?>" required></div>
        <div class="field full" id="activity-schedule-section">
          <label>Schedule <span class="required">*</span></label>
          <div class="schedule-window">
            <label class="multi-day-toggle"><input id="multi-day-toggle" name="multi_day" type="checkbox" value="1" <?= $multiDay ? 'checked' : '' ?>> Schedule over multiple days</label>
            <p class="schedule-window-note"><?= $multiDay ? 'Choose a date window, then select only dates when sessions took place or will take place. Each date has its own times, ' . ($isUpcomingActivity ? 'invitee audience' : 'actual attendees') . ', and optional venue or speaker override.' : 'Set the date and session details below. Turn on Schedule over multiple days to assign separate times, audiences, venues, or speakers to selected dates.' ?></p>
            <div class="schedule-window-fields">
        <div class="field"><label for="window-start">Start date <span class="required">*</span></label><input id="window-start" name="window_start" type="date" value="<?= activityFormEscape($scheduleWindowStart) ?>" required></div>
              <div class="field" id="window-end-field" <?= $multiDay ? '' : 'hidden' ?>><label for="window-end">End date <span class="required">*</span></label><input id="window-end" name="window_end" type="date" value="<?= activityFormEscape($scheduleWindowEnd) ?>" <?= $multiDay ? 'required' : '' ?>></div>
            </div>
            <div>
              <div class="schedule-date-choices" id="schedule-date-choices" aria-label="Select scheduled dates" <?= $multiDay ? '' : 'hidden' ?>>
                <?php foreach ($dateChoices as $choiceDate): ?>
                  <label class="schedule-date-choice"><input type="checkbox" name="schedule_dates[<?= activityFormEscape($choiceDate) ?>][selected]" data-schedule-date="<?= activityFormEscape($choiceDate) ?>" value="1" <?= isset($scheduleFormRows[$choiceDate]) ? 'checked' : '' ?>><time datetime="<?= activityFormEscape($choiceDate) ?>"><span class="schedule-date-weekday"><?= activityFormEscape(date('D', strtotime($choiceDate))) ?></span><strong><?= activityFormEscape(date('j', strtotime($choiceDate))) ?></strong><span class="schedule-date-month"><?= activityFormEscape(date('M Y', strtotime($choiceDate))) ?></span></time></label>
                <?php endforeach; ?>
              </div>
              <button class="button" id="refresh-window" type="submit" name="refresh_schedule_window" value="1" formnovalidate>Update date choices</button>
              <p class="schedule-window-note" id="schedule-details-update-hint" <?= $_SERVER['REQUEST_METHOD'] === 'GET' && !$isEdit ? '' : 'hidden' ?>>Click Update date choices to show the activity, session, and budget details.</p>
              <?php if (!$dateChoices): ?><p class="schedule-window-note">Enter a valid date window of no more than 366 days, then update date choices.</p><?php endif; ?>
            </div>
          </div>
          <?php if (!$scheduleTablesAvailable || !$participantsAvailable): ?><small>Apply activity schedule migrations 034 and 035 before saving this schedule.</small><?php endif; ?>
        </div>
        <?php if ($multiDay): ?>
        <div class="field full" id="activity-session-details" <?= $_SERVER['REQUEST_METHOD'] === 'GET' && !$isEdit ? 'hidden' : '' ?>><strong>Selected session details</strong>
            <?php foreach ($scheduleFormRows as $scheduleDate => $scheduleRow): ?>
              <?php if (!in_array($scheduleDate, $dateChoices, true)) { continue; } ?>
              <fieldset class="schedule-date-card" data-audience="<?= activityFormEscape((string) ($scheduleRow['recipient_scope'] ?? 'all')) ?>" data-error-date="<?= activityFormEscape(date('M j, Y', strtotime($scheduleDate))) ?>">
                <legend class="sr-only"><?= activityFormEscape(date('F j, Y', strtotime($scheduleDate))) ?></legend>
                <h3><?= activityFormEscape(date('l, F j, Y', strtotime($scheduleDate))) ?></h3>
                <div class="schedule-date-fields">
                  <div class="field"><label for="start-<?= activityFormEscape($scheduleDate) ?>">Start time <span class="required">*</span></label><input id="start-<?= activityFormEscape($scheduleDate) ?>" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][start_time]" type="time" value="<?= activityFormEscape((string) ($scheduleRow['start_time'] ?? '')) ?>" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'required' : '' ?>></div>
                  <div class="field"><label for="end-<?= activityFormEscape($scheduleDate) ?>">End time <span class="required">*</span></label><input id="end-<?= activityFormEscape($scheduleDate) ?>" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][end_time]" type="time" value="<?= activityFormEscape((string) ($scheduleRow['end_time'] ?? '')) ?>" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'required' : '' ?>></div>
                  <div class="field"><label for="venue-<?= activityFormEscape($scheduleDate) ?>">Venue override</label><small class="schedule-override-help" id="venue-help-<?= activityFormEscape($scheduleDate) ?>">Leave blank to use the default venue.</small><input id="venue-<?= activityFormEscape($scheduleDate) ?>" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][venue_override]" aria-describedby="venue-help-<?= activityFormEscape($scheduleDate) ?>" maxlength="255" value="<?= activityFormEscape((string) ($scheduleRow['venue_override'] ?? '')) ?>"></div>
                  <div class="field"><label for="speaker-<?= activityFormEscape($scheduleDate) ?>">Speaker override</label><small class="schedule-override-help" id="speaker-help-<?= activityFormEscape($scheduleDate) ?>">Leave blank to use the default speaker.</small><input id="speaker-<?= activityFormEscape($scheduleDate) ?>" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][speaker_override]" aria-describedby="speaker-help-<?= activityFormEscape($scheduleDate) ?>" maxlength="255" value="<?= activityFormEscape((string) ($scheduleRow['speaker_override'] ?? '')) ?>"></div>
                  <div class="field full"><label for="audience-<?= activityFormEscape($scheduleDate) ?>"><?= $isUpcomingActivity ? 'Invitee audience' : 'Actual participants' ?> <span class="required">*</span></label>
                    <select id="audience-<?= activityFormEscape($scheduleDate) ?>" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][recipient_scope]" class="date-audience">
                      <?php foreach ($recipientScopeOptions as $scopeKey => $scopeOption): ?><option value="<?= activityFormEscape($scopeKey) ?>" <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === $scopeKey ? 'selected' : '' ?>><?= activityFormEscape($scopeOption['label']) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <fieldset class="field full custom-audience-roles" data-custom-roles <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'hidden' ?>>
                    <legend>Include users by role <span class="custom-role-optional">(optional)</span></legend>
                    <?php foreach ($recipientRoleLabels as $roleKey => $roleLabel): ?>
                      <?php if (isset($availableRecipientRoles[$roleKey])): ?>
                        <?php $roleInputId = 'audience-role-' . $roleKey . '-' . $scheduleDate; ?>
                        <label class="custom-audience-role" for="<?= activityFormEscape($roleInputId) ?>">
                          <input id="<?= activityFormEscape($roleInputId) ?>" type="checkbox" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][recipient_roles][]" value="<?= activityFormEscape($roleKey) ?>" <?= in_array($roleKey, $scheduleRow['recipient_roles'] ?? [], true) ? 'checked' : '' ?> <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'disabled' ?>>
                          <span><?= activityFormEscape($roleLabel) ?></span>
                        </label>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </fieldset>
                  <div class="field full specific-date-users" <?= in_array((string) ($scheduleRow['recipient_scope'] ?? 'all'), ['specific', 'custom'], true) ? '' : 'hidden' ?>>
                    <?php $pickerId = 'users-' . $scheduleDate; ?>
                    <span class="field-label" id="recipient-heading-<?= activityFormEscape($scheduleDate) ?>">Individual users <span class="required" data-specific-required <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'specific' ? '' : 'hidden' ?>>*</span><span class="custom-role-optional" data-custom-optional <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'hidden' ?>(optional for Custom)</span></span>
                    <p class="recipient-filter-help" id="recipient-help-<?= activityFormEscape($scheduleDate) ?>">Specific users requires at least one person. For a custom audience, these selections are optional and are combined with users in the selected roles. Filters only hide accounts; they do not clear checked selections. Selection actions affect only visible matches.</p>
                    <div class="recipient-filters" data-recipient-filters hidden>
                      <div class="recipient-filter-field">
                        <label for="role-filter-<?= activityFormEscape($scheduleDate) ?>">Role</label>
                        <select id="role-filter-<?= activityFormEscape($scheduleDate) ?>" data-role-filter>
                          <option value="any">Any available role</option>
                          <?php foreach (['student', 'faculty', 'research_staff'] as $filterRole): ?>
                            <?php if (isset($availableRecipientRoles[$filterRole])): ?>
                              <option value="<?= activityFormEscape($filterRole) ?>"><?= activityFormEscape($recipientRoleLabels[$filterRole]) ?></option>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="recipient-filter-field">
                        <label for="affiliation-filter-<?= activityFormEscape($scheduleDate) ?>" data-affiliation-label>Course / department</label>
                        <select id="affiliation-filter-<?= activityFormEscape($scheduleDate) ?>" data-affiliation-filter>
                          <option value="any">All affiliations</option>
                        </select>
                      </div>
                    </div>
                    <div class="recipient-selection-actions" data-recipient-actions hidden>
                      <button type="button" data-select-visible>Select visible users</button>
                      <button type="button" data-clear-visible>Clear visible selections</button>
                    </div>
                    <p class="participant-filter-status" id="recipient-status-<?= activityFormEscape($scheduleDate) ?>" data-filter-status role="status" aria-live="polite"></p>
                    <p class="participant-filter-empty" id="recipient-empty-<?= activityFormEscape($scheduleDate) ?>" data-filter-empty role="status" hidden>No eligible accounts match these filters. Try another role or affiliation.</p>
                    <fieldset class="recipient-user-list" aria-labelledby="recipient-heading-<?= activityFormEscape($scheduleDate) ?>" aria-describedby="recipient-help-<?= activityFormEscape($scheduleDate) ?> recipient-status-<?= activityFormEscape($scheduleDate) ?> recipient-empty-<?= activityFormEscape($scheduleDate) ?>">
                      <legend class="sr-only">Eligible users for <?= activityFormEscape(date('F j, Y', strtotime($scheduleDate))) ?></legend>
                      <?php foreach ($activeUsers as $activeUser): ?>
                        <?php
                          $candidateId = (int) $activeUser['user_id'];
                          $candidateRole = (string) $activeUser['role'];
                          $candidateAffiliationType = $candidateRole === 'student' ? 'course' : 'department';
                          $candidateAffiliation = trim((string) ($activeUser[$candidateRole === 'student' ? 'program' : 'department'] ?? ''));
                          $candidateAffiliationLabel = $candidateAffiliation !== ''
                              ? ucfirst($candidateAffiliationType) . ': ' . $candidateAffiliation
                              : ($candidateRole === 'student' ? 'Course / program not specified' : 'Department not specified');
                        ?>
                        <label class="recipient-user-choice" data-recipient-user data-role="<?= activityFormEscape($candidateRole) ?>" data-affiliation-type="<?= activityFormEscape($candidateAffiliationType) ?>" data-affiliation="<?= activityFormEscape($candidateAffiliation) ?>">
                          <input type="checkbox" name="schedule_dates[<?= activityFormEscape($scheduleDate) ?>][recipient_user_ids][]" value="<?= $candidateId ?>" <?= in_array($candidateId, $scheduleRow['recipient_user_ids'] ?? [], true) ? 'checked' : '' ?> <?= in_array((string) ($scheduleRow['recipient_scope'] ?? 'all'), ['specific', 'custom'], true) ? '' : 'disabled' ?>>
                          <span><?= activityFormEscape(trim((string) $activeUser['first_name'] . ' ' . (string) $activeUser['last_name'])) ?><small><?= activityFormEscape($recipientRoleLabels[$candidateRole] ?? ucfirst($candidateRole)) ?> · <?= activityFormEscape($candidateAffiliationLabel) ?></small></span>
                        </label>
                      <?php endforeach; ?>
                    </fieldset>
                  </div>
                </div>
              </fieldset>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <?php $scheduleDate = $scheduleWindowStart; $scheduleRow = $scheduleFormRows[$scheduleDate] ?? ['start_time' => '', 'end_time' => '', 'venue_override' => '', 'speaker_override' => '', 'recipient_scope' => 'all', 'recipient_user_ids' => []]; ?>
          <div class="field full" id="activity-single-session-details" <?= $_SERVER['REQUEST_METHOD'] === 'GET' && !$isEdit ? 'hidden' : '' ?>><strong>Session details · <?= activityFormEscape(date('F j, Y', strtotime($scheduleDate))) ?></strong>
            <div class="schedule-date-card" data-audience="<?= activityFormEscape((string) ($scheduleRow['recipient_scope'] ?? 'all')) ?>" data-error-date="<?= activityFormEscape(date('M j, Y', strtotime($scheduleDate))) ?>">
              <div class="schedule-date-fields">
                <div class="field"><label for="start-<?= activityFormEscape($scheduleDate) ?>">Start time <span class="required">*</span></label><input id="start-<?= activityFormEscape($scheduleDate) ?>" name="single_schedule[start_time]" type="time" value="<?= activityFormEscape((string) ($scheduleRow['start_time'] ?? '')) ?>" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'required' : '' ?>></div>
                <div class="field"><label for="end-<?= activityFormEscape($scheduleDate) ?>">End time <span class="required">*</span></label><input id="end-<?= activityFormEscape($scheduleDate) ?>" name="single_schedule[end_time]" type="time" value="<?= activityFormEscape((string) ($scheduleRow['end_time'] ?? '')) ?>" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'required' : '' ?>></div>
                <input type="hidden" name="single_schedule[venue_override]" value="<?= activityFormEscape((string) ($scheduleRow['venue_override'] ?? '')) ?>">
                <input type="hidden" name="single_schedule[speaker_override]" value="<?= activityFormEscape((string) ($scheduleRow['speaker_override'] ?? '')) ?>">
                <div class="field full"><label for="audience-<?= activityFormEscape($scheduleDate) ?>"><?= $isUpcomingActivity ? 'Invitee audience' : 'Actual participants' ?> <span class="required">*</span></label>
                  <select id="audience-<?= activityFormEscape($scheduleDate) ?>" name="single_schedule[recipient_scope]" class="date-audience">
                    <?php foreach ($recipientScopeOptions as $scopeKey => $scopeOption): ?><option value="<?= activityFormEscape($scopeKey) ?>" <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === $scopeKey ? 'selected' : '' ?>><?= activityFormEscape($scopeOption['label']) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <fieldset class="field full custom-audience-roles" data-custom-roles <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'hidden' ?>>
                  <legend>Include users by role <span class="custom-role-optional">(optional)</span></legend>
                  <?php foreach ($recipientRoleLabels as $roleKey => $roleLabel): ?>
                    <?php if (isset($availableRecipientRoles[$roleKey])): ?>
                      <?php $roleInputId = 'audience-role-' . $roleKey . '-' . $scheduleDate; ?>
                      <label class="custom-audience-role" for="<?= activityFormEscape($roleInputId) ?>">
                        <input id="<?= activityFormEscape($roleInputId) ?>" type="checkbox" name="single_schedule[recipient_roles][]" value="<?= activityFormEscape($roleKey) ?>" <?= in_array($roleKey, $scheduleRow['recipient_roles'] ?? [], true) ? 'checked' : '' ?> <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'disabled' ?>>
                        <span><?= activityFormEscape($roleLabel) ?></span>
                      </label>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </fieldset>
                <div class="field full specific-date-users" <?= in_array((string) ($scheduleRow['recipient_scope'] ?? 'all'), ['specific', 'custom'], true) ? '' : 'hidden' ?>>
                  <span class="field-label" id="recipient-heading-<?= activityFormEscape($scheduleDate) ?>">Individual users <span class="required" data-specific-required <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'specific' ? '' : 'hidden' ?>>*</span><span class="custom-role-optional" data-custom-optional <?= (string) ($scheduleRow['recipient_scope'] ?? 'all') === 'custom' ? '' : 'hidden' ?>(optional for Custom)</span></span>
                  <p class="recipient-filter-help" id="recipient-help-<?= activityFormEscape($scheduleDate) ?>">Specific users requires at least one person. For a custom audience, these selections are optional and are combined with users in the selected roles. Filters only hide accounts; they do not clear checked selections. Selection actions affect only visible matches.</p>
                  <div class="recipient-filters" data-recipient-filters hidden>
                    <div class="recipient-filter-field">
                      <label for="role-filter-<?= activityFormEscape($scheduleDate) ?>">Role</label>
                      <select id="role-filter-<?= activityFormEscape($scheduleDate) ?>" data-role-filter>
                        <option value="any">Any available role</option>
                        <?php foreach (['student', 'faculty', 'research_staff'] as $filterRole): ?>
                          <?php if (isset($availableRecipientRoles[$filterRole])): ?>
                            <option value="<?= activityFormEscape($filterRole) ?>"><?= activityFormEscape($recipientRoleLabels[$filterRole]) ?></option>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="recipient-filter-field">
                      <label for="affiliation-filter-<?= activityFormEscape($scheduleDate) ?>" data-affiliation-label>Course / department</label>
                      <select id="affiliation-filter-<?= activityFormEscape($scheduleDate) ?>" data-affiliation-filter>
                        <option value="any">All affiliations</option>
                      </select>
                    </div>
                  </div>
                  <div class="recipient-selection-actions" data-recipient-actions hidden>
                    <button type="button" data-select-visible>Select visible users</button>
                    <button type="button" data-clear-visible>Clear visible selections</button>
                  </div>
                  <p class="participant-filter-status" id="recipient-status-<?= activityFormEscape($scheduleDate) ?>" data-filter-status role="status" aria-live="polite"></p>
                  <p class="participant-filter-empty" id="recipient-empty-<?= activityFormEscape($scheduleDate) ?>" data-filter-empty role="status" hidden>No eligible accounts match these filters. Try another role or affiliation.</p>
                  <fieldset class="recipient-user-list" aria-labelledby="recipient-heading-<?= activityFormEscape($scheduleDate) ?>" aria-describedby="recipient-help-<?= activityFormEscape($scheduleDate) ?> recipient-status-<?= activityFormEscape($scheduleDate) ?> recipient-empty-<?= activityFormEscape($scheduleDate) ?>">
                    <legend class="sr-only">Eligible users for <?= activityFormEscape(date('F j, Y', strtotime($scheduleDate))) ?></legend>
                    <?php foreach ($activeUsers as $activeUser): ?>
                      <?php
                        $candidateId = (int) $activeUser['user_id'];
                        $candidateRole = (string) $activeUser['role'];
                        $candidateAffiliationType = $candidateRole === 'student' ? 'course' : 'department';
                        $candidateAffiliation = trim((string) ($activeUser[$candidateRole === 'student' ? 'program' : 'department'] ?? ''));
                        $candidateAffiliationLabel = $candidateAffiliation !== ''
                            ? ucfirst($candidateAffiliationType) . ': ' . $candidateAffiliation
                            : ($candidateRole === 'student' ? 'Course / program not specified' : 'Department not specified');
                      ?>
                      <label class="recipient-user-choice" data-recipient-user data-role="<?= activityFormEscape($candidateRole) ?>" data-affiliation-type="<?= activityFormEscape($candidateAffiliationType) ?>" data-affiliation="<?= activityFormEscape($candidateAffiliation) ?>">
                        <input type="checkbox" name="single_schedule[recipient_user_ids][]" value="<?= $candidateId ?>" <?= in_array($candidateId, $scheduleRow['recipient_user_ids'] ?? [], true) ? 'checked' : '' ?> <?= in_array((string) ($scheduleRow['recipient_scope'] ?? 'all'), ['specific', 'custom'], true) ? '' : 'disabled' ?>>
                        <span><?= activityFormEscape(trim((string) $activeUser['first_name'] . ' ' . (string) $activeUser['last_name'])) ?><small><?= activityFormEscape($recipientRoleLabels[$candidateRole] ?? ucfirst($candidateRole)) ?> · <?= activityFormEscape($candidateAffiliationLabel) ?></small></span>
                      </label>
                    <?php endforeach; ?>
                  </fieldset>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <div class="field" id="activity-venue-field" <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'hidden' : '' ?>><label for="activity-venue">Venue</label><input id="activity-venue" name="venue" type="text" maxlength="255" value="<?= activityFormEscape($values['venue']) ?>"></div>
        <div class="field" id="activity-speaker-field" <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'hidden' : '' ?>><label for="activity-speaker">Speaker</label><input id="activity-speaker" name="speaker" type="text" maxlength="255" value="<?= activityFormEscape($values['speaker']) ?>"></div>
        <div class="field" id="activity-organizer-field" <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'hidden' : '' ?>><label for="activity-organizer">Organizer</label><input id="activity-organizer" name="organizer" type="text" maxlength="255" value="<?= activityFormEscape($values['organizer']) ?>"></div>
        <div class="field full" id="activity-description-field" <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'hidden' : '' ?>><label for="activity-description">Description</label><textarea id="activity-description" name="description"><?= activityFormEscape($values['description']) ?></textarea><small>Include the purpose, audience, or preparation notes participants should know.</small></div>
        <?php if (!$requestedScheduleMode && !$requestedBudgetMode && $canChooseArchivedResearchSource): ?>
          <div class="field full" data-activity-source="colloquium" <?= $values['activity_type'] === 'colloquium' ? '' : 'hidden' ?>>
            <label for="source-project-id">Archived research <span class="muted">(optional)</span></label>
            <select id="source-project-id" name="source_project_id">
              <option value="">No linked research</option>
              <?php foreach ($sourceResearchProjects as $sourceProject): ?>
                <option value="<?= (int) $sourceProject['project_id'] ?>" <?= (string) $sourceProject['project_id'] === $values['source_project_id'] ? 'selected' : '' ?>><?= activityFormEscape((string) $sourceProject['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <small>Choose a completed or archived student research project related to this colloquium.</small>
          </div>
        <?php endif; ?>
        <?php if (!$requestedScheduleMode && !$requestedBudgetMode && $canChooseArchivedPublicationSource): ?>
          <div class="field full" data-activity-source="conference_congress" <?= $values['activity_type'] === 'conference_congress' ? '' : 'hidden' ?>>
            <label for="source-publication-id">Archived publication <span class="muted">(optional)</span></label>
            <select id="source-publication-id" name="source_publication_id">
              <option value="">No linked publication</option>
              <?php foreach ($sourceFacultyPublications as $sourcePublication): ?>
                <?php $publicationLabel = (string) $sourcePublication['research_title']; ?>
                <?php if (trim((string) ($sourcePublication['researcher_name'] ?? '')) !== ''): ?>
                  <?php $publicationLabel .= ' · ' . trim((string) $sourcePublication['researcher_name']); ?>
                <?php endif; ?>
                <option value="<?= (int) $sourcePublication['publication_id'] ?>" <?= (string) $sourcePublication['publication_id'] === $values['source_publication_id'] ? 'selected' : '' ?>><?= activityFormEscape($publicationLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <small>Choose a published or archived faculty publication related to this conference.</small>
          </div>
        <?php endif; ?>
        <?php if ($role === 'admin' && !$requestedScheduleMode && !$requestedBudgetMode): ?>
          <?php if ($activityDocumentsAvailable): ?>
            <div class="field full" id="activity-document-field" <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'hidden' : '' ?>>
              <?php if ($activityDocument): ?><p>Current document: <?= activityFormEscape((string) $activityDocument['original_name']) ?></p><?php endif; ?>
              <?= renderFileUploader([
                  'inputName' => 'activity_document',
                  'accept' => '.pdf',
                  'maxSize' => 20000,
                  'folderTarget' => 'activities',
                  'label' => $activityDocument ? 'Replace activity document (PDF)' : 'Activity document (PDF)',
                  'description' => 'Upload a PDF that invited users can open on its own',
                  'allowedFormatsText' => 'PDF only · Max 20 MB',
              ]) ?>
              <small>Only users invited to at least one scheduled date can open this document. Replacing the file updates the document available to invitees.</small>
            </div>
          <?php else: ?>
            <p class="field full" role="status">Activity documents become available after migration 048 is applied.</p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($budgetSchemaAvailable): ?>
          <fieldset class="field full budget-section" id="budget-section" <?= $_SERVER['REQUEST_METHOD'] === 'GET' && !$isEdit ? 'hidden' : '' ?>>
            <legend>Budget and Participation Details</legend>
            <p class="budget-intro">Keep institutional funding, participant costs, and schedule-specific expenses distinct. Budget notices are separate from schedule invitations and are sent only when explicitly confirmed.</p>
            <p class="budget-intro" id="participant-funding-requirement" role="status" hidden>Participant-funded activities must include at least one mandatory participant charge with an amount.</p>
            <div class="budget-grid">
              <div class="field"><label for="activity-format">Activity format</label><select id="activity-format" name="activity_format"><option value="on_site" <?= $budgetValues['activity_format'] === 'on_site' ? 'selected' : '' ?>>On-site</option><option value="online" <?= $budgetValues['activity_format'] === 'online' ? 'selected' : '' ?>>Online</option><option value="hybrid" <?= $budgetValues['activity_format'] === 'hybrid' ? 'selected' : '' ?>>Hybrid</option></select></div>
              <div class="field"><label for="funding-source">Funding arrangement</label><select id="funding-source" name="funding_source"><?php if ($budgetValues['funding_source'] === 'combined'): ?><option value="combined" selected hidden>Institutional and participant funding (existing)</option><?php endif; ?><option value="organizational" <?= $budgetValues['funding_source'] === 'organizational' ? 'selected' : '' ?>>Institutional budget</option><option value="participants" <?= $budgetValues['funding_source'] === 'participants' ? 'selected' : '' ?>>Participant-funded</option><option value="free" <?= $budgetValues['funding_source'] === 'free' ? 'selected' : '' ?>>Free of charge</option></select></div>
              <?php if (!$isConductedActivityCreation): ?><div class="field" id="budget-status-field" <?= in_array($budgetValues['funding_source'], ['free', 'participants'], true) ? 'hidden' : '' ?>><label for="budget-status">Budget status</label><select id="budget-status" name="budget_status"><option value="pending" <?= $budgetValues['budget_status'] === 'pending' ? 'selected' : '' ?>>Pending — details may change</option><option value="confirmed" <?= $budgetValues['budget_status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed — details verified</option></select></div><?php endif; ?>
              <div class="field" id="organizational-allocation-field" <?= in_array($budgetValues['funding_source'], ['free', 'participants'], true) ? 'hidden' : '' ?>><label for="organizational-allocation">Institutional budget</label><input id="organizational-allocation" name="organizational_allocation" type="number" min="0" step="0.01" value="<?= activityFormEscape((string) ($budgetValues['organizational_allocation'] ?? '')) ?>"><small>Actual organizational expenses are itemized separately below.</small></div>
            </div>
            <div class="field full budget-expenses-wrap">
              <strong>Expense details</strong>
              <p class="budget-intro">Leave the schedule blank for activity-wide costs. Organizational estimates and actuals are separate from participant charges.</p>
              <div class="budget-expenses" id="budget-expenses">
                <?php foreach (array_values($budgetExpenses) as $expenseIndex => $expenseRow): ?>
                  <fieldset class="expense-row" data-budget-expense-row>
                    <div class="expense-row-head"><strong>Expense item <?= $expenseIndex + 1 ?></strong><button class="expense-remove" type="button" data-remove-expense>Remove</button></div>
                    <div class="expense-row-fields">
                      <div class="field"><label>Category</label><select name="budget_expenses[<?= $expenseIndex ?>][expense_category]"><?php foreach (['registration' => 'Registration', 'transport' => 'Transportation / travel', 'accommodation' => 'Accommodation', 'meals' => 'Meals', 'online_fee' => 'Online participation fee', 'other' => 'Other related cost'] as $categoryKey => $categoryLabel): ?><option value="<?= activityFormEscape($categoryKey) ?>" <?= ($expenseRow['expense_category'] ?? 'other') === $categoryKey ? 'selected' : '' ?>><?= activityFormEscape($categoryLabel) ?></option><?php endforeach; ?></select></div>
                      <div class="field"><label>Schedule date</label><select name="budget_expenses[<?= $expenseIndex ?>][schedule_date]"><option value="">Activity-wide</option><?php foreach ($budgetScheduleChoices as $budgetDate): ?><option value="<?= activityFormEscape($budgetDate) ?>" <?= (string) ($expenseRow['schedule_date'] ?? '') === $budgetDate ? 'selected' : '' ?>><?= activityFormEscape(date('M j, Y', strtotime($budgetDate))) ?></option><?php endforeach; ?></select></div>
                      <input type="hidden" name="budget_expenses[<?= $expenseIndex ?>][audience_scope]" value="<?= activityFormEscape((string) ($expenseRow['audience_scope'] ?? 'all')) ?>">
                      <?php if (($expenseRow['audience_scope'] ?? 'all') === 'specific'): ?><input type="hidden" name="budget_expenses[<?= $expenseIndex ?>][audience_user_id]" value="<?= activityFormEscape((string) ($expenseRow['audience_user_id'] ?? '')) ?>"><?php endif; ?>
                      <div class="field" data-participant-cost-field <?= $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant charge</label><select name="budget_expenses[<?= $expenseIndex ?>][charge_type]"><option value="none" <?= ($expenseRow['charge_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>No participant charge</option><?php if (($expenseRow['charge_type'] ?? 'none') === 'optional'): ?><option value="optional" selected hidden>Optional contribution (existing)</option><?php endif; ?><option value="mandatory" <?= ($expenseRow['charge_type'] ?? 'none') === 'mandatory' ? 'selected' : '' ?>>Mandatory fee / cost</option></select></div>
                      <input type="hidden" name="budget_expenses[<?= $expenseIndex ?>][organizational_estimated_amount]" value="<?= activityFormEscape((string) ($expenseRow['organizational_estimated_amount'] ?? '')) ?>">
                      <input type="hidden" name="budget_expenses[<?= $expenseIndex ?>][organizational_actual_amount]" value="<?= activityFormEscape((string) ($expenseRow['organizational_actual_amount'] ?? '')) ?>">
                      <div class="field expense-estimate-field" data-participant-cost-field <?= !$isUpcomingActivity || $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant estimate (₱)</label><input name="budget_expenses[<?= $expenseIndex ?>][participant_estimated_charge]" type="number" min="0" step="0.01" value="<?= activityFormEscape((string) ($expenseRow['participant_estimated_charge'] ?? '')) ?>"></div>
                      <div class="field expense-actual-field" data-participant-cost-field <?= $isUpcomingActivity || $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant actual (₱)</label><input name="budget_expenses[<?= $expenseIndex ?>][participant_actual_charge]" type="number" min="0" step="0.01" value="<?= activityFormEscape((string) ($expenseRow['participant_actual_charge'] ?? '')) ?>"></div>
                      <div class="field full"><label>Description</label><input name="budget_expenses[<?= $expenseIndex ?>][description]" maxlength="255" value="<?= activityFormEscape((string) ($expenseRow['description'] ?? '')) ?>" placeholder="Optional cost details"></div>
                    </div>
                  </fieldset>
                <?php endforeach; ?>
              </div>
              <div class="budget-tools"><button class="button secondary budget-add" id="add-budget-expense" type="button">Add expense item</button></div>
            </div>
            <?php if ($isUpcomingActivity): ?>
              <label class="budget-check" id="contribution-prompt-wrap" <?= $budgetValues['budget_status'] !== 'pending' || in_array($budgetValues['funding_source'], ['free', 'participants'], true) ? 'hidden' : '' ?>><input id="contribution-prompt" type="checkbox" name="contribution_prompt" value="1" <?= !empty($budgetValues['contribution_prompt']) && $budgetValues['budget_status'] === 'pending' && !in_array($budgetValues['funding_source'], ['free', 'participants'], true) ? 'checked' : '' ?> <?= $budgetValues['budget_status'] !== 'pending' || in_array($budgetValues['funding_source'], ['free', 'participants'], true) ? 'disabled' : '' ?>><span>Ask invited participants whether they are willing to contribute when costs are optional or still pending. Mandatory fees will be disclosed as required costs.</span></label>
            <?php else: ?>
              <p class="budget-intro">This completed activity records actual costs and attendance. Contribution willingness and individual payments are not collected.</p>
            <?php endif; ?>
            <div class="budget-notice" id="budget-notice-wrap" <?= $budgetValues['funding_source'] === 'free' ? 'hidden' : '' ?>>
              <strong>Notice preview</strong><div class="notice-preview" id="budget-notice-preview" role="status" aria-live="polite"></div>
              <input type="hidden" name="budget_notice_confirmation_key" value="<?= activityFormEscape($budgetNoticeConfirmationKey) ?>">
              <label class="budget-check"><input id="send-budget-notice" type="checkbox" name="send_budget_notice" value="1" <?= !empty($budgetNoticeValues['confirmed']) && $budgetValues['funding_source'] !== 'free' ? 'checked' : '' ?> <?= $budgetValues['funding_source'] === 'free' ? 'disabled' : '' ?>><span>I reviewed this notice and explicitly confirm sending it to the activity <?= $isUpcomingActivity ? 'invitees' : 'participants' ?> after the activity and budget are saved.</span></label>
            </div>
          </fieldset>
        <?php endif; ?>
      </div>
      <div class="form-actions"><a class="button secondary" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . ($modulePage !== '' ? '&module_page=' . rawurlencode($modulePage) : '') : $activityListUrl) ?>">Cancel</a><button class="button" id="activity-create-submit" type="submit"><?= $isEdit ? 'Save changes' : 'Create ' . activityFormEscape($selectedActivityLabel) ?></button></div>
    </form>
  </section>
</div>

<?php if (!$isEdit): ?>
<dialog class="activity-review-dialog" id="activity-review-dialog" aria-labelledby="activity-review-title" aria-describedby="activity-review-description">
  <div class="activity-review-header">
    <p class="activity-review-eyebrow">Final review</p>
    <h2 id="activity-review-title">Review activity details</h2>
    <p id="activity-review-description">Check the schedule and information below before creating this activity.</p>
  </div>
  <div class="activity-review-content" id="activity-review-content"></div>
  <div class="activity-review-footer">
    <button class="button secondary" id="activity-review-cancel" type="button">Cancel</button>
    <button class="button review-confirm" id="activity-review-confirm" type="button">Confirm</button>
  </div>
</dialog>
<script>
(() => {
  const form = document.getElementById('activity-form');
  const dialog = document.getElementById('activity-review-dialog');
  const content = document.getElementById('activity-review-content');
  const confirmButton = document.getElementById('activity-review-confirm');
  const cancelButton = document.getElementById('activity-review-cancel');
  const createButton = document.getElementById('activity-create-submit');
  if (!form || !dialog || !content || !confirmButton || !cancelButton || !createButton) return;

  let confirmed = false;
  let returnFocusTo = createButton;

  function fieldLabel(control) {
    const field = control.closest('.field');
    const label = field?.querySelector('label[for="' + control.id + '"]') || field?.querySelector('label');
    if (!label) return '';
    const copy = label.cloneNode(true);
    copy.querySelectorAll('.required, small').forEach((item) => item.remove());
    return copy.textContent.replace(/\s+/g, ' ').trim();
  }

  function controlValue(control) {
    if (control.type === 'checkbox') return control.checked ? 'Yes' : '';
    if (control.tagName === 'SELECT') return control.selectedOptions[0]?.textContent.trim() || '';
    if (control.type === 'time' && control.value) {
      const [hour, minute] = control.value.split(':').map(Number);
      const date = new Date();
      date.setHours(hour, minute, 0, 0);
      return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    return control.value.trim();
  }

  function formatDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    if (!match) return value || '';
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
      .toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function addSection(title, entries) {
    const visibleEntries = entries.filter((entry) => entry.label && entry.value);
    if (!visibleEntries.length) return;
    const section = document.createElement('section');
    section.className = 'activity-review-section';
    const heading = document.createElement('h3');
    heading.textContent = title;
    const list = document.createElement('dl');
    list.className = 'activity-review-list';
    visibleEntries.forEach(({ label, value }) => {
      const term = document.createElement('dt');
      const detail = document.createElement('dd');
      term.textContent = label;
      detail.textContent = value;
      list.append(term, detail);
    });
    section.append(heading, list);
    content.append(section);
  }

  function summarizeControls(controls) {
    const entries = [];
    controls.forEach((control) => {
      if (control.disabled || control.closest('[hidden]') || control.type === 'hidden' || control.type === 'button' || control.type === 'submit') return;
      if (control.type === 'checkbox' && !control.checked) return;
      const label = fieldLabel(control);
      const value = controlValue(control);
      if (label && value) entries.push({ label, value });
    });
    return entries;
  }

  function buildReview() {
    content.replaceChildren();
    const type = document.getElementById('activity-type');
    const title = document.getElementById('activity-title');
    const customType = document.getElementById('custom-activity-type');
    addSection('Activity', [
      { label: 'Type', value: type?.value === 'other' ? (customType?.value.trim() || 'Other') : (type ? controlValue(type) : '') },
      { label: 'Title', value: title?.value.trim() || '' },
      ...summarizeControls([
        document.getElementById('activity-venue'),
        document.getElementById('activity-speaker'),
        document.getElementById('activity-organizer'),
        document.getElementById('activity-description'),
      ].filter(Boolean)),
    ]);

    const scheduleEntries = [];
    const startDate = document.getElementById('window-start');
    const endDate = document.getElementById('window-end');
    if (startDate?.value) scheduleEntries.push({ label: 'Start date', value: formatDate(startDate.value) });
    if (document.getElementById('multi-day-toggle')?.checked && endDate?.value) {
      scheduleEntries.push({ label: 'End date', value: formatDate(endDate.value) });
      const selectedDates = Array.from(document.querySelectorAll('.schedule-date-choice input:checked'))
        .map((input) => input.closest('label')?.textContent.trim())
        .filter(Boolean);
      if (selectedDates.length) scheduleEntries.push({ label: 'Session dates', value: selectedDates.join('\n') });
    }
    if (document.getElementById('multi-day-toggle')?.checked) {
      document.querySelectorAll('.schedule-date-card').forEach((card) => {
        const date = card.querySelector('h3')?.textContent.trim() || 'Session';
        const controls = Array.from(card.querySelectorAll('input[name*="start_time"], input[name*="end_time"], input[name*="venue_override"], input[name*="speaker_override"], select.date-audience'));
        controls.forEach((control) => {
          const label = fieldLabel(control);
          const value = controlValue(control);
          if (label && value) scheduleEntries.push({ label: date + ' · ' + label, value });
        });
        const selectedUsers = Array.from(card.querySelectorAll('.recipient-user-choice input:checked'))
          .map((input) => input.closest('.recipient-user-choice')?.querySelector('span')?.textContent.trim())
          .filter(Boolean);
        if (selectedUsers.length) scheduleEntries.push({ label: date + ' · Selected participants', value: selectedUsers.join(', ') });
        const selectedRoles = Array.from(card.querySelectorAll('[data-custom-roles] input:checked'))
          .map((input) => input.closest('label')?.textContent.trim())
          .filter(Boolean);
        if (selectedRoles.length) scheduleEntries.push({ label: date + ' · Included roles', value: selectedRoles.join(', ') });
      });
    } else {
      const singleControls = Array.from(form.querySelectorAll('[name^="single_schedule["]'))
        .filter((control) => /start_time|end_time|venue_override|speaker_override|recipient_scope/.test(control.name));
      singleControls.forEach((control) => {
        const label = fieldLabel(control);
        const value = controlValue(control);
        if (label && value) scheduleEntries.push({ label, value });
      });
      const singleUsers = Array.from(form.querySelectorAll('[name="single_schedule[recipient_user_ids][]"]:checked'))
        .map((input) => input.closest('.recipient-user-choice')?.querySelector('span')?.textContent.trim())
        .filter(Boolean);
      if (singleUsers.length) scheduleEntries.push({ label: 'Selected participants', value: singleUsers.join(', ') });
      const singleRoles = Array.from(form.querySelectorAll('[name="single_schedule[recipient_roles][]"]:checked'))
        .map((input) => input.closest('label')?.textContent.trim())
        .filter(Boolean);
      if (singleRoles.length) scheduleEntries.push({ label: 'Included roles', value: singleRoles.join(', ') });
    }
    addSection('Schedule and participants', scheduleEntries);

    const budget = document.querySelector('.budget-section');
    if (budget) {
      const supportsParticipantCharges = ['participants', 'combined'].includes(document.getElementById('funding-source')?.value);
      const budgetControls = Array.from(budget.querySelectorAll('.budget-grid .field input, .budget-grid .field select'));
      const budgetEntries = summarizeControls(budgetControls);
      budget.querySelectorAll('.expense-row').forEach((row, index) => {
        const itemEntries = summarizeControls(Array.from(row.querySelectorAll('.field input, .field select')))
          .filter((entry) => supportsParticipantCharges || !/^Participant (?:charge|estimate|actual)\b/.test(entry.label))
          .filter((entry) => entry.label !== 'Participant charge' || entry.value !== 'No participant charge');
        itemEntries.forEach((entry) => budgetEntries.push({ label: 'Expense ' + (index + 1) + ' · ' + entry.label, value: entry.value }));
      });
      const contributionPrompt = budget.querySelector('[name="contribution_prompt"]');
      if (contributionPrompt?.checked) budgetEntries.push({ label: 'Contribution prompt', value: 'Enabled' });
      if (budget.querySelector('[name="send_budget_notice"]')?.checked) {
        budgetEntries.push({ label: 'Budget notice', value: 'Confirmed to send after saving' });
      }
      addSection('Budget and participation', budgetEntries);
    }
  }

  form.addEventListener('submit', (event) => {
    if (event.submitter === document.getElementById('refresh-window') || confirmed) return;
    event.preventDefault();
    returnFocusTo = event.submitter || createButton;
    buildReview();
    dialog.showModal();
    cancelButton.focus();
  });

  confirmButton.addEventListener('click', () => {
    confirmed = true;
    dialog.close();
    form.requestSubmit(createButton);
  });
  cancelButton.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => returnFocusTo?.focus());
})();
</script>
<?php endif; ?>

<?php if ($budgetSchemaAvailable): ?>
<style>.expense-row-fields>.field[hidden]{display:none!important}</style>
<template id="budget-expense-template">
  <fieldset class="expense-row" data-budget-expense-row>
    <div class="expense-row-head"><strong>Expense item</strong><button class="expense-remove" type="button" data-remove-expense>Remove</button></div>
    <div class="expense-row-fields">
      <div class="field"><label>Category</label><select name="budget_expenses[__INDEX__][expense_category]"><option value="registration">Registration</option><option value="transport">Transportation / travel</option><option value="accommodation">Accommodation</option><option value="meals">Meals</option><option value="online_fee">Online participation fee</option><option value="other" selected>Other related cost</option></select></div>
      <div class="field"><label>Schedule date</label><select name="budget_expenses[__INDEX__][schedule_date]"><option value="">Activity-wide</option><?php foreach ($budgetScheduleChoices as $budgetDate): ?><option value="<?= activityFormEscape($budgetDate) ?>"><?= activityFormEscape(date('M j, Y', strtotime($budgetDate))) ?></option><?php endforeach; ?></select></div>
      <input type="hidden" name="budget_expenses[__INDEX__][audience_scope]" value="all">
      <input type="hidden" name="budget_expenses[__INDEX__][audience_user_id]" value="">
      <div class="field" data-participant-cost-field <?= $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant charge</label><select name="budget_expenses[__INDEX__][charge_type]"><option value="none">No participant charge</option><option value="mandatory">Mandatory fee / cost</option></select></div>
      <input type="hidden" name="budget_expenses[__INDEX__][organizational_estimated_amount]" value="">
      <input type="hidden" name="budget_expenses[__INDEX__][organizational_actual_amount]" value="">
      <div class="field expense-estimate-field" data-participant-cost-field <?= !$isUpcomingActivity || $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant estimate (₱)</label><input name="budget_expenses[__INDEX__][participant_estimated_charge]" type="number" min="0" step="0.01"></div>
      <div class="field expense-actual-field" data-participant-cost-field <?= $isUpcomingActivity || $budgetValues['funding_source'] === 'organizational' ? 'hidden' : '' ?>><label>Participant actual (₱)</label><input name="budget_expenses[__INDEX__][participant_actual_charge]" type="number" min="0" step="0.01"></div>
      <div class="field full"><label>Description</label><input name="budget_expenses[__INDEX__][description]" maxlength="255" placeholder="Optional cost details"></div>
    </div>
  </fieldset>
</template>
<script>
const budgetExpenseList = document.getElementById('budget-expenses');
const budgetExpenseTemplate = document.getElementById('budget-expense-template');
let nextBudgetExpenseIndex = <?= count($budgetExpenses) ?>;
const useExpenseEstimates = <?= $isUpcomingActivity ? 'true' : 'false' ?>;
document.getElementById('add-budget-expense')?.addEventListener('click', () => {
  if (!budgetExpenseList || !budgetExpenseTemplate || budgetExpenseList.children.length >= 50) return;
  const html = budgetExpenseTemplate.innerHTML.replaceAll('__INDEX__', String(nextBudgetExpenseIndex++));
  budgetExpenseList.insertAdjacentHTML('beforeend', html);
  updateBudgetNoticePreview();
});
budgetExpenseList?.addEventListener('click', (event) => {
  const removeButton = event.target.closest('[data-remove-expense]');
  if (removeButton && budgetExpenseList.children.length > 1) {
    removeButton.closest('[data-budget-expense-row]')?.remove();
    updateBudgetNoticePreview();
  }
});
function updateBudgetNoticePreview() {
  const sourceSelect = document.getElementById('funding-source');
  const statusSelect = document.getElementById('budget-status');
  const isFreeFunding = sourceSelect?.value === 'free';
  const isOrganizationalFunding = sourceSelect?.value === 'organizational';
  const isParticipantFunding = sourceSelect?.value === 'participants';
  const canAskForContributions = statusSelect?.value === 'pending' && !isFreeFunding && !isParticipantFunding;
  const hasMandatoryParticipantCharge = Array.from(budgetExpenseList?.querySelectorAll('[data-budget-expense-row]') || []).some((row) => {
    const chargeType = row.querySelector('[name$="[charge_type]"]')?.value;
    const amountName = useExpenseEstimates ? '[name$="[participant_estimated_charge]"]' : '[name$="[participant_actual_charge]"]';
    const amount = row.querySelector(amountName)?.value.trim();
    return chargeType === 'mandatory' && Boolean(amount);
  });
  const participantFundingRequirement = document.getElementById('participant-funding-requirement');
  if (participantFundingRequirement) participantFundingRequirement.hidden = !isParticipantFunding;
  const participantChargeControls = Array.from(budgetExpenseList?.querySelectorAll('[name$="[charge_type]"]') || []);
  const participantChargeError = 'Choose Mandatory fee / cost for at least one Participant charge and enter its amount before saving a participant-funded activity.';
  sourceSelect?.setCustomValidity('');
  participantChargeControls.forEach((control, index) => {
    control.required = isParticipantFunding && index === 0;
    control.setCustomValidity(isParticipantFunding && !hasMandatoryParticipantCharge && index === 0
      ? participantChargeError
      : '');
  });
  if (isParticipantFunding && !participantChargeControls.length) {
    sourceSelect?.setCustomValidity(participantChargeError);
  }
  const organizationalAllocationField = document.getElementById('organizational-allocation-field');
  if (organizationalAllocationField) organizationalAllocationField.hidden = isFreeFunding || isParticipantFunding;
  const budgetStatusField = document.getElementById('budget-status-field');
  if (budgetStatusField) budgetStatusField.hidden = isFreeFunding || isParticipantFunding;
  const expenseDetails = document.querySelector('.budget-expenses-wrap');
  if (expenseDetails) expenseDetails.hidden = isFreeFunding;
  budgetExpenseList?.querySelectorAll('[data-participant-cost-field]').forEach((field) => {
    const wrongLifecycleAmount = (useExpenseEstimates && field.classList.contains('expense-actual-field'))
      || (!useExpenseEstimates && field.classList.contains('expense-estimate-field'));
    field.hidden = isOrganizationalFunding || wrongLifecycleAmount;
  });
  const contributionPromptWrap = document.getElementById('contribution-prompt-wrap');
  const contributionPrompt = document.getElementById('contribution-prompt');
  if (contributionPromptWrap) contributionPromptWrap.hidden = !canAskForContributions;
  if (contributionPrompt) {
    contributionPrompt.disabled = !canAskForContributions;
    if (!canAskForContributions) contributionPrompt.checked = false;
  }
  const budgetNoticeWrap = document.getElementById('budget-notice-wrap');
  const sendBudgetNotice = document.getElementById('send-budget-notice');
  if (budgetNoticeWrap) budgetNoticeWrap.hidden = isFreeFunding;
  if (sendBudgetNotice) {
    sendBudgetNotice.disabled = isFreeFunding;
    if (isFreeFunding) sendBudgetNotice.checked = false;
  }
  const sourceLabels = {organizational:'The activity is funded by an institutional budget.', participants:'Participant contributions fund the activity.', combined:'The activity uses institutional funding and participant contributions.', free:'Participation is free of charge.'};
  const formatLabels = {on_site:'on-site', online:'online', hybrid:'hybrid'};
  const lines = [sourceLabels[sourceSelect?.value] || 'Funding details are being finalized.'];
  lines.push(`Participation format: ${formatLabels[document.getElementById('activity-format')?.value] || 'not specified'}.`);
  if (statusSelect?.value === 'pending') lines.push('Funding arrangements and expenses are still being finalized and may change.');
  const charges = [];
  budgetExpenseList?.querySelectorAll('[data-budget-expense-row]').forEach((row) => {
    if (isOrganizationalFunding) return;
    const chargeType = row.querySelector('[name$="[charge_type]"]')?.value || 'none';
    const category = row.querySelector('[name$="[expense_category]"]')?.selectedOptions[0]?.textContent || 'Participant cost';
    const participantAmountName = useExpenseEstimates ? '[name$="[participant_estimated_charge]"]' : '[name$="[participant_actual_charge]"]';
    const participantAmount = row.querySelector(participantAmountName)?.value;
    if (chargeType === 'none' && !participantAmount) return;
    const amount = participantAmount ? `₱${Number(participantAmount).toFixed(2)}` : 'amount to be confirmed';
    const audience = row.querySelector('[name$="[audience_scope]"]')?.value || 'all';
    const audienceLabels = {all:'',student:' for students',faculty:' for faculty',staff:' for staff',specific:' for a selected user'};
    const dateSelect = row.querySelector('[name$="[schedule_date]"]');
    const date = dateSelect?.value ? ` on ${dateSelect.selectedOptions[0].textContent}` : '';
    const chargeLabel = chargeType === 'mandatory' ? 'Required' : (chargeType === 'optional' ? 'Optional' : 'Recorded participant cost');
    charges.push(`${chargeLabel} ${category}${audienceLabels[audience] || ''}${date}: ${amount}.`);
  });
  if (charges.length) lines.push([...new Set(charges)].join(' '));
  else if (sourceSelect?.value !== 'free') lines.push('No participant registration fee or other participant expense is currently listed.');
  const preview = document.getElementById('budget-notice-preview');
  if (preview) preview.textContent = lines.join('\n\n');
}
document.getElementById('funding-source')?.addEventListener('change', updateBudgetNoticePreview);
document.getElementById('activity-format')?.addEventListener('change', updateBudgetNoticePreview);
document.getElementById('budget-status')?.addEventListener('change', updateBudgetNoticePreview);
budgetExpenseList?.addEventListener('input', updateBudgetNoticePreview);
budgetExpenseList?.addEventListener('change', updateBudgetNoticePreview);
updateBudgetNoticePreview();
</script>
<?php endif; ?>

<?php if ($scheduleTablesAvailable): ?>
<script>
const multiDayToggle = document.getElementById('multi-day-toggle');
const endDateField = document.getElementById('window-end-field');
const endDateInput = document.getElementById('window-end');
const startDateInput = document.getElementById('window-start');
const dateChoices = document.getElementById('schedule-date-choices');
const multiDaySessionDetails = document.getElementById('activity-session-details');
const singleDaySessionDetails = document.getElementById('activity-single-session-details');
const sessionDetailsUpdateHint = document.getElementById('schedule-details-update-hint');
function hideSessionDetails(panel) {
  if (!panel) return;
  panel.hidden = true;
  panel.querySelectorAll('[required]').forEach((control) => { control.required = false; });
}
function updateScheduleWindowControls() {
  const isMultiDay = Boolean(multiDayToggle?.checked);
  if (isMultiDay) hideSessionDetails(singleDaySessionDetails);
  if (endDateField) endDateField.hidden = !isMultiDay;
  if (endDateInput) {
    endDateInput.disabled = !isMultiDay;
    endDateInput.required = isMultiDay;
  }
  if (dateChoices) dateChoices.hidden = !isMultiDay;
  if (sessionDetailsUpdateHint) {
    const detailsWaiting = isMultiDay
      ? (!multiDaySessionDetails || multiDaySessionDetails.hidden)
      : Boolean(singleDaySessionDetails?.hidden);
    sessionDetailsUpdateHint.hidden = !detailsWaiting;
  }
}
function updateDateAudience(card) {
  const selector = card.querySelector('.date-audience');
  const userPicker = card.querySelector('.specific-date-users');
  const customRoles = card.querySelector('[data-custom-roles]');
  if (!selector || !userPicker) return;
  const scope = selector.value;
  const allowsIndividualUsers = scope === 'specific' || scope === 'custom';
  card.dataset.audience = selector.value;
  userPicker.hidden = !allowsIndividualUsers;
  userPicker.querySelectorAll('[data-recipient-user] input').forEach((checkbox) => {
    checkbox.disabled = !allowsIndividualUsers;
  });
  userPicker.querySelectorAll('[data-select-visible], [data-clear-visible]').forEach((button) => {
    button.disabled = !allowsIndividualUsers;
  });
  customRoles?.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
    checkbox.disabled = scope !== 'custom';
  });
  if (customRoles) customRoles.hidden = scope !== 'custom';
  const heading = userPicker.querySelector('[data-specific-required]');
  const optional = userPicker.querySelector('[data-custom-optional]');
  if (heading) heading.hidden = scope !== 'specific';
  if (optional) optional.hidden = scope !== 'custom';
}
function setupRecipientPicker(picker) {
  const users = Array.from(picker.querySelectorAll('[data-recipient-user]'));
  const roleFilter = picker.querySelector('[data-role-filter]');
  const affiliationFilter = picker.querySelector('[data-affiliation-filter]');
  const affiliationLabel = picker.querySelector('[data-affiliation-label]');
  const filterControls = picker.querySelector('[data-recipient-filters]');
  const actions = picker.querySelector('[data-recipient-actions]');
  const status = picker.querySelector('[data-filter-status]');
  const noMatches = picker.querySelector('[data-filter-empty]');
  const selectVisibleButton = picker.querySelector('[data-select-visible]');
  const clearVisibleButton = picker.querySelector('[data-clear-visible]');
  if (!roleFilter || !affiliationFilter || !affiliationLabel || !filterControls || !actions) return;

  function affiliationKey(user) {
    const value = (user.dataset.affiliation || '').trim().toLocaleLowerCase();
    return `${user.dataset.affiliationType}:${value || '__unspecified__'}`;
  }

  function updateVisibleUsers() {
    const role = roleFilter.value;
    const affiliation = affiliationFilter.value;
    let visibleCount = 0;
    let visibleSelectedCount = 0;
    let selectedCount = 0;
    const selectedByRole = {};
    users.forEach((user) => {
      const checkbox = user.querySelector('input[type="checkbox"]');
      const visible = (role === 'any' || user.dataset.role === role)
        && (affiliation === 'any' || affiliationKey(user) === affiliation);
      user.hidden = !visible;
      if (checkbox.checked) {
        selectedCount += 1;
        const selectedRole = user.dataset.role || 'other';
        selectedByRole[selectedRole] = (selectedByRole[selectedRole] || 0) + 1;
      }
      if (visible) {
        visibleCount += 1;
        if (checkbox.checked) visibleSelectedCount += 1;
      }
    });
    if (status) {
      const roleLabels = {
        student: ['student', 'students'],
        faculty: ['faculty', 'faculty'],
        research_staff: ['staff member', 'staff members'],
        other: ['other account', 'other accounts'],
      };
      const selectedRoleSummary = Object.entries(selectedByRole)
        .map(([selectedRole, count]) => `${count} ${(roleLabels[selectedRole] || roleLabels.other)[count === 1 ? 0 : 1]}`)
        .join(', ');
      status.textContent = `Showing ${visibleCount} of ${users.length} accounts; ${selectedCount} selected overall (${visibleSelectedCount} visible).${selectedCount ? ` Selected by role: ${selectedRoleSummary}.` : ''}`;
    }
    if (noMatches) noMatches.hidden = visibleCount > 0;
    if (selectVisibleButton) selectVisibleButton.textContent = `Select visible users (${visibleCount})`;
    if (clearVisibleButton) clearVisibleButton.textContent = `Clear visible selections (${visibleSelectedCount})`;
  }

  function refreshAffiliations() {
    const role = roleFilter.value;
    const previousValue = affiliationFilter.value;
    const candidates = users.filter((user) => role === 'any' || user.dataset.role === role);
    const affiliations = new Map();
    candidates.forEach((user) => {
      const key = affiliationKey(user);
      const type = user.dataset.affiliationType;
      const value = (user.dataset.affiliation || '').trim();
      const typeLabel = type === 'course' ? 'Course' : 'Department';
      const displayValue = value || (type === 'course' ? 'Course / program not specified' : 'Department not specified');
      affiliations.set(key, {
        label: role === 'any' ? `${typeLabel}: ${displayValue}` : displayValue,
        sort: `${typeLabel} ${displayValue}`.toLocaleLowerCase(),
      });
    });
    const sortedAffiliations = Array.from(affiliations.entries())
      .sort((first, second) => first[1].sort.localeCompare(second[1].sort));
    affiliationFilter.replaceChildren(new Option('All affiliations', 'any'));
    sortedAffiliations.forEach(([key, item]) => {
      affiliationFilter.add(new Option(item.label, key));
    });
    affiliationFilter.value = affiliations.has(previousValue) ? previousValue : 'any';
    affiliationLabel.textContent = role === 'student'
      ? 'Course / program'
      : (role === 'any' ? 'Course / department' : 'Department');
    updateVisibleUsers();
  }

  roleFilter.addEventListener('change', refreshAffiliations);
  affiliationFilter.addEventListener('change', updateVisibleUsers);
  users.forEach((user) => {
    user.querySelector('input[type="checkbox"]')?.addEventListener('change', updateVisibleUsers);
  });
  selectVisibleButton?.addEventListener('click', () => {
    if (users.length > 0 && users.every((user) => !user.hidden)
      && !window.confirm(`This will select all ${users.length} eligible accounts for this date. Continue?`)) {
      return;
    }
    users.forEach((user) => {
      if (!user.hidden) user.querySelector('input[type="checkbox"]').checked = true;
    });
    updateVisibleUsers();
  });
  clearVisibleButton?.addEventListener('click', () => {
    users.forEach((user) => {
      if (!user.hidden) user.querySelector('input[type="checkbox"]').checked = false;
    });
    updateVisibleUsers();
  });
  filterControls.hidden = false;
  actions.hidden = false;
  refreshAffiliations();
}
multiDayToggle?.addEventListener('change', (event) => {
  const isMultiDay = event.currentTarget.checked;
  if (!isMultiDay) {
    const firstSelectedDate = dateChoices?.querySelector('input[data-schedule-date]:checked')?.dataset.scheduleDate;
    if (firstSelectedDate && startDateInput) startDateInput.value = firstSelectedDate;
    if (startDateInput && endDateInput) endDateInput.value = startDateInput.value;
  }
  updateScheduleWindowControls();
});
document.querySelectorAll('.schedule-date-card').forEach((card) => {
  const selector = card.querySelector('.date-audience');
  selector?.addEventListener('change', () => updateDateAudience(card));
  updateDateAudience(card);
});
document.querySelectorAll('.specific-date-users').forEach(setupRecipientPicker);
updateScheduleWindowControls();
</script>
<?php endif; ?>

<script>
(() => {
  const errorSummary = document.getElementById('activity-form-errors');
  if (!errorSummary) return;
  const form = document.getElementById('activity-form');
  const monthPattern = /\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+\d{4}\b/;
  const find = (selector) => form?.querySelector(selector) || null;
  const fieldFor = (control) => control?.closest('.field, .budget-check') || control;
  const dateCardFor = (message) => {
    const dateLabel = message.match(monthPattern)?.[0];
    if (!dateLabel) return null;
    return Array.from(form?.querySelectorAll('.schedule-date-card') || [])
      .find((card) => card.dataset.errorDate === dateLabel) || null;
  };
  const budgetExpenseField = (suffix) => fieldFor(find(`.expense-row [name$="[${suffix}]"]`));

  function resolveErrorTargets(message) {
    const lowerMessage = message.toLowerCase();
    const dateCard = dateCardFor(message);
    if (dateCard) {
      const times = dateCard.querySelectorAll('input[type="time"]');
      if (/both a start time and end time/.test(lowerMessage)) return Array.from(times).map(fieldFor);
      if (/end time/.test(lowerMessage)) return [fieldFor(times[1])];
      if (/start time/.test(lowerMessage)) return [fieldFor(times[0])];
      if (/venue override/.test(lowerMessage)) return [fieldFor(dateCard.querySelector('input[id^="venue-"]'))];
      if (/speaker override/.test(lowerMessage)) return [fieldFor(dateCard.querySelector('input[id^="speaker-"]'))];
      if (/audience roles|roles can only be selected/.test(lowerMessage)) {
        return [dateCard.querySelector('[data-custom-roles]') || fieldFor(dateCard.querySelector('.date-audience'))];
      }
      if (/role or individual recipient/.test(lowerMessage)) {
        return [dateCard.querySelector('[data-custom-roles]'), dateCard.querySelector('.specific-date-users')];
      }
      if (/recipient|no active users/.test(lowerMessage)) return [dateCard.querySelector('.specific-date-users') || dateCard];
      if (/audience/.test(lowerMessage)) return [fieldFor(dateCard.querySelector('.date-audience'))];
      return [dateCard];
    }

    if (/activity type/.test(lowerMessage)) return [fieldFor(find('#activity-type'))];
    if (/title/.test(lowerMessage)) return [fieldFor(find('#activity-title'))];
    if (/organizer/.test(lowerMessage)) return [fieldFor(find('#activity-organizer'))];
    if (/description/.test(lowerMessage)) return [fieldFor(find('#activity-description'))];
    if (/activity date/.test(lowerMessage)) return [fieldFor(find('#window-start'))];
    if (/activity time/.test(lowerMessage)) return [fieldFor(form?.querySelector('.schedule-date-card input[type="time"]'))];
    if (/speaker override/.test(lowerMessage)) return [find('#activity-schedule-section')];
    if (/venue override/.test(lowerMessage)) return [find('#activity-schedule-section')];
    if (/speaker/.test(lowerMessage)) return [fieldFor(find('#activity-speaker'))];
    if (/venue/.test(lowerMessage)) return [fieldFor(find('#activity-venue'))];
    if (/schedule type/.test(lowerMessage)) return [find('.multi-day-toggle')];
    if (/valid activity date window/.test(lowerMessage)) return [fieldFor(find('#window-start')), fieldFor(find('#window-end'))];
    if (/schedule end date|schedule window/.test(lowerMessage)) return [fieldFor(find('#window-end'))];
    if (/schedule date|at least one date/.test(lowerMessage)) return [find('#schedule-date-choices') || find('#window-start')];
    if (/activity schedule|schedule and invitations/.test(lowerMessage)) return [find('#activity-schedule-section')];
    if (/activity format/.test(lowerMessage)) return [fieldFor(find('#activity-format'))];
    if (/funding source|free activity/.test(lowerMessage)) return [fieldFor(find('#funding-source'))];
    if (/budget status/.test(lowerMessage)) return [fieldFor(find('#budget-status'))];
    if (/organizational allocation/.test(lowerMessage)) return [fieldFor(find('#organizational-allocation'))];
    if (/organizational estimate/.test(lowerMessage)) return [budgetExpenseField('organizational_estimated_amount')];
    if (/actual organizational expense/.test(lowerMessage)) return [budgetExpenseField('organizational_actual_amount')];
    if (/estimated participant cost/.test(lowerMessage)) return [budgetExpenseField('participant_estimated_charge')];
    if (/actual participant cost/.test(lowerMessage)) return [budgetExpenseField('participant_actual_charge')];
    if (/estimated or actual participant amount/.test(lowerMessage)) {
      return [budgetExpenseField('participant_estimated_charge'), budgetExpenseField('participant_actual_charge')];
    }
    if (/expense category/.test(lowerMessage)) return [budgetExpenseField('expense_category')];
    if (/expense.*date|reference a date/.test(lowerMessage)) return [budgetExpenseField('schedule_date')];
    if (/audience for each participant expense/.test(lowerMessage)) return [budgetExpenseField('audience_scope')];
    if (/user-specific expense/.test(lowerMessage)) return [budgetExpenseField('audience_scope')];
    if (/charge type|participant charge/.test(lowerMessage)) return [budgetExpenseField('charge_type')];
    if (/expense descriptions/.test(lowerMessage)) return [budgetExpenseField('description')];
    if (/expense list|expense details|50 budget expense/.test(lowerMessage)) return [find('.budget-expenses-wrap')];
    if (/contribution willingness/.test(lowerMessage)) return [find('[name="contribution_prompt"]')?.closest('.budget-check')];
    if (/budget notice roles|roles for the budget notice/.test(lowerMessage)) return [find('.budget-recipient-options')];
    if (/budget notice recipient|recipient before confirming/.test(lowerMessage)) return [find('.budget-recipient-picker') || find('.budget-recipient-options')];
    if (/recipient/.test(lowerMessage)) return Array.from(form?.querySelectorAll('.specific-date-users') || []);
    return [];
  }

  function addInlineError(target, message, errorIndex, targetIndex) {
    if (!target) return;
    if (target.closest?.('[hidden]')) {
      const dateCard = target.closest('.schedule-date-card');
      target = dateCard?.querySelector('.date-audience') || dateCard || target;
    }
    const placeAfterTarget = target.matches?.('.multi-day-toggle, #schedule-date-choices, .budget-recipient-options, .budget-recipient-picker');
    const control = target.matches?.('input, select, textarea')
      ? target
      : (target.matches?.('.field, .budget-check')
        ? target.querySelector('input:not([type="hidden"]), select, textarea')
        : (target.matches?.('.multi-day-toggle, #schedule-date-choices, .budget-recipient-options')
          ? target.querySelector('input[type="checkbox"]')
          : null));
    const host = target.classList?.contains('field') || target.classList?.contains('budget-check')
      ? target
      : (target.closest?.('.field, .budget-check') || target);
    const note = document.createElement('p');
    note.className = 'activity-field-error';
    note.id = `activity-field-error-${errorIndex}-${targetIndex}`;
    note.textContent = message;
    if (placeAfterTarget) target.insertAdjacentElement('afterend', note);
    else host.append(note);
    if (control) {
      control.setAttribute('aria-invalid', 'true');
      const descriptions = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
      descriptions.add(note.id);
      control.setAttribute('aria-describedby', Array.from(descriptions).join(' '));
    }
    return control || host;
  }

  Array.from(errorSummary.querySelectorAll('li')).forEach((item, errorIndex) => {
    const message = item.textContent.trim();
    const destinations = resolveErrorTargets(message).filter(Boolean).map((target, targetIndex) => (
      addInlineError(target, message, errorIndex, targetIndex)
    ));
    const firstDestination = destinations.find(Boolean);
    if (firstDestination) {
      const focusableSelector = 'input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled), summary, [tabindex]';
      const linkTarget = firstDestination.matches?.(focusableSelector)
        ? firstDestination
        : (firstDestination.querySelector(focusableSelector) || firstDestination);
      if (!linkTarget.id) linkTarget.id = `activity-error-target-${errorIndex}`;
      if (!linkTarget.matches(focusableSelector)) linkTarget.setAttribute('tabindex', '-1');
      const link = document.createElement('a');
      link.href = `#${linkTarget.id}`;
      link.textContent = message;
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const details = linkTarget.closest('details');
        if (details) details.open = true;
        const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        linkTarget.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'center' });
        linkTarget.focus({ preventScroll: true });
      });
      item.replaceChildren(link);
    }
  });
  errorSummary.focus({ preventScroll: true });
  window.setTimeout(() => errorSummary.classList.remove('is-highlighted'), 7000);
})();
</script>
<script>
(() => {
  const form = document.getElementById('activity-form');
  if (!form) return;

  const dateInputs = Array.from(form.querySelectorAll('input[type="date"]'));
  if (!dateInputs.length) return;

  const monthFormatter = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
  const fullDateFormatter = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  const monthDayFormatter = new Intl.DateTimeFormat(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
  const pad = (value) => String(value).padStart(2, '0');
  const asIsoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const fromIsoDate = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
  };
  const sameDate = (first, second) => Boolean(first && second
    && first.getFullYear() === second.getFullYear()
    && first.getMonth() === second.getMonth()
    && first.getDate() === second.getDate());
  const isBefore = (first, second) => Boolean(first && second && asIsoDate(first) < asIsoDate(second));
  const isAfter = (first, second) => Boolean(first && second && asIsoDate(first) > asIsoDate(second));
  let activePicker = null;

  function enhanceDateInput(input) {
    if (input.dataset.calendarEnhanced === 'true') return;
    input.dataset.calendarEnhanced = 'true';
    input.classList.add('is-calendar-enhanced');

    const wrapper = document.createElement('div');
    wrapper.className = 'calendar-picker';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(input);

    const fieldLabel = Array.from(input.labels || []).map((label) => label.textContent.trim().replace(/\s+/g, ' ')).join(' ')
      || 'Choose a date';
    const trigger = document.createElement('button');
    trigger.className = 'calendar-picker-trigger';
    trigger.type = 'button';
    trigger.textContent = '🗓️';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;
    wrapper.append(trigger);

    const panel = document.createElement('section');
    panel.className = 'activity-calendar';
    panel.id = `${input.id || 'activity-date'}-calendar`;
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', `${fieldLabel} calendar`);
    panel.innerHTML = '<div class="activity-calendar-head"><h2 class="activity-calendar-month"></h2><div class="activity-calendar-nav"><button type="button" data-calendar-previous aria-label="Previous month">‹</button><button type="button" data-calendar-next aria-label="Next month">›</button></div></div><div class="activity-calendar-weekdays" aria-hidden="true"></div><div class="activity-calendar-days" role="group"></div><div class="activity-calendar-footer"><button type="button" data-calendar-today>Today</button><button type="button" data-calendar-close>Close</button></div>';
    document.body.append(panel);

    const monthTitle = panel.querySelector('.activity-calendar-month');
    const weekdays = panel.querySelector('.activity-calendar-weekdays');
    const daysGrid = panel.querySelector('.activity-calendar-days');
    trigger.setAttribute('aria-controls', panel.id);
    const today = new Date();
    let viewMonth = fromIsoDate(input.value) || new Date(today.getFullYear(), today.getMonth(), 1);
    let focusedDate = fromIsoDate(input.value) || today;
    viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);

    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach((day) => {
      const weekday = document.createElement('span');
      weekday.textContent = day;
      weekdays.append(weekday);
    });

    function syncTriggerName() {
      const selected = fromIsoDate(input.value);
      trigger.setAttribute('aria-label', selected
        ? `${fieldLabel}. Selected ${fullDateFormatter.format(selected)}. Choose another date.`
        : `${fieldLabel}. Choose a date.`);
    }

    function positionPanel() {
      const bounds = trigger.getBoundingClientRect();
      const panelWidth = Math.min(326, window.innerWidth - 24);
      const panelHeight = panel.offsetHeight;
      const left = Math.max(12, Math.min(bounds.left, window.innerWidth - panelWidth - 12));
      const below = bounds.bottom + 8;
      const top = below + panelHeight <= window.innerHeight - 12
        ? below
        : Math.max(12, bounds.top - panelHeight - 8);
      panel.style.left = `${left}px`;
      panel.style.top = `${top}px`;
    }

    function closePicker(restoreFocus = false) {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (activePicker === closePicker) activePicker = null;
      if (restoreFocus) trigger.focus();
    }

    function selectDate(date) {
      const minimum = fromIsoDate(input.min);
      const maximum = fromIsoDate(input.max);
      if (isBefore(date, minimum) || isAfter(date, maximum)) return;
      input.value = asIsoDate(date);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      syncTriggerName();
      closePicker(true);
    }

    function renderCalendar(focusDate = null) {
      monthTitle.textContent = monthFormatter.format(viewMonth);
      daysGrid.setAttribute('aria-label', monthTitle.textContent);
      daysGrid.replaceChildren();
      const selectedDate = fromIsoDate(input.value);
      const firstOfMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);
      const gridStart = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1 - firstOfMonth.getDay());
      let rovingDate = focusDate || focusedDate || selectedDate || today;
      if (rovingDate.getMonth() !== viewMonth.getMonth() || rovingDate.getFullYear() !== viewMonth.getFullYear()) {
        rovingDate = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);
      }
      const minimum = fromIsoDate(input.min);
      const maximum = fromIsoDate(input.max);
      if (isBefore(rovingDate, minimum) || isAfter(rovingDate, maximum)) {
        const lastDay = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 0).getDate();
        for (let day = 1; day <= lastDay; day += 1) {
          const candidate = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), day);
          if (!isBefore(candidate, minimum) && !isAfter(candidate, maximum)) {
            rovingDate = candidate;
            break;
          }
        }
      }
      focusedDate = rovingDate;
      panel.querySelector('[data-calendar-today]').disabled = isBefore(today, minimum) || isAfter(today, maximum);

      for (let index = 0; index < 42; index += 1) {
        const date = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + index);
        const dayButton = document.createElement('button');
        const isCurrentMonth = date.getMonth() === viewMonth.getMonth();
        const isSelected = sameDate(date, selectedDate);
        dayButton.className = 'activity-calendar-day';
        dayButton.type = 'button';
        dayButton.textContent = String(date.getDate());
        dayButton.setAttribute('aria-label', fullDateFormatter.format(date));
        dayButton.setAttribute('aria-pressed', String(isSelected));
        dayButton.dataset.date = asIsoDate(date);
        dayButton.tabIndex = sameDate(date, rovingDate) ? 0 : -1;
        if (!isCurrentMonth) dayButton.classList.add('is-outside');
        if (isSelected) dayButton.classList.add('is-selected');
        if (sameDate(date, today)) dayButton.classList.add('is-today');
        dayButton.disabled = !isCurrentMonth || isBefore(date, minimum) || isAfter(date, maximum);
        dayButton.addEventListener('click', () => selectDate(date));
        dayButton.addEventListener('keydown', (event) => {
          let nextDate = null;
          if (event.key === 'ArrowLeft') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 1);
          if (event.key === 'ArrowRight') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1);
          if (event.key === 'ArrowUp') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 7);
          if (event.key === 'ArrowDown') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 7);
          if (event.key === 'Home') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() - date.getDay());
          if (event.key === 'End') nextDate = new Date(date.getFullYear(), date.getMonth(), date.getDate() + (6 - date.getDay()));
          if (event.key === 'PageUp') nextDate = new Date(date.getFullYear(), date.getMonth() - 1, date.getDate());
          if (event.key === 'PageDown') nextDate = new Date(date.getFullYear(), date.getMonth() + 1, date.getDate());
          if (!nextDate) return;
          event.preventDefault();
          focusedDate = nextDate;
          viewMonth = new Date(nextDate.getFullYear(), nextDate.getMonth(), 1);
          renderCalendar(nextDate);
          daysGrid.querySelector(`[data-date="${asIsoDate(nextDate)}"]`)?.focus();
        });
        daysGrid.append(dayButton);
      }
      positionPanel();
    }

    function openPicker() {
      if (activePicker && activePicker !== closePicker) activePicker();
      const selectedDate = fromIsoDate(input.value);
      focusedDate = selectedDate || today;
      viewMonth = new Date(focusedDate.getFullYear(), focusedDate.getMonth(), 1);
      renderCalendar(focusedDate);
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      activePicker = closePicker;
      positionPanel();
      daysGrid.querySelector(`[data-date="${asIsoDate(focusedDate)}"]:not(:disabled)`)?.focus();
    }

    trigger.addEventListener('click', openPicker);
    wrapper.addEventListener('click', (event) => {
      if (event.target !== trigger) openPicker();
    });
    input.addEventListener('invalid', () => {
      window.setTimeout(() => {
        openPicker();
        trigger.focus();
      }, 0);
    });
    input.addEventListener('change', syncTriggerName);
    Array.from(input.labels || []).forEach((label) => {
      label.addEventListener('click', (event) => {
        event.preventDefault();
        openPicker();
        trigger.focus();
      });
    });
    panel.querySelector('[data-calendar-previous]').addEventListener('click', () => {
      viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1);
      renderCalendar();
    });
    panel.querySelector('[data-calendar-next]').addEventListener('click', () => {
      viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 1);
      renderCalendar();
    });
    panel.querySelector('[data-calendar-today]').addEventListener('click', () => selectDate(today));
    panel.querySelector('[data-calendar-close]').addEventListener('click', () => closePicker(true));
    panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
      }
    });
    document.addEventListener('pointerdown', (event) => {
      if (!panel.hidden && !panel.contains(event.target) && !wrapper.contains(event.target)) closePicker();
    });
    window.addEventListener('resize', () => { if (!panel.hidden) positionPanel(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) positionPanel(); }, true);
    syncTriggerName();
  }

  dateInputs.forEach(enhanceDateInput);
})();
</script>
<script>
(() => {
  const form = document.getElementById('activity-form');
  if (!form) return;

  const timeInputs = Array.from(form.querySelectorAll('input[type="time"]'));
  if (!timeInputs.length) return;

  const pad = (value) => String(value).padStart(2, '0');
  let activeTimePicker = null;

  function enhanceTimeInput(input) {
    if (input.dataset.timePickerEnhanced === 'true') return;
    input.dataset.timePickerEnhanced = 'true';

    const wrapper = document.createElement('div');
    wrapper.className = 'time-picker';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(input);

    const fieldLabel = Array.from(input.labels || []).map((label) => label.textContent.trim().replace(/\s+/g, ' ')).join(' ')
      || 'Choose a time';
    const panelPrefix = `${(input.id || 'activity-time').replace(/[^a-zA-Z0-9_-]/g, '-')}-picker`;
    const trigger = document.createElement('button');
    trigger.className = 'time-picker-trigger';
    trigger.type = 'button';
    trigger.textContent = '⏰';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;
    wrapper.append(trigger);

    const panel = document.createElement('section');
    panel.className = 'activity-time-panel';
    panel.id = panelPrefix;
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', `${fieldLabel} time picker`);
    panel.innerHTML = `<h2 class="activity-time-heading">Set session time</h2><p class="activity-time-preview" aria-live="polite"></p><div class="activity-time-controls"><div class="activity-time-control"><label for="${panelPrefix}-hour">Hour</label><div class="activity-time-value"><button type="button" data-time-adjust="hour" data-step="1" aria-label="Increase hour">+</button><input id="${panelPrefix}-hour" type="number" min="1" max="12" step="1" inputmode="numeric"><button type="button" data-time-adjust="hour" data-step="-1" aria-label="Decrease hour">−</button></div></div><div class="activity-time-control"><label for="${panelPrefix}-minute">Minute</label><div class="activity-time-value"><button type="button" data-time-adjust="minute" data-step="1" aria-label="Increase minute">+</button><input id="${panelPrefix}-minute" type="number" min="0" max="59" step="1" inputmode="numeric"><button type="button" data-time-adjust="minute" data-step="-1" aria-label="Decrease minute">−</button></div></div><div class="activity-time-control"><span class="field-label">Period</span><div class="activity-time-period" role="group" aria-label="AM or PM"><button type="button" data-time-period="AM" aria-pressed="true">AM</button><button type="button" data-time-period="PM" aria-pressed="false">PM</button></div></div></div><div class="activity-time-actions"><button type="button" data-time-cancel>Cancel</button><button type="button" data-time-apply>Set time</button></div>`;
    document.body.append(panel);
    trigger.setAttribute('aria-controls', panel.id);

    const hourInput = panel.querySelector(`#${panelPrefix}-hour`);
    const minuteInput = panel.querySelector(`#${panelPrefix}-minute`);
    const preview = panel.querySelector('.activity-time-preview');
    const periodButtons = Array.from(panel.querySelectorAll('[data-time-period]'));
    const now = { hour: 9, minute: 0, period: 'AM' };
    let draft = { ...now };

    function syncTriggerName() {
      const match = /^(\d{2}):(\d{2})/.exec(input.value || '');
      if (!match) {
        trigger.setAttribute('aria-label', `${fieldLabel}. Choose a time.`);
        return;
      }
      const hour24 = Number(match[1]);
      const hour12 = (hour24 % 12) || 12;
      const period = hour24 >= 12 ? 'PM' : 'AM';
      trigger.setAttribute('aria-label', `${fieldLabel}. Selected ${hour12}:${match[2]} ${period}. Choose another time.`);
    }

    function positionPanel() {
      const bounds = trigger.getBoundingClientRect();
      const panelWidth = Math.min(326, window.innerWidth - 24);
      const panelHeight = panel.offsetHeight;
      const left = Math.max(12, Math.min(bounds.left, window.innerWidth - panelWidth - 12));
      const below = bounds.bottom + 8;
      const top = below + panelHeight <= window.innerHeight - 12
        ? below
        : Math.max(12, bounds.top - panelHeight - 8);
      panel.style.left = `${left}px`;
      panel.style.top = `${top}px`;
    }

    function closePicker(restoreFocus = false) {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (activeTimePicker === closePicker) activeTimePicker = null;
      if (restoreFocus) trigger.focus();
    }

    function updateDraft() {
      const hour = Math.min(12, Math.max(1, Number(hourInput.value) || 12));
      const minute = Math.min(59, Math.max(0, Number(minuteInput.value) || 0));
      const selectedPeriod = periodButtons.find((button) => button.getAttribute('aria-pressed') === 'true')?.dataset.timePeriod || 'AM';
      draft = { hour, minute, period: selectedPeriod };
      preview.textContent = `${pad(hour)}:${pad(minute)} ${selectedPeriod}`;
    }

    function loadDraftFromInput() {
      const match = /^(\d{2}):(\d{2})/.exec(input.value || '');
      if (!match) {
        draft = { ...now };
      } else {
        const hour24 = Number(match[1]);
        draft = {
          hour: (hour24 % 12) || 12,
          minute: Number(match[2]),
          period: hour24 >= 12 ? 'PM' : 'AM',
        };
      }
      hourInput.value = String(draft.hour);
      minuteInput.value = pad(draft.minute);
      periodButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.timePeriod === draft.period)));
      updateDraft();
    }

    function openPicker() {
      if (activeTimePicker && activeTimePicker !== closePicker) activeTimePicker();
      loadDraftFromInput();
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      activeTimePicker = closePicker;
      positionPanel();
      hourInput.focus();
    }

    trigger.addEventListener('click', openPicker);
    wrapper.addEventListener('click', (event) => {
      if (event.target !== trigger) openPicker();
    });
    input.addEventListener('invalid', () => {
      window.setTimeout(() => {
        openPicker();
        hourInput.focus();
      }, 0);
    });
    input.addEventListener('change', syncTriggerName);
    Array.from(input.labels || []).forEach((label) => {
      label.addEventListener('click', (event) => {
        event.preventDefault();
        openPicker();
      });
    });
    panel.querySelectorAll('[data-time-adjust]').forEach((button) => {
      button.addEventListener('click', () => {
        const targetInput = button.dataset.timeAdjust === 'hour' ? hourInput : minuteInput;
        const minimum = button.dataset.timeAdjust === 'hour' ? 1 : 0;
        const maximum = button.dataset.timeAdjust === 'hour' ? 12 : 59;
        const step = Number(button.dataset.step);
        const current = Number(targetInput.value) || (button.dataset.timeAdjust === 'hour' ? 12 : 0);
        targetInput.value = String(current + step > maximum ? minimum : (current + step < minimum ? maximum : current + step));
        updateDraft();
      });
    });
    periodButtons.forEach((button) => {
      button.addEventListener('click', () => {
        periodButtons.forEach((periodButton) => periodButton.setAttribute('aria-pressed', String(periodButton === button)));
        updateDraft();
      });
    });
    hourInput.addEventListener('input', updateDraft);
    minuteInput.addEventListener('input', updateDraft);
    panel.querySelector('[data-time-cancel]').addEventListener('click', () => closePicker(true));
    panel.querySelector('[data-time-apply]').addEventListener('click', () => {
      if (!hourInput.reportValidity() || !minuteInput.reportValidity()) return;
      updateDraft();
      const hour24 = (draft.hour % 12) + (draft.period === 'PM' ? 12 : 0);
      input.value = `${pad(hour24)}:${pad(draft.minute)}`;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      syncTriggerName();
      closePicker(true);
    });
    panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
      }
    });
    document.addEventListener('pointerdown', (event) => {
      if (!panel.hidden && !panel.contains(event.target) && !wrapper.contains(event.target)) closePicker();
    });
    window.addEventListener('resize', () => { if (!panel.hidden) positionPanel(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) positionPanel(); }, true);
    syncTriggerName();
  }

  timeInputs.forEach(enhanceTimeInput);
})();
</script>

<script>
(() => {
  const typeInput = document.getElementById('activity-type');
  const sourceFields = Array.from(document.querySelectorAll('[data-activity-source]'));
  const customTypeField = document.getElementById('custom-activity-type-field');
  const customTypeInput = document.getElementById('custom-activity-type');
  if (!typeInput) return;

  function syncActivitySourceFields() {
    sourceFields.forEach((field) => {
      const isRelevant = field.dataset.activitySource === typeInput.value;
      field.hidden = !isRelevant;
      if (!isRelevant) {
        const select = field.querySelector('select');
        if (select) select.value = '';
      }
    });
    if (customTypeField && customTypeInput) {
      const isOther = typeInput.value === 'other';
      customTypeField.hidden = !isOther;
      customTypeInput.required = isOther;
      if (!isOther) customTypeInput.value = '';
    }
  }

  typeInput.addEventListener('change', syncActivitySourceFields);
  syncActivitySourceFields();
})();
</script>

<script src="<?= activityFormEscape(SITE_URL . 'js/file-uploader.js') ?>"></script>

<?php
if ($shell === 'admin') renderAdminShellClose();
else renderStaffShellClose();
?>
