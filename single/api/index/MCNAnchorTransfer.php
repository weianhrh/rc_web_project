<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/MCNAnchorTransferService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');
$code=0;$msg='ok';$data=[];
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不支持',405);
    $db=new Database();$token=$_COOKIE['session_token']??'';
    $user=is_string($token)&&$token!==''?$db->getUserBySessionToken($token):null;
    if(!$user||empty($user['id'])||empty($user['role_id']))throw new RuntimeException('请先登录',1001);
    if(empty($user['is_mcn'])||empty($user['mcn_id']))throw new RuntimeException('仅MCN账号可以调配旗下主播',403);
    $csrf=hash_hmac('sha256','mcn_anchor_transfer',$token);
    if($method==='POST') {
        if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site'||!hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new RuntimeException('请求校验失败，请刷新页面',403);
        if(stripos($_SERVER['CONTENT_TYPE']??'','application/json')!==0)throw new RuntimeException('请使用JSON提交',415);
        $raw=file_get_contents('php://input',false,null,0,4097);
        if(strlen($raw)>4096)throw new RuntimeException('请求内容过长',400);
        $input=json_decode($raw,true);
        if(!is_array($input))throw new RuntimeException('请求参数无效',400);
        foreach(['uid','source_venue_id','target_venue_id'] as $key)if(!isset($input[$key])||!(is_int($input[$key])||is_string($input[$key]))||!ctype_digit((string)$input[$key])||(int)$input[$key]<=0)throw new RuntimeException('请选择有效主播和场地',400);
        $data=transferMcnAnchor($db,$user,$input);$msg='主播场地绑定已更新';
    }else {
        $context=anchorVenues($db,(int)$user['mcn_id']);$ids=array_keys($context['venues']);
        $anchors=anchorQuery($db,'SELECT uid,nickname,streamer_venue FROM users WHERE is_streamer=1 AND COALESCE(deleted,0)=0 AND streamer_venue IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY streamer_venue,uid LIMIT 500',$ids);
        $logs=anchorQuery($db,'SELECT id,anchor_uid,anchor_nickname,source_venue_id,source_venue_name,target_venue_id,target_venue_name,operator_admin_id,created_at FROM mcn_anchor_transfer_logs WHERE mcn_id=? ORDER BY id DESC LIMIT 50',[(int)$user['mcn_id']]);
        $data=['organization'=>$context['organization'],'venues'=>array_values($context['venues']),'anchors'=>$anchors,'logs'=>$logs,'csrf_token'=>$csrf];
    }
}catch(Throwable $e){$code=in_array($e->getCode(),[400,403,405,409,415,1001],true)?$e->getCode():500;$msg=$code===500?'操作失败，请刷新后重试':$e->getMessage();if($code===500)error_log('MCNAnchorTransfer: '.$e->getMessage());}
echo json_encode(compact('code','msg','data'),JSON_UNESCAPED_UNICODE);
