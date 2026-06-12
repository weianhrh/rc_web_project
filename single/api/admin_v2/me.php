<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once '../Database.php';

function out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionToken = $_COOKIE['session_token'] ?? '';

if (!$sessionToken) {
    out(1001, '未登录或会话已过期');
}

$database = new Database();
$user = $database->getUserBySessionToken($sessionToken);

if (!$user) {
    out(1001, '未登录或会话已过期');
}

$roleId = (int)($user['role_id'] ?? 0);

out(0, 'ok', [
    'uid'      => (int)($user['uid'] ?? 0),
    'username' => $user['username'] ?? $user['nickname'] ?? '',
    'role_id'  => $roleId,

    // 给 v3-admin-vite 用
    // 例如 role_id=1 => role_1
    'roles'    => ['role_' . $roleId],

    'venue_id' => $user['venue_id'] ?? null
]);