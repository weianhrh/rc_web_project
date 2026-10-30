'use strict';
const $=id=>document.getElementById(id);
let state=null,busy=false,cancelTarget=null,clockOffset=0;
const endpoint='/api/index/VoiceRoomRecommendationAdmin.php';
function message(value,type=''){ $('notice').textContent=value;$('notice').className=type; }
function now(){return Date.now()+clockOffset;}
function timestamp(value){return new Date(value.replace(' ','T')+'+08:00').getTime();}
function status(row){if(!Number(row.enabled))return '已取消';return timestamp(row.ends_at)>now()?'生效中':'已到期';}
function cell(tr,label,value){const td=document.createElement('td');td.dataset.label=label;td.textContent=value??'—';tr.append(td);return td;}
function empty(target,colspan){const tr=document.createElement('tr'),td=cell(tr,'','暂无记录');td.colSpan=colspan;td.className='empty';target.append(tr);}
function selected(){return state?.venues.find(v=>Number(v.id)===Number($('venue').value));}
function describe(){const row=selected();$('current').textContent=row?.revision>0?`当前：${status(row)} · 截止 ${row.ends_at}；重新设置将从现在起计时。`:'同优先级按生效时间、场地ID排序；封禁或不满足推荐资格的场地不展示。';}
function edit(row){$('venue').value=String(row.id);$('priority').value=row.priority||50;$('reason').value='';describe();$('settings').scrollIntoView({behavior:'smooth',block:'start'});$('reason').focus({preventScroll:true});}
function render(){
  $('rows').replaceChildren();$('logs').replaceChildren();if(!state)return;
  const q=$('search').value.trim().toLowerCase(),filter=$('filter').value;
  const all=state.venues.filter(v=>Number(v.revision)>0);
  const rows=all.filter(v=>(!q||String(v.id)===q||v.venue_name.toLowerCase().includes(q))&&(filter==='all'||status(v)===({active:'生效中',expired:'已到期',cancelled:'已取消'}[filter])));
  rows.sort((a,b)=>Number(b.priority)-Number(a.priority)||a.starts_at.localeCompare(b.starts_at)||Number(a.id)-Number(b.id));
  $('count').textContent=`生效中 ${all.filter(v=>status(v)==='生效中').length} 个`;
  for(const row of rows){const tr=document.createElement('tr');cell(tr,'场地',`${row.id} ${row.venue_name}`);const s=cell(tr,'状态',status(row));s.className=status(row)==='生效中'?'status':'muted';if(Number(row.is_banned)||Number(row.is_voice_room_banned))s.append(document.createTextNode(' · 已封禁不展示'));else if(row.venue_status!=='营业中'||Number(row.venue_status_num)!==2)s.append(document.createTextNode(' · 未营业/未开房'));cell(tr,'优先级',row.priority);cell(tr,'生效时间',row.starts_at);cell(tr,'截止时间',row.ends_at);cell(tr,'原因',row.reason);const td=cell(tr,'操作','');td.className='buttons';const editBtn=document.createElement('button');editBtn.textContent='调整';editBtn.disabled=busy;editBtn.onclick=()=>edit(row);td.append(editBtn);if(Number(row.enabled)){const btn=document.createElement('button');btn.textContent='取消推荐';btn.disabled=busy;btn.onclick=()=>{cancelTarget={...row};$('cancelName').textContent=`${row.id} ${row.venue_name}`;$('cancelReason').value='';$('cancelDialog').showModal();};td.append(btn);}$('rows').append(tr);}
  if(!rows.length)empty($('rows'),7);
  for(const log of state.logs){let after={};try{after=JSON.parse(log.after_json);}catch(e){}const tr=document.createElement('tr');cell(tr,'操作时间',log.created_at);cell(tr,'场地',`${log.venue_id} ${log.venue_name||''}`);cell(tr,'操作',log.action==='set'?'设置推荐':'取消推荐');cell(tr,'管理员ID',log.operator_admin_id);cell(tr,'优先级 / 截止',`${after.priority??'—'} / ${after.ends_at??'—'}`);cell(tr,'原因',after.reason);$('logs').append(tr);}
  if(!state.logs.length)empty($('logs'),6);
}
async function load(keepMessage=false){$('refresh').disabled=true;$('fields').disabled=true;if(!keepMessage)message('正在加载...');try{const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error(`请求失败 (${response.status})`);const result=await response.json();if(result.code!==0)throw Error(result.msg||'加载失败');state=result.data;clockOffset=state.server_timestamp*1000-Date.now();const previous=$('venue').value;$('venue').replaceChildren(new Option('请选择场地',''));for(const venue of state.venues){const option=new Option(`${venue.id} ${venue.venue_name}`,venue.id);option.disabled=Number(venue.is_banned)===1||Number(venue.is_voice_room_banned)===1;$('venue').append(option);}$('venue').value=previous;render();describe();$('fields').disabled=false;if(!keepMessage)message(`更新于 ${state.server_time} · 未满足推荐资格的场地不会因临时推荐解禁。`);return true;}catch(e){message(e.message,'error');return false;}finally{$('refresh').disabled=false;}}
async function mutate(input){if(busy)return;busy=true;$('fields').disabled=true;$('confirmCancel').disabled=true;render();try{const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf_token},body:JSON.stringify(input)});if(!response.ok)throw Error(`请求失败 (${response.status})`);const result=await response.json();if(result.code!==0)throw Error(result.msg||'操作失败');$('cancelDialog').close();message(`${result.msg}，列表接口约5秒内生效，到期自动失效。`,'success');await load(true);}catch(e){message(e.message,'error');}finally{busy=false;$('fields').disabled=!state;$('confirmCancel').disabled=false;render();}}
$('settings').onsubmit=event=>{event.preventDefault();const venue=selected();if(!venue)return;const minutes=Number($('duration').value==='custom'?$('minutes').value:$('duration').value);mutate({action:'set',venue_id:Number(venue.id),expected_revision:Number(venue.revision),minutes,priority:Number($('priority').value),reason:$('reason').value.trim()});};
$('cancelForm').onsubmit=event=>{event.preventDefault();if(cancelTarget)mutate({action:'cancel',venue_id:Number(cancelTarget.id),expected_revision:Number(cancelTarget.revision),reason:$('cancelReason').value.trim()});};
$('closeDialog').onclick=()=>{if(!busy)$('cancelDialog').close();};
$('cancelDialog').addEventListener('cancel',event=>{if(busy)event.preventDefault();});
$('venue').onchange=describe;$('duration').onchange=()=>{$('customWrap').hidden=$('duration').value!=='custom';$('minutes').required=$('duration').value==='custom';};
$('search').oninput=render;$('filter').onchange=render;$('refresh').onclick=()=>load();
setInterval(()=>{if(state&&!busy){render();describe();}},15000);
load();
