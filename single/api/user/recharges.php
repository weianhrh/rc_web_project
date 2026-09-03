<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function ret($data) {
    // 同时返回 msg / message，兼容项目里两种前端提示字段。
    if (is_array($data)) {
        if (isset($data['message']) && !isset($data['msg'])) {
            $data['msg'] = $data['message'];
        } elseif (isset($data['msg']) && !isset($data['message'])) {
            $data['message'] = $data['msg'];
        }
    }

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
 * daily_gift_count_limit=3
 */
function getEnergyChargeConfig() {
    $default = [
        'single_limit'          => 6,
        'total_limit'           => 10,
        'daily_gift_count_limit' => 3,
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

    $dailyGiftCountLimit = isset($config['daily_gift_count_limit'])
        && is_numeric($config['daily_gift_count_limit'])
        ? intval($config['daily_gift_count_limit'])
        : $default['daily_gift_count_limit'];

    if ($singleLimit <= 0) {
        $singleLimit = $default['single_limit'];
    }

    if ($totalLimit <= 0) {
        $totalLimit = $default['total_limit'];
    }

    if ($dailyGiftCountLimit <= 0) {
        $dailyGiftCountLimit = $default['daily_gift_count_limit'];
    }

    return [
        'single_limit'           => $singleLimit,
        'total_limit'            => $totalLimit,
        'daily_gift_count_limit' => $dailyGiftCountLimit,
    ];
}

// 创建数据库连接
$database = new Database();

// 读取配置
$chargeConfig = getEnergyChargeConfig();
$singleLimit = $chargeConfig['single_limit'];
$totalLimit  = $chargeConfig['total_limit'];
$dailyGiftCountLimit = $chargeConfig['daily_gift_count_limit'];

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

$role_id  = intval($user['role_id']);
$username = $user['username'];
$venue_id = intval($user['venue_id'] ?? 0);

// 只有平台管理员 role_id=1/2 不受每日次数限制，其余角色都需要限制。
$shouldLimitDailyGiftCount = !in_array($role_id, [1, 2], true);

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

// 次数校验、余额更新、变动日志和次数累加必须在同一事务中完成。
// 计数行使用 FOR UPDATE 锁定，可防止快速连点、多窗口或并发请求绕过每日 3 次限制。
$database->beginTransaction();

try {
    if ($shouldLimitDailyGiftCount) {
        $ensureCounterSql = "INSERT INTO venue_user_daily_energy_gift_counts
            (venue_id, user_uid, gift_date, gift_count)
            VALUES (?, ?, CURRENT_DATE(), 0)
            ON DUPLICATE KEY UPDATE id = id";
        $ensureCounterResult = $database->query($ensureCounterSql, [$venue_id, $uid], true);
        if ($ensureCounterResult === false) {
            throw new Exception('初始化每日赠送次数失败');
        }

        $counterSql = "SELECT gift_count
            FROM venue_user_daily_energy_gift_counts
            WHERE venue_id = ? AND user_uid = ? AND gift_date = CURRENT_DATE()
            FOR UPDATE";
        $counterResult = $database->query($counterSql, [$venue_id, $uid]);
        if ($counterResult === false || count($counterResult) === 0) {
            throw new Exception('读取每日赠送次数失败');
        }

        $todayGiftCount = intval($counterResult[0]['gift_count']);
        if ($todayGiftCount >= $dailyGiftCountLimit) {
            $database->rollBack();
            ret([
                'code'    => 1008,
                'success' => false,
                'msg'     => '这个用户赠送能量次数已达上限，请联系管理员赠送',
                'data'    => [
                    'daily_limit' => $dailyGiftCountLimit,
                    'today_count' => $todayGiftCount,
                ],
            ]);
        }
    }

    // 锁定当前场地、当前用户最新的能量记录，保证余额计算和写入一致。
    $queryBalance = "SELECT id, energy
        FROM energy_records
        WHERE user_uid = ? AND venue_id = ?
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE";
    $resultBalance = $database->query($queryBalance, [$uid, $venue_id]);
    if ($resultBalance === false) {
        throw new Exception('读取能量记录失败');
    }

    if (count($resultBalance) > 0) {
        $recordId = intval($resultBalance[0]['id']);
        $energy = floatval($resultBalance[0]['energy']);
    } else {
        // 不存在记录时初始化余额为 0。
        $insertSql = "INSERT INTO energy_records (user_uid, venue_id, energy) VALUES (?, ?, 0)";
        $insertResult = $database->query($insertSql, [$uid, $venue_id], true);
        if ($insertResult === false) {
            throw new Exception('初始化能量记录失败');
        }

        $resultBalance = $database->query($queryBalance, [$uid, $venue_id]);
        if ($resultBalance === false || count($resultBalance) === 0) {
            throw new Exception('读取初始化后的能量记录失败');
        }

        $recordId = intval($resultBalance[0]['id']);
        $energy = floatval($resultBalance[0]['energy']);
    }

    $newWallet = $amount + $energy;

    // 总能量上限：判断本次赠送后的余额。
    if ($newWallet > $totalLimit) {
        $database->rollBack();
        ret([
            'code'    => 1,
            'success' => false,
            'message' => '总能量超过限额，当前余额：' . $energy . '，本次充值：' . $amount . '，总上限：' . $totalLimit,
        ]);
    }

    // 只更新最新一条余额记录，避免历史重复记录被同时增加。
    $updateSql = "UPDATE energy_records SET energy = energy + ? WHERE id = ?";
    $updateResult = $database->query($updateSql, [$amount, $recordId], true);
    if ($updateResult === false) {
        throw new Exception('更新能量余额失败');
    }

    $balanceChangeSql = "INSERT INTO energy_changes
        (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change)
        VALUES (?, ?, ?, ?, ?, ?)";
    $reason = '代理充值，充值人员：' . $username . '，角色ID：' . $role_id;
    $balanceChangeResult = $database->query(
        $balanceChangeSql,
        [$uid, $venue_id, $amount, $newWallet, $reason, $energy],
        true
    );
    if ($balanceChangeResult === false) {
        throw new Exception('写入能量变动记录失败');
    }

    if ($shouldLimitDailyGiftCount) {
        $increaseCounterSql = "UPDATE venue_user_daily_energy_gift_counts
            SET gift_count = gift_count + 1
            WHERE venue_id = ? AND user_uid = ? AND gift_date = CURRENT_DATE()";
        $increaseCounterResult = $database->query($increaseCounterSql, [$venue_id, $uid], true);
        if ($increaseCounterResult === false) {
            throw new Exception('更新每日赠送次数失败');
        }
    }

    $database->commit();
} catch (Throwable $e) {
    $database->rollBack();
    error_log('recharges gift_energy failed: ' . $e->getMessage());
    ret([
        'code'    => 1,
        'success' => false,
        'message' => '能量赠送失败，请稍后重试',
    ]);
}

ret([
    'code'    => 0,
    'success' => true,
    'message' => $newWallet,
    'data'    => [
        'daily_limit' => $shouldLimitDailyGiftCount ? $dailyGiftCountLimit : null,
    ],
]);
