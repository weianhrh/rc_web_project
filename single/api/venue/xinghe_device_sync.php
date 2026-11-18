<?php
// Standalone selected-device sync. Requires the existing xinghe_sync_common.php.
function xdsTargetUid($value) {
    if ($value === null) return null;
    if (!is_string($value) && !is_int($value)) throw new RuntimeException('新绑定UID须为正整数，留空则按原同步规则');
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!preg_match('/^[0-9]+$/D', $value)) throw new RuntimeException('新绑定UID须为正整数，留空则按原同步规则');
    $value = ltrim($value, '0');
    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new RuntimeException('新绑定UID无效或超出范围');
    return $value;
}
function xdsParseList($text) {
    if (!is_string($text) || strlen($text) > 32000) throw new RuntimeException('设备清单过长或格式错误');
    $items = []; $seen = [];
    foreach (preg_split('/\R/u', trim($text)) as $lineNumber => $line) {
        $line = trim($line); if ($line === '') continue;
        if (!preg_match('/^(?:([0-9]{1,20}(?:_(?:main|aux))?)\s*(?:[—–－,，-]+|\s+)\s*)?([A-Za-z0-9][A-Za-z0-9_.:-]{2,99})$/uD', $line, $match)) throw new RuntimeException('第 ' . ($lineNumber + 1) . ' 行格式错误，请填“图传标识——设备序列号”或单独序列号');
        $hint = $match[1] ?? ''; $serial = $match[2]; $key = strtolower($serial);
        if (isset($seen[$key])) {
            if ($seen[$key] !== $hint) throw new RuntimeException('同一序列号填写了不同图传标识：' . $serial);
            continue;
        }
        $seen[$key] = $hint; $items[] = ['serial_number' => $serial, 'camera_hint' => $hint];
    }
    if (!$items || count($items) > 100) throw new RuntimeException('每次请输入1至100台设备');
    return $items;
}
function xdsExternalCamera($conn, $value) {
    // User-supplied numbers are external identities, NEVER device_information.id.
    return xhQuery($conn, 'SELECT * FROM device_information WHERE CAST(device_id AS CHAR)=? OR BINARY playing_stream_id=BINARY ? OR BINARY room_id=BINARY ?', [$value, $value, $value]);
}
function xdsSourceCamera($conn, $value) {
    if (ctype_digit($value)) {
        $rows = xhQuery($conn, 'SELECT * FROM device_information WHERE id=?', [$value]);
        if ($rows) return ['rows' => $rows, 'by_id' => true];
    }
    $rows = xdsExternalCamera($conn, $value);
    if (!$rows) $rows = xhQuery($conn, 'SELECT * FROM device_information WHERE BINARY rtc_user_id=BINARY ?', [$value]);
    if (!$rows && preg_match('/^([0-9]+)_(main|aux)$/D', $value, $parts)) $rows = xdsExternalCamera($conn, $parts[1]);
    return ['rows' => $rows, 'by_id' => false];
}
function xdsSourceItems($conn, $requested) {
    $items = []; $canonical = []; $billingChecked = false;
    foreach ($requested as $request) {
        $item = $request;
        try {
            $rows = xhQuery($conn, 'SELECT * FROM vehicles WHERE serial_number=?', [$request['serial_number']]);
            if (count($rows) !== 1) throw new RuntimeException($rows ? 'RC存在重复序列号，请先核对' : 'RC未找到该车辆');
            $vehicle = $rows[0]; $sn = (string)$vehicle['serial_number'];
            if (isset($canonical[strtolower($sn)])) throw new RuntimeException('清单重复匹配到同一辆RC设备');
            $canonical[strtolower($sn)] = true; $item['serial_number'] = $sn;
            $item['name'] = (string)($vehicle['name'] ?? '');
            $item['source_venue_id'] = (string)($vehicle['bind_site'] ?? '');
            $sourceVenue = xhQuery($conn, 'SELECT venue_name FROM venues WHERE CAST(id AS CHAR)=?', [$item['source_venue_id']]);
            $item['source_venue_name'] = $sourceVenue ? $sourceVenue[0]['venue_name'] : '未绑定';
            $settings = xhQuery($conn, 'SELECT * FROM vehicle_control_settings WHERE serial_number=?', [$sn]);
            if (count($settings) > 1) throw new RuntimeException('RC存在多条控制配置');
            $item['settings'] = $settings ? xhSettings($settings[0]) : null;
            $item['cameras'] = []; $matchedHint = $request['camera_hint'] === '';
            $hintCamera = null;
            if (!$matchedHint) {
                $hintRows = xdsExternalCamera($conn, $request['camera_hint']);
                if (count($hintRows) !== 1) throw new RuntimeException($hintRows ? '图传标识匹配多条记录，请核对RC图传表' : 'RC图传表未找到输入的device_id/playing_stream_id/room_id');
                $hintCamera = (string)$hintRows[0]['id'];
            }
            foreach (['image_device_serial', 'bk_image_device_serial'] as $field) {
                $value = trim((string)($vehicle[$field] ?? ''));
                if ($value === '' || $value === '0') continue;
                $resolved = xdsSourceCamera($conn, $value); $cameras = $resolved['rows'];
                if (count($cameras) > 1) throw new RuntimeException('RC车辆关联的图传标识不唯一');
                if (!$cameras) {
                    if ((int)($vehicle['image_transmission_type'] ?? 0) === 1) throw new RuntimeException('RC的ZEGO图传引用缺少对应记录，请先核对车辆图传配置');
                    continue; // Other camera providers retain their original stream strings.
                }
                $camera = $cameras[0];
                if ((string)$camera['id'] === $hintCamera) $matchedHint = true;
                $raw = $field === 'bk_image_device_serial' && !$resolved['by_id'];
                $item['cameras'][$field] = ['data' => xhSettings($camera), 'reference' => $raw ? 'raw' : 'id', 'raw_value' => $raw ? $value : null];
            }
            if (!$matchedHint) throw new RuntimeException('输入图传标识与该RC车辆已绑定的前/后摄不一致，请核对配对或先在RC绑定图传');
            $item['billing'] = null;
            if (isset($vehicle['billing_rules'])) {
                if (!$billingChecked) { xhTransactional($conn, ['billing_rules']); $billingChecked = true; }
                if (!ctype_digit((string)$vehicle['billing_rules'])) throw new RuntimeException('RC设备计费规则ID无效');
                $rules = xhQuery($conn, 'SELECT * FROM billing_rules WHERE id=?', [$vehicle['billing_rules']]);
                if (count($rules) !== 1) throw new RuntimeException('RC设备关联的计费规则不存在');
                $item['billing'] = xhSettings($rules[0]);
            }
            $item['vehicle'] = xhPick($vehicle, xhVehicleFields());
        } catch (mysqli_sql_exception $e) { throw $e; }
        catch (RuntimeException $e) {
            $item = array_intersect_key($item, array_flip(['serial_number','camera_hint','name','source_venue_id','source_venue_name']));
            $item['error'] = $e->getMessage();
        }
        $items[] = $item;
    }
    return $items;
}
function xdsCanonical($value) {
    if (!is_array($value)) return $value;
    if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
    foreach ($value as &$child) $child = xdsCanonical($child);
    return $value;
}
function xdsHash($value) { return hash('sha256', json_encode(xdsCanonical($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
function xdsColumns($conn, $table, &$cache) {
    if (!isset($cache[$table])) { xhTransactional($conn, [$table]); $cache[$table] = xhColumns($conn, $table); }
    return $cache[$table];
}
function xdsAssetPlan($conn, $table, $data, &$cache) {
    if (!is_array($data) || !$data || $data !== xhSettings($data)) throw new RuntimeException('关联配置包含无效主键或时间字段');
    $columns = xdsColumns($conn, $table, $cache); xhCheckFields($columns, $data, $table);
    $conditions = []; $params = [];
    if ($table === 'device_information') {
        foreach (['device_id', 'playing_stream_id', 'room_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || (string)$value === '' || (string)$value === '0') continue;
            $conditions[] = "`$field`=?"; $params[] = $value;
        }
        if (!$conditions) throw new RuntimeException('图传缺少设备/房间/流标识');
        $found = xhQuery($conn, 'SELECT * FROM device_information WHERE ' . implode(' OR ', $conditions) . ' FOR UPDATE', $params);
        if (count($found) > 1) throw new RuntimeException('星河图传标识分属多条记录，已跳过该车辆');
        if ($found) {
            foreach ($data as $field => $value) if ((string)$found[0][$field] !== (string)$value || (($found[0][$field] === null) !== ($value === null))) throw new RuntimeException('星河已有同标识图传但配置不同，已跳过该车辆（保留星河配置）');
        }
    } else {
        foreach ($data as $field => $value) {
            $numeric = preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit)\b/i', $columns[$field]['Type']);
            $conditions[] = $numeric ? "`$field` <=> ?" : "BINARY `$field` <=> BINARY ?"; $params[] = $value;
        }
        $found = xhQuery($conn, 'SELECT id FROM billing_rules WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id LIMIT 1 FOR UPDATE', $params);
    }
    if (!$found && $table === 'device_information' && stripos($columns['id']['Extra'] ?? '', 'auto_increment') === false) throw new RuntimeException('星河图传表id须为自增主键');
    return ['table' => $table, 'data' => $data, 'existing_id' => $found ? (string)$found[0]['id'] : null];
}
function xdsPlan($conn, $targetId, $items, $targetUid = null) {
    $targetUid = xdsTargetUid($targetUid);
    if (!is_array($items) || !$items || count($items) > 100) throw new RuntimeException('同步设备数量须为1至100台');
    $cache = [];
    $venueColumns = xdsColumns($conn, 'venues', $cache);
    $vehicleColumns = xdsColumns($conn, 'vehicles', $cache);
    $settingColumns = xdsColumns($conn, 'vehicle_control_settings', $cache);
    $venue = xhQuery($conn, 'SELECT id,venue_name,zone_id FROM venues WHERE id=? FOR UPDATE', [$targetId]);
    if (count($venue) !== 1) throw new RuntimeException('所选星河场地不存在，请刷新列表重新选择');
    $targetUser = null;
    if ($targetUid !== null) {
        // Validate and lock the target account again on both preview and commit.
        // Blank UID deliberately keeps the old import behavior and needs no user lookup.
        $userColumns = xdsColumns($conn, 'users', $cache);
        xhCheckFields($userColumns, ['uid' => $targetUid, 'nickname' => ''], 'users');
        xhCheckFields($vehicleColumns, ['uid' => $targetUid], 'vehicles');
        $users = xhQuery($conn, 'SELECT uid,nickname FROM users WHERE uid=? FOR UPDATE', [$targetUid]);
        if (count($users) !== 1) throw new RuntimeException('星河不存在指定UID的用户，请核对星河用户UID后重新预览');
        $targetUser = ['uid' => (string)$users[0]['uid'], 'nickname' => (string)($users[0]['nickname'] ?? '')];
        if ($targetUser['uid'] !== $targetUid) throw new RuntimeException('星河用户UID查询结果不一致，请核对后重新预览');
    }
    // All imports share the existing global RC sync lock (acquired by the caller).
    // target_user is part of the signed preview so a changed UID requires a new preview.
    $plan = ['target' => $venue[0], 'target_user' => $targetUser, 'rows' => [], 'assets' => []]; $seen = []; $identities = [];
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['serial_number'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{2,99}$/D', $item['serial_number'])) throw new RuntimeException('同步包设备序列号无效');
        $sn = $item['serial_number']; $key = strtolower($sn);
        if (isset($seen[$key])) throw new RuntimeException('同步包存在重复序列号'); $seen[$key] = true;
        $row = ['serial_number' => $sn, 'name' => (string)($item['name'] ?? ''), 'source_venue_id' => (string)($item['source_venue_id'] ?? ''), 'source_venue_name' => (string)($item['source_venue_name'] ?? ''), 'camera_hint' => (string)($item['camera_hint'] ?? ''), 'warnings' => [], 'action' => 'skip'];
        try {
            if (isset($item['error'])) throw new RuntimeException((string)$item['error']);
            if (!is_array($item['vehicle'] ?? null) || ($item['vehicle']['serial_number'] ?? null) !== $sn) throw new RuntimeException('车辆数据与序列号不对应');
            $vehicle = xhPick($item['vehicle'], xhVehicleFields()); $vehicle['bind_site'] = $targetId;
            if ($targetUser !== null) $vehicle['uid'] = $targetUser['uid'];
            xhCheckFields($vehicleColumns, $vehicle, 'vehicles');
            $existing = xhQuery($conn, 'SELECT id,bind_site FROM vehicles WHERE serial_number=? FOR UPDATE', [$sn]);
            if (count($existing) > 1) throw new RuntimeException('星河存在重复序列号');
            $binding = $existing ? trim((string)$existing[0]['bind_site']) : '';
            if ($binding !== '' && $binding !== '0' && $binding !== (string)$targetId) throw new RuntimeException('已属于星河场地 ' . $binding . '，保留原归属');
            if (!$existing && stripos($vehicleColumns['id']['Extra'] ?? '', 'auto_increment') === false) throw new RuntimeException('星河车辆表id须为自增主键');
            $oldSettings = xhQuery($conn, 'SELECT serial_number FROM vehicle_control_settings WHERE serial_number=? FOR UPDATE', [$sn]);
            if (count($oldSettings) > 1) throw new RuntimeException('星河存在重复控制配置');
            $settings = $item['settings'] ?? null;
            if ($settings !== null) {
                if (!is_array($settings) || ($settings['serial_number'] ?? null) !== $sn || $settings !== xhSettings($settings)) throw new RuntimeException('控制配置与设备不对应或包含本地主键');
                xhCheckFields($settingColumns, $settings, 'vehicle_control_settings');
            } else $row['warnings'][] = $oldSettings ? 'RC无控制配置，保留星河原配置' : 'RC无控制配置，同步后仍待设置';
            $assets = []; $refs = []; $rowIdentities = [];
            if (!is_array($item['cameras'] ?? null) || count($item['cameras']) > 2) throw new RuntimeException('图传关联格式错误');
            foreach ($item['cameras'] as $field => $camera) {
                if (!in_array($field, ['image_device_serial','bk_image_device_serial'], true) || !is_array($camera) || !in_array($camera['reference'] ?? '', ['id','raw'], true)) throw new RuntimeException('图传引用格式错误');
                $asset = xdsAssetPlan($conn, 'device_information', $camera['data'] ?? null, $cache);
                $assetKey = 'camera:' . xdsHash($asset['data']); $assets[$assetKey] = $asset;
                foreach (['device_id','playing_stream_id','room_id'] as $identityField) {
                    $value = (string)($asset['data'][$identityField] ?? ''); if ($value === '' || $value === '0') continue;
                    $identity = $identityField . ':' . $value;
                    if (isset($identities[$identity]) && $identities[$identity] !== $assetKey || isset($rowIdentities[$identity]) && $rowIdentities[$identity] !== $assetKey) throw new RuntimeException('本批次图传标识对应不同配置，请核对RC');
                    $rowIdentities[$identity] = $assetKey;
                }
                $raw = $camera['raw_value'] ?? null;
                if ($camera['reference'] === 'raw') {
                    if ($field !== 'bk_image_device_serial' || !is_string($raw) || strlen($raw) > 366 || (string)($vehicle[$field] ?? '') !== $raw) throw new RuntimeException('图传原始流引用不合法');
                    $values = array_map('strval', array_filter([$asset['data']['device_id'] ?? null, $asset['data']['playing_stream_id'] ?? null, $asset['data']['room_id'] ?? null, $asset['data']['rtc_user_id'] ?? null], function ($v) { return $v !== null && $v !== ''; }));
                    $valid = in_array($raw, $values, true);
                    if (!$valid && preg_match('/^([0-9]+)_(main|aux)$/D', $raw, $m)) $valid = in_array($m[1], $values, true) || in_array($m[1] . '_main', $values, true);
                    if (!$valid) throw new RuntimeException('后摄流与图传配置不匹配');
                }
                $refs[$field] = ['asset' => $assetKey, 'reference' => $camera['reference'], 'raw_value' => $raw];
            }
            // Never carry an unresolved source-local ZEGO FK into the target database.
            foreach (['image_device_serial','bk_image_device_serial'] as $field) {
                $value = trim((string)($vehicle[$field] ?? ''));
                if ((int)($vehicle['image_transmission_type'] ?? 0) === 1 && $value !== '' && $value !== '0' && !isset($refs[$field])) throw new RuntimeException('ZEGO引用缺少已核对的图传配置');
            }
            $billingKey = null;
            if (isset($vehicle['billing_rules'])) {
                $asset = xdsAssetPlan($conn, 'billing_rules', $item['billing'] ?? null, $cache);
                $billingKey = 'billing:' . xdsHash($asset['data']); $assets[$billingKey] = $asset;
            }
            $row['action'] = !$existing ? 'create' : ($binding === (string)$targetId ? 'update' : 'bind');
            $row['existing_id'] = $existing ? (string)$existing[0]['id'] : null;
            $row['vehicle'] = $vehicle; $row['settings'] = $settings; $row['settings_exists'] = (bool)$oldSettings;
            $row['refs'] = $refs; $row['billing_key'] = $billingKey;
            $row['camera_count'] = count($refs);
            if ($targetUser !== null) $row['target_uid'] = $targetUser['uid'];
            $row['reason'] = count($refs) ? '图传关联已核对' : '无关联图传记录';
            $plan['assets'] += $assets; $identities += $rowIdentities;
        } catch (mysqli_sql_exception $e) { throw $e; }
        catch (RuntimeException $e) { $row['reason'] = $e->getMessage(); }
        $plan['rows'][] = $row;
    }
    return $plan;
}
function xdsPublicPlan($plan) {
    $rows = []; $summary = ['create' => 0, 'update' => 0, 'bind' => 0, 'skip' => 0];
    foreach ($plan['rows'] as $row) {
        $summary[$row['action']]++;
        $rows[] = xhPick($row, ['serial_number','name','source_venue_id','source_venue_name','camera_hint','camera_count','warnings','action','reason','target_uid']);
    }
    return ['target' => $plan['target'], 'target_user' => $plan['target_user'], 'rows' => $rows, 'summary' => $summary];
}
function xdsPreviewToken($plan, $secret, $expires) { return $expires . '.' . hash_hmac('sha256', $expires . ':' . xdsHash($plan), $secret); }
function xdsVerifyPreview($plan, $secret, $token) {
    if (!is_string($token) || !preg_match('/^([0-9]{10})\.[a-f0-9]{64}$/D', $token, $m) || (int)$m[1] < time() || (int)$m[1] > time() + 600 || !hash_equals(xdsPreviewToken($plan, $secret, (int)$m[1]), $token)) throw new RuntimeException('预览已过期或设备数据发生变化，请重新预览后再同步');
}
function xdsApply($conn, $plan) {
    $ids = []; $cache = []; $counts = ['created_devices' => 0, 'updated_devices' => 0, 'bound_devices' => 0, 'configurations' => 0, 'created_cameras' => 0, 'created_billing_rules' => 0];
    foreach ($plan['assets'] as $key => $asset) {
        $id = $asset['existing_id'];
        if ($id === null) {
            $data = $asset['data']; $columns = xdsColumns($conn, $asset['table'], $cache);
            if ($asset['table'] === 'billing_rules' && stripos($columns['id']['Extra'] ?? '', 'auto_increment') === false) {
                $next = xhQuery($conn, 'SELECT COALESCE(MAX(id),0)+1 AS next_id FROM billing_rules'); $data['id'] = $next[0]['next_id'];
            }
            xhWrite($conn, $asset['table'], $data); $id = isset($data['id']) ? (string)$data['id'] : (string)$conn->insert_id;
            if ((int)$id <= 0) throw new RuntimeException('星河关联记录主键生成失败');
            $counts[$asset['table'] === 'device_information' ? 'created_cameras' : 'created_billing_rules']++;
        }
        $ids[$key] = $id;
    }
    foreach ($plan['rows'] as $row) {
        if ($row['action'] === 'skip') continue;
        $vehicle = $row['vehicle'];
        foreach ($row['refs'] as $field => $ref) $vehicle[$field] = $ref['reference'] === 'raw' ? $ref['raw_value'] : $ids[$ref['asset']];
        if ($row['billing_key'] !== null) $vehicle['billing_rules'] = $ids[$row['billing_key']];
        $updating = $row['existing_id'] !== null;
        xhWrite($conn, 'vehicles', $vehicle, $updating ? 'id=?' : null, $updating ? [$row['existing_id']] : []);
        $counts[$updating ? 'updated_devices' : 'created_devices']++;
        if ($row['action'] === 'bind') $counts['bound_devices']++;
        if ($row['settings'] !== null) {
            xhWrite($conn, 'vehicle_control_settings', $row['settings'], $row['settings_exists'] ? 'serial_number=?' : null, $row['settings_exists'] ? [$row['serial_number']] : []);
            $counts['configurations']++;
        }
    }
    // Only refresh the chosen zone's cached device count. No venue/package/invite/room writes.
    $zoneId = $plan['target']['zone_id'] ?? null;
    if ($zoneId) {
        xhTransactional($conn, ['zones']);
        $count = xhQuery($conn, 'SELECT COUNT(*) AS n FROM vehicles c JOIN venues v ON CAST(c.bind_site AS BINARY)=CAST(v.id AS BINARY) WHERE v.zone_id=?', [$zoneId]);
        xhWrite($conn, 'zones', ['vehicle_count' => $count[0]['n']], 'zone_id=?', [$zoneId]);
    }
    return $counts;
}
