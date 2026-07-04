<?php
/**
 * 公共查询 / 公共统计中心接口（增强版）
 *
 * 建议统一给后台 HTML 使用，不建议每个页面重复写一套列表/统计 PHP。
 *
 * 路径：single/api/common/common_query_stats.php
 * 兼容：single/api/admin_v2/common_query_stats.php 可 require 本文件。
 *
 * 设计原则：
 * 1. 前端不能传 table / sql / field 直接拼 SQL，只能传 action + resource 白名单。
 * 2. 时间统一左闭右开：start <= time < end。
 * 3. 管理员 role_id=1/2 可查全场地；其他角色默认只查自己的场地。
 * 4. 驾驶收入统一排除：能量、金币、礼物、娃娃机。
 */

require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$db = null;
try {
    $db = new Database();
    $user = cq_require_login($db);

    $action = cq_req_str('action', 'overview');

    // 兼容老调用：?action=orders / users / recharges
    $resourceActionMap = array(
        'orders' => 'orders',
        'users' => 'users',
        'recharges' => 'recharges',
        'refunds' => 'refunds',
        'withdrawals' => 'withdrawals',
        'reports' => 'reports',
        'devices' => 'vehicles',
    );

    if (isset($resourceActionMap[$action])) {
        $_GET['resource'] = $resourceActionMap[$action];
        $action = 'list';
    }

    switch ($action) {
        case 'me':
            cq_ok(cq_safe_user($user));
            break;

        case 'meta':
            cq_ok(cq_meta($db, $user));
            break;

        case 'venues':
            cq_ok(cq_get_venues($db, $user));
            break;

        case 'options':
        case 'filters':
            cq_ok(cq_get_options($db, $user));
            break;

        case 'overview':
        case 'dashboard':
            cq_ok(cq_get_overview($db, $user));
            break;

        case 'pending':
            cq_ok(cq_get_pending_summary($db, $user));
            break;

        case 'list':
            cq_ok(cq_resource_list($db, $user));
            break;

        case 'detail':
            cq_ok(cq_resource_detail($db, $user));
            break;

        case 'summary':
        case 'resource_summary':
            cq_ok(cq_resource_summary($db, $user));
            break;

        case 'trend':
        case 'income_trend':
            cq_ok(cq_metric_trend($db, $user));
            break;

        case 'rank':
        case 'venue_rank':
            cq_ok(cq_metric_rank($db, $user));
            break;

        default:
            cq_fail(400, '未知 action：' . $action);
    }
} catch (Throwable $e) {
    error_log('[common_query_stats] ' . $e->getMessage());
    cq_fail(500, '服务器错误', array('error' => $e->getMessage()));
} finally {
    if ($db) {
        $db->close();
    }
}

/* =========================================================
 * 基础输出 / 参数 / 登录
 * ========================================================= */

