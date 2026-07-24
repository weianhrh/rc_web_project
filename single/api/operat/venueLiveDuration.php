<?php
// /api/operat/venueLiveDuration.php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$database = new Database();

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_valid_stat_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }

    return checkdate(
        intval(substr($date, 5, 2)),
        intval(substr($date, 8, 2)),
        intval(substr($date, 0, 4))
    );
}

function normalize_live_state($row) {
    if (isset($row['remark']) && (string)$row['remark'] === '系统关播') {
        return 0;
    }

    if (array_key_exists('new_is_live', $row) && $row['new_is_live'] !== null) {
        $newState = (string)$row['new_is_live'];
        if ($newState === '0' || $newState === '1') {
            return intval($newState);
        }
    }

    if (array_key_exists('action', $row) && $row['action'] !== null) {
        $action = (string)$row['action'];
        if ($action === '0' || $action === '1') {
            return intval($action);
        }
    }

    return null;
}

function has_index_prefix($indexRows, $expectedColumns) {
    $indexes = [];

    foreach ($indexRows as $row) {
        $indexName = isset($row['INDEX_NAME']) ? $row['INDEX_NAME'] : ($row['Index_name'] ?? '');
        $sequence = intval(isset($row['SEQ_IN_INDEX']) ? $row['SEQ_IN_INDEX'] : ($row['Seq_in_index'] ?? 0));
        $columnName = isset($row['COLUMN_NAME']) ? $row['COLUMN_NAME'] : ($row['Column_name'] ?? '');

        if ($indexName === '' || $sequence <= 0 || $columnName === '') {
            continue;
        }

        $indexes[$indexName][$sequence] = strtolower((string)$columnName);
    }

    foreach ($indexes as $columns) {
        ksort($columns);
        $orderedColumns = array_values($columns);
        $matched = true;

        foreach ($expectedColumns as $position => $expectedColumn) {
            if (!isset($orderedColumns[$position]) || $orderedColumns[$position] !== strtolower($expectedColumn)) {
                $matched = false;
                break;
            }
        }

        if ($matched) {
            return true;
        }
    }

    return false;
}

