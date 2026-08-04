<?php 
require_once '../Database.php';   // 确保路径正确 
//  api/finance/getDrivOrder.php
header('Content-Type: application/json; charset=utf-8');
// 创建数据库连接 
$database = new Database(); 
 
// 从会话中获取 session_token 
$session_token = filter_input(INPUT_COOKIE, 'session_token'); 
 
// 验证 session_token 并获取用户信息 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 
 
$user = $database->getUserBySessionToken($session_token); 
 
// 检查用户是否存在和权限获取 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
 
$role_id = (int)$user['role_id'];
 
// 获取请求参数，用于订单号和用户 ID 过滤 
$order_number = filter_input(INPUT_GET, 'order_number'); 
$uid = filter_input(INPUT_GET, 'uid');
$venue_id = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);

// 订单类型：drive=驾驶订单，gift=礼物订单，guardian=守护订单
$order_type = filter_input(INPUT_GET, 'order_type') ?: 'drive';
$order_type = in_array($order_type, ['drive', 'gift', 'guardian'], true)
    ? $order_type
    : 'drive';

// ==========================
// 守护订单查询：venue_guardian_orders
// ==========================
if ($order_type === 'guardian') {
    $whereSql = " WHERE 1=1";
    $params = [];

    // 支持按守护订单号、请求号、表主键搜索
    if (!empty($order_number)) {
        $whereSql .= " AND (
            gdo.order_no LIKE ?
            OR gdo.request_id LIKE ?
            OR CAST(gdo.id AS CHAR) LIKE ?
        )";
        $keyword = "%{$order_number}%";
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    if (!empty($uid)) {
        $whereSql .= " AND gdo.uid = ?";
        $params[] = $uid;
    }

    if (!in_array($role_id, [1, 2], true)) {
        // role_id=3/4 以及其他非管理员账号，只能查询绑定场地
        $whereSql .= " AND gdo.venue_id = ?";
        $params[] = (int)($user['venue_id'] ?? 0);
    } elseif (!empty($venue_id)) {
        // role_id=1/2 可按场地下拉框筛选
        $whereSql .= " AND gdo.venue_id = ?";
        $params[] = $venue_id;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = max(1, min(20, intval($_GET['page_size'] ?? $_GET['limit'] ?? 5)));
    $offset = ($page - 1) * $pageSize;
    $fast = isset($_GET['fast']) && $_GET['fast'] == '1';
    $queryLimit = $fast ? ($pageSize + 1) : $pageSize;

    $sql = "SELECT
                gdo.id,
                gdo.order_no,
                gdo.order_no AS order_id,
                gdo.request_id,
                gdo.venue_id,
                gdo.venue_id AS reservation_id,
                gdo.uid,
                COALESCE(u.nickname, '') AS nickname,
                gdo.price_gold,
                gdo.duration_days,
                gdo.balance_before,
                gdo.balance_after,
                gdo.expires_before,
                gdo.expires_after,
                gdo.venue_share_gold,
                gdo.platform_share_gold,
                gdo.status AS status_code,
                CASE gdo.status
                    WHEN 0 THEN '处理中'
                    WHEN 1 THEN '成功'
                    WHEN 2 THEN '失败'
                    WHEN 3 THEN '退款'
                    ELSE CONCAT('未知(', gdo.status, ')')
                END AS status,
                gdo.failure_reason,
                gdo.created_at,
                gdo.paid_at,
                gdo.updated_at,
                COALESCE(gdo.paid_at, gdo.created_at) AS start_time,
                COALESCE(gdo.paid_at, gdo.created_at) AS sort_time,
                COALESCE(v.venue_name, '未知场地') AS venue_name,
                '金币' AS pays_type,
                'guardian' AS order_type,
                NULL AS refund_status,
                NULL AS lock_status,
                0 AS lock_amount
            FROM venue_guardian_orders gdo
            LEFT JOIN users u ON u.uid = gdo.uid
            LEFT JOIN venues v ON v.id = gdo.venue_id
            {$whereSql}
            ORDER BY COALESCE(gdo.paid_at, gdo.created_at) DESC, gdo.id DESC
            LIMIT {$queryLimit} OFFSET {$offset}";

    $data = $database->query($sql, $params);

    if ($data === false || !is_array($data)) {
        echo json_encode([
            'code' => 500,
            'msg' => '守护订单查询失败，请确认 venue_guardian_orders 表已创建',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }

    $hasMore = false;
    if ($fast && count($data) > $pageSize) {
        $hasMore = true;
        $data = array_slice($data, 0, $pageSize);
    }

    if ($fast) {
        $totalCount = $offset + count($data) + ($hasMore ? 1 : 0);
    } else {
        $countSql = "SELECT COUNT(*) AS count FROM venue_guardian_orders gdo {$whereSql}";
        $countResult = $database->query($countSql, $params);
        $totalCount = is_array($countResult) && isset($countResult[0]['count'])
            ? intval($countResult[0]['count'])
            : count($data);
    }

    echo json_encode([
        'code' => 0,
        'msg' => '',
        'order_type' => 'guardian',
        'count' => $totalCount,
        'has_more' => $hasMore,
        'fast' => $fast,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

    $database->close();
    exit;
}
 
// ==========================
// 礼物订单查询
// ==========================
if ($order_type === 'gift') {
    $whereSql = " WHERE 1=1";
    $params = [];

    // 礼物订单搜索：支持输入 425 或 GIFT425
    if (!empty($order_number)) {
        $whereSql .= " AND (
            CAST(g.id AS CHAR) LIKE ?
            OR CONCAT('GIFT', g.id) LIKE ?
        )";
        $params[] = "%$order_number%";
        $params[] = "%$order_number%";
    }

    if (!empty($uid)) {
        $whereSql .= " AND g.uid = ?";
        $params[] = $uid;
    }

if (!in_array($role_id, [1, 2], true)) {
    // 非管理员，只能看自己场地的礼物订单
    $whereSql .= " AND g.reservation_id = ?";
    $params[] = $user['venue_id'];
} elseif (!empty($venue_id)) {
    // 管理员可以按下拉框选择场地
    $whereSql .= " AND g.reservation_id = ?";
    $params[] = $venue_id;
}

    $sql = "
        SELECT
            CONCAT('GIFT', g.id) AS order_id,
            g.id AS gift_order_id,
            g.reservation_id,
            g.gift_id,
            g.uid,
            u.nickname,
            g.status,
            g.payment_amount,
            g.send_time,
            g.send_time AS start_time,
            g.send_time AS sort_time,
            NULL AS end_time,
            g.pays_type,
            g.note,
            v.venue_name,
            COALESCE(vgd.gift_name, gd.gift_name, CONCAT('礼物#', g.gift_id)) AS gift_name,
            'gift' AS order_type,

            NULL AS serial_number,
            NULL AS billing_rules,
            NULL AS refund_status,
            NULL AS lock_status,
            0 AS lock_amount
        FROM gift_orders g
        LEFT JOIN users u ON g.uid = u.uid
        LEFT JOIN venues v ON g.reservation_id = v.id
        LEFT JOIN gift_detail gd ON gd.id = g.gift_id
        LEFT JOIN venue_gift_detail vgd ON vgd.id = g.gift_id
        {$whereSql}
        ORDER BY g.id DESC
    ";

    $data = $database->query($sql, $params);

    $countSql = "
        SELECT COUNT(*) AS count
        FROM gift_orders g
        LEFT JOIN users u ON g.uid = u.uid
        LEFT JOIN venues v ON g.reservation_id = v.id
        {$whereSql}
    ";

    $totalCountResult = $database->query($countSql, $params);
    $totalCount = is_array($totalCountResult) && isset($totalCountResult[0]['count'])
        ? intval($totalCountResult[0]['count'])
        : 0;

    echo json_encode([
        'code' => 0,
        'msg' => '',
        'order_type' => 'gift',
        'count' => $totalCount,
        'data' => $data ?: []
    ], JSON_UNESCAPED_UNICODE);

    $database->close();
    exit;
}
 
// 初始化 WHERE 条件和参数数组 
$whereSql = " WHERE 1=1"; 
$params = []; // 初始化参数数组 
 
// 添加搜索条件 
if (!empty($order_number)) { 
    $whereSql .= " AND o.order_id   LIKE ?"; 
    $params[] = "$order_number%"; 
} 
 
if (!empty($uid)) { 
    $whereSql .= " AND o.uid   = ?"; 
    $params[] = $uid; 
} 
 
if (!in_array($role_id, [1, 2], true)) {
    // 非管理员，只能看到绑定的场地数据
    $whereSql .= " AND o.reservation_id = ?";
    $params[] = $user['venue_id'];
} elseif (!empty($venue_id)) {
    // 管理员可以按下拉框选择场地
    $whereSql .= " AND o.reservation_id = ?";
    $params[] = $venue_id;
}
 
// 构建查询语句,包括用户的昵称,并按照 order_id 倒序排列 
// $sql = "SELECT o.*, u.nickname  FROM orders o JOIN users u ON o.uid  = u.uid"  . $whereSql . " ORDER BY o.order_id  DESC"; 
// 驾驶订单查询：尽量保持原来的稳定 SQL
// 驾驶订单查询：fast=1 时不做 COUNT，只查当前页 + 多 1 条判断是否有下一页
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = max(1, min(20, intval($_GET['page_size'] ?? $_GET['limit'] ?? 5)));
$offset = ($page - 1) * $pageSize;

$fast = isset($_GET['fast']) && $_GET['fast'] == '1';
$queryLimit = $fast ? ($pageSize + 1) : $pageSize;

// 不建议 SELECT o.*，只取前端展示需要的字段
$sql = "SELECT 
            o.order_id,
            o.uid,
            o.reservation_id,
            o.serial_number,
            o.status,
            o.payment_amount,
            o.billing_rules,
            o.pays_type,
            o.start_time,
            o.end_time,
            o.start_time AS sort_time,
            u.nickname,
            COALESCE(v.venue_name, '未知场地') AS venue_name
        FROM orders o 
        LEFT JOIN users u ON o.uid = u.uid
        LEFT JOIN venues v ON v.id = o.reservation_id
        
        {$whereSql}
        ORDER BY o.start_time DESC, o.order_id DESC
        LIMIT {$queryLimit} OFFSET {$offset}";

$data = $database->query($sql, $params);

if ($data === false || !is_array($data)) {
    echo json_encode([
        'code' => 500,
        'msg'  => '驾驶订单查询失败',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}

// fast 模式：多查 1 条，用来判断有没有下一页
$hasMore = false;
if ($fast && count($data) > $pageSize) {
    $hasMore = true;
    $data = array_slice($data, 0, $pageSize);
}
// 批量补充设备名称，避免 orders 和 vehicles 直接联表导致查询变慢
if (!empty($data)) {
    $serialNumbers = [];

    foreach ($data as $order) {
        $serialNumber = trim((string)($order['serial_number'] ?? ''));

        if ($serialNumber !== '') {
            $serialNumbers[] = $serialNumber;
        }
    }

    $serialNumbers = array_values(array_unique($serialNumbers));
    $deviceNameMap = [];

    if (!empty($serialNumbers)) {
        $placeholders = implode(',', array_fill(0, count($serialNumbers), '?'));

        $deviceSql = "
            SELECT serial_number, name
            FROM vehicles
            WHERE serial_number IN ($placeholders)
        ";

        $deviceRows = $database->query($deviceSql, $serialNumbers);

        if (is_array($deviceRows)) {
            foreach ($deviceRows as $deviceRow) {
                $sn = (string)($deviceRow['serial_number'] ?? '');

                if ($sn !== '') {
                    $deviceNameMap[$sn] = (string)($deviceRow['name'] ?? '');
                }
            }
        }
    }

    foreach ($data as &$order) {
        $sn = (string)($order['serial_number'] ?? '');
        $order['device_name'] = $deviceNameMap[$sn] ?? '';
    }
    unset($order);
}
// 给驾驶订单补充前端需要的字段
foreach ($data as &$order) {
    $order['order_type'] = 'drive';
    $order['sort_time'] = $order['start_time'] ?? ($order['end_time'] ?? '');
}
unset($order);

// 只对当前页这几条补退款/锁定状态
if (!empty($data)) {
    $orderIds = array_column($data, 'order_id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $refundSql = "SELECT order_id, status FROM refund_records WHERE order_id IN ($placeholders)";
    $refunds = $database->query($refundSql, $orderIds);
    if ($refunds === false || !is_array($refunds)) {
        $refunds = [];
    }

    $refundMap = [];
    foreach ($refunds as $r) {
        $refundMap[$r['order_id']] = $r['status'];
    }

    $lockSql = "SELECT order_id, status, lock_amount FROM order_lock_records WHERE order_id IN ($placeholders)";
    $locks = $database->query($lockSql, $orderIds);
    if ($locks === false || !is_array($locks)) {
        $locks = [];
    }

    $lockMap = [];
    foreach ($locks as $r) {
        $lockMap[$r['order_id']] = [
            'status' => (int)$r['status'],
            'lock_amount' => (float)$r['lock_amount'],
        ];
    }

    foreach ($data as &$order) {
        $order['refund_status'] = $refundMap[$order['order_id']] ?? null;

        $order['lock_status'] = isset($lockMap[$order['order_id']])
            ? (int)$lockMap[$order['order_id']]['status']
            : null;

        $order['lock_amount'] = isset($lockMap[$order['order_id']])
            ? (float)$lockMap[$order['order_id']]['lock_amount']
            : 0;
    }
    unset($order);
}

// 非 fast 模式才查总数
if ($fast) {
    $totalCount = $offset + count($data) + ($hasMore ? 1 : 0);
} else {
    $countSql = "SELECT COUNT(*) as count FROM orders o" . $whereSql;
    $totalCountResult = $database->query($countSql, $params);

    if (is_array($totalCountResult) && isset($totalCountResult[0]['count'])) {
        $totalCount = intval($totalCountResult[0]['count']);
    } else {
        error_log("Unexpected result from count query: " . print_r($totalCountResult, true));
        $totalCount = count($data);
    }
}

echo json_encode([
    'code' => 0,
    'msg' => '',
    'count' => $totalCount,
    'has_more' => $hasMore,
    'fast' => $fast,
    'data' => $data
], JSON_UNESCAPED_UNICODE);

$database->close();
exit; 
?> 