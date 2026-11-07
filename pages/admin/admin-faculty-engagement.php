<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole(['admin', 'research_staff']);
$user = getCurrentUser();
$isStaff = (string) ($user['role'] ?? '') === 'research_staff';
if ($isStaff) {
    requireModulePermission('research_development', 'view', 'research_engagement_records');
    require_once __DIR__ . '/../../includes/staff-shell.php';
} else {
    require_once __DIR__ . '/../../includes/admin-shell.php';
}
$canManageReviews = !$isStaff || hasModulePermission(
    (int) ($user['user_id'] ?? 0),
    'research_development',
    'manage',
    'research_engagement_records'
);
$canDownloadEvidence = !$isStaff || hasDownloadPermission(
    (int) ($user['user_id'] ?? 0),
    'research_development',
    'research_engagement_records'
);

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
$engagementTypeOptions = [
    'seminar_training' => 'Seminar / Training / Workshop',
    'conference_presentation' => 'Conference / Presentation',
    'extension_community' => 'Extension / Community Research',
    'research_award' => 'Research Award',
    'collaboration' => 'Research Collaboration',
    'other' => 'Other Research Engagement',
];
$readQueryValue = static function (string $key, int $maxLength = 150): string {
    $value = $_GET[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return function_exists('mb_substr')
        ? trim(mb_substr($value, 0, $maxLength, 'UTF-8'))
        : trim(substr($value, 0, $maxLength));
};
$statusFilter = $readQueryValue('status', 20);
if (!in_array($statusFilter, ['all', ...$statusOptions], true)) {
    $statusFilter = 'pending';
}
$keywordFilter = $readQueryValue('q');
$rawTypeFilter = $readQueryValue('type', 50);
$typeFilter = array_key_exists($rawTypeFilter, $engagementTypeOptions) ? $rawTypeFilter : '';
$rawDepartmentFilter = $readQueryValue('department');
$departmentOptions = [];
if ($recordsAvailable) {
    $departmentStmt = $conn->prepare(
        "SELECT DISTINCT u.department
           FROM faculty_engagement_records e
           JOIN users u ON u.user_id = e.faculty_id
          WHERE u.department IS NOT NULL AND TRIM(u.department) <> ''
          ORDER BY u.department"
    );
    if (!$departmentStmt) {
        throw new RuntimeException('Unable to prepare engagement departments: ' . $conn->error);
    }
    if (!$departmentStmt->execute()) {
        throw new RuntimeException('Unable to load engagement departments: ' . $departmentStmt->error);
    }
    $departmentResult = $departmentStmt->get_result();
    while ($departmentRow = $departmentResult->fetch_assoc()) {
        $departmentOptions[] = (string) $departmentRow['department'];
    }
    $departmentStmt->close();
}
$departmentFilter = in_array($rawDepartmentFilter, $departmentOptions, true) ? $rawDepartmentFilter : '';
$rawDateFrom = $readQueryValue('date_from', 20);
$rawDateTo = $readQueryValue('date_to', 20);
$normalizeFilterDate = static function (string $value): ?string {
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date || $date->format('Y-m-d') !== $value
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        return null;
    }
    return $value;
};
$dateFromFilter = $normalizeFilterDate($rawDateFrom);
$dateToFilter = $normalizeFilterDate($rawDateTo);
if ($dateFromFilter === null || $dateToFilter === null) {
    $error = 'Enter valid start and end dates to filter activity dates.';
    $dateFromFilter = '';
    $dateToFilter = '';
} elseif ($dateFromFilter !== '' && $dateToFilter !== '' && $dateFromFilter > $dateToFilter) {
    $error = 'The start date must be on or before the end date.';
    $dateFromFilter = '';
    $dateToFilter = '';
}
$pageSize = 25;
$requestedPage = filter_var($readQueryValue('page', 12), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pageNumber = $requestedPage === false ? 1 : (int) $requestedPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageReviews) {
        http_response_code(403);
        exit('Access denied.');
    } elseif (!$recordsAvailable) {
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
$totalRecords = 0;
$totalPages = 1;
$recordStart = 0;
$recordEnd = 0;
$searchPattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywordFilter) . '%';
$recordFilterSql = " WHERE (? = 'all' OR e.review_status = ?)
                       AND (? = '' OR CONCAT_WS(' ', u.first_name, u.last_name, e.title, e.organizer, e.description) LIKE ? ESCAPE '!')
                       AND (? = '' OR e.engagement_type = ?)
                       AND (? = '' OR u.department = ?)
                       AND (? = '' OR e.activity_date >= ?)
                       AND (? = '' OR e.activity_date <= ?)";
