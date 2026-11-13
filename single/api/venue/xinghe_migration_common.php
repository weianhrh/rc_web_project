<?php
// RC-side migration evidence. No DDL and no device/venue mutation in this module.
require_once __DIR__ . '/xinghe_sync_common.php';

function xhmValidSerial($value) {
    return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D', $value) === 1;
}

function xhmSerialList($values) {
    if (!is_array($values) || !$values || count($values) > 100 || array_keys($values) !== range(0, count($values) - 1)) {
        throw new RuntimeException('每次请提交1至100个设备序列号');
    }
    $seen = []; $result = [];
    foreach ($values as $serial) {
        if (!xhmValidSerial($serial)) throw new RuntimeException('设备序列号格式无效');
        $key = strtolower($serial);
        if (isset($seen[$key])) throw new RuntimeException('请求包含重复设备序列号');
        $seen[$key] = true; $result[] = $serial;
    }
    return $result;
}

function xhmRequireStorage($conn) {
    $tables = xhQuery($conn, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rc_xinghe_device_migrations'");
    if (!$tables) throw new RuntimeException('搬迁标识表尚未安装，请先在RC数据库执行 rc_migration_schema.sql');
    if (strtoupper($tables[0]['ENGINE']) !== 'INNODB') throw new RuntimeException('搬迁标识表必须使用InnoDB，请核对 rc_migration_schema.sql');
    $columns = xhColumns($conn, 'rc_xinghe_device_migrations');
    foreach (['source_vehicle_id','serial_number','state','target_vehicle_id','target_venue_id','target_venue_name','confirmed_at','checked_at','last_check_state','last_check_message','source'] as $field) {
        if (!isset($columns[$field])) throw new RuntimeException('搬迁标识表结构不完整，请核对 rc_migration_schema.sql');
    }
}

function xhmSourceMatches($conn, $serial, $lock = false) {
    // LOWER guarantees ASCII case-insensitive lookup even on binary source columns.
    // PHP exact comparison then rejects accent/collation-equivalent false matches.
    $rows = xhQuery($conn, 'SELECT id,serial_number FROM vehicles WHERE LOWER(serial_number)=?' . ($lock ? ' FOR UPDATE' : ''), [strtolower($serial)]);
    $matches = [];
    foreach ($rows as $row) {
        if (strtolower((string)$row['serial_number']) === strtolower($serial)) $matches[] = $row;
    }
    return $matches;
}

function xhmEmptyRow($serial, $state = 'unchecked', $warning = '') {
    return ['serial_number' => $serial, 'state' => $state, 'target_venue_id' => null, 'target_venue_name' => null,
        'checked_at' => null, 'confirmed_at' => null, 'warning' => $warning];
}

function xhmPublicRecord($record, $serial = null) {
    $state = (string)$record['state'];
    $last = (string)$record['last_check_state'];
    $warning = (string)($record['last_check_message'] ?? '');
    if ($state === 'migrated' && !in_array($last, ['found','sync'], true)) {
        $warning = ($warning !== '' ? $warning : '本次核对未能确认星河设备') . '；保留此前已确认搬迁记录';
    }
    return ['serial_number' => $serial === null ? (string)$record['serial_number'] : $serial,
        'state' => $state, 'target_venue_id' => $record['target_venue_id'], 'target_venue_name' => $record['target_venue_name'],
        'checked_at' => $record['checked_at'], 'confirmed_at' => $record['confirmed_at'], 'warning' => $warning];
}

function xhmStatusRows($conn, $serials) {
    xhmRequireStorage($conn);
    $rows = [];
    foreach (xhmSerialList($serials) as $serial) {
        $matches = xhmSourceMatches($conn, $serial);
        if (count($matches) !== 1) {
            $rows[] = xhmEmptyRow($serial, 'conflict', $matches ? 'RC存在重复序列号，请先核对' : 'RC未找到该设备，请刷新设备列表');
            continue;
        }
        $saved = xhQuery($conn, 'SELECT * FROM rc_xinghe_device_migrations WHERE source_vehicle_id=?', [(string)$matches[0]['id']]);
        if (!$saved || strtolower((string)$saved[0]['serial_number']) !== strtolower((string)$matches[0]['serial_number'])) {
            $rows[] = xhmEmptyRow($serial); continue;
        }
        $rows[] = xhmPublicRecord($saved[0], $serial);
    }
    return $rows;
}

function xhmNow($conn) {
    $rows = xhQuery($conn, "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS checked_at");
    return $rows[0]['checked_at'];
}

function xhmSaveEvidence($conn, $vehicle, $check, $source, $checkedAt, $confirmedAt = null) {
    $id = (string)$vehicle['id']; $serial = (string)$vehicle['serial_number'];
    $saved = xhQuery($conn, 'SELECT * FROM rc_xinghe_device_migrations WHERE source_vehicle_id=? FOR UPDATE', [$id]);
    $old = $saved && strtolower((string)$saved[0]['serial_number']) === strtolower($serial) ? $saved[0] : null;
    // A slow historical check must not overwrite a more recent sync/check result.
    // DATETIME has second precision: in a tie, confirmed sync takes precedence.
    if ($old && ((string)$old['checked_at'] > $checkedAt ||
        ((string)$old['checked_at'] === $checkedAt && $old['last_check_state'] === 'sync' && $source === 'backfill'))) {
        return xhmPublicRecord($old, $serial);
    }
    $history = $old && $old['confirmed_at'] !== null;
    $positive = in_array($check['state'], ['found','sync'], true);
    $localConflict = in_array($check['state'], ['invalid_source','source_conflict'], true);
    $record = ['source_vehicle_id' => $id, 'serial_number' => $serial,
        'state' => $localConflict ? 'conflict' : ($positive || $history ? 'migrated' : ($check['state'] === 'not_found' ? 'not_migrated' : 'conflict')),
        'target_vehicle_id' => null, 'target_venue_id' => null, 'target_venue_name' => null,
        'confirmed_at' => $history ? $old['confirmed_at'] : ($positive ? ($confirmedAt ?? $checkedAt) : null),
        'checked_at' => $checkedAt, 'last_check_state' => $check['state'],
        'last_check_message' => (string)($check['warning'] ?? ''), 'source' => $source];
    if ($positive) {
        // A sync reply proves existence but does not return the current target
        // vehicle ID; never carry a possibly deleted/recreated target's old ID.
        $record['target_vehicle_id'] = $check['target_vehicle_id'] ?? null;
        $record['target_venue_id'] = $check['target_venue_id'] ?? null;
        $record['target_venue_name'] = $check['target_venue_name'] ?? null;
    } elseif ($history) {
        foreach (['target_vehicle_id','target_venue_id','target_venue_name','source'] as $field) $record[$field] = $old[$field];
    }
    $fields = array_keys($record);
    $sets = array_map(function ($field) { return '`' . $field . '`=VALUES(`' . $field . '`)'; }, array_slice($fields, 1));
    xhQuery($conn, 'INSERT INTO rc_xinghe_device_migrations (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $sets), array_values($record));
    return xhmPublicRecord($record);
}

function xhmRemoteChecks($serials) {
    if (!$serials) return [];
    $config = xhConfig();
    if (strtolower(parse_url($config['url'], PHP_URL_SCHEME) ?? '') !== 'https' || !parse_url($config['url'], PHP_URL_HOST)) {
        throw new RuntimeException('请检查已有星河同步接口HTTPS配置');
    }
    $config['url'] = preg_replace('~/receiveRcVenue\.php(?:\?.*)?$~', '/checkRcDeviceMigration.php', $config['url'], 1, $replaced);
    if ($replaced !== 1) throw new RuntimeException('现有星河同步地址须以 receiveRcVenue.php 结尾');
    $data = xhRemote($config, ['protocol' => 'rc_device_migration_v1', 'source' => 'RC', 'serial_numbers' => array_values($serials)]);
    if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) !== count($serials)) {
        throw new RuntimeException('星河核对结果不完整，本页未保存，请检查星河端部署');
    }
    $expected = []; foreach ($serials as $serial) $expected[strtolower($serial)] = true;
    $checks = [];
    foreach ($data['rows'] as $row) {
        if (!is_array($row) || !xhmValidSerial($row['serial_number'] ?? null)) throw new RuntimeException('星河核对结果序列号格式异常，本页未保存');
        $key = strtolower($row['serial_number']);
        if (!isset($expected[$key]) || isset($checks[$key]) || !in_array($row['state'] ?? '', ['found','not_found','ambiguous'], true)) {
            throw new RuntimeException('星河核对结果不匹配，本页未保存');
        }
        $check = ['state' => $row['state'], 'warning' => $row['state'] === 'ambiguous' ? '星河存在重复或无法唯一对应的序列号，请核对' : ($row['state'] === 'not_found' ? '本次核对星河未找到该设备' : '')];
        if ($row['state'] === 'found') {
            $vehicleId = $row['target_vehicle_id'] ?? null; $venueId = $row['target_venue_id'] ?? null; $venueName = $row['target_venue_name'] ?? null;
            if ((!is_string($vehicleId) && !is_int($vehicleId)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string)$vehicleId) !== 1 ||
                ($venueId !== null && ((!is_string($venueId) && !is_int($venueId)) || preg_match('/^(?:0|[1-9][0-9]{0,19})$/D', (string)$venueId) !== 1)) ||
                ($venueName !== null && (!is_string($venueName) || strlen($venueName) > 16384))) {
                throw new RuntimeException('星河核对结果目标设备或场地格式异常，本页未保存');
            }
            $check['target_vehicle_id'] = (string)$vehicleId;
            $check['target_venue_id'] = $venueId === null ? null : (string)$venueId;
            $check['target_venue_name'] = $venueName;
        }
        $checks[$key] = $check;
    }
    if (count($checks) !== count($expected)) throw new RuntimeException('星河核对结果缺少设备，本页未保存');
    return $checks;
}

