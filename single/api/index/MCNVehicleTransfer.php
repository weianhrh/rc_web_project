<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function transferQuery($db, $sql, $params = [], $write = false) {
    $result = $db->query($sql, $params, $write);
    if ($result === false) throw new RuntimeException('Database operation failed');
    return $result;
}

function transferState($db, $vehicles) {
    if (!$vehicles) return ['bans' => [], 'orders' => [], 'reservations' => [], 'automation' => []];
    $serials = array_column($vehicles, 'serial_number');
    $sites = array_values(array_unique(array_column($vehicles, 'bind_site')));
    $bans = transferQuery($db, 'SELECT serial_number, image_device_serial FROM device_bans
        WHERE status = 1 AND (ban_end_time IS NULL OR ban_end_time > NOW())');
    $orders = transferQuery($db, "SELECT DISTINCT serial_number FROM orders WHERE status = '正在驾驶'
        AND serial_number IN (" . implode(',', array_fill(0, count($serials), '?')) . ')', $serials);
    $reservations = transferQuery($db, "SELECT DISTINCT reservation_id FROM Reservations
        WHERE order_status IN ('等待驾驶', '正在驾驶') AND reservation_id IN ("
        . implode(',', array_fill(0, count($sites), '?')) . ')', $sites);
    $automation = [];
    foreach (transferQuery($db, 'SELECT vehicle_states FROM excavator_auto_run_locks') as $lock) {
        $states = json_decode($lock['vehicle_states'], true);
        if (!is_array($states)) throw new RuntimeException('Invalid automation lock');
        foreach ($states as $state) $automation[(int)($state['id'] ?? 0)] = true;
    }
    return ['bans' => $bans,
        'orders' => array_fill_keys(array_column($orders, 'serial_number'), true),
        'reservations' => array_fill_keys(array_column($reservations, 'reservation_id'), true),
        'automation' => $automation];
}

function transferReason($redis, $vehicle, $state) {
    $serial = $vehicle['serial_number'];
    if ((int)$vehicle['is_banned'] === 1) return '设备违规封禁中';
    $images = array_values(array_filter([
        trim((string)$vehicle['image_device_serial']),
        trim((string)$vehicle['bk_image_device_serial'])
    ], function ($value) { return $value !== ''; }));
    foreach ($state['bans'] as $ban) {
        if ($ban['serial_number'] === $serial || in_array((string)$ban['image_device_serial'], $images, true)) {
            return '设备存在未解除的违规封禁';
        }
    }
    $redis->select(1);
    $ban = $redis->get('device_ban:' . $serial);
    if ($ban !== false) {
        $data = json_decode($ban, true);
        if (!is_array($data) || empty($data['ban_end_time'])
            || strtotime($data['ban_end_time']) === false
            || strtotime($data['ban_end_time']) > time()) return '设备封禁尚未解除';
    }
    $redis->select(14);
    foreach ($images as $image) {
        if ($redis->exists('device_violation:' . $image)
            || $redis->exists('device_violation:' . $image . '_predicted')) {
            return '设备存在待处理违规，请先完成审核';
        }
    }
    if ((int)$vehicle['Reservation_lock'] !== 0
        || (int)$vehicle['driver_id'] > 0
        || in_array($vehicle['status'], ['占有', '占用'], true)
        || $vehicle['vehicle_status'] === '开始围观') return '设备正在使用或预约锁定';
    $redis->select(12);
    if ($redis->exists($serial)) return '设备存在当前驾驶占用';
    if (isset($state['orders'][$serial])) {
        return '设备存在未结束的驾驶订单';
    }
    if (isset($state['reservations'][$vehicle['bind_site']])) {
        return '原场地有未结束的预约，请待预约结束后调配';
    }
    // Even expired automation locks must be released before moving their vehicles.
    if (isset($state['automation'][(int)$vehicle['id']])) return '设备自动运行锁尚未释放';
    return '';
}

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
    $venueMap = [];
    foreach ($venues as $venue) $venueMap[(int)$venue['id']] = $venue;
    $redis = new Redis();
    if (!$redis->connect('127.0.0.1', 6379, 1.5)) throw new RuntimeException('Redis unavailable');
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 2);
    if ($method === 'GET') {
        $ids = array_map('strval', array_keys($venueMap));
        $vehicles = transferQuery($db,
            'SELECT id, serial_number, name, bind_site, status, vehicle_status, sharing_status,
             is_banned, driver_id, Reservation_lock, image_device_serial, bk_image_device_serial
             FROM vehicles WHERE bind_site IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY bind_site, id DESC', $ids);
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
