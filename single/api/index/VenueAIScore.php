<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/VenueScoreModel.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Shanghai');
function scoreOut($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function scoreQuery($db, $sql, $params = []) {
    $rows = $db->query($sql, $params);
    if ($rows === false) throw new RuntimeException('Score data query failed');
    return $rows;
}
$db = new Database(); $redis = null; $locked = false;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') scoreOut(405, '仅支持GET查询');
    $token = $_COOKIE['session_token'] ?? '';
    $admin = is_string($token) && $token !== '' ? $db->getUserBySessionToken($token) : null;
    if (!$admin) scoreOut(1001, '请先登录');
    // Match the existing all-venue performance ranking permission.
    if ((int)$admin['role_id'] !== 1) scoreOut(403, '仅超级管理员可以查看平台全部场地评分');
    $days = $_GET['days'] ?? '7';
    if (!is_string($days) || !in_array($days, ['7', '30'], true)) scoreOut(400, '评分周期只支持7日或30日');
    $days = (int)$days;
    $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('yesterday'));
    $date = is_string($endDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) : false;
    if (!$date || $date->format('Y-m-d') !== $endDate || $endDate >= date('Y-m-d')) scoreOut(400, '请选择昨天或更早的完整日期');
    $end = $date->modify('+1 day')->format('Y-m-d 00:00:00');
    $start = $date->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');
    $previousStart = (new DateTimeImmutable($start))->modify('-' . $days . ' days')->format('Y-m-d 00:00:00');
    $key = 'venue_ai_score:v1:' . $endDate . ':' . $days;
    try {
        $redis = new Redis(); $redis->connect('127.0.0.1', 6379, .5); $redis->select(0);
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 1);
        $cached = $redis->get($key);
        $cachedData = $cached ? json_decode($cached, true) : null;
        if (is_array($cachedData) && (empty($_GET['refresh']) || time() - (int)$cachedData['generated_timestamp'] < 60)) {
            $cachedData['cached'] = true; scoreOut(0, 'ok', $cachedData);
        }
        $locked = (bool)$redis->set($key . ':lock', '1', ['nx', 'ex' => 60]);
        if (!$locked) scoreOut(409, '评分正在生成，请稍后刷新');
    } catch (Throwable $e) { $redis = null; }
    if (!$db->getConnection()->query('SET SESSION max_execution_time = 15000')) throw new RuntimeException('Query timeout setup failed');
    $venues = scoreQuery($db, 'SELECT id, venue_name, image_url, invite_code, venue_status, venue_tags, venue_level, is_claw_machine_venue, is_banned FROM venues');
    $registrations = scoreQuery($db,
        "SELECT invitation_code, COUNT(*) AS registrations FROM users
         WHERE created_at >= ? AND created_at < ? AND invitation_code IS NOT NULL
           AND invitation_code <> '' AND invitation_code <> '0000'
         GROUP BY invitation_code", [$start, $end]);
    $records = scoreQuery($db,
        "SELECT (o.end_time >= ?) AS is_current, o.reservation_id AS venue_id, o.uid,
                COUNT(*) AS order_count, SUM(o.payment_amount) AS amount,
                COUNT(DISTINCT DATE(o.end_time)) AS active_days, MIN(o.end_time) AS first_paid_at,
                u.invitation_code, u.created_at
         FROM orders o
         LEFT JOIN users u ON u.uid = o.uid
         INNER JOIN venues v ON v.id = o.reservation_id AND v.is_claw_machine_venue = 0
         WHERE o.end_time >= ? AND o.end_time < ?
           AND o.status = '已完成' AND o.payment_amount > 0
           AND o.pays_type NOT IN ('能量', '礼物')
           AND (o.note IS NULL OR TRIM(o.note) <> 'gift')
           AND o.serial_number IS NOT NULL AND o.serial_number <> ''
         GROUP BY is_current, o.reservation_id, o.uid, u.invitation_code, u.created_at",
        [$start, $previousStart, $end]);
    $result = buildVenueScores($venues, $registrations, $records, $start, $end);
    $result['range'] = ['start_date' => substr($start, 0, 10), 'end_date' => $endDate, 'days' => $days,
        'previous_start_date' => substr($previousStart, 0, 10), 'previous_end_date' => (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d')];
    $result['generated_at'] = date('Y-m-d H:i:s'); $result['generated_timestamp'] = time(); $result['cached'] = false;
    if ($redis) $redis->setex($key, 300, json_encode($result, JSON_UNESCAPED_UNICODE));
    if ($locked && $redis) { $redis->del($key . ':lock'); $locked = false; }
    scoreOut(0, 'ok', $result);
} catch (Throwable $e) {
    if ($locked && $redis) $redis->del($key . ':lock');
    error_log('VenueAIScore: ' . $e->getMessage());
    scoreOut(500, '评分生成失败，请稍后重试');
}
