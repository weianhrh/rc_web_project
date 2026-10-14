<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

require_once __DIR__ . '/../Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// ===== ZEGO 配置 =====
$appId = 141962251;
$serverSecret = '5bfaa3399946c98cc6792dd19f9a08ec';
$rtcApiUrl = 'https://rtc-api.zego.im/';

function json_out($code, $msg, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function generate_signature_nonce_hex16(): string {
    return bin2hex(random_bytes(8));
}

function generate_signature_md5($appId, string $nonce, string $serverSecret, int $timestamp): string {
    return md5((string)$appId . $nonce . $serverSecret . (string)$timestamp);
}

function parse_to_user_ids($raw): array {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $parts = preg_split('/[,\s，]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }

    $parts = array_map('trim', $parts);
    $parts = array_filter($parts, function ($v) {
        return $v !== '';
    });
    $parts = array_values(array_unique($parts));

    return $parts;
}

function build_query_with_to_user_ids(array $params, array $toUserIds): string {
    unset($params['ToUserId']);

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    foreach ($toUserIds as $uid) {
        $query .= '&ToUserId%5B%5D=' . rawurlencode($uid);
    }

    return $query;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, '仅支持 POST 请求');
}

$database = new Database();
$sessionToken = $_COOKIE['session_token'] ?? '';
$adminUser = $sessionToken !== '' ? $database->getUserBySessionToken($sessionToken) : null;
if (!$adminUser || empty($adminUser['role_id'])) {
    json_out(1001, '用户未登录或会话已过期');
}

$roomId = trim((string)($_POST['room_id'] ?? ''));
$fromUserId = trim((string)($_POST['from_user_id'] ?? 'server_bot_1'));
$message = (string)($_POST['message'] ?? '');
$toUserIds = parse_to_user_ids($_POST['to_user_ids'] ?? '');
$commandType = trim((string)($_POST['command_type'] ?? ''));

// Automatic motion commands are a privileged, allowlisted subset of this API.
// Keep the existing camera-management commands unchanged.
$isExcavatorCommand = strpos($commandType, 'excavator_') === 0;
$isVehicleCommand = strpos($commandType, 'vehicle_') === 0;
if ($isExcavatorCommand || $isVehicleCommand) {
    $roleId = (int)($adminUser['role_id'] ?? 0);
    if (!in_array($roleId, [1, 2, 3], true)) {
        json_out(403, '当前账号无设备动作控制权限');
    }

    if ($isExcavatorCommand) {
        if (!preg_match('/^\+(dx|xs|ws|lz|ql|qr)#([012])-$/', trim($message), $motionMatch)) {
            json_out(400, '挖机动作指令不在允许范围内');
        }
        $expectedType = $motionMatch[2] === '0' ? 'excavator_stop' : 'excavator_motion';
    } else {
        if (!preg_match('/^\+(fd|bk|lt|rt|sp|md)#(1000|1[0-9]{3}|2000)-$/', trim($message), $motionMatch)) {
            json_out(400, '车辆动作指令不在允许范围内');
        }
        $expectedType = in_array($motionMatch[1], ['sp', 'md'], true)
            ? 'vehicle_stop'
            : 'vehicle_motion';
    }
    if ($commandType !== $expectedType) {
        json_out(400, '动作类型与指令不匹配');
    }

    if ($roleId === 3) {
        $venueId = (int)($adminUser['venue_id'] ?? 0);
        if ($venueId <= 0) {
            json_out(403, '加盟商账号未绑定场地，不能控制设备');
        }
        $knownRoom = $database->query(
            "SELECT di.id,
                    SUM(CASE WHEN v.bind_site = ? THEN 1 ELSE 0 END) AS own_count,
                    SUM(CASE WHEN v.bind_site <> ? THEN 1 ELSE 0 END) AS foreign_count
             FROM device_information di
             INNER JOIN vehicles v
               ON v.image_device_serial = CAST(di.id AS CHAR)
               OR v.bk_image_device_serial = CAST(di.id AS CHAR)
             WHERE di.room_id = ?
             GROUP BY di.id
             LIMIT 1",
            [$venueId, $venueId, $roomId]
        );
        if (!$knownRoom || (int)$knownRoom[0]['own_count'] < 1 || (int)$knownRoom[0]['foreign_count'] > 0) {
            json_out(403, '该设备不属于当前加盟商场地');
        }
    } else {
        $knownRoom = $database->query(
            'SELECT id FROM device_information WHERE room_id = ? LIMIT 1',
            [$roomId]
        );
    }
    if (!$knownRoom) {
        json_out(404, '未找到对应设备房间，请先在设备信息中登记');
    }

    // A device room is isolated; never allow browser-supplied recipient routing here.
    $toUserIds = [];
    $fromUserId = 'server_bot_1';
}

if ($roomId === '') {
    json_out(400, 'room_id 不能为空');
}
if ($fromUserId === '') {
    json_out(400, 'from_user_id 不能为空');
}
if (trim($message) === '') {
    json_out(400, 'message 不能为空');
}

$msgBytes = strlen($message);
if ($msgBytes > 1024) {
    json_out(400, "消息内容过长：{$msgBytes} 字节，最大允许 1024 字节");
}

if (count($toUserIds) > 10) {
    json_out(400, 'ToUserId 最多支持 10 个');
}

$timestamp = time();
$signatureNonce = generate_signature_nonce_hex16();
$signature = generate_signature_md5($appId, $signatureNonce, $serverSecret, $timestamp);

$params = [
    'Action'           => 'SendCustomCommand',
    'AppId'            => (string)$appId,
    'SignatureNonce'   => $signatureNonce,
    'Timestamp'        => (string)$timestamp,
    'Signature'        => $signature,
    'SignatureVersion' => '2.0',
    'RoomId'           => $roomId,
    'FromUserId'       => $fromUserId,
    'MessageContent'   => $message,
];

if (!empty($toUserIds)) {
    $query = build_query_with_to_user_ids($params, $toUserIds);
} else {
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

$url = rtrim($rtcApiUrl, '/') . '/?' . $query;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
    ],
]);

