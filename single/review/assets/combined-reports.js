(() => {
  'use strict';
  const API = window.ReviewAPI;
  const platforms = ['rc', 'xh'];
  const history = new URLSearchParams(location.search).get('history') === '1';
  const states = Object.fromEntries(platforms.map(p => [p, {role: 0, records: [], page: 1, pages: 1, total: 0, status: '加载中…', error: false, loading: false}]));
  const list = document.getElementById('report-list');
  const modal = document.getElementById('operation-modal');
  const preview = document.getElementById('preview');
  const filters = document.getElementById('history-filters');
  let platformFilter = 'all', loading = false;
  const el = (tag, cls, text) => { const node = document.createElement(tag); if (cls) node.className = cls; if (text !== undefined) node.textContent = String(text ?? ''); return node; };
  function badge(p) { const span = el('span'); span.innerHTML = API.badge(p); return span; }
  function safeURL(url, p) {
    try { const parsed = new URL(String(url || ''), p === 'xh' ? 'https://open.sxzjjx.cn/' : 'https://open.rcwulian.cn/'); return /^https?:$/.test(parsed.protocol) ? parsed.href : ''; } catch (_) { return ''; }
  }
  async function json(p, path, options) {
    const response = await API.request(p, path, options);
    const data = await response.json();
    if (!response.ok || Number(data.code) !== 0) throw new Error(data.msg || data.message || '请求失败');
    return data;
  }
  function formPost(p, path, values) {
    return json(p, path, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body: new URLSearchParams(values).toString()});
  }
  function detail(label, value) {
    const item = el('div', 'detail-item'); item.append(el('div', 'detail-label', label), el('div', 'detail-value', value === 0 ? 0 : value || '-')); return item;
  }
  function button(text, cls, onClick) { const b = el('button', cls, text); b.type = 'button'; b.addEventListener('click', onClick); return b; }
  function showImage(url, p) { const src = safeURL(url, p); if (!src) return; preview.querySelector('img').src = API.media(p, src); preview.hidden = false; }
  preview.addEventListener('click', () => { preview.hidden = true; preview.querySelector('img').src = ''; });
  function rowDate(record) { return String(record.insert_time || record.created_at || ''); }
  function renderCard(record, p) {
    const card = el('article', 'report-card');
    card.dataset.platform = p;
    card.dataset.recordKey = [p, record.source_table || record.record_source || 'Reports', record.id].join(':');
    const top = el('div', 'card-top'), tags = el('div', 'card-tags');
    tags.append(badge(p), el('span', 'kind-tag', record.report_type_text || record.source_label || (record.source_table === 'voice_reports' ? '语音房举报' : '设备举报')));
    const status = record.status || '未知'; tags.append(el('span', 'status-tag ' + (status === '未处理' ? 'pending' : status === '处理中' ? 'working' : 'done'), status));
    top.append(tags, el('time', 'muted', rowDate(record))); card.append(top);
    const details = el('div', 'detail-grid');
    const voice = record.source_table === 'voice_reports' || /^voice_/.test(record.record_source || '');
    if (voice) {
      const isRoom = String(record.report_type) === '0' || record.record_source === 'voice_room';
      details.append(detail('举报人 UID', record.reporter_uid), detail('举报人昵称', record.reporter_name), detail(isRoom ? '被举报场地 ID' : '被举报用户 UID', record.target_id || record.handler_uid), detail(isRoom ? '被举报场地名称' : '被举报用户昵称', record.target_name));
    } else {
      details.append(detail('设备 ID', record.device_id), detail('设备名称', record.vehicle_name), detail('场地 ID', record.bind_site || record.venue_id), detail('举报人 UID', record.reporter_uid), detail('处理人 ID', record.handler_uid));
    }
    if (record.venue_name) details.append(detail('场地名称', record.venue_name));
    card.append(details, el('div', 'report-content', record.report_content || record.report_type_text || '-'));
    if (record.notes) card.append(el('div', 'report-content', '备注：' + record.notes));
    const imageURL = safeURL(record.image_url, p);
    if (imageURL) { const image = el('img', 'report-image'); image.src = API.media(p, imageURL); image.alt = '举报截图'; image.loading = 'lazy'; image.addEventListener('click', () => showImage(imageURL, p)); card.append(image); }
    if (!history && [1, 2].includes(states[p].role)) {
      const actions = el('div', 'action-row');
      actions.append(button('修改处理状态', '', () => statusAction(record, p)), button('无效', 'danger', () => invalidAction(record, p)));
      const comment = voice && String(record.report_type) === '1';
      const canBan = (record.source_table || 'Reports') === 'Reports' || (voice && String(record.report_type) === '0');
      actions.append(button('有效', 'success', () => comment ? muteModal(record, p) : canBan ? banModal(record, p) : markDoneAction(record, p)));
      card.append(actions);
    }
    return card;
  }
  function render() {
    const messages = platforms.map(p => API.label(p) + '：' + states[p].status);
    const status = document.getElementById('source-status'); status.textContent = messages.join('\n'); status.classList.toggle('status-error', platforms.some(p => states[p].error));
    const records = platforms.flatMap(p => states[p].records.map(record => ({record, p}))).filter(x => platformFilter === 'all' || x.p === platformFilter).sort((a,b) => rowDate(b.record).localeCompare(rowDate(a.record)));
    list.replaceChildren(...records.map(x => renderCard(x.record, x.p)));
    if (!records.length) list.append(el('p', 'empty-note', loading ? '正在加载…' : '暂无记录'));
    const pager = document.getElementById('history-pagination'); pager.replaceChildren();
    if (history) platforms.filter(p => platformFilter === 'all' || p === platformFilter).forEach(p => {
      const s = states[p];
      if (s.page < s.pages) { const more = button(API.label(p) + ' 加载更多（' + s.page + '/' + s.pages + '）', '', () => loadPlatform(p, true)); more.disabled = loading || s.loading; pager.append(more); }
    });
  }
  async function loadPlatform(p, more = false) {
    const s = states[p];
    if (s.loading) return;
    s.loading = true;
    render();
    try {
      if (!s.role) { const user = await json(p, '/api/operat/get_current_user.php'); s.role = Number(user.data && user.data.role_id || 0); }
      let path = '/api/operat/ReporthandV2.php';
      const page = more ? s.page + 1 : 1;
      if (history) { const query = new URLSearchParams(new FormData(filters)); query.set('page', page); query.set('page_size', '20'); path = '/api/operat/ReporthandRecord.php?' + query; }
      const data = await json(p, path);
      const rows = Array.isArray(data.data) ? data.data : [];
      s.records = more ? s.records.concat(rows) : rows;
      if (history) { s.page = Number(data.pagination && data.pagination.page || page); s.pages = Number(data.pagination && data.pagination.total_pages || 1); s.total = Number(data.pagination && data.pagination.total || 0); }
      s.status = history ? '已加载 ' + s.records.length + ' / 共 ' + s.total + ' 条' : rows.length + ' 条待处理'; s.error = false;
    } catch (error) { s.records = []; s.error = true; s.status = error.message || '加载失败'; }
    s.loading = false;
    render();
  }
  async function reload() {
    if (loading) return;
    loading = true; document.getElementById('reload').disabled = true;
    await Promise.allSettled(platforms.map(p => loadPlatform(p)));
    loading = false; document.getElementById('reload').disabled = false; render();
  }
  function statusValue(record) { return record.status === '未处理' ? '处理中' : record.status === '处理中' ? '已处理' : '未处理'; }
  async function updateStatus(record, p, status) {
    return formPost(p, '/api/operat/ReporthandV2.php', {report_id: record.id, status, source_table: record.source_table || 'Reports'});
  }
  async function statusAction(record, p) {
    const next = statusValue(record);
    if (!confirm(API.label(p) + '：将此条举报改为“' + next + '”？')) return;
    try { await updateStatus(record, p, next); await loadPlatform(p); } catch (error) { alert(API.label(p) + '：' + error.message); }
  }
  async function markDoneAction(record, p) {
    if (!confirm(API.label(p) + '：将此条举报标记为已处理？')) return;
    try { await updateStatus(record, p, '已处理'); await loadPlatform(p); } catch (error) { alert(API.label(p) + '：' + error.message); }
  }
  async function invalidAction(record, p) {
    if (!confirm(API.label(p) + '：确定将此条举报标记为无效？')) return;
    try { await formPost(p, '/api/operat/ReporthandV2.php', {delete_id: record.id, source_table: record.source_table || 'Reports'}); await loadPlatform(p); } catch (error) { alert(API.label(p) + '：' + error.message); }
  }
  function dialog(title, p) {
    modal.replaceChildren(); const box = el('form', 'operations-dialog'); box.append(el('h2', '', title), badge(p)); modal.append(box); modal.hidden = false;
    const error = el('div', 'dialog-error'); error.setAttribute('role', 'alert');
    const actions = el('div', 'dialog-actions'); const cancel = button('取消', '', () => { modal.hidden = true; }); const submit = el('button', 'primary', '确认'); submit.type = 'submit'; actions.append(cancel, submit);
    return {box, error, actions, submit, cancel};
  }
  function field(box, label, tag = 'input', options = []) {
    const wrapper = el('label', '', label), input = el(tag);
    options.forEach(([value, text]) => { const option = el('option', '', text); option.value = value; input.append(option); }); wrapper.append(input); box.append(wrapper); return input;
  }
  const durations = [['600','10分钟'],['1800','半小时'],['3600','1小时'],['21600','6小时'],['43200','半天'],['86400','1天'],['259200','3天'],['604800','1周']];
  function muteModal(record, p) {
    const uid = String(record.target_id || record.handler_uid || '');
    if (!/^\d+$/.test(uid) || Number(uid) <= 0) return alert(API.label(p) + '：被举报用户 UID 无效');
    const d = dialog('全场禁言', p); d.box.append(el('p', 'dialog-tip', '被举报用户：' + uid + (record.target_name ? '（' + record.target_name + '）' : '')));
    const duration = field(d.box, '禁言时长', 'select', durations); duration.value = '1800';
    const reason = field(d.box, '禁言原因', 'textarea'); reason.required = true;
    const updateReason = () => { reason.value = '存在违规发言，现已被禁言' + duration.options[duration.selectedIndex].textContent; }; updateReason(); duration.addEventListener('change', updateReason);
    d.box.append(el('p', 'dialog-tip', '将对该平台全部场地写入限时禁言，并将此条举报标记为已处理。'), d.error, d.actions);
    d.box.addEventListener('submit', async event => {
      event.preventDefault(); if (!reason.value.trim()) return;
      d.submit.disabled = d.cancel.disabled = true; d.error.textContent = '';
      try { const result = await formPost(p, '/api/operat/venue_mute_records_api.php', {action:'global_mute', report_id: record.id, banned_uid:uid, duration_seconds:duration.value, reason:reason.value.trim()}); modal.hidden = true; alert(API.label(p) + '：全场禁言成功，覆盖 ' + Number(result.data && result.data.venue_count || 0) + ' 个场地'); await loadPlatform(p); }
      catch (error) { d.error.textContent = error.message; }
      finally { d.submit.disabled = d.cancel.disabled = false; }
    });
  }
  function banModal(record, p) {
    const d = dialog('举报有效 · 封禁处理', p);
    const room = record.source_table === 'voice_reports' && String(record.report_type) === '0';
    const type = field(d.box, '封禁类型', 'select', [['device','封禁设备'],['venue','封禁场地']]); type.value = room ? 'venue' : 'device'; if (room) type.querySelector('option[value="device"]').disabled = true;
    const duration = field(d.box, '封禁时长', 'select', durations);
    const venue = field(d.box, '场地 ID'); venue.value = room ? record.target_id || record.handler_uid || '' : record.bind_site || record.venue_id || ''; venue.readOnly = true;
    const serial = field(d.box, '图传序列号'); serial.value = record.image_device_serial || ''; serial.readOnly = true;
    const reason = field(d.box, '封禁原因', 'textarea'); reason.required = true; reason.value = record.report_content || '';
    d.box.append(d.error, d.actions); let banCommitted = false;
    d.box.addEventListener('submit', async event => {
      event.preventDefault(); if (!reason.value.trim()) return;
      if (type.value === 'device' && !String(record.device_id || '')) { d.error.textContent = '缺少设备序列号，无法封禁设备'; return; }
      if (type.value === 'venue' && !/^\d+$/.test(String(venue.value))){ d.error.textContent = '场地 ID 无效'; return; }
      d.submit.disabled = d.cancel.disabled = true; d.error.textContent = '';
      try {
        if (!banCommitted) {
          await json(p, '/api/devMgr/devMgtV2.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ban:true,id:1,serial_number:String(record.device_id || ''),ban_duration:duration.value,ban_reason:reason.value.trim(),banType:type.value,venue_id:venue.value,image_device_serial:serial.value || undefined,image_url:record.image_url || ''})});
          banCommitted = true;
        }
        await updateStatus(record, p, '已处理'); modal.hidden = true; alert(API.label(p) + '：封禁成功'); await loadPlatform(p);
      } catch (error) { d.error.textContent = (banCommitted ? '封禁已成功，举报状态更新失败。点击下方按钮仅重试状态更新：' : '') + error.message; if (banCommitted) { d.submit.textContent = '重试标记已处理'; [type,duration,reason].forEach(input => input.disabled = true); } }
      finally { d.submit.disabled = d.cancel.disabled = false; }
    });
  }
  document.querySelectorAll('[data-platform-filter]').forEach(b => b.addEventListener('click', () => { platformFilter = b.dataset.platformFilter; document.querySelectorAll('[data-platform-filter]').forEach(x => x.classList.toggle('active', x === b)); render(); }));
  document.getElementById('reload').addEventListener('click', reload);
  filters.addEventListener('submit', event => { event.preventDefault(); reload(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { preview.hidden = true; if (!modal.querySelector('button:disabled')) modal.hidden = true; } });
  if (history) { document.getElementById('report-title').textContent = '投诉举报 · 处理记录'; const link = document.getElementById('history-link'); link.href = '/review/combined-reports.php'; link.textContent = '返回待处理'; filters.hidden = false; }
  reload();
})();
