<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
review_require_user();
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RC物联和星河远控 · 图文审核</title>
  <link rel="stylesheet" href="/review/assets/combined.css?v=20261113-media3">
  <style>
    .image-review{max-width:1200px;margin:0 auto;padding:16px}.image-review h1{font-size:21px;margin:0 0 14px}.image-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}.image-toolbar button,.image-actions button,.image-dialog button{border:0;border-radius:8px;padding:9px 14px;background:#218bd4;color:#fff;cursor:pointer}.image-toolbar button:disabled,.image-actions button:disabled{opacity:.55;cursor:wait}.image-status{font-size:13px;color:#9fb8c3}.image-errors{white-space:pre-line;color:#ffb6a5;font-size:13px;line-height:1.7;margin-bottom:12px}.image-section{margin:18px 0}.image-section h2{font-size:16px;margin:0 0 10px}.image-row{display:flex;gap:14px;align-items:center;padding:15px;border:1px solid #315764;border-radius:12px;margin-bottom:10px;background:rgba(21,49,59,.6)}.image-thumb{width:88px;height:88px;object-fit:cover;border-radius:8px;cursor:zoom-in;flex-shrink:0}.image-info{min-width:0;flex:1;line-height:1.65;font-size:13px;overflow-wrap:anywhere}.image-value{font-size:15px;font-weight:600;white-space:pre-wrap}.image-actions{display:flex;gap:8px;flex-shrink:0}.image-actions .danger,.image-dialog .danger{background:#b74155}.image-empty{padding:20px;color:#9fb8c3;text-align:center;border:1px dashed #315764;border-radius:10px}.image-dialog-mask,.image-preview{position:fixed;inset:0;z-index:1000;background:rgba(4,16,24,.8);display:flex;align-items:center;justify-content:center;padding:20px}.image-dialog-mask[hidden],.image-preview[hidden]{display:none}.image-dialog{width:min(460px,100%);padding:22px;background:#183541;border:1px solid #427080;border-radius:15px;color:#e4f5fa}.image-dialog h2{font-size:18px;margin:0 0 12px}.image-dialog p{white-space:pre-wrap;line-height:1.7}.image-dialog textarea{box-sizing:border-box;width:100%;min-height:90px;border:1px solid #63808c;border-radius:8px;padding:10px;background:#102c37;color:#e4f5fa;font:inherit}.image-dialog-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px}.image-dialog .cancel{background:#415c68}.image-preview img{max-width:95vw;max-height:90vh;object-fit:contain}.image-preview button{position:fixed;top:16px;right:18px;border:0;border-radius:50%;font-size:28px;width:42px;height:42px;background:#fff;color:#243b46;cursor:pointer}@media(max-width:650px){.image-review{padding:12px}.image-row{align-items:flex-start;flex-wrap:wrap}.image-thumb{width:70px;height:70px}.image-actions{width:100%;justify-content:flex-end}.image-review h1{font-size:18px}}
  </style>
</head>
<body>
<main class="image-review">
  <h1>RC物联和星河远控 · 图文审核</h1>
  <div class="image-toolbar"><button id="image-refresh" type="button">刷新待审内容</button><span id="image-status" class="image-status" role="status">正在加载…</span></div>
  <div id="image-errors" class="image-errors" role="alert"></div>
  <div id="image-queues"></div>
</main>
<div id="image-dialog-mask" class="image-dialog-mask" hidden>
  <div class="image-dialog" role="dialog" aria-modal="true" aria-labelledby="image-dialog-title">
    <h2 id="image-dialog-title"></h2><p id="image-dialog-message"></p>
    <textarea id="image-dialog-reason" placeholder="请输入拒绝理由" hidden></textarea>
    <div class="image-dialog-actions"><button type="button" class="cancel" id="image-dialog-cancel">取消</button><button type="button" id="image-dialog-confirm">确定</button></div>
  </div>
</div>
<div id="image-preview" class="image-preview" hidden><button type="button" aria-label="关闭图片">×</button><img alt="审核图片大图"></div>
<script src="/review/assets/platform.js?v=20261113-media3"></script>
<script src="/review/assets/combined-image.js?v=20261113-reject1"></script>
</body>
</html>
