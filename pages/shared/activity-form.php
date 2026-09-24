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
$canManage = in_array($role, ['research_staff', 'admin'], true);
if (!$canManage) {
    header('Location: ' . SITE_URL . 'public/403.php');
    exit;
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'research_activities'");
$activitiesAvailable = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;
if ($tableCheck instanceof mysqli_result) {
    $tableCheck->free();
}

$typeOptions = ['seminar' => 'Seminar', 'presentation' => 'Presentation', 'workshop' => 'Workshop', 'forum' => 'Forum'];
$editRequested = array_key_exists('id', $_GET);
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($editRequested && !$activityId) {
    header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php');
    exit;
}
$isEdit = $activityId !== null;
$values = [
    'activity_type' => 'seminar', 'title' => '', 'activity_date' => '', 'activity_time' => '',
    'venue' => '', 'speaker' => '', 'organizer' => '', 'description' => '', 'status' => 'scheduled',
];
$errors = [];
if (!$activitiesAvailable) {
    $errors[] = 'The research activities module is not installed.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    $postedId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $isEdit = $postedId !== null;
    $activityId = $postedId;
    foreach (['activity_type', 'title', 'activity_date', 'activity_time', 'venue', 'speaker', 'organizer', 'description'] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if (!preg_match('/^(seminar|presentation|workshop|forum)$/D', $values['activity_type'])) {
        $errors[] = 'Choose a valid activity type.';
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
            $stmt = $conn->prepare("INSERT INTO research_activities (activity_type, title, activity_date, activity_time, venue, speaker, organizer, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)");
            $stmt->bind_param('ssssssssi', $values['activity_type'], $values['title'], $values['activity_date'], $time, $venue, $speaker, $organizer, $description, $userId);
            $stmt->execute();
            $activityId = (int) $stmt->insert_id;
            $stmt->close();

            $notificationMessage = 'New research ' . $values['activity_type'] . ': "' . $values['title'] . '" on ' . $values['activity_date'] . ' at ' . ($venue ?: 'venue to be announced') . '.';
            $notificationLink = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
            $recipients = $conn->prepare("SELECT user_id FROM users WHERE status = 'active' AND role IN ('faculty', 'student')");
            $recipients->execute();
            $recipientResult = $recipients->get_result();
            $recipientIds = [];
            while ($recipient = $recipientResult->fetch_assoc()) {
                $recipientIds[] = (int) $recipient['user_id'];
            }
            $recipients->close();
            foreach ($recipientIds as $recipientId) {
                createNotification($recipientId, 'New research activity', $notificationMessage, 'info', $notificationLink);
            }

            logActivity("Created research activity #{$activityId}: {$values['title']}", 'research_activities');
            $_SESSION['activities_flash'] = ['type' => 'success', 'message' => 'Activity created and faculty and students notified.'];
        }
        header('Location: ' . SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId);
        exit;
    }
}

$shell = $role === 'admin' ? 'admin' : 'staff';
$pageTitle = $isEdit ? 'Edit Activity' : 'Add Activity';
$pageSubtitle = $isEdit ? 'Update the activity details; status changes remain on the detail page' : 'Schedule a seminar, presentation, workshop, or forum';
if ($shell === 'admin') renderAdminShell($user, 'activities.php', $pageTitle, $pageSubtitle);
else renderStaffShell($user, 'activities.php', $pageTitle, $pageSubtitle);
?>

<style>
.activity-form-page{max-width:980px;margin:0 auto;color:#172033}.form-heading{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}.form-heading h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.form-heading p{max-width:620px;margin:8px 0 0;color:#64748b;line-height:1.55}.back-link{color:#0f766e;font-size:12px;font-weight:800;text-decoration:none}.form-card{overflow:hidden;border:1px solid #dfe7e5;border-radius:18px;background:#fff;box-shadow:0 15px 38px rgba(30,65,60,.07)}.form-status{padding:13px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#526176;font-size:12px}.form-errors{margin:0 0 18px;padding:15px 18px;border:1px solid #fecaca;border-radius:12px;background:#fff1f2;color:#991b1b}.form-errors strong{display:block;margin-bottom:6px}.form-errors ul{margin:0;padding-left:19px}.activity-form{padding:26px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px 18px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.required{color:#b42318}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:#172033;font:inherit;font-size:13px}.field textarea{min-height:140px;resize:vertical;line-height:1.55}.field input:focus,.field select:focus,.field textarea:focus,.button:focus-visible,.back-link:focus-visible{border-color:#0f766e;outline:3px solid rgba(15,118,110,.12);outline-offset:2px}.field small{display:block;margin-top:6px;color:#64748b;font-size:11px}.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #e8edf3}.button{display:inline-flex;align-items:center;justify-content:center;min-height:43px;border:1px solid #0f766e;border-radius:9px;padding:9px 17px;background:#0f766e;color:#fff;font:inherit;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#115e59;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}@media(max-width:640px){.form-heading{display:block}.back-link{display:inline-block;margin-top:14px}.activity-form{padding:20px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions{flex-direction:column-reverse}.button{width:100%;box-sizing:border-box}}
</style>

<div class="activity-form-page">
  <header class="form-heading"><div><h1><?= activityFormEscape($pageTitle) ?></h1><p><?= activityFormEscape($pageSubtitle) ?></p></div><a class="back-link" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId : SITE_URL . 'pages/shared/activities.php') ?>">← Back</a></header>
  <?php if ($errors): ?><div class="form-errors" role="alert"><strong>Review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= activityFormEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="form-card">
    <?php if ($isEdit): ?><div class="form-status">Current status: <strong><?= activityFormEscape(ucfirst($values['status'])) ?></strong>. Use the activity detail page to complete or cancel it.</div><?php endif; ?>
    <form class="activity-form" method="post">
      <?= csrfField() ?>
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
      </div>
      <div class="form-actions"><a class="button secondary" href="<?= activityFormEscape($isEdit && $activityId ? SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId : SITE_URL . 'pages/shared/activities.php') ?>">Cancel</a><button class="button" type="submit"><?= $isEdit ? 'Save changes' : 'Create activity' ?></button></div>
    </form>
  </section>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
else renderStaffShellClose();
?>
