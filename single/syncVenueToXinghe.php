<?php
require_once __DIR__ . '/xinghe_sync_common.php';
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') xhReply(405, '仅支持POST请求');
    $token = $_COOKIE['session_token'] ?? '';
    if ($token === '') xhReply(1001, '请先登录');
    // Reject cross-site browser form submissions; JSON requests require same-origin access.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) xhReply(400, '请使用JSON请求');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') xhReply(403, '禁止跨站请求');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = filter_var($input['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) xhReply(400, '场地ID无效');
    $config = xhConfig();
    if (strtolower(parse_url($config['url'], PHP_URL_SCHEME) ?? '') !== 'https' || !parse_url($config['url'], PHP_URL_HOST)) {
        throw new RuntimeException('请配置星河接收接口的HTTPS地址 XINGHE_SYNC_URL');
    }
    require_once __DIR__ . '/../Database.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = (new Database())->getConnection();
    $conn->set_charset('utf8mb4');
    // Existing RC login uses session_token only; older live schemas have no expiry column.
    // Keep expiry enforcement when that column exists, without requiring a schema migration.
    $adminColumns = xhColumns($conn, 'admin_users');
    $expiryClause = isset($adminColumns['session_expires']) ? ' AND session_expires>NOW()' : '';
    $admins = xhQuery($conn, 'SELECT id,role_id FROM admin_users WHERE session_token=?' . $expiryClause . ' LIMIT 1', [$token]);
    if (!$admins) xhReply(1001, '登录已过期');
    if (!in_array((int)$admins[0]['role_id'], [1, 2], true)) xhReply(403, '仅平台管理员可同步');
    xhTransactional($conn, ['venues', 'vehicles', 'vehicle_control_settings']);
    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $venues = xhQuery($conn, 'SELECT * FROM venues WHERE id=?', [$id]);
    if (count($venues) !== 1) throw new RuntimeException('场地不存在');
    $venue = $venues[0];
    // Current projects bind by numeric venue ID. Do not match names (names need not be unique).
    $vehicles = xhQuery($conn, 'SELECT * FROM vehicles WHERE bind_site=? ORDER BY serial_number', [(string)$id]);
    $settings = [];
    $serials = [];
    $cameras = [];
    $cameraRefs = [];
    $billingRules = [];
    $cameraTable = xhQuery($conn, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='device_information'");
    if ($cameraTable) xhTransactional($conn, ['device_information']);
    foreach ($vehicles as &$vehicle) {
        $sn = (string)$vehicle['serial_number'];
        if (trim($sn) === '' || isset($serials['sn:' . $sn])) throw new RuntimeException('设备序列号为空或重复');
        $serials['sn:' . $sn] = true;
        if (array_key_exists('billing_rules', $vehicle) && $vehicle['billing_rules'] !== null) {
            $ruleId = (string)$vehicle['billing_rules'];
            if (!ctype_digit($ruleId)) throw new RuntimeException("设备 $sn 的计费规则ID无效");
            if (!isset($billingRules['rule:' . $ruleId])) {
                xhTransactional($conn, ['billing_rules']);
                $rules = xhQuery($conn, 'SELECT * FROM billing_rules WHERE id=?', [$ruleId]);
                if (count($rules) !== 1) throw new RuntimeException("设备 $sn 引用的RC计费规则不存在：" . $ruleId);
                $billingRules['rule:' . $ruleId] = $rules[0];
            }
        }
        $rows = xhQuery($conn, 'SELECT * FROM vehicle_control_settings WHERE serial_number=?', [$sn]);
        if (count($rows) !== 1) throw new RuntimeException("设备 $sn 的控制配置缺失或重复，请先修复");
        $settings[] = xhSettings($rows[0]);
        foreach (['image_device_serial', 'bk_image_device_serial'] as $field) {
            $value = (string)($vehicle[$field] ?? '');
            if (!$cameraTable || $value === '') continue;
            $camera = xhQuery($conn, 'SELECT * FROM device_information WHERE CAST(id AS CHAR)=?', [$value]);
            if (!$camera) $camera = xhQuery($conn, 'SELECT * FROM device_information WHERE room_id=?', [$value]);
            if (count($camera) > 1) throw new RuntimeException("设备 $sn 的图传标识不唯一");
            if ($camera) {
                $cameraId = (string)$camera[0]['id'];
                $cameras['camera:' . $cameraId] = $camera[0];
                $cameraRefs[] = ['serial_number' => $sn, 'field' => $field, 'camera_id' => $cameraId];
            }
        }
        $vehicle = xhPick($vehicle, xhVehicleFields());
    }
    unset($vehicle);
    $payload = ['version' => 1, 'source' => 'RC', 'source_venue_id' => (int)$id,
        'venue' => xhPick($venue, xhVenueFields()), 'vehicles' => $vehicles, 'settings' => $settings,
        'cameras' => array_values($cameras), 'camera_refs' => $cameraRefs,
        'billing_rules' => array_values($billingRules)];
    $conn->commit();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($config['url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Xinghe-Sync-Token: ' . $config['token']],
        CURLOPT_POSTFIELDS => $body]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('星河连接失败；如超时可重试（重复同步不新增场地）：' . $error);
    $result = json_decode($response, true);
    if ($http !== 200 || !is_array($result)) throw new RuntimeException('星河响应异常，HTTP ' . $http);
    if (($result['code'] ?? -1) !== 0) throw new RuntimeException($result['msg'] ?? '星河拒绝同步');
    xhReply(0, '场地、设备和设备配置已同步到星河', $result['data'] ?? []);
} catch (Throwable $e) {
    if ($conn) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    error_log('Xinghe send: ' . $e->getMessage());
    xhReply(500, '同步失败：' . $e->getMessage());
}
