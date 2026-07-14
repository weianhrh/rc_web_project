<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';

function json_out($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$sessionToken = $_COOKIE['session_token'] ?? '';
$user = $sessionToken !== '' ? $database->getUserBySessionToken($sessionToken) : null;
if (!$user || empty($user['role_id'])) json_out(1001, '用户未登录或会话已过期');

$roomId = trim((string)($_GET['room_id'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(50, max(1, (int)($_GET['page_size'] ?? 20)));
if ($roomId === '') json_out(400, 'room_id 不能为空');

$countRows = $database->query('SELECT COUNT(*) AS total FROM camera_ota_send_records WHERE room_id = ?', [$roomId]);
if ($countRows === false) json_out(500, '历史记录查询失败，请先执行建表 SQL');
$total = (int)($countRows[0]['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$rows = $database->query(
    "SELECT id, room_id, ota_version, firmware_url, ota_force, operator_id, operator_name,
            zego_code, zego_message, created_at
     FROM camera_ota_send_records WHERE room_id = ? ORDER BY id DESC LIMIT {$offset}, {$pageSize}",
    [$roomId]
);
if ($rows === false) json_out(500, '历史记录查询失败');

json_out(200, '查询成功', [
    'room_id' => $roomId, 'page' => $page, 'page_size' => $pageSize,
    'total' => $total, 'rows' => $rows,
]);
