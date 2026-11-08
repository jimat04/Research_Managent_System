<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';

requireRole('admin');
$user = getCurrentUser();

function adminResearchersEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminResearchersIsHttpsUrl(?string $url): bool
{
    if (!$url || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https';
}

function adminResearchersTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function adminResearchersRun(mysqli_stmt $stmt, string $types = '', array $params = []): mysqli_result
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

$hasActivities = adminResearchersTableExists($conn, 'activity_participants')
    && adminResearchersTableExists($conn, 'research_activities');
$hasPublications = adminResearchersTableExists($conn, 'publications')
    && adminResearchersTableExists($conn, 'publication_contributors');
$hasCopyrights = adminResearchersTableExists($conn, 'copyright_applications')
    && adminResearchersTableExists($conn, 'copyright_contributors');
$hasFacultyProfiles = adminResearchersTableExists($conn, 'faculty_research_profiles');
$hasFacultyProgramAssignments = adminResearchersTableExists($conn, 'faculty_program_assignments')
    && adminResearchersTableExists($conn, 'programs');

$researcherId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$roleInput = trim((string) ($_GET['role'] ?? ''));
$roleFilter = in_array($roleInput, ['faculty', 'student'], true) ? $roleInput : '';
$academicYearInput = trim((string) ($_GET['academic_year'] ?? ''));
$academicYears = [];
if (!$researcherId) {
    $stmt = $conn->prepare(
        'SELECT DISTINCT ay.label
           FROM academic_years ay
           INNER JOIN research_projects rp ON rp.ay_id = ay.ay_id
          WHERE rp.deleted_at IS NULL
          ORDER BY ay.label DESC'
    );
    $result = adminResearchersRun($stmt);
    while ($row = $result->fetch_assoc()) {
        $academicYears[] = (string) $row['label'];
    }
    $stmt->close();
}
$academicYearFilter = in_array($academicYearInput, $academicYears, true) ? $academicYearInput : '';
if ($academicYearFilter !== '') {
    $roleFilter = 'student';
}
$statusInput = trim((string) ($_GET['status'] ?? ''));
$statusFilter = in_array($statusInput, ['active', 'pending', 'suspended'], true) ? $statusInput : '';
$search = trim((string) ($_GET['q'] ?? ''));
$search = strlen($search) > 150 ? substr($search, 0, 150) : $search;

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '';
$statusInput = trim((string) ($_GET['activity_status'] ?? ''));
$activityStatusOptions = [
    'upcoming' => 'Upcoming',
    'ongoing' => 'Ongoing',
    'conducted' => 'Conducted',
    'cancelled' => 'Cancelled',
];
$activityStatusValues = [
    'upcoming' => ['upcoming', 'incoming', 'scheduled'],
    'ongoing' => ['ongoing'],
    'conducted' => ['conducted', 'completed'],
    'cancelled' => ['cancelled'],
];
$activityStatusFilter = array_key_exists($statusInput, $activityStatusOptions) ? $statusInput : '';

$profile = null;
$activities = [];
$publications = [];
$copyrights = [];
$researchProjects = [];
$researchProfile = null;
$profileProgram = '';
$researchers = [];
$capped = false;

if ($researcherId) {
    $stmt = $conn->prepare("SELECT user_id, role, first_name, last_name, email, student_id, department, program, year_level, specialization, academic_rank, status, created_at FROM users WHERE user_id = ? AND role IN ('faculty', 'student') AND deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $researcherId);
    $profile = adminResearchersRun($stmt)->fetch_assoc() ?: null;
    $stmt->close();

    if ($profile) {
        $profileProgram = trim((string) ($profile['program'] ?? ''));
        if ($profile['role'] === 'faculty' && $hasFacultyProgramAssignments) {
            $stmt = $conn->prepare(
                "SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.program_code), '') ORDER BY p.program_code SEPARATOR ', ') AS program_codes
                   FROM faculty_program_assignments fpa
                   INNER JOIN programs p ON p.program_id = fpa.program_id
                  WHERE fpa.faculty_id = ?"
            );
            $stmt->bind_param('i', $researcherId);
            $programCodes = adminResearchersRun($stmt)->fetch_assoc();
            $stmt->close();
            $assignedProgramCodes = trim((string) ($programCodes['program_codes'] ?? ''));
            if ($assignedProgramCodes !== '') {
                $profileProgram = $assignedProgramCodes;
            }
        }
    }

    if ($profile && $profile['role'] === 'faculty' && $hasFacultyProfiles) {
        $stmt = $conn->prepare('SELECT highest_degree, research_interests, orcid_url, google_scholar_url, researchgate_url FROM faculty_research_profiles WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $researcherId);
        $stmt->execute();
        $researchProfile = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($profile) {
        $stmt = $conn->prepare(
            'SELECT rp.project_id, rp.title, rp.status, rp.updated_at
               FROM research_projects rp
              WHERE rp.deleted_at IS NULL
                AND (rp.created_by = ? OR rp.student_id = ? OR rp.adviser_id = ?
                  OR EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?)
                  OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = ?))
              ORDER BY rp.updated_at DESC, rp.project_id DESC
              LIMIT 100'
        );
        $stmt->bind_param('iiiii', $researcherId, $researcherId, $researcherId, $researcherId, $researcherId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $researchProjects[] = $row;
        }
        $stmt->close();
    }
    if ($profile && $hasActivities) {
        $sql = 'SELECT ra.activity_id, ra.activity_type, ra.title, ra.activity_date, ra.status, ap.role AS participant_role, ap.attended FROM activity_participants ap INNER JOIN research_activities ra ON ra.activity_id = ap.activity_id WHERE ap.user_id = ?';
        $params = [$researcherId];
        $types = 'i';
        if ($dateFrom !== '') {
            $sql .= ' AND ra.activity_date >= ?';
            $params[] = $dateFrom;
            $types .= 's';
        }
        if ($dateTo !== '') {
            $sql .= ' AND ra.activity_date <= ?';
            $params[] = $dateTo;
            $types .= 's';
        }
        if ($activityStatusFilter !== '') {
            $statusValues = $activityStatusValues[$activityStatusFilter];
            $sql .= ' AND ra.status IN (' . implode(', ', array_fill(0, count($statusValues), '?')) . ')';
            foreach ($statusValues as $statusValue) {
                $params[] = $statusValue;
                $types .= 's';
            }
        }
        $sql .= ' ORDER BY ra.activity_date DESC, ra.activity_id DESC';
        $stmt = $conn->prepare($sql);
        $result = adminResearchersRun($stmt, $types, $params);
        while ($row = $result->fetch_assoc()) $activities[] = $row;
        $stmt->close();
    }
    if ($profile && $hasPublications) {
        $stmt = $conn->prepare('SELECT p.publication_id, p.research_title, p.publication_type, p.archival_status AS status, p.publication_date FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?) ORDER BY p.created_at DESC, p.publication_id DESC LIMIT 100');
        $result = adminResearchersRun($stmt, 'i', [$researcherId]);
        while ($row = $result->fetch_assoc()) $publications[] = $row;
        $stmt->close();
    }
    if ($profile && $hasCopyrights) {
        $stmt = $conn->prepare("SELECT c.copyright_id, c.output_title, c.output_type, CASE WHEN c.output_type IN ('software','research','instructional_material','module','other') THEN c.archival_status ELSE c.status END AS status, c.copyright_ref_no, c.registered_at FROM copyright_applications c WHERE c.deactivated_at IS NULL AND ((c.output_type IN ('software','research','instructional_material','module','other') AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = ?)) OR (c.output_type NOT IN ('software','research','instructional_material','module','other') AND c.applicant_id = ?)) ORDER BY c.created_at DESC, c.copyright_id DESC LIMIT 100");
        $result = adminResearchersRun($stmt, 'ii', [$researcherId, $researcherId]);
        while ($row = $result->fetch_assoc()) $copyrights[] = $row;
        $stmt->close();
    }
} else {
    $selectCounts = [
        $hasActivities && $roleFilter !== 'student'
            ? '(SELECT COUNT(DISTINCT ap.activity_id) FROM activity_participants ap WHERE ap.user_id = u.user_id) AS activity_count'
            : '0 AS activity_count',
        $hasPublications && $roleFilter !== 'student'
            ? '(SELECT COUNT(*) FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = u.user_id)) AS publication_count'
            : '0 AS publication_count',
        $hasCopyrights && $roleFilter !== 'student'
            ? "(SELECT COUNT(*) FROM copyright_applications c WHERE c.deactivated_at IS NULL AND ((c.output_type IN ('software','research','instructional_material','module','other') AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = u.user_id)) OR (c.output_type NOT IN ('software','research','instructional_material','module','other') AND c.applicant_id = u.user_id))) AS copyright_count"
            : '0 AS copyright_count',
        $roleFilter !== 'faculty'
            ? '(SELECT COUNT(*) FROM research_projects rp WHERE rp.deleted_at IS NULL AND (rp.created_by = u.user_id OR rp.student_id = u.user_id OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id))) AS project_count'
            : '0 AS project_count',
        $roleFilter !== 'faculty'
            ? '(SELECT COUNT(DISTINCT c.chapter_id) FROM chapters c INNER JOIN research_projects rp ON rp.project_id = c.project_id WHERE c.deleted_at IS NULL AND c.submitted_at IS NOT NULL AND rp.deleted_at IS NULL AND (rp.created_by = u.user_id OR rp.student_id = u.user_id OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id))) AS submitted_chapter_count'
            : '0 AS submitted_chapter_count',
        $roleFilter !== 'faculty'
            ? '(SELECT COUNT(DISTINCT c.chapter_id) FROM chapters c INNER JOIN research_projects rp ON rp.project_id = c.project_id WHERE c.deleted_at IS NULL AND c.status = \'approved\' AND rp.deleted_at IS NULL AND (rp.created_by = u.user_id OR rp.student_id = u.user_id OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = rp.project_id AND pm.user_id = u.user_id))) AS approved_chapter_count'
            : '0 AS approved_chapter_count',
        $hasFacultyProfiles && $roleFilter !== 'student'
            ? 'EXISTS (SELECT 1 FROM faculty_research_profiles frp WHERE frp.user_id = u.user_id) AS has_research_profile'
            : '0 AS has_research_profile',
    ];
    $sql = 'SELECT u.user_id, u.role, u.first_name, u.last_name, u.email, u.student_id, u.department, u.program, u.year_level, u.specialization, u.academic_rank, u.status, '
        . implode(', ', $selectCounts)
        . " FROM users u WHERE u.role IN ('faculty', 'student') AND u.deleted_at IS NULL";
    $params = [];
    $types = '';
    if ($roleFilter !== '') {
        $sql .= ' AND u.role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }
    if ($statusFilter !== '') {
        $sql .= ' AND u.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($academicYearFilter !== '') {
        $sql .= ' AND EXISTS (
            SELECT 1
              FROM research_projects ay_rp
              INNER JOIN academic_years ay ON ay.ay_id = ay_rp.ay_id
             WHERE ay_rp.deleted_at IS NULL
               AND ay.label = ?
               AND (ay_rp.created_by = u.user_id
                 OR ay_rp.student_id = u.user_id
                 OR EXISTS (
                     SELECT 1 FROM project_members ay_pm
                      WHERE ay_pm.project_id = ay_rp.project_id AND ay_pm.user_id = u.user_id
                 ))
        )';
        $params[] = $academicYearFilter;
        $types .= 's';
    }
    if ($search !== '') {
        $searchableFields = 'u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.program LIKE ? OR u.specialization LIKE ?';
        $searchParamCount = 6;
        if ($roleFilter === 'student') {
            $searchableFields .= ' OR u.student_id LIKE ? OR u.year_level LIKE ?';
            $searchParamCount += 2;
        }
        if ($hasFacultyProfiles && $roleFilter !== 'student') {
            $searchableFields .= ' OR EXISTS (SELECT 1 FROM faculty_research_profiles frp WHERE frp.user_id = u.user_id AND frp.research_interests LIKE ?)';
        }
        $sql .= ' AND (' . $searchableFields . ')';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        for ($searchParam = 0; $searchParam < $searchParamCount; $searchParam++) {
            $params[] = $like;
        }
        $types .= str_repeat('s', $searchParamCount);
        if ($hasFacultyProfiles && $roleFilter !== 'student') {
            $params[] = $like;
            $types .= 's';
        }
    }
    $sql .= " ORDER BY FIELD(u.status, 'active', 'pending', 'suspended'), u.last_name ASC, u.first_name ASC LIMIT 251";
    $stmt = $conn->prepare($sql);
    $result = adminResearchersRun($stmt, $types, $params);
    while ($row = $result->fetch_assoc()) $researchers[] = $row;
    $stmt->close();
    $capped = count($researchers) > 250;
    if ($capped) $researchers = array_slice($researchers, 0, 250);
}

renderAdminShell(
    $user,
    'admin-researchers.php',
    $profile ? 'Researcher Profile' : 'Researcher Profiles',
    $profile ? 'Research specialization and participation history.' : 'Browse faculty and student researcher profiles and activity.'
);
?>
<style>
.researcher-page{max-width:1320px;margin:0 auto;color:#111827}.researcher-intro{margin-bottom:22px;padding:26px 28px;border:1px solid rgba(91,30,188,.18);border-left:4px solid #5b1ebc;border-radius:16px;background:linear-gradient(135deg,rgba(91,30,188,.08),#fff)}.researcher-intro h1{margin:0;font-size:clamp(25px,3vw,36px);letter-spacing:-.035em}.researcher-intro p{margin:8px 0 0;color:#64748b;line-height:1.55}.researcher-panel{overflow:hidden;margin-bottom:22px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.04)}.researcher-panel h2{margin:0;font-size:18px}.panel-heading{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid #edf2f7}.panel-heading-left{display:flex;flex-direction:column;gap:4px;min-width:0}.researcher-muted{color:#64748b}.researcher-summary{display:flex;flex-wrap:wrap;gap:8px}.researcher-summary-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;color:#374151;font-size:11px;font-weight:700}.profile-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0}.profile-field{padding:17px 20px;border-bottom:1px solid #edf2f7}.profile-field span{display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700}.profile-field strong{font-size:13px;overflow-wrap:anywhere}.researcher-toolbar{display:grid;grid-template-columns:minmax(200px,1.8fr) minmax(160px,.9fr) minmax(160px,.9fr) auto;gap:12px;align-items:end;padding:18px 20px;border-bottom:1px solid #edf2f7}.researcher-toolbar label{display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700}.researcher-toolbar input,.researcher-toolbar select{width:100%;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:white;color:#111827;font:inherit}.researcher-toolbar input:focus,.researcher-toolbar select:focus,.researcher-button:focus-visible,.researcher-tab:focus-visible{outline:3px solid rgba(91,30,188,.16);outline-offset:2px;border-color:#5b1ebc}.researcher-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:8px 14px;border:1px solid #5b1ebc;border-radius:9px;background:#5b1ebc;color:#fff;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}.researcher-button.secondary{border-color:#d1d5db;background:#fff;color:#374151}.researcher-tabs{display:flex;flex-wrap:wrap;gap:10px;padding:16px 20px 0}.researcher-tab{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;color:#475569;text-decoration:none;font-weight:700;font-size:12px}.researcher-tab.active{background:linear-gradient(135deg,#5b1ebc,#7c3aed);border-color:transparent;color:#fff;box-shadow:0 8px 20px rgba(91,30,188,.18)}.researcher-list-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;padding:18px 20px 20px}.researcher-card{display:flex;flex-direction:column;gap:14px;padding:18px;border:1px solid #e5e7eb;border-radius:16px;background:linear-gradient(180deg,#fff,#f8fafc);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}.researcher-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.06);border-color:rgba(91,30,188,.15)}.researcher-card-head{display:flex;align-items:center;gap:12px}.researcher-avatar{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#5b1ebc,#8b5cf6);color:#fff;font-weight:800}.researcher-meta{flex:1;min-width:0}.researcher-meta h3{margin:0;font-size:20px;line-height:1.2;letter-spacing:-.02em}.researcher-meta h3 a{color:#111827;text-decoration:none}.researcher-email{margin-top:4px;color:#64748b;font-size:12px;word-break:break-word}.researcher-role-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.researcher-role-chip.role-faculty{background:rgba(29,78,216,.08);color:#1d4ed8}.researcher-role-chip.role-student{background:rgba(91,30,188,.08);color:#5b1ebc}.researcher-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding-top:4px;border-top:1px solid #edf2f7}.researcher-details div{display:flex;flex-direction:column;gap:4px}.researcher-details span{color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.researcher-details strong{font-size:13px;line-height:1.4;overflow-wrap:anywhere}.researcher-stat-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.researcher-stat{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:10px;background:#fff;border:1px solid #edf2f7}.researcher-stat span{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700}.researcher-stat strong{font-size:20px;line-height:1}.researcher-card-actions{display:flex;justify-content:flex-end}.researcher-table-wrap{overflow-x:auto}.researcher-table{width:100%;border-collapse:collapse;min-width:940px}.researcher-table th{padding:12px 15px;background:#f8fafc;color:#64748b;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em}.researcher-table td{padding:13px 15px;border-top:1px solid #edf2f7;font-size:12px;vertical-align:middle}.researcher-link{color:#111827;font-weight:700;text-decoration:none}.researcher-link:hover{color:#5b1ebc}.researcher-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#fff4e8;color:#9a3f00;font-size:10px;font-weight:700}.researcher-empty{padding:42px 20px;text-align:center;color:#64748b}.researcher-empty strong{display:block;margin-bottom:5px;color:#111827}.activity-status{color:#475569}.back-link{color:#9a3f00;font-size:12px;font-weight:700;text-decoration:none}@media(max-width:980px){.researcher-toolbar{grid-template-columns:1fr 1fr}.researcher-list-grid{grid-template-columns:1fr}}@media(max-width:760px){.panel-heading{align-items:flex-start;gap:8px}.panel-heading > *:not(.panel-heading-left){margin-left:auto}.researcher-toolbar{grid-template-columns:1fr}.researcher-list-grid{grid-template-columns:1fr}.researcher-details{grid-template-columns:1fr}.researcher-stat-row{grid-template-columns:1fr 1fr 1fr}.researcher-card-actions .researcher-button{width:100%}}@media(max-width:560px){.panel-heading{justify-content:flex-start}.panel-heading > *:not(.panel-heading-left){margin-left:0;margin-top:8px}.researcher-summary{width:100%}.researcher-card-head{align-items:flex-start}.researcher-role-chip{margin-top:4px}.researcher-stat-row{grid-template-columns:1fr}.profile-grid{grid-template-columns:1fr}}
.researcher-intro{display:flex;align-items:center;justify-content:space-between;gap:18px}
.researcher-print-meta{display:none}
@media print{
  @page{margin:16mm}
  body *{visibility:hidden!important}
  body .researcher-page,body .researcher-page *{visibility:visible!important}
  body .researcher-page{position:absolute;inset:0;width:100%;max-width:none;margin:0;padding:0;color:#111827}
  .researcher-page> *{display:none!important}
  .researcher-page>.researcher-intro,.researcher-page>[data-print-section="identity"],.researcher-page>[data-print-section="participation"]{display:block!important}
  .researcher-intro{display:block!important;margin:0 0 16px;padding:0 0 12px;border:0;border-bottom:2px solid #1d4ed8;border-radius:0;background:none}
  .researcher-intro h1{font-size:24px}
  .researcher-intro>div>p:not(.researcher-print-meta){display:none!important}
  .researcher-print-meta{display:block!important;margin:5px 0 0;color:#475569;font-size:11px}
  .print-button,.researcher-page [data-print-field="screen-only"],.researcher-page .back-link{display:none!important}
  .researcher-page .researcher-toolbar{display:none!important}
  .researcher-panel{overflow:visible;margin:0 0 14px;border:1px solid #cbd5e1;border-radius:0;box-shadow:none;break-inside:avoid}
  .researcher-panel .panel-heading{padding:9px 11px}
  .researcher-panel .profile-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .researcher-panel .profile-field{padding:9px 11px}
  .researcher-panel .researcher-table-wrap{overflow:visible}
  .researcher-panel .researcher-table{width:100%;min-width:0;table-layout:auto}
  .researcher-panel .researcher-table th,.researcher-panel .researcher-table td{padding:7px 8px;font-size:10px}
  .researcher-panel .researcher-table th{background:#f1f5f9!important;color:#334155}
  .researcher-panel .researcher-link{color:#111827;text-decoration:none}
  .researcher-panel .researcher-table tr{break-inside:avoid}
}
@media(min-width:981px){.researcher-toolbar{grid-template-columns:minmax(200px,1.8fr) repeat(3,minmax(140px,.9fr)) auto}}
</style>

<main class="researcher-page">
  <header class="researcher-intro"><div><h1><?= adminResearchersEscape($profile ? trim($profile['first_name'] . ' ' . $profile['last_name']) : 'Researcher Profiles') ?></h1><p><?= $profile ? 'Research profile and associated participation records.' : 'Faculty and student researchers, their specializations, and their recorded research participation.' ?></p><p class="researcher-print-meta">Faculty participation report · Printed <?= adminResearchersEscape(date('F j, Y')) ?></p></div><?php if ($profile && $profile['role'] === 'faculty'): ?><button class="researcher-button print-button" type="button" onclick="window.print()">Print participation</button><?php endif; ?></header>

  <?php if ($researcherId && !$profile): ?>
    <section class="researcher-panel researcher-empty"><strong>Researcher not found</strong>The researcher may have been removed or the profile link is invalid.<p><a class="researcher-button" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">Return to researchers</a></p></section>
  <?php elseif ($profile): ?>
    <p><a class="back-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">&larr; All researchers</a></p>
    <section class="researcher-panel" aria-labelledby="profile-heading" data-print-section="identity">
      <div class="panel-heading"><h2 id="profile-heading">Researcher information</h2><span class="researcher-badge"><?= adminResearchersEscape(ucfirst((string) $profile['role'])) ?></span></div>
      <div class="profile-grid">
        <div class="profile-field" data-print-field="identity"><span>Email</span><strong><?= adminResearchersEscape($profile['email']) ?></strong></div>
        <?php if ($profile['role'] === 'student'): ?><div class="profile-field"><span>Student ID</span><strong><?= adminResearchersEscape($profile['student_id'] ?: 'Not recorded') ?></strong></div><?php endif; ?>
        <?php if ($profile['role'] === 'faculty'): ?>
          <div class="profile-field" data-print-field="identity"><span>Research specialization</span><strong><?= adminResearchersEscape($profile['specialization'] ?: 'Not recorded') ?></strong></div>
          <div class="profile-field" data-print-field="identity"><span>Academic rank</span><strong><?= adminResearchersEscape($profile['academic_rank'] ?: 'Not recorded') ?></strong></div>
        <?php endif; ?>
        <?php if ($profile['role'] === 'faculty'): ?><div class="profile-field" data-print-field="identity"><span>Department</span><strong><?= adminResearchersEscape($profile['department'] ?: 'Not recorded') ?></strong></div><?php endif; ?>
        <div class="profile-field" data-print-field="screen-only"><span>Program</span><strong><?= adminResearchersEscape($profileProgram ?: 'Not recorded') ?></strong></div>
        <div class="profile-field" data-print-field="screen-only"><span>Account status</span><strong><?= adminResearchersEscape(ucfirst((string) $profile['status'])) ?></strong></div>
        <?php if ($profile['role'] === 'student'): ?><div class="profile-field"><span>Year level</span><strong><?= adminResearchersEscape($profile['year_level'] ?: 'Not recorded') ?></strong></div><?php endif; ?>
      </div>
    </section>

    <?php if ($profile['role'] === 'faculty'): ?>
      <section class="researcher-panel" aria-labelledby="faculty-profile-heading">
        <div class="panel-heading">
          <div><h2 id="faculty-profile-heading">Faculty research profile</h2><span class="researcher-muted">Research interests and scholarly identity supplied by this faculty member.</span></div>
          <?php if ($researchProfile): ?><span class="researcher-badge">Saved</span><?php endif; ?>
        </div>
        <?php if (!$hasFacultyProfiles): ?>
          <div class="researcher-empty"><strong>Faculty profile details unavailable</strong>Apply migration 024 to enable faculty research profiles.</div>
        <?php elseif (!$researchProfile): ?>
          <div class="researcher-empty"><strong>No faculty research profile saved</strong>This faculty member has not provided additional research profile details.</div>
        <?php else: ?>
          <div class="profile-grid">
            <div class="profile-field"><span>Highest degree</span><strong><?= adminResearchersEscape($researchProfile['highest_degree'] ?: 'Not recorded') ?></strong></div>
            <div class="profile-field"><span>ORCID</span><strong><?php if (adminResearchersIsHttpsUrl($researchProfile['orcid_url'])): ?><a href="<?= adminResearchersEscape($researchProfile['orcid_url']) ?>" target="_blank" rel="noopener noreferrer"><?= adminResearchersEscape($researchProfile['orcid_url']) ?></a><?php else: ?>Not linked<?php endif; ?></strong></div>
            <div class="profile-field"><span>Google Scholar</span><strong><?php if (adminResearchersIsHttpsUrl($researchProfile['google_scholar_url'])): ?><a href="<?= adminResearchersEscape($researchProfile['google_scholar_url']) ?>" target="_blank" rel="noopener noreferrer">View profile</a><?php else: ?>Not linked<?php endif; ?></strong></div>
            <div class="profile-field"><span>ResearchGate</span><strong><?php if (adminResearchersIsHttpsUrl($researchProfile['researchgate_url'])): ?><a href="<?= adminResearchersEscape($researchProfile['researchgate_url']) ?>" target="_blank" rel="noopener noreferrer">View profile</a><?php else: ?>Not linked<?php endif; ?></strong></div>
            <div class="profile-field"><span>Research interests</span><strong><?= nl2br(adminResearchersEscape($researchProfile['research_interests'] ?: 'Not recorded')) ?></strong></div>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="researcher-panel" aria-labelledby="projects-heading">
      <div class="panel-heading"><div><h2 id="projects-heading">Linked research projects</h2><span class="researcher-muted">Projects where this researcher is an owner, member, or adviser.</span></div></div>
      <?php if (!$researchProjects): ?><div class="researcher-empty"><strong>No linked research projects</strong>Project relationships will appear here when the researcher is assigned to a project.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Research project</th><th>Status</th><th>Last updated</th></tr></thead><tbody><?php foreach ($researchProjects as $project): ?><tr><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= adminResearchersEscape($project['title']) ?></a></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $project['status']))) ?></td><td><?= adminResearchersEscape(date('M j, Y', strtotime((string) $project['updated_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

      <section class="researcher-panel" aria-labelledby="participation-heading" data-print-section="participation">
      <div class="panel-heading">
        <div><h2 id="participation-heading">Research participation history</h2><span class="researcher-muted">Activities linked to this user account.</span></div>
        <form class="researcher-toolbar" method="get" action="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . $researcherId) ?>" style="padding:12px 20px 18px;border-top:1px solid #edf2f7;gap:10px;grid-template-columns:minmax(140px,1fr) minmax(140px,1fr) minmax(160px,1fr) auto;align-items:end">
          <input type="hidden" name="id" value="<?= (int) $researcherId ?>">
          <div>
            <label for="date-from" style="display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700">From date</label>
            <input id="date-from" name="date_from" type="date" value="<?= adminResearchersEscape($dateFrom) ?>" style="width:100%;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:white;color:#111827;font:inherit">
          </div>
          <div>
            <label for="date-to" style="display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700">To date</label>
            <input id="date-to" name="date_to" type="date" value="<?= adminResearchersEscape($dateTo) ?>" style="width:100%;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:white;color:#111827;font:inherit">
          </div>
          <div>
            <label for="activity-status" style="display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700">Activity status</label>
            <select id="activity-status" name="activity_status" style="width:100%;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;background:white;color:#111827;font:inherit">
              <option value="">All statuses</option>
              <?php foreach ($activityStatusOptions as $statusValue => $statusLabel): ?>
                <option value="<?= adminResearchersEscape($statusValue) ?>" <?= $activityStatusFilter === $statusValue ? 'selected' : '' ?>><?= adminResearchersEscape($statusLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <button class="researcher-button" type="submit" style="min-height:42px">Filter</button>
            <?php if ($dateFrom !== '' || $dateTo !== '' || $activityStatusFilter !== ''): ?>
              <a class="researcher-button secondary" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . $researcherId) ?>" style="min-height:42px">Clear</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <?php if (!$hasActivities): ?><div class="researcher-empty"><strong>Activity history unavailable</strong>Install migration 012 to enable activity records.</div>
      <?php elseif (!$activities): ?><div class="researcher-empty"><strong>No linked participation records</strong>Participation appears here when this researcher is linked to an activity.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Date</th><th>Activity</th><th>Role</th><th>Status</th><th>Attendance</th></tr></thead><tbody><?php foreach ($activities as $activity): $activityTypeLabel = match ((string) $activity['activity_type']) { 'seminar_workshop' => 'Seminars / Workshops', 'training' => 'Trainings', 'colloquium' => 'Colloquia', 'conference_congress' => 'Conference / Congress', default => 'Research Activity' }; ?><tr><td><?= adminResearchersEscape(date('M j, Y', strtotime((string) $activity['activity_date']))) ?></td><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/activity-detail.php?id=' . (int) $activity['activity_id']) ?>"><?= adminResearchersEscape($activity['title']) ?></a><div class="researcher-muted"><?= adminResearchersEscape($activityTypeLabel) ?></div></td><td><?= adminResearchersEscape(ucfirst((string) $activity['participant_role'])) ?></td><td class="activity-status"><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $activity['status']))) ?></td><td><?= (int) $activity['attended'] === 1 ? 'Attended' : 'Not recorded' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="researcher-panel" aria-labelledby="publications-heading">
      <div class="panel-heading"><h2 id="publications-heading">Publications</h2></div>
      <?php if (!$hasPublications): ?><div class="researcher-empty"><strong>Publication records unavailable</strong>Install migration 012 to enable publication records.</div>
      <?php elseif (!$publications): ?><div class="researcher-empty"><strong>No publication records</strong>Publications associated with this researcher will appear here.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Publication date</th></tr></thead><tbody><?php foreach ($publications as $publication): ?><tr><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-publication-detail.php?id=' . (int) $publication['publication_id']) ?>"><?= adminResearchersEscape($publication['research_title']) ?></a></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $publication['publication_type']))) ?></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $publication['status']))) ?></td><td><?= adminResearchersEscape($publication['publication_date'] ? date('M j, Y', strtotime((string) $publication['publication_date'])) : 'Not recorded') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="researcher-panel" aria-labelledby="outputs-heading">
      <div class="panel-heading"><h2 id="outputs-heading">Copyright and intellectual outputs</h2></div>
      <?php if (!$hasCopyrights): ?><div class="researcher-empty"><strong>Output records unavailable</strong>Install migration 012 to enable copyright records.</div>
      <?php elseif (!$copyrights): ?><div class="researcher-empty"><strong>No copyright applications</strong>Applications associated with this researcher will appear here.</div>
      <?php else: ?><div class="researcher-table-wrap"><table class="researcher-table"><thead><tr><th>Output</th><th>Type</th><th>Status</th><th>Reference</th><th>Registered</th></tr></thead><tbody><?php foreach ($copyrights as $copyright): ?><tr><td><a class="researcher-link" href="<?= adminResearchersEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $copyright['copyright_id']) ?>"><?= adminResearchersEscape($copyright['output_title']) ?></a></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $copyright['output_type']))) ?></td><td><?= adminResearchersEscape(ucwords(str_replace('_', ' ', (string) $copyright['status']))) ?></td><td><?= adminResearchersEscape($copyright['copyright_ref_no'] ?: '—') ?></td><td><?= adminResearchersEscape($copyright['registered_at'] ? date('M j, Y', strtotime((string) $copyright['registered_at'])) : '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  <?php else: ?>
    <section class="researcher-panel" aria-labelledby="researcher-directory-heading">
      <div class="panel-heading">
        <div class="panel-heading-left">
          <h2 id="researcher-directory-heading">Researchers</h2>
          <span class="researcher-muted"><?= count($researchers) ?> <?= count($researchers) === 1 ? 'profile' : 'profiles' ?> shown</span>
        </div>
        <div class="researcher-summary">
          <?php $facultyCount = count(array_filter($researchers, fn($r) => ($r['role'] ?? '') === 'faculty')); ?>
          <?php $studentCount = count(array_filter($researchers, fn($r) => ($r['role'] ?? '') === 'student')); ?>
          <span class="researcher-summary-pill">Faculty: <?= $facultyCount ?></span>
          <span class="researcher-summary-pill">Students: <?= $studentCount ?></span>
        </div>
      </div>

      <form class="researcher-toolbar" method="get" action="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">
        <div>
          <label for="researcher-q">Name, email, department, program, or specialization</label>
          <input id="researcher-q" name="q" type="search" maxlength="150" value="<?= adminResearchersEscape($search) ?>" placeholder="Search researcher profiles">
        </div>
        <div>
          <label for="researcher-role">Role</label>
          <select id="researcher-role" name="role">
            <option value="">Faculty and students</option>
            <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Students</option>
          </select>
        </div>
        <div>
          <label for="researcher-status">Account status</label>
          <select id="researcher-status" name="status">
            <option value="">All statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
          </select>
        </div>
        <div>
          <label for="researcher-academic-year">Academic Year</label>
          <select id="researcher-academic-year" name="academic_year">
            <option value="">All academic years</option>
            <?php foreach ($academicYears as $academicYear): ?>
              <option value="<?= adminResearchersEscape($academicYear) ?>" <?= $academicYearFilter === $academicYear ? 'selected' : '' ?>><?= adminResearchersEscape($academicYear) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <button class="researcher-button" type="submit">Apply</button>
          <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== '' || $academicYearFilter !== ''): ?>
            <a class="researcher-button secondary" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php') ?>">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <nav class="researcher-tabs" aria-label="Researcher role filters">
        <?php $allTabHref = SITE_URL . 'pages/admin/admin-researchers.php' . ($search !== '' || $statusFilter !== '' ? '?q=' . rawurlencode($search) . '&status=' . rawurlencode($statusFilter) : ''); ?>
        <a class="researcher-tab <?= $roleFilter === '' ? 'active' : '' ?>" href="<?= adminResearchersEscape($allTabHref) ?>">All</a>
        <a class="researcher-tab <?= $roleFilter === 'faculty' ? 'active' : '' ?>" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?role=faculty' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '')) ?>">Faculty</a>
        <a class="researcher-tab <?= $roleFilter === 'student' ? 'active' : '' ?>" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?role=student' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '') . ($academicYearFilter !== '' ? '&academic_year=' . rawurlencode($academicYearFilter) : '')) ?>">Students</a>
      </nav>
      <?php if (!$researchers): ?><div class="researcher-empty"><strong>No researcher profiles found</strong>Try changing the search or filters.</div>
      <?php else: ?>
        <div class="researcher-list-grid">
          <?php foreach ($researchers as $researcher):
              $researcherName = trim((string) ($researcher['first_name'] ?? '') . ' ' . (string) ($researcher['last_name'] ?? ''));
              $initials = strtoupper(trim(substr((string) ($researcher['first_name'] ?? ''), 0, 1) . substr((string) ($researcher['last_name'] ?? ''), 0, 1)) ?: 'R');
              $roleKey = strtolower((string) ($researcher['role'] ?? 'researcher'));
          ?>
            <article class="researcher-card" aria-label="<?= adminResearchersEscape($researcherName) ?> profile">
              <div class="researcher-card-head">
                <div class="researcher-avatar"><?= adminResearchersEscape($initials) ?></div>
                <div class="researcher-meta">
                  <h3><a href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . (int) $researcher['user_id']) ?>"><?= adminResearchersEscape($researcherName ?: 'Unnamed researcher') ?></a></h3>
                  <div class="researcher-email"><?= adminResearchersEscape((string) ($researcher['email'] ?? '')) ?></div>
                </div>
                <span class="researcher-role-chip role-<?= adminResearchersEscape($roleKey) ?>"><?= adminResearchersEscape(ucfirst((string) ($researcher['role'] ?? 'researcher'))) ?></span>
              </div>

              <div class="researcher-details">
                <?php if ($researcher['role'] === 'student'): ?>
                  <div>
                    <span>Student ID</span>
                    <strong><?= adminResearchersEscape((string) ($researcher['student_id'] ?: 'Not recorded')) ?></strong>
                  </div>
                  <div>
                    <span>Year level</span>
                    <strong><?= adminResearchersEscape((string) ($researcher['year_level'] ?: 'Not recorded')) ?></strong>
                  </div>
                <?php endif; ?>
                <?php if ($researcher['role'] === 'faculty'): ?><div>
                  <span>Department</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['department'] ?: '—')) ?></strong>
                </div><?php endif; ?>
                <div>
                  <span>Program</span>
                  <strong><?= adminResearchersEscape((string) ($researcher['program'] ?: '—')) ?></strong>
                </div>
                <?php if ($researcher['role'] === 'faculty'): ?>
                  <div>
                    <span>Specialization</span>
                    <strong><?= adminResearchersEscape((string) ($researcher['specialization'] ?: '—')) ?></strong>
                  </div>
                  <div>
                    <span>Rank</span>
                    <strong><?= adminResearchersEscape((string) ($researcher['academic_rank'] ?: '—')) ?></strong>
                  </div>
                <?php endif; ?>
              </div>

              <div class="researcher-stat-row">
                <?php if ($researcher['role'] === 'student'): ?>
                  <div class="researcher-stat"><span>Research Projects</span><strong><?= (int) ($researcher['project_count'] ?? 0) ?></strong></div>
                  <div class="researcher-stat"><span>Chapters Submitted</span><strong><?= (int) ($researcher['submitted_chapter_count'] ?? 0) ?></strong></div>
                  <div class="researcher-stat"><span>Approved Chapters</span><strong><?= (int) ($researcher['approved_chapter_count'] ?? 0) ?></strong></div>
                <?php else: ?>
                  <div class="researcher-stat"><span>Activities</span><strong><?= (int) ($researcher['activity_count'] ?? 0) ?></strong></div>
                  <div class="researcher-stat"><span>Publications</span><strong><?= (int) ($researcher['publication_count'] ?? 0) ?></strong></div>
                  <div class="researcher-stat"><span>Outputs</span><strong><?= (int) ($researcher['copyright_count'] ?? 0) ?></strong></div>
                <?php endif; ?>
              </div>
              <?php if ($researcher['role'] === 'faculty'): ?>
                <span class="researcher-summary-pill">Research profile: <?= !empty($researcher['has_research_profile']) ? 'Saved' : 'Not saved' ?></span>
              <?php endif; ?>

              <div class="researcher-card-actions">
                <a class="researcher-button secondary" href="<?= adminResearchersEscape(SITE_URL . 'pages/admin/admin-researchers.php?id=' . (int) $researcher['user_id']) ?>">View profile</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($capped): ?><div class="researcher-empty">Showing the first 250 matching profiles. Refine the search to narrow the directory.</div><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php renderAdminShellClose(); ?>
