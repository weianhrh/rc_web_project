<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../RedisHelper.php';


$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->query("SELECT role_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}

$dir = __DIR__ . '/pending_images/';
$files = scandir($dir);
$results = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;

    // 新正则，提取 venue_id 和时间戳
    if (preg_match('/venue_(\d+)_(\d{14})\.(jpg|jpeg|png|gif|webp)$/i', $file, $matches)) {
        $venue_id = $matches[1];
        $timestamp = $matches[2];

        // 格式化时间
        $formatted_time = DateTime::createFromFormat('YmdHis', $timestamp)->format('Y-m-d H:i:s');

        $venue = $database->query("SELECT venue_name FROM venues WHERE id = ?", [$venue_id]);

        $results[] = [
            'id' => $venue_id,
            'venue_name' => $venue[0]['venue_name'] ?? '未知场地',
            'image_url' => 'pending_images/' . $file,
            'image_status' => 'pending',
            'upload_time' => $formatted_time, // 加上时间字段
            // 兼容旧场地头像审核；语音房背景图通过独立类型进入同一审核列表。
            'review_id' => '',
            'review_type' => 'venue_avatar'
        ];
    }
}

// 语音房背景图审核：只读取 Redis DB6 中待审核池，不改动原场地头像审核表/目录。
$voiceRedis = new RedisHelper();
$voiceRedis->connect();
$voiceRedis->selectDb(6);
$voiceNativeRedis = $voiceRedis->getNative();
$voiceBgKeys = $voiceNativeRedis->sMembers('venue_voice_bg_review_pool');
foreach ($voiceBgKeys as $voiceBgKey) {
    $raw = $voiceRedis->get($voiceBgKey);
    $review = $raw ? json_decode($raw, true) : null;

    // 过期、损坏或已经处理的记录从待审核池清掉，状态记录本身仍按原 TTL 保留。
    if (
        !is_array($review) ||
        ($review['review_type'] ?? '') !== 'voice_room_background' ||
        ($review['status'] ?? '') !== 'pending'
    ) {
        $voiceNativeRedis->sRem('venue_voice_bg_review_pool', $voiceBgKey);
        continue;
    }

    $voiceVenueId = (int)($review['venue_id'] ?? 0);
    $reviewId = trim((string)($review['review_id'] ?? ''));
    $pendingPath = ltrim((string)($review['pending_path'] ?? $review['image_url'] ?? ''), '/');
    if (
        $voiceVenueId <= 0 ||
        $reviewId === '' ||
        !preg_match(
            '#^pending_voice_room_bg/voice_bg_\d+_\d{14}_[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#i',
            $pendingPath
        ) ||
        pathinfo(basename($pendingPath), PATHINFO_FILENAME) !== $reviewId
    ) {
        $voiceNativeRedis->sRem('venue_voice_bg_review_pool', $voiceBgKey);
        continue;
    }

    $venue = $database->query("SELECT venue_name FROM venues WHERE id = ?", [$voiceVenueId]);
    $uploadedAt = (string)($review['uploaded_at'] ?? '');
    if ($uploadedAt === '' && !empty($review['timestamp'])) {
        $uploadedAt = date('Y-m-d H:i:s', (int)$review['timestamp']);
    }

    $results[] = [
        'id' => $voiceVenueId,
        'venue_name' => $venue[0]['venue_name'] ?? '未知场地',
        'image_url' => $pendingPath,
        'image_status' => 'pending',
        'upload_time' => $uploadedAt,
        'review_id' => $reviewId,
        'review_type' => 'voice_room_background'
    ];
}
$voiceRedis->close();

// 原名称、描述、设备名称审核池继续使用 Redis DB3。
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);
$nativeRedis = $redis->getNative();

// 场地名称
$nameKeys = $nativeRedis->sMembers('venue_name_audit_pool');
$nameCount = 0;
foreach ($nameKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $nameCount++;
    }
}

// 场地描述
$descKeys = $nativeRedis->sMembers('venue_description_audit_pool');
$descCount = 0;
foreach ($descKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $descCount++;
    }
}

// 设备名称/分享名称
$deviceKeys = $nativeRedis->sMembers('vehicle_name_audit_pool');
$deviceCount = 0;
foreach ($deviceKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $deviceCount++;
    }
}

$total = $nameCount + $descCount + $deviceCount;

echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'count' => count($results) + $total, // 新增统计数量字段
    'data' => $results
], JSON_UNESCAPED_UNICODE);

?>
