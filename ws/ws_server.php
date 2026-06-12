<?php
// ws_server.php  —— 适配 PHP 7.4 + phpredis(无 pubSubLoop)
// 路径按你现有项目
require_once '/www/wwwroot/open.rcwulian.cn/single/api/Database.php';
require_once '/www/wwwroot/open.rcwulian.cn/single/api/RedisHelper.php';

use Workerman\Worker;
use Workerman\Timer;

require_once '/www/wwwroot/open.rcwulian.cn/vendor/autoload.php';

/* ------------------ 连接管理：支持同一 uid 多连接 ------------------ */
$UID_CONNS = [];   // uid => [connId => $connection]
function reg_conn($connection){
  global $UID_CONNS;
  if (empty($connection->uid)) return;
  $id = spl_object_id($connection);
  $uid = (int)$connection->uid;
  if (!isset($UID_CONNS[$uid])) $UID_CONNS[$uid] = [];
  $UID_CONNS[$uid][$id] = $connection;
}
function unreg_conn($connection){
  global $UID_CONNS;
  if (empty($connection->uid)) return;
  $id = spl_object_id($connection);
  $uid = (int)$connection->uid;
  unset($UID_CONNS[$uid][$id]);
  if (empty($UID_CONNS[$uid])) unset($UID_CONNS[$uid]);
}
function push_to_uid($uid, $text){
  global $UID_CONNS;
  if (empty($UID_CONNS[$uid])) return;
  foreach ($UID_CONNS[$uid] as $conn){
    /** @var \Workerman\Connection\TcpConnection $conn */
    if (!empty($conn->websocketHandshake)) {
      $conn->send($text);
    }
  }
}

/* ------------------ 认证：token -> uid ------------------ */
function auth_and_get_uid(string $token): int {
  $db = new Database();
  // 普通用户
  $u = $db->query("SELECT uid FROM users WHERE token = ? LIMIT 1", [$token]);
  if ($u && isset($u[0]['uid'])) return (int)$u[0]['uid'];
  // 客服
  $a = $db->query("SELECT uid FROM admin_users WHERE session_token = ? AND session_expires>NOW() LIMIT 1", [$token]);
  if ($a && isset($a[0]['uid'])) return (int)$a[0]['uid'];
  return 0;
}

/* ------------------ Redis 订阅：兼容旧版 phpredis ------------------ */
function start_redis_subscribe(){
  $worker = new Worker();               // 单独进程跑订阅
  $worker->name  = 'chat-redis-sub';
  $worker->count = 1;

  $worker->onWorkerStart = function(){
    while (true) {
      try {
        $rh = new RedisHelper();
        $rh->connect('127.0.0.1', 6379, 0);          // 第3个参数 DB=0
        /** @var Redis $r */
        $r = $rh->getNative();

        // 读超时永久阻塞（某些版本用 -1；老版本用 0 也可以）
        if (defined('Redis::OPT_READ_TIMEOUT')) {
          $r->setOption(Redis::OPT_READ_TIMEOUT, -1);
        }

        // 用通配订阅：chat:uid:*
        // 位置：start_redis_subscribe() 的回调里
        $r->psubscribe(['chat:uid:*'], function($redis, $pattern, $channel, $payload){
          if (preg_match('~^chat:uid:(\d+)$~', $channel, $m)) {
            $uid = (int)$m[1];
            // 包一层，让前端识别为实时消息
            $out = json_encode([
              'event' => 'message',
              'data'  => json_decode($payload, true) ?: $payload
            ], JSON_UNESCAPED_UNICODE);
            push_to_uid($uid, $out);
          }
        });


        // 若返回（一般是断线），短暂休眠后重连
        sleep(1);
      } catch (\Throwable $e) {
        echo "[redis-sub] ".$e->getMessage()."\n";
        sleep(2);
      }
    }
  };
  $worker->listen();
}

/* ------------------ WebSocket 服务 ------------------ */
$ws = new Worker('websocket://0.0.0.0:2346');
$ws->name  = 'chat-ws';
$ws->count = 1;

// 握手阶段读取 ?token=，不要再访问 $connection->httpRequest（有的环境为 null）
$ws->onWebSocketConnect = function($connection, $http_header){
  $token = $_GET['token'] ?? '';
  if ($token) {
    $uid = auth_and_get_uid($token);
    if ($uid > 0) {
      $connection->uid = $uid;
      $connection->auth_ok = true;
      reg_conn($connection);
      $connection->send(json_encode(['event'=>'hello','uid'=>$uid,'ok'=>1], JSON_UNESCAPED_UNICODE));
      return;
    }
  }
  // 不带 token 或校验失败，允许后续首包再认证；也可以直接拒绝：
  $connection->auth_ok = false;
};

// 首包备用认证 + 心跳处理
$ws->onMessage = function($connection, $data){
  // 首包 JSON {"token": "..."} 认证（作为 onWebSocketConnect 的兜底）
  if (empty($connection->auth_ok)) {
    $obj = json_decode($data, true);
    $token = $obj['token'] ?? '';
    $uid = $token ? auth_and_get_uid($token) : 0;
    if ($uid > 0) {
      $connection->uid = $uid;
      $connection->auth_ok = true;
      reg_conn($connection);
      $connection->send(json_encode(['event'=>'hello','uid'=>$uid,'ok'=>1], JSON_UNESCAPED_UNICODE));
    } else {
      $connection->send(json_encode(['event'=>'error','msg'=>'unauthorized']));
      $connection->close();
    }
    return;
  }

  // 心跳
  $obj = json_decode($data, true);
  if ($obj && ($obj['type'] ?? '') === 'ping') {
    $connection->send(json_encode(['event'=>'pong']));
    return;
  }

  // 其他应用消息（通常建议仍走你的 HTTP send_message.php）
};

// 连接关闭，清理映射
$ws->onClose = function($connection){
  unreg_conn($connection);
};

// 周期性发送 ping，保持链路活跃（文本帧“ping”事件）
$ws->onWorkerStart = function() use ($ws){
  Timer::add(20, function() use ($ws){
    foreach ($ws->connections as $con) {
      if (!empty($con->auth_ok)) {
        $con->send(json_encode(['event'=>'ping']));
      }
    }
  });
};

/* ------------------ 启动：先挂订阅，再跑 WS ------------------ */
start_redis_subscribe();   // 注册订阅 worker
Worker::runAll();
