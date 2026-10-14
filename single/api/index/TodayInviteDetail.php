<?php
declare(strict_types=1);

require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function respond(int $code, string $msg, array $data = []): void
{
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        respond(1001, '用户未登录或无权访问');
    }

    $venueId = (int)($user['venue_id'] ?? 0);
    if ($venueId <= 0) {
        respond(400, '请先切换到具体场地');
    }

    $venueRows = $database->query(
        "SELECT id, venue_name, invite_code FROM venues WHERE id = ? LIMIT 1",
        [$venueId]
    );
    if (!$venueRows) {
        respond(404, '场地不存在');
    }

    $venue = $venueRows[0];
    $inviteCode = trim((string)($venue['invite_code'] ?? ''));
    if ($inviteCode === '' || $inviteCode === '0000') {
        respond(0, 'ok', [
            'venue_id' => $venueId,
            'venue_name' => $venue['venue_name'],
            'invite_code' => $inviteCode,
            'count' => 0,
            'list' => [],
        ]);
    }

    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $rows = $database->query(
        "SELECT uid, nickname, platform_type, created_at
         FROM users
         WHERE invitation_code = ?
           AND created_at >= ?
           AND created_at < ?
         ORDER BY created_at DESC, uid DESC
         LIMIT 300",
        [$inviteCode, $todayStart, $tomorrowStart]
    );
    if ($rows === false) {
        respond(500, '今日引流明细查询失败');
    }

    respond(0, 'ok', [
        'venue_id' => $venueId,
        'venue_name' => $venue['venue_name'],
        'invite_code' => $inviteCode,
        'count' => count($rows),
        'list' => array_map(static function (array $row): array {
            return [
                'uid' => (int)$row['uid'],
                'nickname' => (string)($row['nickname'] ?? ''),
                'platform_type' => (string)($row['platform_type'] ?? ''),
                'created_at' => (string)$row['created_at'],
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    error_log('TodayInviteDetail error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}
