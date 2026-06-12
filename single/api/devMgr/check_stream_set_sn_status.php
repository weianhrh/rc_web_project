<?php
/**
 * /api/devMgr/check_stream_set_sn_status.php
 *
 * 简单单次版：
 * 传入 sn + playing_stream_id
 *
 * 流在线：
 *   1. Redis DB4 写入 key = sn
 *   2. vehicles.status = 在线
 *   3. vehicles.sharing_status = 正在共享
 *
 * 流离线：
 *   1. Redis DB4 删除 key = sn
 *   2. vehicles.status = 离线
 *   3. 不修改 vehicles.sharing_status
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../RedisHelper.php';

/**
 * 即构配置
 * ServerSecret 不要写错，填你原来 stream_state.php 或巡检脚本里同一个。
 */
$ZEGO_APP_ID = 141962251;
$ZEGO_SERVER_SECRET = '5bfaa3399946c98cc6792dd19f9a08ec';
$ZEGO_IS_TEST = false;

/**
 * Redis DB4
 */
$REDIS_HOST = '127.0.0.1';
$REDIS_PORT = 6379;
$REDIS_DB   = 4;

function jsonOut($arr)
{
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 接收参数
 */
$sn = trim((string)($_POST['sn'] ?? $_POST['serial_number'] ?? $_GET['sn'] ?? $_GET['serial_number'] ?? ''));
$playingStreamId = trim((string)($_POST['playing_stream_id'] ?? $_GET['playing_stream_id'] ?? ''));

if ($sn === '') {
    jsonOut([
        'code' => 400,
        'msg'  => '缺少 sn 参数',
    ]);
}

if ($playingStreamId === '') {
    jsonOut([
        'code' => 400,
        'msg'  => '缺少 playing_stream_id 参数',
    ]);
}

/**
 * 构建即构查询流状态 URL
 */
function buildZegoStreamStateUrl($streamId)
{
    global $ZEGO_APP_ID, $ZEGO_SERVER_SECRET, $ZEGO_IS_TEST;

    $streamId = trim((string)$streamId);

    if ($ZEGO_IS_TEST) {
        $prefix = "zegotest-{$ZEGO_APP_ID}-";
        if (strpos($streamId, $prefix) !== 0) {
            $streamId = $prefix . $streamId;
        }
    }

    $timestamp = time();
    $signatureNonce = bin2hex(random_bytes(8));
    $sequence = (string)round(microtime(true) * 1000);

    $signature = md5($ZEGO_APP_ID . $signatureNonce . $ZEGO_SERVER_SECRET . $timestamp);

    $params = [
        'Action'           => 'DescribeRTCStreamState',
        'AppId'            => $ZEGO_APP_ID,
        'SignatureNonce'   => $signatureNonce,
        'Timestamp'        => $timestamp,
        'Signature'        => $signature,
        'SignatureVersion' => '2.0',
        'StreamId'         => $streamId,
        'Sequence'         => $sequence,
    ];

    if ($ZEGO_IS_TEST) {
        $params['IsTest'] = 1;
    }

    return 'https://rtc-api.zego.im/?' . http_build_query($params);
}

/**
 * 查询即构流是否在线
 *
 * 返回：
 * [
 *   'ok' => true/false,
 *   'active' => true/false,
 *   'zego_code' => 0,
 *   'message' => '',
 *   'raw' => []
 * ]
 */
function queryZegoStreamOnline($streamId)
{
    $url = buildZegoStreamStateUrl($streamId);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo) {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'curl error: ' . $err,
            'http'      => $http,
            'raw'       => null,
        ];
    }

    if ($resp === false || trim((string)$resp) === '') {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'empty zego response',
            'http'      => $http,
            'raw'       => null,
        ];
    }

    $zego = json_decode($resp, true);

    if (!is_array($zego)) {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'invalid zego response',
            'http'      => $http,
            'raw'       => $resp,
        ];
    }

    $zegoCode = intval($zego['Code'] ?? -1);
    $zegoMsg  = (string)($zego['Message'] ?? 'unknown');

    /**
     * 和你之前巡检脚本保持一致：
     * Code === 0 认为流在线
     */
    $active = ($zegoCode === 0);

    return [
        'ok'        => true,
        'active'    => $active,
        'zego_code' => $zegoCode,
        'message'   => $active ? 'stream active' : $zegoMsg,
        'http'      => $http,
        'raw'       => $zego,
    ];
}

