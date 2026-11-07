<?php
// ============================================================
// RESEARCH MANAGEMENT SYSTEM — AUTHENTICATION HELPERS
// ============================================================
require_once __DIR__ . '/staff-permission-schema.php';

const RMS_RESEARCH_ARCHIVE_RETURN_VALUE = 'research-archive';
const RMS_RESEARCH_ARCHIVE_RETURN_SESSION_KEY = 'rms_research_archive_return';
const RMS_PUBLIC_RECORD_RETURN_SESSION_KEY = 'rms_public_record_return';

function rmsSetPublicRecordReturnIntent($sourceType, $recordId): void
{
    $allowedTypes = ['student_project', 'faculty_publication', 'faculty_research_record'];
    $recordId = filter_var($recordId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_string($sourceType) || !in_array($sourceType, $allowedTypes, true) || !$recordId) {
        unset($_SESSION[RMS_PUBLIC_RECORD_RETURN_SESSION_KEY]);
        return;
    }
    $_SESSION[RMS_PUBLIC_RECORD_RETURN_SESSION_KEY] = ['type' => $sourceType, 'id' => (int) $recordId];
}

function rmsPublicRecordReturnIntent(): ?array
{
    $intent = $_SESSION[RMS_PUBLIC_RECORD_RETURN_SESSION_KEY] ?? null;
    if (!is_array($intent)) return null;
    $allowedTypes = ['student_project', 'faculty_publication', 'faculty_research_record'];
    $type = $intent['type'] ?? null;
    $id = filter_var($intent['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_string($type) && in_array($type, $allowedTypes, true) && $id
        ? ['type' => $type, 'id' => (int) $id]
        : null;
}

function rmsClearPublicRecordReturnIntent(): void
{
    unset($_SESSION[RMS_PUBLIC_RECORD_RETURN_SESSION_KEY]);
}

function rmsPublicRecordReturnUrl(): ?string
{
    $intent = rmsPublicRecordReturnIntent();
    return $intent
        ? SITE_URL . 'public/research-record.php?' . http_build_query(['type' => $intent['type'], 'id' => $intent['id']])
        : null;
}

/**
 * Store the one supported post-onboarding destination; never accept a URL here.
 */
function rmsSetResearchArchiveReturnIntent($value) {
    if ($value === RMS_RESEARCH_ARCHIVE_RETURN_VALUE) {
        $_SESSION[RMS_RESEARCH_ARCHIVE_RETURN_SESSION_KEY] = RMS_RESEARCH_ARCHIVE_RETURN_VALUE;
    }
}

function rmsHasResearchArchiveReturnIntent() {
    return isset($_SESSION[RMS_RESEARCH_ARCHIVE_RETURN_SESSION_KEY])
        && $_SESSION[RMS_RESEARCH_ARCHIVE_RETURN_SESSION_KEY] === RMS_RESEARCH_ARCHIVE_RETURN_VALUE;
}

function rmsClearResearchArchiveReturnIntent() {
    unset($_SESSION[RMS_RESEARCH_ARCHIVE_RETURN_SESSION_KEY]);
}

/**
 * Check the live account once per request, never trusting session presence alone.
 */
function isLoggedIn() {
    global $conn;
    static $validatedUserId = null;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    if ($validatedUserId === $userId) {
        return true;
    }

    $stmt = $conn->prepare("SELECT user_id, password FROM users WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $active = false;
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $account = $result ? $result->fetch_assoc() : null;
            $active = is_array($account)
                && isset($_SESSION['auth_password_hash'])
                && hash_equals((string) $_SESSION['auth_password_hash'], (string) $account['password']);
        }
        $stmt->close();
    }

    if (!$active) {
        // Drop identity, role, CSRF token and other authenticated session state.
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $validatedUserId = null;
        return false;
    }

    $_SESSION['auth_password_hash'] = (string) $account['password'];
    $validatedUserId = $userId;
    return true;
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
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
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
 * Count unread notifications linked to the research workspace sidebar groups.
 */
function getUnreadNotificationCountsByArea($user_id) {
    global $conn;

    $counts = ['my_research' => 0, 'research_modules' => 0];
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return $counts;
    }

    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(link, '')) LIKE '%pages/researcher/%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/research-detail.php%'
                THEN 1 ELSE 0
            END), 0) AS my_research_count,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(link, '')) LIKE '%pages/shared/activities.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/activity-detail.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/publications.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/publication-detail.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/faculty/faculty-publications.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/faculty/faculty-publication-form.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/faculty/faculty-publication-detail.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/faculty/faculty-publication-reports.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/admin/admin-publications.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/admin/admin-publication-form.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/admin/admin-publication-detail.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/admin/admin-publication-reports.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/copyrights.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/copyright-detail.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/implementation-outputs.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/research-archive.php%'
                  OR LOWER(COALESCE(link, '')) LIKE '%pages/shared/research-submission-review.php%'
                THEN 1 ELSE 0
            END), 0) AS research_modules_count
         FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load unread notification group counts: ' . $conn->error);
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $counts['my_research'] = (int) ($row['my_research_count'] ?? 0);
    $counts['research_modules'] = (int) ($row['research_modules_count'] ?? 0);
    return $counts;
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
        return false;
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

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
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
                'add_activity' => 'Add Activity',
                'add_upcoming_activity' => 'Add Upcoming Activity',
                'add_conducted_activity' => 'Add Completed Activity',
                'view_activity' => 'Research Activities',
                'upcoming_activities' => 'Upcoming',
                'ongoing_activities' => 'Ongoing',
                'conducted_activities' => 'Completed',
            ],
        ],
        'research_development' => [
            'label' => 'Research Development',
            'pages' => [
                'research_proposals' => 'Research Proposals',
                'research_review' => 'Chapter Review',
                'research_approval' => 'Approved Proposals',
                'research_engagement_records' => 'Research Engagement Records',
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
                'publication_records' => 'All Records',
                'publication_add' => 'Add New',
                'publication_reports' => 'Reports',
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

    if (defined('RMS_E2E_MODE') && RMS_E2E_MODE === true) {
        rmsEnsureStaffPermissionSchema(
            true,
            static function () use ($conn): array {
                try {
                    $columnsResult = $conn->query("SHOW COLUMNS FROM `staff_permissions`");
                } catch (mysqli_sql_exception $error) {
                    return [];
                }
                if (!$columnsResult instanceof mysqli_result) {
                    return [];
                }
                $columns = [];
                while ($column = $columnsResult->fetch_assoc()) {
                    $columns[$column['Field']] = [
                        'type' => strtolower((string) $column['Type']),
                        'null' => (string) $column['Null'],
                        'default' => $column['Default'],
                        'extra' => (string) $column['Extra'],
                    ];
                }
                $columnsResult->free();

                try {
                    $indexesResult = $conn->query("SHOW INDEX FROM `staff_permissions`");
                } catch (mysqli_sql_exception $error) {
                    return [];
                }
                if (!$indexesResult instanceof mysqli_result) {
                    return [];
                }
                $indexes = [];
                while ($index = $indexesResult->fetch_assoc()) {
                    $name = (string) $index['Key_name'];
                    $indexes[$name] ??= [
                        'nonUnique' => (int) $index['Non_unique'],
                        'columns' => [],
                    ];
                    $indexes[$name]['columns'][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
                }
                $indexesResult->free();
                foreach ($indexes as &$index) {
                    ksort($index['columns']);
                    $index['columns'] = array_values($index['columns']);
                }
                unset($index);

                return ['columns' => $columns, 'indexes' => $indexes];
            }
        );
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
            `download_allowed` tinyint(1) NOT NULL DEFAULT 0,
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
            $matrix[$moduleKey]['pages'][$subKey] = $subKey === 'publication_add' ? 'none' : 'view';
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

    $exists = $conn->prepare('SELECT COUNT(*) AS permission_count FROM staff_permissions WHERE user_id = ?');
    $exists->bind_param('i', $user_id);
    $exists->execute();
    $existingCount = (int) ($exists->get_result()->fetch_assoc()['permission_count'] ?? 0);
    $exists->close();
    $isNewMatrix = $existingCount === 0;

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
            } elseif ($force) {
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
            if ($pageExists && $force) {
                $update = $conn->prepare('UPDATE staff_permissions SET access_level = ? WHERE user_id = ? AND module_key = ? AND subpage_key = ?');
                if ($update) {
                    $update->bind_param('siss', $accessLevel, $user_id, $moduleKeyValue, $subpageKey);
                    $update->execute();
                    $update->close();
                }
            } elseif (!$pageExists) {
                $insertLevel = $force || $isNewMatrix ? $accessLevel : 'none';
                $insert = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level) VALUES (?, ?, ?, ?)');
                if ($insert) {
                    $insert->bind_param('isss', $user_id, $moduleKeyValue, $subpageKey, $insertLevel);
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
        $matrix[$moduleKey] = ['access' => 'none', 'pages' => [], 'downloads' => []];
        foreach ($moduleData['pages'] as $subpageKey => $label) {
            $matrix[$moduleKey]['pages'][$subpageKey] = 'none';
            $matrix[$moduleKey]['downloads'][$subpageKey] = false;
        }
    }

    $stmt = $conn->prepare('SELECT module_key, subpage_key, access_level, download_allowed FROM staff_permissions WHERE user_id = ?');
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
            if (array_key_exists($subpageKey, $matrix[$moduleKey]['pages'])) {
                $matrix[$moduleKey]['pages'][$subpageKey] = $level;
                $matrix[$moduleKey]['downloads'][$subpageKey] = (int) ($row['download_allowed'] ?? 0) === 1;
            }
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

function rmsCanDownloadWithStaffPermissionMatrix(
    bool $isAdmin,
    bool $isResearchStaff,
    array $matrix,
    string $moduleKey,
    string $subpageKey
): bool {
    if ($isAdmin) {
        return true;
    }
    if (!$isResearchStaff
        || !isset($matrix[$moduleKey]['downloads'])
        || !array_key_exists($subpageKey, $matrix[$moduleKey]['downloads'])) {
        return false;
    }

    return $matrix[$moduleKey]['downloads'][$subpageKey] === true;
}

function hasDownloadPermission(int $userId, string $moduleKey, string $subpageKey): bool
{
    if ($userId <= 0) {
        return false;
    }

    $user = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    if (!$user) {
        return false;
    }
    $user->bind_param('i', $userId);
    $user->execute();
    $roleRow = $user->get_result()->fetch_assoc();
    $user->close();

    if (!$roleRow) {
        return false;
    }
    if ((string) $roleRow['role'] === 'admin') {
        return true;
    }
    if ((string) $roleRow['role'] !== 'research_staff') {
        return false;
    }

    return rmsCanDownloadWithStaffPermissionMatrix(
        false,
        true,
        getStaffPermissionMatrix($userId),
        $moduleKey,
        $subpageKey
    );
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

function saveStaffPermissionCollection(int $user_id, array $moduleAccess, array $downloadAccess = []): bool
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
        $moduleDownload = 0;
        $moduleStmt = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), download_allowed = VALUES(download_allowed)');
        if ($moduleStmt) {
            $moduleStmt->bind_param('isssi', $user_id, $moduleKey, $moduleEmpty, $moduleLevel, $moduleDownload);
            $moduleStmt->execute();
            $moduleStmt->close();
        }

        foreach ($moduleConfig['pages'] as $subpageKey => $label) {
            $level = 'none';
            if (isset($moduleAccess[$moduleKey]['pages'][$subpageKey]) && in_array($moduleAccess[$moduleKey]['pages'][$subpageKey], ['none', 'view', 'manage'], true)) {
                $level = $moduleAccess[$moduleKey]['pages'][$subpageKey];
            }
            $downloadAllowed = isset($downloadAccess[$moduleKey]['pages'][$subpageKey])
                && (string) $downloadAccess[$moduleKey]['pages'][$subpageKey] === '1' ? 1 : 0;

            $subStmt = $conn->prepare('INSERT INTO staff_permissions (user_id, module_key, subpage_key, access_level, download_allowed) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), download_allowed = VALUES(download_allowed)');
            if ($subStmt) {
                $subStmt->bind_param('isssi', $user_id, $moduleKey, $subpageKey, $level, $downloadAllowed);
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
