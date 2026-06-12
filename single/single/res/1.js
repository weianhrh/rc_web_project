  let ossConfig = null; 
                
                // 页面加载时获取场地列表和OSS配置 
        window.onload = async function() {
            try {
                // 获取 OSS 配置
                const configResponse = await fetch('https://open.rcwulian.cn/api/operat/upload.php?get_config=1'); 
                const configData = await configResponse.json(); 
                if (configData.code === 0) { 
                    ossConfig = configData.data; 
                }
        
                // 加载待审核图片
                loadPendingImages();
        
            } catch (e) {
                console.error('初始化失败:', e);
            }
        }

function loadPendingImages() {
    fetch('https://open.rcwulian.cn/api/venue/getPendingImages.php')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('image-list');
            const loading = document.getElementById('loading');
            loading.style.display = 'none';

            if (data.code === 0 && data.data.length > 0) {
                 data.data.sort((a, b) => new Date(b.upload_time) - new Date(a.upload_time));
                data.data.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'venue-card';
                    const imageUrl = "https://open.rcwulian.cn/api/venue/"+item.image_url;
                    card.innerHTML = `
                    <img src="https://open.rcwulian.cn/api/venue/${item.image_url}"class="venue-img" alt="待审核图片"  onclick="showModal('${imageUrl}')">
                        <div class="venue-info">
                            <span class="venue-name">${item.venue_name}</span>
                            <span>场地ID: ${item.id}</span>
                            <span>状态: ${item.image_status}</span>
                            <span>上传时间: ${item.upload_time}</span>
                        </div>
                        <div class="venue-actions">
                            <button class="edit-btn" onclick="approveImage(${item.id})">审核通过</button>
                            <button class="edit-btn" style="background-color:#f44336" onclick="rejectImage(${item.id})">拒绝</button>
                        </div>
                    `;

                    container.appendChild(card);
                });
                 // ✅ 主动通知父页面
                setTimeout(notifyParentCount, 100);
            } else {
                container.innerHTML = '<div class="loading">暂无待审核图片</div>';
                notifyParentCount(); // 如果没图也要通知 count=0
            }
        })
        .catch(error => {
            document.getElementById('loading').innerText = '加载失败';
            console.error('加载失败：', error);
        });
}

async function approveImage(venueId) {
    if (!confirm("确认通过该图片审核，并上传设为场地头像？")) {
            return;
    }
    const card = document.querySelector(`.venue-card button[onclick="approveImage(${venueId})"]`).closest('.venue-card');
    const imgUrl = card.querySelector('img').getAttribute('src');

    if (!ossConfig) {
        alert("OSS 配置未加载");
        return;
    }

    try {
        const response = await fetch(imgUrl, { mode: 'cors' });
        if (!response.ok) {
            alert(`图片加载失败：HTTP ${response.status}`);
            return;
        }

        const blob = await response.blob();

        if (blob.size < 100) {
            alert("图片太小或无效，上传中止");
            return;
        }

        const filename = `app/img/${Date.now()}_venue_${venueId}.jpg`;
        const client = new OSS(ossConfig);
        const result = await client.put(filename, blob);
        const uploadedUrl = result.url;

        // 更新数据库
        const res = await fetch('https://open.rcwulian.cn/api/operat/upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                venue_id: venueId,
                image_url: uploadedUrl
            })
        });
        fetch('https://open.rcwulian.cn/api/venue/reviewImage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                venue_id: venueId,
                oss_uploaded_url: uploadedUrl
            })
        });
        
        const data = await res.json();
        if (data.code === 0) {
            alert("上传成功，头像已更新！");
        const filename = imgUrl.split('/pending_images/')[1]; // 得到 venue_xxx.jpg
        const deleteRes = await fetch('https://open.rcwulian.cn/api/venue/deletePendingImage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                venue_id: venueId,
                filename: `pending_images/${filename}`
            })
        });
        const deleteText = await deleteRes.text();
        console.log("🧹 删除图片响应文本：", deleteText);


            location.reload();
        } else {
            alert("数据库写入失败：" + data.msg);
        }

    } catch (e) {
        console.error("上传异常：", e);
        alert("图片审核处理失败，请检查网络和 OSS 权限");
    }
}


