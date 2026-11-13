<?php
/**
 * 图传绑定优先使用 device_information.id；未匹配 id 的旧数据再按 room_id 匹配。
 * 主图传和备用图传均可查到车辆，车型以控制设置表为准。
 */
function getRoomVehicleControlContext($database, string $roomId): array
{
    $rows = $database->query(
        "SELECT DISTINCT v.id AS vehicle_id, vcs.car_type
         FROM device_information di
         INNER JOIN vehicles v ON (
             v.image_device_serial = CAST(di.id AS CHAR)
             OR v.bk_image_device_serial = CAST(di.id AS CHAR)
             OR (v.image_device_serial = di.room_id AND NOT EXISTS (
                 SELECT 1 FROM device_information primary_di
                 WHERE CAST(primary_di.id AS CHAR) = v.image_device_serial
             ))
             OR (v.bk_image_device_serial = di.room_id AND NOT EXISTS (
                 SELECT 1 FROM device_information backup_di
                 WHERE CAST(backup_di.id AS CHAR) = v.bk_image_device_serial
             ))
         )
         LEFT JOIN vehicle_control_settings vcs ON vcs.serial_number = v.serial_number
         WHERE di.room_id = ?",
        [$roomId]
    );
    if ($rows === false) {
        throw new RuntimeException('房间关联车辆查询失败');
    }

    $carType = null;
    $vehicleIds = [];
    foreach ($rows as $row) {
        $vehicleIds[(string)$row['vehicle_id']] = true;
    }
    if ($rows) {
        $types = array_unique(array_map(function ($row) {
            return $row['car_type'] === null ? null : (int)$row['car_type'];
        }, $rows), SORT_REGULAR);
        // 同一房间绑定不同车型或缺少车型时，不展示反转快捷按钮。
        if (count($types) === 1) {
            $carType = reset($types);
        }
    }
    return [
        'room_id' => $roomId,
        'car_type' => $carType,
        'vehicle_count' => count($vehicleIds),
    ];
}
