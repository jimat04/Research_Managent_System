<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/research-workflow.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');
$user = getCurrentUser();

function adminFacultyCollaborationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminFacultyCollaborationTableExists(mysqli $connection, string $tableName): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

$error = '';
$success = '';
$filterInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$courseFilter = strtoupper(trim((string) ($filterInput['course'] ?? $filterInput['course_filter'] ?? '')));
if (!in_array($courseFilter, ['', 'BSIT', 'BSCS'], true)) {
    $courseFilter = '';
}
$projectStatuses = [];
$statusOptionsStmt = $conn->prepare("SELECT DISTINCT status FROM research_projects WHERE deleted_at IS NULL ORDER BY status");
$statusOptionsStmt->execute();
$statusOptionsResult = $statusOptionsStmt->get_result();
while ($statusOption = $statusOptionsResult->fetch_assoc()) {
    $projectStatuses[] = (string) $statusOption['status'];
}
$statusOptionsStmt->close();
$statusFilter = strtolower(trim((string) ($filterInput['status'] ?? $filterInput['status_filter'] ?? '')));
if (!in_array($statusFilter, $projectStatuses, true)) {
    $statusFilter = '';
}
$academicYears = [];
$academicYearsStmt = $conn->prepare(
    'SELECT DISTINCT academic_year.label
       FROM academic_years academic_year
       JOIN research_projects project ON project.ay_id = academic_year.ay_id
      WHERE project.deleted_at IS NULL
      ORDER BY academic_year.label DESC'
);
$academicYearsStmt->execute();
$academicYearsResult = $academicYearsStmt->get_result();
while ($academicYear = $academicYearsResult->fetch_assoc()) {
    $academicYears[] = (string) $academicYear['label'];
}
$academicYearsStmt->close();
$academicYearFilter = trim((string) ($filterInput['academic_year'] ?? $filterInput['school_year'] ?? ''));
if (!in_array($academicYearFilter, $academicYears, true)) {
    $academicYearFilter = '';
}
$keywordFilter = trim((string) ($filterInput['keyword'] ?? $filterInput['keyword_filter'] ?? ''));
if (function_exists('mb_substr')) {
    $keywordFilter = mb_substr($keywordFilter, 0, 100, 'UTF-8');
} elseif (function_exists('iconv_substr')) {
    $keywordFilter = iconv_substr($keywordFilter, 0, 100, 'UTF-8') ?: '';
} else {
    preg_match_all('/./us', $keywordFilter, $keywordCharacters);
    $keywordFilter = implode('', array_slice($keywordCharacters[0] ?? [], 0, 100));
}
$keywordLike = $keywordFilter !== '' ? '%' . $keywordFilter . '%' : '';
$redirectFilters = array_filter([
    'course' => $courseFilter,
    'status' => $statusFilter,
    'academic_year' => $academicYearFilter,
    'keyword' => $keywordFilter,
], static fn(string $value): bool => $value !== '');
$redirectQuery = static function (string $savedState) use ($redirectFilters): string {
    return http_build_query(['saved' => $savedState] + $redirectFilters);
};
if (($_GET['saved'] ?? '') === 'assigned') {
    $success = 'Assignment saved.';
} elseif (($_GET['saved'] ?? '') === 'removed') {
    $success = 'Assignment removed.';
}
$hasAdviserHistory = adminFacultyCollaborationTableExists($conn, 'project_advisers_history');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'assign_member') {
            $projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $memberRole = (string) ($_POST['member_role'] ?? 'member');
            if (!$projectId || !$userId) {
                $error = 'Select a project and a researcher.';
            } elseif ($action === 'assign_member' && !in_array($memberRole, ['lead', 'member'], true)) {
                $error = 'Select a valid project team role.';
            } else {
                $projectStmt = $conn->prepare('SELECT project_id FROM research_projects WHERE project_id = ? AND deleted_at IS NULL LIMIT 1');
                $projectStmt->bind_param('i', $projectId);
                $projectStmt->execute();
                $projectExists = $projectStmt->get_result()->num_rows === 1;
                $projectStmt->close();
                if (!$projectExists) {
                    $error = 'The selected research project is unavailable.';
                } else {
                    $allowedRoles = ['student'];
                    $rolePlaceholders = implode(',', array_fill(0, count($allowedRoles), '?'));
                    $roleStmt = $conn->prepare(
                        'SELECT role FROM users WHERE user_id = ? AND status = \'active\' AND deleted_at IS NULL AND role IN (' . $rolePlaceholders . ') LIMIT 1'
                    );
                    $types = 'i' . str_repeat('s', count($allowedRoles));
                    $params = array_merge([$userId], $allowedRoles);
                    $roleStmt->bind_param($types, ...$params);
                    $roleStmt->execute();
                    $selectedRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? null;
                    $roleStmt->close();
                    if (!$selectedRole) {
                        $error = 'Choose an active student account as a team member.';
                    } elseif ($action === 'assign_member'
                        && $selectedRole === 'student'
                        && !rms_student_program_is_eligible($conn, (int) $userId)) {
                        $error = 'Student team members must be active students in an enabled BSIT or BSCS program.';
                    } else {
                        if ($action === 'assign_member') {
                            $existsStmt = $conn->prepare('SELECT project_member_id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
                            $existsStmt->bind_param('ii', $projectId, $userId);
                            $existsStmt->execute();
                            $exists = $existsStmt->get_result()->num_rows > 0;
                            $existsStmt->close();
                            if ($exists) {
                                $error = 'That researcher is already assigned to this project.';
                            } else {
                                $conn->begin_transaction();
                                try {
                                    if ($selectedRole === 'student'
                                        && !rms_claim_student_project_slot($conn, (int) $userId, (int) $projectId)) {
                                        throw new DomainException('That student is already assigned to another research project. Remove that assignment before adding them.');
                                    }
                                    $stmt = $conn->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)');
                                    if (!$stmt) {
                                        throw new RuntimeException('Unable to prepare the project team assignment.');
                                    }
                                    $stmt->bind_param('iis', $projectId, $userId, $memberRole);
                                    if (!$stmt->execute()) {
                                        $stmt->close();
                                        throw new RuntimeException('Unable to assign project team member.');
                                    }
                                    $stmt->close();
                                    $conn->commit();
                                } catch (Throwable $exception) {
                                    $conn->rollback();
                                    $error = $exception instanceof DomainException
                                        ? $exception->getMessage()
                                        : 'Unable to assign project team member.';
                                }
                            }
                        }
                        if ($error === '') {
                            logActivity('Updated faculty collaboration assignment for project #' . $projectId, 'faculty_collaboration');
                            header('Location: ' . SITE_URL . 'pages/admin/admin-faculty-collaboration.php?' . $redirectQuery('assigned'));
                            exit;
                        }
                    }
                }
            }
        } elseif ($action === 'remove_member') {
            $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$assignmentId) {
                $error = 'Select a valid assignment to remove.';
            } else {
                $memberStmt = $conn->prepare(
                    'SELECT pm.project_id, pm.user_id, pm.role AS member_role, u.role AS user_role,
                            CASE WHEN rp.created_by = pm.user_id OR rp.student_id = pm.user_id THEN 1 ELSE 0 END AS is_project_owner
                       FROM project_members pm
                       JOIN users u ON u.user_id = pm.user_id
                       JOIN research_projects rp ON rp.project_id = pm.project_id
                      WHERE pm.project_member_id = ?
                      LIMIT 1'
                );
                $memberStmt->bind_param('i', $assignmentId);
                $memberStmt->execute();
                $member = $memberStmt->get_result()->fetch_assoc();
                $memberStmt->close();
                if (!$member) {
                    $error = 'The assignment no longer exists. Refresh the page and try again.';
                } elseif ($member['user_role'] === 'student'
                    && ($member['member_role'] === 'lead' || (int) $member['is_project_owner'] === 1)) {
                    $error = 'A student lead or project owner cannot be removed here. Reassign the project ownership first.';
                } else {
                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare('DELETE FROM project_members WHERE project_member_id = ?');
                        if (!$stmt) {
                            throw new RuntimeException('Unable to prepare the project assignment removal.');
                        }
                        $stmt->bind_param('i', $assignmentId);
                        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                            $stmt->close();
                            throw new RuntimeException('Unable to remove the project assignment.');
                        }
                        $stmt->close();
                        rms_release_student_project_slot($conn, (int) $member['user_id'], (int) $member['project_id']);
                        $conn->commit();
                    } catch (Throwable $exception) {
                        $conn->rollback();
                        $error = 'Unable to remove project assignment. Please try again.';
                    }
                }
                if ($error === '') {
                    logActivity('Removed project team assignment #' . $assignmentId, 'faculty_collaboration');
                    header('Location: ' . SITE_URL . 'pages/admin/admin-faculty-collaboration.php?' . $redirectQuery('removed'));
                    exit;
                }
            }
        } else {
            $error = 'Select a valid collaboration action.';
        }
    }
}

