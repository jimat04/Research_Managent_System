const { test, expect } = require('@playwright/test');
const { randomBytes } = require('node:crypto');
const { spawnSync } = require('node:child_process');

const baseUrl = process.env.RMS_E2E_BASE_URL || 'http://localhost/rms/';
const testPassword = process.env.RMS_E2E_TEST_PASSWORD;
const adminEmail = process.env.RMS_E2E_ADMIN_EMAIL;
const adminPassword = process.env.RMS_E2E_ADMIN_PASSWORD;
const staffEmail = process.env.RMS_E2E_STAFF_EMAIL;
const staffPassword = process.env.RMS_E2E_STAFF_PASSWORD;
const phpRoot = process.env.RMS_PROJECT_ROOT || 'C:/xampp/htdocs/rms';

let disposableEmail = '';
let disposablePassword = '';
let disposableUserId = 0;
let initialVerificationToken = '';
let workflowMarker = '';
let workflowProjectId = 0;
let workflowFacultyEmails = [];
let workflowFacultyIds = [];
let workflowStaffEmail = '';
let workflowStaffId = 0;
let workflowActivityIds = [];
let workflowPublicationIds = [];
let workflowCopyrightIds = [];
let workflowProjectTitle = '';
let workflowTitleStageTitle = '';
let workflowJournalTitle = '';
let workflowConferenceTitle = '';
let workflowStudentEmail = '';
let workflowStudentId = 0;

const pdfUpload = {
  name: 'rms-e2e-test.pdf',
  mimeType: 'application/pdf',
  buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'),
};

function phpJson(code, ...args) {
  const result = spawnSync('php', ['-r', code, '--', ...args], {
    cwd: phpRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.status !== 0) {
    throw new Error(`PHP test helper failed: ${String(result.stderr || '').slice(0, 400)}`);
  }
  return JSON.parse(result.stdout.trim());
}

function loadTestUser(email) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $email = $argv[1];
    $stmt = $conn->prepare('SELECT user_id, email, email_verified, email_verification_token, email_verification_expires FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc());
  `, email);
}

function deleteTestUser(email) {
  phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $email = $argv[1];
    $stmt = $conn->prepare('DELETE FROM activity_log WHERE user_id = (SELECT user_id FROM users WHERE email = ?)');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    echo json_encode(['deleted' => $stmt->affected_rows]);
  `, email);
}

function loadUser(email) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $email = $argv[1];
    $stmt = $conn->prepare('SELECT user_id, role, first_name, last_name, email, status FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, email);
}

function loadStaffAccess(email) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $email = $argv[1];
    $module = 'research_development';
    $stmt = $conn->prepare('SELECT sp.subpage_key, sp.access_level FROM staff_permissions sp JOIN users u ON u.user_id = sp.user_id WHERE u.email = ? AND sp.module_key = ?');
    $stmt->bind_param('ss', $email, $module);
    $stmt->execute();
    $out = [];
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) $out[$row['subpage_key']] = $row['access_level'];
    echo json_encode($out);
  `, email);
}

function loadProject(title) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $title = $argv[1];
    $stmt = $conn->prepare('SELECT project_id, title, status, created_by FROM research_projects WHERE title = ? LIMIT 1');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, title);
}

function loadSubmissionState(title) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $title = $argv[1];
    $stmt = $conn->prepare(
      'SELECT rp.project_id, rp.title, rp.status, rp.created_by,
              (SELECT COUNT(*) FROM research_submission_versions v WHERE v.project_id = rp.project_id AND v.submission_type = \\'title\\') AS title_versions,
              (SELECT v.status FROM research_submission_versions v WHERE v.project_id = rp.project_id AND v.submission_type = \\'title\\' ORDER BY v.version_number DESC LIMIT 1) AS latest_title_version_status,
              (SELECT COUNT(*) FROM research_submission_reviews r JOIN research_submission_versions v ON v.version_id = r.version_id WHERE v.project_id = rp.project_id AND v.submission_type = \\'title\\') AS title_reviews,
              (SELECT COUNT(*) FROM uploads u WHERE u.project_id = rp.project_id AND u.type = \\'proposal\\') AS title_uploads,
              (SELECT u.upload_id FROM uploads u WHERE u.project_id = rp.project_id AND u.type = \\'proposal\\' ORDER BY u.upload_id DESC LIMIT 1) AS latest_title_upload_id,
              (SELECT u.file_path FROM uploads u WHERE u.project_id = rp.project_id AND u.type = \\'proposal\\' ORDER BY u.upload_id DESC LIMIT 1) AS latest_title_file_path,
              (SELECT COUNT(*) FROM chapters ch WHERE ch.project_id = rp.project_id) AS chapter_count
         FROM research_projects rp WHERE rp.title = ? LIMIT 1'
    );
    $stmt->bind_param('s', $title);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, title);
}

function loadChapterSubmissionState(projectId, chapterNumber) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $projectId = (int) $argv[1];
    $chapterNumber = (int) $argv[2];
    $stmt = $conn->prepare(
      'SELECT ch.chapter_id, ch.status,
              (SELECT COUNT(*) FROM research_submission_versions v WHERE v.chapter_id = ch.chapter_id AND v.submission_type = \\'chapter\\') AS versions,
              (SELECT v.status FROM research_submission_versions v WHERE v.chapter_id = ch.chapter_id AND v.submission_type = \\'chapter\\' ORDER BY v.version_number DESC LIMIT 1) AS latest_version_status,
              (SELECT COUNT(*) FROM research_submission_reviews r JOIN research_submission_versions v ON v.version_id = r.version_id WHERE v.chapter_id = ch.chapter_id AND v.submission_type = \\'chapter\\') AS reviews,
              (SELECT r.comments FROM research_submission_reviews r JOIN research_submission_versions v ON v.version_id = r.version_id WHERE v.chapter_id = ch.chapter_id AND v.submission_type = \\'chapter\\' ORDER BY r.reviewed_at DESC, r.review_id DESC LIMIT 1) AS latest_comments,
              cc.background
         FROM chapters ch LEFT JOIN chapter_content cc ON cc.chapter_id = ch.chapter_id
        WHERE ch.project_id = ? AND ch.chapter_number = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $projectId, $chapterNumber);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, String(projectId), String(chapterNumber));
}

function loadActivity(title) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $title = $argv[1];
    $stmt = $conn->prepare('SELECT activity_id, activity_type, title, status FROM research_activities WHERE title = ? LIMIT 1');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    if ($row) {
      $id = (int) $row['activity_id'];
      $p = $conn->prepare('SELECT COUNT(*) AS c FROM activity_participants WHERE activity_id = ?');
      $p->bind_param('i', $id);
      $p->execute();
      $row['participant_count'] = (int) ($p->get_result()->fetch_assoc()['c'] ?? 0);
      $n = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE link LIKE CONCAT('%activity-detail.php?id=', ?, '%')");
      $n->bind_param('i', $id);
      $n->execute();
      $row['notification_count'] = (int) ($n->get_result()->fetch_assoc()['c'] ?? 0);
    }
    echo json_encode($row);
  `, title);
}

