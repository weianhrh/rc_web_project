<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';

function out($code, $msg, $data = array(), $httpCode = 200) {
    // This site replaces non-200 API responses with an HTML error page.
    // Keep transport status 200 and expose the business status in `code`.
    http_response_code(200);
    echo json_encode(array('code' => $code, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function restore_vehicle_states($database, $states) {
    if (!is_array($states)) return;
    foreach ($states as $state) {
        $id = isset($state['id']) ? (int)$state['id'] : 0;
        $sharingStatus = array_key_exists('sharing_status', $state) ? $state['sharing_status'] : null;
        if ($id > 0) {
            $restored = $database->query(
                'UPDATE vehicles SET sharing_status = ? WHERE id = ?',
                array($sharingStatus, $id),
                true
            );
            if ($restored === false) throw new Exception('车辆共享状态恢复失败: ' . $id);
        }
    }
}

function cleanup_expired_locks($database) {
    $expired = $database->query(
        'SELECT room_id FROM excavator_auto_run_locks WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT 100'
    );
    if (!$expired) return 0;

    $count = 0;
    foreach ($expired as $row) {
        $roomId = (string)$row['room_id'];
        $database->beginTransaction();
        try {
            $locked = $database->query(
                'SELECT vehicle_states FROM excavator_auto_run_locks WHERE room_id = ? AND expires_at < NOW() FOR UPDATE',
                array($roomId)
            );
            if ($locked) {
                restore_vehicle_states($database, json_decode($locked[0]['vehicle_states'], true));
                $database->query('DELETE FROM excavator_auto_run_locks WHERE room_id = ?', array($roomId), true);
                $count++;
            }
            $database->commit();
        } catch (Exception $e) {
            $database->rollBack();
            error_log('Excavator lock cleanup failed: ' . $e->getMessage());
        }
    }
    return $count;
}

$database = new Database();

if (PHP_SAPI === 'cli') {
    $cleaned = cleanup_expired_locks($database);
    echo 'cleaned=' . $cleaned . PHP_EOL;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, '仅支持 POST 请求', array(), 405);

$sessionToken = isset($_COOKIE['session_token']) ? $_COOKIE['session_token'] : '';
$adminUser = $sessionToken !== '' ? $database->getUserBySessionToken($sessionToken) : null;
if (!$adminUser) out(1001, '用户未登录或会话已过期');

$roleId = (int)$adminUser['role_id'];
if (!in_array($roleId, array(1, 2, 3), true)) out(403, '当前账号无设备自动运行权限', array(), 403);
$venueId = (int)(isset($adminUser['venue_id']) ? $adminUser['venue_id'] : 0);
if ($roleId === 3 && $venueId <= 0) out(403, '加盟商账号未绑定场地，不能控制设备');

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$roomId = isset($_POST['room_id']) ? trim((string)$_POST['room_id']) : '';
$lockToken = isset($_POST['lock_token']) ? trim((string)$_POST['lock_token']) : '';
$operatorId = (int)(isset($adminUser['id']) ? $adminUser['id'] : (isset($adminUser['uid']) ? $adminUser['uid'] : 0));

cleanup_expired_locks($database);

if ($action === 'list') {
    $venueScopeSql = $roleId === 3 ? ' AND v.bind_site = ?' : '';
    $venueScopeParams = $roleId === 3 ? array($venueId) : array();
    $rows = $database->query(
        "SELECT di.device_id,
                di.room_id,
                di.rtc_user_id,
                COUNT(v.id) AS vehicle_count,
                GROUP_CONCAT(DISTINCT v.name ORDER BY v.id SEPARATOR ' / ') AS vehicle_names,
                SUM(CASE WHEN IFNULL(v.driver_id, 0) > 0 OR v.Reservation_lock = 1 THEN 1 ELSE 0 END) AS busy_count,
                SUM(CASE WHEN v.sharing_status = '未共享' THEN 1 ELSE 0 END) AS unshared_count,
                MIN(COALESCE(vcs.direction_max, 2000)) AS direction_max,
                MIN(COALESCE(vcs.direction_min, 1000)) AS direction_min,
                MIN(COALESCE(vcs.direction_mid, 1500)) AS direction_mid,
                MIN(COALESCE(vcs.throttle_max, 2000)) AS throttle_max,
                MIN(COALESCE(vcs.throttle_min, 1000)) AS throttle_min,
                MIN(COALESCE(vcs.throttle_mid, 1500)) AS throttle_mid
         FROM device_information di
         INNER JOIN vehicles v
           ON v.image_device_serial = CAST(di.id AS CHAR)
           OR v.bk_image_device_serial = CAST(di.id AS CHAR)
         LEFT JOIN vehicle_control_settings vcs ON vcs.serial_number = v.serial_number
         WHERE di.room_id IS NOT NULL AND di.room_id <> ''
         {$venueScopeSql}
         GROUP BY di.id, di.device_id, di.room_id, di.rtc_user_id
         ORDER BY di.device_id DESC",
        $venueScopeParams
    );
    if ($rows === false) out(500, '可控设备列表加载失败');
    out(200, '可控设备列表加载成功', array('rows' => $rows));
}

if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $roomId)) out(400, 'room_id 格式错误');

if ($action === 'acquire') {
    $database->beginTransaction();
    try {
        $existing = $database->query(
            'SELECT lock_token, operator_id, expires_at FROM excavator_auto_run_locks WHERE room_id = ? FOR UPDATE',
            array($roomId)
        );
        if ($existing) {
            $database->rollBack();
            out(409, '该设备正在执行其他自动任务，请稍后再试', array(
                'expires_at' => $existing[0]['expires_at']
            ), 409);
        }

        $deviceRows = $database->query(
            'SELECT id, device_id FROM device_information WHERE room_id = ? LIMIT 1',
            array($roomId)
        );
        if (!$deviceRows) {
            $database->rollBack();
            out(404, '未找到对应设备房间', array(), 404);
        }

        $deviceId = (string)$deviceRows[0]['device_id'];
        $deviceReferenceId = (string)$deviceRows[0]['id'];
        $vehicles = $database->query(
            'SELECT id, serial_number, name, bind_site, sharing_status, driver_id, Reservation_lock
             FROM vehicles
             WHERE image_device_serial = ? OR bk_image_device_serial = ?
             FOR UPDATE',
            array($deviceReferenceId, $deviceReferenceId)
        );
        if (!$vehicles) {
            $database->rollBack();
            out(404, '该摄像头房间没有关联车辆，不能开始运行', array(), 404);
        }

        if ($roleId === 3) {
            foreach ($vehicles as $vehicle) {
                if ((int)$vehicle['bind_site'] !== $venueId) {
                    $database->rollBack();
                    out(403, '该设备房间包含其他场地车辆，已阻止控制', array(), 403);
                }
            }
        }

        $busy = array();
        $states = array();
        foreach ($vehicles as $vehicle) {
            $driverId = (int)$vehicle['driver_id'];
            $reservationLock = (int)$vehicle['Reservation_lock'];
            if ($driverId > 0 || $reservationLock === 1) {
                $busy[] = array(
                    'serial_number' => $vehicle['serial_number'],
                    'name' => $vehicle['name'],
                    'driver_id' => $driverId,
                    'reservation_lock' => $reservationLock
                );
            }
            $states[] = array(
                'id' => (int)$vehicle['id'],
                'sharing_status' => (string)$vehicle['sharing_status']
            );
        }
        if ($busy) {
            $database->rollBack();
            out(409, '设备已有用户驾驶或预约，已阻止自动运行', array('busy_vehicles' => $busy), 409);
        }

        foreach ($states as $state) {
            $updated = $database->query(
                "UPDATE vehicles SET sharing_status = '未共享' WHERE id = ?",
                array($state['id']),
                true
            );
            if ($updated === false) throw new Exception('车辆共享状态切换失败: ' . $state['id']);
        }

        $newToken = bin2hex(random_bytes(24));
        $stateJson = json_encode($states, JSON_UNESCAPED_UNICODE);
        $inserted = $database->query(
            'INSERT INTO excavator_auto_run_locks
             (room_id, device_id, lock_token, operator_id, vehicle_states, acquired_at, heartbeat_at, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 2 MINUTE))',
            array($roomId, $deviceId, $newToken, $operatorId, $stateJson),
            true
        );
        if ($inserted === false) throw new Exception('运行锁保存失败');

        $database->commit();
        out(200, '设备已切换为未共享', array(
            'lock_token' => $newToken,
            'vehicle_count' => count($vehicles),
            'expires_in_seconds' => 120
        ));
    } catch (Exception $e) {
        $database->rollBack();
        error_log('Excavator lock acquire failed: ' . $e->getMessage());
        out(500, '设备运行锁获取失败', array(), 500);
    }
}

