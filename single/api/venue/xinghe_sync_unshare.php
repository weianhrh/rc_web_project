<?php
// RC-local handoff after a confirmed Xinghe commit. Never changes ban/order state.
require_once __DIR__ . '/xinghe_sync_common.php';

function xsuSerialKey($serial) {
    if (!is_string($serial) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D', $serial)) {
        throw new RuntimeException('设备序列号格式无效，不能核对RC共享状态');
    }
    return 'sn:' . strtolower($serial);
}

function xsuValidId($id) {
    return (is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]{0,19}$/D', (string)$id) === 1;
}

function xsuRequireSourceSchema($conn) {
    xhTransactional($conn, ['vehicles']);
    $columns = xhColumns($conn, 'vehicles');
    foreach (['id', 'serial_number', 'sharing_status'] as $field) {
        if (!isset($columns[$field]) || stripos($columns[$field]['Extra'] ?? '', 'GENERATED') !== false) {
            throw new RuntimeException('RC车辆表缺少可用的设备身份或共享状态字段');
        }
    }
}

function xsuCaptureIdentity($conn, $serial, $expectedId = null) {
    xsuSerialKey($serial);
    $rows = xhQuery($conn, 'SELECT id,serial_number FROM vehicles WHERE serial_number=?', [$serial]);
    if (count($rows) !== 1 || (string)$rows[0]['serial_number'] !== $serial || !xsuValidId($rows[0]['id'])
        || ($expectedId !== null && (string)$rows[0]['id'] !== (string)$expectedId)) {
        throw new RuntimeException('RC设备身份不唯一或已变化，请刷新设备资料后再同步');
    }
    return ['id' => (string)$rows[0]['id'], 'serial_number' => $serial];
}

function xsuCaptureSelected($conn, $items) {
    try {
        xsuRequireSourceSchema($conn);
        $identities = [];
        foreach ($items as $item) {
            // Source validation errors already become skipped target rows.
            if (isset($item['error'])) continue;
            $serial = $item['serial_number'] ?? null;
            $key = xsuSerialKey($serial);
            if (isset($identities[$key])) throw new RuntimeException('同步清单存在重复RC设备');
            $identities[$key] = xsuCaptureIdentity($conn, $serial);
        }
        return $identities;
    } catch (Throwable $e) {
        error_log('RC unshare preflight selected: ' . $e->getMessage());
        throw new RuntimeException('RC设备关闭共享预检失败，请检查设备序列号、车辆表结构及服务器日志后重试；尚未向星河提交同步');
    }
}

function xsuCaptureVenue($conn, $vehicles) {
    try {
        xsuRequireSourceSchema($conn);
        $identities = [];
        foreach ($vehicles as $vehicle) {
            $serial = $vehicle['serial_number'] ?? null;
            $key = xsuSerialKey($serial);
            if (!xsuValidId($vehicle['id'] ?? null) || isset($identities[$key])) throw new RuntimeException('场地中存在无效或重复的RC设备身份');
            $identities[$key] = xsuCaptureIdentity($conn, $serial, $vehicle['id']);
        }
        return $identities;
    } catch (Throwable $e) {
        error_log('RC unshare preflight venue: ' . $e->getMessage());
        throw new RuntimeException('RC设备关闭共享预检失败，请检查设备序列号、车辆表结构及服务器日志后重试；尚未向星河提交同步');
    }
}

function xsuCount($value, $maximum) {
    if (!is_int($value) || $value < 0 || $value > $maximum) throw new RuntimeException('星河设备处理数量格式异常');
    return $value;
}

function xsuSelectedSuccesses($data, $items, $identities, $targetId) {
    if (!is_array($data) || !is_array($data['rows'] ?? null) || !is_array($data['summary'] ?? null)
        || !is_array($data['counts'] ?? null) || !xsuValidId($data['target']['id'] ?? null)
        || (string)$data['target']['id'] !== (string)$targetId || count($data['rows']) !== count($items)) {
        throw new RuntimeException('星河设备同步结果不完整或目标场地不符');
    }
    $requested = [];
    foreach ($items as $item) {
        $key = xsuSerialKey($item['serial_number'] ?? null);
        if (isset($requested[$key])) throw new RuntimeException('源设备清单重复');
        $requested[$key] = $item;
    }
    $selected = []; $seen = []; $counts = ['create' => 0, 'update' => 0, 'bind' => 0, 'skip' => 0];
    foreach ($data['rows'] as $row) {
        if (!is_array($row)) throw new RuntimeException('星河设备同步明细格式异常');
        $key = xsuSerialKey($row['serial_number'] ?? null);
        $action = $row['action'] ?? '';
        if (!isset($requested[$key]) || isset($seen[$key]) || !array_key_exists($action, $counts)
            || $row['serial_number'] !== $requested[$key]['serial_number']) throw new RuntimeException('星河设备同步明细与源设备不一致');
        $seen[$key] = true; $counts[$action]++;
        if ($action === 'skip') continue;
        if (isset($requested[$key]['error']) || !isset($identities[$key])) throw new RuntimeException('星河成功明细缺少可确认的RC设备身份');
        $selected[] = $identities[$key];
    }
    foreach ($counts as $action => $count) {
        if (xsuCount($data['summary'][$action] ?? null, count($items)) !== $count) throw new RuntimeException('星河设备处理汇总与明细不一致');
    }
    if (xsuCount($data['counts']['created_devices'] ?? null, count($items)) !== $counts['create']
        || xsuCount($data['counts']['updated_devices'] ?? null, count($items)) !== $counts['update'] + $counts['bind']
        || xsuCount($data['counts']['bound_devices'] ?? null, count($items)) !== $counts['bind']) {
        throw new RuntimeException('星河设备实际处理数量与成功明细不一致');
    }
    return $selected;
}

