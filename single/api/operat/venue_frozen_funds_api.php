<?php
// /api/operat/venue_frozen_funds_api.php
// 场地资金冻结管理：只读取/解除 Redis DB1 中正在生效的 venue:{venue_id}:frozen:* 记录；日志读取 frozen_log.txt。

require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_request_data(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: [];
}

function format_money($value): string {
    return number_format((float)$value, 2, '.', '');
}

function format_ttl($seconds): string {
    $seconds = (int)$seconds;
    if ($seconds < 0) {
        return $seconds === -1 ? '永久' : '已过期';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    if ($days > 0) {
        return sprintf('%d天 %02d:%02d:%02d', $days, $hours, $minutes, $secs);
    }
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function parse_frozen_key(string $key): ?array {
    if (preg_match('/^venue:(\d+):frozen:(\d+)$/', $key, $m)) {
        return [
            'venue_id' => (int)$m[1],
            'ban_id'   => (int)$m[2]
        ];
    }
    return null;
}

function redis_scan_keys(RedisHelper $redis, string $pattern): array {
    if (method_exists($redis, 'scan')) {
        return $redis->scan($pattern, 300);
    }
    return $redis->getAllKeys($pattern);
}

function connect_frozen_redis(): RedisHelper {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(1); // 和 devMgtV2.php / ApplyWithdrawal.php 里的冻结读取一致，使用 DB1
    return $redis;
}

function get_user_scope(Database $database): array {
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        json_out(1001, '用户未登录或会话已过期', []);
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        json_out(1001, '用户未登录或无权访问', []);
    }

    $roleId = (int)$user['role_id'];
    if (!in_array($roleId, [1, 2, 3], true)) {
        json_out(1003, '权限不足', []);
    }

    return [
        'user' => $user,
        'role_id' => $roleId,
        'uid' => (int)($user['uid'] ?? 0),
        'user_venue_id' => (int)($user['venue_id'] ?? 0),
        'can_select_venue' => in_array($roleId, [1, 2], true)
    ];
}

function resolve_requested_venue_id(array $scope, $requestVenueId): int {
    if (!$scope['can_select_venue']) {
        return (int)$scope['user_venue_id'];
    }

    $venueId = (int)$requestVenueId;
    return $venueId > 0 ? $venueId : 0; // 0 = 全场地
}

function assert_venue_scope(array $scope, int $venueId): void {
    if ($venueId <= 0) {
        json_out(1, '场地ID无效', []);
    }

    if (!$scope['can_select_venue'] && $venueId !== (int)$scope['user_venue_id']) {
        json_out(1003, '只能操作自己场地的冻结记录', []);
    }
}

function get_venues(Database $database, array $scope): array {
    if ($scope['can_select_venue']) {
        $rows = $database->query("SELECT id, venue_name FROM venues ORDER BY id ASC");
    } else {
        $rows = $database->query("SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1", [$scope['user_venue_id']]);
    }

    $list = [];
    foreach ($rows ?: [] as $row) {
        $list[] = [
            'id' => (int)($row['id'] ?? 0),
            'venue_name' => (string)($row['venue_name'] ?? '')
        ];
    }
    return $list;
}

function get_venue_map(Database $database, array $scope): array {
    $map = [];
    foreach (get_venues($database, $scope) as $venue) {
        $map[(int)$venue['id']] = $venue['venue_name'];
    }
    return $map;
}

function get_ban_map(Database $database, array $banIds): array {
    $banIds = array_values(array_unique(array_filter(array_map('intval', $banIds))));
    if (empty($banIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($banIds), '?'));
    $sql = "SELECT id, venue_id, ban_reason, ban_end_time, created_at, status FROM venue_bans WHERE id IN ($placeholders)";
    $rows = $database->query($sql, $banIds);

    $map = [];
    foreach ($rows ?: [] as $row) {
        $map[(int)$row['id']] = $row;
    }
    return $map;
}

function get_frozen_records(Database $database, array $scope, int $venueId = 0): array {
    $records = [];
    $total = 0.0;
    $pattern = $venueId > 0 ? "venue:{$venueId}:frozen:*" : 'venue:*:frozen:*';
    $venueMap = get_venue_map($database, $scope);

    $rawItems = [];
    $banIds = [];

    $redis = connect_frozen_redis();
    try {
        $keys = redis_scan_keys($redis, $pattern);
        sort($keys, SORT_NATURAL);

        foreach ($keys as $key) {
            $key = (string)$key;
            $parsed = parse_frozen_key($key);
            if (!$parsed) {
                continue;
            }

            $keyVenueId = (int)$parsed['venue_id'];
            if (!$scope['can_select_venue'] && $keyVenueId !== (int)$scope['user_venue_id']) {
                continue;
            }

            $rawValue = $redis->get($key);
            if ($rawValue === false || $rawValue === null || $rawValue === '') {
                continue;
            }

            $amount = (float)$rawValue;
            $ttl = (int)$redis->ttl($key);
            $total += $amount;
            $banId = (int)$parsed['ban_id'];
            $banIds[] = $banId;

            $rawItems[] = [
                'key' => $key,
                'venue_id' => $keyVenueId,
                'venue_name' => $venueMap[$keyVenueId] ?? '',
                'ban_id' => $banId,
                'amount' => round($amount, 2),
                'amount_text' => format_money($amount),
                'ttl' => $ttl,
                'ttl_text' => format_ttl($ttl),
            ];
        }
    } finally {
        $redis->close();
    }

    $banMap = get_ban_map($database, $banIds);
    foreach ($rawItems as $item) {
        $ban = $banMap[(int)$item['ban_id']] ?? [];
        $item['ban_reason'] = (string)($ban['ban_reason'] ?? '');
        $item['ban_created_at'] = (string)($ban['created_at'] ?? '');
        $item['ban_end_time'] = (string)($ban['ban_end_time'] ?? '');
        $item['ban_status'] = isset($ban['status']) ? (int)$ban['status'] : null;
        $records[] = $item;
    }

    usort($records, function ($a, $b) {
        if ($a['venue_id'] === $b['venue_id']) {
            return $b['ban_id'] <=> $a['ban_id'];
        }
        return $a['venue_id'] <=> $b['venue_id'];
    });

    return [
        'records' => $records,
        'total_amount' => round($total, 2),
        'total_amount_text' => format_money($total),
        'count' => count($records),
        'pattern' => $pattern
    ];
}

function resolve_frozen_log_path(): string {
    // devMgtV2.php 里 logMessage_frozen 使用 __DIR__ . '/frozen_log.txt'。
    // 如果本接口放在 /api/operat，旧日志通常在 /api/vehicle/frozen_log.txt 或 /api/devMgr/frozen_log.txt。
    $candidates = [
        dirname(__DIR__) . '/vehicle/frozen_log.txt',
        dirname(__DIR__) . '/devMgr/frozen_log.txt',
        __DIR__ . '/frozen_log.txt',
        dirname(__DIR__) . '/frozen_log.txt',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return dirname(__DIR__) . '/vehicle/frozen_log.txt';
}

function parse_frozen_log_line(string $line): ?array {
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $time = '';
    $body = $line;
    if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $line, $m)) {
        $time = $m[1];
        $body = $m[2];
    }

    $status = 'other';
    $statusText = '其他';
    if (mb_strpos($body, '解除') !== false || mb_strpos($body, '释放') !== false || mb_strpos($body, '解冻') !== false) {
        $status = 'released';
        $statusText = '已解除';
    } elseif (mb_strpos($body, '未冻结') !== false) {
        $status = 'no_freeze';
        $statusText = '未冻结';
    } elseif (mb_strpos($body, '冻结成功') !== false || mb_strpos($body, 'venue freeze') !== false) {
        $status = 'frozen';
        $statusText = '冻结成功';
    }

    $venueId = 0;
    $banId = 0;
    $amount = null;
    $ttl = null;
    $banSeconds = null;
    $key = '';

    if (preg_match('/venue_id=(\d+)/i', $body, $m)) {
        $venueId = (int)$m[1];
    }
    if (preg_match('/ban_id=(\d+)/i', $body, $m)) {
        $banId = (int)$m[1];
    }
    if (preg_match('/(?:frozen_amount|frozenAmount|amount)=([0-9]+(?:\.[0-9]+)?)/i', $body, $m)) {
        $amount = (float)$m[1];
    }
    if (preg_match('/ttl=(-?\d+)/i', $body, $m)) {
        $ttl = (int)$m[1];
    }
    if (preg_match('/banSeconds=(\d+)/i', $body, $m)) {
        $banSeconds = (int)$m[1];
    }
    if (preg_match('/(?:key|redisKey)=([^,\s]+)/i', $body, $m)) {
        $key = trim($m[1]);
        if ($key === 'null') {
            $key = '';
        }
    }

    return [
        'time' => $time,
        'status' => $status,
        'status_text' => $statusText,
        'venue_id' => $venueId,
        'ban_id' => $banId,
        'amount' => $amount,
        'amount_text' => $amount === null ? '-' : format_money($amount),
        'ttl' => $ttl,
        'ttl_text' => $ttl === null ? '-' : format_ttl($ttl),
        'ban_seconds' => $banSeconds,
        'ban_seconds_text' => $banSeconds === null ? '-' : format_ttl($banSeconds),
        'key' => $key,
        'raw' => $line
    ];
}

