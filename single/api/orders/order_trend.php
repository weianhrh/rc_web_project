<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonOut($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeRange($value) {
    $range = (int)$value;
    return in_array($range, [5, 10, 30, 180], true) ? $range : 180;
}

function normalizeStatus($value) {
    $value = trim((string)$value);
    return in_array($value, ['driving', 'completed', 'active', 'all'], true) ? $value : 'driving';
}

function bucketSecondsByRange($range) {
    if ($range <= 10) {
        return 60;
    }
    if ($range <= 30) {
        return 300;
    }
    return 600;
}

function statusWhereSql($status) {
    if ($status === 'completed') {
        return " AND o.status = '已完成'";
    }
    if ($status === 'active') {
        return " AND o.status = '正在驾驶'";
    }
    return '';
}

function bindParams($stmt, $types, &$params) {
    if (empty($params)) {
        return;
    }
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetchAll($connection, $sql, $types = '', $params = []) {
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL预处理失败：' . $connection->error);
    }
    if ($types !== '') {
        bindParams($stmt, $types, $params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('SQL执行失败：' . $error);
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        jsonOut(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        jsonOut(1001, '用户未登录或无权访问');
    }

    $roleId = (int)$user['role_id'];
    $isAdmin = in_array($roleId, [1, 2], true);
    $userVenueId = (int)($user['venue_id'] ?? 0);

    $range = normalizeRange($_GET['range'] ?? 180);
    $status = normalizeStatus($_GET['status'] ?? 'driving');
    $bucketSeconds = bucketSecondsByRange($range);
    $now = date('Y-m-d H:i:s');
    $windowStart = date('Y-m-d H:i:s', time() - 10800);

    $connection = $database->getConnection();
    $connection->set_charset('utf8mb4');

    $baseWhere = "
        WHERE o.start_time >= ?
          AND o.start_time <= ?
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND (o.pays_type IS NULL OR o.pays_type <> '能量')
          AND (o.note IS NULL OR o.note <> 'gift')
    ";
    $baseTypes = 'ss';
    $baseParams = [$windowStart, $now];

    if (!$isAdmin) {
        $baseWhere .= " AND o.reservation_id = ?";
        $baseTypes .= 'i';
        $baseParams[] = $userVenueId;
    }

    $filteredWhere = $baseWhere . statusWhereSql($status);

    $venueSql = "
        SELECT
            o.reservation_id AS venue_id,
            COALESCE(v.venue_name, CONCAT('场地', o.reservation_id)) AS venue_name,
            SUM(CASE WHEN o.start_time >= DATE_SUB(?, INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS m5,
            SUM(CASE WHEN o.start_time >= DATE_SUB(?, INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) AS m10,
            SUM(CASE WHEN o.start_time >= DATE_SUB(?, INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) AS m30,
            COUNT(*) AS h3,
            COUNT(DISTINCT o.uid) AS users_h3,
            COALESCE(SUM(COALESCE(o.checkout_amount, o.payment_amount, 0)), 0) AS amount_h3,
            COALESCE(SUM(CASE WHEN o.start_time >= DATE_SUB(?, INTERVAL 30 MINUTE)
                              THEN COALESCE(o.checkout_amount, o.payment_amount, 0)
                              ELSE 0 END), 0) AS amount_30,
            SUM(CASE WHEN o.status = '正在驾驶' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN o.status = '已完成' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN o.start_time >= DATE_SUB(?, INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) AS current_30,
            SUM(CASE WHEN o.start_time < DATE_SUB(?, INTERVAL 30 MINUTE)
                      AND o.start_time >= DATE_SUB(?, INTERVAL 60 MINUTE) THEN 1 ELSE 0 END) AS previous_30,
            MAX(o.start_time) AS last_order_time
        FROM orders o
        LEFT JOIN venues v ON v.id = o.reservation_id
        {$filteredWhere}
        GROUP BY o.reservation_id, v.venue_name
        ORDER BY h3 DESC, m30 DESC, m10 DESC
        LIMIT 200
    ";

    $venueTypes = 'sssssss' . $baseTypes;
    $venueParams = [$now, $now, $now, $now, $now, $now, $now];
    foreach ($baseParams as $param) {
        $venueParams[] = $param;
    }
    $venueRows = fetchAll($connection, $venueSql, $venueTypes, $venueParams);

    $venues = [];
    foreach ($venueRows as $row) {
        $current30 = (int)($row['current_30'] ?? 0);
        $previous30 = (int)($row['previous_30'] ?? 0);
        if ($previous30 > 0) {
            $changeRate = round((($current30 - $previous30) / $previous30) * 100);
        } elseif ($current30 > 0) {
            $changeRate = 100;
        } else {
            $changeRate = 0;
        }

        $venues[] = [
            'venue_id' => (int)$row['venue_id'],
            'venue_name' => (string)$row['venue_name'],
            'm5' => (int)$row['m5'],
            'm10' => (int)$row['m10'],
            'm30' => (int)$row['m30'],
            'h3' => (int)$row['h3'],
            'users_h3' => (int)$row['users_h3'],
            'amount_h3' => round((float)$row['amount_h3'], 2),
            'amount_30' => round((float)$row['amount_30'], 2),
            'avg_amount' => (int)$row['h3'] > 0 ? round((float)$row['amount_h3'] / (int)$row['h3'], 2) : 0,
            'active' => (int)$row['active'],
            'completed' => (int)$row['completed'],
            'change_rate' => $changeRate,
            'last_order_time' => $row['last_order_time']
        ];
    }

    $trendStart = date('Y-m-d H:i:s', time() - ($range * 60));
    $trendWhere = str_replace('o.start_time >= ?', 'o.start_time >= ?', $baseWhere) . statusWhereSql($status);
    $trendParams = [$trendStart, $now];
    $trendTypes = 'ss';
    if (!$isAdmin) {
        $trendTypes .= 'i';
        $trendParams[] = $userVenueId;
    }

    $trendSql = "
        SELECT
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(o.start_time) / ?) * ?) AS bucket_time,
            COUNT(*) AS order_count,
            COALESCE(SUM(COALESCE(o.checkout_amount, o.payment_amount, 0)), 0) AS amount,
            COUNT(DISTINCT o.uid) AS users,
            COUNT(DISTINCT o.reservation_id) AS active_venues
        FROM orders o
        {$trendWhere}
        GROUP BY bucket_time
        ORDER BY bucket_time ASC
    ";
    $trendTypes = 'ii' . $trendTypes;
    array_unshift($trendParams, $bucketSeconds, $bucketSeconds);
    $trendRows = fetchAll($connection, $trendSql, $trendTypes, $trendParams);

    $trendMap = [];
    foreach ($trendRows as $row) {
        $key = date('Y-m-d H:i:00', strtotime($row['bucket_time']));
        $trendMap[$key] = [
            'orders' => (int)$row['order_count'],
            'amount' => round((float)$row['amount'], 2),
            'users' => (int)$row['users'],
            'active_venues' => (int)$row['active_venues']
        ];
    }

    $trend = [];
    $cursor = floor(strtotime($trendStart) / $bucketSeconds) * $bucketSeconds;
    $end = floor(time() / $bucketSeconds) * $bucketSeconds;
    while ($cursor <= $end) {
        $key = date('Y-m-d H:i:00', $cursor);
        $trend[] = array_merge([
            'time' => date('H:i', $cursor),
            'orders' => 0,
            'amount' => 0,
            'users' => 0,
            'active_venues' => 0
        ], $trendMap[$key] ?? []);
        $cursor += $bucketSeconds;
    }

    $summary = [
        'orders_h3' => array_sum(array_column($venues, 'h3')),
        'orders_m30' => array_sum(array_column($venues, 'm30')),
        'orders_m10' => array_sum(array_column($venues, 'm10')),
        'orders_m5' => array_sum(array_column($venues, 'm5')),
        'amount_h3' => round(array_sum(array_column($venues, 'amount_h3')), 2),
        'amount_m30' => round(array_sum(array_column($venues, 'amount_30')), 2),
        'active_orders' => array_sum(array_column($venues, 'active')),
        'completed_orders' => array_sum(array_column($venues, 'completed')),
        'active_venues' => count(array_filter($venues, function ($row) {
            return (int)$row['h3'] > 0;
        })),
        'hot_venues' => count(array_filter($venues, function ($row) {
            return (int)$row['m5'] >= 6 || (int)$row['change_rate'] >= 30;
        })),
        'watch_venues' => count(array_filter($venues, function ($row) {
            return (int)$row['change_rate'] <= -20 || (int)$row['m30'] === 0;
        }))
    ];
    $summary['avg_order_amount'] = $summary['orders_h3'] > 0
        ? round($summary['amount_h3'] / $summary['orders_h3'], 2)
        : 0;

    jsonOut(0, 'ok', [
        'mode' => 'real',
        'range' => $range,
        'status' => $status,
        'bucket_seconds' => $bucketSeconds,
        'generated_at' => $now,
        'window_start' => $windowStart,
        'summary' => $summary,
        'venues' => $venues,
        'trend' => $trend
    ]);
} catch (Throwable $e) {
    jsonOut(500, '订单趋势统计失败：' . $e->getMessage());
} finally {
    $database->close();
}
