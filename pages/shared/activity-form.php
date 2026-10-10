<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
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

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);
$capacityPageInput = trim((string) ($_POST['capacity_page'] ?? $_GET['capacity_page'] ?? ''));
$capacityPage = in_array($capacityPageInput, ['seminars', 'trainings', 'workshops'], true)
    ? $capacityPageInput
    : '';
$capacityPageTypes = [
    'seminars' => 'seminar',
    'trainings' => 'training',
    'workshops' => 'workshop',
];
$requestedType = trim((string) ($_GET['type'] ?? ''));
if ($capacityPage === '') {
    $capacityPage = match ($requestedType) {
        'seminar' => 'seminars',
        'training' => 'trainings',
        'workshop' => 'workshops',
        default => '',
    };
}
$capacityPermissionPages = static function (string $activityType, string $selectedPage): array {
    if ($activityType === 'seminar') {
        return ['seminars'];
    }
    if ($activityType === 'training') {
        return ['trainings'];
    }
    if ($activityType === 'workshop') {
        return ['workshops'];
    }
    return [];
};
$canManageActivity = static function (string $activityType, string $selectedPage) use ($role, $userId, $capacityPermissionPages): bool {
    if ($role === 'admin') {
        return true;
    }
    foreach ($capacityPermissionPages($activityType, $selectedPage) as $permissionPage) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $permissionPage)) {
            return true;
        }
    }
    return false;
};

$tableCheck = $conn->query("SHOW TABLES LIKE 'research_activities'");
$activitiesAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) {
    $tableCheck->free();
}
$participantCheck = $conn->query("SHOW TABLES LIKE 'activity_participants'");
$participantsAvailable = $participantCheck instanceof mysqli_result && $participantCheck->num_rows > 0;
if ($participantCheck instanceof mysqli_result) {
    $participantCheck->free();
}

$typeOptions = $role === 'admin'
    ? ['seminar' => 'Seminar', 'training' => 'Training', 'presentation' => 'Presentation', 'workshop' => 'Workshop', 'forum' => 'Forum']
    : [];
if ($role !== 'admin') {
    foreach (['seminars' => ['seminar', 'Seminar'], 'trainings' => ['training', 'Training'], 'workshops' => ['workshop', 'Workshop']] as $pageKey => [$typeKey, $typeLabel]) {
        if (hasModulePermission($userId, 'research_capacity_building', 'manage', $pageKey)) {
            $typeOptions[$typeKey] = $typeLabel;
        }
    }
}
$editRequested = array_key_exists('id', $_GET);
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($editRequested && !$activityId) {
    header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php');
    exit;
}
$isEdit = $activityId !== null;
$values = [
    'activity_type' => $capacityPage !== '' && isset($capacityPageTypes[$capacityPage])
        ? $capacityPageTypes[$capacityPage]
        : (isset($typeOptions[$requestedType]) ? $requestedType : (string) array_key_first($typeOptions)),
    'title' => '', 'activity_date' => '', 'activity_time' => '',
    'venue' => '', 'speaker' => '', 'organizer' => '', 'description' => '', 'status' => 'scheduled',
];
$errors = [];
if (!$activitiesAvailable) {
    $errors[] = 'The research activities module is not installed.';
}
$activeUsers = [];
$activeUsersById = [];
$recipientIds = [];
$recipientScope = 'all';
$recipientScopeOptions = [
    'all' => ['label' => 'All users', 'description' => 'Active students, faculty, and staff', 'roles' => ['student', 'faculty', 'research_staff']],
    'student' => ['label' => 'Students', 'description' => 'All active student accounts', 'roles' => ['student']],
    'faculty' => ['label' => 'Faculty', 'description' => 'All active faculty accounts', 'roles' => ['faculty']],
    'staff' => ['label' => 'Staff', 'description' => 'All active research staff accounts', 'roles' => ['research_staff']],
    'specific' => ['label' => 'Specific users', 'description' => 'Choose one or more people below', 'roles' => []],
];
$recipientRoleLabels = ['student' => 'Student', 'faculty' => 'Faculty', 'research_staff' => 'Staff'];
if (!$isEdit && $participantsAvailable) {
    $usersStmt = $conn->prepare("SELECT user_id, first_name, last_name, role FROM users WHERE status = 'active' AND role IN ('faculty', 'student', 'research_staff') AND deleted_at IS NULL ORDER BY role ASC, last_name ASC, first_name ASC");
    if ($usersStmt) {
        $usersStmt->execute();
        $usersResult = $usersStmt->get_result();
        while ($activeUser = $usersResult->fetch_assoc()) {
            $activeUsers[] = $activeUser;
            $activeUsersById[(int) $activeUser['user_id']] = $activeUser;
        }
        $usersStmt->close();
    } else {
        $errors[] = 'Unable to load eligible activity participants.';
    }
}

