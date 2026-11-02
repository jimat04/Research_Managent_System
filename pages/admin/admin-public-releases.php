<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/publication-classification.php';

requireRole('admin');
$user = getCurrentUser();
$userId = (int) ($user['user_id'] ?? 0);

function publicReleaseEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function publicReleaseRows(mysqli $conn, string $sql, string $types, array $values): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare public release queue: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load public release queue: ' . $error);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$success = (string) ($_SESSION['public_release_success'] ?? '');
$error = (string) ($_SESSION['public_release_error'] ?? '');
unset($_SESSION['public_release_success'], $_SESSION['public_release_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'save_public_release') {
        http_response_code(400);
        exit('Invalid public release request.');
    } else {
        $sourceType = is_string($_POST['source_type'] ?? null) ? $_POST['source_type'] : '';
        $sourceId = filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $releaseStatus = is_string($_POST['release_status'] ?? null) ? $_POST['release_status'] : '';
        $sourceColumns = [
            'student_project' => 'project_id',
            'faculty_publication' => 'publication_id',
            'faculty_research_record' => 'faculty_research_record_id',
        ];
        $eligible = false;
        $source = null;
        $fileVersionId = null;
        $paperAction = is_string($_POST['publication_paper_action'] ?? null) ? $_POST['publication_paper_action'] : 'keep';

        if (!isset($sourceColumns[$sourceType]) || $sourceId <= 0 || !in_array($releaseStatus, ['published', 'withdrawn'], true)) {
            $error = 'Choose a valid record and release action.';
        } else {
            if ($sourceType === 'student_project') {
                $sourceStmt = $conn->prepare("SELECT rp.project_id, rp.title FROM research_projects rp WHERE rp.project_id = ? AND rp.status IN ('completed', 'archived') AND rp.deleted_at IS NULL LIMIT 1");
                $sourceStmt->bind_param('i', $sourceId);
                $sourceStmt->execute();
                $source = $sourceStmt->get_result()->fetch_assoc() ?: null;
                $sourceStmt->close();
                $eligible = $source !== null;
                $rawVersion = $_POST['manuscript_version_id'] ?? '';
                if ($rawVersion !== '' && $rawVersion !== null) {
                    $fileVersionId = filter_var($rawVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
                    if (!$fileVersionId) {
                        $error = 'Choose an approved final-manuscript version.';
                    } else {
                        $versionStmt = $conn->prepare("SELECT v.version_id FROM research_submission_versions v JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id JOIN uploads up ON up.upload_id = vu.upload_id AND up.deleted_at IS NULL WHERE v.version_id = ? AND v.project_id = ? AND v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL AND v.status = 'approved' LIMIT 1");
                        $versionStmt->bind_param('ii', $fileVersionId, $sourceId);
                        $versionStmt->execute();
                        $validVersion = $versionStmt->get_result()->num_rows > 0;
                        $versionStmt->close();
                        if (!$validVersion) {
                            $fileVersionId = null;
                            $error = 'That manuscript version is no longer available for public release.';
                        }
                    }
                }
            } elseif ($sourceType === 'faculty_publication') {
                $sourceStmt = $conn->prepare("SELECT publication_id, research_title, file_path, file_name FROM publications WHERE publication_id = ? AND researcher_category = 'faculty' AND (status = 'published' OR archival_status = 'archived') AND deactivated_at IS NULL LIMIT 1");
                $sourceStmt->bind_param('i', $sourceId);
                $sourceStmt->execute();
                $source = $sourceStmt->get_result()->fetch_assoc() ?: null;
                $sourceStmt->close();
                $eligible = $source !== null;
                if (!in_array($paperAction, ['keep', 'release_current', 'withdraw'], true)) {
                    $error = 'Choose a valid publication paper release action.';
                } elseif ($paperAction === 'release_current' && empty($source['file_path'])) {
                    $error = 'This publication has no primary paper file to release.';
                }
            } else {
                $sourceStmt = $conn->prepare("SELECT research_record_id, title FROM faculty_research_records WHERE research_record_id = ? AND status = 'archived' AND deleted_at IS NULL LIMIT 1");
                $sourceStmt->bind_param('i', $sourceId);
                $sourceStmt->execute();
                $source = $sourceStmt->get_result()->fetch_assoc() ?: null;
                $sourceStmt->close();
                $eligible = $source !== null;
            }

            if (!$eligible && $error === '') {
                $error = 'This record is no longer eligible for public release.';
            }
            if ($releaseStatus === 'withdrawn') {
                $fileVersionId = null;
                $paperAction = 'withdraw';
            }

            if ($error === '' && $eligible) {
                $sourceColumn = $sourceColumns[$sourceType];
                $sourceValues = [
                    'project_id' => null,
                    'publication_id' => null,
                    'faculty_research_record_id' => null,
                ];
                $sourceValues[$sourceColumn] = $sourceId;
                $existingStmt = $conn->prepare("SELECT release_id, release_status, public_manuscript_version_id, publication_paper_released, publication_paper_path, publication_paper_name FROM public_research_releases WHERE source_type = ? AND `{$sourceColumn}` = ? LIMIT 1 FOR UPDATE");
                if (!$conn->begin_transaction()) {
                    $error = 'Unable to start the release update.';
                } else {
                    $existingStmt->bind_param('si', $sourceType, $sourceId);
                    $existingStmt->execute();
                    $existing = $existingStmt->get_result()->fetch_assoc() ?: null;
                    $existingStmt->close();
                    $releaseVersion = $sourceType === 'student_project' ? $fileVersionId : null;
                    $paperPath = null;
                    $paperName = null;
                    $paperFlag = 0;
                    if ($sourceType === 'faculty_publication' && $releaseStatus === 'published') {
                        if ($paperAction === 'release_current') {
                            $paperFlag = 1;
                            $paperPath = (string) $source['file_path'];
                            $paperName = (string) ($source['file_name'] ?? '');
                        } elseif ($paperAction === 'keep' && (int) ($existing['publication_paper_released'] ?? 0) === 1) {
                            $paperFlag = 1;
                            $paperPath = (string) ($existing['publication_paper_path'] ?? '');
                            $paperName = (string) ($existing['publication_paper_name'] ?? '');
                        }
                    }
                    $fileChanged = !$existing
                        || (int) ($existing['public_manuscript_version_id'] ?? 0) !== (int) ($releaseVersion ?? 0)
                        || (int) ($existing['publication_paper_released'] ?? 0) !== $paperFlag
                        || (string) ($existing['publication_paper_path'] ?? '') !== (string) ($paperPath ?? '')
                        || (string) ($existing['publication_paper_name'] ?? '') !== (string) ($paperName ?? '');

                    if ($existing) {
                        $releaseId = (int) $existing['release_id'];
                        $updateStmt = $conn->prepare('UPDATE public_research_releases SET release_status = ?, reviewed_by = ?, reviewed_at = NOW(), public_manuscript_version_id = ?, publication_paper_released = ?, publication_paper_path = ?, publication_paper_name = ?, file_reviewed_by = IF(?, ?, file_reviewed_by), file_reviewed_at = IF(?, NOW(), file_reviewed_at) WHERE release_id = ?');
                        $updateStmt->bind_param('siiissiiii', $releaseStatus, $userId, $releaseVersion, $paperFlag, $paperPath, $paperName, $fileChanged, $userId, $fileChanged, $releaseId);
                        $saved = $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        $insertStmt = $conn->prepare('INSERT INTO public_research_releases (source_type, project_id, publication_id, faculty_research_record_id, release_status, reviewed_by, reviewed_at, public_manuscript_version_id, publication_paper_released, publication_paper_path, publication_paper_name, file_reviewed_by, file_reviewed_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL))');
                        $insertStmt->bind_param('siiisiiissii', $sourceType, $sourceValues['project_id'], $sourceValues['publication_id'], $sourceValues['faculty_research_record_id'], $releaseStatus, $userId, $releaseVersion, $paperFlag, $paperPath, $paperName, $userId, $fileChanged);
                        $saved = $insertStmt->execute();
                        $insertStmt->close();
                    }

                    $actionLabel = $releaseStatus === 'published' ? 'published' : 'withdrew';
                    $fileLabel = $sourceType === 'student_project'
                        ? ($releaseVersion ? ' manuscript version ' . (int) $releaseVersion : ' no manuscript file')
                        : ($sourceType === 'faculty_publication' ? ($paperFlag ? ' primary publication paper' : ' no publication paper') : '');
                    if (!$saved || !logActivity('Admin ' . $actionLabel . ' public ' . str_replace('_', ' ', $sourceType) . ' #' . $sourceId . $fileLabel, 'public_research_releases')) {
                        $conn->rollback();
                        $error = 'The release could not be saved and audited. No changes were kept.';
                    } elseif (!$conn->commit()) {
                        $conn->rollback();
                        $error = 'The release could not be committed. Please try again.';
                    } else {
                        $success = $releaseStatus === 'published'
                            ? 'Public release saved. The original record owner and authorship were not changed.'
                            : 'Public release withdrawn. The original record owner and authorship were not changed.';
                    }
                }
            }
        }
        $_SESSION[$error !== '' ? 'public_release_error' : 'public_release_success'] = $error !== '' ? $error : $success;
        header('Location: ' . SITE_URL . 'pages/admin/admin-public-releases.php' . (!empty($_GET['source']) ? '?source=' . rawurlencode((string) $_GET['source']) : ''));
        exit;
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search, 0, 120) : substr($search, 0, 120);
$sourceFilter = is_string($_GET['source'] ?? null) ? $_GET['source'] : 'all';
if (!in_array($sourceFilter, ['all', 'student_project', 'faculty_publication', 'faculty_research_record'], true)) $sourceFilter = 'all';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'pending';
if (!in_array($statusFilter, ['pending', 'published', 'withdrawn', 'all'], true)) $statusFilter = 'pending';
$statusCondition = match ($statusFilter) {
    'pending' => 'r.release_id IS NULL',
    'published' => "r.release_status = 'published'",
    'withdrawn' => "r.release_status = 'withdrawn'",
    default => '1 = 1',
};
$searchTerm = '%' . $search . '%';
$rows = [];

if ($sourceFilter === 'all' || $sourceFilter === 'student_project') {
    $rows = array_merge($rows, publicReleaseRows($conn,
        "SELECT 'student_project' AS source_type, rp.project_id AS source_id, rp.title,
                rp.status AS record_status, COALESCE(ra.authors, TRIM(CONCAT(owner.first_name, ' ', owner.last_name))) AS author_names,
                NULLIF(TRIM(CONCAT(owner.first_name, ' ', owner.last_name)), '') AS record_owner,
                rp.updated_at AS sort_at,
                EXISTS(SELECT 1 FROM research_submission_versions v JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id JOIN uploads up ON up.upload_id = vu.upload_id AND up.deleted_at IS NULL WHERE v.project_id = rp.project_id AND v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL AND v.status = 'approved') AS has_approved_manuscript,
                r.release_id, r.release_status, r.reviewed_by, r.reviewed_at,
                reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name,
                r.public_manuscript_version_id, r.publication_paper_released
           FROM research_projects rp
           JOIN users owner ON owner.user_id = rp.created_by
      LEFT JOIN (SELECT project_id, GROUP_CONCAT(author_name ORDER BY display_order, author_snapshot_id SEPARATOR ', ') AS authors FROM research_author_snapshots GROUP BY project_id) ra ON ra.project_id = rp.project_id
      LEFT JOIN public_research_releases r ON r.source_type = 'student_project' AND r.project_id = rp.project_id
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewed_by
          WHERE rp.status IN ('completed','archived') AND rp.deleted_at IS NULL AND {$statusCondition}
            AND (? = '' OR rp.title LIKE ? OR rp.abstract LIKE ? OR EXISTS (SELECT 1 FROM research_author_snapshots ras_search WHERE ras_search.project_id = rp.project_id AND ras_search.author_name LIKE ?))
          ORDER BY rp.updated_at DESC, rp.project_id DESC",
        'ssss', [$search, $searchTerm, $searchTerm, $searchTerm]));
}
if ($sourceFilter === 'all' || $sourceFilter === 'faculty_publication') {
    $rows = array_merge($rows, publicReleaseRows($conn,
        "SELECT 'faculty_publication' AS source_type, p.publication_id AS source_id, p.research_title AS title, p.authors_json,
                p.status AS record_status, COALESCE(NULLIF(p.researcher_name, ''), CONCAT('Faculty record #', p.publication_id)) AS author_names,
                CONCAT_WS(' ', author_user.first_name, author_user.last_name) AS record_owner,
                COALESCE(p.publication_date, p.updated_at) AS sort_at,
                (p.file_path IS NOT NULL AND p.file_path <> '') AS has_approved_manuscript,
                r.release_id, r.release_status, r.reviewed_by, r.reviewed_at,
                reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name,
                r.public_manuscript_version_id, r.publication_paper_released
           FROM publications p
      LEFT JOIN users author_user ON author_user.user_id = p.researcher_id
      LEFT JOIN public_research_releases r ON r.source_type = 'faculty_publication' AND r.publication_id = p.publication_id
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewed_by
          WHERE p.researcher_category = 'faculty' AND (p.status = 'published' OR p.archival_status = 'archived')
            AND p.deactivated_at IS NULL AND {$statusCondition}
            AND (? = '' OR p.research_title LIKE ? OR p.abstract_text LIKE ? OR p.researcher_name LIKE ? OR p.authors_json LIKE ?)
          ORDER BY p.publication_date DESC, p.publication_id DESC",
        'sssss', [$search, $searchTerm, $searchTerm, $searchTerm, $searchTerm]));
}
if ($sourceFilter === 'all' || $sourceFilter === 'faculty_research_record') {
    $rows = array_merge($rows, publicReleaseRows($conn,
        "SELECT 'faculty_research_record' AS source_type, fr.research_record_id AS source_id, fr.title,
                fr.status AS record_status,
                COALESCE(NULLIF(GROUP_CONCAT(fra.full_name ORDER BY fra.display_order SEPARATOR ', '), ''), 'No author names recorded') AS author_names,
                CONCAT_WS(' ', owner.first_name, owner.last_name) AS record_owner,
                COALESCE(fr.date_completed, fr.updated_at) AS sort_at,
                0 AS has_approved_manuscript,
                r.release_id, r.release_status, r.reviewed_by, r.reviewed_at,
                reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name,
                r.public_manuscript_version_id, r.publication_paper_released
           FROM faculty_research_records fr
           JOIN users owner ON owner.user_id = fr.owner_id
      LEFT JOIN faculty_research_record_authors fra ON fra.research_record_id = fr.research_record_id
      LEFT JOIN public_research_releases r ON r.source_type = 'faculty_research_record' AND r.faculty_research_record_id = fr.research_record_id
      LEFT JOIN users reviewer ON reviewer.user_id = r.reviewed_by
          WHERE fr.status = 'archived' AND fr.deleted_at IS NULL AND {$statusCondition}
          GROUP BY fr.research_record_id, fr.title, fr.status, fr.date_completed, fr.updated_at, owner.first_name, owner.last_name,
                   r.release_id, r.release_status, r.reviewed_by, r.reviewed_at,
                   reviewer.first_name, reviewer.last_name, r.public_manuscript_version_id, r.publication_paper_released
          HAVING (? = '' OR fr.title LIKE ? OR GROUP_CONCAT(fra.full_name ORDER BY fra.display_order SEPARATOR ', ') LIKE ?)
          ORDER BY fr.updated_at DESC, fr.research_record_id DESC",
        'sss', [$search, $searchTerm, $searchTerm]));
}

usort($rows, static function (array $left, array $right): int {
    return strtotime((string) $right['sort_at']) <=> strtotime((string) $left['sort_at']);
});
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 30;
$totalRows = count($rows);
$pageCount = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $pageCount);
$visibleRows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);
$versionOptions = [];
foreach ($visibleRows as $row) {
    if ($row['source_type'] !== 'student_project') continue;
    $projectId = (int) $row['source_id'];
    $versionStmt = $conn->prepare("SELECT v.version_id, v.version_number, v.submitted_at, GROUP_CONCAT(up.original_name ORDER BY up.upload_id SEPARATOR ', ') AS file_names FROM research_submission_versions v JOIN research_submission_version_uploads vu ON vu.version_id = v.version_id JOIN uploads up ON up.upload_id = vu.upload_id AND up.deleted_at IS NULL WHERE v.project_id = ? AND v.submission_type = 'final_manuscript' AND v.chapter_id IS NULL AND v.status = 'approved' GROUP BY v.version_id, v.version_number, v.submitted_at ORDER BY v.version_number DESC, v.version_id DESC");
    $versionStmt->bind_param('i', $projectId);
    $versionStmt->execute();
    $versionOptions[$projectId] = $versionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $versionStmt->close();
}

