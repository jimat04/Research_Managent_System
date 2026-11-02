<?php
// Compatibility entry point for existing bookmarks and already-open forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/admin-research-management.php';
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
$query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . SITE_URL . 'pages/admin/admin-research-management.php' . ($query !== '' ? '?' . $query : ''));
exit;
