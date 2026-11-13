<?php
declare(strict_types=1);
// 与星河 api/review/_bridge_config.php 使用相同密钥。仅服务器使用。
return [
    'bridge_url' => 'https://open.sxzjjx.cn/api/review/bridge.php',
    'shared_secret' => 'a3c6f51b83cbd1ecd0b1472bf3b40b7925b8ef7673e150754da70112d7fcc3a5',
    // 留空使用每个审核员在 RC 已映射的后台用户名；星河也需存在同名 role 1/2 账号。
    // 也可在星河配置 reviewer_admin_username，统一映射到星河专用审核账号。
];
