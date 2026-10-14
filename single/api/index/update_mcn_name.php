<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, '仅支持POST请求');
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (!$sessionToken) {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['is_mcn']) || empty($user['mcn_id'])) {
        respond(1003, '仅MCN账号可以修改机构名称');
    }

    $name = trim((string)($_POST['mcn_name'] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length < 2 || $length > 100) {
        respond(1004, 'MCN名称长度应为2至100个字符');
    }

    $updated = $database->query(
        "UPDATE mcn_organizations SET mcn_name = ?, updated_at = NOW() WHERE id = ? AND status = 1",
        [$name, (int)$user['mcn_id']],
        true
    );
    if ($updated === false) {
        respond(500, '修改MCN名称失败');
    }

    respond(0, '修改成功', ['mcn_name' => $name]);
} catch (Throwable $e) {
    error_log('update_mcn_name error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}
