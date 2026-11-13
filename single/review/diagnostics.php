<?php
declare(strict_types=1);
require_once __DIR__ . '/_platform.php';
platform_authorize();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') platform_fail('不支持的请求方法', 405);
$disabledClasses = array_map('trim', explode(',', strtolower((string)ini_get('disable_classes'))));
$pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jfWQAAAAASUVORK5CYII=');
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code'=>0,
    'data'=>[
        'php_version'=>PHP_VERSION,
        'curl_available'=>function_exists('curl_init'),
        'image_detection_available'=>function_exists('getimagesizefromstring'),
        'image_detection_test'=>platform_image_mime($pixel) === 'image/png',
        'fileinfo_available'=>class_exists('finfo') && !in_array('finfo', $disabledClasses, true),
        'fileinfo_required'=>false,
        'display_mode'=>'direct-url',
    ],
], JSON_UNESCAPED_UNICODE);
