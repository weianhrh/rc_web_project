<?php
/**
 * https://open.rcwulian.cn/app/ban/send_room_ban_message.php
 *
 * 日志文件：
 * /app/ban/logs/send_room_ban_message.log
 *
 * 最终发送 ZEGO 自定义信令：
 * MessageContent = ROOM_BAN#1#封禁原因
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ==========================
// 配置区
// ==========================

define('ZEGO_APP_ID', '141962251');

// 建议正式环境使用环境变量；没有环境变量时，填回你原来的 ServerSecret
define('ZEGO_SERVER_SECRET', '5bfaa3399946c98cc6792dd19f9a08ec');

define('ZEGO_RTC_API_URL', 'https://rtc-api.zego.im/');

define('DEFAULT_FROM_USER_ID', 'server_bot_1');

// 可选：内部调用密钥，防止外部乱调；不想校验就保持空字符串
define('INTERNAL_API_KEY', '');

// 日志配置
define('LOG_ENABLE', true);
define('LOG_DIR', __DIR__ . '/log');
define('LOG_FILE', LOG_DIR . '/send_room_ban_message.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB 自动切割

$TRACE_ID = date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);

// ==========================
// 日志函数
// ==========================
function writeLog(string $level, string $message, array $context = []): void
{
    if (!LOG_ENABLE) {
        return;
    }

    try {
        if (!is_dir(LOG_DIR)) {
            @mkdir(LOG_DIR, 0755, true);
        }

        if (is_file(LOG_FILE) && filesize(LOG_FILE) >= LOG_MAX_SIZE) {
            @rename(LOG_FILE, LOG_FILE . '.' . date('Ymd_His'));
        }

        global $TRACE_ID;

        $record = [
            'time'     => date('Y-m-d H:i:s'),
            'level'    => $level,
            'trace_id' => $TRACE_ID,
            'message'  => $message,
            'ip'       => getClientIp(),
            'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri'      => $_SERVER['REQUEST_URI'] ?? '',
            'context'  => sanitizeForLog($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // 日志失败不能影响主流程
    }
}

function sanitizeForLog($data)
{
    $sensitiveKeys = [
        'serversecret',
        'zego_server_secret',
        'internal_key',
        'authorization',
        'token',
        'password',
        'signature',
    ];

    if (is_array($data)) {
        $safe = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            $safe[$key] = $isSensitive ? '***MASKED***' : sanitizeForLog($value);
        }
        return $safe;
    }

    if (is_string($data)) {
        return limitText($data, 2000);
    }

    return $data;
}

function limitText(string $text, int $maxLen = 2000): string
{
    if (strlen($text) <= $maxLen) {
        return $text;
    }

    return substr($text, 0, $maxLen) . '...[TRUNCATED]';
}

function getClientIp(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}

// 捕获 PHP Warning / Notice
set_error_handler(function ($severity, $message, $file, $line) {
    writeLog('PHP_ERROR', 'php_error_handler', [
        'severity' => $severity,
        'error'    => $message,
        'file'     => $file,
        'line'     => $line,
    ]);

    return false;
});

// 捕获 Fatal Error
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        writeLog('FATAL', 'php_fatal_error', $error);
    }
});

// ==========================
// 输出函数
// ==========================
function jsonOut(int $code, string $msg, array $data = []): void
{
    global $TRACE_ID;

    $payload = [
        'code'     => $code,
        'msg'      => $msg,
        'trace_id' => $TRACE_ID,
        'data'     => $data,
    ];

    $level = 'INFO';
    if ($code >= 500) {
        $level = 'ERROR';
    } elseif ($code >= 400) {
        $level = 'WARN';
    }

    writeLog($level, 'response', $payload);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==========================
// 读取 POST JSON / Form
// ==========================
function getInputData(): array
{
    $raw = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    writeLog('INFO', 'request_input_start', [
        'content_type' => $contentType,
        'raw_length'   => strlen((string)$raw),
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    $json = json_decode((string)$raw, true);

    if (is_array($json)) {
        writeLog('INFO', 'input_parsed_as_json', [
            'input' => $json,
        ]);

        return $json;
    }

    if ((string)$raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        writeLog('WARN', 'json_decode_failed_use_form_fallback', [
            'json_error' => json_last_error_msg(),
            'raw_length' => strlen((string)$raw),
        ]);
    }

    $form = $_POST ?: [];

    writeLog('INFO', 'input_parsed_as_form', [
        'input' => $form,
    ]);

    return $form;
}

// ==========================
// 校验内部 key，可选
// ==========================
function checkInternalKey(array $input): void
{
    if (INTERNAL_API_KEY === '') {
        writeLog('INFO', 'internal_key_check_skipped');
        return;
    }

    $headerKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    $postKey   = $input['internal_key'] ?? '';

    if ($headerKey !== INTERNAL_API_KEY && $postKey !== INTERNAL_API_KEY) {
        writeLog('WARN', 'internal_key_invalid', [
            'header_key_exists' => $headerKey !== '',
            'post_key_exists'   => $postKey !== '',
        ]);

        jsonOut(403, 'internal_key无效');
    }

    writeLog('INFO', 'internal_key_check_passed');
}

// ==========================
// 发送 ZEGO 自定义信令
// ==========================
function sendCustomCommand(string $roomId, string $fromUserId, string $message): array
{
    $timestamp = time();
    $nonce = generateSignatureNonceHex16();
    $signature = generateSignatureMd5(ZEGO_APP_ID, $nonce, ZEGO_SERVER_SECRET, $timestamp);

    $params = [
        'Action'           => 'SendCustomCommand',
        'AppId'            => (string)ZEGO_APP_ID,
        'SignatureNonce'   => $nonce,
        'Timestamp'        => (string)$timestamp,
        'Signature'        => $signature,
        'SignatureVersion' => '2.0',
        'RoomId'           => $roomId,
        'FromUserId'       => $fromUserId,
        'MessageContent'   => $message,
    ];

    $safeParams = $params;
    $safeParams['Signature'] = '***MASKED***';

    writeLog('INFO', 'zego_request_build', [
        'api'           => ZEGO_RTC_API_URL,
        'params'        => $safeParams,
        'message_bytes' => strlen($message),
    ]);

    // 不传 ToUserId，表示广播给房间内所有用户
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = rtrim(ZEGO_RTC_API_URL, '/') . '/?' . $query;

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
    ]);

    $startTime = microtime(true);
    $raw = curl_exec($curl);
    $costMs = round((microtime(true) - $startTime) * 1000, 2);

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($curl);
    $curlInfo = curl_getinfo($curl);

    curl_close($curl);

    writeLog($curlErr ? 'ERROR' : 'INFO', 'zego_curl_finished', [
        'http_code' => $httpCode,
        'cost_ms'   => $costMs,
        'curl_err'  => $curlErr,
        'curl_info' => [
            'total_time'        => $curlInfo['total_time'] ?? null,
            'namelookup_time'   => $curlInfo['namelookup_time'] ?? null,
            'connect_time'      => $curlInfo['connect_time'] ?? null,
            'starttransfer_time'=> $curlInfo['starttransfer_time'] ?? null,
            'primary_ip'        => $curlInfo['primary_ip'] ?? null,
        ],
        'raw' => (string)$raw,
    ]);

    if ($curlErr) {
        return [
            'success'   => false,
            'error'     => 'cURL错误：' . $curlErr,
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success'   => false,
            'error'     => 'HTTP错误：' . $httpCode,
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ];
    }

    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        writeLog('ERROR', 'zego_response_not_json', [
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ]);

        return [
            'success'   => false,
            'error'     => 'ZEGO响应不是合法JSON',
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ];
    }

    $zegoCode = (int)($data['Code'] ?? -1);
    $zegoMsg  = (string)($data['Message'] ?? '');

    if ($zegoCode !== 0) {
        writeLog('ERROR', 'zego_return_failed', [
            'zego_code' => $zegoCode,
            'zego_msg'  => $zegoMsg,
            'zego'      => $data,
        ]);

        return [
            'success'   => false,
            'error'     => "ZEGO返回失败 Code={$zegoCode}, Message={$zegoMsg}",
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
            'zego'      => $data,
        ];
    }

    writeLog('INFO', 'zego_return_success', [
        'zego' => $data,
    ]);

    return [
        'success'   => true,
        'http_code' => $httpCode,
        'raw'       => (string)$raw,
        'zego'      => $data,
    ];
}

// ==========================
// 生成随机 nonce
// ==========================
function generateSignatureNonceHex16(): string
{
    return bin2hex(random_bytes(8));
}

// ==========================
// ZEGO v2 签名
// Signature = md5(AppId + SignatureNonce + ServerSecret + Timestamp)
// ==========================
function generateSignatureMd5($appId, string $nonce, string $serverSecret, int $timestamp): string
{
    return md5((string)$appId . $nonce . $serverSecret . (string)$timestamp);
}

// ==========================
// 主逻辑
// ==========================
writeLog('INFO', 'request_start');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, '只支持POST');
}

$input = getInputData();

checkInternalKey($input);

if (ZEGO_SERVER_SECRET === '' || ZEGO_SERVER_SECRET === '这里填你的ZEGO_SERVER_SECRET') {
    writeLog('ERROR', 'zego_server_secret_empty');
    jsonOut(500, 'ZEGO_SERVER_SECRET未配置');
}

$roomId = trim((string)($input['room_id'] ?? ''));

$fromUserId = trim((string)($input['from_user_id'] ?? DEFAULT_FROM_USER_ID));
if ($fromUserId === '') {
    $fromUserId = DEFAULT_FROM_USER_ID;
}

$banReason = trim((string)($input['ban_reason'] ?? '订单已被手动结束,请换车驾驶!'));
if ($banReason === '') {
    $banReason = '订单已被手动结束,请换车驾驶!';
}

writeLog('INFO', 'normalized_params', [
    'room_id'      => $roomId,
    'from_user_id' => $fromUserId,
    'ban_reason'   => $banReason,
]);

if ($roomId === '') {
    jsonOut(400, 'room_id不能为空');
}

// 客户端最终收到这个
$message = 'ROOM_BAN#1#' . $banReason;

$messageBytes = strlen($message);

writeLog('INFO', 'room_ban_message_ready', [
    'message'       => $message,
    'message_bytes' => $messageBytes,
]);

if ($messageBytes > 1024) {
    jsonOut(400, '消息过长，ROOM_BAN消息最大1024字节', [
        'message_bytes' => $messageBytes,
    ]);
}

$result = sendCustomCommand($roomId, $fromUserId, $message);

if (!$result['success']) {
    jsonOut(500, 'ROOM_BAN发送失败：' . $result['error'], [
        'room_id'      => $roomId,
        'from_user_id' => $fromUserId,
        'message'      => $message,
        'zego_result'  => $result,
    ]);
}

jsonOut(0, 'ROOM_BAN发送成功', [
    'room_id'      => $roomId,
    'from_user_id' => $fromUserId,
    'message'      => $message,
    'zego_result'  => $result['zego'] ?? [],
]);