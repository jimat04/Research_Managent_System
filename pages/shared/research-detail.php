<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/staff-shell.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireLogin();

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
$user_id = (int) $user['user_id'];
$role = (string) ($user['role'] ?? 'student');

// Get project ID from URL
$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

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
        WHERE rp.project_id = ?
    ";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $project = $result->fetch_assoc();

            // Admin and Research Staff process projects system-wide.
            if ($role === 'admin' || $role === 'research_staff') {
                $has_access = true;
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
                // Faculty may view projects assigned to them as adviser.
                $faculty_stmt = $conn->prepare(
                    'SELECT 1 FROM project_advisers WHERE project_id = ? AND adviser_id = ? LIMIT 1'
                );
                if ($faculty_stmt) {
                    $faculty_stmt->bind_param('ii', $project_id, $user_id);
                    $faculty_stmt->execute();
                    $has_access = $faculty_stmt->get_result()->num_rows > 0;
                    $faculty_stmt->close();
                }

                // CREC/EREC reviewer assignments live in migration 006's
                // optional project_reviews table.
                if (!$has_access) {
                    $reviews_table = $conn->query("SHOW TABLES LIKE 'project_reviews'");
                    $has_reviews_table = $reviews_table && $reviews_table->num_rows > 0;
                    if ($reviews_table) {
                        $reviews_table->close();
                    }
                    if ($has_reviews_table) {
                        $review_stmt = $conn->prepare(
                            'SELECT 1 FROM project_reviews WHERE project_id = ? AND reviewer_id = ? LIMIT 1'
                        );
                        if ($review_stmt) {
                            $review_stmt->bind_param('ii', $project_id, $user_id);
                            $review_stmt->execute();
                            $has_access = $review_stmt->get_result()->num_rows > 0;
                            $review_stmt->close();
                        }
                    }
                }
            }
        }
        $stmt->close();
    }
}

$is_project_student = ($role === 'student' && $has_access === true);
$back_links = [
    'student' => ['pages/student/my-research.php', 'Back to My Research'],
    'faculty' => ['pages/faculty/faculty-submissions.php', 'Back to My Submissions'],
    'research_staff' => ['pages/staff/staff-submissions.php', 'Back to Submissions'],
    'admin' => ['pages/admin/admin-research.php', 'Back to Research Management'],
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
        SELECT pm.*, u.first_name, u.last_name
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
}

// Fetch recent uploads (up to 5)
$uploads = [];
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