if ($isEdit && $activitiesAvailable) {
    $stmt = $conn->prepare('SELECT activity_type, title, activity_date, activity_time, venue, speaker, organizer, description, status FROM research_activities WHERE activity_id = ? LIMIT 1');
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$activity) {
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId);
        exit;
    } else {
        foreach ($values as $key => $_value) {
            $values[$key] = (string) ($activity[$key] ?? '');
        }
        if ($values['activity_time'] !== '') {
            $values['activity_time'] = substr($values['activity_time'], 0, 5);
        }
    }
}

if ($isEdit && $activity && !$canManageActivity((string) $values['activity_type'], $capacityPage)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}
if (!$isEdit && !$canManageActivity((string) $values['activity_type'], $capacityPage)) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    $postedCapacityPage = trim((string) ($_POST['capacity_page'] ?? $_GET['capacity_page'] ?? ''));
    $capacityPage = in_array($postedCapacityPage, ['seminars', 'trainings', 'workshops'], true)
        ? $postedCapacityPage
        : match ($requestedType) {
            'seminar' => 'seminars',
            'training' => 'trainings',
            'workshop' => 'workshops',
            default => '',
        };
    $postedId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $isEdit = $postedId !== null;
    $activityId = $postedId;
    foreach (['activity_type', 'title', 'activity_date', 'activity_time', 'venue', 'speaker', 'organizer', 'description'] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if (isset($capacityPageTypes[$capacityPage])) {
        $lockedActivityType = $capacityPageTypes[$capacityPage];
        if ($values['activity_type'] !== '' && $values['activity_type'] !== $lockedActivityType) {
            $errors[] = 'The activity type must match the selected ' . rtrim($capacityPage, 's') . ' page.';
        }
        $values['activity_type'] = $lockedActivityType;
    }

    if (!preg_match('/^(seminar|training|presentation|workshop|forum)$/D', $values['activity_type'])) {
        $errors[] = 'Choose a valid activity type.';
    }
    if (!$canManageActivity((string) $values['activity_type'], $capacityPage)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if ($values['title'] === '' || strlen($values['title']) > 255) {
        $errors[] = 'Title is required and must not exceed 255 characters.';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['activity_date']);
    if (!$date || $date->format('Y-m-d') !== $values['activity_date']) {
        $errors[] = 'Enter a valid activity date.';
    }
    if ($values['activity_time'] !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $values['activity_time'])) {
        $errors[] = 'Enter a valid activity time.';
    }
    foreach (['venue' => 255, 'speaker' => 255, 'organizer' => 255] as $field => $limit) {
        if (strlen($values[$field]) > $limit) {
            $errors[] = ucfirst($field) . " must not exceed {$limit} characters.";
        }
    }

    if (!$isEdit) {
        $postedRecipientScope = trim((string) ($_POST['recipient_scope'] ?? 'all'));
        if (!isset($recipientScopeOptions[$postedRecipientScope])) {
            $errors[] = 'Choose who should be notified about this activity.';
        } else {
            $recipientScope = $postedRecipientScope;
            if ($recipientScope !== 'specific') {
                $allowedRoles = $recipientScopeOptions[$recipientScope]['roles'];
                foreach ($activeUsers as $activeUser) {
                    if (in_array((string) $activeUser['role'], $allowedRoles, true)) {
                        $recipientIds[] = (int) $activeUser['user_id'];
                    }
                }
                if (!$recipientIds) {
                    $errors[] = 'There are no active users in the selected recipient group.';
                }
            } else {
                $rawRecipientIds = $_POST['recipient_user_ids'] ?? [];
                if (!is_array($rawRecipientIds)) {
                    $errors[] = 'Choose valid activity participants.';
                } else {
                    foreach (array_unique($rawRecipientIds) as $rawRecipientId) {
                        $recipientId = filter_var($rawRecipientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if (!$recipientId || !isset($activeUsersById[$recipientId])) {
                            $errors[] = 'One or more selected participants are no longer eligible. Refresh and choose them again.';
                            break;
                        }
                        $recipientIds[] = (int) $recipientId;
                    }
                    if (!$recipientIds) {
                        $errors[] = 'Select at least one faculty or student to notify and register as a participant.';
                    }
                }
            }
        }
        if (!$participantsAvailable) {
            $errors[] = 'Participant registration is not installed, so the activity cannot be created with invitations.';
        }
    }

    if (!$errors && $isEdit) {
        $check = $conn->prepare('SELECT status FROM research_activities WHERE activity_id = ? LIMIT 1');
        $check->bind_param('i', $activityId);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$existing) {
            $errors[] = 'The activity you tried to edit was not found.';
        } else {
            $values['status'] = (string) $existing['status'];
        }
    }

    if (!$errors) {
        $time = $values['activity_time'] !== '' ? $values['activity_time'] : null;
        $venue = $values['venue'] !== '' ? $values['venue'] : null;
        $speaker = $values['speaker'] !== '' ? $values['speaker'] : null;
        $organizer = $values['organizer'] !== '' ? $values['organizer'] : null;
        $description = $values['description'] !== '' ? $values['description'] : null;

        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE research_activities SET activity_type = ?, title = ?, activity_date = ?, activity_time = ?, venue = ?, speaker = ?, organizer = ?, description = ? WHERE activity_id = ?');
            $stmt->bind_param('ssssssssi', $values['activity_type'], $values['title'], $values['activity_date'], $time, $venue, $speaker, $organizer, $description, $activityId);
            $stmt->execute();
            $stmt->close();
            logActivity("Updated research activity #{$activityId}: {$values['title']}", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity details updated.'];
        } else {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare("INSERT INTO research_activities (activity_type, title, activity_date, activity_time, venue, speaker, organizer, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare the activity record.');
                }
                $stmt->bind_param('ssssssssi', $values['activity_type'], $values['title'], $values['activity_date'], $time, $venue, $speaker, $organizer, $description, $userId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to save the activity record.');
                }
                $activityId = (int) $stmt->insert_id;
                $stmt->close();

                $participantStmt = $conn->prepare("INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended) VALUES (?, ?, ?, 'attendee', 0)");
                if (!$participantStmt) {
                    throw new RuntimeException('Unable to prepare participant registrations.');
                }
                $notificationMessage = 'You are invited to research ' . $values['activity_type'] . ': "' . $values['title'] . '" on ' . $values['activity_date'] . ' at ' . ($venue ?: 'venue to be announced') . '.';
                $notificationLink = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
                foreach ($recipientIds as $recipientId) {
                    $participant = $activeUsersById[$recipientId];
                    $participantName = trim((string) $participant['first_name'] . ' ' . (string) $participant['last_name']);
                    $participantRole = 'attendee';
                    $participantStmt->bind_param('iis', $activityId, $recipientId, $participantName);
                    if (!$participantStmt->execute()) {
                        throw new RuntimeException('Unable to register a selected participant.');
                    }
                    if (!createNotification($recipientId, 'Research activity invitation', $notificationMessage, 'info', $notificationLink)) {
                        throw new RuntimeException('Unable to create a participant notification.');
                    }
                }
                $participantStmt->close();
                $conn->commit();

                logActivity("Created research activity #{$activityId}: {$values['title']}", 'research_activities');
                $recipientLabel = $recipientScope === 'specific'
                    ? 'the selected users'
                    : strtolower($recipientScopeOptions[$recipientScope]['label']);
                $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity created. ' . ucfirst($recipientLabel) . ' were registered and notified.'];
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Research activity creation failed: ' . $exception->getMessage());
                $errors[] = 'The activity and participant invitations could not be saved. Please try again.';
            }
        }
        if (!$errors) {
            header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId);
            exit;
        }
    }
}

