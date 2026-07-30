<?php
// /single/api/voiceRoom/venueGuardianManage.php
// 场地守护查看：仅支持查看详情和禁用，不提供任何编辑能力。

require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bind_params_dynamic($stmt, $types, &$params) {
    if ($types === '' || empty($params)) {
        return;
    }

    $bindArgs = [$types];
    foreach ($params as $key => &$value) {
        $bindArgs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function fetch_one($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('数据库查询准备失败：' . $conn->error);
    }

    bind_params_dynamic($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('数据库查询失败：' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function fetch_all_rows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('数据库查询准备失败：' . $conn->error);
    }

    bind_params_dynamic($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('数据库查询失败：' . $error);
    }

    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function normalize_guardian_row($row) {
    if (!$row) {
        return null;
    }

    $isDisabled = (int)($row['is_disabled'] ?? 0);
    $expiresAt = (string)($row['expires_at'] ?? '');
    $isExpired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time();

    if ($isDisabled === 1) {
        $status = 'disabled';
        $statusText = '已禁用';
    } elseif ($isExpired) {
        $status = 'expired';
        $statusText = '已到期';
    } else {
        $status = 'active';
        $statusText = '守护中';
    }

    return [
        'id'                => (int)$row['id'],
        'venue_id'          => (int)$row['venue_id'],
        'venue_name'        => (string)($row['venue_name'] ?? ''),
        'uid'               => (int)$row['uid'],
        'total_paid_gold'   => (int)$row['total_paid_gold'],
        'opened_at'         => (string)$row['opened_at'],
        'last_renewed_at'   => (string)$row['last_renewed_at'],
        'expires_at'        => $expiresAt,
        'last_order_id'     => isset($row['last_order_id']) && $row['last_order_id'] !== null ? (int)$row['last_order_id'] : null,
        'last_welcome_at'   => (string)($row['last_welcome_at'] ?? ''),
        'is_disabled'       => $isDisabled,
        'status'            => $status,
        'status_text'       => $statusText,
        'created_at'        => (string)$row['created_at'],
        'updated_at'        => (string)$row['updated_at']
    ];
}

$database = new Database();
$conn = $database->getConnection();
$sessionToken = $_COOKIE['session_token'] ?? '';

if ($sessionToken === '') {
    json_out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($sessionToken);
if (!$user) {
    json_out(1001, '用户未登录或会话已过期');
}

$roleId = (int)($user['role_id'] ?? 0);
$userVenueId = (int)($user['venue_id'] ?? 0);
$isAdmin = in_array($roleId, [1, 2], true);
$isVenueRole = in_array($roleId, [3, 4], true);

if (!$isAdmin && !$isVenueRole) {
    json_out(1003, '当前账号无权访问场地守护查看');
}

if ($isVenueRole && $userVenueId <= 0) {
    json_out(1003, '当前账号未绑定场地，无法查看守护记录');
}

$action = trim((string)($_REQUEST['action'] ?? 'list'));

try {
    if ($action === 'disable') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_out(405, '请求方式不正确');
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_out(422, '守护记录ID不正确');
        }

        $scopeSql = '';
        $scopeTypes = 'i';
        $scopeParams = [$id];
        if ($isVenueRole) {
            $scopeSql = ' AND vg.venue_id = ?';
            $scopeTypes .= 'i';
            $scopeParams[] = $userVenueId;
        }

        $row = fetch_one(
            $conn,
            "SELECT vg.id, vg.venue_id, vg.uid, vg.is_disabled
             FROM venue_guardians vg
             WHERE vg.id = ?{$scopeSql}
             LIMIT 1",
            $scopeTypes,
            $scopeParams
        );

        if (!$row) {
            json_out(404, '守护记录不存在或不属于当前可管理场地');
        }

        if ((int)$row['is_disabled'] === 1) {
            json_out(0, '该守护记录已经禁用', [
                'id' => (int)$row['id'],
                'is_disabled' => 1
            ]);
        }

        $updateSql = 'UPDATE venue_guardians SET is_disabled = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        $updateTypes = 'i';
        $updateParams = [$id];
        if ($isVenueRole) {
            $updateSql .= ' AND venue_id = ?';
            $updateTypes .= 'i';
            $updateParams[] = $userVenueId;
        }

        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new Exception('准备禁用语句失败：' . $conn->error);
        }
        bind_params_dynamic($stmt, $updateTypes, $updateParams);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('禁用守护记录失败：' . $error);
        }
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows <= 0) {
            json_out(409, '禁用失败，记录可能已发生变化');
        }

        json_out(0, '禁用成功', [
            'id' => $id,
            'venue_id' => (int)$row['venue_id'],
            'uid' => (int)$row['uid'],
            'is_disabled' => 1
        ]);
    }

    if ($action === 'detail') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            json_out(422, '守护记录ID不正确');
        }

        $where = 'vg.id = ?';
        $types = 'i';
        $params = [$id];
        if ($isVenueRole) {
            $where .= ' AND vg.venue_id = ?';
            $types .= 'i';
            $params[] = $userVenueId;
        }

        $row = fetch_one(
            $conn,
            "SELECT vg.id, vg.venue_id, v.venue_name, vg.uid, vg.total_paid_gold,
                    vg.opened_at, vg.last_renewed_at, vg.expires_at, vg.last_order_id,
                    vg.last_welcome_at, vg.is_disabled, vg.created_at, vg.updated_at
             FROM venue_guardians vg
             LEFT JOIN venues v ON v.id = vg.venue_id
             WHERE {$where}
             LIMIT 1",
            $types,
            $params
        );

        if (!$row) {
            json_out(404, '守护记录不存在或不属于当前可查看场地');
        }

        json_out(0, '获取成功', [
            'guardian' => normalize_guardian_row($row),
            'role_id' => $roleId,
            'is_admin' => $isAdmin ? 1 : 0,
            'bound_venue_id' => $userVenueId
        ]);
    }

    if ($action !== 'list') {
        json_out(400, '不支持的操作');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(10, min(100, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $status = trim((string)($_GET['status'] ?? 'all'));
    if (!in_array($status, ['all', 'active', 'expired', 'disabled'], true)) {
        $status = 'all';
    }

    $selectedVenueId = null;
    if ($isVenueRole) {
        $selectedVenueId = $userVenueId;
    } else {
        $rawVenueId = trim((string)($_GET['venue_id'] ?? ''));
        if ($rawVenueId !== '' && $rawVenueId !== 'all') {
            $selectedVenueId = (int)$rawVenueId;
            if ($selectedVenueId <= 0) {
                $selectedVenueId = null;
            }
        }
    }

    $whereParts = ['1 = 1'];
    $types = '';
    $params = [];

    if ($selectedVenueId !== null) {
        $whereParts[] = 'vg.venue_id = ?';
        $types .= 'i';
        $params[] = $selectedVenueId;
    }

    if ($keyword !== '') {
        if (preg_match('/^\d+$/', $keyword)) {
            $whereParts[] = '(CAST(vg.uid AS CHAR) LIKE ? OR CAST(vg.id AS CHAR) LIKE ?)';
            $like = '%' . $keyword . '%';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        } else {
            $whereParts[] = 'v.venue_name LIKE ?';
            $types .= 's';
            $params[] = '%' . $keyword . '%';
        }
    }

    if ($status === 'active') {
        $whereParts[] = 'vg.is_disabled = 0 AND vg.expires_at > NOW()';
    } elseif ($status === 'expired') {
        $whereParts[] = 'vg.is_disabled = 0 AND vg.expires_at <= NOW()';
    } elseif ($status === 'disabled') {
        $whereParts[] = 'vg.is_disabled = 1';
    }

    $whereSql = implode(' AND ', $whereParts);

    $countRow = fetch_one(
        $conn,
        "SELECT COUNT(*) AS total
         FROM venue_guardians vg
         LEFT JOIN venues v ON v.id = vg.venue_id
         WHERE {$whereSql}",
        $types,
        $params
    );
    $total = (int)($countRow['total'] ?? 0);

    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $offset;
    $listParams[] = $pageSize;

    $rows = fetch_all_rows(
        $conn,
        "SELECT vg.id, vg.venue_id, v.venue_name, vg.uid, vg.total_paid_gold,
                vg.opened_at, vg.last_renewed_at, vg.expires_at, vg.last_order_id,
                vg.last_welcome_at, vg.is_disabled, vg.created_at, vg.updated_at
         FROM venue_guardians vg
         LEFT JOIN venues v ON v.id = vg.venue_id
         WHERE {$whereSql}
         ORDER BY vg.id DESC
         LIMIT ?, ?",
        $listTypes,
        $listParams
    );

    $list = array_map('normalize_guardian_row', $rows);

    $boundVenueName = '';
    if ($userVenueId > 0) {
        $venueRow = fetch_one($conn, 'SELECT venue_name FROM venues WHERE id = ? LIMIT 1', 'i', [$userVenueId]);
        $boundVenueName = (string)($venueRow['venue_name'] ?? '');
    }

    json_out(0, '查询成功', [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $total > 0 ? (int)ceil($total / $pageSize) : 1
        ],
        'role_id' => $roleId,
        'is_admin' => $isAdmin ? 1 : 0,
        'selected_venue_id' => $selectedVenueId,
        'bound_venue_id' => $userVenueId,
        'bound_venue_name' => $boundVenueName,
        'permissions' => [
            'can_view' => 1,
            'can_disable' => 1,
            'can_edit' => 0
        ]
    ]);
} catch (Throwable $e) {
    error_log('[venueGuardianManage] ' . $e->getMessage());
    json_out(500, '服务器处理失败：' . $e->getMessage());
}
