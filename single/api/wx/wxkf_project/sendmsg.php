<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Crypt.php';

header('Content-Type: application/json; charset=utf-8');

$userid  = trim($_POST['userid'] ?? '');
$msg     = trim($_POST['msg'] ?? '');
$channel = intval($_POST['channel'] ?? WX_CHANNEL_H5);
$event   = isset($_POST['event']) ? trim($_POST['event']) : ''; // 仅作展示用途
$name    = trim($_POST['kefuname'] ?? '客服小姐姐');
$avatar  = trim($_POST['kefuavatar'] ?? 'https://rcwulian.cn/app/imgv2/img/img/002.png');

if ($userid === '' || ($msg === '' && $event === '')) {
    echo json_encode(['status'=>0,'msg'=>'缺少 userid 或 (msg/event)']);
    exit;
}

// 同时给 userid + openid，避免平台按不同渠道取字段时对不上
$xml = "<xml>
    <appid><![CDATA[".APPID."]]></appid>
    <userid><![CDATA[{$userid}]]></userid>
    <openid><![CDATA[{$userid}]]></openid>
    <msg><![CDATA[{$msg}]]></msg>
    <channel>{$channel}</channel>
    <event><![CDATA[{$event}]]></event>
    <kefuname><![CDATA[{$name}]]></kefuname>
    <kefuavatar><![CDATA[{$avatar}]]></kefuavatar>
</xml>";

try {
    $enc = (new WXMsgEncryptor(EncodingAESKey, APPID))->encrypt($xml);
    $payload = json_encode(['encrypt' => $enc], JSON_UNESCAPED_UNICODE);

    $url = "https://chatbot.weixin.qq.com/openapi/sendmsg/".TOKEN;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) throw new Exception("curl errno=$errno");
    $json = json_decode($res, true);
    if (!is_array($json)) throw new Exception("bad json: ".$res);

    // 本地写一条“客服发言”到日志，便于面板即时刷新
    $log = [
        'time' => date('Y-m-d H:i:s'),
        'userid' => $userid,
        'appid' => APPID,
        'msg' => $msg,
        'from' => 2,
        'event' => $event,
        'kfstate' => null,
        'channel' => $channel,
    ];
    file_put_contents(CALLBACK_LOG, json_encode($log, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);

    echo json_encode(['status'=>1,'http'=>$http,'wx'=>$json]);
} catch (Exception $e) {
    file_put_contents(ERR_LOG, "[".date('c')."] sendmsg_exception ".$e->getMessage().PHP_EOL, FILE_APPEND);
    echo json_encode(['status'=>0,'msg'=>$e->getMessage()]);
}
