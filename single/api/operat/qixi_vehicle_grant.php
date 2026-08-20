<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');

require_once '../Database.php';

function qvg_ok(array $data = array(), string $msg = 'ok'): void {
    echo json_encode(array('ok' => 1, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qvg_bad(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(array('ok' => 0, 'msg' => $msg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qvg_payload(): array {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = (string)file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }
    return $_POST ?? array();
}

function qvg_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = array();
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array(array($stmt, 'bind_param'), $refs);
}

function qvg_all(mysqli $conn, string $sql, string $types = '', array $params = array()): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('SQL预处理失败：' . $conn->error);
    qvg_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('SQL执行失败：' . $error);
    }
    $result = $stmt->get_result();
    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function qvg_one(mysqli $conn, string $sql, string $types = '', array $params = array()): ?array {
    $rows = qvg_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function qvg_money($value): string {
    if (function_exists('bcadd')) return bcadd((string)$value, '0', 2);
    return number_format((float)$value, 2, '.', '');
}

function qvg_date($value): string {
    $value = trim((string)$value);
    if ($value === '') return '2026-08-19';
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) qvg_bad('活动日期格式不正确');
    return $value;
}

function qvg_int($value, int $default = 0): int {
    return ($value === null || $value === '') ? $default : (int)$value;
}

function qvg_ensure_table(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS qixi_vehicle_activity_grants (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        activity_code VARCHAR(64) NOT NULL COMMENT '活动编码',
        activity_date DATE NOT NULL COMMENT '活动统计日期',
        uid BIGINT UNSIGNED NOT NULL COMMENT '获奖用户UID',
        product_id BIGINT UNSIGNED NOT NULL COMMENT '座驾商品ID',
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '达标消费金额',
        order_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '达标订单数',
        grant_record_id BIGINT UNSIGNED NULL COMMENT 'user_entrance_effect_purchases记录ID',
        admin_uid BIGINT UNSIGNED NOT NULL COMMENT '发放管理员UID',
        admin_name VARCHAR(255) NULL COMMENT '发放管理员名称',
        remark VARCHAR(255) NULL COMMENT '备注',
        granted_at DATETIME NOT NULL COMMENT '发放时间',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_activity_uid_product (activity_code, uid, product_id),
        KEY idx_activity_date (activity_date),
        KEY idx_uid (uid),
        KEY idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='七夕活动座驾发放记录'";
    if (!$conn->query($sql)) throw new Exception('创建活动发放表失败：' . $conn->error);
}

function qvg_product(mysqli $conn, int $productId): array {
    $product = qvg_one(
        $conn,
        'SELECT id, purchase_id, effect_name, effect_image_url, effect_animation_url, CAST(price AS CHAR) AS price, valid_days
           FROM entrance_effect_products
          WHERE id = ? AND status = 1
          LIMIT 1',
        'i',
        array($productId)
    );
    if (!$product) qvg_bad('座驾商品不存在或已下架');
    return $product;
}

function qvg_user_total(mysqli $conn, int $uid, string $date): array {
    $start = $date . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));
    $row = qvg_one(
        $conn,
        "SELECT
            COALESCE(SUM(COALESCE(NULLIF(o.checkout_amount, 0), o.payment_amount, 0)), 0) AS total_amount,
            COUNT(*) AS order_count,
            MAX(o.start_time) AS last_order_time
         FROM orders o
         WHERE o.uid = ?
           AND o.start_time >= ?
           AND o.start_time < ?
           AND o.status = '已完成'
           AND (o.pays_type IS NULL OR o.pays_type <> '能量')
           AND (o.note IS NULL OR o.note <> 'gift')",
        'iss',
        array($uid, $start, $end)
    );
    return array(
        'total_amount' => qvg_money($row['total_amount'] ?? '0'),
        'order_count' => (int)($row['order_count'] ?? 0),
        'last_order_time' => $row['last_order_time'] ?? null
    );
}

function qvg_order_no(int $uid): string {
    try {
        $random = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        $random = (string)mt_rand(10000000, 99999999);
    }
    return 'QX' . date('YmdHis') . $uid . $random;
}

$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) qvg_bad('未登录或缺少 session_token', 401);

$db = new Database();
$conn = $db->getConnection();
$conn->set_charset('utf8mb4');
$admin = $db->getUserBySessionToken($token);
if (!$admin) qvg_bad('登录已过期或 token 无效', 401);
if (!in_array((int)($admin['role_id'] ?? 0), array(1, 2), true)) qvg_bad('无权发放活动座驾', 403);

$act = (string)($_GET['act'] ?? 'list');

try {
    qvg_ensure_table($conn);

    if ($act === 'products') {
        $rows = qvg_all(
            $conn,
            'SELECT id, purchase_id, effect_name, effect_image_url, CAST(price AS CHAR) AS price, valid_days
               FROM entrance_effect_products
              WHERE status = 1
              ORDER BY sort_order ASC, id DESC'
        );
        qvg_ok(array('list' => $rows));
    }

    if ($act === 'grant') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') qvg_bad('请使用POST');
        $payload = qvg_payload();
        $date = qvg_date($payload['activity_date'] ?? '');
        $minAmount = qvg_money($payload['min_amount'] ?? '200');
        $productId = qvg_int($payload['product_id'] ?? 4, 4);
        $activityCode = trim((string)($payload['activity_code'] ?? 'QIXI_2026'));
        $autoEquip = qvg_int($payload['auto_equip'] ?? 1, 1) === 1;
        $uids = $payload['uids'] ?? array();
        if (!is_array($uids) || empty($uids)) qvg_bad('请选择要发放的用户');

        $product = qvg_product($conn, $productId);
        $adminUid = (int)$admin['uid'];
        $adminName = (string)($admin['username'] ?? ($admin['nickname'] ?? ''));
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $nowText = $now->format('Y-m-d H:i:s');
        $result = array('granted' => array(), 'skipped' => array());

        $conn->begin_transaction();
        try {
            foreach ($uids as $rawUid) {
                $uid = (int)$rawUid;
                if ($uid <= 0) continue;

                $total = qvg_user_total($conn, $uid, $date);
                if ((float)$total['total_amount'] < (float)$minAmount) {
                    $result['skipped'][] = array('uid' => $uid, 'reason' => '未达到消费门槛', 'total_amount' => $total['total_amount']);
                    continue;
                }

                $stmt = $conn->prepare(
                    'INSERT IGNORE INTO qixi_vehicle_activity_grants
                        (activity_code, activity_date, uid, product_id, total_amount, order_count, admin_uid, admin_name, remark, granted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $remark = '七夕消费满额活动发放';
                $stmt->bind_param(
                    'ssiisiisss',
                    $activityCode,
                    $date,
                    $uid,
                    $productId,
                    $total['total_amount'],
                    $total['order_count'],
                    $adminUid,
                    $adminName,
                    $remark,
                    $nowText
                );
                $stmt->execute();
                $insertedGrant = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$insertedGrant) {
                    $result['skipped'][] = array('uid' => $uid, 'reason' => '已发放过');
                    continue;
                }

                $user = qvg_one($conn, 'SELECT uid, CAST(wallet AS CHAR) AS wallet FROM users WHERE uid = ? LIMIT 1 FOR UPDATE', 'i', array($uid));
                if (!$user) {
                    $result['skipped'][] = array('uid' => $uid, 'reason' => '用户不存在');
                    continue;
                }

                $latest = qvg_one(
                    $conn,
                    'SELECT expire_time FROM user_entrance_effect_purchases
                      WHERE uid = ? AND product_id = ? AND status = 1
                      ORDER BY expire_time DESC LIMIT 1 FOR UPDATE',
                    'ii',
                    array($uid, $productId)
                );
                $base = $now;
                if ($latest && !empty($latest['expire_time'])) {
                    $latestExpire = new DateTimeImmutable((string)$latest['expire_time'], new DateTimeZone('Asia/Shanghai'));
                    if ($latestExpire > $base) $base = $latestExpire;
                }
                $expireText = $base->modify('+' . (int)$product['valid_days'] . ' days')->format('Y-m-d H:i:s');
                $orderNo = qvg_order_no($uid);
                $requestId = $activityCode . '_' . $date . '_' . $uid . '_' . $productId;
                $wallet = qvg_money($user['wallet'] ?? '0');
                $zero = '0.00';

                $stmt = $conn->prepare(
                    'INSERT INTO user_entrance_effect_purchases
                        (order_no, request_id, uid, product_id, purchase_id,
                         effect_name, effect_image_url, effect_animation_url,
                         price_paid, balance_before, balance_after,
                         start_time, expire_time, last_purchase_time, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $stmt->bind_param(
                    'ssiissssssssss',
                    $orderNo,
                    $requestId,
                    $uid,
                    $productId,
                    $product['purchase_id'],
                    $product['effect_name'],
                    $product['effect_image_url'],
                    $product['effect_animation_url'],
                    $zero,
                    $wallet,
                    $wallet,
                    $nowText,
                    $expireText,
                    $nowText
                );
                $stmt->execute();
                $purchaseRecordId = (int)$conn->insert_id;
                $stmt->close();

                if ($autoEquip) {
                    $stmt = $conn->prepare(
                        'INSERT INTO user_entrance_effect_equipment (uid, product_id, is_enabled, equipped_at)
                         VALUES (?, ?, 1, NOW())
                         ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), is_enabled = 1, equipped_at = NOW()'
                    );
                    $stmt->bind_param('ii', $uid, $productId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare(
                    'UPDATE qixi_vehicle_activity_grants
                        SET grant_record_id = ?, total_amount = ?, order_count = ?, updated_at = NOW()
                      WHERE activity_code = ? AND uid = ? AND product_id = ?'
                );
                $stmt->bind_param('isisii', $purchaseRecordId, $total['total_amount'], $total['order_count'], $activityCode, $uid, $productId);
                $stmt->execute();
                $stmt->close();

                $result['granted'][] = array(
                    'uid' => $uid,
                    'effect_name' => $product['effect_name'],
                    'expire_time' => $expireText,
                    'total_amount' => $total['total_amount']
                );
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        qvg_ok($result, '发放完成');
    }

    if ($act !== 'list') qvg_bad('未知操作');

    $date = qvg_date($_GET['activity_date'] ?? '');
    $minAmount = qvg_money($_GET['min_amount'] ?? '200');
    $productId = qvg_int($_GET['product_id'] ?? 4, 4);
    $activityCode = trim((string)($_GET['activity_code'] ?? 'QIXI_2026'));
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $grantStatus = (string)($_GET['grant_status'] ?? 'pending');
    $start = $date . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));

    qvg_product($conn, $productId);

    $whereKeyword = '';
    $types = 'ssssi';
    $params = array($start, $end, $activityCode, $date, $productId);
    if ($keyword !== '') {
        $whereKeyword = ' AND (CAST(a.uid AS CHAR) = ? OR a.nickname LIKE CONCAT("%", ?, "%") OR a.phone_number LIKE CONCAT("%", ?, "%")) ';
    }

    $having = ' WHERE a.total_amount >= ? ';
    $types .= 's';
    $params[] = $minAmount;
    if ($grantStatus === 'pending') {
        $having .= ' AND g.id IS NULL ';
    } elseif ($grantStatus === 'granted') {
        $having .= ' AND g.id IS NOT NULL ';
    }
    if ($keyword !== '') {
        $types .= 'sss';
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    $sql = "SELECT
            a.uid,
            a.nickname,
            a.phone_number,
            CAST(a.total_amount AS CHAR) AS total_amount,
            a.order_count,
            a.last_order_time,
            g.id AS grant_id,
            g.grant_record_id,
            g.granted_at,
            g.admin_uid,
            g.admin_name
        FROM (
            SELECT
                o.uid,
                COALESCE(u.nickname, '') AS nickname,
                COALESCE(u.phone_number, '') AS phone_number,
                COALESCE(SUM(COALESCE(NULLIF(o.checkout_amount, 0), o.payment_amount, 0)), 0) AS total_amount,
                COUNT(*) AS order_count,
                MAX(o.start_time) AS last_order_time
            FROM orders o
            LEFT JOIN users u ON u.uid = o.uid
            WHERE o.start_time >= ?
              AND o.start_time < ?
              AND o.status = '已完成'
              AND (o.pays_type IS NULL OR o.pays_type <> '能量')
              AND (o.note IS NULL OR o.note <> 'gift')
            GROUP BY o.uid, u.nickname, u.phone_number
        ) a
        LEFT JOIN qixi_vehicle_activity_grants g
          ON g.activity_code = ?
         AND g.activity_date = ?
         AND g.product_id = ?
         AND g.uid = a.uid
        {$having}
        {$whereKeyword}
        ORDER BY g.id IS NOT NULL ASC, a.total_amount DESC, a.order_count DESC
        LIMIT 500";

    $rows = qvg_all($conn, $sql, $types, $params);
    foreach ($rows as &$row) {
        $row['uid'] = (int)$row['uid'];
        $row['order_count'] = (int)$row['order_count'];
        $row['total_amount'] = qvg_money($row['total_amount']);
        $row['is_granted'] = !empty($row['grant_id']);
    }
    unset($row);

    $summary = qvg_one(
        $conn,
        "SELECT
            COUNT(*) AS eligible_users,
            COALESCE(SUM(total_amount), 0) AS eligible_amount
         FROM (
            SELECT o.uid, COALESCE(SUM(COALESCE(NULLIF(o.checkout_amount, 0), o.payment_amount, 0)), 0) AS total_amount
            FROM orders o
            WHERE o.start_time >= ?
              AND o.start_time < ?
              AND o.status = '已完成'
              AND (o.pays_type IS NULL OR o.pays_type <> '能量')
              AND (o.note IS NULL OR o.note <> 'gift')
            GROUP BY o.uid
            HAVING total_amount >= ?
         ) s",
        'sss',
        array($start, $end, $minAmount)
    );
    $grantSummary = qvg_one(
        $conn,
        'SELECT COUNT(*) AS granted_users
           FROM qixi_vehicle_activity_grants
          WHERE activity_code = ? AND activity_date = ? AND product_id = ?',
        'ssi',
        array($activityCode, $date, $productId)
    );

    qvg_ok(array(
        'list' => $rows,
        'summary' => array(
            'eligible_users' => (int)($summary['eligible_users'] ?? 0),
            'eligible_amount' => qvg_money($summary['eligible_amount'] ?? '0'),
            'granted_users' => (int)($grantSummary['granted_users'] ?? 0),
            'pending_users' => max(0, (int)($summary['eligible_users'] ?? 0) - (int)($grantSummary['granted_users'] ?? 0))
        )
    ));
} catch (Throwable $e) {
    if (isset($db) && method_exists($db, 'logToFile')) $db->logToFile('[qixi_vehicle_grant] ' . $e->getMessage());
    qvg_bad('服务器异常：' . $e->getMessage(), 500);
}
