<?php
define("LOG_PATH", __DIR__ . '/dialog_callback.log');
require_once '../RedisHelper.php';

header('Content-Type: application/json');

if (!file_exists(LOG_PATH)) {
    echo json_encode([]);
    exit;
}

$lines = array_reverse(file(LOG_PATH));
$users = [];
$kfUsersInRedis = [];

// 初始化 Redis
try {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(3);

    // 拿所有人工客服接入状态的 key
    $redisKeys = $redis->getAllKeys('kf_active_*');
    foreach ($redisKeys as $key) {
        $userid = str_replace('kf_active_', '', $key);
        $kfUsersInRedis[$userid] = true;
    }
} catch (Exception $e) {
    // Redis 挂了也能继续跑
    $kfUsersInRedis = [];
}

// 遍历日志
foreach ($lines as $line) {
    $data = json_decode(trim($line), true);
    if (!isset($data['userid'], $data['msg'])) continue;

    $uid = $data['userid'];

    // 判断是否符合：kfstate==3 或 Redis中存在
    $isKfLogState = isset($data['kfstate']) && (int)$data['kfstate'] === 3;
    $isKfRedis    = isset($kfUsersInRedis[$uid]);

    if (($isKfLogState || $isKfRedis) && !isset($users[$uid])) {
        $users[$uid] = [
            'userid' => $uid,
            'last_msg' => $data['msg'],
            'time' => $data['time'] ?? ''
        ];
    }
}

echo json_encode(array_values($users), JSON_UNESCAPED_UNICODE);