$projects = [];
 $projectSql = "SELECT project.project_id, project.title, project.status, project_program.program_code
                   FROM research_projects project
                   JOIN users project_owner ON project_owner.user_id = project.created_by
                    AND project_owner.role = 'student' AND project_owner.deleted_at IS NULL
              LEFT JOIN programs project_program ON project_program.status = 1
                    AND project_program.program_code IN ('BSIT', 'BSCS')
                    AND (UPPER(TRIM(project_program.program_code)) = UPPER(TRIM(project_owner.program))
                      OR UPPER(TRIM(project_program.program_name)) = UPPER(TRIM(project_owner.program)))
              LEFT JOIN academic_years project_year ON project_year.ay_id = project.ay_id
";
$projectSql .= " WHERE project.deleted_at IS NULL";
$projectTypes = '';
$projectParams = [];
if ($courseFilter !== '') {
    $projectSql .= ' AND project_program.program_code = ?';
    $projectTypes .= 's';
    $projectParams[] = $courseFilter;
}
if ($statusFilter !== '') {
    $projectSql .= ' AND project.status = ?';
    $projectTypes .= 's';
    $projectParams[] = $statusFilter;
}
if ($academicYearFilter !== '') {
    $projectSql .= ' AND project_year.label = ?';
    $projectTypes .= 's';
    $projectParams[] = $academicYearFilter;
}
if ($keywordFilter !== '') {
    $projectSql .= " AND (project.title LIKE ? OR CONCAT_WS(' ', project_owner.first_name, project_owner.last_name) LIKE ?)";
    $projectTypes .= 'ss';
    $projectParams[] = $keywordLike;
    $projectParams[] = $keywordLike;
}
$projectSql .= ' ORDER BY project.updated_at DESC, project.project_id DESC LIMIT 250';
$projectStmt = $conn->prepare($projectSql);
if ($projectTypes !== '') {
    $projectStmt->bind_param($projectTypes, ...$projectParams);
}
$projectStmt->execute();
$projectResult = $projectStmt->get_result();
while ($row = $projectResult->fetch_assoc()) {
    $projects[] = $row;
}
$projectStmt->close();