function xhmBackfillPage($conn, $cursor) {
    if ((!is_string($cursor) && !is_int($cursor)) || preg_match('/^(?:0|[1-9][0-9]{0,19})$/D', (string)$cursor) !== 1) {
        throw new RuntimeException('补标游标无效，请从头开始核对');
    }
    xhmRequireStorage($conn);
    xhTransactional($conn, ['vehicles']);
    $page = xhQuery($conn, 'SELECT id,serial_number FROM vehicles WHERE id>? ORDER BY id ASC LIMIT 101', [(string)$cursor]);
    $done = count($page) <= 100; $page = array_slice($page, 0, 100);
    if (!$page) return ['rows' => [], 'next_cursor' => (string)$cursor, 'done' => true, 'processed' => 0, 'counts' => ['migrated' => 0, 'not_migrated' => 0, 'conflict' => 0]];
    // Timestamp comes from the RC database before network work begins.
    $checkedAt = xhmNow($conn);
    $checks = []; $remoteSerials = [];
    foreach ($page as $vehicle) {
        $serial = (string)$vehicle['serial_number']; $id = (string)$vehicle['id'];
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) throw new RuntimeException('RC车辆主键格式异常，请先核对车辆表');
        if (!xhmValidSerial($serial)) {
            $checks[$id] = ['state' => 'invalid_source', 'warning' => 'RC序列号为空或格式异常，未自动补标']; continue;
        }
        $matches = xhmSourceMatches($conn, $serial);
        if (count($matches) !== 1 || (string)$matches[0]['id'] !== $id) {
            $checks[$id] = ['state' => 'source_conflict', 'warning' => 'RC存在重复序列号，未自动补标']; continue;
        }
        $remoteSerials[strtolower($serial)] = $serial;
    }
    // Do all network work and complete response validation BEFORE any local writes.
    $remote = xhmRemoteChecks(array_values($remoteSerials));
    $conn->begin_transaction();
    try {
        $confirmedAt = xhmNow($conn);
        $rows = []; $counts = ['migrated' => 0, 'not_migrated' => 0, 'conflict' => 0];
        foreach ($page as $vehicle) {
            $id = (string)$vehicle['id']; $serial = (string)$vehicle['serial_number'];
            $current = xhQuery($conn, 'SELECT id,serial_number FROM vehicles WHERE id=? FOR UPDATE', [$id]);
            if (count($current) !== 1 || (string)$current[0]['serial_number'] !== $serial) throw new RuntimeException('RC设备在核对期间发生变化，本页未保存，请重试本页');
            $check = $checks[$id] ?? $remote[strtolower($serial)];
            if (xhmValidSerial($serial)) {
                $matches = xhmSourceMatches($conn, $serial, true);
                if (count($matches) !== 1 || (string)$matches[0]['id'] !== $id) $check = ['state' => 'source_conflict', 'warning' => 'RC存在重复序列号，未自动补标'];
            }
            $row = xhmSaveEvidence($conn, $vehicle, $check, 'backfill', $checkedAt, $confirmedAt);
            $rows[] = $row; $counts[$row['state']]++;
        }
        $conn->commit();
        return ['rows' => $rows, 'next_cursor' => (string)$page[count($page) - 1]['id'], 'done' => $done, 'processed' => count($page), 'counts' => $counts];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

// Owns its local transaction. Call only AFTER the remote sync has committed.
// The caller must preserve remote success and expose a warning if this throws.
function xhmMarkSynced($conn, $serials, $targetVenueId, $targetVenueName = '') {
    xhmRequireStorage($conn);
    if (!is_array($serials)) throw new RuntimeException('同步成功设备清单格式异常');
    if (!$serials) return [];
    xhTransactional($conn, ['vehicles']);
    if ((!is_string($targetVenueId) && !is_int($targetVenueId)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string)$targetVenueId) !== 1 || !is_string($targetVenueName) || strlen($targetVenueName) > 16384) {
        throw new RuntimeException('同步成功目标场地信息格式异常');
    }
    $unique = [];
    foreach ($serials as $serial) {
        if (!xhmValidSerial($serial)) throw new RuntimeException('同步成功设备序列号格式异常');
        $unique[strtolower($serial)] = $serial;
    }
    $conn->begin_transaction();
    try {
        $checkedAt = xhmNow($conn); $rows = [];
        foreach ($unique as $serial) {
            $matches = xhmSourceMatches($conn, $serial, true);
            if (count($matches) !== 1) throw new RuntimeException($matches ? 'RC存在重复序列号，搬迁标识未保存' : 'RC设备已不存在，搬迁标识未保存');
            $rows[] = xhmSaveEvidence($conn, $matches[0], ['state' => 'sync', 'target_venue_id' => (string)$targetVenueId, 'target_venue_name' => $targetVenueName], 'sync', $checkedAt);
        }
        $conn->commit(); return $rows;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
