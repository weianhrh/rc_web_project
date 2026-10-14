<?php
require_once '../Database.php'; // 引入数据库连接类

// 启用会话
session_start();

$database = new Database();
$sessionToken = $_COOKIE['session_token'] ?? null;
$currentUser = $sessionToken ? $database->getUserBySessionToken($sessionToken) : null;
if (!$currentUser || (int)$currentUser['role_id'] !== 1) {
    echo json_encode(['code' => 1003, 'msg' => '仅超级管理员可以创建后台账号', 'data' => []]);
    exit;
}

// 检查是否是POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取请求参数
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '4'; // 默认为普通用户

    if (!$username || !$password) {
        echo json_encode(['code' => 1, 'msg' => '用户名或密码不能为空', 'data' => []]);
        exit;
    }

    if (!in_array((int)$role, [1, 2, 3, 4], true)) {
        echo json_encode(['code' => 1, 'msg' => '无效的角色', 'data' => []]);
        exit;
    }

    // 检查用户名是否已存在
    $checkUserSql = "SELECT uid FROM admin_users WHERE username = ?";
    if ($database->query($checkUserSql, [$username])) {
        echo json_encode(['code' => 1, 'msg' => '用户名已存在', 'data' => []]);
        exit;
    }

    // 密码加密
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // 插入新用户数据
    $insertSql = "INSERT INTO admin_users (username, password, role_id) VALUES (?, ?, ?)";
    $params = [$username, $hashedPassword, $role];
    if ($database->query($insertSql, $params, true)) {
        echo json_encode(['code' => 0, 'msg' => '用户注册成功', 'data' => []]);
    } else {
        echo json_encode(['code' => 1, 'msg' => '用户注册失败', 'data' => []]);
    }

    // 关闭数据库连接
    $database->close();
} else {
    // 非POST请求处理
    echo json_encode(['code' => 1, 'msg' => '无效请求', 'data' => []]);
}
?>
