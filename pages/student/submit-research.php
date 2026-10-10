<?php
require_once __DIR__ . '/../../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/../researcher/submit-research.php';
    exit;
}

$query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
$target = SITE_URL . 'pages/researcher/submit-research.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
