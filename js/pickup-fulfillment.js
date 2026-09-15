(()=>{
'use strict';
const app=window.PICKUP_FULFILLMENT||{};
const el={
  board:document.getElementById('pickupBoard'),empty:document.getElementById('pickupEmpty'),message:document.getElementById('pickupMessage'),
  location:document.getElementById('locationFilter'),filter:document.getElementById('stateFilter'),search:document.getElementById('searchOrders'),
  refresh:document.getElementById('refreshQueue'),updated:document.getElementById('lastUpdated'),
  active:document.getElementById('sumActive'),ready:document.getElementById('sumReady'),payment:document.getElementById('sumPayment'),late:document.getElementById('sumLate')
};
const state={orders:[],csrf:'',canFulfill:!!app.canFulfill,canRecover:!!app.canRecover,loading:false};
const esc=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
const money=value=>new Intl.NumberFormat(undefined,{style:'currency',currency:'USD'}).format(Number(value||0));
const normalizeDbTime=value=>String(value??'').trim().replace(' ','T').replace(/(\.\d{3})\d+$/,'$1');
const dateTime=value=>{if(!value)return '—';const d=new Date(normalizeDbTime(value));return Number.isNaN(d.getTime())?String(value):d.toLocaleString([],{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});};
const age=seconds=>{if(seconds===null||seconds===undefined)return '—';seconds=Math.max(0,Number(seconds)||0);const m=Math.floor(seconds/60);if(m<1)return '<1 min';if(m<60)return `${m} min`;const h=Math.floor(m/60),r=m%60;return r?`${h}h ${r}m`:`${h}h`;};
const promiseLabel=order=>{const s=Number(order.promiseDeltaSeconds);if(!Number.isFinite(s))return 'No promise time';if(s<0)return `${age(Math.abs(s))} past promise`;if(s<60)return 'Due now';return `${age(s)} until promise`;};
const setMessage=(text,type='info')=>{el.message.hidden=!text;el.message.className=`pickup-message ${type}`;el.message.textContent=text||'';};
function filteredOrders(){
  const q=el.search.value.trim().toLowerCase();
  if(!q)return state.orders;
  return state.orders.filter(o=>[o.check_number,o.customer_name,o.customer_email,o.customer_phone,o.location_name,o.order_public_id].join(' ').toLowerCase().includes(q));
}
function badge(order){
  const cls=esc(order.fulfillmentState||'submitted');
  return `<span class="pickup-badge ${cls}">${esc(order.displayStatus||'Submitted')}</span>`;
}
function paymentBadge(order){return `<span class="pickup-payment ${order.paymentComplete?'paid':'due'}">${order.paymentComplete?'Paid':'Payment due'}</span>`;}
function action(order){
  const recover=state.canRecover?`<a class="admin-button" href="order-recovery.php?order=${encodeURIComponent(order.order_public_id)}">Recover</a>`:'';
  let primary='';
  if(order.fulfillmentState==='fulfilled')primary=`<div class="pickup-handed"><strong>Handed off</strong><span>${esc(dateTime(order.fulfilled_at))}${order.fulfilled_by_name?` · ${esc(order.fulfilled_by_name)}`:''}</span></div>`;
  else if(order.fulfillmentState==='cancelled')primary='<button class="pickup-action" disabled>Cancelled</button>';
  else if(!order.physicalReady)primary='<button class="pickup-action" disabled>Waiting on kitchen</button>';
  else if(!order.paymentComplete)primary='<button class="pickup-action payment-block" disabled>Complete payment first</button>';
  else if(!state.canFulfill)primary='<button class="pickup-action" disabled>View only</button>';
  else primary=`<button class="pickup-action ready-action" data-fulfill="${esc(order.order_public_id)}">Handed to Customer</button>`;
  return `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">${recover}${primary}</div>`;
}
function card(order){
  const past=Number(order.promiseDeltaSeconds)<0 && !['fulfilled','cancelled'].includes(order.fulfillmentState);
  const ready=!!order.physicalReady && order.fulfillmentState!=='fulfilled';
  return `<article class="pickup-card ${ready?'is-ready':''} ${past?'is-late':''}" data-order="${esc(order.order_public_id)}">
    <div class="pickup-card-top"><div><span class="pickup-order-number">${esc(order.check_number)}</span><h2>${esc(order.customer_name||'Pickup customer')}</h2><p>${esc(order.location_name)}</p></div><div class="pickup-card-badges">${badge(order)}${paymentBadge(order)}</div></div>
    <div class="pickup-timing"><div><span>Promised</span><strong>${esc(dateTime(order.requested_ready_at))}</strong><small class="${past?'late-text':''}">${esc(promiseLabel(order))}</small></div><div><span>Ready age</span><strong>${order.physicalReady?esc(age(order.readyAgeSeconds)):'—'}</strong><small>${order.physicalReady?'Since complete ticket became ready':'Kitchen still active'}</small></div><div><span>Total</span><strong>${esc(money(order.total_amount))}</strong><small>${esc(order.paymentStatus||'')}</small></div></div>
    <div class="pickup-contact"><span>${order.customer_phone?`☎ ${esc(order.customer_phone)}`:'No phone'}</span><span>${order.customer_email?`✉ ${esc(order.customer_email)}`:'No email'}</span></div>
    ${order.customer_note?`<div class="pickup-note"><strong>Customer note</strong><span>${esc(order.customer_note)}</span></div>`:''}
    ${order.fulfillment_note?`<div class="pickup-note fulfilled-note"><strong>Handoff note</strong><span>${esc(order.fulfillment_note)}</span></div>`:''}
    <div class="pickup-card-foot"><span class="pickup-submitted">Submitted ${esc(dateTime(order.submitted_at))}</span>${action(order)}</div>
  </article>`;
}
function render(){
  const rows=filteredOrders();
  el.board.innerHTML=rows.map(card).join('');
  el.empty.hidden=rows.length!==0;
  el.board.setAttribute('aria-busy','false');
  const active=state.orders.filter(o=>!['fulfilled','cancelled'].includes(o.fulfillmentState));
  el.active.textContent=String(active.length);
  el.ready.textContent=String(active.filter(o=>o.physicalReady).length);
  el.payment.textContent=String(active.filter(o=>o.physicalReady&&!o.paymentComplete).length);
  el.late.textContent=String(active.filter(o=>Number(o.promiseDeltaSeconds)<0).length);
}
function syncLocationOptions(locations,selected){
  const current=String(selected||el.location.value||0);
  el.location.innerHTML='<option value="0">All assigned locations</option>'+locations.map(l=>`<option value="${Number(l.id)}">${esc(l.name)}</option>`).join('');
  if([...el.location.options].some(o=>o.value===current))el.location.value=current;
}
async function load(silent=false){
  if(state.loading)return;state.loading=true;
  if(!silent){el.board.setAttribute('aria-busy','true');setMessage('');}
  try{
    const p=new URLSearchParams();if(Number(el.location.value)>0)p.set('locationId',el.location.value);p.set('filter',el.filter.value||'active');
    const r=await fetch(`api/pickup-fulfillment.php?${p.toString()}`,{headers:{Accept:'application/json'},credentials:'same-origin'});
    const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||'Pickup queue could not load.');
    state.orders=Array.isArray(data.orders)?data.orders:[];state.csrf=String(data.csrfToken||state.csrf);state.canFulfill=!!data.canFulfill;
    syncLocationOptions(Array.isArray(data.locations)?data.locations:[],data.locationId);
    el.updated.textContent=`Updated ${new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit',second:'2-digit'})}`;
    render();
  }catch(err){setMessage(err instanceof Error?err.message:'Pickup queue could not load.','error');}
  finally{state.loading=false;el.board.setAttribute('aria-busy','false');}
}
async function fulfill(publicId,button){
  const order=state.orders.find(o=>String(o.order_public_id)===String(publicId));if(!order)return;
  if(!window.confirm(`Hand order ${order.check_number} to ${order.customer_name}? This records the physical pickup.`))return;
  button.disabled=true;button.textContent='Recording…';setMessage('');
  try{
    const payload={action:'order.fulfill',orderPublicId:publicId,locationId:Number(el.location.value)||0,filter:el.filter.value||'active'};
    const r=await fetch('api/pickup-fulfillment.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify(payload)});
    const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||'Pickup handoff could not be recorded.');
    state.orders=Array.isArray(data.orders)?data.orders:state.orders;
    setMessage(`Order ${order.check_number} marked handed to customer.`,'success');render();
  }catch(err){setMessage(err instanceof Error?err.message:'Pickup handoff could not be recorded.','error');button.disabled=false;button.textContent='Handed to Customer';}
}
el.board.addEventListener('click',event=>{const button=event.target.closest('[data-fulfill]');if(button)fulfill(button.dataset.fulfill,button);});
el.refresh.addEventListener('click',()=>load(false));
el.location.addEventListener('change',()=>load(false));
el.filter.addEventListener('change',()=>load(false));
el.search.addEventListener('input',render);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)load(true);});
setInterval(()=>{if(!document.hidden)load(true);},20000);
load(false);
})();
