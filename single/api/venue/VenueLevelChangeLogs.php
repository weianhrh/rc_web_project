<?php
declare(strict_types=1);

require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function jsonOut(int $code, string $msg, array $data = []): void
{
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function validDate(string $value): bool
{
    return $value === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        jsonOut(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || !in_array((int)($user['role_id'] ?? 0), [1, 2], true)) {
        jsonOut(1002, '当前账号无权查看等级变动记录');
    }

    $venueId = max(0, (int)($_GET['venue_id'] ?? 0));
    $source = trim((string)($_GET['source'] ?? ''));
    $startDate = trim((string)($_GET['start_date'] ?? ''));
    $endDate = trim((string)($_GET['end_date'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 30)));
    $offset = ($page - 1) * $pageSize;

    if (!in_array($source, ['', 'auto', 'manual'], true)) {
        jsonOut(400, '变动来源参数错误');
    }
    if (!validDate($startDate) || !validDate($endDate)) {
        jsonOut(400, '日期格式必须为YYYY-MM-DD');
    }

    $where = ['1=1'];
    $params = [];
    if ($venueId > 0) {
        $where[] = 'l.venue_id = ?';
        $params[] = $venueId;
    }
    if ($source !== '') {
        $where[] = 'l.change_source = ?';
        $params[] = $source;
    }
    if ($startDate !== '') {
        $where[] = 'l.created_at >= ?';
        $params[] = $startDate . ' 00:00:00';
    }
    if ($endDate !== '') {
        $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $endDate . ' 00:00:00';
    }
    $whereSql = implode(' AND ', $where);

    $totalRows = $database->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN l.new_level = 'C' AND l.old_level <> 'C' THEN 1 ELSE 0 END) AS downgrade_count,
            SUM(CASE WHEN l.new_level = 'A' AND l.old_level = 'C' THEN 1 ELSE 0 END) AS upgrade_count,
            SUM(CASE WHEN l.change_source = 'manual' THEN 1 ELSE 0 END) AS manual_count
         FROM venue_level_auto_change_logs l
         WHERE {$whereSql}",
        $params
    );
    if ($totalRows === false) {
        jsonOut(500, '等级记录汇总查询失败');
    }

    $rows = $database->query(
        "SELECT
            l.id, l.run_id, l.venue_id, v.venue_name, l.invite_code,
            l.old_level, l.new_level, l.change_source,
            l.operator_uid, l.operator_name,
            l.stat_start_date, l.stat_end_date, l.invite_count,
            l.reason, l.created_at
         FROM venue_level_auto_change_logs l
         LEFT JOIN venues v ON v.id = l.venue_id
         WHERE {$whereSql}
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );
    if ($rows === false) {
        jsonOut(500, '等级记录列表查询失败');
    }

    $venues = $database->query(
        "SELECT DISTINCT l.venue_id, COALESCE(v.venue_name, CONCAT('场地', l.venue_id)) AS venue_name
         FROM venue_level_auto_change_logs l
         LEFT JOIN venues v ON v.id = l.venue_id
         ORDER BY l.venue_id"
    );

    $summary = $totalRows[0] ?? [];
    jsonOut(0, 'ok', [
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'downgrade_count' => (int)($summary['downgrade_count'] ?? 0),
            'upgrade_count' => (int)($summary['upgrade_count'] ?? 0),
            'manual_count' => (int)($summary['manual_count'] ?? 0),
        ],
        'list' => $rows,
        'venues' => $venues ?: [],
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int)($summary['total'] ?? 0),
            'total_pages' => max(1, (int)ceil(((int)($summary['total'] ?? 0)) / $pageSize)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('VenueLevelChangeLogs error: ' . $e->getMessage());
    jsonOut(500, '服务器内部错误');
} finally {
    $database->close();
}
