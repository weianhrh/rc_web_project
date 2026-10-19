<?php
require_once __DIR__ . '/../Database.php';
date_default_timezone_set('Asia/Shanghai');
$database = new Database();

// -------------------- 参数 --------------------
$period = $_GET['period'] ?? 'day';    // day | week | month
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');
$detail_date = trim($_GET['detail_date'] ?? '');
$detail_start = trim($_GET['detail_start'] ?? '');
$detail_end = trim($_GET['detail_end'] ?? '');

function isValidDateYmd($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
$hasRange = isValidDateYmd($start_date) && isValidDateYmd($end_date);
$hasDetailDate = isValidDateYmd($detail_date);
$hasDetailRange = isValidDateYmd($detail_start) && isValidDateYmd($detail_end);

// 注册统计字段
$timeField  = 'users.created_at';
$pageTitle  = '注册用户统计';
$trendName  = '注册趋势';
$seriesName = '注册总人数';
$inviteCondition = "users.invitation_code IS NOT NULL AND users.invitation_code <> '' AND users.invitation_code <> '0000'";

// -------------------- 列表统计 SQL（day/week/month） --------------------
$where = " WHERE 1=1 ";

if ($hasRange) {
  $where .= " AND {$timeField} >= '{$start_date} 00:00:00'
              AND {$timeField} < DATE_ADD('{$end_date} 00:00:00', INTERVAL 1 DAY) ";
}

$limit = 30;
if ($period === 'week' || $period === 'month') $limit = 12;

if (!$hasRange && $period === 'day') {
  $where .= " AND {$timeField} >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
              AND {$timeField} < DATE_ADD(CURDATE(), INTERVAL 1 DAY) ";
}

switch ($period) {
  case 'week':
    $sql = "
      SELECT
        YEARWEEK({$timeField}, 1) AS label,
        MIN(DATE({$timeField})) AS start_date,
        MAX(DATE({$timeField})) AS end_date,
        COUNT(*) AS total,
        SUM(CASE WHEN {$inviteCondition} THEN 1 ELSE 0 END) AS invite_total,
        SUM(CASE WHEN {$inviteCondition} THEN 0 ELSE 1 END) AS self_total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
  case 'month':
    $sql = "
      SELECT
        DATE_FORMAT({$timeField}, '%Y-%m') AS label,
        COUNT(*) AS total,
        SUM(CASE WHEN {$inviteCondition} THEN 1 ELSE 0 END) AS invite_total,
        SUM(CASE WHEN {$inviteCondition} THEN 0 ELSE 1 END) AS self_total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
  case 'day':
  default:
    $sql = "
      SELECT
        DATE({$timeField}) AS label,
        COUNT(*) AS total,
        SUM(CASE WHEN {$inviteCondition} THEN 1 ELSE 0 END) AS invite_total,
        SUM(CASE WHEN {$inviteCondition} THEN 0 ELSE 1 END) AS self_total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
}

$data = $database->query($sql);

// The trend has an independent, bounded interval; the end is exclusive.
$trendStart = new DateTimeImmutable('today');
$trendEnd = $trendStart->modify('+1 day');
$trendError = '';
$rawTrendStart = $_GET['trend_start'] ?? '';
$rawTrendEnd = $_GET['trend_end'] ?? '';
if ($rawTrendStart !== '' || $rawTrendEnd !== '') {
  $s = is_string($rawTrendStart) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rawTrendStart) : false;
  $e = is_string($rawTrendEnd) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rawTrendEnd) : false;
  if (!$s || !$e || $s->format('Y-m-d\TH:i') !== $rawTrendStart || $e->format('Y-m-d\TH:i') !== $rawTrendEnd) {
    $trendError = '请填写有效的开始和结束时间，已恢复今日范围。';
  } elseif ($e <= $s || $e->getTimestamp() - $s->getTimestamp() > 31 * 86400) {
    $trendError = '结束时间须晚于开始时间，且范围不超过31天，已恢复今日范围。';
  } else {
    $trendStart = $s; $trendEnd = $e;
  }
}
$trendDaily = $trendEnd->getTimestamp() - $trendStart->getTimestamp() > 2 * 86400;
$trendBucketLabel = $trendDaily ? '按天聚合' : '按半小时聚合';
$trendRangeLabel = $trendStart->format('Y-m-d H:i') . ' ~ ' . $trendEnd->format('Y-m-d H:i');
$slots = []; $slotMap = []; $slotCounts = []; $slotInviteCounts = []; $slotSelfCounts = [];
$cursor = $trendDaily ? $trendStart->setTime(0, 0) : $trendStart->setTime((int)$trendStart->format('H'), (int)(floor((int)$trendStart->format('i') / 30) * 30));
while ($cursor < $trendEnd) {
  $key = $cursor->format($trendDaily ? 'Y-m-d' : 'Y-m-d H:i');
  $slotMap[$key] = count($slots);
  $slots[] = $cursor->format($trendDaily ? 'm-d' : 'm-d H:i');
  $slotCounts[] = 0; $slotInviteCounts[] = 0; $slotSelfCounts[] = 0;
  $cursor = $cursor->modify($trendDaily ? '+1 day' : '+30 minutes');
}
$bucketSql = $trendDaily ? "DATE_FORMAT({$timeField}, '%Y-%m-%d')" : "CONCAT(DATE_FORMAT({$timeField}, '%Y-%m-%d %H:'), IF(MINUTE({$timeField}) < 30, '00', '30'))";
$trendRows = $database->query("SELECT {$bucketSql} AS bucket, COUNT(*) AS c,
  SUM(CASE WHEN {$inviteCondition} THEN 1 ELSE 0 END) AS invite_c,
  SUM(CASE WHEN {$inviteCondition} THEN 0 ELSE 1 END) AS self_c
  FROM users WHERE {$timeField} >= ? AND {$timeField} < ?
  GROUP BY bucket ORDER BY bucket", [$trendStart->format('Y-m-d H:i:s'), $trendEnd->format('Y-m-d H:i:s')]);
if ($trendRows === false) {
  $trendError = '趋势数据查询失败，请刷新重试。';
  $todayTotal = $todayInviteTotal = $todaySelfTotal = null;
} else {
  foreach ($trendRows as $r) {
    if (!isset($slotMap[$r['bucket']])) continue;
    $i = $slotMap[$r['bucket']];
    $slotCounts[$i] = (int)$r['c'];
    $slotInviteCounts[$i] = (int)$r['invite_c'];
    $slotSelfCounts[$i] = (int)$r['self_c'];
  }
  $todayTotal = array_sum($slotCounts);
  $todayInviteTotal = array_sum($slotInviteCounts);
  $todaySelfTotal = array_sum($slotSelfCounts);
}

$inviteDetail = [];
$inviteDetailTotal = 0;
$inviteDetailSelfTotal = 0;
$inviteDetailAmountTotal = '0.00';
$inviteDetailOtherVenueAmountTotal = '0.00';
$inviteDetailEstimatedRewardTotal = '0.00';
$detailStartAt = '';
$detailEndAt = '';
$detailTitle = '';
if ($hasDetailRange) {
  $detailStartAt = $detail_start . ' 00:00:00';
  $detailEndAt = date('Y-m-d H:i:s', strtotime($detail_end . ' 00:00:00 +1 day'));
  $detailTitle = $detail_start === $detail_end ? $detail_start : ($detail_start . ' ~ ' . $detail_end);
} elseif ($hasDetailDate) {
  $detailStartAt = $detail_date . ' 00:00:00';
  $detailEndAt = date('Y-m-d H:i:s', strtotime($detail_date . ' 00:00:00 +1 day'));
  $detailTitle = $detail_date;
}
$hasDetail = $detailStartAt !== '' && $detailEndAt !== '';
if ($hasDetail) {
  $todayDate = date('Y-m-d');
  $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
  $detailStartDate = substr($detailStartAt, 0, 10);
  $detailEndDate = date('Y-m-d', strtotime($detailEndAt . ' -1 second'));

  $loggedRewardStartDate = $detailStartDate;
  $loggedRewardEndDate = $detailEndDate < $yesterdayDate ? $detailEndDate : $yesterdayDate;
  if ($loggedRewardStartDate <= $loggedRewardEndDate) {
    $loggedRewardJoinSql = "
      LEFT JOIN (
        SELECT
          vpr.invite_code,
          ROUND(COALESCE(SUM(vpr.reward_amount), 0), 2) AS reward_amount
        FROM venue_promotion_reward_logs vpr
        INNER JOIN users reward_user
                ON reward_user.uid = vpr.invitee_uid
        WHERE vpr.reward_date >= '{$loggedRewardStartDate}'
          AND vpr.reward_date <= '{$loggedRewardEndDate}'
          AND vpr.reward_status IN ('pending', 'settled', 'frozen')
          AND reward_user.created_at >= '{$detailStartAt}'
          AND reward_user.created_at < '{$detailEndAt}'
        GROUP BY vpr.invite_code
      ) logged_reward
        ON logged_reward.invite_code = users.invitation_code
    ";
  } else {
    $loggedRewardJoinSql = "
      LEFT JOIN (
        SELECT '' AS invite_code, 0 AS reward_amount
        WHERE 1 = 0
      ) logged_reward
        ON logged_reward.invite_code = users.invitation_code
    ";
  }

  $todayStartAt = $todayDate . ' 00:00:00';
  $tomorrowStartAt = date('Y-m-d 00:00:00', strtotime('+1 day'));
  $dynamicStartAt = $detailStartAt > $todayStartAt ? $detailStartAt : $todayStartAt;
  $dynamicEndAt = $detailEndAt < $tomorrowStartAt ? $detailEndAt : $tomorrowStartAt;
  if ($dynamicStartAt < $dynamicEndAt) {
    $dynamicRewardJoinSql = "
      LEFT JOIN (
        SELECT
          dynamic_user.invitation_code,
          ROUND(COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0), 2) AS reward_amount
        FROM orders o
        INNER JOIN users dynamic_user
                ON dynamic_user.uid = o.uid
        INNER JOIN venues promotion_venue
                ON promotion_venue.invite_code = dynamic_user.invitation_code
        WHERE o.end_time >= '{$dynamicStartAt}'
          AND o.end_time < '{$dynamicEndAt}'
          AND o.status = '已完成'
          AND o.pays_type != '能量'
          AND (o.note <> 'gift' OR o.note IS NULL)
          AND dynamic_user.created_at >= '{$detailStartAt}'
          AND dynamic_user.created_at < '{$detailEndAt}'
          AND dynamic_user.invitation_code IS NOT NULL
          AND dynamic_user.invitation_code <> ''
          AND dynamic_user.invitation_code <> '0000'
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND promotion_venue.id <> o.reservation_id
        GROUP BY dynamic_user.invitation_code
      ) dynamic_reward
        ON dynamic_reward.invitation_code = users.invitation_code
    ";
  } else {
    $dynamicRewardJoinSql = "
      LEFT JOIN (
        SELECT '' AS invitation_code, 0 AS reward_amount
        WHERE 1 = 0
      ) dynamic_reward
        ON dynamic_reward.invitation_code = users.invitation_code
    ";
  }

  $detailSql = "
    SELECT
      users.invitation_code,
      COUNT(DISTINCT users.uid) AS total,
      COUNT(DISTINCT CASE WHEN od.total_amount > 0 THEN users.uid END) AS consuming_users,
      COALESCE(SUM(od.order_count), 0) AS invited_day_orders,
      CAST(COALESCE(SUM(od.total_amount), 0) AS CHAR) AS invited_day_amount,
      CAST(COALESCE(SUM(CASE
        WHEN COALESCE(v.id, v2.id, 0) > 0
         AND od.reservation_id = COALESCE(v.id, v2.id, 0)
        THEN od.total_amount ELSE 0 END), 0) AS CHAR) AS promotion_venue_amount,
      CAST(COALESCE(SUM(CASE
        WHEN COALESCE(v.id, v2.id, 0) > 0
         AND od.reservation_id <> COALESCE(v.id, v2.id, 0)
        THEN od.total_amount ELSE 0 END), 0) AS CHAR) AS other_venue_amount,
      COALESCE(SUM(CASE
        WHEN COALESCE(v.id, v2.id, 0) > 0
         AND od.reservation_id <> COALESCE(v.id, v2.id, 0)
        THEN od.order_count ELSE 0 END), 0) AS other_venue_orders,
      CAST(ROUND(
        COALESCE(MAX(logged_reward.reward_amount), 0)
        + COALESCE(MAX(dynamic_reward.reward_amount), 0),
        2
      ) AS CHAR) AS estimated_reward_amount,
      COALESCE(v.venue_name, v2.venue_name, '') AS venue_name,
      COALESCE(v.id, v2.id, 0) AS venue_id,
      uic.uid AS inviter_uid,
      COALESCE(inviter.nickname, '') AS inviter_nickname
    FROM users
    LEFT JOIN (
      SELECT
        o.uid,
        o.reservation_id,
        COUNT(*) AS order_count,
        COALESCE(SUM(COALESCE(NULLIF(o.checkout_amount, 0), o.payment_amount, 0)), 0) AS total_amount
      FROM orders o
      WHERE o.start_time >= '{$detailStartAt}'
        AND o.start_time < '{$detailEndAt}'
        AND o.status = '已完成'
        AND (o.pays_type IS NULL OR o.pays_type <> '能量')
        AND (o.note IS NULL OR o.note <> 'gift')
      GROUP BY o.uid, o.reservation_id
    ) od
      ON od.uid = users.uid
    {$loggedRewardJoinSql}
    {$dynamicRewardJoinSql}
    LEFT JOIN venue_invite_codes vic
      ON vic.invite_code = users.invitation_code AND vic.is_enabled = 1
    LEFT JOIN venues v
      ON v.id = vic.venue_id
    LEFT JOIN venues v2
      ON v2.invite_code = users.invitation_code
    LEFT JOIN user_invite_codes uic
      ON uic.invite_code = users.invitation_code
    LEFT JOIN users inviter
      ON inviter.uid = uic.uid
    WHERE {$timeField} >= '{$detailStartAt}'
      AND {$timeField} < '{$detailEndAt}'
      AND {$inviteCondition}
    GROUP BY users.invitation_code, venue_name, venue_id, uic.uid, inviter.nickname
    ORDER BY invited_day_amount DESC, total DESC, users.invitation_code ASC
    LIMIT 300
  ";
  $inviteDetail = $database->query($detailSql) ?: [];
  foreach ($inviteDetail as &$detailRow) {
    $detailRow['invited_day_amount'] = number_format((float)($detailRow['invited_day_amount'] ?? 0), 2, '.', '');
    $detailRow['promotion_venue_amount'] = number_format((float)($detailRow['promotion_venue_amount'] ?? 0), 2, '.', '');
    $detailRow['other_venue_amount'] = number_format((float)($detailRow['other_venue_amount'] ?? 0), 2, '.', '');
    $detailRow['estimated_reward_amount'] = number_format((float)($detailRow['estimated_reward_amount'] ?? 0), 2, '.', '');
    $totalAmount = (float)$detailRow['invited_day_amount'];
    $otherAmount = (float)$detailRow['other_venue_amount'];
    $detailRow['other_venue_rate'] = $totalAmount > 0 ? number_format(($otherAmount / $totalAmount) * 100, 1, '.', '') : '0.0';
    $inviteDetailAmountTotal = number_format((float)$inviteDetailAmountTotal + (float)$detailRow['invited_day_amount'], 2, '.', '');
    $inviteDetailOtherVenueAmountTotal = number_format((float)$inviteDetailOtherVenueAmountTotal + $otherAmount, 2, '.', '');
    $inviteDetailEstimatedRewardTotal = number_format((float)$inviteDetailEstimatedRewardTotal + (float)$detailRow['estimated_reward_amount'], 2, '.', '');
  }
  unset($detailRow);

  $detailSummarySql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN {$inviteCondition} THEN 1 ELSE 0 END) AS invite_total,
      SUM(CASE WHEN {$inviteCondition} THEN 0 ELSE 1 END) AS self_total
    FROM users
    WHERE {$timeField} >= '{$detailStartAt}'
      AND {$timeField} < '{$detailEndAt}'
  ";
  $detailSummary = $database->query($detailSummarySql)[0] ?? [];
  $inviteDetailTotal = (int)($detailSummary['invite_total'] ?? 0);
  $inviteDetailSelfTotal = (int)($detailSummary['self_total'] ?? 0);
}

