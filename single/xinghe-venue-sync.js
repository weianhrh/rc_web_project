(function () {
    const pending = new Set();
    window.syncVenueToXinghe = async function (venueId, button) {
        if (pending.has(venueId)) return;
        if (!window.confirm('同步该场地资料、绑定设备及全部设备控制配置到星河？已同步的设备配置将被更新。')) return;
        pending.add(venueId);
        const original = button.textContent;
        button.disabled = true;
        button.textContent = '正在同步…';
        try {
            const response = await fetch('../api/venue/syncVenueToXinghe.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ venue_id: Number(venueId) })
            });
            const result = await response.json();
            if (!response.ok || result.code !== 0) throw new Error(result.msg || '同步失败');
            const data = result.data;
            window.alert('同步成功\n星河场地ID：' + data.xinghe_venue_id +
                '\n新增设备：' + data.created_devices + '\n更新设备：' + data.updated_devices +
                '\n设备配置：' + data.configurations + '\n图传配置：' + data.camera_configurations +
                '\n计费规则：' + (data.billing_rules || 0));
        } catch (error) {
            window.alert('同步未完成：' + error.message + '\n网络超时可重试，已提交的数据会更新，不重复创建场地。');
        } finally {
            pending.delete(venueId);
            button.disabled = false;
            button.textContent = original;
        }
    };
}());
