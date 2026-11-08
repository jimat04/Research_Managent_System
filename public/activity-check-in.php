<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity-schedule.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function activityCheckInEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activityCheckInLoad(mysqli $conn, string $tokenHash): ?array
{
    $stmt = $conn->prepare(
        'SELECT d.schedule_id, d.activity_id, d.schedule_date, d.status AS date_status,
                a.title, a.status AS activity_status, CURRENT_DATE() AS today
           FROM activity_schedule_dates d
           INNER JOIN research_activities a ON a.activity_id = d.activity_id
          WHERE d.checkin_token_hash = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare activity check-in lookup.');
    }
    $stmt->bind_param('s', $tokenHash);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load activity check-in details.');
    }
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $record;
}

$codeValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['checkin_code'] ?? '')
    : ($_GET['code'] ?? '');
$code = is_scalar($codeValue) ? strtolower(trim((string) $codeValue)) : '';
$tokenIsWellFormed = preg_match('/^[a-f0-9]{32}$/D', $code) === 1;
$checkinKey = preg_match('/^[a-f0-9]{64}$/i', ACTIVITY_CHECKIN_HASH_KEY) === 1
    ? hex2bin(ACTIVITY_CHECKIN_HASH_KEY)
    : false;
$tokenHash = $tokenIsWellFormed ? hash('sha256', $code) : '';
$record = null;
$state = 'invalid';
$notice = '';
$firstName = is_scalar($_POST['attendee_first_name'] ?? null) ? trim((string) $_POST['attendee_first_name']) : '';
$lastName = is_scalar($_POST['attendee_last_name'] ?? null) ? trim((string) $_POST['attendee_last_name']) : '';
$name = trim($firstName . ' ' . $lastName);
$schoolId = is_scalar($_POST['school_id'] ?? null) ? trim((string) $_POST['school_id']) : '';
$affiliation = is_scalar($_POST['attendee_affiliation'] ?? null) ? strtolower(trim((string) $_POST['attendee_affiliation'])) : '';
$programDepartment = is_scalar($_POST['attendee_program_department'] ?? null)
    ? trim((string) $_POST['attendee_program_department'])
    : '';

