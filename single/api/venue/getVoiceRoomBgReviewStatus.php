<?php
// 查询当前场地的语音房背景图、最近审核状态与一周上传冷却。
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/_voice_room_bg_review.php';

function voiceBgStatusJson($code, $msg, $data = [], $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    voiceBgStatusJson(1000, '仅支持 GET 请求', [], 405);
}

try {
    $database = new Database();
    $sessionToken = isset($_COOKIE['session_token'])
        ? trim((string)$_COOKIE['session_token'])
        : '';
    if ($sessionToken === '') {
        voiceBgStatusJson(1001, '用户未登录或会话已过期');
    }

    $rows = $database->query(
        'SELECT uid, role_id, venue_id FROM admin_users WHERE session_token = ? LIMIT 1',
        [$sessionToken]
    );
    if (!$rows) {
        voiceBgStatusJson(1001, '用户未登录或会话已过期');
    }

    $user = $rows[0];
    $roleId = intval($user['role_id']);
    if (!in_array($roleId, [1, 2, 3], true)) {
        voiceBgStatusJson(1002, '当前账号无权查看语音房背景图审核状态');
    }

    $requestedVenueId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($roleId === 3) {
        $venueId = intval($user['venue_id']);
        if ($venueId <= 0) {
            voiceBgStatusJson(1003, '当前账号未绑定场地');
        }
        if ($requestedVenueId > 0 && $requestedVenueId !== $venueId) {
            voiceBgStatusJson(1003, '无权查看其他场地');
        }
    } else {
        $venueId = $requestedVenueId;
        if ($venueId <= 0) {
            voiceBgStatusJson(1003, '缺少有效的场地ID');
        }
    }

    $venueRows = $database->query(
        'SELECT id, voice_room_bg_url FROM venues WHERE id = ? LIMIT 1',
        [$venueId]
    );
    if (!$venueRows) {
        voiceBgStatusJson(1004, '场地不存在');
    }

    $store = new VoiceRoomBgReviewStore();
    $review = $store->getReview($venueId);
    if (is_array($review)) {
        $review['review_ttl'] = $store->getReviewTtl($venueId);
        if (!empty($review['pending_path'])) {
            $review['preview_url'] = '/api/venue/' .
                ltrim((string)$review['pending_path'], '/');
        }
    }
    $cooldown = $store->getCooldown($venueId);
    $currentUrl = (string)($venueRows[0]['voice_room_bg_url'] ?? '');

    $database->close();
    $store->close();

    voiceBgStatusJson(0, '获取成功', [
        'venue_id' => $venueId,
        'current_url' => $currentUrl,
        'review' => $review,
        'has_pending' => is_array($review) &&
            (($review['status'] ?? '') === 'pending'),
        'cooldown' => $cooldown
    ]);
} catch (Throwable $e) {
    error_log('getVoiceRoomBgReviewStatus failed: ' . $e->getMessage());
    voiceBgStatusJson(1500, '服务器处理失败，请稍后重试');
}

