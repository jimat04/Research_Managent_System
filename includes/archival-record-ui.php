<?php
function archiveShellOpen(array $user, array $c, string $title, bool $adminPublicationList = false): void
{
    $subtitle = 'Record and maintain Institute outputs processed outside RMS.';
    if ($user['role'] === 'student') {
        $page = $c['module'] === 'publications' ? 'my-publications.php' : 'my-ip-contributions.php';
        $subtitle = $c['module'] === 'publications'
            ? 'Browse archived and published publication records across RMS, including records linked to you.'
            : 'Copyright records explicitly linked to you as an author or creator.';
        renderStudentShell($user, $page, $title, $subtitle);
    } elseif ($user['role'] === 'admin') {
        $adminPage = $c['module'] === 'publications' ? basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) : '';
        $publicationPages = ['admin-publications.php', 'admin-publication-form.php', 'admin-publication-detail.php', 'admin-publication-reports.php'];
        if (!in_array($adminPage, $publicationPages, true)) $adminPage = 'admin-publications.php';
        renderAdminShell($user, $c['module'] === 'publications' ? $adminPage : $c['module'] . '.php', $title, $subtitle);
    }
    elseif ($user['role'] === 'faculty') {
        if ($c['module'] === 'publications') $subtitle = 'Browse archived and published publication records across RMS. You can manage records you own.';
        renderFacultyShell($user, $c['faculty_page'] ?? ($c['module'] === 'publications' ? 'faculty-publications.php' : $c['module'] . '.php'), $title, $subtitle);
    }
    else renderStaffShell($user, $c['module'] . '.php', $title, $subtitle);
    $archiveCssPath = dirname(__DIR__) . '/css/archival-records.css';
    $archiveCssVersion = is_file($archiveCssPath) ? (string) filemtime($archiveCssPath) : '1';
    $archivePageClass = $adminPublicationList && $user['role'] === 'admin' && $c['module'] === 'publications'
        ? ' archive-admin-publication-page'
        : '';
    echo '<link rel="stylesheet" href="' . archiveEscape(SITE_URL . 'css/archival-records.css?v=' . $archiveCssVersion) . '"><div class="archive-page' . $archivePageClass . '">';
}
function archiveShellClose(array $user): void
{
    echo '</div>';
    if ($user['role'] === 'student') renderStudentShellClose();
    elseif ($user['role'] === 'admin') renderAdminShellClose();
    elseif ($user['role'] === 'faculty') renderFacultyShellClose();
    else renderStaffShellClose();
}
function archiveUserName(mysqli $db, $id): string
{
    if (!$id) return 'Not recorded (legacy)';
    $u = archiveQuery($db, 'SELECT first_name, last_name FROM users WHERE user_id = ?', [$id])->get_result()->fetch_assoc();
    return $u ? trim($u['first_name'] . ' ' . $u['last_name']) : 'Account #' . (int) $id;
}
