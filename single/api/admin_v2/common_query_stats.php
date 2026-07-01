<?php
/**
 * 公共查询 / 公共统计接口
 * 建议路径：single/api/common/common_query_stats.php
 *
 * 前端示例：
 *   fetch('../api/common/common_query_stats.php?action=overview&mode=day')
 *   fetch('../api/common/common_query_stats.php?action=venues')
 *   fetch('../api/common/common_query_stats.php?action=venue_rank&mode=month')
 *   fetch('../api/common/common_query_stats.php?action=orders&mode=day&page=1&page_size=20')
 *
 * 设计原则：
 * 1. 不允许前端直接传 table / field / sql，全部走白名单 action，避免 SQL 注入。
 * 2. 管理员 role_id=1/2 可查全场地或指定 venue_id；其他角色默认只查自己 venue_id。
 * 3. 时间范围统一为左闭右开：start <= time < end，避免 23:59:59 漏秒/重复。
 * 4. 驾驶订单收入统一排除：能量、金币、礼物、娃娃机。
 */

require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$database = null;

try {
    $database = new Database();
    $user = require_admin_login($database);

    $action = trim($_GET['action'] ?? 'overview');

    switch ($action) {
        case 'me':
            api_ok(get_safe_user($user));
            break;

        case 'venues':
            api_ok(get_venues($database, $user));
            break;

        case 'overview':
            api_ok(get_overview_stats($database, $user));
            break;

        case 'venue_rank':
            api_ok(get_venue_rank($database, $user));
            break;

        case 'income_trend':
            api_ok(get_income_trend($database, $user));
            break;

        case 'orders':
            api_ok(get_order_list($database, $user));
            break;

        case 'users':
            api_ok(get_user_list($database, $user));
            break;

        case 'recharges':
            api_ok(get_recharge_list($database, $user));
            break;

        default:
            api_fail(400, '未知 action：' . $action);
    }
} catch (Throwable $e) {
    error_log('[common_query_stats] ' . $e->getMessage());
    api_fail(500, '服务器错误', ['error' => $e->getMessage()]);
} finally {
    if ($database) {
        $database->close();
    }
}

/* =========================================================
 * 基础输出 / 登录 / 参数
 * ========================================================= */