function read_frozen_logs(Database $database, array $scope, int $venueId = 0, string $keyword = '', int $limit = 300): array {
    $path = resolve_frozen_log_path();
    if (!is_file($path)) {
        return [
            'path' => $path,
            'exists' => false,
            'records' => [],
            'count' => 0,
            'message' => '未找到 frozen_log.txt'
        ];
    }

    $limit = max(1, min(1000, $limit));
    $maxReadLines = 5000;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        $lines = [];
    }
    if (count($lines) > $maxReadLines) {
        $lines = array_slice($lines, -$maxReadLines);
    }
    $lines = array_reverse($lines); // 最新在前

    $venueMap = get_venue_map($database, $scope);
    $records = [];
    $keyword = trim($keyword);

    foreach ($lines as $line) {
        $item = parse_frozen_log_line($line);
        if (!$item) {
            continue;
        }

        // 记录页不展示“未冻结”的条目，只看真正冻结成功和手动解除冻结记录。
        if (($item['status'] ?? '') === 'no_freeze') {
            continue;
        }

        $itemVenueId = (int)$item['venue_id'];
        if (!$scope['can_select_venue']) {
            if ($itemVenueId !== (int)$scope['user_venue_id']) {
                continue;
            }
        } elseif ($venueId > 0 && $itemVenueId !== $venueId) {
            continue;
        }

        if ($keyword !== '' && mb_stripos($item['raw'], $keyword) === false) {
            continue;
        }

        $item['venue_name'] = $venueMap[$itemVenueId] ?? '';
        $records[] = $item;

        if (count($records) >= $limit) {
            break;
        }
    }

    return [
        'path' => $path,
        'exists' => true,
        'records' => $records,
        'count' => count($records),
        'limit' => $limit
    ];
}

