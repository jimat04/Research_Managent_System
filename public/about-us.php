<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact-handler.php';

$name = '';
$email = '';
$concern_type = 'General Inquiry';
$message = '';
$errors = [];
$success = false;
$password_help_request = ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['password_help'] ?? '') === '1');
$password_help_message = 'I cannot access my RMS account and need help resetting my password. My registered email address is: [enter your registered email]. Please contact me to verify my identity and assist with a password reset.';

if ($password_help_request) {
    $concern_type = 'Account Issue';
    $message = $password_help_message;
}

$concern_types = [
    'General Inquiry',
    'Technical Support',
    'Research Advisory',
    'Account Issue',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your form has expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $concern_type = trim($_POST['concern_type'] ?? 'General Inquiry');
    $message = trim($_POST['message'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter your name.';
    }

    if ($email === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!in_array($concern_type, $concern_types, true)) {
        $concern_type = 'General Inquiry';
    }

    if ($message === '') {
        $errors[] = 'Please enter your message.';
    }

    if (empty($errors)) {
        $result = saveContactMessage($conn, $name, $email, $concern_type, $message);

        if ($result['success']) {
            $success = true;
            $name = '';
            $email = '';
            $concern_type = 'General Inquiry';
            $message = '';
        } else {
            $errors[] = 'Failed to send your message. Please try again later.';
        }
    }
}

function contact_escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// These public aggregate queries contain no user input and are safe from SQL injection; prepared statements keep the page consistent with protected areas.
$total_research_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects");
$total_research_stmt->execute();
$stat_total_research = $total_research_stmt->get_result()->fetch_assoc()['count'];

$active_users_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE status = 'active' AND role = 'student'");
$active_users_stmt->execute();
$stat_active_students = $active_users_stmt->get_result()->fetch_assoc()['count'];

$completed_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('completed', 'archived')");
$completed_stmt->execute();
$stat_completed = $completed_stmt->get_result()->fetch_assoc()['count'];

$approval_rate_stmt = $conn->prepare("SELECT ROUND(100.0 * COUNT(CASE WHEN status IN ('completed', 'approved', 'archived') THEN 1 END) / NULLIF(COUNT(CASE WHEN status NOT IN ('draft') THEN 1 END), 0)) AS rate FROM research_projects");
$approval_rate_stmt->execute();
$stat_approval_rate = $approval_rate_stmt->get_result()->fetch_assoc()['rate'] ?? 0;

$under_review_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM research_projects WHERE status IN ('submitted', 'under_review', 'under_crec_review', 'under_erec_review')");
$under_review_stmt->execute();
$stat_under_review = $under_review_stmt->get_result()->fetch_assoc()['count'];

$faculty_advisers_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE role = 'faculty' AND status = 'active'");
$faculty_advisers_stmt->execute();
$stat_faculty_advisers = $faculty_advisers_stmt->get_result()->fetch_assoc()['count'];

$research_staff_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE role IN ('research_staff', 'admin') AND status = 'active'");
$research_staff_stmt->execute();
$stat_research_staff = $research_staff_stmt->get_result()->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>About Us | <?php echo htmlspecialchars(rmsSetting('site_name'), ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/public-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-theme.css'); ?>">
        <link rel="stylesheet" href="assets/css/landing-v2.css?v=<?php echo filemtime(__DIR__ . '/assets/css/landing-v2.css'); ?>">
        <link rel="stylesheet" href="assets/css/public-header.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-header.css'); ?>">
        <link rel="stylesheet" href="../css/about-editorial.css?v=<?php echo filemtime(__DIR__ . '/../css/about-editorial.css'); ?>">
        <link rel="stylesheet" href="assets/css/public-footer.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-footer.css'); ?>">
</head>
    <body class="public-v2 about-page">
        <svg class="icon-defs" width="0" height="0" aria-hidden="true"><defs>
          <linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7C3AED"/><stop offset=".6" stop-color="#2563EB"/><stop offset="1" stop-color="#06B6D4"/></linearGradient>
          <symbol id="arr" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
          <symbol id="usr" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></symbol>
          <symbol id="usp" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3-6 8-6M18 14v6M15 17h6"/></symbol><symbol id="grp" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M18 14.5c2.5.3 4 2 4 5"/></symbol>
          <symbol id="doc" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></symbol><symbol id="bld" viewBox="0 0 24 24"><path d="M3 10l9-6 9 6M5 10v9M9 10v9M15 10v9M19 10v9M3 21h18"/></symbol>
          <symbol id="cap" viewBox="0 0 24 24"><path d="M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5"/></symbol><symbol id="fld" viewBox="0 0 24 24"><path d="M3 6h6l2 2h10v11H3z"/></symbol>
          <symbol id="chk" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol><symbol id="mal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></symbol>
          <symbol id="tel" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2 1c1 3 3 5 6 6l1-2 5 2v4c-9 1-17-7-16-16z"/></symbol><symbol id="pin" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></symbol>
        </defs></svg>

        <?php $publicHeaderActivePage = 'about'; require __DIR__ . '/../includes/public-header.php'; ?>

        <main>
        <div class="about-reading-progress" aria-hidden="true"></div>
        <section class="hero about-hero" aria-labelledby="about-title">
          <video class="about-hero-video" aria-hidden="true" tabindex="-1" loop muted playsinline preload="auto" poster="../photos/admin-hero-building.jpg">
            <source src="assets/video/research-building.mp4?v=<?php echo filemtime(__DIR__ . '/assets/video/research-building.mp4'); ?>" type="video/mp4">
          </video>
          <div class="about-hero-scrim" aria-hidden="true"></div>
          <div class="w about-editorial">
            <div class="about-hero-copy">
            <p class="about-kicker">Research Office</p>
            <h1 id="about-title">A home for<br>research at <span class="about-hero-institution">EARIST</span>.</h1>
            <p class="about-lead">One RMS platform for student research submissions, faculty research and advising, and Research Office records.</p>
            <a class="btn n" href="<?php echo SITE_URL; ?>/public/research-archive.php">Explore the research archive <span aria-hidden="true">↗</span></a>
            </div>
          </div>
        </section>
        <nav class="about-section-nav" aria-label="About Us page sections">
          <div class="container">
            <a href="#about-earist">About EARIST</a>
            <a href="#research-workflow">The process</a>
            <a href="#research-features">Features</a>
            <a href="#research-roles">Your role</a>
            <a href="#research-activity">Research activity</a>
            <a href="#contact-us">Contact</a>
          </div>
        </nav>
        <!-- INSTITUTIONAL RESEARCH OFFICE -->
        <section id="about-earist" class="section about-section about-purpose" data-reveal>
            <div class="container">
                <div class="feature-card">
                    <h3>About the Research Office</h3>
                    <p>The EARIST Office of Research Services (ORS) supports the Institute’s research program by coordinating research projects, building researchers’ capacity, conducting research and development studies, and maintaining research information for planning and use across the Institute.</p>
                </div>
                <div class="feature-card">
                    <h3>EARIST Vision</h3>
                    <p>EARIST is envisioned to be a Center of Excellence in trades, business, arts, sciences and technology education.</p>
                    <h3 class="about-purpose-mission">ORS Mission</h3>
                    <p>Enhance basic and applied research projects and activities aimed at solving education, industrial and community problems, innovation and inventions.</p>
                </div>
            </div>
        </section>

        <section class="section about-section about-manual" data-reveal aria-labelledby="ors-manual-title">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title" id="ors-manual-title">ORS Goals, Objectives &amp; Research Agenda</h2>
                    <p class="section-desc">Institutional research priorities documented in the EARIST Research Manual (2015).</p>
                </div>
                <div class="manual-grid">
                    <details class="manual-card">
                        <summary><h3>ORS Goals</h3><span class="manual-disclosure-label" aria-hidden="true">Expand</span><span class="manual-disclosure-indicator" aria-hidden="true"></span></summary>
                        <div class="manual-card-content">
                            <ol>
                                <li>Develop and maintain an effective mechanism of forecasting, planning, policy-making, financing, management, and evaluation of RS activities of the Institute.</li>
                                <li>Develop a self-generating and independent core of RS manpower through relevant and responsive capacity building activities to serve the needs of science, technology and technological education research.</li>
                                <li>Establish an efficient, effective, and functional collection of physical resources that are highly relevant and appropriate in attaining the goals and objectives of RS projects.</li>
                                <li>Generate high quality research outputs that are useful and acceptable to the industrial and education sectors.</li>
                            </ol>
                        </div>
                    </details>
                    <details class="manual-card">
                        <summary><h3>ORS Objectives</h3><span class="manual-disclosure-label" aria-hidden="true">Expand</span><span class="manual-disclosure-indicator" aria-hidden="true"></span></summary>
                        <div class="manual-card-content">
                            <ol>
                                <li>Search and discover through specific research, relevant and updated information that will serve as input for academic and administrative planning, policy formulation and discussion making.</li>
                                <li>Provide relevant and responsive capacity building activities for upgrading or retooling research competencies of researchers.</li>
                                <li>Conduct quality research and development studies in: (a) science and technology education; (b) Institute policy research; (c) current technologies in the educational and industrial sectors in terms of products, processes, and services.</li>
                                <li>Develop, maintain, enrich and provide a general databank, readily available to colleges/sectors/units of the Institute and to outside clientele.</li>
                            </ol>
                        </div>
                    </details>
                    <details class="manual-card">
                        <summary><h3>Core Values</h3><span class="manual-disclosure-label" aria-hidden="true">Expand</span><span class="manual-disclosure-indicator" aria-hidden="true"></span></summary>
                        <div class="manual-card-content">
                            <p>Responsive and Scientific Thinking</p>
                        </div>
                    </details>
                    <details class="manual-card">
                        <summary><h3>EARIST Institute Research Agenda</h3><span class="manual-disclosure-label" aria-hidden="true">Expand</span><span class="manual-disclosure-indicator" aria-hidden="true"></span></summary>
                        <div class="manual-card-content">
                            <ol>
                                <li>Research in science and technology for institutional and national development.</li>
                                <li>Research to promote productivity and competitiveness of EARIST.</li>
                                <li>Research to enhance capability building and management in the Institute.</li>
                                <li>Research to improve instruction in the various disciplines.</li>
                                <li>Research to promote institutional image.</li>
                            </ol>
                        </div>
                    </details>
                </div>
                <p>Source: EARIST Research Manual (2015).</p>
            </div>
        </section>

        <section id="research-workflow" class="about-workflow" aria-labelledby="workflow-title">
          <div class="container">
            <div class="about-workflow-heading">
              <div><p class="about-process-eyebrow">Student research process</p><h2 id="workflow-title">From first draft to research record.</h2></div>
              <p class="about-workflow-intro">Students follow this title-to-final-manuscript path. Faculty use separate tools for research portfolios, advising, engagement, and documents.</p>
            </div>
            <ol>
              <li><div class="about-workflow-marker"><span aria-hidden="true">01</span><svg class="i" aria-hidden="true"><use href="#doc"/></svg></div><div class="about-workflow-copy"><h3>Draft &amp; submit</h3><p>Students prepare a title proposal and submit it for authorized review.</p><details class="about-step-detail"><summary>Saving &amp; submission</summary><p>Save and edit the student draft before submitting. Submission preserves a locked version snapshot for review.</p></details></div></li>
              <li><div class="about-workflow-marker"><span aria-hidden="true">02</span><svg class="i" aria-hidden="true"><use href="#mal"/></svg></div><div class="about-workflow-copy"><h3>Review &amp; revise</h3><p>Students receive a decision and can revise returned work.</p><details class="about-step-detail"><summary>Decisions &amp; revisions</summary><p>Administrators and research staff authorized for the relevant action approve or return student submissions. Returned work can be revised and resubmitted with earlier versions preserved.</p></details></div></li>
              <li><div class="about-workflow-marker"><span aria-hidden="true">03</span><svg class="i" aria-hidden="true"><use href="#fld"/></svg></div><div class="about-workflow-copy"><h3>Build each chapter</h3><p>Students move through Chapters 1–5, with approval unlocking each next step.</p><details class="about-step-detail"><summary>Chapter sequencing</summary><p>Title approval unlocks Chapter 1. Each approved chapter unlocks the next; submitted versions stay locked during review.</p></details></div></li>
              <li><div class="about-workflow-marker"><span aria-hidden="true">04</span><svg class="i" aria-hidden="true"><use href="#bld"/></svg></div><div class="about-workflow-copy"><h3>Final manuscript review</h3><p>After all five student chapters are approved, submit the complete manuscript; an authorized reviewer’s approval completes the research.</p><details class="about-step-detail"><summary>Completion &amp; outputs</summary><p>Student research is complete only after an authorized reviewer approves the final manuscript. Publications and IP records are related outputs, not additional chapter stages.</p></details></div></li>
            </ol>
          </div>
        </section>

        <!-- ABOUT THE SYSTEM -->
        <section class="section about-section about-overview" data-reveal aria-labelledby="about-record-title">
            <div class="container">
                <div class="about-overview-heading">
                    <p class="about-process-eyebrow">A record you can follow</p>
                    <h2 class="section-title" id="about-record-title">Every revision.<br>One clear record.</h2>
                    <p class="about-overview-lead">Students follow the title-and-chapter submission path through final-manuscript review. Faculty have separate research, advising, engagement, and document tools.</p>
                    <div class="about-overview-audience"><svg class="i" aria-hidden="true"><use href="#grp"/></svg><span>For students and faculty</span></div>
                </div>
                <div class="about-record-principles">
                    <article class="about-record-principle">
                        <span class="about-record-icon" aria-hidden="true"><svg class="i"><use href="#doc"/></svg></span>
                        <div><h3>Your work, preserved</h3><p>Students save drafts explicitly. Each submission becomes a locked version, so later revisions preserve the record of earlier work.</p></div>
                    </article>
                    <article class="about-record-principle">
                        <span class="about-record-icon" aria-hidden="true"><svg class="i"><use href="#mal"/></svg></span>
                        <div><h3>Review with a clear next step</h3><p>Administrators and research staff authorized for the relevant action approve student submissions or return them with comments. Students revise and resubmit.</p></div>
                    </article>
                    <article class="about-record-principle">
                        <span class="about-record-icon" aria-hidden="true"><svg class="i"><use href="#fld"/></svg></span>
                        <div><h3>Progress you can trace</h3><p>For students, title approval opens Chapter 1; each approved chapter unlocks the next. After five chapters are approved, final-manuscript review begins, and its approval completes the research. Publication and IP records track related outputs.</p></div>
                    </article>
                </div>
            </div>
        </section>

        <!-- KEY FEATURES -->
        <section id="research-features" class="section about-section about-capabilities" data-reveal>
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">Tools for the work along the way</h2>
                    <p class="section-desc">The practical details of submitting, reviewing, and keeping research on record.</p>
                </div>
                <div class="features-grid">
                    <a class="feature-card" data-feature-message="Students submit title proposals with a required proposal document for authorized review." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#fld"/></svg></span><h3>Student Proposal Submission</h3><p>Students submit title proposals with a required proposal document for authorized review.</p></a>
                    <a class="feature-card" data-feature-message="Authorized administrators and research staff review student title and chapter submissions, share feedback, and preserve revision history." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#doc"/></svg></span><h3>Student Submission Review</h3><p>Administrators and research staff authorized for the relevant action review locked student title and chapter versions, then approve or return them with comments and preserved revision history.</p></a>
                    <a class="feature-card" data-feature-message="Students submit chapter documents after title approval, with each approved chapter unlocking the next." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#cap"/></svg></span><h3>Student Chapter Management</h3><p>After title approval, students submit Chapters 1–5 in sequence, with each approved chapter unlocking the next submission.</p></a>
                    <a class="feature-card" data-feature-message="Student title approval unlocks Chapter 1; each chapter unlocks the next only after approval." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#chk"/></svg></span><h3>Student Chapter Sequencing</h3><p>For student projects, title approval unlocks Chapter 1; each subsequent chapter opens after approval of the previous one.</p></a>
                    <a class="feature-card" data-feature-message="Students and faculty can submit midway progress and terminal reports for authorized staff review." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#arr"/></svg></span><h3>Progress &amp; Terminal Reports</h3><p>Students and faculty can submit midway progress and terminal reports for authorized staff review.</p></a>
                    <a class="feature-card" data-feature-message="Browse completed research and scholarly publication records. Faculty can prepare and generate DOCX documents from saved research records using approved EARIST templates." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#bld"/></svg></span><h3>Research Archive &amp; Journal</h3><p>Browse completed research and scholarly publication records. Faculty can prepare and generate DOCX documents from saved research records using approved EARIST templates.</p></a>
                    <a class="feature-card" data-feature-message="Eligible student teams can include co-researchers; faculty advising follows a separate student-request and faculty-decision process." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#grp"/></svg></span><h3>Student Teams &amp; Faculty Advising</h3><p>Eligible student teams can include co-researchers; faculty advising follows a separate student-request and faculty-decision process.</p></a>
                    <a class="feature-card" data-feature-message="The Research Office records capacity-building activities, while researchers receive relevant decision and revision updates." href="<?php echo SITE_URL; ?>/public/login.php#register"><span class="about-feature-symbol" aria-hidden="true"><svg class="i"><use href="#mal"/></svg></span><h3>Research Activities &amp; Updates</h3><p>The Research Office records seminars, trainings, workshops, colloquia, and conferences. Researchers receive relevant updates about decisions and revisions.</p></a>
                </div>
                <dialog class="about-feature-dialog" id="research-feature-dialog" aria-labelledby="research-feature-dialog-title" aria-describedby="research-feature-dialog-message">
                    <p class="about-feature-dialog-eyebrow">Research at EARIST</p>
                    <h2 id="research-feature-dialog-title" tabindex="-1">Ready to start your research journey?</h2>
                    <p id="research-feature-dialog-message">Choose a feature to see how it can support your research.</p>
                    <div class="about-feature-dialog-actions">
                        <a class="btn f" href="<?php echo SITE_URL; ?>/public/login.php#register">Get Started</a>
                        <button class="btn n" type="button" data-about-feature-dismiss>Not Now</button>
                    </div>
                </dialog>
            </div>
        </section>

        <!-- WHO CAN USE THE SYSTEM -->
        <section id="research-roles" class="section about-section about-roles" data-reveal>
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">Distinct roles. Clear responsibilities.</h2>
                    <p class="section-desc">Each person sees the tools and records their role permits.</p>
                </div>
                <div class="about-role-directory">
                    <article class="about-role-row" data-about-role="student" aria-labelledby="about-role-student">
                        <div class="about-role-identity">
                            <span class="about-role-number" aria-hidden="true">01</span>
                            <h3 id="about-role-student">Student researcher</h3>
                        </div>
                        <p class="about-role-purpose">Develop your research from title draft to approved chapters.</p>
                        <ul class="about-role-responsibilities" aria-label="Student researcher responsibilities">
                            <li>Draft and submit</li>
                            <li>Revise with comments</li>
                            <li>Track chapter progress</li>
                        </ul>
                    </article>
                    <article class="about-role-row" data-about-role="faculty" aria-labelledby="about-role-faculty">
                        <div class="about-role-identity">
                            <span class="about-role-number" aria-hidden="true">02</span>
                            <h3 id="about-role-faculty">Faculty researcher</h3>
                        </div>
                        <p class="about-role-purpose">Faculty manage their research, respond to student adviser requests, and maintain faculty research and engagement records.</p>
                        <ul class="about-role-responsibilities" aria-label="Faculty researcher responsibilities">
                            <li>Maintain a faculty research portfolio</li>
                            <li>Accept or decline student adviser requests</li>
                            <li>Prepare EARIST DOCX documents</li>
                        </ul>
                    </article>
                    <article class="about-role-row" data-about-role="staff" aria-labelledby="about-role-staff">
                        <div class="about-role-identity">
                            <span class="about-role-number" aria-hidden="true">03</span>
                            <h3 id="about-role-staff">Research office staff</h3>
                        </div>
                        <p class="about-role-purpose">Coordinate records and reviews within the permissions assigned to you.</p>
                        <ul class="about-role-responsibilities" aria-label="Research office staff responsibilities">
                            <li>Coordinate records</li>
                            <li>Review when authorized</li>
                            <li>Manage permitted pages</li>
                        </ul>
                    </article>
                    <article class="about-role-row" data-about-role="admin" aria-labelledby="about-role-admin">
                        <div class="about-role-identity">
                            <span class="about-role-number" aria-hidden="true">04</span>
                            <h3 id="about-role-admin">Administrator</h3>
                        </div>
                        <p class="about-role-purpose">Oversee research decisions, institutional records, and staff access.</p>
                        <ul class="about-role-responsibilities" aria-label="Administrator responsibilities">
                            <li>Approve or return</li>
                            <li>Manage access</li>
                            <li>Oversee records</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <!-- BENEFITS -->
        <section class="section about-section about-benefits" data-reveal aria-labelledby="about-standards-title">
            <div class="container about-standards-layout">
                <div class="section-header about-standards-intro">
                    <p class="about-process-eyebrow">Six shared standards</p>
                    <h2 class="section-title" id="about-standards-title">What stays<br>consistent.</h2>
                    <p class="section-desc">Permissions and traceable records support the distinct work of each RMS role.</p>
                    <div class="about-standards-signature"><span aria-hidden="true"></span>Across the research process</div>
                </div>
                <div class="about-benefits-grid">
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#doc"/></svg>
                        <div><h3>Decisions with context</h3><p>Keep approval or return decisions, review comments, and submitted versions in the research record.</p></div>
                    </article>
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#arr"/></svg>
                        <div><h3>Role-specific research paths</h3><p>Students use the title-and-chapter submission process. Faculty use separate research portfolio, advising, engagement, and document tools.</p></div>
                    </article>
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#usr"/></svg>
                        <div><h3>Access by responsibility</h3><p>Records and actions follow roles, project access rules, and assigned staff permissions.</p></div>
                    </article>
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#chk"/></svg>
                        <div><h3>Visible chapter progress</h3><p>Student researchers follow title status and chapter approvals to identify the next permitted submission step.</p></div>
                    </article>
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#grp"/></svg>
                        <div><h3>Connected research teams</h3><p>Eligible student co-researchers are linked to their projects; faculty adviser assignments follow student requests and faculty decisions.</p></div>
                    </article>
                    <article class="feature-card about-standard">
                        <svg class="i about-standard-icon" aria-hidden="true"><use href="#fld"/></svg>
                        <div><h3>Outputs kept on record</h3><p>Maintain publication, repository, and intellectual-property records for research outputs.</p></div>
                    </article>
                </div>
            </div>
        </section>

        <!-- STATISTICS -->
        <section id="research-activity" class="about-stats-band" data-reveal aria-labelledby="about-activity-title">
            <div class="container">
                <div class="about-stats-heading">
                    <div>
                        <p class="about-stats-eyebrow">RMS / Research activity</p>
                        <h2 id="about-activity-title">Research in the system</h2>
                    </div>
                    <p class="stats-note">A snapshot of the research and people recorded in RMS.</p>
                </div>
                <div class="about-stats-grid">
                    <div class="about-stat about-stat--total">
                        <div class="about-stat-heading"><span>Collection</span><svg class="i" aria-hidden="true"><use href="#doc"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_total_research, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Total research</h3>
                        <p class="about-stat-description">Research records across all stages.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Research status</span><svg class="i" aria-hidden="true"><use href="#fld"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_completed, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Completed studies</h3>
                        <p class="about-stat-description">Records marked completed or archived.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Research status</span><svg class="i" aria-hidden="true"><use href="#chk"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_under_review, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Under review</h3>
                        <p class="about-stat-description">Submissions recorded in review statuses.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Research status</span><svg class="i" aria-hidden="true"><use href="#arr"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_approval_rate, ENT_QUOTES, 'UTF-8'); ?><span class="about-stat-unit">%</span></div>
                        <h3 class="about-stat-label">Approval rate</h3>
                        <p class="about-stat-description">Approved, completed or archived records; excludes drafts.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Our researchers</span><svg class="i" aria-hidden="true"><use href="#cap"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_active_students, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Active students</h3>
                        <p class="about-stat-description">Student accounts currently active.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Our researchers</span><svg class="i" aria-hidden="true"><use href="#usr"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_faculty_advisers, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Active faculty</h3>
                        <p class="about-stat-description">Faculty accounts currently active.</p>
                    </div>
                    <div class="about-stat">
                        <div class="about-stat-heading"><span>Research support</span><svg class="i" aria-hidden="true"><use href="#bld"/></svg></div>
                        <div class="about-stat-value"><?php echo htmlspecialchars((string) $stat_research_staff, ENT_QUOTES, 'UTF-8'); ?></div>
                        <h3 class="about-stat-label">Research office</h3>
                        <p class="about-stat-description">Active research staff and administrators.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="contact-us" class="about-section about-contact" data-reveal aria-labelledby="contact-title">
            <div class="container">
                <div class="about-contact-heading">
                    <p class="about-process-eyebrow">Contact the Research Office</p>
                    <h2 id="contact-title">We’re here to help with your research journey.</h2>
                    <p>Send a message to the RMS team or reach your department’s research coordinator.</p>
                </div>
                <div class="about-contact-grid">
                    <div class="about-contact-details">
                        <h3>Contact information</h3>
                        <p class="about-contact-intro">Get in touch or visit the research office during office hours.</p>
                        <div class="about-contact-detail">
                            <span aria-hidden="true">📧</span>
                            <div><strong>Email</strong><span><?php echo contact_escape(rmsSetting('contact_email')); ?></span></div>
                        </div>
                        <div class="about-contact-detail">
                            <span aria-hidden="true">📞</span>
                            <div><strong>Phone</strong><span><?php echo contact_escape(rmsSetting('contact_phone')); ?></span></div>
                        </div>
                        <div class="about-contact-detail">
                            <span aria-hidden="true">📍</span>
                            <div><strong>Address</strong><span><?php echo contact_escape(rmsSetting('contact_address')); ?></span></div>
                        </div>
                        <div class="about-contact-detail">
                            <span aria-hidden="true">🕒</span>
                            <div><strong>Office hours</strong><span>Mon–Fri, 8:00 AM – 5:00 PM</span></div>
                        </div>
                    </div>
                    <div class="about-contact-message">
                        <h3>Send a message</h3>
                        <p class="about-contact-intro">Use this form to send a message to the RMS team.</p>
                        <?php if ($success): ?>
                            <div class="about-contact-alert about-contact-alert--success" role="status">Your message has been received.</div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                            <div class="about-contact-alert about-contact-alert--error" role="alert">
                                <ul>
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo contact_escape($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form class="about-contact-form" method="POST" action="about-us.php#contact-us">
                            <?php echo csrfField(); ?>
                            <div class="about-contact-field">
                                <label for="contact-name">Name</label>
                                <input class="about-contact-control" type="text" id="contact-name" name="name" required value="<?php echo contact_escape($name); ?>">
                            </div>
                            <div class="about-contact-field">
                                <label for="contact-email">Email</label>
                                <input class="about-contact-control" type="email" id="contact-email" name="email" required value="<?php echo contact_escape($email); ?>">
                            </div>
                            <div class="about-contact-field">
                                <label for="contact-concern-type">Concern type</label>
                                <select class="about-contact-control" id="contact-concern-type" name="concern_type">
                                    <?php foreach ($concern_types as $type): ?>
                                        <option value="<?php echo contact_escape($type); ?>"<?php echo $concern_type === $type ? ' selected' : ''; ?>><?php echo contact_escape($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="about-contact-field">
                                <label for="contact-message">Message</label>
                                <textarea class="about-contact-control" id="contact-message" name="message" rows="6" required><?php echo contact_escape($message); ?></textarea>
                            </div>
                            <button class="btn f" type="submit">Send message</button>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <!-- CALL TO ACTION -->
        <section class="section about-section about-cta" data-reveal>
            <div class="container">
                <div class="bn"><h2>Ready to start your research journey?</h2><p>Join EARIST CAVITE Research Management System and be part of our growing research community.</p><a class="btn" href="<?php echo SITE_URL; ?>/public/login.php">Sign In</a><a class="btn f" href="<?php echo SITE_URL; ?>/public/login.php#register">Create Account</a></div>
            </div>
        </section>

        <!-- FOOTER -->
        </main>
        <?php require __DIR__ . '/../includes/public-footer.php'; ?>
<script src="assets/js/landing-v2.js?v=<?php echo filemtime(__DIR__ . '/assets/js/landing-v2.js'); ?>" defer></script>
<script src="assets/js/about-editorial.js?v=<?php echo filemtime(__DIR__ . '/assets/js/about-editorial.js'); ?>" defer></script>
<script src="../js/public-motion.js?v=<?php echo filemtime(__DIR__ . '/../js/public-motion.js'); ?>" defer></script>
</body>
</html>
