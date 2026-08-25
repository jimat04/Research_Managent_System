<?php
include 'includes/config.php';
include 'includes/auth.php';

if (isLoggedIn()) {
    $user = getCurrentUser();
    $redirect = 'pages/' . $user['role'] . '-dashboard.php';
    header('Location: ' . $redirect);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="css/style.css" />
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
    <li><a href="#home" class="active">Home</a></li>
      <li><a href="about.php">About</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="research-archive.php">Research Archive</a></li>
      <li><a href="contact.php">Contact</a></li>
  </ul>

  <div class="nav-actions">
    <a href="login.php" class="btn btn-secondary btn-sm">Login</a>
    <a href="login.php#register" class="btn btn-primary btn-sm">Register</a>
  </div>
</nav>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- HERO SECTION -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="hero" id="home">
  <div class="hero-container">
    <!-- Left Content -->
    <div class="hero-content">
      <h1>
        Research<br>
        <span class="highlight">Management</span><br>
        System
      </h1>

      <p>
        Streamline research submissions, approvals, and tracking in one intelligent platform. 
        Empowering students, faculty, and administrators to collaborate seamlessly.
      </p>

      <div class="hero-actions">
        <a href="login.php" class="btn btn-primary btn-lg">
          <span>🔑</span> Login Now
        </a>
        <a href="#features" class="btn btn-secondary btn-lg">
          <span>✨</span> Explore Features
        </a>
      </div>

      <div class="hero-stats">
        <div class="hero-stat">
          <div class="number">1,250<span style="font-size:0.6em;">+</span></div>
          <div class="label">Total Research</div>
        </div>
        <div class="hero-stat">
          <div class="number">850<span style="font-size:0.6em;">+</span></div>
          <div class="label">Active Users</div>
        </div>
        <div class="hero-stat">
          <div class="number">95<span style="font-size:0.6em;">%</span></div>
          <div class="label">Approval Rate</div>
        </div>
      </div>
    </div>

    <!-- Right Visualization -->
    <div class="hero-visual">
      <svg width="100%" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg">
        <!-- Animated circles -->
        <circle cx="200" cy="200" r="150" fill="none" stroke="url(#grad1)" stroke-width="2" opacity="0.3">
          <animateTransform attributeName="transform" type="rotate" from="0 200 200" to="360 200 200" dur="20s" repeatCount="indefinite" />
        </circle>
        <circle cx="200" cy="200" r="120" fill="none" stroke="url(#grad2)" stroke-width="2" opacity="0.4">
          <animateTransform attributeName="transform" type="rotate" from="360 200 200" to="0 200 200" dur="15s" repeatCount="indefinite" />
        </circle>

        <!-- Center gear icon -->
        <g transform="translate(200,200)">
          <circle cx="0" cy="0" r="40" fill="#5B1EBC" opacity="0.2" />
          <text x="0" y="15" font-size="50" text-anchor="middle" fill="#0F6CBD">⚙️</text>
        </g>

        <!-- Arrow decorations -->
        <path d="M 280 80 Q 300 100 320 90" stroke="#FF9800" stroke-width="3" fill="none" stroke-linecap="round">
          <animate attributeName="d" 
            values="M 280 80 Q 300 100 320 90; M 290 70 Q 310 90 330 80; M 280 80 Q 300 100 320 90"
            dur="3s" repeatCount="indefinite" />
        </path>

        <!-- Gradients -->
        <defs>
          <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#5B1EBC;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#0F6CBD;stop-opacity:1" />
          </linearGradient>
          <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#0F6CBD;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#FF9800;stop-opacity:1" />
          </linearGradient>
        </defs>
      </svg>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- FEATURES SECTION -->
<!-- ════════════════════════════════════════════════════════════ -->
<section class="section features-bg" id="features">
  <div class="section-header">
    <div class="section-tag">✨ Platform Features</div>
    <h2 class="section-title">Powerful Features for <span style="color: var(--primary);">Research Excellence</span></h2>
    <p class="section-desc">Everything you need to manage research from proposal to completion</p>
  </div>

  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon">📄</div>
      <h3>Research Submission</h3>
      <p>Easy submission and document upload for research proposals and chapters.</p>
      <a class="card-action" href="#features">Learn more →</a>
    </div>
    <div class="feature-card">
      <div class="feature-icon">✅</div>
      <h3>Review & Approval</h3>
      <p>Streamlined multi-level approval workflow with faculty feedback and comments.</p>
      <a class="card-action" href="#features">Learn more →</a>
    </div>
    <div class="feature-card">
      <div class="feature-icon">📈</div>
      <h3>Progress Tracking</h3>
      <p>Real-time monitoring of research progress through every phase and milestone.</p>
      <a class="card-action" href="#features">Learn more →</a>
    </div>
    <div class="feature-card">
      <div class="feature-icon">🗂️</div>
      <h3>Research Archive</h3>
      <p>Centralized, searchable repository of completed and published research.</p>
      <a class="card-action" href="#features">Learn more →</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- STATS SECTION -->
<!-- ════════════════════════════════════════════════════════════ -->
<section style="background: linear-gradient(135deg, #3D0F8A 0%, #5B1EBC 50%, #0F6CBD 100%); padding: 60px 40px; color: white;">
  <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 40px; text-align: center;">
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;">1,250<span style="font-size:0.5em;">+</span></div>
      <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Total Research</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;">850<span style="font-size:0.5em;">+</span></div>
      <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Active Users</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;">320<span style="font-size:0.5em;">+</span></div>
      <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Completed Studies</div>
    </div>
    <div>
      <div style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800;">95<span style="font-size:0.5em;">%</span></div>
      <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-top: 4px;">Approval Rate</div>
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
        Empowering academic institutions to streamline their research management processes with modern, intelligent technology.
      </p>
    </div>

    <div class="footer-col">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="#home">Home</a></li>
        <li><a href="#features">Features</a></li>
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
        <span>📧</span> research@university.edu.ph
      </p>
      <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px; margin-top: 8px;">
        <span>📞</span> +63 912 345 6789
      </p>
    </div>
  </div>

  <div class="footer-bottom">
    © 2024 Research Management System. All rights reserved.
  </div>
</footer>

</body>
</html>
