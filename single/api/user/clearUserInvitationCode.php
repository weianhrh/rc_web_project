<?php
declare(strict_types=1);

require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function respond(int $code, string $msg, array $data = []): void
{
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, '仅支持POST请求');
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        respond(1001, '用户未登录或会话已过期');
    }

    $admin = $database->getUserBySessionToken($sessionToken);
    if (!$admin || !in_array((int)($admin['role_id'] ?? 0), [1, 2], true)) {
        respond(1002, '当前账号无权清除用户邀请码');
    }

    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) {
        respond(400, '用户UID无效');
    }

    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = '管理员在用户管理清除邀请码';
    }
    if (mb_strlen($reason, 'UTF-8') > 255) {
        $reason = mb_substr($reason, 0, 255, 'UTF-8');
    }

    $database->beginTransaction();
    try {
        $rows = $database->query(
            "SELECT uid, nickname, invitation_code
             FROM users
             WHERE uid = ?
             LIMIT 1
             FOR UPDATE",
            [$uid]
        );
        if (!$rows) {
            throw new RuntimeException('用户不存在');
        }

        $oldCode = trim((string)($rows[0]['invitation_code'] ?? ''));
        if ($oldCode === '' || $oldCode === '0000') {
            $database->commit();
            respond(0, '该用户邀请码已经是0000，无需重复清除', [
                'uid' => $uid,
                'old_code' => $oldCode,
                'new_code' => '0000',
                'changed' => false,
            ]);
        }

        $updated = $database->query(
            "UPDATE users
             SET invitation_code = '0000'
             WHERE uid = ? AND invitation_code = ?
             LIMIT 1",
            [$uid, $oldCode],
            true
        );
        if ($updated !== 1) {
            throw new RuntimeException('邀请码已被其他操作修改，请刷新后重试');
        }

        $logged = $database->query(
            "INSERT INTO user_invitation_code_change_logs
                (user_uid, user_nickname, old_invitation_code, new_invitation_code,
                 change_type, reason, operator_admin_id, operator_uid, operator_name)
             VALUES (?, ?, ?, '0000', 'clear', ?, ?, ?, ?)",
            [
                $uid,
                (string)($rows[0]['nickname'] ?? ''),
                $oldCode,
                $reason,
                (int)($admin['id'] ?? 0),
                (int)($admin['uid'] ?? 0),
                (string)($admin['username'] ?? ''),
            ],
            true
        );
        if ($logged !== 1) {
            throw new RuntimeException('邀请码操作日志写入失败');
        }

        $database->commit();
        respond(0, '邀请码已从 ' . $oldCode . ' 清除为 0000', [
            'uid' => $uid,
            'old_code' => $oldCode,
            'new_code' => '0000',
            'changed' => true,
        ]);
    } catch (Throwable $transactionError) {
        $database->rollBack();
        throw $transactionError;
    }
} catch (Throwable $e) {
    error_log('clearUserInvitationCode error: ' . $e->getMessage());
    respond(500, $e->getMessage());
} finally {
    $database->close();
}
