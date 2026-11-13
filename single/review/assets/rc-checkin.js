/* Persistent workbench RC check-in; embedded audit frames never start a second owner. */
(() => {
  'use strict';
  if (window.parent !== window || window.ReviewRcCheckin) return;
  const API = window.ReviewAPI;
  const host = document.getElementById('rcCheckinHost');
  const status = document.getElementById('rcCheckinStatus');
  if (!API || !host) return;
  let active = null, completedKey = '', checking = false, queued = false;
  const el = (tag, cls, value) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (value !== undefined) node.textContent = value;
    return node;
  };
  function emit(type) { window.dispatchEvent(new CustomEvent(type)); }
  function connectionError(error) {
    if (!status) return;
    status.textContent = /登录/.test(error.message || '') ? 'RC签到登录已失效，请重新登录' : 'RC签到检查暂时失败，正在自动重试';
    status.hidden = false;
  }
  async function call(path, options = {}, timeoutMs = 8000) {
    const controller = new AbortController();
    let timer;
    const timeout = new Promise((resolve, reject) => {
      timer = setTimeout(() => {
        controller.abort();
        reject(new Error('RC签到请求超时，请稍后重试'));
      }, timeoutMs);
    });
    const operation = (async () => {
      const response = await API.request('rc', path, {...options, signal:controller.signal});
      const result = await response.json();
      if (!response.ok) throw new Error(result.msg || result.message || 'RC签到接口请求失败');
      return result;
    })();
    try { return await Promise.race([operation, timeout]); }
    finally { clearTimeout(timer); }
  }
  function close(prompt = active) {
    if (!prompt || prompt !== active) return;
    clearTimeout(prompt.timer);
    prompt.box.remove();
    active = null;
    if (prompt.previousFocus && document.contains(prompt.previousFocus)) prompt.previousFocus.focus();
    emit('review-checkin-close');
  }
  function show(info) {
    if (!info.is_makeup && !info.planned_at) return;
    const id = JSON.stringify([info.period || '', info.planned_at || '', Boolean(info.is_makeup)]);
    if (active?.id === id || completedKey === id) return;
    close();
    const box = el('div', 'rc-checkin-overlay');
    box.setAttribute('data-rc-checkin-dialog', 'true');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'rcCheckinTitle');
    const content = el('div', 'rc-checkin-box');
    const title = el('h2', '', info.is_makeup ? 'RC物联 · 补签提醒' : 'RC物联 · 签到提醒');
    title.id = 'rcCheckinTitle';
    content.appendChild(title);
    if (info.is_makeup) content.append(el('p', '', '本时间段签到不足 3 次'), el('p', '', '给你最后一次补签机会'));
    else content.appendChild(el('p', '', '计划时间：' + info.planned_at));
    const errorMessage = el('p', 'rc-checkin-error');
    errorMessage.setAttribute('role', 'alert');
    const caption = info.is_makeup ? '立即补签' : '我在';
    const button = el('button', '', caption);
    button.type = 'button';
    content.append(errorMessage, button);
    box.appendChild(content);
    const prompt = {id, box, button, timer:null, submitting:false, previousFocus:document.activeElement};
    active = prompt;
    async function submit(auto) {
      if (active !== prompt || prompt.submitting) return;
      prompt.submitting = true;
      button.disabled = true;
      button.textContent = info.is_makeup ? '补签中…' : '签到中…';
      errorMessage.textContent = '';
      clearTimeout(prompt.timer);
      try {
        const form = new URLSearchParams();
        if (info.is_makeup) { form.set('resign', '1'); if (info.period) form.set('period', info.period); }
        else form.set('planned_at', info.planned_at || '');
        const result = await call('/api/checkin/click_redis.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:form.toString()}, 15000);
        if (active !== prompt) return;
        if (![200, 0].includes(Number(result.code))) {
          if (/已有人处理|已经补签过/.test(result.msg || '')) {
            completedKey = id;
            close(prompt);
            if (!auto) alert('RC物联：' + result.msg);
            poll();
            return;
          }
          throw new Error(result.msg || '签到失败');
        }
        completedKey = id;
        close(prompt);
        alert(auto ? 'RC物联：为避免本时间段签到不足 3 次，系统已自动为您补签一次。' : 'RC物联：' + (result.msg || '签到成功'));
      } catch (error) {
        if (active !== prompt) return;
        prompt.submitting = false;
        button.disabled = false;
        button.textContent = caption;
        errorMessage.textContent = error.message;
      }
    }
    button.addEventListener('click', () => submit(false));
    host.appendChild(box);
    emit('review-checkin-open');
    button.focus();
    if (info.is_makeup) prompt.timer = setTimeout(() => submit(true), 40000);
  }
  async function poll() {
    if (active?.submitting) return;
    if (checking) { queued = true; return; }
    checking = true;
    try {
      const result = await call('/api/checkin/next_prompt_redis.php');
      if (active?.submitting) return;
      if (Number(result.code) !== 200) throw new Error(result.msg || 'RC签到检查失败');
      if (status) status.hidden = true;
      if (!result.show || !result.data) close();
      else show(result.data);
    } catch (error) { connectionError(error); }
    finally {
      checking = false;
      if (queued) { queued = false; setTimeout(poll, 0); }
    }
  }
  document.addEventListener('keydown', event => {
    if (!active || !['Escape', 'Tab'].includes(event.key)) return;
    event.preventDefault();
    if (!active.button.disabled) active.button.focus();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  ['focus', 'pageshow', 'online', 'review-left-module-change'].forEach(type => window.addEventListener(type, poll));
  window.ReviewRcCheckin = {check:poll, isOpen:() => Boolean(active)};
  poll();
  setInterval(poll, 10000);
})();
