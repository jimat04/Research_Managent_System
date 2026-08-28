<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

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
        <title>About Research Management System</title>
        <link rel="stylesheet" href="../css/style.css">
        <link rel="stylesheet" href="../css/about.css">
</head>
<body>
        <!-- NAVBAR -->
        <nav class="navbar">
            <div class="nav-brand">
                <div class="nav-logo">🔬</div>
                <div class="nav-brand-text">
                    <span class="brand-main">Research<br>Management</span>
                    <span class="brand-sub">System</span>
                </div>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="about.php" class="active">About</a></li>
                <li><a href="features.php">Features</a></li>
                <li><a href="research-archive.php">Research Archive</a></li>
                <li><a href="contact.php">Contact</a></li>
            </ul>
            <div class="nav-actions">
                <a href="login.php" class="btn btn-secondary btn-sm">Login</a>
                <a href="login.php#register" class="btn btn-primary btn-sm">Register</a>
            </div>
        </nav>

        <!-- HERO SECTION -->
        <section class="hero about-hero">
            <div class="about-hero-container">
                <div class="about-hero-content">
                    <div class="section-tag" style="background: rgba(91,30,188,0.2); color: #c4a9ff; border: 1px solid rgba(196,169,255,0.3); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                        📖 Institutional Overview
                    </div>
                    <h1 class="about-hero-title">
                        Research Management <span class="highlight" style="background: linear-gradient(135deg, #c4a9ff, var(--secondary-light)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">System</span>
                    </h1>
                    <p class="about-hero-desc">A centralized digital platform purpose-built for EARIST — aligned with the 2015 Research Manual, serving students, faculty advisers, research office staff, and the Research Head through the full proposal-to-colloquium workflow.</p>
                    <div class="about-hero-actions">
                        <a href="research-archive.php" class="btn btn-primary btn-lg">View Research Archive</a>
                        <a href="contact.php" class="btn btn-secondary btn-lg">Contact Us</a>
                    </div>
                </div>
                <div class="about-hero-visual">
                    <svg width="280" height="280" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="aboutGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color: #5B1EBC; stop-opacity: 1" />
                                <stop offset="100%" style="stop-color: #0F6CBD; stop-opacity: 1" />
                            </linearGradient>
                            <linearGradient id="aboutGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color: #0F6CBD; stop-opacity: 1" />
                                <stop offset="100%" style="stop-color: #F57C00; stop-opacity: 1" />
                            </linearGradient>
                        </defs>
                        <circle cx="200" cy="200" r="150" fill="none" stroke="url(#aboutGrad1)" stroke-width="2" opacity="0.3">
                            <animateTransform attributeName="transform" type="rotate" from="0 200 200" to="360 200 200" dur="20s" repeatCount="indefinite" />
                        </circle>
                        <circle cx="200" cy="200" r="120" fill="none" stroke="url(#aboutGrad2)" stroke-width="2" opacity="0.4">
                            <animateTransform attributeName="transform" type="rotate" from="360 200 200" to="0 200 200" dur="15s" repeatCount="indefinite" />
                        </circle>
                        <g transform="translate(200,200)">
                            <circle cx="0" cy="0" r="55" fill="rgba(91,30,188,0.25)" />
                            <text x="0" y="20" font-size="65" text-anchor="middle" fill="#0F6CBD">📚</text>
                        </g>
                    </svg>
                </div>
            </div>
        </section>

        <!-- ABOUT THE SYSTEM -->
        <section class="section" style="background: #fff; padding: 70px 0;">
            <div class="container" style="max-width: 1000px; margin: 0 auto; padding: 0 20px; text-align: center;">
                <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                    About The Platform
                </div>
                <h2 class="section-title" style="font-size: 2.2rem; color: var(--text-dark); margin-bottom: 18px;">What is the <span style="color: var(--primary);">Research Management System</span>?</h2>
                <p style="font-size: 1.05rem; color: var(--text-light); line-height: 1.8; text-align: justify;">The Research Management System (RMS) is EARIST's institutional research-management system, purpose-built to operationalize the 2015 Research Manual. It connects research agenda management, CREC and EREC committee evaluation with internal and external specialists, MOU and NDA tracking, progress and terminal reports, the research colloquium, archival, research incentives, and publication in the EARIST Research Journal.<br><br>RMS supports the full path from Proposal Submission through CREC Evaluation, ORS Consolidation, EREC Research Forum, Approved/Revision/Disapproved, President Approval, MOU signed, Implementation, Midway Progress Report, Terminal Report review, Final bound report, and Research Colloquium. Individual and collaborative studies can deliver the five-chapter manuscript structure through these stages: Ch.1 Problem, Ch.2 RRL, Ch.3 Methods, Ch.4 Findings and Analysis, and Ch.5 Summary, Conclusions, and Recommendations.</p>
            </div>
        </section>

        <!-- MISSION & VISION -->
        <section class="section" style="background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); padding: 70px 0;">
            <div class="container" style="max-width: 1150px; margin: 0 auto; padding: 0 20px; display: flex; flex-wrap: wrap; gap: 32px; justify-content: center;">
                <div class="feature-card" style="flex: 1 1 340px; min-width: 280px; text-align: center; border-radius: 16px; padding: 36px 30px;">
                    <div class="feature-icon" style="margin: 0 auto 16px; font-size: 2.2rem;">🎯</div>
                    <h3 style="font-size: 1.3rem; color: var(--primary-dark); margin-bottom: 12px;">Our Mission</h3>
                    <p style="color: var(--text-light); line-height: 1.7;">To institutionalize an efficient, transparent, and accountable digital ecosystem for research management at EARIST — supporting students, faculty, and research committees from proposal through publication, in full alignment with the 2015 Research Manual.</p>
                </div>
                <div class="feature-card" style="flex: 1 1 340px; min-width: 280px; text-align: center; border-radius: 16px; padding: 36px 30px;">
                    <div class="feature-icon" style="margin: 0 auto 16px; font-size: 2.2rem; background: rgba(15,108,189,0.1);">🌟</div>
                    <h3 style="font-size: 1.3rem; color: var(--secondary); margin-bottom: 12px;">Our Vision</h3>
                    <p style="color: var(--text-light); line-height: 1.7;">To be a flagship research-management platform enabling EARIST to produce high-impact, ethically sound, and publishable research that contributes to national development.</p>
                </div>
            </div>
        </section>

        <!-- KEY FEATURES -->
        <section class="section" style="background: #fff; padding: 70px 0;">
            <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
                <div class="section-header" style="text-align: center; margin-bottom: 48px;">
                    <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                        ✨ System Capabilities
                    </div>
                    <h2 class="section-title" style="font-size: 2.2rem; color: var(--text-dark);">Core System <span style="color: var(--primary);">Features</span></h2>
                    <p class="section-desc" style="color: var(--text-light); max-width: 600px; margin: 0 auto;">Streamlined workflows to empower researchers, advisers, and administrators</p>
                </div>
                <div class="features-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px;">
                    <div class="feature-card"><div class="feature-icon">📄</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Proposal Submission</h3><p>Supports Science/Tech and Social/Behavioral proposal templates with required-document tracking.</p></div>
                    <div class="feature-card"><div class="feature-icon" style="background: rgba(15,108,189,0.1);">🏛️</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">CREC/EREC Review</h3><p>College-level evaluation flows into institutional review with internal and external specialists.</p></div>
                    <div class="feature-card"><div class="feature-icon">📑</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Chapter Management</h3><p>Manage the five-chapter structure with field-by-field editors and file uploads.</p></div>
                    <div class="feature-card"><div class="feature-icon" style="background: rgba(34,197,94,0.1);">✅</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Approval &amp; MOU Tracking</h3><p>Track the President approval gate, Memorandum of Research Undertaking, and Non-Disclosure Form.</p></div>
                    <div class="feature-card"><div class="feature-icon" style="background: rgba(245,124,0,0.1);">📈</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Progress &amp; Terminal Reports</h3><p>Submit midway progress reports and manage draft and final terminal report review.</p></div>
                    <div class="feature-card"><div class="feature-icon">🗂️</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Research Archive &amp; Journal</h3><p>Archive completed studies and track publication incentives aligned with Manual Ch.6–7.</p></div>
                    <div class="feature-card"><div class="feature-icon" style="background: rgba(91,30,188,0.1);">👥</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Collaborative Research</h3><p>Support Project Leaders, Study Leaders, Co-researchers, and Support Staff through project_members.</p></div>
                    <div class="feature-card"><div class="feature-icon" style="background: rgba(245,158,11,0.1);">🔔</div><h3 style="color: var(--text-dark); margin-bottom: 8px;">Notifications &amp; Deadlines</h3><p>Receive timely alerts for revisions, defenses, colloquium schedules, and research deadlines.</p></div>
                </div>
            </div>
        </section>

        <!-- WHO CAN USE THE SYSTEM -->
        <section class="section" style="background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); padding: 70px 0;">
            <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
                <div class="section-header" style="text-align: center; margin-bottom: 48px;">
                    <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                        👥 User Roles
                    </div>
                    <h2 class="section-title" style="font-size: 2.2rem; color: var(--text-dark);">Who Can Use the <span style="color: var(--primary);">System</span>?</h2>
                    <p class="section-desc" style="color: var(--text-light); max-width: 600px; margin: 0 auto;">Dedicated dashboards and tools crafted for all academic stakeholders</p>
                </div>
                <div class="features-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px;">
                    <div class="feature-card">
                        <div class="feature-icon">🎓</div>
                        <h3 style="font-size: 1.15rem; color: var(--primary-dark); margin-bottom: 12px;">Student Researcher</h3>
                        <ul style="color: var(--text-light); font-size: 0.9rem; padding-left: 18px; margin: 0; line-height: 1.7;">
                            <li>Submit digital research proposals</li>
                            <li>Upload chapter drafts and final manuscripts</li>
                            <li>Search approved research in the archive</li>
                            <li>Register co-researchers for collaborative studies</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon" style="background: rgba(15,108,189,0.1);">👨‍🏫</div>
                        <h3 style="font-size: 1.15rem; color: var(--secondary); margin-bottom: 12px;">Faculty Adviser</h3>
                        <ul style="color: var(--text-light); font-size: 0.9rem; padding-left: 18px; margin: 0; line-height: 1.7;">
                            <li>Review assigned advisees' manuscripts</li>
                            <li>Provide chapter-level comments and feedback</li>
                            <li>Endorse or request revisions on milestones</li>
                            <li>Evaluate oral defenses and presentations</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon" style="background: rgba(13,148,136,0.1);">📋</div>
                        <h3 style="font-size: 1.15rem; color: #0d9488; margin-bottom: 12px;">Research Office Staff</h3>
                        <ul style="color: var(--text-light); font-size: 0.9rem; padding-left: 18px; margin: 0; line-height: 1.7;">
                            <li>Handle day-to-day administrative processing</li>
                            <li>Verify submission completeness &amp; documents</li>
                            <li>Assist the Research Head &amp; committees</li>
                            <li>Maintain the central research repository</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon" style="background: rgba(245,124,0,0.1);">⚙️</div>
                        <h3 style="font-size: 1.15rem; color: var(--accent); margin-bottom: 12px;">Administrator</h3>
                        <ul style="color: var(--text-light); font-size: 0.9rem; padding-left: 18px; margin: 0; line-height: 1.7;">
                            <li>Oversee overall institutional research operations</li>
                            <li>Monitor completion metrics and analytics</li>
                            <li>Generate high-level institutional reports</li>
                            <li>Manage accounts, roles, and campus settings</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- BENEFITS -->
        <section class="section" style="background: #fff; padding: 70px 0;">
            <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
                <div class="section-header" style="text-align: center; margin-bottom: 48px;">
                    <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                        💡 Advantages
                    </div>
                    <h2 class="section-title" style="font-size: 2.2rem; color: var(--text-dark);">Why Choose <span style="color: var(--primary);">RMS</span>?</h2>
                    <p class="section-desc" style="color: var(--text-light); max-width: 600px; margin: 0 auto;">Key benefits engineered to simplify academic research governance</p>
                </div>
                <div class="features-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">⚡</span> Faster Review Processing</div>
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">📋</span> Manual-Compliant Workflows</div>
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">🔐</span> Secure Role-Based Access</div>
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">📊</span> Real-Time Research Analytics</div>
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">🤝</span> Committee Collaboration</div>
                    <div class="feature-card" style="padding: 24px; text-align: center; font-weight: 600; color: var(--primary-dark);"><span style="font-size: 1.6rem; display: block; margin-bottom: 8px;">📚</span> Research Archive &amp; Journal</div>
                </div>
            </div>
        </section>

        <!-- EARIST RESEARCH MANUAL -->
        <section class="section" style="background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); padding: 70px 0;">
            <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
                <div class="section-header" style="text-align: center; margin-bottom: 36px;">
                    <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                        📜 Institutional Standards
                    </div>
                    <h2 class="section-title" style="font-size: 2.2rem; color: var(--text-dark);">Aligned with the <span style="color: var(--primary);">EARIST Research Manual</span></h2>
                    <p style="font-size: 1.05rem; color: var(--text-light); line-height: 1.7; max-width: 850px; margin: 0 auto 32px; text-align: center;">RMS implements the 2015 Research Manual end-to-end, connecting research agenda planning, committee-based CREC/EREC review, required documents, implementation, reporting, publication, ethics awareness, and colloquium presentation in one institutional process.</p>
                </div>
                <div class="manual-grid">
                    <div class="manual-card"><h3>Ch.1 General Information</h3><p>Establishes the manual's institutional scope and research context.</p></div>
                    <div class="manual-card"><h3>Ch.2 Roadmap for Excellence</h3><p>Guides the direction and quality goals of EARIST research.</p></div>
                    <div class="manual-card"><h3>Ch.3 The Research Agenda</h3><p>Connects projects to priority areas and institutional needs.</p></div>
                    <div class="manual-card"><h3>Ch.4 Management of Research</h3><p>Defines the committee and administrative workflow RMS supports.</p></div>
                    <div class="manual-card"><h3>Ch.5 Development &amp; Implementation</h3><p>Tracks approved studies from development through implementation.</p></div>
                    <div class="manual-card"><h3>Ch.6 Research Incentive Program</h3><p>Supports tracking of research incentives and publication outputs.</p></div>
                    <div class="manual-card"><h3>Ch.7 Guidelines for Journal Publication</h3><p>Keeps journal-ready research and publication milestones visible.</p></div>
                    <div class="manual-card"><h3>Ch.8 Code of Research Ethics</h3><p>Raises awareness of ethical responsibilities throughout the process.</p></div>
                    <div class="manual-card"><h3>Ch.9 Transitory Provisions</h3><p>Provides continuity as institutional research procedures evolve.</p></div>
                </div>
            </div>
        </section>

        <!-- STATISTICS -->
        <section style="background: linear-gradient(135deg, #3D0F8A 0%, #5B1EBC 50%, #0F6CBD 100%); padding: 70px 40px; color: white;">
            <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 32px; text-align: center;">
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_total_research, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Total Research</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_active_students, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Active Students</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_completed, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Completed Studies</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_under_review, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Under Review</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_faculty_advisers, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Faculty Advisers</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_research_staff, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Research Staff</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo htmlspecialchars((string) $stat_approval_rate, ENT_QUOTES, 'UTF-8'); ?><span style="font-size:0.5em;">%</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px;">Approval Rate</div>
                </div>
            </div>
        </section>

        <!-- CALL TO ACTION -->
        <section class="section" style="background: #fff; padding: 80px 0;">
            <div class="container" style="max-width: 900px; margin: 0 auto; text-align: center; padding: 0 20px;">
                <div class="section-tag" style="background: rgba(91,30,188,0.08); color: var(--primary); border: 1px solid rgba(91,30,188,0.2); padding: 4px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 16px; display: inline-block;">
                    🚀 Get Started
                </div>
                <h2 class="section-title" style="font-size: 2.3rem; color: var(--text-dark); margin-bottom: 16px;">Begin Your Research at <span style="color: var(--primary);">EARIST</span></h2>
                <p style="font-size: 1.1rem; color: var(--text-light); margin-bottom: 32px; line-height: 1.7;">Move from proposal review to implementation, reporting, and the research colloquium with a workflow built for research excellence.</p>
                <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
                    <a href="login.php" class="btn btn-primary btn-lg"><span>🔑</span> Login Now</a>
                    <a href="login.php#register" class="btn btn-secondary btn-lg" style="color: var(--primary); border: 2px solid var(--primary);"><span>✨</span> Register Account</a>
                </div>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="footer">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <span style="font-size: 1.4rem;">🔬</span>
                        <span style="font-family: 'Poppins', sans-serif;">RMS</span>
                    </h4>
                    <p style="color: #8B8FAD; font-size: 0.85rem; line-height: 1.6;">Empowering academic institutions to streamline their research management processes with modern, intelligent technology.</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
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
                        <li><a href="#">Guidelines</a></li>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Documentation</a></li>
                        <li><a href="#">Support</a></li>
                        <li><a href="#">Research Manual 2015</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Contact</h4>
                    <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;">
                        <span>📧</span> research@earist.edu.ph
                    </p>
                </div>
            </div>
            <div class="footer-bottom">
                © 2024 Research Management System. All rights reserved.
            </div>
        </footer>
</body>
</html>

