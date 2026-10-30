<?php
date_default_timezone_set('Asia/Shanghai');
function voiceRecommendationQuery($db, $sql, $params = [], $write = false) {
    $rows = $db->query($sql, $params, $write);
    if ($rows === false) { throw new RuntimeException('Recommendation database operation failed'); }
    return $rows;
}
function voiceRecommendationRedis() {
    try {
        $redis = new Redis();
        if (!$redis->connect('127.0.0.1', 6379, .5) || !$redis->select(0)) { return null; }
        $redis->setOption(Redis::OPT_READ_TIMEOUT, .5);
        return $redis;
    } catch (Throwable $e) { return null; }
}
function voiceRecommendationActive($db, $redis = null, $now = null) {
    $now = $now ?? time();
    $key = 'voice_recommendation:v1:snapshot';
    $rows = null;
    if ($redis) {
        try {
            $cached = json_decode($redis->get($key) ?: 'null', true);
            if (is_array($cached) && isset($cached['rows'], $cached['at']) && $now - $cached['at'] < 5) {
                $rows = $cached['rows'];
            }
        } catch (Throwable $e) { /* Database remains authoritative. */ }
    }
    if ($rows === null) {
        $rows = voiceRecommendationQuery($db, 'SELECT venue_id,priority,starts_at,ends_at,revision FROM voice_room_recommendations WHERE enabled=1 AND starts_at<=? AND ends_at>?', [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now)]);
        if ($redis) {
            try { $redis->setex($key, 5, json_encode(['at'=>$now, 'rows'=>$rows])); } catch (Throwable $e) {}
        }
    }
    $active = [];
    foreach ($rows as $row) {
        // Recheck timestamps even on cache hits, so cached lists cannot extend a recommendation.
        if (strtotime($row['starts_at']) <= $now && strtotime($row['ends_at']) > $now) {
            $active[(int)$row['venue_id']] = $row;
        }
    }
    ksort($active);
    return $active;
}
function voiceRecommendationSignature($active) {
    return substr(hash('sha256', json_encode($active)), 0, 20);
}
function voiceRecommendationSort($rows, $active, $idKey) {
    $positions = [];
    foreach ($rows as $i=>$row) { $positions[(int)$row[$idKey]] = $i; }
    usort($rows, function ($a, $b) use ($active, $positions, $idKey) {
        $idA = (int)$a[$idKey]; $idB = (int)$b[$idKey];
        $recA = $active[$idA] ?? null; $recB = $active[$idB] ?? null;
        if ((bool)$recA !== (bool)$recB) { return $recA ? -1 : 1; }
        if ($recA && $recB) {
            $priority = (int)$recB['priority'] <=> (int)$recA['priority'];
            if ($priority) { return $priority; }
            $start = strcmp($recA['starts_at'], $recB['starts_at']);
            if ($start) { return $start; }
            return $idA <=> $idB;
        }
        return $positions[$idA] <=> $positions[$idB];
    });
    return $rows;
}
function voiceRecommendationPreview($ranking, $active) {
    $eligible = []; $excluded = [];
    foreach ($ranking['rows'] as $row) {
        if ($row['excluded']) { $excluded[] = $row; } else { $eligible[] = $row; }
    }
    $eligible = voiceRecommendationSort($eligible, $active, 'venue_id');
    foreach ($eligible as $i=>&$row) {
        $row['display_rank'] = $i < 10 ? $i + 1 : null;
        if (isset($active[(int)$row['venue_id']])) {
            $row['slot_type'] = '临时推荐至 '.$active[(int)$row['venue_id']]['ends_at'];
        } elseif ($i >= 10) { $row['slot_type'] = ''; }
    }
    unset($row);
    $ranking['rows'] = array_merge($eligible, $excluded);
    return $ranking;
}
