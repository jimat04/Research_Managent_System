<?php
/**
 * Path Update Script - Fixes all file paths after restructuring
 * Updates includes, CSS, redirects, and navigation links
 */

function updateFile($filepath, $replacements) {
    if (!file_exists($filepath)) {
        echo "⚠️  File not found: $filepath\n";
        return false;
    }

    $content = file_get_contents($filepath);
    $original = $content;

    foreach ($replacements as $old => $new) {
        $content = str_replace($old, $new, $content);
    }

    if ($content !== $original) {
        file_put_contents($filepath, $content);
        echo "✅ Updated: $filepath\n";
        return true;
    }

    return false;
}

echo "Starting path updates...\n\n";

// Update public files
$publicReplacements = [
    "include 'includes/config.php'" => "require_once __DIR__ . '/../includes/config.php'",
    "include 'includes/auth.php'" => "require_once __DIR__ . '/../includes/auth.php'",
    "include_once __DIR__ . '/includes/config.php'" => "require_once __DIR__ . '/../includes/config.php'",
    "href=\"css/style.css\"" => "href=\"../css/style.css\"",
    "href=\"css/about.css\"" => "href=\"../css/about.css\"",
    "href='css/style.css'" => "href='../css/style.css'",
    "pages/student-dashboard.php" => "../pages/student/student-dashboard.php",
    "pages/faculty-dashboard.php" => "../pages/faculty/faculty-dashboard.php",
    "pages/staff-dashboard.php" => "../pages/staff/staff-dashboard.php",
    "pages/admin-dashboard.php" => "../pages/admin/admin-dashboard.php",
    "action=\"contact.php\"" => "action=\"contact.php\"",
    "href=\"login.php\"" => "href=\"login.php\"",
    "href=\"about.php\"" => "href=\"about.php\"",
    "href=\"index.php\"" => "href=\"index.php\"",
];

$publicFiles = glob(__DIR__ . '/../public/*.php');
echo "=== Updating PUBLIC files ===\n";
foreach ($publicFiles as $file) {
    updateFile($file, $publicReplacements);
}

// Update pages/shared files
$sharedReplacements = [
    "include '../includes/config.php'" => "require_once __DIR__ . '/../../includes/config.php'",
    "include '../includes/auth.php'" => "require_once __DIR__ . '/../../includes/auth.php'",
    "include_once '../includes/config.php'" => "require_once __DIR__ . '/../../includes/config.php'",
    "include_once '../includes/auth.php'" => "require_once __DIR__ . '/../../includes/auth.php'",
];

$sharedFiles = glob(__DIR__ . '/../pages/shared/*.php');
echo "\n=== Updating SHARED files ===\n";
foreach ($sharedFiles as $file) {
    updateFile($file, $sharedReplacements);
}

// Update pages/student files
$studentFiles = glob(__DIR__ . '/../pages/student/*.php');
echo "\n=== Updating STUDENT files ===\n";
foreach ($studentFiles as $file) {
    updateFile($file, $sharedReplacements);
}

// Update pages/faculty files
$facultyFiles = glob(__DIR__ . '/../pages/faculty/*.php');
echo "\n=== Updating FACULTY files ===\n";
foreach ($facultyFiles as $file) {
    updateFile($file, $sharedReplacements);
}

// Update pages/admin files
$adminFiles = glob(__DIR__ . '/../pages/admin/*.php');
echo "\n=== Updating ADMIN files ===\n";
foreach ($adminFiles as $file) {
    updateFile($file, $sharedReplacements);
}

// Update pages/staff files
$staffFiles = glob(__DIR__ . '/../pages/staff/*.php');
echo "\n=== Updating STAFF files ===\n";
foreach ($staffFiles as $file) {
    updateFile($file, $sharedReplacements);
}

echo "\n✅ Path update complete!\n";
echo "\nNext steps:\n";
echo "1. Create an index.php in root that redirects to public/index.php\n";
echo "2. Test login and navigation\n";
echo "3. Update .htaccess if needed\n";
