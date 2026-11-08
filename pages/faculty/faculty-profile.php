<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-ranks.php';
require_once __DIR__ . '/../../includes/academic-affiliation.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/services/FacultyParticipationService.php';

requireRole('faculty');
$user = getCurrentUser();
if (!$user) {
    header('Location: ' . SITE_URL . 'public/login.php');
    exit;
}
if (($user['role'] ?? '') !== 'faculty') {
    http_response_code(403);
    exit('Faculty access is required.');
}
if ((string) ($_GET['tab'] ?? '') === 'mentoring') {
    header('Location: ' . SITE_URL . 'pages/faculty/faculty-advising.php');
    exit;
}

function facultyProfileEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fpStatusLabel($value): string
{
    $status = trim((string) $value);
    if ($status === '') {
        return 'Not recorded';
    }

    $labels = [
        'under_crec_review' => 'Under CREC Review',
        'under_erec_review' => 'Under EREC Review',
        'under_review' => 'Under Review',
        'book_chapter' => 'Book Chapter',
        'conference_presentation' => 'Conference Presentation',
        'extension_community' => 'Extension / Community',
        'research_award' => 'Research Award',
        'seminar_training' => 'Seminar / Training',
        'title_approved' => 'Title Approved',
        'for_revision' => 'For Revision',
        'revision_required' => 'Revision Required',
        'legacy' => 'Legacy Record',
    ];
    $normalizedStatus = strtolower($status);

    return $labels[$normalizedStatus] ?? ucwords(str_replace(['_', '-'], ' ', $status));
}

function fpStatusBadgeClass($value): string
{
    return match (strtolower(trim((string) $value))) {
        'verified' => 'verified',
        'approved', 'accepted', 'published', 'registered', 'completed', 'ongoing', 'title_approved' => 'success',
        'pending', 'submitted', 'under_review', 'under_crec_review', 'under_erec_review', 'proposal', 'progress_report', 'terminal_review' => 'pending',
        'draft' => 'draft',
        'returned', 'rejected', 'for_revision', 'revision_required' => 'returned',
        default => 'neutral',
    };
}

function facultyProfileIsHttpsUrl(?string $url): bool
{
    if (!$url || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https';
}

function facultyProfileTableExists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to check faculty profile setup: ' . $connection->error);
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

$userId = (int) $user['user_id'];
$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'research', 'publications', 'achievements'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}
$profileAvailable = facultyProfileTableExists($conn, 'faculty_research_profiles');
$hasPublications = facultyProfileTableExists($conn, 'publications');
$hasPublicationContributors = facultyProfileTableExists($conn, 'publication_contributors');
$hasCopyrights = facultyProfileTableExists($conn, 'copyright_applications');
$hasCopyrightContributors = facultyProfileTableExists($conn, 'copyright_contributors');
$hasEngagementRecords = $profileAvailable && facultyProfileTableExists($conn, 'faculty_engagement_records');

$userStmt = $conn->prepare('SELECT first_name, last_name, email, department, program, specialization, academic_rank FROM users WHERE user_id = ? AND role = \'faculty\' AND deleted_at IS NULL LIMIT 1');
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$faculty = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
$facultyAffiliationAvailable = rmsAcademicAffiliationTablesAvailable($conn);
$facultyAffiliationSummary = $facultyAffiliationAvailable
    ? rmsAcademicAffiliationDisplay($conn, $userId)
    : [];
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile is unavailable.');
}
$fullName = trim((string) $faculty['first_name'] . ' ' . (string) $faculty['last_name']);
$initials = strtoupper(substr((string) $faculty['first_name'], 0, 1) . substr((string) $faculty['last_name'], 0, 1));
$profile = [
    'highest_degree' => '',
    'research_interests' => '',
    'orcid_url' => '',
    'google_scholar_url' => '',
    'researchgate_url' => '',
];
$formError = '';
$formSuccess = isset($_GET['saved']) && $_GET['saved'] === '1' ? 'Research profile saved.' : '';

