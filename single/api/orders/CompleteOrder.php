<?php
require_once '../Database.php';
require_once '../logger.php';
require_once '../RedisHelper.php';
$database = new Database();

// ==========================
// 订单结束后：停止图传/ZEGO 房间通知
// ==========================
define('COMPLETE_ORDER_IMAGE_STOP_LOG', __DIR__ . '/complete_order_image_stop.log');

function completeOrderImageStopLog($message, $context = [])
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    $ok = @file_put_contents(COMPLETE_ORDER_IMAGE_STOP_LOG, $line, FILE_APPEND | LOCK_EX);
    if ($ok === false) {
        error_log($line);
    }
}

function completeOrderGetAuthorizationHeader()
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return $value;
            }
        }
    }

    return '';
}

function completeOrderPostForm($url, $data, $extraHeaders = [])
{
    $postData = http_build_query($data);

    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    foreach ($extraHeaders as $header) {
        if (!empty($header)) {
            $headers[] = $header;
        }
    }

    $safeHeaders = [];
    foreach ($headers as $header) {
        $safeHeaders[] = stripos($header, 'Authorization:') === 0 ? 'Authorization: ***MASKED***' : $header;
    }

    completeOrderImageStopLog('准备POST接口', [
        'url' => $url,
        'data' => $data,
        'headers' => $safeHeaders
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        completeOrderImageStopLog('POST接口返回', [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlError,
            'response' => substr((string)$response, 0, 500),
        ]);

        return [
            'success' => $curlErrNo === 0 && $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlError,
            'response' => (string)$response,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $postData,
            'timeout' => 5,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    $headersResp = isset($http_response_header) ? $http_response_header : [];

    completeOrderImageStopLog('POST接口返回(file_get_contents)', [
        'url' => $url,
        'success' => $response !== false ? 1 : 0,
        'response' => substr((string)$response, 0, 500),
        'headers' => $headersResp,
    ]);

    return [
        'success' => $response !== false,
        'http_code' => 0,
        'curl_errno' => 0,
        'curl_error' => '',
        'response' => (string)$response,
    ];
}

function completeOrderStopImageBySerialNumber($database, $serial_number)
{
    if (empty($serial_number)) {
        completeOrderImageStopLog('跳过停止图传：serial_number为空');
        return;
    }

    $sql = "SELECT
                vh.image_transmission_type,
                vh.image_device_serial,
                di.room_id
            FROM vehicles vh
            LEFT JOIN device_information di ON vh.image_device_serial = di.id
            WHERE vh.serial_number = ?
            LIMIT 1";

    $stmt = $database->prepare($sql);
    if (!$stmt) {
        completeOrderImageStopLog('查询图传信息失败：prepare失败', ['serial_number' => $serial_number]);
        return;
    }

    $stmt->bind_param("s", $serial_number);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        completeOrderImageStopLog('未找到车辆图传信息', ['serial_number' => $serial_number]);
        return;
    }

    $imageTransmissionType = (int)($row['image_transmission_type'] ?? 0);
    $imageDeviceSerial = $row['image_device_serial'] ?? '';
    $roomId = $row['room_id'] ?? '';

    completeOrderImageStopLog('订单结束后图传分支判断', [
        'serial_number' => $serial_number,
        'image_transmission_type' => $imageTransmissionType,
        'image_device_serial' => $imageDeviceSerial,
        'room_id' => $roomId,
    ]);

    // 非 ZEGO：走 start_status.php，把 start_status 置为 0
    if ($imageTransmissionType !== 1) {
        $startStatusUrl = 'https://rcwulian.cn/app/user/car/start_status.php';

        $headers = [];
        $authHeader = completeOrderGetAuthorizationHeader();
        if (!empty($authHeader)) {
            $headers[] = 'Authorization: ' . $authHeader;
        } else {
            completeOrderImageStopLog('调用 start_status.php 时没有 Authorization，若接口强校验Token会失败', [
                'serial_number' => $serial_number,
            ]);
        }

        completeOrderPostForm($startStatusUrl, [
            'serial_number' => $serial_number,
            'start_status' => 0,
        ], $headers);

        return;
    }

    // ZEGO：走房间 ROOM_BAN 自定义信令
    if (empty($roomId)) {
        completeOrderImageStopLog('ZEGO 未调用：room_id为空', [
            'serial_number' => $serial_number,
            'image_device_serial' => $imageDeviceSerial,
        ]);
        return;
    }

    $zegoUrl = 'https://open.rcwulian.cn/api/orders/send_room_ban_message.php';
    completeOrderPostForm($zegoUrl, [
        'room_id' => $roomId,
        'ban_reason' => '订单已结束,请换车驾驶!',
    ]);
}

function completeOrderSuccess($database, $serial_number, $payload)
{
    // 停止图传/发送 ZEGO 通知不能影响订单完成主流程，所以只记录日志，不拦截返回。
    try {
        completeOrderStopImageBySerialNumber($database, $serial_number);
    } catch (Throwable $e) {
        completeOrderImageStopLog('停止图传异常', [
            'serial_number' => $serial_number,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// https://open.rcwulian.cn/api/orders/send_room_ban_message.php
$orderID = $_GET['order_id'] ?? '';

// 获取订单信息，包括状态、开始时间、计费规则以及当前的支付金额
$orderInfoSql = "SELECT serial_number, status, start_time, uid ,billing_rules, payment_amount, pays_type FROM orders WHERE order_id = ?";
$stmt = $database->prepare($orderInfoSql);
$stmt->bind_param("s", $orderID);
$stmt->execute();
$orderResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderResult) {
    echo json_encode(['code' => 400, 'msg' => '订单不存在']);
    exit;
}
$pays_type = $orderResult['pays_type'];
$uid = $orderResult['uid'];
$serial_number = $orderResult['serial_number'] ?? '';

if ($orderResult['status'] !== '正在驾驶') {
    if ($orderResult['status'] == '已完成') {
        // ✅ 前置修复：订单已完成但预约表仍在“正在驾驶”，补齐预约结束时间
if ($orderResult['status'] === '已完成') {

        // 1) 取 orders.end_time（可能为空）
        $stmt = $database->prepare("SELECT end_time FROM orders WHERE order_id = ?");
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $orderEndTime = $row['end_time'] ?? null;
    
        // 2) 找到仍在“正在驾驶”的预约记录
        $stmt = $database->prepare("
            SELECT id
            FROM Reservations
            WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
            LIMIT 1
        ");
        $stmt->bind_param("is", $uid, $orderID);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        // 3) 如果存在，则用 orders.end_time 补写 driving_end_time，并置为已完成
        if ($res) {
            if (!empty($orderEndTime)) {
                $stmt = $database->prepare("
                    UPDATE Reservations
                    SET order_status = '已完成',
                        driving_end_time = ?
                    WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
                ");
                $stmt->bind_param("sis", $orderEndTime, $uid, $orderID);
            } else {
                // orders.end_time 为空：兜底用 NOW()
                $stmt = $database->prepare("
                    UPDATE Reservations
                    SET order_status = '已完成',
                        driving_end_time = NOW()
                    WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
                ");
                $stmt->bind_param("is", $uid, $orderID);
            }
            $stmt->execute();
            $stmt->close();
        }
    
        // 4) 这里按你原逻辑：已完成直接返回
        completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => '订单修复已完成']);
    }

    } else {
        echo json_encode(['code' => 400, 'msg' => '只有进行中的订单可以结束']);
        exit;
    }
}

// 从查询结果中提取当前的支付金额
$existingPaymentAmount = $orderResult['payment_amount'] ?? 0; // 如果没有金额，默认为0
// 从查询结果中提取当前的支付金额
$existingtime = $orderResult['billing_rules'] ?? 0; // 可驾驶的时长
if ($existingtime > 0) {
    $ratePerMinute = ceil($existingPaymentAmount / $existingtime); // 每分钟资费，向上取整
} else {
    $ratePerMinute = 0; // 防止除以0错误
}

$sql = "SELECT driving_start_time, order_number,driving_duration FROM Reservations WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'";
$params = [$uid, $orderID];
$result = $database->query($sql, $params);
if ($result && count($result) > 0) {
    $currentReservation = $result[0];
    $drivingStartTime = strtotime($currentReservation['driving_start_time']);
    $drivingDuration = $currentReservation['driving_duration']; // 假设这个是以分钟为单位
    $order_number = $currentReservation['order_number'];

    // 计算剩余时间
    $currentTime = time();
    $endTime = $drivingStartTime + ($drivingDuration * 60); // 转换为秒
    $remainingTime = $endTime - $currentTime;

    // 计算已使用的时间（秒）
    $usedTime = $currentTime - $drivingStartTime;

    // 如果驾驶尚未开始，则已使用时间为 0
    if ($usedTime < 0) {
        $usedTime = 0;
    }
    $usedTimeMinutes = ceil($usedTime / 60); // 不足1分钟也会按照1分钟扣费

    if ($remainingTime < 0) {

        // ✅ 修复点：如果 NOW() 已超过 $endTime，则结束时间写入本应结束的 $endTime
        // 结束订单
        $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = FROM_UNIXTIME(?) WHERE order_id = ?");
        $stmt->bind_param("is", $endTime, $orderID);
        $stmt->execute();
        $stmt->close();

        // 结束预约记录
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = FROM_UNIXTIME(?) WHERE order_number = ?");
        $stmt->bind_param("is", $endTime, $orderID);
        $stmt->execute();
        $stmt->close();

        // 检查订单是否存在并属于当前用户
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);

        if (!$checkResult || count($checkResult) == 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }

        $reservation = $checkResult[0];
        $reservationLocation = $reservation['reservation_id'];

        $redisHelper = new RedisHelper();
        $redisHelper->connect(); // 可选参数：主机，端口，超时
        $redisHelper->selectDb(9); // 选择 Redis 的第 6 个数据库
        $redisHelper->delete($orderID);
        $redisHelper->close();

        completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => '订单已完成']);
    } else {

        // 结束预约记录
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = NOW() WHERE order_number = ?");
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $stmt->close();

        // 取订单所在的场地id
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);
        if (!$checkResult || count($checkResult) == 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }
        $reservation = $checkResult[0];
        $reservationLocation = $reservation['reservation_id'];

        // 减少排队人数
        $redisHelper = new RedisHelper();
        $redisHelper->connect(); // 可选参数：主机，端口，超时
        $redisHelper->selectDb(9); // 选择 Redis 的第 6 个数据库
        $redisHelper->delete($orderID);
        $redisHelper->close();

        if ($pays_type == "能量") {

            $stmt = $database->prepare("SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("ii", $uid, $reservationLocation);
            $stmt->execute();
            $userEnergy = $stmt->get_result()->fetch_assoc()['energy']; // 取用户所在场地的能量
            $stmt->close();

            // 更新订单中的实际支付金额
            // 使用 ceil 函数向上取整计算已经使用的金额
            $usedTimepay = ceil($usedTimeMinutes) * $ratePerMinute;

            // 计算返还金额
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {

                $newEnergy = $userEnergy + $refundAmount;

                // 更新用户余额
                $stmt = $database->prepare("UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?");
                $stmt->bind_param("dii", $newEnergy, $uid, $reservationLocation);
                $stmt->execute();
                $stmt->close();

                // 插入余额变动记录
                $stmt = $database->prepare("INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )");

                $reason = '未驾驶时长返还'; // 变动原因
                $stmt->bind_param("iiidsd", $uid, $reservationLocation, $refundAmount, $newEnergy, $reason, $userEnergy);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ?");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $stmt->close();
                $msg = '已提前结束驾驶，返还能量：' . $refundAmount;
                // 输出成功信息
                completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
            } else {
                // 结束订单并更新实际使用时间
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ?";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $stmt->close();
                completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => '订单已完成']);
            }
        } else {

            // 获取用户余额
            $stmt = $database->prepare("SELECT wallet FROM users WHERE uid = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $userWallet = $stmt->get_result()->fetch_assoc()['wallet'];
            $stmt->close();

            $usedTimepay = ceil($usedTimeMinutes) * $ratePerMinute;

            // 计算返还金额
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {

                $newWallet = $userWallet + $refundAmount;

                // 更新用户余额
                $stmt = $database->prepare("UPDATE users SET wallet = ? WHERE uid = ?");
                $stmt->bind_param("di", $newWallet, $uid);
                $stmt->execute();
                $stmt->close();

                // 获取变动前的余额
                $balanceBefore = $userWallet;

                // 计算变动后的余额
                $balanceAfter = $newWallet;

                // 插入余额变动记录
                $stmt = $database->prepare("INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $changeType = '充值';
                $description = '未驾驶时长返还[后台自动结算]';
                $paymentChannel = '余额';
                $stmt->bind_param("idddsss", $uid, $refundAmount, $balanceBefore, $balanceAfter, $changeType, $description, $paymentChannel);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ?");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $stmt->close();
                $msg = '已提前结束驾驶，剩余时长返还余额：' . $refundAmount . $pays_type;
                // 输出成功信息
                completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
            } else {
                // 结束订单并更新实际使用时间
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ?";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $stmt->close();
                completeOrderSuccess($database, $serial_number, ['code' => 200, 'msg' => '订单已完成']);
            }
        }
    }
}

echo json_encode(['code' => 404, 'msg' => '没有找到相应的预约记录']);
exit;
?>