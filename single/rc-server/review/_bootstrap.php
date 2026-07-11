<?php
declare(strict_types=1);

const REVIEW_ROOT = __DIR__;
require_once __DIR__ . '/../api/Database.php';

function review_config(): array
{
    static $config;
    if ($config === null) {
        $config = require REVIEW_ROOT . '/_config.php';
    }
    return $config;
}

function review_db(): Database
{
    return new Database();
}

function review_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function review_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        return trim(explode(',', $value)[0]);
    }
    return 'unknown';
}

function review_set_cookie(string $name, string $value, int $expires): void
{
    $config = review_config();
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'domain' => (string)$config['cookie_domain'],
        'secure' => review_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function review_clear_cookie(string $name, bool $sharedDomain = true): void
{
    $config = review_config();
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => $sharedDomain ? (string)$config['cookie_domain'] : '',
        'secure' => review_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function review_current_user(Database $db): ?array
{
    $config = review_config();
    $rawToken = (string)($_COOKIE[$config['cookie_name']] ?? '');
    if ($rawToken === '') {
        return null;
    }

    $rows = $db->query(
        "SELECT u.id, u.username, u.display_name, u.rc_admin_username, u.kwx_admin_username,
                u.status, s.expires_at
           FROM audit_review_sessions s
           JOIN audit_review_users u ON u.id = s.reviewer_id
          WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.status = 1
          LIMIT 1",
        [hash('sha256', $rawToken)]
    );
    return $rows[0] ?? null;
}

function review_require_user(bool $json = false): array
{
    $db = review_db();
    $user = review_current_user($db);
    $db->close();
    if ($user) {
        return $user;
    }

    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 1001, 'msg' => '审核登录已失效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: /review/login.php');
    exit;
}

function review_start_session(Database $db, array $user): void
{
    $config = review_config();
    $days = max(1, (int)$config['session_days']);
    $rawToken = bin2hex(random_bytes(32));
    $expires = time() + $days * 86400;

    $db->query('DELETE FROM audit_review_sessions WHERE expires_at <= NOW()', [], true);
    $db->query(
        'INSERT INTO audit_review_sessions (reviewer_id, token_hash, ip_address, user_agent, expires_at, created_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), NOW())',
        [
            (int)$user['id'],
            hash('sha256', $rawToken),
            review_client_ip(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            $expires,
        ],
        true
    );
    review_set_cookie((string)$config['cookie_name'], $rawToken, $expires);
}

function review_has_column(Database $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = !empty($db->query("SHOW COLUMNS FROM `$table` LIKE ?", [$column]));
    }
    return $cache[$key];
}

function review_issue_rc_admin_session(Database $db, array $reviewUser): void
{
    $config = review_config();
    $username = trim((string)($reviewUser['rc_admin_username'] ?? ''));
    $rows = $db->query('SELECT id, role_id, session_token FROM admin_users WHERE username = ? LIMIT 1', [$username]);
    $admin = $rows[0] ?? null;
    if (!$admin || !in_array((int)$admin['role_id'], $config['allowed_admin_roles'], true)) {
        throw new RuntimeException('RC 映射账号不存在，或不是 role_id 1/2');
    }

    $token = trim((string)($admin['session_token'] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
    }
    if (review_has_column($db, 'admin_users', 'session_expires')) {
        $db->query(
            'UPDATE admin_users SET session_token = ?, session_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?',
            [$token, (int)$admin['id']],
            true
        );
    } else {
        $db->query('UPDATE admin_users SET session_token = ? WHERE id = ?', [$token, (int)$admin['id']], true);
    }

    // RC 业务页面仍使用原 session_token；保持 host-only，避免和 KWX 代理子域同名 Cookie 冲突。
    setcookie('session_token', $token, [
        'expires' => time() + 2592000,
        'path' => '/',
        'secure' => review_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function review_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function review_kwx_ticket(array $user, string $target): string
{
    $config = review_config();
    $payload = [
        'aud' => 'kwx-review',
        'username' => (string)$user['kwx_admin_username'],
        'reviewer' => (string)$user['username'],
        'target' => $target,
        'iat' => time(),
        'exp' => time() + 60,
        'nonce' => bin2hex(random_bytes(12)),
    ];
    $encoded = review_base64url_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = review_base64url_encode(hash_hmac('sha256', $encoded, (string)$config['shared_secret'], true));
    return $encoded . '.' . $signature;
}

function review_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
