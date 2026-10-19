<?php
/**
 * 用户管理：将指定用户昵称重置为 Rc用户_毫秒时间戳。
 * 只修改 users.nickname；仅 session_token 对应的 role_id=1、2 可操作。
 * POST application/json: {"uid":"60749"}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

function rcResetNicknameResult($success, $message, $data = [])
{
    return array_merge(['success' => $success, 'message' => $message], $data);
}

function rcResetNicknameHandle()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return rcResetNicknameResult(false, '仅支持POST请求');
    }

    // JSON + 同源校验，避免其他网站通过表单代替管理员发起重置。
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        return rcResetNicknameResult(false, '请使用JSON格式提交请求');
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        return rcResetNicknameResult(false, '不允许跨站操作');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!$originHost || !$requestHost || strcasecmp($originHost, $requestHost) !== 0) {
            return rcResetNicknameResult(false, '非法请求来源');
        }
    }

    $sessionToken = $_COOKIE['session_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return rcResetNicknameResult(false, '用户未登录或会话已过期', ['code' => 1001]);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $uid = is_array($input) ? ($input['uid'] ?? null) : null;
    if ((!is_string($uid) && !is_int($uid)) || !preg_match('/^[1-9][0-9]*$/D', (string)$uid)) {
        return rcResetNicknameResult(false, '用户UID无效');
    }

    $database = null;
    $inTransaction = false;
    $hasLock = false;
    $lockName = 'rc_user_nickname_reset';
    try {
        require_once __DIR__ . '/../Database.php';
        $database = new Database();
        $admin = $database->getUserBySessionToken($sessionToken);
        if (!$admin || empty($admin['id'])) {
            return rcResetNicknameResult(false, '用户未登录或会话已过期', ['code' => 1001]);
        }
        if (!in_array((int)($admin['role_id'] ?? 0), [1, 2], true)) {
            return rcResetNicknameResult(false, '仅平台管理员、运营可以重置用户昵称', ['code' => 403]);
        }

        // 串行生成默认名，避免两个管理员在同一毫秒生成相同的昵称。
        $locked = $database->query('SELECT GET_LOCK(?, 3) AS acquired', [$lockName]);
        if (!$locked || (int)($locked[0]['acquired'] ?? 0) !== 1) {
            return rcResetNicknameResult(false, '当前重置操作较多，请稍后重试');
        }
        $hasLock = true;
        $database->beginTransaction();
        $inTransaction = true;
        $rows = $database->query('SELECT uid, nickname FROM users WHERE uid = ? LIMIT 1 FOR UPDATE', [$uid]);
        if ($rows === false) {
            throw new RuntimeException('查询目标用户失败');
        }
        if (!$rows) {
            return rcResetNicknameResult(false, '用户不存在');
        }
        $oldNickname = (string)($rows[0]['nickname'] ?? '');
        $newNickname = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            // 使用字符串格式化，避免时间戳转换为科学计数法或32位整数溢出。
            $candidate = 'Rc用户_' . sprintf('%.0f', floor(microtime(true) * 1000));
            if ($candidate !== $oldNickname) {
                $duplicates = $database->query('SELECT uid FROM users WHERE nickname = ? LIMIT 1', [$candidate]);
                if ($duplicates === false) {
                    throw new RuntimeException('检查默认昵称重名失败');
                }
                if (!$duplicates) {
                    $newNickname = $candidate;
                    break;
                }
            }
            usleep(2000);
        }
        if ($newNickname === '') {
            throw new RuntimeException('生成默认昵称失败');
        }

        // 默认昵称由服务端生成，不接受客户端传入 nickname，也不走APP的6字限制。
        $affected = $database->query('UPDATE users SET nickname = ? WHERE uid = ? LIMIT 1', [$newNickname, $uid], true);
        if ($affected !== 1) {
            throw new RuntimeException('更新目标用户昵称失败');
        }
        $database->commit();
        $inTransaction = false;

        // 记录到现有PHP错误日志，避免新增表、覆盖其他日志配置。
        error_log('[resetUserNickname] ' . json_encode([
            'time' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
            'operator_admin_id' => $admin['id'],
            'operator_uid' => $admin['uid'] ?? 0,
            'operator_name' => $admin['username'] ?? '',
            'operator_role_id' => (int)$admin['role_id'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_uid' => (string)$uid,
            'old_nickname' => $oldNickname,
            'new_nickname' => $newNickname,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return rcResetNicknameResult(true, '昵称已重置为默认值', [
            'uid' => (string)$uid,
            'old_nickname' => $oldNickname,
            'nickname' => $newNickname,
        ]);
    } catch (Throwable $e) {
        error_log('resetUserNickname failed: ' . $e->getMessage());
        return rcResetNicknameResult(false, '重置昵称失败，请稍后重试');
    } finally {
        if ($database !== null) {
            try {
                if ($inTransaction) {
                    $database->rollBack();
                }
                if ($hasLock) {
                    $database->query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                }
            } catch (Throwable $cleanupError) {
                error_log('resetUserNickname cleanup failed: ' . $cleanupError->getMessage());
            }
            $database->close();
        }
    }
}

echo json_encode(rcResetNicknameHandle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
