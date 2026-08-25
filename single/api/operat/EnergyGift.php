<?php

// 引入数据库连接类
require_once '../Database.php'; 
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 创建数据库连接实例
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 根据 session_token 获取用户信息
$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}
$username = $user['username'];
// 处理不同请求
$action = $_POST['action'] ?? null;

if ($action === 'gift_energy') {
    // 获取必要的参数
    $userId = $_POST['user_id'] ?? null;
    $venueId = $_POST['venue_id'] ?? null;
    $energyAmount = $_POST['energy_amount'] ?? null;

    if (!$userId || !$venueId || !$energyAmount) {
        echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
        exit;
    }

    // 检查用户是否存在
    $checkUserSql = "SELECT * FROM `users` WHERE `uid` = ?";
    $userResult = $database->query($checkUserSql, [$userId]);

    if (!$userResult) {
        echo json_encode(['code' => 1003, 'msg' => '用户不存在', 'data' => []]);
        exit;
    }

    // 检查用户在该场地是否有可用能量记录
    $checkEnergySql = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ?";
    $energyResult = $database->query($checkEnergySql, [$userId, $venueId]);

    if ($energyResult) {
        $updateSql = "UPDATE energy_records SET energy = energy + ? WHERE user_uid = ? AND venue_id = ?";
        $database->query($updateSql, [$energyAmount, $userId, $venueId], true);
        
                        // 记录能量变动
        $current_energy = $energyResult[0]['energy'] ?? 0;
        $balance_after_change = $current_energy + floatval($energyAmount);
        $reason = '代理充值，充值人员：'.$username;
        $balanceChangeParams = [$userId, $venueId, $energyAmount, $balance_after_change, $reason, $current_energy];
        $balanceChangeSql = "INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )";
        $database->query($balanceChangeSql, $balanceChangeParams, true);
    } else {
        $insertSql = "INSERT INTO energy_records (user_uid, venue_id, energy) VALUES (?, ?, ?)";
        $database->query($insertSql, [$userId, $venueId, $energyAmount], true);
        
                        // 记录能量变动
        $current_energy = $energyResult[0]['energy'] ?? 0;
        $balance_after_change = $current_energy + floatval($energyAmount);
        $reason = '代理充值，充值人员：'.$username;
        $balanceChangeParams = [$userId, $venueId, $energyAmount, $balance_after_change, $reason, $current_energy];
        $balanceChangeSql = "INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )";
        $database->query($balanceChangeSql, $balanceChangeParams, true);
    }

    // 查询更新后的记录
    $selectSql = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ?";
    $newEnergyResult = $database->query($selectSql, [$userId, $venueId]);
    $newEnergy = $newEnergyResult[0]['energy'];

    echo json_encode([
        'code' => 0,
        'msg' => '赠送能量成功',
        'data' => [
            'userId' => $userId,
            'venue_id' => $venueId,
            'energyAmount' => $newEnergy
        ]
    ]);
} elseif ($action === 'query_energy') {
    // 获取必要的参数
    $userId = $_POST['user_id'] ?? null;
    $venueId = $_POST['venue_id'] ?? null;

    if (!$userId || !$venueId) {
        echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
        exit;
    }

    // 检查用户是否存在
    $checkUserSql = "SELECT * FROM `users` WHERE `uid` = ?";
    $userResult = $database->query($checkUserSql, [$userId]);

    if (!$userResult) {
        echo json_encode(['code' => 1003, 'msg' => '用户不存在', 'data' => []]);
        exit;
    }

    // 查询用户在该场地的能量值
    $querySql = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ?";
    $result = $database->query($querySql, [$userId, $venueId]);

    echo json_encode([
        'code' => 0,
        'msg' => '查询成功',
        'data' => [
            'energy' => $result ? $result[0]['energy'] : 0
        ]
    ]);
} elseif ($action === 'get_venues') {
    // 查询所有场地信息
    $sql = "SELECT id, venue_name FROM venues";
    $result = $database->query($sql);

    echo json_encode(['code' => 0, 'msg' => '成功', 'data' => $result]);
} elseif ($action === 'clear_energy') {
    // 获取必要的参数
    $userId = $_POST['user_id'] ?? null;
    $venueId = $_POST['venue_id'] ?? null;
    $amountProvided = array_key_exists('energy_amount', $_POST);
    $rawEnergyAmount = $amountProvided ? $_POST['energy_amount'] : null;
    $energyAmount = null;

    if (!$userId || !$venueId) {
        echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
        exit;
    }

    // 指定清除数量时，只允许大于 0 的有限数字，禁止减负数反向增加能量。
    if ($amountProvided) {
        if (!is_scalar($rawEnergyAmount)
            || trim((string)$rawEnergyAmount) === ''
            || !is_numeric($rawEnergyAmount)) {
            echo json_encode(['code' => 1002, 'msg' => '清除能量必须是大于0的数字', 'data' => []]);
            exit;
        }

        $energyAmount = (float)$rawEnergyAmount;
        if (!is_finite($energyAmount) || $energyAmount <= 0) {
            echo json_encode(['code' => 1002, 'msg' => '清除能量必须是大于0的数字', 'data' => []]);
            exit;
        }
    }

    // 检查用户是否存在
    $checkUserSql = "SELECT * FROM `users` WHERE `uid` = ?";
    $userResult = $database->query($checkUserSql, [$userId]);

    if (!$userResult) {
        echo json_encode(['code' => 1003, 'msg' => '用户不存在', 'data' => []]);
        exit;
    }

    // 清除余额和写入变动记录必须在同一个事务中完成，避免余额已变更但日志缺失。
    $database->beginTransaction();
    try {
        $querySql = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? FOR UPDATE";
        $result = $database->query($querySql, [$userId, $venueId]);
        if ($result === false) {
            throw new Exception('查询当前能量失败');
        }

        $currentEnergy = $result ? (float)$result[0]['energy'] : 0.0;
        $newEnergy = $amountProvided
            ? max(0, $currentEnergy - $energyAmount)
            : 0.0;

        if ($result) {
            $updateSql = "UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?";
            $updateResult = $database->query($updateSql, [$newEnergy, $userId, $venueId], true);
            if ($updateResult === false) {
                throw new Exception('更新能量失败');
            }
        }

        // 只记录实际发生的减少量；清除数量大于余额时，以实际余额为准。
        $removedEnergy = $currentEnergy - $newEnergy;
        if ($removedEnergy > 0) {
            $reason = '代理清除能量，操作人员：' . $username;
            $changeSql = "INSERT INTO energy_changes
                (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change)
                VALUES (?, ?, ?, ?, ?, ?)";
            $changeResult = $database->query(
                $changeSql,
                [$userId, $venueId, -$removedEnergy, $newEnergy, $reason, $currentEnergy],
                true
            );
            if ($changeResult === false) {
                throw new Exception('写入能量变动记录失败');
            }
        }

        $database->commit();
    } catch (Throwable $e) {
        $database->rollBack();
        error_log('clear_energy failed: ' . $e->getMessage());
        echo json_encode(['code' => 1005, 'msg' => '清除能量失败', 'data' => []]);
        exit;
    }

    echo json_encode([
        'code' => 0,
        'msg' => '清除能量成功',
        'data' => []
    ]);
} elseif ($action === 'get_gift_records') {

    $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $pageSize = isset($_POST['page_size']) ? max(1, min(100, intval($_POST['page_size']))) : 20;
    $offset   = ($page - 1) * $pageSize;

    $venueId  = $_POST['venue_id'] ?? null;
    $userId   = $_POST['user_id'] ?? null;

    $roleId   = $user['role_id'];
    $username = $user['username'];

    $where = " WHERE 1=1 ";
    $params = [];

    // ⭐ 只拿 "代理充值" 记录
    $where   .= " AND ec.reason LIKE ?";
    $params[] = "%代理充值%";

    // ⭐ 如果不是管理员，只能看自己充值的记录
    if ($roleId != 1) {
        $where   .= " AND ec.reason LIKE ?";
        $params[] = "%充值人员：" . $username . "%";
    }

    if (!empty($venueId)) {
        $where   .= " AND ec.venue_id = ?";
        $params[] = intval($venueId);
    }

    if (!empty($userId)) {
        $where   .= " AND ec.user_uid = ?";
        $params[] = intval($userId);
    }

    // COUNT
    $countSql = "SELECT COUNT(*) AS total
                 FROM energy_changes ec
                 LEFT JOIN admin_users au 
                        ON ec.venue_id = au.venue_id 
                       AND ec.user_uid = au.uid
                 $where";

    $countResult = $database->query($countSql, $params);
    $total = $countResult ? intval($countResult[0]['total']) : 0;

    // DATA
    $listSql = "SELECT 
                    ec.id,
                    ec.user_uid,
                    ec.venue_id,
                    ec.energy_change,
                    ec.balance_before_change,
                    ec.balance_after_change,
                    ec.reason,
                    ec.created_at,
                    au.username,
                    au.venue_name
                FROM energy_changes ec
                LEFT JOIN admin_users au 
                        ON ec.venue_id = au.venue_id 
                       AND ec.user_uid = au.uid
                $where
                ORDER BY ec.id DESC
                LIMIT $offset, $pageSize";

    $records = $database->query($listSql, $params);

    echo json_encode([
        'code' => 0,
        'msg'  => '查询成功',
        'data' => [
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'list'      => $records ?: []
        ]
    ]);
}
elseif ($action === 'gift_all_energy') {
    // 获取必要的参数
    $userId = $_POST['user_id'] ?? null;
    $energyAmount = $_POST['energy_amount'] ?? null;

    if (!$userId || !$energyAmount) {
        echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
        exit;
    }
    
    $energyAmount = floatval($energyAmount);
    if ($energyAmount <= 0) {
        echo json_encode(['code' => 1002, 'msg' => '赠送能量必须大于0', 'data' => []]);
        exit;
    }
    // 检查用户是否存在
    $checkUserSql = "SELECT * FROM `users` WHERE `uid` = ?";
    $userResult = $database->query($checkUserSql, [$userId]);
    if (!$userResult) {
        echo json_encode(['code' => 1003, 'msg' => '用户不存在', 'data' => []]);
        exit;
    }

    // 全场地赠送（存在则更新，不存在则插入）
    $insertSql = "INSERT INTO energy_records (user_uid, venue_id, energy)
                  SELECT ?, v.id, ?
                  FROM venues v
                  ON DUPLICATE KEY UPDATE energy = energy + VALUES(energy)";
    $database->query($insertSql, [$userId, $energyAmount], true);

    // 记录能量变动日志
    // 注意：上面已经把 energy_records 加完了，所以 er.energy 是更新后的余额
    // 因此：变动前 = er.energy - 本次赠送能量；变动后 = er.energy
    $logSql = "INSERT INTO energy_changes (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change)
               SELECT ?, v.id, ?, IFNULL(er.energy,0), ?, IFNULL(er.energy,0) - ?
               FROM venues v
               LEFT JOIN energy_records er ON er.user_uid = ? AND er.venue_id = v.id";
    $reason = '代理充值全场地，充值人员：' . $username;
    $database->query($logSql, [$userId, $energyAmount, $reason, $energyAmount, $userId], true);
    echo json_encode(['code'=>0, 'msg'=>'全场地赠送成功', 'data'=>[]]);
}

