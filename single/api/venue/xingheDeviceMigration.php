<?php
require_once __DIR__ . '/xinghe_migration_common.php';
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') xhReply(405, '仅支持POST请求');
    $session = $_COOKIE['session_token'] ?? '';
    if (!is_string($session) || $session === '') xhReply(1001, '请先登录RC后台');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0 || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') xhReply(403, '请从RC后台页面提交');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) xhReply(413, '请求内容过长');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !in_array($input['action'] ?? '', ['status','backfill'], true)) xhReply(400, '操作无效');
    require_once __DIR__ . '/../Database.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = (new Database())->getConnection(); $conn->set_charset('utf8mb4');
    $columns = xhColumns($conn, 'admin_users');
    $expiry = isset($columns['session_expires']) ? ' AND session_expires>NOW()' : '';
    $admins = xhQuery($conn, 'SELECT id,role_id FROM admin_users WHERE session_token=?' . $expiry . ' LIMIT 1', [$session]);
    if (!$admins) xhReply(1001, '登录已过期');
    if (!in_array((int)$admins[0]['role_id'], [1,2], true)) xhReply(403, '仅平台管理员可核对设备搬迁标识');
    if ($input['action'] === 'status') xhReply(0, '搬迁标识已读取', ['rows' => xhmStatusRows($conn, $input['serial_numbers'] ?? null)]);
    $data = xhmBackfillPage($conn, $input['cursor'] ?? '0');
    xhReply(0, $data['done'] ? '本轮历史搬迁核对完成' : '本页历史搬迁核对完成', $data);
} catch (JsonException $e) {
    xhReply(400, '请求JSON格式错误');
} catch (Throwable $e) {
    if ($conn) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    error_log('RC device migration evidence: ' . $e->getMessage());
    xhReply(500, $e instanceof mysqli_sql_exception ? '搬迁标识数据库处理失败，本页未保存，请检查服务器日志及表结构' : $e->getMessage());
}
