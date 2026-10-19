const $=id=>document.getElementById(id);
let report=null,visibleRows=[],requestId=0;
const money=n=>'¥'+Number(n||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2});
const percent=n=>(Number(n||0)*100).toFixed(1)+'%';
function el(tag,text,cls){const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;}
function gradeNode(row){return el('span',row.grade,'grade grade-'+(row.grade.match(/^[SABCD]$/)?row.grade:'other'));}
function growthText(row){return row.growth_rate===null?(row.revenue>0?'无前期基准':'—'):(row.growth_rate>=0?'+':'')+percent(row.growth_rate);}
function cell(tr,label){const td=document.createElement('td');td.append(el('span',label,'mobile-label'));tr.appendChild(td);return td;}
function render(){
 const q=$('search').value.trim().toLowerCase(),grade=$('gradeFilter').value,key=$('sort').value;
 visibleRows=(report?.rows||[]).filter(r=>(!q||(r.venue_name+' '+r.venue_id).toLowerCase().includes(q))&&(!grade||r.grade===grade)&&(!$('onlyAlerts').checked||r.alerts.length));
 visibleRows.sort((a,b)=>{if(a.score===null||b.score===null)return a.score===null?(b.score===null?a.venue_id-b.venue_id:1):-1;return Number(b[key])-Number(a[key])||a.rank-b.rank;});
 const body=$('rows');body.replaceChildren();
 for(const r of visibleRows){
  const tr=el('tr');cell(tr,'').append(el('span',r.rank??'—'));
  const venue=cell(tr,'场地'),identity=el('div',undefined,'venue'),avatar=el('div',undefined,'venue-avatar');const icon=el('i');icon.className='fas fa-store';icon.setAttribute('aria-hidden','true');avatar.append(icon);
  if(/^https?:\/\//i.test(r.image_url||'')){const image=el('img');image.src=r.image_url;image.alt='';image.loading='lazy';image.onerror=()=>image.remove();avatar.append(image);}
  const names=el('div');names.append(el('div',r.venue_name,'venue-name'),el('span',`ID ${r.venue_id} · 经营评级 ${r.business_level||'未设置'}`,'sub'));identity.append(avatar,names);venue.append(identity);
  const score=cell(tr,'综合评分 / 分析等级');score.append(el('strong',r.score===null?'—':r.score.toFixed(1),'score'),gradeNode(r));
  const refs=cell(tr,'有效引流 / 注册');refs.append(el('strong',r.effective_referrals+'人'),el('span','注册 '+r.registrations+'人','sub'));
  cell(tr,'场地付费客户').append(el('span',r.special_business?'—':r.paid_users+'人'));cell(tr,'真实订单').append(el('span',r.special_business?'—':r.orders+'单'));
  const amount=cell(tr,'实付消费 / 环比');amount.append(el('span',r.special_business?'—':money(r.revenue),'money'),el('span',r.special_business?'独立业务单列':growthText(r),'sub '+(r.growth_rate<0?'growth-down':r.growth_rate>0?'growth-up':'')));
  const quality=cell(tr,'引流转化 / 跨日复购');quality.append(el('span','转化 '+percent(r.conversion_rate)),el('span',r.special_business?'跨日复购 —':'跨日复购 '+percent(r.repeat_rate),'sub'));
  const alerts=cell(tr,'运营预警');for(const alert of r.alerts.slice(0,2))alerts.append(el('span',alert,'alert-tag'));if(r.alerts.length>2)alerts.append(el('span','另'+(r.alerts.length-2)+'项','sub'));if(!r.alerts.length)alerts.append(el('span','暂无预警','quiet'));
  const action=cell(tr,'');const button=el('button');button.type='button';button.className='icon';button.title='查看评分依据与建议';button.setAttribute('aria-label','分析'+r.venue_name);const chart=el('i');chart.className='fas fa-chart-line';chart.setAttribute('aria-hidden','true');button.append(chart);button.onclick=()=>showDetail(r);action.append(button);body.append(tr);
 }
 if(!visibleRows.length){const tr=el('tr'),td=el('td','暂无符合条件的场地','empty');td.colSpan=10;tr.append(td);body.append(tr);}
 $('listCount').textContent=`显示 ${visibleRows.length} / ${report?.rows.length||0} 个场地`;
 $('exportButton').disabled=!visibleRows.length;
}
function renderReport(){
 const scored=report.rows.filter(r=>r.score!==null);
 $('venueCount').textContent=report.rows.length;$('highCount').textContent=scored.filter(r=>['S','A'].includes(r.grade)).length;
 $('alertCount').textContent=report.rows.filter(r=>r.alerts.length).length;$('referralCount').textContent=scored.reduce((s,r)=>s+r.effective_referrals,0);
 $('rangeText').textContent=`${report.range.start_date} 至 ${report.range.end_date} · 前期对比 ${report.range.previous_start_date} 至 ${report.range.previous_end_date}`;
 $('generatedAt').textContent='生成于 '+report.generated_at+(report.cached?' · 缓存快照':'');$('endDate').value=report.range.end_date;
 const weights=el('div',undefined,'weights');for(const d of report.model.dimensions)weights.append(el('div',`${d.name}：${d.weight}分`));$('ruleWeights').replaceChildren(weights);
 const anchors=report.model.anchors;$('calibrationInfo').replaceChildren(el('div',`校准样本：${report.model.peer_count}个同类营业场地；付费引流P90：${anchors.effective_referrals.toFixed(1)}人，付费客户P90：${anchors.paid_users.toFixed(1)}人，订单P90：${anchors.orders.toFixed(1)}单，实付消费P90：${money(anchors.revenue)}。`,'calibration'));
 render();
}
async function load(force=false){
 const id=++requestId;$('refreshButton').disabled=true;$('notice').textContent='正在校准场地评分...';
 const params=new URLSearchParams({days:$('days').value});if($('endDate').value)params.set('end_date',$('endDate').value);if(force)params.set('refresh','1');
 try{const response=await fetch('/api/index/VenueAIScore.php?'+params,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('评分接口暂不可用');const result=await response.json();if(id!==requestId)return;if(result.code!==0)throw Error(result.msg||'评分读取失败');report=result.data;renderReport();$('notice').textContent='';}
 catch(error){if(id!==requestId)return;report=null;render();['venueCount','highCount','alertCount','referralCount'].forEach(key=>$(key).textContent='—');$('rangeText').textContent='评分数据暂不可用';$('generatedAt').textContent='';$('notice').textContent=error.message;}
 finally{if(id===requestId)$('refreshButton').disabled=false;}
}
function showDetail(r){
 $('detailTitle').textContent=r.venue_name+' · 评分分析';const content=$('detailContent');content.replaceChildren();
 const meta=el('div',undefined,'detail-meta');meta.append(el('span',r.score===null?'独立业务，暂不参与遥控驾驶评分':`综合评分 ${r.score.toFixed(1)} / 100 · `));meta.append(gradeNode(r));content.append(meta);
 for(const d of r.dimensions){const line=el('div',undefined,'dimension'),name=el('div',d.name);let raw=d.key==='revenue'?money(d.value):['conversion_rate','repeat_rate'].includes(d.key)?percent(d.value):Number(d.value).toLocaleString('zh-CN');name.append(el('small','实际值 '+raw));const bar=el('div',undefined,'bar'),fill=el('span');fill.style.width=d.score+'%';bar.append(fill);line.append(name,bar,el('div',`${d.points.toFixed(1)} / ${d.weight}`,'points'));content.append(line);}
 const metrics=el('div',undefined,'detail-metrics');for(const [name,value] of [['引流用户实付',money(r.referral_revenue)],['其中外场消费',money(r.external_referral_revenue)],['场地客单价',r.special_business?'—':money(r.average_order_value)],['跨日复购客户',r.special_business?'—':r.repeat_users+'人'],['前期场地实付',r.special_business?'—':money(r.previous_revenue)],['实付消费环比',r.special_business?'—':growthText(r)]]){const item=el('div');item.append(el('span',name),el('strong',value));metrics.append(item);}content.append(metrics);
 content.append(el('h3','运营预警'));const alerts=el('ul');for(const text of r.alerts.length?r.alerts:['当前未触发运营预警'])alerts.append(el('li',text));content.append(alerts,el('h3','建议行动'));const advice=el('ul');for(const text of r.recommendations)advice.append(el('li',text));content.append(advice);
 $('deepLink').href='/res/venue_data_analysis.html?'+new URLSearchParams({venue_id:r.venue_id,start_date:report.range.start_date,end_date:report.range.end_date});$('detailDialog').showModal();
}
function csvField(v){let s=String(v??'');if(/^[=+@\-\t\r]/.test(s))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';}
$('exportButton').onclick=()=>{if(!report)return;const lines=[['综合排名','场地ID','场地','综合评分','分析等级','引流注册','有效付费引流','场地付费客户','真实订单','实付消费','引流转化率','跨日复购率','预警','建议']];for(const r of visibleRows)lines.push([r.rank,r.venue_id,r.venue_name,r.score,r.grade,r.registrations,r.effective_referrals,r.paid_users,r.orders,r.revenue.toFixed(2),percent(r.conversion_rate),percent(r.repeat_rate),r.alerts.join('；'),r.recommendations.join('；')]);const blob=new Blob(['\uFEFF'+lines.map(row=>row.map(csvField).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8;'});const url=URL.createObjectURL(blob),a=el('a');a.href=url;a.download=`场地评分_${report.range.start_date}_${report.range.end_date}.csv`;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);};
for(const id of ['search','gradeFilter','sort','onlyAlerts'])$(id).addEventListener(id==='search'?'input':'change',render);
for(const id of ['days','endDate'])$(id).addEventListener('change',()=>load());
$('refreshButton').onclick=()=>load(true);$('rulesButton').onclick=()=>$('rulesDialog').showModal();document.querySelectorAll('.close').forEach(button=>button.onclick=()=>button.closest('dialog').close());
const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());const yesterday=new Date(today+'T00:00:00+08:00');yesterday.setUTCDate(yesterday.getUTCDate()-1);$('endDate').max=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).format(yesterday);
load();
