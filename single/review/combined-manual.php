<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
review_require_user();
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RC 与星河远控 · 人工巡查</title><link rel="stylesheet" href="/review/assets/combined.css?v=20261113-media3"><link rel="stylesheet" href="/review/assets/combined-operations.css?v=20261113-media3"><script src="/review/assets/platform.js?v=20261113-media3"></script></head><body>
<main class="operations-main"><header class="operations-head"><div><h1>人工巡查</h1><p>RC物联与星河远控 · 驾驶中设备</p></div><div class="operations-tools"><button type="button" id="reload">刷新设备</button><button class="primary" type="button" id="capture-all">一键抓图</button></div></header>
<div class="source-filters" aria-label="来源筛选"><button data-platform-filter="all" class="active">全部</button><button data-platform-filter="rc">RC物联</button><button data-platform-filter="xh">XH 星河远控</button></div>
<div id="source-status" class="source-status" role="status"></div><div id="device-list" class="device-grid"></div></main>
<div id="operation-modal" class="operations-mask" hidden></div><div id="preview" class="operations-mask" hidden><button class="preview-close" aria-label="关闭">×</button><img alt="截图预览"></div><script src="/review/assets/combined-manual.js?v=20261113-media3"></script></body></html>
