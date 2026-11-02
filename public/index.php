<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    $user = getCurrentUser();
    $role = $user['role'] ?? 'student';
    $dashboardMap = [
        'student'        => '../pages/student/student-dashboard.php',
        'faculty'        => '../pages/faculty/faculty-dashboard.php',
        'research_staff' => '../pages/staff/staff-dashboard.php',
        'admin'          => '../pages/admin/admin-dashboard.php',
    ];
    header('Location: ' . ($dashboardMap[$role] ?? '../pages/student/student-dashboard.php'));
    exit();
}

$siteTitle = htmlspecialchars(rmsSetting('site_title'), ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
          <title><?php echo $siteTitle; ?> — EARIST Research Platform</title>
          <meta name="description" content="EARIST Research Management System: submit and monitor research, maintain activity and publication records, and access research archives and intellectual-property records.">
          <link rel="preconnect" href="https://fonts.googleapis.com">
          <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
          <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
          <link rel="stylesheet" href="assets/css/public-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-theme.css'); ?>">
          <link rel="stylesheet" href="assets/css/landing-v2.css?v=<?php echo filemtime(__DIR__ . '/assets/css/landing-v2.css'); ?>">
          <link rel="stylesheet" href="assets/css/public-header.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-header.css'); ?>">
          <link rel="stylesheet" href="assets/css/public-footer.css?v=<?php echo filemtime(__DIR__ . '/assets/css/public-footer.css'); ?>">
          </head>
          <body>
        <svg class="icon-defs" width="0" height="0" aria-hidden="true"><defs>
          <linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7C3AED"/><stop offset=".6" stop-color="#2563EB"/><stop offset="1" stop-color="#06B6D4"/></linearGradient>
          <symbol id="arr" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
          <symbol id="usr" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></symbol>
          <symbol id="usp" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3-6 8-6M18 14v6M15 17h6"/></symbol>
          <symbol id="grp" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M18 14.5c2.5.3 4 2 4 5"/></symbol>
          <symbol id="doc" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></symbol>
          <symbol id="bld" viewBox="0 0 24 24"><path d="M3 10l9-6 9 6M5 10v9M9 10v9M15 10v9M19 10v9M3 21h18"/></symbol>
          <symbol id="gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/><circle cx="12" cy="12" r="6.5"/></symbol>
          <symbol id="bok" viewBox="0 0 24 24"><path d="M3 5c3-1 6-1 9 1 3-2 6-2 9-1v14c-3-1-6-1-9 1-3-2-6-2-9-1z"/><path d="M12 6v14"/></symbol>
          <symbol id="blb" viewBox="0 0 24 24"><path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10c1 1 1 2 1 3h6c0-1 0-2 1-3a6 6 0 0 0-4-10z"/></symbol>
          <symbol id="srch" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M16 16l5 5"/></symbol>
          <symbol id="upl" viewBox="0 0 24 24"><path d="M7 18a4 4 0 0 1 0-8 5 5 0 0 1 10 0 4 4 0 0 1 0 8M12 12v8M9 15l3-3 3 3"/></symbol>
          <symbol id="fld" viewBox="0 0 24 24"><path d="M3 6h6l2 2h10v11H3z"/></symbol>
          <symbol id="cap" viewBox="0 0 24 24"><path d="M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5"/></symbol>
          <symbol id="chk" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
          <symbol id="mal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></symbol>
          <symbol id="tel" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2 1c1 3 3 5 6 6l1-2 5 2v4c-9 1-17-7-16-16z"/></symbol>
          <symbol id="pin" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></symbol>
          </defs></svg>

          <?php $publicHeaderActivePage = 'home'; require __DIR__ . '/../includes/public-header.php'; ?>

          <main>
          <section class="hero" id="about">
          <svg class="sw" viewBox="0 0 460 330" fill="none" aria-hidden="true"><path d="M0 40C120 90 150 220 230 330H0z" fill="url(#lg)" opacity=".18"/><path d="M0 150C80 190 120 260 160 330" stroke="url(#lg)" stroke-width="22" opacity=".5"/><path d="M0 230C50 250 90 290 110 330" stroke="#7C3AED" stroke-width="16" opacity=".6"/></svg>
          <div class="w hg">
          <div class="rv">
          <h1>Make research <span>easier</span> with a clear and guided process</h1>
          <p class="lead">One research workspace for students and faculty to submit research, monitor progress, and maintain related activity, archive, publication, and intellectual-property records.</p>
          <div class="cta"><a class="btn f" href="login.php#register"><svg class="i" aria-hidden="true"><use href="#usp"/></svg>Start your Research Today</a></div>
          </div>
          <a class="vis rv" href="about-us.php#research-roles" aria-label="View the research roles section">
          <div class="mock"><div class="dots"><i></i><i></i><i></i><em>rms.earist.edu.ph</em></div>
          <div class="wf"><h3><svg class="i" aria-hidden="true"><use href="#cap"/></svg>Institutional Workflow<b>Active</b></h3>Title review · Sequential Chapters 1–5 · Final manuscript approval · Research records<div class="pb"><i></i></div></div>
          <div class="rg">
          <div class="rc student"><h3><svg class="i" aria-hidden="true"><use href="#cap"/></svg>Student<svg class="i a" aria-hidden="true"><use href="#arr"/></svg></h3>Submit titles and chapters, track revisions and progress.</div>
          <div class="rc faculty"><h3><svg class="i" aria-hidden="true"><use href="#grp"/></svg>Faculty<svg class="i a" aria-hidden="true"><use href="#arr"/></svg></h3>Submit research, support advisees, and monitor project progress.</div>
          <div class="rc staff"><h3><svg class="i" aria-hidden="true"><use href="#bld"/></svg>Staff<svg class="i a" aria-hidden="true"><use href="#arr"/></svg></h3>Review submissions and manage research records.</div>
          <div class="rc admin"><h3><svg class="i" aria-hidden="true"><use href="#gear"/></svg>Admin<svg class="i a" aria-hidden="true"><use href="#arr"/></svg></h3>Manage users, reports, and system settings.</div>
          </div></div>
          </a>
          </div></section>

          <section class="sec" id="features"><div class="w">
          <div class="sh rv"><h2>Everything in one workspace</h2><span class="tag">Research &bull; Collaboration &bull; Innovation</span></div>
          <div class="fg">
          <div class="fc capacity rv"><div class="ic"><svg class="i" aria-hidden="true"><use href="#grp"/></svg></div><h3>Capacity Building</h3><p>Maintain records of seminars, trainings, workshops, colloquia, and conferences.</p><span>LEARN &amp; GROW</span></div>
          <div class="fc development rv"><div class="ic"><svg class="i" aria-hidden="true"><use href="#doc"/></svg></div><h3>Research Development</h3><p>Submit research titles, monitor review decisions, and track progress through the workflow.</p><span>PLAN &amp; MONITOR</span></div>
          <div class="fc implementation rv"><div class="ic"><svg class="i" aria-hidden="true"><use href="#gear"/></svg></div><h3>Implementation &amp; Outputs</h3><p>Organize research records and retrieve available documents through archive views.</p><span>EXECUTE &amp; DELIVER</span></div>
          <div class="fc publication rv"><div class="ic"><svg class="i" aria-hidden="true"><use href="#bok"/></svg></div><h3>Publication &amp; Dissemination</h3><p>Maintain scholarly publication records for institutional documentation and monitoring.</p><span>SHARE KNOWLEDGE</span></div>
          <div class="fc ip rv"><div class="ic"><svg class="i" aria-hidden="true"><use href="#blb"/></svg></div><h3>Intellectual Property &amp; Utilization</h3><p>Document copyrights, patents, utility models, and innovation-related records.</p><span>PROTECT &amp; INNOVATE</span></div>
          </div></div></section>

          <section class="sec hw" id="archive"><div class="w">
          <div class="sh rv"><h2>How It Works</h2><span class="tag">From title to research outputs</span></div>
          <div class="sg rv">
          <div><div class="sr"><b>1</b><svg class="i" aria-hidden="true"><use href="#doc"/></svg><u></u></div><h3>Submit Research Title</h3><p>Students and faculty draft and submit a research title for review.</p></div>
          <div><div class="sr"><b>2</b><svg class="i" aria-hidden="true"><use href="#srch"/></svg><u></u></div><h3>Title Review</h3><p>Administrators and authorized staff approve or return titles with comments and recommendations.</p></div>
          <div><div class="sr"><b>3</b><svg class="i" aria-hidden="true"><use href="#doc"/></svg><u></u></div><h3>Chapters 1–5</h3><p>Submit Chapters 1–5 in sequence; approval unlocks the next.</p></div>
          <div><div class="sr"><b>4</b><svg class="i" aria-hidden="true"><use href="#upl"/></svg><u></u></div><h3>Final Manuscript &amp; Outputs</h3><p>After five approved chapters, submit the complete manuscript. Reviewer approval completes the research.</p></div>
          </div>
          </div></section>

          <?php require __DIR__ . '/../includes/public-footer.php'; ?>

          <script src="assets/js/landing-v2.js" defer></script>
          <script src="../js/public-motion.js?v=<?php echo filemtime(__DIR__ . '/../js/public-motion.js'); ?>" defer></script>
          </body>
          </html>
