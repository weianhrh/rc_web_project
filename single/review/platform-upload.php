<?php
declare(strict_types=1);
require_once __DIR__ . '/_platform.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') platform_fail('不支持的请求方法', 405);
platform_authorize(true);
$source = platform_source();
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) platform_fail('请选择有效图片');
if ((int)$file['size'] > 20 * 1024 * 1024) platform_fail('图片不能超过 20MB', 413);
$imageBytes = @file_get_contents($file['tmp_name']);
$mime = $imageBytes === false ? '' : platform_image_mime($imageBytes);
$extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
if (!isset($extensions[$mime])) platform_fail('只支持 JPG、PNG、GIF、WebP 图片');
if (!function_exists('curl_init') || !class_exists('CURLFile')) platform_fail('服务器 PHP 未启用 cURL 文件上传支持', 503);
$fields = ['file' => new CURLFile($file['tmp_name'], $mime, 'review.' . $extensions[$mime])];
$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    if ($action !== 'update_venue_pictrue' || !ctype_digit((string)($_POST['venue_id'] ?? '')) || (int)$_POST['venue_id'] <= 0) platform_fail('无效的图片审核操作');
    $fields['action'] = $action;
    $fields['venue_id'] = (string)(int)$_POST['venue_id'];
}
$url = $source === 'rc' ? 'https://rcwulian.cn/app/img/userimg/upload_profile.php' : 'https://sxzjjx.cn/app/img/userimg/upload_profile.php';
$curl = curl_init($url);
curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$fields, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>12, CURLOPT_TIMEOUT=>120, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
$result = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if ($result === false || $status !== 200) platform_fail('图片上传服务暂不可用，请稍后重试', 502);
$json = json_decode($result, true);
if (!is_array($json)) platform_fail('图片上传服务返回格式错误', 502);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
