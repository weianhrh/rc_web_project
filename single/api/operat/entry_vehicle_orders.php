<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');
require_once '../Database.php';

function evo_ok(array $data = array(), string $msg = 'ok'): void {
    echo json_encode(array('ok' => 1, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function evo_bad(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(array('ok' => 0, 'msg' => $msg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function evo_int($value, int $default = 0): int {
    return ($value === null || $value === '') ? $default : (int)$value;
}
function evo_text($value): string {
    return trim((string)$value);
}

$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) evo_bad('未登录或缺少 session_token', 401);
$db = new Database();
$admin = $db->getUserBySessionToken($token);
if (!$admin) evo_bad('登录已过期或 token 无效', 401);
if (!in_array((int)($admin['role_id'] ?? 0), array(1, 2, 3, 4), true)) evo_bad('无权查看座驾订单', 403);

$act = (string)($_GET['act'] ?? 'list');
try {
    if ($act === 'detail') {
        $uid = evo_int($_GET['uid'] ?? 0);
        $productId = evo_int($_GET['product_id'] ?? 0);
        if ($uid <= 0 || $productId <= 0) evo_bad('缺少UID或商品ID');
        $rows = $db->query(
            'SELECT id, order_no, request_id, uid, product_id, purchase_id,
                    effect_name, effect_image_url, effect_animation_url,
                    CAST(price_paid AS CHAR) AS price_paid,
                    CAST(balance_before AS CHAR) AS balance_before,
                    CAST(balance_after AS CHAR) AS balance_after,
                    start_time, expire_time, last_purchase_time, status, created_at
               FROM user_entrance_effect_purchases
              WHERE uid = ? AND product_id = ?
              ORDER BY last_purchase_time DESC, id DESC',
            array($uid, $productId)
        );
        evo_ok(array('list' => $rows ?: array()));
    }

    if ($act !== 'list') evo_bad('未知操作');

    $page = max(1, evo_int($_GET['page'] ?? 1, 1));
    $pageSize = min(100, max(1, evo_int($_GET['page_size'] ?? 20, 20)));
    $offset = ($page - 1) * $pageSize;
    $uid = evo_int($_GET['uid'] ?? 0);
    $orderNo = evo_text($_GET['order_no'] ?? '');
    $expiryStatus = (string)($_GET['expiry_status'] ?? '');

    $where = ' WHERE up.status = 1 ';
    $params = array();
    if ($uid > 0) {
        $where .= ' AND up.uid = ? ';
        $params[] = $uid;
    }
    if ($orderNo !== '') {
        $where .= ' AND EXISTS (
            SELECT 1 FROM user_entrance_effect_purchases search_order
             WHERE search_order.uid = up.uid
               AND search_order.product_id = up.product_id
               AND search_order.order_no LIKE CONCAT("%", ?, "%")
        ) ';
        $params[] = $orderNo;
    }

    $having = '';
    if ($expiryStatus === 'active') {
        $having = ' HAVING MAX(up.expire_time) > NOW() ';
    } elseif ($expiryStatus === 'expired') {
        $having = ' HAVING MAX(up.expire_time) <= NOW() ';
    }

    $countSql = 'SELECT COUNT(*) AS c FROM (
        SELECT up.uid, up.product_id
          FROM user_entrance_effect_purchases up
          ' . $where . '
         GROUP BY up.uid, up.product_id
         ' . $having . '
    ) grouped_orders';
    $countRows = $db->query($countSql, $params);
    $total = (int)($countRows[0]['c'] ?? 0);

    $listSql = 'SELECT
            up.uid,
            up.product_id,
            COALESCE(u.nickname, "") AS nickname,
            COALESCE(u.phone_number, "") AS phone_number,
            p.purchase_id,
            p.effect_name,
            p.effect_image_url,
            COUNT(*) AS purchase_count,
            GREATEST(COUNT(*) - 1, 0) AS renewal_count,
            CAST(SUM(up.price_paid) AS CHAR) AS total_paid,
            MIN(up.start_time) AS first_start_time,
            MAX(up.expire_time) AS expire_time,
            MAX(up.last_purchase_time) AS last_purchase_time,
            TIMESTAMPDIFF(SECOND, NOW(), MAX(up.expire_time)) AS remaining_seconds,
            SUBSTRING_INDEX(
                GROUP_CONCAT(up.order_no ORDER BY up.last_purchase_time DESC, up.id DESC SEPARATOR "||"),
                "||", 1
            ) AS latest_order_no,
            MAX(up.id) AS latest_record_id
        FROM user_entrance_effect_purchases up
        LEFT JOIN users u ON u.uid = up.uid
        LEFT JOIN entrance_effect_products p ON p.id = up.product_id
        ' . $where . '
        GROUP BY
            up.uid, up.product_id, u.nickname, u.phone_number,
            p.purchase_id, p.effect_name, p.effect_image_url
        ' . $having . '
        ORDER BY MAX(up.last_purchase_time) DESC, MAX(up.id) DESC
        LIMIT ' . $offset . ', ' . $pageSize;
    $rows = $db->query($listSql, $params);
    foreach ($rows as &$row) {
        $row['uid'] = (int)$row['uid'];
        $row['product_id'] = (int)$row['product_id'];
        $row['purchase_count'] = (int)$row['purchase_count'];
        $row['renewal_count'] = (int)$row['renewal_count'];
        $row['remaining_seconds'] = (int)$row['remaining_seconds'];
        $row['is_expired'] = strtotime((string)$row['expire_time']) <= time();
    }
    unset($row);

    evo_ok(array('list' => $rows ?: array(), 'pagination' => array(
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_page' => (int)ceil($total / max(1, $pageSize))
    )));
} catch (Throwable $e) {
    if (method_exists($db, 'logToFile')) $db->logToFile('[entry_vehicle_orders] ' . $e->getMessage());
    evo_bad('服务器异常：' . $e->getMessage(), 500);
}