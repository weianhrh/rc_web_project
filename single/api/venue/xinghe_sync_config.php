<?php
// Copy as xinghe_sync_config.php alongside the sync endpoint on BOTH servers.
// Both tokens MUST match. Generate with: php -r "echo bin2hex(random_bytes(32));"
// The URL is only needed by RC. Do not use the old DR endpoint.
return [
    'url' => 'https://open.sxzjjx.cn/api/venue/receiveRcVenue.php',
    'token' => '6cd2966d4576dd581e653912595386df1930408571a5728329bd50c957b81a72',
];
