(()=>{
'use strict';
const root=document.getElementById('menuOperations');
if(!root)return;
const config=window.GELATO_MENU_MANAGER||{};
const csrf=String(config.csrf||'');
const canManage=Boolean(config.canManage);
const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[ch]));
const money=value=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(value||0));
const $=selector=>root.querySelector(selector);
const ui={location:$('#opsLocation'),summary:$('#opsSummary'),items:$('#opsItems'),events:$('#opsEvents'),health:$('#opsHealth'),status:$('#opsStatus'),refresh:$('#opsRefresh'),bulkStatus:$('#opsBulkStatus'),bulkChannel:$('#opsBulkChannel'),bulkEnabled:$('#opsBulkEnabled'),bulkPriceMode:$('#opsBulkPriceMode'),bulkPriceValue:$('#opsBulkPriceValue'),bulkPrice:$('#opsBulkPrice'),selectAll:$('#opsSelectAll'),categoryOrder:$('#opsCategoryOrder'),bulkMove:$('#opsBulkMove'),moveCategory:$('#opsMoveCategory')};
let data={locations:[],locationState:[],summary:{},events:[],images:{}};
let manager={categories:[],items:[]};
let selected=new Set();
let locationId=0;

async function get(params){const url=new URL('api/menu-manager.php',location.href);Object.entries(params).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)url.searchParams.set(k,String(v));});const r=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'});const j=await r.json().catch(()=>({ok:false,message:'Invalid response.'}));if(!r.ok||!j.ok)throw new Error(j.message||'Menu request failed.');return j;}
async function post(payload){const r=await fetch('api/menu-manager.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...payload,csrfToken:csrf})});const j=await r.json().catch(()=>({ok:false,message:'Invalid response.'}));if(!r.ok||!j.ok)throw new Error(j.message||'Menu action failed.');return j;}
function note(text,error=false){if(!ui.status)return;ui.status.textContent=text;ui.status.style.color=error?'#a4312a':'#647064';}
function resumeLabel(value){if(!value)return'';const date=new Date(String(value).replace(' ','T')+'Z');return Number.isNaN(date.getTime())?'':` · resumes ${date.toLocaleString()}`;}
function activeLocation(){return data.locations.find(l=>Number(l.id)===Number(locationId));}

async function load(initial=false){
  try{
    note('Loading live menu operations…');
    manager=await get({action:'state',archived:1});
    const first=(data.locations[0]?.id)||0;
    data=await get({action:'operations',locationId:locationId||''});
    if(!locationId&&data.locations.length){locationId=Number(data.locations[0].id);data=await get({action:'operations',locationId});}
    if(initial||ui.location.options.length!==data.locations.length){ui.location.innerHTML=data.locations.map(l=>`<option value="${l.id}" ${Number(l.id)===Number(locationId)?'selected':''}>${esc(l.name)}${l.status!=='active'?' · '+esc(l.status):''}</option>`).join('');}
    render();note(`Live operational state${activeLocation()?' · '+activeLocation().name:''}`);
  }catch(e){note(e.message,true);ui.items.innerHTML=`<div class="ops-empty">${esc(e.message)}</div>`;}
}

function render(){renderSummary();renderHealth();renderCategories();renderItems();renderEvents();renderBulkCategories();}
function renderSummary(){const s=data.summary||{};const metrics=[['Published',s.published||0],['Sold-out items',s.soldOutItems||0],['Sold-out sizes',s.soldOutSizes||0],['Scheduled items',s.scheduledItems||0],['Draft / paused',(s.draft||0)+(s.paused||0)]];ui.summary.innerHTML=metrics.map(([label,value])=>`<article class="ops-metric"><span>${esc(label)}</span><strong>${Number(value)}</strong></article>`).join('');}
function renderHealth(){const i=data.images||{};const rows=[['Menu item images',i.menuItems],['Ingredient images',i.ingredients],['Location images',i.locations]];ui.health.innerHTML=rows.map(([label,v])=>`<div><span>${esc(label)}</span><strong>${Number(v?.missing||0)} missing / ${Number(v?.total||0)}</strong></div>`).join('');}
function renderCategories(){const cats=(manager.categories||[]).filter(c=>c.status==='active');ui.categoryOrder.innerHTML=cats.length?cats.map((c,index)=>`<div class="ops-event"><strong>${esc(c.name)}</strong><span>Order ${c.sortOrder}</span>${canManage?` <span class="ops-order"><button class="ops-btn" data-cat-move="up" data-id="${c.id}" ${index===0?'disabled':''}>↑</button><button class="ops-btn" data-cat-move="down" data-id="${c.id}" ${index===cats.length-1?'disabled':''}>↓</button></span>`:''}</div>`).join(''):'<div class="ops-empty">No active categories.</div>';}
function renderBulkCategories(){if(!ui.moveCategory)return;ui.moveCategory.innerHTML='<option value="">Move selected to…</option>'+(manager.categories||[]).filter(c=>c.status==='active').map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');}
function categoryItemIds(categoryName){return data.locationState.filter(i=>i.category===categoryName).map(i=>Number(i.id));}
function renderItems(){
  const rows=data.locationState||[];if(!rows.length){ui.items.innerHTML='<div class="ops-empty">Choose an active location to manage sold-out state and size availability.</div>';return;}
  const seen={};
  ui.items.innerHTML=rows.map(item=>{const ids=categoryItemIds(item.category);const index=ids.indexOf(Number(item.id));seen[item.category]=true;const sold=Boolean(item.status?.soldOut);return `<article class="ops-item">
    <div class="ops-item-main"><input type="checkbox" data-select-item="${item.id}" ${selected.has(Number(item.id))?'checked':''} aria-label="Select ${esc(item.name)}"><div><h4>${esc(item.name)}</h4><small>${esc(item.category)}${sold?` · <span class="ops-sold">SOLD OUT${item.status.reason?' — '+esc(item.status.reason):''}</span>${esc(resumeLabel(item.status.resumeAt))}`:''}</small></div>${canManage?`<div class="ops-order"><button class="ops-btn" data-item-move="up" data-id="${item.id}" data-category="${esc(item.category)}" ${index===0?'disabled':''}>↑</button><button class="ops-btn" data-item-move="down" data-id="${item.id}" data-category="${esc(item.category)}" ${index===ids.length-1?'disabled':''}>↓</button><button class="ops-btn" data-schedule="${item.id}">Schedule</button></div><div class="ops-availability"><button class="ops-btn ${sold?'good':'danger'}" data-item-sold="${item.id}" data-current="${sold?1:0}">${sold?'Restore item':'86 item'}</button></div>`:''}</div>
    <div class="ops-sizes">${(item.sizes||[]).map(size=>{const sizeSold=Boolean(size.status?.soldOut);return `<div class="ops-size"><span>${esc(size.label||size.sizeCode||'Default')}${sizeSold?` <b class="ops-sold">SOLD OUT</b>`:''}</span><input type="number" min="0" step="0.01" value="${Number(size.amount||0).toFixed(2)}" data-price-input="${size.id}" aria-label="Price for ${esc(item.name)} ${esc(size.label)}" ${canManage?'':'disabled'}><button class="ops-btn ${sizeSold?'good':'danger'}" data-price-sold="${size.id}" data-current="${sizeSold?1:0}" ${canManage?'':'disabled'}>${sizeSold?'Restore':'86 size'}</button></div>`;}).join('')}</div></article>`;}).join('');
}
function renderEvents(){ui.events.innerHTML=(data.events||[]).slice(0,18).map(event=>`<div class="ops-event"><strong>${esc(event.summary)}</strong><span>${esc(event.createdAt||'')}${event.actor?' · '+esc(event.actor):''}</span></div>`).join('')||'<div class="ops-empty">No menu operation events yet.</div>';}

async function mutate(payload,message='Menu updated.'){
  if(!canManage)return;try{note('Saving…');await post(payload);note(message);await load();}catch(e){note(e.message,true);alert(e.message);}
}
function soldOutPrompt(kind,id,current){if(current)return mutate({action:`operations.${kind}_status`,[kind==='item'?'itemId':'priceId']:id,locationId,soldOut:false},'Availability restored.');const reason=prompt('Sold-out / 86 reason (optional):','Out of stock')??'';const resume=prompt('Auto-resume local date/time (optional, example 2026-09-15 17:00). Leave blank for manual restore.','')??'';return mutate({action:`operations.${kind}_status`,[kind==='item'?'itemId':'priceId']:id,locationId,soldOut:true,reason,resumeAt:resume},'Sold-out state saved.');}

root.addEventListener('click',async event=>{
  const target=event.target.closest('button');if(!target)return;
  if(target.id==='opsRefresh'){await load();return;}
  if(target.dataset.itemSold){await soldOutPrompt('item',Number(target.dataset.itemSold),target.dataset.current==='1');return;}
  if(target.dataset.priceSold){await soldOutPrompt('price',Number(target.dataset.priceSold),target.dataset.current==='1');return;}
  if(target.dataset.schedule){const itemId=Number(target.dataset.schedule);const channel=prompt('Channel: public_menu, online_order, pos, packages, or catering','online_order')||'online_order';const daysRaw=prompt('Days (0=Sun … 6=Sat). Comma separated:','1,2,3,4,5');if(daysRaw===null)return;const start=prompt('Start time (24-hour)','11:00');if(start===null)return;const end=prompt('End time (24-hour)','22:00');if(end===null)return;const days=daysRaw.split(',').map(v=>Number(v.trim())).filter(v=>Number.isInteger(v)&&v>=0&&v<=6);await mutate({action:'operations.schedule_save',itemId,locationId,channel,rows:days.map(dayOfWeek=>({dayOfWeek,startTime:start,endTime:end}))},'Availability schedule saved.');return;}
  if(target.dataset.catMove){const cats=(manager.categories||[]).filter(c=>c.status==='active');const i=cats.findIndex(c=>Number(c.id)===Number(target.dataset.id));const j=target.dataset.catMove==='up'?i-1:i+1;if(i<0||j<0||j>=cats.length)return;[cats[i],cats[j]]=[cats[j],cats[i]];await mutate({action:'operations.reorder_categories',ids:cats.map(c=>c.id)},'Category order saved.');return;}
  if(target.dataset.itemMove){const ids=categoryItemIds(target.dataset.category);const i=ids.indexOf(Number(target.dataset.id));const j=target.dataset.itemMove==='up'?i-1:i+1;if(i<0||j<0||j>=ids.length)return;[ids[i],ids[j]]=[ids[j],ids[i]];const cat=(manager.categories||[]).find(c=>c.name===target.dataset.category);if(!cat)return;await mutate({action:'operations.reorder_items',categoryId:cat.id,ids},'Item order saved.');return;}
  if(target.id==='opsBulkStatus'){if(!selected.size)return alert('Select at least one item.');const status=prompt('Lifecycle action: publish, pause, resume, draft, archive','pause');if(!status)return;await mutate({action:'operations.bulk_status',itemIds:[...selected],status},'Bulk lifecycle updated.');return;}
  if(target.id==='opsBulkChannel'){if(!selected.size)return alert('Select at least one item.');const channel=prompt('Distribution channel: public_menu, online_order, pos, packages, catering','online_order');if(!channel)return;const enabled=confirm('OK = enable this channel. Cancel = disable it.');await mutate({action:'operations.bulk_distribution',itemIds:[...selected],channel,enabled},'Bulk distribution updated.');return;}
  if(target.id==='opsBulkPrice'){if(!selected.size)return alert('Select at least one item.');const mode=ui.bulkPriceMode.value;const value=Number(ui.bulkPriceValue.value);if(!Number.isFinite(value))return alert('Enter a valid price adjustment.');await mutate({action:'operations.bulk_price',itemIds:[...selected],mode,value},'Bulk prices updated.');return;}
  if(target.id==='opsBulkMove'){if(!selected.size)return alert('Select at least one item.');const categoryId=Number(ui.moveCategory.value);if(!categoryId)return alert('Choose a destination category.');await mutate({action:'operations.bulk_move',itemIds:[...selected],categoryId},'Selected items moved.');return;}
  if(target.id==='opsSelectAll'){const ids=(data.locationState||[]).map(i=>Number(i.id));const all=ids.length&&ids.every(id=>selected.has(id));if(all)ids.forEach(id=>selected.delete(id));else ids.forEach(id=>selected.add(id));renderItems();return;}
});
root.addEventListener('change',async event=>{const target=event.target;if(target===ui.location){locationId=Number(target.value||0);selected.clear();await load();return;}if(target.matches('[data-select-item]')){const id=Number(target.dataset.selectItem);target.checked?selected.add(id):selected.delete(id);return;}if(target.matches('[data-price-input]')){const amount=Number(target.value);if(Number.isFinite(amount)&&amount>=0)await mutate({action:'operations.price_update',priceId:Number(target.dataset.priceInput),amount},'Price updated.');}});
load(true);
})();
