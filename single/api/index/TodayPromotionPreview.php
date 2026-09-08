<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function outJson($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (!$sessionToken) {
        outJson(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        outJson(1001, '用户未登录或无权访问');
    }

    $roleId = (int)$user['role_id'];
    if (!in_array($roleId, [1, 2], true)) {
        outJson(1002, '当前账号无平台推广收益预览权限');
    }

    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $sql = "
        SELECT
            COUNT(*) AS order_count,
            COUNT(DISTINCT o.uid) AS invitee_count,
            COUNT(DISTINCT promotion_venue.id) AS promotion_venue_count,
            ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS order_amount,
            ROUND(COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0), 2) AS reward_amount
        FROM orders o
        INNER JOIN users u
                ON u.uid = o.uid
        INNER JOIN venues promotion_venue
                ON promotion_venue.invite_code = u.invitation_code
        WHERE o.end_time >= ?
          AND o.end_time < ?
          AND o.status = '已完成'
          AND o.pays_type != '能量'
          AND (o.note <> 'gift' OR o.note IS NULL)
          AND u.invitation_code IS NOT NULL
          AND u.invitation_code <> ''
          AND u.invitation_code <> '0000'
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND promotion_venue.id <> o.reservation_id
    ";

    $rows = $database->query($sql, [$todayStart, $tomorrowStart]);
    if ($rows === false) {
        outJson(500, '今日推广收益预览查询失败');
    }

    $row = $rows[0] ?? [];
    outJson(0, 'ok', [
        'date' => date('Y-m-d'),
        'order_count' => (int)($row['order_count'] ?? 0),
        'invitee_count' => (int)($row['invitee_count'] ?? 0),
        'promotion_venue_count' => (int)($row['promotion_venue_count'] ?? 0),
        'order_amount' => number_format((float)($row['order_amount'] ?? 0), 2, '.', ''),
        'reward_amount' => number_format((float)($row['reward_amount'] ?? 0), 2, '.', ''),
    ]);
} catch (Throwable $e) {
    error_log('TodayPromotionPreview error: ' . $e->getMessage());
    outJson(500, '服务器内部错误');
} finally {
    $database->close();
}
