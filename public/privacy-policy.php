<?php
require_once __DIR__ . '/../includes/config.php';

$siteName = htmlspecialchars((string) rmsSetting('site_name'), ENT_QUOTES, 'UTF-8');
$driveConfig = require __DIR__ . '/../config/earist-google-drive.php';
$googleDriveEnabled = !empty($driveConfig['enabled']);
$publicBase = rtrim(SITE_URL, '/') . '/public/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Privacy Policy Draft | <?= $siteName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/public-theme.css">
    <link rel="stylesheet" href="assets/css/landing-v2.css">
    <link rel="stylesheet" href="assets/css/public-header.css">
    <link rel="stylesheet" href="assets/css/public-footer.css">
    <link rel="stylesheet" href="assets/css/policy-pages.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/policy-pages.css') ?>">
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body class="public-v2 policy-page">
    <?php $publicHeaderStickyInner = true; require __DIR__ . '/../includes/public-header.php'; ?>

    <main class="policy-main" id="main-content">
        <article class="policy-document" aria-labelledby="policy-title">
            <header class="policy-heading">
                <p class="policy-eyebrow">Research Management System</p>
                <h1 id="policy-title">Privacy Policy</h1>
                <p class="policy-intro">This page describes the types of information the RMS application can process and how its features use that information.</p>
            </header>

            <aside class="policy-review-notice" aria-label="Review status">
                <strong>Review draft — not the approved institutional policy.</strong>
                <span>EARIST’s authorized office must confirm this text, the responsible office and contact details, service providers, retention periods, and request procedures before this page is treated as final or used for production OAuth verification.</span>
            </aside>

            <nav class="policy-toc" aria-label="On this page">
                <strong>On this page</strong>
                <a href="#information">Information processed</a>
                <a href="#uses">How information is used</a>
                <a href="#access">Access and sharing</a>
                <?php if ($googleDriveEnabled): ?><a href="#google-drive">Google Drive feature</a><?php endif; ?>
                <a href="#retention">Retention and requests</a>
            </nav>

            <section class="policy-section" id="information">
                <h2>Information processed by RMS</h2>
                <p>Depending on how a person uses RMS, the application may process:</p>
                <ul>
                    <li>Account details such as a person’s name, email address, role, and profile or academic information entered into the account.</li>
                    <li>Research and institutional records entered into RMS, including research titles, project and workflow details, adviser or review information, activity and publication records, intellectual-property records, and documents uploaded or generated through the system.</li>
                    <li>Messages sent through the public contact form, including the sender’s name, email address, selected inquiry type, and message.</li>
                    <li>Operational information needed to run the application, such as account and workflow activity, notifications, and security-related records.</li>
                </ul>
                <p>The exact information involved depends on the feature used and the information a user or authorized staff member enters.</p>
            </section>

            <section class="policy-section" id="uses">
                <h2>How information is used</h2>
                <p>RMS uses submitted information to provide accounts and role-based access, support research submission and review workflows, maintain research-related records, show permitted archive entries, send application messages, respond to contact inquiries, and operate and protect the system.</p>
            </section>

            <section class="policy-section" id="access">
                <h2>Access and sharing</h2>
                <p>Information is available to authorized RMS users according to their role and the permissions and assignments configured in the application. Some records specifically released to the public may appear in the public research archive.</p>
                <p>The service also depends on infrastructure and delivery providers configured by the institution, such as web hosting and email delivery. The institution must identify and approve the providers and describe their roles before this draft is finalized.</p>
            </section>

            <?php if ($googleDriveEnabled): ?>
            <section class="policy-section" id="google-drive">
                <h2>Optional Google Drive editing</h2>
                <p>When the Google Drive editing feature is enabled, a faculty user can connect a Google account to edit eligible generated DOCX files. RMS receives the Google account’s verified email and account identifier and stores the refresh authorization token in encrypted form. RMS can upload working copies to that user’s Google Drive and retrieve an edited DOCX when the user imports it into RMS.</p>
                <p>Disconnecting Google Drive removes the saved connection from RMS and requests revocation of the authorization. It does not delete working copies already stored in the user’s Google Drive. Google’s own services are subject to Google’s terms and privacy information.</p>
            </section>
            <?php endif; ?>

            <section class="policy-section" id="retention">
                <h2>Retention, requests, and contact</h2>
                <p>This draft does not set a retention period or describe a complete procedure for access, correction, deletion, or privacy inquiries. Those details must come from the institution’s approved records schedule and privacy process before publication as a final policy.</p>
                <p>For general questions, use the <a href="<?= htmlspecialchars($publicBase . 'about-us.php#contact-us', ENT_QUOTES, 'UTF-8') ?>">RMS contact page</a>. The institution must confirm whether this is also an approved channel for privacy requests and provide any required privacy-office contact details.</p>
            </section>

            <footer class="policy-page-links">
                <a href="<?= htmlspecialchars($publicBase . 'terms-of-service.php', ENT_QUOTES, 'UTF-8') ?>">Read the Terms of Service draft</a>
                <a href="<?= htmlspecialchars($publicBase . 'index.php', ENT_QUOTES, 'UTF-8') ?>">Return to the RMS homepage</a>
            </footer>
        </article>
    </main>

    <?php require __DIR__ . '/../includes/public-footer.php'; ?>
</body>
</html>
