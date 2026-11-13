<?php
declare(strict_types=1);
require_once __DIR__ . '/_platform.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') platform_fail('不支持的请求方法', 405);
$user = platform_authorize();
$source = platform_source();
$raw = (string)($_GET['url'] ?? '');
if ($raw === '' || strlen($raw) > 4096 || preg_match('/[\x00-\x20\\\\]/', $raw)) platform_fail('无效图片地址');
$backend = $source === 'rc' ? 'open.rcwulian.cn' : 'open.sxzjjx.cn';
$app = $source === 'rc' ? 'rcwulian.cn' : 'sxzjjx.cn';
if (strpos($raw, '//') === 0) $raw = 'https:' . $raw;
if ($raw[0] === '/') $raw = 'https://' . $backend . $raw;
$url = parse_url($raw);
if (!$url || !in_array(strtolower((string)($url['scheme'] ?? '')), ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])) platform_fail('无效图片地址');
$host = strtolower((string)($url['host'] ?? ''));
$path = (string)($url['path'] ?? '');
$decoded = rawurldecode($path);
if (preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\\\\]~', $decoded) || strpos($decoded, '%') !== false) platform_fail('无效图片路径');
$backendImage = $host === $backend && preg_match('~^/(?:api/venue/(?:pending_images|pending_voice_room_bg)/|api/pop/(?:review_backup|pending_risk|risk_images|pending_images_folder|risk_images_folder)/|pending_images_folder/|risk_images_folder/|uploads/|upload/|res/)[A-Za-z0-9_./()\x{4e00}-\x{9fff}-]+\.(?:jpe?g|png|gif|webp)$~ui', $decoded);
$appImage = $host === $app && preg_match('~^/(?:app/|img/|uploads/|upload/|pending_images_folder/|risk_images_folder/)[^?]+\.(?:jpe?g|png|gif|webp)$~i', $decoded);
$ossImage = preg_match('~^[a-z0-9-]+\.oss-cn-[a-z0-9-]+\.aliyuncs\.com$~', $host) && strpos($host, '-internal.') === false;
$captureImage = $host === 'file.minirtc.com' && preg_match('~^/Dev/[A-Za-z0-9_-]+/OnCapture$~', $decoded);
if (!$backendImage && !$appImage && !$ossImage && !$captureImage) platform_fail('该图片地址不在审核图片范围内', 403);
$bytes = '';
if ($backendImage && $source === 'xh') {
    require_once __DIR__ . '/_bridge_policy.php';
    try {
        $route = review_bridge_validate_request('GET', $path, '', []);
        if ($route['media']) {
            $response = platform_xh_response($user, 'GET', $path, '', 'application/octet-stream');
            if ($response['status'] !== 200) platform_send_response($response);
            $bytes = $response['body'];
        }
    } catch (RuntimeException $ignored) {
        // 其他固定平台目录沿用原来的公开图片读取方式。
    }
}
// 安全的本地文件走快速读取；找不到时仍请求同一固定站点 URL，兼容 Nginx alias/rewrite。
if ($bytes !== '' && strlen($bytes) > 20 * 1024 * 1024) platform_fail('图片过大', 413);
if ($bytes === '' && $backendImage && $source === 'rc') {
    $root = realpath(dirname(__DIR__));
    $file = realpath($root . $decoded);
    if ($file && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && is_file($file) && is_readable($file)) {
        if (filesize($file) > 20 * 1024 * 1024) platform_fail('图片过大', 413);
        $localBytes = @file_get_contents($file);
        if ($localBytes !== false) $bytes = $localBytes;
    }
}
if ($bytes === '') {
    if (!function_exists('curl_init')) platform_fail('服务器 PHP 未启用 cURL，无法读取远程图片', 503);
    $remote = 'https://' . $host . $path . (isset($url['query']) ? '?' . $url['query'] : '');
    $curl = curl_init($remote);
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
    if (!$ok || $status !== 200) platform_fail('图片读取失败', 502);
}
$mime = platform_image_mime($bytes);
if ($mime === '') platform_fail('无效图片内容', 415);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $bytes;
