<?php
// Pure scoring functions: no database writes or business-level changes.
function venueScorePercentile($values, $p) {
    $values = array_values(array_filter($values, function ($v) { return is_numeric($v) && $v > 0; }));
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $position = (count($values) - 1) * $p;
    $lo = (int)floor($position); $hi = (int)ceil($position);
    return (float)$values[$lo] + ($values[$hi] - $values[$lo]) * ($position - $lo);
}
function venueScoreVolume($value, $anchor) {
    if ($value <= 0 || $anchor <= 0) return 0.0;
    return min(100.0, 100 * log(1 + $value) / log(1 + $anchor));
}
function venueScoreQuality($successes, $trials, $prior, $anchor) {
    if ($successes <= 0 || $trials <= 0 || $anchor <= 0) return 0.0;
    $smoothed = ($successes + 20 * $prior) / ($trials + 20);
    return min(100.0, 100 * $smoothed / $anchor * sqrt($trials / ($trials + 20)));
}
function buildVenueScores($venues, $registrations, $records, $start, $end) {
    $codeOwners = []; $rows = [];
    foreach ($venues as $v) {
        $code = trim((string)$v['invite_code']);
        if ($code !== '' && $code !== '0000') $codeOwners[$code][] = (int)$v['id'];
        if ($v['venue_status'] !== '营业中' || $v['venue_tags'] === '内测') continue;
        $id = (int)$v['id'];
        $rows[$id] = [
            'venue_id' => $id, 'venue_name' => $v['venue_name'], 'image_url' => $v['image_url'],
            'business_level' => $v['venue_level'], 'invite_code' => $code,
            'special_business' => (int)$v['is_claw_machine_venue'] === 1,
            'is_banned' => (int)$v['is_banned'] === 1,
            'registrations' => 0, 'effective_referrals' => 0, 'paid_users' => 0,
            'orders' => 0, 'revenue' => 0.0, 'previous_revenue' => 0.0,
            'repeat_users' => 0, 'conversion_rate' => 0.0, 'repeat_rate' => 0.0,
            'referral_revenue' => 0.0, 'external_referral_revenue' => 0.0,
            'largest_customer_revenue' => 0.0, 'referral_set' => []
        ];
    }
    foreach ($registrations as $r) {
        $owners = $codeOwners[$r['invitation_code']] ?? [];
        if (count($owners) === 1 && isset($rows[$owners[0]])) {
            $rows[$owners[0]]['registrations'] = (int)$r['registrations'];
        }
    }
    foreach ($records as $r) {
        $venue = (int)$r['venue_id'];
        if ((int)$r['is_current'] !== 1) {
            if (isset($rows[$venue])) $rows[$venue]['previous_revenue'] += (float)$r['amount'];
            continue;
        }
        if (isset($rows[$venue])) {
            $rows[$venue]['orders'] += (int)$r['order_count'];
            $rows[$venue]['revenue'] += (float)$r['amount'];
            if ((int)$r['uid'] > 0) {
                $rows[$venue]['paid_users']++;
                if ((int)$r['active_days'] >= 2) $rows[$venue]['repeat_users']++;
            }
            $rows[$venue]['largest_customer_revenue'] = max($rows[$venue]['largest_customer_revenue'], (float)$r['amount']);
        }
        $owners = $codeOwners[$r['invitation_code']] ?? [];
        if (count($owners) !== 1 || !isset($rows[$owners[0]]) || (int)$r['uid'] <= 0
            || !$r['created_at'] || $r['created_at'] < $start || $r['created_at'] >= $end
            || $r['first_paid_at'] < $r['created_at']) continue;
        $owner = $owners[0];
        $rows[$owner]['referral_set'][(int)$r['uid']] = true;
        $rows[$owner]['referral_revenue'] += (float)$r['amount'];
        if ($venue !== $owner) $rows[$owner]['external_referral_revenue'] += (float)$r['amount'];
    }
    $pool = [];
    foreach ($rows as &$r) {
        $r['effective_referrals'] = count($r['referral_set']); unset($r['referral_set']);
        $r['conversion_rate'] = $r['registrations'] > 0 ? $r['effective_referrals'] / $r['registrations'] : 0;
        $r['repeat_rate'] = $r['paid_users'] > 0 ? $r['repeat_users'] / $r['paid_users'] : 0;
        $r['average_order_value'] = $r['orders'] > 0 ? $r['revenue'] / $r['orders'] : 0;
        $r['growth_rate'] = $r['previous_revenue'] > 0 ? ($r['revenue'] - $r['previous_revenue']) / $r['previous_revenue'] : null;
        $r['sample_status'] = ($r['paid_users'] >= 10 || $r['effective_referrals'] >= 5) ? 'sufficient' : 'limited';
        if (!$r['special_business']) $pool[] = $r;
    }
    unset($r);
    $registrationTotal = array_sum(array_column($pool, 'registrations'));
    $paidTotal = array_sum(array_column($pool, 'paid_users'));
    $conversionPrior = $registrationTotal > 0 ? array_sum(array_column($pool, 'effective_referrals')) / $registrationTotal : 0;
    $repeatPrior = $paidTotal > 0 ? array_sum(array_column($pool, 'repeat_users')) / $paidTotal : 0;
    $conversionAnchors = []; $repeatAnchors = [];
    foreach ($pool as $r) {
        if ($r['registrations'] >= 10 && $r['effective_referrals'] > 0) {
            $conversionAnchors[] = ($r['effective_referrals'] + 20 * $conversionPrior) / ($r['registrations'] + 20);
        }
        if ($r['paid_users'] >= 10 && $r['repeat_users'] > 0) {
            $repeatAnchors[] = ($r['repeat_users'] + 20 * $repeatPrior) / ($r['paid_users'] + 20);
        }
    }
    $anchors = [
        'effective_referrals' => venueScorePercentile(array_column($pool, 'effective_referrals'), .9),
        'paid_users' => venueScorePercentile(array_column($pool, 'paid_users'), .9),
        'orders' => venueScorePercentile(array_column($pool, 'orders'), .9),
        'revenue' => venueScorePercentile(array_column($pool, 'revenue'), .9),
        'conversion_rate' => venueScorePercentile($conversionAnchors, .75) ?: $conversionPrior,
        'repeat_rate' => venueScorePercentile($repeatAnchors, .75) ?: $repeatPrior
    ];
    $dimensions = [
        ['key' => 'effective_referrals', 'name' => '有效付费引流', 'weight' => 25],
        ['key' => 'paid_users', 'name' => '场地付费客户', 'weight' => 20],
        ['key' => 'orders', 'name' => '真实订单', 'weight' => 15],
        ['key' => 'revenue', 'name' => '实付消费', 'weight' => 20],
        ['key' => 'conversion_rate', 'name' => '引流转化', 'weight' => 10],
        ['key' => 'repeat_rate', 'name' => '跨日复购', 'weight' => 10]
    ];
    foreach ($rows as &$r) {
        $r['dimensions'] = []; $r['score'] = 0.0; $r['alerts'] = []; $r['recommendations'] = [];
        if ($r['special_business']) {
            $r['score'] = null; $r['grade'] = '独立业务';
            $r['alerts'][] = '娃娃机等独立业务不与遥控驾驶混排';
            $r['recommendations'][] = '使用独立业务的消费与复购指标评估';
            continue;
        }
        foreach ($dimensions as $d) {
            $key = $d['key'];
            if ($key === 'conversion_rate') $value = venueScoreQuality($r['effective_referrals'], $r['registrations'], $conversionPrior, $anchors[$key]);
            elseif ($key === 'repeat_rate') $value = venueScoreQuality($r['repeat_users'], $r['paid_users'], $repeatPrior, $anchors[$key]);
            else $value = venueScoreVolume($r[$key], $anchors[$key]);
            $points = round($value * $d['weight'] / 100, 2);
            $r['score'] += $points;
            $r['dimensions'][] = array_merge($d, ['value' => $r[$key], 'score' => round($value, 1), 'points' => $points]);
        }
        $r['score'] = round(min(100, $r['score']), 1);
        $r['grade'] = $r['sample_status'] === 'limited' ? '观察' : ($r['score'] >= 85 ? 'S' : ($r['score'] >= 75 ? 'A' : ($r['score'] >= 55 ? 'B' : ($r['score'] >= 35 ? 'C' : 'D'))));
        if ($r['is_banned']) { $r['alerts'][] = '当前场地封禁中'; $r['recommendations'][] = '优先完成违规整改，评分不代表合规状态'; }
        $owners = $codeOwners[$r['invite_code']] ?? [];
        if (count($owners) !== 1) { $r['alerts'][] = '邀请码缺失或重复'; $r['recommendations'][] = '先校正邀请码归属，再判断引流贡献'; }
        if ($r['effective_referrals'] === 0) {
            $r['alerts'][] = '本期无付费引流客户';
            $r['recommendations'][] = '核查邀请码覆盖与外站引流，优先带来首笔真实消费';
        }
        if ($r['registrations'] >= 20 && $r['conversion_rate'] < .10) {
            $r['alerts'][] = '注册到付费转化偏低';
            $r['recommendations'][] = '复核低质量注册来源，优化首次预约和首次驾驶承接';
        }
        if ($r['paid_users'] >= 20 && $r['repeat_rate'] < $repeatPrior * .7) {
            $r['alerts'][] = '跨日复购低于平台基准';
            $r['recommendations'][] = '安排次日用户召回，检查体验、套餐和设备稳定性';
        }
        if ($r['growth_rate'] !== null && $r['previous_revenue'] >= 100 && $r['growth_rate'] < -.3) {
            $r['alerts'][] = '实付消费环比下降超过30%';
            $r['recommendations'][] = '对照前一周期核查开播时长、引流和设备供给变化';
        }
        if ($r['paid_users'] >= 5 && $r['revenue'] > 0 && $r['largest_customer_revenue'] / $r['revenue'] > .5) {
            $r['alerts'][] = '单一客户贡献超过50%';
            $r['recommendations'][] = '扩大真实付费客户覆盖，避免过度依赖单一客户';
        }
        if ($r['sample_status'] === 'limited') {
            $r['alerts'][] = '样本不足，等级待观察';
            $r['recommendations'][] = '结合30日视角复核，不据短期小样本做处罚或分账调整';
        }
        if (!$r['recommendations']) $r['recommendations'][] = '保持有效引流与跨日复购，持续观察下一周期表现';
    }
    unset($r);
    $rows = array_values($rows);
    usort($rows, function ($a, $b) {
        if ($a['score'] === null || $b['score'] === null) {
            if ($a['score'] === null && $b['score'] === null) return $a['venue_id'] <=> $b['venue_id'];
            return $a['score'] === null ? 1 : -1;
        }
        return ($b['score'] <=> $a['score']) ?: (($b['effective_referrals'] <=> $a['effective_referrals']) ?: ($a['venue_id'] <=> $b['venue_id']));
    });
    $rank = 0;
    foreach ($rows as &$r) $r['rank'] = $r['score'] === null ? null : ++$rank;
    unset($r);
    return ['rows' => $rows, 'model' => [
        'version' => 'venue-score-v1.0', 'type' => 'empirical_calibration', 'trained_prediction_model' => false,
        'dimensions' => $dimensions, 'anchors' => $anchors,
        'conversion_prior' => $conversionPrior, 'repeat_prior' => $repeatPrior,
        'peer_count' => count($pool), 'prior_strength' => 20,
        'grade_thresholds' => ['S' => 85, 'A' => 75, 'B' => 55, 'C' => 35, 'D' => 0]
    ]];
}
