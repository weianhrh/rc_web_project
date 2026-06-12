<?php

require_once 'Database.php';      // 数据库类
require_once 'RedisHelper.php';   // Redis工具类

$db = new Database();
$redis = new RedisHelper();

try {
    $redis->connect('127.0.0.1', 6379);
    $redis->selectDb(12);

    $allKeys = $redis->getAllKeys();
    $keys = array_slice($allKeys, 0, 1000);

    // 构建 SQL，仅更新 vip 和 vip_name（基于已有 cumulative_spending）
    $sql = "UPDATE users SET
        vip = CASE
            WHEN cumulative_spending >= 10000 THEN 9
            WHEN cumulative_spending >= 5000 THEN 8
            WHEN cumulative_spending >= 3000 THEN 7
            WHEN cumulative_spending >= 1600 THEN 6
            WHEN cumulative_spending >= 800 THEN 5
            WHEN cumulative_spending >= 400 THEN 4
            WHEN cumulative_spending >= 200 THEN 3
            WHEN cumulative_spending >= 100 THEN 2
            ELSE 1
        END,
        vip_name = CASE
            WHEN cumulative_spending >= 10000 THEN '赛车传奇'
            WHEN cumulative_spending >= 5000 THEN '超凡赛车手'
            WHEN cumulative_spending >= 3000 THEN '赛车大师'
            WHEN cumulative_spending >= 1600 THEN '传奇赛手'
            WHEN cumulative_spending >= 800 THEN '冠军赛手'
            WHEN cumulative_spending >= 400 THEN '专业赛手'
            WHEN cumulative_spending >= 200 THEN '熟练赛手'
            WHEN cumulative_spending >= 100 THEN '业余赛手'
            ELSE '新手赛手'
        END
        WHERE uid = ?";

    foreach ($keys as $uid) {
        $db->query($sql, [$uid], true);
        echo "用户 $uid 的 VIP 等级和称号已更新<br>";
    }

} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
} finally {
    $redis->close();
    $db->close();
}
