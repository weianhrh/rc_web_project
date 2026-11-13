(() => {
  'use strict';
  const API = window.ReviewAPI, platforms = ['rc','xh'];
  const states = Object.fromEntries(platforms.map(p => [p,{devices:[],status:'加载中…',error:false}]));
  const cards = new Map(), images = new Map(), counts = new Map();
  const container = document.getElementById('device-list'), modal = document.getElementById('operation-modal'), preview = document.getElementById('preview');
  let filter = 'all', loading = false, capturingAll = false;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = String(text ?? ''); return n; };
  const key = (p,d) => JSON.stringify([p,String(d.device_id || ''),String(d.image_device_serial || '')]);
  function badge(p) { const n = el('span'); n.innerHTML = API.badge(p); return n; }
  function safeURL(url,p) { try { const u = new URL(String(url || ''),p === 'xh' ? 'https://open.sxzjjx.cn/' : 'https://open.rcwulian.cn/'); return /^https?:$/.test(u.protocol) ? u.href : ''; } catch (_) { return ''; } }
  async function request(p,path,options) { const response = await API.request(p,path,options); const data = await response.json(); if (!response.ok || ![0,200].includes(Number(data.code))) throw new Error(data.msg || data.message || '请求失败'); return data; }
  function button(label,cls,handler) { const b = el('button',cls,label); b.type = 'button'; b.addEventListener('click',handler); return b; }
  function stamp() { return new Date().toLocaleString('zh-CN',{hour12:false}); }
  function openPreview(p,url) { const src = safeURL(url,p); if (!src) return; preview.querySelector('img').src = API.media(p,src); preview.hidden = false; }
  preview.addEventListener('click',() => { preview.hidden = true; preview.querySelector('img').src = ''; });
  function visibleDevices() { return platforms.flatMap(p => states[p].devices.map(d => ({p,d}))).filter(x => filter === 'all' || x.p === filter); }
  function renderStatus() { const s = document.getElementById('source-status'); s.textContent = platforms.map(p => API.label(p) + '：' + states[p].status).join('\n'); s.classList.toggle('status-error',platforms.some(p => states[p].error)); }
  function render() {
    renderStatus(); cards.clear(); container.replaceChildren();
    const rows = visibleDevices();
    rows.forEach(({p,d}) => {
      const k = key(p,d), card = el('article','device-card'); card.dataset.platform=p; card.dataset.deviceKey=k;
      const head = el('div','card-top'); head.append(badge(p),el('span','muted','图传：' + (d.image_device_serial || '-'))); card.append(head);
      const wrap=el('div','device-image-wrap'), img=el('img'); img.alt='请点击抓图'; img.hidden=true;
      const empty=el('div','empty-note','点击“抓图”查看设备画面'), time=el('time'); time.hidden=true;
      const previous=images.get(k); if(previous) {img.src=API.media(p,previous.url);img.hidden=false;empty.hidden=true;time.textContent=previous.time;time.hidden=false;}
      img.addEventListener('click',() => {const value=images.get(k);if(value)openPreview(p,value.url);}); wrap.append(empty,img,time);
      const count=el('p','muted',counts.get(p+':'+d.bind_site) ?? '今日违规：加载中…');
      const errors=el('p','capture-error'); errors.setAttribute('role','status');
      const actions=el('div','action-row'); const capture=button('抓图','primary',()=>captureDevice(p,d)); const ban=button('封禁','danger',()=>openBan(p,d)); actions.append(capture,ban);
      card.append(wrap,el('h2','',d.device_name || '未命名设备'),el('p','','场地：' + (d.venue_name || '-') + '（' + (d.bind_site || '-') + '）'),el('p','muted','设备：' + (d.device_id || '-')),count,errors,actions); container.append(card);cards.set(k,{card,img,time,empty,capture,ban,errors,count});
    });
    if (!rows.length) container.append(el('p','empty-note',loading ? '正在加载设备…' : '暂无驾驶中设备'));
  }
  function todayCount(records) {
    // 后台以中国时间每日 06:00 为一个统计日。
    const now=Date.now(), china=new Date(now+8*3600000); const start=new Date(Date.UTC(china.getUTCFullYear(),china.getUTCMonth(),china.getUTCDate(),6)-8*3600000);if(now<start.getTime())start.setUTCDate(start.getUTCDate()-1);
    const end=start.getTime()+86400000;
    return records.filter(r => { const raw=String(r.created_at || ''); const timestamp=Date.parse(raw.includes('T') ? raw : raw.replace(' ','T') + '+08:00');return Number.isFinite(timestamp)&&timestamp>=start.getTime()&&timestamp<end;}).length;
  }
  async function loadCounts(p) {
    const venues=[...new Set(states[p].devices.map(d=>String(d.bind_site || '')).filter(Boolean))];
    let cursor=0; const worker=async()=>{while(cursor<venues.length){const venue=venues[cursor++];let text;try {const data=await request(p,'/api/operat/ban_record.php?venue_id='+encodeURIComponent(venue));text='今日违规：'+todayCount(Array.isArray(data.data && data.data.device_bans)?data.data.device_bans:[]);}catch(_){text='今日违规：读取失败';}counts.set(p+':'+venue,text);states[p].devices.filter(d=>String(d.bind_site)===venue).forEach(d=>{const card=cards.get(key(p,d));if(card)card.count.textContent=text;});}};
    await Promise.allSettled(Array.from({length:Math.min(3,venues.length)},worker));
  }
  async function loadPlatform(p) {
    try { const data=await request(p,'/api/gl/get_sb.php'); states[p].devices=Array.isArray(data.data)?data.data:[];states[p].status=states[p].devices.length+' 台设备 · '+stamp();states[p].error=false; }
    catch(error){states[p].devices=[];states[p].status=error.message;states[p].error=true;}
  }
  async function reload() {
    if(loading||capturingAll)return;loading=true;document.getElementById('reload').disabled=true;document.getElementById('capture-all').disabled=true;
    await Promise.allSettled(platforms.map(loadPlatform));loading=false;render();document.getElementById('reload').disabled=false;document.getElementById('capture-all').disabled=false;
    await Promise.allSettled(platforms.map(loadCounts));
  }
  function uploadedURL(result) {
    if(typeof result==='string')return result;
    return result && (result.url || result.image_url || (typeof result.data==='string' ? result.data : result.data && (result.data.url || result.data.image_url || result.data.file_url)) || result.file_url) || '';
  }
  async function captureDevice(p,d) {
    const k=key(p,d), refs=cards.get(k);if(!refs||refs.capture.disabled)return;
    const serial=String(d.image_device_serial||'');if(!serial){refs.errors.textContent='缺少图传序列号';return;}
    refs.capture.disabled=true;refs.capture.textContent='抓图中…';refs.errors.textContent='';
    try {
      let imageURL;
      if(/^ch/i.test(serial)) {
        // CH 原有抓图入口，经所属平台中转后立即持久化当前截图。
        const capture='https://file.minirtc.com/Dev/'+encodeURIComponent(serial)+'/OnCapture?authid=1&ccc=bGQCVsd4hBHf8YjAM%2BjkQ%2FR8zBA%3D&ttt=sss&_ts='+Date.now();
        const proxy=await request(p,'/api/operat/image_proxy.php?url='+encodeURIComponent(capture));
        if(typeof proxy.data!=='string'||!/^data:image\//i.test(proxy.data))throw new Error('抓图接口未返回图片');
        const blob=await(await fetch(proxy.data)).blob();
        const result=await API.upload(p,new File([blob],Date.now()+'_'+serial+'.jpg',{type:blob.type||'image/jpeg'}));
        imageURL=uploadedURL(result);if(!imageURL)throw new Error(result && (result.msg||result.message)||'截图保存失败');
      } else { const result=await request(p,'/api/pop/capture.php?sn='+encodeURIComponent(serial));imageURL=result.image_url;if(!imageURL)throw new Error('抓图接口未返回图片'); }
      imageURL=safeURL(imageURL,p);if(!imageURL)throw new Error('图片地址无效');
      images.set(k,{url:imageURL,time:stamp()});refs.img.src=API.media(p,imageURL);refs.img.hidden=false;refs.empty.hidden=true;refs.time.textContent=stamp();refs.time.hidden=false;
    }catch(error){refs.errors.textContent=API.label(p)+'：'+error.message;}
    finally{refs.capture.disabled=false;refs.capture.textContent='抓图';}
  }
  async function captureAll() {
    if(loading||capturingAll)return;
    const rows=visibleDevices();capturingAll=true;const control=document.getElementById('capture-all');control.disabled=true;document.getElementById('reload').disabled=true;
    let cursor=0,done=0;const worker=async()=>{while(cursor<rows.length){const {p,d}=rows[cursor++];await captureDevice(p,d);done++;control.textContent='抓图 '+done+'/'+rows.length;}};
    await Promise.allSettled(Array.from({length:Math.min(4,rows.length)},worker));
    capturingAll=false;control.disabled=false;control.textContent='一键抓图';document.getElementById('reload').disabled=false;
  }
  function field(box,label,tag='input',options=[]) {const wrap=el('label','',label),input=el(tag);options.forEach(([value,text])=>{const option=el('option','',text);option.value=value;input.append(option);});wrap.append(input);box.append(wrap);return input;}
  function openBan(p,d) {
    const captured=images.get(key(p,d));if(!captured)return alert(API.label(p)+'：请先抓图，确认当前画面后再封禁');
    modal.replaceChildren();const box=el('form','operations-dialog');box.append(el('h2','','人工巡查 · 封禁处理'),badge(p),el('p','dialog-tip',(d.venue_name||'-')+' / '+(d.device_name||'-')));
    const type=field(box,'封禁类型','select',[['device','封禁设备'],['venue','封禁场地']]);
    const duration=field(box,'封禁时长','select',[['600','10分钟'],['1200','20分钟'],['1800','半小时'],['3600','1小时'],['21600','6小时'],['43200','半天'],['86400','1天'],['259200','3天'],['604800','1周']]);
    const venue=field(box,'场地 ID');venue.value=d.bind_site||'';venue.readOnly=true;
    const serial=field(box,'图传序列号');serial.value=d.image_device_serial||'';serial.readOnly=true;
    const reason=field(box,'封禁原因','textarea');reason.required=true;
    const error=el('div','dialog-error');error.setAttribute('role','alert');const actions=el('div','dialog-actions'),cancel=button('取消','',()=>{modal.hidden=true;}),submit=el('button','primary','确认封禁');submit.type='submit';actions.append(cancel,submit);box.append(error,actions);modal.append(box);modal.hidden=false;
    box.addEventListener('submit',async event=>{event.preventDefault();if(!reason.value.trim())return;submit.disabled=cancel.disabled=true;error.textContent='';
      try {await request(p,'/api/devMgr/devMgtV2.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ban:true,id:1,serial_number:String(d.device_id||''),ban_duration:duration.value,ban_reason:reason.value.trim(),venue_id:venue.value,image_device_serial:serial.value,image_url:captured.url,banType:type.value})});
        modal.hidden=true;alert(API.label(p)+'：封禁成功');states[p].devices=states[p].devices.filter(device=>type.value==='venue'?String(device.bind_site)!==String(d.bind_site):key(p,device)!==key(p,d));states[p].status=states[p].devices.length+' 台设备 · '+stamp();counts.delete(p+':'+d.bind_site);render();await loadCounts(p);
      }catch(e){error.textContent=e.message;}finally{submit.disabled=cancel.disabled=false;}});
  }
  document.querySelectorAll('[data-platform-filter]').forEach(b=>b.addEventListener('click',()=>{if(capturingAll)return;filter=b.dataset.platformFilter;document.querySelectorAll('[data-platform-filter]').forEach(x=>x.classList.toggle('active',x===b));render();}));
  document.getElementById('reload').addEventListener('click',reload);document.getElementById('capture-all').addEventListener('click',captureAll);
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){preview.hidden=true;if(!modal.querySelector('button:disabled'))modal.hidden=true;}});
  reload();
})();
