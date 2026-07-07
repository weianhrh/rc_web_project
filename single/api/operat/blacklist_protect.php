<?php
/**
 * /api/operat/blacklist_protect.php
 *
 * 拉黑保护 UID 配置。
 * - 后端接口 block_user.php / block_user_for_venue.php 直接 require 本文件，调用 blacklist_protect_is_uid($uid)
 * - 前端页面通过本文件读取/维护 same txt
 * - TXT 格式：每行一个 UID，可在 UID 后面加备注，例如：10001 官方巡查
 */

if (!defined('BLACKLIST_PROTECT_UID_FILE')) {
    define('BLACKLIST_PROTECT_UID_FILE', __DIR__ . '/blacklist_protect_uids.txt');
}

if (!function_exists('blacklist_protect_file_path')) {
    function blacklist_protect_file_path() {
        return BLACKLIST_PROTECT_UID_FILE;
    }
}

if (!function_exists('blacklist_protect_parse_line')) {
    function blacklist_protect_parse_line($line) {
        $line = trim(str_replace("\xEF\xBB\xBF", '', (string)$line));
        if ($line === '' || preg_match('/^\s*[#;]/u', $line)) {
            return null;
        }

        if (!preg_match('/^(\d+)(?:\s+(.+))?$/u', $line, $m)) {
            return null;
        }

        $uid = intval($m[1]);
        if ($uid <= 0) {
            return null;
        }

        $remark = trim((string)($m[2] ?? ''));
        if ($remark !== '' && strpos($remark, '#') === 0) {
            $remark = trim(substr($remark, 1));
        }

        return [
            'uid' => $uid,
            'remark' => $remark
        ];
    }
}

if (!function_exists('blacklist_protect_read_entries_from_string')) {
    function blacklist_protect_read_entries_from_string($content) {
        $entries = [];
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', (string)$content);

        foreach ($lines as $line) {
            $entry = blacklist_protect_parse_line($line);
            if (!$entry) {
                continue;
            }

            $uid = intval($entry['uid']);
            if (isset($seen[$uid])) {
                // 同一个 UID 重复出现时，保留第一次顺序；后面的备注非空则覆盖备注
                if ($entry['remark'] !== '') {
                    $entries[$seen[$uid]]['remark'] = $entry['remark'];
                }
                continue;
            }

            $seen[$uid] = count($entries);
            $entries[] = $entry;
        }

        return $entries;
    }
}

