<?php
// RC 调用端与星河接收端使用同一白名单；仅开放审核实际需要的接口。
function review_bridge_policy(): array
{
return [
    'routes' => [
        '/api/pop/fetch_vuln.php' => ['GET'],
        '/api/pop/get_today_counters.php' => ['GET'],
        '/api/pop/get_today_vendor_counters.php' => ['GET'],
        '/api/pop/get_highrate_venues.php' => ['GET'],
        '/api/pop/handle_device_ban.php' => ['POST'],
        '/api/pop/clear_device_violation.php' => ['POST'],
        '/api/pop/get_device_violation_review_list.php' => ['GET'],
        '/api/pop/append_device_ban.php' => ['POST'],
        '/api/pop/clear_device_violation_review_ignore.php' => ['POST'],
        '/api/pop/capture.php' => ['GET'],
        '/api/venue/getPendingImages.php' => ['GET'],
        '/api/venue/get_audit_list.php' => ['GET'],
        '/api/venue/getPendingVehicleNameAudits.php' => ['GET'],
        '/api/venue/review_venue_name.php' => ['POST'],
        '/api/venue/review_venue_description.php' => ['POST'],
        '/api/venue/reviewVehicleNameAudit.php' => ['POST'],
        '/api/venue/reviewVoiceRoomBg.php' => ['POST'],
        '/api/venue/deletePendingImage.php' => ['POST'],
        '/api/venue/rejectImage.php' => ['POST'],
        '/api/operat/get_current_user.php' => ['GET'],
        '/api/operat/ReporthandV2.php' => ['GET', 'POST'],
        '/api/operat/ReporthandRecord.php' => ['GET'],
        '/api/operat/image_proxy.php' => ['GET'],
        '/api/operat/venue_mute_records_api.php' => ['POST'],
        '/api/operat/EnergyGift.php' => ['GET', 'POST'],
        '/api/operat/ban_record.php' => ['GET'],
        '/api/gl/get_sb.php' => ['GET'],
        '/api/devMgr/devMgtV2.php' => ['POST'],
        '/api/checkin/next_prompt_redis.php' => ['GET'],
        '/api/checkin/click_redis.php' => ['POST'],
        '/api/operat/checkin_report.php' => ['GET'],
    ],
    'media_patterns' => [
        '#^/api/venue/pending_images/[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)$#i',
        '#^/api/venue/pending_voice_room_bg/[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)$#i',
        '#^/api/pop/review_backup/\d{4}-\d{2}-\d{2}/[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)$#i',
    ],
];
}

/**
 * Both callers enforce the same exact path/method/action boundary.
 * Throws RuntimeException before any upstream request or local endpoint dispatch.
 */
function review_bridge_validate_request(string $method, string $target, string $body, array $post): array
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'], true) || strlen($target) > 8192
        || !preg_match('#\A/api/[A-Za-z0-9_./-]+(?:\?[^\x00-\x20\x7f\#]*)?\z#D', $target)) {
        throw new RuntimeException('无效审核接口路径或方法');
    }
    $parts = explode('?', $target, 2);
    $path = $parts[0];
    if (strpos($path, '..') !== false || strpos($path, '//') !== false) throw new RuntimeException('无效审核接口路径');
    $policy = review_bridge_policy();
    $media = false;
    foreach ($policy['media_patterns'] as $pattern) {
        if (preg_match($pattern, $path)) $media = true;
    }
    if ($media) {
        if ($method !== 'GET') throw new RuntimeException('图片接口只允许 GET');
    } elseif (!isset($policy['routes'][$path]) || !in_array($method, $policy['routes'][$path], true)) {
        throw new RuntimeException('该接口不在聚合审核白名单中');
    }
    $query = [];
    parse_str($parts[1] ?? '', $query);
    if ($method === 'GET' && $body !== '') throw new RuntimeException('GET 请求不能携带正文');
    $json = null;
    if ($method === 'POST' && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) $json = $decoded;
        elseif (!$post) parse_str($body, $post);
    }
    if ($path === '/api/operat/EnergyGift.php' && ($method !== 'POST' || ($post['action'] ?? '') !== 'get_venues' || $query)) {
        throw new RuntimeException('仅允许读取审核场地列表');
    }
    if ($path === '/api/devMgr/devMgtV2.php') {
        $allowedKeys = ['ban', 'id', 'serial_number', 'ban_duration', 'ban_reason', 'banType', 'venue_id', 'image_url', 'image_url_2', 'image_url_3', 'image_urls', 'image_device_serial'];
        if (!is_array($json) || ($json['ban'] ?? false) !== true || (int)($json['id'] ?? 0) !== 1
            || !in_array($json['banType'] ?? '', ['device', 'venue'], true)
            || array_diff(array_keys($json), $allowedKeys) || $query) throw new RuntimeException('仅允许审核封禁动作');
    }
    if ($path === '/api/operat/venue_mute_records_api.php') {
        if (($post['action'] ?? ($json['action'] ?? '')) !== 'global_mute' || $query) throw new RuntimeException('仅允许举报全场禁言动作');
    }
    if ($path === '/api/pop/capture.php') {
        if (!isset($query['sn']) || !is_string($query['sn']) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $query['sn'])
            || array_diff(array_keys($query), ['sn'])) throw new RuntimeException('抓图需要有效 sn 参数');
    }
    if ($path === '/api/operat/image_proxy.php') {
        $url = $query['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        $vendorQuery = [];
        if (is_array($parts)) parse_str($parts['query'] ?? '', $vendorQuery);
        $validVendorQuery = !array_diff(array_keys($vendorQuery), ['authid', 'ccc', 'ttt', '_ts']);
        foreach ($vendorQuery as $vendorValue) {
            // ccc 是厂商 base64 令牌；parse_str 会把 URL 中未转义的 + 解析为空格。
            if (!is_string($vendorValue) || strlen($vendorValue) > 512 || !preg_match('/\A[A-Za-z0-9_.~+\/= -]*\z/D', $vendorValue)) $validVendorQuery = false;
        }
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'file.minirtc.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || !$validVendorQuery || !preg_match('#\A/Dev/[A-Za-z0-9_-]+/OnCapture\z#D', $parts['path'] ?? '')
            || array_diff(array_keys($query), ['url'])) throw new RuntimeException('只允许厂商抓图图片地址');
    }
    return ['path' => $path, 'query' => $query, 'media' => $media];
}

return review_bridge_policy();
