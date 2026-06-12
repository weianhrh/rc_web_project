<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// 加载 .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// 提供全局访问函数
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}
