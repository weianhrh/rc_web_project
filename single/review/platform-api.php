<?php
declare(strict_types=1);
require_once __DIR__ . '/_platform.php';
require_once __DIR__ . '/_bridge_policy.php';
$method = (string)$_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) platform_fail('不支持的请求方法', 405);
$user = platform_authorize($method === 'POST');
$source = platform_source();
$target = (string)($_GET['path'] ?? '');
$body = (string)file_get_contents('php://input');
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded');
if (strlen($body) > 2097152) platform_fail('请求内容过大', 413);
if ($method === 'POST' && !preg_match('~^(application/json|application/x-www-form-urlencoded)(?:;|$)~i', $contentType)) platform_fail('不支持的请求格式', 415);
try {
    $route = review_bridge_validate_request($method, $target, $body, $_POST);
} catch (Throwable $e) {
    platform_fail($e->getMessage(), 403);
}
if ($source === 'xh') platform_send_response(platform_xh_response($user, $method, $target, $body, $contentType));
platform_rc_admin($user);
$_GET = $route['query'];
$_REQUEST = array_merge($_GET, $_POST);
$_SERVER['QUERY_STRING'] = explode('?', $target, 2)[1] ?? '';
if ($route['path'] === '/api/operat/image_proxy.php') platform_vendor_image_response((string)$_GET['url']);
if (!empty($route['media'])) platform_fail('请通过审核图片入口读取图片', 403);
$_SERVER['REQUEST_URI'] = $target;
$_SERVER['SCRIPT_NAME'] = $route['path'];
$_SERVER['PHP_SELF'] = $route['path'];
$file = dirname(__DIR__) . $route['path'];
if (!is_file($file)) platform_fail('RC 审核接口不存在', 404);
chdir(dirname($file));
require $file;
