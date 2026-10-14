<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function validDate($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function money($value) {
    return number_format((float)$value, 2, '.', '');
}

function percent($numerator, $denominator) {
    return $denominator > 0 ? round(((float)$numerator / (float)$denominator) * 100, 1) : 0.0;
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        respond(1001, '用户未登录或无权访问');
    }

    $venueId = filter_var($_GET['venue_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$venueId || $venueId <= 0) {
        respond(400, '场地参数无效');
    }

    $roleId = (int)$user['role_id'];
    $isMcn = !empty($user['is_mcn']) && !empty($user['mcn_id']);
    if ($isMcn) {
        $authorized = $database->query(
            "SELECT 1 FROM mcn_venues
             WHERE mcn_id = ? AND venue_id = ? AND status = 1
             LIMIT 1",
            [(int)$user['mcn_id'], $venueId]
        );
        if (!$authorized) {
            respond(1003, '无权查看该场地数据');
        }
    } elseif (!in_array($roleId, [1, 2], true) && (int)($user['venue_id'] ?? 0) !== $venueId) {
        respond(1003, '无权查看该场地数据');
    }

    $venueRows = $database->query(
        "SELECT id, venue_name, invite_code FROM venues WHERE id = ? LIMIT 1",
        [$venueId]
    );
    if (!$venueRows) {
        respond(404, '场地不存在');
    }
    $venue = $venueRows[0];
    $inviteCode = trim((string)($venue['invite_code'] ?? ''));

    $defaultEnd = date('Y-m-d');
    $defaultStart = date('Y-m-d', strtotime('-6 days'));
    $startDate = $_GET['start_date'] ?? $defaultStart;
    $endDate = $_GET['end_date'] ?? $defaultEnd;
    if (!validDate($startDate) || !validDate($endDate)) {
        respond(400, '日期格式应为 YYYY-MM-DD');
    }

    $startObject = new DateTime($startDate);
    $endObject = new DateTime($endDate);
    if ($startObject > $endObject) {
        respond(400, '开始日期不能大于结束日期');
    }
    $days = (int)$startObject->diff($endObject)->days + 1;
    if ($days > 90) {
        respond(400, '单次最多查询90天');
    }

    $startTime = $startDate . ' 00:00:00';
    $endTime = $endDate . ' 23:59:59';

    $cohort = [
        'registrations' => 0,
        'paid_users' => 0,
        'repeat_users' => 0,
        'order_count' => 0,
        'payment_amount' => 0,
        'avg_first_order_minutes' => null,
    ];
    if ($inviteCode !== '') {
        $cohortRows = $database->query(
            "SELECT COUNT(*) AS registrations,
                    COALESCE(SUM(CASE WHEN x.order_count > 0 THEN 1 ELSE 0 END), 0) AS paid_users,
                    COALESCE(SUM(CASE WHEN x.order_count >= 2 THEN 1 ELSE 0 END), 0) AS repeat_users,
                    COALESCE(SUM(x.order_count), 0) AS order_count,
                    COALESCE(SUM(x.payment_amount), 0) AS payment_amount,
                    AVG(CASE WHEN x.first_order_time IS NOT NULL
                        THEN TIMESTAMPDIFF(MINUTE, x.created_at, x.first_order_time)
                        ELSE NULL END) AS avg_first_order_minutes
             FROM (
                 SELECT u.uid, u.created_at,
                        COUNT(o.order_id) AS order_count,
                        COALESCE(SUM(o.payment_amount), 0) AS payment_amount,
                        MIN(o.end_time) AS first_order_time
                 FROM users u
                 LEFT JOIN orders o
                        ON o.uid = u.uid
                       AND o.end_time >= u.created_at
                       AND o.end_time <= ?
                       AND o.status = '已完成'
                       AND o.pays_type != '能量'
                       AND (o.note <> 'gift' OR o.note IS NULL)
                 WHERE u.invitation_code = ?
                   AND u.created_at >= ? AND u.created_at <= ?
                 GROUP BY u.uid, u.created_at
             ) x",
            [$endTime, $inviteCode, $startTime, $endTime]
        );
        if ($cohortRows) {
            $cohort = array_merge($cohort, $cohortRows[0]);
        }
    }

    $registrations = (int)$cohort['registrations'];
    $paidUsers = (int)$cohort['paid_users'];
    $repeatUsers = (int)$cohort['repeat_users'];

    $referralPeriod = [
        'active_users' => 0,
        'order_count' => 0,
        'total_amount' => 0,
        'local_amount' => 0,
        'external_amount' => 0,
    ];
    if ($inviteCode !== '') {
        $referralRows = $database->query(
            "SELECT COUNT(DISTINCT o.uid) AS active_users,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.payment_amount), 0) AS total_amount,
                    COALESCE(SUM(CASE WHEN o.reservation_id = ? THEN o.payment_amount ELSE 0 END), 0) AS local_amount,
                    COALESCE(SUM(CASE WHEN o.reservation_id <> ? THEN o.payment_amount ELSE 0 END), 0) AS external_amount
             FROM orders o
             INNER JOIN users u ON u.uid = o.uid
             WHERE u.invitation_code = ?
               AND o.end_time >= ? AND o.end_time <= ?
               AND o.status = '已完成'
               AND o.pays_type != '能量'
               AND (o.note <> 'gift' OR o.note IS NULL)",
            [$venueId, $venueId, $inviteCode, $startTime, $endTime]
        );
        if ($referralRows) {
            $referralPeriod = array_merge($referralPeriod, $referralRows[0]);
        }
    }

    $reservationRows = $database->query(
        "SELECT COUNT(*) AS reservation_count,
                COALESCE(SUM(CASE WHEN order_status = '已完成' THEN 1 ELSE 0 END), 0) AS completed_count,
                COALESCE(SUM(CASE WHEN order_status = '已取消' THEN 1 ELSE 0 END), 0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN order_status = '等待驾驶' THEN 1 ELSE 0 END), 0) AS waiting_count
         FROM Reservations
         WHERE reservation_id = ?
           AND reservation_time >= ? AND reservation_time <= ?",
        [$venueId, $startTime, $endTime]
    );
    $reservations = $reservationRows[0] ?? [
        'reservation_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'waiting_count' => 0,
    ];

    $venueOrderRows = $database->query(
        "SELECT COUNT(*) AS order_count,
                COUNT(DISTINCT uid) AS user_count,
                COALESCE(SUM(payment_amount), 0) AS payment_amount
         FROM orders
         WHERE reservation_id = ?
           AND end_time >= ? AND end_time <= ?
           AND status = '已完成'
           AND pays_type != '能量'
           AND (note <> 'gift' OR note IS NULL)",
        [$venueId, $startTime, $endTime]
    );
    $venueOrders = $venueOrderRows[0] ?? ['order_count' => 0, 'user_count' => 0, 'payment_amount' => 0];

    $packageRows = $database->query(
        "SELECT COALESCE(driving_duration, 0) AS minutes,
                COALESCE(NULLIF(TRIM(pay_money), ''), '0') AS price,
                COUNT(*) AS reservation_count,
                COALESCE(SUM(CASE WHEN order_status = '已完成' THEN 1 ELSE 0 END), 0) AS completed_count,
                COALESCE(SUM(CASE WHEN order_status = '已取消' THEN 1 ELSE 0 END), 0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN order_status = '等待驾驶' THEN 1 ELSE 0 END), 0) AS waiting_count
         FROM Reservations
         WHERE reservation_id = ?
           AND reservation_time >= ? AND reservation_time <= ?
         GROUP BY driving_duration, pay_money
         ORDER BY reservation_count DESC, minutes ASC",
        [$venueId, $startTime, $endTime]
    ) ?: [];

    $packages = array_map(function ($row) {
        $total = (int)$row['reservation_count'];
        $completed = (int)$row['completed_count'];
        return [
            'minutes' => (int)$row['minutes'],
            'price' => money($row['price']),
            'reservation_count' => $total,
            'completed_count' => $completed,
            'cancelled_count' => (int)$row['cancelled_count'],
            'waiting_count' => (int)$row['waiting_count'],
            'completion_rate' => percent($completed, $total),
        ];
    }, $packageRows);

    $currentPackages = $database->query(
        "SELECT ID, Title, Minutes, Battery, PricingType
         FROM PricingOptions
         WHERE BindLocation = ?
         ORDER BY Battery ASC, Minutes ASC",
        [(string)$venueId]
    ) ?: [];

    $destinationRows = [];
    if ($inviteCode !== '') {
        $destinationRows = $database->query(
            "SELECT o.reservation_id AS venue_id,
                    COALESCE(v.venue_name, CONCAT('场地', o.reservation_id)) AS venue_name,
                    COUNT(*) AS order_count,
                    COUNT(DISTINCT o.uid) AS user_count,
                    COALESCE(SUM(o.payment_amount), 0) AS payment_amount
             FROM orders o
             INNER JOIN users u ON u.uid = o.uid
             LEFT JOIN venues v ON v.id = o.reservation_id
             WHERE u.invitation_code = ?
               AND o.end_time >= ? AND o.end_time <= ?
               AND o.status = '已完成'
               AND o.pays_type != '能量'
               AND (o.note <> 'gift' OR o.note IS NULL)
               AND o.reservation_id IS NOT NULL
             GROUP BY o.reservation_id, v.venue_name
             ORDER BY payment_amount DESC
             LIMIT 10",
            [$inviteCode, $startTime, $endTime]
        ) ?: [];
    }

    $registrationTrend = [];
    $referralTrend = [];
    if ($inviteCode !== '') {
        $registrationTrend = $database->query(
            "SELECT DATE(created_at) AS day, COUNT(*) AS registrations
             FROM users
             WHERE invitation_code = ?
               AND created_at >= ? AND created_at <= ?
             GROUP BY DATE(created_at)",
            [$inviteCode, $startTime, $endTime]
        ) ?: [];
        $referralTrend = $database->query(
            "SELECT DATE(o.end_time) AS day, COALESCE(SUM(o.payment_amount), 0) AS referral_amount
             FROM orders o
             INNER JOIN users u ON u.uid = o.uid
             WHERE u.invitation_code = ?
               AND o.end_time >= ? AND o.end_time <= ?
               AND o.status = '已完成'
               AND o.pays_type != '能量'
               AND (o.note <> 'gift' OR o.note IS NULL)
             GROUP BY DATE(o.end_time)",
            [$inviteCode, $startTime, $endTime]
        ) ?: [];
    }
    $venueTrend = $database->query(
        "SELECT DATE(end_time) AS day,
                COUNT(*) AS order_count,
                COALESCE(SUM(payment_amount), 0) AS venue_amount
         FROM orders
         WHERE reservation_id = ?
           AND end_time >= ? AND end_time <= ?
           AND status = '已完成'
           AND pays_type != '能量'
           AND (note <> 'gift' OR note IS NULL)
         GROUP BY DATE(end_time)",
        [$venueId, $startTime, $endTime]
    ) ?: [];

    $trendMap = [];
    $cursor = clone $startObject;
    while ($cursor <= $endObject) {
        $day = $cursor->format('Y-m-d');
        $trendMap[$day] = [
            'day' => $day,
            'registrations' => 0,
            'order_count' => 0,
            'venue_amount' => '0.00',
            'referral_amount' => '0.00',
        ];
        $cursor->modify('+1 day');
    }
    foreach ($registrationTrend as $row) {
        if (isset($trendMap[$row['day']])) {
            $trendMap[$row['day']]['registrations'] = (int)$row['registrations'];
        }
    }
    foreach ($venueTrend as $row) {
        if (isset($trendMap[$row['day']])) {
            $trendMap[$row['day']]['order_count'] = (int)$row['order_count'];
            $trendMap[$row['day']]['venue_amount'] = money($row['venue_amount']);
        }
    }
    foreach ($referralTrend as $row) {
        if (isset($trendMap[$row['day']])) {
            $trendMap[$row['day']]['referral_amount'] = money($row['referral_amount']);
        }
    }

    $referralTotal = (float)$referralPeriod['total_amount'];
    $externalAmount = (float)$referralPeriod['external_amount'];
    $reservationCount = (int)$reservations['reservation_count'];
    $completedCount = (int)$reservations['completed_count'];
    $venueOrderCount = (int)$venueOrders['order_count'];
    $venuePayment = (float)$venueOrders['payment_amount'];

    respond(0, 'ok', [
        'venue' => [
            'id' => (int)$venue['id'],
            'name' => $venue['venue_name'],
            'invite_code' => $inviteCode,
        ],
        'range' => ['start_date' => $startDate, 'end_date' => $endDate, 'days' => $days],
        'cohort' => [
            'registrations' => $registrations,
            'paid_users' => $paidUsers,
            'conversion_rate' => percent($paidUsers, $registrations),
            'repeat_users' => $repeatUsers,
            'repeat_rate' => percent($repeatUsers, $paidUsers),
            'order_count' => (int)$cohort['order_count'],
            'payment_amount' => money($cohort['payment_amount']),
            'avg_first_order_hours' => $cohort['avg_first_order_minutes'] === null
                ? null
                : round((float)$cohort['avg_first_order_minutes'] / 60, 1),
        ],
        'referral_period' => [
            'active_users' => (int)$referralPeriod['active_users'],
            'order_count' => (int)$referralPeriod['order_count'],
            'total_amount' => money($referralTotal),
            'local_amount' => money($referralPeriod['local_amount']),
            'external_amount' => money($externalAmount),
            'outflow_rate' => percent($externalAmount, $referralTotal),
            'estimated_reward' => money($externalAmount * 0.10),
        ],
        'venue_period' => [
            'reservation_count' => $reservationCount,
            'completed_count' => $completedCount,
            'cancelled_count' => (int)$reservations['cancelled_count'],
            'waiting_count' => (int)$reservations['waiting_count'],
            'completion_rate' => percent($completedCount, $reservationCount),
            'order_count' => $venueOrderCount,
            'user_count' => (int)$venueOrders['user_count'],
            'payment_amount' => money($venuePayment),
            'average_order_value' => money($venueOrderCount > 0 ? $venuePayment / $venueOrderCount : 0),
        ],
        'packages' => $packages,
        'current_packages' => array_map(function ($row) {
            return [
                'id' => (int)$row['ID'],
                'title' => $row['Title'],
                'minutes' => (int)$row['Minutes'],
                'battery' => money($row['Battery']),
                'pricing_type' => $row['PricingType'],
            ];
        }, $currentPackages),
        'destinations' => array_map(function ($row) use ($venueId) {
            return [
                'venue_id' => (int)$row['venue_id'],
                'venue_name' => $row['venue_name'],
                'is_local' => (int)$row['venue_id'] === $venueId,
                'order_count' => (int)$row['order_count'],
                'user_count' => (int)$row['user_count'],
                'payment_amount' => money($row['payment_amount']),
            ];
        }, $destinationRows),
        'trend' => array_values($trendMap),
    ]);
} catch (Throwable $e) {
    error_log('venue_data_analysis error: ' . $e->getMessage());
    respond(500, '数据分析加载失败');
} finally {
    $database->close();
}

