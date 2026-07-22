<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function outJson($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function validDate($value) {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

$database = new Database();
$sessionToken = $_COOKIE['session_token'] ?? '';
$user = $sessionToken ? $database->getUserBySessionToken($sessionToken) : null;
if (!$user || empty($user['role_id'])) {
    outJson(1001, '用户未登录或会话已过期');
}

$roleId = (int)$user['role_id'];
$bindVenueId = (int)($user['venue_id'] ?? 0);
$action = $_GET['action'] ?? 'orders';

if ($action === 'venues') {
    $sql = "SELECT id, venue_name FROM venues";
    $params = [];
    if (!in_array($roleId, [1, 2], true)) {
        $sql .= " WHERE id = ?";
        $params[] = $bindVenueId;
    }
    $sql .= " ORDER BY id ASC";
    outJson(0, 'ok', ['list' => $database->query($sql, $params) ?: []]);
}

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
if (!validDate($startDate) || !validDate($endDate) || strtotime($startDate) > strtotime($endDate)) {
    outJson(400, '统计日期参数错误');
}
$startTime = $startDate . ' 00:00:00';
$endTime = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));

if ($action === 'orders') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(50, max(10, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $unionSql = "
        SELECT CONCAT('apple_', a.id) AS row_key, a.uid,
               COALESCE(NULLIF(a.transaction_id, ''), NULLIF(a.purchase_id, ''), CONCAT('IAP_', a.id)) AS order_number,
               COALESCE(p.product_name, CONCAT('金币充值', COALESCE(a.total_gold, 0), '金币')) AS product_name,
               COALESCE(a.total_gold, p.gold_amount, 0) AS gold_amount,
               CAST(COALESCE(p.price, 0) AS DECIMAL(10,2)) AS amount,
               CASE WHEN a.environment = 'Sandbox' THEN '苹果IAP(Sandbox)' ELSE '苹果IAP' END AS channel,
               COALESCE(a.purchase_date, a.created_at) AS paid_time
        FROM apple_iap_orders a
        LEFT JOIN iap_gold_products p ON p.product_id = a.product_id
        WHERE a.order_status = 'success' AND a.verify_status = 1
          AND COALESCE(a.purchase_date, a.created_at) >= ?
          AND COALESCE(a.purchase_date, a.created_at) < ?
        UNION ALL
        SELECT CONCAT('android_', r.id), r.uid, r.order_number, r.product_name,
               CAST(COALESCE(NULLIF(r.value, ''), '0') AS DECIMAL(12,2)),
               CAST(COALESCE(NULLIF(r.payer_total, ''), '0') AS DECIMAL(10,2)),
               COALESCE(NULLIF(r.payment_channel, ''), NULLIF(r.third_party, ''), '安卓'), r.created_at
        FROM RechargeOrders r
        WHERE r.order_number LIKE '%GO%' AND r.status = '支付成功'
          AND r.created_at >= ? AND r.created_at < ?
    ";
    $params = [$startTime, $endTime, $startTime, $endTime];
    $countRows = $database->query("SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS total_amount FROM ($unionSql) x", $params) ?: [];
    $total = (int)($countRows[0]['total'] ?? 0);
    $listSql = "SELECT x.*, u.nickname FROM ($unionSql) x LEFT JOIN users u ON u.uid = x.uid ORDER BY x.paid_time DESC LIMIT $pageSize OFFSET $offset";
    $list = $database->query($listSql, $params) ?: [];
    outJson(0, 'ok', [
        'list' => $list,
        'total' => $total,
        'total_amount' => number_format((float)($countRows[0]['total_amount'] ?? 0), 2, '.', ''),
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int)ceil($total / $pageSize))
    ]);
}

if ($action === 'top_consumers') {
    $venueId = (int)($_GET['venue_id'] ?? 0);
    if (!in_array($roleId, [1, 2], true)) {
        $venueId = $bindVenueId;
    }

    $whereVenue = '';
    $params = [$startTime, $endTime];
    if ($venueId > 0) {
        $whereVenue = ' AND g.reservation_id = ?';
        $params[] = $venueId;
    }

    $sql = "
        SELECT g.uid, COALESCE(NULLIF(u.nickname, ''), CONCAT('用户', g.uid)) AS nickname,
               COUNT(*) AS order_count,
               ROUND(COALESCE(SUM(g.payment_amount), 0), 2) AS consume_gold
        FROM gift_orders g
        LEFT JOIN users u ON u.uid = g.uid
        WHERE g.pays_type = '金币'
          AND g.status = '已完成'
          AND g.send_time >= ? AND g.send_time < ? $whereVenue
        GROUP BY g.uid, u.nickname
        ORDER BY consume_gold DESC, order_count DESC, g.uid ASC
        LIMIT 50
    ";
    outJson(0, 'ok', ['list' => $database->query($sql, $params) ?: [], 'venue_id' => $venueId]);
}

outJson(400, '不支持的操作');