$resp = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    json_out(500, 'cURL错误: ' . $curlErr, [
        'http_code' => $httpCode,
        'raw' => $resp,
    ]);
}

if ((int)$httpCode !== 200) {
    json_out(500, 'HTTP错误代码: ' . $httpCode, [
        'http_code' => $httpCode,
        'raw' => $resp,
    ]);
}

$data = json_decode((string)$resp, true);
if (!is_array($data)) {
    json_out(500, '响应不是合法 JSON', [
        'http_code' => $httpCode,
        'raw' => $resp,
    ]);
}

$zegoCode = (int)($data['Code'] ?? -1);
$zegoMsg  = (string)($data['Message'] ?? '');
$zegoData = $data['Data'] ?? [];
$failUsers = $zegoData['FailUsers'] ?? [];

if ($zegoCode !== 0) {
    json_out(500, "ZEGO返回失败：Code={$zegoCode}, Message={$zegoMsg}", [
        'zego_code' => $zegoCode,
        'zego_message' => $zegoMsg,
        'zego_data' => $zegoData,
        'raw' => $resp,
    ]);
}

if ($isExcavatorCommand || $isVehicleCommand) {
    $operatorId = (int)($adminUser['id'] ?? $adminUser['uid'] ?? 0);
    $logLine = sprintf(
        "[%s] operator_id=%d room_id=%s command=%s ip=%s\n",
        date('Y-m-d H:i:s'),
        $operatorId,
        preg_replace('/[^A-Za-z0-9_.-]/', '', $roomId),
        trim($message),
        $_SERVER['REMOTE_ADDR'] ?? ''
    );
    error_log($logLine, 3, __DIR__ . '/device_auto_command.log');
}

// WIFI 指令发送成功后保存记录。只认后端实际发送的消息内容，避免前端伪造记录。
$wifiRecordId = null;
$otaRecordId = null;
$messageData = json_decode($message, true);
if (is_array($messageData) && ($messageData['type'] ?? '') === 'cfg_wifi') {
    $wifiData = is_array($messageData['data'] ?? null) ? $messageData['data'] : [];
    $wifiEssid = trim((string)($wifiData['essid'] ?? ''));
    $wifiPasswd = (string)($wifiData['passwd'] ?? '');
    $actionType = $commandType === 'wifi_default_password' ? 'default_password' : 'set_wifi';
    $operatorId = (int)($adminUser['id'] ?? $adminUser['uid'] ?? 0);
    $operatorName = (string)($adminUser['username'] ?? '');

    $inserted = $database->query(
        "INSERT INTO camera_wifi_send_records
         (room_id, wifi_essid, wifi_password, action_type, operator_id, operator_name, zego_code, zego_message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$roomId, $wifiEssid, $wifiPasswd, $actionType, $operatorId, $operatorName, $zegoCode, $zegoMsg],
        true
    );

    if ($inserted === false || $inserted < 1) {
        json_out(500, 'WIFI 指令已发送成功，但发送记录保存失败，请先执行建表 SQL', [
            'zego_code' => $zegoCode,
            'zego_message' => $zegoMsg,
            'command_sent' => true,
            'fail_users' => $failUsers,
        ]);
    }

    $idRows = $database->query('SELECT LAST_INSERT_ID() AS id');
    $wifiRecordId = isset($idRows[0]['id']) ? (int)$idRows[0]['id'] : null;
}

if (is_array($messageData) && ($messageData['type'] ?? '') === 'ota_update') {
    $otaData = is_array($messageData['data'] ?? null) ? $messageData['data'] : [];
    $otaVersion = trim((string)($otaData['ver'] ?? ''));
    $firmwareUrl = trim((string)($otaData['url'] ?? ''));
    $otaForce = (int)($otaData['force'] ?? 0);
    $operatorId = (int)($adminUser['id'] ?? $adminUser['uid'] ?? 0);
    $operatorName = (string)($adminUser['username'] ?? '');

    $inserted = $database->query(
        "INSERT INTO camera_ota_send_records
         (room_id, ota_version, firmware_url, ota_force, operator_id, operator_name, zego_code, zego_message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$roomId, $otaVersion, $firmwareUrl, $otaForce, $operatorId, $operatorName, $zegoCode, $zegoMsg],
        true
    );
    if ($inserted === false || $inserted < 1) {
        json_out(500, '摄像头 OTA 指令已发送成功，但发送记录保存失败，请先执行建表 SQL', [
            'zego_code' => $zegoCode,
            'zego_message' => $zegoMsg,
            'command_sent' => true,
            'fail_users' => $failUsers,
        ]);
    }
    $idRows = $database->query('SELECT LAST_INSERT_ID() AS id');
    $otaRecordId = isset($idRows[0]['id']) ? (int)$idRows[0]['id'] : null;
}

json_out(200, '发送成功', [
    'zego_code' => $zegoCode,
    'zego_message' => $zegoMsg,
    'fail_users' => $failUsers,
    'zego_data' => $zegoData,
    'raw' => $resp,
    'wifi_record_id' => $wifiRecordId,
    'ota_record_id' => $otaRecordId,
]);
