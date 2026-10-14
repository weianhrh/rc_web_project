<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function post_value($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function parse_venue_ids() {
    $raw = $_POST['venue_ids'] ?? '[]';
    $ids = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($ids)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    })));
    return $ids;
}

function placeholders($count) {
    return implode(',', array_fill(0, $count, '?'));
}

function venue_rows(Database $database, array $venueIds) {
    if (!$venueIds) return [];
    return $database->query(
        'SELECT v.id, v.venue_name,
                COALESCE((SELECT au.uid FROM admin_users au
                          WHERE au.venue_id = v.id AND au.uid IS NOT NULL
                          ORDER BY (au.account_type = \'single\') DESC, au.id ASC LIMIT 1), 0) AS venue_uid
         FROM venues v WHERE v.id IN (' . placeholders(count($venueIds)) . ')',
        $venueIds
    ) ?: [];
}

function validate_payload(Database $database, $currentMcnId = 0) {
    $name = post_value('mcn_name');
    $username = post_value('username');
    $password = (string)($_POST['password'] ?? '');
    $venueIds = parse_venue_ids();
    $defaultVenueId = (int)($_POST['default_venue_id'] ?? 0);

    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($nameLength < 2 || $nameLength > 100) respond(1004, 'MCN名称长度应为2至100个字符');
    if (!preg_match('/^[A-Za-z0-9_@.\-]{3,50}$/', $username)) respond(1004, '登录账号需为3至50位字母、数字或常用符号');
    if (!$venueIds) respond(1004, '请至少绑定一个场地');
    if (!in_array($defaultVenueId, $venueIds, true)) respond(1004, '默认场地必须属于已选场地');

    $venueRows = venue_rows($database, $venueIds);
    if (count($venueRows) !== count($venueIds)) respond(1004, '包含不存在的场地');
    foreach ($venueRows as $venueRow) {
        if ((int)$venueRow['venue_uid'] <= 0) {
            respond(1004, '场地' . $venueRow['id'] . '尚未绑定加盟商UID，不能加入MCN');
        }
    }

    $params = $venueIds;
    $sql = 'SELECT mv.venue_id, mo.mcn_name FROM mcn_venues mv
            INNER JOIN mcn_organizations mo ON mo.id = mv.mcn_id
            WHERE mv.status = 1 AND mv.venue_id IN (' . placeholders(count($venueIds)) . ')';
    if ($currentMcnId > 0) {
        $sql .= ' AND mv.mcn_id <> ?';
        $params[] = $currentMcnId;
    }
    $conflicts = $database->query($sql, $params) ?: [];
    if ($conflicts) {
        respond(1005, '场地' . $conflicts[0]['venue_id'] . '已绑定到MCN：' . $conflicts[0]['mcn_name']);
    }

    return [$name, $username, $password, $venueIds, $defaultVenueId, $venueRows];
}

$database = new Database();

try {
    $token = $_COOKIE['session_token'] ?? null;
    $user = $token ? $database->getUserBySessionToken($token) : null;
    if (!$user) respond(1001, '用户未登录或会话已过期');
    if ((int)$user['role_id'] !== 1) respond(1003, '仅超级管理员可以管理MCN机构');

    $action = post_value('action', 'list');

    if ($action === 'list') {
        $organizations = $database->query(
            "SELECT mo.id, mo.mcn_name, mo.status, mo.created_at, mo.updated_at,
                    au.id AS admin_user_id, au.username, au.venue_id AS default_venue_id, au.uid
             FROM mcn_organizations mo
             LEFT JOIN admin_users au ON au.mcn_id = mo.id AND au.account_type = 'mcn'
             ORDER BY mo.id DESC"
        ) ?: [];
        $bindings = $database->query(
            "SELECT mv.mcn_id, mv.venue_id, mv.venue_uid, mv.is_default, mv.status, v.venue_name
             FROM mcn_venues mv INNER JOIN venues v ON v.id = mv.venue_id
             ORDER BY mv.mcn_id, mv.is_default DESC, mv.venue_id"
        ) ?: [];
        $byMcn = [];
        foreach ($bindings as $binding) {
            $binding['venue_id'] = (int)$binding['venue_id'];
            $binding['venue_uid'] = (int)$binding['venue_uid'];
            $binding['is_default'] = (int)$binding['is_default'];
            $byMcn[(int)$binding['mcn_id']][] = $binding;
        }
        foreach ($organizations as &$organization) {
            $organization['id'] = (int)$organization['id'];
            $organization['status'] = (int)$organization['status'];
            $organization['admin_user_id'] = (int)$organization['admin_user_id'];
            $organization['default_venue_id'] = (int)$organization['default_venue_id'];
            $organization['venues'] = $byMcn[$organization['id']] ?? [];
        }
        unset($organization);

        $venues = $database->query(
            "SELECT v.id, v.venue_name,
                    COALESCE((SELECT au.uid FROM admin_users au
                              WHERE au.venue_id = v.id AND au.uid IS NOT NULL
                              ORDER BY (au.account_type = 'single') DESC, au.id ASC LIMIT 1), 0) AS venue_uid,
                    mv.mcn_id, mo.mcn_name
             FROM venues v
             LEFT JOIN mcn_venues mv ON mv.venue_id = v.id AND mv.status = 1
             LEFT JOIN mcn_organizations mo ON mo.id = mv.mcn_id
             ORDER BY v.id ASC"
        ) ?: [];
        respond(0, 'ok', ['organizations' => $organizations, 'venues' => $venues]);
    }

    if ($action === 'create') {
        list($name, $username, $password, $venueIds, $defaultVenueId, $venueRows) = validate_payload($database);
        if (strlen($password) < 8) respond(1004, '初始密码不能少于8位');
        if ($database->query('SELECT id FROM admin_users WHERE username = ? LIMIT 1', [$username])) {
            respond(1005, '登录账号已存在');
        }

        $venueMap = [];
        foreach ($venueRows as $row) $venueMap[(int)$row['id']] = $row;
        $defaultVenue = $venueMap[$defaultVenueId];
        $conn = $database->getConnection();
        $database->beginTransaction();
        try {
            $stmt = $conn->prepare('INSERT INTO mcn_organizations (mcn_name, status) VALUES (?, 1)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $mcnId = (int)$stmt->insert_id;
            $stmt->close();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $roleId = 3;
            $accountType = 'mcn';
            $defaultVenueName = $defaultVenue['venue_name'];
            $defaultVenueUid = (int)$defaultVenue['venue_uid'];
            $stmt = $conn->prepare('INSERT INTO admin_users
                (username,password,role_id,venue_name,venue_id,uid,account_type,mcn_id,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->bind_param('ssisiisi', $username, $hash, $roleId, $defaultVenueName, $defaultVenueId, $defaultVenueUid, $accountType, $mcnId);
            $stmt->execute();
            $adminUserId = (int)$stmt->insert_id;
            $stmt->close();

            $mcnStmt = $conn->prepare('INSERT INTO mcn_venues (mcn_id,venue_id,venue_uid,is_default,status) VALUES (?,?,?,?,1)');
            $adminStmt = $conn->prepare('INSERT INTO admin_user_venues (admin_user_id,venue_id,is_default,status) VALUES (?,?,?,1)');
            foreach ($venueIds as $venueId) {
                $venueUid = (int)$venueMap[$venueId]['venue_uid'];
                $isDefault = $venueId === $defaultVenueId ? 1 : 0;
                $mcnStmt->bind_param('iiii', $mcnId, $venueId, $venueUid, $isDefault);
                $mcnStmt->execute();
                $adminStmt->bind_param('iii', $adminUserId, $venueId, $isDefault);
                $adminStmt->execute();
            }
            $mcnStmt->close();
            $adminStmt->close();
            $database->commit();
            respond(0, 'MCN机构创建成功', ['mcn_id' => $mcnId, 'admin_user_id' => $adminUserId]);
        } catch (Throwable $e) {
            $database->rollBack();
            throw $e;
        }
    }

    if ($action === 'update') {
        $mcnId = (int)($_POST['mcn_id'] ?? 0);
        $adminUserId = (int)($_POST['admin_user_id'] ?? 0);
        if ($mcnId <= 0 || $adminUserId <= 0) respond(1004, '缺少MCN或账号ID');
        $accountRows = $database->query(
            "SELECT id FROM admin_users WHERE id = ? AND mcn_id = ? AND account_type = 'mcn' LIMIT 1",
            [$adminUserId, $mcnId]
        );
        if (!$accountRows) respond(1004, 'MCN账号不存在');

        list($name, $username, $password, $venueIds, $defaultVenueId, $venueRows) = validate_payload($database, $mcnId);
        $sameUsername = $database->query('SELECT id FROM admin_users WHERE username = ? AND id <> ? LIMIT 1', [$username, $adminUserId]);
        if ($sameUsername) respond(1005, '登录账号已存在');
        if ($password !== '' && strlen($password) < 8) respond(1004, '新密码不能少于8位');

        $venueMap = [];
        foreach ($venueRows as $row) $venueMap[(int)$row['id']] = $row;
        $defaultVenue = $venueMap[$defaultVenueId];
        $conn = $database->getConnection();
        $database->beginTransaction();
        try {
            $database->query('UPDATE mcn_organizations SET mcn_name = ?, updated_at = NOW() WHERE id = ?', [$name, $mcnId], true);
            if ($password !== '') {
                $database->query(
                    "UPDATE admin_users SET username=?, password=?, role_id=3, venue_name=?, venue_id=?, uid=?, account_type='mcn', mcn_id=?, updated_at=NOW() WHERE id=?",
                    [$username, password_hash($password, PASSWORD_DEFAULT), $defaultVenue['venue_name'], $defaultVenueId, (int)$defaultVenue['venue_uid'], $mcnId, $adminUserId],
                    true
                );
            } else {
                $database->query(
                    "UPDATE admin_users SET username=?, role_id=3, venue_name=?, venue_id=?, uid=?, account_type='mcn', mcn_id=?, updated_at=NOW() WHERE id=?",
                    [$username, $defaultVenue['venue_name'], $defaultVenueId, (int)$defaultVenue['venue_uid'], $mcnId, $adminUserId],
                    true
                );
            }
            $database->query('DELETE FROM mcn_venues WHERE mcn_id = ?', [$mcnId], true);
            $database->query('DELETE FROM admin_user_venues WHERE admin_user_id = ?', [$adminUserId], true);

            $mcnStmt = $conn->prepare('INSERT INTO mcn_venues (mcn_id,venue_id,venue_uid,is_default,status) VALUES (?,?,?,?,1)');
            $adminStmt = $conn->prepare('INSERT INTO admin_user_venues (admin_user_id,venue_id,is_default,status) VALUES (?,?,?,1)');
            foreach ($venueIds as $venueId) {
                $venueUid = (int)$venueMap[$venueId]['venue_uid'];
                $isDefault = $venueId === $defaultVenueId ? 1 : 0;
                $mcnStmt->bind_param('iiii', $mcnId, $venueId, $venueUid, $isDefault);
                $mcnStmt->execute();
                $adminStmt->bind_param('iii', $adminUserId, $venueId, $isDefault);
                $adminStmt->execute();
            }
            $mcnStmt->close();
            $adminStmt->close();
            $database->commit();
            respond(0, 'MCN机构更新成功');
        } catch (Throwable $e) {
            $database->rollBack();
            throw $e;
        }
    }

    if ($action === 'toggle_status') {
        $mcnId = (int)($_POST['mcn_id'] ?? 0);
        $status = (int)($_POST['status'] ?? -1);
        if ($mcnId <= 0 || !in_array($status, [0, 1], true)) respond(1004, '参数错误');
        $database->query('UPDATE mcn_organizations SET status=?, updated_at=NOW() WHERE id=?', [$status, $mcnId], true);
        if ($status === 0) {
            $database->query("UPDATE admin_users SET session_token=NULL WHERE mcn_id=? AND account_type='mcn'", [$mcnId], true);
        }
        respond(0, $status ? '机构已启用' : '机构已停用');
    }

    respond(1004, '不支持的操作');
} catch (Throwable $e) {
    error_log('MCNManagement error: ' . $e->getMessage());
    respond(500, '服务器内部错误');
} finally {
    $database->close();
}