if (!function_exists('blacklist_protect_read_entries')) {
    function blacklist_protect_read_entries() {
        $file = blacklist_protect_file_path();
        if (!is_file($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        return blacklist_protect_read_entries_from_string($content);
    }
}

if (!function_exists('blacklist_protect_read_uids')) {
    function blacklist_protect_read_uids() {
        $uids = [];
        foreach (blacklist_protect_read_entries() as $entry) {
            $uid = intval($entry['uid'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }
        return $uids;
    }
}

if (!function_exists('blacklist_protect_is_uid')) {
    function blacklist_protect_is_uid($uid) {
        $uid = intval($uid);
        if ($uid <= 0) {
            return false;
        }
        return in_array($uid, blacklist_protect_read_uids(), true);
    }
}

if (!function_exists('blacklist_protect_build_content')) {
    function blacklist_protect_build_content($entries) {
        $lines = [
            '# 拉黑保护 UID，每行一个 UID，可在 UID 后面写备注',
            '# 示例：10001 官方巡查',
        ];

        foreach ($entries as $entry) {
            $uid = intval($entry['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $remark = trim((string)($entry['remark'] ?? ''));
            $remark = str_replace(["\r", "\n", "\t"], ' ', $remark);
            $lines[] = $remark === '' ? (string)$uid : ($uid . ' ' . $remark);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}

if (!function_exists('blacklist_protect_update_locked')) {
    function blacklist_protect_update_locked($callback) {
        $file = blacklist_protect_file_path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return [false, '保护名单文件无法打开，请检查目录写入权限', []];
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return [false, '保护名单文件加锁失败，请稍后重试', []];
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        $entries = blacklist_protect_read_entries_from_string($content ?: '');

        $result = $callback($entries);
        $newEntries = is_array($result) && isset($result['entries']) ? $result['entries'] : $entries;
        $message = is_array($result) && isset($result['msg']) ? $result['msg'] : 'ok';

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, blacklist_protect_build_content($newEntries));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return [true, $message, $newEntries];
    }
}

if (!function_exists('blacklist_protect_http_request')) {
    function blacklist_protect_http_request() {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $json = [];
        }
        return array_merge($_GET, $_POST, $json);
    }
}

/* 直接访问本 PHP 时，作为前端维护接口使用；被其它 PHP require 时，只提供函数，不输出内容。 */
$__blacklistProtectIsDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($__blacklistProtectIsDirect) {
    require_once __DIR__ . '/_bootstrap.php';

    $db = new Database();
    $loginUser = auth_or_die($db);
    if (!can_block($loginUser)) {
        json_err('无权访问拉黑保护名单', 1003);
    }

    $roleId = intval($loginUser['role_id'] ?? 0);
    $canWrite = in_array($roleId, [1, 2], true);
    $req = blacklist_protect_http_request();
    $action = strtolower(trim((string)($req['action'] ?? 'list')));

    if ($action === '' || $action === 'list') {
        $entries = blacklist_protect_read_entries();
        json_ok([
            'list' => $entries,
            'uids' => array_map('intval', array_column($entries, 'uid')),
            'count' => count($entries),
            'can_write' => $canWrite ? 1 : 0,
        ]);
    }

    if (!$canWrite) {
        json_err('无权修改拉黑保护名单', 1003, ['role_id' => $roleId]);
    }

    if ($action === 'add' || $action === 'save') {
        $uid = intval($req['uid'] ?? 0);
        $remark = trim((string)($req['remark'] ?? ''));
        if ($uid <= 0) {
            json_err('请输入正确的 UID', 1002);
        }

        list($ok, $msg, $entries) = blacklist_protect_update_locked(function($entries) use ($uid, $remark) {
            $found = false;
            foreach ($entries as &$entry) {
                if (intval($entry['uid']) === $uid) {
                    $found = true;
                    if ($remark !== '') {
                        $entry['remark'] = $remark;
                    }
                    break;
                }
            }
            unset($entry);

            if (!$found) {
                $entries[] = ['uid' => $uid, 'remark' => $remark];
            }

            return [
                'entries' => $entries,
                'msg' => $found ? '该 UID 已在保护名单中，已更新备注' : '已添加拉黑保护'
            ];
        });

        if (!$ok) {
            json_err($msg, 2001);
        }

        json_ok([
            'list' => $entries,
            'uids' => array_map('intval', array_column($entries, 'uid')),
            'count' => count($entries),
        ], $msg);
    }

    if ($action === 'delete' || $action === 'remove') {
        $uid = intval($req['uid'] ?? 0);
        if ($uid <= 0) {
            json_err('请输入正确的 UID', 1002);
        }

        list($ok, $msg, $entries) = blacklist_protect_update_locked(function($entries) use ($uid) {
            $before = count($entries);
            $entries = array_values(array_filter($entries, function($entry) use ($uid) {
                return intval($entry['uid'] ?? 0) !== $uid;
            }));
            return [
                'entries' => $entries,
                'msg' => count($entries) < $before ? '已移除拉黑保护' : '该 UID 不在保护名单中'
            ];
        });

        if (!$ok) {
            json_err($msg, 2001);
        }

        json_ok([
            'list' => $entries,
            'uids' => array_map('intval', array_column($entries, 'uid')),
            'count' => count($entries),
        ], $msg);
    }

    if ($action === 'check') {
        $uid = intval($req['uid'] ?? 0);
        json_ok([
            'uid' => $uid,
            'protected' => blacklist_protect_is_uid($uid) ? 1 : 0
        ]);
    }

    json_err('未知操作', 1002);
}
