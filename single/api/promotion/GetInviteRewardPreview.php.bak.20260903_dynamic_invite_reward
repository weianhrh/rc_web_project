<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = []) {
    echo json_encode(
        ['code' => $code, 'msg' => $msg, 'data' => $data],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function isValidDateYmd($value) {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (!$sessionToken) {
        jsonOut(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        jsonOut(1001, '用户未登录或无权访问');
    }

    // 推广收益按当前后台账号绑定的场地归属，与首页 DailySummaryStatsv2.php 保持一致。
    $venueId = (int)($user['venue_id'] ?? 0);
    if ($venueId <= 0) {
        jsonOut(1002, '当前账号未绑定有效场地');
    }

    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!isValidDateYmd($date)) {
        jsonOut(400, '日期格式不正确');
    }

    $startAt = $date . ' 00:00:00';
    $endAt = $date . ' 23:59:59';

    $venueRows = $database->query(
        "SELECT venue_name, invite_code FROM venues WHERE id = ? LIMIT 1",
        [$venueId]
    );
    if ($venueRows === false || count($venueRows) === 0) {
        jsonOut(1003, '当前绑定场地不存在');
    }

    $venueName = (string)($venueRows[0]['venue_name'] ?? '');
    $venueInviteCode = (string)($venueRows[0]['invite_code'] ?? '');

    // recorded_at 是推广收益实际生成时间；invitation_venue_id 是收益归属场地。
    // 这两个筛选条件与首页推广收益卡片完全一致。
    $sql = "SELECT
                pos.id,
                pos.order_id,
                pos.reservation_id,
                pos.uid,
                pos.payment_amount,
                pos.promotion_amount,
                pos.start_time,
                pos.end_time,
                pos.invitation_code,
                pos.invitation_venue_id,
                pos.recorded_at,
                u.nickname AS invitee_nickname,
                consumer_venue.venue_name AS consumer_venue_name
            FROM promotion_order_statistics pos
            LEFT JOIN users u
                   ON u.uid = pos.uid
            LEFT JOIN venues consumer_venue
                   ON consumer_venue.id = pos.reservation_id
            WHERE pos.invitation_venue_id = ?
              AND pos.recorded_at >= ?
              AND pos.recorded_at <= ?
            ORDER BY pos.recorded_at DESC, pos.id DESC";

    $rows = $database->query($sql, [$venueId, $startAt, $endAt]);
    if ($rows === false) {
        jsonOut(500, '推广收益流水查询失败');
    }

    $totalPaymentAmount = 0.0;
    $totalPromotionAmount = 0.0;
    $list = [];

    foreach ($rows as $row) {
        $paymentAmount = (float)($row['payment_amount'] ?? 0);
        $promotionAmount = (float)($row['promotion_amount'] ?? 0);
        $rewardRatePercent = $paymentAmount > 0
            ? round(($promotionAmount / $paymentAmount) * 100, 2)
            : 0.0;

        $totalPaymentAmount += $paymentAmount;
        $totalPromotionAmount += $promotionAmount;

        $list[] = [
            'id' => (int)$row['id'],
            'order_id' => (string)$row['order_id'],
            'venue_id' => (int)$row['reservation_id'],
            'consumer_venue_name' => (string)($row['consumer_venue_name'] ?? ''),
            'invitee_uid' => (int)$row['uid'],
            'invitee_nickname' => (string)($row['invitee_nickname'] ?? ''),
            'order_amount' => number_format($paymentAmount, 2, '.', ''),
            'reward_amount' => number_format($promotionAmount, 2, '.', ''),
            'reward_rate_percent' => $rewardRatePercent,
            'invitation_code' => (string)($row['invitation_code'] ?? ''),
            'invitation_venue_id' => (int)$row['invitation_venue_id'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'recorded_at' => $row['recorded_at'],

            // 兼容旧页面字段，防止前端缓存未及时更新时显示异常。
            'estimated_reward_amount' => number_format($promotionAmount, 2, '.', ''),
            'generated_status' => 'settled',
            'pays_type' => '',
        ];
    }

    $overallRatePercent = $totalPaymentAmount > 0
        ? round(($totalPromotionAmount / $totalPaymentAmount) * 100, 2)
        : 0.0;

    jsonOut(200, 'ok', [
        'date' => $date,
        'venue_id' => $venueId,
        'venue_name' => $venueName,
        'invite_code' => $venueInviteCode,
        'summary' => [
            'settled_reward_amount' => round($totalPromotionAmount, 2),
            'settled_order_count' => count($list),
            'settled_order_amount' => round($totalPaymentAmount, 2),
            'reward_rate_percent' => $overallRatePercent,

            // 兼容旧页面字段。
            'estimated_reward_amount' => round($totalPromotionAmount, 2),
            'valid_order_count' => count($list),
            'valid_order_amount' => round($totalPaymentAmount, 2),
        ],
        'list' => $list,
    ]);
} catch (Throwable $e) {
    error_log('GetInviteRewardPreview error: ' . $e->getMessage());
    jsonOut(500, '服务器内部错误');
} finally {
    $database->close();
}
