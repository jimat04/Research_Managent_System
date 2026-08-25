<?php
include 'includes/config.php';
include 'includes/auth.php';
// Fetch statistics from database
$stat_total_research = $conn->query("SELECT COUNT(*) as count FROM research_projects")->fetch_assoc()['count'];
$stat_active_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'];
$stat_completed = $conn->query("SELECT COUNT(*) as count FROM research_projects WHERE status = 'completed'")->fetch_assoc()['count'];
$stat_approval_rate = $conn->query("SELECT ROUND((SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) / COUNT(*)) * 100) as rate FROM research_projects")->fetch_assoc()['rate'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>About Research Management System</title>
        <link rel="stylesheet" href="css/style.css">
        <link rel="stylesheet" href="css/about.css">
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
                <li><a href="index.php#features">Features</a></li>
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
                    <h1 class="about-hero-title">Research Management System</h1>
                    <p class="about-hero-desc">A centralized digital platform designed to streamline research submissions, reviews, approvals, tracking, and archiving for students, faculty, and administrators.</p>
                    <div class="about-hero-actions">
                        <a href="research-archive.php" class="btn btn-primary btn-lg">View Research Archive</a>
                        <a href="contact.php" class="btn btn-secondary btn-lg">Contact Us</a>
                    </div>
                </div>
                <div class="about-hero-visual">
                    <svg width="260" height="260" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="aboutGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color: var(--primary-dark); stop-opacity: 1" />
                                <stop offset="100%" style="stop-color: var(--secondary); stop-opacity: 1" />
                            </linearGradient>
                        </defs>
                        <circle cx="200" cy="200" r="150" fill="none" stroke="url(#aboutGrad1)" stroke-width="2" opacity="0.18"/>
                        <circle cx="200" cy="200" r="120" fill="none" stroke="url(#aboutGrad1)" stroke-width="2" opacity="0.26"/>
                        <g transform="translate(200,200)">
                            <circle cx="0" cy="0" r="60" fill="rgba(255,255,255,0.10)" />
                            <text x="0" y="20" font-size="70" text-anchor="middle" fill="var(--primary)">📚</text>
                        </g>
                    </svg>
                </div>
            </div>
        </section>


        <!-- ABOUT THE SYSTEM -->
        <section class="section" style="background: #fff; padding:60px 0;">

            <div class="container" style="max-width:900px; margin:0 auto;">
                <h2 class="section-title" style="font-size:2rem; color:#3D0F8A; margin-bottom:18px;">What is the Research Management System?</h2>
                <p style="font-size:1.1rem; color:#444; line-height:1.7;">The Research Management System (RMS) is a web-based platform developed to improve the management of academic research processes. It enables students, faculty members, and administrators to collaborate efficiently through a centralized and secure environment for research submission, review, approval, tracking, and archiving.<br><br>The system minimizes manual processes, reduces paperwork, improves communication, and promotes efficient research management through modern technology.</p>
            </div>
        </section>

        <!-- MISSION & VISION -->
        <section class="section" style="background:#F8F9FE; padding:60px 0;">
            <div class="container" style="max-width:1100px; margin:0 auto; display:flex; flex-wrap:wrap; gap:32px; justify-content:center;">
                <div class="card" style="flex:1 1 320px; min-width:260px; background:#fff; border-radius:14px; box-shadow:var(--card-shadow); padding:32px 28px; display:flex; flex-direction:column; align-items:center;">
                    <div style="font-size:2.5rem; color:var(--primary); margin-bottom:12px;">🎯</div>
                    <h3 style="font-size:1.3rem; color:#3D0F8A; margin-bottom:10px;">Our Mission</h3>
                    <p style="color:#444; text-align:center;">To empower students, faculty, and academic institutions by providing an efficient, transparent, and innovative platform that supports research development, collaboration, and knowledge advancement.</p>
                </div>
                <div class="card" style="flex:1 1 320px; min-width:260px; background:#fff; border-radius:14px; box-shadow:var(--card-shadow); padding:32px 28px; display:flex; flex-direction:column; align-items:center;">
                    <div style="font-size:2.5rem; color:var(--primary); margin-bottom:12px;">🌟</div>
                    <h3 style="font-size:1.3rem; color:#3D0F8A; margin-bottom:10px;">Our Vision</h3>
                    <p style="color:#444; text-align:center;">To become a reliable and intelligent research management platform that promotes academic excellence, innovation, and digital transformation in research administration.</p>
                </div>
            </div>
        </section>

        <!-- KEY FEATURES -->
        <section class="section" style="background:#fff; padding:60px 0;">
            <div class="container" style="max-width:1100px; margin:0 auto;">
                <h2 class="section-title" style="font-size:2rem; color:#3D0F8A; margin-bottom:32px;">Key Features</h2>
                <div class="features-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:28px;">
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">📄</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Research Submission</h3>
                        <p style="color:#444;">Easy submission and document upload with tracking.</p>
                    </div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">✅</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Review and Approval Workflow</h3>
                        <p style="color:#444;">Streamlined review process for faculty and reviewers.</p>
                    </div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">📈</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Progress Tracking</h3>
                        <p style="color:#444;">Monitor research status in real time.</p>
                    </div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">🗂️</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Research Archive</h3>
                        <p style="color:#444;">Centralized repository for approved and completed research.</p>
                    </div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">🔔</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Notifications and Updates</h3>
                        <p style="color:#444;">Receive alerts regarding revisions, approvals, and deadlines.</p>
                    </div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">📊</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Reports and Analytics</h3>
                        <p style="color:#444;">Generate research statistics and visual insights.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- WHO CAN USE THE SYSTEM -->
        <section class="section" style="background:#F8F9FE; padding:60px 0;">
            <div class="container" style="max-width:1100px; margin:0 auto;">
                <h2 class="section-title" style="font-size:2rem; color:#3D0F8A; margin-bottom:32px;">Who Can Use the System?</h2>
                <div class="features-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:28px;">
                    <div class="feature-card" style="background:#fff; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">🎓</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Student</h3>
                        <ul style="color:#444; font-size:1rem; padding-left:18px; margin:0; list-style:disc;">
                            <li>Submit research</li>
                            <li>Upload chapters</li>
                            <li>Track progress</li>
                            <li>View feedback</li>
                        </ul>
                    </div>
                    <div class="feature-card" style="background:#fff; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">👨‍🏫</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Faculty Reviewer</h3>
                        <ul style="color:#444; font-size:1rem; padding-left:18px; margin:0; list-style:disc;">
                            <li>Review submissions</li>
                            <li>Provide comments</li>
                            <li>Approve or request revisions</li>
                            <li>Monitor assigned projects</li>
                        </ul>
                    </div>
                    <div class="feature-card" style="background:#fff; border-radius:12px; box-shadow:var(--card-shadow); padding:28px 22px;">
                        <div class="feature-icon" style="font-size:2rem; color:var(--primary);">🛡️</div>
                        <h3 style="font-size:1.1rem; color:#3D0F8A; margin:10px 0 8px;">Administrator</h3>
                        <ul style="color:#444; font-size:1rem; padding-left:18px; margin:0; list-style:disc;">
                            <li>Manage users</li>
                            <li>Manage research records</li>
                            <li>Generate reports</li>
                            <li>Monitor system activities</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- BENEFITS -->
        <section class="section" style="background:#fff; padding:60px 0;">
            <div class="container" style="max-width:1100px; margin:0 auto;">
                <h2 class="section-title" style="font-size:2rem; color:#3D0F8A; margin-bottom:32px;">Why Choose RMS?</h2>
                <div class="features-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:24px;">
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">⚡ Faster research processing</div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">📁 Centralized document management</div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">🔒 Secure data storage</div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">⏱️ Real-time progress monitoring</div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">🤝 Better collaboration</div>
                    <div class="feature-card" style="background:#F8F9FE; border-radius:12px; box-shadow:var(--card-shadow); padding:22px 18px; text-align:center; font-size:1rem; color:#3D0F8A; font-weight:600;">📝 Reduced paperwork</div>
                </div>
            </div>
        </section>

        <!-- STATISTICS -->
        <section class="section" style="background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 50%, var(--secondary) 100%); padding: 60px 0; color: white;">

            <div class="container" style="max-width:1100px; margin:0 auto; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:32px; text-align:center;">
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo $stat_total_research; ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Total Research</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo $stat_active_users; ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Active Users</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo $stat_completed; ?><span style="font-size:0.5em;">+</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Completed Studies</div>
                </div>
                <div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;"> <?php echo $stat_approval_rate; ?><span style="font-size:0.5em;">%</span></div>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Approval Rate</div>
                </div>
            </div>
        </section>

        <!-- CALL TO ACTION -->
        <section class="section" style="background:#fff; padding:60px 0;">
            <div class="container" style="max-width:900px; margin:0 auto; text-align:center;">
                <h2 class="section-title" style="font-size:2rem; color:#3D0F8A; margin-bottom:18px;">Start Your Research Journey Today</h2>
                <p style="font-size:1.1rem; color:#444; margin-bottom:32px;">Experience a smarter and more efficient way of managing academic research.</p>
<div style="display:flex; gap:16px; justify-content:center; flex-wrap:wrap;">
                    <a href="login.php" class="btn btn-primary btn-lg" style="margin-left: 100px;">Login</a>
                    <a href="login.php#register" class="btn btn-secondary btn-lg">Register</a>
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
                        <li><a href="index.php#features">Features</a></li>
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
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Contact</h4>
                    <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;">
                        <span>✉️</span> research@university.edu.ph
                    </p>
                </div>
            </div>
        </footer>

        <style>
            @media (max-width: 900px) {
                .hero-container, .footer-grid, .features-grid, .container {
                    flex-direction: column !important;
                    gap: 24px !important;
                }
                .hero-visual { margin-top: 24px; }
            }
            @media (max-width: 600px) {
                .hero-content h1 { font-size: 2rem !important; }
                .section-title { font-size: 1.3rem !important; }
                .features-grid, .footer-grid {
                    grid-template-columns: 1fr !important;
                }
                .container { padding: 0 10px !important; }
            }
        </style>
</body>
</html>

