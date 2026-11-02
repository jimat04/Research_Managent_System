<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');
$user = getCurrentUser();

function adminFacultyEngagementEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$tableCheck = $conn->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$tableName = 'faculty_engagement_records';
$tableCheck->bind_param('s', $tableName);
$tableCheck->execute();
$recordsAvailable = (int) ($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
$tableCheck->close();

$activityLinkAvailable = false;
if ($recordsAvailable) {
    $columnCheck = $conn->prepare('SELECT COUNT(*) AS column_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $tableName = 'faculty_engagement_records';
    $columnName = 'activity_id';
    $columnCheck->bind_param('ss', $tableName, $columnName);
    $columnCheck->execute();
    $activityLinkAvailable = (int) ($columnCheck->get_result()->fetch_assoc()['column_count'] ?? 0) > 0;
    $columnCheck->close();
}

$scheduleTableCheck = $conn->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$scheduleTableName = 'activity_schedule_dates';
$scheduleTableCheck->bind_param('s', $scheduleTableName);
$scheduleTableCheck->execute();
$activityScheduleDatesAvailable = (int) ($scheduleTableCheck->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
$scheduleTableCheck->close();

$error = '';
$success = '';
$statusOptions = ['pending', 'verified', 'returned'];
$statusFilter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['all', ...$statusOptions], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$recordsAvailable) {
        $error = 'Engagement review is unavailable until migration 024 is applied.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'review');
        if ($action === 'set_activity_link') {
            $engagementId = filter_var($_POST['engagement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rawActivityId = $_POST['activity_id'] ?? '';
            if (!$activityLinkAvailable) {
                $error = 'Activity links are unavailable until migration 062 is applied.';
            } elseif (!$engagementId || !is_string($rawActivityId)) {
                $error = 'Select a valid presentation record and activity.';
            } else {
                $recordStmt = $conn->prepare(
                    "SELECT activity_id, review_status
                       FROM faculty_engagement_records
                      WHERE engagement_id = ?
                        AND engagement_type = 'conference_presentation'
                        AND participation_role = 'presenter'
                      LIMIT 1"
                );
                if (!$recordStmt) {
                    throw new RuntimeException('Unable to prepare presentation link check: ' . $conn->error);
                }
                $recordStmt->bind_param('i', $engagementId);
                if (!$recordStmt->execute()) {
                    throw new RuntimeException('Unable to check presentation link: ' . $recordStmt->error);
                }
                $record = $recordStmt->get_result()->fetch_assoc();
                $recordStmt->close();

                if (!$record || !in_array($record['review_status'], ['pending', 'verified'], true)) {
                    $error = 'Only pending or verified presentation records can be linked.';
                } else {
                    $currentActivityId = $record['activity_id'] === null ? null : (int) $record['activity_id'];
                    $requestedActivityId = null;
                    if (trim($rawActivityId) !== '') {
                        $requestedActivityId = filter_var($rawActivityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($requestedActivityId === false) {
                            $error = 'Select a valid activity.';
                        } else {
                            $requestedActivityId = (int) $requestedActivityId;
                        }
                    }

                    if ($error === '' && $requestedActivityId !== $currentActivityId && $requestedActivityId !== null) {
                        $scheduleEligibility = $activityScheduleDatesAvailable
                            ? " AND (
                                NOT EXISTS (SELECT 1 FROM activity_schedule_dates any_dates WHERE any_dates.activity_id = ra.activity_id)
                                OR EXISTS (SELECT 1 FROM activity_schedule_dates active_dates
                                            WHERE active_dates.activity_id = ra.activity_id
                                              AND active_dates.status = 'scheduled')
                            )"
                            : '';
                        $activityStmt = $conn->prepare(
                            "SELECT ra.activity_id
                               FROM research_activities ra
                              WHERE ra.activity_id = ?
                                AND ra.status IN ('conducted', 'completed')
                                {$scheduleEligibility}
                              LIMIT 1"
                        );
                        if (!$activityStmt) {
                            throw new RuntimeException('Unable to prepare activity eligibility check: ' . $conn->error);
                        }
                        $activityStmt->bind_param('i', $requestedActivityId);
                        if (!$activityStmt->execute()) {
                            throw new RuntimeException('Unable to check activity eligibility: ' . $activityStmt->error);
                        }
                        $eligibleActivity = $activityStmt->get_result()->fetch_assoc();
                        $activityStmt->close();
                        if (!$eligibleActivity) {
                            $error = 'Choose a conducted or completed activity with a non-cancelled schedule.';
                        }
                    }

                    if ($error === '' && $requestedActivityId !== $currentActivityId) {
                        if ($requestedActivityId === null) {
                            $linkStmt = $conn->prepare(
                                "UPDATE faculty_engagement_records
                                    SET activity_id = NULL
                                  WHERE engagement_id = ?
                                    AND review_status IN ('pending', 'verified')
                                    AND engagement_type = 'conference_presentation'
                                    AND participation_role = 'presenter'"
                            );
                            if (!$linkStmt) {
                                throw new RuntimeException('Unable to prepare presentation unlink: ' . $conn->error);
                            }
                            $linkStmt->bind_param('i', $engagementId);
                        } else {
                            $linkStmt = $conn->prepare(
                                "UPDATE faculty_engagement_records
                                    SET activity_id = ?
                                  WHERE engagement_id = ?
                                    AND review_status IN ('pending', 'verified')
                                    AND engagement_type = 'conference_presentation'
                                    AND participation_role = 'presenter'"
                            );
                            if (!$linkStmt) {
                                throw new RuntimeException('Unable to prepare presentation link: ' . $conn->error);
                            }
                            $linkStmt->bind_param('ii', $requestedActivityId, $engagementId);
                        }
                        if (!$linkStmt->execute()) {
                            throw new RuntimeException('Unable to save presentation activity link: ' . $linkStmt->error);
                        }
                        $updated = $linkStmt->affected_rows === 1;
                        $linkStmt->close();
                        if (!$updated) {
                            $error = 'This record is no longer pending or verified. Refresh the page before changing its activity link.';
                        } else {
                            logActivity(
                                'Updated faculty presentation activity link for record #' . $engagementId,
                                'faculty_research_engagement'
                            );
                            $success = $requestedActivityId === null
                                ? 'Presentation activity link removed.'
                                : 'Presentation linked to the selected activity.';
                        }
                    } elseif ($error === '') {
                        $success = 'The selected activity link is already current.';
                    }
                }
            }
        } else {
            $engagementId = filter_var($_POST['engagement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $decision = (string) ($_POST['decision'] ?? '');
            $comments = trim((string) ($_POST['review_comments'] ?? ''));
            if (!$engagementId || !in_array($decision, ['verified', 'returned'], true)) {
                $error = 'Select a valid engagement record and review decision.';
            } elseif (strlen($comments) > 4000) {
                $error = 'Review comments must be 4,000 characters or fewer.';
            } elseif ($decision === 'returned' && $comments === '') {
                $error = 'Add a comment explaining what needs clarification before returning a record.';
            } else {
                $reviewerId = (int) ($user['user_id'] ?? 0);
                $stmt = $conn->prepare(
                    "UPDATE faculty_engagement_records
                        SET review_status = ?, reviewed_by = ?, review_comments = ?, reviewed_at = NOW()
                      WHERE engagement_id = ? AND review_status = 'pending'"
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare engagement review: ' . $conn->error);
                }
                $stmt->bind_param('sisi', $decision, $reviewerId, $comments, $engagementId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to save engagement review: ' . $stmt->error);
                }
                $updated = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$updated) {
                    $error = 'This record is no longer pending review. Refresh the page to see its current status.';
                } else {
                    logActivity('Reviewed faculty engagement record #' . $engagementId . ' as ' . $decision, 'faculty_research_engagement');
                    $success = $decision === 'verified' ? 'Engagement record verified.' : 'Engagement record returned for clarification.';
                }
            }
        }
    }
}

$activities = [];
if ($activityLinkAvailable) {
    $scheduleEligibility = $activityScheduleDatesAvailable
        ? "AND (
            NOT EXISTS (SELECT 1 FROM activity_schedule_dates any_dates WHERE any_dates.activity_id = ra.activity_id)
            OR EXISTS (SELECT 1 FROM activity_schedule_dates active_dates
                        WHERE active_dates.activity_id = ra.activity_id
                          AND active_dates.status = 'scheduled')
        )"
        : '';
    $activityStmt = $conn->prepare(
        "SELECT ra.activity_id, ra.activity_type, ra.title, ra.activity_date, ra.status
           FROM research_activities ra
          WHERE ra.status IN ('conducted', 'completed')
            {$scheduleEligibility}
          ORDER BY ra.activity_date DESC, ra.activity_id DESC
          LIMIT 250"
    );
    if (!$activityStmt) {
        throw new RuntimeException('Unable to prepare activity list: ' . $conn->error);
    }
    if (!$activityStmt->execute()) {
        throw new RuntimeException('Unable to load activities for presentation links: ' . $activityStmt->error);
    }
    $activities = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityStmt->close();
}

$records = [];
if ($recordsAvailable) {
    $activityLinkFields = $activityLinkAvailable
        ? ', e.activity_id, linked_activity.title AS linked_activity_title, linked_activity.status AS linked_activity_status'
        : '';
    $activityLinkJoin = $activityLinkAvailable
        ? ' LEFT JOIN research_activities linked_activity ON linked_activity.activity_id = e.activity_id'
        : '';
    $sql = 'SELECT e.engagement_id, e.engagement_type, e.custom_engagement_type, e.title, e.participation_role, e.activity_date, e.organizer, e.location, e.description, e.review_status, e.review_comments, e.research_project_id, e.evidence_upload_id, e.created_at, u.user_id, u.first_name, u.last_name, u.department, rp.title AS project_title, up.original_name AS evidence_name'
              . $activityLinkFields . '
              FROM faculty_engagement_records e
              JOIN users u ON u.user_id = e.faculty_id
              LEFT JOIN research_projects rp ON rp.project_id = e.research_project_id AND rp.deleted_at IS NULL
              LEFT JOIN uploads up ON up.upload_id = e.evidence_upload_id' . $activityLinkJoin . '
             WHERE (? = \'all\' OR e.review_status = ?)
             ORDER BY FIELD(e.review_status, \'pending\', \'returned\', \'verified\'), e.activity_date DESC, e.engagement_id DESC
             LIMIT 200';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load engagement records: ' . $conn->error);
    }
    $stmt->bind_param('ss', $statusFilter, $statusFilter);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
}

renderAdminShell($user, 'admin-faculty-engagement.php', 'Research Engagement Records', 'Review faculty-submitted activities and supporting evidence.');
?>
<style>
.faculty-engagement-admin{max-width:1400px;margin:0 auto;color:#111827}.faculty-engagement-admin .page-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin:0 0 24px}.faculty-engagement-admin h2{margin:0;font-size:25px}.faculty-engagement-admin p{color:#64748b;line-height:1.6}.engagement-filter,.engagement-record{border:1px solid #e5e7eb;border-radius:16px;background:#fff}.engagement-filter{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:18px 20px;margin-bottom:20px}.engagement-filter label{font-size:13px;font-weight:600}.engagement-filter select,.engagement-review textarea{min-height:40px;padding:8px 12px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#111827;font:inherit}.engagement-record{padding:22px;margin-bottom:16px}.engagement-record h3{margin:0 0 6px;font-size:18px}.engagement-meta{display:flex;flex-wrap:wrap;gap:8px 18px;color:#64748b;font-size:13px}.engagement-description{white-space:pre-wrap;line-height:1.6}.engagement-status{display:inline-flex;padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:700;text-transform:capitalize}.engagement-status--verified{background:#dcfce7;color:#166534}.engagement-status--returned{background:#ffedd5;color:#9a3412}.engagement-status--pending{background:#dbeafe;color:#1d4ed8}.engagement-review{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:12px;margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}.engagement-review label{display:block;color:#475569;font-size:13px;font-weight:600}.engagement-review textarea{display:block;width:100%;min-height:72px;margin-top:6px;box-sizing:border-box}.engagement-actions{display:flex;flex-wrap:wrap;gap:8px}.engagement-actions button,.engagement-filter button{min-height:40px;padding:8px 14px;border:0;border-radius:9px;background:#f57c00;color:#fff;font:600 13px inherit;cursor:pointer}.engagement-actions button.return{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.engagement-actions button:focus-visible,.engagement-filter select:focus-visible,.engagement-review textarea:focus-visible{outline:3px solid rgba(245,124,0,.28);outline-offset:2px}.engagement-notice{padding:14px 16px;margin:0 0 18px;border-radius:10px;background:#fff7ed;color:#9a3412}.engagement-empty{padding:42px 22px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:center;color:#64748b}@media(max-width:700px){.faculty-engagement-admin .page-heading{align-items:flex-start;flex-direction:column}.engagement-review{grid-template-columns:1fr}.engagement-actions button{flex:1}}
</style>
<style>
.engagement-activity-link select{display:block;width:100%;margin-top:6px;box-sizing:border-box;font:inherit}
.engagement-link-note{grid-column:1/-1;margin:0;font-size:12px}
@media(max-width:700px){.engagement-link-note{grid-column:1}}
</style>
<main class="faculty-engagement-admin">
  <header class="page-heading"><div><h2>Faculty research engagement</h2><p>Review submitted records. Existing seminar, training, and workshop attendance remains linked to its source activity.</p></div></header>
  <?php if ($success !== ''): ?><p class="engagement-notice" role="status"><?= adminFacultyEngagementEscape($success) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="engagement-notice" role="alert"><?= adminFacultyEngagementEscape($error) ?></p><?php endif; ?>
  <?php if ($recordsAvailable && !$activityLinkAvailable): ?><p class="engagement-notice">Explicit presentation links are available after migration 062 is applied.</p><?php endif; ?>
  <?php if (!$recordsAvailable): ?>
    <section class="engagement-empty"><strong>Engagement records are not installed.</strong><p>Apply database migration 024 before using review actions.</p></section>
  <?php else: ?>
    <form class="engagement-filter" method="get">
      <label for="engagement-status">Show records</label>
      <select id="engagement-status" name="status">
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending review</option>
        <option value="returned" <?= $statusFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
        <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
      </select>
      <button type="submit">Filter</button>
    </form>
    <?php if (!$records): ?><div class="engagement-empty">No <?= adminFacultyEngagementEscape($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>engagement records found.</div><?php endif; ?>
    <?php foreach ($records as $record): ?>
      <article class="engagement-record">
        <div class="engagement-meta"><span><?= adminFacultyEngagementEscape(trim($record['first_name'] . ' ' . $record['last_name'])) ?></span><span><?= adminFacultyEngagementEscape($record['department'] ?: 'Department not recorded') ?></span><span><?= adminFacultyEngagementEscape(date('M j, Y', strtotime((string) $record['activity_date']))) ?></span><span class="engagement-status engagement-status--<?= adminFacultyEngagementEscape($record['review_status']) ?>"><?= adminFacultyEngagementEscape($record['review_status']) ?></span></div>
        <h3><?= adminFacultyEngagementEscape($record['title']) ?></h3>
        <div class="engagement-meta">
          <span><?php
                    $engType = $record['engagement_type'];
                    $displayType = ucwords(str_replace('_', ' ', (string) $engType));
                    if ($engType === 'other' && !empty($record['custom_engagement_type'])) {
                        $displayType .= ': ' . $record['custom_engagement_type'];
                    }
                    echo adminFacultyEngagementEscape($displayType);
                ?></span>
          <span>Role: <?= adminFacultyEngagementEscape(ucwords(str_replace('_', ' ', (string) $record['participation_role']))) ?></span>
          <?php if ($record['organizer']): ?><span>Organizer: <?= adminFacultyEngagementEscape($record['organizer']) ?></span><?php endif; ?>
          <?php if ($record['location']): ?><span>Location: <?= adminFacultyEngagementEscape($record['location']) ?></span><?php endif; ?>
          <?php if ($record['project_title']): ?><span>Research: <?= adminFacultyEngagementEscape($record['project_title']) ?></span><?php endif; ?>
          <?php if ($activityLinkAvailable && $record['activity_id'] !== null): ?><span>Linked activity: <?= adminFacultyEngagementEscape($record['linked_activity_title'] ?: 'Activity #' . (int) $record['activity_id']) ?><?= $record['linked_activity_status'] && !in_array($record['linked_activity_status'], ['conducted', 'completed'], true) ? ' (' . adminFacultyEngagementEscape($record['linked_activity_status']) . ')' : '' ?></span><?php endif; ?>
          <?php if ($record['evidence_upload_id']): ?><a href="<?= adminFacultyEngagementEscape(SITE_URL . 'pages/shared/download-research-document.php?type=engagement&id=' . (int) $record['engagement_id']) ?>"><?= adminFacultyEngagementEscape($record['evidence_name'] ?: 'View evidence') ?></a><?php endif; ?>
        </div>
        <?php if ($record['description']): ?><p class="engagement-description"><?= adminFacultyEngagementEscape($record['description']) ?></p><?php endif; ?>
        <?php if ($activityLinkAvailable && $record['engagement_type'] === 'conference_presentation' && $record['participation_role'] === 'presenter' && in_array($record['review_status'], ['pending', 'verified'], true)): ?>
          <?php
            $currentActivityId = $record['activity_id'] === null ? null : (int) $record['activity_id'];
            $eligibleActivityIds = array_map('intval', array_column($activities, 'activity_id'));
          ?>
          <form class="engagement-review engagement-activity-link" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_activity_link">
            <input type="hidden" name="engagement_id" value="<?= (int) $record['engagement_id'] ?>">
            <label for="activity-link-<?= (int) $record['engagement_id'] ?>">Link to a conducted or completed activity
              <select id="activity-link-<?= (int) $record['engagement_id'] ?>" name="activity_id">
                <option value="" <?= $currentActivityId === null ? 'selected' : '' ?>>No linked activity</option>
                <?php if ($currentActivityId !== null && !in_array($currentActivityId, $eligibleActivityIds, true)): ?>
                  <option value="<?= $currentActivityId ?>" selected>Current link: <?= adminFacultyEngagementEscape($record['linked_activity_title'] ?: 'Activity #' . $currentActivityId) ?> (not available for new links)</option>
                <?php endif; ?>
                <?php foreach ($activities as $activity): ?>
                  <?php $activityId = (int) $activity['activity_id']; ?>
                  <option value="<?= $activityId ?>" <?= $currentActivityId === $activityId ? 'selected' : '' ?>><?= adminFacultyEngagementEscape(date('M j, Y', strtotime((string) $activity['activity_date'])) . ' — ' . $activity['title'] . ' (' . ucwords(str_replace('_', ' ', (string) $activity['activity_type'])) . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="engagement-actions"><button type="submit">Save explicit link</button></div>
            <p class="engagement-link-note">Links are reviewer-selected; pending records do not count until verified.</p>
          </form>
        <?php endif; ?>
        <?php if ($record['review_status'] === 'pending'): ?>
          <form class="engagement-review" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="engagement_id" value="<?= (int) $record['engagement_id'] ?>">
            <label>Review comment (required when returning)<textarea name="review_comments" maxlength="4000"></textarea></label>
            <div class="engagement-actions"><button type="submit" name="decision" value="verified">Verify record</button><button class="return" type="submit" name="decision" value="returned">Return for clarification</button></div>
          </form>
        <?php elseif ($record['review_comments']): ?>
          <p><strong>Review note:</strong> <?= adminFacultyEngagementEscape($record['review_comments']) ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<?php renderAdminShellClose(); ?>
