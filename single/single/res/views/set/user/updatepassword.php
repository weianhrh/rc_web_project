<?php
require_once '../../Database.php';  // 确保路径正确

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

// 检查是否是POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取请求参数
    $oldPassword = $_POST['oldPassword'] ?? '';
    $newPassword = $_POST['password'] ?? '';
    $repassword = $_POST['repassword'] ?? '';

    // 验证参数
    if (!$oldPassword || !$newPassword || !$repassword) {
        echo json_encode(['code' => 1, 'msg' => '所有密码字段都不能为空', 'data' => []]);
        exit;
    }

    if ($newPassword !== $repassword) {
        echo json_encode(['code' => 1, 'msg' => '新密码和确认密码不匹配', 'data' => []]);
        exit;
    }

    // 验证旧密码
    $checkPasswordSql = "SELECT password FROM admin_users WHERE uid = ?";
    $result = $database->query($checkPasswordSql, [$user['uid']]);
    $userData = $result[0] ?? null;

    if (!$userData || !password_verify($oldPassword, $userData['password'])) {
        echo json_encode(['code' => 1, 'msg' => '当前密码错误', 'data' => []]);
        exit;
    }

    // 更新密码
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateSql = "UPDATE admin_users SET password = ? WHERE uid = ?";
    if ($database->query($updateSql, [$hashedPassword, $user['uid']], true)) {
        echo json_encode(['code' => 0, 'msg' => '密码修改成功', 'data' => []]);
    } else {
        echo json_encode(['code' => 1, 'msg' => '密码修改失败', 'data' => []]);
    }

    // 关闭数据库连接
    $database->close();
} else {
    // 非POST请求处理
    echo json_encode(['code' => 1, 'msg' => '无效请求', 'data' => []]);
}
?> 