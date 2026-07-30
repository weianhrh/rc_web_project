<?php
// gift_detail.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '../Database.php';

function ok($data = [], $msg = 'ok') {
    echo json_encode([
        'ok' => 1,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function bad($msg, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'ok' => 0,
        'msg' => $msg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 登录校验 ----
$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) bad('未登录或缺少 session_token', 401);

$db = new Database();
$user = $db->getUserBySessionToken($token);
if (!$user) bad('登录已过期或 token 无效', 401);

// 只允许角色 1、2、3 访问
$role_id = (int)($user['role_id'] ?? 0);
if (!in_array($role_id, [1, 2, 3, 4], true)) {
    bad('无权访问礼物配置', 403);
}

$act = $_GET['act'] ?? 'list';
$giftScope = (($_GET['scope'] ?? '') === 'venue') ? 'venue' : 'global';
$giftTable = $giftScope === 'venue' ? 'venue_gift_detail' : 'gift_detail';

// ------- 工具函数 -------
function toInt($v, $default = 0) {
    if ($v === null || $v === '') return $default;
    return (int)$v;
}

function toFloat($v, $default = 0.0) {
    if ($v === null || $v === '') return $default;
    return (float)$v;
}

function to01($v, $default = 0) {
    if ($v === null || $v === '') return $default;
    return ((int)$v === 1) ? 1 : 0;
}
function to01DefaultOn($v) {
    if ($v === null || $v === '') return 1;
    return ((int)$v === 1) ? 1 : 0;
}
function strOrEmpty($v) {
    return trim((string)$v);
}

function strOrNull($v) {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function read_payload(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) return $arr;
        }
        return [];
    }
    return $_POST ?? [];
}

try {
    switch ($act) {
        case 'venues': {
            // 与“能量补偿”页面的 get_venues 保持相同的获取及排列方式
            $rows = $db->query("SELECT id, venue_name FROM venues");
            ok(['list' => $rows ?: []]);
        }

        // ============ 列表 ============
        case 'list': {
            $page     = max(1, toInt($_GET['page'] ?? 1));
            $pageSize = min(100, max(1, toInt($_GET['page_size'] ?? 20)));
            $offset   = ($page - 1) * $pageSize;
            $q        = trim($_GET['q'] ?? '');
            $onlyShow = $_GET['is_display'] ?? ''; // "", "0", "1"
            $venueId = toInt($_GET['venue_id'] ?? 0);
            $giftStatus = $_GET['gift_status'] ?? '';
            $broadcastScope = $_GET['is_broadcast_all'] ?? '';

            $where = ' WHERE 1=1 ';
            $params = [];

            if ($q !== '') {
                $where .= ' AND gd.gift_name LIKE CONCAT("%", ?, "%") ';
                $params[] = $q;
            }

            if ($onlyShow !== '' && ($onlyShow === '0' || $onlyShow === '1')) {
                $where .= ' AND gd.is_display = ? ';
                $params[] = $onlyShow;
            }
            if ($giftScope === 'venue' && $venueId > 0) {
                $where .= ' AND gd.venue_id = ? ';
                $params[] = $venueId;
            }
            if ($giftScope === 'venue' && ($giftStatus === '0' || $giftStatus === '1')) {
                $where .= ' AND gd.gift_status = ? ';
                $params[] = $giftStatus;
            }
            if ($giftScope === 'venue' && ($broadcastScope === '0' || $broadcastScope === '1')) {
                $where .= ' AND gd.is_broadcast_all = ? ';
                $params[] = $broadcastScope;
            }

            $countSql = "SELECT COUNT(*) AS c
                         FROM {$giftTable} gd
                         {$where}";
            $countRes = $db->query($countSql, $params);
            $totalRows = (int)($countRes[0]['c'] ?? 0);

            $listSql = "SELECT
                gd.id,
                gd.gift_name,
                gd.gift_price,
                gd.is_display,
                gd.image_url,
                gd.gif_url,
                COALESCE(gd.is_top_banner_show, 1) AS is_top_banner_show,
                COALESCE(gd.is_play_svga, 1) AS is_play_svga" .
                ($giftScope === 'venue' ? ",
                gd.venue_id,
                COALESCE(v.venue_name, '') AS venue_name,
                COALESCE(gd.is_broadcast_all, 0) AS is_broadcast_all,
                COALESCE(gd.gift_status, 0) AS gift_status" : "") . "
            FROM {$giftTable} gd
            " . ($giftScope === 'venue' ? "LEFT JOIN venues v ON v.id = gd.venue_id" : "") . "
            {$where}
            ORDER BY gd.id DESC
            LIMIT {$offset}, {$pageSize}";
            $rows = $db->query($listSql, $params);

            ok([
                'list' => $rows ?: [],
                'pagination' => [
                    'page'       => $page,
                    'page_size'  => $pageSize,
                    'total'      => $totalRows,
                    'total_page' => (int)ceil($totalRows / max($pageSize, 1)),
                ]
            ]);
        }

        // ============ 详情 ============
        case 'detail': {
            $id = toInt($_GET['id'] ?? 0);
            if ($id <= 0) bad('缺少 id');

            $sql = "SELECT
                        id,
                        gift_name,
                        gift_price,
                        is_display,
                        image_url,
                        gif_url,
                        COALESCE(is_top_banner_show, 1) AS is_top_banner_show,
                        COALESCE(is_play_svga, 1) AS is_play_svga" .
                        ($giftScope === 'venue' ? ",
                        venue_id,
                        COALESCE(is_broadcast_all, 0) AS is_broadcast_all,
                        COALESCE(gift_status, 0) AS gift_status" : "") . "
                    FROM {$giftTable}
                    WHERE id = ?";
            $rows = $db->query($sql, [$id]);

            if (!$rows) bad('未找到数据', 404);
            ok($rows[0]);
        }

        // ============ 新增 ============
        case 'create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');

            $payload = read_payload();

            $gift_name  = strOrNull($payload['gift_name'] ?? '');
            $gift_price = toFloat($payload['gift_price'] ?? 0);
            $is_display = to01($payload['is_display'] ?? 0);
            $image_url  = strOrEmpty($payload['image_url'] ?? '');
            $gif_url    = strOrEmpty($payload['gif_url'] ?? '');
            $is_top_banner_show = to01DefaultOn($payload['is_top_banner_show'] ?? 1);
            $is_play_svga       = to01DefaultOn($payload['is_play_svga'] ?? 1);
            $venue_id           = toInt($payload['venue_id'] ?? 0);
            $is_broadcast_all   = to01($payload['is_broadcast_all'] ?? 0);
            $gift_status        = to01($payload['gift_status'] ?? 0);
            if (!$gift_name) bad('gift_name 不能为空');
            if ($gift_price < 0) bad('gift_price 不能为负');
            if ($giftScope === 'venue' && $venue_id <= 0) bad('请选择归属场地');

            $db->beginTransaction();
            try {
                $sql = "INSERT INTO {$giftTable} (
                            " . ($giftScope === 'venue' ? "venue_id," : "") . "
                            gift_name,
                            gift_price,
                            is_display,
                            image_url,
                            gif_url,
                            is_top_banner_show,
                            is_play_svga
                            " . ($giftScope === 'venue' ? ", is_broadcast_all, gift_status" : "") . "
                        ) VALUES (" . implode(',', array_fill(0, $giftScope === 'venue' ? 10 : 7, '?')) . ")";
                
                $params = array_merge($giftScope === 'venue' ? [$venue_id] : [], [
                    $gift_name,
                    $gift_price,
                    $is_display,
                    $image_url,
                    $gif_url,
                    $is_top_banner_show,
                    $is_play_svga
                ], $giftScope === 'venue' ? [$is_broadcast_all, $gift_status] : []);

                $db->query($sql, $params, true);
                $newId = $db->getConnection()->insert_id;

                $db->commit();
                ok(['id' => $newId], '创建成功');
            } catch (Throwable $e) {
                $db->rollBack();
                if (method_exists($db, 'logToFile')) {
                    $db->logToFile('[gift_detail.create] ' . $e->getMessage());
                }
                bad('创建失败：' . $e->getMessage(), 500);
            }
        }

        // ============ 更新 ============
        case 'update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');

            $payload = read_payload();
            $id = toInt($payload['id'] ?? 0);
            if ($id <= 0) bad('缺少 id');

            $fields = [];
            $params = [];

            if (array_key_exists('gift_name', $payload)) {
                $giftName = strOrNull($payload['gift_name']);
                if (!$giftName) bad('gift_name 不能为空');
                $fields[] = 'gift_name = ?';
                $params[] = $giftName;
            }

            if (array_key_exists('gift_price', $payload)) {
                $price = toFloat($payload['gift_price']);
                if ($price < 0) bad('gift_price 不能为负');
                $fields[] = 'gift_price = ?';
                $params[] = $price;
            }

            if (array_key_exists('is_display', $payload)) {
                $fields[] = 'is_display = ?';
                $params[] = to01($payload['is_display']);
            }

            if (array_key_exists('image_url', $payload)) {
                $fields[] = 'image_url = ?';
                $params[] = strOrEmpty($payload['image_url']);
            }

            if (array_key_exists('gif_url', $payload)) {
                $fields[] = 'gif_url = ?';
                $params[] = strOrEmpty($payload['gif_url']);
            }
            if (array_key_exists('is_top_banner_show', $payload)) {
                $fields[] = 'is_top_banner_show = ?';
                $params[] = to01DefaultOn($payload['is_top_banner_show']);
            }
            
            if (array_key_exists('is_play_svga', $payload)) {
                $fields[] = 'is_play_svga = ?';
                $params[] = to01DefaultOn($payload['is_play_svga']);
            }
            if ($giftScope === 'venue' && array_key_exists('venue_id', $payload)) {
                $venueId = toInt($payload['venue_id']);
                if ($venueId <= 0) bad('请选择归属场地');
                $fields[] = 'venue_id = ?';
                $params[] = $venueId;
            }
            if ($giftScope === 'venue' && array_key_exists('is_broadcast_all', $payload)) {
                $fields[] = 'is_broadcast_all = ?';
                $params[] = to01($payload['is_broadcast_all']);
            }
            if ($giftScope === 'venue' && array_key_exists('gift_status', $payload)) {
                $fields[] = 'gift_status = ?';
                $params[] = to01($payload['gift_status']);
            }
            if (!$fields) bad('没有可更新的字段');

            $params[] = $id;
            $sql = "UPDATE {$giftTable} SET " . implode(', ', $fields) . " WHERE id = ?";

            $db->beginTransaction();
            try {
                $affected = $db->query($sql, $params, true);
                $db->commit();
                ok(['affected' => $affected], '更新成功');
            } catch (Throwable $e) {
                $db->rollBack();
                if (method_exists($db, 'logToFile')) {
                    $db->logToFile('[gift_detail.update] ' . $e->getMessage());
                }
                bad('更新失败：' . $e->getMessage(), 500);
            }
        }

        // ============ 删除（单条） ============
        case 'delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');

            $payload = read_payload();
            $id = toInt($payload['id'] ?? 0);
            if ($id <= 0) bad('缺少 id');

            $db->beginTransaction();
            try {
                $affected = $db->query(
                    "DELETE FROM {$giftTable} WHERE id = ?",
                    [$id],
                    true
                );
                $db->commit();
                ok(['affected' => $affected], '删除成功');
            } catch (Throwable $e) {
                $db->rollBack();
                if (method_exists($db, 'logToFile')) {
                    $db->logToFile('[gift_detail.delete] ' . $e->getMessage());
                }
                bad('删除失败：' . $e->getMessage(), 500);
            }
        }

        // ============ 批量删除 ============
        case 'batch_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');

            $payload = read_payload();
            $ids = $payload['ids'] ?? [];

            if (!is_array($ids) || empty($ids)) bad('缺少 ids');

            $ids = array_values(array_filter(array_map('intval', $ids), function ($v) {
                return $v > 0;
            }));

            if (!$ids) bad('ids 非法');

            $in = implode(',', array_fill(0, count($ids), '?'));
            $params = array_map('strval', $ids);

            $db->beginTransaction();
            try {
                $affected = $db->query(
                    "DELETE FROM {$giftTable} WHERE id IN ($in)",
                    $params,
                    true
                );
                $db->commit();
                ok(['affected' => $affected], '批量删除成功');
            } catch (Throwable $e) {
                $db->rollBack();
                if (method_exists($db, 'logToFile')) {
                    $db->logToFile('[gift_detail.batch_delete] ' . $e->getMessage());
                }
                bad('批量删除失败：' . $e->getMessage(), 500);
            }
        }

        // ============ 切换显示状态 ============
        case 'toggle_display': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');

            $payload = read_payload();
            $id = toInt($payload['id'] ?? 0);
            $is_display = to01($payload['is_display'] ?? 0);

            if ($id <= 0) bad('缺少 id');

            $db->beginTransaction();
            try {
                $affected = $db->query(
                    "UPDATE {$giftTable} SET is_display = ? WHERE id = ?",
                    [$is_display, $id],
                    true
                );
                $db->commit();
                ok(['affected' => $affected], '状态已更新');
            } catch (Throwable $e) {
                $db->rollBack();
                if (method_exists($db, 'logToFile')) {
                    $db->logToFile('[gift_detail.toggle_display] ' . $e->getMessage());
                }
                bad('更新失败：' . $e->getMessage(), 500);
            }
        }

        default:
            bad('未知操作 act');
    }
} catch (Throwable $e) {
    if (isset($db) && method_exists($db, 'logToFile')) {
        $db->logToFile('[gift_detail.fatal] ' . $e->getMessage());
    }
    bad('服务器异常：' . $e->getMessage(), 500);
}
