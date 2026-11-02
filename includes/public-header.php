<?php
$publicHeaderActivePage = $publicHeaderActivePage ?? '';
$publicAuthContinuation = $publicHeaderActivePage === 'archive' ? '?continue=research-archive' : '';
?>
<header id="site-header" class="navbar"><div class="public-header-inner">
    <a class="home-brand" href="index.php" aria-label="EARIST Research Management System home"><img src="../photos/rms-logo.png" alt="" width="40" height="40" aria-hidden="true"><span><strong>EARIST CAVITE</strong><small>Research Management System</small></span></a>
    <nav id="primary-navigation" class="nav-links" aria-label="Main navigation">
        <a href="index.php"<?php echo $publicHeaderActivePage === 'home' ? ' class="on" aria-current="page"' : ''; ?>>Home</a>
        <a href="about-us.php"<?php echo $publicHeaderActivePage === 'about' ? ' class="on" aria-current="page"' : ''; ?>>About Us</a>
        <a href="research-archive.php"<?php echo $publicHeaderActivePage === 'archive' ? ' class="active" aria-current="page"' : ''; ?>>Research Archive</a>
        <div class="mobile-nav-actions nav-actions"><a class="btn btn-secondary" href="login.php<?php echo $publicAuthContinuation; ?>">Sign In</a><a class="btn btn-primary" href="login.php<?php echo $publicAuthContinuation; ?>#register">Get Started</a></div>
    </nav>
    <div class="header-actions nav-actions"><a class="btn btn-secondary" href="login.php<?php echo $publicAuthContinuation; ?>">Sign In</a><a class="btn btn-primary" href="login.php<?php echo $publicAuthContinuation; ?>#register">Get Started</a></div>
    <button class="burger menu-toggle public-menu-toggle" type="button" aria-label="Open navigation" aria-controls="primary-navigation" aria-expanded="false"><span></span><span></span><span></span></button>
</div></header>
