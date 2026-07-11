<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$user = review_require_user();
$config = review_config();
$moduleKey = trim((string)($_GET['module'] ?? 'ai'));
if (!isset($config['modules'][$moduleKey])) {
    http_response_code(400);
    exit('无效审核模块');
}
$target = (string)$config['modules'][$moduleKey]['kwx'];
$ticket = review_kwx_ticket($user, $target);
$url = (string)$config['kwx_sso_url'] . '?ticket=' . rawurlencode($ticket);
header('Cache-Control: no-store');
header('Location: ' . $url);
exit;
