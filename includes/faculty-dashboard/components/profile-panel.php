<?php
/**
 * Profile Panel Component
 *
 * Shows saved research profile details with quick-edit links.
 *
 * @var array $profile Profile status data from FacultyProfileService
 * @var string $profileUrl URL for editing the research profile
 */

// Handle unavailable profile module
if (isset($profile['available']) && $profile['available'] === false) {
    $status = 'unavailable';
    $statusClass = 'incomplete';
    $statusLabel = 'Profile module not installed';
    $guidance = 'The faculty research profile feature is not available.';
    $quickEdits = [];
    $orcid = null;
    $scholar = null;
    $researchgate = null;
    $interests = null;
} else {
    $status = $profile['status'] ?? 'not_saved';
    $statusLabels = [
        'saved' => 'Saved',
        'not_saved' => 'Not saved',
        'unavailable' => '⚠️ Unavailable',
    ];
    $statusLabel = $statusLabels[$status] ?? 'Incomplete';

    $statusClassMap = [
        'saved' => 'verified',
        'not_saved' => 'incomplete',
        'unavailable' => 'incomplete',
    ];
    $statusClass = $statusClassMap[$status] ?? 'incomplete';

    $guidance = $profile['guidance'] ?? '';
    $quickEdits = $profile['quick_edits'] ?? [];

    // Extract values from quick_edits
    $orcid = null;
    $scholar = null;
    $researchgate = null;
    $interests = null;

    foreach ($quickEdits as $edit) {
        switch ($edit['label']) {
            case 'ORCID':
                $orcid = $edit['value'] ?? null;
                break;
            case 'Google Scholar':
                $scholar = $edit['value'] ?? null;
                break;
            case 'ResearchGate':
                $researchgate = $edit['value'] ?? null;
                break;
            case 'Research Interests':
                $interests = $edit['has_value'] ? 'Set' : null;
                break;
        }
    }
}
?>
<div class="faculty-panel">
    <div class="faculty-panel-head">
        <h3>Research Profile</h3>
        <a href="<?= htmlspecialchars($profileUrl) ?>" class="faculty-panel-link">Edit Profile →</a>
    </div>

    <div class="faculty-profile-status">
        <span class="faculty-profile-status-badge faculty-profile-status-badge--<?= $statusClass ?>">
            <?= $statusLabel ?>
        </span>
        <?php if (!empty($guidance)): ?>
            <p class="faculty-profile-guidance"><?= htmlspecialchars($guidance) ?></p>
        <?php endif; ?>
    </div>

    <div class="faculty-profile-links">
        <?php if (!empty($orcid)): ?>
            <a href="<?= htmlspecialchars($orcid) ?>" target="_blank" rel="noopener" class="faculty-profile-link">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-.1-13.06h-.04v3.99h-.04c-.39 0-.7.29-.7.66 0 .37.31.67.7.67h.04v3.82h.04c.39 0 .7-.3.7-.67 0-.37-.31-.66-.7-.66h-.04v-3.82h.04c.39 0 .7-.3.7-.67 0-.37-.31-.67-.7-.67zm5.38 3.06c-.07 3.32-2.76 6-6.12 6-3.37 0-6.06-2.68-6.12-6H.06C.06 8.82 1.73 7 4.03 7c2.02 0 3.81 1.17 4.7 2.85.04.08.1.15.16.22.05.06.11.12.17.18.14.13.28.26.43.39.45.39 1.02.39 1.47 0 .15-.13.29-.26.43-.39.06-.06.12-.12.17-.18.06-.07.12-.14.16-.22.89-1.68 2.68-2.85 4.7-2.85 2.3 0 3.97 1.82 3.97 4.06 0 .07 0 .14 0 .21z"/></svg>
                ORCID: <?= htmlspecialchars($orcid) ?>
            </a>
        <?php else: ?>
            <?php
            $orcidEditUrl = $profileUrl . '?tab=research#orcid';
            foreach ($quickEdits as $edit) {
                if ($edit['label'] === 'ORCID') {
                    $orcidEditUrl = $edit['url'];
                    break;
                }
            }
            ?>
            <a href="<?= htmlspecialchars($orcidEditUrl) ?>" class="faculty-profile-link faculty-profile-link--add">
                + Add ORCID
            </a>
        <?php endif; ?>

        <?php if (!empty($scholar)): ?>
            <a href="<?= htmlspecialchars($scholar) ?>" target="_blank" rel="noopener" class="faculty-profile-link">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 3c5.5 0 10 4.5 10 10s-4.5 10-10 10S2 17.5 2 12 6.5 3 12 3zm0 2c-4.4 0-8 3.6-8 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm3.5 10.5c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5zm-7 0c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5zm7 0c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5z"/></svg>
                Google Scholar: Connected
            </a>
        <?php else: ?>
            <?php
            $scholarEditUrl = $profileUrl . '?tab=research#scholar';
            foreach ($quickEdits as $edit) {
                if ($edit['label'] === 'Google Scholar') {
                    $scholarEditUrl = $edit['url'];
                    break;
                }
            }
            ?>
            <a href="<?= htmlspecialchars($scholarEditUrl) ?>" class="faculty-profile-link faculty-profile-link--add">
                + Add Google Scholar
            </a>
        <?php endif; ?>

        <?php if (!empty($researchgate)): ?>
            <a href="<?= htmlspecialchars($researchgate) ?>" target="_blank" rel="noopener" class="faculty-profile-link">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-10.5h-2v-3h2v3zm-7 0h-2v-3h2v3z"/></svg>
                ResearchGate: Connected
            </a>
        <?php else: ?>
            <?php
            $rgEditUrl = $profileUrl . '?tab=research#researchgate';
            foreach ($quickEdits as $edit) {
                if ($edit['label'] === 'ResearchGate') {
                    $rgEditUrl = $edit['url'];
                    break;
                }
            }
            ?>
            <a href="<?= htmlspecialchars($rgEditUrl) ?>" class="faculty-profile-link faculty-profile-link--add">
                + Add ResearchGate
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($interests)): ?>
        <div class="faculty-profile-interests">
            <strong>Research Interests:</strong>
            <span>Configured</span>
        </div>
    <?php endif; ?>

    <div class="faculty-panel-action">
        <?php
        $interestsEditUrl = $profileUrl . '?tab=research#interests';
        foreach ($quickEdits as $edit) {
            if ($edit['label'] === 'Research Interests') {
                $interestsEditUrl = $edit['url'];
                break;
            }
        }
        ?>
        <a href="<?= htmlspecialchars($interestsEditUrl) ?>" class="faculty-btn faculty-btn--secondary faculty-btn--sm">
            Edit Interests
        </a>
    </div>
</div>
