<?php
define("APPID", "NeqeuQ8wXrfGtM3");
define("TOKEN", "B6oX3kGGg3NjhmQcm1G9Wxe3Aa0V2z");
define("EncodingAESKey", "tcP4in3fmhhwXGucfVTolzc9cMDYzga3IzLsPn1LPfk");

header('Content-Type: application/json');

class WXMsgEncryptor {
    private $aesKey;
    private $appId;

    public function __construct($encodingAesKey, $appId) {
        $key = base64_decode($encodingAesKey . "=");
        if (strlen($key) !== 32) {
            throw new Exception("EncodingAESKey无效");
        }
        $this->aesKey = $key;
        $this->appId = $appId;
    }

    public function encrypt($text) {
        $random = openssl_random_pseudo_bytes(16);
        $msg_len = pack("N", strlen($text));
        $msg = $random . $msg_len . $text . $this->appId;
        $pad = 32 - (strlen($msg) % 32);
        $msg .= str_repeat(chr($pad), $pad);
        $iv = substr($this->aesKey, 0, 16);
        return base64_encode(openssl_encrypt($msg, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv));
    }
}

// 获取 POST 输入（支持 form-data 或 application/x-www-form-urlencoded）
$userid  = trim($_POST['userid'] ?? '');
$msg     = trim($_POST['msg'] ?? '');
$event = isset($_POST['event']) ? trim($_POST['event']) : '';

// $event   = trim($_POST['event'] ?? 'waiterEnter'); // 可选 waiterEnter / waiterQuit
$channel = intval($_POST['channel'] ?? 7); // 默认为 7：网页 H5
$name    = trim($_POST['kefuname'] ?? '客服小姐姐');
$avatar  = trim($_POST['kefuavatar'] ?? 'https://ruoche.oss-cn-beijing.aliyuncs.com/app/img/002.png');

// ✅ 允许 msg 为空，只要存在 event
if (!$userid || ($msg === '' && $event === '')) {
    echo json_encode(['status' => 0, 'msg' => '缺少用户ID(userid)，或消息内容为空且未指定事件(event)']);
    exit;
}


// 构造 XML
$xml = "<xml>
            <appid><![CDATA[" . APPID . "]]></appid>
            <openid><![CDATA[{$userid}]]></openid>
            <msg><![CDATA[{$msg}]]></msg>
            <channel>{$channel}</channel>
            <event><![CDATA[{$event}]]></event>
            <kefuname><![CDATA[{$name}]]></kefuname>
            <kefuavatar><![CDATA[{$avatar}]]></kefuavatar>
        </xml>";


try {
    $encryptor = new WXMsgEncryptor(EncodingAESKey, APPID);
    $encrypted = $encryptor->encrypt($xml);
    $payload = json_encode(['encrypt' => $encrypted], JSON_UNESCAPED_UNICODE);

    $url = "https://chatbot.weixin.qq.com/openapi/sendmsg/" . TOKEN;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) {
        throw new Exception("CURL 错误码：" . $errno);
    }

    $json = json_decode($res, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 0, 'msg' => '微信接口返回非 JSON 数据', 'raw' => $res]);
    } else {
        // ✅ 添加客服发言模拟记录到 dialog_callback.log（用于前端即时显示）
        $logFile = __DIR__ . '/dialog_callback.log';
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'userid' => $userid,
            'appid' => APPID,
            'msg' => $msg,
            'from' => 2, // 表示客服发言
            'event' => $event,
            'kfstate' => ($event === 'waiterQuit') ? 2 : 1,
            'channel' => $channel,
            'assessment' => null,
            'customerInfo' => [
                'name' => $name,
                'avatar' => $avatar,
                'openid' => null
            ]
        ];
        
        $fp = fopen($logFile, 'a');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        echo json_encode(['status' => 1, 'msg' => '发送成功', 'wx' => $json]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 0, 'msg' => $e->getMessage()]);
}
