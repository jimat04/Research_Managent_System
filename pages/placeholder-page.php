<?php
include '../includes/config.php';
include '../includes/auth.php';
requireLogin();
$user = getCurrentUser();
$page_title = isset($_GET['title']) ? $_GET['title'] : 'Page';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <title><?php echo htmlspecialchars($page_title); ?> — RMS</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<div class="dashboard">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px;">🔬</div>
      <div class="sidebar-brand">Research<br>Management</div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-title">MAIN</div>
      <div class="nav-item" onclick="history.back()"><span class="icon">← </span>Back</div>
    </nav>
    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
        <div class="user-info">
          <div class="user-name"><?php echo htmlspecialchars($user['first_name']); ?></div>
          <div class="user-role"><?php echo ucfirst($user['role']); ?></div>
        </div>
      </div>
    </div>
  </aside>
  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h2><?php echo htmlspecialchars($page_title); ?></h2>
      </div>
      <div class="topbar-right">
        <div class="user-profile-btn">
          <div class="profile-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
          <div class="profile-text">
            <div class="profile-name"><?php echo htmlspecialchars($user['first_name']); ?></div>
          </div>
        </div>
      </div>
    </header>
    <div class="page-content">
      <div class="card" style="text-align: center; padding: 60px 40px;">
        <div style="font-size: 3rem; margin-bottom: 20px;">🔨</div>
        <h2 style="font-size: 1.5rem; margin-bottom: 10px;">This Page is Under Construction</h2>
        <p style="color: var(--text-muted); margin-bottom: 20px;">This feature will be available soon. In the meantime, you can:</p>
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Go to Dashboard</button>
        <button class="btn btn-secondary" onclick="history.back()" style="margin-left: 10px;">Go Back</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
