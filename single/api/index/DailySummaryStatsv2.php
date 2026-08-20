<?php
require_once '../Database.php'; // Ensure correct path
// api/index/DailySummaryStatsv2.php
// Check for session token in cookie
if (!isset($_COOKIE['session_token'])) {
    header("Location: login.html");
    exit;
}

// Create database connection
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

// Validate session token
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// Validate user and role
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$today = date('Y-m-d');
// $today = date('Y-m-d', strtotime('-1 day'));
// 当天时间范围
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';
$venue_id = ($role_id != 0) ? $user['venue_id'] : null;
$venue_name = null; // Default value

// Get venue name and queue length
$queue_length = 0;
if ($venue_id) {
    $venue_level = 'A';
    $withdraw_ratio = 80;
    
    if ($venue_id) {
        $venueNameSql = "SELECT venue_name, queue_length, venue_level FROM venues WHERE id = ?";
        $venueNameResult = $database->query($venueNameSql, [$venue_id]);
        if ($venueNameResult) {
            $venue_name = $venueNameResult[0]['venue_name'] ?? null;
            $queue_length = $venueNameResult[0]['queue_length'] ?? 0;
    
            $venue_level = strtoupper(trim($venueNameResult[0]['venue_level'] ?? 'A'));
            if (!in_array($venue_level, ['S', 'A', 'B', 'C', 'D'], true)) {
                $venue_level = 'A';
            }
    
            // 当前只启用 A / C
            if ($venue_level === 'C') {
                $withdraw_ratio = 60;
            } else {
                $withdraw_ratio = 80;
            }
        }
    }
    $venueNameResult = $database->query($venueNameSql, [$venue_id]);
    if ($venueNameResult) {
        $venue_name = $venueNameResult[0]['venue_name'] ?? null;
        $queue_length = $venueNameResult[0]['queue_length'] ?? 0;
    }
}

// Order count query
$orderCountSql = "SELECT COUNT(*) AS order_count FROM orders WHERE end_time >= ? AND end_time <= ?" .
    ($venue_id ? " AND reservation_id = ?" : "");

$orderParams = [$todayStart, $todayEnd];
if ($venue_id) {
    $orderParams[] = $venue_id;
}
$orderCount = $database->query($orderCountSql, $orderParams)[0]['order_count'];
// Reservation count query
$reservationCountSql = "SELECT COUNT(*) AS reservation_count FROM Reservations WHERE reservation_time >= ? AND reservation_time <= ?" .
    ($venue_id ? " AND reservation_id = ?" : "");
$reservationParams = [$todayStart, $todayEnd];
if ($venue_id) {
    $reservationParams[] = $venue_id;
}
$reservationCount = $database->query($reservationCountSql, $reservationParams)[0]['reservation_count'];



// 设备/订单收益（排除能量，排除旧礼物订单，避免和 gift_orders 重复计算）
$totalPaymentSql = "SELECT COALESCE(SUM(payment_amount), 0) AS total_payment
                    FROM orders
                    WHERE end_time >= ? AND end_time <= ?
                      AND pays_type != '能量'
                      AND (note <> 'gift' OR note IS NULL)" .
                    ($venue_id ? " AND reservation_id = ?" : "");

$paymentParams = [$todayStart, $todayEnd];
if ($venue_id) {
    $paymentParams[] = $venue_id;
}

$orderTotalPayment = $database->query($totalPaymentSql, $paymentParams)[0]['total_payment'] ?? '0.00';

// 推广扣除：累计当前场地当天已结束订单的 promotion_amount
$promotionDeductionSql = "SELECT COALESCE(SUM(promotion_amount), 0) AS promotion_deduction
                          FROM orders
                          WHERE end_time >= ? AND end_time <= ?
                            AND pays_type != '能量'
                            AND (note <> 'gift' OR note IS NULL)" .
                         ($venue_id ? " AND reservation_id = ?" : "");

$promotionDeductionParams = [$todayStart, $todayEnd];
if ($venue_id) {
    $promotionDeductionParams[] = $venue_id;
}

$promotionDeductionResult = $database->query($promotionDeductionSql, $promotionDeductionParams);
$promotionDeduction = (float)($promotionDeductionResult[0]['promotion_deduction'] ?? 0);


