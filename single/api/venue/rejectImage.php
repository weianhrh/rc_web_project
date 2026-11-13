<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/../Database.php';

function avatar_reject_reply($code, $message, array $extra = []) {
    echo json_encode(array_merge(['code'=>$code, 'msg'=>$message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    avatar_reject_reply(1003, '仅支持 POST 请求');
}
$input = $_POST;
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) avatar_reject_reply(1003, '请求内容不是有效 JSON');
}
$sessionToken = $_COOKIE['session_token'] ?? '';
if (!$sessionToken) avatar_reject_reply(1001, '未登录');
$database = new Database();
$users = $database->query('SELECT uid,role_id FROM admin_users WHERE session_token = ?', [$sessionToken]);
if (!$users || !in_array((int)$users[0]['role_id'], [1,2], true)) avatar_reject_reply(1002, '权限不足');
$rawVenue = $input['venue_id'] ?? '';
if ((!is_int($rawVenue) && !is_string($rawVenue)) || !ctype_digit((string)$rawVenue) || (int)$rawVenue <= 0) avatar_reject_reply(1003, '缺少或无效的场地ID');
$venueId = (int)$rawVenue;
$reason = $input['reason'] ?? '无原因';
if (!is_string($reason)) avatar_reject_reply(1003, '拒绝原因格式错误');
$reason = trim($reason);
$reviewerUid = $users[0]['uid'];
$reviewedAt = date('Y-m-d H:i:s');
$filename = null;
if (array_key_exists('filename', $input)) {
    $filename = $input['filename'];
    if (!is_string($filename) || !preg_match('#\Apending_images/venue_(\d+)_(\d{14})\.(?:jpg|jpeg|png|gif|webp)\z#i', $filename, $matches) || (int)$matches[1] !== $venueId) {
        avatar_reject_reply(1003, '待审图片文件名与场地不匹配');
    }
}
// 新聚合页按准确文件审核，避免同场地的另一张待审图被一起处理。
$sql = "UPDATE venue_image_reviews SET status = 'rejected', reason = ?, reviewer_uid = ?, reviewed_at = ? WHERE venue_id = ? AND status = 'pending'";
$params = [$reason, $reviewerUid, $reviewedAt, $venueId];
if ($filename !== null) {
    $sql .= ' AND image_url = ?';
    $params[] = $filename;
}
$result = $database->query($sql, $params, true);
if ($result === false) avatar_reject_reply(1004, '拒绝状态保存失败，请稍后重试');
if ($result > 0) avatar_reject_reply(0, '图片已拒绝');
// 旧接口曾先写入拒绝状态，再输出多余 UID 导致前端中断。
// 同一图片已拒绝时允许继续后续清理，不覆盖原审核人、时间和原因。
if ($filename !== null) {
    $rows = $database->query('SELECT status FROM venue_image_reviews WHERE venue_id = ? AND image_url = ? ORDER BY uploaded_at DESC LIMIT 1', [$venueId, $filename]);
    if ($rows && ($rows[0]['status'] ?? '') === 'rejected') {
        avatar_reject_reply(0, '该图片已拒绝，可继续清理待审文件', ['already_rejected'=>true]);
    }
}
avatar_reject_reply(1004, '未找到对应的待审记录，请刷新后核对');
