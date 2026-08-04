<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');
require_once '../Database.php';

function evp_ok(array $data = array(), string $msg = 'ok'): void {
    echo json_encode(array('ok' => 1, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function evp_bad(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(array('ok' => 0, 'msg' => $msg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function evp_payload(): array {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = (string)file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }
    return $_POST ?? array();
}
function evp_int($value, int $default = 0): int {
    return ($value === null || $value === '') ? $default : (int)$value;
}
function evp_flag($value, int $default = 0): int {
    if ($value === null || $value === '') return $default;
    return (int)$value === 1 ? 1 : 0;
}
function evp_text($value): string {
    return trim((string)$value);
}
function evp_datetime($value, string $label): ?string {
    $value = trim(str_replace('T', ' ', (string)$value));
    if ($value === '') return null;
    if (strlen($value) === 16) $value .= ':00';
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) evp_bad($label . '格式不正确');
    return $value;
}
function evp_product_payload(array $payload): array {
    $purchaseId = evp_text($payload['purchase_id'] ?? '');
    $effectName = evp_text($payload['effect_name'] ?? '');
    $imageUrl = evp_text($payload['effect_image_url'] ?? '');
    $animationUrl = evp_text($payload['effect_animation_url'] ?? '');
    $price = round((float)($payload['price'] ?? 0), 2);
    $validDays = evp_int($payload['valid_days'] ?? 30, 30);
    $startTime = evp_datetime($payload['start_time'] ?? '', '开始售卖时间');
    $expireTime = evp_datetime($payload['expire_time'] ?? '', '停止售卖时间');
    $status = evp_flag($payload['status'] ?? 1, 1);
    $sortOrder = evp_int($payload['sort_order'] ?? 0, 0);

    if ($purchaseId === '' || strlen($purchaseId) > 64 || preg_match('/^[A-Za-z0-9._-]+$/', $purchaseId) !== 1) {
        evp_bad('购买ID仅允许字母、数字、点、下划线和短横线，最长64位');
    }
    if ($effectName === '' || mb_strlen($effectName) > 100) evp_bad('特效名称不能为空且不能超过100字');
    if ($imageUrl === '' || strlen($imageUrl) > 1000) evp_bad('商品图链接不能为空或过长');
    if ($animationUrl === '' || strlen($animationUrl) > 1000) evp_bad('特效动图链接不能为空或过长');
    if ($price < 0) evp_bad('价格不能为负数');
    if ($validDays <= 0 || $validDays > 36500) evp_bad('有效天数必须在1到36500之间');
    if ($startTime !== null && $expireTime !== null && strtotime($expireTime) <= strtotime($startTime)) {
        evp_bad('停止售卖时间必须晚于开始售卖时间');
    }

    return array($purchaseId, $effectName, $imageUrl, $animationUrl, number_format($price, 2, '.', ''), $validDays, $startTime, $expireTime, $status, $sortOrder);
}

$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) evp_bad('未登录或缺少 session_token', 401);
$db = new Database();
$admin = $db->getUserBySessionToken($token);
if (!$admin) evp_bad('登录已过期或 token 无效', 401);
if (!in_array((int)($admin['role_id'] ?? 0), array(1, 2, 3, 4), true)) evp_bad('无权管理座驾商品', 403);

$act = (string)($_GET['act'] ?? 'list');
try {
    switch ($act) {
        case 'list':
            $page = max(1, evp_int($_GET['page'] ?? 1, 1));
            $pageSize = min(100, max(1, evp_int($_GET['page_size'] ?? 20, 20)));
            $offset = ($page - 1) * $pageSize;
            $q = evp_text($_GET['q'] ?? '');
            $statusFilter = (string)($_GET['status'] ?? '');
            $where = ' WHERE 1=1 ';
            $params = array();
            if ($q !== '') {
                $where .= ' AND (effect_name LIKE CONCAT("%", ?, "%") OR purchase_id LIKE CONCAT("%", ?, "%")) ';
                $params[] = $q;
                $params[] = $q;
            }
            if ($statusFilter === '0' || $statusFilter === '1') {
                $where .= ' AND status = ? ';
                $params[] = $statusFilter;
            }
            $countRows = $db->query('SELECT COUNT(*) AS c FROM entrance_effect_products ' . $where, $params);
            $total = (int)($countRows[0]['c'] ?? 0);
            $rows = $db->query(
                'SELECT id, purchase_id, effect_name, effect_image_url, effect_animation_url,
                        CAST(price AS CHAR) AS price, valid_days, start_time, expire_time,
                        status, sort_order, created_at, updated_at
                   FROM entrance_effect_products ' . $where . '
                  ORDER BY sort_order ASC, id DESC
                  LIMIT ' . $offset . ', ' . $pageSize,
                $params
            );
            evp_ok(array('list' => $rows ?: array(), 'pagination' => array(
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_page' => (int)ceil($total / max(1, $pageSize))
            )));
            break;

        case 'detail':
            $id = evp_int($_GET['id'] ?? 0);
            if ($id <= 0) evp_bad('缺少商品ID');
            $rows = $db->query('SELECT * FROM entrance_effect_products WHERE id = ? LIMIT 1', array($id));
            if (!$rows) evp_bad('商品不存在', 404);
            evp_ok($rows[0]);
            break;

        case 'create':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evp_bad('请使用POST');
            $data = evp_product_payload(evp_payload());
            $exists = $db->query('SELECT id FROM entrance_effect_products WHERE purchase_id = ? LIMIT 1', array($data[0]));
            if ($exists) evp_bad('购买ID已存在');
            $db->beginTransaction();
            try {
                $db->query(
                    'INSERT INTO entrance_effect_products
                        (purchase_id, effect_name, effect_image_url, effect_animation_url,
                         price, valid_days, start_time, expire_time, status, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $data,
                    true
                );
                $id = (int)$db->getConnection()->insert_id;
                $db->commit();
                evp_ok(array('id' => $id), '新增成功');
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'update':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evp_bad('请使用POST');
            $payload = evp_payload();
            $id = evp_int($payload['id'] ?? 0);
            if ($id <= 0) evp_bad('缺少商品ID');
            $data = evp_product_payload($payload);
            $exists = $db->query('SELECT id FROM entrance_effect_products WHERE purchase_id = ? AND id <> ? LIMIT 1', array($data[0], $id));
            if ($exists) evp_bad('购买ID已被其他商品使用');
            $data[] = $id;
            $db->beginTransaction();
            try {
                $affected = $db->query(
                    'UPDATE entrance_effect_products SET
                        purchase_id = ?, effect_name = ?, effect_image_url = ?, effect_animation_url = ?,
                        price = ?, valid_days = ?, start_time = ?, expire_time = ?, status = ?, sort_order = ?
                     WHERE id = ?',
                    $data,
                    true
                );
                $db->commit();
                evp_ok(array('affected' => $affected), '保存成功');
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'toggle_status':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evp_bad('请使用POST');
            $payload = evp_payload();
            $id = evp_int($payload['id'] ?? 0);
            if ($id <= 0) evp_bad('缺少商品ID');
            $status = evp_flag($payload['status'] ?? 0);
            $affected = $db->query('UPDATE entrance_effect_products SET status = ? WHERE id = ?', array($status, $id), true);
            evp_ok(array('affected' => $affected), $status === 1 ? '已上架' : '已下架');
            break;

        case 'delete':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evp_bad('请使用POST');
            $payload = evp_payload();
            $id = evp_int($payload['id'] ?? 0);
            if ($id <= 0) evp_bad('缺少商品ID');
            $used = $db->query('SELECT COUNT(*) AS c FROM user_entrance_effect_purchases WHERE product_id = ?', array($id));
            if ((int)($used[0]['c'] ?? 0) > 0) evp_bad('该商品已有购买记录，不能删除，请改为下架');
            $affected = $db->query('DELETE FROM entrance_effect_products WHERE id = ?', array($id), true);
            evp_ok(array('affected' => $affected), '删除成功');
            break;

        default:
            evp_bad('未知操作');
    }
} catch (Throwable $e) {
    if (method_exists($db, 'logToFile')) $db->logToFile('[entry_vehicle_products] ' . $e->getMessage());
    evp_bad('服务器异常：' . $e->getMessage(), 500);
}