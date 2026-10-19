<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');
function historyOut($code,$msg,$data=[]) { echo json_encode(compact('code','msg','data'),JSON_UNESCAPED_UNICODE);exit; }
function historyQuery($db,$sql,$params=[]) { $r=$db->query($sql,$params);if($r===false)throw new RuntimeException('History query failed');return $r; }
function historyDate($v) { $d=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d',$v):false;return $d&&$d->format('Y-m-d')===$v?$d:false; }
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')historyOut(405,'仅支持GET');
    $db=new Database();$token=$_COOKIE['session_token']??'';
    $user=is_string($token)&&$token!==''?$db->getUserBySessionToken($token):null;
    if(!$user)historyOut(1001,'请先登录');
    if(!in_array((int)$user['role_id'],[1,2,3,4],true))historyOut(403,'无权查看经营数据');
    $id=filter_var($_GET['venue_id']??$user['venue_id'],FILTER_VALIDATE_INT);
    if(!$id||$id<=0)historyOut(400,'请先选择具体场地');
    if(!empty($user['is_mcn'])) {
        $allowed=historyQuery($db,'SELECT 1 FROM mcn_venues mv JOIN mcn_organizations mo ON mo.id=mv.mcn_id AND mo.status=1 WHERE mv.mcn_id=? AND mv.venue_id=? AND mv.status=1',[(int)$user['mcn_id'],$id]);
        if(!$allowed)historyOut(403,'无权查看该场地');
    } elseif($id!==(int)$user['venue_id'])historyOut(403,'仅可查看当前账号绑定场地');
    $venue=historyQuery($db,'SELECT id,venue_name,invite_code FROM venues WHERE id=?',[$id]);
    if(!$venue)historyOut(404,'场地不存在');$venue=$venue[0];
    $s=historyDate($_GET['start_date']??date('Y-m-d',strtotime('-6 days')));
    $e=historyDate($_GET['end_date']??date('Y-m-d'));
    if(!$s||!$e||$s>$e||$e>new DateTimeImmutable('today')||$s->diff($e)->days>=90)historyOut(400,'请选择有效日期范围，最多90天，结束日期不能晚于今天');
    $start=$s->format('Y-m-d 00:00:00');$end=$e->modify('+1 day')->format('Y-m-d 00:00:00');
    if(!$db->getConnection()->query('SET SESSION max_execution_time=10000'))throw new RuntimeException('Query budget failed');
    $rows=[];
    for($day=$s;$day<=$e;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$rows[$date]=['date'=>$date,'invites'=>0,'invite_payers'=>0,'paid_users'=>0,'orders'=>0,'revenue'=>'0.00','average'=>'0.00','conversion'=>0.0];}
    $code=trim((string)$venue['invite_code']);$warning='';
    if($code!==''&&$code!=='0000') {
        $owners=historyQuery($db,'SELECT COUNT(*) n FROM venues WHERE invite_code=?',[$code]);
        if((int)$owners[0]['n']!==1)$warning='邀请码重复，当前引流归属无法唯一确认，引流数据暂不统计';
        else {
            $referrals=historyQuery($db,"SELECT DATE(u.created_at) day,COUNT(*) invites,
              SUM(EXISTS(SELECT 1 FROM orders o WHERE o.uid=u.uid AND o.end_time>=u.created_at AND o.start_time>=u.created_at
                AND o.end_time<DATE_ADD(DATE(u.created_at),INTERVAL 1 DAY) AND o.status='已完成' AND o.payment_amount>0
                AND o.pays_type NOT IN ('能量','礼物') AND (o.note IS NULL OR TRIM(o.note)<>'gift'))) invite_payers
              FROM users u WHERE u.invitation_code=? AND u.created_at>=? AND u.created_at<? GROUP BY DATE(u.created_at)",[$code,$start,$end]);
            foreach($referrals as $r){$rows[$r['day']]['invites']=(int)$r['invites'];$rows[$r['day']]['invite_payers']=(int)$r['invite_payers'];}
        }
    }else $warning='当前场地未设置有效邀请码，引流数据暂不统计';
    $orders=historyQuery($db,"SELECT DATE(end_time) day,COUNT(*) orders,COUNT(DISTINCT uid) paid_users,SUM(payment_amount) revenue
        FROM orders WHERE reservation_id=? AND end_time>=? AND end_time<? AND status='已完成' AND payment_amount>0
        AND pays_type NOT IN ('能量','礼物') AND (note IS NULL OR TRIM(note)<>'gift') GROUP BY DATE(end_time)",[$id,$start,$end]);
    foreach($orders as $r){$row=&$rows[$r['day']];$row['orders']=(int)$r['orders'];$row['paid_users']=(int)$r['paid_users'];$row['revenue']=number_format((float)$r['revenue'],2,'.','');unset($row);}
    // Historical amounts are authoritative bill snapshots, never recalculated from mutable attribution.
    foreach($rows as &$row){$row['net_revenue']=null;$row['net_status']='missing';}unset($row);
    $bills=historyQuery($db,'SELECT date,total_revenue,is_checked FROM DailyVenueRevenue WHERE venue_id=? AND date>=? AND date<=?',[$id,$s->format('Y-m-d'),$e->format('Y-m-d')]);
    foreach($bills as $bill){if($bill['date']===date('Y-m-d')||$bill['total_revenue']===null)continue;$rows[$bill['date']]['net_revenue']=number_format((float)$bill['total_revenue'],2,'.','');$rows[$bill['date']]['net_status']=(int)$bill['is_checked']===1?'checked':'bill';}
    if(isset($rows[date('Y-m-d')])){
        $today=date('Y-m-d 00:00:00');$tomorrow=date('Y-m-d 00:00:00',strtotime('+1 day'));
        // Match the production daily-bill task's current calculation exactly.
        $live=historyQuery($db,"SELECT COALESCE(SUM(o.payment_amount),0) gross,
          COALESCE(SUM(CASE WHEN u.invitation_code IS NOT NULL AND u.invitation_code NOT IN ('','0000')
            AND pv.id IS NOT NULL AND pv.id<>o.reservation_id THEN ROUND(o.payment_amount*0.10,2) ELSE 0 END),0) deduction
          FROM orders o LEFT JOIN users u ON u.uid=o.uid LEFT JOIN venues pv ON pv.invite_code=u.invitation_code
          WHERE o.reservation_id=? AND o.end_time>=? AND o.end_time<? AND o.status='已完成' AND o.pays_type!='能量'",[$id,$today,$tomorrow])[0];
        $rows[date('Y-m-d')]['net_revenue']=number_format(max(0,(float)$live['gross']-(float)$live['deduction']),2,'.','');
        $rows[date('Y-m-d')]['net_status']='estimated';
    }
    $summary=['invites'=>0,'invite_payers'=>0,'orders'=>0,'revenue'=>'0.00','average'=>'0.00','conversion'=>0.0,'net_revenue'=>'0.00','net_missing_days'=>0,'net_estimated_days'=>0];$cents=0;$netCents=0;
    foreach($rows as $row){if($row['net_revenue']===null)$summary['net_missing_days']++;else $netCents+=(int)round((float)$row['net_revenue']*100);if($row['net_status']==='estimated')$summary['net_estimated_days']++;}
    $summary['net_revenue']=number_format($netCents/100,2,'.','');
    foreach($rows as &$row){$row['average']=$row['orders']?number_format((float)$row['revenue']/$row['orders'],2,'.',''):'0.00';$row['conversion']=$row['invites']?round(100*$row['invite_payers']/$row['invites'],1):0.0;foreach(['invites','invite_payers','orders'] as $k)$summary[$k]+=$row[$k];$cents+=(int)round((float)$row['revenue']*100);}unset($row);
    $summary['revenue']=number_format($cents/100,2,'.','');$summary['average']=$summary['orders']?number_format($cents/100/$summary['orders'],2,'.',''):'0.00';$summary['conversion']=$summary['invites']?round(100*$summary['invite_payers']/$summary['invites'],1):0.0;
    historyOut(0,'ok',['venue_id'=>$id,'venue_name'=>$venue['venue_name'],'invite_code'=>$code,'start_date'=>$s->format('Y-m-d'),'end_date'=>$e->format('Y-m-d'),'generated_at'=>date('Y-m-d H:i:s'),'warning'=>$warning,'summary'=>$summary,'rows'=>array_reverse(array_values($rows))]);
}catch(Throwable $ex){error_log('VenueHistory: '.$ex->getMessage());historyOut(500,'查询失败，请稍后重试');}
