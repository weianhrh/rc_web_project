<?php
require_once '../Database.php'; // 确保路径正确
// api/venue/script.php
// 创建数据库连接
$database = new Database();

// 获取传入的日期，如果没有传入，则使用昨日的日期
$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));

// 获取所有场地的ID
$venues = $database->query("SELECT id FROM venues");

foreach ($venues as $venue) {
    $venue_id = $venue['id'];

    // 查询扣除前收入和推广扣减金额
    $totalPaymentSql = "SELECT
                            COALESCE(SUM(payment_amount), 0) AS revenue_before_deduction,
                            COALESCE(SUM(promotion_amount), 0) AS promotion_deduction
                        FROM orders
                        WHERE DATE(end_time) = ?
                          AND reservation_id = ?
                          AND pays_type != '能量'";
    $paymentParams = [$today, $venue_id];
    $totalPaymentResult = $database->query($totalPaymentSql, $paymentParams);

    // 扣除前收入
    $revenueBeforeDeduction = $totalPaymentResult[0]['revenue_before_deduction'] ?: '0.00';

    // 扣除后收入 = 扣除前收入 - 推广金额累计
    $promotionDeduction = $totalPaymentResult[0]['promotion_deduction'] ?: '0.00';
    $totalPayment = number_format(
        (float)$revenueBeforeDeduction - (float)$promotionDeduction,
        2,
        '.',
        ''
    );

    // 检查是否已存在记录
    $checkExist = $database->query(
        "SELECT id FROM DailyVenueRevenue WHERE date = ? AND venue_id = ?",
        [$today, $venue_id]
    );

    if ($checkExist) {
        // 更新已存在的记录
        $updateSql = "UPDATE DailyVenueRevenue
                      SET revenue_before_deduction = ?, total_revenue = ?
                      WHERE date = ? AND venue_id = ?";
        $database->query(
            $updateSql,
            [$revenueBeforeDeduction, $totalPayment, $today, $venue_id]
        );
    } else {
        // 插入新记录
        $insertSql = "INSERT INTO DailyVenueRevenue
                      (venue_id, date, revenue_before_deduction, total_revenue)
                      VALUES (?, ?, ?, ?)";
        $database->query(
            $insertSql,
            [$venue_id, $today, $revenueBeforeDeduction, $totalPayment]
        );
    }
}

// 关闭数据库连接
$database->close();

echo "收入数据已更新。";
?>
