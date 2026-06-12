<?php
define("LOG_PATH", __DIR__ . '/dialog_callback.log');
header('Content-Type: application/json');

// 获取参数
$userid = $_GET['userid'] ?? '';
if (empty($userid)) {
    echo json_encode([]);
    exit;
}

if (!file_exists(LOG_PATH)) {
    echo json_encode([]);
    exit;
}

// 判断一条消息是否有效
function isValidMessage($data) {
    $from = $data['from'] ?? null;
    $msg = trim($data['msg'] ?? '');
    $event = isset($data['event']) ? (string)$data['event'] : '';

    // 用户发空 + 无事件
    if ($msg === '' && !in_array($event, ['userEnter', 'userQuit'])) {
        return false;
    }

    // 客服发空
    if ($from === 2 && $msg === '') {
        return false;
    }

    return true;
}

// 读取日志并筛选该用户的有效消息
$lines = file(LOG_PATH);
$messages = [];

foreach ($lines as $line) {
    $data = json_decode(trim($line), true);
    if (!isset($data['userid'], $data['from'])) continue;
    if ($data['userid'] != $userid) continue;

    if (!isValidMessage($data)) continue;

    $messages[] = [
        'msg' => $data['msg'] ?? '',
        'from' => $data['from'],
        'time' => $data['time'] ?? ''
    ];
}

// 时间排序（old → new）
usort($messages, function ($a, $b) {
    return strtotime($a['time']) <=> strtotime($b['time']);
});

// 输出
echo json_encode($messages, JSON_UNESCAPED_UNICODE);
