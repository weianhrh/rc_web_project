<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (!$sessionToken) {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['id'])) {
        respond(1001, '用户未登录或无权访问');
    }

    $isMcn = !empty($user['is_mcn']);
    $mcn = null;

    if ($isMcn) {
        $mcnRows = $database->query(
            "SELECT id, mcn_name FROM mcn_organizations WHERE id = ? AND status = 1 LIMIT 1",
            [(int)$user['mcn_id']]
        );
        $mcn = $mcnRows[0] ?? null;
        if (!$mcn) {
            respond(1003, 'MCN机构不存在或已停用');
        }

        $venues = $database->query(
            "SELECT v.id, v.venue_name, v.invite_code, mv.is_default
             FROM mcn_venues mv
             INNER JOIN venues v ON v.id = mv.venue_id
             WHERE mv.mcn_id = ? AND mv.status = 1
             ORDER BY mv.is_default DESC, v.id ASC",
            [(int)$user['mcn_id']]
        );
    } else {
        $venues = $database->query(
            "SELECT id, venue_name, invite_code, 1 AS is_default
             FROM venues WHERE id = ? LIMIT 1",
            [(int)$user['default_venue_id']]
        );
    }

    if ($venues === false) {
        respond(500, '获取可管理场地失败');
    }

    $defaultScope = $isMcn ? 'all' : 'single';
    $scope = ($_COOKIE['venue_scope'] ?? $defaultScope) === 'all' && $isMcn && count($venues) > 1
        ? 'all'
        : 'single';

    respond(0, 'ok', [
        'account_type' => $isMcn ? 'mcn' : 'single',
        'is_mcn' => $isMcn,
        'mcn_id' => $isMcn ? (int)$mcn['id'] : null,
        'mcn_name' => $isMcn ? $mcn['mcn_name'] : null,
        'can_edit_mcn_name' => $isMcn,
        'scope' => $scope,
        'current_venue_id' => (int)($user['venue_id'] ?? 0),
        'default_venue_id' => (int)($user['default_venue_id'] ?? $user['venue_id'] ?? 0),
        'can_view_all' => $isMcn && count($venues) > 1,
        'can_switch_venue' => $isMcn && count($venues) > 0,
        'venues' => array_map(function ($venue) {
            return [
                'id' => (int)$venue['id'],
                'venue_name' => $venue['venue_name'],
                'invite_code' => $venue['invite_code'],
                'is_default' => (int)$venue['is_default'],
            ];
        }, $venues),
    ]);
} catch (Throwable $e) {
    error_log('get_authorized_venues error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}