elseif ($action === 'clear_all_energy') {
    $userId = $_POST['user_id'] ?? null;
    if (!$userId) {
        echo json_encode(['code'=>1002,'msg'=>'缺少用户ID','data'=>[]]);
        exit;
    }

    // 先按每个场地记录清零前余额，再统一清零；两步必须同时成功。
    $database->beginTransaction();
    try {
        $reason = '代理全场地清零，操作人员：' . $username;
        $logSql = "INSERT INTO energy_changes
            (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change)
            SELECT user_uid, venue_id, -energy, 0, ?, energy
            FROM energy_records
            WHERE user_uid = ?
              AND venue_id IN (SELECT id FROM venues)
              AND energy > 0";
        $logResult = $database->query($logSql, [$reason, $userId], true);
        if ($logResult === false) {
            throw new Exception('写入全场地清零记录失败');
        }

        $updateSql = "UPDATE energy_records SET energy = 0 WHERE user_uid = ? AND venue_id IN (SELECT id FROM venues)";
        $updateResult = $database->query($updateSql, [$userId], true);
        if ($updateResult === false) {
            throw new Exception('全场地清零失败');
        }

        $database->commit();
    } catch (Throwable $e) {
        $database->rollBack();
        error_log('clear_all_energy failed: ' . $e->getMessage());
        echo json_encode(['code'=>1005, 'msg'=>'全场地能量清零失败', 'data'=>[]]);
        exit;
    }

    echo json_encode(['code'=>0, 'msg'=>'全场地能量清零成功','data'=>[]]);
}
else {
    echo json_encode(['code' => 1004, 'msg' => '无效的请求操作', 'data' => []]);
}

?>
