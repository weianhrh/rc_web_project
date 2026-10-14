<?php
// api/venue/getVenueEditLock.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../RedisHelper.php';
require_once './_venue_locks.php';

$database = new Database();

// 登录校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code'=>1001,'msg'=>'无有效认证信息，请登录','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user) {
    echo json_encode(['code'=>1001,'msg'=>'用户未登录或不存在','data'=>[]], JSON_UNESCAPED_UNICODE); 
    $database->close(); exit;
}

$role_id      = (int)$user['role_id'];
$operator_uid = (int)$user['uid'];
// 传了 id 就用传入的；否则用自己场地
$venue_id = isset($_GET['id']) ? intval($_GET['id']) : (int)$user['venue_id'];
if ($venue_id <= 0) {
    echo json_encode(['code'=>1002,'msg'=>'缺少场地ID','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}
// 非超管不允许看别的场地锁
if (!in_array($role_id, [1, 2], true) && isset($_GET['id']) && intval($_GET['id']) !== (int)$user['venue_id']) {
    echo json_encode(['code'=>1003,'msg'=>'权限不足','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}

$locks = new VenueLocks();
// ✅ 管理员：返回 is_admin=true，并把锁置空（前端自然不会禁用）
if ($role_id === 1 || $role_id === 2) {
    echo json_encode([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'role_id'    => $role_id,
            'is_admin'   => true,
            // 'name_lock'  => null,
            // 'image_lock' => null,
                        'name_lock'  => $locks->get('name',  $venue_id),
            'image_lock' => $locks->get('image', $venue_id),
        ]
    ], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}
// ❗普通用户：照常返回真实锁信息
echo json_encode([
    'code' => 0,
    'msg'  => 'ok',
    'data' => [
        'role_id' => $role_id,
        'name_lock'  => $locks->get('name',  $venue_id),
        'image_lock' => $locks->get('image', $venue_id),
    ]
], JSON_UNESCAPED_UNICODE);

$database->close();