async function rejectImage(venueId) {
  
    

    // 缓存驳回理由到 localStorage
    // localStorage.setItem(`rejected_reason_${venueId}`, reason);

    // 找到图片 URL 并提取 filename
    const reason = prompt("请输入拒绝原因：");
    if (!reason) return;
    const rejectRes = await fetch('https://open.rcwulian.cn/api/venue/rejectImage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            venue_id: venueId,
            reason: reason
        })
    });

    const card = document.querySelector(`.venue-card button[onclick="rejectImage(${venueId})"]`).closest('.venue-card');
    const imgUrl = card.querySelector('img').getAttribute('src');
    const filename = imgUrl.split('/pending_images/')[1];

    // 删除图片 + 还原 image_status
    const deleteRes = await fetch('https://open.rcwulian.cn/api/venue/deletePendingImage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            venue_id: venueId,
            filename: `pending_images/${filename}`
        })
    });

    const deleteData = await deleteRes.json();
    console.log("🧹 删除图片响应：", deleteData);

    if (deleteData.code === 0) {
        alert('图片已驳回并清理');
        location.reload();
    } else {
        alert('删除失败：' + deleteData.msg);
    }
}

 function showModal(imageUrl) {
        let modal = document.getElementById('img-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'img-modal';
            modal.className = 'modal';
            modal.innerHTML = `<img class="modal-content" id="modal-img">`;
            modal.onclick = () => (modal.style.display = 'none');
            document.body.appendChild(modal);
        }
        const img = modal.querySelector('#modal-img');
        img.src = imageUrl;
        modal.style.display = 'block';
    }
 // 方法2：页面加载完后主动告诉父页面
  function notifyParentCount() {
    const count = document.querySelectorAll('.venue-card').length;
    window.parent.postMessage({ type: 'image-card-count', count }, '*');
  }
     async function loadList() {
      const res = await fetch("https://open.rcwulian.cn/api/venue/get_audit_list.php");
      const result = await res.json();
      const list = result.data?.name_list || [];

      const tbody = document.getElementById("audit-list");
      tbody.innerHTML = "";

      list.forEach(item => {
        const time = new Date(item.timestamp * 1000).toLocaleString();
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td data-label="场地ID">${item.venue_id}</td>
          <td data-label="提交名称">${item.venue_name}</td>
          <td data-label="提交时间" data-hide="true">${time}</td>
          <td data-label="操作">
            <button onclick="approve(${item.venue_id})">通过</button>
            <button class="danger" onclick="toggleReject(${item.venue_id})">拒绝</button>
            <div class="textarea-box" id="reject-box-${item.venue_id}" style="display:none;">
              <textarea id="reason-${item.venue_id}" rows="2" placeholder="请输入拒绝理由"></textarea>
              <button class="danger" onclick="reject(${item.venue_id})" style="margin-top:8px;">确认拒绝</button>
            </div>
          </td>
        `;
        tbody.appendChild(tr);
      });

      notifyTextCount();
    }

    async function loadDeviceList() {
      const res = await fetch("https://open.rcwulian.cn/api/venue/getPendingVehicleNameAudits.php");
      const result = await res.json();
      const list = result.data || [];

      const tbody = document.getElementById("device-audit-list");
      tbody.innerHTML = "";

      list.forEach(item => {
        const time = new Date(item.timestamp * 1000).toLocaleString();
        const fieldText = item.field === 'name' ? '设备名称' : '分享名称';

        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td data-label="字段">${fieldText}</td>
          <td data-label="名称">${item.new}</td>
          <td data-label="提交时间" data-hide="true">${time}</td>
          <td data-label="操作">
            <button onclick="approveDevice('${item.device_id}', '${item.field}')">通过</button>
            <button class="danger" onclick="toggleRejectDevice('${item.device_id}', '${item.field}')">拒绝</button>
            <div class="textarea-box" id="reject-box-${item.device_id}-${item.field}" style="display:none;">
              <textarea id="reason-${item.device_id}-${item.field}" rows="2" placeholder="请输入拒绝理由"></textarea>
              <button class="danger" onclick="rejectDevice('${item.device_id}', '${item.field}')" style="margin-top:8px;">确认拒绝</button>
            </div>
          </td>
        `;
        tbody.appendChild(tr);
      });

      notifyTextCount();
    }

    async function loadDescList() {
      const res = await fetch("https://open.rcwulian.cn/api/venue/get_audit_list.php");
      const result = await res.json();
      const list = result.data?.desc_list || [];

      const tbody = document.getElementById("desc-audit-list");
      tbody.innerHTML = "";

      list.forEach(item => {
        const time = new Date(item.timestamp * 1000).toLocaleString();
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td data-label="场地ID">${item.venue_id}</td>
          <td data-label="提交描述">${item.venue_description}</td>
          <td data-label="提交时间" data-hide="true">${time}</td>
          <td data-label="操作">
            <button onclick="approveDesc(${item.venue_id})">通过</button>
            <button class="danger" onclick="toggleRejectDesc(${item.venue_id})">拒绝</button>
            <div class="textarea-box" id="reject-desc-box-${item.venue_id}" style="display:none;">
              <textarea id="reason-desc-${item.venue_id}" rows="2" placeholder="请输入拒绝理由"></textarea>
              <button class="danger" onclick="rejectDesc(${item.venue_id})" style="margin-top:8px;">确认拒绝</button>
            </div>
          </td>
        `;
        tbody.appendChild(tr);
      });

      notifyTextCount();
    }

    async function approve(id) {
      await fetch("https://open.rcwulian.cn/api/venue/review_venue_name.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ venue_id: id, action: "approve" })
      });
      alert("已通过");
      loadList();
    }

    async function reject(id) {
      const reason = document.getElementById(`reason-${id}`).value.trim();
      if (!reason) return alert("请输入拒绝理由");

      await fetch("https://open.rcwulian.cn/api/venue/review_venue_name.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ venue_id: id, action: "reject", reason })
      });
      alert("已拒绝");
      loadList();
    }

    function toggleReject(id) {
      const box = document.getElementById(`reject-box-${id}`);
      box.style.display = box.style.display === "none" ? "block" : "none";
    }

    async function approveDevice(id, field) {
      await fetch("https://open.rcwulian.cn/api/venue/reviewVehicleNameAudit.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ device_id: id, field, action: "approve" })
      });
      alert("设备审核通过");
      loadDeviceList();
    }

    async function rejectDevice(id, field) {
      const reason = document.getElementById(`reason-${id}-${field}`).value.trim();
      if (!reason) return alert("请输入拒绝理由");

      await fetch("https://open.rcwulian.cn/api/venue/reviewVehicleNameAudit.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ device_id: id, field, action: "reject", reason })
      });
      alert("设备已拒绝");
      loadDeviceList();
    }

    function toggleRejectDevice(id, field) {
      const box = document.getElementById(`reject-box-${id}-${field}`);
      box.style.display = box.style.display === "none" ? "block" : "none";
    }

    async function approveDesc(id) {
      await fetch("https://open.rcwulian.cn/api/venue/review_venue_description.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ venue_id: id, action: "approve" })
      });
      alert("描述审核通过");
      loadDescList();
    }

    async function rejectDesc(id) {
      const reason = document.getElementById(`reason-desc-${id}`).value.trim();
      if (!reason) return alert("请输入拒绝理由");

      await fetch("https://open.rcwulian.cn/api/venue/review_venue_description.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ venue_id: id, action: "reject", reason })
      });
      alert("描述已拒绝");
      loadDescList();
    }

    function toggleRejectDesc(id) {
      const box = document.getElementById(`reject-desc-box-${id}`);
      box.style.display = box.style.display === "none" ? "block" : "none";
    }

    function notifyTextCount() {
      const count = 
        document.querySelectorAll('#audit-list tr').length +
        document.querySelectorAll('#desc-audit-list tr').length +
        document.querySelectorAll('#device-audit-list tr').length;
      window.parent.postMessage({ type: 'text-card-count', count }, '*');
    }

    loadList();
    loadDeviceList();
    loadDescList(); 