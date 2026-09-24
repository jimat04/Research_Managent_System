<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
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
        'scheduled' => ['label' => 'Scheduled', 'tone' => 'info'],
        'completed' => ['label' => 'Completed', 'tone' => 'success'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger'],
        default => ['label' => 'Unknown', 'tone' => 'neutral'],
    };
}

$role = (string) ($user['role'] ?? 'student');
$userId = (int) ($user['user_id'] ?? 0);
$canManage = in_array($role, ['research_staff', 'admin'], true);
$activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$activityCheck = $conn->query("SHOW TABLES LIKE 'research_activities'");
$activitiesAvailable = $activityCheck instanceof mysqli_result && $activityCheck->num_rows > 0;
if ($activityCheck instanceof mysqli_result) $activityCheck->free();
$participantCheck = $conn->query("SHOW TABLES LIKE 'activity_participants'");
$participantsAvailable = $participantCheck instanceof mysqli_result && $participantCheck->num_rows > 0;
if ($participantCheck instanceof mysqli_result) $participantCheck->free();

$activity = null;
if ($activitiesAvailable && $activityId) {
    $stmt = $conn->prepare('SELECT activity_id, activity_type, title, activity_date, activity_time, venue, speaker, organizer, description, status, created_by FROM research_activities WHERE activity_id = ? LIMIT 1');
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array((string) ($user['role'] ?? ''), ['research_staff', 'admin'], true)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
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
    $redirectToDetail = true;
    $flash = ['type' => 'error', 'message' => 'The requested action is invalid.'];

    if (in_array($action, ['complete', 'cancel'], true)) {
        $targetStatus = $action === 'complete' ? 'completed' : 'cancelled';
        $stmt = $conn->prepare("UPDATE research_activities SET status = ? WHERE activity_id = ? AND status = 'scheduled'");
        $stmt->bind_param('si', $targetStatus, $activityId);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            logActivity("Marked research activity #{$activityId} as {$targetStatus}", 'research_activities');
            $flash = ['type' => 'success', 'message' => "Activity marked {$targetStatus}."];
        } else {
            $flash['message'] = 'Only scheduled activities can be completed or cancelled.';
        }
        $stmt->close();
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
                    $stmt = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS full_name FROM users WHERE user_id = ? AND status = 'active' AND role IN ('faculty', 'student') LIMIT 1");
                    $stmt->bind_param('i', $selectedUserId);
                    $stmt->execute();
                    $selectedUser = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$selectedUser) {
                        $flash['message'] = 'Choose an active faculty or student user.';
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
$canViewParticipants = $activity && ($canManage || (int) ($activity['created_by'] ?? 0) === $userId);
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
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, role FROM users WHERE status = 'active' AND role IN ('faculty', 'student') ORDER BY role ASC, last_name ASC, first_name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $activeUsers[] = $row;
    $stmt->close();
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

$typeLabels = ['seminar' => 'Seminar', 'presentation' => 'Presentation', 'workshop' => 'Workshop', 'forum' => 'Forum'];
$statusBadge = activityDetailStatusBadge((string) ($activity['status'] ?? ''));
?>

<style>
.detail-page{max-width:1180px;margin:0 auto;color:#172033}.detail-back{display:inline-flex;margin-bottom:16px;color:#0f766e;font-size:12px;font-weight:800;text-decoration:none}.detail-hero{padding:28px 30px;border-left:5px solid #0f766e;border-radius:4px 18px 18px 4px;background:#eef8f6}.detail-tags{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}.badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800}.type-badge{background:#d9ede9;color:#0f5f58}.tone-info{background:#e0f2fe;color:#075985}.tone-success{background:#dcfce7;color:#166534}.tone-danger{background:#fee2e2;color:#991b1b}.tone-neutral{background:#e2e8f0;color:#475569}.detail-hero h1{max-width:850px;margin:0;font-size:clamp(26px,4vw,42px);line-height:1.08;letter-spacing:-.04em;text-wrap:balance}.detail-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:25px;padding-top:20px;border-top:1px solid #cfdfdc}.meta-item span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em}.meta-item strong{display:block;margin-top:6px;font-size:13px;line-height:1.4}.description{max-width:760px;margin:22px 0 0;color:#475569;line-height:1.7}.action-bar{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 24px;padding:14px;border:1px solid #dfe7e5;border-radius:13px;background:#fff}.action-bar form{display:inline}.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;box-sizing:border-box;border:1px solid #0f766e;border-radius:8px;padding:8px 13px;background:#0f766e;color:#fff;font:inherit;font-size:11px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .18s ease,background .18s ease}.button:hover{background:#115e59;color:#fff;transform:translateY(-1px)}.button.secondary{border-color:#cbd5e1;background:#fff;color:#475569}.button.danger{border-color:#dc2626;background:#fff;color:#b42318}.button:focus-visible,.detail-back:focus-visible,input:focus,select:focus{outline:3px solid rgba(15,118,110,.13);outline-offset:2px}.flash{margin:18px 0;padding:13px 16px;border:1px solid;border-radius:11px;font-size:13px;font-weight:650}.flash-success{border-color:#bbdfcf;background:#eefaf4;color:#166534}.flash-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.panel{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(30,50,70,.05)}.panel-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.count-mark{display:grid;place-items:center;min-width:42px;height:42px;border-radius:11px;background:#e3f3f0;color:#0f766e;font-weight:850}.table-wrap{overflow-x:auto}.detail-table{width:100%;min-width:680px;border-collapse:collapse}.detail-table th{padding:11px 16px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.detail-table td{padding:14px 16px;border-top:1px solid #edf1f5;font-size:13px}.attendance-yes{color:#166534;font-weight:800}.attendance-no{color:#64748b}.inline-form{display:grid;grid-template-columns:1fr 1fr 160px auto;gap:13px;align-items:end;padding:20px}.field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.field input,.field select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;font-size:12px}.attendance-list{padding:7px 20px 20px}.attendance-row{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:12px 2px;border-bottom:1px solid #edf1f5}.attendance-row label{font-size:13px;font-weight:700}.attendance-row input{width:18px;height:18px;accent-color:#0f766e}.attendance-submit{display:flex;justify-content:flex-end;margin-top:16px}.empty{padding:45px 24px;text-align:center;color:#64748b}.empty h2{margin:0 0 6px;color:#172033;font-size:17px}.privacy-note{padding:32px 24px;text-align:center;color:#64748b;font-size:13px}.not-found{padding:70px 25px;border:1px solid #e2e8f0;border-radius:17px;background:#fff;text-align:center}.not-found h1{margin:0;font-size:24px}.not-found p{margin:9px auto 20px;max-width:500px;color:#64748b;line-height:1.6}@media(max-width:800px){.detail-meta{grid-template-columns:1fr 1fr}.inline-form{grid-template-columns:1fr 1fr}}@media(max-width:560px){.detail-hero{padding:24px 21px}.detail-meta,.inline-form{grid-template-columns:1fr}.action-bar,.action-bar form,.button{width:100%}}
</style>

<div class="detail-page">
  <a class="detail-back" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activities.php') ?>">← All research activities</a>
  <?php if (is_array($flash)): ?><div class="flash flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= activityDetailEscape($flash['message'] ?? '') ?></div><?php endif; ?>
  <?php if (!$activity): ?>
    <section class="not-found"><h1>Activity not found</h1><p><?= activityDetailEscape($activitiesAvailable ? 'The activity may have been removed, or the link is incomplete.' : 'The research activities module is not installed on this database.') ?></p><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activities.php') ?>">Return to activities</a></section>
  <?php else: ?>
    <header class="detail-hero"><div class="detail-tags"><span class="badge type-badge"><?= activityDetailEscape($typeLabels[$activity['activity_type']] ?? 'Activity') ?></span><span class="badge tone-<?= activityDetailEscape($statusBadge['tone']) ?>"><?= activityDetailEscape($statusBadge['label']) ?></span></div><h1><?= activityDetailEscape($activity['title']) ?></h1><div class="detail-meta"><div class="meta-item"><span>Date and time</span><strong><?= activityDetailEscape(date('M j, Y', strtotime((string) $activity['activity_date']))) ?><?= $activity['activity_time'] ? ' · ' . activityDetailEscape(date('g:i A', strtotime((string) $activity['activity_time']))) : '' ?></strong></div><div class="meta-item"><span>Venue</span><strong><?= activityDetailEscape($activity['venue'] ?: 'To be announced') ?></strong></div><div class="meta-item"><span>Speaker</span><strong><?= activityDetailEscape($activity['speaker'] ?: 'Not specified') ?></strong></div><div class="meta-item"><span>Organizer</span><strong><?= activityDetailEscape($activity['organizer'] ?: 'Not specified') ?></strong></div></div><?php if ($activity['description']): ?><p class="description"><?= nl2br(activityDetailEscape($activity['description'])) ?></p><?php endif; ?></header>

    <?php if ($canManage): ?><nav class="action-bar" aria-label="Activity management"><a class="button secondary" href="<?= activityDetailEscape(SITE_URL . 'pages/shared/activity-form.php?id=' . $activityId) ?>">Edit</a><a class="button secondary" href="#add-participants">Add Participants</a><?php if (in_array($activity['status'], ['scheduled', 'completed'], true)): ?><a class="button secondary" href="#attendance">Mark Attendance</a><?php endif; ?><?php if ($activity['status'] === 'scheduled'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="complete"><button class="button" type="submit">Complete</button></form><form method="post" onsubmit="return confirm('Cancel this activity?');"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><button class="button danger" type="submit">Cancel</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this activity and all participant records? This cannot be undone.');"><?= csrfField() ?><input type="hidden" name="action" value="delete_activity"><button class="button danger" type="submit">Delete</button></form></nav><?php endif; ?>

    <section class="panel" aria-labelledby="participants-heading"><div class="panel-head"><div><h2 id="participants-heading">Participants</h2><p><?= $canViewParticipants ? 'Registered speakers, organizers, and attendees.' : 'Participant names are limited to authorized viewers.' ?></p></div><div class="count-mark" aria-label="<?= $participantCount ?> participants"><?= $participantCount ?></div></div>
      <?php if (!$canViewParticipants): ?><div class="privacy-note">You can see the participant count, but the participant list is private.</div>
      <?php elseif (!$participants): ?><div class="empty"><h2>No participants yet</h2><p>Participant records will appear here after they are added.</p></div>
      <?php else: ?><div class="table-wrap"><table class="detail-table"><thead><tr><th>Name</th><th>Role</th><th>Attended</th><?php if ($canManage): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php foreach ($participants as $participant): ?><tr><td><?= activityDetailEscape($participant['participant_name'] ?: 'Unnamed participant') ?></td><td><?= activityDetailEscape(ucfirst((string) $participant['role'])) ?></td><td class="<?= (int) $participant['attended'] === 1 ? 'attendance-yes' : 'attendance-no' ?>"><?= (int) $participant['attended'] === 1 ? 'Yes' : 'No' ?></td><?php if ($canManage): ?><td><form method="post" onsubmit="return confirm('Remove this participant?');"><?= csrfField() ?><input type="hidden" name="action" value="remove_participant"><input type="hidden" name="participant_id" value="<?= (int) $participant['participant_id'] ?>"><button class="button danger" type="submit">Remove</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <?php if ($canManage): ?>
      <section class="panel" id="add-participants" aria-labelledby="add-participants-heading"><div class="panel-head"><div><h2 id="add-participants-heading">Add participant</h2><p>Enter a name, optionally connect an active faculty or student account, and assign a role.</p></div></div><form class="inline-form" method="post"><?= csrfField() ?><input type="hidden" name="action" value="add_participant"><div class="field"><label for="participant-name">Participant name</label><input id="participant-name" name="participant_name" type="text" maxlength="255" placeholder="Full name"></div><div class="field"><label for="participant-user">Linked user (optional)</label><select id="participant-user" name="user_id"><option value="">No linked account</option><?php foreach ($activeUsers as $activeUser): ?><option value="<?= (int) $activeUser['user_id'] ?>"><?= activityDetailEscape(trim($activeUser['first_name'] . ' ' . $activeUser['last_name']) . ' · ' . ucfirst((string) $activeUser['role'])) ?></option><?php endforeach; ?></select></div><div class="field"><label for="participant-role">Role</label><select id="participant-role" name="participant_role" required><option value="attendee">Attendee</option><option value="speaker">Speaker</option><option value="organizer">Organizer</option></select></div><button class="button" type="submit">Add participant</button></form></section>

      <?php if (in_array($activity['status'], ['scheduled', 'completed'], true)): ?><section class="panel" id="attendance" aria-labelledby="attendance-heading"><div class="panel-head"><div><h2 id="attendance-heading">Mark attendance</h2><p>Check each participant who attended, then save the list.</p></div></div><?php if (!$participants): ?><div class="empty"><h2>No attendance list yet</h2><p>Add participants before recording attendance.</p></div><?php else: ?><form class="attendance-list" method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_attendance"><?php foreach ($participants as $participant): ?><input type="hidden" name="participant_ids[]" value="<?= (int) $participant['participant_id'] ?>"><div class="attendance-row"><label for="attended-<?= (int) $participant['participant_id'] ?>"><?= activityDetailEscape($participant['participant_name'] ?: 'Unnamed participant') ?></label><input id="attended-<?= (int) $participant['participant_id'] ?>" name="attended[<?= (int) $participant['participant_id'] ?>]" type="checkbox" value="1" <?= (int) $participant['attended'] === 1 ? 'checked' : '' ?>></div><?php endforeach; ?><div class="attendance-submit"><button class="button" type="submit">Save attendance</button></div></form><?php endif; ?></section><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
if ($shell === 'admin') renderAdminShellClose();
elseif ($shell === 'staff') renderStaffShellClose();
elseif ($shell === 'faculty') renderFacultyShellClose();
else renderStudentShellClose();
?>
