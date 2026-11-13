<?php
// Record evidence only after Xinghe has committed a successful sync.
require_once __DIR__ . '/xinghe_migration_common.php';

function xhmSourceSerials($items) {
    $serials = [];
    foreach ($items as $item) {
        $sn = (string)($item['serial_number'] ?? '');
        $key = strtolower($sn);
        if ($sn === '' || isset($serials[$key])) throw new RuntimeException('源设备序列号为空或重复');
        $serials[$key] = $sn;
    }
    return $serials;
}

function xhmRecordSelectedResult($conn, $data, $items) {
    try {
        $source = xhmSourceSerials($items);
        if (!is_array($data['rows'] ?? null) || count($data['rows']) !== count($source)
            || !ctype_digit((string)($data['target']['id'] ?? ''))
            || trim((string)$data['target']['id'], '0') === '') {
            throw new RuntimeException('星河设备同步结果不完整');
        }
        $successful = []; $seen = [];
        foreach ($data['rows'] as $row) {
            $key = strtolower((string)($row['serial_number'] ?? ''));
            if (!isset($source[$key]) || isset($seen[$key])
                || !in_array($row['action'] ?? '', ['create', 'update', 'bind', 'skip'], true)) {
                throw new RuntimeException('星河设备同步结果与请求不一致');
            }
            $seen[$key] = true;
            if ($row['action'] !== 'skip') $successful[] = $source[$key];
        }
        // A skipped row may have failed before its target identity was checked.
        // It is not sufficient evidence of migration; the backfill checks it.
        if ($successful) xhmMarkSynced($conn, $successful, (string)$data['target']['id'], (string)($data['target']['venue_name'] ?? ''));
    } catch (Throwable $e) {
        error_log('RC migration badge after selected sync: ' . $e->getMessage());
        $data['migration_warning'] = '星河同步已完成，但RC搬迁标识未完整保存。请确认已执行搬迁标识建表SQL，再到设备管理点击“补标历史搬迁”；无需为补标重复同步设备。';
    }
    return $data;
}

function xhmRecordVenueResult($conn, $data, $vehicles, $venueName) {
    try {
        $source = xhmSourceSerials($vehicles);
        $targetId = (string)($data['xinghe_venue_id'] ?? '');
        if (!ctype_digit($targetId) || trim($targetId, '0') === ''
            || !is_array($data['skipped_devices'] ?? null)) throw new RuntimeException('星河整场地同步结果不完整');
        $skipped = [];
        foreach ($data['skipped_devices'] as $row) {
            $key = strtolower((string)($row['serial_number'] ?? ''));
            $existingId = (string)($row['existing_venue_id'] ?? '');
            if (!isset($source[$key]) || isset($skipped[$key]) || $existingId === '' || $existingId === '0'
                || ($row['action'] ?? '') !== 'skipped_other_venue') throw new RuntimeException('星河跳过设备结果不完整');
            $skipped[$key] = true;
            // Skips report a binding but not the actual stored target SN. The
            // historical checker must verify those identities before marking.
        }
        $successful = array_values(array_diff_key($source, $skipped));
        if (!isset($data['created_devices'], $data['updated_devices'])
            || (int)$data['created_devices'] + (int)$data['updated_devices'] !== count($successful)) {
            throw new RuntimeException('星河整场地设备处理数量不一致');
        }
        if ($successful) xhmMarkSynced($conn, $successful, $targetId, (string)$venueName);
    } catch (Throwable $e) {
        error_log('RC migration badge after venue sync: ' . $e->getMessage());
        $data['migration_warning'] = '星河同步已完成，但RC搬迁标识未完整保存。请确认已执行搬迁标识建表SQL，再到设备管理点击“补标历史搬迁”；无需为补标重复同步场地。';
    }
    return $data;
}
