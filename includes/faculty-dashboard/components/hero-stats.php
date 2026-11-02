<?php
/**
 * Hero Stats Component
 *
 * Displays 4 stat cards + 3 quick action buttons.
 *
 * @var array $stats Stat cards data
 * @var string $analyticsUrl URL for analytics link
 */

if (!isset($stats) || !is_array($stats)) {
    $stats = [];
}
$analyticsUrl = $analyticsUrl ?? (SITE_URL . 'pages/faculty/faculty-analytics.php');
?>

<section class="faculty-dashboard-hero" aria-label="Faculty dashboard overview">
    <header class="faculty-hero-header">
        <div class="faculty-hero-title-group">
            <p class="faculty-overline">Faculty workspace</p>
            <h2>Research, advising, and engagement at a glance</h2>
        </div>
        <a class="faculty-header-chip" href="<?= htmlspecialchars($analyticsUrl, ENT_QUOTES, 'UTF-8') ?>">
            Research Analytics
        </a>
    </header>

    <div class="faculty-summary-grid" aria-label="Faculty dashboard metrics">
        <?php foreach ($stats as $index => $card): ?>
            <article class="faculty-stat-card faculty-stat-card--<?= htmlspecialchars($card['tone'] ?? 'primary', ENT_QUOTES, 'UTF-8') ?>" style="animation-delay: <?= $index * 0.04 ?>s;">
                <span class="faculty-stat-value"><?= htmlspecialchars((string) $card['value'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="faculty-stat-label"><?= htmlspecialchars($card['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="faculty-quick-actions" aria-label="Quick actions">
        <a class="faculty-quick-action-btn faculty-quick-action-btn--primary" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-engagement.php', ENT_QUOTES, 'UTF-8') ?>">
            <span class="faculty-qaction-icon" aria-hidden="true">+</span>
            <span>Add Engagement</span>
        </a>
        <a class="faculty-quick-action-btn faculty-quick-action-btn--secondary" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-profile.php?tab=research', ENT_QUOTES, 'UTF-8') ?>">
            <span class="faculty-qaction-icon" aria-hidden="true">✎</span>
            <span>Update Profile</span>
        </a>
        <a class="faculty-quick-action-btn faculty-quick-action-btn--secondary" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-advising.php', ENT_QUOTES, 'UTF-8') ?>">
            <span class="faculty-qaction-icon" aria-hidden="true">👥</span>
            <span>View Advisees</span>
        </a>
    </div>
</section>
