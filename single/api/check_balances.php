<?php
require_once 'Database.php';  // 确保已包含上述数据库类的定义

function checkUserBalances($specifiedDate) {
    $db = new Database();
    try {
        // 第一步：获取所有余额支付的订单UID
        $sql = "SELECT DISTINCT uid FROM orders WHERE DATE(end_time) = ? AND pays_type = '余额'";
        $userIds = $db->query($sql, [$specifiedDate]);

        if (!$userIds) {
            $db->logToFile("未找到订单或查询指定日期失败：" . $specifiedDate);
            return;
        }

        // 循环每个用户ID进行核查
        foreach ($userIds as $user) {
            $uid = $user['uid'];

            // 第二步：动态更新累计消费字段
            $updateSql = "
                UPDATE users 
                SET cumulative_spending = (
                    SELECT COALESCE(SUM(payer_total + extra_value), 0)
                    FROM RechargeOrders
                    WHERE RechargeOrders.uid = users.uid
                    AND status = '支付成功'
                )
                WHERE uid = ?";
            $db->query($updateSql, [$uid]);

            // 获取更新后的用户信息
            $userInfo = $db->query("SELECT wallet, cumulative_spending FROM users WHERE uid = ?", [$uid]);
            if (!$userInfo) {
                $db->logToFile("未能获取用户信息，用户ID：" . $uid);
                continue;
            }
            $wallet = $userInfo[0]['wallet'];
            $cumulative_spending = $userInfo[0]['cumulative_spending'];

            // 第三步：计算用户的非能量支付总额
            $totalPayment = $db->query("SELECT COALESCE(SUM(payment_amount), 0) AS total_payment FROM orders WHERE uid = ? AND pays_type != '能量'", [$uid]);
            $totalPayment = $totalPayment[0]['total_payment'];

            // 核查余额是否匹配
            if (($totalPayment + $wallet) > $cumulative_spending) {
                $message = "余额不符，用户ID：" . $uid . " - 支付总额 + 钱包余额 != 累计充值 (" . $totalPayment . " + " . $wallet . " != " . $cumulative_spending . ")";
                $db->logToFile($message);
            } /*else {
                //$db->logToFile("账户核查通过，用户ID：" . $uid);
            }*/
        }
    } catch (Exception $e) {
        $db->logToFile("余额核查过程中出错：" . $e->getMessage());
    } finally {
        $db->close();
    }
}

// 调用函数
$specifiedDate = '2025-04-14';  // 指定要检查的日期
checkUserBalances($specifiedDate);
?>
