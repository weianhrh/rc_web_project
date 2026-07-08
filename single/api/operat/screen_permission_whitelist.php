<?php
/**
 * /api/operat/screen_permission_whitelist.php
 *
 * open.rcwulian.cn 后台维护入口。
 * 注意：open 站点有 open_basedir，不能直接读 /www/wwwroot/rcwulian.cn 下的文件。
 * 所以这里先校验后台登录/角色，再通过服务端 HTTP 调用 rcwulian.cn 下面的受保护接口，
 * 实际白名单仍然只有一份：/www/wwwroot/rcwulian.cn/app/user/car/screen_permission_uids.txt
 */

require_once __DIR__ . '/_bootstrap.php';

// 和 rcwulian.cn/app/user/car/screen_permission_whitelist_api.php 保持一致。
// 建议上线后把这个密钥改成更长的随机字符串，两边必须完全相同。
if (!defined('SCREEN_PERMISSION_API_SECRET')) {
    define('SCREEN_PERMISSION_API_SECRET', 'RC_SCREEN_PERMISSION_SECRET_20260708_9f3d6e7a5b2c4a18');
}

// 实际写入 txt 的接口在 rcwulian.cn 站点内，避免 open_basedir 跨站目录限制。
if (!defined('SCREEN_PERMISSION_REMOTE_API')) {
    define('SCREEN_PERMISSION_REMOTE_API', 'https://rcwulian.cn/app/user/car/screen_permission_whitelist_api.php');
}

function screen_permission_request_data() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = [];
    }
    return array_merge($_GET ?: [], $_POST ?: [], $json);
}

function screen_permission_remote_call($action, $params = []) {
    $payload = $params;
    $payload['action'] = $action;

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        json_err('请求参数编码失败', 2001);
    }

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'X-Screen-Permission-Secret: ' . SCREEN_PERMISSION_API_SECRET,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init(SCREEN_PERMISSION_REMOTE_API);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        if ($resp === false || $errno) {
            json_err('调用 rcwulian 白名单接口失败：' . $error, 2002, [
                'remote_api' => SCREEN_PERMISSION_REMOTE_API,
                'curl_errno' => $errno,
            ]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            json_err('rcwulian 白名单接口 HTTP 异常：' . $httpCode, 2003, [
                'remote_api' => SCREEN_PERMISSION_REMOTE_API,
                'http_code' => $httpCode,
                'response' => mb_substr((string)$resp, 0, 300, 'UTF-8'),
            ]);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 10,
            ],
        ]);
        $resp = @file_get_contents(SCREEN_PERMISSION_REMOTE_API, false, $context);
        if ($resp === false) {
            json_err('调用 rcwulian 白名单接口失败：服务器未启用 curl，且 file_get_contents 远程请求失败', 2002, [
                'remote_api' => SCREEN_PERMISSION_REMOTE_API,
            ]);
        }
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json)) {
        json_err('rcwulian 白名单接口返回不是 JSON', 2004, [
            'remote_api' => SCREEN_PERMISSION_REMOTE_API,
            'response' => mb_substr((string)$resp, 0, 300, 'UTF-8'),
        ]);
    }

    return $json;
}

$db = new Database();
$loginUser = auth_or_die($db);
$roleId = intval($loginUser['role_id'] ?? 0);

// 截屏/录屏权限属于敏感配置，只允许平台/运营账号查看和维护。
if (!in_array($roleId, [1, 2], true)) {
    json_err('无权访问截屏录屏白名单', 1003, ['role_id' => $roleId]);
}

$req = screen_permission_request_data();
$action = strtolower(trim((string)($req['action'] ?? 'list')));
if ($action === '') {
    $action = 'list';
}

$allowedActions = ['list', 'add', 'save', 'delete', 'remove', 'check'];
if (!in_array($action, $allowedActions, true)) {
    json_err('未知操作', 1002);
}

$result = screen_permission_remote_call($action, $req);

// 透传 rcwulian.cn 接口结果，保持前端原格式。
echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;