renderAdminShell($user, SITE_URL . 'pages/admin/admin-public-releases.php', 'Public Research Releases', 'Review which eligible research records and files can appear in the public archive.');
?>
<style>
  .public-release-page{max-width:1440px;margin:0 auto;padding:24px 28px 48px;color:#111827}
  .public-release-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:28px;align-items:end;padding:30px 34px;border:1px solid rgba(245,124,0,.2);border-radius:20px;background:linear-gradient(135deg,#fff 0%,#fff8f0 100%)}
  .public-release-kicker{margin:0 0 10px;color:#c65f00;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
  .public-release-hero h2{margin:0;font-size:clamp(28px,3vw,42px);line-height:1.08;letter-spacing:-.04em}
  .public-release-hero p:last-child{max-width:720px;margin:13px 0 0;color:#64748b;line-height:1.65}
  .public-release-note{max-width:280px;padding:16px 18px;border-radius:14px;background:#fff;border:1px solid #e5e7eb;color:#475569;font-size:13px;line-height:1.55}
  .public-release-note strong{display:block;color:#111827;margin-bottom:4px}
  .public-release-alert{margin:18px 0;padding:13px 16px;border-radius:12px;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
  .public-release-alert.error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
  .public-release-filters{display:grid;grid-template-columns:minmax(220px,1fr) minmax(180px,.55fr) minmax(180px,.55fr) auto;gap:12px;align-items:end;margin:20px 0;padding:18px;background:#fff;border:1px solid #e5e7eb;border-radius:16px}
  .public-release-field label{display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:700}
  .public-release-field input,.public-release-field select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #d7dce3;border-radius:10px;background:#fff;color:#111827;font:inherit}
  .public-release-field input:focus,.public-release-field select:focus{outline:2px solid #f57c00;outline-offset:2px}
  .public-release-list{display:grid;gap:14px}
  .public-release-card{padding:22px 24px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .public-release-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}
  .public-release-type{margin:0 0 6px;color:#b45309;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
  .public-release-card h3{margin:0;color:#111827;font-size:20px;line-height:1.3}
  .public-release-meta{margin:10px 0 0;color:#64748b;font-size:13px;line-height:1.6}
  .public-release-state{flex:0 0 auto;padding:6px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
  .public-release-state.published{background:#dcfce7;color:#166534}.public-release-state.withdrawn{background:#fff7ed;color:#9a3412}
  .public-release-controls{display:grid;grid-template-columns:minmax(180px,.6fr) minmax(260px,1.2fr) auto;gap:16px;align-items:end;margin-top:20px;padding-top:18px;border-top:1px solid #edf0f4}
  .public-release-controls .control{display:flex;flex-direction:column;justify-content:flex-end;align-self:stretch;min-height:100%}
  .public-release-controls .control label{display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:700}
  .public-release-controls select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #d7dce3;border-radius:10px;background:#fff;color:#111827;font:inherit}
  .public-release-controls select:focus{outline:2px solid #f57c00;outline-offset:2px}
  .public-release-checkbox{display:flex;align-items:flex-start;gap:9px;margin:0 0 8px;color:#334155;font-size:13px;line-height:1.5}
  .public-release-checkbox input{margin-top:3px;accent-color:#f57c00}
  .public-release-help{display:block;color:#64748b;font-size:11px;line-height:1.45}
  .public-release-actions{display:flex;justify-content:flex-end}
  .public-release-actions .btn{min-height:42px;white-space:nowrap}
  .public-release-empty{padding:36px 24px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b}
  .public-release-empty h3{margin:0 0 8px;color:#111827}
  .public-release-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;color:#64748b;font-size:13px}
  .public-release-pagination a{padding:8px 12px;border:1px solid #d7dce3;border-radius:9px;background:#fff;color:#334155;text-decoration:none}
  .public-release-pagination a:focus-visible,.public-release-actions button:focus-visible{outline:2px solid #f57c00;outline-offset:2px}
  @media(max-width:900px){.public-release-hero{grid-template-columns:1fr}.public-release-note{max-width:none}.public-release-filters{grid-template-columns:1fr 1fr}.public-release-controls{grid-template-columns:1fr 1fr}.public-release-actions{grid-column:1/-1}}
  @media(max-width:600px){.public-release-page{padding:16px 14px 32px}.public-release-hero{padding:23px 20px}.public-release-filters{grid-template-columns:1fr}.public-release-card{padding:18px}.public-release-head{flex-direction:column}.public-release-controls{grid-template-columns:1fr}.public-release-actions{grid-column:auto}.public-release-actions .btn{width:100%}.public-release-pagination{flex-wrap:wrap}}
</style>
<main class="public-release-page">
  <section class="public-release-hero" aria-labelledby="public-release-title">
    <div><p class="public-release-kicker">Public archive governance</p><h2 id="public-release-title">Review research before release.</h2><p>Choose which completed student work and eligible faculty records can be shown in the public archive. Releasing a record changes its public visibility only; it does not change its owner or authorship.</p></div>
    <aside class="public-release-note"><strong>Custodian record</strong>Your administrator account is recorded as the release reviewer. The source record continues to identify its original researcher and authors.</aside>
  </section>
  <?php if ($success !== ''): ?><div class="public-release-alert" role="status"><?php echo publicReleaseEscape($success); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="public-release-alert error" role="alert"><?php echo publicReleaseEscape($error); ?></div><?php endif; ?>
  <form class="public-release-filters" method="get" action="<?php echo publicReleaseEscape(SITE_URL . 'pages/admin/admin-public-releases.php'); ?>">
    <div class="public-release-field"><label for="release-search">Search records and authors</label><input id="release-search" name="search" type="search" maxlength="120" value="<?php echo publicReleaseEscape($search); ?>" placeholder="Title or author"></div>
    <div class="public-release-field"><label for="release-source">Record type</label><select id="release-source" name="source"><option value="all"<?php echo $sourceFilter === 'all' ? ' selected' : ''; ?>>All record types</option><option value="student_project"<?php echo $sourceFilter === 'student_project' ? ' selected' : ''; ?>>Student projects</option><option value="faculty_publication"<?php echo $sourceFilter === 'faculty_publication' ? ' selected' : ''; ?>>Faculty publications</option><option value="faculty_research_record"<?php echo $sourceFilter === 'faculty_research_record' ? ' selected' : ''; ?>>Faculty research records</option></select></div>
    <div class="public-release-field"><label for="release-status">Release status</label><select id="release-status" name="status"><option value="pending"<?php echo $statusFilter === 'pending' ? ' selected' : ''; ?>>Needs review</option><option value="published"<?php echo $statusFilter === 'published' ? ' selected' : ''; ?>>Published</option><option value="withdrawn"<?php echo $statusFilter === 'withdrawn' ? ' selected' : ''; ?>>Withdrawn</option><option value="all"<?php echo $statusFilter === 'all' ? ' selected' : ''; ?>>All</option></select></div>
    <button class="btn btn-primary" type="submit">Filter records</button>
  </form>
  <?php if (!$visibleRows): ?>
    <section class="public-release-empty"><h3>No matching records</h3><p>Try another filter. Existing records stay private until an administrator releases them.</p></section>
  <?php else: ?>
    <div class="public-release-list">
      <?php foreach ($visibleRows as $row): ?>
        <?php
          $recordId = (int) $row['source_id'];
          $sourceType = (string) $row['source_type'];
          $status = (string) ($row['release_status'] ?? 'pending');
          $currentVersion = (int) ($row['public_manuscript_version_id'] ?? 0);
          $currentPaper = (int) ($row['publication_paper_released'] ?? 0) === 1;
          $typeLabel = match ($sourceType) {
              'student_project' => 'Student research project',
              'faculty_publication' => 'Faculty publication',
              default => 'Independent faculty research record',
          };
          $authorText = trim((string) ($row['author_names'] ?? '')) ?: 'Authorship not recorded';
          if ($sourceType === 'faculty_publication') {
              $fullAuthors = publicationAuthors($row);
              if ($fullAuthors) $authorText = implode('; ', $fullAuthors);
          }
          $ownerText = trim((string) ($row['record_owner'] ?? ''));
        ?>
        <article class="public-release-card">
          <header class="public-release-head"><div><p class="public-release-type"><?php echo publicReleaseEscape($typeLabel); ?> · <?php echo publicReleaseEscape(ucfirst((string) $row['record_status'])); ?></p><h3><?php echo publicReleaseEscape($row['title']); ?></h3><p class="public-release-meta"><strong>Recorded author(s):</strong> <?php echo publicReleaseEscape($authorText); ?><?php if ($ownerText !== ''): ?><br><strong>Source record owner:</strong> <?php echo publicReleaseEscape($ownerText); ?><?php endif; ?><?php if (!empty($row['reviewed_at'])): ?><br><strong>Last release review:</strong> <?php echo publicReleaseEscape(trim(($row['reviewer_first_name'] ?? '') . ' ' . ($row['reviewer_last_name'] ?? '')) ?: 'Administrator'); ?> · <?php echo publicReleaseEscape($row['reviewed_at']); ?><?php endif; ?></p></div><span class="public-release-state <?php echo publicReleaseEscape($status); ?>"><?php echo publicReleaseEscape($status === 'pending' ? 'Needs review' : $status); ?></span></header>
          <form class="public-release-controls" method="post" action="<?php echo publicReleaseEscape(SITE_URL . 'pages/admin/admin-public-releases.php'); ?>">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save_public_release"><input type="hidden" name="source_type" value="<?php echo publicReleaseEscape($sourceType); ?>"><input type="hidden" name="source_id" value="<?php echo $recordId; ?>">
            <div class="control"><label for="release-status-<?php echo $sourceType . '-' . $recordId; ?>">Catalog visibility</label><select id="release-status-<?php echo $sourceType . '-' . $recordId; ?>" name="release_status"><option value="published"<?php echo $status === 'published' ? ' selected' : ''; ?>>Publish catalog record</option><option value="withdrawn"<?php echo $status !== 'published' ? ' selected' : ''; ?>>Keep hidden / withdraw</option></select><span class="public-release-help">Only eligible records appear in the public archive.</span></div>
            <div class="control">
              <?php if ($sourceType === 'student_project'): ?>
                <label for="release-file-<?php echo $recordId; ?>">Approved final manuscript</label>
                <select id="release-file-<?php echo $recordId; ?>" name="manuscript_version_id"><option value="">Do not release a manuscript file</option><?php foreach ($versionOptions[$recordId] ?? [] as $version): ?><option value="<?php echo (int) $version['version_id']; ?>"<?php echo $currentVersion === (int) $version['version_id'] ? ' selected' : ''; ?>>Version <?php echo (int) $version['version_number']; ?> · <?php echo publicReleaseEscape($version['file_names'] ?: 'Approved file'); ?> · <?php echo publicReleaseEscape($version['submitted_at']); ?></option><?php endforeach; ?></select>
                <span class="public-release-help"><?php echo !empty($row['has_approved_manuscript']) ? 'Select one approved version. Later submissions will not be released automatically.' : 'No approved final-manuscript file is currently available.'; ?></span>
              <?php elseif ($sourceType === 'faculty_publication'): ?>
                <label for="release-paper-<?php echo $recordId; ?>">Primary paper release</label><select id="release-paper-<?php echo $recordId; ?>" name="publication_paper_action"><option value="keep"<?php echo $currentPaper ? ' selected' : ''; ?>>Keep current released paper</option><option value="release_current"<?php echo empty($row['has_approved_manuscript']) ? ' disabled' : ''; ?>>Release the current primary paper</option><option value="withdraw"<?php echo !$currentPaper ? ' selected' : ''; ?>>Do not release / withdraw paper</option></select><span class="public-release-help">Primary paper only. Supporting evidence and references remain private. Selecting “release current” snapshots this file; later uploads stay private until reviewed.</span>
              <?php else: ?>
                <label>Files</label><span class="public-release-help">This record contains catalog metadata only. Authorship and author ordering come from the original faculty record.</span>
              <?php endif; ?>
            </div>
            <div class="public-release-actions"><button class="btn btn-primary" type="submit">Save release decision</button></div>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($pageCount > 1): ?>
      <?php $queryBase = ['search' => $search, 'source' => $sourceFilter, 'status' => $statusFilter]; ?>
      <nav class="public-release-pagination" aria-label="Release queue pages"><span>Page <?php echo $page; ?> of <?php echo $pageCount; ?> · <?php echo $totalRows; ?> records</span><div><?php if ($page > 1): ?><a href="?<?php echo publicReleaseEscape(http_build_query($queryBase + ['page' => $page - 1])); ?>">Previous</a><?php endif; ?> <?php if ($page < $pageCount): ?><a href="?<?php echo publicReleaseEscape(http_build_query($queryBase + ['page' => $page + 1])); ?>">Next</a><?php endif; ?></div></nav>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php renderAdminShellClose(); ?>
