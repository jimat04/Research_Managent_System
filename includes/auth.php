<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — AUTHENTICATION HELPERS
// ============================================================

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user data
 */
function getCurrentUser() {
    global $conn;
    
    if (!isLoggedIn()) {
        return null;
    }
    
    $user_id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, role, student_id, department, program, contact, avatar, status, is_reviewer, created_at FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Check user role
 */
function hasRole($role) {
    if (!isLoggedIn()) return false;
    return $_SESSION['role'] === $role;
}

/**
 * Require specific role(s), redirect if not authorized
 * Accepts string or array of roles. Admins always pass.
 */
function requireRole($roles) {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'public/login.php');
        exit();
    }

    // Convert single role to array
    if (is_string($roles)) {
        $roles = [$roles];
    }

    // Check if user has any of the required roles or is admin
    $hasAccess = false;
    foreach ($roles as $role) {
        if (hasRole($role)) {
            $hasAccess = true;
            break;
        }
    }

    // Admins always have access
    if (hasRole('admin')) {
        $hasAccess = true;
    }

    if (!$hasAccess) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit();
    }
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'public/login.php');
        exit();
    }
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * CSRF token management
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function isCsrfTokenValid($token) {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input
 */
function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Create notification
 *
 * Uses a prepared statement. $link is bound as NULL when null/empty,
 * not as an empty string, so the nullable column stays semantically NULL.
 */
function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    global $conn;

    $user_id = intval($user_id);
    $title   = (string) $title;
    $message = (string) $message;
    $type    = (string) $type;
    $hasLink = ($link !== null && $link !== '');
    $linkVal = $hasLink ? (string) $link : null;

    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type, link)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt === false) {
        return false;
    }

    if ($hasLink) {
        $stmt->bind_param('issss', $user_id, $title, $message, $type, $linkVal);
    } else {
        // bind_param requires a reference; use a reusable $null variable for the NULL slot.
        $null = null;
        $stmt->bind_param('issss', $user_id, $title, $message, $type, $null);
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Log activity
 *
 * Uses a prepared statement. $user_id and $module are bound as NULL when
 * not set, so the nullable columns stay semantically NULL.
 */
function logActivity($action, $module = null) {
    global $conn;

    $action    = (string) $action;
    $hasUser   = isLoggedIn();
    $user_id   = $hasUser ? intval($_SESSION['user_id']) : null;
    $hasModule = ($module !== null && $module !== '');
    $moduleVal = $hasModule ? (string) $module : null;
    $ip        = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    $stmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, action, module, ip_address)
         VALUES (?, ?, ?, ?)"
    );
    if ($stmt === false) {
        return;
    }

    // Type string depends on which nullable columns are NULL.
    //   user_id INT when logged in, NULL when anonymous
    //   module NULL when not provided
    //   ip is always a non-null string
    if ($hasUser && $hasModule) {
        $stmt->bind_param('isss', $user_id, $action, $moduleVal, $ip);
    } elseif ($hasUser && !$hasModule) {
        $null = null;
        $stmt->bind_param('isss', $user_id, $action, $null, $ip);
    } elseif (!$hasUser && $hasModule) {
        $null = null;
        $stmt->bind_param('ssss', $null, $action, $moduleVal, $ip);
    } else {
        $null1 = null;
        $null2 = null;
        $stmt->bind_param('ssss', $null1, $action, $null2, $ip);
    }

    $stmt->execute();
    $stmt->close();
}

/**
 * Research management access matrix for staff roles.
 */