if ($recordsAvailable) {
    $countStmt = $conn->prepare(
        'SELECT COUNT(*) AS match_count
           FROM faculty_engagement_records e
           JOIN users u ON u.user_id = e.faculty_id'
        . $recordFilterSql
    );
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare engagement record count: ' . $conn->error);
    }
    $countStmt->bind_param(
        'ssssssssssss',
        $statusFilter,
        $statusFilter,
        $keywordFilter,
        $searchPattern,
        $typeFilter,
        $typeFilter,
        $departmentFilter,
        $departmentFilter,
        $dateFromFilter,
        $dateFromFilter,
        $dateToFilter,
        $dateToFilter
    );
    if (!$countStmt->execute()) {
        throw new RuntimeException('Unable to count engagement records: ' . $countStmt->error);
    }
    $totalRecords = (int) ($countStmt->get_result()->fetch_assoc()['match_count'] ?? 0);
    $countStmt->close();
    $totalPages = max(1, (int) ceil($totalRecords / $pageSize));
    $pageNumber = min($pageNumber, $totalPages);
    $offset = ($pageNumber - 1) * $pageSize;
    $recordStart = $totalRecords === 0 ? 0 : $offset + 1;
    $recordEnd = min($offset + $pageSize, $totalRecords);

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
              LEFT JOIN uploads up ON up.upload_id = e.evidence_upload_id' . $activityLinkJoin
              . $recordFilterSql . '
             ORDER BY FIELD(e.review_status, \'pending\', \'returned\', \'verified\'), e.activity_date DESC, e.engagement_id DESC
             LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load engagement records: ' . $conn->error);
    }
    $stmt->bind_param(
        'ssssssssssssii',
        $statusFilter,
        $statusFilter,
        $keywordFilter,
        $searchPattern,
        $typeFilter,
        $typeFilter,
        $departmentFilter,
        $departmentFilter,
        $dateFromFilter,
        $dateFromFilter,
        $dateToFilter,
        $dateToFilter,
        $pageSize,
        $offset
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to load engagement records: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
}

