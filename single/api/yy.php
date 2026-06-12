<?php
require_once 'RedisHelper.php'; // 引入RedisHelper类
require_once 'Database.php';    // 引入Database类

// 创建 Redis 和数据库连接实例
$redisHelper = new RedisHelper();
$database = new Database();

try {
    // 连接 Redis 服务器并选择数据库 5
    $redisHelper->connect();
    $redisHelper->selectDb(4);

    // 获取 Redis 中所有设备的 serial_number（假设 Redis 中存储的是设备 ID）
    $deviceKeys = $redisHelper->getAllKeys('*');

    // 遍历 Redis 中的所有设备并检查是否存在于 vehicles 表中
    foreach ($deviceKeys as $key) {
        // 获取设备 ID
        $deviceId = $key;

        // 查询数据库中是否存在该设备的 serial_number
        $query = "SELECT serial_number FROM vehicles WHERE serial_number = ?";
        $stmt = $database->prepare($query);
        $stmt->bind_param('s', $deviceId);  // 绑定参数
        $stmt->execute();

        $result = $stmt->get_result();

        // 如果设备在数据库中不存在
        if ($result->num_rows === 0) {
            echo "设备 ID {$deviceId} 没有被添加。\n";
        }

        $stmt->close();
    }

    // 关闭 Redis 和数据库连接
    $redisHelper->close();
    $database->close();
} catch (Exception $e) {
    echo "发生错误: " . $e->getMessage();
}
?>
