<?php
function anchorQuery($db,$sql,$params=[],$write=false) {
    $result=$db->query($sql,$params,$write);
    if($result===false)throw new RuntimeException('Database operation failed');
    return $result;
}
function anchorVenues($db,$mcnId,$lock=false) {
    $org=anchorQuery($db,'SELECT id,mcn_name FROM mcn_organizations WHERE id=? AND status=1'.($lock?' FOR UPDATE':''),[$mcnId]);
    if(!$org)throw new RuntimeException('MCN机构已停用或不存在',403);
    $venues=anchorQuery($db,'SELECT v.id,v.venue_name,v.is_banned FROM mcn_venues mv JOIN venues v ON v.id=mv.venue_id WHERE mv.mcn_id=? AND mv.status=1 ORDER BY v.id'.($lock?' FOR UPDATE':''),[$mcnId]);
    if(!$venues)throw new RuntimeException('当前机构没有有效绑定场地',403);
    $map=[];foreach($venues as $v)$map[(int)$v['id']]=$v;
    return ['organization'=>$org[0],'venues'=>$map];
}
function validateAnchorMove($anchor,$venues,$source,$target) {
    if(!isset($venues[$source],$venues[$target]))throw new RuntimeException('只能在本机构旗下场地之间调配',403);
    if($source===$target)throw new RuntimeException('目标场地与当前场地相同',400);
    if(!$anchor||(int)$anchor['streamer_venue']!==$source)throw new RuntimeException('主播绑定已变化，请刷新重试',409);
    if((int)$anchor['is_streamer']!==1||(int)$anchor['deleted']!==0)throw new RuntimeException('仅可调配正常的主播账号',409);
    if((int)$venues[$source]['is_banned']===1||(int)$venues[$target]['is_banned']===1)throw new RuntimeException('封禁场地不能参与主播调配',403);
}
function transferMcnAnchor($db,$user,$input) {
    $conn=$db->getConnection();
    if(!$conn->begin_transaction())throw new RuntimeException('Transaction start failed');
    try {
        // Recheck the actual MCN administrator under lock, never use the substituted venue UID.
        $actor=anchorQuery($db,"SELECT id FROM admin_users WHERE id=? AND account_type='mcn' AND mcn_id=? AND role_id>0 FOR UPDATE",[(int)$user['id'],(int)$user['mcn_id']]);
        if(!$actor)throw new RuntimeException('MCN权限已变化，请重新登录',403);
        $context=anchorVenues($db,(int)$user['mcn_id'],true);$venues=$context['venues'];
        $source=(int)$input['source_venue_id'];$target=(int)$input['target_venue_id'];$uid=(int)$input['uid'];
        if(!isset($venues[$source],$venues[$target]))throw new RuntimeException('只能在本机构旗下场地之间调配',403);
        $rows=anchorQuery($db,'SELECT uid,nickname,is_streamer,streamer_venue,deleted FROM users WHERE uid=? FOR UPDATE',[$uid]);
        $anchor=$rows[0]??null;validateAnchorMove($anchor,$venues,$source,$target);
        $conflicts=anchorQuery($db,'SELECT mv.id FROM mcn_venues mv JOIN mcn_organizations mo ON mo.id=mv.mcn_id AND mo.status=1 WHERE mv.status=1 AND mv.venue_id IN (?,?) AND mv.mcn_id<>? FOR UPDATE',[$source,$target,(int)$user['mcn_id']]);
        if($conflicts)throw new RuntimeException('场地存在多个MCN归属，请联系管理员核实',409);
        $changed=anchorQuery($db,'UPDATE users SET streamer_venue=? WHERE uid=? AND streamer_venue=? AND is_streamer=1 AND COALESCE(deleted,0)=0',[$target,$uid,$source],true);
        if($changed!==1)throw new RuntimeException('主播状态已变化，请刷新重试',409);
        // App SSO already follows this binding; update existing role4 workspaces immediately.
        anchorQuery($db,"UPDATE admin_users SET venue_id=?,venue_name=?,updated_at=NOW() WHERE uid=? AND role_id=4 AND COALESCE(account_type,'single')='single'",[$target,$venues[$target]['venue_name'],$uid],true);
        $logged=anchorQuery($db,'INSERT INTO mcn_anchor_transfer_logs (mcn_id,anchor_uid,anchor_nickname,source_venue_id,source_venue_name,target_venue_id,target_venue_name,operator_admin_id) VALUES (?,?,?,?,?,?,?,?)',[(int)$user['mcn_id'],$uid,$anchor['nickname'],$source,$venues[$source]['venue_name'],$target,$venues[$target]['venue_name'],(int)$user['id']],true);
        if($logged!==1)throw new RuntimeException('Audit insert failed');
        if(!$conn->commit())throw new RuntimeException('Commit failed');
        return ['uid'=>$uid,'venue_id'=>$target,'venue_name'=>$venues[$target]['venue_name']];
    }catch(Throwable $e){$conn->rollback();throw $e;}
}