function loadPublication(title) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $title = $argv[1];
    $stmt = $conn->prepare('SELECT publication_id, research_title, research_project_id, publication_type, status, file_path FROM publications WHERE research_title = ? LIMIT 1');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, title);
}

function loadCopyright(title) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $title = $argv[1];
    $stmt = $conn->prepare('SELECT copyright_id, output_title, research_project_id, publication_id, output_type, status, file_path FROM copyright_applications WHERE output_title = ? LIMIT 1');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
  `, title);
}

function loadMilestones(projectId) {
  return phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $projectId = (int) $argv[1];
    $out = [];
    $stmt = $conn->prepare('SELECT report_id, report_type, status, summary FROM research_reports WHERE project_id = ? ORDER BY report_id');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) $out['report_' . $row['report_type']] = $row;
    $stmt->close();
    $stmt = $conn->prepare('SELECT document_id, document_type, status, remarks FROM research_documents WHERE project_id = ? ORDER BY document_id');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) $out['doc_' . $row['document_type']] = $row;
    echo json_encode($out);
  `, String(projectId));
}

function cleanupWorkflowDataLegacy(marker, testEmails) {
  phpJson(`
    ini_set('session.save_path', sys_get_temp_dir());
    require ${JSON.stringify(`${phpRoot.replaceAll('\\', '/')}/includes/config.php`)};
    $marker = $argv[1];
    $emails = array_slice($argv, 2);
    $userIds = [];
    $userStmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    foreach ($emails as $email) {
      $userStmt->bind_param('s', $email);
      $userStmt->execute();
      $row = $userStmt->get_result()->fetch_assoc();
      if ($row) $userIds[] = (int) $row['user_id'];
    }
    $userStmt->close();

    $projectIds = [];
    $projectStmt = $conn->prepare('SELECT project_id FROM research_projects WHERE title LIKE ?');
    $projectLike = $marker . '%';
    $projectStmt->bind_param('s', $projectLike);
    $projectStmt->execute();
    $projectRows = $projectStmt->get_result();
    while ($row = $projectRows->fetch_assoc()) $projectIds[] = (int) $row['project_id'];
    $projectStmt->close();

    $filePaths = [];
    if ($projectIds) {
      $projectIdList = implode(',', array_map('intval', $projectIds));
      $uploadResult = $conn->query("SELECT file_path FROM uploads WHERE project_id IN ($projectIdList) OR research_id IN ($projectIdList)");
      if ($uploadResult) while ($row = $uploadResult->fetch_assoc()) $filePaths[] = (string) $row['file_path'];
      $copyrightResult = $conn->query("SELECT file_path FROM copyright_applications WHERE research_project_id IN ($projectIdList)");
      if ($copyrightResult) while ($row = $copyrightResult->fetch_assoc()) $filePaths[] = (string) $row['file_path'];
      $publicationResult = $conn->query("SELECT file_path FROM publications WHERE research_project_id IN ($projectIdList)");
      if ($publicationResult) while ($row = $publicationResult->fetch_assoc()) $filePaths[] = (string) $row['file_path'];
      $conn->query("DELETE FROM copyright_applications WHERE research_project_id IN ($projectIdList)");
      $conn->query("DELETE FROM publications WHERE research_project_id IN ($projectIdList)");
      $conn->query("DELETE FROM project_reviews WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM research_reports WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM research_documents WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM research_author_snapshots WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM research_publication_tracking WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM defense_schedule WHERE research_id IN ($projectIdList)");
      $conn->query("DELETE FROM chapters WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM project_members WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM project_advisers_history WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM project_advisers WHERE project_id IN ($projectIdList)");
      $conn->query("DELETE FROM uploads WHERE project_id IN ($projectIdList) OR research_id IN ($projectIdList)");
      $conn->query("DELETE FROM research_projects WHERE project_id IN ($projectIdList)");
    }

    $activityIds = [];
    $activityStmt = $conn->prepare('SELECT activity_id FROM research_activities WHERE title LIKE ?');
    $activityStmt->bind_param('s', $projectLike);
    $activityStmt->execute();
    $activityRows = $activityStmt->get_result();
    while ($row = $activityRows->fetch_assoc()) $activityIds[] = (int) $row['activity_id'];
    $activityStmt->close();
    if ($activityIds) {
      $activityIdList = implode(',', array_map('intval', $activityIds));
      $conn->query("DELETE FROM activity_participants WHERE activity_id IN ($activityIdList)");
      $conn->query("DELETE FROM research_activities WHERE activity_id IN ($activityIdList)");
    }

    $publicationIds = [];
    $publicationStmt = $conn->prepare('SELECT publication_id, file_path FROM publications WHERE research_title LIKE ?');
    $publicationStmt->bind_param('s', $projectLike);
    $publicationStmt->execute();
    $publicationRows = $publicationStmt->get_result();
    while ($row = $publicationRows->fetch_assoc()) {
      $publicationIds[] = (int) $row['publication_id'];
      if (!empty($row['file_path'])) $filePaths[] = (string) $row['file_path'];
    }
    $publicationStmt->close();
    if ($publicationIds) {
      $publicationIdList = implode(',', array_map('intval', $publicationIds));
      $conn->query("DELETE FROM publications WHERE publication_id IN ($publicationIdList)");
    }

    $copyrightIds = [];
    $copyrightStmt = $conn->prepare('SELECT copyright_id, file_path FROM copyright_applications WHERE output_title LIKE ?');
    $copyrightStmt->bind_param('s', $projectLike);
    $copyrightStmt->execute();
    $copyrightRows = $copyrightStmt->get_result();
    while ($row = $copyrightRows->fetch_assoc()) {
      $copyrightIds[] = (int) $row['copyright_id'];
      if (!empty($row['file_path'])) $filePaths[] = (string) $row['file_path'];
    }
    $copyrightStmt->close();
    if ($copyrightIds) {
      $copyrightIdList = implode(',', array_map('intval', $copyrightIds));
      $conn->query("DELETE FROM copyright_applications WHERE copyright_id IN ($copyrightIdList)");
    }

    $markerLike = '%' . $marker . '%';
    $stmt = $conn->prepare('DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?');
    $stmt->bind_param('ss', $markerLike, $markerLike);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM activity_log WHERE action LIKE ?');
    $stmt->bind_param('s', $markerLike);
    $stmt->execute();
    $stmt->close();

    if ($userIds) {
      $userIdList = implode(',', array_map('intval', $userIds));
      $conn->query("DELETE FROM staff_permissions WHERE user_id IN ($userIdList)");
      $conn->query("DELETE FROM notifications WHERE user_id IN ($userIdList)");
      $conn->query("DELETE FROM activity_log WHERE user_id IN ($userIdList)");
      $conn->query("DELETE FROM users WHERE user_id IN ($userIdList) AND role <> 'admin'");
    }

    $projectRoot = ${JSON.stringify(phpRoot.replaceAll('\\', '/'))};
    $uploadsRoot = realpath($projectRoot . '/uploads');
    foreach (array_unique($filePaths) as $relativePath) {
      $resolved = realpath($projectRoot . '/' . ltrim($relativePath, '/'));
      if ($uploadsRoot && $resolved && str_starts_with($resolved, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) && is_file($resolved)) @unlink($resolved);
    }
    echo json_encode(['projects' => count($projectIds), 'activities' => count($activityIds), 'publications' => count($publicationIds), 'copyrights' => count($copyrightIds), 'users' => count($userIds)]);
  `, marker, ...testEmails);
}

