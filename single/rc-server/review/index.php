<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$user = review_require_user();
$config = review_config();
$modules = $config['modules'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>统一审核工作台</title>
  <style>
    :root{--blue:#2f7df6;--line:#dce4f0;--ink:#172033;--muted:#6d788c;--bar:64px;--nav:58px}*{box-sizing:border-box}html,body{height:100%;margin:0;font-family:Inter,"PingFang SC","Microsoft YaHei",sans-serif;background:#eef3f9;color:var(--ink);overflow:hidden}
    .top{height:var(--bar);display:flex;align-items:center;gap:18px;padding:0 20px;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 2px 10px rgba(28,55,90,.05)}.logo{display:flex;align-items:center;gap:10px;white-space:nowrap}.logo-mark{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#2f7df6,#21a4ee);display:grid;place-items:center;color:#fff;font-weight:800}.logo-title{font-size:17px;font-weight:750}.modules{display:flex;align-items:center;gap:6px;min-width:0;overflow-x:auto;scrollbar-width:none}.modules::-webkit-scrollbar{display:none}.module-btn{height:36px;padding:0 14px;border:0;border-radius:9px;color:#5e6b80;background:transparent;font-size:13px;font-weight:650;white-space:nowrap;cursor:pointer}.module-btn:hover{background:#f0f5ff;color:var(--blue)}.module-btn.active{background:#eaf2ff;color:var(--blue)}.account{margin-left:auto;display:flex;align-items:center;gap:10px;white-space:nowrap}.who{font-size:13px;color:#68758a}.logout{height:34px;padding:0 12px;border:1px solid #dce4ef;border-radius:9px;background:#fff;color:#5b6678;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
    .mobile-system{display:none;height:48px;padding:7px 12px;background:#fff;border-bottom:1px solid var(--line);gap:8px}.system-btn{flex:1;border:0;border-radius:9px;background:#edf2f8;color:#667286;font-weight:700}.system-btn.active{background:var(--blue);color:#fff}
    .workspace{height:calc(100vh - var(--bar));display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#cfd9e7}.panel{min-width:0;background:#fff;display:flex;flex-direction:column}.panel-head{height:46px;padding:0 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);background:#fbfcfe}.system-name{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:750}.dot{width:8px;height:8px;border-radius:50%}.rc .dot{background:#2f7df6}.kwx .dot{background:#19a974}.panel-actions{margin-left:auto;display:flex;gap:6px}.small-btn{height:30px;padding:0 10px;border:1px solid #dce4ef;border-radius:8px;background:#fff;color:#5e6b80;font-size:12px;cursor:pointer}.small-btn:hover{border-color:#87aff4;color:var(--blue)}iframe{width:100%;height:calc(100% - 46px);border:0;background:#fff}.notice{position:fixed;left:50%;top:74px;z-index:50;transform:translate(-50%,-18px);opacity:0;pointer-events:none;padding:11px 16px;border-radius:10px;background:#172033;color:#fff;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.2);transition:.2s}.notice.show{opacity:1;transform:translate(-50%,0)}
    @media(max-width:900px){:root{--bar:106px}.top{height:106px;display:grid;grid-template-columns:1fr auto;grid-template-rows:52px 46px;padding:0 12px;gap:0}.logo{grid-column:1}.account{grid-column:2;grid-row:1}.who{display:none}.modules{grid-column:1/3;grid-row:2;width:100%}.module-btn{height:34px;padding:0 12px}.mobile-system{display:flex}.workspace{height:calc(100vh - var(--bar) - 48px);display:block}.panel{height:100%;display:none}.panel.mobile-active{display:flex}.panel-head{height:42px}iframe{height:calc(100% - 42px)}.logout{padding:0 9px}.logo-title{font-size:15px}}
  </style>
</head>
<body>
  <header class="top">
    <div class="logo"><div class="logo-mark">审</div><div class="logo-title">统一审核工作台</div></div>
    <nav class="modules" aria-label="审核功能">
      <?php foreach ($modules as $key => $module): ?>
        <button class="module-btn<?= $key === 'ai' ? ' active' : '' ?>" type="button" data-module="<?= review_escape((string)$key) ?>"><?= review_escape((string)$module['label']) ?></button>
      <?php endforeach; ?>
    </nav>
    <div class="account"><span class="who"><?= review_escape((string)($user['display_name'] ?: $user['username'])) ?></span><a class="logout" href="/review/logout.php">退出</a></div>
  </header>

  <div class="mobile-system" aria-label="平台切换"><button class="system-btn active" data-system="rc">RC审核</button><button class="system-btn" data-system="kwx">KWX审核</button></div>

  <main class="workspace">
    <section class="panel rc mobile-active" data-panel="rc">
      <div class="panel-head"><div class="system-name"><span class="dot"></span><span>RC · <b data-current-label>AI巡查</b></span></div><div class="panel-actions"><button class="small-btn" data-action="reload" data-system="rc">刷新</button><button class="small-btn" data-action="open" data-system="rc">新窗口</button></div></div>
      <iframe id="rcFrame" title="RC审核面板" src="<?= review_escape((string)$modules['ai']['rc']) ?>" allow="autoplay; clipboard-read; clipboard-write" referrerpolicy="same-origin"></iframe>
    </section>
    <section class="panel kwx" data-panel="kwx">
      <div class="panel-head"><div class="system-name"><span class="dot"></span><span>KWX · <b data-current-label>AI巡查</b></span></div><div class="panel-actions"><button class="small-btn" data-action="reload" data-system="kwx">刷新</button><button class="small-btn" data-action="open" data-system="kwx">新窗口</button></div></div>
      <iframe id="kwxFrame" title="KWX审核面板" src="/review/kwx-entry.php?module=ai" allow="autoplay; clipboard-read; clipboard-write"></iframe>
    </section>
  </main>
  <div class="notice" id="notice" role="status"></div>

  <script>
    const modules = <?= json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const kwxOrigin = <?= json_encode($config['kwx_proxy_origin'], JSON_UNESCAPED_SLASHES) ?>;
    const rcFrame = document.getElementById('rcFrame');
    const kwxFrame = document.getElementById('kwxFrame');
    let currentModule = 'ai';

    function kwxEntry(module) { return '/review/kwx-entry.php?module=' + encodeURIComponent(module); }
    function selectModule(module) {
      if (!modules[module]) return;
      currentModule = module;
      document.querySelectorAll('.module-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.module === module));
      document.querySelectorAll('[data-current-label]').forEach(el => el.textContent = modules[module].label);
      rcFrame.src = modules[module].rc;
      kwxFrame.src = kwxEntry(module);
    }
    document.querySelectorAll('.module-btn').forEach(btn => btn.addEventListener('click', () => selectModule(btn.dataset.module)));

    document.querySelectorAll('.system-btn').forEach(btn => btn.addEventListener('click', () => {
      document.querySelectorAll('.system-btn').forEach(item => item.classList.toggle('active', item === btn));
      document.querySelectorAll('[data-panel]').forEach(panel => panel.classList.toggle('mobile-active', panel.dataset.panel === btn.dataset.system));
    }));

    document.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => {
      const system = btn.dataset.system;
      if (btn.dataset.action === 'reload') {
        if (system === 'rc') rcFrame.src = modules[currentModule].rc;
        else kwxFrame.src = kwxEntry(currentModule);
      } else {
        const url = system === 'rc' ? modules[currentModule].rc : kwxEntry(currentModule);
        window.open(url, '_blank', 'noopener');
      }
    }));

    let noticeTimer;
    function showNotice(message) {
      const el = document.getElementById('notice');
      el.textContent = message;
      el.classList.add('show');
      clearTimeout(noticeTimer);
      noticeTimer = setTimeout(() => el.classList.remove('show'), 4500);
    }
    window.addEventListener('message', event => {
      const allowed = event.origin === location.origin || event.origin === kwxOrigin;
      if (!allowed || !event.data || event.data.type !== 'review-auth-expired') return;
      showNotice((event.data.system === 'kwx' ? 'KWX' : 'RC') + ' 登录状态已刷新，请点击面板右上角“刷新”');
    });
  </script>
</body>
</html>
