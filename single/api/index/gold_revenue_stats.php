<?php
require_once '../Database.php';

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function jsonOut($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['code' => $code, 'msg' => $msg];
    if ($data !== null) {
        $resp['data'] = $data;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    if (isset($_GET['ajax'])) {
        jsonOut(1001, '用户未登录或会话已过期', []);
    }
    header("Location: login.html");
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    if (isset($_GET['ajax'])) {
        jsonOut(1001, '用户未登录或无权访问', []);
    }
    echo '用户未登录或无权访问';
    exit;
}

// ===== AJAX 数据接口 =====
if (isset($_GET['ajax'])) {
    $mode = $_GET['mode'] ?? 'day';
    $mode = in_array($mode, ['day', 'week', 'month'], true) ? $mode : 'day';

    // 苹果金币订单时间
    $appleTimeExpr = "COALESCE(a.purchase_date, a.created_at)";

    if ($mode === 'day') {
        $sql = "
            SELECT
                label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_FORMAT(DATE($appleTimeExpr), '%m月%d日') AS label,
                    DATE($appleTimeExpr) AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE($appleTimeExpr)

                UNION ALL

                -- 安卓金币充值：RechargeOrders，订单号包含 GO
                SELECT
                    DATE_FORMAT(DATE(created_at), '%m月%d日') AS label,
                    DATE(created_at) AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE(created_at)
            ) t
            GROUP BY sort_key, label
            ORDER BY sort_key DESC
            LIMIT 30
        ";
    } elseif ($mode === 'week') {
        $sql = "
            SELECT
                CONCAT(
                    DATE_FORMAT(sort_key, '%m月%d日'),
                    ' - ',
                    DATE_FORMAT(DATE_ADD(sort_key, INTERVAL 6 DAY), '%m月%d日')
                ) AS label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_SUB(DATE($appleTimeExpr), INTERVAL WEEKDAY($appleTimeExpr) DAY) AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE_SUB(DATE($appleTimeExpr), INTERVAL WEEKDAY($appleTimeExpr) DAY)

                UNION ALL

                -- 安卓金币充值
                SELECT
                    DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)
            ) t
            GROUP BY sort_key
            ORDER BY sort_key DESC
            LIMIT 20
        ";
    } else {
        $sql = "
            SELECT
                DATE_FORMAT(sort_key, '%Y年%m月') AS label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_FORMAT($appleTimeExpr, '%Y-%m-01') AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE_FORMAT($appleTimeExpr, '%Y-%m')

                UNION ALL

                -- 安卓金币充值
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-01') AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ) t
            GROUP BY sort_key
            ORDER BY sort_key DESC
            LIMIT 12
        ";
    }

    $rows = $database->query($sql) ?: [];

    jsonOut(0, 'ok', [
        'mode' => $mode,
        'list' => $rows
    ]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>金币收益统计</title>
    <style>
        :root {
            --primary: #3f6de0;
            --primary-light: #6f95f5;
            --text-main: #222;
            --text-sub: #666;
            --card-bg: #ffffff;
            --page-bg: #f3f3f3;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
        }

        body {
            background: var(--page-bg);
            min-height: 100vh;
            padding: 28px 24px 40px;
            color: var(--text-main);
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
        }

        .title {
            text-align: center;
            font-size: 34px;
            font-weight: 700;
            margin: 10px 0 24px;
            color: #111;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .tab-btn {
            border: none;
            background: var(--primary-light);
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            transition: transform .15s ease, opacity .15s ease, background .15s ease;
        }

        .tab-btn:hover {
            transform: translateY(-1px);
        }

        .tab-btn.active {
            background: var(--primary);
            box-shadow: 0 4px 12px rgba(63, 109, 224, 0.28);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            min-height: 110px;
            padding: 18px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
        .card-tip { margin-top: 7px; font-size: 12px; color: var(--primary); }

        .modal-mask { position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.48); display: none; align-items: center; justify-content: center; padding: 18px; }
        .modal-mask.show { display: flex; }
        .modal { width: min(1050px, 100%); max-height: 88vh; background: #fff; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; }
        .modal-head { padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; }
        .modal-title { font-size: 20px; font-weight: 700; }
        .close-btn { border: 0; background: #f2f3f5; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; font-size: 22px; }
        .modal-tools { padding: 14px 22px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-bottom: 1px solid #eee; }
        .detail-tab { border: 1px solid #d8dce5; background: #fff; color: #444; padding: 8px 14px; border-radius: 7px; cursor: pointer; }
        .detail-tab.active { color: #fff; background: var(--primary); border-color: var(--primary); }
        .venue-select { margin-left: auto; min-width: 190px; padding: 8px 10px; border: 1px solid #d8dce5; border-radius: 7px; background: #fff; }
        .modal-body { padding: 0 22px 18px; overflow: auto; }
        .summary { padding: 13px 0; color: #666; }
        .detail-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .detail-table th, .detail-table td { padding: 11px 9px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        .detail-table th { position: sticky; top: 0; background: #f7f8fa; color: #555; }
        .pager { display: flex; justify-content: center; gap: 10px; align-items: center; padding-top: 16px; }
        .pager button { border: 1px solid #d8dce5; background: #fff; border-radius: 6px; padding: 7px 13px; cursor: pointer; }
        .rank-no { display:inline-flex; width:24px; height:24px; align-items:center; justify-content:center; border-radius:50%; background:#eef2ff; color:var(--primary); font-weight:700; }

        .card-date {
            font-size: 16px;
            color: #555;
            margin-bottom: 14px;
        }

        .card-amount {
            font-size: 20px;
            font-weight: 700;
            color: #10233f;
        }

        .card-sub {
            margin-top: 8px;
            font-size: 12px;
            color: #888;
        }

        .empty {
            text-align: center;
            color: #888;
            font-size: 16px;
            padding: 48px 0;
        }

        .loading {
            text-align: center;
            color: #888;
            font-size: 16px;
            padding: 48px 0;
        }

        @media (max-width: 1024px) {
            .grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 18px 14px 28px;
            }

            .title {
                font-size: 28px;
                margin-bottom: 18px;
            }

            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .card {
                min-height: 96px;
            }

            .card-date {
                font-size: 14px;
                margin-bottom: 10px;
            }

            .card-amount {
                font-size: 18px;
            }
        }

        @media (max-width: 420px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="title">金币收益统计</div>

    <div class="toolbar">
        <button class="tab-btn active" data-mode="day">按天</button>
        <button class="tab-btn" data-mode="week">按周</button>
        <button class="tab-btn" data-mode="month">按月</button>
    </div>

    <div id="content" class="loading">加载中...</div>
</div>

<div id="detailModal" class="modal-mask">
    <div class="modal">
        <div class="modal-head"><div id="modalTitle" class="modal-title">订单详情</div><button id="closeModal" class="close-btn">×</button></div>
        <div class="modal-tools">
            <button class="detail-tab active" data-detail-tab="orders">订单详情</button>
            <button class="detail-tab" data-detail-tab="top">消费最多</button>
            <select id="venueSelect" class="venue-select" style="display:none"><option value="0">全部场地</option></select>
        </div>
        <div id="modalBody" class="modal-body"></div>
    </div>
</div>

<script>
const contentEl = document.getElementById('content');
const tabBtns = document.querySelectorAll('.tab-btn');
const modalEl = document.getElementById('detailModal');
const modalBody = document.getElementById('modalBody');
const venueSelect = document.getElementById('venueSelect');
let selectedRange = null;
let detailPage = 1;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

function rangeByMode(mode, sortKey) {
    const start = new Date(`${sortKey}T00:00:00`);
    const end = new Date(start);
    if (mode === 'week') end.setDate(end.getDate() + 6);
    if (mode === 'month') end.setMonth(end.getMonth() + 1, 0);
    const fmt = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    return { start: fmt(start), end: fmt(end) };
}

async function loadVenues() {
    if (venueSelect.dataset.loaded) return;
    const ret = await fetch('gold_revenue_detail.php?action=venues', {credentials:'include', cache:'no-store'}).then(r => r.json());
    if (ret.code === 0) {
        (ret.data.list || []).forEach(v => venueSelect.insertAdjacentHTML('beforeend', `<option value="${Number(v.id)}">${escapeHtml(v.venue_name)}（${Number(v.id)}）</option>`));
        venueSelect.dataset.loaded = '1';
    }
}

async function loadOrderDetails(page = 1) {
    detailPage = page;
    modalBody.innerHTML = '<div class="loading">加载中...</div>';
    const q = new URLSearchParams({action:'orders', start_date:selectedRange.start, end_date:selectedRange.end, page:String(page)});
    const ret = await fetch(`gold_revenue_detail.php?${q}`, {credentials:'include', cache:'no-store'}).then(r => r.json());
    if (ret.code !== 0) { modalBody.innerHTML = `<div class="empty">${escapeHtml(ret.msg)}</div>`; return; }
    const d = ret.data, list = d.list || [];
    modalBody.innerHTML = `<div class="summary">共 ${d.total} 笔，合计 ${Number(d.total_amount).toFixed(2)} 元</div>
      <table class="detail-table"><thead><tr><th>订单号</th><th>用户</th><th>商品</th><th>金币</th><th>金额</th><th>渠道</th><th>支付时间</th></tr></thead><tbody>
      ${list.length ? list.map(x => `<tr><td>${escapeHtml(x.order_number)}</td><td>${escapeHtml(x.nickname || '-') }（${escapeHtml(x.uid)}）</td><td>${escapeHtml(x.product_name)}</td><td>${escapeHtml(x.gold_amount)}</td><td>${Number(x.amount||0).toFixed(2)} 元</td><td>${escapeHtml(x.channel)}</td><td>${escapeHtml(x.paid_time)}</td></tr>`).join('') : '<tr><td colspan="7">暂无订单</td></tr>'}</tbody></table>
      <div class="pager"><button ${page<=1?'disabled':''} onclick="loadOrderDetails(${page-1})">上一页</button><span>${page} / ${d.total_pages}</span><button ${page>=d.total_pages?'disabled':''} onclick="loadOrderDetails(${page+1})">下一页</button></div>`;
}

async function loadTopConsumers() {
    modalBody.innerHTML = '<div class="loading">加载中...</div>';
    const q = new URLSearchParams({action:'top_consumers', start_date:selectedRange.start, end_date:selectedRange.end, venue_id:venueSelect.value});
    const ret = await fetch(`gold_revenue_detail.php?${q}`, {credentials:'include', cache:'no-store'}).then(r => r.json());
    if (ret.code !== 0) { modalBody.innerHTML = `<div class="empty">${escapeHtml(ret.msg)}</div>`; return; }
    const list = ret.data.list || [];
    modalBody.innerHTML = `<div class="summary">统计所选区间内使用金币支付的订单，最多显示前 50 名</div>
      <table class="detail-table"><thead><tr><th>排名</th><th>用户</th><th>UID</th><th>消费金币</th><th>消费订单数</th></tr></thead><tbody>
      ${list.length ? list.map((x,i) => `<tr><td><span class="rank-no">${i+1}</span></td><td>${escapeHtml(x.nickname)}</td><td>${escapeHtml(x.uid)}</td><td>${Number(x.consume_gold||0).toFixed(2)}</td><td>${Number(x.order_count||0)}</td></tr>`).join('') : '<tr><td colspan="5">暂无金币消费数据</td></tr>'}</tbody></table>`;
}

async function openDetails(item, mode) {
    selectedRange = rangeByMode(mode, item.sort_key);
    document.getElementById('modalTitle').textContent = `${item.label} · 金币数据`;
    modalEl.classList.add('show');
    document.querySelectorAll('[data-detail-tab]').forEach((x,i) => x.classList.toggle('active', i === 0));
    venueSelect.style.display = 'none';
    await loadOrderDetails(1);
}

async function loadStats(mode = 'day') {
    contentEl.className = 'loading';
    contentEl.textContent = '加载中...';

    try {
        const res = await fetch(`gold_revenue_stats.php?ajax=1&mode=${mode}&_=${Date.now()}`, {
            credentials: 'include',
            cache: 'no-store'
        });
        const ret = await res.json();

        if (ret.code !== 0) {
            contentEl.className = 'empty';
            contentEl.textContent = ret.msg || '加载失败';
            return;
        }

        const list = Array.isArray(ret.data?.list) ? ret.data.list : [];

        if (!list.length) {
            contentEl.className = 'empty';
            contentEl.textContent = '暂无数据';
            return;
        }

        const html = `
            <div class="grid">
                ${list.map(item => `
                    <div class="card" data-index="${list.indexOf(item)}">
                        <div class="card-date">${item.label || ''}</div>
                        <div class="card-amount">${Number(item.total_amount || 0).toFixed(2)} 元</div>
                        <div class="card-sub">订单数：${item.order_count || 0}</div>
                        <div class="card-tip">点击查看详情</div>
                    </div>
                `).join('')}
            </div>
        `;

        contentEl.className = '';
        contentEl.innerHTML = html;
        contentEl.querySelectorAll('.card').forEach(card => card.addEventListener('click', () => openDetails(list[Number(card.dataset.index)], mode)));

    } catch (e) {
        contentEl.className = 'empty';
        contentEl.textContent = '加载失败，请刷新重试';
        console.error(e);
    }
}

tabBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        tabBtns.forEach(x => x.classList.remove('active'));
        this.classList.add('active');
        loadStats(this.dataset.mode);
    });
});

loadStats('day');
document.getElementById('closeModal').addEventListener('click', () => modalEl.classList.remove('show'));
modalEl.addEventListener('click', e => { if (e.target === modalEl) modalEl.classList.remove('show'); });
document.querySelectorAll('[data-detail-tab]').forEach(btn => btn.addEventListener('click', async () => {
    document.querySelectorAll('[data-detail-tab]').forEach(x => x.classList.remove('active'));
    btn.classList.add('active');
    if (btn.dataset.detailTab === 'top') { venueSelect.style.display = ''; await loadVenues(); await loadTopConsumers(); }
    else { venueSelect.style.display = 'none'; await loadOrderDetails(1); }
}));
venueSelect.addEventListener('change', loadTopConsumers);
</script>
</body>
</html>
