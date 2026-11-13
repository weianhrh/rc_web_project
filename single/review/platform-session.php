<?php
declare(strict_types=1);
require_once __DIR__ . '/_platform.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') platform_fail('不支持的请求方法', 405);
platform_authorize();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['csrf' => platform_csrf()], JSON_UNESCAPED_UNICODE);
