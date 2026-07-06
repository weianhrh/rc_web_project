<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function jsonRet($code, $msg, $data = [], $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidDateStr($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function isAdminRole($roleId) {
    return (int)$roleId === 1 || (int)$roleId === 2;
}

function appendDateWhere(&$where, &$params, $field, $startDate, $endDate, $isDateLimitedUser, $visibleFrom) {
    if ($startDate !== '') {
        $where[] = $field . ' >= ?';
        $params[] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $endExclusive = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day'));
        $where[] = $field . ' < ?';
        $params[] = $endExclusive;
    }

    if ($isDateLimitedUser) {
        $where[] = $field . ' >= ?';
        $params[] = $visibleFrom;
    }
}

// 获取当前用户
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    jsonRet(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    jsonRet(1001, '用户无效或无权限');
}

$role_id  = (int)$user['role_id'];
$username = trim($user['username'] ?? '');

/**
 * 针对指定后台账号隐藏 2026 年 7 月之前的投诉处理记录数据
 * 账号：cc0123456
 */
$limitUsers = ['cc0123456'];
$visibleFrom = '2026-07-01 00:00:00';
$isDateLimitedUser = in_array($username, $limitUsers, true);

// 分页参数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = isset($_GET['page_size']) ? intval($_GET['page_size']) : 10;
$pageSize = max(1, min(100, $pageSize));
$offset = ($page - 1) * $pageSize;

// 筛选参数
$venue_id  = trim($_GET['venue_id'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');
$source    = trim($_GET['source'] ?? ($_GET['type'] ?? 'all'));

$allowedSources = ['all', 'drive', 'voice_room', 'voice_comment'];
if (!in_array($source, $allowedSources, true)) {
    jsonRet(1006, '无效的举报类型');
}

if ($startDate !== '' && !isValidDateStr($startDate)) {
    jsonRet(1004, '开始日期格式错误');
}

if ($endDate !== '' && !isValidDateStr($endDate)) {
    jsonRet(1005, '结束日期格式错误');
}

$useVenueFilter = $venue_id !== '' && strtolower($venue_id) !== 'all';
if (isAdminRole($role_id) && $useVenueFilter && !is_numeric($venue_id)) {
    jsonRet(1002, '无效的场地ID');
}

$userVenueId = '';
if (!isAdminRole($role_id)) {
    $userVenueId = $user['venue_id'] ?? '';
    if (!$userVenueId) {
        jsonRet(1003, '用户未绑定场地');
    }
}

$parts = [];
$params = [];

// 1) 普通驾驶投诉 Reports
if ($source === 'all' || $source === 'drive') {
    $where = ['1=1'];
    $partParams = [];

    if (isAdminRole($role_id)) {
        // 管理员/运营：默认全场地；传 venue_id 时才按场地筛选
        if ($useVenueFilter) {
            $where[] = 'v.bind_site = ?';
            $partParams[] = (string)intval($venue_id);
        }
    } else {
        // 普通用户：强制只能看自己绑定场地
        $where[] = 'v.bind_site = ?';
        $partParams[] = (string)intval($userVenueId);
    }

    appendDateWhere($where, $partParams, 'r.insert_time', $startDate, $endDate, $isDateLimitedUser, $visibleFrom);

    $whereSql = implode(' AND ', $where);
    $parts[] = "
        SELECT
            'drive' AS record_source,
            'Reports' AS source_table,
            '普通驾驶投诉' AS source_label,
            r.id,
            r.device_id,
            r.reporter_uid,
            '' AS reporter_name,
            r.report_type,
            '普通驾驶投诉' AS report_type_text,
            r.status,
            r.notes,
            r.handler_uid,
            r.insert_time,
            r.image_url,
            CAST(v.bind_site AS CHAR) AS venue_id,
            COALESCE(ve.venue_name, '') AS venue_name,
            CAST(v.name AS CHAR) AS vehicle_name,
            CAST(r.report_type AS CHAR) AS report_content,
            'device' AS target_kind,
            r.device_id AS target_id,
            CAST(v.name AS CHAR) AS target_name
        FROM Reports r
        JOIN vehicles v ON r.device_id = v.serial_number
        LEFT JOIN venues ve ON ve.id = v.bind_site
        WHERE {$whereSql}
    ";
    $params = array_merge($params, $partParams);
}

// 2) 语音房举报 / 评论举报 voice_reports
if ($source === 'all' || $source === 'voice_room' || $source === 'voice_comment') {
    $where = ['1=1'];
    $partParams = [];

    if ($source === 'voice_room') {
        $where[] = 'vr.report_type = 0';
    } elseif ($source === 'voice_comment') {
        $where[] = 'vr.report_type = 1';
    }

    if (isAdminRole($role_id)) {
        if ($useVenueFilter) {
            // 房间举报：handler_uid=场地ID
            // 评论/弹幕举报：handler_uid=被举报用户UID，尽量按被举报用户 users.streamer_venue 归属场地过滤
            $where[] = "(
                (vr.report_type = 0 AND CAST(vr.handler_uid AS CHAR) = ?)
                OR
                (vr.report_type = 1 AND CAST(uu.streamer_venue AS CHAR) = ?)
            )";
            $partParams[] = (string)intval($venue_id);
            $partParams[] = (string)intval($venue_id);
        }
    } else {
        $where[] = "(
            (vr.report_type = 0 AND CAST(vr.handler_uid AS CHAR) = ?)
            OR
            (vr.report_type = 1 AND CAST(uu.streamer_venue AS CHAR) = ?)
        )";
        $partParams[] = (string)intval($userVenueId);
        $partParams[] = (string)intval($userVenueId);
    }

    appendDateWhere($where, $partParams, 'vr.insert_time', $startDate, $endDate, $isDateLimitedUser, $visibleFrom);

    $whereSql = implode(' AND ', $where);
    $parts[] = "
        SELECT
            CASE
                WHEN vr.report_type = 0 THEN 'voice_room'
                WHEN vr.report_type = 1 THEN 'voice_comment'
                ELSE 'voice_other'
            END AS record_source,
            'voice_reports' AS source_table,
            CASE
                WHEN vr.report_type = 0 THEN '语音房举报'
                WHEN vr.report_type = 1 THEN '评论/弹幕举报'
                ELSE '语音房举报'
            END AS source_label,
            vr.id,
            '' AS device_id,
            vr.reporter_uid,
            COALESCE(ru.nickname, '') AS reporter_name,
            vr.report_type,
            CASE
                WHEN vr.report_type = 0 THEN '房间举报'
                WHEN vr.report_type = 1 THEN '评论/弹幕举报'
                ELSE '未知举报'
            END AS report_type_text,
            vr.status,
            vr.notes,
            vr.handler_uid,
            vr.insert_time,
            vr.image_url,
            CASE
                WHEN vr.report_type = 0 THEN CAST(vv.id AS CHAR)
                WHEN vr.report_type = 1 THEN CAST(uv.id AS CHAR)
                ELSE ''
            END AS venue_id,
            CASE
                WHEN vr.report_type = 0 THEN COALESCE(vv.venue_name, '')
                WHEN vr.report_type = 1 THEN COALESCE(uv.venue_name, '')
                ELSE ''
            END AS venue_name,
            '' AS vehicle_name,
            vr.report_content,
            CASE
                WHEN vr.report_type = 0 THEN 'venue'
                WHEN vr.report_type = 1 THEN 'user'
                ELSE 'unknown'
            END AS target_kind,
            vr.handler_uid AS target_id,
            CASE
                WHEN vr.report_type = 0 THEN COALESCE(vv.venue_name, '')
                WHEN vr.report_type = 1 THEN COALESCE(uu.nickname, '')
                ELSE ''
            END AS target_name
        FROM voice_reports vr
        LEFT JOIN users ru
            ON CAST(vr.reporter_uid AS UNSIGNED) = ru.uid
        LEFT JOIN venues vv
            ON vr.report_type = 0 AND CAST(vr.handler_uid AS UNSIGNED) = vv.id
        LEFT JOIN users uu
            ON vr.report_type = 1 AND CAST(vr.handler_uid AS UNSIGNED) = uu.uid
        LEFT JOIN venues uv
            ON vr.report_type = 1 AND CAST(uu.streamer_venue AS UNSIGNED) = uv.id
        WHERE {$whereSql}
    ";
    $params = array_merge($params, $partParams);
}

if (empty($parts)) {
    jsonRet(0, '获取成功', [], [
        'pagination' => [
            'page'        => 1,
            'page_size'   => $pageSize,
            'total'       => 0,
            'total_pages' => 1
        ]
    ]);
}

$unionSql = implode("\nUNION ALL\n", $parts);

$countSql = "SELECT COUNT(*) AS total FROM ({$unionSql}) x";
$countRows = $database->query($countSql, $params) ?: [];
$total = isset($countRows[0]['total']) ? intval($countRows[0]['total']) : 0;
$totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

// 如果请求页码超过最大页，自动回到最后一页
if ($total > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$dataSql = "
    SELECT *
    FROM ({$unionSql}) x
    ORDER BY x.insert_time DESC, x.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
";

$reports = $database->query($dataSql, $params) ?: [];

$database->close();

jsonRet(0, '获取成功', $reports, [
    'pagination' => [
        'page'        => $page,
        'page_size'   => $pageSize,
        'total'       => $total,
        'total_pages' => $totalPages
    ],
    'filters' => [
        'venue_id'   => ($venue_id === '' ? 'all' : $venue_id),
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'source'     => $source
    ],
    'visible_from' => $isDateLimitedUser ? $visibleFrom : ''
]);
