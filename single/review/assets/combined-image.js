/* The platform travels with each item; no action looks an item up by its bare ID. */
(() => {
  'use strict';
  const API = window.ReviewAPI;
  const platforms = ['rc', 'xh'];
  const state = {rc: {items: [], errors: []}, xh: {items: [], errors: []}};
  const origins = {rc: 'https://open.rcwulian.cn', xh: 'https://open.sxzjjx.cn'};
  const kinds = {image: '场地头像与语音房背景图', name: '场地名称', description: '场地描述', device: '设备名称与分享名称'};
  const queue = document.getElementById('image-queues');
  const status = document.getElementById('image-status');
  let loading = false;
  let acting = false;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  }

  function timeText(value, epoch = false) {
    if (!value) return '-';
    const time = new Date(epoch ? Number(value) * 1000 : String(value).replace(' ', 'T'));
    return Number.isNaN(time.getTime()) ? String(value) : time.toLocaleString('zh-CN');
  }

  function sourceImage(item) {
    const raw = String(item.image_url || '');
    if (!raw) return '';
    try {
      const url = new URL(raw, origins[item.platform] + '/api/venue/');
      return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  }

  function mediaUrl(item) {
    const url = sourceImage(item);
    return typeof API.media === 'function' ? API.media(item.platform, url) : url;
  }

  async function json(platform, path, body) {
    const options = body === undefined ? {} : {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
    };
    const response = await API.request(platform, path, options);
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('接口未返回有效数据，请重新登录或稍后重试'); }
    if (!response.ok || data.code !== 0) throw new Error(data.msg || data.message || '审核接口请求失败');
    return data;
  }

  function decorate(platform, kind, list) {
    return (Array.isArray(list) ? list : []).map(item => ({...item, platform, kind}));
  }

  async function loadPlatform(platform) {
    const tasks = [
      {name: '待审图片', path: '/api/venue/getPendingImages.php', parse: data => decorate(platform, 'image', data.data)},
      {name: '场地名称及描述', path: '/api/venue/get_audit_list.php', parse: data => [
        ...decorate(platform, 'name', data.data && data.data.name_list),
        ...decorate(platform, 'description', data.data && data.data.desc_list)
      ]},
      {name: '设备名称', path: '/api/venue/getPendingVehicleNameAudits.php', parse: data => decorate(platform, 'device', data.data)}
    ];
    const results = await Promise.allSettled(tasks.map(task => json(platform, task.path).then(task.parse)));
    state[platform].items = [];
    state[platform].errors = [];
    results.forEach((result, index) => {
      if (result.status === 'fulfilled') state[platform].items.push(...result.value);
      else state[platform].errors.push(tasks[index].name + '：' + result.reason.message);
    });
  }

  async function refresh() {
    if (loading || acting) return;
    loading = true;
    document.getElementById('image-refresh').disabled = true;
    status.textContent = '正在加载 RC 和 XH 待审内容…';
    await Promise.all(platforms.map(loadPlatform));
    loading = false;
    document.getElementById('image-refresh').disabled = false;
    render();
  }

  function render() {
    queue.replaceChildren();
    const all = platforms.flatMap(platform => state[platform].items);
    for (const [kind, title] of Object.entries(kinds)) {
      const list = all.filter(item => item.kind === kind).sort((a, b) => {
        const stamp = item => item.timestamp ? Number(item.timestamp) : Date.parse(String(item.upload_time || '').replace(' ', 'T')) / 1000 || 0;
        return stamp(b) - stamp(a);
      });
      const section = element('section', 'image-section');
      section.appendChild(element('h2', '', title + ' · ' + list.length));
      if (!list.length) section.appendChild(element('div', 'image-empty', '暂无待审内容'));
      list.forEach(item => section.appendChild(renderItem(item)));
      queue.appendChild(section);
    }
    status.textContent = platforms.map(platform => API.label(platform) + ' ' + state[platform].items.length + ' 条').join('　|　');
    document.getElementById('image-errors').textContent = platforms.flatMap(platform => state[platform].errors.map(error => API.label(platform) + ' · ' + error)).join('\n');
    window.parent.postMessage({type: 'image-card-count', count: all.length, rc: state.rc.items.length, xh: state.xh.items.length}, location.origin);
  }

  function renderItem(item) {
    const row = element('article', 'image-row');
    row.dataset.platform = item.platform;
    row.dataset.kind = item.kind;
    const info = element('div', 'image-info');
    const badge = element('span');
    badge.innerHTML = API.badge(item.platform);
    info.appendChild(badge);
    if (item.kind === 'image') {
      const img = element('img', 'image-thumb');
      img.alt = '待审核图片';
      img.src = mediaUrl(item);
      img.addEventListener('click', () => showImage(img.src));
      row.appendChild(img);
      info.appendChild(element('div', 'image-value', item.venue_name || '未知场地'));
      info.appendChild(element('div', '', '审核类型：' + (item.review_type === 'voice_room_background' ? '语音房背景图' : '场地头像')));
      info.appendChild(element('div', '', '场地 ID：' + item.id));
      info.appendChild(element('div', '', '上传时间：' + timeText(item.upload_time)));
    } else if (item.kind === 'device') {
      info.appendChild(element('div', 'image-value', (item.field === 'name' ? '设备名称：' : '分享名称：') + (item.new || '')));
      info.appendChild(element('div', '', '原值：' + (item.old || '-')));
      info.appendChild(element('div', '', '设备 ID：' + item.device_id));
      info.appendChild(element('div', '', '提交时间：' + timeText(item.timestamp, true)));
    } else {
      info.appendChild(element('div', 'image-value', (item.kind === 'name' ? '提交名称：' : '提交描述：') + (item.kind === 'name' ? item.venue_name || '' : item.venue_description || '')));
      info.appendChild(element('div', '', '场地 ID：' + item.venue_id));
      info.appendChild(element('div', '', '提交时间：' + timeText(item.timestamp, true)));
    }
    row.appendChild(info);
    const actions = element('div', 'image-actions');
    for (const action of ['approve', 'reject']) {
      const button = element('button', action === 'reject' ? 'danger' : '', action === 'approve' ? '通过' : '拒绝');
      button.type = 'button';
      button.addEventListener('click', () => handle(item, action, row));
      actions.appendChild(button);
    }
    row.appendChild(actions);
    return row;
  }

  function dialog({title, message, reject = false, alert = false}) {
    const mask = document.getElementById('image-dialog-mask');
    const reason = document.getElementById('image-dialog-reason');
    const cancel = document.getElementById('image-dialog-cancel');
    const submit = document.getElementById('image-dialog-confirm');
    document.getElementById('image-dialog-title').textContent = title;
    document.getElementById('image-dialog-message').textContent = message;
    reason.hidden = !reject;
    reason.value = reject ? '内容不适宜' : '';
    cancel.hidden = alert;
    submit.textContent = alert ? '知道了' : reject ? '确认拒绝' : '确认通过';
    submit.classList.toggle('danger', reject);
    mask.hidden = false;
    return new Promise(resolve => {
      const close = confirmed => {
        if (confirmed && reject && !reason.value.trim()) { reason.focus(); return; }
        mask.hidden = true;
        cancel.onclick = null;
        submit.onclick = null;
        document.removeEventListener('keydown', escape);
        resolve({confirmed, reason: reason.value.trim()});
      };
      const escape = event => { if (event.key === 'Escape') close(false); };
      cancel.onclick = () => close(false);
      submit.onclick = () => close(true);
      document.addEventListener('keydown', escape);
      (reject ? reason : submit).focus();
    });
  }

  function pendingFilename(item) {
    const url = new URL(sourceImage(item));
    const marker = '/pending_images/';
    const offset = url.pathname.indexOf(marker);
    if (offset < 0) throw new Error('待审核图片路径无效，请刷新后重试');
    const filename = decodeURIComponent(url.pathname.slice(offset + marker.length));
    if (!filename || filename.includes('/') || filename.includes('\\') || filename === '.' || filename === '..') throw new Error('待审核图片文件名无效');
    return 'pending_images/' + filename;
  }

  async function reviewImage(item, action, reason) {
    const platform = item.platform;
    if (item.review_type === 'voice_room_background') {
      if (!item.review_id) throw new Error('语音房背景图审核记录 ID 缺失，请刷新后重试');
      return json(platform, '/api/venue/reviewVoiceRoomBg.php', {venue_id: Number(item.id), review_id: item.review_id, action, reason});
    }
    // Validate the cleanup path before changing the avatar or rejection state.
    const filename = pendingFilename(item);
    if (action === 'approve') {
      const response = await API.fetchImage(item.platform, sourceImage(item));
      if (!response.ok) throw new Error('图片读取失败：HTTP ' + response.status);
      const blob = await response.blob();
      if (blob.size < 100) throw new Error('图片太小或无效，无法设置为场地头像');
      const form = new FormData();
      form.append('file', blob, 'venue_' + Number(item.id) + '.jpg');
      form.append('action', 'update_venue_pictrue');
      form.append('venue_id', String(item.id));
      const result = await API.upload(platform, form);
      if (!result || result.code !== 0) throw new Error(result && (result.msg || result.message) || '头像上传失败');
    } else {
      await json(platform, '/api/venue/rejectImage.php', {venue_id: Number(item.id), reason, filename});
    }
    try {
      return await json(platform, '/api/venue/deletePendingImage.php', {venue_id: Number(item.id), filename});
    } catch (error) {
      throw new Error('审核已提交，但待审图片清理失败：' + error.message + '。请刷新检查，避免重复提交。');
    }
  }

  async function handle(item, action, row) {
    if (acting || loading) return;
    acting = true;
    const result = await dialog({
      title: API.label(item.platform) + ' · ' + (action === 'approve' ? '确认通过' : '拒绝审核'),
      message: (action === 'approve' ? '确认通过这条' : '请输入拒绝这条') + kinds[item.kind] + (action === 'approve' ? '审核？' : '审核的原因。'),
      reject: action === 'reject'
    });
    if (!result.confirmed) { acting = false; return; }
    row.querySelectorAll('button').forEach(button => button.disabled = true);
    try {
      if (item.kind === 'image') await reviewImage(item, action, result.reason);
      else if (item.kind === 'device') await json(item.platform, '/api/venue/reviewVehicleNameAudit.php', {device_id: item.device_id, field: item.field, action, reason: result.reason});
      else await json(item.platform, item.kind === 'name' ? '/api/venue/review_venue_name.php' : '/api/venue/review_venue_description.php', {venue_id: item.venue_id, action, reason: result.reason});
      await loadPlatform(item.platform);
      render();
    } catch (error) {
      await dialog({title: API.label(item.platform) + ' · 处理失败', message: error.message, alert: true});
    } finally {
      row.querySelectorAll('button').forEach(button => button.disabled = false);
      acting = false;
    }
  }

  function showImage(url) {
    const preview = document.getElementById('image-preview');
    preview.querySelector('img').src = url;
    preview.hidden = false;
  }
  document.getElementById('image-preview').addEventListener('click', event => {
    if (event.target.tagName !== 'IMG') document.getElementById('image-preview').hidden = true;
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.getElementById('image-preview').hidden = true;
  });
  document.getElementById('image-refresh').addEventListener('click', refresh);
  refresh();
})();
