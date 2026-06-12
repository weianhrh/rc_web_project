<?php
require_once 'Database.php';      // 包含 Database 类定义
require_once 'RedisHelper.php';   // 包含 RedisHelper 类定义

// 创建数据库连接
$db = new Database();

// 创建 Redis 实例并连接
$redis = new RedisHelper();
$redis->connect('127.0.0.1', 6379);
$redis->selectDb(12);  // 使用 Redis 第 13 个数据库

// 查询前 100 名用户
$sql = "SELECT uid, vip, vip_name, nickname, headimgurl
        FROM users
        WHERE deleted = 0
        ORDER BY vip DESC, cumulative_spending DESC
        LIMIT 100";

$topUsers = $db->query($sql, []);  // 添加 true 开启日志记录（如你已实现）

if ($topUsers) {
    $redisKey = 'top100_users';

    // 可选：先清空旧数据
    $redis->delete($redisKey);

     if (is_array($topUsers)) {
        foreach ($topUsers as $user) {
            $redis->save("{$user['uid']}", json_encode($user));
        }
        echo "前 100 名用户信息已存入 Redis。";
    } else {
        echo "未查询到用户数据或查询失败。";
    }


    echo "前 100 名用户信息已存入 Redis。";
} else {
    echo "未查询到用户数据或查询失败。";
}

// 关闭连接
$db->close();
$redis->close();
?>
