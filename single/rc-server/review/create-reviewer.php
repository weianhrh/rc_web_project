<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc < 5) {
    fwrite(STDERR, "用法：php create-reviewer.php <审核登录账号> <审核登录密码> <RC内部账号> <KWX内部账号> [显示名称]\n");
    exit(1);
}

[$script, $username, $password, $rcUsername, $kwxUsername] = $argv;
$displayName = trim((string)($argv[5] ?? '审核员')) ?: '审核员';

if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
    fwrite(STDERR, "审核账号只能使用3-64位字母、数字、点、下划线或横线。\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "密码至少需要10位。\n");
    exit(1);
}

$db = review_db();
try {
    $admin = $db->query('SELECT role_id FROM admin_users WHERE username = ? LIMIT 1', [$rcUsername]);
    if (!$admin || !in_array((int)$admin[0]['role_id'], review_config()['allowed_admin_roles'], true)) {
        throw new RuntimeException('RC内部账号不存在，或不是 role_id 1/2');
    }

    $db->query(
        "INSERT INTO audit_review_users
            (username, password_hash, display_name, rc_admin_username, kwx_admin_username, status)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash), display_name = VALUES(display_name),
            rc_admin_username = VALUES(rc_admin_username), kwx_admin_username = VALUES(kwx_admin_username),
            status = 1, failed_attempts = 0, locked_until = NULL",
        [$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $rcUsername, $kwxUsername],
        true
    );
    fwrite(STDOUT, "审核账号已创建或更新：{$username}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "创建失败：{$e->getMessage()}\n");
    exit(1);
} finally {
    $db->close();
}
