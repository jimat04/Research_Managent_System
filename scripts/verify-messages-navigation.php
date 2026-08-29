<?php
/**
 * Messages Navigation Verification Script
 *
 * Verifies the internal messaging system setup and navigation structure.
 * Run from command line: php scripts/verify-messages-navigation.php
 */

require_once __DIR__ . '/../includes/config.php';

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  RMS Messages Navigation Verification\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$errors = [];
$warnings = [];
$passed = [];

// ============================================================
// 1. Check Database Tables
// ============================================================
echo "[1] Checking database tables...\n";

$tables = ['messages', 'contact_messages', 'users', 'notifications'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        $passed[] = "✓ Table '$table' exists";
    } else {
        $errors[] = "✗ Table '$table' missing";
    }
}

// Check messages table structure
$result = $conn->query("DESCRIBE messages");
if ($result) {
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    $required = ['message_id', 'sender_id', 'recipient_id', 'subject', 'message', 'is_read', 'created_at'];
    foreach ($required as $col) {
        if (in_array($col, $columns)) {
            $passed[] = "✓ messages.$col column exists";
        } else {
            $errors[] = "✗ messages.$col column missing";
        }
    }
}

// Check contact_messages table structure
$result = $conn->query("DESCRIBE contact_messages");
if ($result) {
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    $required = ['contact_id', 'name', 'email', 'subject', 'message', 'status'];
    foreach ($required as $col) {
        if (in_array($col, $columns)) {
            $passed[] = "✓ contact_messages.$col column exists";
        } else {
            $errors[] = "✗ contact_messages.$col column missing";
        }
    }
}

echo "\n";

// ============================================================
// 2. Check Test Users by Role
// ============================================================
echo "[2] Checking test user accounts...\n";

$roles = [
    'student' => 'jdelacruz@rms.edu.ph',
    'faculty' => 'msantos@rms.edu.ph',
    'admin' => 'admin@rms.edu.ph'
];

$user_ids = [];

foreach ($roles as $role => $email) {
    $stmt = $conn->prepare("SELECT user_id, email, role, status FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['status'] === 'active') {
            $passed[] = "✓ $role account active: {$row['email']}";
            $user_ids[$role] = $row['user_id'];
        } else {
            $warnings[] = "⚠ $role account inactive: {$row['email']}";
        }
    } else {
        $errors[] = "✗ $role account not found: $email";
    }
    $stmt->close();
}

// Check for research_staff
$stmt = $conn->prepare("SELECT user_id, email, status FROM users WHERE role = 'research_staff' LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'active') {
        $passed[] = "✓ research_staff account active: {$row['email']}";
        $user_ids['research_staff'] = $row['user_id'];
    } else {
        $warnings[] = "⚠ research_staff account inactive: {$row['email']}";
    }
} else {
    $warnings[] = "⚠ No research_staff account found (optional for testing)";
}
$stmt->close();

echo "\n";

// ============================================================
// 3. Check Critical Files
// ============================================================
echo "[3] Checking critical files...\n";

$files = [
    'pages/shared/module-page.php' => 'Shared module router',
    'pages/shared/messages.php' => 'Messages entry point',
    'includes/module-pages.php' => 'Module action handler',
    'pages/admin/admin-contact.php' => 'Admin contact messages',
    'pages/staff/contact-messages.php' => 'Staff contact messages'
];

foreach ($files as $path => $desc) {
    $full_path = __DIR__ . '/../' . $path;
    if (file_exists($full_path)) {
        $passed[] = "✓ $desc exists";
    } else {
        $errors[] = "✗ $desc missing: $path";
    }
}

echo "\n";

// ============================================================
// 4. Check Existing Messages Data
// ============================================================
echo "[4] Checking messages data...\n";

$result = $conn->query("SELECT COUNT(*) as total FROM messages");
$row = $result->fetch_assoc();
$message_count = $row['total'];

if ($message_count > 0) {
    $passed[] = "✓ Found $message_count internal message(s)";
} else {
    $warnings[] = "⚠ No internal messages in database (empty state is valid)";
}

$result = $conn->query("SELECT COUNT(*) as total FROM contact_messages");
$row = $result->fetch_assoc();
$contact_count = $row['total'];

if ($contact_count > 0) {
    $passed[] = "✓ Found $contact_count public contact message(s)";
} else {
    $warnings[] = "⚠ No public contact messages in database (empty state is valid)";
}

echo "\n";

// ============================================================
// 5. Create Test Message (Optional)
// ============================================================
echo "[5] Test message creation...\n";

