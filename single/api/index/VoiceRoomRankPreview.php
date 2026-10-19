<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/VoiceRoomRankService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new RuntimeException('仅支持GET',405);
    $db=new Database();$token=$_COOKIE['session_token']??'';
    $admin=is_string($token)&&$token!==''?$db->getUserBySessionToken($token):null;
    if(!$admin)throw new RuntimeException('请先登录',1001);
    if((int)$admin['role_id']!==1)throw new RuntimeException('仅超级管理员可查看',403);
    echo json_encode(['code'=>0,'msg'=>'ok','data'=>getVoiceRoomRanking($db)],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $code=in_array($e->getCode(),[405,1001,403],true)?$e->getCode():500;
    if($code===500)error_log('VoiceRoomRankPreview: '.$e->getMessage());
    echo json_encode(['code'=>$code,'msg'=>$code===500?'排名生成失败，请稍后重试':$e->getMessage(),'data'=>[]],JSON_UNESCAPED_UNICODE);
}
