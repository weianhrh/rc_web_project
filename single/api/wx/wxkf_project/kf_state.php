<?php
require_once '../RedisHelper.php';

$action = $_GET['action'] ?? '';
$userid = $_GET['userid'] ?? '';
$value = $_GET['value'] ?? '';
$ttl = 600; // 默认人工客服状态保持 10 分钟

header('Content-Type: application/json');

if (!$userid) {
    echo json_encode(['status' => 0, 'msg' => '缺少 userid']);
    exit;
}

try {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(3); // 选择数据库可根据你自己需要

    $key = "kf_active_" . $userid;

    switch ($action) {
        case 'set':
            $redis->setWithExpiration($key, 1, $ttl);
            echo json_encode(['status' => 1, 'msg' => '已设置为接入状态', 'ttl' => $ttl]);
            break;

        case 'del':
            $redis->delete($key);
            echo json_encode(['status' => 1, 'msg' => '状态已清除']);
            break;

        case 'check':
            $exists = $redis->exists($key);
            echo json_encode(['status' => 1, 'active' => $exists]);
            break;

        default:
            echo json_encode(['status' => 0, 'msg' => '未知操作']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 0, 'msg' => $e->getMessage()]);
}
