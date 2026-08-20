<?php
require_once '../Database.php'; // 确保路径正确
header('Content-Type: application/json; charset=utf-8');

function logMessage($message) {
    $logFile = __DIR__ . '/order_test.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$role_id = (int)$user['role_id'];
$username = trim($user['username'] ?? '');

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit <= 0) {
    $limit = 20;
}
if ($limit > 100) {
    $limit = 100;
}
$offset = ($page - 1) * $limit;

// order_type=device：设备订单；order_type=gift：礼物订单
$order_type = $_GET['order_type'] ?? 'device';
$order_type = ($order_type === 'gift') ? 'gift' : 'device';

$order_number   = trim($_GET['order_number'] ?? '');
$uid            = trim($_GET['uid'] ?? '');
$serial_number  = trim($_GET['serial_number'] ?? '');

// 兼容旧参数 start_date/end_date，同时支持 start_time/end_time
$start_time     = trim($_GET['start_time'] ?? ($_GET['start_date'] ?? ''));
$end_time       = trim($_GET['end_time'] ?? ($_GET['end_date'] ?? ''));

$status         = trim($_GET['status'] ?? '');
$exclude_energy = $_GET['exclude_energy'] ?? 'off';

$isAdmin = in_array($role_id, [1, 2], true);
$userVenueId = (int)($user['venue_id'] ?? 0);

/**
 * 针对指定后台账号隐藏 2026 年 7 月之前的收入明细
 * 账号：cc0123456
 * 生效逻辑：
 * 1. 查询结束时间早于 2026-07-01，直接返回空数据。
 * 2. 查询开始时间为空或早于 2026-07-01，强制改成 2026-07-01 00:00:00。
 * 3. 前端就算手动改参数，也查不到 2026-07-01 之前的收入明细。
 */
$incomeLimitUsers = ['cc0123456'];
$incomeVisibleFrom = '2026-07-01 00:00:00';
$isIncomeLimitedUser = in_array($username, $incomeLimitUsers, true);

function normalizeIncomeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    // 兼容 datetime-local 传来的 2026-07-01T00:00
    $value = str_replace('T', ' ', $value);

    // 如果只有到分钟，补秒
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }

    // 如果只有日期，补当天开始
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= ' 00:00:00';
    }

    return $value;
}

function isBeforeIncomeVisibleFrom($value, $incomeVisibleFrom) {
    $value = normalizeIncomeDateTime($value);
    if ($value === '') {
        return false;
    }

    $time = strtotime($value);
    $limit = strtotime($incomeVisibleFrom);

    if ($time === false || $limit === false) {
        return false;
    }

    return $time < $limit;
}

if ($isIncomeLimitedUser) {
    $start_time = normalizeIncomeDateTime($start_time);
    $end_time   = normalizeIncomeDateTime($end_time);

    // 如果结束时间都在 2026-07-01 之前，直接返回空数据
    if ($end_time !== '' && isBeforeIncomeVisibleFrom($end_time, $incomeVisibleFrom)) {
        echo json_encode([
            'code' => 0,
            'msg'  => '',
            'order_type' => $order_type,
            'role_id' => $role_id,
            'count' => 0,
            'data'  => [],
            'total_income' => 0.00,
            'total_payment_amount' => 0.00,
            'income_visible_from' => $incomeVisibleFrom
        ], JSON_UNESCAPED_UNICODE);

        $database->close();
        exit;
    }

    // 没传开始时间，或者开始时间早于 2026-07-01，都强制从 2026-07-01 开始查
    if ($start_time === '' || isBeforeIncomeVisibleFrom($start_time, $incomeVisibleFrom)) {
        $start_time = $incomeVisibleFrom;
    }
}

if ($order_type === 'gift') {
    // =========================
    // 礼物订单：gift_orders
    // =========================
    $whereSql = " WHERE 1=1";
    $params = [];

    if ($uid !== '') {
        $whereSql .= " AND g.uid = ?";
        $params[] = $uid;
    }

    // 礼物订单按 send_time 过滤
    if ($start_time !== '') {
        $whereSql .= " AND g.send_time >= ?";
        $params[] = $start_time;
    }

    if ($end_time !== '') {
        $whereSql .= " AND g.send_time <= ?";
        $params[] = $end_time;
    }

    if ($status !== '') {
        $whereSql .= " AND g.status = ?";
        $params[] = $status;
    }

    if ($exclude_energy === 'on') {
        $whereSql .= " AND g.pays_type != '能量'";
    }

    // 非管理员只能看自己场地
    if (!$isAdmin) {
        $whereSql .= " AND g.reservation_id = ?";
        $params[] = $userVenueId;
    }

    $sql = "SELECT
                g.id,
                g.id AS order_id,
                g.reservation_id,
                g.gift_id,
                g.uid,
                COALESCE(u.nickname, '') AS nickname,
                g.status,
                g.payment_amount,
                ROUND((g.payment_amount / 10) * 0.6, 2) AS venue_income,
                g.send_time,
                g.pays_type,
                g.note,
                'gift' AS order_type
            FROM gift_orders g
            LEFT JOIN users u ON g.uid = u.uid
            $whereSql
            ORDER BY g.send_time DESC
            LIMIT ?, ?";

    $paramsForData = $params;
    $paramsForData[] = (int)$offset;
    $paramsForData[] = (int)$limit;

    $data = $database->query($sql, $paramsForData);

    $countSql = "SELECT COUNT(*) AS count FROM gift_orders g $whereSql";
    $countResult = $database->query($countSql, $params);
    $totalCount = is_array($countResult) && isset($countResult[0]['count']) ? (int)$countResult[0]['count'] : 0;

    // 礼物收益口径：sum(payment_amount) / 10 * 60%
    $sumSql = "SELECT
                    ROUND((COALESCE(SUM(g.payment_amount), 0) / 10) * 0.6, 2) AS total_income,
                    COALESCE(SUM(g.payment_amount), 0) AS total_payment_amount
               FROM gift_orders g
               $whereSql";
    logMessage('[gift] ' . $sumSql . ' | params=' . json_encode($params, JSON_UNESCAPED_UNICODE));

    $sumResult = $database->query($sumSql, $params);
    $totalIncome = 0.00;
    $totalPaymentAmount = 0.00;

    if (is_array($sumResult) && isset($sumResult[0])) {
        $totalIncome = round((float)($sumResult[0]['total_income'] ?? 0), 2);
        $totalPaymentAmount = round((float)($sumResult[0]['total_payment_amount'] ?? 0), 2);
    }

    echo json_encode([
        'code' => 0,
        'msg'  => '',
        'order_type' => 'gift',
        'role_id' => $role_id,
        'count' => $totalCount,
        'data'  => $data ?: [],
        'total_income' => $totalIncome,
        'total_payment_amount' => $totalPaymentAmount,
        'income_visible_from' => $isIncomeLimitedUser ? $incomeVisibleFrom : ''
    ], JSON_UNESCAPED_UNICODE);

    $database->close();
    exit;
}

