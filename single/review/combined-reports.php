<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
review_require_user();
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RC 与星河远控 · 投诉举报</title><link rel="stylesheet" href="/review/assets/combined.css?v=20261113-media3"><link rel="stylesheet" href="/review/assets/combined-operations.css?v=20261113-media3"><script src="/review/assets/platform.js?v=20261113-media3"></script></head>
<body><main class="operations-main">
<header class="operations-head"><div><h1 id="report-title">投诉举报</h1><p>RC物联与星河远控</p></div><div class="operations-tools"><a id="history-link" href="/review/combined-reports.php?history=1">查看处理记录</a><button type="button" id="reload">刷新</button></div></header>
<div class="source-filters" aria-label="来源筛选"><button data-platform-filter="all" class="active">全部</button><button data-platform-filter="rc">RC物联</button><button data-platform-filter="xh">XH 星河远控</button></div>
<form id="history-filters" class="operations-tools" hidden><label>开始日期 <input name="start_date" type="date"></label><label>结束日期 <input name="end_date" type="date"></label><label>场地ID <input name="venue_id" inputmode="numeric" placeholder="全部场地"></label><label>类型 <select name="source"><option value="all">全部举报</option><option value="drive">驾驶投诉</option><option value="voice_room">语音房举报</option><option value="voice_comment">评论/弹幕举报</option></select></label><button>查询</button></form>
<div id="source-status" class="source-status" role="status"></div><div id="report-list" class="report-list"></div><div id="history-pagination" class="operations-tools"></div>
</main><div id="operation-modal" class="operations-mask" hidden></div><div id="preview" class="operations-mask" hidden><button class="preview-close" aria-label="关闭">×</button><img alt="截图预览"></div><script src="/review/assets/combined-reports.js?v=20261113-media3"></script></body></html>
