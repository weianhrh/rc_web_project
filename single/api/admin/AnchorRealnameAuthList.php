<?php
require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function columnExists($database, $table, $column)
{
    $rows = $database->query("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
    return $rows && count($rows) > 0;
}

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? '';

if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !isset($user['role_id'])) {
    jsonOut(1001, '用户未登录或无权访问');
}

$role_id = (int)$user['role_id'];
$loginVenueId = (int)($user['venue_id'] ?? 0);
$operatorUid = (int)($user['uid'] ?? $user['id'] ?? 0);

/**
 * 管理员角色：
 * 这里先兼容 role_id 0 / 1 / 2。
 * 如果你后台只有 role_id == 1 是管理员，把这里改成：
 * $isAdmin = ($role_id === 1);
 */
$isAdmin = in_array($role_id, [0, 1, 2], true);

$action = $_REQUEST['action'] ?? 'list';

/**
 * 解绑认证记录：
 * 不删除旧认证信息，只把当前生效场地 venue_id 置为 0。
 * 同时把对应用户 users.streamer_venue 置为 0，方便后续重新认证/重新分配场地。
 * 如果表里已经加了 old_venue_id / unbound_at / unbound_by 字段，会同时记录解绑前场地和操作信息。
 */
if ($action === 'unbind') {
    if (!$isAdmin) {
        jsonOut(403, '仅管理员可以解绑认证记录');
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        jsonOut(400, '缺少记录ID');
    }

    $debugLogFile = __DIR__ . '/anchor_unbind_debug.log';

    function unbindLog($message, $context = [])
    {
        global $debugLogFile;
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $line .= PHP_EOL;

        if (@file_put_contents($debugLogFile, $line, FILE_APPEND) === false) {
            @file_put_contents(sys_get_temp_dir() . '/anchor_unbind_debug.log', $line, FILE_APPEND);
        }
    }

    function bindParamsForStmt($stmt, $types, &$params)
    {
        if (empty($params)) {
            return true;
        }

        $refs = [];
        $refs[] = $types;
        foreach ($params as $k => &$v) {
            $refs[] = &$v;
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    function fetchOneAssoc($conn, $sql, $params = [], $types = '')
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            unbindLog('SELECT prepare失败', [
                'sql' => $sql,
                'errno' => $conn->errno,
                'error' => $conn->error
            ]);
            return [false, null, $conn->error];
        }

        if (!empty($params)) {
            if ($types === '') {
                $types = str_repeat('s', count($params));
            }
            bindParamsForStmt($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            unbindLog('SELECT execute失败', [
                'sql' => $sql,
                'params' => $params,
                'errno' => $stmt->errno,
                'error' => $err
            ]);
            $stmt->close();
            return [false, null, $err];
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return [true, $row, null];
    }

    function executeWrite($conn, $sql, $params = [], $types = '')
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $err = $conn->error;
            unbindLog('WRITE prepare失败', [
                'sql' => $sql,
                'params' => $params,
                'errno' => $conn->errno,
                'error' => $err
            ]);
            return [false, -1, $err];
        }

        if (!empty($params)) {
            if ($types === '') {
                $types = str_repeat('s', count($params));
            }
            bindParamsForStmt($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            unbindLog('WRITE execute失败', [
                'sql' => $sql,
                'params' => $params,
                'errno' => $stmt->errno,
                'error' => $err
            ]);
            $stmt->close();
            return [false, -1, $err];
        }

        $affected = $stmt->affected_rows;
        unbindLog('WRITE execute成功', [
            'sql' => $sql,
            'params' => $params,
            'affected_rows' => $affected
        ]);
        $stmt->close();

        return [true, $affected, null];
    }

    function getColumnInfo($conn, $table, $column)
    {
        /**
         * v7 修复：不要用 SHOW COLUMNS ... LIKE ? 做字段检测。
         * 部分 MySQL/MariaDB + mysqli 环境下 SHOW 语句配 prepared 占位符会拿不到结果，
         * 导致明明已经有 old_venue_id / unbound_at / unbound_by，却被误判为缺字段。
         * 改为查 INFORMATION_SCHEMA.COLUMNS。
         */
        $sql = "
            SELECT
                COLUMN_NAME AS Field,
                COLUMN_TYPE AS Type,
                IS_NULLABLE AS `Null`,
                COLUMN_DEFAULT AS `Default`,
                COLUMN_KEY AS `Key`,
                EXTRA AS Extra,
                COLUMN_COMMENT AS Comment
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ";

        [$ok, $row, $err] = fetchOneAssoc($conn, $sql, [$table, $column], 'ss');

        if (!$ok) {
            unbindLog('字段检测失败', [
                'table' => $table,
                'column' => $column,
                'error' => $err
            ]);
            return null;
        }

        return $row ?: null;
    }

    try {
        $conn = $database->getConnection();
        $conn->begin_transaction();

        unbindLog('开始解绑', ['id' => $id]);

        [$ok, $record, $err] = fetchOneAssoc(
            $conn,
            "SELECT id, uid, venue_id, aliyun_passed FROM anchor_realname_auth WHERE id = ? LIMIT 1 FOR UPDATE",
            [$id],
            'i'
        );

        if (!$ok) {
            $conn->rollback();
            jsonOut(500, '解绑失败：查询认证记录失败：' . $err);
        }

        if (!$record) {
            $conn->rollback();
            jsonOut(404, '记录不存在');
        }

        $recordUid = (int)($record['uid'] ?? 0);
        $currentVenueId = (int)($record['venue_id'] ?? 0);
        $aliyunPassed = strtoupper(trim((string)($record['aliyun_passed'] ?? '')));

        unbindLog('锁定认证记录', [
            'id' => $id,
            'uid' => $recordUid,
            'venue_id' => $currentVenueId,
            'aliyun_passed' => $aliyunPassed
        ]);

        if ($aliyunPassed !== 'T') {
            $conn->rollback();
            jsonOut(400, '只能解绑认证通过记录');
        }

        if ($currentVenueId <= 0) {
            $conn->rollback();
            jsonOut(400, '该认证记录已经是解绑状态');
        }

        $venueColumn = getColumnInfo($conn, 'anchor_realname_auth', 'venue_id');
        $venueNullable = strtoupper((string)($venueColumn['Null'] ?? 'YES')) === 'YES';

        /**
         * v7 强制记录解绑信息：
         * 之前 v5 为了兼容老表，字段不存在时会跳过 old_venue_id / unbound_at / unbound_by。
         * 现在你的表已经有这 3 个字段，所以解绑必须同时写入，避免只释放 venue_id 却没留痕。v7 已修复字段检测误判。
         */
        $requiredCols = ['old_venue_id', 'unbound_at', 'unbound_by'];
        $missingCols = [];
        foreach ($requiredCols as $colName) {
            if (!getColumnInfo($conn, 'anchor_realname_auth', $colName)) {
                $missingCols[] = $colName;
            }
        }
        if (!empty($missingCols)) {
            $conn->rollback();
            jsonOut(500, '解绑失败：缺少解绑记录字段 ' . implode(',', $missingCols) . '，请先执行补字段SQL');
        }

        /**
         * 如果 anchor_realname_auth.venue_id 允许 NULL，优先置 NULL。
         * 如果不允许 NULL，才置 0。
         */
        $unbindVenueSqlValue = $venueNullable ? 'NULL' : '0';

        $hasUpdatedAt = getColumnInfo($conn, 'anchor_realname_auth', 'updated_at') ? true : false;

        $setParts = [];
        $params = [];
        $types = '';

        // 只在 old_venue_id 为空时写入解绑前场地，避免重复点解绑覆盖历史。
        $setParts[] = "old_venue_id = CASE WHEN old_venue_id IS NULL OR old_venue_id = 0 THEN ? ELSE old_venue_id END";
        $params[] = $currentVenueId;
        $types .= 'i';

        $setParts[] = "venue_id = {$unbindVenueSqlValue}";
        $setParts[] = "unbound_at = NOW()";
        $setParts[] = "unbound_by = ?";
        $params[] = $operatorUid;
        $types .= 'i';

        if ($hasUpdatedAt) {
            $setParts[] = "updated_at = NOW()";
        }

        $params[] = $id;
        $types .= 'i';

        $unbindSql = "UPDATE anchor_realname_auth SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1";
        [$updateOk, $authAffected, $updateErr] = executeWrite($conn, $unbindSql, $params, $types);

        if (!$updateOk) {
            $conn->rollback();
            jsonOut(500, '解绑失败：认证表 UPDATE 执行失败：' . $updateErr, [
                'log' => $debugLogFile,
                'try_value' => $unbindVenueSqlValue,
                'id' => $id,
                'uid' => $recordUid,
                'old_venue_id' => $currentVenueId
            ]);
        }

        [$verifyOk, $verifyRecord, $verifyErr] = fetchOneAssoc(
            $conn,
            "SELECT id, uid, venue_id, old_venue_id, unbound_at, unbound_by FROM anchor_realname_auth WHERE id = ? LIMIT 1",
            [$id],
            'i'
        );

        if (!$verifyOk || !$verifyRecord) {
            $conn->rollback();
            jsonOut(500, '解绑失败：UPDATE 后校验认证记录失败：' . $verifyErr);
        }

        $afterVenueRaw = $verifyRecord['venue_id'] ?? null;
        $isUnbound = ($afterVenueRaw === null || $afterVenueRaw === '' || (int)$afterVenueRaw === 0);

        if (!$isUnbound) {
            $conn->rollback();
            jsonOut(500, '解绑失败：UPDATE 已执行但 venue_id 仍未释放，可能有触发器/旧代码回写', [
                'id' => $id,
                'uid' => $recordUid,
                'before_venue_id' => $currentVenueId,
                'after_venue_id' => $afterVenueRaw,
                'auth_update_return' => $authAffected,
                'log' => $debugLogFile
            ]);
        }

        [$userOk, $userClearAffected, $userErr] = executeWrite(
            $conn,
            "UPDATE users SET streamer_venue = 0 WHERE uid = ? AND streamer_venue = ? LIMIT 1",
            [$recordUid, $currentVenueId],
            'ii'
        );

        if (!$userOk) {
            $conn->rollback();
            jsonOut(500, '认证记录已解绑，但清空 users.streamer_venue 失败，已回滚：' . $userErr, [
                'log' => $debugLogFile
            ]);
        }

        $conn->commit();

        unbindLog('解绑成功', [
            'id' => $id,
            'uid' => $recordUid,
            'old_venue_id' => $currentVenueId,
            'new_venue_id' => $afterVenueRaw,
            'db_old_venue_id' => $verifyRecord['old_venue_id'] ?? null,
            'db_unbound_at' => $verifyRecord['unbound_at'] ?? null,
            'db_unbound_by' => $verifyRecord['unbound_by'] ?? null,
            'users_streamer_venue_cleared' => $userClearAffected
        ]);
    } catch (Throwable $e) {
        if (isset($conn) && $conn) {
            $conn->rollback();
        } else {
            $database->rollBack();
        }
        unbindLog('解绑异常', [
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        jsonOut(500, '解绑异常：' . $e->getMessage(), [
            'log' => $debugLogFile
        ]);
    }

    jsonOut(0, '解绑成功', [
        'id' => $id,
        'uid' => $recordUid,
        'old_venue_id' => $currentVenueId,
        'venue_id' => $afterVenueRaw,
        'db_old_venue_id' => $verifyRecord['old_venue_id'] ?? null,
        'db_unbound_at' => $verifyRecord['unbound_at'] ?? null,
        'db_unbound_by' => $verifyRecord['unbound_by'] ?? null,
        'auth_update_return' => (int)$authAffected,
        'users_streamer_venue_cleared' => (int)$userClearAffected
    ]);
}

/**
 * 删除认证记录
 * 注意：a.id 不展示在页面上，但删除时用它精准定位。
 * 不推荐日常使用；需要保留旧信息时，请使用 unbind。
 */
if ($action === 'delete') {
    if (!$isAdmin) {
        jsonOut(403, '仅管理员可以删除认证记录');
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        jsonOut(400, '缺少记录ID');
    }

    $checkSql = "
        SELECT id, uid, venue_id, aliyun_passed
        FROM anchor_realname_auth
        WHERE id = ?
        LIMIT 1
    ";

    $checkRows = $database->query($checkSql, [$id]);

    if (!$checkRows) {
        jsonOut(404, '记录不存在');
    }

    $record = $checkRows[0];

    if (($record['aliyun_passed'] ?? '') !== 'T') {
        jsonOut(400, '只能删除认证通过记录');
    }

    $deleteSql = "
        DELETE FROM anchor_realname_auth
        WHERE id = ?
          AND aliyun_passed = 'T'
        LIMIT 1
    ";

    $ok = $database->query($deleteSql, [$id], true);

    if ($ok === false || (int)$ok < 1) {
        jsonOut(500, '删除失败或记录状态已变化');
    }

    jsonOut(0, '删除成功');
}

/**
 * 列表查询
 * status=active  ：默认当前仍绑定场地的认证记录，显示“解绑”按钮。
 * status=unbound ：已解绑历史记录，按 old_venue_id 展示解绑前场地，不再显示解绑按钮。
 */
if ($action !== 'list') {
    jsonOut(400, '无效操作');
}

$page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));

$pageSize = (int)($_GET['page_size'] ?? $_POST['page_size'] ?? 20);
$pageSize = max(10, min($pageSize, 100));

$offset = ($page - 1) * $pageSize;

$venueId = (int)($_GET['venue_id'] ?? $_POST['venue_id'] ?? 0);
$keyword = trim($_GET['keyword'] ?? $_POST['keyword'] ?? '');
$status = trim($_GET['status'] ?? $_POST['status'] ?? 'active');
$status = ($status === 'unbound') ? 'unbound' : 'active';

$where = [];
$params = [];

$where[] = "a.aliyun_passed = 'T'";

/**
 * 当前认证：venue_id 还有值。
 * 已解绑：venue_id 已释放为 NULL 或 0，解绑前场地从 old_venue_id 取。
 */
if ($status === 'unbound') {
    $where[] = "(a.venue_id IS NULL OR a.venue_id = 0)";
    $venueField = 'a.old_venue_id';
    $venueJoin = 'v.id = a.old_venue_id';
    $orderSql = "a.unbound_at DESC, a.id DESC";
} else {
    $where[] = "a.venue_id IS NOT NULL";
    $where[] = "a.venue_id > 0";
    $venueField = 'a.venue_id';
    $venueJoin = 'v.id = a.venue_id';
    $orderSql = "a.certify_finished_at DESC, a.id DESC";
}

/**
 * 非管理员只能看自己场地。
 * 当前认证按 venue_id 限制；已解绑记录按 old_venue_id 限制。
 */
if (!$isAdmin) {
    if ($loginVenueId <= 0) {
        jsonOut(403, '当前账号未绑定场地，无法查看认证记录');
    }

    $where[] = "{$venueField} = ?";
    $params[] = $loginVenueId;
} else {
    /**
     * 管理员可以按场地筛选。
     * 当前认证筛选当前场地；解绑记录筛选解绑前场地。
     */
    if ($venueId > 0) {
        $where[] = "{$venueField} = ?";
        $params[] = $venueId;
    }
}

/**
 * 搜索：
 * 当前认证支持 uid / 当前 venue_id / old_venue_id / 场地名称。
 * 解绑记录支持 uid / 解绑前 old_venue_id / 当前 venue_id(NULL/0) / 场地名称。
 */
if ($keyword !== '') {
    $like = '%' . $keyword . '%';

    $where[] = "(
        CAST(a.uid AS CHAR) LIKE ?
        OR CAST(a.venue_id AS CHAR) LIKE ?
        OR CAST(a.old_venue_id AS CHAR) LIKE ?
        OR v.venue_name LIKE ?
    )";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*) AS total
    FROM anchor_realname_auth a
    LEFT JOIN venues v ON {$venueJoin}
    WHERE {$whereSql}
";

$countRows = $database->query($countSql, $params);
$total = (int)($countRows[0]['total'] ?? 0);
$totalPage = max(1, (int)ceil($total / $pageSize));

$listSql = "
    SELECT
        a.id,
        a.uid,
        a.venue_id,
        a.old_venue_id,
        v.venue_name,
        a.aliyun_passed,
        a.certify_finished_at,
        a.unbound_at,
        a.unbound_by
    FROM anchor_realname_auth a
    LEFT JOIN venues v ON {$venueJoin}
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT {$pageSize} OFFSET {$offset}
";

$rows = $database->query($listSql, $params);

jsonOut(0, 'success', [
    'list' => $rows ?: [],
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'total_page' => $totalPage,
    'status' => $status,
    'is_admin' => $isAdmin ? 1 : 0,
    'login_venue_id' => $loginVenueId
]);

$database->close();
?>