if ($profileAvailable) {
    $profileStmt = $conn->prepare('SELECT highest_degree, research_interests, orcid_url, google_scholar_url, researchgate_url FROM faculty_research_profiles WHERE user_id = ? LIMIT 1');
    $profileStmt->bind_param('i', $userId);
    $profileStmt->execute();
    $savedProfile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
    if ($savedProfile) {
        $profile = array_merge($profile, $savedProfile);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'highest_degree' => trim((string) ($_POST['highest_degree'] ?? '')),
        'research_interests' => trim((string) ($_POST['research_interests'] ?? '')),
        'orcid_url' => trim((string) ($_POST['orcid_url'] ?? '')),
        'google_scholar_url' => trim((string) ($_POST['google_scholar_url'] ?? '')),
        'researchgate_url' => trim((string) ($_POST['researchgate_url'] ?? '')),
    ];
    $profile = array_merge($profile, $values);
    if (!$profileAvailable) {
        $formError = 'Profile editing is unavailable until database migration 024 is applied.';
    } elseif (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $formError = 'Your form has expired. Refresh the page and try again.';
    } elseif (strlen($values['highest_degree']) > 150 || strlen($values['research_interests']) > 5000) {
        $formError = 'Highest degree must be 150 characters or fewer; research interests must be 5,000 characters or fewer.';
    } else {
        $orcidValid = $values['orcid_url'] === ''
            || preg_match('#^https://orcid\.org/\d{4}-\d{4}-\d{4}-\d{3}[\dX]$#iD', $values['orcid_url']);
        $urlValid = static function (string $url, array $allowedHosts): bool {
            if ($url === '') {
                return true;
            }
            $parts = parse_url($url);
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])) {
                return false;
            }
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            return $host !== '' && in_array($host, $allowedHosts, true);
        };
        if (!$orcidValid) {
            $formError = 'Enter an ORCID URL in the format https://orcid.org/0000-0000-0000-0000.';
        } elseif (strlen($values['google_scholar_url']) > 500 || strlen($values['researchgate_url']) > 500) {
            $formError = 'Profile links must be 500 characters or fewer.';
        } elseif (!$urlValid($values['google_scholar_url'], ['scholar.google.com']) || !$urlValid($values['researchgate_url'], ['researchgate.net', 'www.researchgate.net'])) {
            $formError = 'Use a valid HTTPS Google Scholar or ResearchGate profile link.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO faculty_research_profiles
                    (user_id, highest_degree, research_interests, orcid_url, google_scholar_url, researchgate_url, profile_status, reviewed_by, review_comments, reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'draft', NULL, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    highest_degree = VALUES(highest_degree),
                    research_interests = VALUES(research_interests),
                    orcid_url = VALUES(orcid_url),
                    google_scholar_url = VALUES(google_scholar_url),
                    researchgate_url = VALUES(researchgate_url),
                    profile_status = 'draft'"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare profile update: ' . $conn->error);
            }
            $stmt->bind_param('isssss', $userId, $values['highest_degree'], $values['research_interests'], $values['orcid_url'], $values['google_scholar_url'], $values['researchgate_url']);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save faculty profile: ' . $stmt->error);
            }
            $stmt->close();
            logActivity('Saved faculty research profile', 'faculty_research_profile');
            header('Location: ' . SITE_URL . 'pages/faculty/faculty-profile.php?tab=research&saved=1');
            exit;
        }
    }
}

$publications = [];
if ($hasPublications && $hasPublicationContributors) {
    $stmt = $conn->prepare('SELECT p.publication_id, p.research_title, p.publication_type, p.archival_status AS status, p.publication_date FROM publications p WHERE p.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?) ORDER BY p.publication_date DESC, p.created_at DESC LIMIT 100');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $publications[] = $row;
    }
    $stmt->close();
}

$portfolioProjects = [];
$registeredIpOutputs = [];
$stmt = $conn->prepare(
    'SELECT rp.project_id, rp.title, rp.status, rp.updated_at
       FROM research_projects rp
      WHERE rp.deleted_at IS NULL
        AND (rp.adviser_id = ? OR EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?))
      ORDER BY rp.updated_at DESC, rp.project_id DESC
      LIMIT 100'
);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $portfolioProjects[] = $row;
}
$stmt->close();

