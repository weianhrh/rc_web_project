<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once '../Database.php';

function evu_json(int $ok, string $msg, array $data = array(), int $status = 200): void {
    http_response_code($status);
    echo json_encode(array('ok' => $ok, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) evu_json(0, '未登录或缺少 session_token', array(), 401);
$db = new Database();
$admin = $db->getUserBySessionToken($token);
if (!$admin) evu_json(0, '登录已过期或 token 无效', array(), 401);
if (!in_array((int)($admin['role_id'] ?? 0), array(1, 2, 3, 4), true)) evu_json(0, '无权上传座驾资源', array(), 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evu_json(0, '请使用POST', array(), 405);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) evu_json(0, '没有上传文件');

$file = $_FILES['file'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) evu_json(0, '上传失败，错误码：' . (int)$file['error']);
if ((int)($file['size'] ?? 0) <= 0) evu_json(0, '文件为空');
if ((int)$file['size'] > 30 * 1024 * 1024) evu_json(0, '文件不能超过30MB');
if (!is_uploaded_file((string)$file['tmp_name'])) evu_json(0, '上传文件无效');

$kind = (string)($_POST['kind'] ?? 'image');
$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$imageExtensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'apng');
$animationExtensions = array('gif', 'webp', 'apng', 'svga');
$allowed = $kind === 'animation' ? $animationExtensions : $imageExtensions;
if (!in_array($extension, $allowed, true)) {
    evu_json(0, $kind === 'animation' ? '动图仅支持 GIF、WebP、APNG、SVGA' : '商品图仅支持 JPG、PNG、GIF、WebP、APNG');
}

$root = dirname(__DIR__, 2);
$relativeDirectory = '/uploads/entry_vehicle/' . date('Ym') . '/';
$saveDirectory = $root . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
if (!is_dir($saveDirectory) && !mkdir($saveDirectory, 0755, true) && !is_dir($saveDirectory)) {
    evu_json(0, '无法创建上传目录', array(), 500);
}
try {
    $random = bin2hex(random_bytes(8));
} catch (Throwable $e) {
    $random = str_replace('.', '', uniqid('', true));
}
$filename = ($kind === 'animation' ? 'vehicle_animation_' : 'vehicle_image_') . date('YmdHis') . '_' . $random . '.' . $extension;
$savePath = $saveDirectory . $filename;
if (!move_uploaded_file((string)$file['tmp_name'], $savePath)) evu_json(0, '保存文件失败', array(), 500);

$url = 'https://rcwulian.cn' . $relativeDirectory . rawurlencode($filename);
evu_json(1, '上传成功', array('url' => $url, 'filename' => $filename, 'kind' => $kind));