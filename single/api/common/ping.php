<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 只用于后台顶部网络延迟检测，不查库，尽量保持轻量。
echo json_encode([
    'code' => 0,
    'msg' => 'pong',
    'server_time' => round(microtime(true) * 1000)
], JSON_UNESCAPED_UNICODE);
