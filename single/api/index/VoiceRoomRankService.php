<?php
require_once __DIR__ . '/VoiceRoomRankModel.php';
function voiceRankQuery($db,$sql,$params=[]) {
    $result=$db->query($sql,$params);
    if($result===false)throw new RuntimeException('Voice rank query failed');
    return $result;
}
function getVoiceRoomRanking($db) {
    date_default_timezone_set('Asia/Shanghai');
    // Shared across users and the admin preview; never run heavy queries without a cache lock.
    $redis=new Redis();
    $redis->connect('127.0.0.1',6379,.5);$redis->select(0);$redis->setOption(Redis::OPT_READ_TIMEOUT,1);
    $key='voice_rank_live:v1:'.date('Y-m-d-H');
    $cached=$redis->get($key);$data=$cached?json_decode($cached,true):null;
    if(is_array($data)&&isset($data['rows'],$data['generated_timestamp'])&&time()-$data['generated_timestamp']<60)return $data;
    $lockToken=bin2hex(random_bytes(16));
    if(!$redis->set($key.':lock',$lockToken,['nx','ex'=>60])) {
        if(is_array($data)&&isset($data['generated_timestamp'],$data['rows'])&&time()-$data['generated_timestamp']<300)return $data;
        throw new RuntimeException('Voice rank refresh in progress');
    }
    try {
        if(!$db->getConnection()->query('SET SESSION max_execution_time=10000'))throw new RuntimeException('Query budget failed');
        $today=date('Y-m-d 00:00:00');$start=date('Y-m-d 00:00:00',strtotime('-7 days'));$now=date('Y-m-d H:i:s');
        $venues=voiceRankQuery($db,"SELECT id,venue_name,invite_code,venue_status,venue_status_num,venue_tags,is_banned,is_voice_room_banned,is_claw_machine_venue FROM venues WHERE IFNULL(venue_tags,'')<>'内测'");
        $owners=[];$rows=[];
        foreach($venues as $v){
            $code=trim((string)$v['invite_code']);$id=(int)$v['id'];
            if($code!==''&&$code!=='0000')$owners[$code][]=$id;
            $reasons=[];
            if((int)$v['venue_status_num']!==2)$reasons[]='语音房未开放';
            if($v['venue_status']!=='营业中')$reasons[]='非营业状态';
            if((int)$v['is_banned']===1||(int)$v['is_voice_room_banned']===1)$reasons[]='场地或语音房封禁';
            if((int)$v['is_claw_machine_venue']===1)$reasons[]='独立业务不混排';
            $rows[$id]=['venue_id'=>$id,'venue_name'=>$v['venue_name'],'invite_code'=>$code,'current_visible'=>(int)$v['venue_status_num']===2&&!(int)$v['is_banned']&&!(int)$v['is_voice_room_banned'],'excluded'=>$reasons,'referrals7'=>0,'referrals_today'=>0,'paid_users'=>0,'repeat_users'=>0,'completed'=>0,'cancelled'=>0,'available'=>0,'gift_today'=>0,'attribution_warning'=>''];
        }
        $refs=voiceRankQuery($db,"SELECT u.invitation_code,(u.created_at>=?) today,COUNT(*) n FROM users u WHERE u.created_at>=? AND u.created_at<? AND u.invitation_code IS NOT NULL AND u.invitation_code NOT IN ('','0000') AND EXISTS (SELECT 1 FROM orders o WHERE o.uid=u.uid AND o.start_time>=u.created_at AND o.end_time>=u.created_at AND o.end_time<? AND o.status='已完成' AND o.payment_amount>0 AND o.pays_type NOT IN ('能量','礼物') AND (o.note IS NULL OR TRIM(o.note)<>'gift') AND o.serial_number IS NOT NULL AND o.serial_number<>'') GROUP BY u.invitation_code,today",[$today,$start,$now,$now]);
        foreach($refs as $r){$ids=$owners[$r['invitation_code']]??[];if(count($ids)===1)$rows[$ids[0]][(int)$r['today']?'referrals_today':'referrals7']=(int)$r['n'];}
        foreach($rows as &$r)if(count($owners[$r['invite_code']]??[])!==1)$r['attribution_warning']='邀请码缺失或重复，引流不计分';unset($r);
        $paid=voiceRankQuery($db,"SELECT reservation_id,COUNT(*) paid_users,SUM(active_days>=2) repeat_users FROM (SELECT reservation_id,uid,COUNT(DISTINCT DATE(end_time)) active_days FROM orders WHERE end_time>=? AND end_time<? AND uid>0 AND status='已完成' AND payment_amount>0 AND pays_type NOT IN ('能量','礼物') AND (note IS NULL OR TRIM(note)<>'gift') AND serial_number IS NOT NULL AND serial_number<>'' GROUP BY reservation_id,uid) p GROUP BY reservation_id",[$start,$today]);
        foreach($paid as $r)if(isset($rows[$r['reservation_id']]))foreach(['paid_users','repeat_users'] as $k)$rows[$r['reservation_id']][$k]=(int)$r[$k];
        $reservations=voiceRankQuery($db,"SELECT reservation_id,SUM(order_status='已完成') completed,SUM(order_status='已取消') cancelled FROM Reservations WHERE reservation_time>=? AND reservation_time<? AND pay_type NOT IN ('能量','礼物') GROUP BY reservation_id",[$start,$today]);
        foreach($reservations as $r)if(isset($rows[$r['reservation_id']]))foreach(['completed','cancelled'] as $k)$rows[$r['reservation_id']][$k]=(int)$r[$k];
        $devices=voiceRankQuery($db,"SELECT bind_site,COUNT(*) n FROM vehicles WHERE status='在线' AND sharing_status='正在共享' AND IFNULL(is_banned,0)=0 AND IFNULL(Reservation_lock,0)=0 AND IFNULL(driver_id,0)=0 GROUP BY bind_site");
        foreach($devices as $r)if(isset($rows[$r['bind_site']]))$rows[$r['bind_site']]['available']=(int)$r['n'];
        $gifts=voiceRankQuery($db,'SELECT reservation_id,SUM(payment_amount) amount FROM gift_orders WHERE send_time>=? AND send_time<? GROUP BY reservation_id',[$today,$now]);
        foreach($gifts as $r)if(isset($rows[$r['reservation_id']]))$rows[$r['reservation_id']]['gift_today']=(float)$r['amount'];
        foreach($rows as &$r)if(!$r['available'])$r['excluded'][]='暂无在线空闲共享车辆';unset($r);
        $data=['rows'=>buildVoiceRank($rows,date('Y-m-d H')),'generated_at'=>$now,'generated_timestamp'=>time(),'start_date'=>substr($start,0,10),'end_date'=>date('Y-m-d',strtotime('yesterday')),'coverage'=>85,'version'=>'voice-live-v1','personalized'=>false];
        $redis->setex($key,300,json_encode($data,JSON_UNESCAPED_UNICODE));
        return $data;
    }finally{
        $redis->eval("if redis.call('get',KEYS[1]) == ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end",[$key.':lock',$lockToken],1);
    }
}