function xsuVenueSuccesses($data, $identities, $sourceVenueId) {
    if (!is_array($data) || !xsuValidId($data['xinghe_venue_id'] ?? null)
        || (string)($data['rc_venue_id'] ?? '') !== (string)$sourceVenueId
        || !is_array($data['skipped_devices'] ?? null)) throw new RuntimeException('星河场地同步结果不完整');
    $skipped = [];
    foreach ($data['skipped_devices'] as $row) {
        if (!is_array($row)) throw new RuntimeException('星河跳过设备明细格式异常');
        $key = xsuSerialKey($row['serial_number'] ?? null);
        if (!isset($identities[$key]) || isset($skipped[$key]) || $row['serial_number'] !== $identities[$key]['serial_number']
            || ($row['action'] ?? '') !== 'skipped_other_venue' || !xsuValidId($row['existing_venue_id'] ?? null)
            || (string)($row['requested_venue_id'] ?? '') !== (string)$data['xinghe_venue_id']) {
            throw new RuntimeException('星河跳过设备明细与本次同步不一致');
        }
        $skipped[$key] = true;
    }
    $selected = array_values(array_diff_key($identities, $skipped));
    if (xsuCount($data['created_devices'] ?? null, count($identities))
        + xsuCount($data['updated_devices'] ?? null, count($identities)) !== count($selected)) {
        throw new RuntimeException('星河整场地设备实际处理数量不一致');
    }
    return $selected;
}

function xsuEmptyResult($requestedCount = 0, $success = false) {
    return ['success' => $success, 'requested_count' => $requestedCount, 'unshared_count' => 0, 'changed_count' => 0, 'serial_numbers' => []];
}

function xsuCloseSharing($conn, $identities) {
    if (!$identities) return xsuEmptyResult(0, true);
    xsuRequireSourceSchema($conn);
    usort($identities, function ($a, $b) {
        $left = (string)$a['id']; $right = (string)$b['id'];
        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) - strlen($right);
    });
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$conn->begin_transaction()) {
        throw new RuntimeException('RC关闭共享事务无法启动');
    }
    try {
        $locked = [];
        foreach ($identities as $identity) {
            $serial = $identity['serial_number'];
            $rows = xhQuery($conn, 'SELECT id,serial_number,sharing_status FROM vehicles WHERE serial_number=? FOR UPDATE', [$serial]);
            if (count($rows) !== 1 || (string)$rows[0]['id'] !== $identity['id'] || (string)$rows[0]['serial_number'] !== $serial) {
                throw new RuntimeException('RC设备在同步期间被删除、替换或出现重复序列号');
            }
            $locked[] = $rows[0];
        }
        $serials = []; $changed = 0;
        foreach ($locked as $row) {
            $serial = (string)$row['serial_number'];
            if ($row['sharing_status'] !== '未共享') {
                xhQuery($conn, "UPDATE vehicles SET sharing_status='未共享' WHERE id=? AND BINARY serial_number=BINARY ?", [(string)$row['id'], $serial]);
                $verified = xhQuery($conn, 'SELECT sharing_status FROM vehicles WHERE id=? AND BINARY serial_number=BINARY ?', [(string)$row['id'], $serial]);
                if (count($verified) !== 1 || $verified[0]['sharing_status'] !== '未共享') throw new RuntimeException('RC共享状态未能写入');
                $changed++;
            }
            $serials[] = $serial;
        }
        if (!$conn->commit()) throw new RuntimeException('RC关闭共享提交结果无法确认');
        return ['success' => true, 'requested_count' => count($identities), 'unshared_count' => count($serials), 'changed_count' => $changed, 'serial_numbers' => $serials];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

function xsuAfterConfirmedSync($conn, $data, $resolveSuccesses) {
    $result = is_array($data) ? $data : [];
    $requestedCount = 0;
    try {
        $selected = $resolveSuccesses();
        $requestedCount = count($selected);
        $result['rc_unshare'] = xsuCloseSharing($conn, $selected);
        unset($result['rc_unshare_warning']);
    } catch (Throwable $e) {
        error_log('RC unshare after confirmed Xinghe sync: ' . $e->getMessage());
        $result['rc_unshare'] = xsuEmptyResult($requestedCount, false);
        $result['rc_unshare_warning'] = '星河已同步，但RC未成功关闭共享或结果尚未确认。请立即到RC核对并将对应车辆设为“未共享”，确认前不要在两端同时使用。';
    }
    return $result;
}

function xsuAfterSelectedSync($conn, $data, $items, $identities, $targetId) {
    return xsuAfterConfirmedSync($conn, $data, function () use ($data, $items, $identities, $targetId) {
        return xsuSelectedSuccesses($data, $items, $identities, $targetId);
    });
}

function xsuAfterVenueSync($conn, $data, $identities, $sourceVenueId) {
    return xsuAfterConfirmedSync($conn, $data, function () use ($data, $identities, $sourceVenueId) {
        return xsuVenueSuccesses($data, $identities, $sourceVenueId);
    });
}
