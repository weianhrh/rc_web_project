/* RC-local migration labels and an explicit, resumable history verification. */
(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const apiUrl = '../api/venue/xingheDeviceMigration.php';
    const serialPattern = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/;
    const labels = {migrated:'已搬迁到星河',not_migrated:'未搬迁',unchecked:'搬迁状态：待核对',conflict:'搬迁状态：核对异常'};
    const cache = new Map();
    let allowed = false, readVersion = 0, refreshTimer = null;
    let running = false, stopRequested = false, cursor = '0', completed = false;
    let processed = 0, totals = {migrated:0,not_migrated:0,conflict:0};
    const key = value => String(value || '').toLowerCase();
    function element(tag, className, text) {
        const el = document.createElement(tag); el.className = className;
        if (text !== undefined) el.textContent = String(text);
        return el;
    }
    function message(id, text, error = false) {
        const el = $(id); if (!el) return;
        el.textContent = text; el.classList.toggle('xhm-error',error);
    }
    function visibleNodes() { return Array.from(document.querySelectorAll('[data-xhm-serial]')); }
    function draw(el, row) {
        const state = row && Object.prototype.hasOwnProperty.call(labels,row.state) ? row.state : 'unchecked';
        const badge = element('span','xhm-badge xhm-' + state,labels[state]);
        const children = [badge];
        if (state === 'migrated') {
            const target = row.target_venue_id == null ? '' : String(row.target_venue_id).trim();
            const name = String(row.target_venue_name || '').trim();
            children.push(element('span','xhm-target',target && target !== '0'
                ? '星河场地：' + target + (name ? ' · ' + name : '')
                : '星河场地：' + (name || '未绑定')));
        }
        if (row && row.warning) children.push(element('span','xhm-warning',row.warning));
        el.replaceChildren(...children);
        el.title = row && row.checked_at ? '最近核对：' + row.checked_at : '尚未核对星河设备，请执行“补标历史搬迁”';
    }
    function paintVisible() {
        for (const el of visibleNodes()) {
            el.hidden = !allowed;
            if (!allowed) continue;
            const sn = el.dataset.xhmSerial || '';
            if (!serialPattern.test(sn)) draw(el,{state:'conflict',warning:'设备序列号格式异常，请先核对 RC 资料'});
            else draw(el,cache.get(key(sn))?.row);
        }
    }
    async function request(payload) {
        const controller = new AbortController(); let timedOut = false;
        const timer = setTimeout(() => {timedOut = true;controller.abort();},135000);
        try {
            const response = await fetch(apiUrl,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),signal:controller.signal});
            let result;
            try {result = await response.json();} catch (error) {
                if (controller.signal.aborted) throw error;
                throw new Error('搬迁标识接口未返回 JSON，请检查改动包是否部署完整。');
            }
            if (!response.ok || result.code !== 0) throw new Error(result.msg || '读取搬迁标识失败');
            return result.data;
        } catch (error) {
            if (timedOut) throw new Error('核对超时，本批次可重试；已有搬迁标识仍保留。');
            throw error;
        } finally {clearTimeout(timer);}
    }
    function validateRows(rows, requested = null) {
        if (!Array.isArray(rows)) throw new Error('搬迁标识响应格式异常，未采用本次结果。');
        const seen = new Set(), wanted = requested && new Set(requested.map(key));
        for (const row of rows) {
            if (!row || typeof row.serial_number !== 'string' || !Object.prototype.hasOwnProperty.call(labels,row.state)) throw new Error('搬迁标识响应内容异常，未采用本次结果。');
            const id = key(row.serial_number);
            // Backfill may report duplicate local SN rows; every result must stay a conflict.
            if (requested && (seen.has(id) || !wanted.has(id))) throw new Error('搬迁标识响应与当前设备不一致。');
            seen.add(id);
        }
        if (wanted && seen.size !== wanted.size) throw new Error('部分设备未返回搬迁标识，请刷新重试。');
        return rows;
    }
    function remember(rows) {
        const stamp = Date.now(), seen = new Set(), duplicates = new Set();
        for (const row of rows) {
            const id = key(row.serial_number);
            if (seen.has(id)) duplicates.add(id);
            seen.add(id);
        }
        for (const row of rows) {
            const id = key(row.serial_number);
            const safeRow = duplicates.has(id) ? {
                serial_number: row.serial_number,
                state: 'conflict',
                checked_at: row.checked_at || null,
                warning: 'RC存在重复序列号，请先核对；历史记录已保留'
            } : row;
            cache.set(id,{row:safeRow,stamp});
        }
    }
    async function readVisible(version) {
        if (!allowed || version !== readVersion) return;
        paintVisible();
        const serials = new Map();
        for (const el of visibleNodes()) {
            const sn = el.dataset.xhmSerial || '', stored = cache.get(key(sn));
            if (serialPattern.test(sn) && (!stored || Date.now() - stored.stamp > 30000)) serials.set(key(sn),sn);
        }
        const list = Array.from(serials.values());
        if (!list.length) return;
        try {
            for (let offset = 0; offset < list.length; offset += 100) {
                const batch = list.slice(offset,offset+100);
                const data = await request({action:'status',serial_numbers:batch});
                if (!allowed || version !== readVersion) return;
                remember(validateRows(data && data.rows,batch));paintVisible();
            }
            message('xhm-read-status','');
        } catch (error) {
            if (allowed && version === readVersion) message('xhm-read-status','搬迁标识读取失败：' + error.message + ' 当前已有标识保留，未核对设备不会标为未搬迁。',true);
        }
    }
    function refresh(force = false) {
        if (!allowed) return;
        if (force) for (const stored of cache.values()) stored.stamp = 0;
        const version = ++readVersion;
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => readVisible(version),50);
    }
    function updateButtons() {
        const start = $('xhm-backfill'), stop = $('xhm-stop');
        if (start) {start.disabled = running || !allowed; start.textContent = completed ? '重新核对全部设备' : cursor !== '0' || processed ? '继续补标历史搬迁' : '补标历史搬迁';}
        if (stop) {stop.hidden = !running; stop.disabled = stopRequested; stop.textContent = stopRequested ? '本批完成后暂停…' : '暂停补标';}
        if ($('xhm-refresh')) $('xhm-refresh').disabled = running || !allowed;
    }
    function cursorAfter(next, previous) {
        const a = next.replace(/^0+/, '') || '0', b = previous.replace(/^0+/, '') || '0';
        return a.length !== b.length ? a.length > b.length : a > b;
    }
    function progressText() {
        return '已核对 ' + processed + ' 台：已搬迁 ' + totals.migrated + '，未搬迁 ' + totals.not_migrated + '，需核对 ' + totals.conflict + '。';
    }
    async function backfill() {
        if (!allowed || running) return;
        if (completed) {cursor = '0';processed = 0;totals = {migrated:0,not_migrated:0,conflict:0};completed = false;}
        running = true;stopRequested = false;updateButtons();
        message('xhm-progress',progressText() + ' 正在核对下一批，请稍候…');
        try {
            do {
                const requestedCursor = cursor;
                const data = await request({action:'backfill',cursor:requestedCursor});
                if (!data || typeof data.done !== 'boolean' || typeof data.next_cursor !== 'string' || !/^\d{1,20}$/.test(data.next_cursor)
                    || !Number.isInteger(data.processed) || data.processed < 0 || data.processed > 100 || !data.counts) throw new Error('补标进度响应异常，请重试本批次。');
                if (!data.done && (!cursorAfter(data.next_cursor,requestedCursor) || data.processed === 0)) throw new Error('补标进度未前进，请核对接口后重试本批次。');
                for (const name of Object.keys(totals)) if (!Number.isInteger(data.counts[name]) || data.counts[name] < 0) throw new Error('补标统计响应异常，请重试本批次。');
                if (Object.keys(totals).reduce((sum,name) => sum + data.counts[name],0) !== data.processed) throw new Error('补标统计数量不一致，请重试本批次。');
                const rows = validateRows(data.rows);
                if (rows.length !== data.processed) throw new Error('补标明细数量不一致，请重试本批次。');
                // Only move the cursor after a complete successful response. Repeats are safe server-side.
                cursor = data.next_cursor;processed += data.processed;
                for (const name of Object.keys(totals)) totals[name] += data.counts[name];
                ++readVersion;clearTimeout(refreshTimer);remember(rows);paintVisible();
                message('xhm-read-status','');
                completed = data.done;
                message('xhm-progress',progressText() + (completed ? ' 历史搬迁补标完成。' : stopRequested ? ' 已暂停，点击“继续补标历史搬迁”可继续。' : ' 正在核对下一批…'));
            } while (!completed && !stopRequested && allowed);
        } catch (error) {
            message('xhm-progress',progressText() + ' 本批次未确认完成：' + error.message + ' 点击补标按钮可从当前批次重试；此前完成的批次已保存。',true);
        } finally {running = false;updateButtons();}
    }
    function setAllowed(value) {
        allowed = value === true;
        if ($('xhm-tools')) $('xhm-tools').hidden = !allowed;
        if (!allowed) {++readVersion;clearTimeout(refreshTimer);stopRequested = true;}
        updateButtons();paintVisible();if (allowed) refresh();
    }
    $('xhm-backfill')?.addEventListener('click',backfill);
    $('xhm-stop')?.addEventListener('click',() => {stopRequested = true;updateButtons();message('xhm-progress',progressText() + ' 正在完成当前批次，完成后暂停。');});
    $('xhm-refresh')?.addEventListener('click',() => refresh(true));
    window.XingheDeviceMigration = {refresh,setAllowed};
    setAllowed(window.xingheMigrationAllowed === true);
})();
