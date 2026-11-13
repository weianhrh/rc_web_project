<?php
declare(strict_types=1);
require_once __DIR__ . '/_runtime.php';
require_once __DIR__ . '/_image.php';
require_once __DIR__ . '/_bootstrap.php';

function platform_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['code' => $status, 'success' => false, 'msg' => $message, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function platform_source(): string
{
    $source = (string)($_GET['platform'] ?? '');
    if (!in_array($source, ['rc', 'xh'], true)) platform_fail('无效平台');
    return $source;
}

function platform_csrf(): string
{
    $config = review_config();
    $token = (string)($_COOKIE[$config['cookie_name']] ?? '');
    return hash_hmac('sha256', 'combined-review-csrf:' . $token, (string)$config['shared_secret']);
}

function platform_authorize(bool $mutating = false): array
{
    $user = review_require_user(true);
    header('Cache-Control: private, no-store');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'https://open.rcwulian.cn') platform_fail('不允许的请求来源', 403);
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') platform_fail('不允许的请求来源', 403);
    if ($mutating && !hash_equals(platform_csrf(), (string)($_SERVER['HTTP_X_REVIEW_CSRF'] ?? ''))) {
        platform_fail('页面校验已失效，请刷新后重试', 403);
    }
    return $user;
}

function platform_xh_config(): array
{
    static $config;
    if ($config === null) $config = require __DIR__ . '/_xh_config.php';
    return $config;
}

function platform_xh_response(array $user, string $method, string $target, string $body, string $contentType): array
{
    if (!function_exists('curl_init')) platform_fail('服务器 PHP 未启用 cURL，无法连接星河审核接口', 503);
    $config = platform_xh_config();
    if (strlen((string)$config['shared_secret']) < 32) platform_fail('星河审核连接尚未配置，请核对两端配置', 503);
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(18));
    $reviewer = (string)$user['username'];
    $admin = (string)$user['rc_admin_username'];
    // 身份值只允许在单个 HTTP 头中出现，不接受换行。
    if (preg_match('/[\r\n]/', $reviewer . $admin . $target)) platform_fail('无效请求参数');
    $canonical = implode("\n", [$method, $target, $timestamp, $nonce, $reviewer, $admin, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $canonical, (string)$config['shared_secret']);
    $curl = curl_init((string)$config['bridge_url']);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'X-Review-Path: ' . $target,
            'X-Review-Timestamp: ' . $timestamp,
            'X-Review-Nonce: ' . $nonce,
            'X-Review-Reviewer: ' . $reviewer,
            'X-Review-Admin: ' . $admin,
            'X-Review-Signature: ' . $signature,
            'Expect:',
        ],
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $data = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $mime = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = curl_errno($curl);
    curl_close($curl);
    if ($data === false) {
        error_log('XH review bridge transport failure, curl=' . $error);
        platform_fail('星河审核连接失败（cURL ' . $error . '），请检查星河地址和证书', 502);
    }
    if ($status < 200 || $status >= 600 || ($status >= 300 && $status < 400)) platform_fail('星河审核返回了无效响应', 502);
    return ['status' => $status, 'content_type' => $mime, 'body' => $data];
}

function platform_send_response(array $response): void
{
    http_response_code($response['status']);
    $type = preg_replace('/[\r\n].*/s', '', $response['content_type']);
    header('Content-Type: ' . ($type ?: 'application/json; charset=utf-8'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $response['body'];
    exit;
}

function platform_rc_admin(array $user): void
{
    $db = review_db();
    $rows = $db->query('SELECT * FROM admin_users WHERE username = ? LIMIT 1', [(string)$user['rc_admin_username']]);
    $admin = $rows[0] ?? null;
    if (!$admin || !in_array((int)$admin['role_id'], [1, 2], true)) platform_fail('RC 映射审核账号不存在或没有权限', 403);
    $expires = isset($admin['session_expires']) ? strtotime((string)$admin['session_expires']) : false;
    if (empty($admin['session_token']) || ($expires !== false && $expires < time() + 60)) {
        review_issue_rc_admin_session($db, $user);
    }
    $rows = $db->query('SELECT session_token FROM admin_users WHERE id = ? LIMIT 1', [(int)$admin['id']]);
    $_COOKIE['session_token'] = (string)($rows[0]['session_token'] ?? '');
    $db->close();
    if ($_COOKIE['session_token'] === '') platform_fail('RC 审核账号会话创建失败', 503);
}

function platform_vendor_image_response(string $url): void
{
    // URL 已通过相同的固定厂商域名和路径白名单；不使用原任意 URL 图片代理。
    $bytes = '';
    if (!function_exists('curl_init')) platform_fail('服务器 PHP 未启用 cURL，无法读取厂商抓图', 503);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
            if (strlen($bytes) + strlen($chunk) > 20 * 1024 * 1024) return 0;
            $bytes .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $mime = platform_image_mime($bytes);
    if (!$ok || $status !== 200 || $mime === '') platform_fail('厂商图片读取失败或返回内容不是图片', 502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code'=>0, 'data'=>'data:' . $mime . ';base64,' . base64_encode($bytes)]);
    exit;
}
