<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student-shell.php';

requireRole('student');

$user = getCurrentUser();
$user_id = $user['user_id'];

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

            // Check access: creator OR member
            if ($project['created_by'] == $user_id) {
                $has_access = true;
            } else {
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
        }
        $stmt->close();
    }
}

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
    // hard-coded in this file (research_documents, research_reports,
    // research_publication_tracking) so SQL injection is not possible.
    static $allowed_tables = [
        'research_documents'             => true,
        'research_reports'               => true,
        'research_publication_tracking'  => true,
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
renderStudentShell($user, 'my-research.php', $detail_title, $detail_subtitle);
?>
      <!-- BREADCRUMB -->
      <div style="margin-bottom: 20px;">
        <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" style="color: var(--primary); text-decoration: none; font-size: 14px;">← Back to My Research</a>
      </div>

      <?php if (!$has_access || !$project): ?>
        <!-- ACCESS DENIED / NOT FOUND -->
        <div class="card" style="text-align: center; padding: 60px 40px;">
          <div style="font-size: 48px; margin-bottom: 16px;">❌</div>
          <h3 style="margin: 0 0 8px 0; color: var(--text-dark);">Project not found or you don't have access</h3>
          <p style="margin: 0 0 24px 0; color: var(--text-light); font-size: 14px;">
            The research project you're looking for doesn't exist or you don't have permission to view it.
          </p>
          <a href="<?php echo SITE_URL; ?>pages/student/my-research.php" class="btn btn-primary">Go back to My Research</a>
        </div>

      <?php else: ?>
        <!-- STATUS + ACTIONS CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-body" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
            <div style="flex: 1;">
              <div style="margin-bottom: 12px;">
                <?php $status = isset($project['status']) ? $project['status'] : 'draft'; $badge = isset($status_badges[$status]) ? $status_badges[$status] : $status_badges['draft']; ?>
                <span class="<?php echo htmlspecialchars($badge['class']); ?>" <?php echo !empty($badge['style']) ? 'style="' . htmlspecialchars($badge['style']) . '"' : ''; ?>>
                  <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                </span>
              </div>
              <div style="color: var(--text-light); font-size: 13px; line-height: 1.5;">
                📅 Created: <?php echo date('M d, Y', strtotime($project['created_at'])); ?><br>
                🔄 Last updated: <?php echo date('M d, Y', strtotime($project['updated_at'])); ?><br>
                👤 Lead: <?php echo htmlspecialchars($project['first_name'] . ' ' . $project['last_name']); ?>
              </div>
            </div>

          </div>
        </div>

        <!-- ABSTRACT CARD -->
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-header">
            <div class="card-title">Abstract</div>
          </div>
          <div class="card-body">
            <?php if (!empty($project['abstract'])): ?>
              <div style="white-space: pre-wrap; color: var(--text-dark); line-height: 1.6;">
                <?php echo htmlspecialchars($project['abstract'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php else: ?>
              <div style="color: var(--text-light); font-style: italic;">No abstract provided.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- CHAPTERS CARD -->
        <div class="card" style="margin-bottom: 20px;">
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
                No chapters submitted yet. Start with Chapter 1.
              </div>
              <div style="display: flex; justify-content: center; gap: 8px;">
                <a href="<?php echo SITE_URL; ?>pages/student/submit-chapter.php?project_id=<?php echo $project_id; ?>&chapter=1" class="btn btn-sm btn-primary">Upload Chapter 1</a>
              </div>
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
                  </div>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- RESEARCH TEAM CARD -->
        <div class="card" style="margin-bottom: 20px;">
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
        <div class="card" style="margin-bottom: 20px;">
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
        <div class="card" style="margin-bottom: 20px;">
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
        <div class="card">
          <div class="card-header">
            <div class="card-title">Activity</div>
          </div>
          <div class="card-body" style="color: var(--text-light); text-align: center; padding: 24px;">
            <!-- @rms-db: add project_id to activity_log to enable project-level activity tracking -->
            Recent activity for this project will appear here.
          </div>
        </div>

<?php endif; ?>

<?php renderStudentShellClose(); ?>
