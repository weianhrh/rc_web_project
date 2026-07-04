(function () {
  if (window.__RC_NET_STATUS_INIT__) return;
  window.__RC_NET_STATUS_INIT__ = true;

  var PING_URL = window.RC_NET_STATUS_PING_URL || './api/common/ping.php';
  var INTERVAL = window.RC_NET_STATUS_INTERVAL || 5000;
  var TIMEOUT = window.RC_NET_STATUS_TIMEOUT || 3000;
  var GOOD_LIMIT = window.RC_NET_STATUS_GOOD_LIMIT || 200;
  var timer = null;

  function injectStyle() {
    if (document.getElementById('rc-net-status-style')) return;

    var style = document.createElement('style');
    style.id = 'rc-net-status-style';
    style.innerHTML = '' +
      '.layui-layout-admin .layui-header .rc-net-nav-item>a{padding:0 10px!important;}' +
      '#rcNetStatus.rc-net-status{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 9px;border-radius:999px;font-size:12px;line-height:24px;vertical-align:middle;background:rgba(255,255,255,.24);color:rgba(0,0,0,.58);transition:all .2s ease;}' +
      '#rcNetStatus .rc-net-dot{width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;flex:0 0 auto;}' +
      '#rcNetStatus .rc-net-text{font-weight:600;letter-spacing:.1px;}' +
      '#rcNetStatus.rc-net-good{background:rgba(34,197,94,.16);color:#168a43;}' +
      '#rcNetStatus.rc-net-good .rc-net-dot{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.16);}' +
      '#rcNetStatus.rc-net-bad{background:rgba(239,68,68,.16);color:#c62828;}' +
      '#rcNetStatus.rc-net-bad .rc-net-dot{background:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.16);}' +
      '#rcNetStatus.rc-net-unknown{background:rgba(148,163,184,.18);color:#64748b;}' +
      '@media (max-width:768px){.layui-layout-admin .layui-header .rc-net-nav-item>a{padding:0 6px!important;}#rcNetStatus.rc-net-status{height:22px;padding:0 7px;font-size:11px;}.rc-net-label{display:none;}}';
    document.head.appendChild(style);
  }

  function ensureDom() {
    var el = document.getElementById('rcNetStatus');
    if (el) return el;

    var rightNav = document.querySelector('.layui-header .layui-layout-right');
    if (!rightNav) return null;

    var li = document.createElement('li');
    li.className = 'layui-nav-item rc-net-nav-item';
    li.setAttribute('lay-unselect', '');
    li.innerHTML = '<a href="javascript:;" title="网络延迟">' +
      '<span id="rcNetStatus" class="rc-net-status rc-net-unknown">' +
      '<i class="rc-net-dot"></i>' +
      '<span class="rc-net-label">网络</span>' +
      '<span class="rc-net-text">--ms</span>' +
      '</span>' +
      '</a>';

    rightNav.insertBefore(li, rightNav.firstElementChild || null);
    return document.getElementById('rcNetStatus');
  }

  function setStatus(ms, status) {
    var el = ensureDom();
    if (!el) return;

    var textEl = el.querySelector('.rc-net-text');
    el.classList.remove('rc-net-good', 'rc-net-bad', 'rc-net-unknown');

    if (status === 'offline' || ms === null) {
      el.classList.add('rc-net-bad');
      if (textEl) textEl.textContent = '离线';
      el.title = '网络异常或请求超时';
      return;
    }

    if (typeof ms === 'number') {
      if (textEl) textEl.textContent = ms + 'ms';
      el.classList.add(ms <= GOOD_LIMIT ? 'rc-net-good' : 'rc-net-bad');
      el.title = ms <= GOOD_LIMIT ? '网络良好：' + ms + 'ms' : '网络较慢：' + ms + 'ms';
      return;
    }

    el.classList.add('rc-net-unknown');
    if (textEl) textEl.textContent = '--ms';
    el.title = '正在检测网络延迟';
  }

  function buildUrl() {
    return PING_URL + (PING_URL.indexOf('?') === -1 ? '?' : '&') + '_t=' + Date.now();
  }

  function ping() {
    if (!navigator.onLine) {
      setStatus(null, 'offline');
      return;
    }

    var controller = new AbortController();
    var timeoutId = setTimeout(function () {
      controller.abort();
    }, TIMEOUT);
    var start = performance.now();

    fetch(buildUrl(), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'include',
      signal: controller.signal
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      var ms = Math.round(performance.now() - start);
      setStatus(ms);
    }).catch(function () {
      setStatus(null, 'offline');
    }).finally(function () {
      clearTimeout(timeoutId);
    });
  }

  function start() {
    injectStyle();
    setStatus();

    if (!ensureDom()) {
      setTimeout(start, 300);
      return;
    }

    ping();
    if (timer) clearInterval(timer);
    timer = setInterval(ping, INTERVAL);

    window.addEventListener('online', ping);
    window.addEventListener('offline', function () {
      setStatus(null, 'offline');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
