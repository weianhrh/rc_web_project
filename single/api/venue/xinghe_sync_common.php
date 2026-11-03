<?php
// Shared by the RC sender and Xinghe receiver. No database credentials here.
ini_set('display_errors', '0');
function xhReply($code, $msg, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function xhConfig() {
    $config = ['url' => getenv('XINGHE_SYNC_URL') ?: '', 'token' => getenv('XINGHE_SYNC_TOKEN') ?: ''];
    $file = __DIR__ . '/xinghe_sync_config.php';
    if (is_file($file)) $config = array_merge($config, require $file);
    if (strlen($config['token']) < 32) throw new RuntimeException('请配置至少32字符的 XINGHE_SYNC_TOKEN');
    return $config;
}
function xhQuery($conn, $sql, $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('SQL准备失败：' . $conn->error);
    if ($params) {
        $refs = [];
        foreach ($params as $i => $value) $refs[$i] = &$params[$i];
        $stmt->bind_param(str_repeat('s', count($params)), ...$refs);
    }
    if (!$stmt->execute()) throw new RuntimeException('SQL执行失败：' . $stmt->error);
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function xhColumns($conn, $table) {
    $columns = [];
    foreach (xhQuery($conn, "SHOW FULL COLUMNS FROM `$table`") as $row) $columns[$row['Field']] = $row;
    return $columns;
}
function xhVenueFields() {
    return explode(',', 'venue_name,image_url,venue_description,venue_tags,venue_type,start_time,live_stream_url,is_claw_machine_venue,venue_level,billing_mode,minute_fee');
}
function xhVehicleFields() {
    return explode(',', 'serial_number,photo_url,name,bind_city,sharing_status,billing_rules,share_name,image_device_serial,bk_image_device_serial,vehicle_level,image_transmission_type,drive_confirm_seconds');
}
function xhPick($row, $fields) {
    return array_intersect_key($row, array_fill_keys($fields, true));
}
function xhSettings($row) {
    // Copy ALL configuration columns, including future channel/firmware options.
    // Database identities and timestamps are local to each installation.
    return array_diff_key($row, array_fill_keys(['id', 'created_at', 'updated_at'], true));
}
function xhCheckFields($columns, $data, $table) {
    foreach ($data as $field => $value) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field) || !isset($columns[$field])) {
            throw new RuntimeException("星河缺少字段 {$table}.{$field}，请先对齐表结构");
        }
        if (stripos($columns[$field]['Extra'], 'GENERATED') !== false) throw new RuntimeException("不能同步生成列 $table.$field");
        if (!is_scalar($value) && $value !== null) throw new RuntimeException("字段值格式错误 $table.$field");
    }
}
function xhWrite($conn, $table, $data, $where = null, $params = []) {
    $fields = array_keys($data);
    if (!$fields) throw new RuntimeException('同步数据为空');
    if ($where !== null) {
        $sets = array_map(function ($f) { return "`$f` = ?"; }, $fields);
        xhQuery($conn, "UPDATE `$table` SET " . implode(',', $sets) . " WHERE $where", array_merge(array_values($data), $params));
    } else {
        xhQuery($conn, "INSERT INTO `$table` (`" . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($data));
    }
}
function xhTransactional($conn, $tables) {
    foreach ($tables as $table) {
        $rows = xhQuery($conn, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if (!$rows || strtoupper($rows[0]['ENGINE']) !== 'INNODB') throw new RuntimeException("$table 必须存在且使用 InnoDB，请执行部署SQL/检查表引擎");
    }
}
function xhRemote($config, $payload) {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($config['url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Xinghe-Sync-Token: ' . $config['token']],
        CURLOPT_POSTFIELDS => $body]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('星河连接失败；超时可重试：' . $error);
    $result = json_decode($response, true);
    if ($http !== 200 || !is_array($result)) throw new RuntimeException('星河响应异常，HTTP ' . $http . '，请检查接收接口地址');
    if (($result['code'] ?? -1) !== 0) throw new RuntimeException($result['msg'] ?? '星河拒绝同步');
    return $result['data'] ?? [];
}