if (isset($user_ids['admin']) && isset($user_ids['student'])) {
    $sender = $user_ids['admin'];
    $recipient = $user_ids['student'];
    $subject = "Navigation Test Message";
    $message = "This is an automated test message created by verify-messages-navigation.php on " . date('Y-m-d H:i:s');

    // Check if test message already exists
    $stmt = $conn->prepare("SELECT message_id FROM messages WHERE sender_id = ? AND recipient_id = ? AND subject = ?");
    $stmt->bind_param('iis', $sender, $recipient, $subject);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $warnings[] = "⚠ Test message already exists (skipping creation)";
    } else {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, recipient_id, subject, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $sender, $recipient, $subject, $message);

        if ($stmt->execute()) {
            $passed[] = "✓ Created test message: Admin → Student";
            $passed[] = "  Subject: '$subject'";
            $passed[] = "  Login as student to verify inbox receipt";
        } else {
            $errors[] = "✗ Failed to create test message: " . $conn->error;
        }
        $stmt->close();
    }
} else {
    $warnings[] = "⚠ Cannot create test message (missing admin or student account)";
}

echo "\n";

// ============================================================
// 6. Check Navigation Link Patterns
// ============================================================
echo "[6] Checking navigation link patterns...\n";

$files_to_check = [
    'pages/student/student-dashboard.php',
    'pages/faculty/faculty-dashboard.php',
    'pages/admin/admin-dashboard.php',
    'pages/staff/staff-dashboard.php'
];

foreach ($files_to_check as $file) {
    $full_path = __DIR__ . '/../' . $file;
    if (file_exists($full_path)) {
        $content = file_get_contents($full_path);

        // Check for correct shared message link
        if (strpos($content, "../shared/messages.php") !== false) {
            $passed[] = "✓ " . basename($file) . " uses correct Messages path";
        } else if (strpos($content, "messages.php") !== false) {
            $warnings[] = "⚠ " . basename($file) . " may have incorrect Messages path";
        }
    }
}

echo "\n";

// ============================================================
// Summary Report
// ============================================================
echo "═══════════════════════════════════════════════════════════════\n";
echo "  VERIFICATION SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if (count($passed) > 0) {
    echo "PASSED (" . count($passed) . "):\n";
    foreach ($passed as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

if (count($errors) > 0) {
    echo "ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

// ============================================================
// Manual Browser Testing Guide
// ============================================================
echo "═══════════════════════════════════════════════════════════════\n";
echo "  MANUAL BROWSER TESTING REQUIRED\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "Next Steps:\n\n";

echo "1. Student Navigation Test\n";
echo "   • Login: jdelacruz@rms.edu.ph / Student@123\n";
echo "   • Click 'Messages' in Communication section\n";
echo "   • Verify: Sidebar shows Student navigation with Messages active\n";
echo "   • Verify: Can navigate back to Dashboard, My Research, etc.\n\n";

echo "2. Faculty Navigation Test\n";
echo "   • Login: msantos@rms.edu.ph / Faculty@123\n";
echo "   • Click 'Messages' in Communication section\n";
echo "   • Verify: Sidebar shows Faculty navigation with Messages active\n";
echo "   • Verify: Can navigate to Review Queue, My Students, etc.\n\n";

echo "3. Admin Navigation Test\n";
echo "   • Login: admin@rms.edu.ph / Admin@123\n";
echo "   • Click 'Messages' in Communication section\n";
echo "   • Verify: Sidebar shows Admin navigation with Messages active\n";
echo "   • Verify: Both 'Messages' and 'Contact Messages' visible\n";
echo "   • Click 'Contact Messages' → should route to admin-contact.php\n\n";

echo "4. Message Sending Test\n";
echo "   • As Admin, compose and send message to Student\n";
echo "   • Verify: Message appears in Admin's Sent Messages\n";
echo "   • Logout and login as Student\n";
echo "   • Verify: Message appears in Student inbox\n";
echo "   • Verify: Notification created (if any)\n";
echo "   • Mark message as read → status updates\n\n";

echo "5. Contact Messages Separation Test\n";
echo "   • As Admin, navigate to Contact Messages\n";
echo "   • Verify: Page title shows 'Contact Messages'\n";
echo "   • Verify: Description mentions 'public contact form'\n";
echo "   • Verify: Shows contact_messages table (NOT internal messages)\n\n";

if (count($errors) === 0) {
    echo "✓ Database structure verification PASSED\n";
    echo "  Proceed with manual browser testing above.\n\n";
    exit(0);
} else {
    echo "✗ Database structure verification FAILED\n";
    echo "  Fix errors above before proceeding.\n\n";
    exit(1);
}