function api_ok($data = [], $extra = []) {
    echo json_encode(array_merge([
        'code' => 0,
        'msg'  => 'ok',
        'data' => $data,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_fail($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_admin_login($db) {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        api_fail(1001, '用户未登录或会话已过期');
    }

    $user = $db->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        api_fail(1001, '用户未登录或无权访问');
    }

    return $user;
}

function is_admin_role($user) {
    $roleId = intval($user['role_id'] ?? 0);
    return in_array($roleId, [1, 2], true);
}

function get_safe_user($user) {
    return [
        'uid'      => intval($user['uid'] ?? $user['id'] ?? 0),
        'username' => $user['username'] ?? '',
        'role_id'  => intval($user['role_id'] ?? 0),
        'venue_id' => intval($user['venue_id'] ?? 0),
        'is_admin' => is_admin_role($user) ? 1 : 0,
    ];
}

function req_str($name, $default = '') {
    return trim(strval($_GET[$name] ?? $_POST[$name] ?? $default));
}

function req_int($name, $default = 0, $min = null, $max = null) {
    $v = $_GET[$name] ?? $_POST[$name] ?? $default;
    $v = is_numeric($v) ? intval($v) : intval($default);
    if ($min !== null && $v < $min) $v = $min;
    if ($max !== null && $v > $max) $v = $max;
    return $v;
}

function money_fmt($v) {
    return number_format(floatval($v), 2, '.', '');
}

function valid_date($s) {
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function normalize_datetime($s) {
    $s = trim(strval($s));
    if ($s === '') return null;
    $s = str_replace('/', '-', $s);
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

/**
 * mode 支持：day / yesterday / week / month / custom
 * 也支持 date=YYYY-MM-DD 指定某天；custom 用 start_date/end_date 或 start/end。
 */
function get_time_range() {
    $mode = strtolower(req_str('mode', req_str('period', 'day')));
    $date = req_str('date', '');

    $startQ = req_str('start_date', req_str('start', ''));
    $endQ   = req_str('end_date', req_str('end', ''));

    // 允许 start_date/end_date 只传日期，也允许完整时间。
    if ($startQ !== '' && $endQ !== '') {
        if (valid_date($startQ)) $startQ .= ' 00:00:00';
        if (valid_date($endQ))   $endQ   .= ' 00:00:00';
        $start = normalize_datetime($startQ);
        $end   = normalize_datetime($endQ);
        if ($start && $end && strtotime($end) > strtotime($start)) {
            return [
                'mode' => 'custom',
                'start' => $start,
                'end' => $end,
                'start_date' => substr($start, 0, 10),
                'end_date' => substr($end, 0, 10),
            ];
        }
    }

    if ($date !== '' && valid_date($date)) {
        $start = $date . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        return [
            'mode' => 'day',
            'start' => $start,
            'end' => $end,
            'start_date' => substr($start, 0, 10),
            'end_date' => substr($end, 0, 10),
        ];
    }

    if ($mode === 'yesterday') {
        $start = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end   = date('Y-m-d 00:00:00');
    } elseif ($mode === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end   = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } elseif ($mode === 'month') {
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-01 00:00:00', strtotime('+1 month'));
    } else {
        $mode  = 'day';
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 00:00:00', strtotime('+1 day'));
    }

    return [
        'mode' => $mode,
        'start' => $start,
        'end' => $end,
        'start_date' => substr($start, 0, 10),
        'end_date' => substr($end, 0, 10),
    ];
}

/**
 * 管理员：venue_id 可选，0 表示全场地。
 * 非管理员：强制当前账号 venue_id。
 */
function get_scoped_venue_id($user) {
    if (is_admin_role($user)) {
        return req_int('venue_id', 0, 0);
    }
    return intval($user['venue_id'] ?? 0);
}

function add_venue_where(&$where, &$params, $alias, $field, $venueId) {
    if ($venueId > 0) {
        $prefix = $alias ? ($alias . '.') : '';
        $where[] = $prefix . $field . ' = ?';
        $params[] = $venueId;
    }
}

function page_params() {
    $page = req_int('page', 1, 1, 1000000);
    $pageSize = req_int('page_size', req_int('limit', 20), 1, 200);
    return [$page, $pageSize, ($page - 1) * $pageSize];
}

/* =========================================================
 * 表 / 字段探测
 * ========================================================= */

function table_exists($db, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $rows = $db->query(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    $cache[$table] = intval($rows[0]['c'] ?? 0) > 0;
    return $cache[$table];
}

function column_exists($db, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $rows = $db->query(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
    $cache[$key] = intval($rows[0]['c'] ?? 0) > 0;
    return $cache[$key];
}

function first_existing_column($db, $table, $columns, $default = '') {
    foreach ($columns as $col) {
        if (column_exists($db, $table, $col)) return $col;
    }
    return $default;
}

/* =========================================================
 * 统一业务口径
 * ========================================================= */

/**
 * 驾驶订单收入统一排除：能量、金币、礼物、娃娃机。
 * 说明：不同旧页面可能把礼物/娃娃机写在 pays_type 或 note 里，所以这里两边都兜底。
 */
function append_drive_exclude_where($db, &$where, $alias = 'o') {
    $p = $alias ? ($alias . '.') : '';

    if (column_exists($db, 'orders', 'pays_type')) {
        $where[] = "(" . $p . "pays_type IS NULL OR " . $p . "pays_type NOT IN ('能量', '金币', '礼物', '娃娃机'))";
    }

    if (column_exists($db, 'orders', 'note')) {
        $where[] = "(" . $p . "note IS NULL
                    OR (
                        " . $p . "note <> 'gift'
                        AND " . $p . "note <> '礼物'
                        AND " . $p . "note NOT LIKE '%娃娃机%'
                        AND " . $p . "note NOT LIKE '%claw%'
                    ))";
    }
}

/* =========================================================
 * action=venues
 * ========================================================= */

function get_venues($db, $user) {
    if (!table_exists($db, 'venues')) {
        return [];
    }

    $nameCol = first_existing_column($db, 'venues', ['venue_name', 'name'], 'id');
    $queueCol = column_exists($db, 'venues', 'queue_length') ? 'queue_length' : null;

    $fields = "id, {$nameCol} AS venue_name";
    if ($queueCol) {
        $fields .= ", {$queueCol} AS queue_length";
    } else {
        $fields .= ", 0 AS queue_length";
    }

    $where = [];
    $params = [];
    if (!is_admin_role($user)) {
        $where[] = 'id = ?';
        $params[] = intval($user['venue_id'] ?? 0);
    }

    $sql = "SELECT {$fields} FROM venues";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id ASC';

    $rows = $db->query($sql, $params) ?: [];
    foreach ($rows as &$row) {
        $row['id'] = intval($row['id']);
        $row['queue_length'] = intval($row['queue_length'] ?? 0);
    }
    return $rows;
}

/* =========================================================
 * action=overview
 * ========================================================= */

function get_overview_stats($db, $user) {
    $range = get_time_range();
    $venueId = get_scoped_venue_id($user);

    $drive = get_drive_stats($db, $range, $venueId);
    $gift = get_gift_stats($db, $range, $venueId);
    $reservation = get_reservation_stats($db, $range, $venueId);
    $recharge = get_recharge_stats($db, $range);
    $users = get_user_stats($db, $range, $venueId);
    $reports = get_report_stats($db, $range, $venueId);
    $refund = get_refund_stats($db, $range, $venueId);
    $devices = get_device_stats($db, $venueId);

    return [
        'range' => $range,
        'scope' => [
            'is_admin' => is_admin_role($user) ? 1 : 0,
            'role_id' => intval($user['role_id'] ?? 0),
            'venue_id' => $venueId,
            'venue_note' => $venueId > 0 ? '指定/当前场地' : '全场地',
        ],
        'summary' => [
            // 驾驶订单收入：已排除礼物、金币、娃娃机、能量
            'drive_income' => money_fmt($drive['drive_income'] ?? 0),
            'drive_order_count' => intval($drive['drive_order_count'] ?? 0),
            'drive_user_count' => intval($drive['drive_user_count'] ?? 0),

            // 预约
            'reservation_count' => intval($reservation['reservation_count'] ?? 0),

            // 礼物收益，单独给页面想展示时用；不计入 drive_income
            'gift_income' => money_fmt($gift['gift_income'] ?? 0),
            'gift_count' => intval($gift['gift_count'] ?? 0),

            // 充值流水，平台口径使用；不计入 drive_income
            'recharge_paid_amount' => money_fmt($recharge['paid_amount'] ?? 0),
            'recharge_paid_count' => intval($recharge['paid_count'] ?? 0),

            // 用户
            'new_users' => intval($users['new_users'] ?? 0),
            'active_users' => intval($users['active_users'] ?? 0),

            // 待处理违规/投诉
            'pending_report_count' => intval($reports['pending_report_count'] ?? 0),

            // 退款
            'refund_amount' => money_fmt($refund['refund_amount'] ?? 0),
            'refund_count' => intval($refund['refund_count'] ?? 0),

            // 设备
            'device_total' => intval($devices['device_total'] ?? 0),
            'device_online' => intval($devices['device_online'] ?? 0),
            'device_offline' => intval($devices['device_offline'] ?? 0),
            'device_using' => intval($devices['device_using'] ?? 0),
        ],
        'explain' => [
            'drive_income' => 'orders.payment_amount，按 end_time 统计，排除 pays_type=能量/金币/礼物/娃娃机，以及 note=gift/礼物/包含娃娃机/claw',
            'gift_income' => 'gift_orders.payment_amount / 10 * 0.6，单独统计，不混入驾驶收入',
            'time_rule' => '左闭右开：start <= 时间 < end',
        ],
    ];
}

function get_drive_stats($db, $range, $venueId) {
    if (!table_exists($db, 'orders')) {
        return ['drive_income' => 0, 'drive_order_count' => 0, 'drive_user_count' => 0];
    }

    $timeCol = first_existing_column($db, 'orders', ['end_time', 'created_at'], 'end_time');
    $amountCol = column_exists($db, 'orders', 'payment_amount') ? 'payment_amount' : '';
    if ($amountCol === '') {
        return ['drive_income' => 0, 'drive_order_count' => 0, 'drive_user_count' => 0];
    }

    $where = ["o.{$timeCol} >= ?", "o.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];
    append_drive_exclude_where($db, $where, 'o');

    if (column_exists($db, 'orders', 'reservation_id')) {
        add_venue_where($where, $params, 'o', 'reservation_id', $venueId);
    }

    $uidCount = column_exists($db, 'orders', 'uid') ? 'COUNT(DISTINCT o.uid)' : '0';

    $sql = "SELECT
                COALESCE(SUM(o.{$amountCol}), 0) AS drive_income,
                COUNT(*) AS drive_order_count,
                {$uidCount} AS drive_user_count
            FROM orders o
            WHERE " . implode(' AND ', $where);

    $row = ($db->query($sql, $params)[0] ?? []);
    return [
        'drive_income' => floatval($row['drive_income'] ?? 0),
        'drive_order_count' => intval($row['drive_order_count'] ?? 0),
        'drive_user_count' => intval($row['drive_user_count'] ?? 0),
    ];
}

function get_gift_stats($db, $range, $venueId) {
    if (!table_exists($db, 'gift_orders')) {
        return ['gift_income' => 0, 'gift_count' => 0];
    }

    $timeCol = first_existing_column($db, 'gift_orders', ['send_time', 'created_at'], 'created_at');
    $amountCol = column_exists($db, 'gift_orders', 'payment_amount') ? 'payment_amount' : '';
    if ($amountCol === '') {
        return ['gift_income' => 0, 'gift_count' => 0];
    }

    $where = ["g.{$timeCol} >= ?", "g.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    if (column_exists($db, 'gift_orders', 'status')) {
        $where[] = "(g.status = '已完成' OR g.status = '支付成功')";
    }
    if (column_exists($db, 'gift_orders', 'reservation_id')) {
        add_venue_where($where, $params, 'g', 'reservation_id', $venueId);
    }

    $sql = "SELECT
                COALESCE(SUM(g.{$amountCol}) / 10 * 0.6, 0) AS gift_income,
                COUNT(*) AS gift_count
            FROM gift_orders g
            WHERE " . implode(' AND ', $where);

    $row = ($db->query($sql, $params)[0] ?? []);
    return [
        'gift_income' => floatval($row['gift_income'] ?? 0),
        'gift_count' => intval($row['gift_count'] ?? 0),
    ];
}

function get_reservation_stats($db, $range, $venueId) {
    if (!table_exists($db, 'Reservations')) {
        return ['reservation_count' => 0];
    }

    $timeCol = first_existing_column($db, 'Reservations', ['reservation_time', 'created_at'], 'reservation_time');
    $where = ["r.{$timeCol} >= ?", "r.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    if (column_exists($db, 'Reservations', 'reservation_id')) {
        add_venue_where($where, $params, 'r', 'reservation_id', $venueId);
    }

    $sql = "SELECT COUNT(*) AS reservation_count FROM Reservations r WHERE " . implode(' AND ', $where);
    $row = ($db->query($sql, $params)[0] ?? []);
    return ['reservation_count' => intval($row['reservation_count'] ?? 0)];
}

function get_recharge_stats($db, $range) {
    if (!table_exists($db, 'RechargeOrders')) {
        return ['paid_amount' => 0, 'paid_count' => 0];
    }

    $timeCol = first_existing_column($db, 'RechargeOrders', ['created_at', 'pay_time', 'updated_at'], 'created_at');
    $amountCol = column_exists($db, 'RechargeOrders', 'payer_total') ? 'payer_total' : '';
    if ($amountCol === '') {
        return ['paid_amount' => 0, 'paid_count' => 0];
    }

    $where = ["ro.{$timeCol} >= ?", "ro.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    if (column_exists($db, 'RechargeOrders', 'status')) {
        $where[] = "ro.status = '支付成功'";
    }

    $sql = "SELECT
                COALESCE(SUM(ro.{$amountCol}), 0) AS paid_amount,
                COUNT(*) AS paid_count
            FROM RechargeOrders ro
            WHERE " . implode(' AND ', $where);

    $row = ($db->query($sql, $params)[0] ?? []);
    return [
        'paid_amount' => floatval($row['paid_amount'] ?? 0),
        'paid_count' => intval($row['paid_count'] ?? 0),
    ];
}

function get_user_stats($db, $range, $venueId) {
    if (!table_exists($db, 'users')) {
        return ['new_users' => 0, 'active_users' => 0];
    }

    $whereNew = [];
    $paramsNew = [];
    if (column_exists($db, 'users', 'created_at')) {
        $whereNew[] = 'u.created_at >= ?';
        $whereNew[] = 'u.created_at < ?';
        $paramsNew[] = $range['start'];
        $paramsNew[] = $range['end'];
    }
    if (column_exists($db, 'users', 'venue_id')) {
        add_venue_where($whereNew, $paramsNew, 'u', 'venue_id', $venueId);
    }

    $newUsers = 0;
    if ($whereNew) {
        $row = ($db->query('SELECT COUNT(*) AS c FROM users u WHERE ' . implode(' AND ', $whereNew), $paramsNew)[0] ?? []);
        $newUsers = intval($row['c'] ?? 0);
    }

    $activeUsers = 0;
    if (column_exists($db, 'users', 'last_active_at')) {
        $whereActive = ['u.last_active_at >= ?', 'u.last_active_at < ?'];
        $paramsActive = [$range['start'], $range['end']];
        if (column_exists($db, 'users', 'venue_id')) {
            add_venue_where($whereActive, $paramsActive, 'u', 'venue_id', $venueId);
        }
        $row = ($db->query('SELECT COUNT(*) AS c FROM users u WHERE ' . implode(' AND ', $whereActive), $paramsActive)[0] ?? []);
        $activeUsers = intval($row['c'] ?? 0);
    }

    return ['new_users' => $newUsers, 'active_users' => $activeUsers];
}

function get_report_stats($db, $range, $venueId) {
    if (!table_exists($db, 'Reports')) {
        return ['pending_report_count' => 0];
    }

    $where = [];
    $params = [];
    $join = '';

    if (column_exists($db, 'Reports', 'status')) {
        $where[] = "(r.status = '未处理' OR r.status = '处理中')";
    }

    if ($venueId > 0 && table_exists($db, 'vehicles') && column_exists($db, 'Reports', 'device_id') && column_exists($db, 'vehicles', 'serial_number') && column_exists($db, 'vehicles', 'bind_site')) {
        $join = ' JOIN vehicles v ON r.device_id = v.serial_number ';
        $where[] = 'v.bind_site = ?';
        $params[] = $venueId;
    }

    if (!$where) $where[] = '1=1';

    $sql = 'SELECT COUNT(*) AS c FROM Reports r ' . $join . ' WHERE ' . implode(' AND ', $where);
    $row = ($db->query($sql, $params)[0] ?? []);
    return ['pending_report_count' => intval($row['c'] ?? 0)];
}

function get_refund_stats($db, $range, $venueId) {
    if (!table_exists($db, 'refund_records')) {
        return ['refund_amount' => 0, 'refund_count' => 0];
    }

    $timeCol = first_existing_column($db, 'refund_records', ['created_at', 'refund_time', 'updated_at'], 'created_at');
    $amountCol = first_existing_column($db, 'refund_records', ['refund_amount', 'amount'], '');
    if ($amountCol === '') {
        return ['refund_amount' => 0, 'refund_count' => 0];
    }

    $where = ["rr.{$timeCol} >= ?", "rr.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    if (column_exists($db, 'refund_records', 'reservation_id')) {
        add_venue_where($where, $params, 'rr', 'reservation_id', $venueId);
    } elseif (column_exists($db, 'refund_records', 'venue_id')) {
        add_venue_where($where, $params, 'rr', 'venue_id', $venueId);
    }

    if (column_exists($db, 'refund_records', 'is_reduced')) {
        $where[] = 'rr.is_reduced = 1';
    }

    $sql = "SELECT COALESCE(SUM(rr.{$amountCol}), 0) AS amount, COUNT(*) AS c
            FROM refund_records rr
            WHERE " . implode(' AND ', $where);

    $row = ($db->query($sql, $params)[0] ?? []);
    return [
        'refund_amount' => floatval($row['amount'] ?? 0),
        'refund_count' => intval($row['c'] ?? 0),
    ];
}

function get_device_stats($db, $venueId) {
    if (!table_exists($db, 'vehicles')) {
        return ['device_total' => 0, 'device_online' => 0, 'device_offline' => 0, 'device_using' => 0];
    }

    $where = [];
    $params = [];
    if (column_exists($db, 'vehicles', 'bind_site')) {
        add_venue_where($where, $params, 'v', 'bind_site', $venueId);
    }
    if (!$where) $where[] = '1=1';

    $statusCol = column_exists($db, 'vehicles', 'status') ? 'status' : '';
    if ($statusCol === '') {
        $sql = 'SELECT COUNT(*) AS total FROM vehicles v WHERE ' . implode(' AND ', $where);
        $row = ($db->query($sql, $params)[0] ?? []);
        return ['device_total' => intval($row['total'] ?? 0), 'device_online' => 0, 'device_offline' => 0, 'device_using' => 0];
    }

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN v.{$statusCol} = '在线' THEN 1 ELSE 0 END) AS online_count,
                SUM(CASE WHEN v.{$statusCol} = '离线' THEN 1 ELSE 0 END) AS offline_count,
                SUM(CASE WHEN v.{$statusCol} = '占有' THEN 1 ELSE 0 END) AS using_count
            FROM vehicles v
            WHERE " . implode(' AND ', $where);

    $row = ($db->query($sql, $params)[0] ?? []);
    return [
        'device_total' => intval($row['total'] ?? 0),
        'device_online' => intval($row['online_count'] ?? 0),
        'device_offline' => intval($row['offline_count'] ?? 0),
        'device_using' => intval($row['using_count'] ?? 0),
    ];
}

/* =========================================================
 * action=venue_rank
 * ========================================================= */

function get_venue_rank($db, $user) {
    if (!table_exists($db, 'venues')) {
        return ['range' => get_time_range(), 'list' => []];
    }

    $range = get_time_range();
    $venueId = get_scoped_venue_id($user);
    $nameCol = first_existing_column($db, 'venues', ['venue_name', 'name'], 'id');

    $whereVenue = [];
    $paramsVenue = [];
    add_venue_where($whereVenue, $paramsVenue, '', 'id', $venueId);
    $venueSql = "SELECT id, {$nameCol} AS venue_name FROM venues";
    if ($whereVenue) $venueSql .= ' WHERE ' . implode(' AND ', $whereVenue);
    $venueRows = $db->query($venueSql, $paramsVenue) ?: [];

    $map = [];
    foreach ($venueRows as $v) {
        $id = intval($v['id']);
        $map[$id] = [
            'venue_id' => $id,
            'venue_name' => $v['venue_name'],
            'drive_income' => '0.00',
            'drive_order_count' => 0,
            'gift_income' => '0.00',
            'total_income' => '0.00',
        ];
    }

    if ($map && table_exists($db, 'orders')) {
        $timeCol = first_existing_column($db, 'orders', ['end_time', 'created_at'], 'end_time');
        $where = ["o.{$timeCol} >= ?", "o.{$timeCol} < ?"];
        $params = [$range['start'], $range['end']];
        append_drive_exclude_where($db, $where, 'o');
        if (column_exists($db, 'orders', 'reservation_id')) {
            add_venue_where($where, $params, 'o', 'reservation_id', $venueId);
        }

        $sql = "SELECT o.reservation_id,
                       COALESCE(SUM(o.payment_amount), 0) AS drive_income,
                       COUNT(*) AS drive_order_count
                FROM orders o
                WHERE " . implode(' AND ', $where) . "
                GROUP BY o.reservation_id";
        $rows = $db->query($sql, $params) ?: [];
        foreach ($rows as $row) {
            $rid = intval($row['reservation_id'] ?? 0);
            if (isset($map[$rid])) {
                $map[$rid]['drive_income'] = money_fmt($row['drive_income'] ?? 0);
                $map[$rid]['drive_order_count'] = intval($row['drive_order_count'] ?? 0);
            }
        }
    }

    if ($map && table_exists($db, 'gift_orders')) {
        $timeCol = first_existing_column($db, 'gift_orders', ['send_time', 'created_at'], 'created_at');
        $where = ["g.{$timeCol} >= ?", "g.{$timeCol} < ?"];
        $params = [$range['start'], $range['end']];
        if (column_exists($db, 'gift_orders', 'status')) {
            $where[] = "(g.status = '已完成' OR g.status = '支付成功')";
        }
        if (column_exists($db, 'gift_orders', 'reservation_id')) {
            add_venue_where($where, $params, 'g', 'reservation_id', $venueId);
        }

        $sql = "SELECT g.reservation_id,
                       COALESCE(SUM(g.payment_amount) / 10 * 0.6, 0) AS gift_income
                FROM gift_orders g
                WHERE " . implode(' AND ', $where) . "
                GROUP BY g.reservation_id";
        $rows = $db->query($sql, $params) ?: [];
        foreach ($rows as $row) {
            $rid = intval($row['reservation_id'] ?? 0);
            if (isset($map[$rid])) {
                $map[$rid]['gift_income'] = money_fmt($row['gift_income'] ?? 0);
            }
        }
    }

    $list = array_values($map);
    foreach ($list as &$item) {
        $item['total_income'] = money_fmt(floatval($item['drive_income']) + floatval($item['gift_income']));
    }

    $rankType = req_str('rank_type', 'drive'); // drive / gift / total / orders
    $sortField = 'drive_income';
    if ($rankType === 'gift') $sortField = 'gift_income';
    if ($rankType === 'total') $sortField = 'total_income';
    if ($rankType === 'orders') $sortField = 'drive_order_count';

    usort($list, function ($a, $b) use ($sortField) {
        $cmp = floatval($b[$sortField]) <=> floatval($a[$sortField]);
        if ($cmp !== 0) return $cmp;
        return intval($a['venue_id']) <=> intval($b['venue_id']);
    });

    return [
        'range' => $range,
        'rank_type' => $rankType,
        'sort_field' => $sortField,
        'scope_venue_id' => $venueId,
        'list' => $list,
    ];
}

/* =========================================================
 * action=income_trend
 * ========================================================= */

function get_income_trend($db, $user) {
    $range = get_time_range();
    $venueId = get_scoped_venue_id($user);

    if (!table_exists($db, 'orders')) {
        return ['range' => $range, 'list' => []];
    }

    $timeCol = first_existing_column($db, 'orders', ['end_time', 'created_at'], 'end_time');
    $where = ["o.{$timeCol} >= ?", "o.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];
    append_drive_exclude_where($db, $where, 'o');
    if (column_exists($db, 'orders', 'reservation_id')) {
        add_venue_where($where, $params, 'o', 'reservation_id', $venueId);
    }

    $sql = "SELECT DATE(o.{$timeCol}) AS stat_date,
                   COALESCE(SUM(o.payment_amount), 0) AS drive_income,
                   COUNT(*) AS drive_order_count
            FROM orders o
            WHERE " . implode(' AND ', $where) . "
            GROUP BY DATE(o.{$timeCol})
            ORDER BY stat_date ASC";

    $rows = $db->query($sql, $params) ?: [];
    foreach ($rows as &$row) {
        $row['drive_income'] = money_fmt($row['drive_income'] ?? 0);
        $row['drive_order_count'] = intval($row['drive_order_count'] ?? 0);
    }

    return [
        'range' => $range,
        'scope_venue_id' => $venueId,
        'list' => $rows,
    ];
}

/* =========================================================
 * action=orders 公共订单查询
 * ========================================================= */

function get_order_list($db, $user) {
    if (!table_exists($db, 'orders')) {
        return ['list' => [], 'total' => 0];
    }

    [$page, $pageSize, $offset] = page_params();
    $range = get_time_range();
    $venueId = get_scoped_venue_id($user);

    $timeCol = first_existing_column($db, 'orders', ['end_time', 'created_at'], 'end_time');
    $where = ["o.{$timeCol} >= ?", "o.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    $driveOnly = req_int('drive_only', 0, 0, 1);
    if ($driveOnly === 1) {
        append_drive_exclude_where($db, $where, 'o');
    }

    if (column_exists($db, 'orders', 'reservation_id')) {
        add_venue_where($where, $params, 'o', 'reservation_id', $venueId);
    }

    $orderNumber = req_str('order_number', '');
    if ($orderNumber !== '' && column_exists($db, 'orders', 'order_number')) {
        $where[] = 'o.order_number LIKE ?';
        $params[] = '%' . $orderNumber . '%';
    }

    $uid = req_str('uid', '');
    if ($uid !== '' && column_exists($db, 'orders', 'uid')) {
        $where[] = 'o.uid = ?';
        $params[] = intval($uid);
    }

    $status = req_str('status', '');
    if ($status !== '' && column_exists($db, 'orders', 'status')) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $countRow = ($db->query("SELECT COUNT(*) AS c FROM orders o WHERE {$whereSql}", $params)[0] ?? []);
    $total = intval($countRow['c'] ?? 0);

    $fields = [];
    foreach (['id', 'order_number', 'uid', 'reservation_id', 'payment_amount', 'pays_type', 'status', 'end_time', 'created_at', 'note'] as $col) {
        if (column_exists($db, 'orders', $col)) {
            $fields[] = 'o.' . $col;
        }
    }
    if (!$fields) $fields[] = '*';

    $sql = "SELECT " . implode(', ', $fields) . "
            FROM orders o
            WHERE {$whereSql}
            ORDER BY o.{$timeCol} DESC
            LIMIT {$offset}, {$pageSize}";

    $rows = $db->query($sql, $params) ?: [];

    return [
        'range' => $range,
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'list' => $rows,
    ];
}

/* =========================================================
 * action=users 公共用户查询
 * ========================================================= */

function get_user_list($db, $user) {
    if (!table_exists($db, 'users')) {
        return ['list' => [], 'total' => 0];
    }

    [$page, $pageSize, $offset] = page_params();
    $range = get_time_range();
    $venueId = get_scoped_venue_id($user);

    $where = ['1=1'];
    $params = [];

    $useDate = req_int('use_date', 0, 0, 1);
    if ($useDate === 1 && column_exists($db, 'users', 'created_at')) {
        $where[] = 'u.created_at >= ?';
        $where[] = 'u.created_at < ?';
        $params[] = $range['start'];
        $params[] = $range['end'];
    }

    if (column_exists($db, 'users', 'venue_id')) {
        add_venue_where($where, $params, 'u', 'venue_id', $venueId);
    }

    $keyword = req_str('keyword', '');
    if ($keyword !== '') {
        $kwWhere = [];
        if (column_exists($db, 'users', 'uid') && is_numeric($keyword)) {
            $kwWhere[] = 'u.uid = ?';
            $params[] = intval($keyword);
        }
        foreach (['nickname', 'phone', 'mobile', 'username'] as $col) {
            if (column_exists($db, 'users', $col)) {
                $kwWhere[] = "u.{$col} LIKE ?";
                $params[] = '%' . $keyword . '%';
            }
        }
        if ($kwWhere) {
            $where[] = '(' . implode(' OR ', $kwWhere) . ')';
        }
    }

    $whereSql = implode(' AND ', $where);
    $countRow = ($db->query("SELECT COUNT(*) AS c FROM users u WHERE {$whereSql}", $params)[0] ?? []);
    $total = intval($countRow['c'] ?? 0);

    $fields = [];
    foreach (['uid', 'nickname', 'phone', 'mobile', 'wallet', 'created_at', 'last_active_at', 'venue_id', 'is_mute'] as $col) {
        if (column_exists($db, 'users', $col)) {
            $fields[] = 'u.' . $col;
        }
    }
    if (!$fields) $fields[] = '*';

    $orderCol = column_exists($db, 'users', 'created_at') ? 'created_at' : (column_exists($db, 'users', 'uid') ? 'uid' : '1');
    $sql = "SELECT " . implode(', ', $fields) . "
            FROM users u
            WHERE {$whereSql}
            ORDER BY u.{$orderCol} DESC
            LIMIT {$offset}, {$pageSize}";

    $rows = $db->query($sql, $params) ?: [];

    return [
        'range' => $range,
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'list' => $rows,
    ];
}

/* =========================================================
 * action=recharges 公共充值查询
 * ========================================================= */

function get_recharge_list($db, $user) {
    if (!table_exists($db, 'RechargeOrders')) {
        return ['list' => [], 'total' => 0];
    }

    [$page, $pageSize, $offset] = page_params();
    $range = get_time_range();
    $timeCol = first_existing_column($db, 'RechargeOrders', ['created_at', 'pay_time', 'updated_at'], 'created_at');

    $where = ["r.{$timeCol} >= ?", "r.{$timeCol} < ?"];
    $params = [$range['start'], $range['end']];

    $uid = req_str('uid', '');
    if ($uid !== '' && column_exists($db, 'RechargeOrders', 'uid')) {
        $where[] = 'r.uid = ?';
        $params[] = intval($uid);
    }

    $orderNumber = req_str('order_number', '');
    if ($orderNumber !== '' && column_exists($db, 'RechargeOrders', 'order_number')) {
        $where[] = 'r.order_number LIKE ?';
        $params[] = '%' . $orderNumber . '%';
    }

    $status = req_str('status', '');
    if ($status !== '' && column_exists($db, 'RechargeOrders', 'status')) {
        $statusMap = [
            'paid' => '支付成功',
            'pending' => '待支付',
        ];
        $where[] = 'r.status = ?';
        $params[] = $statusMap[$status] ?? $status;
    }

    $channel = req_str('channel', '');
    if ($channel !== '' && column_exists($db, 'RechargeOrders', 'channel')) {
        $where[] = 'r.channel = ?';
        $params[] = $channel;
    }

    $whereSql = implode(' AND ', $where);
    $countRow = ($db->query("SELECT COUNT(*) AS c FROM RechargeOrders r WHERE {$whereSql}", $params)[0] ?? []);
    $total = intval($countRow['c'] ?? 0);

    $sumAmount = 0;
    if (column_exists($db, 'RechargeOrders', 'payer_total')) {
        $sumRow = ($db->query("SELECT COALESCE(SUM(r.payer_total), 0) AS amount FROM RechargeOrders r WHERE {$whereSql}", $params)[0] ?? []);
        $sumAmount = floatval($sumRow['amount'] ?? 0);
    }

    $fields = [];
    foreach (['id', 'order_number', 'uid', 'payer_total', 'status', 'channel', 'created_at', 'pay_time'] as $col) {
        if (column_exists($db, 'RechargeOrders', $col)) {
            $fields[] = 'r.' . $col;
        }
    }
    if (!$fields) $fields[] = '*';

    $sql = "SELECT " . implode(', ', $fields) . "
            FROM RechargeOrders r
            WHERE {$whereSql}
            ORDER BY r.{$timeCol} DESC
            LIMIT {$offset}, {$pageSize}";

    $rows = $db->query($sql, $params) ?: [];

    return [
        'range' => $range,
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'amount' => money_fmt($sumAmount),
        'list' => $rows,
    ];
}
