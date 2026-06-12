<?php
define("APPID", "NeqeuQ8wXrfGtM3");
define("TOKEN", "B6oX3kGGg3NjhmQcm1G9Wxe3Aa0V2z");
define("EncodingAESKey", "tcP4in3fmhhwXGucfVTolzc9cMDYzga3IzLsPn1LPfk");

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


?>