function cq_ok($data = array(), $extra = array()) {
    echo json_encode(array_merge(array(
        'code' => 0,
        'msg'  => 'ok',
        'data' => $data,
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cq_fail($code, $msg, $data = array()) {
    echo json_encode(array(
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cq_req_raw($name, $default = null) {
    if (isset($_GET[$name])) return $_GET[$name];
    if (isset($_POST[$name])) return $_POST[$name];
    return $default;
}

function cq_has_req($name) {
    return isset($_GET[$name]) || isset($_POST[$name]);
}

function cq_any_req($names) {
    foreach ($names as $name) {
        if (cq_has_req($name)) return true;
    }
    return false;
}

function cq_req_str($name, $default = '') {
    return trim(strval(cq_req_raw($name, $default)));
}

function cq_req_int($name, $default = 0, $min = null, $max = null) {
    $v = cq_req_raw($name, $default);
    $v = is_numeric($v) ? intval($v) : intval($default);
    if ($min !== null && $v < $min) $v = $min;
    if ($max !== null && $v > $max) $v = $max;
    return $v;
}

function cq_req_float($name, $default = null) {
    $v = cq_req_raw($name, $default);
    if ($v === null || $v === '') return $default;
    return is_numeric($v) ? floatval($v) : $default;
}

function cq_money($value) {
    return number_format(floatval($value), 2, '.', '');
}

function cq_int($value) {
    return intval($value ?: 0);
}

function cq_require_login($db) {
    $token = $_COOKIE['session_token'] ?? '';
    if ($token === '') {
        cq_fail(1001, '用户未登录或会话已过期');
    }

    $user = $db->getUserBySessionToken($token);
    if (!$user || empty($user['role_id'])) {
        cq_fail(1001, '用户未登录或无权访问');
    }

    return $user;
}

function cq_is_admin($user) {
    return in_array(intval($user['role_id'] ?? 0), array(1, 2), true);
}

function cq_safe_user($user) {
    return array(
        'id'       => cq_int($user['id'] ?? 0),
        'uid'      => cq_int($user['uid'] ?? $user['id'] ?? 0),
        'username' => strval($user['username'] ?? ''),
        'role_id'  => cq_int($user['role_id'] ?? 0),
        'venue_id' => cq_int($user['venue_id'] ?? 0),
        'is_admin' => cq_is_admin($user) ? 1 : 0,
    );
}

function cq_page_params() {
    $page = cq_req_int('page', 1, 1, 1000000);
    $pageSize = cq_req_int('page_size', cq_req_int('limit', 20), 1, 200);
    $offset = ($page - 1) * $pageSize;
    return array($page, $pageSize, $offset);
}

function cq_valid_date($s) {
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function cq_normalize_datetime($s) {
    $s = trim(strval($s));
    if ($s === '') return null;
    $s = str_replace('/', '-', $s);
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function cq_time_range() {
    $mode = strtolower(cq_req_str('mode', cq_req_str('period', 'day')));
    $date = cq_req_str('date', '');
    $startQ = cq_req_str('start_date', cq_req_str('start', ''));
    $endQ   = cq_req_str('end_date', cq_req_str('end', ''));

    if ($startQ !== '' && $endQ !== '') {
        if (cq_valid_date($startQ)) $startQ .= ' 00:00:00';
        // end_date 默认当作“结束日期当天 00:00:00”。例如 7-01 到 7-02 表示包含 7-01，不包含 7-02。
        if (cq_valid_date($endQ)) $endQ .= ' 00:00:00';
        $start = cq_normalize_datetime($startQ);
        $end = cq_normalize_datetime($endQ);
        if ($start && $end && strtotime($end) > strtotime($start)) {
            return array(
                'mode' => 'custom',
                'start' => $start,
                'end' => $end,
                'start_date' => substr($start, 0, 10),
                'end_date' => substr($end, 0, 10),
            );
        }
    }

    if ($date !== '' && cq_valid_date($date)) {
        $start = $date . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        return array('mode' => 'day', 'start' => $start, 'end' => $end, 'start_date' => substr($start, 0, 10), 'end_date' => substr($end, 0, 10));
    }

    if ($mode === 'yesterday') {
        $start = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end = date('Y-m-d 00:00:00');
    } elseif ($mode === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } elseif ($mode === 'month') {
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-01 00:00:00', strtotime('+1 month'));
    } elseif ($mode === 'year') {
        $start = date('Y-01-01 00:00:00');
        $end = date('Y-01-01 00:00:00', strtotime('+1 year'));
    } else {
        $mode = 'day';
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
    }

    return array('mode' => $mode, 'start' => $start, 'end' => $end, 'start_date' => substr($start, 0, 10), 'end_date' => substr($end, 0, 10));
}

/* =========================================================
 * 表 / 字段探测
 * ========================================================= */

function cq_table_exists($db, $table) {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $rows = $db->query(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        array($table)
    );
    $cache[$table] = intval($rows[0]['c'] ?? 0) > 0;
    return $cache[$table];
}

function cq_column_exists($db, $table, $column) {
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $rows = $db->query(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        array($table, $column)
    );
    $cache[$key] = intval($rows[0]['c'] ?? 0) > 0;
    return $cache[$key];
}

function cq_existing_columns($db, $table, $columns) {
    $out = array();
    foreach ($columns as $col) {
        if (cq_column_exists($db, $table, $col)) $out[] = $col;
    }
    return $out;
}

function cq_first_col($db, $table, $columns, $default = '') {
    foreach ($columns as $col) {
        if (cq_column_exists($db, $table, $col)) return $col;
    }
    return $default;
}

function cq_in_placeholders($count) {
    return implode(',', array_fill(0, $count, '?'));
}

function cq_add_in_where(&$where, &$params, $sqlField, $values) {
    $values = array_values(array_filter(array_unique(array_map('intval', $values)), function ($v) { return $v > 0; }));
    if (!$values) {
        $where[] = '1=0';
        return;
    }
    $where[] = $sqlField . ' IN (' . cq_in_placeholders(count($values)) . ')';
    foreach ($values as $v) $params[] = $v;
}

/* =========================================================
 * 资源白名单配置
 * ========================================================= */

function cq_resource_aliases() {
    return array(
        'order' => 'orders', 'drive_orders' => 'orders', 'driving_orders' => 'orders',
        'reservation' => 'reservations', 'reserve' => 'reservations',
        'recharge' => 'recharges', 'recharge_orders' => 'recharges',
        'refund' => 'refunds', 'withdraw' => 'withdrawals', 'withdrawal' => 'withdrawals',
        'report' => 'reports', 'complaints' => 'reports', 'complaint' => 'reports',
        'appeal' => 'appeals', 'order_appeal' => 'appeals',
        'device' => 'vehicles', 'devices' => 'vehicles', 'vehicle' => 'vehicles',
        'camera' => 'device_information', 'cameras' => 'device_information',
        'venue' => 'venues', 'user' => 'users', 'admin_user' => 'admin_users',
        'gift' => 'gift_orders', 'gifts' => 'gift_orders',
        'energy' => 'energy_records',
        'doll' => 'doll_records', 'claw' => 'doll_records', 'claw_records' => 'doll_records',
        'device_ban' => 'device_bans', 'venue_ban' => 'venue_bans', 'ban' => 'device_bans',
        'blacklist' => 'banned_users', 'mute' => 'banned_users',
        'order_lock' => 'order_locks', 'locks' => 'order_locks',
        'pricing' => 'pricing_options', 'pricing_options' => 'pricing_options',
        'zone' => 'zones', 'venue_fund' => 'venue_funds', 'funds' => 'venue_funds',
        'feedbacks' => 'feedback', 'notice' => 'official_notices', 'notices' => 'official_notices',
        'anchor_auth' => 'anchor_realname_auth', 'app_image' => 'app_images', 'app_images' => 'app_images',
        'balance' => 'balance_changes', 'balance_changes' => 'balance_changes',
    );
}

function cq_resolve_resource($resource) {
    $resource = strtolower(trim(strval($resource)));
    $aliases = cq_resource_aliases();
    return $aliases[$resource] ?? $resource;
}

function cq_resources() {
    return array(
        'venues' => array(
            'title' => '场地', 'table' => 'venues', 'alias' => 'v', 'pk' => array('id'),
            'fields' => array('id','name','venue_name','uid','venue_status','status','is_banned','dr_venue_id','queue_length','open_time','close_time','venue_level','withdraw_ratio','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('id'),
            'keyword' => array('id','name','venue_name','uid'), 'filters' => array('id','uid','venue_status','status','is_banned','venue_level'),
            'default_date' => false,
        ),
        'vehicles' => array(
            'title' => '设备', 'table' => 'vehicles', 'alias' => 'v', 'pk' => array('id','serial_number'),
            'fields' => array('id','serial_number','name','uid','bind_site','status','vehicle_status','sharing_status','start_status','is_banned','image_device_serial','bk_image_device_serial','driver_id','driver','Reservation_lock','photo_url','created_at','updated_at'),
            'time' => array('updated_at','created_at','insert_time'), 'venue' => array('bind_site','venue_id'),
            'keyword' => array('serial_number','name','uid','bind_site','image_device_serial'),
            'filters' => array('uid','bind_site','status','vehicle_status','sharing_status','start_status','is_banned','image_device_serial'),
            'default_date' => false,
        ),
        'device_information' => array(
            'title' => '摄像/图传信息', 'table' => 'device_information', 'alias' => 'd', 'pk' => array('id','device_id','room_id'),
            'fields' => array('id','device_id','playing_stream_id','room_id','rtc_user_id','created_at','updated_at'),
            'time' => array('updated_at','created_at'), 'venue' => array(),
            'keyword' => array('id','device_id','playing_stream_id','room_id','rtc_user_id'),
            'filters' => array('device_id','room_id','rtc_user_id'), 'default_date' => false,
        ),
        'users' => array(
            'title' => '用户', 'table' => 'users', 'alias' => 'u', 'pk' => array('uid','id'),
            'fields' => array('uid','id','nickname','phone','mobile','username','wallet','gold','venue_id','is_mute','status','headimgurl','created_at','last_active_at','updated_at'),
            'time' => array('created_at','last_active_at','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('uid','id','nickname','phone','mobile','username'),
            'filters' => array('uid','id','venue_id','is_mute','status'), 'default_date' => false,
        ),
        'admin_users' => array(
            'title' => '后台账号', 'table' => 'admin_users', 'alias' => 'a', 'pk' => array('id','uid'), 'admin_only' => true,
            'fields' => array('id','uid','username','role_id','venue_id','guild_id','created_at','updated_at','session_expires'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','uid','username','venue_id'), 'filters' => array('id','uid','role_id','venue_id','username'), 'default_date' => false,
        ),
        'orders' => array(
            'title' => '订单', 'table' => 'orders', 'alias' => 'o', 'pk' => array('id','order_number','order_id'),
            'fields' => array('id','order_id','order_number','uid','user_id','reservation_id','reservation_location','serial_number','device_id','payment_amount','pay_money','pays_type','pay_type','status','order_status','start_time','end_time','driving_start_time','driving_end_time','created_at','updated_at','note','billing_rules','channel'),
            'time' => array('end_time','created_at','start_time','driving_end_time'), 'venue' => array('reservation_id','venue_id','bind_site'),
            'keyword' => array('order_number','order_id','uid','user_id','reservation_id','serial_number','device_id'),
            'filters' => array('uid','user_id','reservation_id','serial_number','device_id','status','order_status','pays_type','pay_type','channel'),
            'amount' => array('payment_amount','pay_money'), 'default_date' => true,
        ),
        'reservations' => array(
            'title' => '预约/驾驶记录', 'table' => 'Reservations', 'alias' => 'r', 'pk' => array('id','order_number'),
            'fields' => array('id','order_number','user_id','uid','reservation_id','reservation_type','reservation_location','reservation_time','order_status','driving_duration','pay_type','pay_money','device_type','notification_status','driver_id','created_at','updated_at'),
            'time' => array('reservation_time','created_at','driving_start_time'), 'venue' => array('reservation_id','venue_id'),
            'keyword' => array('order_number','user_id','uid','reservation_id','reservation_location'),
            'filters' => array('user_id','uid','reservation_id','reservation_type','order_status','pay_type','device_type'),
            'amount' => array('pay_money'), 'default_date' => true,
        ),
        'recharges' => array(
            'title' => '充值', 'table' => 'RechargeOrders', 'alias' => 'r', 'pk' => array('id','order_number'), 'admin_only' => true,
            'fields' => array('id','order_number','uid','payer_total','status','channel','payment_channel','product_id','product_name','recharge_type','third_party','transaction_id','created_at','paid_at','pay_time','updated_at'),
            'time' => array('created_at','paid_at','pay_time','updated_at'), 'venue' => array(),
            'keyword' => array('order_number','uid','transaction_id','product_name'),
            'filters' => array('uid','status','channel','payment_channel','product_id','recharge_type','third_party'),
            'amount' => array('payer_total'), 'default_date' => true,
        ),
        'refunds' => array(
            'title' => '退款', 'table' => 'refund_records', 'alias' => 'rr', 'pk' => array('id'),
            'fields' => array('id','order_id','uid','reservation_id','refund_amount','reason','applicant_admin_uid','status','is_reduced','created_at','updated_at','refund_time'),
            'time' => array('created_at','refund_time','updated_at'), 'venue' => array('reservation_id','venue_id'),
            'keyword' => array('id','order_id','uid','reservation_id','reason'),
            'filters' => array('uid','reservation_id','status','is_reduced','applicant_admin_uid'),
            'amount' => array('refund_amount','amount'), 'default_date' => true,
        ),
        'withdrawals' => array(
            'title' => '提现', 'table' => 'withdrawal_requests', 'alias' => 'w', 'pk' => array('id'),
            'fields' => array('id','uid','venue_id','account_name','account_type','withdrawal_account','withdrawal_amount','technical_service_fee','withdrawal_fee','actual_amount','application_time','payout_time','application_status','payout_status','withdrawal_type','created_at','updated_at'),
            'time' => array('application_time','created_at','updated_at'), 'venue' => array('venue_id','reservation_id'),
            'keyword' => array('id','uid','venue_id','account_name','withdrawal_account'),
            'filters' => array('uid','venue_id','application_status','payout_status','withdrawal_type','account_type'),
            'amount' => array('withdrawal_amount','actual_amount'), 'default_date' => true,
        ),
        'reports' => array(
            'title' => '投诉/举报', 'table' => 'Reports', 'alias' => 'rp', 'pk' => array('id'),
            'fields' => array('id','uid','reporter_uid','device_id','reservation_id','report_type','notes','image_url','status','handler_uid','insert_time','created_at','updated_at'),
            'time' => array('created_at','insert_time','updated_at'), 'venue' => array('reservation_id','venue_id','bind_site'),
            'keyword' => array('id','uid','reporter_uid','device_id','reservation_id','report_type','notes'),
            'filters' => array('uid','reporter_uid','device_id','reservation_id','report_type','status','handler_uid'),
            'default_date' => true,
        ),
        'appeals' => array(
            'title' => '订单申诉', 'table' => 'order_appeals', 'alias' => 'oa', 'pk' => array('id'),
            'fields' => array('id','order_number','uid','venue_id','reservation_id','reason','content','image_url','status','handler_uid','handle_result','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id','reservation_id'),
            'keyword' => array('id','order_number','uid','venue_id','reservation_id','reason','content'),
            'filters' => array('uid','venue_id','reservation_id','status','handler_uid'), 'default_date' => true,
        ),
        'device_bans' => array(
            'title' => '设备违规/封禁', 'table' => 'device_bans', 'alias' => 'dbn', 'pk' => array('id'),
            'fields' => array('id','serial_number','venue_id','bind_site','ban_type','ban_reason','reason','status','start_time','end_time','created_at','updated_at','operator_uid','admin_uid'),
            'time' => array('created_at','start_time','updated_at'), 'venue' => array('venue_id','bind_site'),
            'keyword' => array('id','serial_number','ban_type','ban_reason','reason'),
            'filters' => array('serial_number','venue_id','bind_site','ban_type','status','operator_uid','admin_uid'), 'default_date' => true,
        ),
        'venue_bans' => array(
            'title' => '场地违规/封禁', 'table' => 'venue_bans', 'alias' => 'vbn', 'pk' => array('id'),
            'fields' => array('id','venue_id','ban_type','ban_reason','reason','status','start_time','end_time','created_at','updated_at','operator_uid','admin_uid'),
            'time' => array('created_at','start_time','updated_at'), 'venue' => array('venue_id','dr_venue_id'),
            'keyword' => array('id','venue_id','ban_type','ban_reason','reason'),
            'filters' => array('venue_id','ban_type','status','operator_uid','admin_uid'), 'default_date' => true,
        ),
        'banned_users' => array(
            'title' => '拉黑/禁言用户', 'table' => 'banned_users', 'alias' => 'bu', 'pk' => array('id'),
            'fields' => array('id','admin_uid','venue_id','banned_uid','reason','start_time','end_time','created_at','updated_at'),
            'time' => array('created_at','start_time','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','admin_uid','venue_id','banned_uid','reason'),
            'filters' => array('admin_uid','venue_id','banned_uid'), 'default_date' => true,
        ),
        'order_locks' => array(
            'title' => '锁定订单', 'table' => 'order_lock_records', 'alias' => 'ol', 'pk' => array('id'),
            'fields' => array('id','order_number','order_id','venue_id','uid','reservation_id','lock_status','status','reason','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id','reservation_id'),
            'keyword' => array('id','order_number','order_id','uid','venue_id','reservation_id','reason'),
            'filters' => array('uid','venue_id','reservation_id','lock_status','status'), 'default_date' => true,
        ),
        'gift_orders' => array(
            'title' => '礼物订单', 'table' => 'gift_orders', 'alias' => 'g', 'pk' => array('id','order_id'),
            'fields' => array('id','order_id','uid','reservation_id','gift_id','payment_amount','status','send_time','created_at','updated_at'),
            'time' => array('send_time','created_at','updated_at'), 'venue' => array('reservation_id','venue_id'),
            'keyword' => array('id','order_id','uid','reservation_id','gift_id'),
            'filters' => array('uid','reservation_id','gift_id','status'), 'amount' => array('payment_amount'), 'default_date' => true,
        ),
        'energy_records' => array(
            'title' => '能量记录', 'table' => 'energy_records', 'alias' => 'er', 'pk' => array('id'),
            'fields' => array('id','uid','venue_id','reservation_id','energy','amount','type','remark','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id','reservation_id'),
            'keyword' => array('id','uid','venue_id','reservation_id','type','remark'),
            'filters' => array('uid','venue_id','reservation_id','type'), 'amount' => array('amount','energy'), 'default_date' => true,
        ),
        'doll_records' => array(
            'title' => '娃娃机记录', 'table' => 'claw_machine_records', 'alias' => 'cr', 'pk' => array('id','order_id'),
            'fields' => array('id','order_id','uid','serial_number','payment_amount','status','note','start_time','end_time','created_at','updated_at'),
            'time' => array('end_time','start_time','created_at'), 'venue' => array('venue_id','reservation_id','bind_site'),
            'keyword' => array('id','order_id','uid','serial_number','note'),
            'filters' => array('uid','serial_number','status'), 'amount' => array('payment_amount'), 'default_date' => true,
        ),
        'pricing_options' => array(
            'title' => '资费套餐', 'table' => 'PricingOptions', 'alias' => 'p', 'pk' => array('id'),
            'fields' => array('id','BindLocation','PricingType','Minutes','Battery','Price','price','status','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('BindLocation','venue_id'),
            'keyword' => array('id','BindLocation','PricingType'), 'filters' => array('BindLocation','PricingType','status'),
            'amount' => array('Price','price'), 'default_date' => false,
        ),
        'zones' => array(
            'title' => '专区', 'table' => 'zones', 'alias' => 'z', 'pk' => array('id'),
            'fields' => array('id','venue_id','name','zone_name','title','status','sort','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','venue_id','name','zone_name','title'), 'filters' => array('venue_id','status'), 'default_date' => false,
        ),
        'venue_funds' => array(
            'title' => '场地资金', 'table' => 'venue_funds', 'alias' => 'vf', 'pk' => array('id','venue_id'),
            'fields' => array('id','venue_id','uid','account_balance','gift_balance','frozen_amount','locked_amount','not_settled','pending_30d','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','venue_id','uid'), 'filters' => array('venue_id','uid'),
            'amount' => array('account_balance','gift_balance','frozen_amount','locked_amount','not_settled','pending_30d'), 'default_date' => false,
        ),
        'feedback' => array(
            'title' => '反馈', 'table' => 'feedback', 'alias' => 'f', 'pk' => array('id'),
            'fields' => array('id','uid','venue_id','message','image_url','status','reply_content','replied_by_uid','replied_at','created_at','updated_at'),
            'time' => array('created_at','updated_at','replied_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','uid','venue_id','message','reply_content'),
            'filters' => array('uid','venue_id','status','replied_by_uid'), 'default_date' => true,
        ),
        'official_notices' => array(
            'title' => '官方通知', 'table' => 'official_notices', 'alias' => 'n', 'pk' => array('id'), 'scope_required' => false,
            'fields' => array('id','title','content','importance','status','publish_time','created_at','updated_at'),
            'time' => array('publish_time','created_at','updated_at'), 'venue' => array(),
            'keyword' => array('id','title','content'), 'filters' => array('status','importance'), 'default_date' => false,
        ),
        'anchor_realname_auth' => array(
            'title' => '主播认证', 'table' => 'anchor_realname_auth', 'alias' => 'ar', 'pk' => array('id'), 'admin_only' => true,
            'fields' => array('id','uid','realname','id_card','venue_id','status','is_unbound','unbind_time','created_at','updated_at'),
            'time' => array('created_at','updated_at','unbind_time'), 'venue' => array('venue_id'),
            'keyword' => array('id','uid','realname','id_card','venue_id'), 'filters' => array('uid','venue_id','status','is_unbound'), 'default_date' => true,
        ),
        'app_images' => array(
            'title' => '轮播图', 'table' => 'app_images', 'alias' => 'ai', 'pk' => array('id'), 'admin_only' => true,
            'fields' => array('id','title','image_url','link_url','sort','status','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array(), 'scope_required' => false,
            'keyword' => array('id','title','image_url','link_url'), 'filters' => array('status'), 'default_date' => false,
        ),
        'balance_changes' => array(
            'title' => '余额流水', 'table' => 'balance_changes', 'alias' => 'bc', 'pk' => array('id'), 'admin_only' => true,
            'fields' => array('id','uid','venue_id','amount','change_amount','balance_before','balance_after','type','remark','created_at','updated_at'),
            'time' => array('created_at','updated_at'), 'venue' => array('venue_id'),
            'keyword' => array('id','uid','venue_id','type','remark'), 'filters' => array('uid','venue_id','type'),
            'amount' => array('amount','change_amount'), 'default_date' => true,
        ),
    );
}

function cq_get_resource_config($resource) {
    $key = cq_resolve_resource($resource);
    $map = cq_resources();
    if (!isset($map[$key])) return array(null, null);
    return array($key, $map[$key]);
}

/* =========================================================
 * 权限范围 / 场地范围
 * ========================================================= */

function cq_accessible_venue_ids($db, $user) {
    // 管理员：venue_id=0 表示全场地，指定 venue_id 时只查指定场地。
    if (cq_is_admin($user)) {
        $venueId = cq_req_int('venue_id', 0, 0);
        return $venueId > 0 ? array($venueId) : null;
    }

    $ids = array();
    $mainVenueId = cq_int($user['venue_id'] ?? 0);
    if ($mainVenueId > 0) $ids[] = $mainVenueId;

    // 兼容后续多场地加盟商表：有就读，没有就忽略。
    if (cq_table_exists($db, 'admin_user_venues')) {
        $adminId = cq_int($user['id'] ?? 0);
        $uid = cq_int($user['uid'] ?? 0);
        $where = array();
        $params = array();
        if ($adminId > 0 && cq_column_exists($db, 'admin_user_venues', 'admin_user_id')) {
            $where[] = 'admin_user_id = ?';
            $params[] = $adminId;
        }
        if ($uid > 0 && cq_column_exists($db, 'admin_user_venues', 'admin_uid')) {
            $where[] = 'admin_uid = ?';
            $params[] = $uid;
        }
        if ($where && cq_column_exists($db, 'admin_user_venues', 'venue_id')) {
            $rows = $db->query('SELECT venue_id FROM admin_user_venues WHERE ' . implode(' OR ', $where), $params) ?: array();
            foreach ($rows as $row) {
                $vid = cq_int($row['venue_id'] ?? 0);
                if ($vid > 0) $ids[] = $vid;
            }
        }
    }

    $ids = array_values(array_unique(array_filter($ids, function ($v) { return intval($v) > 0; })));
    return $ids;
}

function cq_apply_scope(&$where, &$params, $db, $cfg, $user) {
    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $scopeRequired = array_key_exists('scope_required', $cfg) ? (bool)$cfg['scope_required'] : true;
    $venueFields = $cfg['venue'] ?? array();
    $venueIds = cq_accessible_venue_ids($db, $user);

    // 管理员且未指定 venue_id：全量，不加范围。
    if ($venueIds === null) return;

    // 非管理员或管理员指定 venue_id：必须能找到场地字段才能过滤。
    $field = cq_first_col($db, $table, $venueFields, '');
    if ($field === '') {
        if ($scopeRequired) {
            $where[] = '1=0';
        }
        return;
    }

    cq_add_in_where($where, $params, $alias . '.' . $field, $venueIds);
}

function cq_get_venues($db, $user) {
    if (!cq_table_exists($db, 'venues')) return array();

    $nameCol = cq_first_col($db, 'venues', array('venue_name','name'), 'id');
    $fields = array('id', $nameCol . ' AS venue_name');
    foreach (array('uid','venue_status','status','is_banned','queue_length','venue_level','withdraw_ratio') as $col) {
        if (cq_column_exists($db, 'venues', $col)) $fields[] = $col;
    }

    $where = array();
    $params = array();
    $ids = cq_accessible_venue_ids($db, $user);
    if ($ids !== null) cq_add_in_where($where, $params, 'id', $ids);

    $sql = 'SELECT ' . implode(', ', $fields) . ' FROM venues';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id ASC';
    return $db->query($sql, $params) ?: array();
}

/* =========================================================
 * 统一业务口径
 * ========================================================= */

function cq_append_drive_filter($db, &$where, $alias) {
    $p = $alias . '.';
    if (cq_column_exists($db, 'orders', 'pays_type')) {
        $where[] = '(' . $p . "pays_type IS NULL OR " . $p . "pays_type NOT IN ('能量','金币','礼物','娃娃机'))";
    }
    if (cq_column_exists($db, 'orders', 'pay_type')) {
        $where[] = '(' . $p . "pay_type IS NULL OR " . $p . "pay_type NOT IN ('能量','金币','礼物','娃娃机'))";
    }
    if (cq_column_exists($db, 'orders', 'note')) {
        $where[] = '(' . $p . "note IS NULL OR (" .
            $p . "note <> 'gift' AND " .
            $p . "note <> '礼物' AND " .
            $p . "note NOT LIKE '%娃娃机%' AND " .
            $p . "note NOT LIKE '%claw%'" .
        '))';
    }
}

function cq_paid_status_where($db, $table, $alias, &$where) {
    if (cq_column_exists($db, $table, 'status')) {
        $where[] = "(" . $alias . ".status IN ('支付成功','已完成','success','paid') OR " . $alias . ".status = 1)";
    }
}

/* =========================================================
 * action=meta
 * ========================================================= */

function cq_meta($db, $user) {
    $resources = array();
    foreach (cq_resources() as $key => $cfg) {
        if (!empty($cfg['admin_only']) && !cq_is_admin($user)) continue;
        $resources[$key] = array(
            'title' => $cfg['title'],
            'table' => $cfg['table'],
            'exists' => cq_table_exists($db, $cfg['table']) ? 1 : 0,
            'default_date' => !empty($cfg['default_date']) ? 1 : 0,
            'amount_fields' => cq_existing_columns($db, $cfg['table'], $cfg['amount'] ?? array()),
            'time_fields' => cq_existing_columns($db, $cfg['table'], $cfg['time'] ?? array()),
            'filter_fields' => cq_existing_columns($db, $cfg['table'], $cfg['filters'] ?? array()),
        );
    }

    return array(
        'user' => cq_safe_user($user),
        'actions' => array('me','meta','venues','options','overview','pending','list','detail','summary','trend','rank'),
        'resources' => $resources,
        'metrics' => array_keys(cq_metric_configs()),
        'range_modes' => array('day','yesterday','week','month','year','custom'),
    );
}

/* =========================================================
 * action=list / detail / summary
 * ========================================================= */

function cq_build_resource_where($db, $user, $resource, $forSummary = false) {
    list($key, $cfg) = cq_get_resource_config($resource);
    if (!$cfg) cq_fail(400, '未知 resource：' . $resource);
    if (!empty($cfg['admin_only']) && !cq_is_admin($user)) cq_fail(1002, '无权访问该资源');
    if (!cq_table_exists($db, $cfg['table'])) {
        return array($key, $cfg, array('1=0'), array(), null, false);
    }

    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $range = cq_time_range();
    $where = array('1=1');
    $params = array();

    $timeCol = cq_first_col($db, $table, $cfg['time'] ?? array(), '');
    $dateExplicit = cq_any_req(array('mode','period','date','start_date','end_date','start','end'));
    $defaultUseDate = !empty($cfg['default_date']) ? 1 : ($dateExplicit ? 1 : 0);
    $useDate = cq_req_int('use_date', $defaultUseDate, 0, 1);
    if ($useDate === 1 && $timeCol !== '') {
        $where[] = $alias . '.' . $timeCol . ' >= ?';
        $where[] = $alias . '.' . $timeCol . ' < ?';
        $params[] = $range['start'];
        $params[] = $range['end'];
    }

    cq_apply_scope($where, $params, $db, $cfg, $user);

    if ($key === 'orders') {
        $business = strtolower(cq_req_str('business', ''));
        $driveOnly = cq_req_int('drive_only', $business === 'drive' ? 1 : 0, 0, 1);
        if ($driveOnly === 1) cq_append_drive_filter($db, $where, $alias);
    }

    $keyword = cq_req_str('keyword', cq_req_str('q', ''));
    if ($keyword !== '') {
        $kw = array();
        $kwParams = array();
        foreach (($cfg['keyword'] ?? array()) as $col) {
            if (!cq_column_exists($db, $table, $col)) continue;
            if (is_numeric($keyword) && preg_match('/(_id|^id$|uid$|number$|site$)/i', $col)) {
                $kw[] = $alias . '.' . $col . ' = ?';
                $kwParams[] = $keyword;
            }
            $kw[] = $alias . '.' . $col . ' LIKE ?';
            $kwParams[] = '%' . $keyword . '%';
        }
        if ($kw) {
            $where[] = '(' . implode(' OR ', $kw) . ')';
            foreach ($kwParams as $p) $params[] = $p;
        }
    }

    // 常规字段过滤：?status=xxx&uid=xxx&venue_id=xxx 等。
    foreach (($cfg['filters'] ?? array()) as $col) {
        if (!cq_column_exists($db, $table, $col)) continue;
        if (!cq_has_req($col)) continue;
        $value = cq_req_str($col, '');
        if ($value === '') continue;
        if (strpos($value, ',') !== false) {
            $items = array_filter(array_map('trim', explode(',', $value)), 'strlen');
            if ($items) {
                $where[] = $alias . '.' . $col . ' IN (' . cq_in_placeholders(count($items)) . ')';
                foreach ($items as $item) $params[] = $item;
            }
        } else {
            $where[] = $alias . '.' . $col . ' = ?';
            $params[] = $value;
        }
    }

    // 兼容常用别名参数。
    $aliasFilters = array(
        'order_no' => array('order_number','order_id'),
        'sn' => array('serial_number','device_id'),
        'device_sn' => array('serial_number','device_id'),
        'banned_uid' => array('banned_uid'),
    );
    foreach ($aliasFilters as $paramName => $cols) {
        $value = cq_req_str($paramName, '');
        if ($value === '') continue;
        $or = array();
        foreach ($cols as $col) {
            if (cq_column_exists($db, $table, $col)) {
                $or[] = $alias . '.' . $col . ' = ?';
                $params[] = $value;
            }
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
    }

    $amountCol = cq_first_col($db, $table, $cfg['amount'] ?? array(), '');
    $minAmount = cq_req_float('min_amount', null);
    $maxAmount = cq_req_float('max_amount', null);
    if ($amountCol !== '' && $minAmount !== null) {
        $where[] = $alias . '.' . $amountCol . ' >= ?';
        $params[] = $minAmount;
    }
    if ($amountCol !== '' && $maxAmount !== null) {
        $where[] = $alias . '.' . $amountCol . ' <= ?';
        $params[] = $maxAmount;
    }

    return array($key, $cfg, $where, $params, $range, $useDate === 1);
}

function cq_resource_list($db, $user) {
    $resource = cq_req_str('resource', 'orders');
    list($key, $cfg, $where, $params, $range, $usedDate) = cq_build_resource_where($db, $user, $resource);
    $table = $cfg['table'];
    $alias = $cfg['alias'];
    list($page, $pageSize, $offset) = cq_page_params();

    if ($where === array('1=0') || !cq_table_exists($db, $table)) {
        return array('resource' => $key, 'title' => $cfg['title'], 'page' => $page, 'page_size' => $pageSize, 'total' => 0, 'list' => array());
    }

    $whereSql = implode(' AND ', $where);
    $countRow = ($db->query('SELECT COUNT(*) AS c FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql, $params)[0] ?? array());
    $total = cq_int($countRow['c'] ?? 0);

    $sum = cq_resource_sums($db, $cfg, $whereSql, $params);

    $fields = cq_existing_columns($db, $table, $cfg['fields'] ?? array());
    if (!$fields) $fields = cq_existing_columns($db, $table, $cfg['pk'] ?? array());
    if (!$fields) cq_fail(500, '资源字段配置为空：' . $key);
    $select = implode(', ', array_map(function ($col) use ($alias) { return $alias . '.' . $col; }, $fields));

    $orderCol = cq_sort_column($db, $cfg);
    $orderDir = strtolower(cq_req_str('order_dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $sql = 'SELECT ' . $select . ' FROM ' . $table . ' ' . $alias .
        ' WHERE ' . $whereSql .
        ' ORDER BY ' . $alias . '.' . $orderCol . ' ' . $orderDir .
        ' LIMIT ' . intval($offset) . ', ' . intval($pageSize);

    $rows = $db->query($sql, $params) ?: array();

    return array(
        'resource' => $key,
        'title' => $cfg['title'],
        'range' => $usedDate ? $range : null,
        'scope' => cq_scope_payload($db, $user),
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'sum' => $sum,
        'fields' => $fields,
        'list' => $rows,
    );
}

function cq_sort_column($db, $cfg) {
    $table = $cfg['table'];
    $sort = cq_req_str('sort', '');
    $allowed = array_unique(array_merge($cfg['fields'] ?? array(), $cfg['time'] ?? array(), $cfg['pk'] ?? array(), $cfg['amount'] ?? array()));
    if ($sort !== '' && in_array($sort, $allowed, true) && cq_column_exists($db, $table, $sort)) return $sort;
    $time = cq_first_col($db, $table, $cfg['time'] ?? array(), '');
    if ($time !== '') return $time;
    return cq_first_col($db, $table, $cfg['pk'] ?? array(), cq_existing_columns($db, $table, $cfg['fields'] ?? array())[0] ?? 'id');
}

function cq_resource_sums($db, $cfg, $whereSql, $params) {
    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $amountCols = cq_existing_columns($db, $table, $cfg['amount'] ?? array());
    if (!$amountCols) return array();
    $parts = array();
    foreach ($amountCols as $col) {
        $parts[] = 'COALESCE(SUM(' . $alias . '.' . $col . '),0) AS sum_' . $col;
    }
    $row = ($db->query('SELECT ' . implode(', ', $parts) . ' FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql, $params)[0] ?? array());
    $out = array();
    foreach ($amountCols as $col) {
        $out[$col] = cq_money($row['sum_' . $col] ?? 0);
    }
    return $out;
}

function cq_resource_detail($db, $user) {
    $resource = cq_req_str('resource', 'orders');
    list($key, $cfg) = cq_get_resource_config($resource);
    if (!$cfg) cq_fail(400, '未知 resource：' . $resource);
    if (!empty($cfg['admin_only']) && !cq_is_admin($user)) cq_fail(1002, '无权访问该资源');
    if (!cq_table_exists($db, $cfg['table'])) cq_fail(404, '数据表不存在：' . $cfg['table']);

    $id = cq_req_str('id', '');
    if ($id === '') cq_fail(400, '缺少 id');

    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $pk = cq_first_col($db, $table, $cfg['pk'] ?? array(), '');
    if ($pk === '') cq_fail(500, '未配置主键：' . $key);

    $where = array($alias . '.' . $pk . ' = ?');
    $params = array($id);
    cq_apply_scope($where, $params, $db, $cfg, $user);

    $fields = cq_existing_columns($db, $table, $cfg['fields'] ?? array());
    if (!$fields) $fields = cq_existing_columns($db, $table, $cfg['pk'] ?? array());
    $select = implode(', ', array_map(function ($col) use ($alias) { return $alias . '.' . $col; }, $fields));
    $rows = $db->query('SELECT ' . $select . ' FROM ' . $table . ' ' . $alias . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $params) ?: array();
    return array('resource' => $key, 'title' => $cfg['title'], 'row' => $rows[0] ?? null);
}

function cq_resource_summary($db, $user) {
    $resource = cq_req_str('resource', 'orders');
    list($key, $cfg, $where, $params, $range, $usedDate) = cq_build_resource_where($db, $user, $resource, true);
    $table = $cfg['table'];
    $alias = $cfg['alias'];
    if (!cq_table_exists($db, $table)) return array('resource' => $key, 'total' => 0);

    $whereSql = implode(' AND ', $where);
    $countRow = ($db->query('SELECT COUNT(*) AS c FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql, $params)[0] ?? array());
    $sums = cq_resource_sums($db, $cfg, $whereSql, $params);

    $statusBreakdown = array();
    $statusCol = cq_first_col($db, $table, array('status','order_status','application_status','payout_status','venue_status'), '');
    if ($statusCol !== '') {
        $rows = $db->query('SELECT ' . $alias . '.' . $statusCol . ' AS k, COUNT(*) AS c FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql . ' GROUP BY ' . $alias . '.' . $statusCol . ' ORDER BY c DESC LIMIT 50', $params) ?: array();
        foreach ($rows as $row) $statusBreakdown[] = array('key' => strval($row['k'] ?? ''), 'count' => cq_int($row['c'] ?? 0));
    }

    $daily = array();
    $timeCol = cq_first_col($db, $table, $cfg['time'] ?? array(), '');
    if ($usedDate && $timeCol !== '') {
        $rows = $db->query('SELECT DATE(' . $alias . '.' . $timeCol . ') AS stat_date, COUNT(*) AS c FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql . ' GROUP BY DATE(' . $alias . '.' . $timeCol . ') ORDER BY stat_date ASC', $params) ?: array();
        foreach ($rows as $row) $daily[] = array('date' => strval($row['stat_date'] ?? ''), 'count' => cq_int($row['c'] ?? 0));
    }

    return array(
        'resource' => $key,
        'title' => $cfg['title'],
        'range' => $usedDate ? $range : null,
        'scope' => cq_scope_payload($db, $user),
        'total' => cq_int($countRow['c'] ?? 0),
        'sum' => $sums,
        'status_breakdown' => $statusBreakdown,
        'daily_count' => $daily,
    );
}

/* =========================================================
 * action=overview / pending
 * ========================================================= */

function cq_get_overview($db, $user) {
    $range = cq_time_range();
    $scope = cq_scope_payload($db, $user);

    $drive = cq_metric_total($db, $user, 'drive_income', $range);
    $driveCount = cq_metric_total($db, $user, 'drive_order_count', $range);
    $recharge = cq_metric_total($db, $user, 'recharge_amount', $range);
    $rechargeCount = cq_metric_total($db, $user, 'recharge_count', $range);
    $refund = cq_metric_total($db, $user, 'refund_amount', $range);
    $refundCount = cq_metric_total($db, $user, 'refund_count', $range);
    $gift = cq_metric_total($db, $user, 'gift_income', $range);
    $giftCount = cq_metric_total($db, $user, 'gift_count', $range);
    $doll = cq_metric_total($db, $user, 'doll_amount', $range);
    $dollCount = cq_metric_total($db, $user, 'doll_count', $range);
    $newUsers = cq_metric_total($db, $user, 'new_users', $range);
    $activeUsers = cq_metric_total($db, $user, 'active_users', $range);
    $devices = cq_device_overview($db, $user);
    $pending = cq_get_pending_summary($db, $user);

    return array(
        'range' => $range,
        'scope' => $scope,
        'cards' => array(
            'drive_income' => cq_money($drive),
            'drive_order_count' => cq_int($driveCount),
            'recharge_amount' => cq_money($recharge),
            'recharge_count' => cq_int($rechargeCount),
            'refund_amount' => cq_money($refund),
            'refund_count' => cq_int($refundCount),
            'gift_income' => cq_money($gift),
            'gift_count' => cq_int($giftCount),
            'doll_amount' => cq_money($doll),
            'doll_count' => cq_int($dollCount),
            'new_users' => cq_int($newUsers),
            'active_users' => cq_int($activeUsers),
            'device_total' => cq_int($devices['total'] ?? 0),
            'device_online' => cq_int($devices['online'] ?? 0),
            'device_offline' => cq_int($devices['offline'] ?? 0),
            'device_using' => cq_int($devices['using'] ?? 0),
        ),
        'pending' => $pending['items'],
        'explain' => array(
            'drive_income' => 'orders.payment_amount，按 end_time/created_at 统计，排除能量、金币、礼物、娃娃机。',
            'recharge_amount' => 'RechargeOrders.payer_total，默认只统计支付成功。',
            'gift_income' => 'gift_orders.payment_amount / 10 * 0.6，单独统计，不混入驾驶收入。',
            'range_rule' => '左闭右开：start <= time < end。',
        ),
    );
}

function cq_get_pending_summary($db, $user) {
    $items = array();
    $items[] = array('key' => 'reports', 'title' => '投诉处理', 'count' => cq_pending_count($db, $user, 'reports', array('未处理','处理中','待处理','pending')));
    $items[] = array('key' => 'appeals', 'title' => '订单申诉', 'count' => cq_pending_count($db, $user, 'appeals', array('未处理','处理中','待处理','pending','0')));
    $items[] = array('key' => 'withdrawals', 'title' => '提现审批', 'count' => cq_pending_count($db, $user, 'withdrawals', array('待审核','待审批','未审核','pending','0')));
    $items[] = array('key' => 'feedback', 'title' => '用户反馈', 'count' => cq_pending_count($db, $user, 'feedback', array('未处理','待处理','pending','0')));
    $items[] = array('key' => 'device_bans', 'title' => '设备违规', 'count' => cq_pending_count($db, $user, 'device_bans', array('未处理','待处理','pending','0')));

    $total = 0;
    foreach ($items as $item) $total += cq_int($item['count']);

    return array('total' => $total, 'items' => $items, 'scope' => cq_scope_payload($db, $user));
}

function cq_pending_count($db, $user, $resource, $pendingValues) {
    list($key, $cfg) = cq_get_resource_config($resource);
    if (!$cfg || !cq_table_exists($db, $cfg['table'])) return 0;
    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $statusCol = cq_first_col($db, $table, array('status','application_status','payout_status','handle_status'), '');
    if ($statusCol === '') return 0;

    $where = array();
    $params = array();
    cq_apply_scope($where, $params, $db, $cfg, $user);
    $where[] = $alias . '.' . $statusCol . ' IN (' . cq_in_placeholders(count($pendingValues)) . ')';
    foreach ($pendingValues as $v) $params[] = $v;
    if (!$where) $where[] = '1=1';
    $row = ($db->query('SELECT COUNT(*) AS c FROM ' . $table . ' ' . $alias . ' WHERE ' . implode(' AND ', $where), $params)[0] ?? array());
    return cq_int($row['c'] ?? 0);
}

function cq_device_overview($db, $user) {
    list($key, $cfg) = cq_get_resource_config('vehicles');
    if (!$cfg || !cq_table_exists($db, 'vehicles')) return array('total'=>0,'online'=>0,'offline'=>0,'using'=>0);
    $where = array();
    $params = array();
    cq_apply_scope($where, $params, $db, $cfg, $user);
    if (!$where) $where[] = '1=1';
    $status = cq_first_col($db, 'vehicles', array('status','vehicle_status'), '');
    if ($status === '') {
        $row = ($db->query('SELECT COUNT(*) AS total FROM vehicles v WHERE ' . implode(' AND ', $where), $params)[0] ?? array());
        return array('total'=>cq_int($row['total'] ?? 0),'online'=>0,'offline'=>0,'using'=>0);
    }
    $sql = "SELECT COUNT(*) AS total,
        SUM(CASE WHEN v.{$status}='在线' THEN 1 ELSE 0 END) AS online,
        SUM(CASE WHEN v.{$status}='离线' THEN 1 ELSE 0 END) AS offline,
        SUM(CASE WHEN v.{$status}='占有' OR v.{$status}='使用中' THEN 1 ELSE 0 END) AS using_count
        FROM vehicles v WHERE " . implode(' AND ', $where);
    $row = ($db->query($sql, $params)[0] ?? array());
    return array('total'=>cq_int($row['total'] ?? 0),'online'=>cq_int($row['online'] ?? 0),'offline'=>cq_int($row['offline'] ?? 0),'using'=>cq_int($row['using_count'] ?? 0));
}

/* =========================================================
 * metric 配置 / trend / rank
 * ========================================================= */

function cq_metric_configs() {
    return array(
        'drive_income' => array('resource'=>'orders','type'=>'sum','amount'=>'payment_amount','time'=>array('end_time','created_at'), 'drive'=>true, 'paid'=>false),
        'drive_order_count' => array('resource'=>'orders','type'=>'count','time'=>array('end_time','created_at'), 'drive'=>true),
        'order_count' => array('resource'=>'orders','type'=>'count','time'=>array('end_time','created_at')),
        'recharge_amount' => array('resource'=>'recharges','type'=>'sum','amount'=>'payer_total','time'=>array('created_at','paid_at','pay_time'), 'paid'=>true),
        'recharge_count' => array('resource'=>'recharges','type'=>'count','time'=>array('created_at','paid_at','pay_time'), 'paid'=>true),
        'refund_amount' => array('resource'=>'refunds','type'=>'sum','amount'=>'refund_amount','time'=>array('created_at','refund_time')),
        'refund_count' => array('resource'=>'refunds','type'=>'count','time'=>array('created_at','refund_time')),
        'gift_income' => array('resource'=>'gift_orders','type'=>'gift_sum','amount'=>'payment_amount','time'=>array('send_time','created_at'), 'paid'=>true),
        'gift_count' => array('resource'=>'gift_orders','type'=>'count','time'=>array('send_time','created_at'), 'paid'=>true),
        'doll_amount' => array('resource'=>'doll_records','type'=>'sum','amount'=>'payment_amount','time'=>array('end_time','start_time','created_at')),
        'doll_count' => array('resource'=>'doll_records','type'=>'count','time'=>array('end_time','start_time','created_at')),
        'new_users' => array('resource'=>'users','type'=>'count','time'=>array('created_at')),
        'active_users' => array('resource'=>'users','type'=>'count','time'=>array('last_active_at')),
        'reports_count' => array('resource'=>'reports','type'=>'count','time'=>array('created_at','insert_time')),
        'device_bans_count' => array('resource'=>'device_bans','type'=>'count','time'=>array('created_at','start_time')),
        'venue_bans_count' => array('resource'=>'venue_bans','type'=>'count','time'=>array('created_at','start_time')),
    );
}

function cq_metric_base($db, $user, $metric, $range) {
    $metrics = cq_metric_configs();
    if (!isset($metrics[$metric])) cq_fail(400, '未知 metric：' . $metric);
    $m = $metrics[$metric];
    list($key, $cfg) = cq_get_resource_config($m['resource']);
    if (!$cfg || !cq_table_exists($db, $cfg['table'])) return array(null, null, array('1=0'), array(), '', '');

    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $timeCol = cq_first_col($db, $table, $m['time'] ?? ($cfg['time'] ?? array()), '');
    if ($timeCol === '') return array($m, $cfg, array('1=0'), array(), '', '');

    $where = array($alias . '.' . $timeCol . ' >= ?', $alias . '.' . $timeCol . ' < ?');
    $params = array($range['start'], $range['end']);
    cq_apply_scope($where, $params, $db, $cfg, $user);

    if (!empty($m['drive']) && $key === 'orders') cq_append_drive_filter($db, $where, $alias);
    if (!empty($m['paid'])) cq_paid_status_where($db, $table, $alias, $where);

    $amountCol = '';
    if (!empty($m['amount'])) {
        $amountCol = cq_column_exists($db, $table, $m['amount']) ? $m['amount'] : cq_first_col($db, $table, $cfg['amount'] ?? array(), '');
    }

    return array($m, $cfg, $where, $params, $timeCol, $amountCol);
}

function cq_metric_total($db, $user, $metric, $range) {
    list($m, $cfg, $where, $params, $timeCol, $amountCol) = cq_metric_base($db, $user, $metric, $range);
    if (!$m || !$cfg) return 0;
    $alias = $cfg['alias'];
    $table = $cfg['table'];
    $whereSql = implode(' AND ', $where);

    if ($m['type'] === 'count') {
        $row = ($db->query('SELECT COUNT(*) AS v FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql, $params)[0] ?? array());
        return cq_int($row['v'] ?? 0);
    }
    if ($amountCol === '') return 0;
    $expr = $alias . '.' . $amountCol;
    if ($m['type'] === 'gift_sum') $expr = '(' . $expr . ' / 10 * 0.6)';
    $row = ($db->query('SELECT COALESCE(SUM(' . $expr . '),0) AS v FROM ' . $table . ' ' . $alias . ' WHERE ' . $whereSql, $params)[0] ?? array());
    return floatval($row['v'] ?? 0);
}

function cq_metric_trend($db, $user) {
    $metric = cq_req_str('metric', 'drive_income');
    $range = cq_time_range();
    list($m, $cfg, $where, $params, $timeCol, $amountCol) = cq_metric_base($db, $user, $metric, $range);
    if (!$m || !$cfg) return array('metric'=>$metric,'range'=>$range,'list'=>array());

    $alias = $cfg['alias'];
    $table = $cfg['table'];
    $whereSql = implode(' AND ', $where);
    $group = strtolower(cq_req_str('group', 'day'));
    $dateExpr = 'DATE(' . $alias . '.' . $timeCol . ')';
    if ($group === 'month') $dateExpr = "DATE_FORMAT(" . $alias . "." . $timeCol . ", '%Y-%m')";

    if ($m['type'] === 'count') {
        $selectValue = 'COUNT(*)';
    } else {
        if ($amountCol === '') return array('metric'=>$metric,'range'=>$range,'list'=>array());
        $expr = $alias . '.' . $amountCol;
        if ($m['type'] === 'gift_sum') $expr = '(' . $expr . ' / 10 * 0.6)';
        $selectValue = 'COALESCE(SUM(' . $expr . '),0)';
    }

    $sql = 'SELECT ' . $dateExpr . ' AS stat_key, ' . $selectValue . ' AS value FROM ' . $table . ' ' . $alias .
        ' WHERE ' . $whereSql . ' GROUP BY ' . $dateExpr . ' ORDER BY stat_key ASC';
    $rows = $db->query($sql, $params) ?: array();
    foreach ($rows as &$row) {
        if ($m['type'] === 'count') $row['value'] = cq_int($row['value'] ?? 0);
        else $row['value'] = cq_money($row['value'] ?? 0);
    }
    unset($row);

    return array('metric'=>$metric,'range'=>$range,'group'=>$group,'scope'=>cq_scope_payload($db, $user),'list'=>$rows);
}

function cq_metric_rank($db, $user) {
    $metric = cq_req_str('metric', 'drive_income');
    $dimension = strtolower(cq_req_str('dimension', cq_req_str('by', 'venue')));
    $limit = cq_req_int('limit', 20, 1, 200);
    $range = cq_time_range();

    list($m, $cfg, $where, $params, $timeCol, $amountCol) = cq_metric_base($db, $user, $metric, $range);
    if (!$m || !$cfg) return array('metric'=>$metric,'dimension'=>$dimension,'range'=>$range,'list'=>array());

    $table = $cfg['table'];
    $alias = $cfg['alias'];
    $groupCandidates = array();
    if ($dimension === 'venue') $groupCandidates = $cfg['venue'] ?? array();
    if ($dimension === 'device') $groupCandidates = array('serial_number','device_id','image_device_serial');
    if ($dimension === 'user') $groupCandidates = array('uid','user_id','banned_uid','admin_uid');
    $groupCol = cq_first_col($db, $table, $groupCandidates, '');
    if ($groupCol === '') return array('metric'=>$metric,'dimension'=>$dimension,'range'=>$range,'list'=>array());

    if ($m['type'] === 'count') {
        $selectValue = 'COUNT(*)';
    } else {
        if ($amountCol === '') return array('metric'=>$metric,'dimension'=>$dimension,'range'=>$range,'list'=>array());
        $expr = $alias . '.' . $amountCol;
        if ($m['type'] === 'gift_sum') $expr = '(' . $expr . ' / 10 * 0.6)';
        $selectValue = 'COALESCE(SUM(' . $expr . '),0)';
    }

    $sql = 'SELECT ' . $alias . '.' . $groupCol . ' AS group_id, ' . $selectValue . ' AS value, COUNT(*) AS row_count FROM ' . $table . ' ' . $alias .
        ' WHERE ' . implode(' AND ', $where) .
        ' GROUP BY ' . $alias . '.' . $groupCol .
        ' ORDER BY value DESC LIMIT ' . intval($limit);
    $rows = $db->query($sql, $params) ?: array();
    cq_enrich_rank_names($db, $dimension, $rows);
    foreach ($rows as &$row) {
        $row['row_count'] = cq_int($row['row_count'] ?? 0);
        if ($m['type'] === 'count') $row['value'] = cq_int($row['value'] ?? 0);
        else $row['value'] = cq_money($row['value'] ?? 0);
    }
    unset($row);

    return array('metric'=>$metric,'dimension'=>$dimension,'range'=>$range,'scope'=>cq_scope_payload($db, $user),'list'=>$rows);
}

function cq_enrich_rank_names($db, $dimension, &$rows) {
    if (!$rows) return;
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return strval($r['group_id'] ?? ''); }, $rows), 'strlen')));
    if (!$ids) return;

    $nameMap = array();
    if ($dimension === 'venue' && cq_table_exists($db, 'venues')) {
        $nameCol = cq_first_col($db, 'venues', array('venue_name','name'), 'id');
        $sql = 'SELECT id, ' . $nameCol . ' AS name FROM venues WHERE id IN (' . cq_in_placeholders(count($ids)) . ')';
        $list = $db->query($sql, $ids) ?: array();
        foreach ($list as $r) $nameMap[strval($r['id'])] = $r['name'];
    } elseif ($dimension === 'user' && cq_table_exists($db, 'users')) {
        $nameCol = cq_first_col($db, 'users', array('nickname','username','phone','mobile'), 'uid');
        $sql = 'SELECT uid, ' . $nameCol . ' AS name FROM users WHERE uid IN (' . cq_in_placeholders(count($ids)) . ')';
        $list = $db->query($sql, $ids) ?: array();
        foreach ($list as $r) $nameMap[strval($r['uid'])] = $r['name'];
    } elseif ($dimension === 'device' && cq_table_exists($db, 'vehicles')) {
        $nameCol = cq_first_col($db, 'vehicles', array('name','serial_number'), 'serial_number');
        $sql = 'SELECT serial_number, ' . $nameCol . ' AS name FROM vehicles WHERE serial_number IN (' . cq_in_placeholders(count($ids)) . ')';
        $list = $db->query($sql, $ids) ?: array();
        foreach ($list as $r) $nameMap[strval($r['serial_number'])] = $r['name'];
    }

    foreach ($rows as &$row) {
        $gid = strval($row['group_id'] ?? '');
        $row['group_name'] = $nameMap[$gid] ?? $gid;
    }
    unset($row);
}

/* =========================================================
 * action=options
 * ========================================================= */

function cq_get_options($db, $user) {
    $resource = cq_req_str('resource', 'orders');
    $field = cq_req_str('field', 'status');
    list($key, $cfg) = cq_get_resource_config($resource);
    if (!$cfg) cq_fail(400, '未知 resource：' . $resource);
    if (!empty($cfg['admin_only']) && !cq_is_admin($user)) cq_fail(1002, '无权访问该资源');
    if (!cq_table_exists($db, $cfg['table'])) return array('resource'=>$key,'field'=>$field,'list'=>array());

    $allowed = array_unique(array_merge($cfg['filters'] ?? array(), $cfg['keyword'] ?? array(), array('status','channel','pays_type','pay_type','order_status','application_status','venue_status')));
    if (!in_array($field, $allowed, true) || !cq_column_exists($db, $cfg['table'], $field)) cq_fail(400, '不允许的 field：' . $field);

    $where = array();
    $params = array();
    cq_apply_scope($where, $params, $db, $cfg, $user);
    if (!$where) $where[] = '1=1';
    $alias = $cfg['alias'];
    $sql = 'SELECT ' . $alias . '.' . $field . ' AS value, COUNT(*) AS count FROM ' . $cfg['table'] . ' ' . $alias .
        ' WHERE ' . implode(' AND ', $where) . ' AND ' . $alias . '.' . $field . ' IS NOT NULL AND ' . $alias . '.' . $field . " <> ''" .
        ' GROUP BY ' . $alias . '.' . $field . ' ORDER BY count DESC LIMIT 100';
    $rows = $db->query($sql, $params) ?: array();
    foreach ($rows as &$row) $row['count'] = cq_int($row['count'] ?? 0);
    unset($row);
    return array('resource'=>$key,'field'=>$field,'list'=>$rows);
}

function cq_scope_payload($db, $user) {
    $ids = cq_accessible_venue_ids($db, $user);
    return array(
        'is_admin' => cq_is_admin($user) ? 1 : 0,
        'role_id' => cq_int($user['role_id'] ?? 0),
        'venue_ids' => $ids === null ? array() : $ids,
        'all_venues' => $ids === null ? 1 : 0,
        'requested_venue_id' => cq_req_int('venue_id', 0, 0),
    );
}
