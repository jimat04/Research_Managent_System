<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/student-shell.php';
require_once __DIR__ . '/../../includes/research-workflow.php';

requireLogin();

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user_id = (int) $user['user_id'];
$role = (string) ($user['role'] ?? 'student');
$flash_success = $_SESSION['module_success'] ?? null;
$flash_error   = $_SESSION['module_error'] ?? null;
unset($_SESSION['module_success'], $_SESSION['module_error']);

// Get project ID from URL
$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$deleted_column_check = $conn->query("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$has_project_deleted_at = $deleted_column_check && $deleted_column_check->num_rows > 0;
if ($deleted_column_check) {
    $deleted_column_check->close();
}
$project_deleted_filter = $has_project_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// Access control & fetch project
$project = null;
$has_access = false;

if ($project_id > 0) {
    $query = "
        SELECT rp.*, rc.category_name, aa.label as ay_label, aa.semester, u.first_name, u.last_name
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years aa ON rp.ay_id = aa.ay_id
        LEFT JOIN users u ON rp.created_by = u.user_id
        WHERE rp.project_id = ?{$project_deleted_filter}
    ";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $project = $result->fetch_assoc();

            // Admin and Research Staff process projects system-wide.
            if ($role === 'admin') {
                $has_access = true;
            } elseif ($role === 'research_staff') {
                $has_access = hasModulePermission($user_id, 'research_development', 'view', 'research_proposals')
                    || hasModulePermission($user_id, 'research_development', 'view', 'research_review')
                    || hasModulePermission($user_id, 'research_development', 'view', 'research_approval');
            } elseif ($role === 'student') {
                // Students may only view projects they own or have joined.
                if ((int) $project['created_by'] === $user_id) {
                    $has_access = true;
                }
                if (!$has_access) {
                    $member_query = "SELECT project_member_id FROM project_members WHERE project_id = ? AND user_id = ?";
                    $member_stmt = $conn->prepare($member_query);
                    if ($member_stmt) {
                        $member_stmt->bind_param("ii", $project_id, $user_id);
                        $member_stmt->execute();
                        if ($member_stmt->get_result()->num_rows > 0) {
                            $has_access = true;
                        }
                        $member_stmt->close();
                    }
                }
            } elseif ($role === 'faculty') {
                // Faculty retain project visibility only for assigned advisees.
                $faculty_stmt = $conn->prepare(
                  'SELECT 1 FROM research_projects rp
                    WHERE rp.project_id = ? AND rp.deleted_at IS NULL
                    AND (rp.adviser_id = ? OR EXISTS (
                      SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?
                    ))
                    LIMIT 1'
                );
                if ($faculty_stmt) {
                  $faculty_stmt->bind_param('iii', $project_id, $user_id, $user_id);
                    $faculty_stmt->execute();
                    $has_access = $faculty_stmt->get_result()->num_rows > 0;
                    $faculty_stmt->close();
                }
            }
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return_approved_submission') {
    try {
        if ($role !== 'admin' || !$has_access || !$project) {
            http_response_code(403);
            exit('Access denied.');
        }
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session has expired. Refresh and try again.');
        }
        $version_input = $_POST['version_id'] ?? null;
        $revision_version = is_scalar($version_input) ? filter_var($version_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $revision_comments = $_POST['comments'] ?? null;
        if (!$revision_version || !is_string($revision_comments)) {
            throw new RuntimeException('Choose an approved submission and enter revision comments.');
        }
        require_once __DIR__ . '/../../includes/admin-project-actions.php';
        rms_admin_return_approved($conn, $project_id, $revision_version, $revision_comments);
        $_SESSION['module_success'] = 'Submission returned for revision. Other chapters remain unchanged; completion is suspended until the required approval is restored.';
    } catch (Throwable $e) {
        $_SESSION['module_error'] = $e instanceof mysqli_sql_exception ? 'Unable to return this submission. Refresh and try again.' : $e->getMessage();
    }
    header('Location: ' . SITE_URL . 'pages/shared/research-detail.php?id=' . $project_id);
    exit;
}
$reopen_options = [];
if ($role === 'admin' && $has_access && $project) {
    $reopen_stmt = $conn->prepare("SELECT v.version_id, v.submission_type, v.version_number, ch.chapter_number
        FROM research_submission_versions v LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
        WHERE v.project_id = ? AND v.status = 'approved'
          AND ((v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL)
            OR (v.submission_type = 'chapter' AND ch.status = 'approved' AND ch.deleted_at IS NULL))
          AND NOT EXISTS (SELECT 1 FROM research_submission_versions newer WHERE newer.project_id = v.project_id
            AND newer.submission_type = v.submission_type AND newer.chapter_id <=> v.chapter_id AND newer.version_number > v.version_number)
        ORDER BY ch.chapter_number, v.version_id");
    $reopen_stmt->bind_param('i', $project_id);
    $reopen_stmt->execute();
    $reopen_options = $reopen_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reopen_stmt->close();
}

$is_project_student = $role === 'student'
    && $has_access
    && (
        (int) ($project['created_by'] ?? 0) === $user_id
        || (function () use ($conn, $project_id, $user_id) {
            $member_stmt = $conn->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
            if (!$member_stmt) return false;
            $member_stmt->bind_param('ii', $project_id, $user_id);
            $member_stmt->execute();
            $is_member = $member_stmt->get_result()->num_rows > 0;
            $member_stmt->close();
            return $is_member;
        })()
    );
$chapter_sequence_title_approved = in_array((string) ($project['status'] ?? ''), ['title_approved', 'approved', 'ongoing'], true);
$project_area_names = [];
$project_title_classifications = [];
if ($has_access && $project) {
    $area_stmt = $conn->prepare(
        'SELECT ra.area_name
           FROM research_project_areas rpa
           JOIN research_areas ra ON ra.area_id = rpa.area_id
          WHERE rpa.project_id = ?
          ORDER BY rpa.display_order'
    );
    if (!$area_stmt) {
        throw new RuntimeException('Unable to prepare project research-area lookup: ' . $conn->error);
    }
    $area_stmt->bind_param('i', $project_id);
    if (!$area_stmt->execute()) {
        throw new RuntimeException('Unable to load project research areas: ' . $area_stmt->error);
    }
    $area_result = $area_stmt->get_result();
    while ($area_row = $area_result->fetch_assoc()) {
        $project_area_names[] = (string) $area_row['area_name'];
    }
    $area_stmt->close();
    $classification_stmt = $conn->prepare(
        'SELECT tpc.title_option_number, rc.category_name, ra.area_name
           FROM research_project_title_classifications tpc
           JOIN research_categories rc ON rc.category_id = tpc.category_id
           JOIN research_areas ra ON ra.area_id = tpc.area_id
          WHERE tpc.project_id = ?
          ORDER BY tpc.title_option_number'
    );
    if (!$classification_stmt) {
        throw new RuntimeException('Unable to prepare title classification lookup: ' . $conn->error);
    }
    $classification_stmt->bind_param('i', $project_id);
    if (!$classification_stmt->execute()) {
        throw new RuntimeException('Unable to load title classifications: ' . $classification_stmt->error);
    }
    $classification_result = $classification_stmt->get_result();
    while ($classification_row = $classification_result->fetch_assoc()) {
        $project_title_classifications[] = $classification_row;
    }
    $classification_stmt->close();
}
$final_manuscript_status = $has_access ? rms_latest_final_manuscript_status($conn, $project_id) : null;
$approved_final_abstract = $has_access
    ? rms_approved_final_manuscript_abstract($conn, $project_id)
    : null;
$final_manuscript_ready = $is_project_student
    && rms_project_has_all_approved_chapters($conn, $project_id);
$final_manuscript_team_eligible = $is_project_student
    && (($role !== 'student') || rms_student_program_is_eligible($conn, $user_id))
    && rms_project_students_are_eligible($conn, $project_id);
$back_links = [
    'student' => ['pages/researcher/my-research.php', 'Back to My Research'],
    'faculty' => ['pages/faculty/faculty-advising.php', 'Back to Advising'],
    'research_staff' => ['pages/shared/research-submission-review.php?module_page=research_review', 'Back to Review Queue'],
    'admin' => ['pages/admin/admin-research-management.php', 'Back to Research Management'],
];
[$back_path, $back_label] = $back_links[$role] ?? $back_links['student'];
$back_url = SITE_URL . $back_path;

// Fetch chapters
$chapters = [];
$approved_count = 0;
if ($has_access && $project_id > 0) {
    $chapter_query = "
        SELECT c.*, ua.first_name as approver_first, ua.last_name as approver_last
        FROM chapters c
        LEFT JOIN users ua ON c.approved_by = ua.user_id
        WHERE c.project_id = ?
          AND c.chapter_number BETWEEN 1 AND 5
          AND c.deleted_at IS NULL
        ORDER BY c.chapter_number ASC
    ";
    $ch_stmt = $conn->prepare($chapter_query);
    if ($ch_stmt) {
        $ch_stmt->bind_param("i", $project_id);
        $ch_stmt->execute();
        $ch_result = $ch_stmt->get_result();
        while ($row = $ch_result->fetch_assoc()) {
            $chapters[$row['chapter_number']] = $row;
            if ($row['status'] === 'approved') {
                $approved_count++;
            }
        }
        $ch_stmt->close();
    }
}

// Fetch project members
$members = [];
if ($has_access && $project_id > 0) {
    $member_query = "
        SELECT pm.*, u.first_name, u.last_name, NULL AS snapshot_name
        FROM project_members pm
        LEFT JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ?
        ORDER BY pm.role DESC, u.first_name ASC
    ";
    $ms_stmt = $conn->prepare($member_query);
    if ($ms_stmt) {
        $ms_stmt->bind_param("i", $project_id);
        $ms_stmt->execute();
        $ms_result = $ms_stmt->get_result();
        while ($row = $ms_result->fetch_assoc()) {
            $members[] = $row;
        }
        $ms_stmt->close();
    }

    $snapshot_stmt = $conn->prepare(
        'SELECT author_snapshot_id, user_id, author_name, author_role
           FROM research_author_snapshots
          WHERE project_id = ?
          ORDER BY display_order, author_snapshot_id'
    );
    if ($snapshot_stmt) {
        $snapshot_stmt->bind_param('i', $project_id);
        $snapshot_stmt->execute();
        $snapshot_result = $snapshot_stmt->get_result();
        $member_ids = [];
        foreach ($members as $member) {
            if ((int) ($member['user_id'] ?? 0) > 0) {
                $member_ids[(int) $member['user_id']] = true;
            }
        }
        while ($snapshot_result && ($row = $snapshot_result->fetch_assoc())) {
            $snapshot_user_id = (int) ($row['user_id'] ?? 0);
            if ($snapshot_user_id > 0 && isset($member_ids[$snapshot_user_id])) {
                continue;
            }
            if ($snapshot_user_id > 0) {
                $member_ids[$snapshot_user_id] = true;
            }
            $members[] = [
                'user_id' => $snapshot_user_id,
                'first_name' => '',
                'last_name' => '',
                'role' => $row['author_role'],
                'snapshot_name' => $row['author_name'],
            ];
        }
        $snapshot_stmt->close();
    }
}

// Fetch recent uploads (up to 5)
$uploads = [];
$submission_history = [];
$research_trail = [];
$normalize_research_title = static function (string $title): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title), 'UTF-8');
};
if ($has_access && $project_id > 0) {
    $upload_query = "
        SELECT u.*, us.first_name, us.last_name
        FROM uploads u
        LEFT JOIN users us ON u.uploaded_by = us.user_id
        WHERE u.project_id = ?
        ORDER BY u.upload_date DESC
        LIMIT 5
    ";
    $up_stmt = $conn->prepare($upload_query);
    if ($up_stmt) {
        $up_stmt->bind_param("i", $project_id);
        $up_stmt->execute();
        $up_result = $up_stmt->get_result();
        while ($row = $up_result->fetch_assoc()) {
            $uploads[] = $row;
        }
        $up_stmt->close();
    }
}
if ($has_access && $project_id > 0) {
    $history_stmt = $conn->prepare(
        'SELECT v.version_id, v.submitted_by, v.submission_type, v.version_number, v.submitted_at, v.status, v.snapshot_json,
                ch.chapter_number, r.review_id, r.reviewer_id, r.decision, r.comments, r.recommendation, r.selected_title, r.reviewed_at,
                u.upload_id, u.original_name,
                CONCAT(submitter.first_name, " ", submitter.last_name) AS submitter_name,
                CONCAT(reviewer.first_name, " ", reviewer.last_name) AS reviewer_name
           FROM research_submission_versions v
      LEFT JOIN users submitter ON submitter.user_id = v.submitted_by
      LEFT JOIN chapters ch ON ch.chapter_id = v.chapter_id
      LEFT JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id
      LEFT JOIN uploads u ON u.upload_id = vu.upload_id
      LEFT JOIN research_submission_reviews r ON r.version_id = v.version_id
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewer_id
          WHERE v.project_id = ?
          ORDER BY v.submitted_at DESC, v.version_id DESC, r.reviewed_at DESC, r.review_id DESC'
    );
    if (!$history_stmt) {
        throw new RuntimeException('Unable to prepare submission history lookup: ' . $conn->error);
    }
    $history_stmt->bind_param('i', $project_id);
    if (!$history_stmt->execute()) {
        throw new RuntimeException('Unable to load submission history: ' . $history_stmt->error);
    }
    $history_result = $history_stmt->get_result();
    while ($history_row = $history_result->fetch_assoc()) {
        // One version can have multiple documents and reviews; preserve each only once.
        $history_version_id = (int) $history_row['version_id'];
        if (!isset($submission_history[$history_version_id])) {
            $submission_history[$history_version_id] = $history_row;
            $history_snapshot = json_decode((string) $history_row['snapshot_json'], true);
            $submission_history[$history_version_id]['snapshot'] = is_array($history_snapshot) ? $history_snapshot : [];
            $submission_history[$history_version_id]['documents'] = [];
            $submission_history[$history_version_id]['reviews'] = [];
        }
        if (!empty($history_row['upload_id'])) {
            $submission_history[$history_version_id]['documents'][(int) $history_row['upload_id']] = [
                'id' => (int) $history_row['upload_id'],
                'name' => (string) $history_row['original_name'],
            ];
        }
        if (!empty($history_row['review_id'])) {
            $submission_history[$history_version_id]['reviews'][(int) $history_row['review_id']] = $history_row;
        }
    }
    $history_stmt->close();

    if (!empty($project['created_at'])) {
        $research_trail[] = [
            'kind' => 'created', 'status' => 'draft', 'label' => 'Research project created',
            'occurred_at' => (string) $project['created_at'], 'order' => 0, 'id' => 0,
            'actor' => trim((string) $project['first_name'] . ' ' . (string) $project['last_name']) ?: 'Researcher',
        ];
    }
    foreach ($submission_history as $history_version_id => $history_version) {
        $submission_label = $history_version['submission_type'] === 'title' ? 'Research title'
            : ($history_version['submission_type'] === 'final_manuscript' ? 'Complete Research Manuscript'
                : 'Chapter ' . (int) $history_version['chapter_number']);
        $is_imported = !empty($history_version['snapshot']['legacy_import']['imported']);
        $research_trail[] = [
            'kind' => $is_imported ? 'legacy' : 'submitted',
            'status' => $is_imported ? $history_version['status'] : 'submitted',
            'label' => $is_imported ? 'Legacy research record'
                : $submission_label . ((int) $history_version['version_number'] > 1 ? ' resubmitted' : ' submitted'),
            'occurred_at' => (string) $history_version['submitted_at'], 'order' => 1, 'id' => $history_version_id,
            'actor' => trim((string) $history_version['submitter_name']) ?: 'Researcher #' . (int) $history_version['submitted_by'],
            'version' => $history_version,
        ];
        foreach ($history_version['reviews'] as $review_id => $history_review) {
            $research_trail[] = [
                'kind' => 'reviewed', 'status' => $history_review['decision'],
                'label' => $submission_label . ($history_review['decision'] === 'approved' ? ' approved' : ' returned for revision'),
                'occurred_at' => (string) $history_review['reviewed_at'], 'order' => 2, 'id' => $review_id,
                'actor' => trim((string) $history_review['reviewer_name']) ?: 'Authorized reviewer',
                'version' => $history_version, 'review' => $history_review,
            ];
        }
    }
    usort($research_trail, static function (array $left, array $right): int {
        return strcmp($right['occurred_at'], $left['occurred_at'])
            ?: ($right['order'] <=> $left['order'])
            ?: ($right['id'] <=> $left['id']);
    });
}

