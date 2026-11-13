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
  <title>RC物联和星河远控-AI违规巡查系统</title>
  <link rel="stylesheet" href="/review/assets/rc-checkin.css?v=20261113-live1">
  <link rel="stylesheet" href="/review/assets/combined.css?v=20261113-media3">
  <style>
    .ai-combined{margin:0;background:#142e37;color:#dceff4;font-family:"Microsoft YaHei",sans-serif;font-size:14px}.ai-wrap{padding:15px}.ai-header{padding:18px;border:1px solid #245765;border-radius:12px;background:#132a32}.ai-header h1{margin:0 0 14px;color:#22d3ee;font-size:22px;line-height:1.5}.ai-platform-stats{padding:10px 0;border-top:1px solid #24505b;line-height:1.9}.ai-platform-stats:first-of-type{border-top:0}.ai-platform-stats strong{font-size:15px}.ai-stats-line{display:block;color:#c9f4ff;font-size:13px}.ai-tools{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:14px 0}.ai-combined button,.ai-combined select,.ai-combined input,.ai-combined textarea{font:inherit}.ai-combined button{border:1px solid #278eb0;border-radius:8px;background:#108da9;color:white;padding:8px 12px;cursor:pointer}.ai-combined button:hover{background:#0d7894}.ai-combined button:disabled{opacity:.55;cursor:wait}.ai-combined button.secondary{background:#23434d;border-color:#3b6470}.ai-combined select,.ai-combined textarea{border:1px solid #3e6976;border-radius:6px;background:#102a33;color:#e5f6fa;padding:6px;max-width:100%}.ai-status{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;font-size:12px;color:#afcbd4}.ai-status .failed{color:#ffbd9e}.ai-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(265px,1fr));gap:14px;align-items:start}.ai-card{overflow:hidden;border:1px solid #285a68;border-radius:12px;background:#15313b;min-width:0}.ai-photo{height:190px;position:relative;background:#0d2229}.ai-photo img{width:100%;height:100%;object-fit:contain;cursor:zoom-in}.ai-time{position:absolute;right:5px;bottom:5px;padding:2px 6px;border-radius:4px;background:#000b;color:white;font-size:11px}.ai-content{padding:12px;line-height:1.7;overflow-wrap:anywhere}.ai-risk-row{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}.ai-risk{padding:1px 10px;border:1px solid #627c86;border-radius:20px;font-size:13px}.ai-risk.high{border-color:#f06777;color:#ffacb4;background:#ad203522}.ai-risk.medium{border-color:#e9b357;color:#f6d18f}.ai-risk.low{border-color:#5baddb;color:#a4ddff}.ai-risk.safe{border-color:#4da884;color:#a0e3c8}.ai-count{color:#f86f80;font-weight:bold}.ai-remark{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:3px;color:#a9c3cb}.ai-venue.sensitive{color:#ff9c99;background:#892f3522;border-left:3px solid #ee6870;padding-left:6px}.ai-source-badge{display:inline-flex;border-radius:12px;background:#1d65a8;color:#d9ecff;padding:0 8px;font-size:11px;font-weight:bold}.ai-source-badge.xh{background:#17664c;color:#bdeed8}.ai-tagging{display:flex;align-items:center;gap:6px;margin-top:8px}.ai-actions{display:flex;gap:7px;margin-top:12px}.ai-note{font-size:12px;color:#9fbec8}.ai-empty{padding:35px;text-align:center;color:#afcbd4;grid-column:1/-1;border:1px dashed #38616e;border-radius:10px}.ai-modal{position:fixed;inset:0;background:#00151bc9;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px}.ai-modal[hidden]{display:none}.ai-dialog{width:min(460px,100%);max-height:90vh;overflow:auto;padding:22px;background:#16343e;border:1px solid #3e6b78;border-radius:14px;box-shadow:0 15px 60px #0007}.ai-dialog h2{font-size:18px;margin:0 0 16px}.ai-dialog label{display:block;margin:12px 0 5px}.ai-dialog textarea{width:100%;box-sizing:border-box}.ai-dialog .ai-actions{justify-content:flex-end}.ai-preview{max-width:95vw;max-height:88vh;object-fit:contain}.ai-image-close{position:absolute;top:12px;right:15px}.ai-checkins{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}.ai-checkin{padding:10px;border:1px solid #548191;border-radius:8px;background:#1d414d}.ai-checkin button{margin-left:8px}.ai-refresh-note{font-size:12px;color:#98b7c1}@media(max-width:480px){.ai-wrap{padding:10px}.ai-header{padding:13px}.ai-header h1{font-size:19px}.ai-grid{grid-template-columns:1fr}.ai-photo{height:240px}}
    .ai-image-error{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;gap:8px;background:#10262f;color:#b6cbd3;font-size:13px}.ai-image-error[hidden]{display:none}.ai-image-error a{color:#72d8f0}
    .ai-stats-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;list-style:none;user-select:none}.ai-stats-summary::-webkit-details-marker{display:none}.ai-stats-summary:focus-visible{outline:2px solid #35d8e8;outline-offset:4px;border-radius:4px}.ai-stats-toggle-label{font-size:12px;font-weight:400;color:#92c0cb;white-space:nowrap}.ai-stats-toggle-label::after{content:"展开 ▾"}.ai-platform-stats[open] .ai-stats-toggle-label::after{content:"收起 ▴"}.ai-stats-content{padding-top:4px}
    .ai-checkin-modal{z-index:1100}.ai-checkin-dialog{text-align:center;width:min(360px,100%)}.ai-checkin-dialog p{margin:0 0 12px;line-height:1.7}.ai-checkin-dialog button{min-width:100px}.ai-checkin-dialog .ai-checkin-error{color:#ffb6a6!important;font-size:13px;overflow-wrap:anywhere}.ai-checkin-error:empty{display:none}
  </style>
</head>
<body class="combined-page ai-combined">
<main class="ai-wrap">
  <header class="ai-header">
    <h1>RC物联和星河远控-AI违规巡查系统</h1>
    <?php foreach (['rc' => 'RC物联', 'xh' => '星河远控'] as $platform => $label): ?>
    <details class="ai-platform-stats" data-stats-platform="<?= $platform ?>" aria-label="<?= review_escape($label) ?>今日统计" open>
      <summary class="ai-stats-summary">
        <strong><span class="ai-source-badge <?= $platform ?>"><?= strtoupper($platform) ?></span> <?= review_escape($label) ?></strong>
        <span class="ai-stats-toggle-label" aria-hidden="true"></span>
      </summary>
      <div class="ai-stats-content">
        <span class="ai-stats-line" id="<?= $platform ?>Recognition" aria-live="polite">今日识别统计加载中…</span>
        <span class="ai-stats-line" id="<?= $platform ?>Vendor" aria-live="polite">今日厂商抓图统计加载中…</span>
      </div>
    </details>
    <?php endforeach; ?>
  </header>
  <div class="ai-tools">
    <button type="button" id="soundToggle" aria-pressed="true">声音提醒：开启</button>
    <button type="button" id="predictedToggle">全部显示</button>
    <label>来源 <select id="sourceFilter"><option value="all">RC + XH</option><option value="rc">RC物联</option><option value="xh">星河远控</option></select></label>
    <button type="button" id="refreshAI" class="secondary">刷新</button>
    <a href="/api/operat/checkin_report.php" target="_blank" rel="noopener" class="ai-note">RC签到记录</a>
    <span class="ai-refresh-note">每30秒刷新</span>
  </div>
  <div id="sourceStatus" class="ai-status" aria-live="polite"></div>
  <div id="violationsContainer" class="ai-grid"><div class="ai-empty">待审数据加载中…</div></div>
</main>
<div id="rcCheckinHost"></div>
<div id="rcCheckinStatus" class="rc-checkin-status" role="status" hidden></div>
<audio id="alertSound" src="/res/001.mp3" preload="none"></audio>
<div id="banModal" class="ai-modal" hidden>
  <form id="banForm" class="ai-dialog" aria-labelledby="banTitle">
    <h2 id="banTitle">封禁设备</h2>
    <p id="banTarget"></p>
    <label for="banDuration">封禁时长</label>
    <select id="banDuration"><option value="30">30分钟</option><option value="60">1小时</option></select>
    <label for="banReason">封禁原因</label>
    <textarea id="banReason" rows="3" maxlength="1000" required></textarea>
    <div class="ai-actions"><button type="button" id="cancelBan" class="secondary">取消</button><button type="submit" id="confirmBan">确认封禁</button></div>
  </form>
</div>
<div id="imageModal" class="ai-modal" hidden><button type="button" class="ai-image-close" id="closeImage">关闭</button><img id="modalImage" class="ai-preview" alt="放大截图"></div>
<script src="/review/assets/platform.js?v=20261113-media3"></script>
<script src="/review/assets/stats-collapse.js?v=20261113-1"></script>
<script src="/review/assets/combined-ai.js?v=20261113-checkin-live1"></script>
<script src="/review/assets/rc-checkin.js?v=20261113-live1"></script>
</body>
</html>
