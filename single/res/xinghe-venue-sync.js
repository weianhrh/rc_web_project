(function () {
    const pending = new Set();
    const imageUploadUrl = 'https://sxzjjx.cn/app/img/userimg/upload_profile.php';
    async function request(payload) {
        const response = await fetch('../api/venue/syncVenueToXinghe.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('接口未返回JSON，请检查部署及服务器日志'); }
        if (!response.ok || result.code !== 0) throw new Error(result.msg || '请求失败');
        return result.data;
    }
    function style() {
        if (document.getElementById('xh-sync-style')) return;
        const el = document.createElement('style'); el.id = 'xh-sync-style';
        el.textContent = `.xh-sync-dialog{box-sizing:border-box;width:min(560px,94vw);max-height:90vh;overflow:auto;padding:26px;border:0;border-radius:16px;color:#24324a;background:white;box-shadow:0 20px 70px #16295440;font:14px/1.6 sans-serif}
        .xh-sync-dialog::backdrop{background:#18243c70}.xh-sync-dialog h2{margin:0 0 6px;font-size:22px}.xh-sync-dialog p{margin:0 0 18px;color:#65748c}.xh-sync-dialog fieldset{border:0;padding:0;margin:0}
        .xh-sync-dialog .xh-mode{display:flex;gap:22px;flex-wrap:wrap;padding:12px 0}.xh-sync-dialog .xh-field{display:block;margin:12px 0}.xh-sync-dialog .xh-field>span{display:block;margin-bottom:5px}
        .xh-sync-dialog input:not([type=radio]):not([type=checkbox]),.xh-sync-dialog select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #d4dcec;border-radius:8px;font:inherit;background:white;color:#24324a}
        .xh-sync-dialog .xh-image-row{display:flex;align-items:center;gap:10px}.xh-sync-dialog .xh-image-row input{flex:1;min-width:0}.xh-sync-dialog .xh-upload{flex-shrink:0;white-space:nowrap}
        .xh-sync-dialog .xh-checks{display:flex;gap:20px;margin-top:12px}.xh-sync-dialog .xh-status{white-space:pre-wrap;margin:12px 0;color:#52627b}.xh-sync-dialog .xh-error{color:#bc293a}
        .xh-sync-dialog footer{display:flex;justify-content:flex-end;gap:12px;margin-top:22px}.xh-sync-dialog button{padding:10px 18px;border-radius:8px;border:1px solid #d4dcec;background:white;cursor:pointer;font:inherit}
        .xh-sync-dialog button[type=submit]{background:#426df4;color:white;border-color:#426df4}.xh-sync-dialog button:disabled{opacity:.55;cursor:wait}.xh-sync-dialog [hidden]{display:none!important}`;
        document.head.appendChild(el);
    }
    window.syncVenueToXinghe = async function (venueId, button) {
        if (pending.has(venueId)) return;
        pending.add(venueId); button.disabled = true; style();
        const dialog = document.createElement('dialog'); dialog.className = 'xh-sync-dialog';
        dialog.setAttribute('aria-label', '同步场地到星河');
        dialog.innerHTML = `<h2>同步场地到星河</h2><p>同步场地资料、套餐、绑定设备、控制配置和关联摄像头图传。</p>
        <form><fieldset class="xh-fields" disabled>
          <div class="xh-mode"><label><input type="radio" name="zone_mode" value="existing" checked> 选择星河已有专区</label><label><input type="radio" name="zone_mode" value="new"> 新建星河专区</label></div>
          <section class="xh-existing"><label class="xh-field"><span>目标专区</span><select name="zone_id" required aria-label="星河已有专区"></select></label></section>
          <section class="xh-new" hidden>
            <label class="xh-field"><span>新专区名称 *</span><input name="partition_name" maxlength="50" placeholder="例如：室内竞技专区"></label>
            <div class="xh-field"><span>专区图片地址（选填）</span><div class="xh-image-row"><input name="zone_image" type="url" maxlength="255" placeholder="https://…" aria-label="专区图片地址"><button type="button" class="xh-upload">上传图片</button></div><input name="zone_image_file" type="file" accept="image/*" aria-label="选择专区图片" hidden></div>
            <label class="xh-field"><span>排序</span><input name="sort_order" type="number" min="-2147483648" max="2147483647" value="0"></label>
            <div class="xh-checks"><label><input name="is_visible" type="checkbox" checked> 显示专区</label><label><input name="is_claw_machine" type="checkbox"> 娃娃机专区</label></div>
          </section></fieldset>
          <div class="xh-status" role="status" aria-live="polite">正在读取星河专区…</div>
          <footer><button type="button" class="xh-cancel">取消</button><button type="submit" disabled>确认同步</button></footer></form>`;
        document.body.appendChild(dialog);
        const form = dialog.querySelector('form'), fields = dialog.querySelector('.xh-fields');
        const select = form.elements.zone_id, submit = dialog.querySelector('[type=submit]'), cancel = dialog.querySelector('.xh-cancel'), status = dialog.querySelector('.xh-status');
        const upload = dialog.querySelector('.xh-upload'), imageFile = form.elements.zone_image_file;
        let busy = false, loaded = false;
        function message(text, error) { status.textContent = text; status.classList.toggle('xh-error', !!error); }
        function mode() {
            const isNew = form.elements.zone_mode.value === 'new';
            dialog.querySelector('.xh-existing').hidden = isNew; dialog.querySelector('.xh-new').hidden = !isNew;
            select.disabled = isNew; select.required = !isNew;
            for (const name of ['partition_name', 'zone_image', 'zone_image_file', 'sort_order', 'is_visible', 'is_claw_machine']) form.elements[name].disabled = !isNew;
            upload.disabled = !isNew;
            form.elements.partition_name.required = isNew;
        }
        form.addEventListener('change', mode);
        upload.addEventListener('click', () => { if (!busy && loaded) imageFile.click(); });
        imageFile.addEventListener('change', async () => {
            const file = imageFile.files && imageFile.files[0];
            if (!file || busy || !loaded || form.elements.zone_mode.value !== 'new') return;
            if (file.type && !file.type.startsWith('image/')) {
                message('请选择图片文件', true); imageFile.value = ''; return;
            }
            busy = true; fields.disabled = submit.disabled = cancel.disabled = true;
            upload.textContent = '上传中…';
            message('正在上传专区图片到星河，请稍候…');
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 60000);
            try {
                const data = new FormData();
                // 与星河专区管理页保持一致，兼容接口的 file / image 字段。
                data.append('file', file); data.append('image', file);
                const response = await fetch(imageUploadUrl, { method: 'POST', body: data, signal: controller.signal });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('上传接口未返回有效 JSON'); }
                const url = result && typeof result.url === 'string' ? result.url.trim().replace(/^http:\/\//i, 'https://') : '';
                if (!url) throw new Error(result && result.msg ? result.msg : '接口未返回图片 URL');
                let parsed;
                try { parsed = new URL(url); } catch (_) { throw new Error('接口返回的图片 URL 无效'); }
                if (!['https:', 'http:'].includes(parsed.protocol)) throw new Error('接口返回的图片 URL 无效');
                if (new TextEncoder().encode(url).length > 255) throw new Error('图片 URL 超过 255 字节，无法保存到专区');
                form.elements.zone_image.value = url;
                message('上传成功，已回填图片地址。确认同步后将保存到新专区。');
            } catch (error) {
                message('图片上传失败：' + (error.name === 'AbortError' ? '上传超时，请重试' : error.message), true);
            } finally {
                clearTimeout(timeout); imageFile.value = ''; upload.textContent = '上传图片';
                busy = false; fields.disabled = submit.disabled = cancel.disabled = false; mode();
            }
        });
        cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
        dialog.addEventListener('cancel', e => { if (busy) e.preventDefault(); });
        dialog.addEventListener('close', () => { dialog.remove(); pending.delete(venueId); button.disabled = false; });
        dialog.showModal();
        try {
            const data = await request({ action: 'list_zones', venue_id: Number(venueId) });
            if (!dialog.isConnected) return;
            if (!Array.isArray(data.zones)) throw new Error('请先覆盖新版星河端文件');
            for (const zone of data.zones) {
                const option = document.createElement('option'); option.value = zone.zone_id;
                option.textContent = zone.partition_name + '（ID: ' + zone.zone_id + (Number(zone.is_visible) === 0 ? '，隐藏' : '') + '）'; select.appendChild(option);
            }
            if (data.current_zone_id != null && Array.from(select.options).some(o => o.value === String(data.current_zone_id))) select.value = String(data.current_zone_id);
            if (!data.zones.length) form.elements.zone_mode.value = 'new';
            fields.disabled = false; loaded = true; submit.disabled = false; mode();
            message('同步后场地归入所选专区；套餐和设备配置随场地更新。');
        } catch (error) { if (dialog.isConnected) message('读取专区失败：' + error.message + '\n请检查两端文件及配置，关闭后重试。', true); }
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (busy || !loaded || !form.reportValidity()) return;
            const selectedMode = form.elements.zone_mode.value;
            const zone = selectedMode === 'existing' ? { mode: selectedMode, zone_id: Number(select.value) } : {
                mode: selectedMode, partition_name: form.elements.partition_name.value.trim(), zone_image: form.elements.zone_image.value.trim(),
                sort_order: Number(form.elements.sort_order.value), is_visible: form.elements.is_visible.checked ? 1 : 0, is_claw_machine: form.elements.is_claw_machine.checked ? 1 : 0
            };
            if (selectedMode === 'new' && !zone.partition_name) { message('请输入新专区名称', true); return; }
            busy = true; fields.disabled = submit.disabled = cancel.disabled = true;
            message('正在同步场地、套餐、设备及图传配置，请稍候…');
            try {
                const data = await request({ venue_id: Number(venueId), zone }); dialog.close();
                const missingCameras = Array.isArray(data.missing_camera_refs) ? data.missing_camera_refs : [];
                const skippedDevices = Array.isArray(data.skipped_devices) ? data.skipped_devices : [];
                const skippedPricing = Array.isArray(data.skipped_pricing_options) ? data.skipped_pricing_options : [];
                const skippedCameras = Array.isArray(data.skipped_camera_configurations) ? data.skipped_camera_configurations : [];
                let report = (missingCameras.length || skippedDevices.length || skippedPricing.length || skippedCameras.length ? '同步完成（有保留或跳过项）' : '同步成功') + '\n星河场地ID：' + data.xinghe_venue_id + '\n专区ID：' + data.zone_id + '\n场地套餐：' + data.pricing_options +
                    '\n新增设备：' + data.created_devices + '\n更新设备：' + data.updated_devices + '\n控制配置：' + data.configurations +
                    '\n摄像头图传：' + data.camera_configurations + '\n计费规则：' + (data.billing_rules || 0);
                if (data.restored_venue) report += '\n已按原映射恢复缺失的星河场地：' + data.xinghe_venue_id;
                if (data.pricing_summary) report += '\n套餐处理：新增 ' + data.pricing_summary.created + ' / 更新 ' + data.pricing_summary.updated + ' / 复用 ' + data.pricing_summary.reused;
                if (data.camera_summary) report += '\n图传处理：新增 ' + data.camera_summary.created + ' / 复用 ' + data.camera_summary.reused;
                if ((data.bound_unassigned_devices || []).length) report += '\n绑定原未绑定设备：' + data.bound_unassigned_devices.length + ' 台';
                if (skippedDevices.length) {
                    report += '\n\n跳过星河其他场地设备：' + skippedDevices.length + ' 台（保留原归属及配置）';
                    for (const item of skippedDevices.slice(0, 20)) report += '\n' + item.serial_number + ' → 星河场地 ' + item.existing_venue_id + (item.existing_venue_name ? '（' + item.existing_venue_name + '）' : '');
                    if (skippedDevices.length > 20) report += '\n其余跳过设备请查看接口响应。';
                }
                if (skippedPricing.length) {
                    report += '\n\n跳过其他场地套餐：' + skippedPricing.length + ' 条（保留原套餐及归属）';
                    for (const item of skippedPricing.slice(0, 20)) report += '\n' + item.title + ' → 星河套餐 ' + item.target_pricing_id + ' / 场地 ' + item.existing_venue_id;
                }
                if (skippedCameras.length) {
                    report += '\n\n跳过冲突图传：' + skippedCameras.length + ' 条（保留星河记录，待核对）';
                    for (const item of skippedCameras.slice(0, 20)) report += '\n图传设备 ' + item.device_id + ' / 房间 ' + (item.room_id || '未设置');
                    for (const item of (data.skipped_camera_fields || []).slice(0, 20)) report += '\n' + item.serial_number + ' / ' + (item.field === 'image_device_serial' ? '前摄' : '后摄') + ' → ' + (item.action === 'preserved_existing' ? '保留原图传' : '保持未配置');
                }
                if (Array.isArray(data.source_missing_settings) && data.source_missing_settings.length) {
                    const unset = data.unconfigured_devices || [];
                    report += '\n\nRC未设置控制配置：' + data.source_missing_settings.length + ' 台（设备已同步）' +
                        '\n保留星河已有配置：' + data.preserved_settings + ' 台\n仍待配置：' + unset.length + ' 台';
                    if (unset.length) report += '\n待配置设备：' + unset.slice(0, 20).join('、') + (unset.length > 20 ? ' 等' : '');
                }
                if (missingCameras.length) {
                    report += '\n\nRC图传缺项：' + missingCameras.length + ' 项\n保留星河原图传的设备：' + (data.preserved_camera_devices || []).length + ' 台';
                    for (const item of missingCameras.slice(0, 20)) {
                        report += '\n' + item.serial_number + ' / ' + (item.field === 'image_device_serial' ? '前摄' : '后摄') +
                            ' / RC标识：' + item.source_value + ' → ' + (item.action === 'preserved_existing' ? '保留星河图传，待核对' : '保持未配置，待补充');
                    }
                    if (missingCameras.length > 20) report += '\n其余缺项请查看接口响应。';
                }
                window.alert(report);
            } catch (error) { message('同步未完成：' + error.message + '\n超时可用相同选择重试，已提交的映射会复用。', true); }
            finally { busy = false; fields.disabled = submit.disabled = cancel.disabled = false; mode(); }
        });
    };
}());
