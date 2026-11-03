<?php
require_once __DIR__ . '/xinghe_sync_common.php';
function xhSourceCamera($conn, $value) {
    // Numeric front/back references may be local record IDs; preserve that precedence.
    if (ctype_digit($value)) {
        $rows = xhQuery($conn, 'SELECT * FROM device_information WHERE CAST(id AS CHAR)=?', [$value]);
        if ($rows) return ['rows' => $rows, 'by_id' => true];
    }
    $rows = xhQuery($conn, 'SELECT * FROM device_information WHERE BINARY room_id=BINARY ? OR BINARY playing_stream_id=BINARY ? OR BINARY rtc_user_id=BINARY ? OR CAST(device_id AS CHAR)=?', [$value, $value, $value, $value]);
    if (!$rows && preg_match('/^([0-9]+)_(main|aux)$/D', $value, $match)) {
        $base = $match[1];
        // A main and auxiliary stream can share one physical camera record.
        $rows = xhQuery($conn, 'SELECT * FROM device_information WHERE CAST(device_id AS CHAR)=? OR BINARY room_id=BINARY ? OR BINARY playing_stream_id IN (BINARY ?,BINARY ?)', [$base, $base, $base, $base . '_main']);
    }
    return ['rows' => $rows, 'by_id' => false];
}
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') xhReply(405, '仅支持POST请求');
    $token = $_COOKIE['session_token'] ?? '';
    if ($token === '') xhReply(1001, '请先登录');
    // Reject cross-site browser form submissions; JSON requests require same-origin access.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) xhReply(400, '请使用JSON请求');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') xhReply(403, '禁止跨站请求');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = filter_var($input['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) xhReply(400, '场地ID无效');
    $config = xhConfig();
    if (strtolower(parse_url($config['url'], PHP_URL_SCHEME) ?? '') !== 'https' || !parse_url($config['url'], PHP_URL_HOST)) {
        throw new RuntimeException('请配置星河接收接口的HTTPS地址 XINGHE_SYNC_URL');
    }
    require_once __DIR__ . '/../Database.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = (new Database())->getConnection();
    $conn->set_charset('utf8mb4');
    // Existing RC login uses session_token only; older live schemas have no expiry column.
    // Keep expiry enforcement when that column exists, without requiring a schema migration.
    $adminColumns = xhColumns($conn, 'admin_users');
    $expiryClause = isset($adminColumns['session_expires']) ? ' AND session_expires>NOW()' : '';
    $admins = xhQuery($conn, 'SELECT id,role_id FROM admin_users WHERE session_token=?' . $expiryClause . ' LIMIT 1', [$token]);
    if (!$admins) xhReply(1001, '登录已过期');
    if (!in_array((int)$admins[0]['role_id'], [1, 2], true)) xhReply(403, '仅平台管理员可同步');
    if (($input['action'] ?? '') === 'list_zones') {
        xhReply(0, '星河专区列表', xhRemote($config, ['version' => 2, 'source' => 'RC', 'action' => 'list_zones', 'source_venue_id' => (int)$id]));
    }
    xhTransactional($conn, ['venues', 'vehicles', 'vehicle_control_settings']);
    xhTransactional($conn, ['PricingOptions']);
    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $venues = xhQuery($conn, 'SELECT * FROM venues WHERE id=?', [$id]);
    if (count($venues) !== 1) throw new RuntimeException('场地不存在');
    $venue = $venues[0];
    $pricing = xhQuery($conn, 'SELECT * FROM PricingOptions WHERE BindLocation=? ORDER BY ID', [(string)$id]);
    // Current projects bind by numeric venue ID. Do not match names (names need not be unique).
    $vehicles = xhQuery($conn, 'SELECT * FROM vehicles WHERE bind_site=? ORDER BY serial_number', [(string)$id]);
    $settings = [];
    $serials = [];
    $cameras = [];
    $cameraRefs = [];
    $missingCameraRefs = [];
    $billingRules = [];
    $cameraTable = xhQuery($conn, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='device_information'");
    if ($cameraTable) xhTransactional($conn, ['device_information']);
    foreach ($vehicles as &$vehicle) {
        $sn = (string)$vehicle['serial_number'];
        if (trim($sn) === '' || isset($serials['sn:' . $sn])) throw new RuntimeException('设备序列号为空或重复');
        $serials['sn:' . $sn] = true;
        if (array_key_exists('billing_rules', $vehicle) && $vehicle['billing_rules'] !== null) {
            $ruleId = (string)$vehicle['billing_rules'];
            if (!ctype_digit($ruleId)) throw new RuntimeException("设备 $sn 的计费规则ID无效");
            if (!isset($billingRules['rule:' . $ruleId])) {
                xhTransactional($conn, ['billing_rules']);
                $rules = xhQuery($conn, 'SELECT * FROM billing_rules WHERE id=?', [$ruleId]);
                if (count($rules) !== 1) throw new RuntimeException("设备 $sn 引用的RC计费规则不存在：" . $ruleId);
                $billingRules['rule:' . $ruleId] = $rules[0];
            }
        }
        $rows = xhQuery($conn, 'SELECT * FROM vehicle_control_settings WHERE serial_number=?', [$sn]);
        if (count($rows) > 1) throw new RuntimeException("设备 $sn 存在多条控制配置，请先核对重复记录");
        // Missing source configuration is an unset device, not a reason to omit the vehicle.
        // Do not invent calibration values or overwrite an existing target configuration.
        if ($rows) $settings[] = xhSettings($rows[0]);
        foreach (['image_device_serial', 'bk_image_device_serial'] as $field) {
            $value = (string)($vehicle[$field] ?? '');
            if ($value === '') continue;
            if (!$cameraTable) {
                if ((int)($vehicle['image_transmission_type'] ?? 0) === 1) {
                    $missingCameraRefs[] = ['serial_number' => $sn, 'field' => $field, 'source_value' => $value];
                    unset($vehicle[$field]);
                }
                continue;
            }
            $resolved = xhSourceCamera($conn, $value);
            $camera = $resolved['rows'];
            if (count($camera) > 1) throw new RuntimeException("设备 $sn 的图传标识不唯一");
            if (!$camera && (int)($vehicle['image_transmission_type'] ?? 0) === 1) {
                // Supplemental sync can still import packages/other device fields.
                // Omit this unresolved reference; never send a source-local ID as a target ID.
                $missingCameraRefs[] = ['serial_number' => $sn, 'field' => $field, 'source_value' => $value];
                unset($vehicle[$field]);
                continue;
            }
            if ($camera) {
                $cameraId = (string)$camera[0]['id'];
                $cameras['camera:' . $cameraId] = $camera[0];
                $ref = ['serial_number' => $sn, 'field' => $field, 'camera_id' => $cameraId];
                if ($field === 'bk_image_device_serial' && !$resolved['by_id']) {
                    $ref['reference_type'] = 'raw';
                    $ref['raw_value'] = $value;
                }
                $cameraRefs[] = $ref;
            }
        }
        $vehicle = xhPick($vehicle, xhVehicleFields());
    }
    unset($vehicle);
    $payload = ['version' => 5, 'source' => 'RC', 'source_venue_id' => (int)$id,
        'venue' => xhPick($venue, xhVenueFields()), 'vehicles' => $vehicles, 'settings' => $settings,
        'cameras' => array_values($cameras), 'camera_refs' => $cameraRefs, 'missing_camera_refs' => $missingCameraRefs,
        'billing_rules' => array_values($billingRules), 'pricing_options' => $pricing,
        'zone' => $input['zone'] ?? ['mode' => 'keep']];
    $conn->commit();
    $data = xhRemote($config, $payload);
    xhReply(0, '场地、套餐、设备及图传配置已同步到星河', $data);
} catch (Throwable $e) {
    if ($conn) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    error_log('Xinghe send: ' . $e->getMessage());
    xhReply(500, '同步失败：' . $e->getMessage());
}