// =========================
// 设备订单：orders，保持原来的展示逻辑
// =========================
$whereSql = " WHERE 1=1";
$params = [];

// 模糊查询订单号
if ($order_number !== '') {
    $whereSql .= " AND o.order_id LIKE ?";
    $params[] = "%$order_number%";
}

// 指定 UID
if ($uid !== '') {
    $whereSql .= " AND o.uid = ?";
    $params[] = $uid;
}

// 设备号模糊查询
if ($serial_number !== '') {
    $whereSql .= " AND o.serial_number LIKE ?";
    $params[] = "%$serial_number%";
}

// 设备订单按 end_time 过滤
if ($start_time !== '') {
    $whereSql .= " AND o.end_time >= ?";
    $params[] = $start_time;
}

if ($end_time !== '') {
    $whereSql .= " AND o.end_time <= ?";
    $params[] = $end_time;
}

// 状态筛选
if ($status !== '') {
    $whereSql .= " AND o.status = ?";
    $params[] = $status;
}

// 排除能量支付类型
if ($exclude_energy === 'on') {
    $whereSql .= " AND o.pays_type != '能量'";
}

// 分成设备订单/礼物订单后，设备订单不再展示 orders 里历史 note=gift 的记录，避免和 gift_orders 重复
$whereSql .= " AND (o.note <> 'gift' OR o.note IS NULL)";

// 非管理员只能看自己场地
if (!$isAdmin) {
    $whereSql .= " AND o.reservation_id = ?";
    $params[] = $userVenueId;
}

$sql = "SELECT
            o.*,
            COALESCE(u.nickname, '') AS nickname,
            o.payment_amount AS venue_income,
            ROUND(
                COALESCE(o.payment_amount, 0) - COALESCE(o.promotion_amount, 0),
                2
            ) AS actual_arrival_amount,
            'device' AS order_type
        FROM orders o
        LEFT JOIN users u ON o.uid = u.uid
        $whereSql
        ORDER BY o.start_time DESC
        LIMIT ?, ?";

$paramsForData = $params;
$paramsForData[] = (int)$offset;
$paramsForData[] = (int)$limit;

$data = $database->query($sql, $paramsForData);

$countSql = "SELECT COUNT(*) AS count FROM orders o $whereSql";
$countResult = $database->query($countSql, $params);
$totalCount = is_array($countResult) && isset($countResult[0]['count']) ? (int)$countResult[0]['count'] : 0;

$sumSql = "SELECT
                ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS total_income,
                ROUND(COALESCE(SUM(o.promotion_amount), 0), 2) AS total_promotion_amount,
                ROUND(
                    COALESCE(SUM(o.payment_amount), 0)
                    - COALESCE(SUM(o.promotion_amount), 0),
                    2
                ) AS total_actual_income
           FROM orders o
           $whereSql";
logMessage('[device] ' . $sumSql . ' | params=' . json_encode($params, JSON_UNESCAPED_UNICODE));

$sumResult = $database->query($sumSql, $params);
$totalIncome = 0.00;
$totalPromotionAmount = 0.00;
$totalActualIncome = 0.00;

if (is_array($sumResult) && isset($sumResult[0])) {
    $totalIncome = round((float)($sumResult[0]['total_income'] ?? 0), 2);
    $totalPromotionAmount = round((float)($sumResult[0]['total_promotion_amount'] ?? 0), 2);
    $totalActualIncome = round((float)($sumResult[0]['total_actual_income'] ?? 0), 2);
}

echo json_encode([
    'code' => 0,
    'msg'  => '',
    'order_type' => 'device',
    'role_id' => $role_id,
    'count' => $totalCount,
    'data'  => $data ?: [],
    'total_income' => $totalIncome,
    'total_promotion_amount' => $totalPromotionAmount,
    'total_actual_income' => $totalActualIncome,
    'income_visible_from' => $isIncomeLimitedUser ? $incomeVisibleFrom : ''
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