function rmsPermissionModules(): array
{
    return [
        'research_capacity_building' => [
            'label' => 'Research Capacity Building',
            'pages' => [
                'seminars' => 'Seminars',
                'trainings' => 'Trainings',
                'workshops' => 'Workshops',
            ],
        ],
        'research_development' => [
            'label' => 'Research Development',
            'pages' => [
                'research_proposals' => 'Research Proposals',
                'research_review' => 'Research Review',
                'research_approval' => 'Research Approval',
            ],
        ],
        'research_implementation_outputs' => [
            'label' => 'Research Implementation & Outputs',
            'pages' => [
                'ongoing_research' => 'Ongoing Research',
                'completed_research' => 'Completed Research',
                'research_outputs' => 'Research Outputs',
            ],
        ],
        'publication_dissemination' => [
            'label' => 'Publication & Dissemination',
            'pages' => [
                'journal_publications' => 'Journal Publications',
                'conference_presentations' => 'Conference Presentations',
                'research_repository' => 'Research Repository',
            ],
        ],
        'intellectual_property_utilization' => [
            'label' => 'Intellectual Property & Utilization',
            'pages' => [
                'copyrights' => 'Copyrights',
                'patents_utility_models' => 'Patents / Utility Models',
                'innovation_technology_transfer' => 'Innovation & Technology Transfer',
            ],
        ],
    ];
}