// 国内设备扣除前收益：和驾驶订单收益一致，排除能量和礼物
$devicePaymentBeforeDeductionRaw = (float)$orderTotalPayment;

// role_id = 4 时，国内设备收益按 80% 返回
if ((int)$role_id === 4) {
    $devicePaymentBeforeDeductionRaw *= 0.8;
}

// promotion_amount 是驾驶订单产生的扣减，因此国内设备收益本身也要扣除
$devicePaymentRaw = $devicePaymentBeforeDeductionRaw - $promotionDeduction;
$devicePaymentBeforeDeduction = number_format($devicePaymentBeforeDeductionRaw, 2, '.', '');
$devicePayment = number_format($devicePaymentRaw, 2, '.', '');


// 礼物收益：从 gift_orders 按 send_time 当天统计
$giftPaymentSql = "SELECT COALESCE(SUM(payment_amount), 0) AS venue_gift_total
                   FROM gift_orders
                   WHERE send_time >= ?
                     AND send_time <= ?" .
                   ($venue_id ? " AND reservation_id = ?" : "");

$giftPaymentParams = [$todayStart, $todayEnd];

if ($venue_id) {
    $giftPaymentParams[] = $venue_id;
}

$venueGiftTotal = $database->query($giftPaymentSql, $giftPaymentParams)[0]['venue_gift_total'] ?? '0.00';

// 礼物收益 = 礼物金币 / 10 * 60%
$giftPayment = number_format(((float)$venueGiftTotal / 10) * 0.6, 2, '.', '');


// 扣除前收益 = 国内设备扣除前收益 + 礼物收益
// 今日收益 = 国内设备扣除后收益 + 礼物收益，避免再次重复扣除 promotion_amount
// role_id=4 时，国内设备部分已先按 80% 计算
$totalPaymentBeforeDeductionRaw = $devicePaymentBeforeDeductionRaw + (float)$giftPayment;
$totalPaymentBeforeDeduction = number_format($totalPaymentBeforeDeductionRaw, 2, '.', '');
$totalPayment = number_format($devicePaymentRaw + (float)$giftPayment, 2, '.', '');

// 推广收益：按 invitation_venue_id 归属到当前登录场地。
// 统计 recorded_at，表示今天实际生成的满 24 小时推广收益；不能按原订单 end_time 统计。
$promotionIncomeSql = "SELECT COALESCE(SUM(promotion_amount), 0) AS promotion_income
                       FROM promotion_order_statistics
                       WHERE recorded_at >= ? AND recorded_at <= ?" .
                      ($venue_id ? " AND invitation_venue_id = ?" : "");

$promotionIncomeParams = [$todayStart, $todayEnd];
if ($venue_id) {
    $promotionIncomeParams[] = $venue_id;
}

$promotionIncomeResult = $database->query($promotionIncomeSql, $promotionIncomeParams);
$promotionIncome = number_format((float)($promotionIncomeResult[0]['promotion_income'] ?? 0), 2, '.', '');
// Report count
$reportCountSql = "SELECT COUNT(r.device_id) AS report_count
                   FROM Reports r
                   JOIN vehicles v ON r.device_id = v.serial_number  
                   WHERE v.bind_site = ? AND (r.status  = '未处理' OR r.status  = '处理中')";
$reportCountResult = $database->query($reportCountSql, [$venue_id]);

// Output data as JSON
echo json_encode([
    'code' => 0,
    'msg' => '',
    'data' => [
        'venue_level' => $venue_level,
        'withdraw_ratio' => $withdraw_ratio,
        'orderCount' => $orderCount,
        'reservationCount' => $reservationCount,
        'venue_id' => $venue_id,
        'venue_name' => $venue_name,
        'queue_length' => 0,
        'totalPayment' => $totalPayment,
        'totalPaymentBeforeDeduction' => $totalPaymentBeforeDeduction,
        'promotionDeduction' => number_format($promotionDeduction, 2, '.', ''),
        'promotionIncome' => $promotionIncome,
        'devicePaymentBeforeDeduction' => $devicePaymentBeforeDeduction,
        'devicePayment' => $devicePayment,
        'giftPayment' => $giftPayment,
        'reportCount' => $reportCountResult[0]['report_count']
    ]
]);

// Close database connection
$database->close();
?>
