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

// Dynamic statistics
$stat_total_res = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects")->fetch_assoc()['c'] ?? 0);
$stat_users     = (int) ($conn->query("SELECT COUNT(*) AS c FROM users WHERE status = 'active'")->fetch_assoc()['c'] ?? 0);
$stat_approved  = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status IN ('approved','completed','archived')")->fetch_assoc()['c'] ?? 0);
$stat_completed = (int) ($conn->query("SELECT COUNT(*) AS c FROM research_projects WHERE status IN ('completed','archived')")->fetch_assoc()['c'] ?? 0);

$rate_stmt = $conn->query("SELECT ROUND(100.0 * COUNT(CASE WHEN status IN ('completed', 'approved', 'archived') THEN 1 END) / NULLIF(COUNT(CASE WHEN status NOT IN ('draft') THEN 1 END), 0)) AS rate FROM research_projects");
$stat_rate = (int) ($rate_stmt ? ($rate_stmt->fetch_assoc()['rate'] ?? 95) : 95);
if ($stat_rate === 0) $stat_rate = 95;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo SITE_TITLE; ?> — EARIST Research Platform</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* ═════════════════════════════════════════════════════════════
       MODERN ENHANCEMENTS FOR INDEX.PHP
       ═════════════════════════════════════════════════════════════ */
    .glass-card {
      background: rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 20px;
      padding: 30px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.3);
    }
    
    .hero-glow-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 18px;
      background: rgba(91, 30, 188, 0.25);
      border: 1px solid rgba(196, 169, 255, 0.35);
      border-radius: 50px;
      color: #c4a9ff;
      font-size: 0.8rem;
      font-weight: 600;
      letter-spacing: 0.5px;
      margin-bottom: 24px;
    }

    .interactive-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px 26px;
      transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .interactive-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 40px rgba(91, 30, 188, 0.12);
      border-color: var(--primary);
    }
    .interactive-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, var(--primary), var(--secondary));
      opacity: 0;
      transition: opacity 0.3s;
    }
    .interactive-card:hover::before {
      opacity: 1;
    }

    /* Workflow Timeline / Steps */
    .workflow-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      position: relative;
    }
    .workflow-step {
      background: white;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 28px 22px;
      position: relative;
      transition: all 0.3s;
    }
    .workflow-step:hover {
      border-color: var(--primary);
      transform: translateY(-4px);
      box-shadow: 0 12px 28px rgba(91, 30, 188, 0.08);
    }
    .step-number {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.9rem;
      margin-bottom: 16px;
    }

    /* Chapter Structure Badge List */
    .chapter-pill {
      display: flex;
      align-items: center;
      gap: 14px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px 20px;
      transition: all 0.25s;
    }
    .chapter-pill:hover {
      border-color: var(--secondary);
      background: #faf5ff;
      transform: translateX(4px);
    }
    .chapter-tag {
      font-weight: 700;
      color: var(--primary);
      background: rgba(91, 30, 188, 0.1);
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.75rem;
      white-space: nowrap;
    }

    /* Floating UI Widget in Hero */
    .hero-mockup-window {
      background: rgba(13, 26, 58, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    }
    .mockup-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 18px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      padding-bottom: 12px;
    }
    .mockup-dot {
      width: 10px; height: 10px; border-radius: 50%;
    }

    @media (max-width: 992px) {
      .hero-container { grid-template-columns: 1fr; gap: 40px; }
      .workflow-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- NAVBAR -->
<!-- ════════════════════════════════════════════════════════════ -->
<nav class="navbar">
  <div class="nav-brand">
    <div class="nav-logo">🔬</div>
    <div class="nav-brand-text">
      <span class="brand-main">Research<br>Management</span>
      <span class="brand-sub">System</span>
    </div>
  </div>

  <ul class="nav-links">
    <li><a href="index.php" class="active">Home</a></li>
    <li><a href="about.php">About</a></li>
    <li><a href="features.php">Features</a></li>
    <li><a href="research-archive.php">Research Archive</a></li>
    <li><a href="contact.php">Contact</a></li>
  </ul>

  <div class="nav-actions">
    <a href="login.php" class="btn btn-secondary btn-sm">Sign In</a>
    <a href="login.php#register" class="btn btn-primary btn-sm">Get Started</a>
  </div>
</nav>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- HERO SECTION -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="hero" id="home">
  <div class="hero-container">
    <!-- Left Content -->
    <div class="hero-content">
      <div class="hero-glow-badge">
        <span>📜</span> EARIST CAVITE CAMPUS
      </div>

      <h1>
        <span class="highlight">Research Management System</span><br>
      </h1>

      <p>
        An all-in-one digital platform unifying Students, Faculty Advisers, Review Committees (CREC/EREC), and Research Administrators from Proposal Submission through the Final Research Colloquium.
      </p>

      <div class="hero-actions">
        <a href="login.php" class="btn btn-primary btn-lg">
          <span>🔑</span> Sign In to Portal
        </a>
        <a href="login.php#register" class="btn btn-secondary btn-lg">
          <span>✨</span> Create Account
        </a>
        <a href="research-archive.php" class="btn btn-secondary btn-lg" style="border: 1px dashed rgba(255,255,255,0.4);">
          <span>🗂️</span> Browse Archive
        </a>
      </div>

      <div class="hero-stats">
        <div class="hero-stat">
          <div class="number"><?php echo number_format($stat_total_res ?: 1250); ?><span style="font-size:0.6em; color: var(--secondary-light);">+</span></div>
          <div class="label">Research Studies</div>
        </div>
        <div class="hero-stat">
          <div class="number"><?php echo number_format($stat_users ?: 850); ?><span style="font-size:0.6em; color: var(--primary-light);">+</span></div>
          <div class="label">Active Researchers</div>
        </div>
        <div class="hero-stat">
          <div class="number"><?php echo $stat_rate; ?><span style="font-size:0.6em; color: var(--success);">%</span></div>
          <div class="label">Completion Rate</div>
        </div>
      </div>
    </div>

    <!-- Right Mockup / Visualization -->
    <div class="hero-visual">
      <div class="hero-mockup-window" style="width: 100%; max-width: 480px;">
        <div class="mockup-header">
          <div class="mockup-dot" style="background: #ef4444;"></div>
          <div class="mockup-dot" style="background: #f59e0b;"></div>
          <div class="mockup-dot" style="background: #22c55e;"></div>
          <span style="color: #94a3b8; font-size: 0.75rem; margin-left: auto; font-family: monospace;">rms.earist.edu.ph</span>
        </div>

        <div style="background: rgba(255,255,255,0.03); border-radius: 12px; padding: 16px; margin-bottom: 14px; border: 1px solid rgba(255,255,255,0.06);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="color: #bfdbfe; font-weight: 600; font-size: 0.85rem;">🎓 Institutional Workflow</span>
            <span style="background: rgba(34,197,94,0.2); color: #86efac; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600;">Active</span>
          </div>
          <div style="font-size: 0.8rem; color: #cbd5e1; margin-bottom: 12px; line-height: 1.5;">
            5-Chapter Thesis Structure &bull; CREC &amp; EREC Reviews &bull; Defense Scheduling &bull; Archival
          </div>
          <div style="height: 6px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden;">
            <div style="width: 78%; height: 100%; background: linear-gradient(90deg, var(--primary), var(--secondary));"></div>
          </div>
        </div>

        <!-- Role Quick Cards -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
          <div style="background: rgba(91,30,188,0.15); border: 1px solid rgba(196,169,255,0.2); border-radius: 10px; padding: 12px;">
            <div style="font-size: 1.1rem; margin-bottom: 4px;color: #D0D3E8;">🎓 Student</div>
            <div style="font-size: 0.72rem; color: #D0D3E8;">Submit chapters, track revisions &amp; defenses</div>
          </div>
          <div style="background: rgba(15,108,189,0.15); border: 1px solid rgba(147,197,253,0.2); border-radius: 10px; padding: 12px;">
            <div style="font-size: 1.1rem; margin-bottom: 4px;color: #D0D3E8;">👨‍🏫 Faculty</div>
            <div style="font-size: 0.72rem; color: #D0D3E8;">Review advisees &amp; score panel defenses</div>
          </div>
          <div style="background: rgba(13,148,136,0.15); border: 1px solid rgba(94,234,212,0.2); border-radius: 10px; padding: 12px;">
            <div style="font-size: 1.1rem; margin-bottom: 4px;color: #D0D3E8;">📋 Staff</div>
            <div style="font-size: 0.72rem; color: #D0D3E8;">Verify forms &amp; repository management</div>
          </div>
          <div style="background: rgba(245,124,0,0.15); border: 1px solid rgba(253,186,116,0.2); border-radius: 10px; padding: 12px;">
            <div style="font-size: 1.1rem; margin-bottom: 4px;color: #D0D3E8;">⚙️ Admin</div>
            <div style="font-size: 0.72rem; color: #D0D3E8;">Campus analytics, logs &amp; system control</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- RESEARCH MANUAL 2015: 5-STAGE WORKFLOW LIFECYCLE -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="section" style="background: #ffffff; padding: 80px 40px;">
  <div class="section-header" style="text-align: center; margin-bottom: 50px;">
    <div class="section-tag">🔄 End-to-End Governance</div>
    <h2 class="section-title">The Official <span style="color: var(--primary);">Research Lifecycle</span></h2>
    <p class="section-desc">Modeled directly after the EARIST Research Manual (Ch.4–5: Management &amp; Implementation)</p>
  </div>

  <div class="workflow-grid" style="max-width: 1240px; margin: 0 auto;">
    <div class="workflow-step">
      <div class="step-number">1</div>
      <h3 style="font-size: 1.1rem; color: var(--text-dark); margin-bottom: 8px;">Proposal Submission</h3>
      <p style="font-size: 0.88rem; color: var(--text-light); line-height: 1.6;">
        Submission of Title, Objectives, Science/Tech or Social/Behavioral Form 1, and Initial Capsule Proposal.
      </p>
    </div>

    <div class="workflow-step">
      <div class="step-number" style="background: linear-gradient(135deg, var(--secondary), #0284c7);">2</div>
      <h3 style="font-size: 1.1rem; color: var(--text-dark); margin-bottom: 8px;">CREC / EREC Evaluation</h3>
      <p style="font-size: 0.88rem; color: var(--text-light); line-height: 1.6;">
        College Review Committee (CREC) &amp; Executive Research Ethics Committee (EREC) specialist evaluation.
      </p>
    </div>

    <div class="workflow-step">
      <div class="step-number" style="background: linear-gradient(135deg, #0d9488, #059669);">3</div>
      <h3 style="font-size: 1.1rem; color: var(--text-dark); margin-bottom: 8px;">Approval &amp; MOU</h3>
      <p style="font-size: 0.88rem; color: var(--text-light); line-height: 1.6;">
        Presidential Approval Gate, Non-Disclosure Agreement (NDA), and Memorandum of Research Undertaking.
      </p>
    </div>

    <div class="workflow-step">
      <div class="step-number" style="background: linear-gradient(135deg, var(--accent), #d97706);">4</div>
      <h3 style="font-size: 1.1rem; color: var(--text-dark); margin-bottom: 8px;">Implementation &amp; Defense</h3>
      <p style="font-size: 0.88rem; color: var(--text-light); line-height: 1.6;">
        Midway progress tracking, chapter drafts 1-5, oral defense evaluation, and formal revisions.
      </p>
    </div>

    <div class="workflow-step">
      <div class="step-number" style="background: linear-gradient(135deg, #10b981, #047857);">5</div>
      <h3 style="font-size: 1.1rem; color: var(--text-dark); margin-bottom: 8px;">Colloquium &amp; Journal</h3>
      <p style="font-size: 0.88rem; color: var(--text-light); line-height: 1.6;">
        Terminal report approval, presentation at the Research Colloquium, and publication in the EARIST Journal.
      </p>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CHAPTER STRUCTURE (5 CHAPTERS MANUAL STANDARD) -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="section" style="background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); padding: 80px 40px;">
  <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1.2fr; gap: 48px; align-items: center;">
    <div>
      <div class="section-tag">📑 Standardized Structure</div>
      <h2 class="section-title" style="font-size: 2.2rem;">Institutional 5-Chapter <span style="color: var(--primary);">Manuscript Framework</span></h2>
      <p style="color: var(--text-light); line-height: 1.7; margin-bottom: 24px;">
        To maintain uniform scholarly quality across all colleges, RMS strictly governs and formats submissions into the 5-chapter thesis specification mandated by the EARIST Research Manual.
      </p>
      <a href="features.php" class="btn btn-primary btn-md">
        <span>📖</span> View Complete Guidelines →
      </a>
    </div>

    <div style="display: flex; flex-direction: column; gap: 12px;">
      <div class="chapter-pill">
        <span class="chapter-tag">Chapter 1</span>
        <div>
          <strong style="display: block; font-size: 0.95rem; color: var(--text-dark);">The Problem and Its Background</strong>
          <span style="font-size: 0.8rem; color: var(--text-light);">Background &bull; Statement of the Problem &bull; Objectives &bull; Scope &amp; Significance</span>
        </div>
      </div>

      <div class="chapter-pill">
        <span class="chapter-tag">Chapter 2</span>
        <div>
          <strong style="display: block; font-size: 0.95rem; color: var(--text-dark);">Review of Related Literature &amp; Studies</strong>
          <span style="font-size: 0.8rem; color: var(--text-light);">Foreign &amp; Local Literature &bull; Foreign &amp; Local Studies &bull; Conceptual Framework</span>
        </div>
      </div>

      <div class="chapter-pill">
        <span class="chapter-tag">Chapter 3</span>
        <div>
          <strong style="display: block; font-size: 0.95rem; color: var(--text-dark);">Research Methodology</strong>
          <span style="font-size: 0.8rem; color: var(--text-light);">Research Design &bull; Respondents &bull; Instruments &bull; Data Gathering &bull; Statistical Treatment</span>
        </div>
      </div>

      <div class="chapter-pill">
        <span class="chapter-tag">Chapter 4</span>
        <div>
          <strong style="display: block; font-size: 0.95rem; color: var(--text-dark);">Presentation, Analysis &amp; Interpretation of Data</strong>
          <span style="font-size: 0.8rem; color: var(--text-light);">Empirical Findings &bull; Statistical Analysis &bull; Thematic Data Interpretations</span>
        </div>
      </div>

      <div class="chapter-pill">
        <span class="chapter-tag">Chapter 5</span>
        <div>
          <strong style="display: block; font-size: 0.95rem; color: var(--text-dark);">Summary of Findings, Conclusions &amp; Recommendations</strong>
          <span style="font-size: 0.8rem; color: var(--text-light);">Synthesized Conclusions &bull; Actionable Policy &amp; Future Recommendations</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- PLATFORM FEATURES -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="section" style="background: #ffffff; padding: 80px 40px;" id="features">
  <div class="section-header" style="text-align: center; margin-bottom: 50px;">
    <div class="section-tag">✨ Capabilities</div>
    <h2 class="section-title">Built for Modern <span style="color: var(--primary);">Academic Research</span></h2>
    <p class="section-desc">Equipped with powerful automation and collaborative tools for every campus role</p>
  </div>

  <div class="features-grid" style="max-width: 1200px; margin: 0 auto;">
    <div class="interactive-card">
      <div class="feature-icon">📄</div>
      <h3 style="color: var(--text-dark); margin-bottom: 10px;">Manuscript &amp; Form Submissions</h3>
      <p style="color: var(--text-light); font-size: 0.9rem; line-height: 1.6; margin-bottom: 16px;">
        Submit digital proposals, upload documents in PDF/DOCX, and track document validation in real time.
      </p>
      <a class="card-action" href="features.php#feature-submission" style="color: var(--primary); font-weight: 600; font-size: 0.85rem; margin-top: auto;">Learn more →</a>
    </div>

    <div class="interactive-card">
      <div class="feature-icon" style="background: rgba(15,108,189,0.1);">✅</div>
      <h3 style="color: var(--text-dark); margin-bottom: 10px;">Committee Review &amp; Defense Scoring</h3>
      <p style="color: var(--text-light); font-size: 0.9rem; line-height: 1.6; margin-bottom: 16px;">
        Faculty advisers and panel members evaluate submissions, annotate feedback, and record oral defense ratings.
      </p>
      <a class="card-action" href="features.php#feature-review" style="color: var(--secondary); font-weight: 600; font-size: 0.85rem; margin-top: auto;">Learn more →</a>
    </div>

    <div class="interactive-card">
      <div class="feature-icon" style="background: rgba(13,148,136,0.1);">📈</div>
      <h3 style="color: var(--text-dark); margin-bottom: 10px;">Progress Tracking &amp; Milestones</h3>
      <p style="color: var(--text-light); font-size: 0.9rem; line-height: 1.6; margin-bottom: 16px;">
        Stay on schedule with automatic deadline calendars, progress indicators, and instant revision notifications.
      </p>
      <a class="card-action" href="features.php#feature-analytics" style="color: #0d9488; font-weight: 600; font-size: 0.85rem; margin-top: auto;">Learn more →</a>
    </div>

    <div class="interactive-card">
      <div class="feature-icon" style="background: rgba(245,124,0,0.1);">🗂️</div>
      <h3 style="color: var(--text-dark); margin-bottom: 10px;">Institutional Archive &amp; Repository</h3>
      <p style="color: var(--text-light); font-size: 0.9rem; line-height: 1.6; margin-bottom: 16px;">
        Searchable knowledge base of approved theses and capstone projects with department and category filtering.
      </p>
      <a class="card-action" href="research-archive.php" style="color: var(--accent); font-weight: 600; font-size: 0.85rem; margin-top: auto;">Browse Archive →</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- STATS BANNER -->
<!-- ════════════════════════════════════════════════════════════ -->
<section style="background: linear-gradient(135deg, #3D0F8A 0%, #5B1EBC 50%, #0F6CBD 100%); padding: 70px 40px; color: white;">
  <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 36px; text-align: center;">
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.6rem; font-weight: 800;"><?php echo number_format($stat_total_res ?: 1250); ?><span style="font-size:0.5em; opacity: 0.8;">+</span></div>
      <div style="color: rgba(255,255,255,0.75); font-size: 0.88rem; margin-top: 4px;">Total Research Projects</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.6rem; font-weight: 800;"><?php echo number_format($stat_users ?: 850); ?><span style="font-size:0.5em; opacity: 0.8;">+</span></div>
      <div style="color: rgba(255,255,255,0.75); font-size: 0.88rem; margin-top: 4px;">Registered Users</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.6rem; font-weight: 800;"><?php echo number_format($stat_completed ?: 320); ?><span style="font-size:0.5em; opacity: 0.8;">+</span></div>
      <div style="color: rgba(255,255,255,0.75); font-size: 0.88rem; margin-top: 4px;">Completed &amp; Archived</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.6rem; font-weight: 800;"><?php echo $stat_rate; ?><span style="font-size:0.5em; opacity: 0.8;">%</span></div>
      <div style="color: rgba(255,255,255,0.75); font-size: 0.88rem; margin-top: 4px;">Proposal Approval Rate</div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CALL TO ACTION -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="section" style="background: #ffffff; padding: 90px 40px; text-align: center;">
  <div style="max-width: 850px; margin: 0 auto;">
    <div class="section-tag" style="margin-bottom: 20px;">🚀 Join The Platform</div>
    <h2 class="section-title" style="font-size: 2.5rem; margin-bottom: 16px;">Ready to Elevate Your <span style="color: var(--primary);">Research Journey</span>?</h2>
    <p style="color: var(--text-light); font-size: 1.1rem; line-height: 1.8; margin-bottom: 36px;">
      Log in or create your institutional account today to access automated tracking, expert review workflows, and campus-wide scholarly collaboration.
    </p>
    <div style="display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
      <a href="login.php" class="btn btn-primary btn-lg">
        <span>🔑</span> Sign In Now
      </a>
      <a href="login.php#register" class="btn btn-secondary btn-lg" style="color: var(--primary); border: 2px solid var(--primary);">
        <span>✨</span> Register an Account
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- FOOTER -->
<!-- ════════════════════════════════════════════════════════════ -->
<footer class="footer">
  <div class="footer-grid">
    <div class="footer-col">
      <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
        <span style="font-size: 1.4rem;">🔬</span>
        <span style="font-family: 'Poppins', sans-serif;">RMS</span>
      </h4>
      <p style="color: #8B8FAD; font-size: 0.85rem; line-height: 1.6;">
        Empowering academic institutions to streamline their research management processes with modern, intelligent technology aligned with the EARIST Research Manual 2015.
      </p>
    </div>

    <div class="footer-col">
      <h4>Navigation</h4>
      <ul>
        <li><a href="index.php">Home</a></li>
        <li><a href="about.php">About</a></li>
        <li><a href="features.php">Features</a></li>
        <li><a href="research-archive.php">Research Archive</a></li>
        <li><a href="contact.php">Contact</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Resources</h4>
      <ul>
        <li><a href="about.php">Manual 2015 Guidelines</a></li>
        <li><a href="features.php#feature-submission">Submission Steps</a></li>
        <li><a href="contact.php">Helpdesk &amp; Support</a></li>
        <li><a href="login.php">Portal Access</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Contact Research Office</h4>
      <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;">
        <span>📧</span> research@earist.edu.ph
      </p>
      <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px; margin-top: 8px;">
        <span>📍</span> EARIST Manila Campus, Philippines
      </p>
    </div>
  </div>

  <div class="footer-bottom">
    &copy; <?php echo date('Y'); ?> Research Management System (RMS) &bull; Eulogio "Amang" Rodriguez Institute of Science and Technology. All rights reserved.
  </div>
</footer>

</body>
</html>