function rmsTableExists($conn, $table_name) {
    // SHOW TABLES LIKE ? doesn't support bound placeholders in MariaDB/MySQL.
    // We therefore whitelist the table name strictly and inline it; inputs are
    // hard-coded in this file so SQL injection is not possible.
    static $allowed_tables = [
        'project_advisers_history'        => true,
    ];

    if (!isset($allowed_tables[$table_name])) {
        return false;
    }

    $escaped = $conn->real_escape_string($table_name);
    $result  = $conn->query("SHOW TABLES LIKE '" . $escaped . "'");
    if ($result === false) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->close();

    return $exists;
}

$previous_advisers = [];

if ($has_access && $project_id > 0 && rmsTableExists($conn, 'project_advisers_history')) {
    $history_stmt = $conn->prepare("
        SELECT pah.adviser_id, pah.assigned_at, pah.removed_at,
               u.first_name, u.last_name
          FROM project_advisers_history pah
          LEFT JOIN users u ON u.user_id = pah.adviser_id
         WHERE pah.project_id = ?
         ORDER BY pah.removed_at DESC, pah.id DESC
    ");
    if ($history_stmt) {
        $history_stmt->bind_param('i', $project_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        while ($history = $history_result->fetch_assoc()) {
            $previous_advisers[] = $history;
        }
        $history_stmt->close();
    }
}

// Status badge mapping
$status_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'proposal' => ['class' => 'badge badge-info', 'style' => ''],
    'in_progress' => ['class' => 'badge badge-primary', 'style' => ''],
    'for_defense' => ['class' => 'badge badge-warning', 'style' => ''],
    'completed' => ['class' => 'badge badge-success', 'style' => ''],
    'archived' => ['class' => 'badge', 'style' => 'opacity:0.6;']
];

// Chapter status badges
$chapter_badges = [
    'draft' => ['class' => 'badge', 'style' => 'background:#e2e8f0;color:#475569;'],
    'submitted' => ['class' => 'badge badge-info', 'style' => ''],
    'under_review' => ['class' => 'badge badge-primary', 'style' => ''],
    'revision_required' => ['class' => 'badge badge-warning', 'style' => ''],
    'approved' => ['class' => 'badge badge-success', 'style' => '']
];

// Canonical chapter titles
$chapter_titles = [
    1 => 'The Problem and Its Background',
    2 => 'Review of Related Literature',
    3 => 'Research Methodology',
    4 => 'Presentation, Analysis and Interpretation of Data',
    5 => 'Summary, Conclusions, and Suggestions'
];

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

?>
<?php
$detail_title = $has_access && $project ? $project['title'] : 'Research project';
$detail_subtitle = $has_access && $project
    ? ($project['category_name'] ?? 'Uncategorized') . ' • ' . ($project['ay_label'] ?? 'N/A') . ' • ' . ($project['semester'] ?? '')
    : 'Project details';
$researchTheme = match ($role) {
    'admin' => ['accent' => '#F57C00', 'deep' => '#9A3F00', 'tint' => '#FFF4E8', 'highlight' => '#FED7AA', 'rgb' => '245,124,0'],
    'research_staff' => ['accent' => '#0D9488', 'deep' => '#065F58', 'tint' => '#E9F8F5', 'highlight' => '#99F6E4', 'rgb' => '13,148,136'],
    'faculty' => ['accent' => '#1D4ED8', 'deep' => '#172554', 'tint' => '#EAF0FF', 'highlight' => '#BFDBFE', 'rgb' => '29,78,216'],
    default => ['accent' => '#5B1EBC', 'deep' => '#32106E', 'tint' => '#F3EDFF', 'highlight' => '#DDD6FE', 'rgb' => '91,30,188'],
};

if ($role === 'admin') {
    renderAdminShell($user, 'admin-research-management.php', $detail_title, $detail_subtitle);
} elseif ($role === 'research_staff') {
    renderStaffShell($user, 'research-submission-review.php', $detail_title, $detail_subtitle);
} elseif ($role === 'faculty') {
    renderFacultyShell($user, 'my-research', $detail_title, $detail_subtitle);
} else {
    renderStudentShell($user, 'my-research.php', $detail_title, $detail_subtitle);
}
?>
<style>
  .research-project-page{--research-accent:<?= htmlspecialchars($researchTheme['accent'],ENT_QUOTES,'UTF-8') ?>;--research-deep:<?= htmlspecialchars($researchTheme['deep'],ENT_QUOTES,'UTF-8') ?>;--research-tint:<?= htmlspecialchars($researchTheme['tint'],ENT_QUOTES,'UTF-8') ?>;--research-highlight:<?= htmlspecialchars($researchTheme['highlight'],ENT_QUOTES,'UTF-8') ?>;--research-rgb:<?= htmlspecialchars($researchTheme['rgb'],ENT_QUOTES,'UTF-8') ?>;max-width:1320px;margin:0 auto;color:#0f172a}
  .research-breadcrumb{margin-bottom:18px}.research-back{display:inline-flex;align-items:center;gap:8px;color:#475569!important;font-size:14px!important;font-weight:700;text-decoration:none!important;transition:color .18s ease,transform .18s ease}.research-back:hover{color:var(--research-accent)!important;transform:translateX(-2px)}
  .research-project-page .card{overflow:hidden;margin-bottom:20px!important;border:1px solid #e2e8f0;border-radius:17px;background:#fff;box-shadow:0 12px 32px rgba(15,23,42,.05)}
  .research-project-page .research-summary{position:relative;padding:0!important;border-color:rgba(var(--research-rgb),.45)!important;background:radial-gradient(circle at 88% 5%,rgba(255,255,255,.2),transparent 31%),linear-gradient(135deg,var(--research-deep),var(--research-accent))!important;box-shadow:0 20px 44px rgba(var(--research-rgb),.15)}
  .research-summary::after{content:"";position:absolute;right:-70px;bottom:-120px;width:290px;height:290px;border:1px solid rgba(255,255,255,.14);border-radius:50%}
  .research-project-page .research-summary-body{position:relative;z-index:1;display:grid!important;grid-template-columns:minmax(0,1fr) auto;gap:34px!important;align-items:end!important;padding:34px 38px!important}
  .research-kicker{display:block;margin-bottom:12px;color:var(--research-highlight);font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
  body.admin-body .admin-dashboard .admin-page-content .research-project-page .research-summary .research-kicker{color:var(--research-highlight)!important}
  .research-summary h1{max-width:850px;margin:0;color:#fff!important;font-size:clamp(27px,3vw,40px);line-height:1.1;letter-spacing:-.035em}
  .research-status-line{margin-top:18px}.research-summary .badge{border-color:rgba(255,255,255,.2)!important;background:rgba(255,255,255,.13)!important;color:#fff!important}
  .research-summary-meta{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:18px;color:rgba(255,255,255,.9)!important;font-size:14px!important;line-height:1.6!important}.research-summary-meta span{color:inherit!important}.research-summary-meta strong{color:#fff}
  .research-title-options{margin-top:16px;color:#fff}.research-title-options summary{width:fit-content;padding:4px 0;font-size:14px;font-weight:600;cursor:pointer}.research-title-options summary:focus-visible{outline:2px solid #fff;outline-offset:4px}.research-title-options ol{margin:6px 0 0;padding-left:22px;color:#f1f5f9;line-height:1.55}
  .research-progress-summary{min-width:220px;padding:20px 22px;border:1px solid rgba(255,255,255,.25);border-radius:14px;background:rgba(255,255,255,.13);backdrop-filter:blur(8px)}.research-progress-summary strong{display:block;color:#fff;font-size:32px;line-height:1}.research-progress-summary span{display:block;margin-top:8px;color:#fff;font-size:13px;font-weight:700}
  .research-project-page .card-header{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;padding:22px 25px 18px;border-bottom:1px solid #edf1f5;background:#fff}.research-project-page .card-title{color:#0f172a;font-size:19px;font-weight:750;letter-spacing:-.015em}.research-project-page .card-body{padding:22px 25px;color:#334155;font-size:14px;line-height:1.7}
  .research-project-page .research-abstract .card-body>div{color:#334155!important;font-size:15px;line-height:1.8!important;text-align:left!important}
  .research-project-page .research-chapters .card-body>div,.research-project-page .research-team .card-body>div{gap:8px!important}.research-project-page .research-chapters .card-body>div>div,.research-project-page .research-team .card-body>div>div{padding:16px 18px!important;border:1px solid #e2e8f0!important;border-radius:10px!important;background:#f8fafc!important}.research-project-page .research-chapters .card-body>div>div:last-child,.research-project-page .research-team .card-body>div>div:last-child{border-bottom:1px solid #e2e8f0!important}
  .research-project-page .research-chapters .card-body>div>div>div:first-child>div:first-child,.research-project-page .research-team .card-body>div>div>div:first-child>div:first-child{color:#0f172a!important;font-size:15px;font-weight:700!important;line-height:1.5}
  .research-project-page .research-chapters .card-body>div>div>div:first-child>div:nth-child(2){margin-top:7px!important;color:#475569!important;font-size:13px!important;line-height:1.65!important}
  .research-project-page .research-chapters .card-body>div>div>div:first-child>div:nth-child(2) .badge{font-size:12px!important}
  .research-project-page .research-team .card-body>div>div>div:first-child>div:first-child{font-size:15px!important}
  .research-project-page .research-chapters .card-body>div>div>div:last-child{flex:0 0 auto}
  .research-project-page .btn{min-height:38px;padding:9px 14px;border-radius:8px;font-size:13px!important;font-weight:700}.research-project-page .btn-primary,.research-project-page .btn-accent{border-color:var(--research-accent)!important;background:var(--research-accent)!important;color:#fff!important}.research-project-page .btn-secondary:hover{border-color:rgba(var(--research-rgb),.3)!important;background:var(--research-tint)!important;color:var(--research-accent)!important}
  .research-project-page .table-wrap{overflow:auto;border:1px solid #cbd5e1;border-radius:11px}.research-project-page table{width:100%;min-width:760px;border-collapse:collapse}.research-project-page table th{padding:13px 15px;background:#f1f5f9;color:#334155!important;font-size:12px!important;font-weight:750;letter-spacing:.035em;text-transform:uppercase}.research-project-page table td{padding:14px 15px;color:#334155!important;font-size:14px!important;line-height:1.55;border-top:1px solid #e2e8f0}.research-project-page table tr:hover td{background:var(--research-tint)}
  .research-project-page .research-empty{display:grid;justify-items:center;padding:60px 25px!important;text-align:center}.research-empty-mark{display:grid;place-items:center;width:52px;height:52px;margin-bottom:16px;border-radius:15px;background:var(--research-tint);color:var(--research-accent);font-size:20px;font-weight:850}.research-project-page .research-empty h3{color:#0f172a!important;font-size:20px!important}.research-project-page .research-empty p{max-width:480px;color:#475569!important;font-size:15px!important;line-height:1.65!important}
  .research-trail{scroll-margin-top:96px}
  .research-trail .card-title{margin:0;line-height:1.4}
  .research-trail-intro{margin:6px 0 0;color:#64748b;font-size:14px;line-height:1.6}
  .research-trail-empty{margin:0 0 20px;color:#64748b}
  .research-trail-list{margin:0;padding:0;list-style:none}
  .research-trail-entry{scroll-margin-top:96px;margin:0;padding:0 0 28px 20px;border-left:2px solid #cbd5e1;overflow-wrap:anywhere}
  .research-trail-entry:last-child{padding-bottom:0}
  .research-trail-entry[data-status="submitted"]{border-left-color:#2563eb}
  .research-trail-entry[data-status="approved"]{border-left-color:#16a34a}
  .research-trail-entry[data-status="returned"]{border-left-color:#ea580c}
  .research-trail-event-head{display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:4px 20px}
  .research-trail-event-head h3{margin:0;color:#111827;font-size:15px;font-weight:650;line-height:1.6}
  .research-trail-date{color:#64748b;font-size:13px;font-variant-numeric:tabular-nums}
  .research-trail-meta{margin:4px 0 0;color:#64748b;font-size:13px}
  .research-trail-note{max-width:70ch;margin:8px 0 0;color:#64748b;font-size:14px}
  .research-trail-feedback{max-width:75ch;margin:12px 0 0;color:#334155}
  .research-trail-feedback p{margin:4px 0 0;line-height:1.7}
  .research-trail-version{margin-top:12px}
  .research-trail-version summary{padding:4px 0;font-weight:600;cursor:pointer}
  .research-trail-version summary,.research-trail a{color:var(--research-deep)}
  .research-trail-version p{margin:12px 0 4px}
  .research-trail-version ol,.research-trail-version ul{margin:4px 0;padding-left:22px}
  .research-trail a:focus-visible,.research-trail-version summary:focus-visible{outline:2px solid var(--research-accent);outline-offset:3px;border-radius:3px}
  #admin-revision-actions{border-color:rgba(234,88,12,.24)!important;background:linear-gradient(180deg,#fff 0%,#fffaf7 100%)!important;box-shadow:0 12px 30px rgba(234,88,12,.07)!important}
  #admin-revision-actions .card-body{padding:0!important}
  #admin-revision-actions details{padding:0}
  #admin-revision-actions summary{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 25px;color:#9a3412;font-size:16px;font-weight:800;line-height:1.35;cursor:pointer;list-style:none;transition:background .2s ease,color .2s ease}
  #admin-revision-actions summary::-webkit-details-marker{display:none}
  #admin-revision-actions summary::after{content:'+';display:grid;flex:0 0 30px;width:30px;height:30px;place-items:center;border:1px solid rgba(234,88,12,.28);border-radius:9px;background:#fff7ed;color:#c2410c;font-size:22px;font-weight:500;line-height:1;transition:transform .2s ease,background .2s ease}
  #admin-revision-actions summary:hover{background:#fff7ed;color:#7c2d12}
  #admin-revision-actions summary:focus-visible{outline:3px solid rgba(234,88,12,.32);outline-offset:-3px}
  #admin-revision-actions details[open] summary{border-bottom:1px solid rgba(234,88,12,.16);background:#fff7ed}
  #admin-revision-actions details[open] summary::after{content:'−';background:#ffedd5;transform:rotate(180deg)}
  #admin-revision-actions details>p{margin:0;padding:18px 25px 4px;color:#7c2d12;font-size:14px;line-height:1.65}
  #admin-revision-actions form{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.35fr) auto;gap:16px;align-items:end;padding:18px 25px 25px}
  #admin-revision-actions .form-group{margin:0}
  #admin-revision-actions .form-group:first-of-type{grid-column:1/-1}
  #admin-revision-actions .form-group label{display:block;margin-bottom:7px;color:#475569;font-size:12px;font-weight:800;letter-spacing:.03em;text-transform:uppercase}
  #admin-revision-actions .form-control{border-color:#fed7aa;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.03)}
  #admin-revision-actions .form-control:focus{border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.14)}
  #admin-revision-actions textarea.form-control{min-height:104px;resize:vertical}
  #admin-revision-actions .btn-primary{grid-column:1/-1;justify-self:end;align-self:end;min-height:44px;padding-inline:18px;background:#c2410c!important;border-color:#c2410c!important;box-shadow:0 8px 16px rgba(194,65,12,.18)}
  #admin-revision-actions .btn-primary:hover{background:#9a3412!important;border-color:#9a3412!important;box-shadow:0 10px 20px rgba(154,52,18,.22)}
  @media(max-width:900px){#admin-revision-actions form{grid-template-columns:1fr 1fr}#admin-revision-actions form .btn-primary{grid-column:1/-1;justify-self:start}}
  @media(max-width:560px){#admin-revision-actions summary{padding:18px 20px}#admin-revision-actions details>p,#admin-revision-actions form{padding-inline:20px}#admin-revision-actions form{grid-template-columns:1fr}}
  @media(max-width:760px){.research-project-page .research-summary-body{grid-template-columns:1fr;padding:28px 23px!important}.research-progress-summary{min-width:0;width:100%;box-sizing:border-box}.research-project-page .card-header{align-items:flex-start;flex-direction:column;padding:20px}.research-project-page .card-body{padding:19px 20px}.research-project-page .research-chapters .card-body>div>div,.research-project-page .research-team .card-body>div>div{align-items:flex-start!important;flex-direction:column!important;gap:10px!important}.research-project-page table{min-width:700px}.research-trail-entry{padding-left:16px}}
</style>
<div class="research-project-page">
      <?php if ($flash_success): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars((string) $flash_success, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars((string) $flash_error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <!-- BREADCRUMB -->
      <div class="research-breadcrumb">
        <a class="research-back" href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>">← <?php echo htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8'); ?></a>
      </div>

      <?php if (!$has_access || !$project): ?>
        <!-- ACCESS DENIED / NOT FOUND -->
        <div class="card research-empty">
          <div class="research-empty-mark" aria-hidden="true">!</div>
          <h3 style="margin: 0 0 8px 0; color: var(--text-dark);">Project not found or you don't have access</h3>
          <p style="margin: 0 0 24px 0; color: var(--text-light); font-size: 14px;">
            The research project you're looking for doesn't exist or you don't have permission to view it.
          </p>
          <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary"><?php echo htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8'); ?></a>
        </div>

      <?php else: ?>
        <!-- STATUS + ACTIONS CARD -->
        <div class="card research-summary">
          <div class="card-body research-summary-body">
            <div style="flex: 1;">
              <span class="research-kicker">Research project · #<?php echo (int) $project_id; ?></span>
              <h1><?php echo htmlspecialchars((string) $project['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
              <?php
                $project_title_options = array_values(array_filter([
                    (string) ($project['title_option_1'] ?? ''),
                    (string) ($project['title_option_2'] ?? ''),
                    (string) ($project['title_option_3'] ?? ''),
                ], static function (string $option) use ($project, $normalize_research_title): bool {
                    return trim($option) !== ''
                        && $normalize_research_title($option) !== $normalize_research_title((string) $project['title']);
                }));
              ?>
              <?php if ($project_title_options): ?>
                <details class="research-title-options">
                  <summary>Other proposed titles</summary>
                  <ol>
                    <?php foreach ($project_title_options as $option_title): ?>
                      <li><?php echo htmlspecialchars($option_title, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                  </ol>
                </details>
              <?php endif; ?>
              <?php if ($project_title_classifications): ?>
                <details class="research-title-options">
                  <summary>Categories and research areas for each title</summary>
                  <ol>
                    <?php foreach ($project_title_classifications as $classification): ?>
                      <li><strong><?php echo (int) $classification['title_option_number']; ?>.</strong> <?php echo htmlspecialchars((string) $classification['category_name'] . ' — ' . (string) $classification['area_name'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                  </ol>
                </details>
              <?php endif; ?>
              <div class="research-status-line">
                <?php $status = isset($project['status']) ? $project['status'] : 'draft'; $badge = isset($status_badges[$status]) ? $status_badges[$status] : $status_badges['draft']; ?>
                <span class="<?php echo htmlspecialchars($badge['class']); ?>" <?php echo !empty($badge['style']) ? 'style="' . htmlspecialchars($badge['style']) . '"' : ''; ?>>
                  <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                </span>
              </div>
              <div class="research-summary-meta">
                <span>Created <strong><?php echo date('M d, Y', strtotime($project['created_at'])); ?></strong></span>
                <span>Updated <strong><?php echo date('M d, Y', strtotime($project['updated_at'])); ?></strong></span>
                <span>Lead <strong><?php echo htmlspecialchars($project['first_name'] . ' ' . $project['last_name']); ?></strong></span>
                <?php if ($project_title_classifications): ?>
                  <span>Title classifications <strong><?php foreach ($project_title_classifications as $classification_index => $classification): ?><?php echo $classification_index > 0 ? ' · ' : ''; ?>Option <?php echo (int) $classification['title_option_number']; ?>: <?php echo htmlspecialchars((string) $classification['category_name'] . ' — ' . (string) $classification['area_name'], ENT_QUOTES, 'UTF-8'); ?><?php endforeach; ?></strong></span>
                <?php elseif ($project_area_names): ?>
                  <span>Research areas <strong><?php echo htmlspecialchars(implode(', ', $project_area_names), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php elseif (!empty($project['research_area'])): ?>
                  <span>Research area <strong><?php echo htmlspecialchars((string) $project['research_area'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($previous_advisers)): ?>
                  <span>
                    Previous adviser(s):
                    <?php foreach ($previous_advisers as $index => $previous_adviser):
                      $previous_name = trim(($previous_adviser['first_name'] ?? '') . ' ' . ($previous_adviser['last_name'] ?? ''));
                      if ($previous_name === '') {
                          $previous_name = 'Faculty #' . (int) $previous_adviser['adviser_id'];
                      }
                      $assigned_date = date('M d, Y', strtotime((string) $previous_adviser['assigned_at']));
                      $removed_date = date('M d, Y', strtotime((string) $previous_adviser['removed_at']));
                    ?><?php echo $index > 0 ? '; ' : ''; ?><?php echo htmlspecialchars($previous_name . ' (' . $assigned_date . ' - ' . $removed_date . ')', ENT_QUOTES, 'UTF-8'); ?><?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <div class="research-progress-summary"><strong><?php echo (int) $approved_count; ?>/5</strong><span>Chapters approved</span></div>
          </div>
        </div>

        <?php if ($approved_final_abstract !== null): ?>
          <!-- APPROVED FINAL MANUSCRIPT ABSTRACT -->
          <div class="card research-abstract">
            <div class="card-header">
              <div class="card-title">Approved Final Manuscript Abstract</div>
            </div>
            <div class="card-body">
              <div style="white-space: pre-wrap; color: var(--text-dark); line-height: 1.6; text-align: justify; text-justify: inter-word;">
                <?php echo htmlspecialchars($approved_final_abstract, ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- CHAPTERS CARD -->
        <div class="card research-chapters">
          <div class="card-header">
            <div>
              <div class="card-title">Chapters</div>
            </div>
            <div style="font-size: 13px; color: var(--text-light);">
              <?php echo $approved_count; ?>/5 approved
              <!-- @rms-ui: chapter progress bar (200px wide, colored fill) -->
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($chapters)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No chapters submitted yet.<?php echo $is_project_student && $chapter_sequence_title_approved ? ' Start with Chapter 1.' : ''; ?>
              </div>
              <?php if ($is_project_student && $chapter_sequence_title_approved): ?>
                <div style="display: flex; justify-content: center; gap: 8px;">
                  <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=1" class="btn btn-sm btn-primary">Upload Chapter 1</a>
                </div>
              <?php elseif ($is_project_student): ?>
                <div style="color: var(--text-light); text-align:center;padding-bottom:16px;">Chapter 1 becomes available after the research title is approved.</div>
              <?php endif; ?>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <?php $chapter_sequence_available = $chapter_sequence_title_approved && ($i === 1 || (($chapters[$i - 1]['status'] ?? '') === 'approved')); ?>
                  <div id="chapter-<?php echo $i; ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;">
                    <div style="flex: 1;">
                      <div style="font-weight: 500; color: var(--text-dark);">Ch. <?php echo $i; ?> — <?php echo htmlspecialchars($chapter_titles[$i]); ?></div>
                      <div style="font-size: 13px; color: var(--text-light); margin-top: 4px;">
                        <?php if (isset($chapters[$i])): ?>
                          <?php $ch_status = isset($chapters[$i]['status']) ? $chapters[$i]['status'] : 'draft'; $ch_badge = isset($chapter_badges[$ch_status]) ? $chapter_badges[$ch_status] : $chapter_badges['draft']; ?>
                          <span class="<?php echo htmlspecialchars($ch_badge['class']); ?>" <?php echo !empty($ch_badge['style']) ? 'style="' . htmlspecialchars($ch_badge['style']) . '"' : ''; ?>>
                            <?php echo ucwords(str_replace('_', ' ', $ch_status)); ?>
                          </span>
                          <?php if (!empty($chapters[$i]['submitted_at'])): ?>
                            • Submitted: <?php echo date('M d, Y', strtotime($chapters[$i]['submitted_at'])); ?>
                          <?php endif; ?>
                          <?php if (!empty($chapters[$i]['approved_at'])): ?>
                            • Approved: <?php echo date('M d, Y', strtotime($chapters[$i]['approved_at'])); ?>
                            <?php if (!empty($chapters[$i]['approver_first'])): ?>
                              by <?php echo htmlspecialchars($chapters[$i]['approver_first'] . ' ' . $chapters[$i]['approver_last']); ?>
                            <?php endif; ?>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge" style="background:#e2e8f0;color:#475569;">Not Started</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if ($is_project_student): ?>
                      <div>
                        <?php if (!isset($chapters[$i]) && $chapter_sequence_available): ?>
                          <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Start Chapter</a>
                        <?php elseif (isset($chapters[$i]) && $chapters[$i]['status'] === 'draft' && $chapter_sequence_available): ?>
                          <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Edit Draft</a>
                        <?php elseif (isset($chapters[$i]) && $chapters[$i]['status'] === 'revision_required' && $chapter_sequence_available): ?>
                          <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-accent">Edit Revision</a>
                        <?php elseif (isset($chapters[$i]) && in_array($chapters[$i]['status'], ['submitted', 'under_review'], true)): ?>
                          <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">View Submission</a>
                        <?php elseif (isset($chapters[$i]) && $chapters[$i]['status'] === 'approved'): ?>
                          <a href="<?php echo SITE_URL; ?>pages/researcher/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-accent">View Approved</a>
                        <?php else: ?>
                          <span style="color:var(--text-light);font-size:12px">Locked until the previous stage is approved</span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <section class="card research-final-manuscript" aria-labelledby="final-manuscript-heading">
          <div class="card-header"><div class="card-title" id="final-manuscript-heading">Complete research manuscript</div></div>
          <div class="card-body">
            <?php if (!$final_manuscript_ready && $is_project_student): ?>
              <p>Submit the complete manuscript after all five chapters are approved.</p>
            <?php elseif ($final_manuscript_status === 'approved'): ?>
              <p><span class="badge badge-success">Approved</span> The final manuscript is approved and the research is complete.</p>
            <?php elseif ($final_manuscript_status === 'submitted'): ?>
              <p><span class="badge badge-info">Under review</span> The submitted final manuscript is locked while an authorized reviewer considers it.</p>
            <?php elseif ($final_manuscript_status === 'returned'): ?>
              <p><span class="badge badge-warning">Returned for revision</span> Review the comments in Submission and Review History, then upload a revised final manuscript.</p>
            <?php elseif ($final_manuscript_ready && $is_project_student): ?>
              <p>All five chapters are approved. Submit one complete manuscript for the final authorized review.</p>
            <?php else: ?>
              <p>No final manuscript has been submitted yet.</p>
            <?php endif; ?>
            <?php if ($is_project_student && $final_manuscript_ready && $final_manuscript_team_eligible && !in_array($project['status'], ['completed', 'archived'], true) && !in_array($final_manuscript_status, ['submitted', 'approved'], true)): ?>
              <a class="btn btn-primary" href="<?php echo SITE_URL; ?>pages/researcher/submit-final-research.php?project_id=<?php echo (int) $project_id; ?>">
                <?php echo $final_manuscript_status === 'returned' ? 'Revise Final Manuscript' : 'Submit Final Manuscript'; ?>
              </a>
            <?php elseif ($is_project_student && $final_manuscript_ready && !$final_manuscript_team_eligible): ?>
              <p class="alert alert-error" role="alert">Student researchers on this project must be active BSIT or BSCS students before submitting the final manuscript.</p>
            <?php endif; ?>
          </div>
        </section>

        <!-- RESEARCH TEAM CARD -->
        <div class="card research-team">
          <div class="card-header">
            <div class="card-title">Research Team</div>
          </div>
          <div class="card-body">
            <?php if (empty($members)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No additional team members yet.
              </div>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($members as $member): ?>
                  <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;">
                    <div style="flex: 1;">
                      <div style="font-weight: 500; color: var(--text-dark);">
                        <?php echo htmlspecialchars((string) ($member['snapshot_name'] ?? trim($member['first_name'] . ' ' . $member['last_name']))); ?>
                      </div>
                    </div>
                    <div>
                      <span class="badge <?php echo $member['role'] === 'lead' ? 'badge-primary' : 'badge-info'; ?>">
                        <?php echo ucfirst(htmlspecialchars($member['role'])); ?>
                      </span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($role === 'admin' && $reopen_options): ?>
          <section id="admin-revision-actions" class="card" aria-label="Admin revision actions">
            <div class="card-body">
              <details<?php echo ($_GET['revision'] ?? '') === '1' ? ' open' : ''; ?>>
                <summary>Return approved work for revision</summary>
                <p>Choose a chapter or final manuscript and explain what needs correcting. Other chapters stay approved. Completion is suspended until the selected work is approved again.</p>
                <form method="post" action="<?php echo SITE_URL; ?>pages/shared/research-detail.php?id=<?php echo $project_id; ?>" onsubmit="return confirm('Return this approved submission for revision and suspend project completion?');">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="return_approved_submission">
                  <div class="form-group">
                    <label for="reopen-version">Approved submission</label>
                    <select class="form-control" id="reopen-version" name="version_id" required>
                      <?php foreach ($reopen_options as $option): ?>
                        <option value="<?php echo (int) $option['version_id']; ?>"><?php echo $option['submission_type'] === 'chapter' ? 'Chapter ' . (int) $option['chapter_number'] : 'Final manuscript'; ?> — version <?php echo (int) $option['version_number']; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="reopen-comments">Required corrections</label>
                    <textarea class="form-control" id="reopen-comments" name="comments" rows="4" minlength="10" maxlength="5000" required></textarea>
                  </div>
                  <button class="btn btn-primary" type="submit">Return for revision</button>
                </form>
              </details>
            </div>
          </section>
        <?php endif; ?>
        <section id="research-trail" class="card research-trail" aria-labelledby="research-trail-heading">
          <div class="card-header">
            <div>
              <h2 id="research-trail-heading" class="card-title">Research trail</h2>
              <p class="research-trail-intro">Project creation, submitted versions and review decisions, newest first.</p>
            </div>
          </div>
          <div class="card-body">
            <?php if (!$submission_history): ?>
              <p class="research-trail-empty">No submitted versions or review decisions have been recorded yet.</p>
            <?php endif; ?>
            <?php if ($research_trail): ?>
              <ol class="research-trail-list" aria-label="Research history, newest first">
                <?php foreach ($research_trail as $event): ?>
                  <?php
                    $event_version = $event['version'] ?? [];
                    $event_review = $event['review'] ?? [];
                    $event_timestamp = strtotime($event['occurred_at']);
                    $event_is_submission = in_array($event['kind'], ['submitted', 'legacy'], true);
                    $event_anchor = $event_is_submission ? 'trail-version-' . (int) $event_version['version_id']
                        : 'trail-' . $event['kind'] . '-' . (int) $event['id'];
                    $event_titles = [];
                    if ($event_is_submission && $event_version['submission_type'] === 'title') {
                        $event_snapshot = $event_version['snapshot'];
                        $event_titles = is_array($event_snapshot['title_options'] ?? null)
                            ? array_values(array_filter($event_snapshot['title_options'], static function ($title): bool {
                                return is_string($title) && trim($title) !== '';
                            }))
                            : (is_string($event_snapshot['title'] ?? null) ? [$event_snapshot['title']] : []);
                    }
                  ?>
                  <li id="<?php echo htmlspecialchars($event_anchor, ENT_QUOTES, 'UTF-8'); ?>" class="research-trail-entry" data-status="<?php echo htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="research-trail-event-head">
                      <h3><?php echo htmlspecialchars($event['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                      <div class="research-trail-date">
                        <?php if ($event['kind'] === 'legacy'): ?>Approximate project-creation date: <?php endif; ?>
                        <?php if ($event_timestamp !== false): ?>
                          <time datetime="<?php echo htmlspecialchars(date(DATE_ATOM, $event_timestamp), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(date($event['kind'] === 'legacy' ? 'M j, Y' : 'M j, Y g:i A', $event_timestamp), ENT_QUOTES, 'UTF-8'); ?></time>
                        <?php else: ?>Date unavailable<?php endif; ?>
                      </div>
                    </div>
                    <p class="research-trail-meta">
                      <?php if ($event['kind'] !== 'legacy'): ?><?php echo htmlspecialchars($event['actor'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                      <?php if ($event_version): ?>
                        <?php if ($event['kind'] !== 'legacy'): ?> &middot; <?php endif; ?>
                        <?php if ($event['kind'] === 'reviewed'): ?>
                          <a href="#trail-version-<?php echo (int) $event_version['version_id']; ?>">Version <?php echo (int) $event_version['version_number']; ?></a>
                        <?php else: ?>Version <?php echo (int) $event_version['version_number']; ?><?php endif; ?>
                      <?php endif; ?>
                    </p>
                    <?php if ($event['kind'] === 'legacy'): ?>
                      <p class="research-trail-note">Imported legacy record. Original submission and reviewer details are unavailable; this date is approximate.</p>
                    <?php endif; ?>
                    <?php if ($event_review): ?>
                      <?php if (trim((string) ($event_review['selected_title'] ?? '')) !== ''
                          && $normalize_research_title((string) $event_review['selected_title']) !== $normalize_research_title((string) $project['title'])): ?>
                        <p class="research-trail-feedback"><strong>Approved title:</strong> <?php echo htmlspecialchars((string) $event_review['selected_title'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <?php endif; ?>
                      <?php if (trim((string) ($event_review['comments'] ?? '')) !== ''): ?>
                        <div class="research-trail-feedback"><strong><?php echo $event_version['submission_type'] === 'title' ? 'Comments / Suggestion' : 'Review comments'; ?></strong><p><?php echo nl2br(htmlspecialchars((string) $event_review['comments'], ENT_QUOTES, 'UTF-8')); ?></p></div>
                      <?php endif; ?>
                      <?php if (trim((string) ($event_review['recommendation'] ?? '')) !== ''): ?>
                        <div class="research-trail-feedback"><strong>Suggestion</strong><p><?php echo nl2br(htmlspecialchars((string) $event_review['recommendation'], ENT_QUOTES, 'UTF-8')); ?></p></div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($event_is_submission && ($event_titles || $event_version['documents'])): ?>
                      <details class="research-trail-version">
                        <summary>View submitted version</summary>
                        <?php if ($event_titles): ?>
                          <p><strong>Submitted <?php echo count($event_titles) > 1 ? 'title options' : 'title'; ?></strong></p>
                          <ol>
                            <?php foreach ($event_titles as $event_title): ?>
                              <li><?php echo htmlspecialchars($event_title, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                          </ol>
                        <?php endif; ?>
                        <?php if ($event_version['documents']): ?>
                          <p><strong>Version documents</strong></p>
                          <ul>
                            <?php foreach ($event_version['documents'] as $event_document): ?>
                              <li><a href="<?php echo SITE_URL; ?>pages/shared/download-research-document.php?type=research&amp;id=<?php echo (int) $event_document['id']; ?>&amp;mode=viewer"><?php echo htmlspecialchars($event_document['name'], ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php endif; ?>
                      </details>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        </section>

        <!-- RECENT UPLOADS CARD -->
        <div class="card research-documents">
          <div class="card-header">
            <div>
              <div class="card-title">Recent Documents</div>
            </div>
            <?php if ($role === 'student'): ?>
              <a href="<?php echo SITE_URL; ?>pages/student/my-documents.php?project_id=<?php echo (int) $project_id; ?>" style="color: var(--primary); text-decoration: none; font-size: 13px;">View All →</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (empty($uploads)): ?>
              <div style="color: var(--text-light); text-align: center; padding: 24px;">
                No documents uploaded yet.
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>File Name</th>
                      <th>Type</th>
                      <th>Size</th>
                      <th>Uploaded</th>
                      <th>By</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($uploads as $upload): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($upload['original_name']); ?></td>
                        <td>
                          <span class="badge badge-info"><?php echo ucfirst(htmlspecialchars($upload['type'])); ?></span>
                        </td>
                        <td><?php echo formatFileSize($upload['file_size']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($upload['upload_date'])); ?></td>
                        <td><?php echo htmlspecialchars($upload['first_name'] . ' ' . $upload['last_name']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ACTIVITY CARD (PLACEHOLDER) -->
        <div class="card research-activity">
          <div class="card-header">
            <div class="card-title">Activity</div>
          </div>
          <div class="card-body" style="color: var(--text-light); text-align: center; padding: 24px;">
            <!-- @rms-db: add project_id to activity_log to enable project-level activity tracking -->
            Recent activity for this project will appear here.
          </div>
        </div>

<?php endif; ?>

</div>

<?php
if ($role === 'admin') {
    renderAdminShellClose();
} elseif ($role === 'research_staff') {
    renderStaffShellClose();
} elseif ($role === 'faculty') {
    renderFacultyShellClose();
} else {
    renderStudentShellClose();
}
?>
