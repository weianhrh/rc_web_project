<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

try {
    $token = $_COOKIE['session_token'] ?? null;
    $user = $token ? $database->getUserBySessionToken($token) : null;
    if (!$user) {
        respond(1001, '用户未登录或会话已过期');
    }
    if ((int)$user['role_id'] !== 1) {
        respond(1003, '仅超级管理员可以查看平台核心数据');
    }

    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $rechargeRows = $database->query(
        "SELECT COALESCE(ROUND(SUM(payer_total), 2), 0) AS total_revenue
         FROM RechargeOrders
         WHERE status = '支付成功'
           AND created_at >= ? AND created_at < ?",
        [$todayStart, $tomorrowStart]
    );

    $orderRows = $database->query(
        "SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS order_consumption
         FROM orders
         WHERE end_time >= ? AND end_time < ?
           AND pays_type <> '能量'",
        [$todayStart, $tomorrowStart]
    );

    $giftRows = $database->query(
        "SELECT COALESCE(ROUND(SUM(payment_amount) / 10 * 0.6, 2), 0) AS gift_consumption
         FROM gift_orders
         WHERE send_time >= ? AND send_time < ?
           AND status = '已完成'",
        [$todayStart, $tomorrowStart]
    );

    $platformRevenue = (float)($rechargeRows[0]['total_revenue'] ?? 0);
    $orderConsumption = (float)($orderRows[0]['order_consumption'] ?? 0);
    $giftConsumption = (float)($giftRows[0]['gift_consumption'] ?? 0);

    respond(0, 'ok', [
        'date' => date('Y-m-d'),
        'platform_total_revenue' => number_format($platformRevenue, 2, '.', ''),
        'user_actual_consumption' => number_format($orderConsumption + $giftConsumption, 2, '.', ''),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log('platform_finance_private error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}
