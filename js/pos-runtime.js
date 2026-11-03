(()=>{'use strict';
const cfg=window.GELATO_POS_CONFIG||{};
const $=id=>document.getElementById(id);
const state={locationId:0,locations:[],locationStatus:'loading',settings:{},menu:[],openChecks:[],recentChecks:[],readyTickets:[],current:null,category:0,tenderType:'cash',permissions:{},floorPlan:null,view:'menu'};
let locationRevision=0;
let checkRevision=0;
let customerSearchRevision=0;
let readyRequest=null;

function locationScope(){return {locationId:state.locationId,locationRevision}}
function locationIsCurrent(scope){return scope.locationRevision===locationRevision&&scope.locationId===state.locationId}
function checkScope(){return {...locationScope(),checkRevision,checkPublicId:state.current?.publicId??null}}
function checkIsCurrent(scope){
  return locationIsCurrent(scope)&&scope.checkRevision===checkRevision&&scope.checkPublicId===(state.current?.publicId??null);
}
function publishAgentContext(){
  window.GelatoPosAgentContext?.applyPosPayload({
    ok:true,locationId:state.locationId,locations:state.locations,view:state.view,check:state.current
  });
}
const money=n=>'$'+Number(n||0).toFixed(2);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const fmtQty=n=>{const v=Number(n||0);return Number.isInteger(v)?String(v):v.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')};
function readyTime(value){if(!value)return'';const normalized=String(value).replace(' ','T').replace(/(\.\d{3})\d+/,'$1');const date=new Date(normalized);return Number.isNaN(date.getTime())?'':date.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'})}
function toast(message){const el=$('toast');el.textContent=message;el.classList.remove('hidden');clearTimeout(toast.t);toast.t=setTimeout(()=>el.classList.add('hidden'),3300)}
async function getData(locationId,checkPublicId=null){
  const query=new URLSearchParams({locationId:locationId||''});
  if(checkPublicId)query.set('check',checkPublicId);
  const r=await fetch('api/pos.php?'+query,{headers:{Accept:'application/json'},cache:'no-store'});
  const d=await r.json();
  if(!r.ok||!d.ok)throw new Error(d.message||'POS request failed.');
  return d;
}
async function post(action,payload={}){const remove=action==='item.remove';const r=await fetch(remove?'api/pos-item-remove.php':'api/pos.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf||'',Accept:'application/json'},body:JSON.stringify({action:remove?'remove':action,...payload})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'POS action failed.');return d}
async function crmGet(params){const r=await fetch('api/customer-crm.php?'+new URLSearchParams(params),{headers:{Accept:'application/json'},cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Customer lookup failed.');return d}
async function crmPost(action,payload={}){const r=await fetch('api/customer-crm.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf||'',Accept:'application/json'},body:JSON.stringify({action,...payload})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Customer link failed.');return d}
function show(id){$(id)?.classList.remove('hidden')}
function hide(id){$(id)?.classList.add('hidden')}
function openTicketDrawer(){const d=$('activeTicketsDrawer'),b=$('activeTicketsBackdrop'),m=$('posMenuButton');d?.classList.add('open');b?.classList.add('open');d?.setAttribute('aria-hidden','false');m?.setAttribute('aria-expanded','true')}
function closeTicketDrawer(){const d=$('activeTicketsDrawer'),b=$('activeTicketsBackdrop'),m=$('posMenuButton');d?.classList.remove('open');b?.classList.remove('open');d?.setAttribute('aria-hidden','true');m?.setAttribute('aria-expanded','false')}
function switchView(view,notify=false){
  if(state.locationStatus==='ready'){
    if(view==='floor'&&!state.permissions.floorView){
      if(notify)toast('Floor-plan access is not available at this location.');
      view='menu';
    }
    if(view==='ready'&&!state.permissions.kdsAvailable){
      if(notify)toast('Kitchen Display is not available at this location.');
      view='menu';
    }
  }
  state.view=view==='floor'?'floor':view==='ready'?'ready':'menu';
  document.querySelectorAll('[data-pos-view]').forEach(b=>b.classList.toggle('active',b.dataset.posView===state.view));
  $('menuView')?.classList.toggle('hidden',state.view!=='menu');
  $('floorView')?.classList.toggle('hidden',state.view!=='floor');
  $('readyView')?.classList.toggle('hidden',state.view!=='ready');
  publishAgentContext();
}
function sync(d){
  state.locations=d.locations||state.locations;
  state.settings=d.settings||state.settings;
  state.menu=d.menu||state.menu;
  state.openChecks=d.openChecks||state.openChecks;
  state.recentChecks=d.recentChecks||state.recentChecks;
  state.permissions=d.permissions||state.permissions;
  if(!state.permissions.kdsAvailable)state.readyTickets=[];
  if(d.floorPlan!==undefined)state.floorPlan=d.floorPlan;
  if(d.check!==undefined)state.current=d.check;
  render();
}
function resetLocation(locationId){
  locationRevision++;
  checkRevision++;
  readyRequest=null;
  customerSearchRevision++;
  clearTimeout(searchCustomers.t);
  clearTimeout(toast.t);
  closeTicketDrawer();
  for(const id of ['newModal','payModal','settingsModal','customerModal','toast'])hide(id);
  for(const id of ['newTable','newNotes','payAmount','payTip','cashReceived','terminalReference','customerSearch'])$(id).value='';
  $('newGuests').value='1';
  $('makePrimary').checked=false;
  $('customerResults').replaceChildren();
  $('toast').textContent='';
  clearSelectedTable();
  Object.assign(state,{
    locationId,locationStatus:'loading',settings:{},menu:[],openChecks:[],recentChecks:[],readyTickets:[],
    current:null,category:0,tenderType:'cash',permissions:{},floorPlan:null
  });
  render();
}
async function loadLocation(locationId){
  resetLocation(locationId);
  const scope=locationScope();
  try{
    const d=await getData(scope.locationId);
    if(!locationIsCurrent(scope))return;
    const resultId=Number(d.locationId);
    if(!Number.isSafeInteger(resultId)||resultId<1||(scope.locationId&&resultId!==scope.locationId)||(d.check&&Number(d.check.locationId)!==resultId))
      throw new Error('The POS response did not match the requested location.');
    state.locationId=resultId;
    state.locationStatus='ready';
    sync(d);
    void refreshReady();
  }catch(e){
    if(!locationIsCurrent(scope))return;
    state.locationStatus='error';
    render();
    toast(e.message);
  }
}
async function refreshReady(){
  if(readyRequest||state.locationStatus!=='ready'||!state.permissions.kdsAvailable||document.hidden)return;
  const scope=locationScope();
  readyRequest=scope;
  try{
    const r=await fetch('api/pos-ready.php?locationId='+encodeURIComponent(scope.locationId),{headers:{Accept:'application/json'},cache:'no-store'});
    const d=await r.json();
    if(locationIsCurrent(scope)&&state.permissions.kdsAvailable&&r.ok&&d.ok&&Number(d.locationId)===scope.locationId){
      state.readyTickets=d.readyTickets||[];
      renderReady();
    }
  }catch(e){}finally{
    if(readyRequest===scope)readyRequest=null;
  }
}
function locationStatusMessage(){
  return state.locationStatus==='loading'?'Loading POS location…':'POS location unavailable.';
}
function renderLocations(){const el=$('locationSelect');if(!el)return;el.innerHTML=state.locations.map(l=>`<option value="${Number(l.id)}" ${Number(l.id)===Number(state.locationId)?'selected':''}>${esc(l.name)}</option>`).join('')}
function renderChecks(){const el=$('openChecks'),badge=$('activeTicketBadge');if(badge){badge.textContent=String(state.openChecks.length);badge.classList.toggle('hidden',state.openChecks.length===0)}if(!el)return;if(!state.openChecks.length){el.innerHTML='<div class="empty">No active tickets.</div>';return}el.innerHTML=state.openChecks.map(c=>{const active=state.current&&state.current.publicId===c.public_id;const mode=String(c.service_mode||'').replaceAll('_',' ');const tableId=Number(c.table_id||0);const tableName=String(c.table_name||'').trim();let destination=`<span>${esc(mode)}</span>`;if(String(c.service_mode||'')==='dine_in'){const label=tableId>0?`Table ID #${tableId}${tableName?' · '+esc(tableName):''}`:(tableName?`Table · ${esc(tableName)}`:'Table ID #—');destination=`<span class="table-id">${label}</span>`}return `<button class="check-chip ${active?'active':''}" data-check="${esc(c.public_id)}"><strong><span>${esc(c.check_number)}</span><span>${money(c.total_amount)}</span></strong>${destination}<span>${esc(mode)} · ${Number(c.guest_count||1)} guest${Number(c.guest_count||1)===1?'':'s'}</span><span>Opened by ${esc(c.opened_by_name||'Staff')}</span></button>`}).join('')}
function renderCategories(){const el=$('categories');if(!el)return;if(!state.menu.length){el.innerHTML='';return}if(!state.category||!state.menu.some(s=>Number(s.id)===Number(state.category)))state.category=Number(state.menu[0].id);el.innerHTML=state.menu.map(s=>`<button class="category ${Number(s.id)===Number(state.category)?'active':''}" data-category="${Number(s.id)}">${esc(s.name)}</button>`).join('')}
function renderMenu(){const el=$('menuGrid');if(!el)return;if(state.locationStatus!=='ready'){el.innerHTML='<div class="empty">'+locationStatusMessage()+'</div>';return}const section=state.menu.find(s=>Number(s.id)===Number(state.category));if(!section||!section.items.length){el.innerHTML='<div class="empty">No active priced items in this section.</div>';return}const disabled=!state.current||state.current.status!=='open';el.innerHTML=section.items.map(i=>`<article class="menu-card"><h3>${esc(i.name)}</h3><p>${esc(i.description||'')}</p><select id="price-${Number(i.id)}">${i.prices.map(p=>`<option value="${Number(p.id)}">${esc(p.optionName)} · ${money(p.amount)}</option>`).join('')}</select><button class="btn dark" data-add="${Number(i.id)}" ${disabled?'disabled':''}>Add to check</button></article>`).join('')}
function floorStyle(item){return `left:${Number(item.xPercent||0)}%;top:${Number(item.yPercent||0)}%;width:${Math.max(.5,Number(item.widthPercent||1))}%;height:${Math.max(.5,Number(item.heightPercent||1))}%;transform:rotate(${Number(item.rotation||0)}deg)`}
function floorTableStyle(table,structure){const item=structure||table;return `left:${Number(item.xPercent||0)}%;top:${Number(item.yPercent||0)}%;width:${Math.max(3,Number(item.widthPercent||8))}%;height:${Math.max(7,Number(item.heightPercent||10))}%`}
function tableIsOnFloor(table){
  const plan=state.floorPlan?.selectedPlan;
  if(!plan||table.retired||(table.status&&table.status!=='active'))return false;
  if(!table.floorPlanPublicId&&!table.floorPlanComponentId)return true;
  return table.floorPlanPublicId===plan.publicId&&!!table.floorPlanComponentId;
}
function renderFloor(){
  const shell=$('floorShell'),floorTab=document.querySelector('[data-pos-view="floor"]'),canvas=$('floorCanvas');
  const pending=state.locationStatus!=='ready';
  const visible=pending||!!state.permissions.floorView;
  if(shell)shell.style.display=visible?'grid':'none';
  floorTab?.classList.toggle('hidden',!visible);
  if(!canvas)return;
  if(pending){canvas.innerHTML='<div class="empty">'+locationStatusMessage()+'</div>';return}
  if(!visible){canvas.replaceChildren();return}
  const f=state.floorPlan||{ready:false};
  if(!f.ready){canvas.innerHTML='<div class="empty">Floor plan is unavailable until the current database upgrade is applied.</div>';return}
  if(!f.selectedPlan){canvas.innerHTML='<div class="empty">No floor plan is assigned to this POS location.</div>';return}
  const structures=f.structures||[];
  const tables=(f.tables||[]).filter(tableIsOnFloor);
  if(!tables.some(t=>t.floorPlanComponentId)){canvas.innerHTML='<div class="empty">No mapped service points are available on this floor plan.</div>';return}
  const structureById=new Map(structures.map(i=>[String(i.id),i]));
  const mappedIds=new Set(tables.filter(t=>t.floorPlanComponentId).map(t=>String(t.floorPlanComponentId)));
  const background=structures.filter(i=>i.type!=='table'||!mappedIds.has(String(i.id))).map(i=>`<div class="floor-structure ${esc(i.type==='table'?'table-unlinked':i.type)}" style="${floorStyle(i)}">${i.type==='table'?esc(i.label||'Unlinked'):((i.type==='zone'||i.type==='host'||i.type==='bar')?esc(i.label||i.type):'')}</div>`).join('');
  const buttons=tables.map(t=>{
    const current=state.current&&t.checkPublicId===state.current.publicId;
    const actionable=!!t.checkPublicId||(state.permissions.tableUse&&t.seatAllowed===true);
    const subtitle=t.checkNumber?`${t.checkNumber} · ${money(t.totalAmount)}`:`${Number(t.capacity)} seat${Number(t.capacity)===1?'':'s'} · ${String(t.state).replaceAll('_',' ')}`;
    return `<button class="floor-table ${esc(t.state)} ${current?'current':''}" style="${floorTableStyle(t,structureById.get(String(t.floorPlanComponentId)))}" data-table-public="${esc(t.publicId)}" ${actionable?'':'disabled'}><strong>${esc(t.name)}</strong><span>${esc(subtitle)}</span></button>`;
  }).join('');
  canvas.innerHTML=background+buttons;
}
function renderReady(){const tab=$('readyTab'),badge=$('readyBadge'),el=$('readyTickets'),available=!!state.permissions.kdsAvailable;if(tab)tab.classList.toggle('hidden',!available);if(badge){badge.textContent=String(state.readyTickets.length);badge.classList.toggle('hidden',state.readyTickets.length===0)}if(!el)return;if(state.locationStatus!=='ready'){el.innerHTML='<div class="empty">'+locationStatusMessage()+'</div>';return}if(!available){el.innerHTML='<div class="empty">Kitchen Display is not available.</div>';return}if(!state.readyTickets.length){el.innerHTML='<div class="empty ready-empty"><strong>No tickets ready.</strong><span>Tickets appear here automatically when all active fired kitchen items are ready.</span></div>';return}el.innerHTML=state.readyTickets.map(ticket=>{const destination=ticket.tableName||String(ticket.serviceMode||'').replaceAll('_',' ');const items=(ticket.items||[]).map(item=>`<li><strong>${esc(fmtQty(item.quantity))}× ${esc(item.name)}</strong>${item.option?`<span>${esc(item.option)}</span>`:''}${item.station?`<small>${esc(item.station)}</small>`:''}</li>`).join('');const time=readyTime(ticket.readyAt);return `<button class="ready-card" type="button" data-ready-check="${esc(ticket.checkPublicId)}"><div class="ready-card-top"><div><strong>${esc(ticket.checkNumber)}</strong><span>${esc(destination)} · ${Number(ticket.guestCount||1)} guest${Number(ticket.guestCount||1)===1?'':'s'}${ticket.serverName?' · '+esc(ticket.serverName):''}</span></div><span class="ready-pill">READY${time?' · '+esc(time):''}</span></div><ul class="ready-items">${items}</ul><div class="ready-card-foot">Open ticket</div></button>`}).join('')}
function itemById(id){return state.current&&state.current.items?state.current.items.find(i=>Number(i.id)===Number(id)):null}
function kitchenItemByLine(id){const items=state.current?.kitchen?.items||[];return items.find(i=>Number(i.pos_check_item_id)===Number(id))||null}
function customerBlock(c){if(!state.permissions.customerLink||c.status!=='open')return c.customer?`<div class="customer-link"><div><strong>${esc(c.customer.displayName)}</strong><small>Customer attached</small></div></div>`:'';if(c.customer)return `<div class="customer-link"><div><strong>${esc(c.customer.displayName)}</strong><small>${esc(c.customer.email||c.customer.phone||'CRM customer')}</small></div><div><button class="mini" id="changeCustomer">Change</button> <button class="mini bad" id="detachCustomer">Remove</button></div></div>`;return '<div class="customer-link"><div><strong>Walk-in guest</strong><small>No customer attached</small></div><button class="mini" id="attachCustomer">Attach customer</button></div>'}
function renderTicket(){const c=state.current;if(!c){$('ticketHead').innerHTML='<div class="ticket-title"><h2>No open check</h2><span class="status">Ready</span></div><div class="meta">Choose a table from Floor Plan or start a new check.</div>';$('ticketItems').innerHTML='<div class="empty">Your ticket appears here.</div>';$('ticketFoot').innerHTML='<div class="totals"><div class="total-row"><span>Subtotal</span><strong>$0.00</strong></div><div class="total-row grand"><span>Total</span><strong>$0.00</strong></div></div><div class="pay-grid"><button class="btn" disabled>Cash</button><button class="btn" disabled>Card terminal</button></div>';return}$('ticketHead').innerHTML=`<div class="ticket-title"><h2>${esc(c.checkNumber)}</h2><span class="status">${esc(c.status)}</span></div><div class="meta">${esc(c.locationName)} · ${esc(c.serviceMode.replaceAll('_',' '))}${c.tableName?' · Table ID '+esc(c.tableName):''} · ${Number(c.guestCount)} guest${Number(c.guestCount)===1?'':'s'} · ${esc(c.openedBy)}</div>${customerBlock(c)}`;const active=c.status==='open';$('ticketItems').innerHTML=c.items.length?c.items.map(i=>{const k=kitchenItemByLine(i.id);const sent=!!k;const kitchenMeta=sent?`<div class="kitchen-line"><span class="kitchen-chip sent">Kitchen · ${esc(String(k.status||'sent').replaceAll('_',' '))}</span>${k.station_name?`<span class="kitchen-chip">${esc(k.station_name)}</span>`:'<span class="kitchen-chip unrouted">Unrouted</span>'}</div>`:'';let actions='';if(active&&i.status==='active'){if(!sent)actions=`<div class="line-actions"><button class="mini" data-line="${Number(i.id)}" data-act="minus">−</button><button class="mini" data-line="${Number(i.id)}" data-act="plus">+</button><button class="mini" data-line="${Number(i.id)}" data-act="note">Note</button><button class="mini bad" data-line="${Number(i.id)}" data-act="remove">Remove</button>${state.permissions.void?`<button class="mini bad" data-line="${Number(i.id)}" data-act="void">Void</button>`:''}</div>`;else if(state.permissions.void)actions=`<div class="line-actions"><button class="mini bad" data-line="${Number(i.id)}" data-act="void">Void sent item</button></div>`}return `<div class="line ${i.status!=='active'?'voided':''}"><div class="line-top"><div><strong>${esc(i.item_name_snapshot)}</strong><br><small>${esc(i.option_name_snapshot)} · ${Number(i.quantity)} × ${money(i.unit_price)}${i.special_instructions?'<br>Note: '+esc(i.special_instructions):''}</small>${kitchenMeta}</div><strong>${money(i.gross_amount)}</strong></div>${actions}</div>`}).join(''):'<div class="empty">Add menu items to this check.</div>';const kitchen=c.kitchen||{ready:false,sent:0,unsent:0,unrouted:0,items:[]};const canSend=active&&state.permissions.kdsAvailable&&kitchen.ready&&Number(kitchen.unsent)>0;const kitchenRow=state.permissions.kdsAvailable&&kitchen.ready?`<div class="total-row"><span>Kitchen</span><strong>${Number(kitchen.sent)} sent · ${Number(kitchen.unsent)} unsent${Number(kitchen.unrouted)>0?' · '+Number(kitchen.unrouted)+' unrouted':''}</strong></div>`:'';const payEnabled=active&&Number(c.balanceDue)>0&&c.items.some(i=>i.status==='active');$('ticketFoot').innerHTML=`<div class="totals"><div class="total-row"><span>Subtotal</span><strong>${money(c.subtotal)}</strong></div>${Number(c.discountAmount)>0?`<div class="total-row"><span>Discount</span><strong>−${money(c.discountAmount)}</strong></div>`:''}${Number(c.taxAmount)>0?`<div class="total-row"><span>Tax</span><strong>${money(c.taxAmount)}</strong></div>`:''}${Number(c.serviceChargeAmount)>0?`<div class="total-row"><span>Service charge</span><strong>${money(c.serviceChargeAmount)}</strong></div>`:''}${Number(c.tipAmount)>0?`<div class="total-row"><span>Tips</span><strong>${money(c.tipAmount)}</strong></div>`:''}${kitchenRow}<div class="total-row grand"><span>Total</span><strong>${money(c.totalAmount)}</strong></div>${active?`<div class="total-row balance"><span>Balance due</span><strong>${money(c.balanceDue)}</strong></div>`:''}</div><div class="pay-grid">${state.permissions.kdsAvailable&&active?`<button class="btn accent" id="kitchenSend" ${canSend?'':'disabled'}>Send to kitchen</button><button class="btn" id="kitchenHold" ${canSend?'':'disabled'}>Send held</button>`:''}<button class="btn dark" data-pay="cash" ${payEnabled?'':'disabled'}>Cash</button><button class="btn dark" data-pay="external_card" ${payEnabled?'':'disabled'}>Card terminal</button>${state.permissions.discount&&active?'<button class="btn" id="discountBtn">Discount</button>':'<span></span>'}${state.permissions.void&&active?'<button class="btn bad" id="cancelCheck">Cancel check</button>':'<span></span>'}</div>`}
function renderSettings(){if(!state.settings)return;$('taxRate').value=(Number(state.settings.taxRate||0)*100).toFixed(3);$('serviceRate').value=(Number(state.settings.serviceChargeRate||0)*100).toFixed(3);$('defaultMode').value=state.settings.defaultServiceMode||'dine_in';$('settingsBtn').style.display=state.permissions.manage?'':'none';const fs=$('floorSettings');const select=$('floorPlanSelect');if(!state.permissions.floorView){fs.style.display='none';select.replaceChildren();$('floorSettingsNote').textContent='';return}fs.style.display='block';const f=state.floorPlan||{};select.innerHTML='<option value="">No assigned plan</option>'+((f.plans||[]).map(p=>`<option value="${esc(p.publicId)}" ${f.selectedPlan&&p.publicId===f.selectedPlan.publicId?'selected':''}>${esc(p.name)}${p.isDefault?' · default':''}</option>`).join(''));$('saveFloorPlan').style.display=state.permissions.floorManage?'':'none';$('syncFloorTables').style.display=state.permissions.floorManage?'':'none';select.disabled=!state.permissions.floorManage;$('floorSettingsNote').textContent=f.selectedPlan?`${f.selectedPlan.name}: ${Number(f.mappedCount||0)} mapped service point${Number(f.mappedCount||0)===1?'':'s'} available to POS.`:'Choose the main floor plan for this POS location.'}
function render(){
  $('newCheck').disabled=state.locationStatus!=='ready';
  $('createCheck').disabled=state.locationStatus!=='ready';
  renderLocations();renderChecks();renderFloor();renderReady();renderCategories();renderMenu();renderTicket();renderSettings();
  switchView(state.view);
}
async function selectCheck(publicId){
  if(state.locationStatus!=='ready')return;
  checkRevision++;
  state.current=null;
  hide('payModal');
  hide('customerModal');
  render();
  const scope=checkScope();
  try{
    const d=await getData(scope.locationId,publicId);
    if(!checkIsCurrent(scope))return;
    if(Number(d.locationId)!==scope.locationId||d.check?.publicId!==publicId||Number(d.check.locationId)!==scope.locationId)
      throw new Error('The POS response did not match the requested check.');
    sync(d);
  }catch(e){if(checkIsCurrent(scope))toast(e.message)}
}
async function mutate(action,payload,onSuccess){
  if(state.locationStatus!=='ready')return null;
  const scope=checkScope();
  if((payload.locationId!==undefined&&Number(payload.locationId)!==scope.locationId)||
    (payload.checkPublicId&&payload.checkPublicId!==scope.checkPublicId))return null;
  const isCurrent=payload.locationId!==undefined&&!payload.checkPublicId&&action!=='check.create'&&action!=='table.seat'
    ?()=>locationIsCurrent(scope):()=>checkIsCurrent(scope);
  try{
    const d=await post(action,payload);
    if(!isCurrent())return null;
    if((d.check&&(Number(d.check.locationId)!==scope.locationId||(payload.checkPublicId&&d.check.publicId!==payload.checkPublicId)))||
      (d.settings?.locationId!==undefined&&Number(d.settings.locationId)!==scope.locationId))
      throw new Error('The POS action response did not match the selected location.');
    if(action==='check.cancel')d.check=null;
    sync(d);
    if(state.permissions.kdsAvailable)void refreshReady();
    onSuccess?.(d);
    return d;
  }catch(e){
    if(isCurrent())toast(e.message);
    return null;
  }
}
function customerPickerIsCurrent(scope,revision){
  return checkIsCurrent(scope)&&revision===customerSearchRevision&&!$('customerModal').classList.contains('hidden');
}
async function searchCustomers(){
  if(!state.permissions.customerLink||!state.current||$('customerModal').classList.contains('hidden'))return;
  const scope=checkScope();
  const revision=++customerSearchRevision;
  const q=$('customerSearch').value.trim();
  try{
    const d=await crmGet({action:'search',scope:'pos',q,limit:'25'});
    if(!customerPickerIsCurrent(scope,revision))return;
    const list=d.customers||[];
    $('customerResults').innerHTML=list.length?list.map(c=>`<button class="customer-result" data-attach-customer="${esc(c.publicId)}"><div><strong>${esc(c.displayName)}</strong><small>${esc(c.email||c.phone||'No contact channel')}</small></div><span class="mini">Attach</span></button>`).join(''):'<div class="empty">No matching customers.</div>';
  }catch(e){if(customerPickerIsCurrent(scope,revision))toast(e.message)}
}
function openCustomerPicker(){
  if(!state.current||!state.permissions.customerLink)return;
  customerSearchRevision++;
  clearTimeout(searchCustomers.t);
  $('customerSearch').value='';
  $('customerResults').innerHTML='<div class="empty">Search the CRM to attach an existing customer.</div>';
  show('customerModal');
  $('customerSearch').focus();
}
async function changeCustomer(customerPublicId){
  if(!state.current||!state.permissions.customerLink)return;
  const scope=checkScope();
  try{
    const action=customerPublicId?'pos.attach':'pos.detach';
    const d=await crmPost(action,{checkPublicId:scope.checkPublicId,...(customerPublicId?{customerPublicId}:{})});
    if(!checkIsCurrent(scope))return;
    state.current.customer=d.customer||null;
    hide('customerModal');
    renderTicket();
    publishAgentContext();
    toast(customerPublicId?'Customer attached to check.':'Customer removed from check.');
  }catch(e){if(checkIsCurrent(scope))toast(e.message)}
}
function clearSelectedTable(){if($('newTablePublicId'))$('newTablePublicId').value=''}
$('posMenuButton')?.addEventListener('click',openTicketDrawer);
$('activeTicketsClose')?.addEventListener('click',closeTicketDrawer);
$('activeTicketsBackdrop')?.addEventListener('click',closeTicketDrawer);
document.querySelectorAll('[data-pos-view]').forEach(b=>b.addEventListener('click',()=>switchView(b.dataset.posView||'menu',true)));
$('locationSelect').addEventListener('change',e=>void loadLocation(Number(e.target.value)));
$('newCheck').addEventListener('click',()=>{
  if(state.locationStatus!=='ready')return;
  clearSelectedTable();
  $('newTable').value='';
  $('newMode').value=state.settings.defaultServiceMode||'dine_in';
  show('newModal');
});
$('newMode').addEventListener('change',e=>{if(e.target.value!=='dine_in')clearSelectedTable()});
$('createCheck').addEventListener('click',()=>{
  const tablePublicId=$('newTablePublicId').value.trim();
  const payload={locationId:state.locationId,serviceMode:$('newMode').value,guestCount:Number($('newGuests').value||1),tableName:$('newTable').value,notes:$('newNotes').value};
  const afterCreate=()=>{hide('newModal');clearSelectedTable();$('newTable').value='';$('newNotes').value=''};
  if(payload.serviceMode==='dine_in'&&tablePublicId){
    const table=(state.floorPlan?.tables||[]).find(t=>t.publicId===tablePublicId&&tableIsOnFloor(t));
    if(!table||table.seatAllowed!==true||!state.permissions.tableUse)return toast('This service point is no longer available to seat.');
    void mutate('table.seat',{...payload,tablePublicId},afterCreate);
  }else void mutate('check.create',payload,afterCreate);
});
$('floorCanvas').addEventListener('click',e=>{
  const button=e.target.closest('[data-table-public]');
  if(!button||state.locationStatus!=='ready')return;
  const table=(state.floorPlan?.tables||[]).find(t=>t.publicId===button.dataset.tablePublic&&tableIsOnFloor(t));
  if(!table)return;
  if(table.checkPublicId){void selectCheck(table.checkPublicId);return}
  if(!state.permissions.tableUse)return toast('Table-service permission is required to seat this service point.');
  if(table.seatAllowed!==true)return toast(`${table.name} is ${String(table.state).replaceAll('_',' ')} and cannot be seated.`);
  $('newMode').value='dine_in';
  $('newTable').value=table.name;
  $('newTablePublicId').value=table.publicId;
  $('newGuests').value=Math.max(1,Math.min(Number(table.capacity||1),Number($('newGuests').value||1)));
  show('newModal');
});
$('readyTickets').addEventListener('click',e=>{const b=e.target.closest('[data-ready-check]');if(b)void selectCheck(b.dataset.readyCheck)});
$('openChecks').addEventListener('click',e=>{const b=e.target.closest('[data-check]');if(b){closeTicketDrawer();void selectCheck(b.dataset.check)}});
$('categories').addEventListener('click',e=>{const b=e.target.closest('[data-category]');if(!b)return;state.category=Number(b.dataset.category);renderCategories();renderMenu()});
$('menuGrid').addEventListener('click',e=>{
  const b=e.target.closest('[data-add]');
  if(!b||!state.current)return;
  const price=$('price-'+Number(b.dataset.add));
  if(price)void mutate('item.add',{checkPublicId:state.current.publicId,priceId:Number(price.value),quantity:1});
});
$('ticketHead').addEventListener('click',e=>{
  if(e.target.id==='attachCustomer'||e.target.id==='changeCustomer')openCustomerPicker();
  if(e.target.id==='detachCustomer')void changeCustomer(null);
});
$('ticketItems').addEventListener('click',e=>{
  const b=e.target.closest('[data-line]');
  if(!b||!state.current)return;
  const item=itemById(Number(b.dataset.line));
  if(!item)return;
  const act=b.dataset.act;
  const checkPublicId=state.current.publicId;
  const payload={checkPublicId,itemId:Number(item.id)};
  const removed=()=>toast('Item removed from ticket.');
  if(act==='minus'){
    if(Number(item.quantity)<=1){void mutate('item.remove',payload,removed);return}
    void mutate('item.update',{...payload,quantity:Number(item.quantity)-1,specialInstructions:item.special_instructions||''});
  }
  if(act==='plus')void mutate('item.update',{...payload,quantity:Number(item.quantity)+1,specialInstructions:item.special_instructions||''});
  if(act==='note'){
    const note=prompt('Special instructions',item.special_instructions||'');
    if(note!==null)void mutate('item.update',{...payload,quantity:Number(item.quantity),specialInstructions:note});
  }
  if(act==='remove')void mutate('item.remove',payload,removed);
  if(act==='void'){
    const reason=prompt('Void reason');
    if(reason)void mutate('item.void',{...payload,reason});
  }
});
$('ticketFoot').addEventListener('click',e=>{
  if(!state.current)return;
  const checkPublicId=state.current.publicId;
  if(e.target.id==='kitchenSend'||e.target.id==='kitchenHold'){
    const held=e.target.id==='kitchenHold';
    void mutate('kitchen.send',{checkPublicId,hold:held},d=>{
      const k=d.kitchen||state.current?.kitchen||{};
      toast(held?'Items sent to kitchen on hold.':`Sent to kitchen${Number(k.unrouted)>0?' · '+Number(k.unrouted)+' unrouted':''}.`);
    });
    return;
  }
  const pay=e.target.closest('[data-pay]');
  if(pay){
    state.tenderType=pay.dataset.pay;
    $('payTitle').textContent=state.tenderType==='cash'?'Record cash':'Record external terminal payment';
    $('payAmount').value=Number(state.current.balanceDue).toFixed(2);
    $('payTip').value='0.00';
    $('cashReceived').value=Number(state.current.balanceDue).toFixed(2);
    $('terminalReference').value='';
    $('cashReceivedWrap').style.display=state.tenderType==='cash'?'grid':'none';
    $('referenceWrap').style.display=state.tenderType==='external_card'?'grid':'none';
    show('payModal');
    return;
  }
  if(e.target.id==='discountBtn'){
    const current=Number(state.current.discountAmount||0).toFixed(2);
    const raw=prompt('Discount amount',current);
    if(raw===null)return;
    const amount=Number(raw);
    if(!Number.isFinite(amount)||amount<0)return toast('Enter a valid discount amount.');
    let reason='';
    if(amount>0){
      reason=prompt('Discount reason',state.current.discountReason||'')||'';
      if(!reason)return toast('A discount reason is required.');
    }
    void mutate('discount.set',{checkPublicId,amount,reason});
  }
  if(e.target.id==='cancelCheck'){
    const reason=prompt('Cancellation reason');
    if(reason)void mutate('check.cancel',{checkPublicId,reason});
  }
});
$('recordPayment').addEventListener('click',()=>{
  if(!state.current)return;
  const amount=Number($('payAmount').value||0),tip=Number($('payTip').value||0);
  const payload={checkPublicId:state.current.publicId,tenderType:state.tenderType,amount,tipAmount:tip};
  if(state.tenderType==='cash')payload.receivedAmount=Number($('cashReceived').value||0);
  else payload.externalReference=$('terminalReference').value;
  void mutate('tender.record',payload,d=>{
    hide('payModal');
    if(d.check&&d.check.status==='paid')toast('Check paid and table moved to reset/cleaning flow.');
  });
});
$('settingsBtn').addEventListener('click',()=>{if(state.permissions.manage)show('settingsModal')});
$('saveSettings').addEventListener('click',()=>{
  void mutate('settings.save',{locationId:state.locationId,taxRate:Number($('taxRate').value||0)/100,serviceChargeRate:Number($('serviceRate').value||0)/100,defaultServiceMode:$('defaultMode').value,makePrimary:$('makePrimary').checked},()=>{
    hide('settingsModal');
    toast('POS settings saved.');
  });
});
$('saveFloorPlan').addEventListener('click',()=>{
  const floorPlanPublicId=$('floorPlanSelect').value;
  void mutate('floor.select',{locationId:state.locationId,floorPlanPublicId},()=>{
    toast(floorPlanPublicId?'Floor plan assigned to this POS location.':'Floor plan assignment cleared for this POS location.');
  });
});
$('syncFloorTables').addEventListener('click',()=>{
  void mutate('floor.sync',{locationId:state.locationId},d=>{
    const s=d.sync||{};
    const retired=Number(s.retired??s.orphaned??0);
    toast(`Mapped service points synced · ${Number(s.created||0)} created · ${Number(s.updated||0)} updated${retired?' · '+retired+' retired':''}.`);
  });
});
$('customerSearchBtn').addEventListener('click',searchCustomers);
$('customerSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();void searchCustomers()}});
$('customerSearch').addEventListener('input',()=>{
  customerSearchRevision++;
  clearTimeout(searchCustomers.t);
  searchCustomers.t=setTimeout(searchCustomers,240);
});
$('customerResults').addEventListener('click',e=>{
  const b=e.target.closest('[data-attach-customer]');
  if(b)void changeCustomer(b.dataset.attachCustomer);
});
document.addEventListener('click',e=>{const b=e.target.closest('[data-close]');if(b)hide(b.dataset.close)});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('activeTicketsDrawer')?.classList.contains('open'))closeTicketDrawer()});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refreshReady()});
void loadLocation(0);
setInterval(refreshReady,4000);
})();
