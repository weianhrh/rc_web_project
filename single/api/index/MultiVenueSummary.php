<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function placeholders($values) {
    return implode(',', array_fill(0, count($values), '?'));
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (!$sessionToken) {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['id']) || empty($user['role_id'])) {
        respond(1001, '用户未登录或无权访问');
    }

    if (empty($user['is_mcn']) || empty($user['mcn_id'])) {
        respond(1003, '仅MCN账号可以查看全部场地汇总');
    }

    $authorizedVenues = $database->query(
        "SELECT v.id, v.venue_name, v.invite_code
         FROM mcn_venues mv
         INNER JOIN venues v ON v.id = mv.venue_id
         INNER JOIN mcn_organizations mo ON mo.id = mv.mcn_id AND mo.status = 1
         WHERE mv.mcn_id = ? AND mv.status = 1
         ORDER BY mv.is_default DESC, v.id ASC",
        [(int)$user['mcn_id']]
    );

    if (!$authorizedVenues) {
        respond(1002, '当前账号没有可管理场地');
    }

    $venueIds = array_map(function ($venue) {
        return (int)$venue['id'];
    }, $authorizedVenues);
    $in = placeholders($venueIds);
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd = date('Y-m-d 23:59:59');

    $orderParams = array_merge([$todayStart, $todayEnd], $venueIds);
    $orderRows = $database->query(
        "SELECT COUNT(*) AS order_count,
                COALESCE(SUM(payment_amount), 0) AS order_total
         FROM orders
         WHERE end_time >= ? AND end_time <= ?
           AND status = '已完成'
           AND pays_type != '能量'
           AND (note <> 'gift' OR note IS NULL)
           AND reservation_id IN ($in)",
        $orderParams
    );
    $orderRow = $orderRows[0] ?? [];

    $deductionParams = array_merge([$todayStart, $todayEnd], $venueIds);
    $deductionRows = $database->query(
        "SELECT COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0) AS promotion_deduction
         FROM orders o
         INNER JOIN users u ON u.uid = o.uid
         INNER JOIN venues promotion_venue ON promotion_venue.invite_code = u.invitation_code
         WHERE o.end_time >= ? AND o.end_time <= ?
           AND o.status = '已完成'
           AND o.pays_type != '能量'
           AND (o.note <> 'gift' OR o.note IS NULL)
           AND o.reservation_id IN ($in)
           AND promotion_venue.id <> o.reservation_id",
        $deductionParams
    );
    $promotionDeduction = (float)($deductionRows[0]['promotion_deduction'] ?? 0);

    $giftParams = array_merge([$todayStart, $todayEnd], $venueIds);
    $giftRows = $database->query(
        "SELECT COALESCE(SUM(payment_amount), 0) AS gift_total
         FROM gift_orders
         WHERE send_time >= ? AND send_time <= ?
           AND reservation_id IN ($in)",
        $giftParams
    );
    $giftPayment = ((float)($giftRows[0]['gift_total'] ?? 0) / 10) * 0.6;

    $promotionParams = array_merge([$todayStart, $todayEnd], $venueIds);
    $promotionRows = $database->query(
        "SELECT COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0) AS promotion_income
         FROM orders o
         INNER JOIN users u ON u.uid = o.uid
         INNER JOIN venues promotion_venue ON promotion_venue.invite_code = u.invitation_code
         WHERE o.end_time >= ? AND o.end_time <= ?
           AND o.status = '已完成'
           AND o.pays_type != '能量'
           AND (o.note <> 'gift' OR o.note IS NULL)
           AND o.reservation_id IS NOT NULL
           AND o.reservation_id > 0
           AND promotion_venue.id <> o.reservation_id
           AND promotion_venue.id IN ($in)",
        $promotionParams
    );
    $promotionIncome = (float)($promotionRows[0]['promotion_income'] ?? 0);

    $inviteParams = array_merge([$todayStart, $todayEnd], $venueIds);
    $inviteRows = $database->query(
        "SELECT COUNT(DISTINCT u.uid) AS invite_user_count
         FROM users u
         INNER JOIN venues v ON v.invite_code = u.invitation_code
         WHERE u.created_at >= ? AND u.created_at <= ?
           AND v.id IN ($in)",
        $inviteParams
    );
    $todayInviteUsers = (int)($inviteRows[0]['invite_user_count'] ?? 0);

    $devicePaymentBeforeDeduction = (float)($orderRow['order_total'] ?? 0);
    if ((int)$user['role_id'] === 4) {
        $devicePaymentBeforeDeduction *= 0.8;
    }
    $devicePayment = $devicePaymentBeforeDeduction - $promotionDeduction;

    $breakdownParams = array_merge(
        [$todayStart, $todayEnd], $venueIds,
        [$todayStart, $todayEnd], $venueIds,
        [$todayStart, $todayEnd], $venueIds,
        [$todayStart, $todayEnd], $venueIds
    );
    $breakdownRows = $database->query(
        "SELECT v.id AS venue_id,
                v.venue_name,
                COALESCE(o.order_count, 0) AS order_count,
                COALESCE(o.order_total, 0) AS order_total,
                COALESCE(d.promotion_deduction, 0) AS promotion_deduction,
                COALESCE(g.gift_total, 0) AS gift_total,
                COALESCE(i.invite_count, 0) AS invite_count
         FROM venues v
         LEFT JOIN (
             SELECT reservation_id, COUNT(*) AS order_count, SUM(payment_amount) AS order_total
             FROM orders
             WHERE end_time >= ? AND end_time <= ?
               AND status = '已完成'
               AND pays_type != '能量'
               AND (note <> 'gift' OR note IS NULL)
               AND reservation_id IN ($in)
             GROUP BY reservation_id
         ) o ON o.reservation_id = v.id
         LEFT JOIN (
             SELECT o.reservation_id,
                    SUM(ROUND(o.payment_amount * 0.10, 2)) AS promotion_deduction
             FROM orders o
             INNER JOIN users u ON u.uid = o.uid
             INNER JOIN venues promotion_venue ON promotion_venue.invite_code = u.invitation_code
             WHERE o.end_time >= ? AND o.end_time <= ?
               AND o.status = '已完成'
               AND o.pays_type != '能量'
               AND (o.note <> 'gift' OR o.note IS NULL)
               AND o.reservation_id IN ($in)
               AND promotion_venue.id <> o.reservation_id
             GROUP BY o.reservation_id
         ) d ON d.reservation_id = v.id
         LEFT JOIN (
             SELECT reservation_id, SUM(payment_amount) AS gift_total
             FROM gift_orders
             WHERE send_time >= ? AND send_time <= ?
               AND reservation_id IN ($in)
             GROUP BY reservation_id
         ) g ON g.reservation_id = v.id
         LEFT JOIN (
             SELECT iv.id AS venue_id, COUNT(DISTINCT u.uid) AS invite_count
             FROM venues iv
             LEFT JOIN users u
                    ON u.invitation_code = iv.invite_code
                   AND u.created_at >= ? AND u.created_at <= ?
             WHERE iv.id IN ($in)
             GROUP BY iv.id
         ) i ON i.venue_id = v.id
         WHERE v.id IN ($in)
         ORDER BY order_total DESC, v.id ASC",
        array_merge($breakdownParams, $venueIds)
    );

    respond(0, 'ok', [
        'is_all' => true,
        'venue_count' => count($venueIds),
        'venue_id' => null,
        'venue_name' => '全部场地汇总',
        'venue_level' => 'MULTI',
        'withdraw_ratio' => null,
        'orderCount' => (int)($orderRow['order_count'] ?? 0),
        'reservationCount' => 0,
        'queue_length' => 0,
        'totalPayment' => number_format($devicePayment + $giftPayment, 2, '.', ''),
        'totalPaymentBeforeDeduction' => number_format($devicePaymentBeforeDeduction + $giftPayment, 2, '.', ''),
        'promotionDeduction' => number_format($promotionDeduction, 2, '.', ''),
        'promotionIncome' => number_format($promotionIncome, 2, '.', ''),
        'devicePaymentBeforeDeduction' => number_format($devicePaymentBeforeDeduction, 2, '.', ''),
        'devicePayment' => number_format($devicePayment, 2, '.', ''),
        'giftPayment' => number_format($giftPayment, 2, '.', ''),
        'todayInviteUsers' => $todayInviteUsers,
        'venues' => array_map(function ($row) use ($user) {
            $orderTotal = (float)$row['order_total'];
            $deviceIncomeBeforeDeduction = (int)$user['role_id'] === 4
                ? $orderTotal * 0.8
                : $orderTotal;
            $promotionDeduction = (float)$row['promotion_deduction'];
            $giftPayment = ((float)$row['gift_total'] / 10) * 0.6;
            $estimatedIncome = $deviceIncomeBeforeDeduction - $promotionDeduction + $giftPayment;

            return [
                'venue_id' => (int)$row['venue_id'],
                'venue_name' => $row['venue_name'],
                'order_count' => (int)$row['order_count'],
                'order_total' => number_format($orderTotal, 2, '.', ''),
                'promotion_deduction' => number_format($promotionDeduction, 2, '.', ''),
                'gift_payment' => number_format($giftPayment, 2, '.', ''),
                'estimated_income' => number_format($estimatedIncome, 2, '.', ''),
                'invite_count' => (int)$row['invite_count'],
            ];
        }, $breakdownRows ?: []),
    ]);
} catch (Throwable $e) {
    error_log('MultiVenueSummary error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}