function cleanupWorkflowData(marker, testEmails) {
  const cleanupScript = `${phpRoot.replaceAll('\\', '/')}/tests/e2e-browser/db-cleanup.php`;
  const result = spawnSync('php', [cleanupScript, marker, ...testEmails], {
    cwd: phpRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.status !== 0) {
    throw new Error(`PHP cleanup failed: ${String(result.stderr || '').slice(0, 400)}`);
  }
  return JSON.parse(result.stdout.trim());
}

async function expectNoServerErrors(page) {
  await expect(page.locator('body')).not.toContainText(/Fatal error|Warning:|mysqli_sql_exception|Parse error/i);
}

async function login(page, email, password, role) {
  await page.goto('public/logout.php');
  await page.goto('public/login.php');
  await page.locator('input[name="email"]').first().fill(email);
  await page.locator('input[name="password"]').first().fill(password);
  await page.locator('input[name="role"]').first().evaluate((element, value) => {
    element.value = value;
  }, role);
  await page.locator('form').filter({ has: page.locator('input[name="action"][value="login"]') }).getByRole('button', { name: /sign in|login/i }).click();
}

async function createAdminManagedUser(page, user) {
  await page.goto('pages/admin/admin-users.php');
  await page.getByRole('button', { name: /add faculty or staff/i }).first().click();
  const modal = page.locator('#createModal');
  await expect(modal).toHaveClass(/active/);
  await modal.locator('select[name="role"]').selectOption(user.role);
  await modal.locator('input[name="first_name"]').fill(user.firstName);
  await modal.locator('input[name="last_name"]').fill(user.lastName);
  await modal.locator('input[name="email"]').fill(user.email);
  await modal.locator('input[name="password"]').fill(user.password);
  await modal.locator('input[name="student_id"]').fill(user.employeeId || '');
  await modal.locator('input[name="contact"]').fill('09000000000');
  if (user.role === 'faculty') {
    await modal.locator('input[name="department"]').first().fill('RMS E2E Department');
    await modal.locator('select[name="academic_rank"]').selectOption('Instructor');
    if (user.reviewer) await modal.locator('#createReviewer').check();
  } else {
    await modal.locator('select[name="office"]').selectOption('Office of Research Services');
    await modal.locator('input[name="department"]').last().fill('RMS E2E Office');
  }
  await modal.getByRole('button', { name: /^create user$/i }).click();
  await expect(page.locator('body')).toContainText(/created successfully/i);
  const created = loadUser(user.email);
  expect(created.email).toBe(user.email);
  expect(created.role).toBe(user.role);
  return Number(created.user_id);
}

async function setStaffPermissions(page, email, permissions) {
  await page.goto(`pages/admin/admin-users.php?search=${encodeURIComponent(email)}`);
  const row = page.locator('tr').filter({ hasText: email }).first();
  await expect(row).toContainText(email);
  await row.getByRole('button', { name: /^permissions$/i }).click();
  const modal = page.locator('#staffPermissionsModal');
  await expect(modal).toHaveClass(/active/);
  for (const [moduleKey, pages] of Object.entries(permissions)) {
    if (Object.keys(pages).length > 0) {
      await modal.locator(`input[name="module_access[${moduleKey}][access]"][value="view"]`).check();
    }
    for (const [pageKey, level] of Object.entries(pages)) {
      const selector = `input[name="module_access[${moduleKey}][pages][${pageKey}]"][value="${level}"]`;
      await modal.locator(selector).check();
    }
  }
  await modal.getByRole('button', { name: /save permissions/i }).click();
  await expect(page.locator('body')).toContainText(/permissions updated/i);
  const savedAccess = loadStaffAccess(email);
  for (const [moduleKey, pages] of Object.entries(permissions)) {
    if (moduleKey !== 'research_development') continue;
    for (const [pageKey, level] of Object.entries(pages)) expect(savedAccess[pageKey]).toBe(level);
  }
}

async function expectDbProject(title, status, projectId = 0) {
  await expect.poll(() => loadProject(title).status).toBe(status);
  const project = loadProject(title);
  expect(Number(project.project_id)).toBe(projectId || Number(project.project_id));
  return project;
}

async function createActivity(page, type, capacityPage, title, recipientScope, recipientIds = []) {
  await page.goto(`pages/shared/activity-form.php?capacity_page=${capacityPage}&type=${type}`);
  await page.locator('#activity-type').selectOption(type);
  await page.locator('#activity-date').fill('2030-01-15');
  await page.locator('#activity-title').fill(title);
  await page.locator('#activity-time').fill('09:30');
  await page.locator('#activity-venue').fill('RMS E2E Test Venue');
  await page.locator('#activity-organizer').fill('RMS E2E Test Office');
  await page.locator('#activity-description').fill(`Created for ${title}`);
  await page.locator(`input[name="recipient_scope"][value="${recipientScope}"]`).check();
  for (const recipientId of recipientIds) {
    await page.locator(`input[name="recipient_user_ids[]"][value="${recipientId}"]`).check();
  }
  await page.getByRole('button', { name: /create activity/i }).click();
  await expect(page.locator('body')).toContainText(title);
  const activity = loadActivity(title);
  expect(activity.activity_type).toBe(type);
  expect(Number(activity.participant_count)).toBeGreaterThan(0);
  return Number(activity.activity_id);
}

async function createPublication(page, type, title, projectId) {
  await page.goto('pages/shared/publication-form.php');
  await page.locator('input[name="research_title"]').fill(title);
  await page.locator('select[name="research_project_id"]').selectOption(String(projectId));
  await page.locator('select[name="publication_type"]').selectOption(type);
  await page.locator('input[name="journal_publisher"]').fill('RMS E2E Publisher');
  await page.locator('input[name="publication_date"]').fill('2030-02-01');
  await page.locator('input[name="doi_identifier"]').fill(`10.0000/${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);
  await page.locator('input[name="research_file"]').setInputFiles(pdfUpload);
  await page.getByRole('button', { name: /submit publication/i }).click();
  await expect(page.locator('body')).toContainText(title);
  const publication = loadPublication(title);
  expect(Number(publication.research_project_id)).toBe(projectId);
  expect(publication.publication_type).toBe(type);
  return Number(publication.publication_id);
}

async function createCopyright(page, type, title, projectId, publicationId) {
  await page.goto(`pages/shared/copyright-form.php?output_type=${type}`);
  await page.locator('input[name="output_title"]').fill(title);
  await page.locator('select[name="output_type"]').selectOption(type);
  await page.locator('select[name="research_project_id"]').selectOption(String(projectId));
  if (publicationId) await page.locator('select[name="publication_id"]').selectOption(String(publicationId));
  if (type === 'patent' || type === 'utility_model') {
    await page.locator('input[name="application_number"]').fill(`RMS-E2E-APP-${Date.now()}`);
    await page.locator('textarea[name="inventors"]').fill('RMS E2E Inventor');
    await page.locator('input[name="filing_date"]').fill('2030-02-02');
  }
  if (type === 'innovation' || type === 'technology_transfer') {
    await page.locator('textarea[name="transfer_activities"]').fill('RMS E2E controlled technology transfer activity.');
  }
  await page.locator('input[name="supporting_file"]').setInputFiles(pdfUpload);
  await page.getByRole('button', { name: /submit application/i }).click();
  await expect(page.locator('body')).toContainText(title);
  const copyright = loadCopyright(title);
  expect(Number(copyright.research_project_id)).toBe(projectId);
  expect(copyright.output_type).toBe(type);
  return Number(copyright.copyright_id);
}

test.describe.serial('RMS browser end-to-end smoke and role checks', () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    test.skip(!testPassword, 'RMS_E2E_TEST_PASSWORD is required for disposable-account browser tests');
    const context = await browser.newContext();
    const page = await context.newPage();
    disposableEmail = `rms-e2e-${Date.now()}-${randomBytes(3).toString('hex')}@example.invalid`;
    disposablePassword = testPassword;

    await page.goto('public/login.php');
    await page.getByRole('link', { name: /create one/i }).click();
    await page.locator('input[name="first_name"]').fill('RMS');
    await page.locator('input[name="last_name"]').fill('Browser E2E');
    await page.locator('input[name="email"]').last().fill(disposableEmail);
    await page.locator('input[name="password"]').last().fill(disposablePassword);
    await page.locator('input[name="password_confirm"]').fill(disposablePassword);
    await page.locator('input[name="student_id"]').fill(`E2E-${Date.now()}`);
    await page.locator('input[name="contact"]').fill('09000000000');
    await page.locator('select[name="role"]').selectOption('student');
    await page.locator('input[name="program"]').fill('BSIT');
    await page.locator('select[name="year_level"]').selectOption('1st');
    await page.locator('form').filter({ has: page.locator('input[name="action"][value="register"]') }).getByRole('button', { name: /create account/i }).click();
    await expect(page.locator('body')).toContainText(/Registration successful/i);

    const user = loadTestUser(disposableEmail);
    disposableUserId = Number(user.user_id);
    initialVerificationToken = String(user.email_verification_token || '');
    await expect.poll(() => initialVerificationToken.length).toBe(64);

    await page.goto(`public/verify-email.php?token=${encodeURIComponent(initialVerificationToken)}`);
    await expect(page.locator('body')).toContainText(/Email Verified|Email verified successfully/i);
    await context.close();
  });

  test.afterAll(async () => {
    if (disposableEmail) {
      deleteTestUser(disposableEmail);
    }
  });

  test('registration stores a disposable account and verification succeeds', async ({ page }) => {
    await page.goto('public/login.php');
    await expect(page).toHaveTitle(/Login/i);
    const user = loadTestUser(disposableEmail);
    expect(Number(user.user_id)).toBe(disposableUserId);
    expect(Number(user.email_verified)).toBe(1);
    expect(user.email_verification_token).toBeNull();
  });

  test('milestone submission page stays unavailable to students', async ({ page }) => {
    await login(page, disposableEmail, disposablePassword, 'student');
    await page.goto('pages/student/submit-milestone.php?project_id=1');
    await expect(page).toHaveURL(/pages\/researcher\/my-research\.php$/);
    await expect(page.locator('form input[name="milestone_file"]')).toHaveCount(0);
  });

  test('reused and invalid verification tokens are rejected', async ({ page }) => {
    await page.goto(`public/verify-email.php?token=${encodeURIComponent(initialVerificationToken)}`);
    await expect(page.locator('body')).toContainText(/Invalid verification token|already verified|Verification Failed/i);
    await page.goto('public/verify-email.php?token=invalid-browser-test-token');
    await expect(page.locator('body')).toContainText(/Invalid verification token|Verification Failed/i);
    await page.goto('public/verify-email.php');
    await expect(page.locator('body')).toContainText(/No verification token provided|Verification Failed/i);
  });

  test('duplicate registration is rejected in the browser', async ({ page }) => {
    await page.goto('public/login.php');
    await page.getByRole('link', { name: /create one/i }).click();
    await page.locator('input[name="first_name"]').fill('RMS');
    await page.locator('input[name="last_name"]').fill('Duplicate');
    await page.locator('input[name="email"]').last().fill(disposableEmail);
    await page.locator('input[name="password"]').last().fill(disposablePassword);
    await page.locator('input[name="password_confirm"]').fill(disposablePassword);
    await page.locator('select[name="role"]').selectOption('student');
    await page.locator('input[name="program"]').fill('BSIT');
    await page.locator('select[name="year_level"]').selectOption('1st');
    await page.locator('form').filter({ has: page.locator('input[name="action"][value="register"]') }).getByRole('button', { name: /create account/i }).click();
    await expect(page.locator('body')).toContainText(/Email already registered/i);
  });

  test('successful login, logout, and protected-route redirect work', async ({ page }) => {
    await login(page, disposableEmail, disposablePassword, 'student');
    await expect(page).toHaveURL(/student-dashboard\.php/);
    await expectNoServerErrors(page);
    await page.goto('public/logout.php');
    await page.goto('pages/student/student-dashboard.php');
    await expect(page).toHaveURL(/public\/login\.php/);
  });

  test('invalid credentials are rejected', async ({ page }) => {
    await page.goto('public/login.php');
    await page.locator('input[name="email"]').first().fill(disposableEmail);
    await page.locator('input[name="password"]').first().fill('wrong-browser-password');
    await page.locator('input[name="role"]').first().evaluate((element) => {
      element.value = 'student';
    });
    await page.locator('form').filter({ has: page.locator('input[name="action"][value="login"]') }).getByRole('button', { name: /sign in|login/i }).click();
    await expect(page.locator('body')).toContainText(/Invalid email, password, or role/i);
  });

  test('student cannot access staff or admin pages', async ({ page }) => {
    await login(page, disposableEmail, disposablePassword, 'student');
    await page.goto('pages/admin/admin-dashboard.php');
    await expect(page).toHaveURL(/public\/login\.php|403\.php/);
    await page.goto('pages/staff/staff-dashboard.php');
    await expect(page).toHaveURL(/public\/login\.php|403\.php/);
  });

  test('configured staff account can load its dashboard without server errors', async ({ page }) => {
    test.skip(!staffEmail || !staffPassword, 'RMS_E2E_STAFF_EMAIL and RMS_E2E_STAFF_PASSWORD are required');
    await login(page, staffEmail, staffPassword, 'research_staff');
    await expect(page).toHaveURL(/staff-dashboard\.php/);
    await expectNoServerErrors(page);
  });

  test('configured admin account loads the dashboard and all 15 research destinations', async ({ page }) => {
    test.skip(!adminEmail || !adminPassword, 'RMS_E2E_ADMIN_EMAIL and RMS_E2E_ADMIN_PASSWORD are required');
    await login(page, adminEmail, adminPassword, 'admin');
    await expect(page).toHaveURL(/admin-dashboard\.php/);
    await expectNoServerErrors(page);

    const mainModules = [
      'Research Capacity Building',
      'Research Development',
      'Research Implementation & Outputs',
      'Publication & Dissemination',
      'Intellectual Property & Utilization',
    ];
    for (const moduleLabel of mainModules) {
      await expect(page.locator('.admin-nav-group-summary', { hasText: moduleLabel })).toHaveCount(1);
    }
    await expect(page.locator('.admin-nav-group')).toHaveCount(7);

    const destinations = [
      ['seminars', 'Seminars', 'pages/shared/activities.php'], ['trainings', 'Trainings', 'pages/shared/activities.php'], ['workshops', 'Workshops', 'pages/shared/activities.php'],
      ['research_proposals', 'Research Proposals', 'pages/shared/research-submission-review.php'], ['research_review', 'Research Review', 'pages/shared/research-submission-review.php'], ['research_approval', 'Research Approval', 'pages/shared/research-submission-review.php'],
      ['ongoing_research', 'Ongoing Research', 'pages/shared/implementation-outputs.php?view=ongoing&module_page=ongoing_research'], ['completed_research', 'Completed Research', 'pages/shared/implementation-outputs.php?view=completed&module_page=completed_research'], ['research_outputs', 'Research Outputs', 'pages/shared/implementation-outputs.php?view=outputs&module_page=research_outputs'],
      ['journal_publications', 'Journal Publications', 'pages/shared/publications.php'], ['conference_presentations', 'Conference Presentations', 'pages/shared/publications.php'], ['research_repository', 'Research Repository', 'pages/shared/publications.php'],
      ['copyrights', 'Copyrights', 'pages/shared/copyrights.php'], ['patents_utility_models', 'Patents / Utility Models', 'pages/shared/copyrights.php'], ['innovation_technology_transfer', 'Innovation & Technology Transfer', 'pages/shared/copyrights.php'],
    ];
    for (const [module, label, targetPath] of destinations) {
      await page.goto(`pages/admin/admin-research-module.php?module=${module}`);
      const resolvedUrl = new URL(page.url());
      const [targetPathname, targetQuery = ''] = targetPath.split('?');
      expect(resolvedUrl.pathname).toContain(targetPathname);
      if (targetQuery) {
        expect(resolvedUrl.search).toBe(`?${targetQuery}`);
      }
      expect(resolvedUrl.searchParams.get('module_page')).toBe(module);
      await expect(page.locator('body')).toContainText(new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));
      await expect(page.locator('.admin-nav-subitem.active[aria-current="page"]')).toHaveCount(1);
      await expect(page.locator('.admin-nav-subitem.active[aria-current="page"]')).toContainText(new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));
      await expect(page.locator('.admin-nav-group[open]')).toHaveCount(1);
      await expectNoServerErrors(page);
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('pages/admin/admin-dashboard.php');
    await expect(page.locator('body')).toBeVisible();
    await page.getByRole('button', { name: /open administrator navigation/i }).click();
    await expect(page.locator('.admin-sidebar')).toHaveClass(/is-open/);
    await expect(page.getByRole('button', { name: /open administrator navigation/i })).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('.admin-sidebar')).not.toHaveClass(/is-open/);
    await expectNoServerErrors(page);
  });
});

test.describe.serial('RMS connected business workflows and persistence', () => {
  test.beforeAll(async ({ browser }) => {
    test.skip(!testPassword || !adminEmail || !adminPassword, 'Admin and disposable test credentials are required');
    workflowMarker = `RMS E2E ${Date.now()} ${randomBytes(3).toString('hex')}`;
    workflowProjectTitle = `${workflowMarker} Research Proposal`;
    workflowTitleStageTitle = `${workflowMarker} Title Stage`;
    workflowJournalTitle = `${workflowMarker} Journal Record`;
    workflowConferenceTitle = `${workflowMarker} Conference Record`;
    workflowFacultyEmails = [
      `rms-e2e-reviewer-a-${Date.now()}@example.invalid`,
      `rms-e2e-reviewer-b-${Date.now()}@example.invalid`,
    ];
    workflowStaffEmail = `rms-e2e-workflow-staff-${Date.now()}@example.invalid`;
    workflowStudentEmail = `rms-e2e-workflow-student-${Date.now()}@example.invalid`;

    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await page.goto('public/login.php');
      await page.getByRole('link', { name: /create one/i }).click();
      await page.locator('input[name="first_name"]').fill('RMS E2E');
      await page.locator('input[name="last_name"]').fill('Workflow Student');
      await page.locator('input[name="email"]').last().fill(workflowStudentEmail);
      await page.locator('input[name="password"]').last().fill(testPassword);
      await page.locator('input[name="password_confirm"]').fill(testPassword);
      await page.locator('input[name="student_id"]').fill(`RMS-E2E-ST-${Date.now()}`);
      await page.locator('input[name="contact"]').fill('09000000000');
      await page.locator('select[name="role"]').selectOption('student');
      await page.locator('input[name="program"]').fill('BSIT');
      await page.locator('select[name="year_level"]').selectOption('1st');
      await page.locator('form').filter({ has: page.locator('input[name="action"][value="register"]') }).getByRole('button', { name: /create account/i }).click();
      await expect(page.locator('body')).toContainText(/registration successful/i);
      const workflowStudent = loadTestUser(workflowStudentEmail);
      workflowStudentId = Number(workflowStudent.user_id);
      const workflowToken = String(workflowStudent.email_verification_token || '');
      expect(workflowToken).toHaveLength(64);
      await page.goto(`public/verify-email.php?token=${encodeURIComponent(workflowToken)}`);
      await expect(page.locator('body')).toContainText(/email verified|successfully/i);

      await login(page, adminEmail, adminPassword, 'admin');
      await expect(page).toHaveURL(/admin-dashboard\.php/);
      for (let index = 0; index < workflowFacultyEmails.length; index += 1) {
        workflowFacultyIds.push(await createAdminManagedUser(page, {
          role: 'faculty',
          firstName: 'RMS E2E',
          lastName: `Reviewer ${String.fromCharCode(65 + index)}`,
          email: workflowFacultyEmails[index],
          password: testPassword,
          employeeId: `RMS-E2E-F-${index + 1}`,
          reviewer: true,
        }));
      }
      workflowStaffId = await createAdminManagedUser(page, {
        role: 'research_staff',
        firstName: 'RMS E2E',
        lastName: 'Workflow Staff',
        email: workflowStaffEmail,
        password: testPassword,
        employeeId: 'RMS-E2E-S-1',
      });
      await setStaffPermissions(page, workflowStaffEmail, {
        research_capacity_building: { seminars: 'manage', trainings: 'manage', workshops: 'manage' },
        research_development: { research_proposals: 'manage', research_review: 'manage', research_approval: 'manage' },
        research_implementation_outputs: { ongoing_research: 'manage', research_outputs: 'manage' },
        publication_dissemination: { journal_publications: 'manage', conference_presentations: 'manage', research_repository: 'view' },
        intellectual_property_utilization: { copyrights: 'manage', patents_utility_models: 'manage', innovation_technology_transfer: 'manage' },
      });
    } finally {
      await context.close();
    }
  });

  test.afterAll(async () => {
    if (workflowMarker) {
      cleanupWorkflowData(workflowMarker, [...workflowFacultyEmails, workflowStaffEmail, workflowStudentEmail]);
    }
  });

  test('title drafts remain editable, submitted versions lock, and title approval unlocks Chapter 1', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, workflowStudentEmail, testPassword, 'student');
    await page.goto('pages/researcher/submit-research.php');
    await page.locator('input[name="title"]').fill(workflowTitleStageTitle);
    await page.locator('select[name="category_id"]').selectOption({ index: 1 });
    await page.locator('select[name="ay_id"]').selectOption({ index: 1 });
    await page.locator('input[name="research_area"]').fill('RMS E2E title-stage workflow');
    await page.locator('textarea[name="abstract"]').fill('A test title draft used to verify the shared submission and approval workflow.');
    await page.locator('input[name="proposal_file"]').setInputFiles(pdfUpload);
    await page.getByRole('button', { name: /save as draft/i }).click();
    await expect(page.locator('body')).toContainText(/saved as draft/i);

    let state = loadSubmissionState(workflowTitleStageTitle);
    expect(state.status).toBe('draft');
    expect(Number(state.title_versions)).toBe(0);
    workflowProjectId = Number(state.project_id);

    await page.goto(`pages/researcher/edit-research.php?id=${workflowProjectId}`);
    await page.locator('textarea[name="abstract"]').fill('Edited while still a draft; this change is allowed.');
    await page.getByRole('button', { name: /save changes/i }).click();
    await expect(page.locator('body')).toContainText(/updated successfully|changes saved/i);
    await page.goto(`pages/researcher/edit-research.php?id=${workflowProjectId}`);
    const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
    const categoryId = await page.locator('select[name="category_id"]').inputValue();
    const academicYearId = await page.locator('select[name="ay_id"]').inputValue();
    await page.getByRole('button', { name: /resubmit for review/i }).click();
    await expect(page.locator('body')).toContainText(/submitted/i);

    state = loadSubmissionState(workflowTitleStageTitle);
    expect(state.status).toBe('submitted');
    expect(Number(state.title_versions)).toBe(1);
    expect(state.latest_title_version_status).toBe('submitted');
    const directFileResponse = await page.request.get(`${baseUrl}${state.latest_title_file_path}`);
    expect(directFileResponse.status()).toBe(403);

    const replacementResponse = await page.request.post(`${baseUrl}public/api/upload.php`, {
      multipart: {
        csrf_token: csrfToken,
        folder_target: 'proposals',
        project_id: String(workflowProjectId),
        proposal_file: pdfUpload,
      },
    });
    expect(replacementResponse.status()).toBe(400);
    expect((await replacementResponse.json()).error).toMatch(/attachments are locked/i);
    const deletionResponse = await page.request.post(`${baseUrl}pages/researcher/edit-research.php?id=${workflowProjectId}`, {
      form: {
        csrf_token: csrfToken,
        action: 'remove_attachment',
        upload_id: String(state.latest_title_upload_id),
      },
    });
    expect(deletionResponse.status()).toBe(200);
    expect(Number(loadSubmissionState(workflowTitleStageTitle).title_uploads)).toBe(1);

    await page.request.post(`${baseUrl}pages/researcher/edit-research.php?id=${workflowProjectId}`, {
      form: {
        csrf_token: csrfToken,
        action: 'save',
        title: workflowTitleStageTitle,
        category_id: categoryId,
        ay_id: academicYearId,
        research_area: 'Tampered after submission',
        abstract: 'This direct POST must not change the official version.',
      },
    });
    state = loadSubmissionState(workflowTitleStageTitle);
    expect(state.status).toBe('submitted');
    expect(Number(state.title_versions)).toBe(1);
    expect(state.title).toBe(workflowTitleStageTitle);

    await page.goto(`pages/researcher/submit-chapter.php?project_id=${workflowProjectId}&chapter=1`);
    await expect(page.locator('body')).toContainText(/this chapter is locked/i);
    expect(Number(loadSubmissionState(workflowTitleStageTitle).chapter_count)).toBe(0);
    const blockedChapterCsrf = await page.locator('input[name="csrf_token"]').first().inputValue();
    await page.request.post(`${baseUrl}pages/researcher/submit-chapter.php`, {
      form: {
        csrf_token: blockedChapterCsrf,
        project_id: String(workflowProjectId),
        chapter: '1',
        submit_review: '1',
        background: 'Attempt to bypass the title approval requirement.',
      },
    });
    expect(Number(loadSubmissionState(workflowTitleStageTitle).chapter_count)).toBe(0);

    await login(page, adminEmail, adminPassword, 'admin');
    await page.goto('pages/shared/research-submission-review.php');
    const titleCard = page.locator('.submission-review-card').filter({ hasText: workflowTitleStageTitle });
    await expect(titleCard).toContainText('Research Title');
    const secureFileHref = await titleCard.getByRole('link', { name: /rms-e2e-test\.pdf/i }).getAttribute('href');
    expect(secureFileHref).toBeTruthy();
    const secureFileResponse = await page.request.get(new URL(secureFileHref, page.url()).toString());
    expect(secureFileResponse.status()).toBe(200);
    await titleCard.getByRole('button', { name: /^approve$/i }).click();
    await expect(page.locator('body')).toContainText(/research title approved/i);

    state = loadSubmissionState(workflowTitleStageTitle);
    expect(state.status).toBe('title_approved');
    expect(state.latest_title_version_status).toBe('approved');
    await login(page, workflowStudentEmail, testPassword, 'student');
    await page.goto(`pages/researcher/submit-chapter.php?project_id=${workflowProjectId}`);
    await expect(page.locator('body')).toContainText(/choose a chapter/i);
    await page.locator('a.picker-item').filter({ hasText: /chapter 1:/i }).click();
    await page.locator('textarea[name="background"]').fill('Draft Chapter 1 background content for workflow verification.');
    await page.getByRole('button', { name: /save as draft/i }).click();
    let chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('draft');
    expect(Number(chapterState.versions)).toBe(0);

    await page.goto(`pages/researcher/submit-chapter.php?project_id=${workflowProjectId}&chapter=1`);
    await page.locator('textarea[name="background"]').fill('Submitted Chapter 1 content, version one.');
    const chapterCsrf = await page.locator('input[name="csrf_token"]').first().inputValue();
    await page.getByRole('button', { name: /^submit for review$/i }).click();
    chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('submitted');
    expect(Number(chapterState.versions)).toBe(1);
    expect(chapterState.latest_version_status).toBe('submitted');
    await page.request.post(`${baseUrl}pages/researcher/submit-chapter.php`, {
      form: {
        csrf_token: chapterCsrf,
        project_id: String(workflowProjectId),
        chapter: '1',
        save_draft: '1',
        background: 'Tampered after chapter submission.',
      },
    });
    chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('submitted');
    expect(chapterState.background).toBe('Submitted Chapter 1 content, version one.');

    await login(page, adminEmail, adminPassword, 'admin');
    await page.goto('pages/shared/research-submission-review.php?type=chapter');
    let chapterCard = page.locator('.submission-review-card').filter({ hasText: workflowTitleStageTitle }).filter({ hasText: 'Chapter 1' });
    await chapterCard.locator('textarea[name="comments"]').fill('Please clarify the research context and improve the supporting rationale.');
    await chapterCard.getByRole('button', { name: /return for revision/i }).click();
    chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('revision_required');
    expect(chapterState.latest_version_status).toBe('returned');
    expect(Number(chapterState.reviews)).toBe(1);
    expect(chapterState.latest_comments).toContain('clarify the research context');

    await login(page, workflowStudentEmail, testPassword, 'student');
    await page.goto(`pages/researcher/submit-chapter.php?project_id=${workflowProjectId}&chapter=1`);
    await page.locator('textarea[name="background"]').fill('Revised Chapter 1 content addressing the review comments.');
    await page.getByRole('button', { name: /resubmit for review/i }).click();
    chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('submitted');
    expect(Number(chapterState.versions)).toBe(2);
    expect(chapterState.latest_version_status).toBe('submitted');

    await login(page, adminEmail, adminPassword, 'admin');
    await page.goto('pages/shared/research-submission-review.php?type=chapter');
    chapterCard = page.locator('.submission-review-card').filter({ hasText: workflowTitleStageTitle }).filter({ hasText: 'Chapter 1' });
    await chapterCard.getByRole('button', { name: /^approve$/i }).click();
    chapterState = loadChapterSubmissionState(workflowProjectId, 1);
    expect(chapterState.status).toBe('approved');
    expect(chapterState.latest_version_status).toBe('approved');
    expect(Number(chapterState.versions)).toBe(2);
    expect(Number(chapterState.reviews)).toBe(2);

    await login(page, workflowStudentEmail, testPassword, 'student');
    await page.goto(`pages/researcher/submit-chapter.php?project_id=${workflowProjectId}`);
    await expect(page.locator('a.picker-item').filter({ hasText: /chapter 2:/i })).toBeVisible();
    await expectNoServerErrors(page);

    await login(page, workflowFacultyEmails[0], testPassword, 'faculty');
    const facultyTitle = `${workflowTitleStageTitle} Faculty`;
    await page.goto('pages/researcher/submit-research.php');
    await page.locator('input[name="title"]').fill(facultyTitle);
    await page.locator('select[name="category_id"]').selectOption({ index: 1 });
    await page.locator('select[name="ay_id"]').selectOption({ index: 1 });
    await page.locator('input[name="research_area"]').fill('RMS E2E faculty title-stage workflow');
    await page.locator('textarea[name="abstract"]').fill('Faculty accounts use the same research title submission functionality.');
    await page.locator('input[name="proposal_file"]').setInputFiles(pdfUpload);
    await page.getByRole('button', { name: /submit for review/i }).click();
    await expect(page.locator('body')).toContainText(/submitted/i);
    const facultyState = loadSubmissionState(facultyTitle);
    expect(facultyState.status).toBe('submitted');
    expect(Number(facultyState.created_by)).toBe(workflowFacultyIds[0]);
    expect(Number(facultyState.title_versions)).toBe(1);
    const facultyProjectId = Number(facultyState.project_id);
    await login(page, adminEmail, adminPassword, 'admin');
    await page.goto('pages/shared/research-submission-review.php?type=title');
    const facultyTitleCard = page.locator('.submission-review-card').filter({ hasText: facultyTitle });
    await facultyTitleCard.getByRole('button', { name: /^approve$/i }).click();
    expect(loadSubmissionState(facultyTitle).status).toBe('title_approved');

    await login(page, workflowFacultyEmails[0], testPassword, 'faculty');
    await page.goto(`pages/researcher/submit-chapter.php?project_id=${facultyProjectId}`);
    await expect(page.locator('body')).toContainText(/choose a chapter/i);
    await page.locator('a.picker-item').filter({ hasText: /chapter 1:/i }).click();
    await page.locator('textarea[name="background"]').fill('Faculty Chapter 1 submission through the shared chapter workflow.');
    await page.getByRole('button', { name: /^submit for review$/i }).click();
    const facultyChapterState = loadChapterSubmissionState(facultyProjectId, 1);
    expect(facultyChapterState.status).toBe('submitted');
    expect(Number(facultyChapterState.versions)).toBe(1);
  });

  test('seminars, trainings, and workshops persist recipients and edit without duplicate notifications', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, adminEmail, adminPassword, 'admin');
    const allTitle = `${workflowMarker} Seminar`;
    const specificTitle = `${workflowMarker} Training`;
    const staffTitle = `${workflowMarker} Workshop`;
    const allId = await createActivity(page, 'seminar', 'seminars', allTitle, 'all');
    const allActivity = loadActivity(allTitle);
    expect(Number(allActivity.participant_count)).toBeGreaterThanOrEqual(3);
    const specificId = await createActivity(page, 'training', 'trainings', specificTitle, 'specific', [workflowStudentId, workflowFacultyIds[0]]);
    expect(Number(loadActivity(specificTitle).participant_count)).toBe(2);
    const staffId = await createActivity(page, 'workshop', 'workshops', staffTitle, 'staff');
    expect(Number(loadActivity(staffTitle).participant_count)).toBeGreaterThan(0);
    workflowActivityIds.push(allId, specificId, staffId);

    const beforeEdit = loadActivity(specificTitle);
    await page.goto(`pages/shared/activity-form.php?id=${specificId}&capacity_page=trainings`);
    const editedActivityTitle = `${specificTitle} Edited`;
    await page.locator('#activity-title').fill(editedActivityTitle);
    await page.getByRole('button', { name: /save changes/i }).click();
    await expect(page.locator('body')).toContainText(editedActivityTitle);
    const afterEdit = loadActivity(editedActivityTitle);
    expect(Number(afterEdit.activity_id)).toBe(specificId);
    expect(Number(afterEdit.participant_count)).toBe(Number(beforeEdit.participant_count));
    expect(Number(afterEdit.notification_count)).toBe(Number(beforeEdit.notification_count));

    await login(page, workflowStudentEmail, testPassword, 'student');
    await page.goto(`pages/shared/activity-detail.php?id=${specificId}&capacity_page=trainings`);
    await expect(page.locator('body')).toContainText(editedActivityTitle);
    await expectNoServerErrors(page);
  });

  test('publications, repository, and intellectual-property records retain project relationships', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, workflowStudentEmail, testPassword, 'student');
    const journalId = await createPublication(page, 'journal', workflowJournalTitle, workflowProjectId);
    const conferenceId = await createPublication(page, 'conference', workflowConferenceTitle, workflowProjectId);
    workflowPublicationIds.push(journalId, conferenceId);

    const editedJournalTitle = `${workflowJournalTitle} Edited`;
    await page.goto(`pages/shared/publication-form.php?id=${journalId}`);
    await page.locator('input[name="research_title"]').fill(editedJournalTitle);
    await page.getByRole('button', { name: /save changes/i }).click();
    await expect(page.locator('body')).toContainText(editedJournalTitle);
    const editedJournal = loadPublication(editedJournalTitle);
    expect(Number(editedJournal.publication_id)).toBe(journalId);
    expect(Number(editedJournal.research_project_id)).toBe(workflowProjectId);

    await login(page, workflowStaffEmail, testPassword, 'research_staff');
    for (const publicationId of [journalId, conferenceId]) {
      await page.goto(`pages/shared/publication-detail.php?id=${publicationId}`);
      await page.getByRole('button', { name: /start review/i }).click();
      await expect(page.locator('body')).toContainText(/review/i);
      await page.getByRole('button', { name: /mark published/i }).click();
      await expect(page.locator('body')).toContainText(/published/i);
    }
    expect(loadPublication(editedJournalTitle).status).toBe('published');
    expect(loadPublication(workflowConferenceTitle).status).toBe('published');

    await page.goto('pages/shared/publications.php?publication_type=journal');
    await expect(page.locator('body')).toContainText(editedJournalTitle);
    await page.goto('pages/shared/publications.php?publication_type=conference');
    await expect(page.locator('body')).toContainText(workflowConferenceTitle);
    await page.goto('pages/shared/publications.php?status=published&repository=1');
    await expect(page.locator('body')).toContainText(editedJournalTitle);
    await expect(page.locator('body')).toContainText(workflowConferenceTitle);

    await login(page, workflowStudentEmail, testPassword, 'student');
    const researchCopyrightTitle = `${workflowMarker} Research Output`;
    const patentTitle = `${workflowMarker} Patent`;
    const innovationTitle = `${workflowMarker} Innovation`;
    const researchId = await createCopyright(page, 'research', researchCopyrightTitle, workflowProjectId, journalId);
    const patentId = await createCopyright(page, 'patent', patentTitle, workflowProjectId, journalId);
    const innovationId = await createCopyright(page, 'innovation', innovationTitle, workflowProjectId, conferenceId);
    workflowCopyrightIds.push(researchId, patentId, innovationId);

    await login(page, workflowStaffEmail, testPassword, 'research_staff');
    for (const [id, reference] of [[researchId, 'RMS-E2E-CR-1'], [patentId, 'RMS-E2E-PT-1'], [innovationId, 'RMS-E2E-IN-1']]) {
      await page.goto(`pages/shared/copyright-detail.php?id=${id}`);
      await page.getByRole('button', { name: /start review/i }).click();
      await expect(page.locator('body')).toContainText(/review/i);
      await page.locator('input[name="copyright_ref_no"]').fill(reference);
      await page.getByRole('button', { name: /register application/i }).click();
      await expect(page.locator('body')).toContainText(/registered/i);
    }
    expect(loadCopyright(researchCopyrightTitle).status).toBe('registered');
    expect(Number(loadCopyright(researchCopyrightTitle).publication_id)).toBe(journalId);
    expect(loadCopyright(patentTitle).output_type).toBe('patent');
    expect(loadCopyright(innovationTitle).output_type).toBe('innovation');
    await page.goto('pages/shared/copyrights.php?output_type=research');
    await expect(page.locator('body')).toContainText(researchCopyrightTitle);
    await page.goto('pages/shared/copyrights.php?ip_scope=patents_utility_models');
    await expect(page.locator('body')).toContainText(patentTitle);
    await page.goto('pages/shared/copyrights.php?ip_scope=innovation_technology_transfer');
    await expect(page.locator('body')).toContainText(innovationTitle);
    await expectNoServerErrors(page);
  });

  test('staff view and manage permissions are enforced on actual actions', async ({ page }) => {
    test.setTimeout(90_000);
    await login(page, adminEmail, adminPassword, 'admin');
    await setStaffPermissions(page, workflowStaffEmail, {
      research_capacity_building: { seminars: 'view', trainings: 'none', workshops: 'none' },
      research_development: { research_proposals: 'view', research_review: 'none', research_approval: 'none' },
      research_implementation_outputs: { ongoing_research: 'none', research_outputs: 'none' },
      publication_dissemination: { journal_publications: 'none', conference_presentations: 'none', research_repository: 'none' },
      intellectual_property_utilization: { copyrights: 'none', patents_utility_models: 'none', innovation_technology_transfer: 'none' },
    });
    await login(page, workflowStaffEmail, testPassword, 'research_staff');
    await page.goto('pages/shared/activities.php?capacity_page=seminars&type=seminar');
    await expect(page).toHaveURL(/activities\.php/);
    await expect(page.getByRole('link', { name: /add|create/i })).toHaveCount(0);
    await page.goto('pages/shared/activities.php?capacity_page=trainings&type=training');
    await expect(page).toHaveURL(/403\.php/);
    await page.goto('pages/shared/research-submission-review.php?module_page=research_proposals');
    await expect(page).toHaveURL(/research-submission-review\.php\?module_page=research_proposals/);
    await expect(page.getByRole('heading', { name: 'Research Proposals' })).toBeVisible();
    await expect(page.getByRole('button', { name: /approve|return for revision/i })).toHaveCount(0);

    await login(page, adminEmail, adminPassword, 'admin');
    await setStaffPermissions(page, workflowStaffEmail, {
      research_capacity_building: { seminars: 'manage', trainings: 'manage', workshops: 'manage' },
      research_development: { research_proposals: 'manage', research_review: 'manage', research_approval: 'manage' },
      research_implementation_outputs: { ongoing_research: 'manage', research_outputs: 'manage' },
      publication_dissemination: { journal_publications: 'manage', conference_presentations: 'manage', research_repository: 'view' },
      intellectual_property_utilization: { copyrights: 'manage', patents_utility_models: 'manage', innovation_technology_transfer: 'manage' },
    });
    await login(page, workflowStaffEmail, testPassword, 'research_staff');
    await page.goto('pages/shared/research-submission-review.php?module_page=research_review');
    await expect(page).toHaveURL(/research-submission-review\.php\?module_page=research_review/);
    await expect(page.getByRole('heading', { name: 'Research Review' })).toBeVisible();
    await expectNoServerErrors(page);
  });
});
