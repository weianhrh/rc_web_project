<?php
require_once '../Database.php';

// api/venue/DailyVenueRevenueVenue2Test.php
// 仅用于核对2号测试场地，确认无误后再改为全部场地统计。
header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$venueId = 2;

// 可通过 ?date=2026-08-14 指定日期；未传时默认统计昨天。
$targetDate = isset($_GET['date'])
    ? trim($_GET['date'])
    : date('Y-m-d', strtotime('-1 day'));

// 严格校验日期，防止错误日期被写入核对表。
$dateObject = DateTime::createFromFormat('!Y-m-d', $targetDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $targetDate) {
    $database->close();
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg' => '日期格式错误，请使用 YYYY-MM-DD，例如 2026-08-14。'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$startTime = $targetDate . ' 00:00:00';
$endTime = $dateObject->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// 同一次查询取得扣除前收入、推广扣减及扣除后收入。
// 保持原账单口径：排除能量订单。
$revenueSql = "SELECT
                   COALESCE(SUM(payment_amount), 0) AS revenue_before_deduction,
                   COALESCE(SUM(promotion_amount), 0) AS promotion_deduction,
                   COALESCE(SUM(payment_amount), 0)
                       - COALESCE(SUM(promotion_amount), 0) AS total_revenue
               FROM orders
               WHERE end_time >= ?
                 AND end_time < ?
                 AND reservation_id = ?
                 AND pays_type != '能量'";

$revenueResult = $database->query($revenueSql, [$startTime, $endTime, $venueId]);
$revenue = $revenueResult[0] ?? [];

$revenueBeforeDeduction = $revenue['revenue_before_deduction'] ?? '0.00';
$promotionDeduction = $revenue['promotion_deduction'] ?? '0.00';
$totalRevenue = $revenue['total_revenue'] ?? '0.00';

// 只检查并更新2号测试场地，不影响其他场地。
$existing = $database->query(
    "SELECT id FROM DailyVenueRevenue WHERE date = ? AND venue_id = ? LIMIT 1",
    [$targetDate, $venueId]
);

if ($existing) {
    $database->query(
        "UPDATE DailyVenueRevenue
         SET revenue_before_deduction = ?, total_revenue = ?
         WHERE date = ? AND venue_id = ?",
        [$revenueBeforeDeduction, $totalRevenue, $targetDate, $venueId]
    );
    $action = 'updated';
} else {
    $database->query(
        "INSERT INTO DailyVenueRevenue
            (venue_id, date, revenue_before_deduction, total_revenue)
         VALUES (?, ?, ?, ?)",
        [$venueId, $targetDate, $revenueBeforeDeduction, $totalRevenue]
    );
    $action = 'inserted';
}

$database->close();

echo json_encode([
    'code' => 0,
    'msg' => '2号测试场地收入数据已更新。',
    'data' => [
        'action' => $action,
        'venue_id' => $venueId,
        'date' => $targetDate,
        'revenue_before_deduction' => number_format((float)$revenueBeforeDeduction, 2, '.', ''),
        'promotion_deduction' => number_format((float)$promotionDeduction, 2, '.', ''),
        'total_revenue' => number_format((float)$totalRevenue, 2, '.', '')
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
