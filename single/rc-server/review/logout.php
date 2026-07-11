<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$config = review_config();
$token = (string)($_COOKIE[$config['cookie_name']] ?? '');
if ($token !== '') {
    $db = review_db();
    $db->query('DELETE FROM audit_review_sessions WHERE token_hash = ?', [hash('sha256', $token)], true);
    $db->close();
}
review_clear_cookie((string)$config['cookie_name'], true);
review_clear_cookie('session_token', false);
header('Location: /review/login.php');
exit;
