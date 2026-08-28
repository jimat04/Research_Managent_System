<?php
/**
 * Contact message handler with role-based routing
 * Routes messages to appropriate staff based on concern type
 */

require_once 'config.php';

/**
 * Route concern to appropriate recipient roles
 * Returns an array of roles that should receive the message
 *
 * Based on Research Manual 2015 role responsibilities:
 * - Research Staff: Handles office operations, consolidation, documentation
 * - Faculty: Provides academic advisory and review
 * - Admin: System administration and research coordination
 */
function getRecipientRolesForConcern($concern_type) {
    $routing = [
        'Technical Support' => ['admin', 'research_staff'], // System & office tech issues
        'Research Advisory' => ['faculty', 'research_staff'], // Academic & procedural guidance
        'General Inquiry' => ['research_staff'], // Office handles general inquiries
        'Account Issue' => ['admin'], // Account management
        'Other' => ['research_staff', 'admin'] // General support
    ];

    return $routing[$concern_type] ?? ['admin'];
}

/**
 * Get active users by role for message distribution
 */
function getActiveUsersByRole($conn, $role) {
    $stmt = $conn->prepare("
        SELECT user_id, email, first_name, last_name
        FROM users
        WHERE role = ? AND status = 'active'
        ORDER BY user_id ASC
    ");
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    return $users;
}

/**
 * Save contact message to database
 * Contact messages are managed separately in the Contact Messages page
 * and do NOT create internal messages in the Messages inbox
 */
function saveContactMessage($conn, $name, $email, $concern_type, $message) {
    try {
        // Insert into contact_messages table
        $stmt = $conn->prepare("
            INSERT INTO contact_messages (name, email, concern_type, message, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param('ssss', $name, $email, $concern_type, $message);

        if (!$stmt->execute()) {
            throw new Exception('Failed to save contact message');
        }

        $contact_message_id = $conn->insert_id;
        $stmt->close();

        return [
            'success' => true,
            'contact_message_id' => $contact_message_id
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
