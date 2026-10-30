<?php
require_once __DIR__.'/../Database.php';
require_once __DIR__.'/VoiceRoomRecommendation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
date_default_timezone_set('Asia/Shanghai');
$db = null; $transaction = false;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) { throw new RuntimeException('不支持的请求方式',405); }
    $db = new Database();
    $token = $_COOKIE['session_token'] ?? '';
    $admin = is_string($token) && $token !== '' ? $db->getUserBySessionToken($token) : null;
    if (!$admin || empty($admin['id'])) { throw new RuntimeException('请先登录',1001); }
    if ((int)$admin['role_id'] !== 1) { throw new RuntimeException('仅超级管理员可管理推荐位',403); }
    $csrf = hash_hmac('sha256', 'voice_recommendation', $token);
    if ($method === 'GET') {
        $venues = voiceRecommendationQuery($db, "SELECT v.id,v.venue_name,v.venue_status,v.venue_status_num,v.is_banned,v.is_voice_room_banned,r.priority,r.starts_at,r.ends_at,r.enabled,COALESCE(r.revision,0) revision,r.reason FROM venues v LEFT JOIN voice_room_recommendations r ON r.venue_id=v.id WHERE IFNULL(v.venue_tags,'')<>'内测' AND IFNULL(v.is_claw_machine_venue,0)=0 ORDER BY v.id");
        $logs = voiceRecommendationQuery($db, 'SELECT l.id,l.venue_id,v.venue_name,l.operator_admin_id,l.action,l.before_json,l.after_json,l.created_at FROM voice_room_recommendation_logs l LEFT JOIN venues v ON v.id=l.venue_id ORDER BY l.id DESC LIMIT 100');
        $data = ['venues'=>$venues,'logs'=>$logs,'csrf_token'=>$csrf,'server_time'=>date('Y-m-d H:i:s'),'server_timestamp'=>time()];
        $msg = 'ok';
    } else {
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' || !hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { throw new RuntimeException('请求校验失败，请刷新',403); }
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) { throw new RuntimeException('请使用JSON提交',415); }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        $input = strlen($raw) <= 4096 ? json_decode($raw,true) : null;
        if (!is_array($input)) { throw new RuntimeException('参数无效',400); }
        foreach (['venue_id','expected_revision'] as $key) {
            if (!isset($input[$key]) || !(is_int($input[$key]) || is_string($input[$key])) || !ctype_digit((string)$input[$key]) || strlen((string)$input[$key]) > 10) { throw new RuntimeException('场地或版本无效',400); }
        }
        $venueId = (int)$input['venue_id'];
        $action = $input['action'] ?? '';
        if ($venueId <= 0 || !in_array($action,['set','cancel'],true)) { throw new RuntimeException('请选择场地及操作',400); }
        $reason = $input['reason'] ?? '';
        if (!is_string($reason) || trim($reason)==='' || mb_strlen(trim($reason),'UTF-8')>200) { throw new RuntimeException('请填写200字以内的操作原因',400); }
        $reason = trim($reason);
        if ($action === 'set') {
            foreach (['minutes'=>[1,10080], 'priority'=>[1,100]] as $key=>$range) {
                if (!isset($input[$key]) || !(is_int($input[$key]) || is_string($input[$key])) || !ctype_digit((string)$input[$key]) || strlen((string)$input[$key])>5 || (int)$input[$key]<$range[0] || (int)$input[$key]>$range[1]) { throw new RuntimeException('时长须为1至10080分钟，优先级须为1至100',400); }
            }
        }
        $connection = $db->getConnection();
        if (!$connection->begin_transaction()) { throw new RuntimeException('Transaction failed'); }
        $transaction = true;
        $actor = voiceRecommendationQuery($db,'SELECT role_id FROM admin_users WHERE id=? AND session_token=? FOR UPDATE',[(int)$admin['id'],$token]);
        if (!$actor || (int)$actor[0]['role_id']!==1) { throw new RuntimeException('权限已变更，请重新登录',403); }
        $venue = voiceRecommendationQuery($db, 'SELECT id,is_banned,is_voice_room_banned,is_claw_machine_venue,venue_tags FROM venues WHERE id=? FOR UPDATE',[$venueId]);
        if (!$venue) { throw new RuntimeException('场地不存在',400); }
        if ($action==='set' && ((int)$venue[0]['is_banned']===1 || (int)$venue[0]['is_voice_room_banned']===1 || (int)$venue[0]['is_claw_machine_venue']===1 || $venue[0]['venue_tags']==='内测')) { throw new RuntimeException('封禁、内测或独立业务场地不可设置推荐',400); }
        $old = voiceRecommendationQuery($db, 'SELECT * FROM voice_room_recommendations WHERE venue_id=? FOR UPDATE',[$venueId]);
        $old = $old[0] ?? null;
        if ((int)($old['revision'] ?? 0) !== (int)$input['expected_revision']) { throw new RuntimeException('设置已被其他操作更新，请刷新后重试',409); }
        if ($action==='cancel' && (!$old || !(int)$old['enabled'])) { throw new RuntimeException('该场地无可取消的推荐',409); }
        $now = date('Y-m-d H:i:s');
        $after = ['venue_id'=>$venueId,'priority'=>$action==='set'?(int)$input['priority']:(int)$old['priority'],'starts_at'=>$action==='set'?$now:$old['starts_at'],'ends_at'=>$action==='set'?date('Y-m-d H:i:s',time()+(int)$input['minutes']*60):$old['ends_at'],'enabled'=>$action==='set'?1:0,'revision'=>(int)($old['revision'] ?? 0)+1,'reason'=>$reason,'operator_admin_id'=>(int)$admin['id'],'updated_at'=>$now];
        voiceRecommendationQuery($db, 'INSERT INTO voice_room_recommendations (venue_id,priority,starts_at,ends_at,enabled,revision,reason,operator_admin_id,updated_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE priority=VALUES(priority),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),enabled=VALUES(enabled),revision=VALUES(revision),reason=VALUES(reason),operator_admin_id=VALUES(operator_admin_id),updated_at=VALUES(updated_at)',array_values($after),true);
        if (voiceRecommendationQuery($db, 'INSERT INTO voice_room_recommendation_logs (venue_id,operator_admin_id,action,before_json,after_json,created_at) VALUES (?,?,?,?,?,?)',[$venueId,(int)$admin['id'],$action,$old?json_encode($old,JSON_UNESCAPED_UNICODE):null,json_encode($after,JSON_UNESCAPED_UNICODE),$now],true)!==1) { throw new RuntimeException('Audit failed'); }
        if (!$connection->commit()) { throw new RuntimeException('Commit failed'); }
        $transaction = false;
        $redis = voiceRecommendationRedis();
        if ($redis) { try { $redis->del('voice_recommendation:v1:snapshot'); } catch (Throwable $e) {} }
        $data = $after;
        $msg = $action==='set'?'推荐已设置':'推荐已取消';
    }
    echo json_encode(['code'=>0,'msg'=>$msg,'data'=>$data],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($transaction) { $db->getConnection()->rollback(); }
    $code = in_array($e->getCode(),[400,403,405,409,415,1001],true)?$e->getCode():500;
    if ($code===500) { error_log('VoiceRecommendationAdmin: '.$e->getMessage()); }
    echo json_encode(['code'=>$code,'msg'=>$code===500?'操作失败，请稍后重试':$e->getMessage(),'data'=>[]],JSON_UNESCAPED_UNICODE);
}
