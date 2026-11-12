<?php
require_once __DIR__ . '/xinghe_sync_common.php';
require_once __DIR__ . '/xinghe_device_sync.php';
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') xhReply(405, '仅支持POST请求');
    $session = $_COOKIE['session_token'] ?? '';
    if ($session === '') xhReply(1001, '请先登录RC后台');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0 || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') xhReply(403, '请从RC后台页面提交');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) xhReply(413, '设备清单过长');
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !in_array($input['action'] ?? '', ['list_venues','preview','sync'], true)) xhReply(400, '操作无效');
    require_once __DIR__ . '/../Database.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = (new Database())->getConnection(); $conn->set_charset('utf8mb4');
    $adminColumns = xhColumns($conn, 'admin_users');
    $expiry = isset($adminColumns['session_expires']) ? ' AND session_expires>NOW()' : '';
    $admins = xhQuery($conn, 'SELECT id,role_id FROM admin_users WHERE session_token=?' . $expiry . ' LIMIT 1', [$session]);
    if (!$admins) xhReply(1001, '登录已过期');
    if (!in_array((int)$admins[0]['role_id'], [1,2], true)) xhReply(403, '仅平台管理员可同步指定车辆');
    $config = xhConfig();
    if (strtolower(parse_url($config['url'], PHP_URL_SCHEME) ?? '') !== 'https' || !parse_url($config['url'], PHP_URL_HOST)) throw new RuntimeException('请检查已有星河同步接口HTTPS配置');
    $remoteUrl = preg_replace('~/receiveRcVenue\.php(?:\?.*)?$~', '/receiveRcDevices.php', $config['url'], 1, $replaced);
    if ($replaced !== 1) throw new RuntimeException('现有星河同步地址须以 receiveRcVenue.php 结尾，请核对同步配置');
    $config['url'] = $remoteUrl;
    $payload = ['protocol' => 'rc_selected_devices_v1', 'source' => 'RC', 'action' => $input['action'], 'operator_id' => (int)$admins[0]['id']];
    if ($input['action'] !== 'list_venues') {
        $targetId = filter_var($input['target_venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$targetId) throw new RuntimeException('请选择星河已有场地');
        $requested = xdsParseList($input['devices_text'] ?? null);
        xhTransactional($conn, ['venues','vehicles','vehicle_control_settings','device_information']);
        $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $payload['target_venue_id'] = $targetId;
        $payload['items'] = xdsSourceItems($conn, $requested);
        $payload['preview_token'] = $input['preview_token'] ?? null;
        $conn->commit();
    }
    xhReply(0, $input['action'] === 'sync' ? '指定车辆同步完成' : '查询成功', xhRemote($config, $payload));
} catch (Throwable $e) {
    if ($conn) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    error_log('RC selected-device sync: ' . $e->getMessage());
    xhReply(500, '设备同步未完成：' . $e->getMessage());
}