$shell = $role === 'admin' ? 'admin' : 'staff';
$activityTypeLabels = [
    'seminar' => 'Seminar',
    'training' => 'Training',
    'presentation' => 'Presentation',
    'workshop' => 'Workshop',
    'forum' => 'Forum',
];
$selectedActivityLabel = $activityTypeLabels[$values['activity_type']] ?? 'Activity';
$pageTitle = ($isEdit ? 'Edit ' : 'Add ') . $selectedActivityLabel;
$pageSubtitle = $isEdit
    ? 'Update the ' . strtolower($selectedActivityLabel) . ' details; status changes remain on the detail page'
    : 'Schedule a ' . strtolower($selectedActivityLabel) . ' and set who should be notified';
$activityListUrl = SITE_URL . 'pages/shared/activities.php?type=' . rawurlencode((string) $values['activity_type']);
if ($capacityPage !== '') {
    $activityListUrl .= '&capacity_page=' . rawurlencode($capacityPage);
}
if ($shell === 'admin') renderAdminShell($user, 'activities.php', $pageTitle, $pageSubtitle);
else renderStaffShell($user, 'activities.php', $pageTitle, $pageSubtitle);
?>

<style>
.activity-form-page{max-width:980px;margin:0 auto;color:#172033}.form-heading{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.form-heading h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.form-heading p{max-width:620px;margin:8px 0 0;color:#64748b;line-height:1.55}.back-link{color:#0f766e;font-size:12px;font-weight:800;text-decoration:none}.form-card{overflow:hidden;border:1px solid #dfe7e5;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(30,65,60,.07)}.form-status{padding:13px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#526176;font-size:12px}.form-errors{margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#991b1b}.form-errors strong{display:block;margin-bottom:6px}.form-errors ul{margin:0;padding-left:19px}.activity-form{padding:26px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px 18px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.required{color:#b42318}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#172033;font:inherit;font-size:13px}.field textarea{min-height:140px;resize:vertical;line-height:1.55}.field input:focus,.field select:focus,.field textarea:focus,.button:focus-visible,.back-link:focus-visible{border-color:#0f766e;outline:3px solid rgba(15,118,110,.12);outline-offset:2px}.field small{display:block;margin-top:6px;color:#64748b;font-size:11px}.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #e8edf3}.button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;border:1px solid #0f766e;border-radius:9px;padding:9px 17px;background:#0f766e;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#115e59;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}@media(max-width:640px){.form-heading{display:block}.back-link{display:inline-block;margin-top:14px}.activity-form{padding:20px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions{flex-direction:column-reverse}.button{width:100%;box-sizing:border-box}}
.participant-picker{display:grid;gap:10px;padding:14px;border:1px solid #dbe3ea;border-radius:12px;background:#f8fafc}.participant-picker-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.participant-picker-head label{margin:0!important}.participant-picker-head button{border:0;background:none;color:#0f766e;font:inherit;font-size:11px;font-weight:800;text-decoration:underline;cursor:pointer}.recipient-scope{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}.scope-option{display:flex;align-items:flex-start;gap:9px;min-width:0;padding:12px;border:1px solid #dbe3ea;border-radius:10px;background:#fff;color:#334155;cursor:pointer}.scope-option:has(input:checked){border-color:#0f766e;background:#f0fdfa;box-shadow:0 0 0 2px rgba(15,118,110,.08)}.scope-option input{width:16px;height:16px;flex:0 0 16px;margin-top:1px;accent-color:#0f766e}.scope-option strong{display:block;font-size:12px}.scope-option small{display:block;margin-top:4px;color:#64748b;font-size:10px;font-weight:500;line-height:1.4}.participant-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;max-height:260px;overflow:auto}.participant-option{display:flex;align-items:center;gap:9px;min-width:0;padding:9px 10px;border:1px solid #e4eaf0;border-radius:8px;background:#fff;color:#334155;font-size:12px;font-weight:600;cursor:pointer}.participant-option input{width:16px;height:16px;flex:0 0 16px;accent-color:#0f766e}.participant-option span{min-width:0;overflow-wrap:anywhere}.participant-option small{margin:0 0 0 auto;flex:0 0 auto;color:#64748b;font-size:10px;font-weight:500}@media(max-width:900px){.recipient-scope{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.recipient-scope,.participant-list{grid-template-columns:1fr}}
</style>

<div class="activity-form-page">
  <header class="form-heading"><div><h1><?= activityFormEscape($pageTitle) ?></h1><p><?= activityFormEscape($pageSubtitle) ?></p></div><a class="back-link" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . ($capacityPage !== '' ? '&capacity_page=' . rawurlencode($capacityPage) : '') : $activityListUrl) ?>">← Back</a></header>
  <?php if ($errors): ?><div class="form-errors" role="alert"><strong>Review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= activityFormEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="form-card">
    <?php if ($isEdit): ?><div class="form-status">Current status: <strong><?= activityFormEscape(ucfirst($values['status'])) ?></strong>. Use the activity detail page to complete or cancel it.</div><?php endif; ?>
    <form class="activity-form" method="post">
      <?= csrfField() ?>
      <?php if ($capacityPage !== ''): ?><input type="hidden" name="capacity_page" value="<?= activityFormEscape($capacityPage) ?>"><?php endif; ?>
      <?php if ($isEdit && $activityId): ?><input type="hidden" name="activity_id" value="<?= (int) $activityId ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="field"><label for="activity-type">Type <span class="required">*</span></label><select id="activity-type" name="activity_type" required><?php foreach ($typeOptions as $value => $label): ?><option value="<?= activityFormEscape($value) ?>" <?= $values['activity_type'] === $value ? 'selected' : '' ?>><?= activityFormEscape($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="activity-date">Date <span class="required">*</span></label><input id="activity-date" name="activity_date" type="date" value="<?= activityFormEscape($values['activity_date']) ?>" required></div>
        <div class="field full"><label for="activity-title">Title <span class="required">*</span></label><input id="activity-title" name="title" type="text" maxlength="255" value="<?= activityFormEscape($values['title']) ?>" required></div>
        <div class="field"><label for="activity-time">Time</label><input id="activity-time" name="activity_time" type="time" value="<?= activityFormEscape($values['activity_time']) ?>"></div>
        <div class="field"><label for="activity-venue">Venue</label><input id="activity-venue" name="venue" type="text" maxlength="255" value="<?= activityFormEscape($values['venue']) ?>"></div>
        <div class="field"><label for="activity-speaker">Speaker</label><input id="activity-speaker" name="speaker" type="text" maxlength="255" value="<?= activityFormEscape($values['speaker']) ?>"></div>
        <div class="field"><label for="activity-organizer">Organizer</label><input id="activity-organizer" name="organizer" type="text" maxlength="255" value="<?= activityFormEscape($values['organizer']) ?>"></div>
        <div class="field full"><label for="activity-description">Description</label><textarea id="activity-description" name="description"><?= activityFormEscape($values['description']) ?></textarea><small>Include the purpose, audience, or preparation notes participants should know.</small></div>
        <?php if (!$isEdit): ?>
          <div class="field full">
            <div class="participant-picker-head">
              <label>Notify users <span class="required">*</span></label>
            </div>
            <small>Choose whether this activity should be sent to all users, a role group, or only specific users. Notified users are also registered as attendees.</small>
            <?php if (!$participantsAvailable): ?>
              <p class="form-errors">Participant registration is unavailable. Apply the activities migration before creating an activity with invitations.</p>
            <?php elseif (!$activeUsers): ?>
              <p class="form-status">There are no active student, faculty, or staff accounts available to invite.</p>
            <?php else: ?>
              <div class="participant-picker">
                <div class="recipient-scope" role="radiogroup" aria-label="Notification audience">
                  <?php foreach ($recipientScopeOptions as $scopeKey => $scopeOption): ?>
                    <label class="scope-option">
                      <input type="radio" name="recipient_scope" value="<?= activityFormEscape($scopeKey) ?>" <?= $recipientScope === $scopeKey ? 'checked' : '' ?>>
                      <span><strong><?= activityFormEscape($scopeOption['label']) ?></strong><small><?= activityFormEscape($scopeKey === 'all' ? count($activeUsers) . ' active users' : $scopeOption['description']) ?></small></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div id="specific-recipient-picker" <?= $recipientScope === 'specific' ? '' : 'hidden' ?>>
                  <div class="participant-picker-head">
                    <label>Choose users</label>
                    <button type="button" id="select-all-participants">Select all / clear</button>
                  </div>
                  <div class="participant-list">
                    <?php foreach ($activeUsers as $activeUser): ?>
                      <?php $candidateId = (int) $activeUser['user_id']; ?>
                      <label class="participant-option">
                        <input type="checkbox" name="recipient_user_ids[]" value="<?= $candidateId ?>" <?= in_array($candidateId, $recipientIds, true) ? 'checked' : '' ?>>
                        <span><?= activityFormEscape(trim((string) $activeUser['first_name'] . ' ' . (string) $activeUser['last_name'])) ?></span>
                        <small><?= activityFormEscape($recipientRoleLabels[(string) $activeUser['role']] ?? ucfirst((string) $activeUser['role'])) ?></small>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-actions"><a class="button secondary" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId . ($capacityPage !== '' ? '&capacity_page=' . rawurlencode($capacityPage) : '') : $activityListUrl) ?>">Cancel</a><button class="button" type="submit"><?= $isEdit ? 'Save changes' : 'Create Activity' ?></button></div>
    </form>
  </section>
</div>

<?php if (!$isEdit && $activeUsers): ?>
<script>
const recipientScopeInputs = document.querySelectorAll('input[name="recipient_scope"]');
const specificRecipientPicker = document.getElementById('specific-recipient-picker');
const recipientCheckboxes = document.querySelectorAll('input[name="recipient_user_ids[]"]');
function updateRecipientPicker() {
  const isSpecific = document.querySelector('input[name="recipient_scope"]:checked')?.value === 'specific';
  if (specificRecipientPicker) specificRecipientPicker.hidden = !isSpecific;
  recipientCheckboxes.forEach((checkbox) => { checkbox.disabled = !isSpecific; });
}
recipientScopeInputs.forEach((input) => input.addEventListener('change', updateRecipientPicker));
updateRecipientPicker();
document.getElementById('select-all-participants')?.addEventListener('click', function () {
  const shouldSelect = Array.from(recipientCheckboxes).some((checkbox) => !checkbox.checked);
  recipientCheckboxes.forEach((checkbox) => { checkbox.checked = shouldSelect; });
});
</script>
<?php endif; ?>

<?php
if ($shell === 'admin') renderAdminShellClose();
else renderStaffShellClose();
?>