if ($tokenIsWellFormed && $checkinKey !== false) {
    try {
        $detailsSchemaAvailable = rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'attendee_affiliation')
            && rmsActivityScheduleColumnExists($conn, 'activity_schedule_participants', 'attendee_program_department');
        if ($detailsSchemaAvailable) {
            $record = activityCheckInLoad($conn, $tokenHash);
            if ($record) {
                $isOpen = (string) $record['date_status'] === 'scheduled'
                    && (string) $record['activity_status'] === 'ongoing'
                    && (string) $record['schedule_date'] === (string) $record['today'];
                $state = $isOpen ? 'open' : 'closed';
            }
        }
    } catch (Throwable $exception) {
        error_log('Public activity check-in lookup failed: ' . $exception->getMessage());
        $state = 'unavailable';
    }
} elseif ($tokenIsWellFormed) {
    $state = 'unavailable';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $state === 'open') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $notice = 'This check-in form expired. Refresh the page and try again.';
    } elseif (!in_array($affiliation, ['student', 'faculty', 'staff', 'guest'], true)) {
        $notice = 'Choose your affiliation.';
    } elseif (preg_match('/\A[^\x00-\x1F\x7F]{1,127}\z/u', $firstName) !== 1
        || preg_match('/\A[^\x00-\x1F\x7F]{1,127}\z/u', $lastName) !== 1
        || preg_match('/\A[^\x00-\x1F\x7F]{1,50}\z/u', $schoolId) !== 1) {
        $notice = 'Enter your first name, last name, and a school or employee ID (up to 50 characters).';
    } elseif (preg_match('/\A[^\x00-\x1F\x7F]{0,150}\z/u', $programDepartment) !== 1) {
        $notice = 'Program or department must be 150 characters or fewer.';
    } else {
        $identity = strtoupper($schoolId);
        $identityHash = hash_hmac('sha256', (int) $record['schedule_id'] . ':' . $identity, $checkinKey);
        try {
            $conn->begin_transaction();
            $lockStmt = $conn->prepare(
                'SELECT d.schedule_id, d.activity_id, d.schedule_date, d.status AS date_status,
                        a.title, a.status AS activity_status, CURRENT_DATE() AS today
                   FROM activity_schedule_dates d
                   INNER JOIN research_activities a ON a.activity_id = d.activity_id
                  WHERE d.checkin_token_hash = ? LIMIT 1 FOR UPDATE'
            );
            if (!$lockStmt) {
                throw new RuntimeException('Unable to verify this activity check-in.');
            }
            $lockStmt->bind_param('s', $tokenHash);
            if (!$lockStmt->execute()) {
                $lockStmt->close();
                throw new RuntimeException('Unable to verify this activity check-in.');
            }
            $record = $lockStmt->get_result()->fetch_assoc() ?: null;
            $lockStmt->close();
            $isOpen = $record
                && (string) $record['date_status'] === 'scheduled'
                && (string) $record['activity_status'] === 'ongoing'
                && (string) $record['schedule_date'] === (string) $record['today'];
            if (!$record) {
                $conn->rollback();
                $state = 'invalid';
            } elseif (!$isOpen) {
                $conn->rollback();
                $state = 'closed';
            } else {
                $scheduleId = (int) $record['schedule_id'];
                $activityId = (int) $record['activity_id'];
                $duplicateStmt = $conn->prepare(
                    'SELECT checkin_id FROM activity_schedule_checkins
                      WHERE schedule_id = ? AND identity_hash = ? LIMIT 1'
                );
                if (!$duplicateStmt) {
                    throw new RuntimeException('Unable to check for a prior check-in.');
                }
                $duplicateStmt->bind_param('is', $scheduleId, $identityHash);
                if (!$duplicateStmt->execute()) {
                    $duplicateStmt->close();
                    throw new RuntimeException('Unable to check for a prior check-in.');
                }
                $isDuplicate = $duplicateStmt->get_result()->num_rows > 0;
                $duplicateStmt->close();
                if ($isDuplicate) {
                    $conn->commit();
                    $state = 'duplicate';
                } else {
                    $matchedUserId = null;
                    $userName = '';
                    $userStmt = $conn->prepare(
                        "SELECT user_id, first_name, last_name
                           FROM users
                          WHERE student_id = ? AND status = 'active' AND deleted_at IS NULL
                          ORDER BY user_id ASC LIMIT 2"
                    );
                    if (!$userStmt) {
                        throw new RuntimeException('Unable to match this check-in to an RMS account.');
                    }
                    $userStmt->bind_param('s', $schoolId);
                    if (!$userStmt->execute()) {
                        $userStmt->close();
                        throw new RuntimeException('Unable to match this check-in to an RMS account.');
                    }
                    $userResult = $userStmt->get_result();
                    if ($userResult->num_rows === 1) {
                        $matchedUser = $userResult->fetch_assoc();
                        $matchedUserId = (int) $matchedUser['user_id'];
                        $userName = trim((string) $matchedUser['first_name'] . ' ' . (string) $matchedUser['last_name']);
                    }
                    $userStmt->close();

                    $participantId = null;
                    if ($matchedUserId !== null) {
                        $participantStmt = $conn->prepare(
                            'SELECT participant_id FROM activity_schedule_participants
                              WHERE schedule_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
                        );
                        if (!$participantStmt) {
                            throw new RuntimeException('Unable to load the activity participant record.');
                        }
                        $participantStmt->bind_param('ii', $scheduleId, $matchedUserId);
                        if (!$participantStmt->execute()) {
                            $participantStmt->close();
                            throw new RuntimeException('Unable to load the activity participant record.');
                        }
                        $participant = $participantStmt->get_result()->fetch_assoc() ?: null;
                        $participantStmt->close();
                        if ($participant) {
                            $participantId = (int) $participant['participant_id'];
                            $markStmt = $conn->prepare(
                                'UPDATE activity_schedule_participants
                                    SET attended = 1, attendee_affiliation = ?, attendee_program_department = ?
                                  WHERE participant_id = ? AND schedule_id = ?'
                            );
                            if (!$markStmt) {
                                throw new RuntimeException('Unable to record activity attendance.');
                            }
                            $markStmt->bind_param('ssii', $affiliation, $programDepartment, $participantId, $scheduleId);
                            if (!$markStmt->execute()) {
                                $markStmt->close();
                                throw new RuntimeException('Unable to record activity attendance.');
                            }
                            $markStmt->close();
                        }
                    }

                    if ($participantId === null) {
                        $participantName = $matchedUserId !== null ? $userName : $name;
                        $newParticipantStmt = $conn->prepare(
                            'INSERT INTO activity_schedule_participants
                                (schedule_id, user_id, participant_name, attendee_affiliation,
                                 attendee_program_department, role, attended, is_invited)
                             VALUES (?, ?, ?, ?, ?, \'attendee\', 1, 0)'
                        );
                        if (!$newParticipantStmt) {
                            throw new RuntimeException('Unable to add the attendee to the activity date.');
                        }
                        $newParticipantStmt->bind_param(
                            'iisss',
                            $scheduleId,
                            $matchedUserId,
                            $participantName,
                            $affiliation,
                            $programDepartment
                        );
                        if (!$newParticipantStmt->execute()) {
                            $newParticipantStmt->close();
                            throw new RuntimeException('Unable to add the attendee to the activity date.');
                        }
                        $participantId = (int) $conn->insert_id;
                        $newParticipantStmt->close();
                    }

                    $checkinStmt = $conn->prepare(
                        'INSERT INTO activity_schedule_checkins (schedule_id, participant_id, identity_hash)
                         VALUES (?, ?, ?)'
                    );
                    if (!$checkinStmt) {
                        throw new RuntimeException('Unable to save the check-in record.');
                    }
                    $checkinStmt->bind_param('iis', $scheduleId, $participantId, $identityHash);
                    if (!$checkinStmt->execute()) {
                        $checkinStmt->close();
                        throw new RuntimeException('Unable to save the check-in record.');
                    }
                    $checkinStmt->close();

                    if ($matchedUserId !== null) {
                        $legacyFind = $conn->prepare(
                            'SELECT participant_id FROM activity_participants WHERE activity_id = ? AND user_id = ? LIMIT 1'
                        );
                        if (!$legacyFind) {
                            throw new RuntimeException('Unable to update the activity attendance summary.');
                        }
                        $legacyFind->bind_param('ii', $activityId, $matchedUserId);
                        if (!$legacyFind->execute()) {
                            $legacyFind->close();
                            throw new RuntimeException('Unable to update the activity attendance summary.');
                        }
                        $hasLegacyParticipant = $legacyFind->get_result()->num_rows > 0;
                        $legacyFind->close();
                        if ($hasLegacyParticipant) {
                            $legacyStmt = $conn->prepare(
                                'UPDATE activity_participants SET attended = 1 WHERE activity_id = ? AND user_id = ?'
                            );
                            if (!$legacyStmt) {
                                throw new RuntimeException('Unable to update the activity attendance summary.');
                            }
                            $legacyStmt->bind_param('ii', $activityId, $matchedUserId);
                            if (!$legacyStmt->execute()) {
                                $legacyStmt->close();
                                throw new RuntimeException('Unable to update the activity attendance summary.');
                            }
                            $legacyStmt->close();
                        } else {
                            $legacyName = $userName !== '' ? $userName : $name;
                            $legacyInsert = $conn->prepare(
                                'INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended)
                                 VALUES (?, ?, ?, \'attendee\', 1)'
                            );
                            if (!$legacyInsert) {
                                throw new RuntimeException('Unable to update the activity attendance summary.');
                            }
                            $legacyInsert->bind_param('iis', $activityId, $matchedUserId, $legacyName);
                            if (!$legacyInsert->execute()) {
                                $legacyInsert->close();
                                throw new RuntimeException('Unable to update the activity attendance summary.');
                            }
                            $legacyInsert->close();
                        }
                    } else {
                        $legacyInsert = $conn->prepare(
                            'INSERT INTO activity_participants (activity_id, user_id, participant_name, role, attended)
                             VALUES (?, NULL, ?, \'attendee\', 1)'
                        );
                        if (!$legacyInsert) {
                            throw new RuntimeException('Unable to update the activity attendance summary.');
                        }
                        $legacyInsert->bind_param('is', $activityId, $name);
                        if (!$legacyInsert->execute()) {
                            $legacyInsert->close();
                            throw new RuntimeException('Unable to update the activity attendance summary.');
                        }
                        $legacyInsert->close();
                    }

                    $conn->commit();
                    $state = 'success';
                }
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Public activity check-in save failed: ' . $exception->getMessage());
            $notice = 'Your check-in could not be saved. Please ask event staff for help.';
            $state = 'open';
        }
    }
}

$pageTitle = 'Activity check-in';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= activityCheckInEscape($pageTitle) ?> | <?= activityCheckInEscape(SITE_NAME) ?></title>
  <style>
    :root{color-scheme:light;--ink:#111827;--muted:#64748b;--brand:#0d9488;--border:#e5e7eb;--surface:#fff;--page:#f8fafc}*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font:16px/1.55 Inter,Arial,sans-serif}.wrap{width:min(100% - 32px,560px);margin:clamp(32px,9vh,88px) auto}.card{padding:clamp(24px,6vw,40px);border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:0 12px 36px rgba(17,24,39,.07)}.brand{margin:0 0 8px;color:var(--brand);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}h1{margin:0;font-size:clamp(25px,6vw,34px);line-height:1.15}p{color:var(--muted)}.activity{margin:16px 0 22px;padding:14px 16px;border:1px solid #d7eeeb;border-radius:12px;background:#f0fdfa}.activity strong,.activity span{display:block}.activity span{margin-top:3px;color:var(--muted);font-size:14px}.field{margin:18px 0}.name-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.name-fields .field{min-width:0;margin:18px 0 0}.field label{display:block;margin-bottom:7px;font-size:14px;font-weight:700}.field input,.field select{width:100%;min-height:48px;padding:11px 13px;border:1px solid #cbd5e1;border-radius:10px;background:white;color:var(--ink);font:inherit}.field input:focus-visible,.field select:focus-visible,button:focus-visible{outline:3px solid rgba(13,148,136,.2);outline-offset:2px}.button{width:100%;min-height:48px;border:0;border-radius:10px;background:var(--brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}.notice{margin:20px 0;padding:14px 16px;border:1px solid #bae6fd;border-radius:12px;background:#f0f9ff;color:#075985}.notice.error{border-color:#fecaca;background:#fff1f2;color:#991b1b}.notice.success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.foot{margin:20px 0 0;color:var(--muted);font-size:13px}@media(max-width:420px){.name-fields{grid-template-columns:1fr}.name-fields .field{margin-top:14px}}
  </style>
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body>
  <main class="wrap">
    <section class="card" aria-labelledby="checkin-title">
      <p class="brand">Research activity attendance</p>
      <h1 id="checkin-title"><?= activityCheckInEscape($pageTitle) ?></h1>
      <?php if ($record): ?><div class="activity"><strong><?= activityCheckInEscape((string) $record['title']) ?></strong><span><?= activityCheckInEscape(date('F j, Y', strtotime((string) $record['schedule_date']))) ?></span></div><?php endif; ?>
      <?php if ($state === 'success'): ?>
        <div class="notice success" role="status">Your attendance has been recorded. Thank you.</div>
      <?php elseif ($state === 'duplicate'): ?>
        <div class="notice" role="status">A check-in for this ID is already recorded for this activity date.</div>
      <?php elseif ($state === 'closed'): ?>
        <div class="notice" role="status">Check-in is closed for this activity date.</div>
      <?php elseif ($state === 'invalid'): ?>
        <div class="notice error" role="alert">This check-in QR code is invalid or has been revoked.</div>
      <?php elseif ($state === 'unavailable'): ?>
        <div class="notice error" role="alert">Activity check-in is temporarily unavailable. Please ask event staff for help.</div>
      <?php else: ?>
        <?php if ($notice !== ''): ?><div class="notice error" role="alert"><?= activityCheckInEscape($notice) ?></div><?php endif; ?>
        <p>Enter your details to record your attendance for this date. Your school or employee ID is used to prevent duplicate check-ins.</p>
        <form method="post" autocomplete="on">
          <?= csrfField() ?>
          <input type="hidden" name="checkin_code" value="<?= activityCheckInEscape($code) ?>">
          <div class="name-fields">
            <div class="field"><label for="attendee-first-name">First name</label><input id="attendee-first-name" name="attendee_first_name" maxlength="127" value="<?= activityCheckInEscape($firstName) ?>" autocomplete="given-name" required></div>
            <div class="field"><label for="attendee-last-name">Last name</label><input id="attendee-last-name" name="attendee_last_name" maxlength="127" value="<?= activityCheckInEscape($lastName) ?>" autocomplete="family-name" required></div>
          </div>
          <div class="field"><label for="school-id">School or employee ID</label><input id="school-id" name="school_id" maxlength="50" value="<?= activityCheckInEscape($schoolId) ?>" autocomplete="off" required></div>
          <div class="field"><label for="attendee-affiliation">Affiliation</label><select id="attendee-affiliation" name="attendee_affiliation" required><option value="">Choose an affiliation</option><option value="student" <?= $affiliation === 'student' ? 'selected' : '' ?>>Student</option><option value="faculty" <?= $affiliation === 'faculty' ? 'selected' : '' ?>>Faculty</option><option value="staff" <?= $affiliation === 'staff' ? 'selected' : '' ?>>Staff</option><option value="guest" <?= $affiliation === 'guest' ? 'selected' : '' ?>>Guest</option></select></div>
          <div class="field"><label for="attendee-program-department">Program or department <span>(optional)</span></label><input id="attendee-program-department" name="attendee_program_department" maxlength="150" value="<?= activityCheckInEscape($programDepartment) ?>" autocomplete="organization-title"></div>
          <button class="button" type="submit">Check in</button>
        </form>
        <p class="foot">Check-in is available only on the scheduled date while the activity is ongoing. Do not submit another person’s ID.</p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