/**
 * 在线时写入 Redis DB4 的 value
 */
function buildRedisValue($sn)
{
    return [
        'serial_number' => $sn,
        'voltage'       => 7.8,
        'longitude'     => 0,
        'latitude'      => 0,
        'speed'         => 0,
    ];
}

$database = null;
$redis = null;

try {
    $database = new Database();

    $redis = new RedisHelper();
    $redis->connect($REDIS_HOST, $REDIS_PORT, 0);
    $redis->selectDb($REDIS_DB);

    /**
     * 先查询流状态
     */
    $state = queryZegoStreamOnline($playingStreamId);

    /**
     * 查询失败不要误删 Redis，不改数据库
     */
    if (!$state['ok']) {
        jsonOut([
            'code'              => 502,
            'msg'               => '查询即构流状态失败，本次不修改车辆状态，避免误判',
            'sn'                => $sn,
            'playing_stream_id' => $playingStreamId,
            'redis_db'          => $REDIS_DB,
            'zego_message'      => $state['message'],
            'http'              => $state['http'],
        ]);
    }

    /**
     * 在线：写 DB4 + 设置车辆 在线/正在共享
     */
    if ($state['active']) {
        $redisValue = buildRedisValue($sn);

        $redis->save(
            $sn,
            json_encode($redisValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0
        );

        $affected = $database->query(
            "UPDATE vehicles 
             SET status = ?, sharing_status = ? 
             WHERE serial_number = ? 
             LIMIT 1",
            ['在线', '正在共享', $sn],
            true
        );

        jsonOut([
            'code'                => 200,
            'msg'                 => '流在线，已设置 Redis DB4 在线，并设置车辆为在线',
            'sn'                  => $sn,
            'playing_stream_id'   => $playingStreamId,
            'redis_db'            => $REDIS_DB,
            'redis_key'           => $sn,
            'redis_value'         => $redisValue,
            'vehicle_status'      => '在线',
            'vehicle_share_status'=> '正在共享',
            'db_affected'         => intval($affected),
            'zego_code'           => $state['zego_code'],
            'zego_message'        => $state['message'],
            'time'                => date('Y-m-d H:i:s'),
        ]);
    }

/**
 * 离线：只删除 Redis DB4，只更新车辆 status=离线
 * 不再更新 sharing_status=未共享
 */
$delResult = $redis->delete($sn);

$affected = $database->query(
    "UPDATE vehicles 
     SET status = ? 
     WHERE serial_number = ? 
     LIMIT 1",
    ['离线', $sn],
    true
);

jsonOut([
    'code'              => 200,
    'msg'               => '流离线，已删除 Redis DB4，并设置车辆为离线',
    'sn'                => $sn,
    'playing_stream_id' => $playingStreamId,
    'redis_db'          => $REDIS_DB,
    'redis_key'         => $sn,
    'redis_deleted'     => $delResult > 0 ? 1 : 0,
    'vehicle_status'    => '离线',
    'db_affected'       => intval($affected),
    'zego_code'         => $state['zego_code'],
    'zego_message'      => $state['message'],
    'time'              => date('Y-m-d H:i:s'),
]);

} catch (Exception $e) {
    jsonOut([
        'code' => 500,
        'msg'  => '执行异常：' . $e->getMessage(),
        'time' => date('Y-m-d H:i:s'),
    ]);

} finally {
    if ($redis) {
        try {
            $redis->close();
        } catch (Exception $e) {
        }
    }

    if ($database) {
        try {
            $database->close();
        } catch (Exception $e) {
        }
    }
}