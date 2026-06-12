<?php
require_once __DIR__ . '/config.php';

class WXBizMsgCryptLite {
    private $aesKey;

    public function __construct($encodingAesKey) {
        $k = self::safeB64Decode($encodingAesKey);
        if ($k === false || strlen($k) !== 32) {
            throw new Exception("非法 EncodingAESKey，解码后长度不是32字节");
        }
        $this->aesKey = $k;
    }

    // base64 43/44 长度容错
    private static function safeB64Decode($str){
        $pad = 4 - (strlen($str) % 4);
        if ($pad < 4) $str .= str_repeat('=', $pad);
        return base64_decode($str);
    }

    public function decrypt($encrypted) {
        $cipher = base64_decode($encrypted);
        if ($cipher === false) return false;
        $iv = substr($this->aesKey, 0, 16);

        $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) return false;

        // 格式: 16B随机 + 4B网络序len + xml + appid
        $content = substr($decrypted, 16);
        if (strlen($content) < 4) return false;

        $len_list = unpack("N", substr($content, 0, 4));
        $xml_len  = $len_list[1];
        $xml      = substr($content, 4, $xml_len);
        $appid    = substr($content, 4 + $xml_len);

        // 校验 appid
        if (trim($appid) !== APPID) {
            file_put_contents(ERR_LOG, "[".date('c')."] AppIdMismatch: got={$appid} expected=".APPID.PHP_EOL, FILE_APPEND);
            return false;
        }
        return $xml;
    }
}

class WXMsgEncryptor {
    private $aesKey;
    private $appId;

    public function __construct($encodingAesKey, $appId) {
        $k = WXBizMsgCryptLite::safeB64Decode($encodingAesKey);
        if ($k === false || strlen($k) !== 32) {
            throw new Exception("EncodingAESKey无效");
        }
        $this->aesKey = $k;
        $this->appId = $appId;
    }

    public function encrypt($text) {
        $random = openssl_random_pseudo_bytes(16);
        $msg_len = pack("N", strlen($text));
        $msg = $random . $msg_len . $text . $this->appId;

        // PKCS#7
        $block = 32;
        $pad = $block - (strlen($msg) % $block);
        $pad = ($pad === 0) ? $block : $pad;
        $msg .= str_repeat(chr($pad), $pad);

        $iv = substr($this->aesKey, 0, 16);
        $enc = openssl_encrypt($msg, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        return base64_encode($enc);
    }
}