$researchers = [];
$researcherSql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.role, u.department, p.program_code
                    FROM users u
                    JOIN programs p ON p.status = 1
                     AND p.program_code IN ('BSIT', 'BSCS')
                     AND (UPPER(TRIM(p.program_code)) = UPPER(TRIM(u.program))
                       OR UPPER(TRIM(p.program_name)) = UPPER(TRIM(u.program)))
                   WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
if ($courseFilter !== '') {
    $researcherSql .= ' AND p.program_code = ?';
    $researcherTypes = 's';
    $researcherParams = [$courseFilter];
} else {
    $researcherTypes = '';
    $researcherParams = [];
}
if ($keywordFilter !== '') {
    $researcherSql .= " AND CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?";
    $researcherTypes .= 's';
    $researcherParams[] = $keywordLike;
}
$researcherSql .= ' ORDER BY p.program_code, u.last_name, u.first_name LIMIT 500';
$researcherStmt = $conn->prepare($researcherSql);
if ($researcherTypes !== '') {
    $researcherStmt->bind_param($researcherTypes, ...$researcherParams);
}
$researcherStmt->execute();
$researcherResult = $researcherStmt->get_result();
while ($row = $researcherResult->fetch_assoc()) {
    $researchers[] = $row;
}
$researcherStmt->close();

$members = [];
$memberSql =
    "SELECT pm.project_member_id, pm.project_id, pm.role, project.title AS project_title, u.user_id, u.first_name, u.last_name, u.role AS user_role, u.department, course.program_code
       FROM project_members pm
       JOIN research_projects project ON project.project_id = pm.project_id AND project.deleted_at IS NULL
       JOIN users u ON u.user_id = pm.user_id AND u.deleted_at IS NULL AND u.role = 'student'
  LEFT JOIN programs course ON course.status = 1
       AND course.program_code IN ('BSIT', 'BSCS')
       AND (UPPER(TRIM(course.program_code)) = UPPER(TRIM(u.program))
         OR UPPER(TRIM(course.program_name)) = UPPER(TRIM(u.program)))
  LEFT JOIN academic_years project_year ON project_year.ay_id = project.ay_id
