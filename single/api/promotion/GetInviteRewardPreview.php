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

function rewardRatePercent($amount, $reward) {
    $amount = (float)$amount;
    if ($amount <= 0) {
        return 0.0;
    }
    return round(((float)$reward / $amount) * 100, 2);
}

function buildRewardList($rows) {
    $orderAmountTotal = 0.0;
    $rewardAmountTotal = 0.0;
    $list = [];

    foreach ($rows as $row) {
        $orderAmount = (float)($row['order_amount'] ?? 0);
        $rewardAmount = (float)($row['reward_amount'] ?? 0);
        $orderAmountTotal += $orderAmount;
        $rewardAmountTotal += $rewardAmount;
        $list[] = [
            'id' => (int)$row['id'],
            'order_id' => (string)$row['order_id'],
            'reward_date' => (string)$row['reward_date'],
            'invitee_uid' => (int)$row['invitee_uid'],
            'invitee_nickname' => (string)($row['invitee_nickname'] ?? ''),
            'invitation_code' => (string)$row['invite_code'],
            'order_amount' => number_format($orderAmount, 2, '.', ''),
            'reward_amount' => number_format($rewardAmount, 2, '.', ''),
            'reward_rate_percent' => rewardRatePercent($orderAmount, $rewardAmount),
            'generated_status' => (string)$row['reward_status'],
            'recorded_at' => (string)$row['generated_at'],
            'settled_at' => (string)($row['settled_at'] ?? ''),
            'settlement_batch_id' => (string)($row['settlement_batch_id'] ?? ''),
        ];
    }

    return [
        'summary' => [
            'reward_amount' => round($rewardAmountTotal, 2),
            'order_count' => count($list),
            'order_amount' => round($orderAmountTotal, 2),
            'reward_rate_percent' => rewardRatePercent($orderAmountTotal, $rewardAmountTotal),
        ],
        'list' => $list,
    ];
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
    $today = date('Y-m-d');
    $mode = trim($_GET['mode'] ?? 'summary');
    $detailType = trim($_GET['detail_type'] ?? '');
    $detailDate = trim($_GET['detail_date'] ?? $date);
    if ($mode === 'detail') {
        if (!in_array($detailType, ['dynamic', 'pending', 'settled'], true)) {
            jsonOut(400, '明细类型不正确');
        }
        if (!isValidDateYmd($detailDate)) {
            jsonOut(400, '明细日期格式不正确');
        }
        $date = $detailDate;
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

    if ($mode === 'detail' && in_array($detailType, ['pending', 'settled'], true)) {
        $rewardDetailSql = "SELECT
                    vpr.id,
                    vpr.order_id,
                    vpr.reward_date,
                    vpr.invite_code,
                    vpr.promotion_venue_id,
                    vpr.consumer_venue_id,
                    vpr.invitee_uid,
                    vpr.order_amount,
                    vpr.reward_rate,
                    vpr.reward_amount,
                    vpr.reward_status,
                    vpr.generated_at,
                    vpr.settled_at,
                    vpr.settlement_batch_id,
                    u.nickname AS invitee_nickname
                FROM venue_promotion_reward_logs vpr
                LEFT JOIN users u
                       ON u.uid = vpr.invitee_uid
                WHERE vpr.promotion_venue_id = ?
                  AND vpr.reward_status = ?
                  AND vpr.reward_date = ?
                ORDER BY vpr.reward_date DESC, vpr.generated_at DESC, vpr.id DESC
                LIMIT 2000";
        $rewardDetailRows = $database->query($rewardDetailSql, [$venueId, $detailType, $detailDate]);
        if ($rewardDetailRows === false) {
            jsonOut(500, '推广收益明细查询失败');
        }
        $rewardDetail = buildRewardList($rewardDetailRows);
        jsonOut(200, 'ok', [
            'mode' => 'detail',
            'detail_type' => $detailType,
            'date' => $detailDate,
            'venue_id' => $venueId,
            'venue_name' => $venueName,
            'invite_code' => $venueInviteCode,
            'summary' => $rewardDetail['summary'],
            'list' => $rewardDetail['list'],
        ]);
    }

    // 动态预估口径：
    // 当前场地邀请码推广来的历史用户，所选日期在其他场地完成的真实消费，
    // 按 orders.payment_amount * 10% 预估推广收益，排除能量/礼物订单。
    $dynamicSql = "SELECT
                o.order_id,
                o.reservation_id,
                o.uid,
                o.payment_amount,
                o.start_time,
                o.end_time,
                u.invitation_code,
                u.nickname AS invitee_nickname,
                consumer_venue.venue_name AS consumer_venue_name
            FROM orders o
            INNER JOIN users u
                   ON u.uid = o.uid
            LEFT JOIN venues consumer_venue
                   ON consumer_venue.id = o.reservation_id
            WHERE u.invitation_code = ?
              AND o.reservation_id IS NOT NULL
              AND o.reservation_id > 0
              AND o.reservation_id <> ?
              AND o.status = '已完成'
              AND o.end_time >= ?
              AND o.end_time <= ?
              AND COALESCE(o.payment_amount, 0) > 0
              AND (o.pays_type IS NULL OR o.pays_type <> '能量')
              AND (o.note IS NULL OR o.note <> 'gift')
            ORDER BY o.end_time DESC, o.order_id DESC";

    $dynamicRows = $database->query($dynamicSql, [$venueInviteCode, $venueId, $startAt, $endAt]);
    if ($dynamicRows === false) {
        jsonOut(500, '推广收益动态预估查询失败');
    }

    $dynamicOrderAmount = 0.0;
    $dynamicRewardAmount = 0.0;
    $dynamicList = [];
    $rewardRate = 0.10;

    foreach ($dynamicRows as $row) {
        $paymentAmount = (float)($row['payment_amount'] ?? 0);
        $promotionAmount = round($paymentAmount * $rewardRate, 2);
        $rewardRatePercent = 10.0;

        $dynamicOrderAmount += $paymentAmount;
        $dynamicRewardAmount += $promotionAmount;

        $dynamicList[] = [
            'order_id' => (string)$row['order_id'],
            'venue_id' => (int)$row['reservation_id'],
            'consumer_venue_name' => (string)($row['consumer_venue_name'] ?? ''),
            'invitee_uid' => (int)$row['uid'],
            'invitee_nickname' => (string)($row['invitee_nickname'] ?? ''),
            'order_amount' => number_format($paymentAmount, 2, '.', ''),
            'reward_amount' => number_format($promotionAmount, 2, '.', ''),
            'reward_rate_percent' => $rewardRatePercent,
            'invitation_code' => (string)($row['invitation_code'] ?? ''),
            'invitation_venue_id' => $venueId,
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'recorded_at' => date('Y-m-d H:i:s'),

            // 兼容旧页面字段，防止前端缓存未及时更新时显示异常。
            'estimated_reward_amount' => number_format($promotionAmount, 2, '.', ''),
            'generated_status' => 'dynamic_preview',
            'pays_type' => '',
        ];
    }

    $dynamicRatePercent = rewardRatePercent($dynamicOrderAmount, $dynamicRewardAmount);

    if ($mode === 'detail' && $detailType === 'dynamic') {
        jsonOut(200, 'ok', [
            'mode' => 'detail',
            'detail_type' => 'dynamic',
            'date' => $detailDate,
            'venue_id' => $venueId,
            'venue_name' => $venueName,
            'invite_code' => $venueInviteCode,
            'summary' => [
                'reward_amount' => round($dynamicRewardAmount, 2),
                'order_count' => count($dynamicList),
                'order_amount' => round($dynamicOrderAmount, 2),
                'reward_rate_percent' => $dynamicRatePercent,
            ],
            'list' => $dynamicList,
        ]);
    }

    $pendingSql = "SELECT
                vpr.id,
                vpr.order_id,
                vpr.reward_date,
                vpr.invite_code,
                vpr.promotion_venue_id,
                vpr.consumer_venue_id,
                vpr.invitee_uid,
                vpr.order_amount,
                vpr.reward_rate,
                vpr.reward_amount,
                vpr.reward_status,
                vpr.generated_at,
                u.nickname AS invitee_nickname
            FROM venue_promotion_reward_logs vpr
            LEFT JOIN users u
                   ON u.uid = vpr.invitee_uid
            WHERE vpr.promotion_venue_id = ?
              AND vpr.reward_status = 'pending'
              AND vpr.reward_date < ?
            ORDER BY vpr.reward_date DESC, vpr.generated_at DESC, vpr.id DESC
            LIMIT 500";
    $pendingRows = $database->query($pendingSql, [$venueId, $today]);
    if ($pendingRows === false) {
        jsonOut(500, '历史未结算推广收益查询失败');
    }

    $pendingHistory = buildRewardList($pendingRows);
    $pendingSummaryRows = $database->query(
        "SELECT
            COUNT(*) AS order_count,
            ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
            ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount
         FROM venue_promotion_reward_logs
         WHERE promotion_venue_id = ?
           AND reward_status = 'pending'
           AND reward_date < ?",
        [$venueId, $today]
    );
    if ($pendingSummaryRows === false || count($pendingSummaryRows) === 0) {
        jsonOut(500, '历史未结算推广收益汇总查询失败');
    }
    $pendingOrderCount = (int)$pendingSummaryRows[0]['order_count'];
    $pendingOrderAmount = (float)$pendingSummaryRows[0]['order_amount'];
    $pendingRewardAmount = (float)$pendingSummaryRows[0]['reward_amount'];
    $pendingList = $pendingHistory['list'];

    $pendingByDateRows = $database->query(
        "SELECT
            reward_date,
            COUNT(*) AS order_count,
            ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
            ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount
         FROM venue_promotion_reward_logs
         WHERE promotion_venue_id = ?
           AND reward_status = 'pending'
           AND reward_date < ?
         GROUP BY reward_date
         ORDER BY reward_date DESC
         LIMIT 90",
        [$venueId, $today]
    );
    if ($pendingByDateRows === false) {
        $pendingByDateRows = [];
    }

    $settledSql = "SELECT
                vpr.id,
                vpr.order_id,
                vpr.reward_date,
                vpr.invite_code,
                vpr.promotion_venue_id,
                vpr.consumer_venue_id,
                vpr.invitee_uid,
                vpr.order_amount,
                vpr.reward_rate,
                vpr.reward_amount,
                vpr.reward_status,
                vpr.generated_at,
                vpr.settled_at,
                vpr.settlement_batch_id,
                u.nickname AS invitee_nickname
            FROM venue_promotion_reward_logs vpr
            LEFT JOIN users u
                   ON u.uid = vpr.invitee_uid
            WHERE vpr.promotion_venue_id = ?
              AND vpr.reward_status = 'settled'
            ORDER BY COALESCE(vpr.settled_at, vpr.updated_at) DESC, vpr.reward_date DESC, vpr.id DESC
            LIMIT 500";
    $settledRows = $database->query($settledSql, [$venueId]);
    if ($settledRows === false) {
        jsonOut(500, '已结算推广收益查询失败');
    }

    $settledHistory = buildRewardList($settledRows);
    $settledSummaryRows = $database->query(
        "SELECT
            COUNT(*) AS order_count,
            ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
            ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount
         FROM venue_promotion_reward_logs
         WHERE promotion_venue_id = ?
           AND reward_status = 'settled'",
        [$venueId]
    );
    if ($settledSummaryRows === false || count($settledSummaryRows) === 0) {
        jsonOut(500, '已结算推广收益汇总查询失败');
    }
    $settledOrderCount = (int)$settledSummaryRows[0]['order_count'];
    $settledOrderAmount = (float)$settledSummaryRows[0]['order_amount'];
    $settledRewardAmount = (float)$settledSummaryRows[0]['reward_amount'];
    $settledList = $settledHistory['list'];

    $settledByDateRows = $database->query(
        "SELECT
            reward_date,
            COUNT(*) AS order_count,
            ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
            ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount,
            MIN(settled_at) AS first_settled_at,
            MAX(settled_at) AS last_settled_at,
            GROUP_CONCAT(DISTINCT settlement_batch_id ORDER BY settlement_batch_id SEPARATOR ',') AS settlement_batch_ids
         FROM venue_promotion_reward_logs
         WHERE promotion_venue_id = ?
           AND reward_status = 'settled'
         GROUP BY reward_date
         ORDER BY MAX(COALESCE(settled_at, updated_at)) DESC, reward_date DESC
         LIMIT 90",
        [$venueId]
    );
    if ($settledByDateRows === false) {
        $settledByDateRows = [];
    }

    jsonOut(200, 'ok', [
        'date' => $date,
        'venue_id' => $venueId,
        'venue_name' => $venueName,
        'invite_code' => $venueInviteCode,
        'today_preview' => [
            'summary' => [
                'reward_amount' => round($dynamicRewardAmount, 2),
                'order_count' => count($dynamicList),
                'order_amount' => round($dynamicOrderAmount, 2),
                'reward_rate_percent' => $dynamicRatePercent,
            ],
            'list' => $dynamicList,
        ],
        'pending_history' => [
            'summary' => [
                'reward_amount' => round($pendingRewardAmount, 2),
                'order_count' => $pendingOrderCount,
                'order_amount' => round($pendingOrderAmount, 2),
                'reward_rate_percent' => rewardRatePercent($pendingOrderAmount, $pendingRewardAmount),
            ],
            'by_date' => $pendingByDateRows,
            'list' => $pendingList,
        ],
        'settled_history' => [
            'summary' => [
                'reward_amount' => round($settledRewardAmount, 2),
                'order_count' => $settledOrderCount,
                'order_amount' => round($settledOrderAmount, 2),
                'reward_rate_percent' => rewardRatePercent($settledOrderAmount, $settledRewardAmount),
            ],
            'by_date' => $settledByDateRows,
            'list' => $settledList,
        ],
        'summary' => [
            'settled_reward_amount' => round($dynamicRewardAmount, 2),
            'settled_order_count' => count($dynamicList),
            'settled_order_amount' => round($dynamicOrderAmount, 2),
            'reward_rate_percent' => $dynamicRatePercent,
            'dynamic_reward_rate' => $rewardRate,
            'dynamic_reward_rate_percent' => 10,

            // 兼容旧页面字段。
            'estimated_reward_amount' => round($dynamicRewardAmount, 2),
            'valid_order_count' => count($dynamicList),
            'valid_order_amount' => round($dynamicOrderAmount, 2),
        ],
        'list' => $dynamicList,
    ]);
} catch (Throwable $e) {
    error_log('GetInviteRewardPreview error: ' . $e->getMessage());
    jsonOut(500, '服务器内部错误');
} finally {
    $database->close();
}
