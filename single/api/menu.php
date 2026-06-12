<?php
require_once 'Database.php';

$database = new Database();

// 从请求中获取 session_token
$session_token = $_COOKIE['session_token'] ?? '';

// 检查 session_token 是否存在
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未提供有效的身份验证令牌', 'data' => []]);
    exit;
}

// 查询数据库中的用户信息
$sql = "SELECT username, role_id FROM admin_users WHERE session_token = ?";
$params = [$session_token];
$user = $database->query($sql, $params);

if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => 'Session token is invalid or user does not exist', 'data' => []]);
    exit;
}

// 根据用户的角色ID动态生成菜单
$role_id = $user[0]['role_id'];
$menu = getMenuByRoleId($role_id, $database);


echo json_encode(['code' => 0, 'msg' => '', 'data' => $menu]);

$database->close();

/**
 * 根据角色ID返回不同的菜单数组
 */
function getMenuByRoleId($roleId, $database) {
    $sql = "SELECT * FROM admin_menus ORDER BY sort ASC";
    $menus = $database->query($sql);

    // 过滤角色可见菜单
    $menus = array_filter($menus, function($menu) use ($roleId) {
        $roleArr = explode(',', $menu['role_ids']);
        return in_array($roleId, $roleArr);
    });

    // 构造树状结构
    return buildMenuTree($menus);
}

function buildMenuTree($items, $parentId = 0) {
    $tree = [];
    foreach ($items as $item) {
        if ($item['parent_id'] == $parentId) {
            $children = buildMenuTree($items, $item['id']);
            $node = [
                'name' => $item['name'],
                'title' => $item['title'],
                'icon' => $item['icon'],
            ];
            if (!empty($children)) {
                $node['list'] = $children;
            } else if (!empty($item['jump'])) {
                $node['jump'] = $item['jump'];
            }
            $tree[] = $node;
        }
    }
    return $tree;
}

?>