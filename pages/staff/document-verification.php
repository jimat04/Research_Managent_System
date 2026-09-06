<?php
/**
 * Staff — Document Verification
 *
 * Read-only lookup and on-demand integrity check for research documents.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff-shell.php';

requireLogin();
requireRole(['research_staff', 'admin']);

$user = getCurrentUser();

function dver_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dver_table_columns(mysqli $conn, string $table): array {
    $allowed = ['research_documents', 'research_projects', 'uploads'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $columns = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[(string) $row['COLUMN_NAME']] = true;
    }
    $stmt->close();
    return $columns;
}

function dver_document_label(string $type): string {
    $map = [
        'mou'                => 'MOU',
        'nda'                => 'NDA',
        'progress_report'    => 'Midway Progress Report',
        'terminal_report'    => 'Terminal Report',
        'bound_report'       => 'Final Bound Report',
        'final_bound_report' => 'Final Bound Report',
        'publication_record' => 'Publication Record',
        'defense_material'   => 'Defense Material',
        'revision_checklist' => 'Revision Checklist',
        'proposal'           => 'Proposal Document',
        'other'              => 'Document',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function dver_status_badge(string $status): array {
    $map = [
        'pending'   => ['status-pending', 'Pending'],
        'submitted' => ['status-review', 'Submitted'],
        'approved'  => ['status-approved', 'Approved'],
        'rejected'  => ['status-rejected', 'Rejected'],
        'waived'    => ['status-waived', 'Waived'],
    ];
    return $map[$status] ?? ['status-neutral', ucwords(str_replace('_', ' ', $status))];
}

function dver_date(?string $date): string {
    if (!$date) {
        return 'Not recorded';
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? 'Not recorded' : date('M d, Y h:i A', $timestamp);
}

$document_columns = dver_table_columns($conn, 'research_documents');
$project_columns = dver_table_columns($conn, 'research_projects');
$upload_columns = dver_table_columns($conn, 'uploads');

$lookup_requested = array_key_exists('document_id', $_GET);
$document_id = $lookup_requested
    ? filter_var($_GET['document_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$document = null;
$not_found = $lookup_requested;

if ($lookup_requested && $document_id !== false && !empty($document_columns)) {
    $remarks_select = isset($document_columns['remarks']) ? 'rd.remarks' : 'NULL';
    $reviewed_by_select = isset($document_columns['reviewed_by']) ? 'rd.reviewed_by' : 'NULL';
    $reviewed_at_select = isset($document_columns['reviewed_at']) ? 'rd.reviewed_at' : 'NULL';
    $reviewer_select = isset($document_columns['reviewed_by'])
        ? 'reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name'
        : 'NULL AS reviewer_first_name, NULL AS reviewer_last_name';
    $reviewer_join = isset($document_columns['reviewed_by'])
        ? ' LEFT JOIN users reviewer ON reviewer.user_id = rd.reviewed_by'
        : '';

    $file_join = '';
    $file_path_select = "''";
    $file_name_select = "''";
    $original_name_select = "''";

    if (isset($document_columns['file_path'])) {
        $file_path_select = 'rd.file_path';
        $file_name_select = isset($document_columns['file_name']) ? 'rd.file_name' : "''";
        $original_name_select = isset($document_columns['original_name']) ? 'rd.original_name' : $file_name_select;
    } elseif (isset($document_columns['upload_id'], $upload_columns['upload_id'], $upload_columns['file_path'])) {
        $upload_deleted_filter = isset($upload_columns['deleted_at']) ? ' AND up.deleted_at IS NULL' : '';
        $file_join = ' LEFT JOIN uploads up ON up.upload_id = rd.upload_id' . $upload_deleted_filter;
        $file_path_select = 'up.file_path';
        $file_name_select = isset($upload_columns['file_name']) ? 'up.file_name' : "''";
        $original_name_select = isset($upload_columns['original_name']) ? 'up.original_name' : $file_name_select;
    }

    $project_deleted_filter = isset($project_columns['deleted_at']) ? ' AND rp.deleted_at IS NULL' : '';
    $lookup_stmt = $conn->prepare(
        "SELECT rd.document_id, rd.project_id, rd.document_type, rd.status,
                $remarks_select AS remarks,
                rd.submitted_by, rd.submitted_at,
                $reviewed_by_select AS reviewed_by,
                $reviewed_at_select AS reviewed_at,
                $file_path_select AS file_path,
                $file_name_select AS file_name,
                $original_name_select AS original_name,
                rp.title AS project_title,
                submitter.first_name AS submitter_first_name,
                submitter.last_name AS submitter_last_name,
                $reviewer_select
           FROM research_documents rd
           JOIN research_projects rp ON rp.project_id = rd.project_id"
           . $file_join . "
           LEFT JOIN users submitter ON submitter.user_id = rd.submitted_by"
           . $reviewer_join . '
          WHERE rd.document_id = ?'
           . $project_deleted_filter . '
          LIMIT 1'
    );
    if ($lookup_stmt) {
        $lookup_stmt->bind_param('i', $document_id);
        $lookup_stmt->execute();
        $document = $lookup_stmt->get_result()->fetch_assoc() ?: null;
        $lookup_stmt->close();
    }
    $not_found = $document === null;
}

$file_exists = false;
$file_size_kb = null;
$file_sha1 = null;
if ($document) {
    $app_root = realpath(__DIR__ . '/../../');
    $stored_path = trim((string) ($document['file_path'] ?? ''));
    if ($app_root !== false && $stored_path !== '') {
        $relative_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($stored_path, '/\\'));
        $resolved_path = realpath($app_root . DIRECTORY_SEPARATOR . $relative_path);
        $inside_app = $resolved_path !== false
            && ($resolved_path === $app_root
                || str_starts_with($resolved_path, $app_root . DIRECTORY_SEPARATOR));
        $file_exists = $inside_app && is_file($resolved_path);
        if ($file_exists) {
            $bytes = filesize($resolved_path);
            $hash = sha1_file($resolved_path);
            $file_size_kb = $bytes === false ? null : $bytes / 1024;
            $file_sha1 = $hash === false ? null : $hash;
        }
    }
}

renderStaffShell(
    $user,
    'document-verification.php',
    'Document Verification',
    'Look up a research document and verify its stored-file integrity.'
);
?>

<style>
  .verify-card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 22px;
  }
  .verify-card h2 { margin: 0 0 5px; color: #111827; font-size: 18px; }
  .verify-card-sub { margin: 0 0 18px; color: #64748B; font-size: 13px; }
  .lookup-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
  .form-group { flex: 1 1 260px; }
  .form-label { display: block; margin-bottom: 7px; color: #334155; font-size: 13px; font-weight: 650; }
  .form-control {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 13px;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    color: #111827;
    background: #fff;
    font-size: 14px;
  }
  .form-control:focus { outline: 2px solid rgba(109,40,217,.18); border-color: #7C3AED; }
  .verify-button {
    padding: 11px 17px;
    border: 0;
    border-radius: 10px;
    background: #6D28D9;
    color: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
  }
  .verify-button:hover { background: #5B21B6; }
  .document-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 18px;
    margin-bottom: 4px;
    border-bottom: 1px solid #E5E7EB;
  }
  .document-title { margin: 0; color: #111827; font-size: 20px; }
  .document-project { margin-top: 6px; color: #64748B; font-size: 13px; }
  .status-badge {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 999px;
    white-space: nowrap;
    font-size: 11px;
    font-weight: 700;
  }
  .status-pending { background: #FEF3C7; color: #B45309; }
  .status-review { background: #DBEAFE; color: #1D4ED8; }
  .status-approved { background: #DCFCE7; color: #15803D; }
  .status-rejected { background: #FEE2E2; color: #B91C1C; }
  .status-waived, .status-neutral { background: #F1F5F9; color: #475569; }
  .detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 28px;
  }
  .detail-row { padding: 15px 0; border-bottom: 1px solid #F1F5F9; }
  .detail-label { margin-bottom: 5px; color: #94A3B8; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .detail-value { color: #334155; font-size: 14px; overflow-wrap: anywhere; }
  .remarks-box {
    padding: 15px;
    margin-top: 18px;
    border: 1px solid #E2E8F0;
    border-radius: 11px;
    background: #F8FAFC;
    color: #475569;
    font-size: 13px;
    line-height: 1.5;
  }
  .integrity-card { border-color: #C7D2FE; background: #F8FAFF; }
  .integrity-line {
    display: grid;
    grid-template-columns: 180px minmax(0, 1fr);
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid #E0E7FF;
    font-size: 13px;
  }
  .integrity-line:last-child { border-bottom: 0; }
  .integrity-label { color: #64748B; font-weight: 650; }
  .integrity-value { color: #1E293B; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
  .integrity-yes { color: #15803D; font-weight: 700; }
  .integrity-no { color: #B91C1C; font-weight: 700; }
  .not-found { padding: 48px 24px; text-align: center; }
  .not-found-icon { margin-bottom: 10px; font-size: 42px; }
  .not-found h2 { margin: 0 0 7px; color: #334155; }
  .not-found p { margin: 0; color: #64748B; font-size: 14px; }
  @media (max-width: 720px) {
    .detail-grid { grid-template-columns: 1fr; }
    .integrity-line { grid-template-columns: 1fr; gap: 4px; }
  }
</style>

<section class="verify-card">
  <h2>Find a document</h2>
  <p class="verify-card-sub">Enter the numeric document ID recorded in the research document register.</p>
  <form method="get" class="lookup-form">
    <div class="form-group">
      <label class="form-label" for="document_id">Document ID</label>
      <input class="form-control" id="document_id" name="document_id" type="number" min="1" step="1"
             value="<?php echo $document_id !== false ? (int) $document_id : ''; ?>"
             placeholder="e.g. 12" required>
    </div>
    <button class="verify-button" type="submit">Verify document</button>
  </form>
</section>

<?php if ($not_found): ?>
  <section class="verify-card not-found">
    <div class="not-found-icon">🔎</div>
    <h2>Document not found</h2>
    <p><?php echo $document_id === false
        ? 'Enter a valid positive numeric document ID.'
        : 'No research document matches that ID.'; ?></p>
  </section>
<?php elseif ($document):
    [$badge_class, $badge_label] = dver_status_badge((string) $document['status']);
    $submitter_name = trim((string) ($document['submitter_first_name'] ?? '') . ' ' . (string) ($document['submitter_last_name'] ?? ''));
    $reviewer_name = trim((string) ($document['reviewer_first_name'] ?? '') . ' ' . (string) ($document['reviewer_last_name'] ?? ''));
    $submitter_display = $submitter_name !== ''
        ? $submitter_name
        : ((int) ($document['submitted_by'] ?? 0) > 0 ? 'User #' . (int) $document['submitted_by'] : 'Not recorded');
    $reviewer_display = $reviewer_name !== ''
        ? $reviewer_name
        : ((int) ($document['reviewed_by'] ?? 0) > 0 ? 'User #' . (int) $document['reviewed_by'] : 'Not reviewed');
    $display_file_name = (string) ($document['original_name'] ?: ($document['file_name'] ?: 'Not recorded'));
?>
  <section class="verify-card">
    <div class="document-heading">
      <div>
        <h2 class="document-title"><?php echo dver_escape(dver_document_label((string) $document['document_type'])); ?></h2>
        <div class="document-project">
          <?php echo dver_escape($document['project_title']); ?> · Project #<?php echo (int) $document['project_id']; ?> · Document #<?php echo (int) $document['document_id']; ?>
        </div>
      </div>
      <span class="status-badge <?php echo dver_escape($badge_class); ?>"><?php echo dver_escape($badge_label); ?></span>
    </div>

    <div class="detail-grid">
      <div class="detail-row"><div class="detail-label">Original file name</div><div class="detail-value"><?php echo dver_escape($display_file_name); ?></div></div>
      <div class="detail-row"><div class="detail-label">Document type</div><div class="detail-value"><?php echo dver_escape((string) $document['document_type']); ?></div></div>
      <div class="detail-row"><div class="detail-label">Submitted by</div><div class="detail-value"><?php echo dver_escape($submitter_display); ?></div></div>
      <div class="detail-row"><div class="detail-label">Submitted at</div><div class="detail-value"><?php echo dver_escape(dver_date($document['submitted_at'] ?? null)); ?></div></div>
      <?php if (isset($document_columns['reviewed_by'])): ?>
        <div class="detail-row"><div class="detail-label">Reviewed by</div><div class="detail-value"><?php echo dver_escape($reviewer_display); ?></div></div>
      <?php endif; ?>
      <?php if (isset($document_columns['reviewed_at'])): ?>
        <div class="detail-row"><div class="detail-label">Reviewed at</div><div class="detail-value"><?php echo dver_escape(dver_date($document['reviewed_at'] ?? null)); ?></div></div>
      <?php endif; ?>
    </div>

    <?php if (isset($document_columns['remarks'])): ?>
      <div class="remarks-box"><strong>Remarks:</strong><br><?php echo trim((string) ($document['remarks'] ?? '')) !== ''
          ? nl2br(dver_escape($document['remarks']))
          : 'No remarks recorded.'; ?></div>
    <?php endif; ?>
  </section>

  <section class="verify-card integrity-card">
    <h2>Stored-file integrity</h2>
    <p class="verify-card-sub">Computed on demand from the file stored under the RMS application root. No download is offered from this page.</p>
    <div class="integrity-line">
      <div class="integrity-label">File exists</div>
      <div class="integrity-value <?php echo $file_exists ? 'integrity-yes' : 'integrity-no'; ?>"><?php echo $file_exists ? 'Yes' : 'No'; ?></div>
    </div>
    <div class="integrity-line">
      <div class="integrity-label">File size</div>
      <div class="integrity-value"><?php echo $file_size_kb !== null ? dver_escape(number_format($file_size_kb, 2) . ' KB') : 'Unavailable'; ?></div>
    </div>
    <div class="integrity-line">
      <div class="integrity-label">SHA-1</div>
      <div class="integrity-value"><?php echo dver_escape($file_sha1 ?? 'Unavailable'); ?></div>
    </div>
  </section>
<?php elseif (!$lookup_requested): ?>
  <section class="verify-card not-found">
    <div class="not-found-icon">📄</div>
    <h2>Enter a document ID to begin</h2>
    <p>The document metadata and live integrity result will appear here.</p>
  </section>
<?php endif; ?>

<?php renderStaffShellClose(); ?>
