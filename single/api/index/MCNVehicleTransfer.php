<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/McnVehicleTransferState.php';
require_once __DIR__ . '/../devMgr/McnVoltagePlatform.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = new Database();
$transaction = false;
$redis = null;
$code = 0;
$message = 'ok';
$output = [];
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) throw new RuntimeException('请求方式不支持', 405);
    $token = $_COOKIE['session_token'] ?? '';
    $user = $token !== '' ? $db->getUserBySessionToken($token) : null;
    if (!$user || empty($user['id']) || empty($user['role_id'])) throw new RuntimeException('请先登录', 1001);
    if (empty($user['is_mcn']) || empty($user['mcn_id'])) throw new RuntimeException('仅MCN账号可以调配旗下车辆', 403);
    if (!empty($user['session_expires']) && strtotime($user['session_expires']) <= time()) {
        throw new RuntimeException('登录已过期，请重新登录', 1001);
    }
    $csrf = hash_hmac('sha256', 'mcn_vehicle_transfer', $token);
    $input = [];
    if ($method === 'POST') {
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new RuntimeException('请求校验失败，请刷新页面', 403);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) throw new RuntimeException('请求参数错误', 400);
        foreach (['vehicle_id', 'source_venue_id', 'target_venue_id'] as $field) {
            if (!isset($input[$field]) || !ctype_digit((string)$input[$field]) || (int)$input[$field] <= 0) {
                throw new RuntimeException('请选择有效车辆和场地', 400);
            }
        }
        if (!$db->getConnection()->begin_transaction()) throw new RuntimeException('Transaction failed');
        $transaction = true;
    }
    $venues = transferQuery($db,
        'SELECT v.id, v.venue_name, v.is_banned FROM mcn_venues mv
         JOIN mcn_organizations mo ON mo.id = mv.mcn_id AND mo.status = 1
         JOIN venues v ON v.id = mv.venue_id
         WHERE mv.mcn_id = ? AND mv.status = 1 ORDER BY v.id' . ($transaction ? ' FOR UPDATE' : ''),
        [(int)$user['mcn_id']]);
    if (!$venues) throw new RuntimeException('当前机构没有有效管理场地', 403);
    if ($method === 'GET' && ($_GET['view'] ?? '') === 'platform_logs') {
        $logs = transferQuery($db,
            'SELECT id, vehicle_name, serial_number, venue_name, operator_name, operator_admin_id,
                    target, created_at
             FROM mcn_vehicle_platform_logs
             WHERE mcn_id = ? ORDER BY id DESC LIMIT 50', [(int)$user['mcn_id']]);
        foreach ($logs as $i => $log) {
            $logs[$i]['platform_label'] = mcnVoltagePlatformLabel($log['target']);
        }
        echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['logs' => $logs]], JSON_UNESCAPED_UNICODE);
        return;
    }
    $venueMap = [];
    foreach ($venues as $venue) $venueMap[(int)$venue['id']] = $venue;
    $redis = transferOpenRedis();
    if ($method === 'GET') {
        $ids = array_map('strval', array_keys($venueMap));
        $vehicles = transferQuery($db,
            'SELECT v.id, v.serial_number, v.name, v.bind_site, v.status, v.vehicle_status, v.sharing_status,
             v.is_banned, v.driver_id, v.Reservation_lock, v.image_device_serial, v.bk_image_device_serial,
             v.image_transmission_type, di.id AS device_id, di.room_id AS primary_room_id,
             wifi.wifi_essid AS last_wifi_name, wifi.created_at AS last_wifi_at,
             platform.target AS last_platform_target, platform.created_at AS last_platform_at,
             COALESCE(NULLIF(di.room_id, \'\'), NULLIF(v.image_device_serial, \'\')) AS room_id
             FROM vehicles v
             LEFT JOIN device_information di ON CAST(di.id AS CHAR) = v.image_device_serial
             LEFT JOIN mcn_vehicle_platform_logs platform ON platform.id = (
                 SELECT p.id FROM mcn_vehicle_platform_logs p
                 WHERE p.mcn_id = ? AND p.vehicle_id = v.id ORDER BY p.id DESC LIMIT 1
             )
             LEFT JOIN camera_wifi_send_records wifi ON wifi.id = (
                 SELECT w.id FROM camera_wifi_send_records w
                 WHERE w.room_id = COALESCE(NULLIF(di.room_id, \'\'), NULLIF(v.image_device_serial, \'\'))
                   AND w.zego_code = 0
                 ORDER BY w.id DESC LIMIT 1
             )
             WHERE v.bind_site IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY v.bind_site, v.id DESC', array_merge([(int)$user['mcn_id']], $ids));
        $rows = [];
        $state = transferState($db, $vehicles);
        foreach ($vehicles as $vehicle) {
            $reason = (int)$venueMap[(int)$vehicle['bind_site']]['is_banned'] === 1
                ? '原场地封禁中，无法调配设备' : transferReason($redis, $vehicle, $state);
            $rows[] = [
                'id' => (int)$vehicle['id'], 'serial_number' => $vehicle['serial_number'],
                'name' => $vehicle['name'], 'venue_id' => (int)$vehicle['bind_site'],
                'venue_name' => $venueMap[(int)$vehicle['bind_site']]['venue_name'],
                'status' => $vehicle['status'], 'sharing_status' => $vehicle['sharing_status'],
                'last_wifi_name' => $vehicle['last_wifi_name'], 'last_wifi_at' => $vehicle['last_wifi_at'],
                'room_id' => $vehicle['room_id'],
                'last_platform_label' => $vehicle['last_platform_target'] === null
                    ? null : mcnVoltagePlatformLabel($vehicle['last_platform_target']),
                'last_platform_at' => $vehicle['last_platform_at'],
                'can_switch_platform' => mcnVoltageRoom($vehicle) !== null,
                'can_transfer' => $reason === '', 'blocked_reason' => $reason
            ];
        }
        $logs = transferQuery($db,
            'SELECT l.id, l.serial_number, l.created_at, l.operator_admin_id,
                    s.venue_name AS source_venue_name, t.venue_name AS target_venue_name
             FROM mcn_vehicle_transfer_logs l
             LEFT JOIN venues s ON s.id = l.source_venue_id
             LEFT JOIN venues t ON t.id = l.target_venue_id
             WHERE l.mcn_id = ? ORDER BY l.id DESC LIMIT 50', [(int)$user['mcn_id']]);
        $output = ['venues' => $venues, 'vehicles' => $rows, 'logs' => $logs, 'csrf_token' => $csrf];
    } else {
        $sourceId = (int)$input['source_venue_id'];
        $targetId = (int)$input['target_venue_id'];
        if (!isset($venueMap[$sourceId], $venueMap[$targetId])) throw new RuntimeException('只能在本机构旗下场地间调配', 403);
        if ($sourceId === $targetId) throw new RuntimeException('目标场地与当前场地相同', 400);
        if ((int)$venueMap[$sourceId]['is_banned'] === 1 || (int)$venueMap[$targetId]['is_banned'] === 1) {
            throw new RuntimeException('封禁场地不能参与车辆调配', 403);
        }
        $vehicles = transferQuery($db, 'SELECT * FROM vehicles WHERE id = ? FOR UPDATE', [(int)$input['vehicle_id']]);
        $vehicle = $vehicles[0] ?? null;
        if (!$vehicle || (string)$vehicle['bind_site'] !== (string)$sourceId) {
            throw new RuntimeException('车辆归属已变化，请刷新后重试', 409);
        }
        $reason = transferReason($redis, $vehicle, transferState($db, [$vehicle]));
        if ($reason !== '') throw new RuntimeException($reason, 409);
        $changed = transferQuery($db, 'UPDATE vehicles SET bind_site = ? WHERE id = ? AND bind_site = ?',
            [(string)$targetId, (int)$vehicle['id'], (string)$sourceId], true);
        if ($changed !== 1) throw new RuntimeException('车辆状态已变化，请刷新后重试', 409);
        transferQuery($db,
            'INSERT INTO mcn_vehicle_transfer_logs
             (mcn_id, vehicle_id, serial_number, source_venue_id, target_venue_id, operator_admin_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$user['mcn_id'], (int)$vehicle['id'], $vehicle['serial_number'], $sourceId, $targetId, (int)$user['id']], true);
        if (!$db->getConnection()->commit()) throw new RuntimeException('Commit failed');
        $transaction = false;
        $message = '车辆已切换至' . $venueMap[$targetId]['venue_name'];
        $output = ['vehicle_id' => (int)$vehicle['id'], 'venue_id' => $targetId];
    }
} catch (Throwable $e) {
    if ($transaction) $db->rollBack();
    $code = in_array((int)$e->getCode(), [400, 403, 405, 409, 1001], true) ? (int)$e->getCode() : 500;
    $message = $code === 500 ? '状态校验暂不可用，请稍后重试' : $e->getMessage();
    if ($code === 500) error_log('MCNVehicleTransfer: ' . $e->getMessage());
} finally {
    if ($redis) $redis->close();
    $db->close();
}
echo json_encode(['code' => $code, 'msg' => $message, 'data' => $output], JSON_UNESCAPED_UNICODE);
