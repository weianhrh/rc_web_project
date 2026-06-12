<?php
require_once '../Database.php'; // 确保正确的路径

// 创建数据库连接
$database = new Database();

// 获取今天的日期
$today = date('Y-m-d');

// 查询今日的充值记录
$sql = "SELECT id, uid, order_number, product_name, shop_id, shop_type, value, extra_value, payer_total, status, created_at, paid_at, reservation_id
        FROM RechargeOrders
        WHERE DATE(created_at) = ?";
$params = [$today];

// 执行查询
$result = $database->query($sql, $params);

if ($result !== false) {
    // 输出 JSON 格式的结果
    echo json_encode(['success' => true, 'data' => $result]);
} else {
    // 输出错误信息
    echo json_encode(['success' => false, 'message' => '获取今日充值记录失败']);
}

// 关闭数据库连接
$database->close();
