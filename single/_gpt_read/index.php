<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

/**
 * ChatGPT 只读文件接口
 * 只允许读取指定目录内的文本代码文件
 */

// 改成你自己的超长随机密钥，不要用这个示例
const GPT_READ_TOKEN = 'CHANGE_THIS_TO_A_LONG_RANDOM_TOKEN_2026';

// 单个文件最大读取大小，避免一次读太大
const MAX_FILE_SIZE = 500 * 1024; // 500KB

// 允许读取的根目录，按你的项目自己改
$ALLOWED_ROOTS = [
    // 整个 single 项目，但读取时仍然会限制后缀和敏感文件
    'single' => '/www/wwwroot/open.rcwulian.cn/single',

    // API 目录
    'api' => '/www/wwwroot/open.rcwulian.cn/single/api',

    // admin-v2 前端
    'admin-v2' => '/www/wwwroot/open.rcwulian.cn/single/admin-v2',

    // 如果你的 res 也在 single 下面
    'res' => '/www/wwwroot/open.rcwulian.cn/single/res',
];

// 允许读取的文件类型
$ALLOWED_EXTS = [
    'php',
    'html',
    'htm',
    'js',
    'css',
    'json',
    'txt',
    'md',
    'xml'
];

// 明确禁止读取的敏感文件
$DENY_FILENAMES = [
    '.env',
    'Database.php',
    'Encryption.php',
    'config.php',
    'composer.lock',
    'id_rsa',
    'id_rsa.pub'
];

function out_json($code, $msg, $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function isDeniedFile($path, $denyNames) {
    $base = basename($path);
    if (in_array($base, $denyNames, true)) {
        return true;
    }

    $lower = strtolower($base);

    // 防止读取私钥、证书、密码类文件
    $denyKeywords = [
        'password',
        'passwd',
        'secret',
        'private',
        'key',
        'pem',
        'cert'
    ];

    foreach ($denyKeywords as $keyword) {
        if (strpos($lower, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

// 校验 token
$token = $_GET['token'] ?? '';
if (!hash_equals(GPT_READ_TOKEN, $token)) {
    out_json(403, 'token错误或无权限');
}

$rootKey = $_GET['root'] ?? '';
$path    = $_GET['path'] ?? '';
$action  = $_GET['action'] ?? 'read';

if (!$rootKey || !isset($ALLOWED_ROOTS[$rootKey])) {
    out_json(400, 'root参数错误');
}

$baseDir = realpath($ALLOWED_ROOTS[$rootKey]);
if (!$baseDir || !is_dir($baseDir)) {
    out_json(500, '服务器允许目录不存在');
}

$path = str_replace('\\', '/', $path);
$path = ltrim($path, '/');

if ($path === '') {
    $target = $baseDir;
} else {
    if (strpos($path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        out_json(400, '非法路径');
    }

    $target = realpath($baseDir . '/' . $path);
}

if (!$target) {
    out_json(404, '文件或目录不存在');
}

// 防止越权跳出允许目录
$basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$targetNorm = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if ($target !== $baseDir && strpos($targetNorm, $basePrefix) !== 0) {
    out_json(403, '禁止访问该路径');
}

// 列出目录
if ($action === 'list') {
    if (!is_dir($target)) {
        out_json(400, '目标不是目录');
    }

    $items = [];
    foreach (scandir($target) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = $target . DIRECTORY_SEPARATOR . $name;

        if (isDeniedFile($full, $DENY_FILENAMES)) {
            continue;
        }

        $items[] = [
            'name' => $name,
            'type' => is_dir($full) ? 'dir' : 'file',
            'size' => is_file($full) ? filesize($full) : null,
            'mtime' => date('Y-m-d H:i:s', filemtime($full)),
        ];
    }

    out_json(0, 'ok', [
        'root' => $rootKey,
        'path' => $path,
        'items' => $items,
    ]);
}

// 读取文件
if ($action !== 'read') {
    out_json(400, 'action只支持read或list');
}

if (!is_file($target)) {
    out_json(400, '目标不是文件');
}

if (!is_readable($target)) {
    out_json(403, '文件不可读');
}

if (isDeniedFile($target, $DENY_FILENAMES)) {
    out_json(403, '禁止读取敏感文件');
}

$size = filesize($target);
if ($size > MAX_FILE_SIZE) {
    out_json(413, '文件过大，拒绝读取', [
        'size' => $size,
        'max_size' => MAX_FILE_SIZE,
    ]);
}

$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED_EXTS, true)) {
    out_json(403, '不允许读取该文件类型');
}

$content = file_get_contents($target);

out_json(0, 'ok', [
    'root' => $rootKey,
    'path' => $path,
    'size' => $size,
    'mtime' => date('Y-m-d H:i:s', filemtime($target)),
    'sha1' => sha1($content),
    'content' => $content,
]);