<?php
// 驾驶确认时长保存在 vehicles 普通字段中；此文件只读表结构，不自动建表或改字段。

function driveConfirmConfigColumns($conn) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $result = $conn->query('SHOW COLUMNS FROM `vehicles`');
    if (!$result) {
        throw new RuntimeException('读取驾驶确认配置表结构失败');
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = $row;
    }
    $result->free();
    return $cache = $columns;
}

function driveConfirmConfigFetchVehicle($conn, $serialNumber, $forUpdate = false) {
    $columns = driveConfirmConfigColumns($conn);
    $secondsColumn = isset($columns['drive_confirm_seconds'])
        ? '`drive_confirm_seconds`'
        : 'NULL AS drive_confirm_seconds';

    $sql = "SELECT id, $secondsColumn FROM vehicles WHERE serial_number = ? LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('读取设备驾驶确认配置失败');
    }
    $stmt->bind_param('s', $serialNumber);
    if (!$stmt->execute()) {
        throw new RuntimeException('读取设备驾驶确认配置失败');
    }
    $result = $stmt->get_result();
    $vehicle = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $vehicle;
}

function driveConfirmConfigCanAccess($user, $vehicle) {
    return $vehicle && in_array((int)($user['role_id'] ?? 0), [1, 2], true);
}

function driveConfirmConfigGetState($database, $user, $serialNumber) {
    $state = [
        'value' => 10,
        'raw_value' => null,
        'available' => false,
        'editable' => false,
        'message' => '',
        'error_code' => 1003
    ];

    try {
        $conn = $database->getConnection();
        $columns = driveConfirmConfigColumns($conn);
        $column = $columns['drive_confirm_seconds'] ?? null;
        $generated = $column && preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', $column['Extra'] ?? '');
        $state['available'] = $column !== null && !$generated;

        $vehicle = driveConfirmConfigFetchVehicle($conn, $serialNumber);
        if (!$vehicle) {
            $state['message'] = '未找到对应设备，无法配置驾驶确认时长';
            $state['error_code'] = 1006;
            return $state;
        }
        // raw_value 仅供服务端日志使用；无权限时不向页面返回设备实际秒数。
        $state['raw_value'] = $vehicle['drive_confirm_seconds'];
        if (!driveConfirmConfigCanAccess($user, $vehicle)) {
            $state['message'] = '仅平台管理员可修改驾驶确认时长';
            return $state;
        }

        $state['value'] = $vehicle['drive_confirm_seconds'] === null ? 10 : (int)$vehicle['drive_confirm_seconds'];
        if (!$column) {
            $state['message'] = '请先在 vehicles 表添加普通字段 drive_confirm_seconds（默认 10）后重试';
            $state['error_code'] = 1012;
        } elseif ($generated) {
            $state['message'] = 'drive_confirm_seconds 当前是生成列，请先改为普通字段（默认 10）后重试';
            $state['error_code'] = 1012;
        } else {
            $state['editable'] = true;
            $state['error_code'] = 0;
        }
    } catch (Throwable $e) {
        $state['message'] = '读取驾驶确认配置失败，请刷新重试或检查数据库字段';
        $state['error_code'] = 500;
        error_log('driveConfirmConfig: ' . $e->getMessage());
    }

    return $state;
}

function driveConfirmConfigAppendSnapshot($database, $serialNumber, $settings) {
    if (!$settings) return $settings;
    try {
        $vehicle = driveConfirmConfigFetchVehicle($database->getConnection(), $serialNumber);
        if ($vehicle) {
            $settings['drive_confirm_seconds'] = $vehicle['drive_confirm_seconds'];
        }
    } catch (Throwable $e) {
        error_log('driveConfirmConfig snapshot: ' . $e->getMessage());
    }
    return $settings;
}
