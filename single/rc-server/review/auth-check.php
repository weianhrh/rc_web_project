<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
header('Cache-Control: no-store');
$db = review_db();
$user = review_current_user($db);
$db->close();
if (!$user) {
    http_response_code(401);
    exit;
}
http_response_code(204);
