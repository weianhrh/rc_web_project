<?php
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json; charset=utf-8');

function rechargeOut($code, $message) {
    echo json_encode([
        'code' => $code,
        'success' => $code === 0,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$uid = $_POST['uid'] ?? null;
$amount = $_POST['amount'] ?? null;

if (!is_scalar($uid) || !ctype_digit((string)$uid) || (int)$uid <= 0
    || !is_scalar($amount) || !is_numeric($amount) || !is_finite((float)$amount)) {
    rechargeOut(1, '无效的用户ID或金额');
}

$connection = $database->getConnection();
$transactionStarted = false;
try {
    if (!$connection->begin_transaction()) {
        throw new Exception('开启充值事务失败');
    }
    $transactionStarted = true;

    // 串行处理同一用户的余额变动，流水使用锁定后的余额。
    $users = $database->query('SELECT wallet FROM users WHERE uid = ? FOR UPDATE', [$uid]);
    if ($users === false) {
        throw new Exception('查询用户余额失败');
    }
    if (!$users) {
        throw new Exception('用户不存在');
    }
    $wallet = $users[0]['wallet'];

    $result = $database->query('UPDATE users SET wallet = wallet + ? WHERE uid = ?', [$amount, $uid], true);
    if ($result === false) {
        throw new Exception('更新用户余额失败');
    }

    // 读取数据库实际保存的余额，避免金额精度转换使流水与钱包不一致。
    $updatedUsers = $database->query('SELECT wallet FROM users WHERE uid = ?', [$uid]);
    if ($updatedUsers === false || !$updatedUsers) {
        throw new Exception('读取充值后余额失败');
    }
    $newWallet = $updatedUsers[0]['wallet'];
    $balanceChangeSql = "INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, '充值', '官方代充', '官方01')";
    $change = $database->query($balanceChangeSql, [$uid, $amount, $wallet, $newWallet], true);
    if ($change === false || $change !== 1) {
        throw new Exception('写入余额变动流水失败');
    }

    if (!$connection->commit()) {
        throw new Exception('提交充值事务失败');
    }
    $transactionStarted = false;
} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackError) {
            error_log('recharge rollback: ' . $rollbackError->getMessage());
        }
    }
    error_log('recharge: ' . $e->getMessage());
    rechargeOut(1, '充值失败，请稍后重试');
}

$database->close();
rechargeOut(0, '充值成功');