// 会话校验
$sessionToken = $_COOKIE['session_token'] ?? '';
if (!$sessionToken) {
    json_out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($sessionToken);
if (!$user || empty($user['role_id'])) {
    json_out(1001, '用户未登录或无权访问');
}

$roleId = intval($user['role_id']);
$userVenueId = intval($user['venue_id'] ?? 0);

// role_id=1/2 可筛选全部场地；role_id=3/4 只能查询账号绑定场地。
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    json_out(403, '当前角色无权查看开播统计');
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_today_live_duration');

// 场地下拉只向 role_id=1/2 提供，避免 role_id=3/4 获取全部场地列表。
if ($action === 'get_venues') {
    if (!in_array($roleId, [1, 2], true)) {
        json_out(403, '当前角色无权获取场地列表');
    }

    $venues = $database->query(
        'SELECT id, venue_name FROM venues ORDER BY id ASC'
    );
    if ($venues === false) {
        json_out(500, '场地列表查询失败');
    }

    json_out(0, 'success', $venues);
}

if (!in_array($action, ['get_today_live_duration', 'get_live_duration'], true)) {
    json_out(400, '无效操作');
}

$nowTimestamp = time();
$today = date('Y-m-d', $nowTimestamp);

/*
 * 日期参数兼容规则：
 * 1. 优先使用 startday / endday；
 * 2. 兼容旧 start_date / end_date；
 * 3. 日期范围两项必须同时传入；
 * 4. 两项都未传时，如果传了旧 date，则按单日范围处理；
 * 5. 所有日期参数都未传时，默认统计当天。
 */
$startDateParam = $_POST['startday']
    ?? ($_GET['startday']
    ?? ($_POST['start_date']
    ?? ($_GET['start_date'] ?? null)));
$endDateParam = $_POST['endday']
    ?? ($_GET['endday']
    ?? ($_POST['end_date']
    ?? ($_GET['end_date'] ?? null)));
$legacyDateParam = $_POST['date'] ?? ($_GET['date'] ?? null);

if (($startDateParam === null) !== ($endDateParam === null)) {
    json_out(422, '开始日期和结束日期必须同时传入');
}

if ($startDateParam === null && $endDateParam === null && $legacyDateParam !== null) {
    $startDateParam = $legacyDateParam;
    $endDateParam = $legacyDateParam;
}

$startDate = $startDateParam === null
    ? $today
    : (is_string($startDateParam) ? trim($startDateParam) : '');
$endDate = $endDateParam === null
    ? $today
    : (is_string($endDateParam) ? trim($endDateParam) : '');

if (!is_valid_stat_date($startDate)) {
    json_out(422, 'startday参数错误，请使用YYYY-MM-DD格式');
}

if (!is_valid_stat_date($endDate)) {
    json_out(422, 'endday参数错误，请使用YYYY-MM-DD格式');
}

if ($endDate > $today) {
    json_out(422, '结束日期不能是未来日期');
}

if ($startDate > $endDate) {
    json_out(422, '开始日期不能晚于结束日期');
}

$statTimezone = new DateTimeZone('Asia/Shanghai');
$rangeStartObject = new DateTimeImmutable($startDate . ' 00:00:00', $statTimezone);
$endDayStartObject = new DateTimeImmutable($endDate . ' 00:00:00', $statTimezone);
$nextDayStartObject = $endDayStartObject->modify('+1 day');

$rangeStart = $rangeStartObject->format('Y-m-d H:i:s');
$rangeEndInclusive = $endDate . ' 23:59:59';
// SQL 使用半开区间 [startday 00:00:00, endday次日00:00:00)，
// 等效包含 endday 23:59:59，并且不会漏掉带毫秒的记录。
$rangeEndExclusive = $nextDayStartObject->format('Y-m-d H:i:s');
$rangeStartTimestamp = $rangeStartObject->getTimestamp();
$rangeEndTimestamp = $nextDayStartObject->getTimestamp();
$calculationEndTimestamp = min($nowTimestamp, $rangeEndTimestamp);

$rangeDays = intval(($rangeEndTimestamp - $rangeStartTimestamp) / 86400);
if ($rangeDays > 366) {
    json_out(422, '单次最多查询366天，请缩小日期范围');
}

// role_id=3/4 必须使用会话账号绑定场地，完全忽略客户端传入的 venue_id。
if (in_array($roleId, [3, 4], true)) {
    if ($userVenueId <= 0) {
        json_out(403, '当前账号未绑定场地');
    }
    $venueId = $userVenueId;
} else {
    $venueIdParam = $_POST['venue_id'] ?? ($_GET['venue_id'] ?? '0');
    if (!is_string($venueIdParam) && !is_int($venueIdParam)) {
        json_out(422, 'venue_id参数错误');
    }

    $venueIdText = trim((string)$venueIdParam);
    if ($venueIdText !== '' && $venueIdText !== '0' && !ctype_digit($venueIdText)) {
        json_out(422, 'venue_id参数错误');
    }
    $venueId = ($venueIdText === '' || $venueIdText === '0')
        ? 0
        : intval($venueIdText);
}

try {
    /*
     * 旧实现会扫描全部历史开播记录，并对每一行再次反查上一状态和下一关播，
     * 默认“全部场地”时会形成近似 N^2 的慢查询。
     *
     * 新实现只读取：
     * 1. 每个目标场地在 rangeStart 之前的最后一个有效状态；
     * 2. [rangeStart, rangeEnd) 内的有效状态日志；
     * 3. 在 PHP 中用一次状态机合并重复开播/关播并生成区间。
     */

    @set_time_limit(25);
    $connection = method_exists($database, 'getConnection') ? $database->getConnection() : null;
    if ($connection instanceof mysqli) {
        // MySQL 5.7.8+ 使用毫秒；MariaDB 使用秒。旧版本不支持时忽略即可。
        @mysqli_query($connection, 'SET SESSION MAX_EXECUTION_TIME = 15000');
        @mysqli_query($connection, 'SET SESSION max_statement_time = 15');
    }

    // 缺少统计索引时直接返回明确错误，避免再次把 PHP-FPM/MySQL 拖到 502。
    $indexRows = $database->query(
        "SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'venue_live_status_logs'
         ORDER BY INDEX_NAME, SEQ_IN_INDEX"
    );

    if ($indexRows !== false) {
        /*
         * 查询真正需要的索引前缀是：
         *   1. 指定场地：venue_id, created_at
         *   2. 全部场地：created_at
         *
         * InnoDB 的普通二级索引会在内部携带主键 id，因此不要求用户再创建
         * (venue_id, created_at, id) / (created_at, id, venue_id) 这类重复索引。
         * 即使是其他存储引擎，现有前缀也能完成日期范围过滤，少量结果排序不会
         * 退化为旧版逐行反查的 N² 慢查询。
         */
        $hasVenueTimeIndex = has_index_prefix($indexRows, ['venue_id', 'created_at']);
        $hasTimeIndex = has_index_prefix($indexRows, ['created_at']);

        if (!$hasVenueTimeIndex || ($venueId === 0 && !$hasTimeIndex)) {
            json_out(503, '开播统计索引未安装，请先执行随包提供的索引SQL');
        }
    }

    $validStateSql = "(
        %s.remark = '系统关播'
        OR %s.new_is_live IN (0, 1)
        OR (%s.new_is_live IS NULL AND %s.action IN (0, 1))
    )";

    $stateSelectSql = "CASE
        WHEN %s.remark = '系统关播' THEN 0
        WHEN %s.new_is_live IN (0, 1) THEN %s.new_is_live
        WHEN %s.new_is_live IS NULL AND %s.action IN (0, 1) THEN %s.action
        ELSE NULL
    END";

    $anchorStateSql = sprintf($stateSelectSql, 'l', 'l', 'l', 'l', 'l', 'l');
    $anchorValidSql = sprintf($validStateSql, 's', 's', 's', 's');

    if ($venueId > 0) {
        $anchorSql = "
            SELECT /*+ MAX_EXECUTION_TIME(5000) */
                l.id,
                l.venue_id,
                IFNULL(v.venue_name, '') AS venue_name,
                l.action,
                l.new_is_live,
                l.remark,
                l.created_at,
                $anchorStateSql AS live_state
            FROM venue_live_status_logs l
            LEFT JOIN venues v ON v.id = l.venue_id
            WHERE l.venue_id = ?
              AND l.created_at < ?
              AND " . sprintf($validStateSql, 'l', 'l', 'l', 'l') . "
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 1
        ";
        $anchorRows = $database->query($anchorSql, [$venueId, $rangeStart]);
    } else {
        $anchorSql = "
            SELECT /*+ MAX_EXECUTION_TIME(5000) */
                l.id,
                l.venue_id,
                IFNULL(v.venue_name, '') AS venue_name,
                l.action,
                l.new_is_live,
                l.remark,
                l.created_at,
                $anchorStateSql AS live_state
            FROM venues v
            INNER JOIN venue_live_status_logs l ON l.id = (
                SELECT s.id
                FROM venue_live_status_logs s
                WHERE s.venue_id = v.id
                  AND s.created_at < ?
                  AND $anchorValidSql
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT 1
            )
            ORDER BY l.venue_id ASC
        ";
        $anchorRows = $database->query($anchorSql, [$rangeStart]);
    }

    if ($anchorRows === false) {
        json_out(500, '查询统计起始状态失败');
    }

    $eventStateSql = sprintf($stateSelectSql, 'l', 'l', 'l', 'l', 'l', 'l');
    $eventValidSql = sprintf($validStateSql, 'l', 'l', 'l', 'l');
    $eventVenueWhere = '';
    $eventParams = [$rangeStart, $rangeEndExclusive];

    if ($venueId > 0) {
        $eventVenueWhere = ' AND l.venue_id = ? ';
        $eventParams[] = $venueId;
    }

    $eventSql = "
        SELECT /*+ MAX_EXECUTION_TIME(10000) */
            l.id,
            l.venue_id,
            IFNULL(v.venue_name, '') AS venue_name,
            l.action,
            l.new_is_live,
            l.remark,
            l.created_at,
            $eventStateSql AS live_state
        FROM venue_live_status_logs l
        LEFT JOIN venues v ON v.id = l.venue_id
        WHERE l.created_at >= ?
          AND l.created_at < ?
          AND $eventValidSql
          $eventVenueWhere
        ORDER BY l.created_at ASC, l.id ASC
        LIMIT 50001
    ";

    $eventRows = $database->query($eventSql, $eventParams);
    if ($eventRows === false) {
        json_out(500, '查询日期范围内开关播日志失败');
    }

    if (count($eventRows) > 50000) {
        json_out(422, '所选日期范围日志超过50000条，请缩小日期范围或指定场地');
    }

    $segments = [];
    $totalSeconds = 0;
    $venueTotals = [];

    $anchorsByVenue = [];
    $eventsByVenue = [];
    $venueNames = [];

    foreach ($anchorRows as $row) {
        $vid = intval($row['venue_id']);
        $anchorsByVenue[$vid] = $row;
        $venueNames[$vid] = isset($row['venue_name']) ? (string)$row['venue_name'] : '';
    }

    foreach ($eventRows as $row) {
        $vid = intval($row['venue_id']);
        if (!isset($eventsByVenue[$vid])) {
            $eventsByVenue[$vid] = [];
        }
        $eventsByVenue[$vid][] = $row;
        if (!isset($venueNames[$vid]) || $venueNames[$vid] === '') {
            $venueNames[$vid] = isset($row['venue_name']) ? (string)$row['venue_name'] : '';
        }
    }

    $venueIds = array_values(array_unique(array_merge(
        array_keys($anchorsByVenue),
        array_keys($eventsByVenue)
    )));
    sort($venueIds, SORT_NUMERIC);

    $appendSegment = function ($vid, $openRow, $closeRow) use (
        &$segments,
        &$totalSeconds,
        &$venueTotals,
        $venueNames,
        $rangeStartTimestamp,
        $calculationEndTimestamp
    ) {
        if (!$openRow || empty($openRow['created_at'])) {
            return;
        }

        $openTimestamp = strtotime($openRow['created_at']);
        if ($openTimestamp === false) {
            return;
        }

        $closeTime = $closeRow && !empty($closeRow['created_at'])
            ? $closeRow['created_at']
            : null;
        $closeTimestamp = $closeTime === null ? null : strtotime($closeTime);
        $calcStart = max($openTimestamp, $rangeStartTimestamp);
        $calcEnd = $closeTimestamp === null
            ? $calculationEndTimestamp
            : min($closeTimestamp, $calculationEndTimestamp);

        if ($calcEnd <= $calcStart) {
            return;
        }

        $durationSeconds = $calcEnd - $calcStart;
        $venueName = isset($venueNames[$vid]) ? $venueNames[$vid] : '';

        if (!isset($venueTotals[$vid])) {
            $venueTotals[$vid] = [
                'venue_id' => $vid,
                'venue_name' => $venueName,
                'total_seconds' => 0,
                'segment_count' => 0
            ];
        }

        $venueTotals[$vid]['total_seconds'] += $durationSeconds;
        $venueTotals[$vid]['segment_count']++;
        $totalSeconds += $durationSeconds;

        $segments[] = [
            'venue_id' => $vid,
            'venue_name' => $venueName,
            'open_time' => $openRow['created_at'],
            'close_time' => $closeTime,
            'calc_start' => date('Y-m-d H:i:s', $calcStart),
            'calc_end' => date('Y-m-d H:i:s', $calcEnd),
            'duration_seconds' => $durationSeconds,
            // 历史范围内未出现关播时，表示统计截止时仍开播；今天则表示当前直播中。
            'is_living' => $closeTime === null ? 1 : 0
        ];
    };

    foreach ($venueIds as $vid) {
        $state = 0;
        $openRow = null;

        if (isset($anchorsByVenue[$vid])) {
            $anchorState = normalize_live_state($anchorsByVenue[$vid]);
            if ($anchorState === 1) {
                $state = 1;
                $openRow = $anchorsByVenue[$vid];
            }
        }

        $venueEvents = isset($eventsByVenue[$vid]) ? $eventsByVenue[$vid] : [];
        foreach ($venueEvents as $eventRow) {
            $nextState = normalize_live_state($eventRow);
            if ($nextState === null) {
                continue;
            }

            if ($nextState === 1) {
                if ($state !== 1) {
                    $openRow = $eventRow;
                }
                $state = 1;
                continue;
            }

            if ($state === 1 && $openRow !== null) {
                $appendSegment($vid, $openRow, $eventRow);
            }
            $state = 0;
            $openRow = null;
        }

        if ($state === 1 && $openRow !== null) {
            $appendSegment($vid, $openRow, null);
        }
    }

    usort($segments, function ($left, $right) {
        if (intval($left['venue_id']) !== intval($right['venue_id'])) {
            return intval($left['venue_id']) <=> intval($right['venue_id']);
        }
        return strcmp((string)$left['calc_start'], (string)$right['calc_start']);
    });

    json_out(0, 'success', [
        'role_id' => $roleId,
        'can_select_venue' => in_array($roleId, [1, 2], true) ? 1 : 0,
        'is_venue_limited' => in_array($roleId, [3, 4], true) ? 1 : 0,
        'forced_venue_id' => in_array($roleId, [3, 4], true) ? $userVenueId : 0,
        'selected_venue_id' => $venueId,
        'startday' => $startDate,
        'endday' => $endDate,
        // 兼容旧前端字段名。
        'start_date' => $startDate,
        'end_date' => $endDate,
        'date_range' => $startDate === $endDate
            ? $startDate
            : ($startDate . ' 至 ' . $endDate),
        // 兼容旧前端：多日查询时 date 返回范围起始日。
        'date' => $startDate,
        'is_today' => $startDate === $today && $endDate === $today ? 1 : 0,
        'range_start' => $rangeStart,
        // 兼容仍读取 today_start 的旧前端。
        'today_start' => $rangeStart,
        'range_end' => $rangeEndInclusive,
        'range_end_exclusive' => $rangeEndExclusive,
        'effective_duration_end' => date('Y-m-d H:i:s', $calculationEndTimestamp),
        'total_seconds' => $totalSeconds,
        'venue_totals' => array_values($venueTotals),
        'segments' => $segments
    ]);

} catch (Exception $e) {
    json_out(500, '服务器异常：' . $e->getMessage());
}