function append_frozen_admin_log(string $message): void {
    $path = resolve_frozen_log_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (!is_writable($dir)) {
        $path = __DIR__ . '/frozen_log.txt';
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

try {
    $database = new Database();
    $scope = get_user_scope($database);
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === '' || $action === 'summary') {
        $venueId = resolve_requested_venue_id($scope, $_GET['venue_id'] ?? 0);
        $freezeData = get_frozen_records($database, $scope, $venueId);
        $venues = get_venues($database, $scope);

        json_out(0, '成功', [
            'user' => [
                'role_id' => $scope['role_id'],
                'uid' => $scope['uid'],
                'venue_id' => $scope['user_venue_id'],
                'can_select_venue' => $scope['can_select_venue']
            ],
            'selected_venue_id' => $venueId,
            'venues' => $venues,
            'freeze' => $freezeData
        ]);
    }

    if ($action === 'logs') {
        $venueId = resolve_requested_venue_id($scope, $_GET['venue_id'] ?? 0);
        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 300);
        $venues = get_venues($database, $scope);
        $logs = read_frozen_logs($database, $scope, $venueId, $keyword, $limit);

        json_out(0, '成功', [
            'user' => [
                'role_id' => $scope['role_id'],
                'uid' => $scope['uid'],
                'venue_id' => $scope['user_venue_id'],
                'can_select_venue' => $scope['can_select_venue']
            ],
            'selected_venue_id' => $venueId,
            'venues' => $venues,
            'logs' => $logs
        ]);
    }

    if ($action === 'unfreeze') {
        $input = read_request_data();
        $key = trim((string)($input['key'] ?? ''));
        $parsed = parse_frozen_key($key);
        if (!$parsed) {
            json_out(1, 'Redis Key格式不正确', []);
        }

        $targetVenueId = (int)$parsed['venue_id'];
        $targetBanId = (int)$parsed['ban_id'];
        assert_venue_scope($scope, $targetVenueId);

        $amountBefore = null;
        $ttlBefore = null;
        $deleted = 0;

        $redis = connect_frozen_redis();
        try {
            $rawValue = $redis->get($key);
            if ($rawValue !== false && $rawValue !== null && $rawValue !== '') {
                $amountBefore = (float)$rawValue;
                $ttlBefore = (int)$redis->ttl($key);
            }
            $deleted = (int)$redis->delete($key);
        } finally {
            $redis->close();
        }

        append_frozen_admin_log(
            '场地资金手动解除冻结 venue_id=' . $targetVenueId .
            ', ban_id=' . $targetBanId .
            ', amount=' . ($amountBefore === null ? '-' : format_money($amountBefore)) .
            ', ttl=' . ($ttlBefore === null ? '-' : $ttlBefore) .
            ', key=' . $key .
            ', deleted=' . $deleted .
            ', operator_uid=' . $scope['uid'] .
            ', role_id=' . $scope['role_id']
        );

        $freshData = get_frozen_records($database, $scope, $scope['can_select_venue'] ? (int)($_GET['venue_id'] ?? 0) : $scope['user_venue_id']);

        json_out(0, $deleted > 0 ? '解除冻结成功' : '该冻结记录已不存在', [
            'deleted' => $deleted,
            'key' => $key,
            'venue_id' => $targetVenueId,
            'ban_id' => $targetBanId,
            'freeze' => $freshData
        ]);
    }

    json_out(1002, '未知操作', []);
} catch (Exception $e) {
    json_out(500, $e->getMessage(), []);
}
