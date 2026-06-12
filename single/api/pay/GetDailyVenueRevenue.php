<?php
require_once '../Database.php';

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

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

$role_id = $user['role_id'];
$venue_id = $user['venue_id'];

// 获取请求参数
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 31;
$offset = ($page - 1) * $limit;

// 构建查询条件
$whereSql = "WHERE 1=1";
$params = [];

if ($role_id != 1) {
    // 如果不是管理员，则只返回当前绑定场地的数据
    $whereSql .= " AND venue_id = ?";
    $params[] = $venue_id;
}

// 查询总记录数
$countSql = "SELECT COUNT(*) as count FROM DailyVenueRevenue " . $whereSql;
$totalCount = $database->query($countSql, $params)[0]['count'] ?? 0;

// 查询每日交易额数据
$dataSql = "SELECT * FROM DailyVenueRevenue " . $whereSql . " ORDER BY date DESC LIMIT ?, ?";
$params[] = (int)$offset;
$params[] = (int)$limit;

$data = $database->query($dataSql, $params);

// 返回JSON数据
echo json_encode([
    'code' => 0,
    'msg' => '',
    'count' => $totalCount,
    'data' => $data
]);

$database->close();