";
$memberSql .= ' WHERE 1 = 1';
$memberTypes = '';
$memberParams = [];
if ($statusFilter === '') {
    $memberSql .= " AND project.status NOT IN ('completed', 'archived')";
}
if ($courseFilter !== '') {
    $memberSql .= ' AND course.program_code = ?';
    $memberTypes .= 's';
    $memberParams[] = $courseFilter;
}
if ($statusFilter !== '') {
    $memberSql .= ' AND project.status = ?';
    $memberTypes .= 's';
    $memberParams[] = $statusFilter;
}
if ($academicYearFilter !== '') {
    $memberSql .= ' AND project_year.label = ?';
    $memberTypes .= 's';
    $memberParams[] = $academicYearFilter;
}
if ($keywordFilter !== '') {
    $memberSql .= " AND (project.title LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $memberTypes .= 'ssss';
    $memberParams[] = $keywordLike;
    $memberParams[] = $keywordLike;
    $memberParams[] = $keywordLike;
    $memberParams[] = $keywordLike;
}
$memberSql .= ' ORDER BY project.updated_at DESC, project.project_id DESC, pm.role, u.last_name LIMIT 500';
$memberStmt = $conn->prepare($memberSql);
if ($memberTypes !== '') {
    $memberStmt->bind_param($memberTypes, ...$memberParams);
}
$memberStmt->execute();
$memberResult = $memberStmt->get_result();
while ($row = $memberResult->fetch_assoc()) {
    $members[] = $row;
}
$memberStmt->close();

$advisers = [];
$adviserStmt = $conn->prepare(
        "SELECT pa.project_adviser_id, pa.project_id, pa.assigned_at, 0 AS is_legacy, p.title AS project_title, u.user_id, u.first_name, u.last_name, u.department
       FROM project_advisers pa
       JOIN research_projects p ON p.project_id = pa.project_id AND p.deleted_at IS NULL
       JOIN users u ON u.user_id = pa.adviser_id AND u.role = 'faculty' AND u.deleted_at IS NULL
            UNION ALL
         SELECT NULL AS project_adviser_id, p.project_id, p.created_at AS assigned_at, 1 AS is_legacy, p.title AS project_title, u.user_id, u.first_name, u.last_name, u.department
             FROM research_projects p
             JOIN users u ON u.user_id = p.adviser_id AND u.role = 'faculty' AND u.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = p.project_id AND pa.adviser_id = p.adviser_id)
    ORDER BY project_id DESC, assigned_at DESC, last_name
      LIMIT 500"
);
$adviserStmt->execute();
$adviserResult = $adviserStmt->get_result();
while ($row = $adviserResult->fetch_assoc()) {
    $advisers[] = $row;
}
$adviserStmt->close();

$adviserHistory = [];
if ($hasAdviserHistory) {
    $historyStmt = $conn->prepare(
    "SELECT pah.id, pah.project_id, pah.adviser_id, pah.assigned_at, pah.removed_at,
            p.title AS project_title,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), CONCAT('Former faculty account #', pah.adviser_id)) AS adviser_name,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, ''))), ''), 'Administrator') AS removed_by_name
       FROM project_advisers_history pah
       LEFT JOIN research_projects p ON p.project_id = pah.project_id
       LEFT JOIN users u ON u.user_id = pah.adviser_id
       LEFT JOIN users r ON r.user_id = pah.removed_by
      ORDER BY pah.removed_at DESC, pah.id DESC
      LIMIT 100"
    );
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    while ($row = $historyResult->fetch_assoc()) {
        $adviserHistory[] = $row;
    }
    $historyStmt->close();
}

$activeFilterLabels = [];
if ($courseFilter !== '') {
    $activeFilterLabels[] = 'Program: ' . $courseFilter;
}
if ($statusFilter !== '') {
    $activeFilterLabels[] = 'Status: ' . ucwords(str_replace('_', ' ', $statusFilter));
}
if ($academicYearFilter !== '') {
    $activeFilterLabels[] = 'School year: ' . $academicYearFilter;
}
if ($keywordFilter !== '') {
    $activeFilterLabels[] = 'Search: ' . $keywordFilter;
}
$shownMemberCount = count($members);
$memberCountMessage = $shownMemberCount === 500
    ? 'Showing 500 assignments (display limit).'
    : sprintf('Showing %d assignments.', $shownMemberCount);