$registeredOutputs = 0;
if ($hasCopyrights && $hasCopyrightContributors) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM copyright_applications c WHERE c.output_type IN ('software','research','instructional_material','module','other','patent','utility_model','innovation','technology_transfer') AND c.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = ?) AND (c.external_status = 'registered' OR (c.external_status IS NULL AND c.status = 'registered' AND NULLIF(c.copyright_ref_no, '') IS NOT NULL))");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $registeredOutputs = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT c.copyright_id, c.output_title, c.output_type, c.copyright_ref_no, c.registered_at, c.external_status, c.archival_status FROM copyright_applications c WHERE c.output_type IN ('software','research','instructional_material','module','other','patent','utility_model','innovation','technology_transfer') AND c.deactivated_at IS NULL AND EXISTS (SELECT 1 FROM copyright_contributors cc WHERE cc.copyright_id = c.copyright_id AND cc.user_id = ?) AND (c.external_status = 'registered' OR (c.external_status IS NULL AND c.status = 'registered' AND NULLIF(c.copyright_ref_no, '') IS NOT NULL)) ORDER BY c.registered_at DESC, c.copyright_id DESC LIMIT 100");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $registeredIpOutputs[] = $row;
    }
    $stmt->close();
}
$verifiedEngagements = 0;
if ($hasEngagementRecords) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM faculty_engagement_records WHERE faculty_id = ? AND review_status = 'verified'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $verifiedEngagements = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$publishedCount = 0;
if ($hasPublications && $hasPublicationContributors) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM publications p WHERE p.deactivated_at IS NULL AND (p.archival_status = 'archived' OR p.status = 'published') AND EXISTS (SELECT 1 FROM publication_contributors pc WHERE pc.publication_id = p.publication_id AND pc.user_id = ?)");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $publishedCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$projectCountStmt = $conn->prepare(
    'SELECT COUNT(DISTINCT rp.project_id) AS total
       FROM research_projects rp
      WHERE rp.deleted_at IS NULL
        AND (rp.adviser_id = ? OR EXISTS (SELECT 1 FROM project_advisers pa WHERE pa.project_id = rp.project_id AND pa.adviser_id = ?))'
);
        $projectCountStmt->bind_param('ii', $userId, $userId);
