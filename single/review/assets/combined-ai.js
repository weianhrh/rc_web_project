/* Audit workbench only: the platform is kept outside the upstream record keys. */
(() => {
  'use strict';
  const API = window.ReviewAPI;
  const platforms = ['rc', 'xh'];
  const state = {
    rc: { items: [], error: '', loaded: false, sensitive: [] },
    xh: { items: [], error: '', loaded: false, sensitive: [] }
  };
  const riskNames = { safe: '安全', low: '低风险', medium: '中风险', high: '高风险' };
  const riskWeight = { high: 3, medium: 2, low: 1, safe: 0 };
  const $ = id => document.getElementById(id);
  let soundEnabled = true;
  let showMode = 'all';
  try { showMode = localStorage.getItem('reviewCombinedPredictedMode') || 'all'; } catch (_) {}
  if (!['all', 'onlyPredicted', 'onlyNonPredicted'].includes(showMode)) showMode = 'all';
  let busy = false;
  let selected = null;
  let signature = '';
  let cardRecords = new Map();
  const manualValues = new Map();
  const actionKeys = new Set();

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function label(platform) { return platform === 'xh' ? '星河远控 XH' : 'RC物联 RC'; }
  function badge(platform) { return el('span', 'ai-source-badge ' + platform, platform.toUpperCase()); }
  function count(value) { const n = Number(value); return Number.isFinite(n) && n >= 0 ? n : 0; }
  function predicted(item) {
    return item.source_type ? item.source_type === 'predicted' : Boolean(item.remark);
  }
  function keyFor(platform, item) {
    return JSON.stringify([platform, item.archive_key || '', item.redis_key || '', item.key || '', item.image_url || '', item.time || '']);
  }
  function originalImage(item) { return String(item.image_url || ''); }
  function displayImage(item) { return originalImage(item).replace(/\/pending_images_folder\//i, '/risk_images_folder/'); }
  function swapImage(url) {
    if (/\/pending_images_folder\//i.test(url)) return url.replace(/\/pending_images_folder\//i, '/risk_images_folder/');
    if (/\/risk_images_folder\//i.test(url)) return url.replace(/\/risk_images_folder\//i, '/pending_images_folder/');
    return url;
  }
  function directImage(platform, value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^data:image\/(?:jpeg|png|gif|webp);base64,/i.test(raw)) return raw;
    try {
      const url = new URL(raw, platform === 'xh' ? 'https://open.sxzjjx.cn/' : 'https://open.rcwulian.cn/');
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.port) return '';
      // 原接口可能返回 http 后台 URL；当前审核台为 HTTPS。
      const owned = ['open.rcwulian.cn', 'rcwulian.cn', 'open.sxzjjx.cn', 'sxzjjx.cn'].includes(url.hostname);
      if (owned || /\.aliyuncs\.com$/i.test(url.hostname)) url.protocol = 'https:';
      return url.href;
    } catch (_) { return ''; }
  }
  function imageCandidates(platform, item) {
    const values = [displayImage(item), originalImage(item), swapImage(displayImage(item))];
    if (item.redis_image_url) {
      const redisUrl = String(item.redis_image_url);
      values.push(redisUrl.replace(/\/pending_images_folder\//i, '/risk_images_folder/'), redisUrl, swapImage(redisUrl));
    }
    const candidates = [];
    for (const value of values) {
      const direct = directImage(platform, value);
      if (!direct) continue;
      // 图片展示保留浏览器原来的 URL 读取方式，避免 PHP 本地路径/TLS 差异。
      // 星河仍优先走已登录的同源中转，直连作为兼容回退。
      const proxy = typeof API.mediaProxy === 'function' ? API.mediaProxy(platform, direct) : API.media(platform, direct);
      const attempts = platform === 'rc' ? [direct, proxy] : [proxy, direct];
      for (const attempt of attempts) if (attempt && !candidates.includes(attempt)) candidates.push(attempt);
    }
    return candidates;
  }
  async function json(platform, path, options) {
    const response = await API.request(platform, path, options);
    if (!response.ok) throw new Error('请求失败（' + response.status + '）');
    const result = await response.json();
    if (result.code === 1001 || result.code === 1002) throw new Error(result.msg || '登录失效或权限不足');
    return result;
  }
  function sourceStatus() {
    const target = $('sourceStatus');
    target.replaceChildren();
    platforms.forEach(platform => {
      const s = state[platform];
      const node = el('span', s.error ? 'failed' : '', label(platform) + '：' + (s.error ? s.error : s.loaded ? s.items.length + ' 条待审' : '加载中…'));
      target.appendChild(node);
    });
  }
  function visible(record) {
    if ($('sourceFilter').value !== 'all' && $('sourceFilter').value !== record.platform) return false;
    const isPredicted = predicted(record.item);
    return showMode === 'all' || (showMode === 'onlyPredicted' ? isPredicted : !isPredicted);
  }
  function riskSelect(record) {
    const wrap = el('label', 'ai-tagging', '实际标记：');
    const select = el('select', 'manual-risk-label');
    const values = { '': '请选择', safe: '安全', low: '低风险', medium: '中风险', high: '高风险' };
    Object.entries(values).forEach(([value, text]) => {
      const option = el('option', '', text); option.value = value; select.appendChild(option);
    });
    select.value = manualValues.get(record.id) || record.item.manual_risk || record.item.actual_label || '';
    select.addEventListener('change', () => manualValues.set(record.id, select.value));
    wrap.appendChild(select);
    return wrap;
  }
  function renderCard(record) {
    const { platform, item, id } = record;
    const card = el('article', 'ai-card');
    card.dataset.platform = platform;
    card.dataset.archiveKey = item.archive_key || '';
    card.dataset.redisKey = item.redis_key || '';
    card.dataset.sourceType = item.source_type || '';
    const photo = el('div', 'ai-photo');
    const img = el('img');
    const imageSources = imageCandidates(platform, item);
    let imageAttempt = 0;
    img.alt = label(platform) + '违规截图';
    img.loading = 'lazy';
    const failed = el('div', 'ai-image-error', '图片暂时无法加载');
    failed.hidden = true;
    const originalLink = directImage(platform, originalImage(item));
    if (originalLink && !originalLink.startsWith('data:')) {
      const link = el('a', '', '打开原图');
      link.href = originalLink;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      failed.append(el('br'), link);
    }
    img.addEventListener('error', () => {
      if (++imageAttempt < imageSources.length) img.src = imageSources[imageAttempt];
      else { img.hidden = true; failed.hidden = false; }
    });
    img.addEventListener('load', () => { img.hidden = false; failed.hidden = true; });
    if (imageSources.length) img.src = imageSources[0];
    else { img.hidden = true; failed.hidden = false; }
    img.addEventListener('click', () => {
      $('modalImage').src = img.src;
      $('imageModal').hidden = false;
    });
    photo.append(img, failed, el('span', 'ai-time', item.time || ''));
    const content = el('div', 'ai-content');
    const riskRow = el('div', 'ai-risk-row');
    const risk = Object.hasOwn(riskNames, item.risk_level) ? item.risk_level : 'unknown';
    riskRow.append(el('span', 'ai-risk ' + risk, riskNames[risk] || '待确认'), el('span', 'ai-count', count(item.hit_count) + ' 次违规'));
    const venueName = String(item.venue_name || '未绑定场地');
    const sensitive = state[platform].sensitive.some(name => name && venueName.includes(name));
    content.append(riskRow, el('div', 'ai-venue' + (sensitive ? ' sensitive' : ''), '场地：' + venueName));
    content.append(el('div', '', '设备：' + (item.device_name || item.key || '—')));
    content.append(el('div', '', '场地违规次数：' + count(item.violation_count) + ' 次'));
    content.append(el('div', '', '描述：' + (item.reason || '—')));
    const remark = el('div', 'ai-remark');
    remark.append(el('span', '', '备注：' + (item.remark || '—')), badge(platform));
    content.append(remark, riskSelect(record));
    const actions = el('div', 'ai-actions');
    const ban = el('button', '', Number(item.is_banned) === 1 ? '已封禁 · 继续处理' : '封禁');
    ban.type = 'button';
    ban.disabled = actionKeys.has(id);
    ban.addEventListener('click', () => openBan(record));
    const ignore = el('button', 'secondary', '忽略');
    ignore.type = 'button';
    ignore.disabled = actionKeys.has(id);
    ignore.addEventListener('click', () => ignoreRecord(record));
    actions.append(ban, ignore);
    content.appendChild(actions);
    card.append(photo, content);
    return card;
  }
  function render() {
    sourceStatus();
    cardRecords = new Map();
    const records = platforms.flatMap(platform => state[platform].items.map(item => ({ platform, item, id: keyFor(platform, item) })));
    records.sort((a, b) => (riskWeight[b.item.risk_level] || 0) - (riskWeight[a.item.risk_level] || 0) || String(b.item.time || '').localeCompare(String(a.item.time || '')));
    const shown = records.filter(visible);
    const container = $('violationsContainer');
    container.replaceChildren();
    shown.forEach(record => { cardRecords.set(record.id, record); container.appendChild(renderCard(record)); });
    if (!shown.length) container.append(el('div', 'ai-empty', platforms.some(p => state[p].error) ? '当前没有可显示的数据；请查看上方各平台加载状态。' : '当前筛选下暂无待审记录'));
  }
  async function refresh() {
    if (busy) return;
    busy = true;
    $('refreshAI').disabled = true;
    try {
      await Promise.all(platforms.map(async platform => {
        try {
          const result = await json(platform, '/api/pop/fetch_vuln.php');
          if (!result.success || !Array.isArray(result.violations)) throw new Error(result.message || '待审列表加载失败');
          state[platform].items = result.violations;
          state[platform].error = '';
          state[platform].loaded = true;
        } catch (error) {
          state[platform].error = error.message || '加载失败';
          // Do not leave actionable stale cards after a failed refresh.
          state[platform].items = [];
        }
      }));
      const nextSignature = JSON.stringify(platforms.map(p => [p, state[p].items]));
      const hasItems = platforms.some(p => state[p].items.length > 0);
      if (soundEnabled && hasItems && signature !== nextSignature) $('alertSound').play().catch(() => {});
      signature = nextSignature;
      render();
    } finally {
      busy = false;
      $('refreshAI').disabled = false;
    }
  }
  async function stats(platform, kind) {
    const target = $(platform + (kind === 'recognition' ? 'Recognition' : 'Vendor'));
    const isRecognition = kind === 'recognition';
    try {
      const result = await json(platform, '/api/pop/' + (isRecognition ? 'get_today_counters.php' : 'get_today_vendor_counters.php'));
      if (result.code === 404) {
        target.textContent = isRecognition ? '今日暂无识别数据' : '今日暂无厂商抓图数据';
        return;
      }
      if (result.code !== 200 || !result.data) throw new Error(result.msg || '加载失败');
      const data = result.data;
      target.textContent = isRecognition
        ? `今日识别统计：安全 ${count(data.safe_cnt)} 条 ｜ 低风险 ${count(data.low_cnt)} 条 ｜ 中风险 ${count(data.medium_cnt)} 条 ｜ 高风险 ${count(data.high_cnt)} 条`
        : `今日厂商抓图统计：CH ${count(data.ch_cnt)} 张 ｜ YL ${count(data.yl_cnt)} 张 ｜ 熊麦 ${count(data.xm_cnt)} 张 ｜ 即构 ${count(data.zego_cnt)} 张 ｜ 总计 ${count(data.total_cnt)} 张`;
    } catch (error) { target.textContent = (isRecognition ? '今日识别统计' : '今日厂商抓图统计') + '加载失败：' + error.message; }
  }
  async function sensitiveVenues(platform) {
    try {
      const result = await json(platform, '/api/pop/get_highrate_venues.php');
      if (result.code === 0 && Array.isArray(result.data?.names)) state[platform].sensitive = result.data.names.map(String);
    } catch (_) { /* Highlighting is optional; queue failures remain visible separately. */ }
  }
  function formFor(record) {
    const { item, id } = record;
    const form = new URLSearchParams();
    form.set('manual_risk', manualValues.get(id) || item.manual_risk || item.actual_label || '');
    form.set('image_url', originalImage(item));
    form.set('archive_key', item.archive_key || '');
    form.set('redis_key', item.redis_key || '');
    form.set('source_type', item.source_type || (predicted(item) ? 'predicted' : 'original'));
    return form;
  }
  function removeRecord(record) {
    state[record.platform].items = state[record.platform].items.filter(item => keyFor(record.platform, item) !== record.id);
    manualValues.delete(record.id);
    render();
  }
  async function ignoreRecord(record) {
    if (actionKeys.has(record.id) || !confirm('确认忽略 ' + label(record.platform) + ' 设备 ' + record.item.key + ' 的本次违规？')) return;
    actionKeys.add(record.id);
    render();
    try {
      const form = formFor(record);
      const serial = String(record.item.key || '').replace(/_predicted$/, '');
      form.set('serial_number', serial + (predicted(record.item) ? '_predicted' : ''));
      const result = await json(record.platform, '/api/pop/clear_device_violation.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
      if (!result.success) throw new Error(result.message || '忽略失败');
      removeRecord(record);
      alert(label(record.platform) + '：' + (result.message || '忽略成功'));
    } catch (error) { alert(label(record.platform) + '：' + error.message); }
    finally { actionKeys.delete(record.id); render(); }
  }
  function openBan(record) {
    selected = record;
    $('banTarget').textContent = label(record.platform) + ' · 设备 ' + (record.item.key || '') + ' · ' + (record.item.venue_name || '未绑定场地');
    $('banDuration').value = '30';
    $('banReason').value = record.item.reason || '违规封禁';
    $('banModal').hidden = false;
    $('banReason').focus();
  }
  function closeBan() { if ($('confirmBan').disabled) return; $('banModal').hidden = true; selected = null; }
  $('banForm').addEventListener('submit', async event => {
    event.preventDefault();
    const record = selected;
    if (!record || actionKeys.has(record.id)) return;
    actionKeys.add(record.id);
    $('confirmBan').disabled = true;
    $('cancelBan').disabled = true;
    try {
      const form = formFor(record);
      form.set('image_device_serial', String(record.item.key || '').replace(/_predicted$/, ''));
      form.set('ban_duration', $('banDuration').value);
      form.set('ban_reason', $('banReason').value.trim());
      const result = await json(record.platform, '/api/pop/handle_device_ban.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
      if (!result.success) throw new Error(result.message || '封禁失败');
      removeRecord(record);
      $('banModal').hidden = true;
      selected = null;
      alert(label(record.platform) + '：' + (result.message || '封禁成功'));
    } catch (error) { alert(label(record.platform) + '：' + error.message); }
    finally { actionKeys.delete(record.id); $('confirmBan').disabled = false; $('cancelBan').disabled = false; render(); }
  });
  $('cancelBan').addEventListener('click', closeBan);
  $('banModal').addEventListener('click', event => { if (event.target === $('banModal')) closeBan(); });
  function closeImage() { $('imageModal').hidden = true; $('modalImage').removeAttribute('src'); }
  $('closeImage').addEventListener('click', closeImage);
  $('imageModal').addEventListener('click', event => { if (event.target === $('imageModal')) closeImage(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeImage(); closeBan(); } });
  $('sourceFilter').addEventListener('change', render);
  $('refreshAI').addEventListener('click', () => { refresh(); platforms.forEach(p => { stats(p, 'recognition'); stats(p, 'vendor'); }); });
  $('soundToggle').addEventListener('click', () => {
    soundEnabled = !soundEnabled;
    $('soundToggle').textContent = '声音提醒：' + (soundEnabled ? '开启' : '关闭');
    $('soundToggle').setAttribute('aria-pressed', String(soundEnabled));
    if (soundEnabled) $('alertSound').play().catch(() => {});
  });
  function showModeLabel() { $('predictedToggle').textContent = ({ all: '全部显示', onlyPredicted: '仅显示自研', onlyNonPredicted: '仅显示非自研' })[showMode]; }
  $('predictedToggle').addEventListener('click', () => {
    showMode = ({ all: 'onlyPredicted', onlyPredicted: 'onlyNonPredicted', onlyNonPredicted: 'all' })[showMode];
    try { localStorage.setItem('reviewCombinedPredictedMode', showMode); } catch (_) {}
    showModeLabel(); render();
  });
  showModeLabel();
  platforms.forEach(platform => { stats(platform, 'recognition'); stats(platform, 'vendor'); sensitiveVenues(platform); });
  refresh();
  setInterval(refresh, 30000);
  setInterval(() => platforms.forEach(p => { stats(p, 'recognition'); stats(p, 'vendor'); }), 80000);
})();
