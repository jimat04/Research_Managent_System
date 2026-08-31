<?php
/**
 * Student — Research Detail (View)
 *
 * Read-only project view for the owning student (or a co-member of the project).
 * Shows project metadata, abstract, current status, document, and chapter progress.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user    = getCurrentUser();
$user_id = (int) $user['user_id'];

$project_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function rd_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Status badge helper — same vocabulary as the rest of the student area
$status_label_map = [
    'draft'               => ['slate',   'Draft'],
    'submitted'           => ['blue',    'Submitted'],
    'under_review'        => ['blue',    'Under Review'],
    'under_crec_review'   => ['blue',    'CREC Review'],
    'under_erec_review'   => ['violet',  'EREC Review'],
    'for_revision'        => ['orange',  'For Revision'],
    'revision_required'   => ['orange',  'Revision Required'],
    'approved'            => ['green',   'Approved'],
    'ongoing'             => ['green',   'Ongoing'],
    'completed'           => ['emerald', 'Completed'],
    'archived'            => ['slate',   'Archived'],
];

// research_projects.deleted_at is added by the migration but not in the base dump.
$rp_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$rp_has_deleted_at = false;
if ($rp_deleted_column_stmt) {
    $rp_deleted_column_stmt->execute();
    $rp_has_deleted_at = $rp_deleted_column_stmt->get_result()->num_rows > 0;
    $rp_deleted_column_stmt->close();
}
$rp_deleted_filter = $rp_has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// project_members.created_at is added by the migration; the base dump only has (id, project_id, user_id, role).
// Use a runtime check so this page is safe on both schemas.
$pm_created_at_stmt = $conn->prepare("SHOW COLUMNS FROM project_members LIKE 'created_at'");
$pm_has_created_at = false;
if ($pm_created_at_stmt) {
    $pm_created_at_stmt->execute();
    $pm_has_created_at = $pm_created_at_stmt->get_result()->num_rows > 0;
    $pm_created_at_stmt->close();
}
$pm_select_col = $pm_has_created_at ? 'pm.created_at AS added_at' : 'pm.id AS added_at';
$pm_order_col  = $pm_has_created_at ? 'pm.created_at' : 'pm.id';

// Fetch the project — must be owner OR a project member
$project = null;
if ($project_id > 0) {
    $stmt = $conn->prepare("
        SELECT
            rp.project_id, rp.title, rp.research_area, rp.abstract, rp.status,
            rp.created_by, rp.created_at, rp.updated_at,
            rp.category_id, rp.ay_id,
            rc.category_name,
            ay.label AS ay_label, ay.semester,
            CONCAT(u.first_name, ' ', u.last_name) AS lead_name, u.email AS lead_email
        FROM research_projects rp
        LEFT JOIN research_categories rc ON rp.category_id = rc.category_id
        LEFT JOIN academic_years ay ON rp.ay_id = ay.ay_id
        LEFT JOIN users u ON rp.created_by = u.user_id
        WHERE rp.project_id = ?" . $rp_deleted_filter . "
          AND (rp.created_by = ? OR EXISTS (
                SELECT 1 FROM project_members pm
                WHERE pm.project_id = rp.project_id AND pm.user_id = ?
          ))
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('iii', $project_id, $user_id, $user_id);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

// Co-members + adviser (optional joins — best-effort, wrapped to survive missing tables)
$members = [];
if ($project) {
    $m = $conn->prepare("
        SELECT pm.role, $pm_select_col, u.user_id, u.first_name, u.last_name, u.email
        FROM project_members pm
        LEFT JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ?
        ORDER BY (pm.role = 'lead') DESC, $pm_order_col ASC
    ");
    if ($m) {
        $m->bind_param('i', $project_id);
        $m->execute();
        $r = $m->get_result();
        while ($row = $r->fetch_assoc()) {
            $members[] = $row;
        }
        $m->close();
    }
}

// Chapter progress (uses base schema — chapters table is in the base dump)
$chapters = [];
if ($project) {
    $c = $conn->prepare("
        SELECT chapter_id, chapter_number, chapter_title, status, version, updated_at
        FROM chapters
        WHERE project_id = ?
        ORDER BY chapter_number ASC
    ");
    if ($c) {
        $c->bind_param('i', $project_id);
        $c->execute();
        $r = $c->get_result();
        while ($row = $r->fetch_assoc()) {
            $chapters[(int) $row['chapter_number']] = $row;
        }
        $c->close();
    }
}

// Latest proposal document (if any)
$proposal_doc = null;
if ($project) {
    // uploads.deleted_at is present in some installations but not in the supplied base dump.
    $uploads_deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM uploads LIKE 'deleted_at'");
    $uploads_have_deleted_at = false;
    if ($uploads_deleted_column_stmt) {
        $uploads_deleted_column_stmt->execute();
        $uploads_have_deleted_at = $uploads_deleted_column_stmt->get_result()->num_rows > 0;
        $uploads_deleted_column_stmt->close();
    }
    $uploads_filter = $uploads_have_deleted_at ? ' AND deleted_at IS NULL' : '';
    $d = $conn->prepare("
        SELECT upload_id, original_name, file_name, file_size, mime_type, uploaded_at, chapter_id
        FROM uploads
        WHERE project_id = ? AND type = 'proposal'" . $uploads_filter . "
        ORDER BY uploaded_at DESC LIMIT 1
    ");
    if ($d) {
        $d->bind_param('i', $project_id);
        $d->execute();
        $proposal_doc = $d->get_result()->fetch_assoc() ?: null;
        $d->close();
    }
}

// Friendly flash banner (read by Edit + Submit pages)
$flash_success = $_SESSION['module_success'] ?? null;
$flash_error   = $_SESSION['module_error']   ?? null;
unset($_SESSION['module_success'], $_SESSION['module_error']);

renderStudentShell($user, 'research-detail', $project ? rd_escape($project['title']) : 'Research Detail', $project ? 'View your research project details, status, and chapter progress.' : '');
?>

<style>
  .back-link {
    color: #5B1EBC;
    text-decoration: none;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 20px;
  }
  .back-link:hover { text-decoration: underline; }

  .card {
    background: #ffffff;
    border: 1px solid #E5E7EB;
    border-radius: 20px;
    padding: 28px;
    margin-bottom: 20px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
  }

  .card-title {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 4px 0;
  }

  .card-subtitle {
    font-size: 13px;
    color: #64748B;
    margin: 0;
  }

  .meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-top: 16px;
  }

  .meta-label {
    font-size: 12px;
    font-weight: 600;
    color: #94A3B8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
  }

  .meta-value {
    font-size: 14px;
    color: #111827;
    font-weight: 500;
  }

  .badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 600;
  }
  .badge-slate   { background: #F1F5F9; color: #475569; }
  .badge-blue    { background: #DBEAFE; color: #2563EB; }
  .badge-violet  { background: #EDE9FE; color: #7C3AED; }
  .badge-orange  { background: #FEF3C7; color: #EA580C; }
  .badge-green   { background: #DCFCE7; color: #16A34A; }
  .badge-emerald { background: #D1FAE5; color: #059669; }

  .abstract-body {
    margin-top: 12px;
    padding: 16px;
    background: #F8FAFC;
    border-radius: 12px;
    border-left: 3px solid #5B1EBC;
    color: #111827;
    line-height: 1.7;
    white-space: pre-wrap;
    word-wrap: break-word;
  }

  .chapter-list { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
  .chapter-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 16px;
    background: #F8FAFC;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
  }
  .chapter-num {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: #111827;
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
  }
  .chapter-num.approved   { background: #16A34A; }
  .chapter-num.review     { background: #2563EB; }
  .chapter-num.revision   { background: #EA580C; }
  .chapter-info { flex: 1; min-width: 0; }
  .chapter-name { font-weight: 600; color: #111827; margin-bottom: 2px; }
  .chapter-meta { font-size: 12px; color: #64748B; }
  .chapter-status { font-size: 12px; font-weight: 600; }

  .empty {
    padding: 32px 16px;
    text-align: center;
    color: #94A3B8;
    font-size: 14px;
  }

  .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
  .alert-success { background: #DCFCE7; color: #16A34A; border: 1px solid #86EFAC; }
  .alert-error   { background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; }

  .doc-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    background: #F8FAFC;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
  }
  .doc-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    background: #EDE9FE;
    color: #5B1EBC;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
  }
  .doc-name { font-weight: 600; color: #111827; }
  .doc-meta { font-size: 12px; color: #64748B; }

  .actions { display: flex; gap: 10px; flex-wrap: wrap; }
  .btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
  }
  .btn-primary { background: #5B1EBC; color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(91, 30, 188, 0.3); }
  .btn-secondary { background: #F8FAFC; color: #111827; border: 1px solid #E5E7EB; }
  .btn-secondary:hover { background: #111827; color: white; }
  .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
</style>

<?php if ($flash_success): ?>
  <div class="alert alert-success">✅ <?php echo rd_escape($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error">❌ <?php echo rd_escape($flash_error); ?></div>
<?php endif; ?>

<a href="<?php echo SITE_URL; ?>pages/student/my-research.php" class="back-link">← Back to My Research</a>

<?php if (!$project): ?>
  <div class="card">
    <div class="empty">
      <div style="font-size: 48px; margin-bottom: 12px;">🔍</div>
      <p style="margin: 0 0 8px 0; color: #111827; font-weight: 600;">Project not found</p>
      <p style="margin: 0;">This project does not exist, was deleted, or you don't have access to it.</p>
    </div>
  </div>
<?php else: ?>

  <?php
    $status_key = $project['status'];
    [$status_class, $status_text] = $status_label_map[$status_key] ?? ['slate', ucwords(str_replace('_', ' ', $status_key))];
    $is_editable = in_array($status_key, ['draft', 'for_revision', 'revision_required'], true);
    $approved_count = 0;
    foreach ($chapters as $ch) { if (($ch['status'] ?? '') === 'approved') $approved_count++; }
  ?>

  <!-- ACTIONS BAR -->
  <div class="card" style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
    <div>
      <div style="font-size: 12px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px;">Project Status</div>
      <span class="badge badge-<?php echo $status_class; ?>" style="font-size: 13px; padding: 8px 16px;"><?php echo rd_escape($status_text); ?></span>
    </div>
    <div class="actions">
      <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" class="btn btn-secondary">← Back to List</a>
      <?php if ($is_editable): ?>
        <a href="<?php echo SITE_URL; ?>pages/student/edit-research.php?id=<?php echo (int) $project['project_id']; ?>" class="btn btn-primary">✏️ Edit Project</a>
      <?php else: ?>
        <button class="btn btn-primary" disabled title="Editing is disabled once the project is submitted for review.">✏️ Edit Project</button>
      <?php endif; ?>
      <?php if ($status_key === 'approved' || $status_key === 'ongoing'): ?>
        <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo (int) $project['project_id']; ?>" class="btn btn-secondary">📄 Submit Chapter</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- BASIC INFORMATION -->
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?php echo rd_escape($project['title']); ?></h2>
        <p class="card-subtitle">Basic information about your research project</p>
      </div>
    </div>

    <div class="meta-grid">
      <div>
        <div class="meta-label">Category</div>
        <div class="meta-value"><?php echo rd_escape($project['category_name'] ?? 'N/A'); ?></div>
      </div>
      <div>
        <div class="meta-label">Academic Year / Semester</div>
        <div class="meta-value"><?php echo rd_escape(trim(($project['ay_label'] ?? '') . ' / ' . ($project['semester'] ?? ''), ' /')); ?></div>
      </div>
      <div>
        <div class="meta-label">Research Area</div>
        <div class="meta-value"><?php echo $project['research_area'] ? rd_escape($project['research_area']) : '<span style="color:#94A3B8;">Not specified</span>'; ?></div>
      </div>
      <div>
        <div class="meta-label">Lead Researcher</div>
        <div class="meta-value"><?php echo rd_escape($project['lead_name'] ?? 'N/A'); ?><?php if (!empty($project['lead_email'])): ?><div style="font-size:12px;color:#64748B;font-weight:400;"><?php echo rd_escape($project['lead_email']); ?></div><?php endif; ?></div>
      </div>
      <div>
        <div class="meta-label">Created</div>
        <div class="meta-value"><?php echo date('M d, Y', strtotime($project['created_at'])); ?></div>
      </div>
      <div>
        <div class="meta-label">Last Updated</div>
        <div class="meta-value"><?php echo date('M d, Y g:i A', strtotime($project['updated_at'])); ?></div>
      </div>
    </div>
  </div>

  <!-- ABSTRACT -->
  <div class="card">
    <h2 class="card-title">Abstract</h2>
    <p class="card-subtitle">Purpose, methods, and expected outcomes</p>
    <div class="abstract-body"><?php echo rd_escape($project['abstract'] ?? ''); ?></div>
  </div>

  <!-- PROPOSAL DOCUMENT -->
  <div class="card">
    <h2 class="card-title">Proposal Document</h2>
    <p class="card-subtitle">The file you uploaded when you submitted this project</p>
    <?php if ($proposal_doc): ?>
      <div class="doc-card">
        <div class="doc-icon">📄</div>
        <div style="flex: 1; min-width: 0;">
          <div class="doc-name"><?php echo rd_escape($proposal_doc['original_name']); ?></div>
          <div class="doc-meta">
            <?php echo rd_escape(strtoupper(pathinfo($proposal_doc['original_name'], PATHINFO_EXTENSION) ?: '')); ?>
            ·
            <?php echo number_format(((int) $proposal_doc['file_size']) / 1024, 1); ?> KB
            ·
            Uploaded <?php echo date('M d, Y', strtotime($proposal_doc['uploaded_at'])); ?>
          </div>
        </div>
        <a href="<?php echo SITE_URL; ?>public/download.php?id=<?php echo (int) $proposal_doc['upload_id']; ?>" class="btn btn-secondary btn-sm">⬇ Download</a>
      </div>
    <?php else: ?>
      <div class="empty" style="padding: 20px;">
        <p style="margin: 0;">📎 No proposal document has been uploaded yet.</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- CHAPTER PROGRESS -->
  <div class="card">
    <h2 class="card-title">Chapter Progress</h2>
    <p class="card-subtitle"><?php echo $approved_count; ?> of 5 chapters approved</p>

    <?php
      $chapter_titles = [
        1 => 'The Problem and Its Background',
        2 => 'Review of Related Literature',
        3 => 'Methodology',
        4 => 'Results and Discussion',
        5 => 'Summary, Conclusions, and Recommendations',
      ];
    ?>

    <div class="chapter-list">
      <?php foreach ($chapter_titles as $num => $default_title):
        $row = $chapters[$num] ?? null;
        $status = $row['status'] ?? 'draft';
        $num_class = '';
        $status_text_ch = ucwords(str_replace('_', ' ', $status));
        $status_color = '#64748B';
        if ($status === 'approved')        { $num_class = 'approved'; $status_color = '#16A34A'; }
        elseif ($status === 'under_review') { $num_class = 'review';   $status_color = '#2563EB'; }
        elseif ($status === 'revision_required' || $status === 'for_revision') { $num_class = 'revision'; $status_color = '#EA580C'; }
        $title = $row['chapter_title'] ?? $default_title;
      ?>
        <div class="chapter-row">
          <div class="chapter-num <?php echo $num_class; ?>"><?php echo (int) $num; ?></div>
          <div class="chapter-info">
            <div class="chapter-name"><?php echo rd_escape($title); ?></div>
            <div class="chapter-meta">
              <?php if ($row): ?>
                v<?php echo (int) ($row['version'] ?? 1); ?>
                · Updated <?php echo date('M d, Y', strtotime($row['updated_at'])); ?>
              <?php else: ?>
                Not yet started
              <?php endif; ?>
            </div>
          </div>
          <div class="chapter-status" style="color: <?php echo $status_color; ?>;"><?php echo rd_escape($status_text_ch); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TEAM MEMBERS -->
  <?php if (!empty($members)): ?>
  <div class="card">
    <h2 class="card-title">Research Team</h2>
    <p class="card-subtitle"><?php echo count($members); ?> member<?php echo count($members) === 1 ? '' : 's'; ?></p>
    <div class="chapter-list">
      <?php foreach ($members as $mem): ?>
        <div class="chapter-row">
          <div class="chapter-num" style="background: #5B1EBC;">
            <?php echo strtoupper(substr($mem['first_name'] ?? '?', 0, 1) . substr($mem['last_name'] ?? '', 0, 1)); ?>
          </div>
          <div class="chapter-info">
            <div class="chapter-name"><?php echo rd_escape(trim(($mem['first_name'] ?? '') . ' ' . ($mem['last_name'] ?? ''))); ?></div>
            <div class="chapter-meta">
              <?php echo rd_escape(ucfirst($mem['role'] ?? 'member')); ?>
              <?php if (!empty($mem['email'])): ?>
                · <?php echo rd_escape($mem['email']); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php renderStudentShellClose(); ?>
