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
    <title>Terms of Service | <?= $siteName ?></title>
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
    <?php require __DIR__ . '/../includes/public-header.php'; ?>

    <main class="policy-main" id="main-content">
        <article class="policy-document" aria-labelledby="terms-title">
            <header class="policy-heading">
                <p class="policy-eyebrow">Research Management System</p>
                <h1 id="terms-title">Terms of Service</h1>
                <p class="policy-intro">These draft terms summarize expected use of RMS. They are not an approved institutional agreement.</p>
            </header>

            <nav class="policy-toc" aria-label="On this page">
                <strong>On this page</strong>
                <a href="#scope">Using RMS</a>
                <a href="#accounts">Accounts and submissions</a>
                <a href="#conduct">Acceptable use</a>
                <?php if ($googleDriveEnabled): ?><a href="#google-drive">Google Drive feature</a><?php endif; ?>
                <a href="#changes">Changes and support</a>
            </nav>

            <section class="policy-section" id="scope">
                <h2>Using RMS</h2>
                <p>RMS provides online features for research submissions and review, related institutional records, and access to research information that has been released for public viewing. Access to particular features depends on the account role and permissions assigned in the system.</p>
                <p>These draft terms are provided for institutional review. They do not establish an approved legal agreement or replace institutional rules that apply to research, records, or account use.</p>
            </section>

            <section class="policy-section" id="accounts">
                <h2>Accounts and submissions</h2>
                <ul>
                    <li>Use only an account assigned or authorized for you, and keep your sign-in information private.</li>
                    <li>Submit information and documents only when you are authorized to provide them and the intended RMS users are allowed to access them.</li>
                    <li>Use the system’s supported workflow for research submissions, reviews, revisions, and document handling. Access to records depends on the permissions configured in RMS.</li>
                    <li>RMS may process research documents and related information to provide its workflow and recordkeeping features. The institution must approve the applicable intellectual-property and record-handling rules.</li>
                </ul>
            </section>

            <section class="policy-section" id="conduct">
                <h2>Acceptable use</h2>
                <p>Users are expected to use RMS for authorized institutional and research purposes, respect the access restrictions on records and documents, and avoid disrupting the service or attempting to access another person’s account or information.</p>
                <p>Institutional rules determine how misuse is handled, including any account restrictions or review process. The authorized office must confirm and add those rules before this page is finalized.</p>
            </section>

            <?php if ($googleDriveEnabled): ?>
            <section class="policy-section" id="google-drive">
                <h2>Optional Google Drive editing</h2>
                <p>If enabled, the Google Drive feature lets eligible faculty users connect a Google account to edit generated DOCX working copies. The user’s Drive remains under Google’s service and account. Disconnecting the integration removes the RMS connection but does not delete copies already in Google Drive.</p>
                <p>Use the feature only for files you are authorized to edit and store in the connected Google account. Google services are also subject to Google’s terms.</p>
            </section>
            <?php endif; ?>

            <section class="policy-section" id="changes">
                <h2>Changes and support</h2>
                <p>RMS features and these terms may need updates as institutional requirements change. The institution must approve the process for notifying users and the rules for service availability, account closure, complaints, and disputes before these terms are final.</p>
                <p>For general questions, use the <a href="<?= htmlspecialchars($publicBase . 'about-us.php#contact-us', ENT_QUOTES, 'UTF-8') ?>">RMS contact page</a>.</p>
            </section>

            <footer class="policy-page-links">
                <a href="<?= htmlspecialchars($publicBase . 'privacy-policy.php', ENT_QUOTES, 'UTF-8') ?>">Read the Privacy Policy</a>
                <a href="<?= htmlspecialchars($publicBase . 'index.php', ENT_QUOTES, 'UTF-8') ?>">Return to the RMS homepage</a>
            </footer>
        </article>
    </main>

    <?php require __DIR__ . '/../includes/public-footer.php'; ?>
</body>
</html>
