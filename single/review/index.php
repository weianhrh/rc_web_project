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
    :root{--blue:#2f7df6;--green:#19a974;--line:#dce4f0;--ink:#172033;--muted:#6d788c;--bar:58px}*{box-sizing:border-box}html,body{height:100%;margin:0;font-family:Inter,"PingFang SC","Microsoft YaHei",sans-serif;background:#eef3f9;color:var(--ink);overflow:hidden}
    .top{height:var(--bar);display:flex;align-items:center;padding:0 20px;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 2px 10px rgba(28,55,90,.05)}.logo{display:flex;align-items:center;gap:10px;white-space:nowrap}.logo-mark{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#2f7df6,#21a4ee);display:grid;place-items:center;color:#fff;font-weight:800}.logo-title{font-size:17px;font-weight:750}.top-hint{margin-left:18px;font-size:12px;color:#8792a4}.account{margin-left:auto;display:flex;align-items:center;gap:10px;white-space:nowrap}.who{font-size:13px;color:#68758a}.logout{height:34px;padding:0 12px;border:1px solid #dce4ef;border-radius:9px;background:#fff;color:#5b6678;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
    .mobile-system{display:none;height:48px;padding:7px 12px;background:#fff;border-bottom:1px solid var(--line);gap:8px}.system-btn{flex:1;border:0;border-radius:9px;background:#edf2f8;color:#667286;font-weight:700}.system-btn.active{background:var(--blue);color:#fff}
    .workspace{height:calc(100vh - var(--bar));display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#cfd9e7}.panel{min-width:0;background:#fff;display:flex;flex-direction:column}.panel-head{height:42px;padding:0 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);background:#fbfcfe;flex:0 0 auto}.system-name{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:750}.dot{width:8px;height:8px;border-radius:50%}.rc .dot{background:var(--blue)}.kwx .dot{background:var(--green)}.panel-actions{margin-left:auto;display:flex;gap:6px}.small-btn{height:29px;padding:0 10px;border:1px solid #dce4ef;border-radius:8px;background:#fff;color:#5e6b80;font-size:12px;cursor:pointer}.small-btn:hover{border-color:#87aff4;color:var(--blue)}
    .panel-tabs{height:42px;display:flex;align-items:center;gap:5px;padding:5px 9px;border-bottom:1px solid var(--line);background:#fff;overflow-x:auto;scrollbar-width:none;flex:0 0 auto}.panel-tabs::-webkit-scrollbar{display:none}.panel-tab{height:31px;padding:0 10px;border:0;border-radius:8px;background:transparent;color:#657186;font-size:12px;font-weight:650;white-space:nowrap;cursor:pointer}.panel-tab:hover{background:#f1f5fb}.rc .panel-tab.active{background:#eaf2ff;color:var(--blue)}.kwx .panel-tab.active{background:#e8f8f1;color:#13875f}iframe{width:100%;min-height:0;flex:1;border:0;background:#fff}.notice{position:fixed;left:50%;top:68px;z-index:50;transform:translate(-50%,-18px);opacity:0;pointer-events:none;padding:11px 16px;border-radius:10px;background:#172033;color:#fff;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.2);transition:.2s}.notice.show{opacity:1;transform:translate(-50%,0)}
    @media(max-width:900px){.top{padding:0 12px}.top-hint{display:none}.who{display:none}.mobile-system{display:flex}.workspace{height:calc(100vh - var(--bar) - 48px);display:block}.panel{height:100%;display:none}.panel.mobile-active{display:flex}.panel-tabs{height:40px}.panel-tab{height:29px;padding:0 9px}.logout{padding:0 9px}.logo-title{font-size:15px}}
  </style>
</head>
<body>
  <header class="top">
    <div class="logo"><div class="logo-mark">审</div><div class="logo-title">统一审核工作台</div></div>
    <div class="top-hint">RC 与 KWX 可分别切换审核功能，互不影响</div>
    <div class="account"><span class="who"><?= review_escape((string)($user['display_name'] ?: $user['username'])) ?></span><a class="logout" href="/review/logout.php">退出</a></div>
  </header>

  <div class="mobile-system" aria-label="平台切换"><button class="system-btn active" data-system="rc">RC审核</button><button class="system-btn" data-system="kwx">KWX审核</button></div>

  <main class="workspace">
    <section class="panel rc mobile-active" data-panel="rc">
      <div class="panel-head"><div class="system-name"><span class="dot"></span><span>RC · <b data-current-label="rc">AI巡查</b></span></div><div class="panel-actions"><button class="small-btn" data-action="reload" data-system="rc">刷新</button><button class="small-btn" data-action="open" data-system="rc">新窗口</button></div></div>
      <nav class="panel-tabs" data-module-nav="rc" aria-label="RC审核功能">
        <?php foreach ($modules as $key => $module): ?>
          <button class="panel-tab<?= $key === 'ai' ? ' active' : '' ?>" type="button" data-system="rc" data-module="<?= review_escape((string)$key) ?>"><?= review_escape((string)$module['label']) ?></button>
        <?php endforeach; ?>
      </nav>
      <iframe id="rcFrame" title="RC审核面板" src="<?= review_escape((string)$modules['ai']['rc']) ?>" allow="autoplay; clipboard-read; clipboard-write" referrerpolicy="same-origin"></iframe>
    </section>
    <section class="panel kwx" data-panel="kwx">
      <div class="panel-head"><div class="system-name"><span class="dot"></span><span>KWX · <b data-current-label="kwx">AI巡查</b></span></div><div class="panel-actions"><button class="small-btn" data-action="reload" data-system="kwx">刷新</button><button class="small-btn" data-action="open" data-system="kwx">新窗口</button></div></div>
      <nav class="panel-tabs" data-module-nav="kwx" aria-label="KWX审核功能">
        <?php foreach ($modules as $key => $module): ?>
          <button class="panel-tab<?= $key === 'ai' ? ' active' : '' ?>" type="button" data-system="kwx" data-module="<?= review_escape((string)$key) ?>"><?= review_escape((string)$module['label']) ?></button>
        <?php endforeach; ?>
      </nav>
      <iframe id="kwxFrame" title="KWX审核面板" src="/review/kwx-entry.php?module=ai" allow="autoplay; clipboard-read; clipboard-write"></iframe>
    </section>
  </main>
  <div class="notice" id="notice" role="status"></div>

  <script>
    const modules = <?= json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const kwxOrigin = <?= json_encode($config['kwx_proxy_origin'], JSON_UNESCAPED_SLASHES) ?>;
    const rcFrame = document.getElementById('rcFrame');
    const kwxFrame = document.getElementById('kwxFrame');
    const currentModules = { rc: 'ai', kwx: 'ai' };

    // RC 页面只在审核工作台 iframe 内加载统一皮肤，不修改 RC 原后台页面文件的默认样式。
    function applyRcReviewTheme() {
      try {
        const doc = rcFrame.contentDocument;
        if (!doc || !doc.head || !doc.body) return;
        let theme = doc.getElementById('review-workbench-theme');
        if (!theme) {
          theme = doc.createElement('link');
          theme.id = 'review-workbench-theme';
          theme.rel = 'stylesheet';
          theme.href = '/review/review-theme.css?v=20260711-2';
          doc.head.appendChild(theme);
        }
        doc.body.classList.add('review-dark-page');
        const path = rcFrame.contentWindow.location.pathname;
        doc.body.classList.toggle('ai-patrol-page', path.endsWith('/res/0607.html'));
      } catch (error) {
        console.warn('RC审核皮肤加载失败', error);
      }
    }
    rcFrame.addEventListener('load', applyRcReviewTheme);
    setTimeout(applyRcReviewTheme, 0);

    function kwxEntry(module) { return '/review/kwx-entry.php?module=' + encodeURIComponent(module); }
    function setModuleState(system, module) {
      if (!currentModules.hasOwnProperty(system) || !modules[module]) return;
      currentModules[system] = module;
      document.querySelectorAll(`.panel-tab[data-system="${system}"]`).forEach(btn => {
        btn.classList.toggle('active', btn.dataset.module === module);
      });
      const label = document.querySelector(`[data-current-label="${system}"]`);
      if (label) label.textContent = modules[module].label;
    }
    function selectModule(system, module) {
      if (!currentModules.hasOwnProperty(system) || !modules[module]) return;
      setModuleState(system, module);
      if (system === 'rc') rcFrame.src = modules[module].rc;
      else kwxFrame.src = kwxEntry(module);
    }
    document.querySelectorAll('.panel-tab').forEach(btn => {
      btn.addEventListener('click', () => selectModule(btn.dataset.system, btn.dataset.module));
    });

    document.querySelectorAll('.system-btn').forEach(btn => btn.addEventListener('click', () => {
      document.querySelectorAll('.system-btn').forEach(item => item.classList.toggle('active', item === btn));
      document.querySelectorAll('[data-panel]').forEach(panel => panel.classList.toggle('mobile-active', panel.dataset.panel === btn.dataset.system));
    }));

    document.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => {
      const system = btn.dataset.system;
      const module = currentModules[system];
      if (btn.dataset.action === 'reload') {
        if (system === 'rc') rcFrame.src = modules[module].rc;
        else kwxFrame.src = kwxEntry(module);
      } else {
        const url = system === 'rc' ? modules[module].rc : kwxEntry(module);
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
      if (!allowed || !event.data) return;
      if (event.data.type === 'review-auth-expired') {
        showNotice((event.data.system === 'kwx' ? 'KWX' : 'RC') + ' 登录状态已刷新，请点击面板右上角“刷新”');
      } else if (event.data.type === 'review-module-change') {
        setModuleState(event.data.system, event.data.module);
      }
    });
  </script>
</body>
</html>