$database->close();

$slotsJson  = json_encode($slots, JSON_UNESCAPED_UNICODE);
$countsJson = json_encode($slotCounts, JSON_UNESCAPED_UNICODE);
$inviteCountsJson = json_encode($slotInviteCounts, JSON_UNESCAPED_UNICODE);
$selfCountsJson = json_encode($slotSelfCounts, JSON_UNESCAPED_UNICODE);

function activeClass($a, $b) { return $a === $b ? 'layui-btn-normal' : 'layui-btn-primary'; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- Layui -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/layui@2.9.21/dist/css/layui.css">
  <script src="https://cdn.jsdelivr.net/npm/layui@2.9.21/dist/layui.js"></script>

  <!-- ECharts -->
  <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>

  <style>
    :root{
      --primary:#5b8cff;
      --bg:#f6f8fc;
      --card:#fff;
      --text:#111827;
      --muted:#6b7280;
      --border:#e5e7eb;
      --shadow: 0 8px 28px rgba(17,24,39,.06);
      --shadow2: 0 10px 34px rgba(17,24,39,.10);
      --radius: 14px;
    }
    body{ background:var(--bg); }
    .page-wrap{ max-width: 1200px; margin: 18px auto 28px; padding: 0 14px; }

    .topbar{
      display:flex; align-items:center; justify-content:space-between;
      gap:12px; padding: 14px 16px; border-radius: var(--radius);
      background: linear-gradient(135deg, rgba(91,140,255,.16), rgba(255,255,255,1));
      border: 1px solid rgba(91,140,255,.18);
      box-shadow: var(--shadow);
    }
    .topbar .title{
      font-size: 18px; font-weight: 700; color: var(--text);
      display:flex; align-items:center; gap:10px;
    }
    .badge{
      font-size:12px; color:#fff; background: var(--primary);
      padding: 2px 8px; border-radius: 999px;
      box-shadow: 0 6px 18px rgba(91,140,255,.25);
    }

    .panel-grid{ margin-top: 14px; }
    .panel-card{
      border-radius: var(--radius);
      border: 1px solid var(--border);
      background: var(--card);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .panel-hd{
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
      display:flex; align-items:center; justify-content:space-between; gap:10px;
    }
    .panel-hd .hd-left{
      display:flex; align-items:center; gap:10px;
      color: var(--text); font-weight: 700;
    }
    .hd-sub{ font-weight: 500; color: var(--muted); font-size: 12px; }

    .kpi{
      display:flex; gap:14px; flex-wrap:wrap;
      padding: 14px;
    }
    .kpi-item{
      flex: 1; min-width: 240px;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(91,140,255,.16), rgba(255,255,255,1));
      border: 1px solid rgba(91,140,255,.18);
      padding: 14px;
    }
    .kpi-title{ color: var(--muted); font-size: 13px; }
    .kpi-value{ font-size: 26px; font-weight: 800; color: var(--text); margin-top: 6px; }
    .kpi-foot{ margin-top: 6px; color: var(--muted); font-size: 12px; }
    .split-row{
      display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;
      color: var(--muted); font-size: 12px; font-weight: 600;
    }
    .split-pill{
      display:inline-flex; align-items:center; gap:5px;
      padding:3px 8px; border-radius:999px; background:#f8fafc;
      border:1px solid var(--border);
    }
    .split-dot{ width:7px; height:7px; border-radius:50%; }
    .split-dot.invite{ background:#5b8cff; }
    .split-dot.self{ background:#16a34a; }

    .filters{
      padding: 14px;
      display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;
    }
    .filters .left, .filters .right{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .cards{
      padding: 14px;
    }
    .stat-card{
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      padding: 14px 14px;
      transition: .18s ease;
      height: 100%;
    }
    .stat-card.clickable{ cursor:pointer; }
    .stat-card.active{
      border-color: rgba(91,140,255,.68);
      box-shadow: var(--shadow2);
    }
    .stat-card:hover{
      transform: translateY(-2px);
      box-shadow: var(--shadow2);
      border-color: rgba(91,140,255,.28);
    }
    .stat-label{ color: var(--muted); font-size: 13px; }
    .stat-num{ margin-top: 8px; font-size: 22px; font-weight: 800; color: var(--text); }
    .empty{
      padding: 34px 0 44px; text-align:center; color: var(--muted);
    }

    .layui-btn{ border-radius: 10px !important; }
    .layui-input{ border-radius: 10px !important; }
    .mini-note{ font-size: 12px; color: var(--muted); }
    .detail-table-wrap{ padding: 14px; overflow-x:auto; }
    .detail-table{ width:100%; border-collapse:collapse; background:#fff; }
    .detail-table th,
    .detail-table td{
      padding: 11px 12px;
      border-bottom: 1px solid var(--border);
      text-align:left;
      white-space:nowrap;
      font-size:13px;
    }
    .detail-table th{ color:#526174; background:#f8fafc; font-weight:700; }
    .detail-table .rank{ color:var(--muted); font-weight:700; }
    .detail-table .num{ color:var(--primary); font-size:16px; font-weight:800; }
    .code-tag{
      display:inline-flex; align-items:center; height:24px;
      padding:0 9px; border-radius:999px;
      color:#2f5fd0; background:#eef4ff; font-weight:800;
    }
  </style>
</head>
<body>
<div class="page-wrap">

  <!-- 顶部栏 -->
  <div class="topbar">
    <div class="title">
      <?= htmlspecialchars($pageTitle) ?>
      <span class="badge">Dashboard</span>
    </div>
    <div class="layui-btn-group">
      <button class="layui-btn layui-btn-normal" onclick="goRegister()">注册统计</button>
      <button class="layui-btn layui-btn-primary" onclick="goActive()">活跃统计</button>
    </div>
  </div>

  <!-- KPI + 趋势图 -->
  <div class="panel-grid">
    <div class="panel-card">
      <div class="panel-hd">
        <div class="hd-left">
          <span class="layui-badge-dot" style="background:var(--primary)"></span>
          <span><?= htmlspecialchars($trendName) ?></span>
          <span class="hd-sub">（<?= $trendBucketLabel ?>）</span>
        </div>
        <div class="mini-note">更新：<?= date('Y-m-d H:i') ?></div>
      </div>

      <form class="filters" method="get" id="trendForm">
        <?php foreach (['period', 'start_date', 'end_date', 'detail_date', 'detail_start', 'detail_end'] as $param):
          if (!isset($_GET[$param]) || !is_string($_GET[$param])) continue; ?>
          <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param], ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>
        <div class="left">
          <label>开始时间 <input class="layui-input" type="datetime-local" name="trend_start" id="trendStart" value="<?= $trendStart->format('Y-m-d\TH:i') ?>" required></label>
          <label>结束时间 <input class="layui-input" type="datetime-local" name="trend_end" id="trendEnd" value="<?= $trendEnd->format('Y-m-d\TH:i') ?>" required></label>
          <button class="layui-btn layui-btn-normal" type="submit">查询</button>
          <button class="layui-btn layui-btn-primary" type="button" onclick="resetTrend()">今日</button>
        </div>
        <span class="mini-note">北京时间，含开始、不含结束；最长31天</span>
      </form>
      <?php if ($trendError !== ''): ?><div role="alert" style="padding:0 14px;color:#b42318"><?= htmlspecialchars($trendError) ?></div><?php endif; ?>
      <div class="kpi">
        <div class="kpi-item">
          <div class="kpi-title">所选时段注册用户</div>
          <div class="kpi-value"><?= $todayTotal ?? '—' ?> <span style="font-size:14px;font-weight:700;color:var(--muted)">人</span></div>
          <div class="split-row">
            <span class="split-pill"><span class="split-dot invite"></span>推广 <?= $todayInviteTotal ?> 人</span>
            <span class="split-pill"><span class="split-dot self"></span>自行 <?= $todaySelfTotal ?> 人</span>
          </div>
          <div class="kpi-foot"><?= htmlspecialchars($trendRangeLabel) ?></div>
        </div>
        <div class="kpi-item">
          <div class="kpi-title">下方周期统计筛选</div>
          <div class="kpi-value" style="font-size:18px;">
            <?= $hasRange ? (htmlspecialchars($start_date).' ~ '.htmlspecialchars($end_date)) : '（默认最近区间）' ?>
          </div>
          <div class="kpi-foot">按天默认近30天；按周/月默认近12条</div>
        </div>
      </div>

      <div style="padding:0 14px 14px;">
        <div id="trendChart" style="width:100%;height:340px;"></div>
      </div>
    </div>

    <!-- 筛选与列表 -->
    <div class="panel-card" style="margin-top:14px;">
      <div class="panel-hd">
        <div class="hd-left">
          <span class="layui-badge-dot" style="background:#16a34a"></span>
          <span>周期统计</span>
          <span class="hd-sub">（点击卡片查看邀请码明细）</span>
        </div>
      </div>

      <div class="filters">
        <div class="left">
          <div class="layui-btn-group">
            <button class="layui-btn <?= activeClass($period,'day') ?>" onclick="changePeriod('day')">按天</button>
            <button class="layui-btn <?= activeClass($period,'week') ?>" onclick="changePeriod('week')">按周</button>
            <button class="layui-btn <?= activeClass($period,'month') ?>" onclick="changePeriod('month')">按月</button>
          </div>

          <div class="layui-form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input id="startDate" type="text" class="layui-input" placeholder="开始日期" style="width:140px;" value="<?= htmlspecialchars($start_date) ?>">
            <input id="endDate" type="text" class="layui-input" placeholder="结束日期" style="width:140px;" value="<?= htmlspecialchars($end_date) ?>">
            <button class="layui-btn layui-btn-normal" onclick="applyRange()">筛选</button>
            <button class="layui-btn layui-btn-primary" onclick="clearRange()">清空</button>
          </div>
        </div>

        <div class="right">
          <span class="mini-note"></span>
        </div>
      </div>

      <div class="cards">
        <?php if (empty($data)): ?>
          <div class="empty">暂无数据</div>
        <?php else: ?>
          <div class="layui-row layui-col-space12">
            <?php foreach ($data as $row):
              $cardStart = '';
              $cardEnd = '';
              if ($period === 'week') {
                $label = date("m月d日", strtotime($row['start_date'])) . ' ~ ' . date("m月d日", strtotime($row['end_date']));
                $cardStart = (string)$row['start_date'];
                $cardEnd = (string)$row['end_date'];
              } elseif ($period === 'month') {
                $label = date("Y年m月", strtotime($row['label'] . '-01'));
                $cardStart = (string)$row['label'] . '-01';
                $cardEnd = date('Y-m-t', strtotime($cardStart));
              } else {
                $label = date("m月d日", strtotime($row['label']));
                $cardStart = (string)$row['label'];
                $cardEnd = (string)$row['label'];
              }
              $isActiveCard = $hasDetail && $detail_start === $cardStart && $detail_end === $cardEnd;
              if (!$hasDetailRange && $hasDetailDate && $period === 'day') {
                $isActiveCard = $detail_date === $row['label'];
              }
            ?>
              <div class="layui-col-xs12 layui-col-sm6 layui-col-md3">
                <div class="stat-card clickable <?= $isActiveCard ? 'active' : '' ?>"
                  onclick="showInviteDetail('<?= htmlspecialchars($cardStart) ?>', '<?= htmlspecialchars($cardEnd) ?>')">
                  <div class="stat-label"><?= $label ?></div>
                  <div class="stat-num"><?= (int)$row['total'] ?> <span style="font-size:13px;color:var(--muted)">人</span></div>
                  <div class="split-row">
                    <span class="split-pill"><span class="split-dot invite"></span>推广 <?= (int)($row['invite_total'] ?? 0) ?></span>
                    <span class="split-pill"><span class="split-dot self"></span>自行 <?= (int)($row['self_total'] ?? 0) ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <?php if ($hasDetail): ?>
    <div class="panel-card" style="margin-top:14px;">
      <div class="panel-hd">
        <div class="hd-left">
          <span class="layui-badge-dot" style="background:#8b5cf6"></span>
          <span><?= htmlspecialchars($detailTitle) ?> 邀请码注册明细</span>
          <span class="hd-sub">推广 <?= $inviteDetailTotal ?> 人 / 自行 <?= $inviteDetailSelfTotal ?> 人 / 推广用户周期消费 ¥<?= htmlspecialchars($inviteDetailAmountTotal) ?> / 非推广场地消费 ¥<?= htmlspecialchars($inviteDetailOtherVenueAmountTotal) ?> / 预估推广收益 ¥<?= htmlspecialchars($inviteDetailEstimatedRewardTotal) ?></span>
        </div>
        <button class="layui-btn layui-btn-primary layui-btn-sm" onclick="clearInviteDetail()">关闭明细</button>
      </div>
      <div class="detail-table-wrap">
        <?php if (empty($inviteDetail)): ?>
          <div class="empty">该日期暂无邀请码注册用户</div>
        <?php else: ?>
          <table class="detail-table">
            <thead>
              <tr>
                <th>排行</th>
                <th>邀请码</th>
                <th>对应场地</th>
                <th>场地ID</th>
                <th>邀请注册人数</th>
                <th>周期消费用户</th>
                <th>周期订单数</th>
                <th>周期消费金额</th>
                <th>本推广场地消费</th>
                <th>非推广场地消费</th>
                <th>外流占比</th>
                <th>预估推广收益</th>
                <th>邀请码归属UID</th>
                <th>归属昵称</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inviteDetail as $i => $item): ?>
                <tr>
                  <td class="rank">#<?= $i + 1 ?></td>
                  <td><span class="code-tag"><?= htmlspecialchars((string)$item['invitation_code']) ?></span></td>
                  <td><?= htmlspecialchars((string)($item['venue_name'] ?: '-')) ?></td>
                  <td><?= ((int)($item['venue_id'] ?? 0)) > 0 ? (int)$item['venue_id'] : '-' ?></td>
                  <td class="num"><?= (int)$item['total'] ?> 人</td>
                  <td><?= (int)($item['consuming_users'] ?? 0) ?> 人</td>
                  <td><?= (int)($item['invited_day_orders'] ?? 0) ?> 单</td>
                  <td class="num">¥<?= htmlspecialchars((string)($item['invited_day_amount'] ?? '0.00')) ?></td>
                  <td>¥<?= htmlspecialchars((string)($item['promotion_venue_amount'] ?? '0.00')) ?></td>
                  <td class="num">¥<?= htmlspecialchars((string)($item['other_venue_amount'] ?? '0.00')) ?> / <?= (int)($item['other_venue_orders'] ?? 0) ?> 单</td>
                  <td><?= htmlspecialchars((string)($item['other_venue_rate'] ?? '0.0')) ?>%</td>
                  <td class="num">¥<?= htmlspecialchars((string)($item['estimated_reward_amount'] ?? '0.00')) ?></td>
                  <td><?= $item['inviter_uid'] ? (int)$item['inviter_uid'] : '-' ?></td>
                  <td><?= htmlspecialchars((string)($item['inviter_nickname'] ?: '-')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
  const slots = <?= $slotsJson ?>;
  const counts = <?= $countsJson ?>;
  const inviteCounts = <?= $inviteCountsJson ?>;
  const selfCounts = <?= $selfCountsJson ?>;
  const seriesName = <?= json_encode($seriesName, JSON_UNESCAPED_UNICODE) ?>;

  function buildUrl(base, overrides){
    const params = new URLSearchParams(window.location.search);
    for (const k in overrides){
      if (overrides[k] === null) params.delete(k);
      else params.set(k, overrides[k]);
    }
    if (!params.get('period')) params.set('period', 'day');
    return base + '?' + params.toString();
  }

  function goRegister(){ window.location.href = buildUrl('/api/index/user_register_detail.php', {}); }
  function resetTrend(){ window.location.href = buildUrl('/api/index/user_register_detail.php', {trend_start:null, trend_end:null}); }
  document.getElementById('trendForm').addEventListener('submit', function(event){
    const s = new Date(document.getElementById('trendStart').value);
    const e = new Date(document.getElementById('trendEnd').value);
    if (!Number.isFinite(+s) || !Number.isFinite(+e) || e <= s || e - s > 31 * 86400000) {
      event.preventDefault(); alert('请选择有效时间范围：结束晚于开始，最长31天。');
    }
  });
  function goActive(){ window.location.href = buildUrl('/api/index/user_active_detail.php', {}); }

  function changePeriod(p){
    window.location.href = buildUrl('/api/index/user_register_detail.php', {
      period: p,
      detail_date: null,
      detail_start: null,
      detail_end: null
    });
  }

  function applyRange(){
    const s = (document.getElementById('startDate').value || '').trim();
    const e = (document.getElementById('endDate').value || '').trim();
    window.location.href = buildUrl('/api/index/user_register_detail.php', {
      start_date: s ? s : null,
      end_date: e ? e : null,
      detail_date: null,
      detail_start: null,
      detail_end: null
    });
  }

  function clearRange(){
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    window.location.href = buildUrl('/api/index/user_register_detail.php', {
      start_date: null,
      end_date: null,
      detail_date: null,
      detail_start: null,
      detail_end: null
    });
  }

  function showInviteDetail(start, end){
    window.location.href = buildUrl('/api/index/user_register_detail.php', {
      detail_date: null,
      detail_start: start,
      detail_end: end
    });
  }

  function clearInviteDetail(){
    window.location.href = buildUrl('/api/index/user_register_detail.php', {
      detail_date: null,
      detail_start: null,
      detail_end: null
    });
  }

  // Layui 日期
  layui.use(['laydate'], function(){
    const laydate = layui.laydate;
    laydate.render({ elem: '#startDate', type:'date', format:'yyyy-MM-dd' });
    laydate.render({ elem: '#endDate', type:'date', format:'yyyy-MM-dd' });
  });

  // 趋势图（更像后台：更干净、带 dataZoom）
  const el = document.getElementById('trendChart');
  if (el && window.echarts) {
    const chart = echarts.init(el);

    chart.setOption({
      tooltip: { trigger: 'axis' },
      grid: { left: 52, right: 20, top: 42, bottom: 44 },
      xAxis: {
        type: 'category',
        boundaryGap: false,
        data: slots,
        axisLabel: { color: '#6b7280', interval: 3 }
      },
      legend: {
        top: 0,
        right: 8,
        data: [seriesName, '邀请推广', '自行注册']
      },
      color: ['#5b8cff', '#8b5cf6', '#16a34a'],
      yAxis: {
        type: 'value',
        axisLabel: { color: '#6b7280' },
        splitLine: { lineStyle: { color: 'rgba(17,24,39,.08)' } }
      },
      dataZoom: [
        { type:'inside', start: 0, end: 100 },
        { type:'slider', height: 18, bottom: 12, start: 0, end: 100 }
      ],
      series: [
        {
          name: seriesName,
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 7,
          data: counts,
          lineStyle: { width: 3 },
          areaStyle: { opacity: 0.12 }
        },
        {
          name: '邀请推广',
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 6,
          data: inviteCounts,
          lineStyle: { width: 2 }
        },
        {
          name: '自行注册',
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 6,
          data: selfCounts,
          lineStyle: { width: 2 }
        }
      ]
    });

    window.addEventListener('resize', () => chart.resize());
  }
</script>
</body>
</html>
