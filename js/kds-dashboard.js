(()=>{'use strict';
const C=window.KDS_DASHBOARD_CONFIG||{};
const S={locationId:0,data:null,loading:false,cleanupReview:[]};
const $=s=>document.querySelector(s);
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const fmtQty=n=>{const v=Number(n)||0;return Number.isInteger(v)?String(v):v.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')};
const canConfigure=()=>Boolean(S.data?.permissions?.configure);
function toast(message){const e=$('#toast');if(!e)return;e.textContent=message;e.classList.remove('hidden');clearTimeout(toast.t);toast.t=setTimeout(()=>e.classList.add('hidden'),2600)}
async function api(method='GET',body=null){let url='api/kds.php';if(method==='GET'){const q=new URLSearchParams();if(S.locationId)q.set('locationId',S.locationId);url+='?'+q.toString()}const options={method,headers:{Accept:'application/json'},cache:'no-store'};if(body){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=C.csrf||'';options.body=JSON.stringify(body)}const response=await fetch(url,options),json=await response.json().catch(()=>({ok:false,message:'Invalid server response.'}));if(!response.ok||!json.ok)throw new Error(json.message||'Kitchen request failed.');return json}
function stationOptions(selected=''){const stations=S.data?.board?.stations||[];return '<option value="">Unrouted</option>'+stations.map(s=>`<option value="${esc(s.public_id)}" ${String(selected)===String(s.public_id)?'selected':''}>${esc(s.name)}</option>`).join('')}
function renderLocations(){const el=$('#location'),locations=S.data?.locations||[];el.innerHTML=locations.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(S.locationId)?'selected':''}>${esc(l.name)}</option>`).join('')}
function renderSummary(){const m=S.data?.board?.metrics||{};const values=[['Active Tickets',m.tickets||0,''],['Queued',m.queued||0,''],['Cooking',m.inProgress||0,''],['Ready',m.ready||0,'good'],['Expo Ready',m.readyTickets||0,'good'],['SLA Warning',m.warning||0,'warn'],['Late',m.late||0,'bad'],['Unrouted',m.unrouted||0,'bad']];$('#summary').innerHTML=values.map(([name,value,klass])=>`<div class="metric ${klass}"><span>${name}</span><strong>${Number(value)}</strong></div>`).join('')}
function renderStationLoad(){const board=S.data?.board||{},counts=board.stationCounts||{},stations=board.stations||[],el=$('#stationLoad');const rows=stations.map(s=>({name:s.name,target:Math.round(Number(s.target_seconds||0)/60),count:Number(counts[s.public_id]||0)}));if(Number(counts.unrouted||0)>0)rows.push({name:'Unrouted',target:0,count:Number(counts.unrouted||0)});el.innerHTML=rows.length?rows.map(r=>`<div class="station-row"><div><strong>${esc(r.name)}</strong><small>${r.target?`${r.target} min target`:'Needs routing'}</small></div><div class="count">${r.count}</div></div>`).join(''):'<div class="empty">No kitchen stations configured.</div>'}
function renderAllDay(){const rows=S.data?.board?.allDay||[],el=$('#allDay');el.innerHTML=rows.length?rows.map(r=>`<div class="all-day-row"><div><strong>${esc(r.name)}</strong><small>${fmtQty(r.queued)} queued · ${fmtQty(r.inProgress)} cooking · ${fmtQty(r.ready)} ready</small></div><div class="count">${fmtQty(r.quantity)}</div></div>`).join(''):'<div class="empty">No active fired items.</div>'}
function renderConfig(){const form=$('#stationForm'),panel=form?.closest('.panel');if(panel)panel.classList.toggle('hidden',!canConfigure());if(!canConfigure())return;const stations=S.data?.board?.stations||[],stationList=$('#stationList'),routes=$('#routes');if(stationList)stationList.innerHTML=stations.length?stations.map(s=>`<div class="station-row"><div><strong>${esc(s.name)}</strong><small>${Math.round(Number(s.target_seconds||0)/60)} min target · ${esc(s.status)}</small></div><div>${Number(s.sort_order||0)}</div></div>`).join(''):'<div class="empty">No stations yet.</div>';const catalog=S.data?.catalog||[];if(routes)routes.innerHTML=catalog.length?catalog.map(i=>`<div class="route-row"><span>${esc(i.sectionName)} · ${esc(i.name)}</span><select data-menu-route="${Number(i.id)}">${stationOptions(i.station?.publicId||'')}</select></div>`).join(''):'<div class="empty">No menu items available.</div>'}
function removableTestTickets(){
 return (S.data?.board?.tickets||[]).filter(t=>t.checkStatus==='open'&&
   Array.isArray(t.items)&&t.items.length>0&&t.unrouted===t.items.length &&
   t.items.every(i=>!i.station_public_id && !i.stationPublicId));
}
function renderCleanup(){
 const list=$('#kdsTestCleanupList');if(!list)return;
 const items=removableTestTickets(),previous=new Set(
  Array.from(list.querySelectorAll('input[data-test-check]:checked')).map(e=>e.value));
 list.replaceChildren();
 if(!items.length){const empty=document.createElement('div');empty.className='empty';
  empty.textContent='No active fully unrouted test tickets at this location.';list.appendChild(empty);return;}
 for(const ticket of items){
  const row=document.createElement('label');row.className='station-row';row.style.cursor='pointer';
  const left=document.createElement('span');
  const checkbox=document.createElement('input');checkbox.type='checkbox';
  checkbox.dataset.testCheck='1';checkbox.value=ticket.checkPublicId;
  checkbox.checked=previous.has(checkbox.value);checkbox.style.marginRight='9px';
  const title=document.createElement('strong');title.textContent='#'+ticket.checkNumber+' · '+ticket.items.length+' unrouted KDS item(s)';
  const detail=document.createElement('small');detail.textContent=ticket.businessDate+' · '+ticket.checkStatus+' · '+(ticket.tableName||ticket.serviceMode||'test order');
  left.append(checkbox,title,detail);row.appendChild(left);list.appendChild(row);
 }
}
function previewTestCleanup(){
 const selected=new Set(Array.from(document.querySelectorAll('input[data-test-check]:checked')).map(x=>x.value));
 S.cleanupReview=removableTestTickets().filter(x=>selected.has(x.checkPublicId))
  .map(x=>({id:x.checkPublicId,number:x.checkNumber,count:x.items.length}));
 const review=$('#kdsTestCleanupReview'),summary=$('#kdsTestCleanupSummary');
 if(!review)return;
 review.hidden=!S.cleanupReview.length;
 $('#kdsTestCleanupPhrase').value='';
 summary.textContent=S.cleanupReview.length?
  'Remove '+S.cleanupReview.length+' selected test ticket(s), '+S.cleanupReview.reduce((a,x)=>a+x.count,0)+' KDS items: '+
    S.cleanupReview.map(x=>'#'+x.number).join(', ')+'. POS/KDS cancellation is audited.':
  'Select at least one fully unrouted test ticket.';
 if(!S.cleanupReview.length)toast('Select tickets to review first.');
}
async function executeTestCleanup(){
 if($('#kdsTestCleanupPhrase')?.value!=='REMOVE TEST TICKETS'){
  toast('Type REMOVE TEST TICKETS to confirm.');return;}
 const review=S.cleanupReview.slice(),list=removableTestTickets(),eligible=new Set(list.map(x=>x.checkPublicId));
 if(!review.length||review.some(x=>!eligible.has(x.id))){
  toast('Selection changed. Refresh and review again.');return;}
 const button=$('#kdsTestCleanupExecute');button.disabled=true;
 const progress=$('#kdsTestCleanupMessage');let completed=0,failed=[];
 for(const ticket of review){
  try{
   const response=await fetch('api/pos.php',{method:'POST',credentials:'same-origin',cache:'no-store',
    headers:{'Content-Type':'application/json','X-CSRF-Token':C.csrf||''},
    body:JSON.stringify({csrf_token:C.csrf,action:'check.cancel',locationId:S.locationId,
     checkPublicId:ticket.id,reason:'Owner-requested cleanup of unrouted test KDS ticket'})});
   const data=await response.json().catch(()=>null);
   if(!response.ok||!data?.ok)throw Error(data?.message||'POS cancellation failed');
   completed++;progress.textContent='Cancelled '+completed+'/'+review.length+' test tickets…';
  }catch(error){failed.push('#'+ticket.number+': '+error.message);}
 }
 S.cleanupReview=[];$('#kdsTestCleanupReview').hidden=true;
 button.disabled=false;
 await load();
 progress.textContent='Removed '+completed+' test ticket(s)'+(failed.length?' · Failed: '+failed.join('; '):' · KDS queue refreshed.');
}
function render(){renderLocations();renderSummary();renderStationLoad();renderAllDay();renderConfig();renderCleanup()}
async function load(){if(S.loading)return;S.loading=true;try{const data=await api();S.data=data;if(!S.locationId)S.locationId=Number(data.locationId)||0;render()}catch(e){toast(e.message)}finally{S.loading=false}}
async function post(body){try{const data=await api('POST',{locationId:S.locationId,...body});if(data.board)S.data.board=data.board;if(data.catalog)S.data.catalog=data.catalog;if(data.stations&&S.data?.board)S.data.board.stations=data.stations;render();return data}catch(e){toast(e.message);throw e}}
document.addEventListener('change',async e=>{if(e.target.id==='location'){S.locationId=Number(e.target.value)||0;await load();return}if(e.target.matches('[data-menu-route]')){if(!canConfigure()){toast('Kitchen configuration permission required at this location.');return}await post({action:'route.save',menuItemId:Number(e.target.dataset.menuRoute),stationPublicId:e.target.value||null}).then(()=>toast('Route saved')).catch(()=>{})}});
const stationForm=$('#stationForm');if(stationForm)stationForm.addEventListener('submit',async e=>{e.preventDefault();if(!canConfigure()){toast('Kitchen configuration permission required at this location.');return}await post({action:'station.save',name:$('#stationName').value,targetSeconds:(Number($('#targetMinutes').value)||10)*60,sortOrder:Number($('#sortOrder').value)||0,status:'active'}).then(()=>{$('#stationName').value='';toast('Station added')}).catch(()=>{})});
$('#kdsTestCleanupPreview')?.addEventListener('click',previewTestCleanup);
$('#kdsTestCleanupExecute')?.addEventListener('click',executeTestCleanup);
$('#location')?.addEventListener('change',()=>{S.cleanupReview=[];if($('#kdsTestCleanupReview'))$('#kdsTestCleanupReview').hidden=true;});
load();setInterval(load,10000);
})();