$pageTitle = $isStaff ? 'Faculty engagement review' : 'Research Engagement Records';
if ($isStaff) {
    renderStaffShell($user, 'admin-faculty-engagement.php', $pageTitle, 'Review faculty-submitted activities and supporting evidence.');
} else {
    renderAdminShell($user, 'admin-faculty-engagement.php', $pageTitle, 'Review faculty-submitted activities and supporting evidence.');
}
?>
<style>
.faculty-engagement-admin{--engagement-accent:<?= $isStaff ? '#0d9488' : '#f57c00' ?>;max-width:1400px;margin:0 auto;color:#111827}.faculty-engagement-admin .page-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin:0 0 24px}.faculty-engagement-admin h2{margin:0;font-size:25px}.faculty-engagement-admin p{color:#64748b;line-height:1.6}.engagement-filter,.engagement-record{border:1px solid #e5e7eb;border-radius:16px;background:#fff}.engagement-filter{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:18px 20px;margin-bottom:20px}.engagement-filter label{font-size:13px;font-weight:600}.engagement-filter select,.engagement-review textarea{min-height:40px;padding:8px 12px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#111827;font:inherit}.engagement-record{padding:22px;margin-bottom:16px}.engagement-record h3{margin:0 0 6px;font-size:18px}.engagement-record-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.engagement-record-heading h3{margin:0 0 5px;font-size:19px;line-height:1.35}.engagement-record-heading p{margin:0;color:#64748b;font-size:13px}.engagement-status{display:inline-flex;flex:0 0 auto;padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:700;text-transform:capitalize}.engagement-status--verified{background:#dcfce7;color:#166534}.engagement-status--returned{background:#ffedd5;color:#9a3412}.engagement-status--pending{background:#dbeafe;color:#1d4ed8}.engagement-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 22px;margin:0}.engagement-detail{min-width:0}.engagement-detail dt{margin:0 0 4px;color:#64748b;font-size:12px;font-weight:600}.engagement-detail dd{margin:0;color:#1f2937;font-size:14px;line-height:1.5;overflow-wrap:anywhere}.engagement-description-panel{margin-top:18px;padding:14px 16px;border-left:3px solid var(--engagement-accent);border-radius:0 10px 10px 0;background:#f8fafc}.engagement-description-panel h4{margin:0 0 5px;font-size:13px}.engagement-description{margin:0;white-space:pre-wrap;line-height:1.6}.engagement-evidence{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#fbfdff}.engagement-evidence-copy{display:grid;gap:3px;min-width:0}.engagement-evidence-copy span{color:#64748b;font-size:12px}.engagement-evidence-actions{display:flex;flex-wrap:wrap;gap:8px}.engagement-evidence-actions a{display:inline-flex;min-height:38px;align-items:center;justify-content:center;padding:8px 13px;border:1px solid var(--engagement-accent);border-radius:9px;color:var(--engagement-accent);font-size:13px;font-weight:600;text-decoration:none}.engagement-evidence-actions a:first-child{background:var(--engagement-accent);color:#fff}.engagement-evidence-actions a:hover{filter:brightness(.94)}.engagement-review-note{margin:18px 0 0;padding:13px 15px;border-radius:10px;background:#f8fafc;color:#334155}.engagement-review-note strong{display:block;margin-bottom:3px;font-size:13px}.engagement-review{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:12px;margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}.engagement-review label{display:block;color:#475569;font-size:13px;font-weight:600}.engagement-review textarea{display:block;width:100%;min-height:72px;margin-top:6px;box-sizing:border-box}.engagement-actions{display:flex;flex-wrap:wrap;gap:8px}.engagement-actions button,.engagement-filter button{min-height:40px;padding:8px 14px;border:0;border-radius:9px;background:var(--engagement-accent);color:#fff;font:600 13px inherit;cursor:pointer}.engagement-actions button.return{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.engagement-actions button:focus-visible,.engagement-filter select:focus-visible,.engagement-review textarea:focus-visible,.engagement-evidence-actions a:focus-visible{outline:2px solid var(--engagement-accent);outline-offset:3px}.engagement-notice{padding:14px 16px;margin:0 0 18px;border-radius:10px;background:#fff7ed;color:#9a3412}.engagement-empty{padding:42px 22px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:center;color:#64748b}.engagement-empty p{margin-bottom:0}@media(max-width:760px){.faculty-engagement-admin .page-heading{align-items:flex-start;flex-direction:column}.engagement-details{grid-template-columns:repeat(2,minmax(0,1fr))}.engagement-review{grid-template-columns:1fr}}@media(max-width:520px){.engagement-record{padding:17px}.engagement-record-heading{align-items:flex-start;flex-direction:column}.engagement-details{grid-template-columns:1fr}.engagement-evidence{align-items:flex-start;flex-direction:column}.engagement-evidence-actions,.engagement-evidence-actions a{width:100%}.engagement-actions button{flex:1}}
</style>
<style>
.engagement-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:end}
.engagement-filter label{display:grid;gap:6px;color:#475569;font-size:12px;font-weight:600}
.engagement-filter input,.engagement-filter select{width:100%;min-width:0;min-height:42px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#111827;font:inherit}
.engagement-filter button,.engagement-filter .engagement-filter-clear{display:inline-flex;min-height:42px;align-items:center;justify-content:center;padding:9px 14px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none}
.engagement-filter .engagement-filter-clear{border:1px solid #cbd5e1;background:#fff;color:#334155}
.engagement-filter input:focus-visible,.engagement-filter select:focus-visible,.engagement-filter button:focus-visible,.engagement-filter .engagement-filter-clear:focus-visible{outline:2px solid var(--engagement-accent);outline-offset:3px}
.engagement-results-summary{margin:-4px 0 14px;color:#64748b;font-size:13px}
.engagement-pagination{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;margin:20px 0}
.engagement-pagination a,.engagement-pagination span{display:inline-flex;min-width:40px;min-height:40px;align-items:center;justify-content:center;padding:8px 12px;border:1px solid #dbe2ea;border-radius:9px;background:#fff;color:#334155;font-size:13px;text-decoration:none}
.engagement-pagination a:hover{border-color:var(--engagement-accent);color:var(--engagement-accent)}
.engagement-pagination .engagement-pagination-current{border-color:var(--engagement-accent);background:var(--engagement-accent);color:#fff;font-weight:700}
.engagement-pagination a:focus-visible{outline:2px solid var(--engagement-accent);outline-offset:3px}
@media(max-width:850px){.engagement-filter{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.engagement-filter{grid-template-columns:1fr}.engagement-filter>*{grid-column:auto!important}.engagement-filter button,.engagement-filter .engagement-filter-clear{width:100%}}
.engagement-activity-link select{display:block;width:100%;margin-top:6px;box-sizing:border-box;font:inherit}
.engagement-link-note{grid-column:1/-1;margin:0;font-size:12px}
@media(max-width:700px){.engagement-link-note{grid-column:1}}
</style>
<main class="faculty-engagement-admin">
  <header class="page-heading"><div><h2>Faculty research engagement</h2><p>Review submitted records. Activity attendance remains linked to its source activity.</p></div></header>
  <?php if ($success !== ''): ?><p class="engagement-notice" role="status"><?= adminFacultyEngagementEscape($success) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="engagement-notice" role="alert"><?= adminFacultyEngagementEscape($error) ?></p><?php endif; ?>
  <?php if ($recordsAvailable && !$activityLinkAvailable): ?><p class="engagement-notice">Explicit presentation links are available after migration 062 is applied.</p><?php endif; ?>
  <?php if (!$recordsAvailable): ?>
    <section class="engagement-empty"><strong>Engagement records are not installed.</strong><p>Apply database migration 024 before using review actions.</p></section>
  <?php else: ?>
    <form class="engagement-filter" method="get">
      <label for="engagement-status">Show records
      <select id="engagement-status" name="status">
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending review</option>
        <option value="returned" <?= $statusFilter === 'returned' ? 'selected' : '' ?>>Returned</option>
        <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
      </select>
      </label>
      <label for="engagement-keyword">Search records
        <input id="engagement-keyword" type="search" name="q" maxlength="150" value="<?= adminFacultyEngagementEscape($keywordFilter) ?>" placeholder="Faculty, title, organizer, description">
      </label>
      <label for="engagement-type">Engagement type
        <select id="engagement-type" name="type">
          <option value="">All types</option>
          <?php foreach ($engagementTypeOptions as $typeKey => $typeLabel): ?>
            <option value="<?= adminFacultyEngagementEscape($typeKey) ?>" <?= $typeFilter === $typeKey ? 'selected' : '' ?>><?= adminFacultyEngagementEscape($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label for="engagement-department">Department
        <select id="engagement-department" name="department">
          <option value="">All departments</option>
          <?php foreach ($departmentOptions as $departmentOption): ?>
            <option value="<?= adminFacultyEngagementEscape($departmentOption) ?>" <?= $departmentFilter === $departmentOption ? 'selected' : '' ?>><?= adminFacultyEngagementEscape($departmentOption) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label for="engagement-date-from">Activity date from
        <input id="engagement-date-from" type="date" name="date_from" value="<?= adminFacultyEngagementEscape($rawDateFrom) ?>">
      </label>
      <label for="engagement-date-to">Activity date to
        <input id="engagement-date-to" type="date" name="date_to" value="<?= adminFacultyEngagementEscape($rawDateTo) ?>">
      </label>
      <button type="submit">Filter</button>
      <a class="engagement-filter-clear" href="<?= adminFacultyEngagementEscape(SITE_URL . 'pages/admin/admin-faculty-engagement.php?status=pending') ?>">Clear filters</a>
    </form>
    <p class="engagement-results-summary" role="status" aria-live="polite"><?php if ($totalRecords > 0): ?>Showing <?= $recordStart ?>–<?= $recordEnd ?> of <?= $totalRecords ?> matching records · Page <?= $pageNumber ?> of <?= $totalPages ?><?php else: ?>No matching engagement records found.<?php endif; ?></p>
    <?php if (!$records): ?><div class="engagement-empty">No <?= adminFacultyEngagementEscape($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>engagement records found for these filters.</div><?php endif; ?>
    <?php foreach ($records as $record): ?>
      <article class="engagement-record">
        <header class="engagement-record-heading">
          <div><h3><?= adminFacultyEngagementEscape($record['title']) ?></h3><p><?= adminFacultyEngagementEscape(trim($record['first_name'] . ' ' . $record['last_name'])) ?> · <?= adminFacultyEngagementEscape($record['department'] ?: 'Department not recorded') ?></p></div>
          <span class="engagement-status engagement-status--<?= adminFacultyEngagementEscape($record['review_status']) ?>"><?= adminFacultyEngagementEscape($record['review_status']) ?></span>
        </header>
        <dl class="engagement-details">
          <div class="engagement-detail"><dt>Activity date</dt><dd><?= adminFacultyEngagementEscape(date('M j, Y', strtotime((string) $record['activity_date']))) ?></dd></div>
          <div class="engagement-detail"><dt>Engagement type</dt><dd><?php
                    $engType = $record['engagement_type'];
                    $displayType = ucwords(str_replace('_', ' ', (string) $engType));
                    if ($engType === 'other' && !empty($record['custom_engagement_type'])) {
                        $displayType .= ': ' . $record['custom_engagement_type'];
                    }
                    echo adminFacultyEngagementEscape($displayType);
                ?></dd></div>
          <div class="engagement-detail"><dt>Participation role</dt><dd><?= adminFacultyEngagementEscape(ucwords(str_replace('_', ' ', (string) $record['participation_role']))) ?></dd></div>
          <?php if ($record['organizer']): ?><div class="engagement-detail"><dt>Organizer</dt><dd><?= adminFacultyEngagementEscape($record['organizer']) ?></dd></div><?php endif; ?>
          <?php if ($record['location']): ?><div class="engagement-detail"><dt>Location</dt><dd><?= adminFacultyEngagementEscape($record['location']) ?></dd></div><?php endif; ?>
          <?php if ($record['project_title']): ?><div class="engagement-detail"><dt>Linked research</dt><dd><?= adminFacultyEngagementEscape($record['project_title']) ?></dd></div><?php endif; ?>
          <?php if ($activityLinkAvailable && $record['activity_id'] !== null): ?><div class="engagement-detail"><dt>Linked activity</dt><dd><?= adminFacultyEngagementEscape($record['linked_activity_title'] ?: 'Activity #' . (int) $record['activity_id']) ?><?= $record['linked_activity_status'] && !in_array($record['linked_activity_status'], ['conducted', 'completed'], true) ? ' (' . adminFacultyEngagementEscape($record['linked_activity_status']) . ')' : '' ?></dd></div><?php endif; ?>
          <div class="engagement-detail"><dt>Submitted</dt><dd><?= adminFacultyEngagementEscape(date('M j, Y', strtotime((string) $record['created_at']))) ?></dd></div>
        </dl>
        <?php if ($record['description']): ?><section class="engagement-description-panel"><h4>Record description</h4><p class="engagement-description"><?= adminFacultyEngagementEscape($record['description']) ?></p></section><?php endif; ?>
        <?php if ($record['evidence_upload_id']): ?>
          <?php $evidenceViewerUrl = SITE_URL . 'pages/shared/download-research-document.php?type=engagement&id=' . (int) $record['engagement_id'] . '&mode=viewer'; ?>
          <?php $evidenceDownloadUrl = SITE_URL . 'pages/shared/download-research-document.php?type=engagement&id=' . (int) $record['engagement_id'] . '&mode=download'; ?>
          <section class="engagement-evidence" aria-label="Supporting document">
            <div class="engagement-evidence-copy"><strong><?= adminFacultyEngagementEscape($record['evidence_name'] ?: 'Supporting evidence') ?></strong><span>Supporting evidence attached to this record</span></div>
            <div class="engagement-evidence-actions"><a href="<?= adminFacultyEngagementEscape($evidenceViewerUrl) ?>" target="_blank" rel="noopener">Preview document</a><?php if ($canDownloadEvidence): ?><a href="<?= adminFacultyEngagementEscape($evidenceDownloadUrl) ?>">Download</a><?php endif; ?></div>
          </section>
        <?php endif; ?>
        <?php if ($canManageReviews && $activityLinkAvailable && $record['engagement_type'] === 'conference_presentation' && $record['participation_role'] === 'presenter' && in_array($record['review_status'], ['pending', 'verified'], true)): ?>
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
                  <?php $activityTypeLabel = match ((string) $activity['activity_type']) { 'seminar_workshop' => 'Seminars / Workshops', 'training' => 'Trainings', 'colloquium' => 'Colloquia', 'conference_congress' => 'Conference / Congress', default => 'Research Activity' }; ?>
                  <option value="<?= $activityId ?>" <?= $currentActivityId === $activityId ? 'selected' : '' ?>><?= adminFacultyEngagementEscape(date('M j, Y', strtotime((string) $activity['activity_date'])) . ' — ' . $activity['title'] . ' (' . $activityTypeLabel . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="engagement-actions"><button type="submit">Save explicit link</button></div>
            <p class="engagement-link-note">Links are reviewer-selected; pending records do not count until verified.</p>
          </form>
        <?php endif; ?>
        <?php if ($record['review_comments']): ?><p class="engagement-review-note"><strong>Review note</strong><?= adminFacultyEngagementEscape($record['review_comments']) ?></p><?php endif; ?>
        <?php if ($canManageReviews && $record['review_status'] === 'pending'): ?>
          <form class="engagement-review" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="engagement_id" value="<?= (int) $record['engagement_id'] ?>">
            <input type="hidden" name="review_comments" value="" data-review-comments>
            <div class="engagement-actions"><button type="submit" name="decision" value="verified">Verify record</button><button class="return" type="submit" name="decision" value="returned" data-return-trigger>Return for clarification</button></div>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if ($totalPages > 1): ?>
      <?php
        $paginationFilters = [
            'status' => $statusFilter,
            'q' => $keywordFilter,
            'type' => $typeFilter,
            'department' => $departmentFilter,
            'date_from' => $rawDateFrom,
            'date_to' => $rawDateTo,
        ];
        $paginationUrl = static function (int $targetPage) use ($paginationFilters): string {
            return SITE_URL . 'pages/admin/admin-faculty-engagement.php?' . http_build_query(array_merge($paginationFilters, ['page' => $targetPage]));
        };
      ?>
      <nav class="engagement-pagination" aria-label="Engagement record pages">
        <?php if ($pageNumber > 1): ?><a href="<?= adminFacultyEngagementEscape($paginationUrl($pageNumber - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
        <span class="engagement-pagination-current" aria-current="page">Page <?= $pageNumber ?> of <?= $totalPages ?></span>
        <?php if ($pageNumber < $totalPages): ?><a href="<?= adminFacultyEngagementEscape($paginationUrl($pageNumber + 1)) ?>" rel="next">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>
<style>
.engagement-return-dialog{box-sizing:border-box;width:min(520px,calc(100vw - 32px));max-width:none;margin:auto;padding:0;border:1px solid #e2e8f0;border-radius:16px;background:#fff;color:#111827;box-shadow:0 24px 72px rgba(15,23,42,.24)}
.engagement-return-dialog::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(2px)}
.engagement-return-dialog__content{padding:24px}
.engagement-return-dialog h2{margin:0 0 8px;font-size:20px;line-height:1.3}
.engagement-return-dialog p{margin:0 0 16px;color:#64748b;line-height:1.55}
.engagement-return-dialog label{display:block;color:#334155;font-size:13px;font-weight:600}
.engagement-return-dialog textarea{display:block;box-sizing:border-box;width:100%;min-height:130px;margin-top:7px;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#111827;font:inherit;resize:vertical}
.engagement-return-dialog__actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.engagement-return-dialog__actions button{min-height:42px;padding:9px 15px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.engagement-return-dialog__actions .confirm{border-color:#9a3412;background:#9a3412;color:#fff}
.engagement-return-dialog :is(textarea,button):focus-visible{outline:2px solid <?= $isStaff ? '#0d9488' : '#f57c00' ?>;outline-offset:3px}
@media(max-width:520px){.engagement-return-dialog__content{padding:20px}.engagement-return-dialog__actions{flex-direction:column-reverse}.engagement-return-dialog__actions button{width:100%}}
</style>
<dialog class="engagement-return-dialog" id="engagement-return-dialog" aria-labelledby="engagement-return-title" aria-describedby="engagement-return-description">
  <div class="engagement-return-dialog__content">
    <h2 id="engagement-return-title">Return for clarification</h2>
    <p id="engagement-return-description">Explain what the faculty member needs to clarify. This comment will be included with the returned record.</p>
    <label for="engagement-return-comment">Review comment (required)
      <textarea id="engagement-return-comment" maxlength="4000" required></textarea>
    </label>
    <div class="engagement-return-dialog__actions">
      <button type="button" data-return-cancel>Cancel</button>
      <button type="button" class="confirm" data-return-confirm>Confirm return</button>
    </div>
  </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('engagement-return-dialog');
    const commentField = document.getElementById('engagement-return-comment');
    const cancelButton = dialog?.querySelector('[data-return-cancel]');
    const confirmButton = dialog?.querySelector('[data-return-confirm]');
    let pendingForm = null;
    let returnButton = null;

    if (!dialog || !commentField || !cancelButton || !confirmButton || typeof dialog.showModal !== 'function') {
        return;
    }

    document.querySelectorAll('[data-return-trigger]').forEach((button) => {
        button.form?.addEventListener('submit', (event) => {
            if (event.submitter !== button || button.form.dataset.confirmedReturn === '1') {
                return;
            }

            event.preventDefault();
            pendingForm = button.form;
            returnButton = button;
            commentField.value = '';
            commentField.setCustomValidity('');
            dialog.showModal();
            commentField.focus();
        });
    });

    cancelButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        pendingForm = null;
        returnButton = null;
        commentField.value = '';
        commentField.setCustomValidity('');
    });

    confirmButton.addEventListener('click', () => {
        const comment = commentField.value.trim();
        if (!comment) {
            commentField.setCustomValidity('Enter a comment explaining what needs clarification.');
            commentField.reportValidity();
            return;
        }

        if (!pendingForm || !returnButton) {
            dialog.close();
            return;
        }

        commentField.setCustomValidity('');
        pendingForm.querySelector('[data-review-comments]').value = comment;
        pendingForm.dataset.confirmedReturn = '1';
        const formToSubmit = pendingForm;
        const buttonToSubmit = returnButton;
        dialog.close();
        formToSubmit.requestSubmit(buttonToSubmit);
    });
})();
</script>
<?php if ($isStaff): ?><?php renderStaffShellClose(); ?><?php else: ?><?php renderAdminShellClose(); ?><?php endif; ?>