function ensureStaffPermissionTable(): void
{
    global $conn;

    if (!($conn instanceof mysqli)) {
        return;
    }

    $check = $conn->query("SHOW TABLES LIKE 'staff_permissions'");
    if ($check instanceof mysqli_result && $check->num_rows > 0) {
        if ($check instanceof mysqli_result) {
            $check->free();
        }
        $conn->query("ALTER TABLE `staff_permissions` MODIFY `subpage_key` varchar(64) NOT NULL DEFAULT ''");
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `staff_permissions` (
            `permission_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` int UNSIGNED NOT NULL,
            `module_key` varchar(64) NOT NULL,
            `subpage_key` varchar(64) NOT NULL DEFAULT '',
            `access_level` enum('none','view','manage') NOT NULL DEFAULT 'none',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`permission_id`),
            UNIQUE KEY `uq_staff_permissions_user_module_subpage` (`user_id`, `module_key`, `subpage_key`),
            KEY `idx_staff_permissions_user` (`user_id`),
            KEY `idx_staff_permissions_module` (`module_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function rmsDefaultStaffPermissions(): array
{
    $matrix = [];
    foreach (rmsPermissionModules() as $moduleKey => $module) {
        $matrix[$moduleKey] = [
            'access' => 'view',
            'pages' => [],
        ];
        foreach ($module['pages'] as $subKey => $label) {
            $matrix[$moduleKey]['pages'][$subKey] = 'view';
        }
    }

    return $matrix;
}

function seedDefaultStaffPermissions(int $user_id, bool $force = false): void
{
    global $conn;

    if ($user_id <= 0) {
        return;
    }

    ensureStaffPermissionTable();

    if (!$force) {
        $exists = $conn->prepare('SELECT permission_id FROM staff_permissions WHERE user_id = ? LIMIT 1');
        if ($exists) {
            $exists->bind_param('i', $user_id);
            $exists->execute();
            $hasPermission = $exists->get_result()->num_rows > 0;
            $exists->close();
            if ($hasPermission) {
                return;
            }
        }
    }

    $defaults = rmsDefaultStaffPermissions();
    foreach ($defaults as $moduleKey => $moduleData) {
        $moduleKeyValue = $moduleKey;
        $moduleLevel = $moduleData['access'];
        $empty = '';
        $moduleCheck = $conn->prepare('SELECT permission_id FROM staff_permissions WHERE user_id = ? AND module_key = ? AND subpage_key = ? LIMIT 1');
        if ($moduleCheck) {
            $moduleCheck->bind_param('iss', $user_id, $moduleKeyValue, $empty);
            $moduleCheck->execute();
            $moduleExists = $moduleCheck->get_result()->num_rows > 0;
            $moduleCheck->close();
            if (!$moduleExists) {
                $insert = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level) VALUES (?, ?, ?, ?)');
                if ($insert) {
                    $insert->bind_param('isss', $user_id, $moduleKeyValue, $empty, $moduleLevel);
                    $insert->execute();
                    $insert->close();
                }
            } else {
                $update = $conn->prepare('UPDATE staff_permissions SET access_level = ? WHERE user_id = ? AND module_key = ? AND subpage_key = ?');
                if ($update) {
                    $update->bind_param('siss', $moduleLevel, $user_id, $moduleKeyValue, $empty);
                    $update->execute();
                    $update->close();
                }
            }
        }

        foreach ($moduleData['pages'] as $subpageKey => $accessLevel) {
            $pageCheck = $conn->prepare('SELECT permission_id FROM staff_permissions WHERE user_id = ? AND module_key = ? AND subpage_key = ? LIMIT 1');
            if (!$pageCheck) {
                continue;
            }
            $pageCheck->bind_param('iss', $user_id, $moduleKeyValue, $subpageKey);
            $pageCheck->execute();
            $pageExists = $pageCheck->get_result()->num_rows > 0;
            $pageCheck->close();
            if ($pageExists) {
                $update = $conn->prepare('UPDATE staff_permissions SET access_level = ? WHERE user_id = ? AND module_key = ? AND subpage_key = ?');
                if ($update) {
                    $update->bind_param('siss', $accessLevel, $user_id, $moduleKeyValue, $subpageKey);
                    $update->execute();
                    $update->close();
                }
            } else {
                $insert = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level) VALUES (?, ?, ?, ?)');
                if ($insert) {
                    $insert->bind_param('isss', $user_id, $moduleKeyValue, $subpageKey, $accessLevel);
                    $insert->execute();
                    $insert->close();
                }
            }
        }
    }
}

function getStaffPermissionMatrix(int $user_id): array
{
    global $conn;

    if ($user_id <= 0) {
        return [];
    }

    ensureStaffPermissionTable();
    seedDefaultStaffPermissions($user_id);

    $matrix = [];
    foreach (rmsPermissionModules() as $moduleKey => $moduleData) {
        $matrix[$moduleKey] = ['access' => 'none', 'pages' => []];
        foreach ($moduleData['pages'] as $subpageKey => $label) {
            $matrix[$moduleKey]['pages'][$subpageKey] = 'none';
        }
    }

    $stmt = $conn->prepare('SELECT module_key, subpage_key, access_level FROM staff_permissions WHERE user_id = ?');
    if (!$stmt) {
        return $matrix;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $moduleKey = (string) ($row['module_key'] ?? '');
        $subpageKey = $row['subpage_key'] ?? null;
        $level = in_array((string) $row['access_level'], ['none', 'view', 'manage'], true) ? (string) $row['access_level'] : 'none';
        if (!isset($matrix[$moduleKey])) {
            continue;
        }
        if ($subpageKey === null || $subpageKey === '') {
            $matrix[$moduleKey]['access'] = $level;
        } else {
            $matrix[$moduleKey]['pages'][$subpageKey] = $level;
        }
    }
    $stmt->close();

    foreach ($matrix as $moduleKey => $moduleData) {
        if ($moduleData['access'] === 'none' && array_filter($moduleData['pages'], static fn ($level) => $level !== 'none')) {
            $matrix[$moduleKey]['access'] = 'view';
        }
    }

    return $matrix;
}

function hasModulePermission(int $user_id, string $module_key, string $required = 'view', ?string $subpage_key = null): bool
{
    if ($user_id <= 0) {
        return false;
    }

    $user = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    if ($user) {
        $user->bind_param('i', $user_id);
        $user->execute();
        $roleRow = $user->get_result()->fetch_assoc();
        $user->close();
        if (($roleRow['role'] ?? '') === 'admin') {
            return true;
        }
    }

    $matrix = getStaffPermissionMatrix($user_id);
    if (!isset($matrix[$module_key])) {
        return false;
    }

    if ($subpage_key !== null && $subpage_key !== '') {
        $level = $matrix[$module_key]['pages'][$subpage_key] ?? 'none';
    } else {
        $level = $matrix[$module_key]['access'] ?? 'none';
    }

    if ($required === 'manage') {
        if ($subpage_key === null || $subpage_key === '') {
            return false;
        }
        return $level === 'manage';
    }

    return in_array($level, ['view', 'manage'], true);
}

function requireModulePermission(string $module_key, string $required = 'view', ?string $subpage_key = null): void
{
    if (isLoggedIn() && hasRole('admin')) {
        return;
    }

    $user = getCurrentUser();
    if (!$user || !hasModulePermission((int) ($user['user_id'] ?? 0), $module_key, $required, $subpage_key)) {
        header('Location: ' . SITE_URL . 'public/403.php');
        exit;
    }
}

function saveStaffPermissionCollection(int $user_id, array $moduleAccess): bool
{
    global $conn;

    if ($user_id <= 0) {
        return false;
    }

    ensureStaffPermissionTable();
    $allModules = rmsPermissionModules();

    foreach ($allModules as $moduleKey => $moduleConfig) {
        $moduleLevel = 'none';
        if (isset($moduleAccess[$moduleKey]['access']) && in_array($moduleAccess[$moduleKey]['access'], ['none', 'view'], true)) {
            $moduleLevel = $moduleAccess[$moduleKey]['access'];
        }

        $moduleEmpty = '';
        $moduleStmt = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)');
        if ($moduleStmt) {
            $moduleStmt->bind_param('isss', $user_id, $moduleKey, $moduleEmpty, $moduleLevel);
            $moduleStmt->execute();
            $moduleStmt->close();
        }

        foreach ($moduleConfig['pages'] as $subpageKey => $label) {
            $level = 'none';
            if (isset($moduleAccess[$moduleKey]['pages'][$subpageKey]) && in_array($moduleAccess[$moduleKey]['pages'][$subpageKey], ['none', 'view', 'manage'], true)) {
                $level = $moduleAccess[$moduleKey]['pages'][$subpageKey];
            }

            $subStmt = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)');
            if ($subStmt) {
                $subStmt->bind_param('isss', $user_id, $moduleKey, $subpageKey, $level);
                $subStmt->execute();
                $subStmt->close();
            }
        }
    }

    return true;
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Redirect with message
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['message']      = $message;
    $_SESSION['message_type'] = $type;
    header('Location: ' . $url);
    exit();
}

/**
 * Get and clear message
 */
function getMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type    = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : 'success';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Display alert message
 */
function displayMessage() {
    $msg = getMessage();
    
    if (!$msg) return '';
    
    $icon = ['success' => '✓', 'error' => '✕', 'warning' => '⚠', 'info' => 'ℹ'][$msg['type']] ?? 'ℹ';
    $colors = [
        'success' => '#22c55e',
        'error'   => '#ef4444',
        'warning' => '#f59e0b',
        'info'    => '#0F6CBD'
    ];
    $color = $colors[$msg['type']] ?? '#0F6CBD';
    
    echo <<<HTML
    <div class="alert alert-{$msg['type']}" style="
        position: fixed; top: 20px; right: 20px; z-index: 9999;
        background: white; padding: 16px 20px; border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        display: flex; align-items: center; gap: 12px;
        animation: slideIn 0.3s ease;
    ">
        <span style="
            width: 24px; height: 24px; border-radius: 50%;
            background: $color; color: white; display: flex;
            align-items: center; justify-content: center;
            font-weight: bold; font-size: 0.75rem; flex-shrink: 0;
        ">$icon</span>
        <span style="color: #334155; font-size: 0.875rem;">{$msg['message']}</span>
        <button onclick="this.parentElement.remove()" style="
            background: none; border: none; color: #94a3b8;
            font-size: 1.2rem; cursor: pointer; padding: 0;
        ">×</button>
    </div>
    HTML;
}
?>
