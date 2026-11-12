/* Shared single-device dialog and independent batch selection page. */
(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const serialPattern = /^[A-Za-z0-9][A-Za-z0-9_.:-]{2,99}$/;
    const labels = {create:'新增', update:'更新', bind:'绑定并更新', skip:'跳过'};
    function node(tag, className, text) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined) el.textContent = String(text);
        return el;
    }
    function status(el, text, kind = '') { el.textContent = text; el.className = 'xds-status' + (kind ? ' xds-' + kind : ''); }
    async function getJSON(url, options = {}, signal = null) {
        const controller = new AbortController(); let timedOut = false;
        const abort = () => controller.abort();
        if (signal) { if (signal.aborted) abort(); else signal.addEventListener('abort', abort, {once:true}); }
        const timer = setTimeout(() => {timedOut = true; controller.abort();}, 130000);
        try {
            const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options, signal:controller.signal});
            let result;
            try { result = await response.json(); } catch (error) {
                if (controller.signal.aborted) throw error;
                throw new Error('接口未返回 JSON，请检查文件是否部署完整、登录是否有效。');
            }
            if (!response.ok || result.code !== 0) throw new Error(result.msg || ('请求失败，HTTP ' + response.status));
            return result.data;
        } catch (error) {
            if (timedOut) throw new Error('请求超时。同步可能仍在处理，请重新预览核对结果后重试。');
            throw error;
        } finally { clearTimeout(timer); if (signal) signal.removeEventListener('abort', abort); }
    }
    function api(payload, signal) {
        return getJSON('../api/venue/syncDevicesToXinghe.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}, signal);
    }

    let modal, operation = null, targets = [], targetsLoaded = false, preview = null, busy = '', epoch = 0, pending = null;
    function invalidatePreview() {
        preview = null; $('xds-preview-box').hidden = true;
        $('xds-confirm').hidden = true; $('xds-confirm').disabled = true;
    }
    function setBusy(value) {
        busy = value;
        $('xds-target-fields').disabled = !!value || !targetsLoaded;
        $('xds-preview').disabled = !!value || !targetsLoaded || !$('xds-target').value;
        $('xds-target-retry').disabled = !!value;
        $('xds-confirm').disabled = !!value || !preview;
        $('xds-modal-close').disabled = value === 'sync';
        $('xds-cancel').disabled = value === 'sync';
        $('xds-preview').textContent = value === 'preview' ? '正在预览…' : '预览同步';
        if (value === 'sync') $('xds-confirm').textContent = '正在同步…';
    }
    function closeModal() { if (busy !== 'sync') modal.close(); }
    function renderTargets() {
        const selected = $('xds-target').value, query = $('xds-target-search').value.trim().toLowerCase();
        $('xds-target').replaceChildren(new Option('请选择星河已有场地', ''));
        for (const venue of targets) {
            const title = 'ID ' + venue.id + ' · ' + venue.venue_name;
            if (!query || title.toLowerCase().includes(query)) $('xds-target').add(new Option(title, String(venue.id)));
        }
        if (Array.from($('xds-target').options).some(option => option.value === selected)) $('xds-target').value = selected;
        if (selected !== $('xds-target').value) invalidatePreview();
        setBusy(busy);
    }
    async function loadTargets() {
        if (busy) return;
        const version = epoch; targetsLoaded = false; targets = []; invalidatePreview();
        $('xds-target').replaceChildren(new Option('正在读取星河场地…', ''));
        $('xds-target-retry').hidden = true;
        pending = new AbortController(); setBusy('load');
        status($('xds-modal-status'), '正在读取星河已有场地…');
        try {
            const data = await api({action:'list_venues'}, pending.signal);
            if (version !== epoch) return;
            if (!Array.isArray(data.venues)) throw new Error('星河场地列表格式异常。');
            targets = data.venues; targetsLoaded = true; renderTargets();
            status($('xds-modal-status'), targets.length ? '选择目标场地后，点击“预览同步”。' : '星河暂无已有场地，请先创建场地再重试。');
            $('xds-target-retry').hidden = targets.length > 0;
        } catch (error) {
            if (version !== epoch) return;
            status($('xds-modal-status'), error.message, 'error'); $('xds-target-retry').hidden = false;
        } finally { if (version === epoch) {pending = null; setBusy('');} }
    }
    function renderResult(data, completed = false) {
        $('xds-result-title').textContent = completed ? '同步结果' : '同步预览';
        const s = data.summary;
        $('xds-preview-summary').textContent = '星河 ID ' + data.target.id + ' · ' + data.target.venue_name + ' ｜ ' + (completed
            ? '已新增 ' + data.counts.created_devices + ' 台，已更新 ' + data.counts.updated_devices + ' 台，跳过 ' + s.skip + ' 台'
            : '新增 ' + s.create + ' 台，更新 ' + s.update + ' 台，绑定并更新 ' + s.bind + ' 台，跳过 ' + s.skip + ' 台');
        $('xds-result-rows').replaceChildren();
        for (const row of data.rows) {
            const tr = node('tr'), device = node('td','xds-result-device'), action = node('td'), detail = node('td','xds-result-detail',row.reason || '');
            device.append(node('div','',row.name || '未命名设备'),node('div','xds-result-serial',row.serial_number));
            action.append(node('span','xds-badge ' + row.action,(completed && row.action !== 'skip' ? '已' : '') + (labels[row.action] || row.action)));
            for (const warning of row.warnings || []) detail.append(node('div','xds-warning',warning));
            tr.append(device, action, detail); $('xds-result-rows').append(tr);
        }
        $('xds-preview-box').hidden = false;
    }
    async function previewDevices() {
        if (busy || !operation || !$('xds-target').value) return;
        const version = epoch, target = Number($('xds-target').value), text = operation.devices.map(v => v.serial_number).join('\n');
        invalidatePreview(); pending = new AbortController(); setBusy('preview');
        status($('xds-modal-status'), '正在核对 RC 配置、星河车辆归属和关联图传…');
        try {
            const data = await api({action:'preview',target_venue_id:target,devices_text:text}, pending.signal);
            if (version !== epoch) return;
            renderResult(data);
            const eligible = data.summary.create + data.summary.update + data.summary.bind;
            preview = eligible ? {target,text,data} : null;
            $('xds-confirm').hidden = !eligible;
            $('xds-confirm').textContent = '确认同步 ' + eligible + ' 台';
            status($('xds-modal-status'), eligible ? '尚未写入数据。请核对目标场地和上方预览，再确认同步。' : '没有可同步的设备，请查看跳过原因。',eligible ? '' : 'error');
        } catch (error) { if (version === epoch) status($('xds-modal-status'), error.message, 'error'); }
        finally { if (version === epoch) {pending = null; setBusy('');} }
    }
    async function syncDevices() {
        if (busy || !preview || !operation) return;
        const version = epoch, chosen = preview, onCompleted = operation.onCompleted;
        setBusy('sync'); status($('xds-modal-status'), '正在同步到星河，请稍候…');
        try {
            const data = await api({action:'sync',target_venue_id:chosen.target,devices_text:chosen.text,preview_token:chosen.data.preview_token});
            if (version !== epoch) return;
            renderResult(data, true); $('xds-cancel').textContent = '关闭';
            status($('xds-modal-status'), '同步完成，设备已按结果处理。', 'success');
            if (onCompleted) { try { onCompleted(data); } catch (error) { console.error('更新设备选择状态失败',error); } }
        } catch (error) {
            if (version === epoch) status($('xds-modal-status'), error.message + '\n请重新预览核对后重试。', 'error');
        } finally {
            if (version === epoch) {preview = null; $('xds-confirm').hidden = true; setBusy('');}
        }
    }
    function createModal() {
        modal = node('dialog','xds-dialog'); modal.id = 'xds-dialog'; modal.setAttribute('aria-labelledby','xds-modal-title');
        // Static template only. All device/API values below are inserted with textContent.
        modal.innerHTML = `<header class="xds-dialog-header"><div><h2 id="xds-modal-title">同步设备到星河</h2><p class="xds-muted">选择星河已有场地，核对后同步。</p></div><button id="xds-modal-close" type="button" class="xds-close" aria-label="关闭">×</button></header>
        <div class="xds-dialog-main"><div class="xds-device-summary"><strong id="xds-operation-summary"></strong><details id="xds-operation-details"><summary>查看本次设备</summary><ul id="xds-operation-devices"></ul></details></div>
        <fieldset id="xds-target-fields" disabled><div class="xds-target-fields"><label for="xds-target-search">搜索星河场地<input id="xds-target-search" type="search" placeholder="场地 ID / 名称" autocomplete="off"></label><label for="xds-target">目标场地<select id="xds-target"><option value="">请选择星河已有场地</option></select></label></div></fieldset>
        <p class="xds-target-note">设备已属于星河其他场地时会跳过；图传及控制参数从 RC 自动读取。</p><button id="xds-target-retry" type="button" class="xds-btn xds-target-retry" hidden>重新读取星河场地</button>
        <section id="xds-preview-box" class="xds-preview-box" hidden><h3 id="xds-result-title">同步预览</h3><div class="xds-preview-summary" id="xds-preview-summary"></div><div class="xds-table-wrap"><table><thead><tr><th>设备</th><th>处理</th><th>说明</th></tr></thead><tbody id="xds-result-rows"></tbody></table></div></section>
        <div id="xds-modal-status" class="xds-status" role="status" aria-live="polite"></div></div>
        <footer class="xds-dialog-footer"><button id="xds-cancel" type="button" class="xds-btn">取消</button><button id="xds-preview" type="button" class="xds-btn xds-primary" disabled>预览同步</button><button id="xds-confirm" type="button" class="xds-btn xds-primary" hidden disabled>确认同步</button></footer>`;
        document.body.append(modal);
        $('xds-modal-close').addEventListener('click',closeModal); $('xds-cancel').addEventListener('click',closeModal);
        modal.addEventListener('cancel',event => {if (busy === 'sync') event.preventDefault();});
        modal.addEventListener('close',() => {epoch++; if (pending) pending.abort(); pending = null; busy = ''; operation = null; preview = null;});
        $('xds-target-search').addEventListener('input',renderTargets);
        $('xds-target').addEventListener('change',() => {invalidatePreview(); setBusy(''); status($('xds-modal-status'),'目标已选择，请预览同步。');});
        $('xds-target-retry').addEventListener('click',loadTargets);
        $('xds-preview').addEventListener('click',previewDevices); $('xds-confirm').addEventListener('click',syncDevices);
    }
    function openSync(devices, onCompleted = null) {
        if (modal && modal.open) return;
        const unique = new Map();
        for (const device of devices || []) {
            const sn = String(device.serial_number || '').trim();
            if (!serialPattern.test(sn)) { alert('设备序列号格式异常，请先核对 RC 设备资料。'); return; }
            unique.set(sn.toLowerCase(),{serial_number:sn,name:String(device.name || '')});
        }
        if (!unique.size || unique.size > 100) {alert('每次请选择 1 至 100 台设备。'); return;}
        if (!modal) createModal();
        epoch++; operation = {devices:Array.from(unique.values()),onCompleted}; busy = ''; preview = null; targetsLoaded = false;
        const list = operation.devices;
        $('xds-operation-summary').textContent = list.length === 1 ? (list[0].name || '未命名设备') + ' · ' + list[0].serial_number : '本次已选 ' + list.length + ' 台 RC 设备';
        $('xds-operation-details').hidden = list.length === 1; $('xds-operation-details').open = false;
        $('xds-operation-devices').replaceChildren(...list.map(v => node('li','',(v.name || '未命名设备') + ' · ' + v.serial_number)));
        $('xds-target-search').value = ''; $('xds-cancel').textContent = '取消'; invalidatePreview();
        modal.showModal(); loadTargets();
    }
    window.XingheDeviceSync = {open:openSync};
    document.addEventListener('click',event => {
        const button = event.target.closest('.xds-device-button');
        if (!button || button.disabled) return;
        openSync([{serial_number:button.dataset.xdsSerial,name:button.dataset.xdsName}]);
    });

    async function initBatch() {
        let all = [], venues = new Map(), selected = new Set(), filtered = [], page = 1, loading = false;
        const pageSize = 50;
        const isUnbound = value => !String(value ?? '').trim() || String(value).trim() === '0';
        const siteName = vehicle => isUnbound(vehicle.bind_site) ? '未绑定场地' : 'ID ' + vehicle.bind_site + ' · ' + (venues.get(String(vehicle.bind_site)) || '场地名称未提供');
        function filterList() {
            const site = $('xds-source-venue').value, keyword = $('xds-keyword').value.trim().toLowerCase(), state = $('xds-source-status').value;
            return all.filter(v => (!site || (site === '__unbound__' ? isUnbound(v.bind_site) : String(v.bind_site) === site))
                && (!keyword || [v.serial_number,v.name,v.uid,v.bind_site,siteName(v)].some(value => String(value ?? '').toLowerCase().includes(keyword)))
                && (!state || (state === 'online' ? ['在线','占有'].includes(v.status) : v.status === '离线'))
                && (!$('xds-only-selected').checked || selected.has(v.serial_number)));
        }
        function render() {
            filtered = filterList(); const pages = Math.max(1,Math.ceil(filtered.length/pageSize)); page = Math.min(page,pages);
            const visible = filtered.slice((page-1)*pageSize,page*pageSize), currentSelected = filtered.filter(v => selected.has(v.serial_number)).length;
            $('xds-selected-count').textContent = '已选 ' + selected.size + ' 台' + (selected.size > currentSelected ? '（筛选外 ' + (selected.size-currentSelected) + ' 台）' : '');
            $('xds-batch-sync').disabled = !selected.size || loading; $('xds-clear').disabled = !selected.size || loading;
            $('xds-select-all').checked = filtered.length > 0 && currentSelected === filtered.length;
            $('xds-select-all').indeterminate = currentSelected > 0 && currentSelected < filtered.length;
            $('xds-select-all').disabled = !filtered.length || loading;
            $('xds-filter-count').textContent = '共 ' + all.length + ' 台 · 筛选结果 ' + filtered.length + ' 台 · 每次最多同步 100 台';
            $('xds-page-info').textContent = '第 ' + page + ' / ' + pages + ' 页，每页 ' + pageSize + ' 台';
            $('xds-prev').disabled = loading || page === 1; $('xds-next').disabled = loading || page === pages;
            $('xds-picked-details').hidden = !selected.size;
            $('xds-picked-chips').replaceChildren();
            for (const vehicle of all.filter(v => selected.has(v.serial_number))) {
                const chip = node('button','xds-chip',(vehicle.name || '未命名设备') + ' · ' + vehicle.serial_number + ' ×'); chip.type = 'button';
                chip.setAttribute('aria-label','移除 ' + vehicle.serial_number);
                chip.addEventListener('click',() => {selected.delete(vehicle.serial_number);render();}); $('xds-picked-chips').append(chip);
            }
            $('xds-vehicle-list').replaceChildren();
            if (!visible.length) $('xds-vehicle-list').append(node('div','xds-empty',loading ? '正在读取设备…' : '暂无匹配设备'));
            for (const vehicle of visible) {
                const card = node('label','xds-vehicle' + (selected.has(vehicle.serial_number) ? ' xds-selected' : ''));
                const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.value = vehicle.serial_number; checkbox.checked = selected.has(vehicle.serial_number);
                checkbox.setAttribute('aria-label','选择 ' + (vehicle.name || '') + ' ' + vehicle.serial_number);
                checkbox.addEventListener('change',() => {
                    if (checkbox.checked && selected.size >= 100) {checkbox.checked = false;status($('xds-batch-status'),'每次最多选择 100 台，请减少已选设备。','error'); return;}
                    if (checkbox.checked) selected.add(vehicle.serial_number); else selected.delete(vehicle.serial_number);
                    status($('xds-batch-status'),'选择完成后点击“批量同步到星河”，再选择目标场地。'); render();
                });
                let photo = node('span','xds-photo','设备');
                try {
                    const url = new URL(vehicle.photo_url || '',location.href);
                    if (vehicle.photo_url && ['https:','http:'].includes(url.protocol)) {photo = node('img','xds-photo');photo.src = url.href;photo.alt = '';photo.loading = 'lazy';}
                } catch (_) {}
                const info = node('div','xds-vehicle-info'), tags = node('div','xds-tags');
                info.append(node('div','xds-vehicle-title',vehicle.name || '未命名设备'),node('div','xds-vehicle-serial','序列号：' + vehicle.serial_number));
                tags.append(node('span','xds-tag',siteName(vehicle)),node('span','xds-tag','UID：' + (vehicle.uid || '未绑定')),node('span','xds-tag ' + (['在线','占有'].includes(vehicle.status) ? 'xds-online' : 'xds-offline'),vehicle.status || '未知'));
                info.append(tags);card.append(checkbox,photo,info);$('xds-vehicle-list').append(card);
            }
        }
        async function load() {
            if (loading) return; loading = true; $('xds-batch-fields').disabled = true; $('xds-reload').disabled = true;
            all = [];render();status($('xds-batch-status'),'正在读取 RC 场地和设备列表…');
            try {
                const role = await getJSON('../api/operat/get_current_user.php');
                if (![1,2].includes(Number(role.role_id))) throw new Error('仅平台管理员可同步设备到星河。');
                const [vehicles, source] = await Promise.all([getJSON('../api/devMgr/vehicles_manage.php'),getJSON('../api/devMgr/devMgtV2.php?get_venues=1')]);
                if (!Array.isArray(vehicles) || !Array.isArray(source.venues)) throw new Error('RC 场地或设备列表格式异常。');
                const unique = new Map();
                for (const v of vehicles) {
                    const sn = String(v.serial_number || '').trim();
                    if (sn && !unique.has(sn.toLowerCase())) unique.set(sn.toLowerCase(),{...v,serial_number:sn});
                }
                all = Array.from(unique.values()); venues = new Map(source.venues.map(v => [String(v.id),String(v.venue_name || '')]));
                for (const v of all) if (!isUnbound(v.bind_site) && !venues.has(String(v.bind_site))) venues.set(String(v.bind_site),'场地名称未提供');
                const oldSite = $('xds-source-venue').value;
                $('xds-source-venue').replaceChildren(new Option('全部场地',''),new Option('未绑定场地','__unbound__'));
                for (const [id,name] of Array.from(venues).sort((a,b)=>a[0].localeCompare(b[0],undefined,{numeric:true}))) $('xds-source-venue').add(new Option(id+' - '+name,id));
                if (Array.from($('xds-source-venue').options).some(o => o.value === oldSite)) $('xds-source-venue').value = oldSite;
                const present = new Set(all.map(v=>v.serial_number)); selected = new Set(Array.from(selected).filter(sn=>present.has(sn)));
                $('xds-batch-fields').disabled = false;
                status($('xds-batch-status'),'按场地或关键字筛选，勾选需要同步的设备。');
            } catch (error) {all = [];selected.clear();status($('xds-batch-status'),error.message,'error');}
            finally {loading = false; $('xds-reload').disabled = false;render();}
        }
        for (const id of ['xds-source-venue','xds-source-status','xds-only-selected']) $(id).addEventListener('change',()=>{page=1;render();});
        $('xds-keyword').addEventListener('input',()=>{page=1;render();});
        $('xds-select-all').addEventListener('change',() => {
            if ($('xds-select-all').checked) {
                const combined = new Set([...selected,...filtered.map(v=>v.serial_number)]);
                if (combined.size > 100) {status($('xds-batch-status'),'全选后共 ' + combined.size + ' 台，超过每次 100 台上限，请缩小筛选范围或取消部分已选。','error');render();return;}
                selected = combined;
            } else for (const v of filtered) selected.delete(v.serial_number);
            status($('xds-batch-status'),'选择已更新；全选只作用于当前筛选结果。');render();
        });
        $('xds-clear').addEventListener('click',()=>{selected.clear();render();});
        $('xds-prev').addEventListener('click',()=>{page--;render();}); $('xds-next').addEventListener('click',()=>{page++;render();});
        $('xds-reload').addEventListener('click',load);
        $('xds-batch-sync').addEventListener('click',()=> {
            if (loading || !selected.size) return;
            openSync(all.filter(v => selected.has(v.serial_number)),data => {
                for (const row of data.rows) if (row.action !== 'skip') selected.delete(row.serial_number);
                render();status($('xds-batch-status'),'本次同步已完成。成功设备已取消勾选，跳过的设备保留选择，便于核对。','success');
            });
        });
        load();
    }
    if ($('xds-batch-page')) initBatch();
})();