if ($action === 'heartbeat') {
    if (!preg_match('/^[a-f0-9]{48}$/', $lockToken)) out(400, '运行锁凭证无效');
    $affected = $database->query(
        'UPDATE excavator_auto_run_locks
         SET heartbeat_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
         WHERE room_id = ? AND lock_token = ? AND operator_id = ?',
        array($roomId, $lockToken, $operatorId),
        true
    );
    if ($affected !== 1) out(409, '运行锁已失效，请立即停止设备', array(), 409);
    out(200, '运行锁已续期');
}

if ($action === 'release') {
    if (!preg_match('/^[a-f0-9]{48}$/', $lockToken)) out(400, '运行锁凭证无效');
    $database->beginTransaction();
    try {
        $locked = $database->query(
            'SELECT vehicle_states FROM excavator_auto_run_locks
             WHERE room_id = ? AND lock_token = ? AND operator_id = ? FOR UPDATE',
            array($roomId, $lockToken, $operatorId)
        );
        if (!$locked) {
            $database->rollBack();
            out(409, '运行锁不存在或已释放', array(), 409);
        }
        restore_vehicle_states($database, json_decode($locked[0]['vehicle_states'], true));
        $database->query(
            'DELETE FROM excavator_auto_run_locks WHERE room_id = ? AND lock_token = ?',
            array($roomId, $lockToken),
            true
        );
        $database->commit();
        out(200, '设备共享状态已恢复');
    } catch (Exception $e) {
        $database->rollBack();
        error_log('Excavator lock release failed: ' . $e->getMessage());
        out(500, '恢复设备共享状态失败，请人工检查', array(), 500);
    }
}

out(400, '不支持的操作');
