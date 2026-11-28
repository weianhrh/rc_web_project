<?php
require_once __DIR__ . '/../index/McnVehicleTransferState.php';

// 兼容旧设备直接保存房号，也保留 001 等房号的前导零。
function mcnVoltageRoom(array $vehicle): ?string
{
    $type = (int)$vehicle['image_transmission_type'];
    $room = trim((string)($vehicle['device_id'] === null
        ? $vehicle['image_device_serial'] : $vehicle['primary_room_id']));
    if (!in_array($type, [0, 1], true) || !preg_match('/^[0-9]{1,7}$/D', $room) || (int)$room === 0) {
        return null;
    }
    return $room;
}

function mcnVoltagePlatformLabel(string $target): string
{
    $labels = ['rc' => 'RC物联', 'xhyk' => '星河远控', 'reset' => '设备默认'];
    return $labels[$target] ?? '';
}

// Called by the authenticated device command endpoint.
function mcnVoltagePlatform($db, array $user, string $token, array $input, string $csrf, callable $zego,
    ?callable $openRedis = null): string
{
    if (empty($user['is_mcn']) || empty($user['mcn_id'])) {
        throw new RuntimeException('仅 MCN 机构账号可以切换平台', 403);
    }
    if (!empty($user['session_expires']) && strtotime($user['session_expires']) <= time()) {
        throw new RuntimeException('登录已过期，请重新登录', 1001);
    }
    if (!hash_equals(hash_hmac('sha256', 'mcn_vehicle_transfer', $token), $csrf)) {
        throw new RuntimeException('请求校验失败，请刷新车辆列表', 403);
    }
    $commands = [
        'rc' => '+VU#http://rcwulian.cn/app/dump/dump_post.php-',
        'xhyk' => '+VU#http://sxzjjx.cn/app/dump/dump_post.php-',
        'reset' => '+VU#RESET-',
    ];
    $target = $input['target'] ?? '';
    $vehicleId = filter_var($input['vehicle_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$vehicleId || !is_string($target) || ($target !== 'check' && !isset($commands[$target]))) {
        throw new RuntimeException('请选择有效车辆及平台', 400);
    }

    $connection = $db->getConnection();
    // 查流经过网络请求，下发前需要能读到这期间新提交的驾驶订单和预约。
    if ($target !== 'check' && !$connection->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) {
        throw new RuntimeException('Cannot set transaction isolation');
    }
    if (!$connection->begin_transaction()) throw new RuntimeException('Transaction failed');
    $committed = false;
    $redis = null;
    try {
        // 锁定本车及当前绑定，确保查流与发送期间车辆仍归该机构管理。
        $rows = $db->query(
            "SELECT v.id, v.bind_site, v.name, v.serial_number, venue.venue_name, venue.is_banned AS venue_is_banned,
                    v.is_banned, v.driver_id, v.Reservation_lock, v.status, v.vehicle_status, v.bk_image_device_serial,
                    v.image_transmission_type, v.image_device_serial, di.id AS device_id, di.room_id AS primary_room_id
             FROM vehicles v
             JOIN venues venue ON venue.id = v.bind_site
             JOIN mcn_venues mv ON mv.venue_id = v.bind_site AND mv.mcn_id = ? AND mv.status = 1
             JOIN mcn_organizations mo ON mo.id = mv.mcn_id AND mo.status = 1
             LEFT JOIN device_information di ON di.id = v.image_device_serial
                 AND CAST(di.id AS CHAR) = v.image_device_serial
             WHERE v.id = ? FOR UPDATE", [(int)$user['mcn_id'], $vehicleId]);
        if ($rows === false) throw new RuntimeException('Vehicle query failed');
        if (!$rows) throw new RuntimeException('该车辆不在本机构当前关联的场地内', 403);
        $vehicle = $rows[0];
        $room = mcnVoltageRoom($vehicle);
        if ($room === null) {
            throw new RuntimeException('该车辆未关联 Zego 主图传房间', 400);
        }
        if ($room !== ($input['expected_room'] ?? '') || (string)$vehicle['bind_site'] !== ($input['expected_venue'] ?? '')) {
            throw new RuntimeException('车辆的场地或图传房间已变更，请刷新列表', 409);
        }
        // 房间信令会广播到房间。车辆重复绑定同一主图传或后视图传时，不能按单车指派。
        $other = $db->query(
            "SELECT v.id FROM vehicles v
             LEFT JOIN device_information front ON front.id = v.image_device_serial AND CAST(front.id AS CHAR) = v.image_device_serial
             LEFT JOIN device_information rear ON rear.id = v.bk_image_device_serial AND CAST(rear.id AS CHAR) = v.bk_image_device_serial
             WHERE v.id <> ? AND (
                COALESCE(NULLIF(front.room_id, ''), v.image_device_serial) = ?
                OR COALESCE(NULLIF(rear.room_id, ''), v.bk_image_device_serial) = ?
             ) LIMIT 1", [$vehicleId, $room, $room]);
        if ($other === false) throw new RuntimeException('Room binding query failed');
        if ($other) throw new RuntimeException('该图传房间关联多辆车，请先核对设备绑定', 409);

        if ($target !== 'check') {
            if ((int)$vehicle['venue_is_banned'] === 1) {
                throw new RuntimeException('原场地封禁中，无法调配设备', 409);
            }
            $redis = $openRedis === null ? transferOpenRedis() : $openRedis();
            mcnVoltageCheckTransferState($db, $redis, $vehicle);
        }
        $state = mcnVoltageZegoResponse($zego, 'DescribeRTCStreamState', [
            'StreamId' => $room, 'Sequence' => (string)round(microtime(true) * 1000)
        ]);
        if (in_array($state['Code'], [41004, 41006], true)) {
            throw new RuntimeException('流不在线，请先上线车辆再发送切换平台指令', 409);
        }
        if ($state['Code'] !== 0) {
            throw new RuntimeException('流查询失败（Zego ' . $state['Code'] . '），请稍后重试', 502);
        }
        if ($target === 'check') return '流在线';
        // 车辆和场地行已锁定；此处重查独立变化的 Redis 占用、预约和订单。
        mcnVoltageCheckTransferState($db, $redis, $vehicle);
        // 在发送前准备快照；Zego 确认下发成功后才提交，失败的指令不会成为最近平台。
        $inserted = $db->query(
            'INSERT INTO mcn_vehicle_platform_logs
             (mcn_id, vehicle_id, venue_id, vehicle_name, serial_number, room_id,
              venue_name, operator_admin_id, operator_name, target)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$user['mcn_id'], $vehicleId, (int)$vehicle['bind_site'], (string)$vehicle['name'],
             (string)$vehicle['serial_number'], $room, (string)$vehicle['venue_name'],
             (int)$user['id'], (string)($user['username'] ?? ''), $target], true);
        if ($inserted !== 1) {
            throw new RuntimeException('切换记录无法保存，指令未发送，请联系管理员检查记录表', 502);
        }
        $reply = mcnVoltageZegoResponse($zego, 'SendCustomCommand', [
            'RoomId' => $room, 'FromUserId' => 'server_bot_1', 'MessageContent' => $commands[$target]
        ]);
        if ($reply['Code'] !== 0 || !empty($reply['Data']['FailUsers'])) {
            throw new RuntimeException('Zego 未成功下发指令，请检查车辆是否仍在线（Code ' . $reply['Code'] . '）', 502);
        }
        try {
            $committed = $connection->commit();
        } catch (Throwable $e) {
            error_log('MCN voltage log commit: ' . $e->getMessage());
        }
        if (!$committed) {
            throw new RuntimeException('指令已发送，但记录保存结果未确认，请刷新记录并核对车辆回传', 502);
        }
        if ($target === 'reset') return '恢复默认电压回传地址指令已发送';
        return '已发送切换至「' . mcnVoltagePlatformLabel($target) . '」的电压回传指令';
    } finally {
        if (!$committed) {
            try {
                $connection->rollback();
            } catch (Throwable $e) {
                error_log('MCN voltage rollback: ' . $e->getMessage());
            }
        }
        if ($redis !== null) $redis->close();
    }
}

function mcnVoltageCheckTransferState($db, $redis, array $vehicle): void
{
    $reason = transferReason($redis, $vehicle, transferState($db, [$vehicle]));
    if ($reason !== '') throw new RuntimeException($reason, 409);
}

function mcnVoltageZegoResponse(callable $zego, string $action, array $params): array
{
    [$raw, $error, $http] = $zego($action, $params);
    $reply = is_string($raw) ? json_decode($raw, true) : null;
    $code = $reply['Code'] ?? null;
    if ($error !== '' || $http !== 200 || !(is_int($code) || (is_string($code) && ctype_digit($code)))) {
        $message = $action === 'SendCustomCommand'
            ? '未收到有效的发送结果，请核对车辆回传后重试'
            : '暂时无法查询流状态，请稍后重试';
        throw new RuntimeException($message, 502);
    }
    $reply['Code'] = (int)$code;
    return $reply;
}
