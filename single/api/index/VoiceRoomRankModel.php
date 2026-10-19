<?php
require_once __DIR__ . '/VenueScoreModel.php';
function buildVoiceRank($rows, $slot) {
    $pool = array_values(array_filter($rows, function($r) { return !$r['excluded']; }));
    $a7 = venueScorePercentile(array_column($pool, 'referrals7'), .9);
    $a0 = venueScorePercentile(array_column($pool, 'referrals_today'), .9);
    foreach ($rows as &$r) {
        $r['referral_points'] = round(.32 * venueScoreVolume($r['referrals7'], $a7) + .08 * venueScoreVolume($r['referrals_today'], $a0), 2);
        // Retention is an observable proxy, not room-entry conversion.
        $r['retention_points'] = $r['paid_users'] > 0 ? round(25 * $r['repeat_users'] / $r['paid_users'] * sqrt($r['paid_users'] / ($r['paid_users'] + 20)), 2) : 0;
        $finalized = $r['completed'] + $r['cancelled'];
        $r['experience_points'] = $finalized > 0 ? round(20 * $r['completed'] / $finalized * sqrt($finalized / ($finalized + 20)), 2) : 0;
        $r['score'] = round(($r['referral_points'] + $r['retention_points'] + $r['experience_points']) / 85 * 100, 1);
        $r['sample_limited'] = $r['paid_users'] < 10 || $finalized < 20;
        $r['new_rank'] = null; $r['old_rank'] = null; $r['display_rank'] = null; $r['slot_type'] = '';
    }
    unset($r);
    $old = array_keys(array_filter($rows, function($r) { return $r['current_visible']; }));
    usort($old, function($a,$b) use($rows) { return ($rows[$b]['referrals_today'] <=> $rows[$a]['referrals_today']) ?: (($rows[$b]['gift_today'] <=> $rows[$a]['gift_today']) ?: ($rows[$b]['venue_id'] <=> $rows[$a]['venue_id'])); });
    foreach ($old as $i=>$key) $rows[$key]['old_rank'] = $i+1;
    $eligible = array_keys(array_filter($rows, function($r) { return !$r['excluded']; }));
    usort($eligible, function($a,$b) use($rows) { return ($rows[$b]['score'] <=> $rows[$a]['score']) ?: (($rows[$b]['referrals7'] <=> $rows[$a]['referrals7']) ?: ($rows[$a]['venue_id'] <=> $rows[$b]['venue_id'])); });
    foreach ($eligible as $i=>$key) $rows[$key]['new_rank'] = $i+1;
    $chosen = array_slice($eligible,0,7); $types=array_fill(0,count($chosen),'综合推荐');
    $remaining = array_values(array_diff($eligible,$chosen));
    $limited = array_values(array_filter($remaining, function($k) use($rows) { return $rows[$k]['paid_users'] < 10; }));
    usort($limited, function($a,$b) use($rows,$slot) { return strcmp(hash('sha256',$slot.':'.$rows[$a]['venue_id']),hash('sha256',$slot.':'.$rows[$b]['venue_id'])); });
    foreach (array_slice($limited,0,2) as $k) { $chosen[]=$k; $types[]='小样本扶持'; }
    $remaining=array_values(array_diff($eligible,$chosen));
    usort($remaining,function($a,$b)use($rows,$slot){return strcmp(hash('sha256','explore:'.$slot.':'.$rows[$a]['venue_id']),hash('sha256','explore:'.$slot.':'.$rows[$b]['venue_id']));});
    if ($remaining && count($chosen)<10) { $chosen[]=$remaining[0]; $types[]='探索轮换'; }
    foreach ($eligible as $k) if(count($chosen)<10 && !in_array($k,$chosen,true)) { $chosen[]=$k; $types[]='综合补位'; }
    foreach ($chosen as $i=>$k) { $rows[$k]['display_rank']=$i+1; $rows[$k]['slot_type']=$types[$i]; }
    usort($rows,function($a,$b){return (($a['display_rank']??9999)<=>($b['display_rank']??9999)) ?: (($a['new_rank']??9999)<=>($b['new_rank']??9999)) ?: ($a['venue_id']<=>$b['venue_id']);});
    return $rows;
}
