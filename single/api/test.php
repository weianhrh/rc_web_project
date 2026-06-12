<?php
require_once 'Database.php';  // 确保路径正确

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
// var_dump($user);

    echo json_encode(['code' => 1, 'msg' => 'msg', 'data' => [$user]]);
?> 