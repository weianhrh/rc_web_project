<?php
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
    if ($redis->select(1) === false) throw new RuntimeException('Redis DB 1 unavailable');
    $ban = $redis->get('device_ban:' . $serial);
    if ($ban !== false) {
        $data = json_decode($ban, true);
        if (!is_array($data) || empty($data['ban_end_time'])
            || strtotime($data['ban_end_time']) === false
            || strtotime($data['ban_end_time']) > time()) return '设备封禁尚未解除';
    }
    if ($redis->select(14) === false) throw new RuntimeException('Redis DB 14 unavailable');
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
    if ($redis->select(12) === false) throw new RuntimeException('Redis DB 12 unavailable');
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

function transferOpenRedis() {
    $redis = new Redis();
    if (!$redis->connect('127.0.0.1', 6379, 1.5)) throw new RuntimeException('Redis unavailable');
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 2);
    return $redis;
}
