<?php
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode([
        'code' => 403,
        'msg' => '该任务仅允许服务器计划任务执行',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

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
    $promotionDeductionEffectiveDate = '2026-09-07';
    $cliOptions = getopt('', ['date:']);
    $targetDate = !empty($cliOptions['date'])
        ? trim($cliOptions['date'])
        : date('Y-m-d', strtotime('-1 day'));

    $dateObject = DateTime::createFromFormat('!Y-m-d', $targetDate);
    if (!isValidDateYmd($targetDate) || !$dateObject || $dateObject->format('Y-m-d') !== $targetDate) {
        jsonOut(400, '日期格式错误，请使用 YYYY-MM-DD');
    }

    $startTime = $targetDate . ' 00:00:00';
    $endTime = $dateObject->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $usePromotionDeduction = ($targetDate >= $promotionDeductionEffectiveDate);

    $venues = $database->query("SELECT id FROM venues");
    if ($venues === false) {
        jsonOut(500, '场地列表查询失败');
    }

    $inserted = 0;
    $updated = 0;
    $skippedChecked = 0;
    $skippedLegacyMissing = 0;
    $failed = 0;
    $totalRevenueBeforeDeduction = 0.0;
    $totalPromotionDeduction = 0.0;
    $totalRevenue = 0.0;
    $details = [];

    foreach ($venues as $venue) {
        $venueId = (int)$venue['id'];

        if ($usePromotionDeduction) {
            $totalPaymentSql = "
                SELECT
                    COALESCE(SUM(o.payment_amount), 0) AS revenue_before_deduction,
                    COALESCE(SUM(
                        CASE
                            WHEN u.invitation_code IS NOT NULL
                             AND u.invitation_code <> ''
                             AND u.invitation_code <> '0000'
                             AND promotion_venue.id IS NOT NULL
                             AND promotion_venue.id <> o.reservation_id
                            THEN ROUND(o.payment_amount * 0.10, 2)
                            ELSE 0
                        END
                    ), 0) AS promotion_deduction
                FROM orders o
                LEFT JOIN users u
                       ON u.uid = o.uid
                LEFT JOIN venues promotion_venue
                       ON promotion_venue.invite_code = u.invitation_code
                WHERE o.end_time >= ?
                  AND o.end_time < ?
                  AND o.reservation_id = ?
                  AND o.status = '已完成'
                  AND o.pays_type != '能量'
            ";
        } else {
            $totalPaymentSql = "
                SELECT
                    COALESCE(SUM(o.payment_amount), 0) AS revenue_before_deduction,
                    0 AS promotion_deduction
                FROM orders o
                WHERE o.end_time >= ?
                  AND o.end_time < ?
                  AND o.reservation_id = ?
                  AND o.status = '已完成'
                  AND o.pays_type != '能量'
            ";
        }
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

        $revenueBeforeDeduction = (float)($totalPaymentResult[0]['revenue_before_deduction'] ?? 0);
        $promotionDeduction = (float)($totalPaymentResult[0]['promotion_deduction'] ?? 0);
        $venueRevenueRaw = max(0, $revenueBeforeDeduction - $promotionDeduction);

        $revenueBeforeDeductionFormatted = number_format($revenueBeforeDeduction, 2, '.', '');
        $promotionDeductionFormatted = number_format($promotionDeduction, 2, '.', '');
        $venueRevenue = number_format($venueRevenueRaw, 2, '.', '');

        $totalRevenueBeforeDeduction += $revenueBeforeDeduction;
        $totalPromotionDeduction += $promotionDeduction;
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
                    'revenue_before_deduction' => $revenueBeforeDeductionFormatted,
                    'promotion_deduction' => $promotionDeductionFormatted,
                    'total_revenue' => $venueRevenue,
                ];
                continue;
            }

            if ($usePromotionDeduction) {
                $updateSql = "
                    UPDATE DailyVenueRevenue
                    SET revenue_before_deduction = ?,
                        total_revenue = ?
                    WHERE id = ?
                      AND is_checked = 0
                ";
                $result = $database->query($updateSql, [$revenueBeforeDeductionFormatted, $venueRevenue, $recordId], true);
            } else {
                $updateSql = "
                    UPDATE DailyVenueRevenue
                    SET total_revenue = ?
                    WHERE id = ?
                      AND is_checked = 0
                ";
                $result = $database->query($updateSql, [$venueRevenue, $recordId], true);
            }
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

        if (!$usePromotionDeduction) {
            $skippedLegacyMissing++;
            $details[] = [
                'venue_id' => $venueId,
                'action' => 'skipped_legacy_missing',
                'reason' => '推广扣减生效日前的历史日期不补插缺失账单',
                'revenue_before_deduction' => $revenueBeforeDeductionFormatted,
                'promotion_deduction' => $promotionDeductionFormatted,
                'total_revenue' => $venueRevenue,
            ];
            continue;
        }

        if ($usePromotionDeduction) {
            $insertSql = "
                INSERT INTO DailyVenueRevenue (venue_id, date, revenue_before_deduction, total_revenue)
                VALUES (?, ?, ?, ?)
            ";
            $result = $database->query($insertSql, [$venueId, $targetDate, $revenueBeforeDeductionFormatted, $venueRevenue], true);
        }
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
        'promotion_deduction_effective_date' => $promotionDeductionEffectiveDate,
        'use_promotion_deduction' => $usePromotionDeduction ? 1 : 0,
        'venue_count' => count($venues),
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped_checked' => $skippedChecked,
        'skipped_legacy_missing' => $skippedLegacyMissing,
        'failed' => $failed,
        'revenue_before_deduction' => number_format($totalRevenueBeforeDeduction, 2, '.', ''),
        'promotion_deduction' => number_format($totalPromotionDeduction, 2, '.', ''),
        'total_revenue' => number_format($totalRevenue, 2, '.', ''),
        'details' => $details,
    ]);
} catch (Throwable $e) {
    jsonOut(500, '收入数据更新失败：' . $e->getMessage());
} finally {
    $database->close();
}