function rmsTableExists($conn, $table_name) {
    // SHOW TABLES LIKE ? doesn't support bound placeholders in MariaDB/MySQL.
    // We therefore whitelist the table name strictly and inline it; inputs are
    // hard-coded in this file so SQL injection is not possible.
    static $allowed_tables = [
        'research_documents'             => true,
        'research_reports'               => true,
        'research_publication_tracking'  => true,
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

$manual_documents = [];
$manual_reports = [];
$publication_tracking = null;
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

if ($has_access && $project_id > 0 && rmsTableExists($conn, 'research_documents')) {
    $doc_query = "
        SELECT rd.*, u.original_name
        FROM research_documents rd
        LEFT JOIN uploads u ON rd.upload_id = u.upload_id
        WHERE rd.project_id = ?
        ORDER BY FIELD(rd.document_type, 'proposal', 'revision_checklist', 'defense_material', 'mou', 'nda', 'progress_report', 'terminal_report', 'final_bound_report', 'publication_record', 'other'), rd.created_at DESC
    ";
    $doc_stmt = $conn->prepare($doc_query);
    if ($doc_stmt) {
        $doc_stmt->bind_param("i", $project_id);
        $doc_stmt->execute();
        $doc_result = $doc_stmt->get_result();
        while ($row = $doc_result->fetch_assoc()) {
            $manual_documents[$row['document_type']] = $row;
        }
        $doc_stmt->close();
    }
}

if ($has_access && $project_id > 0 && rmsTableExists($conn, 'research_reports')) {
    $report_query = "
        SELECT *
        FROM research_reports
        WHERE project_id = ?
        ORDER BY FIELD(report_type, 'midway_progress', 'terminal'), created_at DESC
    ";
    $report_stmt = $conn->prepare($report_query);
    if ($report_stmt) {
        $report_stmt->bind_param("i", $project_id);
        $report_stmt->execute();
        $report_result = $report_stmt->get_result();
        while ($row = $report_result->fetch_assoc()) {
            $manual_reports[$row['report_type']] = $row;
        }
        $report_stmt->close();
    }
}

if ($has_access && $project_id > 0 && rmsTableExists($conn, 'research_publication_tracking')) {
    $publication_query = "
        SELECT *
        FROM research_publication_tracking
        WHERE project_id = ?
        LIMIT 1
    ";
    $publication_stmt = $conn->prepare($publication_query);
    if ($publication_stmt) {
        $publication_stmt->bind_param("i", $project_id);
        $publication_stmt->execute();
        $publication_tracking = $publication_stmt->get_result()->fetch_assoc();
        $publication_stmt->close();
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
    5 => 'Summary, Conclusions, and Recommendations'
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

function formatManualLabel($value) {
    return ucwords(str_replace('_', ' ', $value));
}

function manualStatusBadge($status) {
    $badge_map = [
        'approved' => 'badge badge-success',
        'presented' => 'badge badge-success',
        'published' => 'badge badge-success',
        'archived' => 'badge badge-success',
        'submitted' => 'badge badge-info',
        'scheduled' => 'badge badge-info',
        'under_review' => 'badge badge-primary',
        'ready' => 'badge badge-primary',
        'revision_required' => 'badge badge-warning',
        'pending' => 'badge badge-warning',
        'draft' => 'badge',
        'not_scheduled' => 'badge',
        'not_submitted' => 'badge',
        'not_archived' => 'badge',
        'rejected' => 'badge badge-danger',
        'cancelled' => 'badge badge-danger',
        'waived' => 'badge'
    ];

    return $badge_map[$status] ?? 'badge';
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
    $referrer_path = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $admin_active = basename((string) $referrer_path) === 'admin-archive.php'
        ? 'admin-archive.php'
        : 'admin-research.php';
    renderAdminShell($user, $admin_active, $detail_title, $detail_subtitle);
} elseif ($role === 'research_staff') {
    renderStaffShell($user, 'staff-submissions.php', $detail_title, $detail_subtitle);
} elseif ($role === 'faculty') {
    renderFacultyShell($user, 'faculty-submissions.php', $detail_title, $detail_subtitle);
} else {
    renderStudentShell($user, 'my-research.php', $detail_title, $detail_subtitle);
}
?>
<style>
  .research-project-page{--research-accent:<?= htmlspecialchars($researchTheme['accent'],ENT_QUOTES,'UTF-8') ?>;--research-deep:<?= htmlspecialchars($researchTheme['deep'],ENT_QUOTES,'UTF-8') ?>;--research-tint:<?= htmlspecialchars($researchTheme['tint'],ENT_QUOTES,'UTF-8') ?>;--research-highlight:<?= htmlspecialchars($researchTheme['highlight'],ENT_QUOTES,'UTF-8') ?>;--research-rgb:<?= htmlspecialchars($researchTheme['rgb'],ENT_QUOTES,'UTF-8') ?>;max-width:1320px;margin:0 auto;color:#0f172a}
  .research-breadcrumb{margin-bottom:14px}.research-back{display:inline-flex;align-items:center;gap:8px;color:#64748b!important;font-size:11px!important;font-weight:750;text-decoration:none!important;transition:color .18s ease,transform .18s ease}.research-back:hover{color:var(--research-accent)!important;transform:translateX(-2px)}
  .research-project-page .card{overflow:hidden;margin-bottom:20px!important;border:1px solid #e2e8f0;border-radius:17px;background:#fff;box-shadow:0 12px 32px rgba(15,23,42,.05)}
  .research-project-page .research-summary{position:relative;padding:0!important;border-color:rgba(var(--research-rgb),.35);background:radial-gradient(circle at 88% 5%,rgba(255,255,255,.2),transparent 31%),linear-gradient(135deg,var(--research-deep),var(--research-accent));box-shadow:0 20px 44px rgba(var(--research-rgb),.15)}
  .research-summary::after{content:"";position:absolute;right:-70px;bottom:-120px;width:290px;height:290px;border:1px solid rgba(255,255,255,.14);border-radius:50%}
  .research-project-page .research-summary-body{position:relative;z-index:1;display:grid!important;grid-template-columns:minmax(0,1fr) auto;gap:34px!important;align-items:end!important;padding:34px 38px!important}
  .research-kicker{display:block;margin-bottom:10px;color:var(--research-highlight);font-size:10px;font-weight:850;letter-spacing:.13em;text-transform:uppercase}
  .research-summary h1{max-width:850px;margin:0;color:#fff;font-size:clamp(27px,3vw,40px);line-height:1.1;letter-spacing:-.035em}
  .research-status-line{margin-top:18px}.research-summary .badge{border-color:rgba(255,255,255,.2)!important;background:rgba(255,255,255,.13)!important;color:#fff!important}
  .research-summary-meta{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:16px;color:rgba(255,255,255,.76)!important;font-size:11px!important;line-height:1.55!important}.research-summary-meta span{color:inherit!important}.research-summary-meta strong{color:#fff}
  .research-progress-summary{min-width:220px;padding:18px 20px;border:1px solid rgba(255,255,255,.2);border-radius:14px;background:rgba(255,255,255,.1);backdrop-filter:blur(8px)}.research-progress-summary strong{display:block;color:#fff;font-size:29px;line-height:1}.research-progress-summary span{display:block;margin-top:7px;color:var(--research-highlight);font-size:11px;font-weight:700}
  .research-project-page .card-header{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;padding:22px 25px 18px;border-bottom:1px solid #edf1f5;background:#fff}.research-project-page .card-title{color:#0f172a;font-size:17px;font-weight:760;letter-spacing:-.015em}.research-project-page .card-body{padding:22px 25px;color:#475569;font-size:12px;line-height:1.65}
  .research-project-page .research-abstract .card-body>div{color:#475569!important;font-size:13px;line-height:1.78!important;text-align:left!important}
  .research-project-page .research-chapters .card-body>div,.research-project-page .research-team .card-body>div{gap:0!important}.research-project-page .research-chapters .card-body>div>div,.research-project-page .research-team .card-body>div>div{padding:14px 0!important;border:0!important;border-bottom:1px solid #edf1f5!important;border-radius:0!important;background:#fff!important}.research-project-page .research-chapters .card-body>div>div:last-child,.research-project-page .research-team .card-body>div>div:last-child{border-bottom:0!important}
  .research-project-page .research-chapters .card-body>div>div>div:first-child>div:first-child,.research-project-page .research-team .card-body>div>div>div:first-child>div:first-child{color:#0f172a!important;font-size:12px;font-weight:750!important}
  .research-project-page .btn{border-radius:8px;font-size:10px;font-weight:800}.research-project-page .btn-primary,.research-project-page .btn-accent{border-color:var(--research-accent)!important;background:var(--research-accent)!important;color:#fff!important}.research-project-page .btn-secondary:hover{border-color:rgba(var(--research-rgb),.3)!important;background:var(--research-tint)!important;color:var(--research-accent)!important}
  .research-project-page .table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:11px}.research-project-page table{width:100%;border-collapse:collapse}.research-project-page table th{padding:11px 13px;background:#f1f5f9;color:#475569;font-size:9px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.research-project-page table td{padding:13px;color:#475569;font-size:10px;border-top:1px solid #e2e8f0}.research-project-page table tr:hover td{background:var(--research-tint)}
  .research-project-page .research-empty{display:grid;justify-items:center;padding:60px 25px!important;text-align:center}.research-empty-mark{display:grid;place-items:center;width:52px;height:52px;margin-bottom:16px;border-radius:15px;background:var(--research-tint);color:var(--research-accent);font-size:20px;font-weight:850}.research-project-page .research-empty h3{color:#0f172a!important;font-size:18px!important}.research-project-page .research-empty p{max-width:480px;color:#64748b!important;font-size:12px!important;line-height:1.6!important}
  @media(max-width:760px){.research-project-page .research-summary-body{grid-template-columns:1fr;padding:28px 23px!important}.research-progress-summary{min-width:0;width:100%;box-sizing:border-box}.research-project-page .card-header{align-items:flex-start;flex-direction:column;padding:20px}.research-project-page .card-body{padding:19px 20px}.research-project-page .research-chapters .card-body>div>div,.research-project-page .research-team .card-body>div>div{align-items:flex-start!important;flex-direction:column!important;gap:10px!important}}
</style>
<div class="research-project-page">
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

        <!-- ABSTRACT CARD -->
        <div class="card research-abstract">
          <div class="card-header">
            <div class="card-title">Abstract</div>
          </div>
          <div class="card-body">
            <?php if (!empty($project['abstract'])): ?>
              <div style="white-space: pre-wrap; color: var(--text-dark); line-height: 1.6; text-align: justify; text-justify: inter-word;">
                <?php echo htmlspecialchars($project['abstract'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php else: ?>
              <div style="color: var(--text-light); font-style: italic;">No abstract provided.</div>
            <?php endif; ?>
          </div>
        </div>

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
                No chapters submitted yet.<?php echo $is_project_student ? ' Start with Chapter 1.' : ''; ?>
              </div>
              <?php if ($is_project_student): ?>
                <div style="display: flex; justify-content: center; gap: 8px;">
                  <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=1" class="btn btn-sm btn-primary">Upload Chapter 1</a>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: #f9fafb;">
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
                        <?php if (!isset($chapters[$i])): ?>
                          <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Start Chapter</a>
                        <?php elseif ($chapters[$i]['status'] === 'draft'): ?>
                          <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">Edit Draft</a>
                        <?php elseif ($chapters[$i]['status'] === 'revision_required'): ?>
                          <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-accent">Edit Revision</a>
                        <?php elseif (in_array($chapters[$i]['status'], ['submitted', 'under_review'], true)): ?>
                          <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-secondary">View Submission</a>
                        <?php elseif ($chapters[$i]['status'] === 'approved'): ?>
                          <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=<?php echo $i; ?>" class="btn btn-sm btn-accent">View Approved</a>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

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
                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
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

        <!-- RECENT UPLOADS CARD -->
        <div class="card research-documents">
          <div class="card-header">
            <div>
              <div class="card-title">Recent Documents</div>
            </div>
            <a href="#" style="color: var(--primary); text-decoration: none; font-size: 13px;">View All →</a>
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

        <!-- MANUAL MILESTONES CARD -->
        <div class="card research-milestones">
          <div class="card-header">
            <div>
              <div class="card-title">Manual Milestones</div>
            </div>
            <span class="badge badge-info">Research Manual 2015</span>
          </div>
          <div class="card-body">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th>File / Reference</th>
                    <th>Last Update</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $document_requirements = [
                        'proposal' => 'Proposal Document',
                        'revision_checklist' => 'Revision Checklist',
                        'defense_material' => 'Defense / Forum Materials',
                        'mou' => 'Memorandum of Research Undertaking',
                        'nda' => 'Non-Disclosure Agreement',
                        'final_bound_report' => 'Final Bound Report',
                        'publication_record' => 'Publication Record'
                    ];
                  ?>
                  <?php foreach ($document_requirements as $type => $label): ?>
                    <?php $document = $manual_documents[$type] ?? null; ?>
                    <tr>
                      <td><?php echo htmlspecialchars($label); ?></td>
                      <td>
                        <?php $doc_status = $document['status'] ?? 'pending'; ?>
                        <span class="<?php echo htmlspecialchars(manualStatusBadge($doc_status)); ?>">
                          <?php echo htmlspecialchars(formatManualLabel($doc_status)); ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars($document['original_name'] ?? 'Not uploaded'); ?></td>
                      <td>
                        <?php if (!empty($document['reviewed_at'])): ?>
                          <?php echo date('M d, Y', strtotime($document['reviewed_at'])); ?>
                        <?php elseif (!empty($document['submitted_at'])): ?>
                          <?php echo date('M d, Y', strtotime($document['submitted_at'])); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php
                    $report_requirements = [
                        'midway_progress' => 'Midway Progress Report',
                        'terminal' => 'Terminal Report'
                    ];
                  ?>
                  <?php foreach ($report_requirements as $type => $label): ?>
                    <?php $report = $manual_reports[$type] ?? null; ?>
                    <tr>
                      <td><?php echo htmlspecialchars($label); ?></td>
                      <td>
                        <?php $report_status = $report['status'] ?? 'draft'; ?>
                        <span class="<?php echo htmlspecialchars(manualStatusBadge($report_status)); ?>">
                          <?php echo htmlspecialchars(formatManualLabel($report_status)); ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars($report['summary'] ?? 'No report summary'); ?></td>
                      <td>
                        <?php if (!empty($report['reviewed_at'])): ?>
                          <?php echo date('M d, Y', strtotime($report['reviewed_at'])); ?>
                        <?php elseif (!empty($report['submitted_at'])): ?>
                          <?php echo date('M d, Y', strtotime($report['submitted_at'])); ?>
                        <?php elseif (!empty($report['due_date'])): ?>
                          Due <?php echo date('M d, Y', strtotime($report['due_date'])); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <tr>
                    <td>Research Colloquium</td>
                    <td>
                      <?php $colloquium_status = $publication_tracking['colloquium_status'] ?? 'not_scheduled'; ?>
                      <span class="<?php echo htmlspecialchars(manualStatusBadge($colloquium_status)); ?>">
                        <?php echo htmlspecialchars(formatManualLabel($colloquium_status)); ?>
                      </span>
                    </td>
                    <td><?php echo htmlspecialchars($publication_tracking['remarks'] ?? 'No colloquium notes'); ?></td>
                    <td>
                      <?php echo !empty($publication_tracking['colloquium_date']) ? date('M d, Y', strtotime($publication_tracking['colloquium_date'])) : '-'; ?>
                    </td>
                  </tr>

                  <tr>
                    <td>Journal / Archive Tracking</td>
                    <td>
                      <?php $journal_status = $publication_tracking['journal_status'] ?? 'not_submitted'; ?>
                      <span class="<?php echo htmlspecialchars(manualStatusBadge($journal_status)); ?>">
                        <?php echo htmlspecialchars(formatManualLabel($journal_status)); ?>
                      </span>
                    </td>
                    <td><?php echo htmlspecialchars($publication_tracking['journal_reference'] ?? 'No journal reference'); ?></td>
                    <td>
                      <?php $archive_status = $publication_tracking['archive_status'] ?? 'not_archived'; ?>
                      <span class="<?php echo htmlspecialchars(manualStatusBadge($archive_status)); ?>">
                        <?php echo htmlspecialchars(formatManualLabel($archive_status)); ?>
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
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
