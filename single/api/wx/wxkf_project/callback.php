<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Crypt.php';

header("Content-Type: text/plain; charset=utf-8");

// 读取并记录原始体
$raw = file_get_contents("php://input");
file_put_contents(RAW_LOG, "[".date('c')."] ".$raw.PHP_EOL, FILE_APPEND);

$data = json_decode($raw, true);
if (!isset($data['encrypted'])) {
    http_response_code(400);
    echo "Missing 'encrypted'";
    exit;
}

try {
    $crypt = new WXBizMsgCryptLite(EncodingAESKey);
    $xml = $crypt->decrypt($data['encrypted']);
    if ($xml === false) {
        http_response_code(400);
        echo "decrypt_failed";
        exit;
    }

    // 解析 XML
    $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xmlObj === false) {
        http_response_code(400);
        echo "xml_parse_failed";
        exit;
    }

    $get = function($path) use ($xmlObj) {
        // 支持 a/b/c 路径
        $nodes = explode('/', $path);
        $node = $xmlObj;
        foreach ($nodes as $n) {
            if (!isset($node->{$n})) return null;
            $node = $node->{$n};
        }
        return trim((string)$node);
    };

    $userid  = $get('userid') ?: $get('customerInfo/openid'); // 兜底
    $appid   = $get('appid');
    $from    = (int)($get('from') ?? -1);
    $event   = $get('event') ?: '';
    $channel = (int)($get('channel') ?? -1);
    $kfstate = $get('kfstate') !== null ? (int)$get('kfstate') : null;
    // 多路径获取文本
    $msg = $get('content/msg');
    if ($msg === null || $msg === '') $msg = $get('content');

    // 记录
    $log = [
        'time' => date('Y-m-d H:i:s'),
        'userid' => $userid,
        'appid' => $appid,
        'msg' => $msg ?? '',
        'from' => $from,
        'event' => $event,
        'kfstate' => $kfstate,
        'channel' => $channel,
        'raw_xml' => $xml,
    ];
    $fp = fopen(CALLBACK_LOG, 'a');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);

    // ✅ 建议：这里若你希望“人工接入时机器人不答复”，就查Redis键（见你的 kf_state.php）
    // 例如：kf_active_${userid} 存在则只回 success 不做任何业务处理

    // 按平台要求，尽快返回 success
    http_response_code(200);
    echo "success";
} catch (Exception $e) {
    file_put_contents(ERR_LOG, "[".date('c')."] callback_exception ".$e->getMessage().PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo "internal_error";
}
