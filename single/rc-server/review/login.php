<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = review_db();
$current = review_current_user($db);
if ($current) {
    $db->close();
    header('Location: /review/');
    exit;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rows = $db->query('SELECT * FROM audit_review_users WHERE username = ? LIMIT 1', [$username]);
    $user = $rows[0] ?? null;
    $dummy = '$2y$10$usesomesaltystringforequaldelay123456789012345678901234';
    $hash = is_array($user) ? (string)$user['password_hash'] : $dummy;
    $locked = $user && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time();

    if ($locked) {
        $error = '登录失败次数过多，请稍后再试';
    } elseif ($user && (int)$user['status'] === 1 && password_verify($password, $hash)) {
        try {
            review_issue_rc_admin_session($db, $user);
            review_start_session($db, $user);
            $db->query(
                'UPDATE audit_review_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?',
                [(int)$user['id']],
                true
            );
            $db->close();
            header('Location: /review/');
            exit;
        } catch (Throwable $e) {
            error_log('[review-login] ' . $e->getMessage());
            $error = '审核账号映射配置异常，请联系管理员';
        }
    } else {
        if ($user) {
            $attempts = (int)$user['failed_attempts'] + 1;
            if ($attempts >= 5) {
                $db->query(
                    'UPDATE audit_review_users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?',
                    [(int)$user['id']],
                    true
                );
                $error = '登录失败次数过多，账号已锁定10分钟';
            } else {
                $db->query('UPDATE audit_review_users SET failed_attempts = ? WHERE id = ?', [$attempts, (int)$user['id']], true);
                $error = '账号或密码错误';
            }
        } else {
            password_verify($password, $dummy);
            $error = '账号或密码错误';
        }
    }
}
$db->close();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>审核工作台登录</title>
  <style>
    *{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:Inter,"PingFang SC","Microsoft YaHei",sans-serif;background:#eef3fb;color:#172033;display:grid;place-items:center;padding:20px;overflow:hidden}
    body:before,body:after{content:"";position:fixed;border-radius:999px;filter:blur(3px);pointer-events:none}body:before{width:480px;height:480px;left:-180px;top:-220px;background:radial-gradient(circle,#b8d5ff 0,rgba(184,213,255,0) 70%)}body:after{width:520px;height:520px;right:-240px;bottom:-280px;background:radial-gradient(circle,#cde9ff 0,rgba(205,233,255,0) 70%)}
    .login-card{position:relative;z-index:1;width:min(430px,100%);background:rgba(255,255,255,.94);border:1px solid rgba(213,224,241,.9);border-radius:22px;padding:34px 34px 30px;box-shadow:0 24px 70px rgba(33,72,128,.16);backdrop-filter:blur(14px)}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.brand-icon{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;color:#fff;font-size:23px;font-weight:800;background:linear-gradient(135deg,#2f7df6,#22a6f2);box-shadow:0 10px 22px rgba(47,125,246,.28)}h1{margin:0;font-size:22px}.sub{margin:4px 0 0;color:#7b879b;font-size:13px}
    label{display:block;margin:0 0 7px;font-size:13px;font-weight:650;color:#445066}.field{margin-bottom:17px}.input{width:100%;height:47px;border:1px solid #d9e1ef;border-radius:11px;background:#f9fbff;padding:0 14px;font-size:15px;outline:none;transition:.18s}.input:focus{border-color:#4d8df6;box-shadow:0 0 0 4px rgba(47,125,246,.11);background:#fff}
    .error{margin:0 0 16px;padding:10px 12px;border:1px solid #ffd4d4;border-radius:10px;background:#fff2f2;color:#c63c3c;font-size:13px}.submit{width:100%;height:48px;border:0;border-radius:11px;color:#fff;background:linear-gradient(135deg,#2f7df6,#208ee8);font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 10px 22px rgba(47,125,246,.24)}.submit:hover{filter:brightness(1.03)}.hint{text-align:center;color:#9aa4b3;font-size:12px;margin:18px 0 0}
    @media(max-width:520px){body{align-items:start;padding-top:12vh}.login-card{padding:28px 22px;border-radius:18px}}
  </style>
</head>
<body>
  <main class="login-card">
    <div class="brand"><div class="brand-icon">审</div><div><h1>审核工作台</h1><p class="sub">RC 与 KWX 统一审核入口</p></div></div>
    <?php if ($error !== ''): ?><div class="error"><?= review_escape($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <div class="field"><label for="username">审核账号</label><input class="input" id="username" name="username" value="<?= review_escape($username) ?>" autocomplete="username" maxlength="64" required autofocus></div>
      <div class="field"><label for="password">密码</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="submit" type="submit">登录审核工作台</button>
    </form>
    <p class="hint">此入口仅开放审核相关功能</p>
  </main>
</body>
</html>
