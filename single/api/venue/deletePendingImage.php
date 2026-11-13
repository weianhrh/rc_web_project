<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function delete_pending_image_out($code, $message, $extra = []) {
    echo json_encode(array_merge(['code' => $code, 'msg' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    delete_pending_image_out(1003, '仅支持 POST 请求');
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) delete_pending_image_out(1001, '未登录');
$user = $database->query('SELECT role_id FROM admin_users WHERE session_token = ?', [$session_token]);
if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    delete_pending_image_out(1002, '权限不足');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
    delete_pending_image_out(1003, '无效的 JSON 请求');
}
$filename = $input['filename'] ?? '';
$venue_id = $input['venue_id'] ?? null;
if ((!is_string($venue_id) && !is_int($venue_id)) || !ctype_digit((string)$venue_id)) {
    delete_pending_image_out(1003, '缺少或无效的场地ID');
}
$venue_digits = ltrim((string)$venue_id, '0');
if ($venue_digits === '') delete_pending_image_out(1003, '缺少或无效的场地ID');
if (!is_string($filename) || !preg_match('#^pending_images/venue_(\d+)_(\d{14})\.(jpg|jpeg|png|gif|webp)$#iD', $filename, $matches)
    || ltrim($matches[1], '0') !== $venue_digits) {
    delete_pending_image_out(1003, '无效的待审图片路径或场地ID不匹配');
}

// 仅处理指定待审文件；不会修改场地当前头像及其他上传文件。
$pending_dir = __DIR__ . '/pending_images';
if (is_link($pending_dir)) delete_pending_image_out(1003, '待审图片目录无效');
$filepath = $pending_dir . '/' . basename($filename);
if (is_link($filepath)) delete_pending_image_out(1003, '待审图片路径无效');
if (!file_exists($filepath)) {
    delete_pending_image_out(0, '待审文件已清理', ['already_removed' => true]);
}
$pending_root = realpath($pending_dir);
$canonical_file = realpath($filepath);
if (!$pending_root || !$canonical_file || !is_file($canonical_file)
    || strpos($canonical_file, $pending_root . DIRECTORY_SEPARATOR) !== 0) {
    delete_pending_image_out(1003, '待审图片路径无效');
}
if (!@unlink($filepath)) {
    // 另一个审核请求可能已经完成同一文件的清理。
    clearstatcache(true, $filepath);
    if (!file_exists($filepath) && !is_link($filepath)) {
        delete_pending_image_out(0, '待审文件已清理', ['already_removed' => true]);
    }
    delete_pending_image_out(1004, '待审文件清理失败，请稍后重试');
}
delete_pending_image_out(0, '图片审核完成，已清理待审文件', ['already_removed' => false]);
