<?php
// Keep the public footer usable on pages that do not load configuration.
(static function () {
    if (defined('SITE_URL')) {
        $siteBase = rtrim(SITE_URL, '/');
        $publicBase = $siteBase . '/public';
    } else {
        $publicBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/about-us.php')), '/');
        $siteBase = rtrim(str_replace('\\', '/', dirname($publicBase)), '/.');
    }
    $escape = static function ($value) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $siteName = function_exists('rmsSetting') ? rmsSetting('site_name') : 'Research Management System';
    $contactEmail = function_exists('rmsSetting') ? rmsSetting('contact_email') : 'research@earist.edu.ph';
    $contactPhone = function_exists('rmsSetting') ? rmsSetting('contact_phone') : '(02) 1234-5678';
    $contactAddress = function_exists('rmsSetting') ? rmsSetting('contact_address') : '2 Congressional Rd, General Mariano Alvarez, Cavite, Philippines';
    $logo = $escape($siteBase . '/photos/rms-logo.png');
    $links = [
        'Home' => 'index.php',
        'Research Archive' => 'research-archive.php',
        'About Us' => 'about-us.php',
        'Features' => 'about-us.php#research-features',
        'Contact' => 'about-us.php#contact-us',
        'Privacy Policy (Draft)' => 'privacy-policy.php',
        'Terms of Service (Draft)' => 'terms-of-service.php',
    ];
?>
<footer id="contact" class="public-footer">
    <div class="public-footer__grid">
        <div class="public-footer__column">
            <a class="public-footer__brand" href="<?php echo $escape($publicBase . '/index.php'); ?>" aria-label="Research Management System home">
                <img src="<?php echo $logo; ?>" alt="" width="62" height="62" aria-hidden="true">
                <span><?php echo nl2br($escape($siteName)); ?></span>
            </a>
        </div>
        <nav class="public-footer__column" aria-label="Footer quick links">
            <h4 class="public-footer__heading">Quick Links</h4>
            <ul class="public-footer__links">
                <?php foreach ($links as $label => $page): ?>
                <li><a href="<?php echo $escape($publicBase . '/' . $page); ?>"><?php echo $escape($label); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="public-footer__column">
            <h4 class="public-footer__heading">Contact Us</h4>
            <div class="public-footer__contact">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
                <a href="mailto:<?php echo $escape($contactEmail); ?>"><?php echo $escape($contactEmail); ?></a>
            </div>
            <div class="public-footer__contact">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z"/></svg>
                <a href="tel:<?php echo $escape(preg_replace('/[^0-9+]/', '', $contactPhone)); ?>"><?php echo $escape($contactPhone); ?></a>
            </div>
            <div class="public-footer__contact">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span><?php echo $escape(str_ireplace('Cavite', 'CAVITE', $contactAddress)); ?></span>
            </div>
            <div class="public-footer__contact">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12 4 4L19 6"/></svg>
                <span>Mon-Fri, 8:00 AM-5:00 PM</span>
            </div>
        </div>
    </div>
    <div class="public-footer__meta">
        <div class="public-footer__copyright">&copy; <?php echo date('Y'); ?> <?php echo $escape($siteName); ?>.<br>All rights reserved.</div>
        <div class="public-footer__tagline">Empowering Research.<br>Building the Future.</div>
    </div>
</footer>
<?php
})();
