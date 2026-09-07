<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function jsonOut($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function isValidDateYmd($value) {
    return is_string($value) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

$database = new Database();

try {
    $targetDate = isset($_GET['date']) && $_GET['date'] !== ''
        ? trim($_GET['date'])
        : date('Y-m-d', strtotime('-1 day'));

    $dateObject = DateTime::createFromFormat('!Y-m-d', $targetDate);
    if (!isValidDateYmd($targetDate) || !$dateObject || $dateObject->format('Y-m-d') !== $targetDate) {
        jsonOut(400, '日期格式错误，请使用 YYYY-MM-DD');
    }

    $startTime = $targetDate . ' 00:00:00';
    $endTime = $dateObject->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $venues = $database->query("SELECT id FROM venues");
    if ($venues === false) {
        jsonOut(500, '场地列表查询失败');
    }

    $inserted = 0;
    $updated = 0;
    $skippedChecked = 0;
    $failed = 0;
    $totalRevenue = 0.0;
    $details = [];

    foreach ($venues as $venue) {
        $venueId = (int)$venue['id'];

        $totalPaymentSql = "
            SELECT COALESCE(SUM(payment_amount), 0) AS total_payment
            FROM orders
            WHERE end_time >= ?
              AND end_time < ?
              AND reservation_id = ?
              AND status = '已完成'
              AND pays_type != '能量'
        ";
        $totalPaymentResult = $database->query($totalPaymentSql, [$startTime, $endTime, $venueId]);
        if ($totalPaymentResult === false) {
            $failed++;
            $details[] = [
                'venue_id' => $venueId,
                'action' => 'failed',
                'reason' => '订单收入查询失败',
            ];
            continue;
        }

        $venueRevenue = number_format((float)($totalPaymentResult[0]['total_payment'] ?? 0), 2, '.', '');
        $totalRevenue += (float)$venueRevenue;

        $checkExist = $database->query(
            "SELECT id, is_checked FROM DailyVenueRevenue WHERE date = ? AND venue_id = ? LIMIT 1",
            [$targetDate, $venueId]
        );
        if ($checkExist === false) {
            $failed++;
            $details[] = [
                'venue_id' => $venueId,
                'action' => 'failed',
                'reason' => '账单记录查询失败',
            ];
            continue;
        }

        if ($checkExist) {
            $recordId = (int)$checkExist[0]['id'];
            if ((int)$checkExist[0]['is_checked'] === 1) {
                $skippedChecked++;
                $details[] = [
                    'venue_id' => $venueId,
                    'daily_revenue_id' => $recordId,
                    'action' => 'skipped_checked',
                    'total_revenue' => $venueRevenue,
                ];
                continue;
            }

            $updateSql = "
                UPDATE DailyVenueRevenue
                SET total_revenue = ?
                WHERE id = ?
                  AND is_checked = 0
            ";
            $result = $database->query($updateSql, [$venueRevenue, $recordId], true);
            if ($result === false) {
                $failed++;
                $details[] = [
                    'venue_id' => $venueId,
                    'daily_revenue_id' => $recordId,
                    'action' => 'failed',
                    'reason' => '账单记录更新失败',
                ];
                continue;
            }
            $updated++;
            continue;
        }

        $insertSql = "
            INSERT INTO DailyVenueRevenue (venue_id, date, total_revenue)
            VALUES (?, ?, ?)
        ";
        $result = $database->query($insertSql, [$venueId, $targetDate, $venueRevenue], true);
        if ($result === false) {
            $failed++;
            $details[] = [
                'venue_id' => $venueId,
                'action' => 'failed',
                'reason' => '账单记录插入失败',
            ];
            continue;
        }
        $inserted++;
    }

    jsonOut(0, '收入数据已更新', [
        'date' => $targetDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'venue_count' => count($venues),
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped_checked' => $skippedChecked,
        'failed' => $failed,
        'total_revenue' => number_format($totalRevenue, 2, '.', ''),
        'details' => $details,
    ]);
} catch (Throwable $e) {
    jsonOut(500, '收入数据更新失败：' . $e->getMessage());
} finally {
    $database->close();
}
