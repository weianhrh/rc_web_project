<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// 获取当前用户
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户无效或无权限', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 判断是否为管理员
$role_id = (int)$user['role_id'];
$username = trim($user['username'] ?? '');

/**
 * 针对指定后台账号隐藏 2026 年 7 月之前的投诉处理记录数据
 * 账号：cc0123456
 */
$limitUsers = ['cc0123456'];
$visibleFrom = '2026-07-01 00:00:00';
$isDateLimitedUser = in_array($username, $limitUsers, true);

if ($role_id == 1 || $role_id == 2) {
    // 管理员：使用 GET 提供的 venue_id
    $venue_id = $_GET['venue_id'] ?? null;
    if (!$venue_id || !is_numeric($venue_id)) {
        echo json_encode(['code' => 1002, 'msg' => '缺少或无效的场地ID', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // 普通用户：强制使用用户自己的 venue_id
    $venue_id = $user['venue_id'];
    if (!$venue_id) {
        echo json_encode(['code' => 1003, 'msg' => '用户未绑定场地', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 查询 Reports + vehicles（根据场地筛选）
$sql = "
    SELECT r.*, v.bind_site
    FROM Reports r
    JOIN vehicles v ON r.device_id = v.serial_number
    WHERE v.bind_site = ?
";
$params = [$venue_id];

// 指定账号只能看 2026-07-01 之后的投诉处理记录
if ($isDateLimitedUser) {
    $sql .= " AND r.insert_time >= ? ";
    $params[] = $visibleFrom;
}

$sql .= " ORDER BY r.insert_time DESC";

$reports = $database->query($sql, $params) ?: [];

$database->close();

// 返回数据
echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'data' => $reports,
    'visible_from' => $isDateLimitedUser ? $visibleFrom : ''
], JSON_UNESCAPED_UNICODE);
