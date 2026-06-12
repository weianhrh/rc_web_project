<?php
define("APPID", "NeqeuQ8wXrfGtM3");
define("TOKEN", "B6oX3kGGg3NjhmQcm1G9Wxe3Aa0V2z");
define("EncodingAESKey", "tcP4in3fmhhwXGucfVTolzc9cMDYzga3IzLsPn1LPfk");

header("Content-Type: text/plain");

// 解密类
class WXBizMsgCryptLite {
    private $aesKey;

    public function __construct($encodingAesKey) {
        $key = base64_decode($encodingAesKey . "=");
        if (strlen($key) !== 32) {
            throw new Exception("非法 AES Key，长度不为 32 字节");
        }
        $this->aesKey = $key;
    }

    public function decrypt($encrypted) {
        $ciphertext_dec = base64_decode($encrypted);
        $iv = substr($this->aesKey, 0, 16);
        $decrypted = openssl_decrypt($ciphertext_dec, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);

        if (!$decrypted) return false;

        $content = substr($decrypted, 16);
        $len_list = unpack("N", substr($content, 0, 4));
        $xml_len = $len_list[1];
        $xml_content = substr($content, 4, $xml_len);
        return $xml_content;
    }
}

// 加密类（用于查询客服状态）
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

// 查询微信客服状态
function getWxKefuState($openid) {
    $appid = APPID;
    $token = TOKEN;
    $aesKey = EncodingAESKey;

    $xml = "<xml>
        <appid><![CDATA[{$appid}]]></appid>
        <openid><![CDATA[{$openid}]]></openid>
    </xml>";

    $encryptor = new WXMsgEncryptor($aesKey, $appid);
    $encrypted = $encryptor->encrypt($xml);

    $url = "https://chatbot.weixin.qq.com/openapi/kefustate/get/" . $token;

    $payload = json_encode(['encrypt' => $encrypted], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) {
        return ['err' => true, 'msg' => "curl错误：$errno"];
    }

    $data = json_decode($res, true);
    file_put_contents(__DIR__ . '/wx_kefustate_debug.log', json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND); // 调试日志

    if (!isset($data['kefustate'])) {
        return ['err' => true, 'msg' => '接口返回异常', 'raw' => $data];
    }

    return [
        'err' => false,
        'kefustate' => $data['kefustate'], // on/off
        'status' => $data['status'] ?? ''
    ];
}
function changeWxKefuState($openid, $state) {
    $appid = APPID;
    $aesKey = EncodingAESKey;
    $token = TOKEN;

    $xml = "<xml>
      <appid><![CDATA[{$appid}]]></appid>
      <openid><![CDATA[{$openid}]]></openid>
      <kefustate><![CDATA[{$state}]]></kefustate>
    </xml>";

    $encryptor = new WXMsgEncryptor($aesKey, $appid);
    $encrypted = $encryptor->encrypt($xml);

    $payload = json_encode(['encrypt' => $encrypted], JSON_UNESCAPED_UNICODE);
    $url = "https://chatbot.weixin.qq.com/openapi/kefustate/change/{$token}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    file_put_contents(__DIR__ . '/wx_kefchange_debug.log', json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND); // 调试日志
    return json_decode($res, true);
}
// 读取 POST 数据
$rawPostData = file_get_contents("php://input");
file_put_contents(__DIR__ . '/debug_raw_input.log', $rawPostData . PHP_EOL, FILE_APPEND);

$data = json_decode($rawPostData, true);
if (!isset($data['encrypted'])) {
    http_response_code(400);
    exit("Missing 'encrypted' field");
}

$encrypted = $data['encrypted'];

try {
    $crypt = new WXBizMsgCryptLite(EncodingAESKey);
    $xml = $crypt->decrypt($encrypted);
    if (!$xml) {
        http_response_code(400);
        exit("解密失败，请检查 EncodingAESKey 是否正确");
    }

    $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

    $userid    = (string)$xmlObj->userid;
    $appid     = (string)$xmlObj->appid;
    $msg       = (string)$xmlObj->content->msg;
    $from      = (int)$xmlObj->from;
    $event     = isset($xmlObj->event) ? (string)$xmlObj->event : '';
    $kfstate   = isset($xmlObj->kfstate) ? (int)$xmlObj->kfstate : null;
    $channel   = (int)$xmlObj->channel;
    $time      = (int)$xmlObj->createtime;
    $assessment = isset($xmlObj->assessment) ? (int)$xmlObj->assessment : null;

    $customerInfo = isset($xmlObj->customerInfo) ? [
        'name'   => (string)$xmlObj->customerInfo->name,
        'avatar' => (string)$xmlObj->customerInfo->avatar,
        'openid' => (string)$xmlObj->customerInfo->openid
    ] : [];

    // 如果是用户消息，查询客服接入状态
    if ($from === 0 && empty($event)) {
        $state = getWxKefuState($userid);
        if (!$state['err'] && $state['kefustate'] !== 'on') {
            // 自动恢复人工客服状态
            changeWxKefuState($userid, 'personserving');
        }
        if (!$state['err'] && $state['kefustate'] === 'on') {
            file_put_contents(__DIR__ . '/dialog_callback.log', json_encode([
                'time' => date('Y-m-d H:i:s'),
                'userid' => $userid,
                'appid' => $appid,
                'msg' => $msg,
                'from' => $from,
                'event' => $event,
                'channel' => $channel,
                'kfstate' => 1,
                'note' => '已接入客服，机器人停止响应'
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
            exit("用户已接入客服，机器人不再回复");
        }
    }

    // 写入日志
    $log = [
        'time' => date("Y-m-d H:i:s"),
        'userid' => $userid,
        'appid' => $appid,
        'msg' => $msg,
        'from' => $from,
        'event' => $event,
        'kfstate' => $kfstate,
        'channel' => $channel,
        'assessment' => $assessment,
        'customerInfo' => $customerInfo,
        'raw_xml' => $xml,
        'raw_input' => $rawPostData
    ];

    $fp = fopen(__DIR__ . '/dialog_callback.log', 'a');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    echo "success";
} catch (Exception $e) {
    http_response_code(500);
    echo "内部错误: " . $e->getMessage();
}
