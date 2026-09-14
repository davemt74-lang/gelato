(()=>{'use strict';
const C=window.KDS_CONFIG||{};
const S={locationId:0,station:'',completed:false,data:null,timer:null,loading:false};
const $=s=>document.querySelector(s);
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const fmtAge=s=>{s=Math.max(0,Number(s)||0);const m=Math.floor(s/60),r=s%60;return m+':'+String(r).padStart(2,'0')};
const fmtQty=n=>{const v=Number(n)||0;return Number.isInteger(v)?String(v):v.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')};
const label=s=>String(s||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
function toast(message){const e=$('#toast');e.textContent=message;e.classList.remove('hidden');clearTimeout(toast.t);toast.t=setTimeout(()=>e.classList.add('hidden'),2200)}
async function api(method='GET',body=null){
  let url='api/kds.php';
  if(method==='GET'){
    const q=new URLSearchParams();
    if(S.locationId)q.set('locationId',S.locationId);
    if(S.station)q.set('station',S.station);
    if(S.completed)q.set('completed','1');
    url+='?'+q.toString();
  }
  const options={method,headers:{Accept:'application/json'},cache:'no-store'};
  if(body){options.headers['Content-Type']='application/json';body.csrf=C.csrf;options.body=JSON.stringify(body)}
  const response=await fetch(url,options),json=await response.json().catch(()=>({ok:false,message:'Invalid server response.'}));
  if(!response.ok||!json.ok)throw new Error(json.message||'Kitchen request failed.');
  return json;
}
function stationOptions(selected=''){
  const stations=S.data?.board?.stations||[];
  return '<option value="">Unrouted</option>'+stations.map(s=>`<option value="${esc(s.public_id)}" ${String(selected)===String(s.public_id)?'selected':''}>${esc(s.name)}</option>`).join('');
}
function renderLocations(){
  const el=$('#location'),locations=S.data?.locations||[];
  el.innerHTML=locations.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(S.locationId)?'selected':''}>${esc(l.name)}</option>`).join('');
}
function renderTabs(){
  const board=S.data?.board||{},counts=board.stationCounts||{},stations=board.stations||[];
  const tabs=[['','Expo / All',Number(counts.all||0)],['unrouted','Unrouted',Number(counts.unrouted||0)],...stations.map(s=>[String(s.public_id),String(s.name),Number(counts[s.public_id]||0)])];
  $('#tabs').innerHTML=tabs.map(([id,name,count])=>`<button class="tab ${S.station===id?'active':''}" data-station="${esc(id)}">${esc(name)} · ${count}</button>`).join('');
}
function renderAlert(){
  const count=Number(S.data?.board?.metrics?.unrouted||0),el=$('#unroutedAlert');
  el.classList.toggle('show',count>0);
  el.innerHTML=count>0?`<strong>${count} unrouted kitchen item${count===1?'':'s'}.</strong> Assign ${count===1?'it':'them'} to an active station before preparation can begin.`:'';
}
function renderSummary(){
  const m=S.data?.board?.metrics||{};
  const values=[
    ['Tickets',m.tickets||0,''],['Queued',m.queued||0,''],['Cooking',m.inProgress||0,''],['Ready',m.ready||0,'good'],
    ['Expo Ready',m.readyTickets||0,'good'],['SLA Warning',m.warning||0,'warn'],['Late',m.late||0,'bad'],['Unrouted',m.unrouted||0,'bad'],
  ];
  $('#summary').innerHTML=values.map(([name,value,klass])=>`<div class="metric ${klass}"><span>${name}</span><strong>${Number(value)}</strong></div>`).join('');
}
function renderAllDay(){
  const list=S.data?.board?.allDay||[],el=$('#allDay');
  if(!list.length){el.innerHTML='<div class="empty">No active fired items.</div>';return}
  el.innerHTML=list.map(row=>`<div class="all-day-row"><div><strong>${esc(row.name)}</strong><small>${fmtQty(row.queued)} queued · ${fmtQty(row.inProgress)} cooking · ${fmtQty(row.ready)} ready</small></div><div class="qty">${fmtQty(row.quantity)}</div></div>`).join('');
}
function statusChip(item){
  const status=String(item.status||'');
  const klass=status==='ready'?'ready':status==='in_progress'?'progress':status==='held'?'held':'';
  return `<span class="chip ${klass}">${esc(label(status))}</span>`;
}
function itemActionButtons(item){
  if(!C.canUpdate)return'';
  const status=String(item.status||'');let out='';
  if(status==='held')out+='<button class="action" data-item-act="queued">Fire</button>';
  if(status==='queued')out+='<button class="action" data-item-act="in_progress">Start</button><button class="action alt" data-item-act="held">Hold</button>';
  if(status==='in_progress')out+='<button class="action good" data-item-act="ready">Ready</button>';
  if(status==='ready')out+='<button class="action good" data-item-act="completed">Complete</button><button class="action alt" data-item-act="in_progress">Undo</button>';
  if(status==='completed'&&item.recallable)out+='<button class="action warn" data-item-recall>Recall</button>';
  return out;
}
function renderItem(item){
  const context=[];
  if(item.seat_number!==null&&item.seat_number!==undefined)context.push(`Seat ${Number(item.seat_number)}`);
  if(item.course_key)context.push(label(item.course_key));
  if(item.station_name)context.push(String(item.station_name));
  const sla=item.late?'<span class="chip late">Late</span>':item.warning?'<span class="chip warn">SLA warning</span>':'';
  const route=C.canUpdate&&!['completed','cancelled'].includes(String(item.status||''))?`<select class="route" data-route>${stationOptions(item.station_public_id||'')}</select>`:`<span class="fs-note">${item.station_name?esc(item.station_name):'Unrouted'}</span>`;
  return `<div class="kitem" data-item="${esc(item.public_id)}"><div class="kitem-top"><div><div class="kitem-name">${esc(fmtQty(item.quantity))}× ${esc(item.item_name_snapshot)}</div>${item.option_name_snapshot?`<div class="option">${esc(item.option_name_snapshot)}</div>`:''}</div><div class="age ${item.late?'late':item.warning?'warning':''}">${String(item.status)==='held'?'HOLD':fmtAge(item.ageSeconds)}</div></div>${item.special_instructions?`<div class="instructions">${esc(item.special_instructions)}</div>`:''}<div class="item-meta">${statusChip(item)}${context.map(v=>`<span class="chip">${esc(v)}</span>`).join('')}${!item.station_id&&!['completed','cancelled'].includes(String(item.status||''))?'<span class="chip unrouted">Needs routing</span>':''}${sla}</div><div class="item-controls">${route}<div class="item-actions">${itemActionButtons(item)}</div></div></div>`;
}
function ticketActions(ticket){
  if(!C.canUpdate)return'';const buttons=[];
  if(Number(ticket.held)>0)buttons.push('<button class="action" data-ticket-act="fire">Fire held</button>');
  if(Number(ticket.queued)>0){buttons.push('<button class="action alt" data-ticket-act="hold">Hold queued</button>');buttons.push('<button class="action" data-ticket-act="start">Start batch</button>')}
  if(Number(ticket.inProgress)>0)buttons.push('<button class="action good" data-ticket-act="ready">Ready batch</button>');
  if(!S.station&&ticket.readyToBump)buttons.push('<button class="action good" data-ticket-act="bump">Expo bump ticket</button>');
  if(!S.station&&Number(ticket.recallableCount)>0)buttons.push('<button class="action warn" data-ticket-act="recall">Recall ticket</button>');
  return buttons.join('');
}
function renderBoard(){
  const tickets=S.data?.board?.tickets||[],el=$('#board');
  if(!tickets.length){el.innerHTML='<div class="empty">No kitchen tickets in this view.</div>';return}
  el.innerHTML=tickets.map(ticket=>{
    const classes=['ticket'];if(ticket.late)classes.push('late');else if(ticket.warning)classes.push('warning');if(ticket.readyToBump)classes.push('bumpable');
    const headline=ticket.tableName?`${ticket.checkNumber} · ${ticket.tableName}`:ticket.checkNumber;
    const subtitle=[label(ticket.serviceMode),ticket.serverName?`Server ${ticket.serverName}`:null,`${Number(ticket.guestCount)} guest${Number(ticket.guestCount)===1?'':'s'}`].filter(Boolean).join(' · ');
    const meta=[];
    if(ticket.currentCourseKey)meta.push(`<span class="chip">Current ${esc(label(ticket.currentCourseKey))}</span>`);
    if(Number(ticket.held)>0)meta.push(`<span class="chip held">${Number(ticket.held)} held</span>`);
    if(Number(ticket.unrouted)>0)meta.push(`<span class="chip unrouted">${Number(ticket.unrouted)} unrouted</span>`);
    if(ticket.readyToBump)meta.push('<span class="chip ready">Expo ready</span>');
    else if(ticket.late)meta.push('<span class="chip late">Late</span>');
    else if(ticket.warning)meta.push('<span class="chip warn">SLA warning</span>');
    return `<article class="${classes.join(' ')}" data-ticket="${esc(ticket.checkPublicId)}"><div class="ticket-head"><div><strong>${esc(headline)}</strong><small>${esc(subtitle)}</small></div><div class="age ${ticket.late?'late':ticket.warning?'warning':''}">${Number(ticket.oldestAgeSeconds)>0?fmtAge(ticket.oldestAgeSeconds):'—'}</div></div>${meta.length?`<div class="ticket-meta">${meta.join('')}</div>`:''}<div class="ticket-items">${(ticket.items||[]).map(renderItem).join('')}</div>${ticketActions(ticket)?`<div class="ticket-actions">${ticketActions(ticket)}</div>`:''}</article>`;
  }).join('');
}
function renderConfig(){
  const box=$('#config');if(!C.canConfigure){box.classList.add('hidden');return}box.classList.remove('hidden');
  const stations=S.data?.board?.stations||[];
  $('#stationList').innerHTML=stations.map(s=>`<div style="display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #292e29;font-size:10px"><span><b>${esc(s.name)}</b> · ${Math.round(Number(s.target_seconds||0)/60)} min</span><span>${esc(s.status)}</span></div>`).join('')||'<div class="empty">No stations yet.</div>';
  const catalog=S.data?.catalog||[];
  $('#routes').innerHTML=catalog.map(i=>`<div class="route-row"><span>${esc(i.sectionName)} · ${esc(i.name)}</span><select data-menu-route="${Number(i.id)}">${stationOptions(i.station?.publicId||'')}</select></div>`).join('')||'<div class="empty">No menu items available.</div>';
}
function render(){renderLocations();renderTabs();renderAlert();renderSummary();renderAllDay();renderBoard();renderConfig()}
async function load(){
  if(S.loading)return;S.loading=true;
  try{const data=await api();S.data=data;if(!S.locationId)S.locationId=Number(data.locationId)||0;render()}
  catch(e){toast(e.message)}finally{S.loading=false}
}
async function post(body){
  try{
    const data=await api('POST',{locationId:S.locationId,station:S.station,completed:S.completed?1:0,...body});
    if(data.board)S.data.board=data.board;if(data.catalog)S.data.catalog=data.catalog;if(data.stations&&S.data?.board)S.data.board.stations=data.stations;render();return data;
  }catch(e){toast(e.message);throw e}
}
document.addEventListener('click',async e=>{
  const tab=e.target.closest('[data-station]');
  if(tab){S.station=tab.dataset.station||'';await load();return}
  const item=e.target.closest('[data-item]');
  const itemAct=e.target.closest('[data-item-act]');
  if(item&&itemAct){itemAct.disabled=true;await post({action:'item.transition',itemPublicId:item.dataset.item,status:itemAct.dataset.itemAct}).catch(()=>{});return}
  const recall=e.target.closest('[data-item-recall]');
  if(item&&recall){recall.disabled=true;await post({action:'item.recall',itemPublicId:item.dataset.item}).catch(()=>{});return}
  const ticket=e.target.closest('[data-ticket]');
  const ticketAct=e.target.closest('[data-ticket-act]');
  if(ticket&&ticketAct){ticketAct.disabled=true;await post({action:'ticket.action',checkPublicId:ticket.dataset.ticket,ticketAction:ticketAct.dataset.ticketAct}).catch(()=>{});return}
});
document.addEventListener('change',async e=>{
  if(e.target.id==='location'){S.locationId=Number(e.target.value)||0;S.station='';await load();return}
  if(e.target.matches('[data-route]')){const item=e.target.closest('[data-item]');if(!item)return;await post({action:'item.reassign',itemPublicId:item.dataset.item,stationPublicId:e.target.value||null}).catch(()=>{});return}
  if(e.target.matches('[data-menu-route]')){await post({action:'route.save',menuItemId:Number(e.target.dataset.menuRoute),stationPublicId:e.target.value||null}).then(()=>toast('Route saved')).catch(()=>{});return}
});
$('#refresh').addEventListener('click',load);
$('#history').addEventListener('click',async()=>{S.completed=!S.completed;$('#history').textContent=S.completed?'Hide recent completed':'Show recent completed';await load()});
$('#fullscreen').addEventListener('click',async()=>{try{if(!document.fullscreenElement)await document.documentElement.requestFullscreen();else await document.exitFullscreen()}catch(e){toast('Full-screen mode is unavailable in this browser.')}});
document.addEventListener('fullscreenchange',()=>{document.body.classList.toggle('kiosk',!!document.fullscreenElement);$('#fullscreen').textContent=document.fullscreenElement?'Exit full screen':'Full screen'});
$('#stationForm').addEventListener('submit',async e=>{e.preventDefault();await post({action:'station.save',name:$('#stationName').value,targetSeconds:(Number($('#targetMinutes').value)||10)*60,sortOrder:Number($('#sortOrder').value)||0,status:'active'}).then(()=>{toast('Station added');$('#stationName').value=''}).catch(()=>{})});
load();S.timer=setInterval(load,5000);
})();