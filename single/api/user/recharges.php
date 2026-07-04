<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function ret($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 读取能量充值限制配置
 * 文件路径：当前 PHP 同目录 / energy_charge_limit.ini
 *
 * energy_charge_limit.ini 示例：
 * single_limit=6
 * total_limit=10
 */
function getEnergyChargeConfig() {
    $default = [
        'single_limit' => 6,
        'total_limit'  => 10,
    ];

    $file = __DIR__ . '/energy_charge_limit.ini';

    if (!is_file($file)) {
        return $default;
    }

    $config = @parse_ini_file($file);

    if (!$config || !is_array($config)) {
        return $default;
    }

    $singleLimit = isset($config['single_limit']) && is_numeric($config['single_limit'])
        ? floatval($config['single_limit'])
        : $default['single_limit'];

    $totalLimit = isset($config['total_limit']) && is_numeric($config['total_limit'])
        ? floatval($config['total_limit'])
        : $default['total_limit'];

    if ($singleLimit <= 0) {
        $singleLimit = $default['single_limit'];
    }

    if ($totalLimit <= 0) {
        $totalLimit = $default['total_limit'];
    }

    return [
        'single_limit' => $singleLimit,
        'total_limit'  => $totalLimit,
    ];
}

// 创建数据库连接
$database = new Database();

// 读取配置
$chargeConfig = getEnergyChargeConfig();
$singleLimit = $chargeConfig['single_limit'];
$totalLimit  = $chargeConfig['total_limit'];

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token
if (!$session_token) {
    ret([
        'code' => 1001,
        'msg'  => '用户未登录或会话已过期',
        'data' => []
    ]);
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限
if (!$user || !$user['role_id']) {
    ret([
        'code' => 1001,
        'msg'  => '用户未登录或无权访问',
        'data' => []
    ]);
}

$role_id  = $user['role_id'];
$username = $user['username'];
$venue_id = intval($user['venue_id'] ?? 0);

// 获取请求参数
$uid = $_POST['uid'] ?? null;
$amountRaw = $_POST['amount'] ?? null;

if (!$uid || !is_numeric($amountRaw)) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '无效的用户ID或金额'
    ]);
}

$uid = intval($uid);
$amount = floatval($amountRaw);

if ($amount <= 0) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '充值金额必须大于0'
    ]);
}

if ($venue_id <= 0) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '当前账号未绑定有效场地'
    ]);
}

// 单笔充值上限，从文件读取
if ($amount > $singleLimit) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '单笔超过限额，当前单笔上限：' . $singleLimit
    ]);
}

// 获取与指定场地ID关联的最新用户余额
$queryBalance = "SELECT id, energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1";
$resultBalance = $database->query($queryBalance, [$uid, $venue_id]);

if ($resultBalance && count($resultBalance) > 0) {
    $recordId = intval($resultBalance[0]['id']);
    $energy = floatval($resultBalance[0]['energy']);
} else {
    // 不存在记录时初始化余额为0
    $insertSql = "INSERT INTO energy_records (user_uid, venue_id, energy) VALUES (?, ?, 0)";
    $insertResult = $database->query($insertSql, [$uid, $venue_id], true);

    if ($insertResult === false) {
        ret([
            'code'    => 1,
            'success' => false,
            'message' => '初始化能量记录失败'
        ]);
    }

    // 重新读取刚初始化的记录
    $resultBalance = $database->query($queryBalance, [$uid, $venue_id]);

    if (!$resultBalance || count($resultBalance) <= 0) {
        ret([
            'code'    => 1,
            'success' => false,
            'message' => '读取能量记录失败'
        ]);
    }

    $recordId = intval($resultBalance[0]['id']);
    $energy = floatval($resultBalance[0]['energy']);
}

$newWallet = $amount + $energy;

// 总能量上限，从文件读取
// 注意：这里要判断充值后的余额，而不是只判断充值前余额
if ($newWallet > $totalLimit) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '总能量超过限额，当前余额：' . $energy . '，本次充值：' . $amount . '，总上限：' . $totalLimit
    ]);
}

// 更新余额记录，只更新最新那条，避免同一个用户同场地有多条记录时全部被加钱
$sql = "UPDATE energy_records SET energy = energy + ? WHERE id = ?";
$params = [$amount, $recordId];
$result = $database->query($sql, $params, true);

if ($result === false) {
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '充值失败'
    ]);
}

// 记录余额变动
$balanceChangeSql = "INSERT INTO energy_changes 
    (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change) 
    VALUES (?, ?, ?, ?, ?, ?)";

$reason = '代理充值，充值人员：' . $username;

$balanceChangeParams = [
    $uid,
    $venue_id,
    $amount,
    $newWallet,
    $reason,
    $energy
];

$database->query($balanceChangeSql, $balanceChangeParams, true);

ret([
    'code'    => 0,
    'success' => true,
    'message' => '充值成功，总能量余额：' . $newWallet
]);

$database->close();