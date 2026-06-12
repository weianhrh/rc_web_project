<?php
// ws_server.php
require_once '/www/wwwroot/open.rcwulian.cn/single/api/Database.php';
require_once '/www/wwwroot/open.rcwulian.cn/single/api/RedisHelper.php';

use Workerman\Worker;
use Workerman\Timer;

require_once '/www/wwwroot/open.rcwulian.cn/vendor/autoload.php';

// === 用户 -> 连接 映射 ===
$uidConns = [];   // uid => connection
$connUids = [];   // connection->id => uid

// 校验 token，返回 uid（支持 users.token / admin_users.session_token）
function auth_and_get_uid($token){
  $db = new Database();
  // 优先普通用户
  $u = $db->query("SELECT uid FROM users WHERE token = ? LIMIT 1", [$token]);
  if ($u && isset($u[0]['uid'])) return intval($u[0]['uid']);
  // 再查客服
  $a = $db->query("SELECT uid FROM admin_users WHERE session_token = ? AND session_expires>NOW() LIMIT 1", [$token]);
  if ($a && isset($a[0]['uid'])) return intval($a[0]['uid']);
  return 0;
}

// Redis：订阅 chat:uid:{uid}，有消息就向该 uid 的连接推送
function start_redis_subscribe(){
  // 采用 phpredis（你的 RedisHelper 封装）做订阅循环
  $rh = new RedisHelper();
  $rh->connect('127.0.0.1', 6379, 0);
  $r = $rh->getNative();

  // 每 5 秒返回一次，方便做心跳
  $r->setOption(Redis::OPT_READ_TIMEOUT, 5);

  // 订阅所有在线用户的频道不现实；这里订阅一个模式，收到后判断发给谁
  // 如果你的 phpredis 开了 psubscribe 的消息回调，就用 pubSubLoop 更优雅
  $ps = $r->pubSubLoop();
  $ps->psubscribe('chat:uid:*');

  global $uidConns;
  foreach ($ps as $m) {
    if ($m->kind !== 'pmessage') continue;
    $chan = $m->channel;     // chat:uid:12345
    $payload = $m->payload;  // 由 send_message.php 发布的 JSON
    if (!preg_match('#^chat:uid:(\d+)$#', $chan, $mm)) continue;
    $uid = intval($mm[1]);
    if (isset($uidConns[$uid])) {
      // 推给在线用户（可能多个端）
      $data = $payload;
      $uidConns[$uid]->send($data);
    }
  }
}

// 启动 Redis 订阅协程（另一个进程里）
$redisWorker = new Worker();
$redisWorker->name = 'chat-redis-sub';
$redisWorker->count = 1;
$redisWorker->onWorkerStart = function() {
  start_redis_subscribe();
};

// WebSocket 服务器
$ws = new Worker('websocket://0.0.0.0:2346');
$ws->name  = 'chat-ws';
$ws->count = 1;   // 1 个进程即可，够用；流量大再加
$ws->onConnect = function($connection){
  // 15s 内必须携带 token 完成认证
  $connection->auth_ok = false;
  $connection->last_ping = time();

  // 握手参数取 token（?token=xxx），也兼容首包 JSON {type:"auth",token:""}
  $query = $connection->httpRequest->getQuery();
  if (isset($query['token'])) {
    $token = $query['token'];
    $uid = auth_and_get_uid($token);
    if ($uid > 0) {
      $connection->auth_ok = true;
      $connection->uid = $uid;
      global $uidConns, $connUids;
      $uidConns[$uid] = $connection;
      $connUids[$connection->id] = $uid;
      $connection->send(json_encode(['event'=>'hello','uid'=>$uid,'ok'=>1], JSON_UNESCAPED_UNICODE));
      return;
    }
  }
  // 等待首包再认证
};

$ws->onMessage = function($connection, $data){
  global $uidConns, $connUids;

  // 首包携带认证
  if (!$connection->auth_ok) {
    $obj = json_decode($data, true);
    $token = $obj['token'] ?? '';
    $uid = $token ? auth_and_get_uid($token) : 0;
    if ($uid > 0) {
      $connection->auth_ok = true;
      $connection->uid = $uid;
      $uidConns[$uid] = $connection;
      $connUids[$connection->id] = $uid;
      $connection->send(json_encode(['event'=>'hello','uid'=>$uid,'ok'=>1], JSON_UNESCAPED_UNICODE));
    } else {
      $connection->send(json_encode(['event'=>'error','msg'=>'unauthorized']));
      $connection->close();
    }
    return;
  }

  // 心跳
  $connection->last_ping = time();
  $obj = json_decode($data, true);
  if (!$obj) return;
  if (($obj['type'] ?? '') === 'ping') {
    $connection->send(json_encode(['event'=>'pong']));
    return;
  }

  // 如果你想支持客户端通过 WS 直接发消息，也可以在这里处理 type=send
  // 为了简洁，仍旧建议用你现有的 HTTP send_message.php
};

$ws->onClose = function($connection){
  global $uidConns, $connUids;
  if (isset($connUids[$connection->id])) {
    $uid = $connUids[$connection->id];
    unset($connUids[$connection->id]);
    if (isset($uidConns[$uid]) && $uidConns[$uid] === $connection) {
      unset($uidConns[$uid]);
    }
  }
};

// 定时任务：每 20s 主动给在线连接发一条 ping，保持链路活跃
$ws->onWorkerStart = function() use ($ws){
  Timer::add(20, function() use ($ws){
    foreach ($ws->connections as $con) {
      if ($con->auth_ok ?? false) {
        $con->send(json_encode(['event'=>'ping']));
      }
    }
  });
};

Worker::runAll();
