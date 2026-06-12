<?php
/**
 * /api/admin_v2/menu.php
 * admin-v2 后台菜单：主页 + 移动工作台
 * PHP 7.4 可用
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * 统一成功返回
 */
function json_success($data = [], $msg = 'ok') {
    echo json_encode([
        'code' => 0,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 注意：
 * component = Vue 项目 src/pages 下的文件路径，不带 .vue
 *
 * 例如：
 * dashboard/index
 * 对应：
 * src/pages/dashboard/index.vue
 *
 * mobile/workbench/index
 * 对应：
 * src/pages/mobile/workbench/index.vue
 */
$menus = [
    [
        'path' => '/',
        'component' => 'Layout',
        'redirect' => '/dashboard',
        'name' => 'Root',
        'children' => [
            [
                'path' => 'dashboard',
                'component' => 'dashboard/index',
                'name' => 'Dashboard',
                'meta' => [
                    'title' => '主页',
                    'elIcon' => 'HomeFilled',
                    'affix' => true
                ]
            ]
        ]
    ],

    [
        'path' => '/mobile',
        'component' => 'Layout',
        'redirect' => '/mobile/workbench',
        'name' => 'Mobile',
        'meta' => [
            'title' => '移动工作台',
            'elIcon' => 'Iphone',
            'alwaysShow' => true
        ],
        'children' => [
            [
                'path' => 'workbench',
                'component' => 'mobile/workbench/index',
                'name' => 'MobileWorkbench',
                'meta' => [
                    'title' => '移动工作台',
                    'elIcon' => 'Iphone',
                    'keepAlive' => true
                ]
            ]
        ]
    ]
];

json_success($menus);