renderAdminShell($user, 'admin-faculty-collaboration.php', 'Collaboration & Mentorship', 'Manage project teams and review faculty advising assignments.');
?>
<style>
.faculty-collaboration-admin{max-width:1440px;margin:0 auto;color:var(--admin-ink,#111827)}
.collaboration-header{position:relative;margin:0 0 24px;padding:24px 26px 25px;border:1px solid var(--admin-accent-soft,rgba(245,124,0,.18));border-left:4px solid var(--admin-accent,#f57c00);border-radius:16px;background:linear-gradient(110deg,var(--admin-accent-soft,rgba(245,124,0,.08)),rgba(255,255,255,.96) 56%)}
.collaboration-header-kicker{margin:0 0 8px;color:var(--admin-accent,#f57c00);font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}
.collaboration-header h1{margin:0 0 8px;font-size:clamp(28px,2.3vw,34px);line-height:1.15;letter-spacing:-.035em}
.collaboration-header p:last-child{max-width:760px;margin:0;color:var(--admin-muted,#64748b);line-height:1.65}
.collaboration-course-filter{display:grid;grid-template-columns:minmax(145px,.75fr) minmax(190px,1fr) minmax(240px,1.45fr) auto;align-items:end;gap:12px;margin:0 0 12px;padding:18px;border:1px solid var(--admin-border,#e5e7eb);border-radius:14px;background:var(--admin-surface,#fff);box-shadow:0 4px 18px rgba(124,69,18,.045)}
.collaboration-course-filter label{display:grid;gap:7px;min-width:0;color:#475569;font-size:13px;font-weight:600}
.collaboration-course-filter select,.collaboration-course-filter input{width:100%;min-height:44px;padding:9px 12px;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;background:#fff;color:var(--admin-ink,#111827);font:inherit}
.collaboration-course-filter select:focus-visible,.collaboration-course-filter input:focus-visible,.collaboration-course-filter button:focus-visible,.collaboration-course-filter a:focus-visible{outline:3px solid var(--admin-accent-soft,rgba(245,124,0,.2));outline-offset:2px}
.collaboration-filter-actions{display:flex;align-items:center;gap:8px}
.collaboration-course-clear{display:inline-flex;min-height:40px;align-items:center;justify-content:center;padding:10px 12px;border-radius:9px;color:var(--admin-muted,#64748b);font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap}
.collaboration-course-clear:hover{background:#fff7ed;color:#9a3412}
.collaboration-filter-context{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 24px;color:var(--admin-muted,#64748b);font-size:12px}
.collaboration-filter-context p{margin:0 8px 0 0;font-weight:600;color:#475569}
.collaboration-filter-chip{display:inline-flex;align-items:center;min-height:25px;padding:4px 9px;border:1px solid rgba(245,124,0,.18);border-radius:7px;background:#fff7ed;color:#9a3412;font-weight:600}
.collaboration-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:0 0 24px}
.collaboration-stat{padding:16px 18px;border:1px solid var(--admin-border,#e5e7eb);border-radius:14px;background:var(--admin-surface,#fff)}
.collaboration-stat strong{display:block;color:var(--admin-ink,#111827);font-size:24px;line-height:1.15}
.collaboration-stat span{display:block;margin-top:5px;color:var(--admin-muted,#64748b);font-size:12px}
.collaboration-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;margin-bottom:20px}
.collaboration-panel{min-width:0;padding:22px;border:1px solid var(--admin-border,#e5e7eb);border-radius:16px;background:var(--admin-surface,#fff);box-shadow:0 1px 3px rgba(15,23,42,.035)}
.collaboration-panel h2{margin:0 0 6px;font-size:17px;line-height:1.35}
.collaboration-panel-intro{margin:0 0 18px;color:var(--admin-muted,#64748b);font-size:13px;line-height:1.55}
.collaboration-form{display:grid;gap:14px}
.collaboration-form label{display:grid;gap:7px;color:#475569;font-size:13px;font-weight:600}
.collaboration-form select{width:100%;min-height:44px;padding:9px 12px;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;background:#fff;color:var(--admin-ink,#111827);font:inherit}
.collaboration-form select:focus-visible,.collaboration-form button:focus-visible,.assignment-remove:focus-visible{outline:3px solid var(--admin-accent-soft,rgba(245,124,0,.2));outline-offset:2px}
.collaboration-submit,.assignment-remove{display:inline-flex;justify-content:center;align-items:center;min-height:40px;padding:9px 14px;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;line-height:1.2;cursor:pointer}
.collaboration-submit{border:1px solid var(--admin-accent,#f57c00);background:var(--admin-accent,#f57c00);color:#fff;transition:filter .18s ease,transform .18s ease}
.collaboration-submit:hover{filter:brightness(.94);transform:translateY(-1px)}
.collaboration-submit:active{transform:translateY(1px)}
.collaboration-table-wrap{overflow-x:auto;border:1px solid var(--admin-border,#e5e7eb);border-radius:12px}
.collaboration-table{width:100%;border-collapse:collapse;min-width:720px;background:#fff}
.collaboration-table th,.collaboration-table td{padding:13px 14px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:middle}
.collaboration-table th{background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.045em;white-space:nowrap}
.collaboration-table td{color:#334155;font-size:13px;line-height:1.45}
.collaboration-table tbody tr:last-child td{border-bottom:0}
.collaboration-table tbody tr:hover{background:#fafbfe}
.assignment-remove{min-height:34px;padding:6px 10px;border:1px solid #fecaca;background:#fff;color:#b91c1c}
.assignment-remove:hover{background:#fef2f2}
.collaboration-notice{padding:13px 16px;margin:0 0 18px;border:1px solid;border-radius:12px;line-height:1.5}
.collaboration-notice.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.collaboration-notice.is-success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.collaboration-empty{padding:26px 18px;color:var(--admin-muted,#64748b);text-align:center;font-size:13px}
.collaboration-history-note{margin:0 0 14px;color:var(--admin-muted,#64748b);font-size:12px;line-height:1.5}
.collaboration-adviser-panel,.collaboration-history-panel{margin-top:20px}
@media(max-width:900px){.collaboration-grid{grid-template-columns:1fr}}
@media(max-width:1100px){.collaboration-course-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.collaboration-course-filter label:nth-child(4){grid-column:1/-1}.collaboration-filter-actions{grid-column:1/-1}}
@media(max-width:640px){.collaboration-header{padding:20px 18px 21px}.collaboration-header h1{font-size:27px}.collaboration-stats{grid-template-columns:1fr;gap:10px}.collaboration-stat{padding:14px 16px}.collaboration-panel{padding:18px}.collaboration-course-filter{grid-template-columns:1fr;padding:15px}.collaboration-course-filter label:nth-child(4),.collaboration-filter-actions{grid-column:auto}.collaboration-filter-actions{display:grid;grid-template-columns:1fr}.collaboration-course-filter .collaboration-submit,.collaboration-course-clear{width:100%;text-align:center}.collaboration-filter-context{align-items:flex-start}.collaboration-table{min-width:780px}}
@media(prefers-reduced-motion:reduce){.collaboration-submit{transition:none}}
</style>
<main class="faculty-collaboration-admin">
  <header class="collaboration-header">
    <div>
      <p class="collaboration-header-kicker">Research teams and mentorship</p>
      <h1>Collaboration &amp; Mentorship</h1>
      <p>Manage project teams and review student-selected adviser assignments. Adviser changes are driven by the student request and faculty decision workflow.</p>
    </div>
  </header>
  <?php if ($error !== ''): ?><p class="collaboration-notice is-error" role="alert"><?= adminFacultyCollaborationEscape($error) ?></p><?php endif; ?>
  <?php if ($success !== ''): ?><p class="collaboration-notice is-success" role="status"><?= adminFacultyCollaborationEscape($success) ?></p><?php endif; ?>
  <form method="get" action="<?= adminFacultyCollaborationEscape(SITE_URL . 'pages/admin/admin-faculty-collaboration.php') ?>" class="collaboration-course-filter">
    <label for="course-filter">Filter student teams by course/program
      <select id="course-filter" name="course">
        <option value="" <?= $courseFilter === '' ? 'selected' : '' ?>>All eligible programs</option>
        <option value="BSIT" <?= $courseFilter === 'BSIT' ? 'selected' : '' ?>>BSIT</option>
        <option value="BSCS" <?= $courseFilter === 'BSCS' ? 'selected' : '' ?>>BSCS</option>
      </select>
    </label>
    <label for="status-filter">Project status
      <select id="status-filter" name="status">
        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
        <?php foreach ($projectStatuses as $projectStatus): ?>
          <option value="<?= adminFacultyCollaborationEscape($projectStatus) ?>" <?= $statusFilter === $projectStatus ? 'selected' : '' ?>><?= adminFacultyCollaborationEscape(match ($projectStatus) {
              'under_crec_review' => 'CREC Review (legacy)',
              'under_erec_review' => 'EREC Review (legacy)',
              default => ucwords(str_replace('_', ' ', $projectStatus)),
          }) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="academic-year-filter">School year
      <select id="academic-year-filter" name="academic_year">
        <option value="" <?= $academicYearFilter === '' ? 'selected' : '' ?>>All school years</option>
        <?php foreach ($academicYears as $academicYear): ?>
          <option value="<?= adminFacultyCollaborationEscape($academicYear) ?>" <?= $academicYearFilter === $academicYear ? 'selected' : '' ?>><?= adminFacultyCollaborationEscape($academicYear) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="keyword-filter">Project or student
      <input id="keyword-filter" name="keyword" type="search" maxlength="100" value="<?= adminFacultyCollaborationEscape($keywordFilter) ?>" placeholder="Search names or project titles">
    </label>
    <div class="collaboration-filter-actions">
      <button class="collaboration-submit" type="submit">Apply filters</button>
      <?php if ($courseFilter !== '' || $statusFilter !== '' || $academicYearFilter !== '' || $keywordFilter !== ''): ?><a class="collaboration-course-clear" href="<?= adminFacultyCollaborationEscape(SITE_URL . 'pages/admin/admin-faculty-collaboration.php') ?>">Clear all</a><?php endif; ?>
    </div>
  </form>
  <div class="collaboration-filter-context" aria-live="polite">
    <p><?= adminFacultyCollaborationEscape($memberCountMessage) ?></p>
    <?php if ($activeFilterLabels): ?>
      <?php foreach ($activeFilterLabels as $activeFilterLabel): ?><span class="collaboration-filter-chip"><?= adminFacultyCollaborationEscape($activeFilterLabel) ?></span><?php endforeach; ?>
    <?php else: ?><span>All eligible programs, project statuses, and school years</span><?php endif; ?>
  </div>
  <section class="collaboration-stats" aria-label="Available assignment options">
    <div class="collaboration-stat"><strong><?= count($projects) ?></strong><span>Recent projects available</span></div>
    <div class="collaboration-stat"><strong><?= count($researchers) ?></strong><span>Active student accounts available</span></div>
    <div class="collaboration-stat"><strong><?= count($advisers) ?></strong><span>Current adviser assignments shown</span></div>
  </section>
    <div class="collaboration-grid">
    <section class="collaboration-panel">
      <h2>Assign a team member</h2>
      <p class="collaboration-panel-intro">Add an eligible active BSIT/BSCS student to a project team.</p>
      <form class="collaboration-form" method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="assign_member">
        <input type="hidden" name="course" value="<?= adminFacultyCollaborationEscape($courseFilter) ?>">
        <input type="hidden" name="status" value="<?= adminFacultyCollaborationEscape($statusFilter) ?>">
        <input type="hidden" name="academic_year" value="<?= adminFacultyCollaborationEscape($academicYearFilter) ?>">
        <input type="hidden" name="keyword" value="<?= adminFacultyCollaborationEscape($keywordFilter) ?>">
        <label>Research project<select name="project_id" required><option value="">Choose a project</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['project_id'] ?>"><?= adminFacultyCollaborationEscape($project['title'] . ' · ' . ($project['program_code'] ?: 'Unmapped course') . ' · ' . ucfirst(str_replace('_', ' ', (string) $project['status']))) ?></option><?php endforeach; ?></select></label>
        <label>Student researcher<select name="user_id" required><option value="">Choose a student</option><?php foreach ($researchers as $researcher): ?><option value="<?= (int) $researcher['user_id'] ?>"><?= adminFacultyCollaborationEscape(trim($researcher['first_name'] . ' ' . $researcher['last_name']) . ' · ' . $researcher['program_code'] . ($researcher['department'] ? ' · ' . $researcher['department'] : '')) ?></option><?php endforeach; ?></select></label>
        <label>Team role<select name="member_role"><option value="member">Member</option><option value="lead">Lead</option></select></label>
        <button class="collaboration-submit" type="submit">Add to team</button>
      </form>
    </section>
  </div>
  <section class="collaboration-panel">
    <h2>Current research teams</h2>
    <p class="collaboration-panel-intro">Team assignments matching the selected program, project status, school year, and search terms.</p>
    <?php if (!$members): ?><div class="collaboration-empty">No project team assignments match these filters.</div><?php else: ?>
      <div class="collaboration-table-wrap"><table class="collaboration-table"><thead><tr><th>Research project</th><th>Researcher</th><th>Course/program</th><th>Role</th><th>Department</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($members as $member): ?><tr><td><?= adminFacultyCollaborationEscape($member['project_title']) ?></td><td><?= adminFacultyCollaborationEscape(trim($member['first_name'] . ' ' . $member['last_name'])) ?></td><td><?= adminFacultyCollaborationEscape($member['program_code'] ?: 'Unmapped') ?></td><td><?= adminFacultyCollaborationEscape(ucfirst((string) $member['role']) . ' · ' . ucfirst((string) $member['user_role'])) ?></td><td><?= adminFacultyCollaborationEscape($member['department'] ?: '—') ?></td><td><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="course" value="<?= adminFacultyCollaborationEscape($courseFilter) ?>"><input type="hidden" name="status" value="<?= adminFacultyCollaborationEscape($statusFilter) ?>"><input type="hidden" name="keyword" value="<?= adminFacultyCollaborationEscape($keywordFilter) ?>"><input type="hidden" name="assignment_id" value="<?= (int) $member['project_member_id'] ?>"><button class="assignment-remove" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="collaboration-panel collaboration-adviser-panel">
    <h2>Current faculty advising assignments</h2>
    <p class="collaboration-panel-intro">The table shows up to 500 active adviser assignments created through the student request workflow. Admins can review these assignments but do not change them here.</p>
    <?php if (!$advisers): ?><div class="collaboration-empty">No faculty advising assignments found.</div><?php else: ?>
      <div class="collaboration-table-wrap"><table class="collaboration-table"><thead><tr><th>Research project</th><th>Faculty adviser</th><th>Department</th><th>Assigned</th></tr></thead><tbody>
    <?php foreach ($advisers as $adviser): ?><tr><td><?= adminFacultyCollaborationEscape($adviser['project_title']) ?></td><td><?= adminFacultyCollaborationEscape(trim($adviser['first_name'] . ' ' . $adviser['last_name'])) ?><?= (int) $adviser['is_legacy'] === 1 ? ' · Legacy assignment' : '' ?></td><td><?= adminFacultyCollaborationEscape($adviser['department'] ?: '—') ?></td><td><?= adminFacultyCollaborationEscape(date('M j, Y', strtotime((string) $adviser['assigned_at']))) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="collaboration-panel collaboration-history-panel">
    <h2>Previous faculty advisers</h2>
    <p class="collaboration-history-note">This historical log records resolved adviser changes from the student-request workflow. It is read-only for review.</p>
    <?php if (!$hasAdviserHistory): ?>
      <div class="collaboration-empty">Adviser history is unavailable. Apply migration 009 before removing adviser assignments.</div>
    <?php elseif (!$adviserHistory): ?>
      <div class="collaboration-empty">No previous adviser assignments recorded.</div>
    <?php else: ?>
      <div class="collaboration-table-wrap">
        <table class="collaboration-table">
          <thead><tr><th>Research project</th><th>Former adviser</th><th>Assigned</th><th>Removed</th><th>Removed by</th></tr></thead>
          <tbody>
            <?php foreach ($adviserHistory as $history): ?>
              <tr>
                <td><?= adminFacultyCollaborationEscape($history['project_title'] ?: 'Project #' . $history['project_id']) ?></td>
                <td><?= adminFacultyCollaborationEscape($history['adviser_name']) ?></td>
                <td><?= adminFacultyCollaborationEscape(date('M j, Y', strtotime((string) $history['assigned_at']))) ?></td>
                <td><?= adminFacultyCollaborationEscape(date('M j, Y', strtotime((string) $history['removed_at']))) ?></td>
                <td><?= adminFacultyCollaborationEscape($history['removed_by_name']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php renderAdminShellClose(); ?>