$projectCountStmt->execute();
$projectCount = (int) ($projectCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$projectCountStmt->close();
$facultyParticipationSummary = (new FacultyParticipationService($conn))->getMySummary($userId);
$verifiedActivityAttendanceCount = (int) $facultyParticipationSummary['attendee_events'];

$tabLinks = [
    ['key' => 'overview', 'label' => 'Profile overview'],
    ['key' => 'research', 'label' => 'Research interests & expertise'],
    ['key' => 'publications', 'label' => 'Publications & outputs'],
    ['key' => 'achievements', 'label' => 'Registered IP'],
];

renderFacultyShell($user, 'faculty-profile.php', 'Faculty Profile', 'Academic identity and linked research portfolio.', ['faculty-profile.css']);
?>
<div class="fp-page" id="fp-page">
    <header class="fp-header<?= $tab === 'overview' ? ' fp-header--overview' : '' ?>" role="banner">
        <div class="fp-identity">
            <div class="fp-avatar" aria-hidden="true"><?= facultyProfileEscape($initials ?: 'F') ?></div>
            <div class="fp-identity__meta">
                <p class="fp-overline">Faculty Research Profile</p>
                <h1 class="fp-name"><?= facultyProfileEscape($fullName) ?></h1>
                <p class="fp-title">
                    <?= facultyProfileEscape(rmsFacultyAcademicRankLabel($faculty['academic_rank'] ?: 'Academic rank not recorded')) ?>
                    · EARIST
                    <?php if ($facultyAffiliationSummary): ?>
                        · <?= facultyProfileEscape(implode('; ', array_map(static fn($group, $program) => $group . ' — ' . $program, array_keys($facultyAffiliationSummary), array_values($facultyAffiliationSummary)))) ?>
                    <?php else: ?>
                        · <?= facultyProfileEscape($faculty['department'] ?: 'Academic unit not recorded') ?>
                        <?= $faculty['program'] ? ' · ' . facultyProfileEscape($faculty['program']) : '' ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="fp-header__actions">
            <a class="fp-btn fp-btn--primary" href="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-engagement.php') ?>">
                <svg class="fp-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Submit engagement
            </a>
            <a class="fp-btn fp-btn--secondary" href="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-publications.php') ?>">
                <svg class="fp-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                View publications
            </a>
        </div>
    </header>

    <nav class="fp-tabs" aria-label="Faculty profile sections" role="tablist">
        <?php foreach ($tabLinks as $tabLink): ?>
                <a class="fp-tab <?= $tab === $tabLink['key'] ? 'fp-tab--active' : '' ?>"
               href="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-profile.php?tab=' . $tabLink['key']) ?>"
               role="tab"
               <?= $tab === $tabLink['key'] ? ' aria-selected="true" aria-current="page"' : ' aria-selected="false"' ?>>
                <?= facultyProfileEscape($tabLink['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($formSuccess !== ''): ?>
        <div class="fp-alert fp-alert--success" role="status"><?= facultyProfileEscape($formSuccess) ?></div>
    <?php endif; ?>
    <?php if ($formError !== ''): ?>
        <div class="fp-alert fp-alert--error" role="alert"><?= facultyProfileEscape($formError) ?></div>
    <?php endif; ?>

    <main class="fp-main" id="fp-main" role="main">
        <?php if ($tab === 'overview'): ?>
            <!-- OVERVIEW TAB -->
            <section class="fp-grid" aria-labelledby="overview-heading">
                <article class="fp-panel fp-panel--featured">
                    <header class="fp-panel__header">
                        <div>
                            <p class="fp-panel__label">Academic and research profile</p>
                            <h2 id="overview-heading" class="fp-panel__title">Institutional profile</h2>
                        </div>
                    </header>
                    <dl class="fp-meta">
                        <div class="fp-meta__item">
                            <dt class="fp-meta__label">Highest educational attainment</dt>
                            <dd class="fp-meta__value"><?= facultyProfileEscape($profile['highest_degree'] ?: 'Not provided') ?></dd>
                        </div>
                        <div class="fp-meta__item">
                            <dt class="fp-meta__label">Research specialization</dt>
                            <dd class="fp-meta__value"><?= facultyProfileEscape($faculty['specialization'] ?: 'Not recorded') ?></dd>
                        </div>
                        <div class="fp-meta__item fp-meta__item--wide">
                            <dt class="fp-meta__label">Research interests</dt>
                            <dd class="fp-meta__value fp-meta__value--rich"><?= nl2br(facultyProfileEscape($profile['research_interests'] ?: 'Not provided')) ?></dd>
                        </div>
                        <?php if (facultyProfileIsHttpsUrl($profile['orcid_url'])): ?>
                        <div class="fp-meta__item">
                            <dt class="fp-meta__label">ORCID</dt>
                            <dd class="fp-meta__value"><a class="fp-link" href="<?= facultyProfileEscape($profile['orcid_url']) ?>" target="_blank" rel="noopener noreferrer"><?= facultyProfileEscape($profile['orcid_url']) ?></a></dd>
                        </div>
                        <?php endif; ?>
                        <?php if (facultyProfileIsHttpsUrl($profile['google_scholar_url'])): ?>
                        <div class="fp-meta__item">
                            <dt class="fp-meta__label">Google Scholar</dt>
                            <dd class="fp-meta__value"><a class="fp-link" href="<?= facultyProfileEscape($profile['google_scholar_url']) ?>" target="_blank" rel="noopener noreferrer">View Google Scholar profile</a></dd>
                        </div>
                        <?php endif; ?>
                        <?php if (facultyProfileIsHttpsUrl($profile['researchgate_url'])): ?>
                        <div class="fp-meta__item">
                            <dt class="fp-meta__label">ResearchGate</dt>
                            <dd class="fp-meta__value"><a class="fp-link" href="<?= facultyProfileEscape($profile['researchgate_url']) ?>" target="_blank" rel="noopener noreferrer">View ResearchGate profile</a></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                    <div class="fp-panel__footer">
                        <a class="fp-btn fp-btn--secondary" href="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-profile.php?tab=research') ?>">
                            Edit research profile
                        </a>
                    </div>
                </article>

                <article class="fp-panel">
                    <header class="fp-panel__header">
                        <div>
                            <p class="fp-panel__label">Linked accomplishments</p>
                            <h2 class="fp-panel__title">Research portfolio</h2>
                        </div>
                    </header>
                    <div class="fp-stats" role="list" aria-label="Research portfolio statistics">
                        <div class="fp-stat" role="listitem">
                            <span class="fp-stat__value"><?= $projectCount ?></span>
                            <span class="fp-stat__label">Advising projects</span>
                        </div>
                        <div class="fp-stat" role="listitem">
                            <span class="fp-stat__value"><?= $publishedCount ?></span>
                            <span class="fp-stat__label">Published research</span>
                        </div>
                        <div class="fp-stat" role="listitem">
                            <span class="fp-stat__value"><?= $verifiedEngagements ?></span>
                            <span class="fp-stat__label">Verified engagement</span>
                        </div>
                        <div class="fp-stat" role="listitem">
                            <span class="fp-stat__value"><?= $verifiedActivityAttendanceCount ?></span>
                            <span class="fp-stat__label">Verified activity attendance</span>
                        </div>
                        <div class="fp-stat" role="listitem">
                            <span class="fp-stat__value"><?= $registeredOutputs ?></span>
                            <span class="fp-stat__label">Registered IP outputs</span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="fp-panel" aria-labelledby="advising-heading">
                <header class="fp-panel__header">
                    <div>
                        <p class="fp-panel__label">Advising</p>
                        <h2 id="advising-heading" class="fp-panel__title">Assigned advisee projects</h2>
                    </div>
                    <a class="fp-link fp-link--standalone" href="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-advising.php') ?>">
                        Open Advising
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                </header>
                <?php if (!$portfolioProjects): ?>
                    <p class="fp-empty">No advising assignments are linked to your account yet.</p>
                <?php else: ?>
                    <ul class="fp-list" role="list">
                        <?php foreach ($portfolioProjects as $project): ?>
                            <li class="fp-list__item">
                                <div class="fp-list__content">
                                    <strong><a class="fp-link" href="<?= facultyProfileEscape(SITE_URL . 'pages/shared/research-detail.php?id=' . (int) $project['project_id']) ?>"><?= facultyProfileEscape($project['title']) ?></a></strong>
                                    <small class="fp-list__meta">Updated <?= facultyProfileEscape(date('M j, Y', strtotime((string) $project['updated_at']))) ?></small>
                                </div>
                                <span class="fp-badge fp-badge--<?= fpStatusBadgeClass($project['status']) ?>">
                                    <?= facultyProfileEscape(fpStatusLabel($project['status'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        <?php elseif ($tab === 'research'): ?>
            <!-- RESEARCH TAB -->
            <section class="fp-panel" aria-labelledby="research-heading">
                <header class="fp-panel__header">
                    <div>
                        <p class="fp-panel__label">Research profile</p>
                        <h2 id="research-heading" class="fp-panel__title">Research interests and scholarly links</h2>
                    </div>
                </header>
                <?php if (!$profileAvailable): ?>
                    <div class="fp-alert fp-alert--warning">Profile editing is unavailable until database migration 024 is applied.</div>
                <?php else: ?>
                    <p class="fp-hint">Update and save your research profile details at any time.</p>
                    <form class="fp-form" method="post" action="<?= facultyProfileEscape(SITE_URL . 'pages/faculty/faculty-profile.php?tab=research') ?>">
                        <?= csrfField() ?>
                        <div class="fp-form__grid">
                            <div class="fp-form__field">
                                <label class="fp-form__label" for="highest_degree">Highest educational attainment</label>
                                <input class="fp-form__input" type="text" id="highest_degree" name="highest_degree" maxlength="150" value="<?= facultyProfileEscape($profile['highest_degree']) ?>">
                            </div>
                            <div class="fp-form__field">
                                <label class="fp-form__label" for="orcid">ORCID URL</label>
                                <input class="fp-form__input" type="url" id="orcid" name="orcid_url" maxlength="255" value="<?= facultyProfileEscape($profile['orcid_url']) ?>" placeholder="https://orcid.org/0000-0000-0000-0000">
                            </div>
                            <div class="fp-form__field fp-form__field--full">
                                <label class="fp-form__label" for="interests">Research interests and expertise</label>
                                <textarea class="fp-form__textarea" id="interests" name="research_interests" maxlength="5000" rows="5"><?= facultyProfileEscape($profile['research_interests']) ?></textarea>
                            </div>
                            <div class="fp-form__field">
                                <label class="fp-form__label" for="scholar">Google Scholar profile</label>
                                <input class="fp-form__input" type="url" id="scholar" name="google_scholar_url" maxlength="500" value="<?= facultyProfileEscape($profile['google_scholar_url']) ?>" placeholder="https://scholar.google.com/...">
                            </div>
                            <div class="fp-form__field">
                                <label class="fp-form__label" for="researchgate">ResearchGate profile</label>
                                <input class="fp-form__input" type="url" id="researchgate" name="researchgate_url" maxlength="500" value="<?= facultyProfileEscape($profile['researchgate_url']) ?>" placeholder="https://www.researchgate.net/...">
                            </div>
                        </div>
                        <div class="fp-form__actions">
                            <button type="submit" class="fp-btn fp-btn--primary">
                                <svg class="fp-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                Save profile
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

        <?php elseif ($tab === 'publications'): ?>
            <!-- PUBLICATIONS TAB -->
            <section class="fp-panel" aria-labelledby="publications-heading">
                <header class="fp-panel__header">
                    <div>
                        <p class="fp-panel__label">Scholarly outputs</p>
                        <h2 id="publications-heading" class="fp-panel__title">Publications & presentations</h2>
                    </div>
                </header>
                <?php if (!$hasPublications): ?>
                    <div class="fp-alert fp-alert--warning">Publication records are unavailable. Apply migration 012.</div>
                <?php elseif (!$publications): ?>
                    <p class="fp-empty">No publication records are linked to your account yet.</p>
                <?php else: ?>
                    <ul class="fp-list" role="list">
                        <?php foreach ($publications as $publication): ?>
                            <li class="fp-list__item">
                                <div class="fp-list__content">
                                    <strong><?= facultyProfileEscape($publication['research_title']) ?></strong>
                                    <small class="fp-list__meta">
                                        <?= facultyProfileEscape(fpStatusLabel($publication['publication_type'])) ?>
                                        <?= $publication['publication_date'] ? ' · ' . facultyProfileEscape(date('M j, Y', strtotime((string) $publication['publication_date']))) : '' ?>
                                    </small>
                                </div>
                                <span class="fp-badge fp-badge--<?= fpStatusBadgeClass($publication['status']) ?>">
                                    <?= facultyProfileEscape(fpStatusLabel($publication['status'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <!-- ACHIEVEMENTS TAB -->
            <section class="fp-panel" aria-labelledby="registered-ip-outputs">
                <h2 id="registered-ip-outputs" class="fp-ip-title">Registered intellectual property and research outputs</h2>
                <?php if ($hasCopyrights): ?>
                    <?php if (!$registeredIpOutputs): ?>
                        <p class="fp-empty">No registered intellectual property outputs are linked to your account.</p>
                    <?php else: ?>
                        <ul class="fp-list" role="list">
                            <?php foreach ($registeredIpOutputs as $output): ?>
                                <li class="fp-list__item">
                                    <div class="fp-list__content">
                                        <strong><a class="fp-link" href="<?= facultyProfileEscape(SITE_URL . 'pages/shared/copyright-detail.php?id=' . (int) $output['copyright_id']) ?>"><?= facultyProfileEscape($output['output_title']) ?></a></strong>
                                        <small class="fp-list__meta">
                                            <?= facultyProfileEscape(fpStatusLabel($output['output_type'])) ?>
                                            <?= $output['copyright_ref_no'] ? ' · ' . facultyProfileEscape($output['copyright_ref_no']) : '' ?>
                                            <?= $output['registered_at'] ? ' · Registered ' . facultyProfileEscape(date('M j, Y', strtotime((string) $output['registered_at']))) : '' ?>
                                        </small>
                                    </div>
                                    <span class="fp-badge fp-badge--verified">Registered</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="fp-empty">Registered intellectual property records are currently unavailable.</p>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    </main>
</div>
<?php renderFacultyShellClose(); ?>
