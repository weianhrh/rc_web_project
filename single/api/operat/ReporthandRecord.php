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

$where = ['1=1'];
$params = [];

if ($role_id == 1 || $role_id == 2) {
    // 管理员/运营：默认全场地；传 venue_id 时才按场地筛选
    if ($venue_id !== '' && strtolower($venue_id) !== 'all') {
        if (!is_numeric($venue_id)) {
            jsonRet(1002, '无效的场地ID');
        }
        $where[] = 'v.bind_site = ?';
        $params[] = (string)intval($venue_id);
    }
} else {
    // 普通用户：强制只能看自己绑定场地
    $userVenueId = $user['venue_id'] ?? '';
    if (!$userVenueId) {
        jsonRet(1003, '用户未绑定场地');
    }
    $where[] = 'v.bind_site = ?';
    $params[] = (string)intval($userVenueId);
}

if ($startDate !== '') {
    if (!isValidDateStr($startDate)) {
        jsonRet(1004, '开始日期格式错误');
    }
    $where[] = 'r.insert_time >= ?';
    $params[] = $startDate . ' 00:00:00';
}

if ($endDate !== '') {
    if (!isValidDateStr($endDate)) {
        jsonRet(1005, '结束日期格式错误');
    }
    $endExclusive = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day'));
    $where[] = 'r.insert_time < ?';
    $params[] = $endExclusive;
}

// 指定账号只能看 2026-07-01 之后的投诉处理记录
if ($isDateLimitedUser) {
    $where[] = 'r.insert_time >= ?';
    $params[] = $visibleFrom;
}

$whereSql = implode(' AND ', $where);

$fromSql = "
    FROM Reports r
    JOIN vehicles v ON r.device_id = v.serial_number
    LEFT JOIN venues ve ON ve.id = v.bind_site
    WHERE {$whereSql}
";

$countSql = "SELECT COUNT(*) AS total {$fromSql}";
$countRows = $database->query($countSql, $params) ?: [];
$total = isset($countRows[0]['total']) ? intval($countRows[0]['total']) : 0;
$totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

// 如果请求页码超过最大页，自动回到最后一页
if ($total > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$dataSql = "
    SELECT
        r.*,
        v.bind_site AS venue_id,
        ve.venue_name
    {$fromSql}
    ORDER BY r.insert_time DESC
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
        'end_date'   => $endDate
    ],
    'visible_from' => $isDateLimitedUser ? $visibleFrom : ''
]);
