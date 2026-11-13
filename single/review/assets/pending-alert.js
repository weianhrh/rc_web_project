/* One reminder for the combined RC/XH queues, independent of the selected module. */
(() => {
  'use strict';
  const API = window.ReviewAPI;
  const workbench = window.ReviewWorkbench;
  const dialog = document.getElementById('pendingReviewDialog');
  if (!API || !workbench || !dialog) return;
  const sources = ['rc', 'xh'];
  const state = Object.fromEntries(sources.map(source => [source, {count:null, keys:new Set()}]));
  const acknowledged = Object.fromEntries(sources.map(source => [source, {count:0, keys:new Set()}]));
  const later = document.getElementById('pendingReviewLater');
  const go = document.getElementById('pendingReviewGo');
  const frame = document.getElementById('rcFrame');
  let polling = false, queued = false, previousFocus = null;

  function acknowledge() {
    sources.forEach(source => {
      if (state[source].count === null) return;
      acknowledged[source] = {count:state[source].count, keys:new Set(state[source].keys)};
    });
  }
  function checkinOpen() {
    return Boolean(document.querySelector('[data-rc-checkin-dialog="true"]'));
  }
  function close(restoreFocus = true) {
    dialog.hidden = true;
    if (restoreFocus && previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) previousFocus.focus();
    previousFocus = null;
  }
  function counts() {
    document.getElementById('pendingRcCount').textContent = 'RC物联：' + (state.rc.count === null ? '待获取' : state.rc.count + ' 条');
    document.getElementById('pendingXhCount').textContent = '星河远控：' + (state.xh.count === null ? '待获取' : state.xh.count + ' 条');
  }
  function update() {
    counts();
    const pending = sources.some(source => state[source].count > 0);
    const signin = checkinOpen();
    if (workbench.currentLeftModule() === 'image') { acknowledge(); close(!signin); return; }
    // 签到优先；这里只隐藏提醒，不把尚未查看的图文内容视为已提醒。
    if (signin) { close(false); return; }
    if (!pending) { close(); return; }
    const fresh = sources.some(source => {
      const current = state[source], seen = acknowledged[source];
      return current.count > 0 && (current.count > seen.count || [...current.keys].some(key => !seen.keys.has(key)));
    });
    if (fresh && dialog.hidden) {
      previousFocus = document.activeElement;
      dialog.hidden = false;
      go.focus();
    }
  }
  function record(source, data) {
    const rawCount = data && data.count;
    const count = typeof rawCount === 'number' ? rawCount : typeof rawCount === 'string' && /^\d+$/.test(rawCount) ? Number(rawCount) : NaN;
    if (Number(data.code) !== 0 || !Number.isSafeInteger(count) || count < 0) throw new Error(data.msg || '待审数量无效');
    const rows = Array.isArray(data.data) ? data.data : [];
    const keys = new Set(rows.filter(row => row && typeof row === 'object').map(row => JSON.stringify([
      row.review_type || '', row.review_id || '', row.id ?? '', row.image_url || '', row.upload_time || ''
    ])));
    state[source] = {count, keys};
    // 减少/清空不重复弹窗；后续新增项目可以重新提醒。
    const seen = acknowledged[source];
    seen.count = Math.min(seen.count, count);
    seen.keys = new Set([...seen.keys].filter(key => keys.has(key)));
    update();
  }
  async function poll() {
    if (polling) { queued = true; return; }
    polling = true;
    try {
      await Promise.allSettled(sources.map(async source => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
          const response = await API.request(source, '/api/venue/getPendingImages.php', {signal:controller.signal});
          if (!response.ok) throw new Error('HTTP ' + response.status);
          record(source, await response.json());
        } catch (error) {
          // 某个平台失败时保留上次已知数量，不误判为队列已清空。
          console.warn(source.toUpperCase() + ' 图文待审提醒检查失败', error);
        } finally {
          clearTimeout(timeout);
        }
      }));
    } finally {
      polling = false;
      if (queued) { queued = false; setTimeout(poll, 0); }
    }
  }
  later.addEventListener('click', () => { acknowledge(); close(); });
  go.addEventListener('click', () => { acknowledge(); close(); workbench.openImageReview(); });
  document.addEventListener('keydown', event => {
    if (dialog.hidden || checkinOpen()) return;
    if (event.key === 'Escape') { event.preventDefault(); acknowledge(); close(); }
    if (event.key === 'Tab') {
      if (event.shiftKey && document.activeElement === later) { event.preventDefault(); go.focus(); }
      else if (!event.shiftKey && document.activeElement === go) { event.preventDefault(); later.focus(); }
    }
  });
  window.addEventListener('review-checkin-open', update);
  window.addEventListener('review-checkin-close', update);
  window.addEventListener('review-left-module-change', () => { update(); poll(); });
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || !frame || event.source !== frame.contentWindow || !event.data || event.data.type !== 'image-card-count') return;
    // 页面可能只加载成功一个平台，重新查询接口而不直接采用消息中的零数量。
    poll();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  poll();
  setInterval(poll, 30000);
})();
